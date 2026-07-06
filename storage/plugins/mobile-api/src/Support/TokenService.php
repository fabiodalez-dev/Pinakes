<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use mysqli;

/**
 * Device-token service for the Mobile API.
 *
 * Mirrors RememberMeService's security posture:
 *   - 256-bit (32-byte) cryptographically random token, hex-encoded for transport;
 *   - ONLY the sha256 hash of the token is ever persisted (token_hash CHAR(64));
 *   - lookups compare with hash_equals via a hashed WHERE + a final hash_equals
 *     guard against any storage-level surprises;
 *   - tokens are revocable individually (revoked_at) and may carry a far-future
 *     expiry (expires_at); revocation is the primary control (spec §Token lifetime).
 *
 * Data isolation: every method that touches a row scopes by user_id where the
 * caller's identity matters (device list, revoke-own). The bearer lookup is the
 * only identity-establishing call and never trusts client-supplied user_id.
 */
final class TokenService
{
    /** Random token entropy in bytes. 32 bytes = 256 bits (spec §Auth flow). */
    public const TOKEN_BYTES = 32;

    /** Hex length of a valid presented token (2 chars per byte). */
    private const TOKEN_HEX_LEN = self::TOKEN_BYTES * 2;

    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Issue a new device token for a user. Returns the PLAINTEXT token exactly
     * once (the caller returns it to the client; it is never recoverable later).
     *
     * @return array{token:string, token_id:int}|null null on persistence failure.
     */
    public function issue(
        int $userId,
        ?string $deviceName,
        ?string $deviceId,
        ?string $platform,
        ?string $expiresAt = null
    ): ?array {
        $token     = bin2hex(random_bytes(self::TOKEN_BYTES));
        $tokenHash = hash('sha256', $token);

        $deviceName = $this->clip($deviceName, 190);
        $deviceId   = $this->clip($deviceId, 190);
        $platform   = $this->clip($platform, 32);

        $stmt = $this->db->prepare(
            'INSERT INTO mobile_app_tokens
                (user_id, token_hash, device_name, device_id, platform, created_at, last_used_at, expires_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?)'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('isssss', $userId, $tokenHash, $deviceName, $deviceId, $platform, $expiresAt);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $tokenId = (int) $stmt->insert_id;
        $stmt->close();

        return ['token' => $token, 'token_id' => $tokenId];
    }

    /**
     * Resolve a presented bearer token to its active (non-revoked, non-expired)
     * row. Returns null for any malformed/unknown/revoked/expired token.
     *
     * Side effect on success: bumps last_used_at.
     *
     * @return array{id:int, user_id:int, token_hash:string}|null
     */
    public function resolveActive(string $presentedToken): ?array
    {
        // Cheap structural reject before hashing (avoids hashing arbitrary input).
        if (strlen($presentedToken) !== self::TOKEN_HEX_LEN || ctype_xdigit($presentedToken) === false) {
            return null;
        }

        $tokenHash = hash('sha256', $presentedToken);

        $this->db->query("SET SESSION time_zone = '+00:00'");

        $stmt = $this->db->prepare(
            'SELECT id, user_id, token_hash
               FROM mobile_app_tokens
              WHERE token_hash = ?
                AND revoked_at IS NULL
                AND (expires_at IS NULL OR expires_at > NOW())
              LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row === null) {
            return null;
        }

        // Defence-in-depth: constant-time compare the stored hash against the
        // recomputed one even though the WHERE already matched on equality.
        if (!hash_equals((string) $row['token_hash'], $tokenHash)) {
            return null;
        }

        $tokenId = (int) $row['id'];
        $this->touch($tokenId);

        return [
            'id'         => $tokenId,
            'user_id'    => (int) $row['user_id'],
            'token_hash' => (string) $row['token_hash'],
        ];
    }

    /**
     * Revoke a single token by its id, scoped to the owning user. Returns true
     * if a row was actually revoked (idempotent: already-revoked → false).
     */
    public function revokeOwn(int $tokenId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE mobile_app_tokens
                SET revoked_at = NOW()
              WHERE id = ? AND user_id = ? AND revoked_at IS NULL'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $tokenId, $userId);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();

        return $ok;
    }

    /**
     * List the active devices for a user. `is_current` flags the row matching
     * the supplied (already-resolved) token id.
     *
     * @return list<array{id:int, device_name:?string, platform:?string, created_at:?string, last_used_at:?string, is_current:bool}>
     */
    public function listDevices(int $userId, ?int $currentTokenId): array
    {
        $this->db->query("SET SESSION time_zone = '+00:00'");

        $stmt = $this->db->prepare(
            'SELECT id, device_name, platform, created_at, last_used_at
               FROM mobile_app_tokens
              WHERE user_id = ?
                AND revoked_at IS NULL
                AND (expires_at IS NULL OR expires_at > NOW())
              ORDER BY (last_used_at IS NULL), last_used_at DESC, created_at DESC'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();

        $devices = [];
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $devices[] = [
                    'id'           => (int) $row['id'],
                    'device_name'  => $row['device_name'] !== null ? (string) $row['device_name'] : null,
                    'platform'     => $row['platform'] !== null ? (string) $row['platform'] : null,
                    'created_at'   => self::isoUtc($row['created_at'] ?? null),
                    'last_used_at' => self::isoUtc($row['last_used_at'] ?? null),
                    'is_current'   => $currentTokenId !== null && (int) $row['id'] === $currentTokenId,
                ];
            }
        }
        $stmt->close();

        return $devices;
    }

    private function touch(int $tokenId): void
    {
        $stmt = $this->db->prepare('UPDATE mobile_app_tokens SET last_used_at = NOW() WHERE id = ?');
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('i', $tokenId);
        $stmt->execute();
        $stmt->close();
    }

    private function clip(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    /**
     * MySQL DATETIME → ISO-8601 UTC with Z suffix (MOBILE_API_SPEC: "dates
     * ISO-8601 UTC; the app formats locally"). Interprets the wall-clock
     * value in the current PHP timezone, like gmdate elsewhere in the plugin.
     */
    private static function isoUtc(mixed $datetime): ?string
    {
        if (!is_string($datetime) || $datetime === '') {
            return null;
        }
        $ts = strtotime($datetime);
        return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}

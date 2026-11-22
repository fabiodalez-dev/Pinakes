<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

/**
 * Secret URL tokens for the personal loans calendar feed
 * (GET /api/v1/calendar/feed/{token}.ics), one per user.
 *
 * A calendar client cannot send an Authorization header, so the token IS the
 * credential: 256 random bits, looked up by its sha256 (constant-time
 * compared) like the device tokens. Unlike a device token the app must be able
 * to show the same URL again, so the plaintext is also kept encrypted at rest
 * (SettingsEncryption, AES-256-GCM) — never in clear.
 *
 * Rotation replaces the row in place: the previous URL stops working at once.
 * Revocation keeps the row (for created_at) but disables it.
 */
final class CalendarTokenService
{
    public const TABLE = 'mobile_calendar_tokens';

    public function __construct(private \mysqli $db)
    {
    }

    /**
     * @return array{enabled:bool, token:?string, created_at:?string, rotated_at:?string}
     */
    public function status(int $userId): array
    {
        $row = $this->row($userId);
        if ($row === null || $row['revoked_at'] !== null) {
            return [
                'enabled'    => false,
                'token'      => null,
                'created_at' => $row['created_at'] ?? null,
                'rotated_at' => $row['rotated_at'] ?? null,
            ];
        }
        $token = \App\Support\SettingsEncryption::decrypt($row['token_enc'], self::TABLE, 'user:' . $userId);
        $valid = is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) === 1
            && hash_equals($row['token_hash'], hash('sha256', $token));

        return [
            'enabled'    => $valid,
            'token'      => $valid ? $token : null,
            'created_at' => $row['created_at'],
            'rotated_at' => $row['rotated_at'],
        ];
    }

    /**
     * Create the user's token, or replace it (the old URL dies immediately).
     *
     * @return string the new plaintext token (64 hex chars)
     */
    public function rotate(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $enc = \App\Support\SettingsEncryption::encrypt($token, self::TABLE, 'user:' . $userId);

        $stmt = $this->db->prepare(
            'INSERT INTO ' . self::TABLE . ' (user_id, token_hash, token_enc, created_at, rotated_at, revoked_at)
             VALUES (?, ?, ?, NOW(), NULL, NULL)
             ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), token_enc = VALUES(token_enc),
                                     rotated_at = NOW(), revoked_at = NULL'
        );
        if ($stmt === false) {
            throw new \RuntimeException('calendar token prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('iss', $userId, $hash, $enc);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('calendar token write failed: ' . $error);
        }
        $stmt->close();

        return $token;
    }

    /** Idempotent: revoking an absent or already revoked token is a no-op. */
    public function revoke(int $userId): void
    {
        $stmt = $this->db->prepare('UPDATE ' . self::TABLE . ' SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL');
        if ($stmt === false) {
            throw new \RuntimeException('calendar token revoke prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            // Never answer "revoked" while the feed URL still works.
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('calendar token revoke failed: ' . $error);
        }
        $stmt->close();
    }

    /**
     * Owner of an active token, or null: unknown, malformed or revoked token,
     * or an owner who may no longer sign in (suspended / expired / unverified
     * — the same rule AppAuthMiddleware applies to device tokens), so a
     * suspension closes the feed even before anyone revokes it.
     */
    public function resolve(string $token): ?int
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            return null;
        }
        $hash = hash('sha256', $token);
        $stmt = $this->db->prepare(
            'SELECT t.user_id, t.token_hash
               FROM ' . self::TABLE . " t
               JOIN utenti u ON u.id = t.user_id AND u.stato = 'attivo' AND u.email_verificata = 1
              WHERE t.token_hash = ? AND t.revoked_at IS NULL
              LIMIT 1"
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!is_array($row) || !hash_equals((string) $row['token_hash'], $hash)) {
            return null;
        }

        return (int) $row['user_id'];
    }

    /**
     * @return array{token_hash:string, token_enc:string, created_at:?string, rotated_at:?string, revoked_at:?string}|null
     */
    private function row(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT token_hash, token_enc, created_at, rotated_at, revoked_at FROM ' . self::TABLE . ' WHERE user_id = ? LIMIT 1'
        );
        if ($stmt === false) {
            throw new \RuntimeException('calendar token read prepare failed: ' . $this->db->error);
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }

        return [
            'token_hash' => (string) $row['token_hash'],
            'token_enc'  => (string) $row['token_enc'],
            'created_at' => $row['created_at'] !== null ? (string) $row['created_at'] : null,
            'rotated_at' => $row['rotated_at'] !== null ? (string) $row['rotated_at'] : null,
            'revoked_at' => $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
        ];
    }
}

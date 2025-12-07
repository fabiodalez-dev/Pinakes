<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;

/**
 * Database-backed "Remember Me" service for persistent sessions.
 *
 * GDPR-compliant implementation:
 * - Tokens stored as SHA-256 hashes (original token never stored)
 * - Users can view and revoke their sessions
 * - Sessions automatically expire
 * - IP and device info logged for security auditing
 */
class RememberMeService
{
    private mysqli $db;
    private const COOKIE_NAME = 'remember_token';
    private const TOKEN_LIFETIME_DAYS = 30;
    private const TOKEN_LENGTH = 64; // 64 bytes = 512 bits

    /** @var bool|null Static cache for table existence check */
    private static ?bool $tableExistsCache = null;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Create a new remember token for a user and set the cookie.
     */
    public function createToken(int $userId): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        // Generate cryptographically secure token
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $tokenHash = hash('sha256', $token);

        // Get device info
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $deviceInfo = $this->parseDeviceInfo($userAgent);

        // Calculate expiry
        $expiresAt = gmdate('Y-m-d H:i:s', time() + (self::TOKEN_LIFETIME_DAYS * 24 * 60 * 60));

        // Store token hash in database
        $stmt = $this->db->prepare("
            INSERT INTO user_sessions (utente_id, token_hash, device_info, ip_address, user_agent, expires_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('isssss', $userId, $tokenHash, $deviceInfo, $ipAddress, $userAgent, $expiresAt);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            // Set cookie with original token (not the hash)
            $this->setCookie($token);
            return true;
        }

        return false;
    }

    /**
     * Validate remember token from cookie and return user ID if valid.
     */
    public function validateToken(): ?int
    {
        if (!$this->tableExists()) {
            return null;
        }

        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
        if ($token === null || strlen($token) !== self::TOKEN_LENGTH * 2) {
            return null;
        }

        $tokenHash = hash('sha256', $token);

        // Ensure timezone consistency
        $this->db->query("SET SESSION time_zone = '+00:00'");

        // Find valid, non-revoked, non-expired session
        $stmt = $this->db->prepare("
            SELECT id, utente_id
            FROM user_sessions
            WHERE token_hash = ?
              AND is_revoked = 0
              AND expires_at > NOW()
            LIMIT 1
        ");
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row) {
            // Update last_used_at timestamp
            $sessionId = (int) $row['id'];
            $this->updateLastUsed($sessionId);

            return (int) $row['utente_id'];
        }

        // Invalid token - clear the cookie
        $this->clearCookie();
        return null;
    }

    /**
     * Revoke the current remember token (logout from this device).
     */
    public function revokeCurrentToken(): void
    {
        if (!$this->tableExists()) {
            return;
        }

        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
        if ($token !== null) {
            $tokenHash = hash('sha256', $token);

            $stmt = $this->db->prepare("
                UPDATE user_sessions
                SET is_revoked = 1
                WHERE token_hash = ?
            ");
            if ($stmt !== false) {
                $stmt->bind_param('s', $tokenHash);
                $stmt->execute();
                $stmt->close();
            }
        }

        $this->clearCookie();
    }

    /**
     * Revoke all sessions for a user except the current one (logout from all other devices).
     */
    public function revokeAllTokens(int $userId): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        // Get current token hash to preserve current session
        $currentTokenHash = null;
        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
        if ($token !== null) {
            $currentTokenHash = hash('sha256', $token);
        }

        if ($currentTokenHash !== null) {
            // Preserve current session
            $stmt = $this->db->prepare("
                UPDATE user_sessions
                SET is_revoked = 1
                WHERE utente_id = ? AND is_revoked = 0 AND token_hash != ?
            ");
            if ($stmt === false) {
                return 0;
            }
            $stmt->bind_param('is', $userId, $currentTokenHash);
        } else {
            // No current session, revoke all
            $stmt = $this->db->prepare("
                UPDATE user_sessions
                SET is_revoked = 1
                WHERE utente_id = ? AND is_revoked = 0
            ");
            if ($stmt === false) {
                return 0;
            }
            $stmt->bind_param('i', $userId);
        }

        $stmt->execute();
        $affected = $this->db->affected_rows;
        $stmt->close();

        return $affected;
    }

    /**
     * Revoke a specific session by ID (for session management UI).
     */
    public function revokeSession(int $sessionId, int $userId): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE user_sessions
            SET is_revoked = 1
            WHERE id = ? AND utente_id = ?
        ");
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ii', $sessionId, $userId);
        $stmt->execute();
        $success = $this->db->affected_rows > 0;
        $stmt->close();

        return $success;
    }

    /**
     * Get all active sessions for a user (for session management UI).
     */
    public function getActiveSessions(int $userId): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $this->db->query("SET SESSION time_zone = '+00:00'");

        // Pre-compute current token hash to avoid N+1 queries
        $currentTokenHash = null;
        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
        if ($token !== null) {
            $currentTokenHash = hash('sha256', $token);
        }

        $stmt = $this->db->prepare("
            SELECT id, device_info, ip_address, created_at, expires_at, last_used_at, token_hash
            FROM user_sessions
            WHERE utente_id = ?
              AND is_revoked = 0
              AND expires_at > NOW()
            ORDER BY last_used_at DESC, created_at DESC
        ");
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $sessions = [];
        while ($row = $result->fetch_assoc()) {
            // Timing-safe comparison to prevent timing attacks
            $isCurrent = $currentTokenHash !== null && hash_equals($currentTokenHash, $row['token_hash']);

            $sessions[] = [
                'id' => (int) $row['id'],
                'device_info' => $row['device_info'],
                'ip_address' => $row['ip_address'],
                'created_at' => $row['created_at'],
                'expires_at' => $row['expires_at'],
                'last_used_at' => $row['last_used_at'],
                'is_current' => $isCurrent,
            ];
        }
        $stmt->close();

        return $sessions;
    }

    /**
     * Clean up expired and revoked sessions (for maintenance cron).
     */
    public function cleanupExpiredSessions(): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        $this->db->query("SET SESSION time_zone = '+00:00'");

        // Delete sessions older than 90 days or expired more than 30 days ago
        $stmt = $this->db->prepare("
            DELETE FROM user_sessions
            WHERE expires_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
               OR (is_revoked = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY))
        ");
        if ($stmt === false) {
            return 0;
        }
        $stmt->execute();
        $deleted = $this->db->affected_rows;
        $stmt->close();

        return $deleted;
    }

    /**
     * Update last_used_at timestamp for a session.
     */
    private function updateLastUsed(int $sessionId): void
    {
        $stmt = $this->db->prepare("
            UPDATE user_sessions
            SET last_used_at = NOW()
            WHERE id = ?
        ");
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Set the remember cookie.
     */
    private function setCookie(string $token): void
    {
        $expires = time() + (self::TOKEN_LIFETIME_DAYS * 24 * 60 * 60);
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

        setcookie(self::COOKIE_NAME, $token, [
            'expires' => $expires,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    /**
     * Clear the remember cookie.
     */
    private function clearCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    /**
     * Parse user agent to get a human-readable device description.
     */
    private function parseDeviceInfo(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        $browser = 'Unknown Browser';
        $os = 'Unknown OS';

        // Detect browser
        if (preg_match('/Firefox\/[\d.]+/i', $userAgent)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Edg\/[\d.]+/i', $userAgent)) {
            $browser = 'Edge';
        } elseif (preg_match('/Chrome\/[\d.]+/i', $userAgent) && !preg_match('/Edg/i', $userAgent)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Safari\/[\d.]+/i', $userAgent) && !preg_match('/Chrome/i', $userAgent)) {
            $browser = 'Safari';
        } elseif (preg_match('/MSIE|Trident/i', $userAgent)) {
            $browser = 'Internet Explorer';
        }

        // Detect OS
        if (preg_match('/Windows/i', $userAgent)) {
            $os = 'Windows';
        } elseif (preg_match('/Macintosh|Mac OS/i', $userAgent)) {
            $os = 'macOS';
        } elseif (preg_match('/Linux/i', $userAgent) && !preg_match('/Android/i', $userAgent)) {
            $os = 'Linux';
        } elseif (preg_match('/Android/i', $userAgent)) {
            $os = 'Android';
        } elseif (preg_match('/iPhone|iPad|iPod/i', $userAgent)) {
            $os = 'iOS';
        }

        return $browser . ' / ' . $os;
    }

    /**
     * Check if user_sessions table exists (graceful degradation).
     */
    private function tableExists(): bool
    {
        if (self::$tableExistsCache !== null) {
            return self::$tableExistsCache;
        }

        try {
            $result = $this->db->query("SHOW TABLES LIKE 'user_sessions'");
            self::$tableExistsCache = $result && $result->num_rows > 0;
        } catch (\Exception $e) {
            self::$tableExistsCache = false;
        }

        return self::$tableExistsCache;
    }

    /**
     * Reset the table existence cache.
     * Useful during migrations or testing when the table may be created mid-request.
     */
    public static function resetTableExistsCache(): void
    {
        self::$tableExistsCache = null;
    }
}

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
    /**
     * Session key holding the `user_sessions` row this PHP session belongs to.
     *
     * Revoking a device used to mark that row and stop there, and the check
     * that reads it ran only for a request arriving WITHOUT a session — so a
     * device already signed in kept using the session it had, and "revoke"
     * meant no more than "no further automatic sign-ins here". Binding the
     * session to its row is what lets a later request notice.
     */
    public const SESSION_ROW_KEY = 'remember_session_id';

    /**
     * True when the bound row belongs to an ordinary sign-in rather than a
     * remembered one. The session knows how it was opened; the row does not,
     * and the two kinds must not be treated alike — a remembered row's expiry
     * is the life of the cookie and is deliberately fixed, while an ordinary
     * one only has to outlast the session it describes.
     */
    public const SESSION_PLAIN_KEY = 'remember_session_plain';

    public function createToken(int $userId): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        // Generate cryptographically secure token
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $tokenHash = hash('sha256', $token);

        // Get device info (with proxy-aware IP detection)
        $ipAddress = $this->getClientIP();
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
        $rowId = (int) $this->db->insert_id;
        $stmt->close();

        if ($success) {
            // Set cookie with original token (not the hash)
            $this->setCookie($token);
            $this->bindSessionToRow($rowId);
            return true;
        }

        return false;
    }

    /**
     * Record an ORDINARY sign-in — no "remember me", no cookie — as a row in
     * user_sessions, and bind this session to it.
     *
     * Without this, revoking an account's credentials reached only the sessions
     * that happened to carry a remember-me cookie. Everything else is decided
     * from $_SESSION, which AuthMiddleware trusts without asking the database,
     * so a password reset ended the intruder's automatic sign-ins while the
     * browser they were already signed in on carried on until it expired by
     * itself. That is the opposite of what a reset is for.
     *
     * The row's token_hash is the hash of 32 bytes that are generated here and
     * never leave this method: nothing is ever sent to the client, so no cookie
     * can present it and this row can never authenticate anyone. It exists to
     * be revoked. Everything downstream — CredentialRevoker, the session list,
     * boundSessionIsRevoked() — then treats an ordinary sign-in exactly like a
     * remembered one, which is the behaviour a reader of "this ends every
     * session" expects.
     *
     * expires_at starts at the session's own lifetime (session.gc_maxlifetime,
     * as public/index.php set it from the installation's setting) rather than
     * the remember-me lifetime, so the account's session list does not fill up
     * with entries for browsers closed weeks ago. That figure is an INACTIVITY
     * timeout, so keepBoundPlainSessionAlive() pushes the row forward while the
     * person is still working — without it the stamp would act as an absolute
     * deadline and sign an active session out.
     */
    public function bindPlainSession(int $userId): bool
    {
        if ($userId <= 0 || !$this->tableExists()) {
            return false;
        }

        $tokenHash = hash('sha256', 'session:' . bin2hex(random_bytes(32)));
        $ipAddress = $this->getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $deviceInfo = $this->parseDeviceInfo($userAgent);
        $lifetime = (int) ini_get('session.gc_maxlifetime');
        if ($lifetime <= 0) {
            $lifetime = 24 * 60 * 60;
        }
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $lifetime);

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO user_sessions (utente_id, token_hash, device_info, ip_address, user_agent, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            if ($stmt === false) {
                return false;
            }
            $stmt->bind_param('isssss', $userId, $tokenHash, $deviceInfo, $ipAddress, $userAgent, $expiresAt);
            $stmt->execute();
            $rowId = (int) $this->db->insert_id;
            $stmt->close();
        } catch (\Throwable $e) {
            // A sign-in must not fail because its audit row could not be
            // written; log it and leave the session unbound, which is exactly
            // how every session behaved before this method existed.
            SecureLogger::warning('[RememberMeService] Could not record the sign-in: ' . $e->getMessage());
            return false;
        }

        if ($rowId <= 0) {
            return false;
        }
        $this->bindSessionToRow($rowId, true);

        return true;
    }

    /**
     * Push an ordinary sign-in's row forward while the person is still working.
     *
     * session.gc_maxlifetime is an INACTIVITY timeout: PHP renews it on every
     * request, so an active session outlives it indefinitely. The row was
     * stamped once at sign-in with that same figure, which made it an ABSOLUTE
     * deadline instead — and since a bound row that has expired signs the
     * session out, a librarian cataloguing for longer than the configured
     * window was thrown out mid-form while their PHP session was perfectly
     * alive. With the setting at its five-minute minimum that is almost
     * immediate.
     *
     * Only ordinary rows move. A remembered row's expiry is the life of the
     * cookie it issued and is deliberately fixed: renewing it would let a
     * thirty-day cookie live for ever, one request at a time.
     *
     * One statement, no read, and the WHERE clause is the throttle — it writes
     * only once the row is past the halfway mark, so a busy session costs one
     * UPDATE per half window rather than one per request.
     */
    public function keepBoundPlainSessionAlive(): void
    {
        if (empty($_SESSION[self::SESSION_PLAIN_KEY])) {
            return;
        }
        $rowId = isset($_SESSION[self::SESSION_ROW_KEY]) ? (int) $_SESSION[self::SESSION_ROW_KEY] : 0;
        if ($rowId <= 0 || !$this->tableExists()) {
            return;
        }

        $lifetime = (int) ini_get('session.gc_maxlifetime');
        if ($lifetime <= 0) {
            $lifetime = 24 * 60 * 60;
        }
        $renewTo = gmdate('Y-m-d H:i:s', time() + $lifetime);
        $halfway = gmdate('Y-m-d H:i:s', time() + intdiv($lifetime, 2));

        try {
            $stmt = $this->db->prepare(
                'UPDATE user_sessions SET expires_at = ?
                  WHERE id = ? AND is_revoked = 0 AND expires_at < ?'
            );
            if ($stmt === false) {
                return;
            }
            $stmt->bind_param('sis', $renewTo, $rowId, $halfway);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            // Never end a request over this: the worst case is the row expiring
            // on its own schedule, which is where it was before.
            SecureLogger::warning('[RememberMeService] Could not extend the sign-in row: ' . $e->getMessage());
        }
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
            $this->bindSessionToRow($sessionId);

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
     * Remember which `user_sessions` row this PHP session was opened from.
     *
     * Every sign-in creates one — createToken() with a cookie, bindPlainSession()
     * without — so revoking an account's credentials reaches the browser it is
     * being revoked from and not only the ones that asked to be remembered.
     */
    private function bindSessionToRow(int $rowId, bool $plain = false): void
    {
        if ($rowId > 0 && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_ROW_KEY] = $rowId;
            if ($plain) {
                $_SESSION[self::SESSION_PLAIN_KEY] = true;
            } else {
                unset($_SESSION[self::SESSION_PLAIN_KEY]);
            }
        }
    }

    /**
     * True when the row this session is bound to has been revoked or expired.
     *
     * Answers false for a session that is bound to nothing. Every sign-in binds
     * one now, so in practice that means a session opened before this shipped,
     * or one on an installation whose user_sessions table is missing: neither
     * can be judged, and refusing them would sign the library out on upgrade.
     * Also false when the lookup fails — a database hiccup must not sign
     * everyone out, and the next request asks again.
     */
    public function boundSessionIsRevoked(): bool
    {
        $rowId = isset($_SESSION[self::SESSION_ROW_KEY]) ? (int) $_SESSION[self::SESSION_ROW_KEY] : 0;
        if ($rowId <= 0 || !$this->tableExists()) {
            return false;
        }

        try {
            // UTC_TIMESTAMP() rather than the SET SESSION time_zone the other
            // methods here use. expires_at is stored in UTC, so both spellings
            // compare correctly — but this one runs on EVERY authenticated
            // request through RememberMeMiddleware, and moving the shared
            // connection's time zone that often would leave it changed under
            // whatever reads a date next. A comparison does not need the
            // session's clock; only formatting does.
            $stmt = $this->db->prepare(
                'SELECT is_revoked, (expires_at > UTC_TIMESTAMP()) AS still_valid
                   FROM user_sessions WHERE id = ? LIMIT 1'
            );
            if ($stmt === false) {
                return false;
            }
            $stmt->bind_param('i', $rowId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $e) {
            SecureLogger::warning('[RememberMeService] Could not check whether this session was revoked: ' . $e->getMessage());
            return false;
        }

        // A row that has been DELETED outright counts as revoked: the operator
        // asked for that device to stop, and a missing row is not a reason to
        // keep going.
        if (!is_array($row)) {
            return true;
        }

        return (int) ($row['is_revoked'] ?? 0) === 1 || (int) ($row['still_valid'] ?? 0) !== 1;
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

        // The row THIS session was opened from. Every sign-in binds one now,
        // including one with no "remember me" cookie — and that is the case the
        // cookie comparison below cannot answer, because there is no cookie to
        // compare. Without this the browser the reader is looking at the page
        // from would be listed as somebody else's device.
        $boundRow = isset($_SESSION[self::SESSION_ROW_KEY]) ? (int) $_SESSION[self::SESSION_ROW_KEY] : 0;

        $sessions = [];
        while ($row = $result->fetch_assoc()) {
            // Timing-safe comparison to prevent timing attacks
            $isCurrent = ($boundRow > 0 && (int) $row['id'] === $boundRow)
                || ($currentTokenHash !== null && hash_equals($currentTokenHash, $row['token_hash']));

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
        $secure = $this->isSecureConnection();

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
    /**
     * Drop the remember-me cookie of a session that has just been revoked.
     *
     * Without this the browser keeps presenting a token the next request would
     * look up, fail to validate and clear anyway — one wasted round trip, and a
     * confusing moment where the device looks signed in until it reloads.
     */
    public function clearCookieForRevokedSession(): void
    {
        $this->clearCookie();
    }

    private function clearCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => $this->isSecureConnection(),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    /**
     * Detect if the connection is secure (HTTPS).
     * Handles reverse proxy/load balancer scenarios via X-Forwarded-Proto header.
     * Also respects the application's force_https configuration setting.
     */
    private function isSecureConnection(): bool
    {
        // Check if app is configured to force HTTPS (always set secure cookie)
        if (class_exists('\App\Support\ConfigStore')) {
            $forceHttps = \App\Support\ConfigStore::get('advanced.force_https', false);
            if ($forceHttps) {
                return true;
            }
        }

        // Direct HTTPS
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        // Behind reverse proxy/load balancer (Nginx, Apache, Cloudflare, etc.)
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }

        // Cloudflare specific header
        if (isset($_SERVER['HTTP_CF_VISITOR'])) {
            $visitor = json_decode($_SERVER['HTTP_CF_VISITOR'], true);
            if (isset($visitor['scheme']) && $visitor['scheme'] === 'https') {
                return true;
            }
        }

        return false;
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
     * Get client IP address with proxy support.
     * Checks trusted proxy headers for reverse proxy/load balancer deployments.
     */
    private function getClientIP(): ?string
    {
        $remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remoteAddr === '') {
            return null;
        }

        $resolved = ClientIpResolver::resolve($remoteAddr, [
            'X-Forwarded-For' => (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
            'X-Real-IP' => (string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''),
            'CF-Connecting-IP' => (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
            'True-Client-IP' => (string) ($_SERVER['HTTP_TRUE_CLIENT_IP'] ?? ''),
            'X-Client-IP' => (string) ($_SERVER['HTTP_X_CLIENT_IP'] ?? ''),
        ]);

        // An unparseable REMOTE_ADDR resolves to the 'unknown' sentinel; keep
        // the audit column null rather than writing a literal "unknown".
        return $resolved === 'unknown' ? null : $resolved;
    }

    /**
     * Check if user_sessions table exists (graceful degradation).
     *
     * Result is cached statically for the duration of the request/process.
     * Call resetTableExistsCache() if the table may be created mid-execution.
     */
    private function tableExists(): bool
    {
        if (self::$tableExistsCache !== null) {
            return self::$tableExistsCache;
        }

        try {
            $result = $this->db->query("SHOW TABLES LIKE 'user_sessions'");
            if ($result === false) {
                error_log("[RememberMeService] tableExists query failed: " . $this->db->error);
                self::$tableExistsCache = false;
            } else {
                self::$tableExistsCache = $result->num_rows > 0;
            }
        } catch (\Throwable $e) {
            error_log("[RememberMeService] tableExists exception: " . $e->getMessage());
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

<?php
declare(strict_types=1);

namespace App\Support;

class RateLimiter
{
    private const WINDOW = 900; // 15 minutes in seconds
    private const MAX_ATTEMPTS = 10; // Max attempts per window

    /**
     * Check if the request should be rate limited.
     * Uses file-based storage with flock() for atomic read-modify-write.
     */
    public static function isLimited(string $identifier, int $maxAttempts = self::MAX_ATTEMPTS, int $window = self::WINDOW): bool
    {
        // Elapsed-based window measurement: microtime(true) keeps the window a
        // true float duration instead of quantizing the first-attempt marker to
        // whole seconds. Same limits and same file-backed shared state — only
        // the window boundary is finer-grained.
        $currentTime = microtime(true);
        $file = self::getStateFile($identifier);
        if ($file === null) {
            // No usable place for the counter (logged in getStateFile()).
            // Answering "limited" here locked every login, registration and
            // password reset out with no way back; the degraded limiter keeps
            // counting in APCu or in the session instead.
            return self::degradedIsLimited($identifier, $maxAttempts, $window);
        }

        // Open (or create) state file with exclusive lock for atomic read-modify-write
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            // Same reasoning: a counter file we cannot open (e.g. left behind
            // by another user) must not lock this identifier out for good.
            self::logDegraded('RateLimiter: failed to open the state file, using the degraded limiter', ['file' => basename($file)]);
            return self::degradedIsLimited($identifier, $maxAttempts, $window);
        }

        // The opened handle must be the regular file that sits at $file, not
        // something a symlink swapped in between the check and the open.
        if (!self::handleIsPlainFile($handle, $file)) {
            fclose($handle);
            SecureLogger::error('RateLimiter: state file is not a plain file, refusing it', ['file' => basename($file)]);
            return true;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                // Fail-closed: if we can't lock, deny the request
                error_log("RateLimiter: failed to acquire lock on: {$file}");
                return true;
            }

            // Read current state under lock
            $raw = stream_get_contents($handle);
            $state = ['attempts' => 0, 'first_attempt' => 0.0];
            if ($raw !== false && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $state = [
                        'attempts' => (int) ($data['attempts'] ?? 0),
                        'first_attempt' => (float) ($data['first_attempt'] ?? 0),
                    ];
                }
            }

            // Reset if window expired
            if ($state['first_attempt'] > 0 && ($currentTime - $state['first_attempt']) > $window) {
                $state = ['attempts' => 0, 'first_attempt' => 0.0];
            }

            if ($state['attempts'] === 0) {
                $state['first_attempt'] = $currentTime;
            }

            $state['attempts']++;

            // Write updated state under lock
            if (ftruncate($handle, 0) === false) {
                error_log("RateLimiter: ftruncate failed on: {$file}");
            }
            rewind($handle);
            $json = json_encode($state);
            if ($json !== false && fwrite($handle, $json) === false) {
                error_log("RateLimiter: fwrite failed on: {$file}");
            }
            fflush($handle);

            return $state['attempts'] > $maxAttempts;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Reset rate limit for an identifier
     */
    public static function reset(string $identifier): void
    {
        $key = self::degradedKey($identifier);
        if (self::apcuAvailable()) {
            apcu_delete($key);
        }
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[self::SESSION_KEY][$key])) {
            unset($_SESSION[self::SESSION_KEY][$key]);
        }
        $file = self::getStateFile($identifier);
        if ($file === null) {
            return;
        }
        // unlink() on a symlink removes the link itself, never its target.
        if (is_file($file) || is_link($file)) {
            @unlink($file);
        }
    }

    /**
     * Directory holding the throttle counters. It lives inside the install
     * (storage/cache/ratelimit), never in the host-shared temp dir: there, every
     * install and every co-tenant on the host shared one namespace, so they could
     * reset each other's lockouts, plant a symlink the next write would follow, or
     * pre-create the directory to lock everyone out. When this directory cannot
     * be used, fallbackStateDirectory() (per install and per user) takes over,
     * and when that cannot be used either, degradedIsLimited().
     */
    public static function stateDirectory(): string
    {
        return dirname(__DIR__, 2) . '/storage/cache/ratelimit';
    }

    /**
     * Per-install secret, derived from the .env keys (APP_KEY, else
     * PLUGIN_ENCRYPTION_KEY) and hashed; null when neither is set.
     */
    private static function installSecret(): ?string
    {
        foreach (['APP_KEY', 'PLUGIN_ENCRYPTION_KEY'] as $name) {
            $value = $_ENV[$name] ?? getenv($name);
            if (is_string($value) && $value !== '') {
                return hash('sha256', 'pinakes-ratelimit|' . $value);
            }
        }
        return null;
    }

    /**
     * Fallback directory, used when stateDirectory() cannot be created or
     * trusted: a per-install, per-user directory under sys_get_temp_dir(). The
     * name is derived from a per-install secret (installSecret()), the install
     * root and the effective uid, so a co-tenant cannot predict it to pre-create
     * or squat it, and neither another install nor a root CLI run shares it
     * with the web server user. Null when no secret is configured: a
     * predictable name in a shared directory is not offered.
     */
    public static function fallbackStateDirectory(): ?string
    {
        $secret = self::installSecret();
        if ($secret === null) {
            return null;
        }
        $root = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
        $uid = function_exists('posix_geteuid') ? (string) posix_geteuid() : (string) getmyuid();
        return rtrim(sys_get_temp_dir(), '/\\') . '/pinakes_rl_'
            . substr(hash('sha256', $secret . "\0" . $root . "\0" . $uid), 0, 32);
    }

    /** Session slot of the degraded, in-session counters. */
    private const SESSION_KEY = '_pinakes_ratelimit';

    /** Whether the degraded-mode error was already logged in this request. */
    private static bool $degradedLogged = false;

    /** Whether "throttling is off" was already logged in this request. */
    private static bool $offLogged = false;

    /**
     * Log, once per request, that the file-backed limiter could not be used.
     *
     * @param array<string, mixed> $context
     */
    private static function logDegraded(string $message, array $context = []): void
    {
        if (self::$degradedLogged) {
            return;
        }
        self::$degradedLogged = true;
        SecureLogger::error($message, $context);
    }

    private static function apcuAvailable(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    /** Install-scoped key of an identifier for the degraded counters. */
    private static function degradedKey(string $identifier): string
    {
        $root = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
        return 'pinakes_rl_' . hash('sha256', $root . "\0" . (self::installSecret() ?? '') . "\0" . $identifier);
    }

    /**
     * Limiter used when no state directory can be used: an APCu counter
     * (shared by the PHP processes of this server) when APCu is enabled,
     * otherwise a counter in the PHP session when one is active. Only when
     * neither exists does it let the request through, and that is logged.
     */
    private static function degradedIsLimited(string $identifier, int $maxAttempts, int $window): bool
    {
        $key = self::degradedKey($identifier);
        if (self::apcuAvailable()) {
            // apcu_add() starts the window with the entry's TTL; apcu_inc() is atomic.
            apcu_add($key, 0, max(1, $window));
            $attempts = apcu_inc($key);
            if (is_int($attempts)) {
                return $attempts > $maxAttempts;
            }
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $now = microtime(true);
            $bucket = $_SESSION[self::SESSION_KEY] ?? [];
            if (!is_array($bucket)) {
                $bucket = [];
            }
            $state = $bucket[$key] ?? null;
            if (!is_array($state) || ($now - (float) ($state['first'] ?? 0)) > $window) {
                $state = ['attempts' => 0, 'first' => $now];
            }
            $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;
            $bucket[$key] = $state;
            $_SESSION[self::SESSION_KEY] = $bucket;
            return $state['attempts'] > $maxAttempts;
        }
        // Worse than the degraded mode, so it gets its own (once per request) entry.
        if (!self::$offLogged) {
            self::$offLogged = true;
            SecureLogger::error('RateLimiter: no state directory, no APCu and no session, throttling is off for this request');
        }
        return false;
    }

    /**
     * A real directory (never a symlink) owned by $euid, tightened to 0700 and
     * writable: the only kind of directory the counters are written into.
     * Created 0700 when missing and $mayCreate.
     */
    private static function ownPrivateDirectory(string $dir, int $euid, bool $mayCreate, callable $ownerOf): bool
    {
        if ($mayCreate && !is_dir($dir) && !is_link($dir)) {
            @mkdir($dir, 0700, true);
        }
        clearstatcache(true, $dir);
        if (is_link($dir) || !is_dir($dir) || $ownerOf($dir) !== $euid) {
            return false;
        }
        if ((self::modeOf($dir) & 0077) !== 0) {
            @chmod($dir, 0700);
        }
        return (self::modeOf($dir) & 0077) === 0 && is_writable($dir);
    }

    /** Fresh permission bits of $path; an unreadable mode counts as wide open. */
    private static function modeOf(string $path): int
    {
        clearstatcache(true, $path);
        $mode = @fileperms($path);
        return $mode === false ? 0777 : $mode;
    }

    /**
     * The directory, under $dir, the counters can go to, or null.
     *
     *  - $dir owned by us: $dir itself, tightened to 0700.
     *  - $dir owned by another uid (e.g. `chown -R deploy:www-data storage`),
     *    with $acceptForeignOwner: never written into directly. Unless it is
     *    world-writable, a private subdirectory "u<euid>" (0700, ours) is used
     *    inside it; one that someone else created, or a symlink, is refused.
     *  - Root never uses a directory owned by someone else: files it created
     *    there could not be opened by the web server user later.
     * $euid defaults to the process' own and $ownerOf to fileowner() (both
     * replaceable so the tests can stage a directory owned by someone else).
     */
    private static function prepareDirectory(string $dir, bool $acceptForeignOwner, ?int $euid = null, ?callable $ownerOf = null): ?string
    {
        if ($euid === null) {
            $euid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        }
        $ownerOf ??= static fn (string $path): int|false => @fileowner($path);
        if (!is_dir($dir) && !is_link($dir)) {
            @mkdir($dir, 0700, true);
        }
        clearstatcache(true, $dir);
        if (is_link($dir) || !is_dir($dir)) {
            return null;
        }
        $owner = $ownerOf($dir);
        $perms = @fileperms($dir);
        if ($owner === false || $perms === false) {
            return null;
        }
        if ($owner === $euid) {
            return self::ownPrivateDirectory($dir, $euid, false, $ownerOf) ? $dir : null;
        }
        if (!$acceptForeignOwner || $euid === 0 || ($perms & 0002) !== 0 || !is_writable($dir)) {
            return null;
        }
        $private = $dir . '/u' . $euid;
        return self::ownPrivateDirectory($private, $euid, true, $ownerOf) ? $private : null;
    }

    /**
     * The directory the counters go to, or null when neither the install's
     * directory nor the temp-dir fallback can be used.
     */
    private static function resolveStateDirectory(): ?string
    {
        $dir = self::stateDirectory();
        // A root CLI run (a cron job in a container, say) must never create the
        // install's directory: it would be root-owned and 0700, and the web
        // server user could not use it. Root goes to its own fallback instead,
        // and never writes into a directory owned by another uid.
        $isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
        $creatable = !$isRoot || is_dir($dir) || is_link($dir);
        if ($creatable) {
            $usable = self::prepareDirectory($dir, !$isRoot);
            if ($usable !== null) {
                return $usable;
            }
        }
        // The temp dir is shared with every user of the host: there the
        // directory must be ours, never someone else's.
        $fallback = self::fallbackStateDirectory();
        if ($fallback !== null) {
            return self::prepareDirectory($fallback, false);
        }
        return null;
    }

    /**
     * Path of the state file for an identifier, or null when no state directory
     * can be used (callers then use the degraded limiter, and it is logged). The file name
     * mixes in the install root, so two installs never derive the same name for
     * the same identifier.
     */
    private static function getStateFile(string $identifier): ?string
    {
        $dir = self::resolveStateDirectory();
        if ($dir === null) {
            self::logDegraded('RateLimiter: no usable state directory, using the degraded limiter', [
                'dir' => self::stateDirectory(),
                'fallback' => self::fallbackStateDirectory() === null ? 'none (no APP_KEY)' : 'unusable',
            ]);
            return null;
        }

        $root = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
        $file = $dir . '/' . hash('sha256', $root . "\0" . $identifier) . '.json';
        if (is_link($file)) {
            // Never follow a link out of the state directory: drop it and let the
            // caller create a fresh regular file.
            @unlink($file);
            if (is_link($file)) {
                SecureLogger::error('RateLimiter: could not remove a symlinked state file', ['file' => basename($file)]);
                return null;
            }
        }

        return $file;
    }

    /**
     * True when $handle is the regular file currently linked at $path (same
     * device and inode as an lstat of the path, which does not follow links).
     *
     * @param resource $handle
     */
    private static function handleIsPlainFile($handle, string $path): bool
    {
        $opened = @fstat($handle);
        $atPath = @lstat($path);
        if ($opened === false || $atPath === false) {
            return false;
        }
        if (($atPath['mode'] & 0170000) !== 0100000) {
            return false; // a symlink, FIFO, directory...
        }
        return $opened['dev'] === $atPath['dev'] && $opened['ino'] === $atPath['ino'];
    }
}

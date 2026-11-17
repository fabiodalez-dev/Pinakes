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
    public static function isLimited(string $identifier, int $maxAttempts = self::MAX_ATTEMPTS, int $window = self::WINDOW, bool $count = true): bool
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
            return self::degradedIsLimited($identifier, $maxAttempts, $window, $count);
        }

        // Open (or create) state file with exclusive lock for atomic read-modify-write
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            // Same reasoning: a counter file we cannot open (e.g. left behind
            // by another user) must not lock this identifier out for good.
            self::logDegraded('RateLimiter: failed to open the state file, using the degraded limiter', ['file' => basename($file)]);
            return self::degradedIsLimited($identifier, $maxAttempts, $window, $count);
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

            // Read-only check: report whether the bucket is already over its
            // budget without spending an attempt (used for failure-only
            // counters that a successful request must not grow).
            if (!$count) {
                return $state['attempts'] >= $maxAttempts;
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
            self::pruneExpired();
        }
    }

    /**
     * Canonical form of a login name for a per-account bucket key. The users
     * table compares emails under a case- and accent-insensitive, PAD SPACE
     * collation, so 'Admin@x ', 'admin@x' and 'ädmin@x' reach the same
     * account: keying on the raw string would hand an attacker a fresh,
     * empty counter for every spelling of one victim's email.
     */
    public static function accountKey(string $account): string
    {
        $account = trim($account);
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($account, \Normalizer::FORM_KD);
            if (is_string($decomposed)) {
                $account = (string) (preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $account);
            }
        }
        return hash('sha256', mb_strtolower($account, 'UTF-8'));
    }

    /**
     * Bucket key for a client IP. An IPv6 client is keyed on its /64: one
     * end-site normally owns a whole /64, so keying the full /128 let a
     * client rotate through fresh addresses — and fresh, empty counters —
     * at will. An IPv4-mapped IPv6 address is keyed as the IPv4 it carries.
     * Anything that is not an IP (e.g. 'unknown') is returned unchanged.
     */
    public static function clientKey(string $ip): string
    {
        $packed = @inet_pton(trim($ip, '[]'));
        if ($packed === false) {
            return $ip;
        }
        if (strlen($packed) !== 16) {
            return (string) inet_ntop($packed);
        }
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /** State files untouched for this long are expired for every window in use (max 1 h). */
    private const PRUNE_AGE = 86400;
    /** One isLimited() call in PRUNE_ODDS sweeps the directory. */
    private const PRUNE_ODDS = 100;
    /** Upper bound of directory entries one sweep looks at. */
    private const PRUNE_BATCH = 500;

    /**
     * Opportunistic garbage collection of expired state files: every file is
     * one identifier, and nothing else ever removes the ones whose client
     * never came back. Cheap and bounded — runs on a small fraction of
     * calls and inspects at most PRUNE_BATCH entries.
     */
    public static function pruneExpired(?int $now = null, bool $force = false): int
    {
        if (!$force && random_int(1, self::PRUNE_ODDS) !== 1) {
            return 0;
        }
        $dir = self::stateDirectory();
        if (is_link($dir) || !is_dir($dir)) {
            return 0;
        }
        $handle = @opendir($dir);
        if ($handle === false) {
            return 0;
        }
        $cutoff = ($now ?? time()) - self::PRUNE_AGE;
        $removed = 0;
        $seen = 0;
        try {
            while ($seen < self::PRUNE_BATCH && ($entry = readdir($handle)) !== false) {
                if (preg_match('/^[0-9a-f]{64}\.json$/D', $entry) !== 1) {
                    continue;
                }
                $seen++;
                $path = $dir . '/' . $entry;
                $mtime = @filemtime($path);
                if ($mtime !== false && $mtime < $cutoff && @unlink($path)) {
                    $removed++;
                }
            }
        } finally {
            closedir($handle);
        }

        return $removed;
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
     * (shared by the PHP processes of this server, see apcuCount()) when APCu is enabled,
     * otherwise a counter in the PHP session when one is active. Only when
     * neither exists does it let the request through, and that is logged.
     *
     * $count = false is the same read-only check as on the file-backed path:
     * it reports whether the bucket is already at its budget and never spends
     * an attempt, so a failures-only bucket does not grow on every success.
     */
    private static function degradedIsLimited(string $identifier, int $maxAttempts, int $window, bool $count = true): bool
    {
        $key = self::degradedKey($identifier);
        if (!$count) {
            return self::degradedAttempts($key, $window) >= $maxAttempts;
        }
        if (self::apcuAvailable()) {
            $attempts = self::apcuCount($key, $window);
            if ($attempts !== null) {
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
     * Attempts already counted in the current window of a degraded bucket,
     * read without counting one (APCu first, then the session, as
     * degradedIsLimited() counts them). 0 when nothing holds a counter.
     */
    private static function degradedAttempts(string $key, int $window): int
    {
        if (self::apcuAvailable() && PHP_INT_SIZE >= 8) {
            $current = apcu_fetch($key, $found);
            if (!$found || !is_int($current) || $current <= 0) {
                return 0; // missing, corrupt, or a window being reset
            }
            $start = $current >> self::APCU_COUNT_BITS;
            $now = time();
            if ($now - $start >= max(1, $window) || $start > $now) {
                return 0;
            }
            return $current & ((1 << self::APCU_COUNT_BITS) - 1);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $bucket = $_SESSION[self::SESSION_KEY] ?? [];
            $state = is_array($bucket) ? ($bucket[$key] ?? null) : null;
            if (!is_array($state) || (microtime(true) - (float) ($state['first'] ?? 0)) > $window) {
                return 0;
            }
            return (int) ($state['attempts'] ?? 0);
        }
        return 0;
    }

    /** Bits of the packed APCu value that hold the attempt count. */
    private const APCU_COUNT_BITS = 20;

    /**
     * Count one attempt in APCu and return the attempts in the current window,
     * or null when APCu cannot hold the counter (32-bit PHP).
     *
     * One entry per identifier, a single integer packing [window_start, count]
     * (64-bit PHP only: the packed start would overflow a 32-bit int).
     *
     *  - Missing: apcu_add() of a fresh window, with the TTL. One request wins,
     *    the others retry against the new entry.
     *  - Live window: apcu_cas() from the value read to count + 1, so
     *    concurrent requests never lose an attempt.
     *  - Expired window (judged from the stored start, never from the TTL, so
     *    an entry left without a TTL cannot lock anyone out): one request swaps
     *    the stale value for a tombstone (a negative timestamp) with
     *    apcu_cas(), then deletes it and apcu_add()s the fresh window with a
     *    fresh TTL. A reset never keeps the old TTL, which could evict the new
     *    window early and hand out extra attempts. The others retry. A
     *    tombstone left by a request that died mid-reset is taken over after
     *    a few seconds.
     * When the retries run out (contention, which is what an attacker
     * produces), the attempt counts as limited: fail closed.
     *
     * $maxTries, $beforeWrite (run between the read and the write) and
     * $intSize exist for the tests.
     */
    private static function apcuCount(string $key, int $window, int $maxTries = 32, ?callable $beforeWrite = null, int $intSize = PHP_INT_SIZE): ?int
    {
        if ($intSize < 8) {
            return null;
        }
        $window = max(1, $window);
        $mask = (1 << self::APCU_COUNT_BITS) - 1;
        for ($try = 0; $try < $maxTries; $try++) {
            $now = time();
            $fresh = ($now << self::APCU_COUNT_BITS) | 1;
            $current = apcu_fetch($key, $found);
            if ($beforeWrite !== null) {
                $beforeWrite();
            }
            if (!$found) {
                if (apcu_add($key, $fresh, $window)) {
                    return 1;
                }
                continue; // somebody else started the window first
            }
            if (!is_int($current) || $current === 0) {
                apcu_delete($key); // not ours / corrupt: start over
                continue;
            }
            if ($current < 0) {
                // Another request is resetting the window. Take over only a
                // tombstone whose owner evidently died.
                if ($now + $current > 5 && apcu_cas($key, $current, -$now)) {
                    apcu_delete($key);
                    if (apcu_add($key, $fresh, $window)) {
                        return 1;
                    }
                }
                usleep(1000);
                continue;
            }
            $start = $current >> self::APCU_COUNT_BITS;
            $count = $current & $mask;
            if ($now - $start >= $window || $start > $now) {
                if (apcu_cas($key, $current, -$now)) {
                    apcu_delete($key);
                    if (apcu_add($key, $fresh, $window)) {
                        return 1;
                    }
                }
                continue;
            }
            $next = ($start << self::APCU_COUNT_BITS) | min($count + 1, $mask);
            if (apcu_cas($key, $current, $next)) {
                return $next & $mask;
            }
        }
        return $mask; // retries exhausted: limited
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

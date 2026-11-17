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
            // No usable place for the counter (logged in getStateFile()). Not
            // throttling is the lesser harm: answering "limited" here locked
            // every login, registration and password reset out with no way back.
            return false;
        }

        // Open (or create) state file with exclusive lock for atomic read-modify-write
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            // Same reasoning: a counter file we cannot open (e.g. left behind
            // by another user) must not lock this identifier out for good.
            self::logDegraded('RateLimiter: failed to open the state file, throttling is off for this request', ['file' => basename($file)]);
            return false;
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
     * be used, fallbackStateDirectory() (per install and per user) takes over.
     */
    public static function stateDirectory(): string
    {
        return dirname(__DIR__, 2) . '/storage/cache/ratelimit';
    }

    /**
     * Fallback directory, used when stateDirectory() cannot be created or
     * trusted: a per-install, per-user directory under sys_get_temp_dir(). The
     * name mixes the install root and the effective uid, so neither another
     * install nor a root CLI run shares it with the web server user.
     */
    public static function fallbackStateDirectory(): string
    {
        $root = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
        $uid = function_exists('posix_geteuid') ? (string) posix_geteuid() : (string) getmyuid();
        return rtrim(sys_get_temp_dir(), '/\\') . '/pinakes_rl_' . substr(hash('sha256', $root . "\0" . $uid), 0, 32);
    }

    /** Whether the "no usable state directory" error was already logged in this request. */
    private static bool $degradedLogged = false;

    /**
     * Log, once per request, that throttling is off because no state directory
     * could be used.
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

    /**
     * Prepare $dir for the counters and say whether it can be trusted: a real
     * directory (never a symlink), writable by us, and not open to other users.
     * A directory we own is tightened to 0700. With $acceptForeignOwner a
     * directory owned by another uid (e.g. `chown -R deploy:www-data storage`)
     * is accepted when it is not world-writable; a world-writable one owned by
     * someone else is always refused. $euid defaults to the process' own.
     */
    private static function prepareDirectory(string $dir, bool $acceptForeignOwner, ?int $euid = null): bool
    {
        if (!is_dir($dir) && !is_link($dir)) {
            @mkdir($dir, 0700, true);
        }
        clearstatcache(true, $dir);
        if (is_link($dir) || !is_dir($dir)) {
            return false;
        }
        $perms = @fileperms($dir);
        if ($perms === false) {
            return false;
        }
        $ownedByUs = true;
        if ($euid === null && function_exists('posix_geteuid')) {
            $euid = posix_geteuid();
        }
        if ($euid !== null) {
            $owner = @fileowner($dir);
            if ($owner === false) {
                return false;
            }
            $ownedByUs = $owner === $euid;
        }
        if ($ownedByUs) {
            if (($perms & 0077) !== 0) {
                @chmod($dir, 0700);
                clearstatcache(true, $dir);
                if ((@fileperms($dir) & 0002) !== 0) {
                    return false; // could not tighten a world-writable directory
                }
            }
        } elseif (!$acceptForeignOwner || ($perms & 0002) !== 0) {
            return false;
        }
        return is_writable($dir);
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
        // server user could not use it. Root goes to its own fallback instead.
        $isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
        $creatable = !$isRoot || is_dir($dir) || is_link($dir);
        if ($creatable && self::prepareDirectory($dir, true)) {
            return $dir;
        }
        // The temp dir is shared with every user of the host: there the
        // directory must be ours, never someone else's.
        $fallback = self::fallbackStateDirectory();
        if (self::prepareDirectory($fallback, false)) {
            return $fallback;
        }
        return null;
    }

    /**
     * Path of the state file for an identifier, or null when no state directory
     * can be used (callers then do not throttle, and log it). The file name
     * mixes in the install root, so two installs never derive the same name for
     * the same identifier.
     */
    private static function getStateFile(string $identifier): ?string
    {
        $dir = self::resolveStateDirectory();
        if ($dir === null) {
            self::logDegraded('RateLimiter: no usable state directory, throttling is off for this request', [
                'dir' => self::stateDirectory(),
                'fallback' => self::fallbackStateDirectory(),
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

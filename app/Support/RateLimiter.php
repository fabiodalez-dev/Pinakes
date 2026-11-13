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
            // Fail-closed: no trustworthy place to keep the counter.
            return true;
        }

        // Open (or create) state file with exclusive lock for atomic read-modify-write
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            // Fail-closed: if we can't open the state file, deny the request
            error_log("RateLimiter: failed to open state file: {$file}");
            return true;
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
     * pre-create the directory to lock everyone out.
     */
    public static function stateDirectory(): string
    {
        return dirname(__DIR__, 2) . '/storage/cache/ratelimit';
    }

    /**
     * Path of the state file for an identifier, or null when the state directory
     * cannot be trusted (callers fail closed). The file name mixes in the install
     * root, so two installs never derive the same name for the same identifier.
     */
    private static function getStateFile(string $identifier): ?string
    {
        $dir = self::stateDirectory();
        if (!is_dir($dir) && !is_link($dir)) {
            // A root CLI run (a cron job in a container, say) must never create the
            // directory: it would be root-owned and 0700, and the web server user
            // would then fail closed on every throttled request — every login.
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                SecureLogger::error('RateLimiter: refusing to create the state directory as root', ['dir' => $dir]);
                return null;
            }
            @mkdir($dir, 0700, true);
        }
        if (is_link($dir) || !is_dir($dir)) {
            SecureLogger::error('RateLimiter: state directory missing or a symlink', ['dir' => $dir]);
            return null;
        }
        if (function_exists('posix_geteuid')) {
            $owner = @fileowner($dir);
            if ($owner === false || $owner !== posix_geteuid()) {
                SecureLogger::error('RateLimiter: state directory not owned by the PHP user', ['dir' => $dir]);
                return null;
            }
        }
        $perms = @fileperms($dir);
        if ($perms !== false && ($perms & 0077) !== 0) {
            @chmod($dir, 0700);
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

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
        $currentTime = time();
        $file = self::getStateFile($identifier);

        // Open (or create) state file with exclusive lock for atomic read-modify-write
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            // Fail-closed: if we can't open the state file, deny the request
            error_log("RateLimiter: failed to open state file: {$file}");
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
            $state = ['attempts' => 0, 'first_attempt' => 0];
            if ($raw !== false && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $state = [
                        'attempts' => (int) ($data['attempts'] ?? 0),
                        'first_attempt' => (int) ($data['first_attempt'] ?? 0),
                    ];
                }
            }

            // Reset if window expired
            if ($state['first_attempt'] > 0 && ($currentTime - $state['first_attempt']) > $window) {
                $state = ['attempts' => 0, 'first_attempt' => 0];
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
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Get the state file path for a rate limit identifier
     */
    private static function getStateFile(string $identifier): string
    {
        $dir = sys_get_temp_dir() . '/pinakes_ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir . '/' . hash('sha256', $identifier) . '.json';
    }
}

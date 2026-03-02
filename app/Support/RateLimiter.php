<?php
declare(strict_types=1);

namespace App\Support;

class RateLimiter
{
    private const WINDOW = 900; // 15 minutes in seconds
    private const MAX_ATTEMPTS = 10; // Max attempts per window

    /**
     * Check if the request should be rate limited.
     * Uses file-based storage so state persists across PHP requests.
     */
    public static function isLimited(string $identifier, int $maxAttempts = self::MAX_ATTEMPTS, int $window = self::WINDOW): bool
    {
        $currentTime = time();
        $file = self::getStateFile($identifier);
        $state = self::readState($file);

        // Reset if window expired
        if ($state['first_attempt'] > 0 && ($currentTime - $state['first_attempt']) > $window) {
            $state = ['attempts' => 0, 'first_attempt' => 0];
        }

        if ($state['attempts'] === 0) {
            $state['first_attempt'] = $currentTime;
        }

        $state['attempts']++;
        self::writeState($file, $state);

        return $state['attempts'] > $maxAttempts;
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

    /**
     * Read rate limit state from file
     *
     * @return array{attempts: int, first_attempt: int}
     */
    private static function readState(string $file): array
    {
        $default = ['attempts' => 0, 'first_attempt' => 0];
        if (!is_file($file)) {
            return $default;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return $default;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $default;
        }

        return [
            'attempts' => (int) ($data['attempts'] ?? 0),
            'first_attempt' => (int) ($data['first_attempt'] ?? 0),
        ];
    }

    /**
     * Write rate limit state to file
     *
     * @param array{attempts: int, first_attempt: int} $state
     */
    private static function writeState(string $file, array $state): void
    {
        @file_put_contents($file, json_encode($state), LOCK_EX);
    }
}

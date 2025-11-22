<?php
declare(strict_types=1);

namespace App\Support;

class RateLimiter
{
    private static array $attempts = [];
    private static array $timestamps = [];
    private const WINDOW = 900; // 15 minutes in seconds
    private const MAX_ATTEMPTS = 10; // Max attempts per window

    /**
     * Check if the request should be rate limited
     */
    public static function isLimited(string $identifier): bool
    {
        $currentTime = time();
        $key = self::getRateLimitKey($identifier);

        // Clean old entries
        if (isset(self::$timestamps[$key]) && 
            ($currentTime - self::$timestamps[$key]) > self::WINDOW) {
            unset(self::$attempts[$key], self::$timestamps[$key]);
        }

        if (!isset(self::$attempts[$key])) {
            self::$attempts[$key] = 1;
            self::$timestamps[$key] = $currentTime;
            return false;
        }

        self::$attempts[$key]++;
        
        if (self::$attempts[$key] > self::MAX_ATTEMPTS) {
            return true; // Rate limit exceeded
        }

        return false;
    }

    /**
     * Get rate limit key based on identifier
     */
    private static function getRateLimitKey(string $identifier): string
    {
        return $identifier;
    }

    /**
     * Reset rate limit for an identifier
     */
    public static function reset(string $identifier): void
    {
        $key = self::getRateLimitKey($identifier);
        unset(self::$attempts[$key], self::$timestamps[$key]);
    }
}
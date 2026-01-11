<?php
declare(strict_types=1);

namespace App\Support;

/**
 * QueryCache - Simple caching layer for expensive database queries
 *
 * Uses APCu for in-memory caching when available, falls back to file-based
 * caching in storage/cache directory. Designed for caching dashboard stats,
 * aggregations, and other expensive queries that don't need real-time data.
 *
 * @package App\Support
 */
class QueryCache
{
    /** @var string Base directory for file cache */
    private static string $cacheDir = '';

    /** @var bool|null Whether APCu is available (cached check) */
    private static ?bool $apcuAvailable = null;

    /**
     * Get the cache directory path
     */
    private static function getCacheDir(): string
    {
        if (self::$cacheDir === '') {
            self::$cacheDir = dirname(__DIR__, 2) . '/storage/cache';

            // Ensure directory exists
            if (!is_dir(self::$cacheDir)) {
                @mkdir(self::$cacheDir, 0775, true);
            }
        }

        return self::$cacheDir;
    }

    /**
     * Check if APCu is available and enabled
     */
    private static function hasApcu(): bool
    {
        if (self::$apcuAvailable === null) {
            self::$apcuAvailable = function_exists('apcu_fetch') && apcu_enabled();
        }

        return self::$apcuAvailable;
    }

    /**
     * Get a value from cache or execute callback to generate it
     *
     * Uses mutex locking to prevent cache stampede (thundering herd problem).
     * Only one process computes the value while others wait.
     *
     * @param string $key Unique cache key
     * @param callable $callback Function to generate value if not cached
     * @param int $ttl Time to live in seconds (default: 300 = 5 minutes)
     * @return mixed Cached or freshly generated value
     */
    public static function remember(string $key, callable $callback, int $ttl = 300): mixed
    {
        // Try to get from cache first
        $cached = self::get($key);
        if ($cached !== null) {
            return $cached;
        }

        // Acquire mutex lock to prevent stampede
        $lockKey = self::hashKey($key) . '.lock';
        $lockFile = self::getCacheDir() . '/' . $lockKey;
        $lockHandle = @fopen($lockFile, 'c');

        if ($lockHandle === false) {
            // If we can't get a lock, just execute callback (graceful degradation)
            return $callback();
        }

        try {
            // Try to acquire exclusive lock (non-blocking first)
            if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
                // Another process is computing, wait for it and try cache again
                flock($lockHandle, LOCK_EX);
                $cached = self::get($key);
                if ($cached !== null) {
                    return $cached;
                }
            }

            // Double-check cache after acquiring lock
            $cached = self::get($key);
            if ($cached !== null) {
                return $cached;
            }

            // Execute callback to get fresh value
            $value = $callback();

            // Store in cache
            self::set($key, $value, $ttl);

            return $value;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            // Clean up lock file (best effort)
            @unlink($lockFile);
        }
    }

    /**
     * Get a value from cache
     *
     * @param string $key Cache key
     * @return mixed|null Cached value or null if not found/expired
     */
    public static function get(string $key): mixed
    {
        $hashedKey = self::hashKey($key);

        // Try APCu first
        if (self::hasApcu()) {
            $success = false;
            $value = apcu_fetch($hashedKey, $success);
            if ($success) {
                return $value;
            }
            // APCu miss - fall through to file cache
        }

        // Fallback to file cache
        return self::getFromFile($hashedKey);
    }

    /**
     * Set a value in cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds
     * @return bool Success status
     */
    public static function set(string $key, mixed $value, int $ttl = 300): bool
    {
        $hashedKey = self::hashKey($key);

        // Try APCu first
        if (self::hasApcu()) {
            return apcu_store($hashedKey, $value, $ttl);
        }

        // Fallback to file cache
        return self::setToFile($hashedKey, $value, $ttl);
    }

    /**
     * Delete a value from cache
     *
     * @param string $key Cache key
     * @return bool Success status
     */
    public static function delete(string $key): bool
    {
        $hashedKey = self::hashKey($key);

        // Try APCu first
        if (self::hasApcu()) {
            return apcu_delete($hashedKey);
        }

        // Fallback to file cache
        return self::deleteFromFile($hashedKey);
    }

    /**
     * Clear all cache entries with a given prefix
     *
     * @param string $prefix Key prefix to match (e.g., 'dashboard_')
     * @return int Number of entries cleared
     */
    public static function clearByPrefix(string $prefix): int
    {
        $hashedPrefix = 'pinakes_' . $prefix;
        $count = 0;

        // Clear APCu cache if available
        if (self::hasApcu()) {
            $iterator = new \APCUIterator('/^' . preg_quote($hashedPrefix, '/') . '/');
            foreach ($iterator as $item) {
                if (apcu_delete($item['key'])) {
                    $count++;
                }
            }
            // Don't return early - also clear file cache
        }

        // Also clear file cache for consistency
        $cacheDir = self::getCacheDir();
        if (!is_dir($cacheDir)) {
            return $count;
        }

        $files = glob($cacheDir . '/' . $hashedPrefix . '*');
        if ($files === false) {
            return $count;
        }

        foreach ($files as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Clear all Pinakes cache entries
     *
     * Only clears entries with the 'pinakes_' prefix to avoid clearing
     * other applications' cache entries that may share the same APCu instance.
     *
     * @return bool Success status
     */
    public static function flush(): bool
    {
        $successApcu = true;
        $successFiles = true;

        // Clear APCu cache if available - only pinakes_* keys, not the entire cache
        if (self::hasApcu()) {
            $iterator = new \APCUIterator('/^pinakes_/');
            foreach ($iterator as $item) {
                if (!apcu_delete($item['key'])) {
                    $successApcu = false;
                }
            }
            // Don't return early - also clear file cache
        }

        // Also clear file cache for consistency
        $cacheDir = self::getCacheDir();
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . '/pinakes_*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (!@unlink($file)) {
                        $successFiles = false;
                    }
                }
            }
        }

        return $successApcu && $successFiles;
    }

    /**
     * Hash a cache key for storage
     */
    private static function hashKey(string $key): string
    {
        return 'pinakes_' . md5($key);
    }

    /**
     * Get value from file cache
     *
     * Uses file locking (flock) to prevent reading incomplete/corrupted data
     * and safe unserialize to prevent object injection attacks.
     */
    private static function getFromFile(string $hashedKey): mixed
    {
        $path = self::getCacheDir() . '/' . $hashedKey;

        if (!file_exists($path)) {
            return null;
        }

        // Open file with shared lock for reading
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return null;
        }

        try {
            // Acquire shared lock for reading
            if (!flock($handle, LOCK_SH)) {
                return null;
            }

            $content = stream_get_contents($handle);
            if ($content === false || $content === '') {
                return null;
            }

            // Use safe unserialize to prevent object injection attacks
            $data = @unserialize($content, ['allowed_classes' => false]);
            if ($data === false || !\is_array($data)) {
                // finally block handles unlock/close
                @unlink($path);
                return null;
            }

            // Check expiration
            if (isset($data['expires']) && $data['expires'] < time()) {
                // finally block handles unlock/close
                @unlink($path);
                return null;
            }

            return $data['value'] ?? null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Set value to file cache
     */
    private static function setToFile(string $hashedKey, mixed $value, int $ttl): bool
    {
        $path = self::getCacheDir() . '/' . $hashedKey;

        $data = [
            'value' => $value,
            'expires' => time() + $ttl,
            'created' => time()
        ];

        $result = @file_put_contents($path, serialize($data), LOCK_EX);
        if ($result !== false) {
            @chmod($path, 0664);
        }

        return $result !== false;
    }

    /**
     * Delete value from file cache
     */
    private static function deleteFromFile(string $hashedKey): bool
    {
        $path = self::getCacheDir() . '/' . $hashedKey;

        if (!file_exists($path)) {
            return true;
        }

        return @unlink($path);
    }

    /**
     * Clean up expired file cache entries
     *
     * Should be called periodically (e.g., via cron or after certain operations)
     *
     * @return int Number of expired entries removed
     */
    public static function gc(): int
    {
        // APCu handles its own garbage collection
        if (self::hasApcu()) {
            return 0;
        }

        $cacheDir = self::getCacheDir();
        if (!is_dir($cacheDir)) {
            return 0;
        }

        $files = glob($cacheDir . '/pinakes_*');
        if ($files === false) {
            return 0;
        }

        $count = 0;
        $now = time();

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }

            // Use safe unserialize to prevent object injection attacks
            $data = @unserialize($content, ['allowed_classes' => false]);
            if (!is_array($data) || (isset($data['expires']) && $data['expires'] < $now)) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }
}

<?php
declare(strict_types=1);

namespace App\Support;

/**
 * QueryCache - Simple caching layer for expensive database queries
 *
 * Uses a SINGLE backend per request: APCu when available and enabled,
 * otherwise file-based caching in storage/cache. Designed for caching
 * dashboard stats, aggregations, and other expensive queries that don't
 * need real-time data.
 *
 * Invalidation of the known content namespaces (catalog_, home_, ...) is
 * O(1) via generation counters (namespace versioning): the effective storage
 * key embeds the namespace's current generation, so bumping the generation
 * makes every prior entry unreachable without scanning or deleting anything.
 * Unreachable entries are reclaimed lazily by gc() / APCu TTL expiry.
 *
 * @package App\Support
 */
class QueryCache
{
    /**
     * Namespace prefixes eligible for O(1) generation-based invalidation.
     * A logical key starting with one of these prefixes has its storage key
     * derived from (key, current generation of the namespace).
     *
     * Keep in sync with ContentCache and the clearByPrefix() callers.
     */
    private const NAMESPACE_PREFIXES = [
        'catalog_',
        'home_',
        'genre_tree_',
        'plugins_maintenance_',
        'schema_table_',
    ];

    /**
     * TTL for generation counter entries (~1 year). Must outlive any data TTL:
     * if a generation counter is ever lost, it is re-initialized to time(),
     * which is monotonic w.r.t. every previously issued generation, so stale
     * entries can never be resurrected.
     */
    private const GENERATION_TTL = 31536000;

    /** Stale threshold (seconds) shared by the file lock and the APCu lock sentinel */
    private const LOCK_STALE_SECONDS = 300;

    /** @var string Base directory for file cache */
    private static string $cacheDir = '';

    /** @var bool|null Whether APCu is available (cached check = deterministic backend per request) */
    private static ?bool $apcuAvailable = null;

    /** @var int Instrumentation: total get() calls this request */
    private static int $statGets = 0;

    /** @var int Instrumentation: get() calls served from cache this request */
    private static int $statHits = 0;

    /** @var int Instrumentation: get() calls that missed this request */
    private static int $statMisses = 0;

    /**
     * Get the cache directory path
     */
    private static function getCacheDir(): string
    {
        if (self::$cacheDir === '') {
            self::$cacheDir = dirname(__DIR__, 2) . '/storage/cache';

            // Ensure directory exists
            if (!is_dir(self::$cacheDir)) {
                @mkdir(self::$cacheDir, 0755, true);
            }
        }

        return self::$cacheDir;
    }

    /**
     * Check if APCu is available and enabled.
     *
     * Memoized: the backend choice is deterministic for the whole request.
     */
    private static function hasApcu(): bool
    {
        if (self::$apcuAvailable === null) {
            self::$apcuAvailable = function_exists('apcu_fetch') && apcu_enabled();
        }

        return self::$apcuAvailable;
    }

    /**
     * Per-request cache statistics (lightweight instrumentation, no persistence).
     *
     * @return array{backend: string, gets: int, hits: int, misses: int, hit_ratio: float}
     */
    public static function stats(): array
    {
        $gets = self::$statGets;

        return [
            'backend' => self::hasApcu() ? 'apcu' : 'file',
            'gets' => $gets,
            'hits' => self::$statHits,
            'misses' => self::$statMisses,
            'hit_ratio' => $gets > 0 ? round(self::$statHits / $gets, 4) : 0.0,
        ];
    }

    /**
     * Get a value from cache or execute callback to generate it
     *
     * Uses mutex locking to prevent cache stampede (thundering herd problem).
     * Only one process computes the value while others wait. The mutex lives
     * in the selected backend: an apcu_add() sentinel when APCu holds the
     * values (no filesystem I/O on the APCu hot path), a .lock file otherwise.
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

        if (self::hasApcu()) {
            return self::rememberWithApcuLock($key, $callback, $ttl);
        }

        return self::rememberWithFileLock($key, $callback, $ttl);
    }

    /**
     * remember() body for the APCu backend: stampede protection via an
     * apcu_add() sentinel instead of a filesystem lock. Mirrors the file-lock
     * timeout/stale/graceful-degradation semantics:
     *  - the sentinel's own TTL replaces the stale-mtime check;
     *  - same 8s bounded wait, then the FIX F008 final-attempt pass and
     *    SecureLogger warning before proceeding unprotected.
     */
    private static function rememberWithApcuLock(string $key, callable $callback, int $ttl): mixed
    {
        // Lock identity is the logical key (not the generation-resolved storage
        // key) so concurrent callers agree on the mutex even across a bump.
        $lockKey = 'pinakes_lock_' . md5($key);

        $lockAcquired = apcu_add($lockKey, 1, self::LOCK_STALE_SECONDS);
        $timedOut = false;

        try {
            if (!$lockAcquired) {
                $start = microtime(true);
                $maxWaitSeconds = 8.0;
                $sleepMicros = 200000;

                while (true) {
                    usleep($sleepMicros);

                    $lockAcquired = apcu_add($lockKey, 1, self::LOCK_STALE_SECONDS);
                    if ($lockAcquired) {
                        break;
                    }

                    if ((microtime(true) - $start) >= $maxWaitSeconds) {
                        $timedOut = true;
                        break;
                    }
                }

                $cached = self::get($key);
                if ($cached !== null) {
                    return $cached;
                }

                // FIX F008 (mirrored from the file-lock path): after a timeout,
                // attempt a short bounded retry so at most one caller proceeds
                // unprotected; surface the bypass via SecureLogger either way.
                // ($timedOut === true implies the lock was never acquired.)
                if ($timedOut) {
                    $finalAttempts = 5;
                    for ($i = 0; $i < $finalAttempts; $i++) {
                        if (apcu_add($lockKey, 1, self::LOCK_STALE_SECONDS)) {
                            $lockAcquired = true;
                            break;
                        }
                        usleep(100000); // 100ms between attempts → up to ~500ms extra wait
                    }

                    if (!$lockAcquired) {
                        SecureLogger::warning(
                            'QueryCache: proceeding without mutex after lock timeout (stampede protection bypassed)',
                            [
                                'key_prefix' => substr($key, 0, 80),
                                'wait_seconds' => round(microtime(true) - $start, 3),
                            ]
                        );
                    }
                }
            }

            // Execute callback to get fresh value
            $value = $callback();

            // Store in cache
            self::set($key, $value, $ttl);

            return $value;
        } finally {
            if ($lockAcquired) {
                apcu_delete($lockKey);
            }
        }
    }

    /**
     * remember() body for the file backend: the original flock()-based
     * stampede protection, unchanged.
     */
    private static function rememberWithFileLock(string $key, callable $callback, int $ttl): mixed
    {
        // Acquire mutex lock to prevent stampede
        $lockKey = self::hashKey($key) . '.lock';
        $lockFile = self::getCacheDir() . '/' . $lockKey;

        // Check lock file mtime BEFORE fopen (fopen 'c' mode can update mtime)
        clearstatcache(true, $lockFile);
        $initialLockMtime = @filemtime($lockFile);

        $lockHandle = @fopen($lockFile, 'c');

        if ($lockHandle === false) {
            // If we can't get a lock, just execute callback (graceful degradation)
            return $callback();
        }

        try {
            $lockAcquired = false;
            $staleLock = false;
            $timedOut = false;
            $start = microtime(true);
            $maxWaitSeconds = 8.0;
            $staleThreshold = self::LOCK_STALE_SECONDS;
            $sleepMicros = 200000;

            // Check if lock file was already stale before we opened it
            if ($initialLockMtime !== false && (time() - $initialLockMtime) > $staleThreshold) {
                $staleLock = true;
            }

            while (!$staleLock) {
                $lockAcquired = flock($lockHandle, LOCK_EX | LOCK_NB);
                if ($lockAcquired) {
                    break;
                }

                // Re-check mtime periodically (using clearstatcache for fresh stat)
                clearstatcache(true, $lockFile);
                $lockMtime = @filemtime($lockFile);
                if ($lockMtime !== false && (time() - $lockMtime) > $staleThreshold) {
                    $staleLock = true;
                    continue; // re-evaluate while(!$staleLock) → exits loop
                }

                if ((microtime(true) - $start) >= $maxWaitSeconds) {
                    $timedOut = true;
                    break;
                }

                usleep($sleepMicros);
            }

            $cached = self::get($key);
            if ($cached !== null) {
                return $cached;
            }

            // FIX F008: When the wait loop timed out without acquiring the lock and
            // without detecting a stale lock, we previously fell through to $callback()
            // + self::set() WITHOUT holding any lock. That defeats the stampede
            // protection: every concurrent caller that timed out would run the
            // (expensive) callback in parallel. Attempt one final LOCK_EX acquisition
            // (with a short bounded retry) so at most one caller proceeds unprotected.
            if ($timedOut && !$lockAcquired && !$staleLock) {
                // FIX F008: short blocking-ish retry — try a few non-blocking attempts
                // separated by usleep so we don't risk holding the request indefinitely.
                $finalAttempts = 5;
                for ($i = 0; $i < $finalAttempts; $i++) {
                    if (flock($lockHandle, LOCK_EX | LOCK_NB)) {
                        $lockAcquired = true;
                        break;
                    }
                    usleep(100000); // 100ms between attempts → up to ~500ms extra wait
                }

                // FIX F008: observability — if we still couldn't acquire the lock we
                // proceed unprotected (graceful degradation, preserves existing
                // behavior), but at least surface it via SecureLogger so operators
                // know stampede protection was bypassed.
                if (!$lockAcquired) {
                    SecureLogger::warning(
                        'QueryCache: proceeding without mutex after lock timeout (stampede protection bypassed)',
                        [
                            'key_prefix' => substr($key, 0, 80),
                            'wait_seconds' => round(microtime(true) - $start, 3),
                        ]
                    );
                }
            }

            // Execute callback to get fresh value
            $value = $callback();

            // Store in cache
            self::set($key, $value, $ttl);

            return $value;
        } finally {
            if ($lockAcquired) {
                flock($lockHandle, LOCK_UN);
            }
            fclose($lockHandle);
            // Clean up lock file (best effort) - also clean up on timeout to prevent accumulation
            if ($lockAcquired || $staleLock || $timedOut) {
                @unlink($lockFile);
            }
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
        self::$statGets++;

        $value = self::backendGet(self::hashKey($key));

        if ($value !== null) {
            self::$statHits++;
        } else {
            self::$statMisses++;
        }

        return $value;
    }

    /**
     * Set a value in cache (in the selected backend only — no dual-write)
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds
     * @return bool Success status
     */
    public static function set(string $key, mixed $value, int $ttl = 300): bool
    {
        return self::backendSet(self::hashKey($key), $value, $ttl);
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

        if (self::hasApcu()) {
            return apcu_delete($hashedKey);
        }

        return self::deleteFromFile($hashedKey);
    }

    /**
     * Bump the generation counter of a known namespace: O(1) invalidation of
     * every entry whose key starts with that prefix (they become unreachable,
     * no scan, no delete storm). Unknown prefixes fall back to the legacy scan
     * so semantics stay correct for arbitrary callers.
     *
     * @param string $namespace Namespace prefix, with or without trailing '_'
     *                          (e.g. 'catalog' or 'catalog_')
     */
    public static function bumpGeneration(string $namespace): void
    {
        $ns = str_ends_with($namespace, '_') ? $namespace : $namespace . '_';

        if (!in_array($ns, self::NAMESPACE_PREFIXES, true)) {
            // Not generation-tracked: only a scan can invalidate it correctly.
            self::legacyClearByPrefix($ns);
            return;
        }

        $genKey = self::generationStorageKey($ns);
        $current = self::backendGet($genKey);
        // max(current+1, time()): monotonic even if the counter was ever lost
        // and re-initialized (init value is time(), see currentGeneration()).
        $next = is_int($current) ? max($current + 1, time()) : time();
        self::backendSet($genKey, $next, self::GENERATION_TTL);
    }

    /**
     * Clear all cache entries with a given prefix
     *
     * For the known content namespaces this is an O(1) generation bump (the
     * return value is 0 because nothing is enumerated — no caller consumes
     * the count). Arbitrary prefixes keep the original scan behavior.
     *
     * @param string $prefix Key prefix to match (e.g., 'dashboard_')
     * @return int Number of entries physically removed (0 for generation bumps)
     */
    public static function clearByPrefix(string $prefix): int
    {
        if (in_array($prefix, self::NAMESPACE_PREFIXES, true)) {
            self::bumpGeneration($prefix);
            return 0;
        }

        return self::legacyClearByPrefix($prefix);
    }

    /**
     * Original O(n) prefix scan (APCUIterator + glob). Kept as fallback for
     * prefixes outside the generation-tracked namespaces; scans BOTH stores so
     * pre-upgrade leftovers from the old dual-write scheme are reclaimed too.
     */
    private static function legacyClearByPrefix(string $prefix): int
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
     * Intentionally clears BOTH stores (maintenance operation): prevents
     * resurrection of pre-upgrade dual-written entries if the backend flips.
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
     * Read a raw hashed key from the selected backend (no stats, no
     * generation resolution — internal primitive).
     */
    private static function backendGet(string $hashedKey): mixed
    {
        if (self::hasApcu()) {
            $success = false;
            $value = apcu_fetch($hashedKey, $success);

            return $success ? $value : null;
        }

        return self::getFromFile($hashedKey);
    }

    /**
     * Write a raw hashed key to the selected backend (internal primitive).
     */
    private static function backendSet(string $hashedKey, mixed $value, int $ttl): bool
    {
        if (self::hasApcu()) {
            return apcu_store($hashedKey, $value, $ttl);
        }

        return self::setToFile($hashedKey, $value, $ttl);
    }

    /**
     * Namespace prefix of a logical key, or null if not generation-tracked.
     */
    private static function namespaceFor(string $key): ?string
    {
        foreach (self::NAMESPACE_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return $prefix;
            }
        }

        return null;
    }

    /**
     * Storage key of a namespace's generation counter. Deliberately outside
     * every namespace prefix so it never resolves through itself.
     */
    private static function generationStorageKey(string $ns): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $ns);

        return 'pinakes_gen_' . $safe . md5('__gen__' . $ns);
    }

    /**
     * Current generation of a namespace. Missing counters are initialized to
     * time(): monotonic w.r.t. every generation ever issued before, so a lost
     * counter can never make previously invalidated entries reachable again.
     */
    private static function currentGeneration(string $ns): int
    {
        $genKey = self::generationStorageKey($ns);
        $value = self::backendGet($genKey);
        if (is_int($value)) {
            return $value;
        }

        $gen = time();
        self::backendSet($genKey, $gen, self::GENERATION_TTL);

        return $gen;
    }

    /**
     * Hash a cache key for storage
     *
     * The human-readable sanitized prefix is preserved (filesystem safety +
     * the legacy prefix scan keeps matching); the hash suffix embeds the
     * namespace generation for generation-tracked keys.
     */
    private static function hashKey(string $key): string
    {
        // Sanitize prefix for filesystem safety + append hash for uniqueness
        $prefix = preg_replace('/[^A-Za-z0-9_\-]/', '_', substr($key, 0, 80));

        $hashInput = $key;
        $ns = self::namespaceFor($key);
        if ($ns !== null) {
            $hashInput .= '|gen:' . self::currentGeneration($ns);
        }

        return 'pinakes_' . $prefix . '_' . md5($hashInput);
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
            @chmod($path, 0660);
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
            if (str_ends_with($file, '.lock')) {
                continue;
            }

            $content = @file_get_contents($file);
            if ($content === false) {
                if (@unlink($file)) {
                    $count++;
                }
                continue;
            }

            // Use safe unserialize to prevent object injection attacks
            $data = @unserialize($content, ['allowed_classes' => false]);
            // Delete if: not an array, missing 'expires' key (corrupted), or expired
            if (!is_array($data) || !isset($data['expires']) || $data['expires'] < $now) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }

        $lockFiles = glob($cacheDir . '/*.lock');
        if ($lockFiles !== false) {
            $staleTime = $now - self::LOCK_STALE_SECONDS;
            foreach ($lockFiles as $lockFile) {
                $lockMtime = @filemtime($lockFile);
                if ($lockMtime !== false && $lockMtime < $staleTime) {
                    if (@unlink($lockFile)) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }
}

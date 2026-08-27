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
     * TTL for generation counter entries (~1 year). It intentionally outlives
     * every data TTL, so all entries that referenced an expired counter are
     * already expired before the counter can be initialized again.
     */
    private const GENERATION_TTL = 31536000;

    /** Stale threshold (seconds) shared by the file lock and the APCu lock sentinel */
    private const LOCK_STALE_SECONDS = 300;

    /** Run file-cache garbage collection at most once per hour. */
    private const GC_INTERVAL_SECONDS = 3600;

    /** @var string Base directory for file cache */
    private static string $cacheDir = '';

    /** @var bool|null Whether APCu is available (cached check = deterministic backend per request) */
    private static ?bool $apcuAvailable = null;

    /**
     * Request-local namespace generations. The canonical counters live on
     * disk so FPM/APCu and CLI/file users observe the same invalidations; the
     * memo avoids an extra filesystem read for every cache operation.
     *
     * @var array<string, int>
     */
    private static array $generationCache = [];

    /** Avoid checking the GC marker more than once in the same request. */
    private static bool $gcCheckedThisRequest = false;

    /**
     * Monotonic per-process sequence for generation temp filenames. Combined
     * with getmypid() it yields a collision-free name without any time- or
     * random-based component (two live processes never share a PID; a stale
     * leftover from a crashed same-PID process is only ever overwritten while
     * holding the namespace writer lock, so it is safe to reuse).
     */
    private static int $generationTmpSeq = 0;

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
     * values, a .lock file otherwise. Generation-tracked keys read their small
     * canonical generation file once per namespace/request so CLI and FPM stay
     * coherent even when APCu is disabled for CLI.
     *
     * @param string $key Unique cache key
     * @param callable $callback Function to generate value if not cached
     * @param int $ttl Time to live in seconds (default: 300 = 5 minutes)
     * @return mixed Cached or freshly generated value
     */
    public static function remember(string $key, callable $callback, int $ttl = 300): mixed
    {
        // Resolve the generation exactly once. If an invalidation happens while
        // the callback is running, the result is written under this old storage
        // key and therefore stays unreachable from the new generation.
        $hashedKey = self::hashKey($key);

        // This is the one logical lookup represented in stats(). Mutex
        // double-checks below deliberately use backendGet() without counters.
        self::$statGets++;
        $cached = self::backendGet($hashedKey);
        if ($cached !== null) {
            self::$statHits++;
            return $cached;
        }
        self::$statMisses++;

        if (self::hasApcu()) {
            return self::rememberWithApcuLock($key, $hashedKey, $callback, $ttl);
        }

        return self::rememberWithFileLock($key, $hashedKey, $callback, $ttl);
    }

    /**
     * remember() body for the APCu backend: stampede protection via an
     * apcu_add() sentinel instead of a filesystem lock. Mirrors the file-lock
     * timeout/stale/graceful-degradation semantics:
     *  - the sentinel's own TTL replaces the stale-mtime check;
     *  - same 8s bounded wait, then the FIX F008 final-attempt pass and
     *    SecureLogger warning before proceeding unprotected.
     */
    private static function rememberWithApcuLock(
        string $key,
        string $hashedKey,
        callable $callback,
        int $ttl
    ): mixed
    {
        // The lock follows the resolved generation. A request for a new
        // generation must not wait for (or consume) an old-generation loader.
        $lockKey = 'pinakes_lock_' . md5($hashedKey);
        $lockToken = random_int(1, PHP_INT_MAX);

        $lockAcquired = apcu_add($lockKey, $lockToken, self::LOCK_STALE_SECONDS);
        $timedOut = false;
        $start = microtime(true);

        try {
            if (!$lockAcquired) {
                $maxWaitSeconds = 8.0;
                $sleepMicros = 200000;

                while (true) {
                    usleep($sleepMicros);

                    $lockAcquired = apcu_add($lockKey, $lockToken, self::LOCK_STALE_SECONDS);
                    if ($lockAcquired) {
                        break;
                    }

                    if ((microtime(true) - $start) >= $maxWaitSeconds) {
                        $timedOut = true;
                        break;
                    }
                }

                $cached = self::backendGet($hashedKey);
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
                        if (apcu_add($lockKey, $lockToken, self::LOCK_STALE_SECONDS)) {
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

            // Always double-check after acquiring the sentinel. Another worker
            // may have populated the value between our first miss and apcu_add(),
            // or immediately before a final retry acquired the released lock.
            if ($lockAcquired) {
                $cached = self::backendGet($hashedKey);
                if ($cached !== null) {
                    return $cached;
                }
            }

            // Execute callback to get fresh value
            $value = $callback();

            // Store under the generation resolved before the callback.
            self::backendSet($hashedKey, $value, $ttl);

            return $value;
        } finally {
            if ($lockAcquired) {
                self::releaseApcuLock($lockKey, $lockToken);
            }
        }
    }

    /** Release an APCu sentinel only when it is still owned by this caller. */
    private static function releaseApcuLock(string $lockKey, int $lockToken): void
    {
        if (function_exists('apcu_cas')) {
            // CAS keeps an expired/reacquired successor lock from being deleted
            // by the previous owner. The temporary zero remains locked until
            // this caller deletes it immediately below.
            if (apcu_cas($lockKey, $lockToken, 0)) {
                apcu_delete($lockKey);
            }
            return;
        }

        // Compatibility fallback for unusual APCu builds without apcu_cas().
        $success = false;
        $current = apcu_fetch($lockKey, $success);
        if ($success && $current === $lockToken) {
            apcu_delete($lockKey);
        }
    }

    /**
     * remember() body for the file backend: the original flock()-based
     * stampede protection, unchanged.
     */
    private static function rememberWithFileLock(
        string $key,
        string $hashedKey,
        callable $callback,
        int $ttl
    ): mixed
    {
        // Acquire mutex lock to prevent stampede
        $lockKey = $hashedKey . '.lock';
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

            $cached = self::backendGet($hashedKey);
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

            // Store under the generation resolved before the callback.
            self::backendSet($hashedKey, $value, $ttl);

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
        $successFiles = self::deleteFromFile($hashedKey);
        $successApcu = true;

        // Invalidation intentionally reaches both stores when this SAPI can
        // access APCu. This preserves web/FPM -> CLI/file coherence while data
        // writes themselves remain single-backend.
        if (self::hasApcu()) {
            $successApcu = apcu_delete($hashedKey) || !apcu_exists($hashedKey);
        }

        return $successFiles && $successApcu;
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

        $next = self::mutateGenerationFile($ns, true);
        if ($next === null) {
            // If the canonical counter cannot be persisted, fall back to the
            // physical invalidation used before namespace generations. Keep a
            // request-local unique generation as an additional safety net so
            // this process cannot reuse the old key after the invalidation.
            self::legacyClearByPrefix($ns);
            self::$generationCache[$ns] = -random_int(1, PHP_INT_MAX);
            return;
        }

        self::$generationCache[$ns] = $next;
        self::maybeGc();
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
                // Never remove a live mutex: deleting it while its owner is
                // computing would allow a second worker to acquire a new
                // lock for the same key. The sentinel expires on its own.
                if (str_starts_with($item['key'], 'pinakes_lock_')) {
                    continue;
                }
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
                    $basename = basename($file);
                    // Generation writer locks must keep the same inode for
                    // their entire lifetime. Counter files are superseded by
                    // the generation bumps below, rather than deleted while
                    // another process may be updating them.
                    if (str_ends_with($basename, '.lock')
                        || str_starts_with($basename, 'pinakes_gen_')) {
                        continue;
                    }
                    if (!@unlink($file)) {
                        $successFiles = false;
                    }
                }
            }
        }

        // Publish a generation newer than every value visible before the
        // flush. An in-flight loader may still finish afterwards, but it will
        // write under its captured old generation and remain unreachable.
        foreach (self::NAMESPACE_PREFIXES as $namespace) {
            self::bumpGeneration($namespace);
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

    /** Storage filename of a namespace's canonical generation counter. */
    private static function generationStorageKey(string $ns): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $ns);

        return 'pinakes_gen_' . $safe . md5('__gen__' . $ns);
    }

    /**
     * Current generation of a namespace.
     *
     * The canonical counter is deliberately file-backed even when APCu is the
     * selected data backend. PHP-FPM and CLI commonly disagree on APCu
     * availability (apc.enable_cli is normally off); one shared file keeps
     * invalidation coherent across those SAPIs. The value is memoized for the
     * remainder of this request, so each namespace costs at most one file read.
     */
    private static function currentGeneration(string $ns): int
    {
        if (isset(self::$generationCache[$ns])) {
            return self::$generationCache[$ns];
        }

        $gen = self::readGenerationFile($ns);
        if ($gen === null) {
            // Initialize under an exclusive lock. A concurrent initializer may
            // win; mutateGenerationFile(false) then returns its value unchanged.
            $gen = self::mutateGenerationFile($ns, false);
        }

        if ($gen === null) {
            // Graceful degradation when the cache directory is unavailable:
            // a process-unique negative generation prevents stale cross-request
            // reuse while still allowing repeat lookups within this request.
            $gen = -random_int(1, PHP_INT_MAX);
        }

        self::$generationCache[$ns] = $gen;

        return $gen;
    }

    /** Read a valid generation file under a shared lock. */
    private static function readGenerationFile(string $ns): ?int
    {
        $path = self::getCacheDir() . '/' . self::generationStorageKey($ns);
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return null;
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                return null;
            }

            return self::generationFromHandle($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Initialize or atomically increment a namespace generation.
     *
     * Crash/IO safety: the counter file itself is NEVER truncated or written
     * in place. The new payload goes to a temp file in the same directory
     * (flushed + fsynced) and is then rename()d over the counter path —
     * atomic on POSIX — so a failed write leaves the previous counter intact
     * and a reader can never observe an empty/partial file. An in-place
     * ftruncate+fwrite scheme could leave the file empty on a mid-write
     * failure, making the next initializer fall back to time() — potentially
     * LOWER than a counter that had climbed past wall-clock, which would make
     * invalidated-but-TTL-live entries reachable again.
     *
     * Writer serialization: because rename() swaps the inode, an flock held
     * on the counter file itself would not serialize concurrent writers (the
     * second writer would lock the OLD inode). Writers therefore serialize on
     * a dedicated sibling lock file (<counter>.lock) that is never unlinked
     * (gc() skips it), held across the whole read-current → write-temp →
     * rename sequence, so concurrent bumps cannot lose an increment.
     *
     * @param bool $increment true for invalidation, false for initialize-if-missing
     */
    private static function mutateGenerationFile(string $ns, bool $increment): ?int
    {
        $path = self::getCacheDir() . '/' . self::generationStorageKey($ns);

        $lockPath = $path . '.lock';
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            return null;
        }
        @chmod($lockPath, 0660);

        try {
            if (!flock($handle, LOCK_EX)) {
                return null;
            }

            $current = self::readGenerationFile($ns);
            if ($current !== null && !$increment) {
                return $current;
            }

            $next = $current !== null ? max($current + 1, time()) : time();
            $payload = serialize([
                'value' => $next,
                'expires' => time() + self::GENERATION_TTL,
                'created' => time(),
            ]);

            if (!self::replaceGenerationFile($path, $payload)) {
                return null;
            }

            return $next;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Atomically replace the counter file with $payload via temp + rename.
     * Must be called while holding the namespace writer lock. Returns false
     * (leaving the previous counter untouched) on any failure.
     */
    private static function replaceGenerationFile(string $path, string $payload): bool
    {
        $tmpPath = $path . '.tmp.' . getmypid() . '.' . self::$generationTmpSeq++;

        $tmp = @fopen($tmpPath, 'w');
        if ($tmp === false) {
            return false;
        }

        $written = fwrite($tmp, $payload);
        $flushed = $written === strlen($payload) && fflush($tmp);
        // Persist the payload before publishing the name: a rename made
        // durable before its data could surface an empty file after a crash.
        if ($flushed && function_exists('fsync')) {
            $flushed = fsync($tmp);
        }
        fclose($tmp);

        if (!$flushed) {
            @unlink($tmpPath);
            return false;
        }

        @chmod($tmpPath, 0660);

        if (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);
            return false;
        }

        return true;
    }

    /** Parse a generation payload from an already locked file handle. */
    private static function generationFromHandle($handle): ?int
    {
        rewind($handle);
        $content = stream_get_contents($handle);
        if ($content === false || $content === '') {
            return null;
        }

        $data = @unserialize($content, ['allowed_classes' => false]);
        if (!is_array($data)
            || !isset($data['expires'], $data['value'])
            || (int) $data['expires'] < time()
            || !is_int($data['value'])) {
            return null;
        }

        return $data['value'];
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
            self::maybeGc();
        }

        return $result !== false;
    }

    /**
     * Run file-cache GC at most once per configured interval and never make a
     * request wait behind another collector. Generation invalidation leaves
     * old filenames unreachable, so TTL alone is insufficient: without this
     * scheduled sweep those files would remain on disk forever.
     */
    private static function maybeGc(): void
    {
        if (self::$gcCheckedThisRequest) {
            return;
        }
        self::$gcCheckedThisRequest = true;

        $cacheDir = self::getCacheDir();
        $marker = $cacheDir . '/.pinakes_gc';
        clearstatcache(true, $marker);
        $lastRun = @filemtime($marker);
        if ($lastRun !== false && $lastRun >= time() - self::GC_INTERVAL_SECONDS) {
            return;
        }

        $lockPath = $cacheDir . '/.pinakes_gc.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            return;
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return;
            }

            // Recheck after acquiring the lock: another process may have run
            // GC between the optimistic marker check and flock().
            clearstatcache(true, $marker);
            $lastRun = @filemtime($marker);
            if ($lastRun !== false && $lastRun >= time() - self::GC_INTERVAL_SECONDS) {
                return;
            }

            // Mark before scanning so a fatal error cannot trigger a GC storm.
            @touch($marker);
            self::gc();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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

            // Generation temp files (counter payloads awaiting their atomic
            // rename) are not cache entries: never parse them, and only
            // remove residue left behind by a crashed writer once it is
            // unambiguously stale.
            if (str_contains(basename($file), '.tmp.')) {
                $tmpMtime = @filemtime($file);
                if ($tmpMtime !== false
                    && $tmpMtime < $now - self::LOCK_STALE_SECONDS
                    && @unlink($file)) {
                    $count++;
                }
                continue;
            }

            $handle = @fopen($file, 'r');
            if ($handle === false) {
                if (@unlink($file)) {
                    $count++;
                }
                continue;
            }

            $locked = false;
            try {
                // Ordinary entries are written in place under an exclusive
                // lock (file_put_contents LOCK_EX); GC must take that same
                // exclusive lock or it could mistake an in-progress write for
                // corruption. Generation counters are replaced atomically via
                // temp + rename, so any inode opened here is always complete.
                $locked = flock($handle, LOCK_EX | LOCK_NB);
                if (!$locked) {
                    continue;
                }

                $content = stream_get_contents($handle);
                $delete = false;
                if ($content === false) {
                    $delete = true;
                } else {
                    // Use safe unserialize to prevent object injection attacks.
                    $data = @unserialize($content, ['allowed_classes' => false]);
                    $delete = !is_array($data)
                        || !isset($data['expires'])
                        || $data['expires'] < $now;
                }

                // Unlink while the inode is still exclusively locked. Waiting
                // until after unlock would let a concurrent writer refresh the
                // same path between our expiry check and unlink().
                if ($delete && @unlink($file)) {
                    $count++;
                }
            } finally {
                if ($locked) {
                    flock($handle, LOCK_UN);
                }
                fclose($handle);
            }
        }

        $lockFiles = glob($cacheDir . '/*.lock');
        if ($lockFiles !== false) {
            $staleTime = $now - self::LOCK_STALE_SECONDS;
            foreach ($lockFiles as $lockFile) {
                // Generation writer locks are deliberately persistent: writers
                // flock() the same never-recreated file to serialize bumps.
                // Unlinking one here would let a new writer create a second
                // lock inode at the same path and race the current holder.
                if (str_starts_with(basename($lockFile), 'pinakes_gen_')) {
                    continue;
                }

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

<?php
declare(strict_types=1);

/**
 * Behavioural contract for the QueryCache single-backend + generation-key
 * refactor (issue #387, step 1+3 of the caching plan). No DB needed.
 *
 * Covers:
 *  - set/get/delete round-trip and TTL expiry on the selected backend;
 *  - remember(): returns the callback value on miss and computes ONCE
 *    (second call served from cache, callback not re-run);
 *  - single selected backend: stats() reports which backend holds the
 *    values ('apcu' when available+enabled, 'file' otherwise) and the
 *    round-trip proves that backend serves them;
 *  - O(1) generation-key invalidation: after ContentCache::booksChanged()
 *    previously cached catalog_* and home_* entries are no longer served while
 *    an unrelated (non-namespaced) entry survives — AND, on the file
 *    backend, the stale entry's file is left on disk untouched (proof the
 *    invalidation is a generation bump, not a scan/delete storm; gc()
 *    reclaims it later). ContentCache::homeContentChanged() only kills the
 *    home_ namespace, catalog_ survives;
 *  - clearByPrefix() keeps its invalidation semantics both for a known
 *    namespace (generation bump) and for an arbitrary prefix (legacy scan);
 *  - security: a file-backend payload replaced with a bare serialized
 *    object is neutralized by unserialize(..., allowed_classes=false)
 *    (get() returns null, poisoned file removed);
 *  - stats() hit/miss counters move correctly across a miss and a hit, and
 *    remember() records one logical lookup despite its mutex double-check;
 *  - in-flight loaders cannot publish stale data into a newer generation;
 *  - generation bumps are atomic across concurrent processes;
 *  - time-gated GC is actually reached by normal file-cache writes.
 *
 * FAILS BY DESIGN on the pre-refactor QueryCache: stats()/bumpGeneration()
 * do not exist there (fatal Error), and the "stale file left on disk after
 * booksChanged()" assertion is false under the old prefix-scan deletion.
 *
 * Run: php tests/querycache-backend-and-generation.unit.php
 */

use App\Support\ContentCache;
use App\Support\QueryCache;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$cacheDir = $root . '/storage/cache';

$pass = 0;
$check = static function (bool $ok, string $label) use (&$pass): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $pass++;
    echo "  OK  {$label}\n";
};

$run = substr(md5((string) mt_rand()), 0, 8);

// Every logical key this test creates (for cleanup).
$createdKeys = [];
$key = static function (string $logical) use (&$createdKeys): string {
    $createdKeys[] = $logical;
    return $logical;
};

// Locate the storage/cache file(s) of a logical key by its sanitized
// human-readable prefix (the refactor keeps that prefix in the filename).
$filesFor = static function (string $logical) use ($cacheDir): array {
    $prefix = preg_replace('/[^A-Za-z0-9_\-]/', '_', substr($logical, 0, 80));
    $files = glob($cacheDir . '/pinakes_' . $prefix . '_*');
    return $files === false ? [] : $files;
};

try {
    // ── Backend identity ────────────────────────────────────────────────
    $stats = QueryCache::stats();
    $backend = $stats['backend'] ?? '';
    $check(in_array($backend, ['apcu', 'file'], true), "01 stats() reports a deterministic backend ({$backend})");
    if ($backend !== 'file') {
        // The generation/security file-level assertions below inspect the
        // file store directly; they are meaningful on the file backend.
        echo "NOTE: APCu backend active in this environment; file-level assertions run against APCu semantics where applicable.\n";
    } else {
        echo "NOTE: no APCu in this PHP (CLI without apcu/apc.enable_cli) — exercising the file backend, the shared-hosting default.\n";
    }

    // ── set/get/delete round-trip on the selected backend ───────────────
    $k1 = $key("qc_test_roundtrip_{$run}");
    $check(QueryCache::get($k1) === null, '02 unknown key misses');
    $check(QueryCache::set($k1, ['a' => 1, 'b' => 'x'], 60) === true, '03 set() succeeds on the selected backend');
    $check(QueryCache::get($k1) === ['a' => 1, 'b' => 'x'], '04 get() returns the stored value (selected backend holds it)');
    QueryCache::delete($k1);
    $check(QueryCache::get($k1) === null, '05 delete() removes the entry');

    // Single-backend observability: on the file backend the value must be on
    // disk; nothing is (or can be) dual-written to APCu here. On APCu the
    // inverse holds: no file must appear for the key.
    $k2 = $key("qc_test_backend_{$run}");
    QueryCache::set($k2, 'v', 60);
    if ($backend === 'file') {
        $check(count($filesFor($k2)) === 1, '06 file backend physically holds the entry (single backend)');
    } else {
        $check(count($filesFor($k2)) === 0, '06 apcu backend does NOT dual-write to disk (single backend)');
    }
    QueryCache::delete($k2);

    // ── TTL expiry ──────────────────────────────────────────────────────
    $k3 = $key("qc_test_ttl_{$run}");
    QueryCache::set($k3, 'short-lived', 1);
    $check(QueryCache::get($k3) === 'short-lived', '07 entry readable within TTL');
    sleep(2);
    $check(QueryCache::get($k3) === null, '08 entry expired after TTL');

    // ── remember(): miss → callback value, then computed once ───────────
    $k4 = $key("qc_test_remember_{$run}");
    $calls = 0;
    $producer = static function () use (&$calls): string {
        $calls++;
        return 'computed-value';
    };
    $check(QueryCache::remember($k4, $producer, 60) === 'computed-value', '09 remember() returns callback value on miss');
    $check($calls === 1, '10 callback executed exactly once on miss');
    $check(QueryCache::remember($k4, $producer, 60) === 'computed-value', '11 remember() serves the cached value');
    $check($calls === 1, '12 callback NOT re-executed on hit');
    QueryCache::delete($k4);

    // ── Generation-key invalidation via ContentCache ────────────────────
    $catKey = $key("catalog_qc_test_{$run}");
    $homeKey = $key("home_qc_test_{$run}");
    $otherKey = $key("qc_test_survivor_{$run}");

    QueryCache::set($catKey, 'catalog-data', 300);
    QueryCache::set($homeKey, 'home-data', 300);
    QueryCache::set($otherKey, 'unrelated-data', 300);
    $check(QueryCache::get($catKey) === 'catalog-data', '13 catalog_ entry cached');
    $check(QueryCache::get($homeKey) === 'home-data', '14 home_ entry cached');

    $catFilesBefore = $filesFor($catKey);

    ContentCache::booksChanged();

    $check(QueryCache::get($catKey) === null, '15 catalog_ entry no longer served after booksChanged()');
    $check(QueryCache::get($homeKey) === null, '16 home_ entry no longer served after booksChanged()');
    $check(QueryCache::get($otherKey) === 'unrelated-data', '17 unrelated namespace survives booksChanged()');

    if ($backend === 'file') {
        // O(1) proof: the stale entry was NOT deleted (no scan) — it merely
        // became unreachable because the namespace generation moved on.
        $stillOnDisk = count($catFilesBefore) === 1 && file_exists($catFilesBefore[0]);
        $check($stillOnDisk, '18 stale catalog_ file left on disk (generation bump, not a delete scan)');
        foreach ($catFilesBefore as $f) {
            @unlink($f);
        }
    } else {
        $check(true, '18 (apcu backend: on-disk staleness proof not applicable)');
    }

    // Scoped invalidation: homeContentChanged() bumps only home_.
    QueryCache::set($catKey, 'catalog-data-2', 300);
    QueryCache::set($homeKey, 'home-data-2', 300);
    ContentCache::homeContentChanged();
    $check(QueryCache::get($homeKey) === null, '19 home_ entry invalidated by homeContentChanged()');
    $check(QueryCache::get($catKey) === 'catalog-data-2', '20 catalog_ entry survives homeContentChanged()');

    // ── clearByPrefix() keeps its semantics ─────────────────────────────
    // Known namespace → generation bump under the hood.
    QueryCache::clearByPrefix('catalog_');
    $check(QueryCache::get($catKey) === null, '21 clearByPrefix(catalog_) still invalidates the namespace');

    // Arbitrary (non-namespace) prefix → legacy scan fallback still works
    // with the new storage naming.
    $arbKey = $key("qc_arbitrary_{$run}_entry");
    QueryCache::set($arbKey, 'arb', 300);
    $cleared = QueryCache::clearByPrefix("qc_arbitrary_{$run}_");
    $check(QueryCache::get($arbKey) === null, '22 clearByPrefix(arbitrary prefix) still clears matching entries');
    if ($backend === 'file') {
        $check($cleared >= 1, '23 arbitrary-prefix scan reports removed entries');
    } else {
        $check(true, '23 (apcu backend: scan count covered by assertion 22)');
    }

    // ── Security: allowed_classes=false guard on the file store ─────────
    if ($backend === 'file') {
        $poisonKey = $key("qc_test_poison_{$run}");
        QueryCache::set($poisonKey, 'benign', 300);
        $poisonFiles = $filesFor($poisonKey);
        $check(count($poisonFiles) === 1, '24 poisoned-entry fixture in place');
        // Simulate an attacker-controlled payload: a bare serialized object.
        file_put_contents($poisonFiles[0], 'O:8:"stdClass":0:{}');
        $check(QueryCache::get($poisonKey) === null, '25 serialized-object payload rejected (allowed_classes=false guard)');
        clearstatcache();
        $check(!file_exists($poisonFiles[0]), '26 poisoned file removed on read');
    } else {
        // APCu stores native values (no unserialize on read); craft the same
        // guard check directly against the file reader is not applicable.
        $check(true, '24 (apcu backend: no file deserialization path in use)');
        $check(true, '25 (apcu backend: object-injection surface absent)');
        $check(true, '26 (apcu backend: object-injection surface absent)');
    }

    // ── stats() hit/miss accounting ─────────────────────────────────────
    $before = QueryCache::stats();
    $missKey = $key("qc_test_stats_missing_{$run}");
    QueryCache::get($missKey);                       // miss
    $hitKey = $key("qc_test_stats_hit_{$run}");
    QueryCache::set($hitKey, 1, 60);
    QueryCache::get($hitKey);                        // hit
    $after = QueryCache::stats();

    $check(($after['gets'] - $before['gets']) === 2, '27 stats(): gets incremented per get()');
    $check(($after['misses'] - $before['misses']) === 1, '28 stats(): miss counted');
    $check(($after['hits'] - $before['hits']) === 1, '29 stats(): hit counted');
    $check(
        $after['gets'] > 0 && abs($after['hit_ratio'] - round($after['hits'] / $after['gets'], 4)) < 0.0001,
        '30 stats(): hit_ratio consistent with counters'
    );
    QueryCache::delete($hitKey);

    // ── remember() must not publish across an invalidation ──────────────
    $inFlightKey = $key("home_qc_inflight_{$run}");
    $inFlight = QueryCache::remember($inFlightKey, static function (): string {
        // Simulate a committed mutation/invalidation while an older loader is
        // still computing. Its result may be returned to that caller, but must
        // remain stored under the old generation and be unreachable afterwards.
        ContentCache::homeContentChanged();
        return 'pre-invalidation-value';
    }, 300);
    $check($inFlight === 'pre-invalidation-value', '31 in-flight loader returns its callback value');
    $check(QueryCache::get($inFlightKey) === null, '32 in-flight loader cannot populate the newer generation');

    // ── remember() instrumentation counts logical lookups only ──────────
    $statsRememberKey = $key("qc_stats_remember_{$run}");
    $beforeRemember = QueryCache::stats();
    QueryCache::remember($statsRememberKey, static fn(): string => 'v', 60);
    $afterRemember = QueryCache::stats();
    $check(($afterRemember['gets'] - $beforeRemember['gets']) === 1, '33 cold remember() counts one logical get');
    $check(($afterRemember['misses'] - $beforeRemember['misses']) === 1, '34 cold remember() counts one logical miss');

    // ── Generation increments remain atomic across processes ───────────
    if (function_exists('pcntl_fork') && function_exists('pcntl_waitpid')) {
        $reflection = new ReflectionClass(QueryCache::class);
        $generationMethod = $reflection->getMethod('currentGeneration');
        $generationMemo = $reflection->getProperty('generationCache');

        QueryCache::bumpGeneration('schema_table_');
        $generationMemo->setValue(null, []);
        $beforeGeneration = $generationMethod->invoke(null, 'schema_table_');

        $children = [];
        $childCount = 6;
        $bumpsPerChild = 20;
        for ($child = 0; $child < $childCount; $child++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('pcntl_fork failed');
            }
            if ($pid === 0) {
                for ($i = 0; $i < $bumpsPerChild; $i++) {
                    QueryCache::bumpGeneration('schema_table_');
                }
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                throw new RuntimeException('generation bump child failed');
            }
        }

        // The parent intentionally held its request-local memo while children
        // worked; clear it to model the next request reading the canonical file.
        $generationMemo->setValue(null, []);
        $afterGeneration = $generationMethod->invoke(null, 'schema_table_');
        $check(
            ($afterGeneration - $beforeGeneration) === $childCount * $bumpsPerChild,
            '35 concurrent generation bumps have no lost updates or rollback'
        );
    } else {
        $source = file_get_contents($root . '/app/Support/QueryCache.php');
        $check(
            is_string($source) && str_contains($source, 'flock($handle, LOCK_EX)'),
            '35 generation mutation uses an exclusive lock (pcntl unavailable)'
        );
    }

    // ── File GC is wired into writes ────────────────────────────────────
    if ($backend === 'file') {
        $gcMarker = $cacheDir . '/.pinakes_gc';
        @touch($gcMarker, time() - 7200);
        $reflection = new ReflectionClass(QueryCache::class);
        $reflection->getProperty('gcCheckedThisRequest')->setValue(null, false);

        $expiredKey = $key("qc_gc_expired_{$run}");
        QueryCache::set($expiredKey, 'expired', -1);
        $check($filesFor($expiredKey) === [], '36 time-gated GC reclaims expired generation leftovers');
    } else {
        $source = file_get_contents($root . '/app/Support/QueryCache.php');
        $check(
            is_string($source) && str_contains($source, 'self::maybeGc();'),
            '36 file writes schedule time-gated GC (APCu selected here)'
        );
    }
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    exit(1);
} finally {
    // Cleanup: remove every key this test created (current generation) and
    // any leftover files matching this run's unique marker.
    foreach (array_unique($createdKeys) as $logical) {
        QueryCache::delete($logical);
    }
    $leftovers = glob($cacheDir . '/pinakes_*' . $run . '*');
    if ($leftovers !== false) {
        foreach ($leftovers as $f) {
            @unlink($f);
        }
    }
}

echo "\n{$pass} checks passed\n";
exit(0);

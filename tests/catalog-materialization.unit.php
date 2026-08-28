<?php
declare(strict_types=1);

/**
 * Static/runtime-independent contracts for catalog materialization. The real
 * migration behavior lives in migration-0.7.71-rc.1.unit.php; these checks run
 * even during a rolling-upgrade window where the canonical table is absent.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$queryCache = (string) file_get_contents($root . '/app/Support/QueryCache.php');
$snapshot = (string) file_get_contents($root . '/app/Support/CatalogSnapshot.php');
$projection = (string) file_get_contents($root . '/app/Support/CatalogAuthorProjection.php');
$frontend = (string) file_get_contents($root . '/app/Controllers/FrontendController.php');
$authorsApi = (string) file_get_contents($root . '/app/Controllers/AutoriApiController.php');
$schema = (string) file_get_contents($root . '/installer/database/schema.sql');

$check(str_contains($queryCache, 'public static function namespaceGeneration'), 'QueryCache exposes only its canonical namespace generation');
$check(str_contains($queryCache, "throw new \\InvalidArgumentException('Unknown generation-tracked cache namespace:"), 'unknown namespaces cannot forge a materialization generation');
$check(str_contains($snapshot, 'if ($generation <= 0'), 'degraded non-persistent generations bypass DB materialization');
$check(str_contains($snapshot, 'stored_generation') && str_contains($snapshot, '> $generation'), 'an old request cannot overwrite a newer snapshot generation');
$check(str_contains($snapshot, 'age_seconds') && str_contains($snapshot, '>= $ttl'), 'shared snapshots retain the short TTL safety net');
$check(str_contains($snapshot, 'GET_LOCK') && str_contains($snapshot, 'RELEASE_LOCK'), 'cross-worker rebuilds use a bounded MySQL mutex');
$check(str_contains($snapshot, 'return $loader();') && str_contains($snapshot, '!SchemaInfo::hasTable'), 'pre-migration installs fail open to live aggregates');
$check(str_contains($projection, "la.ruolo IN ('principale', 'co-autore')"), 'projection excludes non-creator contributor roles');
$check(str_contains($projection, "COALESCE(la.ordine_credito, 2147483647)"), 'projection selection has a deterministic credit-order tie-break');
$check(str_contains($frontend, 'CatalogAuthorProjection::isReadable($db)'), 'catalog keeps an explicit rolling-upgrade fallback (completeness-gated: backfill window + failed rebuild)');
$bulkDeleteStart = strpos($authorsApi, 'public function bulkDelete(');
$bulkExportStart = strpos($authorsApi, 'public function bulkExport(');
$bulkDelete = $bulkDeleteStart !== false && $bulkExportStart !== false
    ? substr($authorsApi, $bulkDeleteStart, $bulkExportStart - $bulkDeleteStart)
    : '';
$projectionRebuild = strpos($bulkDelete, 'SearchIndexBuilder::rebuildMany');
$cacheInvalidation = strpos($bulkDelete, 'ContentCache::booksChanged');
$check(
    $projectionRebuild !== false
        && $cacheInvalidation !== false
        && $projectionRebuild < $cacheInvalidation,
    'bulk author deletion rebuilds derived fields before publishing cache invalidation'
);
$check(str_contains($schema, 'catalog_materialized_snapshots'), 'fresh installs receive the snapshot table');
$check(str_contains($schema, 'idx_libri_catalog_author_sort'), 'fresh installs receive the author ordering index');

try {
    \App\Support\QueryCache::namespaceGeneration('not-a-real-namespace');
    $check(false, 'unknown namespace is rejected at runtime');
} catch (InvalidArgumentException $e) {
    $check(true, 'unknown namespace is rejected at runtime');
}

echo PHP_EOL;
if ($failed > 0) {
    echo "FAILED: {$failed} check(s) failed, {$passed} passed\n";
    exit(1);
}
echo "ALL {$passed} PASS\n";

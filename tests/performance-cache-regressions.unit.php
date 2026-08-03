<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        echo "  OK  {$message}\n";
        $passed++;
        return;
    }

    echo "FAIL  {$message}\n";
    $failed++;
};

$read = static function (string $relative): string {
    $contents = file_get_contents(__DIR__ . '/../' . $relative);
    if ($contents === false) {
        throw new RuntimeException("Cannot read {$relative}");
    }
    return $contents;
};

echo "Catalogue cache cardinality:\n";
$controller = new \App\Controllers\FrontendController();
$reflection = new ReflectionClass($controller);
$bounded = $reflection->getMethod('hasBoundedCatalogCacheKey');
$base = [
    'search' => '',
    'genere_id' => 0,
    'disponibilita' => '',
    'editore' => '',
    'anno_min' => '',
    'anno_max' => '',
    'tipo_media' => '',
    'autore_id' => 0,
    'sort' => 'newest',
];
$check($bounded->invoke($controller, $base) === true, 'default catalogue state is cacheable');
$check($bounded->invoke($controller, array_replace($base, ['disponibilita' => 'prestato'])) === true, 'finite availability state is cacheable');
foreach ([
    ['search' => 'unique request text'],
    ['editore' => 'request-controlled publisher'],
    ['genere_id' => 999999],
    ['autore_id' => 999999],
    ['anno_min' => '1234'],
    ['tipo_media' => 'request-controlled-type'],
    ['disponibilita' => 'request-controlled-state'],
] as $unboundedFilter) {
    $check(
        $bounded->invoke($controller, array_replace($base, $unboundedFilter)) === false,
        'request-controlled filter tuple bypasses persistent cache: ' . array_key_first($unboundedFilter)
    );
}
$frontend = $read('app/Controllers/FrontendController.php');
$check(!str_contains($frontend, 'QueryCache::remember($cacheKeyGeneri'), 'genre query has no second unbounded per-filter cache');

echo "\nInvalidation coverage and transaction boundary:\n";
$cms = $read('app/Controllers/CmsController.php');
$reorderStart = strpos($cms, 'public function reorderHomeSections');
$toggleStart = strpos($cms, 'public function toggleSectionVisibility');
$check($reorderStart !== false && $toggleStart !== false, 'CMS mutation methods are present');
$reorderBody = $reorderStart !== false && $toggleStart !== false
    ? substr($cms, $reorderStart, $toggleStart - $reorderStart)
    : '';
$toggleBody = $toggleStart !== false ? substr($cms, $toggleStart) : '';
$check(str_contains($reorderBody, 'ContentCache::homeContentChanged()'), 'home reorder invalidates cached order after commit');
$check(str_contains($toggleBody, 'ContentCache::homeContentChanged()'), 'home visibility toggle invalidates cached visibility');

$contentCache = $read('app/Support/ContentCache.php');
$check(str_contains($contentCache, 'function deferBooksChanged'), 'transaction-owned writers can defer invalidation');
$integrity = $read('app/Support/DataIntegrity.php');
$check(str_contains($integrity, 'ContentCache::deferBooksChanged()'), 'DataIntegrity defers invalidation for outer transactions');
$check(
    substr_count($integrity, 'ContentCache::booksChanged()') >= 2,
    'single and global standalone recalculations invalidate after their commits'
);

foreach ([
    'app/Models/AuthorRepository.php',
    'app/Models/PublisherRepository.php',
    'app/Models/GenereRepository.php',
    'app/Services/BulkEnrichmentService.php',
] as $writer) {
    $check(str_contains($read($writer), 'ContentCache::'), "{$writer} invalidates dependent public data");
}

echo "\nPlugin hook prefetch ordering:\n";
$pluginManager = $read('app/Support/PluginManager.php');
$loadStart = strpos($pluginManager, 'public function loadActivePlugins');
$loadEnd = strpos($pluginManager, 'public static function clearPluginCache', $loadStart ?: 0);
$loadBody = $loadStart !== false && $loadEnd !== false
    ? substr($pluginManager, $loadStart, $loadEnd - $loadStart)
    : '';
$instantiateAt = strpos($loadBody, '$this->instantiatePlugin($plugin)');
$fetchAt = strpos($loadBody, '$this->fetchHooksForPlugins(');
$check(
    $instantiateAt !== false && $fetchAt !== false && $instantiateAt < $fetchAt,
    'plugin self-heal runs before hook rows are fetched and cached'
);

echo "\n================================\n";
echo "Passed: {$passed}   Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);

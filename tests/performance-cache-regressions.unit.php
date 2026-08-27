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

/**
 * Assert that every literal cache-key family in a producer belongs to the
 * namespace cleared by its invalidator. Reuse this for future cache families.
 */
$checkCacheNamespace = static function (
    string $producer,
    string $keyFamily,
    string $namespace,
    string $invalidator,
    string $label
) use ($check): void {
    preg_match_all(
        "/QueryCache::remember\\(\\s*'([^']*" . preg_quote($keyFamily, '/') . "[^']*)'/",
        $producer,
        $matches
    );
    $keys = $matches[1] ?? [];
    $check($keys !== [], "{$label} cache keys are discoverable");
    $check(
        $keys !== [] && count(array_filter($keys, static fn(string $key): bool => str_starts_with($key, $namespace))) === count($keys),
        "{$label} keys use the {$namespace} namespace"
    );
    // Either invalidation form targets the namespace: the legacy prefix scan
    // or the O(1) generation bump (issue #387) — both make every prior entry
    // in the namespace unservable.
    $check(
        str_contains($invalidator, "QueryCache::clearByPrefix('{$namespace}')")
            || str_contains($invalidator, "QueryCache::bumpGeneration('{$namespace}')"),
        "{$label} invalidator clears the {$namespace} namespace"
    );
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
$availabilityStart = strpos($frontend, '$availabilityLoader = function');
$availabilityEnd = strpos($frontend, '$available_books = $this->rememberCatalogValue', $availabilityStart ?: 0);
$availabilityBody = $availabilityStart !== false && $availabilityEnd !== false
    ? substr($frontend, $availabilityStart, $availabilityEnd - $availabilityStart)
    : '';
$check(
    str_contains($availabilityBody, 'return 0;') && str_contains($availabilityBody, '$available_books = 0;'),
    'available-book DB failures return a cacheable zero instead of repeated null misses'
);

echo "\nBehavioural cache contracts:\n";
$runKey = 'pr323_' . bin2hex(random_bytes(6));
$cacheKeys = [
    'catalog_' . $runKey => 'catalog value',
    'home_' . $runKey => 'home value',
    'genre_tree_' . $runKey => 'genre value',
    'unrelated_' . $runKey => 'unrelated value',
];
foreach ($cacheKeys as $key => $value) {
    $check(\App\Support\QueryCache::set($key, $value, 120), "fixture cache entry {$key} is written");
}
\App\Support\ContentCache::booksChanged();
$check(\App\Support\QueryCache::get('catalog_' . $runKey) === null, 'booksChanged clears catalogue values');
$check(\App\Support\QueryCache::get('home_' . $runKey) === null, 'booksChanged clears home values');
$check(\App\Support\QueryCache::get('genre_tree_' . $runKey) === null, 'booksChanged clears genre-tree values');
$check(\App\Support\QueryCache::get('unrelated_' . $runKey) === 'unrelated value', 'booksChanged preserves unrelated namespaces');

\App\Support\QueryCache::set('catalog_' . $runKey, 'catalog value', 120);
\App\Support\QueryCache::set('home_' . $runKey, 'home value', 120);
\App\Support\ContentCache::homeContentChanged();
$check(\App\Support\QueryCache::get('home_' . $runKey) === null, 'homeContentChanged clears home values');
$check(\App\Support\QueryCache::get('catalog_' . $runKey) === 'catalog value', 'homeContentChanged preserves catalogue values');

$rememberCatalogValue = $reflection->getMethod('rememberCatalogValue');
$boundedCalls = 0;
$boundedKey = 'catalog_' . $runKey . '_bounded';
$boundedLoader = static function () use (&$boundedCalls): string {
    $boundedCalls++;
    return 'bounded-' . $boundedCalls;
};
$firstBounded = $rememberCatalogValue->invoke($controller, $boundedKey, $base, $boundedLoader);
$secondBounded = $rememberCatalogValue->invoke($controller, $boundedKey, $base, $boundedLoader);
$check($firstBounded === 'bounded-1' && $secondBounded === 'bounded-1' && $boundedCalls === 1, 'bounded catalogue state executes its loader once');

$unboundedCalls = 0;
$unboundedLoader = static function () use (&$unboundedCalls): string {
    $unboundedCalls++;
    return 'unbounded-' . $unboundedCalls;
};
$unboundedFilters = array_replace($base, ['search' => $runKey]);
$firstUnbounded = $rememberCatalogValue->invoke($controller, 'catalog_' . $runKey . '_unbounded', $unboundedFilters, $unboundedLoader);
$secondUnbounded = $rememberCatalogValue->invoke($controller, 'catalog_' . $runKey . '_unbounded', $unboundedFilters, $unboundedLoader);
$check($firstUnbounded === 'unbounded-1' && $secondUnbounded === 'unbounded-2' && $unboundedCalls === 2, 'unbounded catalogue state bypasses persistent cache on every call');

// Cross-request caches can be exercised without a live query: pre-seed the
// shared cache and use an intentionally disconnected mysqli. Any unexpected DB
// access would throw and fail the test, which makes these useful hit-path tests.
$i18nReflection = new ReflectionClass(\App\Support\I18n::class);
$i18nReflection->getProperty('languagesCache')->setValue(null, null);
$i18nReflection->getProperty('languagesLoadedFromDb')->setValue(null, false);
\App\Support\QueryCache::set('i18n_languages', [
    ['code' => 'en-us', 'native_name' => 'English', 'is_default' => 1],
    ['code' => '../bad', 'native_name' => 'Invalid', 'is_default' => 0],
], 120);
$disconnectedDb = new mysqli();
$check(\App\Support\I18n::loadFromDatabase($disconnectedDb), 'I18n consumes cached language rows without a database query');
$check(\App\Support\I18n::getAvailableLocales() === ['en_US' => 'English'], 'I18n normalizes valid cached locales and rejects invalid codes');

$themeReflection = new ReflectionClass(\App\Support\ThemeManager::class);
$themeReflection->getProperty('activeThemeMemo')->setValue(null, []);
\App\Support\QueryCache::set('active_theme_row', ['theme' => ['id' => 323, 'name' => 'Cached theme']], 120);
$themeManager = $themeReflection->newInstanceWithoutConstructor();
$check($themeManager->getActiveTheme()['id'] === 323, 'ThemeManager serves a cached active-theme row without a database query');
$themeReflection->getProperty('activeThemeMemo')->setValue(null, []);
\App\Support\QueryCache::set('active_theme_row', ['theme' => null], 120);
$check($themeManager->getActiveTheme() === null, 'ThemeManager caches the no-active-theme result with a sentinel');
\App\Support\ThemeManager::clearThemeCache();
$check(\App\Support\QueryCache::get('active_theme_row') === null, 'clearThemeCache removes the cross-request row');

\App\Support\QueryCache::set('schema_table_pr323_cached', true, 120);
\App\Support\SchemaInfo::resetCache();
$check(\App\Support\QueryCache::get('schema_table_pr323_cached') === null, 'SchemaInfo reset clears its cross-request namespace');

foreach (array_keys($cacheKeys) as $key) {
    \App\Support\QueryCache::delete($key);
}
\App\Support\QueryCache::delete($boundedKey);
\App\Support\QueryCache::delete('catalog_' . $runKey . '_unbounded');
\App\Support\QueryCache::delete('i18n_languages');

// Generation bumps make old entries unreachable, so delete() can only remove
// the current generation. Reclaim every fixture from this run explicitly in
// both possible stores without flushing unrelated application/test entries.
if (\App\Support\QueryCache::stats()['backend'] === 'apcu' && class_exists('APCUIterator')) {
    $runPattern = '/^pinakes_.*' . preg_quote($runKey, '/') . '/';
    foreach (new APCUIterator($runPattern) as $entry) {
        apcu_delete($entry['key']);
    }
}
$leftovers = glob(__DIR__ . '/../storage/cache/pinakes_*' . $runKey . '*');
if ($leftovers !== false) {
    foreach ($leftovers as $leftover) {
        @unlink($leftover);
    }
}

$i18nReflection->getProperty('languagesCache')->setValue(null, null);
$i18nReflection->getProperty('languagesLoadedFromDb')->setValue(null, false);

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
$checkCacheNamespace($frontend, 'home_api_count_', 'home_', $contentCache, 'home API counts');
$check(str_contains($contentCache, 'function deferBooksChanged'), 'transaction-owned writers can defer invalidation');
$integrity = $read('app/Support/DataIntegrity.php');
$check(str_contains($integrity, 'ContentCache::deferBooksChanged()'), 'DataIntegrity defers invalidation for outer transactions');
$check(
    substr_count($integrity, 'ContentCache::booksChanged()') >= 2,
    'single and global standalone recalculations invalidate after their commits'
);
$settingsRepository = $read('app/Models/SettingsRepository.php');
$check(
    substr_count($settingsRepository, '\\App\\Support\\ConfigStore::clearCache()') >= 2,
    'settings set/delete invalidate ConfigStore through the shared repository boundary'
);
$languageModel = $read('app/Models/Language.php');
$check(
    substr_count($languageModel, "QueryCache::delete('i18n_languages')") >= 5,
    'every language mutation invalidates the shared active-language cache'
);

foreach ([
    'app/Models/AuthorRepository.php',
    'app/Models/PublisherRepository.php',
    'app/Models/GenereRepository.php',
    'app/Services/BulkEnrichmentService.php',
    'app/Controllers/LibriApiController.php',
    'app/Controllers/CsvImportController.php',
    'app/Controllers/LibraryThingImportController.php',
    'app/Controllers/CollaneController.php',
    'storage/plugins/book-club/src/Repo.php',
] as $writer) {
    $check(str_contains($read($writer), 'ContentCache::'), "{$writer} invalidates dependent public data");
}
$csvImport = $read('app/Controllers/CsvImportController.php');
$libraryThingImport = $read('app/Controllers/LibraryThingImportController.php');
$check(
    str_contains($csvImport, 'recalculateBookAvailability($bookId, insideTransaction: true)')
        && str_contains($libraryThingImport, 'recalculateBookAvailability($bookId, insideTransaction: true)'),
    'importers preserve their per-row transaction while recalculating new-book availability'
);
$bulkEnrichment = $read('app/Services/BulkEnrichmentService.php');
$check(
    str_contains($bulkEnrichment, 'ContentCache::deferBooksChanged()')
        && !str_contains($bulkEnrichment, 'ContentCache::booksChanged()'),
    'bulk enrichment collapses repeated cache invalidations into one deferred sweep'
);

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
// Structure over source-text: assert the contract methods EXIST (a rename can't
// silently hollow them out) rather than matching implementation strings/log
// messages. The actual error-handling and caller-transaction BEHAVIOUR is
// exercised end-to-end by plugin-hook-transaction-contract.unit.php.
$check(
    method_exists(\App\Support\PluginManager::class, 'loadActivePlugins'),
    'PluginManager exposes the bulk plugin/hook loader'
);

require_once __DIR__ . '/../storage/plugins/digital-library/DigitalLibraryPlugin.php';
require_once __DIR__ . '/../storage/plugins/goodlib/GoodLibPlugin.php';
$pluginContracts = [
    'Digital Library' => \DigitalLibraryPlugin::class,
    'GoodLib' => \GoodLibPlugin::class,
];
foreach ($pluginContracts as $label => $class) {
    $ref = new ReflectionClass($class);
    $check(
        $ref->hasMethod('setPluginId') && $ref->hasMethod('hasActiveTransaction'),
        "{$label} keeps the self-heal entry point and the caller-transaction probe"
    );
}

echo "\n================================\n";
echo "Passed: {$passed}   Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);

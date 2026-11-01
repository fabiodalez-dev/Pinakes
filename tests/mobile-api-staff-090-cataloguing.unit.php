<?php
declare(strict_types=1);

/**
 * Mobile API 1.10.0 — the cataloguing areas, the parts that need no database:
 *
 *   A. SupplierPayload: the web form's rules (name required, e-mail and
 *      website refused when they are not one, https:// added), over-long
 *      values refused, the PUT merge (absent key kept, null clears), mapping.
 *   B. StaffBookPayload cataloguing keys: ignored while a module is off,
 *      validated and posted while it is on.
 *   C. MarcJobMapper: the CSV ImportJob keys first, counters from the core
 *      state before the first chunk and from the last chunk afterwards.
 *   D. CopyCatalogingMapper, and its fingerprint pinned against the
 *      plugin's own formula (the replay check must agree with it).
 *   E. Permissions: the new /api/v1/staff prefixes follow the catalogue.
 *   F. Areas: every route is under /staff, documented in OpenAPI, and the
 *      documented paths are exactly the routes.
 *
 * Run: php tests/mobile-api-staff-090-cataloguing.unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

$plugin = __DIR__ . '/../storage/plugins/mobile-api/src';
require_once $plugin . '/Support/OpenApiParts.php';
require_once $plugin . '/Support/StaffBookPayload.php';
require_once $plugin . '/Staff090/StaffArea.php';
foreach (['SupplierPayload', 'MarcJobMapper', 'CopyCatalogingMapper'] as $class) {
    require_once $plugin . '/Staff090/Support/' . $class . '.php';
}
foreach (['StaffSuppliersController', 'StaffCatalogueCardsController', 'StaffMarcController', 'StaffCopyCatalogingController'] as $class) {
    require_once $plugin . '/Staff090/Controllers/' . $class . '.php';
}
foreach (['SuppliersArea', 'CatalogueCardsArea', 'MarcArea'] as $class) {
    require_once $plugin . '/Staff090/Areas/' . $class . '.php';
}

use App\Plugins\MobileApi\Staff090\Support\CopyCatalogingMapper;
use App\Plugins\MobileApi\Staff090\Support\MarcJobMapper;
use App\Plugins\MobileApi\Staff090\Support\SupplierPayload;
use App\Plugins\MobileApi\Support\StaffBookPayload;

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    echo ($condition ? '  OK  ' : '  FAIL ') . $label . "\n";
    $condition ? $passed++ : $failed++;
};

// ── A. SupplierPayload ───────────────────────────────────────────────────────
echo "A. SupplierPayload\n";
$r = SupplierPayload::validate([]);
$check(array_keys($r['errors']) === ['name'], 'create without name → name required');
$r = SupplierPayload::validate(['name' => 'Libreria', 'email' => 'nope', 'website' => 'not a url']);
$check(array_keys($r['errors']) === ['email', 'website'], 'invalid email and website are refused, not dropped');
$r = SupplierPayload::validate(['name' => str_repeat('n', 256), 'vat_number' => str_repeat('1', 21), 'phone' => str_repeat('3', 31)]);
$check(array_keys($r['errors']) === ['name', 'phone', 'vat_number'], 'over-long values are refused (not cut)');
$r = SupplierPayload::validate(['name' => ['x']]);
$check(isset($r['errors']['name']) && count($r['errors']) === 1, 'a non-scalar value is invalid (one message per field)');
$r = SupplierPayload::validate(['name' => '  Libreria Rossi ', 'website' => 'www.rossi.it', 'email' => ' info@rossi.it ', 'notes' => '']);
$check($r['errors'] === [], 'valid create');
$check($r['data']['nome'] === 'Libreria Rossi' && $r['data']['sito_web'] === 'https://www.rossi.it' && $r['data']['email'] === 'info@rossi.it' && $r['data']['note'] === null, 'trimmed, https:// added, blank → NULL');
$check(array_keys($r['data']) === \App\Models\SupplierRepository::FIELDS, 'data has exactly the repository fields');
$stored = ['id' => 4, 'nome' => 'Libreria', 'referente' => 'Anna', 'email' => 'a@b.it', 'telefono' => '123', 'sito_web' => 'https://b.it', 'indirizzo' => 'Via 1', 'partita_iva' => 'IT1', 'note' => 'n'];
$r = SupplierPayload::validate(['phone' => null, 'notes' => 'nuova'], $stored);
$check($r['errors'] === [] && $r['data']['referente'] === 'Anna' && $r['data']['telefono'] === null && $r['data']['note'] === 'nuova' && $r['data']['sito_web'] === 'https://b.it', 'PUT: absent kept, null clears, given replaces');
$r = SupplierPayload::validate(['name' => null], $stored);
$check(array_keys($r['errors']) === ['name'], 'PUT name null → required');
$m = SupplierPayload::map($stored + ['created_at' => '2026-10-01 10:00:00', 'updated_at' => null], 3, true);
$check($m['id'] === 4 && $m['contact_person'] === 'Anna' && $m['vat_number'] === 'IT1' && $m['book_count'] === 3 && $m['can_edit'] === true && $m['updated_at'] === null, 'map: English keys, counts, permissions');
$check(is_string($m['created_at']) && preg_match('/^2026-10-01T10:00:00[+-]\d{2}:\d{2}$/', $m['created_at']) === 1, 'map: ISO 8601 created_at');

// ── B. Book cataloguing keys ─────────────────────────────────────────────────
echo "B. StaffBookPayload cataloguing keys\n";
$json = ['supplier_id' => 'x', 'rda_content_type' => 'zzz'];
$check(StaffBookPayload::validateCataloguing($json, false, false) === null, 'modules off: nothing validated');
$check((StaffBookPayload::validateCataloguing($json, true, false)['field'] ?? '') === 'supplier_id', 'suppliers on: malformed id → supplier_id');
$check((StaffBookPayload::validateCataloguing($json, false, true)['field'] ?? '') === 'rda_content_type', 'rda on: unknown code → rda_content_type');
$check(StaffBookPayload::validateCataloguing(['rda_media_type' => 'c'], false, true) === null, 'a valid media code passes');
$check((StaffBookPayload::validateCataloguing(['rda_carrier_type' => 'txt'], false, true)['field'] ?? '') === 'rda_carrier_type', 'a code of another kind is refused');
$check(StaffBookPayload::validateCataloguing(['supplier_id' => null, 'rda_content_type' => '', 'rda_media_type' => null], true, true) === null, 'null / empty are the "none" choice');
$post = [];
StaffBookPayload::applyCataloguing($post, ['supplier_id' => 7, 'rda_content_type' => ' SPW ', 'rda_media_type' => null, 'title' => 'x'], true, true);
$check($post === ['fornitore_id' => '7', 'rda_content_type' => 'spw', 'rda_media_type' => ''], 'posted keys: id, normalised code, empty choice; absent keys not posted');
$post = [];
StaffBookPayload::applyCataloguing($post, ['supplier_id' => 7, 'rda_content_type' => 'spw'], false, false);
$check($post === [], 'modules off: nothing posted (the stored values stay)');
$post = [];
StaffBookPayload::applyCataloguing($post, ['supplier_id' => null], true, false);
$check($post === ['fornitore_id' => ''], 'supplier null clears');

// ── C. MarcJobMapper ─────────────────────────────────────────────────────────
echo "C. MarcJobMapper\n";
$job = [
    'id' => '11111111-2222-4333-8444-555555555555', 'kind' => 'marc', 'file_name' => 'a.mrc', 'status' => 'ready',
    'total_rows' => 12, 'processed' => 0, 'chunk_size' => 10, 'enable_scraping' => 0, 'summary' => null,
    'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00', 'expires_at' => '2026-11-02 10:00:00',
    'state' => [
        'mode' => 'fill', 'covers' => false, 'fornitore_id' => 9, 'processed' => 0, 'counters' => [],
        'errors' => [['line' => 3, 'title' => 'T', 'message' => 'bad', 'type' => 'parse'], ['line' => 4, 'title' => 'D', 'message' => 'dup', 'type' => 'duplicate']],
        'issue_counts' => ['parse' => 1],
        MarcJobMapper::UPLOAD_KEY => ['unreadable' => 1, 'truncated' => null, 'container' => 'iso2709', 'flavour' => 'auto', 'encoding' => 'utf8', 'preview' => [
            ['position' => 1, 'flavour' => 'marc21', 'titolo' => 'Uno', 'sottotitolo' => '', 'autori' => 'A', 'editore' => 'E', 'anno' => '2001', 'isbn' => '9780000000002', 'copertina' => true, 'existing_id' => 5],
            ['position' => 3, 'error' => 'Record non leggibile'],
        ]],
    ],
];
$m = MarcJobMapper::map($job);
$csvKeys = ['id', 'kind', 'file_name', 'status', 'total_rows', 'processed', 'chunk_size', 'enable_scraping', 'imported', 'updated', 'scraped', 'authors_created', 'publishers_created', 'errors', 'error_details', 'complete', 'created_at', 'updated_at', 'expires_at'];
$check(array_slice(array_keys($m), 0, count($csvKeys)) === $csvKeys, 'the CSV ImportJob keys come first, in order');
$check($m['errors'] === 1 && $m['failed'] === 1 && $m['error_details'] === ['#3 (T): bad'], 'before a chunk: failures from the core state, duplicates not listed');
$check($m['duplicates'] === 'fill' && $m['supplier_id'] === 9 && $m['encoding'] === 'utf8' && $m['unreadable'] === 1 && $m['complete'] === false, 'upload settings');
$check($m['preview'][0] === ['position' => 1, 'flavour' => 'marc21', 'title' => 'Uno', 'subtitle' => null, 'authors' => 'A', 'publisher' => 'E', 'year' => '2001', 'isbn' => '9780000000002', 'has_cover' => true, 'existing_id' => 5, 'error' => null], 'preview row in English keys');
$check(array_keys($m['preview'][1]) === array_keys($m['preview'][0]) && $m['preview'][1]['error'] === 'Record non leggibile', 'an unreadable record has the same keys');
$job['status'] = 'completed';
$job['processed'] = 12;
$job['summary'] = ['imported' => 10, 'updated' => 1, 'skipped' => 1, 'covers' => 2, 'authors_created' => 3, 'publishers_created' => 1, 'failed' => 0, 'error_details' => [], 'import_id' => 'abc123'];
$m = MarcJobMapper::map($job);
$check($m['imported'] === 10 && $m['skipped'] === 1 && $m['scraped'] === 2 && $m['covers'] === 2 && $m['errors'] === 0 && $m['error_details'] === [] && $m['import_id'] === 'abc123' && $m['complete'] === true, 'after the last chunk: its answer is the authority');

// ── D. CopyCatalogingMapper ──────────────────────────────────────────────────
echo "D. CopyCatalogingMapper\n";
$row = CopyCatalogingMapper::result(['id' => 'aaaaaaaaaaaaaaaa', 'server' => 'LoC', 'flavour' => 'marc21', 'titolo' => 'T', 'sottotitolo' => '', 'autori' => 'X', 'editore' => '', 'anno' => '1999', 'isbn' => '', 'copertina' => '', 'existing_id' => null, 'detail' => [['tag' => '245', 'ind' => '10', 'value' => '$a T']]]);
$check($row['title'] === 'T' && $row['subtitle'] === null && $row['isbn'] === null && $row['existing_id'] === null && $row['detail'][0]['tag'] === '245', 'search result');
$check(CopyCatalogingMapper::report(['key' => 'sbn', 'name' => 'SBN', 'total' => 4, 'count' => 2, 'error' => null]) === ['key' => 'sbn', 'name' => 'SBN', 'total' => 4, 'count' => 2, 'error' => null], 'server report');
$imp = CopyCatalogingMapper::importResult(['imported' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 1, 'covers' => 0, 'import_id' => 'x', 'items' => [
    ['id' => 'a', 'action' => 'created', 'book_id' => 7, 'title' => 'T', 'warnings' => []],
    ['id' => 'b', 'action' => 'failed', 'message' => 'Risultato non più disponibile: ripeti la ricerca'],
]]);
$check($imp['items'][0]['book_id'] === 7 && $imp['items'][1]['book_id'] === null && $imp['items'][1]['message'] !== null && $imp['items'][1]['title'] === '', 'import items have every key');
$check(CopyCatalogingMapper::fingerprint(['a', 'b', 'a'], 'skip', false) === hash('sha256', (string) json_encode([['a', 'b'], 'skip', false])), 'fingerprint of unique ids, mode, covers');
$source = (string) file_get_contents(__DIR__ . '/../storage/plugins/z39-server/classes/CopyCataloging.php');
$check(str_contains($source, "\$fingerprint = hash('sha256', (string) json_encode([array_values(array_unique(\$ids)), \$mode, \$covers]));"), 'pinned: CopyCataloging::import() computes the same fingerprint');

// ── E. Permissions ───────────────────────────────────────────────────────────
echo "E. Permissions\n";
foreach (['/api/v1/staff/suppliers', '/api/v1/staff/suppliers/4', '/api/v1/staff/marc/options', '/api/v1/staff/marc/imports/abc/chunk', '/api/v1/staff/copy-cataloging/search', '/api/v1/staff/books/3/catalogue-card.pdf', '/api/v1/staff/books/catalogue-cards.pdf'] as $path) {
    $check(\App\Support\Permissions::permissionForPath($path) === 'catalog.manage', "{$path} → catalog.manage");
}
$cataloguer = ['id' => 1, 'tipo_utente' => 'standard', 'stato' => 'attivo', 'permissions' => ['backend.access', 'catalog.manage']];
$desk = ['id' => 2, 'tipo_utente' => 'standard', 'stato' => 'attivo', 'permissions' => ['backend.access', 'loans.manage']];
$check(\App\Support\Permissions::allowsPath('/api/v1/staff/marc/export', $cataloguer) && !\App\Support\Permissions::allowsPath('/api/v1/staff/marc/export', $desk), 'a custom cataloguing role passes, a loan-desk role does not');
$check(!\App\Support\Permissions::allowsPath('/api/v1/staff/books/3/catalogue-card.pdf', $desk), 'the loan desk does not print catalogue cards');

// ── F. Areas: routes ⇔ OpenAPI ───────────────────────────────────────────────
echo "F. Areas\n";
$refs = [];
$norm = static fn (string $method, string $path): string => strtoupper($method) . ' ' . preg_replace('#\{[^/]*\}#', '{}', $path);
foreach ([
    \App\Plugins\MobileApi\Staff090\Areas\SuppliersArea::class => ['suppliers', 'suppliers', 5],
    \App\Plugins\MobileApi\Staff090\Areas\CatalogueCardsArea::class => ['catalogue_cards', 'catalogue_cards', 2],
    \App\Plugins\MobileApi\Staff090\Areas\MarcArea::class => ['marc', 'marc', 10],
] as $class => [$area, $feature, $count]) {
    $check($class::area() === $area && $class::feature() === $feature && $class::circulation() === false, "{$area}: area, feature, not circulation");
    $routes = [];
    foreach ($class::routes() as $route) {
        $check(str_starts_with($route[1], '/staff/') && is_callable($route[2]), "{$area}: {$route[0]} {$route[1]} is a /staff route with a handler");
        $routes[] = $norm($route[0], $route[1]);
    }
    $check(count($routes) === $count, "{$area}: {$count} routes");
    $doc = $class::openApi(['paths' => [], 'tags' => [], 'components' => ['schemas' => ['HealthPayload' => ['properties' => ['features' => ['properties' => []]]]]]]);
    $documented = [];
    foreach ($doc['paths'] as $path => $methods) {
        foreach (array_keys($methods) as $method) {
            $documented[] = $norm($method, $path);
            $op = $methods[$method];
            $check(isset($op['operationId'], $op['responses']) && $op['security'] === [['bearerAuth' => []]], "{$area}: {$method} {$path} documented with auth");
        }
    }
    sort($routes);
    sort($documented);
    $check($routes === $documented, "{$area}: documented paths are exactly the routes");
    $check(isset($doc['components']['schemas']['HealthPayload']['properties']['features']['properties']['staff_' . $area]), "{$area}: health flag documented");
    array_walk_recursive($doc, static function ($value, $key) use (&$refs): void {
        if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/components/schemas/')) {
            $refs[] = substr($value, strlen('#/components/schemas/'));
        }
    });
}
$known = ['Envelope', 'Supplier', 'SupplierWrite', 'MarcOptions', 'MarcPreviewRow', 'MarcImportJob', 'CopyCatalogingServer', 'CopyCatalogingResult', 'CopyCatalogingSearch', 'CopyCatalogingImport'];
$check(array_diff(array_unique($refs), $known) === [], 'every schema reference is defined by an area (or the shared Envelope)');

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} PASS, {$failed} FAIL\n");
exit($failed === 0 ? 0 : 1);

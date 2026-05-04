<?php
declare(strict_types=1);

/**
 * Unit test for the Archives plugin (issue #103, phase 1a).
 *
 * Scope: regression assertions on the DDL strings emitted by the plugin.
 * Constants and bundled-list membership are verified elsewhere (by PHPStan
 * at compile time, and by the plugin-integrity E2E regression at runtime),
 * so this file focuses on what PHPStan *cannot* determine statically:
 * the content of the DDL strings that will hit the production database
 * when an admin activates the plugin.
 *
 * Run:
 *   php tests/archives-plugin.unit.php
 * Exits 0 on success, 1 on any failure.
 */

require_once __DIR__ . '/../app/Support/Hooks.php';
require_once __DIR__ . '/../app/Support/HookManager.php';
require_once __DIR__ . '/../app/Support/ConfigStore.php';
require_once __DIR__ . '/../app/Support/SecureLogger.php';
require_once __DIR__ . '/../storage/plugins/archives/ArchivesPlugin.php';

use App\Plugins\Archives\ArchivesPlugin;

$failed = 0;
$passed = 0;

$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) {
        $passed++;
        echo "  OK  $label\n";
    } else {
        $failed++;
        echo "  FAIL $label\n";
    }
};

echo "DDL string shape — archival_units:\n";
$ddl = ArchivesPlugin::ddlArchivalUnits();
$check(str_contains($ddl, 'CREATE TABLE IF NOT EXISTS archival_units'), 'CREATE TABLE IF NOT EXISTS');
$check(str_contains($ddl, 'parent_id'), 'parent_id column (hierarchy)');
$check(str_contains($ddl, "ENUM('fonds','series','file','item')"), 'level enum matches LEVELS constant');
$check(str_contains($ddl, 'FOREIGN KEY (parent_id) REFERENCES archival_units(id)'), 'self-referencing FK for tree');
$check(str_contains($ddl, 'deleted_at'), 'soft-delete column aligned with libri convention');
$check(str_contains($ddl, 'FULLTEXT KEY ft_search'), 'full-text index for unified search');
$check(str_contains($ddl, 'UNIQUE KEY uq_reference (institution_code, reference_code)'), 'composite unique on institution+reference');
$check(str_contains($ddl, 'ENGINE=InnoDB'), 'InnoDB engine for FK support');
$check(str_contains($ddl, 'utf8mb4'), 'utf8mb4 charset for full Unicode');

echo "\nDDL string shape — authority_records:\n";
$ddl2 = ArchivesPlugin::ddlAuthorityRecords();
$check(str_contains($ddl2, 'CREATE TABLE IF NOT EXISTS authority_records'), 'CREATE TABLE IF NOT EXISTS');
$check(str_contains($ddl2, "ENUM('person','corporate','family')"), 'type enum matches AUTHORITY_TYPES');
$check(str_contains($ddl2, 'dates_of_existence'), 'ISAAR 5.2.1 dates column');
$check(str_contains($ddl2, 'functions'), 'ISAAR 5.2.5 functions column');
$check(str_contains($ddl2, 'FULLTEXT KEY ft_search'), 'full-text index for unified search');

echo "\nDDL string shape — archival_unit_authority link:\n";
$ddl3 = ArchivesPlugin::ddlArchivalAuthorityLinks();
$check(str_contains($ddl3, 'CREATE TABLE IF NOT EXISTS archival_unit_authority'), 'CREATE TABLE IF NOT EXISTS');
$check(str_contains($ddl3, "ENUM('creator','subject','recipient','custodian','associated')"), 'role enum covers ISAD relationships');
$check(str_contains($ddl3, 'ON DELETE CASCADE'), 'cascade delete when a unit or authority is removed');
$check(str_contains($ddl3, 'PRIMARY KEY (archival_unit_id, authority_id, role)'), 'composite PK prevents duplicate role links');

echo "\nplannedHooks() source-level checks:\n";
$source = file_get_contents(__DIR__ . '/../storage/plugins/archives/ArchivesPlugin.php');
$check($source !== false && str_contains($source, "'search.unified.sources'"),   'plannedHooks lists search.unified.sources');
$check($source !== false && str_contains($source, "'admin.menu.render'"),        'plannedHooks lists admin.menu.render');
$check($source !== false && str_contains($source, "'libri.authority.resolve'"),  'plannedHooks lists libri.authority.resolve');

echo "\nOAI-PMH interoperability regressions:\n";
$check($source !== false && str_contains($source, "\$app->get('/archives/{id:[0-9]+}/dc.xml'"), 'public Dublin Core route exists');
$posDecodeTok  = $source !== false ? strpos($source, 'decodeOaiResumptionToken($token)') : false;
$posMetaValid  = $source !== false ? strpos($source, "'cannotDisseminateFormat'") : false;
$check($posDecodeTok !== false && $posMetaValid !== false && $posDecodeTok < $posMetaValid,
    'resumptionToken is decoded before metadata validation');
$check(
    $source !== false && (bool) preg_match('/if\s*\(!\$identifiersOnly\)\s*\{[^}]*startElement\s*\(\s*[\'"]record[\'"]/', $source),
    'ListIdentifiers does not force record wrappers'
);
$check($source !== false && str_contains($source, 'archival_unit|archives'), 'ListMetadataFormats accepts canonical archival_unit identifier');
$check($source !== false && str_contains($source, "'noSetHierarchy'"), 'ListSets reports noSetHierarchy');
$check($source !== false && str_contains($source, "writeElementNs('dc', 'publisher'"), 'Dublin Core emits dc:publisher');
$check($source !== false && str_contains($source, 'application/rdf+xml'), 'Dublin Core discovery advertises application/rdf+xml');
$check($source !== false && str_contains($source, 'filename="archives.ead3.xml"'), 'EAD3 export filename matches issue #125');

$layoutSource = file_get_contents(__DIR__ . '/../app/Views/layout.php');
$check($layoutSource !== false && str_contains($layoutSource, '$headLinks'), 'admin layout renders $headLinks (structured, no XSS sink)');
$check($layoutSource !== false && !str_contains($layoutSource, "echo \"\\n\" . \$headExtra"), 'admin layout does not raw-echo $headExtra');
$frontendLayoutSource = file_get_contents(__DIR__ . '/../app/Views/frontend/layout.php');
$check($frontendLayoutSource !== false && str_contains($frontendLayoutSource, '$headLinks'), 'public layout renders $headLinks (structured, no XSS sink)');
$check($frontendLayoutSource !== false && !str_contains($frontendLayoutSource, "echo \"\\n\" . \$headExtra"), 'public layout does not raw-echo $headExtra');

$reflection = new ReflectionClass(ArchivesPlugin::class);
$instance = $reflection->newInstanceWithoutConstructor();

$invokeXmlFragment = static function (
    ReflectionClass $reflection,
    object $instance,
    string $methodName,
    array $args = []
): string {
    $xw = new XMLWriter();
    $xw->openMemory();
    $xw->setIndent(true);
    $method = $reflection->getMethod($methodName);
    $method->setAccessible(true);
    $method->invokeArgs($instance, array_merge([$xw], $args));
    return $xw->outputMemory();
};

echo "\nOAI-PMH interoperability behavior:\n";
$listSetsXml = $invokeXmlFragment($reflection, $instance, 'oaiListSets');
$check(str_contains($listSetsXml, 'code="noSetHierarchy"'), 'ListSets returns noSetHierarchy');
$check(!str_contains($listSetsXml, '<ListSets'), 'ListSets does not publish a set hierarchy');

$listRecordsSetXml = $invokeXmlFragment($reflection, $instance, 'oaiListRecords', [
    ['metadataPrefix' => 'oai_dc', 'set' => 'series'],
    false,
]);
$check(str_contains($listRecordsSetXml, 'code="noSetHierarchy"'), 'ListRecords rejects set filters with noSetHierarchy');

$buildDc = $reflection->getMethod('buildDublinCoreXml');
$buildDc->setAccessible(true);
$dcXml = (string) $buildDc->invoke($instance, [
    'constructed_title' => 'Test collection',
    'reference_code' => 'TEST-DC-001',
    'repository_name' => 'Archivio Storico Test',
    'level' => 'fonds',
], [
    ['role' => 'creator', 'authorised_form' => 'Creator Test'],
]);
$check(str_contains($dcXml, '<dc:publisher>Archivio Storico Test</dc:publisher>'), 'Dublin Core maps repository_name to dc:publisher');

$configReflection = new ReflectionClass(\App\Support\ConfigStore::class);
$runtimeCache = $configReflection->getProperty('runtimeCache');
$runtimeCache->setAccessible(true);
$previousRuntimeCache = $runtimeCache->getValue();
$runtimeCache->setValue(null, ['app' => ['name' => 'Pinakes Test Repository']]);
$dcFallbackXml = (string) $buildDc->invoke($instance, [
    'constructed_title' => 'Fallback publisher collection',
    'reference_code' => 'TEST-DC-002',
    'repository_name' => '',
    'level' => 'file',
], []);
$runtimeCache->setValue(null, $previousRuntimeCache);
$check(str_contains($dcFallbackXml, '<dc:publisher>Pinakes Test Repository</dc:publisher>'), 'Dublin Core falls back to configured app name as publisher');

echo "\nClass reflection — DI contract:\n";
$ctor = $reflection->getConstructor();
$check($ctor !== null, 'constructor is defined');
if ($ctor !== null) {
    $params = $ctor->getParameters();
    $check(count($params) === 2, 'constructor takes 2 params (db, hookManager)');
    $check(isset($params[0]) && (string) $params[0]->getType() === 'mysqli', 'first param is mysqli');
    $check(isset($params[1]) && (string) $params[1]->getType() === 'App\\Support\\HookManager', 'second param is HookManager');
}
$check($reflection->hasMethod('ensureSchema'), 'ensureSchema method exists');
$check($reflection->hasMethod('plannedHooks'), 'plannedHooks method exists');
$check($reflection->hasMethod('getHookManager'), 'getHookManager method exists');

echo "\nOAI-PMH resumptionToken round-trip:\n";
$encode = $reflection->getMethod('encodeOaiResumptionToken');
$encode->setAccessible(true);
$decode = $reflection->getMethod('decodeOaiResumptionToken');
$decode->setAccessible(true);
$token = (string) $encode->invoke($instance, 100, 'ead3', '2026-05-01T00:00:00Z', '2026-05-02T12:34:56Z', 'series');
$decoded = $decode->invoke($instance, $token);
$check(is_array($decoded), 'encoded token decodes');
$check(is_array($decoded) && $decoded['cursor'] === 100, 'token preserves cursor');
$check(is_array($decoded) && $decoded['metadataPrefix'] === 'ead3', 'token preserves metadataPrefix');
$check(is_array($decoded) && $decoded['from'] === '2026-05-01T00:00:00Z', 'token preserves from date with colons');
$check(is_array($decoded) && $decoded['until'] === '2026-05-02T12:34:56Z', 'token preserves until date with colons');
$check(is_array($decoded) && $decoded['set'] === 'series', 'token preserves set');
$check($decode->invoke($instance, 'not-valid-token') === null, 'invalid token is rejected');

echo "\n================================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed > 0 ? 1 : 0);

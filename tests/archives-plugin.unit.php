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

echo "\nClass reflection — DI contract:\n";
$reflection = new ReflectionClass(ArchivesPlugin::class);
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

echo "\n================================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed > 0 ? 1 : 0);

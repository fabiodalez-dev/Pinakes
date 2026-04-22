<?php
declare(strict_types=1);

/**
 * Seed-driven regression tests for the Archives plugin (PR #105).
 *
 * This validates the persistent SQL seed fixture without requiring a live DB:
 * each CASE line is treated as one reusable feature test and is checked
 * against ArchivesPlugin constants plus the generated DDL.
 *
 * Run:
 *   php tests/archives-feature-seeds.unit.php
 */

require_once __DIR__ . '/../app/Support/Hooks.php';
require_once __DIR__ . '/../app/Support/HookManager.php';
require_once __DIR__ . '/../app/Support/SecureLogger.php';
require_once __DIR__ . '/../storage/plugins/archives/ArchivesPlugin.php';

use App\Plugins\Archives\ArchivesPlugin;

$seedPath = __DIR__ . '/seeds/archives-feature-20.sql';
$sql = file_get_contents($seedPath);
if ($sql === false) {
    fwrite(STDERR, "Unable to read seed file: {$seedPath}\n");
    exit(1);
}

$pattern = '/^-- CASE\s+(E2E_ARCHIVE_\d{3})\s+level=(\w+)\s+material=(\w+)\s+color=(NULL|\w+)\s+status=(\w+)\s+parent=(NONE|E2E_ARCHIVE_\d{3})$/m';
preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER);

$failed = 0;
$passed = 0;

$fail = static function (string $label, string $detail) use (&$failed): void {
    $failed++;
    echo "  FAIL {$label}: {$detail}\n";
};

$pass = static function (string $label) use (&$passed): void {
    $passed++;
    echo "  OK   {$label}\n";
};

if (count($matches) !== 20) {
    $fail('case-count', 'expected exactly 20 CASE metadata lines, got ' . count($matches));
} else {
    $pass('case-count: 20 reusable archive seed cases loaded');
}

if (preg_match('/\bDELETE\s+FROM\s+(archival_units|authority_records|archival_unit_authority)\b/i', $sql) === 1) {
    $fail('persistent-seed-policy', 'seed SQL must not delete persistent archive data');
} else {
    $pass('persistent-seed-policy: no persistent archive DELETE statements');
}

$unitDdl = ArchivesPlugin::ddlArchivalUnits();
$linkDdl = ArchivesPlugin::ddlArchivalAuthorityLinks();
$statusValues = ['unclassified' => true, 'cataloguing' => true, 'completed' => true];
$seenRefs = [];
$seenLevels = [];
$seenMaterials = [];
$seenColors = [];
$seenStatuses = [];

foreach ($matches as $index => $match) {
    [, $ref, $level, $material, $color, $status, $parent] = $match;
    $label = '#' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) . " {$ref}";
    $caseFailures = [];

    if (isset($seenRefs[$ref])) {
        $caseFailures[] = 'reference code must be unique';
    }
    $seenRefs[$ref] = true;

    if (!array_key_exists($level, ArchivesPlugin::LEVELS)) {
        $caseFailures[] = "unknown archival level {$level}";
    }
    if (strpos($unitDdl, "'{$level}'") === false) {
        $caseFailures[] = "DDL is missing level enum {$level}";
    }

    if (!array_key_exists($material, ArchivesPlugin::SPECIFIC_MATERIALS)) {
        $caseFailures[] = "unknown specific material {$material}";
    }
    if (strpos($unitDdl, "'{$material}'") === false) {
        $caseFailures[] = "DDL is missing specific_material enum {$material}";
    }

    if ($color !== 'NULL' && !array_key_exists($color, ArchivesPlugin::COLOR_MODES)) {
        $caseFailures[] = "unknown color mode {$color}";
    }
    if ($color !== 'NULL' && strpos($unitDdl, "'{$color}'") === false) {
        $caseFailures[] = "DDL is missing color enum {$color}";
    }

    if (!isset($statusValues[$status])) {
        $caseFailures[] = "unknown material status {$status}";
    }
    if (strpos($unitDdl, "'{$status}'") === false) {
        $caseFailures[] = "DDL is missing material_status enum {$status}";
    }

    if ($parent !== 'NONE' && !isset($seenRefs[$parent])) {
        $caseFailures[] = "parent {$parent} must be declared before child {$ref}";
    }

    if (substr_count($sql, "'{$ref}'") < 1) {
        $caseFailures[] = 'seed SQL does not insert the reference code';
    }
    if ($parent !== 'NONE' && substr_count($sql, "'{$parent}'") < 2) {
        $caseFailures[] = 'seed SQL does not reference the declared parent';
    }

    $seenLevels[$level] = true;
    $seenMaterials[$material] = true;
    $seenColors[$color] = true;
    $seenStatuses[$status] = true;

    if ($caseFailures === []) {
        $pass($label);
    } else {
        $fail($label, implode('; ', $caseFailures));
    }
}

$coverageChecks = [
    'all 4 archival levels covered' => count($seenLevels) === count(ArchivesPlugin::LEVELS),
    'all 15 specific materials covered' => count($seenMaterials) === count(ArchivesPlugin::SPECIFIC_MATERIALS),
    'NULL plus 3 color modes covered' => count($seenColors) === count(ArchivesPlugin::COLOR_MODES) + 1,
    'all 3 material statuses covered' => count($seenStatuses) === 3,
    'upsert keeps seed rerunnable' => substr_count($sql, 'ON DUPLICATE KEY UPDATE') >= 1,
    'authority links are idempotent' => str_contains($sql, 'INSERT IGNORE INTO archival_unit_authority'),
    'authority role enum includes seeded creator links' => str_contains($linkDdl, "'creator'"),
    'authority role enum includes seeded subject links' => str_contains($linkDdl, "'subject'"),
    'authority role enum includes seeded recipient links' => str_contains($linkDdl, "'recipient'"),
    'authority role enum includes seeded custodian links' => str_contains($linkDdl, "'custodian'"),
    'authority role enum includes seeded associated links' => str_contains($linkDdl, "'associated'"),
];

foreach ($coverageChecks as $label => $ok) {
    if ($ok) {
        $pass($label);
    } else {
        $fail($label, 'coverage invariant failed');
    }
}

echo "\n================================\n";
echo "Archive seed checks passed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);

<?php
declare(strict_types=1);

/**
 * migrate_0.9.0-rc.1.sql is built from one delimited section per feature
 * ("-- ── <feature> ──"). Each section has its own behavioural test,
 * tests/migration-0.9.0-<feature>.unit.php; this entry point, the name
 * scripts/verify-schema.sh derives from version.json, runs them all and fails
 * when one fails or when a section ships without its test.
 *
 * Run: php tests/migration-0.9.0-rc.1.unit.php
 */

$root = dirname(__DIR__);
$sql = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.9.0-rc.1.sql');
preg_match_all('/^-- ── (?!end )(.+?) ──\s*$/mu', $sql, $m);
$sections = $m[1];
$tests = array_values(array_filter(
    glob($root . '/tests/migration-0.9.0-*.unit.php') ?: [],
    // This entry point matches its own glob (migration-0.9.0-rc.1): skip it.
    static fn (string $path): bool => basename($path) !== basename(__FILE__)
));
sort($tests);
if ($sections === [] || $tests === []) {
    fwrite(STDERR, "FAIL migrate_0.9.0-rc.1.sql needs delimited sections, each with a tests/migration-0.9.0-<feature>.unit.php\n");
    exit(1);
}
// Each section names its own test explicitly: a slug derived from the title
// would let a renamed section silently pick up (or lose) the wrong test, and
// a bare count lets one extra test hide a section that has none. A section in
// this map that the SQL does not (yet) carry is simply not checked.
$sectionTests = [
    'Inventory stocktake' => 'migration-0.9.0-stocktake.unit.php',
    'MARC bulk import / copy cataloguing' => 'migration-0.9.0-marc.unit.php',
    'minor features: visitor log, suppliers, RDA overrides' => 'migration-0.9.0-minor-features.unit.php',
    'patron-cards-import' => 'migration-0.9.0-patron-import.unit.php',
    'Circulation policy: patron types, rules matrix, consultation-only copies' => 'migration-0.9.0-circulation-policy.unit.php',
    'closures calendar' => 'migration-0.9.0-closures-calendar.unit.php',
    'fines-ledger (Phase 4: fines, patron ledger, debt block)' => 'migration-0.9.0-fines-ledger.unit.php',
];
$testNames = array_map('basename', $tests);
foreach ($sections as $section) {
    $expected = $sectionTests[$section] ?? null;
    if ($expected === null) {
        fwrite(STDERR, "FAIL migration section \"{$section}\" has no entry in the section-to-test map of " . basename(__FILE__) . "\n");
        exit(1);
    }
    if (!in_array($expected, $testNames, true)) {
        fwrite(STDERR, "FAIL migration section \"{$section}\" expects tests/{$expected}, which does not exist\n");
        exit(1);
    }
}
$php = PHP_BINARY;
foreach ($tests as $test) {
    echo '── ' . basename($test) . "\n";
    passthru(escapeshellarg($php) . ' ' . escapeshellarg($test), $code);
    if ($code !== 0) {
        fwrite(STDERR, 'FAIL ' . basename($test) . " exited {$code}\n");
        exit(1);
    }
}
echo 'SUCCESS ' . count($tests) . ' migration section tests for ' . count($sections) . " sections\n";

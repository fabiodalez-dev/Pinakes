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
if (count($tests) < count($sections)) {
    fwrite(STDERR, sprintf("FAIL %d migration sections (%s) but only %d section tests\n", count($sections), implode(', ', $sections), count($tests)));
    exit(1);
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

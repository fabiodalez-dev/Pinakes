<?php
declare(strict_types=1);

/**
 * Seed-driven regression tests for Discogs Cat# support (PR #102).
 *
 * The fixture file is intentionally kept under tests/seeds so these
 * real-world identifiers can be reused by E2E/API tests later instead of
 * being embedded only in this runner.
 *
 * Run:
 *   php tests/discogs-catno-seeds.unit.php
 */

require_once __DIR__ . '/../storage/plugins/discogs/DiscogsPlugin.php';

use App\Plugins\Discogs\DiscogsPlugin;

$seedPath = __DIR__ . '/seeds/discogs-catno-identifiers.json';
$json = file_get_contents($seedPath);
if ($json === false) {
    fwrite(STDERR, "Unable to read seed file: {$seedPath}\n");
    exit(1);
}

$fixtures = json_decode($json, true);
if (!is_array($fixtures)) {
    fwrite(STDERR, 'Invalid JSON fixture: ' . json_last_error_msg() . "\n");
    exit(1);
}

$failed = 0;
$passed = 0;
$plugin = new DiscogsPlugin();

$fail = static function (string $label, string $detail) use (&$failed): void {
    $failed++;
    echo "  FAIL {$label}: {$detail}\n";
};

$pass = static function (string $label) use (&$passed): void {
    $passed++;
    echo "  OK   {$label}\n";
};

if (count($fixtures) !== 20) {
    $fail('fixture-count', 'expected exactly 20 seed cases, got ' . count($fixtures));
} else {
    $pass('fixture-count: 20 reusable seed cases loaded');
}

$names = [];

foreach ($fixtures as $index => $fixture) {
    $label = '#' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)
        . ' ' . (string) ($fixture['name'] ?? 'unnamed');

    $input = (string) ($fixture['input'] ?? '');
    $expectedValid = (bool) ($fixture['valid'] ?? false);
    $expectedKind = (string) ($fixture['kind'] ?? '');
    $expectedCanonical = (string) ($fixture['canonical'] ?? '');
    $expectedQuery = (string) ($fixture['musicBrainzQuery'] ?? '');

    $caseFailures = [];
    $name = (string) ($fixture['name'] ?? '');
    if ($name === '' || isset($names[$name])) {
        $caseFailures[] = 'fixture names must be non-empty and unique';
    }
    $names[$name] = true;

    $actualValid = $plugin->validateBarcode(false, $input);
    if ($actualValid !== $expectedValid) {
        $caseFailures[] = 'validateBarcode expected '
            . ($expectedValid ? 'true' : 'false')
            . ', got '
            . ($actualValid ? 'true' : 'false');
    }

    $actualKind = DiscogsPlugin::identifierKind($input);
    if ($actualKind !== $expectedKind) {
        $caseFailures[] = "identifierKind expected {$expectedKind}, got {$actualKind}";
    }

    $actualCanonical = DiscogsPlugin::canonicalSearchIdentifier($input, $expectedKind);
    if ($actualCanonical !== $expectedCanonical) {
        $caseFailures[] = "canonical expected {$expectedCanonical}, got {$actualCanonical}";
    }

    $actualQuery = DiscogsPlugin::buildMusicBrainzQuery($input);
    if ($actualQuery !== $expectedQuery) {
        $caseFailures[] = "MusicBrainz query expected {$expectedQuery}, got {$actualQuery}";
    }

    if ($expectedKind === 'catno' && !str_starts_with($expectedQuery, 'catno:"')) {
        $caseFailures[] = 'catno fixtures must use quoted MusicBrainz phrase queries';
    }
    if ($expectedKind === 'barcode' && !str_starts_with($expectedQuery, 'barcode:')) {
        $caseFailures[] = 'barcode fixtures must use barcode: MusicBrainz queries';
    }
    if ($expectedKind === 'unknown' && $expectedQuery !== '') {
        $caseFailures[] = 'unknown fixtures must not build MusicBrainz queries';
    }

    if ($caseFailures === []) {
        $pass($label);
    } else {
        $fail($label, implode('; ', $caseFailures));
    }
}

echo "\n================================\n";
echo "Seed cases passed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);

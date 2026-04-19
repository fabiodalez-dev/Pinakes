<?php
declare(strict_types=1);

/**
 * Unit test for DiscogsPlugin::validateBarcode() and ::identifierKind().
 *
 * Regression for issue #101 (Hans' "Bonnie Raitt - Nick of Time" scenario):
 * Discogs rejected `CDP 7912682` because the old validator only accepted
 * pure-digit barcodes. This test asserts that Cat# strings are now accepted
 * and classified as `catno` (so fetchFromDiscogs/searchMusicBrainz route them
 * to the correct API parameter instead of `barcode=`).
 *
 * Run:
 *   php tests/discogs-catno.unit.php
 * Exits 0 on success, 1 on any failure.
 *
 * Note: we only exercise validateBarcode() and identifierKind(), which do not
 * touch Hooks / HTTP / DB. activate() (which references App\Support\Hooks) is
 * never called, so the real Hooks class does not need to be autoloaded here.
 */

require_once __DIR__ . '/../app/Support/Hooks.php';
require_once __DIR__ . '/../app/Support/HookManager.php';
require_once __DIR__ . '/../storage/plugins/discogs/DiscogsPlugin.php';

use App\Plugins\Discogs\DiscogsPlugin;

$plugin = new DiscogsPlugin();
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

echo "validateBarcode — should ACCEPT:\n";
$check($plugin->validateBarcode(true, '9780141036144'),   'true ISBN short-circuits');
$check($plugin->validateBarcode(false, '5099902988023'),  'EAN-13 13 digits');
$check($plugin->validateBarcode(false, '077778364627'),   'UPC-A 12 digits');
$check($plugin->validateBarcode(false, '5099902-988023'), 'EAN-13 with hyphen');
$check($plugin->validateBarcode(false, 'CDP 7912682'),    'Capitol CDP 7912682 (Hans)');
$check($plugin->validateBarcode(false, 'SRX-6272'),       'SRX-6272');
$check($plugin->validateBarcode(false, 'DGC-24425-2'),    'DGC-24425-2');
$check($plugin->validateBarcode(false, '74321 66847 2'),  'BMG 74321 66847 2 (spaced)');

echo "\nvalidateBarcode — should REJECT:\n";
$check(!$plugin->validateBarcode(false, ''),              'empty');
$check(!$plugin->validateBarcode(false, 'LP'),            'pure letters too short');
$check(!$plugin->validateBarcode(false, 'ABC'),           '3 chars below min length');
$check(!$plugin->validateBarcode(false, '12345'),         '5 digits (not 12 or 13)');
$check(!$plugin->validateBarcode(false, 'only-letters'),  'no digits');
$check(!$plugin->validateBarcode(false, '12345678'),      '8 digits (not 12 or 13)');
$check(!$plugin->validateBarcode(false, str_repeat('A1', 20)), 'too long (>30 chars)');

echo "\nidentifierKind — classification:\n";
$check(DiscogsPlugin::identifierKind('5099902988023') === 'barcode', 'EAN-13 → barcode');
$check(DiscogsPlugin::identifierKind('077778364627')  === 'barcode', 'UPC-A → barcode');
$check(DiscogsPlugin::identifierKind('CDP 7912682')   === 'catno',   'CDP 7912682 → catno (Hans)');
$check(DiscogsPlugin::identifierKind('SRX-6272')      === 'catno',   'SRX-6272 → catno');
$check(DiscogsPlugin::identifierKind('DGC-24425-2')   === 'catno',   'DGC-24425-2 → catno');
$check(DiscogsPlugin::identifierKind('nonsense!!!')   === 'unknown', 'special chars → unknown');
$check(DiscogsPlugin::identifierKind('')              === 'unknown', 'empty → unknown');

echo "\nbuildMusicBrainzQuery — Lucene field:value:\n";
// CodeRabbit regression: `catno:CDP 7912682` without quotes is parsed by
// Lucene as `catno:CDP AND 7912682`, returning false-positives. Must be
// wrapped in double quotes (MusicBrainz Indexed Search Syntax docs).
$check(DiscogsPlugin::buildMusicBrainzQuery('CDP 7912682') === 'catno:"CDP 7912682"',   'catno multi-word is quoted');
$check(DiscogsPlugin::buildMusicBrainzQuery('SRX-6272')    === 'catno:"SRX-6272"',      'catno single-token is still quoted (safe)');
$check(DiscogsPlugin::buildMusicBrainzQuery('5099902988023') === 'barcode:5099902988023', 'EAN-13 → barcode field, unquoted');
$check(DiscogsPlugin::buildMusicBrainzQuery('5099902-988023') === 'barcode:5099902988023', 'EAN-13 with hyphen → digits stripped');
$check(DiscogsPlugin::buildMusicBrainzQuery('077778364627') === 'barcode:077778364627',  'UPC-A → barcode, zero-prefix preserved');
$check(DiscogsPlugin::buildMusicBrainzQuery('74321 66847 2') === 'catno:"74321 66847 2"', 'BMG pure-numeric Cat# quoted');

echo "\n================================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed > 0 ? 1 : 0);

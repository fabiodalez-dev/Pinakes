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
 * Note: we only exercise validateBarcode(), identifierKind(),
 * buildMusicBrainzQuery() and canonicalSearchIdentifier(), which do not
 * touch Hooks / HTTP / DB. activate() (which references App\Support\Hooks
 * and App\Support\HookManager) is never called from this test, so those
 * classes are intentionally NOT required here.
 */

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
$check($plugin->validateBarcode(false, '0777 7836 4627'), 'UPC-A with grouping spaces');
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

echo "\nidentifierKind — ISBN-10 must NEVER be classified as catno (CodeRabbit):\n";
// Regression: before the isIsbn10() guard in isCatalogNumber(), valid
// ISBN-10s ending in 'X' (e.g. "080442957X") matched the generic
// alphanum+letter heuristic and were routed to Discogs as catno=XXX,
// causing music metadata to bleed into book records.
$check(DiscogsPlugin::identifierKind('080442957X')    !== 'catno',   'ISBN-10 080442957X NOT catno');
$check(DiscogsPlugin::identifierKind('080442957X')    === 'unknown', 'ISBN-10 080442957X → unknown (plugin sees "not my key")');
$check(DiscogsPlugin::identifierKind('020161622X')    !== 'catno',   'ISBN-10 020161622X (Knuth TAoCP) NOT catno');
$check(DiscogsPlugin::identifierKind('0-8044-2957-X') !== 'catno',   'hyphenated ISBN-10 NOT catno');
// Pure-digit ISBN-10 like "0804429570" would anyway be caught earlier
// (10 digits isn't a valid barcode length either) but assert the veto.
$check(DiscogsPlugin::identifierKind('0471958697')    !== 'catno',   'all-digit ISBN-10 NOT catno');
// Negative control: a genuine Cat# with terminal 'X' that is NOT a valid
// ISBN-10 checksum must still be accepted as catno.
$check(DiscogsPlugin::identifierKind('DGC24425X')     === 'catno',   'non-ISBN-10 alnum+X still catno');

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
// CodeRabbit round 5: catno padded with leading/trailing whitespace must be
// trimmed via canonicalSearchIdentifier before being embedded in the Lucene
// phrase (matches the canonicalization already applied on the barcode branch).
$check(DiscogsPlugin::buildMusicBrainzQuery('  SRX-6272  ') === 'catno:"SRX-6272"',       'catno padded whitespace is trimmed');
// UPC-A with grouping spaces ("0777 7836 4627") routes to barcode and gets
// canonicalized to digits-only.
$check(DiscogsPlugin::buildMusicBrainzQuery('0777 7836 4627') === 'barcode:077778364627', 'UPC-A spaced → barcode digits-only');
// CodeRabbit round 4: unknown identifiers must not produce malformed
// `barcode:abc` queries. buildMusicBrainzQuery returns '' and searchMusicBrainz
// short-circuits before issuing the HTTP request.
$check(DiscogsPlugin::buildMusicBrainzQuery('nonsense!!!') === '',                         'unknown → empty string (no malformed query)');
$check(DiscogsPlugin::buildMusicBrainzQuery('')            === '',                         'empty input → empty string');

echo "\ncanonicalSearchIdentifier — normalization before persisting fallback:\n";
// CodeRabbit regression: 5099902-988023 was accepted as barcode but the
// raw hyphenated form flowed through fallbackBarcode into mapReleaseToPinakes,
// persisting a non-canonical value in the `ean` column.
$check(DiscogsPlugin::canonicalSearchIdentifier('5099902-988023', 'barcode') === '5099902988023', 'hyphenated EAN → digits-only');
$check(DiscogsPlugin::canonicalSearchIdentifier('5099902988023',  'barcode') === '5099902988023', 'clean EAN unchanged');
$check(DiscogsPlugin::canonicalSearchIdentifier(' 077778364627 ', 'barcode') === '077778364627',  'UPC-A padded with whitespace → trimmed');
$check(DiscogsPlugin::canonicalSearchIdentifier('CDP 7912682',    'catno')   === 'CDP 7912682',   'Cat# kept intact (spaces preserved)');
$check(DiscogsPlugin::canonicalSearchIdentifier('  SRX-6272  ',   'catno')   === 'SRX-6272',      'Cat# trimmed but not stripped');

echo "\n================================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed > 0 ? 1 : 0);

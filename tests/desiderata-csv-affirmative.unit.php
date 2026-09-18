<?php
declare(strict_types=1);

/**
 * A librarian who types SÌ in the is_desiderata column of a CSV must get a
 * request, not a holding.
 *
 * The parser matched its accepted values with byte-wise strtolower(), which
 * folds only ASCII A-Z. The accepted list carries the accented Italian
 * affirmative in lower case, so "sì" matched and "SÌ" became "sÌ" and matched
 * nothing — and an unmatched value is simply false, so the row imported as a
 * book the library owns, with no warning anywhere. The lower-case spelling
 * working is what made this hard to notice: the column behaves correctly right
 * up until someone uses the shift key.
 *
 * The suite drives the REAL parseCsvRow(), because the defect lives in one
 * expression inside it and a test that re-implemented the comparison would
 * agree with whichever version of the bug it was written against.
 *
 * No database: parseCsvRow() is pure row-to-row normalisation.
 *
 * Run:  php tests/desiderata-csv-affirmative.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (!function_exists('__')) {
    function __(string $text, mixed ...$args): string
    {
        return $args ? vsprintf($text, $args) : $text;
    }
}

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$parse = new ReflectionMethod(\App\Controllers\CsvImportController::class, 'parseCsvRow');
$parse->setAccessible(true);
$controller = (new ReflectionClass(\App\Controllers\CsvImportController::class))->newInstanceWithoutConstructor();

/** What the importer decides for a given cell value. */
$wanted = static function (string $cell) use ($parse, $controller): bool {
    $row = $parse->invoke($controller, ['titolo' => 'Probe', 'is_desiderata' => $cell]);

    return (bool) ($row['is_desiderata'] ?? false);
};

echo "A. Every spelling of yes a cataloguer might actually type\n";

$truthy = [
    'si', 'Si', 'SI', 'sI',
    'sì', 'Sì', 'SÌ', 'sÌ',
    '1', 'true', 'TRUE', 'True',
    'yes', 'YES', 'Yes',
    'y', 'Y',
    ' si ', "\tSÌ\n",      // the importer trims before deciding
    // Every language mapColumnHeaders() claims to read. A file headed
    // "wunschbuch" or "recherché" carries "Ja" or "Oui" in the cells, and
    // accepting the header while rejecting its own values imports the whole
    // request list as owned holdings. German and Danish share "ja"; the
    // Spanish affirmative takes an acute accent, the Italian one a grave.
    'sí', 'SÍ',
    'oui', 'OUI', 'Oui',
    'ja', 'JA', 'Ja',
];
foreach ($truthy as $cell) {
    $check($wanted($cell), 'a request for ' . var_export($cell, true));
}

echo "\nB. And everything that must stay a holding\n";

$falsy = [
    '', ' ', '0', 'no', 'NO', 'No', 'false', 'FALSE', 'n', 'N',
    'forse',                // not an affirmative in any casing
    'siamo',                // begins with the affirmative, is not one
    'nein', 'non', 'nej',   // the negatives of the languages accepted above
    'jamais',               // begins with the French-adjacent 'ja', is not one
];
foreach ($falsy as $cell) {
    $check(!$wanted($cell), 'a holding for ' . var_export($cell, true));
}

echo "\nC. The column is optional and its absence means a holding\n";

$row = $parse->invoke($controller, ['titolo' => 'Probe']);
$check(($row['is_desiderata'] ?? null) === false, 'a row with no is_desiderata column at all imports as a holding');

echo "\nD. The header a cataloguer types is matched case-insensitively in every language\n";

// The affirmative above only ever gets consulted if the COLUMN was recognised
// first. strtolower() folds ASCII only, so an all-caps accented header — what
// several library exports write — used to match nothing and be dropped along
// with its values, which is indistinguishable from an import that simply had
// no such column. Both halves of the comparison must fold multibyte.
$map = new ReflectionMethod(\App\Controllers\CsvImportController::class, 'mapColumnHeaders');
$map->setAccessible(true);

$headerCases = [
    'RECHERCHÉ'       => 'is_desiderata',
    'Recherché'       => 'is_desiderata',
    'ØNSKET'          => 'is_desiderata',
    'WUNSCHBUCH'      => 'is_desiderata',
    // Not desiderata columns, but the same fold governs them, and a regression
    // here silently drops real bibliographic data rather than a flag.
    'TÍTULO'          => 'titolo',
    'AÑO'             => 'anno_pubblicazione',
    'NUMÉRO DE SÉRIE' => 'numero_serie',
    'MOTS-CLÉS'       => 'parole_chiave',
    'SCHLAGWÖRTER'    => 'parole_chiave',
    'ÜBERSETZER'      => 'traduttore',
    'EDICIÓN'         => 'edizione',
];
$mapped = $map->invoke($controller, array_keys($headerCases));
$i = 0;
foreach ($headerCases as $header => $canonical) {
    $check(
        ($mapped[$i] ?? null) === $canonical,
        var_export($header, true) . ' maps to ' . $canonical . ' (got ' . var_export($mapped[$i] ?? null, true) . ')'
    );
    $i++;
}

// An unknown header must still pass through untouched, not collapse onto a
// neighbour because the fold got looser.
$unknown = $map->invoke($controller, ['COLONNA IGNOTA À MOI']);
$check(($unknown[0] ?? null) === 'COLONNA IGNOTA À MOI', 'an unrecognised header is preserved verbatim');

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);

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
];
foreach ($truthy as $cell) {
    $check($wanted($cell), 'a request for ' . var_export($cell, true));
}

echo "\nB. And everything that must stay a holding\n";

$falsy = [
    '', ' ', '0', 'no', 'NO', 'No', 'false', 'FALSE', 'n', 'N',
    'forse',                // not an affirmative in any casing
    'siamo',                // begins with the affirmative, is not one
    'oui',                  // affirmative, but not in the accepted list
];
foreach ($falsy as $cell) {
    $check(!$wanted($cell), 'a holding for ' . var_export($cell, true));
}

echo "\nC. The column is optional and its absence means a holding\n";

$row = $parse->invoke($controller, ['titolo' => 'Probe']);
$check(($row['is_desiderata'] ?? null) === false, 'a row with no is_desiderata column at all imports as a holding');

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);

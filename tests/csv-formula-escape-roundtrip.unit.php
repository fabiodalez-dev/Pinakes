<?php
declare(strict_types=1);

/**
 * CSV formula escape: every export -> import round-trip is byte-stable.
 *
 * The exports write their cells through App\Support\Csv with the "'" formula
 * prefix (league/csv EscapeFormula). Since league/csv 9.29 that prefix is also
 * put in front of a cell that already starts with "'": "'Twas the night" is
 * exported as "''Twas the night". The three importers that undo the prefix
 * (books CSV, LibraryThing TSV, patrons) each kept their own copy of the old
 * rule, which only knew = + - @ (and tab/CR), so the title came back with an
 * extra apostrophe on every round-trip.
 *
 * For each importer this suite writes the values through the real export
 * writer, reads them back with the real reader, runs the importer's own
 * unescape step and expects the original value. A value that was never
 * exported and starts with "'" ('Twas, typed in a spreadsheet) is kept as is.
 *
 * Discriminating: on the pre-fix importers the "'"-leading values fail (and,
 * for patrons, the tab-leading one too).
 *
 * Run: php tests/csv-formula-escape-roundtrip.unit.php
 */

use App\Controllers\CsvImportController;
use App\Controllers\LibraryThingImportController;
use App\Support\Csv;
use App\Support\UserImport;

require dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "  OK  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL {$label}\n";
    }
};

// Values a library really holds, plus every formula character the writer escapes.
$values = [
    "'Twas the night before Christmas",
    "'Salem's Lot",
    "''Two apostrophes",
    '=SUM(A1)',
    '+39 02 1234567',
    '-Anon',
    '@note',
    "\tTab first",
    'Plain title',
    "O'Brien, Flann",
];

/** The cells as the real export writes them and the real reader reads them back. */
$roundTrip = static function (string $delimiter) use ($values): array {
    $writer = Csv::writerToString($delimiter, "'");
    $writer->insertOne($values);
    $rows = iterator_to_array(Csv::readerFromString($writer->toString(), $delimiter)->getRecords(), false);
    return $rows[0] ?? [];
};

$importers = [
    'books CSV' => [
        ';',
        static function (string $v): string {
            $m = new ReflectionMethod(CsvImportController::class, 'unescapeCsvFormula');
            return $m->invoke((new ReflectionClass(CsvImportController::class))->newInstanceWithoutConstructor(), $v);
        },
    ],
    'LibraryThing TSV' => [
        "\t",
        static fn (string $v): string => (new ReflectionMethod(LibraryThingImportController::class, 'unescapeFormula'))->invoke(null, $v),
    ],
    'patrons CSV' => [
        ';',
        static fn (string $v): string => (new ReflectionMethod(UserImport::class, 'cell'))->invoke(null, $v),
    ],
];

$exported = $roundTrip(';');
$check(($exported[0] ?? null) === "''Twas the night before Christmas", "precondition: the export writer prefixes a leading apostrophe (got '" . ($exported[0] ?? '') . "')");

foreach ($importers as $name => [$delimiter, $unescape]) {
    echo "\n{$name}\n";
    $cells = $roundTrip($delimiter);
    foreach ($values as $i => $original) {
        // The patron importer trims every cell: compare with what it keeps.
        $expected = $name === 'patrons CSV' ? trim($original) : $original;
        $got = $unescape((string) ($cells[$i] ?? ''));
        $check($got === $expected, sprintf('export -> import keeps %s (got %s)', json_encode($expected), json_encode($got)));
    }
    $raw = $unescape("'Twas the night");
    $check($raw === "'Twas the night", "a never-exported leading apostrophe is kept (got " . json_encode($raw) . ')');
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

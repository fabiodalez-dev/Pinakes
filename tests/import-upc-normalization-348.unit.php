<?php

declare(strict_types=1);

/**
 * Issue #348 — CSV/TSV import must accept UPC-A barcodes (board games etc.).
 *
 * Both the CSV and the TSV import go through the SAME CsvImportController path
 * (the delimiter is auto-detected), and both normalise the barcode column with
 * CsvImportController::normalizeEan(). This guards that method's UPC handling:
 * a valid 12-digit UPC-A is canonicalised to its 13-digit GTIN (a leading zero,
 * which preserves the check digit), while EAN-13 and invalid input keep their
 * existing behaviour.
 *
 * normalizeEan() is private and touches no instance state, so it is exercised
 * via reflection on an instance built without the constructor.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Controllers\CsvImportController;

$controller = (new ReflectionClass(CsvImportController::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(CsvImportController::class, 'normalizeEan');
$method->setAccessible(true);

/** @return string|null */
$normalize = static fn (string $ean) => $method->invoke($controller, $ean);

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

// A valid UPC-A (Coca-Cola sample checksum) → canonical GTIN-13 "0" + upc.
$check($normalize('036000291452') === '0036000291452', 'valid UPC-A is canonicalised to its 13-digit GTIN');

// The same UPC with separators the scanner/label may include.
$check($normalize('0 36000 29145 2') === '0036000291452', 'UPC-A with separators normalises to the same GTIN');

// A UPC-A whose 13-digit GTIN form fails the checksum is rejected.
$check($normalize('036000291453') === null, 'UPC-A with a bad check digit is rejected');

// EAN-13 keeps working unchanged.
$check($normalize('9788804763178') === '9788804763178', 'valid EAN-13 is returned unchanged');

// A UPC-A and its EAN-13 GTIN form collapse to one value (dedup coherence).
$check(
    $normalize('036000291452') === $normalize('0036000291452'),
    'UPC-A and its zero-padded EAN-13 form normalise identically'
);

// Wrong lengths are rejected (11 and 14 digits).
$check($normalize('01234567890') === null, '11-digit input is rejected');
$check($normalize('01234567890123') === null, '14-digit input is rejected');

// Empty / non-numeric input is rejected.
$check($normalize('') === null, 'empty input is rejected');
$check($normalize('not-a-barcode') === null, 'non-numeric input is rejected');

// A stray letter must not be silently stripped into a valid barcode: only
// spaces and dashes are separators, anything else invalidates the cell.
$check($normalize('ABC036000291452') === null, 'letters around a valid UPC-A do not make it valid');
$check($normalize("978-88-04-76317-8") === '9788804763178', 'a dash-separated EAN-13 is accepted');

echo PHP_EOL . "Passed: {$passed}, Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

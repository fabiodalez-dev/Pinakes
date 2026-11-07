<?php
declare(strict_types=1);

/** Identifier fallbacks must respect ISBN/EAN types, even when the API omits them. No network or DB. */
require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/storage/plugins/api-book-scraper/ApiBookScraperPlugin.php';

use App\Support\IsbnFormatter;

$checks = 0;
$failures = 0;
$check = static function (bool $ok, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$ok) { $failures++; }
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
};
$plugin = new ApiBookScraperPlugin();
$mapper = new ReflectionMethod($plugin, 'mapApiResponse');
$map = static fn(string $input, array $data = []): array => $mapper->invoke($plugin, ['title' => 'Fixture'] + $data, $input);

foreach (['0306406152', '0-306-40615-2', '9780306406157', '978-0-306-40615-7'] as $input) {
    $book = $map($input);
    $check(($book['isbn10'] ?? null) === '0306406152', "$input supplies the canonical ISBN-10");
    $check(($book['isbn13'] ?? null) === '9780306406157', "$input supplies the equivalent ISBN-13");
    $check(($book['ean'] ?? null) === '9780306406157', "$input supplies a 13-digit EAN, never an ISBN-10");
}
$book = $map('9791090636071');
$check(($book['isbn13'] ?? null) === '9791090636071' && IsbnFormatter::isValidIsbn13($book['isbn13']), '979 ISBN stays an ISBN-13');
$check(!isset($book['isbn10']), '979 ISBN has no invented ISBN-10');
$check(($book['ean'] ?? null) === '9791090636071', '979 ISBN supplies its EAN');

foreach (['0720642442524', '720642442524'] as $barcode) {
    $book = $map($barcode);
    $check(!isset($book['isbn10']) && !isset($book['isbn13']), "$barcode is not an ISBN");
    $check(($book['ean'] ?? null) === $barcode, "$barcode remains a barcode");
}
foreach (['0306406153', '9780306406158', '0720642442525', 'CDP 7912682', 'prefix9780306406157', '9780306406157suffix'] as $input) {
    $book = $map($input);
    $check(!isset($book['isbn10']) && !isset($book['isbn13']) && !isset($book['ean']), "$input does not fabricate identifiers");
}
$book = $map('0306406152', ['isbn13' => '9780140328721', 'isbn10' => '0140328726', 'ean' => '9780140328721']);
$check($book['isbn13'] === '9780140328721' && $book['isbn10'] === '0140328726' && $book['ean'] === '9780140328721', 'explicit provider identifiers take precedence over request fallbacks');

$book = $map('0306406152', ['isbn13' => '', 'isbn10' => ' ', 'ean' => '']);
$check($book['isbn13'] === '9780306406157' && $book['isbn10'] === '0306406152' && $book['ean'] === '9780306406157', 'blank provider identifiers fall back to the derived ones');

$book = $map('0306406152', ['isbn_13' => '978-0-140-32872-1', 'ean' => '0306406152']);
$check($book['isbn13'] === '9780140328721' && $book['isbn10'] === '0140328726' && $book['ean'] === '9780140328721', 'a provider edition supplies a coherent ISBN pair and replaces an invalid EAN');
$book = $map('0720642442524', ['isbn13' => '0720642442524', 'isbn10' => ['invalid'], 'ean' => '0720642442525']);
$check(!isset($book['isbn13']) && !isset($book['isbn10']) && $book['ean'] === '0720642442524', 'malformed provider identifiers fall back to the valid lookup barcode');

foreach (['720642442524', '0720642442524', '0777 7836 4627'] as $barcode) {
    $check($plugin->validateIsbn(false, $barcode), "$barcode is accepted for the API as a checksum-valid barcode");
}
foreach (['720642442525', 'CDP 7912682', '12345', '0306406153'] as $input) {
    $check(!$plugin->validateIsbn(false, $input), "$input stays rejected");
}
$check($plugin->validateIsbn(true, '9780306406157'), 'a core-valid ISBN stays valid');

// Exercise the controller normalizer too: previously it retained the wrong,
// already-populated isbn13 rather than replacing the ISBN-10 in that slot.
$normalizer = new ReflectionMethod(App\Controllers\ScrapeController::class, 'normalizeIsbnFields');
$book = $normalizer->invoke(new App\Controllers\ScrapeController(), $map('0306406152'), '0306406152');
$check($book['isbn13'] === '9780306406157' && IsbnFormatter::isValidIsbn13($book['isbn13']), 'the mapped ISBN-10 also survives controller finalization with valid identifiers');

echo "$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);

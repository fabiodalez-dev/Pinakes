<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

$pass = 0;
$fail = 0;
$check = static function (bool $condition, string $label) use (&$pass, &$fail): void {
    if ($condition) {
        $pass++;
        echo "  OK  {$label}\n";
        return;
    }
    $fail++;
    echo "  FAIL {$label}\n";
};

$newWithoutConstructor = static function (string $class): object {
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
};

echo "A. Standard CSV role columns\n";
$csv = $newWithoutConstructor(\App\Controllers\CsvImportController::class);
$csvReflection = new ReflectionClass($csv);
$mapHeaders = $csvReflection->getMethod('mapColumnHeaders');
$parseRow = $csvReflection->getMethod('parseCsvRow');
$mapped = $mapHeaders->invoke($csv, ['autori', 'co-authors', 'colorist']);
$parsed = $parseRow->invoke($csv, array_combine($mapped, ['Primary Person', 'Co Person', 'Color Person']));
$check($mapped === ['autori', 'co_autori', 'colorista'], 'header aliases map to distinct canonical roles');
$check(($parsed['autori'] ?? null) === 'Primary Person', 'principal creators remain in autori');
$check(($parsed['co_autori'] ?? null) === 'Co Person', 'co-authors remain separate');
$check(($parsed['colorista'] ?? null) === 'Color Person', 'colorist value is parsed');
$check(($parsed['co_autori_provided'] ?? false) === true && ($parsed['colorista_provided'] ?? false) === true, 'present role columns are authoritative');
$legacyParsed = $parseRow->invoke($csv, ['autori' => 'Legacy Primary']);
$check(($legacyParsed['co_autori_provided'] ?? true) === false && ($legacyParsed['colorista_provided'] ?? true) === false, 'absent legacy columns do not clear roles');

$example = $csvReflection->getMethod('generateExampleCsv')->invoke($csv);
$exampleLines = preg_split('/\R/', trim((string) $example)) ?: [];
$exampleCounts = array_map(
    static fn(string $line): int => count(str_getcsv(ltrim($line, "\xEF\xBB\xBF"), ';', '"', '')),
    $exampleLines
);
$check(count(array_unique($exampleCounts)) === 1 && ($exampleCounts[0] ?? 0) === 26, 'example CSV rows match the 26-column header');

echo "B. LibraryThing role encoding\n";
$book = [
    'titolo' => 'Role roundtrip',
    'autori_nomi' => 'Primary Person;Second Principal',
    'coautori_nomi' => 'Co Person',
    'traduttori_nomi' => 'Translator Person',
    'illustratori_nomi' => 'Illustrator Person',
    'curatori_nomi' => 'Editor Person',
    'coloristi_nomi' => 'Color Person',
];
$expectedSecondary = 'Second Principal; Co Person; Translator Person; Illustrator Person; Editor Person; Color Person';
$expectedRoles = '; Co-author; Translator; Illustrator; Editor; Colorist';

$booksController = $newWithoutConstructor(\App\Controllers\LibriController::class);
$booksFormatter = (new ReflectionClass($booksController))->getMethod('formatLibraryThingRow');
$booksRow = $booksFormatter->invoke($booksController, $book, '2026');
$check(($booksRow[3] ?? null) === 'Primary Person', 'standard export keeps the primary creator');
$check(($booksRow[5] ?? null) === $expectedSecondary, 'standard LibraryThing mode exports every secondary contributor');
$check(($booksRow[6] ?? null) === $expectedRoles, 'standard LibraryThing mode exports aligned roles');

$libraryThing = $newWithoutConstructor(\App\Controllers\LibraryThingImportController::class);
$libraryThingReflection = new ReflectionClass($libraryThing);
$libraryThingFormatter = $libraryThingReflection->getMethod('formatLibraryThingRow');
$libraryThingRow = $libraryThingFormatter->invoke($libraryThing, $book);
$check(($libraryThingRow[5] ?? null) === $expectedSecondary, 'dedicated LibraryThing export keeps every contributor');
$check(($libraryThingRow[6] ?? null) === $expectedRoles, 'dedicated LibraryThing export keeps aligned roles');

$classify = $libraryThingReflection->getMethod('classifySecondaryAuthors');
$classified = $classify->invoke($libraryThing, $expectedSecondary, $expectedRoles);
$check(($classified['authors'] ?? []) === ['Second Principal'], 'unqualified secondary creator remains principal');
$check(($classified['coauthors'] ?? []) === ['Co Person'], 'co-author role decodes independently');
$check(($classified['translators'] ?? []) === ['Translator Person'], 'translator role decodes independently');
$check(($classified['illustrators'] ?? []) === ['Illustrator Person'], 'illustrator role decodes independently');
$check(($classified['curators'] ?? []) === ['Editor Person'], 'editor role decodes as curator');
$check(($classified['colorists'] ?? []) === ['Color Person'], 'colorist role decodes independently');

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);

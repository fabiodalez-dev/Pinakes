<?php
/**
 * #463 — the back-office quick search lists books and articles as ONE list:
 * best title match first, then alphabetical in the operator's language;
 * authors and publishers follow. Data from the issue's screenshot.
 *
 * Run: php tests/quick-search-order-463.unit.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Support\QuickSearchOrder;

$pass = 0; $fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};
$labels = static fn(array $r): array => array_map(static fn($i) => $i['type'] . ':' . $i['label'], $r);

$q = 'For arbejderbevægelsens politiske historie';
$input = [
    ['type' => 'book', 'label' => 'Arbejderhistorie. 2016, nr. 2'],
    ['type' => 'book', 'label' => 'Det røde spøgelse : kommunismen og 1840\'ernes danske politiske kultur'],
    ['type' => 'book', 'label' => 'Den demokratiske socialismes gennembrudsår'],
    ['type' => 'author', 'label' => 'Bryld, Claus'],
    ['type' => 'publisher', 'label' => 'Selskabet til forskning i arbejderbevægelsens historie'],
    ['type' => 'article', 'label' => 'For arbejderbevægelsens politiske historie'],
];
$out = QuickSearchOrder::apply($input, $q, 'da_DK');
$check($out[0]['label'] === $q && $out[0]['type'] === 'article', 'the exact title (an article) comes first');
$check($labels(array_slice($out, 1, 3)) === [
    'book:Arbejderhistorie. 2016, nr. 2',
    'book:Den demokratiske socialismes gennembrudsår',
    "book:Det røde spøgelse : kommunismen og 1840'ernes danske politiske kultur",
], 'the other records follow alphabetically, books and articles mixed');
$check($labels(array_slice($out, 4)) === ['author:Bryld, Claus', 'publisher:Selskabet til forskning i arbejderbevægelsens historie'], 'authors and publishers follow, order kept');
$check(count($out) === count($input), 'nothing is dropped');

// No title match: pure alphabetical across books and articles.
$mixed = [
    ['type' => 'book', 'label' => 'Øresund'],
    ['type' => 'book', 'label' => 'Bog 10'],
    ['type' => 'article', 'label' => 'Aarhus'],
    ['type' => 'article', 'label' => 'Bog 2'],
    ['type' => 'periodical', 'label' => 'Zeitschrift'],
];
$out = QuickSearchOrder::apply($mixed, 'xyz', 'da_DK');
$check(array_column($out, 'label') === ['Bog 2', 'Bog 10', 'Zeitschrift', 'Øresund', 'Aarhus'], 'Danish collation: Ø and Aa after Z, numbers in numeric order');
$out = QuickSearchOrder::apply($mixed, 'xyz', 'it_IT');
$check(array_column($out, 'label') === ['Aarhus', 'Bog 2', 'Bog 10', 'Øresund', 'Zeitschrift'], 'Italian collation: Ø sorts with O');

// Prefix beats contains; case and spacing ignored.
$out = QuickSearchOrder::apply([
    ['type' => 'book', 'label' => 'Storia della Resistenza'],
    ['type' => 'article', 'label' => 'Una storia  breve'],
    ['type' => 'book', 'label' => 'Atlante'],
    ['type' => 'article', 'label' => 'storia BREVE'],
], 'Storia breve', 'it_IT');
$check(array_column($out, 'label') === ['storia BREVE', 'Una storia  breve', 'Atlante', 'Storia della Resistenza'], 'exact (case-insensitive), then contains, then the rest alphabetically');

// Non-record or malformed items pass through untouched.
$out = QuickSearchOrder::apply(['x', ['type' => 'book', 'label' => 'B'], ['type' => 'book', 'label' => 'A']], 'q', 'it_IT');
$check($out === [['type' => 'book', 'label' => 'A'], ['type' => 'book', 'label' => 'B'], 'x'], 'a malformed entry is kept, after the records');

// The cut keeps the matching authors/publishers: records give way first.
$many = [];
for ($i = 0; $i < 25; $i++) { $many[] = ['type' => $i % 2 ? 'article' : 'book', 'label' => sprintf('T%02d', $i)]; }
$many[] = ['type' => 'author', 'label' => 'Author A'];
$many[] = ['type' => 'publisher', 'label' => 'Publisher P'];
$out = QuickSearchOrder::apply($many, 'zzz', 'it_IT', 20);
$check(count($out) === 20, 'the list is cut at the limit');
$check(array_column(array_slice($out, -2), 'type') === ['author', 'publisher'], 'the author and publisher survive the cut');
$check($out[0]['label'] === 'T00' && $out[17]['label'] === 'T17', 'the records kept are the first ones in order');
$tenOthers = array_fill(0, 15, ['type' => 'author', 'label' => 'A']);
$out = QuickSearchOrder::apply(array_merge($many, $tenOthers), 'zzz', 'it_IT', 20);
$check(count(array_filter($out, static fn($r) => $r['type'] !== 'author' && $r['type'] !== 'publisher')) === 10, 'at least half the slots stay records');

echo "\nPassed: {$pass}   Failed: {$fail}\n";
exit($fail === 0 ? 0 : 1);

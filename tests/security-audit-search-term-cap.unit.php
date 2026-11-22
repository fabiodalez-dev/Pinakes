<?php
declare(strict_types=1);

/**
 * Security audit 2026-11 — catalogue search term budget.
 *
 * SearchIndexBuilder turned every token of an anonymous `q` into its own
 * predicate (an unindexed LIKE '%x%' for short tokens) and relevance block,
 * with no cap and no de-duplication; the catalogue then re-ran the list in the
 * count, page and six facet queries. One 8 KB URL of "e e e ..." became 4000
 * LIKEs per statement. The builder now de-duplicates and caps the terms at
 * ExactSearch::MAX_FILTER_TERMS for every caller, buildRelevanceOrder() caps by
 * default, and the controllers cut `q` to 200 characters (mobile: 256 chars and
 * 12 relevance words).
 *
 * DB-free: the builder's schema probes are pre-seeded through reflection and an
 * unconnected mysqli is passed, so only SQL text is built.
 *
 * Run:  php tests/security-audit-search-term-cap.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\ExactSearch;
use App\Support\SearchIndexBuilder;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$setStatic = static function (string $class, string $prop, mixed $value): void {
    $p = new ReflectionProperty($class, $prop);
    $p->setAccessible(true);
    $p->setValue(null, $value);
};
// search_index exists, no descrizione_plain, libri_editori present: no DB round trip.
$setStatic(SearchIndexBuilder::class, 'columnExists', true);
$setStatic(SearchIndexBuilder::class, 'hasDescrizionePlain', false);
$setStatic(\App\Support\SchemaInfo::class, 'tableCache', ['libri_editori' => true]);
$db = mysqli_init();

$cap = ExactSearch::MAX_FILTER_TERMS;
$likeCount = static fn (array $cond): int => substr_count($cond['sql'], 'LIKE ?');
$matchTerms = static function (array $cond): int {
    if (!str_contains($cond['sql'], 'MATCH(')) {
        return 0;
    }
    return count(preg_split('/\s+/', trim((string) $cond['params'][0])) ?: []);
};
$blocks = static fn (array $order): int => substr_count($order['score_sql'], 'THEN 120');

echo "A. buildSearchCondition: repeated short tokens\n";
$q = trim(str_repeat('e ', 4000));
$cond = SearchIndexBuilder::buildSearchCondition($db, 'l.search_index', $q);
$check(is_array($cond) && $likeCount($cond) === 1, "4000 x 'e' become ONE LIKE (got " . (is_array($cond) ? $likeCount($cond) : 'null') . ')');
$check(is_array($cond) && count($cond['params']) === 1 && strlen($cond['types']) === 1, 'one bound parameter');

echo "B. buildSearchCondition: thousands of distinct tokens\n";
$short = implode(' ', array_map(static fn (int $i): string => chr(97 + intdiv($i, 26) % 26) . chr(97 + $i % 26), range(0, 675)));
$cond = SearchIndexBuilder::buildSearchCondition($db, 'l.search_index', $short);
$check(is_array($cond) && $likeCount($cond) <= $cap, "676 distinct 2-letter tokens give at most {$cap} LIKEs (got " . (is_array($cond) ? $likeCount($cond) : 'null') . ')');
$long = implode(' ', array_map(static fn (int $i): string => 'word' . $i, range(1, 3000)));
$cond = SearchIndexBuilder::buildSearchCondition($db, 'l.search_index', $long);
$check(is_array($cond) && $matchTerms($cond) <= $cap, "3000 distinct FULLTEXT words give at most {$cap} MATCH terms (got " . (is_array($cond) ? $matchTerms($cond) : 'null') . ')');
$check(is_array($cond) && count($cond['params']) <= $cap + 1, 'bound parameters stay within the budget');
$mixed = trim(str_repeat('"a b" ', 500) . str_repeat('x ', 500));
$cond = SearchIndexBuilder::buildSearchCondition($db, 'l.search_index', $mixed);
$check(is_array($cond) && count($cond['params']) <= 3, 'repeated quoted phrases and words are de-duplicated');

echo "C. Normal queries are unchanged\n";
$cond = SearchIndexBuilder::buildSearchCondition($db, 'l.search_index', 'nome rosa xy');
$check(is_array($cond) && $matchTerms($cond) === 2 && $likeCount($cond) === 1,
    "'nome rosa xy' keeps MATCH for nome/rosa and one LIKE for 'xy' (sql: " . (is_array($cond) ? $cond['sql'] : 'null') . ')');
$cond = SearchIndexBuilder::buildSearchCondition($db, 'l.search_index', 'Eco Eco eco');
$check(is_array($cond) && $matchTerms($cond) === 1, 'case-only repeats count once');

echo "D. buildRelevanceOrder caps by default\n";
$order = SearchIndexBuilder::buildRelevanceOrder($db, $long, 'l.');
$check($blocks($order) <= $cap, "no maxWords: at most {$cap} score blocks (got {$blocks($order)})");
$order = SearchIndexBuilder::buildRelevanceOrder($db, $long, 'l.', 12);
$check($blocks($order) === 12, 'an explicit lower cap still applies (12)');
$order = SearchIndexBuilder::buildRelevanceOrder($db, $long, 'l.', 500);
$check($blocks($order) <= $cap, "a caller cannot raise the cap above {$cap}");
$order = SearchIndexBuilder::buildRelevanceOrder($db, 'a a', 'l.');
$check($blocks($order) === 1, "'a a' produces one score block, not two");
$order = SearchIndexBuilder::buildRelevanceOrder($db, 'nome rosa', 'l.');
$check($blocks($order) === 2, 'two distinct words still produce two score blocks');

echo "E. Controllers cut the raw query\n";
$frontend = (string) file_get_contents($root . '/app/Controllers/FrontendController.php');
$check(preg_match("/rawSearch = \\\$params\\['q'\\] \\?\\? \\\$params\\['search'\\] \\?\\? '';\\s*\\n\\s*\\\$searchTerm = is_scalar\\(\\\$rawSearch\\) \\? trim\\(mb_substr\\(trim\\(\\(string\\) \\\$rawSearch\\), 0, 200/", $frontend) === 1,
    'FrontendController::getFilters cuts q/search to 200 characters');
$search = (string) file_get_contents($root . '/app/Controllers/SearchController.php');
$check(!str_contains($search, "trim((string)(\$request->getQueryParams()['q'] ?? ''))")
    && substr_count($search, 'self::searchQuery($request)') >= 8
    && str_contains($search, 'private const MAX_QUERY_CHARS = 200;'),
    'every SearchController handler reads q through the 200-character helper');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

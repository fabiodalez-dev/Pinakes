<?php
declare(strict_types=1);

/**
 * Issue #501: the utf8mb4_unicode_ci collation folds accents, so a search for
 * `haffner` also returns `Häffner`. That tolerance stays the default; a quoted
 * term opts into the exact spelling (accents distinct, case still ignored) and
 * a quoted group with spaces into an exact phrase.
 *
 * Covers the tokenizer, the FULLTEXT book search (buildSearchCondition), the
 * single-value admin filters (filterCondition), the multi-word autocomplete
 * (wordsCondition), and that the relevance ORDER BY and the pre-migration
 * fallback still produce valid SQL for quoted terms.
 */

use App\Support\ExactSearch;
use App\Support\SearchIndexBuilder;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$TESTNO = 0;
function pass(string $desc): void
{
    global $TESTNO;
    $TESTNO++;
    printf("[%02d] PASS: %s\n", $TESTNO, $desc);
}
function check(bool $cond, string $desc): void
{
    if (!$cond) {
        throw new \RuntimeException("assertion failed: {$desc}");
    }
    pass($desc);
}

// ── Tokenizer (no database) ────────────────────────────────────────────────
check(ExactSearch::tokenize('haffner') === [['term' => 'haffner', 'exact' => false]], 'plain word is tolerant');
check(ExactSearch::tokenize('"haffner"') === [['term' => 'haffner', 'exact' => true]], 'quoted word is exact');
check(ExactSearch::tokenize('"haffner') === [['term' => 'haffner', 'exact' => true]], 'unclosed quote is treated as closed');
check(ExactSearch::tokenize('„Häffner“') === [['term' => 'Häffner', 'exact' => true]], 'German typographic quotes are recognised');
check(
    ExactSearch::tokenize('roman "thomas   mann" 1929') === [
        ['term' => 'roman', 'exact' => false],
        ['term' => 'thomas mann', 'exact' => true],
        ['term' => '1929', 'exact' => false],
    ],
    'phrase in the middle keeps the surrounding words tolerant and collapses spaces'
);
check(ExactSearch::tokenize('""') === [] && ExactSearch::tokenize('"  "') === [], 'empty quotes yield no term');
// An inch mark after a digit is a literal character, not the start of a group.
check(ExactSearch::tokenize('5" floppy') === [['term' => '5"', 'exact' => false], ['term' => 'floppy', 'exact' => false]], 'inch mark after a digit stays literal');
check(ExactSearch::tokenize('24” monitor "Häffner"') === [['term' => '24"', 'exact' => false], ['term' => 'monitor', 'exact' => false], ['term' => 'Häffner', 'exact' => true]], 'typographic inch mark is literal and a later group still opens');
check(ExactSearch::tokenize('"1984" orwell') === [['term' => '1984', 'exact' => true], ['term' => 'orwell', 'exact' => false]], 'a quote after a digit still closes an open group');
check(ExactSearch::parseFilter('5" floppy') === ['term' => '5" floppy', 'exact' => false], 'a value with only an inch mark is not quoted');
check(ExactSearch::quotedValue('10"') === null, 'an inch mark is never a quoted name');
// Quotes around a whole name are never stored (create, update, contributor text columns).
check(\App\Support\AuthorNormalizer::normalizeStored('"Rolf Häffner"') === 'Rolf Häffner', 'stored contributor name drops whole-name quotes');
check(\App\Support\AuthorNormalizer::normalizeStored('"Häffner, Rolf"') === 'Rolf Häffner', 'stored quoted inverted name is normalised without quotes');
check(\App\Support\AuthorNormalizer::normalizeStored('"Pippo" Inzaghi') === '"Pippo" Inzaghi', 'a name with only a quoted part is kept as typed');
check(\App\Support\AuthorNormalizer::normalizeStored('Rossi, Mario; Bianchi, Luca') === 'Mario Rossi; Luca Bianchi', 'a stored list normalises each inverted name on its own');
check(\App\Support\AuthorNormalizer::normalizeStored('"Rolf Häffner" | Mario Rossi; Mario Rossi') === 'Rolf Häffner; Mario Rossi', 'a stored list drops each name\'s quotes, joins with "; " and keeps no duplicate');
$lt = (new \ReflectionClass(\App\Controllers\LibraryThingImportController::class))->newInstanceWithoutConstructor();
$ltCache = new \ReflectionMethod($lt, 'normalizeContributorField');
check($ltCache->invoke($lt, '"Hüffner, Rolf"; Mario Rossi') === 'Rolf Hüffner; Mario Rossi', 'LibraryThing cache column: a quoted inverted translator is stored canonical and without quotes');
check($ltCache->invoke($lt, '"Rolf Hüffner" | "Rolf Hüffner"') === 'Rolf Hüffner', 'LibraryThing cache column: the quoted duplicate collapses with the plain one');
check(ExactSearch::parseFilter('"Haffner"') === ['term' => 'Haffner', 'exact' => true], 'filter value in quotes is exact');
check(ExactSearch::parseFilter('van der Berg') === ['term' => 'van der Berg', 'exact' => false], 'filter value without quotes stays one tolerant term');
check(ExactSearch::filterCondition(['a.nome'], '"') === null, 'a lone quote adds no filter');

// ── Database ───────────────────────────────────────────────────────────────
function eqsLoadEnv(string $path): array
{
    $env = [];
    foreach (preg_split('/\r?\n/', (string) @file_get_contents($path)) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $env[trim($k)] = $v;
    }
    return $env;
}

$env = eqsLoadEnv($root . '/.env');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
try {
    if (is_string($socket) && $socket !== '' && file_exists($socket)) {
        $db = new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket);
    } else {
        $db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
    }
} catch (\Throwable $e) {
    if (getenv('CI_STRICT_TESTS') === '1') {
        fwrite(STDERR, "FAIL: database not reachable in strict mode\n");
        exit(1);
    }
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    printf("\nALL %d PASS (database part skipped)\n", $TESTNO);
    exit(0);
}
$db->set_charset('utf8mb4');

const EQS_BOOKS = 'zz_exact_search_501_books';
const EQS_PEOPLE = 'zz_exact_search_501_people';

function eqsCleanup(mysqli $db): void
{
    $db->query('DROP TABLE IF EXISTS `' . EQS_BOOKS . '`');
    $db->query('DROP TABLE IF EXISTS `' . EQS_PEOPLE . '`');
}

set_exception_handler(static function (\Throwable $e) use ($db): void {
    try {
        eqsCleanup($db);
    } catch (\Throwable $ignored) {
    }
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
});

/** @param array{sql:string, params:array<int,string>, types:string}|null $cond */
function eqsIds(mysqli $db, string $table, string $alias, ?array $cond): array
{
    if ($cond === null) {
        return [];
    }
    $stmt = $db->prepare("SELECT id FROM `{$table}` {$alias} WHERE {$cond['sql']} ORDER BY id");
    $stmt->bind_param($cond['types'], ...$cond['params']);
    $stmt->execute();
    $res = $stmt->get_result();
    $ids = [];
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int) $row['id'];
    }
    $stmt->close();
    return $ids;
}

eqsCleanup($db);
$db->query(
    'CREATE TABLE `' . EQS_BOOKS . '` (
        id INT NOT NULL PRIMARY KEY,
        search_index MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
        FULLTEXT KEY ft_search_index (search_index)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
$db->query(
    'CREATE TABLE `' . EQS_PEOPLE . '` (
        id INT NOT NULL PRIMARY KEY,
        nome VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        pseudonimo VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$stmt = $db->prepare('INSERT INTO `' . EQS_BOOKS . '` (id, search_index) VALUES (?, ?)');
foreach ([
    1 => 'Die Brücke Rolf Haffner',
    2 => 'Gedichte Anna Häffner',
    3 => 'Der Zauberberg Thomas Mann S. Fischer',
    4 => 'Mann ohne Eigenschaften Thomas Bernhard',
    5 => 'Müller Lexikon',
] as $id => $value) {
    $stmt->bind_param('is', $id, $value);
    $stmt->execute();
}
$stmt->close();

$stmt = $db->prepare('INSERT INTO `' . EQS_PEOPLE . '` (id, nome, pseudonimo) VALUES (?, ?, ?)');
foreach ([
    1 => ['Rolf Haffner', null],
    2 => ['Anna Häffner', null],
    3 => ['Hans Müller', 'H. Mueller'],
    4 => ['Jörg Haffner-Lenz', null],
] as $id => [$nome, $pseudo]) {
    $stmt->bind_param('iss', $id, $nome, $pseudo);
    $stmt->execute();
}
$stmt->close();

$book = static fn(string $q): array => eqsIds($db, EQS_BOOKS, 's', SearchIndexBuilder::buildSearchCondition($db, 's.search_index', $q));

check($book('haffner') === [1, 2], 'unquoted haffner still finds Haffner and Häffner (tolerant default kept)');
check($book('"haffner"') === [1], '"haffner" finds only Haffner');
check($book('"Häffner"') === [2], '"Häffner" finds only Häffner');
check($book('"HAFFNER"') === [1], 'case is still ignored inside quotes');
check($book('"haffner') === [1], 'unclosed quote behaves like a closed one');
check($book('„Häffner“') === [2], 'typographic quotes work like straight quotes');
check($book('muller') === [5] && $book('"muller"') === [], 'muller finds Müller, "muller" does not');
check($book('"thomas mann"') === [3], '"thomas mann" is an exact phrase (not Mann … Thomas)');
check($book('thomas mann') === [3, 4], 'without quotes the two words may be anywhere');
check($book('gedichte "häffner"') === [2] && $book('brücke "häffner"') === [], 'quoted and tolerant terms are ANDed');
check($book('"') === [] && SearchIndexBuilder::buildSearchCondition($db, 's.search_index', '"') === null, 'a lone quote adds no condition');

$person = static fn(string $q): array => eqsIds($db, EQS_PEOPLE, 'a', ExactSearch::filterCondition(['a.nome', 'a.pseudonimo'], $q));
check($person('haffner') === [1, 2, 4], 'admin filter: unquoted haffner finds every spelling');
check($person('"haffner"') === [1, 4], 'admin filter: "haffner" excludes Häffner');
check($person('"Häffner"') === [2], 'admin filter: "Häffner" finds only Häffner');
check($person('"mueller"') === [3], 'admin filter: quoted value also matches the pseudonym column');
check($person('50%') === [], 'admin filter: % is a literal, not a wildcard');

$words = static fn(string $q): array => eqsIds($db, EQS_PEOPLE, 'a', ExactSearch::wordsCondition(['a.nome', 'a.pseudonimo'], $q));
check($words('rolf haffner') === [1], 'autocomplete: tolerant words are ANDed');
check($words('anna "haffner"') === [], 'autocomplete: quoted term excludes the umlaut spelling');
check($words('anna "häffner"') === [2], 'autocomplete: quoted umlaut spelling matches');

// ── Generated SQL against the real libri schema ────────────────────────────
// Ranking and the pre-migration fallback reference libri/autori/editori, so
// prepare them against the real tables (LIMIT 0) to prove the SQL is valid.
$hasLibri = $db->query("SHOW TABLES LIKE 'libri'");
if ($hasLibri && $hasLibri->num_rows > 0) {
    $rel = SearchIndexBuilder::buildRelevanceOrder($db, 'roman "thomas mann"', 'l.');
    check(substr_count($rel['sql'], 'COLLATE utf8mb4_bin') > 0, 'relevance ORDER BY scores quoted terms with the exact comparison');
    $s = $db->prepare("SELECT l.id FROM libri l WHERE l.deleted_at IS NULL ORDER BY {$rel['sql']} LIMIT 0");
    $s->bind_param($rel['types'], ...$rel['params']);
    check($s->execute(), 'relevance ORDER BY with a quoted phrase is valid SQL');
    $s->close();

    $prop = new \ReflectionProperty(SearchIndexBuilder::class, 'columnExists');
    $saved = $prop->getValue();
    $prop->setValue(null, false);
    try {
        $legacy = SearchIndexBuilder::buildSearchCondition($db, 'l.search_index', 'roman "häffner"');
    } finally {
        $prop->setValue(null, $saved);
    }
    check($legacy !== null && str_contains($legacy['sql'], 'COLLATE utf8mb4_bin'), 'pre-migration fallback honours quotes');
    $s = $db->prepare("SELECT l.id FROM libri l WHERE l.deleted_at IS NULL AND {$legacy['sql']} LIMIT 0");
    $s->bind_param($legacy['types'], ...$legacy['params']);
    check($s->execute(), 'pre-migration fallback with a quoted term is valid SQL');
    $s->close();
} elseif (getenv('CI_STRICT_TESTS') === '1') {
    throw new \RuntimeException('libri table missing in strict mode');
}

eqsCleanup($db);
$db->close();
printf("\nALL %d PASS\n", $TESTNO);

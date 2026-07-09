<?php
declare(strict_types=1);

use App\Support\SearchIndexBuilder;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function sibLoadEnv(string $path): array
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

$env = sibLoadEnv($root . '/.env');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$user = $env['DB_USER'] ?? '';
$pass = $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '');
$name = $env['DB_NAME'] ?? '';

try {
    if (is_string($socket) && $socket !== '' && file_exists($socket)) {
        $db = new mysqli(null, $user, $pass, $name, 0, $socket);
    } else {
        $db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $pass, $name, (int) ($env['DB_PORT'] ?? 3306));
    }
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}
$db->set_charset('utf8mb4');

$hasSearchIndex = $db->query("SHOW COLUMNS FROM libri LIKE 'search_index'");
if (!$hasSearchIndex || $hasSearchIndex->num_rows === 0) {
    echo "SKIP: libri.search_index not present\n";
    exit(0);
}

const SIB_TABLE = 'zz_search_index_builder';

function sibCleanup(mysqli $db): void
{
    $db->query('DROP TABLE IF EXISTS `' . SIB_TABLE . '`');
}

set_exception_handler(static function (\Throwable $e) use ($db): void {
    try {
        sibCleanup($db);
    } catch (\Throwable $ignored) {
    }
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
});

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

function sibSearch(mysqli $db, string $query): array
{
    $cond = SearchIndexBuilder::buildSearchCondition($db, 's.search_index', $query);
    if ($cond === null) {
        return [];
    }
    $stmt = $db->prepare('SELECT id FROM `' . SIB_TABLE . "` s WHERE {$cond['sql']} ORDER BY id");
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

sibCleanup($db);
$db->query(
    'CREATE TABLE `' . SIB_TABLE . '` (
        id INT NOT NULL PRIMARY KEY,
        search_index MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
        FULLTEXT KEY ft_search_index (search_index)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$stmt = $db->prepare('INSERT INTO `' . SIB_TABLE . '` (id, search_index) VALUES (?, ?)');
foreach ([
    1 => 'The Hobbit J R R Tolkien',
    2 => 'A Feast for Crows George R R Martin',
    3 => 'C++ Primer Stanley Lippman',
    4 => 'Q&A handbook',
    5 => 'Il nome della rosa Umberto Eco',
] as $id => $searchIndex) {
    $stmt->bind_param('is', $id, $searchIndex);
    $stmt->execute();
}
$stmt->close();

$normal = SearchIndexBuilder::buildSearchCondition($db, 's.search_index', 'hobbit');
check($normal !== null && str_contains($normal['sql'], 'MATCH(s.search_index)') && !str_contains($normal['sql'], ' LIKE '),
    'normal long token uses only MATCH');

$stopword = SearchIndexBuilder::buildSearchCondition($db, 's.search_index', 'the');
check($stopword !== null && !str_contains($stopword['sql'], 'MATCH(s.search_index)') && str_contains($stopword['sql'], ' LIKE '),
    'standalone FULLTEXT stopword falls back to LIKE');

check(sibSearch($db, 'The Hobbit') === [1], 'The Hobbit matches despite required stopword');
check(sibSearch($db, 'for Crows') === [2], 'for Crows matches despite required stopword');
check(sibSearch($db, 'C++ Primer') === [3], 'C++ Primer matches literal punctuation token');
check(sibSearch($db, 'C++') === [3], 'C++ alone does not degrade into C* FULLTEXT');
check(sibSearch($db, 'Q&A handbook') === [4], 'Q&A handbook matches literal ampersand token');
check(sibSearch($db, 'Q&A') === [4], 'Q&A alone matches via LIKE fallback');
check(sibSearch($db, 'nome della rosa') === [5], 'regular Italian title still matches');

sibCleanup($db);
$db->close();
printf("\nALL %d PASS\n", $TESTNO);

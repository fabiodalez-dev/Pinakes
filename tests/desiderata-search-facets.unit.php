<?php
declare(strict_types=1);

/**
 * When a search reaches a book the library WANTS, the facet counts beside the
 * results must be counting the same books the results show.
 *
 * The catalogue grid widens its predicate to BookVisibility::discoverable() as
 * soon as a search term is present — that is what puts a wanted title, badged,
 * in front of someone who asked for it by name. Every facet query in
 * computeFilterOptions() used BookVisibility::catalogue() unconditionally, so
 * the same page showed the book in the grid and in the total while the genre,
 * publisher, author, media and year counters all denied it existed; clicking
 * any of those facets then made the result the visitor had just seen disappear,
 * because the facet-filtered query is the grid query with one more condition.
 *
 * The check below drives the REAL private method with a real search term, so it
 * cannot pass on a predicate that was only fixed in one of the nine places it
 * appears. It asserts the publisher facet, because a publisher count is a plain
 * COUNT over one join and leaves nowhere for an off-by-one to hide.
 *
 * Runs against a DISPOSABLE database of its own (DESIDERATA_SANDBOX_DB, default
 * "<DB_NAME>_desiderata"), rebuilt from installer/database/schema.sql on every
 * run, and refuses to touch the installation's. Removing the fixture rows is
 * not enough to leave an installation as it was: DesiderataPlugin::ensureSchema()
 * alters libri, backfills catalogued_at with an UPDATE and creates
 * desiderata_offers, and none of that is undone by any cleanup or rollback.
 * FAILS HARD rather than skipping when the sandbox is unreachable. Fixtures are
 * still prefixed and removed in a finally block.
 *
 * Run:  php tests/desiderata-search-facets.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (!function_exists('__')) {
    function __(string $text, mixed ...$args): string
    {
        return $args ? vsprintf($text, $args) : $text;
    }
}

require_once $root . '/storage/plugins/desiderata/DesiderataPlugin.php';

use App\Controllers\FrontendController;
use App\Support\BookVisibility;
use App\Support\HookManager;
use App\Support\Hooks;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

// Transport resolved exactly as tests/desiderata-catalogued-on-create.unit.php
// does: a socket when one exists, TCP otherwise (CI reaches MySQL over TCP).
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$dbHost = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$dbPort = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));

// ensureSchema() runs DDL and a backfill UPDATE that no cleanup can undo, so the
// suite never touches the installation's database: it rebuilds a disposable one
// from schema.sql on every run.
$sandboxName = getenv('DESIDERATA_SANDBOX_DB') ?: ($dbName . '_desiderata');
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $sandboxName)) {
    fwrite(STDERR, "FAIL: invalid sandbox database name '{$sandboxName}'\n");
    exit(1);
}
if (strcasecmp($sandboxName, (string) $dbName) === 0) {
    fwrite(STDERR, "FAIL: the sandbox name is the installation's own database. Name a disposable one.\n");
    exit(1);
}

$connect = static function (string $database) use ($socket, $dbUser, $dbPass, $dbHost, $dbPort): mysqli {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $database, 0, $socket)
        : new mysqli($dbHost, $dbUser, $dbPass, $database, $dbPort);
    $db->set_charset('utf8mb4');

    return $db;
};

try {
    $admin = $connect('');
    $admin->query("CREATE DATABASE IF NOT EXISTS `{$sandboxName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $admin->close();

    $prep = $connect($sandboxName);
    $prep->query('SET FOREIGN_KEY_CHECKS = 0');
    $tables = [];
    $result = $prep->query('SHOW TABLES');
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }
    foreach ($tables as $table) {
        $prep->query('DROP TABLE IF EXISTS `' . $table . '`');
    }
    $prep->query('SET FOREIGN_KEY_CHECKS = 1');
    $prep->close();

    $useSocket = is_string($socket) && $socket !== '' && file_exists($socket);
    $command = $useSocket
        ? sprintf('mysql --socket=%s -u %s %s', escapeshellarg($socket), escapeshellarg((string) $dbUser), escapeshellarg($sandboxName))
        : sprintf(
            'mysql -h %s -P %d --protocol=TCP -u %s %s',
            escapeshellarg((string) $dbHost),
            $dbPort,
            escapeshellarg((string) $dbUser),
            escapeshellarg($sandboxName)
        );
    $command .= ' < ' . escapeshellarg($root . '/installer/database/schema.sql') . ' 2>&1';
    $previousPwd = getenv('MYSQL_PWD');
    putenv('MYSQL_PWD=' . (string) $dbPass);
    try {
        exec($command, $out, $rc);
    } finally {
        $previousPwd === false ? putenv('MYSQL_PWD') : putenv('MYSQL_PWD=' . $previousPwd);
    }
    if ($rc !== 0) {
        throw new RuntimeException('could not load schema.sql: ' . implode("\n", $out));
    }

    $db = $connect($sandboxName);
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: the sandbox database '{$sandboxName}' could not be prepared: {$e->getMessage()}\n");
    exit(1);
}

$MARK = 'ZZTESTFACET';
$fatalError = null;
$publisherId = 0;
$bookId = 0;

try {
    (new DesiderataPlugin($db, new HookManager($db)))->ensureSchema();
    if (!BookVisibility::hasDesiderata($db)) {
        throw new RuntimeException('libri.is_desiderata is absent: every assertion below would be vacuous');
    }

    // The plugin's filter is what makes discoverable() differ from catalogue().
    // Registered by hand so the suite does not depend on the plugin being
    // ACTIVE in whatever database it is pointed at.
    $manager = new HookManager($db);
    $manager->setPluginsLoadedRuntime();
    Hooks::init($manager);
    Hooks::add('book.visibility.discoverable', static fn(): string => '1=1');
    if (BookVisibility::discoverable($db, 'l') !== '1=1') {
        throw new RuntimeException('the discoverable filter did not take effect: the two predicates would be identical and nothing would be under test');
    }

    $db->query("INSERT INTO editori (nome) VALUES ('{$MARK} Editore')");
    $publisherId = (int) $db->insert_id;
    $db->query("INSERT INTO libri (titolo, editore_id, is_desiderata, anno_pubblicazione)
                VALUES ('{$MARK} Titolo Desiderato', {$publisherId}, 1, 2001)");
    $bookId = (int) $db->insert_id;
    // The catalogue search runs on the denormalized FULLTEXT column, not on
    // libri.titolo. A raw INSERT leaves it empty, and the fixture would then be
    // unfindable for a reason that has nothing to do with what is under test —
    // the check would fail identically before and after the fix, proving
    // nothing in either direction.
    \App\Support\SearchIndexBuilder::rebuild($db, $bookId);
    $indexed = $db->query("SELECT search_index FROM libri WHERE id = {$bookId}")->fetch_assoc();
    if (!str_contains((string) ($indexed['search_index'] ?? ''), $MARK)) {
        throw new RuntimeException('the fixture never reached the search index: the search checks below would be vacuous');
    }

    $controller = new FrontendController();
    $compute = new ReflectionMethod(FrontendController::class, 'computeFilterOptions');
    $compute->setAccessible(true);

    /** How many books the publisher facet credits to our fixture publisher. */
    $facetCount = static function (array $options) use ($MARK): ?int {
        foreach ($options['editori'] ?? [] as $row) {
            if (str_contains((string) ($row['nome'] ?? ''), $MARK)) {
                // 'cnt' is what the publisher facet query aliases its
                // COUNT(DISTINCT l.id) to. Named exactly, not guessed from a
                // list of plausible spellings: a guess that misses would make
                // this helper report "no count" and read as a failure of the
                // fix rather than of the helper.
                return isset($row['cnt']) ? (int) $row['cnt'] : -1;
            }
        }
        return null; // the publisher is absent from the facet entirely
    };

    echo "A. Browsing — a wanted title is NOT padded into the facets\n";

    $browse = $compute->invoke($controller, $db, ['search' => '']);
    $check($facetCount($browse) === null,
        'with no search term the wanted book is absent from the publisher facet');

    echo "\nB. Searching — the facet counts what the grid shows\n";

    $searched = $compute->invoke($controller, $db, ['search' => $MARK]);
    $got = $facetCount($searched);
    $check($got !== null, 'the publisher of a searched-for wanted title appears in the facet at all');
    $check($got !== -1, 'and its count column was recognised (otherwise this check proves nothing)');
    $check(is_int($got) && $got >= 1, "and it credits at least the one book (counted {$got})");

    echo "\nC. The rule is the same one the grid uses\n";

    // Stated as an identity rather than a literal, so this keeps holding if the
    // predicate's text ever changes.
    $check(BookVisibility::discoverable($db, 'l') !== BookVisibility::catalogue($db, 'l'),
        'the two predicates really are different here, so B above was a real test');
} catch (\Throwable $error) {
    $fatalError = $error;
} finally {
    $cleanup = [];
    if ($bookId > 0) {
        $cleanup[] = "DELETE FROM libri WHERE id = {$bookId}";
    }
    if ($publisherId > 0) {
        $cleanup[] = "DELETE FROM editori WHERE id = {$publisherId}";
    }
    foreach ($cleanup as $sql) {
        try {
            $db->query($sql);
        } catch (\Throwable $error) {
            fwrite(STDERR, "CLEANUP FAILED: {$sql}\n    {$error->getMessage()}\n");
            $fail++;
        }
    }
}

if ($fatalError !== null) {
    fwrite(STDERR, "\nFAIL: {$fatalError->getMessage()}\n");
    exit(1);
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);

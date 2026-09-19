<?php
declare(strict_types=1);

/**
 * A reading club must survive the librarian flagging one of its books as a
 * desiderata.
 *
 * The regression this guards against shipped in the first cut of the desiderata
 * feature and was invisible to every existing suite. The filter
 * `is_desiderata = 0` was appended to the JOIN condition over `libri`, on the
 * stated theory that a predicate placed there "only empties book_title". It
 * does not. On the LEFT JOIN in Repo::bookSelect() it nulls the whole `l.*`
 * projection, and the guard beside it —
 * "(l.id IS NOT NULL OR cb.external_book_id IS NOT NULL)", written to hide a
 * SOFT-DELETED book — then reads that as a missing row and drops the entry
 * entirely: clubBook() answered null and every route keyed on it (state
 * transitions, reading, polls, meetings, surveys, discussions, buddy reading,
 * quotes) returned 404. On the sibling repositories, where the same predicate
 * sat on an INNER JOIN, the row simply vanished from the listing.
 *
 * The resolution was to drop the filter rather than relocate it: `/desiderata`
 * is registered with no auth middleware and publishes the entire wish list —
 * titles, authors, publishers, covers — to anonymous visitors by design, so a
 * club showing the title of a book its members chose to read discloses nothing
 * the feature does not already publish itself. These checks therefore assert
 * that the title STAYS, which is the behaviour that was chosen, not the
 * behaviour the original comment promised.
 *
 * Runs against a DISPOSABLE database of its own (DESIDERATA_SANDBOX_DB, default
 * "<DB_NAME>_desiderata"), rebuilt from installer/database/schema.sql on every
 * run, and refuses to touch the installation's. The rollback below could never
 * have protected an installation: DesiderataPlugin::ensureSchema() alters libri,
 * backfills catalogued_at and creates desiderata_offers, and
 * BookClubPlugin::ensureSchema() creates the club tables and inserts the system
 * roles and the default workflow — all before the transaction opens, and DDL
 * commits implicitly. FAILS HARD rather than skipping when the sandbox is
 * unreachable. The fixture rows (club, book, entry, section) are still written
 * inside a transaction that is rolled back.
 *
 * Run:  php tests/desiderata-bookclub-resolvable.unit.php
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
require_once $root . '/storage/plugins/book-club/BookClubPlugin.php';

use App\Support\BookVisibility;
use App\Support\HookManager;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

/** .env is parsed by hand: this file must run as a bare `php tests/...` process. */
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

// Both plugins' ensureSchema() run DDL and seed rows before the transaction
// opens, and none of it is undone by the rollback, so the suite never touches
// the installation's database: it rebuilds a disposable one from schema.sql on
// every run.
$sandboxName = getenv('DESIDERATA_SANDBOX_DB') ?: ($dbName . '_desiderata');
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $sandboxName)) {
    fwrite(STDERR, "FAIL: invalid sandbox database name '{$sandboxName}'\n");
    exit(1);
}
// The sandbox is refused if it names ANY real database this run could mean:
// the installation's (.env DB_NAME) and the E2E one (E2E_DB_NAME). Comparing
// against only one let DESIDERATA_SANDBOX_DB equal to the other slip through,
// and the setup below would then empty every table in it.
if (in_array(strtolower($sandboxName), array_map('strtolower', array_filter([(string) $dbName, (string) getenv('E2E_DB_NAME'), (string) ($env['DB_NAME'] ?? '')])), true)) {
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

$fatalError = null;
$transactionOpen = false;

try {
    // Built, never skipped. Without the column BookVisibility::catalogue()
    // degrades to the literal 1=1, and every assertion below would pass while
    // proving nothing at all about the filtering.
    (new DesiderataPlugin($db, new HookManager($db)))->ensureSchema();
    if (!BookVisibility::hasDesiderata($db)) {
        throw new RuntimeException('libri.is_desiderata is absent after ensureSchema(): these checks would be vacuous');
    }

    // The club tables are built the same way, through the plugin's own
    // idempotent ensureSchema(), so this suite does not depend on book-club
    // happening to be activated in the database it is pointed at — and does
    // not hand-roll a second copy of a schema that would then drift from the
    // real one.
    (new \App\Plugins\BookClub\BookClubPlugin($db, new HookManager($db)))->ensureSchema();
    foreach (['bookclub_clubs', 'bookclub_books', 'bookclub_sections'] as $table) {
        if ($db->query("SHOW TABLES LIKE '{$table}'")->num_rows === 0) {
            throw new RuntimeException("{$table} is absent after BookClubPlugin::ensureSchema()");
        }
    }

    $repo = new \App\Plugins\BookClub\Repo($db);
    $discussions = new \App\Plugins\BookClub\DiscussionRepo($db);

    $db->begin_transaction();
    $transactionOpen = true;

    // Club, book and entry are all seeded here rather than borrowed from the
    // database this happens to run against. A suite that needs pre-existing
    // demo rows passes on a developer's machine and fails on an empty CI
    // schema for a reason that has nothing to do with the code it guards.
    $db->query("INSERT INTO bookclub_clubs (slug, name, color, privacy, ics_token, is_active)
                VALUES ('zz-probe-club', 'ZZ probe club', '#336699', 'public', REPEAT('0', 32), 1)");
    $clubId = (int) $db->insert_id;

    $title = 'ZZ probe title ' . $clubId;
    $db->query("INSERT INTO libri (titolo, is_desiderata) VALUES ('{$title}', 0)");
    $libroId = (int) $db->insert_id;

    $db->query("INSERT INTO bookclub_books (club_id, libro_id, state, position)
                VALUES ({$clubId}, {$libroId}, 'reading', 1)");
    $clubBookId = (int) $db->insert_id;
    $db->query("INSERT INTO bookclub_sections (club_book_id, title, sort)
                VALUES ({$clubBookId}, 'Regression probe section', 1)");

    echo "A. Baseline — an ordinary holding\n";

    $before = $repo->clubBook($clubBookId);
    $check($before !== null, 'clubBook() resolves the entry');
    $check($before !== null && ($before['titolo'] ?? null) === $title, 'and carries its title');
    $check(count($discussions->clubSections($clubId)) >= 1, 'its discussion section is listed');

    echo "\nB. The librarian flags that same book as wanted rather than held\n";

    $db->query("UPDATE libri SET is_desiderata = 1 WHERE id = {$libroId}");

    $after = $repo->clubBook($clubBookId);
    $check($after !== null, 'clubBook() STILL resolves it — every route keyed on it would 404 otherwise');
    $check($after !== null && ($after['titolo'] ?? null) === $title,
        'the title is still there: the members have to see what they are reading');

    $ids = array_map(static fn(array $r): int => (int) $r['id'], $repo->clubBooks($clubId));
    $check(in_array($clubBookId, $ids, true), 'it is still listed among the club books');

    $sectionIds = array_map(static fn(array $r): int => (int) $r['id'], $discussions->clubSections($clubId));
    $check($sectionIds !== [], 'its discussion section survives the INNER JOIN over libri');

    echo "\nC. Soft deletion is a different matter, and must still hide the row\n";

    // The guard the desiderata filter was mistaken for is a real one. Proving
    // it still bites is what keeps this fix from having quietly removed it.
    $db->query("UPDATE libri SET is_desiderata = 0, deleted_at = NOW() WHERE id = {$libroId}");
    $check($repo->clubBook($clubBookId) === null, 'a soft-deleted catalogue book still makes the entry unresolvable');
} catch (\Throwable $error) {
    $fatalError = $error;
} finally {
    if ($transactionOpen) {
        try {
            $db->rollback();
        } catch (\Throwable $error) {
            fwrite(STDERR, "\nCLEANUP FAILED — fixture rows may still be in the database:\n    " . $error->getMessage() . "\n");
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

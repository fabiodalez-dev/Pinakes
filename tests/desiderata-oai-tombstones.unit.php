<?php
declare(strict_types=1);

/**
 * A harvester is owed a deletion for a record it actually received — and for
 * nothing else.
 *
 * The OAI-PMH de-listing arm derived its tombstones from is_desiderata = 1
 * alone. But a row reaches that flag two ways: a catalogued book WITHDRAWN
 * (a real deletion its subscribers must be told about) and a book BORN as a
 * request (which no harvester ever saw). Nothing afterwards distinguishes them,
 * because the copies are hard-deleted and the transition leaves no trace — so
 * the arm published `status=deleted` headers for wishes that were never
 * published in the first place, handing anonymous harvesters the ids and
 * timestamps of the library's wish list along the way.
 *
 * libri.catalogued_at is the missing half: stamped once, the first time a row
 * is written with is_desiderata = 0, never cleared. The checks below exercise
 * the REAL BookRepository write paths and the REAL plugin schema step rather
 * than hand-written SQL, because the whole question is whether THOSE stamp it.
 *
 * Note on the predicate: it is "was ever is_desiderata = 0", not "ever had
 * copies". The ACTIVE arm filters on is_desiderata alone, so a record with no
 * copies is harvested just the same; tying the stamp to copies would stop a
 * genuinely withdrawn copy-less record from ever tombstoning.
 *
 * Runs against a DISPOSABLE database of its own (DESIDERATA_SANDBOX_DB, default
 * "<DB_NAME>_desiderata"), rebuilt from installer/database/schema.sql on every
 * run, and refuses to touch the installation's. The transaction below does not
 * make the installation safe: ensureSchema() adds libri.catalogued_at, backfills
 * it with an UPDATE and creates desiderata_offers before the transaction opens,
 * and DDL commits implicitly, so none of it would ever be rolled back. FAILS
 * HARD rather than skipping when the sandbox is unreachable. The book rows are
 * still written inside a transaction that is rolled back.
 *
 * Run:  php tests/desiderata-oai-tombstones.unit.php
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

use App\Models\BookRepository;
use App\Support\BookVisibility;
use App\Support\HookManager;

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

// ensureSchema() runs DDL and a backfill UPDATE that no rollback can undo, so
// the suite never touches the installation's database: it rebuilds a
// disposable one from schema.sql on every run.
$sandboxName = getenv('DESIDERATA_SANDBOX_DB') ?: ($dbName . '_desiderata');
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $sandboxName)) {
    fwrite(STDERR, "FAIL: invalid sandbox database name '{$sandboxName}'\n");
    exit(1);
}
// The sandbox is refused if it names ANY real database this run could mean:
// the installation's (.env DB_NAME) and the E2E one (E2E_DB_NAME). Comparing
// against only one let DESIDERATA_SANDBOX_DB equal to the other slip through,
// and the setup below would then empty every table in it.
if (in_array(strtolower($sandboxName), array_map('strtolower', array_filter([(string) $dbName, (string) getenv('E2E_DB_NAME'), (string) ($env['DB_NAME'] ?? '')], static fn (string $name): bool => $name !== '')), true)) {
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

$MARK = 'ZZTOMB';
$fatalError = null;
$transactionOpen = false;

try {
    echo "A. The schema step adds the column and backfills only what it can know\n";

    // A holding that predates the plugin. The sandbox starts empty, and the
    // backfill check below would be vacuous with nothing there to stamp.
    $db->query("INSERT INTO libri (titolo) VALUES ('{$MARK} preesistente')");
    $preexisting = (int) $db->insert_id;

    $plugin = new DesiderataPlugin($db, new HookManager($db));
    $plugin->ensureSchema();
    $check(BookVisibility::hasCataloguedAt($db), 'ensureSchema() leaves libri.catalogued_at in place');
    $plugin->ensureSchema();
    $check(BookVisibility::hasCataloguedAt($db), 'and a second call is harmless (idempotent)');

    $orphans = (int) $db->query(
        'SELECT COUNT(*) c FROM libri WHERE is_desiderata = 0 AND catalogued_at IS NULL'
    )->fetch_assoc()['c'];
    $check($orphans === 0, "the backfill stamped every row already in the catalogue ({$orphans} left unstamped)");
    $check(
        $db->query("SELECT catalogued_at FROM libri WHERE id = {$preexisting}")->fetch_assoc()['catalogued_at'] !== null,
        'including the holding that existed before the plugin (so the check above had something to stamp)'
    );

    echo "\nB. The two origins, written through the real repository\n";

    $db->begin_transaction();
    $transactionOpen = true;

    $repo = new BookRepository($db);
    $stampOf = static function (int $id) use ($db): ?string {
        $row = $db->query("SELECT catalogued_at FROM libri WHERE id = {$id}")->fetch_assoc();

        return $row['catalogued_at'] ?? null;
    };

    $born = (int) $repo->createBasic(['titolo' => $MARK . ' nato in catalogo']);
    $check($born > 0, 'a book created as an ordinary holding');
    $check($stampOf($born) !== null, 'is stamped as catalogued the moment it is created');

    $wished = (int) $repo->createBasic(['titolo' => $MARK . ' nato desiderata', 'is_desiderata' => 1]);
    $check($wished > 0, 'a book created as a request');
    $check($stampOf($wished) === null, 'is NOT stamped — no harvester has ever seen it');

    echo "\nC. Withdrawal keeps the memory; a never-published wish has none\n";

    // The catalogued book is now withdrawn: flagged as wanted again.
    $repo->updateBasic($born, ['titolo' => $MARK . ' nato in catalogo', 'is_desiderata' => 1]);
    $check((int) $db->query("SELECT is_desiderata FROM libri WHERE id = {$born}")->fetch_assoc()['is_desiderata'] === 1,
        'the catalogued book is now flagged as wanted');
    $check($stampOf($born) !== null, 'and it still remembers it was once in the catalogue');

    $delisted = BookVisibility::delisted($db, 'l');
    $ever = BookVisibility::everCatalogued($db, 'l');
    $check($ever !== '0=1', 'everCatalogued() is a real predicate here (otherwise the rest is vacuous)');

    $tombstoned = static function (int $id) use ($db, $delisted, $ever): bool {
        $sql = "SELECT COUNT(*) c FROM libri l WHERE l.id = {$id} AND l.deleted_at IS NULL AND {$delisted} AND {$ever}";

        return (int) $db->query($sql)->fetch_assoc()['c'] === 1;
    };

    $check($tombstoned($born), 'the WITHDRAWN record produces a tombstone, as its subscribers are owed');
    $check(!$tombstoned($wished), 'the never-published wish produces none');

    // The old behaviour, stated explicitly so the regression is unmistakable.
    $oldArm = static function (int $id) use ($db, $delisted): bool {
        $sql = "SELECT COUNT(*) c FROM libri l WHERE l.id = {$id} AND l.deleted_at IS NULL AND {$delisted}";

        return (int) $db->query($sql)->fetch_assoc()['c'] === 1;
    };
    $check($oldArm($wished), 'and the flag ALONE would have tombstoned it — which is the defect');

    echo "\nD. The stamp is written once, not refreshed\n";

    $repo->updateBasic($born, ['titolo' => $MARK . ' nato in catalogo', 'is_desiderata' => 0]);
    $first = $stampOf($born);
    $db->query("UPDATE libri SET catalogued_at = '2000-01-01 00:00:00' WHERE id = {$born}");
    $repo->updateBasic($born, ['titolo' => $MARK . ' nato in catalogo', 'is_desiderata' => 0]);
    $check($stampOf($born) === '2000-01-01 00:00:00',
        'a later catalogue write does not overwrite the first stamp (COALESCE, not assignment)');
    $check($first !== null, 'and receiving it back into the catalogue stamped it in the first place');

    echo "\nE. Receiving a wished book stamps it, so a later withdrawal is honest\n";

    $db->query("UPDATE libri SET is_desiderata = 0" . BookVisibility::catalogueStamp($db) . " WHERE id = {$wished}");
    $check($stampOf($wished) !== null, 'the receipt path stamps through the shared SET fragment');
    $db->query("UPDATE libri SET is_desiderata = 1 WHERE id = {$wished}");
    $check($tombstoned($wished), 'so withdrawing it afterwards DOES owe its harvesters a deletion');
} catch (\Throwable $error) {
    $fatalError = $error;
} finally {
    if ($transactionOpen) {
        try {
            $db->rollback();
        } catch (\Throwable $error) {
            fwrite(STDERR, "\nCLEANUP FAILED: " . $error->getMessage() . "\n");
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

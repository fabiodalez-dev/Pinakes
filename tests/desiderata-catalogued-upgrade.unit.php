<?php
declare(strict_types=1);

/**
 * An installation already running the desiderata plugin must RECEIVE
 * libri.catalogued_at when it upgrades — not only a fresh install.
 *
 * This is the half that cannot be proved by calling ensureSchema() directly.
 * A bundled plugin's schema step re-runs on upgrade only when the version in
 * plugin.json is higher than the one recorded in the plugins table; a schema
 * change shipped without that bump reaches new installations and silently
 * skips every existing one. So the check below drives the REAL sync —
 * PluginManager::autoRegisterBundledPlugins() — against a database rewound to
 * the previous version, which is exactly what an upgrade does.
 *
 * It also pins the backfill's direction, which is a deliberate asymmetry: a row
 * sitting in the catalogue right now IS stamped, while a row already flagged as
 * wanted CANNOT be judged — the copies that would have told us are hard-deleted
 * — so it stays NULL and will never produce a tombstone. The existing wish list
 * loses de-listings it might have deserved; the alternative would keep
 * announcing removals for records no harvester was ever given.
 *
 * Runs against a DISPOSABLE database of its own and refuses to touch the
 * installation's, following tests/desiderata-visibility.integration.php. It
 * FAILS HARD rather than skipping when that database is unreachable: a skip
 * here is indistinguishable from a pass, and this is the check that must not
 * be quietly absent.
 *
 * Run:  php tests/desiderata-catalogued-upgrade.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (!function_exists('__')) {
    function __(string $text, mixed ...$args): string
    {
        return $args ? vsprintf($text, $args) : $text;
    }
}

use App\Support\HookManager;
use App\Support\PluginManager;

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

$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
// Host and port are overridable like the rest, so the TCP branch below can
// actually be exercised on a machine whose .env says 'localhost' — which
// mysqli resolves to the socket, quietly skipping the very path CI takes.
$dbHost = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$dbPort = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));

$sandboxName = getenv('DESIDERATA_SANDBOX_DB') ?: ($dbName . '_desiderata');
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $sandboxName)) {
    throw new RuntimeException("invalid sandbox database name '{$sandboxName}'");
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

// The database is CREATEd if missing and never dropped — only its tables are
// cleared. Dropping it would destroy whatever grant an operator had attached to
// the name, and on a machine where the sandbox is shared with another suite it
// would take that suite's fixtures with it.
$admin = $connect('');
$admin->query("CREATE DATABASE IF NOT EXISTS `{$sandboxName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$admin->close();

$wipe = static function (mysqli $db): void {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    $tables = [];
    $result = $db->query('SHOW TABLES');
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }
    foreach ($tables as $table) {
        $db->query('DROP TABLE IF EXISTS `' . $table . '`');
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
};

$prep = $connect($sandboxName);
$wipe($prep);
$prep->close();

$fatalError = null;

try {
    // The transport is chosen the same way the mysqli connections above choose
    // it, and for the same reason: hard-coding --socket with a path bakes the
    // developer's machine into the test. It passed here and failed on CI, which
    // reaches MySQL over TCP and has no socket at that path at all — a failure
    // that says nothing about the code it guards. The password travels in the
    // environment rather than in argv, so it stays out of the process list and
    // out of mysql's own warning on stderr.
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
        throw new RuntimeException('could not load schema.sql (' . ($useSocket ? 'socket' : 'tcp') . '): ' . implode("\n", $out));
    }

    $db = $connect($sandboxName);
    $manager = new PluginManager($db, new HookManager($db));

    $manifest = json_decode((string) file_get_contents($root . '/storage/plugins/desiderata/plugin.json'), true);
    $shipped = (string) ($manifest['version'] ?? '');

    echo "A. The installation as it stood BEFORE this change\n";

    $manager->autoRegisterBundledPlugins();
    $row = $db->query("SELECT id, version, is_active FROM plugins WHERE name = 'desiderata'")->fetch_assoc();
    $check($row !== null, 'desiderata is registered');
    $check($row !== null && (int) $row['is_active'] === 0, 'and starts deactivated, being optional');

    // Switch it on through the production path, then rewind the database to
    // the previous release: the column gone, the recorded version one step
    // back. That is precisely the state an upgrading installation is in.
    $manager->activatePlugin((int) $row['id']);
    $check($db->query("SHOW COLUMNS FROM libri LIKE 'catalogued\\_at'")->num_rows === 1,
        'activation builds the column on a fresh install');

    $db->query('ALTER TABLE libri DROP COLUMN catalogued_at');
    $db->query("UPDATE plugins SET version = '1.1.1' WHERE name = 'desiderata'");
    $check($db->query("SHOW COLUMNS FROM libri LIKE 'catalogued\\_at'")->num_rows === 0,
        'rewound: an installation on the previous version has no such column');

    // Two books that the upgrade has to treat differently.
    $db->query("INSERT INTO libri (titolo, is_desiderata) VALUES ('Held before the upgrade', 0)");
    $held = (int) $db->insert_id;
    $db->query("INSERT INTO libri (titolo, is_desiderata) VALUES ('Wanted before the upgrade', 1)");
    $wanted = (int) $db->insert_id;

    echo "\nB. The upgrade, driven through the real bundled-plugin sync\n";

    $manager->autoRegisterBundledPlugins();

    $after = $db->query("SELECT version FROM plugins WHERE name = 'desiderata'")->fetch_assoc()['version'];
    $check($after === $shipped, "the recorded version moves to the shipped {$shipped} (got {$after})");
    $check($db->query("SHOW COLUMNS FROM libri LIKE 'catalogued\\_at'")->num_rows === 1,
        'and the upgrade builds the column on an installation that already had the plugin');

    $stamp = static fn(int $id): ?string => $db->query("SELECT catalogued_at FROM libri WHERE id = {$id}")
        ->fetch_assoc()['catalogued_at'] ?? null;

    $check($stamp($held) !== null, 'a book already in the catalogue is backfilled as catalogued');
    $check($stamp($wanted) === null,
        'a book already flagged as wanted is NOT: nothing records whether it was ever published, and guessing would keep announcing deletions for records no harvester received');

    echo "\nC. Running the sync again changes nothing\n";

    $before = $stamp($held);
    $manager->autoRegisterBundledPlugins();
    $check($stamp($held) === $before, 'the stamp is not refreshed by a second sync');
    $check($stamp($wanted) === null, 'and the unjudgeable row stays unjudged');
    $check($db->query("SHOW COLUMNS FROM libri LIKE 'catalogued\\_at'")->num_rows === 1,
        'the column is still there exactly once');

    $db->close();
} catch (\Throwable $error) {
    $fatalError = $error;
} finally {
    // Leave the sandbox empty rather than absent, for the reason above.
    try {
        $cleanup = $connect($sandboxName);
        $wipe($cleanup);
        $cleanup->close();
    } catch (\Throwable $error) {
        fwrite(STDERR, "CLEANUP FAILED: {$error->getMessage()}\n");
        $fail++;
    }
}

if ($fatalError !== null) {
    fwrite(STDERR, "\nFAIL: {$fatalError->getMessage()}\n");
    exit(1);
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);

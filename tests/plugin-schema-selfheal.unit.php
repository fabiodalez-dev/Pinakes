<?php
declare(strict_types=1);

/**
 * Plugin schema self-heal — the Uwe #138 / 0.7.31 regression.
 *
 * THE BUG this pins down: PluginManager stamps `plugins.version` to the new
 * disk version BEFORE (and independently of) ensureSchema actually creating the
 * plugin's tables. If ensureSchema never completes (a sibling plugin aborts the
 * sync loop, a crash between the version write and the DDL, …), the install is
 * left at version == disk with a table MISSING. On the next boot the
 * "same-version, active, hooks present" path SKIPS ensureSchema forever, so the
 * schema never heals and every plugin page 500s with a 1146 "table doesn't
 * exist" — exactly what Uwe reported after upgrading with the plugin active.
 *
 * These tests reproduce that state against the REAL PluginManager and REAL
 * BookClubPlugin, then assert the schema self-heals. On the pre-fix code the
 * "already at target version" case (test 05/06) FAILS — that is the point:
 * a test that only ever passes proves nothing.
 *
 * Safe to run repeatedly: it snapshots the live bookclub_external_books rows,
 * forces the broken state, runs the sync, and restores the rows at the end.
 */

require __DIR__ . '/../vendor/autoload.php';

/* ----------------------------- DB connect ----------------------------- */
function she_env(string $path): array
{
    $env = [];
    foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $env[$k] = $v;
    }
    return $env;
}

$env    = she_env(__DIR__ . '/../.env');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$user   = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$pass   = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$name   = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');

mysqli_report(MYSQLI_REPORT_OFF);
try {
    $db = (is_string($socket) && $socket !== '' && file_exists($socket))
        ? new mysqli(null, $user, $pass, $name, 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $pass, $name, (int) ($env['DB_PORT'] ?? 3306));
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}
$db->set_charset('utf8mb4');

/* ----------------------------- harness ----------------------------- */
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

$tableExists = function (string $t) use ($db): bool {
    return (bool) $db->query(
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $db->real_escape_string($t) . "'"
    )->num_rows;
};
$columnExists = function (string $t, string $c) use ($db): bool {
    return (bool) $db->query(
        "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $db->real_escape_string($t) . "' AND COLUMN_NAME='" . $db->real_escape_string($c) . "'"
    )->num_rows;
};

/* --------- require book-club present + active (else SKIP) --------- */
$row = $db->query("SELECT id, is_active FROM plugins WHERE name='book-club'")->fetch_assoc();
if (!$row) {
    echo "SKIP: book-club plugin not registered in this DB\n";
    exit(0);
}
$pluginId = (int) $row['id'];
$wasActive = (int) $row['is_active'];
$origVersion = (string) $db->query("SELECT version FROM plugins WHERE name='book-club'")->fetch_row()[0];

require_once __DIR__ . '/../storage/plugins/book-club/BookClubPlugin.php';
$hm = new \App\Support\HookManager($db);

/* Bring the plugin to a known-good baseline first (active + schema present). */
$db->query("UPDATE plugins SET is_active=1 WHERE name='book-club'");
$baseline = new \App\Plugins\BookClub\BookClubPlugin($db, $hm);
$baseline->setPluginId($pluginId);
$baseline->onActivate();

/* Snapshot rows so we can restore user data at the end. */
$db->query("DROP TABLE IF EXISTS zz_she_bak");
$db->query("CREATE TABLE zz_she_bak AS SELECT * FROM bookclub_external_books");

$diskVersion = (string) (json_decode((string) file_get_contents(__DIR__ . '/../storage/plugins/book-club/plugin.json'), true)['version'] ?? '1.4.0');

/** Force the "table missing" broken state (keeps hooks). */
$breakSchema = function () use ($db): void {
    @$db->query("ALTER TABLE bookclub_books DROP FOREIGN KEY fk_bcbooks_external");
    @$db->query("ALTER TABLE bookclub_books DROP KEY uq_bcbooks_external");
    @$db->query("ALTER TABLE bookclub_books DROP KEY idx_bcbooks_external");
    @$db->query("ALTER TABLE bookclub_books DROP COLUMN external_book_id");
    @$db->query("DROP TABLE IF EXISTS bookclub_external_books");
};
$runSync = function () use ($db, $hm): void {
    (new \App\Support\PluginManager($db, $hm))->autoRegisterBundledPlugins();
};
$hookCount = function () use ($db, $pluginId): int {
    return (int) $db->query("SELECT COUNT(*) FROM plugin_hooks WHERE plugin_id={$pluginId}")->fetch_row()[0];
};

$cleanup = function () use ($db, $hm, $pluginId, $wasActive, $origVersion, $tableExists): void {
    // Heal + restore the snapshot, leave the plugin as we found it.
    require_once __DIR__ . '/../storage/plugins/book-club/BookClubPlugin.php';
    $p = new \App\Plugins\BookClub\BookClubPlugin($db, $hm);
    $p->setPluginId($pluginId);
    try { $p->onActivate(); } catch (\Throwable $e) { /* best effort */ }
    if ($tableExists('bookclub_external_books')
        && (int) $db->query("SELECT COUNT(*) FROM bookclub_external_books")->fetch_row()[0] === 0
        && $tableExists('zz_she_bak')) {
        @$db->query("INSERT INTO bookclub_external_books SELECT * FROM zz_she_bak");
    }
    $db->query("DROP TABLE IF EXISTS zz_she_bak");
    $ver = $db->real_escape_string($origVersion);
    $db->query("UPDATE plugins SET is_active={$wasActive}, version='{$ver}' WHERE id={$pluginId}");
};
set_exception_handler(function (\Throwable $e) use ($cleanup): void {
    try { $cleanup(); } catch (\Throwable $ignored) {}
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(1);
});

/* ================= Scenario 1 — version-bump upgrade (1.3.x active → disk) ================= */
$breakSchema();
$db->query("UPDATE plugins SET version='1.3.0', is_active=1 WHERE name='book-club'");
check(!$tableExists('bookclub_external_books'), '01 setup: broken state, external_books dropped');
check($hookCount() > 0, '02 setup: plugin is active with hooks registered (Uwe scenario)');

$runSync();
check($tableExists('bookclub_external_books'), '03 version-bump sync heals: external_books created');
check($columnExists('bookclub_books', 'external_book_id'), '04 version-bump sync heals: bookclub_books.external_book_id added');

/* ==== Scenario 2 — THE Uwe bug: version already == disk, table missing, hooks present ==== */
/* This is the state a partial/aborted upgrade leaves behind. Pre-fix, the
 * "same version + hooks present" branch skips ensureSchema and this NEVER heals. */
$breakSchema();
$ver = $db->real_escape_string($diskVersion);
$db->query("UPDATE plugins SET version='{$ver}', is_active=1 WHERE name='book-club'");
check(!$tableExists('bookclub_external_books'), '05 setup: version already at disk, external_books missing, hooks present');

$runSync();
check($tableExists('bookclub_external_books'), '06 same-version sync SELF-HEALS the missing table (was the permanent-500 bug)');
check($columnExists('bookclub_books', 'external_book_id'), '07 same-version sync also restores external_book_id');

/* ==== Scenario 3 — idempotent: a healthy install is not needlessly rebuilt ==== */
$before = $tableExists('bookclub_external_books');
$runSync();
check($before && $tableExists('bookclub_external_books'), '08 idempotent: a second sync on a healthy schema leaves the table intact');

$cleanup();
$db->close();
printf("\nALL %d PASS\n", $TESTNO);

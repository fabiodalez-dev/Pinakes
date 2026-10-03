<?php
declare(strict_types=1);

/**
 * A bulk extension must decide on the reservations that exist, not on the ones
 * that existed before it started waiting for a lock.
 *
 * THE BUG: bulkExtend() opened its transaction and only then ran the SELECT
 * that discovers which books are involved. Under REPEATABLE READ — this
 * project's isolation level — the read view is created by the FIRST consistent
 * read inside the transaction, and every later non-locking read in that
 * transaction sees that same view. So the capacity checks that run AFTER the
 * rows are locked, which exist precisely to see what happened during the wait,
 * were reading a snapshot from before it. A reservation confirmed and committed
 * in that window was invisible, and the extension was granted over it.
 *
 * The convention was already written down in this controller: close(), renew()
 * and store() all take that lookup OUTSIDE the transaction, and the comment in
 * close() states the rule. bulkExtend() was the one place that did not follow
 * it, so this suite pins both halves — the mechanism, on real MySQL with two
 * real connections, and the ordering in the method that got it wrong.
 *
 * Sandbox: PINAKES_AUDIT_DB, default <DB_NAME>_audit. The mechanism belongs to
 * MySQL, not to the schema, so the two tables here carry only the columns the
 * ordering actually touches.
 *
 * Run:  php tests/loan-bulk-extend-snapshot.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  OK  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
    }
};

echo "A. The ordering inside bulkExtend()\n";

$source = (string) file_get_contents($root . '/app/Controllers/PrestitiController.php');
$start = strpos($source, 'public function bulkExtend(');
$check($start !== false, 'bulkExtend() is there to inspect');
$body = $start === false ? '' : substr($source, $start, 6000);

$scanAt = strpos($body, 'SELECT id, libro_id FROM prestiti');
$txAt = strpos($body, '$db->begin_transaction()');
$lockAt = strpos($body, 'FOR UPDATE');

$check($scanAt !== false && $txAt !== false && $lockAt !== false,
    'the discovery read, the transaction and the lock are all present');
$check($scanAt !== false && $txAt !== false && $scanAt < $txAt,
    'the discovery read happens BEFORE begin_transaction() — otherwise it opens the read view '
    . 'that every later capacity check is stuck with');
$check($txAt !== false && $lockAt !== false && $txAt < $lockAt,
    'and the lock is taken inside the transaction, where the canonical order still applies');

echo "\nB. Why that ordering matters, on real MySQL\n";

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$liveDb = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$sandbox = getenv('PINAKES_AUDIT_DB') ?: ($liveDb . '_audit');
if ($liveDb === '' || $sandbox === $liveDb || !str_ends_with($sandbox, '_audit')) {
    fwrite(STDERR, "FAIL: refusing to run — the sandbox must be a separate database ending in _audit.\n");
    exit(1);
}

$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');

/**
 * Make sure the sandbox schema exists.
 *
 * On a developer machine the application user is granted only the databases it
 * was created with, so this is a one-off manual step and the message below says
 * so. On CI the run's MySQL user can create schemas, and the suite provisions
 * its own rather than depending on a step someone has to remember to add.
 */
$ensureSandbox = static function (array $env, string $socket, string $liveDb, string $sandbox): bool {
    try {
        $admin = $socket !== '' && file_exists($socket)
            ? new mysqli('localhost', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '', $liveDb, 0, $socket)
            : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '', $liveDb, (int) ($env['DB_PORT'] ?? 3306));
        $admin->query('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $sandbox) . '` '
            . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $admin->close();
        return true;
    } catch (\Throwable $e) {
        return false;
    }
};
$ensureSandbox($env, $socket, $liveDb, $sandbox);

$open = static function () use ($env, $socket, $sandbox): mysqli {
    return $socket !== '' && file_exists($socket)
        ? new mysqli('localhost', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '', $sandbox, 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '', $sandbox, (int) ($env['DB_PORT'] ?? 3306));
};
try {
    $a = $open();
    $b = $open();
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: sandbox '{$sandbox}' unreachable: {$e->getMessage()}\n"
        . "      Create it once:  CREATE DATABASE {$sandbox}; GRANT ALL ON {$sandbox}.* TO '" . ($env['DB_USER'] ?? '') . "'@'localhost';\n");
    exit(1);
}

$cleanup = static function () use ($a): void {
    try {
        $a->query('DROP TABLE IF EXISTS zz_prenotazioni');
        $a->query('DROP TABLE IF EXISTS zz_libri');
    } catch (\Throwable $e) { /* shutting down */ }
};
register_shutdown_function($cleanup);

try {
    $a->query('DROP TABLE IF EXISTS zz_prenotazioni');
    $a->query('DROP TABLE IF EXISTS zz_libri');
    $a->query('CREATE TABLE zz_libri (id INT PRIMARY KEY) ENGINE=InnoDB');
    $a->query('CREATE TABLE zz_prenotazioni (id INT AUTO_INCREMENT PRIMARY KEY, libro_id INT NOT NULL) ENGINE=InnoDB');
    $a->query('INSERT INTO zz_libri VALUES (7)');

    // The ordering as it was: the discovery read inside the transaction.
    $a->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $a->query('START TRANSACTION');
    $a->query('SELECT libro_id FROM zz_prenotazioni WHERE libro_id = 7');      // opens the read view
    $b->query('INSERT INTO zz_prenotazioni (libro_id) VALUES (7)');            // confirmed elsewhere, committed
    $a->query('SELECT id FROM zz_libri WHERE id = 7 FOR UPDATE');              // canonical lock
    $seenBefore = (int) $a->query('SELECT COUNT(*) c FROM zz_prenotazioni WHERE libro_id = 7')->fetch_assoc()['c'];
    $a->query('ROLLBACK');

    $check($seenBefore === 0,
        'a read view opened before the lock is blind to a reservation committed during the wait '
        . "(saw {$seenBefore}) — this is the shape the fix removes");

    $b->query('DELETE FROM zz_prenotazioni');

    // The ordering as it is now: discovery outside, read view opens at the lock.
    $a->query('SELECT libro_id FROM zz_prenotazioni WHERE libro_id = 7');      // outside any transaction
    $a->query('START TRANSACTION');
    $b->query('INSERT INTO zz_prenotazioni (libro_id) VALUES (7)');
    $a->query('SELECT id FROM zz_libri WHERE id = 7 FOR UPDATE');
    $seenAfter = (int) $a->query('SELECT COUNT(*) c FROM zz_prenotazioni WHERE libro_id = 7')->fetch_assoc()['c'];
    $a->query('ROLLBACK');

    $check($seenAfter === 1,
        "with the discovery read outside, the capacity check sees the concurrent reservation (saw {$seenAfter})");
} finally {
    $cleanup();
    $a->close();
    $b->close();
}

echo "\n" . ($fail === 0
    ? "SUCCESS {$pass} behavioural checks\n"
    : "FAILURE {$fail} of " . ($pass + $fail) . " checks failed\n");

exit($fail === 0 ? 0 : 1);

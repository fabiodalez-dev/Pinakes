<?php
declare(strict_types=1);

/**
 * Behavioural guard for the rating normalisation added to migrate_0.7.31.sql.
 *
 * The migration re-adds `chk_lt_rating` (CHECK rating IS NULL OR BETWEEN 1 AND 5)
 * on `libri`. On an install that already has the `rating` column carrying legacy
 * out-of-range values (e.g. a 0 = "unrated" or a 1-10 import), MySQL rejects the
 * `ALTER ... ADD CONSTRAINT` and aborts the whole migration. The fix normalises
 * those rows to NULL BEFORE the ADD:
 *
 *   UPDATE `libri` SET `rating` = NULL WHERE `rating` IS NOT NULL AND `rating` NOT BETWEEN 1 AND 5;
 *
 * This test reproduces the failure and proves the fix against a sandbox table
 * shaped like the relevant part of `libri` (rating TINYINT UNSIGNED, no CHECK):
 *   1. Adding the CHECK with bad rows present FAILS (proves the fix is needed).
 *   2. The normalising UPDATE nulls only out-of-range values (keeps 1-5 + NULL).
 *   3. After it, the CHECK ADD SUCCEEDS.
 *   4. Re-running the UPDATE is a no-op (idempotent).
 * It also asserts the real migration file actually contains the UPDATE before the
 * CHECK ADD, so the behaviour can't drift out of the shipped SQL.
 *
 * Runs against the LIVE local MySQL; touches only the zz_rating_* table.
 *
 * Run:  php tests/migration-0.7.31-rating-cleanup.unit.php
 */

$root = dirname(__DIR__);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    $line = trim($line);
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
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
try {
    $db = ($socket !== '' && file_exists($socket))
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}
$db->set_charset('utf8mb4');

const T = 'zz_rating_libri';
$cleanup = static fn () => $db->query('DROP TABLE IF EXISTS `' . T . '`');

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};
$scalar = static function (string $sql) use ($db) {
    $r = $db->query($sql);
    $row = $r ? $r->fetch_row() : null;
    return $row ? $row[0] : null;
};
$addCheckThrows = static function () use ($db): bool {
    try {
        $db->query('ALTER TABLE `' . T . '` ADD CONSTRAINT `chk_rt` '
            . 'CHECK (`rating` IS NULL OR `rating` BETWEEN 1 AND 5)');
        return false; // succeeded
    } catch (\Throwable $e) {
        return true;  // rejected
    }
};

try {
    // ── Static: the shipped migration normalises rating before adding the CHECK ──
    echo "A. Static — migration file normalises before the CHECK ADD\n";
    $sql = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.31.sql');
    $updatePos = strpos($sql, "UPDATE `libri` SET `rating` = NULL WHERE `rating` IS NOT NULL AND `rating` NOT BETWEEN 1 AND 5");
    $chkPos = strpos($sql, "ADD CONSTRAINT `chk_lt_rating`");
    $check($updatePos !== false, 'migrate_0.7.31.sql contains the rating-normalising UPDATE');
    $check($updatePos !== false && $chkPos !== false && $updatePos < $chkPos,
        'the UPDATE precedes the chk_lt_rating ADD (so bad rows are cleaned first)');

    // ── Behavioural: reproduce the failure, then prove the fix ──
    echo "B. Behavioural — bad data blocks the CHECK; the UPDATE unblocks it\n";
    $cleanup();
    $db->query('CREATE TABLE `' . T . '` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rating TINYINT UNSIGNED NULL
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // 3 = valid, 0 and 7 = out of range, NULL = valid (unrated).
    $db->query('INSERT INTO `' . T . '` (rating) VALUES (3), (0), (7), (NULL)');

    $check($addCheckThrows(), 'adding chk_lt_rating with out-of-range rows FAILS (bug reproduced)');

    // The fix: normalise out-of-range values (the exact statement from the migration).
    $db->query('UPDATE `' . T . '` SET `rating` = NULL WHERE `rating` IS NOT NULL AND `rating` NOT BETWEEN 1 AND 5');

    $check((int) $scalar('SELECT COUNT(*) FROM `' . T . '` WHERE rating = 3') === 1, 'valid rating 3 is preserved');
    $check((int) $scalar('SELECT COUNT(*) FROM `' . T . '` WHERE rating IS NULL') === 3, 'the two out-of-range rows joined the original NULL (3 NULLs)');
    $check((int) $scalar('SELECT COUNT(*) FROM `' . T . '` WHERE rating IS NOT NULL AND rating NOT BETWEEN 1 AND 5') === 0, 'no out-of-range value remains');

    $check(!$addCheckThrows(), 'after the UPDATE, adding chk_lt_rating SUCCEEDS');

    // ── Idempotency: the UPDATE is a no-op on already-clean data ──
    echo "C. Idempotent — re-running the UPDATE touches nothing\n";
    $db->query('UPDATE `' . T . '` SET `rating` = NULL WHERE `rating` IS NOT NULL AND `rating` NOT BETWEEN 1 AND 5');
    $affected = $db->affected_rows;
    $check($affected === 0, 're-running the normalising UPDATE affects 0 rows');
    $check((int) $scalar('SELECT COUNT(*) FROM `' . T . '` WHERE rating = 3') === 1, 'the valid rating still survives the second run');
} finally {
    $cleanup();
    $db->close();
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);

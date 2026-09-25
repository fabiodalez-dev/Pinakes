<?php
declare(strict_types=1);

/**
 * A backup must be restorable. Generated columns are where that quietly stopped
 * being true.
 *
 * THE BUG: dumpDatabaseTo() reads every column with `SELECT *` and writes
 * `INSERT INTO t VALUES (...)` with no column list. MySQL refuses an INSERT that
 * supplies a value for a generated column, so any table that has one produces a
 * dump that cannot be imported. On this schema exactly one column qualifies —
 * `bookclub_member_loans.open_key`, VIRTUAL because a generated column on a
 * table carrying foreign keys has to be — which means the failure appears the
 * moment a library uses member-to-member lending in a reading club, and not
 * before.
 *
 * What makes it dangerous is the ordering: the backup is created successfully
 * and reported as such. The failure surfaces only at restore, which is the one
 * moment nobody has a second copy. The safety backup taken before a restore
 * goes through the same dump path, so the rollback is broken in the same way.
 *
 * Check C is deliberately derived from information_schema rather than naming
 * bookclub_member_loans: the next generated column anyone adds, anywhere, must
 * fail this suite rather than silently re-open the hole.
 *
 * Run:  php tests/backup-generated-columns.unit.php   (exit 0 iff all pass)
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

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — this suite must not skip silently: {$e->getMessage()}\n");
    exit(1);
}

// A name no production table can collide with, and one this suite owns outright
// so dropping it is never someone else's data.
$probe = 'zz_backup_generated_probe';
$replay = 'zz_backup_generated_replay';
$dumpFile = tempnam(sys_get_temp_dir(), 'pk_dumptest_');

$cleanup = static function () use ($db, $probe, $replay, $dumpFile): void {
    foreach ([$probe, $replay] as $t) {
        try { $db->query("DROP TABLE IF EXISTS `{$t}`"); } catch (\Throwable $e) { /* closing down */ }
    }
    if (is_string($dumpFile) && is_file($dumpFile)) {
        @unlink($dumpFile);
    }
};
register_shutdown_function($cleanup);

try {
    $db->query("DROP TABLE IF EXISTS `{$probe}`");
    $db->query(
        "CREATE TABLE `{$probe}` (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            club_id INT NOT NULL,
            book_id INT NOT NULL,
            stato VARCHAR(20) NOT NULL,
            open_key VARCHAR(40) GENERATED ALWAYS AS (CONCAT(club_id, ':', book_id)) VIRTUAL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $db->query("INSERT INTO `{$probe}` (club_id, book_id, stato) VALUES (2, 3, 'offered')");

    echo "A. What the dump writes for a table with a generated column\n";

    $manager = new \App\Support\BackupManager($db, $root);
    $method = new \ReflectionMethod(\App\Support\BackupManager::class, 'dumpDatabaseTo');
    $method->setAccessible(true);
    $method->invoke($manager, $dumpFile);

    $dump = (string) file_get_contents($dumpFile);
    $check($dump !== '', 'the dump was produced');

    $inserts = [];
    foreach (preg_split('/\r?\n/', $dump) as $line) {
        if (str_starts_with($line, "INSERT INTO `{$probe}`")) {
            $inserts[] = $line;
        }
    }
    $check(count($inserts) === 1, 'the probe row is in the dump (' . count($inserts) . ' INSERT)');
    $insert = $inserts[0] ?? '';

    $check(str_contains($insert, '(`id`,') || str_contains($insert, '(`id`,'),
        'the INSERT names its columns instead of relying on table order');
    $check(!str_contains($insert, '`open_key`'),
        'and does NOT name the generated column — MySQL refuses a value for one');
    $check(str_contains($insert, "'offered'"),
        'while every ordinary column is still carried (the fix must not drop data)');

    echo "\nB. The dumped statements actually import\n";

    // The real proof. Replay this table's own statements under a different name:
    // if the dump is valid SQL for MySQL, this succeeds; today it does not.
    $create = '';
    if (preg_match('/^CREATE TABLE `' . preg_quote($probe, '/') . '`.*?;$/ms', $dump, $m) === 1) {
        $create = $m[0];
    }
    $check($create !== '', 'the dump carries the table definition');

    $replayCreate = str_replace("`{$probe}`", "`{$replay}`", $create);
    $replayInsert = str_replace("`{$probe}`", "`{$replay}`", $insert);

    $db->query("DROP TABLE IF EXISTS `{$replay}`");
    $imported = true;
    $importError = '';
    try {
        $db->query($replayCreate);
        $db->query($replayInsert);
    } catch (\Throwable $e) {
        $imported = false;
        $importError = $e->getMessage();
    }
    $check($imported, 'importing the dumped statements succeeds' . ($imported ? '' : " — {$importError}"));

    if ($imported) {
        $restored = $db->query("SELECT club_id, book_id, stato, open_key FROM `{$replay}`")->fetch_assoc();
        $check(($restored['stato'] ?? '') === 'offered', 'the restored row carries its data');
        $check(($restored['open_key'] ?? '') === '2:3',
            'and the generated column is recomputed by the database, which is what generated means');
    } else {
        $check(false, 'the restored row carries its data (not reached: the import failed)');
        $check(false, 'and the generated column is recomputed (not reached: the import failed)');
    }

    echo "\nC. No generated column anywhere in the schema is written to\n";

    // Derived, never a hand-written list: the next generated column added to any
    // table must break this check rather than quietly re-open the hole.
    $generated = [];
    $rows = $db->query(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND GENERATION_EXPRESSION IS NOT NULL AND GENERATION_EXPRESSION <> ''"
    );
    while ($row = $rows->fetch_assoc()) {
        $generated[] = [$row['TABLE_NAME'], $row['COLUMN_NAME']];
    }
    $rows->free();
    $check($generated !== [], 'the schema really does have at least one generated column to guard (' . count($generated) . ')');

    $offenders = [];
    foreach ($generated as [$table, $column]) {
        foreach (preg_split('/\r?\n/', $dump) as $line) {
            if (str_starts_with($line, "INSERT INTO `{$table}`") && str_contains($line, "`{$column}`")) {
                $offenders[] = "{$table}.{$column}";
                break;
            }
            // A column-less INSERT supplies every column positionally, which
            // includes the generated one — the original defect exactly.
            if (str_starts_with($line, "INSERT INTO `{$table}` VALUES")) {
                $offenders[] = "{$table}.{$column} (column-less INSERT)";
                break;
            }
        }
    }
    $check($offenders === [],
        'no dumped INSERT supplies a generated column' . ($offenders === [] ? '' : ': ' . implode(', ', array_unique($offenders))));
} finally {
    $cleanup();
    $db->close();
}

echo "\n" . ($fail === 0
    ? "SUCCESS {$pass} behavioural checks\n"
    : "FAILURE {$fail} of " . ($pass + $fail) . " checks failed\n");

exit($fail === 0 ? 0 : 1);

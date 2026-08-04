<?php
declare(strict_types=1);

/**
 * Behavioural test for migrate_0.7.53-rc.1.sql (performance release — composite
 * indexes for the "newest first" sorts on the public home page and catalogue).
 *
 * Runs the REAL migration file against a sandbox `libri` table (project
 * pattern: same SQL, only the table name rewritten — both the backticked
 * DDL identifier and the quoted INFORMATION_SCHEMA guard literal) and asserts:
 *   - both composite indexes are created with the expected column order
 *     (idx_libri_deleted_created on (deleted_at, created_at) and
 *      idx_libri_genere_deleted_created on (genere_id, deleted_at, created_at));
 *   - a second run raises NO error at all (the INFORMATION_SCHEMA guard makes
 *     the migration intrinsically idempotent, not merely tolerated via the
 *     updater's ignorable-1061 policy) and exactly one index covers each
 *     column list afterwards.
 *
 * Run:  php tests/migration-0.7.53-rc.1.unit.php   (exit 0 iff all pass)
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

echo "A. Real migration against a sandbox libri table\n";

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    // A migration test that silently skips its DB section is a false green in CI.
    fwrite(STDERR, "FAIL: database unreachable — the migration section is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$SB = 'zz_mig_libri_0753';
$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.53-rc.1.sql');
// Retarget the REAL migration at a sandbox table so the test never touches the
// live libri table. The migration references the table both as a backticked
// DDL identifier and as a quoted string literal in the INFORMATION_SCHEMA
// guards — rewrite both, or the guard would probe the live table.
$sandbox = static fn (string $sql): string => str_replace(
    ['`libri`', "'libri'"],
    ["`{$SB}`", "'{$SB}'"],
    $sql
);

// The guard makes the migration intrinsically idempotent: NO error (not even
// the updater-ignorable 1061) is acceptable on any run.
$runMigration = static function () use ($db, $migration, $sandbox): array {
    $unexpected = [];
    mysqli_report(MYSQLI_REPORT_OFF);
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sandbox(preg_replace('/^--.*$/m', '', $migration) ?? $migration)))) as $statement) {
        if ($db->query($statement) === false) {
            $unexpected[] = $db->errno . ': ' . $db->error;
        }
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    return $unexpected;
};

$indexColumns = static function (string $indexName) use ($db, $SB): array {
    $columns = [];
    $result = $db->query(
        "SELECT COLUMN_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$SB}'
           AND INDEX_NAME = '{$indexName}'
         ORDER BY SEQ_IN_INDEX"
    );
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['COLUMN_NAME'];
    }
    return $columns;
};

// Distinct index names covering EXACTLY the given column sequence. A true
// duplicate created by a non-idempotent re-run would appear under a different
// auto-generated name (same name is impossible), so counting by column list —
// not by name — is what actually detects it.
$indexesCoveringColumns = static function (array $columns) use ($db, $SB): int {
    $wanted = implode(',', $columns);
    $row = $db->query(
        "SELECT COUNT(*) AS n FROM (
             SELECT INDEX_NAME,
                    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$SB}'
               AND INDEX_NAME <> 'PRIMARY'
             GROUP BY INDEX_NAME
             HAVING cols = '" . $db->real_escape_string($wanted) . "'
         ) t"
    )->fetch_assoc();
    return (int) ($row['n'] ?? 0);
};

$cleanup = static function () use ($db, $SB): void {
    $db->query("DROP TABLE IF EXISTS {$SB}");
};

try {
    $cleanup();
    $db->query("
        CREATE TABLE `{$SB}` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `titolo` VARCHAR(255) NOT NULL,
            `genere_id` INT DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $errors = $runMigration();
    $check($errors === [], 'first run applies without unexpected errors' . ($errors ? ' (' . implode('; ', $errors) . ')' : ''));

    $check(
        $indexColumns('idx_libri_deleted_created') === ['deleted_at', 'created_at'],
        'idx_libri_deleted_created covers (deleted_at, created_at) in order'
    );
    $check(
        $indexColumns('idx_libri_genere_deleted_created') === ['genere_id', 'deleted_at', 'created_at'],
        'idx_libri_genere_deleted_created covers (genere_id, deleted_at, created_at) in order'
    );

    $errorsSecond = $runMigration();
    $check($errorsSecond === [], 'second run raises no error at all (guard-based idempotency)' . ($errorsSecond ? ' (' . implode('; ', $errorsSecond) . ')' : ''));
    $check(
        $indexesCoveringColumns(['deleted_at', 'created_at']) === 1,
        'exactly one index covers (deleted_at, created_at) after re-run'
    );
    $check(
        $indexesCoveringColumns(['genere_id', 'deleted_at', 'created_at']) === 1,
        'exactly one index covers (genere_id, deleted_at, created_at) after re-run'
    );
} finally {
    $cleanup();
    $db->close();
}

echo "\n";
if ($fail > 0) {
    echo "FAILED: {$fail} (passed {$pass})\n";
    exit(1);
}
echo "ALL {$pass} PASS\n";
exit(0);

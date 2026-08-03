<?php
declare(strict_types=1);

/**
 * Behavioural test for migrate_0.7.53.sql (performance release — composite
 * indexes for the "newest first" sorts on the public home page and catalogue).
 *
 * Runs the REAL migration file against a sandbox `libri` table (project
 * pattern: same SQL, only the table name rewritten) and asserts:
 *   - both composite indexes are created with the expected column order
 *     (idx_libri_deleted_created on (deleted_at, created_at) and
 *      idx_libri_genere_deleted_created on (genere_id, deleted_at, created_at));
 *   - a second run only raises error 1061 (duplicate key name), which the
 *     updater explicitly ignores — i.e. the migration is idempotent under the
 *     updater's error policy and leaves exactly one copy of each index.
 *
 * Run:  php tests/migration-0.7.53.unit.php   (exit 0 iff all pass)
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
$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.53.sql');
// Retarget the REAL migration at a sandbox table so the test never touches the
// live libri table.
$sandbox = static fn (string $sql): string => str_replace('`libri`', "`{$SB}`", $sql);

// Mirror the updater's error policy: 1061 (duplicate key name) is ignorable.
$runMigration = static function () use ($db, $migration, $sandbox): array {
    $unexpected = [];
    mysqli_report(MYSQLI_REPORT_OFF);
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sandbox(preg_replace('/^--.*$/m', '', $migration) ?? $migration)))) as $statement) {
        if ($db->query($statement) === false && $db->errno !== 1061) {
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

$indexCopies = static function (string $indexName) use ($db, $SB): int {
    $row = $db->query(
        "SELECT COUNT(DISTINCT INDEX_NAME) AS n FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$SB}'
           AND INDEX_NAME = '{$indexName}'"
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
    $check($errorsSecond === [], 'second run is idempotent under the updater error policy (only 1061 raised)');
    $check($indexCopies('idx_libri_deleted_created') === 1, 'no duplicate idx_libri_deleted_created after re-run');
    $check($indexCopies('idx_libri_genere_deleted_created') === 1, 'no duplicate idx_libri_genere_deleted_created after re-run');
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

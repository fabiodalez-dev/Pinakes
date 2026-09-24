<?php
declare(strict_types=1);

/**
 * Behavioural test for migrate_0.7.86.sql — make plugin_data's (plugin_id,
 * data_key) pair actually unique so PluginManager::setData() upserts.
 *
 * Runs the REAL migration file against a sandbox table seeded with the OLD
 * schema (plain `KEY idx_plugin_key`, the shape shipped up to 0.7.85) and
 * asserts:
 *   - pre-existing duplicates are collapsed to one row per (plugin_id,
 *     data_key), keeping the NEWEST — the value the last setData() caller
 *     meant to store, not the stale one getData() used to return;
 *   - rows that were never duplicated are untouched;
 *   - the old non-unique KEY is gone and uniq_plugin_key is UNIQUE over
 *     (plugin_id, data_key) in that column order;
 *   - the constraint actually bites: a second INSERT of the same pair now
 *     UPDATEs instead of appending a row, which is the whole point;
 *   - a second run raises NO error at all and leaves exactly one index over
 *     the pair (the INFORMATION_SCHEMA guards make it intrinsically
 *     idempotent, not merely tolerated by the updater's ignorable-1061
 *     policy);
 *   - the migration is a clean no-op on an already-migrated table.
 *
 * Run:  php tests/migration-0.7.86.unit.php   (exit 0 iff all pass)
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

echo "A. Real migration against a sandbox plugin_data table\n";

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

$SB = 'zz_mig_plugin_data_0786';
$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.86.sql');

// Retarget the REAL migration at a sandbox table so the test never touches the
// live plugin_data. The migration names the table both as a backticked DDL
// identifier and as a quoted literal inside the INFORMATION_SCHEMA guards —
// rewrite both, or the guards would probe the live table and the ALTERs would
// run against it.
$sandbox = static fn (string $sql): string => str_replace(
    ['`plugin_data`', "'plugin_data'"],
    ["`{$SB}`", "'{$SB}'"],
    $sql
);

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

$indexIsUnique = static function (string $indexName) use ($db, $SB): bool {
    $row = $db->query(
        "SELECT MIN(NON_UNIQUE) AS nu FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$SB}'
           AND INDEX_NAME = '{$indexName}'"
    )->fetch_assoc();
    return $row !== null && $row['nu'] !== null && (int) $row['nu'] === 0;
};

// Count DISTINCT index names covering exactly (plugin_id, data_key). A
// non-idempotent re-run would add a second one under a different name, so
// counting by column list — not by name — is what actually detects it.
$indexesOverPair = static function () use ($db, $SB): int {
    $row = $db->query(
        "SELECT COUNT(*) AS n FROM (
             SELECT INDEX_NAME,
                    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$SB}'
               AND INDEX_NAME <> 'PRIMARY'
             GROUP BY INDEX_NAME
             HAVING cols = 'plugin_id,data_key'
         ) t"
    )->fetch_assoc();
    return (int) ($row['n'] ?? 0);
};

$valueOf = static function (int $pluginId, string $key) use ($db, $SB): ?string {
    $row = $db->query(
        "SELECT data_value FROM `{$SB}`
         WHERE plugin_id = {$pluginId} AND data_key = '" . $db->real_escape_string($key) . "'"
    )->fetch_assoc();
    return $row === null ? null : (string) $row['data_value'];
};

$rowCount = static function () use ($db, $SB): int {
    return (int) $db->query("SELECT COUNT(*) AS n FROM `{$SB}`")->fetch_assoc()['n'];
};

$cleanup = static function () use ($db, $SB): void {
    $db->query("DROP TABLE IF EXISTS `{$SB}`");
};

// The pre-0.7.86 shape, verbatim from installer/database/schema.sql as it
// shipped: idx_plugin_key is a plain KEY, which is exactly why setData()'s
// ON DUPLICATE KEY UPDATE never matched. The FK is dropped from the sandbox
// (it would require a plugins row) — it plays no part in this migration.
$createOldSchema = static function () use ($db, $SB): void {
    $db->query("
        CREATE TABLE `{$SB}` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_id` int unsigned NOT NULL,
            `data_key` varchar(255) NOT NULL,
            `data_value` longtext,
            `data_type` varchar(50) NOT NULL DEFAULT 'string',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_plugin_key` (`plugin_id`,`data_key`),
            KEY `idx_plugin_id` (`plugin_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
};

try {
    $cleanup();
    $createOldSchema();

    // Seed the exact damage the bug produces: three writes of the same key
    // land as three rows, oldest first. 'newest' is what the last setData()
    // caller stored and what getData() SHOULD have been returning.
    $db->query("INSERT INTO `{$SB}` (plugin_id, data_key, data_value, data_type) VALUES
        (7, 'state', 'oldest',  'string'),
        (7, 'state', 'middle',  'string'),
        (7, 'state', 'newest',  'string'),
        (7, 'other', 'only',    'string'),
        (9, 'state', 'other-plugin', 'string')");

    $check($rowCount() === 5, 'pre-migration: the old schema really does allow duplicates (5 rows, 3 sharing a pair)');
    $check($valueOf(7, 'state') === 'oldest', 'pre-migration: the first row wins the read — the bug, reproduced');

    $errors = $runMigration();
    $check($errors === [], 'first run raises no error (' . (implode(' | ', $errors) ?: 'none') . ')');

    $check($rowCount() === 3, 'duplicates collapsed: 5 rows -> 3');
    $check($valueOf(7, 'state') === 'newest', 'the surviving row is the NEWEST, not the stale one the read used to return');
    $check($valueOf(7, 'other') === 'only', 'a never-duplicated row of the same plugin is untouched');
    $check($valueOf(9, 'state') === 'other-plugin', 'the same key under a different plugin is untouched');

    $check($indexColumns('idx_plugin_key') === [], 'the old non-unique KEY is gone');
    $check($indexColumns('uniq_plugin_key') === ['plugin_id', 'data_key'], 'uniq_plugin_key covers (plugin_id, data_key) in that order');
    $check($indexIsUnique('uniq_plugin_key'), 'uniq_plugin_key is UNIQUE');
    $check($indexColumns('idx_plugin_id') === ['plugin_id'], 'the unrelated idx_plugin_id survives');

    // The point of the whole migration: the upsert PluginManager::setData()
    // has always written now behaves as an upsert.
    $db->query("INSERT INTO `{$SB}` (plugin_id, data_key, data_value, data_type)
                VALUES (7, 'state', 'written-later', 'string')
                ON DUPLICATE KEY UPDATE data_value = VALUES(data_value)");
    $check($rowCount() === 3, 'setData()-shaped upsert no longer appends a row');
    $check($valueOf(7, 'state') === 'written-later', 'setData()-shaped upsert now actually overwrites');

    // Idempotency: re-running must be silent and must not add a second index
    // over the same pair.
    $errorsSecond = $runMigration();
    $check($errorsSecond === [], 'second run raises no error (' . (implode(' | ', $errorsSecond) ?: 'none') . ')');
    $check($indexesOverPair() === 1, 'exactly one index covers (plugin_id, data_key) after two runs');
    $check($rowCount() === 3, 'second run leaves the rows alone');
    $check($valueOf(7, 'state') === 'written-later', 'second run does not resurrect a collapsed value');

    echo "\nB. Clean no-op on a table that never had duplicates\n";

    $cleanup();
    $createOldSchema();
    $db->query("INSERT INTO `{$SB}` (plugin_id, data_key, data_value, data_type)
                VALUES (1, 'a', 'x', 'string'), (1, 'b', 'y', 'string')");
    $errorsClean = $runMigration();
    $check($errorsClean === [], 'no-duplicate table migrates without error (' . (implode(' | ', $errorsClean) ?: 'none') . ')');
    $check($rowCount() === 2, 'no rows are lost when there was nothing to collapse');
    $check($indexIsUnique('uniq_plugin_key'), 'the constraint still lands on a clean table');
} finally {
    $cleanup();
    $db->close();
}

echo "\n" . ($fail === 0
    ? "SUCCESS {$pass} behavioural checks\n"
    : "FAILURE {$fail} of " . ($pass + $fail) . " checks failed\n");

exit($fail === 0 ? 0 : 1);

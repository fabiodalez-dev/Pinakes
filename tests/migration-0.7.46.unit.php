<?php
declare(strict_types=1);

/**
 * Behavioural test for migrate_0.7.46.sql (issue #301 — optional automatic
 * approval of immediate loan requests).
 *
 * Runs the REAL migration file against a sandbox `system_settings` table
 * (project pattern: same SQL, only the table name rewritten) and asserts:
 *   - the loans/auto_approve_requests setting is created with the safe default '0'
 *     (existing installs keep the manual approval queue);
 *   - a second run is idempotent AND does not clobber an administrator's choice —
 *     ON DUPLICATE KEY UPDATE keeps the stored value, so a '1' the admin set is
 *     preserved rather than reset to '0';
 *   - no duplicate row is created (UNIQUE(category, setting_key)).
 *
 * Run:  php tests/migration-0.7.46.unit.php   (exit 0 iff all pass)
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

echo "A. Real migration against a sandbox system_settings table\n";

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

$SB = 'zz_mig_settings_0746';
$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.46.sql');
// Retarget the REAL migration at a sandbox table so the test never touches the
// live system_settings.
$sandbox = static fn (string $sql): string => str_replace('`system_settings`', "`{$SB}`", $sql);

$runMigration = static function () use ($db, $migration, $sandbox): void {
    // array_filter drops the empty tail after the final ';', so every remaining
    // statement is non-empty.
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sandbox(preg_replace('/^--.*$/m', '', $migration) ?? $migration)))) as $statement) {
        $db->query($statement);
    }
};

$readSetting = static function () use ($db, $SB): ?string {
    $row = $db->query("SELECT setting_value FROM {$SB} WHERE category='loans' AND setting_key='auto_approve_requests'")->fetch_assoc();
    return $row === null ? null : (string) $row['setting_value'];
};

$cleanup = static function () use ($db, $SB): void {
    $db->query("DROP TABLE IF EXISTS {$SB}");
};

try {
    $cleanup();

    $db->query("CREATE TABLE {$SB} (
        id int NOT NULL AUTO_INCREMENT,
        category varchar(50) NOT NULL,
        setting_key varchar(100) NOT NULL,
        setting_value text,
        description text,
        created_at timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_setting (category, setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Pre-migration: setting absent.
    $check($readSetting() === null, 'auto_approve_requests absent before migration');

    $runMigration();

    // Fresh install / upgrade: the setting is created with the safe default '0'.
    $check($readSetting() === '0', "migration creates loans/auto_approve_requests = '0' (manual approval stays the default)");
    $count = (int) $db->query("SELECT COUNT(*) FROM {$SB} WHERE category='loans' AND setting_key='auto_approve_requests'")->fetch_row()[0];
    $check($count === 1, 'exactly one settings row created');

    // An administrator turns auto-approval ON.
    $db->query("UPDATE {$SB} SET setting_value='1' WHERE category='loans' AND setting_key='auto_approve_requests'");

    // Re-running the migration must NOT reset that choice (ON DUPLICATE KEY UPDATE
    // keeps the stored value) and must not duplicate the row.
    $runMigration();
    $check($readSetting() === '1', "second run preserves the admin's '1' (does not reset to '0')");
    $count = (int) $db->query("SELECT COUNT(*) FROM {$SB} WHERE category='loans' AND setting_key='auto_approve_requests'")->fetch_row()[0];
    $check($count === 1, 'second run does not duplicate the row (idempotent upsert)');

    $cleanup();
} catch (\Throwable $e) {
    $fail++;
    echo "  FAIL exception: {$e->getMessage()}\n";
    $cleanup();
}

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);

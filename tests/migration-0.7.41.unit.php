<?php
declare(strict_types=1);

/**
 * Behavioural test for migrate_0.7.41.sql (issue #279 — Danish da_DK language
 * registration on the upgrade path).
 *
 * Runs the REAL migration file against a sandbox `languages` table (project
 * pattern: same SQL, only the table name rewritten) and asserts:
 *   - da_DK is inserted with the canonical metadata (native_name, is_active,
 *     key counts, completion, translation_file),
 *   - pre-existing locales are untouched,
 *   - a second run is a no-op (idempotency — the ON DUPLICATE KEY UPDATE upsert),
 *   - a deactivated da_DK is re-activated (is_active flips back to 1).
 *
 * Run:  php tests/migration-0.7.41.unit.php   (exit 0 iff all pass)
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

echo "A. Real migration against a sandbox languages table\n";

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
try {
    $socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    // A migration test that silently skips its DB section is a false green in
    // CI (both ci-quality and ci-e2e provision MySQL). Fail loudly instead.
    fwrite(STDERR, "FAIL: database unreachable — the migration section is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$SB = 'zz_mig_languages_0741';
$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.41.sql');

// Retarget the REAL migration at a sandbox table name so the test never
// collides with a live `languages` table.
$sandbox = static fn (string $sql): string => str_replace('`languages`', "`{$SB}`", $sql);

$runMigration = static function () use ($db, $migration, $sandbox): void {
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sandbox(preg_replace('/^--.*$/m', '', $migration) ?? $migration)))) as $statement) {
        if ($statement !== '') {
            $db->query($statement);
        }
    }
};

$cleanup = static function () use ($db, $SB): void {
    $db->query("DROP TABLE IF EXISTS {$SB}");
};

try {
    $cleanup();

    // Recreate the languages schema (the columns the migration writes + the
    // UNIQUE(code) key the ON DUPLICATE KEY UPDATE relies on).
    $db->query("CREATE TABLE {$SB} (
        id int NOT NULL AUTO_INCREMENT,
        code varchar(10) NOT NULL,
        name varchar(100) NOT NULL,
        native_name varchar(100) NOT NULL,
        flag_emoji varchar(10) DEFAULT NULL,
        is_default tinyint(1) DEFAULT 0,
        is_active tinyint(1) DEFAULT 1,
        translation_file varchar(255) DEFAULT NULL,
        total_keys int DEFAULT 0,
        translated_keys int DEFAULT 0,
        completion_percentage decimal(5,2) DEFAULT 0.00,
        created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Pre-0.7.41 install state: existing locales present, da_DK absent.
    $db->query("INSERT INTO {$SB} (code, name, native_name, is_default, is_active, translation_file) VALUES
        ('it_IT','Italian','Italiano',1,1,'locale/it_IT.json'),
        ('en_US','English','English',0,1,'locale/en_US.json')");

    $before = (int) $db->query("SELECT COUNT(*) FROM {$SB} WHERE code='da_DK'")->fetch_row()[0];
    $check($before === 0, 'da_DK absent before migration (pre-0.7.41 state)');

    $runMigration();

    $row = $db->query("SELECT native_name, is_active, total_keys, translated_keys, completion_percentage, translation_file FROM {$SB} WHERE code='da_DK'")->fetch_assoc();
    $check($row !== null, 'da_DK row inserted by migration');
    $check($row !== null && $row['native_name'] === 'Dansk', 'native_name = Dansk');
    $check($row !== null && (int) $row['is_active'] === 1, 'da_DK is active');
    $check($row !== null && (int) $row['total_keys'] === 6579 && (int) $row['translated_keys'] === 6579, 'key counts = 6579/6579');
    $check($row !== null && (float) $row['completion_percentage'] === 100.00, 'completion_percentage = 100.00');
    $check($row !== null && $row['translation_file'] === 'locale/da_DK.json', 'translation_file = locale/da_DK.json');

    $others = (int) $db->query("SELECT COUNT(*) FROM {$SB} WHERE code IN ('it_IT','en_US')")->fetch_row()[0];
    $check($others === 2, 'pre-existing locales left untouched');

    // Idempotency: a second run must not error and must not duplicate.
    $runMigration();
    $dupes = (int) $db->query("SELECT COUNT(*) FROM {$SB} WHERE code='da_DK'")->fetch_row()[0];
    $check($dupes === 1, 'second run does not duplicate da_DK (idempotent upsert)');

    // Re-activation: a manually deactivated da_DK is switched back on.
    $db->query("UPDATE {$SB} SET is_active=0 WHERE code='da_DK'");
    $runMigration();
    $reactivated = (int) $db->query("SELECT is_active FROM {$SB} WHERE code='da_DK'")->fetch_row()[0];
    $check($reactivated === 1, 'migration re-activates a deactivated da_DK');

    $cleanup();
} catch (\Throwable $e) {
    $fail++;
    echo "  FAIL exception: {$e->getMessage()}\n";
    $cleanup();
}

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);

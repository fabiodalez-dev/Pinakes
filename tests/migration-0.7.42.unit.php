<?php
declare(strict_types=1);

/**
 * Behavioural test for migrate_0.7.42.sql — bumps the da_DK translation key
 * count from the pre-0.7.42 value (6607) to the current shipped count after
 * v0.7.42 added 4 UI strings, for installs that already applied migrate_0.7.41
 * at the old count.
 *
 * Runs the REAL migration file against a sandbox `languages` table (project
 * pattern: same SQL, only the table name rewritten) and asserts:
 *   - a da_DK row still at the old count is bumped to the current file count,
 *   - completion stays 100.00,
 *   - other locales are untouched,
 *   - a second run is a no-op (idempotent — guarded by total_keys < 6611),
 *   - a row ALREADY at the current count is not lowered/rewritten.
 *
 * Run:  php tests/migration-0.7.42.unit.php   (exit 0 iff all pass)
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
    fwrite(STDERR, "FAIL: database unreachable — the migration section is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$SB = 'zz_mig_languages_0742';
$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.42.sql');
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

// The count the migration targets — read from the shipped locale file so the
// test tracks the real key count rather than a stale literal.
$expectedKeys = count(json_decode((string) file_get_contents($root . '/locale/da_DK.json'), true, 512, JSON_THROW_ON_ERROR));

try {
    $cleanup();

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

    // Post-0.7.41 install state: da_DK present at the OLD count of 6607.
    $db->query("INSERT INTO {$SB} (code, name, native_name, is_default, is_active, translation_file, total_keys, translated_keys, completion_percentage) VALUES
        ('it_IT','Italian','Italiano',1,1,'locale/it_IT.json',6611,6611,100.00),
        ('da_DK','Danish','Dansk',0,1,'locale/da_DK.json',6607,6607,100.00)");

    $runMigration();

    $row = $db->query("SELECT total_keys, translated_keys, completion_percentage FROM {$SB} WHERE code='da_DK'")->fetch_assoc();
    $check($row !== null && (int) $row['total_keys'] === $expectedKeys, "da_DK total_keys bumped to {$expectedKeys}");
    $check($row !== null && (int) $row['translated_keys'] === $expectedKeys, "da_DK translated_keys bumped to {$expectedKeys}");
    $check($row !== null && (float) $row['completion_percentage'] === 100.00, 'completion_percentage stays 100.00');

    $it = (int) $db->query("SELECT total_keys FROM {$SB} WHERE code='it_IT'")->fetch_row()[0];
    $check($it === 6611, 'other locales untouched (it_IT unchanged)');

    // Idempotency: a second run is a no-op (guarded by total_keys < 6611).
    $db->query("UPDATE {$SB} SET updated_at='2000-01-01 00:00:00' WHERE code='da_DK'");
    $runMigration();
    $stamp = $db->query("SELECT updated_at FROM {$SB} WHERE code='da_DK'")->fetch_row()[0];
    $check($stamp === '2000-01-01 00:00:00', 'second run is a no-op (row already at target not rewritten)');

    $cleanup();
} catch (\Throwable $e) {
    $fail++;
    echo "  FAIL exception: {$e->getMessage()}\n";
    $cleanup();
}

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);

<?php
declare(strict_types=1);

/**
 * Behavioural and wiring coverage for issue #360 / migrate_0.7.62-rc.1.sql.
 * Runs the real migration against a sandbox prestiti table and verifies the
 * recall-tracking columns, their idempotency, and the release wiring.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\Updater;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

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
        ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — migration test is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$sandboxTable = 'zz_mig_prestiti_0762';
$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.62-rc.1.sql');
// The migration references the table both backticked (ALTER) and as a quoted
// string (INFORMATION_SCHEMA lookup): rewrite both onto the sandbox.
$sandboxMigration = static fn(string $sql): string => str_replace(
    ['`prestiti`', "'prestiti'"],
    ["`{$sandboxTable}`", "'{$sandboxTable}'"],
    $sql
);
$runMigration = static function () use ($db, $migration, $sandboxMigration): void {
    $withoutComments = preg_replace('/^--.*$/m', '', $migration) ?? $migration;
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sandboxMigration($withoutComments)))) as $statement) {
        $db->query($statement);
    }
};
$cleanup = static function () use ($db, $sandboxTable): void {
    $db->query("DROP TABLE IF EXISTS `{$sandboxTable}`");
};

echo "A. Real migration on a sandbox prestiti table\n";
try {
    $cleanup();
    // Minimal prestiti shape: the migration anchors the new columns AFTER
    // overdue_notification_sent, so that column must exist in the sandbox.
    $db->query("CREATE TABLE `{$sandboxTable}` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `stato` VARCHAR(20) NOT NULL DEFAULT 'in_corso',
        `warning_sent` TINYINT(1) DEFAULT 0,
        `overdue_notification_sent` TINYINT(1) DEFAULT 0,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $runMigration();

    $count = $db->query(
        "SELECT DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '{$sandboxTable}'
            AND COLUMN_NAME = 'recall_count'"
    )->fetch_assoc();
    $check($count !== null && $count['DATA_TYPE'] === 'int', 'migration adds the recall counter');
    $check(
        $count !== null
            && $count['IS_NULLABLE'] === 'NO'
            && $count['COLUMN_DEFAULT'] !== null
            && (int) $count['COLUMN_DEFAULT'] === 0,
        'counter is non-null and starts at zero'
    );

    $lastAt = $db->query(
        "SELECT DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '{$sandboxTable}'
            AND COLUMN_NAME = 'last_recall_at'"
    )->fetch_assoc();
    $check(
        $lastAt !== null && $lastAt['DATA_TYPE'] === 'datetime' && $lastAt['IS_NULLABLE'] === 'YES' && $lastAt['COLUMN_DEFAULT'] === null,
        'last_recall_at is a nullable datetime with no default'
    );

    $db->query("INSERT INTO `{$sandboxTable}` (`stato`) VALUES ('in_ritardo')");
    $row = $db->query("SELECT recall_count, last_recall_at FROM `{$sandboxTable}`")->fetch_assoc();
    $check((int) $row['recall_count'] === 0 && $row['last_recall_at'] === null, 'existing loans start with no recalls');

    // Simulate a recorded recall, then re-run: idempotency must not reset it.
    $db->query("UPDATE `{$sandboxTable}` SET recall_count = 2, last_recall_at = '2026-01-05 10:00:00'");
    $runMigration();
    $row = $db->query("SELECT recall_count, last_recall_at FROM `{$sandboxTable}`")->fetch_assoc();
    $check(
        (int) $row['recall_count'] === 2 && $row['last_recall_at'] === '2026-01-05 10:00:00',
        'migration is idempotent and preserves recall history'
    );
} catch (Throwable $e) {
    $failed++;
    echo "  FAIL exception: {$e->getMessage()}\n";
} finally {
    $cleanup();
    $db->close();
}

echo "B. Release wiring\n";
$schema = (string) file_get_contents($root . '/installer/database/schema.sql');
$check(
    str_contains($schema, '`recall_count` int NOT NULL DEFAULT') && str_contains($schema, '`last_recall_at` datetime DEFAULT NULL'),
    'fresh-install schema includes both recall columns'
);

// Pre-migration installs are covered by the runtime self-healing path — the
// same belt-and-braces used for warning_sent/overdue_notification_sent.
$notificationService = (string) file_get_contents($root . '/app/Support/NotificationService.php');
$check(
    str_contains($notificationService, "SHOW COLUMNS FROM prestiti LIKE 'recall_count'")
        && str_contains($notificationService, "SHOW COLUMNS FROM prestiti LIKE 'last_recall_at'"),
    'addNotificationColumns self-heals both columns at runtime'
);

// Exercise the exact production predicate called by Updater::runMigrations():
// the migration is named after the RC that ships it (see migrate_0.7.59-rc.1),
// so it must fire on every upgrade path that needs the columns and never
// re-fire once the RC installed.
$mig = '0.7.62-rc.1';
$check(version_compare('0.7.62-rc.1', '0.7.62', '<'), 'version_compare orders the RC below its stable (prerelease semantics)');
$check(Updater::shouldRunMigration($mig, '0.7.61', '0.7.62-rc.1'), 'Updater runs it on 0.7.61 -> 0.7.62-rc.1 (RC upgrade)');
$check(Updater::shouldRunMigration($mig, '0.7.61', '0.7.62'), 'Updater runs it on 0.7.61 -> 0.7.62 stable (RC never installed)');
$check(!Updater::shouldRunMigration($mig, '0.7.62-rc.1', '0.7.62'), 'Updater does NOT re-run it on 0.7.62-rc.1 -> 0.7.62 stable (already applied; migration is idempotent as a backstop)');

echo "\n{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);

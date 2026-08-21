<?php
declare(strict_types=1);

/**
 * Behavioural and wiring coverage for migrate_0.7.64.sql (circulation review
 * follow-up): the pickup_notification_sent claim/retry flag for the
 * "ready for pickup" email. Runs the real migration against a sandbox
 * prestiti table and verifies the column, its idempotency, and the release
 * wiring (fresh schema + runtime self-healing + Updater predicate).
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

// Random suffix: concurrent runs (or a leftover table from an aborted one)
// must never collide with — or drop — anything but this run's own sandbox.
$sandboxTable = 'zz_mig_prestiti_0764_' . bin2hex(random_bytes(6));
$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.64.sql');
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
    // Minimal prestiti shape: the migration anchors the new column AFTER
    // overdue_notification_sent, so that column must exist in the sandbox.
    $db->query("CREATE TABLE `{$sandboxTable}` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `stato` VARCHAR(20) NOT NULL DEFAULT 'in_corso',
        `warning_sent` TINYINT(1) DEFAULT 0,
        `overdue_notification_sent` TINYINT(1) DEFAULT 0,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Seed a loan BEFORE the migration so the assertions below cover the
    // backfill applied to pre-existing rows, not only freshly inserted ones.
    $db->query("INSERT INTO `{$sandboxTable}` (`stato`) VALUES ('da_ritirare')");

    $runMigration();

    $column = $db->query(
        "SELECT DATA_TYPE, COLUMN_DEFAULT
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '{$sandboxTable}'
            AND COLUMN_NAME = 'pickup_notification_sent'"
    )->fetch_assoc();
    $check($column !== null && $column['DATA_TYPE'] === 'tinyint', 'migration adds the pickup-notification flag');
    $check(
        $column !== null
            && $column['COLUMN_DEFAULT'] !== null
            && (int) $column['COLUMN_DEFAULT'] === 0,
        'flag defaults to zero (never announced yet)'
    );

    $row = $db->query("SELECT pickup_notification_sent FROM `{$sandboxTable}`")->fetch_assoc();
    $check((int) $row['pickup_notification_sent'] === 0, 'pre-existing loans are backfilled as not-yet-announced');

    // Simulate a claimed announcement, then re-run: idempotency must not reset it.
    $db->query("UPDATE `{$sandboxTable}` SET pickup_notification_sent = 1");
    $runMigration();
    $row = $db->query("SELECT pickup_notification_sent FROM `{$sandboxTable}`")->fetch_assoc();
    $check(
        (int) $row['pickup_notification_sent'] === 1,
        'migration is idempotent and preserves the claimed flag'
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
    str_contains($schema, '`pickup_notification_sent` tinyint(1) DEFAULT'),
    'fresh-install schema includes the pickup-notification flag'
);

// Pre-migration installs are covered by the runtime self-healing paths — the
// same belt-and-braces used for warning_sent/overdue_notification_sent.
$notificationService = (string) file_get_contents($root . '/app/Support/NotificationService.php');
$check(
    str_contains($notificationService, "SHOW COLUMNS FROM prestiti LIKE 'pickup_notification_sent'"),
    'addNotificationColumns self-heals the column at runtime'
);
$maintenanceService = (string) file_get_contents($root . '/app/Support/MaintenanceService.php');
$check(
    str_contains($maintenanceService, "SHOW COLUMNS FROM prestiti LIKE 'pickup_notification_sent'"),
    'MaintenanceService self-heals the column before its lifecycle UPDATEs'
);

// Exercise the exact production predicate called by Updater::runMigrations().
$mig = '0.7.64';
$check(Updater::shouldRunMigration($mig, '0.7.63', '0.7.64'), 'Updater runs it on 0.7.63 -> 0.7.64');
$check(Updater::shouldRunMigration($mig, '0.7.62', '0.7.64'), 'Updater runs it on 0.7.62 -> 0.7.64 (skipped release)');
$check(!Updater::shouldRunMigration($mig, '0.7.64', '0.7.65'), 'Updater does NOT re-run it once 0.7.64 is installed');

echo "\n{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);

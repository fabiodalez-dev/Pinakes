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
$sandboxSuffix = bin2hex(random_bytes(6));
$sandboxTable = 'zz_mig_prestiti_0764_' . $sandboxSuffix;
$sandboxTrigger = 'zz_mig_trg_0764_' . $sandboxSuffix;
$sandboxMarker = 'pickup_notification_backfill_0_7_64_' . $sandboxSuffix;
$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.64.sql');
// The migration references the table both backticked (ALTER) and as a quoted
// string (INFORMATION_SCHEMA lookup): rewrite both onto the sandbox.
$sandboxMigration = static fn(string $sql): string => str_replace(
    ['`prestiti`', "'prestiti'", 'pickup_notification_backfill_0_7_64'],
    ["`{$sandboxTable}`", "'{$sandboxTable}'", $sandboxMarker],
    $sql
);
$runMigration = static function () use ($db, $migration, $sandboxMigration): void {
    $withoutComments = preg_replace('/^--.*$/m', '', $migration) ?? $migration;
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sandboxMigration($withoutComments)))) as $statement) {
        $db->query($statement);
    }
};
$cleanup = static function () use ($db, $sandboxTable, $sandboxMarker): void {
    $db->query("DROP TABLE IF EXISTS `{$sandboxTable}`");
    $stmt = $db->prepare("DELETE FROM system_settings WHERE category = 'migrations' AND setting_key = ?");
    $stmt->bind_param('s', $sandboxMarker);
    $stmt->execute();
    $stmt->close();
};

echo "A. Real migration on a sandbox prestiti table\n";
try {
    $cleanup();
    // Minimal legacy prestiti shape: notification columns may all be absent.
    // In particular warning_sent/overdue_notification_sent are deliberately
    // absent: the migration must not depend on a runtime self-heal having run
    // first or on an AFTER clause naming either legacy column.
    $db->query("CREATE TABLE `{$sandboxTable}` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `stato` VARCHAR(20) NOT NULL DEFAULT 'in_corso',
        `attivo` TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Seed a loan BEFORE the migration so the assertions below cover the
    // safe historical default applied to pre-existing rows, not only freshly
    // inserted ones.
    $db->query("INSERT INTO `{$sandboxTable}` (`stato`) VALUES ('da_ritirare'), ('in_corso')");

    // A legacy circulation trigger may reject every UPDATE on an inconsistent
    // loan even when only notification metadata changes. The migration must
    // therefore initialize history through DDL defaults, never row DML.
    $db->query("CREATE TRIGGER `{$sandboxTrigger}` BEFORE UPDATE ON `{$sandboxTable}`
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'legacy trigger rejected UPDATE'");

    $runMigration();
    $db->query("DROP TRIGGER `{$sandboxTrigger}`");

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
    $protocolColumns = (int) $db->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$sandboxTable}'
            AND COLUMN_NAME IN ('pickup_notification_claim_token', 'pickup_notification_last_attempt_at')"
    )->fetch_row()[0];
    $check($protocolColumns === 2, 'migration adds claim ownership and fair-retry metadata');

    $rows = $db->query("SELECT stato, pickup_notification_sent FROM `{$sandboxTable}` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $check(
        count($rows) === 2
            && $rows[0]['stato'] === 'da_ritirare'
            && (int) $rows[0]['pickup_notification_sent'] === 1,
        'pre-existing ready-for-pickup loans are treated as already announced'
    );
    $check(
        count($rows) === 2
            && $rows[1]['stato'] === 'in_corso'
            && (int) $rows[1]['pickup_notification_sent'] === 1,
        'all historical rows are safely initialized without firing legacy UPDATE triggers'
    );

    // A row created after the migration must retain DEFAULT 0 so the new
    // claim/retry pipeline announces it normally.
    $db->query("INSERT INTO `{$sandboxTable}` (`stato`) VALUES ('da_ritirare')");
    $newRow = $db->query("SELECT pickup_notification_sent FROM `{$sandboxTable}` ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $check((int) $newRow['pickup_notification_sent'] === 0, 'new ready-for-pickup loans start unannounced');

    // Simulate a claimed announcement, then re-run: idempotency must not reset it.
    $db->query("UPDATE `{$sandboxTable}` SET pickup_notification_sent = 1 WHERE stato = 'in_corso'");
    $runMigration();
    $row = $db->query("SELECT pickup_notification_sent FROM `{$sandboxTable}` WHERE stato = 'in_corso'")->fetch_assoc();
    $check(
        (int) $row['pickup_notification_sent'] === 1,
        'migration is idempotent and preserves the claimed flag'
    );
    $marker = $db->prepare("SELECT setting_value FROM system_settings WHERE category = 'migrations' AND setting_key = ?");
    $marker->bind_param('s', $sandboxMarker);
    $marker->execute();
    $markerValue = (string) ($marker->get_result()->fetch_row()[0] ?? '');
    $marker->close();
    $check($markerValue === 'done', 'resumable backfill marker is completed');
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
$check(
    str_contains($schema, '`pickup_notification_claim_token` char(32)')
        && str_contains($schema, '`pickup_notification_last_attempt_at` datetime'),
    'fresh-install schema includes claim ownership and fair-retry metadata'
);

// Pre-migration installs are covered by the runtime self-healing paths — the
// same belt-and-braces used for warning_sent/overdue_notification_sent.
$notificationService = (string) file_get_contents($root . '/app/Support/NotificationService.php');
$pickupSchema = (string) file_get_contents($root . '/app/Support/PickupNotificationSchema.php');
$check(
    str_contains($notificationService, 'PickupNotificationSchema::ensure($this->db)')
        && str_contains($pickupSchema, 'ADD COLUMN pickup_notification_sent TINYINT(1) DEFAULT {$historicalDefault}'),
    'NotificationService delegates to the resumable runtime schema helper'
);
$maintenanceService = (string) file_get_contents($root . '/app/Support/MaintenanceService.php');
$check(
    str_contains($maintenanceService, 'PickupNotificationSchema::ensure($this->db)'),
    'MaintenanceService ensures the schema before its lifecycle UPDATEs'
);

// Exercise the exact production predicate called by Updater::runMigrations().
$mig = '0.7.64';
$check(Updater::shouldRunMigration($mig, '0.7.63', '0.7.64'), 'Updater runs it on 0.7.63 -> 0.7.64');
$check(Updater::shouldRunMigration($mig, '0.7.62', '0.7.64'), 'Updater runs it on 0.7.62 -> 0.7.64 (skipped release)');
$check(!Updater::shouldRunMigration($mig, '0.7.64', '0.7.65'), 'Updater does NOT re-run it once 0.7.64 is installed');

echo "\n{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);

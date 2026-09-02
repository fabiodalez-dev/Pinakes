<?php
declare(strict_types=1);

/**
 * Behavioural coverage for the issue #374 audit-retention migration.
 * The real SQL is retargeted to per-run sandbox tables, then exercised twice.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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

$suffix = bin2hex(random_bytes(5));
$usersTable = 'zz_m73_users_' . $suffix;
$auditTable = 'zz_m73_audit_' . $suffix;
$foreignKey = 'zz_m73_fk_' . $suffix;
$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.73-rc.1.sql');
$migration = str_replace(
    ['`log_modifiche`', "'log_modifiche'", '`utenti`', "'utenti'", '`log_modifiche_ibfk_1`'],
    ["`{$auditTable}`", "'{$auditTable}'", "`{$usersTable}`", "'{$usersTable}'", "`{$foreignKey}`"],
    $migration
);
$migration = preg_replace('/^\s*--.*$/m', '', $migration) ?? $migration;

$runMigration = static function () use ($db, $migration): void {
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $migration))) as $statement) {
        $db->query($statement);
    }
};
$cleanup = static function () use ($db, $auditTable, $usersTable): void {
    $db->query("DROP TABLE IF EXISTS `{$auditTable}`");
    $db->query("DROP TABLE IF EXISTS `{$usersTable}`");
};

$passed = 0;
$check = static function (bool $ok, string $label) use (&$passed): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $passed++;
    echo "  OK  {$label}\n";
};

try {
    $cleanup();
    $db->query("CREATE TABLE `{$usersTable}` (`id` INT NOT NULL PRIMARY KEY) ENGINE=InnoDB");
    $db->query(
        "CREATE TABLE `{$auditTable}` ("
        . "`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, `utente_id` INT NULL, "
        . "CONSTRAINT `{$foreignKey}` FOREIGN KEY (`utente_id`) REFERENCES `{$usersTable}` (`id`)"
        . ") ENGINE=InnoDB"
    );
    $db->query("INSERT INTO `{$usersTable}` (`id`) VALUES (1)");
    $db->query("INSERT INTO `{$auditTable}` (`utente_id`) VALUES (1)");

    $deleteWasRestricted = false;
    try {
        $db->query("DELETE FROM `{$usersTable}` WHERE `id` = 1");
    } catch (mysqli_sql_exception $e) {
        $deleteWasRestricted = $e->getCode() === 1451;
    }
    $check($deleteWasRestricted, 'old audit foreign key restricts operator deletion');

    $runMigration();
    $rule = $db->query(
        "SELECT DELETE_RULE FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS "
        . "WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='{$auditTable}' AND CONSTRAINT_NAME='{$foreignKey}'"
    )->fetch_assoc();
    $check(($rule['DELETE_RULE'] ?? '') === 'SET NULL', 'migration changes the audit foreign key to ON DELETE SET NULL');

    $runMigration();
    $check(true, 'migration is idempotent when SET NULL is already installed');

    $db->query("DELETE FROM `{$usersTable}` WHERE `id` = 1");
    $audit = $db->query("SELECT utente_id FROM `{$auditTable}` WHERE id = 1")->fetch_assoc();
    $check(array_key_exists('utente_id', $audit) && $audit['utente_id'] === null, 'deleting an operator preserves and detaches the audit row');

    $version = json_decode((string) file_get_contents($root . '/version.json'), true);
    $check(($version['version'] ?? '') === '0.7.73-rc.1', 'release version includes the migration');
    $schema = (string) file_get_contents($root . '/installer/database/schema.sql');
    $check(str_contains($schema, 'log_modifiche_ibfk_1` FOREIGN KEY (`utente_id`) REFERENCES `utenti` (`id`) ON DELETE SET NULL'), 'fresh-install schema has the same retention rule');
} catch (Throwable $e) {
    try {
        $cleanup();
    } catch (Throwable) {
    }
    $db->close();
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    exit(1);
}

$cleanup();
$db->close();
echo PHP_EOL . "Passed: {$passed}, Failed: 0" . PHP_EOL;

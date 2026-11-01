<?php
declare(strict_types=1);

/**
 * Installer::importTriggers() must warn only when triggers really are missing.
 *
 * It counted a trigger only when the chunk between two `$$` delimiters started
 * with CREATE TRIGGER, but every chunk starts with comments and a DROP TRIGGER.
 * The count stayed at 0, so each successful install ended on the "manual action
 * required" page while all the triggers were in place.
 *
 * Runs the real importer and the real triggers.sql against a sandbox database:
 * importTriggers() drops and creates triggers, so it must never see a shared one.
 *
 * Run:  php tests/installer-trigger-import.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/installer/classes/Installer.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = Dotenv\Dotenv::parse((string) @file_get_contents($root . '/.env'));
$setting = static function (string $key, string $default = '') use ($env): string {
    $process = getenv('E2E_' . $key);
    return $process === false ? ($env[$key] ?? $default) : $process; // set but empty counts (a passwordless server)
};
if ($setting('DB_USER') === '' || $setting('DB_NAME') === '') {
    echo "SKIP: no database credentials\n";
    exit(0);
}
$host = $setting('DB_HOST', '127.0.0.1');
$socket = $setting('DB_SOCKET');
$useSocket = $host === 'localhost' && $socket !== '' && file_exists($socket) && getenv('E2E_DB_PORT') === false;
$tcpHost = $host === 'localhost' ? '127.0.0.1' : $host;
$port = (int) $setting('DB_PORT', '3306');
$admin = $useSocket
    ? new mysqli(null, $setting('DB_USER'), $setting('DB_PASS'), $setting('DB_NAME'), 0, $socket)
    : new mysqli($tcpHost, $setting('DB_USER'), $setting('DB_PASS'), $setting('DB_NAME'), $port);
$admin->set_charset('utf8mb4');

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$sandbox = $setting('DB_NAME') . '_migtrg';
$schema = (string) file_get_contents($root . '/installer/database/schema.sql');
$triggersSql = (string) file_get_contents($root . '/installer/database/triggers.sql');
preg_match_all('/^\s*CREATE\s+TRIGGER\s+`([^`]+)`/mi', $triggersSql, $declared);
$expected = $declared[1];
sort($expected);

$resetSandbox = static function () use ($admin, $sandbox, $schema): void {
    $admin->query("DROP DATABASE IF EXISTS `{$sandbox}`");
    $admin->query("CREATE DATABASE `{$sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $admin->select_db($sandbox);
    $admin->multi_query($schema);
    do {
        if ($result = $admin->store_result()) {
            $result->free();
        }
    } while ($admin->more_results() && $admin->next_result());
};

// A new Installer per run, as each install request builds its own.
$import = static function () use ($root, $sandbox, $setting, $useSocket, $socket, $tcpHost, $port): array {
    $installer = new Installer($root);
    (new ReflectionProperty($installer, 'config'))->setValue($installer, [
        'DB_HOST' => $useSocket ? 'localhost' : $tcpHost,
        'DB_PORT' => $port,
        'DB_NAME' => $sandbox,
        'DB_USER' => $setting('DB_USER'),
        'DB_PASS' => $setting('DB_PASS'),
        'DB_SOCKET' => $useSocket ? $socket : '',
    ]);
    $installer->importTriggers();
    return $installer->getTriggerWarnings();
};

$installed = static function () use ($admin, $sandbox): array {
    $rows = $admin->query(
        "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '{$sandbox}' ORDER BY TRIGGER_NAME"
    )->fetch_all();
    return array_column($rows, 0);
};

try {
    $check(count($expected) >= 5, 'triggers.sql declares the triggers this test relies on');

    echo "Fresh install\n";
    $resetSandbox();
    $warnings = $import();
    $check($warnings === [], 'all triggers created: no warning (got: ' . implode(' | ', $warnings) . ')');
    $check($installed() === $expected, 'every trigger declared in triggers.sql exists');

    echo "Second run\n";
    $warnings = $import();
    $check($warnings === [], 're-importing over existing triggers stays silent (got: ' . implode(' | ', $warnings) . ')');
    $check($installed() === $expected, 'the triggers are still all there');

    echo "A trigger that really fails\n";
    $resetSandbox();
    $admin->query('SET foreign_key_checks = 0');
    $admin->query('DROP TABLE `prestiti`');
    $admin->query('SET foreign_key_checks = 1');
    $warnings = $import();
    $joined = implode(' | ', $warnings);
    $check($warnings !== [], 'a failed CREATE TRIGGER is still reported');
    $check(str_contains($joined, 'prestiti'), 'the warning carries the real database error (got: ' . $joined . ')');
    $check(!str_contains($joined, 'Nessun trigger valido'), 'triggers that did get created are not reported as none found');
    $check(
        $installed() === array_values(array_filter($expected, static fn (string $name): bool => !str_contains($name, 'prestito'))),
        'the failure does not take the healthy triggers down with it'
    );
} finally {
    $admin->query("DROP DATABASE IF EXISTS `{$sandbox}`");
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

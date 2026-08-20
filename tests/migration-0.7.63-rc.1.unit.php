<?php
declare(strict_types=1);

/**
 * Upgrade-path coverage for the #366 legacy ready-pickup repair.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\Updater;

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
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$suffix = bin2hex(random_bytes(5));
$loans = "zz_mig_0763_loans_{$suffix}";
$copies = "zz_mig_0763_copies_{$suffix}";
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$cleanup = static function () use ($db, $loans, $copies): void {
    $db->query("DROP TABLE IF EXISTS `{$loans}`");
    $db->query("DROP TABLE IF EXISTS `{$copies}`");
};
$row = static function (int $id) use ($db, $loans): array {
    return $db->query("SELECT stato, attivo, copia_id, pickup_deadline FROM `{$loans}` WHERE id = {$id}")->fetch_assoc() ?: [];
};

echo "A. Data repair on a pre-0.7.63 sandbox\n";
try {
    $cleanup();
    $db->query("CREATE TABLE `{$copies}` (
        id INT NOT NULL,
        libro_id INT NOT NULL,
        stato VARCHAR(32) NOT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB");
    $db->query("CREATE TABLE `{$loans}` (
        id INT NOT NULL,
        libro_id INT NOT NULL,
        copia_id INT NULL,
        stato VARCHAR(32) NOT NULL,
        attivo TINYINT(1) NOT NULL,
        pickup_deadline DATE NULL,
        PRIMARY KEY (id),
        KEY idx_copy (copia_id)
    ) ENGINE=InnoDB");

    $db->query("INSERT INTO `{$copies}` (id, libro_id, stato) VALUES
        (1, 1, 'prestato'),
        (2, 2, 'disponibile'),
        (3, 3, 'prenotato'),
        (4, 4, 'disponibile'),
        (5, 5, 'prestato'),
        (6, 5, 'prenotato')");
    $db->query("INSERT INTO `{$loans}` (id, libro_id, copia_id, stato, attivo, pickup_deadline) VALUES
        (1, 1, 1, 'in_ritardo', 1, NULL),
        (2, 1, 1, 'da_ritirare', 1, '2026-08-23'),
        (3, 2, 2, 'da_ritirare', 1, '2026-08-23'),
        (4, 3, 3, 'in_corso', 1, NULL),
        (5, 3, 3, 'da_ritirare', 1, '2026-08-23'),
        (6, 4, NULL, 'da_ritirare', 1, '2026-08-23'),
        (7, 5, 5, 'in_ritardo', 1, NULL),
        (8, 5, 6, 'da_ritirare', 1, '2026-08-23')");

    $migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.63-rc.1.sql');
    $sandboxSql = str_replace(
        ['`prestiti`', '`copie`'],
        ["`{$loans}`", "`{$copies}`"],
        preg_replace('/^--.*$/m', '', $migration) ?? $migration
    );
    $run = static function () use ($db, $sandboxSql): void {
        $db->query(trim($sandboxSql));
    };

    $run();
    $check($row(2)['stato'] === 'prenotato' && $row(2)['pickup_deadline'] === null && $row(2)['copia_id'] === null,
        'same-copy overdue survivor is demoted, unpinned and loses the stale deadline');
    $check($row(3)['stato'] === 'da_ritirare' && (int) $row(3)['copia_id'] === 2,
        'a truthful ready pickup on an available copy is preserved');
    $check($row(5)['stato'] === 'prenotato' && $row(5)['copia_id'] === null,
        'loan-row occupancy wins over a stale copie.stato=prenotato cache');
    $check($row(6)['stato'] === 'prenotato' && $row(6)['copia_id'] === null,
        'copy-less ready pickup is returned to the assignable scheduled state');
    $check($row(8)['stato'] === 'da_ritirare' && (int) $row(8)['copia_id'] === 6,
        'multi-copy ready pickup survives when its own copy is physically free');

    $snapshot = $db->query("SELECT id, stato, copia_id, pickup_deadline FROM `{$loans}` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $run();
    $check($db->query("SELECT id, stato, copia_id, pickup_deadline FROM `{$loans}` ORDER BY id")->fetch_all(MYSQLI_ASSOC) === $snapshot,
        'migration is idempotent');
    $check(str_contains($migration, "`copia_id` = NULL"),
        'repair bypasses the legacy overlap trigger while the updater is still running old code');
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL exception: ' . $e->getMessage() . PHP_EOL;
} finally {
    $cleanup();
    $db->close();
}

echo "B. Upgrade wiring\n";
$migrationVersion = '0.7.63-rc.1';
$check(Updater::shouldRunMigration($migrationVersion, '0.7.62', '0.7.63-rc.1'),
    'Updater runs the repair on 0.7.62 -> 0.7.63-rc.1');
$check(Updater::shouldRunMigration($migrationVersion, '0.7.62', '0.7.63'),
    'Updater runs the repair when the RC was skipped');
$check(!Updater::shouldRunMigration($migrationVersion, '0.7.63-rc.1', '0.7.63'),
    'Updater does not repeat an already-recorded RC migration');

echo PHP_EOL . "{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);

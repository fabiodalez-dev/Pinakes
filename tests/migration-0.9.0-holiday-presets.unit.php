<?php
declare(strict_types=1);

/**
 * Run the REAL "holiday presets" section of migrate_0.9.0-rc.1.sql, twice, against
 * a sandbox whose calendario_chiusure is the one the "closures calendar"
 * section creates (the table as it was before this section, with rows the
 * former Italian preset stored), and check its effect and idempotency.
 *
 * Needs a user allowed to create the sandbox database (E2E_MIG_DB, default
 * "<DB_NAME>_migtest_hol"). Run: php tests/migration-0.9.0-holiday-presets.unit.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$root = dirname(__DIR__);
$env = Dotenv\Dotenv::parse((string) file_get_contents($root . '/.env'));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null);
$sandbox = getenv('E2E_MIG_DB') ?: preg_replace('/^fabiodal_biblioteca_/', 'fabiodal_', (string) $env['DB_NAME']) . '_migtest_hol';
if (preg_match('/^[A-Za-z0-9_]+$/', $sandbox) !== 1) {
    fwrite(STDERR, "invalid sandbox name\n");
    exit(1);
}

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    $checks++;
    echo "OK {$label}\n";
};

$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.9.0-rc.1.sql');
$section = static function (string $name) use ($migration): string {
    if (preg_match('/-- ── ' . preg_quote($name, '/') . ' ──(.*?)-- ── end ' . preg_quote($name, '/') . ' ──/s', $migration, $m) !== 1) {
        fwrite(STDERR, "{$name} section not found in migrate_0.9.0-rc.1.sql\n");
        exit(1);
    }
    return $m[1];
};
$closures = $section('closures calendar');
$holidays = $section('holiday presets');
$schema = (string) file_get_contents($root . '/installer/database/schema.sql');
if (preg_match('/CREATE TABLE `sedi` \(.*?\) ENGINE=[^;]*;/s', $schema, $m) !== 1) {
    fwrite(STDERR, "sedi not found in schema.sql\n");
    exit(1);
}
$sedi = $m[0];

$admin = new mysqli($env['DB_HOST'] ?? 'localhost', $env['DB_USER'], $env['DB_PASS'] ?? '', '', (int) ($env['DB_PORT'] ?? 3306), $socket);
$exit = 1;
try {
    $admin->query("DROP DATABASE IF EXISTS `{$sandbox}`");
    $admin->query("CREATE DATABASE `{$sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new mysqli($env['DB_HOST'] ?? 'localhost', $env['DB_USER'], $env['DB_PASS'] ?? '', $sandbox, (int) ($env['DB_PORT'] ?? 3306), $socket);
    $db->set_charset('utf8mb4');
    $db->query($sedi);

    // Executed the way the Updater does: comment lines dropped, split on ';'.
    $run = static function (string $sql) use ($db): void {
        $sql = implode("\n", array_filter(explode("\n", $sql), static fn (string $l): bool => !str_starts_with(trim($l), '--')));
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') {
                $db->query($statement);
            }
        }
    };
    $columns = static function () use ($db): array {
        $cols = [];
        foreach ($db->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendario_chiusure' ORDER BY ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC) as $c) {
            $cols[$c['COLUMN_NAME']] = $c;
        }
        return $cols;
    };
    $index = static fn (): int => (int) $db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendario_chiusure' AND INDEX_NAME = 'idx_calendario_chiusure_regola' AND COLUMN_NAME = 'regola_festivita'")->fetch_row()[0];

    // The table as the closures section leaves it, with the rows the former
    // Italian preset and a manual entry stored.
    $run($closures);
    $before = $columns();
    $check(!isset($before['regola_festivita']) && !isset($before['attiva']), 'the old table has neither regola_festivita nor attiva');
    $db->query("INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, ricorrente_annuale) VALUES ('2026-12-25','2026-12-25','Natale',1), ('2026-04-06','2026-04-06','Lunedì dell''Angelo (Pasquetta)',0), ('2026-08-16','2026-08-16','Patrono',1)");

    $run($holidays);
    $cols = $columns();
    $check(isset($cols['regola_festivita']) && $cols['regola_festivita']['COLUMN_TYPE'] === 'varchar(64)' && $cols['regola_festivita']['IS_NULLABLE'] === 'YES', 'regola_festivita varchar(64) NULL is added');
    $check(isset($cols['attiva']) && $cols['attiva']['COLUMN_TYPE'] === 'tinyint(1)' && $cols['attiva']['IS_NULLABLE'] === 'NO' && in_array($cols['attiva']['COLUMN_DEFAULT'], ['1', "'1'"], true), 'attiva tinyint(1) NOT NULL DEFAULT 1 is added');
    $order = array_keys($cols);
    $check(array_search('regola_festivita', $order, true) === array_search('sede_id', $order, true) + 1 && array_search('attiva', $order, true) === array_search('regola_festivita', $order, true) + 1, 'the columns follow sede_id, as in schema.sql');
    $check($index() === 1, 'the regola_festivita index exists');
    $rows = $db->query('SELECT descrizione, regola_festivita, attiva FROM calendario_chiusure ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $check(count($rows) === 3, 'existing closures are kept');
    $check(array_unique(array_map(static fn (array $r): string => var_export($r['regola_festivita'], true) . '|' . $r['attiva'], $rows)) === ['NULL|1'], 'they are manual (NULL) and active: the first apply adopts the preset ones');

    $run($holidays);
    $check(array_keys($columns()) === $order && $index() === 1, 'a second run changes nothing (idempotent)');
    $check((int) $db->query('SELECT COUNT(*) FROM calendario_chiusure')->fetch_row()[0] === 3, 'and keeps the rows');

    // The fresh install has the same shape.
    $check(str_contains($schema, "`regola_festivita` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,\n  `attiva` tinyint(1) NOT NULL DEFAULT '1',") && str_contains($schema, 'KEY `idx_calendario_chiusure_regola` (`regola_festivita`)'), 'schema.sql has both columns and the index');
    $check(strpos($migration, '-- ── holiday presets ──') > strpos($migration, '-- ── end closures calendar ──'), 'the section runs after the table is created');
    $version = json_decode((string) file_get_contents($root . '/version.json'), true)['version'];
    $check(version_compare('0.9.0-rc.1', $version, '<='), 'the release version includes the migration');
    $db->close();
    echo "SUCCESS {$checks} migration checks\n";
    $exit = 0;
} catch (\Throwable $e) {
    echo $e->getMessage() . "\n";
} finally {
    $admin->query("DROP DATABASE IF EXISTS `{$sandbox}`");
}
exit($exit);

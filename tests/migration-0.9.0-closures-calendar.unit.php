<?php
declare(strict_types=1);

/**
 * Run the REAL "closures calendar" section of migrate_0.9.0.sql, twice,
 * against a sandbox database seeded with the 0.8.9 schema (the schema.sql of
 * the last release, read from git), and check its effect and idempotency.
 *
 * Needs a user allowed to create the sandbox database (E2E_MIG_DB, default
 * "<DB_NAME>_migtest_cal"). Run: php tests/migration-0.9.0-closures-calendar.unit.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$root = dirname(__DIR__);
$env = Dotenv\Dotenv::parse((string) file_get_contents($root . '/.env'));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null);
$sandbox = getenv('E2E_MIG_DB') ?: preg_replace('/^fabiodal_biblioteca_/', 'fabiodal_', (string) $env['DB_NAME']) . '_migtest_cal';
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

// The 0.8.9 schema: the last released schema.sql, so "old" really is old.
$oldSchema = shell_exec('git -C ' . escapeshellarg($root) . ' show v0.8.9:installer/database/schema.sql 2>/dev/null')
    ?: shell_exec('git -C ' . escapeshellarg($root) . ' show 12f05750:installer/database/schema.sql 2>/dev/null');
if (!is_string($oldSchema) || !str_contains($oldSchema, 'CREATE TABLE `sedi`')) {
    fwrite(STDERR, "cannot read the 0.8.9 schema from git\n");
    exit(1);
}

$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.9.0.sql');
if (preg_match('/-- ── closures calendar ──(.*?)-- ── end closures calendar ──/s', $migration, $m) !== 1) {
    fwrite(STDERR, "closures calendar section not found in migrate_0.9.0.sql\n");
    exit(1);
}
$section = $m[1];

$admin = new mysqli($env['DB_HOST'] ?? 'localhost', $env['DB_USER'], $env['DB_PASS'] ?? '', '', (int) ($env['DB_PORT'] ?? 3306), $socket);
$exit = 1;
try {
    $admin->query("DROP DATABASE IF EXISTS `{$sandbox}`");
    $admin->query("CREATE DATABASE `{$sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new mysqli($env['DB_HOST'] ?? 'localhost', $env['DB_USER'], $env['DB_PASS'] ?? '', $sandbox, (int) ($env['DB_PORT'] ?? 3306), $socket);
    $db->multi_query($oldSchema);
    do {
        if ($r = $db->store_result()) {
            $r->free();
        }
    } while ($db->more_results() && $db->next_result());

    $tableExists = static fn (): bool => (int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendario_chiusure'")->fetch_row()[0] === 1;
    $check(!$tableExists(), 'the 0.8.9 schema has no calendario_chiusure');
    $check((int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sedi'")->fetch_row()[0] === 1, 'the 0.8.9 schema has sedi');

    // Execute the way the Updater does: comment lines dropped, split on ';'.
    $run = static function () use ($db, $section): void {
        $sql = implode("\n", array_filter(explode("\n", $section), static fn (string $l): bool => !str_starts_with(trim($l), '--')));
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') {
                $db->query($statement);
            }
        }
    };
    $run();
    $check($tableExists(), 'the migration creates calendario_chiusure');
    $cols = [];
    foreach ($db->query("SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendario_chiusure'")->fetch_all(MYSQLI_ASSOC) as $c) {
        $cols[$c['COLUMN_NAME']] = $c['DATA_TYPE'] . ':' . $c['IS_NULLABLE'];
    }
    $expected = ['id', 'data_inizio', 'data_fine', 'descrizione', 'ricorrente_annuale', 'sede_id', 'created_at'];
    $check(array_diff($expected, array_keys($cols)) === [] && count($cols) === count($expected), 'columns match the plan');
    $check(($cols['data_inizio'] ?? '') === 'date:NO' && ($cols['data_fine'] ?? '') === 'date:NO', 'the interval is two NOT NULL dates');
    $check(($cols['sede_id'] ?? '') === 'int:YES', 'sede_id is nullable (NULL = all branches)');
    $fk = $db->query("SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendario_chiusure' AND COLUMN_NAME = 'sede_id' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetch_row();
    $check(($fk[0] ?? null) === 'sedi', 'sede_id references sedi');

    $db->query("INSERT INTO sedi (nome) VALUES ('Succursale')");
    $sede = (int) $db->insert_id;
    $db->query("INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione) VALUES ('2026-12-24','2026-12-26','Natale')");
    $db->query("INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, ricorrente_annuale, sede_id) VALUES ('2026-08-15','2026-08-15','Ferragosto',1,{$sede})");
    $row = $db->query("SELECT ricorrente_annuale, sede_id FROM calendario_chiusure WHERE descrizione = 'Natale'")->fetch_assoc();
    $check($row['ricorrente_annuale'] === '0' || (int) $row['ricorrente_annuale'] === 0, 'ricorrente_annuale defaults to 0');
    $check($row['sede_id'] === null, 'sede_id defaults to NULL');

    $run();
    $check((int) $db->query('SELECT COUNT(*) FROM calendario_chiusure')->fetch_row()[0] === 2, 'a second run is a no-op that keeps the rows');

    $db->query("DELETE FROM sedi WHERE id = {$sede}");
    $check((int) $db->query('SELECT COUNT(*) FROM calendario_chiusure')->fetch_row()[0] === 1, 'deleting a branch removes only its closures');

    $schema = (string) file_get_contents($root . '/installer/database/schema.sql');
    $check(str_contains($schema, 'CREATE TABLE `calendario_chiusure`') && str_contains($schema, '`fk_calendario_chiusure_sede`'), 'fresh install (schema.sql) has the table');
    $version = json_decode((string) file_get_contents($root . '/version.json'), true)['version'];
    $check(version_compare('0.9.0', $version, '<='), 'the release version includes the migration');
    $db->close();
    echo "SUCCESS {$checks} migration checks\n";
    $exit = 0;
} catch (\Throwable $e) {
    echo $e->getMessage() . "\n";
} finally {
    $admin->query("DROP DATABASE IF EXISTS `{$sandbox}`");
}
exit($exit);

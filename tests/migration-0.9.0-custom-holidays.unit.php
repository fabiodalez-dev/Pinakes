<?php
declare(strict_types=1);

/**
 * Run the REAL "custom holidays" section of migrate_0.9.0.sql, twice, against
 * a sandbox with the sedi table, and check that festivita_personalizzate is
 * created exactly as in schema.sql, that a second run changes nothing and
 * that deleting a branch deletes its holidays.
 *
 * Needs a user allowed to create the sandbox database (E2E_MIG_DB, default
 * "<DB_NAME>_migtest_cust"). Run: php tests/migration-0.9.0-custom-holidays.unit.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$root = dirname(__DIR__);
$env = Dotenv\Dotenv::parse((string) file_get_contents($root . '/.env'));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null);
$user = getenv('E2E_DB_USER') ?: $env['DB_USER'];
$pass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? '');
$host = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? 'localhost');
$port = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));
$sandbox = getenv('E2E_MIG_DB') ?: preg_replace('/^fabiodal_biblioteca_/', 'fabiodal_', (string) $env['DB_NAME']) . '_migtest_cust';
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

$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.9.0.sql');
if (preg_match('/-- ── custom holidays ──(.*?)-- ── end custom holidays ──/s', $migration, $m) !== 1) {
    fwrite(STDERR, "custom holidays section not found in migrate_0.9.0.sql\n");
    exit(1);
}
$section = $m[1];
$schema = (string) file_get_contents($root . '/installer/database/schema.sql');
foreach (['sedi', 'festivita_personalizzate'] as $table) {
    if (preg_match('/CREATE TABLE `' . $table . '` \(.*?\) ENGINE=[^;]*;/s', $schema, $mm) !== 1) {
        fwrite(STDERR, "{$table} not found in schema.sql\n");
        exit(1);
    }
    $ddl[$table] = $mm[0];
}

$admin = new mysqli($host, $user, $pass, '', $port, $socket);
$exit = 1;
try {
    $admin->query("DROP DATABASE IF EXISTS `{$sandbox}`");
    $admin->query("CREATE DATABASE `{$sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new mysqli($host, $user, $pass, $sandbox, $port, $socket);
    $db->set_charset('utf8mb4');
    $db->query($ddl['sedi']);

    // Executed the way the Updater does: comment lines dropped, split on ';'.
    $run = static function (string $sql) use ($db): void {
        $sql = implode("\n", array_filter(explode("\n", $sql), static fn (string $l): bool => !str_starts_with(trim($l), '--')));
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') {
                $db->query($statement);
            }
        }
    };
    $columns = static function (string $table) use ($db): array {
        return $db->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' ORDER BY ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC);
    };

    $check($db->query("SHOW TABLES LIKE 'festivita_personalizzate'")->num_rows === 0, 'the old schema has no festivita_personalizzate');
    $run($section);
    $migrated = $columns('festivita_personalizzate');
    $check(count($migrated) === 18, 'the migration creates the 18 columns');

    // The fresh-install definition (schema.sql) must be the same table.
    $db->query(str_replace('CREATE TABLE `festivita_personalizzate`', 'CREATE TABLE `festivita_personalizzate_fresh`', str_replace('`fk_festivita_personalizzate_sede`', '`fk_festivita_personalizzate_fresh_sede`', $ddl['festivita_personalizzate'])));
    $check($columns('festivita_personalizzate_fresh') === $migrated, 'the migrated table matches schema.sql column by column');
    $db->query('DROP TABLE festivita_personalizzate_fresh');

    $fk = $db->query("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_festivita_personalizzate_sede'")->fetch_row();
    $check(($fk[0] ?? null) === 'CASCADE', 'the branch foreign key cascades');

    $db->query("INSERT INTO sedi (nome) VALUES ('Sede ZZ')");
    $sede = (int) $db->insert_id;
    $db->query("INSERT INTO festivita_personalizzate (nome, tipo, mese, giorno) VALUES ('Patrono', 'fixed', 6, 24)");
    $db->query("INSERT INTO festivita_personalizzate (nome, tipo, calendario, mese, giorno, sede_id) VALUES ('Eid al-Fitr', 'calendar', 'islamic-umalqura', 10, 1, {$sede})");
    $row = $db->query("SELECT durata_giorni, attiva, fine_settimana FROM festivita_personalizzate WHERE nome = 'Patrono'")->fetch_assoc();
    $check((int) $row['durata_giorni'] === 1 && (int) $row['attiva'] === 1 && $row['fine_settimana'] === null, 'defaults: one day, active, no weekend rule');

    $run($section);
    $check($columns('festivita_personalizzate') === $migrated, 'a second run leaves the table unchanged');
    $check((int) $db->query('SELECT COUNT(*) FROM festivita_personalizzate')->fetch_row()[0] === 2, 'a second run keeps the stored holidays');

    $db->query("DELETE FROM sedi WHERE id = {$sede}");
    $check((int) $db->query('SELECT COUNT(*) FROM festivita_personalizzate')->fetch_row()[0] === 1, 'deleting a branch deletes its holidays only');
    $exit = 0;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
} finally {
    $admin->query("DROP DATABASE IF EXISTS `{$sandbox}`");
}
echo ($exit === 0 ? 'SUCCESS' : 'FAILURE') . " {$checks} custom holiday migration checks\n";
exit($exit);

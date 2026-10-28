<?php
declare(strict_types=1);

/**
 * Run the REAL "Inventory stocktake" section of migrate_0.9.0.sql, twice,
 * against sandbox copies of the 0.8.9 tables it depends on (sedi, utenti,
 * ruoli). Every name the section creates or references is prefixed so the
 * sandbox never touches the installed tables or their constraint names.
 *
 * Run: php tests/migration-0.9.0-stocktake.unit.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = Dotenv\Dotenv::parse((string) file_get_contents(dirname(__DIR__) . '/.env'));
$db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
$db->set_charset('utf8mb4');

$file = dirname(__DIR__) . '/installer/database/migrations/migrate_0.9.0.sql';
$source = (string) file_get_contents($file);
$start = strpos($source, '-- ── Inventory stocktake ──');
$end = strpos($source, '-- ── end Inventory stocktake ──');
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "FAIL the migration has no delimited Inventory stocktake section\n");
    exit(1);
}
$section = substr($source, $start, $end - $start);

$p = 'zz9' . bin2hex(random_bytes(3)) . '_';
$sql = preg_replace('/^--.*$/m', '', $section) ?? '';
$sql = str_replace('inventario_', $p . 'inv_', $sql);
$sql = preg_replace('/\b(sedi|utenti|ruoli)\b/', $p . '$1', $sql) ?? '';
$runMigration = static function () use ($db, $sql): void {
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '') {
            $db->query($statement);
        }
    }
};

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    $checks++;
    echo "OK {$label}\n";
};
$one = static function (string $query) use ($db): ?string {
    $row = $db->query($query)->fetch_row();
    return $row === null || $row[0] === null ? null : (string) $row[0];
};
$tableExists = static fn (string $t): bool => (int) $one("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}'") === 1;

$sessions = $p . 'inv_sessioni';
$expected = $p . 'inv_attese';
$scans = $p . 'inv_scansioni';
try {
    // The 0.8.9 shape of what the section references.
    $db->query("CREATE TABLE {$p}sedi (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, nome varchar(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE {$p}utenti (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, nome varchar(100) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE {$p}ruoli (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, nome varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL, permessi text COLLATE utf8mb4_unicode_ci NOT NULL, UNIQUE KEY uk_ruoli_nome (nome)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("INSERT INTO {$p}ruoli (nome, permessi) VALUES ('Catalogatore', '[\"backend.access\",\"catalog.manage\"]'), ('Addetto prestiti', '[\"backend.access\",\"loans.manage\"]')");
    $db->query("INSERT INTO {$p}utenti (nome) VALUES ('Operator')");
    $check(!$tableExists($sessions) && !$tableExists($expected) && !$tableExists($scans), 'the 0.8.9 schema has no stocktake tables');

    $runMigration();
    $check($tableExists($sessions) && $tableExists($expected) && $tableExists($scans), 'the migration creates the three stocktake tables');
    $columns = $one("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$sessions}'");
    $check($columns === 'id,nome,sede_id,filtro,stato,totale_attese,aperta_da,aperta_il,chiusa_da,chiusa_il,note', 'inventario_sessioni has the planned columns');
    $check($one("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$sessions}' AND COLUMN_NAME = 'stato'") === "enum('aperta','chiusa','annullata')", 'the session state is aperta/chiusa/annullata');
    $check($one("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$expected}'") === 'sessione_id,copia_id,scaffale_atteso,stato_all_apertura,esito_chiusura', 'inventario_attese is the snapshot the plan describes, with the bucket frozen at close');
    $check($one("SELECT CONCAT(COLUMN_TYPE, ':', IS_NULLABLE) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$expected}' AND COLUMN_NAME = 'esito_chiusura'") === 'varchar(20):YES', 'esito_chiusura is a nullable varchar(20): NULL while the session is open');
    $check($one("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$scans}' AND COLUMN_NAME = 'esito'") === "enum('trovata','fuori_posto','in_prestito','sconosciuta','duplicata','fuori_ambito')", 'the scan outcomes are the planned six');
    $check($one("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$scans}' AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY'") === 'sessione_id,copia_id', 'scans are UNIQUE on (sessione_id, copia_id)');
    $check((int) $one("SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '{$expected}' AND REFERENCED_TABLE_NAME <> '{$sessions}'") === 0, 'the snapshot has no foreign key to copie: deleting a copy cannot rewrite it');
    $check($one("SELECT permessi FROM {$p}ruoli WHERE nome = 'Revisore inventario'") === '["backend.access","inventory.manage"]', 'the stocktake role preset is seeded');
    $check((int) $one("SELECT COUNT(*) FROM {$p}ruoli") === 3, 'existing presets are preserved');

    // Live data between runs must survive the second run.
    $db->query("INSERT INTO {$sessions} (nome, aperta_da) VALUES ('Prova', 1)");
    $sid = (int) $db->insert_id;
    $db->query("INSERT INTO {$expected} (sessione_id, copia_id, scaffale_atteso, stato_all_apertura) VALUES ({$sid}, 10, 2, 'disponibile'), ({$sid}, 11, NULL, 'prestato')");
    $db->query("INSERT INTO {$scans} (sessione_id, copia_id, codice_letto, esito, scansionato_da) VALUES ({$sid}, 10, 'INV-10', 'trovata', 1), ({$sid}, NULL, 'XX', 'sconosciuta', 1), ({$sid}, NULL, 'XX', 'sconosciuta', 1)");
    $check($one("SELECT stato FROM {$sessions} WHERE id = {$sid}") === 'aperta' && (int) $one("SELECT totale_attese FROM {$sessions} WHERE id = {$sid}") === 0, 'a new session defaults to open with zero expected copies');
    try {
        $db->query("INSERT INTO {$scans} (sessione_id, copia_id, codice_letto, esito) VALUES ({$sid}, 10, 'INV-10', 'duplicata')");
        $check(false, 'a second row for the same copy is rejected');
    } catch (mysqli_sql_exception $e) {
        $check($e->getCode() === 1062, 'a second row for the same copy is rejected');
    }

    // A table created by an earlier build of 0.9.0 has no esito_chiusura: the
    // guarded ADD COLUMN must add it without touching the rows.
    $db->query("UPDATE {$expected} SET esito_chiusura = 'trovata' WHERE sessione_id = {$sid} AND copia_id = 10");
    $db->query("ALTER TABLE {$expected} DROP COLUMN esito_chiusura");
    $runMigration();
    $check((int) $one("SELECT COUNT(*) FROM {$expected} WHERE sessione_id = {$sid}") === 2 && (int) $one("SELECT COUNT(*) FROM {$scans} WHERE sessione_id = {$sid}") === 3, 'a second run is a no-op that keeps sessions, snapshots and scans');
    $check($one("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$expected}'") === 'sessione_id,copia_id,scaffale_atteso,stato_all_apertura,esito_chiusura', 'the second run adds esito_chiusura to a snapshot table that lacks it');
    $check((int) $one("SELECT COUNT(*) FROM {$expected} WHERE sessione_id = {$sid} AND esito_chiusura IS NULL") === 2, 'the added column is NULL for existing snapshot rows');
    $runMigration();
    $check((int) $one("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$expected}' AND COLUMN_NAME = 'esito_chiusura'") === 1, 'a third run leaves the column alone');
    $check((int) $one("SELECT COUNT(*) FROM {$p}ruoli WHERE nome = 'Revisore inventario'") === 1, 'the preset is not duplicated');

    $db->query("DELETE FROM {$p}utenti WHERE id = 1");
    $check($one("SELECT aperta_da FROM {$sessions} WHERE id = {$sid}") === null && (int) $one("SELECT COUNT(*) FROM {$scans} WHERE sessione_id = {$sid}") === 3, 'deleting an operator keeps the session and its scans');
    $db->query("DELETE FROM {$sessions} WHERE id = {$sid}");
    $check((int) $one("SELECT COUNT(*) FROM {$expected}") === 0 && (int) $one("SELECT COUNT(*) FROM {$scans}") === 0, 'deleting a session cascades to its snapshot and scans');

    $schema = (string) file_get_contents(dirname(__DIR__) . '/installer/database/schema.sql');
    foreach (['inventario_sessioni', 'inventario_attese', 'inventario_scansioni'] as $table) {
        $check(str_contains($schema, "CREATE TABLE `{$table}`"), "fresh install creates {$table}");
    }
    $check(str_contains($schema, "`esito_chiusura` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,"), 'fresh install creates inventario_attese.esito_chiusura');
    $check(str_contains($schema, "('Revisore inventario', '[\"backend.access\",\"inventory.manage\"]')"), 'fresh install seeds the stocktake preset');
    $version = json_decode((string) file_get_contents(dirname(__DIR__) . '/version.json'), true)['version'];
    $check(version_compare('0.9.0', (string) $version, '<='), 'the release includes migrate_0.9.0.sql');
    echo "SUCCESS {$checks} migration checks\n";
} finally {
    foreach ([$scans, $expected, $sessions, $p . 'ruoli', $p . 'utenti', $p . 'sedi'] as $table) {
        $db->query("DROP TABLE IF EXISTS `{$table}`");
    }
}

<?php
declare(strict_types=1);

/**
 * Runs the REAL fines-ledger section of migrate_0.9.0.sql against a sandbox
 * database seeded with the 0.8.9 shape of utenti/prestiti, twice:
 *  - movimenti_lettore is created with its keys and foreign keys;
 *  - every prestiti.sanzione > 0 becomes exactly one ledger charge
 *    (perso → addebito_perdita, anything else → addebito_danno), key
 *    penalty:<loan id>; sanzione = 0 / NULL rows produce nothing;
 *  - a second run (and the PHP backfill) adds nothing; prestiti.sanzione is
 *    left untouched (it stays the mirror for emails and the activity log);
 *  - a penalty assessed after the upgrade is picked up by the maintenance
 *    backfill once, with the same key.
 *
 * Sandbox: <DB_NAME>_migfines, dropped at the end (override with MIG_SANDBOX_DB).
 * Run: php tests/migration-0.9.0-fines-ledger.unit.php (exit code is the verdict)
 */

use App\Services\PatronLedger;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
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
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$sandbox = getenv('MIG_SANDBOX_DB') ?: ((getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? 'pinakes')) . '_migfines');
try {
    $db = new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ''), null, 0, $socket);
    $db->set_charset('utf8mb4');
    $db->query("DROP DATABASE IF EXISTS `{$sandbox}`");
    $db->query("CREATE DATABASE `{$sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($sandbox);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: sandbox database unavailable ({$sandbox}): {$e->getMessage()}\n");
    exit(1);
}

// The real section of the migration file, statement by statement (no ';' in its literals).
$sql = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.9.0.sql');
$start = strpos($sql, '-- ── fines-ledger');
$end = strpos($sql, '-- ── end fines-ledger ──');
$section = $start !== false && $end !== false ? substr($sql, $start, $end - $start) : '';
$section = (string) preg_replace('/^\s*--.*$/m', '', $section);
$runMigration = static function () use ($db, $section): void {
    foreach (explode(';', $section) as $stmt) {
        if (trim($stmt) !== '') {
            $db->query($stmt);
        }
    }
};
$scalar = static fn (string $q): string => (string) ($db->query($q)->fetch_row()[0] ?? '');

try {
    $check($section !== '' && str_contains($section, 'movimenti_lettore'), 'the migration has a delimited fines-ledger section');

    // 0.8.9 shape of the referenced tables (only the columns the migration and
    // the ledger read; copie.sede_id is the copy's branch for closed days).
    $db->query("CREATE TABLE utenti (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NOT NULL) ENGINE=InnoDB");
    $db->query("CREATE TABLE copie (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, sede_id INT DEFAULT NULL) ENGINE=InnoDB");
    $db->query("CREATE TABLE prestiti (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, utente_id INT NOT NULL, copia_id INT DEFAULT NULL,
        stato ENUM('pendente','prenotato','da_ritirare','in_corso','restituito','in_ritardo','perso','danneggiato','annullato','scaduto') DEFAULT 'pendente',
        data_scadenza DATE DEFAULT NULL, sanzione DECIMAL(10,2) DEFAULT '0.00', data_restituzione DATE DEFAULT NULL, processed_by INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_p_u FOREIGN KEY (utente_id) REFERENCES utenti (id)) ENGINE=InnoDB");
    $db->query("INSERT INTO utenti (id, email) VALUES (1,'a@x'),(2,'b@x'),(3,'staff@x')");
    $db->query("INSERT INTO prestiti (id, utente_id, stato, sanzione, data_restituzione, processed_by) VALUES
        (10, 1, 'perso',       25.00, '2026-05-02', 3),
        (11, 1, 'danneggiato', 12.50, '2026-06-01', NULL),
        (12, 2, 'restituito',   0.00, '2026-06-03', NULL),
        (13, 2, 'restituito',   NULL, '2026-06-04', NULL),
        (14, 2, 'restituito',   4.00, NULL, NULL)");

    $check($scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimenti_lettore'") === '0', 'pre-upgrade: no ledger table');
    $runMigration();
    $check($scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimenti_lettore'") === '1', 'the migration creates movimenti_lettore');
    $check($scalar("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimenti_lettore' AND INDEX_NAME = 'uniq_movimenti_chiave' AND NON_UNIQUE = 0") === '1', 'idempotency key is UNIQUE');
    $check($scalar("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimenti_lettore' AND INDEX_NAME = 'idx_movimenti_utente'") === 'utente_id,importo', 'covering index on (utente_id, importo)');
    $check((int) $scalar("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'movimenti_lettore'") === 3, 'three foreign keys (utente, prestito, operatore)');

    $rows = $db->query("SELECT prestito_id, utente_id, tipo, importo, chiave_idempotenza, creato_da, DATE(created_at) AS d FROM movimenti_lettore ORDER BY prestito_id")->fetch_all(MYSQLI_ASSOC);
    $check(count($rows) === 3, 'three loans had a sanzione > 0 → three movements');
    $byLoan = array_column($rows, null, 'prestito_id');
    $check(($byLoan[10]['tipo'] ?? '') === 'addebito_perdita' && ($byLoan[10]['importo'] ?? '') === '25.00' && ($byLoan[10]['chiave_idempotenza'] ?? '') === 'penalty:10', 'lost copy → addebito_perdita 25.00, key penalty:10');
    $check(($byLoan[11]['tipo'] ?? '') === 'addebito_danno' && ($byLoan[11]['importo'] ?? '') === '12.50', 'damaged copy → addebito_danno 12.50');
    $check(($byLoan[14]['tipo'] ?? '') === 'addebito_danno' && ($byLoan[14]['importo'] ?? '') === '4.00', 'other state with a sanzione → addebito_danno');
    $check(!isset($byLoan[12]) && !isset($byLoan[13]), 'sanzione 0 or NULL → no movement');
    $check((int) ($byLoan[10]['creato_da'] ?? 0) === 3 && ($byLoan[10]['d'] ?? '') === '2026-05-02', 'operator and date come from the loan');
    $check($scalar("SELECT SUM(importo) FROM movimenti_lettore WHERE utente_id = 1") === '37.50', 'balance of user 1 = 37.50');

    $runMigration();
    $check($scalar("SELECT COUNT(*) FROM movimenti_lettore") === '3', 'second run: still three movements (idempotent)');
    $check($scalar("SELECT GROUP_CONCAT(CONCAT(id, ':', IFNULL(sanzione, '-')) ORDER BY id) FROM prestiti") === '10:25.00,11:12.50,12:0.00,13:-,14:4.00', 'prestiti.sanzione is left untouched (mirror)');

    $ledger = new PatronLedger($db);
    $check($ledger->backfillLegacyPenalties() === 0, 'maintenance backfill after the migration: nothing to do');
    $db->query("INSERT INTO prestiti (id, utente_id, stato, sanzione, data_restituzione) VALUES (15, 2, 'perso', 9.90, '2026-07-01')");
    $check($ledger->backfillLegacyPenalties(1) === 1, 'a penalty the migration did not reach is converted by the backfill');
    $check($ledger->backfillLegacyPenalties() === 0 && $scalar("SELECT COUNT(*) FROM movimenti_lettore WHERE chiave_idempotenza = 'penalty:15'") === '1', '...exactly once');
    $check($ledger->chargeLossPenalty(15) === 0, 'the live path (same key) does not double it either');

    $schema = (string) file_get_contents($root . '/installer/database/schema.sql');
    $check(str_contains($schema, 'CREATE TABLE `movimenti_lettore`') && str_contains($schema, 'UNIQUE KEY `uniq_movimenti_chiave` (`chiave_idempotenza`)'), 'fresh install (schema.sql) has the same table');
    $version = (string) (json_decode((string) file_get_contents($root . '/version.json'), true)['version'] ?? '');
    $check(version_compare('0.9.0', $version, '<='), 'migration version 0.9.0 <= version.json (' . $version . ')');
} finally {
    $db->query("DROP DATABASE IF EXISTS `{$sandbox}`");
}

echo PHP_EOL . "Result: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

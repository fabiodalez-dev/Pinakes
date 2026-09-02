<?php
declare(strict_types=1);

/**
 * Behavioural coverage for the 0.7.74 activity-history backfill migration.
 *
 * The REAL SQL file is retargeted to per-run sandbox tables seeded with the
 * pre-0.7.74 state (loans and reservations present, empty audit table — the
 * exact state every 0.7.73 upgrade left behind), then executed twice: once to
 * prove the synthesized events, once to prove idempotency. A pre-existing
 * real event must also survive untouched and suppress its own backfill row.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// E2E-runner credentials only (no .env fallback): this test creates and
// drops tables in the configured database, so it must go through the
// serial runner that points at the dedicated test DB.
$dbName = getenv('E2E_DB_NAME') ?: '';
$dbUser = getenv('E2E_DB_USER') ?: '';
if ($dbName === '' || $dbUser === '') {
    fwrite(STDERR, "FAIL: refusing to run — export E2E_DB_NAME/E2E_DB_USER (plus E2E_DB_SOCKET or E2E_DB_HOST/E2E_DB_PASS) via the E2E runner.\n");
    exit(1);
}
$socket = getenv('E2E_DB_SOCKET') ?: '';
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, getenv('E2E_DB_PASS') ?: '', $dbName, 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: '127.0.0.1', $dbUser, getenv('E2E_DB_PASS') ?: '', $dbName, (int) (getenv('E2E_DB_PORT') ?: 3306));
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — migration test is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$suffix = bin2hex(random_bytes(5));
$auditTable = 'zz_m74_audit_' . $suffix;
$loansTable = 'zz_m74_loans_' . $suffix;
$resTable = 'zz_m74_res_' . $suffix;
$booksTable = 'zz_m74_books_' . $suffix;
$migration = (string) file_get_contents($root . '/installer/database/migrations/migrate_0.7.74.sql');
$migration = str_replace(
    ['log_modifiche', 'prestiti p', 'prenotazioni r', ' libri l'],
    [$auditTable, "{$loansTable} p", "{$resTable} r", " {$booksTable} l"],
    $migration
);
$migration = preg_replace('/^\s*--.*$/m', '', $migration) ?? $migration;

$runMigration = static function () use ($db, $migration): void {
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $migration))) as $statement) {
        $db->query($statement);
    }
};
$cleanup = static function () use ($db, $auditTable, $loansTable, $resTable, $booksTable): void {
    foreach ([$auditTable, $loansTable, $resTable, $booksTable] as $t) {
        $db->query("DROP TABLE IF EXISTS `{$t}`");
    }
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
    // Pre-0.7.74 state: circulation data exists, the audit table is empty.
    $db->query("CREATE TABLE `{$booksTable}` (id INT PRIMARY KEY, titolo VARCHAR(255) NOT NULL)");
    $db->query("CREATE TABLE `{$loansTable}` (id INT PRIMARY KEY, libro_id INT NOT NULL, utente_id INT NOT NULL, data_prestito DATE NOT NULL, data_scadenza DATE NOT NULL, data_restituzione DATE NULL, stato VARCHAR(20) NOT NULL, created_at DATETIME NULL)");
    $db->query("CREATE TABLE `{$resTable}` (id INT PRIMARY KEY, libro_id INT NOT NULL, utente_id INT NOT NULL, data_prenotazione DATETIME NULL, stato VARCHAR(20) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $db->query("CREATE TABLE `{$auditTable}` (id INT AUTO_INCREMENT PRIMARY KEY, tabella VARCHAR(50) NOT NULL, record_id INT NOT NULL, azione ENUM('inserimento','aggiornamento','cancellazione') NOT NULL, dati_precedenti TEXT NULL, dati_nuovi TEXT NULL, utente_id INT NULL, data_modifica DATETIME DEFAULT CURRENT_TIMESTAMP)");

    $db->query("INSERT INTO `{$booksTable}` VALUES (14, 'Libro in prestito'), (15, 'Libro restituito'), (16, 'Libro prenotato')");
    $db->query("INSERT INTO `{$loansTable}` VALUES (2, 14, 5, '2026-07-07', '2026-08-07', NULL, 'in_ritardo', '2026-07-07 17:10:13')");
    $db->query("INSERT INTO `{$loansTable}` VALUES (3, 15, 6, '2026-05-01', '2026-06-01', '2026-05-20', 'restituito', '2026-05-01 09:00:00')");
    $db->query("INSERT INTO `{$resTable}` VALUES (7, 16, 5, '2026-08-01 10:00:00', 'attiva', '2026-08-01 10:00:00')");
    // NULL data_prenotazione must fall back to the historical created_at, never NOW().
    $db->query("INSERT INTO `{$resTable}` VALUES (8, 16, 6, NULL, 'attiva', '2026-06-15 09:30:00')");
    // A loan whose creation was ALREADY audited for real must not be duplicated.
    $db->query("INSERT INTO `{$loansTable}` VALUES (4, 14, 9, '2026-09-01', '2026-10-01', NULL, 'in_corso', '2026-09-01 08:00:00')");
    $db->query("INSERT INTO `{$auditTable}` (tabella, record_id, azione, dati_precedenti, dati_nuovi, utente_id, data_modifica) VALUES ('libri', 14, 'inserimento', '{}', '{\"stato\":\"in_corso\",\"_activity\":{\"type\":\"loan\",\"event\":\"loan.created\",\"entity_id\":4,\"book_title\":\"Libro in prestito\",\"source\":\"manual\"}}', 1, '2026-09-01 08:00:00')");

    $runMigration();

    $count = static function (string $event) use ($db, $auditTable): int {
        return (int) $db->query(
            "SELECT COUNT(*) FROM `{$auditTable}` WHERE JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi, '$._activity.event')) = '{$event}'"
        )->fetch_row()[0];
    };

    $check($count('loan.created') === 3, 'every loan has exactly one loan.created (2 backfilled + 1 pre-existing real)');
    $check($count('loan.returned') === 1, 'the returned loan gets its loan.returned event');
    $check($count('reservation.created') === 2, 'every reservation gets its reservation.created event');
    $resType = $db->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi, '$._activity.type')) FROM `{$auditTable}` WHERE JSON_EXTRACT(dati_nuovi, '$._activity.entity_id') = 7 AND JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi, '$._activity.event')) = 'reservation.created'")->fetch_row()[0];
    $check($resType === 'loan', "reservation events keep type='loan' (mirrors ActivityLog::recordReservationEvent and the shared feed filter)");
    $nullDateTs = $db->query("SELECT data_modifica FROM `{$auditTable}` WHERE JSON_EXTRACT(dati_nuovi, '$._activity.entity_id') = 8 AND JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi, '$._activity.event')) = 'reservation.created'")->fetch_row()[0];
    $check($nullDateTs === '2026-06-15 09:30:00', 'NULL data_prenotazione falls back to the historical created_at, never NOW()');

    $row = $db->query("SELECT azione, utente_id, data_modifica, dati_nuovi FROM `{$auditTable}` WHERE JSON_EXTRACT(dati_nuovi, '$._activity.entity_id') = 2")->fetch_assoc();
    $meta = json_decode((string) $row['dati_nuovi'], true)['_activity'] ?? [];
    $check($row['azione'] === 'inserimento' && $row['utente_id'] === null, 'backfilled event has NULL operator (renders as Sistema)');
    $check($row['data_modifica'] === '2026-07-07 17:10:13', 'backfilled event keeps the historical loan timestamp');
    $check(($meta['source'] ?? '') === 'backfill' && ($meta['book_title'] ?? '') === 'Libro in prestito', 'metadata carries source=backfill and the book title');

    $preExisting = (int) $db->query("SELECT COUNT(*) FROM `{$auditTable}` WHERE JSON_EXTRACT(dati_nuovi, '$._activity.entity_id') = 4")->fetch_row()[0];
    $check($preExisting === 1, 'a loan already audited for real is not backfilled again');

    $before = (int) $db->query("SELECT COUNT(*) FROM `{$auditTable}`")->fetch_row()[0];
    $runMigration();
    $after = (int) $db->query("SELECT COUNT(*) FROM `{$auditTable}`")->fetch_row()[0];
    $check($before === $after, 'second run is a no-op (idempotent)');

    $version = json_decode((string) file_get_contents($root . '/version.json'), true);
    $check(
        version_compare('0.7.74', (string) ($version['version'] ?? '0'), '<='),
        'release version includes the migration (0.7.74 <= version.json)'
    );
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
echo "\nPassed: {$passed}, Failed: 0\n";

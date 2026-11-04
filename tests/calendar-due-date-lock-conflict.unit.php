<?php
declare(strict_types=1);

/**
 * A due-date move that meets a lock conflict after a calendar save (the book
 * row held by another transaction) is not lost silently: the save's
 * LibraryCalendar::extendDueDatesAfterSave() reports the moves incomplete
 * (false, so the page warns the librarian) and leaves the loan as it was;
 * once the lock is gone, the next run moves it. Real database, two
 * connections. The closure belongs to a branch of this test only, so no other
 * loan is touched. Uses a dedicated test DB.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
if (getenv('PINAKES_ISOLATED_TEST_DB') !== '1' && !str_contains((string) getenv('E2E_DB_NAME'), 'test')) {
    fwrite(STDERR, "FAIL: select a dedicated test database before running this suite.\n");
    exit(1);
}
Dotenv\Dotenv::createImmutable($root)->safeLoad();

use App\Support\DateHelper;
use App\Support\LibraryCalendar;

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  OK  {$label}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$label}\n";
};

$env = Dotenv\Dotenv::parse((string) file_get_contents($root . '/.env'));
$args = [
    getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? 'localhost'),
    getenv('E2E_DB_USER') ?: $env['DB_USER'],
    getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ''),
    getenv('E2E_DB_NAME') ?: $env['DB_NAME'],
    (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)),
    getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null),
];
$db = new mysqli(...$args);
$other = new mysqli(...$args);
$db->set_charset('utf8mb4');
$other->set_charset('utf8mb4');
// A held lock fails fast instead of the default 50 s.
$db->query('SET SESSION innodb_lock_wait_timeout = 1');

$run = substr((string) hrtime(true), -7);
$today = DateHelper::today();
$due = (new DateTimeImmutable($today))->modify('+5 day')->format('Y-m-d');
$nextDay = (new DateTimeImmutable($due))->modify('+1 day')->format('Y-m-d');
$featureRow = $db->query("SELECT setting_value FROM system_settings WHERE category='features' AND setting_key='closures_calendar'");
$featureBefore = ($featureRow instanceof mysqli_result && ($row = $featureRow->fetch_row()) !== null) ? (string) $row[0] : null;
$ids = ['user' => 0, 'sede' => 0, 'book' => 0, 'copy' => 0, 'loan' => 0, 'closure' => 0];
$dueOf = static function () use ($db, &$ids): string {
    return (string) $db->query("SELECT data_scadenza FROM prestiti WHERE id={$ids['loan']}")->fetch_row()[0];
};

try {
    $db->query("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('features','closures_calendar','1') ON DUPLICATE KEY UPDATE setting_value='1'");
    $db->query("INSERT INTO utenti (nome, cognome, email, password, stato, tipo_utente, codice_tessera) VALUES ('Lock', 'Conflict{$run}', 'lock-conflict-{$run}@example.invalid', 'x', 'attivo', 'standard', 'LCK{$run}')");
    $ids['user'] = (int) $db->insert_id;
    $db->query("INSERT INTO sedi (nome) VALUES ('Lock conflict {$run}')");
    $ids['sede'] = (int) $db->insert_id;
    $db->query("INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato, created_at, updated_at) VALUES ('Lock conflict {$run}', 1, 0, 'prestato', NOW(), NOW())");
    $ids['book'] = (int) $db->insert_id;
    $db->query("INSERT INTO copie (libro_id, numero_inventario, stato, sede_id, created_at) VALUES ({$ids['book']}, 'LCK-{$run}', 'prestato', {$ids['sede']}, NOW())");
    $ids['copy'] = (int) $db->insert_id;
    $db->query("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES ({$ids['book']}, {$ids['copy']}, {$ids['user']}, '{$today}', '{$due}', 'in_corso', 'diretto', 1)");
    $ids['loan'] = (int) $db->insert_id;
    // The branch closes on the due date: the save would move it to the next day.
    $db->query("INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, sede_id, attiva) VALUES ('{$due}', '{$due}', 'Lock conflict {$run}', {$ids['sede']}, 1)");
    $ids['closure'] = (int) $db->insert_id;

    // Another transaction holds the book row (a reservation being confirmed).
    $other->begin_transaction();
    $other->query("SELECT id FROM libri WHERE id={$ids['book']} FOR UPDATE")->fetch_all();

    $complete = LibraryCalendar::extendDueDatesAfterSave($db);
    $check($complete === false, 'a move blocked by a lock is reported incomplete (the page warns)');
    $check($dueOf() === $due, 'the blocked loan keeps its date, nothing half-written');

    $other->rollback();
    $complete = LibraryCalendar::extendDueDatesAfterSave($db);
    $check($complete === true, 'with the lock gone the next run completes');
    $check($dueOf() === $nextDay, 'and moves the due date to the next open day');
} finally {
    try {
        $other->rollback();
    } catch (\Throwable) {
    }
    if ($ids['loan'] > 0) {
        $db->query("DELETE FROM prestiti WHERE id={$ids['loan']}");
    }
    if ($ids['book'] > 0) {
        $db->query("DELETE FROM log_modifiche WHERE tabella='libri' AND record_id={$ids['book']}");
        $db->query("DELETE FROM copie WHERE libro_id={$ids['book']}");
        $db->query("DELETE FROM libri WHERE id={$ids['book']}");
    }
    if ($ids['closure'] > 0) {
        $db->query("DELETE FROM calendario_chiusure WHERE id={$ids['closure']}");
    }
    if ($ids['sede'] > 0) {
        $db->query("DELETE FROM sedi WHERE id={$ids['sede']}");
    }
    if ($ids['user'] > 0) {
        $db->query("DELETE FROM utenti WHERE id={$ids['user']}");
    }
    if ($featureBefore === null) {
        $db->query("DELETE FROM system_settings WHERE category='features' AND setting_key='closures_calendar'");
    } else {
        $db->query("UPDATE system_settings SET setting_value='" . $db->real_escape_string($featureBefore) . "' WHERE category='features' AND setting_key='closures_calendar'");
    }
}

echo "\nPassed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);

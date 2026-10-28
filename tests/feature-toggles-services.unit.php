<?php
declare(strict_types=1);

/**
 * Service-level behaviour of the optional modules switched off (plan §2.3,
 * §2.5-2.6, §2.9-2.11), against the slot database. The switch is set with
 * Features::override(), so the stored value of the database is irrelevant.
 *
 *  A. closures calendar: off = an empty calendar even with closures stored,
 *     due dates as before 0.9.0; on = the stored closure moves the due date;
 *  B. circulation rules: off = no patron types, every copy lendable, the
 *     consultation flag ignored; on = the types and the flag apply;
 *  C. fines: off = no charge, no preview, no debt block in PHP nor in SQL,
 *     even for a patron far above the threshold; on = blocked;
 *  D. RDA types: off = stored overrides ignored by the record view and MARC;
 *  E. suppliers: off = a book save never writes fornitore_id (kept);
 *  F. visitor log: off = maintenance purges nothing; on = the old visit goes;
 *  G. Mobile API /health: the three circulation flags follow their modules.
 *
 * Run: php tests/feature-toggles-services.unit.php (the exit code is the verdict)
 * Data markers: ZZ_FTSVC_% titles, zz-ftsvc-%@test.local accounts.
 */

use App\Services\CirculationPolicy;
use App\Services\PatronLedger;
use App\Support\DateHelper;
use App\Support\Features;
use App\Support\LibraryCalendar;
use App\Support\LoanEligibility;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
putenv('PINAKES_DISABLE_CLI_PURGE=1');

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$env = Dotenv\Dotenv::parse((string) file_get_contents($root . '/.env'));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
    $pass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? '');
    $name = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $user, $pass, $name, 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), $user, $pass, $name, (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
    DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — the behavioural checks are mandatory: {$e->getMessage()}\n");
    exit(1);
}
$tableExists = static fn (string $t): bool => (int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $db->real_escape_string($t) . "'")->fetch_row()[0] === 1;
foreach (['calendario_chiusure', 'tipologie_lettore', 'movimenti_lettore', 'presenze', 'fornitori'] as $t) {
    if (!$tableExists($t)) {
        fwrite(STDERR, "FAIL: {$t} missing — apply migrate_0.9.0.sql first\n");
        exit(1);
    }
}

$run = bin2hex(random_bytes(3));
$saved = [];
foreach (['debt_block_threshold'] as $k) {
    $r = $db->query("SELECT setting_value FROM system_settings WHERE category = 'loans' AND setting_key = '{$k}'")->fetch_row();
    $saved[$k] = $r === null ? null : (string) $r[0];
}
$closureIds = [];
$cleanup = static function () use ($db, &$closureIds): void {
    foreach ($closureIds as $id) {
        $db->query('DELETE FROM calendario_chiusure WHERE id = ' . (int) $id);
    }
    $db->query("DELETE m FROM movimenti_lettore m JOIN utenti u ON u.id = m.utente_id WHERE u.email LIKE 'zz-ftsvc-%@test.local'");
    $db->query("DELETE FROM presenze WHERE nome_ospite LIKE 'ZZ_FTSVC_%'");
    $db->query("DELETE c FROM copie c JOIN libri l ON l.id = c.libro_id WHERE l.titolo LIKE 'ZZ\\_FTSVC\\_%'");
    $db->query("DELETE FROM libri WHERE titolo LIKE 'ZZ\\_FTSVC\\_%'");
    $db->query("DELETE FROM fornitori WHERE nome LIKE 'ZZ_FTSVC_%'");
    $db->query("DELETE FROM utenti WHERE email LIKE 'zz-ftsvc-%@test.local'");
};
$cleanup();

try {
    // ── A. closures calendar ────────────────────────────────────────────────
    $closed = LibraryCalendar::shift(DateHelper::today(), 3);
    $db->query("INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione) VALUES ('{$closed}', '{$closed}', 'ZZ_FTSVC closure')");
    $closureIds[] = (int) $db->insert_id;
    Features::override('closures_calendar', false);
    LibraryCalendar::reset();
    $check(!LibraryCalendar::forDb($db)->hasRules() || LibraryCalendar::fromDatabase($db)->closedDatesBetween($closed, $closed) === [], 'A off: the stored closure is not applied');
    $check(LibraryCalendar::dueDate($db, $closed) === $closed, 'A off: a due date on the closed day stays where it is (0.8.9 behaviour)');
    $check(LibraryCalendar::fromDatabase($db)->closedDatesBetween($closed, $closed) === [], 'A off: availability publishes no closed date');
    $check(CirculationPolicy::dueDateFor(DateHelper::today(), 3, $db) === $closed, 'A off: CirculationPolicy::dueDateFor (web, Mobile API, NCIP) agrees');
    Features::override('closures_calendar', true);
    LibraryCalendar::reset();
    $check(LibraryCalendar::fromDatabase($db)->closedDatesBetween($closed, $closed) === [$closed], 'A on: the closure is published again');
    $check(LibraryCalendar::dueDate($db, $closed) > $closed, 'A on: the due date moves to the next open day');
    LibraryCalendar::reset();

    // ── B. circulation rules ────────────────────────────────────────────────
    Features::override('circulation_rules', false);
    $policy = new CirculationPolicy($db);
    $check($policy->schemaReady() && !$policy->rulesActive(), 'B off: the tables exist, the rules do not apply');
    $check($policy->types() === [] && $policy->rules() === [], 'B off: no patron type and no rule');
    $check($policy->lendableCopySql('c') === '1 = 1', 'B off: every copy counts as lendable');
    $db->query("INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES ('ZZ_FTSVC_consult_{$run}', 1, 1)");
    $bookId = (int) $db->insert_id;
    $db->query("INSERT INTO copie (libro_id, numero_inventario, stato, solo_consultazione) VALUES ({$bookId}, 'ZZFT{$run}', 'disponibile', 1)");
    $copyId = (int) $db->insert_id;
    $check(!$policy->isConsultationCopy($copyId) && !$policy->onlyConsultationCopies($bookId), 'B off: a consultation-only copy is lendable');
    $check($policy->forViewer(null, $bookId)->loanable === true, 'B off: the title is loanable for everybody');
    Features::override('circulation_rules', true);
    $policy = new CirculationPolicy($db);
    $check($policy->rulesActive() && $policy->types() !== [], 'B on: the seeded patron types apply');
    $check($policy->isConsultationCopy($copyId) && $policy->onlyConsultationCopies($bookId), 'B on: the consultation flag applies again');

    // ── C. fines ────────────────────────────────────────────────────────────
    $hash = password_hash('x', PASSWORD_DEFAULT);
    $db->query("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, email_verificata, tipo_utente, privacy_accettata) VALUES ('ZZFS{$run}', 'Zz', 'Svc', 'zz-ftsvc-{$run}@test.local', '" . $db->real_escape_string($hash) . "', 'attivo', 1, 'standard', 1)");
    $patron = (int) $db->insert_id;
    $db->query("INSERT INTO movimenti_lettore (utente_id, tipo, importo, descrizione, created_at) VALUES ({$patron}, 'addebito_manuale', 100.00, 'ZZ_FTSVC debt', NOW())");
    $db->query("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans', 'debt_block_threshold', '1.00') ON DUPLICATE KEY UPDATE setting_value = '1.00'");
    \App\Support\ConfigStore::clearCache();
    Features::override('fines', false);
    $check(PatronLedger::schemaReady($db) && !PatronLedger::active($db), 'C off: the ledger exists, fines do not apply');
    $check((new CirculationPolicy($db))->debtBlockError($patron) === null, 'C off: a patron 100 over the threshold is not blocked');
    $check(LoanEligibility::checkUser($db, $patron) === null, 'C off: LoanEligibility admits the patron (every channel)');
    $check(LoanEligibility::debtBlockSql('u', $db) === '1 = 1', 'C off: the queue predicate admits everybody');
    $check(PatronLedger::forDb($db)->chargeLateReturn(PHP_INT_MAX) === 0, 'C off: a late return charges nothing');
    $preview = PatronLedger::forDb($db)->previewLateFine(PHP_INT_MAX);
    $check($preview['enabled'] === false && $preview['cents'] === 0, 'C off: the return screen previews no fine');
    $check(PatronLedger::forDb($db)->accruedFines($patron) === [], 'C off: no accruing fine is shown');
    $check(PatronLedger::forDb($db)->backfillLegacyPenalties() === null, 'C off: the legacy backfill waits (not marked as done)');
    $check((new \App\Support\NotificationService($db))->sendFineChargedNotification(PHP_INT_MAX) === false, 'C off: no fine_charged email');
    $check(PatronLedger::forDb($db)->balanceCents($patron) === 10000, 'C off: the rows already written stay');
    Features::override('fines', true);
    $check((new CirculationPolicy($db))->debtBlockError($patron) === 'debt_block', 'C on: the same patron is blocked');
    $check(LoanEligibility::debtBlockSql('u', $db) !== '1 = 1', 'C on: the queue predicate checks the balance');

    // ── D. RDA types ────────────────────────────────────────────────────────
    $row = ['tipo_media' => 'libro', 'rda_content_type' => 'prm', 'rda_media_type' => 'c', 'rda_carrier_type' => 'cr'];
    Features::override('rda_types', false);
    $derived = \App\Support\RdaTypes::derive('libro');
    $check(\App\Support\RdaTypes::overrides($row) === [], 'D off: the stored overrides are not applied');
    $check(\App\Support\RdaTypes::effective($row) === $derived, 'D off: the record shows the types derived from the material');
    $check(\App\Support\Marc\MarcMediaType::rdaCodes($row) === \App\Support\Marc\MarcMediaType::rdaCodes(['tipo_media' => 'libro']), 'D off: MARC 336/337/338 are those of 0.8.9');
    Features::override('rda_types', true);
    $check(\App\Support\RdaTypes::effective($row)['content'] === 'prm', 'D on: the override applies again');

    // ── E. suppliers ────────────────────────────────────────────────────────
    $db->query("INSERT INTO fornitori (nome) VALUES ('ZZ_FTSVC_A_{$run}'), ('ZZ_FTSVC_B_{$run}')");
    $supA = (int) $db->query("SELECT id FROM fornitori WHERE nome = 'ZZ_FTSVC_A_{$run}'")->fetch_row()[0];
    $supB = (int) $db->query("SELECT id FROM fornitori WHERE nome = 'ZZ_FTSVC_B_{$run}'")->fetch_row()[0];
    $db->query("UPDATE libri SET fornitore_id = {$supA} WHERE id = {$bookId}");
    Features::override('suppliers', false);
    \App\Support\CataloguingExtras::save($db, $bookId, ['fornitore_id' => '']);
    \App\Support\CataloguingExtras::assignSupplier($db, $bookId, $supB);
    $check((int) $db->query("SELECT fornitore_id FROM libri WHERE id = {$bookId}")->fetch_row()[0] === $supA, 'E off: neither a save nor an import touches the stored supplier');
    Features::override('suppliers', true);
    \App\Support\CataloguingExtras::save($db, $bookId, ['fornitore_id' => (string) $supB]);
    $check((int) $db->query("SELECT fornitore_id FROM libri WHERE id = {$bookId}")->fetch_row()[0] === $supB, 'E on: the form writes the supplier');

    // ── F. visitor log retention ────────────────────────────────────────────
    $db->query("INSERT INTO presenze (tipo, nome_ospite, created_at) VALUES ('ospite', 'ZZ_FTSVC_old_{$run}', NOW() - INTERVAL 20 YEAR)");
    $visit = (int) $db->insert_id;
    $db->query("DELETE FROM system_settings WHERE category = 'maintenance' AND setting_key = 'last_run'");
    Features::override('visitor_log', false);
    $result = (new \App\Support\MaintenanceService($db))->runAll();
    $check((int) ($result['attendance_purged'] ?? -1) === 0, 'F off: maintenance reports no visitor purged');
    $check((int) $db->query("SELECT COUNT(*) FROM presenze WHERE id = {$visit}")->fetch_row()[0] === 1, 'F off: a visit older than the retention stays');
    Features::override('visitor_log', true);
    (new \App\Support\MaintenanceService($db))->runAll();
    $check((int) $db->query("SELECT COUNT(*) FROM presenze WHERE id = {$visit}")->fetch_row()[0] === 0, 'F on: maintenance purges it');

    // ── G. Mobile API /health ───────────────────────────────────────────────
    require_once $root . '/storage/plugins/mobile-api/MobileApiPlugin.php';
    $health = static function () use ($db): array {
        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/api/v1/health');
        $res = (new \App\Plugins\MobileApi\Controllers\HealthController($db))->index($req, new \Slim\Psr7\Response());
        $body = json_decode((string) $res->getBody(), true);
        return (array) ($body['data']['features'] ?? []);
    };
    foreach ([false, true] as $on) {
        foreach (['fines', 'closures_calendar', 'circulation_rules'] as $k) {
            Features::override($k, $on);
        }
        $flags = $health();
        $check(($flags['fines'] ?? null) === $on && ($flags['closures_calendar'] ?? null) === $on && ($flags['circulation_rules'] ?? null) === $on,
            '/health features.fines, closures_calendar, circulation_rules = ' . ($on ? 'true' : 'false'));
    }
    $check(array_key_exists('loans', $health()), '/health keeps every existing flag (additive)');
} finally {
    Features::resetOverrides();
    LibraryCalendar::reset();
    foreach ($saved as $k => $v) {
        if ($v === null) {
            $db->query("DELETE FROM system_settings WHERE category = 'loans' AND setting_key = '{$k}'");
        } else {
            $db->query("UPDATE system_settings SET setting_value = '" . $db->real_escape_string($v) . "' WHERE category = 'loans' AND setting_key = '{$k}'");
        }
    }
    \App\Support\ConfigStore::clearCache();
    $cleanup();
}

echo PHP_EOL . "{$passed} PASS, {$failed} FAIL" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

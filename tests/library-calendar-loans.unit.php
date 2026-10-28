<?php
declare(strict_types=1);

/**
 * Closures calendar applied to the loan code, against the real database:
 * the dueDate()/pickupDeadline() funnels reading loans.closed_weekdays and
 * calendario_chiusure, branch closures through the copy's sede, the renewal
 * cap stepping back to an open day, and the nightly expiry jobs doing nothing
 * on a closed day.
 *
 * Run: php tests/library-calendar-loans.unit.php
 */

use App\Services\CirculationPolicy;
use App\Support\DateHelper;
use App\Support\LibraryCalendar;
use App\Support\LoanWindow;
use App\Support\MaintenanceService;

require dirname(__DIR__) . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$root = dirname(__DIR__);
$env = Dotenv\Dotenv::parse((string) file_get_contents($root . '/.env'));
$db = new mysqli($env['DB_HOST'] ?? 'localhost', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
$db->set_charset('utf8mb4');

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "OK   {$label}\n";
    } else {
        $failed++;
        echo "FAIL {$label}\n";
    }
};

if ($db->query("SHOW TABLES LIKE 'calendario_chiusure'")->num_rows === 0) {
    echo "calendario_chiusure missing: run migrate_0.9.0.sql first\n";
    exit(1);
}

$run = bin2hex(random_bytes(3));
$tag = "zz-cal-{$run}";
$prevWeekdays = $db->query("SELECT setting_value FROM system_settings WHERE category = 'loans' AND setting_key = 'closed_weekdays'")->fetch_row();
$bookId = 0;
$userId = 0;
$sedeId = 0;
$setWeekdays = static function (string $value) use ($db): void {
    $stmt = $db->prepare("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans', 'closed_weekdays', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->bind_param('s', $value);
    $stmt->execute();
    $stmt->close();
    LibraryCalendar::reset();
};
$addClosure = static function (string $from, string $to, ?int $sede = null, int $yearly = 0) use ($db, $tag): void {
    $stmt = $db->prepare('INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, ricorrente_annuale, sede_id) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('sssii', $from, $to, $tag, $yearly, $sede);
    $stmt->execute();
    $stmt->close();
    LibraryCalendar::reset();
};
$clearClosures = static function () use ($db, $tag): void {
    $db->query("DELETE FROM calendario_chiusure WHERE descrizione = '{$tag}'");
    LibraryCalendar::reset();
};
$cleanup = static function () use ($db, $tag, $prevWeekdays, &$bookId, &$userId, &$sedeId): void {
    $db->query("DELETE FROM calendario_chiusure WHERE descrizione = '{$tag}'");
    if ($prevWeekdays === null) {
        $db->query("DELETE FROM system_settings WHERE category = 'loans' AND setting_key = 'closed_weekdays'");
    } else {
        $stmt = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE category = 'loans' AND setting_key = 'closed_weekdays'");
        $stmt->bind_param('s', $prevWeekdays[0]);
        $stmt->execute();
    }
    if ($bookId > 0) {
        $db->query("DELETE FROM admin_notifications WHERE related_id IN (SELECT id FROM prestiti WHERE libro_id = {$bookId})");
        $db->query("DELETE FROM prestiti WHERE libro_id = {$bookId}");
        $db->query("DELETE FROM copie WHERE libro_id = {$bookId}");
        $db->query("DELETE FROM libri WHERE id = {$bookId}");
    }
    if ($userId > 0) {
        $db->query("DELETE FROM utenti WHERE id = {$userId}");
    }
    if ($sedeId > 0) {
        $db->query("DELETE FROM sedi WHERE id = {$sedeId}");
    }
    LibraryCalendar::reset();
};
register_shutdown_function($cleanup);

$today = DateHelper::today();
$shift = static fn (string $d, int $n): string => LibraryCalendar::shift($d, $n);

// ── Zero config ───────────────────────────────────────────────────────────
$setWeekdays('');
$clearClosures();
$plain = $shift($today, 30);
$check(LibraryCalendar::dueDate($db, $plain) === $plain, 'zero config: dueDate is the computed date');
$check(LibraryCalendar::pickupDeadline($db, $today, 3) === $shift($today, 3), 'zero config: pickupDeadline is today + N');
$check(LibraryCalendar::pickupDeadline($db, $today, 3, $shift($today, 1)) === $shift($today, 1), 'pickupDeadline is capped at the due date');

// ── 14-day loan ending on Sunday → Monday ─────────────────────────────────
$setWeekdays('0');
$sunday = $shift($today, 20);
while ((int) (new DateTimeImmutable($sunday))->format('w') !== 0) {
    $sunday = $shift($sunday, 1);
}
$start = $shift($sunday, -14);
$check(LibraryCalendar::dueDate($db, $shift($start, 14)) === $shift($sunday, 1), "14-day loan from {$start} ending Sunday {$sunday} moves to Monday");

// ── CirculationPolicy::dueDateFor() is the funnel every due date goes through
$check(CirculationPolicy::dueDateFor($start, 14, $db) === $shift($sunday, 1), 'dueDateFor with the connection: 14 days ending Sunday move to Monday');
$check(CirculationPolicy::dueDateFor($start, 14) === $sunday, 'dueDateFor without a connection is plain arithmetic');
$check(CirculationPolicy::dueDateFor($sunday, 0, $db) === $shift($sunday, 1), 'dueDateFor(typed date, 0) moves a typed Sunday to Monday');
$check(CirculationPolicy::dueDateFor($shift($sunday, -10), 10, $db) === $shift($sunday, 1), 'a 10-day patron-type duration ending Sunday moves to Monday');
$check(CirculationPolicy::dueDateFor($shift($sunday, 1), 0, $db) === $shift($sunday, 1), 'dueDateFor leaves an open day alone');
$setWeekdays('');
$check(CirculationPolicy::dueDateFor($start, 14, $db) === $sunday, 'dueDateFor with no closures equals the historical arithmetic');

// ── Ending Dec 25 with 24–26 closed → 27 ─────────────────────────────────
$setWeekdays('');
$year = (int) substr($today, 0, 4) + 1;
$addClosure("{$year}-12-24", "{$year}-12-26");
$check(LibraryCalendar::dueDate($db, $shift("{$year}-12-11", 14)) === "{$year}-12-27", "14-day loan ending {$year}-12-25 with 24-26 closed moves to the 27th");
$check(LibraryCalendar::pickupDeadline($db, "{$year}-12-22", 3) === "{$year}-12-28", 'pickup over Christmas counts only open days (23, 27, 28)');

// ── Branch-specific closure through the copy's sede ───────────────────────
$clearClosures();
$db->query("INSERT INTO sedi (nome) VALUES ('{$tag}')");
$sedeId = (int) $db->insert_id;
$title = "Calendar test {$run}";
$stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES (?, 1, 1)");
$stmt->bind_param('s', $title);
$stmt->execute();
$bookId = (int) $db->insert_id;
$stmt->close();
$inv = "ZZCAL-{$run}";
$stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato, sede_id) VALUES (?, ?, 'disponibile', ?)");
$stmt->bind_param('isi', $bookId, $inv, $sedeId);
$stmt->execute();
$copyId = (int) $db->insert_id;
$stmt->close();
$branchDay = $shift($today, 40);
$addClosure($branchDay, $branchDay, $sedeId);
$check(LibraryCalendar::dueDate($db, $branchDay, $copyId) === $shift($branchDay, 1), "a closure of the copy's branch moves its due date");
$check(LibraryCalendar::dueDate($db, $branchDay) === $branchDay, 'the same closure does not move a loan without that branch');
$check(CirculationPolicy::dueDateFor($shift($branchDay, -7), 7, $db, $copyId) === $shift($branchDay, 1), "dueDateFor applies the copy's branch closures when the copy is known");

// ── Renewal cap steps back to an open day ─────────────────────────────────
$clearClosures();
$conflict = $shift($today, 50);
$closedBefore = $shift($conflict, -1);
$addClosure($closedBefore, $closedBefore);
$cap = LoanWindow::renewalCap($shift($today, 30), [$conflict], null, LibraryCalendar::forDb($db));
$check($cap !== null && $cap['max_due_date'] === $shift($conflict, -2), 'renewal cap: the day before the conflict is closed, so the proposal steps back one more day');
$capNoCal = LoanWindow::renewalCap($shift($today, 30), [$conflict]);
$check($capNoCal !== null && $capNoCal['max_due_date'] === $closedBefore, 'renewal cap without a calendar is unchanged');

// ── Nightly jobs ──────────────────────────────────────────────────────────
$clearClosures();
$email = "{$tag}@test.local";
$card = strtoupper("ZZCAL{$run}");
$hash = password_hash('Calendar!1', PASSWORD_DEFAULT);
$stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata) VALUES (?, 'Cal', 'Test', ?, ?, 'standard', 'attivo', 1)");
$stmt->bind_param('sss', $card, $email, $hash);
$stmt->execute();
$userId = (int) $db->insert_id;
$stmt->close();
$db->query("UPDATE copie SET stato = 'prenotato' WHERE id = {$copyId}");
$yesterday = $shift($today, -1);
$loanStart = $shift($today, -4);
$loanEnd = $shift($today, 20);
$stmt = $db->prepare("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo, pickup_deadline) VALUES (?, ?, ?, ?, ?, 'da_ritirare', 1, ?)");
$stmt->bind_param('iiisss', $bookId, $copyId, $userId, $loanStart, $loanEnd, $yesterday);
$stmt->execute();
$pickupLoan = (int) $db->insert_id;
$stmt->close();
$stateOf = static fn (int $id): string => (string) $db->query("SELECT stato FROM prestiti WHERE id = {$id}")->fetch_row()[0];

$maintenance = new MaintenanceService($db);
$addClosure($today, $today);
$check($maintenance->checkExpiredPickups() === 0 && $stateOf($pickupLoan) === 'da_ritirare', 'checkExpiredPickups expires nothing on a closed day');
$check($maintenance->checkExpiredReservations() === 0, 'checkExpiredReservations returns 0 on a closed day');
$clearClosures();
$addClosure($yesterday, $yesterday);
$maintenance->checkExpiredPickups();
$check($stateOf($pickupLoan) === 'da_ritirare', 'a deadline on a later-declared closed day lasts until the next open day (today)');
$clearClosures();
$maintenance->checkExpiredPickups();
$check($stateOf($pickupLoan) !== 'da_ritirare', 'with the library open the overdue pickup expires as before');

echo ($failed === 0 ? 'SUCCESS' : 'FAILURE') . " {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

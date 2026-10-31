<?php
declare(strict_types=1);

/**
 * Closures calendar applied to the loan code, against the real database:
 * the dueDate()/pickupDeadline() funnels reading loans.closed_weekdays and
 * calendario_chiusure, branch closures through the copy's sede, the renewal
 * cap stepping back to an open day, the nightly expiry jobs doing nothing
 * on a closed day, and the NCIP checkout moving its due date with the
 * chosen copy's branch.
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
    echo "calendario_chiusure missing: run migrate_0.9.0-rc.1.sql first\n";
    exit(1);
}

$run = bin2hex(random_bytes(3));
$tag = "zz-cal-{$run}";
$prevWeekdays = $db->query("SELECT setting_value FROM system_settings WHERE category = 'loans' AND setting_key = 'closed_weekdays'")->fetch_row();
$bookId = 0;
$userId = 0;
$otherUserId = 0;
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
$cleanup = static function () use ($db, $tag, $prevWeekdays, &$bookId, &$userId, &$otherUserId, &$sedeId): void {
    $db->query("DELETE FROM calendario_chiusure WHERE descrizione = '{$tag}'");
    if ($prevWeekdays === null) {
        $db->query("DELETE FROM system_settings WHERE category = 'loans' AND setting_key = 'closed_weekdays'");
    } else {
        $stmt = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE category = 'loans' AND setting_key = 'closed_weekdays'");
        $stmt->bind_param('s', $prevWeekdays[0]);
        $stmt->execute();
    }
    if ($bookId > 0) {
        $db->query("DELETE FROM prenotazioni WHERE libro_id = {$bookId}");
        $db->query("DELETE FROM admin_notifications WHERE related_id IN (SELECT id FROM prestiti WHERE libro_id = {$bookId})");
        $db->query("DELETE FROM prestiti WHERE libro_id = {$bookId}");
        $db->query("DELETE FROM copie WHERE libro_id = {$bookId}");
        $db->query("DELETE FROM libri WHERE id = {$bookId}");
    }
    if ($userId > 0) {
        $db->query("DELETE FROM utenti WHERE id = {$userId}");
    }
    if ($otherUserId > 0) {
        $db->query("DELETE FROM utenti WHERE id = {$otherUserId}");
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
$clearClosures();
$addClosure($closedBefore, $closedBefore, $sedeId);
$capBranch = LoanWindow::renewalCap($shift($today, 30), [$conflict], null, LibraryCalendar::forDb($db), LibraryCalendar::branchOfCopy($db, $copyId));
$check($capBranch !== null && $capBranch['max_due_date'] === $shift($conflict, -2), "renewal cap: a closure of the copy's branch on the day before the conflict steps back too");
$check(LibraryCalendar::dueDate($db, $capBranch['max_due_date'] ?? '', $copyId) === ($capBranch['max_due_date'] ?? ''), "the proposed date stays put when the renewal moves it with the copy's branch closures");
$capLibrary = LoanWindow::renewalCap($shift($today, 30), [$conflict], null, LibraryCalendar::forDb($db));
$check($capLibrary !== null && $capLibrary['max_due_date'] === $closedBefore, 'without the branch the branch closure is ignored, as before');

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
// A closure of the copy's branch only: the library is open, that branch is not.
$clearClosures();
$addClosure($today, $today, $sedeId);
$maintenance->checkExpiredPickups();
$check($stateOf($pickupLoan) === 'da_ritirare', "checkExpiredPickups keeps a pickup whose copy's branch is closed today");
$schedStart = $shift($today, -6);
// A second copy of the same branch (the first one holds the pickup).
$inv2 = "ZZCAL-{$run}-2";
$stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato, sede_id) VALUES (?, ?, 'prenotato', ?)");
$stmt->bind_param('isi', $bookId, $inv2, $sedeId);
$stmt->execute();
$copy2Id = (int) $db->insert_id;
$stmt->close();
$stmt = $db->prepare("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo) VALUES (?, ?, ?, ?, ?, 'prenotato', 1)");
$stmt->bind_param('iiiss', $bookId, $copy2Id, $userId, $schedStart, $yesterday);
$stmt->execute();
$schedLoan = (int) $db->insert_id;
$stmt->close();
$maintenance->checkExpiredReservations();
$check($stateOf($schedLoan) === 'prenotato', "checkExpiredReservations keeps a reservation whose copy's branch is closed today");
$clearClosures();
$maintenance->checkExpiredReservations();
$check($stateOf($schedLoan) === 'scaduto', 'with the branch open the same reservation expires as before');
$db->query("DELETE FROM prestiti WHERE id = {$schedLoan}");
$db->query("DELETE FROM copie WHERE id = {$copy2Id}");
$db->query("UPDATE copie SET stato = 'prenotato' WHERE id = {$copyId}");
$clearClosures();
$addClosure($yesterday, $yesterday);
// What the calendar page does after every save.
$check(LibraryCalendar::extendPickupDeadlines($db) >= 1, 'saving the calendar moves the pickup whose deadline is now closed');
$deadlineOf = static fn (int $id): string => (string) $db->query("SELECT pickup_deadline FROM prestiti WHERE id = {$id}")->fetch_row()[0];
$check($deadlineOf($pickupLoan) === $today, 'the stored deadline is the next open day (today), the one every check reads');
$maintenance->checkExpiredPickups();
$check($stateOf($pickupLoan) === 'da_ritirare', 'a deadline on a later-declared closed day lasts until the next open day (today)');
$check(LibraryCalendar::extendPickupDeadlines($db) === 0, 'a second save moves nothing (idempotent)');

// The extension never passes the loan's due date (confirmPickup() refuses a
// pickup after the loan window), and the due date itself is not moved.
$clearClosures();
$pickupDue = $shift($today, 3);
$db->query("UPDATE prestiti SET pickup_deadline = '{$pickupDue}', data_scadenza = '{$pickupDue}' WHERE id = {$pickupLoan}");
$addClosure($pickupDue, $shift($pickupDue, 2));
$check(LibraryCalendar::extendPickupDeadlines($db) === 0, 'a deadline equal to the due date is left alone when the due date is closed');
$check($deadlineOf($pickupLoan) === $pickupDue, 'its stored deadline still is the due date');
$clearClosures();
$db->query("UPDATE prestiti SET pickup_deadline = '{$shift($pickupDue, -1)}' WHERE id = {$pickupLoan}");
$addClosure($shift($pickupDue, -1), $shift($pickupDue, 2));
$check(LibraryCalendar::extendPickupDeadlines($db) >= 1, 'a deadline the day before the due date, closed through after it, moves');
$check($deadlineOf($pickupLoan) === $pickupDue, 'and stops at the due date, not at the next open day');
$check((string) $db->query("SELECT data_scadenza FROM prestiti WHERE id = {$pickupLoan}")->fetch_row()[0] === $pickupDue, 'the due date of the existing loan is not moved');
$clearClosures();
$db->query("UPDATE prestiti SET pickup_deadline = '{$shift($pickupDue, -1)}', data_scadenza = '{$shift($pickupDue, 5)}' WHERE id = {$pickupLoan}");
$addClosure($shift($pickupDue, -1), $pickupDue);
$check(LibraryCalendar::extendPickupDeadlines($db) >= 1 && $deadlineOf($pickupLoan) === $shift($pickupDue, 1), 'with room before the due date the deadline moves to the next open day as before');
$db->query("UPDATE prestiti SET pickup_deadline = '{$yesterday}', data_scadenza = '{$loanEnd}' WHERE id = {$pickupLoan}");
$clearClosures();
$db->query("UPDATE prestiti SET pickup_deadline = '{$yesterday}' WHERE id = {$pickupLoan}");
$check(LibraryCalendar::extendPickupDeadlines($db) === 0, 'without closures no deadline moves');
$maintenance->checkExpiredPickups();
$check($stateOf($pickupLoan) !== 'da_ritirare', 'with the library open the overdue pickup expires as before');

// Queue reservation past its deadline: kept on a closed day, expired once open.
$db->query("DELETE FROM prestiti WHERE libro_id = {$bookId}");
$resStart = $shift($today, -6);
$resDeadline = $shift($today, -1) . ' 23:59:59';
$stmt = $db->prepare("INSERT INTO prenotazioni (libro_id, utente_id, queue_position, stato, data_prenotazione, data_scadenza_prenotazione, data_inizio_richiesta, data_fine_richiesta) VALUES (?, ?, 1, 'attiva', ?, ?, ?, ?)");
$resEnd = $shift($today, -1);
$stmt->bind_param('iissss', $bookId, $userId, $resStart, $resDeadline, $resStart, $resEnd);
$stmt->execute();
$queueRes = (int) $db->insert_id;
$stmt->close();
$resState = static fn (): string => (string) $db->query("SELECT stato FROM prenotazioni WHERE id = {$queueRes}")->fetch_row()[0];
$addClosure($today, $today);
$check((new \App\Controllers\ReservationManager($db))->cancelExpiredReservations() === 0 && $resState() === 'attiva', 'the queue expiry keeps a reservation on a closed day');
$clearClosures();
(new \App\Controllers\ReservationManager($db))->cancelExpiredReservations();
$check($resState() !== 'attiva', 'with the library open the expired queue reservation is cancelled as before');
$db->query("DELETE FROM prenotazioni WHERE id = {$queueRes}");

// ── NCIP CheckOutItem: the due date moves with the chosen copy's branch ───
// handleCheckOutItem() computes the library-wide due date before any copy is
// known; createLoanAtomic() picks the copy under the book lock and must apply
// that copy's branch closures to the date it stores (as the desk does), and
// hand the stored date back for the response's DateDue.
require_once $root . '/storage/plugins/ncip-server/NcipServerPlugin.php';
$clearClosures();
$db->query("DELETE FROM prestiti WHERE libro_id = {$bookId}");
$db->query("UPDATE copie SET stato = 'disponibile' WHERE id = {$copyId}");
$db->query("UPDATE libri SET copie_disponibili = 1 WHERE id = {$bookId}");
$ncip = new \App\Plugins\NcipServer\NcipServerPlugin($db, new \App\Support\HookManager($db));
$createLoanAtomic = new ReflectionMethod($ncip, 'createLoanAtomic');
$ncipPlainDue = $shift($today, 15);
$addClosure($ncipPlainDue, $ncipPlainDue, $sedeId);
$check(LibraryCalendar::dueDate($db, $ncipPlainDue) === $ncipPlainDue, 'NCIP: the library-wide due date is open (the closure is of the branch only)');
$ncipDue = $ncipPlainDue;
$ncipFailure = null;
$ncipLoan = $createLoanAtomic->invokeArgs($ncip, [$bookId, $userId, &$ncipDue, 0, &$ncipFailure]);
$check(is_int($ncipLoan) && $ncipLoan > 0, 'NCIP: the checkout with a branch closure on its due date is created (' . var_export($ncipFailure, true) . ')');
$ncipStored = (string) $db->query('SELECT data_scadenza FROM prestiti WHERE id = ' . (int) $ncipLoan)->fetch_row()[0];
$check($ncipStored === $shift($ncipPlainDue, 1), "NCIP: the stored data_scadenza is the next open day of the copy's branch");
$check($ncipDue === $ncipStored, 'NCIP: the handler is handed the stored date (the DateDue of the response)');

// The shifted window is checked again: a commitment on the copy from the
// day after the closure refuses the checkout instead of storing a due date
// on the closed day (the title has one copy, so capacity refuses first).
$db->query('DELETE FROM prestiti WHERE id = ' . (int) $ncipLoan);
$db->query("UPDATE copie SET stato = 'disponibile' WHERE id = {$copyId}");
$otherEmail = "{$tag}-b@test.local";
$otherCard = strtoupper("ZZCAL{$run}B");
$stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata) VALUES (?, 'Cal', 'Other', ?, ?, 'standard', 'attivo', 1)");
$stmt->bind_param('sss', $otherCard, $otherEmail, $hash);
$stmt->execute();
$otherUserId = (int) $db->insert_id;
$stmt->close();
$heldFrom = $shift($ncipPlainDue, 1);
$heldTo = $shift($ncipPlainDue, 10);
$stmt = $db->prepare("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo) VALUES (?, ?, ?, ?, ?, 'prenotato', 1)");
$stmt->bind_param('iiiss', $bookId, $copyId, $otherUserId, $heldFrom, $heldTo);
$stmt->execute();
$stmt->close();
$ncipDue = $ncipPlainDue;
$ncipFailure = null;
$ncipLoan2 = $createLoanAtomic->invokeArgs($ncip, [$bookId, $userId, &$ncipDue, 0, &$ncipFailure]);
$check($ncipLoan2 === null && in_array($ncipFailure, ['no_capacity', 'no_copy'], true), 'NCIP: a commitment from the shifted day refuses the checkout (' . var_export($ncipFailure, true) . ') rather than storing a closed due date');
$check($ncipDue === $ncipPlainDue, 'NCIP: a refused checkout leaves the handed-in date alone');
$check((int) $db->query("SELECT COUNT(*) FROM prestiti WHERE libro_id = {$bookId} AND utente_id = {$userId}")->fetch_row()[0] === 0, 'NCIP: nothing was stored for the refused checkout');

// A second copy with no branch, committed from the same day: it comes second
// in the preference order (same next commitment, later inventory number), and
// its due date needs no shift. The first copy's longer window does not fit,
// so the checkout falls through to it instead of being refused.
$inv3 = "ZZCAL-{$run}-3";
$stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato, sede_id) VALUES (?, ?, 'disponibile', NULL)");
$stmt->bind_param('is', $bookId, $inv3);
$stmt->execute();
$copy3Id = (int) $db->insert_id;
$stmt->close();
$db->query("UPDATE libri SET copie_totali = 2, copie_disponibili = 2 WHERE id = {$bookId}");
$stmt = $db->prepare("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo) VALUES (?, ?, ?, ?, ?, 'prenotato', 1)");
$stmt->bind_param('iiiss', $bookId, $copy3Id, $otherUserId, $heldFrom, $heldTo);
$stmt->execute();
$stmt->close();
$ncipDue = $ncipPlainDue;
$ncipFailure = null;
$ncipLoan3 = $createLoanAtomic->invokeArgs($ncip, [$bookId, $userId, &$ncipDue, 0, &$ncipFailure]);
$check(is_int($ncipLoan3) && $ncipLoan3 > 0, 'NCIP: when the preferred copy cannot take its shifted window, the next copy is used (' . var_export($ncipFailure, true) . ')');
$check(is_int($ncipLoan3) && (int) $db->query("SELECT copia_id FROM prestiti WHERE id = {$ncipLoan3}")->fetch_row()[0] === $copy3Id, 'NCIP: the loan is on the copy whose branch is open on the due date');
$check($ncipDue === $ncipPlainDue, 'NCIP: and keeps the unshifted due date');
$clearClosures();

// ── The admin page: input validation and the whole-year guard ────────────
$clearClosures();
$_SESSION = ['user' => ['id' => 1, 'tipo_utente' => 'admin', 'stato' => 'attivo']];
$controller = new \App\Controllers\Admin\LibraryCalendarController();
$post = static function (array $body): \Psr\Http\Message\ServerRequestInterface {
    return (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/admin/calendar')->withParsedBody($body);
};
$flash = static function (): array {
    $out = [$_SESSION['success_message'] ?? null, $_SESSION['error_message'] ?? null];
    unset($_SESSION['success_message'], $_SESSION['error_message']);
    return $out;
};
$weekdaysNow = static fn (): string => (string) ($db->query("SELECT setting_value FROM system_settings WHERE category = 'loans' AND setting_key = 'closed_weekdays'")->fetch_row()[0] ?? '');
$taggedRows = static fn (): int => (int) $db->query("SELECT COUNT(*) FROM calendario_chiusure WHERE descrizione = '{$tag}'")->fetch_row()[0];
$setWeekdays('0');
$controller->saveWeekdays($post(['closed_weekdays' => ['1', 'x']]), new \Slim\Psr7\Response(), $db);
[$ok, $err] = $flash();
$check($err !== null && $ok === null && $weekdaysNow() === '0', 'weekdays: one malformed entry refuses the whole save (Sunday stays closed)');
$controller->saveWeekdays($post(['closed_weekdays' => '1']), new \Slim\Psr7\Response(), $db);
[$ok, $err] = $flash();
$check($err !== null && $weekdaysNow() === '0', 'weekdays: a scalar instead of a list is refused');
$controller->saveWeekdays($post([]), new \Slim\Psr7\Response(), $db);
[$ok, $err] = $flash();
$check($ok !== null && $weekdaysNow() === '', 'weekdays: no field reopens the week on purpose');
$setWeekdays('');

$controller->store($post(['data_inizio' => $shift($today, 50), 'descrizione' => $tag, 'sede_id' => [(string) $sedeId]]), new \Slim\Psr7\Response(), $db);
[$ok, $err] = $flash();
$check($err === __('Sede non trovata.') && $taggedRows() === 0, 'closure: an array sede_id is refused, not stored for every branch');
$controller->store($post(['data_inizio' => $shift($today, 50), 'descrizione' => $tag, 'sede_id' => '999999999']), new \Slim\Psr7\Response(), $db);
[$ok, $err] = $flash();
$check($err === __('Sede non trovata.') && $taggedRows() === 0, 'closure: an unknown sede_id is refused');
$controller->store($post(['data_inizio' => $shift($today, 50), 'descrizione' => $tag, 'sede_id' => '']), new \Slim\Psr7\Response(), $db);
[$ok, $err] = $flash();
$check($ok !== null && $taggedRows() === 1, 'closure: an empty sede_id is every branch');
$clearClosures();

$controller->store($post(['data_inizio' => '2026-01-01', 'data_fine' => '2026-06-30', 'ricorrente_annuale' => '1', 'descrizione' => $tag]), new \Slim\Psr7\Response(), $db);
[$ok, $err] = $flash();
$check($ok !== null && $taggedRows() === 1, 'yearly: the first half of the year is stored');
$controller->store($post(['data_inizio' => '2026-07-01', 'data_fine' => '2026-12-31', 'ricorrente_annuale' => '1', 'descrizione' => $tag]), new \Slim\Psr7\Response(), $db);
[$ok, $err] = $flash();
$check($err !== null && $taggedRows() === 1, 'yearly: a second half that would close every day is refused and rolled back');
$controller->store($post(['data_inizio' => '2026-07-01', 'data_fine' => '2026-12-30', 'ricorrente_annuale' => '1', 'descrizione' => $tag]), new \Slim\Psr7\Response(), $db);
[$ok, $err] = $flash();
$check($ok !== null && $taggedRows() === 2, 'yearly: leaving Dec 31 open is accepted');
$check(LibraryCalendar::forDb($db)->nextOpenDay('2027-03-10') === '2027-12-31', 'and a due date reaches that open day');
$clearClosures();
$controller->store($post(['data_inizio' => '2026-01-01', 'data_fine' => '2026-06-30', 'ricorrente_annuale' => '1', 'descrizione' => $tag, 'sede_id' => (string) $sedeId]), new \Slim\Psr7\Response(), $db);
$flash();
$controller->store($post(['data_inizio' => '2026-07-01', 'data_fine' => '2026-12-31', 'ricorrente_annuale' => '1', 'descrizione' => $tag]), new \Slim\Psr7\Response(), $db);
[$ok, $err] = $flash();
$check($err !== null && $taggedRows() === 1, "yearly: a library-wide half that closes the year of one branch is refused");
$clearClosures();

$icsFile = static function (string $body): \Slim\Psr7\UploadedFile {
    $path = tempnam(sys_get_temp_dir(), 'calics');
    file_put_contents($path, $body);
    return new \Slim\Psr7\UploadedFile($path, 'cal.ics', 'text/calendar', strlen($body), UPLOAD_ERR_OK);
};
$halves = "BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART;VALUE=DATE:20260101\nDTEND;VALUE=DATE:20260701\nRRULE:FREQ=YEARLY\nSUMMARY:{$tag}\nEND:VEVENT\n"
    . "BEGIN:VEVENT\nDTSTART;VALUE=DATE:20260701\nDTEND;VALUE=DATE:20270101\nRRULE:FREQ=YEARLY\nSUMMARY:{$tag}\nEND:VEVENT\nEND:VCALENDAR\n";
$controller->importIcs($post(['sede_id' => '999999999'])->withUploadedFiles(['ics_file' => $icsFile($halves)]), new \Slim\Psr7\Response(), $db);
[$ok, $err] = $flash();
$check($err === __('Sede non trovata.') && $taggedRows() === 0, 'ICS: an unknown sede_id refuses the import');
$controller->importIcs($post(['sede_id' => ''])->withUploadedFiles(['ics_file' => $icsFile($halves)]), new \Slim\Psr7\Response(), $db);
[$ok, $err] = $flash();
$check($ok !== null && $taggedRows() === 1, 'ICS: the yearly event that would close the whole year is discarded, the other kept');
$clearClosures();
$_SESSION = [];

echo ($failed === 0 ? 'SUCCESS' : 'FAILURE') . " {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

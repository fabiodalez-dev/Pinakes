<?php
declare(strict_types=1);

/**
 * Behavioural tests — remaining verified audit findings (0.7.62):
 *
 *  P2-1 confirmPickup() must refuse a copy still 'prestato' (out with another
 *       open loan): accepting it created TWO active loans on one physical copy.
 *  P2-2 admin cancelReservation() must promote the waitlist in-transaction
 *       (every sibling release path already does) instead of leaving the freed
 *       capacity idle until the next maintenance run.
 *  P2-3 the chase-up senders (expiry warning + first overdue notice) must keep
 *       firing for active loans whose book was soft-deleted — archiving the
 *       book silenced the first overdue notice and, since sendLoanRecalls()
 *       requires overdue_notification_sent=1, every automatic recall after it.
 *  P2-4 sendReservationNotification() must claim notifica_inviata atomically
 *       BEFORE sending: between the caller's commit and the deferred flush the
 *       retry sweep (cron/admin login) could send the same
 *       'reservation_book_available' email, then the flush sent it again.
 *  P2-5 cancelPickup() must record an on-time staff cancellation as
 *       'annullato', while preserving 'scaduto' for a deadline already passed.
 *  P3-1 store() must cap pickup_deadline at data_scadenza like approveLoan()
 *       and activateScheduledLoans().
 *  P3-2 bulkExtend() and update()'s due-date extension must run the borrower
 *       eligibility gate (LoanEligibility::checkUser) like renew() does.
 *  P4   the "[System] Scaduta/Ritiro scaduto il ..." audit notes must be
 *       formatted from the app-TZ date the decision used (DateHelper::today()),
 *       not from the process TZ.
 *
 * Drives the REAL production paths (LoanApprovalController, PrestitiController,
 * ReservationManager, NotificationService, MaintenanceService). It asserts only
 * on rows it creates (titles ZZ_AFIX_%, emails @afix.test.local) and cleans them
 * up, but the P2-3/P4 checks invoke GLOBAL maintenance senders/sweeps
 * (sendOverdueLoanNotifications/sendLoanExpirationWarnings and the expiry
 * culls), which touch every matching row — run against an isolated/dedicated
 * test DB (as CI does), never a shared one.
 *
 * Run:  php tests/audit-fixes-p2-p4.unit.php
 */

use App\Controllers\LoanApprovalController;
use App\Controllers\PrestitiController;
use App\Controllers\ReservationManager;
use App\Support\DateHelper;
use App\Support\MaintenanceService;
use App\Support\NotificationService;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$dbHost = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$dbPort = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');

try {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $db->set_charset('utf8mb4');
    // Production writers bind the application-local date on every
    // connection (container/cron/scripts bootstrap); the circulation
    // triggers otherwise fall back to the database's UTC CURRENT_DATE(),
    // which disagrees with app.timezone between 22:00 and 24:00 UTC.
    \App\Support\DateHelper::synchronizeDatabaseSession($db);
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$TESTNO = 0;
$failed = 0;
function check(bool $cond, string $desc): void
{
    global $TESTNO, $failed;
    $TESTNO++;
    printf("[%02d] %s: %s\n", $TESTNO, $cond ? 'PASS' : 'FAIL', $desc);
    if (!$cond) {
        $failed++;
    }
}

// Per-run token: cleanup and assertions only ever touch this run's rows.
$RUN = bin2hex(random_bytes(6));
$TITLE_PREFIX = "ZZ_AFIX_{$RUN}_";
$EMAIL_DOMAIN = '@afix.test.local';

$today = DateHelper::today();
$d = static fn (int $offsetDays): string => date('Y-m-d', strtotime($today . ($offsetDays >= 0 ? " +{$offsetDays} days" : ' ' . $offsetDays . ' days')));
$withDatabaseDate = static function (string $date, callable $callback) use ($db): mixed {
    $safeDate = $db->real_escape_string($date);
    $db->query("SET timestamp = UNIX_TIMESTAMP('{$safeDate} 12:00:00')");
    // The circulation triggers read the connection-bound application date
    // before falling back to CURRENT_DATE(), so warping the clock must warp
    // the bound date too — and restore the real application day afterwards.
    $db->query("SET @pinakes_application_date = '{$safeDate}'");
    try {
        return $callback();
    } finally {
        $db->query('SET timestamp = 0');
        \App\Support\DateHelper::synchronizeDatabaseSession($db);
    }
};

$settingsRow = static function (string $key, string $default) use ($db): string {
    $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE category = 'loans' AND setting_key = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row !== null ? (string) $row['setting_value'] : $default;
};
$pickupDays = max(0, (int) $settingsRow('pickup_expiry_days', '3'));

/* -------------------------------- helpers -------------------------------- */

$userSeq = 0;
$mkUser = static function (string $stato = 'attivo') use ($db, $RUN, $EMAIL_DOMAIN, &$userSeq): int {
    $userSeq++;
    $tessera = 'ZAFIX' . strtoupper(substr($RUN, 0, 7)) . $userSeq;
    $email = "u{$userSeq}-{$RUN}{$EMAIL_DOMAIN}";
    $cognome = "ZZAFIX {$userSeq}";
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata)
                          VALUES (?, 'Test', ?, ?, 'x', ?, 'standard', 1)");
    $stmt->bind_param('ssss', $tessera, $cognome, $email, $stato);
    $stmt->execute();
    $stmt->close();
    return (int) $db->insert_id;
};

$bookSeq = 0;
$mkBook = static function (int $copies) use ($db, $TITLE_PREFIX, &$bookSeq): array {
    $bookSeq++;
    $title = $TITLE_PREFIX . $bookSeq;
    $stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at)
                          VALUES (?, ?, ?, NOW(), NOW())");
    $stmt->bind_param('sii', $title, $copies, $copies);
    $stmt->execute();
    $stmt->close();
    $bookId = (int) $db->insert_id;
    $copyIds = [];
    for ($i = 1; $i <= $copies; $i++) {
        $code = "ZZAFIX-{$bookId}-C{$i}";
        $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
        $stmt->bind_param('is', $bookId, $code);
        $stmt->execute();
        $stmt->close();
        $copyIds[] = (int) $db->insert_id;
    }
    return [$bookId, $copyIds];
};

$mkLoan = static function (int $bookId, ?int $copiaId, int $userId, string $stato, string $from, string $to, ?string $pickupDeadline = null) use ($db): int {
    $stmt = $db->prepare("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, pickup_deadline, warning_sent, overdue_notification_sent)
                          VALUES (?, ?, ?, ?, ?, ?, 'diretto', 1, ?, 0, 0)");
    $stmt->bind_param('iiissss', $bookId, $copiaId, $userId, $from, $to, $stato, $pickupDeadline);
    $stmt->execute();
    $stmt->close();
    return (int) $db->insert_id;
};

$setCopyState = static function (int $copiaId, string $stato) use ($db): void {
    $stmt = $db->prepare('UPDATE copie SET stato = ? WHERE id = ?');
    $stmt->bind_param('si', $stato, $copiaId);
    $stmt->execute();
    $stmt->close();
};

$loanRow = static function (int $loanId) use ($db): array {
    $stmt = $db->prepare('SELECT stato, attivo, data_prestito, data_scadenza, pickup_deadline, warning_sent, overdue_notification_sent, note FROM prestiti WHERE id = ?');
    $stmt->bind_param('i', $loanId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
};

$reservationRow = static function (int $resId) use ($db): array {
    $stmt = $db->prepare('SELECT stato, notifica_inviata, queue_position FROM prenotazioni WHERE id = ?');
    $stmt->bind_param('i', $resId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
};

$mkQueueReservation = static function (int $bookId, int $userId, string $from, string $to, int $queuePos, int $notified = 0, string $stato = 'attiva') use ($db): int {
    $stmt = $db->prepare("INSERT INTO prenotazioni (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta, data_scadenza_prenotazione, stato, queue_position, notifica_inviata)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('iisssssi', $bookId, $userId, $from, $to, $to, $stato, $queuePos, $notified);
    $stmt->execute();
    $stmt->close();
    return (int) $db->insert_id;
};

$jsonBody = static function ($response): array {
    return (array) json_decode((string) $response->getBody(), true);
};

// Whether email delivery actually works in this environment (locally the
// 'mail' driver hands off to sendmail and returns true; on CI it may not).
// Email-outcome-dependent assertions are gated on this probe.
$mailWorks = false;
try {
    $probeEmail = new \App\Support\EmailService($db);
    $mailWorks = $probeEmail->sendTemplate("probe-{$RUN}{$EMAIL_DOMAIN}", 'reservation_book_available', [
        'utente_nome' => 'Probe', 'libro_titolo' => 'Probe', 'libro_autore' => 'Probe',
        'libro_isbn' => 'N/A', 'data_inizio' => '01-01-2026', 'data_fine' => '02-01-2026',
        'book_url' => 'http://localhost/', 'profile_url' => 'http://localhost/',
    ], 'it_IT');
} catch (\Throwable $e) {
    $mailWorks = false;
}
echo 'mail delivery in this environment: ' . ($mailWorks ? 'WORKING' : 'unavailable (email-dependent assertions relaxed)') . "\n";

/* -------------------------------- cleanup -------------------------------- */
$cleanup = static function () use ($db, $TITLE_PREFIX, $RUN, $EMAIL_DOMAIN): void {
    $like = $db->real_escape_string($TITLE_PREFIX) . '%';
    $db->query("DELETE n FROM admin_notifications n JOIN prestiti p ON n.related_id = p.id JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE '{$like}'");
    $db->query("DELETE r FROM prenotazioni r JOIN libri l ON l.id = r.libro_id WHERE l.titolo LIKE '{$like}'");
    $db->query("DELETE p FROM prestiti p JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE '{$like}'");
    $db->query("DELETE c FROM copie c JOIN libri l ON l.id = c.libro_id WHERE l.titolo LIKE '{$like}'");
    $db->query("DELETE FROM libri WHERE titolo LIKE '{$like}'");
    $emailLike = $db->real_escape_string("u%-{$RUN}{$EMAIL_DOMAIN}");
    $db->query("DELETE FROM utenti WHERE email LIKE '{$emailLike}'");
};
$cleanup();

$processTz = date_default_timezone_get();

try {
    /* =============== P2-1: confirmPickup refuses a copy still out ========= */
    $borrower1 = $mkUser();
    $picker1 = $mkUser();
    [$bookP1, [$copyP1]] = $mkBook(1);
    // Build the #366-adjacent state in its real chronological order. At the
    // predecessor's due date the two windows are disjoint and both inserts are
    // valid; after the clock returns to today, the still-unreturned predecessor
    // has become an open-ended hold beside its already-announced successor.
    [$prevLoan, $nextLoan] = $withDatabaseDate(
        $d(-5),
        static function () use ($mkLoan, $bookP1, $copyP1, $borrower1, $picker1, $d): array {
            $previous = $mkLoan($bookP1, $copyP1, $borrower1, 'in_corso', $d(-30), $d(-5));
            $next = $mkLoan($bookP1, $copyP1, $picker1, 'da_ritirare', $d(-1), $d(10), $d(2));
            return [$previous, $next];
        }
    );
    $setCopyState($copyP1, 'prestato');

    $_SESSION['user'] = ['tipo_utente' => 'admin', 'id' => $picker1];
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/admin/loans/confirm-pickup')
        ->withParsedBody(['loan_id' => $nextLoan]);
    $resp = (new LoanApprovalController())->confirmPickup($request, (new ResponseFactory())->createResponse(), $db);
    $body = $jsonBody($resp);

    check(($body['success'] ?? null) === false, 'P2-1: confirmPickup REFUSES pickup while the assigned copy is still prestato');
    check(($loanRow($nextLoan)['stato'] ?? '') === 'da_ritirare', 'P2-1: the successor loan stays da_ritirare (rolled back)');
    $copyState = (string) $db->query("SELECT stato FROM copie WHERE id = {$copyP1}")->fetch_row()[0];
    check($copyState === 'prestato', 'P2-1: the copy stays prestato (still held by the open predecessor)');
    $activeOnCopy = (int) $db->query("SELECT COUNT(*) FROM prestiti WHERE copia_id = {$copyP1} AND attivo = 1 AND stato IN ('in_corso','in_ritardo')")->fetch_row()[0];
    check($activeOnCopy === 1, 'P2-1: exactly ONE running loan on the physical copy (no double-issue)');

    /* =============== P2-2: admin cancelReservation promotes the queue ===== */
    $resUser1 = $mkUser();
    $resUser2 = $mkUser();
    [$bookQ, [$copyQ]] = $mkBook(1);
    // Two queued reservations over the same window on a 1-copy book: neither
    // can promote while the other occupies the capacity unit.
    $resQ1 = $mkQueueReservation($bookQ, $resUser1, $d(0), $d(10), 1);
    $resQ2 = $mkQueueReservation($bookQ, $resUser2, $d(0), $d(10), 2);

    $_SESSION['user'] = ['tipo_utente' => 'admin', 'id' => $resUser1];
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/admin/reservations/cancel')
        ->withParsedBody(['reservation_id' => $resQ1, 'reason' => 'test P2-2']);
    $resp = (new LoanApprovalController())->cancelReservation($request, (new ResponseFactory())->createResponse(), $db);
    $body = $jsonBody($resp);

    check(($body['success'] ?? null) === true, 'P2-2: admin cancelReservation succeeds');
    check(($reservationRow($resQ1)['stato'] ?? '') === 'annullata', 'P2-2: the cancelled reservation is annullata');
    check(($reservationRow($resQ2)['stato'] ?? '') === 'completata', 'P2-2: the next queued reservation is promoted IN the same request (not left for the cron)');
    // The promotion always creates the conversion loan as 'pendente' (attivo=0):
    // it awaits the admin's confirmation of the physical pickup.
    $promotedLoans = (int) $db->query("SELECT COUNT(*) FROM prestiti WHERE libro_id = {$bookQ} AND utente_id = {$resUser2} AND stato = 'pendente'")->fetch_row()[0];
    check($promotedLoans === 1, 'P2-2: the promotion created the successor conversion loan (pendente)');

    /* =============== P2-5: cancelPickup keeps cancel/expiry distinct ===== */
    $onTimeUser = $mkUser();
    [$bookOnTime, [$copyOnTime]] = $mkBook(1);
    $onTimePickup = $mkLoan($bookOnTime, $copyOnTime, $onTimeUser, 'da_ritirare', $d(-2), $d(10), $d(0));
    $setCopyState($copyOnTime, 'prenotato');

    $_SESSION['user'] = ['tipo_utente' => 'admin', 'id' => $resUser1];
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/admin/loans/cancel-pickup')
        ->withParsedBody(['loan_id' => $onTimePickup]);
    $resp = (new LoanApprovalController())->cancelPickup($request, (new ResponseFactory())->createResponse(), $db);
    $body = $jsonBody($resp);
    $row = $loanRow($onTimePickup);

    check(($body['success'] ?? null) === true, 'P2-5: staff can cancel a pickup on its deadline');
    check(
        ($row['stato'] ?? '') === 'annullato'
            && (int) ($row['attivo'] ?? 1) === 0
            && ($row['pickup_deadline'] ?? null) === null,
        "P2-5: an on-time cancellation is 'annullato' and clears the deadline"
    );
    check(
        str_contains((string) ($row['note'] ?? ''), __('Ritiro annullato il')),
        'P2-5: on-time cancellation history uses cancellation wording'
    );
    $copyState = (string) $db->query("SELECT stato FROM copie WHERE id = {$copyOnTime}")->fetch_row()[0];
    check($copyState === 'disponibile', 'P2-5: an on-time cancellation releases its physical copy');

    $expiredPickupUser = $mkUser();
    [$bookExpiredPickup, [$copyExpiredPickup]] = $mkBook(1);
    $expiredPickup = $mkLoan($bookExpiredPickup, $copyExpiredPickup, $expiredPickupUser, 'da_ritirare', $d(-3), $d(10), $d(-1));
    $setCopyState($copyExpiredPickup, 'prenotato');

    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/admin/loans/cancel-pickup')
        ->withParsedBody(['loan_id' => $expiredPickup]);
    $resp = (new LoanApprovalController())->cancelPickup($request, (new ResponseFactory())->createResponse(), $db);
    $body = $jsonBody($resp);
    $row = $loanRow($expiredPickup);

    check(($body['success'] ?? null) === true, 'P2-5: staff can close an already-expired pickup');
    check(
        ($row['stato'] ?? '') === 'scaduto'
            && (int) ($row['attivo'] ?? 1) === 0
            && ($row['pickup_deadline'] ?? null) === null,
        "P2-5: a past-deadline pickup remains 'scaduto' and clears the deadline"
    );
    check(
        ($body['message'] ?? '') === __('Ritiro scaduto')
            && str_contains((string) ($row['note'] ?? ''), __('Ritiro scaduto il'))
            && !str_contains((string) ($row['note'] ?? ''), __('Ritiro annullato il')),
        'P2-5: expired response and history use expiry wording, never cancellation wording'
    );
    $copyState = (string) $db->query("SELECT stato FROM copie WHERE id = {$copyExpiredPickup}")->fetch_row()[0];
    check($copyState === 'disponibile', 'P2-5: closing an expired pickup releases its physical copy');

    /* =============== P2-3: chase-up senders on an archived book =========== */
    $overdueUser = $mkUser();
    [$bookArch, [$copyArch]] = $mkBook(1);
    $archLoan = $mkLoan($bookArch, $copyArch, $overdueUser, 'in_corso', $d(-20), $d(-3));
    $setCopyState($copyArch, 'prestato');
    $warnUser = $mkUser();
    [$bookArch2, [$copyArch2]] = $mkBook(1);
    $warnLoan = $mkLoan($bookArch2, $copyArch2, $warnUser, 'in_corso', $d(-10), $d(0));
    $setCopyState($copyArch2, 'prestato');
    // Archive both books while their loans are still out.
    $db->query("UPDATE libri SET deleted_at = NOW() WHERE id IN ({$bookArch}, {$bookArch2})");

    $notifications = new NotificationService($db);
    $notifications->sendOverdueLoanNotifications();
    $row = $loanRow($archLoan);
    // The claim flips stato regardless of the email outcome: pre-fix the loan
    // was never selected at all and stayed 'in_corso'.
    check(($row['stato'] ?? '') === 'in_ritardo', 'P2-3: the overdue notice still processes the loan of an ARCHIVED book (stato flipped by the claim)');
    if ($mailWorks) {
        check((int) ($row['overdue_notification_sent'] ?? 0) === 1, 'P2-3: overdue_notification_sent=1 — sendLoanRecalls() is unblocked for the archived book');
    }

    $notifications->sendLoanExpirationWarnings();
    if ($mailWorks) {
        check((int) ($loanRow($warnLoan)['warning_sent'] ?? 0) === 1, 'P2-3: the expiry warning still fires for the loan of an ARCHIVED book');
    }

    // Environment-independent guard: neither chase-up sender may filter on
    // libri.deleted_at (same source-assertion style as the runAll() order check
    // in issue-366-full-scenario).
    $notifSrc = (string) file_get_contents($root . '/app/Support/NotificationService.php');
    $extractMethod = static function (string $src, string $needle): string {
        $start = strpos($src, $needle);
        if ($start === false) {
            return '';
        }
        $end = strpos($src, "\n    public function ", $start + strlen($needle));
        return substr($src, $start, ($end === false ? strlen($src) : $end) - $start);
    };
    check(!str_contains($extractMethod($notifSrc, 'public function sendLoanExpirationWarnings'), 'deleted_at'),
        'P2-3: sendLoanExpirationWarnings() has no deleted_at filter (source guard)');
    check(!str_contains($extractMethod($notifSrc, 'public function sendOverdueLoanNotifications'), 'deleted_at'),
        'P2-3: sendOverdueLoanNotifications() has no deleted_at filter (source guard)');

    /* =============== P2-4: claim-before-send on the reservation email ===== */
    $notifUser = $mkUser();
    [$bookN, [$copyN]] = $mkBook(1);
    $sendMethod = new \ReflectionMethod(ReservationManager::class, 'sendReservationNotification');
    $sendMethod->setAccessible(true);
    $manager = new ReservationManager($db);
    $userRow = $db->query("SELECT email, nome, cognome FROM utenti WHERE id = {$notifUser}")->fetch_assoc();
    $mkPayload = static fn (int $resId, int $bookId) => [
        'id' => $resId, 'libro_id' => $bookId,
        'email' => $userRow['email'], 'nome' => $userRow['nome'], 'cognome' => $userRow['cognome'],
        'data_inizio_richiesta' => $d(0), 'data_fine_richiesta' => $d(10),
    ];

    // Race replay: the sweep has ALREADY claimed this row (notifica_inviata=1)
    // between the caller's commit and the deferred flush. The flush's send must
    // now be a no-op — pre-fix it sent the email again (returned true).
    $resClaimed = $mkQueueReservation($bookN, $notifUser, $d(0), $d(10), 1, 1, 'completata');
    $sent = (bool) $sendMethod->invoke($manager, $mkPayload($resClaimed, $bookN));
    check($sent === false, 'P2-4: a reservation already claimed by the sweep is NOT sent again by the deferred flush');
    check((int) ($reservationRow($resClaimed)['notifica_inviata'] ?? 0) === 1, 'P2-4: the concurrent claim is left untouched');

    if ($mailWorks) {
        // Happy path: unclaimed row is claimed + sent exactly once.
        $resFresh = $mkQueueReservation($bookN, $notifUser, $d(0), $d(10), 2, 0, 'completata');
        $sent = (bool) $sendMethod->invoke($manager, $mkPayload($resFresh, $bookN));
        check($sent === true, 'P2-4: an unclaimed reservation is claimed and sent');
        check((int) ($reservationRow($resFresh)['notifica_inviata'] ?? 0) === 1, 'P2-4: successful send keeps the claim (notifica_inviata=1)');
    }

    // Failure path: the send aborts (archived book) AFTER the claim — the claim
    // must be released so the retry sweep still sees the row.
    [$bookN2] = $mkBook(1);
    $resFail = $mkQueueReservation($bookN2, $notifUser, $d(0), $d(10), 1, 0, 'completata');
    $db->query("UPDATE libri SET deleted_at = NOW() WHERE id = {$bookN2}");
    $sent = (bool) $sendMethod->invoke($manager, $mkPayload($resFail, $bookN2));
    check($sent === false && (int) ($reservationRow($resFail)['notifica_inviata'] ?? 1) === 0,
        'P2-4: a failed send releases the claim (row stays eligible for the retry sweep)');

    /* =============== P3-1: store() caps pickup_deadline =================== */
    $storeAdmin = $mkUser();
    $storeUser = $mkUser();
    [$bookS] = $mkBook(1);
    $_SESSION['user'] = ['tipo_utente' => 'admin', 'id' => $storeAdmin];
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/admin/loans/store')
        ->withParsedBody([
            'loan_submission_token' => \App\Support\OneTimeFormToken::issue('loan.create'),
            'utente_id' => (string) $storeUser,
            'libro_id' => (string) $bookS,
            'data_prestito' => $d(0),
            'data_scadenza' => $d(1),
        ]);
    $resp = (new PrestitiController())->store($request, (new ResponseFactory())->createResponse(), $db);
    check(str_contains($resp->getHeaderLine('Location'), 'created=1'), 'P3-1: direct admin loan (due tomorrow, no immediate delivery) is created');
    $storeLoanRow = $db->query("SELECT id, stato, pickup_deadline, data_scadenza FROM prestiti WHERE libro_id = {$bookS} AND utente_id = {$storeUser}")->fetch_assoc() ?: [];
    check(($storeLoanRow['stato'] ?? '') === 'da_ritirare', 'P3-1: the loan is da_ritirare with a pickup deadline');
    $expectedDeadline = min($d($pickupDays), $d(1));
    check(($storeLoanRow['pickup_deadline'] ?? '') === $expectedDeadline && ($storeLoanRow['pickup_deadline'] ?? 'z') <= ($storeLoanRow['data_scadenza'] ?? ''),
        "P3-1: pickup_deadline capped at data_scadenza ({$expectedDeadline}) like approveLoan/activateScheduledLoans");
    if ($pickupDays <= 1) {
        echo "      (note: loans.pickup_expiry_days={$pickupDays} makes the cap non-discriminating in this environment)\n";
    }

    /* =============== P3-2: eligibility on bulkExtend and update() ========= */
    $goodUser = $mkUser();
    $suspendedUser = $mkUser('sospeso');
    [$bookG, [$copyG]] = $mkBook(1);
    [$bookX, [$copyX]] = $mkBook(1);
    $goodLoan = $mkLoan($bookG, $copyG, $goodUser, 'in_corso', $d(-5), $d(5));
    $setCopyState($copyG, 'prestato');
    $suspLoan = $mkLoan($bookX, $copyX, $suspendedUser, 'in_corso', $d(-5), $d(5));
    $setCopyState($copyX, 'prestato');

    $_SESSION['user'] = ['tipo_utente' => 'admin', 'id' => $goodUser];
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/admin/loans/bulk-extend')
        ->withParsedBody(['ids' => [(string) $goodLoan, (string) $suspLoan], 'days' => '5']);
    $resp = (new PrestitiController())->bulkExtend($request, (new ResponseFactory())->createResponse(), $db);
    $location = $resp->getHeaderLine('Location');

    check(str_contains($location, 'bulk_extended=1'), 'P3-2: bulkExtend extends only the eligible borrower\'s loan (1 of 2)');
    check(($loanRow($goodLoan)['data_scadenza'] ?? '') === $d(10), 'P3-2: eligible loan extended (+5 from its due date)');
    check(($loanRow($suspLoan)['data_scadenza'] ?? '') === $d(5), 'P3-2: SUSPENDED borrower\'s loan NOT extended by bulkExtend');

    // update(): extending the due date for a suspended borrower must be refused
    // (same benefit gate as renew()); shrinking it must still be allowed.
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/admin/loans/edit/' . $suspLoan)
        ->withParsedBody(['utente_id' => (string) $suspendedUser, 'data_prestito' => $d(-5), 'data_scadenza' => $d(12)]);
    $resp = (new PrestitiController())->update($request, (new ResponseFactory())->createResponse(), $db, $suspLoan);
    check(str_contains($resp->getHeaderLine('Location'), 'error=user_suspended'), 'P3-2: update() refuses a due-date EXTENSION for a suspended borrower');
    check(($loanRow($suspLoan)['data_scadenza'] ?? '') === $d(5), 'P3-2: refused extension leaves the due date unchanged');

    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/admin/loans/edit/' . $suspLoan)
        ->withParsedBody(['utente_id' => (string) $suspendedUser, 'data_prestito' => $d(-5), 'data_scadenza' => $d(3)]);
    $resp = (new PrestitiController())->update($request, (new ResponseFactory())->createResponse(), $db, $suspLoan);
    check(!str_contains($resp->getHeaderLine('Location'), 'error='), 'P3-2: shrinking the due date for a suspended borrower is still allowed (no benefit conferred)');
    check(($loanRow($suspLoan)['data_scadenza'] ?? '') === $d(3), 'P3-2: shrunk due date applied');

    /* =============== P4: audit note date in the app timezone ============== */
    // Force a process TZ whose current DATE differs from the app-TZ date; the
    // two candidates span 26 hours, so at any instant at least one differs.
    $driftTz = null;
    foreach (['Pacific/Kiritimati', 'Etc/GMT+12'] as $tzName) {
        if ((new \DateTime('now', new \DateTimeZone($tzName)))->format('Y-m-d') !== $today) {
            $driftTz = $tzName;
            break;
        }
    }
    check($driftTz !== null, 'P4: found a process TZ whose date differs from the app-TZ date');

    $expiredUser = $mkUser();
    [$bookE1, [$copyE1]] = $mkBook(1);
    $resExpired = $mkLoan($bookE1, $copyE1, $expiredUser, 'prenotato', $d(-10), $d(-2));
    $setCopyState($copyE1, 'prenotato');
    [$bookE2, [$copyE2]] = $mkBook(1);
    $pickupExpired = $mkLoan($bookE2, $copyE2, $expiredUser, 'da_ritirare', $d(-6), $d(10), $d(-1));
    $setCopyState($copyE2, 'prenotato');

    $appNoteDate = implode('/', array_reverse(explode('-', $today)));
    if ($driftTz !== null) {
        date_default_timezone_set($driftTz);
        try {
            $procNoteDate = date('d/m/Y');
            $svc = new MaintenanceService($db);
            $svc->checkExpiredReservations();
            $svc->checkExpiredPickups();
        } finally {
            date_default_timezone_set($processTz);
        }

        $row = $loanRow($resExpired);
        check(($row['stato'] ?? '') === 'scaduto' && str_contains((string) $row['note'], $appNoteDate),
            "P4: 'Scaduta il' audit note carries the app-TZ date ({$appNoteDate})");
        check(!str_contains((string) $row['note'], $procNoteDate),
            "P4: 'Scaduta il' audit note does NOT carry the process-TZ date ({$procNoteDate})");

        $row = $loanRow($pickupExpired);
        check(($row['stato'] ?? '') === 'scaduto' && str_contains((string) $row['note'], $appNoteDate),
            "P4: 'Ritiro scaduto il' audit note carries the app-TZ date ({$appNoteDate})");
        check(!str_contains((string) $row['note'], $procNoteDate),
            "P4: 'Ritiro scaduto il' audit note does NOT carry the process-TZ date ({$procNoteDate})");
    }

} catch (\Throwable $e) {
    date_default_timezone_set($processTz);
    $cleanup();
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $db->close();
    exit(1);
}

$cleanup();
$db->close();
echo "\n" . ($failed === 0 ? "ALL {$TESTNO} PASS\n" : "{$failed}/{$TESTNO} FAILED\n");
exit($failed > 0 ? 1 : 0);

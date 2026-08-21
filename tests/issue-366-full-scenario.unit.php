<?php
declare(strict_types=1);

/**
 * Behavioural test — #366 FULL multi-step scenario ("Book switches to 'Ready
 * for Pickup' while still being on an overdue loan") plus the residual gaps the
 * complete sequence exposes beyond the single-step copy-free promotion guard:
 *
 *   1. A book is reserved right after its current loan.
 *   2. The patron lets the loan go overdue.
 *   3. Staff change the reservation AND prolong the loan.
 *   4. The patron overdues again.
 *   5. Staff do NOT prolong this time.
 *   6. The book must NOT switch to 'da_ritirare' (and no pickup email — the
 *      pickup-ready mail only fires after a successful promotion commit, so
 *      no promotion == no email) while the loan is still out.
 *
 * Residual gaps locked here:
 *   A. runAll() ordering: updateOverdueLoans() must run FIRST, so gates that
 *      special-case 'in_ritardo' in the same pass see the truthful state.
 *   B. PrestitiController::update() reschedule of an open 'prenotato'/
 *      'da_ritirare' loan must recompute stato/pickup_deadline — otherwise
 *      checkExpiredPickups() culls a just-rescheduled valid loan against its
 *      STALE deadline (wrong "pickup expired" email + lost loan), and a
 *      shrunk data_scadenza below pickup_deadline leaves an unexpirable hold.
 *   C. renew() must refuse a loan overdue BY DATE (not just by state), and its
 *      capacity window must start the day AFTER the current due date (#336).
 *   D. CapacityService must treat a date-overdue 'in_corso' loan like
 *      'in_ritardo' (unreturned copy = open-ended occupancy).
 *   E. Follow-up comment #5357382538: an already-corrupt `da_ritirare`
 *      survivor must be demoted automatically and remain editable even after
 *      the predecessor becomes `in_ritardo` (no generic loan_update_failed).
 *
 * Drives the REAL production paths: MaintenanceService::updateOverdueLoans/
 * checkExpiredReservations/checkExpiredPickups/activateScheduledLoans,
 * PrestitiController::update()/renew(), CapacityService::hasFreeCapacity().
 * It asserts only on rows it creates (titles ZZ_366FS_%, emails
 * @366fs.test.local) and cleans them up, but the maintenance sweeps above are
 * GLOBAL (no per-book filter) and mutate every matching row — run against an
 * isolated/dedicated test DB (as CI does), never a shared one.
 *
 * Run:  php tests/issue-366-full-scenario.unit.php
 */

use App\Controllers\PrestitiController;
use App\Services\CapacityService;
use App\Support\DateHelper;
use App\Support\MaintenanceService;
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
$TITLE_PREFIX = "ZZ_366FS_{$RUN}_";
$EMAIL_DOMAIN = '@366fs.test.local';

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
$loanDays = (int) $settingsRow('loan_duration_days', '30');
if ($loanDays < 1) {
    $loanDays = 30;
}

/* -------------------------------- helpers -------------------------------- */

$userSeq = 0;
$mkUser = static function () use ($db, $RUN, $EMAIL_DOMAIN, &$userSeq): int {
    $userSeq++;
    $tessera = 'Z366F' . strtoupper(substr($RUN, 0, 8)) . $userSeq;
    $email = "u{$userSeq}-{$RUN}{$EMAIL_DOMAIN}";
    $cognome = "ZZ366FS {$userSeq}";
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata)
                          VALUES (?, 'Test', ?, ?, 'x', 'attivo', 'standard', 1)");
    $stmt->bind_param('sss', $tessera, $cognome, $email);
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
        $code = "ZZ366FS-{$bookId}-C{$i}";
        $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
        $stmt->bind_param('is', $bookId, $code);
        $stmt->execute();
        $stmt->close();
        $copyIds[] = (int) $db->insert_id;
    }
    return [$bookId, $copyIds];
};

$mkLoan = static function (int $bookId, ?int $copiaId, int $userId, string $stato, string $from, string $to, ?string $pickupDeadline = null, int $warned = 0) use ($db): int {
    $stmt = $db->prepare("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, pickup_deadline, warning_sent, overdue_notification_sent)
                          VALUES (?, ?, ?, ?, ?, ?, 'diretto', 1, ?, ?, ?)");
    $stmt->bind_param('iiissssii', $bookId, $copiaId, $userId, $from, $to, $stato, $pickupDeadline, $warned, $warned);
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

$copyState = static function (int $copiaId) use ($db): string {
    $stmt = $db->prepare('SELECT stato FROM copie WHERE id = ?');
    $stmt->bind_param('i', $copiaId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (string) ($row['stato'] ?? '');
};

$loanRow = static function (int $loanId) use ($db): array {
    $stmt = $db->prepare('SELECT stato, attivo, copia_id, data_prestito, data_scadenza, pickup_deadline, warning_sent, overdue_notification_sent, renewals FROM prestiti WHERE id = ?');
    $stmt->bind_param('i', $loanId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
};

// Fixture-only writes to rows this run created (simulating elapsed days).
$shiftLoan = static function (int $loanId, ?string $from, ?string $to) use ($db): void {
    if ($from !== null) {
        $stmt = $db->prepare('UPDATE prestiti SET data_prestito = ? WHERE id = ?');
        $stmt->bind_param('si', $from, $loanId);
        $stmt->execute();
        $stmt->close();
    }
    if ($to !== null) {
        $stmt = $db->prepare('UPDATE prestiti SET data_scadenza = ? WHERE id = ?');
        $stmt->bind_param('si', $to, $loanId);
        $stmt->execute();
        $stmt->close();
    }
};

$returnLoan = static function (int $loanId, ?int $copiaId, string $copyState = 'disponibile') use ($db, $setCopyState, $today): void {
    $stmt = $db->prepare("UPDATE prestiti SET stato = 'restituito', attivo = 0, data_restituzione = ? WHERE id = ?");
    $stmt->bind_param('si', $today, $loanId);
    $stmt->execute();
    $stmt->close();
    if ($copiaId !== null) {
        $setCopyState($copiaId, $copyState);
    }
};

// Real controller invocations (same harness as loan-bulk-extension-capacity).
$callUpdate = static function (int $loanId, int $userId, string $start, string $due) use ($db) {
    // The controller authorizes from the session; processed_by must reference a
    // real user because of the FK, so reuse the fixture borrower.
    $_SESSION['user'] = ['tipo_utente' => 'admin', 'id' => $userId];
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/admin/loans/edit/' . $loanId)
        ->withParsedBody(['utente_id' => $userId, 'data_prestito' => $start, 'data_scadenza' => $due]);
    $response = (new ResponseFactory())->createResponse();
    return (new PrestitiController())->update($request, $response, $db, $loanId);
};

$callRenew = static function (int $loanId, int $userId) use ($db) {
    $_SESSION['user'] = ['tipo_utente' => 'admin', 'id' => $userId];
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/admin/loans/renew/' . $loanId)
        ->withParsedBody([]);
    $response = (new ResponseFactory())->createResponse();
    return (new PrestitiController())->renew($request, $response, $db, $loanId);
};

$svc = new MaintenanceService($db);
// One maintenance pass, in runAll()'s (fixed) order, restricted to the loan
// lifecycle tasks — the full runAll() also fires notifications/ICS/hooks over
// the whole shared DB, which this test deliberately avoids.
$maintenancePass = static function () use ($svc): void {
    $svc->updateOverdueLoans();
    $svc->repairInvalidReadyPickups();
    $svc->checkExpiredReservations();
    $svc->checkExpiredPickups();
    $svc->activateScheduledLoans();
};

/* -------------------------------- cleanup -------------------------------- */
$cleanup = static function () use ($db, $TITLE_PREFIX, $RUN, $EMAIL_DOMAIN): void {
    $like = $db->real_escape_string($TITLE_PREFIX) . '%';
    $db->query("DELETE r FROM prenotazioni r JOIN libri l ON l.id = r.libro_id WHERE l.titolo LIKE '{$like}'");
    $db->query("DELETE p FROM prestiti p JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE '{$like}'");
    $db->query("DELETE c FROM copie c JOIN libri l ON l.id = c.libro_id WHERE l.titolo LIKE '{$like}'");
    $db->query("DELETE FROM libri WHERE titolo LIKE '{$like}'");
    $emailLike = $db->real_escape_string("u%-{$RUN}{$EMAIL_DOMAIN}");
    $db->query("DELETE FROM utenti WHERE email LIKE '{$emailLike}'");
};
$cleanup();

try {
    /* ---- 0. runAll() ordering: the overdue flip must run FIRST ----------- */
    $maintenanceSrc = (string) file_get_contents($root . '/app/Support/MaintenanceService.php');
    $runAllStart = strpos($maintenanceSrc, 'public function runAll');
    check($runAllStart !== false, 'runAll() is present in MaintenanceService (else the ordering check below is meaningless)');
    $body = $runAllStart !== false ? substr($maintenanceSrc, $runAllStart) : '';
    $posOverdue = strpos($body, '$this->updateOverdueLoans()');
    $posExpired = strpos($body, '$this->checkExpiredReservations()');
    $posActivate = strpos($body, '$this->activateScheduledLoans()');
    check(
        $posOverdue !== false && $posExpired !== false && $posActivate !== false
            && $posOverdue < $posExpired && $posOverdue < $posActivate,
        'runAll() flips overdue loans BEFORE expiries and scheduled-loan activation'
    );

    /* ---- A. The exact #366 six-step sequence (single copy) --------------- */
    $borrower = $mkUser();
    $reserver = $mkUser();
    [$bookA, [$copyA]] = $mkBook(1);

    // Step 1-2: loan out since d-30, due d-5, NOT returned (overdue, not yet
    // flipped — the state a real pass starts from); reservation right after it,
    // start already arrived (d-4 .. d+10), no copy pinned.
    $loanA = $mkLoan($bookA, $copyA, $borrower, 'in_corso', $d(-30), $d(-5));
    $setCopyState($copyA, 'prestato');
    $resA = $mkLoan($bookA, null, $reserver, 'prenotato', $d(-4), $d(10));

    // Maintenance pass #1.
    $maintenancePass();
    check(($loanRow($loanA)['stato'] ?? '') === 'in_ritardo', 'A: pass 1 flips the unreturned predecessor to in_ritardo (order fix)');
    $row = $loanRow($resA);
    check(($row['stato'] ?? '') === 'prenotato' && ($row['pickup_deadline'] ?? null) === null,
        'A: reservation NOT announced ready while the copy is out (no promotion => no pickup email)');

    // Step 3a: staff change the reservation (move it later, inside its window).
    $resp = $callUpdate($resA, $reserver, $d(6), $d(10));
    check(!str_contains($resp->getHeaderLine('Location'), 'error='), 'A: staff reschedule of the queued reservation succeeds');
    $row = $loanRow($resA);
    check(($row['stato'] ?? '') === 'prenotato' && ($row['pickup_deadline'] ?? null) === null,
        'A: rescheduled reservation stays prenotato with no pickup_deadline');

    // Step 3b: renew() refuses the overdue predecessor; staff prolong via the
    // edit form instead (the sanctioned path, which recomputes stato — #281).
    $resp = $callRenew($loanA, $borrower);
    check(str_contains($resp->getHeaderLine('Location'), 'error=loan_overdue'), 'A: renew() refuses the in_ritardo predecessor');
    $resp = $callUpdate($loanA, $borrower, $d(-30), $d(5));
    check(!str_contains($resp->getHeaderLine('Location'), 'error='), 'A: staff prolong the overdue loan via edit');
    $row = $loanRow($loanA);
    check(($row['stato'] ?? '') === 'in_corso' && ($row['data_scadenza'] ?? '') === $d(5), 'A: prolonged loan is back in_corso with the new due date');

    // Step 4: time passes — the patron overdues AGAIN (due date now past, stato
    // still in_corso: the daily flip has not run yet) and the reservation's
    // start date arrives. Fixture-only date shifts on this run's own rows.
    $shiftLoan($loanA, null, $d(-2));
    $shiftLoan($resA, $d(-1), $d(10));

    // Step 5-6: staff do NOT prolong. The next maintenance pass must NOT
    // announce the book ready for pickup, and must NOT destroy the reservation.
    $maintenancePass();
    check(($loanRow($loanA)['stato'] ?? '') === 'in_ritardo', 'A: pass 2 flips the re-overdue predecessor first');
    $row = $loanRow($resA);
    check(($row['stato'] ?? '') === 'prenotato' && ($row['pickup_deadline'] ?? null) === null,
        'A: CORE #366 — reservation still NOT ready-for-pickup while the loan is out (no pickup email)');
    check((int) ($row['attivo'] ?? 0) === 1 && ($row['stato'] ?? '') !== 'scaduto', 'A: reservation not culled by the expiry crons');

    // Step 7: the predecessor finally comes back — the reservation DOES promote.
    $returnLoan($loanA, $copyA);
    $maintenancePass();
    $row = $loanRow($resA);
    check(($row['stato'] ?? '') === 'da_ritirare', 'A: after the return the reservation promotes to da_ritirare');
    check(!empty($row['pickup_deadline']) && $row['pickup_deadline'] <= $row['data_scadenza'],
        'A: promoted reservation has a pickup_deadline capped at its own due date');
    check((int) ($row['copia_id'] ?? 0) === $copyA,
        'A: an unpinned scheduled loan is assigned a real copy before it is announced ready');

    /* ---- B. Reschedule of a da_ritirare loan vs the expiry cron ---------- */
    // B1: pickup window already blown (deadline yesterday); staff reschedule the
    // loan to start in the future BEFORE the cron culls it. The edit must demote
    // it to 'prenotato' and clear the stale deadline, so checkExpiredPickups()
    // does not destroy a just-rescheduled valid loan (wrong "pickup expired"
    // email — that mail only fires after a successful cull commit).
    $userB = $mkUser();
    [$bookB, [$copyB]] = $mkBook(1);
    $loanB = $mkLoan($bookB, $copyB, $userB, 'da_ritirare', $d(-6), $d(15), $d(-1));
    $setCopyState($copyB, 'prenotato');

    $resp = $callUpdate($loanB, $userB, $d(4), $d(15));
    check(!str_contains($resp->getHeaderLine('Location'), 'error='), 'B1: rescheduling the da_ritirare loan to a future start succeeds');
    $row = $loanRow($loanB);
    check(($row['stato'] ?? '') === 'prenotato', 'B1: future start demotes da_ritirare back to prenotato');
    check(($row['pickup_deadline'] ?? null) === null, 'B1: the stale pickup_deadline is cleared on demotion');

    $svc->checkExpiredPickups();
    $svc->checkExpiredReservations();
    $row = $loanRow($loanB);
    check(($row['stato'] ?? '') === 'prenotato' && (int) ($row['attivo'] ?? 0) === 1,
        'B1: expiry crons do NOT cull the rescheduled loan (no wrong pickup-expired email)');

    // B2: staff shrink the due date below the current pickup_deadline. The
    // deadline must be re-derived and capped at the new data_scadenza —
    // otherwise the hold outlives its own loan window and nothing ever expires it.
    $userB2 = $mkUser();
    [$bookB2, [$copyB2]] = $mkBook(1);
    $loanB2 = $mkLoan($bookB2, $copyB2, $userB2, 'da_ritirare', $d(-2), $d(10), $d(3));
    $setCopyState($copyB2, 'prenotato');

    $resp = $callUpdate($loanB2, $userB2, $d(-2), $d(1));
    check(!str_contains($resp->getHeaderLine('Location'), 'error='), 'B2: shrinking the due date of a da_ritirare loan succeeds');
    $row = $loanRow($loanB2);
    $expectedDeadline = min($d($pickupDays), $d(1));
    check(($row['stato'] ?? '') === 'da_ritirare', 'B2: started window keeps the loan da_ritirare');
    check(($row['pickup_deadline'] ?? '') === $expectedDeadline && ($row['pickup_deadline'] ?? '') <= ($row['data_scadenza'] ?? ''),
        'B2: pickup_deadline re-derived and capped at the shrunk due date (no zombie hold)');

    /* ---- C. renew(): date-based overdue gate + #336 window --------------- */
    // C1: overdue BY DATE but still 'in_corso' (flip not yet run). renew() must
    // refuse — renewing from the stale past due date and resetting the
    // notification flags would re-arm a duplicate overdue email.
    $userC = $mkUser();
    [$bookC, [$copyC]] = $mkBook(1);
    $loanC = $mkLoan($bookC, $copyC, $userC, 'in_corso', $d(-30), $d(-5), null, 1);
    $setCopyState($copyC, 'prestato');

    $resp = $callRenew($loanC, $userC);
    check(str_contains($resp->getHeaderLine('Location'), 'error=loan_overdue'), 'C1: renew() refuses a loan overdue by date (stato still in_corso)');
    $row = $loanRow($loanC);
    check(($row['data_scadenza'] ?? '') === $d(-5) && (int) ($row['renewals'] ?? -1) === 0, 'C1: refused renewal leaves the due date and renewal count unchanged');
    check((int) ($row['overdue_notification_sent'] ?? 0) === 1 && (int) ($row['warning_sent'] ?? 0) === 1,
        'C1: notification flags NOT reset (no duplicate overdue email re-armed)');

    // C2: due TODAY, another user's reservation occupies ONLY the due day. The
    // due day is already held by this loan (#336): the claimed window starts the
    // day after, so the renewal must succeed.
    $userC2 = $mkUser();
    $userC2b = $mkUser();
    [$bookC2, [$copyC2]] = $mkBook(1);
    $loanC2 = $mkLoan($bookC2, $copyC2, $userC2, 'in_corso', $d(-10), $d(0));
    $setCopyState($copyC2, 'prestato');
    $stmt = $db->prepare("INSERT INTO prenotazioni (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta, data_scadenza_prenotazione, stato, queue_position)
                          VALUES (?, ?, ?, ?, ?, 'attiva', 1)");
    $sameDay = $d(0);
    $stmt->bind_param('iisss', $bookC2, $userC2b, $sameDay, $sameDay, $sameDay);
    $stmt->execute();
    $stmt->close();

    $resp = $callRenew($loanC2, $userC2);
    check(str_contains($resp->getHeaderLine('Location'), 'renewed=1'),
        'C2: renewal succeeds when the only overlapping commitment sits on the already-held due date (#336 window)');
    $row = $loanRow($loanC2);
    check(($row['data_scadenza'] ?? '') === $d($loanDays), 'C2: renewed due date = current due + configured loan duration');

    /* ---- D. Multi-copy variants ------------------------------------------ */
    // D1: 2 copies, one out overdue-not-flipped, reservation pinned to the free
    // copy: the pass promotes it (multi-copy behaviour preserved).
    $userD = $mkUser();
    $userD2 = $mkUser();
    [$bookD, [$copyDa, $copyDb]] = $mkBook(2);
    $loanD = $mkLoan($bookD, $copyDa, $userD, 'in_corso', $d(-30), $d(-5));
    $setCopyState($copyDa, 'prestato');
    $resD = $mkLoan($bookD, $copyDb, $userD2, 'prenotato', $d(-1), $d(20));
    $setCopyState($copyDb, 'prenotato');

    $maintenancePass();
    check(($loanRow($loanD)['stato'] ?? '') === 'in_ritardo', 'D1: overdue sibling flipped first');
    check(($loanRow($resD)['stato'] ?? '') === 'da_ritirare', 'D1: reservation on the genuinely free copy IS promoted');

    // D2: 2 copies but the reservation is pinned to the copy still out. The
    // activation sweep must repair the stale pin and use the free sibling;
    // a later staff reschedule still demotes it coherently.
    $userE = $mkUser();
    $userE2 = $mkUser();
    [$bookE, [$copyEa, $copyEb]] = $mkBook(2);
    // Seed predecessor first while it is still due today, then its disjoint
    // successor. Returning the DB clock to today reproduces the conflict that
    // arose only because the physical copy was not returned on time.
    [$loanE, $resE] = $withDatabaseDate(
        $d(-5),
        static function () use ($mkLoan, $bookE, $copyEa, $userE, $userE2, $d): array {
            $previous = $mkLoan($bookE, $copyEa, $userE, 'in_corso', $d(-30), $d(-5));
            $next = $mkLoan($bookE, $copyEa, $userE2, 'prenotato', $d(-1), $d(20));
            return [$previous, $next];
        }
    );
    $setCopyState($copyEa, 'prestato');

    $svc->activateScheduledLoans();
    $row = $loanRow($resE);
    check(
        ($row['stato'] ?? '') === 'da_ritirare' && (int) ($row['copia_id'] ?? 0) === $copyEb,
        'D2: reservation pinned to the out copy moves to the free sibling and becomes ready'
    );

    $resp = $callUpdate($resE, $userE2, $d(2), $d(20));
    check(!str_contains($resp->getHeaderLine('Location'), 'error='), 'D2: rescheduling the pinned reservation succeeds');
    $row = $loanRow($resE);
    check(($row['stato'] ?? '') === 'prenotato' && ($row['pickup_deadline'] ?? null) === null, 'D2: rescheduled pinned reservation stays prenotato, no deadline');

    $returnLoan($loanE, $copyEa, 'disponibile'); // original physical copy returns normally
    $shiftLoan($resE, $d(0), null);               // time passes: its start arrives
    $svc->activateScheduledLoans();
    $row = $loanRow($resE);
    check(
        ($row['stato'] ?? '') === 'da_ritirare'
            && (int) ($row['copia_id'] ?? 0) === $copyEb
            && !empty($row['pickup_deadline']),
        'D2: rescheduled sibling-backed hold promotes when its window arrives'
    );

    /* ---- E. CapacityService: date-overdue in_corso occupies open-ended --- */
    $userF = $mkUser();
    [$bookF, [$copyF]] = $mkBook(1);
    $loanF = $mkLoan($bookF, $copyF, $userF, 'in_corso', $d(-30), $d(-5));
    $setCopyState($copyF, 'prestato');

    $capacity = new CapacityService($db);
    check(!$capacity->hasFreeCapacity($bookF, $today, $d(7)),
        'E: a date-overdue in_corso loan occupies the future window (unreturned copy, open-ended clamp)');
    $db->query("UPDATE prestiti SET stato = 'in_ritardo' WHERE id = {$loanF}");
    check(!$capacity->hasFreeCapacity($bookF, $today, $d(7)),
        'E: identical verdict once flipped to in_ritardo (no drift between the two states)');
    $returnLoan($loanF, $copyF);
    check($capacity->hasFreeCapacity($bookF, $today, $d(7)),
        'E: capacity frees once the copy is actually returned');

    /* ---- F. Follow-up #5357382538: repair + editable legacy survivor ----- */
    // Recreate the screenshot state left behind by an older release: the first
    // loan is physically still out and overdue, while its immediate successor
    // was already marked da_ritirare and its prenotazioni row is gone because
    // conversion completed. The canonical update trigger is installed only on
    // upgrade, so this inconsistent pair can legitimately predate it.
    $userG = $mkUser();
    $userG2 = $mkUser();
    [$bookG, [$copyG]] = $mkBook(1);
    [$loanG, $stalePickupG] = $withDatabaseDate(
        $d(-1),
        static function () use ($mkLoan, $bookG, $copyG, $userG, $userG2, $d): array {
            $previous = $mkLoan($bookG, $copyG, $userG, 'in_corso', $d(-30), $d(-1));
            $next = $mkLoan($bookG, $copyG, $userG2, 'da_ritirare', $d(0), $d(12), $d(3));
            return [$previous, $next];
        }
    );
    $setCopyState($copyG, 'prestato');

    $reservationCount = (int) $db->query("SELECT COUNT(*) FROM prenotazioni WHERE libro_id = {$bookG}")->fetch_row()[0];
    check($reservationCount === 0 && ($loanRow($stalePickupG)['stato'] ?? '') === 'da_ritirare',
        'F: reproduced follow-up — Ready for Pickup survives although no reservation row remains');

    $svc->updateOverdueLoans();
    check(($loanRow($loanG)['stato'] ?? '') === 'in_ritardo',
        'F: predecessor can become overdue even with its scheduled successor present');

    $repaired = $svc->repairInvalidReadyPickups();
    $row = $loanRow($stalePickupG);
    check($repaired >= 1
        && ($row['stato'] ?? '') === 'prenotato'
        && ($row['pickup_deadline'] ?? null) === null
        && ($row['copia_id'] ?? null) === null,
        'F: maintenance demotes the impossible pickup and releases its stale copy assignment');

    $resp = $callUpdate($stalePickupG, $userG2, $d(1), $d(12));
    $row = $loanRow($stalePickupG);
    check(!str_contains($resp->getHeaderLine('Location'), 'error=loan_update_failed')
        && !str_contains($resp->getHeaderLine('Location'), 'error=')
        && ($row['data_prestito'] ?? '') === $d(1),
        'F: changing the repaired loan start to tomorrow succeeds (regression for issue comment)');

    $svc->activateScheduledLoans();
    check(($loanRow($stalePickupG)['stato'] ?? '') === 'prenotato',
        'F: corrected successor stays scheduled while the overdue copy is still out');
    $returnLoan($loanG, $copyG, 'prenotato');
    $shiftLoan($stalePickupG, $d(0), null);
    $svc->activateScheduledLoans();
    $row = $loanRow($stalePickupG);
    check(($row['stato'] ?? '') === 'da_ritirare' && (int) ($row['copia_id'] ?? 0) === $copyG,
        'F: after the real return, the same scheduled loan becomes ready with an assigned copy');

    /* ---- G. Repair judges each row on its OWN copy + releases freed copies -- */
    // G1: 2-copy book. A truthful da_ritirare is pinned to a free copy while a
    // sibling corrupt copy-less da_ritirare AND an overdue loan inflate the
    // book-level count to totalCopies. A capacity-style gate would demote the
    // truthful row too — and activateScheduledLoans() would then re-promote it
    // in the same runAll() with an EXTENDED pickup_deadline and a duplicate
    // pickup-ready email. The repair must only demote the corrupt row.
    $userH = $mkUser();
    $userH2 = $mkUser();
    $userH3 = $mkUser();
    [$bookH, [$copyHa, $copyHb]] = $mkBook(2);
    $validH = $mkLoan($bookH, $copyHa, $userH, 'da_ritirare', $d(-1), $d(12), $d(2));
    $setCopyState($copyHa, 'prenotato');
    $loanH = $mkLoan($bookH, $copyHb, $userH2, 'in_ritardo', $d(-30), $d(-1));
    $setCopyState($copyHb, 'prestato');
    $corruptH = $mkLoan($bookH, null, $userH3, 'da_ritirare', $d(-1), $d(12), $d(2));

    // G2 fixture (separate book): a da_ritirare pinned to a copy stuck in
    // 'prestato' with NO loan holding it. The row must be demoted AND its
    // freed copy released back to 'disponibile' in the same repair.
    $userI = $mkUser();
    [$bookI, [$copyI]] = $mkBook(1);
    $staleI = $mkLoan($bookI, $copyI, $userI, 'da_ritirare', $d(-1), $d(12), $d(2));
    $setCopyState($copyI, 'prestato');

    $svc->repairInvalidReadyPickups();

    $row = $loanRow($validH);
    check(($row['stato'] ?? '') === 'da_ritirare'
        && (int) ($row['copia_id'] ?? 0) === $copyHa
        && ($row['pickup_deadline'] ?? '') === $d(2),
        'G1: the truthful pickup on its own free copy is NOT demoted by the sibling corruption (deadline untouched => no re-promote, no duplicate email)');
    $row = $loanRow($corruptH);
    check(($row['stato'] ?? '') === 'prenotato'
        && ($row['copia_id'] ?? null) === null
        && ($row['pickup_deadline'] ?? null) === null,
        'G1: the corrupt copy-less sibling IS demoted back to the schedulable state');

    $row = $loanRow($staleI);
    check(($row['stato'] ?? '') === 'prenotato' && ($row['copia_id'] ?? null) === null,
        'G2: the pickup pinned to a stale prestato copy is demoted');
    check($copyState($copyI) === 'disponibile',
        'G2: the freed copy is released to disponibile in the same repair (not orphaned off the shelf)');

    // The re-promotion path must not undo G1 either: the corrupt row stays
    // deferred (its book has no physically free copy) and the truthful row
    // keeps its ORIGINAL deadline through the activation sweep.
    $svc->activateScheduledLoans();
    $row = $loanRow($validH);
    check(($row['stato'] ?? '') === 'da_ritirare' && ($row['pickup_deadline'] ?? '') === $d(2),
        'G1: activation sweep leaves the truthful pickup untouched (same deadline, no second pickup-ready email)');
    check(($loanRow($corruptH)['stato'] ?? '') === 'prenotato',
        'G1: the demoted corrupt row is not re-promoted while no copy is free');

    // Invariant behind both fixes: within this run's books no copy may be left
    // in a circulation state without a loan committing it.
    $orphanedCopies = (int) $db->query("
        SELECT COUNT(*) FROM copie c
        WHERE c.libro_id IN ({$bookH}, {$bookI})
          AND c.stato IN ('prenotato', 'prestato')
          AND NOT EXISTS (
              SELECT 1 FROM prestiti p
              WHERE p.copia_id = c.id
                AND ( (p.attivo = 1 AND p.stato IN ('in_corso','in_ritardo','da_ritirare','prenotato'))
                      OR (p.attivo = 0 AND p.stato = 'pendente' AND p.copia_id IS NOT NULL) )
          )
    ")->fetch_row()[0];
    check($orphanedCopies === 0, 'G: no copy of this run is left prenotato/prestato without a committing loan');

} catch (\Throwable $e) {
    $cleanup();
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $db->close();
    exit(1);
}

$cleanup();
$db->close();
echo "\n" . ($failed === 0 ? "ALL {$TESTNO} PASS\n" : "{$failed}/{$TESTNO} FAILED\n");
exit($failed > 0 ? 1 : 0);

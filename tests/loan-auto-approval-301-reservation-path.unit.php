<?php
declare(strict_types=1);

/**
 * Behavioural contract for the SECOND half of #301: the book-detail modal
 * posts to ReservationsController::createReservation, which used to create the
 * pending request WITHOUT ever consulting `auto_approve_requests` — so real
 * users' requests always landed in the admin approval queue even with the
 * option enabled (reported twice on the issue).
 *
 * Drives the REAL createReservation() end-to-end (session + JSON request)
 * against seeded rows, asserting the observable transitions:
 *   - setting OFF  → request stays 'pendente' (manual queue preserved);
 *   - setting ON   → response auto_approved=true and the loan reaches
 *                    'da_ritirare' with a copy assigned, via the same
 *                    canonical approval pipeline as the admin button;
 *   - setting ON with no lendable copy → graceful: request left pending.
 *   - a single copy physically out today → a real waitlist reservation is
 *                    created for a date-disjoint future window (#384);
 *   - multi-copy title with one free sibling → normal scheduling still works.
 *   - an in-library `prenotato` copy with a disjoint future commitment remains
 *                    assignable for an earlier loan;
 *   - scheduled, awaiting-pickup and copy-bound pending commitments preceding
 *                    the requested window → real waitlist reservation;
 *   - multi-copy allocation prefers the sibling that needs no prior return.
 *   - an earlier active FIFO reservation consumes the only independent slot;
 *   - a copy-bound pending claim closes the post-commit auto-approval race.
 *
 * Run: php tests/loan-auto-approval-301-reservation-path.unit.php
 */

use App\Controllers\ReservationsController;
use App\Controllers\LoanApprovalController;
use App\Models\CopyRepository;
use App\Models\SettingsRepository;
use App\Support\DateHelper;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
    // Production writers bind the application-local date on every
    // connection (container/cron/scripts bootstrap); the circulation
    // triggers otherwise fall back to the database's UTC CURRENT_DATE(),
    // which disagrees with app.timezone between 22:00 and 24:00 UTC.
    \App\Support\DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

// Keep email out of the way (approveLoan sends the patron notification).
// Preserve the originals so later tests in the same runner keep their email
// configuration — a blind UPDATE here would otherwise leak 'mail' onto every
// subsequent test.
$origEmail = [];
if ($r = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE category='email' AND setting_key IN ('driver_mode','type')")) {
    while ($row = $r->fetch_assoc()) {
        $origEmail[$row['setting_key']] = $row['setting_value'];
    }
}
$db->query("UPDATE system_settings SET setting_value='mail' WHERE category='email' AND setting_key IN ('driver_mode','type')");

$run = substr(hash('sha256', uniqid((string) getmypid(), true)), 0, 10);
$titlePrefix = 'ZZRES301_' . $run;
$emailDomain = '@res301.test.local';

// Preserve the global auto-approve setting so the run doesn't leave it toggled.
$origAuto = null;
if ($r = $db->query("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='auto_approve_requests'")) {
    if ($row = $r->fetch_assoc()) {
        $origAuto = $row['setting_value'];
    }
}

// Restoring the settings the setup mutated MUST be independent of the test-data
// DELETEs: a failed DELETE must never leave email.driver_mode / email.type /
// loans.auto_approve_requests changed for the tests that run after this one. Each
// restore is best-effort (its own try) so one failure can't skip the others.
$restoreSettings = static function () use ($db, $origAuto, $origEmail): void {
    try {
        if ($origAuto === null) {
            $db->query("DELETE FROM system_settings WHERE category='loans' AND setting_key='auto_approve_requests'");
        } else {
            $stmt = $db->prepare("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans','auto_approve_requests',?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->bind_param('s', $origAuto);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable) { /* best effort */ }
    // The setup used an UPDATE (never an INSERT), so only pre-existing rows were
    // touched — a plain UPDATE back to the captured value fully restores state.
    foreach ($origEmail as $emailKey => $emailValue) {
        try {
            $stmt = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE category='email' AND setting_key = ?");
            $stmt->bind_param('ss', $emailValue, $emailKey);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable) { /* best effort */ }
    }
};

$cleanup = static function () use ($db, $titlePrefix, $emailDomain, $restoreSettings): void {
    // Settings restore runs in a finally: a DELETE that throws (strict mysqli
    // mode) must not skip it and pollute later tests.
    try {
        $titleLike = $titlePrefix . '%';
        $emailLike = '%' . $emailDomain;
        foreach ([
            'DELETE p FROM prestiti p JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE ?',
            'DELETE r FROM prenotazioni r JOIN libri l ON l.id = r.libro_id WHERE l.titolo LIKE ?',
            'DELETE c FROM copie c JOIN libri l ON l.id = c.libro_id WHERE l.titolo LIKE ?',
            'DELETE FROM libri WHERE titolo LIKE ?',
        ] as $sql) {
            $stmt = $db->prepare($sql);
            $stmt->bind_param('s', $titleLike);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $db->prepare('DELETE FROM utenti WHERE email LIKE ?');
        $stmt->bind_param('s', $emailLike);
        $stmt->execute();
        $stmt->close();
    } finally {
        $restoreSettings();
    }
};

// Install the handler BEFORE the first cleanup so an exception in the initial
// (pre-test) cleanup still restores settings and reports cleanly.
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    $db->close();
    exit(1);
});
$cleanup();

$pass = 0;
$check = static function (bool $ok, string $label) use (&$pass): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $pass++;
    echo "  OK  {$label}\n";
};

// ── Fixture helpers ─────────────────────────────────────────────────────────
$bookSeq = 0;
$makeBook = static function (int $copies) use ($db, $titlePrefix, $run, &$bookSeq): int {
    $bookSeq++;
    $title = $titlePrefix . '_' . $bookSeq;
    $stmt = $db->prepare("INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (?, 'disponibile', ?, ?)");
    $stmt->bind_param('sii', $title, $copies, $copies);
    $stmt->execute();
    $bookId = (int) $db->insert_id;
    $stmt->close();
    $copyRepo = new CopyRepository($db);
    for ($i = 1; $i <= $copies; $i++) {
        $copyRepo->create($bookId, 'ZZR301-' . $run . '-' . $bookSeq . '-' . $i, 'disponibile');
    }
    return $bookId;
};

$userSeq = 0;
$makeUser = static function () use ($db, $run, $emailDomain, &$userSeq): int {
    $userSeq++;
    $card = 'ZZR301' . strtoupper($run) . $userSeq;
    $email = $run . '-' . $userSeq . $emailDomain;
    $password = password_hash('test', PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato) VALUES (?, 'Res', 'Test', ?, ?, 'standard', 'attivo')");
    $stmt->bind_param('sss', $card, $email, $password);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$today = DateHelper::today();
$due = (new DateTimeImmutable($today))->modify('+14 days')->format('Y-m-d');

// Drive the REAL createReservation with a JSON request + session user, exactly
// like the book-detail modal does (CSRF is middleware, not in the controller).
$callCreate = static function (int $bookId, int $userId, ?string $start = null, ?string $end = null) use ($db, $today, $due): array {
    $start = $start ?? $today;
    $end = $end ?? $due;
    $_SESSION['user'] = ['id' => $userId, 'tipo_utente' => 'standard'];
    // Mirror the book-detail modal exactly: a JSON body behind Content-Type:
    // application/json, so the request drives createReservation's json_decode
    // branch (getParsedBody() does NOT parse JSON — the form-urlencoded path
    // would silently exercise different code than real users hit).
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/api/libro/' . $bookId . '/reservation')
        ->withHeader('Content-Type', 'application/json');
    $request->getBody()->write((string) json_encode(['start_date' => $start, 'end_date' => $end]));
    $request->getBody()->rewind();
    $controller = new ReservationsController($db);
    $result = $controller->createReservation($request, new SlimResponse(), ['id' => $bookId]);
    $payload = json_decode((string) $result->getBody(), true) ?: [];
    unset($_SESSION['user']);
    return ['status' => $result->getStatusCode(), 'payload' => $payload];
};

$loanField = static function (int $loanId, string $col) use ($db) {
    $stmt = $db->prepare("SELECT {$col} AS v FROM prestiti WHERE id = ?");
    $stmt->bind_param('i', $loanId);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc()['v'] ?? null;
    $stmt->close();
    return $v;
};

$makeActiveLoan = static function (int $bookId, int $copyId, int $userId, string $start, string $end) use ($db): int {
    $stmt = $db->prepare("
        INSERT INTO prestiti
            (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
        VALUES (?, ?, ?, ?, ?, 'in_corso', 'diretto', 1)
    ");
    $stmt->bind_param('iiiss', $bookId, $copyId, $userId, $start, $end);
    $stmt->execute();
    $loanId = (int) $db->insert_id;
    $stmt->close();
    $copyStmt = $db->prepare("UPDATE copie SET stato = 'prestato' WHERE id = ?");
    $copyStmt->bind_param('i', $copyId);
    $copyStmt->execute();
    $copyStmt->close();
    return $loanId;
};

$makeScheduledLoan = static function (int $bookId, int $copyId, int $userId, string $start, string $end) use ($db): int {
    $stmt = $db->prepare("
        INSERT INTO prestiti
            (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
        VALUES (?, ?, ?, ?, ?, 'prenotato', 'diretto', 1)
    ");
    $stmt->bind_param('iiiss', $bookId, $copyId, $userId, $start, $end);
    $stmt->execute();
    $loanId = (int) $db->insert_id;
    $stmt->close();
    $copyStmt = $db->prepare("UPDATE copie SET stato = 'prenotato' WHERE id = ?");
    $copyStmt->bind_param('i', $copyId);
    $copyStmt->execute();
    $copyStmt->close();
    return $loanId;
};

$makeCopyBoundCommitment = static function (
    int $bookId,
    int $copyId,
    int $userId,
    string $start,
    string $end,
    string $state,
    int $active
) use ($db): int {
    $stmt = $db->prepare("
        INSERT INTO prestiti
            (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
        VALUES (?, ?, ?, ?, ?, ?, 'prenotazione', ?)
    ");
    $stmt->bind_param('iiisssi', $bookId, $copyId, $userId, $start, $end, $state, $active);
    $stmt->execute();
    $loanId = (int) $db->insert_id;
    $stmt->close();
    $copyStmt = $db->prepare("UPDATE copie SET stato = 'prenotato' WHERE id = ?");
    $copyStmt->bind_param('i', $copyId);
    $copyStmt->execute();
    $copyStmt->close();
    return $loanId;
};

$makeBarePending = static function (int $bookId, int $userId, string $start, string $end) use ($db): int {
    $stmt = $db->prepare("
        INSERT INTO prestiti
            (libro_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
        VALUES (?, ?, ?, ?, 'pendente', 'richiesta', 0)
    ");
    $stmt->bind_param('iiss', $bookId, $userId, $start, $end);
    $stmt->execute();
    $loanId = (int) $db->insert_id;
    $stmt->close();
    return $loanId;
};

$callApprove = static function (int $loanId) use ($db): array {
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/admin/loans/approve')
        ->withParsedBody(['loan_id' => $loanId]);
    $result = (new LoanApprovalController())->approveLoan($request, new SlimResponse(), $db);
    return [
        'status' => $result->getStatusCode(),
        'payload' => json_decode((string) $result->getBody(), true) ?: [],
    ];
};

$copyIdsForBook = static function (int $bookId) use ($db): array {
    $stmt = $db->prepare('SELECT id FROM copie WHERE libro_id = ? ORDER BY id');
    $stmt->bind_param('i', $bookId);
    $stmt->execute();
    $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
    $stmt->close();
    return $ids;
};

$makeActiveReservation = static function (
    int $bookId,
    int $userId,
    string $start,
    string $end,
    int $queuePosition = 1
) use ($db): int {
    $startDt = $start . ' 00:00:00';
    $endDt = $end . ' 23:59:59';
    $stmt = $db->prepare("
        INSERT INTO prenotazioni
            (libro_id, utente_id, queue_position, stato,
             data_prenotazione, data_scadenza_prenotazione,
             data_inizio_richiesta, data_fine_richiesta)
        VALUES (?, ?, ?, 'attiva', ?, ?, ?, ?)
    ");
    $stmt->bind_param('iiissss', $bookId, $userId, $queuePosition, $startDt, $endDt, $start, $end);
    $stmt->execute();
    $reservationId = (int) $db->insert_id;
    $stmt->close();
    return $reservationId;
};

$reservationForUser = static function (int $bookId, int $userId) use ($db): ?array {
    $stmt = $db->prepare("
        SELECT id, stato, queue_position, data_inizio_richiesta, data_fine_richiesta
        FROM prenotazioni
        WHERE libro_id = ? AND utente_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param('ii', $bookId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
};

$openLoanCountForUser = static function (int $bookId, int $userId) use ($db): int {
    $stmt = $db->prepare("
        SELECT COUNT(*) AS n
        FROM prestiti
        WHERE libro_id = ? AND utente_id = ?
          AND ((attivo = 0 AND stato = 'pendente')
               OR (attivo = 1 AND stato IN ('prenotato','da_ritirare','in_corso','in_ritardo')))
    ");
    $stmt->bind_param('ii', $bookId, $userId);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    $stmt->close();
    return $count;
};

$settings = new SettingsRepository($db);

// ── A. Setting OFF: the manual approval queue is preserved ──────────────────
echo "A. createReservation with auto_approve OFF\n";
$settings->set('loans', 'auto_approve_requests', '0');
$resOff = $callCreate($makeBook(1), $makeUser());
$check($resOff['status'] === 200 && ($resOff['payload']['success'] ?? false) === true, '01 request accepted');
$check(($resOff['payload']['status'] ?? '') === 'pending_approval', '02 response status = pending_approval');
$check(($resOff['payload']['auto_approved'] ?? null) === false, '03 response auto_approved = false');
$loanOffId = (int) ($resOff['payload']['loan_request_id'] ?? 0);
$check($loanOffId > 0 && $loanField($loanOffId, 'stato') === 'pendente', '04 loan stays pendente (manual workflow preserved)');

// ── B. Setting ON: the modal path now honours the option (#301) ─────────────
echo "B. createReservation with auto_approve ON\n";
$settings->set('loans', 'auto_approve_requests', '1');
$resOn = $callCreate($makeBook(1), $makeUser());
$check($resOn['status'] === 200 && ($resOn['payload']['success'] ?? false) === true, '05 request accepted');
$check(($resOn['payload']['auto_approved'] ?? null) === true, '06 response auto_approved = true');
$check(($resOn['payload']['status'] ?? '') === 'approved', '07 response status = approved');
$loanOnId = (int) ($resOn['payload']['loan_request_id'] ?? 0);
$check($loanOnId > 0 && $loanField($loanOnId, 'stato') === 'da_ritirare', "08 loan promoted to 'da_ritirare' (approval phase skipped, pickup preserved)");
$check((int) $loanField($loanOnId, 'attivo') === 1 && $loanField($loanOnId, 'copia_id') !== null, '09 canonical pipeline assigned a copy and activated the loan');
$check($loanField($loanOnId, 'pickup_deadline') !== null, '10 immediate auto-approved loan has a pickup deadline');

// ── C. Setting ON but no physical copy: approval fails gracefully ───────────
// A legacy aggregate-only book can advertise capacity without having a `copie`
// row. The request is valid, but the canonical approval allocator cannot assign
// a physical copy: createReservation must still succeed and retain the manual
// pending request (which also keeps the admin-notification path active).
echo "C. createReservation with auto-approve ON and no physical copy\n";
$legacyBookId = $makeBook(0);
$db->query("UPDATE libri SET copie_totali = 1, copie_disponibili = 1 WHERE id = {$legacyBookId}");
$dispBefore = (int) $db->query("SELECT copie_disponibili FROM libri WHERE id = {$legacyBookId}")->fetch_row()[0];
$resNoCopy = $callCreate($legacyBookId, $makeUser());
$check($resNoCopy['status'] === 200 && ($resNoCopy['payload']['success'] ?? false) === true, '11 request remains accepted when automatic approval cannot allocate a copy');
$check(($resNoCopy['payload']['auto_approved'] ?? null) === false, '12 response auto_approved = false after allocation failure');
$check(($resNoCopy['payload']['status'] ?? '') === 'pending_approval', '13 response falls back to pending_approval');
$loanNoCopyId = (int) ($resNoCopy['payload']['loan_request_id'] ?? 0);
$check(
    $loanNoCopyId > 0
        && $loanField($loanNoCopyId, 'stato') === 'pendente'
        && $loanField($loanNoCopyId, 'copia_id') === null,
    '14 request stays pendente without an assigned copy'
);
// A pending request with NO assigned copy must NOT decrement the aggregate
// availability — otherwise a regression could hide behind the stato/copia_id
// asserts while silently shrinking libri.copie_disponibili.
$dispAfter = (int) $db->query("SELECT copie_disponibili FROM libri WHERE id = {$legacyBookId}")->fetch_row()[0];
$check($dispAfter === $dispBefore, "15 aggregate availability is unchanged ({$dispBefore}) — a copy-less pending request occupies nothing");

// ── D. Setting ON + FUTURE start date: the loan is SCHEDULED, not awaiting ───
// F009: a future-dated request is auto-approved into stato 'prenotato' (no
// pickup deadline yet). The response must say 'scheduled', not 'approved', and
// must NOT claim the book is awaiting pickup.
echo "D. createReservation with a FUTURE start date (auto-approve ON) → scheduled\n";
$settings->set('loans', 'auto_approve_requests', '1');
$futureStart = (new DateTimeImmutable($today))->modify('+10 days')->format('Y-m-d');
$futureEnd = (new DateTimeImmutable($today))->modify('+24 days')->format('Y-m-d');
$awaitingPickupMsg = __('Prestito approvato - in attesa di ritiro');
$resFuture = $callCreate($makeBook(1), $makeUser(), $futureStart, $futureEnd);
$check($resFuture['status'] === 200 && ($resFuture['payload']['success'] ?? false) === true, '16 future-dated request accepted');
$check(($resFuture['payload']['auto_approved'] ?? null) === true, '17 response auto_approved = true (a real boolean)');
$check(($resFuture['payload']['status'] ?? '') === 'scheduled', "18 response status = 'scheduled' (not 'approved')");
$check(($resFuture['payload']['loan_state'] ?? '') === 'prenotato', "19 response exposes loan_state = 'prenotato'");
$check(($resFuture['payload']['message'] ?? '') !== $awaitingPickupMsg, '20 message is NOT the awaiting-pickup string');
$loanFutureId = (int) ($resFuture['payload']['loan_request_id'] ?? 0);
$check($loanFutureId > 0 && $loanField($loanFutureId, 'stato') === 'prenotato', "21 loan persisted as 'prenotato' (scheduled)");
$check($loanField($loanFutureId, 'pickup_deadline') === null, '22 scheduled loan has no pickup_deadline yet');

// ── E. #384: a currently-out single copy creates a REAL reservation ────────
// The requested future window starts the day after the contractual due date,
// exactly like the reporter's screenshot. A due date is not a physical return:
// the unified button must create prenotazioni.attiva, not a copy-less loan
// request that bypasses the queue and cannot be approved while the copy is out.
echo "E. #384: out single copy + date-disjoint future window → waitlist reservation\n";
$settings->set('loans', 'auto_approve_requests', '1');
$outBookId = $makeBook(1);
$outCopyId = $copyIdsForBook($outBookId)[0];
$currentBorrower = $makeUser();
$currentDue = (new DateTimeImmutable($today))->modify('+3 days')->format('Y-m-d');
$currentLoanId = $makeActiveLoan($outBookId, $outCopyId, $currentBorrower, $today, $currentDue);
$waitingUser = $makeUser();
$waitStart = (new DateTimeImmutable($currentDue))->modify('+1 day')->format('Y-m-d');
$waitEnd = (new DateTimeImmutable($waitStart))->modify('+14 days')->format('Y-m-d');
$resWait = $callCreate($outBookId, $waitingUser, $waitStart, $waitEnd);
$check($resWait['status'] === 200 && ($resWait['payload']['success'] ?? false) === true, '23 out-copy submission is accepted as a reservation');
$check(($resWait['payload']['status'] ?? '') === 'reserved' && ($resWait['payload']['auto_approved'] ?? null) === false, '24 response identifies a real waitlist reservation');
$waitRow = $reservationForUser($outBookId, $waitingUser);
$check(
    $waitRow !== null
        && (int) $waitRow['id'] === (int) ($resWait['payload']['reservation_id'] ?? 0)
        && $waitRow['stato'] === 'attiva'
        && (int) $waitRow['queue_position'] === 1,
    '25 active FIFO reservation is persisted and returned in the payload'
);
$check(
    $waitRow !== null
        && $waitRow['data_inizio_richiesta'] === $waitStart
        && $waitRow['data_fine_richiesta'] === $waitEnd,
    '26 reservation preserves the requested future interval'
);
$check($openLoanCountForUser($outBookId, $waitingUser) === 0, '27 no pending loan request bypasses the reservation queue');
$check(
    $loanField($currentLoanId, 'stato') === 'in_corso'
        && (int) $loanField($currentLoanId, 'attivo') === 1
        && $db->query("SELECT stato FROM copie WHERE id = {$outCopyId}")->fetch_row()[0] === 'prestato',
    '28 current loan and physically-out copy remain untouched'
);
$duplicateWait = $callCreate($outBookId, $waitingUser, $waitStart, $waitEnd);
$check($duplicateWait['status'] === 400, '29 duplicate submission cannot create a second reservation or loan');

// Multi-copy edge: one out copy must not force the whole title into the
// waitlist while a genuinely free sibling can serve the future loan.
echo "F. #384 multi-copy: free sibling preserves normal scheduling\n";
$multiBookId = $makeBook(2);
[$multiOutCopy, $multiFreeCopy] = $copyIdsForBook($multiBookId);
$multiCurrentLoan = $makeActiveLoan($multiBookId, $multiOutCopy, $makeUser(), $today, $currentDue);
$multiRequester = $makeUser();
$resMulti = $callCreate($multiBookId, $multiRequester, $waitStart, $waitEnd);
$check(($resMulti['payload']['status'] ?? '') === 'scheduled' && ($resMulti['payload']['auto_approved'] ?? false) === true, '30 free sibling keeps the auto-approved scheduled-loan flow');
$multiLoanId = (int) ($resMulti['payload']['loan_request_id'] ?? 0);
$check(
    $multiLoanId > 0
        && (int) $loanField($multiLoanId, 'copia_id') === $multiFreeCopy
        && $loanField($multiLoanId, 'stato') === 'prenotato',
    '31 scheduled loan binds only the genuinely free sibling'
);
$check(
    $loanField($multiCurrentLoan, 'stato') === 'in_corso'
        && (int) $loanField($multiCurrentLoan, 'copia_id') === $multiOutCopy,
    '32 existing loan on the other copy remains intact'
);
$check($reservationForUser($multiBookId, $multiRequester) === null, '33 no waitlist row is created when a sibling is assignable');

// A `prenotato` copy can still be physically in the library: if its future
// commitment is date-disjoint, the canonical approval path deliberately lets
// it serve an earlier loan. The #384 routing gate must mirror that behavior and
// reserve only when the copy is out, period-conflicted, or requires a preceding
// borrower to return it first.
echo "G. #384 scheduled edge: in-library prenotato copy remains assignable\n";
$scheduledBookId = $makeBook(1);
$scheduledCopyId = $copyIdsForBook($scheduledBookId)[0];
$laterStart = (new DateTimeImmutable($today))->modify('+15 days')->format('Y-m-d');
$laterEnd = (new DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d');
$laterLoanId = $makeScheduledLoan($scheduledBookId, $scheduledCopyId, $makeUser(), $laterStart, $laterEnd);
$earlierRequester = $makeUser();
$earlierEnd = (new DateTimeImmutable($today))->modify('+14 days')->format('Y-m-d');
$resEarlier = $callCreate($scheduledBookId, $earlierRequester, $today, $earlierEnd);
$check(
    ($resEarlier['payload']['status'] ?? '') === 'approved'
        && ($resEarlier['payload']['auto_approved'] ?? false) === true,
    '34 disjoint future commitment does not force the immediate request into the waitlist'
);
$earlierLoanId = (int) ($resEarlier['payload']['loan_request_id'] ?? 0);
$check(
    $earlierLoanId > 0
        && (int) $loanField($earlierLoanId, 'copia_id') === $scheduledCopyId
        && $loanField($earlierLoanId, 'stato') === 'da_ritirare',
    '35 immediate loan reuses the in-library prenotato copy for the disjoint earlier window'
);
$check(
    $loanField($laterLoanId, 'stato') === 'prenotato'
        && (int) $loanField($laterLoanId, 'copia_id') === $scheduledCopyId,
    '36 the existing future commitment remains scheduled on the same copy'
);
$check($reservationForUser($scheduledBookId, $earlierRequester) === null, '37 no waitlist row is created for a period-assignable in-library copy');

// Temporal mirror: an in-library copy is NOT safe for a requested window that
// follows an existing commitment. Serving that request would depend on the
// earlier borrower returning on time, exactly the assumption rejected by #384.
echo "H. #384 scheduled edge: a preceding commitment routes to the waitlist\n";
$precedingBookId = $makeBook(1);
$precedingCopyId = $copyIdsForBook($precedingBookId)[0];
$precedingStart = (new DateTimeImmutable($today))->modify('+2 days')->format('Y-m-d');
$precedingEnd = (new DateTimeImmutable($today))->modify('+5 days')->format('Y-m-d');
$precedingLoanId = $makeScheduledLoan($precedingBookId, $precedingCopyId, $makeUser(), $precedingStart, $precedingEnd);
$afterStart = (new DateTimeImmutable($today))->modify('+6 days')->format('Y-m-d');
$afterEnd = (new DateTimeImmutable($today))->modify('+12 days')->format('Y-m-d');
$afterRequester = $makeUser();
$resAfter = $callCreate($precedingBookId, $afterRequester, $afterStart, $afterEnd);
$check(($resAfter['payload']['status'] ?? '') === 'reserved', '38 request after a scheduled loan becomes a reservation');
$check($reservationForUser($precedingBookId, $afterRequester) !== null, '39 the post-commitment waitlist row is persisted');
$check($openLoanCountForUser($precedingBookId, $afterRequester) === 0, '40 no pending loan bypasses the preceding scheduled commitment');
$check($loanField($precedingLoanId, 'stato') === 'prenotato', '41 the preceding scheduled loan remains untouched');

// The same return dependency exists for an awaiting-pickup loan and for a
// copy-bound conversion pending approval, even though both keep the physical
// copy in `prenotato` rather than `prestato`.
echo "I. #384 in-library holds: da_ritirare and bound pending route to waitlist\n";
$pickupBookId = $makeBook(1);
$pickupCopyId = $copyIdsForBook($pickupBookId)[0];
$pickupCommitment = $makeCopyBoundCommitment($pickupBookId, $pickupCopyId, $makeUser(), $today, $currentDue, 'da_ritirare', 1);
$pickupRequester = $makeUser();
$resAfterPickup = $callCreate($pickupBookId, $pickupRequester, $waitStart, $waitEnd);
$check(($resAfterPickup['payload']['status'] ?? '') === 'reserved', '42 request after da_ritirare becomes a reservation');
$check($loanField($pickupCommitment, 'stato') === 'da_ritirare', '43 awaiting-pickup predecessor remains untouched');

$pendingBookId = $makeBook(1);
$pendingCopyId = $copyIdsForBook($pendingBookId)[0];
$boundPending = $makeCopyBoundCommitment($pendingBookId, $pendingCopyId, $makeUser(), $today, $currentDue, 'pendente', 0);
$pendingRequester = $makeUser();
$resAfterPending = $callCreate($pendingBookId, $pendingRequester, $waitStart, $waitEnd);
$check(($resAfterPending['payload']['status'] ?? '') === 'reserved', '44 request after a copy-bound pending conversion becomes a reservation');
$check($loanField($boundPending, 'stato') === 'pendente', '45 copy-bound pending predecessor remains untouched');

// Multi-copy: a tainted earlier commitment must not force a waitlist when a
// clean sibling exists, and approval must bind that clean sibling rather than
// selecting the lower-id date-disjoint predecessor copy.
echo "J. #384 multi-copy allocation prefers a no-prior-return sibling\n";
$safeBookId = $makeBook(2);
[$priorCopyId, $safeCopyId] = $copyIdsForBook($safeBookId);
$makeScheduledLoan($safeBookId, $priorCopyId, $makeUser(), $precedingStart, $precedingEnd);
$safeRequester = $makeUser();
$resSafe = $callCreate($safeBookId, $safeRequester, $afterStart, $afterEnd);
$safeLoanId = (int) ($resSafe['payload']['loan_request_id'] ?? 0);
$check(($resSafe['payload']['status'] ?? '') === 'scheduled', '46 clean sibling keeps the post-commitment request in the loan flow');
$check(
    $safeLoanId > 0 && (int) $loanField($safeLoanId, 'copia_id') === $safeCopyId,
    '47 allocator binds the clean sibling, not the copy awaiting an earlier return'
);
$check($reservationForUser($safeBookId, $safeRequester) === null, '48 no waitlist row is created while a safe sibling exists');

// An unbound FIFO reservation is invisible to per-copy SQL, but it still owns
// book-level capacity. On a single-copy title a subsequent request must join the
// queue instead of assuming that the earlier reservation will be promoted,
// collected and returned on time.
echo "K. #384 active-reservation predecessor participates in the safety gate\n";
$fifoBookId = $makeBook(1);
$fifoOwner = $makeUser();
$fifoReservationId = $makeActiveReservation($fifoBookId, $fifoOwner, $precedingStart, $precedingEnd);
$fifoFollower = $makeUser();
$resAfterFifo = $callCreate($fifoBookId, $fifoFollower, $afterStart, $afterEnd);
$check(($resAfterFifo['payload']['status'] ?? '') === 'reserved', '49 request after an active single-copy FIFO commitment stays in the waitlist');
$followerReservation = $reservationForUser($fifoBookId, $fifoFollower);
$check(
    $followerReservation !== null && (int) $followerReservation['queue_position'] === 2,
    '50 follower receives the next FIFO position'
);
$check($openLoanCountForUser($fifoBookId, $fifoFollower) === 0, '51 no bare pending loan bypasses the earlier active reservation');
$check(
    ($reservationForUser($fifoBookId, $fifoOwner)['stato'] ?? '') === 'attiva'
        && $fifoReservationId > 0,
    '52 predecessor reservation remains active and unchanged'
);

// Capacity-aware multi-copy mirror: a single unbound predecessor does not taint
// every copy when another independent slot remains throughout the horizon.
$fifoMultiBookId = $makeBook(2);
$makeActiveReservation($fifoMultiBookId, $makeUser(), $precedingStart, $precedingEnd);
$fifoMultiRequester = $makeUser();
$resAfterFifoMulti = $callCreate($fifoMultiBookId, $fifoMultiRequester, $afterStart, $afterEnd);
$fifoMultiLoanId = (int) ($resAfterFifoMulti['payload']['loan_request_id'] ?? 0);
$check(($resAfterFifoMulti['payload']['status'] ?? '') === 'scheduled', '53 a second independent copy preserves the scheduled-loan path');
$check(
    $fifoMultiLoanId > 0 && $loanField($fifoMultiLoanId, 'copia_id') !== null,
    '54 automatic approval binds a physical copy while the other slot covers FIFO capacity'
);
$check($reservationForUser($fifoMultiBookId, $fifoMultiRequester) === null, '55 no unnecessary follower reservation is created with independent capacity');

// The approval endpoint must enforce the same invariant independently. This is
// essential for old/manual bare pending rows and is the last guard if a request
// reaches approval after concurrent state changes.
echo "L. #384 approval rejects every preceding-return-only candidate\n";
$manualPriorBookId = $makeBook(1);
$manualPriorCopyId = $copyIdsForBook($manualPriorBookId)[0];
$makeScheduledLoan($manualPriorBookId, $manualPriorCopyId, $makeUser(), $precedingStart, $precedingEnd);
$manualFollowerLoan = $makeBarePending($manualPriorBookId, $makeUser(), $afterStart, $afterEnd);
$manualPriorApproval = $callApprove($manualFollowerLoan);
$check($manualPriorApproval['status'] === 400, '56 approval rejects a date-disjoint copy that still requires a preceding return');
$check($loanField($manualFollowerLoan, 'stato') === 'pendente', '57 rejected approval leaves the durable request pending for safe handling');

$manualFifoBookId = $makeBook(1);
$makeActiveReservation($manualFifoBookId, $makeUser(), $precedingStart, $precedingEnd);
$manualFifoLoan = $makeBarePending($manualFifoBookId, $makeUser(), $afterStart, $afterEnd);
$manualFifoApproval = $callApprove($manualFifoLoan);
$check($manualFifoApproval['status'] === 400, '58 approval also rejects an earlier unbound FIFO commitment on the only slot');
$check($loanField($manualFifoLoan, 'copia_id') === null, '59 rejected FIFO-edge request remains copy-less');

// ── M. F007: a post-commit settings-read failure degrades to pending ────────
// The helper runs AFTER the request row is committed. If its SettingsRepository
// read throws (e.g. the connection died in the post-commit window), the helper
// must CATCH it and return null — so the endpoint reports pending_approval with
// HTTP 200, instead of letting the throw escape to the outer transaction catch
// (which would return a 500 for an already-durable request, and the duplicate
// guard would then permanently block the user's retry). Simulated by invoking
// the private helper against a closed connection: pre-fix the settings read sat
// OUTSIDE the try and this invoke would throw; post-fix it is caught → null.
echo "M. F007: settings-read failure in the post-commit helper degrades to pending (never escapes)\n";
$deadDb = $socket !== '' && file_exists($socket)
    ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
    : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
$deadDb->close(); // connection lost right after the commit
$faultRequest = (new ServerRequestFactory())->createServerRequest('POST', '/api/libro/1/reservation');
$faultThrew = false;
$faultResult = 'unset';
try {
    $faultController = new ReservationsController($deadDb);
    $faultMethod = new ReflectionMethod($faultController, 'autoApproveLoanRequest');
    $faultMethod->setAccessible(true);
    $faultResult = $faultMethod->invoke($faultController, $faultRequest, 12345);
} catch (\Throwable $e) {
    $faultThrew = true;
}
$check($faultThrew === false, '60 a settings-read failure is caught inside the helper (does not escape to the outer catch)');
$check($faultResult === null, '61 failed post-commit settings read degrades to null → pending_approval');

$cleanup();
$db->close();
echo "\n{$pass} checks passed\n";
exit(0);

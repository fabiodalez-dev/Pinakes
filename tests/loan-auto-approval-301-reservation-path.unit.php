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
 *
 * Run: php tests/loan-auto-approval-301-reservation-path.unit.php
 */

use App\Controllers\ReservationsController;
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

// ── E. F007: a post-commit settings-read failure degrades to pending ────────
// The helper runs AFTER the request row is committed. If its SettingsRepository
// read throws (e.g. the connection died in the post-commit window), the helper
// must CATCH it and return null — so the endpoint reports pending_approval with
// HTTP 200, instead of letting the throw escape to the outer transaction catch
// (which would return a 500 for an already-durable request, and the duplicate
// guard would then permanently block the user's retry). Simulated by invoking
// the private helper against a closed connection: pre-fix the settings read sat
// OUTSIDE the try and this invoke would throw; post-fix it is caught → null.
echo "E. F007: settings-read failure in the post-commit helper degrades to pending (never escapes)\n";
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
$check($faultThrew === false, '23 a settings-read failure is caught inside the helper (does not escape to the outer catch)');
$check($faultResult === null, '24 failed post-commit settings read degrades to null → pending_approval');

$cleanup();
$db->close();
echo "\n{$pass} checks passed\n";
exit(0);

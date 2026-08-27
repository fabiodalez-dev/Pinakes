<?php
declare(strict_types=1);

/**
 * Coherence contract for the shared #384 loan-request routing gate
 * (App\Services\LoanRequestGate), covering the core public entry points and
 * the NCIP RequestItem interoperability path:
 *
 *   - the shared gate itself (decision + waitlist persistence, DB level);
 *   - POST /user/loan (UserActionsController::loan) driven through a REAL
 *     ServerRequest — the historically NON-#384-aware second entry point that
 *     always created a bare `prestiti.pendente` row (no copia_id), recreating
 *     the exact state admin approval rejects with HTTP 400 (test D.28).
 *
 * Proven here (fails by design on the pre-refactor code):
 *   - single-copy book whose only copy is held by a commitment PRECEDING the
 *     requested window → the public loan path creates a `prenotazioni.attiva`
 *     reservation and does NOT create a bare pending with copia_id NULL;
 *   - negative control: a genuinely free copy keeps the pending-loan flow
 *     (no reservation row);
 *   - I6: a legacy book with NO `copie` rows keeps the bare-pending fallback;
 *   - transaction contract: with inCallerTransaction=true the gate never
 *     commits the caller's transaction (a rollback erases its reservation).
 *   - NCIP RequestItem routes the same edge to a linked reservation, and
 *     CancelRequestItem cancels only that protocol-owned row while preserving
 *     FIFO ordering.
 *
 * Run: php tests/loan-request-gate-coherence.unit.php
 */

use App\Controllers\UserActionsController;
use App\Models\CopyRepository;
use App\Models\SettingsRepository;
use App\Plugins\MobileApi\Controllers\ActionsController as MobileActionsController;
use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Plugins\NcipServer\NcipServerPlugin;
use App\Services\LoanRequestGate;
use App\Support\DateHelper;
use App\Support\HookManager;
use App\Support\NotificationService;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/AppAuthMiddleware.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/JsonBody.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/ResponseEnvelope.php';
require_once $root . '/storage/plugins/mobile-api/src/Controllers/ActionsController.php';
require_once $root . '/storage/plugins/ncip-server/NcipServerPlugin.php';

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
    // Production writers bind the application-local date on every connection;
    // the circulation triggers otherwise fall back to the database's UTC
    // CURRENT_DATE(), which disagrees with app.timezone late in the evening.
    DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$run = substr(hash('sha256', uniqid((string) getmypid(), true)), 0, 10);
$titlePrefix = 'ZZGATE384_' . $run;
$emailDomain = '@gate384.test.local';

// Preserve the global auto-approve setting so the run doesn't leave it toggled.
$origAuto = null;
if ($r = $db->query("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='auto_approve_requests'")) {
    if ($row = $r->fetch_assoc()) {
        $origAuto = $row['setting_value'];
    }
}

$restoreSettings = static function () use ($db, $origAuto): void {
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
};

$cleanup = static function () use ($db, $titlePrefix, $emailDomain, $restoreSettings): void {
    try {
        $titleLike = $titlePrefix . '%';
        $emailLike = '%' . $emailDomain;
        foreach ([
            'DELETE tx FROM ncip_transactions tx JOIN prestiti p ON p.id = tx.prestito_id JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE ?',
            'DELETE tx FROM ncip_transactions tx JOIN prenotazioni r ON r.id = tx.prenotazione_id JOIN libri l ON l.id = r.libro_id WHERE l.titolo LIKE ?',
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

// The pending and approval paths notify users/admins. Stop sendWithRetry()
// deterministically before EmailService can contact SMTP or the local mail
// transport; retain the process-local value so even the exceptional exit path
// restores the static state.
$smtpProbe = new ReflectionProperty(\App\Support\Mailer::class, 'smtpReachable');
$originalSmtpReachable = $smtpProbe->getValue();
$smtpProbe->setValue(null, false);

set_exception_handler(static function (Throwable $e) use ($cleanup, $db, $smtpProbe, $originalSmtpReachable): void {
    try { $cleanup(); } catch (Throwable) {}
    $smtpProbe->setValue(null, $originalSmtpReachable);
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    $db->close();
    exit(1);
});
$ncip = new NcipServerPlugin($db, new HookManager($db));
$ncipSchemaResult = $ncip->ensureSchema();
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
        $copyRepo->create($bookId, 'ZZG384-' . $run . '-' . $bookSeq . '-' . $i, 'disponibile');
    }
    return $bookId;
};

$userSeq = 0;
$makeUser = static function () use ($db, $run, $emailDomain, &$userSeq): int {
    $userSeq++;
    $card = 'ZZG384' . strtoupper($run) . $userSeq;
    $email = $run . '-' . $userSeq . $emailDomain;
    $password = password_hash('test', PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato) VALUES (?, 'Gate', 'Test', ?, ?, 'standard', 'attivo')");
    $stmt->bind_param('sss', $card, $email, $password);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$copyIdsForBook = static function (int $bookId) use ($db): array {
    $stmt = $db->prepare('SELECT id FROM copie WHERE libro_id = ? ORDER BY id');
    $stmt->bind_param('i', $bookId);
    $stmt->execute();
    $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
    $stmt->close();
    return $ids;
};

/**
 * A copy-bound ACTIVE commitment (in_corso / da_ritirare / prenotato) holding
 * the copy for [start, end]; the copy leaves 'disponibile'.
 */
$makeCommitment = static function (int $bookId, int $copyId, int $userId, string $start, string $end, string $state, string $copyState) use ($db): int {
    $stmt = $db->prepare("
        INSERT INTO prestiti
            (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
        VALUES (?, ?, ?, ?, ?, ?, 'diretto', 1)
    ");
    $stmt->bind_param('iiisss', $bookId, $copyId, $userId, $start, $end, $state);
    $stmt->execute();
    $loanId = (int) $db->insert_id;
    $stmt->close();
    $copyStmt = $db->prepare("UPDATE copie SET stato = ? WHERE id = ?");
    $copyStmt->bind_param('si', $copyState, $copyId);
    $copyStmt->execute();
    $copyStmt->close();
    return $loanId;
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

// The landmine state this whole refactor forbids: a bare 'pendente' request
// with NO copia_id on a title whose copy is held (D.28 rejects it at approval).
$barePendingCount = static function (int $bookId, int $userId) use ($db): int {
    $stmt = $db->prepare("
        SELECT COUNT(*) AS n
        FROM prestiti
        WHERE libro_id = ? AND utente_id = ?
          AND attivo = 0 AND stato = 'pendente' AND copia_id IS NULL
    ");
    $stmt->bind_param('ii', $bookId, $userId);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    $stmt->close();
    return $count;
};

$latestLoanForUser = static function (int $bookId, int $userId) use ($db): ?array {
    $stmt = $db->prepare("
        SELECT id, copia_id, stato, attivo
        FROM prestiti
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

final class NoopGateNotificationService extends NotificationService
{
    public function notifyLoanRequest(int $loanId): bool
    {
        return false;
    }
}

final class GateTestUserActionsController extends UserActionsController
{
    protected function createNotificationService(mysqli $db): NotificationService
    {
        return new NoopGateNotificationService($db);
    }
}

// Drive the REAL POST /user/loan controller action, exactly like the route
// does (CSRF is middleware; the body is form-encoded → getParsedBody()). The
// only overridden seam is the post-commit admin email sender.
$callLoan = static function (int $bookId, int $userId) use ($db): array {
    $_SESSION['user'] = ['id' => $userId, 'tipo_utente' => 'standard'];
    $_SERVER['HTTP_REFERER'] = '/';
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/user/loan')
        ->withParsedBody(['libro_id' => (string) $bookId]);
    $result = (new GateTestUserActionsController())->loan($request, new SlimResponse(), $db);
    unset($_SESSION['user'], $_SERVER['HTTP_REFERER']);
    return [
        'status' => $result->getStatusCode(),
        'location' => $result->getHeaderLine('Location'),
    ];
};

$settings = new SettingsRepository($db);
// Auto-approve OFF: the negative control must persist the plain pending row
// (the reservation branch never consults the setting, so it is unaffected).
$settings->set('loans', 'auto_approve_requests', '0');

$today = DateHelper::today();

// ── A. The shared gate itself: preceding commitment → waitlist row ──────────
// Single-copy title; the only copy carries a scheduled loan PRECEDING the
// requested window (starts before its end). I1: serving the later window would
// depend on that borrower returning on time → must become a reservation.
echo "A. shared gate: single copy held by a preceding commitment → reservation\n";
$gateBookId = $makeBook(1);
$gateCopyId = $copyIdsForBook($gateBookId)[0];
$precedingStart = (new DateTimeImmutable($today))->modify('+2 days')->format('Y-m-d');
$precedingEnd = (new DateTimeImmutable($today))->modify('+5 days')->format('Y-m-d');
$precedingLoanId = $makeCommitment($gateBookId, $gateCopyId, $makeUser(), $precedingStart, $precedingEnd, 'prenotato', 'prenotato');
$gateRequester = $makeUser();
$reqStart = (new DateTimeImmutable($today))->modify('+6 days')->format('Y-m-d');
$reqEnd = (new DateTimeImmutable($today))->modify('+12 days')->format('Y-m-d');

$gate = new LoanRequestGate($db);
$decision = $gate->route($gateBookId, $gateRequester, $reqStart, $reqEnd); // own transaction
$check($decision->isReservation() && $decision->outcome === LoanRequestGate::OUTCOME_RESERVED, '01 gate routes the held-copy request to the waitlist');
$gateRow = $reservationForUser($gateBookId, $gateRequester);
$check(
    $gateRow !== null
        && (int) $gateRow['id'] === (int) $decision->reservationId
        && $gateRow['stato'] === 'attiva'
        && (int) $gateRow['queue_position'] === 1,
    '02 prenotazioni.attiva row persisted with FIFO position 1 and returned id'
);
$check(
    $gateRow !== null
        && $gateRow['data_inizio_richiesta'] === $reqStart
        && $gateRow['data_fine_richiesta'] === $reqEnd,
    '03 reservation carries the requested window'
);
$check($barePendingCount($gateBookId, $gateRequester) === 0, '04 no bare prestiti.pendente (copia_id NULL) row was created');
$stateStmt = $db->prepare('SELECT stato, copia_id FROM prestiti WHERE id = ?');
$stateStmt->bind_param('i', $precedingLoanId);
$stateStmt->execute();
$precedingRow = $stateStmt->get_result()->fetch_assoc();
$stateStmt->close();
$check(
    $precedingRow !== null && $precedingRow['stato'] === 'prenotato' && (int) $precedingRow['copia_id'] === $gateCopyId,
    '05 the preceding commitment stays untouched on its copy'
);

// ── B. Gate decision level: a free copy is NOT routed to the waitlist ───────
echo "B. shared gate negative control: free copy stays on the loan path\n";
$freeDecisionBookId = $makeBook(1);
$freeDecisionCopyId = $copyIdsForBook($freeDecisionBookId)[0];
$freeDecisionUser = $makeUser();
$freeDecision = $gate->route($freeDecisionBookId, $freeDecisionUser, $reqStart, $reqEnd);
$check(!$freeDecision->isReservation() && $freeDecision->outcome === LoanRequestGate::OUTCOME_LOAN, '06 gate keeps a free-copy request on the loan path');
$check($freeDecision->assignableCopyId === null && $freeDecision->hasPhysicalCopies === true, '07 standalone routing does not export a copy claim after releasing its transaction lock');
$check($reservationForUser($freeDecisionBookId, $freeDecisionUser) === null, '08 no reservation row is created on the loan path');

$db->begin_transaction();
$freeLockStmt = $db->prepare('SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
$freeLockStmt->bind_param('i', $freeDecisionBookId);
$freeLockStmt->execute();
$freeLockStmt->get_result();
$freeLockStmt->close();
$claimedFreeDecision = $gate->route($freeDecisionBookId, $freeDecisionUser, $reqStart, $reqEnd, inCallerTransaction: true);
$check((int) $claimedFreeDecision->assignableCopyId === $freeDecisionCopyId, '09 caller-transaction routing exposes the copy while its FOR UPDATE lock is still owned');
$db->rollback();

// ── C. Transaction contract: gate never commits the caller transaction ──────
echo "C. inCallerTransaction=true: caller rollback erases the gate's write\n";
$txBookId = $makeBook(1);
$txCopyId = $copyIdsForBook($txBookId)[0];
$makeCommitment($txBookId, $txCopyId, $makeUser(), $precedingStart, $precedingEnd, 'prenotato', 'prenotato');
$txUser = $makeUser();
$db->begin_transaction();
$lockStmt = $db->prepare('SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
$lockStmt->bind_param('i', $txBookId);
$lockStmt->execute();
$lockStmt->get_result();
$lockStmt->close();
$txDecision = $gate->route($txBookId, $txUser, $reqStart, $reqEnd, inCallerTransaction: true);
$check($txDecision->isReservation(), '10 gate reserves inside the caller transaction');
$db->rollback();
$check($reservationForUser($txBookId, $txUser) === null, '11 caller rollback erased the reservation → the gate did not commit a transaction it did not open');

// ── D. POST /user/loan (real ServerRequest): held copy → reservation ────────
// loan() always requests [today, today+duration], so the preceding commitment
// must sit strictly in the past while still holding the copy: a stale
// 'da_ritirare' hold (dates elapsed, copy still 'prenotato', pickup never
// happened) — the same state as check O of the 301 suite. Pre-refactor this
// exact call committed a bare pending row that approval rejects (D.28).
echo "D. POST /user/loan: only copy under a stale preceding hold → reservation\n";
$loanBookId = $makeBook(1);
$loanCopyId = $copyIdsForBook($loanBookId)[0];
$staleStart = (new DateTimeImmutable($today))->modify('-5 days')->format('Y-m-d');
$staleEnd = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
$staleHoldId = $makeCommitment($loanBookId, $loanCopyId, $makeUser(), $staleStart, $staleEnd, 'da_ritirare', 'prenotato');
$loanRequester = $makeUser();
$loanResult = $callLoan($loanBookId, $loanRequester);
$check($loanResult['status'] === 302, '12 loan() keeps its redirect-based response style');
$check(str_contains($loanResult['location'], 'reserve_success=1'), '13 redirect reports the waitlist outcome (reserve_success), not a loan request');
$check(!str_contains($loanResult['location'], 'loan_error='), '14 redirect carries no loan error');
$loanReqRow = $reservationForUser($loanBookId, $loanRequester);
$check(
    $loanReqRow !== null && $loanReqRow['stato'] === 'attiva' && (int) $loanReqRow['queue_position'] === 1,
    '15 POST /user/loan persisted a real prenotazioni.attiva row'
);
$check($barePendingCount($loanBookId, $loanRequester) === 0, '16 NO bare prestiti.pendente with copia_id NULL was created (the #384 landmine)');
$anyLoanStmt = $db->prepare('SELECT COUNT(*) AS n FROM prestiti WHERE libro_id = ? AND utente_id = ?');
$anyLoanStmt->bind_param('ii', $loanBookId, $loanRequester);
$anyLoanStmt->execute();
$anyLoanCount = (int) ($anyLoanStmt->get_result()->fetch_assoc()['n'] ?? 0);
$anyLoanStmt->close();
$check($anyLoanCount === 0, '17 no prestiti row of any kind bypassed the queue');
$holdStmt = $db->prepare('SELECT stato, copia_id FROM prestiti WHERE id = ?');
$holdStmt->bind_param('i', $staleHoldId);
$holdStmt->execute();
$holdRow = $holdStmt->get_result()->fetch_assoc();
$holdStmt->close();
$check(
    $holdRow !== null && $holdRow['stato'] === 'da_ritirare' && (int) $holdRow['copia_id'] === $loanCopyId,
    '18 the preceding hold remains untouched'
);

// ── E. POST /user/loan negative control: free copy → pending loan request ───
echo "E. POST /user/loan negative control: free copy keeps the pending-loan flow\n";
$freeBookId = $makeBook(1);
$freeRequester = $makeUser();
$freeResult = $callLoan($freeBookId, $freeRequester);
$check($freeResult['status'] === 302 && str_contains($freeResult['location'], 'loan_request_success=1'), '19 free copy yields the normal loan-request success redirect');
$check($barePendingCount($freeBookId, $freeRequester) === 1, '20 the pending request row exists (auto-approve OFF leaves it bare, copy is genuinely free)');
$check($reservationForUser($freeBookId, $freeRequester) === null, '21 no reservation row was created for a servable request');

// ── F. I6: legacy aggregate-only book keeps the bare-pending fallback ───────
echo "F. I6: legacy book without copie rows keeps the bare-pending fallback\n";
$legacyBookId = $makeBook(0);
$db->query("UPDATE libri SET copie_totali = 1, copie_disponibili = 1 WHERE id = {$legacyBookId}");
$legacyRequester = $makeUser();
$legacyResult = $callLoan($legacyBookId, $legacyRequester);
$check($legacyResult['status'] === 302 && str_contains($legacyResult['location'], 'loan_request_success=1'), '22 legacy copyless book still accepts the loan request');
$check($barePendingCount($legacyBookId, $legacyRequester) === 1, '23 legacy fallback persists the bare pending row (promotion could never bind a copy)');
$check($reservationForUser($legacyBookId, $legacyRequester) === null, '24 legacy copyless book is NOT routed to the waitlist');

// ── G. POST /user/loan with auto-approve ON claims before commit ────────
echo "G. POST /user/loan auto-approve: selected copy is durably claimed\n";
$settings->set('loans', 'auto_approve_requests', '1');
$autoBookId = $makeBook(1);
$autoCopyId = $copyIdsForBook($autoBookId)[0];
$autoRequester = $makeUser();
$autoResult = $callLoan($autoBookId, $autoRequester);
$autoLoan = $latestLoanForUser($autoBookId, $autoRequester);
$check(
    $autoResult['status'] === 302
        && str_contains($autoResult['location'], 'loan_request_success=1')
        && str_contains($autoResult['location'], 'auto_approved=1'),
    '25 auto-approved POST /user/loan reports the successful approval'
);
$check(
    $autoLoan !== null
        && (int) $autoLoan['copia_id'] === $autoCopyId
        && (int) $autoLoan['attivo'] === 1
        && $autoLoan['stato'] === 'da_ritirare',
    '26 automatic flow keeps the gate-selected copy and activates the loan'
);
$check($reservationForUser($autoBookId, $autoRequester) === null, '27 auto-approved servable request creates no waitlist row');

// ── H. Mobile API preserves the canonical #384 outcome ────────────────────
echo "H. Mobile API: canonical loan→waitlist routing remains a 201 reservation\n";
$mobileBookId = $makeBook(1);
$mobileCopyId = $copyIdsForBook($mobileBookId)[0];
$makeCommitment($mobileBookId, $mobileCopyId, $makeUser(), $staleStart, $staleEnd, 'da_ritirare', 'prenotato');
$mobileRequester = $makeUser();
$_SESSION['user'] = ['id' => $mobileRequester, 'tipo_utente' => 'standard'];
$mobileRequest = (new ServerRequestFactory())
    ->createServerRequest('POST', '/api/v1/reservations')
    ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $mobileRequester])
    ->withParsedBody(['book_id' => $mobileBookId, 'desired_date' => $today]);
$mobileResponse = (new MobileActionsController($db))
    ->requestReservation($mobileRequest, new SlimResponse());
unset($_SESSION['user']);
$mobileBody = json_decode((string) $mobileResponse->getBody(), true);
$check(
    $mobileResponse->getStatusCode() === 201
        && ($mobileBody['error'] ?? null) === null
        && ($mobileBody['data']['type'] ?? null) === 'reservation',
    '27a Mobile API reports the committed gate outcome as HTTP 201 data.type=reservation, never a false 500'
);
$check(
    $reservationForUser($mobileBookId, $mobileRequester) !== null
        && $barePendingCount($mobileBookId, $mobileRequester) === 0,
    '27b Mobile API leaves exactly the canonical waitlist shape behind'
);

// #381: the explicit loan route must cancel a ready-for-pickup loan without
// passing through the ambiguous prenotazioni/prestiti numeric id space.
$_SESSION['user'] = ['id' => $autoRequester, 'tipo_utente' => 'standard'];
$cancelLoanRequest = (new ServerRequestFactory())
    ->createServerRequest('DELETE', '/api/v1/loans/' . (int) $autoLoan['id'])
    ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $autoRequester]);
$cancelLoanResponse = (new MobileActionsController($db))
    ->cancelLoan($cancelLoanRequest, new SlimResponse(), (int) $autoLoan['id']);
unset($_SESSION['user']);
$cancelledLoanState = (string) ($db->query('SELECT stato FROM prestiti WHERE id = ' . (int) $autoLoan['id'])->fetch_row()[0] ?? '');
$check(
    $cancelLoanResponse->getStatusCode() === 200 && $cancelledLoanState === 'annullato',
    '27c Mobile DELETE /loans/{id} cancels the owned da_ritirare loan (#381)'
);

// ── I. NCIP RequestItem shares #384 routing and cancellation ───────────────
echo "I. NCIP RequestItem: held copy becomes a linked, cancellable reservation\n";
$check($ncipSchemaResult['failed'] === [], '28 NCIP schema exposes the reservation audit relation');

$ncipBookId = $makeBook(1);
$ncipCopyId = $copyIdsForBook($ncipBookId)[0];
$makeCommitment($ncipBookId, $ncipCopyId, $makeUser(), $staleStart, $staleEnd, 'da_ritirare', 'prenotato');
$ncipRequester = $makeUser();
$ncipFailure = 'db_error';
$createNcip = new ReflectionMethod(NcipServerPlugin::class, 'createRequestItemNcip');
$ncipOutcome = $createNcip->invokeArgs($ncip, [
    $ncipBookId,
    $ncipRequester,
    $reqEnd,
    'GATE384-' . $run,
    &$ncipFailure,
]);
$ncipReservationId = (int) ($ncipOutcome['reservation_id'] ?? 0);
$check(
    $ncipReservationId > 0 && ($ncipOutcome['loan_id'] ?? null) === null,
    '29 NCIP RequestItem routes the preceding-return case to the real waitlist'
);
$check($barePendingCount($ncipBookId, $ncipRequester) === 0, '30 NCIP creates no unapprovable bare pending loan');
$linkedRequest = (int) $db->query(
    "SELECT COUNT(*) FROM ncip_transactions
      WHERE message_type='RequestItem' AND prenotazione_id={$ncipReservationId}
        AND prestito_id IS NULL AND status='success'"
)->fetch_row()[0];
$check($linkedRequest === 1, '31 NCIP audit links RequestItem to its reservation');

// A non-NCIP sibling must not be mistaken for the protocol-owned request. It
// remains active and is compacted to queue position 1 after NCIP cancellation.
$followerUser = $makeUser();
$followerPosition = 2;
$followerStmt = $db->prepare(
    "INSERT INTO prenotazioni
        (libro_id, utente_id, queue_position, stato, data_inizio_richiesta, data_fine_richiesta)
     VALUES (?, ?, ?, 'attiva', ?, ?)"
);
$followerStmt->bind_param('iiiss', $ncipBookId, $followerUser, $followerPosition, $today, $reqEnd);
$followerStmt->execute();
$followerReservationId = (int) $db->insert_id;
$followerStmt->close();

$cancelNcip = new ReflectionMethod(NcipServerPlugin::class, 'cancelPendingNcipRequest');
$cancelOutcome = $cancelNcip->invoke($ncip, $ncipBookId, $ncipRequester);
$check(
    ($cancelOutcome['status'] ?? '') === 'cancelled'
        && (int) ($cancelOutcome['reservation_id'] ?? 0) === $ncipReservationId
        && ($cancelOutcome['loan_id'] ?? null) === null,
    '32 CancelRequestItem resolves and cancels the linked reservation'
);
$ncipState = (string) $db->query("SELECT stato FROM prenotazioni WHERE id={$ncipReservationId}")->fetch_row()[0];
$check($ncipState === 'annullata', '33 the NCIP reservation reaches the canonical cancelled state');
$follower = $db->query("SELECT stato, queue_position FROM prenotazioni WHERE id={$followerReservationId}")->fetch_assoc();
$check(
    ($follower['stato'] ?? '') === 'attiva' && (int) ($follower['queue_position'] ?? 0) === 1,
    '34 cancellation preserves the unrelated reservation and compacts FIFO positions'
);
$linkedCancel = (int) $db->query(
    "SELECT COUNT(*) FROM ncip_transactions
      WHERE message_type='CancelRequestItem' AND prenotazione_id={$ncipReservationId}
        AND prestito_id IS NULL AND status='success'"
)->fetch_row()[0];
$check($linkedCancel === 1, '35 NCIP audit links the cancellation to the same reservation');

$cleanup();
$smtpProbe->setValue(null, $originalSmtpReachable);
$db->close();
echo "\n{$pass} checks passed\n";
exit(0);

<?php
declare(strict_types=1);

/**
 * Reservation system: every surface that refuses a window for lack of a free
 * copy answers with the same LoanWindow figures, and the edge cases around
 * them.
 *
 * Companion of loan-window-cap.unit.php (public calendar endpoint, user-area
 * forms). Covered here, against the real database with a throwaway sandbox:
 *   - the admin loan form (PrestitiController::store), the admin edit of a
 *     loan's dates (update), the admin approval of a pending request
 *     (LoanApprovalController::approveLoan), the admin reservation form
 *     (ReservationsAdminController::store / update), a reader moving their own
 *     reservation (UserActionsController::changeReservationDate);
 *   - the Mobile API (POST /reservations) with and without end_date, and its
 *     error/meta contract for the app;
 *   - the availability payload the calendars read their durations from;
 *   - the edge case of a commitment starting the very next day (no loan of at
 *     least one day is possible: no figures, the generic refusal stays).
 *
 * Run: php tests/loan-window-surfaces.unit.php
 */

use App\Controllers\LoanApprovalController;
use App\Controllers\PrestitiController;
use App\Controllers\ReservationsAdminController;
use App\Controllers\ReservationsController;
use App\Controllers\UserActionsController;
use App\Models\SettingsRepository;
use App\Plugins\MobileApi\Controllers\ActionsController as MobileActionsController;
use App\Plugins\MobileApi\Controllers\CatalogController as MobileCatalogController;
use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Services\CapacityService;
use App\Support\DateHelper;
use App\Support\LoanWindow;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/AppAuthMiddleware.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/JsonBody.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/ResponseEnvelope.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/Input.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/CirculationOutcome.php';
require_once $root . '/storage/plugins/mobile-api/src/Controllers/ActionsController.php';
require_once $root . '/storage/plugins/mobile-api/src/Controllers/CatalogController.php';

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
    DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — loan window surfaces suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$run = bin2hex(random_bytes(5));
$prefix = "ZZ_LWSRF_{$run}";
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : "  [{$detail}]") . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

/** @var int[] $bookIds */
$bookIds = [];
/** @var int[] $userIds */
$userIds = [];
$savedLoanSettings = [];
$cleanup = static function () use ($db, &$bookIds, &$userIds, &$savedLoanSettings): void {
    $settings = new SettingsRepository($db);
    foreach ($savedLoanSettings as $key => $value) {
        if ($value === null) {
            $settings->delete('loans', $key);
        } else {
            $settings->set('loans', $key, $value);
        }
    }
    foreach ($bookIds as $id) {
        $db->query("DELETE FROM prestiti WHERE libro_id = {$id}");
        $db->query("DELETE FROM prenotazioni WHERE libro_id = {$id}");
        $db->query("DELETE FROM copie WHERE libro_id = {$id}");
        $db->query("DELETE FROM libri WHERE id = {$id}");
    }
    foreach ($userIds as $id) {
        $db->query("DELETE FROM utenti WHERE id = {$id}");
    }
};
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, 'FAIL: uncaught ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
    $db->close();
    exit(1);
});

// ── Sandbox ──────────────────────────────────────────────────────────────────
$mkUser = static function (int $n, string $tipo = 'standard') use ($db, $prefix, $run, &$userIds): int {
    // codice_tessera is a short column: a run tag plus the sequence fits it.
    $card = 'LW' . substr($run, 0, 8) . $n;
    $email = strtolower("{$prefix}-u{$n}@example.invalid");
    $cognome = "{$prefix} {$n}";
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata)
                          VALUES (?, 'LoanWindow', ?, ?, 'x', 'attivo', ?, 1)");
    $stmt->bind_param('ssss', $card, $cognome, $email, $tipo);
    $stmt->execute();
    $stmt->close();
    $userIds[] = (int) $db->insert_id;
    return (int) $db->insert_id;
};
$mkBook = static function (int $copies, int $n) use ($db, $prefix, &$bookIds): array {
    $title = "{$prefix} book {$n}";
    $stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
    $stmt->bind_param('sii', $title, $copies, $copies);
    $stmt->execute();
    $stmt->close();
    $bookId = (int) $db->insert_id;
    $bookIds[] = $bookId;
    $copyIds = [];
    for ($i = 1; $i <= $copies; $i++) {
        $code = "{$prefix}-{$bookId}-C{$i}";
        $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
        $stmt->bind_param('is', $bookId, $code);
        $stmt->execute();
        $stmt->close();
        $copyIds[] = (int) $db->insert_id;
    }
    return [$bookId, $copyIds];
};
$mkLoan = static function (int $bookId, ?int $copyId, int $userId, string $stato, int $attivo, string $from, string $to) use ($db): int {
    $stmt = $db->prepare("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, warning_sent, overdue_notification_sent)
                          VALUES (?, ?, ?, ?, ?, ?, 'diretto', ?, 0, 0)");
    $stmt->bind_param('iiisssi', $bookId, $copyId, $userId, $from, $to, $stato, $attivo);
    $stmt->execute();
    $stmt->close();
    return (int) $db->insert_id;
};
$addDays = static fn (string $ymd, int $days): string => (new DateTimeImmutable($ymd))->modify(($days >= 0 ? '+' : '') . "{$days} days")->format('Y-m-d');
$redirectParams = static function ($response): array {
    $query = parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY);
    $params = [];
    parse_str(is_string($query) ? $query : '', $params);
    return $params;
};
$jsonBody = static function ($response): array {
    $decoded = json_decode((string) $response->getBody(), true);
    return is_array($decoded) ? $decoded : [];
};
/** Drop what a request wrote for one patron on one title, so later checks start clean. */
$resetPatron = static function (int $bookId, int $userId, array $copyIds) use ($db): void {
    $db->query("DELETE FROM prestiti WHERE libro_id = {$bookId} AND utente_id = {$userId}");
    $db->query("DELETE FROM prenotazioni WHERE libro_id = {$bookId} AND utente_id = {$userId}");
    foreach ($copyIds as $copyId) {
        $db->query("UPDATE copie SET stato = 'disponibile' WHERE id = {$copyId}");
    }
};

$settings = new SettingsRepository($db);
// The scenario needs a default longer than the conflict offset: pin the two
// durations for the run (the installation's values are restored by $cleanup).
foreach (['loan_duration_days', 'max_loan_duration_days'] as $key) {
    $savedLoanSettings[$key] = $settings->get('loans', $key);
}
$settings->set('loans', 'loan_duration_days', '30');
$settings->set('loans', 'max_loan_duration_days', '90');
$maxDays = max(1, (int) ($settings->get('loans', 'max_loan_duration_days', '90') ?? 90));
$defaultDays = min($settings->loanDurationDays(), $maxDays);
$offset = max(2, min(5, $defaultDays - 1));
$today = DateHelper::today();
$conflictStart = $addDays($today, $offset);
$conflictEnd = $addDays($today, $offset + 14);
$expectedMaxEnd = $addDays($today, $offset - 1);
$expectedMaxDays = $offset - 1;

$otherUser = $mkUser(1);
$requester = $mkUser(2);
$admin = $mkUser(3, 'admin');
[$book, $copies] = $mkBook(1, 1);
$mkLoan($book, $copies[0], $otherUser, 'prenotato', 1, $conflictStart, $conflictEnd);
echo "Sandbox: today {$today}, scheduled loan {$conflictStart}..{$conflictEnd}, default {$defaultDays} days, max {$maxDays} days" . PHP_EOL;

$asAdmin = static function () use ($admin): void {
    $_SESSION['user'] = ['tipo_utente' => 'admin', 'id' => $admin];
};
$asRequester = static function () use ($requester, $book): void {
    $_SESSION['user'] = ['tipo_utente' => 'standard', 'id' => $requester];
    $_SERVER['HTTP_REFERER'] = "/libro/{$book}";
};
$post = static fn (string $uri, array $body) => (new ServerRequestFactory())->createServerRequest('POST', $uri)->withParsedBody($body);

// ═════════ 1: the admin loan form ════════════════════════════════════════════
$asAdmin();
$response = (new PrestitiController())->store($post('/admin/loans/store', [
    'loan_submission_token' => \App\Support\OneTimeFormToken::issue('loan.create'),
    'utente_id' => (string) $requester,
    'libro_id' => (string) $book,
    'data_prestito' => $today,
    'data_scadenza' => $addDays($today, $offset + 2),
]), new SlimResponse(), $db);
$params = $redirectParams($response);
$check(($params['error'] ?? null) === 'no_copies_available'
    && ($params['max_end_date'] ?? null) === $expectedMaxEnd
    && (int) ($params['max_loan_days'] ?? -1) === $expectedMaxDays
    && ($params['blocked_from'] ?? null) === $conflictStart,
    "1 admin create loan crossing the scheduled loan: no_copies_available with max_end_date {$expectedMaxEnd}, max_loan_days {$expectedMaxDays}, blocked_from {$conflictStart}",
    $response->getHeaderLine('Location'));
$check(LoanWindow::explainFromParams($params) !== null
    && str_contains((string) LoanWindow::explainFromParams($params), (string) $expectedMaxDays),
    '2 the admin form renders those parameters as the maximum-duration sentence');

// ═════════ 3: editing a loan's dates across the scheduled loan ═══════════════
$ownLoan = $mkLoan($book, $copies[0], $requester, 'in_corso', 1, $addDays($today, -2), $addDays($today, 1));
$db->query("UPDATE copie SET stato = 'prestato' WHERE id = {$copies[0]}");
$asAdmin();
$response = (new PrestitiController())->update($post("/admin/loans/update/{$ownLoan}", [
    'utente_id' => (string) $requester,
    'data_prestito' => $addDays($today, -2),
    'data_scadenza' => $addDays($today, $offset + 2),
]), new SlimResponse(), $db, $ownLoan);
$params = $redirectParams($response);
// Only the added days are checked, but the figures describe the whole loan
// from its own start (today - 2), and the loan being edited is never its own conflict.
$loanStart = $addDays($today, -2);
$check(($params['error'] ?? null) === 'no_copies_available'
    && ($params['max_end_date'] ?? null) === $expectedMaxEnd
    && ($params['blocked_from'] ?? null) === $conflictStart
    && (int) ($params['max_loan_days'] ?? -1) === (int) (new DateTimeImmutable($loanStart))->diff(new DateTimeImmutable($expectedMaxEnd))->days,
    '3 admin edit of a loan due date across the scheduled loan: explained from the loan\'s own start, own loan excluded',
    $response->getHeaderLine('Location'));
$check(str_contains((string) LoanWindow::explainFromParams($params), (string) ((int) (new DateTimeImmutable($loanStart))->diff(new DateTimeImmutable($expectedMaxEnd))->days)),
    '3b the loans list sentence names that whole-loan duration');
$resetPatron($book, $requester, $copies);

// ═════════ 4: approving a pending request ════════════════════════════════════
$pending = $mkLoan($book, null, $requester, 'pendente', 0, $today, $addDays($today, $defaultDays));
$asAdmin();
$response = (new LoanApprovalController())->approveLoan($post('/admin/loans/approve', ['loan_id' => (string) $pending]), new SlimResponse(), $db);
$body = $jsonBody($response);
$check($response->getStatusCode() === 400
    && ($body['success'] ?? true) === false
    && ($body['max_end_date'] ?? null) === $expectedMaxEnd
    && ($body['max_duration_days'] ?? null) === $expectedMaxDays
    && str_contains((string) ($body['message'] ?? ''), (string) $expectedMaxDays),
    '4 approving a pending request whose default window crosses the scheduled loan: 400 JSON with the figures and the sentence',
    json_encode($body));
$resetPatron($book, $requester, $copies);

// ═════════ 5-7: the admin reservation form, create then edit ═════════════════
$asAdmin();
$response = (new ReservationsAdminController())->store($post('/admin/reservations/store', [
    'libro_id' => (string) $book,
    'utente_id' => (string) $requester,
    'data_prenotazione' => $today,
    'data_scadenza' => $addDays($today, $offset + 2),
    'data_inizio_richiesta' => $today,
    'data_fine_richiesta' => $addDays($today, $offset + 2),
]), new SlimResponse(), $db);
$params = $redirectParams($response);
$check(($params['error'] ?? null) === 'capacity_full'
    && ($params['max_end_date'] ?? null) === $expectedMaxEnd
    && ($params['blocked_from'] ?? null) === $conflictStart,
    '5 admin reservation crossing the scheduled loan: capacity_full with the figures',
    $response->getHeaderLine('Location'));

$response = (new ReservationsAdminController())->store($post('/admin/reservations/store', [
    'libro_id' => (string) $book,
    'utente_id' => (string) $requester,
    'data_prenotazione' => $today,
    'data_scadenza' => $expectedMaxEnd,
    'data_inizio_richiesta' => $today,
    'data_fine_richiesta' => $expectedMaxEnd,
]), new SlimResponse(), $db);
$reservationRow = $db->query("SELECT id FROM prenotazioni WHERE libro_id = {$book} AND utente_id = {$requester} AND stato = 'attiva' ORDER BY id DESC LIMIT 1")->fetch_assoc();
$reservationId = (int) ($reservationRow['id'] ?? 0);
$check($reservationId > 0 && !isset($redirectParams($response)['error']),
    "6 the same reservation ending {$expectedMaxEnd} is created",
    $response->getHeaderLine('Location'));

$response = (new ReservationsAdminController())->update($post("/admin/reservations/update/{$reservationId}", [
    'stato' => 'attiva',
    'data_prenotazione' => $today,
    'data_scadenza_prenotazione' => $addDays($today, $offset + 2),
    'data_inizio_richiesta' => $today,
    'data_fine_richiesta' => $addDays($today, $offset + 2),
]), new SlimResponse(), $db, $reservationId);
$params = $redirectParams($response);
$check(($params['error'] ?? null) === 'capacity_full'
    && ($params['max_end_date'] ?? null) === $expectedMaxEnd
    && ($params['blocked_from'] ?? null) === $conflictStart,
    '7 extending that reservation across the scheduled loan: capacity_full with the figures, own row excluded (cap still from the start)',
    $response->getHeaderLine('Location'));

// ═════════ 8: a reader moving their own reservation ══════════════════════════
$asRequester();
$response = (new UserActionsController())->changeReservationDate($post('/reservation/change-date', [
    'reservation_id' => (string) $reservationId,
    'desired_date' => $today,
]), new SlimResponse(), $db);
$params = $redirectParams($response);
$check(($params['error'] ?? null) === 'not_available'
    && ($params['blocked_from'] ?? null) === $conflictStart
    && ($params['max_end_date'] ?? null) === $expectedMaxEnd,
    '8 moving one\'s own reservation to a start whose default window crosses the scheduled loan: not_available with the figures',
    $response->getHeaderLine('Location'));
$resetPatron($book, $requester, $copies);

// ═════════ 9-12: the Mobile API contract ═════════════════════════════════════
$mobile = static function (array $body) use ($db, $requester, $book, $asRequester, $jsonBody): array {
    $asRequester();
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/api/v1/reservations')
        ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $requester])
        ->withParsedBody(['book_id' => $book] + $body);
    $response = (new MobileActionsController($db))->requestReservation($request, new SlimResponse());
    return ['status' => $response->getStatusCode(), 'body' => $jsonBody($response)];
};

$r = $mobile(['desired_date' => $today]);
$check($r['status'] === 409
    && ($r['body']['error']['code'] ?? null) === 'not_available'
    && ($r['body']['meta']['max_end_date'] ?? null) === $expectedMaxEnd
    && ($r['body']['meta']['max_duration_days'] ?? null) === $expectedMaxDays
    && ($r['body']['meta']['first_conflict_date'] ?? null) === $conflictStart
    && str_contains((string) ($r['body']['error']['message'] ?? ''), (string) $expectedMaxDays),
    '9 Mobile POST /reservations without end_date: 409 not_available, the sentence as error.message, the figures in meta',
    json_encode($r));

$r = $mobile(['desired_date' => $today, 'end_date' => $expectedMaxEnd]);
$check($r['status'] === 201 && in_array($r['body']['data']['type'] ?? null, ['loan', 'reservation'], true),
    "10 Mobile POST /reservations with end_date {$expectedMaxEnd}: 201, the request is accepted",
    json_encode($r));
$resetPatron($book, $requester, $copies);

$r = $mobile(['desired_date' => $today, 'end_date' => $addDays($today, $maxDays + 1)]);
$check($r['status'] === 422 && ($r['body']['error']['code'] ?? null) === 'max_duration_exceeded',
    '11 Mobile end_date beyond max_loan_duration_days: 422 max_duration_exceeded',
    json_encode($r));

$r = $mobile(['desired_date' => $today, 'end_date' => $addDays($today, -1)]);
$check($r['status'] === 422 && ($r['body']['error']['code'] ?? null) === 'invalid_date',
    '12 Mobile end_date before the start: 422 invalid_date',
    json_encode($r));

// ═════════ 13-14: the durations every calendar reads ═════════════════════════
$availability = (new ReservationsController($db))->getBookAvailabilityData($book, null, 10, $requester);
$check(is_array($availability)
    && ($availability['loan_duration_days'] ?? null) === $defaultDays
    && ($availability['max_loan_duration_days'] ?? null) === $maxDays,
    "13 the availability payload carries loan_duration_days ({$defaultDays}, capped by the maximum) and max_loan_duration_days ({$maxDays})",
    json_encode([$availability['loan_duration_days'] ?? null, $availability['max_loan_duration_days'] ?? null]));

// The HTTP route the calendars fetch whitelists the payload keys: the two
// durations must be among them, or the forms silently keep their rendered
// default (the controller method alone cannot catch that).
$routeSrc = (string) file_get_contents($root . '/app/Routes/web.php');
$routeAt = strpos($routeSrc, "/{id:\\d+}/availability', function");
$routeSlice = $routeAt === false ? '' : substr($routeSrc, $routeAt, 3000);
$check($routeSlice !== ''
    && str_contains($routeSlice, "'loan_duration_days' => \$availability['loan_duration_days']")
    && str_contains($routeSlice, "'max_loan_duration_days' => \$availability['max_loan_duration_days']"),
    '13b the localized availability route passes both durations through to the calendars');

$blocked = (new CapacityService($db))->unavailableDatesInRange($book, $today, $addDays($today, $defaultDays), $requester);
$check(LoanWindow::redirectParams($today, $blocked) === ['max_loan_days' => $expectedMaxDays, 'max_end_date' => $expectedMaxEnd, 'blocked_from' => $conflictStart],
    '14 LoanWindow::redirectParams carries exactly the three figures every redirect uses');

// ═════════ 15-17: a commitment starting the very next day ════════════════════
[$tightBook, $tightCopies] = $mkBook(1, 2);
$mkLoan($tightBook, $tightCopies[0], $otherUser, 'prenotato', 1, $addDays($today, 1), $addDays($today, 10));
$check(LoanWindow::cap($today, [$addDays($today, 1)]) === null,
    '15 LoanWindow::cap: a conflict on the next day leaves no loan of at least one day, so no figures');

$asRequester();
$request = $post("/api/libro/{$tightBook}/reservation", ['start_date' => $today]);
$response = (new ReservationsController($db))->createReservation($request, new SlimResponse(), ['id' => (string) $tightBook]);
$body = $jsonBody($response);
$check($response->getStatusCode() === 400
    && ($body['error'] ?? null) === 'no_capacity'
    && !array_key_exists('max_end_date', $body)
    && ($body['message'] ?? '') === __('Nessuna copia disponibile nelle date richieste'),
    '16 public request from today with the next day booked: generic refusal, no misleading "0 days" figure',
    json_encode($body));

$r = $mobile(['desired_date' => $today]);
$asRequester();
$request = (new ServerRequestFactory())
    ->createServerRequest('POST', '/api/v1/reservations')
    ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $requester])
    ->withParsedBody(['book_id' => $tightBook, 'desired_date' => $today]);
$response = (new MobileActionsController($db))->requestReservation($request, new SlimResponse());
$body = $jsonBody($response);
$check($response->getStatusCode() === 409
    && ($body['error']['code'] ?? null) === 'not_available'
    && !isset($body['meta']['max_end_date']),
    '17 Mobile request on that title: 409 not_available with no figures in meta',
    json_encode($body));

// A lower configured maximum must govern every default, including Mobile API
// requests without end_date. A commitment at +10 must not reject a valid 7-day
// request just because the configured standard duration is 30 days.
// The installation's values were saved at the top; $cleanup restores them.
$settings->set('loans', 'loan_duration_days', '30');
$settings->set('loans', 'max_loan_duration_days', '7');
[$boundedBook, $boundedCopies] = $mkBook(1, 3);
$boundedScheduledLoan = $mkLoan($boundedBook, $boundedCopies[0], $otherUser, 'prenotato', 1, $addDays($today, 10), $addDays($today, 25));
$expectedDue = $addDays($today, 7);
$createdDue = static function () use ($db, $boundedBook, $requester): ?string {
    $row = $db->query("SELECT data_scadenza AS due FROM prestiti WHERE libro_id = {$boundedBook} AND utente_id = {$requester}
        UNION ALL SELECT data_fine_richiesta AS due FROM prenotazioni WHERE libro_id = {$boundedBook} AND utente_id = {$requester}")->fetch_assoc();
    return $row['due'] ?? null;
};
$availability = (new ReservationsController($db))->getBookAvailabilityData($boundedBook, null, 40, $requester);
$request = (new ServerRequestFactory())->createServerRequest('GET', "/api/v1/catalog/books/{$boundedBook}/availability")
    ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $requester]);
$response = (new MobileCatalogController($db))->bookAvailability($request, new SlimResponse(), $boundedBook);
$mobileAvailability = $jsonBody($response)['data'] ?? [];
$check(($availability['loan_duration_days'] ?? null) === 7
    && ($mobileAvailability['loan_duration_days'] ?? null) === 7
    && ($mobileAvailability['max_loan_duration_days'] ?? null) === 7,
    '18 web and Mobile availability advertise the same bounded 7-day default', json_encode($mobileAvailability));

foreach (['loan', 'reserve'] as $action) {
    $asRequester();
    $response = (new UserActionsController())->$action($post("/user/{$action}", ['libro_id' => $boundedBook]), new SlimResponse(), $db);
    $check(!isset($redirectParams($response)["{$action}_error"]) && $createdDue() === $expectedDue,
        "19 {$action} without end_date persists the configured maximum instead of the longer default", $response->getHeaderLine('Location'));
    $resetPatron($boundedBook, $requester, $boundedCopies);
}
$asRequester();
$request = $post("/api/libro/{$boundedBook}/reservation", ['start_date' => $today]);
$response = (new ReservationsController($db))->createReservation($request, new SlimResponse(), ['id' => (string) $boundedBook]);
$check(($jsonBody($response)['success'] ?? false) && $createdDue() === $expectedDue,
    '20 public calendar requests use the same bounded default');
$resetPatron($boundedBook, $requester, $boundedCopies);

foreach ([$today, $addDays($today, 1)] as $start) {
    $asRequester();
    $request = $post('/api/v1/reservations', ['book_id' => $boundedBook, 'desired_date' => $start])
        ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $requester]);
    $response = (new MobileActionsController($db))->requestReservation($request, new SlimResponse());
    $check($response->getStatusCode() === 201 && $createdDue() === $addDays($start, 7),
        "21 Mobile request from {$start} without end_date succeeds with the bounded default", (string) $response->getBody());
    $resetPatron($boundedBook, $requester, $boundedCopies);
}
$asRequester();
$request = $post('/api/v1/reservations', ['book_id' => $boundedBook, 'desired_date' => $today, 'end_date' => $addDays($today, 9)])
    ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $requester]);
$response = (new MobileActionsController($db))->requestReservation($request, new SlimResponse());
$check($response->getStatusCode() === 422 && ($jsonBody($response)['error']['code'] ?? '') === 'max_duration_exceeded' && $createdDue() === null,
    '22 an explicit return past the same maximum is still refused without writing a request');
foreach ([[], ['date' => $expectedDue], new stdClass()] as $invalidEnd) {
    $request = $post('/api/v1/reservations', ['book_id' => $boundedBook, 'desired_date' => $today, 'end_date' => $invalidEnd])
        ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $requester]);
    $response = (new MobileActionsController($db))->requestReservation($request, new SlimResponse());
    $check($response->getStatusCode() === 422 && ($jsonBody($response)['error']['code'] ?? '') === 'invalid_date' && $createdDue() === null,
        '22b a non-scalar end_date is refused instead of silently creating a default-duration request');
}

// The admin form and store use staff duration, not the capped reader default
// carried by the shared availability endpoint.
[$staffBook, $staffCopies] = $mkBook(1, 4);
$asAdmin();
$response = (new PrestitiController())->store($post('/admin/loans/store', [
    'loan_submission_token' => \App\Support\OneTimeFormToken::issue('loan.create'),
    'utente_id' => $requester, 'libro_id' => $staffBook, 'data_prestito' => $today,
]), new SlimResponse(), $db);
$staffDue = $db->query("SELECT data_scadenza FROM prestiti WHERE libro_id = {$staffBook} AND utente_id = {$requester}")->fetch_assoc();
$staffLoanView = (string) file_get_contents($root . '/app/Views/prestiti/crea_prestito.php');
$staffReservationView = (string) file_get_contents($root . '/app/Views/prenotazioni/crea_prenotazione.php');
$check(($staffDue['data_scadenza'] ?? '') === $addDays($today, 30)
    && !str_contains($staffLoanView, 'parseInt(data.availability.loan_duration_days')
    && !str_contains($staffReservationView, 'parseInt(data.availability.loan_duration_days'),
    '22c staff defaults retain the configured 30 days when reader requests are capped at 7', $response->getHeaderLine('Location'));
$resetPatron($staffBook, $requester, $staffCopies);

// Refusing a huge staff range must not build millions of calendar days.
$capacity = new CapacityService($db);
$check($capacity->firstUnavailableDateInRange($boundedBook, $today, '9999-12-31') === $addDays($today, 10)
    && $capacity->firstUnavailableDateInRange($boundedBook, $today, '9999-12-31', null, $boundedScheduledLoan) === null
    && $capacity->firstUnavailableDateInRange($boundedBook, $addDays($today, 30), $today) === null,
    '22d first-conflict lookup handles huge ranges, exclusions and inverted windows without enumerating dates');
$asAdmin();
$response = (new PrestitiController())->store($post('/admin/loans/store', [
    'loan_submission_token' => \App\Support\OneTimeFormToken::issue('loan.create'),
    'utente_id' => $requester, 'libro_id' => $boundedBook,
    'data_prestito' => $today, 'data_scadenza' => '9999-12-31',
]), new SlimResponse(), $db);
$params = $redirectParams($response);
$check(($params['error'] ?? '') === 'no_copies_available' && ($params['max_end_date'] ?? '') === $addDays($today, 9),
    '22e staff refusal of a range ending in year 9999 reports the same first conflict', $response->getHeaderLine('Location'));
[$adjacentBook, $adjacentCopies] = $mkBook(2, 5);
$mkLoan($adjacentBook, $adjacentCopies[0], $otherUser, 'prenotato', 1, $addDays($today, 5), $addDays($today, 15));
$mkLoan($adjacentBook, $adjacentCopies[1], $requester, 'prenotato', 1, $addDays($today, 1), $addDays($today, 4));
$check($capacity->firstUnavailableDateInRange($adjacentBook, $today, '9999-12-31') === null,
    '22f adjacent commitments on different copies do not create a false full day');
$overlapLoan = $mkLoan($adjacentBook, $adjacentCopies[1], $admin, 'prenotato', 1, $addDays($today, 10), $addDays($today, 12));
$allBlocked = $capacity->unavailableDatesInRange($adjacentBook, $today, $addDays($today, 20));
$check($capacity->firstUnavailableDateInRange($adjacentBook, $today, '9999-12-31') === ($allBlocked[0] ?? null)
    && $capacity->firstUnavailableDateInRange($adjacentBook, $today, '9999-12-31', null, $overlapLoan) === null,
    '22g first-conflict sweep agrees with per-day capacity and honors loan exclusions');
[$zeroBook] = $mkBook(0, 6);
$check($capacity->firstUnavailableDateInRange($zeroBook, $today, '9999-12-31') === $today,
    '22h zero lendable copies block the start without building a huge date list');
$settings->set('loans', 'loan_duration_days', '0');
$settings->set('loans', 'max_loan_duration_days', '0');
$check($settings->maxLoanDurationDays() === 90 && $settings->requestLoanDurationDays() === 30,
    '23 invalid durations retain the existing 30-day default and 90-day maximum fallbacks');

$cleanup();
$db->close();

echo PHP_EOL . "Passed: {$passed}   Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

<?php
declare(strict_types=1);

/**
 * Reservation system: the loan window capped by a scheduled loan.
 *
 * When a scheduled loan (or a reservation on every copy) begins before the
 * default return date, every request surface must say how long the loan can
 * still be instead of a bare "no copies available": the public calendar
 * endpoint (ReservationsController::createReservation), the user-area loan
 * and reservation forms (UserActionsController::loan()/reserve(), which the
 * Mobile API delegates to), and the figures the LoanWindow helper derives
 * for all of them from CapacityService.
 *
 * Behavioral against the real database with a throwaway sandbox: one
 * single-copy title with another patron's scheduled loan starting a few days
 * out, one two-copy title with the same loan on one copy, two patrons.
 *
 * Run: php tests/loan-window-cap.unit.php
 */

use App\Controllers\ReservationsController;
use App\Controllers\UserActionsController;
use App\Models\SettingsRepository;
use App\Services\CapacityService;
use App\Support\DateHelper;
use App\Support\LoanWindow;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

require dirname(__DIR__) . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$root = dirname(__DIR__);
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
    fwrite(STDERR, "FAIL: database unreachable — loan window suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$run = bin2hex(random_bytes(5));
$prefix = "ZZ_LWCAP_{$run}";
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
/** @var array<string, string|null> $savedLoanSettings */
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
$mkUser = static function (int $n) use ($db, $prefix, $run, &$userIds): int {
    // codice_tessera is a short column: a run tag plus the sequence fits it.
    $card = 'LW' . substr($run, 0, 8) . $n;
    $email = strtolower("{$prefix}-u{$n}@example.invalid");
    $cognome = "{$prefix} {$n}";
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata)
                          VALUES (?, 'LoanWindow', ?, ?, 'x', 'attivo', 'standard', 1)");
    $stmt->bind_param('sss', $card, $cognome, $email);
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
$mkScheduledLoan = static function (int $bookId, int $copyId, int $userId, string $from, string $to) use ($db): int {
    $stmt = $db->prepare("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, warning_sent, overdue_notification_sent)
                          VALUES (?, ?, ?, ?, ?, 'prenotato', 'diretto', 1, 0, 0)");
    $stmt->bind_param('iiiss', $bookId, $copyId, $userId, $from, $to);
    $stmt->execute();
    $stmt->close();
    return (int) $db->insert_id;
};
$addDays = static fn (string $ymd, int $days): string => (new DateTimeImmutable($ymd))->modify(($days >= 0 ? '+' : '') . "{$days} days")->format('Y-m-d');
/** Drop what a successful request wrote for one patron on one title, so later checks start clean. */
$resetRequester = static function (int $bookId, int $requesterId, array $copyIds) use ($db): void {
    $db->query("DELETE FROM prestiti WHERE libro_id = {$bookId} AND utente_id = {$requesterId}");
    $db->query("DELETE FROM prenotazioni WHERE libro_id = {$bookId} AND utente_id = {$requesterId}");
    // The sandbox's scheduled loans were inserted directly and never changed a
    // copy's state, so every copy is physically 'disponibile' again here.
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
$defaultDays = $settings->loanDurationDays();
$maxDays = max(1, (int) ($settings->get('loans', 'max_loan_duration_days', '90') ?? 90));
$defaultDays = min($defaultDays, $maxDays);
// The other patron's loan starts inside the default duration, so it must
// shorten the proposed loan to offset - 1 days.
$offset = max(1, min(5, $defaultDays - 1));
$today = DateHelper::today();
$conflictStart = $addDays($today, $offset);
$conflictEnd = $addDays($today, $offset + 14);
$expectedMaxEnd = $addDays($today, $offset - 1);
$expectedMaxDays = $offset - 1;

$otherUser = $mkUser(1);
$requester = $mkUser(2);
[$singleBook, $singleCopies] = $mkBook(1, 1);
[$doubleBook, $doubleCopies] = $mkBook(2, 2);
$mkScheduledLoan($singleBook, $singleCopies[0], $otherUser, $conflictStart, $conflictEnd);
$mkScheduledLoan($doubleBook, $doubleCopies[0], $otherUser, $conflictStart, $conflictEnd);
echo "Sandbox: today {$today}, scheduled loan {$conflictStart}..{$conflictEnd}, default {$defaultDays} days, max {$maxDays} days" . PHP_EOL;

$requestCalendar = static function (int $bookId, array $body) use ($db, $requester): array {
    $_SESSION['user'] = ['tipo_utente' => 'standard', 'id' => $requester];
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', "/api/libro/{$bookId}/reservation")
        ->withParsedBody($body);
    $response = (new ReservationsController($db))->createReservation($request, new SlimResponse(), ['id' => (string) $bookId]);
    $decoded = json_decode((string) $response->getBody(), true);
    return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
};
$userAction = static function (string $method, int $bookId, array $body) use ($db, $requester): array {
    $_SESSION['user'] = ['tipo_utente' => 'standard', 'id' => $requester];
    $_SERVER['HTTP_REFERER'] = "/libro/{$bookId}";
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', "/user/{$method}")
        ->withParsedBody(['libro_id' => $bookId] + $body);
    $response = (new UserActionsController())->{$method}($request, new SlimResponse(), $db);
    $query = parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY);
    $params = [];
    parse_str(is_string($query) ? $query : '', $params);
    return $params;
};

// ═════════ 1-3: the capacity service and the helper agree on the window ══════
$capacity = new CapacityService($db);
$blocked = $capacity->unavailableDatesInRange($singleBook, $today, $addDays($today, $defaultDays), $requester);
$check($blocked !== [] && min($blocked) === $conflictStart,
    '1 CapacityService reports the scheduled loan\'s first day as the first blocked day',
    json_encode($blocked));

$cap = LoanWindow::cap($today, $blocked);
$check($cap !== null
    && $cap['first_conflict_date'] === $conflictStart
    && $cap['max_end_date'] === $expectedMaxEnd
    && $cap['max_duration_days'] === $expectedMaxDays,
    "2 LoanWindow::cap bounds the loan to {$expectedMaxDays} days ending {$expectedMaxEnd}",
    json_encode($cap));

$check(LoanWindow::cap($conflictStart, $blocked) === null && LoanWindow::cap($today, []) === null,
    '3 LoanWindow::cap reports no cap when the start day is blocked or nothing is blocked');

// ═════════ 4: the calendar data the pickers colour ═══════════════════════════
$availability = (new ReservationsController($db))->getBookAvailabilityData($singleBook, null, $offset + 20, $requester);
$byDate = is_array($availability) ? array_column($availability['days'] ?? [], null, 'date') : [];
$check(($byDate[$expectedMaxEnd]['state'] ?? null) === 'free'
    && ($byDate[$conflictStart]['state'] ?? null) === 'borrowed'
    && in_array($conflictStart, $availability['unavailable_dates'] ?? [], true),
    '4 availability payload: free the day before the scheduled loan, borrowed from its first day',
    json_encode([$byDate[$expectedMaxEnd] ?? null, $byDate[$conflictStart] ?? null]));

// A start on the last offered day still has occupancy data for its whole loan
// window: the public route asks for it, the default call is unchanged.
$plain = (new ReservationsController($db))->getBookAvailabilityData($singleBook, $today, 180, $requester);
$covered = (new ReservationsController($db))->getBookAvailabilityData($singleBook, $today, 180, $requester, true);
$lastPlain = is_array($plain) ? (string) (end($plain['days'])['date'] ?? '') : '';
$lastCovered = is_array($covered) ? (string) (end($covered['days'])['date'] ?? '') : '';
$startUntil = $addDays($today, 179);
$check(($plain['start_until'] ?? null) === $startUntil && $lastPlain === $startUntil
    && ($covered['start_until'] ?? null) === $startUntil
    && $lastCovered >= $addDays($startUntil, (int) ($covered['max_loan_duration_days'] ?? 0) + 31),
    '4b the availability covering the loan window runs past the last start day by the longest loan plus the closed-day margin',
    json_encode([$plain['start_until'] ?? null, $lastPlain, $lastCovered, $covered['max_loan_duration_days'] ?? null]));

// ═════════ 5-7: the public calendar endpoint explains the cap ════════════════
$r = $requestCalendar($singleBook, ['start_date' => $today]);
$check($r['status'] === 400
    && ($r['body']['max_duration_days'] ?? null) === $expectedMaxDays
    && ($r['body']['max_end_date'] ?? null) === $expectedMaxEnd
    && ($r['body']['first_conflict_date'] ?? null) === $conflictStart
    && str_contains((string) ($r['body']['message'] ?? ''), (string) $expectedMaxDays),
    '5 createReservation without end_date: 400 with the maximum duration, latest return date and blocked day',
    json_encode($r));

$r = $requestCalendar($singleBook, ['start_date' => $today, 'end_date' => $addDays($today, $offset + 2)]);
$check($r['status'] === 400
    && ($r['body']['max_end_date'] ?? null) === $expectedMaxEnd
    && ($r['body']['error'] ?? null) === 'no_capacity',
    '6 createReservation with an end_date crossing the scheduled loan: same figures, error no_capacity',
    json_encode($r));

$r = $requestCalendar($singleBook, ['start_date' => $conflictStart, 'end_date' => $addDays($conflictStart, 2)]);
$check($r['status'] === 400
    && !array_key_exists('max_end_date', $r['body'])
    && ($r['body']['message'] ?? '') === __('Nessuna copia disponibile nelle date richieste'),
    '7 createReservation starting on the blocked day: generic refusal, nothing to shorten',
    json_encode($r));

// ═════════ 8: the capped request goes through ════════════════════════════════
$r = $requestCalendar($singleBook, ['start_date' => $today, 'end_date' => $expectedMaxEnd]);
$check($r['status'] === 200 && ($r['body']['success'] ?? false) === true,
    "8 createReservation ending {$expectedMaxEnd} (the day before the scheduled loan) is accepted",
    json_encode($r));
$resetRequester($singleBook, $requester, $singleCopies);

// ═════════ 9-10: the other validations keep their own answers ════════════════
$r = $requestCalendar($singleBook, ['start_date' => $today, 'end_date' => $addDays($today, $maxDays + 1)]);
$check($r['status'] === 400 && ($r['body']['error'] ?? null) === 'max_duration_exceeded',
    "9 an end_date beyond max_loan_duration_days ({$maxDays}) is refused as max_duration_exceeded, before any capacity check",
    json_encode($r));

$r1 = $requestCalendar($singleBook, ['start_date' => $addDays($today, 2), 'end_date' => $today]);
$r2 = $requestCalendar($singleBook, ['start_date' => $addDays($today, -1)]);
$check(($r1['body']['error'] ?? null) === 'invalid_range' && ($r2['body']['error'] ?? null) === 'past_date',
    '10 an inverted range and a past start keep their dedicated errors',
    json_encode([$r1['body'], $r2['body']]));

// ═════════ 11-12: the user-area forms carry the explanation through the redirect
$params = $userAction('loan', $singleBook, []);
$check(($params['loan_error'] ?? null) === 'not_available'
    && (int) ($params['max_loan_days'] ?? -1) === $expectedMaxDays
    && ($params['max_end_date'] ?? null) === $expectedMaxEnd
    && ($params['blocked_from'] ?? null) === $conflictStart,
    '11 POST /user/loan: loan_error=not_available with max_loan_days, max_end_date and blocked_from',
    json_encode($params));

$explained = LoanWindow::explainFromParams($params);
$check(is_string($explained)
    && str_contains($explained, (string) $expectedMaxDays)
    && LoanWindow::explainFromParams(['max_loan_days' => 'x'] + $params) === null,
    '12 LoanWindow::explainFromParams rebuilds the message from the redirect, and ignores a tampered query',
    (string) $explained);

$params = $userAction('reserve', $singleBook, ['desired_date' => $today]);
$check(($params['reserve_error'] ?? null) === 'not_available'
    && ($params['blocked_from'] ?? null) === $conflictStart,
    '13 POST /user/reserve for today: reserve_error=not_available with the same blocked_from',
    json_encode($params));

// ═════════ 14: a second free copy means no cap ═══════════════════════════════
$blockedDouble = $capacity->unavailableDatesInRange($doubleBook, $today, $addDays($today, $defaultDays), $requester);
$r = $requestCalendar($doubleBook, ['start_date' => $today]);
$check($blockedDouble === [] && $r['status'] === 200 && ($r['body']['success'] ?? false) === true,
    '14 two copies, one scheduled: no blocked day and the full default request is accepted',
    json_encode(['blocked' => $blockedDouble, 'response' => $r]));
$resetRequester($doubleBook, $requester, $doubleCopies);

// ═════════ 16-19: an explicit return date from the app (Mobile API 1.8.2) ════
$params = $userAction('loan', $singleBook, ['end_date' => $expectedMaxEnd]);
$check(isset($params['loan_request_success']) || isset($params['reserve_success']),
    "16 POST /user/loan with end_date = {$expectedMaxEnd} (the day before the scheduled loan) is accepted",
    json_encode($params));
$resetRequester($singleBook, $requester, $singleCopies);

$params = $userAction('loan', $singleBook, ['end_date' => $addDays($today, $maxDays + 1)]);
$check(($params['loan_error'] ?? null) === 'max_duration_exceeded',
    "17 POST /user/loan with an end_date beyond max_loan_duration_days ({$maxDays}) is refused as max_duration_exceeded",
    json_encode($params));

$params = $userAction('loan', $singleBook, ['end_date' => $addDays($today, -1)]);
$check(($params['loan_error'] ?? null) === 'invalid_date',
    '18 POST /user/loan with an end_date before the start is refused as invalid_date',
    json_encode($params));

$params = $userAction('reserve', $singleBook, ['desired_date' => $today, 'end_date' => $expectedMaxEnd]);
$check(isset($params['reserve_success']),
    '19 POST /user/reserve for today with the capped end_date joins the queue instead of being refused',
    json_encode($params));
$resetRequester($singleBook, $requester, $singleCopies);

// ═════════ 20: the public calendar reads the same figures (view contract) ════
$viewSrc = (string) file_get_contents($root . '/app/Views/frontend/book-detail.php');
$check(str_contains($viewSrc, 'function loanWindowFrom(')
    && str_contains($viewSrc, "endPicker.set('maxDate', loanWindow.maxEndDate)")
    && str_contains($viewSrc, 'Durata massima del prestito: %1$d giorni')
    && str_contains($viewSrc, 'LoanWindow::explainFromParams($_GET)'),
    '20 book-detail.php caps the return picker, explains it with the shared message and renders the redirect explanation');
$routes = (string) file_get_contents($root . '/app/Routes/web.php');
$check(str_contains($viewSrc, 'availData.availability.start_until')
    && str_contains($routes, "getBookAvailabilityData(\$bookId, \\App\\Support\\DateHelper::today(), 180, \$excludeUserId, true)"),
    '21 the book page starts no later than start_until, and its route loads the days past it');

$cleanup();
$db->close();

echo PHP_EOL . "Passed: {$passed}   Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

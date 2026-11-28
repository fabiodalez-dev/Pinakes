<?php
declare(strict_types=1);

/**
 * Circulation review 0.9.7: what the reader and the desk end up with in the
 * database, on a fresh isolated schema (the SettingsReview25 harness: real
 * controllers, two real MySQL sessions, production triggers).
 *
 *   A. The longest loan a refusal proposes ends on a day the library is open,
 *      so the date that was proposed is accepted and never lands on the next
 *      commitment (LoanWindow::cap and every surface that quotes it).
 *   B. A request cannot start later than the last day the date picker offers
 *      (ReservationsController::REQUEST_START_HORIZON_DAYS).
 *   C. The desk loan binds the copy the other allocators would bind (#384).
 *   D. An operator edit that frees days promotes the waiting reader at once.
 *   E. Pickup confirmation and the desk's reservation cancel decide on rows
 *      read after the book lock, not on a snapshot taken before it.
 *
 * Expected dates are stated by each fixture (offsets from today), never read
 * back from LibraryCalendar or LoanWindow.
 *
 * Requires CREATE DATABASE on a prefix derived from E2E_DB_NAME/DB_NAME; the
 * harness creates and drops its own randomly named database.
 * Run: php tests/circulation-review-097.unit.php
 * Optional: CIRC097_CASE=3,4 selects numbered cases.
 */

use App\Controllers\LoanApprovalController;
use App\Controllers\PrestitiController;
use App\Controllers\ReservationsController;
use App\Controllers\UserActionsController;
use App\Plugins\MobileApi\Controllers\ActionsController;
use App\Support\ConfigStore;
use App\Support\LibraryCalendar;
use App\Support\LoanWindow;
use App\Support\OneTimeFormToken;
use Slim\Psr7\Response;

require __DIR__ . '/settings-pr-independent-25.unit.php';

function cr97AsReader(int $reader): void
{
    $_SESSION = ['user' => ['id' => $reader, 'tipo_utente' => 'standard', 'stato' => 'attivo']];
}

/** Weekly closure on the weekday of $ymd (it also closes the same weekday of every other week). */
function cr97CloseWeekdayOf(SettingsReview25 $t, string $ymd): void
{
    $t->set('loans', 'closed_weekdays', (new DateTimeImmutable($ymd, new DateTimeZone('UTC')))->format('w'));
}

/** @return array{status:int, body:array<string,mixed>} */
function cr97Calendar(SettingsReview25 $t, int $reader, int $bookId, array $body): array
{
    cr97AsReader($reader);
    $response = (new ReservationsController($t->db))->createReservation(
        $t->request("/api/libro/{$bookId}/reservation", $body),
        new Response(),
        ['id' => (string) $bookId]
    );
    $decoded = json_decode((string) $response->getBody(), true);
    return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
}

/** @return array{status:int, query:array<string,mixed>} */
function cr97UserAction(SettingsReview25 $t, string $method, int $reader, array $body): array
{
    cr97AsReader($reader);
    $_SERVER['HTTP_REFERER'] = '/';
    $response = (new UserActionsController())->{$method}($t->request("/user/{$method}", $body), new Response(), $t->db);
    $query = [];
    parse_str((string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);
    return ['status' => $response->getStatusCode(), 'query' => $query];
}

/** @return array<string,mixed> the query string of the desk redirect */
function cr97DeskLoan(SettingsReview25 $t, int $bookId, int $reader, string $start, string $due, bool $handOver = false, string $copyCode = ''): array
{
    $t->asAdmin();
    $response = (new PrestitiController())->store($t->request('/admin/loans/store', [
        'utente_id' => (string) $reader, 'libro_id' => (string) $bookId, 'copy_code' => $copyCode,
        'data_prestito' => $start, 'data_scadenza' => $due, 'consegna_immediata' => $handOver ? '1' : '',
        'loan_submission_token' => OneTimeFormToken::issue('loan.create'),
    ]), new Response(), $t->db);
    $query = [];
    parse_str((string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);
    return $query;
}

/** The last day anything of $reader's on $bookId holds or asks for the title, null when nothing is stored. */
function cr97StoredEnd(SettingsReview25 $t, int $bookId, int $reader): ?string
{
    $value = $t->scalar("SELECT MAX(d) FROM (
            SELECT data_scadenza AS d FROM prestiti WHERE libro_id={$bookId} AND utente_id={$reader} AND stato NOT IN ('annullato','rifiutato','restituito','scaduto')
            UNION ALL
            SELECT data_fine_richiesta FROM prenotazioni WHERE libro_id={$bookId} AND utente_id={$reader} AND stato='attiva'
        ) held");
    return $value === null ? null : substr((string) $value, 0, 10);
}

function cr97Reserve(SettingsReview25 $t, int $bookId, int $reader, string $from, string $end, int $position = 1): int
{
    $stmt = $t->db->prepare("INSERT INTO prenotazioni (libro_id,utente_id,data_inizio_richiesta,data_fine_richiesta,data_scadenza_prenotazione,stato,queue_position)
                            VALUES (?,?,?,?,CONCAT(?,' 23:59:59'),'attiva',?)");
    $stmt->bind_param('iisssi', $bookId, $reader, $from, $end, $end, $position);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function cr97CopyOfLastLoan(SettingsReview25 $t, int $bookId, int $reader): ?int
{
    $value = $t->scalar("SELECT copia_id FROM prestiti WHERE libro_id={$bookId} AND utente_id={$reader} ORDER BY id DESC LIMIT 1");
    return $value === null ? null : (int) $value;
}

/**
 * One single-copy title at branch A whose only copy another reader has booked
 * from day +10; the weekday of day +9 (so also day +2) is closed. The longest
 * loan from an earlier start therefore ends on day +8.
 *
 * @return array{0: array{id:int, copies:list<int>, codes:list<string>}, 1: string, 2: string}
 */
function cr97CappedTitle(SettingsReview25 $t): array
{
    $book = $t->book($t->branchA);
    $t->loan($book, $t->day(15), ['reader' => $t->secondReader, 'start' => $t->day(10), 'status' => 'prenotato']);
    cr97CloseWeekdayOf($t, $t->day(9));
    return [$book, $t->day(10), $t->day(8)];
}

/** @var array<string, Closure(SettingsReview25): void> $cases */
$cases = [];

// ═══════════════════════════ A. LoanWindow::cap and the calendar ═════════════

$cases['A1 the cap steps back to the last open day before the next commitment'] = static function (SettingsReview25 $t): void {
    cr97CloseWeekdayOf($t, $t->day(9));
    $calendar = LibraryCalendar::forDb($t->db);
    $cap = LoanWindow::cap($t->day(3), [$t->day(10), $t->day(11)], $calendar, null);
    SettingsReview25::check($cap !== null, 'A loan from day +3 is still possible');
    SettingsReview25::eq($cap['max_end_date'], $t->day(8), 'Day +9 is closed: the loan ends on day +8');
    SettingsReview25::eq($cap['max_duration_days'], 5, 'Five days from day +3 to day +8');
    SettingsReview25::eq($cap['first_conflict_date'], $t->day(10), 'The day the book is booked from is unchanged');
    SettingsReview25::eq(
        LoanWindow::redirectParams($t->day(3), [$t->day(10)], $calendar, null),
        ['max_loan_days' => 5, 'max_end_date' => $t->day(8), 'blocked_from' => $t->day(10)],
        'The redirect parameters carry the same open day'
    );
    // Without a calendar the arithmetic of every release before stays.
    SettingsReview25::eq(LoanWindow::cap($t->day(3), [$t->day(10)])['max_end_date'], $t->day(9), 'No calendar passed: the day before the conflict');
};

$cases['A2 no open day left after the start means no window'] = static function (SettingsReview25 $t): void {
    cr97CloseWeekdayOf($t, $t->day(9));
    $calendar = LibraryCalendar::forDb($t->db);
    // Start on day +8, booked from day +10, day +9 closed: nothing to propose.
    SettingsReview25::eq(LoanWindow::cap($t->day(8), [$t->day(10)], $calendar, null), null, 'The only day in between is closed');
    SettingsReview25::eq(LoanWindow::redirectParams($t->day(8), [$t->day(10)], $calendar, null), [], 'No parameters either');
    // A closure that covers every day between the start and the conflict.
    $t->set('loans', 'closed_weekdays', '');
    $t->db->query("INSERT INTO calendario_chiusure (data_inizio,data_fine,descrizione,ricorrente_annuale,sede_id,attiva)
                   VALUES ('{$t->day(4)}','{$t->day(9)}','Review 097',0,NULL,1)");
    LibraryCalendar::reset();
    SettingsReview25::eq(LoanWindow::cap($t->day(3), [$t->day(10)], LibraryCalendar::forDb($t->db), null), null, 'Every day after the start is closed');
};

$cases['A3 the cap follows the closures of the branch that lends'] = static function (SettingsReview25 $t): void {
    $t->db->query("INSERT INTO calendario_chiusure (data_inizio,data_fine,descrizione,ricorrente_annuale,sede_id,attiva)
                   VALUES ('{$t->day(8)}','{$t->day(9)}','Review 097 branch A',0,{$t->branchA},1)");
    LibraryCalendar::reset();
    $calendar = LibraryCalendar::forDb($t->db);
    SettingsReview25::eq(LoanWindow::cap($t->day(3), [$t->day(10)], $calendar, $t->branchA)['max_end_date'], $t->day(7), 'Branch A is closed on days +8 and +9');
    SettingsReview25::eq(LoanWindow::cap($t->day(3), [$t->day(10)], $calendar, $t->branchB)['max_end_date'], $t->day(9), 'Branch B is open');
    SettingsReview25::eq(LoanWindow::cap($t->day(3), [$t->day(10)], $calendar, [$t->branchA, $t->branchB])['max_end_date'], $t->day(9), 'A title lent by both branches can come back where it is open');
};

$cases['A4 public calendar: the proposed return date is accepted and stops before the next loan'] = static function (SettingsReview25 $t): void {
    [$book, $bookedFrom, $lastOpen] = cr97CappedTitle($t);
    $refused = cr97Calendar($t, $t->reader, $book['id'], ['start_date' => $t->day(3)]);
    SettingsReview25::eq($refused['status'], 400, 'The default duration crosses the booked days');
    $proposed = (string) ($refused['body']['max_end_date'] ?? '');
    $accepted = cr97Calendar($t, $t->reader, $book['id'], ['start_date' => $t->day(3), 'end_date' => $proposed]);
    SettingsReview25::check(($accepted['body']['success'] ?? false) === true, 'The return date the server proposed must be accepted: ' . json_encode($accepted));
    $stored = cr97StoredEnd($t, $book['id'], $t->reader);
    SettingsReview25::check($stored !== null && $stored < $bookedFrom, "What is stored ends before the next commitment ({$bookedFrom}), got " . json_encode($stored));
    SettingsReview25::eq($proposed, $lastOpen, 'The proposal is the last open day');
    SettingsReview25::eq($refused['body']['max_duration_days'] ?? null, 5, 'Counted from the requested start');
};

$cases['A5 loan form: the proposed return date is accepted and stops before the next loan'] = static function (SettingsReview25 $t): void {
    [$book, $bookedFrom, $lastOpen] = cr97CappedTitle($t);
    $refused = cr97UserAction($t, 'loan', $t->reader, ['libro_id' => $book['id']]);
    SettingsReview25::eq($refused['query']['loan_error'] ?? null, 'not_available', 'Fourteen days from today cross the booked days');
    $proposed = (string) ($refused['query']['max_end_date'] ?? '');
    $accepted = cr97UserAction($t, 'loan', $t->reader, ['libro_id' => $book['id'], 'end_date' => $proposed]);
    SettingsReview25::check(isset($accepted['query']['loan_request_success']) || isset($accepted['query']['reserve_success']),
        'The return date the server proposed must be accepted: ' . json_encode($accepted['query']));
    $stored = cr97StoredEnd($t, $book['id'], $t->reader);
    SettingsReview25::check($stored !== null && $stored < $bookedFrom, "What is stored ends before the next commitment ({$bookedFrom}), got " . json_encode($stored));
    SettingsReview25::eq($proposed, $lastOpen, 'The proposal is the last open day');
};

$cases['A6 reservation form and the app: the proposed end is accepted and stops before the next loan'] = static function (SettingsReview25 $t): void {
    [$book, $bookedFrom, $lastOpen] = cr97CappedTitle($t);
    $refused = cr97UserAction($t, 'reserve', $t->reader, ['libro_id' => $book['id'], 'desired_date' => $t->day(3)]);
    SettingsReview25::eq($refused['query']['reserve_error'] ?? null, 'not_available', 'Fourteen days from day +3 cross the booked days');
    $proposed = (string) ($refused['query']['max_end_date'] ?? '');
    $accepted = cr97UserAction($t, 'reserve', $t->reader, ['libro_id' => $book['id'], 'desired_date' => $t->day(3), 'end_date' => $proposed]);
    SettingsReview25::check(isset($accepted['query']['reserve_success']), 'The end date the server proposed must be accepted: ' . json_encode($accepted['query']));
    $stored = cr97StoredEnd($t, $book['id'], $t->reader);
    SettingsReview25::check($stored !== null && $stored < $bookedFrom, "What is stored ends before the next commitment ({$bookedFrom}), got " . json_encode($stored));
    SettingsReview25::eq($proposed, $lastOpen, 'The proposal is the last open day');

    // The Mobile API answers with the figures of that same redirect.
    [$appBook] = cr97CappedTitle($t);
    $request = $t->request('/api/v1/reservations', ['book_id' => $appBook['id'], 'desired_date' => $t->day(3)])
        ->withAttribute('mobile_user', ['id' => $t->reader]);
    $answer = json_decode((string) (new ActionsController($t->db))->requestReservation($request, new Response())->getBody(), true);
    SettingsReview25::check(str_contains(json_encode($answer), '"max_end_date":"' . $lastOpen . '"'), 'The app is told the last open day: ' . json_encode($answer));
};

$cases['A7 moving a reservation: the refusal names an open day'] = static function (SettingsReview25 $t): void {
    [$book, , $lastOpen] = cr97CappedTitle($t);
    $reservation = cr97Reserve($t, $book['id'], $t->reader, $t->day(20), $t->day(25));
    $moved = cr97UserAction($t, 'changeReservationDate', $t->reader, ['reservation_id' => $reservation, 'desired_date' => $t->day(3)]);
    SettingsReview25::eq($moved['query']['error'] ?? null, 'not_available', 'Fourteen days from day +3 cross the booked days');
    SettingsReview25::eq($moved['query']['max_end_date'] ?? null, $lastOpen, 'The longest loan ends on the last open day');
};

$cases['A8 loan desk: the due date the form proposes is stored as typed'] = static function (SettingsReview25 $t): void {
    [$book, $bookedFrom, $lastOpen] = cr97CappedTitle($t);
    $refused = cr97DeskLoan($t, $book['id'], $t->reader, $t->day(3), $t->day(14));
    SettingsReview25::eq($refused['error'] ?? null, 'no_copies_available', 'The typed window crosses the booked days');
    $proposed = (string) ($refused['max_end_date'] ?? '');
    $created = cr97DeskLoan($t, $book['id'], $t->reader, $t->day(3), $proposed);
    SettingsReview25::check(!isset($created['error']), 'The due date the form proposed must be accepted: ' . json_encode($created));
    $stored = cr97StoredEnd($t, $book['id'], $t->reader);
    SettingsReview25::check($stored !== null && $stored < $bookedFrom, "The loan ends before the next commitment ({$bookedFrom}), got " . json_encode($stored));
    SettingsReview25::eq($proposed, $lastOpen, 'The proposal is the last open day');
};

$cases['A9 approval: the refusal names an open day'] = static function (SettingsReview25 $t): void {
    [$book, , $lastOpen] = cr97CappedTitle($t);
    $t->db->query("INSERT INTO prestiti (libro_id,copia_id,utente_id,data_prestito,data_scadenza,stato,origine,attivo)
                   VALUES ({$book['id']},NULL,{$t->reader},'{$t->today}','{$t->day(14)}','pendente','richiesta',0)");
    $loan = (int) $t->db->insert_id;
    $t->asAdmin();
    $response = (new LoanApprovalController())->approveLoan($t->request('/admin/loans/approve', ['loan_id' => $loan]), new Response(), $t->db);
    $body = json_decode((string) $response->getBody(), true);
    SettingsReview25::eq($response->getStatusCode(), 400, 'The requested window crosses the booked days: ' . json_encode($body));
    SettingsReview25::eq($body['max_end_date'] ?? null, $lastOpen, 'The longest loan ends on the last open day');
};

// ═══════════════════════════ B. How far ahead a request may start ════════════

$cases['B1 public calendar refuses a start after the last day the picker offers'] = static function (SettingsReview25 $t): void {
    $book = $t->book($t->branchA);
    $controller = new ReservationsController($t->db);
    $horizon = ReservationsController::REQUEST_START_HORIZON_DAYS;
    $lastStart = $controller->getBookAvailabilityData($book['id'], $t->today, $horizon, $t->reader, true)['start_until'];
    SettingsReview25::eq($lastStart, $t->day(179), 'The picker offers 180 days, today included');
    // The public route must hand the picker the same horizon the write enforces.
    $routes = (string) file_get_contents(dirname(__DIR__) . '/app/Routes/web.php');
    SettingsReview25::check(
        str_contains($routes, "getBookAvailabilityData(\$bookId, \\App\\Support\\DateHelper::today(), {$horizon}, \$excludeUserId, true)")
        || str_contains($routes, 'getBookAvailabilityData($bookId, \App\Support\DateHelper::today(), \App\Controllers\ReservationsController::REQUEST_START_HORIZON_DAYS, $excludeUserId, true)'),
        'The book page asks for the same number of days'
    );

    $late = cr97Calendar($t, $t->reader, $book['id'], ['start_date' => $t->day(180)]);
    SettingsReview25::eq($late['status'], 400, 'A start one day past the picker is refused: ' . json_encode($late['body']));
    SettingsReview25::eq($late['body']['error'] ?? null, 'invalid_date', 'As an invalid date');
    SettingsReview25::eq(cr97StoredEnd($t, $book['id'], $t->reader), null, 'Nothing is stored');
    $far = cr97Calendar($t, $t->reader, $book['id'], ['start_date' => $t->day(4000), 'end_date' => $t->day(4005)]);
    SettingsReview25::eq($far['status'], 400, 'Years ahead is refused too');
    SettingsReview25::eq(cr97StoredEnd($t, $book['id'], $t->reader), null, 'Nothing is stored');

    $last = cr97Calendar($t, $t->reader, $book['id'], ['start_date' => $lastStart]);
    SettingsReview25::check(($last['body']['success'] ?? false) === true, 'The last day the picker offers is accepted: ' . json_encode($last));
};

$cases['B2 a closed last day cannot carry the start past the picker'] = static function (SettingsReview25 $t): void {
    $book = $t->book($t->branchA);
    $t->db->query("INSERT INTO calendario_chiusure (data_inizio,data_fine,descrizione,ricorrente_annuale,sede_id,attiva)
                   VALUES ('{$t->day(179)}','{$t->day(185)}','Review 097',0,NULL,1)");
    LibraryCalendar::reset();
    $moved = cr97Calendar($t, $t->reader, $book['id'], ['start_date' => $t->day(179)]);
    SettingsReview25::eq($moved['status'], 400, 'The first open day is day +186, past the picker: ' . json_encode($moved['body']));
    SettingsReview25::eq(cr97StoredEnd($t, $book['id'], $t->reader), null, 'Nothing is stored');
    $queued = cr97UserAction($t, 'reserve', $t->reader, ['libro_id' => $book['id'], 'desired_date' => $t->day(179)]);
    SettingsReview25::eq($queued['query']['reserve_error'] ?? null, 'invalid_date', 'The reservation form refuses it as well');
    SettingsReview25::eq(cr97StoredEnd($t, $book['id'], $t->reader), null, 'Nothing is stored');
};

$cases['B2b a closure that outlasts the horizon leaves no default start either'] = static function (SettingsReview25 $t): void {
    $book = $t->book($t->branchA);
    // Closed from today to past the last day a request may start on.
    $t->db->query("INSERT INTO calendario_chiusure (data_inizio,data_fine,descrizione,ricorrente_annuale,sede_id,attiva)
                   VALUES ('{$t->today}','{$t->day(185)}','Review 097',0,NULL,1)");
    LibraryCalendar::reset();
    // No date chosen: the start would default to the first open day, +186.
    $queued = cr97UserAction($t, 'reserve', $t->reader, ['libro_id' => $book['id']]);
    SettingsReview25::eq($queued['query']['reserve_error'] ?? null, 'not_available', 'A reservation with no chosen date is refused: ' . json_encode($queued['query']));
    SettingsReview25::eq(cr97StoredEnd($t, $book['id'], $t->reader), null, 'Nothing is stored');
    $asked = cr97UserAction($t, 'loan', $t->reader, ['libro_id' => $book['id']]);
    SettingsReview25::eq($asked['query']['loan_error'] ?? null, 'not_available', 'So is a loan request, which always starts on the first open day: ' . json_encode($asked['query']));
    SettingsReview25::eq(cr97StoredEnd($t, $book['id'], $t->reader), null, 'Nothing is stored');
};

$cases['B3 reservation form, date change and the app refuse a start past the picker'] = static function (SettingsReview25 $t): void {
    $book = $t->book($t->branchA);
    $late = cr97UserAction($t, 'reserve', $t->reader, ['libro_id' => $book['id'], 'desired_date' => $t->day(180)]);
    SettingsReview25::eq($late['query']['reserve_error'] ?? null, 'invalid_date', 'A start one day past the picker is refused: ' . json_encode($late['query']));
    SettingsReview25::eq(cr97StoredEnd($t, $book['id'], $t->reader), null, 'Nothing is stored');

    $request = $t->request('/api/v1/reservations', ['book_id' => $book['id'], 'desired_date' => $t->day(180)])
        ->withAttribute('mobile_user', ['id' => $t->reader]);
    $answer = (new ActionsController($t->db))->requestReservation($request, new Response());
    SettingsReview25::eq($answer->getStatusCode(), 422, 'The app gets a validation error: ' . (string) $answer->getBody());
    SettingsReview25::check(str_contains((string) $answer->getBody(), 'invalid_date'), 'With the invalid-date code');
    SettingsReview25::eq(cr97StoredEnd($t, $book['id'], $t->reader), null, 'Nothing is stored');

    $accepted = cr97UserAction($t, 'reserve', $t->reader, ['libro_id' => $book['id'], 'desired_date' => $t->day(179)]);
    SettingsReview25::check(isset($accepted['query']['reserve_success']), 'The last day the picker offers is accepted: ' . json_encode($accepted['query']));
    $reservation = (int) $t->scalar("SELECT id FROM prenotazioni WHERE libro_id={$book['id']} AND utente_id={$t->reader} AND stato='attiva'");
    $moved = cr97UserAction($t, 'changeReservationDate', $t->reader, ['reservation_id' => $reservation, 'desired_date' => $t->day(400)]);
    SettingsReview25::eq($moved['status'], 422, 'Moving it past the picker is refused like an invalid date');
    SettingsReview25::eq((string) $t->scalar("SELECT data_inizio_richiesta FROM prenotazioni WHERE id={$reservation}"), $t->day(179), 'The reservation keeps its dates');
};

// ═══════════════════════════ C. The desk allocator (#384 parity) ═════════════

$cases['C1 the desk keeps the copy a later loan already needs'] = static function (SettingsReview25 $t): void {
    $book = $t->book($t->branchA, copies: 2);
    [$first, $second] = $book['copies'];
    // The higher id is the one already promised later: an unordered pick of
    // either copy cannot satisfy both this case and its mirror below.
    $t->loan($book, $t->day(40), ['copy' => $second, 'reader' => $t->secondReader, 'start' => $t->day(30), 'status' => 'prenotato']);
    $result = cr97DeskLoan($t, $book['id'], $t->reader, $t->today, $t->day(7), handOver: true);
    SettingsReview25::check(!isset($result['error']), 'The desk loan is recorded: ' . json_encode($result));
    SettingsReview25::eq(cr97CopyOfLastLoan($t, $book['id'], $t->reader), $first, 'The copy with no later commitment is lent');

    $mirror = $t->book($t->branchA, copies: 2);
    [$low, $high] = $mirror['copies'];
    $t->loan($mirror, $t->day(40), ['copy' => $low, 'reader' => $t->secondReader, 'start' => $t->day(30), 'status' => 'prenotato']);
    $result = cr97DeskLoan($t, $mirror['id'], $t->reader, $t->today, $t->day(7), handOver: true);
    SettingsReview25::check(!isset($result['error']), 'The desk loan is recorded: ' . json_encode($result));
    SettingsReview25::eq(cr97CopyOfLastLoan($t, $mirror['id'], $t->reader), $high, 'The lower id is skipped when a later loan needs it');
};

$cases['C2 the desk prefers the furthest next commitment, then the lower id'] = static function (SettingsReview25 $t): void {
    $book = $t->book($t->branchA, copies: 3);
    [$a, $b, $c] = $book['copies'];
    $t->loan($book, $t->day(25), ['copy' => $a, 'reader' => $t->secondReader, 'start' => $t->day(20), 'status' => 'prenotato']);
    $third = $t->patron();
    $t->loan($book, $t->day(45), ['copy' => $c, 'reader' => $third, 'start' => $t->day(40), 'status' => 'prenotato']);
    $fourth = $t->patron();
    $t->loan($book, $t->day(45), ['copy' => $b, 'reader' => $fourth, 'start' => $t->day(40), 'status' => 'prenotato']);
    $result = cr97DeskLoan($t, $book['id'], $t->reader, $t->today, $t->day(7), handOver: true);
    SettingsReview25::check(!isset($result['error']), 'The desk loan is recorded: ' . json_encode($result));
    SettingsReview25::eq(cr97CopyOfLastLoan($t, $book['id'], $t->reader), $b, 'Day +40 is further than day +20; between the two, the lower id');
};

$cases['C3 the desk prefers a copy that depends on nobody returning first'] = static function (SettingsReview25 $t): void {
    $book = $t->book($t->branchA, copies: 2);
    [$first, $second] = $book['copies'];
    // The lower id carries an earlier, date-disjoint loan: the request gate,
    // the queue promotion and the approval would all refuse it (#384).
    $t->loan($book, $t->day(3), ['copy' => $first, 'reader' => $t->secondReader, 'start' => $t->day(1), 'status' => 'prenotato']);
    $result = cr97DeskLoan($t, $book['id'], $t->reader, $t->day(10), $t->day(15));
    SettingsReview25::check(!isset($result['error']), 'The desk loan is recorded: ' . json_encode($result));
    SettingsReview25::eq(cr97CopyOfLastLoan($t, $book['id'], $t->reader), $second, 'The copy with no preceding commitment is bound');

    // The desk may still stack onto a preceding commitment when that is the
    // only copy left: the operator decides, the title is not refused.
    $only = $t->book($t->branchA, copies: 2);
    [$stacked, $busy] = $only['copies'];
    $t->loan($only, $t->day(3), ['copy' => $stacked, 'reader' => $t->secondReader, 'start' => $t->day(1), 'status' => 'prenotato']);
    $third = $t->patron();
    $t->loan($only, $t->day(20), ['copy' => $busy, 'reader' => $third, 'start' => $t->day(9), 'status' => 'prenotato']);
    $result = cr97DeskLoan($t, $only['id'], $t->reader, $t->day(10), $t->day(15));
    SettingsReview25::check(!isset($result['error']), 'The desk loan is still recorded: ' . json_encode($result));
    SettingsReview25::eq(cr97CopyOfLastLoan($t, $only['id'], $t->reader), $stacked, 'On the only copy free for those days');
};

// ═══════════════════════════ D. An edit that frees days promotes the queue ═══

$cases['D1 moving a scheduled loan later hands the freed days to the waiting reader'] = static function (SettingsReview25 $t): void {
    $book = $t->book($t->branchA);
    $loan = $t->loan($book, $t->day(12), ['reader' => $t->secondReader, 'start' => $t->day(6), 'status' => 'prenotato']);
    $waiting = cr97Reserve($t, $book['id'], $t->reader, $t->today, $t->day(7));
    $t->asAdmin();
    $response = (new PrestitiController())->update($t->request('/admin/loans/update/' . $loan, [
        'utente_id' => (string) $t->secondReader, 'data_prestito' => $t->day(8), 'data_scadenza' => $t->day(12),
    ]), new Response(), $t->db, $loan);
    SettingsReview25::check(!str_contains($response->getHeaderLine('Location'), 'error='), 'The edit is saved: ' . $response->getHeaderLine('Location'));
    SettingsReview25::eq((string) $t->scalar("SELECT data_prestito FROM prestiti WHERE id={$loan}"), $t->day(8), 'The loan now starts on day +8');
    SettingsReview25::eq((string) $t->scalar("SELECT stato FROM prenotazioni WHERE id={$waiting}"), 'completata', 'The waiting reservation is promoted by the edit itself');
    SettingsReview25::eq((int) $t->scalar("SELECT COUNT(*) FROM prestiti WHERE libro_id={$book['id']} AND utente_id={$t->reader} AND origine='prenotazione' AND copia_id={$book['copies'][0]}"), 1, 'Its reader holds the copy for the freed days');
};

$cases['D2 an edit that frees nothing leaves the queue alone'] = static function (SettingsReview25 $t): void {
    $book = $t->book($t->branchA);
    $loan = $t->loan($book, $t->day(12), ['reader' => $t->secondReader, 'start' => $t->day(6), 'status' => 'prenotato']);
    $waiting = cr97Reserve($t, $book['id'], $t->reader, $t->today, $t->day(7));
    $third = $t->patron();
    $t->asAdmin();
    // Handing the loan to another reader moves no day.
    $response = (new PrestitiController())->update($t->request('/admin/loans/update/' . $loan, [
        'utente_id' => (string) $third, 'data_prestito' => $t->day(6), 'data_scadenza' => $t->day(12),
    ]), new Response(), $t->db, $loan);
    SettingsReview25::check(!str_contains($response->getHeaderLine('Location'), 'error='), 'The edit is saved: ' . $response->getHeaderLine('Location'));
    // Without this a redirect that saved nothing would pass the two checks below.
    SettingsReview25::eq((int) $t->scalar("SELECT utente_id FROM prestiti WHERE id={$loan}"), $third, 'The loan now belongs to the third reader');
    SettingsReview25::eq((string) $t->scalar("SELECT stato FROM prenotazioni WHERE id={$waiting}"), 'attiva', 'The reservation still waits');
    SettingsReview25::eq((int) $t->scalar("SELECT COUNT(*) FROM prestiti WHERE libro_id={$book['id']} AND utente_id={$t->reader}"), 0, 'No loan was written for its reader');
};

$cases['D3 a promotion that fails does not undo the edit'] = static function (SettingsReview25 $t): void {
    $book = $t->book($t->branchA);
    $loan = $t->loan($book, $t->day(12), ['reader' => $t->secondReader, 'start' => $t->day(6), 'status' => 'prenotato']);
    $waiting = cr97Reserve($t, $book['id'], $t->reader, $t->today, $t->day(7));
    $t->asAdmin();
    // Book locks taken while the edit is still open are its own (the other
    // session cannot see the new start yet) and pass; the first one after its
    // commit belongs to whatever runs next, and fails.
    $failed = false;
    $arm = static function () use ($t, $loan, &$failed, &$arm): void {
        $committed = $t->other->query("SELECT data_prestito FROM prestiti WHERE id={$loan}")->fetch_row()[0] === $t->day(8);
        if (!$committed) {
            $t->db->beforeBookLock = $arm;
            return;
        }
        $failed = true;
        throw new RuntimeException('promotion unavailable (test)');
    };
    $t->db->beforeBookLock = $arm;
    $response = (new PrestitiController())->update($t->request('/admin/loans/update/' . $loan, [
        'utente_id' => (string) $t->secondReader, 'data_prestito' => $t->day(8), 'data_scadenza' => $t->day(12),
    ]), new Response(), $t->db, $loan);
    $t->db->beforeBookLock = null;
    SettingsReview25::check(!str_contains($response->getHeaderLine('Location'), 'error='), 'The operator is told the edit was saved: ' . $response->getHeaderLine('Location'));
    SettingsReview25::eq((string) $t->scalar("SELECT data_prestito FROM prestiti WHERE id={$loan}"), $t->day(8), 'The edit is stored');
    SettingsReview25::check($failed, 'The promotion was attempted after the commit');
    SettingsReview25::eq((string) $t->scalar("SELECT stato FROM prenotazioni WHERE id={$waiting}"), 'attiva', 'The reservation is left for the next run');
    SettingsReview25::eq((int) $t->scalar("SELECT COUNT(*) FROM prestiti WHERE libro_id={$book['id']} AND utente_id={$t->reader}"), 0, 'Nothing half-written for its reader');
};

// ═══════════════════════════ E. Decisions read after the book lock ═══════════

$cases['E1 pickup is refused to a reader suspended while the desk waited for the lock'] = static function (SettingsReview25 $t): void {
    $book = $t->book($t->branchA);
    $loan = $t->loan($book, $t->day(14), ['start' => $t->today, 'status' => 'da_ritirare']);
    $t->asAdmin();
    $suspended = false;
    $t->db->beforeBookLock = static function () use ($t, &$suspended): void {
        $t->other->query("UPDATE utenti SET stato='sospeso' WHERE id={$t->reader}");
        $suspended = true;
    };
    $response = (new LoanApprovalController())->confirmPickup($t->request('/admin/loans/confirm-pickup', ['loan_id' => $loan]), new Response(), $t->db);
    SettingsReview25::check($suspended, 'The suspension is committed before the book lock is taken');
    SettingsReview25::eq((string) $t->scalar("SELECT stato FROM prestiti WHERE id={$loan}"), 'da_ritirare', 'The book is not handed to a suspended reader');
    SettingsReview25::eq($response->getStatusCode(), 403, 'The desk is told why: ' . (string) $response->getBody());
};

$cases['E2 a desk cancel promotes the next reader onto a copy returned while it waited for the lock'] = static function (SettingsReview25 $t): void {
    $book = $t->book($t->branchA);
    $borrower = $t->patron();
    $out = $t->loan($book, $t->day(5), ['reader' => $borrower, 'start' => $t->day(-5)]);
    $head = cr97Reserve($t, $book['id'], $t->reader, $t->today, $t->day(7), 1);
    $next = cr97Reserve($t, $book['id'], $t->secondReader, $t->today, $t->day(7), 2);
    $t->asAdmin();
    $returned = false;
    $t->db->beforeBookLock = static function () use ($t, $book, $out, &$returned): void {
        // A return committed by another session, as the row writes it.
        $t->other->begin_transaction();
        $t->other->query("UPDATE prestiti SET stato='restituito', attivo=0, data_restituzione='{$t->today}' WHERE id={$out}");
        $t->other->query("UPDATE copie SET stato='disponibile' WHERE id={$book['copies'][0]}");
        $t->other->query("UPDATE libri SET stato='disponibile', copie_disponibili=1 WHERE id={$book['id']}");
        $t->other->commit();
        $returned = true;
    };
    $response = (new LoanApprovalController())->cancelReservation($t->request('/admin/loans/cancel-reservation', ['reservation_id' => $head]), new Response(), $t->db);
    SettingsReview25::check($returned, 'The return is committed before the book lock is taken');
    SettingsReview25::eq($response->getStatusCode(), 200, 'The cancellation succeeds: ' . (string) $response->getBody());
    SettingsReview25::eq((string) $t->scalar("SELECT stato FROM prenotazioni WHERE id={$head}"), 'annullata', 'The head of the queue is cancelled');
    SettingsReview25::eq((string) $t->scalar("SELECT stato FROM prenotazioni WHERE id={$next}"), 'completata', 'The next reader is promoted onto the returned copy');
    SettingsReview25::eq((int) $t->scalar("SELECT COUNT(*) FROM prestiti WHERE libro_id={$book['id']} AND utente_id={$t->secondReader} AND copia_id={$book['copies'][0]}"), 1, 'And holds it');
};

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

$passed = $failed = 0;
$suite = null;
$savedSession = $_SESSION ?? [];
$savedReferer = $_SERVER['HTTP_REFERER'] ?? null;
try {
    $selected = array_filter(explode(',', getenv('CIRC097_CASE') ?: ''));
    SettingsReview25::check(array_diff($selected, array_map('strval', range(1, count($cases)))) === [], 'CIRC097_CASE must contain existing case numbers');
    $suite = new SettingsReview25();
    ConfigStore::usingConnection($suite->db, static function () use ($suite, $cases, $selected, &$passed, &$failed): void {
        $number = 0;
        foreach ($cases as $name => $case) {
            $number++;
            if ($selected !== [] && !in_array((string) $number, $selected, true)) {
                continue;
            }
            try {
                $suite->reset();
                $case($suite);
                $passed++;
                echo "PASS {$number} {$name}\n";
            } catch (Throwable $error) {
                $failed++;
                echo "FAIL {$number} {$name}: {$error->getMessage()}\n";
            }
        }
    });
} catch (Throwable $error) {
    $failed++;
    fwrite(STDERR, 'FAIL setup: ' . $error->getMessage() . PHP_EOL);
} finally {
    $_SESSION = $savedSession;
    if ($savedReferer === null) {
        unset($_SERVER['HTTP_REFERER']);
    } else {
        $_SERVER['HTTP_REFERER'] = $savedReferer;
    }
    LibraryCalendar::reset();
    $suite?->close();
}
echo "Circulation review 0.9.7: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

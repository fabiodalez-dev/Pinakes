<?php
declare(strict_types=1);

/**
 * 100 calendar/circulation acceptance scenarios on a fresh isolated schema.
 * Dates are stated by the fixture, never inferred from production calendar code.
 * Set RESERVATION_CASE=81,82 to run specific numbered cases during development.
 */
use App\Controllers\LoanApprovalController;
use App\Controllers\ReservationManager;
use App\Controllers\ReservationsAdminController;
use App\Controllers\ReservationsController;
use App\Controllers\UserActionsController;
use App\Services\CapacityService;
use App\Services\ReservationReassignmentService;
use App\Support\ConfigStore;
use App\Support\LibraryCalendar;
use App\Support\Holidays\HolidayPresets;
use App\Support\IcsGenerator;
use App\Support\MaintenanceService;
use App\Plugins\MobileApi\Support\UserLoanCalendar;
use App\Plugins\NcipServer\NcipServerPlugin;
use Slim\Psr7\Response;

require __DIR__ . '/settings-pr-independent-25.unit.php';

/** Persist a rule without reconciling existing loans, as an import/rollover can. */
function matrixClosure(SettingsReview25 $t, string $start, string $end, ?int $branch = null, bool $yearly = false, bool $active = true): void
{
    $stmt = $t->db->prepare("INSERT INTO calendario_chiusure (data_inizio,data_fine,descrizione,ricorrente_annuale,sede_id,attiva) VALUES (?,?,'Reservation matrix',?,?,?)");
    $repeats = (int) $yearly;
    $enabled = (int) $active;
    $stmt->bind_param('ssiii', $start, $end, $repeats, $branch, $enabled);
    $stmt->execute();
    $stmt->close();
    LibraryCalendar::reset();
}

function matrixHoliday(SettingsReview25 $t, string $start, int $days, ?int $branch = null, bool $active = true): void
{
    $stmt = $t->db->prepare("INSERT INTO festivita_personalizzate (nome,tipo,date_specifiche,durata_giorni,sede_id,attiva) VALUES ('Reservation matrix holiday','dates',?,?,?,?)");
    $enabled = (int) $active;
    $stmt->bind_param('siii', $start, $days, $branch, $enabled);
    $stmt->execute();
    $stmt->close();
    LibraryCalendar::reset();
}

function matrixReserve(SettingsReview25 $t, array $book, int $reader, ?string $from, string $end, int $position = 1): int
{
    $stmt = $t->db->prepare("INSERT INTO prenotazioni (libro_id,utente_id,data_inizio_richiesta,data_fine_richiesta,data_scadenza_prenotazione,stato,queue_position)
                            VALUES (?,?,?,?,CONCAT(?,' 23:59:59'),'attiva',?)");
    $stmt->bind_param('iisssi', $book['id'], $reader, $from, $end, $end, $position);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function matrixLoan(SettingsReview25 $t, array $book, ?int $copy, string $start, string $end, string $state = 'prenotato', string $origin = 'richiesta', ?string $pickup = null): int
{
    $stmt = $t->db->prepare("INSERT INTO prestiti (libro_id,copia_id,utente_id,data_prestito,data_scadenza,stato,origine,attivo,pickup_deadline)
                            VALUES (?,?,?,?,?,?,?,?,?)");
    $active = (int) ($state !== 'pendente');
    $stmt->bind_param('iiissssis', $book['id'], $copy, $t->reader, $start, $end, $state, $origin, $active, $pickup);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function matrixCopy(SettingsReview25 $t, array $book, int $branch): int
{
    $t->db->query("INSERT INTO copie (libro_id,numero_inventario,stato,sede_id) VALUES ({$book['id']},'Matrix-{$book['id']}-{$branch}','disponibile',{$branch})");
    return (int) $t->db->insert_id;
}

function matrixState(SettingsReview25 $t, int $loan): string
{
    return (string) $t->scalar("SELECT stato FROM prestiti WHERE id={$loan}");
}

function matrixCalendarEnd(string $ics, string $uid, string $exclusiveEnd): void
{
    SettingsReview25::check(
        preg_match('/^UID:' . preg_quote($uid, '/') . '\r?\n(?:(?!END:VEVENT).)*?^DTEND;VALUE=DATE:'
            . str_replace('-', '', $exclusiveEnd) . '\r?$/ms', $ics) === 1,
        "{$uid} ends on the expected exclusive date {$exclusiveEnd}"
    );
}

$calendars = [
    'library closure, one day' => [1, static fn (SettingsReview25 $t, string $d) => matrixClosure($t, $d, $d)],
    'library closure, three days' => [3, static fn (SettingsReview25 $t, string $d) => matrixClosure($t, $d, $t->day(2, $d))],
    'observed New Year across the year boundary' => [2, static function (SettingsReview25 $t, string $d): void {
        $newYear = $t->day(1, $d);
        HolidayPresets::apply($t->db, 'US', '', ['US:new_year'], $newYear);
        LibraryCalendar::reset();
    }, static function (SettingsReview25 $t): string {
        // If January 1 is Saturday, the Friday before is observed as well.
        for ($year = (int) substr($t->today, 0, 4) + 1; ; $year++) {
            $newYear = new DateTimeImmutable($year . '-01-01', new DateTimeZone('UTC'));
            if ($newYear->format('N') === '6') {
                return $newYear->modify('-1 day')->format('Y-m-d');
            }
        }
    }],
    'another branch closes' => [0, static fn (SettingsReview25 $t, string $d) => matrixClosure($t, $d, $t->day(2, $d), $t->branchB)],
    'weekly closure' => [1, static fn (SettingsReview25 $t, string $d) => $t->set('loans', 'closed_weekdays', (new DateTimeImmutable($d))->format('w'))],
    'yearly closure projected from last year' => [2, static function (SettingsReview25 $t, string $d): void {
        $year = (int) substr($d, 0, 4) - 1;
        matrixClosure($t, $year . '-01-05', $year . '-01-06', yearly: true);
    }, static fn (SettingsReview25 $t): string => ((int) substr($t->today, 0, 4) + 1) . '-01-05'],
    'custom holiday in the library' => [2, static fn (SettingsReview25 $t, string $d) => matrixHoliday($t, $d, 2)],
    'custom holiday at the lending branch' => [3, static fn (SettingsReview25 $t, string $d) => matrixHoliday($t, $d, 3, $t->branchA)],
    'inactive holiday' => [0, static fn (SettingsReview25 $t, string $d) => matrixHoliday($t, $d, 3, $t->branchA, false)],
    'dated closure followed by a custom holiday' => [2, static function (SettingsReview25 $t, string $d): void {
        matrixClosure($t, $d, $d);
        matrixHoliday($t, $t->day(1, $d), 1);
    }],
];

/** @var array<string, Closure(SettingsReview25): void> $cases */
$cases = [];
foreach ($calendars as $name => $calendarSpec) {
    [$closedDays, $apply] = $calendarSpec;
    $chooseDate = $calendarSpec[2] ?? static fn (SettingsReview25 $t): string => $t->day(12);
    $fixture = static function (SettingsReview25 $t) use ($closedDays, $apply, $chooseDate): array {
        $book = $t->book($t->branchA);
        $date = $chooseDate($t);
        $apply($t, $date);
        return [$book, $date, $t->day($closedDays, $date)];
    };

    $cases["public date picker skips {$name}"] = static function (SettingsReview25 $t) use ($fixture): void {
        [$b, $date, $open] = $fixture($t);
        $view = (new ReservationsController($t->db))->getBookAvailabilityData($b['id'], $date, 5);
        SettingsReview25::eq($view['earliest_available'], $open, 'Earliest selectable start');
        SettingsReview25::eq(in_array($date, $view['closed_dates'], true), $date !== $open, 'Closed dates and selectable starts agree');
    };
    // The inactive-rule control needs no duplicate checks of the other writers.
    if ($name !== 'inactive holiday') {
        $cases["copy-available estimate skips {$name}"] = static function (SettingsReview25 $t) use ($fixture): void {
            [$b, $date, $open] = $fixture($t);
            SettingsReview25::eq((new CapacityService($t->db))->firstAvailableDate($b['id'], $date), $open, 'First open day with physical capacity');
        };
        $cases["staff creates a same-day hold during {$name}"] = static function (SettingsReview25 $t) use ($fixture): void {
            [$b, $date, $open] = $fixture($t);
            (new ReservationsAdminController())->store($t->request('/admin/reservations/create', [
                'libro_id' => $b['id'], 'utente_id' => $t->reader, 'data_prenotazione' => $date, 'data_scadenza' => $date,
            ]), new Response(), $t->db);
            $row = $t->db->query("SELECT data_inizio_richiesta,data_fine_richiesta FROM prenotazioni WHERE libro_id={$b['id']}")->fetch_row();
            SettingsReview25::eq($row, [$open, $open], 'Stored staff hold covers the usable date');
        };
        $cases["staff edits a live hold into {$name}"] = static function (SettingsReview25 $t) use ($fixture): void {
            [$b, $date, $open] = $fixture($t);
            $id = matrixReserve($t, $b, $t->reader, $t->day(6), $t->day(7));
            (new ReservationsAdminController())->update($t->request('/admin/reservations/edit', [
                'stato' => 'attiva', 'data_prenotazione' => $date, 'data_scadenza_prenotazione' => $date,
                'data_inizio_richiesta' => $date, 'data_fine_richiesta' => $date,
            ]), new Response(), $t->db, $id);
            $row = $t->db->query("SELECT data_inizio_richiesta,data_fine_richiesta FROM prenotazioni WHERE id={$id}")->fetch_row();
            SettingsReview25::eq($row, [$open, $open], 'Edited hold follows the same calendar as creation');
        };
        $cases["web request normalizes its start through {$name}"] = static function (SettingsReview25 $t) use ($fixture): void {
            [$b, $date, $open] = $fixture($t);
            $_SESSION['user']['id'] = $t->reader;
            $_SESSION['user']['tipo_utente'] = 'standard';
            $end = $t->day(4, $date);
            $response = (new ReservationsController($t->db))->createReservation($t->request('/api/reservations', [
                'start_date' => $date, 'end_date' => $end,
            ]), new Response(), ['id' => $b['id']]);
            SettingsReview25::eq($response->getStatusCode(), 200, 'Web request is accepted');
            $row = $t->db->query("SELECT data_prestito,data_scadenza FROM prestiti WHERE libro_id={$b['id']}")->fetch_row();
            SettingsReview25::eq($row, [$open, $end], 'Loan starts on the usable day and keeps the open end');
        };
    }
    if ($name !== 'inactive holiday') {
        $cases["explicit reader queue follows {$name}"] = static function (SettingsReview25 $t) use ($fixture): void {
            [$b, $date, $open] = $fixture($t);
            $_SESSION['user']['id'] = $t->reader;
            $_SESSION['user']['tipo_utente'] = 'standard';
            $end = $t->day(4, $date);
            (new UserActionsController())->reserve($t->request('/user/reserve', [
                'libro_id' => $b['id'], 'desired_date' => $date, 'end_date' => $end,
            ]), new Response(), $t->db);
            $row = $t->db->query("SELECT data_inizio_richiesta,data_fine_richiesta FROM prenotazioni WHERE libro_id={$b['id']}")->fetch_row();
            SettingsReview25::eq($row, [$open, $end], 'Reader hold is persisted on the usable window');
        };
    }
    $cases["an existing queue still holds capacity through {$name}"] = static function (SettingsReview25 $t) use ($fixture): void {
        [$b, $date, $open] = $fixture($t);
        $id = matrixReserve($t, $b, $t->reader, $t->day(3), $date);
        $capacity = new CapacityService($t->db);
        SettingsReview25::check(!$capacity->hasFreeCapacity($b['id'], $open, $open), 'Last effective day belongs to the existing reservation');
        SettingsReview25::check($capacity->hasFreeCapacity($b['id'], $t->day(1, $open), $t->day(1, $open)), 'The following day is released');
        $calendar = (new UserLoanCalendar($t->db))->generate($t->reader, 'Test', includeReservations: true);
        matrixCalendarEnd($calendar, 'reservation-' . $id . '@pinakes', $t->day(1, $open));
    };
    $cases["expiry uses the effective deadline after {$name}"] = static function (SettingsReview25 $t) use ($fixture): void {
        [$b, $date, $open] = $fixture($t);
        $deadline = $date . ' 18:00:00';
        $last = $open . ($date === $open ? ' 18:00:00' : ' 23:59:59');
        $after = $date === $open ? $open . ' 18:00:01' : $t->day(1, $open) . ' 00:00:00';
        SettingsReview25::eq(LibraryCalendar::reservationDeadline($t->db, $deadline, $b['id']), $last, 'Reader-visible queue deadline');
        SettingsReview25::check(!LibraryCalendar::reservationExpired($t->db, $deadline, $b['id'], $last), 'The final instant is still usable');
        SettingsReview25::check(LibraryCalendar::reservationExpired($t->db, $deadline, $b['id'], $after), 'The queue expires after the usable window');
    };
}

$cases['a free but closed segment does not mask an occupied reopening day'] = static function (SettingsReview25 $t): void {
    $b = $t->book($t->branchA);
    matrixClosure($t, $t->day(2), $t->day(3));
    matrixReserve($t, $b, $t->reader, $t->day(4), $t->day(5));
    SettingsReview25::eq((new CapacityService($t->db))->firstAvailableDate($b['id'], $t->day(2)), $t->day(6), 'The first open and unoccupied day is after the later hold');
};
$cases['a picker cannot suggest an open date outside the selectable start horizon'] = static function (SettingsReview25 $t): void {
    $b = $t->book();
    matrixClosure($t, $t->day(2), $t->day(4));
    $view = (new ReservationsController($t->db))->getBookAvailabilityData($b['id'], $t->day(2), 3, coverLoanWindow: true);
    SettingsReview25::eq($view['earliest_available'], null, 'Extra return-date data must not extend selectable starts');
    SettingsReview25::check(count($view['days']) > 3, 'Return-date data still extends beyond the start horizon');
};
$cases['reopening cannot give a date to an overdue copy with no known return'] = static function (SettingsReview25 $t): void {
    $b = $t->book();
    $t->loan($b, $t->day(-1), ['status' => 'in_ritardo']);
    matrixHoliday($t, $t->today, 1);
    SettingsReview25::eq((new CapacityService($t->db))->firstAvailableDate($b['id'], $t->today), null, 'Physical return remains unknown');
};
$cases['a long but bounded picker includes holidays beyond the former scan cap'] = static function (SettingsReview25 $t): void {
    $b = $t->book($t->branchA);
    $holiday = $t->day(1200);
    matrixHoliday($t, $holiday, 1);
    $view = (new ReservationsController($t->db))->getBookAvailabilityData($b['id'], $t->today, 180, coverLoanWindow: true);
    SettingsReview25::check(isset($view['by_date'][$holiday]), 'The return date is inside the advertised window');
    SettingsReview25::check(in_array($holiday, $view['closed_dates'], true), 'The return date is also listed as closed');
};

foreach ([
    'scheduled, pinned' => ['prenotato', 'richiesta', true],
    'web pending, copyless' => ['pendente', 'richiesta', false],
    'queue conversion, pinned' => ['pendente', 'prenotazione', true],
    'NCIP pending, copyless' => ['pendente', 'ncip', false],
] as $kind => [$state, $origin, $pinned]) {
    $cases["reopening protects {$kind} from the expiry sweep"] = static function (SettingsReview25 $t) use ($state, $origin, $pinned): void {
        $b = $t->book($t->branchA);
        $loan = matrixLoan($t, $b, $pinned ? $b['copies'][0] : null, $t->day(-5), $t->day(-1), $state, $origin);
        matrixHoliday($t, $t->day(-1), 1, $t->branchA);
        SettingsReview25::eq((new MaintenanceService($t->db))->checkExpiredReservations(), 0, 'Expiry cannot consume the usable reopening day');
        SettingsReview25::eq(matrixState($t, $loan), $state, 'The original state is retained');
    };
}
$cases['copyless requests do not expire on their only branch holiday'] = static function (SettingsReview25 $t): void {
    $b = $t->book($t->branchA);
    $loan = matrixLoan($t, $b, null, $t->day(-5), $t->day(-1), 'pendente');
    matrixHoliday($t, $t->today, 1, $t->branchA);
    SettingsReview25::eq((new MaintenanceService($t->db))->checkExpiredReservations(), 0, 'Only branch is closed');
    SettingsReview25::eq(matrixState($t, $loan), 'pendente', 'The request remains pending');
};
$cases['a holiday at a different branch does not postpone a genuinely expired request'] = static function (SettingsReview25 $t): void {
    $b = $t->book($t->branchA);
    $loan = matrixLoan($t, $b, $b['copies'][0], $t->day(-5), $t->day(-1));
    matrixHoliday($t, $t->today, 1, $t->branchB);
    SettingsReview25::eq((new MaintenanceService($t->db))->checkExpiredReservations(), 1, 'Unrelated holiday does not protect a past window');
    SettingsReview25::eq(matrixState($t, $loan), 'scaduto', 'The expired booking is closed');
};

$cases['the library never announces scheduled pickups on a day it is closed'] = static function (SettingsReview25 $t): void {
    $b = $t->book();
    $loan = matrixLoan($t, $b, $b['copies'][0], $t->today, $t->day(5));
    matrixClosure($t, $t->today, $t->today);
    SettingsReview25::eq((new MaintenanceService($t->db))->activateScheduledLoans(), 0, 'No activation during a library closure');
    SettingsReview25::eq(matrixState($t, $loan), 'prenotato', 'The loan remains scheduled');
};
$cases['a pinned scheduled pickup waits for its own branch to reopen'] = static function (SettingsReview25 $t): void {
    $b = $t->book($t->branchA);
    matrixCopy($t, $b, $t->branchB);
    $loan = matrixLoan($t, $b, $b['copies'][0], $t->today, $t->day(5));
    matrixHoliday($t, $t->today, 1, $t->branchA);
    SettingsReview25::eq((new MaintenanceService($t->db))->activateScheduledLoans(), 0, 'The announced branch is still closed');
    $response = (new LoanApprovalController())->confirmPickup($t->request('/admin/loans/pickup', ['loan_id' => $loan]), new Response(), $t->db);
    SettingsReview25::eq($response->getStatusCode(), 400, 'Direct staff confirmation also respects the assigned branch closure');
    SettingsReview25::eq(matrixState($t, $loan), 'prenotato', 'An open sibling does not silently move an assigned pickup');
};
$cases['an unassigned scheduled pickup chooses an open branch'] = static function (SettingsReview25 $t): void {
    $b = $t->book($t->branchA);
    $openCopy = matrixCopy($t, $b, $t->branchB);
    $loan = matrixLoan($t, $b, null, $t->today, $t->day(5));
    matrixHoliday($t, $t->today, 1, $t->branchA);
    SettingsReview25::eq((new MaintenanceService($t->db))->activateScheduledLoans(), 1, 'An open copy can serve the booking');
    SettingsReview25::eq((int) $t->scalar("SELECT copia_id FROM prestiti WHERE id={$loan}"), $openCopy, 'The closed branch was skipped');
};
$cases['a scheduled loan can activate on the reopening day of a past stored end'] = static function (SettingsReview25 $t): void {
    $b = $t->book($t->branchA);
    $loan = matrixLoan($t, $b, $b['copies'][0], $t->day(-4), $t->day(-1));
    matrixHoliday($t, $t->day(-1), 1, $t->branchA);
    SettingsReview25::eq((new MaintenanceService($t->db))->activateScheduledLoans(), 1, 'The scheduled loan can still be collected');
    $row = $t->db->query("SELECT stato,data_scadenza,pickup_deadline FROM prestiti WHERE id={$loan}")->fetch_row();
    SettingsReview25::eq($row, ['da_ritirare', $t->today, $t->today], 'Both deadlines are capped to the reopening day');
};
$cases['staff can confirm when both unreconciled deadlines fall in the same closure'] = static function (SettingsReview25 $t): void {
    $b = $t->book($t->branchA);
    $loan = matrixLoan($t, $b, $b['copies'][0], $t->day(-5), $t->day(-1), 'da_ritirare', pickup: $t->day(-2));
    matrixHoliday($t, $t->day(-2), 2, $t->branchA);
    matrixCalendarEnd((new IcsGenerator($t->db))->generate(), 'loan-' . $loan . '@pinakes', $t->day(1));
    matrixCalendarEnd((new UserLoanCalendar($t->db))->generate($t->reader, 'Test'), 'loan-' . $loan . '@pinakes', $t->day(1));
    $response = (new LoanApprovalController())->confirmPickup($t->request('/admin/loans/pickup', ['loan_id' => $loan]), new Response(), $t->db);
    SettingsReview25::eq($response->getStatusCode(), 200, 'Reopening is still a valid pickup day before the sweep');
    SettingsReview25::eq(matrixState($t, $loan), 'in_corso', 'Pickup starts the loan');
    SettingsReview25::eq($t->due($loan), $t->today, 'The confirmed loan stores the safe return day');
};
$cases['a closed pickup cannot outlive an unchanged open return deadline'] = static function (SettingsReview25 $t): void {
    $b = $t->book($t->branchA);
    $loan = matrixLoan($t, $b, $b['copies'][0], $t->day(-5), $t->day(-2), 'da_ritirare', pickup: $t->day(-3));
    matrixHoliday($t, $t->day(-3), 1, $t->branchA);
    SettingsReview25::eq((new MaintenanceService($t->db))->checkExpiredPickups(), 1, 'A genuinely elapsed pickup is not revived');
    SettingsReview25::eq(matrixState($t, $loan), 'scaduto', 'The expired pickup frees the copy');
};
$cases['approval during a branch holiday schedules rather than announces immediate pickup'] = static function (SettingsReview25 $t): void {
    $b = $t->book($t->branchA);
    $loan = matrixLoan($t, $b, null, $t->today, $t->day(5), 'pendente');
    matrixHoliday($t, $t->today, 1, $t->branchA);
    $response = (new LoanApprovalController())->approveLoan($t->request('/admin/loans/approve', ['loan_id' => $loan]), new Response(), $t->db);
    SettingsReview25::eq($response->getStatusCode(), 200, 'Approval can reserve a future open pickup');
    $row = $t->db->query("SELECT stato,data_prestito,pickup_deadline FROM prestiti WHERE id={$loan}")->fetch_row();
    SettingsReview25::eq($row, ['prenotato', $t->day(1), null], 'The loan will activate only on reopening');
};
$cases['approval skips a copy whose effective return day is already promised'] = static function (SettingsReview25 $t): void {
    $b = $t->book($t->branchA);
    $openCopy = matrixCopy($t, $b, $t->branchB);
    $loan = matrixLoan($t, $b, $b['copies'][0], $t->today, $t->day(2), 'pendente');
    $t->loan($b, $t->day(7), ['copy' => $b['copies'][0], 'reader' => $t->secondReader, 'start' => $t->day(3), 'status' => 'prenotato']);
    matrixReserve($t, $b, $t->operator, $t->day(3), $t->day(7));
    matrixHoliday($t, $t->day(2), 1, $t->branchA);
    $response = (new LoanApprovalController())->approveLoan($t->request('/admin/loans/approve', ['loan_id' => $loan]), new Response(), $t->db);
    SettingsReview25::eq($response->getStatusCode(), 200, 'A compatible copy can be approved');
    SettingsReview25::eq((int) $t->scalar("SELECT copia_id FROM prestiti WHERE id={$loan}"), $openCopy, 'The first copy cannot promise the reopening day twice');

    matrixHoliday($t, $t->day(-1), 1, $t->branchA);
    foreach ([$t->branchA, $t->branchB] as $pinnedBranch) {
        $otherBranch = $pinnedBranch === $t->branchA ? $t->branchB : $t->branchA;
        $b = $t->book($pinnedBranch);
        $sibling = matrixCopy($t, $b, $otherBranch);
        $loan = matrixLoan($t, $b, $b['copies'][0], $t->day(-4), $t->day(-1), 'pendente');
        $response = (new LoanApprovalController())->approveLoan($t->request('/admin/loans/approve', ['loan_id' => $loan]), new Response(), $t->db);
        SettingsReview25::eq($response->getStatusCode(), 200, 'An elapsed return window at one branch does not discard a valid reopening at another');
        SettingsReview25::eq((int) $t->scalar("SELECT copia_id FROM prestiti WHERE id={$loan}"), $pinnedBranch === $t->branchA ? $b['copies'][0] : $sibling, 'The chosen copy must still have a usable return date');
    }
};
$cases['a returned copy cannot take a hold that conflicts on its reopening day'] = static function (SettingsReview25 $t): void {
    $b = $t->book($t->branchA);
    $copy = $b['copies'][0];
    $loan = matrixLoan($t, $b, null, $t->day(2), $t->day(4));
    $t->loan($b, $t->day(8), ['copy' => $copy, 'reader' => $t->secondReader, 'start' => $t->day(5), 'status' => 'prenotato']);
    matrixHoliday($t, $t->day(4), 1, $t->branchA);
    SettingsReview25::check(!(new ReservationReassignmentService($t->db))->reassignOnReturn($copy), 'The returned copy is not usable for both promises');
    SettingsReview25::eq($t->scalar("SELECT copia_id FROM prestiti WHERE id={$loan}"), null, 'The first hold remains unassigned');
};
$cases['a replacement copy in another branch stores its usable return day'] = static function (SettingsReview25 $t): void {
    $b = $t->book($t->branchB);
    $replacement = matrixCopy($t, $b, $t->branchA);
    $loan = matrixLoan($t, $b, $b['copies'][0], $t->day(2), $t->day(4));
    $t->db->query("UPDATE copie SET stato='perso' WHERE id={$b['copies'][0]}");
    matrixHoliday($t, $t->day(4), 1, $t->branchA);
    (new ReservationReassignmentService($t->db))->reassignOnCopyLost($b['copies'][0]);
    $row = $t->db->query("SELECT copia_id,data_scadenza FROM prestiti WHERE id={$loan}")->fetch_row();
    SettingsReview25::eq([(int) $row[0], $row[1]], [$replacement, $t->day(5)], 'Replacement commitment uses the destination calendar');

    $b = $t->book($t->branchA);
    $sibling = matrixCopy($t, $b, $t->branchB);
    $loan = matrixLoan($t, $b, $b['copies'][0], $t->day(2), $t->day(4));
    $t->loan($b, $t->day(7), ['copy' => $sibling, 'reader' => $t->secondReader, 'start' => $t->day(5), 'status' => 'prenotato']);
    $t->set('loans', 'same_day_handover_after_closure', '1');
    $t->db->query("UPDATE copie SET stato='perso' WHERE id={$b['copies'][0]}");
    SettingsReview25::check((new ReservationReassignmentService($t->db))->reassignOnCopyLost($b['copies'][0]), 'Copy loss can release a hold with no replacement');
    $row = $t->db->query("SELECT copia_id,data_scadenza FROM prestiti WHERE id={$loan}")->fetch_row();
    SettingsReview25::eq($row, [null, $t->day(4)], 'An unassigned hold does not take the reopening day from the next reader');
};

foreach (['staff', 'reader'] as $actor) {
    $cases["{$actor} cancellation on reopening is voluntary, not an expiry"] = static function (SettingsReview25 $t) use ($actor): void {
        $b = $t->book($t->branchA);
        $loan = matrixLoan($t, $b, $b['copies'][0], $t->day(-5), $t->day(-1), 'da_ritirare', pickup: $t->day(-2));
        matrixHoliday($t, $t->day(-2), 2, $t->branchA);
        $request = $t->request('/cancel', ['loan_id' => $loan]);
        if ($actor === 'staff') {
            (new LoanApprovalController())->cancelPickup($request, new Response(), $t->db);
        } else {
            $_SESSION['user']['id'] = $t->reader;
            $_SESSION['user']['tipo_utente'] = 'standard';
            (new UserActionsController())->cancelLoan($request, new Response(), $t->db);
        }
        SettingsReview25::eq(matrixState($t, $loan), 'annullato', 'The collection deadline is still usable');
    };
}

$cases['NCIP requests start on reopening and return the persisted effective due date'] = static function (SettingsReview25 $t): void {
    $b = $t->book($t->branchA);
    matrixHoliday($t, $t->today, 1, $t->branchA);
    matrixHoliday($t, $t->day(15), 1, $t->branchA);
    $plugin = new NcipServerPlugin($t->db, new App\Support\HookManager($t->db));
    $plugin->onInstall();
    $reason = '';
    $outcome = (new ReflectionMethod($plugin, 'createRequestItemNcip'))->invokeArgs($plugin, [$b['id'], $t->reader, null, 'matrix-ncip', &$reason]);
    SettingsReview25::check(is_array($outcome), "NCIP can place the request (reason: {$reason})");
    $id = (int) $outcome['loan_id'];
    $row = $t->db->query("SELECT data_prestito,data_scadenza FROM prestiti WHERE id={$id}")->fetch_row();
    SettingsReview25::eq($row, [$t->day(1), $t->day(16)], 'Duration starts at reopening and skips the return holiday');
    SettingsReview25::eq($outcome['due_date'], $row[1], 'NCIP returns the same due date as the stored request');
};

$cases['a mixed-branch title waits until at least one lending branch reopens'] = static function (SettingsReview25 $t): void {
    $b = $t->book($t->branchA);
    $copyB = matrixCopy($t, $b, $t->branchB);
    $day = $t->day(2);
    matrixClosure($t, $day, $t->day(4), $t->branchA);
    matrixHoliday($t, $day, 1, $t->branchB);
    $firstOpen = $t->day(3);
    $availability = (new ReservationsController($t->db))->getBookAvailabilityData($b['id'], $day, 4);
    SettingsReview25::eq($availability['earliest_available'], $firstOpen, 'No site can serve the request on the first day');
    SettingsReview25::check(in_array($day, $availability['closed_dates'], true), 'Overlapping branch closures are presented as closed');
    SettingsReview25::eq((new CapacityService($t->db))->firstAvailableDate($b['id'], $day), $firstOpen, 'The copy-available estimate follows the same branch scope');
    SettingsReview25::eq(LibraryCalendar::reservationDeadline($t->db, $day . ' 18:00:00', $b['id']), $firstOpen . ' 23:59:59', 'A deadline extends until one branch can accept it');

    $copyless = matrixLoan($t, $b, null, $t->today, $day);
    matrixCalendarEnd((string) (new IcsGenerator($t->db))->generateForLoan($copyless), 'loan-' . $copyless . '@pinakes', $t->day(4));
    SettingsReview25::eq((new CapacityService($t->db))->occupiedCount($b['id'], $firstOpen, $firstOpen), 1, 'A copyless booking also occupies title capacity until an actual lending branch reopens');
    $t->db->query("DELETE FROM prestiti WHERE id={$copyless}");
    $t->loan($b, $t->day(6), ['copy' => $copyB, 'start' => $firstOpen, 'status' => 'prenotato']);
    SettingsReview25::eq((new CapacityService($t->db))->firstAvailableDate($b['id'], $day), $t->day(5), 'The open branch must also have an unoccupied copy');
};

$cases['a pinned booking tries a sibling when the original copy is promised on reopening'] = static function (SettingsReview25 $t): void {
    $b = $t->book($t->branchA);
    $sibling = matrixCopy($t, $b, $t->branchB);
    $loan = matrixLoan($t, $b, $b['copies'][0], $t->today, $t->day(4));
    $t->loan($b, $t->day(8), ['copy' => $b['copies'][0], 'reader' => $t->secondReader, 'start' => $t->day(5), 'status' => 'prenotato']);
    matrixHoliday($t, $t->day(4), 1, $t->branchA);
    SettingsReview25::eq((new MaintenanceService($t->db))->activateScheduledLoans(), 1, 'A compatible sibling keeps the pickup valid');
    SettingsReview25::eq((int) $t->scalar("SELECT copia_id FROM prestiti WHERE id={$loan}"), $sibling, 'The previously pinned copy is protected for the next reader');
};

$passed = $failed = 0;
$suite = null;
$savedSession = $_SESSION ?? [];
try {
    SettingsReview25::eq(count($cases), 100, 'There must be exactly 100 distinct acceptance cases');
    $selected = array_filter(explode(',', getenv('RESERVATION_CASE') ?: ''));
    SettingsReview25::check(array_diff($selected, range(1, 100)) === [], 'RESERVATION_CASE must contain existing case numbers');
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
    LibraryCalendar::reset();
    $suite?->close();
}
echo "Reservation closures: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

<?php
declare(strict_types=1);

/**
 * The calendar gaps left open in 0.9.0-rc.3, closed for 0.9.0.
 *
 * Uses the sandbox of tests/settings-pr-independent-25.unit.php (its own
 * random database with the real schema and triggers, two MySQL sessions).
 * Expected dates come from the stated examples, not from LibraryCalendar.
 * Run: php tests/calendar-090-final-gaps.unit.php
 */

use App\Controllers\Admin\LibraryCalendarController;
use App\Controllers\ReservationManager;
use App\Support\ActivityLog;
use App\Support\ConfigStore;
use App\Support\LibraryCalendar;
use App\Support\NotificationService;
use Slim\Psr7\Response;

require __DIR__ . '/settings-pr-independent-25.unit.php';

/** A second copy of $book in $branch; returns its id. */
function finalGapsCopy(SettingsReview25 $t, array $book, int $branch): int
{
    $t->db->query("INSERT INTO copie (libro_id,numero_inventario,stato,sede_id) VALUES ({$book['id']},'FG-{$book['id']}-" . bin2hex(random_bytes(3)) . "','disponibile',{$branch})");
    $copy = (int) $t->db->insert_id;
    $t->db->query("UPDATE libri SET copie_totali = copie_totali + 1, copie_disponibili = copie_disponibili + 1 WHERE id={$book['id']}");
    return $copy;
}

function finalGapsReserve(SettingsReview25 $t, array $book, int $reader, string $start, string $end, int $queue = 1): int
{
    $t->db->query("INSERT INTO prenotazioni (libro_id,utente_id,data_inizio_richiesta,data_fine_richiesta,stato,queue_position) VALUES ({$book['id']},{$reader},'{$start}','{$end}','attiva',{$queue})");
    return (int) $t->db->insert_id;
}

$cases = [
    'promotion ends on an open day of the branch of the copy it takes' => static function (SettingsReview25 $t): void {
        // Copies in two branches: branch A is closed on day 10. Copy B is out,
        // so the reservation (today .. day 10) takes copy A.
        $book = $t->book($t->branchA);
        $copyB = finalGapsCopy($t, $book, $t->branchB);
        $closure = $t->closure($t->day(10), branch: $t->branchA);
        $t->loan($book, $t->day(20), ['copy' => $copyB, 'reader' => $t->secondReader, 'start' => $t->day(-5)]);
        $reservationId = finalGapsReserve($t, $book, $t->reader, $t->today, $t->day(10));
        SettingsReview25::check((new ReservationManager($t->db))->processBookAvailability($book['id']), 'The reservation is promoted');
        $loan = $t->db->query("SELECT id, copia_id, data_scadenza FROM prestiti WHERE libro_id={$book['id']} AND utente_id={$t->reader}")->fetch_assoc();
        SettingsReview25::eq([(int) $loan['copia_id'], (string) $loan['data_scadenza']], [$book['copies'][0], $t->day(11)], 'The loan on the branch-A copy ends on day 11, the next open day there');

        // The e-mail, sent now or retried later, names that day, also after
        // the closure is taken away (the stored date does not move back).
        $notice = new ReflectionMethod(ReservationManager::class, 'noticeDueDate');
        $row = $t->db->query("SELECT * FROM prenotazioni WHERE id={$reservationId}")->fetch_assoc();
        $t->asAdmin();
        (new LibraryCalendarController())->delete($t->request('/admin/calendar/closures/' . $closure), new Response(), $t->db, $closure);
        LibraryCalendar::reset();
        SettingsReview25::eq($t->due((int) $loan['id']), $t->day(11), 'Removing the closure keeps the stored day');
        SettingsReview25::eq($notice->invoke(new ReservationManager($t->db), $row), $t->day(11), 'A retried e-mail names the loan\'s day, not the requested one');
        SettingsReview25::eq($notice->invoke(new ReservationManager($t->db), $row + ['prestito_id' => (int) $loan['id']]), $t->day(11), 'The first e-mail names the same day');
    },
    'promotion keeps the requested end when the copy is promised on the added day' => static function (SettingsReview25 $t): void {
        $book = $t->book($t->branchA);
        $copyB = finalGapsCopy($t, $book, $t->branchB);
        $t->closure($t->day(10), branch: $t->branchA);
        $t->loan($book, $t->day(20), ['copy' => $copyB, 'reader' => $t->secondReader, 'start' => $t->day(-5)]);
        // A third copy (branch B) is out until day 8: it cannot be taken, but
        // it is free on day 11, so the book as a whole has room on that day.
        $copyC = finalGapsCopy($t, $book, $t->branchB);
        $t->loan($book, $t->day(8), ['copy' => $copyC, 'reader' => $t->patron(), 'start' => $t->day(-2)]);
        // Copy A itself is promised to another reader from day 11.
        $third = $t->patron();
        $t->loan($book, $t->day(15), ['copy' => $book['copies'][0], 'reader' => $third, 'start' => $t->day(11), 'status' => 'prenotato']);
        SettingsReview25::check((new \App\Services\CapacityService($t->db))->hasFreeCapacity($book['id'], $t->today, $t->day(11)), 'The book has room through day 11: only the copy decides');
        finalGapsReserve($t, $book, $t->reader, $t->today, $t->day(10));
        SettingsReview25::check((new ReservationManager($t->db))->processBookAvailability($book['id']), 'The reservation is promoted');
        $loan = $t->db->query("SELECT copia_id, data_scadenza FROM prestiti WHERE libro_id={$book['id']} AND utente_id={$t->reader}")->fetch_assoc();
        SettingsReview25::eq([(int) $loan['copia_id'], (string) $loan['data_scadenza']], [$book['copies'][0], $t->day(10)], 'The loan takes copy A and ends on day 10: day 11 belongs to that copy\'s next reader');
    },
    'a refused move re-arms the due-soon e-mail once, for the new day' => static function (SettingsReview25 $t): void {
        SettingsReview25::check(LibraryCalendar::hasWarningSentFor($t->db), 'The install schema has prestiti.warning_sent_for');
        $book = $t->book($t->branchA);
        $loan = $t->loan($book, $t->day(5));
        $t->db->query("UPDATE prestiti SET warning_sent = 1 WHERE id={$loan}");
        // Another reader holds the book from day 6: the move to day 6 is refused.
        finalGapsReserve($t, $book, $t->secondReader, $t->day(6), $t->day(9));
        $t->closure($t->day(5), branch: $t->branchA);
        SettingsReview25::eq($t->due($loan), $t->day(5), 'The move is refused and the stored date stays');
        SettingsReview25::eq((int) $t->scalar("SELECT warning_sent FROM prestiti WHERE id={$loan}"), 0, 'The reminder sent for day 5 is re-armed: the reader returns on day 6 now');
        // The reminder goes out again for day 6; a later save must not repeat it.
        $t->db->query("UPDATE prestiti SET warning_sent = 1, warning_sent_for = '{$t->day(6)}' WHERE id={$loan}");
        $t->closure($t->day(30), branch: $t->branchA);
        SettingsReview25::eq((int) $t->scalar("SELECT warning_sent FROM prestiti WHERE id={$loan}"), 1, 'A later save with the same usable day does not re-arm it');

        // The sender records the day it announces.
        $silent = $t->patron();
        $t->db->query("UPDATE utenti SET email = '' WHERE id={$silent}");
        $soon = $t->loan($t->book(), $t->day(2), ['reader' => $silent]);
        ConfigStore::clearCache();
        (new NotificationService($t->db))->sendLoanExpirationWarnings();
        SettingsReview25::eq([(int) $t->scalar("SELECT warning_sent FROM prestiti WHERE id={$soon}"), (string) $t->scalar("SELECT warning_sent_for FROM prestiti WHERE id={$soon}")], [1, $t->day(2)], 'The due-soon reminder stores the day it names');
    },
    'a move that succeeds later keeps a reminder that already named the new day' => static function (SettingsReview25 $t): void {
        $book = $t->book($t->branchA);
        $loan = $t->loan($book, $t->day(5));
        $reservation = finalGapsReserve($t, $book, $t->secondReader, $t->day(6), $t->day(9));
        $t->closure($t->day(5), branch: $t->branchA);
        SettingsReview25::eq($t->due($loan), $t->day(5), 'The move is refused while day 6 is promised');
        // The reminder went out for the usable day, 6.
        $t->db->query("UPDATE prestiti SET warning_sent = 1, warning_sent_for = '{$t->day(6)}' WHERE id={$loan}");
        // Another loan of the branch, warned for its stored day 5.
        $other = $t->loan($t->book($t->branchA), $t->day(5), ['reader' => $t->patron()]);
        $t->db->query("UPDATE prestiti SET warning_sent = 1, warning_sent_for = '{$t->day(5)}' WHERE id={$other}");
        $t->db->query("UPDATE prenotazioni SET stato = 'annullata' WHERE id={$reservation}");
        $t->closure($t->day(30), branch: $t->branchA);
        SettingsReview25::eq($t->due($loan), $t->day(6), 'The next save moves the loan to day 6');
        SettingsReview25::eq([(int) $t->scalar("SELECT warning_sent FROM prestiti WHERE id={$loan}"), (string) $t->scalar("SELECT warning_sent_for FROM prestiti WHERE id={$loan}")], [1, $t->day(6)], 'The reminder that named day 6 stays sent: no second e-mail for the same day');
        SettingsReview25::eq([$t->due($other), (int) $t->scalar("SELECT warning_sent FROM prestiti WHERE id={$other}")], [$t->day(6), 0], 'A reminder that named day 5 is re-armed when its loan moves');
    },
    'removing the closure re-arms a reminder that named the later day' => static function (SettingsReview25 $t): void {
        $book = $t->book($t->branchA);
        $loan = $t->loan($book, $t->day(5));
        finalGapsReserve($t, $book, $t->secondReader, $t->day(6), $t->day(9));
        $late = $t->closure($t->day(30), branch: $t->branchA);
        $closure = $t->closure($t->day(5), branch: $t->branchA);
        $t->db->query("UPDATE prestiti SET warning_sent = 1, warning_sent_for = '{$t->day(6)}' WHERE id={$loan}");
        $t->asAdmin();
        (new LibraryCalendarController())->delete($t->request('/admin/calendar/closures/' . $closure), new Response(), $t->db, $closure);
        SettingsReview25::eq([$t->due($loan), (int) $t->scalar("SELECT warning_sent FROM prestiti WHERE id={$loan}")], [$t->day(5), 0], 'The reader returns on day 5 again: the reminder for day 6 is re-armed');

        // The same with the last closure removed (no calendar rule left).
        $closure = $t->closure($t->day(5), branch: $t->branchA);
        $t->db->query("UPDATE prestiti SET warning_sent = 1, warning_sent_for = '{$t->day(6)}' WHERE id={$loan}");
        $t->asAdmin();
        (new LibraryCalendarController())->delete($t->request('/admin/calendar/closures/' . $late), new Response(), $t->db, $late);
        SettingsReview25::eq((int) $t->scalar("SELECT warning_sent FROM prestiti WHERE id={$loan}"), 1, 'Removing another closure leaves the usable day 6 and the reminder as sent');
        $t->asAdmin();
        (new LibraryCalendarController())->delete($t->request('/admin/calendar/closures/' . $closure), new Response(), $t->db, $closure);
        SettingsReview25::eq((int) $t->scalar("SELECT COUNT(*) FROM calendario_chiusure WHERE sede_id={$t->branchA}"), 0, 'No closure is left');
        SettingsReview25::eq((int) $t->scalar("SELECT warning_sent FROM prestiti WHERE id={$loan}"), 0, 'With no rule left the reminder for day 6 is re-armed too');
    },
    'a move refused by the overlap trigger re-arms the reminder' => static function (SettingsReview25 $t): void {
        // Two copies in branch A: the book has room on day 6, but copy A is
        // promised to another reader from day 6, so the trigger refuses.
        $book = $t->book($t->branchA);
        finalGapsCopy($t, $book, $t->branchA);
        $loan = $t->loan($book, $t->day(5));
        $t->loan($book, $t->day(15), ['copy' => $book['copies'][0], 'reader' => $t->patron(), 'start' => $t->day(6), 'status' => 'prenotato']);
        $t->db->query("UPDATE prestiti SET warning_sent = 1, warning_sent_for = '{$t->day(5)}' WHERE id={$loan}");
        $t->closure($t->day(5), branch: $t->branchA);
        SettingsReview25::eq($t->due($loan), $t->day(5), 'The trigger keeps the stored date');
        SettingsReview25::eq((int) $t->scalar("SELECT warning_sent FROM prestiti WHERE id={$loan}"), 0, 'The reminder for day 5 is re-armed: the reader returns on day 6');
    },
    'a failed send reverts only its own claim' => static function (SettingsReview25 $t): void {
        $loan = $t->loan($t->book(), $t->day(2), ['reader' => $t->patron()]);
        $service = new NotificationService($t->db);
        $calls = 0;
        $fake = new class ($t->db, static function () use ($t, $loan, &$calls): void {
            $calls++;
            // While this send runs, a calendar save re-arms the reminder and
            // another run claims it for day 3.
            $t->other->query("UPDATE prestiti SET warning_sent = 1, warning_sent_for = '{$t->day(3)}' WHERE id={$loan}");
        }) extends \App\Support\EmailService {
            public function __construct(mysqli $db, private Closure $during)
            {
                parent::__construct($db);
            }
            public function sendTemplate(string $to, string $templateName, array $variables = [], ?string $locale = null, array $attachments = []): bool
            {
                ($this->during)();
                return false;
            }
        };
        (new ReflectionProperty(NotificationService::class, 'emailService'))->setValue($service, $fake);
        $reachable = new ReflectionProperty(\App\Support\Mailer::class, 'smtpReachable');
        $wasReachable = $reachable->getValue();
        $reachable->setValue(null, true);
        ConfigStore::clearCache();
        try {
            $service->sendLoanExpirationWarnings();
        } finally {
            $reachable->setValue(null, $wasReachable);
        }
        SettingsReview25::check($calls > 0, 'The e-mail was attempted and failed');
        SettingsReview25::eq([(int) $t->scalar("SELECT warning_sent FROM prestiti WHERE id={$loan}"), (string) $t->scalar("SELECT warning_sent_for FROM prestiti WHERE id={$loan}")], [1, $t->day(3)], 'The claim for day 3 made meanwhile is kept');
    },
    'a refused warning_sent_for column does not stop the other reminders' => static function (SettingsReview25 $t): void {
        $t->other->query('ALTER TABLE prestiti DROP COLUMN warning_sent_for');
        LibraryCalendar::reset();
        try {
            $t->db->refuse = ['ADD COLUMN warning_sent_for'];
            SettingsReview25::check((new NotificationService($t->db))->addNotificationColumns(), 'The notification schema is reported ready without the optional column');
            SettingsReview25::check(!LibraryCalendar::hasWarningSentFor($t->db), 'The reminders run without warning_sent_for');
        } finally {
            $t->db->refuse = [];
            $t->other->query('ALTER TABLE prestiti ADD COLUMN warning_sent_for date DEFAULT NULL AFTER warning_sent');
            LibraryCalendar::reset();
        }
    },
    'a retried e-mail finds the loan after its dates were changed' => static function (SettingsReview25 $t): void {
        $book = $t->book($t->branchA);
        $reservationId = finalGapsReserve($t, $book, $t->reader, $t->today, $t->day(10));
        SettingsReview25::check((new ReservationManager($t->db))->processBookAvailability($book['id']), 'The reservation is promoted');
        $loan = (int) $t->scalar("SELECT id FROM prestiti WHERE libro_id={$book['id']} AND utente_id={$t->reader}");
        // Staff moves the loan's dates before the retry.
        $t->db->query("UPDATE prestiti SET data_prestito = '{$t->day(1)}', data_scadenza = '{$t->day(12)}' WHERE id={$loan}");
        $row = $t->db->query("SELECT * FROM prenotazioni WHERE id={$reservationId}")->fetch_assoc();
        $notice = new ReflectionMethod(ReservationManager::class, 'noticeDueDate');
        SettingsReview25::eq($notice->invoke(new ReservationManager($t->db), $row), $t->day(12), 'The retry names the loan\'s current day, not the requested one');
    },
    'a loan whose copy is replaced by one in a closed branch is still moved' => static function (SettingsReview25 $t): void {
        // The loan's copy sits in branch A, open on day 5; the book has a
        // copy in branch B, closed on day 5. The copy is replaced while the
        // calendar save waits for the book lock.
        $book = $t->book($t->branchA);
        $copyB = finalGapsCopy($t, $book, $t->branchB);
        $loan = $t->loan($book, $t->day(5));
        $swapped = false;
        $t->db->beforeBookLock = static function () use ($t, $loan, $copyB, &$swapped): void {
            $t->other->query("UPDATE prestiti SET copia_id={$copyB} WHERE id={$loan}");
            $swapped = true;
        };
        $t->closure($t->day(5), branch: $t->branchB);
        SettingsReview25::check($swapped, 'The loan reaches the locked re-read, where the copy is replaced');
        SettingsReview25::eq($t->due($loan), $t->day(6), 'Its new branch is closed on day 5: the loan moves to day 6');
    },
    'the history of a move records the loan as it was under the lock' => static function (SettingsReview25 $t): void {
        $book = $t->book($t->branchA);
        $loan = $t->loan($book, $t->day(5));
        $t->db->query("UPDATE prestiti SET note='desk note before' WHERE id={$loan}");
        // The calendar save runs in a transaction whose snapshot predates a desk edit.
        $t->db->begin_transaction();
        $t->scalar("SELECT note FROM prestiti WHERE id={$loan}");
        $t->other->query("UPDATE prestiti SET note='desk note after' WHERE id={$loan}");
        $t->db->query("INSERT INTO calendario_chiusure (data_inizio,data_fine,descrizione,sede_id,attiva) VALUES ('{$t->day(5)}','{$t->day(5)}','Final gaps',{$t->branchA},1)");
        LibraryCalendar::reset();
        LibraryCalendar::extendDueDates($t->db, ActivityLog::SYSTEM_OPERATOR);
        $t->db->commit();
        SettingsReview25::eq($t->due($loan), $t->day(6), 'The loan moves to day 6');
        $before = (string) $t->scalar("SELECT JSON_UNQUOTE(JSON_EXTRACT(dati_precedenti,'$.note')) FROM log_modifiche WHERE tabella='libri' AND record_id={$book['id']}
            AND JSON_UNQUOTE(JSON_EXTRACT(dati_nuovi,'$._activity.event'))='loan.due_moved' ORDER BY id DESC LIMIT 1");
        SettingsReview25::eq($before, 'desk note after', 'The before state is the current row, not the older snapshot');
    },
];

$suite = null; $passed = 0; $failed = 0; $savedSession = $_SESSION ?? [];
try {
    $suite = new SettingsReview25();
    ConfigStore::usingConnection($suite->db, static function () use ($suite, $cases, &$passed, &$failed): void {
        foreach ($cases as $name => $case) {
            try {
                $suite->reset(); $case($suite); $passed++;
                echo 'PASS ' . $name . PHP_EOL;
            } catch (Throwable $e) {
                $failed++;
                echo 'FAIL ' . $name . ': ' . $e->getMessage() . PHP_EOL;
            }
        }
    });
} catch (Throwable $e) {
    $failed++; fwrite(STDERR, 'FAIL setup: ' . $e->getMessage() . PHP_EOL);
} finally {
    $_SESSION = $savedSession;
    LibraryCalendar::reset();
    if ($suite !== null) { $suite->close(); }
}
echo "Calendar 0.9.0 final gaps: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

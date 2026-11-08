<?php
declare(strict_types=1);

/**
 * Review follow-ups to the closures acceptance matrix, on the same isolated
 * schema: a sibling copy keeps the return day the reader was promised, an
 * inverted window is refused with or without calendar rules, and the request
 * calendar ends a copyless booking where capacity does.
 */
use App\Controllers\ReservationsController;
use App\Services\CapacityService;
use App\Support\ConfigStore;
use App\Support\LibraryCalendar;
use App\Support\MaintenanceService;

require __DIR__ . '/settings-pr-independent-25.unit.php';

function reviewHoliday(SettingsReview25 $t, string $start, int $days, ?int $branch = null): void
{
    $stmt = $t->db->prepare("INSERT INTO festivita_personalizzate (nome,tipo,date_specifiche,durata_giorni,sede_id,attiva) VALUES ('Review holiday','dates',?,?,?,1)");
    $stmt->bind_param('sii', $start, $days, $branch);
    $stmt->execute();
    $stmt->close();
    LibraryCalendar::reset();
}

function reviewLoan(SettingsReview25 $t, array $book, ?int $copy, string $start, string $end): int
{
    $stmt = $t->db->prepare("INSERT INTO prestiti (libro_id,copia_id,utente_id,data_prestito,data_scadenza,stato,origine,attivo) VALUES (?,?,?,?,?,'prenotato','richiesta',1)");
    $stmt->bind_param('iiiss', $book['id'], $copy, $t->reader, $start, $end);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function reviewCopy(SettingsReview25 $t, array $book, int $branch): int
{
    $t->db->query("INSERT INTO copie (libro_id,numero_inventario,stato,sede_id) VALUES ({$book['id']},'Review-{$book['id']}-{$branch}','disponibile',{$branch})");
    return (int) $t->db->insert_id;
}

$cases = [];
$cases['a sibling copy in another branch keeps the promised return day'] = static function (SettingsReview25 $t): void {
    $b = $t->book($t->branchA);
    $sibling = reviewCopy($t, $b, $t->branchB);
    $loan = reviewLoan($t, $b, $b['copies'][0], $t->today, $t->day(4));
    $t->loan($b, $t->day(8), ['copy' => $b['copies'][0], 'reader' => $t->secondReader, 'start' => $t->day(5), 'status' => 'prenotato']);
    reviewHoliday($t, $t->day(4), 1, $t->branchA);
    SettingsReview25::eq(LibraryCalendar::dueDate($t->db, $t->day(4), $b['copies'][0]), $t->day(5), 'The reader was promised the reopening day');
    SettingsReview25::eq((new MaintenanceService($t->db))->activateScheduledLoans(), 1, 'The sibling activates the booking');
    $row = $t->db->query("SELECT copia_id, data_scadenza FROM prestiti WHERE id={$loan}")->fetch_row();
    SettingsReview25::eq([(int) $row[0], $row[1]], [$sibling, $t->day(5)], 'The sibling stores the promised return day, not its own earlier one');
};
$cases['an inverted window is refused also without calendar rules'] = static function (SettingsReview25 $t): void {
    $b = $t->book($t->branchA);
    $capacity = new CapacityService($t->db);
    SettingsReview25::check(!LibraryCalendar::forDb($t->db)->hasRules(), 'The fixture has no calendar rules');
    SettingsReview25::eq($capacity->reservationDueDateForCopy($b['id'], $b['copies'][0], $t->day(3), $t->day(1)), null, 'A return day before the start is unusable');
    SettingsReview25::eq($capacity->reservationDueDateForCopy($b['id'], $b['copies'][0], $t->day(1), $t->day(3)), $t->day(3), 'A valid window keeps its stored return day');
};
$cases['the request calendar ends a copyless booking where capacity does'] = static function (SettingsReview25 $t): void {
    $b = $t->book($t->branchA);
    reviewLoan($t, $b, null, $t->day(1), $t->day(3));
    reviewHoliday($t, $t->day(3), 1, $t->branchA);
    $capacityHeld = (new CapacityService($t->db))->occupiedCount($b['id'], $t->day(4), $t->day(4));
    SettingsReview25::eq($capacityHeld, 1, 'Capacity holds the only copy until the lending branch reopens');
    $view = (new ReservationsController($t->db))->getBookAvailabilityData($b['id'], $t->today, 8);
    SettingsReview25::eq($view['by_date'][$t->day(4)]['available'], 0, 'The calendar does not offer the day capacity refuses');
};

$passed = $failed = 0;
$suite = null;
$savedSession = $_SESSION ?? [];
try {
    $suite = new SettingsReview25();
    ConfigStore::usingConnection($suite->db, static function () use ($suite, $cases, &$passed, &$failed): void {
        foreach ($cases as $name => $case) {
            try {
                $suite->reset();
                $case($suite);
                $passed++;
                echo "PASS {$name}\n";
            } catch (Throwable $error) {
                $failed++;
                echo "FAIL {$name}: {$error->getMessage()}\n";
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
echo "Reservation closures review: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

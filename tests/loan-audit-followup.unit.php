<?php
declare(strict_types=1);

use App\Controllers\Admin\LibraryCalendarController;
use App\Controllers\LoanApprovalController;
use App\Controllers\ReservationManager;
use App\Controllers\ReservationsAdminController;
use App\Controllers\ReservationsController;
use App\Support\ConfigStore;
use App\Support\DataIntegrity;
use App\Support\LibraryCalendar;
use App\Support\NotificationService;
use Slim\Psr7\Response;

require __DIR__ . '/settings-pr-independent-25.unit.php';

$removeClosure = static function (SettingsReview25 $t, int $id): void {
    unset($_SESSION['error_message']);
    (new LibraryCalendarController())->delete(
        $t->request('/admin/calendar/closures/' . $id . '/delete', ['undo_extensions' => '1']),
        new Response(),
        $t->db,
        $id
    );
};

$cases = [
    'undoing overlapping erroneous closures keeps the original due date' => static function (SettingsReview25 $t) use ($removeClosure): void {
        $loan = $t->loan($t->book(), $t->day(5));
        $first = $t->closure($t->day(5), $t->day(6));
        $last = $t->closure($t->day(6), $t->day(10));
        SettingsReview25::eq($t->due($loan), $t->day(11), 'The two closures extend the loan');
        $removeClosure($t, $last);
        SettingsReview25::eq($t->due($loan), $t->day(7), 'The first closure still applies');
        $removeClosure($t, $first);
        SettingsReview25::eq($t->due($loan), $t->day(5), 'The second undo recovers the original due day');
    },
    'undo skips a loan whose new start is after the original due date' => static function (SettingsReview25 $t) use ($removeClosure): void {
        $loan = $t->loan($t->book(), $t->day(5), ['start' => $t->day(5), 'status' => 'prenotato']);
        $closure = $t->closure($t->day(5), $t->day(6));
        SettingsReview25::eq($t->due($loan), $t->day(7), 'The calendar extended the scheduled loan');
        $t->db->query("UPDATE prestiti SET data_prestito='{$t->day(7)}' WHERE id={$loan}");
        $removeClosure($t, $closure);
        SettingsReview25::eq((int) $t->scalar("SELECT COUNT(*) FROM calendario_chiusure WHERE id={$closure}"), 0, 'The closure is removed');
        SettingsReview25::eq($t->due($loan), $t->day(7), 'The updated start and end remain valid');
    },
    'undo does not commit a date after a MariaDB snapshot conflict' => static function (SettingsReview25 $t) use ($removeClosure): void {
        $isolation = $t->db->query("SHOW VARIABLES LIKE 'innodb_snapshot_isolation'")->fetch_row();
        $snapshotIsolation = $isolation !== null && strcasecmp((string) $isolation[1], 'ON') === 0;
        $loan = $t->loan($t->book(), $t->day(5));
        $closure = $t->closure($t->day(5));
        $t->db->beforeBookLock = static function () use ($t, $loan): void {
            $t->other->query("UPDATE prestiti SET note='updated at the desk' WHERE id={$loan}");
        };
        $removeClosure($t, $closure);
        SettingsReview25::eq($t->due($loan), $t->day($snapshotIsolation ? 6 : 5), 'The due date only changes with the closure');
        SettingsReview25::eq((int) $t->scalar("SELECT COUNT(*) FROM calendario_chiusure WHERE id={$closure}"), (int) $snapshotIsolation, 'The transaction keeps or removes the closure together with the due date');
        SettingsReview25::eq($t->scalar("SELECT note FROM prestiti WHERE id={$loan}"), 'updated at the desk', 'The concurrent edit survives');
    },
    'approval can use a copy at a later reopening even with an unlocated sibling' => static function (SettingsReview25 $t): void {
        $book = $t->book(copies: 2);
        [$unlocated, $reopened] = $book['copies'];
        $t->db->query("UPDATE copie SET sede_id={$t->branchB} WHERE id={$reopened}");
        $t->closure($t->day(-1), branch: $t->branchB);
        $t->db->query("INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, attivo)
            VALUES ({$book['id']}, {$t->reader}, '{$t->day(-2)}', '{$t->day(-1)}', 'pendente', 0)");
        $id = $t->db->insert_id;
        $result = (new LoanApprovalController())->approveLoan($t->request('/admin/loans/approve', ['loan_id' => $id]), new Response(), $t->db);
        SettingsReview25::eq($result->getStatusCode(), 200, 'The request can be approved');
        SettingsReview25::eq((int) $t->scalar("SELECT copia_id FROM prestiti WHERE id={$id}"), $reopened, 'The unlocated copy was due yesterday; the reopened branch is still usable');
    },
    'the reader and staff can save the same queue slot before the overdue sweep' => static function (SettingsReview25 $t): void {
        $book = $t->book();
        $t->loan($book, $t->day(-1), ['reader' => $t->secondReader]);
        $t->closure($t->day(30)); // Enable calendar-aware overdue checks.
        $reader = new ReservationsController($t->db);
        $start = $t->day(1);
        $availability = $reader->getBookAvailabilityData($book['id'], $t->today, 5);
        SettingsReview25::eq($availability['by_date'][$start]['state'], 'waitlist', 'Tomorrow is offered for the queue');
        SettingsReview25::check(in_array($start, $availability['unavailable_dates'], true), 'Tomorrow still has no physical copy');
        $_SESSION['user'] = ['id' => $t->reader, 'tipo_utente' => 'standard', 'stato' => 'attivo'];
        $response = $reader->createReservation($t->request('/reservation', ['start_date' => $start, 'end_date' => $t->day(3)]), new Response(), ['id' => $book['id']]);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        SettingsReview25::eq($payload['status'] ?? null, 'reserved', 'The POST saves a reservation rather than promising the copy');
        $t->asAdmin();
        $edit = (new ReservationsAdminController())->update($t->request('/admin/reservations/update', [
            'stato' => 'attiva', 'data_prenotazione' => $start, 'data_scadenza_prenotazione' => $t->day(3),
        ]), new Response(), $t->db, (int) $payload['reservation_id']);
        SettingsReview25::check(str_contains($edit->getHeaderLine('Location'), 'updated=1'), 'Staff can save the same dates');
        $issues = array_column((new DataIntegrity($t->db))->verifyDataConsistency(), 'type');
        SettingsReview25::check(!in_array('overbooked_circulation_period', $issues, true), 'The integrity audit also accepts a valid future queue slot');
    },
    'retry uses the actual promotion and ignores a manual completion of the same title' => static function (SettingsReview25 $t): void {
        $book = $t->book();
        $t->db->query("INSERT INTO prenotazioni (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta, stato)
            VALUES ({$book['id']}, {$t->reader}, '{$t->today}', '{$t->day(5)}', 'completata'),
                   ({$book['id']}, {$t->reader}, '{$t->today}', '{$t->day(8)}', 'attiva')");
        $manual = $t->db->insert_id;
        $service = new class ($t->db) extends NotificationService {
            /** @var list<string> */
            public array $dueDates = [];
            public function sendReservationBookAvailable(string $email, array $variables): bool
            {
                $this->dueDates[] = (string) $variables['data_fine'];
                return true;
            }
        };
        $manager = new class ($t->db, $service) extends ReservationManager {
            public function __construct(mysqli $db, private NotificationService $fake) { parent::__construct($db); }
            protected function notificationService(): NotificationService { return $this->fake; }
        };
        $manager->setExternalTransaction(true);
        $t->db->begin_transaction();
        SettingsReview25::check($manager->processBookAvailability($book['id']), 'The real reservation promotes');
        $t->db->commit(); // Leave the first notification unsent for the retry.
        SettingsReview25::eq($manager->retryUnsentReservationNotifications(), 1, 'Exactly one notice is sent');
        SettingsReview25::eq((int) $t->scalar("SELECT notifica_inviata FROM prenotazioni WHERE id={$manual}"), 0, 'The manual completion is not notified');
        $email = (string) $t->scalar("SELECT email FROM utenti WHERE id={$t->reader}");
        $date = $service->formatEmailDate($t->day(8), false, $service->resolveRecipientLocale($email));
        SettingsReview25::eq($service->dueDates, [$date], 'The notice uses the promoted loan’s actual due day');
    },
];

$suite = null;
$failed = 0;
try {
    $suite = new SettingsReview25();
    ConfigStore::usingConnection($suite->db, static function () use ($suite, $cases, &$failed): void {
        foreach ($cases as $name => $case) {
            try {
                $suite->reset();
                $case($suite);
                echo "PASS {$name}\n";
            } catch (Throwable $e) {
                $failed++;
                echo "FAIL {$name}: {$e->getMessage()}\n";
            }
        }
    });
} finally {
    LibraryCalendar::reset();
    $suite?->close();
}
exit($failed === 0 ? 0 : 1);

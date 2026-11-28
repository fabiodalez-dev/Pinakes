<?php
declare(strict_types=1);

/**
 * A queue reservation whose requested window is entirely in the past must
 * never become a loan, on any promotion path, and the admin form must not
 * create one.
 *
 * Against the REAL database, MaintenanceService, ReservationManager and
 * ReservationsAdminController:
 *  - one maintenance run with an expired pickup in front of the queue: the
 *    dead reservation is cancelled without a loan row and the next reader is
 *    promoted in the same run;
 *  - the single-book path (every web release path calls it) and the batch
 *    path refuse a dead window on their own, with a copy on the shelf;
 *  - a dead reservation without a stored deadline does not stay in the queue;
 *  - a window that started in the past but ends today or later still promotes;
 *  - closures calendar: a last day that was closed is usable on reopening and
 *    still promotes, while one whose reopening day has also passed is dead;
 *  - ReservationsAdminController::store() refuses a past window with
 *    error=past_dates and starts a still-usable one from today, as update().
 *
 * Dates come from DateHelper::today(); nothing depends on the clock.
 *
 * Run: php tests/reservation-dead-window-promotion.unit.php
 */

use App\Controllers\ReservationManager;
use App\Controllers\ReservationsAdminController;
use App\Support\ConfigStore;
use App\Support\DateHelper;
use App\Support\Features;
use App\Support\LibraryCalendar;
use App\Support\MaintenanceService;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

require dirname(__DIR__) . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
putenv('PINAKES_DISABLE_CLI_PURGE=1');

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
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — the dead-window suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$exit = ConfigStore::usingConnection($db, static function () use ($db): int {
    DateHelper::synchronizeDatabaseSession($db);
    $run = bin2hex(random_bytes(6));
    $prefix = "ZZ_DEADWIN_{$run}";
    $passed = 0;
    $failed = 0;
    $check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
        echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
        $ok ? $passed++ : $failed++;
    };

    // Everything the suite inserts, so cleanup can remove it on any exit.
    $created = new class {
        /** @var list<int> */
        public array $books = [];
        /** @var list<int> */
        public array $users = [];
    };
    $sessionBefore = $_SESSION ?? null;
    $cleanup = static function () use ($db, $created, $prefix, $run, $sessionBefore): void {
        try { $db->rollback(); } catch (Throwable) {}
        $db->query("DELETE FROM calendario_chiusure WHERE descrizione = '{$prefix}'");
        foreach ($created->books as $id) {
            $db->query("DELETE FROM prenotazioni WHERE libro_id = {$id}");
            $db->query("DELETE FROM prestiti WHERE libro_id = {$id}");
            $db->query("DELETE FROM copie WHERE libro_id = {$id}");
            try { $db->query("DELETE FROM log_modifiche WHERE tabella = 'libri' AND record_id = {$id}"); } catch (Throwable) {}
            $db->query("DELETE FROM libri WHERE id = {$id}");
        }
        foreach ($created->users as $id) {
            try { $db->query("DELETE FROM notifications WHERE user_id = {$id}"); } catch (Throwable) {}
            $db->query("DELETE FROM utenti WHERE id = {$id}");
        }
        try { $db->query("DELETE FROM email_delivery_outbox WHERE recipient_email LIKE 'zz-deadwin-%-{$run}@test.local'"); } catch (Throwable) {}
        Features::resetOverrides();
        LibraryCalendar::reset();
        if ($sessionBefore === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $sessionBefore;
        }
    };

    try {
        $makeBook = static function (string $suffix, int $copies) use ($db, $prefix, $created): array {
            $title = "{$prefix}_{$suffix}";
            $stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES (?, ?, ?, 'disponibile')");
            $stmt->bind_param('sii', $title, $copies, $copies);
            $stmt->execute();
            $bookId = (int) $db->insert_id;
            $stmt->close();
            $created->books[] = $bookId;
            $copyIds = [];
            for ($i = 1; $i <= $copies; $i++) {
                $inv = strtoupper("DW{$suffix}{$i}-") . strtoupper(substr($prefix, -8));
                $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
                $stmt->bind_param('is', $bookId, $inv);
                $stmt->execute();
                $copyIds[] = (int) $db->insert_id;
                $stmt->close();
            }
            return [$bookId, $copyIds];
        };
        $makeUser = static function (string $suffix, string $role = 'standard') use ($db, $run, $created): int {
            $email = "zz-deadwin-{$suffix}-{$run}@test.local";
            $card = 'DW' . strtoupper($suffix) . strtoupper(substr($run, 0, 8));
            $password = password_hash('DeadWindow!1', PASSWORD_DEFAULT);
            $surname = ucfirst($suffix);
            $stmt = $db->prepare(
                "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata)
                 VALUES (?, 'Deadwin', ?, ?, ?, ?, 'attivo', 1)"
            );
            $stmt->bind_param('sssss', $card, $surname, $email, $password, $role);
            $stmt->execute();
            $userId = (int) $db->insert_id;
            $stmt->close();
            $created->users[] = $userId;
            return $userId;
        };

        // The base scenarios are those of a library open every day, whatever
        // calendar the database under test happens to hold.
        Features::override('closures_calendar', false);
        LibraryCalendar::reset();

        $today = DateHelper::today();
        $d = static fn (int $offset, ?string $from = null): string =>
            (new DateTimeImmutable($from ?? $today))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');

        // A queue row as the application writes it (both date pairs), or with
        // the requested window only ($deadline = false), as imported rows have.
        $makeReservation = static function (int $book, int $user, int $position, string $start, string $end, bool $deadline = true) use ($db): int {
            if ($deadline) {
                $startDt = $start . ' 00:00:00';
                $endDt = $end . ' 23:59:59';
                $stmt = $db->prepare(
                    "INSERT INTO prenotazioni (libro_id, utente_id, data_prenotazione, data_scadenza_prenotazione, data_inizio_richiesta, data_fine_richiesta, queue_position, stato)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'attiva')"
                );
                $stmt->bind_param('iissssi', $book, $user, $startDt, $endDt, $start, $end, $position);
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO prenotazioni (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta, queue_position, stato)
                     VALUES (?, ?, ?, ?, ?, 'attiva')"
                );
                $stmt->bind_param('iissi', $book, $user, $start, $end, $position);
            }
            $stmt->execute();
            $id = (int) $db->insert_id;
            $stmt->close();
            return $id;
        };
        $reservationState = static fn (int $id): string =>
            (string) $db->query("SELECT stato FROM prenotazioni WHERE id = {$id}")->fetch_row()[0];
        $loansOf = static fn (int $book, int $user): array =>
            $db->query("SELECT id, stato, attivo, copia_id, data_prestito, data_scadenza FROM prestiti WHERE libro_id = {$book} AND utente_id = {$user} ORDER BY id")->fetch_all(MYSQLI_ASSOC);

        // ═════════ A: one maintenance run, expired pickup in front of the queue ═════════
        [$bookA, [$copyA]] = $makeBook('A', 1);
        $holderA = $makeUser('ah');
        $deadReaderA = $makeUser('ar');
        $nextReaderA = $makeUser('av');
        $db->query("UPDATE copie SET stato = 'prenotato' WHERE id = {$copyA}");
        $db->query("UPDATE libri SET copie_disponibili = 0, stato = 'prenotato' WHERE id = {$bookA}");
        $stmt = $db->prepare(
            "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, pickup_deadline)
             VALUES (?, ?, ?, ?, ?, 'da_ritirare', 'diretto', 1, ?)"
        );
        $startH = $d(-5); $endH = $d(9); $deadlineH = $d(-1);
        $stmt->bind_param('iiisss', $bookA, $copyA, $holderA, $startH, $endH, $deadlineH);
        $stmt->execute();
        $pickupA = (int) $db->insert_id;
        $stmt->close();
        $deadA = $makeReservation($bookA, $deadReaderA, 1, $d(-4), $d(-1));
        $nextA = $makeReservation($bookA, $nextReaderA, 2, $d(0), $d(7));

        // ═════════ A2: the same, but the next reader's window started while the dead one ran ═════════
        // The dead reservation still counts for the days both windows share
        // until it is cancelled, so this is the case the order of the sweeps
        // inside one run could break.
        [$bookA2, [$copyA2]] = $makeBook('A2', 1);
        $holderA2 = $makeUser('a2h');
        $deadReaderA2 = $makeUser('a2r');
        $nextReaderA2 = $makeUser('a2v');
        $db->query("UPDATE copie SET stato = 'prenotato' WHERE id = {$copyA2}");
        $db->query("UPDATE libri SET copie_disponibili = 0, stato = 'prenotato' WHERE id = {$bookA2}");
        $stmt = $db->prepare(
            "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, pickup_deadline)
             VALUES (?, ?, ?, ?, ?, 'da_ritirare', 'diretto', 1, ?)"
        );
        $stmt->bind_param('iiisss', $bookA2, $copyA2, $holderA2, $startH, $endH, $deadlineH);
        $stmt->execute();
        $stmt->close();
        $deadA2 = $makeReservation($bookA2, $deadReaderA2, 1, $d(-4), $d(-1));
        $nextA2 = $makeReservation($bookA2, $nextReaderA2, 2, $d(-2), $d(5));

        // ═════════ B: a copy on the shelf and a dead window with no stored deadline ═════════
        [$bookB] = $makeBook('B', 1);
        $deadReaderB = $makeUser('br');
        $deadB = $makeReservation($bookB, $deadReaderB, 1, $d(-4), $d(-1), deadline: false);

        $promotedB = (new ReservationManager($db))->processBookAvailability($bookB);
        $check($promotedB === false, '01 the single-book path (every web release path) does not promote a dead window');
        $check($loansOf($bookB, $deadReaderB) === [], '02 no loan row is written for the dead window');
        (new MaintenanceService($db))->processScheduledReservations();
        $check($loansOf($bookB, $deadReaderB) === [] && $reservationState($deadB) !== 'completata',
            '03 the batch path (processScheduledReservations) does not promote it either');

        // ═════════ C: a past start is not a dead window ═════════
        [$bookC1, [$copyC1]] = $makeBook('C1', 1);
        $readerC1 = $makeUser('c1');
        $aliveC1 = $makeReservation($bookC1, $readerC1, 1, $d(-2), $d(3));
        $check((new ReservationManager($db))->processBookAvailability($bookC1) === true && $reservationState($aliveC1) === 'completata',
            '04 a window that started in the past and ends later still promotes');
        $loanC1 = $loansOf($bookC1, $readerC1);
        $check(count($loanC1) === 1 && $loanC1[0]['stato'] === 'pendente' && (int) $loanC1[0]['copia_id'] === $copyC1
            && $loanC1[0]['data_scadenza'] === $d(3),
            '05 its loan request keeps the requested end');
        [$bookC2] = $makeBook('C2', 1);
        $readerC2 = $makeUser('c2');
        $aliveC2 = $makeReservation($bookC2, $readerC2, 1, $d(-3), $d(0));
        $check((new ReservationManager($db))->processBookAvailability($bookC2) === true && $reservationState($aliveC2) === 'completata',
            '06 a window whose last day is today still promotes');

        // ═════════ A + B through ONE maintenance run ═════════
        $result = (new MaintenanceService($db))->runAll();
        $check(($result['skipped'] ?? false) !== true, '07 the maintenance run was not skipped');
        $check((string) $db->query("SELECT stato FROM prestiti WHERE id = {$pickupA}")->fetch_row()[0] === 'scaduto',
            '08 the unclaimed pickup in front of the queue expired');
        $check($reservationState($deadA) === 'annullata', '09 the reservation whose window is over ends annullata in the same run');
        $check($loansOf($bookA, $deadReaderA) === [], '10 it never got a loan row');
        $check($reservationState($nextA) === 'completata', '11 the next reader is promoted in the same run');
        $loanNextA = $loansOf($bookA, $nextReaderA);
        $check(count($loanNextA) === 1 && $loanNextA[0]['stato'] === 'pendente' && (int) $loanNextA[0]['copia_id'] === $copyA
            && $loanNextA[0]['data_prestito'] === $d(0) && $loanNextA[0]['data_scadenza'] === $d(7),
            '12 the promoted request holds the freed copy for the requested window');
        $check($reservationState($deadB) === 'annullata' && $loansOf($bookB, $deadReaderB) === [],
            '13 a dead window without a stored deadline leaves the queue too, without a loan');
        $loanNextA2 = $loansOf($bookA2, $nextReaderA2);
        $check($reservationState($deadA2) === 'annullata' && $loansOf($bookA2, $deadReaderA2) === []
            && $reservationState($nextA2) === 'completata' && count($loanNextA2) === 1
            && (int) $loanNextA2[0]['copia_id'] === $copyA2 && $loanNextA2[0]['data_scadenza'] === $d(5),
            '13b the same run serves a next reader whose window overlaps the dead one');

        // ═════════ D: closures calendar ═════════
        Features::override('closures_calendar', true);
        $closure = static function (string $from, string $to) use ($db, $prefix): void {
            $stmt = $db->prepare('INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione, ricorrente_annuale, sede_id) VALUES (?, ?, ?, 0, NULL)');
            $stmt->bind_param('sss', $from, $to, $prefix);
            $stmt->execute();
            $stmt->close();
            LibraryCalendar::reset();
        };
        // The last requested day was closed: the reader can still use the
        // reservation on reopening, which is today at the earliest.
        $closure($d(-1), $d(-1));
        [$bookD1] = $makeBook('D1', 1);
        $readerD1 = $makeUser('d1');
        $closedLastDay = $makeReservation($bookD1, $readerD1, 1, $d(-4), $d(-1));
        $check((new ReservationManager($db))->processBookAvailability($bookD1) === true && $reservationState($closedLastDay) === 'completata',
            '14 a window whose last day fell on a closed day still promotes on reopening');
        $loanD1 = $loansOf($bookD1, $readerD1);
        $check(count($loanD1) === 1 && $loanD1[0]['data_scadenza'] >= $today, '15 its loan request ends on the reopening day, not in the past');

        // The reopening day itself has passed: nothing is left to use.
        $db->query("DELETE FROM calendario_chiusure WHERE descrizione = '{$prefix}'");
        LibraryCalendar::reset();
        $reopening = LibraryCalendar::forDb($db)->previousOpenDay($d(-1));
        $closure($d(-2, $reopening), $d(-1, $reopening));
        [$bookD2] = $makeBook('D2', 1);
        $readerD2 = $makeUser('d2');
        $missedReopening = $makeReservation($bookD2, $readerD2, 1, $d(-5, $reopening), $d(-2, $reopening));
        $check((new ReservationManager($db))->processBookAvailability($bookD2) === false
            && $reservationState($missedReopening) === 'attiva' && $loansOf($bookD2, $readerD2) === [],
            '16 once the reopening day has passed too, the window is dead and is not promoted');
        $db->query("DELETE FROM calendario_chiusure WHERE descrizione = '{$prefix}'");
        Features::override('closures_calendar', false);
        LibraryCalendar::reset();

        // ═════════ E: the admin form (and the Mobile API create that delegates to it) ═════════
        $admin = $makeUser('adm', 'admin');
        $_SESSION = ['user' => ['id' => $admin, 'tipo_utente' => 'admin', 'stato' => 'attivo']];
        $store = static function (array $form) use ($db): string {
            $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/reservations/create')->withParsedBody($form);
            return (new ReservationsAdminController())->store($request, new Response(), $db)->getHeaderLine('Location');
        };
        $reservationsOf = static fn (int $book, int $user): array =>
            $db->query("SELECT id, stato, data_inizio_richiesta, data_fine_richiesta, DATE(data_prenotazione) AS reserved, DATE(data_scadenza_prenotazione) AS expires FROM prenotazioni WHERE libro_id = {$book} AND utente_id = {$user}")->fetch_all(MYSQLI_ASSOC);

        [$bookE1] = $makeBook('E1', 1);
        $readerE1 = $makeUser('e1');
        $location = $store(['libro_id' => (string) $bookE1, 'utente_id' => (string) $readerE1, 'data_prenotazione' => $d(-5), 'data_scadenza' => $d(-1)]);
        $check(str_contains($location, '/admin/reservations/create') && str_contains($location, 'error=past_dates'),
            '17 store() refuses a window that is already over with error=past_dates (got: ' . $location . ')');
        $check($reservationsOf($bookE1, $readerE1) === [] && $loansOf($bookE1, $readerE1) === [],
            '18 nothing is written for the refused window');

        [$bookE2] = $makeBook('E2', 1);
        $readerE2 = $makeUser('e2');
        $location = $store(['libro_id' => (string) $bookE2, 'utente_id' => (string) $readerE2, 'data_inizio_richiesta' => $d(-6), 'data_fine_richiesta' => $d(-2)]);
        $check(str_contains($location, 'error=past_dates') && $reservationsOf($bookE2, $readerE2) === [],
            '19 store() refuses a past requested window posted on its own (got: ' . $location . ')');

        [$bookE3] = $makeBook('E3', 1);
        $readerE3 = $makeUser('e3');
        $location = $store(['libro_id' => (string) $bookE3, 'utente_id' => (string) $readerE3, 'data_prenotazione' => $d(-2), 'data_scadenza' => $d(5)]);
        $rowsE3 = $reservationsOf($bookE3, $readerE3);
        $check(str_contains($location, 'created=1') && count($rowsE3) === 1, '20 store() accepts a window that started in the past and ends later');
        $check(count($rowsE3) === 1 && $rowsE3[0]['data_inizio_richiesta'] === $today && $rowsE3[0]['reserved'] === $today
            && $rowsE3[0]['data_fine_richiesta'] === $d(5) && $rowsE3[0]['expires'] === $d(5),
            '21 and starts it from today, as update() does');

        echo PHP_EOL . "Passed: {$passed}   Failed: {$failed}" . PHP_EOL;
        return $failed === 0 ? 0 : 1;
    } catch (Throwable $e) {
        fwrite(STDERR, 'FAIL: uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . PHP_EOL);
        return 1;
    } finally {
        try { $cleanup(); } catch (Throwable $cleanupError) {
            fwrite(STDERR, 'cleanup: ' . $cleanupError->getMessage() . PHP_EOL);
        }
    }
});

$db->close();
exit($exit);

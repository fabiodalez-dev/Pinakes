<?php
declare(strict_types=1);

/**
 * Calendar/circulation regressions: shifted queue capacity, first-open-day
 * expiry, branch closures, admin dates, retroactive pickup closures, complete
 * holiday coverage and paused reservations in the public calendar.
 * Creates and drops its own database "<DB_NAME>_rescal" (override with
 * RESCAL_SANDBOX_DB), a name the test user's grants already cover.
 * Run: php tests/reservation-calendar-edgecases.unit.php
 */
use App\Controllers\Admin\LibraryCalendarController;
use App\Controllers\LoanApprovalController;
use App\Controllers\ReservationManager;
use App\Controllers\ReservationsAdminController;
use App\Controllers\ReservationsController;
use App\Services\CapacityService;
use App\Support\ConfigStore;
use App\Support\DataIntegrity;
use App\Support\DateHelper;
use App\Support\Features;
use App\Support\LibraryCalendar;
use App\Support\MaintenanceService;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$env = is_file($root . '/.env') ? Dotenv\Dotenv::parse((string) file_get_contents($root . '/.env')) : [];
$args = [
    getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? 'localhost'),
    getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? 'root'),
    getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ''),
    '',
    (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)),
    getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null),
];
$db = new mysqli(...$args);
$db->set_charset('utf8mb4');
$liveDb = (string) (getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''));
$sandbox = (string) (getenv('RESCAL_SANDBOX_DB') ?: ($liveDb !== '' ? $liveDb . '_rescal' : 'pinakes_rescal'));
if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $sandbox) !== 1 || $sandbox === $liveDb || $sandbox === ($env['DB_NAME'] ?? null)) {
    fwrite(STDERR, "FAIL: unsafe sandbox database name '{$sandbox}'\n");
    exit(1);
}
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$post = static fn (array $body) => (new ServerRequestFactory())->createServerRequest('POST', '/')->withParsedBody($body);
$previousSession = $_SESSION ?? [];

try {
    $db->query("DROP DATABASE IF EXISTS `{$sandbox}`");
    $db->query("CREATE DATABASE `{$sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($sandbox);
    $db->multi_query((string) file_get_contents($root . '/installer/database/schema.sql'));
    do {
        if ($result = $db->store_result()) { $result->free(); }
    } while ($db->more_results() && $db->next_result());
    // Load the production overlap triggers, respecting the script's delimiters.
    $delimiter = ';';
    $statement = '';
    foreach (file($root . '/installer/database/triggers.sql') as $line) {
        if (preg_match('/^DELIMITER\s+(\S+)/', $line, $match)) {
            $delimiter = $match[1];
            continue;
        }
        $statement .= $line;
        if (str_ends_with(rtrim($line), $delimiter)) {
            $db->query(substr(rtrim($statement), 0, -strlen($delimiter)));
            $statement = '';
        }
    }
    Features::override('closures_calendar', true);
    Features::override('circulation_rules', false);
    Features::override('fines', false);
    $_SESSION = ['user' => ['id' => 1, 'tipo_utente' => 'admin']];

    ConfigStore::usingConnection($db, function () use ($db, $check, $post, $args, $sandbox): void {
        $today = DateHelper::today();
        $d = static fn (int $days): string => LibraryCalendar::shift($today, $days);
        DateHelper::synchronizeDatabaseSession($db);
        $db->query("INSERT INTO utenti (nome,cognome,email,password,stato,tipo_utente,codice_tessera,email_verificata)
                    VALUES ('Calendar','One','calendar-one@example.invalid','x','attivo','standard','CAL1',1),
                           ('Calendar','Two','calendar-two@example.invalid','x','attivo','standard','CAL2',1)");
        $users = array_map('intval', array_column($db->query('SELECT id FROM utenti ORDER BY id')->fetch_all(MYSQLI_ASSOC), 'id'));
        [$user, $other] = $users;
        $reset = static function () use ($db): void {
            foreach (['prenotazioni', 'prestiti', 'copie', 'libri', 'calendario_chiusure', 'festivita_personalizzate', 'system_settings'] as $table) {
                $db->query("DELETE FROM {$table}");
            }
            $db->query("UPDATE utenti SET stato='attivo'");
            ConfigStore::clearCache();
            LibraryCalendar::reset();
        };
        $book = static function (?int $branch = null) use ($db): array {
            $db->query("INSERT INTO libri (titolo,copie_totali,copie_disponibili) VALUES ('Calendar regression',1,1)");
            $id = (int) $db->insert_id;
            $sede = $branch ?? 'NULL';
            $db->query("INSERT INTO copie (libro_id,numero_inventario,stato,sede_id) VALUES ({$id},'CAL-{$id}','disponibile',{$sede})");
            return [$id, (int) $db->insert_id];
        };
        $reserve = static function (int $bookId, int $userId, string $from, string $to, int $position = 1) use ($db): int {
            $db->query("INSERT INTO prenotazioni (libro_id,utente_id,data_prenotazione,data_scadenza_prenotazione,data_inizio_richiesta,data_fine_richiesta,queue_position,stato)
                        VALUES ({$bookId},{$userId},'{$from} 00:00:00','{$to} 23:59:59','{$from}','{$to}',{$position},'attiva')");
            return (int) $db->insert_id;
        };
        $close = static function (string $from, string $to, ?int $branch = null) use ($db): void {
            $sede = $branch ?? 'NULL';
            $db->query("INSERT INTO calendario_chiusure (data_inizio,data_fine,descrizione,sede_id,attiva) VALUES ('{$from}','{$to}','Regression',{$sede},1)");
            LibraryCalendar::reset();
        };
        $state = static fn (int $id): string => (string) $db->query("SELECT stato FROM prenotazioni WHERE id={$id}")->fetch_row()[0];
        $expire = static function () use ($db): int {
            $db->begin_transaction();
            $manager = new ReservationManager($db);
            $manager->setExternalTransaction(true);
            try {
                $count = $manager->cancelExpiredReservations();
                // External transaction prevents sending real expiry notifications.
                $db->commit();
                return $count;
            } catch (Throwable $e) {
                $db->rollback();
                throw $e;
            }
        };

        $reset();
        [$b] = $book();
        $reserve($b, $user, $today, $d(2));
        $close($d(2), $d(3));
        $capacity = new CapacityService($db);
        $check(!$capacity->hasFreeCapacity($b, $d(4), $d(4)), 'a queue reservation holds its effective return day');
        $check($capacity->firstAvailableDate($b, $d(2)) === $d(5), 'first availability follows the shifted queue end');
        $response = (new ReservationsAdminController())->store($post(['libro_id' => $b, 'utente_id' => $other, 'data_prenotazione' => $d(4), 'data_scadenza' => $d(7)]), new Response(), $db);
        $check(str_contains($response->getHeaderLine('Location'), 'error=capacity_full'), 'a second promise overlapping the shifted end is refused');
        $public = (new ReservationsController($db))->getBookAvailabilityData($b, $d(4), 1, $other);
        $check($public['days'][0]['available'] === 0, 'the public calendar blocks that effective return day too');
        $db->begin_transaction();
        $manager = new ReservationManager($db);
        $manager->setExternalTransaction(true);
        $check($manager->processBookAvailability($b), 'the original FIFO head still promotes');
        $check((new CapacityService($db))->occupiedCount($b, $d(4), $d(4)) === 1, 'promotion keeps exactly one commitment on one copy');
        $db->rollback();
        Features::override('closures_calendar', false);
        LibraryCalendar::reset();
        $check((new CapacityService($db))->hasFreeCapacity($b, $d(4), $d(4)), 'calendar off restores the original reservation end');
        Features::override('closures_calendar', true);

        $reset();
        [$b] = $book();
        $head = $reserve($b, $user, $today, $d(2));
        $tail = $reserve($b, $other, $d(4), $d(7), 2);
        $close($d(2), $d(3));
        $db->begin_transaction();
        $manager = new ReservationManager($db);
        $manager->setExternalTransaction(true);
        $check(!$manager->processBookAvailability($b), 'a new closure cannot promote a loan into a previously promised queue period');
        $check($state($head) === 'attiva' && $state($tail) === 'attiva', 'a closure conflict preserves both queue entries for resolution');
        $db->rollback();
        $issues = (new DataIntegrity($db))->verifyDataConsistency();
        $check(in_array('overbooked_circulation_period', array_column($issues, 'type'), true), 'integrity audit exposes conflicts caused by a later closure');

        $reset();
        [$b] = $book();
        $r = $reserve($b, $user, $d(-3), $d(-1));
        $close($d(-1), $d(-1));
        $check($expire() === 0 && $state($r) === 'attiva', 'first reopening day does not cancel the queue');
        $integrity = new DataIntegrity($db);
        $available = static fn (): int => (int) $db->query("SELECT copie_disponibili FROM libri WHERE id={$b}")->fetch_row()[0];
        $check($integrity->recalculateBookAvailability($b) && $available() === 0, 'the individual availability counter keeps the copy reserved on reopening');
        $db->query("UPDATE libri SET copie_disponibili=1 WHERE id={$b}");
        $check($integrity->recalculateAllBookAvailability()['errors'] === [] && $available() === 0, 'the bulk availability counter also accounts for the effective return day');
        $check(!LibraryCalendar::reservationExpired($db, $d(-1) . ' 23:59:59', $b, $today . ' 23:59:59'), 'the entire reopening day remains usable');
        $check(LibraryCalendar::reservationExpired($db, $d(-1) . ' 23:59:59', $b, $d(1) . ' 00:00:00'), 'expiry resumes after that usable day');
        $check(LibraryCalendar::reservationDeadline($db, $d(-1) . ' 23:59:59', $b) === $today . ' 23:59:59', 'the expiry e-mail quotes the effective deadline, not the closed stored day');
        $check(LibraryCalendar::reservationDeadline($db, $d(-3) . ' 18:00:00', $b) === $d(-3) . ' 18:00:00', 'an open deadline day keeps its stored time');
        $issues = (new DataIntegrity($db))->verifyDataConsistency();
        $check(!in_array('expired_reservation', array_column($issues, 'type'), true), 'integrity audit agrees with queue expiry after a closure');
        (new DataIntegrity($db))->fixDataInconsistencies();
        $check($state($r) !== 'annullata', 'integrity repair preserves a queue still inside its effective window');

        $reset();
        [$b] = $book();
        $r = $reserve($b, $user, $d(-1), $d(-1));
        $db->query("UPDATE prenotazioni SET data_inizio_richiesta=NULL, data_fine_richiesta=NULL WHERE id={$r}");
        $close($d(-1), $d(-1));
        $db->begin_transaction();
        $manager = new ReservationManager($db);
        $manager->setExternalTransaction(true);
        $check($manager->processBookAvailability($b), 'a legacy reservation remains promotable on the reopening day');
        $allocated = $db->query("SELECT data_prestito, data_scadenza FROM prestiti WHERE libro_id={$b} AND origine='prenotazione'")->fetch_assoc();
        $check(($allocated['data_prestito'] ?? null) === $today && $allocated['data_scadenza'] === $today, 'legacy promotion starts on the usable day');
        $db->rollback();

        $reset();
        $db->query("INSERT INTO sedi (nome) VALUES ('Calendar regression branch')");
        $branch = (int) $db->insert_id;
        [$b] = $book($branch);
        $r = $reserve($b, $user, $d(-3), $d(-1));
        $close($d(-1), $d(1), $branch);
        [$openBook] = $book();
        $expired = $reserve($openBook, $other, $d(-3), $d(-1));
        $check($expire() === 1, 'only the genuinely expired queue is cancelled');
        $check($state($r) === 'attiva' && $state($expired) === 'annullata', 'a branch closure protects its own queue without protecting other branches');
        $check(!(new CapacityService($db))->hasFreeCapacity($b, $d(2), $d(2)), 'branch closure also extends queue capacity to reopening');

        $reset();
        [$b] = $book($branch);
        $db->query("INSERT INTO copie (libro_id,numero_inventario,stato) VALUES ({$b},'CAL-OPEN-{$b}','disponibile')");
        $openCopy = (int) $db->insert_id;
        $head = $reserve($b, $user, $today, $d(2));
        $tail = $reserve($b, $other, $d(4), $d(7), 2);
        $db->query("INSERT INTO prestiti (libro_id,copia_id,utente_id,data_prestito,data_scadenza,stato,origine,attivo)
                    VALUES ({$b},{$openCopy},{$other},'{$d(4)}','{$d(6)}','prenotato','richiesta',1)");
        $futureLoan = (int) $db->insert_id;
        $close($d(2), $d(3), $branch);
        $db->begin_transaction();
        $manager = new ReservationManager($db);
        $manager->setExternalTransaction(true);
        $check($manager->processBookAvailability($b), 'promotion tries another branch when the preferred copy would overbook the added days');
        $allocated = $db->query("SELECT copia_id, data_scadenza FROM prestiti WHERE libro_id={$b} AND origine='prenotazione'")->fetch_assoc();
        $check((int) ($allocated['copia_id'] ?? 0) === $openCopy && $allocated['data_scadenza'] === $d(2), 'promotion chooses the copy that can keep the promised return date');
        $db->rollback();
        $db->query("UPDATE prestiti SET data_prestito='{$today}' WHERE id={$futureLoan}");
        $db->begin_transaction();
        $manager = new ReservationManager($db);
        $manager->setExternalTransaction(true);
        $check(!$manager->processBookAvailability($b) && $state($head) === 'attiva' && $state($tail) === 'attiva', 'if no copy can honor the promise, the head stays in the queue');
        $db->rollback();

        $reset();
        [$b] = $book();
        $close($d(10), $d(12));
        $admin = new ReservationsAdminController();
        $admin->store($post(['libro_id' => $b, 'utente_id' => $user, 'data_prenotazione' => $d(10), 'data_scadenza' => $d(11)]), new Response(), $db);
        $row = $db->query("SELECT * FROM prenotazioni WHERE libro_id={$b}")->fetch_assoc();
        $check($row['data_inizio_richiesta'] === $d(13) && $row['data_fine_richiesta'] === $d(13), 'admin create shifts explicit closed start and end dates');
        $admin->update($post(['stato' => 'attiva', 'data_prenotazione' => $d(10), 'data_scadenza_prenotazione' => $d(11), 'data_inizio_richiesta' => $d(11), 'data_fine_richiesta' => $d(12)]), new Response(), $db, (int) $row['id']);
        $row = $db->query("SELECT * FROM prenotazioni WHERE id={$row['id']}")->fetch_assoc();
        $check($row['data_inizio_richiesta'] === $d(13) && $row['data_fine_richiesta'] === $d(13), 'admin edit also normalizes explicit requested-period overrides');
        $check(substr($row['data_prenotazione'], 0, 10) === $d(13) && substr($row['data_scadenza_prenotazione'], 0, 10) === $d(13), 'stored queue timestamps agree with the normalized period');
        $admin->update($post(['stato' => 'completata', 'data_prenotazione' => $d(10), 'data_scadenza_prenotazione' => $d(11)]), new Response(), $db, (int) $row['id']);
        $history = $db->query("SELECT data_inizio_richiesta,data_fine_richiesta FROM prenotazioni WHERE id={$row['id']}")->fetch_assoc();
        $check($history['data_inizio_richiesta'] === $d(10) && $history['data_fine_richiesta'] === $d(11), 'editing a completed reservation preserves its historical dates');

        $reset();
        [$b, $copy] = $book();
        $db->query("INSERT INTO prestiti (libro_id,copia_id,utente_id,data_prestito,data_scadenza,stato,origine,attivo,pickup_deadline)
                    VALUES ({$b},{$copy},{$user},'{$d(-2)}','{$d(-1)}','da_ritirare','richiesta',1,'{$d(-1)}')");
        $loan = (int) $db->insert_id;
        $calendarAdmin = new LibraryCalendarController();
        $calendarAdmin->store($post(['data_inizio' => $d(-1), 'data_fine' => $d(-1), 'descrizione' => 'Retroactive closure']), new Response(), $db);
        $row = $db->query("SELECT * FROM prestiti WHERE id={$loan}")->fetch_assoc();
        $check($row['data_scadenza'] === $today && $row['pickup_deadline'] === $today, 'a retroactive closure reconciles both waiting-pickup dates');
        $check(LibraryCalendar::extendDueDates($db) === 0 && LibraryCalendar::extendPickupDeadlines($db) === 0, 'pickup date reconciliation is idempotent');
        $response = (new LoanApprovalController())->confirmPickup($post(['loan_id' => $loan]), new Response(), $db);
        $check($response->getStatusCode() === 200, 'pickup succeeds on the advertised effective day');
        $check($db->query("SELECT stato FROM prestiti WHERE id={$loan}")->fetch_row()[0] === 'in_corso', 'successful pickup starts the loan');

        // Maintenance alone (no calendar save) must reconcile pickups before
        // expiring them: when the reconciliation fails on another book, the
        // pickup expiry waits for the next pass instead of cancelling a pickup
        // whose deadline the closure should have moved.
        $reset();
        $pickupLoan = static function (int $bookId, int $copyId, int $userId) use ($db, $d): int {
            $db->query("INSERT INTO prestiti (libro_id,copia_id,utente_id,data_prestito,data_scadenza,stato,origine,attivo,pickup_deadline)
                        VALUES ({$bookId},{$copyId},{$userId},'{$d(-2)}','{$d(5)}','da_ritirare','richiesta',1,'{$d(-1)}')");
            return (int) $db->insert_id;
        };
        [$blockedBook, $blockedCopy] = $book();
        [$waitingBook, $waitingCopy] = $book();
        $blockedLoan = $pickupLoan($blockedBook, $blockedCopy, $other);
        $waitingLoan = $pickupLoan($waitingBook, $waitingCopy, $user);
        $close($d(-1), $d(-1));
        $check((new MaintenanceService($db))->checkExpiredPickups() === 0, 'pickup expiry protects the reopening day before deadlines are reconciled');
        $holder = new mysqli($args[0], $args[1], $args[2], $sandbox, $args[4], $args[5]);
        $holder->begin_transaction();
        $holder->query("SELECT id FROM libri WHERE id={$blockedBook} FOR UPDATE");
        $db->query('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $run = (new MaintenanceService($db))->runAll();
        } finally {
            $holder->rollback();
            $holder->close();
            $db->query('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }
        $pickupRow = static fn (int $id): array => $db->query("SELECT stato, pickup_deadline FROM prestiti WHERE id={$id}")->fetch_assoc();
        $check((bool) array_filter($run['errors'], static fn ($e): bool => str_starts_with((string) $e, 'extendPickupDeadlines')), 'maintenance reports the failed pickup reconciliation');
        $check($pickupRow($waitingLoan)['stato'] === 'da_ritirare', 'a failed reconciliation defers pickup expiry instead of cancelling the pickup');
        $run = (new MaintenanceService($db))->runAll();
        $check($pickupRow($waitingLoan)['stato'] === 'da_ritirare' && $pickupRow($waitingLoan)['pickup_deadline'] === $today
            && $pickupRow($blockedLoan)['pickup_deadline'] === $today, 'the next maintenance pass moves both pickup deadlines to the reopening day');

        $reset();
        $calendarAdmin->store($post(['data_inizio' => '2026-01-01', 'data_fine' => '2026-12-29', 'ricorrente_annuale' => '1', 'descrizione' => 'Annual closure']), new Response(), $db);
        $holiday = ['nome' => 'Last open days', 'tipo' => 'fixed', 'fixed_mese' => '12', 'fixed_giorno' => '30', 'durata_giorni' => '2', 'attiva' => '1'];
        unset($_SESSION['error_message']);
        $calendarAdmin->storeCustom($post($holiday), new Response(), $db);
        $check(isset($_SESSION['error_message']) && (int) $db->query('SELECT COUNT(*) FROM festivita_personalizzate')->fetch_row()[0] === 0, 'a custom holiday closing the last open days is rolled back');
        $check(LibraryCalendar::forDb($db)->isOpen('2027-12-30'), 'a rejected holiday leaves an open day and resets the calendar cache');
        $holiday['attiva'] = '0';
        $calendarAdmin->storeCustom($post($holiday), new Response(), $db);
        $holidayId = (int) $db->query('SELECT id FROM festivita_personalizzate')->fetch_row()[0];
        $calendarAdmin->toggleCustom($post([]), new Response(), $db, $holidayId);
        $check((int) $db->query("SELECT attiva FROM festivita_personalizzate WHERE id={$holidayId}")->fetch_row()[0] === 0, 'reactivation cannot bypass the full-year coverage guard');
        $holiday['attiva'] = '1';
        $holiday['anno_inizio'] = '2100';
        $calendarAdmin->updateCustom($post($holiday), new Response(), $db, $holidayId);
        $check((int) $db->query("SELECT attiva FROM festivita_personalizzate WHERE id={$holidayId}")->fetch_row()[0] === 0, 'a far-future activation is validated beyond the old 28-year horizon');

        $reset();
        $calendarAdmin->store($post(['data_inizio' => '2026-01-02', 'data_fine' => '2026-12-31', 'ricorrente_annuale' => '1', 'descrizione' => 'Only New Year open']), new Response(), $db);
        $calendarAdmin->storeCustom($post(['nome' => 'Cross-year closure', 'tipo' => 'dates', 'date_specifiche' => '2400-12-31', 'durata_giorni' => '2', 'attiva' => '1']), new Response(), $db);
        $check((int) $db->query('SELECT COUNT(*) FROM festivita_personalizzate')->fetch_row()[0] === 0, 'coverage checks the following year when an explicit holiday crosses New Year');

        $reset();
        [$b] = $book();
        $reserve($b, $user, $d(10), $d(12));
        $db->query("UPDATE utenti SET stato='sospeso' WHERE id={$user}");
        $payload = (new ReservationsController($db))->getBookAvailabilityData($b, $d(10), 2, $other);
        $check((new CapacityService($db))->hasFreeCapacity($b, $d(10), $d(11)) && $payload['days'][0]['available'] === 1, 'a suspended patron queue is paused in capacity and the public calendar');
        $db->query("UPDATE utenti SET stato='attivo' WHERE id={$user}");
        $payload = (new ReservationsController($db))->getBookAvailabilityData($b, $d(10), 2, $other);
        $check($payload['days'][0]['available'] === 0, 're-enabling the patron restores the queue commitment');
        $own = (new ReservationsController($db))->getBookAvailabilityData($b, $d(10), 2, $user);
        $check($own['has_active_reservation'] && $own['days'][0]['available'] === 1, 'own-reservation exclusion preserves the duplicate warning');
        $db->query("UPDATE utenti SET stato='sospeso' WHERE id={$user}");
        $db->query("UPDATE prenotazioni SET data_inizio_richiesta=NULL, data_fine_richiesta=NULL WHERE libro_id={$b}");
        $db->query("UPDATE utenti SET stato='attivo' WHERE id={$user}");
        $check(!(new CapacityService($db))->hasFreeCapacity($b, $d(12), $d(12)), 'legacy NULL-start reservations still occupy their fallback day');

        $reset();
        [$b] = $book();
        [$otherBook, $otherCopy] = $book();
        (new App\Models\SettingsRepository($db))->set('loans', 'max_active_loans_per_user', '1');
        $reserve($b, $user, $d(10), $d(12));
        $db->query("INSERT INTO prestiti (libro_id,copia_id,utente_id,data_prestito,data_scadenza,stato,origine,attivo)
                    VALUES ({$otherBook},{$otherCopy},{$user},'{$d(14)}','{$d(20)}','prenotato','richiesta',1)");
        $check(!(new CapacityService($db))->hasFreeCapacity($b, $d(10), $d(12)), 'a later loan outside the original window does not pause a future reservation');
        $close($d(12), $d(13));
        $check((new CapacityService($db))->hasFreeCapacity($b, $d(10), $d(14)), 'the patron limit also counts a loan starting in the added days');

        // The integrity audit reads a loan due on a closed day the way
        // CapacityService does: it keeps its copy until the reopening day.
        $reset();
        [$b, $copy] = $book();
        $close($d(3), $d(3));
        $db->query("INSERT INTO prestiti (libro_id,copia_id,utente_id,data_prestito,data_scadenza,stato,origine,attivo)
                    VALUES ({$b},{$copy},{$user},'{$d(-2)}','{$d(3)}','in_corso','richiesta',1)");
        $reserve($b, $other, $d(4), $d(6));
        $issues = (new DataIntegrity($db))->verifyDataConsistency();
        $check(in_array('overbooked_circulation_period', array_column($issues, 'type'), true), 'integrity audit counts a loan until the reopening day after its closed due date');

        // nextOpenDay() now steps over a whole yearly closure: same answers
        // as the day-by-day walk, including the New Year wrap and 29 February.
        $yearly = new LibraryCalendar([], [
            ['data_inizio' => '2020-12-20', 'data_fine' => '2021-01-10', 'ricorrente_annuale' => 1],
            ['data_inizio' => '2020-02-01', 'data_fine' => '2020-02-29', 'ricorrente_annuale' => 1],
            ['data_inizio' => '2020-03-05', 'data_fine' => '2020-06-30', 'ricorrente_annuale' => 1],
        ]);
        $check($yearly->nextOpenDay('2027-12-25') === '2028-01-11' && $yearly->nextOpenDay('2028-01-02') === '2028-01-11', 'a yearly closure wrapping New Year is stepped over from either side');
        $check($yearly->nextOpenDay('2027-02-10') === '2027-03-01' && $yearly->nextOpenDay('2028-02-10') === '2028-03-01', 'a closure ending on 29 February ends on the 28th in a common year');
        $check($yearly->nextOpenDay('2027-03-05') === '2027-07-01' && $yearly->addOpenDays('2027-03-04', 1) === '2027-07-01', 'a long yearly closure is stepped over in one move');
    });
} catch (Throwable $e) {
    $failed++;
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
} finally {
    if (App\Support\DbAtomic::inTransaction($db)) { $db->rollback(); }
    try {
        $db->query("DROP DATABASE IF EXISTS `{$sandbox}`");
    } catch (Throwable $dropError) {
        // A failed CREATE must stay the reported error, not this cleanup.
        fwrite(STDERR, 'cleanup: ' . $dropError->getMessage() . PHP_EOL);
    }
    Features::resetOverrides();
    LibraryCalendar::reset();
    $_SESSION = $previousSession;
}
echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);

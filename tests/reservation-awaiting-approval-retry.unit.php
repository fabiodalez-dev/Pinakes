<?php
declare(strict_types=1);

/**
 * The retry of the "copy assigned, wait for approval" e-mail a promotion
 * could not deliver (ReservationManager::retryUnsentReservationNotifications).
 *
 * Against the REAL database and the REAL promotion; only the SMTP hand-off is
 * replaced, so a delivery can be made to fail:
 *  - a failed delivery leaves the notice due and the next run sends it once
 *    (at least once by design);
 *  - once the promoted loan has been approved the retry stays silent: the
 *    reader already heard "ready for pickup" and must not be told to wait;
 *  - the notice ages out seven days after the promotion even when failed
 *    attempts kept touching the reservation row in the meantime.
 *
 * Time passing is simulated by moving every stored timestamp of the fixture
 * back by whole days; nothing waits on the clock.
 *
 * Run: php tests/reservation-awaiting-approval-retry.unit.php
 */

use App\Controllers\ReservationManager;
use App\Support\ConfigStore;
use App\Support\DateHelper;
use App\Support\Features;
use App\Support\LibraryCalendar;
use App\Support\NotificationService;

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
    fwrite(STDERR, "FAIL: database unreachable — the notification retry suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$exit = ConfigStore::usingConnection($db, static function () use ($db): int {
    DateHelper::synchronizeDatabaseSession($db);
    $run = bin2hex(random_bytes(6));
    $prefix = "ZZ_RETRY_{$run}";
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
    $cleanup = static function () use ($db, $created): void {
        try { $db->rollback(); } catch (Throwable) {}
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
        Features::resetOverrides();
        LibraryCalendar::reset();
    };

    try {
        // A library open every day, whatever calendar this database holds.
        Features::override('closures_calendar', false);
        LibraryCalendar::reset();
        $today = DateHelper::today();
        $d = static fn (int $offset): string =>
            (new DateTimeImmutable($today))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');

        // The SMTP hand-off: records every attempt and can be made to fail.
        $mailer = new class ($db) extends NotificationService {
            public bool $delivers = true;
            /** @var list<string> */
            public array $attempts = [];

            public function sendReservationBookAvailable(string $email, array $variables): bool
            {
                $this->attempts[] = $email;
                return $this->delivers;
            }
        };
        $manager = new class ($db, $mailer) extends ReservationManager {
            public function __construct(mysqli $db, private NotificationService $mailer)
            {
                parent::__construct($db);
            }

            protected function notificationService(): NotificationService
            {
                return $this->mailer;
            }
        };
        $attemptsFor = static fn (string $email): int => count(array_keys($mailer->attempts, $email, true));

        // One book with a copy on the shelf and one queued reader whose window
        // starts today, promoted by the real code while the SMTP server is down.
        $promoteWithSmtpDown = static function (string $suffix) use ($db, $prefix, $run, $d, $manager, $mailer, $created): array {
            $title = "{$prefix}_{$suffix}";
            $stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES (?, 1, 1, 'disponibile')");
            $stmt->bind_param('s', $title);
            $stmt->execute();
            $book = (int) $db->insert_id;
            $stmt->close();
            $created->books[] = $book;
            $inv = strtoupper("RT{$suffix}-" . substr($run, 0, 8));
            $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
            $stmt->bind_param('is', $book, $inv);
            $stmt->execute();
            $stmt->close();

            $email = "zz-retry-{$suffix}-{$run}@test.local";
            $card = 'RT' . strtoupper($suffix) . strtoupper(substr($run, 0, 8));
            $password = password_hash('RetrySuite!1', PASSWORD_DEFAULT);
            $stmt = $db->prepare(
                "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata)
                 VALUES (?, 'Retry', ?, ?, ?, 'standard', 'attivo', 1)"
            );
            $stmt->bind_param('ssss', $card, $suffix, $email, $password);
            $stmt->execute();
            $user = (int) $db->insert_id;
            $stmt->close();
            $created->users[] = $user;

            $start = $d(0); $end = $d(7);
            $startDt = $start . ' 00:00:00'; $endDt = $end . ' 23:59:59';
            $stmt = $db->prepare(
                "INSERT INTO prenotazioni (libro_id, utente_id, data_prenotazione, data_scadenza_prenotazione, data_inizio_richiesta, data_fine_richiesta, queue_position, stato)
                 VALUES (?, ?, ?, ?, ?, ?, 1, 'attiva')"
            );
            $stmt->bind_param('iissss', $book, $user, $startDt, $endDt, $start, $end);
            $stmt->execute();
            $reservation = (int) $db->insert_id;
            $stmt->close();

            $mailer->delivers = false;
            $promoted = $manager->processBookAvailability($book);
            $loan = $db->query("SELECT id, stato, attivo FROM prestiti WHERE libro_id = {$book} AND utente_id = {$user} AND origine = 'prenotazione'")->fetch_assoc();

            return ['book' => $book, 'user' => $user, 'email' => $email, 'reservation' => $reservation,
                'promoted' => $promoted, 'loan' => $loan !== null ? (int) $loan['id'] : 0,
                'awaiting' => $loan !== null && $loan['stato'] === 'pendente' && (int) $loan['attivo'] === 0];
        };
        $flag = static fn (int $reservation): int =>
            (int) $db->query("SELECT notifica_inviata FROM prenotazioni WHERE id = {$reservation}")->fetch_row()[0];
        // $days pass: every timestamp of the fixture moves back together
        // (naming updated_at keeps ON UPDATE from stamping the row again).
        $daysPass = static function (array $f, int $days) use ($db): void {
            $db->query("UPDATE prenotazioni SET created_at = created_at - INTERVAL {$days} DAY, updated_at = updated_at - INTERVAL {$days} DAY WHERE id = {$f['reservation']}");
            $db->query("UPDATE prestiti SET created_at = created_at - INTERVAL {$days} DAY, updated_at = updated_at - INTERVAL {$days} DAY WHERE libro_id = {$f['book']}");
            $db->query("UPDATE log_modifiche SET data_modifica = data_modifica - INTERVAL {$days} DAY WHERE tabella = 'libri' AND record_id = {$f['book']}");
        };

        // ═════════ 1: still awaiting approval — delivered at least once ═════════
        $waiting = $promoteWithSmtpDown('wait');
        $check($waiting['promoted'] === true && $waiting['awaiting'], '01 the reservation is promoted to a request awaiting approval');
        $check($attemptsFor($waiting['email']) === 1 && $flag($waiting['reservation']) === 0,
            '02 the failed delivery at promotion leaves the notice due');
        $mailer->delivers = false;
        $manager->retryUnsentReservationNotifications(1000);
        $check($attemptsFor($waiting['email']) === 2 && $flag($waiting['reservation']) === 0,
            '03 a retry that fails again keeps it due');
        $mailer->delivers = true;
        $manager->retryUnsentReservationNotifications(1000);
        $check($attemptsFor($waiting['email']) === 3 && $flag($waiting['reservation']) === 1,
            '04 the next retry delivers it while the request still awaits approval');
        $manager->retryUnsentReservationNotifications(1000);
        $check($attemptsFor($waiting['email']) === 3, '05 a delivered notice is not sent again');

        // ═════════ 2: approved in the meantime — the retry stays silent ═════════
        $approved = $promoteWithSmtpDown('appr');
        $check($approved['promoted'] === true && $attemptsFor($approved['email']) === 1 && $flag($approved['reservation']) === 0,
            '06 a second promotion could not deliver its notice either');
        $pickupDeadline = $d(3);
        $db->query("UPDATE prestiti SET stato = 'da_ritirare', attivo = 1, pickup_deadline = '{$pickupDeadline}' WHERE id = {$approved['loan']}");
        $mailer->delivers = true;
        $manager->retryUnsentReservationNotifications(1000);
        $check($attemptsFor($approved['email']) === 1,
            '07 a reader whose loan is approved and ready for pickup is not told to wait for approval');
        $db->query("UPDATE prestiti SET stato = 'in_corso', pickup_deadline = NULL WHERE id = {$approved['loan']}");
        $manager->retryUnsentReservationNotifications(1000);
        $check($attemptsFor($approved['email']) === 1, '08 nor is one who already took the book home');

        // ═════════ 3: the notice ages out seven days after the promotion ═════════
        $aged = $promoteWithSmtpDown('aged');
        $check($aged['promoted'] === true && $aged['awaiting'] && $attemptsFor($aged['email']) === 1,
            '09 a third promotion could not deliver its notice');
        $daysPass($aged, 6);
        $mailer->delivers = false;
        $manager->retryUnsentReservationNotifications(1000);
        $check($attemptsFor($aged['email']) === 2 && $flag($aged['reservation']) === 0,
            '10 six days later the retry still tries, and fails again');
        $daysPass($aged, 2);
        $mailer->delivers = true;
        $manager->retryUnsentReservationNotifications(1000);
        $check($attemptsFor($aged['email']) === 2,
            '11 eight days after the promotion the notice is dropped, although a failed attempt touched the row two days ago');

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

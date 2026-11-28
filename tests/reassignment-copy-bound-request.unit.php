<?php
declare(strict_types=1);

/**
 * A loan request that already holds a copy follows that copy's fate, whatever
 * its origin.
 *
 * With automatic approval a reader's request is written as a pending row
 * bound to the exact copy (origine = 'richiesta') before it is approved. When
 * that copy is set to maintenance or lost, ReservationReassignmentService
 * (the service the copy editor calls) must move the request to another copy,
 * or free it, exactly as it does for a promoted reservation — never leave it
 * pinned to a copy nobody can lend.
 *
 * Against the REAL database and service; only the e-mail hand-off is replaced:
 *  - a copy-bound pending request moves to a free sibling copy;
 *  - with no other copy it goes back to a plain request for the staff, and
 *    the reader is told the copy is gone;
 *  - a request left on an unusable copy takes a copy that becomes free;
 *  - a manual request that never held a copy is NOT given one (it stays in
 *    the staff review queue, by design);
 *  - a promoted reservation keeps moving as before.
 *
 * Run: php tests/reassignment-copy-bound-request.unit.php
 */

use App\Services\ReservationReassignmentService;
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
    fwrite(STDERR, "FAIL: database unreachable — the reassignment suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$exit = ConfigStore::usingConnection($db, static function () use ($db): int {
    DateHelper::synchronizeDatabaseSession($db);
    $run = bin2hex(random_bytes(6));
    $prefix = "ZZ_REASSIGN_{$run}";
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
                $inv = strtoupper("RA{$suffix}{$i}-") . strtoupper(substr($prefix, -8));
                $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
                $stmt->bind_param('is', $bookId, $inv);
                $stmt->execute();
                $copyIds[] = (int) $db->insert_id;
                $stmt->close();
            }
            return [$bookId, $copyIds];
        };
        $makeUser = static function (string $suffix) use ($db, $run, $created): array {
            $email = "zz-reassign-{$suffix}-{$run}@test.local";
            $card = 'RA' . strtoupper($suffix) . strtoupper(substr($run, 0, 8));
            $password = password_hash('Reassign!1', PASSWORD_DEFAULT);
            $stmt = $db->prepare(
                "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata)
                 VALUES (?, 'Reassign', ?, ?, ?, 'standard', 'attivo', 1)"
            );
            $stmt->bind_param('ssss', $card, $suffix, $email, $password);
            $stmt->execute();
            $userId = (int) $db->insert_id;
            $stmt->close();
            $created->users[] = $userId;
            return [$userId, $email];
        };
        // A pending row (attivo = 0), bound to $copy or not, as the request
        // paths ('richiesta') and the queue promotion ('prenotazione') write it.
        $pending = static function (int $book, ?int $copy, int $user, string $origin) use ($db, $d): int {
            $start = $d(0); $end = $d(7);
            $stmt = $db->prepare(
                "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
                 VALUES (?, ?, ?, ?, ?, 'pendente', ?, 0)"
            );
            $stmt->bind_param('iiisss', $book, $copy, $user, $start, $end, $origin);
            $stmt->execute();
            $id = (int) $db->insert_id;
            $stmt->close();
            return $id;
        };
        $loan = static fn (int $id): array =>
            $db->query("SELECT copia_id, stato, origine, attivo, data_prestito, data_scadenza FROM prestiti WHERE id = {$id}")->fetch_assoc();
        $retire = static function (int $copy, string $state) use ($db): void {
            $db->query("UPDATE copie SET stato = '{$state}' WHERE id = {$copy}");
        };

        // The e-mail hand-off: records what the reader would have been sent.
        $mailer = new class ($db) extends NotificationService {
            /** @var list<array{string, string}> [kind, recipient] */
            public array $sent = [];

            public function sendReservationBookAvailable(string $email, array $variables): bool
            {
                $this->sent[] = ['copy_assigned', $email];
                return true;
            }

            public function sendCopyUnavailableNotification(string $email, array $variables): bool
            {
                $this->sent[] = ['copy_unavailable', $email];
                return true;
            }

            public function createNotification(string $type, string $title, string $message, ?string $link = null, ?int $relatedId = null): bool
            {
                return true;
            }
        };
        $service = static function () use ($db, $mailer): ReservationReassignmentService {
            $service = new ReservationReassignmentService($db);
            (new ReflectionProperty($service, 'notificationService'))->setValue($service, $mailer);
            return $service;
        };
        $sentTo = static fn (string $email): array =>
            array_values(array_map(static fn (array $m): string => $m[0], array_filter($mailer->sent, static fn (array $m): bool => $m[1] === $email)));

        // ═════════ 1: a sibling copy is free ═════════
        [$book1, [$copy1A, $copy1B]] = $makeBook('M', 2);
        [$reader1, $email1] = $makeUser('m');
        $request1 = $pending($book1, $copy1A, $reader1, 'richiesta');
        $retire($copy1A, 'manutenzione');
        $service()->reassignAllOnCopyLost($copy1A);
        $row = $loan($request1);
        $check((int) $row['copia_id'] === $copy1B, '01 a copy-bound pending request moves to the free sibling when its copy goes to maintenance');
        $check($row['stato'] === 'pendente' && $row['origine'] === 'richiesta' && (int) $row['attivo'] === 0
            && $row['data_prestito'] === $d(0) && $row['data_scadenza'] === $d(7),
            '02 it is still the same pending request for the same days');

        // ═════════ 2: no other copy ═════════
        [$book2, [$copy2]] = $makeBook('L', 1);
        [$reader2, $email2] = $makeUser('l');
        $request2 = $pending($book2, $copy2, $reader2, 'richiesta');
        $retire($copy2, 'perso');
        $service()->reassignAllOnCopyLost($copy2);
        $row = $loan($request2);
        $check($row['copia_id'] === null, '03 with no other copy the request lets go of the lost one');
        $check($row['stato'] === 'pendente' && $row['origine'] === 'richiesta' && (int) $row['attivo'] === 0,
            '04 and stays a pending request for the staff to decide');
        $check($sentTo($email2) === ['copy_unavailable'], '05 the reader is told the copy is no longer available');

        // ═════════ 3: a request left on an unusable copy takes one that becomes free ═════════
        [$book3, [$copy3A, $copy3B]] = $makeBook('N', 2);
        [$reader3, $email3] = $makeUser('n');
        [$other3] = $makeUser('n2');
        $request3 = $pending($book3, $copy3A, $reader3, 'richiesta');
        $retire($copy3A, 'manutenzione');
        $moved = $service()->reassignOnNewCopy($book3, $copy3B);
        $check($moved === true && (int) $loan($request3)['copia_id'] === $copy3B,
            '06 a pending request stuck on an unusable copy takes the copy that becomes available');
        $check($sentTo($email3) === ['copy_assigned'], '07 the reader hears a copy is assigned and approval is still due');

        // ═════════ 4: a manual request never held a copy and is not given one ═════════
        [$book4, [$copy4]] = $makeBook('Q', 1);
        [$reader4, $email4] = $makeUser('q');
        $manual = $pending($book4, null, $reader4, 'richiesta');
        $taken = $service()->reassignOnNewCopy($book4, $copy4);
        $check($taken === false && $loan($manual)['copia_id'] === null,
            '08 a manual request with no copy stays copy-less in the review queue');
        $check($sentTo($email4) === [], '09 and its reader is not notified');

        // ═════════ 5: a promoted reservation keeps moving as before ═════════
        [$book5, [$copy5A, $copy5B]] = $makeBook('P', 2);
        [$reader5] = $makeUser('p');
        $promoted = $pending($book5, $copy5A, $reader5, 'prenotazione');
        $retire($copy5A, 'manutenzione');
        $service()->reassignAllOnCopyLost($copy5A);
        $check((int) $loan($promoted)['copia_id'] === $copy5B, '10 a promoted reservation still moves to the free sibling');

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

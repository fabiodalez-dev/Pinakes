<?php
declare(strict_types=1);

/**
 * Circulation policy review fixes (PR #508):
 *  - queue promotion does not stall behind a patron the per-material rules
 *    refuse (limit for the material reached, or material not loanable for
 *    the patron type): the next patron in the queue is promoted, and the
 *    refused reservation stays active without holding capacity;
 *  - PatronTypeAssignment::fromSubmission() refuses a deactivated patron
 *    type, unless it is the one the user already has;
 *  - DataIntegrity recalculates libri.copie_disponibili without the
 *    consultation-only copies (copie_totali keeps counting them).
 *
 * Runs against the REAL database of the checkout and only touches rows it
 * creates (titles ZZ_CQM_%, emails zzcqm+%@test.local, types zzcqm-%).
 *
 * Run: php tests/circulation-queue-material-rules.unit.php
 */

use App\Controllers\ReservationManager;
use App\Services\CapacityService;
use App\Services\CirculationPolicy;
use App\Support\DataIntegrity;
use App\Support\DateHelper;
use App\Support\PatronTypeAssignment;

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
    fwrite(STDERR, "FAIL: database unreachable: {$e->getMessage()}\n");
    exit(1);
}

if (!(new CirculationPolicy($db))->schemaReady()) {
    fwrite(STDERR, "FAIL: the 0.9.0 circulation schema is missing on this database\n");
    exit(1);
}

$run = bin2hex(random_bytes(5));
$prefix = "ZZ_CQM_{$run}";
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$bookIds = [];
$userIds = [];
$typeIds = [];

$cleanup = static function () use ($db, &$bookIds, &$userIds, &$typeIds): void {
    foreach ($bookIds as $id) {
        $db->query("DELETE FROM log_modifiche WHERE tabella = 'libri' AND record_id = {$id}");
        $db->query("DELETE FROM prenotazioni WHERE libro_id = {$id}");
        $db->query("DELETE FROM prestiti WHERE libro_id = {$id}");
        $db->query("DELETE FROM copie WHERE libro_id = {$id}");
        $db->query("DELETE FROM libri WHERE id = {$id}");
    }
    foreach ($userIds as $id) {
        try { $db->query("DELETE FROM notifications WHERE user_id = {$id}"); } catch (Throwable) {}
        $db->query("DELETE FROM utenti WHERE id = {$id}");
    }
    foreach ($typeIds as $id) {
        $db->query("DELETE FROM regole_prestito WHERE tipologia_id = {$id}");
        $db->query("DELETE FROM tipologie_lettore WHERE id = {$id}");
    }
    try { $db->query("DELETE FROM email_delivery_outbox WHERE recipient_email LIKE 'zzcqm+%@test.local'"); } catch (Throwable) {}
};
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, 'FAIL: uncaught ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
    $db->close();
    exit(1);
});

$makeType = static function (string $suffix, int $active = 1) use ($db, $run, &$typeIds): int {
    $code = "zzcqm-{$suffix}-{$run}";
    $name = "ZZ CQM {$suffix}";
    $stmt = $db->prepare('INSERT INTO tipologie_lettore (codice, nome, attiva) VALUES (?, ?, ?)');
    $stmt->bind_param('ssi', $code, $name, $active);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    $typeIds[] = $id;
    return $id;
};

$makeRule = static function (int $typeId, string $media, ?int $loanable, ?int $maxLoans) use ($db): void {
    $stmt = $db->prepare('INSERT INTO regole_prestito (tipologia_id, tipo_media, prestabile, max_prestiti) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isii', $typeId, $media, $loanable, $maxLoans);
    $stmt->execute();
    $stmt->close();
};

$makeBook = static function (string $suffix, string $media) use ($db, $prefix, &$bookIds): array {
    $title = "{$prefix}_{$suffix}";
    $stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato, tipo_media) VALUES (?, 1, 1, 'disponibile', ?)");
    $stmt->bind_param('ss', $title, $media);
    $stmt->execute();
    $bookId = (int) $db->insert_id;
    $stmt->close();
    $bookIds[] = $bookId;
    $inv = 'ZZCQM-' . strtoupper($suffix) . '-' . strtoupper($prefix);
    $inv = substr($inv, 0, 50);
    $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
    $stmt->bind_param('is', $bookId, $inv);
    $stmt->execute();
    $copyId = (int) $db->insert_id;
    $stmt->close();
    return [$bookId, $copyId];
};

$makeUser = static function (string $suffix, ?int $typeId) use ($db, $run, &$userIds): int {
    $email = "zzcqm+{$suffix}-{$run}@test.local";
    $card = substr('ZM' . strtoupper($suffix) . strtoupper($run), 0, 20);
    $password = password_hash('CqmSuite!1', PASSWORD_DEFAULT);
    $stmt = $db->prepare(
        "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata, tipologia_id)
         VALUES (?, 'Cqm', ?, ?, ?, 'standard', 'attivo', 1, ?)"
    );
    $cog = ucfirst($suffix);
    $stmt->bind_param('ssssi', $card, $cog, $email, $password, $typeId);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    $userIds[] = $id;
    return $id;
};

$today = DateHelper::today();
$d = static fn (int $offset): string => (new DateTimeImmutable($today))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');

$mkLoan = static function (int $bookId, int $copyId, int $userId) use ($db, $d): void {
    $start = $d(-2);
    $end = $d(20);
    $stmt = $db->prepare(
        "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
         VALUES (?, ?, ?, ?, ?, 'in_corso', 'diretto', 1)"
    );
    $stmt->bind_param('iiiss', $bookId, $copyId, $userId, $start, $end);
    $stmt->execute();
    $stmt->close();
    $db->query("UPDATE copie SET stato = 'prestato' WHERE id = {$copyId}");
};

$mkReservation = static function (int $bookId, int $userId, int $queuePos) use ($db, $d): int {
    $start = $d(0);
    $end = $d(14);
    $startDt = $start . ' 00:00:00';
    $endDt = $end . ' 23:59:59';
    $stmt = $db->prepare(
        "INSERT INTO prenotazioni (libro_id, utente_id, queue_position, stato, data_prenotazione, data_scadenza_prenotazione, data_inizio_richiesta, data_fine_richiesta)
         VALUES (?, ?, ?, 'attiva', ?, ?, ?, ?)"
    );
    $stmt->bind_param('iiissss', $bookId, $userId, $queuePos, $startDt, $endDt, $start, $end);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$reservationState = static function (int $id) use ($db): ?string {
    $res = $db->query("SELECT stato FROM prenotazioni WHERE id = {$id}");
    $row = $res ? $res->fetch_assoc() : null;
    return $row['stato'] ?? null;
};
$openLoanOf = static function (int $bookId, int $userId) use ($db): int {
    $res = $db->query("SELECT COUNT(*) AS c FROM prestiti WHERE libro_id = {$bookId} AND utente_id = {$userId} AND stato <> 'annullato'");
    return (int) ($res->fetch_assoc()['c'] ?? 0);
};

/* ------------------------------------------------------------------------
 * 1. Per-material limit reached: the head of the queue is skipped.
 * ---------------------------------------------------------------------- */
echo "Queue promotion past a patron at the limit for the material\n";
$student = $makeType('stud');
$makeRule($student, 'dvd', null, 1);
[$heldDvd, $heldCopy] = $makeBook('held', 'dvd');
[$dvd, $dvdCopy] = $makeBook('queued', 'dvd');
$head = $makeUser('head', $student);
$next = $makeUser('next', null);
$mkLoan($heldDvd, $heldCopy, $head);
$headRes = $mkReservation($dvd, $head, 1);
$nextRes = $mkReservation($dvd, $next, 2);

$policy = new CirculationPolicy($db);
$check($policy->materialRefusedReservationUserIds($dvd) === [$head], 'the patron at the DVD limit is the only refused one');
$check((new CapacityService($db))->occupiedCount($dvd, $d(0), $d(14)) === 1, 'the refused head does not hold capacity (only the next reservation counts)');
$check((new CapacityService($db))->hasFreeCapacity($dvd, $d(0), $d(14), excludeReservationId: $nextRes, excludeReservationsAfterQueuePos: 2), 'the only copy is free for the next patron');
$promoted = (new ReservationManager($db))->processBookAvailability($dvd);
$check($promoted === true, 'a return promotes somebody');
$check($openLoanOf($dvd, $next) === 1 && $reservationState($nextRes) === 'completata', 'the second patron in the queue gets the DVD');
$check($openLoanOf($dvd, $head) === 0 && $reservationState($headRes) === 'attiva', 'the refused head keeps its reservation, without a loan');

/* ------------------------------------------------------------------------
 * 2. Material not loanable for the patron type: same pause.
 * ---------------------------------------------------------------------- */
echo "Queue promotion past a patron whose type may not borrow the material\n";
$guest = $makeType('guest');
$makeRule($guest, 'disco', 0, null);
[$cd, $cdCopy] = $makeBook('cd', 'disco');
$guestUser = $makeUser('guest', $guest);
$after = $makeUser('after', null);
$guestRes = $mkReservation($cd, $guestUser, 1);
$afterRes = $mkReservation($cd, $after, 2);
$check((new CirculationPolicy($db))->materialRefusedReservationUserIds($cd) === [$guestUser], 'the patron whose type may not borrow CDs is refused');
$check((new ReservationManager($db))->processBookAvailability($cd) === true, 'the CD is promoted');
$check($openLoanOf($cd, $after) === 1 && $reservationState($afterRes) === 'completata', 'the patron behind gets the CD');
$check($reservationState($guestRes) === 'attiva', 'the refused reservation stays in the queue');

/* ------------------------------------------------------------------------
 * 3. Nobody refused: unchanged behaviour (the head is promoted).
 * ---------------------------------------------------------------------- */
echo "No refusal: the head of the queue is promoted as before\n";
[$book, $bookCopy] = $makeBook('book', 'libro');
$first = $makeUser('first', $student);
$second = $makeUser('second', null);
$firstRes = $mkReservation($book, $first, 1);
$mkReservation($book, $second, 2);
$check((new CirculationPolicy($db))->materialRefusedReservationUserIds($book) === [], 'no refusal for a material without rules');
$check((new ReservationManager($db))->processBookAvailability($book) === true, 'the book is promoted');
$check($openLoanOf($book, $first) === 1 && $reservationState($firstRes) === 'completata', 'the head of the queue gets it');

/* ------------------------------------------------------------------------
 * 4. PatronTypeAssignment::fromSubmission() and deactivated types.
 * ---------------------------------------------------------------------- */
echo "Patron type submitted on the user form\n";
$retired = $makeType('retired', 0);
$check(PatronTypeAssignment::fromSubmission($db, ['tipologia_id' => (string) $student]) === $student, 'an active type is accepted');
$check(PatronTypeAssignment::fromSubmission($db, ['tipologia_id' => (string) $retired]) === null, 'a deactivated type is refused on a new user');
$check(PatronTypeAssignment::fromSubmission($db, ['tipologia_id' => (string) $retired], $student) === $student, 'a deactivated type keeps the current one on update');
$check(PatronTypeAssignment::fromSubmission($db, ['tipologia_id' => (string) $retired], $retired) === $retired, 'a user keeps the deactivated type they already have');
$check(PatronTypeAssignment::fromSubmission($db, ['tipologia_id' => '999999999'], $student) === $student, 'an unknown type keeps the current one');
$check(PatronTypeAssignment::fromSubmission($db, ['tipologia_id' => ''], $student) === null, 'an empty value selects the default type');

/* ------------------------------------------------------------------------
 * 5. libri.copie_disponibili excludes the consultation-only copies.
 * ---------------------------------------------------------------------- */
echo "Availability counter without the consultation copies\n";
[$mixed, $lendableCopy] = $makeBook('mixed', 'libro');
$consultationInv = substr('ZZCQM-MIXED-CONS-' . strtoupper($prefix), 0, 50);
$stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato, solo_consultazione) VALUES (?, ?, 'disponibile', 1)");
$stmt->bind_param('is', $mixed, $consultationInv);
$stmt->execute();
$stmt->close();
$counters = static function (int $bookId) use ($db): string {
    $row = $db->query("SELECT copie_disponibili, copie_totali, stato FROM libri WHERE id = {$bookId}")->fetch_assoc();
    return $row['copie_disponibili'] . '/' . $row['copie_totali'] . '/' . $row['stato'];
};
$integrity = new DataIntegrity($db);
$check($integrity->recalculateBookAvailability($mixed) && $counters($mixed) === '1/2/disponibile', 'one lendable + one consultation copy: 1 available of 2 physical copies');
$borrower = $makeUser('borrower', null);
$mkLoan($mixed, $lendableCopy, $borrower);
$check($integrity->recalculateBookAvailability($mixed) && $counters($mixed) === '0/2/prestato', 'with the lendable copy out the consultation copy does not count: 0 available, title lent');
$db->query("UPDATE copie SET solo_consultazione = 0 WHERE numero_inventario = '{$consultationInv}'");
$check($integrity->recalculateBookAvailability($mixed) && $counters($mixed) === '1/2/disponibile', 'back in circulation, the copy counts again');
$db->query("UPDATE copie SET solo_consultazione = 1 WHERE libro_id = {$mixed}");
$db->query("DELETE FROM prestiti WHERE libro_id = {$mixed}");
$db->query("UPDATE copie SET stato = 'disponibile' WHERE libro_id = {$mixed}");
$check($integrity->recalculateBookAvailability($mixed) && $counters($mixed) === '0/2/non_disponibile', 'every copy kept for consultation: nothing available, title not lendable');
$all = $integrity->recalculateAllBookAvailability();
$check($all['errors'] === [] && $counters($mixed) === '0/2/non_disponibile', 'the global recalculation applies the same rule');

$cleanup();
$db->close();

if ($failed > 0) {
    echo "FAILED: {$failed} of " . ($passed + $failed) . " checks\n";
    exit(1);
}
echo "SUCCESS: {$passed} checks\n";

<?php
declare(strict_types=1);

/**
 * Behavioral suite for the wave-2 review fixes of branch
 * fix/circulation-coherence-0781 (queue/capacity coherence).
 *
 * Runs against the REAL database and the REAL controllers/services; only
 * touches data it creates (titles ZZ_QCC_%, emails zzqcc+%@test.local,
 * inventory ZZQCC-%). Hard-fails when the DB is unreachable.
 *
 * Covers:
 *  - F3 capacity gate overlap-aware: a reservation of a user at cap TODAY
 *    whose loans all end BEFORE the reserved window OCCUPIES the window;
 *    a user at cap DURING the window (incl. open-ended overdue) stays
 *    excluded (anti-starvation preserved);
 *  - user cancellation of a da_ritirare past its pickup_deadline lands on
 *    'scaduto' with a `loan.expired` audit event (parity with the sweep and
 *    admin cancelPickup) and frees the copy; the voluntary branch stays
 *    'annullato' + `loan.cancelled` and confirms by email with a
 *    recipient-localized reason;
 *  - queue promotion records `reservation.promoted` (SYSTEM, source
 *    'promotion') inside the transaction;
 *  - admin reactivation (update -> attiva) of a reservation on a book with
 *    NO copie rows is rejected with capacity_full, like store();
 *  - LoanMultiplicityPolicy dedup: the 3 request entry-points (POST
 *    /user/loan, POST /user/reserve, POST /api/books/{id}/reserve) block a
 *    duplicate title/user identically with the policy ON and OFF;
 *  - reservationsPage shows an ACTIVE loan whose book was soft-deleted with
 *    the REAL title (CI-SOFT-DELETE-EXEMPT join, single convention with the
 *    mobile API and the email senders).
 *
 * Dates derive from DateHelper::today() (application timezone).
 *
 * Run: php tests/queue-capacity-coherence-0781.unit.php
 * DB credentials: .env of the checkout, overridable via E2E_DB_SOCKET /
 * E2E_DB_HOST / E2E_DB_USER / E2E_DB_PASS / E2E_DB_NAME (this is a standalone
 * PHP suite — the /tmp/run-e2e.sh wrapper applies to the Playwright specs).
 */

use App\Controllers\ReservationManager;
use App\Controllers\ReservationsAdminController;
use App\Controllers\ReservationsController;
use App\Controllers\UserActionsController;
use App\Models\SettingsRepository;
use App\Services\CapacityService;
use App\Support\DateHelper;
use App\Support\NotificationService;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

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
    fwrite(STDERR, "FAIL: database unreachable — queue/capacity coherence suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$run = bin2hex(random_bytes(6));
$prefix = "ZZ_QCC_{$run}";
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK   ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

/* --------------------------------------------------------------------------
 * Settings preserved and restored (absence vs value, like loan-edge-cases)
 * ------------------------------------------------------------------------ */
$settings = new SettingsRepository($db);
$origMaxLoans = $settings->get('loans', 'max_active_loans_per_user');
$origMultiplicity = $settings->get('loans', 'allow_multiple_loans_same_book');

$bookIds = [];
$userIds = [];

$cleanup = static function () use ($db, &$bookIds, &$userIds, $settings, $origMaxLoans, $origMultiplicity): void {
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
    try { $db->query("DELETE FROM email_delivery_outbox WHERE recipient_email LIKE 'zzqcc+%@test.local'"); } catch (Throwable) {}
    if ($origMaxLoans === null) {
        $settings->delete('loans', 'max_active_loans_per_user');
    } else {
        $settings->set('loans', 'max_active_loans_per_user', (string) $origMaxLoans);
    }
    if ($origMultiplicity === null) {
        $settings->delete('loans', 'allow_multiple_loans_same_book');
    } else {
        $settings->set('loans', 'allow_multiple_loans_same_book', (string) $origMultiplicity);
    }
};
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, 'FAIL: uncaught ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
    $db->close();
    exit(1);
});

/* --------------------------------------------------------------------------
 * Fixture builders
 * ------------------------------------------------------------------------ */
$makeBook = static function (string $suffix, int $copies) use ($db, $prefix, &$bookIds): array {
    $title = "{$prefix}_{$suffix}";
    $stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES (?, ?, ?, 'disponibile')");
    $stmt->bind_param('sii', $title, $copies, $copies);
    $stmt->execute();
    $bookId = (int) $db->insert_id;
    $stmt->close();
    $bookIds[] = $bookId;
    $copyIds = [];
    for ($i = 1; $i <= $copies; $i++) {
        $inv = 'ZZQCC-' . strtoupper($suffix) . $i . '-' . strtoupper(substr($prefix, -6));
        $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
        $stmt->bind_param('is', $bookId, $inv);
        $stmt->execute();
        $copyIds[] = (int) $db->insert_id;
        $stmt->close();
    }
    return [$bookId, $copyIds];
};

$makeUser = static function (string $suffix) use ($db, $run, &$userIds): array {
    $email = "zzqcc+{$suffix}-{$run}@test.local";
    $card = 'ZQ' . strtoupper($suffix) . strtoupper(substr($run, 0, 8));
    $password = password_hash('QccSuite!1', PASSWORD_DEFAULT);
    $stmt = $db->prepare(
        "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata)
         VALUES (?, 'Qcc', ?, ?, ?, 'standard', 'attivo', 1)"
    );
    $cog = ucfirst($suffix);
    $stmt->bind_param('ssss', $card, $cog, $email, $password);
    $stmt->execute();
    $userId = (int) $db->insert_id;
    $stmt->close();
    $userIds[] = $userId;
    return [$userId, $email];
};

$today = DateHelper::today();
$d = static fn (int $offset): string => (new DateTimeImmutable($today))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');

$mkLoan = static function (int $bookId, ?int $copyId, int $userId, string $start, string $end, string $stato, int $attivo, ?string $pickupDeadline = null) use ($db): int {
    $stmt = $db->prepare(
        "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, pickup_deadline)
         VALUES (?, ?, ?, ?, ?, ?, 'diretto', ?, ?)"
    );
    $stmt->bind_param('iiisssis', $bookId, $copyId, $userId, $start, $end, $stato, $attivo, $pickupDeadline);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$mkReservation = static function (int $bookId, int $userId, string $start, string $end, string $stato = 'attiva', int $queuePos = 1) use ($db): int {
    $startDt = $start . ' 00:00:00';
    $endDt = $end . ' 23:59:59';
    $stmt = $db->prepare(
        "INSERT INTO prenotazioni (libro_id, utente_id, queue_position, stato, data_prenotazione, data_scadenza_prenotazione, data_inizio_richiesta, data_fine_richiesta)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('iiisssss', $bookId, $userId, $queuePos, $stato, $startDt, $endDt, $start, $end);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$loanCol = static function (int $loanId, string $col) use ($db): ?string {
    $res = $db->query("SELECT {$col} AS v FROM prestiti WHERE id = {$loanId}");
    $row = $res ? $res->fetch_assoc() : null;
    return $row === null ? null : ($row['v'] === null ? null : (string) $row['v']);
};

$reservationCol = static function (int $rid, string $col) use ($db): ?string {
    $res = $db->query("SELECT {$col} AS v FROM prenotazioni WHERE id = {$rid}");
    $row = $res ? $res->fetch_assoc() : null;
    return $row === null ? null : ($row['v'] === null ? null : (string) $row['v']);
};

/** Latest audit event of a given _activity.event for a book (see sweep suite). */
$auditEvent = static function (int $bookId, string $event, ?int $entityId = null) use ($db): ?array {
    $res = $db->query(
        "SELECT utente_id, dati_nuovi FROM log_modifiche
         WHERE tabella = 'libri' AND record_id = {$bookId}
         ORDER BY id DESC"
    );
    while ($res && ($row = $res->fetch_assoc())) {
        $decoded = json_decode((string) $row['dati_nuovi'], true);
        $meta = is_array($decoded) && isset($decoded['_activity']) && is_array($decoded['_activity'])
            ? $decoded['_activity']
            : [];
        if (($meta['event'] ?? '') !== $event) {
            continue;
        }
        if ($entityId !== null && (int) ($meta['entity_id'] ?? 0) !== $entityId) {
            continue;
        }
        return ['utente_id' => $row['utente_id'], 'meta' => $meta];
    }
    return null;
};

/* --------------------------------------------------------------------------
 * Notification recorder: keeps the REAL locale/lookup helpers but never
 * touches SMTP. Injected through the createNotificationService() seam.
 * ------------------------------------------------------------------------ */
final class QccRecordingNotificationService extends NotificationService
{
    /** @var list<array{type:string,args:array}> */
    public static array $calls = [];

    public function sendPickupCancelledNotification(int $loanId, string $reason = '', string $terminalState = 'annullato', ?string $pickupDeadline = null): bool
    {
        self::$calls[] = ['type' => 'pickup_cancelled', 'args' => ['loanId' => $loanId, 'reason' => $reason, 'terminalState' => $terminalState, 'pickupDeadline' => $pickupDeadline]];
        return true;
    }

    public function sendReservationCancelledNotification(string $email, array $variables): bool
    {
        self::$calls[] = ['type' => 'reservation_cancelled', 'args' => ['email' => $email, 'variables' => $variables]];
        return true;
    }

    public function notifyLoanRequest(int $loanId): bool
    {
        self::$calls[] = ['type' => 'loan_request', 'args' => ['loanId' => $loanId]];
        return true;
    }
}

final class QccUserActionsController extends UserActionsController
{
    protected function createNotificationService(mysqli $db): NotificationService
    {
        return new QccRecordingNotificationService($db);
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 * 1) F3 — capacity gate overlap-aware
 * ═══════════════════════════════════════════════════════════════════════════ */
echo "1. F3 — capacity cap valutato sulla finestra prenotata, non su oggi\n";
$settings->set('loans', 'max_active_loans_per_user', '1');

// U-A al cap OGGI: prestito in_corso che finisce PRIMA della finestra prenotata.
[$bookF3, ] = $makeBook('F3', 1);
[$bookOtherA, [$copyOtherA]] = $makeBook('F3OA', 1);
[$userA] = $makeUser('f3a');
$mkLoan($bookOtherA, $copyOtherA, $userA, $d(-5), $d(2), 'in_corso', 1);
$db->query("UPDATE copie SET stato = 'prestato' WHERE id = {$copyOtherA}");
$mkReservation($bookF3, $userA, $d(10), $d(20), 'attiva', 1);

$capacity = new CapacityService($db);
$check(
    $capacity->occupiedCount($bookF3, $d(10), $d(20)) === 1,
    'F3a: la prenotazione di un utente al cap oggi (prestiti che finiscono prima della finestra) OCCUPA la finestra'
);
$check(
    $capacity->hasFreeCapacity($bookF3, $d(10), $d(20)) === false,
    'F3a: hasFreeCapacity vede la finestra piena (1 copia, prenotazione contata)'
);

// U-B al cap DURANTE la finestra: prestito che copre la finestra -> esclusa.
[$bookF3b, ] = $makeBook('F3B', 1);
[$bookOtherB, [$copyOtherB]] = $makeBook('F3OB', 1);
[$userB] = $makeUser('f3b');
$mkLoan($bookOtherB, $copyOtherB, $userB, $d(-1), $d(30), 'in_corso', 1);
$db->query("UPDATE copie SET stato = 'prestato' WHERE id = {$copyOtherB}");
$mkReservation($bookF3b, $userB, $d(10), $d(20), 'attiva', 1);
$check(
    $capacity->hasFreeCapacity($bookF3b, $d(10), $d(20)) === true,
    'F3b: utente al cap DURANTE la finestra -> prenotazione esclusa (anti-starvation preservato)'
);

// U-C con in_ritardo open-ended: la copia è ancora fuori, copre qualunque finestra.
[$bookF3c, ] = $makeBook('F3C', 1);
[$bookOtherC, [$copyOtherC]] = $makeBook('F3OC', 1);
[$userC] = $makeUser('f3c');
$mkLoan($bookOtherC, $copyOtherC, $userC, $d(-20), $d(-3), 'in_ritardo', 1);
$db->query("UPDATE copie SET stato = 'prestato' WHERE id = {$copyOtherC}");
$mkReservation($bookF3c, $userC, $d(10), $d(20), 'attiva', 1);
$check(
    $capacity->hasFreeCapacity($bookF3c, $d(10), $d(20)) === true,
    'F3c: in_ritardo open-ended conta contro qualunque finestra futura -> prenotazione esclusa'
);

// Ripristina il cap per non influenzare i blocchi successivi.
if ($origMaxLoans === null) {
    $settings->delete('loans', 'max_active_loans_per_user');
} else {
    $settings->set('loans', 'max_active_loans_per_user', (string) $origMaxLoans);
}

/* ═══════════════════════════════════════════════════════════════════════════
 * 2) Annullo utente post-deadline -> scaduto + loan.expired
 * ═══════════════════════════════════════════════════════════════════════════ */
echo "2. cancelLoan utente su da_ritirare con deadline trascorsa -> scaduto\n";
[$bookExp, [$copyExp]] = $makeBook('EXP', 1);
[$userExp] = $makeUser('exp');
$db->query("UPDATE copie SET stato = 'prenotato' WHERE id = {$copyExp}");
$loanExp = $mkLoan($bookExp, $copyExp, $userExp, $d(-5), $d(9), 'da_ritirare', 1, $d(-1));

QccRecordingNotificationService::$calls = [];
$_SESSION['user'] = ['id' => $userExp, 'tipo_utente' => 'standard'];
$request = (new ServerRequestFactory())
    ->createServerRequest('POST', '/user/loan/cancel')
    ->withParsedBody(['loan_id' => (string) $loanExp]);
$response = (new QccUserActionsController())->cancelLoan($request, new SlimResponse(), $db);
unset($_SESSION['user']);

$check(
    $response->getStatusCode() === 302 && str_contains($response->getHeaderLine('Location'), 'canceled=1'),
    'annullo post-deadline: redirect di successo'
);
$check(
    $loanCol($loanExp, 'stato') === 'scaduto' && $loanCol($loanExp, 'attivo') === '0' && $loanCol($loanExp, 'pickup_deadline') === null,
    'annullo post-deadline: stato terminale scaduto, attivo=0, deadline azzerata'
);
$expEvent = $auditEvent($bookExp, 'loan.expired', $loanExp);
$check(
    $expEvent !== null && ($expEvent['meta']['source'] ?? '') === 'user',
    'annullo post-deadline: evento audit loan.expired con source user'
);
$copyExpState = $db->query("SELECT stato FROM copie WHERE id = {$copyExp}")->fetch_assoc()['stato'] ?? '';
$check($copyExpState === 'disponibile', 'annullo post-deadline: copia rilasciata (disponibile)');
$expMail = array_values(array_filter(QccRecordingNotificationService::$calls, fn ($c) => $c['type'] === 'pickup_cancelled'));
$check(
    count($expMail) === 1 && $expMail[0]['args']['terminalState'] === 'scaduto' && $expMail[0]['args']['pickupDeadline'] === $d(-1),
    'annullo post-deadline: email di scadenza ritiro (stessa scelta del gemello admin cancelPickup)'
);

/* ── controllo: annullo volontario (deadline futura) resta annullato ──────── */
echo "3. cancelLoan utente con deadline futura -> annullato + email col motivo localizzato\n";
[$bookVol, [$copyVol]] = $makeBook('VOL', 1);
[$userVol, $emailVol] = $makeUser('vol');
$db->query("UPDATE copie SET stato = 'prenotato' WHERE id = {$copyVol}");
$loanVol = $mkLoan($bookVol, $copyVol, $userVol, $d(0), $d(14), 'da_ritirare', 1, $d(2));

QccRecordingNotificationService::$calls = [];
$_SESSION['user'] = ['id' => $userVol, 'tipo_utente' => 'standard'];
$request = (new ServerRequestFactory())
    ->createServerRequest('POST', '/user/loan/cancel')
    ->withParsedBody(['loan_id' => (string) $loanVol]);
(new QccUserActionsController())->cancelLoan($request, new SlimResponse(), $db);
unset($_SESSION['user']);

$check(
    $loanCol($loanVol, 'stato') === 'annullato' && $loanCol($loanVol, 'attivo') === '0',
    'annullo volontario: resta annullato (deadline non trascorsa)'
);
$volEvent = $auditEvent($bookVol, 'loan.cancelled', $loanVol);
$check($volEvent !== null && ($volEvent['meta']['source'] ?? '') === 'user', 'annullo volontario: evento loan.cancelled source user');
$volMail = array_values(array_filter(QccRecordingNotificationService::$calls, fn ($c) => $c['type'] === 'pickup_cancelled'));
$probeNs = new NotificationService($db);
$expectedReason = $probeNs->translateInLocale('Annullamento effettuato su tua richiesta', $probeNs->resolveRecipientLocale($emailVol));
$check(
    count($volMail) === 1
        && $volMail[0]['args']['terminalState'] === 'annullato'
        && $volMail[0]['args']['reason'] === $expectedReason
        && $volMail[0]['args']['reason'] !== '',
    'annullo volontario: email di conferma col motivo tradotto nel locale del destinatario'
);

/* ═══════════════════════════════════════════════════════════════════════════
 * 4) Promozione coda -> reservation.promoted (SYSTEM, source promotion)
 * ═══════════════════════════════════════════════════════════════════════════ */
echo "4. promozione: audit reservation.promoted dentro la transazione\n";
[$bookProm, ] = $makeBook('PROM', 1);
[$userProm] = $makeUser('prom');
$ridProm = $mkReservation($bookProm, $userProm, $d(0), $d(10), 'attiva', 1);

// Transazione esterna: le notifiche restano differite (mai flushate qui),
// quindi il test non tocca SMTP; l'audit è comunque registrato e committato.
$db->begin_transaction();
$manager = new ReservationManager($db);
$manager->setExternalTransaction(true);
$promoted = $manager->processBookAvailability($bookProm);
$db->commit();

$check($promoted === true, 'promozione: processBookAvailability converte la prenotazione');
$check($reservationCol($ridProm, 'stato') === 'completata', 'promozione: prenotazione completata');
$promLoan = $db->query("SELECT id FROM prestiti WHERE libro_id = {$bookProm} AND utente_id = {$userProm} AND stato = 'pendente' AND copia_id IS NOT NULL")->fetch_assoc();
$check($promLoan !== null, 'promozione: prestito pendente con copia creato');
$promEvent = $auditEvent($bookProm, 'reservation.promoted', $ridProm);
$check(
    $promEvent !== null && $promEvent['utente_id'] === null && ($promEvent['meta']['source'] ?? '') === 'promotion',
    'promozione: evento reservation.promoted con operatore SYSTEM (utente_id NULL) e source promotion'
);

/* ═══════════════════════════════════════════════════════════════════════════
 * 5) Riattivazione admin su libro senza righe copie -> rifiutata
 * ═══════════════════════════════════════════════════════════════════════════ */
echo "5. update() admin: riattivazione su libro senza copie fisiche rifiutata\n";
// Libro legacy: NESSUNA riga in copie, capacità solo da copie_totali.
$titleNoCopies = "{$prefix}_NOCOPIE";
$stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES (?, 1, 1, 'disponibile')");
$stmt->bind_param('s', $titleNoCopies);
$stmt->execute();
$bookNoCopies = (int) $db->insert_id;
$stmt->close();
$bookIds[] = $bookNoCopies;
[$userReact] = $makeUser('react');
$ridNoCopies = $mkReservation($bookNoCopies, $userReact, $d(3), $d(8), 'annullata', 1);

$adminController = new ReservationsAdminController();
$reactivateBody = [
    'stato' => 'attiva',
    'data_prenotazione' => $d(3),
    'data_scadenza_prenotazione' => $d(8),
    'data_inizio_richiesta' => $d(3),
    'data_fine_richiesta' => $d(8),
];
$request = (new ServerRequestFactory())
    ->createServerRequest('POST', '/admin/reservations/edit/' . $ridNoCopies)
    ->withParsedBody($reactivateBody);
$response = $adminController->update($request, new SlimResponse(), $db, $ridNoCopies);
$check(
    $response->getStatusCode() === 302 && str_contains($response->getHeaderLine('Location'), 'error=capacity_full'),
    'riattivazione senza copie: rifiutata con capacity_full (stesso errore di store)'
);
$check($reservationCol($ridNoCopies, 'stato') === 'annullata', 'riattivazione senza copie: stato invariato (annullata)');

// Controllo: con una copia fisica la stessa riattivazione passa.
[$bookReactOk, ] = $makeBook('REOK', 1);
[$userReactOk] = $makeUser('reok');
$ridReactOk = $mkReservation($bookReactOk, $userReactOk, $d(3), $d(8), 'annullata', 1);
$request = (new ServerRequestFactory())
    ->createServerRequest('POST', '/admin/reservations/edit/' . $ridReactOk)
    ->withParsedBody($reactivateBody);
$response = $adminController->update($request, new SlimResponse(), $db, $ridReactOk);
$check(
    $response->getStatusCode() === 302 && str_contains($response->getHeaderLine('Location'), 'updated=1')
        && $reservationCol($ridReactOk, 'stato') === 'attiva',
    'riattivazione con copia fisica: accettata (controllo positivo)'
);

/* ═══════════════════════════════════════════════════════════════════════════
 * 6) Multiplicity dedup: 3 entry-point, policy ON e OFF
 * ═══════════════════════════════════════════════════════════════════════════ */
echo "6. multiplicity: i 3 entry-point bloccano il duplicato con policy ON e OFF\n";
[$bookDup, [$copyDup1, $copyDup2]] = $makeBook('DUP', 2);
[$userDup] = $makeUser('dup');
// Prestito aperto sul titolo: con 2 copie la capacità NON è il fattore
// bloccante, quindi ogni rifiuto qui sotto è la regola per-titolo.
$mkLoan($bookDup, $copyDup1, $userDup, $d(-2), $d(5), 'in_corso', 1);
$db->query("UPDATE copie SET stato = 'prestato' WHERE id = {$copyDup1}");

$callLoan = static function (int $bookId, int $userId) use ($db): string {
    $_SESSION['user'] = ['id' => $userId, 'tipo_utente' => 'standard'];
    $_SERVER['HTTP_REFERER'] = '/';
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/user/loan')
        ->withParsedBody(['libro_id' => (string) $bookId]);
    $result = (new QccUserActionsController())->loan($request, new SlimResponse(), $db);
    unset($_SESSION['user'], $_SERVER['HTTP_REFERER']);
    return $result->getHeaderLine('Location');
};
$callReserve = static function (int $bookId, int $userId) use ($db, $d): string {
    $_SESSION['user'] = ['id' => $userId, 'tipo_utente' => 'standard'];
    $_SERVER['HTTP_REFERER'] = '/';
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/user/reserve')
        ->withParsedBody(['libro_id' => (string) $bookId, 'desired_date' => $d(1)]);
    $result = (new QccUserActionsController())->reserve($request, new SlimResponse(), $db);
    unset($_SESSION['user'], $_SERVER['HTTP_REFERER']);
    return $result->getHeaderLine('Location');
};
$callApiReserve = static function (int $bookId, int $userId) use ($db, $d): array {
    $_SESSION['user'] = ['id' => $userId, 'tipo_utente' => 'standard'];
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', "/api/books/{$bookId}/reserve")
        ->withParsedBody(['start_date' => $d(1), 'end_date' => $d(10)]);
    $result = (new ReservationsController($db))->createReservation($request, new SlimResponse(), ['id' => (string) $bookId]);
    unset($_SESSION['user']);
    return ['status' => $result->getStatusCode(), 'body' => json_decode((string) $result->getBody(), true) ?: []];
};

foreach ([['0', 'OFF'], ['1', 'ON']] as [$policyValue, $policyLabel]) {
    $settings->set('loans', 'allow_multiple_loans_same_book', $policyValue);

    $loc = $callLoan($bookDup, $userDup);
    $check(str_contains($loc, 'loan_error=duplicate'), "policy {$policyLabel}: POST /user/loan rifiuta il duplicato");

    $loc = $callReserve($bookDup, $userDup);
    $check(str_contains($loc, 'reserve_error=duplicate'), "policy {$policyLabel}: POST /user/reserve rifiuta il duplicato");

    $api = $callApiReserve($bookDup, $userDup);
    $check(
        $api['status'] === 400 && ($api['body']['message'] ?? '') === __('Hai già un prestito attivo o in attesa per questo libro'),
        "policy {$policyLabel}: POST /api/books/{id}/reserve rifiuta il duplicato con lo stesso messaggio"
    );

    // Nessuna riga creata dai tentativi rifiutati.
    $rows = (int) ($db->query("SELECT COUNT(*) c FROM prestiti WHERE libro_id = {$bookDup}")->fetch_assoc()['c'] ?? 0);
    $resRows = (int) ($db->query("SELECT COUNT(*) c FROM prenotazioni WHERE libro_id = {$bookDup}")->fetch_assoc()['c'] ?? 0);
    $check($rows === 1 && $resRows === 0, "policy {$policyLabel}: nessuna riga creata dai tentativi rifiutati");
}
if ($origMultiplicity === null) {
    $settings->delete('loans', 'allow_multiple_loans_same_book');
} else {
    $settings->set('loans', 'allow_multiple_loans_same_book', (string) $origMultiplicity);
}

/* ═══════════════════════════════════════════════════════════════════════════
 * 7) Prestito attivo su libro soft-deleted visibile nella pagina utente
 * ═══════════════════════════════════════════════════════════════════════════ */
echo "7. reservationsPage: prestito attivo su libro archiviato visibile (fix 13)\n";
[$bookSd, [$copySd]] = $makeBook('SDEL', 1);
[$userSd] = $makeUser('sdel');
$loanSd = $mkLoan($bookSd, $copySd, $userSd, $d(-3), $d(10), 'in_corso', 1);
$db->query("UPDATE copie SET stato = 'prestato' WHERE id = {$copySd}");
$db->query("UPDATE libri SET deleted_at = NOW() WHERE id = {$bookSd}");

$_SESSION['user'] = ['id' => $userSd, 'nome' => 'Qcc', 'cognome' => 'Sdel', 'tipo_utente' => 'standard'];
ob_start();
try {
    (new QccUserActionsController())->reservationsPage(
        (new ServerRequestFactory())->createServerRequest('GET', '/user/reservations'),
        new SlimResponse(),
        $db
    );
    $html = (string) ob_get_clean();
} catch (Throwable $renderError) {
    ob_end_clean();
    throw $renderError;
} finally {
    unset($_SESSION['user']);
}
// Convenzione unica (review M4): per gli impegni PROPRI dell'utente il
// titolo archiviato si mostra REALE, come già fanno mobile ed email —
// l'utente riceve solleciti per quel prestito e deve riconoscerlo.
$check(
    str_contains($html, "{$prefix}_SDEL"),
    'pagina utente: il prestito attivo sul libro archiviato compare col titolo reale'
);

/* ═══════════════════════════════════════════════════════════════════════════ */
// 8. Contratti sorgente dei follow-up di review (M2 / L5 / L2): asserzioni
// statiche sul codice — proteggono decisioni che un refactor potrebbe
// silenziosamente invertire senza far fallire alcun percorso felice.
echo "8. contratti sorgente follow-up review (M2/L5/L2)\n";
$adminSrc = (string) file_get_contents($root . '/app/Controllers/ReservationsAdminController.php');
$check(
    str_contains($adminSrc, "if (\$oldStato === 'attiva' || \$reactivating)"),
    'L5: anche la riattivazione (annullata/completata -> attiva) promuove subito la coda'
);
$check(
    preg_match('/try\s*\{\s*\$reservationManager->flushDeferredNotifications\(\);/', $adminSrc) === 1,
    'L5: il flush post-commit delle notifiche promo è dentro un try/catch'
);
$maintCtrlSrc = (string) file_get_contents($root . '/app/Controllers/MaintenanceController.php');
$circPos = strpos($maintCtrlSrc, 'runIfNeeded(0)');
// Offset da $circPos: la stessa chiamata esiste anche in fixIntegrityIssues(),
// prima nel file — qui interessa l'occorrenza DENTRO performMaintenance.
$fixPos = $circPos === false ? false : strpos($maintCtrlSrc, '$fixResult = $integrity->fixDataInconsistencies();', $circPos);
$check(
    $circPos !== false && $fixPos !== false && $fixPos > $circPos
    && preg_match('/if\s*\(\(\$circulation\[.skipped.\]\s*\?\?\s*false\)\s*===\s*true\)\s*\{\s*\$results\[.circulation_note.\]/', $maintCtrlSrc) === 1,
    'M2: il claim salta SOLO la circolazione; la riparazione dati gira comunque'
);
$viewSrc = (string) file_get_contents($root . '/app/Views/prestiti/dettagli_prestito.php');
$check(
    str_contains($viewSrc, "ConfigStore::get('app.currency'"),
    "L2: la sanzione nella vista usa la valuta configurata (app.currency), non l'euro fisso"
);

$cleanup();
$db->close();

echo PHP_EOL . "Passed: {$passed}, Failed: {$failed}" . PHP_EOL;
if ($failed > 0) {
    exit(1);
}
echo "ALL PASS\n";

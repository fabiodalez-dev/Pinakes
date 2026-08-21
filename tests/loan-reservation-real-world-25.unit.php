<?php
declare(strict_types=1);

/**
 * Twenty-five real-DB circulation scenarios covering loans, reservations,
 * overlap/capacity, overdue transitions, pickup expiry and notification
 * idempotency. Every scenario creates physical books/copies and real users in
 * MySQL, then exercises the production services.
 *
 * The maintenance methods are global sweeps, so this suite belongs on an
 * isolated test database (as used by CI). Rows created here are marked with
 * ZZ_RW25_* / @rw25.test.local and are removed on success or failure.
 *
 * Run: php tests/loan-reservation-real-world-25.unit.php
 */

use App\Controllers\ReservationManager;
use App\Services\CapacityService;
use App\Support\DateHelper;
use App\Support\EmailService;
use App\Support\Mailer;
use App\Support\MaintenanceService;
use App\Support\NotificationService;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

// Global-sweep safety valve: sendLoanExpirationWarnings()/
// sendOverdueLoanNotifications() and the cleanup DELETEs mutate whatever
// database this handle reaches. Never fall back to .env (a dev box's live DB):
// run only when the caller explicitly names a dedicated test database.
$dbName = getenv('E2E_DB_NAME') ?: '';
if ($dbName === '') {
    fwrite(STDERR, "FAIL: refusing to run — this suite performs GLOBAL maintenance sweeps and cleanup DELETEs. Export E2E_DB_NAME (plus E2E_DB_HOST/E2E_DB_USER/E2E_DB_PASS or E2E_DB_SOCKET) pointing at a dedicated test database.\n");
    exit(1);
}

$dbHost = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbPort = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));
// An explicit E2E_DB_HOST must not be silently overridden by a leftover local
// socket: only use a socket the caller (or .env, absent E2E_DB_HOST) names.
$socket = getenv('E2E_DB_SOCKET') ?: (getenv('E2E_DB_HOST') ? '' : ($env['DB_SOCKET'] ?? ''));

try {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $db->set_charset('utf8mb4');
    // Production writers bind the application-local date on every
    // connection (container/cron/scripts bootstrap); the circulation
    // triggers otherwise fall back to the database's UTC CURRENT_DATE(),
    // which disagrees with app.timezone between 22:00 and 24:00 UTC.
    \App\Support\DateHelper::synchronizeDatabaseSession($db);
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for the 25 real-world scenarios: {$e->getMessage()}\n");
    exit(1);
}

final class RealWorldFakeEmailService extends EmailService
{
    /** @var list<array{to:string,template:string}> */
    public array $sent = [];

    public function __construct()
    {
        // No real transport: these scenarios verify notification claiming and
        // idempotency deterministically, without depending on SMTP availability.
    }

    public function sendTemplate(
        string $to,
        string $templateName,
        array $variables = [],
        ?string $locale = null,
        array $attachments = []
    ): bool {
        $this->sent[] = ['to' => $to, 'template' => $templateName];
        return true;
    }
}

$run = bin2hex(random_bytes(6));
$titlePrefix = "ZZ_RW25_{$run}_";
$emailSuffix = '@rw25.test.local';
$today = DateHelper::today();
$d = static fn (int $offset): string => date(
    'Y-m-d',
    strtotime($today . ($offset >= 0 ? " +{$offset} days" : " {$offset} days"))
);
$withDatabaseDate = static function (string $date, callable $callback) use ($db): mixed {
    $safeDate = $db->real_escape_string($date);
    $db->query("SET timestamp = UNIX_TIMESTAMP('{$safeDate} 12:00:00')");
    // The circulation triggers read the connection-bound application date
    // before falling back to CURRENT_DATE(), so warping the clock must warp
    // the bound date too — and restore the real application day afterwards.
    $db->query("SET @pinakes_application_date = '{$safeDate}'");
    try {
        return $callback();
    } finally {
        $db->query('SET timestamp = 0');
        \App\Support\DateHelper::synchronizeDatabaseSession($db);
    }
};

$cleanup = static function () use ($db, $titlePrefix, $run, $emailSuffix): void {
    $titleLike = $db->real_escape_string($titlePrefix) . '%';
    $db->query("DELETE n FROM admin_notifications n JOIN prestiti p ON p.id = n.related_id JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE '{$titleLike}'");
    $db->query("DELETE r FROM prenotazioni r JOIN libri l ON l.id = r.libro_id WHERE l.titolo LIKE '{$titleLike}'");
    $db->query("DELETE p FROM prestiti p JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE '{$titleLike}'");
    $db->query("DELETE c FROM copie c JOIN libri l ON l.id = c.libro_id WHERE l.titolo LIKE '{$titleLike}'");
    $db->query("DELETE FROM libri WHERE titolo LIKE '{$titleLike}'");
    $emailLike = $db->real_escape_string("rw25-%-{$run}{$emailSuffix}");
    $db->query("DELETE FROM utenti WHERE email LIKE '{$emailLike}'");
};

$userSequence = 0;
$mkUser = static function (string $tag) use ($db, $run, $emailSuffix, &$userSequence): array {
    $userSequence++;
    $card = 'RW25' . strtoupper(substr($run, 0, 8)) . $userSequence;
    $email = "rw25-{$tag}-{$userSequence}-{$run}{$emailSuffix}";
    $surname = "RW25 {$tag}";
    $stmt = $db->prepare("INSERT INTO utenti
        (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata, locale)
        VALUES (?, 'Test', ?, ?, 'x', 'attivo', 'standard', 1, 'it_IT')");
    $stmt->bind_param('sss', $card, $surname, $email);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return ['id' => $id, 'email' => $email];
};

$bookSequence = 0;
$mkBook = static function (string $tag, array $copyStates) use ($db, $titlePrefix, $run, &$bookSequence): array {
    $bookSequence++;
    $title = $titlePrefix . $bookSequence . '_' . $tag;
    $copies = count($copyStates);
    $stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato, created_at, updated_at)
                          VALUES (?, ?, ?, 'disponibile', NOW(), NOW())");
    $stmt->bind_param('sii', $title, $copies, $copies);
    $stmt->execute();
    $bookId = (int) $db->insert_id;
    $stmt->close();

    $copyIds = [];
    foreach ($copyStates as $index => $state) {
        $inventory = 'RW25-' . substr($run, 0, 8) . "-{$bookSequence}-" . ($index + 1);
        $stmt = $db->prepare('INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $bookId, $inventory, $state);
        $stmt->execute();
        $copyIds[] = (int) $db->insert_id;
        $stmt->close();
    }
    return [$bookId, $copyIds];
};

$mkLoan = static function (
    int $bookId,
    ?int $copyId,
    int $userId,
    string $status,
    string $start,
    string $end,
    int $active = 1,
    ?string $pickupDeadline = null,
    string $origin = 'diretto',
    int $warningSent = 0,
    int $overdueSent = 0
) use ($db): int {
    $stmt = $db->prepare("INSERT INTO prestiti
        (libro_id, copia_id, utente_id, data_prestito, data_scadenza, pickup_deadline,
         stato, origine, attivo, warning_sent, overdue_notification_sent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param(
        'iiisssssiii',
        $bookId,
        $copyId,
        $userId,
        $start,
        $end,
        $pickupDeadline,
        $status,
        $origin,
        $active,
        $warningSent,
        $overdueSent
    );
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$mkReservation = static function (
    int $bookId,
    int $userId,
    ?string $start,
    ?string $end,
    ?string $deadline,
    int $position,
    string $status = 'attiva'
) use ($db): int {
    $stmt = $db->prepare("INSERT INTO prenotazioni
        (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta,
         data_scadenza_prenotazione, queue_position, stato, notifica_inviata)
        VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
    $stmt->bind_param('iisssis', $bookId, $userId, $start, $end, $deadline, $position, $status);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$loanRow = static function (int $loanId) use ($db): array {
    $stmt = $db->prepare('SELECT * FROM prestiti WHERE id = ?');
    $stmt->bind_param('i', $loanId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
};

$reservationRow = static function (int $reservationId) use ($db): array {
    $stmt = $db->prepare('SELECT * FROM prenotazioni WHERE id = ?');
    $stmt->bind_param('i', $reservationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
};

$copyState = static function (int $copyId) use ($db): string {
    $stmt = $db->prepare('SELECT stato FROM copie WHERE id = ?');
    $stmt->bind_param('i', $copyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (string) ($row['stato'] ?? '');
};

$setCopyState = static function (int $copyId, string $state) use ($db): void {
    $stmt = $db->prepare('UPDATE copie SET stato = ? WHERE id = ?');
    $stmt->bind_param('si', $state, $copyId);
    $stmt->execute();
    $stmt->close();
};

$returnLoan = static function (int $loanId, int $copyId, string $nextCopyState = 'disponibile') use ($db, $today): void {
    $stmt = $db->prepare("UPDATE prestiti SET stato = 'restituito', attivo = 0, data_restituzione = ? WHERE id = ?");
    $stmt->bind_param('si', $today, $loanId);
    $stmt->execute();
    $stmt->close();
    $stmt = $db->prepare('UPDATE copie SET stato = ? WHERE id = ?');
    $stmt->bind_param('si', $nextCopyState, $copyId);
    $stmt->execute();
    $stmt->close();
};

$countConversionLoans = static function (int $bookId, int $userId) use ($db): int {
    $stmt = $db->prepare("SELECT COUNT(*) AS n FROM prestiti WHERE libro_id = ? AND utente_id = ? AND origine = 'prenotazione'");
    $stmt->bind_param('ii', $bookId, $userId);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    $stmt->close();
    return $count;
};

$injectFakeEmail = static function (NotificationService $service): RealWorldFakeEmailService {
    $fake = new RealWorldFakeEmailService();
    $property = new ReflectionProperty(NotificationService::class, 'emailService');
    $property->setValue($service, $fake);
    return $fake;
};

$setMailerReachable = static function (bool $reachable): void {
    $property = new ReflectionProperty(Mailer::class, 'smtpReachable');
    $property->setValue(null, $reachable);
};

$failed = 0;
$testNumber = 0;
$scenario = static function (string $description, callable $test) use (&$failed, &$testNumber): void {
    $testNumber++;
    try {
        $passed = $test() === true;
    } catch (\Throwable $e) {
        $passed = false;
        $description .= ' — ' . $e->getMessage();
    }
    printf("[%02d/25] %s: %s\n", $testNumber, $passed ? 'PASS' : 'FAIL', $description);
    if (!$passed) {
        $failed++;
    }
};

$cleanup();
$setMailerReachable(false); // lifecycle tests must not depend on a real SMTP server
$maintenance = new MaintenanceService($db);

try {
    $scenario('issue #366: an unreturned overdue loan blocks ready-for-pickup promotion', function () use ($mkUser, $mkBook, $mkLoan, $setCopyState, $maintenance, $loanRow, $d): bool {
        $borrower = $mkUser('s01a');
        $next = $mkUser('s01b');
        [$bookId, [$copyId]] = $mkBook('s01', ['disponibile']);
        $predecessor = $mkLoan($bookId, $copyId, $borrower['id'], 'in_corso', $d(-20), $d(-2));
        $setCopyState($copyId, 'prestato');
        $successor = $mkLoan($bookId, null, $next['id'], 'prenotato', $d(-1), $d(10));
        $maintenance->updateOverdueLoans();
        $maintenance->activateScheduledLoans();
        $before = $loanRow($predecessor);
        $after = $loanRow($successor);
        return ($before['stato'] ?? '') === 'in_ritardo'
            && ($after['stato'] ?? '') === 'prenotato'
            && ($after['pickup_deadline'] ?? null) === null;
    });

    $scenario('a returned predecessor lets the scheduled successor become ready', function () use ($mkUser, $mkBook, $mkLoan, $maintenance, $loanRow, $setCopyState, $d): bool {
        $old = $mkUser('s02a');
        $next = $mkUser('s02b');
        [$bookId, [$copyId]] = $mkBook('s02', ['disponibile']);
        $mkLoan($bookId, $copyId, $old['id'], 'restituito', $d(-20), $d(-2), 0);
        $successor = $mkLoan($bookId, $copyId, $next['id'], 'prenotato', $d(0), $d(10));
        $setCopyState($copyId, 'prenotato');
        $maintenance->activateScheduledLoans();
        $row = $loanRow($successor);
        return ($row['stato'] ?? '') === 'da_ritirare'
            && !empty($row['pickup_deadline'])
            && $row['pickup_deadline'] <= $row['data_scadenza'];
    });

    $scenario('a reservation pinned to an out copy moves to a free sibling before pickup', function () use ($mkUser, $mkBook, $mkLoan, $setCopyState, $maintenance, $loanRow, $d, $withDatabaseDate): bool {
        $old = $mkUser('s03a');
        $next = $mkUser('s03b');
        [$bookId, [$outCopy, $freeCopy]] = $mkBook('s03', ['disponibile', 'disponibile']);
        [, $successor] = $withDatabaseDate(
            $d(-1),
            static function () use ($mkLoan, $bookId, $outCopy, $old, $next, $d): array {
                $previous = $mkLoan($bookId, $outCopy, $old['id'], 'in_corso', $d(-20), $d(-1));
                $scheduled = $mkLoan($bookId, $outCopy, $next['id'], 'prenotato', $d(0), $d(10));
                return [$previous, $scheduled];
            }
        );
        $setCopyState($outCopy, 'prestato');
        $maintenance->updateOverdueLoans();
        $maintenance->activateScheduledLoans();
        $row = $loanRow($successor);
        return ($row['stato'] ?? '') === 'da_ritirare'
            && (int) ($row['copia_id'] ?? 0) === $freeCopy
            && $freeCopy > 0;
    });

    $scenario('a pinned reservation promotes as soon as its own copy is returned', function () use ($mkUser, $mkBook, $mkLoan, $setCopyState, $returnLoan, $maintenance, $loanRow, $d, $withDatabaseDate): bool {
        $old = $mkUser('s04a');
        $next = $mkUser('s04b');
        [$bookId, [$copyId, $unusedCopy]] = $mkBook('s04', ['disponibile', 'disponibile']);
        [$predecessor, $successor] = $withDatabaseDate(
            $d(-1),
            static function () use ($mkLoan, $bookId, $copyId, $old, $next, $d): array {
                $previous = $mkLoan($bookId, $copyId, $old['id'], 'in_corso', $d(-20), $d(-1));
                $scheduled = $mkLoan($bookId, $copyId, $next['id'], 'prenotato', $d(0), $d(10));
                return [$previous, $scheduled];
            }
        );
        $setCopyState($copyId, 'prestato');
        $returnLoan($predecessor, $copyId, 'prenotato');
        $maintenance->activateScheduledLoans();
        return ($loanRow($successor)['stato'] ?? '') === 'da_ritirare' && $unusedCopy > 0;
    });

    $scenario('multi-copy title promotes an unpinned reservation while one copy is overdue', function () use ($mkUser, $mkBook, $mkLoan, $setCopyState, $maintenance, $loanRow, $d): bool {
        $old = $mkUser('s05a');
        $next = $mkUser('s05b');
        [$bookId, [$copyId, $freeCopy]] = $mkBook('s05', ['disponibile', 'disponibile']);
        $mkLoan($bookId, $copyId, $old['id'], 'in_corso', $d(-20), $d(-1));
        $setCopyState($copyId, 'prestato');
        $successor = $mkLoan($bookId, null, $next['id'], 'prenotato', $d(0), $d(10));
        $maintenance->updateOverdueLoans();
        $maintenance->activateScheduledLoans();
        return ($loanRow($successor)['stato'] ?? '') === 'da_ritirare' && $freeCopy > 0;
    });

    $scenario('a future scheduled loan is never announced ready before its start date', function () use ($mkUser, $mkBook, $mkLoan, $setCopyState, $maintenance, $loanRow, $d): bool {
        $user = $mkUser('s06');
        [$bookId, [$copyId]] = $mkBook('s06', ['disponibile']);
        $loanId = $mkLoan($bookId, $copyId, $user['id'], 'prenotato', $d(2), $d(10));
        $setCopyState($copyId, 'prenotato');
        $maintenance->activateScheduledLoans();
        $row = $loanRow($loanId);
        return ($row['stato'] ?? '') === 'prenotato' && ($row['pickup_deadline'] ?? null) === null;
    });

    $scenario('an already-ended scheduled window expires instead of becoming ready', function () use ($mkUser, $mkBook, $mkLoan, $setCopyState, $maintenance, $loanRow, $copyState, $d): bool {
        $user = $mkUser('s07');
        [$bookId, [$copyId]] = $mkBook('s07', ['disponibile']);
        $loanId = $mkLoan($bookId, $copyId, $user['id'], 'prenotato', $d(-10), $d(-1));
        $setCopyState($copyId, 'prenotato');
        $maintenance->checkExpiredReservations();
        $maintenance->activateScheduledLoans();
        $row = $loanRow($loanId);
        return ($row['stato'] ?? '') === 'scaduto'
            && (int) ($row['attivo'] ?? 1) === 0
            && $copyState($copyId) === 'disponibile';
    });

    $scenario('a missed pickup expires and releases its physical copy', function () use ($mkUser, $mkBook, $mkLoan, $setCopyState, $maintenance, $loanRow, $copyState, $d): bool {
        $user = $mkUser('s08');
        [$bookId, [$copyId]] = $mkBook('s08', ['disponibile']);
        $loanId = $mkLoan($bookId, $copyId, $user['id'], 'da_ritirare', $d(-3), $d(10), 1, $d(-1));
        $setCopyState($copyId, 'prenotato');
        $maintenance->checkExpiredPickups();
        $row = $loanRow($loanId);
        return ($row['stato'] ?? '') === 'scaduto'
            && (int) ($row['attivo'] ?? 1) === 0
            && $copyState($copyId) === 'disponibile';
    });

    $scenario('a pickup whose deadline is today remains valid for the whole day', function () use ($mkUser, $mkBook, $mkLoan, $setCopyState, $maintenance, $loanRow, $d): bool {
        $user = $mkUser('s09');
        [$bookId, [$copyId]] = $mkBook('s09', ['disponibile']);
        $loanId = $mkLoan($bookId, $copyId, $user['id'], 'da_ritirare', $d(-2), $d(10), 1, $d(0));
        $setCopyState($copyId, 'prenotato');
        $maintenance->checkExpiredPickups();
        $row = $loanRow($loanId);
        return ($row['stato'] ?? '') === 'da_ritirare' && (int) ($row['attivo'] ?? 0) === 1;
    });

    $scenario('a loan due yesterday is flipped to in_ritardo', function () use ($mkUser, $mkBook, $mkLoan, $setCopyState, $maintenance, $loanRow, $d): bool {
        $user = $mkUser('s10');
        [$bookId, [$copyId]] = $mkBook('s10', ['disponibile']);
        $loanId = $mkLoan($bookId, $copyId, $user['id'], 'in_corso', $d(-15), $d(-1));
        $setCopyState($copyId, 'prestato');
        $maintenance->updateOverdueLoans();
        return ($loanRow($loanId)['stato'] ?? '') === 'in_ritardo';
    });

    $scenario('a loan due today is not marked overdue early', function () use ($mkUser, $mkBook, $mkLoan, $setCopyState, $maintenance, $loanRow, $d): bool {
        $user = $mkUser('s11');
        [$bookId, [$copyId]] = $mkBook('s11', ['disponibile']);
        $loanId = $mkLoan($bookId, $copyId, $user['id'], 'in_corso', $d(-10), $d(0));
        $setCopyState($copyId, 'prestato');
        $maintenance->updateOverdueLoans();
        return ($loanRow($loanId)['stato'] ?? '') === 'in_corso';
    });

    $scenario('a closed returned loan is untouched by the overdue sweep', function () use ($mkUser, $mkBook, $mkLoan, $maintenance, $loanRow, $d): bool {
        $user = $mkUser('s12');
        [$bookId, [$copyId]] = $mkBook('s12', ['disponibile']);
        $loanId = $mkLoan($bookId, $copyId, $user['id'], 'restituito', $d(-20), $d(-5), 0);
        $maintenance->updateOverdueLoans();
        $row = $loanRow($loanId);
        return ($row['stato'] ?? '') === 'restituito' && (int) ($row['attivo'] ?? 1) === 0;
    });

    $scenario('inclusive ranges: starting on another loan due date is an overlap', function () use ($mkUser, $mkBook, $mkLoan, $setCopyState, $db, $d): bool {
        $user = $mkUser('s13');
        [$bookId, [$copyId]] = $mkBook('s13', ['disponibile']);
        $mkLoan($bookId, $copyId, $user['id'], 'in_corso', $d(-5), $d(0));
        $setCopyState($copyId, 'prestato');
        return !(new CapacityService($db))->hasFreeCapacity($bookId, $d(0), $d(2));
    });

    $scenario('adjacent ranges: the day after a due date is free', function () use ($mkUser, $mkBook, $mkLoan, $setCopyState, $db, $d): bool {
        $user = $mkUser('s14');
        [$bookId, [$copyId]] = $mkBook('s14', ['disponibile']);
        $mkLoan($bookId, $copyId, $user['id'], 'in_corso', $d(-5), $d(2));
        $setCopyState($copyId, 'prestato');
        return (new CapacityService($db))->hasFreeCapacity($bookId, $d(3), $d(5));
    });

    $scenario('two copies retain one free slot while only one overlaps', function () use ($mkUser, $mkBook, $mkLoan, $setCopyState, $db, $d): bool {
        $user = $mkUser('s15');
        [$bookId, [$copyId, $freeCopy]] = $mkBook('s15', ['disponibile', 'disponibile']);
        $mkLoan($bookId, $copyId, $user['id'], 'in_corso', $d(-2), $d(5));
        $setCopyState($copyId, 'prestato');
        return (new CapacityService($db))->hasFreeCapacity($bookId, $d(0), $d(3)) && $freeCopy > 0;
    });

    $scenario('a maintenance copy reduces capacity and cannot hide overbooking', function () use ($mkUser, $mkBook, $mkLoan, $setCopyState, $db, $d): bool {
        $user = $mkUser('s16');
        [$bookId, [$copyId, $maintenanceCopy]] = $mkBook('s16', ['disponibile', 'manutenzione']);
        $mkLoan($bookId, $copyId, $user['id'], 'in_corso', $d(-2), $d(5));
        $setCopyState($copyId, 'prestato');
        $capacity = new CapacityService($db);
        return $capacity->totalCopies($bookId) === 1
            && !$capacity->hasFreeCapacity($bookId, $d(0), $d(3))
            && $maintenanceCopy > 0;
    });

    $scenario('a bare pending request without a copy does not consume capacity', function () use ($mkUser, $mkBook, $mkLoan, $db, $d): bool {
        $user = $mkUser('s17');
        [$bookId] = $mkBook('s17', ['disponibile']);
        $mkLoan($bookId, null, $user['id'], 'pendente', $d(0), $d(5), 0, null, 'richiesta');
        return (new CapacityService($db))->hasFreeCapacity($bookId, $d(0), $d(5));
    });

    $scenario('a pending reservation conversion with a copy consumes capacity', function () use ($mkUser, $mkBook, $mkLoan, $db, $d): bool {
        $user = $mkUser('s18');
        [$bookId, [$copyId]] = $mkBook('s18', ['disponibile']);
        $mkLoan($bookId, $copyId, $user['id'], 'pendente', $d(0), $d(5), 0, null, 'prenotazione');
        return !(new CapacityService($db))->hasFreeCapacity($bookId, $d(0), $d(5));
    });

    $scenario('an active waitlist reservation occupies its promised period', function () use ($mkUser, $mkBook, $mkReservation, $db, $d): bool {
        $user = $mkUser('s19');
        [$bookId] = $mkBook('s19', ['disponibile']);
        $mkReservation($bookId, $user['id'], $d(1), $d(5), $d(5) . ' 23:59:59', 1);
        return !(new CapacityService($db))->hasFreeCapacity($bookId, $d(2), $d(4));
    });

    $scenario('a cancelled reservation immediately stops consuming capacity', function () use ($mkUser, $mkBook, $mkReservation, $db, $d): bool {
        $user = $mkUser('s20');
        [$bookId] = $mkBook('s20', ['disponibile']);
        $mkReservation($bookId, $user['id'], $d(1), $d(5), $d(5) . ' 23:59:59', 1, 'annullata');
        return (new CapacityService($db))->hasFreeCapacity($bookId, $d(2), $d(4));
    });

    $scenario('a legacy reservation with NULL start still blocks on its deadline', function () use ($mkUser, $mkBook, $mkReservation, $db, $d): bool {
        $user = $mkUser('s21');
        [$bookId] = $mkBook('s21', ['disponibile']);
        $mkReservation($bookId, $user['id'], null, null, $d(2) . ' 12:00:00', 1);
        return !(new CapacityService($db))->hasFreeCapacity($bookId, $d(2), $d(2));
    });

    $scenario('an eligible later queue entry can promote while position one starts in the future', function () use ($mkUser, $mkBook, $mkReservation, $reservationRow, $countConversionLoans, $d, $db): bool {
        $future = $mkUser('s22a');
        $eligible = $mkUser('s22b');
        [$bookId] = $mkBook('s22', ['disponibile']);
        $futureId = $mkReservation($bookId, $future['id'], $d(20), $d(25), $d(25) . ' 23:59:59', 1);
        $eligibleId = $mkReservation($bookId, $eligible['id'], $d(-1), $d(5), $d(5) . ' 23:59:59', 2);
        $promoted = (new ReservationManager($db))->processBookAvailability($bookId);
        return $promoted
            && ($reservationRow($futureId)['stato'] ?? '') === 'attiva'
            && (int) ($reservationRow($futureId)['queue_position'] ?? 0) === 1
            && ($reservationRow($eligibleId)['stato'] ?? '') === 'completata'
            && $countConversionLoans($bookId, $eligible['id']) === 1;
    });

    $scenario('the queue tail does not falsely block promotion of the queue head', function () use ($mkUser, $mkBook, $mkReservation, $reservationRow, $countConversionLoans, $d, $db): bool {
        $head = $mkUser('s23a');
        $tail = $mkUser('s23b');
        [$bookId] = $mkBook('s23', ['disponibile']);
        $headId = $mkReservation($bookId, $head['id'], $d(-1), $d(5), $d(5) . ' 23:59:59', 1);
        $tailId = $mkReservation($bookId, $tail['id'], $d(-1), $d(5), $d(5) . ' 23:59:59', 2);
        $promoted = (new ReservationManager($db))->processBookAvailability($bookId);
        return $promoted
            && ($reservationRow($headId)['stato'] ?? '') === 'completata'
            && ($reservationRow($tailId)['stato'] ?? '') === 'attiva'
            && (int) ($reservationRow($tailId)['queue_position'] ?? 0) === 1
            && $countConversionLoans($bookId, $head['id']) === 1;
    });

    $scenario('a due-soon warning is claimed and emailed exactly once', function () use ($mkUser, $mkBook, $mkLoan, $setCopyState, $injectFakeEmail, $setMailerReachable, $loanRow, $db, $d): bool {
        $user = $mkUser('s24');
        [$bookId, [$copyId]] = $mkBook('s24', ['disponibile']);
        $loanId = $mkLoan($bookId, $copyId, $user['id'], 'in_corso', $d(-5), $d(0));
        $setCopyState($copyId, 'prestato');
        $setMailerReachable(true);
        $service = new NotificationService($db);
        $fake = $injectFakeEmail($service);
        $service->sendLoanExpirationWarnings();
        $service->sendLoanExpirationWarnings();
        $ownEmails = array_filter($fake->sent, static fn (array $mail): bool =>
            $mail['to'] === $user['email'] && $mail['template'] === 'loan_expiring_warning'
        );
        return (int) ($loanRow($loanId)['warning_sent'] ?? 0) === 1 && count($ownEmails) === 1;
    });

    $scenario('an overdue notice flips, claims and emails exactly once', function () use ($mkUser, $mkBook, $mkLoan, $setCopyState, $injectFakeEmail, $setMailerReachable, $loanRow, $db, $d): bool {
        $user = $mkUser('s25');
        [$bookId, [$copyId]] = $mkBook('s25', ['disponibile']);
        $loanId = $mkLoan($bookId, $copyId, $user['id'], 'in_corso', $d(-20), $d(-2));
        $setCopyState($copyId, 'prestato');
        $setMailerReachable(true);
        $service = new NotificationService($db);
        $fake = $injectFakeEmail($service);
        $service->sendOverdueLoanNotifications();
        $service->sendOverdueLoanNotifications();
        $ownEmails = array_filter($fake->sent, static fn (array $mail): bool =>
            $mail['to'] === $user['email'] && $mail['template'] === 'loan_overdue_notification'
        );
        $row = $loanRow($loanId);
        return ($row['stato'] ?? '') === 'in_ritardo'
            && (int) ($row['overdue_notification_sent'] ?? 0) === 1
            && count($ownEmails) === 1;
    });
} catch (\Throwable $e) {
    $cleanup();
    $db->close();
    fwrite(STDERR, 'FAIL: suite aborted — ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}

$cleanup();
$db->close();

if ($testNumber !== 25) {
    fwrite(STDERR, "FAIL: expected exactly 25 scenarios, executed {$testNumber}.\n");
    exit(1);
}

echo "\n" . ($failed === 0 ? 'ALL 25 PASS' : "{$failed}/25 FAILED") . "\n";
exit($failed === 0 ? 0 : 1);

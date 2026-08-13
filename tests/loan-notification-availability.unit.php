<?php
declare(strict_types=1);

/**
 * Regression coverage for circulation notification availability.
 *
 * Verifies that NotificationService uses the canonical CapacityService rules:
 * overdue loans remain physically occupied, future commitments do not occupy
 * today, active reservations do, and legacy books without `copie` rows retain
 * the documented libri.copie_totali fallback.
 */

use App\Models\CopyRepository;
use App\Support\DateHelper;
use App\Support\NotificationService;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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
    fwrite(STDERR, "FAIL: database unreachable — {$e->getMessage()}\n");
    exit(1);
}

$run = substr(hash('sha256', uniqid((string) getmypid(), true)), 0, 10);
$titleLike = 'ZZNOTIFY_' . $run . '%';
$email = 'zznotify+' . $run . '@test.local';

$cleanup = static function () use ($db, $titleLike, $email): void {
    $stmt = $db->prepare('DELETE p FROM prestiti p JOIN libri l ON l.id=p.libro_id WHERE l.titolo LIKE ?');
    $stmt->bind_param('s', $titleLike); $stmt->execute(); $stmt->close();
    $stmt = $db->prepare('DELETE r FROM prenotazioni r JOIN libri l ON l.id=r.libro_id WHERE l.titolo LIKE ?');
    $stmt->bind_param('s', $titleLike); $stmt->execute(); $stmt->close();
    $stmt = $db->prepare('DELETE c FROM copie c JOIN libri l ON l.id=c.libro_id WHERE l.titolo LIKE ?');
    $stmt->bind_param('s', $titleLike); $stmt->execute(); $stmt->close();
    $stmt = $db->prepare('DELETE FROM libri WHERE titolo LIKE ?');
    $stmt->bind_param('s', $titleLike); $stmt->execute(); $stmt->close();
    $stmt = $db->prepare('DELETE FROM utenti WHERE email = ?');
    $stmt->bind_param('s', $email); $stmt->execute(); $stmt->close();
};

$cleanup();
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    $db->close();
    exit(1);
});

$password = password_hash('test', PASSWORD_BCRYPT);
$card = 'ZZNOTIFY' . strtoupper($run);
$stmt = $db->prepare("INSERT INTO utenti (codice_tessera,nome,cognome,email,password,tipo_utente) VALUES (?,'Notify','Test',?,?,'standard')");
$stmt->bind_param('sss', $card, $email, $password);
$stmt->execute();
$userId = (int) $db->insert_id;
$stmt->close();

$sequence = 0;
$makeBook = static function (int $copies, bool $physical = true) use ($db, $run, &$sequence): array {
    $sequence++;
    $title = 'ZZNOTIFY_' . $run . '_' . $sequence;
    $stmt = $db->prepare("INSERT INTO libri (titolo,stato,copie_totali,copie_disponibili) VALUES (?,'disponibile',?,?)");
    $stmt->bind_param('sii', $title, $copies, $copies);
    $stmt->execute();
    $bookId = (int) $db->insert_id;
    $stmt->close();
    $copyId = null;
    if ($physical && $copies > 0) {
        $copyId = (new CopyRepository($db))->create($bookId, 'ZZNOTIFY-' . $run . '-' . $sequence, 'disponibile');
    }
    return [$bookId, $copyId];
};

$today = DateHelper::today();
$yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
$tomorrow = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
$nextMonth = (new DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d');
$service = new NotificationService($db);
$passed = 0;
$check = static function (bool $condition, string $label) use (&$passed): void {
    if (!$condition) { throw new RuntimeException($label); }
    $passed++;
    echo "  OK  {$label}\n";
};

[$overdueBook, $overdueCopy] = $makeBook(1);
$stmt = $db->prepare("INSERT INTO prestiti (libro_id,copia_id,utente_id,data_prestito,data_scadenza,stato,origine,attivo) VALUES (?,?,?,?,?,'in_ritardo','diretto',1)");
$loanStart = (new DateTimeImmutable($today))->modify('-30 days')->format('Y-m-d');
$stmt->bind_param('iiiss', $overdueBook, $overdueCopy, $userId, $loanStart, $yesterday);
$stmt->execute(); $stmt->close();
$check($service->hasActualAvailableCopy($overdueBook) === false, 'overdue unreturned copy is not advertised as available');

[$futureBook, $futureCopy] = $makeBook(1);
$stmt = $db->prepare("INSERT INTO prestiti (libro_id,copia_id,utente_id,data_prestito,data_scadenza,stato,origine,attivo) VALUES (?,?,?,?,?,'prenotato','diretto',1)");
$stmt->bind_param('iiiss', $futureBook, $futureCopy, $userId, $tomorrow, $nextMonth);
$stmt->execute(); $stmt->close();
$check($service->hasActualAvailableCopy($futureBook) === true, 'future loan does not suppress today availability');

[$reservedBook] = $makeBook(1);
$startAt = $today . ' 00:00:00';
$endAt = $nextMonth . ' 23:59:59';
$stmt = $db->prepare("INSERT INTO prenotazioni (libro_id,utente_id,data_prenotazione,data_scadenza_prenotazione,data_inizio_richiesta,data_fine_richiesta,queue_position,stato) VALUES (?,?,?,?,?,?,1,'attiva')");
$stmt->bind_param('iissss', $reservedBook, $userId, $startAt, $endAt, $today, $nextMonth);
$stmt->execute(); $stmt->close();
$check($service->hasActualAvailableCopy($reservedBook) === false, 'active reservation occupies today capacity');

[$nullStartBook] = $makeBook(1);
$legacyDeadline = $today . ' 23:59:59';
$stmt = $db->prepare("INSERT INTO prenotazioni (libro_id,utente_id,data_prenotazione,data_scadenza_prenotazione,data_inizio_richiesta,data_fine_richiesta,queue_position,stato) VALUES (?,?,NOW(),?,NULL,NULL,1,'attiva')");
$stmt->bind_param('iis', $nullStartBook, $userId, $legacyDeadline);
$stmt->execute(); $stmt->close();
$check($service->hasActualAvailableCopy($nullStartBook) === false, 'legacy NULL-start reservation falls back to its deadline for today capacity');
$check($service->getNextAvailabilityDate($nullStartBook) === $tomorrow, 'next availability includes a legacy NULL-start reservation end');

[$legacyBook] = $makeBook(1, false);
$check($service->hasActualAvailableCopy($legacyBook) === true, 'legacy title without copy rows uses libri.copie_totali fallback');

$check(DateHelper::isISODateFormat('2028-02-29'), 'valid leap-day ISO date accepted');
$check(!DateHelper::isISODateFormat('2026-02-30'), 'impossible ISO-shaped date rejected');
$check(!DateHelper::isISODateFormat('2026-13-01'), 'invalid month rejected');
$check(!DateHelper::isISODateFormat("2026-01-01\n"), 'trailing newline rejected by the strict ISO boundary');
$check(!DateHelper::isISODateFormat(' 2026-01-01'), 'leading whitespace rejected by the strict ISO boundary');
$check(!DateHelper::isISODateFormat("2026-01-01\0"), 'embedded NUL rejected without throwing');

$cleanup();
$db->close();
echo "\nALL {$passed} PASS\n";

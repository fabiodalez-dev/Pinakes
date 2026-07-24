<?php
declare(strict_types=1);

/**
 * End-to-end database contract for #281 bulk extension. It invokes the real
 * controller and proves book-capacity conflicts, physical-copy conflicts and
 * all-or-nothing rollback behavior.
 *
 * Run: php tests/loan-bulk-extension-capacity.unit.php
 */

use App\Controllers\PrestitiController;
use App\Models\CopyRepository;
use App\Support\DateHelper;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

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
$dbHost = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$dbPort = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');

try {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$run = substr(hash('sha256', uniqid((string) getmypid(), true)), 0, 10);
$titlePrefix = 'ZZ_BULK281_' . $run;
$emailDomain = '@bulk281.test.local';

$cleanup = static function () use ($db, $titlePrefix, $emailDomain): void {
    $titleLike = $titlePrefix . '%';
    $emailLike = '%' . $emailDomain;
    $stmt = $db->prepare('DELETE r FROM prenotazioni r JOIN libri l ON l.id = r.libro_id WHERE l.titolo LIKE ?');
    $stmt->bind_param('s', $titleLike);
    $stmt->execute();
    $stmt->close();
    $stmt = $db->prepare('DELETE p FROM prestiti p JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE ?');
    $stmt->bind_param('s', $titleLike);
    $stmt->execute();
    $stmt->close();
    $stmt = $db->prepare('DELETE c FROM copie c JOIN libri l ON l.id = c.libro_id WHERE l.titolo LIKE ?');
    $stmt->bind_param('s', $titleLike);
    $stmt->execute();
    $stmt->close();
    $stmt = $db->prepare('DELETE FROM libri WHERE titolo LIKE ?');
    $stmt->bind_param('s', $titleLike);
    $stmt->execute();
    $stmt->close();
    $stmt = $db->prepare('DELETE FROM utenti WHERE email LIKE ?');
    $stmt->bind_param('s', $emailLike);
    $stmt->execute();
    $stmt->close();
};

$cleanup();
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try {
        $cleanup();
    } catch (Throwable) {
    }
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    $db->close();
    exit(1);
});

$pass = 0;
$check = static function (bool $ok, string $label) use (&$pass): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $pass++;
    echo "  OK  {$label}\n";
};

$bookSeq = 0;
$makeBook = static function (int $copies = 1) use ($db, $titlePrefix, $run, &$bookSeq): array {
    $bookSeq++;
    $title = $titlePrefix . '_' . $bookSeq;
    $stmt = $db->prepare("INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (?, 'disponibile', 0, 0)");
    $stmt->bind_param('s', $title);
    $stmt->execute();
    $bookId = (int) $db->insert_id;
    $stmt->close();
    $copyIds = [];
    $copyRepo = new CopyRepository($db);
    for ($i = 1; $i <= $copies; $i++) {
        $inventory = 'ZZB281-' . $run . '-' . $bookSeq . '-' . $i;
        $copyIds[] = $copyRepo->create($bookId, $inventory, 'disponibile');
    }
    return [$bookId, $copyIds];
};

$userSeq = 0;
$makeUser = static function () use ($db, $run, $emailDomain, &$userSeq): int {
    $userSeq++;
    $card = 'ZZB281' . strtoupper($run) . $userSeq;
    $email = $run . '-' . $userSeq . $emailDomain;
    $password = password_hash('test', PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente) VALUES (?, 'Bulk', 'Test', ?, ?, 'standard')");
    $stmt->bind_param('sss', $card, $email, $password);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$makeLoan = static function (int $bookId, int $copyId, int $userId, string $start, string $due, string $state = 'in_corso') use ($db): int {
    $stmt = $db->prepare(
        "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, warning_sent, overdue_notification_sent)
         VALUES (?, ?, ?, ?, ?, ?, 'diretto', 1, 1, 1)"
    );
    $stmt->bind_param('iiisss', $bookId, $copyId, $userId, $start, $due, $state);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$dueDate = static function (int $loanId) use ($db): string {
    $stmt = $db->prepare('SELECT data_scadenza FROM prestiti WHERE id = ?');
    $stmt->bind_param('i', $loanId);
    $stmt->execute();
    $value = (string) ($stmt->get_result()->fetch_assoc()['data_scadenza'] ?? '');
    $stmt->close();
    return $value;
};

$callBulk = static function (array $ids, int $days) use ($db) {
    $_SESSION['user'] = ['tipo_utente' => 'admin', 'id' => 1];
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/admin/loans/bulk-extend')
        ->withParsedBody(['ids' => $ids, 'days' => $days]);
    $response = (new ResponseFactory())->createResponse();
    return (new PrestitiController())->bulkExtend($request, $response, $db);
};

$today = new DateTimeImmutable(DateHelper::today());
$start = $today->modify('-5 days')->format('Y-m-d');
$due = $today->modify('+2 days')->format('Y-m-d');
$reservationStart = $today->modify('+3 days')->format('Y-m-d');
$reservationEnd = $today->modify('+10 days')->format('Y-m-d');

echo "A. Capacity conflict rolls back the complete batch\n";
[$cleanBook, [$cleanCopy]] = $makeBook();
$cleanLoan = $makeLoan($cleanBook, $cleanCopy, $makeUser(), $start, $due);
[$blockedBook, [$blockedCopy]] = $makeBook();
$blockedLoan = $makeLoan($blockedBook, $blockedCopy, $makeUser(), $start, $due);
$reservationUser = $makeUser();
$stmt = $db->prepare(
    "INSERT INTO prenotazioni (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta, data_scadenza_prenotazione, stato, queue_position)
     VALUES (?, ?, ?, ?, ?, 'attiva', 1)"
);
$stmt->bind_param('iisss', $blockedBook, $reservationUser, $reservationStart, $reservationEnd, $reservationEnd);
$stmt->execute();
$reservationId = (int) $db->insert_id;
$stmt->close();

$response = $callBulk([$cleanLoan, $blockedLoan], 10);
$check(str_contains($response->getHeaderLine('Location'), 'error=bulk_extend_conflict'), 'reservation overlap returns the dedicated conflict');
$check($dueDate($cleanLoan) === $due && $dueDate($blockedLoan) === $due, 'conflict rolls back earlier extensions in the same batch');

$db->query('DELETE FROM prenotazioni WHERE id = ' . $reservationId);
$response = $callBulk([$cleanLoan, $blockedLoan], 10);
$expectedDue = $today->modify('+12 days')->format('Y-m-d');
$check(str_contains($response->getHeaderLine('Location'), 'bulk_extended=2'), 'conflict-free batch extends every selected loan');
$check($dueDate($cleanLoan) === $expectedDue && $dueDate($blockedLoan) === $expectedDue, 'successful batch persists both due dates');

echo "B. Physical-copy schedule remains exclusive\n";
[$copyBook, [$scheduledCopy, $freeCopy]] = $makeBook(2);
$currentLoan = $makeLoan($copyBook, $scheduledCopy, $makeUser(), $start, $due);
$futureStart = $today->modify('+8 days')->format('Y-m-d');
$futureEnd = $today->modify('+12 days')->format('Y-m-d');
$futureLoan = $makeLoan($copyBook, $scheduledCopy, $makeUser(), $futureStart, $futureEnd, 'prenotato');
$check($futureLoan > 0 && $freeCopy > 0, 'fixture has spare book capacity but a busy assigned copy');
$response = $callBulk([$currentLoan], 10);
$check(str_contains($response->getHeaderLine('Location'), 'error=bulk_extend_conflict'), 'same-copy future schedule blocks the extension despite spare book capacity');
$check($dueDate($currentLoan) === $due, 'copy conflict leaves the original due date unchanged');

// ── Area 4: overdue loans clear "Overdue" when extended (issue #281 gap) ─────
echo "D. Extending an overdue loan clears the overdue status\n";
$loanState = static function (int $loanId) use ($db): string {
    $stmt = $db->prepare('SELECT stato FROM prestiti WHERE id = ?');
    $stmt->bind_param('i', $loanId);
    $stmt->execute();
    $v = (string) ($stmt->get_result()->fetch_assoc()['stato'] ?? '');
    $stmt->close();
    return $v;
};

// Deeply overdue: due 40 days ago, extended by only 14. It must extend from
// TODAY (today+14, in the future) and become in_corso — not stay in_ritardo
// because 14 < the 40-day overdue gap.
[$odBook, $odCopies] = $makeBook(1);
$odUser = $makeUser();
$odStart = $today->modify('-50 days')->format('Y-m-d');
$odDue = $today->modify('-40 days')->format('Y-m-d');
$overdueLoan = $makeLoan($odBook, $odCopies[0], $odUser, $odStart, $odDue, 'in_ritardo');
$callBulk([$overdueLoan], 14);
$check($dueDate($overdueLoan) === $today->modify('+14 days')->format('Y-m-d'), 'overdue loan extends from today, not its stale past due date');
$check($loanState($overdueLoan) === 'in_corso', 'overdue loan returns to in_corso once extended into the future');

// Control: a loan not yet due keeps extending from its own due date.
[$fdBook, $fdCopies] = $makeBook(1);
$fdUser = $makeUser();
$fdStart = $today->modify('-2 days')->format('Y-m-d');
$fdDue = $today->modify('+2 days')->format('Y-m-d');
$notDueLoan = $makeLoan($fdBook, $fdCopies[0], $fdUser, $fdStart, $fdDue, 'in_corso');
$callBulk([$notDueLoan], 14);
$check($dueDate($notDueLoan) === $today->modify('+16 days')->format('Y-m-d'), 'not-yet-due loan still extends from its own due date');
$check($loanState($notDueLoan) === 'in_corso', 'not-yet-due loan stays in_corso');

$cleanup();
$db->close();
echo "\n{$pass} PASS, 0 FAIL\n";
exit(0);

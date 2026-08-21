<?php
declare(strict_types=1);

/**
 * Regression coverage for #366 read models that consume loan intervals.
 *
 * A date-overdue active `in_corso` loan is an unreturned physical copy even
 * before maintenance changes its state to `in_ritardo`. The consistency
 * auditor, the authenticated availability payload and the dashboard calendar
 * must all expose the same open-ended interval. A not-yet-due loan must retain
 * its contractual end so a disjoint future commitment remains valid.
 *
 * Run: php tests/overdue-copy-read-models-366.unit.php
 */

use App\Models\DashboardStats;
use App\Support\DataIntegrity;
use App\Support\DateHelper;

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
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');

try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $description) use (&$passed, &$failed): void {
    echo ($condition ? '  OK  ' : '  FAIL ') . $description . PHP_EOL;
    $condition ? $passed++ : $failed++;
};

$run = bin2hex(random_bytes(5));
$titlePrefix = "ZZ_366READ_{$run}_";
$emailSuffix = "-{$run}@366read.test.local";
$inventoryPrefix = "ZZ366R-{$run}-";
$today = DateHelper::today();
$pastStart = (new DateTimeImmutable($today))->modify('-20 days')->format('Y-m-d');
$pastDue = (new DateTimeImmutable($today))->modify('-2 days')->format('Y-m-d');
$futureStart = (new DateTimeImmutable($today))->modify('+2 days')->format('Y-m-d');
$futureEnd = (new DateTimeImmutable($today))->modify('+8 days')->format('Y-m-d');

$userSequence = 0;
$makeUser = static function () use ($db, $run, $emailSuffix, &$userSequence): int {
    $userSequence++;
    $card = 'Z366R' . strtoupper($run) . $userSequence;
    $email = "u{$userSequence}{$emailSuffix}";
    $stmt = $db->prepare(
        "INSERT INTO utenti
            (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata)
         VALUES (?, 'Test', 'ZZ366 Read', ?, 'x', 'attivo', 'standard', 1)"
    );
    $stmt->bind_param('ss', $card, $email);
    $stmt->execute();
    $stmt->close();
    return (int) $db->insert_id;
};

$bookSequence = 0;
/** @return array{0:int,1:list<int>} */
$makeBook = static function (int $copyCount) use ($db, $titlePrefix, $inventoryPrefix, &$bookSequence): array {
    $bookSequence++;
    $title = $titlePrefix . $bookSequence;
    $stmt = $db->prepare(
        'INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at)
         VALUES (?, ?, ?, NOW(), NOW())'
    );
    $stmt->bind_param('sii', $title, $copyCount, $copyCount);
    $stmt->execute();
    $stmt->close();
    $bookId = (int) $db->insert_id;

    $copyIds = [];
    for ($copyNo = 1; $copyNo <= $copyCount; $copyNo++) {
        $inventory = $inventoryPrefix . $bookSequence . '-' . $copyNo;
        $stmt = $db->prepare(
            "INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')"
        );
        $stmt->bind_param('is', $bookId, $inventory);
        $stmt->execute();
        $stmt->close();
        $copyIds[] = (int) $db->insert_id;
    }
    return [$bookId, $copyIds];
};

$makeLoan = static function (
    int $bookId,
    int $copyId,
    int $userId,
    string $start,
    string $end,
    string $state
) use ($db): int {
    $stmt = $db->prepare(
        "INSERT INTO prestiti
            (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
         VALUES (?, ?, ?, ?, ?, ?, 'diretto', 1)"
    );
    $stmt->bind_param('iiisss', $bookId, $copyId, $userId, $start, $end, $state);
    $stmt->execute();
    $stmt->close();
    return (int) $db->insert_id;
};

$cleanup = static function () use ($db, $titlePrefix, $emailSuffix): void {
    $titleLike = $db->real_escape_string($titlePrefix) . '%';
    $emailLike = $db->real_escape_string('%' . $emailSuffix);
    $db->query(
        "DELETE n FROM admin_notifications n
         JOIN prestiti p ON p.id = n.related_id
         JOIN libri l ON l.id = p.libro_id
         WHERE l.titolo LIKE '{$titleLike}'"
    );
    $db->query("DELETE r FROM prenotazioni r JOIN libri l ON l.id = r.libro_id WHERE l.titolo LIKE '{$titleLike}'");
    $db->query("DELETE p FROM prestiti p JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE '{$titleLike}'");
    $db->query("DELETE c FROM copie c JOIN libri l ON l.id = c.libro_id WHERE l.titolo LIKE '{$titleLike}'");
    $db->query("DELETE FROM libri WHERE titolo LIKE '{$titleLike}'");
    $db->query("DELETE FROM utenti WHERE email LIKE '{$emailLike}'");
};

$cleanup();

try {
    $staleUser = $makeUser();
    $futureUser = $makeUser();
    [$overbookedBook, $overbookedCopies] = $makeBook(2);
    $staleLoanId = $makeLoan(
        $overbookedBook,
        $overbookedCopies[0],
        $staleUser,
        $pastStart,
        $pastDue,
        'in_corso'
    );
    $futureLoanId = $makeLoan(
        $overbookedBook,
        $overbookedCopies[1],
        $futureUser,
        $futureStart,
        $futureEnd,
        'prenotato'
    );
    $db->query("UPDATE copie SET stato = 'prestato' WHERE id = {$overbookedCopies[0]}");
    $db->query("UPDATE copie SET stato = 'danneggiato' WHERE id = {$overbookedCopies[1]}");

    $issues = (new DataIntegrity($db))->verifyDataConsistency();
    $foundOverbooking = false;
    foreach ($issues as $issue) {
        if (($issue['type'] ?? '') === 'overbooked_circulation_period'
            && str_contains((string) ($issue['message'] ?? ''), (string) $overbookedBook)) {
            $foundOverbooking = true;
            break;
        }
    }
    $check(
        $foundOverbooking,
        'auditor overlaps a stale in_corso loan with a later commitment open-endedly'
    );

    $currentUser = $makeUser();
    $disjointUser = $makeUser();
    [$disjointBook, $disjointCopies] = $makeBook(1);
    $currentDue = (new DateTimeImmutable($today))->modify('+3 days')->format('Y-m-d');
    $disjointStart = (new DateTimeImmutable($currentDue))->modify('+1 day')->format('Y-m-d');
    $disjointEnd = (new DateTimeImmutable($disjointStart))->modify('+5 days')->format('Y-m-d');
    $currentLoanId = $makeLoan(
        $disjointBook,
        $disjointCopies[0],
        $currentUser,
        $pastStart,
        $currentDue,
        'in_corso'
    );
    $makeLoan(
        $disjointBook,
        $disjointCopies[0],
        $disjointUser,
        $disjointStart,
        $disjointEnd,
        'prenotato'
    );
    $db->query("UPDATE copie SET stato = 'prestato' WHERE id = {$disjointCopies[0]}");

    $controlIssues = (new DataIntegrity($db))->verifyDataConsistency();
    $controlOverbooked = false;
    foreach ($controlIssues as $issue) {
        if (($issue['type'] ?? '') === 'overbooked_circulation_period'
            && str_contains((string) ($issue['message'] ?? ''), (string) $disjointBook)) {
            $controlOverbooked = true;
            break;
        }
    }
    $check(
        !$controlOverbooked,
        'auditor preserves disjoint scheduling after a not-yet-due in_corso loan'
    );

    $events = (new DashboardStats($db))->calendarEvents();
    $eventsById = [];
    foreach ($events as $event) {
        $eventsById[(string) ($event['id'] ?? '')] = $event;
    }
    $staleEvent = $eventsById['loan_' . $staleLoanId] ?? null;
    $currentEvent = $eventsById['loan_' . $currentLoanId] ?? null;
    $check(
        is_array($staleEvent)
            && ($staleEvent['status'] ?? '') === 'in_corso'
            && ($staleEvent['end'] ?? '') === $today,
        'dashboard includes stale in_corso and extends its event through application today'
    );
    $check(
        is_array($currentEvent) && ($currentEvent['end'] ?? '') === $currentDue,
        'dashboard keeps the contractual end for not-yet-due in_corso'
    );

    // Execute the exact occupied-range SELECT embedded in the API route, then
    // separately pin its bind order. This covers query behaviour without
    // bootstrapping every unrelated web route and middleware in this unit test.
    $webSource = (string) file_get_contents($root . '/app/Routes/web.php');
    $sectionStart = strpos($webSource, '// Intervalli occupati');
    $sectionEnd = $sectionStart === false
        ? false
        : strpos($webSource, '// Anche la coda prenotazioni', $sectionStart);
    $apiSection = $sectionStart !== false && $sectionEnd !== false
        ? substr($webSource, $sectionStart, $sectionEnd - $sectionStart)
        : '';
    $apiSql = '';
    if (preg_match('/\$stmt = \$db->prepare\("(.*?)"\);/s', $apiSection, $match) === 1) {
        $apiSql = $match[1];
    }
    $check(
        $apiSql !== ''
            && str_contains($apiSection, "bind_param('si', \$today, \$libroId)"),
        'availability route binds application today before the book id'
    );

    $apiRows = [];
    if ($apiSql !== '') {
        $stmt = $db->prepare($apiSql);
        $stmt->bind_param('si', $today, $overbookedBook);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $apiRows[] = $row;
        }
        $stmt->close();
    }
    $staleRangeFound = false;
    $futureRangePreserved = false;
    foreach ($apiRows as $row) {
        if (($row['stato'] ?? '') === 'in_corso'
            && ($row['data_prestito'] ?? '') === $pastStart
            && ($row['occupied_until'] ?? '') === '9999-12-31') {
            $staleRangeFound = true;
        }
        if (($row['stato'] ?? '') === 'prenotato'
            && ($row['data_prestito'] ?? '') === $futureStart
            && ($row['occupied_until'] ?? '') === $futureEnd) {
            $futureRangePreserved = true;
        }
    }
    $check(
        $staleRangeFound,
        'availability occupied_ranges exposes stale in_corso as open-ended'
    );
    $check(
        $futureRangePreserved,
        'availability occupied_ranges preserves a normal future contractual end'
    );

    // Keep both ids live in the assertions above: this also prevents an
    // accidental fixture simplification from leaving the future row untested.
    $check($futureLoanId > 0, 'future comparison fixture was persisted');
} catch (Throwable $e) {
    $failed++;
    echo '  FAIL exception: ' . $e->getMessage() . PHP_EOL;
} finally {
    try {
        $db->rollback();
    } catch (Throwable) {
        // Best effort: no transaction is expected here.
    }
    try {
        $cleanup();
    } catch (Throwable $cleanupError) {
        $failed++;
        echo '  FAIL cleanup: ' . $cleanupError->getMessage() . PHP_EOL;
    }
    $db->close();
}

echo PHP_EOL . "{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);

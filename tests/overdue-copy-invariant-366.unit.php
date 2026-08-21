<?php
declare(strict_types=1);

/**
 * Regression coverage for issue #366's physical-copy invariant.
 *
 * A loan that is still `in_corso` after its due date represents a physical
 * copy that has not returned yet, even before maintenance flips the row to
 * `in_ritardo`. It therefore blocks that copy open-ended. A current loan whose
 * due date has not arrived must still allow a genuinely disjoint future hold.
 *
 * Exercises the pure multiplicity comparator, the real staff forced/automatic
 * allocation paths, and isolated copies of the canonical INSERT/UPDATE
 * triggers. All persistent fixtures carry unique markers and are removed.
 *
 * Run: php tests/overdue-copy-invariant-366.unit.php
 */

use App\Controllers\PrestitiController;
use App\Models\SettingsRepository;
use App\Support\ConfigStore;
use App\Support\DateHelper;
use App\Support\LoanMultiplicityPolicy;
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
$titlePrefix = "ZZ_366OVERDUE_{$run}_";
$emailSuffix = "-{$run}@366overdue.test.local";
$inventoryPrefix = "ZZ366O-{$run}-";
$sessionBefore = $_SESSION ?? [];
$applicationToday = DateHelper::today();
$pastStart = (new DateTimeImmutable($applicationToday))->modify('-10 days')->format('Y-m-d');
$pastDue = (new DateTimeImmutable($applicationToday))->modify('-1 day')->format('Y-m-d');
$candidateStart = (new DateTimeImmutable($applicationToday))->modify('+1 day')->format('Y-m-d');
$candidateEnd = (new DateTimeImmutable($applicationToday))->modify('+7 days')->format('Y-m-d');

$captureSetting = static function (string $key) use ($db): array {
    $stmt = $db->prepare(
        "SELECT setting_value FROM system_settings WHERE category = 'loans' AND setting_key = ? LIMIT 1"
    );
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return [
        'exists' => $row !== null,
        'value' => $row !== null && $row['setting_value'] !== null ? (string) $row['setting_value'] : null,
    ];
};

$restoreSetting = static function (string $key, array $original) use ($db): void {
    if (!$original['exists']) {
        $stmt = $db->prepare(
            "DELETE FROM system_settings WHERE category = 'loans' AND setting_key = ?"
        );
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->close();
        ConfigStore::clearCache();
        return;
    }

    $value = $original['value'];
    $stmt = $db->prepare(
        "INSERT INTO system_settings (category, setting_key, setting_value)
         VALUES ('loans', ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
    ConfigStore::clearCache();
};

$originalMultiplicity = $captureSetting('allow_multiple_loans_same_book');
$originalMaxLoans = $captureSetting('max_active_loans_per_user');

$userSequence = 0;
$makeUser = static function (string $role = 'standard') use ($db, $run, $emailSuffix, &$userSequence): int {
    $userSequence++;
    $card = 'Z366O' . strtoupper($run) . $userSequence;
    $email = "u{$userSequence}{$emailSuffix}";
    $surname = "ZZ366 Overdue {$userSequence}";
    $stmt = $db->prepare(
        "INSERT INTO utenti
            (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata)
         VALUES (?, 'Test', ?, ?, 'x', 'attivo', ?, 1)"
    );
    $stmt->bind_param('ssss', $card, $surname, $email, $role);
    $stmt->execute();
    $stmt->close();
    return (int) $db->insert_id;
};

$bookSequence = 0;
/** @return array{0:int,1:list<int>,2:list<string>} */
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
    $copyCodes = [];
    for ($copyNo = 1; $copyNo <= $copyCount; $copyNo++) {
        $code = $inventoryPrefix . $bookSequence . '-' . $copyNo;
        $stmt = $db->prepare(
            "INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')"
        );
        $stmt->bind_param('is', $bookId, $code);
        $stmt->execute();
        $stmt->close();
        $copyIds[] = (int) $db->insert_id;
        $copyCodes[] = $code;
    }

    return [$bookId, $copyIds, $copyCodes];
};

$makeLoan = static function (
    int $bookId,
    int $copyId,
    int $userId,
    string $start,
    string $end,
    string $state = 'in_corso'
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

$callStore = static function (
    int $adminId,
    int $userId,
    int $bookId,
    string $copyCode,
    string $start,
    string $end
) use ($db) {
    $_SESSION['user'] = ['tipo_utente' => 'admin', 'id' => $adminId];
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/admin/loans/create')
        ->withParsedBody([
            'loan_submission_token' => \App\Support\OneTimeFormToken::issue('loan.create'),
            'utente_id' => (string) $userId,
            'libro_id' => (string) $bookId,
            'copy_code' => $copyCode,
            'data_prestito' => $start,
            'data_scadenza' => $end,
            'note' => 'Issue #366 overdue-copy invariant',
            'consegna_immediata' => '1',
            'scarica_pdf' => '0',
        ]);

    return (new PrestitiController())->store(
        $request,
        (new ResponseFactory())->createResponse(),
        $db
    );
};

$cleanupFixtures = static function () use ($db, $titlePrefix, $emailSuffix): void {
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

$sandboxSuffix = bin2hex(random_bytes(5));
$sandboxLoans = "zz366overdue_loans_{$sandboxSuffix}";
$sandboxCopies = "zz366overdue_copies_{$sandboxSuffix}";
$sandboxInsertTrigger = "zz366overdue_insert_{$sandboxSuffix}";
$sandboxUpdateTrigger = "zz366overdue_update_{$sandboxSuffix}";
$cleanupSandbox = static function () use (
    $db,
    $sandboxLoans,
    $sandboxCopies,
    $sandboxInsertTrigger,
    $sandboxUpdateTrigger
): void {
    $db->query("DROP TRIGGER IF EXISTS `{$sandboxInsertTrigger}`");
    $db->query("DROP TRIGGER IF EXISTS `{$sandboxUpdateTrigger}`");
    $db->query("DROP TABLE IF EXISTS `{$sandboxLoans}`");
    $db->query("DROP TABLE IF EXISTS `{$sandboxCopies}`");
};

$installSandboxTrigger = static function (string $canonicalName, string $sandboxName) use (
    $db,
    $root,
    $sandboxLoans,
    $sandboxCopies
): void {
    $source = (string) file_get_contents($root . '/installer/database/triggers.sql');
    $pattern = '/CREATE TRIGGER `' . preg_quote($canonicalName, '/') . '`.*?END\$\$/s';
    if (!preg_match($pattern, $source, $match)) {
        throw new RuntimeException("Cannot extract canonical trigger {$canonicalName}");
    }
    $ddl = substr($match[0], 0, -2);
    $ddl = str_replace(
        [
            "`{$canonicalName}`",
            'ON `prestiti`',
            'FROM prestiti p',
            'FROM copie c',
            'FROM copie WHERE',
        ],
        [
            "`{$sandboxName}`",
            "ON `{$sandboxLoans}`",
            "FROM `{$sandboxLoans}` p",
            "FROM `{$sandboxCopies}` c",
            "FROM `{$sandboxCopies}` WHERE",
        ],
        $ddl
    );

    $executable = preg_replace('/^\s*--.*$/m', '', $ddl) ?? $ddl;
    if (preg_match('/\b(prestiti|copie|prenotazioni|libri)\b/i', $executable, $leftover) === 1) {
        throw new RuntimeException("Sandbox trigger still references real table {$leftover[1]}");
    }
    $db->query($ddl);
};

$cleanupFixtures();
$cleanupSandbox();

try {
    $staleCommitment = [[
        'loanId' => 1,
        'copyId' => 10,
        'userId' => 100,
        'startDate' => $pastStart,
        'endDate' => $pastDue,
        'state' => 'in_corso',
    ]];
    $check(
        LoanMultiplicityPolicy::candidateConflictsWithOpenCommitments(
            2,
            200,
            $candidateStart,
            $candidateEnd,
            $staleCommitment,
            $applicationToday
        ),
        'date-overdue in_corso is open-ended in the central comparator'
    );

    $futureDue = (new DateTimeImmutable($applicationToday))->modify('+3 days')->format('Y-m-d');
    $futureStart = (new DateTimeImmutable($futureDue))->modify('+1 day')->format('Y-m-d');
    $futureEnd = (new DateTimeImmutable($futureStart))->modify('+5 days')->format('Y-m-d');
    $currentCommitment = [[
        'loanId' => 3,
        'copyId' => 11,
        'userId' => 101,
        'startDate' => $pastStart,
        'endDate' => $futureDue,
        'state' => 'in_corso',
    ]];
    $check(
        !LoanMultiplicityPolicy::candidateConflictsWithOpenCommitments(
            4,
            201,
            $futureStart,
            $futureEnd,
            $currentCommitment,
            $applicationToday
        ),
        'not-yet-due in_corso still permits a disjoint future commitment'
    );

    $settings = new SettingsRepository($db);
    $settings->set('loans', 'allow_multiple_loans_same_book', '1');
    $settings->set('loans', 'max_active_loans_per_user', '0');

    $adminId = $makeUser('admin');
    $holderId = $makeUser();
    $candidateUserId = $makeUser();
    [$bookId, $copyIds, $copyCodes] = $makeBook(2);
    $staleLoanId = $makeLoan($bookId, $copyIds[0], $holderId, $pastStart, $pastDue);
    $db->query("UPDATE copie SET stato = 'prestato' WHERE id = {$copyIds[0]}");

    $forced = $callStore(
        $adminId,
        $candidateUserId,
        $bookId,
        $copyCodes[0],
        $candidateStart,
        $candidateEnd
    );
    $candidateRowsAfterForced = (int) $db->query(
        "SELECT COUNT(*) FROM prestiti WHERE libro_id = {$bookId} AND utente_id = {$candidateUserId}"
    )->fetch_row()[0];
    $check(
        str_contains($forced->getHeaderLine('Location'), 'error=copy_not_available')
            && $candidateRowsAfterForced === 0,
        'forced staff allocation rejects a copy held by stale overdue in_corso'
    );

    $automatic = $callStore(
        $adminId,
        $candidateUserId,
        $bookId,
        '',
        $candidateStart,
        $candidateEnd
    );
    $automaticRow = $db->query(
        "SELECT id, copia_id FROM prestiti
         WHERE libro_id = {$bookId} AND utente_id = {$candidateUserId}
         ORDER BY id DESC LIMIT 1"
    )->fetch_assoc() ?: [];
    $check(
        str_contains($automatic->getHeaderLine('Location'), 'created=1')
            && (int) ($automaticRow['copia_id'] ?? 0) === $copyIds[1]
            && (int) ($automaticRow['id'] ?? 0) !== $staleLoanId,
        'automatic staff allocation skips the overdue copy and selects the free sibling'
    );

    $futureHolderId = $makeUser();
    $futureCandidateId = $makeUser();
    [$futureBookId, $futureCopyIds, $futureCopyCodes] = $makeBook(1);
    $futureCurrentStart = (new DateTimeImmutable($applicationToday))->modify('-2 days')->format('Y-m-d');
    $futureCurrentDue = (new DateTimeImmutable($applicationToday))->modify('+2 days')->format('Y-m-d');
    $disjointStart = (new DateTimeImmutable($futureCurrentDue))->modify('+1 day')->format('Y-m-d');
    $disjointEnd = (new DateTimeImmutable($disjointStart))->modify('+5 days')->format('Y-m-d');
    $makeLoan(
        $futureBookId,
        $futureCopyIds[0],
        $futureHolderId,
        $futureCurrentStart,
        $futureCurrentDue
    );
    $db->query("UPDATE copie SET stato = 'prestato' WHERE id = {$futureCopyIds[0]}");
    $futureForced = $callStore(
        $adminId,
        $futureCandidateId,
        $futureBookId,
        $futureCopyCodes[0],
        $disjointStart,
        $disjointEnd
    );
    $futureCopyRows = (int) $db->query(
        "SELECT COUNT(*) FROM prestiti WHERE copia_id = {$futureCopyIds[0]} AND attivo = 1"
    )->fetch_row()[0];
    $check(
        str_contains($futureForced->getHeaderLine('Location'), 'created=1')
            && $futureCopyRows === 2,
        'forced staff allocation preserves valid scheduling after a not-yet-due loan'
    );

    $dbToday = (string) $db->query('SELECT CURRENT_DATE()')->fetch_row()[0];
    $dbPastStart = (new DateTimeImmutable($dbToday))->modify('-10 days')->format('Y-m-d');
    $dbPastDue = (new DateTimeImmutable($dbToday))->modify('-1 day')->format('Y-m-d');
    $dbCandidateStart = (new DateTimeImmutable($dbToday))->modify('+1 day')->format('Y-m-d');
    $dbCandidateEnd = (new DateTimeImmutable($dbToday))->modify('+7 days')->format('Y-m-d');
    $dbCurrentStart = (new DateTimeImmutable($dbToday))->modify('-2 days')->format('Y-m-d');
    $dbCurrentDue = (new DateTimeImmutable($dbToday))->modify('+2 days')->format('Y-m-d');
    $dbDisjointStart = (new DateTimeImmutable($dbCurrentDue))->modify('+1 day')->format('Y-m-d');
    $dbDisjointEnd = (new DateTimeImmutable($dbDisjointStart))->modify('+5 days')->format('Y-m-d');

    $db->query("CREATE TABLE `{$sandboxCopies}` (
        id INT NOT NULL AUTO_INCREMENT,
        libro_id INT NOT NULL,
        stato VARCHAR(32) NOT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB");
    $db->query("CREATE TABLE `{$sandboxLoans}` (
        id INT NOT NULL AUTO_INCREMENT,
        libro_id INT NOT NULL,
        copia_id INT NULL,
        data_prestito DATE NOT NULL,
        data_scadenza DATE NOT NULL,
        stato VARCHAR(32) NOT NULL,
        attivo TINYINT(1) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_copy (copia_id)
    ) ENGINE=InnoDB");
    $db->query("INSERT INTO `{$sandboxCopies}` (libro_id, stato) VALUES
        (1, 'prestato'), (1, 'disponibile'), (1, 'prestato'), (1, 'disponibile'),
        (1, 'prestato'), (1, 'disponibile'), (1, 'prestato'), (1, 'disponibile')");

    $seed = $db->prepare(
        "INSERT INTO `{$sandboxLoans}`
            (libro_id, copia_id, data_prestito, data_scadenza, stato, attivo)
         VALUES (1, ?, ?, ?, ?, 1)"
    );
    $seedCopy = 1;
    $seedState = 'in_corso';
    $seedStart = $dbPastStart;
    $seedEnd = $dbPastDue;
    $seed->bind_param('isss', $seedCopy, $seedStart, $seedEnd, $seedState);
    $seed->execute(); // id 1: stale holder for INSERT gate
    $seedCopy = 3;
    $seed->execute(); // id 2: stale holder for UPDATE gate
    $seedCopy = 4;
    $seedState = 'prenotato';
    $seedStart = $dbCandidateStart;
    $seedEnd = $dbCandidateEnd;
    $seed->execute(); // id 3: candidate moved onto stale copy 3
    $seedCopy = 5;
    $seedState = 'in_corso';
    $seedStart = $dbCurrentStart;
    $seedEnd = $dbCurrentDue;
    $seed->execute(); // id 4: current holder for disjoint INSERT
    $seedCopy = 7;
    $seed->execute(); // id 5: current holder for disjoint UPDATE
    $seedCopy = 8;
    $seedState = 'prenotato';
    $seedStart = $dbDisjointStart;
    $seedEnd = $dbDisjointEnd;
    $seed->execute(); // id 6: candidate moved onto current copy 7
    $seed->close();

    $installSandboxTrigger('trg_check_active_prestito_before_insert', $sandboxInsertTrigger);
    $installSandboxTrigger('trg_check_active_prestito_before_update', $sandboxUpdateTrigger);

    $insertStaleBlocked = false;
    try {
        $stmt = $db->prepare(
            "INSERT INTO `{$sandboxLoans}`
                (libro_id, copia_id, data_prestito, data_scadenza, stato, attivo)
             VALUES (1, 1, ?, ?, 'prenotato', 1)"
        );
        $stmt->bind_param('ss', $dbCandidateStart, $dbCandidateEnd);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        $insertStaleBlocked = str_contains($e->getMessage(), 'Esiste già un prestito attivo');
    }
    $check($insertStaleBlocked, 'INSERT trigger rejects a new hold after stale overdue in_corso');

    $updateStaleBlocked = false;
    try {
        $db->query("UPDATE `{$sandboxLoans}` SET copia_id = 3 WHERE id = 3");
    } catch (mysqli_sql_exception $e) {
        $updateStaleBlocked = str_contains($e->getMessage(), 'Esiste già un prestito attivo');
    }
    $check($updateStaleBlocked, 'UPDATE trigger rejects moving a hold onto stale overdue in_corso');

    $insertDisjointSucceeded = true;
    try {
        $stmt = $db->prepare(
            "INSERT INTO `{$sandboxLoans}`
                (libro_id, copia_id, data_prestito, data_scadenza, stato, attivo)
             VALUES (1, 5, ?, ?, 'prenotato', 1)"
        );
        $stmt->bind_param('ss', $dbDisjointStart, $dbDisjointEnd);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception) {
        $insertDisjointSucceeded = false;
    }
    $check($insertDisjointSucceeded, 'INSERT trigger allows a future hold after not-yet-due in_corso');

    $updateDisjointSucceeded = true;
    try {
        $db->query("UPDATE `{$sandboxLoans}` SET copia_id = 7 WHERE id = 6");
    } catch (mysqli_sql_exception) {
        $updateDisjointSucceeded = false;
    }
    $check($updateDisjointSucceeded, 'UPDATE trigger allows a future hold after not-yet-due in_corso');
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
        $cleanupSandbox();
        $cleanupFixtures();
        $restoreSetting('allow_multiple_loans_same_book', $originalMultiplicity);
        $restoreSetting('max_active_loans_per_user', $originalMaxLoans);
    } catch (Throwable $cleanupError) {
        $failed++;
        echo '  FAIL cleanup: ' . $cleanupError->getMessage() . PHP_EOL;
    }
    $_SESSION = $sessionBefore;
    $db->close();
}

echo PHP_EOL . "{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);

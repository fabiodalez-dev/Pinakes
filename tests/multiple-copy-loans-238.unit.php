<?php
declare(strict_types=1);

/**
 * Behavioural regression test for Discussion #238: one borrower may receive
 * several physical copies of the same title when the library explicitly opts
 * in. The historical title-level rule remains the safe default.
 *
 * Exercises the real SettingsRepository, LoanMultiplicityPolicy and staff
 * PrestitiController paths against an isolated test database. Every fixture is
 * uniquely prefixed, cleaned up, and the touched settings/session are restored.
 * MaintenanceService::activateScheduledLoans() is a global sweep, so this test
 * must run on the dedicated CI/test database, never a shared production copy.
 *
 * Run: php tests/multiple-copy-loans-238.unit.php
 */

use App\Controllers\LoanApprovalController;
use App\Controllers\PrestitiController;
use App\Models\SettingsRepository;
use App\Services\ReservationReassignmentService;
use App\Support\ConfigStore;
use App\Support\DateHelper;
use App\Support\LoanMultiplicityPolicy;
use App\Support\MaintenanceService;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

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

// Valvola di sicurezza per gli sweep globali (come loan-reservation-real-world-25):
// questa suite esegue activateScheduledLoans()/updateOverdueLoans() e DELETE di
// cleanup su qualunque database raggiunga. Il NOME del database non ricade mai
// su .env (il DB applicativo di una dev box): si esegue solo quando il chiamante
// nomina esplicitamente un DB di test dedicato. Le sole credenziali/host possono
// ancora venire da .env, come nel resto della suite.
$dbName = getenv('E2E_DB_NAME') ?: '';
if ($dbName === '') {
    fwrite(STDERR, "FAIL: refusing to run — this suite performs GLOBAL maintenance sweeps and cleanup DELETEs. Export E2E_DB_NAME (plus E2E_DB_HOST/E2E_DB_USER/E2E_DB_PASS or E2E_DB_SOCKET) pointing at a dedicated test database.\n");
    exit(1);
}

$dbHost = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbPort = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));
$socket = getenv('E2E_DB_SOCKET') ?: (getenv('E2E_DB_HOST') ? '' : ($env['DB_SOCKET'] ?? ''));

try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $db->set_charset('utf8mb4');
    // Production writers bind the application-local date on every
    // connection (container/cron/scripts bootstrap); the circulation
    // triggers otherwise fall back to the database's UTC CURRENT_DATE(),
    // which disagrees with app.timezone between 22:00 and 24:00 UTC.
    \App\Support\DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$testNo = 0;
$failed = 0;
$check = static function (bool $condition, string $description) use (&$testNo, &$failed): void {
    $testNo++;
    printf("[%02d] %s: %s\n", $testNo, $condition ? 'PASS' : 'FAIL', $description);
    if (!$condition) {
        $failed++;
    }
};

$run = bin2hex(random_bytes(6));
$titlePrefix = "ZZ_238MULTI_{$run}_";
$emailDomain = '@238multi.test.local';
$today = DateHelper::today();
$due = (new DateTimeImmutable($today))->modify('+14 days')->format('Y-m-d');
$sessionBefore = $_SESSION ?? [];

/** @return array{exists: bool, value: ?string} */
$captureSetting = static function (string $key) use ($db): array {
    $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE category = 'loans' AND setting_key = ? LIMIT 1");
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
        $stmt = $db->prepare("DELETE FROM system_settings WHERE category = 'loans' AND setting_key = ?");
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
$makeUser = static function (string $role = 'standard') use ($db, $run, $emailDomain, &$userSequence): int {
    $userSequence++;
    $card = 'Z238' . strtoupper(substr($run, 0, 8)) . $userSequence;
    $email = "u{$userSequence}-{$run}{$emailDomain}";
    $surname = "ZZ238Multi {$userSequence}";
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
/** @return array{0: int, 1: list<int>, 2: list<string>} */
$makeBook = static function (int $copies) use ($db, $titlePrefix, &$bookSequence): array {
    $bookSequence++;
    $title = $titlePrefix . $bookSequence;
    $stmt = $db->prepare(
        'INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at)
         VALUES (?, ?, ?, NOW(), NOW())'
    );
    $stmt->bind_param('sii', $title, $copies, $copies);
    $stmt->execute();
    $stmt->close();
    $bookId = (int) $db->insert_id;

    $copyIds = [];
    $copyCodes = [];
    for ($copyNo = 1; $copyNo <= $copies; $copyNo++) {
        $code = "ZZ238M-{$bookId}-C{$copyNo}";
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
    ?int $copyId,
    int $userId,
    string $state,
    int $active,
    ?string $startDate = null,
    ?string $endDate = null,
    string $origin = 'diretto'
) use ($db, $today, $due): int {
    $loanStart = $startDate ?? $today;
    $loanEnd = $endDate ?? $due;
    $stmt = $db->prepare(
        "INSERT INTO prestiti
            (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('iiissssi', $bookId, $copyId, $userId, $loanStart, $loanEnd, $state, $origin, $active);
    $stmt->execute();
    $stmt->close();

    return (int) $db->insert_id;
};

$makeActiveReservation = static function (int $bookId, int $userId) use ($db, $today, $due): int {
    $stmt = $db->prepare(
        "INSERT INTO prenotazioni
            (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta,
             data_scadenza_prenotazione, stato, queue_position, notifica_inviata)
         VALUES (?, ?, ?, ?, ?, 'attiva', 1, 0)"
    );
    $stmt->bind_param('iisss', $bookId, $userId, $today, $due, $due);
    $stmt->execute();
    $stmt->close();

    return (int) $db->insert_id;
};

$loanCount = static function (int $bookId, int $userId) use ($db): int {
    $stmt = $db->prepare('SELECT COUNT(*) FROM prestiti WHERE libro_id = ? AND utente_id = ?');
    $stmt->bind_param('ii', $bookId, $userId);
    $stmt->execute();
    $count = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $count;
};

$cleanup = static function () use ($db, $titlePrefix, $run, $emailDomain): void {
    $titleLike = $db->real_escape_string($titlePrefix) . '%';
    $db->query("DELETE n FROM admin_notifications n JOIN prestiti p ON p.id = n.related_id JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE '{$titleLike}'");
    $db->query("DELETE r FROM prenotazioni r JOIN libri l ON l.id = r.libro_id WHERE l.titolo LIKE '{$titleLike}'");
    $db->query("DELETE p FROM prestiti p JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE '{$titleLike}'");
    $db->query("DELETE c FROM copie c JOIN libri l ON l.id = c.libro_id WHERE l.titolo LIKE '{$titleLike}'");
    $db->query("DELETE FROM libri WHERE titolo LIKE '{$titleLike}'");
    $emailLike = $db->real_escape_string("u%-{$run}{$emailDomain}");
    $db->query("DELETE FROM utenti WHERE email LIKE '{$emailLike}'");
};

$callStore = static function (
    int $adminId,
    int $userId,
    int $bookId,
    string $copyCode,
    bool $saveAndNew = false,
    ?string $startDate = null,
    ?string $endDate = null,
    ?string $submissionToken = null
) use ($db, $today, $due) {
    $_SESSION['user'] = ['tipo_utente' => 'admin', 'id' => $adminId];
    $body = [
        'loan_submission_token' => $submissionToken ?? \App\Support\OneTimeFormToken::issue('loan.create'),
        'utente_id' => (string) $userId,
        'libro_id' => (string) $bookId,
        'copy_code' => $copyCode,
        'data_prestito' => $startDate ?? $today,
        'data_scadenza' => $endDate ?? $due,
        'note' => 'Discussion #238 regression',
        'consegna_immediata' => '1',
        'scarica_pdf' => '0',
    ];
    if ($saveAndNew) {
        $body['save_and_new'] = '1';
    }
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/admin/loans/store')
        ->withParsedBody($body);

    return (new PrestitiController())->store(
        $request,
        (new ResponseFactory())->createResponse(),
        $db
    );
};

$callApproval = static function (int $loanId) use ($db) {
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/admin/loans/approve')
        ->withParsedBody(['loan_id' => (string) $loanId]);

    return (new LoanApprovalController())->approveLoan(
        $request,
        (new ResponseFactory())->createResponse(),
        $db
    );
};

$cleanup();
$settings = new SettingsRepository($db);

try {
    /* ================= setting accessor: default and strict parsing ====== */
    $db->query(
        "DELETE FROM system_settings
         WHERE category = 'loans' AND setting_key = 'allow_multiple_loans_same_book'"
    );
    ConfigStore::clearCache();
    $check(!$settings->allowsMultipleLoansSameBook(), 'missing setting keeps the safe OFF default');

    $settings->set('loans', 'allow_multiple_loans_same_book', '1');
    $check($settings->allowsMultipleLoansSameBook(), "only persisted value '1' enables multiplicity");

    foreach (['0', 'true', 'yes', '01', ''] as $malformed) {
        $settings->set('loans', 'allow_multiple_loans_same_book', $malformed);
        $check(
            !$settings->allowsMultipleLoansSameBook(),
            "value " . var_export($malformed, true) . ' fails closed'
        );
    }

    // Keep capacity limits out of these fixtures; the feature must not alter
    // that independent rule (covered by the existing loan limit test suites).
    $settings->set('loans', 'max_active_loans_per_user', '0');

    /* ================= central policy: OFF, ON and unbound rows ========= */
    $policyUser = $makeUser();
    [$policyBook, $policyCopies] = $makeBook(3);
    $activeBoundLoan = $makeLoan($policyBook, $policyCopies[0], $policyUser, 'in_corso', 1);

    $settings->set('loans', 'allow_multiple_loans_same_book', '0');
    $policy = new LoanMultiplicityPolicy($db);
    $check(
        $policy->hasBlockingLoan($policyBook, $policyUser, true),
        'OFF blocks a second borrower/title loan even when both operations bind copies'
    );

    $settings->set('loans', 'allow_multiple_loans_same_book', '1');
    $policy = new LoanMultiplicityPolicy($db);
    $check(
        !$policy->hasBlockingLoan($policyBook, $policyUser, true),
        'ON ignores an existing copy-bound sibling for a new copy-bound operation'
    );
    $check(
        $policy->hasBlockingLoan($policyBook, $policyUser, false),
        'ON remains strict when the proposed operation will not bind a physical copy'
    );

    $sameCopyRejected = false;
    try {
        $makeLoan($policyBook, $policyCopies[0], $policyUser, 'in_corso', 1);
    } catch (mysqli_sql_exception $e) {
        $sameCopyRejected = true;
    }
    $sameCopyCount = (int) $db->query(
        "SELECT COUNT(*) FROM prestiti WHERE copia_id = {$policyCopies[0]} AND attivo = 1"
    )->fetch_row()[0];
    $check(
        $sameCopyRejected && $sameCopyCount === 1,
        'the database overlap guard still forbids two open loans on the same physical copy'
    );

    $pendingLoan = $makeLoan($policyBook, null, $policyUser, 'pendente', 0);
    $check(
        $policy->hasBlockingLoan($policyBook, $policyUser, true),
        'a sibling pending/copyless request remains blocking while ON'
    );
    $check(
        !$policy->hasBlockingLoan($policyBook, $policyUser, true, $pendingLoan),
        'excluding the loan being approved does not make its own pending row self-conflict'
    );
    $check(
        $policy->hasBlockingLoan($policyBook, $policyUser, false, $pendingLoan),
        'exclude-id does not relax an unbound operation against another open title loan'
    );

    $db->query("UPDATE prestiti SET stato = 'annullato', attivo = 0 WHERE id = {$pendingLoan}");
    $activeCopylessLoan = $makeLoan($policyBook, null, $policyUser, 'in_corso', 1);
    $check(
        $policy->hasBlockingLoan($policyBook, $policyUser, true),
        'a legacy active copyless loan remains blocking while ON'
    );
    $check(
        !$policy->hasBlockingLoan($policyBook, $policyUser, true, $activeCopylessLoan),
        'exclude-id correctly removes the legacy row currently being changed'
    );

    /* ================= shared open-copy commitment API ================= */
    // Exercise the database-backed selector and the pure comparator together:
    // callers receive one normalized definition of an open physical commitment.
    $apiSameUser = $makeUser();
    $apiOtherUser = $makeUser();
    $apiCandidateUser = $makeUser();
    [$apiBook, $apiCopies] = $makeBook(3);
    $apiFirstStart = (new DateTimeImmutable($today))->modify('+70 days')->format('Y-m-d');
    $apiFirstEnd = (new DateTimeImmutable($today))->modify('+75 days')->format('Y-m-d');
    $apiSecondStart = (new DateTimeImmutable($today))->modify('+85 days')->format('Y-m-d');
    $apiSecondEnd = (new DateTimeImmutable($today))->modify('+90 days')->format('Y-m-d');
    $apiUserStart = (new DateTimeImmutable($today))->modify('+100 days')->format('Y-m-d');
    $apiUserEnd = (new DateTimeImmutable($today))->modify('+105 days')->format('Y-m-d');
    $apiSameUserLoan = $makeLoan(
        $apiBook,
        $apiCopies[0],
        $apiSameUser,
        'prenotato',
        1,
        $apiFirstStart,
        $apiFirstEnd
    );
    $apiOtherUserLoan = $makeLoan(
        $apiBook,
        $apiCopies[0],
        $apiOtherUser,
        'prenotato',
        1,
        $apiSecondStart,
        $apiSecondEnd
    );
    $apiSameUserOtherCopyLoan = $makeLoan(
        $apiBook,
        $apiCopies[1],
        $apiSameUser,
        'da_ritirare',
        1,
        $apiUserStart,
        $apiUserEnd
    );
    $makeLoan(
        $apiBook,
        $apiCopies[0],
        $apiCandidateUser,
        'restituito',
        0,
        $apiFirstStart,
        $apiFirstEnd
    );
    $apiOverdueStart = (new DateTimeImmutable($today))->modify('-20 days')->format('Y-m-d');
    $apiOverdueEnd = (new DateTimeImmutable($today))->modify('-10 days')->format('Y-m-d');
    $apiOverdueLoan = $makeLoan(
        $apiBook,
        $apiCopies[2],
        $apiCandidateUser,
        'in_ritardo',
        1,
        $apiOverdueStart,
        $apiOverdueEnd
    );

    $apiPolicy = new LoanMultiplicityPolicy($db);
    $db->begin_transaction();
    try {
        $apiBookLock = $db->prepare('SELECT id FROM libri WHERE id = ? FOR UPDATE');
        $apiBookLock->bind_param('i', $apiBook);
        $apiBookLock->execute();
        $apiBookLock->get_result()->fetch_assoc();
        $apiBookLock->close();

        $apiCopyCommitments = $apiPolicy->lockOpenCopyCommitments(
            $apiBook,
            copyId: $apiCopies[0]
        );
        $apiUserCommitmentsExcludingCandidate = $apiPolicy->lockOpenCopyCommitments(
            $apiBook,
            userId: $apiSameUser,
            excludeLoanId: $apiSameUserLoan
        );
        $apiOverdueCommitments = $apiPolicy->lockOpenCopyCommitments(
            $apiBook,
            copyId: $apiCopies[2]
        );
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }

    $check(
        $apiCopyCommitments === [
            [
                'loanId' => $apiSameUserLoan,
                'copyId' => $apiCopies[0],
                'userId' => $apiSameUser,
                'startDate' => $apiFirstStart,
                'endDate' => $apiFirstEnd,
                'state' => 'prenotato',
            ],
            [
                'loanId' => $apiOtherUserLoan,
                'copyId' => $apiCopies[0],
                'userId' => $apiOtherUser,
                'startDate' => $apiSecondStart,
                'endDate' => $apiSecondEnd,
                'state' => 'prenotato',
            ],
        ],
        'copy selector returns normalized open commitments and excludes terminal rows'
    );
    $check(
        $apiUserCommitmentsExcludingCandidate === [[
            'loanId' => $apiSameUserOtherCopyLoan,
            'copyId' => $apiCopies[1],
            'userId' => $apiSameUser,
            'startDate' => $apiUserStart,
            'endDate' => $apiUserEnd,
            'state' => 'da_ritirare',
        ]],
        'user selector spans copies and excludeLoanId removes only the candidate row'
    );

    $apiFarStart = (new DateTimeImmutable($today))->modify('+365 days')->format('Y-m-d');
    $apiFarEnd = (new DateTimeImmutable($today))->modify('+370 days')->format('Y-m-d');
    $check(
        LoanMultiplicityPolicy::candidateConflictsWithOpenCommitments(
            0,
            $apiSameUser,
            $apiFarStart,
            $apiFarEnd,
            $apiCopyCommitments
        ),
        'same borrower/title identity conflicts even when the date windows are disjoint'
    );
    $check(
        LoanMultiplicityPolicy::candidateConflictsWithOpenCommitments(
            0,
            $apiCandidateUser,
            $apiFirstEnd,
            (new DateTimeImmutable($apiFirstEnd))->modify('+2 days')->format('Y-m-d'),
            $apiCopyCommitments
        ),
        'another borrower conflicts when its window touches an existing end date inclusively'
    );
    $check(
        !LoanMultiplicityPolicy::candidateConflictsWithOpenCommitments(
            0,
            $apiCandidateUser,
            (new DateTimeImmutable($apiSecondEnd))->modify('+1 day')->format('Y-m-d'),
            (new DateTimeImmutable($apiSecondEnd))->modify('+5 days')->format('Y-m-d'),
            $apiCopyCommitments
        ),
        'another borrower does not conflict when every locked window is disjoint'
    );
    $check(
        LoanMultiplicityPolicy::candidateConflictsWithOpenCommitments(
            0,
            $apiSameUser,
            $apiFarStart,
            $apiFarEnd,
            $apiOverdueCommitments
        ),
        'an overdue commitment remains open-ended for a different borrower'
    );
    $check(
        !LoanMultiplicityPolicy::candidateConflictsWithOpenCommitments(
            $apiSameUserLoan,
            $apiCandidateUser,
            $apiFirstStart,
            $apiFirstEnd,
            $apiCopyCommitments
        ) && ($apiOverdueCommitments[0]['loanId'] ?? 0) === $apiOverdueLoan,
        'the comparator excludes its candidate loan without hiding other normalized rows'
    );

    /* ================= real staff creation and quick-copy workflow ====== */
    $admin = $makeUser('admin');
    $multiBorrower = $makeUser();
    [$multiBook, $multiCopyIds, $multiCopyCodes] = $makeBook(3);
    $settings->set('loans', 'allow_multiple_loans_same_book', '1');

    $response = $callStore($admin, $multiBorrower, $multiBook, $multiCopyCodes[0], true);
    $check(
        $response->getStatusCode() === 302 && str_contains($response->getHeaderLine('Location'), 'created=1'),
        'real store() creates the first scanned physical-copy loan in quick mode'
    );
    $retained = $_SESSION['loan_form_old'] ?? [];
    $check(
        (int) ($retained['libro_id'] ?? 0) === $multiBook
            && (int) ($retained['utente_id'] ?? 0) === $multiBorrower
            && ($retained['note'] ?? '') === 'Discussion #238 regression',
        'quick mode retains borrower, title, dates and note while multiplicity is ON'
    );
    $check(
        !array_key_exists('copy_code', $retained) && !array_key_exists('save_and_new', $retained),
        'quick mode always clears the physical-copy code and one-shot submit flag'
    );

    $response = $callStore($admin, $multiBorrower, $multiBook, $multiCopyCodes[0]);
    $check(
        str_contains($response->getHeaderLine('Location'), 'error=copy_not_available')
            && $loanCount($multiBook, $multiBorrower) === 1,
        'real store() still rejects reusing the same scanned copy while ON'
    );

    $response = $callStore($admin, $multiBorrower, $multiBook, $multiCopyCodes[1]);
    $distinctCopies = $db->query(
        "SELECT COUNT(DISTINCT copia_id), COUNT(*)
         FROM prestiti
         WHERE libro_id = {$multiBook} AND utente_id = {$multiBorrower} AND attivo = 1"
    )->fetch_row();
    $check(
        str_contains($response->getHeaderLine('Location'), 'created=1')
            && (int) $distinctCopies[0] === 2
            && (int) $distinctCopies[1] === 2,
        'real store() permits two simultaneous loans of distinct copies of one title while ON'
    );

    $replayBorrower = $makeUser();
    [$replayBook, $replayCopyIds] = $makeBook(2);
    $replayToken = \App\Support\OneTimeFormToken::issue('loan.create');
    $firstReplayResponse = $callStore(
        $admin,
        $replayBorrower,
        $replayBook,
        '',
        false,
        null,
        null,
        $replayToken
    );
    $secondReplayResponse = $callStore(
        $admin,
        $replayBorrower,
        $replayBook,
        '',
        false,
        null,
        null,
        $replayToken
    );
    $check(
        str_contains($firstReplayResponse->getHeaderLine('Location'), 'created=1')
            && str_contains($secondReplayResponse->getHeaderLine('Location'), 'error=duplicate_submission')
            && $loanCount($replayBook, $replayBorrower) === 1
            && count($replayCopyIds) === 2,
        'server-side one-time token rejects a replay before it auto-assigns another copy'
    );

    // Each physical copy is still a full active loan for the independent
    // borrower cap. The opt-in changes title multiplicity, never quota math.
    $settings->set('loans', 'max_active_loans_per_user', '2');
    $response = $callStore($admin, $multiBorrower, $multiBook, $multiCopyCodes[2]);
    $thirdCopyLoanCount = (int) $db->query(
        "SELECT COUNT(*) FROM prestiti
         WHERE libro_id = {$multiBook} AND utente_id = {$multiBorrower} AND attivo = 1"
    )->fetch_row()[0];
    $thirdCopyAssigned = (int) $db->query(
        "SELECT COUNT(*) FROM prestiti WHERE copia_id = {$multiCopyIds[2]}"
    )->fetch_row()[0];
    $check(
        str_contains($response->getHeaderLine('Location'), 'error=max_loans_reached')
            && $thirdCopyLoanCount === 2
            && $thirdCopyAssigned === 0,
        'max_active_loans_per_user counts every same-title copy and blocks the next loan'
    );
    $settings->set('loans', 'max_active_loans_per_user', '0');

    // P2 regression: copy identity is stronger than date overlap. An open
    // future commitment to copy A means a second open same-borrower/title row
    // must represent copy B, even when the requested windows are disjoint.
    $settings->set('loans', 'allow_multiple_loans_same_book', '1');
    $windowBorrower = $makeUser();
    [$windowBook, $windowCopies, $windowCopyCodes] = $makeBook(2);
    $futureStart = (new DateTimeImmutable($today))->modify('+20 days')->format('Y-m-d');
    $futureEnd = (new DateTimeImmutable($futureStart))->modify('+5 days')->format('Y-m-d');
    $scheduledCopyLoan = $makeLoan(
        $windowBook,
        $windowCopies[0],
        $windowBorrower,
        'prenotato',
        1,
        $futureStart,
        $futureEnd
    );

    $windowPolicy = new LoanMultiplicityPolicy($db);
    $committedBefore = $windowPolicy->committedCopyIds($windowBook, $windowBorrower);
    $committedExcludingCurrent = $windowPolicy->committedCopyIds(
        $windowBook,
        $windowBorrower,
        $scheduledCopyLoan
    );
    $check(
        $committedBefore === [$windowCopies[0]] && $committedExcludingCurrent === [],
        'committedCopyIds tracks physical identity and honours excludeLoanId'
    );

    $response = $callStore(
        $admin,
        $windowBorrower,
        $windowBook,
        $windowCopyCodes[0]
    );
    $check(
        str_contains($response->getHeaderLine('Location'), 'error=copy_not_available')
            && $loanCount($windowBook, $windowBorrower) === 1,
        'explicit copy reuse is rejected for disjoint same-borrower/title windows while ON'
    );

    $response = $callStore($admin, $windowBorrower, $windowBook, '');
    $automaticCopyRow = $db->query(
        "SELECT copia_id FROM prestiti
         WHERE libro_id = {$windowBook} AND utente_id = {$windowBorrower} AND id <> {$scheduledCopyLoan}
         ORDER BY id DESC LIMIT 1"
    )->fetch_assoc() ?: [];
    $check(
        str_contains($response->getHeaderLine('Location'), 'created=1')
            && $loanCount($windowBook, $windowBorrower) === 2
            && (int) ($automaticCopyRow['copia_id'] ?? 0) === $windowCopies[1],
        'automatic assignment skips the committed copy and chooses another physical copy for a disjoint window'
    );

    // Queue reservations are book-level commitments and deliberately remain
    // unique even though distinct copy-bound staff loans may coexist.
    $reservedBorrower = $makeUser();
    [$reservedBook, , $reservedCopyCodes] = $makeBook(2);
    $activeReservation = $makeActiveReservation($reservedBook, $reservedBorrower);
    $response = $callStore($admin, $reservedBorrower, $reservedBook, $reservedCopyCodes[0]);
    $reservationStateStmt = $db->prepare('SELECT stato FROM prenotazioni WHERE id = ?');
    $reservationStateStmt->bind_param('i', $activeReservation);
    $reservationStateStmt->execute();
    $reservationState = (string) $reservationStateStmt->get_result()->fetch_row()[0];
    $reservationStateStmt->close();
    $check(
        str_contains($response->getHeaderLine('Location'), 'error=duplicate_reservation')
            && $loanCount($reservedBook, $reservedBorrower) === 0
            && $reservationState === 'attiva',
        'an active same-user/title reservation still blocks real store() while ON'
    );

    // A scheduled reservation remains a title-level commitment even when it
    // already pins a physical copy. Only loans that represent physical items
    // in circulation may coexist under the opt-in policy.
    $settings->set('loans', 'allow_multiple_loans_same_book', '1');
    $pinnedReservationBorrower = $makeUser();
    [$pinnedReservationBook, $pinnedReservationCopies, $pinnedReservationCodes] = $makeBook(2);
    $pinnedReservationStart = (new DateTimeImmutable($today))->modify('+20 days')->format('Y-m-d');
    $pinnedReservationEnd = (new DateTimeImmutable($today))->modify('+25 days')->format('Y-m-d');
    $pinnedReservationLoan = $makeLoan(
        $pinnedReservationBook,
        $pinnedReservationCopies[0],
        $pinnedReservationBorrower,
        'prenotato',
        1,
        $pinnedReservationStart,
        $pinnedReservationEnd,
        'prenotazione'
    );
    $response = $callStore(
        $admin,
        $pinnedReservationBorrower,
        $pinnedReservationBook,
        $pinnedReservationCodes[1]
    );
    $pinnedReservationStmt = $db->prepare(
        'SELECT stato, attivo, copia_id FROM prestiti WHERE id = ?'
    );
    $pinnedReservationStmt->bind_param('i', $pinnedReservationLoan);
    $pinnedReservationStmt->execute();
    $pinnedReservationRow = $pinnedReservationStmt->get_result()->fetch_assoc() ?: [];
    $pinnedReservationStmt->close();
    $check(
        str_contains($response->getHeaderLine('Location'), 'error=duplicate_reservation')
            && $loanCount($pinnedReservationBook, $pinnedReservationBorrower) === 1
            && ($pinnedReservationRow['stato'] ?? '') === 'prenotato'
            && (int) ($pinnedReservationRow['attivo'] ?? 0) === 1
            && (int) ($pinnedReservationRow['copia_id'] ?? 0) === $pinnedReservationCopies[0],
        'a copy-bound active reservation remains blocking while multiplicity is ON'
    );

    // Scheduled direct loans are physical-copy commitments, not queue
    // reservations: with the option ON, a distinct copy may coexist.
    $directScheduledBorrower = $makeUser();
    [$directScheduledBook, $directScheduledCopies, $directScheduledCodes] = $makeBook(2);
    $directScheduledStart = (new DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d');
    $directScheduledEnd = (new DateTimeImmutable($today))->modify('+35 days')->format('Y-m-d');
    $directScheduledLoan = $makeLoan(
        $directScheduledBook,
        $directScheduledCopies[0],
        $directScheduledBorrower,
        'prenotato',
        1,
        $directScheduledStart,
        $directScheduledEnd
    );
    $response = $callStore(
        $admin,
        $directScheduledBorrower,
        $directScheduledBook,
        $directScheduledCodes[1]
    );
    $directScheduledStmt = $db->prepare(
        "SELECT COUNT(*) AS total, COUNT(DISTINCT copia_id) AS physical_copies,
                SUM(CASE WHEN id = ? AND origine = 'diretto' AND stato = 'prenotato' THEN 1 ELSE 0 END) AS original_ok
         FROM prestiti WHERE libro_id = ? AND utente_id = ? AND attivo = 1"
    );
    $directScheduledStmt->bind_param(
        'iii',
        $directScheduledLoan,
        $directScheduledBook,
        $directScheduledBorrower
    );
    $directScheduledStmt->execute();
    $directScheduledRows = $directScheduledStmt->get_result()->fetch_assoc() ?: [];
    $directScheduledStmt->close();
    $check(
        str_contains($response->getHeaderLine('Location'), 'created=1')
            && (int) ($directScheduledRows['total'] ?? 0) === 2
            && (int) ($directScheduledRows['physical_copies'] ?? 0) === 2
            && (int) ($directScheduledRows['original_ok'] ?? 0) === 1,
        'a copy-bound direct prenotato loan may coexist with another distinct copy while ON'
    );

    // The same distinction holds during the direct pickup-ready phase.
    $directPickupBorrower = $makeUser();
    [$directPickupBook, $directPickupCopies, $directPickupCodes] = $makeBook(2);
    $directPickupLoan = $makeLoan(
        $directPickupBook,
        $directPickupCopies[0],
        $directPickupBorrower,
        'da_ritirare',
        1
    );
    $response = $callStore(
        $admin,
        $directPickupBorrower,
        $directPickupBook,
        $directPickupCodes[1]
    );
    $directPickupStmt = $db->prepare(
        "SELECT COUNT(*) AS total, COUNT(DISTINCT copia_id) AS physical_copies,
                SUM(CASE WHEN id = ? AND origine = 'diretto' AND stato = 'da_ritirare' THEN 1 ELSE 0 END) AS original_ok
         FROM prestiti WHERE libro_id = ? AND utente_id = ? AND attivo = 1"
    );
    $directPickupStmt->bind_param('iii', $directPickupLoan, $directPickupBook, $directPickupBorrower);
    $directPickupStmt->execute();
    $directPickupRows = $directPickupStmt->get_result()->fetch_assoc() ?: [];
    $directPickupStmt->close();
    $check(
        str_contains($response->getHeaderLine('Location'), 'created=1')
            && (int) ($directPickupRows['total'] ?? 0) === 2
            && (int) ($directPickupRows['physical_copies'] ?? 0) === 2
            && (int) ($directPickupRows['original_ok'] ?? 0) === 1,
        'a copy-bound direct da_ritirare loan may coexist with another distinct copy while ON'
    );

    /* ================= strict mode remains backward compatible ========== */
    $strictBorrower = $makeUser();
    [$strictBook, , $strictCopyCodes] = $makeBook(2);
    $settings->set('loans', 'allow_multiple_loans_same_book', '0');

    $response = $callStore($admin, $strictBorrower, $strictBook, $strictCopyCodes[0], true);
    $check(str_contains($response->getHeaderLine('Location'), 'created=1'), 'strict mode still creates a normal first loan');
    $strictRetained = $_SESSION['loan_form_old'] ?? [];
    $check(
        !array_key_exists('libro_id', $strictRetained),
        'strict quick mode preserves the historical behaviour of clearing the title'
    );

    $response = $callStore($admin, $strictBorrower, $strictBook, $strictCopyCodes[1]);
    $check(
        str_contains($response->getHeaderLine('Location'), 'error=duplicate_reservation')
            && $loanCount($strictBook, $strictBorrower) === 1,
        'strict mode rejects a second distinct copy for the same borrower/title'
    );

    /* ================= real pending-loan approval semantics ============= */
    $settings->set('loans', 'allow_multiple_loans_same_book', '1');
    $approvalUser = $makeUser();
    [$approvalBook, $approvalCopies] = $makeBook(2);
    $makeLoan($approvalBook, $approvalCopies[0], $approvalUser, 'in_corso', 1);
    $loanToApprove = $makeLoan($approvalBook, null, $approvalUser, 'pendente', 0);

    $response = $callApproval($loanToApprove);
    $approvalBody = (array) json_decode((string) $response->getBody(), true);
    $approvedRow = $db->query(
        "SELECT stato, attivo, copia_id FROM prestiti WHERE id = {$loanToApprove}"
    )->fetch_assoc() ?: [];
    $check(
        $response->getStatusCode() === 200
            && ($approvalBody['success'] ?? null) === true
            && ($approvedRow['stato'] ?? '') === 'da_ritirare'
            && (int) ($approvedRow['attivo'] ?? 0) === 1
            && (int) ($approvedRow['copia_id'] ?? 0) === $approvalCopies[1],
        'real approveLoan() assigns a distinct copy beside an existing copy-bound loan while ON'
    );

    $settings->set('loans', 'allow_multiple_loans_same_book', '0');
    $strictApprovalUser = $makeUser();
    [$strictApprovalBook, $strictApprovalCopies] = $makeBook(2);
    $makeLoan($strictApprovalBook, $strictApprovalCopies[0], $strictApprovalUser, 'in_corso', 1);
    $strictPendingLoan = $makeLoan($strictApprovalBook, null, $strictApprovalUser, 'pendente', 0);

    $response = $callApproval($strictPendingLoan);
    $strictApprovalRow = $db->query(
        "SELECT stato, attivo, copia_id FROM prestiti WHERE id = {$strictPendingLoan}"
    )->fetch_assoc() ?: [];
    $check(
        $response->getStatusCode() === 409
            && ($strictApprovalRow['stato'] ?? '') === 'pendente'
            && (int) ($strictApprovalRow['attivo'] ?? 1) === 0
            && $strictApprovalRow['copia_id'] === null,
        'real approveLoan() remains title-strict beside an active loan while OFF'
    );

    $settings->set('loans', 'allow_multiple_loans_same_book', '1');
    $pendingSiblingUser = $makeUser();
    [$pendingSiblingBook] = $makeBook(2);
    $pendingCandidate = $makeLoan($pendingSiblingBook, null, $pendingSiblingUser, 'pendente', 0);
    $pendingSibling = $makeLoan($pendingSiblingBook, null, $pendingSiblingUser, 'pendente', 0);

    $response = $callApproval($pendingCandidate);
    $pendingRows = (int) $db->query(
        "SELECT COUNT(*) FROM prestiti
         WHERE id IN ({$pendingCandidate}, {$pendingSibling}) AND stato = 'pendente' AND attivo = 0 AND copia_id IS NULL"
    )->fetch_row()[0];
    $check(
        $response->getStatusCode() === 409 && $pendingRows === 2,
        'real approveLoan() keeps a sibling pending/copyless request blocking while ON'
    );

    /* ================= real reassignment semantics ====================== */
    $settings->set('loans', 'allow_multiple_loans_same_book', '1');
    $reassignTarget = $makeUser();
    $reassignSource = $makeUser();
    [$reassignBook, $reassignCopies] = $makeBook(2);
    $makeLoan($reassignBook, $reassignCopies[0], $reassignTarget, 'in_corso', 1);
    $loanToReassign = $makeLoan($reassignBook, $reassignCopies[1], $reassignSource, 'in_corso', 1);

    $_SESSION['user'] = ['tipo_utente' => 'admin', 'id' => $admin];
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', "/admin/loans/edit/{$loanToReassign}")
        ->withParsedBody(['utente_id' => (string) $reassignTarget]);
    $response = (new PrestitiController())->update(
        $request,
        (new ResponseFactory())->createResponse(),
        $db,
        $loanToReassign
    );
    $reassignedUser = (int) $db->query(
        "SELECT utente_id FROM prestiti WHERE id = {$loanToReassign}"
    )->fetch_row()[0];
    $check(
        !str_contains($response->getHeaderLine('Location'), 'error=') && $reassignedUser === $reassignTarget,
        'real update() permits reassignment when both same-title loans have distinct copies'
    );

    $pickupSource = $makeUser();
    $pickupTarget = $makeUser();
    [$pickupReassignBook, $pickupReassignCopies] = $makeBook(1);
    $pickupReassignLoan = $makeLoan(
        $pickupReassignBook,
        $pickupReassignCopies[0],
        $pickupSource,
        'da_ritirare',
        1
    );
    $db->query("UPDATE prestiti SET pickup_notification_sent = 1 WHERE id = {$pickupReassignLoan}");
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', "/admin/loans/edit/{$pickupReassignLoan}")
        ->withParsedBody(['utente_id' => (string) $pickupTarget]);
    $response = (new PrestitiController())->update(
        $request,
        (new ResponseFactory())->createResponse(),
        $db,
        $pickupReassignLoan
    );
    $pickupReassigned = $db->query(
        "SELECT utente_id, pickup_notification_sent FROM prestiti WHERE id = {$pickupReassignLoan}"
    )->fetch_assoc() ?: [];
    $check(
        !str_contains($response->getHeaderLine('Location'), 'error=')
            && (int) ($pickupReassigned['utente_id'] ?? 0) === $pickupTarget
            && (int) ($pickupReassigned['pickup_notification_sent'] ?? 1) === 0,
        'reassigning a ready-for-pickup loan releases the old recipient notification claim'
    );

    $legacyTarget = $makeUser();
    $legacySource = $makeUser();
    [$legacyBook, $legacyCopies] = $makeBook(1);
    $makeLoan($legacyBook, $legacyCopies[0], $legacyTarget, 'in_corso', 1);
    $legacyCopylessLoan = $makeLoan($legacyBook, null, $legacySource, 'in_corso', 1);

    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', "/admin/loans/edit/{$legacyCopylessLoan}")
        ->withParsedBody(['utente_id' => (string) $legacyTarget]);
    $response = (new PrestitiController())->update(
        $request,
        (new ResponseFactory())->createResponse(),
        $db,
        $legacyCopylessLoan
    );
    $legacyUserAfter = (int) $db->query(
        "SELECT utente_id FROM prestiti WHERE id = {$legacyCopylessLoan}"
    )->fetch_row()[0];
    $check(
        str_contains($response->getHeaderLine('Location'), 'error=duplicate_reservation')
            && $legacyUserAfter === $legacySource,
        'real update() keeps legacy copyless reassignment strict while ON'
    );

    /* ================= lifecycle allocation after disabling the toggle === */
    // Existing duplicate rows are legitimate historical state. Turning the
    // option OFF must prevent new duplicates, not let background lifecycle
    // allocators collapse two rows back onto one physical item.
    $settings->set('loans', 'allow_multiple_loans_same_book', '1');
    $newCopyUser = $makeUser();
    [$newCopyBook, $newCopyCopies] = $makeBook(2);
    $newCopySiblingStart = (new DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d');
    $newCopySiblingEnd = (new DateTimeImmutable($today))->modify('+35 days')->format('Y-m-d');
    $newCopyTargetStart = (new DateTimeImmutable($today))->modify('+40 days')->format('Y-m-d');
    $newCopyTargetEnd = (new DateTimeImmutable($today))->modify('+45 days')->format('Y-m-d');
    $newCopySibling = $makeLoan(
        $newCopyBook,
        $newCopyCopies[0],
        $newCopyUser,
        'prenotato',
        1,
        $newCopySiblingStart,
        $newCopySiblingEnd
    );
    $newCopyTarget = $makeLoan(
        $newCopyBook,
        null,
        $newCopyUser,
        'prenotato',
        1,
        $newCopyTargetStart,
        $newCopyTargetEnd
    );
    $newCopyFallbackUser = $makeUser();
    $newCopyFallback = $makeLoan(
        $newCopyBook,
        null,
        $newCopyFallbackUser,
        'prenotato',
        1,
        $newCopyTargetStart,
        $newCopyTargetEnd
    );
    // Make FIFO deterministic even on databases whose TIMESTAMP precision is
    // one second: the incompatible borrower is first, the compatible one next.
    $fifoCreatedStmt = $db->prepare('UPDATE prestiti SET created_at = ? WHERE id = ?');
    $fifoCreatedAt = '2001-01-01 00:00:00';
    $fifoLoanId = $newCopyTarget;
    $fifoCreatedStmt->bind_param('si', $fifoCreatedAt, $fifoLoanId);
    $fifoCreatedStmt->execute();
    $fifoCreatedAt = '2001-01-01 00:00:01';
    $fifoLoanId = $newCopyFallback;
    $fifoCreatedStmt->execute();
    $fifoCreatedStmt->close();
    $settings->set('loans', 'allow_multiple_loans_same_book', '0');

    $committedAfterDisable = (new LoanMultiplicityPolicy($db))->committedCopyIds(
        $newCopyBook,
        $newCopyUser,
        $newCopyTarget
    );
    $check(
        $committedAfterDisable === [$newCopyCopies[0]],
        'disabling the toggle preserves committed-copy knowledge for existing duplicate lifecycle rows'
    );

    $db->begin_transaction();
    try {
        (new ReservationReassignmentService($db))
            ->setExternalTransaction(true)
            ->reassignOnNewCopy($newCopyBook, $newCopyCopies[0]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    $fifoAssignmentStmt = $db->prepare(
        'SELECT id, copia_id FROM prestiti WHERE id IN (?, ?) ORDER BY id'
    );
    $fifoAssignmentStmt->bind_param('ii', $newCopyTarget, $newCopyFallback);
    $fifoAssignmentStmt->execute();
    $fifoAssignments = $fifoAssignmentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $fifoAssignmentStmt->close();
    $fifoCopyByLoan = [];
    foreach ($fifoAssignments as $fifoAssignment) {
        $fifoCopyByLoan[(int) $fifoAssignment['id']] = $fifoAssignment['copia_id'] !== null
            ? (int) $fifoAssignment['copia_id']
            : null;
    }
    $check(
        ($fifoCopyByLoan[$newCopyTarget] ?? null) === null
            && ($fifoCopyByLoan[$newCopyFallback] ?? null) === $newCopyCopies[0],
        'reassignOnNewCopy() skips an incompatible FIFO head and assigns the returned copy to the next borrower'
    );

    $db->begin_transaction();
    try {
        (new ReservationReassignmentService($db))
            ->setExternalTransaction(true)
            ->reassignOnNewCopy($newCopyBook, $newCopyCopies[1]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    $newCopyAssignmentStmt = $db->prepare(
        'SELECT id, copia_id FROM prestiti WHERE id IN (?, ?, ?) ORDER BY id'
    );
    $newCopyAssignmentStmt->bind_param('iii', $newCopySibling, $newCopyTarget, $newCopyFallback);
    $newCopyAssignmentStmt->execute();
    $newCopyAssignments = $newCopyAssignmentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $newCopyAssignmentStmt->close();
    $newCopyByLoan = [];
    foreach ($newCopyAssignments as $newCopyAssignment) {
        $newCopyByLoan[(int) $newCopyAssignment['id']] = $newCopyAssignment['copia_id'] !== null
            ? (int) $newCopyAssignment['copia_id']
            : null;
    }
    $check(
        count($newCopyAssignments) === 3
            && ($newCopyByLoan[$newCopySibling] ?? null) === $newCopyCopies[0]
            && ($newCopyByLoan[$newCopyTarget] ?? null) === $newCopyCopies[1]
            && ($newCopyByLoan[$newCopyFallback] ?? null) === $newCopyCopies[0],
        'reassignOnNewCopy() assigns the alternative without changing either earlier commitment'
    );

    // Copy-loss allocator: copy A is already committed by this borrower/title,
    // copy B is lost, so the disjoint hold must move to copy C rather than A.
    $settings->set('loans', 'allow_multiple_loans_same_book', '1');
    $copyLostUser = $makeUser();
    [$copyLostBook, $copyLostCopies] = $makeBook(3);
    $copyLostSiblingStart = (new DateTimeImmutable($today))->modify('+50 days')->format('Y-m-d');
    $copyLostSiblingEnd = (new DateTimeImmutable($today))->modify('+55 days')->format('Y-m-d');
    $copyLostTargetStart = (new DateTimeImmutable($today))->modify('+60 days')->format('Y-m-d');
    $copyLostTargetEnd = (new DateTimeImmutable($today))->modify('+65 days')->format('Y-m-d');
    $copyLostSibling = $makeLoan(
        $copyLostBook,
        $copyLostCopies[0],
        $copyLostUser,
        'prenotato',
        1,
        $copyLostSiblingStart,
        $copyLostSiblingEnd
    );
    $copyLostTarget = $makeLoan(
        $copyLostBook,
        $copyLostCopies[1],
        $copyLostUser,
        'prenotato',
        1,
        $copyLostTargetStart,
        $copyLostTargetEnd
    );
    $settings->set('loans', 'allow_multiple_loans_same_book', '0');
    $db->query("UPDATE copie SET stato = 'danneggiato' WHERE id = {$copyLostCopies[1]}");

    (new ReservationReassignmentService($db))->reassignOnCopyLost($copyLostCopies[1]);
    $copyLostAssignments = $db->query(
        "SELECT id, copia_id FROM prestiti WHERE id IN ({$copyLostSibling}, {$copyLostTarget}) ORDER BY id"
    )->fetch_all(MYSQLI_ASSOC);
    $check(
        count($copyLostAssignments) === 2
            && (int) $copyLostAssignments[0]['copia_id'] === $copyLostCopies[0]
            && (int) $copyLostAssignments[1]['copia_id'] === $copyLostCopies[2],
        'reassignOnCopyLost() skips the committed sibling copy and chooses the distinct alternative after toggle OFF'
    );

    // Maintenance allocator: a legacy/unpinned loan whose window starts today
    // must become ready on copy B; copy A belongs to its same-title future
    // sibling even though the two windows are disjoint.
    $settings->set('loans', 'allow_multiple_loans_same_book', '1');
    $maintenanceUser = $makeUser();
    [$maintenanceBook, $maintenanceCopies] = $makeBook(2);
    $maintenanceSiblingStart = (new DateTimeImmutable($today))->modify('+10 days')->format('Y-m-d');
    $maintenanceSiblingEnd = (new DateTimeImmutable($today))->modify('+15 days')->format('Y-m-d');
    $maintenanceTargetEnd = (new DateTimeImmutable($today))->modify('+5 days')->format('Y-m-d');
    $maintenanceSibling = $makeLoan(
        $maintenanceBook,
        $maintenanceCopies[0],
        $maintenanceUser,
        'prenotato',
        1,
        $maintenanceSiblingStart,
        $maintenanceSiblingEnd
    );
    $maintenanceTarget = $makeLoan(
        $maintenanceBook,
        null,
        $maintenanceUser,
        'prenotato',
        1,
        $today,
        $maintenanceTargetEnd
    );
    $settings->set('loans', 'allow_multiple_loans_same_book', '0');

    $activated = (new MaintenanceService($db))->activateScheduledLoans();
    $maintenanceAssignments = $db->query(
        "SELECT id, stato, copia_id FROM prestiti
         WHERE id IN ({$maintenanceSibling}, {$maintenanceTarget}) ORDER BY id"
    )->fetch_all(MYSQLI_ASSOC);
    $check(
        $activated >= 1
            && count($maintenanceAssignments) === 2
            && (int) $maintenanceAssignments[0]['copia_id'] === $maintenanceCopies[0]
            && $maintenanceAssignments[0]['stato'] === 'prenotato'
            && (int) $maintenanceAssignments[1]['copia_id'] === $maintenanceCopies[1]
            && $maintenanceAssignments[1]['stato'] === 'da_ritirare',
        'activateScheduledLoans() assigns an unpinned loan to the distinct alternative after toggle OFF'
    );

    // The variable is intentionally referenced so static analysers also verify
    // that our initial policy fixture was actually created and not optimized away.
    $check($activeBoundLoan > 0 && count($multiCopyIds) === 3, 'fixtures retain explicit physical-copy identity');
} catch (Throwable $e) {
    $failed++;
    fwrite(STDERR, "UNCAUGHT TEST ERROR: {$e->getMessage()}\n{$e->getTraceAsString()}\n");
} finally {
    try {
        // Harmless in autocommit mode; essential if a controller threw after
        // opening a transaction, so fixture cleanup never inherits row locks.
        $db->rollback();
    } catch (Throwable $e) {
        // No active transaction is the normal path.
    }

    try {
        $cleanup();
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "CLEANUP ERROR: {$e->getMessage()}\n");
    }

    try {
        $restoreSetting('allow_multiple_loans_same_book', $originalMultiplicity);
        $restoreSetting('max_active_loans_per_user', $originalMaxLoans);
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "SETTING RESTORE ERROR: {$e->getMessage()}\n");
    }

    $_SESSION = $sessionBefore;
}

echo "\nPassed: " . ($testNo - $failed) . "   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);

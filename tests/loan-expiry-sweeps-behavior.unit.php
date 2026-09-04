<?php
declare(strict_types=1);

/**
 * Behavioral suite for the expiry sweeps, the per-copy overlap trigger and the
 * loan multiplicity policy — the automated half of the circulation state
 * machine.
 *
 * Covers, against the REAL database and the REAL MaintenanceService:
 *  - checkExpiredPickups: da_ritirare past its pickup_deadline → scaduto,
 *    attivo=0, pickup_deadline cleared, only a held copy freed (never a copy
 *    already in prestato), availability recalculated, idempotent on re-run;
 *  - checkExpiredReservations: prenotato whose window fully passed → scaduto,
 *    and reservation-origin pendente whose window passed → scaduto, copy freed,
 *    idempotent;
 *  - waitlist promotion on expiry: the freed capacity promotes the head of the
 *    prenotazioni queue into a pendente loan and completes the reservation;
 *  - DB trigger semantics: per-copy overlap SIGNAL, disjoint windows allowed,
 *    same user on two copies allowed at trigger level (policy decides),
 *    copy-bound pendente occupies, bare pendente does not, open-ended overdue
 *    blocks future windows;
 *  - LoanMultiplicityPolicy: strict by default, relaxed only when the
 *    allow_multiple_loans_same_book setting is enabled AND the operation binds
 *    a copy.
 *
 * Dates are derived from DateHelper::today() (application timezone, the same
 * source the sweeps compare against) — never from the runner's local clock.
 *
 * Run: php tests/loan-expiry-sweeps-behavior.unit.php
 */

use App\Models\SettingsRepository;
use App\Support\DateHelper;
use App\Support\LoanMultiplicityPolicy;
use App\Support\MaintenanceService;

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
    fwrite(STDERR, "FAIL: database unreachable — expiry sweep suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$run = bin2hex(random_bytes(6));
$prefix = "ZZ_SWEEP_{$run}";
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$bookIds = [];
$userIds = [];
$settings = new SettingsRepository($db);
$originalMulti = $settings->get('loans', 'allow_multiple_loans_same_book', '0');

$cleanup = static function () use ($db, &$bookIds, &$userIds, $settings, $originalMulti): void {
    foreach ($bookIds as $id) {
        $db->query("DELETE FROM prenotazioni WHERE libro_id = {$id}");
        $db->query("DELETE FROM prestiti WHERE libro_id = {$id}");
        $db->query("DELETE FROM copie WHERE libro_id = {$id}");
        $db->query("DELETE FROM libri WHERE id = {$id}");
    }
    foreach ($userIds as $id) {
        // In-app notification rows (if any) reference the user; remove them
        // first, tolerating installs where the table differs.
        try { $db->query("DELETE FROM notifications WHERE user_id = {$id}"); } catch (Throwable) {}
        $db->query("DELETE FROM utenti WHERE id = {$id}");
    }
    try { $db->query("DELETE FROM email_delivery_outbox WHERE recipient_email LIKE 'zz-sweep-%@test.local'"); } catch (Throwable) {}
    $settings->set('loans', 'allow_multiple_loans_same_book', (string) $originalMulti);
};
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, 'FAIL: uncaught ' . $e->getMessage() . PHP_EOL);
    $db->close();
    exit(1);
});

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
        $inv = strtoupper("{$suffix}{$i}-") . strtoupper(substr($prefix, -6));
        $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
        $stmt->bind_param('is', $bookId, $inv);
        $stmt->execute();
        $copyIds[] = (int) $db->insert_id;
        $stmt->close();
    }
    return [$bookId, $copyIds];
};

$makeUser = static function (string $suffix) use ($db, $run, &$userIds): array {
    $email = "zz-sweep-{$suffix}-{$run}@test.local";
    $card = 'ZS' . strtoupper($suffix) . strtoupper(substr($run, 0, 8));
    $password = password_hash('SweepSuite!1', PASSWORD_DEFAULT);
    $stmt = $db->prepare(
        "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata)
         VALUES (?, 'Sweep', ?, ?, ?, 'standard', 'attivo', 1)"
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

$loanCol = static function (int $loanId, string $col) use ($db): ?string {
    $res = $db->query("SELECT {$col} AS v FROM prestiti WHERE id = {$loanId}");
    $row = $res ? $res->fetch_assoc() : null;
    return $row === null ? null : ($row['v'] === null ? null : (string) $row['v']);
};
$copyState = static function (int $copyId) use ($db): string {
    return (string) $db->query("SELECT stato FROM copie WHERE id = {$copyId}")->fetch_assoc()['stato'];
};

// ═════════ Fixture A: expired pickup, NO waitlist ═════════
[$bookA, [$copyA]] = $makeBook('A', 1);
[$userA] = $makeUser('a');
$db->query("UPDATE copie SET stato = 'prenotato' WHERE id = {$copyA}");
$db->query("UPDATE libri SET copie_disponibili = 0, stato = 'prenotato' WHERE id = {$bookA}");
$stmt = $db->prepare(
    "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, pickup_deadline)
     VALUES (?, ?, ?, ?, ?, 'da_ritirare', 'diretto', 1, ?)"
);
$sA = $d(-5); $eA = $d(9); $dlA = $d(-2);
$stmt->bind_param('iiisss', $bookA, $copyA, $userA, $sA, $eA, $dlA);
$stmt->execute();
$loanA = (int) $db->insert_id;
$stmt->close();

// ═════════ Fixture B: scheduled reservation whose window fully passed ═════════
[$bookB, [$copyB]] = $makeBook('B', 1);
[$userB] = $makeUser('b');
$db->query("UPDATE copie SET stato = 'prenotato' WHERE id = {$copyB}");
$db->query("UPDATE libri SET copie_disponibili = 0, stato = 'prenotato' WHERE id = {$bookB}");
$stmt = $db->prepare(
    "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
     VALUES (?, ?, ?, ?, ?, 'prenotato', 'richiesta', 1)"
);
$sB = $d(-10); $eB = $d(-3);
$stmt->bind_param('iiiss', $bookB, $copyB, $userB, $sB, $eB);
$stmt->execute();
$loanB = (int) $db->insert_id;
$stmt->close();

// ═════════ Fixture C: promoted reservation never approved before window end ═════════
[$bookC, [$copyC]] = $makeBook('C', 1);
[$userC] = $makeUser('c');
// La copia parte OCCUPATA (prenotato, disponibili=0): lo sweep libera solo
// copie in stato 'prenotato', quindi senza questo setup l'assert passerebbe
// anche se la liberazione non avvenisse mai.
$db->query("UPDATE copie SET stato = 'prenotato' WHERE id = {$copyC}");
$db->query("UPDATE libri SET copie_disponibili = 0 WHERE id = {$bookC}");
$stmt = $db->prepare(
    "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
     VALUES (?, ?, ?, ?, ?, 'pendente', 'prenotazione', 0)"
);
$sC = $d(-10); $eC = $d(-2);
$stmt->bind_param('iiiss', $bookC, $copyC, $userC, $sC, $eC);
$stmt->execute();
$loanC = (int) $db->insert_id;
$stmt->close();

// ═════════ Fixture D: stale pickup points at a copy already physically lent ═════════
[$bookD, [$copyD]] = $makeBook('D', 1);
[$userD] = $makeUser('d');
[$userD2] = $makeUser('d2');
$db->query("UPDATE copie SET stato = 'prestato' WHERE id = {$copyD}");
$db->query("UPDATE libri SET copie_disponibili = 0, stato = 'prestato' WHERE id = {$bookD}");
$stmt = $db->prepare(
    "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, pickup_deadline)
     VALUES (?, ?, ?, ?, ?, 'da_ritirare', 'diretto', 1, ?)"
);
$sD = $d(-30); $eD = $d(-20); $dlD = $d(-2);
$stmt->bind_param('iiisss', $bookD, $copyD, $userD, $sD, $eD, $dlD);
$stmt->execute();
$loanD = (int) $db->insert_id;
$stmt->close();
$stmt = $db->prepare(
    "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
     VALUES (?, ?, ?, ?, ?, 'in_corso', 'diretto', 1)"
);
$sD2 = $d(0); $eD2 = $d(14);
$stmt->bind_param('iiiss', $bookD, $copyD, $userD2, $sD2, $eD2);
$stmt->execute();
$activeLoanD = (int) $db->insert_id;
$stmt->close();

// ═════════ Fixture W: expired pickup WITH a waiting queue behind it ═════════
[$bookW, [$copyW]] = $makeBook('W', 1);
[$userW1] = $makeUser('w1');
[$userW2] = $makeUser('w2');
$db->query("UPDATE copie SET stato = 'prenotato' WHERE id = {$copyW}");
$db->query("UPDATE libri SET copie_disponibili = 0, stato = 'prenotato' WHERE id = {$bookW}");
$stmt = $db->prepare(
    "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, pickup_deadline)
     VALUES (?, ?, ?, ?, ?, 'da_ritirare', 'diretto', 1, ?)"
);
$sW = $d(-5); $eW = $d(9); $dlW = $d(-1);
$stmt->bind_param('iiisss', $bookW, $copyW, $userW1, $sW, $eW, $dlW);
$stmt->execute();
$loanW1 = (int) $db->insert_id;
$stmt->close();
$stmt = $db->prepare(
    "INSERT INTO prenotazioni (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta, queue_position, stato)
     VALUES (?, ?, ?, ?, 1, 'attiva')"
);
$rs = $d(0); $re = $d(7);
$stmt->bind_param('iiss', $bookW, $userW2, $rs, $re);
$stmt->execute();
$resW2 = (int) $db->insert_id;
$stmt->close();

// ═════════ 01-08: checkExpiredPickups behavior ═════════
$maint = new MaintenanceService($db);
$processedPickups = $maint->checkExpiredPickups();
$check($processedPickups >= 3, '01 checkExpiredPickups processes all expired pickups (A, D and W)');
$check($loanCol($loanA, 'stato') === 'scaduto', '02 loan A transitions da_ritirare → scaduto');
$check($loanCol($loanA, 'attivo') === '0', '03 loan A is deactivated (attivo=0)');
$check($loanCol($loanA, 'pickup_deadline') === null, '04 loan A pickup_deadline is cleared on the terminal row');
$noteA = (string) $loanCol($loanA, 'note');
$check($noteA !== '' && str_contains($noteA, 'Ritiro scaduto'), '05 loan A audit note records the expiry');
$check($copyState($copyA) === 'disponibile', '06 copy A is released back to disponibile');
$availA = (int) $db->query("SELECT copie_disponibili FROM libri WHERE id = {$bookA}")->fetch_assoc()['copie_disponibili'];
$check($availA === 1, '07 book A availability is recalculated to 1 free copy');
$noteLenBefore = strlen($noteA);
$maint->checkExpiredPickups();
$check(strlen((string) $loanCol($loanA, 'note')) === $noteLenBefore
    && $loanCol($loanA, 'stato') === 'scaduto',
    '08 a second sweep run is a no-op on the already-expired loan (idempotent)');

// ═════════ 09-12: waitlist promotion on the freed capacity ═════════
$resState = (string) $db->query("SELECT stato FROM prenotazioni WHERE id = {$resW2}")->fetch_assoc()['stato'];
$check($resState === 'completata', '09 the waiting reservation is completed by the promotion');
$promoted = $db->query(
    "SELECT id, stato, attivo, pickup_deadline FROM prestiti
     WHERE libro_id = {$bookW} AND utente_id = {$userW2} ORDER BY id DESC LIMIT 1"
)->fetch_assoc();
$check($promoted !== null && $promoted['stato'] === 'pendente' && (string) $promoted['attivo'] === '0',
    '10 promotion creates a pendente (admin-approval) loan for the queue head');
$check($promoted !== null && $promoted['pickup_deadline'] === null,
    '11 the promoted pendente has no pickup_deadline yet (set at approval, by design)');
$check($loanCol($loanW1, 'stato') === 'scaduto' && $loanCol($loanW1, 'pickup_deadline') === null,
    '12 the expired pickup behind the queue is terminal with a cleared deadline');
$check($loanCol($loanD, 'stato') === 'scaduto'
    && $loanCol($activeLoanD, 'stato') === 'in_corso'
    && $copyState($copyD) === 'prestato',
    '12b expiry never releases a copy whose lifecycle has already advanced to prestato');

// ═════════ 13-17: checkExpiredReservations behavior ═════════
$processedRes = $maint->checkExpiredReservations();
$check($processedRes >= 2, '13 checkExpiredReservations processes scheduled and pending reservation windows');
$check($loanCol($loanB, 'stato') === 'scaduto', '14 loan B transitions prenotato → scaduto');
$check($loanCol($loanB, 'attivo') === '0', '15 loan B is deactivated');
$check($copyState($copyB) === 'disponibile', '16 copy B is released');
$check($loanCol($loanC, 'stato') === 'scaduto', '16b promoted-but-unapproved reservation expires instead of holding a copy forever');
$check($loanCol($loanC, 'attivo') === '0', '16c expired pending conversion remains inactive and terminal');
$check($copyState($copyC) === 'disponibile', '16d its physical copy remains available for reassignment');
$maint->checkExpiredReservations();
$check($loanCol($loanB, 'stato') === 'scaduto', '17 reservation expiry is idempotent on re-run');

// ═════════ 18-23: trigger semantics on a dedicated sandbox book ═════════
[$bookT, [$copyT1, $copyT2]] = $makeBook('T', 2);
[$userT1] = $makeUser('t1');
[$userT2] = $makeUser('t2');

$insertLoan = static function (int $book, ?int $copy, int $user, string $s, string $e, string $stato, int $attivo) use ($db): int {
    $stmt = $db->prepare(
        "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
         VALUES (?, ?, ?, ?, ?, ?, 'diretto', ?)"
    );
    $stmt->bind_param('iiisssi', $book, $copy, $user, $s, $e, $stato, $attivo);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$base = $insertLoan($bookT, $copyT1, $userT1, $d(1), $d(8), 'prenotato', 1);
$overlapBlocked = false;
try {
    $insertLoan($bookT, $copyT1, $userT2, $d(4), $d(12), 'prenotato', 1);
} catch (mysqli_sql_exception) {
    $overlapBlocked = true;
}
$check($overlapBlocked, '18 the trigger SIGNALs an overlapping active window on the SAME copy');

$disjoint = $insertLoan($bookT, $copyT1, $userT2, $d(20), $d(27), 'prenotato', 1);
$check($disjoint > 0, '19 a date-disjoint window on the same copy is allowed (calendar model)');

$sameUserOtherCopy = $insertLoan($bookT, $copyT2, $userT1, $d(1), $d(8), 'prenotato', 1);
$check($sameUserOtherCopy > 0, '20 the trigger is PER-COPY: same user on a second copy passes (policy decides)');

$pendenteBound = $insertLoan($bookT, $copyT2, $userT2, $d(30), $d(37), 'pendente', 0);
$pendingBlocks = false;
try {
    $insertLoan($bookT, $copyT2, $userT1, $d(32), $d(40), 'prenotato', 1);
} catch (mysqli_sql_exception) {
    $pendingBlocks = true;
}
$check($pendingBlocks, '21 a copy-bound pendente occupies its copy for the trigger');

$bare = $insertLoan($bookT, null, $userT2, $d(50), $d(57), 'pendente', 0);
$notBlocked = $insertLoan($bookT, $copyT2, $userT1, $d(50), $d(57), 'prenotato', 1);
$check($bare > 0 && $notBlocked > 0, '22 a bare (copyless) pendente does NOT occupy any copy');

$db->query("DELETE FROM prestiti WHERE libro_id = {$bookT}");
$overdueLoan = $insertLoan($bookT, $copyT1, $userT1, $d(-20), $d(-2), 'in_corso', 1);
$openEndedBlocks = false;
try {
    $insertLoan($bookT, $copyT1, $userT2, $d(5), $d(12), 'prenotato', 1);
} catch (mysqli_sql_exception) {
    $openEndedBlocks = true;
}
$check($openEndedBlocks, '23 an overdue in_corso blocks FUTURE windows too (open-ended overdue)');

// ═════════ 24-28: LoanMultiplicityPolicy gates ═════════
$db->query("DELETE FROM prestiti WHERE libro_id = {$bookT}");
$active = $insertLoan($bookT, $copyT1, $userT1, $d(0), $d(7), 'in_corso', 1);

$settings->set('loans', 'allow_multiple_loans_same_book', '0');
$policy = new LoanMultiplicityPolicy($db, new SettingsRepository($db));
$check($policy->isEnabled() === false, '24 multiplicity opt-in defaults to disabled');
$check($policy->hasBlockingLoan($bookT, $userT1, true) === true,
    '25 strict mode blocks a second engagement on the same title for the same user');
$check($policy->hasBlockingLoan($bookT, $userT2, true) === false,
    '26 another user is never blocked by someone else\'s loan');

$settings->set('loans', 'allow_multiple_loans_same_book', '1');
$policyOn = new LoanMultiplicityPolicy($db, new SettingsRepository($db));
$check($policyOn->isEnabled() === true, '27 the setting flows through SettingsRepository when enabled');
$check($policyOn->hasBlockingLoan($bookT, $userT1, true) === false,
    '28 relaxed mode allows a second COPY-BINDING loan on the same title');
$check($policyOn->hasBlockingLoan($bookT, $userT1, false) === true,
    '29 a non-binding operation (bare request) stays strict even when enabled');
$settings->set('loans', 'allow_multiple_loans_same_book', (string) $originalMulti);

// ═════════ 30: application date is bound on this connection ═════════
$appDate = $db->query('SELECT @pinakes_application_date AS v')->fetch_assoc()['v'];
$check($appDate === $today, '30 @pinakes_application_date matches DateHelper::today() on a synchronized connection');

$cleanup();
$db->close();

echo PHP_EOL . "Passed: {$passed}   Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

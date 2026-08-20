<?php
declare(strict_types=1);

/**
 * Behavioral unit test — #366: a reservation must NOT switch to 'da_ritirare'
 * (ready for pickup) while the book's preceding loan is still out.
 *
 * Reproduces the reported sequence: a book is reserved right after its current
 * loan, the patron lets the loan go overdue, and the maintenance sweep
 * (activateScheduledLoans) used to promote the queued reservation to
 * 'da_ritirare' — and email "your reservation is ready for pickup" — on the
 * start date alone, even though the copy had never come back. Note the sweep
 * runs BEFORE updateOverdueLoans in runAll(), so the overdue predecessor can
 * still sit in 'in_corso' with a past data_scadenza at promotion time.
 *
 * Asserts the #366 guard:
 *   1. 1 copy, predecessor 'in_corso' past due (not yet flipped) + queued
 *      reservation due today -> stays 'prenotato', no pickup_deadline
 *      (the pickup email only fires after a successful promotion commit,
 *      so no promotion == no email).
 *   2. Same with predecessor flipped to 'in_ritardo' -> still 'prenotato'.
 *   3. Predecessor returned -> next sweep promotes to 'da_ritirare' with a
 *      pickup_deadline.
 *   4. Multi-copy: 2 copies, 1 out overdue -> the reservation on the free
 *      copy IS promoted (multi-copy behaviour preserved).
 *   5. Multi-copy, reservation pinned to the copy that is still out -> stays
 *      'prenotato' even though the book-level count has room; promotes after
 *      that copy is returned.
 *
 * Drives the real MaintenanceService::activateScheduledLoans() against the
 * live DB. It asserts only on rows it creates (titles ZZ_366_%, users zz366-%)
 * and cleans them up, but activateScheduledLoans() is a GLOBAL sweep with no
 * per-book filter — it mutates every matching row in the database. Run this
 * against an isolated/dedicated test DB (as CI does), never a shared one.
 *
 * Run:  php tests/pickup-ready-copy-free-366.unit.php
 */

use App\Support\MaintenanceService;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function prcfenv(string $path): array
{
    $env = [];
    foreach (preg_split('/\r?\n/', (string) @file_get_contents($path)) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $env[$k] = $v;
    }
    return $env;
}

$env    = prcfenv($root . '/.env');
// Read CI env vars first (the other tests in this PR do), so the test connects
// when CI supplies credentials via the environment rather than a written .env.
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbHost = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
try {
    $db = (is_string($socket) && $socket !== '' && file_exists($socket))
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli($dbHost, $dbUser, $dbPass, $dbName, (int) ($env['DB_PORT'] ?? 3306));
} catch (\Throwable $e) {
    // Under CI_STRICT_TESTS a missing DB must FAIL, not silently skip: a test
    // that exit(0)s on no-DB gives false confidence that it ran green in CI.
    if (getenv('CI_STRICT_TESTS') === '1') {
        fwrite(STDERR, "DB unreachable under CI_STRICT_TESTS: " . $e->getMessage() . "\n");
        exit(1);
    }
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}
$db->set_charset('utf8mb4');

$TESTNO = 0;
$failed = 0;
function check(bool $cond, string $desc): void
{
    global $TESTNO, $failed;
    $TESTNO++;
    printf("[%02d] %s: %s\n", $TESTNO, $cond ? 'PASS' : 'FAIL', $desc);
    if (!$cond) {
        $failed++;
    }
}

// Per-run token: cleanup and assertions only ever touch this run's rows.
$RUN = bin2hex(random_bytes(6));
$TITLE_PREFIX = "ZZ_366_{$RUN}_";
$EMAIL_SUFFIX = "@example.invalid";

$today = \App\Support\DateHelper::today();
$d = static fn (int $offsetDays): string => date('Y-m-d', strtotime($today . ($offsetDays >= 0 ? " +{$offsetDays} days" : ' ' . $offsetDays . ' days')));

/* -------------------------------- helpers -------------------------------- */

$mkUser = static function (string $tag) use ($db, $RUN, $EMAIL_SUFFIX): int {
    $tessera = 'Z366' . strtoupper($tag) . substr($RUN, 0, 10);
    $email = "zz366-{$tag}-{$RUN}{$EMAIL_SUFFIX}";
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata)
                          VALUES (?, 'Test', ?, ?, 'x', 'attivo', 'standard', 1)");
    $cognome = "ZZ366 {$tag}";
    $stmt->bind_param('sss', $tessera, $cognome, $email);
    $stmt->execute();
    $stmt->close();
    return (int) $db->insert_id;
};

$mkBook = static function (string $tag, int $copies) use ($db, $TITLE_PREFIX): array {
    $title = $TITLE_PREFIX . $tag;
    $stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at)
                          VALUES (?, ?, ?, NOW(), NOW())");
    $stmt->bind_param('sii', $title, $copies, $copies);
    $stmt->execute();
    $stmt->close();
    $bookId = (int) $db->insert_id;
    $copyIds = [];
    for ($i = 1; $i <= $copies; $i++) {
        $code = "ZZ366-{$bookId}-C{$i}";
        $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
        $stmt->bind_param('is', $bookId, $code);
        $stmt->execute();
        $stmt->close();
        $copyIds[] = (int) $db->insert_id;
    }
    return [$bookId, $copyIds];
};

$mkLoan = static function (int $bookId, ?int $copiaId, int $userId, string $stato, string $from, string $to, int $attivo = 1) use ($db): int {
    $stmt = $db->prepare("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
                          VALUES (?, ?, ?, ?, ?, ?, 'diretto', ?)");
    $stmt->bind_param('iiisssi', $bookId, $copiaId, $userId, $from, $to, $stato, $attivo);
    $stmt->execute();
    $stmt->close();
    return (int) $db->insert_id;
};

$setCopyState = static function (int $copiaId, string $stato) use ($db): void {
    $stmt = $db->prepare("UPDATE copie SET stato = ? WHERE id = ?");
    $stmt->bind_param('si', $stato, $copiaId);
    $stmt->execute();
    $stmt->close();
};

$loanRow = static function (int $loanId) use ($db): array {
    $stmt = $db->prepare("SELECT stato, pickup_deadline FROM prestiti WHERE id = ?");
    $stmt->bind_param('i', $loanId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
};

$returnLoan = static function (int $loanId, ?int $copiaId) use ($db, $setCopyState, $today): void {
    $stmt = $db->prepare("UPDATE prestiti SET stato = 'restituito', attivo = 0, data_restituzione = ? WHERE id = ?");
    $stmt->bind_param('si', $today, $loanId);
    $stmt->execute();
    $stmt->close();
    if ($copiaId !== null) {
        $setCopyState($copiaId, 'disponibile');
    }
};

/* -------------------------------- cleanup -------------------------------- */
$cleanup = static function () use ($db, $TITLE_PREFIX, $RUN, $EMAIL_SUFFIX): void {
    $like = $db->real_escape_string($TITLE_PREFIX) . '%';
    $db->query("DELETE p FROM prestiti p JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE '{$like}'");
    $db->query("DELETE c FROM copie c JOIN libri l ON l.id = c.libro_id WHERE l.titolo LIKE '{$like}'");
    $db->query("DELETE FROM libri WHERE titolo LIKE '{$like}'");
    $emailLike = $db->real_escape_string("zz366-%-{$RUN}{$EMAIL_SUFFIX}");
    $db->query("DELETE FROM utenti WHERE email LIKE '{$emailLike}'");
};
$cleanup();

try {
    $svc = new MaintenanceService($db);
    $borrower = $mkUser('a');
    $reserver = $mkUser('b');

    /* ---- Scenario 1: single copy, predecessor still out (issue #366) ---- */
    [$book1, $copies1] = $mkBook('one-copy', 1);
    $c1 = $copies1[0];
    // Predecessor loan, picked up 30 days ago, due 5 days ago, NOT returned.
    // Deliberately left in 'in_corso' (stale) — runAll() flips overdue loans
    // to 'in_ritardo' only AFTER activateScheduledLoans, so this is exactly
    // the state the sweep sees on a real run.
    $prev1 = $mkLoan($book1, $c1, $borrower, 'in_corso', $d(-30), $d(-5));
    $setCopyState($c1, 'prestato');
    // Queued reservation right after the loan: window started 2 days ago.
    // No copy pinned (queued behind the outstanding loan).
    $res1 = $mkLoan($book1, null, $reserver, 'prenotato', $d(-2), $d(20));

    $svc->activateScheduledLoans();
    $row = $loanRow($res1);
    check(($row['stato'] ?? '') === 'prenotato', "1 copy + predecessor 'in_corso' past due: reservation stays 'prenotato' (no promotion, no pickup email)");
    check(($row['pickup_deadline'] ?? null) === null, 'blocked reservation gets no pickup_deadline');

    // Flip the predecessor to 'in_ritardo' (what updateOverdueLoans does) and
    // sweep again: still no free copy, still no promotion.
    $db->query("UPDATE prestiti SET stato = 'in_ritardo' WHERE id = {$prev1}");
    $svc->activateScheduledLoans();
    $row = $loanRow($res1);
    check(($row['stato'] ?? '') === 'prenotato', "predecessor 'in_ritardo': reservation still 'prenotato'");

    // Return the predecessor: the very next sweep must promote.
    $returnLoan($prev1, $c1);
    $svc->activateScheduledLoans();
    $row = $loanRow($res1);
    check(($row['stato'] ?? '') === 'da_ritirare', "predecessor returned: reservation promotes to 'da_ritirare'");
    check(!empty($row['pickup_deadline']), 'promoted reservation gets a pickup_deadline');

    /* ---- Scenario 2: multi-copy, one out overdue, one free -> promote ---- */
    [$book2, $copies2] = $mkBook('two-copies', 2);
    [$c2a, $c2b] = $copies2;
    $prev2 = $mkLoan($book2, $c2a, $borrower, 'in_ritardo', $d(-30), $d(-5));
    $setCopyState($c2a, 'prestato');
    $res2 = $mkLoan($book2, $c2b, $reserver, 'prenotato', $d(-1), $d(20));
    $setCopyState($c2b, 'prenotato');

    $svc->activateScheduledLoans();
    $row = $loanRow($res2);
    check(($row['stato'] ?? '') === 'da_ritirare', '2 copies / 1 out overdue: reservation on the free copy IS promoted');

    /* ---- Scenario 3: multi-copy but pinned to the copy still out -------- */
    [$book3, $copies3] = $mkBook('pinned-copy', 2);
    [$c3a, $c3b] = $copies3;
    $prev3 = $mkLoan($book3, $c3a, $borrower, 'in_corso', $d(-30), $d(-5));
    $setCopyState($c3a, 'prestato');
    // Reservation pinned to the very copy that is still out (non-overlapping
    // window, so the DB trigger allows the row — the #366 blind spot).
    $res3 = $mkLoan($book3, $c3a, $reserver, 'prenotato', $d(-1), $d(20));

    $svc->activateScheduledLoans();
    $row = $loanRow($res3);
    check(($row['stato'] ?? '') === 'prenotato', "reservation pinned to the out copy stays 'prenotato' even with book-level room");

    $returnLoan($prev3, $c3a);
    $setCopyState($c3a, 'prenotato'); // copy back on the shelf, held for the reservation
    $svc->activateScheduledLoans();
    $row = $loanRow($res3);
    check(($row['stato'] ?? '') === 'da_ritirare', "pinned copy returned: reservation promotes to 'da_ritirare'");

} catch (\Throwable $e) {
    $cleanup();
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}

$cleanup();
$db->close();
echo "\n" . ($failed === 0 ? "ALL {$TESTNO} PASS\n" : "{$failed}/{$TESTNO} FAILED\n");
exit($failed > 0 ? 1 : 0);

<?php
declare(strict_types=1);

/**
 * Reusable behavioral suite for the Book Club "member lending" module —
 * App\Plugins\BookClub\LendingRepo (bookclub_member_loans).
 *
 * 25 lifecycle scenarios (31 assertions) drive the REAL LendingRepo against a live MySQL, covering the full
 * loan state machine (offered → requested → active → returned / cancelled), the
 * conditional-UPDATE guards ("first requester wins", state-machine gates), and the
 * "at most one OPEN loan per (club_book_id, lender_id)" invariant enforced by the
 * generated open_key column + UNIQUE index (the fix that must never regress).
 *
 * Self-contained + reusable: LendingRepo hardcodes its table names, so instead of
 * prefixed sandbox tables it CREATEs the three bookclub tables IF NOT EXISTS (a
 * no-op where the plugin is already active) and seeds a dedicated club / book /
 * users whose ids never collide with real data. Every row it inserts is deleted
 * at the end (FK-safe order), on success AND on failure — it leaves the DB as it
 * found it. Runs in CI via the tests/*.unit.php glob in ci-quality.yml.
 *
 * Run:  php tests/bookclub-lending.unit.php
 * Exit: 0 only if all checks pass; prints "ALL <n> PASS".
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/storage/plugins/book-club/src/LendingRepo.php';

use App\Plugins\BookClub\LendingRepo;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function blLoadEnv(string $path): array
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

$env = blLoadEnv($root . '/.env');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$pass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$name = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
try {
    if (is_string($socket) && $socket !== '' && file_exists($socket)) {
        $db = new mysqli(null, $user, $pass, $name, 0, $socket);
    } else {
        $db = new mysqli(
            getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'),
            $user,
            $pass,
            $name,
            (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306))
        );
    }
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}
$db->set_charset('utf8mb4');

// Unique sentinel suffix so re-runs and parallel CI shards never collide on the
// UNIQUE (email / codice_tessera / slug) columns.
$RUN = substr(md5(uniqid('', true)), 0, 10);

$tableExists = function (string $t) use ($db): bool {
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->bind_param('s', $t);
    $stmt->execute();
    $n = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $n > 0;
};

/* -------- created-row bookkeeping for cleanup --------
 * 'drop_tables' holds the bookclub tables THIS test created (those absent before
 * it ran). It drops only those: on a system where the plugin is already active
 * the tables pre-exist and must survive with the real data. Never drop a table
 * the test found in place — that would delete a live install's data, and leaving
 * a test-created minimal table behind would make a later plugin activation skip
 * its real CREATE. */
$created = ['loans' => [], 'book' => null, 'club' => null, 'libro' => null, 'users' => [], 'drop_tables' => []];

function blCleanup(mysqli $db, array $created): void
{
    // FK-safe order: member_loans → bookclub_books → bookclub_clubs → libri → utenti.
    foreach ($created['loans'] as $id) {
        $db->query('DELETE FROM bookclub_member_loans WHERE id = ' . (int) $id);
    }
    if ($created['book'] !== null) {
        $db->query('DELETE FROM bookclub_member_loans WHERE club_book_id = ' . (int) $created['book']);
        $db->query('DELETE FROM bookclub_books WHERE id = ' . (int) $created['book']);
    }
    if ($created['club'] !== null) {
        $db->query('DELETE FROM bookclub_clubs WHERE id = ' . (int) $created['club']);
    }
    if ($created['libro'] !== null) {
        $db->query('DELETE FROM libri WHERE id = ' . (int) $created['libro']);
    }
    foreach ($created['users'] as $id) {
        $db->query('DELETE FROM utenti WHERE id = ' . (int) $id);
    }
    // Drop only the tables this test created (child before parents).
    foreach (['bookclub_member_loans', 'bookclub_books', 'bookclub_clubs'] as $t) {
        if (in_array($t, $created['drop_tables'], true)) {
            $db->query('DROP TABLE IF EXISTS ' . $t);
        }
    }
}

set_exception_handler(static function (\Throwable $e) use ($db, &$created): void {
    try {
        blCleanup($db, $created);
    } catch (\Throwable $ignored) {
    }
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
});

$TESTNO = 0;
function check(bool $cond, string $desc): void
{
    global $TESTNO;
    if (!$cond) {
        throw new \RuntimeException("assertion failed: {$desc}");
    }
    $TESTNO++;
    printf("[%02d] PASS: %s\n", $TESTNO, $desc);
}

$statusOf = function (int $loanId) use ($db): ?string {
    $r = $db->query('SELECT status FROM bookclub_member_loans WHERE id = ' . $loanId)->fetch_row();
    return $r ? (string) $r[0] : null;
};
$openKeyOf = function (int $loanId) use ($db): ?string {
    $r = $db->query('SELECT open_key FROM bookclub_member_loans WHERE id = ' . $loanId)->fetch_row();
    return $r ? ($r[0] === null ? null : (string) $r[0]) : null;
};

/* -------- schema: create the bookclub tables if the plugin isn't active here -------- */
// Record which tables we create, so cleanup drops exactly those and never a real one.
foreach (['bookclub_clubs', 'bookclub_books', 'bookclub_member_loans'] as $t) {
    if (!$tableExists($t)) {
        $created['drop_tables'][] = $t;
    }
}
$db->query(
    "CREATE TABLE IF NOT EXISTS bookclub_clubs (
        id INT NOT NULL AUTO_INCREMENT,
        ics_token CHAR(32) NOT NULL,
        name VARCHAR(190) NOT NULL,
        slug VARCHAR(120) NOT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$db->query(
    "CREATE TABLE IF NOT EXISTS bookclub_books (
        id INT NOT NULL AUTO_INCREMENT,
        club_id INT NOT NULL,
        libro_id INT NOT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$db->query(
    "CREATE TABLE IF NOT EXISTS bookclub_member_loans (
        id INT NOT NULL AUTO_INCREMENT,
        club_id INT NOT NULL,
        club_book_id INT NOT NULL,
        lender_id INT NOT NULL,
        borrower_id INT NULL,
        status ENUM('offered','requested','active','returned','cancelled') NOT NULL DEFAULT 'offered',
        notes VARCHAR(500) NULL,
        due_on DATE NULL,
        offered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        lent_at DATETIME NULL,
        returned_at DATETIME NULL,
        open_key VARCHAR(32) GENERATED ALWAYS AS (CASE WHEN status IN ('offered','requested','active') THEN CONCAT(club_book_id, ':', lender_id) ELSE NULL END) VIRTUAL,
        PRIMARY KEY (id),
        KEY idx_book (club_book_id),
        UNIQUE KEY uq_bcmloan_open (open_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

/* -------- seed dedicated fixtures -------- */
$db->query("INSERT INTO bookclub_clubs (ics_token, name, slug) VALUES ('" . $RUN . str_repeat('0', 32 - strlen($RUN)) . "', 'BL Test Club', 'bl-test-$RUN')");
$CLUB = (int) $db->insert_id;
$created['club'] = $CLUB;

$db->query("INSERT INTO libri (titolo) VALUES ('BL Test Book $RUN')");
$LIBRO = (int) $db->insert_id;
$created['libro'] = $LIBRO;

$db->query("INSERT INTO bookclub_books (club_id, libro_id) VALUES ($CLUB, $LIBRO)");
$BOOK = (int) $db->insert_id;
$created['book'] = $BOOK;

$mkUser = function (string $tag) use ($db, $RUN, &$created): int {
    $db->query(
        "INSERT INTO utenti (codice_tessera, nome, cognome, email, password)
         VALUES ('BL$tag$RUN', 'BL$tag', 'Test', 'bl_" . $tag . '_' . $RUN . "@example.test', 'x')"
    );
    $id = (int) $db->insert_id;
    $created['users'][] = $id;
    return $id;
};
$U1 = $mkUser('L');  // lender
$U2 = $mkUser('B');  // borrower
$U3 = $mkUser('C');  // second borrower

$repo = new LendingRepo($db);

/* ============================ 25 checks ============================ */

// 1. createOffer inserts an 'offered' row and returns its id.
$offerId = $repo->createOffer($CLUB, $BOOK, $U1, 'first offer');
check(is_int($offerId) && $offerId > 0, "createOffer() returns a new loan id");

// 2. loanById reflects the offered state + lender.
$loan = $repo->loanById($offerId);
check($loan !== null && $loan['status'] === 'offered' && (int) $loan['lender_id'] === $U1, "loanById() shows the offered row with correct lender");

// 3. the generated open_key materializes the pair while open.
check($openKeyOf($offerId) === "$BOOK:$U1", "open_key = 'club_book_id:lender_id' on the open row");

// 4. hasOpenOffer true for this (book, lender).
check($repo->hasOpenOffer($BOOK, $U1) === true, "hasOpenOffer() true for the offered pair");

// 5. hasOpenOffer false for a different lender.
check($repo->hasOpenOffer($BOOK, $U2) === false, "hasOpenOffer() false for a different lender");

// 6. countOpenOffers counts the offered row.
check($repo->countOpenOffers($CLUB) === 1, "countOpenOffers() == 1 after one offer");

// 7. openOffers lists it.
$open = $repo->openOffers($CLUB);
check(count(array_filter($open, fn ($o) => (int) $o['id'] === $offerId)) === 1, "openOffers() includes the offered row");

// 8. one-open-offer guard: a second offer for the same pair returns null.
check($repo->createOffer($CLUB, $BOOK, $U1, 'dup') === null, "createOffer() twice for the same pair returns null (one-open guard)");

// 9. only the single open row exists for the pair.
$openCount = (int) $db->query("SELECT COUNT(*) FROM bookclub_member_loans WHERE club_book_id=$BOOK AND lender_id=$U1 AND status IN ('offered','requested','active')")->fetch_row()[0];
check($openCount === 1, "still exactly one OPEN row for the pair after the duplicate attempt");

// 10. the UNIQUE index is the hard backstop: a raw duplicate open insert is rejected (1062).
$rawDup = false;
try {
    $db->query("INSERT INTO bookclub_member_loans (club_id, club_book_id, lender_id, status) VALUES ($CLUB, $BOOK, $U1, 'offered')");
} catch (\mysqli_sql_exception $e) {
    $rawDup = ($e->getCode() === 1062);
}
check($rawDup, "UNIQUE(open_key) rejects a raw duplicate OPEN insert (error 1062)");

// 11. requestLoan: 'offered' → 'requested', first requester wins.
check($repo->requestLoan($offerId, $U2) === true, "requestLoan() moves offered → requested");
check($statusOf($offerId) === 'requested', "status is 'requested' after requestLoan");

// 12. a second requester loses (row no longer offered / borrower set).
check($repo->requestLoan($offerId, $U3) === false, "second requestLoan() fails (first requester wins)");

// 13. the loan still carries the pair key while requested (open state).
check($openKeyOf($offerId) === "$BOOK:$U1", "open_key still set while 'requested' (still open)");

// 14. it shows up in the borrower's list.
$borrowings = $repo->myBorrowings($CLUB, $U2);
check(count(array_filter($borrowings, fn ($l) => (int) $l['id'] === $offerId)) === 1, "myBorrowings() shows the requested loan for the borrower");

// 15. declineRequest: 'requested' → 'offered', borrower cleared.
check($repo->declineRequest($offerId) === true, "declineRequest() moves requested → offered");
$afterDecline = $repo->loanById($offerId);
check($afterDecline['status'] === 'offered' && $afterDecline['borrower_id'] === null, "borrower cleared after decline");

// 16. now the second borrower can take it.
check($repo->requestLoan($offerId, $U3) === true, "requestLoan() succeeds for U3 after decline");

// 17. handOver: 'requested' → 'active', lent_at + due_on set.
check($repo->handOver($offerId, '2099-12-31') === true, "handOver() moves requested → active");
$active = $repo->loanById($offerId);
check($active['status'] === 'active' && $active['lent_at'] !== null && substr((string) $active['due_on'], 0, 10) === '2099-12-31', "active loan has lent_at and due_on set");

// 18. handOver again fails (no longer requested).
check($repo->handOver($offerId, null) === false, "handOver() fails when not 'requested'");

// 19. active loan appears for both lender and borrower.
$lenderActive = $repo->myActiveLoans($CLUB, $U1);
$borrowerActive = $repo->myActiveLoans($CLUB, $U3);
check(
    count(array_filter($lenderActive, fn ($l) => (int) $l['id'] === $offerId)) === 1
    && count(array_filter($borrowerActive, fn ($l) => (int) $l['id'] === $offerId)) === 1,
    "myActiveLoans() lists the active loan for lender and borrower"
);

// 20. markReturned: 'active' → 'returned'.
check($repo->markReturned($offerId) === true, "markReturned() moves active → returned");
check($statusOf($offerId) === 'returned', "status is 'returned' after markReturned");

// 21. markReturned again fails (not active).
check($repo->markReturned($offerId) === false, "markReturned() fails when not 'active'");

// 22. closed loan drops out of the open_key index (NULL) — frees the pair.
check($openKeyOf($offerId) === null, "open_key is NULL once the loan is 'returned' (pair freed)");

// 23. because the pair is free, a fresh offer for the same (book, lender) succeeds.
$offer2 = $repo->createOffer($CLUB, $BOOK, $U1, 'after return');
check(is_int($offer2) && $offer2 > 0 && $offer2 !== $offerId, "createOffer() succeeds again after the previous loan closed");
$created['loans'][] = $offer2;

// 24. cancel: an offered/requested row → 'cancelled'; then cancel again fails.
check($repo->cancel($offer2) === true, "cancel() moves offered → cancelled");
check($statusOf($offer2) === 'cancelled' && $openKeyOf($offer2) === null, "cancelled row has NULL open_key");
check($repo->cancel($offer2) === false, "cancel() fails on an already-cancelled row");

// 25. history/consistency: myOffers lists both rows (closed history), countOpenOffers is back to 0.
$mine = $repo->myOffers($CLUB, $U1);
check(
    count(array_filter($mine, fn ($l) => in_array((int) $l['id'], [$offerId, $offer2], true))) === 2
    && $repo->countOpenOffers($CLUB) === 0,
    "myOffers() returns the full history and countOpenOffers() is 0 with no open rows"
);

blCleanup($db, $created);
printf("\nALL %d PASS\n", $TESTNO);
exit(0);

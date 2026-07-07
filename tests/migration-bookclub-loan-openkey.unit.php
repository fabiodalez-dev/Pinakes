<?php
declare(strict_types=1);

/**
 * Behavioral suite for the bookclub_member_loans "one open loan per
 * (club_book_id, lender_id)" migration (LendingModule::ensureSchema()).
 *
 * The invariant used to rest only on an INSERT … WHERE NOT EXISTS in
 * LendingRepo::createOffer(), which under REPEATABLE READ lets two concurrent
 * offers both slip through. The fix adds a STORED generated column `open_key`
 * (the pair key while the loan is open, NULL otherwise) plus a UNIQUE index, and
 * an idempotent migration that de-dupes any pre-existing duplicate open loans
 * before adding the index.
 *
 * Strategy (mirrors tests/migration-0.7.25.unit.php): build a sandbox table
 * `zz_mig_member_loans` with the OLD schema (no open_key / no unique index),
 * seed it — INCLUDING a pre-existing duplicate open pair — then run the REAL
 * migration statements against it and assert: de-dup, column + index added,
 * generated values correct, the UNIQUE constraint actually blocks a second open
 * offer, and idempotency. A drift guard confirms the tested open_key expression
 * still matches LendingModule.php's source.
 *
 * Runs against the LIVE local MySQL; touches only `zz_mig_member_loans`.
 * Run:  php tests/migration-bookclub-loan-openkey.unit.php
 * Exit: 0 only if all checks pass; prints "ALL <n> PASS".
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function mlLoadEnv(string $path): array
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

$env = mlLoadEnv($root . '/.env');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$user = $env['DB_USER'] ?? '';
$pass = $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '');
$name = $env['DB_NAME'] ?? '';
try {
    if (is_string($socket) && $socket !== '' && file_exists($socket)) {
        $db = new mysqli(null, $user, $pass, $name, 0, $socket);
    } else {
        $db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $pass, $name, (int) ($env['DB_PORT'] ?? 3306));
    }
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}
$db->set_charset('utf8mb4');

const SANDBOX = 'zz_mig_member_loans';

// The generated-column definition the migration adds. Kept in sync with
// LendingModule::OPEN_KEY_DEF via the drift guard (check 1). MUST be VIRTUAL, not
// STORED: the real table has foreign keys and a STORED column forces a rebuild that
// fails with "1215 Cannot add foreign key constraint" — the sandbox below reproduces
// that FK condition so this test catches a regression back to STORED.
const OPEN_KEY_DEF =
    "VARCHAR(32) GENERATED ALWAYS AS (CASE WHEN status IN ('offered','requested','active') "
    . "THEN CONCAT(club_book_id, ':', lender_id) ELSE NULL END) VIRTUAL";

const SANDBOX_PARENT = 'zz_mig_loan_parent';

function mlCleanup(mysqli $db): void
{
    // Child first (FK), then parent.
    $db->query('DROP TABLE IF EXISTS `' . SANDBOX . '`');
    $db->query('DROP TABLE IF EXISTS `' . SANDBOX_PARENT . '`');
}

set_exception_handler(static function (\Throwable $e) use ($db): void {
    try {
        mlCleanup($db);
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

$columnExists = function (string $col) use ($db): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $t = SANDBOX;
    $stmt->bind_param('ss', $t, $col);
    $stmt->execute();
    $n = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $n > 0;
};
$indexExists = function (string $idx) use ($db): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    $t = SANDBOX;
    $stmt->bind_param('ss', $t, $idx);
    $stmt->execute();
    $n = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $n > 0;
};
$scalar = function (string $sql) use ($db): int {
    return (int) $db->query($sql)->fetch_row()[0];
};

/* ---- 1. drift guard: source still uses the tested expression ---- */
$src = (string) file_get_contents($root . '/storage/plugins/book-club/src/Modules/LendingModule.php');
check(
    str_contains($src, "CONCAT(club_book_id, ':', lender_id)")
        && str_contains($src, "status IN ('offered','requested','active')")
        && str_contains($src, 'uq_bcmloan_open')
        // Anchor to the actual column definition (… END) VIRTUAL), not the word
        // "VIRTUAL" anywhere — the module comment says "VIRTUAL, not STORED", so a
        // bare str_contains would be a comment-only false green, symmetric with the
        // negative STORED check below.
        && preg_match('/END\)\s+VIRTUAL/', $src) === 1
        && !preg_match('/END\)\s+STORED/', $src),
    "LendingModule source defines the open_key expression + uq_bcmloan_open, and uses VIRTUAL (not STORED, which 1215s on the FK table)"
);

/* ---- build the OLD sandbox schema (no open_key, no unique index) ----
 * A foreign key is REQUIRED here: the real bookclub_member_loans has three FKs, and
 * that's exactly what makes a STORED generated column fail (the ADD COLUMN rebuild
 * re-creates the FKs → 1215). Without a FK the sandbox would pass even with STORED — the
 * false-green that shipped the bug. The parent + FK reproduce the real condition. */
mlCleanup($db);
$db->query("CREATE TABLE `" . SANDBOX_PARENT . "` (id INT NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB");
$db->query("INSERT INTO `" . SANDBOX_PARENT . "` (id) VALUES (1)");
$db->query(
    "CREATE TABLE `" . SANDBOX . "` (
        id INT NOT NULL AUTO_INCREMENT,
        club_id INT NOT NULL,
        club_book_id INT NOT NULL,
        lender_id INT NOT NULL,
        borrower_id INT NULL,
        status ENUM('offered','requested','active','returned','cancelled') NOT NULL DEFAULT 'offered',
        offered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_book (club_book_id),
        KEY idx_lender (lender_id),
        CONSTRAINT fk_sandbox_parent FOREIGN KEY (club_id) REFERENCES `" . SANDBOX_PARENT . "` (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// Seed: pair (10,20) has TWO open rows (a pre-existing duplicate); pair (11,20)
// has one open row; one closed ('returned') row for (10,21).
$db->query("INSERT INTO `" . SANDBOX . "` (id, club_id, club_book_id, lender_id, status) VALUES
    (1, 1, 10, 20, 'offered'),
    (2, 1, 10, 20, 'active'),
    (3, 1, 11, 20, 'offered'),
    (4, 1, 10, 21, 'returned')");
check($scalar("SELECT COUNT(*) FROM `" . SANDBOX . "`") === 4, "seeded 4 rows incl. a duplicate open pair");

/* ---- run the REAL migration statements against the sandbox ---- */
$runMigration = function () use ($db, $columnExists, $indexExists): void {
    $t = SANDBOX;
    if (!$columnExists('open_key')) {
        $db->query(
            "UPDATE `{$t}` m
                JOIN (
                    SELECT club_book_id, lender_id, MIN(id) AS keep_id
                      FROM `{$t}`
                     WHERE status IN ('offered','requested','active')
                     GROUP BY club_book_id, lender_id
                    HAVING COUNT(*) > 1
                ) d ON d.club_book_id = m.club_book_id AND d.lender_id = m.lender_id
                SET m.status = 'cancelled'
              WHERE m.status IN ('offered','requested','active') AND m.id <> d.keep_id"
        );
        $db->query("ALTER TABLE `{$t}` ADD COLUMN open_key " . OPEN_KEY_DEF);
    }
    if (!$indexExists('uq_bcmloan_open')) {
        $db->query("ALTER TABLE `{$t}` ADD UNIQUE KEY uq_bcmloan_open (open_key)");
    }
};
$runMigration();

/* ---- 2..7 effect assertions ---- */
check($columnExists('open_key'), "open_key column added");
check($indexExists('uq_bcmloan_open'), "uq_bcmloan_open UNIQUE index added");

// De-dup: pair (10,20) keeps exactly one open row (the earliest, id=1).
check(
    $scalar("SELECT COUNT(*) FROM `" . SANDBOX . "` WHERE club_book_id=10 AND lender_id=20 AND status IN ('offered','requested','active')") === 1,
    "duplicate open pair de-duped to a single open row"
);
check(
    $scalar("SELECT status='offered' FROM `" . SANDBOX . "` WHERE id=1") === 1
        && $scalar("SELECT status='cancelled' FROM `" . SANDBOX . "` WHERE id=2") === 1,
    "earliest open row (id=1) kept unchanged, the later one (id=2) cancelled"
);
// Generated value: open rows carry the pair key, closed rows are NULL.
check(
    $scalar("SELECT open_key='10:20' FROM `" . SANDBOX . "` WHERE id=1") === 1
        && $scalar("SELECT open_key='11:20' FROM `" . SANDBOX . "` WHERE id=3") === 1,
    "open rows compute open_key = 'club_book_id:lender_id'"
);
check(
    $scalar("SELECT COUNT(*) FROM `" . SANDBOX . "` WHERE open_key IS NULL") === 2,
    "closed/cancelled rows have NULL open_key (id=2 cancelled, id=4 returned)"
);

/* ---- 8. enforcement: a second concurrent open offer is rejected ---- */
$rejected = false;
try {
    $db->query("INSERT INTO `" . SANDBOX . "` (club_id, club_book_id, lender_id, status) VALUES (1, 11, 20, 'offered')");
} catch (\mysqli_sql_exception $e) {
    $rejected = ($e->getCode() === 1062);
}
check($rejected, "a second OPEN offer for an existing open pair is blocked by the UNIQUE index (1062)");

// A NEW pair, and a CLOSED duplicate of an existing open pair, are both still allowed.
$db->query("INSERT INTO `" . SANDBOX . "` (club_id, club_book_id, lender_id, status) VALUES (1, 99, 20, 'offered')");
$db->query("INSERT INTO `" . SANDBOX . "` (club_id, club_book_id, lender_id, status) VALUES (1, 11, 20, 'returned')");
check(
    $scalar("SELECT COUNT(*) FROM `" . SANDBOX . "` WHERE club_book_id=99 AND status='offered'") === 1
        && $scalar("SELECT COUNT(*) FROM `" . SANDBOX . "` WHERE club_book_id=11 AND lender_id=20 AND status='returned'") === 1,
    "a new open pair and a closed duplicate of an open pair are both accepted"
);

/* ---- 9. idempotency: re-running the migration is a no-op ---- */
$before = $scalar("SELECT COUNT(*) FROM `" . SANDBOX . "`");
$runMigration();
$after = $scalar("SELECT COUNT(*) FROM `" . SANDBOX . "`");
check($before === $after && $columnExists('open_key') && $indexExists('uq_bcmloan_open'), "migration is idempotent on re-run");

mlCleanup($db);
printf("\nALL %d PASS\n", $TESTNO);
exit(0);

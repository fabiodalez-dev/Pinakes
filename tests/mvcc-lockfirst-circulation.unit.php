<?php
declare(strict_types=1);

/**
 * MVCC lock-first concurrency test — P1: stale REPEATABLE READ snapshot in
 * circulation write transactions.
 *
 * Root cause: several write paths opened the transaction, resolved the loan's
 * book id with a PLAIN consistent read (which fixes the InnoDB read view
 * PRE-lock) and only then acquired `SELECT ... FROM libri ... FOR UPDATE`.
 * Once the lock was finally granted — exactly when a competitor existed —
 * every later plain SELECT still read the pre-lock snapshot and was blind to
 * the competitor's just-committed changes. Consequences reproduced here with
 * TWO real mysqli connections:
 *
 *   RACE 1 (cancelled reservation resurrected + wrong email):
 *     connection A freezes its read view, connection B cancels the queued
 *     reservation and commits, A then takes the book lock and runs the REAL
 *     ReservationManager::processBookAvailability() inside its transaction.
 *     Pre-fix: the plain queue read saw the stale 'attiva' row, the unguarded
 *     completata UPDATE resurrected the cancelled reservation, a loan was
 *     created and the "book available" notification queued. Post-fix: the
 *     queue read is FOR UPDATE (current read) and the completata UPDATE is
 *     guarded by AND stato='attiva' + affected_rows — promotion is skipped.
 *
 *   RACE 2 (same copia_id double-committed):
 *     A freezes its view, B commits a competing pendente loan holding the
 *     book's only copy, A takes the book lock and promotes its reservation.
 *     Pre-fix: the copy-overlap re-check in createLoanFromReservation() was a
 *     plain SELECT against the stale snapshot — it missed B's loan and
 *     committed a second loan on the same copy. Post-fix: the re-check is
 *     FOR UPDATE and aborts, the claim is reverted, the copy keeps ONE loan.
 *
 *   SOURCE INVARIANTS: every one of the 8 affected transactions must resolve
 *     the book id BEFORE begin_transaction() so the FIRST in-transaction
 *     statement is the locking `libri ... FOR UPDATE` read (read view created
 *     post-lock); ReservationManager must keep the locking queue read, the
 *     guarded claim and the locking overlap re-check.
 *
 * Touches only data it creates (titles ZZ_MVCC_%, users zzmvcc-%), cleans up
 * after itself.
 *
 * Run:  php tests/mvcc-lockfirst-circulation.unit.php
 */

use App\Controllers\ReservationManager;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function mvccenv(string $path): array
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

$env    = mvccenv($root . '/.env');
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') !== false && getenv('E2E_DB_PASS') !== '' ? getenv('E2E_DB_PASS') : ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');

$connect = static function () use ($dbName, $dbUser, $dbPass, $socket, $env): mysqli {
    $db = (is_string($socket) && $socket !== '' && file_exists($socket))
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $dbUser, $dbPass, $dbName, (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
    return $db;
};

try {
    $connA = $connect(); // transactional actor (the vulnerable write path)
    $connB = $connect(); // concurrent competitor (autocommit)
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}

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
$TITLE_PREFIX = "ZZ_MVCC_{$RUN}_";
$EMAIL_SUFFIX = "@example.invalid";

$today = \App\Support\DateHelper::today();
$d = static fn (int $offsetDays): string => date('Y-m-d', strtotime($today . ($offsetDays >= 0 ? " +{$offsetDays} days" : ' ' . $offsetDays . ' days')));

/* -------------------------------- helpers -------------------------------- */

$mkUser = static function (string $tag) use ($connB, $RUN, $EMAIL_SUFFIX): int {
    $tessera = 'ZMV' . strtoupper($tag) . substr($RUN, 0, 9);
    $email = "zzmvcc-{$tag}-{$RUN}{$EMAIL_SUFFIX}";
    $stmt = $connB->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata)
                             VALUES (?, 'Test', ?, ?, 'x', 'attivo', 'standard', 1)");
    $cognome = "ZZMVCC {$tag}";
    $stmt->bind_param('sss', $tessera, $cognome, $email);
    $stmt->execute();
    $stmt->close();
    return (int) $connB->insert_id;
};

$mkBook = static function (string $tag, int $copies) use ($connB, $TITLE_PREFIX): array {
    $title = $TITLE_PREFIX . $tag;
    $stmt = $connB->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at)
                             VALUES (?, ?, ?, NOW(), NOW())");
    $stmt->bind_param('sii', $title, $copies, $copies);
    $stmt->execute();
    $stmt->close();
    $bookId = (int) $connB->insert_id;
    $copyIds = [];
    for ($i = 1; $i <= $copies; $i++) {
        $code = "ZZMVCC-{$bookId}-C{$i}";
        $stmt = $connB->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
        $stmt->bind_param('is', $bookId, $code);
        $stmt->execute();
        $stmt->close();
        $copyIds[] = (int) $connB->insert_id;
    }
    return [$bookId, $copyIds];
};

$mkReservation = static function (int $bookId, int $userId, string $from, string $to, int $queuePos = 1) use ($connB): int {
    $stmt = $connB->prepare("INSERT INTO prenotazioni (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta, queue_position, stato)
                             VALUES (?, ?, ?, ?, ?, 'attiva')");
    $stmt->bind_param('iissi', $bookId, $userId, $from, $to, $queuePos);
    $stmt->execute();
    $stmt->close();
    return (int) $connB->insert_id;
};

$reservationState = static function (int $id) use ($connB): string {
    $stmt = $connB->prepare("SELECT stato FROM prenotazioni WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (string) ($row['stato'] ?? '');
};

$loanCountOnCopy = static function (int $copiaId) use ($connB): int {
    $stmt = $connB->prepare("SELECT COUNT(*) AS n FROM prestiti WHERE copia_id = ? AND stato NOT IN ('restituito','annullato')");
    $stmt->bind_param('i', $copiaId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['n'] ?? 0);
};

$promoLoanCount = static function (int $bookId, int $userId) use ($connB): int {
    $stmt = $connB->prepare("SELECT COUNT(*) AS n FROM prestiti WHERE libro_id = ? AND utente_id = ? AND origine = 'prenotazione'");
    $stmt->bind_param('ii', $bookId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['n'] ?? 0);
};

$deferredNotifications = static function (ReservationManager $rm): array {
    $prop = new \ReflectionProperty(ReservationManager::class, 'deferredReservationNotifications');
    $prop->setAccessible(true);
    return (array) $prop->getValue($rm);
};

/* -------------------------------- cleanup -------------------------------- */
$cleanup = static function () use ($connB, $TITLE_PREFIX, $RUN, $EMAIL_SUFFIX): void {
    $like = $connB->real_escape_string($TITLE_PREFIX) . '%';
    $connB->query("DELETE p FROM prestiti p JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE '{$like}'");
    $connB->query("DELETE r FROM prenotazioni r JOIN libri l ON l.id = r.libro_id WHERE l.titolo LIKE '{$like}'");
    $connB->query("DELETE c FROM copie c JOIN libri l ON l.id = c.libro_id WHERE l.titolo LIKE '{$like}'");
    $connB->query("DELETE FROM libri WHERE titolo LIKE '{$like}'");
    $emailLike = $connB->real_escape_string("zzmvcc-%-{$RUN}{$EMAIL_SUFFIX}");
    $connB->query("DELETE FROM utenti WHERE email LIKE '{$emailLike}'");
};
$cleanup();

try {
    $reserver = $mkUser('a');
    $competitor = $mkUser('b');

    /* =====================================================================
     * RACE 1 — cancelled reservation must NOT be resurrected nor emailed.
     * ===================================================================== */
    [$book1, $copies1] = $mkBook('race1', 1);
    $res1 = $mkReservation($book1, $reserver, $d(-1), $d(14));

    // Connection A: mirror the PRE-FIX transaction shape of the vulnerable
    // callers (close/cancelLoan/...): open the txn and freeze the read view
    // with a plain consistent read BEFORE acquiring the book lock.
    $connA->begin_transaction();
    $freeze = $connA->prepare("SELECT libro_id FROM prestiti WHERE id = ?");
    $zero = 0;
    $freeze->bind_param('i', $zero);
    $freeze->execute();
    $freeze->get_result();
    $freeze->close();

    // Connection B (the competitor): the user cancels the reservation — and
    // COMMITS — while A is on its way to the book lock.
    $connB->query("UPDATE prenotazioni SET stato = 'annullata' WHERE id = {$res1}");

    // Connection A: acquire the book lock (first lock, as post-fix code does),
    // then run the REAL promotion inside A's transaction.
    $lock = $connA->prepare("SELECT id FROM libri WHERE id = ? FOR UPDATE");
    $lock->bind_param('i', $book1);
    $lock->execute();
    $lock->get_result();
    $lock->close();

    // Precondition of the race: A's PLAIN snapshot is genuinely stale — it
    // still sees the cancelled reservation as 'attiva'. (True pre- and
    // post-fix: this is InnoDB REPEATABLE READ, not the code under test.)
    $staleStmt = $connA->prepare("SELECT stato FROM prenotazioni WHERE id = ?");
    $staleStmt->bind_param('i', $res1);
    $staleStmt->execute();
    $staleRow = $staleStmt->get_result()->fetch_assoc();
    $staleStmt->close();
    check(($staleRow['stato'] ?? '') === 'attiva', "race 1 precondition: A's plain snapshot still sees the cancelled reservation as 'attiva' (stale MVCC view reproduced)");

    $rm1 = new ReservationManager($connA);
    $rm1->setExternalTransaction(true);
    $promoted = $rm1->processBookAvailability($book1);
    $connA->commit();

    check($promoted === false, 'race 1: promotion reports false for the cancelled reservation');
    check($reservationState($res1) === 'annullata', "race 1: reservation stays 'annullata' (not resurrected to 'completata')");
    check($promoLoanCount($book1, $reserver) === 0, 'race 1: no loan created from the cancelled reservation');
    check(count($deferredNotifications($rm1)) === 0, 'race 1: no "book available" notification queued for the cancelled reservation');

    /* =====================================================================
     * RACE 2 — the same copia_id must NOT be committed to two loans.
     * ===================================================================== */
    [$book2, $copies2] = $mkBook('race2', 1);
    $c2 = $copies2[0];
    $res2 = $mkReservation($book2, $reserver, $d(-1), $d(14));

    // Connection A: freeze the read view pre-lock (pre-fix caller shape).
    $connA->begin_transaction();
    $freeze = $connA->prepare("SELECT libro_id FROM prestiti WHERE id = ?");
    $freeze->bind_param('i', $zero);
    $freeze->execute();
    $freeze->get_result();
    $freeze->close();

    // Connection B: a competing pendente loan claims the only copy — committed.
    $from2 = $d(0);
    $to2 = $d(10);
    $stmt = $connB->prepare("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
                             VALUES (?, ?, ?, ?, ?, 'pendente', 'prenotazione', 0)");
    $stmt->bind_param('iiiss', $book2, $c2, $competitor, $from2, $to2);
    $stmt->execute();
    $stmt->close();

    // Connection A: book lock, then the real promotion.
    $lock = $connA->prepare("SELECT id FROM libri WHERE id = ? FOR UPDATE");
    $lock->bind_param('i', $book2);
    $lock->execute();
    $lock->get_result();
    $lock->close();

    $rm2 = new ReservationManager($connA);
    $rm2->setExternalTransaction(true);
    $promoted2 = $rm2->processBookAvailability($book2);
    $connA->commit();

    check($promoted2 === false, 'race 2: promotion aborts when the copy was just committed to a competitor');
    check($loanCountOnCopy($c2) === 1, 'race 2: exactly ONE open loan holds the copy (no copia_id double-commit)');
    check($reservationState($res2) === 'attiva', "race 2: the unpromoted reservation is released back to 'attiva' (claim reverted)");
    check(count($deferredNotifications($rm2)) === 0, 'race 2: no notification queued for the aborted promotion');

    /* =====================================================================
     * SOURCE INVARIANTS — lock-first ordering in all 8 transactions +
     * ReservationManager guards.
     * ===================================================================== */
    $readSource = static function (string $path) use ($root): string {
        $source = file_get_contents($root . $path);
        if ($source === false) {
            throw new \RuntimeException("unreadable source: {$path}");
        }
        return $source;
    };
    $extractMethod = static function (string $source, string $signature): string {
        $start = strpos($source, $signature);
        if ($start === false) {
            return '';
        }
        $remaining = substr($source, $start + strlen($signature));
        if (!preg_match('/\n    (?:public|protected|private) (?:const |function )/', $remaining, $m, PREG_OFFSET_CAPTURE)) {
            return substr($source, $start);
        }
        return substr($source, $start, strlen($signature) + $m[0][1]);
    };

    $lockFirst = [
        ['/app/Controllers/LoanApprovalController.php', 'function approveLoan(', 'SELECT libro_id FROM prestiti'],
        ['/app/Controllers/LoanApprovalController.php', 'function rejectLoan(', 'SELECT libro_id FROM prestiti'],
        ['/app/Controllers/LoanApprovalController.php', 'function returnLoan(', 'SELECT libro_id FROM prestiti'],
        ['/app/Controllers/LoanApprovalController.php', 'function cancelPickup(', 'SELECT libro_id FROM prestiti'],
        ['/app/Controllers/PrestitiController.php', 'function processReturn(', 'SELECT libro_id FROM prestiti'],
        ['/app/Models/LoanRepository.php', 'function close(', 'SELECT libro_id FROM prestiti'],
        ['/app/Controllers/UserActionsController.php', 'function cancelLoan(', 'SELECT libro_id'],
        ['/app/Controllers/UserActionsController.php', 'function cancelReservation(', 'SELECT libro_id FROM prenotazioni'],
    ];
    foreach ($lockFirst as [$file, $sig, $lookupSql]) {
        $body = $extractMethod($readSource($file), $sig);
        $label = basename($file) . ' ' . trim($sig, '(');
        // Match the actual CALL (`->begin_transaction(`), not comments that
        // merely mention begin_transaction() while explaining the ordering.
        $beginPos = strpos($body, '->begin_transaction(');
        $lookupPos = strpos($body, $lookupSql);
        $ok = $body !== '' && $beginPos !== false && $lookupPos !== false && $lookupPos < $beginPos;
        check($ok, "lock-first: {$label} resolves the book id BEFORE begin_transaction()");

        // The FIRST prepared statement inside the transaction must be the
        // locking read of the book row (read view created post-lock).
        $afterBegin = $beginPos !== false ? substr($body, $beginPos) : '';
        $firstSql = '';
        if (preg_match('/prepare\(\s*(["\'])(.*?)\1/s', $afterBegin, $m)) {
            $firstSql = $m[2];
        }
        $ok2 = str_contains($firstSql, 'FROM libri') && str_contains($firstSql, 'FOR UPDATE');
        check($ok2, "lock-first: {$label} first in-txn statement is `libri ... FOR UPDATE`");
    }

    $rmSource = $readSource('/app/Controllers/ReservationManager.php');
    $pba = $extractMethod($rmSource, 'function processBookAvailability(');
    check((bool) preg_match('/FROM prenotazioni r\s+JOIN utenti u ON r\.utente_id = u\.id.*?LIMIT 1\s+FOR UPDATE/s', $pba), 'ReservationManager: queue read is a locking read (FOR UPDATE)');
    check(str_contains($pba, "SET stato = 'completata' WHERE id = ? AND stato = 'attiva'"), "ReservationManager: completata claim is guarded by AND stato = 'attiva'");
    check((bool) preg_match('/\$claimed\s*!==?\s*1|affected_rows/s', $pba) && str_contains($pba, 'affected_rows'), 'ReservationManager: completata claim checks affected_rows');
    // The claim must happen BEFORE the loan is created (match the actual
    // call, not comments that mention the method name).
    $claimPos = strpos($pba, "SET stato = 'completata' WHERE id = ? AND stato = 'attiva'");
    $createPos = strpos($pba, '$this->createLoanFromReservation(');
    check($claimPos !== false && $createPos !== false && $claimPos < $createPos, 'ReservationManager: reservation is claimed before the loan is created');

    $clfr = $extractMethod($rmSource, 'function createLoanFromReservation(');
    check((bool) preg_match('/SELECT 1 FROM prestiti\s+WHERE copia_id = \?.*?LIMIT 1\s+FOR UPDATE/s', $clfr), 'ReservationManager: copy-overlap re-check is a locking read (FOR UPDATE)');

} catch (\Throwable $e) {
    // Never leave A mid-transaction holding locks.
    try {
        $connA->rollback();
    } catch (\Throwable $ignored) {
    }
    $cleanup();
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}

$cleanup();
$connA->close();
$connB->close();
echo "\n" . ($failed === 0 ? "ALL {$TESTNO} PASS\n" : "{$failed}/{$TESTNO} FAILED\n");
exit($failed > 0 ? 1 : 0);

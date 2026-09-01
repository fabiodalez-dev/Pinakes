<?php
declare(strict_types=1);

/**
 * Behavioural review coverage for the 0.7.64 loan-circulation release.
 *
 * Runs against the LIVE local MySQL (branch schema: the pickup_notification_*
 * columns on `prestiti` AND the open-ended-overdue circulation triggers), but
 * only ever touches data it creates, marked with:
 *   - book titles         ZZ_0764REV_<run>_%
 *   - copy numero_inv.    ZZ0764-<run>-%
 *   - user email          zz0764rev+<run>-<n>@test.local
 * Cleanup is scoped strictly by those markers (FK-safe order) and runs at
 * start, at end, and on any failure. The touched global loan settings are
 * captured and restored byte-for-byte.
 *
 * Locks the intended behaviour reviewed for 0.7.64:
 *   B (point 2)  CopyRepository::getAvailableByBookIdForDateRange still offers a
 *                copy that is physically out today when the requested window is
 *                date-disjoint, and hides one whose loan really overlaps, plus
 *                the c.stato NOT IN (...) out-of-circulation filter.
 *   C (point 3)  the per-copy circulation trigger treats a stale in_corso loan
 *                (overdue by date, not yet flipped) as open-ended and rejects a
 *                second loan on the SAME copy, while a DIFFERENT copy is allowed.
 *   D (point 4)  multiplicity ON permits one borrower to hold DISTINCT copies of
 *                one title, but never two overlapping loans of the SAME copy.
 *   E (point 5)  the ready-for-pickup claim UPDATE is atomic: only one attempt
 *                wins, a finalized claim is never re-selected, a stale lease is
 *                fairly re-claimable.
 *   F (point 6)  NCIP checkout keeps strict borrower/title uniqueness even when
 *                the relaxed multiplicity setting is ON.
 *
 * Requires E2E_DB_NAME (a guard aborts otherwise — this suite writes to the DB).
 * Run:
 *   E2E_DB_SOCKET=/opt/homebrew/var/mysql/mysql.sock E2E_DB_USER=... \
 *   E2E_DB_PASS="$PASS" E2E_DB_NAME=fabiodal_biblioteca \
 *   php tests/loan-review-0764.unit.php
 */

use App\Models\CopyRepository;
use App\Models\SettingsRepository;
use App\Support\ConfigStore;
use App\Support\DateHelper;
use App\Support\LoanMultiplicityPolicy;
use App\Support\PickupNotificationSchema;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

// Surface every mysqli/trigger error (SIGNAL) as an exception we can assert on.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* --------------------------------------------------------------------------
 * .env loading + safety guard (this suite performs cleanup DELETEs)
 * ------------------------------------------------------------------------ */
$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}

$dbName = getenv('E2E_DB_NAME') ?: '';
if ($dbName === '') {
    fwrite(STDERR, "FAIL: refusing to run — this suite writes to and cleans up the DB. Export E2E_DB_NAME (plus E2E_DB_SOCKET or E2E_DB_HOST/E2E_DB_USER/E2E_DB_PASS) pointing at the test database.\n");
    exit(1);
}
$dbHost = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbPort = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));
$socket = getenv('E2E_DB_SOCKET') ?: (getenv('E2E_DB_HOST') ? '' : ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock'));

try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    $db->set_charset('utf8mb4');
    // App writers bind the application-local date on every connection; the
    // circulation triggers otherwise fall back to the DB's UTC CURRENT_DATE().
    DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

/* --------------------------------------------------------------------------
 * Harness
 * ------------------------------------------------------------------------ */
$RUN = bin2hex(random_bytes(5));
$TITLE_LIKE = "ZZ_0764REV_{$RUN}_%";
$INV_LIKE = "ZZ0764-{$RUN}-%";
$EMAIL_LIKE = "zz0764rev+{$RUN}-%@test.local";

$today = DateHelper::today();
$d = static fn(int $n): string => (new DateTimeImmutable($today))->modify(($n >= 0 ? '+' : '-') . abs($n) . ' days')->format('Y-m-d');

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
    $ok ? $passed++ : $failed++;
};

$cleanup = static function () use ($db, $RUN): void {
    $like = "ZZ_0764REV_{$RUN}\\_%";
    $db->query("DELETE p FROM prestiti p JOIN libri l ON p.libro_id = l.id WHERE l.titolo LIKE '{$like}'");
    $db->query("DELETE c FROM copie c JOIN libri l ON c.libro_id = l.id WHERE l.titolo LIKE '{$like}'");
    $db->query("DELETE FROM copie WHERE numero_inventario LIKE 'ZZ0764-{$RUN}-%'");
    $db->query("DELETE FROM libri WHERE titolo LIKE '{$like}'");
    $db->query("DELETE FROM utenti WHERE email LIKE 'zz0764rev+{$RUN}-%@test.local'");
};

/** Capture a loans setting for byte-exact restore. @return array{exists:bool,value:?string} */
$captureSetting = static function (string $key) use ($db): array {
    $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key=? LIMIT 1");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ['exists' => $row !== null, 'value' => $row !== null ? ($row['setting_value'] === null ? null : (string) $row['setting_value']) : null];
};
$restoreSetting = static function (string $key, array $orig) use ($db): void {
    if (!$orig['exists']) {
        $stmt = $db->prepare("DELETE FROM system_settings WHERE category='loans' AND setting_key=?");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->close();
        ConfigStore::clearCache();
        return;
    }
    $stmt = $db->prepare("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans', ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $value = $orig['value'];
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
    ConfigStore::clearCache();
};

$seq = 0;
$mkUser = static function () use ($db, $RUN, &$seq): int {
    $seq++;
    $card = 'ZZ0764' . strtoupper($RUN) . $seq;
    $email = "zz0764rev+{$RUN}-{$seq}@test.local";
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata) VALUES (?, 'ZZ', '0764Rev', ?, 'x', 'standard', 'attivo', 1)");
    $stmt->bind_param('ss', $card, $email);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$bookSeq = 0;
/** @return array{0:int,1:list<int>} */
$mkBook = static function (int $copies, string $copyStato = 'disponibile') use ($db, $RUN, &$bookSeq): array {
    $bookSeq++;
    $title = "ZZ_0764REV_{$RUN}_{$bookSeq}";
    $stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES (?, ?, ?)");
    $stmt->bind_param('sii', $title, $copies, $copies);
    $stmt->execute();
    $bookId = (int) $db->insert_id;
    $stmt->close();

    $ids = [];
    for ($i = 1; $i <= $copies; $i++) {
        $inv = "ZZ0764-{$RUN}-{$bookId}-{$i}";
        $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, ?)");
        $stmt->bind_param('iss', $bookId, $inv, $copyStato);
        $stmt->execute();
        $ids[] = (int) $db->insert_id;
        $stmt->close();
    }
    return [$bookId, $ids];
};

$loan = static function (int $bookId, ?int $copyId, int $userId, string $from, string $to, string $stato = 'in_corso', int $attivo = 1) use ($db): int {
    $stmt = $db->prepare("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (?, ?, ?, ?, ?, ?, 'diretto', ?)");
    $stmt->bind_param('iiisssi', $bookId, $copyId, $userId, $from, $to, $stato, $attivo);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

set_exception_handler(static function (Throwable $e) use ($db, $cleanup): void {
    try { $cleanup(); } catch (Throwable $ignored) {}
    fwrite(STDERR, "UNCAUGHT: {$e->getMessage()}\n{$e->getTraceAsString()}\n");
    exit(1);
});

$origMultiplicity = $captureSetting('allow_multiple_loans_same_book');
$origMaxLoans = $captureSetting('max_active_loans_per_user');
$sessionBefore = $_SESSION ?? [];
$cleanup();

$settings = new SettingsRepository($db);
// Keep the independent per-user cap out of these fixtures.
$settings->set('loans', 'max_active_loans_per_user', '0');
ConfigStore::clearCache();

try {
    /* ==================================================================
     * B (point 2) — getAvailableByBookIdForDateRange: out-but-disjoint
     * copy is still offered; a real overlap is hidden; out-of-circulation
     * status is filtered.
     * ================================================================ */
    $repo = new CopyRepository($db);
    [$b, $copies] = $mkBook(4);
    [$cDisjoint, $cOverlap, $cManut, $cFree] = $copies;
    $u1 = $mkUser();
    $u2 = $mkUser();

    // Copy 0: physically out today (loan today..+10), DATE-DISJOINT from the
    // future request window +20..+30.
    $loan($b, $cDisjoint, $u1, $d(0), $d(10), 'in_corso', 1);
    $db->query("UPDATE copie SET stato='prestato' WHERE id={$cDisjoint}");
    // Copy 1: a loan +18..+25 that REALLY overlaps the +20..+30 window.
    $loan($b, $cOverlap, $u2, $d(18), $d(25), 'in_corso', 1);
    $db->query("UPDATE copie SET stato='prestato' WHERE id={$cOverlap}");
    // Copy 2: out of circulation (manutenzione) with no loan at all.
    $db->query("UPDATE copie SET stato='manutenzione' WHERE id={$cManut}");
    // Copy 3: plain free copy.

    $available = $repo->getAvailableByBookIdForDateRange($b, $d(20), $d(30));
    $availableIds = array_map(static fn($r) => (int) $r['id'], $available);

    $check(in_array($cDisjoint, $availableIds, true), 'B1 out-today copy with a date-disjoint loan is still assignable for a future window');
    $check(!in_array($cOverlap, $availableIds, true), 'B2 a copy whose loan truly overlaps the window is excluded');
    $check(!in_array($cManut, $availableIds, true), 'B3 an out-of-circulation (manutenzione) copy is filtered by c.stato NOT IN (...)');
    $check(in_array($cFree, $availableIds, true), 'B4 a plain free copy is offered');
    $check(count($availableIds) === 2, 'B5 exactly the disjoint + free copies are returned');

    /* ==================================================================
     * C (point 3) — stale in_corso overdue-by-date is open-ended; a second
     * loan on the SAME copy is rejected, a DIFFERENT copy is allowed.
     * ================================================================ */
    [$bc, $ccopies] = $mkBook(2, 'prestato');
    [$copyStale, $copyOther] = $ccopies;
    $borrower = $mkUser();

    // Overdue by DATE (data_scadenza in the past) but still in_corso — the cron
    // has not flipped it to in_ritardo yet.
    $staleLoan = $loan($bc, $copyStale, $borrower, $d(-30), $d(-5), 'in_corso', 1);
    $check($staleLoan > 0, 'C1 a stale in_corso loan overdue-by-date inserts');

    $rejected = false;
    try {
        // Future non-overlapping-by-date window on the SAME copy: must still be
        // rejected because the stale loan is treated as open-ended.
        $loan($bc, $copyStale, $mkUser(), $d(20), $d(30), 'in_corso', 1);
    } catch (mysqli_sql_exception $e) {
        $rejected = true;
    }
    $sameCopyCount = (int) $db->query("SELECT COUNT(*) FROM prestiti WHERE copia_id={$copyStale}")->fetch_row()[0];
    $check($rejected && $sameCopyCount === 1, 'C2 a future loan on the SAME copy is rejected — stale overdue is open-ended');

    $otherCopyLoan = $loan($bc, $copyOther, $borrower, $d(20), $d(30), 'in_corso', 1);
    $check($otherCopyLoan > 0, 'C3 a DIFFERENT copy for the same borrower is allowed (per-copy trigger scope)');

    /* ==================================================================
     * D (point 4) — multiplicity ON: distinct copies of one title for one
     * borrower coexist; the same physical copy never hosts two open loans.
     * ================================================================ */
    $settings->set('loans', 'allow_multiple_loans_same_book', '1');
    ConfigStore::clearCache();
    [$bm, $mcopies] = $mkBook(2);
    [$mA, $mB] = $mcopies;
    $mUser = $mkUser();

    $lA = $loan($bm, $mA, $mUser, $d(0), $d(7), 'in_corso', 1);
    $check($lA > 0, 'D1 multiplicity ON: first physical copy loan inserts');
    $lB = $loan($bm, $mB, $mUser, $d(0), $d(7), 'in_corso', 1);
    $distinct = (int) $db->query("SELECT COUNT(DISTINCT copia_id) FROM prestiti WHERE libro_id={$bm} AND utente_id={$mUser} AND attivo=1")->fetch_row()[0];
    $check($lB > 0 && $distinct === 2, 'D2 a second DISTINCT copy of the same title inserts (2 distinct copies held)');

    $sameRejected = false;
    try {
        $loan($bm, $mA, $mUser, $d(0), $d(7), 'in_corso', 1);
    } catch (mysqli_sql_exception $e) {
        $sameRejected = true;
    }
    $check($sameRejected, 'D3 a second overlapping loan on the SAME copy is rejected by the trigger even while ON');

    $policyOn = new LoanMultiplicityPolicy($db);
    $check(
        !$policyOn->hasBlockingLoan($bm, $mUser, true),
        'D4 LoanMultiplicityPolicy ON: an existing copy-bound sibling does not block a further copy-bound operation'
    );

    /* ==================================================================
     * E (point 5) — the ready-for-pickup claim UPDATE is atomic.
     * ================================================================ */
    [$bp, $pcopies] = $mkBook(1);
    $pUser = $mkUser();
    $pickupLoan = $loan($bp, $pcopies[0], $pUser, $d(0), $d(7), 'da_ritirare', 1);
    $db->query("UPDATE prestiti SET pickup_notification_sent=0, pickup_notification_claim_token=NULL, pickup_notification_last_attempt_at=NULL WHERE id={$pickupLoan}");

    $claim = static function (int $loanId, int $userId, string $token) use ($db): int {
        $w = PickupNotificationSchema::claimLeaseWindow();
        $stmt = $db->prepare(
            "UPDATE prestiti
                SET pickup_notification_sent = 1,
                    pickup_notification_claim_token = ?,
                    pickup_notification_last_attempt_at = ?
              WHERE id = ? AND utente_id = ?
                AND attivo = 1 AND stato = 'da_ritirare'
                AND (
                      pickup_notification_sent IS NULL
                      OR pickup_notification_sent = 0
                      OR (
                          pickup_notification_sent = 1
                          AND pickup_notification_claim_token IS NOT NULL
                          AND pickup_notification_last_attempt_at < ?
                      )
                )"
        );
        $attemptedAt = $w['attemptedAt'];
        $staleBefore = $w['staleBefore'];
        $stmt->bind_param('ssiis', $token, $attemptedAt, $loanId, $userId, $staleBefore);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected;
    };

    $tok1 = bin2hex(random_bytes(16));
    $tok2 = bin2hex(random_bytes(16));
    $check($claim($pickupLoan, $pUser, $tok1) === 1, 'E1 first claim wins (affected_rows == 1)');
    $check($claim($pickupLoan, $pUser, $tok2) === 0, 'E2 a concurrent second claim loses while the lease is live (affected_rows == 0)');

    // Finalize the announcement: the sender clears its own token; sent stays 1.
    $fin = $db->prepare("UPDATE prestiti SET pickup_notification_claim_token = NULL WHERE id = ? AND pickup_notification_claim_token = ?");
    $fin->bind_param('is', $pickupLoan, $tok1);
    $fin->execute();
    $fin->close();
    $check($claim($pickupLoan, $pUser, bin2hex(random_bytes(16))) === 0, 'E3 a finalized claim (sent=1, token cleared) is never re-selected');

    // Fair retry: a stale lease (older than the window) is re-claimable.
    $w = PickupNotificationSchema::claimLeaseWindow();
    $staleTs = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-2000 seconds')->format('Y-m-d H:i:s');
    $staleTok = bin2hex(random_bytes(16));
    $set = $db->prepare("UPDATE prestiti SET pickup_notification_sent=1, pickup_notification_claim_token=?, pickup_notification_last_attempt_at=? WHERE id=?");
    $set->bind_param('ssi', $staleTok, $staleTs, $pickupLoan);
    $set->execute();
    $set->close();
    $check($claim($pickupLoan, $pUser, bin2hex(random_bytes(16))) === 1, 'E4 an expired/stale claim lease is fairly re-claimable (affected_rows == 1)');

    /* ==================================================================
     * F (point 6) — NCIP checkout stays strict even with multiplicity ON.
     * Reproduces the plugin's own duplicate predicate (NcipServerPlugin
     * atomicCheckout) verbatim against seeded rows.
     * ================================================================ */
    $settings->set('loans', 'allow_multiple_loans_same_book', '1');
    ConfigStore::clearCache();

    [$bn, $ncopies] = $mkBook(2);
    $nUser = $mkUser();
    $loan($bn, $ncopies[0], $nUser, $d(0), $d(7), 'in_corso', 1); // active copy-bound loan

    $ncipDuplicate = static function (int $bookId, int $userId) use ($db): bool {
        $stmt = $db->prepare(
            "SELECT id FROM prestiti
              WHERE libro_id = ? AND utente_id = ?
                AND ((attivo = 0 AND stato = 'pendente')
                     OR (attivo = 1 AND stato IN ('prenotato','da_ritirare','in_corso','in_ritardo')))
              LIMIT 1"
        );
        $stmt->bind_param('ii', $bookId, $userId);
        $stmt->execute();
        $hit = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        return $hit;
    };

    $check($ncipDuplicate($bn, $nUser), 'F1 NCIP duplicate predicate rejects a second borrower/title checkout even while multiplicity is ON');
    $relaxed = new LoanMultiplicityPolicy($db);
    $check(
        !$relaxed->hasBlockingLoan($bn, $nUser, true),
        'F2 the relaxed policy WOULD allow another distinct copy here — NCIP deliberately does not adopt it'
    );

    // The pendente-copyless branch of the predicate is also strict.
    [$bn2, ] = $mkBook(1);
    $nUser2 = $mkUser();
    $loan($bn2, null, $nUser2, $d(0), $d(7), 'pendente', 0);
    $check($ncipDuplicate($bn2, $nUser2), 'F3 NCIP duplicate predicate also catches a pending/copyless request');
} finally {
    try { $db->rollback(); } catch (Throwable $e) {}
    try { $cleanup(); } catch (Throwable $e) { $failed++; fwrite(STDERR, "CLEANUP ERROR: {$e->getMessage()}\n"); }
    try {
        $restoreSetting('allow_multiple_loans_same_book', $origMultiplicity);
        $restoreSetting('max_active_loans_per_user', $origMaxLoans);
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "SETTING RESTORE ERROR: {$e->getMessage()}\n");
    }
    $_SESSION = $sessionBefore;
    $db->close();
}

echo "\n{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);

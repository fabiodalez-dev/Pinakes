<?php
declare(strict_types=1);

/**
 * #384 allocator parity suite.
 *
 * Four allocators bind copies to engagements: the request gate
 * (LoanRequestGate::findAssignableInLibraryCopyThrough), the FIFO promotion
 * (ReservationManager), the approval Step-2d allocator (LoanApprovalController)
 * and the reassignment allocator
 * (ReservationReassignmentService::findAvailableCopyExcluding). The first three
 * enforce the #384 HARD rule (any preceding commitment rejects the copy — its
 * due date is a promise, not a guarantee) plus the preference ordering "no
 * later commitment first, else furthest next commitment". The reassignment
 * allocator DELIBERATELY keeps the lenient date-overlap rule (an already
 * promised hold being repointed may stack onto disjoint commitments — the
 * alternative is cancelling it) but MUST share the preference ordering.
 *
 * This suite proves both halves behaviorally (reflection on the private
 * allocators, sandbox data, dates derived from the application today) and
 * guards the textual parity contract statically.
 *
 * Run: php tests/loan-allocator-384-parity.unit.php
 */

use App\Services\CapacityService;
use App\Services\LoanRequestGate;
use App\Services\ReservationReassignmentService;
use App\Support\DateHelper;

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
    fwrite(STDERR, "FAIL: database unreachable — allocator parity suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$run = bin2hex(random_bytes(6));
$prefix = "ZZ_ALLOC_{$run}";
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$bookIds = [];
$userIds = [];
$cleanup = static function () use ($db, &$bookIds, &$userIds): void {
    foreach ($bookIds as $id) {
        $db->query("DELETE FROM prenotazioni WHERE libro_id = {$id}");
        $db->query("DELETE FROM prestiti WHERE libro_id = {$id}");
        $db->query("DELETE FROM copie WHERE libro_id = {$id}");
        $db->query("DELETE FROM libri WHERE id = {$id}");
    }
    foreach ($userIds as $id) {
        $db->query("DELETE FROM utenti WHERE id = {$id}");
    }
};
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, 'FAIL: uncaught ' . $e->getMessage() . PHP_EOL);
    $db->close();
    exit(1);
});

$makeBook = static function (string $suffix, int $copies) use ($db, $prefix, $run, &$bookIds): array {
    $title = "{$prefix}_{$suffix}";
    $stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES (?, ?, ?, 'disponibile')");
    $stmt->bind_param('sii', $title, $copies, $copies);
    $stmt->execute();
    $bookId = (int) $db->insert_id;
    $stmt->close();
    $bookIds[] = $bookId;
    $copyIds = [];
    for ($i = 1; $i <= $copies; $i++) {
        $inv = strtoupper("AL{$suffix}{$i}-") . strtoupper(substr($run, 0, 6));
        $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
        $stmt->bind_param('is', $bookId, $inv);
        $stmt->execute();
        $copyIds[] = (int) $db->insert_id;
        $stmt->close();
    }
    return [$bookId, $copyIds];
};
$makeUser = static function (string $suffix) use ($db, $run, &$userIds): int {
    $email = "zz-alloc-{$suffix}-{$run}@test.local";
    $card = 'ZA' . strtoupper($suffix) . strtoupper(substr($run, 0, 8));
    $password = password_hash('AllocSuite!1', PASSWORD_DEFAULT);
    $stmt = $db->prepare(
        "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata)
         VALUES (?, 'Alloc', ?, ?, ?, 'standard', 'attivo', 1)"
    );
    $cog = ucfirst($suffix);
    $stmt->bind_param('ssss', $card, $cog, $email, $password);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    $userIds[] = $id;
    return $id;
};

$today = DateHelper::today();
$d = static fn (int $offset): string => (new DateTimeImmutable($today))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');

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

// ═════════ Reassignment allocator — behavioral (reflection) ═════════
[$bookP, [$copyA, $copyB]] = $makeBook('P', 2); // copyA has the LOWER id
$holder = $makeUser('holder');
$other = $makeUser('other');

$svc = new ReservationReassignmentService($db);
$alloc = new ReflectionMethod(ReservationReassignmentService::class, 'findAvailableCopyExcluding');
$pick = static fn (array $exclude, int $resId, int $userId, string $s, string $e) =>
    $alloc->invoke($svc, $bookP, $exclude, $resId, $userId, $s, $e, $today);

// 01 — preference (DISCRIMINATING for the 0.7.72 follow-up fix): copy A carries
// a future commitment AFTER the requested window; copy B is clean. The old
// ORDER BY c.id would pick A; the #384 preference must pick B.
$futureOnA = $insertLoan($bookP, $copyA, $other, $d(30), $d(40), 'prenotato', 1);
$picked = $pick([], 0, $holder, $d(0), $d(7));
$check($picked === $copyB, '01 reassignment prefers the copy with NO later commitment (would pick lower-id A pre-fix)');

// 02 — tie-break: with the future commitment gone, the lower id wins.
$db->query("DELETE FROM prestiti WHERE id = {$futureOnA}");
$picked = $pick([], 0, $holder, $d(0), $d(7));
$check($picked === $copyA, '02 with equal preference the tie-break is the lower copy id');

// 03 — exclusions are honored even when the alternative carries a future hold.
$futureOnA = $insertLoan($bookP, $copyA, $other, $d(30), $d(40), 'prenotato', 1);
$picked = $pick([$copyB], 0, $holder, $d(0), $d(7));
$check($picked === $copyA, '03 excluded copies are skipped; the remaining candidate is bound');

// 04 — lenient overlap semantics PRESERVED (the loan-edge-cases contract):
// a date-disjoint PRECEDING commitment does not reject the copy here.
$db->query("DELETE FROM prestiti WHERE libro_id = {$bookP}");
$preceding = $insertLoan($bookP, $copyA, $other, $d(1), $d(3), 'prenotato', 1);
$picked = $pick([$copyB], 0, $holder, $d(10), $d(15));
$check($picked === $copyA, '04 reassignment may stack onto a disjoint preceding commitment (deliberate divergence)');

// 05 — own-user exclusion: the target user already engaged on copy A → B wins.
$db->query("DELETE FROM prestiti WHERE libro_id = {$bookP}");
$own = $insertLoan($bookP, $copyA, $holder, $d(20), $d(25), 'pendente', 0);
$picked = $pick([], 0, $holder, $d(0), $d(7));
$check($picked === $copyB, '05 a copy already engaged by the SAME user is excluded');

// 06 — open-ended overdue blocks even future windows.
$db->query("DELETE FROM prestiti WHERE libro_id = {$bookP}");
$overdue = $insertLoan($bookP, $copyA, $other, $d(-20), $d(-2), 'in_corso', 1);
$picked = $pick([$copyB], 0, $holder, $d(10), $d(15));
$check($picked === null, '06 an overdue in_corso blocks the copy for ANY future window (open-ended)');

// ═════════ Request-gate allocator — behavioral (reflection) ═════════
$db->query("DELETE FROM prestiti WHERE libro_id = {$bookP}");
$gate = new LoanRequestGate($db);
$gateAlloc = new ReflectionMethod(LoanRequestGate::class, 'findAssignableInLibraryCopyThrough');

// 07 — HARD rule: a preceding scheduled commitment on A rejects it → B.
$preceding = $insertLoan($bookP, $copyA, $other, $d(1), $d(3), 'prenotato', 1);
$picked = $gateAlloc->invoke($gate, $bookP, $d(7));
$check($picked === $copyB, '07 the gate REJECTS a copy with a preceding commitment (hard #384 rule)');

// 08 — both copies carry preceding commitments → nobody qualifies (waitlist).
$precedingB = $insertLoan($bookP, $copyB, $other, $d(2), $d(4), 'prenotato', 1);
$picked = $gateAlloc->invoke($gate, $bookP, $d(7));
$check($picked === null, '08 with every copy preceded the gate returns null (request routes to the waitlist)');

// 09 — gate preference: A has a commitment AFTER the window, B is clean → B.
$db->query("DELETE FROM prestiti WHERE libro_id = {$bookP}");
$futureOnA = $insertLoan($bookP, $copyA, $other, $d(30), $d(40), 'prenotato', 1);
$picked = $gateAlloc->invoke($gate, $bookP, $d(7));
$check($picked === $copyB, '09 the gate shares the preference: keep the copy a future loan already needs');

// ═════════ Capacity gate + headQueuePos (#157 F.21b) ═════════
[$bookC] = $makeBook('C', 1);
$uH = $makeUser('head');
$uT = $makeUser('tail');
$stmt = $db->prepare(
    "INSERT INTO prenotazioni (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta, queue_position, stato)
     VALUES (?, ?, ?, ?, ?, 'attiva')"
);
$rs = $d(0); $re = $d(7); $pos = 1;
$stmt->bind_param('iissi', $bookC, $uH, $rs, $re, $pos);
$stmt->execute();
$resHead = (int) $db->insert_id;
$pos = 2;
$stmt->bind_param('iissi', $bookC, $uT, $rs, $re, $pos);
$stmt->execute();
$resTail = (int) $db->insert_id;
$stmt->close();

$capacity = new CapacityService($db);
$check($capacity->hasFreeCapacity($bookC, $d(0), $d(7), null, $resHead, null, 1) === true,
    '10 the queue HEAD sees free capacity (tail reservations are excluded after its position)');
$check($capacity->hasFreeCapacity($bookC, $d(0), $d(7), null, $resTail, null, 2) === false,
    '11 the queue TAIL does not: the head still occupies the only copy');

$stmt = $db->prepare(
    "INSERT INTO prenotazioni (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta, queue_position, stato)
     VALUES (?, ?, ?, ?, NULL, 'attiva')"
);
$stmt->bind_param('iiss', $bookC, $other, $rs, $re);
$stmt->execute();
$stmt->close();
$check($capacity->hasFreeCapacity($bookC, $d(0), $d(7), null, $resHead, null, 1) === false,
    '12 a NULL queue_position is counted conservatively and consumes capacity');

// ═════════ Static parity contract ═════════
$gateSrc = (string) file_get_contents($root . '/app/Services/LoanRequestGate.php');
$rmSrc = (string) file_get_contents($root . '/app/Controllers/ReservationManager.php');
$apprSrc = (string) file_get_contents($root . '/app/Controllers/LoanApprovalController.php');
$reasSrc = (string) file_get_contents($root . '/app/Services/ReservationReassignmentService.php');

$check(str_contains($gateSrc, "'9999-12-31'"), '13 gate allocator carries the MIN(future) preference sentinel');
$check(str_contains($rmSrc, "'9999-12-31'"), '14 FIFO-promotion allocator carries the preference sentinel');
$check(str_contains($apprSrc, "'9999-12-31'"), '15 approval Step-2d allocator carries the preference sentinel');
$check(str_contains($reasSrc, "'9999-12-31'"), '16 reassignment allocator now carries the preference sentinel too');

$check(substr_count($reasSrc, 'MIN(future.data_prestito)') >= 1
    && str_contains($reasSrc, 'DELIBERATE divergence'),
    '17 the reassignment divergence from the hard rule is explicit and documented');
$check(str_contains($reasSrc, "p.data_prestito <= ?")
    && str_contains($reasSrc, "p.data_scadenza >= ?"),
    '18 the lenient date-overlap predicate (loan-edge-cases contract) is intact');
$check(str_contains($gateSrc, 'p.data_prestito <= ?')
    && !str_contains(substr($gateSrc, (int) strpos($gateSrc, 'findAssignableInLibraryCopyThrough'), 1600), 'data_scadenza >= ?'),
    '19 the gate keeps the HARD rule: any commitment starting before the window end rejects');

$cleanup();
$db->close();

echo PHP_EOL . "Passed: {$passed}   Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

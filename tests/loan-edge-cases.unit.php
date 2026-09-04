<?php
declare(strict_types=1);

/**
 * Behavioral integration suite — 68 loan ("prestiti") edge cases for Pinakes.
 *
 * Runs against the LIVE local MySQL but only ever touches data it creates,
 * marked with: book titles `ZZ_LOANEDGE_%`, copy numero_inventario `ZZLE-%`,
 * user email `zzloanedge+<n>@test.local`. Cleanup is scoped strictly by those
 * markers (FK-safe order) and runs at start, at end, and on any failure.
 *
 * Locks the intended behavior of:
 *   - DataIntegrity::recalculateBookAvailability() (availability engine)
 *   - CopyRepository::create()/updateStatus()
 *   - PrestitiController::processReturn() (return outcome -> copy mapping,
 *     late-return flag) — replicated here by ret()
 *   - installer/database/triggers.sql (copy-occupancy invariants)
 *
 * Run:   php tests/loan-edge-cases.unit.php
 * Exit:  0 only if all 66 pass; prints "ALL 66 PASS".
 */

use App\Models\CopyRepository;
use App\Models\SettingsRepository;
use App\Controllers\ReservationManager;
use App\Controllers\UserActionsController;
use App\Services\CapacityService;
use App\Services\ReservationReassignmentService;
use App\Support\DataIntegrity;
use App\Support\DateHelper;
use App\Support\IcsGenerator;
use App\Support\MaintenanceService;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

// Throw on any mysqli/trigger error so SIGNAL from triggers surfaces as an
// exception we can assert against.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* --------------------------------------------------------------------------
 * .env loading (DB_NAME / DB_USER / DB_PASS|DB_PASSWORD)
 * ------------------------------------------------------------------------ */
function loadEnv(string $path): array
{
    $env = [];
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new \RuntimeException("Cannot read .env at {$path}");
    }
    foreach (preg_split('/\r?\n/', $raw) as $line) {
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

$env    = loadEnv($root . '/.env');
$dbName = $env['DB_NAME'] ?? '';
$dbUser = $env['DB_USER'] ?? '';
$dbPass = $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '');
// Socket/host configurabili (CI non ha il socket Homebrew di macOS):
// E2E_DB_SOCKET > .env DB_SOCKET > default macOS; se il socket non esiste,
// fallback TCP su DB_HOST/DB_PORT. DB irraggiungibile => SKIP, non FAIL.
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
try {
    if (is_string($socket) && $socket !== '' && file_exists($socket)) {
        $db = new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket);
    } else {
        $db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $dbUser, $dbPass, $dbName, (int) ($env['DB_PORT'] ?? 3306));
    }
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}
$db->set_charset('utf8mb4');
// Production writers bind the application-local date on every
// connection (container/cron/scripts bootstrap); the circulation
// triggers otherwise fall back to the database's UTC CURRENT_DATE(),
// which disagrees with app.timezone between 22:00 and 24:00 UTC.
\App\Support\DateHelper::synchronizeDatabaseSession($db);
$edgeSettings = new SettingsRepository($db);
$originalMaxActiveLoans = $edgeSettings->get('loans', 'max_active_loans_per_user', '0');

/* --------------------------------------------------------------------------
 * Markers + cleanup
 * ------------------------------------------------------------------------ */
$RUN          = substr(md5(uniqid((string) getmypid(), true)), 0, 8);
$TITLE_LIKE   = 'ZZ_LOANEDGE_%';
$EMAIL_LIKE   = 'zzloanedge+%@test.local';
$INV_LIKE     = 'ZZLE-%';

function cleanup(mysqli $db): void
{
    global $originalMaxActiveLoans;
    // FK-safe order, scoped strictly by markers. ON DELETE RESTRICT on
    // prestiti.copia_id forces prestiti to die before copie/libri.
    $db->query(
        "DELETE p FROM prestiti p JOIN libri l ON p.libro_id = l.id WHERE l.titolo LIKE 'ZZ_LOANEDGE_%'"
    );
    $db->query(
        "DELETE p FROM prestiti p JOIN utenti u ON p.utente_id = u.id WHERE u.email LIKE 'zzloanedge+%@test.local'"
    );
    $db->query(
        "DELETE pr FROM prenotazioni pr JOIN libri l ON pr.libro_id = l.id WHERE l.titolo LIKE 'ZZ_LOANEDGE_%'"
    );
    $db->query(
        "DELETE pr FROM prenotazioni pr JOIN utenti u ON pr.utente_id = u.id WHERE u.email LIKE 'zzloanedge+%@test.local'"
    );
    $db->query(
        "DELETE c FROM copie c JOIN libri l ON c.libro_id = l.id WHERE l.titolo LIKE 'ZZ_LOANEDGE_%'"
    );
    $db->query("DELETE FROM copie WHERE numero_inventario LIKE 'ZZLE-%'");
    $db->query("DELETE FROM libri WHERE titolo LIKE 'ZZ_LOANEDGE_%'");
    $db->query("DELETE FROM utenti WHERE email LIKE 'zzloanedge+%@test.local'");
    (new SettingsRepository($db))->set(
        'loans',
        'max_active_loans_per_user',
        (string) ($originalMaxActiveLoans ?? '0')
    );
}

// Single, intentional exit point. On any failure (assertion throw or DB error)
// scrub our markers, report to STDERR, exit non-zero for CI.
set_exception_handler(static function (\Throwable $e) use ($db): void {
    try {
        cleanup($db);
    } catch (\Throwable $ignored) {
        // best-effort
    }
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
});

cleanup($db); // start clean so reruns never collide

/* --------------------------------------------------------------------------
 * Test harness
 * ------------------------------------------------------------------------ */
$TESTNO = 0;
function pass(string $desc): void
{
    global $TESTNO;
    $TESTNO++;
    printf("[%02d/68] PASS: %s\n", $TESTNO, $desc);
}

function assertEq($exp, $got, string $msg): void
{
    if ($exp !== $got) {
        throw new \RuntimeException(
            $msg . " (expected " . var_export($exp, true) . ", got " . var_export($got, true) . ")"
        );
    }
}

function assertThrows(callable $fn, string $msg): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return;
    }
    throw new \RuntimeException("Expected exception but none thrown: " . $msg);
}

/* --- build helpers --- */
function mkBook(string $title): int
{
    global $db, $RUN;
    $t = 'ZZ_LOANEDGE_' . $RUN . '_' . $title;
    $stmt = $db->prepare(
        "INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (?, 'disponibile', 0, 0)"
    );
    $stmt->bind_param('s', $t);
    $stmt->execute();
    $id = $db->insert_id;
    $stmt->close();
    return $id;
}

function mkCopies(int $bookId, int $n, string $stato = 'disponibile'): array
{
    global $db, $RUN;
    $repo = new CopyRepository($db);
    $ids = [];
    for ($i = 0; $i < $n; $i++) {
        $inv = 'ZZLE-' . $RUN . '-' . $bookId . '-' . uniqid();
        $ids[] = $repo->create($bookId, $inv, $stato);
    }
    return $ids;
}

function mkUser(): int
{
    global $db, $RUN;
    static $seq = 0;
    $seq++;
    $tess  = 'ZZLE' . strtoupper($RUN) . $seq;
    $email = 'zzloanedge+' . $RUN . '-' . $seq . '@test.local';
    $pwd   = password_hash('x', PASSWORD_BCRYPT);
    $stmt  = $db->prepare(
        "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente)
         VALUES (?, 'ZZ', 'LoanEdge', ?, ?, 'standard')"
    );
    $stmt->bind_param('sss', $tess, $email, $pwd);
    $stmt->execute();
    $id = $db->insert_id;
    $stmt->close();
    return $id;
}

/**
 * Raw INSERT into prestiti (LoanRepository::create() is disabled). Lets the
 * trigger throw on invariant violations.
 */
function loan(
    int $bookId,
    int $copyId,
    int $userId,
    string $from,
    string $to,
    string $stato = 'in_corso',
    int $attivo = 1
): int {
    global $db;
    $stmt = $db->prepare(
        "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo)
         VALUES (?, ?, ?, ?, ?, ?, 'diretto', ?)"
    );
    $stmt->bind_param('iiisssi', $bookId, $copyId, $userId, $from, $to, $stato, $attivo);
    $stmt->execute();
    $id = $db->insert_id;
    $stmt->close();
    return $id;
}

/**
 * Replicates PrestitiController::processReturn() core so the suite documents
 * and locks the intended behavior. Guarded on attivo=1 (no double-processing).
 */
function ret(int $loanId, string $outcome): void
{
    global $db;
    $today = date('Y-m-d');

    $stmt = $db->prepare("SELECT libro_id, copia_id, data_scadenza, attivo FROM prestiti WHERE id = ?");
    $stmt->bind_param('i', $loanId);
    $stmt->execute();
    $loan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$loan || (int) $loan['attivo'] !== 1) {
        return; // already closed -> no-op (mirrors processReturn WHERE attivo=1 guard)
    }

    // outcome -> (loan_stato, copia_stato). Repair outcomes close the loan as
    // 'restituito' but divert the copy out of circulation.
    [$loanStato, $copiaStato] = match ($outcome) {
        'restituito'   => ['restituito',  'disponibile'],
        'manutenzione' => ['restituito',  'manutenzione'],
        'in_restauro'  => ['restituito',  'in_restauro'],
        'perso'        => ['perso',       'perso'],
        'danneggiato'  => ['danneggiato', 'danneggiato'],
        default        => throw new \RuntimeException("Unknown outcome {$outcome}"),
    };

    $scadenza = (string) ($loan['data_scadenza'] ?? '');
    $ritardo  = ($loanStato === 'restituito' && $scadenza !== '' && $scadenza < $today) ? 1 : 0;

    $stmt = $db->prepare(
        "UPDATE prestiti SET stato = ?, data_restituzione = ?, attivo = 0, restituito_in_ritardo = ?
         WHERE id = ? AND attivo = 1"
    );
    $stmt->bind_param('ssii', $loanStato, $today, $ritardo, $loanId);
    $stmt->execute();
    $stmt->close();

    if ($loan['copia_id'] !== null) {
        (new CopyRepository($db))->updateStatus((int) $loan['copia_id'], $copiaStato);
    }

    recalc((int) $loan['libro_id']);
}

function recalc(int $bookId): void
{
    global $db;
    (new DataIntegrity($db))->recalculateBookAvailability($bookId);
}

function bookStato(int $id): string
{
    global $db;
    $r = $db->query("SELECT stato FROM libri WHERE id = " . (int) $id);
    return (string) $r->fetch_assoc()['stato'];
}

function copieDisp(int $id): int
{
    global $db;
    $r = $db->query("SELECT copie_disponibili FROM libri WHERE id = " . (int) $id);
    return (int) $r->fetch_assoc()['copie_disponibili'];
}

function copieTot(int $id): int
{
    global $db;
    $r = $db->query("SELECT copie_totali FROM libri WHERE id = " . (int) $id);
    return (int) $r->fetch_assoc()['copie_totali'];
}

function copyStato(int $id): string
{
    global $db;
    $r = $db->query("SELECT stato FROM copie WHERE id = " . (int) $id);
    return (string) $r->fetch_assoc()['stato'];
}

/* ==========================================================================
 * 1-6  Basic single copy
 * ====================================================================== */
$b = mkBook('basic');
$c = mkCopies($b, 1)[0];
$u = mkUser();
recalc($b);
assertEq(1, copieDisp($b), 'fresh single copy -> copie_disponibili=1');
pass('single copy: create -> copie_disponibili=1');

assertEq('disponibile', bookStato($b), 'fresh single copy -> libri.stato disponibile');
pass('single copy: create -> libri.stato disponibile');

assertEq('disponibile', copyStato($c), 'fresh copy -> copie.stato disponibile');
pass('single copy: create -> copie.stato disponibile');

$l = loan($b, $c, $u, date('Y-m-d'), date('Y-m-d', strtotime('+7 days')));
recalc($b);
assertEq(0, copieDisp($b), 'loaned single copy -> copie_disponibili=0');
pass('single copy: loan -> copie_disponibili=0');

assertEq('prestato', bookStato($b), 'loaned single copy -> libri.stato prestato');
assertEq('prestato', copyStato($c), 'loaned single copy -> copie.stato prestato');
pass('single copy: loan -> libri.stato + copie.stato = prestato');

ret($l, 'restituito');
assertEq(1, copieDisp($b), 'returned -> copie_disponibili back to 1');
assertEq('disponibile', bookStato($b), 'returned -> libri.stato disponibile');
assertEq('disponibile', copyStato($c), 'returned -> copie.stato disponibile');
pass('single copy: return restituito -> back to 1/disponibile');

/* ==========================================================================
 * 7-14  Multi-copy (3 copies)
 * ====================================================================== */
$b = mkBook('multi');
[$c1, $c2, $c3] = mkCopies($b, 3);
$u = mkUser();
recalc($b);
assertEq(3, copieDisp($b), '3 fresh copies -> copie_disponibili=3');
assertEq('disponibile', bookStato($b), '3 fresh copies -> disponibile');
pass('multi-copy: 3 copies -> copie_disponibili=3, disponibile');

$lA = loan($b, $c1, $u, date('Y-m-d'), date('Y-m-d', strtotime('+7 days')));
recalc($b);
assertEq(2, copieDisp($b), 'loan 1 of 3 -> copie_disponibili=2');
pass('multi-copy: loan 1/3 -> copie_disponibili=2');

assertEq('disponibile', bookStato($b), 'loan 1 of 3 -> still disponibile');
pass('multi-copy: loan 1/3 -> libri.stato still disponibile');

$lB = loan($b, $c2, $u, date('Y-m-d'), date('Y-m-d', strtotime('+7 days')));
recalc($b);
assertEq(1, copieDisp($b), 'loan 2 of 3 -> copie_disponibili=1');
pass('multi-copy: loan 2/3 -> copie_disponibili=1');

$lC = loan($b, $c3, $u, date('Y-m-d'), date('Y-m-d', strtotime('+7 days')));
recalc($b);
assertEq(0, copieDisp($b), 'loan 3 of 3 -> copie_disponibili=0');
pass('multi-copy: loan 3/3 -> copie_disponibili=0');

assertEq('prestato', bookStato($b), 'all 3 loaned -> libri.stato prestato');
pass('multi-copy: all loaned -> libri.stato prestato');

ret($lA, 'restituito');
assertEq(1, copieDisp($b), 'return one of 3 -> copie_disponibili=1');
assertEq('disponibile', copyStato($c1), 'returned copy -> disponibile');
pass('multi-copy: return one -> copie_disponibili up');

assertEq('disponibile', bookStato($b), 'after one return -> libri.stato disponibile');
pass('multi-copy: after one return -> libri.stato disponibile');

/* ==========================================================================
 * 15-20  Overlap / occupancy (trigger-enforced)
 * ====================================================================== */
$b = mkBook('overlap');
$c = mkCopies($b, 1)[0];
$u = mkUser();
$u2 = mkUser();
$lov = loan($b, $c, $u, date('Y-m-d'), date('Y-m-d', strtotime('+7 days')));
assertEq(true, $lov > 0, 'first loan inserts');
pass('overlap: first loan on copy succeeds');

assertThrows(static function () use ($b, $c, $u2) {
    loan($b, $c, $u2, date('Y-m-d', strtotime('+2 days')), date('Y-m-d', strtotime('+5 days')));
}, 'overlapping loan on busy copy must be rejected by trigger');
pass('overlap: overlapping loan on same copy rejected (trigger)');

assertThrows(static function () use ($b, $c, $u2) {
    // boundary: new loan starts exactly on the day the first one ends -> inclusive overlap
    loan($b, $c, $u2, date('Y-m-d', strtotime('+7 days')), date('Y-m-d', strtotime('+10 days')));
}, 'boundary-touching loan (start == other end) must be rejected');
pass('overlap: boundary same-day overlap rejected (inclusive)');

$lfut = loan($b, $c, $u2, date('Y-m-d', strtotime('+8 days')), date('Y-m-d', strtotime('+12 days')));
assertEq(true, $lfut > 0, 'non-overlapping future loan on same copy allowed');
pass('overlap: non-overlapping future loan on same copy allowed');

$bx = mkBook('nonlendable');
$cx = mkCopies($bx, 1, 'manutenzione')[0];
$ux = mkUser();
assertThrows(static function () use ($bx, $cx, $ux) {
    loan($bx, $cx, $ux, date('Y-m-d'), date('Y-m-d', strtotime('+7 days')));
}, 'loan on a non-lendable (manutenzione) copy must be rejected');
pass('overlap: loan on non-lendable copy rejected (trigger)');

// clear the future loan, then return the active one so the original period frees
$db->query("DELETE FROM prestiti WHERE id = " . (int) $lfut);
ret($lov, 'restituito');
$lre = loan($b, $c, $u2, date('Y-m-d', strtotime('+2 days')), date('Y-m-d', strtotime('+5 days')));
assertEq(true, $lre > 0, 'after return, copy reloanable for the formerly-conflicting period');
pass('overlap: after return, same copy loanable again for conflicting period');

/* ==========================================================================
 * 21-30  Return outcomes & copy mapping
 * ====================================================================== */
// 21-22 on-time restituito
$b = mkBook('ret_ontime');
$c = mkCopies($b, 1)[0];
$u = mkUser();
$l = loan($b, $c, $u, date('Y-m-d'), date('Y-m-d', strtotime('+7 days')));
ret($l, 'restituito');
$row = $db->query("SELECT stato, restituito_in_ritardo, attivo FROM prestiti WHERE id = " . (int) $l)->fetch_assoc();
assertEq('0', (string) $row['restituito_in_ritardo'], 'on-time return -> restituito_in_ritardo=0');
assertEq('restituito', $row['stato'], 'on-time -> loan stato restituito');
pass('return: on-time restituito -> restituito_in_ritardo=0');

assertEq('disponibile', copyStato($c), 'on-time return -> copy disponibile');
pass('return: on-time restituito -> copy disponibile');

// 23-25 late restituito
$b = mkBook('ret_late');
$c = mkCopies($b, 1)[0];
$u = mkUser();
$l = loan($b, $c, $u, date('Y-m-d', strtotime('-10 days')), date('Y-m-d', strtotime('-3 days')));
ret($l, 'restituito');
$row = $db->query("SELECT stato, restituito_in_ritardo FROM prestiti WHERE id = " . (int) $l)->fetch_assoc();
assertEq('1', (string) $row['restituito_in_ritardo'], 'late return -> restituito_in_ritardo=1');
pass('return: late restituito -> restituito_in_ritardo=1');

assertEq('restituito', $row['stato'], 'late return -> loan stato restituito (not in_ritardo)');
pass('return: late restituito -> loan stato=restituito');

assertEq('disponibile', copyStato($c), 'late return -> copy disponibile');
pass('return: late restituito -> copy disponibile');

// 26-28 perso
$b = mkBook('ret_perso');
$c = mkCopies($b, 1)[0];
$u = mkUser();
$l = loan($b, $c, $u, date('Y-m-d'), date('Y-m-d', strtotime('+7 days')));
ret($l, 'perso');
$row = $db->query("SELECT stato FROM prestiti WHERE id = " . (int) $l)->fetch_assoc();
assertEq('perso', $row['stato'], 'perso -> loan stato perso');
pass('return: perso -> loan stato=perso');

assertEq('perso', copyStato($c), 'perso -> copy stato perso');
pass('return: perso -> copy stato=perso');

assertEq(0, copieDisp($b), 'perso copy excluded -> copie_disponibili=0');
assertEq(0, copieTot($b), 'perso copy excluded from copie_totali');
pass('return: perso -> copy excluded from availability');

// 29-30 danneggiato
$b = mkBook('ret_danneggiato');
$c = mkCopies($b, 1)[0];
$u = mkUser();
$l = loan($b, $c, $u, date('Y-m-d'), date('Y-m-d', strtotime('+7 days')));
ret($l, 'danneggiato');
$row = $db->query("SELECT stato FROM prestiti WHERE id = " . (int) $l)->fetch_assoc();
assertEq('danneggiato', $row['stato'], 'danneggiato -> loan stato danneggiato');
assertEq('danneggiato', copyStato($c), 'danneggiato -> copy stato danneggiato');
pass('return: danneggiato -> loan + copy stato=danneggiato');

assertEq(0, copieDisp($b), 'danneggiato copy excluded -> copie_disponibili=0');
assertEq(0, copieTot($b), 'danneggiato copy excluded from copie_totali');
pass('return: danneggiato -> copy excluded from availability + totali');

/* ==========================================================================
 * 31-40  REPAIR (manutenzione / in_restauro) — the new feature
 * ====================================================================== */
// 31-35 manutenzione
$b = mkBook('repair_manut');
$c = mkCopies($b, 1)[0];
$u = mkUser();
$l = loan($b, $c, $u, date('Y-m-d'), date('Y-m-d', strtotime('+7 days')));
recalc($b);
ret($l, 'manutenzione');
$row = $db->query("SELECT stato, attivo FROM prestiti WHERE id = " . (int) $l)->fetch_assoc();
assertEq('restituito', $row['stato'], 'manutenzione -> loan closed as restituito');
assertEq('0', (string) $row['attivo'], 'manutenzione -> loan attivo=0');
pass('repair: manutenzione return -> loan stato=restituito');

assertEq('manutenzione', copyStato($c), 'manutenzione -> copy stato manutenzione');
pass('repair: manutenzione return -> copy stato=manutenzione');

assertEq(0, copieDisp($b), 'manutenzione copy excluded from copie_disponibili');
pass('repair: manutenzione -> excluded from copie_disponibili');

assertEq(true, bookStato($b) !== 'disponibile', 'sole copy in manutenzione -> libri.stato not disponibile');
pass('repair: manutenzione sole copy -> libri.stato not disponibile');

recalc($b);
recalc($b);
assertEq('manutenzione', copyStato($c), 'recalc twice preserves manutenzione copy');
pass('repair: recalc twice preserves manutenzione copy state');

// 36-38 in_restauro
$b = mkBook('repair_restauro');
$c = mkCopies($b, 1)[0];
$u = mkUser();
$l = loan($b, $c, $u, date('Y-m-d'), date('Y-m-d', strtotime('+7 days')));
ret($l, 'in_restauro');
$row = $db->query("SELECT stato FROM prestiti WHERE id = " . (int) $l)->fetch_assoc();
assertEq('restituito', $row['stato'], 'in_restauro -> loan closed as restituito');
assertEq('in_restauro', copyStato($c), 'in_restauro -> copy stato in_restauro');
pass('repair: in_restauro return -> loan restituito, copy in_restauro');

assertEq(0, copieDisp($b), 'in_restauro copy excluded from copie_disponibili');
pass('repair: in_restauro -> excluded from copie_disponibili');

recalc($b);
recalc($b);
assertEq('in_restauro', copyStato($c), 'recalc twice preserves in_restauro copy');
pass('repair: recalc twice preserves in_restauro copy state');

// 39-40 repair done -> back in circulation
(new CopyRepository($db))->updateStatus($c, 'disponibile');
recalc($b);
assertEq('disponibile', copyStato($c), 'repair done -> copy back disponibile');
assertEq(1, copieDisp($b), 'repair done -> copie_disponibili restored to 1');
assertEq('disponibile', bookStato($b), 'repair done -> libri.stato disponibile');
pass('repair: done -> copy circulating again, availability restored');

// 40: while in repair the copy is out of copie_totali; after repair it returns
$b40 = mkBook('repair_totali');
$c40 = mkCopies($b40, 1)[0];
(new CopyRepository($db))->updateStatus($c40, 'manutenzione');
recalc($b40);
assertEq(0, copieTot($b40), 'manutenzione copy excluded from copie_totali');
(new CopyRepository($db))->updateStatus($c40, 'disponibile');
recalc($b40);
assertEq(1, copieTot($b40), 'after repair, copy returns to copie_totali');
pass('repair: copie_totali excludes repair copy, restores after repair');

/* ==========================================================================
 * 41-48  Mixed / availability math
 * ====================================================================== */
// 41-43: 3 copies = 1 loaned + 1 manutenzione + 1 disponibile
$b = mkBook('mixed');
[$m1, $m2, $m3] = mkCopies($b, 3);
$u = mkUser();
$lm = loan($b, $m1, $u, date('Y-m-d'), date('Y-m-d', strtotime('+7 days')));
(new CopyRepository($db))->updateStatus($m2, 'manutenzione');
recalc($b);
assertEq(1, copieDisp($b), 'loaned + manutenzione + disponibile -> copie_disponibili=1');
pass('mixed: 1 loaned + 1 repair + 1 free -> copie_disponibili=1');

assertEq('disponibile', bookStato($b), 'mixed with one free copy -> libri.stato disponibile');
pass('mixed: with a free copy -> libri.stato disponibile');

assertEq(2, copieTot($b), 'copie_totali excludes the manutenzione copy (=2)');
pass('mixed: copie_totali excludes repair copy');

// 44-45: all copies in repair
(new CopyRepository($db))->updateStatus($m3, 'in_restauro');
ret($lm, 'manutenzione'); // last circulating copy now also out
recalc($b);
assertEq(0, copieDisp($b), 'all copies in repair -> copie_disponibili=0');
pass('mixed: all copies in repair -> copie_disponibili=0');

// 45: the libri.stato gap is CLOSED (0.7.25). With copies present but none
// circulating (all in repair) and none loaned/reserved, recalc derives the new
// 'non_disponibile' enum value instead of leaving a stale 'disponibile'.
assertEq('non_disponibile', bookStato($b), 'all copies in repair -> libri.stato non_disponibile');
$circulating = (int) $db->query(
    "SELECT COUNT(*) AS n FROM copie WHERE libro_id = " . (int) $b
    . " AND stato IN ('disponibile','prenotato','prestato')"
)->fetch_assoc()['n'];
assertEq(0, $circulating, 'all copies in repair -> zero circulating copy rows');
pass('mixed: all copies in repair -> libri.stato non_disponibile + 0 circulating');

// 45b: restoring one repair copy flips the book back to 'disponibile'.
(new CopyRepository($db))->updateStatus($m3, 'disponibile');
recalc($b);
assertEq(1, copieDisp($b), 'repair copy restored -> copie_disponibili=1');
assertEq('disponibile', bookStato($b), 'repair copy restored -> libri.stato disponibile');
pass('repair: restore one copy -> book disponibile again');

// 45c: a book whose copies are ALL 'perso' is non_disponibile (not stale).
$bp = mkBook('all_perso');
mkCopies($bp, 2, 'perso');
recalc($bp);
assertEq(0, copieDisp($bp), 'all-perso book -> copie_disponibili=0');
assertEq('non_disponibile', bookStato($bp), 'all-perso book -> libri.stato non_disponibile');
pass('edge: all copies perso -> libri.stato non_disponibile');

// 48: copie_disponibili never negative (reservations exceeding copies clamp to 0)
$b = mkBook('negclamp');
$c = mkCopies($b, 1)[0];
$ua = mkUser();
$ub = mkUser();
$today = date('Y-m-d');
$end = date('Y-m-d', strtotime('+1 day'));
foreach ([$ua, $ub] as $uu) {
    $st = $db->prepare(
        "INSERT INTO prenotazioni (libro_id, utente_id, data_inizio_richiesta, data_fine_richiesta, data_scadenza_prenotazione, stato, queue_position)
         VALUES (?, ?, ?, ?, ?, 'attiva', 1)"
    );
    $st->bind_param('iisss', $b, $uu, $today, $end, $end);
    $st->execute();
    $st->close();
}
recalc($b);
assertEq(true, copieDisp($b) >= 0, 'copie_disponibili never negative');
assertEq(0, copieDisp($b), '1 copy - 2 active reservations clamps to 0');
pass('math: copie_disponibili never negative (clamped to 0)');

/* ==========================================================================
 * 49-52  Misc invariants
 * ====================================================================== */
// 49: returning an already-returned loan is a guarded no-op
$b = mkBook('double_return');
$c = mkCopies($b, 1)[0];
$u = mkUser();
$l = loan($b, $c, $u, date('Y-m-d'), date('Y-m-d', strtotime('+7 days')));
ret($l, 'restituito');
ret($l, 'perso'); // must be ignored — loan already closed
$row = $db->query("SELECT stato, attivo FROM prestiti WHERE id = " . (int) $l)->fetch_assoc();
assertEq('restituito', $row['stato'], 'second return is a no-op -> stato stays restituito');
assertEq('disponibile', copyStato($c), 'second return is a no-op -> copy stays disponibile');
pass('misc: returning an already-returned loan is a no-op');

// 50: soft-deleted book — return still closes the loan & frees the copy
$b = mkBook('softdel');
$c = mkCopies($b, 1)[0];
$u = mkUser();
$l = loan($b, $c, $u, date('Y-m-d'), date('Y-m-d', strtotime('+7 days')));
recalc($b);
$db->query("UPDATE libri SET deleted_at = NOW() WHERE id = " . (int) $b);
ret($l, 'restituito');
$row = $db->query("SELECT attivo, stato FROM prestiti WHERE id = " . (int) $l)->fetch_assoc();
assertEq('0', (string) $row['attivo'], 'soft-deleted book -> return still closes loan');
assertEq('disponibile', copyStato($c), 'soft-deleted book -> copy still freed');
pass('misc: soft-deleted book return closes loan & frees copy');

// 51: in_trasferimento copy excluded from loanable count
$b = mkBook('transit');
[$t1, $t2] = mkCopies($b, 2);
(new CopyRepository($db))->updateStatus($t2, 'in_trasferimento');
recalc($b);
assertEq(1, copieTot($b), 'in_trasferimento copy excluded from copie_totali');
assertEq(1, copieDisp($b), 'in_trasferimento copy excluded from copie_disponibili');
pass('misc: in_trasferimento copy excluded from loanable count');

// 50: libri.stato is derived — recalc overwrites a manually-wrong value
$b = mkBook('derived');
$c = mkCopies($b, 1)[0];
recalc($b);
$db->query("UPDATE libri SET stato = 'prestato' WHERE id = " . (int) $b); // deliberately wrong
recalc($b);
assertEq('disponibile', bookStato($b), 'recalc overwrites manually-wrong libri.stato');
pass('misc: libri.stato is derived (recalc overwrites wrong value)');

/* ==========================================================================
 * 53-66  Canonical capacity, schedules, integrity, calendars and reservation queues
 * ====================================================================== */
// 53: an unreturned overdue loan is open-ended for future capacity decisions.
$b = mkBook('capacity_overdue_open');
$c = mkCopies($b, 1, 'prestato')[0];
$u = mkUser();
loan($b, $c, $u, date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('-10 days')), 'in_ritardo', 1);
$futureStart = date('Y-m-d', strtotime('+5 days'));
$futureEnd = date('Y-m-d', strtotime('+12 days'));
assertEq(false, (new CapacityService($db))->hasFreeCapacity($b, $futureStart, $futureEnd), 'overdue copy must block future capacity');
pass('capacity: overdue unreturned loan remains open-ended');

// 54: capacity is the daily PEAK, not the raw number of intervals.
$b = mkBook('capacity_peak');
[$c1, $c2] = mkCopies($b, 2);
$u1 = mkUser();
$u2 = mkUser();
$start = date('Y-m-d', strtotime('+10 days'));
$middle = date('Y-m-d', strtotime('+15 days'));
$afterMiddle = date('Y-m-d', strtotime('+16 days'));
$end = date('Y-m-d', strtotime('+21 days'));
loan($b, $c1, $u1, $start, $middle, 'prenotato', 1);
loan($b, $c1, $u2, $afterMiddle, $end, 'prenotato', 1);
assertEq(true, (new CapacityService($db))->hasFreeCapacity($b, $start, $end), 'two disjoint intervals use peak=1 of two copies');
pass('capacity: disjoint commitments use daily peak, not raw count');

// 55: physical-copy lists stay unique while the calendar retains every slot.
$b = mkBook('copy_schedule_unique');
$c = mkCopies($b, 1)[0];
$u1 = mkUser();
$u2 = mkUser();
loan($b, $c, $u1, date('Y-m-d', strtotime('+30 days')), date('Y-m-d', strtotime('+35 days')), 'prenotato', 1);
loan($b, $c, $u2, date('Y-m-d', strtotime('+36 days')), date('Y-m-d', strtotime('+42 days')), 'prenotato', 1);
$copyRepo = new CopyRepository($db);
assertEq(1, count($copyRepo->getByBookId($b)), 'copy table must have one row per physical copy');
assertEq(2, count($copyRepo->getScheduleByBookId($b)), 'calendar must retain both non-overlapping commitments');
pass('copies: one physical row and all scheduled calendar events');

// 56: the integrity report detects terminal rows that are still active.
$b = mkBook('integrity_terminal_active');
$c = mkCopies($b, 1)[0];
$u = mkUser();
$badLoanId = loan($b, $c, $u, date('Y-m-d', strtotime('-5 days')), date('Y-m-d', strtotime('-1 day')), 'restituito', 1);
$issues = (new DataIntegrity($db))->verifyDataConsistency();
$found = false;
foreach ($issues as $issue) {
    if (($issue['type'] ?? '') === 'terminated_loan_active' && str_contains((string) ($issue['message'] ?? ''), (string) $badLoanId)) {
        $found = true;
        break;
    }
}
assertEq(true, $found, 'integrity report must identify active terminal loan row');
pass('integrity: active terminal loan is reported');

// 57: every non-overlapping future hold moves off a damaged physical copy.
$b = mkBook('reassign_damaged_copy');
[$damagedCopy, $replacementCopy] = mkCopies($b, 2);
$u1 = mkUser();
$u2 = mkUser();
$u3 = mkUser();
$hold1 = loan($b, $damagedCopy, $u1, date('Y-m-d', strtotime('+50 days')), date('Y-m-d', strtotime('+55 days')), 'prenotato', 1);
$hold2 = loan($b, $damagedCopy, $u2, date('Y-m-d', strtotime('+56 days')), date('Y-m-d', strtotime('+62 days')), 'prenotato', 1);
$hold3 = loan($b, $damagedCopy, $u3, date('Y-m-d', strtotime('+63 days')), date('Y-m-d', strtotime('+69 days')), 'pendente', 0);
$db->query("UPDATE prestiti SET origine = 'prenotazione' WHERE id = {$hold3}");
(new CopyRepository($db))->updateStatus($damagedCopy, 'danneggiato');
$reassignment = new ReservationReassignmentService($db);
$reassignment->reassignOnCopyLost($damagedCopy);
$reassignment->reassignOnCopyLost($damagedCopy);
$reassignment->reassignOnCopyLost($damagedCopy);
$assigned = $db->query("SELECT COUNT(*) AS n FROM prestiti WHERE id IN ({$hold1},{$hold2},{$hold3}) AND copia_id = {$replacementCopy}")->fetch_assoc();
assertEq(3, (int) $assigned['n'], 'active and converted-pending disjoint holds must move to the replacement copy');
pass('copies: damaged copy reassigns active and converted-pending future holds');

// 58: ICS keeps an unreturned overdue event open through today (exclusive end tomorrow).
$b = mkBook('ics_overdue_open');
$c = mkCopies($b, 1, 'prestato')[0];
$u = mkUser();
$icsLoan = loan($b, $c, $u, date('Y-m-d', strtotime('-20 days')), date('Y-m-d', strtotime('-5 days')), 'in_ritardo', 1);
$ics = (new IcsGenerator($db))->generate();
$pattern = '/BEGIN:VEVENT.*?UID:loan-' . $icsLoan . '@pinakes.*?END:VEVENT/s';
if (!preg_match($pattern, $ics, $eventMatch)) {
    throw new \RuntimeException('ICS event for overdue loan not found');
}
$tomorrow = (new \DateTimeImmutable(DateHelper::today()))->modify('+1 day')->format('Ymd');
assertEq(true, str_contains($eventMatch[0], 'DTEND;VALUE=DATE:' . $tomorrow), 'overdue ICS event must remain open through today');
pass('calendar: overdue ICS event remains open through today');

// 59: a title without any physical copy can never remain available.
$b = mkBook('zero_copies_status');
recalc($b);
assertEq('non_disponibile', bookStato($b), 'zero-copy book must derive non_disponibile');
pass('availability: zero physical copies derives non_disponibile');

// 60: overbooking involving OVERDUE loans must be detected. The open-ended
// overdue sentinel used to become endExclusive '10000-01-01', which ksort's
// lexicographic order placed BEFORE every real date, zeroing overdue occupancy
// in the peak scan — two overdue loans on a single-copy book went unreported.
$b = mkBook('integrity_overdue_overbook');
[$oc1, $oc2] = mkCopies($b, 2, 'prestato');
$ou1 = mkUser();
$ou2 = mkUser();
// One overdue loan per physical copy (the trigger forbids two on one copy)…
loan($b, $oc1, $ou1, date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('-10 days')), 'in_ritardo', 1);
loan($b, $oc2, $ou2, date('Y-m-d', strtotime('-20 days')), date('Y-m-d', strtotime('-5 days')), 'in_ritardo', 1);
// …then one copy is damaged: loanable capacity drops to 1 while both overdue
// loans are still physically out → occupancy 2 > capacity 1 for the whole
// open-ended overdue window. (No reassignment: overdue loans are in a user's
// hands, not future holds.)
(new CopyRepository($db))->updateStatus($oc2, 'danneggiato');
$issues = (new DataIntegrity($db))->verifyDataConsistency();
$foundOverbook = false;
foreach ($issues as $issue) {
    if (($issue['type'] ?? '') === 'overbooked_circulation_period'
        && str_contains((string) ($issue['message'] ?? ''), (string) $b)) {
        $foundOverbook = true;
        break;
    }
}
assertEq(true, $foundOverbook, 'integrity report must detect overbooking that involves overdue loans');
pass('integrity: overbooked period with overdue loans is reported');

// 61: copy-loss reassignment must inspect every viable physical copy, not give
// up after five overlapping candidates while a later copy is free.
$b = mkBook('reassign_after_five_conflicts');
$copies = mkCopies($b, 7);
$damagedCopy = array_shift($copies);
$periodStart = date('Y-m-d', strtotime('+90 days'));
$periodEnd = date('Y-m-d', strtotime('+100 days'));
for ($i = 0; $i < 5; $i++) {
    loan($b, $copies[$i], mkUser(), $periodStart, $periodEnd, 'prenotato', 1);
}
$freeReplacement = $copies[5];
$hold = loan($b, $damagedCopy, mkUser(), $periodStart, $periodEnd, 'prenotato', 1);
(new CopyRepository($db))->updateStatus($damagedCopy, 'danneggiato');
(new ReservationReassignmentService($db))->reassignOnCopyLost($damagedCopy);
$assignedCopy = (int) $db->query("SELECT COALESCE(copia_id, 0) FROM prestiti WHERE id = {$hold}")->fetch_row()[0];
assertEq($freeReplacement, $assignedCopy, 'allocator must reach the free physical copy after five conflicts');
pass('copies: reassignment searches beyond five overlapping candidates');

// 62: a copy physically out today can still host a disjoint future hold. The
// derived status must stay 'prestato' until the current loan is returned.
$b = mkBook('reassign_to_currently_loaned_copy');
[$damagedCopy, $loanedReplacement] = mkCopies($b, 2);
$currentStart = date('Y-m-d', strtotime('-2 days'));
$currentEnd = date('Y-m-d', strtotime('+5 days'));
loan($b, $loanedReplacement, mkUser(), $currentStart, $currentEnd, 'in_corso', 1);
(new CopyRepository($db))->updateStatus($loanedReplacement, 'prestato');
$futureStart = date('Y-m-d', strtotime('+10 days'));
$futureEnd = date('Y-m-d', strtotime('+20 days'));
$futureHold = loan($b, $damagedCopy, mkUser(), $futureStart, $futureEnd, 'prenotato', 1);
(new CopyRepository($db))->updateStatus($damagedCopy, 'danneggiato');
(new ReservationReassignmentService($db))->reassignOnCopyLost($damagedCopy);
$futureCopy = (int) $db->query("SELECT COALESCE(copia_id, 0) FROM prestiti WHERE id = {$futureHold}")->fetch_row()[0];
assertEq($loanedReplacement, $futureCopy, 'disjoint future hold must reuse the currently loaned physical copy');
assertEq('prestato', copyStato($loanedReplacement), 'current physical loan must retain status priority after future reassignment');
pass('copies: disjoint future hold reuses a loaned copy without corrupting its current status');

// 63: legacy reservations with NULL data_inizio_richiesta use the canonical
// deadline fallback and must still promote to a copy-linked pending loan.
$b = mkBook('legacy_null_start_promotion');
$copy = mkCopies($b, 1)[0];
$u = mkUser();
$legacyStart = DateHelper::today();
$legacyEnd = (new \DateTimeImmutable($legacyStart))->modify('+7 days')->format('Y-m-d');
$legacyDeadline = $legacyStart . ' 23:59:59';
$stmt = $db->prepare(
    "INSERT INTO prenotazioni
        (libro_id, utente_id, data_prenotazione, data_inizio_richiesta, data_fine_richiesta,
         data_scadenza_prenotazione, queue_position, stato)
     VALUES (?, ?, NOW(), NULL, ?, ?, 1, 'attiva')"
);
$stmt->bind_param('iiss', $b, $u, $legacyEnd, $legacyDeadline);
$stmt->execute();
$legacyReservation = (int) $db->insert_id;
$stmt->close();
$db->begin_transaction();
$manager = new ReservationManager($db);
$manager->setExternalTransaction(true);
$promoted = $manager->processBookAvailability($b);
$db->commit();
$legacyState = (string) $db->query("SELECT stato FROM prenotazioni WHERE id = {$legacyReservation}")->fetch_row()[0];
$legacyLoan = $db->query(
    "SELECT stato, copia_id, data_prestito FROM prestiti
     WHERE libro_id = {$b} AND utente_id = {$u} AND origine = 'prenotazione'
     ORDER BY id DESC LIMIT 1"
)->fetch_assoc();
assertEq(true, $promoted, 'legacy NULL-start reservation must be selected for promotion');
assertEq('completata', $legacyState, 'legacy reservation must become completed');
assertEq($copy, (int) ($legacyLoan['copia_id'] ?? 0), 'promoted legacy reservation must link the physical copy');
assertEq($legacyStart, (string) ($legacyLoan['data_prestito'] ?? ''), 'legacy fallback must become the loan start date');
pass('reservations: legacy NULL start promotes into a physical-copy-linked loan');

// 64: cancelling a queue reservation from the user frontend must immediately
// promote the next eligible row and link the freed physical capacity.
$b = mkBook('user_cancel_promotes_queue');
$copy = mkCopies($b, 1)[0];
$cancelUser = mkUser();
$nextUser = mkUser();
$queueStart = DateHelper::today();
$queueEnd = (new \DateTimeImmutable($queueStart))->modify('+7 days')->format('Y-m-d');
$queueStartDt = $queueStart . ' 00:00:00';
$queueEndDt = $queueEnd . ' 23:59:59';
$insertReservation = $db->prepare(
    "INSERT INTO prenotazioni
        (libro_id, utente_id, data_prenotazione, data_scadenza_prenotazione,
         data_inizio_richiesta, data_fine_richiesta, queue_position, stato)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'attiva')"
);
$firstPosition = 1;
$insertReservation->bind_param('iissssi', $b, $cancelUser, $queueStartDt, $queueEndDt, $queueStart, $queueEnd, $firstPosition);
$insertReservation->execute();
$cancelReservationId = (int) $db->insert_id;
$secondPosition = 2;
$insertReservation->bind_param('iissssi', $b, $nextUser, $queueStartDt, $queueEndDt, $queueStart, $queueEnd, $secondPosition);
$insertReservation->execute();
$nextReservationId = (int) $db->insert_id;
$insertReservation->close();
$_SESSION['user'] = ['id' => $cancelUser, 'tipo_utente' => 'standard'];
$cancelRequest = (new ServerRequestFactory())
    ->createServerRequest('POST', '/reservation/cancel')
    ->withParsedBody(['reservation_id' => $cancelReservationId]);
$cancelResponse = (new UserActionsController())->cancelReservation($cancelRequest, new SlimResponse(), $db);
unset($_SESSION['user']);
$cancelledState = (string) $db->query("SELECT stato FROM prenotazioni WHERE id = {$cancelReservationId}")->fetch_row()[0];
$nextState = (string) $db->query("SELECT stato FROM prenotazioni WHERE id = {$nextReservationId}")->fetch_row()[0];
$promotedLoan = $db->query(
    "SELECT copia_id, stato FROM prestiti
     WHERE libro_id = {$b} AND utente_id = {$nextUser} AND origine = 'prenotazione'
     ORDER BY id DESC LIMIT 1"
)->fetch_assoc();
assertEq(302, $cancelResponse->getStatusCode(), 'user cancellation must complete with the normal redirect');
assertEq('annullata', $cancelledState, 'cancelled reservation must be terminal');
assertEq('completata', $nextState, 'next eligible reservation must be promoted immediately');
assertEq($copy, (int) ($promotedLoan['copia_id'] ?? 0), 'promoted queue row must link the freed physical copy');
pass('reservations: user cancellation immediately promotes and links the next queue row');

// 65: queue selection must scan beyond an arbitrary batch of ineligible
// patrons. Suspended reservations are paused: they retain FIFO identity for
// recovery but do not consume capacity or starve an eligible later reader.
$b = mkBook('queue_beyond_ineligible_batch');
$queueCopies = mkCopies($b, 26);
$queueStart = DateHelper::today();
$queueEnd = (new \DateTimeImmutable($queueStart))->modify('+7 days')->format('Y-m-d');
$queueStartDt = $queueStart . ' 00:00:00';
$queueEndDt = $queueEnd . ' 23:59:59';
$insertReservation = $db->prepare(
    "INSERT INTO prenotazioni
        (libro_id, utente_id, data_prenotazione, data_scadenza_prenotazione,
         data_inizio_richiesta, data_fine_richiesta, queue_position, stato)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'attiva')"
);
$ineligibleReservationIds = [];
for ($position = 1; $position <= 25; $position++) {
    $userId = mkUser();
    $db->query("UPDATE utenti SET stato = 'sospeso' WHERE id = {$userId}");
    $insertReservation->bind_param('iissssi', $b, $userId, $queueStartDt, $queueEndDt, $queueStart, $queueEnd, $position);
    $insertReservation->execute();
    $ineligibleReservationIds[] = (int) $db->insert_id;
}
$eligibleUserId = mkUser();
$eligiblePosition = 26;
$insertReservation->bind_param('iissssi', $b, $eligibleUserId, $queueStartDt, $queueEndDt, $queueStart, $queueEnd, $eligiblePosition);
$insertReservation->execute();
$eligibleReservationId = (int) $db->insert_id;
$insertReservation->close();
$queueCapacity = new CapacityService($db);
$temporarilyUnavailableCopy = $queueCopies[25];
(new CopyRepository($db))->updateStatus($temporarilyUnavailableCopy, 'manutenzione');
assertEq(25, $queueCapacity->totalCopies($b), 'one maintenance copy must reduce queue capacity from 26 to 25');
assertEq(1, $queueCapacity->occupiedCount($b, $queueStart, $queueEnd), 'only the eligible queue row contributes to OCC');
assertEq(
    0,
    $queueCapacity->occupiedCount($b, $queueStart, $queueEnd, excludeReservationId: $eligibleReservationId),
    'suspended reservations are paused and consume no capacity'
);
assertEq(
    true,
    $queueCapacity->hasFreeCapacity(
        $b,
        $queueStart,
        $queueEnd,
        excludeReservationId: $eligibleReservationId,
        excludeReservationsAfterQueuePos: $eligiblePosition
    ),
    'position 26 sees free capacity despite the earlier suspended accounts'
);
$promoted = (new ReservationManager($db))->processBookAvailability($b);
$eligibleState = (string) $db->query("SELECT stato FROM prenotazioni WHERE id = {$eligibleReservationId}")->fetch_row()[0];
$ineligibleIdsSql = implode(',', $ineligibleReservationIds);
$ineligibleActive = (int) $db->query("SELECT COUNT(*) FROM prenotazioni WHERE id IN ({$ineligibleIdsSql}) AND stato = 'attiva'")->fetch_row()[0];
$eligibleLoan = $db->query("SELECT COALESCE(copia_id, 0) AS copia_id, stato, attivo FROM prestiti WHERE libro_id = {$b} AND utente_id = {$eligibleUserId} ORDER BY id DESC LIMIT 1")->fetch_assoc();
$eligibleCopy = (int) ($eligibleLoan['copia_id'] ?? 0);
assertEq(true, $promoted, 'eligible queue position after 25 suspended patrons is promoted');
assertEq('completata', $eligibleState, 'eligible queue row must become completed');
assertEq(25, $ineligibleActive, 'ineligible rows must remain active for later account recovery');
assertEq(true, in_array($eligibleCopy, $queueCopies, true), 'eligible row must receive one of the remaining physical copies');
assertEq('pendente', (string) ($eligibleLoan['stato'] ?? ''), 'promotion must replace the queue row with a pending copy-bound hold');
assertEq(0, (int) ($eligibleLoan['attivo'] ?? -1), 'the pending promoted hold remains inactive until approval');
assertEq(1, $queueCapacity->occupiedCount($b, $queueStart, $queueEnd), 'only the promoted copy-bound hold consumes capacity');
assertEq(true, $queueCapacity->hasFreeCapacity($b, $queueStart, $queueEnd), 'paused reservations leave the remaining physical capacity available');
pass('reservations: suspended queue rows are paused while scanning beyond position 25');

// 66: a patron already at the active-loan cap is paused without blocking the
// next eligible queue position.
$edgeSettings->set('loans', 'max_active_loans_per_user', '1');
$atCapUser = mkUser();
$nextUnderCapUser = mkUser();
$otherBook = mkBook('queue_cap_existing_loan');
$otherCopy = mkCopies($otherBook, 1)[0];
loan($otherBook, $otherCopy, $atCapUser, DateHelper::today(), $queueEnd, 'in_corso', 1);
$capBook = mkBook('queue_cap_skip');
$capCopy = mkCopies($capBook, 1)[0];
$capInsert = $db->prepare(
    "INSERT INTO prenotazioni
        (libro_id, utente_id, data_prenotazione, data_scadenza_prenotazione,
         data_inizio_richiesta, data_fine_richiesta, queue_position, stato)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'attiva')"
);
$capPosition = 1;
$capInsert->bind_param('iissssi', $capBook, $atCapUser, $queueStartDt, $queueEndDt, $queueStart, $queueEnd, $capPosition);
$capInsert->execute();
$atCapReservation = (int) $db->insert_id;
$capPosition = 2;
$capInsert->bind_param('iissssi', $capBook, $nextUnderCapUser, $queueStartDt, $queueEndDt, $queueStart, $queueEnd, $capPosition);
$capInsert->execute();
$underCapReservation = (int) $db->insert_id;
$capInsert->close();
$capPromoted = (new ReservationManager($db))->processBookAvailability($capBook);
$atCapState = (string) $db->query("SELECT stato FROM prenotazioni WHERE id = {$atCapReservation}")->fetch_row()[0];
$underCapState = (string) $db->query("SELECT stato FROM prenotazioni WHERE id = {$underCapReservation}")->fetch_row()[0];
$underCapCopy = (int) $db->query("SELECT COALESCE(copia_id, 0) FROM prestiti WHERE libro_id = {$capBook} AND utente_id = {$nextUnderCapUser} ORDER BY id DESC LIMIT 1")->fetch_row()[0];
assertEq(true, $capPromoted, 'promotion scans past a patron at the active-loan cap');
assertEq('attiva', $atCapState, 'at-cap reservation retains its FIFO identity for later recovery');
assertEq('completata', $underCapState, 'next eligible reservation is completed');
assertEq($capCopy, $underCapCopy, 'eligible reader receives the physical copy');
$edgeSettings->set('loans', 'max_active_loans_per_user', (string) ($originalMaxActiveLoans ?? '0'));
pass('reservations: active-loan cap pauses a row without head-of-line blocking');

// 67: eligibility is checked again when a scheduled loan reaches its start.
$scheduledEligibilityBook = mkBook('scheduled_eligibility_recheck');
$scheduledEligibilityCopy = mkCopies($scheduledEligibilityBook, 1)[0];
$scheduledEligibilityUser = mkUser();
$scheduledEligibilityLoan = loan(
    $scheduledEligibilityBook,
    $scheduledEligibilityCopy,
    $scheduledEligibilityUser,
    DateHelper::today(),
    $queueEnd,
    'prenotato',
    1
);
$db->query("UPDATE utenti SET stato = 'sospeso' WHERE id = {$scheduledEligibilityUser}");
(new MaintenanceService($db))->activateScheduledLoans();
assertEq('prenotato', (string) $db->query("SELECT stato FROM prestiti WHERE id = {$scheduledEligibilityLoan}")->fetch_row()[0], 'suspended patron is not advanced to pickup-ready');
$db->query("UPDATE utenti SET stato = 'attivo' WHERE id = {$scheduledEligibilityUser}");
(new MaintenanceService($db))->activateScheduledLoans();
assertEq('da_ritirare', (string) $db->query("SELECT stato FROM prestiti WHERE id = {$scheduledEligibilityLoan}")->fetch_row()[0], 'scheduled loan recovers automatically after eligibility is restored');
pass('schedules: eligibility is rechecked at activation and can recover');

// 68: a scheduled hold pinned to an unavailable physical copy must move to a
// free sibling copy instead of remaining stuck forever.
$b = mkBook('scheduled_pinned_copy_fallback');
[$pinnedCopy, $alternativeCopy] = mkCopies($b, 2);
$scheduledUser = mkUser();
$scheduledStart = DateHelper::today();
$scheduledEnd = (new \DateTimeImmutable($scheduledStart))->modify('+7 days')->format('Y-m-d');
$scheduledLoan = loan($b, $pinnedCopy, $scheduledUser, $scheduledStart, $scheduledEnd, 'prenotato', 1);
(new CopyRepository($db))->updateStatus($pinnedCopy, 'manutenzione');
(new MaintenanceService($db))->activateScheduledLoans();
$activated = $db->query("SELECT stato, copia_id FROM prestiti WHERE id = {$scheduledLoan}")->fetch_assoc();
assertEq('da_ritirare', (string) ($activated['stato'] ?? ''), 'scheduled loan must become ready for pickup');
assertEq($alternativeCopy, (int) ($activated['copia_id'] ?? 0), 'scheduled loan must move to the free sibling copy');
assertEq('manutenzione', copyStato($pinnedCopy), 'unavailable pinned copy state must remain untouched');
pass('schedules: unavailable pinned copy falls back to a free sibling');

/* ==========================================================================
 * Done
 * ====================================================================== */
cleanup($db);
echo "ALL 68 PASS\n";
$db->close();

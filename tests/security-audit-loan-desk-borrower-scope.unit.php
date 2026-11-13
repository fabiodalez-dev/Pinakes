<?php
declare(strict_types=1);

/**
 * Security audit 2026-11, finding
 * app/Controllers/PrestitiController.php:processReturn:ledger-account-scope-bypass.
 *
 * A custom role holding loans.manage has the borrower scope "reader accounts
 * only" (Permissions::accountScopeSql(SCOPE_BORROWER)). The pickers and the
 * ledger page enforced it, the loan-desk writes did not: such a role created
 * loans and reservations for a Staff or administrator account, reassigned a
 * loan to one, and set a loss penalty or a fine waiver on their returns. The
 * operator-typed penalty could also exceed PatronLedger::MAX_AMOUNT_CENTS.
 *
 * Part 1 is DB-free (penalty cap, Permissions::mayActOnBorrower short-circuits
 * and fail-closed path). Part 2 drives the real controller actions against a
 * database and is skipped, with a message, when none is configured: it reads
 * E2E_DB_* / DB_* from the environment, else the project's .env. It creates
 * its own tagged rows and deletes them at the end.
 *
 * Run:  php tests/security-audit-loan-desk-borrower-scope.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Controllers\PrestitiController;
use App\Controllers\ReservationsAdminController;
use App\Services\PatronLedger;
use App\Support\Permissions as P;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$post = static fn (array $body) => (new ServerRequestFactory())->createServerRequest('POST', 'http://library.test/')->withParsedBody($body);
$location = static fn (Response|\Psr\Http\Message\ResponseInterface $r): string => $r->getHeaderLine('Location');

echo "1. DB-free\n";
$unconnected = mysqli_init();
$_SESSION = ['user' => ['id' => 1, 'tipo_utente' => 'admin', 'stato' => 'attivo']];
$attempt = static function (string $sanzione) use ($post, $unconnected, $location): string {
    try {
        $r = (new PrestitiController())->processReturn($post(['stato' => 'perso', 'sanzione' => $sanzione]), new Response(), $unconnected, 1);
        return $location($r);
    } catch (\Throwable) {
        return 'reached-database'; // validation passed; the unconnected handle stops it
    }
};
$cap = PatronLedger::MAX_AMOUNT_CENTS;
$above = number_format(($cap + 1) / 100, 2, '.', '');
$atCap = number_format($cap / 100, 2, '.', '');
$check(str_contains($attempt($above), 'error=invalid_penalty'), "a penalty of {$above} (above the ledger cap) is refused before any write");
$check(str_contains($attempt('99999999.99'), 'error=invalid_penalty'), 'the old 8-digit maximum is refused too');
$check($attempt($atCap) === 'reached-database', "a penalty of {$atCap} (the cap) is accepted");
$check($attempt('50') === 'reached-database', 'an ordinary penalty is accepted');

$check(P::mayActOnBorrower($unconnected, 42), 'an administrator acts on any account (no lookup)');
$_SESSION = ['user' => ['id' => 2, 'tipo_utente' => 'staff', 'stato' => 'attivo']];
$check(P::mayActOnBorrower($unconnected, 42), 'Staff acts on any account (no lookup)');
$_SESSION = ['user' => ['id' => 3, 'tipo_utente' => 'premium', 'stato' => 'attivo', 'permissions' => ['backend.access', 'loans.manage']]];
$check(!P::mayActOnBorrower($unconnected, 42), 'a custom role fails closed when the lookup cannot run');
$check(!P::mayActOnBorrower($unconnected, 0), 'a custom role never matches a missing borrower id');
$_SESSION = [];

echo "2. Controller actions against a database\n";
$envFile = is_file($root . '/.env') ? Dotenv\Dotenv::parse((string) file_get_contents($root . '/.env')) : [];
$setting = static function (string $key, string $default = '') use ($envFile): string {
    foreach (['E2E_' . $key, $key] as $name) {
        $value = getenv($name);
        if ($value !== false && $value !== '') { return $value; }
    }
    return (string) ($envFile[$key] ?? $default);
};
$db = null;
if ($setting('DB_NAME') !== '' && $setting('DB_USER') !== '') {
    mysqli_report(MYSQLI_REPORT_OFF);
    $socket = $setting('DB_SOCKET');
    $candidate = mysqli_init();
    $ok = @$candidate->real_connect($setting('DB_HOST', '127.0.0.1'), $setting('DB_USER'), $setting('DB_PASS'), $setting('DB_NAME'), (int) $setting('DB_PORT', '3306'), $socket !== '' && file_exists($socket) ? $socket : null);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    if ($ok) {
        $candidate->set_charset('utf8mb4');
        $db = $candidate;
    }
}
if ($db === null) {
    echo "  SKIP no database configured (E2E_DB_* / DB_* or .env): controller checks not run\n";
    echo PHP_EOL . "Passed: {$passed}, Failed: {$failed}" . PHP_EOL;
    exit($failed === 0 ? 0 : 1);
}

$tag = 'secA2-' . bin2hex(random_bytes(3));
$users = [];
$bookId = 0;
$roleId = 0;
$insertUser = static function (string $type, ?int $role = null, string $stato = 'attivo') use ($db, $tag, &$users): int {
    $code = substr($tag . count($users), 0, 20);
    $email = $code . '@example.test';
    $stmt = $db->prepare("INSERT INTO utenti (nome, cognome, email, password, codice_tessera, tipo_utente, stato, email_verificata, ruolo_id) VALUES ('Audit', 'Scope', ?, 'unused', ?, ?, ?, 1, ?)");
    $stmt->bind_param('ssssi', $email, $code, $type, $stato, $role);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $users[] = $id;
};
$count = static function (string $sql, array $ids) use ($db): int {
    $stmt = $db->prepare($sql);
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $n = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $n;
};
$insertLoan = static function (int $userId, int $copyId) use ($db, &$bookId): int {
    $today = \App\Support\DateHelper::today();
    $due = (new DateTimeImmutable($today))->modify('+14 days')->format('Y-m-d');
    $stmt = $db->prepare("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo) VALUES (?, ?, ?, ?, ?, 'in_corso', 1)");
    $stmt->bind_param('iiiss', $bookId, $copyId, $userId, $today, $due);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    $db->query("UPDATE copie SET stato = 'prestato' WHERE id = " . $copyId);
    return $id;
};
$copies = [];
try {
    $roleName = $tag . '-desk';
    $permissions = json_encode(['backend.access', 'loans.manage']);
    $stmt = $db->prepare('INSERT INTO ruoli (nome, permessi) VALUES (?, ?)');
    $stmt->bind_param('ss', $roleName, $permissions);
    $stmt->execute();
    $roleId = (int) $stmt->insert_id;
    $stmt->close();

    $admin = $insertUser('admin');
    $staff = $insertUser('staff');
    $reader = $insertUser('standard');
    $suspended = $insertUser('standard', null, 'sospeso');
    $operator = $insertUser('premium', $roleId);

    $title = $tag . ' book';
    $stmt = $db->prepare('INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES (?, 3, 3)');
    $stmt->bind_param('s', $title);
    $stmt->execute();
    $bookId = (int) $stmt->insert_id;
    $stmt->close();
    for ($i = 1; $i <= 3; $i++) {
        $inv = $tag . '-C' . $i;
        $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
        $stmt->bind_param('is', $bookId, $inv);
        $stmt->execute();
        $copies[] = (int) $stmt->insert_id;
        $stmt->close();
    }

    $actAs = static function (int $id) use ($db): void {
        \App\Support\SessionRoleRevalidator::reset();
        $_SESSION = ['user' => P::viewer($id, $db)];
    };

    echo "  Permissions::mayActOnBorrower\n";
    $actAs($operator);
    $check(P::mayActOnBorrower($db, $reader) && P::mayActOnBorrower($db, $operator), 'custom loan-desk role: reader accounts are in scope');
    $check(!P::mayActOnBorrower($db, $staff), 'custom loan-desk role: a Staff account is out of scope');
    $check(!P::mayActOnBorrower($db, $admin), 'custom loan-desk role: an administrator account is out of scope');
    $check(!P::mayActOnBorrower($db, 2147483000), 'custom loan-desk role: a missing account is out of scope');
    $actAs($staff);
    $check(P::mayActOnBorrower($db, $admin) && P::mayActOnBorrower($db, $staff), 'Staff keeps every account');

    echo "  PrestitiController::store\n";
    $today = \App\Support\DateHelper::today();
    $due = (new DateTimeImmutable($today))->modify('+14 days')->format('Y-m-d');
    $store = static function (int $borrower) use ($post, $db, $bookId, $today, $due): string {
        $token = \App\Support\OneTimeFormToken::issue('loan.create');
        $r = (new PrestitiController())->store($post(['loan_submission_token' => $token, 'utente_id' => (string) $borrower, 'libro_id' => (string) $bookId, 'data_prestito' => $today, 'data_scadenza' => $due]), new Response(), $db);
        return $r->getHeaderLine('Location');
    };
    $actAs($operator);
    $check(str_contains($store($staff), 'error=user_not_found'), 'a custom role cannot create a loan for a Staff account');
    $check(str_contains($store($admin), 'error=user_not_found'), '...nor for an administrator');
    $check($count('SELECT COUNT(*) FROM prestiti WHERE utente_id IN (?, ?)', [$staff, $admin]) === 0, 'no loan row was written for them');
    $check(str_contains($store($suspended), 'error=user_suspended'), 'a reader in scope reaches the eligibility checks (control)');

    echo "  PrestitiController::update (borrower change)\n";
    $readerLoan = $insertLoan($reader, $copies[0]);
    $actAs($operator);
    $r = (new PrestitiController())->update($post(['utente_id' => (string) $staff]), new Response(), $db, $readerLoan);
    $check(str_contains($location($r), 'error=user_not_found'), 'a custom role cannot reassign a loan to a Staff account');
    $check($count('SELECT COUNT(*) FROM prestiti WHERE id = ? AND utente_id = ?', [$readerLoan, $reader]) === 1, 'the loan keeps its borrower');
    $r = (new PrestitiController())->update($post(['utente_id' => (string) $suspended]), new Response(), $db, $readerLoan);
    $check(str_contains($location($r), 'error=user_suspended'), 'reassigning to a reader in scope reaches the eligibility checks (control)');

    echo "  PrestitiController::processReturn\n";
    $staffLoan = $insertLoan($staff, $copies[1]);
    $return = static function (array $body, int $loanId) use ($post, $db): string {
        return (new PrestitiController())->processReturn($post($body), new Response(), $db, $loanId)->getHeaderLine('Location');
    };
    $actAs($operator);
    $check(str_contains($return(['stato' => 'perso', 'sanzione' => '50'], $staffLoan), 'error=user_not_found'), 'a custom role cannot set a loss penalty on a Staff account');
    $check(str_contains($return(['stato' => 'restituito', 'waive_fine' => '1', 'waive_reason' => 'audit'], $staffLoan), 'error=user_not_found'), '...nor waive its fine');
    $check($count("SELECT COUNT(*) FROM prestiti WHERE id = ? AND attivo = 1 AND sanzione = 0", [$staffLoan]) === 1, 'the Staff loan stays open with no penalty');
    $plain = $return(['stato' => 'restituito'], $staffLoan);
    $check(!str_contains($plain, 'error='), 'a plain return of that loan still goes through (the copy comes back)');
    $check($count('SELECT COUNT(*) FROM prestiti WHERE id = ? AND attivo = 0', [$staffLoan]) === 1, 'the Staff loan is closed');
    $readerLoan2 = $insertLoan($reader, $copies[2]);
    $actAs($operator);
    $readerReturn = $return(['stato' => 'perso', 'sanzione' => '12.50'], $readerLoan2);
    $check(!str_contains($readerReturn, 'error='), 'a penalty on a reader in scope is recorded (control)');
    $check($count('SELECT COUNT(*) FROM prestiti WHERE id = ? AND attivo = 0 AND sanzione = 12.50', [$readerLoan2]) === 1, 'with the typed amount');

    echo "  ReservationsAdminController::store / update\n";
    $actAs($operator);
    $reserve = static function (int $borrower) use ($post, $db, $bookId): string {
        return (new ReservationsAdminController())->store($post(['libro_id' => (string) $bookId, 'utente_id' => (string) $borrower]), new Response(), $db)->getHeaderLine('Location');
    };
    $check(str_contains($reserve($staff), 'error=user_not_found'), 'a custom role cannot queue a reservation for a Staff account');
    $check($count('SELECT COUNT(*) FROM prenotazioni WHERE utente_id = ?', [$staff]) === 0, 'no reservation row was written');
    $check(str_contains($reserve($suspended), 'error=user_suspended'), 'a reader in scope reaches the eligibility checks (control)');
    $stmt = $db->prepare("INSERT INTO prenotazioni (libro_id, utente_id, data_prenotazione, data_scadenza_prenotazione, queue_position, stato) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 1, 'attiva')");
    $stmt->bind_param('ii', $bookId, $staff);
    $stmt->execute();
    $staffReservation = (int) $stmt->insert_id;
    $stmt->close();
    $r = (new ReservationsAdminController())->update($post(['libro_id' => (string) $bookId, 'stato' => 'annullata']), new Response(), $db, $staffReservation);
    $check(str_contains($location($r), 'error=not_found'), "a custom role cannot edit a Staff account's reservation");
    $check($count("SELECT COUNT(*) FROM prenotazioni WHERE id = ? AND stato = 'attiva'", [$staffReservation]) === 1, 'the reservation is unchanged');
} catch (\Throwable $e) {
    $check(false, 'unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $_SESSION = [];
    if ($users !== []) {
        $in = implode(',', array_map('intval', $users));
        foreach (["DELETE FROM movimenti_lettore WHERE utente_id IN ({$in})", "DELETE FROM prenotazioni WHERE utente_id IN ({$in})", "DELETE FROM prestiti WHERE utente_id IN ({$in})"] as $sql) {
            try { $db->query($sql); } catch (\Throwable) { /* table absent on an older schema */ }
        }
    }
    if ($bookId > 0) {
        try { $db->query('DELETE FROM prestiti WHERE libro_id = ' . $bookId); } catch (\Throwable) {}
        try { $db->query('DELETE FROM copie WHERE libro_id = ' . $bookId); } catch (\Throwable) {}
        try { $db->query('DELETE FROM libri WHERE id = ' . $bookId); } catch (\Throwable) {}
    }
    if ($users !== []) {
        try { $db->query('DELETE FROM utenti WHERE id IN (' . implode(',', array_map('intval', $users)) . ')'); } catch (\Throwable $e) { echo '  cleanup: ' . $e->getMessage() . PHP_EOL; }
    }
    if ($roleId > 0) {
        try { $db->query('DELETE FROM ruoli WHERE id = ' . $roleId); } catch (\Throwable) {}
    }
}

echo PHP_EOL . "Passed: {$passed}, Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

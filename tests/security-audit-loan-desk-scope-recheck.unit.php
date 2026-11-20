<?php
declare(strict_types=1);

/**
 * Security audit 2026-11 (review): the borrower scope of the loan-desk writes is
 * checked before the transaction, on a plain read. An administrator can hand the
 * loan to a Staff account between that read and the row lock, and the write then
 * ran on a loan its operator has no scope over. Every write that locks the row
 * (edit, renew, approve, reject, confirm and cancel the pickup, cancel a
 * reservation) asks again about the account the LOCKED row holds.
 *
 * Deterministic, no timing: a mysqli subclass reassigns the loan in
 * begin_transaction(), which runs after the first scope check and before the lock.
 *
 * Needs a database (E2E_DB_* / DB_* or .env). It creates its own tagged rows and
 * removes them. CI_STRICT_TESTS=1 turns "no database" into a failure.
 *
 * Run:  php tests/security-audit-loan-desk-scope-recheck.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Controllers\LoanApprovalController;
use App\Controllers\PrestitiController;
use App\Support\Permissions as P;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

/** Runs a callback just before the transaction opens: the window between the scope check and the lock. */
final class RacingDb extends mysqli
{
    public ?Closure $beforeBegin = null;

    public function begin_transaction(int $flags = 0, ?string $name = null): bool
    {
        if ($this->beforeBegin !== null) {
            $run = $this->beforeBegin;
            $this->beforeBegin = null;
            $run($this);
        }
        return parent::begin_transaction($flags, $name);
    }
}

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
    $candidate = new RacingDb();
    $ok = @$candidate->real_connect($setting('DB_HOST', '127.0.0.1'), $setting('DB_USER'), $setting('DB_PASS'), $setting('DB_NAME'), (int) $setting('DB_PORT', '3306'), $socket !== '' && file_exists($socket) ? $socket : null);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    if ($ok) {
        $candidate->set_charset('utf8mb4');
        $db = $candidate;
    }
}
if ($db === null) {
    if (getenv('CI_STRICT_TESTS') === '1') {
        fwrite(STDERR, "FAIL: no database (CI_STRICT_TESTS=1 forbids skipping)\n");
        exit(1);
    }
    echo "  SKIP no database configured\n";
    exit(0);
}

$post = static fn (array $body) => (new ServerRequestFactory())->createServerRequest('POST', 'http://library.test/')->withParsedBody($body);
$location = static fn (\Psr\Http\Message\ResponseInterface $r): string => $r->getHeaderLine('Location');
$json = static function (\Psr\Http\Message\ResponseInterface $r): array {
    return ['status' => $r->getStatusCode(), 'data' => json_decode((string) $r->getBody(), true) ?: []];
};

$tag = 'secRL-' . bin2hex(random_bytes(3));
$users = [];
$bookId = 0;
$roleId = 0;
$copies = [];
try {
    $insertUser = static function (string $type, ?int $role = null) use ($db, $tag, &$users): int {
        $code = substr($tag . count($users), 0, 20);
        $email = $code . '@example.test';
        $stmt = $db->prepare("INSERT INTO utenti (nome, cognome, email, password, codice_tessera, tipo_utente, stato, email_verificata, ruolo_id) VALUES ('Audit', 'Recheck', ?, 'unused', ?, ?, 'attivo', 1, ?)");
        $stmt->bind_param('sssi', $email, $code, $type, $role);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $users[] = $id;
    };
    $roleName = $tag . '-desk';
    $permissions = json_encode(['backend.access', 'loans.manage']);
    $stmt = $db->prepare('INSERT INTO ruoli (nome, permessi) VALUES (?, ?)');
    $stmt->bind_param('ss', $roleName, $permissions);
    $stmt->execute();
    $roleId = (int) $stmt->insert_id;
    $stmt->close();

    $staff = $insertUser('staff');
    $reader = $insertUser('standard');
    $operator = $insertUser('premium', $roleId);

    $title = $tag . ' book';
    $stmt = $db->prepare('INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES (?, 8, 8)');
    $stmt->bind_param('s', $title);
    $stmt->execute();
    $bookId = (int) $stmt->insert_id;
    $stmt->close();
    for ($i = 1; $i <= 8; $i++) {
        $inv = $tag . '-C' . $i;
        $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
        $stmt->bind_param('is', $bookId, $inv);
        $stmt->execute();
        $copies[] = (int) $stmt->insert_id;
        $stmt->close();
    }

    $today = \App\Support\DateHelper::today();
    $due = (new DateTimeImmutable($today))->modify('+14 days')->format('Y-m-d');
    $insertLoan = static function (int $userId, ?int $copyId, string $stato, int $attivo) use ($db, &$bookId, $today, $due): int {
        $stmt = $db->prepare('INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iiisssi', $bookId, $copyId, $userId, $today, $due, $stato, $attivo);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    };
    $one = static function (string $sql, int $id) use ($db): ?array {
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    };
    $actAsOperator = static function () use ($db, $operator): void {
        \App\Support\SessionRoleRevalidator::reset();
        $_SESSION = ['user' => P::viewer($operator, $db)];
    };
    /** Hand $table row $id to $to in the window between the scope check and the lock. */
    $handOverAtBegin = static function (string $table, int $id, int $to) use ($db): void {
        $db->beforeBegin = static function (mysqli $c) use ($table, $id, $to): void {
            $c->query("UPDATE {$table} SET utente_id = {$to} WHERE id = {$id}");
        };
    };
    $ownerOf = static function (string $table, int $id) use ($db): int {
        return (int) $db->query("SELECT utente_id FROM {$table} WHERE id = {$id}")->fetch_row()[0];
    };
    $approval = new LoanApprovalController();

    echo "Control: with no race an in-scope reader loan is not refused by the scope\n";
    $actAsOperator();
    $control = $insertLoan($reader, $copies[0], 'pendente', 0);
    $a = $json($approval->rejectLoan($post(['loan_id' => (string) $control, 'reason' => 'audit']), new Response(), $db));
    $check($a['status'] === 200 && ($a['data']['success'] ?? null) === true, 'rejecting a reader request goes through');

    // Built-in Staff can act on this borrower both before and after the hand-over.
    // Only the separation-of-duties check on the locked row can refuse the write.
    \App\Support\SessionRoleRevalidator::reset();
    $_SESSION = ['user' => P::viewer($staff, $db)];
    $own = $insertLoan($reader, null, 'pendente', 0);
    $handOverAtBegin('prestiti', $own, $staff);
    $a = $json($approval->rejectLoan($post(['loan_id' => (string) $own, 'reason' => 'audit']), new Response(), $db));
    $check($a['status'] === 403 && ($a['data']['code'] ?? '') === 'self_dealing', 'reject checks the locked borrower against the operator');
    $check(($one('SELECT stato FROM prestiti WHERE id = ?', $own)['stato'] ?? '') === 'pendente', 'the operator’s request stays pending');

    echo "The loan is handed to a Staff account after the first scope check\n";
    $actAsOperator();
    $pending = $insertLoan($reader, null, 'pendente', 0);
    $handOverAtBegin('prestiti', $pending, $staff);
    $a = $json($approval->approveLoan($post(['loan_id' => (string) $pending]), new Response(), $db));
    $check($a['status'] === 404 && ($a['data']['success'] ?? null) === false, 'approve: answered as not found');
    $check(($one('SELECT stato FROM prestiti WHERE id = ?', $pending)['stato'] ?? '') === 'pendente', 'approve: the request is still pending');

    $actAsOperator();
    $pending2 = $insertLoan($reader, null, 'pendente', 0);
    $handOverAtBegin('prestiti', $pending2, $staff);
    $a = $json($approval->rejectLoan($post(['loan_id' => (string) $pending2, 'reason' => 'audit']), new Response(), $db));
    $check($a['status'] === 404, 'reject: answered as not found');
    $check(($one('SELECT stato FROM prestiti WHERE id = ?', $pending2)['stato'] ?? '') === 'pendente', 'reject: the request is still pending');

    $actAsOperator();
    $pickup = $insertLoan($reader, $copies[1], 'da_ritirare', 1);
    $handOverAtBegin('prestiti', $pickup, $staff);
    $a = $json($approval->confirmPickup($post(['loan_id' => (string) $pickup]), new Response(), $db));
    $check($a['status'] === 404, 'confirm pickup: answered as not found');
    $check(($one('SELECT stato FROM prestiti WHERE id = ?', $pickup)['stato'] ?? '') === 'da_ritirare', 'confirm pickup: the loan still waits for pickup');

    $actAsOperator();
    $pickup2 = $insertLoan($reader, $copies[2], 'da_ritirare', 1);
    $handOverAtBegin('prestiti', $pickup2, $staff);
    $a = $json($approval->cancelPickup($post(['loan_id' => (string) $pickup2, 'reason' => 'audit']), new Response(), $db));
    $check($a['status'] === 404, 'cancel pickup: answered as not found');
    $check(($one('SELECT stato FROM prestiti WHERE id = ?', $pickup2)['stato'] ?? '') === 'da_ritirare', 'cancel pickup: the loan still waits for pickup');

    $actAsOperator();
    $stmt = $db->prepare("INSERT INTO prenotazioni (libro_id, utente_id, data_prenotazione, data_scadenza_prenotazione, queue_position, stato) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 1, 'attiva')");
    $stmt->bind_param('ii', $bookId, $reader);
    $stmt->execute();
    $reservation = (int) $stmt->insert_id;
    $stmt->close();
    $handOverAtBegin('prenotazioni', $reservation, $staff);
    $a = $json($approval->cancelReservation($post(['reservation_id' => (string) $reservation, 'reason' => 'audit']), new Response(), $db));
    $check($a['status'] === 404, 'cancel reservation: answered as not found');
    $check(($one('SELECT stato FROM prenotazioni WHERE id = ?', $reservation)['stato'] ?? '') === 'attiva', 'cancel reservation: the reservation is still active');

    $actAsOperator();
    $editable = $insertLoan($reader, $copies[3], 'in_corso', 1);
    $before = $one('SELECT data_scadenza, renewals FROM prestiti WHERE id = ?', $editable);
    $later = (new DateTimeImmutable((string) $before['data_scadenza']))->modify('+7 days')->format('Y-m-d');
    $handOverAtBegin('prestiti', $editable, $staff);
    $r = (new PrestitiController())->update($post(['data_prestito' => $today, 'data_scadenza' => $later]), new Response(), $db, $editable);
    $check(str_contains($location($r), 'error=loan_not_found'), 'edit dates: answered as not found');
    $check($one('SELECT data_scadenza, renewals FROM prestiti WHERE id = ?', $editable) === $before, 'edit dates: the due date is unchanged');

    $actAsOperator();
    $renewable = $insertLoan($reader, $copies[4], 'in_corso', 1);
    $before = $one('SELECT data_scadenza, renewals FROM prestiti WHERE id = ?', $renewable);
    $handOverAtBegin('prestiti', $renewable, $staff);
    $r = (new PrestitiController())->renew($post([]), new Response(), $db, $renewable);
    $check(str_contains($location($r), 'error=loan_not_found'), 'renew: answered as not found');
    $check($one('SELECT data_scadenza, renewals FROM prestiti WHERE id = ?', $renewable) === $before, 'renew: the due date and the renewal count are unchanged');
    $check($ownerOf('prestiti', $renewable) === $staff, '(the hand-over really happened in the window, so the checks above are not vacuous)');

    // Review of PR #536: the scope check inside the transaction was a plain SELECT.
    // Under REPEATABLE READ it answers from the snapshot taken at the transaction's
    // first read, so a borrower promoted to Staff after that point still looked
    // like a reader. The in-transaction callers ask with a locking read, which sees
    // the latest committed row and holds it until commit.
    echo "A role change that lands after the transaction's snapshot is still seen\n";
    $actAsOperator();
    $promoted = $insertUser('standard');
    $socket = $setting('DB_SOCKET');
    mysqli_report(MYSQLI_REPORT_OFF);
    $other = new mysqli();
    $otherOk = @$other->real_connect($setting('DB_HOST', '127.0.0.1'), $setting('DB_USER'), $setting('DB_PASS'), $setting('DB_NAME'), (int) $setting('DB_PORT', '3306'), $socket !== '' && file_exists($socket) ? $socket : null);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $check($otherOk === true, 'a second connection for the administrator who promotes the account');
    $db->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->begin_transaction();
    $db->query("SELECT tipo_utente FROM utenti WHERE id = {$promoted}")->fetch_row();
    $other->query("UPDATE utenti SET tipo_utente = 'staff' WHERE id = {$promoted}");
    $check(P::mayActOnBorrower($db, $promoted) === true, 'control: a plain read still answers from the snapshot (the hazard)');
    $check(P::mayActOnBorrower($db, $promoted, true) === false, 'a locking read sees the promotion and refuses');
    $db->rollback();
    $other->query("UPDATE utenti SET tipo_utente = 'standard' WHERE id = {$promoted}");
    $db->begin_transaction();
    $db->query("SELECT tipo_utente FROM utenti WHERE id = {$promoted}")->fetch_row();
    $check(P::mayActOnBorrower($db, $promoted, true) === true, 'a locking read still admits an account that is a reader');
    $db->rollback();
    $other->close();

    // The invariant, not a list of call sites: inside a method, every borrower
    // scope check that follows begin_transaction() is a locking read. A list of
    // needles let new checks added later (the overdue-extension waiver, the
    // return desk's penalty, the bulk extension) slip through as plain reads.
    $inTransaction = 0;
    foreach (['LoanApprovalController', 'PrestitiController', 'ReservationsAdminController'] as $controller) {
        $lines = file($root . '/app/Controllers/' . $controller . '.php') ?: [];
        $method = '';
        $txOpen = false;
        foreach ($lines as $n => $line) {
            if (preg_match('/^\s*(?:(?:public|private|protected|static|final)\s+)*function\s+(\w+)\s*\(/', $line, $m) === 1) {
                $method = $m[1];
                $txOpen = false;
                continue;
            }
            $code = ltrim($line);
            if (str_starts_with($code, '//') || str_starts_with($code, '*')) {
                continue;
            }
            if (str_contains($line, 'begin_transaction(')) {
                $txOpen = true;
            }
            if ($txOpen && str_contains($line, 'mayActOnBorrower(')) {
                $inTransaction++;
                $check(preg_match('/mayActOnBorrower\([^;]*,\s*true\)/', $line) === 1, "{$controller}::{$method}() line " . ($n + 1) . ': the check inside the transaction is a locking read');
            }
        }
    }
    // Guard against a scan that silently matches nothing (renamed method, moved file).
    $check($inTransaction >= 9, "the scan found the in-transaction checks ({$inTransaction}, expected at least 9)");
    // lockedRowOutOfScope() opens no transaction itself: its callers already hold one.
    $approval = (string) file_get_contents($root . '/app/Controllers/LoanApprovalController.php');
    $helperBody = preg_match('/function lockedRowOutOfScope\b[^{]*\{(.*?)\n    \}/s', $approval, $hm) === 1 ? $hm[1] : '';
    $check(str_contains($helperBody, 'mayActOnBorrower(') && preg_match('/mayActOnBorrower\([^;]*,\s*true\)/', $helperBody) === 1, 'LoanApprovalController::lockedRowOutOfScope(): the check under the caller\'s transaction is a locking read');
} catch (\Throwable $e) {
    $check(false, 'unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $_SESSION = [];
    $db->beforeBegin = null;
    if ($users !== []) {
        $in = implode(',', array_map('intval', $users));
        foreach (["DELETE FROM movimenti_lettore WHERE utente_id IN ({$in})", "DELETE FROM prenotazioni WHERE utente_id IN ({$in})", "DELETE FROM prestiti WHERE utente_id IN ({$in})"] as $sql) {
            try { $db->query($sql); } catch (\Throwable) { /* table absent on an older schema */ }
        }
    }
    if ($bookId > 0) {
        foreach (['DELETE FROM prenotazioni WHERE libro_id = ', 'DELETE FROM prestiti WHERE libro_id = ', 'DELETE FROM copie WHERE libro_id = ', 'DELETE FROM libri WHERE id = '] as $sql) {
            try { $db->query($sql . $bookId); } catch (\Throwable) {}
        }
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

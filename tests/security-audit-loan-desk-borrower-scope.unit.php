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
 * loan to one, and set a loss penalty or a fine waiver on their returns. Every
 * other write on an existing loan or reservation of such an account is refused
 * too (edit dates, renew, bulk extend, approve, reject, confirm or cancel the
 * pickup, cancel a reservation); a plain return stays possible, the copy has
 * to come back. The
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

    echo "  Writes on an existing loan or reservation of an out-of-scope account\n";
    $insertState = static function (int $userId, ?int $copyId, string $stato, int $attivo) use ($db, &$bookId): int {
        $today = \App\Support\DateHelper::today();
        $due = (new DateTimeImmutable($today))->modify('+14 days')->format('Y-m-d');
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
    $json = static function (\Psr\Http\Message\ResponseInterface $r): array {
        $body = (string) $r->getBody();
        return ['status' => $r->getStatusCode(), 'data' => json_decode($body, true) ?: []];
    };
    $approval = new \App\Controllers\LoanApprovalController();

    $staffActive = $insertLoan($staff, $copies[3]);
    $before = $one('SELECT data_scadenza, renewals FROM prestiti WHERE id = ?', $staffActive);
    $actAs($operator);
    $later = (new DateTimeImmutable((string) $before['data_scadenza']))->modify('+7 days')->format('Y-m-d');
    $r = (new PrestitiController())->update($post(['data_prestito' => $today, 'data_scadenza' => $later]), new Response(), $db, $staffActive);
    $check(str_contains($location($r), 'error=loan_not_found'), "a custom role cannot edit the dates of a Staff account's loan");
    $_GET = ['error' => 'loan_not_found'];
    $loans = (string) (new PrestitiController())->index((new ServerRequestFactory())->createServerRequest('GET', 'http://library.test/admin/loans')->withQueryParams($_GET), new Response(), $db)->getBody();
    $_GET = [];
    $check(str_contains($loans, 'Prestito non trovato o non modificabile con il tuo ruolo.') && !str_contains($loans, "Errore durante l'aggiornamento del prestito."),
        'the loans list explains the refusal instead of a generic update error (F067)');
    $r = (new PrestitiController())->renew($post([]), new Response(), $db, $staffActive);
    $check(str_contains($location($r), 'error=loan_not_found'), '...nor renew it');
    $r = (new PrestitiController())->bulkExtend($post(['ids' => [(string) $staffActive], 'days' => '7']), new Response(), $db);
    $check(str_contains($location($r), 'bulk_extended=0'), '...nor extend it in bulk (skipped)');
    $after = $one('SELECT data_scadenza, renewals FROM prestiti WHERE id = ?', $staffActive);
    $check($after === $before, 'the Staff loan keeps its due date and renewal count');

    $staffPending = $insertState($staff, null, 'pendente', 0);
    $actAs($operator);
    $a = $json($approval->approveLoan($post(['loan_id' => (string) $staffPending]), new Response(), $db));
    $check($a['status'] === 404 && ($a['data']['success'] ?? null) === false, "a custom role cannot approve a Staff account's loan request");
    $a = $json($approval->rejectLoan($post(['loan_id' => (string) $staffPending, 'reason' => 'audit']), new Response(), $db));
    $check($a['status'] === 404, '...nor reject it');
    $check(($one('SELECT stato FROM prestiti WHERE id = ?', $staffPending)['stato'] ?? '') === 'pendente', 'the request is still pending');

    $staffPickup = $insertState($staff, $copies[4], 'da_ritirare', 1);
    $actAs($operator);
    $a = $json($approval->confirmPickup($post(['loan_id' => (string) $staffPickup]), new Response(), $db));
    $check($a['status'] === 404, "a custom role cannot confirm the pickup of a Staff account's loan");
    $a = $json($approval->cancelPickup($post(['loan_id' => (string) $staffPickup, 'reason' => 'audit']), new Response(), $db));
    $check($a['status'] === 404, '...nor cancel it');
    $check(($one('SELECT stato FROM prestiti WHERE id = ?', $staffPickup)['stato'] ?? '') === 'da_ritirare', 'the loan is still waiting for pickup');

    $actAs($operator);
    $a = $json($approval->cancelReservation($post(['reservation_id' => (string) $staffReservation, 'reason' => 'audit']), new Response(), $db));
    $check($a['status'] === 404, "a custom role cannot cancel a Staff account's reservation");
    $check(($one('SELECT stato FROM prenotazioni WHERE id = ?', $staffReservation)['stato'] ?? '') === 'attiva', 'the reservation is still active');

    echo "  Reservation reads and the create picker (review of PR #536: F024, F090, F066)\n";
    $render = static function (callable $action): string {
        $r = $action();
        return $r->getStatusCode() . "\n" . (string) $r->getBody();
    };
    $get = static fn (array $q = []) => (new ServerRequestFactory())->createServerRequest('GET', 'http://library.test/')->withQueryParams($q);
    $email = static function (int $id) use ($db): string {
        $stmt = $db->prepare('SELECT email FROM utenti WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $e = (string) ($stmt->get_result()->fetch_row()[0] ?? '');
        $stmt->close();
        return $e;
    };
    $stmt = $db->prepare("INSERT INTO prenotazioni (libro_id, utente_id, data_prenotazione, data_scadenza_prenotazione, queue_position, stato) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 2, 'attiva')");
    $stmt->bind_param('ii', $bookId, $reader);
    $stmt->execute();
    $readerReservation = (int) $stmt->insert_id;
    $stmt->close();
    $editLink = static fn (int $id): string => '/admin/reservations/edit/' . $id . '"';

    $actAs($operator);
    $form = $render(fn () => (new ReservationsAdminController())->createForm($get(), new Response(), $db));
    $check(str_contains($form, $email($reader)), 'create form: a custom role is offered the reader accounts');
    $check(!str_contains($form, $email($staff)) && !str_contains($form, $email($admin)),
        "create form: a custom role never sees a Staff or administrator account's email");
    $list = $render(fn () => (new ReservationsAdminController())->index($get(['libro_id' => (string) $bookId]), new Response(), $db));
    $check(str_contains($list, $editLink($readerReservation)), "reservations list: a custom role sees a reader's reservation");
    $check(!str_contains($list, $editLink($staffReservation)), "reservations list: a custom role does not see a Staff account's reservation");
    $edit = $render(fn () => (new ReservationsAdminController())->editForm($get(), new Response(), $db, $staffReservation));
    $check(str_starts_with($edit, '404'), "edit form: a Staff account's reservation answers 404 to a custom role");
    $edit = $render(fn () => (new ReservationsAdminController())->editForm($get(), new Response(), $db, $readerReservation));
    $check(str_starts_with($edit, '200'), "edit form: a reader's reservation opens (control)");
    $_GET = ['error' => 'not_found'];
    $list = $render(fn () => (new ReservationsAdminController())->index($get(['error' => 'not_found']), new Response(), $db));
    $_GET = [];
    $check(str_contains($list, 'Prenotazione non trovata o non modificabile con il tuo ruolo.'), 'a refused edit tells the operator why on the list');
    $actAs($staff);
    $form = $render(fn () => (new ReservationsAdminController())->createForm($get(), new Response(), $db));
    $check(str_contains($form, $email($admin)) && str_contains($form, $email($reader)), 'Staff keeps every active account in the create form');
    $list = $render(fn () => (new ReservationsAdminController())->index($get(['libro_id' => (string) $bookId]), new Response(), $db));
    $check(str_contains($list, $editLink($staffReservation)) && str_contains($list, $editLink($readerReservation)), 'Staff keeps every reservation in the list');

    echo "  Loan reads of an out-of-scope borrower (F076)\n";
    $actAs($operator);
    $readerLoanR = $insertLoan($reader, $copies[6]);
    $page = static fn (string $m, int $loanId): string => (string) (new PrestitiController())->{$m}($get(), new Response(), $db, $loanId)->getBody();
    $check(!str_contains($page('details', $staffActive), $email($staff)), "loan details: a custom role does not see a Staff account's email");
    $check(str_contains($page('details', $readerLoanR), $email($reader)), "loan details: a reader's email is shown (control)");
    $check(!str_contains($page('returnForm', $staffActive), $email($staff)), "return form: the copy can come back, the Staff account's email stays hidden");
    $pdf = (new PrestitiController())->downloadPdf($get(), new Response(), $db, $staffActive);
    $check($pdf->getStatusCode() === 302 && str_contains($pdf->getHeaderLine('Location'), 'error=loan_not_found'),
        "loan receipt PDF: refused for a Staff account's loan");
    $actAs($staff);
    $check(str_contains($page('details', $staffActive), $email($staff)), 'Staff keeps every borrower in loan details');

    echo "  User edit page error box (F065)\n";
    $actAs($admin);
    $editUser = static function (string $error) use ($db, $reader): string {
        $_GET = ['error' => $error];
        $r = (new \App\Controllers\UsersController())->editForm((new ServerRequestFactory())->createServerRequest('GET', 'http://library.test/')->withQueryParams($_GET), new Response(), $db, $reader);
        $_GET = [];
        return (string) $r->getBody();
    };
    $emptyBox = static fn (string $html): bool => preg_match('#<div class="mb-6 border border-red-200 bg-red-50 text-red-700 rounded-lg px-4 py-3 text-sm" role="alert">\s*</div>#', $html) === 1;
    $html = $editUser('wrong_current_password');
    $check($html !== '' && !$emptyBox($html), 'wrong_current_password no longer renders an empty red box');
    $html = $editUser('email_exists');
    $check(str_contains($html, 'Email già registrata'), 'a known error still renders its message (control)');

    echo "  Controls: the same writes on a reader in scope go through\n";
    $readerActive = $insertLoan($reader, $copies[5]);
    $actAs($operator);
    $r = (new PrestitiController())->renew($post([]), new Response(), $db, $readerActive);
    $check(!str_contains($location($r), 'error=loan_not_found'), 'renewing a reader loan is not refused by the scope');
    $r = (new PrestitiController())->update($post(['data_prestito' => $today, 'data_scadenza' => $due]), new Response(), $db, $readerActive);
    $check(!str_contains($location($r), 'error=loan_not_found'), 'editing a reader loan is not refused by the scope');
    $readerPending = $insertState($reader, null, 'pendente', 0);
    $actAs($operator);
    $a = $json($approval->rejectLoan($post(['loan_id' => (string) $readerPending, 'reason' => 'audit']), new Response(), $db));
    $check($a['status'] === 200 && ($a['data']['success'] ?? null) === true, 'rejecting a reader request goes through');
    // Staff keeps every account in scope. Its own request is the one exception,
    // and that refusal comes from separation of duties (SelfDealingGuard), not
    // from the borrower scope: it never answers "not found".
    $adminPending = $insertState($admin, null, 'pendente', 0);
    $actAs($staff);
    $a = $json($approval->rejectLoan($post(['loan_id' => (string) $adminPending, 'reason' => 'audit']), new Response(), $db));
    $check($a['status'] === 200 && ($a['data']['success'] ?? null) === true, 'Staff keeps every account: it rejects an admin account request');
    $a = $json($approval->rejectLoan($post(['loan_id' => (string) $staffPending, 'reason' => 'audit']), new Response(), $db));
    $check($a['status'] !== 404 && ($a['data']['success'] ?? null) === false, 'Staff cannot reject its own request (separation of duties, not the scope)');
    $check(($one('SELECT stato FROM prestiti WHERE id = ?', $staffPending)['stato'] ?? '') === 'pendente', 'its own request is still pending');
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

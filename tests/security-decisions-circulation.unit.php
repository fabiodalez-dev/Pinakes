<?php
declare(strict_types=1);

/**
 * Security decisions [55]/[71] and [56] of the 2026-11 hardening audit.
 *
 * [55]/[71] Separation of duties at the loan desk: an operator who is not an
 * administrator (Staff, or a reader account with a custom loan-desk role) must
 * not record circulation or ledger writes on their own account. One guard,
 * App\Support\SelfDealingGuard, is called from every operator write path.
 *
 * [56] PrestitiController::update() moving an OVERDUE loan's due date forward
 * erased the late fine silently. It now requires a reason and records the fine
 * accrued so far as a late fine plus a waiver (condono) with that reason.
 *
 *  A. guard logic, stubbed identities (no database);
 *  B-F. the real controllers against a sandbox database built from
 *       installer/database/schema.sql (<DB_NAME>_selfdeal, dropped at the end):
 *     B. ledger payment/charge/waiver on one's own account (web controller);
 *     C. renewal and return of one's own loan, D. approval of one's own request
 *        (an automatic approval of the reader's own request is not refused);
 *     E. reservation and loan created for oneself;
 *     F. [56] overdue extension: reason required, fine written + waived.
 *  G. the mobile API maps the refusal to a 403 not_permitted envelope.
 *
 * Run:  php tests/security-decisions-circulation.unit.php
 * DB:   E2E_DB_* or the worktree .env. Sections B-F SKIP cleanly without a
 *       reachable database (or without the mysql client to load the schema).
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\SelfDealingGuard;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};
$finish = static function () use (&$pass, &$fail): never {
    echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
    exit($fail === 0 ? 0 : 1);
};

// ── A. Guard logic ─────────────────────────────────────────────────────────
echo "A. SelfDealingGuard decisions\n";
$staff = ['id' => 41, 'tipo_utente' => 'staff', 'stato' => 'attivo'];
$custom = ['id' => 42, 'tipo_utente' => 'standard', 'stato' => 'attivo', 'permissions' => ['backend.access', 'loans.manage']];
$admin = ['id' => 43, 'tipo_utente' => 'admin', 'stato' => 'attivo'];
$check(SelfDealingGuard::blocks(41, $staff), 'Staff acting on its own account is refused');
$check(SelfDealingGuard::blocks(42, $custom), 'a custom loan-desk role acting on its own reader account is refused');
$check(!SelfDealingGuard::blocks(43, $admin), 'an administrator is exempt');
$check(!SelfDealingGuard::blocks(99, $staff) && !SelfDealingGuard::blocks(99, $custom), 'acting on another account is allowed');
$check(!SelfDealingGuard::blocks(0, $staff) && !SelfDealingGuard::blocks(41, []), 'no target or no operator: not judged here');
$check(SelfDealingGuard::blocks(41, ['id' => '41', 'tipo_utente' => 'staff']), 'a numeric-string session id still matches');
$_SESSION = ['user' => $staff];
$check(SelfDealingGuard::blocks(41), 'defaults to the signed-in user');
$threw = false;
try {
    SelfDealingGuard::assertNotSelf(41);
} catch (\App\Support\SelfDealingException $e) {
    $threw = $e->getMessage() !== '';
}
$check($threw, 'assertNotSelf() throws with an explanation');
$_SESSION = [];

// ── Database sandbox ───────────────────────────────────────────────────────
$skip = static function (string $why) use ($finish): never {
    if (getenv('CI_STRICT_TESTS') === '1') {
        fwrite(STDERR, "FAIL: {$why} (CI_STRICT_TESTS=1 forbids skipping)\n");
        exit(1);
    }
    echo "SKIP (B-F): {$why}\n";
    $finish();
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$dbHost = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$dbPort = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));
if ($dbName === '' || $dbUser === '') {
    $skip('no database configured');
}
$sandbox = $dbName . '_selfdeal';
if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $sandbox) !== 1) {
    $skip('unusable sandbox name');
}
$useSocket = $socket !== '' && file_exists($socket);
$connect = static function (string $database) use ($useSocket, $socket, $dbUser, $dbPass, $dbHost, $dbPort): mysqli {
    $db = $useSocket
        ? new mysqli(null, $dbUser, $dbPass, $database, 0, $socket)
        : new mysqli($dbHost, $dbUser, $dbPass, $database, $dbPort);
    $db->set_charset('utf8mb4');
    return $db;
};
try {
    $server = $connect('');
    $server->query("DROP DATABASE IF EXISTS `{$sandbox}`");
    $server->query("CREATE DATABASE `{$sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (\Throwable $e) {
    $skip('database not reachable or no grant on ' . $sandbox . ' (' . $e->getMessage() . ')');
}
$dropSandbox = static function () use ($server, $sandbox): void {
    try {
        $server->query("DROP DATABASE IF EXISTS `{$sandbox}`");
    } catch (\Throwable) {
    }
};
register_shutdown_function($dropSandbox);

$command = $useSocket
    ? sprintf('mysql --socket=%s -u %s %s', escapeshellarg($socket), escapeshellarg($dbUser), escapeshellarg($sandbox))
    : sprintf('mysql -h %s -P %d --protocol=TCP -u %s %s', escapeshellarg($dbHost), $dbPort, escapeshellarg($dbUser), escapeshellarg($sandbox));
$command .= ' < ' . escapeshellarg($root . '/installer/database/schema.sql') . ' 2>&1';
$previousPwd = getenv('MYSQL_PWD');
putenv('MYSQL_PWD=' . $dbPass);
try {
    exec($command, $out, $rc);
} finally {
    $previousPwd === false ? putenv('MYSQL_PWD') : putenv('MYSQL_PWD=' . $previousPwd);
}
if ($rc !== 0) {
    $skip('could not load schema.sql into the sandbox (mysql client missing?)');
}

$db = $connect($sandbox);
\App\Support\DateHelper::synchronizeDatabaseSession($db);
\App\Support\Features::override('fines', true);
\App\Support\Features::override('automatic_fines', true);
\App\Support\Features::override('debt_block', false);

$db->query("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans', 'daily_fine', '0.50')
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

$newUser = static function (string $tag, string $type) use ($db): int {
    $email = "zz-selfdeal-{$tag}@test.local";
    $card = 'ZSD' . strtoupper($tag);
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata, privacy_accettata) VALUES (?, 'Zz', ?, ?, 'x', 'attivo', ?, 1, 1)");
    $stmt->bind_param('ssss', $card, $tag, $email, $type);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
};
$newBook = static function (string $copyState = 'prestato') use ($db): array {
    $title = 'ZZ_SELFDEAL_' . bin2hex(random_bytes(3));
    $available = $copyState === 'disponibile' ? 1 : 0;
    $db->query("INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES ('{$title}', 1, {$available}, '" . ($available ? 'disponibile' : 'prestato') . "')");
    $bookId = (int) $db->insert_id;
    $db->query("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES ({$bookId}, 'ZSD-{$title}', '{$copyState}')");
    return [$bookId, (int) $db->insert_id];
};
$newLoan = static function (int $userId, string $start, string $due, string $state = 'in_corso') use ($db, $newBook): int {
    [$bookId, $copyId] = $newBook($state === 'pendente' ? 'disponibile' : 'prestato');
    $active = $state === 'pendente' ? 0 : 1;
    $copy = $state === 'pendente' ? null : $copyId;
    $stmt = $db->prepare("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (?, ?, ?, ?, ?, ?, 'diretto', ?)");
    $stmt->bind_param('iiisssi', $bookId, $copy, $userId, $start, $due, $state, $active);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
};
$post = static function (string $path, array $body) {
    return (new ServerRequestFactory())->createServerRequest('POST', $path)
        ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
        ->withParsedBody($body);
};
$loanRow = static fn (int $id): array => $db->query("SELECT * FROM prestiti WHERE id = {$id}")->fetch_assoc() ?: [];
$movements = static fn (int $userId): array => $db->query("SELECT tipo, importo, descrizione, prestito_id FROM movimenti_lettore WHERE utente_id = {$userId} ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$as = static function (int $id, string $type): void {
    $_SESSION = ['user' => ['id' => $id, 'tipo_utente' => $type, 'stato' => 'attivo', 'permissions' => $type === 'standard' ? ['backend.access', 'loans.manage'] : []]];
};

$today = \App\Support\DateHelper::today();
$day = static fn (int $offset): string => (new DateTimeImmutable($today))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');

try {
    $staffId = $newUser('staff', 'staff');
    $deskId = $newUser('desk', 'standard');      // a reader account holding a custom loan-desk role
    $adminId = $newUser('admin', 'admin');
    $readerId = $newUser('reader', 'standard');

    // ── B. Ledger writes ───────────────────────────────────────────────────
    echo "B. Ledger payment / charge / waiver on one's own account\n";
    $ledger = new \App\Controllers\PatronLedgerController();
    $key = static fn (): string => bin2hex(random_bytes(16));
    foreach ([[$staffId, 'staff', 'Staff'], [$deskId, 'standard', 'custom role']] as [$opId, $opType, $label]) {
        $as($opId, $opType);
        $r = $ledger->charge($post("/admin/users/{$opId}/ledger/charge", ['importo' => '5.00', 'descrizione' => 'x', 'request_key' => $key()]), new SlimResponse(), $db, $opId);
        $check(str_contains($r->getHeaderLine('Location'), 'ledger_error=self_dealing') || ($_SESSION['error_message'] ?? '') === SelfDealingGuard::message(), "{$label}: a charge on its own account is refused");
        $_SESSION['error_message'] = null;
        $r = $ledger->waive($post("/admin/users/{$opId}/ledger/waive", ['importo' => '1.00', 'motivazione' => 'x', 'request_key' => $key()]), new SlimResponse(), $db, $opId);
        $r2 = $ledger->payment($post("/admin/users/{$opId}/ledger/payment", ['importo' => '1.00', 'metodo_pagamento' => 'contanti', 'request_key' => $key()]), new SlimResponse(), $db, $opId);
        $check($movements($opId) === [], "{$label}: no movement written on its own ledger (charge, waiver, payment)");
    }
    $as($staffId, 'staff');
    $r = $ledger->charge($post("/admin/users/{$readerId}/ledger/charge", ['importo' => '5.00', 'descrizione' => 'desk', 'request_key' => $key()]), new SlimResponse(), $db, $readerId);
    $check(count($movements($readerId)) === 1, "Staff still charges another reader's account");
    $as($adminId, 'admin');
    $ledger->charge($post("/admin/users/{$adminId}/ledger/charge", ['importo' => '2.00', 'descrizione' => 'self', 'request_key' => $key()]), new SlimResponse(), $db, $adminId);
    $check(count($movements($adminId)) === 1, 'an administrator is exempt on its own ledger');
    $db->query('DELETE FROM movimenti_lettore');

    // ── C. Renewal and return of one's own loan ───────────────────────────
    echo "C. Renewal and return of one's own loan\n";
    $prestiti = new \App\Controllers\PrestitiController();
    $ownLoan = $newLoan($staffId, $day(-5), $day(5));
    $as($staffId, 'staff');
    $r = $prestiti->renew($post("/admin/loans/renew/{$ownLoan}", []), new SlimResponse(), $db, $ownLoan);
    $check(str_contains($r->getHeaderLine('Location'), 'error=self_dealing'), 'renewing its own loan is refused');
    $check($loanRow($ownLoan)['data_scadenza'] === $day(5), 'the due date is unchanged');
    $r = $prestiti->processReturn($post("/admin/loans/returned/{$ownLoan}", ['stato' => 'restituito']), new SlimResponse(), $db, $ownLoan);
    $check(str_contains($r->getHeaderLine('Location'), 'error=self_dealing') && (int) $loanRow($ownLoan)['attivo'] === 1, 'recording the return of its own loan is refused, the loan stays open');
    $r = $prestiti->update($post("/admin/loans/update/{$ownLoan}", ['utente_id' => (string) $staffId, 'data_prestito' => $day(-5), 'data_scadenza' => $day(20)]), new SlimResponse(), $db, $ownLoan);
    $check(str_contains($r->getHeaderLine('Location'), 'error=self_dealing') && $loanRow($ownLoan)['data_scadenza'] === $day(5), 'extending its own loan from the edit form is refused');
    $r = $prestiti->bulkExtend($post('/admin/loans/bulk-extend', ['ids' => [(string) $ownLoan], 'days' => '7']), new SlimResponse(), $db);
    $check(str_contains($r->getHeaderLine('Location'), 'error=self_dealing') && $loanRow($ownLoan)['data_scadenza'] === $day(5), 'a bulk extension holding its own loan is refused');
    $readerLoan = $newLoan($readerId, $day(-5), $day(5));
    $r = $prestiti->update($post("/admin/loans/update/{$readerLoan}", ['utente_id' => (string) $staffId, 'data_prestito' => $day(-5), 'data_scadenza' => $day(5)]), new SlimResponse(), $db, $readerLoan);
    $check(str_contains($r->getHeaderLine('Location'), 'error=self_dealing') && (int) $loanRow($readerLoan)['utente_id'] === $readerId, "reassigning a reader's loan to itself is refused");
    $as($adminId, 'admin');
    $r = $prestiti->renew($post("/admin/loans/renew/{$ownLoan}", []), new SlimResponse(), $db, $ownLoan);
    $check(!str_contains($r->getHeaderLine('Location'), 'self_dealing'), "an administrator renews the operator's loan");

    // ── D. Approval of one's own request ──────────────────────────────────
    echo "D. Approval desk\n";
    $approval = new \App\Controllers\LoanApprovalController();
    $pending = $newLoan($deskId, $day(1), $day(15), 'pendente');
    $as($deskId, 'standard');
    foreach (['approveLoan', 'rejectLoan', 'confirmPickup', 'cancelPickup', 'returnLoan'] as $method) {
        $r = $approval->{$method}($post('/admin/loans/x', ['loan_id' => (string) $pending]), new SlimResponse(), $db);
        $json = json_decode((string) $r->getBody(), true);
        $check($r->getStatusCode() === 403 && ($json['code'] ?? '') === 'self_dealing', "{$method} on its own loan: 403 self_dealing");
    }
    $check($loanRow($pending)['stato'] === 'pendente', 'the request is untouched');
    $r = $approval->approveLoan($post('/admin/loans/approve', ['loan_id' => (string) $pending])->withAttribute('automatic_loan_approval', true), new SlimResponse(), $db);
    $json = json_decode((string) $r->getBody(), true);
    $check(($json['code'] ?? '') !== 'self_dealing', "an automatic approval of the reader's own request is not an operator action");

    // ── E. Creating for oneself ───────────────────────────────────────────
    echo "E. Loan and reservation created for oneself\n";
    [$freeBook] = $newBook('disponibile');
    $as($staffId, 'staff');
    $_SESSION['_one_time_form_tokens'] = [];
    $token = \App\Support\OneTimeFormToken::issue('loan.create');
    $r = $prestiti->store($post('/admin/loans/create', ['utente_id' => (string) $staffId, 'libro_id' => (string) $freeBook, 'data_prestito' => $today, 'data_scadenza' => $day(14), 'loan_submission_token' => $token]), new SlimResponse(), $db);
    $count = (int) $db->query("SELECT COUNT(*) FROM prestiti WHERE libro_id = {$freeBook}")->fetch_row()[0];
    $check(str_contains($r->getHeaderLine('Location'), 'error=self_dealing') && $count === 0, 'a loan for its own account is refused');
    $r = (new \App\Controllers\ReservationsAdminController())->store($post('/admin/reservations/create', ['utente_id' => (string) $staffId, 'libro_id' => (string) $freeBook, 'data_prenotazione' => $today, 'data_scadenza' => $day(10)]), new SlimResponse(), $db);
    $count = (int) $db->query("SELECT COUNT(*) FROM prenotazioni WHERE libro_id = {$freeBook}")->fetch_row()[0];
    $check(str_contains($r->getHeaderLine('Location'), 'error=self_dealing') && $count === 0, 'a reservation for its own account is refused');

    // ── F. [56] Overdue extension waives the accrued fine with a reason ──
    echo "F. Moving an overdue loan forward\n";
    $as($adminId, 'admin');
    $overdue = $newLoan($readerId, $day(-30), $day(-10), 'in_ritardo');
    $form = ['utente_id' => (string) $readerId, 'data_prestito' => $day(-30), 'data_scadenza' => $day(7)];
    $r = $prestiti->update($post("/admin/loans/update/{$overdue}", $form), new SlimResponse(), $db, $overdue);
    $check(str_contains($r->getHeaderLine('Location'), 'error=extension_reason_required'), 'without a reason the extension is refused');
    $check($loanRow($overdue)['data_scadenza'] === $day(-10) && $movements($readerId) === [], 'nothing changed, nothing written');
    $r = $prestiti->update($post("/admin/loans/update/{$overdue}", $form + ['waive_reason' => 'Proroga concordata']), new SlimResponse(), $db, $overdue);
    $rows = $movements($readerId);
    $check(!str_contains($r->getHeaderLine('Location'), 'error=') && $loanRow($overdue)['data_scadenza'] === $day(7), 'with a reason the due date moves forward');
    $types = array_column($rows, 'tipo');
    $check($types === ['multa_ritardo', 'condono'], 'the accrued fine is written and waived (multa_ritardo + condono)');
    $charged = isset($rows[0]) ? (float) $rows[0]['importo'] : 0.0;
    $check($charged > 0 && isset($rows[1]) && abs((float) $rows[1]['importo'] + $charged) < 0.001, 'waiver equals the accrued fine (balance unchanged)');
    $check(($rows[1]['descrizione'] ?? '') === 'Proroga concordata' && (int) ($rows[1]['prestito_id'] ?? 0) === $overdue, 'the waiver carries the reason and the loan');
    $check(abs($charged - 5.00) < 0.001, 'the figure is the fine accrued to today (10 days x 0.50)');
    $check(\App\Services\PatronLedger::forDb($db)->balanceCents($readerId) === 0, 'the reader owes nothing for it');

    $current = $newLoan($readerId, $day(-3), $day(4));
    $r = $prestiti->update($post("/admin/loans/update/{$current}", ['utente_id' => (string) $readerId, 'data_prestito' => $day(-3), 'data_scadenza' => $day(12)]), new SlimResponse(), $db, $current);
    $check(!str_contains($r->getHeaderLine('Location'), 'error=') && $loanRow($current)['data_scadenza'] === $day(12), 'a loan that is not overdue extends as before, no reason needed');
    $check(count($movements($readerId)) === 2, 'and writes nothing to the ledger');

    echo "F2. Bulk extension and overdue loans\n";
    $fined = $newLoan($readerId, $day(-30), $day(-8), 'in_ritardo');
    $plain = $newLoan($readerId, $day(-3), $day(4));
    $r = $prestiti->bulkExtend($post('/admin/loans/bulk-extend', ['ids' => [(string) $plain, (string) $fined], 'days' => '14']), new SlimResponse(), $db);
    $loc = $r->getHeaderLine('Location');
    $check(str_contains($loc, 'error=bulk_extend_fine_accruing') && str_contains($loc, 'loan_id=' . $fined), 'a batch holding an overdue loan with a fine accruing is refused, naming the loan');
    $check($loanRow($fined)['data_scadenza'] === $day(-8) && $loanRow($plain)['data_scadenza'] === $day(4), 'the whole batch is left as it was');
    $check(count($movements($readerId)) === 2, 'nothing written to the ledger');
    \App\Support\Features::override('automatic_fines', false);
    $r = $prestiti->bulkExtend($post('/admin/loans/bulk-extend', ['ids' => [(string) $fined], 'days' => '14']), new SlimResponse(), $db);
    \App\Support\Features::override('automatic_fines', true);
    $check(!str_contains($r->getHeaderLine('Location'), 'error=') && $loanRow($fined)['data_scadenza'] > $day(0), 'an overdue loan with no fine accruing extends in bulk as before');
    $r = $prestiti->bulkExtend($post('/admin/loans/bulk-extend', ['ids' => [(string) $plain], 'days' => '14']), new SlimResponse(), $db);
    $check(!str_contains($r->getHeaderLine('Location'), 'error=') && $loanRow($plain)['data_scadenza'] > $day(4), 'a loan that is not overdue extends in bulk as before');
} catch (\Throwable $e) {
    $check(false, 'unexpected ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// ── G. Mobile API mapping ──────────────────────────────────────────────────
echo "G. Mobile API envelopes\n";
require_once $root . '/storage/plugins/mobile-api/src/Support/CirculationOutcome.php';
require_once $root . '/storage/plugins/mobile-api/src/Staff090/Support/LedgerOutcome.php';
$C = \App\Plugins\MobileApi\Support\CirculationOutcome::class;
foreach (['CREATE', 'UPDATE', 'RETURN', 'RENEW', 'BULK', 'RESERVATION'] as $table) {
    $entry = constant($C . '::' . $table)['self_dealing'] ?? null;
    $check(is_array($entry) && $entry[0] === 403 && $entry[1] === 'not_permitted', "{$table}: self_dealing -> 403 not_permitted");
}
$check(($C::UPDATE['extension_reason_required'][2] ?? null) === 'waive_reason', 'UPDATE: extension_reason_required -> 422 on waive_reason');
$check(($C::BULK['bulk_extend_fine_accruing'][0] ?? 0) === 409, 'BULK: bulk_extend_fine_accruing -> 409 (meta.loan_id names the loan)');
$check(\App\Plugins\MobileApi\Staff090\Support\LedgerOutcome::error('self_dealing') === [403, 'not_permitted', null], 'ledger: self_dealing -> 403 not_permitted');

$_SESSION = [];
$finish();

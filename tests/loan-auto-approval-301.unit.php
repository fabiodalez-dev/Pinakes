<?php
declare(strict_types=1);

/**
 * Behavioural database contract for #301 — automatic approval of immediate loan
 * requests. Exercises the REAL SettingsRepository accessor and the REAL
 * UserActionsController::autoApproveLoanRequest() (which delegates to the
 * canonical LoanApprovalController::approveLoan pipeline) against seeded
 * libri/copie/utenti/prestiti rows, and asserts the observable state
 * transitions rather than string-matching the source.
 *
 * Run: php tests/loan-auto-approval-301.unit.php
 */

use App\Controllers\UserActionsController;
use App\Models\CopyRepository;
use App\Models\SettingsRepository;
use App\Support\DateHelper;
use Slim\Psr7\Factory\ServerRequestFactory;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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
    // Production writers bind the application-local date on every
    // connection (container/cron/scripts bootstrap); the circulation
    // triggers otherwise fall back to the database's UTC CURRENT_DATE(),
    // which disagrees with app.timezone between 22:00 and 24:00 UTC.
    \App\Support\DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

// Keep email out of the way — approveLoan sends the patron notification; the
// 'mail' driver never blocks, but we also don't want a stray SMTP config to hang.
$db->query("UPDATE system_settings SET setting_value='mail' WHERE category='email' AND setting_key IN ('driver_mode','type')");

// Make the email outcome DETERMINISTIC: sendWithRetry() short-circuits on
// Mailer::isSmtpReachable(), so seeding the cached probe with `false` makes the
// approval email fail identically everywhere (CI runners have no sendmail; dev
// machines may have one). The pickup-claim assertion below (09b) then tests the
// release contract instead of depending on the host's mail transport.
$smtpProbe = new ReflectionProperty(\App\Support\Mailer::class, 'smtpReachable');
$smtpProbe->setValue(null, false);

$run = substr(hash('sha256', uniqid((string) getmypid(), true)), 0, 10);
$titlePrefix = 'ZZAUTO301_' . $run;
$emailDomain = '@auto301.test.local';

// Preserve the global auto-approve setting so the run doesn't leave it toggled.
$origAuto = null;
if ($r = $db->query("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='auto_approve_requests'")) {
    if ($row = $r->fetch_assoc()) {
        $origAuto = $row['setting_value'];
    }
}

$cleanup = static function () use ($db, $titlePrefix, $emailDomain, $origAuto): void {
    $titleLike = $titlePrefix . '%';
    $emailLike = '%' . $emailDomain;
    foreach ([
        'DELETE p FROM prestiti p JOIN libri l ON l.id = p.libro_id WHERE l.titolo LIKE ?',
        'DELETE r FROM prenotazioni r JOIN libri l ON l.id = r.libro_id WHERE l.titolo LIKE ?',
        'DELETE c FROM copie c JOIN libri l ON l.id = c.libro_id WHERE l.titolo LIKE ?',
        'DELETE FROM libri WHERE titolo LIKE ?',
    ] as $sql) {
        $stmt = $db->prepare($sql);
        $stmt->bind_param('s', $titleLike);
        $stmt->execute();
        $stmt->close();
    }
    $stmt = $db->prepare('DELETE FROM utenti WHERE email LIKE ?');
    $stmt->bind_param('s', $emailLike);
    $stmt->execute();
    $stmt->close();
    // Restore the auto-approve setting to its pre-test value (or remove it).
    if ($origAuto === null) {
        $db->query("DELETE FROM system_settings WHERE category='loans' AND setting_key='auto_approve_requests'");
    } else {
        $stmt = $db->prepare("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans','auto_approve_requests',?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->bind_param('s', $origAuto);
        $stmt->execute();
        $stmt->close();
    }
};

$cleanup();
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    $db->close();
    exit(1);
});

$pass = 0;
$check = static function (bool $ok, string $label) use (&$pass): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $pass++;
    echo "  OK  {$label}\n";
};

// ── Fixture helpers ─────────────────────────────────────────────────────────
$bookSeq = 0;
$makeBook = static function (int $copies) use ($db, $titlePrefix, $run, &$bookSeq): int {
    $bookSeq++;
    $title = $titlePrefix . '_' . $bookSeq;
    $stmt = $db->prepare("INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (?, 'disponibile', ?, ?)");
    $stmt->bind_param('sii', $title, $copies, $copies);
    $stmt->execute();
    $bookId = (int) $db->insert_id;
    $stmt->close();
    $copyRepo = new CopyRepository($db);
    for ($i = 1; $i <= $copies; $i++) {
        $copyRepo->create($bookId, 'ZZA301-' . $run . '-' . $bookSeq . '-' . $i, 'disponibile');
    }
    return $bookId;
};

$userSeq = 0;
$makeUser = static function () use ($db, $run, $emailDomain, &$userSeq): int {
    $userSeq++;
    $card = 'ZZA301' . strtoupper($run) . $userSeq;
    $email = $run . '-' . $userSeq . $emailDomain;
    $password = password_hash('test', PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente) VALUES (?, 'Auto', 'Test', ?, ?, 'standard')");
    $stmt->bind_param('sss', $card, $email, $password);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$today = DateHelper::today();
$due = (new DateTimeImmutable($today))->modify('+14 days')->format('Y-m-d');

// A fresh pending request: attivo=0, stato='pendente', no copy assigned yet,
// data_prestito = today so approval yields the immediate 'da_ritirare' state.
$makePendingLoan = static function (int $bookId, int $userId, ?string $state = 'pendente') use ($db, $today, $due): int {
    $attivo = $state === 'pendente' ? 0 : 1;
    $stmt = $db->prepare("INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (?, ?, ?, ?, ?, 'diretto', ?)");
    $stmt->bind_param('iisssi', $bookId, $userId, $today, $due, $state, $attivo);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$setAuto = static function (?string $value) use ($db): void {
    if ($value === null) {
        $db->query("DELETE FROM system_settings WHERE category='loans' AND setting_key='auto_approve_requests'");
        return;
    }
    (new SettingsRepository($db))->set('loans', 'auto_approve_requests', $value);
};

$accessor = static fn (): bool => (new SettingsRepository($db))->autoApproveLoanRequests();

// autoApproveLoanRequest() is private — invoke the real method by reflection so
// the test drives the exact code path the loan() request handler triggers.
$controller = new UserActionsController();
$autoMethod = new ReflectionMethod($controller, 'autoApproveLoanRequest');
$autoMethod->setAccessible(true);
$autoApprove = static function (int $loanId) use ($controller, $autoMethod, $db): bool {
    $request = (new ServerRequestFactory())->createServerRequest('POST', '/prestito');
    return (bool) $autoMethod->invoke($controller, $request, $db, $loanId);
};

$loanField = static function (int $loanId, string $col) use ($db) {
    $stmt = $db->prepare("SELECT {$col} AS v FROM prestiti WHERE id = ?");
    $stmt->bind_param('i', $loanId);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc()['v'] ?? null;
    $stmt->close();
    return $v;
};

// ── A. Setting accessor: the default must be safe for existing installs ──────
echo "A. SettingsRepository::autoApproveLoanRequests() accessor\n";
$setAuto(null);
$check($accessor() === false, '01 absent setting → off (existing installs keep the manual queue)');
$setAuto('0');
$check($accessor() === false, "02 setting '0' → off");
$setAuto('1');
$check($accessor() === true, "03 setting '1' → on");
$setAuto('yes');
$check($accessor() === false, "04 any non-'1' value → off (strict opt-in)");

// ── B. Auto-approval behaviour through the canonical pipeline ────────────────
echo "B. autoApproveLoanRequest() behaviour\n";

// 05 — OFF: the request must remain in the manual pendente queue.
$setAuto('0');
$loanOff = $makePendingLoan($makeBook(1), $makeUser());
$check($autoApprove($loanOff) === false, '05 setting off → returns false');
$check($loanField($loanOff, 'stato') === 'pendente', '05b setting off → loan stays pendente (manual workflow preserved)');

// 06-08 — ON with an available copy: promoted to da_ritirare via the real pipeline.
$setAuto('1');
$loanOn = $makePendingLoan($makeBook(1), $makeUser());
$check($autoApprove($loanOn) === true, '06 setting on + available copy → returns true');
$check($loanField($loanOn, 'stato') === 'da_ritirare', "07 auto-approved loan → stato 'da_ritirare' (waiting for pickup)");
$check($loanField($loanOn, 'pickup_deadline') !== null, '08 auto-approved immediate loan → pickup_deadline is set');

// 09 — reuses the pipeline: a copy is locked/assigned and the loan is activated.
$check((int) $loanField($loanOn, 'attivo') === 1 && $loanField($loanOn, 'copia_id') !== null,
    '09 auto-approval assigns a physical copy and activates the loan (canonical pipeline)');
// 09b — the pre-commit pickup claim must be SETTLED after the approval email
// attempt. The mailer is forced unreachable above, so the approval email
// deterministically fails: the claim taken before commit must then be
// RELEASED (sent=0, no dangling token) so the retry sweep can still deliver
// the missing announcement — a dangling claim would silently lose it forever.
// (When the email succeeds in production the claim stays sent=1 with the
// token cleared; that branch needs a deliverable transport and cannot be
// asserted portably here.)
// Le colonne di claim arrivano con la migrazione 0.7.64: garantiscile prima
// di leggerle, altrimenti su un DB non aggiornato $loanField() solleva
// "Unknown column" (MYSQLI_REPORT_STRICT) invece di un FAIL descrittivo.
if (!\App\Support\PickupNotificationSchema::ensure($db)) {
    fwrite(STDERR, "FAIL: 09b requires the pickup_notification_* columns on prestiti (migration 0.7.64)\n");
    $cleanup();
    exit(1);
}
$check(
    (int) $loanField($loanOn, 'pickup_notification_sent') === 0
        && $loanField($loanOn, 'pickup_notification_claim_token') === null,
    '09b immediate auto-approval claims the pickup announcement before a retry sweep can duplicate it'
);

// 10 — ON but no available copy: must not force-approve; request stays pending.
$setAuto('1');
$loanNoCopy = $makePendingLoan($makeBook(0), $makeUser());
$check($autoApprove($loanNoCopy) === false, '10 setting on + no available copy → returns false (graceful)');
$check($loanField($loanNoCopy, 'stato') === 'pendente', '10b no-copy request is left pending, never wrongly auto-approved');

$cleanup();
$db->close();
echo "\n{$pass} checks passed\n";
exit(0);

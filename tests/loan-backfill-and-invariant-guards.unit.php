<?php
declare(strict_types=1);

/**
 * Circulation invariant guards: the pickup-deadline backfill, the legacy
 * maintenance script's transaction discipline, the trigger single-source
 * contract, the application-date binding, and the copy-release guards on the
 * two cancel paths.
 *
 * Behavioral where it matters (the backfill runs against the real DB with a
 * sandbox), static where the invariant IS textual (a call site that must
 * exist, a predicate that must stay in the trigger file both the installer
 * and the Updater consume).
 *
 * Run: php tests/loan-backfill-and-invariant-guards.unit.php
 */

use App\Models\SettingsRepository;
use App\Support\DateHelper;
use App\Support\PickupDeadlineBackfill;

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
    fwrite(STDERR, "FAIL: database unreachable — invariant guard suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$run = bin2hex(random_bytes(6));
$prefix = "ZZ_GUARD_{$run}";
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$bookId = 0;
$userId = 0;
$cleanup = static function () use ($db, &$bookId, &$userId): void {
    if ($bookId > 0) {
        $db->query("DELETE FROM prestiti WHERE libro_id = {$bookId}");
        $db->query("DELETE FROM copie WHERE libro_id = {$bookId}");
        $db->query("DELETE FROM libri WHERE id = {$bookId}");
    }
    if ($userId > 0) {
        $db->query("DELETE FROM utenti WHERE id = {$userId}");
    }
};
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, 'FAIL: uncaught ' . $e->getMessage() . PHP_EOL);
    $db->close();
    exit(1);
});

// ── Sandbox: one book, three copies, one user ────────────────────────────────
$title = "{$prefix}_BK";
$stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES (?, 3, 0, 'prenotato')");
$stmt->bind_param('s', $title);
$stmt->execute();
$bookId = (int) $db->insert_id;
$stmt->close();
$copyIds = [];
for ($i = 1; $i <= 3; $i++) {
    $inv = "ZG{$i}-" . strtoupper(substr($run, 0, 8));
    $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'prenotato')");
    $stmt->bind_param('is', $bookId, $inv);
    $stmt->execute();
    $copyIds[] = (int) $db->insert_id;
    $stmt->close();
}
$card = 'ZG' . strtoupper(substr($run, 0, 10));
$email = "zz-guard-{$run}@test.local";
$password = password_hash('GuardSuite!1', PASSWORD_DEFAULT);
$stmt = $db->prepare(
    "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata)
     VALUES (?, 'Guard', 'Suite', ?, ?, 'standard', 'attivo', 1)"
);
$stmt->bind_param('sss', $card, $email, $password);
$stmt->execute();
$userId = (int) $db->insert_id;
$stmt->close();

$today = DateHelper::today();
$d = static fn (int $offset): string => (new DateTimeImmutable($today))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');

$insert = static function (?int $copy, string $s, string $e, string $stato, int $attivo, ?string $deadline) use ($db, $bookId, $userId): int {
    $stmt = $db->prepare(
        "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, pickup_deadline)
         VALUES (?, ?, ?, ?, ?, ?, 'diretto', ?, ?)"
    );
    $stmt->bind_param('iiisssis', $bookId, $copy, $userId, $s, $e, $stato, $attivo, $deadline);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};
$deadlineOf = static function (int $loanId) use ($db): ?string {
    $row = $db->query("SELECT pickup_deadline FROM prestiti WHERE id = {$loanId}")->fetch_assoc();
    return $row['pickup_deadline'] === null ? null : (string) $row['pickup_deadline'];
};

// ═════════ 01-06: PickupDeadlineBackfill behavior ═════════
$settings = new SettingsRepository($db);
$pickupDays = max(1, (int) ($settings->get('loans', 'pickup_expiry_days', '3') ?? 3));
$expected = $d($pickupDays);

$farLoan = $insert($copyIds[0], $d(-1), $d(30), 'da_ritirare', 1, null);
$capLoan = $insert($copyIds[1], $d(-1), $d(1), 'da_ritirare', 1, null);
$setLoan = $insert($copyIds[2], $d(-1), $d(30), 'da_ritirare', 1, $d(5));
$untouchedInCorso = $insert(null, $d(-1), $d(30), 'in_corso', 1, null);

$check(PickupDeadlineBackfill::run($db) === true, '01 the backfill runs and reports success');
$check($deadlineOf($farLoan) === $expected, '02 a NULL deadline becomes application-today + pickup_expiry_days');
$check($deadlineOf($capLoan) === $d(1), '03 the deadline is capped at the loan\'s own data_scadenza');
$check($deadlineOf($setLoan) === $d(5), '04 an already-set deadline is never rewritten');
$check($deadlineOf($untouchedInCorso) === null, '05 non-pickup states are left alone');
PickupDeadlineBackfill::run($db);
$check($deadlineOf($farLoan) === $expected && $deadlineOf($capLoan) === $d(1),
    '06 a second backfill run is a no-op (idempotent)');

// ═════════ 07-08: backfill uses the application date, not the DB session ═════
$backfillSrc = (string) file_get_contents($root . '/app/Support/PickupDeadlineBackfill.php');
$check(!str_contains($backfillSrc, 'DATE_ADD(CURDATE'), '07 the backfill no longer computes deadlines with SQL CURDATE()');
$check(str_contains($backfillSrc, 'DateHelper::today()'), '08 the backfill binds DateHelper::today() (application timezone)');

// ═════════ 09-11: legacy maintenance script transaction discipline ═══════════
$legacySrc = (string) file_get_contents($root . '/scripts/maintenance.php');
$check(str_contains($legacySrc, 'setExternalTransaction(true)'),
    '09 scripts/maintenance.php marks its transactions as caller-owned (no nested begin)');
$check(str_contains($legacySrc, 'flushDeferredNotifications()'),
    '10 scripts/maintenance.php flushes deferred promotion emails after commit');
$check(str_contains($legacySrc, 'TXN-003'),
    '11 the nested-transaction hazard is documented at the call site');

// ═════════ 12-15: @pinakes_application_date bound on every writer path ═══════
foreach ([
    ['config/container.php', '12 the shared container connection'],
    ['cron/full-maintenance.php', '13 the full-maintenance cron'],
    ['cron/automatic-notifications.php', '14 the notifications cron'],
    ['scripts/_db_bootstrap.php', '15 the scripts bootstrap'],
] as [$file, $label]) {
    $src = (string) @file_get_contents($root . '/' . $file);
    $check($src !== '' && str_contains($src, 'synchronizeDatabaseSession'),
        "{$label} synchronizes the application date");
}

// ═════════ 16-19: trigger file invariants (single source for install+upgrade) ═
$triggers = (string) file_get_contents($root . '/installer/database/triggers.sql');
$check(substr_count($triggers, 'p.copia_id = NEW.copia_id') >= 2,
    '16 the overlap gate is PER-COPY in both INSERT and UPDATE triggers');
$check(str_contains($triggers, '<=>'),
    '17 the UPDATE trigger keeps the engagement-change guard (null-safe compare)');
$check(str_contains($triggers, 'OLD.'),
    '18 the UPDATE trigger references OLD state (pre-existing-conflict exemption, #367)');
$check(str_contains($triggers, '@pinakes_application_date'),
    '19 the triggers read the bound application date (open-ended overdue semantics)');

$installerSrc = (string) file_get_contents($root . '/installer/classes/Installer.php');
$updaterSrc = (string) file_get_contents($root . '/app/Support/Updater.php');
$check(str_contains($installerSrc, 'triggers.sql'), '20 the installer consumes installer/database/triggers.sql');
$check(str_contains($updaterSrc, 'triggers.sql'), '21 Updater::reapplyTriggers consumes the SAME file (no drift possible)');

// ═════════ 22-24: copy-release guards on the two cancel paths ═══════════════
$apprSrc = (string) file_get_contents($root . '/app/Controllers/LoanApprovalController.php');
$cancelAt = strpos($apprSrc, 'public function cancelPickup');
$cancelSlice = $cancelAt === false ? '' : substr($apprSrc, $cancelAt, 14000);
$check($cancelSlice !== '' && str_contains($cancelSlice, "=== 'prenotato'"),
    '22 admin cancelPickup releases ONLY a copy actually held for the pickup');
$check($cancelSlice !== '' && !str_contains($cancelSlice, 'invalidStates'),
    '23 the permissive exclusion-list release (which freed prestato copies) is gone');
$userSrc = (string) file_get_contents($root . '/app/Controllers/UserActionsController.php');
$check(str_contains($userSrc, "SET stato = 'disponibile' WHERE id = ? AND stato = 'prenotato'"),
    '24 the user cancel twin keeps its guarded, idempotent copy release');

$cleanup();
$db->close();

echo PHP_EOL . "Passed: {$passed}   Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

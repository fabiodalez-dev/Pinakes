<?php
declare(strict_types=1);

/**
 * Regression suite: terminal loan notifications must reach the borrower even
 * when the title was soft-deleted meanwhile (#381 class).
 *
 * The maintenance sweeps (checkExpiredPickups / checkExpiredReservations) and
 * the return path are deliberately CI-SOFT-DELETE-EXEMPT: they expire/close
 * circulation on archived titles. Their notification siblings, however, used
 * `JOIN libri … AND l.deleted_at IS NULL`, so the loan row was never fetched
 * and the borrower silently lost the email while the loan still expired and
 * the copy was freed. Fixed for: sendPickupExpiredNotification,
 * sendReservationExpiredNotification, sendLoanReturnedNotification,
 * notifyAdminsOverdue, and the ReservationsAdminController cancel-via-edit
 * fetch. This suite is DISCRIMINATING: every behavioral check fails on the
 * pre-fix queries.
 *
 * Probe technique (same as loan-pickup-cancelled-softdelete.unit.php):
 * resolveRecipientLocale() is the first call after a successful fetch, so a
 * recording override that throws gives a deterministic, SMTP-free signal that
 * the JOIN found the loan. Negative controls (loan id 0) prove the probe
 * discriminates.
 *
 * Run: php tests/loan-archived-title-notifications.unit.php
 */

use App\Support\DateHelper;
use App\Support\NotificationService;

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
    fwrite(STDERR, "FAIL: database unreachable — archived-title notification suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}

/** Fetch-reached probe: flags and stops before any transport is contacted. */
final class ArchivedProbeNotificationService extends NotificationService
{
    public bool $localeResolved = false;
    public string $capturedEmail = '';

    public function resolveRecipientLocale(string $email): string
    {
        $this->localeResolved = true;
        $this->capturedEmail = $email;
        throw new RuntimeException('Injected stop before sending the test notification');
    }
}

/**
 * Deadline-plumbing probe: lets locale resolution pass, then records the raw
 * date handed to formatEmailDate() and stops before the send.
 */
final class DeadlineProbeNotificationService extends NotificationService
{
    public ?string $capturedDate = null;

    public function resolveRecipientLocale(string $email): string
    {
        return 'it_IT';
    }

    public function formatEmailDate(string $dateString, bool $includeTime = false, ?string $locale = null): string
    {
        $this->capturedDate = $dateString;
        throw new RuntimeException('Injected stop after capturing the deadline');
    }
}

$run = bin2hex(random_bytes(6));
$title = "ZZ_ARCHNOTIF_{$run}";
$email = "zz-archnotif-{$run}@test.local";
$inventory = "ZZAN-{$run}";
$bookId = 0;
$userId = 0;
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

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

// ── Fixture: an expired-pickup loan on a book we then soft-delete ────────────
$stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES (?, 1, 0, 'prestato')");
$stmt->bind_param('s', $title);
$stmt->execute();
$bookId = (int) $db->insert_id;
$stmt->close();

$stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'prenotato')");
$stmt->bind_param('is', $bookId, $inventory);
$stmt->execute();
$copyId = (int) $db->insert_id;
$stmt->close();

$card = 'ZZAN' . strtoupper($run);
$password = password_hash('ArchivedNotif!1', PASSWORD_DEFAULT);
$stmt = $db->prepare(
    "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata)
     VALUES (?, 'Archived', 'Notif', ?, ?, 'standard', 'attivo', 1)"
);
$stmt->bind_param('sss', $card, $email, $password);
$stmt->execute();
$userId = (int) $db->insert_id;
$stmt->close();

$start = DateHelper::today();
$end = (new DateTimeImmutable($start))->modify('+14 days')->format('Y-m-d');
$deadline = (new DateTimeImmutable($start))->modify('+3 days')->format('Y-m-d');
$stmt = $db->prepare(
    "INSERT INTO prestiti
        (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, pickup_deadline)
     VALUES (?, ?, ?, ?, ?, 'da_ritirare', 'diretto', 1, ?)"
);
$stmt->bind_param('iiisss', $bookId, $copyId, $userId, $start, $end, $deadline);
$stmt->execute();
$loanId = (int) $db->insert_id;
$stmt->close();

$db->query("UPDATE libri SET deleted_at = NOW() WHERE id = {$bookId}");

// ── 1-3. Negative controls: non-existent loan never resolves a locale ────────
$svc = new ArchivedProbeNotificationService($db);
$svc->sendPickupExpiredNotification(0);
$check($svc->localeResolved === false, '01 pickup-expired probe stays false with no loan row (discriminating control)');

$svc = new ArchivedProbeNotificationService($db);
$svc->sendReservationExpiredNotification(0);
$check($svc->localeResolved === false, '02 reservation-expired probe stays false with no loan row');

$svc = new ArchivedProbeNotificationService($db);
$svc->sendLoanReturnedNotification(0);
$check($svc->localeResolved === false, '03 loan-returned probe stays false with no loan row');

// ── 4-9. Archived title: every fixed sender must still fetch the loan ────────
$svc = new ArchivedProbeNotificationService($db);
$svc->sendPickupExpiredNotification($loanId);
$check($svc->localeResolved === true, '04 pickup-expired email fetches the loan for a soft-deleted book');
$check($svc->capturedEmail === $email, '05 pickup-expired resolves the borrower email for the archived title');

$svc = new ArchivedProbeNotificationService($db);
$svc->sendReservationExpiredNotification($loanId);
$check($svc->localeResolved === true, '06 reservation-expired email fetches the loan for a soft-deleted book');
$check($svc->capturedEmail === $email, '07 reservation-expired resolves the borrower email for the archived title');

$svc = new ArchivedProbeNotificationService($db);
$svc->sendLoanReturnedNotification($loanId);
$check($svc->localeResolved === true, '08 return-confirmation email fetches the loan for a soft-deleted book');
$check($svc->capturedEmail === $email, '09 return-confirmation resolves the borrower email for the archived title');

// ── 10. Admin overdue alert also reaches past the JOIN for archived titles ───
$svc = new ArchivedProbeNotificationService($db);
$svc->notifyAdminsOverdue($loanId);
$check($svc->localeResolved === true, '10 admin overdue alert fetches the loan for a soft-deleted book');

// ── 11-13. Sanity: a live (non-archived) book behaves identically ────────────
$db->query("UPDATE libri SET deleted_at = NULL WHERE id = {$bookId}");
$svc = new ArchivedProbeNotificationService($db);
$svc->sendPickupExpiredNotification($loanId);
$check($svc->localeResolved === true, '11 pickup-expired still fetches a live book (no over-tightening)');
$svc = new ArchivedProbeNotificationService($db);
$svc->sendReservationExpiredNotification($loanId);
$check($svc->localeResolved === true, '12 reservation-expired still fetches a live book');
$svc = new ArchivedProbeNotificationService($db);
$svc->sendLoanReturnedNotification($loanId);
$check($svc->localeResolved === true, '13 return-confirmation still fetches a live book');
$db->query("UPDATE libri SET deleted_at = NOW() WHERE id = {$bookId}");

// ── 14-15. Deadline plumbing: explicit parameter wins, row value is fallback ─
$db->query("UPDATE prestiti SET pickup_deadline = NULL WHERE id = {$loanId}");
$svc = new DeadlineProbeNotificationService($db);
$svc->sendPickupExpiredNotification($loanId, '2030-05-05');
$check($svc->capturedDate === '2030-05-05', '14 explicit deadline parameter reaches the email even when the row was NULLed');

$db->query("UPDATE prestiti SET pickup_deadline = '{$deadline}' WHERE id = {$loanId}");
$svc = new DeadlineProbeNotificationService($db);
$svc->sendPickupExpiredNotification($loanId, null);
$check($svc->capturedDate === $deadline, '15 with no parameter the row pickup_deadline is still used (fallback intact)');

// ── 16-24. Static guards: the fixed queries and their exempt annotations ─────
$notifSrc = (string) file_get_contents($root . '/app/Support/NotificationService.php');
$sliceOf = static function (string $src, string $fn): string {
    $at = strpos($src, "function {$fn}(");
    return $at === false ? '' : substr($src, $at, 2600);
};

$pickupExpired = $sliceOf($notifSrc, 'sendPickupExpiredNotification');
$check($pickupExpired !== '' && !str_contains($pickupExpired, 'deleted_at IS NULL'),
    '16 sendPickupExpiredNotification query no longer filters deleted_at');
$reservationExpired = $sliceOf($notifSrc, 'sendReservationExpiredNotification');
$check($reservationExpired !== '' && !str_contains($reservationExpired, 'deleted_at IS NULL'),
    '17 sendReservationExpiredNotification query no longer filters deleted_at');
$returned = $sliceOf($notifSrc, 'sendLoanReturnedNotification');
$check($returned !== '' && !str_contains($returned, 'deleted_at IS NULL'),
    '18 sendLoanReturnedNotification query no longer filters deleted_at');
$adminsOverdue = $sliceOf($notifSrc, 'notifyAdminsOverdue');
$check($adminsOverdue !== '' && !str_contains($adminsOverdue, 'deleted_at IS NULL'),
    '19 notifyAdminsOverdue query no longer filters deleted_at');

// The soft-delete CI gate requires an explicit exemption where the filter is
// deliberately absent — all four senders must carry the annotation.
foreach ([
    ['sendPickupExpiredNotification', '20'],
    ['sendReservationExpiredNotification', '21'],
    ['sendLoanReturnedNotification', '22'],
    ['notifyAdminsOverdue', '23'],
] as [$fn, $n]) {
    $fnAt = strpos($notifSrc, "function {$fn}(");
    $window = $fnAt === false ? '' : substr($notifSrc, max(0, $fnAt - 900), 1200 + strlen($fn));
    $check(str_contains($window, 'CI-SOFT-DELETE-EXEMPT'),
        "{$n} {$fn} carries the CI-SOFT-DELETE-EXEMPT annotation");
}

$adminCtrl = (string) file_get_contents($root . '/app/Controllers/ReservationsAdminController.php');
$cancelBlockAt = strpos($adminCtrl, "utente_nome, u.email, l.titolo");
$cancelBlock = $cancelBlockAt === false ? '' : substr($adminCtrl, max(0, $cancelBlockAt - 700), 1400);
$check($cancelBlock !== '' && !str_contains($cancelBlock, 'deleted_at IS NULL')
    && str_contains($cancelBlock, 'CI-SOFT-DELETE-EXEMPT'),
    '24 admin cancel-via-edit notification fetch is exempt and unfiltered');

// ── 25-26. Recipient-locale reason fallback (rejected-loan emails) ───────────
$check(substr_count($notifSrc, "translateInLocale('Nessun motivo specificato'") >= 2
    && !str_contains($notifSrc, "__('Nessun motivo specificato')"),
    '25 both reject senders localize the fallback reason in the RECIPIENT locale');

$ref = new ReflectionMethod(NotificationService::class, 'translateInLocale');
$plainSvc = new NotificationService($db);
$translated = (string) $ref->invoke($plainSvc, 'Nessun motivo specificato', 'en_US');
$check($translated !== '' && $translated !== 'Nessun motivo specificato',
    '26 translateInLocale actually renders the fallback reason in another locale (en_US)');

// ── 27. Sweep passes the captured deadline to the sender ─────────────────────
$maintSrc = (string) file_get_contents($root . '/app/Support/MaintenanceService.php');
$check(str_contains($maintSrc, 'sendPickupExpiredNotification(') && preg_match(
    '/sendPickupExpiredNotification\(\s*\$id\s*,/', $maintSrc) === 1,
    '27 checkExpiredPickups passes the elapsed deadline captured under lock to the email');

$cleanup();
$db->close();

echo PHP_EOL . "Passed: {$passed}   Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

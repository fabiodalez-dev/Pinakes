<?php
declare(strict_types=1);

/**
 * Regression: sendPickupCancelledNotification() must reach the borrower even
 * when the book is soft-deleted.
 *
 * cancelPickup() deliberately locks and closes a waiting-for-pickup loan WITHOUT
 * a libri.deleted_at filter (an admin can cancel/expire a pickup for a book that
 * has since been soft-deleted). The terminal-notification query, however, used
 * `JOIN libri ... AND l.deleted_at IS NULL`, so for a soft-deleted book the loan
 * row was never fetched and the method returned false BEFORE sending — the
 * borrower silently lost the loan_pickup_expired / loan_pickup_cancelled email.
 *
 * The method calls resolveRecipientLocale() immediately after fetching the loan
 * and before any send. Overriding it into a recorder gives a deterministic,
 * SMTP-free probe: the flag is set iff the JOIN found the loan. A non-existent
 * loan id is the negative control proving the probe actually discriminates.
 *
 * Run: php tests/loan-pickup-cancelled-softdelete.unit.php
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
    fwrite(STDERR, "FAIL: database unreachable — soft-delete notification test is mandatory: {$e->getMessage()}\n");
    exit(1);
}

/**
 * Records that the method got past the JOIN + fetch (resolveRecipientLocale is
 * the first call after a successful fetch) without opening an SMTP connection.
 */
final class RecordingCancelNotificationService extends NotificationService
{
    public bool $localeResolved = false;
    public string $capturedEmail = '';

    public function resolveRecipientLocale(string $email): string
    {
        $this->localeResolved = true;
        $this->capturedEmail = $email;
        return 'it_IT';
    }
}

$run = bin2hex(random_bytes(6));
$title = "ZZ_PICKUP_SD_{$run}";
$email = "zz-pickup-sd-{$run}@test.local";
$inventory = "ZZPSD-{$run}";
$bookId = 0;
$copyId = 0;
$userId = 0;
$loanId = 0;
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$cleanup = static function () use ($db, &$bookId, &$userId): void {
    $db->query("DELETE FROM prestiti WHERE libro_id = {$bookId}");
    $db->query("DELETE FROM copie WHERE libro_id = {$bookId}");
    $db->query("DELETE FROM libri WHERE id = {$bookId}");
    $db->query("DELETE FROM utenti WHERE id = {$userId}");
};
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    $db->close();
    exit(1);
});

// ── Fixture: a da_ritirare loan on a book we then soft-delete ────────────────
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

$card = 'ZZPSD' . strtoupper($run);
$password = password_hash('PickupSoftDelete!', PASSWORD_DEFAULT);
$stmt = $db->prepare(
    "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata)
     VALUES (?, 'Pickup', 'SoftDelete', ?, ?, 'standard', 'attivo', 1)"
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

// Soft-delete the book — exactly the state cancelPickup() still processes.
$db->query("UPDATE libri SET deleted_at = NOW() WHERE id = {$bookId}");

// ── Negative control: a non-existent loan must NOT resolve a locale ──────────
$controlSvc = new RecordingCancelNotificationService($db);
$controlSvc->sendPickupCancelledNotification(0, '', 'annullato');
$check($controlSvc->localeResolved === false, '1 the probe stays false when no loan row is fetched (discriminating)');

// ── annullato branch reaches the borrower despite the soft-deleted book ──────
$cancelSvc = new RecordingCancelNotificationService($db);
$cancelSvc->sendPickupCancelledNotification($loanId, '', 'annullato');
$check($cancelSvc->localeResolved === true, '2 cancelled notification fetches the loan even for a soft-deleted book');
$check($cancelSvc->capturedEmail === $email, '3 the fetched loan carries the correct borrower email');

// ── scaduto branch (loan_pickup_expired) also reaches past the JOIN ──────────
$expiredSvc = new RecordingCancelNotificationService($db);
$expiredSvc->sendPickupCancelledNotification($loanId, '', 'scaduto', $deadline);
$check($expiredSvc->localeResolved === true, '4 expired notification also fetches the soft-deleted book loan');
$check($expiredSvc->capturedEmail === $email, '5 expired branch resolves the borrower for the soft-deleted book');

$cleanup();
$db->close();
echo "\n{$passed} checks passed" . ($failed ? ", {$failed} FAILED" : '') . PHP_EOL;
exit($failed ? 1 : 0);

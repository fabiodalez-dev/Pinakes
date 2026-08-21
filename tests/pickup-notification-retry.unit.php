<?php
declare(strict_types=1);

use App\Support\DateHelper;
use App\Support\NotificationService;
use App\Support\PickupNotificationSchema;

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
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — pickup retry test is mandatory: {$e->getMessage()}\n");
    exit(1);
}

final class ThrowingPickupNotificationService extends NotificationService
{
    public function resolveRecipientLocale(string $email): string
    {
        throw new RuntimeException('Injected failure after pickup claim');
    }
}

final class AbaPickupNotificationService extends NotificationService
{
    public function __construct(private mysqli $testDb, private int $testLoanId)
    {
        parent::__construct($testDb);
    }

    public function resolveRecipientLocale(string $email): string
    {
        // Simulate: claimant A sends; the loan is reassigned/reset; claimant B
        // acquires a new token; then A fails late. A must not clear B's claim.
        $replacementToken = str_repeat('b', 32);
        $attemptedAt = DateHelper::now();
        $stmt = $this->testDb->prepare(
            "UPDATE prestiti
                SET pickup_notification_sent = 1,
                    pickup_notification_claim_token = ?,
                    pickup_notification_last_attempt_at = ?
              WHERE id = ?"
        );
        $stmt->bind_param('ssi', $replacementToken, $attemptedAt, $this->testLoanId);
        $stmt->execute();
        $stmt->close();
        throw new RuntimeException('Injected late failure after another claimant took ownership');
    }
}

$run = bin2hex(random_bytes(6));
$title = "ZZ_PICKUP_RETRY_{$run}";
$email = "zz-pickup-retry-{$run}@test.local";
$inventory = "ZZPR-{$run}";
$bookId = 0;
$copyId = 0;
$userId = 0;
$replacementUserId = 0;
$loanId = 0;
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$cleanup = static function () use ($db, &$bookId, &$userId, &$replacementUserId): void {
    // All fixture identifiers start at zero, which is not a generated ID.
    // Unconditional deletes therefore keep partial-setup cleanup simple and
    // let static analysis follow the captured by-reference values correctly.
    $db->query("DELETE FROM prestiti WHERE libro_id = {$bookId}");
    $db->query("DELETE FROM copie WHERE libro_id = {$bookId}");
    $db->query("DELETE FROM libri WHERE id = {$bookId}");
    $db->query("DELETE FROM utenti WHERE id = {$userId}");
    $db->query("DELETE FROM utenti WHERE id = {$replacementUserId}");
};

try {
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

    $card = 'ZZPR' . strtoupper($run);
    $password = password_hash('PickupRetry!', PASSWORD_DEFAULT);
    $stmt = $db->prepare(
        "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata)
         VALUES (?, 'Pickup', 'Retry', ?, ?, 'standard', 'attivo', 1)"
    );
    $stmt->bind_param('sss', $card, $email, $password);
    $stmt->execute();
    $userId = (int) $db->insert_id;
    $stmt->close();

    $replacementCard = 'ZZPRR' . strtoupper($run);
    $replacementEmail = "zz-pickup-retry-replacement-{$run}@test.local";
    $stmt = $db->prepare(
        "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata)
         VALUES (?, 'Pickup', 'Replacement', ?, ?, 'standard', 'attivo', 1)"
    );
    $stmt->bind_param('sss', $replacementCard, $replacementEmail, $password);
    $stmt->execute();
    $replacementUserId = (int) $db->insert_id;
    $stmt->close();

    $start = DateHelper::today();
    $end = (new DateTimeImmutable($start))->modify('+14 days')->format('Y-m-d');
    $deadline = (new DateTimeImmutable($start))->modify('+3 days')->format('Y-m-d');
    $stmt = $db->prepare(
        "INSERT INTO prestiti
            (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine,
             attivo, pickup_deadline, pickup_notification_sent)
         VALUES (?, ?, ?, ?, ?, 'da_ritirare', 'diretto', 1, ?, 0)"
    );
    $stmt->bind_param('iiisss', $bookId, $copyId, $userId, $start, $end, $deadline);
    $stmt->execute();
    $loanId = (int) $db->insert_id;
    $stmt->close();

    $sent = (new ThrowingPickupNotificationService($db))->sendPickupReadyNotification($loanId);
    $retryState = $db->query(
        "SELECT pickup_notification_sent, pickup_notification_claim_token,
                pickup_notification_last_attempt_at
           FROM prestiti WHERE id = {$loanId}"
    )->fetch_assoc();
    $check($sent === false, 'an exception after the atomic claim is reported as a failed send');
    $check(
        (int) ($retryState['pickup_notification_sent'] ?? 1) === 0
            && ($retryState['pickup_notification_claim_token'] ?? null) === null,
        'the catch path releases only its owned claim so the next sweep can retry'
    );
    $check(
        !empty($retryState['pickup_notification_last_attempt_at']),
        'a failed attempt retains its timestamp for fair retry ordering'
    );

    // A worker that dies after claiming leaves sent=1 plus a token behind. Once
    // the 15-minute lease has expired, a later sender must be able to take over;
    // the injected failure then proves that the new owner can release its claim.
    $orphanToken = str_repeat('c', 32);
    // Come per la lease viva più sotto: parti dal riferimento UTC di
    // produzione, non da DateHelper::now() (fuso applicativo).
    $orphanAttemptedAt = (new DateTimeImmutable(PickupNotificationSchema::claimLeaseWindow()['attemptedAt']))
        ->modify('-1 day')
        ->format('Y-m-d H:i:s');
    $stmt = $db->prepare(
        "UPDATE prestiti
            SET pickup_notification_sent = 1,
                pickup_notification_claim_token = ?,
                pickup_notification_last_attempt_at = ?
          WHERE id = ?"
    );
    $stmt->bind_param('ssi', $orphanToken, $orphanAttemptedAt, $loanId);
    $stmt->execute();
    $stmt->close();

    $orphanRetrySent = (new ThrowingPickupNotificationService($db))->sendPickupReadyNotification($loanId);
    $orphanState = $db->query(
        "SELECT pickup_notification_sent, pickup_notification_claim_token,
                pickup_notification_last_attempt_at
           FROM prestiti WHERE id = {$loanId}"
    )->fetch_assoc();
    $check($orphanRetrySent === false, 'a stale orphan claim is acquired before the injected delivery failure');
    $check(
        (int) ($orphanState['pickup_notification_sent'] ?? 1) === 0
            && ($orphanState['pickup_notification_claim_token'] ?? null) === null
            && (string) ($orphanState['pickup_notification_last_attempt_at'] ?? '') > $orphanAttemptedAt,
        'an expired orphan lease is recoverable and its new owner can release it'
    );

    // Conversely, a live claimant must retain ownership until its lease expires.
    $liveToken = str_repeat('d', 32);
    // Stesso riferimento UTC della produzione: claimLeaseWindow() calcola
    // staleBefore in UTC, mentre DateHelper::now() usa il fuso applicativo
    // (Europe/Rome, +1/+2h). Con quello scarto il check "lease ancora viva"
    // passerebbe anche con una lease molto più corta o azzerata.
    $liveAttemptedAt = PickupNotificationSchema::claimLeaseWindow()['attemptedAt'];
    $stmt = $db->prepare(
        "UPDATE prestiti
            SET pickup_notification_sent = 1,
                pickup_notification_claim_token = ?,
                pickup_notification_last_attempt_at = ?
          WHERE id = ?"
    );
    $stmt->bind_param('ssi', $liveToken, $liveAttemptedAt, $loanId);
    $stmt->execute();
    $stmt->close();

    $liveRetrySent = (new ThrowingPickupNotificationService($db))->sendPickupReadyNotification($loanId);
    $liveState = $db->query(
        "SELECT pickup_notification_sent, pickup_notification_claim_token,
                pickup_notification_last_attempt_at
           FROM prestiti WHERE id = {$loanId}"
    )->fetch_assoc();
    $check($liveRetrySent === false, 'a send cannot steal a claim whose lease is still live');
    $check(
        (int) ($liveState['pickup_notification_sent'] ?? 0) === 1
            && ($liveState['pickup_notification_claim_token'] ?? '') === $liveToken
            && ($liveState['pickup_notification_last_attempt_at'] ?? '') === $liveAttemptedAt,
        'the live owner token and lease timestamp remain unchanged'
    );

    $db->query("UPDATE prestiti SET pickup_notification_sent = 0, pickup_notification_claim_token = NULL WHERE id = {$loanId}");
    $abaSent = (new AbaPickupNotificationService($db, $loanId))->sendPickupReadyNotification($loanId);
    $abaState = $db->query(
        "SELECT pickup_notification_sent, pickup_notification_claim_token
           FROM prestiti WHERE id = {$loanId}"
    )->fetch_assoc();
    $check($abaSent === false, 'late failure after reassignment is reported without masking ownership');
    $check(
        (int) ($abaState['pickup_notification_sent'] ?? 0) === 1
            && ($abaState['pickup_notification_claim_token'] ?? '') === str_repeat('b', 32),
        'an old sender cannot release a newer claimant (ABA ownership guard)'
    );

    // Simulate a reassignment committed after a worker read the old recipient
    // but before it attempted the atomic claim. The user predicate must make the
    // stale claim a no-op, leaving the replacement recipient retryable.
    $staleRecipientUserId = $userId;
    $stmt = $db->prepare(
        "UPDATE prestiti
            SET utente_id = ?, pickup_notification_sent = 0,
                pickup_notification_claim_token = NULL,
                pickup_notification_last_attempt_at = NULL
          WHERE id = ?"
    );
    $stmt->bind_param('ii', $replacementUserId, $loanId);
    $stmt->execute();
    $stmt->close();

    $staleClaimToken = str_repeat('e', 32);
    $staleClaimAttemptedAt = DateHelper::now();
    $staleBefore = (new DateTimeImmutable($staleClaimAttemptedAt))
        ->modify('-15 minutes')
        ->format('Y-m-d H:i:s');
    $stmt = $db->prepare(
        "UPDATE prestiti
            SET pickup_notification_sent = 1,
                pickup_notification_claim_token = ?,
                pickup_notification_last_attempt_at = ?
          WHERE id = ? AND utente_id = ?
            AND attivo = 1 AND stato = 'da_ritirare'
            AND (
                  pickup_notification_sent IS NULL
                  OR pickup_notification_sent = 0
                  OR (
                      pickup_notification_sent = 1
                      AND pickup_notification_claim_token IS NOT NULL
                      AND pickup_notification_last_attempt_at < ?
                  )
            )"
    );
    $stmt->bind_param('ssiis', $staleClaimToken, $staleClaimAttemptedAt, $loanId, $staleRecipientUserId, $staleBefore);
    $stmt->execute();
    $staleClaimRows = $stmt->affected_rows;
    $stmt->close();
    $reassignedState = $db->query(
        "SELECT utente_id, pickup_notification_sent, pickup_notification_claim_token
           FROM prestiti WHERE id = {$loanId}"
    )->fetch_assoc();
    $check(
        $staleClaimRows === 0
            && (int) ($reassignedState['utente_id'] ?? 0) === $replacementUserId
            && (int) ($reassignedState['pickup_notification_sent'] ?? 1) === 0
            && ($reassignedState['pickup_notification_claim_token'] ?? null) === null,
        'a claim bound to the stale user cannot consume a reassigned recipient retry'
    );

    $fixedStaleBefore = '2026-08-21 10:00:00';
    $check(
        PickupNotificationSchema::isClaimLive(str_repeat('f', 32), '2026-08-21 10:00:00', $fixedStaleBefore)
            && !PickupNotificationSchema::isClaimLive(str_repeat('f', 32), '2026-08-21 09:59:59', $fixedStaleBefore)
            && !PickupNotificationSchema::isClaimLive(null, null, $fixedStaleBefore),
        'reassignment blocks a live pickup claim but can clear an expired orphan lease'
    );
    $check(
        PickupNotificationSchema::isClaimLive(str_repeat('f', 32), null, $fixedStaleBefore),
        'a malformed token without a timestamp fails closed during reassignment'
    );

    $cron = (string) file_get_contents($root . '/cron/automatic-notifications.php');
    $check(
        str_contains($cron, '$notificationService->retryUnsentPickupNotifications()'),
        'the hourly notification cron invokes the pickup retry sweep'
    );
    $notificationSource = (string) file_get_contents($root . '/app/Support/NotificationService.php');
    $claimStart = strpos($notificationSource, '$claimStmt = $this->db->prepare');
    $claimEnd = $claimStart === false ? false : strpos($notificationSource, '$claimStmt->close()', $claimStart);
    $claimSource = $claimStart === false || $claimEnd === false
        ? ''
        : substr($notificationSource, $claimStart, $claimEnd - $claimStart);
    $check(
        str_contains($claimSource, 'WHERE id = ? AND utente_id = ?')
            && str_contains($claimSource, '$recipientUserId')
            && str_contains($claimSource, "bind_param('ssiis'"),
        'the production pickup claim binds its atomic guard to the recipient user'
    );
    $check(
        str_contains($notificationSource, 'pickup_notification_last_attempt_at IS NULL DESC')
            && str_contains($notificationSource, 'pickup_notification_last_attempt_at ASC'),
        'retry batches prioritize never-attempted rows before recurring poison recipients'
    );
} catch (Throwable $e) {
    $failed++;
    fwrite(STDERR, "UNCAUGHT TEST ERROR: {$e->getMessage()}\n{$e->getTraceAsString()}\n");
} finally {
    try {
        $cleanup();
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "CLEANUP ERROR: {$e->getMessage()}\n");
    }
    $db->close();
}

echo "\n{$passed} PASS, {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);

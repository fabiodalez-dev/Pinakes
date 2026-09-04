<?php
declare(strict_types=1);

/** Behavioural contract for the durable circulation-email outbox. */

use App\Support\DateHelper;
use App\Support\EmailOutboxSchema;
use App\Support\Mailer;
use App\Support\NotificationService;

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
    DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    echo 'SKIP: database not reachable (' . $e->getMessage() . ")\n";
    exit(0);
}

$email = 'zz-outbox-0780-' . bin2hex(random_bytes(5)) . '@test.local';
$cleanup = static function () use ($db, $email): void {
    try {
        $stmt = $db->prepare('DELETE FROM email_delivery_outbox WHERE recipient_email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable) {
    }
};
register_shutdown_function($cleanup);

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$check(EmailOutboxSchema::ensure($db), 'runtime schema creates or detects the outbox');
$cleanup();

// Deterministically stop before any real transport attempt.
$smtpProbe = new ReflectionProperty(Mailer::class, 'smtpReachable');
$smtpProbe->setValue(null, false);
$service = new NotificationService($db);
$send = new ReflectionMethod(NotificationService::class, 'sendWithRetry');
$sent = $send->invoke($service, $email, 'reservation_cancelled', [
    'utente_nome' => 'Outbox Test',
    'libro_titolo' => 'Fixture',
    'motivo' => 'Test',
]);
$check($sent === false, 'forced-unreachable transport reports failure');

$stmt = $db->prepare('SELECT id, attempts, available_at, claim_token, claimed_at, variables_json FROM email_delivery_outbox WHERE recipient_email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$check($row !== null, 'failed terminal email remains durably queued');
$check((int) ($row['attempts'] ?? 0) === 1 && $row['claim_token'] === null && $row['claimed_at'] === null,
    'direct-send claim is released with an attempt count');
$variables = json_decode((string) ($row['variables_json'] ?? ''), true);
$check(is_array($variables) && ($variables['libro_titolo'] ?? null) === 'Fixture',
    'template variables survive JSON persistence');

$id = (int) ($row['id'] ?? 0);
$db->query("UPDATE email_delivery_outbox SET available_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = {$id}");
$retried = $service->retryQueuedEmailDeliveries(10);
$after = $db->query("SELECT attempts, claim_token, claimed_at FROM email_delivery_outbox WHERE id = {$id}")->fetch_assoc();
$check($retried === 0, 'failed retry is not counted as delivered');
$check((int) ($after['attempts'] ?? 0) === 2 && $after['claim_token'] === null && $after['claimed_at'] === null,
    'retry claim is released with exponential-backoff state');

$cleanup();
$remaining = (int) $db->query("SELECT COUNT(*) FROM email_delivery_outbox WHERE recipient_email = '" . $db->real_escape_string($email) . "'")->fetch_row()[0];
$check($remaining === 0, 'test outbox row is cleaned up');
$db->close();

echo "\nPassed: {$passed}   Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);

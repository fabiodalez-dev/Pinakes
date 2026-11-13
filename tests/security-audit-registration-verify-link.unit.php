<?php
declare(strict_types=1);

/**
 * Security regression (audit 2026-11,
 * host-header:email-verification-link:NotificationService::sendUserRegistrationPending).
 *
 * The self-registration email built its "confirm your email" link with
 * absoluteUrl(), which falls back to the request's Host header when neither
 * APP_CANONICAL_URL nor APP_TRUSTED_HOSTS is set. An anonymous registrant could
 * therefore have the library mail a victim a live verification token pointing at
 * a host of the registrant's choosing.
 *
 * Invariant: the link uses TrustedLink, like the recovery and invitation mails.
 * With no operator-configured host, no email is sent (fail closed, as
 * PasswordController::forgot() does); with one, the link uses it whatever the
 * request said.
 *
 * DB-free: the service is built without its constructor, over a connection-less
 * mysqli and an EmailService double that records what would have been sent.
 *
 * Run:  php tests/security-audit-registration-verify-link.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/helpers/security-audit-fake-db.php';

use App\Support\EmailService;
use App\Support\NotificationService;

final class RecordingEmailService extends EmailService
{
    /** @var list<array{to: string, template: string, variables: array}> */
    public array $sent = [];

    public function __construct()
    {
    }

    public function sendTemplate(string $to, string $templateName, array $variables = [], ?string $locale = null, array $attachments = []): bool
    {
        $this->sent[] = ['to' => $to, 'template' => $templateName, 'variables' => $variables];
        return true;
    }
}

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$setEnv = static function (string $name, ?string $value): void {
    if ($value === null) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    } else {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
    }
};

$token = bin2hex(random_bytes(24));
/** @return list<array{to: string, template: string, variables: array}> */
$register = static function () use ($token): array {
    $service = (new ReflectionClass(NotificationService::class))->newInstanceWithoutConstructor();
    $mailer = new RecordingEmailService();
    $user = ['nome' => 'Audit', 'cognome' => 'Victim', 'email' => 'audit-a1-victim@example.test',
        'codice_tessera' => 'AUDA1', 'created_at' => '2026-11-13 10:00:00', 'token_verifica_email' => $token];
    (new ReflectionProperty(NotificationService::class, 'db'))->setValue($service, new SecurityAuditFakeDb([$user, ['locale' => 'it_IT']], 2));
    (new ReflectionProperty(NotificationService::class, 'emailService'))->setValue($service, $mailer);
    $service->sendUserRegistrationPending(77);
    return $mailer->sent;
};

$saved = [];
foreach (['APP_CANONICAL_URL', 'APP_TRUSTED_HOSTS'] as $name) {
    $saved[$name] = getenv($name) === false ? null : (string) getenv($name);
}
$savedHost = $_SERVER['HTTP_HOST'] ?? null;
// The anonymous registrant's forged Host header.
$_SERVER['HTTP_HOST'] = 'attacker.example';

try {
    echo "No operator-configured host\n";
    $setEnv('APP_CANONICAL_URL', null);
    $setEnv('APP_TRUSTED_HOSTS', null);
    $sent = $register();
    $leaked = array_filter($sent, static fn(array $m): bool => str_contains(json_encode($m['variables']) ?: '', 'attacker.example'));
    $check($leaked === [], 'no email carries a link to the Host the request supplied');
    $check($sent === [], 'the email is suppressed rather than sent with an unverifiable link');

    echo "APP_CANONICAL_URL configured\n";
    $setEnv('APP_CANONICAL_URL', 'https://library.example');
    $sent = $register();
    $section = (string) ($sent[0]['variables']['sezione_verifica'] ?? '');
    $check(count($sent) === 1, 'the registration email is sent');
    $check(str_contains($section, 'https://library.example/') && str_contains($section, $token),
        'its verification link uses the canonical host and carries the token');
    $check(!str_contains($section, 'attacker.example'), 'and never the request Host');
} finally {
    foreach ($saved as $name => $value) {
        $setEnv($name, $value);
    }
    if ($savedHost === null) {
        unset($_SERVER['HTTP_HOST']);
    } else {
        $_SERVER['HTTP_HOST'] = $savedHost;
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

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
 * With no host at all the administrators must be told to act: an in-app
 * notice links to the account, which stays 'sospeso' and can be activated with
 * "Attiva utente" (POST /admin/users/{id}/activate-directly).
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

/** Statement double that keeps the values bound to it. */
final class RecordingStmt extends mysqli_stmt
{
    /** @var list<mixed> */
    public array $bound = [];
    public function __construct(public string $sql, private ?array $row) {}
    public function bind_param(string $types, mixed &...$vars): bool { $this->bound = $vars; return true; }
    public function execute(?array $params = null): bool { return true; }
    public function get_result(): mysqli_result|false { return new SecurityAuditFakeResult($this->row); }
    public function close(): true { return true; }
}

/** Connection-less mysqli answering SELECTs from $rows and recording every statement. */
final class RecordingDb extends mysqli
{
    /** @var list<RecordingStmt> */
    public array $stmts = [];
    public function __construct(private array $rows) {}
    public function prepare(string $query): mysqli_stmt|false
    {
        $stmt = new RecordingStmt($query, str_contains($query, 'SELECT') ? array_shift($this->rows) : null);
        $this->stmts[] = $stmt;
        return $stmt;
    }
    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool { return true; }
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
$lastDb = null;
$register = static function () use ($token, &$lastDb): array {
    $service = (new ReflectionClass(NotificationService::class))->newInstanceWithoutConstructor();
    $mailer = new RecordingEmailService();
    $user = ['nome' => 'Audit', 'cognome' => 'Victim', 'email' => 'audit-a1-victim@example.test',
        'codice_tessera' => 'AUDA1', 'created_at' => '2026-11-13 10:00:00', 'token_verifica_email' => $token];
    $lastDb = new RecordingDb([$user, ['locale' => 'it_IT']]);
    (new ReflectionProperty(NotificationService::class, 'db'))->setValue($service, $lastDb);
    (new ReflectionProperty(NotificationService::class, 'emailService'))->setValue($service, $mailer);
    $service->sendUserRegistrationPending(77);
    return $mailer->sent;
};
/** @return list<RecordingStmt> the admin notifications the last run recorded */
$adminNotices = static function () use (&$lastDb): array {
    return array_values(array_filter($lastDb->stmts ?? [], static fn(RecordingStmt $s): bool => str_contains($s->sql, 'INSERT INTO admin_notifications')));
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
    $notices = $adminNotices();
    $check(count($notices) === 1, 'the administrators get one in-app notice that they must act');
    $bound = $notices[0]->bound ?? [];
    $check(($bound[3] ?? null) === '/admin/users/details/77' && ($bound[4] ?? null) === 77,
        'the notice links to the account to activate');
    $check(str_contains((string) ($bound[2] ?? ''), 'audit-a1-victim@example.test') && str_contains((string) ($bound[2] ?? ''), 'APP_CANONICAL_URL')
        && !str_contains(json_encode($bound) ?: '', 'attacker.example'),
        'the notice names the registrant and the setting to fix, and never the request Host');

    echo "APP_CANONICAL_URL configured\n";
    $setEnv('APP_CANONICAL_URL', 'https://library.example');
    $sent = $register();
    $section = (string) ($sent[0]['variables']['sezione_verifica'] ?? '');
    $check(count($sent) === 1, 'the registration email is sent');
    $check(str_contains($section, 'https://library.example/') && str_contains($section, $token),
        'its verification link uses the canonical host and carries the token');
    $check(!str_contains($section, 'attacker.example'), 'and never the request Host');
    $check($adminNotices() === [], 'with a trusted host no "must act" notice is raised');

    echo "The manual activation path exists for such an account\n";
    $reg = (string) file_get_contents($root . '/app/Controllers/RegistrationController.php');
    $check(str_contains($reg, "\$stato = 'sospeso';"), 'a self-registered account starts as sospeso');
    $routes = (string) file_get_contents($root . '/app/Routes/web.php');
    $check(str_contains($routes, "'/admin/users/{id:\\d+}/activate-directly'"), 'POST /admin/users/{id}/activate-directly is routed');
    $users = (string) file_get_contents($root . '/app/Controllers/UsersController.php');
    $act = substr($users, (int) strpos($users, 'public function activateDirectly('), 3000);
    $check(str_contains($act, "if (\$user['stato'] !== 'sospeso')") && str_contains($act, "email_verificata = 1"),
        'activateDirectly() accepts a sospeso account and marks the email verified (no link needed)');
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

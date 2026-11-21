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

    /** Renders like the real one over the shipped Italian admin template, and records the body. */
    public function sendTemplateWithBodyFilter(string $to, string $templateName, array $variables, ?string $locale, callable $bodyFilter): bool
    {
        $body = $this->replaceVariables($bodyFilter(ADMIN_REGISTRATION_TEMPLATE), $variables);
        $this->sent[] = ['to' => $to, 'template' => $templateName, 'variables' => $variables, 'body' => $body];
        return true;
    }
}

// The admin_new_registration body as shipped (SettingsMailTemplates), button included.
const ADMIN_REGISTRATION_TEMPLATE = '<p>Email: {{email}}</p>'
    . '<p><a href="{{admin_users_url}}" style="background-color: #3b82f6; color: white;">Gestisci Utenti</a></p>';

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

/** Result double for the admin list query. */
final class AdminRowsResult extends mysqli_result
{
    public function __construct(private array $rows) {}
    public function fetch_assoc(): array|null|false { return array_shift($this->rows); }
}

/** Connection-less mysqli answering SELECTs from $rows and recording every statement. */
class RecordingDb extends mysqli
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
/** The admin email of a new registration: admins come from the second query. */
$notifyAdmins = static function () use ($token): array {
    $service = (new ReflectionClass(NotificationService::class))->newInstanceWithoutConstructor();
    $mailer = new RecordingEmailService();
    $user = ['nome' => 'Audit', 'cognome' => 'Victim', 'email' => 'audit-a1-victim@example.test',
        'codice_tessera' => 'AUDA1', 'created_at' => '2026-11-13 10:00:00', 'token_verifica_email' => $token];
    $db = new class ([$user, ['locale' => 'it_IT']]) extends RecordingDb {
        public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
        {
            return str_contains($query, "tipo_utente IN ('admin', 'staff')")
                ? new AdminRowsResult([['email' => 'admin@example.test']])
                : true;
        }
    };
    (new ReflectionProperty(NotificationService::class, 'db'))->setValue($service, $db);
    (new ReflectionProperty(NotificationService::class, 'emailService'))->setValue($service, $mailer);
    $service->notifyNewUserRegistration(77);
    return $mailer->sent;
};

/** @return list<RecordingStmt> the admin notifications the last run recorded */
$adminNotices = static function () use (&$lastDb): array {
    return array_values(array_filter($lastDb->stmts ?? [], static fn(RecordingStmt $s): bool => str_contains($s->sql, 'INSERT INTO admin_notifications')));
};

/** The admin-triggered activation mail: same invariant. @return array{sent: list<array>, result: bool} */
$activate = static function () use ($token): array {
    $service = (new ReflectionClass(NotificationService::class))->newInstanceWithoutConstructor();
    $mailer = new RecordingEmailService();
    $user = ['nome' => 'Audit', 'cognome' => 'Victim', 'email' => 'audit-a1-victim@example.test', 'codice_tessera' => 'AUDA1'];
    (new ReflectionProperty(NotificationService::class, 'db'))->setValue($service, new SecurityAuditFakeDb([$user, ['locale' => 'it_IT']], 2));
    (new ReflectionProperty(NotificationService::class, 'emailService'))->setValue($service, $mailer);
    $result = $service->sendUserActivationWithVerification(77, $token);
    return ['sent' => $mailer->sent, 'result' => $result];
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
    $adminMail = $notifyAdmins();
    $adminBody = (string) ($adminMail[0]['body'] ?? '');
    $check(count($adminMail) === 1 && ($adminMail[0]['to'] ?? '') === 'admin@example.test',
        'the admins still get the new-registration email');
    $check($adminBody !== '' && !str_contains($adminBody, 'attacker.example') && !str_contains($adminBody, '<a ')
        && !str_contains($adminBody, '{{admin_users_url}}'),
        'the admin email carries no link at all, never one to the request Host');
    $check(str_contains($adminBody, "Per gestire la richiesta apri l&apos;area di amministrazione e vai su Utenti.")
        || str_contains($adminBody, "Per gestire la richiesta apri l&#039;area di amministrazione e vai su Utenti."),
        'the button is replaced by a plain-text pointer to the user list');
    $check(str_contains($adminBody, 'audit-a1-victim@example.test'), 'the rest of the email is unchanged');
    foreach (['it_IT' => null, 'en_US' => 'en_US', 'de_DE' => 'de_DE', 'fr_FR' => 'fr_FR', 'da_DK' => 'da_DK'] as $loc => $file) {
        $tpl = $file === null
            ? (string) (function () use ($root): string {
                $src = (string) file_get_contents($root . '/app/Support/SettingsMailTemplates.php');
                $from = (int) strpos($src, "<<<'HTML'", (int) strpos($src, "'admin_new_registration' => ["));
                return substr($src, $from, (int) strpos($src, "\nHTML,", $from) - $from);
            })()
            : (string) ((require $root . '/app/Support/mail_templates/' . $file . '.php')['admin_new_registration']['body'] ?? '');
        $out = NotificationService::replaceAdminUsersLink($tpl, 'POINTER');
        $check(str_contains($tpl, '{{admin_users_url}}') && !str_contains($out, 'admin_users_url') && !str_contains($out, '<a ')
            && substr_count($out, 'POINTER') === 1,
            "the shipped {$loc} admin template loses its button and gets the pointer once");
    }
    $check(NotificationService::replaceAdminUsersLink('<p>No button</p>', 'POINTER') === '<p>No button</p><p>POINTER</p>',
        'a customised template without the button gets the pointer appended');
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
    $adminMail = $notifyAdmins();
    $check(count($adminMail) === 1 && !isset($adminMail[0]['body'])
        && ($adminMail[0]['variables']['admin_users_url'] ?? '') === 'https://library.example/admin/users',
        'with a trusted host the admin email keeps its button, built from that host');

    echo "The manual activation path exists for such an account\n";
    $reg = (string) file_get_contents($root . '/app/Controllers/RegistrationController.php');
    $check(str_contains($reg, "\$stato = 'sospeso';"), 'a self-registered account starts as sospeso');
    $routes = (string) file_get_contents($root . '/app/Routes/web.php');
    $check(str_contains($routes, "'/admin/users/{id:\\d+}/activate-directly'"), 'POST /admin/users/{id}/activate-directly is routed');
    $users = (string) file_get_contents($root . '/app/Controllers/UsersController.php');
    $act = substr($users, (int) strpos($users, 'public function activateDirectly('), 3000);
    $check(str_contains($act, "if (\$user['stato'] !== 'sospeso')") && str_contains($act, "email_verificata = 1"),
        'activateDirectly() accepts a sospeso account and marks the email verified (no link needed)');

    echo "Admin activation mail\n";
    $setEnv('APP_CANONICAL_URL', null);
    $setEnv('APP_TRUSTED_HOSTS', null);
    $out = $activate();
    $check($out['sent'] === [] && $out['result'] === false, 'no trusted host: nothing is sent and the caller is told (false)');
    $setEnv('APP_CANONICAL_URL', 'https://library.example');
    $out = $activate();
    $url = (string) ($out['sent'][0]['variables']['verification_url'] ?? '');
    $check($out['result'] === true && count($out['sent']) === 1, 'with a canonical host the activation mail is sent');
    $check(str_starts_with($url, 'https://library.example/') && str_contains($url, $token) && !str_contains($url, 'attacker.example'),
        'its link uses the canonical host, carries the token and never the request Host');
    $ctl = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/UsersController.php');
    $check(str_contains($ctl, 'success=approved_email_sent') && str_contains($ctl, 'error=approval_email_failed') && str_contains($ctl, 'success=approved_email_not_sent'),
        'approval tells the admin when the email could not be sent (behaviour: tests/security-audit-approval-email-failure.unit.php)');
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

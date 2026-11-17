<?php
declare(strict_types=1);

/**
 * Security regression (audit 2026-11, review of PR #536, F047/F048/F111/F112).
 *
 * admin_new_registration had been moved to TrustedLink, but the sibling emails
 * still built their links with absoluteUrl(), which takes the request's Host
 * header when neither APP_CANONICAL_URL nor APP_TRUSTED_HOSTS is set:
 * loan_request_notification (approve_url) and admin_new_review
 * (link_approvazione), both triggered by any logged-in reader, plus the
 * account-approved, activation, invitation, wishlist and reservation emails,
 * the admin notifyAdmins() mail, the per-loan .ics link, the email logo and
 * the book-club / desiderata emails.
 *
 * Invariant: no emailed URL takes its host from the request. With a trusted
 * host the link uses it; without one the button becomes a plain-text pointer
 * (template emails) or is left out (built bodies); a token-bearing link that
 * cannot be replaced by a pointer is not sent at all.
 *
 * DB-free except the .ics section, which needs the per-install HMAC secret and
 * uses the isolated slot database when one is configured.
 *
 * Run:  php tests/security-audit-email-trusted-links.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\EmailLayout;
use App\Support\EmailService;
use App\Support\NotificationService;
use App\Support\SettingsMailTemplates;
use App\Support\TrustedLink;

final class LinksRecordingMailer extends EmailService
{
    /** @var list<array{to: string, template: string, variables: array, body?: string, subject?: string}> */
    public array $sent = [];

    public function __construct()
    {
    }

    public function sendTemplate(string $to, string $templateName, array $variables = [], ?string $locale = null, array $attachments = []): bool
    {
        $this->sent[] = ['to' => $to, 'template' => $templateName, 'variables' => $variables];
        return true;
    }

    public function sendEmail(string $to, string $subject, string $body, string $toName = '', ?string $locale = null, array $attachments = []): bool
    {
        $this->sent[] = ['to' => $to, 'template' => '', 'variables' => [], 'body' => $body, 'subject' => $subject];
        return true;
    }
}

final class LinksFakeResult extends mysqli_result
{
    public function __construct(private array $rows) {}
    public function fetch_assoc(): array|null|false { return array_shift($this->rows); }
    public function free(): void {}
}

final class LinksFakeStmt extends mysqli_stmt
{
    public function __construct(private ?array $row) {}
    public function bind_param(string $types, mixed &...$vars): bool { return true; }
    public function execute(?array $params = null): bool { return true; }
    public function get_result(): mysqli_result|false { return new LinksFakeResult($this->row === null ? [] : [$this->row]); }
    public function close(): true { return true; }
}

/** Connection-less mysqli: prepare() answers by SQL substring, query() lists the admins. */
final class LinksFakeDb extends mysqli
{
    /** @param array<string, array> $rowsBySql */
    public function __construct(private array $rowsBySql) {}
    public function prepare(string $query): mysqli_stmt|false
    {
        foreach ($this->rowsBySql as $needle => $row) {
            if (str_contains($query, $needle)) {
                return new LinksFakeStmt($row);
            }
        }
        return new LinksFakeStmt(null);
    }
    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        return str_contains($query, "tipo_utente IN ('admin', 'staff')")
            ? new LinksFakeResult([['email' => 'admin@example.test', 'nome' => 'A', 'cognome' => 'B', 'locale' => 'it_IT']])
            : true;
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

/** A NotificationService over $db whose mail goes to a recorder. */
$service = static function (mysqli $db): array {
    $svc = (new ReflectionClass(NotificationService::class))->newInstanceWithoutConstructor();
    $mailer = new LinksRecordingMailer();
    (new ReflectionProperty(NotificationService::class, 'db'))->setValue($svc, $db);
    (new ReflectionProperty(NotificationService::class, 'emailService'))->setValue($svc, $mailer);
    return [$svc, $mailer];
};
$loanDb = static fn(): LinksFakeDb => new LinksFakeDb([
    'FROM prestiti p' => ['libro_titolo' => 'Il nome della rosa', 'utente_nome' => 'Reader One', 'utente_email' => 'reader@example.test',
        'data_prestito' => '', 'data_scadenza' => '', 'created_at' => '2026-11-17 10:00:00', 'copia_id' => null],
    'FROM recensioni r' => ['libro_titolo' => 'Il nome della rosa', 'utente_nome' => 'Reader One', 'utente_email' => 'reader@example.test',
        'stelle' => 4, 'titolo' => 'Bello', 'descrizione' => 'Molto', 'created_at' => '2026-11-17 10:00:00'],
    'FROM utenti' => ['nome' => 'Reader', 'cognome' => 'One', 'email' => 'reader@example.test', 'codice_tessera' => 'T1', 'locale' => 'it_IT'],
]);
/** The variables of the one admin email a run sent, by template. */
$varsOf = static function (LinksRecordingMailer $m, string $template): ?array {
    foreach ($m->sent as $mail) {
        if ($mail['template'] === $template) {
            return $mail['variables'];
        }
    }
    return null;
};
/** Render a shipped template the way EmailService::sendTemplate() does. */
$render = static function (string $template, string $locale, array $variables): string {
    $tpl = SettingsMailTemplates::all($locale)[$template] ?? null;
    if ($tpl === null) {
        return '';
    }
    $mailer = (new ReflectionClass(EmailService::class))->newInstanceWithoutConstructor();
    $body = EmailService::dropUnavailableLinks((string) $tpl['body'], $variables, $locale);
    return $mailer->replaceVariables($body, $variables);
};

$saved = [];
foreach (['APP_CANONICAL_URL', 'APP_TRUSTED_HOSTS'] as $name) {
    $saved[$name] = getenv($name) === false ? null : (string) getenv($name);
}
$savedHost = $_SERVER['HTTP_HOST'] ?? null;
$_SERVER['HTTP_HOST'] = 'attacker.example';
$leaks = static fn(mixed $v): bool => str_contains((string) json_encode($v), 'attacker.example');

try {
    echo "No operator-configured host (forged Host header)\n";
    $setEnv('APP_CANONICAL_URL', null);
    $setEnv('APP_TRUSTED_HOSTS', null);

    [$svc, $mailer] = $service($loanDb());
    $svc->notifyLoanRequest(5);
    $vars = $varsOf($mailer, 'loan_request_notification');
    $check($vars !== null && array_key_exists('approve_url', $vars) && $vars['approve_url'] === null && !$leaks($vars),
        'loan_request_notification: approve_url is null, never the request Host');
    foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $loc) {
        $body = $render('loan_request_notification', $loc, (array) $vars);
        $check($body !== '' && !str_contains($body, '<a ') && !str_contains($body, 'approve_url') && !$leaks($body)
            && (str_contains($body, 'Prestiti') || str_contains($body, 'Loans') || str_contains($body, 'Ausleihen')
                || str_contains($body, 'Prêts') || str_contains($body, 'Udlån')),
            "loan_request_notification ({$loc}): the button becomes a plain-text pointer to Loans");
    }

    [$svc, $mailer] = $service($loanDb());
    $svc->notifyNewReview(9);
    $vars = $varsOf($mailer, 'admin_new_review');
    $check($vars !== null && array_key_exists('link_approvazione', $vars) && $vars['link_approvazione'] === null && !$leaks($vars),
        'admin_new_review: link_approvazione is null, never the request Host');
    $body = $render('admin_new_review', 'it_IT', (array) $vars);
    $check($body !== '' && !str_contains($body, 'link_approvazione') && !$leaks($body) && str_contains($body, 'Recensioni'),
        'admin_new_review: the button becomes a plain-text pointer to Reviews');
    $body = $render('admin_new_review', 'en_US', ['approval_link' => null]);
    $check(!str_contains($body, 'link_approvazione') && !str_contains($body, 'approval_link') && str_contains($body, 'Reviews'),
        'the English alias of the placeholder is handled too');

    [$svc, $mailer] = $service($loanDb());
    $svc->sendUserAccountApproved(3);
    $vars = $varsOf($mailer, 'user_account_approved');
    $check($vars !== null && $vars['login_url'] === null && !$leaks($vars), 'user_account_approved: login_url is null, never the request Host');

    [$svc, $mailer] = $service($loanDb());
    $sent = $svc->sendUserActivationWithVerification(3, 'tok123');
    $check($sent === false && $mailer->sent === [], 'activation email with a live token is not sent at all');

    [$svc, $mailer] = $service($loanDb());
    $svc->notifyAdmins('general', 'T', 'Message', 'http://attacker.example/admin/desiderata#x');
    $mail = $mailer->sent[0] ?? null;
    $check($mail !== null && !$leaks($mail) && !str_contains((string) ($mail['body'] ?? ''), '<a '),
        'notifyAdmins(): the emailed copy of the link is dropped, not sent to the request Host');

    $check(TrustedLink::fromAppUrl('http://attacker.example/admin/x') === null, 'TrustedLink::fromAppUrl() is null without a trusted host');

    $html = EmailLayout::render('<p>Hi</p>', 'Subject', 'it_IT');
    $check(!$leaks($html), 'the email layout never loads its logo from the request Host');

    echo "APP_CANONICAL_URL configured\n";
    $setEnv('APP_CANONICAL_URL', 'https://library.example/biblio');

    [$svc, $mailer] = $service($loanDb());
    $svc->notifyLoanRequest(5);
    $vars = $varsOf($mailer, 'loan_request_notification');
    $check(($vars['approve_url'] ?? null) === 'https://library.example/biblio/admin/loans/pending', 'approve_url uses the canonical URL');
    $body = $render('loan_request_notification', 'it_IT', (array) $vars);
    $check(str_contains($body, 'href="https://library.example/biblio/admin/loans/pending"'), 'and the button stays');

    [$svc, $mailer] = $service($loanDb());
    $svc->notifyNewReview(9);
    $vars = $varsOf($mailer, 'admin_new_review');
    $check(($vars['link_approvazione'] ?? null) === 'https://library.example/biblio/admin/reviews', 'link_approvazione uses the canonical URL');

    [$svc, $mailer] = $service($loanDb());
    $svc->sendUserActivationWithVerification(3, 'tok123');
    $vars = $varsOf($mailer, 'user_activation_with_verification');
    $check(str_starts_with((string) ($vars['verification_url'] ?? ''), 'https://library.example/biblio/') && str_contains((string) $vars['verification_url'], 'tok123'),
        'the activation email is sent with a canonical link');

    [$svc, $mailer] = $service($loanDb());
    $svc->notifyAdmins('general', 'T', 'Message', 'http://attacker.example/admin/desiderata#x');
    $body = (string) ($mailer->sent[0]['body'] ?? '');
    $check(str_contains($body, 'href="https://library.example/biblio/admin/desiderata#x"') && !$leaks($body),
        'notifyAdmins(): the emailed link is rebuilt on the canonical host');

    // HtmlHelper::getBasePath() is computed once per process (per request in
    // production), so the base-path case runs in a fresh PHP process.
    $probe = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';'
        . 'echo json_encode([App\\Support\\TrustedLink::fromAppUrl("/biblio/libro/1-x"),'
        . 'App\\Support\\TrustedLink::fromAppUrl("http://attacker.example/biblio/admin/x?a=1#f")]);';
    $out = (string) shell_exec('APP_CANONICAL_URL=https://library.example/biblio ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($probe));
    $check(json_decode($out, true) === ['https://library.example/biblio/libro/1-x', 'https://library.example/biblio/admin/x?a=1#f'],
        'fromAppUrl() keeps the base path once for a url()/book_url() path or an absolute app URL');
    $check(TrustedLink::fromAppUrl('javascript:alert(1)') === null, 'fromAppUrl() refuses non-http(s) URLs');

    echo "Every shipped template, every link placeholder, every locale\n";
    $all = true;
    foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $loc) {
        foreach (SettingsMailTemplates::all($loc) as $name => $tpl) {
            foreach (array_keys(EmailService::LINK_FALLBACK_POINTERS) as $ph) {
                if (!str_contains((string) $tpl['body'], '{{' . $ph . '}}')) {
                    continue;
                }
                $vars = [$ph => null];
                $out = EmailService::dropUnavailableLinks((string) $tpl['body'], $vars, $loc);
                if (str_contains($out, '{{' . $ph . '}}') || $vars[$ph] !== '') {
                    $all = false;
                    echo "    {$loc}/{$name}/{$ph} left a placeholder\n";
                }
            }
        }
    }
    $check($all, 'a null link variable never leaves its button or placeholder behind');
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

echo "Static: no emailed link is built with absoluteUrl()\n";
$sites = [
    'app/Support/NotificationService.php' => ["'login_url' => absoluteUrl", "'approve_url' => absoluteUrl", "'link_approvazione' => absoluteUrl",
        "'dashboard_url' => absoluteUrl", "'book_url' => absoluteUrl", "'wishlist_url' => absoluteUrl", "\$verificationUrl = absoluteUrl"],
    'app/Controllers/ReservationManager.php' => ["'book_url' => absoluteUrl", "'profile_url' => absoluteUrl"],
    'app/Services/ReservationReassignmentService.php' => ["'book_url' => absoluteUrl", "'profile_url' => absoluteUrl"],
    // icsUrl() itself goes through TrustedLink (tests/security-audit-loan-ics-trusted-link.unit.php).
    'app/Support/LoanCalendarLinks.php' => ['return absoluteUrl(\'/calendar/loan/'],
    'app/Support/EmailLayout.php' => ['absoluteUrl($logo)'],
    'storage/plugins/book-club/src/PublicController.php' => ["\$link = absoluteUrl(\\App\\Support\\RouteTranslator::route('book_club') . '/invite/'"],
    'storage/plugins/book-club/src/MeetingController.php' => ['$link = absoluteUrl('],
    'storage/plugins/book-club/src/Modules/GovernanceModule.php' => ['$link = absoluteUrl('],
];
foreach ($sites as $file => $needles) {
    $src = (string) file_get_contents($root . '/' . $file);
    foreach ($needles as $needle) {
        $check(!str_contains($src, $needle), "{$file}: no `{$needle}`");
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

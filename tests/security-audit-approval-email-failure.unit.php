<?php
/**
 * Security audit follow-up (PR review): "Approva e Invia Email Attivazione" set
 * the account to 'attivo' BEFORE sending the verification email. Now that the
 * email is suppressed when no trusted host is configured (TrustedLink), an
 * account that never verified its address would be left active but unable to
 * sign in, with no link to do it with — and no button on its page to recover.
 *
 * The approval is therefore undone when the email cannot be sent: the account
 * goes back to 'sospeso' with its previous verification link. An account
 * that already verified its address can sign in and stays approved.
 *
 * Real controller, real database, one transaction that is always rolled back;
 * no host is configured, so nothing can be mailed. Needs E2E_DB_* or a .env
 * (CI_STRICT_TESTS=1 turns a missing database into a failure).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Controllers\UsersController;

final class ApprovalTestConnection extends mysqli
{
    public ?Closure $duringSend = null;
    /** Runs once, right before the direct activation's UPDATE is prepared. */
    public ?Closure $beforeActivate = null;
    /** Runs once, right before the approval's claiming UPDATE is prepared. */
    public ?Closure $beforeApprove = null;
    /** Makes the undo of a failed approval fail, as a lost connection would. */
    public bool $failRevert = false;
    /** Keeps the welcome email of a direct activation from reaching mail(). */
    public bool $blockMail = false;

    public function prepare(string $query): mysqli_stmt|false
    {
        if ($this->duringSend !== null && str_contains($query, 'SELECT nome, cognome, email, codice_tessera')) {
            ($this->duringSend)();
            $this->duringSend = null;
        }
        if ($this->blockMail && str_contains($query, 'SELECT nome, cognome, email, codice_tessera')) {
            throw new RuntimeException('mail blocked by the test');
        }
        if ($this->beforeActivate !== null && str_contains($query, 'email_verificata = 1')) {
            ($this->beforeActivate)();
            $this->beforeActivate = null;
        }
        if ($this->beforeApprove !== null && str_contains($query, "SET stato = 'attivo',") && str_contains($query, 'token_verifica_email = ?,')) {
            ($this->beforeApprove)();
            $this->beforeApprove = null;
        }
        if ($this->failRevert && str_contains($query, 'SET stato = ?,')) {
            throw new mysqli_sql_exception('revert failed (simulated)');
        }
        return parent::prepare($query);
    }
}

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};
$skip = static function (string $why): never {
    if (getenv('CI_STRICT_TESTS') === '1') {
        fwrite(STDERR, "FAIL: {$why} (CI_STRICT_TESTS=1 forbids skipping)\n");
        exit(1);
    }
    echo "SKIP: {$why}\n";
    exit(0);
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = array_map('trim', explode('=', $line, 2));
    $env[$k] = trim($v, "\"'");
}
$name = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
if ($name === '' || $user === '') {
    $skip('no database configured (E2E_DB_NAME/E2E_DB_USER or .env)');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = ($socket !== '' && file_exists($socket))
        ? new ApprovalTestConnection(null, $user, $dbPass, $name, 0, $socket)
        : new ApprovalTestConnection(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), $user, $dbPass, $name, (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    $skip('database not reachable (' . $e->getMessage() . ')');
}

// No operator-configured host: TrustedLink yields null and nothing can be mailed.
foreach (['APP_CANONICAL_URL', 'APP_TRUSTED_HOSTS'] as $n) {
    putenv($n);
    unset($_ENV[$n], $_SERVER[$n]);
}
$_SERVER['HTTP_HOST'] = 'attacker.example';
// A sub-directory install: url() keeps the base path it resolved first, so the
// redirects can be checked for it while no host is configured for the mails.
putenv('APP_CANONICAL_URL=https://library.example/pinakes');
$_ENV['APP_CANONICAL_URL'] = 'https://library.example/pinakes';
$basePath = \App\Support\HtmlHelper::getBasePath();
putenv('APP_CANONICAL_URL');
unset($_ENV['APP_CANONICAL_URL']);
if ($basePath !== '/pinakes') {
    $skip('the base path could not be pinned for this run (' . $basePath . ')');
}

$db->begin_transaction();
try {
    $mk = static function (int $verified, ?string $role = 'standard') use ($db): int {
        $suffix = bin2hex(random_bytes(4));
        $email = "audit-approval-{$suffix}@example.test";
        $hash = password_hash('x-' . $suffix, PASSWORD_DEFAULT);
        $tessera = 'ZP' . strtoupper($suffix);
        $stmt = $db->prepare("INSERT INTO utenti (nome, cognome, email, password, telefono, indirizzo, tipo_utente, stato, email_verificata, codice_tessera, locale)
                              VALUES ('Audit', 'Approval', ?, ?, '000', 'Audit street 1', ?, 'sospeso', ?, ?, 'it_IT')");
        $stmt->bind_param('sssis', $email, $hash, $role, $verified, $tessera);
        $stmt->execute();
        $id = (int) $db->insert_id;
        $stmt->close();
        return $id;
    };
    $state = static function (int $id) use ($db): array {
        return (array) $db->query("SELECT stato, token_verifica_email, data_token_verifica FROM utenti WHERE id = {$id}")->fetch_assoc();
    };
    // A trusted host is configured (approval refuses up front without one, see
    // below) and the send itself fails, as an SMTP error would.
    $approve = static function (int $id, string $as = 'staff', bool $hostConfigured = true) use ($db): string {
        $_SESSION = ['user' => ['id' => 1, 'tipo_utente' => $as, 'stato' => 'attivo']];
        $request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', "/admin/users/{$id}/approve-and-send-activation");
        if ($hostConfigured) {
            putenv('APP_CANONICAL_URL=https://library.example/pinakes');
            $_ENV['APP_CANONICAL_URL'] = 'https://library.example/pinakes';
            $db->blockMail = true;
        }
        try {
            $out = (new UsersController())->approveAndSendActivation($request, new Slim\Psr7\Response(), $db, ['id' => (string) $id]);
        } finally {
            $db->blockMail = false;
            putenv('APP_CANONICAL_URL');
            unset($_ENV['APP_CANONICAL_URL']);
            $_SESSION = [];
        }
        return $out->getHeaderLine('Location');
    };
    $activate = static function (int $id, string $as = 'staff') use ($db): string {
        $_SESSION = ['user' => ['id' => 1, 'tipo_utente' => $as, 'stato' => 'attivo']];
        $request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', "/admin/users/{$id}/activate-directly");
        $db->blockMail = true;
        try {
            $out = (new UsersController())->activateDirectly($request, new Slim\Psr7\Response(), $db, ['id' => (string) $id]);
        } finally {
            $db->blockMail = false;
            $_SESSION = [];
        }
        return $out->getHeaderLine('Location');
    };

    echo "No trusted host configured\n";
    $noHost = $mk(0);
    $before = $state($noHost);
    $location = $approve($noHost, 'staff', false);
    $check(str_contains($location, 'error=no_trusted_host'), 'approval is refused before the account changes (' . $location . ')');
    $check($state($noHost) === $before, "the account stays 'sospeso' with its token untouched");

    echo "An account that never verified its address\n";
    $unverified = $mk(0);
    $location = $approve($unverified);
    $row = $state($unverified);
    $check(str_contains($location, 'error=approval_email_failed'), 'the admin is told the approval was undone (' . $location . ')');
    $check($row['stato'] === 'sospeso', "the account is back to 'sospeso', where the approval actions exist");
    $check($row['token_verifica_email'] === null && $row['data_token_verifica'] === null, 'and no verification token is left behind');

    echo "A verification link was already sent\n";
    $pending = $mk(0);
    $previousToken = bin2hex(random_bytes(24));
    $previousExpiry = gmdate('Y-m-d H:i:s', time() + 86400);
    $db->query("UPDATE utenti SET token_verifica_email = '{$previousToken}', data_token_verifica = '{$previousExpiry}' WHERE id = {$pending}");
    $before = $state($pending);
    $location = $approve($pending);
    $check(str_contains($location, 'error=approval_email_failed') && $state($pending) === $before,
        'failed mail restores the existing token and expiry together with the suspended state');

    echo "Another update rotates the token during delivery\n";
    $replacement = bin2hex(random_bytes(24));
    $db->duringSend = static function () use ($db, $pending, $replacement): void {
        $db->query("UPDATE utenti SET token_verifica_email = '{$replacement}' WHERE id = {$pending}");
    };
    $location = $approve($pending);
    $row = $state($pending);
    $check($row['stato'] === 'attivo' && $row['token_verifica_email'] === $replacement,
        'rollback leaves an approval with a different token untouched');
    $check(!str_contains($location, 'error=approval_email_failed'), 'the response does not claim that the approval was undone');
    $check(str_contains($location, 'error=state_changed') && !str_contains($location, 'not_suspended'),
        'it says the account changed meanwhile, not that it was never suspended (' . $location . ')');

    echo "An account that already verified its address\n";
    $verified = $mk(1);
    $location = $approve($verified);
    $row = $state($verified);
    $check(str_contains($location, 'success=approved_email_not_sent'), 'it stays approved, with a notice that the email was not sent (' . $location . ')');
    $check($row['stato'] === 'attivo', "and is 'attivo' (it can already sign in)");

    echo "An account whose role is NULL (nullable column)\n";
    $roleless = $mk(0, null);
    $location = $approve($roleless, 'admin');
    $check(!str_contains($location, 'error=state_changed'), 'the claim matches a NULL role instead of reporting a change (' . $location . ')');
    $check(str_contains($location, 'error=approval_email_failed') && $state($roleless)['stato'] === 'sospeso',
        'and the approval runs its normal course (undone: no mail can be sent)');

    echo "The undo of a failed approval fails too\n";
    $stuck = $mk(0);
    $db->failRevert = true;
    try {
        $location = $approve($stuck);
    } finally {
        $db->failRevert = false;
    }
    $row = (array) $db->query("SELECT stato, email_verificata FROM utenti WHERE id = {$stuck}")->fetch_assoc();
    $check($row['stato'] === 'attivo' && (int) $row['email_verificata'] === 0, 'premise: the account is left active and unverified');
    $check(str_contains($location, 'error=approval_rollback_failed'),
        'the admin is told exactly that, not a generic database error (' . $location . ')');
    $view = (string) file_get_contents($root . '/app/Views/utenti/dettagli_utente.php');
    $check(str_contains($view, "\$error === 'approval_rollback_failed'"), 'the user page has a banner for it');

    echo "The banners name both settings the activation link accepts\n";
    // NotificationService builds the link from APP_CANONICAL_URL or, failing
    // that, APP_TRUSTED_HOSTS: telling the admin to set only the first one is
    // wrong for an install that relies on the second.
    preg_match_all('/__\("([^"]*APP_CANONICAL_URL[^"]*)"\)/', $view, $m);
    $check(count($m[1]) === 3, 'three banners mention the site address settings');
    foreach ($m[1] as $key) {
        $check(str_contains($key, 'APP_TRUSTED_HOSTS'), 'the source text names APP_TRUSTED_HOSTS too: ' . mb_substr($key, 0, 50) . '…');
        foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $loc) {
            $cat = json_decode((string) file_get_contents($root . "/locale/{$loc}.json"), true);
            $tr = is_array($cat) ? (string) ($cat[$key] ?? '') : '';
            if (!str_contains($tr, 'APP_CANONICAL_URL') || !str_contains($tr, 'APP_TRUSTED_HOSTS')) {
                $check(false, "{$loc} names both settings for: " . mb_substr($key, 0, 50) . '…');
            }
        }
    }

    echo "Redirects keep the base path of a sub-directory install\n";
    $check(str_starts_with($approve($mk(0)), '/pinakes/admin/users/details/'), 'approve and send activation');
    $direct = $mk(0);
    $location = $activate($direct);
    $check(str_starts_with($location, '/pinakes/admin/users/details/' . $direct . '?success=activated_directly'),
        'activate directly (' . $location . ')');
    $check($state($direct)['stato'] === 'attivo', 'and the account is active');

    echo "Activate directly races another change\n";
    $raced = $mk(0);
    $otherToken = bin2hex(random_bytes(24));
    $db->beforeActivate = static function () use ($db, $raced, $otherToken): void {
        // A concurrent "approve and send activation" landed after the read.
        $db->query("UPDATE utenti SET stato = 'attivo', token_verifica_email = '{$otherToken}' WHERE id = {$raced}");
    };
    $location = $activate($raced);
    $row = (array) $db->query("SELECT stato, email_verificata, token_verifica_email FROM utenti WHERE id = {$raced}")->fetch_assoc();
    $check(str_contains($location, 'error=state_changed'), 'the admin is told the account changed meanwhile (' . $location . ')');
    $check((int) $row['email_verificata'] === 0 && $row['token_verifica_email'] === $otherToken,
        'and the concurrent approval is not overwritten');
    $promoted = $mk(0);
    $db->beforeActivate = static function () use ($db, $promoted): void {
        $db->query("UPDATE utenti SET tipo_utente = 'staff' WHERE id = {$promoted}");
    };
    $location = $activate($promoted);
    $check(str_contains($location, 'error=state_changed') && $state($promoted)['stato'] === 'sospeso',
        'a role changed after the permission check is not activated (' . $location . ')');

    echo "Staff decides on ruolo_id and accesso_digitale too\n";
    $granted = $mk(0);
    $db->beforeActivate = static function () use ($db, $granted): void {
        $db->query("UPDATE utenti SET accesso_digitale = 1 WHERE id = {$granted}");
    };
    $location = $activate($granted);
    $check(str_contains($location, 'error=state_changed') && $state($granted)['stato'] === 'sospeso',
        'digital access granted after the check: staff does not activate it (' . $location . ')');
    $roleId = (int) ($db->query('SELECT id FROM ruoli ORDER BY id LIMIT 1')->fetch_row()[0] ?? 0);
    $withRole = $mk(0);
    $db->beforeApprove = static function () use ($db, $withRole, $roleId): void {
        $db->query($roleId > 0
            ? "UPDATE utenti SET ruolo_id = {$roleId} WHERE id = {$withRole}"
            : "UPDATE utenti SET accesso_digitale = 1 WHERE id = {$withRole}");
    };
    $location = $approve($withRole);
    $check(str_contains($location, 'error=state_changed') && $state($withRole)['stato'] === 'sospeso',
        ($roleId > 0 ? 'a staff role' : 'digital access') . ' assigned after the check: staff does not approve it (' . $location . ')');
    $plain = $mk(0);
    $location = $activate($plain);
    $check(str_contains($location, 'success=activated_directly'), 'an unchanged reader is still activated by staff (' . $location . ')');

    $nullRole = $mk(0, null);
    $location = $activate($nullRole, 'admin');
    $check(str_contains($location, 'success=activated_directly'), 'a NULL role can still be activated directly (' . $location . ')');

} catch (\Throwable $e) {
    $fail++;
    echo '  FAIL unexpected ' . get_class($e) . ': ' . $e->getMessage() . "\n";
} finally {
    $db->rollback();
    $db->close();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

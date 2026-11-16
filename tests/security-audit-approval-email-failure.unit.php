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

    public function prepare(string $query): mysqli_stmt|false
    {
        if ($this->duringSend !== null && str_contains($query, 'SELECT nome, cognome, email, codice_tessera')) {
            ($this->duringSend)();
            $this->duringSend = null;
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

$db->begin_transaction();
try {
    $mk = static function (int $verified) use ($db): int {
        $suffix = bin2hex(random_bytes(4));
        $email = "audit-approval-{$suffix}@example.test";
        $hash = password_hash('x-' . $suffix, PASSWORD_DEFAULT);
        $tessera = 'ZP' . strtoupper($suffix);
        $stmt = $db->prepare("INSERT INTO utenti (nome, cognome, email, password, telefono, indirizzo, tipo_utente, stato, email_verificata, codice_tessera, locale)
                              VALUES ('Audit', 'Approval', ?, ?, '000', 'Audit street 1', 'standard', 'sospeso', ?, ?, 'it_IT')");
        $stmt->bind_param('ssis', $email, $hash, $verified, $tessera);
        $stmt->execute();
        $id = (int) $db->insert_id;
        $stmt->close();
        return $id;
    };
    $state = static function (int $id) use ($db): array {
        return (array) $db->query("SELECT stato, token_verifica_email, data_token_verifica FROM utenti WHERE id = {$id}")->fetch_assoc();
    };
    $approve = static function (int $id) use ($db): string {
        $_SESSION = ['user' => ['id' => 1, 'tipo_utente' => 'staff', 'stato' => 'attivo']];
        $request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', "/admin/users/{$id}/approve-and-send-activation");
        $out = (new UsersController())->approveAndSendActivation($request, new Slim\Psr7\Response(), $db, ['id' => (string) $id]);
        $_SESSION = [];
        return $out->getHeaderLine('Location');
    };

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

    echo "An account that already verified its address\n";
    $verified = $mk(1);
    $location = $approve($verified);
    $row = $state($verified);
    $check(str_contains($location, 'success=approved_email_not_sent'), 'it stays approved, with a notice that the email was not sent (' . $location . ')');
    $check($row['stato'] === 'attivo', "and is 'attivo' (it can already sign in)");

} catch (\Throwable $e) {
    $fail++;
    echo '  FAIL unexpected ' . get_class($e) . ': ' . $e->getMessage() . "\n";
} finally {
    $db->rollback();
    $db->close();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

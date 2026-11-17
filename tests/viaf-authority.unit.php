<?php
declare(strict_types=1);

/**
 * viaf-authority: Basic-Auth throttle and the authority panel on a new author.
 *
 *  A. The Basic-Auth path of requireAdminOrStaff() is throttled per client IP
 *     and account (10 attempts / 5 min) BEFORE any password check, a success
 *     clears that account's counter only, a wrong password is a 401 with a Basic challenge, and an
 *     unknown account still spends a bcrypt round (no timing enumeration).
 *  B. renderAuthorFields() with no author row (authorId 0, the create form)
 *     has no Save button — the fields are posted with the form and stored by
 *     author.created — and says so; with an author the Save button is live.
 *  C. The six autori columns are declared for the boot-time self-heal, also
 *     through the wrapper PluginManager actually instantiates.
 *
 * Uses a documentation-range client IP so the real E2E client's throttle
 * bucket is never touched.
 *
 * Run:
 *   /tmp/run-e2e.sh tests/viaf-authority.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/viaf-authority/wrapper.php';

use App\Plugins\ViafAuthority\ViafAuthorityPlugin;
use App\Support\RateLimiter;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

$failed = 0;
$passed = 0;
$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) { $passed++; echo "  OK   $label\n"; }
    else { $failed++; echo "  FAIL $label\n"; }
};

$env = [];
foreach (@file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$user   = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$pass   = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$name   = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
if ($user === '' || $name === '') {
    fwrite(STDERR, "FAIL: database credentials not set (E2E_DB_* or .env)\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = ($socket !== '' && file_exists($socket))
    ? new mysqli(null, $user, $pass, $name, 0, $socket)
    : new mysqli($env['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1', $user, $pass, $name, (int) ($env['DB_PORT'] ?? getenv('DB_PORT') ?: 3306));
$db->set_charset('utf8mb4');

// A throwaway staff account with a known password: the suite needs no real
// admin credentials, and CI has none.
$adminEmail = 'viaf-unit-' . bin2hex(random_bytes(4)) . '@example.invalid';
$adminPass  = 'Viaf-unit-' . bin2hex(random_bytes(6));
$hash = password_hash($adminPass, PASSWORD_DEFAULT);
$card = 'VU' . strtoupper(bin2hex(random_bytes(5)));
// Verified email: the Basic gate applies the same verified-email rule as the
// interactive login.
$ins = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata) VALUES (?, 'Viaf', 'Unit', ?, ?, 'staff', 'attivo', 1)");
$ins->bind_param('sss', $card, $adminEmail, $hash);
$ins->execute();
$ins->close();
register_shutdown_function(static function () use ($db, $adminEmail): void {
    try {
        $del = $db->prepare('DELETE FROM utenti WHERE email = ?');
        $del->bind_param('s', $adminEmail);
        $del->execute();
    } catch (\Throwable) {
        // best effort
    }
});

$plugin = new ViafAuthorityPlugin($db, new \App\Support\HookManager($db));
$gate = new ReflectionMethod($plugin, 'requireAdminOrStaff');

$ip = '198.51.100.' . random_int(1, 254);
// The counter is keyed on IP AND account (as NCIP): one key per email.
$rlKey = static fn (string $email): string => 'viaf_basic:' . RateLimiter::clientKey($ip) . ':' . strtolower($email);
$rlId = $rlKey($adminEmail);
RateLimiter::reset($rlId);
$_SESSION = [];

/** @return array{0:bool,1:\Psr\Http\Message\ResponseInterface} */
$attempt = static function (string $email, string $password) use ($gate, $plugin, $ip): array {
    $request = (new ServerRequestFactory())
        ->createServerRequest('GET', 'http://localhost/api/viaf/author/1', ['REMOTE_ADDR' => $ip])
        ->withHeader('Authorization', 'Basic ' . base64_encode($email . ':' . $password));
    $out = null;
    $ok = $gate->invokeArgs($plugin, [(new ResponseFactory())->createResponse(), &$out, $request]);
    return [(bool) $ok, $out];
};

echo "A. Basic-Auth throttle\n";
[$ok, $res] = $attempt($adminEmail, $adminPass . '-wrong');
$check(!$ok && $res->getStatusCode() === 401, 'a wrong password is a 401');
$check(str_starts_with($res->getHeaderLine('WWW-Authenticate'), 'Basic'), 'the 401 carries a Basic challenge');

for ($i = 2; $i <= 9; $i++) {
    $attempt($adminEmail, $adminPass . '-wrong');
}
[$ok] = $attempt($adminEmail, $adminPass);
$check($ok, 'valid credentials pass while under the limit (10th attempt)');
for ($i = 1; $i <= 10; $i++) {
    $attempt($adminEmail, $adminPass . '-wrong');
}
[, $res] = $attempt('nobody@example.invalid', 'x');
$check($res->getStatusCode() === 401, 'another account has its own counter: still answered (401)');
[$ok, $res] = $attempt($adminEmail, $adminPass);
$check(!$ok && $res->getStatusCode() === 429, 'the 11th attempt on one account is throttled BEFORE the password check (valid credentials get 429)');
$check($res->getHeaderLine('Retry-After') === '300', 'the 429 says when to retry');
RateLimiter::reset($rlId);
RateLimiter::reset($rlKey('nobody@example.invalid'));

// Unknown account: the dummy bcrypt verify keeps the timing of a real one.
$equalizedBefore = \App\Support\PasswordTiming::equalizedCount();
$t0 = microtime(true);
$attempt('nobody-timing@example.invalid', 'x');
$check(
    \App\Support\PasswordTiming::equalizedCount() === $equalizedBefore + 1,
    'an unknown account still spends a constant-time dummy password_verify'
);
$unknownMs = (microtime(true) - $t0) * 1000;
RateLimiter::reset($rlKey('nobody-timing@example.invalid'));
$check($unknownMs > 20, sprintf('an unknown account costs a bcrypt round (%.1f ms)', $unknownMs));

echo "B. Authority panel without an author row\n";
$render = static function (?array $autore) use ($plugin): string {
    ob_start();
    $plugin->renderAuthorFields($autore);
    return (string) ob_get_clean();
};
$newForm = $render(null);
$check(!str_contains($newForm, 'id="viaf-save-btn"'), 'authorId 0: no Save button (the fields go with the create form)');
$check(
    str_contains($newForm, 'name="viaf_id"') && str_contains($newForm, 'name="isni_id"'),
    'authorId 0: the fields are named, so the create form posts them'
);
$check(str_contains($newForm, 'id="viaf-save-hint"'), 'authorId 0: a hint says the data is saved with the author');
$check(!str_contains($newForm, 'notYet: ""'), 'authorId 0: the client-side status never claims a save');
$editForm = $render(['id' => 7, 'nome' => 'Primo Levi']);
$check(
    str_contains($editForm, 'id="viaf-save-btn"')
    && preg_match('/<button[^>]*id="viaf-save-btn"[^>]*\sdisabled[\s>]/', $editForm) === 0
    && !str_contains($editForm, 'id="viaf-save-hint"'),
    'existing author: the Save button is live and no hint is shown'
);

echo "C. Self-heal surface\n";
$expected = ['viaf_id', 'viaf_uri', 'isni_id', 'isni_uri', 'authority_source', 'authority_confidence'];
$declared = array_map(static fn(array $c): string => $c['table'] . '.' . $c['column'], $plugin->expectedColumns());
$check($declared === array_map(static fn(string $c): string => 'autori.' . $c, $expected), 'expectedColumns() lists the six autori columns');
$wrapper = new \ViafAuthorityPlugin($db, new \App\Support\HookManager($db));
$check(method_exists($wrapper, 'expectedColumns'), 'the wrapper exposes expectedColumns() to method_exists()');
$check($wrapper->expectedColumns() === $plugin->expectedColumns(), 'the wrapper forwards the same column list');

echo "\n================================\n";
echo "Passed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);

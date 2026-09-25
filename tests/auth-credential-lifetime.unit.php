<?php
declare(strict_types=1);

/**
 * How long a way into an account survives the events meant to end it.
 *
 * Five findings, one question asked at five levels. All of them were reproduced
 * against this installation before being fixed; what follows is what pins them.
 *
 *  - An invitation link never worked. The token was stored in clear while the
 *    recovery page looks it up by SHA-256, and `data_token_reset` was written
 *    as the current instant while the page requires a future expiry. Either
 *    mistake alone is fatal, and every path that invited someone made both.
 *  - Recovering an account left the credentials the old password authorised —
 *    a remember-me cookie, a Mobile API token — working, across the exact act
 *    performed to take the account back.
 *  - Revoking a device marked its row and nothing else: the check ran only for
 *    a request arriving WITHOUT a session, so a device already signed in
 *    carried on.
 *  - The Digital Library upload was the one administrative endpoint mounted
 *    without the middleware that re-reads the account, and its own check read
 *    the role as it was at sign-in.
 *  - A link that is emailed carries a token, so its host cannot come from the
 *    request. One of the three senders knew that; the other two did not.
 *
 * Writes go inside a transaction and are rolled back. The account it works on
 * is created by this suite and removed by it.
 *
 * Run:  php tests/auth-credential-lifetime.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

// Before the first line of output: PHP refuses to start a session once
// anything has been printed, and section D2 needs a genuinely open one —
// bindSessionToRow() guards on session_status(), which is the behaviour under
// test. No cookie is involved on the command line.
// use_cookies only. Disabling use_only_cookies is deprecated in PHP 8.4, and
// the deprecation notice is itself output — which is exactly what stops the
// session from starting. The guard it would have relaxed is irrelevant here.
ini_set('session.use_cookies', '0');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

use App\Support\CredentialRevoker;
use App\Support\PasswordSetupToken;
use App\Support\RememberMeService;
use App\Support\TrustedLink;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  OK  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
    }
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $dbUser, $dbPass, $dbName, (int) ($env['DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — this suite must not skip silently: {$e->getMessage()}\n");
    exit(1);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $_SESSION = [];
}

$db->query("SET SESSION time_zone = '+00:00'");
$db->begin_transaction();
$userId = 0;

try {
    $suffix = bin2hex(random_bytes(6));
    $email = "zz-auth-probe-{$suffix}@example.invalid";
    $card = 'ZZ' . substr($suffix, 0, 8);
    $stmt = $db->prepare(
        'INSERT INTO utenti (nome, cognome, email, password, tipo_utente, stato, email_verificata, codice_tessera, locale)
         VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
    );
    $nome = 'Auth'; $cognome = 'Probe'; $hash = password_hash('x', PASSWORD_DEFAULT);
    $role = 'staff'; $stato = 'attivo'; $locale = 'it_IT';
    $stmt->bind_param('ssssssss', $nome, $cognome, $email, $hash, $role, $stato, $card, $locale);
    $stmt->execute();
    $userId = (int) $db->insert_id;
    $stmt->close();
    $check($userId > 0, 'a probe account exists to work on');

    echo "A. An invitation token the recovery page will accept\n";

    $raw = PasswordSetupToken::issue($db, $userId);
    $check(is_string($raw) && strlen($raw) === 64, 'issue() returns the original token');

    $row = $db->query("SELECT token_reset_password, data_token_reset, (data_token_reset > NOW()) AS future,
                              TIMESTAMPDIFF(HOUR, NOW(), data_token_reset) AS hours
                         FROM utenti WHERE id = {$userId}")->fetch_assoc();

    $check(($row['token_reset_password'] ?? '') === hash('sha256', (string) $raw),
        'the database holds the SHA-256, which is what the recovery page looks up');
    $check(($row['token_reset_password'] ?? '') !== $raw,
        'and NOT the token itself — a reader of this table, or of a backup, holds no working link');
    $check((int) ($row['future'] ?? 0) === 1,
        'the expiry is in the future, which is what the page requires (it was the current instant)');
    $check((int) ($row['hours'] ?? 0) >= PasswordSetupToken::TTL_HOURS - 1,
        'and matches the ' . PasswordSetupToken::TTL_HOURS . ' hours both invitation templates promise the reader');

    echo "\nB. Recovering the account ends the access the old password gave\n";

    $db->query("INSERT INTO user_sessions (utente_id, token_hash, expires_at)
                VALUES ({$userId}, '" . hash('sha256', 'a') . "', DATE_ADD(NOW(), INTERVAL 30 DAY))");
    $keptRow = (int) $db->insert_id;
    $db->query("INSERT INTO user_sessions (utente_id, token_hash, expires_at)
                VALUES ({$userId}, '" . hash('sha256', 'b') . "', DATE_ADD(NOW(), INTERVAL 30 DAY))");

    $liveSessions = static fn(): int => (int) $db->query(
        "SELECT COUNT(*) FROM user_sessions WHERE utente_id = {$userId} AND is_revoked = 0"
    )->fetch_row()[0];

    $check($liveSessions() === 2, 'two devices are signed in');

    unset($_SESSION[RememberMeService::SESSION_ROW_KEY]);
    CredentialRevoker::revokeAll($db, $userId, false);
    $check($liveSessions() === 0, 'a RESET revokes every device — the browser at that page is not known to be the owner\'s');

    echo "\nC. Changing the password from inside a session keeps that one session\n";

    $db->query("UPDATE user_sessions SET is_revoked = 0 WHERE utente_id = {$userId}");
    $_SESSION[RememberMeService::SESSION_ROW_KEY] = $keptRow;
    CredentialRevoker::revokeAll($db, $userId, true);
    $check($liveSessions() === 1, 'exactly one device survives');
    $survivor = (int) $db->query(
        "SELECT id FROM user_sessions WHERE utente_id = {$userId} AND is_revoked = 0 LIMIT 1"
    )->fetch_row()[0];
    $check($survivor === $keptRow, 'and it is the one the change was made from, not an arbitrary other');

    echo "\nD. A revoked device stops working on its next request\n";

    $service = new RememberMeService($db);
    $_SESSION[RememberMeService::SESSION_ROW_KEY] = $keptRow;
    $check($service->boundSessionIsRevoked() === false, 'a live device is not treated as revoked');

    $db->query("UPDATE user_sessions SET is_revoked = 1 WHERE id = {$keptRow}");
    $check($service->boundSessionIsRevoked() === true,
        'a revoked row ends the session bound to it — this is what "revoke" now means');

    $db->query("UPDATE user_sessions SET is_revoked = 0, expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = {$keptRow}");
    $check($service->boundSessionIsRevoked() === true, 'so does a row that has simply expired');

    unset($_SESSION[RememberMeService::SESSION_ROW_KEY]);
    $check($service->boundSessionIsRevoked() === false,
        'a session bound to no row is left alone — an installation whose table predates this is not locked out');

    echo "\nD2. An ordinary sign-in — no cookie, no \"remember me\" — is revocable too\n";

    // The hole this closes: AuthMiddleware decides from $_SESSION alone and
    // never asks the database, so before bindPlainSession() a reset ended the
    // remembered devices while the browser the intruder was already signed in
    // on carried on. Bind one the way a plain login does and revoke it the way
    // a reset does; nothing here is simulated but the login form itself.
    // bindSessionToRow() writes to $_SESSION only while a session is actually
    // open, which is right — and means this section needs a real one, not the
    // bare array the sections above set by hand.
    $check(session_status() === PHP_SESSION_ACTIVE, 'a real PHP session is open for this section');
    unset($_SESSION[RememberMeService::SESSION_ROW_KEY]);
    $before = $liveSessions();
    $bound = $service->bindPlainSession($userId);
    $check($bound === true, 'a sign-in with no remember-me cookie still records a session row');
    $plainRow = isset($_SESSION[RememberMeService::SESSION_ROW_KEY])
        ? (int) $_SESSION[RememberMeService::SESSION_ROW_KEY]
        : 0;
    $check($plainRow > 0, 'and binds this session to it');
    $check($liveSessions() === $before + 1, 'exactly one row, not one per request');

    $plainHash = (string) ($db->query(
        "SELECT token_hash FROM user_sessions WHERE id = {$plainRow}"
    )->fetch_row()[0] ?? '');
    $check($plainHash !== '', 'the row carries a token hash, as the column requires');
    $_COOKIE['remember_token'] = $plainHash;
    $check((new RememberMeService($db))->validateToken() === null,
        'and that hash is not a usable cookie: the row can be revoked but never authenticates anyone');
    unset($_COOKIE['remember_token']);

    $check($service->boundSessionIsRevoked() === false, 'the ordinary session is live before the reset');

    echo "\nD3. And it does not expire under someone who is still working\n";

    // session.gc_maxlifetime is an INACTIVITY timeout that PHP renews on every
    // request, so an active session outlives it. Stamped once on the row it
    // became an absolute deadline instead, and a bound row that has expired
    // signs the session out — with the setting at its five-minute minimum, mid
    // catalogue entry.
    $expiryOf = static function () use ($db, $plainRow): string {
        return (string) ($db->query("SELECT expires_at FROM user_sessions WHERE id = {$plainRow}")->fetch_row()[0] ?? '');
    };
    $db->query("UPDATE user_sessions SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 SECOND) WHERE id = {$plainRow}");
    $nearly = $expiryOf();
    $service->keepBoundPlainSessionAlive();
    $check($expiryOf() > $nearly, 'a row about to lapse is pushed forward while the session is in use');
    $check($service->boundSessionIsRevoked() === false, 'so the person is not signed out from under themselves');

    // Twice in a row must not write twice: the WHERE clause is the throttle.
    $settled = $expiryOf();
    $service->keepBoundPlainSessionAlive();
    $check($expiryOf() === $settled, 'a row with most of its window left is left alone — one write per half window, not per request');

    // A remembered row must never move: its expiry is the life of the cookie
    // it issued, and renewing it would let a thirty-day cookie live for ever.
    unset($_SESSION[RememberMeService::SESSION_PLAIN_KEY]);
    $db->query("UPDATE user_sessions SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 SECOND) WHERE id = {$plainRow}");
    $rememberedExpiry = $expiryOf();
    $service->keepBoundPlainSessionAlive();
    $check($expiryOf() === $rememberedExpiry, 'a remembered row keeps its fixed expiry — a cookie must not renew itself');
    $_SESSION[RememberMeService::SESSION_PLAIN_KEY] = true;

    $db->query("UPDATE user_sessions SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE id = {$plainRow}");
    CredentialRevoker::revokeAll($db, $userId, false);
    $check($service->boundSessionIsRevoked() === true,
        'a password reset ends it on its next request — the claim "a reset ends every session" is now true');

    echo "\nE. An administrative endpoint decides on the account, not on the snapshot\n";

    $runMiddleware = static function (array $headers) use ($db, $userId): int {
        $_SESSION['user'] = ['id' => $userId, 'tipo_utente' => 'staff', 'stato' => 'attivo'];
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/plugins/digital-library/upload');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return (new ResponseFactory())->createResponse(200);
            }
        };
        // The role decision is memoised in a STATIC keyed by user id, which is
        // correct under PHP-FPM — it is fresh for every request — and wrong
        // inside one CLI process, where three "requests" would share the first
        // answer. reset() is how this suite draws the request boundary the
        // middleware is written against; without it the suspended checks below
        // would read the decision taken while the account was still active and
        // pass for the wrong reason.
        \App\Support\SessionRoleRevalidator::reset();

        return (new \App\Middleware\AdminAuthMiddleware($db))->process($request, $handler)->getStatusCode();
    };

    $db->query("UPDATE utenti SET stato = 'attivo' WHERE id = {$userId}");
    $check($runMiddleware([]) === 200, 'an active staff account reaches the handler');

    $db->query("UPDATE utenti SET stato = 'sospeso' WHERE id = {$userId}");
    $check($runMiddleware([]) !== 200,
        'a suspended account does not, even though the session still says it is staff');
    $check($runMiddleware(['X-Requested-With' => 'XMLHttpRequest']) === 403,
        'and an XHR caller is refused with 403, not redirected to an HTML page it cannot read');
    $check($runMiddleware(['Accept' => 'application/json']) === 403,
        'the same for a caller that asked for JSON');

    echo "\nF. A link that will be emailed never takes its host from the request\n";

    $canonical = getenv('APP_CANONICAL_URL') ?: ($_ENV['APP_CANONICAL_URL'] ?? '');
    $trusted = getenv('APP_TRUSTED_HOSTS') ?: ($_ENV['APP_TRUSTED_HOSTS'] ?? '');
    putenv('APP_CANONICAL_URL'); unset($_ENV['APP_CANONICAL_URL']);
    putenv('APP_TRUSTED_HOSTS'); unset($_ENV['APP_TRUSTED_HOSTS']);
    $_SERVER['HTTP_HOST'] = 'evil.example';

    $check(TrustedLink::build('/reimposta-password?token=x') === null,
        'with no configured host the link is refused rather than built from Host');

    putenv('APP_CANONICAL_URL=https://library.example/pinakes');
    $_ENV['APP_CANONICAL_URL'] = 'https://library.example/pinakes';
    $built = TrustedLink::build('/reimposta-password?token=x');
    $check($built === 'https://library.example/pinakes/reimposta-password?token=x',
        'a configured canonical URL keeps its scheme and its base path');
    $check(is_string($built) && !str_contains($built, 'evil.example'),
        'and the request Host never appears in it');

    putenv($canonical !== '' ? "APP_CANONICAL_URL={$canonical}" : 'APP_CANONICAL_URL');
    putenv($trusted !== '' ? "APP_TRUSTED_HOSTS={$trusted}" : 'APP_TRUSTED_HOSTS');
} finally {
    $db->rollback();
    unset($_SESSION[RememberMeService::SESSION_ROW_KEY], $_SESSION['user']);
    $db->close();
}

echo "\n" . ($fail === 0
    ? "SUCCESS {$pass} behavioural checks\n"
    : "FAILURE {$fail} of " . ($pass + $fail) . " checks failed\n");

exit($fail === 0 ? 0 : 1);

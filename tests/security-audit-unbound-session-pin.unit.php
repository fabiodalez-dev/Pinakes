<?php
/**
 * Security audit (PR review): a sign-in whose user_sessions row could not be
 * written (bindPlainSession()/createToken() failed) left a PHP session with no
 * row for CredentialRevoker::revokeAll() to revoke, so a password reset by an
 * admin did not end an intruder's already-open session.
 *
 * Such a session is now pinned to a fingerprint of the password hash it was
 * opened with. SessionRoleRevalidator treats a pin that no longer matches as a
 * deleted account (every caller then ends the session), and revokeAll() re-pins
 * the device it keeps, so changing your own password does not sign you out.
 *
 *   A. DB-free: pinning rules and the revalidator verdicts.
 *   B. Database (E2E_DB_* or .env): revokeAll() re-pins the kept device, run
 *      against a TEMPORARY shadow of `utenti` so no real account is touched.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once __DIR__ . '/helpers/security-audit-fake-db.php';

use App\Support\CredentialRevoker;
use App\Support\RememberMeService;
use App\Support\SessionRoleRevalidator;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$_SESSION = [];
$oldHash = password_hash('old-secret-1', PASSWORD_DEFAULT);
$newHash = password_hash('new-secret-2', PASSWORD_DEFAULT);
$userRow = static fn (string $hash): array => [
    'id' => 5, 'tipo_utente' => 'admin', 'stato' => 'attivo', 'password' => $hash, 'ruolo_id' => 0,
];
$resolveWith = static function (string $currentHash) use ($userRow): string {
    SessionRoleRevalidator::reset();
    return SessionRoleRevalidator::resolve(new SecurityAuditFakeDb([$userRow($currentHash)], 5))['status'];
};

echo "A. Pinning rules and revalidation\n";
$_SESSION = ['user' => ['id' => 5], RememberMeService::SESSION_ROW_KEY => 42];
RememberMeService::pinIfUnbound($oldHash);
$check(!(RememberMeService::currentPin() !== null), 'a session bound to a row is not pinned');

$_SESSION = ['user' => ['id' => 5]];
RememberMeService::pinIfUnbound($oldHash);
$check((RememberMeService::currentPin() ?? '') === RememberMeService::pinValue(5, $oldHash),
    'an unbound session is pinned to its account and the password hash it was opened with');
$check(!str_contains((string) RememberMeService::currentPin(), $oldHash),
    'the session stores a fingerprint, not the hash itself');

$check($resolveWith($oldHash) === SessionRoleRevalidator::OK, 'same password: the pinned session stays valid');
$check($resolveWith($newHash) === SessionRoleRevalidator::ABSENT, 'password changed elsewhere: the pinned session is ended');

// A session opened before this check existed: no row, no pin. It is pinned on
// its first revalidation, so a later admin reset ends it as well.
$_SESSION = ['user' => ['id' => 5]];
$check($resolveWith($oldHash) === SessionRoleRevalidator::OK, 'a legacy unbound session stays valid on its first revalidation');
$check((RememberMeService::currentPin() ?? '') === RememberMeService::pinValue(5, $oldHash),
    'and is pinned to its account and the password it is still valid for');
$check($resolveWith($newHash) === SessionRoleRevalidator::ABSENT, 'a later password reset ends the legacy session');

// A pin names its account: one naming another account ends the session (every
// sign-in replaces the pin, so it cannot appear legitimately).
$_SESSION = ['user' => ['id' => 5, RememberMeService::UNBOUND_CREDENTIAL_KEY => RememberMeService::pinValue(6, $oldHash)]];
$check($resolveWith($oldHash) === SessionRoleRevalidator::ABSENT, "another account's pin ends the session");

// The earlier pin format (fingerprint only) is honoured only while it matches,
// then upgraded; a stale one ends the session.
$_SESSION = ['user' => ['id' => 5, RememberMeService::UNBOUND_CREDENTIAL_KEY => RememberMeService::credentialFingerprint($oldHash)]];
$check($resolveWith($oldHash) === SessionRoleRevalidator::OK
    && (RememberMeService::currentPin() ?? '') === RememberMeService::pinValue(5, $oldHash),
    'an earlier-format pin that still matches is upgraded');
$_SESSION = ['user' => ['id' => 5, RememberMeService::UNBOUND_CREDENTIAL_KEY => RememberMeService::credentialFingerprint($oldHash)]];
$check($resolveWith($newHash) === SessionRoleRevalidator::ABSENT, 'an earlier-format pin from before a reset ends the session');
$_SESSION = ['user' => ['id' => 5, RememberMeService::UNBOUND_CREDENTIAL_KEY => 'garbage']];
$check($resolveWith($oldHash) === SessionRoleRevalidator::ABSENT, 'a malformed pin ends the session');

$_SESSION = ['user' => ['id' => 5, RememberMeService::UNBOUND_CREDENTIAL_KEY => RememberMeService::pinValue(5, $oldHash)]];
$check($resolveWith($newHash) === SessionRoleRevalidator::ABSENT, "this account's stale pin ends the session");

// A bound session is revoked through its row: it needs no pin.
$_SESSION = ['user' => ['id' => 5], RememberMeService::SESSION_ROW_KEY => 42];
$check($resolveWith($newHash) === SessionRoleRevalidator::OK && !(RememberMeService::currentPin() !== null),
    'a bound session is left to its user_sessions row (no pin needed)');

// A Mobile API request: identity is the token, revoked on its own.
$_SESSION = ['user' => ['id' => 5, 'auth_via' => 'mobile_token']];
$check($resolveWith($newHash) === SessionRoleRevalidator::OK, 'a Mobile API token request is not subject to the browser pin');
SessionRoleRevalidator::reset();

// A sign-in starts unbound: a row id left by an earlier sign-in in this browser
// (session_regenerate_id() keeps the data) must not make pinIfUnbound() skip.
$auth = (string) file_get_contents($root . '/app/Controllers/AuthController.php');
$unsetPos = strpos($auth, 'unset($_SESSION[RememberMeService::SESSION_ROW_KEY]);');
$bindPos = strpos($auth, '$rememberMeService->bindPlainSession(');
$check($unsetPos !== false && $bindPos !== false && $unsetPos < $bindPos, 'login drops a stale row binding before binding the new sign-in');
$_SESSION = ['user' => ['id' => 5], RememberMeService::SESSION_ROW_KEY => 99];
unset($_SESSION[RememberMeService::SESSION_ROW_KEY]);
RememberMeService::pinIfUnbound($oldHash);
$check(RememberMeService::currentPin() === RememberMeService::pinValue(5, $oldHash), 'so a sign-in whose binding fails is pinned to its own account');

echo "B. revokeAll() re-pins the device it keeps\n";
$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = array_map('trim', explode('=', $line, 2));
    if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
        $v = substr($v, 1, -1);
    }
    $env[$k] = $v;
}
$name = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$user = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$db = null;
if ($name !== '' && $user !== '') {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
    $socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
    try {
        $db = ($socket !== '' && file_exists($socket))
            ? new mysqli(null, $user, $dbPass, $name, 0, $socket)
            : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), $user, $dbPass, $name, (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    } catch (\Throwable $e) {
        $db = null;
    }
}
if (!$db instanceof mysqli) {
    if (getenv('CI_STRICT_TESTS') === '1') {
        fwrite(STDERR, "FAIL: no database for part B (CI_STRICT_TESTS=1 forbids skipping)\n");
        exit(1);
    }
    echo "  (part B skipped: no database configured)\n";
} else {
    // A user id no real account has, in a session-local shadow of `utenti`:
    // revokeAll()'s UPDATEs on the real credential tables match no rows.
    $ghostId = 2147480001;
    try {
        $db->query('CREATE TEMPORARY TABLE utenti (id INT NOT NULL PRIMARY KEY, password VARCHAR(255) NOT NULL)');
        $stmt = $db->prepare('INSERT INTO utenti (id, password) VALUES (?, ?)');
        $stmt->bind_param('is', $ghostId, $newHash);
        $stmt->execute();
        $stmt->close();

        $_SESSION = ['user' => ['id' => $ghostId]];
        RememberMeService::pinIfUnbound($oldHash);
        CredentialRevoker::revokeAll($db, $ghostId, true);
        $check((RememberMeService::currentPin() ?? '') === RememberMeService::pinValue($ghostId, $newHash),
            'changing your own password re-pins this unbound session to the new hash');

        $_SESSION = ['user' => ['id' => 1]];
        RememberMeService::pinIfUnbound($oldHash);
        CredentialRevoker::revokeAll($db, $ghostId, true);
        $check((RememberMeService::currentPin() ?? '') === RememberMeService::pinValue(1, $oldHash),
            "revoking ANOTHER account's credentials leaves this session's pin alone");

        // A Mobile API token request carries the token user in $_SESSION['user']
        // for its length: a password change made there never re-pins anything.
        $_SESSION = ['user' => ['id' => $ghostId, 'auth_via' => 'mobile_token', RememberMeService::UNBOUND_CREDENTIAL_KEY => RememberMeService::pinValue($ghostId, $oldHash)]];
        CredentialRevoker::revokeAll($db, $ghostId, true);
        $check(RememberMeService::currentPin() === RememberMeService::pinValue($ghostId, $oldHash),
            'a password change through the Mobile API does not re-pin');

        $_SESSION = ['user' => ['id' => $ghostId]];
        RememberMeService::pinIfUnbound($oldHash);
        CredentialRevoker::revokeAll($db, $ghostId, false);
        $check((RememberMeService::currentPin() ?? '') === RememberMeService::pinValue($ghostId, $oldHash),
            'an admin reset (no device kept) does not re-pin, so the old session ends');
    } finally {
        $db->query('DROP TEMPORARY TABLE IF EXISTS utenti');
        $db->close();
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

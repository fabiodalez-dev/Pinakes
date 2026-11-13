<?php
declare(strict_types=1);

/**
 * Security audit 2026-11 — installer/index.php:force-gate-trusts-stale-session-and-unverified-admin
 *
 * /installer/?force=1 (which from step 2 rewrites .env) trusted the login-time
 * $_SESSION['user']['tipo_utente'] snapshot and a sticky installer_admin_verified
 * flag, and its POST door ignored stato/email_verificata and had no rate limit.
 * A demoted, suspended, deleted or session-revoked ex-admin kept the door.
 *
 *   A. Installer::adminRowIsActive(): admin + 'attivo' + verified, nothing less.
 *   B. Installer::boundSessionRowIsRevoked(): same semantics as
 *      RememberMeService::boundSessionIsRevoked() (deleted/revoked/expired row),
 *      and an unbound session is not trusted.
 *   C. recordForceLoginAttempt(): 15 attempts / 300 s per IP, fail closed.
 *   D. installer/index.php wiring: no snapshot trust, no sticky flag, the POST
 *      query carries the account-state conditions, \Throwable fails closed.
 *   E. an installer login stays valid only while the account's password hash
 *      matches the keyed fingerprint taken at login (password change/reset
 *      ends it).
 *
 * No database needed. Run: php tests/security-audit-installer-force-gate.unit.php
 */

$ROOT = dirname(__DIR__);

if (!function_exists('__')) {
    function __(string $key, mixed ...$args): string
    {
        return $args ? sprintf($key, ...$args) : $key;
    }
}

require_once $ROOT . '/installer/classes/Installer.php';

$failed = 0;
$passed = 0;
$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) { $passed++; echo "  OK   $label\n"; }
    else       { $failed++; echo "  FAIL $label\n"; }
};

// ---------------------------------------------------------------------------
echo "A. Active-admin predicate (re-read from utenti on every request)\n";
// ---------------------------------------------------------------------------
$active = ['tipo_utente' => 'admin', 'stato' => 'attivo', 'email_verificata' => 1];
$check(Installer::adminRowIsActive($active), 'active verified admin is accepted');
$check(Installer::adminRowIsActive(['email_verificata' => '1'] + $active), 'mysqli string "1" for email_verificata is accepted');
$check(!Installer::adminRowIsActive(null), 'deleted account (no row) is refused');
$check(!Installer::adminRowIsActive(['tipo_utente' => 'standard'] + $active), 'demoted account is refused');
$check(!Installer::adminRowIsActive(['tipo_utente' => 'staff'] + $active), 'staff is refused');
$check(!Installer::adminRowIsActive(['stato' => 'sospeso'] + $active), 'suspended admin is refused');
$check(!Installer::adminRowIsActive(['stato' => 'sospeso', 'tipo_utente' => 'admin', 'email_verificata' => 1]), 'suspended admin (explicit) is refused');
$check(!Installer::adminRowIsActive(['email_verificata' => 0] + $active), 'unverified admin is refused');

// ---------------------------------------------------------------------------
echo "B. Bound sign-in row (remember_session_id)\n";
// ---------------------------------------------------------------------------
$check(Installer::boundSessionRowIsRevoked(0, null), 'session bound to no row is not trusted');
$check(Installer::boundSessionRowIsRevoked(7, null), 'deleted row counts as revoked');
$check(Installer::boundSessionRowIsRevoked(7, ['is_revoked' => 1, 'still_valid' => 1]), 'revoked row counts as revoked');
$check(Installer::boundSessionRowIsRevoked(7, ['is_revoked' => 0, 'still_valid' => 0]), 'expired row counts as revoked');
$check(!Installer::boundSessionRowIsRevoked(7, ['is_revoked' => '0', 'still_valid' => '1']), 'live row is accepted');

// ---------------------------------------------------------------------------
echo "C. Reinstall-login rate limit\n";
// ---------------------------------------------------------------------------
$tmp = sys_get_temp_dir() . '/pk_secaudit_force_' . bin2hex(random_bytes(5));
mkdir($tmp . '/storage/cache', 0700, true);
$installer = new Installer($tmp);
$now = 1_800_000_000;
$allowed = 0;
for ($i = 0; $i < Installer::FORCE_LOGIN_MAX_ATTEMPTS; $i++) {
    $allowed += $installer->recordForceLoginAttempt('198.51.100.7', $now + $i) ? 1 : 0;
}
$check($allowed === 15, 'the first 15 attempts in the window are allowed');
$check(!$installer->recordForceLoginAttempt('198.51.100.7', $now + 20), 'the 16th attempt is refused');
$check($installer->recordForceLoginAttempt('198.51.100.8', $now + 20), 'another IP has its own budget');
$check($installer->recordForceLoginAttempt('198.51.100.7', $now + Installer::FORCE_LOGIN_WINDOW + 15), 'attempts age out after the window');
$files = glob($tmp . '/storage/cache/installer-login/*.json') ?: [];
$check($files !== [] && !str_contains(implode('', $files), '198.51.100'), 'the IP is stored hashed, not in clear');

$broken = $tmp . '/broken';
mkdir($broken, 0700);
file_put_contents($broken . '/storage', 'not a directory');
$check(!(new Installer($broken))->recordForceLoginAttempt('198.51.100.9', $now), 'an unrecordable attempt is refused (fail closed)');

// ---------------------------------------------------------------------------
echo "D. installer/index.php force gate wiring\n";
// ---------------------------------------------------------------------------
$src = (string) file_get_contents($ROOT . '/installer/index.php');
$gateStart = strpos($src, "if (isset(\$_GET['force']) && \$installer->isInstalled())");
$gateEnd = strpos($src, '// Check if already installed');
$gate = ($gateStart !== false && $gateEnd !== false) ? substr($src, $gateStart, $gateEnd - $gateStart) : '';
$check($gate !== '', 'force gate located');
$check(preg_match("/\\\$_SESSION\\['user'\\]\\['tipo_utente'\\]\\s*===\\s*'admin'/", $gate) !== 1,
    'the login-time role snapshot no longer grants the door');
$check(!str_contains($gate, "\$_SESSION['installer_admin_verified'] = true")
    && preg_match("/installer_admin_verified'\\]\\s*\\)\\s*&&\\s*\\\$_SESSION\\['installer_admin_verified'\\]\\s*===\\s*true/", $gate) !== 1,
    'the sticky installer_admin_verified grant is gone');
$check(str_contains($gate, "unset(\$_SESSION['installer_admin_verified'])"), 'a legacy sticky flag in an old session is dropped');
$check(str_contains($gate, 'Installer::isActiveAdmin($db, $sessionUserId)')
    && str_contains($gate, 'Installer::isBoundSessionRevoked($db, $boundRowId)')
    && str_contains($gate, "\$_SESSION['remember_session_id']"),
    'a main-app session is re-validated against utenti and its bound user_sessions row');
$check(str_contains($gate, 'Installer::isActiveAdminWithCredential(')
    && preg_match('/isActiveAdminWithCredential\(\s*\$db,\s*\$installerUserId,/', $gate) === 1
    && !str_contains($gate, 'Installer::isActiveAdmin($db, $installerUserId)'),
    'an installer login is re-validated on every request, account AND password fingerprint');
$check(str_contains($gate, "\$_SESSION['installer_admin_credential'] = Installer::installerCredentialFingerprint((string) \$row['password'], \$credentialKey)")
    && str_contains($gate, "\$_SESSION['installer_admin_credential_key'] = \$credentialKey"),
    'the installer login stores a keyed fingerprint of the password hash it verified');
$check(preg_match("/unset\\(\\s*\\\$_SESSION\\['installer_admin_user_id'\\],\\s*\\\$_SESSION\\['installer_admin_credential'\\],\\s*\\\$_SESSION\\['installer_admin_credential_key'\\]\\s*\\)/", $gate) === 1,
    'a stale installer login is dropped with its fingerprint');
$check(preg_match("/tipo_utente = 'admin' AND stato = 'attivo' AND email_verificata = 1/", $gate) === 1,
    'the POST door requires stato attivo and a verified email');
$check(str_contains($gate, 'recordForceLoginAttempt('), 'the POST door is rate limited');
$check(str_contains($gate, 'catch (\\Throwable $e)') && !preg_match('/catch \(Exception \$e\)/', $gate),
    'failures are caught as \\Throwable and fail closed');
$postPos = strpos($gate, 'recordForceLoginAttempt(');
$queryPos = strpos($gate, "AND stato = 'attivo'");
$check($postPos !== false && $queryPos !== false && $postPos < $queryPos, 'the attempt is counted before the credential check');

// ---------------------------------------------------------------------------
echo "E. Installer login is bound to the password it was made with\n";
// ---------------------------------------------------------------------------
if (!method_exists(Installer::class, 'installerLoginRowIsCurrent') || !method_exists(Installer::class, 'installerCredentialFingerprint')) {
    $check(false, 'Installer::installerLoginRowIsCurrent()/installerCredentialFingerprint() exist');
} else {
    $key = bin2hex(random_bytes(32));
    $hash = password_hash('old-password', PASSWORD_DEFAULT);
    $fp = Installer::installerCredentialFingerprint($hash, $key);
    $row = $active + ['password' => $hash];
    $check(Installer::installerLoginRowIsCurrent($row, $fp, $key), 'same active admin, same password: still logged in');
    $check(!Installer::installerLoginRowIsCurrent(['password' => password_hash('new-password', PASSWORD_DEFAULT)] + $row, $fp, $key),
        'password changed or reset (CredentialRevoker::revokeAll() path): login refused');
    $check(!Installer::installerLoginRowIsCurrent(['stato' => 'sospeso'] + $row, $fp, $key), 'suspended admin: login refused');
    $check(!Installer::installerLoginRowIsCurrent(null, $fp, $key), 'deleted account: login refused');
    $check(!Installer::installerLoginRowIsCurrent($row, '', $key) && !Installer::installerLoginRowIsCurrent($row, $fp, ''),
        'a session without fingerprint (pre-fix login) is refused');
    $check(!Installer::installerLoginRowIsCurrent($row, $fp, bin2hex(random_bytes(32))), 'a fingerprint under another key is refused');
    $check(!Installer::installerLoginRowIsCurrent(['password' => ''] + $row, Installer::installerCredentialFingerprint('', $key), $key),
        'an account without a password hash is refused');
    $check(!str_contains($fp, $hash) && strlen($fp) === 64, 'the session holds an HMAC, not the password hash');
}

// Cleanup: only the private temp tree created above.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); // nosemgrep
}
@rmdir($tmp);

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} PASS, {$failed} FAIL\n");
exit($failed === 0 ? 0 : 1);

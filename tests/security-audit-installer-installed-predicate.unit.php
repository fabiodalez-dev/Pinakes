<?php
declare(strict_types=1);

/**
 * Security audit 2026-11 — installer/classes/Installer.php:isInstalled-lock-only-vs-env-predicate
 *
 * The installer judged "installed" by .installed alone while the front
 * controllers serve the app as soon as .env exists, and step 7 swallowed every
 * finalisation error (and an unwritten .installed) behind the success page. In
 * that .env-only state any anonymous client could re-run step 2 and rewrite .env.
 *
 *   A. isInstalled(): .env counts as installed for every session except the one
 *      that wrote it (bound by a hashed token), and except a .env whose database
 *      is reachable and empty (Docker wizard mode). Unreachable DB = installed.
 *   B. createEnvFile() binds the session before writing .env, and writes nothing
 *      when it cannot bind; when the .env write fails the previous binding
 *      (or none) is restored.
 *   C. step 7 renders the error and never the success page when finalisation
 *      throws or .installed cannot be written (real step7.php, fake installer);
 *      the retry of a finalised session recreates a missing .installed.
 *   D. the claim is exclusive: while another session holds a live claim over
 *      .env, the empty-database exception is off and the claim cannot be taken.
 *   E. the owner itself goes on only while the configured database is empty
 *      or was filled by its own step 3; over any other populated database it
 *      needs an active admin of that database like everyone else.
 *
 * No database needed. Run: php tests/security-audit-installer-installed-predicate.unit.php
 */

$ROOT = dirname(__DIR__);

if (!function_exists('__')) {
    function __(string $key, mixed ...$args): string
    {
        return $args ? sprintf($key, ...$args) : $key;
    }
}

require_once $ROOT . '/installer/classes/Installer.php';

// Guarded so the suite reports FAILs (not a fatal) against code without the rule.
$popKey = defined('Installer::INSTALL_POPULATED_SESSION_KEY') ? Installer::INSTALL_POPULATED_SESSION_KEY : 'installer_populated_by_owner';
$forceKey = defined('Installer::FORCE_MODE_SESSION_KEY') ? Installer::FORCE_MODE_SESSION_KEY : 'installer_force_mode';

$failed = 0;
$passed = 0;
$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) { $passed++; echo "  OK   $label\n"; }
    else       { $failed++; echo "  FAIL $label\n"; }
};

$tmpRoot = sys_get_temp_dir() . '/pk_secaudit_inst_' . bin2hex(random_bytes(5));
mkdir($tmpRoot . '/sessions', 0700, true);
$mkSite = static function () use ($tmpRoot): string {
    $d = $tmpRoot . '/site_' . bin2hex(random_bytes(4));
    mkdir($d . '/storage', 0700, true);
    return $d;
};

/** The web wizard's view: these checks run in the CLI but exercise the web predicate. */
class WebInstaller extends Installer
{
    protected function runsUnderCli(): bool
    {
        return false;
    }
}

/** Installer whose database probe answers what the test says. */
final class ProbeInstaller extends WebInstaller
{
    public bool $emptyDb = false;
    public int $probes = 0;
    /** The import-proof nonce "stored in the configured database"; false = unreachable. */
    public string|false|null $dbNonce = null;
    protected function configuredDatabaseIsEmpty(): bool
    {
        $this->probes++;
        return $this->emptyDb;
    }
    protected function storeProofNonce(string $nonce): bool
    {
        $this->dbNonce = $nonce;
        return true;
    }
    protected function readProofNonce(): string|false|null
    {
        return $this->dbNonce;
    }
    protected function dropProofNonce(): void
    {
        if ($this->dbNonce !== false) {
            $this->dbNonce = null;
        }
    }
}

// Before any output: in the CLI, output counts as "headers sent" and would stop
// session_start(). First the no-session case, then a real file session.
$siteNoSession = $mkSite();
$noSessionResult = (new ProbeInstaller($siteNoSession))->createEnvFile('127.0.0.1', 'u', 'p', 'db');
session_save_path($tmpRoot . '/sessions');
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_start();

// ---------------------------------------------------------------------------
echo "A. Installed predicate\n";
// ---------------------------------------------------------------------------
$site = $mkSite();
$inst = new ProbeInstaller($site);
$check($inst->isInstalled() === false, 'no .env, no .installed: not installed');

file_put_contents($site . '/.env', "DB_NAME=live\n");
$_SESSION = [];
$check($inst->isInstalled() === true, '.env without .installed, foreign session: installed (the old lock-only check said no)');

$inst->emptyDb = true;
$check($inst->isInstalled() === false, '.env whose database is reachable and empty (Docker wizard): not installed');
$inst->emptyDb = false;

file_put_contents($site . '/.installed', "x\n");
$check($inst->isInstalled() === true && $inst->hasLockFile(), '.installed present: installed');
unlink($site . '/.installed');

// The real probe fails closed on an unreachable database and on a .env without DB_NAME.
$siteReal = $mkSite();
file_put_contents($siteReal . '/.env', "DB_HOST=127.0.0.1\nDB_PORT=1\nDB_USER=x\nDB_PASS=x\nDB_NAME=nope\n");
$check((new WebInstaller($siteReal))->isInstalled() === true, 'real probe: unreachable database counts as installed');
file_put_contents($siteReal . '/.env', "# no database configured\nAPP_ENV=production\n");
$check((new WebInstaller($siteReal))->isInstalled() === true, 'real probe: .env without DB_NAME counts as installed');

// Ownership binding.
$_SESSION = [];
$check($inst->claimInstallOwnership() === true, 'claimInstallOwnership() succeeds with an active session');
$token = (string) ($_SESSION[Installer::INSTALL_OWNER_SESSION_KEY] ?? '');
$marker = (string) @file_get_contents($site . '/storage/.install_in_progress');
$check($token !== '' && strlen($token) === 64, 'a 256-bit token is kept in the session');
$markerLines = explode("\n", $marker);
$check(($markerLines[0] ?? '') === hash('sha256', $token) && !str_contains($marker, $token), 'only the token hash is on disk');
$check(isset($markerLines[1]) && abs((int) $markerLines[1] - time()) < 5, 'with the claim time, for the absolute limit');
// The owner goes on over a populated database only once it has itself started
// the schema import there (section E covers the rule).
$inst->emptyDb = true; // step 3 imports into the database step 2 accepted empty
$check(method_exists($inst, 'recordSchemaImportByOwner') && $inst->recordSchemaImportByOwner(), 'the owner records its schema import');
$inst->emptyDb = false; // the import filled it
$proof = $_SESSION[$popKey] ?? null;
$nonceKey = defined('Installer::INSTALL_PROOF_NONCE_SESSION_KEY') ? Installer::INSTALL_PROOF_NONCE_SESSION_KEY : 'installer_populated_nonce';
$nonce = $_SESSION[$nonceKey] ?? null;
$check($inst->ownsInstallInProgress() && $inst->isInstalled() === false, 'the owning session may continue the install');

$_SESSION = [];
$check($inst->isInstalled() === true, 'a fresh session (no token) is refused');
$_SESSION[Installer::INSTALL_OWNER_SESSION_KEY] = bin2hex(random_bytes(32));
$check($inst->isInstalled() === true, 'a forged token is refused');
$_SESSION = [Installer::INSTALL_OWNER_SESSION_KEY => $token, $popKey => $proof, $nonceKey => $nonce];
// Review of PR #536 (F117/F116): the TTL lets OTHER sessions take over a lost
// binding; it does not expire the owner's own session. An owner that paused
// for more than the TTL between the schema import (step 3) and the admin
// account (step 4) was locked out for good, the database being no longer empty.
touch($site . '/storage/.install_in_progress', time() - Installer::INSTALL_OWNER_TTL - 3600);
clearstatcache();
$check($inst->ownsInstallInProgress() && $inst->isInstalled() === false,
    'the owner that imported the schema goes on after pausing longer than the TTL');
clearstatcache();
$check(time() - (int) filemtime($site . '/storage/.install_in_progress') < 5, '... and its binding is renewed');
$_SESSION = [];
touch($site . '/storage/.install_in_progress', time() - Installer::INSTALL_OWNER_TTL - 3600);
clearstatcache();
$check($inst->isInstalled() === true, 'every other session still finds the populated database installed');
$_SESSION = [Installer::INSTALL_OWNER_SESSION_KEY => $token];
$check($inst->isInstalled() === true, 'the owner token without its import proof is still refused over the populated database');
$_SESSION = [Installer::INSTALL_OWNER_SESSION_KEY => $token, $popKey => $proof, $nonceKey => $nonce];
touch($site . '/storage/.install_in_progress');
clearstatcache();
$check($inst->isInstalled() === false, 'a live binding is honoured');

// Upper bound on the owner (security review of the change above): an absolute
// TTL from the claim. Inside it the owner always goes on; past it the marker,
// the database nonce and the session proof are deleted together.
$markerPathAbs = $site . '/storage/.install_in_progress';
$hashLine = explode("\n", (string) file_get_contents($markerPathAbs))[0];
file_put_contents($markerPathAbs, $hashLine . "\n" . (time() - Installer::INSTALL_OWNER_ABSOLUTE_TTL + 3600));
touch($markerPathAbs, time() - Installer::INSTALL_OWNER_TTL - 600);
clearstatcache();
$check($inst->isInstalled() === false, 'an idle owner one hour inside the absolute limit still goes on');
file_put_contents($markerPathAbs, $hashLine . "\n" . (time() - Installer::INSTALL_OWNER_ABSOLUTE_TTL - 60));
clearstatcache();
$check($inst->isInstalled() === true && !is_file($markerPathAbs), 'past the absolute limit the owner is refused and the marker deleted');
$check(!isset($_SESSION[Installer::INSTALL_OWNER_SESSION_KEY]) && !isset($_SESSION[$popKey]) && !isset($_SESSION[$nonceKey]) && $inst->dbNonce === null,
    '... together with the session proof and the database nonce');
$check(Installer::INSTALL_OWNER_ABSOLUTE_TTL > Installer::INSTALL_OWNER_TTL && Installer::INSTALL_OWNER_ABSOLUTE_TTL <= 86400,
    'the absolute limit is longer than the sliding one, and at most a day');
// A foreign session meeting an abandoned binding deletes it without touching its own keys.
$_SESSION = [];
$inst->claimInstallOwnership();
$ownerAbs = $_SESSION;
$hashLine = explode("\n", (string) file_get_contents($markerPathAbs))[0];
file_put_contents($markerPathAbs, $hashLine . "\n" . (time() - Installer::INSTALL_OWNER_ABSOLUTE_TTL - 60));
$_SESSION = [$forceKey => true];
clearstatcache();
$check($inst->installClaimedByAnotherSession() === false && !is_file($markerPathAbs) && ($_SESSION[$forceKey] ?? null) === true,
    'another session finds an abandoned binding gone, and keeps its own session keys');
// Review of PR #536: the cleanup of an abandoned binding ran outside the lock the
// claimers take. A request that read an expired marker and paused could then
// delete the marker, and the import proof, of a session that claimed the install
// meanwhile. The discard runs under that lock and only removes the marker it was
// told was abandoned.
$discard = new ReflectionMethod(Installer::class, 'discardAbandonedBinding');
$discard->setAccessible(true);
$_SESSION = [];
$staleContent = hash('sha256', 'old-owner') . "\n" . (time() - Installer::INSTALL_OWNER_ABSOLUTE_TTL - 60);
$freshContent = hash('sha256', 'new-owner') . "\n" . time();
file_put_contents($markerPathAbs, $freshContent);
$inst->dbNonce = 'new-owners-proof';
$discard->invoke($inst, ['exists' => true, 'content' => $staleContent, 'mtime' => time()]);
clearstatcache();
$check(is_file($markerPathAbs) && file_get_contents($markerPathAbs) === $freshContent && $inst->dbNonce === 'new-owners-proof',
    'a marker claimed anew since it was judged abandoned is left alone, with its proof');
$discard->invoke($inst, ['exists' => true, 'content' => $freshContent, 'mtime' => time()]);
clearstatcache();
$check(!is_file($markerPathAbs) && $inst->dbNonce === null, 'the marker that was judged abandoned is removed with its proof');
// Claiming over an abandoned foreign binding discards it while holding the lock
// itself: it must not wait on the lock it already holds.
file_put_contents($site . '/.env', "DB_NAME=live\n");
file_put_contents($markerPathAbs, $staleContent);
$_SESSION = [];
$check($inst->claimInstallOwnership() === true && ($_SESSION[Installer::INSTALL_OWNER_SESSION_KEY] ?? '') !== '',
    'a claim over an abandoned binding goes through (the discard inside the claim does not deadlock)');
$instSrc = (string) file_get_contents($ROOT . '/installer/classes/Installer.php');
$check(str_contains($instSrc, '$this->discardAbandonedBinding($state);'), 'the expiry check hands the marker it read to the discard');

// A marker written before the claim time existed gets one at the owner's next renewal.
$_SESSION = $ownerAbs;
file_put_contents($markerPathAbs, hash('sha256', (string) $ownerAbs[Installer::INSTALL_OWNER_SESSION_KEY]));
$inst->emptyDb = true;
$inst->isInstalled();
$inst->emptyDb = false;
$legacyLines = explode("\n", (string) file_get_contents($markerPathAbs));
$check(isset($legacyLines[1]) && abs((int) $legacyLines[1] - time()) < 5, 'a legacy marker gets its claim time on renewal');

// The import proof is tied to the database it was earned on.
$inst->releaseInstallOwnership();
$_SESSION = [];
$check($inst->claimInstallOwnership(), 'setup: a new owner claims the install');
$inst->emptyDb = true;
$inst->recordSchemaImportByOwner();
$inst->emptyDb = false;
$earned = $inst->dbNonce;
$check(is_string($earned) && $earned === ($_SESSION[$nonceKey] ?? null) && $inst->isInstalled() === false,
    'the import stores a nonce in the database and the owner goes on');
$inst->dbNonce = bin2hex(random_bytes(32));
$check($inst->isInstalled() === true, 'a database replaced under the same .env (other nonce) invalidates the proof');
$inst->dbNonce = null;
$check($inst->isInstalled() === true, 'so does a restored database without the nonce');
$inst->dbNonce = false;
$check($inst->isInstalled() === true && $inst->ownerProofUnverifiable(), 'an unreachable database proves nothing (and is reported as such)');
$inst->dbNonce = $earned;
$check($inst->isInstalled() === false && $inst->recordSchemaImportByOwner() === true && $inst->dbNonce === $earned,
    'the original database is accepted again, and a retried import keeps its proof');
$inst->releaseInstallOwnership();
$check($inst->dbNonce === null && !is_file($markerPathAbs), 'finishing the install drops the nonce with the marker');
$_SESSION = [];
$inst->claimInstallOwnership();
$_SESSION = [Installer::INSTALL_OWNER_SESSION_KEY => $token, $popKey => $proof, $nonceKey => $nonce];
file_put_contents($markerPathAbs, hash('sha256', $token) . "\n" . time());
$inst->dbNonce = $nonce;
clearstatcache();

// A lost owner session must not shut everyone out for half a day: the binding
// lasts INSTALL_OWNER_TTL of inactivity (2 hours), renewed by the owner's use.
$check(Installer::INSTALL_OWNER_TTL <= 7200, 'the install-in-progress binding lasts at most 2 hours without activity (was 12)');
$markerPath = $site . '/storage/.install_in_progress';
touch($markerPath, time() - Installer::INSTALL_OWNER_TTL + 60);
clearstatcache();
$check($inst->isInstalled() === false, 'an owner request one minute before expiry still goes on');
clearstatcache();
$check(time() - (int) filemtime($markerPath) < 5, '... and renews the binding (sliding expiry)');
$saved = $_SESSION;
$_SESSION = [];
touch($markerPath, time() - Installer::INSTALL_OWNER_TTL + 60);
clearstatcache();
$inst->isInstalled();
clearstatcache();
$check(time() - (int) filemtime($markerPath) > 60, 'a foreign session never renews the binding');
$_SESSION = $saved;
touch($markerPath);
clearstatcache();

$inst->releaseInstallOwnership();
clearstatcache();
$check(!is_file($site . '/storage/.install_in_progress') && !isset($_SESSION[Installer::INSTALL_OWNER_SESSION_KEY]),
    'releaseInstallOwnership() removes the marker and the session token');
$check($inst->isInstalled() === true, 'after release, .env alone counts as installed again');

// ---------------------------------------------------------------------------
echo "B. createEnvFile() binds the session that writes .env\n";
// ---------------------------------------------------------------------------
$site2 = $mkSite();
$inst2 = new ProbeInstaller($site2);
$inst2->emptyDb = true; // step 2 only accepts an empty database
$_SESSION = [];
$check($inst2->createEnvFile('127.0.0.1', 'u', 'p', 'db') === true, 'createEnvFile() writes .env');
$check(is_file($site2 . '/.env') && $inst2->isInstalled() === false, 'the writing session owns the install in progress');
$_SESSION = [];
$check($inst2->isInstalled() === true, 'any other session sees an installed application and cannot reach step 2');

$check($noSessionResult === false && !is_file($siteNoSession . '/.env'),
    'without a session to bind, no .env is written');
$site4 = $mkSite();
rmdir($site4 . '/storage');
file_put_contents($site4 . '/storage', 'not a directory');
$check((new ProbeInstaller($site4))->createEnvFile('127.0.0.1', 'u', 'p', 'db') === false && !is_file($site4 . '/.env'),
    'when the binding marker cannot be written, no .env is written');

// A failed .env write must not leave a binding behind (it would grant ?force=1).
// A directory named .env makes the write fail while the binding can be taken.
$site6 = $mkSite();
mkdir($site6 . '/.env');
$inst6 = new ProbeInstaller($site6);
$_SESSION = [];
$res6 = @$inst6->createEnvFile('127.0.0.1', 'u', 'p', 'db');
clearstatcache();
$check($res6 === false, 'createEnvFile() reports a failed .env write');
$check(!is_file($site6 . '/storage/.install_in_progress') && !array_key_exists(Installer::INSTALL_OWNER_SESSION_KEY, $_SESSION),
    'failed .env write without a previous binding: no marker and no session token left');
$check(!$inst6->ownsInstallInProgress(), 'failed .env write: the session does not own the install');

// With a previous binding of this session, that exact binding comes back.
$site7 = $mkSite();
$inst7 = new ProbeInstaller($site7);
$_SESSION = [];
$inst7->claimInstallOwnership();
$prevToken7 = (string) $_SESSION[Installer::INSTALL_OWNER_SESSION_KEY];
$prevMtime7 = time() - 600;
touch($site7 . '/storage/.install_in_progress', $prevMtime7);
mkdir($site7 . '/.env');
$res7 = @$inst7->createEnvFile('127.0.0.1', 'u', 'p', 'db');
clearstatcache();
$check($res7 === false
    && ($_SESSION[Installer::INSTALL_OWNER_SESSION_KEY] ?? null) === $prevToken7
    && explode("\n", (string) @file_get_contents($site7 . '/storage/.install_in_progress'))[0] === hash('sha256', $prevToken7)
    && filemtime($site7 . '/storage/.install_in_progress') === $prevMtime7,
    'failed .env write with a previous binding: token, marker and expiry restored as they were');

// ---------------------------------------------------------------------------
echo "D. A claimed install is exclusive (no empty-DB exception for others)\n";
// ---------------------------------------------------------------------------
$site8 = $mkSite();
$envBefore8 = "DB_NAME=wizard\n";
file_put_contents($site8 . '/.env', $envBefore8);
$inst8 = new ProbeInstaller($site8);
$inst8->emptyDb = true; // Docker wizard: pre-written .env, empty database
$_SESSION = [];
$check($inst8->isInstalled() === false, 'unclaimed wizard .env over an empty database: not installed');
$check($inst8->claimInstallOwnership() === true, 'session A claims the install at step 2');
$tokenA = (string) $_SESSION[Installer::INSTALL_OWNER_SESSION_KEY];
$markerA = (string) file_get_contents($site8 . '/storage/.install_in_progress');

$_SESSION = []; // session B
$check($inst8->isInstalled() === true, 'session B: a live claim of A turns off the empty-database exception');
$check(method_exists($inst8, 'installClaimedByAnotherSession') && $inst8->installClaimedByAnotherSession() === true, 'session B sees the install claimed by another session');
$check($inst8->claimInstallOwnership() === false && !isset($_SESSION[Installer::INSTALL_OWNER_SESSION_KEY]),
    'session B cannot take the claim');
$check($inst8->createEnvFile('evil-host', 'evil', 'evil', 'evil') === false, 'session B cannot write .env');
clearstatcache();
$check((string) file_get_contents($site8 . '/.env') === $envBefore8
    && (string) file_get_contents($site8 . '/storage/.install_in_progress') === $markerA,
    'session B changed neither .env nor the marker');

$_SESSION = [Installer::INSTALL_OWNER_SESSION_KEY => $tokenA];
$check($inst8->ownsInstallInProgress() && $inst8->isInstalled() === false && !(method_exists($inst8, 'installClaimedByAnotherSession') && $inst8->installClaimedByAnotherSession()),
    'session A still owns the install');
$check($inst8->claimInstallOwnership() === true, 'session A may re-claim its own install (step 2 again)');
$tokenA = (string) $_SESSION[Installer::INSTALL_OWNER_SESSION_KEY];

touch($site8 . '/storage/.install_in_progress', time() - Installer::INSTALL_OWNER_TTL - 10);
clearstatcache();
$_SESSION = []; // session B again, after expiry
$check($inst8->isInstalled() === false, 'an expired claim no longer blocks the empty-database exception');
$check($inst8->claimInstallOwnership() === true, 'an expired claim can be taken');
$_SESSION = [Installer::INSTALL_OWNER_SESSION_KEY => $tokenA]; // session A comes back
$check(!$inst8->ownsInstallInProgress() && $inst8->isInstalled() === true,
    'once another session took the expired claim over, the old owner no longer owns the install');

$site9 = $mkSite();
$inst9 = new ProbeInstaller($site9);
$_SESSION = [];
$inst9->claimInstallOwnership();
$_SESSION = [];
$check($inst9->claimInstallOwnership() === true,
    'without .env a leftover claim does not block a fresh install (nothing is live yet)');

if (function_exists('posix_getuid') && posix_getuid() !== 0) {
    $site10 = $mkSite();
    file_put_contents($site10 . '/.env', "DB_NAME=wizard\n");
    $inst10 = new ProbeInstaller($site10);
    $inst10->emptyDb = true;
    file_put_contents($site10 . '/storage/.install_in_progress', hash('sha256', 'someone'));
    chmod($site10 . '/storage/.install_in_progress', 0000);
    $_SESSION = [];
    $check($inst10->isInstalled() === true && $inst10->claimInstallOwnership() === false,
        'an unreadable live marker counts as claimed (fail closed)');
    chmod($site10 . '/storage/.install_in_progress', 0600);
}

// ---------------------------------------------------------------------------
echo "E. The owner goes on only while no account can exist to check it\n";
// ---------------------------------------------------------------------------
$hasRule = method_exists(Installer::class, 'ownerMayContinue') && method_exists(Installer::class, 'recordSchemaImportByOwner');
$check($hasRule, 'Installer::ownerMayContinue() and recordSchemaImportByOwner() exist');
$site11 = $mkSite();
$inst11 = new ProbeInstaller($site11);
$inst11->emptyDb = true;
$_SESSION = [];
$inst11->createEnvFile('127.0.0.1', 'u', 'p', 'newdb');
$check($inst11->isInstalled() === false && (!$hasRule || $inst11->ownerMayContinue()),
    '(i) owner over the still-empty new database: goes on (no account exists yet)');
$inst11->emptyDb = false; // populated, and not by this session
$check($inst11->ownsInstallInProgress() && $inst11->isInstalled() === true,
    'owner over a populated database it did not fill: refused (needs an active admin of that database)');
$check(!$hasRule || $inst11->ownerMayContinue() === false, 'ownerMayContinue() says no');
$_SESSION[$popKey] = hash('sha256', 'populated:' . bin2hex(random_bytes(32)));
$check($inst11->isInstalled() === true, 'a forged import proof is refused');
unset($_SESSION[$popKey]);

// An owner that reached step 3 through an admin of an already-populated
// database earns no standing proof: it keeps needing that admin.
$check($hasRule && $inst11->recordSchemaImportByOwner() === false && !isset($_SESSION[$popKey]),
    'no import proof over a database that is already populated (the probe is re-read, not memoised)');
$check($inst11->isInstalled() === true, 'so that owner still needs an active admin');

$inst11->emptyDb = true;
$check($hasRule && $inst11->recordSchemaImportByOwner() === true, 'step 3 records the import while the database is still empty');
$inst11->emptyDb = false; // step 3 filled it
$check($inst11->isInstalled() === false, '(ii) the session that imported the schema goes on over its own database (steps 4-7)');
$check($inst11->recordSchemaImportByOwner() === true, 'a retry of a failed import keeps the proof it already earned');

$inst11->emptyDb = true;
$inst11->createEnvFile('127.0.0.1', 'u', 'p', 'otherdb'); // back to step 2: new binding, new .env
$inst11->emptyDb = false;
$check($inst11->isInstalled() === true, 'a new step 2 drops the old import proof (it was for another database)');

$saved = $_SESSION;
$_SESSION = [];
$check($hasRule && $inst11->recordSchemaImportByOwner() === false && !isset($_SESSION[$popKey]),
    'a session that does not own the install cannot record an import');
$_SESSION = $saved + [$forceKey => true];
$inst11->releaseInstallOwnership();
$check(!isset($_SESSION[$popKey]) && !isset($_SESSION[$forceKey]) && !isset($_SESSION[Installer::INSTALL_OWNER_SESSION_KEY]),
    'releaseInstallOwnership() also drops the import proof and the reinstall routing hint');

// A new .env voids everything recorded about the previous database.
$_SESSION = ['schema_imported' => true, 'data_imported' => true, 'installation_complete' => true,
    'installation_finalized' => true, 'admin_user' => ['id' => 1], 'completed_steps' => [0, 1, 2, 3, 4, 5, 6],
    'app_locale' => 'it_IT'];
(new Installer($mkSite()))->resetProgressAfterEnvWrite();
$check(!isset($_SESSION['schema_imported'], $_SESSION['data_imported'], $_SESSION['installation_complete'],
        $_SESSION['installation_finalized'], $_SESSION['admin_user'])
    && $_SESSION['completed_steps'] === [0, 1, 2] && ($_SESSION['app_locale'] ?? '') === 'it_IT',
    'resetProgressAfterEnvWrite() drops the previous database\'s progress, keeps steps 0-2 and the language');
$step2 = (string) file_get_contents($ROOT . '/installer/steps/step2.php');
$envPos = strpos($step2, '$installer->createEnvFile(');
$resetPos = strpos($step2, '$installer->resetProgressAfterEnvWrite();');
$check($envPos !== false && $resetPos !== false && $resetPos > $envPos && $resetPos < (int) strpos($step2, 'completeStep(2)'),
    'step 2 resets the progress right after a successful .env write, before marking step 2 done');
$_SESSION = [];

// The reinstall gate (?force=1) runs with .installed present, where isInstalled()
// answers before its own renewal: the owner's binding must still slide.
$siteR = $mkSite();
$instR = new ProbeInstaller($siteR);
$instR->emptyDb = true;
$_SESSION = [];
$instR->createEnvFile('127.0.0.1', 'u', 'p', 'newdb');
file_put_contents($siteR . '/.installed', 'x');
$markerR = $siteR . '/storage/.install_in_progress';
$aged = time() - Installer::INSTALL_OWNER_TTL + 60;
touch($markerR, $aged);
clearstatcache(true, $markerR);
$check($instR->isInstalled() === true && (int) filemtime($markerR) === $aged,
    'with .installed present isInstalled() answers installed and does not renew the binding (why the gate must)');
$check($instR->ownerMayContinue() === true, 'the owner still passes ownerMayContinue() over the empty database');
$instR->renewInstallOwnership();
clearstatcache(true, $markerR);
$check((int) filemtime($markerR) >= time() - 5, 'renewInstallOwnership() restarts the binding of the owner');
touch($markerR, $aged);
clearstatcache(true, $markerR);
$_SESSION = [];
$instR->renewInstallOwnership();
clearstatcache(true, $markerR);
$check((int) filemtime($markerR) === $aged, 'a session that does not own the install cannot keep the binding alive');
$_SESSION = [];
$gateSrc = (string) file_get_contents($ROOT . '/installer/index.php');
$ownerMayPos = strpos($gateSrc, 'if ($installer->ownerMayContinue()) {');
$renewPos = strpos($gateSrc, '$installer->renewInstallOwnership();');
$check($ownerMayPos !== false && $renewPos !== false && $renewPos > $ownerMayPos && $renewPos < strpos($gateSrc, '$forceAuthenticated = true;', $ownerMayPos),
    'the ?force=1 gate renews the owner right after ownerMayContinue() confirms it');

$step3 = (string) file_get_contents($ROOT . '/installer/steps/step3.php');
$recPos = strpos($step3, '$installer->recordSchemaImportByOwner()');
$impPos = strpos($step3, '$installer->importSchema();');
$check($recPos !== false && $impPos !== false && $recPos < $impPos, 'step 3 records the import before importSchema() (a failed import stays retryable)');

// Review of PR #536 (F054): installer/.htaccess redirected every request
// without "force" in it to / while <docroot>/.installed existed, which on a
// project-root docroot broke a ?force=1 reinstall at its first form. The PHP
// gate decides on its own; no Apache rule may answer on .installed.
$installerHtaccess = (string) file_get_contents($ROOT . '/installer/.htaccess');
$activeRules = implode("\n", array_filter(array_map('trim', explode("\n", $installerHtaccess)), static fn (string $l): bool => $l !== '' && $l[0] !== '#'));
$check(!str_contains($activeRules, '.installed') && !preg_match('/R=30[12]/', $activeRules),
    'installer/.htaccess has no redirect keyed on .installed (the PHP force gate decides)');

// Review of PR #536 (F055): step 3 ignored a refused proof and imported anyway,
// filling the database for nobody and wedging the wizard for every session.
// Runs the real installer/steps/step3.php in a subprocess over an installer
// double that records what it is asked to do.
$runStep3 = static function (bool $proofRecorded) use ($ROOT, $tmpRoot): array {
    $script = $tmpRoot . '/step3-' . bin2hex(random_bytes(3)) . '.php';
    file_put_contents($script, '<?php
        function __(string $k, mixed ...$a): string { return $a ? sprintf($k, ...$a) : $k; }
        function completeStep($n): void { $GLOBALS["calls"][] = "completeStep" . $n; }
        function renderHeader($s, $t): void {}
        function renderFooter(): void {}
        $calls = [];
        $installer = new class ((bool) ' . ($proofRecorded ? 'true' : 'false') . ') {
            public function __construct(private bool $proof) {}
            public function __call(string $m, array $a): mixed {
                $GLOBALS["calls"][] = $m;
                return match ($m) { "recordSchemaImportByOwner" => $this->proof, "getTriggerWarnings" => [], "installOpenLibraryPlugin" => 1, default => true };
            }
        };
        $_SESSION = [];
        register_shutdown_function(static function () use (&$error): void {
            echo "\n@@" . json_encode(["calls" => $GLOBALS["calls"], "error" => $GLOBALS["error"] ?? null]);
        });
        require ' . var_export($ROOT . '/installer/steps/step3.php', true) . ';
    ');
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
    @unlink($script);
    $json = substr($out, (int) strrpos($out, '@@') + 2);
    return (array) json_decode($json, true);
};
$refused = $runStep3(false);
$check(in_array('recordSchemaImportByOwner', $refused['calls'] ?? [], true) && !in_array('importSchema', $refused['calls'] ?? [], true)
    && !in_array('completeStep3', $refused['calls'] ?? [], true) && is_string($refused['error'] ?? null) && $refused['error'] !== '',
    'step 3 imports nothing and shows an error when the import proof cannot be recorded');
$allowed = $runStep3(true);
$check(in_array('importSchema', $allowed['calls'] ?? [], true) && in_array('completeStep3', $allowed['calls'] ?? [], true),
    'step 3 imports when the owner records its proof (control)');
$index = (string) file_get_contents($ROOT . '/installer/index.php');
$check(str_contains($index, 'if ($installer->ownerMayContinue()) {') && !str_contains($index, 'if ($installer->ownsInstallInProgress()) {'),
    'the ?force=1 gate lets the owner through only under ownerMayContinue()');
$interruptedKey = "Installazione interrotta (manca il file .installed). Il browser che l'ha iniziata può continuarla.";
$check(substr_count($index, "!\$installationStatus['lock_exists'] ? '<div class=\"alert alert-warning mt-3\">' . htmlspecialchars(sprintf(__(\"" . $interruptedKey) === 2
    && str_contains($index, 'intdiv(Installer::INSTALL_OWNER_TTL, 60)'),
    'both installed-state pages explain an interrupted install (no .installed): wait for the binding, or delete .env AND empty the database');
foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $loc) {
    $strings = json_decode((string) file_get_contents($ROOT . '/locale/' . $loc . '.json'), true);
    $key = null;
    foreach (array_keys(is_array($strings) ? $strings : []) as $k) {
        if (str_starts_with((string) $k, $interruptedKey)) { $key = $k; }
    }
    $check($key !== null && str_contains((string) $strings[$key], '%d') && str_contains((string) $strings[$key], '.env'),
        "the interrupted-install notice is translated ({$loc})");
}

// ---------------------------------------------------------------------------
echo "C. Step 7 surfaces finalisation failures (real installer/steps/step7.php)\n";
// ---------------------------------------------------------------------------

function renderHeader($currentStep, $stepTitle): void { echo "[HEADER:$stepTitle]"; }
function renderFooter(): void { echo '[FOOTER]'; }

final class FakeFinaliser
{
    public bool $throwOnSettings = false;
    public bool $lockWrites = true;
    public bool $released = false;
    private string $dir;
    public function __construct(string $dir) { $this->dir = $dir; }
    public function loadEnvConfig(): bool { return true; }
    public function populateDefaultSettings(): void
    {
        if ($this->throwOnSettings) {
            throw new \PDOException("Table 'system_settings' doesn't exist");
        }
    }
    public function installPluginsFromZip(): array { return []; }
    public function createHtaccess(): bool { return true; }
    public function createLockFile(): bool
    {
        return $this->lockWrites && file_put_contents($this->dir . '/.installed', "x\n") !== false;
    }
    public function hasLockFile(): bool { return is_file($this->dir . '/.installed'); }
    public function releaseInstallOwnership(): void { $this->released = true; }
    public function setSecurePermissions(): array { return ['directories' => 0, 'files' => 0, 'sensitive_files' => 0]; }
}

$runStep7 = static function (FakeFinaliser $installer, string $baseDir) use ($ROOT): string {
    $installerBasePath = '';
    ob_start();
    (static function () use ($installer, $baseDir, $installerBasePath, $ROOT): void {
        require $ROOT . '/installer/steps/step7.php';
    })();
    return (string) ob_get_clean();
};

$site5 = $mkSite();
$_SESSION = [];
$fake = new FakeFinaliser($site5);
$fake->throwOnSettings = true;
$out = $runStep7($fake, $site5);
$check(str_contains($out, 'Errore durante la finalizzazione:') && str_contains($out, 'system_settings'), 'a thrown finalisation error is rendered');
$check(!str_contains($out, 'Installazione Completata!'), 'no success banner after a finalisation error');
$check(!isset($_SESSION['installation_finalized']) && !is_file($site5 . '/.installed'), 'not marked finalised, no lock written');

$_SESSION = [];
$fake = new FakeFinaliser($site5);
$fake->lockWrites = false;
$out = $runStep7($fake, $site5);
$check(str_contains($out, 'Impossibile creare il file .installed') && !str_contains($out, 'Installazione Completata!'),
    'an unwritten .installed is reported as a failure, never as success');
$check(!$fake->released, 'the install binding is kept so the owner can retry');

$_SESSION = ['installation_finalized' => true];
$fake = new FakeFinaliser($site5);
$fake->lockWrites = false;
$out = $runStep7($fake, $site5);
$check(str_contains($out, 'Impossibile creare il file .installed') && !str_contains($out, 'Installazione Completata!')
    && !is_file($site5 . '/.installed') && !$fake->released,
    'a finalised session without .installed on disk, lock still unwritable: error, no success page');

// The "Riprova la finalizzazione" retry of a finalised session must be able to
// write the missing lock again (it used to skip createLockFile() forever).
$_SESSION = ['installation_finalized' => true];
$fake = new FakeFinaliser($site5);
$out = $runStep7($fake, $site5);
$check(is_file($site5 . '/.installed') && $fake->released
    && str_contains($out, 'Installazione Completata!') && !str_contains($out, 'Impossibile creare'),
    'the retry of a finalised session recreates .installed, and only then shows success');
// nosemgrep: php.lang.security.unlink-use.unlink-use -- test temp file
unlink($site5 . '/.installed');

$_SESSION = [];
$fake = new FakeFinaliser($site5);
$out = $runStep7($fake, $site5);
$check(str_contains($out, 'Installazione Completata!') && is_file($site5 . '/.installed') && $fake->released
    && ($_SESSION['installation_finalized'] ?? false) === true,
    'the success page appears only once .installed exists, and the binding is released');

// ---------------------------------------------------------------------------
// Cleanup: only the private temp tree created above.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); // nosemgrep
}
@rmdir($tmpRoot);

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} PASS, {$failed} FAIL\n");
exit($failed === 0 ? 0 : 1);

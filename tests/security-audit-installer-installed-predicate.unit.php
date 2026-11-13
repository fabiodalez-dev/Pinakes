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
 *      when it cannot bind.
 *   C. step 7 renders the error and never the success page when finalisation
 *      throws or .installed cannot be written (real step7.php, fake installer).
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

/** Installer whose database probe answers what the test says. */
final class ProbeInstaller extends Installer
{
    public bool $emptyDb = false;
    public int $probes = 0;
    protected function configuredDatabaseIsEmpty(): bool
    {
        $this->probes++;
        return $this->emptyDb;
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
$check((new Installer($siteReal))->isInstalled() === true, 'real probe: unreachable database counts as installed');
file_put_contents($siteReal . '/.env', "# no database configured\nAPP_ENV=production\n");
$check((new Installer($siteReal))->isInstalled() === true, 'real probe: .env without DB_NAME counts as installed');

// Ownership binding.
$_SESSION = [];
$check($inst->claimInstallOwnership() === true, 'claimInstallOwnership() succeeds with an active session');
$token = (string) ($_SESSION[Installer::INSTALL_OWNER_SESSION_KEY] ?? '');
$marker = (string) @file_get_contents($site . '/storage/.install_in_progress');
$check($token !== '' && strlen($token) === 64, 'a 256-bit token is kept in the session');
$check($marker === hash('sha256', $token) && !str_contains($marker, $token), 'only the token hash is on disk');
$check($inst->ownsInstallInProgress() && $inst->isInstalled() === false, 'the owning session may continue the install');

$_SESSION = [];
$check($inst->isInstalled() === true, 'a fresh session (no token) is refused');
$_SESSION[Installer::INSTALL_OWNER_SESSION_KEY] = bin2hex(random_bytes(32));
$check($inst->isInstalled() === true, 'a forged token is refused');
$_SESSION[Installer::INSTALL_OWNER_SESSION_KEY] = $token;
touch($site . '/storage/.install_in_progress', time() - Installer::INSTALL_OWNER_TTL - 10);
clearstatcache();
$check($inst->isInstalled() === true, 'an expired binding is refused');
touch($site . '/storage/.install_in_progress');
clearstatcache();
$check($inst->isInstalled() === false, 'a live binding is honoured again');

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
$out = $runStep7(new FakeFinaliser($site5), $site5);
$check(str_contains($out, "Il file .installed non è presente") && !str_contains($out, 'Installazione Completata!'),
    'a finalised session without .installed on disk still gets no success page');

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

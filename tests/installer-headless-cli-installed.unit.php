<?php
declare(strict_types=1);

/**
 * Installer::isInstalled() as the Docker image's config/headless-install.php
 * calls it: CLI SAPI, no session, .env already written by the entrypoint, and
 * no .installed (it lives in the ephemeral container layer, so a container
 * recreate or image upgrade loses it). The released script exits 0 when
 * isInstalled() is true, BEFORE the code that re-writes .installed over a
 * populated database; so under the CLI the answer must be the lock file alone,
 * or the site stays on the installer forever. On a first install with an
 * unreachable database the same guard would skip the install silently.
 *
 *   A. CLI, .env + unreachable database, no .installed: false.
 *   B. CLI, .env + populated database, no .installed: false (real database
 *      when E2E_DB_* is set, and in any case without running the probe).
 *   C. CLI with .installed: true.
 *   D. The web predicate is unchanged for the same files (fail closed).
 *
 * Run: php tests/installer-headless-cli-installed.unit.php
 *      (optionally with the E2E_DB_* variables of a disposable database)
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

$check(PHP_SAPI === 'cli', 'this test runs under the CLI SAPI, like headless-install.php');
$check(session_status() !== PHP_SESSION_ACTIVE && !isset($_SESSION), 'no session, like headless-install.php');

$tmpRoot = sys_get_temp_dir() . '/pk_headless_cli_' . bin2hex(random_bytes(5));
$mkSite = static function () use ($tmpRoot): string {
    $d = $tmpRoot . '/site_' . bin2hex(random_bytes(4));
    mkdir($d . '/storage', 0700, true);
    return $d;
};

/** Counts probe connections and reports a populated database. */
final class PopulatedProbe extends Installer
{
    public int $probes = 0;
    public bool $web = false;
    protected function runsUnderCli(): bool
    {
        return $this->web ? false : parent::runsUnderCli();
    }
    protected function configuredDatabaseIsEmpty(): bool
    {
        $this->probes++;
        return false;
    }
}

// ---------------------------------------------------------------------------
echo "A. CLI, unreachable database\n";
// ---------------------------------------------------------------------------
$siteA = $mkSite();
file_put_contents($siteA . '/.env', "DB_HOST=127.0.0.1\nDB_PORT=1\nDB_USER=x\nDB_PASS=x\nDB_NAME=pinakes\n");
$check((new Installer($siteA))->isInstalled() === false,
    'first headless install, database not reachable yet: not installed (the install is not skipped silently)');

// ---------------------------------------------------------------------------
echo "B. CLI, populated database (container recreate / image upgrade)\n";
// ---------------------------------------------------------------------------
$siteB = $mkSite();
file_put_contents($siteB . '/.env', "DB_HOST=db\nDB_PORT=3306\nDB_USER=pinakes\nDB_PASS=x\nDB_NAME=pinakes\n");
$probe = new PopulatedProbe($siteB);
$check($probe->isInstalled() === false && $probe->probes === 0,
    'populated database, no .installed: not installed, and the database is not even probed');

$dbName = (string) (getenv('E2E_DB_NAME') ?: '');
if ($dbName !== '' && (getenv('E2E_DB_USER') ?: '') !== '') {
    $socket = (string) (getenv('E2E_DB_SOCKET') ?: '');
    $env = 'DB_USER="' . addcslashes((string) getenv('E2E_DB_USER'), "\"\\\$") . "\"\n"
        . 'DB_PASS="' . addcslashes((string) (getenv('E2E_DB_PASS') ?: ''), "\"\\\$") . "\"\n"
        . 'DB_NAME="' . addcslashes($dbName, "\"\\\$") . "\"\n"
        . ($socket !== ''
            ? 'DB_SOCKET="' . addcslashes($socket, "\"\\\$") . "\"\n"
            : 'DB_HOST=' . (getenv('E2E_DB_HOST') ?: '127.0.0.1') . "\nDB_PORT=" . (getenv('E2E_DB_PORT') ?: '3306') . "\n");
    $siteReal = $mkSite();
    file_put_contents($siteReal . '/.env', $env);
    $tables = -1;
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = $socket !== ''
            ? new mysqli('localhost', (string) getenv('E2E_DB_USER'), (string) (getenv('E2E_DB_PASS') ?: ''), $dbName, 0, $socket)
            : new mysqli((string) (getenv('E2E_DB_HOST') ?: '127.0.0.1'), (string) getenv('E2E_DB_USER'), (string) (getenv('E2E_DB_PASS') ?: ''), $dbName, (int) (getenv('E2E_DB_PORT') ?: 3306));
        $row = $db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetch_row();
        $tables = (int) ($row[0] ?? -1);
        $db->close();
    } catch (\Throwable $e) {
        echo "  note: E2E database not reachable (" . $e->getMessage() . "), real-database case not run\n";
    }
    if ($tables > 0) {
        $check((new Installer($siteReal))->isInstalled() === false,
            "real populated database ({$tables} tables), no .installed: not installed under the CLI");
        $webReal = new class ($siteReal) extends Installer {
            protected function runsUnderCli(): bool { return false; }
        };
        $check($webReal->isInstalled() === true, 'the same real database through the web predicate: installed');
    } elseif ($tables === 0) {
        echo "  note: E2E database is empty, real-database case not run\n";
    }
} else {
    echo "  note: E2E_DB_* not set, real-database case not run (the probe-free case above covers it)\n";
}

// ---------------------------------------------------------------------------
echo "C. CLI with the lock file\n";
// ---------------------------------------------------------------------------
file_put_contents($siteB . '/.installed', "x\n");
$check((new Installer($siteB))->isInstalled() === true, '.installed present: installed (the idempotency guard still works)');
// nosemgrep: php.lang.security.unlink-use.unlink-use -- test temp file
unlink($siteB . '/.installed');
$check((new Installer($tmpRoot . '/missing'))->isInstalled() === false, 'no .env and no .installed: not installed');

// ---------------------------------------------------------------------------
echo "D. The web predicate is unchanged\n";
// ---------------------------------------------------------------------------
$web = new PopulatedProbe($siteB);
$web->web = true;
$check($web->isInstalled() === true && $web->probes === 1, 'web: .env over a populated database counts as installed');
$webA = new class ($siteA) extends Installer {
    protected function runsUnderCli(): bool { return false; }
};
$check($webA->isInstalled() === true, 'web: .env over an unreachable database counts as installed (fail closed)');

// Cleanup: only the private temp tree created above.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); // nosemgrep
}
@rmdir($tmpRoot);

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} PASS, {$failed} FAIL\n");
exit($failed === 0 ? 0 : 1);

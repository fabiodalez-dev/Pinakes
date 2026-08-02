<?php
declare(strict_types=1);

/**
 * Reusable guard for the linchpin of the maintenance-recovery design (PR #312):
 * the update runs its cleanup in a `finally`, and cleanup() lifts maintenance.
 * That is what makes EVERY non-fatal outcome (success or caught exception)
 * leave maintenance mode — the shutdown handler is only the fatal-path fallback.
 *
 * maintenance-recovery-lockout.unit.php already guards the handlers, the
 * ownership flag, the disarm-before-unlock order and the persistent lock inode.
 * This file adds the piece none of those cover: if disableMaintenanceMode() is
 * ever dropped from cleanup(), those guards all still pass while the site stays
 * stuck in maintenance after every update. So prove it — behaviourally.
 *
 * Run:  php tests/updater-maintenance-cleanup.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\Updater;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$updaterSrc = (string) file_get_contents($root . '/app/Support/Updater.php');
$backupSrc  = (string) file_get_contents($root . '/app/Support/BackupManager.php');

// 1. Behavioural: cleanup() removes an existing storage/.maintenance. Needs a
//    mysqli for the constructor (cleanup itself doesn't touch the DB). Skips if
//    the DB is unreachable — the source guards below still run.
$maintenanceFile = $root . '/storage/.maintenance';
$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
$db = null;
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', 0, $socket)
        : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'] ?? '', $env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''), $env['DB_NAME'] ?? '', (int) ($env['DB_PORT'] ?? 3306));
} catch (\Throwable) {
    $db = null;
}

if ($db === null) {
    echo "  SKIP 01 behavioural cleanup() check — database unreachable\n";
} else {
    // Never clobber a real update in progress.
    if (is_file($maintenanceFile)) {
        echo "  SKIP 01 behavioural cleanup() check — storage/.maintenance already present\n";
    } else {
        file_put_contents($maintenanceFile, json_encode(['time' => time(), 'message' => 'cleanup self-test']));
        try {
            (new Updater($db))->cleanup();
            $check(!is_file($maintenanceFile), "01 cleanup() lifts maintenance (removes storage/.maintenance)");
        } finally {
            if (is_file($maintenanceFile)) {
                @unlink($maintenanceFile);
            }
        }
    }
    $db->close();
}

// 2. cleanup() actually calls disableMaintenanceMode().
$cleanupBody = '';
if (preg_match('/public function cleanup\(\): void\s*\{(.*?)\n    \}/s', $updaterSrc, $m)) {
    $cleanupBody = $m[1];
}
$check(
    $cleanupBody !== '' && str_contains($cleanupBody, '$this->disableMaintenanceMode()'),
    "02 cleanup() calls disableMaintenanceMode()"
);

// 3. Both update paths run cleanup() from a `finally`, so any non-fatal outcome
//    lifts maintenance regardless of the shutdown handler.
$finallyCleanups = preg_match_all('/\}\s*finally\s*\{\s*\$this->cleanup\(\);/s', $updaterSrc);
$check($finallyCleanups >= 2, "03 both update paths run cleanup() in a finally (got {$finallyCleanups})");

// 4. The restore path (BackupManager) keeps the shared lock inode persistent —
//    its shutdown handler must not capture or unlink $lockFile (same inode-race
//    fix as the updater).
$restoreHandlerSafe = (bool) preg_match(
    '/register_shutdown_function\(static function \(\) use \(\$maintenanceFile\): void/',
    $backupSrc
) && !preg_match('/@unlink\(\$lockFile\)/', $backupSrc);
$check($restoreHandlerSafe, "04 BackupManager restore keeps the lock inode persistent (no \$lockFile unlink)");

echo "\n{$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);

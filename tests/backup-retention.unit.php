<?php
declare(strict_types=1);

/**
 * Backup rotation (BackupManager).
 *
 * A backup is written before every update and nothing removed the old ones.
 * On a live installation that meant 73 files and ~300 MB accumulated in three
 * months, until the account ran out of space and the NEXT update failed while
 * copying the application aside for rollback: the backup meant to make updates
 * safe was what broke them. The error surfaced as a copy failure on whichever
 * file the process happened to reach ("illuminate/support/Str.php"), which says
 * nothing about the real cause — hence this suite pins the rotation itself.
 *
 * Covered against the REAL class and a real backups directory:
 *  - the rotation keeps exactly the configured number of automatic backups,
 *    newest first, and the file just written is always among them;
 *  - files an administrator dropped in the directory by hand (any name that is
 *    not the generated backup_*.zip) are never deleted;
 *  - retention_count = 0 disables the rotation (for setups archiving elsewhere);
 *  - a missing setting falls back to the default instead of skipping the rotation.
 *
 * Run: php tests/backup-retention.unit.php
 */

use App\Support\BackupManager;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

/**
 * Drive the private rotation directly: creating real backups would need a
 * database dump per file, and what is under test is the retention rule.
 */
$prune = static function (BackupManager $manager, string $justWritten): void {
    $ref = new ReflectionMethod(BackupManager::class, 'pruneOldBackups');
    $ref->setAccessible(true);
    $ref->invoke($manager, $justWritten);
};

// The retention is read through SettingsRepository, which runs real queries:
// a fake connection cannot answer it, so the suite drives the real setting on
// the real database and restores it afterwards.
$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — the retention is read from settings: {$e->getMessage()}\n");
    exit(1);
}

$origRetention = null;
$res = $db->query("SELECT setting_value FROM system_settings WHERE category = 'backup' AND setting_key = 'retention_count'");
if ($res instanceof mysqli_result && ($row = $res->fetch_assoc())) {
    $origRetention = (string) $row['setting_value'];
}
$setRetention = static function (?string $value) use ($db): void {
    if ($value === null) {
        $db->query("DELETE FROM system_settings WHERE category = 'backup' AND setting_key = 'retention_count'");
        return;
    }
    $stmt = $db->prepare("INSERT INTO system_settings (category, setting_key, setting_value, updated_at)
        VALUES ('backup', 'retention_count', ?, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
    $stmt->bind_param('s', $value);
    $stmt->execute();
    $stmt->close();
};

$makeManager = static function (string $dir, ?string $retention) use ($root, $db, $setRetention): BackupManager {
    $setRetention($retention);
    $manager = new BackupManager($db, $root);
    $prop = new ReflectionProperty(BackupManager::class, 'backupPath');
    $prop->setAccessible(true);
    $prop->setValue($manager, $dir);
    return $manager;
};

$tmp = sys_get_temp_dir() . '/zz_backup_retention_' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);
$cleanup = static function () use ($tmp, &$origRetention, &$setRetention): void {
    foreach (glob($tmp . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
    if (is_callable($setRetention)) {
        $setRetention($origRetention);
    }
};
set_exception_handler(static function (Throwable $e) use ($cleanup): void {
    $cleanup();
    fwrite(STDERR, 'FAIL: uncaught ' . $e->getMessage() . PHP_EOL);
    exit(1);
});

/** Seed n automatic backups with decreasing mtime, newest last. */
$seed = static function (int $n) use ($tmp): array {
    $paths = [];
    for ($i = 0; $i < $n; $i++) {
        $p = $tmp . '/backup_2026-01-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . '_000000_' . bin2hex(random_bytes(3)) . '.zip';
        file_put_contents($p, 'x');
        touch($p, time() - (($n - $i) * 3600));
        $paths[] = $p;
    }
    return $paths;
};

echo "A. the rotation keeps the configured number, newest first\n";
$seeded = $seed(20);
$newest = end($seeded);
$manager = $makeManager($tmp, '10');
$prune($manager, $newest);
$remaining = glob($tmp . '/backup_*.zip') ?: [];
$check(count($remaining) === 10, 'exactly ten automatic backups survive (got ' . count($remaining) . ')');
$check(in_array($newest, $remaining, true), 'the backup just written is never rotated away');
// The seeds were written oldest-first, so the survivors must be exactly the
// last ten of that list — the rotation must drop by age, not by name or order
// returned by glob().
$expectedSurvivors = array_slice($seeded, -10);
sort($expectedSurvivors);
$actualSurvivors = $remaining;
sort($actualSurvivors);
$check($actualSurvivors === $expectedSurvivors, 'the survivors are exactly the ten most recent, by age');
$check(count($seeded) - count($remaining) === 10, 'the ten oldest were removed');

foreach (glob($tmp . '/*') ?: [] as $f) {
    @unlink($f);
}

echo "B. hand-placed files are never touched\n";
$seeded = $seed(15);
$newest = end($seeded);
$manual = $tmp . '/before-the-big-migration.zip';
file_put_contents($manual, 'x');
touch($manual, time() - 999999);
// The dangerous case: a hand-made archive that DOES carry the generated
// prefix. Matching on `backup_*` alone would delete it.
$manualPrefixed = $tmp . '/backup_migrazione.zip';
file_put_contents($manualPrefixed, 'x');
touch($manualPrefixed, time() - 999999);
$manualDated = $tmp . '/backup_2026-01-01_000000_prima-del-restauro.zip';
file_put_contents($manualDated, 'x');
touch($manualDated, time() - 999999);
$notes = $tmp . '/README.txt';
file_put_contents($notes, 'x');
$manager = $makeManager($tmp, '5');
$prune($manager, $newest);
$check(is_file($manual), 'an administrator archive with a custom name survives the rotation');
$check(is_file($manualPrefixed), 'a hand-made archive carrying the backup_ prefix survives too');
$check(is_file($manualDated), 'a hand-made archive that mimics the date form but not the suffix survives');
$check(is_file($notes), 'a non-backup file in the directory is left alone');
$generated = array_filter(glob($tmp . '/backup_*.zip') ?: [], static fn(string $f): bool =>
    preg_match('/^backup_\\d{4}-\\d{2}-\\d{2}_\\d{6}_[0-9a-f]{6}\\.zip$/', basename($f)) === 1);
$check(count($generated) === 5, 'only the generated backups are rotated (kept ' . count($generated) . ')');

foreach (glob($tmp . '/*') ?: [] as $f) {
    @unlink($f);
}

echo "C. retention_count = 0 disables the rotation\n";
$seeded = $seed(12);
$manager = $makeManager($tmp, '0');
$prune($manager, end($seeded));
$check(count(glob($tmp . '/backup_*.zip') ?: []) === 12, 'nothing is deleted when the rotation is switched off');

foreach (glob($tmp . '/*') ?: [] as $f) {
    @unlink($f);
}

echo "D. a missing setting falls back to the default\n";
$seeded = $seed(20);
$manager = $makeManager($tmp, null);
$prune($manager, end($seeded));
$kept = count(glob($tmp . '/backup_*.zip') ?: []);
$check($kept === BackupManager::DEFAULT_RETENTION,
    'the default retention applies when the setting cannot be read (kept ' . $kept . ')');

$cleanup();
echo PHP_EOL . "Passed: {$passed}   Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

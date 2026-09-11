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
        if (is_dir($f)) {
            foreach (glob($f . '/*') ?: [] as $inner) {
                @unlink($inner);
            }
            @rmdir($f);
            continue;
        }
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

echo "E. the legacy directory format is rotated too\n";
// Pre-0.7.x updates left a directory holding a single database.sql. Nothing
// creates them any more, but listBackups() still shows them as backups — and the
// rotation only ever globbed backup_*.zip, so they accumulated forever. One
// production install had 60 of them, 17 MB, spanning six months.
foreach (glob($tmp . '/*') ?: [] as $f) {
    if (is_dir($f)) {
        foreach (glob($f . '/*') ?: [] as $inner) { @unlink($inner); }
        @rmdir($f);
    } else {
        @unlink($f);
    }
}

/** Seed n legacy update_ directories, oldest first. */
$seedLegacy = static function (int $n) use ($tmp): array {
    $paths = [];
    for ($i = 0; $i < $n; $i++) {
        $d = $tmp . '/update_2025-12-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . '_000000';
        @mkdir($d, 0775, true);
        file_put_contents($d . '/database.sql', 'x');
        touch($d, time() - ((100 - $i) * 3600));
        $paths[] = $d;
    }
    return $paths;
};

$legacy = $seedLegacy(8);
$zips = $seed(4);
$newest = end($zips);
$manager = $makeManager($tmp, '5');
$prune($manager, $newest);

$survivingZips = glob($tmp . '/backup_*.zip') ?: [];
$survivingDirs = glob($tmp . '/update_*', GLOB_ONLYDIR) ?: [];
$check(count($survivingZips) + count($survivingDirs) === 5,
    'both formats share one retention pool (got ' . count($survivingZips) . ' zip + ' . count($survivingDirs) . ' dir)');
$check(in_array($newest, $survivingZips, true), 'the backup just written still survives');
// The zips were seeded newer than every legacy directory, so with five slots the
// four zips plus the single newest directory must be what remains.
$check(count($survivingZips) === 4, 'the four recent archives all survive');
$check($survivingDirs === [end($legacy)], 'only the newest legacy directory survives, by age');
$check(!is_dir($legacy[0]), 'a rotated legacy directory is removed with its contents');

echo "F. hand-placed directories are never touched\n";
foreach (glob($tmp . '/*') ?: [] as $f) {
    if (is_dir($f)) {
        foreach (glob($f . '/*') ?: [] as $inner) { @unlink($inner); }
        @rmdir($f);
    } else {
        @unlink($f);
    }
}
$seedLegacy(6);
// Same discipline as the archive pattern: only the EXACT generated shape is a
// candidate. A directory an operator parked here by hand must survive whatever
// the retention says.
$manual = $tmp . '/update_migrazione_manuale';
@mkdir($manual, 0775, true);
file_put_contents($manual . '/database.sql', 'x');
touch($manual, time() - (999 * 3600)); // older than every seeded one
$zips = $seed(2);
$manager = $makeManager($tmp, '2');
$prune($manager, end($zips));
$check(is_dir($manual), 'a hand-named directory is never a rotation candidate');
$check(is_file($manual . '/database.sql'), 'and its contents are left alone');

echo "G. a backup the operator asked for is never rotated away\n";
// The manual button and the automatic pre-update copy used to produce identical
// names, so ten automatic backups would evict a restore point someone created on
// purpose — usually right before doing something risky. The origin now lives in
// the filename, which is what the rotation can see from a glob.
foreach (glob($tmp . '/*') ?: [] as $f) {
    if (is_dir($f)) {
        foreach (glob($f . '/*') ?: [] as $inner) { @unlink($inner); }
        @rmdir($f);
    } else {
        @unlink($f);
    }
}

$nameFor = new ReflectionMethod(BackupManager::class, 'backupFileName');
$nameFor->setAccessible(true);

$manual = $tmp . '/' . $nameFor->invoke(null, '2025-01-01_000000', BackupManager::ORIGIN_MANUAL);
$safety = $tmp . '/' . $nameFor->invoke(null, '2025-01-02_000000', BackupManager::ORIGIN_SAFETY);
foreach ([$manual, $safety] as $i => $path) {
    file_put_contents($path, 'x');
    touch($path, time() - ((900 - $i) * 3600)); // older than every automatic one
}

$autos = $seed(12);
$manager = $makeManager($tmp, '3');
$prune($manager, end($autos));

$check(is_file($manual), 'a backup created from the button survives the rotation');
$check(is_file($safety), 'the safety copy taken before a restore survives too');
$survivingAuto = array_values(array_filter(glob($tmp . '/backup_*.zip') ?: [],
    static fn(string $f): bool => !str_contains($f, '_manual.') && !str_contains($f, '_safety.')));
$check(count($survivingAuto) === 3,
    'the automatic ones are still rotated to the configured count (got ' . count($survivingAuto) . ')');
// And they must not consume the quota either: the retention counts automatic
// copies, so a hoard of manual ones cannot starve the rolling window.
$check(count($survivingAuto) === 3 && is_file($manual) && is_file($safety),
    'preserved backups do not consume rotation slots');

echo "H. the origin survives a round trip through the list\n";
$listed = $manager->listBackups();
$byName = [];
foreach ($listed as $row) {
    $byName[$row['name']] = $row;
}
$check(($byName[basename($manual)]['origin'] ?? null) === BackupManager::ORIGIN_MANUAL,
    'the list reports a manual backup as manual');
$check(($byName[basename($safety)]['origin'] ?? null) === BackupManager::ORIGIN_SAFETY,
    'the list reports the safety copy as such');
$anAuto = basename((string) end($survivingAuto));
$check(($byName[$anAuto]['origin'] ?? null) === BackupManager::ORIGIN_AUTO,
    'an archive with no origin recorded reads as automatic, which is what it was');
// The suffix must not leak into the date shown to the operator.
$check(!str_contains((string) ($byName[basename($manual)]['date'] ?? ''), 'manual'),
    'the origin suffix stays out of the displayed date');

echo "I. a directory wearing the name but not the content is left alone\n";
foreach (glob($tmp . '/*') ?: [] as $f) {
    if (is_dir($f)) {
        foreach (glob($f . '/*') ?: [] as $inner) { @unlink($inner); }
        @rmdir($f);
    } else {
        @unlink($f);
    }
}
// A generated legacy backup IS its database.sql. A directory that matches the
// name but has no dump is something else wearing our shape — and this rotation
// deletes recursively, so getting it wrong destroys whatever is inside.
$impostor = $tmp . '/update_2019-03-03_030303';
@mkdir($impostor, 0775, true);
file_put_contents($impostor . '/note.txt', 'roba mia');
touch($impostor, time() - (999 * 3600)); // oldest of all: first to go if eligible
$seedLegacy(4);
$zips = $seed(2);
$manager = $makeManager($tmp, '2');
$prune($manager, end($zips));
$check(is_dir($impostor), 'a legacy-named directory without database.sql is not a rotation candidate');
$check(is_file($impostor . '/note.txt'), 'and whatever it contained is still there');

echo "J. a newly written preserved backup does not consume automatic slots\n";
foreach ([BackupManager::ORIGIN_MANUAL, BackupManager::ORIGIN_SAFETY, BackupManager::ORIGIN_UPLOAD] as $origin) {
    $originDir = $tmp . '/origin_' . $origin;
    mkdir($originDir);
    $autos = [];
    for ($i = 1; $i <= 3; $i++) {
        $auto = $originDir . '/' . $nameFor->invoke(null, '2026-01-0' . $i . '_000000', BackupManager::ORIGIN_AUTO);
        file_put_contents($auto, 'x');
        touch($auto, time() - (4 - $i) * 3600);
        $autos[] = $auto;
    }
    $preserved = $originDir . '/' . $nameFor->invoke(null, '2026-02-01_000000', $origin);
    file_put_contents($preserved, 'x');
    $manager = $makeManager($originDir, '1');
    $prune($manager, $preserved);
    $check(is_file(end($autos)), $origin . ': the newest automatic backup survives at retention 1');
    $check(!is_file($autos[0]) && !is_file($autos[1]), $origin . ': older automatic backups still rotate');
    $check(is_file($preserved), $origin . ': the newly written preserved backup survives');
}

// Teardown belongs at the END, after the last section. It restores the SHARED
// system_settings.retention_count that $makeManager() overwrites — leave it and
// the next suite to run rotates at whatever number this file last set.
$cleanup();

echo PHP_EOL . "Passed: {$passed}   Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

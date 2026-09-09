<?php
declare(strict_types=1);

/**
 * Space preflight and copy-failure diagnosis (Updater), issue #422.
 *
 * An update that ran out of disk used to fail mid-copy with the name of
 * whichever file the loop had reached — a path inside vendor/ that says nothing
 * about the cause and made a healthy release look corrupt. Two changes fix
 * that: refuse before touching anything when the space is not there, and, when
 * a copy does fail, say WHY.
 *
 * Covered against the REAL class:
 *  - the estimate is driven by the same directory list the rollback backup
 *    copies, so the two can never disagree;
 *  - the write probe answers false when the requested size cannot be written;
 *  - a copy failure is described by cause (unwritable target, missing
 *    directory, unwritable directory) rather than by file name alone;
 *  - the preflight passes on a healthy installation, so it cannot become a
 *    gate that blocks legitimate updates.
 *
 * Run: php tests/update-space-preflight.unit.php
 */

use App\Support\Updater;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$call = static function (Updater $u, string $method, array $args = []) {
    $ref = new ReflectionMethod(Updater::class, $method);
    $ref->setAccessible(true);
    return $ref->invoke($u, ...$args);
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
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — Updater needs a connection: {$e->getMessage()}\n");
    exit(1);
}

$updater = new Updater($db, $root);

echo "A. the estimate follows the directories the rollback backup copies\n";
$dirsRef = new ReflectionClassConstant(Updater::class, 'APP_BACKUP_DIRS');
$dirs = $dirsRef->getValue();
$check(is_array($dirs) && $dirs !== [], 'the backup directory list is a shared constant');
$sum = 0;
foreach ($dirs as $dir) {
    $sum += (int) $call($updater, 'directorySize', [$root . '/' . $dir]);
}
$estimate = (int) $call($updater, 'estimateUpdateSpace');
$check($sum > 0, 'the measured directories are not empty on this checkout');
$check($estimate > $sum, 'the estimate adds a margin over the raw size');
$check($estimate < $sum * 2, 'the margin stays proportionate (no runaway over-estimate)');

// The guarantee that matters: a directory added to the backup must show up in
// the estimate by construction. Reading the same constant in both places is
// what provides it, so assert the backup really uses it.
$src = (string) file_get_contents($root . '/app/Support/Updater.php');
$check(substr_count($src, 'self::APP_BACKUP_DIRS') >= 3,
    'backup, restore and estimate all read the same constant');

echo "B. the write probe reflects what can actually be written\n";
$check($call($updater, 'canWriteBytes', [1024]) === true, 'a small write succeeds on a healthy install');
// An absurd request must be refused rather than filling the disk trying.
$huge = PHP_INT_MAX;
$freeBytes = @disk_free_space($root);
if (is_float($freeBytes) && $freeBytes > 0) {
    $check($call($updater, 'canWriteBytes', [(int) $freeBytes + (1024 * 1024 * 1024)]) === false,
        'a request larger than the free space is refused');
} else {
    echo "  SKIP disk_free_space() unavailable on this filesystem\n";
}

echo "C. a copy failure is described by cause, not just by file name\n";
$tmp = sys_get_temp_dir() . '/zz_preflight_' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);

$missingDir = $tmp . '/nope/deeper/file.txt';
$reason = (string) $call($updater, 'describeWriteFailure', [$missingDir]);
$check(str_contains($reason, 'directory') && str_contains($reason, 'esiste'),
    'a missing destination directory is named as such (' . $reason . ')');

$roFile = $tmp . '/readonly.txt';
file_put_contents($roFile, 'x');
chmod($roFile, 0444);
$reason = (string) $call($updater, 'describeWriteFailure', [$roFile]);
$check(str_contains($reason, 'non è scrivibile'), 'an unwritable target file is named as such (' . $reason . ')');
chmod($roFile, 0644);

$roDir = $tmp . '/rodir';
mkdir($roDir, 0555, true);
$reason = (string) $call($updater, 'describeWriteFailure', [$roDir . '/new.txt']);
$check(str_contains($reason, 'directory') && str_contains($reason, 'scrivibile'),
    'an unwritable destination directory is named as such (' . $reason . ')');
chmod($roDir, 0755);

// The message must never be empty: an empty reason is the original defect.
$reason = (string) $call($updater, 'describeWriteFailure', [$tmp . '/plain.txt']);
$check(trim($reason) !== '', 'a cause is always reported, even when nothing obvious is wrong');

foreach (glob($tmp . '/*') ?: [] as $f) {
    is_dir($f) ? @rmdir($f) : @unlink($f);
}
@rmdir($tmp);

echo "D. the preflight does not block a healthy installation\n";
$verdict = $call($updater, 'checkFreeSpaceForUpdate');
$check($verdict === null, 'this checkout passes the preflight (' . var_export($verdict, true) . ')');
$check(count(glob($root . '/storage/tmp/.space_probe_*') ?: []) === 0, 'the probe file is removed after the check');

echo PHP_EOL . "Passed: {$passed}   Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

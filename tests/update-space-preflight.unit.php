<?php
declare(strict_types=1);

/**
 * Space preflight and copy-failure diagnosis (Updater), issue #422.
 *
 * An update that ran out of disk used to fail mid-copy with the name of
 * whichever file the loop had reached — a path inside vendor/ that says nothing
 * about the cause and made a healthy release look corrupt. Two changes fix
 * that: refuse before touching anything when the space is not there, and, when
 * a write does fail, say WHY.
 *
 * Covered against the REAL class:
 *  - the estimate is driven by the same directory list the rollback backup
 *    copies, so the two can never disagree;
 *  - the write probe is BOUNDED BY CONSTRUCTION: an impossible request is
 *    refused from disk_free_space() in microseconds instead of being disproven
 *    by filling the volume, and it leaves no probe file behind;
 *  - the probe proves the WHOLE requirement, not a 16 MB constant — an account
 *    with room for a small file but not for the update must be refused;
 *  - the probe is TRI-STATE: "the probe could not be created" is not reported
 *    as "the disk is full";
 *  - a write failure is described by cause (unwritable target, missing
 *    directory, exhausted space) with the cheap, specific checks running first,
 *    and the PHP error it falls back to is the caller's, not one the diagnosis
 *    produced itself;
 *  - the gate runs in both update entry points BEFORE the first byte is
 *    written, and is kept in installUpdate() where it re-measures;
 *  - copyDirectory() — not just the helper in isolation — reports a cause;
 *  - scripts/manual-upgrade.php, the fallback the updater's own message
 *    recommends, has the same probe and the same cause-naming;
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
$src = (string) file_get_contents($root . '/app/Support/Updater.php');

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
$check(substr_count($src, 'self::APP_BACKUP_DIRS') >= 3,
    'backup, restore and estimate all read the same constant');

echo "B. the write probe is bounded by construction and proves the real requirement\n";
$check($call($updater, 'probeWrite', [1024]) === '', 'a small write succeeds on a healthy install');
// One primitive, three answers. The bool it replaced could not tell "the probe
// could not be created" from "the write ran out of room", and every downstream
// misreport traced back to that collapse.
$check(!str_contains($src, 'function canWriteBytes'),
    'the lossy bool probe is gone; probeWrite() is the single primitive');

// An impossible request must be REFUSED, not disproven by filling the volume.
// Before this was bounded, the probe wrote 1 MB chunks until ENOSPC — on a dev
// box that is the documented trigger for a MySQL abort, and in CI it starves
// the database service the next gate depends on.
$freeBytes = @disk_free_space($root);
$freeBefore = is_float($freeBytes) && $freeBytes > 0 ? $freeBytes : null;
$impossible = $freeBefore !== null
    ? (int) $freeBefore + (1024 * 1024 * 1024)
    : 1024 * 1024 * 1024 * 1024; // 1 TiB: larger than any volume this runs on
$t0 = microtime(true);
$verdict = $call($updater, 'probeWrite', [$impossible]);
$elapsed = microtime(true) - $t0;
$check($elapsed < 5.0, sprintf('an impossible request is answered in %.3fs, without filling the disk', $elapsed));
$check(count(glob($root . '/storage/tmp/.space_probe_*') ?: []) === 0, 'no probe file survives a refused probe');
if ($freeBefore !== null) {
    $check($verdict === 'nospace', 'a request larger than the free space is refused');
    $freeAfter = @disk_free_space($root);
    $delta = is_float($freeAfter) ? $freeBefore - $freeAfter : 0.0;
    $check($delta < 64 * 1024 * 1024,
        sprintf('free space is essentially unchanged by the refusal (%s consumed)', number_format($delta)));
} else {
    // disk_free_space() unreadable: the probe must degrade to a bounded write
    // rather than refusing a legitimate update, so only the bound is assertable.
    $check(in_array($verdict, ['', 'nospace', 'unavailable'], true),
        'with free space unreadable the probe still answers a bounded verdict');
}

// The cap used to live in the CALLER: the probe answered "can a small file be
// written", never "can the update fit". An account with 50 MB of headroom
// facing a 120 MB update passed both checks and died mid-copy anyway.
$check(!str_contains($src, 'min($needed'), 'the gate no longer caps the probe below the requirement');
$check($call($updater, 'probeWrite', [$estimate]) === '',
    'the full estimate can be proven on this checkout (' . number_format($estimate) . ' bytes)');
$check(count(glob($root . '/storage/tmp/.space_probe_*') ?: []) === 0, 'no probe file survives a full-size probe');

// Tri-state: "the probe could not be created" must not be reported as "no
// space". Built with a FILE where storage/tmp belongs, so mkdir cannot succeed
// even for uid 0 — a chmod-based precondition inverts under root.
$bareRef = new ReflectionClass(Updater::class);
$bare = $bareRef->newInstanceWithoutConstructor();
$rootProp = $bareRef->getProperty('rootPath');
$rootProp->setAccessible(true);
$blockedRoot = sys_get_temp_dir() . '/zz_preflight_blocked_' . bin2hex(random_bytes(4));
mkdir($blockedRoot . '/storage', 0777, true);
file_put_contents($blockedRoot . '/storage/tmp', 'not a directory');
$rootProp->setValue($bare, $blockedRoot);
$check($call($bare, 'probeWrite', [1024]) === 'unavailable',
    'an uncreatable working directory answers "unavailable", not "nospace"');
$blockedTarget = $blockedRoot . '/target.txt';
$reason = (string) $call($bare, 'describeWriteFailure', [$blockedTarget]);
$check(!str_contains($reason, 'spazio') && !str_contains($reason, 'quota'),
    'an unusable probe is never reported as exhausted space (' . $reason . ')');
@unlink($blockedRoot . '/storage/tmp');
@rmdir($blockedRoot . '/storage');
@rmdir($blockedRoot);

echo "C. a write failure is described by cause, not just by file name\n";
$tmp = sys_get_temp_dir() . '/zz_preflight_' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);

$missingDir = $tmp . '/nope/deeper/file.txt';
$reason = (string) $call($updater, 'describeWriteFailure', [$missingDir]);
$check(str_contains($reason, 'directory') && str_contains($reason, 'esiste'),
    'a missing destination directory is named as such (' . $reason . ')');

// chmod() manufactures the precondition only where the OS enforces mode bits
// against the running user — is_writable() returns true for uid 0 on both a
// 0444 file and a 0555 directory. Assert what the function SHOULD say given the
// state that actually obtains, so a root run neither fails falsely nor passes
// while asserting nothing.
$roFile = $tmp . '/readonly.txt';
file_put_contents($roFile, 'x');
chmod($roFile, 0444);
$enforced = !is_writable($roFile);
$reason = (string) $call($updater, 'describeWriteFailure', [$roFile]);
if ($enforced) {
    $check(str_contains($reason, 'non è scrivibile'), 'an unwritable target file is named as such (' . $reason . ')');
} else {
    $check(trim($reason) !== '',
        'mode bits not enforced for this user (root?): a cause is still reported (' . $reason . ')');
}
chmod($roFile, 0644);

$roDir = $tmp . '/rodir';
mkdir($roDir, 0555, true);
$enforced = !is_writable($roDir);
$reason = (string) $call($updater, 'describeWriteFailure', [$roDir . '/new.txt']);
if ($enforced) {
    $check(str_contains($reason, 'directory') && str_contains($reason, 'scrivibile'),
        'an unwritable destination directory is named as such (' . $reason . ')');
} else {
    $check(trim($reason) !== '',
        'mode bits not enforced for this user (root?): a cause is still reported (' . $reason . ')');
}
chmod($roDir, 0755);

// The fallback must report the CALLER's error. error_get_last() is
// process-global: a diagnosis that probes before reading it ends up reporting
// whatever its own I/O last produced.
@copy($tmp . '/there-is-no-such-source', $tmp . '/dest.txt');
$reason = (string) $call($updater, 'describeWriteFailure', [$tmp . '/dest.txt']);
$check(str_contains($reason, 'copy('),
    'the fallback reports the failing copy, not an error the diagnosis produced (' . $reason . ')');

// The message must never be empty: an empty reason is the original defect.
$reason = (string) $call($updater, 'describeWriteFailure', [$tmp . '/plain.txt']);
$check(trim($reason) !== '', 'a cause is always reported, even when nothing obvious is wrong');

// Same rule, enforced across every call site rather than one at a time. The
// diagnosis has to be the first thing its failure branch does: error_get_last()
// is process-global, so anything performing I/O ahead of it — debugLog(), which
// ends in a file write, or ZipArchive::close(), which flushes — replaces the very
// error the diagnosis exists to report. When this shipped the rule was honoured at
// two call sites and broken at two others; without a gate the next site added
// picks whichever neighbour it happens to copy.
$updaterLines = preg_split('/\r?\n/', $src) ?: [];
$outOfOrder = [];
foreach ($updaterLines as $i => $line) {
    if (!str_contains($line, '$this->describeWriteFailure(')) {
        continue;
    }
    // Walk back to the line opening the enclosing branch.
    $start = $i;
    for ($j = $i - 1; $j >= 0 && ($i - $j) < 40; $j--) {
        $t = trim($updaterLines[$j]);
        if (str_ends_with($t, '{')
            && (str_starts_with($t, 'if (') || str_starts_with($t, '} else') || str_starts_with($t, 'foreach ('))) {
            $start = $j;
            break;
        }
    }
    // Comments are excluded on purpose: the branches that get this right carry a
    // comment explaining WHY they call debugLog() second, and scanning raw text
    // would let that explanation trip the very rule it documents.
    $between = array_filter(
        array_slice($updaterLines, $start + 1, max(0, $i - $start - 1)),
        static function (string $l): bool {
            $t = ltrim($l);
            return $t !== '' && !str_starts_with($t, '//') && !str_starts_with($t, '*') && !str_starts_with($t, '/*');
        }
    );
    $code = implode("\n", $between);
    if (str_contains($code, 'debugLog(') || str_contains($code, '->close()')) {
        $outOfOrder[] = $i + 1;
    }
}
$check($outOfOrder === [],
    'the diagnosis runs before any logging or teardown in every failure branch that uses it'
    . ($outOfOrder === [] ? '' : ' — violated at line(s) ' . implode(', ', $outOfOrder)));

echo "D. copyDirectory itself reports the cause, not only the helper\n";
$copySrc = $tmp . '/pkg';
$copyDst = $tmp . '/live';
mkdir($copySrc, 0777, true);
mkdir($copyDst, 0777, true);
file_put_contents($copySrc . '/nuovo.txt', 'contenuto');
file_put_contents($copyDst . '/nuovo.txt', 'vecchio');
chmod($copyDst . '/nuovo.txt', 0444);
$enforced = !is_writable($copyDst . '/nuovo.txt');
$copyMessage = null;
try {
    $call($updater, 'copyDirectory', [$copySrc, $copyDst]);
} catch (Throwable $e) {
    $copyMessage = $e->getMessage();
}
if ($enforced) {
    $check($copyMessage !== null && str_contains($copyMessage, '—'),
        'a failed copy carries a cause segment (' . (string) $copyMessage . ')');
    $check($copyMessage !== null && str_contains($copyMessage, 'scrivibile'),
        'the cause names the unwritable target, not just the file name');
} else {
    $check($copyMessage === null, 'mode bits not enforced for this user (root?): the copy simply succeeds');
}
chmod($copyDst . '/nuovo.txt', 0644);

// Neither copy engine may keep the bare, causeless message: installUpdate()
// runs one straight after the other and the operator cannot tell them apart.
$check(!str_contains($src, "__('Errore nella copia del file: %s')"),
    'the bare copy-failure message is gone from Updater.php');
$check(preg_match('/if \(!copy\(|if \(!mkdir\(/', $src) === 0,
    'no unsuppressed copy()/mkdir() is left to leak a warning into the response');
$check(substr_count($src, 'describeWriteFailure') >= 6,
    'every write-failure site consults the diagnosis (' . substr_count($src, 'describeWriteFailure') . ' references)');

foreach (glob($tmp . '/*') ?: [] as $f) {
    if (is_dir($f)) {
        foreach (glob($f . '/*') ?: [] as $inner) {
            is_dir($inner) ? @rmdir($inner) : @unlink($inner);
        }
        @rmdir($f);
    } else {
        @unlink($f);
    }
}
@rmdir($tmp);

echo "E. the gate runs before the first byte is written, in BOTH entry points\n";
$bodyOf = static function (string $source, string $signature): string {
    $start = strpos($source, $signature);
    if ($start === false) {
        return '';
    }
    $rest = substr($source, $start + strlen($signature));
    $end = preg_match('/\n    (?:public|protected|private) function /', $rest, $m, PREG_OFFSET_CAPTURE)
        ? (int) $m[0][1]
        : strlen($rest);
    return substr($rest, 0, $end);
};
$check(substr_count($src, 'checkFreeSpaceForUpdate(') >= 4,
    'the gate has a declaration and at least three call sites (' . substr_count($src, 'checkFreeSpaceForUpdate(') . ')');
$check(str_contains($src, 'private function checkFreeSpaceForUpdate(int $extraBytes = 0)'),
    'the gate can be asked for more than the rollback copy');
foreach ([
    'public function performUpdate(string $targetVersion): array',
    'public function performUpdateFromFile(string $uploadTempPath): array',
] as $signature) {
    $body = $bodyOf($src, $signature);
    $gate = strpos($body, 'checkFreeSpaceForUpdate(');
    $backup = strpos($body, '$this->createBackup()');
    $label = trim(explode('(', $signature)[0]);
    $check($body !== '' && $gate !== false && $backup !== false && $gate < $backup,
        $label . ': the space gate precedes createBackup()');
}
// The later gate must NOT be moved: it is the only measurement taken after the
// backup and the download have consumed their share.
$installBody = $bodyOf($src, 'public function installUpdate(string $sourcePath, string $targetVersion): array');
$check(str_contains($installBody, 'checkFreeSpaceForUpdate()'),
    'installUpdate() keeps its own re-measuring gate');

echo "F. the CLI fallback got the same guarantees\n";
$cli = (string) file_get_contents($root . '/scripts/manual-upgrade.php');
$check(str_contains($cli, 'function probeWriteBytes('), 'the standalone script defines its own write probe');
$check(str_contains($cli, 'function describeWriteFailure('), 'the standalone script defines its own diagnosis');
// It must keep working on an installation whose application will not boot:
// the helpers are plain functions and no message goes through translation.
$check(!str_contains($cli, '__('), 'the script stays free of the translation function');
// The script does load the autoloader late, for the post-migration backfills.
// The helpers and every check that uses them must sit BEFORE that point: they
// run on installations where the application itself will not boot.
$autoloadAt = strpos($cli, 'vendor/autoload.php');
// Presence first, THEN order. strpos() returns false when the symbol is absent,
// and (int) false is 0, which compares below every offset — an assertion written
// that way passes precisely when the thing it guards has disappeared.
$probeDefAt  = strpos($cli, 'function probeWriteBytes(');
$diagDefAt   = strpos($cli, 'function describeWriteFailure(');
$probeUseAt  = strrpos($cli, 'probeWriteBytes($rootPath');
$check($autoloadAt !== false
    && $probeDefAt !== false && $probeDefAt < $autoloadAt
    && $diagDefAt !== false && $diagDefAt < $autoloadAt
    && $probeUseAt !== false && $probeUseAt < $autoloadAt,
    'probe and diagnosis are defined and used before any autoloader is required');
$cliLines = preg_split('/\r?\n/', $cli) ?: [];
foreach (['Copia file fallita', 'Backup file critico fallito', 'Impossibile creare directory destinazione'] as $message) {
    $window = '';
    foreach ($cliLines as $i => $text) {
        if (str_contains($text, "'" . $message)) {
            $window = implode("\n", array_slice($cliLines, $i, 5));
            break;
        }
    }
    $check($window !== '' && str_contains($window, 'describeWriteFailure('),
        '"' . $message . '" carries a cause');
}
$freeChecks = 0;
$probedChecks = 0;
foreach ($cliLines as $i => $text) {
    if (!str_contains($text, '@disk_free_space($rootPath)')) {
        continue;
    }
    $freeChecks++;
    if (str_contains(implode("\n", array_slice($cliLines, $i, 20)), 'probeWriteBytes(')) {
        $probedChecks++;
    }
}
$check($freeChecks > 0 && $freeChecks === $probedChecks,
    "every disk_free_space() gate is backed by a real write probe ({$probedChecks}/{$freeChecks})");

echo "G. the preflight does not block a healthy installation\n";
$verdict = $call($updater, 'checkFreeSpaceForUpdate');
$check($verdict === null, 'this checkout passes the preflight (' . var_export($verdict, true) . ')');
if ($freeBefore !== null) {
    $refusal = $call($updater, 'checkFreeSpaceForUpdate', [(int) $freeBefore + (1024 * 1024 * 1024)]);
    $check(is_string($refusal) && $refusal !== '',
        'an update that cannot fit is refused with a message');
} else {
    $check($call($updater, 'checkFreeSpaceForUpdate', [0]) === null,
        'with free space unreadable the gate still lets a healthy install through');
}
$check(count(glob($root . '/storage/tmp/.space_probe_*') ?: []) === 0, 'the probe file is removed after the check');

echo PHP_EOL . "Passed: {$passed}   Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

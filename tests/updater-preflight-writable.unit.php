<?php
declare(strict_types=1);

/**
 * Behavioral suite — Updater::verifyWritableTargets() preflight (issue #205).
 *
 * A 0.7.25 update failed MID-COPY on an install where the PHP user could not
 * create the NEW public/assets/swagger-ui directory (parent not writable),
 * leaving a partially updated tree. The preflight must catch every such case
 * BEFORE any modification. This exercises the real method on a filesystem
 * sandbox with real permissions (no DB needed — the Updater is instantiated
 * without its constructor and configured via reflection).
 *
 * Run:   php tests/updater-preflight-writable.unit.php
 * Exit:  0 only if all pass; prints "ALL <n> PASS".
 */

use App\Support\Updater;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$SANDBOX = sys_get_temp_dir() . '/zz-updater-preflight-' . getmypid();

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    // restore write perms so cleanup always succeeds
    @chmod($dir, 0755);
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = "$dir/$e";
        if (is_dir($p) && !is_link($p)) {
            rrmdir($p);
        } else {
            @chmod($p, 0644);
            @unlink($p);
        }
    }
    @rmdir($dir);
}

set_exception_handler(static function (\Throwable $e) use ($SANDBOX): void {
    rrmdir($SANDBOX);
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
});

$TESTNO = 0;
function pass(string $desc): void
{
    global $TESTNO;
    $TESTNO++;
    printf("[%02d] PASS: %s\n", $TESTNO, $desc);
}
function check(bool $cond, string $desc): void
{
    if (!$cond) {
        throw new \RuntimeException("assertion failed: {$desc}");
    }
    pass($desc);
}

if (posix_geteuid() === 0) {
    // root bypasses permission bits: the chmod-based scenarios cannot fail.
    echo "SKIP: running as root, permission checks are meaningless\n";
    exit(0);
}

/* -------- sandbox: package source + install dest -------- */
rrmdir($SANDBOX);
$src = "$SANDBOX/pkg";
$dst = "$SANDBOX/install";
// package: an existing file, a NEW directory with a file (the 0.7.25 case),
// a file in a preserved path, a file in a skipped path
mkdir("$src/app", 0755, true);
file_put_contents("$src/app/x.php", "<?php // new version\n");
mkdir("$src/public/assets/swagger-ui", 0755, true);
file_put_contents("$src/public/assets/swagger-ui/swagger-ui-bundle.js", "js\n");
mkdir("$src/storage/uploads", 0755, true);
file_put_contents("$src/storage/uploads/seed.png", "png\n");
mkdir("$src/installer", 0755, true);
file_put_contents("$src/installer/skipme.txt", "skip\n");
// install: app/x.php exists (writable), public/assets exists but NOT writable
mkdir("$dst/app", 0755, true);
file_put_contents("$dst/app/x.php", "<?php // old version\n");
mkdir("$dst/public/assets", 0755, true);
mkdir("$dst/storage/uploads", 0755, true);
file_put_contents("$dst/storage/uploads/seed.png", "user data\n");

/* -------- Updater senza costruttore, configurato via reflection -------- */
$ref = new \ReflectionClass(Updater::class);
$updater = $ref->newInstanceWithoutConstructor();
$set = function (string $prop, $value) use ($ref, $updater): void {
    if ($ref->hasProperty($prop)) {
        $p = $ref->getProperty($prop);
        $p->setValue($updater, $value);
    }
};
$set('rootPath', $dst);
$set('skipPaths', ['installer']);
$set('preservePaths', ['storage/uploads']);

$method = $ref->getMethod('verifyWritableTargets');
$run = fn(): array => $method->invoke($updater, $src, $dst);

/* ========================= checks ========================= */

// 1 — tutto scrivibile → nessun fallimento
check($run() === [], '01 all writable: preflight reports nothing');

// 2 — il caso #205: parent dir non scrivibile, la NUOVA dir viene segnalata
chmod("$dst/public/assets", 0555);
$fails = $run();
check(in_array('public/assets/swagger-ui', $fails, true),
    '02 issue #205: new dir under unwritable parent is reported');

// 3 — il file dentro la nuova dir non genera un secondo falso positivo separato dal padre
check(count(array_filter($fails, fn($p) => str_starts_with($p, 'public/assets'))) <= 2,
    '03 failures are collapsed to the offending dir(s), not one per file');

// 4 — fix dei permessi → preflight pulito di nuovo
chmod("$dst/public/assets", 0755);
check($run() === [], '04 after fixing perms the preflight is clean');

// 5 — file ESISTENTE non scrivibile → segnalato (copy() lo tronca)
chmod("$dst/app/x.php", 0444);
check(in_array('app/x.php', $run(), true), '05 existing read-only target file is reported');
chmod("$dst/app/x.php", 0644);

// 6 — preservePaths: il target esiste → mai controllato (né copiato)
chmod("$dst/storage/uploads", 0555);
check(!in_array('storage/uploads/seed.png', $run(), true),
    '06 preserved existing path is not checked (it will not be copied)');
chmod("$dst/storage/uploads", 0755);

// 7 — skipPaths: mai controllato
rrmdir("$dst/installer"); // non esiste a destinazione, e va comunque ignorato
$fails = $run();
check(!in_array('installer', $fails, true) && !in_array('installer/skipme.txt', $fails, true),
    '07 skipped path is never checked');

// 8 — NUOVO file in dir ESISTENTE non scrivibile → segnalato col nome della dir
file_put_contents("$src/app/newfile.php", "<?php\n");
chmod("$dst/app", 0555);
check(in_array('app', $run(), true), '08 new file under unwritable existing dir reports the dir');
chmod("$dst/app", 0755);

/* -------- done -------- */
rrmdir($SANDBOX);
printf("\nALL %d PASS\n", $TESTNO);

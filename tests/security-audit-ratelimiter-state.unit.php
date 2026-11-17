<?php
declare(strict_types=1);

/**
 * Security audit 2026-11 — RateLimiter state must be install-owned.
 *
 * The throttle counters lived in sys_get_temp_dir()/pinakes_ratelimit, named
 * sha256(identifier): shared by every install and co-tenant on the host. A
 * sibling install's reset() cleared this install's lockout, a planted symlink
 * redirected the next write (fopen 'c+' follows links), and a pre-created
 * directory was trusted as-is. State now lives in storage/cache/ratelimit
 * (0700, owned by the PHP user), names mix in the install root, symlinks are
 * refused. An untrusted or unusable directory falls back to a per-install
 * directory under sys_get_temp_dir() with the same checks, and when that fails
 * too the limiter answers "not limited" and logs it once per request: it used
 * to answer 429 to every login with no way back.
 *
 * Touches only <root>/storage/cache/ratelimit (test identifiers are random and
 * removed at the end) and a private temp dir of its own.
 *
 * Run:  php tests/security-audit-ratelimiter-state.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\RateLimiter;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$stateFile = static function (string $id): ?string {
    $m = new ReflectionMethod(RateLimiter::class, 'getStateFile');
    $m->setAccessible(true);
    return $m->invoke(null, $id);
};

$expectedDir = $root . '/storage/cache/ratelimit';
$tag = 'secaudit-rl-' . bin2hex(random_bytes(6));
$ids = [];
$scratch = sys_get_temp_dir() . '/secaudit-rl-' . bin2hex(random_bytes(6));
@mkdir($scratch, 0700, true);
$fallbackExisted = is_dir(RateLimiter::fallbackStateDirectory());

try {
    echo "A. Location and ownership\n";
    $id = $ids[] = "192.0.2.51:{$tag}:login";
    $check(RateLimiter::isLimited($id, 5, 60) === false, 'first attempt is allowed');
    $file = $stateFile($id);
    $check(is_string($file) && str_starts_with($file, $expectedDir . '/'), 'state file lives under storage/cache/ratelimit');
    $check(is_string($file) && is_file($file), 'the counter was written there');
    $legacy = sys_get_temp_dir() . '/pinakes_ratelimit/' . hash('sha256', $id) . '.json';
    $check(!is_file($legacy), 'nothing is written to the host-shared temp dir');
    $check(is_dir($expectedDir) && !is_link($expectedDir), 'the state directory is a real directory');
    $check((fileperms($expectedDir) & 0777) === 0700, 'the state directory is 0700 (got ' . decoct(fileperms($expectedDir) & 0777) . ')');
    if (function_exists('posix_geteuid')) {
        $check(fileowner($expectedDir) === posix_geteuid(), 'the state directory is owned by the PHP user');
    }

    echo "B. File names carry the install identity\n";
    $check(is_string($file) && basename($file) !== hash('sha256', $id) . '.json',
        'name is not sha256(identifier) alone (another install derives a different one)');
    $realRoot = realpath($root) ?: $root;
    $check(is_string($file) && basename($file) === hash('sha256', $realRoot . "\0" . $id) . '.json',
        'name is sha256(install root + NUL + identifier)');

    echo "C. Counting and reset still work\n";
    for ($i = 2; $i <= 5; $i++) {
        RateLimiter::isLimited($id, 5, 60);
    }
    $check(RateLimiter::isLimited($id, 5, 60) === true, 'sixth attempt in the window is limited');
    RateLimiter::reset($id);
    $check(RateLimiter::isLimited($id, 5, 60) === false, 'reset() clears the counter');

    echo "D. A symlink at the state path is never followed\n";
    $id2 = $ids[] = "192.0.2.52:{$tag}:edge";
    $victim = $scratch . '/victim.txt';
    file_put_contents($victim, 'ORIGINAL');
    $file2 = (string) $stateFile($id2);
    @unlink($file2);
    symlink($victim, $file2);
    RateLimiter::isLimited($id2, 5, 60);
    $check(file_get_contents($victim) === 'ORIGINAL', 'the link target was not overwritten');
    $check(!is_link($file2) && is_file($file2), 'the link was replaced by a regular state file');

    echo "E. An untrusted state directory is refused, the temp fallback takes over\n";
    $fallbackDir = RateLimiter::fallbackStateDirectory();
    $realTmp = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    $check(str_starts_with(realpath(dirname($fallbackDir)) ?: '', $realTmp) && str_contains(basename($fallbackDir), 'pinakes_rl_'),
        'the fallback is a per-install directory under sys_get_temp_dir()');
    $id3 = $ids[] = "192.0.2.53:{$tag}:login";
    $moved = $expectedDir . '.secaudit-' . bin2hex(random_bytes(4));
    $check(rename($expectedDir, $moved), 'test setup: move the real state dir aside');
    try {
        $foreign = $scratch . '/foreign-dir';
        mkdir($foreign, 0777);
        chmod($foreign, 0777);
        symlink($foreign, $expectedDir);
        $check(RateLimiter::isLimited($id3, 2, 60) === false, 'a symlinked state directory no longer locks everyone out');
        $check(glob($foreign . '/*') === [], 'nothing was written through the symlinked directory');
        $f3 = (string) $stateFile($id3);
        $check(str_starts_with($f3, $fallbackDir . '/') && is_file($f3), 'the counter went to the fallback directory');
        $check(is_dir($fallbackDir) && !is_link($fallbackDir) && (fileperms($fallbackDir) & 0777) === 0700,
            'the fallback directory is a real 0700 directory');
        RateLimiter::isLimited($id3, 2, 60);
        $check(RateLimiter::isLimited($id3, 2, 60) === true, 'the fallback still counts: the third attempt over a limit of 2 is limited');
        RateLimiter::reset($id3);
        $check(!is_file($f3), 'reset() clears the fallback counter');
        unlink($expectedDir);

        // A directory we cannot write to (chown'ed away, read-only...).
        mkdir($expectedDir, 0500);
        $check(RateLimiter::isLimited($id3, 5, 60) === false, 'an unwritable state directory falls back instead of answering 429');
        $check(str_starts_with((string) $stateFile($id3), $fallbackDir . '/'), '... to the fallback directory');
        chmod($expectedDir, 0700);
        rmdir($expectedDir);

        // A plain file where the directory should be.
        file_put_contents($expectedDir, 'x');
        $check(RateLimiter::isLimited($id3, 5, 60) === false, 'a file in place of the state directory falls back too');
        unlink($expectedDir);
    } finally {
        if (is_link($expectedDir) || is_file($expectedDir)) {
            unlink($expectedDir);
        } elseif (is_dir($expectedDir)) {
            @chmod($expectedDir, 0700);
            @rmdir($expectedDir);
        }
        rename($moved, $expectedDir);
    }
    $check(is_dir($expectedDir) && !is_link($expectedDir), 'test teardown: the real state dir is back');
    $f3b = (string) $stateFile($id3);
    $check(str_starts_with($f3b, $expectedDir . '/'), 'with the real directory back, the counters live there again');

    echo "F. Ownership rules (prepareDirectory)\n";
    $prepare = new ReflectionMethod(RateLimiter::class, 'prepareDirectory');
    $prepare->setAccessible(true);
    $me = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    $other = $me + 4242; // simulate a directory owned by another uid
    $d = $scratch . '/owned';
    mkdir($d, 0770);
    chmod($d, 0770);
    $check($prepare->invoke(null, $d, true, $other) === true,
        'not owned by us, group-shared (0770) and writable by us: accepted (chown deploy:www-data storage)');
    $check((fileperms($d) & 0777) === 0770, '... and its mode is left alone (we do not own it)');
    $check($prepare->invoke(null, $d, false, $other) === false, 'the temp-dir fallback never accepts a directory owned by someone else');
    chmod($d, 0777);
    $check($prepare->invoke(null, $d, true, $other) === false, 'world-writable and owned by someone else: refused');
    $check($prepare->invoke(null, $d, true, $me) === true && (fileperms($d) & 0777) === 0700,
        'world-writable but ours: tightened to 0700 and accepted');
    chmod($d, 0500);
    $check($prepare->invoke(null, $d, true, $me) === false, 'not writable by us: refused');
    chmod($d, 0700);
    rmdir($d);
    $l = $scratch . '/linked';
    mkdir($scratch . '/target', 0700);
    symlink($scratch . '/target', $l);
    $check($prepare->invoke(null, $l, true, $me) === false, 'a symlink is refused even when its target is ours');
    unlink($l);
    rmdir($scratch . '/target');

    echo "G. Nothing usable at all: not limited, logged once per request\n";
    $id4 = "192.0.2.54:{$tag}:login";
    $moved = $expectedDir . '.secaudit-' . bin2hex(random_bytes(4));
    $check(rename($expectedDir, $moved), 'test setup: move the real state dir aside');
    $logFile = $root . '/storage/logs/app.log';
    $countLog = static function () use ($logFile): int {
        return substr_count((string) @file_get_contents($logFile), 'RateLimiter: no usable state directory');
    };
    $before = $countLog();
    try {
        symlink($scratch, $expectedDir);
        $notDir = $scratch . '/not-a-dir';
        file_put_contents($notDir, 'x');
        // A child PHP whose temp dir is a regular file: the fallback cannot be created either.
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';'
            . '$r = []; for ($i = 0; $i < 3; $i++) { $r[] = \App\Support\RateLimiter::isLimited(' . var_export($id4, true) . ', 1, 60) ? "L" : "-"; }'
            . 'echo implode("", $r);';
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d sys_temp_dir=' . escapeshellarg($notDir . '/sub') . ' -r ' . escapeshellarg($code) . ' 2>&1');
        $check(trim($out) === '---', 'three attempts over a limit of 1 are all allowed (got ' . trim($out) . ')');
        $check($countLog() - $before === 1, 'the error is logged once for the whole request (got ' . ($countLog() - $before) . ')');
        $check(glob($scratch . '/*.json') === [], 'nothing was written through the symlinked directory');
    } finally {
        if (is_link($expectedDir)) {
            unlink($expectedDir);
        }
        rename($moved, $expectedDir);
        @unlink($scratch . '/not-a-dir');
    }
} finally {
    foreach ($ids as $cleanupId) {
        RateLimiter::reset($cleanupId);
    }
    foreach (glob($scratch . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (in_array(basename($f), ['.', '..'], true)) {
            continue;
        }
        is_dir($f) && !is_link($f) ? @rmdir($f) : @unlink($f);
    }
    @rmdir($scratch);
    if (!$fallbackExisted) {
        @rmdir(RateLimiter::fallbackStateDirectory()); // only if this run created it (and left it empty)
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

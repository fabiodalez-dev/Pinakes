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
 * refused. An untrusted or unusable directory falls back to a directory under
 * sys_get_temp_dir() named from the install secret, with the same checks; when
 * that fails too, an APCu or in-session counter still limits, and only with
 * none of them does the limiter let requests through (logged once per
 * request). It used to answer 429 to every login with no way back. A
 * foreign-owned directory is only used through a private 0700 subdirectory,
 * and root never writes into a directory owned by another uid.
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

// The temp fallback is named from the install secret; give this run one.
$testAppKey = 'secaudit-rl-key-' . bin2hex(random_bytes(8));
putenv('APP_KEY=' . $testAppKey);
$_ENV['APP_KEY'] = $testAppKey;

$expectedDir = $root . '/storage/cache/ratelimit';
$tag = 'secaudit-rl-' . bin2hex(random_bytes(6));
$ids = [];
$scratch = sys_get_temp_dir() . '/secaudit-rl-' . bin2hex(random_bytes(6));
@mkdir($scratch, 0700, true);
$fallbackExisted = is_dir((string) RateLimiter::fallbackStateDirectory());

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
    $fallbackDir = (string) RateLimiter::fallbackStateDirectory();
    $realTmp = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    $check(str_starts_with(realpath(dirname($fallbackDir)) ?: '', $realTmp) && str_contains(basename($fallbackDir), 'pinakes_rl_'),
        'the fallback is a directory under sys_get_temp_dir()');
    putenv('APP_KEY=another-install-secret');
    $_ENV['APP_KEY'] = 'another-install-secret';
    $otherName = RateLimiter::fallbackStateDirectory();
    putenv('APP_KEY');
    unset($_ENV['APP_KEY']);
    $savedPluginKey = getenv('PLUGIN_ENCRYPTION_KEY');
    putenv('PLUGIN_ENCRYPTION_KEY');
    unset($_ENV['PLUGIN_ENCRYPTION_KEY']);
    $noSecretName = RateLimiter::fallbackStateDirectory();
    putenv('APP_KEY=' . $testAppKey);
    $_ENV['APP_KEY'] = $testAppKey;
    if ($savedPluginKey !== false) {
        putenv('PLUGIN_ENCRYPTION_KEY=' . $savedPluginKey);
    }
    $check($otherName !== null && $otherName !== $fallbackDir,
        'the fallback name depends on the install secret (a co-tenant cannot predict it to squat it)');
    $check($noSecretName === null, 'without any install secret no predictable temp directory is offered');

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
    $other = $me + 4242;
    // Stage "owned by someone else" for listed paths; everything else is real.
    $foreignPaths = [];
    $ownerOf = static function (string $p) use (&$foreignPaths, $other): int|false {
        return in_array($p, $foreignPaths, true) ? $other : @fileowner($p);
    };
    $d = $scratch . '/owned';
    mkdir($d, 0770);
    chmod($d, 0770);
    $foreignPaths = [$d];
    $got = $prepare->invoke(null, $d, true, $me, $ownerOf);
    $check($got === $d . '/u' . $me, 'foreign-owned, group-shared directory (chown deploy:www-data storage): counters go to a private subdirectory');
    $check(is_string($got) && is_dir($got) && !is_link($got) && fileowner($got) === $me && (fileperms($got) & 0777) === 0700,
        '... which is ours and 0700');
    $check((fileperms($d) & 0777) === 0770, '... and the shared parent keeps its mode');
    $check($prepare->invoke(null, $d, false, $me, $ownerOf) === null, 'the temp-dir fallback never accepts a directory owned by someone else');
    $check($prepare->invoke(null, $d, true, 0, $ownerOf) === null, 'root never writes into a directory owned by another uid');
    rmdir($d . '/u' . $me);
    // A co-tenant pre-created our private subdirectory: it is not ours, refused.
    mkdir($d . '/u' . $me, 0700);
    $foreignPaths = [$d, $d . '/u' . $me];
    $check($prepare->invoke(null, $d, true, $me, $ownerOf) === null, 'a private subdirectory created by someone else is refused');
    rmdir($d . '/u' . $me);
    // ... or planted a symlink there.
    mkdir($scratch . '/elsewhere', 0700);
    symlink($scratch . '/elsewhere', $d . '/u' . $me);
    $foreignPaths = [$d];
    $check($prepare->invoke(null, $d, true, $me, $ownerOf) === null, 'a symlink in place of the private subdirectory is refused');
    unlink($d . '/u' . $me);
    rmdir($scratch . '/elsewhere');
    chmod($d, 0777);
    $check($prepare->invoke(null, $d, true, $me, $ownerOf) === null && !is_dir($d . '/u' . $me),
        'world-writable and owned by someone else: refused, nothing created in it');
    $foreignPaths = [];
    $check($prepare->invoke(null, $d, true, $me, $ownerOf) === $d && (fileperms($d) & 0777) === 0700,
        'world-writable but ours: tightened to 0700 and used directly');
    chmod($d, 0500);
    $check($prepare->invoke(null, $d, true, $me, $ownerOf) === null, 'not writable by us: refused');
    chmod($d, 0700);
    rmdir($d);
    $l = $scratch . '/linked';
    mkdir($scratch . '/target', 0700);
    symlink($scratch . '/target', $l);
    $check($prepare->invoke(null, $l, true, $me, $ownerOf) === null, 'a symlink is refused even when its target is ours');
    unlink($l);
    rmdir($scratch . '/target');

    echo "G. No usable directory at all: the degraded limiter still limits\n";
    $id4 = "192.0.2.54:{$tag}:login";
    $moved = $expectedDir . '.secaudit-' . bin2hex(random_bytes(4));
    $check(rename($expectedDir, $moved), 'test setup: move the real state dir aside');
    $logFile = $root . '/storage/logs/app.log';
    $countLog = static function (string $needle) use ($logFile): int {
        return substr_count((string) @file_get_contents($logFile), $needle);
    };
    $notDir = $scratch . '/not-a-dir';
    // A child PHP whose temp dir is a regular file (no fallback either), with or
    // without APCu, with or without a session; prints one char per attempt.
    $runChild = static function (bool $apcu, bool $session) use ($root, $notDir, $id4, $testAppKey): string {
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';'
            . ($session ? 'ini_set("session.use_cookies","0"); session_save_path(' . var_export(dirname($notDir), true) . '); session_start();' : '')
            . '$r = ""; for ($i = 0; $i < 3; $i++) { $r .= \App\Support\RateLimiter::isLimited(' . var_export($id4, true) . ', 1, 60) ? "L" : "-"; }'
            . '\App\Support\RateLimiter::reset(' . var_export($id4, true) . ');'
            . '$r .= \App\Support\RateLimiter::isLimited(' . var_export($id4, true) . ', 1, 60) ? "L" : "-";'
            . 'echo $r;';
        $cmd = 'APP_KEY=' . escapeshellarg($testAppKey) . ' ' . escapeshellarg(PHP_BINARY)
            . ' -d sys_temp_dir=' . escapeshellarg($notDir . '/sub')
            . ' -d apc.enable_cli=' . ($apcu ? '1' : '0')
            . ' -r ' . escapeshellarg($code) . ' 2>&1';
        return trim((string) shell_exec($cmd));
    };
    try {
        symlink($scratch, $expectedDir);
        file_put_contents($notDir, 'x');
        $before = $countLog('RateLimiter: no usable state directory');
        if (function_exists('apcu_enabled')) {
            $check($runChild(true, false) === '-LL-', 'APCu: attempts over the limit are still limited, reset() clears it');
            $check($countLog('RateLimiter: no usable state directory') - $before === 1, 'the degraded mode is logged once for the whole request');
        } else {
            echo "  note: APCu extension not loaded, APCu case not run\n";
        }
        $check($runChild(false, true) === '-LL-', 'no APCu, active session: the in-session counter still limits, reset() clears it');
        $beforeOff = $countLog('no APCu and no session, throttling is off');
        $check($runChild(false, false) === '----', 'nothing available at all: not limited (never a lock-out)');
        $check($countLog('no APCu and no session, throttling is off') - $beforeOff === 1, '... and that is logged once');
        $check(glob($scratch . '/*.json') === [], 'nothing was written through the symlinked directory');
    } finally {
        if (is_link($expectedDir)) {
            unlink($expectedDir);
        }
        rename($moved, $expectedDir);
        @unlink($notDir);
        foreach (glob($scratch . '/sess_*') ?: [] as $sf) {
            @unlink($sf);
        }
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
        @rmdir((string) RateLimiter::fallbackStateDirectory()); // only if this run created it (and left it empty)
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

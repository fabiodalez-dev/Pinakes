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
 * refused, and an untrusted directory still fails CLOSED.
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

    echo "E. An untrusted state directory fails closed\n";
    $id3 = $ids[] = "192.0.2.53:{$tag}:login";
    $moved = $expectedDir . '.secaudit-' . bin2hex(random_bytes(4));
    $check(rename($expectedDir, $moved), 'test setup: move the real state dir aside');
    try {
        $foreign = $scratch . '/foreign-dir';
        mkdir($foreign, 0777);
        symlink($foreign, $expectedDir);
        $check(RateLimiter::isLimited($id3, 5, 60) === true, 'a symlinked state directory makes every attempt limited');
        $check(glob($foreign . '/*') === [], 'nothing was written through the symlinked directory');
    } finally {
        if (is_link($expectedDir)) {
            unlink($expectedDir);
        }
        rename($moved, $expectedDir);
    }
    $check(is_dir($expectedDir) && !is_link($expectedDir), 'test teardown: the real state dir is back');
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
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

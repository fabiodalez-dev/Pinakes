<?php
declare(strict_types=1);

/**
 * Security audit 2026-11 — QueryCache keys must carry the install identity.
 *
 * APCu is one segment per PHP pool, often shared by every site on a host. The
 * storage key used to be 'pinakes_' . prefix . '_' . md5(key), identical in
 * every install, so install B read install A's cached `config_settings_raw`
 * (custom JS echoed raw on public pages, private mode, ...). Keys now mix in an
 * install tag derived from the real app root and APP_KEY, and flush() /
 * clearByPrefix() only touch this install's entries.
 *
 * Two installs are simulated with two APP_KEY values (the root of this checkout
 * is fixed); the shared-segment part runs on real APCu when the CLI has it
 * enabled (re-executing with apc.enable_cli=1 if needed) and is skipped
 * otherwise.
 *
 * Run:  php tests/security-audit-querycache-namespace.unit.php   (exit 0 iff all pass)
 */

if (function_exists('apcu_fetch') && !apcu_enabled() && getenv('QC_NS_APCU_CHILD') !== '1') {
    putenv('QC_NS_APCU_CHILD=1');
    passthru(escapeshellarg(PHP_BINARY) . ' -d apc.enable_cli=1 ' . escapeshellarg(__FILE__), $exitCode);
    exit($exitCode);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\QueryCache;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$hashKey = static function (string $key): string {
    $m = new ReflectionMethod(QueryCache::class, 'hashKey');
    $m->setAccessible(true);
    return (string) $m->invoke(null, $key);
};
$asInstall = static function (string $appKey): void {
    $_ENV['APP_KEY'] = $appKey;
    putenv('APP_KEY=' . $appKey);
};
$originalEnv = $_ENV['APP_KEY'] ?? null;
$originalGetenv = getenv('APP_KEY');

try {
    echo "A. Storage keys are install-scoped\n";
    $asInstall('install-A-' . bin2hex(random_bytes(8)));
    $keyA = $hashKey('config_settings_raw');
    $check($keyA !== 'pinakes_config_settings_raw_' . md5('config_settings_raw'),
        'config_settings_raw no longer maps to the install-independent constant key');
    $check(str_starts_with($keyA, 'pinakes_config_settings_raw_'), 'the readable prefix is kept (prefix scans still match)');
    $check($hashKey('config_settings_raw') === $keyA, 'the key is stable within one install');

    $appKeyB = 'install-B-' . bin2hex(random_bytes(8));
    $asInstall($appKeyB);
    $keyB = $hashKey('config_settings_raw');
    $check($keyB !== $keyA, 'another install derives a different storage key');
    $check(substr($keyA, -16) !== substr($keyB, -16), 'the install tags differ');

    echo "B. Shared APCu segment: no cross-install reads, writes or flushes\n";
    if (!function_exists('apcu_enabled') || !apcu_enabled() || !class_exists(\APCUIterator::class)) {
        echo "  SKIP APCu not available in this CLI\n";
    } else {
        $logical = 'config_settings_raw';
        $asInstall('install-A-shared');
        QueryCache::set($logical, ['advanced.custom_js_essential' => 'alert("A")', 'advanced.private_mode' => '1'], 60);
        $check(QueryCache::get($logical) !== null, 'install A reads back its own settings');

        $asInstall('install-B-shared');
        $check(QueryCache::get($logical) === null, "install B does not read install A's settings");
        QueryCache::set($logical, ['advanced.private_mode' => '0'], 60);
        QueryCache::flush();

        $asInstall('install-A-shared');
        $a = QueryCache::get($logical);
        $check(is_array($a) && ($a['advanced.private_mode'] ?? null) === '1',
            "install B's write and flush() left install A's entry intact");
        QueryCache::delete($logical);
        $asInstall('install-B-shared');
        QueryCache::delete($logical);
    }
} finally {
    if ($originalEnv === null) {
        unset($_ENV['APP_KEY']);
    } else {
        $_ENV['APP_KEY'] = $originalEnv;
    }
    putenv($originalGetenv === false ? 'APP_KEY' : 'APP_KEY=' . $originalGetenv);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

<?php
declare(strict_types=1);

/**
 * APCu-path contract for QueryCache.
 *
 * When the extension is absent (the common CI image), a small in-process APCu
 * double exercises the same public function contract. When APCu is installed
 * but disabled for CLI, the test re-executes itself with apc.enable_cli=1.
 */

if (function_exists('apcu_fetch') && !apcu_enabled() && getenv('QC_APCU_CHILD') !== '1') {
    $command = escapeshellarg(PHP_BINARY)
        . ' -d apc.enable_cli=1 '
        . escapeshellarg(__FILE__);
    putenv('QC_APCU_CHILD=1');
    passthru($command, $exitCode);
    exit($exitCode);
}

if (!function_exists('apcu_fetch')) {
    /** @var array<string, array{value: mixed, expires: int}> */
    $GLOBALS['querycache_apcu_stub'] = [];

    function apcu_enabled(): bool
    {
        return true;
    }

    function apcu_fetch(string $key, ?bool &$success = null): mixed
    {
        $entry = $GLOBALS['querycache_apcu_stub'][$key] ?? null;
        if (!is_array($entry) || ($entry['expires'] !== 0 && $entry['expires'] < time())) {
            unset($GLOBALS['querycache_apcu_stub'][$key]);
            $success = false;
            return false;
        }

        $success = true;
        return $entry['value'];
    }

    function apcu_store(string $key, mixed $value, int $ttl = 0): bool
    {
        $GLOBALS['querycache_apcu_stub'][$key] = [
            'value' => $value,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
        ];
        return true;
    }

    function apcu_add(string $key, mixed $value, int $ttl = 0): bool
    {
        $success = false;
        apcu_fetch($key, $success);
        if ($success) {
            return false;
        }

        return apcu_store($key, $value, $ttl);
    }

    function apcu_delete(string $key): bool
    {
        $exists = array_key_exists($key, $GLOBALS['querycache_apcu_stub']);
        unset($GLOBALS['querycache_apcu_stub'][$key]);
        return $exists;
    }

    function apcu_exists(string $key): bool
    {
        $success = false;
        apcu_fetch($key, $success);
        return $success;
    }

    function apcu_cas(string $key, int $old, int $new): bool
    {
        $success = false;
        $current = apcu_fetch($key, $success);
        if (!$success || $current !== $old) {
            return false;
        }

        return apcu_store($key, $new, 300);
    }
}

use App\Support\ContentCache;
use App\Support\QueryCache;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$pass = 0;
$check = static function (bool $ok, string $label) use (&$pass): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $pass++;
    echo "  OK  {$label}\n";
};

$run = bin2hex(random_bytes(4));
$cacheDir = $root . '/storage/cache';
$createdKeys = [];

try {
    $check(QueryCache::stats()['backend'] === 'apcu', '01 APCu backend is selected');

    $key = "qc_apcu_roundtrip_{$run}";
    $createdKeys[] = $key;
    $check(QueryCache::set($key, 'value', 60), '02 APCu set succeeds');
    $check(QueryCache::get($key) === 'value', '03 APCu get returns the value');
    $prefix = preg_replace('/[^A-Za-z0-9_\-]/', '_', substr($key, 0, 80));
    $check(glob($cacheDir . '/pinakes_' . $prefix . '_*') === [], '04 APCu does not dual-write data to files');

    // Invoke the APCu remember primitive with a value inserted immediately
    // before lock acquisition: its post-acquire double-check must avoid the
    // callback even though the outer logical lookup would previously have missed.
    $reflection = new ReflectionClass(QueryCache::class);
    $hashKey = $reflection->getMethod('hashKey');
    $backendSet = $reflection->getMethod('backendSet');
    $rememberApcu = $reflection->getMethod('rememberWithApcuLock');
    $lateKey = "qc_apcu_late_fill_{$run}";
    $createdKeys[] = $lateKey;
    $hashedLateKey = $hashKey->invoke(null, $lateKey);
    $backendSet->invoke(null, $hashedLateKey, 'filled-by-peer', 60);
    $calls = 0;
    $value = $rememberApcu->invoke(
        null,
        $lateKey,
        $hashedLateKey,
        static function () use (&$calls): string {
            $calls++;
            return 'duplicate-computation';
        },
        60
    );
    $check($value === 'filled-by-peer' && $calls === 0, '05 lock acquisition double-check avoids duplicate computation');

    // Ownership-safe release: an old token must not delete a successor lock.
    $release = $reflection->getMethod('releaseApcuLock');
    $lockKey = 'pinakes_lock_test_' . $run;
    apcu_store($lockKey, 222, 300);
    $release->invoke(null, $lockKey, 111);
    $success = false;
    $owner = apcu_fetch($lockKey, $success);
    $check($success && $owner === 222, '06 stale owner cannot delete successor APCu lock');
    $release->invoke(null, $lockKey, 222);
    $check(!apcu_exists($lockKey), '07 current owner releases its APCu lock');

    // The same generation-capture invariant must hold on APCu.
    $inFlightKey = "home_qc_apcu_inflight_{$run}";
    $createdKeys[] = $inFlightKey;
    QueryCache::remember($inFlightKey, static function (): string {
        ContentCache::homeContentChanged();
        return 'stale';
    }, 300);
    $check(QueryCache::get($inFlightKey) === null, '08 APCu loader cannot publish into a newer generation');

    $before = QueryCache::stats();
    $statsKey = "qc_apcu_stats_{$run}";
    $createdKeys[] = $statsKey;
    QueryCache::remember($statsKey, static fn(): string => 'v', 60);
    $after = QueryCache::stats();
    $check(($after['gets'] - $before['gets']) === 1, '09 APCu remember counts one logical lookup');
    $check(($after['misses'] - $before['misses']) === 1, '10 APCu remember counts one logical miss');
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    exit(1);
} finally {
    foreach ($createdKeys as $createdKey) {
        QueryCache::delete($createdKey);
        $prefix = preg_replace('/[^A-Za-z0-9_\-]/', '_', substr($createdKey, 0, 80));
        foreach (glob($cacheDir . '/pinakes_' . $prefix . '_*') ?: [] as $file) {
            @unlink($file);
        }
    }
}

echo "\n{$pass} checks passed\n";
exit(0);

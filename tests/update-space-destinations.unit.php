<?php
declare(strict_types=1);

// Controlled filesystem measurements; allocations are real but limited to KiB.
// No test fills a filesystem or requires a privileged mount/quota configuration.
namespace App\Support {
    function disk_free_space(string $path): float|false {
        return $GLOBALS['spaceFree'][$path] ?? $GLOBALS['spaceDefaultFree'];
    }
    function stat(string $path): array|false {
        $stat = \stat($path);
        if ($stat !== false) {
            $stat['dev'] = $GLOBALS['spaceDevices'][$path] ?? 100;
        }
        return $stat;
    }
    function fopen(string $path, string $mode) {
        $GLOBALS['spaceOpened'][] = dirname($path);
        return \fopen($path, $mode);
    }
    function fwrite($handle, string $data): int|false {
        $dir = dirname(stream_get_meta_data($handle)['uri']);
        $end = ftell($handle) + strlen($data);
        if ($end > ($GLOBALS['spaceQuota'][$dir] ?? PHP_INT_MAX)) {
            return false;
        }
        $GLOBALS['spaceWritten'] += strlen($data);
        return \fwrite($handle, $data);
    }
}
namespace {
    function __(string $s, ...$args): string { return $args ? sprintf($s, ...$args) : $s; }
    require dirname(__DIR__) . '/app/Support/Updater.php';
    $root = sys_get_temp_dir() . '/space_destinations_' . bin2hex(random_bytes(6));
    foreach (['storage/tmp', 'storage/backups', 'public/assets', 'app', 'package/public/assets'] as $dir) {
        mkdir($root . '/' . $dir, 0775, true);
    }
    file_put_contents($root . '/package/public/assets/new.js', 'new asset');
    $ref = new ReflectionClass(App\Support\Updater::class);
    $updater = $ref->newInstanceWithoutConstructor();
    $ref->getProperty('rootPath')->setValue($updater, $root);
    $ref->getProperty('backupPath')->setValue($updater, $root . '/storage/backups');
    $ref->getProperty('estimateCache')->setValue($updater, 8192);
    $call = static fn(string $method, array $args = []) => $ref->getMethod($method)->invoke($updater, ...$args);
    $reset = static function () use ($ref, $updater): void {
        $GLOBALS['spaceFree'] = $GLOBALS['spaceDevices'] = $GLOBALS['spaceQuota'] = $GLOBALS['spaceOpened'] = [];
        $GLOBALS['spaceDefaultFree'] = 1024.0 * 1024 * 1024;
        $GLOBALS['spaceWritten'] = 0;
        $ref->getProperty('probeVerdicts')->setValue($updater, []);
    };
    $passed = $failed = 0;
    $check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
        echo ($ok ? 'OK ' : 'FAIL ') . $label . "\n";
        $ok ? $passed++ : $failed++;
    };
    $cleanup = static function (string $dir) use (&$cleanup): void {
        foreach (glob($dir . '/*') ?: [] as $p) {
            is_dir($p) ? $cleanup($p) : unlink($p);
        }
        rmdir($dir);
    };
    try {
        $reset();
        $spaceFree[$root] = 0.0;
        $spaceDevices[$root . '/storage/tmp'] = 200;
        $check($call('checkFreeSpaceForUpdate') !== null, 'a full code volume is refused even when tmp has capacity');
        $check($spaceWritten === 0, 'zero free bytes is refused without allocating');

        $reset();
        $spaceFree[$root . '/storage/backups'] = 0.0;
        $spaceDevices[$root . '/storage/backups'] = 200;
        $check($call('checkFreeSpaceForUpdate') !== null, 'a full separate backup volume is refused');

        $reset();
        $spaceFree[$root . '/public/assets'] = 0.0;
        $spaceDevices[$root . '/public/assets'] = 200;
        $check($call('checkFreeSpaceForUpdate', [0, $root . '/package']) !== null,
            'the actual package exposes a full nested destination mount');

        $reset();
        $spaceQuota[$root] = 100 * 1024;
        $error = $call('checkSpaceRequirements', [[$root => 60 * 1024, $root . '/storage/tmp' => 60 * 1024]]);
        $check($error !== null && str_contains($error, 'quota'), 'simultaneous writes share one cumulative quota check');

        $reset();
        $spaceDevices[$root . '/storage/tmp'] = 200;
        $check($call('checkSpaceRequirements', [[$root => 8192, $root . '/storage/tmp' => 8192]]) === null,
            'healthy separate filesystems pass');
        $check(in_array($root, $spaceOpened, true) && in_array($root . '/storage/tmp', $spaceOpened, true),
            'both destination filesystems receive a real write probe');

        $reset();
        $spaceDefaultFree = false;
        $check($call('probeWrite', [32 * 1024 * 1024, true]) === 'unknown', 'an unreadable capacity cannot prove a large request');
        $check($spaceWritten === 0 && $spaceOpened === [], 'unknown large capacity does not start an unbounded allocation');
        $check($call('probeWrite', [4096, true]) === '', 'a small request is still proved completely when the measurement is unavailable');
        $check($spaceWritten === 4096, 'the small probe writes every requested byte');
        $check($call('checkSpaceRequirements', [[$root => 32 * 1024 * 1024]]) !== null,
            'unknown capacity is a preflight error, not success');

        $reset();
        $check($call('checkSpaceRequirements', [[$root . '/new/subdirectory' => 8192]]) === null,
            'new destinations use an existing writable ancestor');
        $check($spaceOpened === [$root], 'the probe is placed where the new directory will allocate');
        $check((glob($root . '/.space_probe_*') ?: []) === [], 'probe files are cleaned up');

        $zipPath = $root . '/payload.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('file.txt', str_repeat('a', 8192));
        $zip->close();
        $zip->open($zipPath);
        $reset();
        $check($call('extractionSpaceError', [$zip, $root . '/storage/tmp']) === null, 'first extraction preflight passes');
        $spaceFree[$root . '/storage/tmp'] = 0.0;
        $check($call('extractionSpaceError', [$zip, $root . '/storage/tmp']) !== null,
            'a repeated extraction check observes capacity consumed since the first check');
        $zip->close();
    } finally {
        $cleanup($root);
    }
    echo "Passed: $passed Failed: $failed\n";
    exit($failed ? 1 : 0);
}

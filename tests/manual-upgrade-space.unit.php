<?php
declare(strict_types=1);

// Load only the standalone helpers, before any CLI/authentication/upgrade code.
// Copying this known section into a temporary namespace lets the real functions
// receive deterministic capacity measurements without mounting a full disk.
$source = (string) file_get_contents(dirname(__DIR__) . '/scripts/manual-upgrade.php');
$start = strpos($source, 'function formatBytes(');
$end = strpos($source, 'function deleteDirectory(');
if ($start === false || $end === false || $end <= $start) {
    throw new RuntimeException('Standalone space helpers are missing');
}
$helpers = substr($source, $start, $end - $start);
$fixture = tempnam(sys_get_temp_dir(), 'cli_space_helpers_');
file_put_contents($fixture, <<<'HEADER'
<?php
namespace CliSpaceFixture;
use RuntimeException;
use Throwable;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;
function disk_free_space($path) { return $GLOBALS['cliFree']; }
function stat($path) {
    $stat = \stat($path);
    if ($stat !== false) { $stat['dev'] = 1; }
    return $stat;
}
HEADER
    . "\n" . $helpers);
$root = sys_get_temp_dir() . '/cli_space_' . bin2hex(random_bytes(6));
mkdir($root . '/storage/tmp', 0775, true);
mkdir($root . '/storage/backups');
$passed = $failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? 'OK ' : 'FAIL ') . $label . "\n";
    $ok ? $passed++ : $failed++;
};
try {
    require $fixture;
    $cliFree = 0.0;
    $check(CliSpaceFixture\probeWriteBytes($root, 4096) === 'nospace', 'zero available space is refused');
    $cliFree = false;
    $check(CliSpaceFixture\probeWriteBytes($root, 200 * 1024 * 1024) === 'unknown', 'unknown capacity cannot prove 200 MiB with a sample');
    $check(CliSpaceFixture\probeWriteBytes($root, 4096) === '', 'small standalone probe verifies its full requirement');
    $cliFree = 300.0 * 1024 * 1024;
    $refused = false;
    try {
        CliSpaceFixture\verifyUpgradeSpace($root, [
            $root . '/storage/tmp' => 200 * 1024 * 1024,
            $root . '/storage/backups' => 250 * 1024 * 1024,
        ]);
    } catch (RuntimeException $e) {
        $refused = str_contains($e->getMessage(), '450 MB');
    }
    $check($refused, '300 MiB cannot hold a 250 MiB dump plus a 200 MiB reserve');
    $check((glob($root . '/storage/tmp/.upgrade_probe_*') ?: []) === [], 'no probe files remain');
} finally {
    unlink($fixture);
    rmdir($root . '/storage/tmp');
    rmdir($root . '/storage/backups');
    rmdir($root . '/storage');
    rmdir($root);
}
echo "Passed: $passed Failed: $failed\n";
exit($failed ? 1 : 0);

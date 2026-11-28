<?php
declare(strict_types=1);

// Load only the standalone helpers, before any CLI/authentication/upgrade code.
// Copying this known section into a temporary namespace lets the real functions
// receive deterministic capacity measurements without mounting a full disk.
$source = (string) file_get_contents(dirname(__DIR__) . '/scripts/manual-upgrade.php');
$start = strpos($source, 'function formatBytes(');
$end = strpos($source, '// LOGIN THROTTLE STATE');
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
// A disk that is full although PHP cannot say so: every write fails.
function fwrite($handle, $data) { return empty($GLOBALS['cliWritesFail']) ? \fwrite($handle, $data) : false; }
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

    // A host where PHP cannot measure free space: without an override the
    // recovery tool would be the second route closed, after the in-app one.
    // The override waives "cannot be measured" only, and says so in the log.
    $cliFree = false;
    $GLOBALS['upgradeAssumeSpace'] = false;
    $GLOBALS['log'] = [];
    $refused = false;
    try {
        CliSpaceFixture\verifyUpgradeSpace($root, [$root . '/storage/tmp' => 200 * 1024 * 1024]);
    } catch (RuntimeException $e) {
        $refused = str_contains($e->getMessage(), 'non verificabile');
    }
    $check($refused, 'unmeasurable space stops the upgrade unless the operator confirms it');
    $GLOBALS['upgradeAssumeSpace'] = true;
    $passedThrough = true;
    try {
        CliSpaceFixture\verifyUpgradeSpace($root, [$root . '/storage/tmp' => 200 * 1024 * 1024]);
    } catch (RuntimeException $e) {
        $passedThrough = false;
    }
    $check($passedThrough, 'with --assume-space-checked an unmeasurable volume no longer blocks');
    $check(count(array_filter($GLOBALS['log'], static fn($line) => str_contains((string) $line, 'verificato a mano'))) === 1,
        'the waived check is written to the upgrade log');
    $cliFree = 1024.0 * 1024;
    $refused = false;
    try {
        CliSpaceFixture\verifyUpgradeSpace($root, [$root . '/storage/tmp' => 200 * 1024 * 1024]);
    } catch (RuntimeException $e) {
        $refused = str_contains($e->getMessage(), 'insufficienti');
    }
    $check($refused, 'the override never waives a volume that is actually full');
    // Unmeasurable AND full: the waiver still writes 16 MiB, which fails, so
    // the upgrade stops before the dump instead of halfway through it.
    $cliFree = false;
    $GLOBALS['cliWritesFail'] = true;
    $GLOBALS['log'] = [];
    $refused = false;
    try {
        CliSpaceFixture\verifyUpgradeSpace($root, [$root . '/storage/tmp' => 200 * 1024 * 1024]);
    } catch (RuntimeException $e) {
        $refused = str_contains($e->getMessage(), 'insufficienti');
    }
    $GLOBALS['cliWritesFail'] = false;
    $check($refused, 'the override still refuses an unmeasurable volume that cannot be written');
    $check($GLOBALS['log'] === [], 'a refused waiver is not logged as verified by hand');
    $check((glob($root . '/storage/tmp/.upgrade_probe_*') ?: []) === [], 'the dense probe leaves no file behind');
    $GLOBALS['upgradeAssumeSpace'] = false;

    $package = $root . '/release';
    $installed = $root . '/installed';
    foreach ([$package, $installed] as $tree) {
        mkdir($tree . '/storage/plugins/desiderata/views', 0775, true);
        mkdir($tree . '/storage/plugins/local-extension');
        file_put_contents($tree . '/storage/plugins/desiderata/views/book-detail.php', $tree);
        file_put_contents($tree . '/storage/plugins/local-extension/plugin.json', $tree);
    }
    $check(CliSpaceFixture\upgradePreservedPluginPaths($package) === ['storage/plugins'], 'an older package without a plugin list preserves the entire plugin directory');
    mkdir($package . '/app/Support', 0775, true);
    file_put_contents($package . '/app/Support/BundledPlugins.php', "<?php throw new \\RuntimeException(); class PackagePlugins { public const LIST = ['desiderata']; }");
    $preserve = CliSpaceFixture\upgradePreservedPluginPaths($package);
    $space = CliSpaceFixture\upgradeCopyRequirements($package, $installed, $preserve);
    $check(isset($space[$installed . '/storage/plugins/desiderata/views'])
        && !isset($space[$installed . '/storage/plugins/local-extension']), 'preflight budgets only for plugins bundled in the incoming release');
    CliSpaceFixture\copyTree($package, $installed, $installed, $preserve);
    $check(file_get_contents($installed . '/storage/plugins/desiderata/views/book-detail.php') === $package, 'the bundled Desiderata view is upgraded');
    $check(file_get_contents($installed . '/storage/plugins/local-extension/plugin.json') === $installed, 'an installed third-party plugin is preserved');
    mkdir($installed . '/storage/plugins/only-installed');
    file_put_contents($installed . '/storage/plugins/only-installed/plugin.json', 'mine');
    file_put_contents($installed . '/storage/plugins/desiderata/views/book-detail.php', 'old');
    CliSpaceFixture\copyTree($package, $installed, $installed, $preserve);
    $check(file_get_contents($installed . '/storage/plugins/only-installed/plugin.json') === 'mine'
        && file_get_contents($installed . '/storage/plugins/desiderata/views/book-detail.php') === $package, 'a plugin the package does not carry is left alone');

    // A bundled plugin kept elsewhere, behind a link (as the whole plugin
    // directory can be): it is preserved, not copied, and the log says so.
    $outside = $root . '/outside';
    mkdir($outside);
    file_put_contents($outside . '/plugin.json', 'linked');
    CliSpaceFixture\deleteDirectory($installed . '/storage/plugins/desiderata');
    symlink($outside, $installed . '/storage/plugins/desiderata');
    $GLOBALS['log'] = [];
    $linkedPreserve = CliSpaceFixture\upgradePreservedPluginPaths($package, $installed);
    $check(in_array('storage/plugins/desiderata', $linkedPreserve, true)
        && in_array('storage/plugins/local-extension', $linkedPreserve, true), 'a bundled plugin whose installed directory is a link is preserved, beside the third-party ones');
    $check(count($GLOBALS['log']) === 1
        && str_starts_with((string) $GLOBALS['log'][0], '[ATTENZIONE] ')
        && str_contains((string) $GLOBALS['log'][0], 'storage/plugins/desiderata'), 'the log names the linked plugin that was not updated');
    $accepted = true;
    try {
        $space = CliSpaceFixture\upgradeCopyRequirements($package, $installed, $linkedPreserve);
        CliSpaceFixture\copyTree($package, $installed, $installed, $linkedPreserve);
    } catch (RuntimeException $e) {
        $accepted = false;
        $space = [];
    }
    $check($accepted && !isset($space[$installed . '/storage/plugins/desiderata/views']), 'the upgrade goes ahead and budgets nothing for the linked plugin');
    $check(is_link($installed . '/storage/plugins/desiderata')
        && (scandir($outside) ?: []) === ['.', '..', 'plugin.json']
        && file_get_contents($outside . '/plugin.json') === 'linked', 'nothing is written through the linked plugin directory, and the link stays');
    // Without the destination the link cannot be seen: the list is the plain
    // one, and both the preflight and the copy itself still refuse the link.
    $GLOBALS['log'] = [];
    $check(CliSpaceFixture\upgradePreservedPluginPaths($package) === $preserve && $GLOBALS['log'] === [], 'without a destination no plugin is reported as linked');
    $refused = false;
    try {
        CliSpaceFixture\upgradeCopyRequirements($package, $installed, $preserve);
    } catch (RuntimeException $e) {
        $refused = str_contains($e->getMessage(), 'simbolico');
    }
    $check($refused, 'the preflight refuses a linked destination that is not preserved, before the copy starts');
    $refused = false;
    try {
        CliSpaceFixture\copyTree($package, $installed, $installed, $preserve);
    } catch (RuntimeException $e) {
        $refused = str_contains($e->getMessage(), 'simbolico');
    }
    $check($refused && (scandir($outside) ?: []) === ['.', '..', 'plugin.json'], 'the copy creates nothing through a linked plugin directory left out of the preserve list');
    unlink($installed . '/storage/plugins/desiderata');

    // The whole plugin directory on another disk, behind a link: left as it is.
    rename($installed . '/storage/plugins', $root . '/plugins-elsewhere');
    symlink($root . '/plugins-elsewhere', $installed . '/storage/plugins');
    $GLOBALS['log'] = [];
    $check(CliSpaceFixture\upgradePreservedPluginPaths($package, $installed) === ['storage/plugins']
        && count($GLOBALS['log']) === 1, 'a linked plugin directory is preserved whole, and the log says the plugins were not updated');
    unlink($installed . '/storage/plugins');
} finally {
    unlink($fixture);
    CliSpaceFixture\deleteDirectory($root);
}
// Early exits (a rejected CSRF token, ...) `goto render`: anything the page prints
// must be computed after that label, or it is undefined on exactly those requests.
$renderAt = strpos($source, "\nrender:\n");
$versionAt = strpos($source, '$currentVersion = \'?\';');
$check($renderAt !== false && $versionAt !== false && $versionAt > $renderAt, 'the installed version is computed after the render label, so early exits still show it');
echo "Passed: $passed Failed: $failed\n";
exit($failed ? 1 : 0);

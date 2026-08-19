<?php
declare(strict_types=1);

/**
 * Release gate for the package contract shared by every shipped plugin.
 *
 * Run: php tests/plugin-package-contract.unit.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$failed = 0;
$passed = 0;
$check = static function (bool $condition, string $label) use (&$failed, &$passed): void {
    if ($condition) {
        $passed++;
        echo "  OK  {$label}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$label}\n";
};

$root = dirname(__DIR__);
$pluginDirs = glob($root . '/storage/plugins/*', GLOB_ONLYDIR) ?: [];
sort($pluginDirs);

echo "Plugin package contract:\n";
foreach ($pluginDirs as $pluginDir) {
    $slug = basename($pluginDir);
    $manifestPath = $pluginDir . '/plugin.json';
    $manifest = is_file($manifestPath)
        ? json_decode((string) file_get_contents($manifestPath), true)
        : null;

    $check(is_array($manifest), "{$slug}: plugin.json is a JSON object");
    if (!is_array($manifest)) {
        continue;
    }

    foreach (['name', 'display_name', 'version', 'main_file'] as $field) {
        $check(
            isset($manifest[$field]) && is_string($manifest[$field]) && trim($manifest[$field]) !== '',
            "{$slug}: {$field} is a non-empty string"
        );
    }
    $check(($manifest['name'] ?? null) === $slug, "{$slug}: manifest name matches its directory");

    $mainFile = (string) ($manifest['main_file'] ?? '');
    $safeMainFile = $mainFile !== ''
        && !str_contains($mainFile, "\0")
        && preg_match('#^(?:[A-Za-z]:)?[/\\\\]#', $mainFile) !== 1
        && !preg_match('#(?:^|[/\\\\])(?:\.|\.\.)(?:[/\\\\]|$)#', $mainFile);
    $check($safeMainFile, "{$slug}: main_file is a relative, traversal-free path");
    $check($safeMainFile && is_file($pluginDir . '/' . $mainFile), "{$slug}: main_file exists");

    $check(
        !isset($manifest['metadata']) || is_array($manifest['metadata']),
        "{$slug}: metadata is an object when present"
    );
}

echo "\nBundled plugin registry:\n";
$bundled = \App\Support\BundledPlugins::LIST;
$known = array_map('basename', $pluginDirs);
foreach ($bundled as $slug) {
    $check(in_array($slug, $known, true), "{$slug}: bundled plugin directory exists");
}

echo "\n================================\n";
echo "Passed: {$passed}   Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);

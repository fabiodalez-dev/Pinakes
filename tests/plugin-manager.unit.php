<?php
declare(strict_types=1);

/**
 * Source-level regression checks for PluginManager lifecycle behavior.
 *
 * Run:
 *   php tests/plugin-manager.unit.php
 */

$failed = 0;
$passed = 0;

$check = static function (bool $cond, string $label) use (&$failed, &$passed): void {
    if ($cond) {
        $passed++;
        echo "  OK  $label\n";
    } else {
        $failed++;
        echo "  FAIL $label\n";
    }
};

$source = file_get_contents(__DIR__ . '/../app/Support/PluginManager.php');

echo "PluginManager bundled upgrade lifecycle:\n";
$check($source !== false && str_contains($source, '$this->instantiatePlugin(['), 'upgrade path instantiates one plugin instance');
$check($source !== false && str_contains($source, '$upgradeInstance->onActivate();'), 'upgrade path reruns onActivate');
$check($source !== false && str_contains($source, 'UPDATE plugins SET version = ? WHERE id = ?'), 'failed upgrade rolls version back');
$check($source !== false && str_contains($source, "bind_param('si', \$dbVersion, \$updId)"), 'rollback restores previous DB version for the same plugin id');
$check($source !== false && str_contains($source, 'Bundled plugin upgrade lifecycle failed'), 'failed lifecycle propagates an explicit error');

echo "\n================================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed > 0 ? 1 : 0);

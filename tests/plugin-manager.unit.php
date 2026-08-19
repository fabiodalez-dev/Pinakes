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
$check($source !== false && str_contains($source, 'onActivate failed during upgrade'), 'failed lifecycle logs an explicit error');

echo "\nPluginManager same-version hook re-sync:\n";
$check($source !== false && str_contains($source, "\$diskVersion === \$dbVersion"), 'same-version branch exists');
$check($source !== false && str_contains($source, '$syncInstance->onActivate();'), 'same-version branch calls onActivate to re-sync hooks');
$hasWarn = $source !== false && str_contains($source, 'Schema/hook self-heal skipped');
$check($hasWarn, 'same-version failure is non-fatal (warning, no rethrow)');

echo "\nPlugin ZIP update lifecycle:\n";
$check($source !== false && str_contains($source, 'updatePluginFromStaging('), 'existing plugin ZIPs use the update path');
$check($source !== false && str_contains($source, 'createPluginStagingDirectory('), 'ZIPs are extracted to a staging directory first');
$check($source !== false && str_contains($source, "rename(\$pluginPath, \$backupPath)"), 'existing package is backed up before replacement');
$check($source !== false && str_contains($source, "rename(\$stagingPath, \$pluginPath)"), 'staging package is atomically promoted');
$check($source !== false && str_contains($source, 'Failed to restore plugin after update rollback'), 'failed update restores the prior package');
$check($source !== false && str_contains($source, 'UPDATE plugins SET display_name'), 'update persists new manifest metadata');
$check($source !== false && str_contains($source, "'updated' => true"), 'update response identifies a successful update');
$check($source !== false && str_contains($source, 'isSafePluginFilePath'), 'manifest main_file is validated against traversal');
$check($source !== false && str_contains($source, '$this->finalizePendingPluginUpdates();'), 'fresh bootstrap finalizes active ZIP updates');
$check($source !== false && str_contains($source, '$instance->onActivate();'), 'fresh bootstrap runs the replacement lifecycle');
$check($source !== false && str_contains($source, '$this->restorePluginHooks($pluginId, $oldHooks);'), 'failed replacement lifecycle restores prior hook rows');
$check($source !== false && str_contains($source, '$this->skipPluginIdsThisRequest[$pluginId] = true;'), 'rolled-back replacement class is skipped for the rest of its PHP request');
$finalizeAt = $source !== false ? strpos($source, '$this->finalizePendingPluginUpdates();') : false;
$maintenanceAt = $source !== false ? strpos($source, '$maintenanceKey =', $finalizeAt === false ? 0 : $finalizeAt) : false;
$check(
    $finalizeAt !== false && $maintenanceAt !== false && $finalizeAt < $maintenanceAt,
    'pending lifecycle completes before maintenance and active hook loading'
);

echo "\n================================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed > 0 ? 1 : 0);

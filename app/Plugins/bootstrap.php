<?php

/**
 * Plugins Bootstrap
 *
 * This file is responsible for loading and activating plugins.
 * It is called early in the application bootstrap process.
 */

use App\Support\PluginManager;

// Create plugin manager instance
$pluginManager = new PluginManager();

// Auto-discover plugins in the Plugins directory
$pluginsDir = __DIR__;
$pluginManager->autoDiscover($pluginsDir);

// Activate all discovered plugins
$pluginManager->activateAll();

// Store plugin manager in global container (if needed)
// You can make it available via dependency injection or as a singleton
if (!isset($GLOBALS['pluginManager'])) {
    $GLOBALS['pluginManager'] = $pluginManager;
}

return $pluginManager;

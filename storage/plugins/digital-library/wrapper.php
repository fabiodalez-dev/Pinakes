<?php
/**
 * Digital Library Plugin Wrapper
 *
 * This file is loaded by the PluginManager to instantiate the plugin.
 */

require_once __DIR__ . '/DigitalLibraryPlugin.php';

// Instantiate plugin with dependencies
$plugin = new DigitalLibraryPlugin($db ?? $GLOBALS['db'] ?? null, $hookManager ?? null);

// Register in global plugins array
$GLOBALS['plugins']['digital-library'] = $plugin;

return $plugin;

<?php

/**
 * Open Library Plugin Activation Script
 *
 * This file can be included in the bootstrap to activate the Open Library plugin
 * without using the database-based PluginManager.
 *
 * Usage:
 *   require __DIR__ . '/app/Plugins/OpenLibrary/activate.php';
 */

use App\Plugins\OpenLibrary\OpenLibraryPlugin;

// Create and activate plugin
$openLibraryPlugin = new OpenLibraryPlugin();
$openLibraryPlugin->activate();

// Optionally store in global scope for debugging
if (!isset($GLOBALS['plugins'])) {
    $GLOBALS['plugins'] = [];
}
$GLOBALS['plugins']['openlibrary'] = $openLibraryPlugin;

// Log activation
error_log('[OpenLibrary] Plugin activated successfully');

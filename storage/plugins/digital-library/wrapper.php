<?php
/**
 * Digital Library Plugin Wrapper
 *
 * This wrapper is loaded by the PluginManager and creates
 * a class that can be instantiated by the PluginManager.
 */

// Load the main plugin file
require_once __DIR__ . '/DigitalLibraryPlugin.php';

// The PluginManager will instantiate this class directly
// No need to create instances here - just load the class definition

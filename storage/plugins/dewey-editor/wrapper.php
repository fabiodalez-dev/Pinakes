<?php
/**
 * Dewey Editor Plugin Wrapper
 *
 * This wrapper allows the plugin to work with the HookManager.
 * The HookManager always loads wrapper.php to initialize plugins.
 */

// Load the main plugin file
require_once __DIR__ . '/DeweyEditorPlugin.php';

// DeweyEditorPlugin is already defined as a global class (no namespace),
// so no alias is needed. The class is ready to use.

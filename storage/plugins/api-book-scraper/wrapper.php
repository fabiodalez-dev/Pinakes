<?php
/**
 * API Book Scraper Plugin Wrapper
 *
 * Questo file viene caricato dal PluginManager per inizializzare il plugin
 */

require_once __DIR__ . '/ApiBookScraperPlugin.php';

// Inizializza il plugin
// NOTA: Usiamo $_pluginInstance per evitare conflitto con variabile $plugin del chiamante (PluginManager)
// PluginManager::instantiatePlugin() includes this file from a method scope in
// which neither $db nor a global $db exists, so the old `$db ?? $GLOBALS['db']`
// raised "Undefined global variable $db" on every plugin load and passed null
// anyway. The instance PluginManager actually uses is the one it builds itself
// right after this include, with the real connection; this one only feeds the
// $GLOBALS['plugins'] registry, which PluginController overwrites with a
// properly built instance before the settings view reads it.
$_pluginInstance = new ApiBookScraperPlugin($db ?? ($GLOBALS['db'] ?? null), $hookManager ?? null);

// Registra il plugin per accesso globale
if (!isset($GLOBALS['plugins'])) {
    $GLOBALS['plugins'] = [];
}
$GLOBALS['plugins']['api-book-scraper'] = $_pluginInstance;

return $_pluginInstance;

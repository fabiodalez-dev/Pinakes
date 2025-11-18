<?php
/**
 * API Book Scraper Plugin Wrapper
 *
 * Questo file viene caricato dal PluginManager per inizializzare il plugin
 */

require_once __DIR__ . '/ApiBookScraperPlugin.php';

// Inizializza il plugin
$plugin = new ApiBookScraperPlugin($db ?? $GLOBALS['db'], $hookManager ?? null);

// Registra il plugin per accesso globale
if (!isset($GLOBALS['plugins'])) {
    $GLOBALS['plugins'] = [];
}
$GLOBALS['plugins']['api-book-scraper'] = $plugin;

return $plugin;

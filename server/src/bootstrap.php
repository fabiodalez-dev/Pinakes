<?php
/**
 * Application Bootstrap
 * Initializes the application environment
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/error.log');

// Ensure log directory exists
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Autoloader
require_once __DIR__ . '/autoloader.php';

// Load configuration
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    Config::load($envFile);
}

// Parse request URI
$requestUri = $_SERVER['REQUEST_URI'];
$scriptName = dirname($_SERVER['SCRIPT_NAME']);

// Remove script name from URI if present
if ($scriptName !== '/' && str_starts_with($requestUri, $scriptName)) {
    $requestUri = substr($requestUri, strlen($scriptName));
}

// Remove query string
$requestUri = strtok($requestUri, '?');
$requestUri = '/' . trim($requestUri, '/');

// Make request URI globally available
$GLOBALS['requestUri'] = $requestUri;

// Initialize scraper registry
$registry = new ScraperRegistry();

// Load scrapers configuration
$scrapersConfigFile = __DIR__ . '/../config/scrapers.php';
if (file_exists($scrapersConfigFile)) {
    $scrapersConfig = require $scrapersConfigFile;

    foreach ($scrapersConfig['scrapers'] as $scraper) {
        $registry->register(
            $scraper['name'],
            $scraper['class'],
            $scraper['priority'] ?? 0,
            $scraper['enabled'] ?? true
        );
    }
}

// Make registry globally available
$GLOBALS['scraperRegistry'] = $registry;

/**
 * Log request helper
 */
function logRequest(string $message, array $context = []): void
{
    $logDir = Config::get('LOG_DIR', __DIR__ . '/../logs');
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . '/access.log';
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' ' . json_encode($context) : '';

    file_put_contents(
        $logFile,
        "[{$timestamp}] {$message}{$contextStr}\n",
        FILE_APPEND
    );
}

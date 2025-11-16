<?php
/**
 * Z39.50/SRU Server Plugin Activation Script
 *
 * This file is executed when the plugin is activated.
 * It sets up the route for the SRU endpoint.
 */

declare(strict_types=1);

// Add route to web.php
$routeFile = __DIR__ . '/../../../app/Routes/web.php';

if (!file_exists($routeFile)) {
    error_log('[Z39 Server Plugin] Route file not found: ' . $routeFile);
    return;
}

// Read current routes
$routeContent = file_get_contents($routeFile);

// Check if route already exists
if (strpos($routeContent, '/api/sru') !== false) {
    error_log('[Z39 Server Plugin] SRU route already exists');
    return;
}

// Find the position to insert the route (before the closing }; of the return function)
// We'll add it before the final line of the file

// Create the route code to add
$sruRoute = <<<'EOT'

    // Z39.50/SRU Server Plugin Endpoint
    $app->get('/api/sru', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $pluginManager = $app->getContainer()->get('pluginManager');
        $plugin = $pluginManager->getPluginByName('z39-server');
        $pluginId = $plugin ? (int)$plugin['id'] : null;

        require __DIR__ . '/../../storage/plugins/z39-server/endpoint.php';
        return handleSRURequest($request, $response, $db, $pluginId);
    });

EOT;

// Find the position before the closing braces
$insertPosition = strrpos($routeContent, '};');

if ($insertPosition === false) {
    error_log('[Z39 Server Plugin] Could not find insertion point in route file');
    return;
}

// Insert the route
$newContent = substr($routeContent, 0, $insertPosition) . $sruRoute . "\n" . substr($routeContent, $insertPosition);

// Write back to file
$success = file_put_contents($routeFile, $newContent);

if ($success) {
    error_log('[Z39 Server Plugin] SRU route successfully added to ' . $routeFile);
} else {
    error_log('[Z39 Server Plugin] Failed to write route to ' . $routeFile);
}

<?php
/**
 * API Book Scraper Server
 * Entry point - Refactored with clean architecture
 *
 * @version 2.0.0
 */

declare(strict_types=1);

// Bootstrap application
require __DIR__ . '/../src/bootstrap.php';

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Create router
$router = new Router();

// Register routes
$router->get('/health', [HealthController::class, 'index']);
$router->get('/api', [ApiController::class, 'index']);
$router->get('/api/books/{isbn}', [ApiController::class, 'getBook']);
$router->get('/api/stats', [StatsController::class, 'index']);
$router->get('/api/stats/keys', [StatsController::class, 'apiKeys']);

// Dispatch request
try {
    $router->dispatch($_SERVER['REQUEST_METHOD'], $GLOBALS['requestUri']);
} catch (PDOException $e) {
    logRequest('Database error', ['error' => $e->getMessage()]);
    Response::serverError('Database error. Please try again later.');
} catch (Exception $e) {
    logRequest('Server error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    Response::serverError('Internal server error.');
}

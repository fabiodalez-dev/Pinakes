<?php
/**
 * API Book Scraper Server
 * Standalone PHP server for book metadata scraping
 */

declare(strict_types=1);

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/error.log');

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $env = parse_ini_file(__DIR__ . '/../.env');
    foreach ($env as $key => $value) {
        $_ENV[$key] = $value;
    }
}

// Autoloader
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
        return;
    }

    // Check in Scrapers subdirectory
    $file = __DIR__ . '/../src/Scrapers/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Parse request
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];
$scriptName = dirname($_SERVER['SCRIPT_NAME']);

// Remove script name from URI if present
if ($scriptName !== '/' && str_starts_with($requestUri, $scriptName)) {
    $requestUri = substr($requestUri, strlen($scriptName));
}

// Remove query string
$requestUri = strtok($requestUri, '?');
$requestUri = '/' . trim($requestUri, '/');

/**
 * Send JSON response
 */
function sendJson(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send error response
 */
function sendError(string $message, int $status = 400): void
{
    sendJson([
        'success' => false,
        'error' => $message,
        'timestamp' => date('c')
    ], $status);
}

/**
 * Get API key from request
 */
function getApiKey(): ?string
{
    // Check Authorization header (Bearer token)
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
        return $matches[1];
    }

    // Check X-API-Key header
    if (!empty($_SERVER['HTTP_X_API_KEY'])) {
        return $_SERVER['HTTP_X_API_KEY'];
    }

    // Check query parameter
    if (!empty($_GET['api_key'])) {
        return $_GET['api_key'];
    }

    return null;
}

/**
 * Authenticate request
 */
function authenticate(): ?array
{
    $apiKey = getApiKey();

    if (!$apiKey) {
        sendError('API key required. Provide via Authorization header, X-API-Key header, or api_key query parameter.', 401);
    }

    $keyData = Database::validateApiKey($apiKey);

    if (!$keyData) {
        sendError('Invalid or inactive API key.', 403);
    }

    return $keyData;
}

/**
 * Log request
 */
function logRequest(string $message, array $context = []): void
{
    $logDir = $_ENV['LOG_DIR'] ?? __DIR__ . '/../logs';
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

// ============================================================================
// Routes
// ============================================================================

try {
    // Health check (no auth required)
    if ($requestUri === '/health' || $requestUri === '/api/health') {
        sendJson([
            'status' => 'ok',
            'timestamp' => date('c'),
            'version' => '1.0.0'
        ]);
    }

    // API documentation (no auth required)
    if ($requestUri === '/' || $requestUri === '/api') {
        sendJson([
            'name' => 'API Book Scraper Server',
            'version' => '1.0.0',
            'endpoints' => [
                'GET /health' => 'Health check',
                'GET /api/books/{isbn}' => 'Get book metadata by ISBN',
                'GET /api/stats' => 'Get usage statistics (authenticated)',
            ],
            'authentication' => 'Required for all endpoints except /health and /. Provide API key via Authorization header (Bearer), X-API-Key header, or api_key query parameter.',
            'documentation' => 'https://github.com/pinakes/api-book-scraper'
        ]);
    }

    // Book lookup endpoint
    if (preg_match('#^/api/books/([0-9]{10,13})$#', $requestUri, $matches)) {
        if ($requestMethod !== 'GET') {
            sendError('Method not allowed. Use GET.', 405);
        }

        $keyData = authenticate();
        $isbn = $matches[1];

        // Rate limiting
        $rateLimit = new RateLimit();
        if (!$rateLimit->isAllowed($keyData['api_key'])) {
            $remaining = $rateLimit->getRemaining($keyData['api_key']);
            sendError('Rate limit exceeded. Try again later.', 429);
        }

        logRequest('Book lookup', ['isbn' => $isbn, 'api_key_id' => $keyData['id']]);

        // Initialize scrapers
        $scrapers = [
            new LibreriaUniversitariaScraper(),
            new FeltrinelliScraper()
        ];

        $startTime = microtime(true);
        $bookData = null;
        $scraperUsed = null;

        // Try each scraper
        foreach ($scrapers as $scraper) {
            try {
                $result = $scraper->scrape($isbn);
                if ($result) {
                    $bookData = $result;
                    $scraperUsed = $scraper->getName();
                    break;
                }
            } catch (Exception $e) {
                logRequest('Scraper error', [
                    'scraper' => $scraper->getName(),
                    'isbn' => $isbn,
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        $responseTime = (int)((microtime(true) - $startTime) * 1000);

        // Log statistics
        if ($_ENV['STATS_ENABLED'] ?? true) {
            Database::logStats(
                $keyData['api_key'],
                $isbn,
                $bookData !== null,
                $scraperUsed,
                $responseTime
            );
        }

        if (!$bookData) {
            sendError('Book not found or could not be scraped.', 404);
        }

        sendJson([
            'success' => true,
            'data' => $bookData,
            'meta' => [
                'response_time_ms' => $responseTime,
                'remaining_requests' => $rateLimit->getRemaining($keyData['api_key'])
            ]
        ]);
    }

    // Statistics endpoint (authenticated)
    if ($requestUri === '/api/stats') {
        if ($requestMethod !== 'GET') {
            sendError('Method not allowed. Use GET.', 405);
        }

        $keyData = authenticate();

        $limit = min((int)($_GET['limit'] ?? 100), 1000);
        $offset = (int)($_GET['offset'] ?? 0);

        $stats = Database::getStats($limit, $offset);

        sendJson([
            'success' => true,
            'data' => $stats,
            'meta' => [
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($stats)
            ]
        ]);
    }

    // Route not found
    sendError('Endpoint not found.', 404);

} catch (PDOException $e) {
    logRequest('Database error', ['error' => $e->getMessage()]);
    sendError('Database error. Please try again later.', 500);
} catch (Exception $e) {
    logRequest('Server error', ['error' => $e->getMessage()]);
    sendError('Internal server error.', 500);
}

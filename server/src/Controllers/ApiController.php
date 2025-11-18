<?php
/**
 * API Controller
 * Handles main API endpoints
 */
class ApiController
{
    private AuthMiddleware $auth;
    private RateLimitMiddleware $rateLimit;
    private BookService $bookService;

    public function __construct()
    {
        $this->auth = new AuthMiddleware();
        $this->rateLimit = new RateLimitMiddleware();

        // Initialize BookService with ScraperManager
        $scraperManager = new ScraperManager($GLOBALS['scraperRegistry']);
        $this->bookService = new BookService($scraperManager);
    }

    /**
     * API info endpoint (no authentication required)
     */
    public function index(array $params = []): void
    {
        Response::json([
            'name' => 'API Book Scraper Server',
            'version' => '2.0.0',
            'endpoints' => [
                'GET /health' => 'Health check',
                'GET /api' => 'API information',
                'GET /api/books/{isbn}' => 'Get book metadata by ISBN (authenticated)',
                'GET /api/stats' => 'Get usage statistics (authenticated)',
            ],
            'authentication' => [
                'description' => 'Required for all endpoints except /health and /api',
                'methods' => [
                    'Authorization header (Bearer)' => 'Authorization: Bearer YOUR_API_KEY',
                    'X-API-Key header' => 'X-API-Key: YOUR_API_KEY',
                    'Query parameter' => '?api_key=YOUR_API_KEY',
                ],
            ],
            'rate_limiting' => [
                'enabled' => (bool)Config::get('RATE_LIMIT_ENABLED', true),
                'requests_per_hour' => (int)Config::get('RATE_LIMIT_REQUESTS_PER_HOUR', 1000),
            ],
            'scrapers' => $this->bookService->getScrapersInfo(),
        ]);
    }

    /**
     * Get book by ISBN
     */
    public function getBook(array $params): void
    {
        // 1. Authenticate
        $keyData = $this->auth->requireAuth();

        // 2. Rate limit check
        $this->rateLimit->requireRateLimit($keyData['api_key']);

        // 3. Validate ISBN parameter
        if (empty($params['isbn'])) {
            Response::error('ISBN parameter is required', 400);
        }

        $isbn = $params['isbn'];

        // 4. Find book
        try {
            $bookData = $this->bookService->findByIsbn($isbn, $keyData['api_key']);
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400);
        }

        if (!$bookData) {
            Response::notFound('Book not found or could not be scraped from any source.');
        }

        // 5. Success response
        Response::success($bookData, [
            'response_time_ms' => $bookData['response_time_ms'] ?? null,
            'remaining_requests' => $this->rateLimit->getRemaining($keyData['api_key']),
        ]);
    }
}

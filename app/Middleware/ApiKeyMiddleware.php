<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Models\ApiKeyRepository;
use App\Models\SettingsRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class ApiKeyMiddleware implements MiddlewareInterface
{
    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        // Check if API is enabled
        $settingsRepo = new SettingsRepository($this->db);
        $apiEnabled = $settingsRepo->get('api', 'enabled', '0');

        if ($apiEnabled !== '1') {
            return $this->jsonError('API is disabled', 403);
        }

        // Get API key from header or query parameter
        $apiKey = $this->extractApiKey($request);

        if ($apiKey === null) {
            return $this->jsonError('API key is required. Provide it via X-API-Key header or api_key query parameter', 401);
        }

        // Validate API key
        $apiKeyRepo = new ApiKeyRepository($this->db);
        $apiKeyRepo->ensureTable();

        $keyData = $apiKeyRepo->getByKey($apiKey);

        if ($keyData === null) {
            return $this->jsonError('Invalid or inactive API key', 401);
        }

        // Update last used timestamp (async, non-blocking)
        try {
            $apiKeyRepo->updateLastUsed($apiKey);
        } catch (\Throwable $e) {
            // Log error but don't block the request
            error_log('Failed to update API key last used timestamp: ' . $e->getMessage());
        }

        // Add API key data to request attributes for use in controller
        $request = $request->withAttribute('api_key_data', $keyData);

        return $handler->handle($request);
    }

    private function extractApiKey(Request $request): ?string
    {
        // Try header first (preferred method)
        $headers = $request->getHeader('X-API-Key');
        if (!empty($headers)) {
            return trim($headers[0]);
        }

        // Fallback to query parameter
        $queryParams = $request->getQueryParams();
        if (isset($queryParams['api_key']) && is_string($queryParams['api_key'])) {
            return trim($queryParams['api_key']);
        }

        return null;
    }

    private function jsonError(string $message, int $statusCode): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode([
            'error' => $message,
            'status' => $statusCode
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($statusCode);
    }
}

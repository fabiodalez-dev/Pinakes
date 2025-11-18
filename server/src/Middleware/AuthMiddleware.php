<?php
/**
 * Authentication Middleware
 * Handles API key validation
 */
class AuthMiddleware
{
    /**
     * Authenticate request and return API key data
     * Returns null if authentication fails
     */
    public function handle(): ?array
    {
        $apiKey = $this->getApiKey();

        if (!$apiKey) {
            return null;
        }

        return Database::validateApiKey($apiKey);
    }

    /**
     * Get API key from request
     * Checks multiple sources: Authorization header, X-API-Key header, query parameter
     */
    public function getApiKey(): ?string
    {
        // 1. Check Authorization header (Bearer token)
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }

        // 2. Check X-API-Key header
        if (!empty($_SERVER['HTTP_X_API_KEY'])) {
            return trim($_SERVER['HTTP_X_API_KEY']);
        }

        // 3. Check query parameter
        if (!empty($_GET['api_key'])) {
            return trim($_GET['api_key']);
        }

        return null;
    }

    /**
     * Require authentication or send error response
     */
    public function requireAuth(): array
    {
        $keyData = $this->handle();

        if (!$keyData) {
            $apiKey = $this->getApiKey();

            if (!$apiKey) {
                Response::unauthorized(
                    'API key required. Provide via Authorization header, X-API-Key header, or api_key query parameter.'
                );
            } else {
                Response::forbidden('Invalid or inactive API key.');
            }
        }

        return $keyData;
    }
}

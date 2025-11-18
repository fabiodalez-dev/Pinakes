<?php
/**
 * Health Check Controller
 * Handles health check endpoint (no authentication required)
 */
class HealthController
{
    /**
     * Health check endpoint
     */
    public function index(array $params = []): void
    {
        Response::json([
            'status' => 'ok',
            'timestamp' => date('c'),
            'version' => '2.0.0',
            'scrapers' => [
                'total' => $GLOBALS['scraperRegistry']->count(),
                'enabled' => $GLOBALS['scraperRegistry']->countEnabled(),
            ]
        ]);
    }
}

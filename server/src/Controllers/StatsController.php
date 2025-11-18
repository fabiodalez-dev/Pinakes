<?php
/**
 * Statistics Controller
 * Handles statistics endpoints
 */
class StatsController
{
    private AuthMiddleware $auth;
    private StatsService $statsService;

    public function __construct()
    {
        $this->auth = new AuthMiddleware();
        $this->statsService = new StatsService();
    }

    /**
     * Get statistics
     */
    public function index(array $params = []): void
    {
        // Authenticate
        $keyData = $this->auth->requireAuth();

        // Get query parameters
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $aggregated = isset($_GET['aggregated']) && $_GET['aggregated'] === 'true';

        if ($aggregated) {
            // Return aggregated statistics
            $stats = $this->statsService->getAggregatedStats();

            Response::success($stats, [
                'type' => 'aggregated',
            ]);
        } else {
            // Return detailed statistics
            $stats = $this->statsService->getStats($limit, $offset);

            Response::success($stats, [
                'type' => 'detailed',
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($stats),
            ]);
        }
    }

    /**
     * Get API keys statistics
     */
    public function apiKeys(array $params = []): void
    {
        // Authenticate
        $keyData = $this->auth->requireAuth();

        $stats = $this->statsService->getApiKeysStats();

        Response::success($stats, [
            'count' => count($stats),
        ]);
    }
}

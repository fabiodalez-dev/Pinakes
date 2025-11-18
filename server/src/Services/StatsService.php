<?php
/**
 * Statistics Service
 * Business logic for statistics and analytics
 */
class StatsService
{
    /**
     * Get statistics
     */
    public function getStats(int $limit = 100, int $offset = 0): array
    {
        // Validate parameters
        $limit = max(1, min($limit, 1000));
        $offset = max(0, $offset);

        return Database::getStats($limit, $offset);
    }

    /**
     * Get statistics by API key
     */
    public function getStatsByApiKey(string $apiKey, int $limit = 100): array
    {
        $allStats = $this->getStats($limit, 0);

        return array_filter($allStats, fn($stat) => $stat['api_key'] === $apiKey);
    }

    /**
     * Get aggregated statistics
     */
    public function getAggregatedStats(): array
    {
        $stats = $this->getStats(10000, 0); // Get all

        $totalRequests = count($stats);
        $successfulRequests = count(array_filter($stats, fn($s) => $s['success']));
        $failedRequests = $totalRequests - $successfulRequests;

        $avgResponseTime = 0;
        if ($totalRequests > 0) {
            $totalResponseTime = array_sum(array_column($stats, 'response_time_ms'));
            $avgResponseTime = (int)($totalResponseTime / $totalRequests);
        }

        // Count by scraper
        $scraperCounts = [];
        foreach ($stats as $stat) {
            if ($stat['success'] && $stat['scraper_used']) {
                $scraperCounts[$stat['scraper_used']] = ($scraperCounts[$stat['scraper_used']] ?? 0) + 1;
            }
        }

        // Sort by count
        arsort($scraperCounts);

        return [
            'total_requests' => $totalRequests,
            'successful_requests' => $successfulRequests,
            'failed_requests' => $failedRequests,
            'success_rate' => $totalRequests > 0 ? round(($successfulRequests / $totalRequests) * 100, 2) : 0,
            'average_response_time_ms' => $avgResponseTime,
            'scraper_usage' => $scraperCounts,
        ];
    }

    /**
     * Get API keys statistics
     */
    public function getApiKeysStats(): array
    {
        $apiKeys = Database::getApiKeys();

        return array_map(function($key) {
            return [
                'name' => $key['name'],
                'requests_count' => $key['requests_count'],
                'last_used_at' => $key['last_used_at'],
                'is_active' => (bool)$key['is_active'],
            ];
        }, $apiKeys);
    }
}

<?php
/**
 * File-based Rate Limiter
 */
class RateLimit
{
    private string $dir;
    private int $maxRequests;

    public function __construct()
    {
        $this->dir = $_ENV['RATE_LIMIT_DIR'] ?? __DIR__ . '/../data/rate_limits';
        $this->maxRequests = (int)($_ENV['RATE_LIMIT_REQUESTS_PER_HOUR'] ?? 1000);

        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
    }

    /**
     * Check if request is allowed for given API key
     */
    public function isAllowed(string $apiKey): bool
    {
        if (!($_ENV['RATE_LIMIT_ENABLED'] ?? true)) {
            return true;
        }

        $hash = md5($apiKey);
        $filePath = $this->dir . '/' . $hash . '.json';

        // Read current data
        $data = $this->readData($filePath);

        // Clean old entries (older than 1 hour)
        $oneHourAgo = time() - 3600;
        $data['requests'] = array_filter($data['requests'], function($timestamp) use ($oneHourAgo) {
            return $timestamp > $oneHourAgo;
        });

        // Check limit
        if (count($data['requests']) >= $this->maxRequests) {
            return false;
        }

        // Add current request
        $data['requests'][] = time();

        // Save
        $this->writeData($filePath, $data);

        return true;
    }

    /**
     * Get remaining requests for API key
     */
    public function getRemaining(string $apiKey): int
    {
        $hash = md5($apiKey);
        $filePath = $this->dir . '/' . $hash . '.json';
        $data = $this->readData($filePath);

        // Clean old entries
        $oneHourAgo = time() - 3600;
        $data['requests'] = array_filter($data['requests'], function($timestamp) use ($oneHourAgo) {
            return $timestamp > $oneHourAgo;
        });

        return max(0, $this->maxRequests - count($data['requests']));
    }

    /**
     * Read rate limit data from file
     */
    private function readData(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return ['requests' => []];
        }

        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        return $data ?: ['requests' => []];
    }

    /**
     * Write rate limit data to file
     */
    private function writeData(string $filePath, array $data): void
    {
        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Clear rate limit data for API key
     */
    public function clear(string $apiKey): void
    {
        $hash = md5($apiKey);
        $filePath = $this->dir . '/' . $hash . '.json';
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
}

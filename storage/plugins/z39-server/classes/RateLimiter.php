<?php
/**
 * Rate Limiter Class
 *
 * Implements token bucket algorithm for rate limiting SRU requests.
 * Protects against DoS attacks by limiting requests per IP address.
 *
 * @package Z39Server
 */

declare(strict_types=1);

namespace Z39Server;

use mysqli;

class RateLimiter
{
    private mysqli $db;
    private int $maxRequests;
    private int $windowSeconds;

    public function __construct(mysqli $db, int $maxRequests, int $windowSeconds)
    {
        $this->db = $db;
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
    }

    /**
     * Check if client is within rate limit
     *
     * @param string $clientIp Client IP address
     * @return bool True if within limit, false if exceeded
     */
    public function checkLimit(string $clientIp): bool
    {
        // Clean up old entries (older than window)
        $cutoff = date('Y-m-d H:i:s', time() - $this->windowSeconds);
        $this->db->query("DELETE FROM z39_rate_limits WHERE window_start < '{$cutoff}'");

        // Get current count for this IP
        $stmt = $this->db->prepare("
            SELECT request_count, window_start
            FROM z39_rate_limits
            WHERE ip_address = ?
            AND window_start >= ?
            ORDER BY window_start DESC
            LIMIT 1
        ");

        $stmt->bind_param('ss', $clientIp, $cutoff);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        $now = date('Y-m-d H:i:s');

        if ($row) {
            // Check if exceeded
            if ($row['request_count'] >= $this->maxRequests) {
                return false;
            }

            // Increment count
            $stmt = $this->db->prepare("
                UPDATE z39_rate_limits
                SET request_count = request_count + 1,
                    last_request = NOW()
                WHERE ip_address = ?
                AND window_start = ?
            ");

            $stmt->bind_param('ss', $clientIp, $row['window_start']);
            $stmt->execute();
            $stmt->close();
        } else {
            // Create new entry
            $stmt = $this->db->prepare("
                INSERT INTO z39_rate_limits (ip_address, request_count, window_start, last_request)
                VALUES (?, 1, ?, NOW())
            ");

            $stmt->bind_param('ss', $clientIp, $now);
            $stmt->execute();
            $stmt->close();
        }

        return true;
    }
}

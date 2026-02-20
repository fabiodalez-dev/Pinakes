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
        // SECURITY FIX: Use prepared statement for cleanup
        $cutoff = date('Y-m-d H:i:s', time() - $this->windowSeconds);
        $cleanupStmt = $this->db->prepare("DELETE FROM z39_rate_limits WHERE window_start < ?");
        if ($cleanupStmt) {
            $cleanupStmt->bind_param('s', $cutoff);
            $cleanupStmt->execute();
            $cleanupStmt->close();
        } else {
            \App\Support\SecureLogger::error("[RateLimiter] Failed to prepare cleanup statement: " . $this->db->error);
        }

        $now = date('Y-m-d H:i:s');

        // SECURITY FIX: Use atomic INSERT...ON DUPLICATE KEY UPDATE to prevent race condition
        // This ensures thread-safe rate limiting without SELECT-then-UPDATE vulnerability
        $stmt = $this->db->prepare("
            INSERT INTO z39_rate_limits (ip_address, request_count, window_start, last_request)
            VALUES (?, 1, ?, NOW())
            ON DUPLICATE KEY UPDATE
                request_count = IF(window_start >= ?, request_count + 1, 1),
                window_start = IF(window_start >= ?, window_start, VALUES(window_start)),
                last_request = NOW()
        ");

        if (!$stmt) {
            // If prepare fails, allow request but log error
            \App\Support\SecureLogger::error("[RateLimiter] Failed to prepare statement: " . $this->db->error);
            return true;
        }

        $stmt->bind_param('ssss', $clientIp, $now, $cutoff, $cutoff);
        $stmt->execute();
        $stmt->close();

        // Now check if limit exceeded
        $checkStmt = $this->db->prepare("
            SELECT request_count
            FROM z39_rate_limits
            WHERE ip_address = ?
            AND window_start >= ?
            ORDER BY window_start DESC
            LIMIT 1
        ");

        if (!$checkStmt) {
            \App\Support\SecureLogger::error("[RateLimiter] Failed to prepare check statement: " . $this->db->error);
            return true;
        }

        $checkStmt->bind_param('ss', $clientIp, $cutoff);
        $checkStmt->execute();
        // Use bind_result/fetch for compatibility without mysqlnd extension
        $checkStmt->bind_result($requestCount);
        $found = $checkStmt->fetch();
        $checkStmt->close();

        if ($found && (int)$requestCount > $this->maxRequests) {
            return false;
        }

        return true;
    }
}

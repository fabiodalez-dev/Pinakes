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
        // Clean up old entries (older than window) - FIX: Use prepared statement
        $cutoff = date('Y-m-d H:i:s', time() - $this->windowSeconds);
        $cleanupStmt = $this->db->prepare("DELETE FROM z39_rate_limits WHERE window_start < ?");
        if ($cleanupStmt) {
            $cleanupStmt->bind_param('s', $cutoff);
            $cleanupStmt->execute();
            $cleanupStmt->close();
        }

        // Get current count for this IP
        $stmt = $this->db->prepare("
            SELECT request_count, window_start
            FROM z39_rate_limits
            WHERE ip_address = ?
            AND window_start >= ?
            ORDER BY window_start DESC
            LIMIT 1
        ");

        if (!$stmt) {
            return true; // Allow request if statement preparation fails
        }

        $stmt->bind_param('ss', $clientIp, $cutoff);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        $now = date('Y-m-d H:i:s');

        // FIX: Use INSERT ... ON DUPLICATE KEY UPDATE to prevent race condition
        // This is atomic and thread-safe
        $stmt = $this->db->prepare("
            INSERT INTO z39_rate_limits (ip_address, request_count, window_start, last_request)
            VALUES (?, 1, ?, NOW())
            ON DUPLICATE KEY UPDATE
                request_count = IF(window_start >= ?, request_count + 1, 1),
                window_start = IF(window_start >= ?, window_start, ?),
                last_request = NOW()
        ");

        if (!$stmt) {
            return true; // Allow on error
        }

        $stmt->bind_param('sssss', $clientIp, $now, $cutoff, $cutoff, $now);
        $stmt->execute();

        // Now check if limit was exceeded (get updated count)
        $checkStmt = $this->db->prepare("
            SELECT request_count
            FROM z39_rate_limits
            WHERE ip_address = ?
            AND window_start >= ?
            ORDER BY window_start DESC
            LIMIT 1
        ");

        if (!$checkStmt) {
            $stmt->close();
            return true; // Allow on error
        }

        $checkStmt->bind_param('ss', $clientIp, $cutoff);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $checkRow = $checkResult->fetch_assoc();

        $stmt->close();
        $checkStmt->close();

        if ($checkRow && $checkRow['request_count'] > $this->maxRequests) {
            return false; // Limit exceeded
        }

        return true;
    }
}

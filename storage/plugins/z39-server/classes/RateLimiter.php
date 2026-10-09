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
     * Client IP for rate limiting, shared by the SRU endpoint and the SBN
     * search route.
     *
     * Forwarding headers are honoured only when the direct peer is a trusted
     * proxy (TRUSTED_PROXIES, exact IPs or CIDRs, plus $extraTrustedProxies).
     * X-Forwarded-For is then walked from the RIGHT: each proxy appends the
     * address it received the request from, so the rightmost entry that is
     * not one of our proxies is the real client. The leftmost entry is
     * whatever the client chose to send and must never win. A malformed
     * chain fails closed to the direct peer.
     *
     * @param array<string,mixed> $server $_SERVER-shaped array
     * @param list<string> $extraTrustedProxies exact IPs trusted in addition to TRUSTED_PROXIES
     */
    public static function resolveClientIp(array $server, array $extraTrustedProxies = []): string
    {
        $isTrusted = static fn (string $ip): bool =>
            in_array($ip, $extraTrustedProxies, true) || \App\Support\HtmlHelper::isTrustedProxyIp($ip);

        $remoteAddr = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        if (filter_var($remoteAddr, FILTER_VALIDATE_IP) === false) {
            return 'unknown';
        }
        if (!$isTrusted($remoteAddr)) {
            return $remoteAddr;
        }

        $forwardedFor = trim((string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwardedFor !== '') {
            $chain = array_map('trim', explode(',', $forwardedFor));
            foreach ($chain as $hop) {
                if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                    return $remoteAddr;
                }
            }
            foreach (array_reverse($chain) as $hop) {
                if (!$isTrusted($hop)) {
                    return $hop;
                }
            }
            // Every hop is one of our proxies: the leftmost is the farthest.
            return $chain[0];
        }

        // Single-value headers, only when the proxy sends no X-Forwarded-For.
        foreach (['HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP'] as $header) {
            $value = trim((string) ($server[$header] ?? ''));
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_IP) !== false) {
                return $value;
            }
        }

        return $remoteAddr;
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

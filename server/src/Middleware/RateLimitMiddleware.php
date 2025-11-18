<?php
/**
 * Rate Limiting Middleware
 * Handles request rate limiting per API key
 */
class RateLimitMiddleware
{
    private RateLimit $rateLimit;

    public function __construct()
    {
        $this->rateLimit = new RateLimit();
    }

    /**
     * Check if request is allowed
     * Returns true if allowed, false if rate limit exceeded
     */
    public function handle(string $apiKey): bool
    {
        return $this->rateLimit->isAllowed($apiKey);
    }

    /**
     * Get remaining requests for API key
     */
    public function getRemaining(string $apiKey): int
    {
        return $this->rateLimit->getRemaining($apiKey);
    }

    /**
     * Require rate limit check or send error response
     */
    public function requireRateLimit(string $apiKey): void
    {
        if (!$this->handle($apiKey)) {
            $remaining = $this->getRemaining($apiKey);

            Response::tooManyRequests(
                'Rate limit exceeded. Try again later. Remaining requests: ' . $remaining
            );
        }
    }

    /**
     * Get rate limit instance
     */
    public function getRateLimit(): RateLimit
    {
        return $this->rateLimit;
    }
}

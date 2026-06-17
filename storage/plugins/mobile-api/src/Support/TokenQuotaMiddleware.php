<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Support\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Per-token quota for authenticated endpoints (spec §Rate limiting:
 * "per-token quotas on other endpoints").
 *
 * Runs AFTER AppAuthMiddleware in the stack (so the resolved token id is present
 * on the request as an attribute) but, because Slim executes group middleware in
 * LIFO order, it is ADDED before AppAuthMiddleware so it wraps the inner handler.
 * The quota key is the token id when available, else the client IP — an
 * unauthenticated/invalid request is rejected by AppAuthMiddleware anyway.
 *
 * Reuses the shared file-backed RateLimiter (same store as the web throttles),
 * and honours the E2E bypass env var so the serial test suite isn't throttled.
 */
final class TokenQuotaMiddleware implements MiddlewareInterface
{
    private int $maxRequests;
    private int $window;

    public function __construct(int $maxRequests = 240, int $window = 60)
    {
        $this->maxRequests = max(1, $maxRequests);
        $this->window      = max(1, $window);
    }

    public function process(Request $request, RequestHandler $handler): ResponseInterface
    {
        $bypass = $_ENV['PINAKES_E2E_BYPASS_RATE_LIMIT']
            ?? getenv('PINAKES_E2E_BYPASS_RATE_LIMIT')
            ?: '';
        if ($bypass === '1' || strtolower((string) $bypass) === 'true') {
            return $handler->handle($request);
        }

        $tokenId = $request->getAttribute(AppAuthMiddleware::ATTR_TOKEN_ID);
        if (is_int($tokenId)) {
            $identifier = 'mobile_api_quota:token:' . $tokenId;
        } else {
            $server = $request->getServerParams();
            $ip     = (string) ($server['REMOTE_ADDR'] ?? 'unknown');
            $identifier = 'mobile_api_quota:ip:' . $ip;
        }

        if (RateLimiter::isLimited($identifier, $this->maxRequests, $this->window)) {
            return ResponseEnvelope::error(
                new SlimResponse(),
                'rate_limited',
                __('Hai superato il limite di richieste. Riprova più tardi.'),
                429,
                ['retry_after' => $this->window]
            )->withHeader('Retry-After', (string) $this->window);
        }

        return $handler->handle($request);
    }
}

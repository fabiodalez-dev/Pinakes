<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use App\Support\ClientIpResolver;
use App\Support\RateLimiter;

class RateLimitMiddleware implements MiddlewareInterface
{
    private int $maxAttempts;
    private int $window; // in seconds
    private ?string $actionKey;

    public function __construct(int $maxAttempts = 10, int $window = 900, ?string $actionKey = null) // 15 minutes default
    {
        $this->maxAttempts = max(1, $maxAttempts);
        $this->window = max(1, $window);
        $this->actionKey = $actionKey;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        // Test-env escape hatch — opt-in via PINAKES_E2E_BYPASS_RATE_LIMIT=1
        // in the env (NOT shipped to production .env). When set, the flag
        // bypasses *every* instance of this middleware, not only the login
        // throttle — also register/forgot-password and any future route
        // that wires this middleware in. That is exactly what an E2E
        // suite with ~30 serial describe blocks (each with a beforeAll
        // login) needs: without this flag the 5/300s throttle saturates
        // after ~6 hooks and the remaining tests cascade to "did not run".
        // Production is unaffected — the flag is ignored when absent or
        // empty, and only `1`/`true` enables it.
        $bypassFlag = $_ENV['PINAKES_E2E_BYPASS_RATE_LIMIT']
            ?? getenv('PINAKES_E2E_BYPASS_RATE_LIMIT')
            ?: '';
        if ($bypassFlag === '1' || strtolower((string) $bypassFlag) === 'true') {
            return $handler->handle($request);
        }

        $ip = $this->getClientIP($request);
        // Use action-based key if provided (prevents bypass via localized URLs)
        $endpoint = $this->actionKey ?? $request->getUri()->getPath();
        $identifier = $ip . ':' . $endpoint;

        if (RateLimiter::isLimited($identifier, $this->maxAttempts, $this->window)) {
            $response = new \Slim\Psr7\Response();
            $body = fopen('php://temp', 'r+');
            if ($body === false) {
                // Fallback: return response without body if stream creation fails
                return $response
                    ->withStatus(429)
                    ->withHeader('Retry-After', (string)$this->window);
            }

            $jsonData = json_encode([
                'error' => __('Too many requests'),
                'message' => __('Rate limit exceeded. Please try again later.')
            ]);

            if ($jsonData === false) {
                // Fallback: use hardcoded JSON if encoding fails
                $jsonData = '{"error":"Too many requests","message":"Rate limit exceeded. Please try again later."}';
            }
            fwrite($body, $jsonData);

            rewind($body);
            return $response
                ->withStatus(429)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string)$this->window)
                ->withBody(new \Slim\Psr7\Stream($body));
        }

        return $handler->handle($request);
    }

    /**
     * Get the real client IP considering proxies
     */
    private function getClientIP(Request $request): string
    {
        $serverParams = $request->getServerParams();
        $headers = [];
        foreach (['X-Forwarded-For', 'X-Real-IP', 'CF-Connecting-IP', 'True-Client-IP', 'X-Client-IP'] as $name) {
            $headers[$name] = $request->getHeaderLine($name);
        }

        return ClientIpResolver::resolve((string) ($serverParams['REMOTE_ADDR'] ?? 'unknown'), $headers);
    }
}

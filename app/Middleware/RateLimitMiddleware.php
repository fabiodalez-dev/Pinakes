<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
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
        
        // Check various headers that might contain the real IP
        $headers = [
            'X-Forwarded-For',
            'X-Real-IP',
            'X-Client-IP',
            'CF-Connecting-IP',
            'True-Client-IP'
        ];

        foreach ($headers as $header) {
            $headerValue = $request->getHeaderLine($header);
            if (!empty($headerValue)) {
                // X-Forwarded-For can contain multiple IPs, take the first one
                $ips = explode(',', $headerValue);
                $ip = trim($ips[0]);
                
                if ($this->isValidIP($ip)) {
                    return $ip;
                }
            }
        }

        // Fallback to REMOTE_ADDR
        return $serverParams['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Validate IP address format
     */
    private function isValidIP(string $ip): bool
    {
        $filtered = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        return $filtered !== false;
    }
}
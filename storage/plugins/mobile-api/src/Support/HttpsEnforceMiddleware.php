<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use App\Plugins\MobileApi\Support\ResponseEnvelope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Enforce HTTPS on the whole /api/v1 group.
 *
 * Spec: "HTTPS enforced; reject `http` except `localhost`/loopback (dev)."
 * The /health endpoint itself still works over http on loopback so the app can
 * complete onboarding in dev; it advertises the https status in its own payload.
 *
 * Detection order (proxy-aware): X-Forwarded-Proto, then the PSR-7 URI scheme,
 * then HTTPS server var. Loopback hosts (localhost, 127.0.0.0/8, ::1) are exempt.
 */
final class HttpsEnforceMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): ResponseInterface
    {
        if ($this->isSecure($request) || $this->isLoopback($request)) {
            return $handler->handle($request);
        }

        $response = new SlimResponse();

        return ResponseEnvelope::error(
            $response,
            'https_required',
            __('Questa API richiede una connessione HTTPS.'),
            426
        );
    }

    private function isSecure(Request $request): bool
    {
        $forwarded = $request->getHeaderLine('X-Forwarded-Proto');
        if ($forwarded !== '') {
            // May be a comma-separated list when chained proxies are involved.
            $first = strtolower(trim(explode(',', $forwarded)[0]));
            if ($first === 'https') {
                return true;
            }
            if ($first === 'http') {
                return false;
            }
        }

        $scheme = strtolower($request->getUri()->getScheme());
        if ($scheme === 'https') {
            return true;
        }
        if ($scheme === 'http') {
            return false;
        }

        $server = $request->getServerParams();
        $https  = strtolower((string) ($server['HTTPS'] ?? ''));

        return $https !== '' && $https !== 'off';
    }

    private function isLoopback(Request $request): bool
    {
        $host = strtolower($request->getUri()->getHost());
        if ($host === '') {
            $server = $request->getServerParams();
            $host   = strtolower((string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? ''));
            // Strip a possible :port suffix.
            $host = (string) preg_replace('/:\d+$/', '', $host);
        }

        if ($host === 'localhost' || $host === '::1' || $host === '[::1]') {
            return true;
        }

        // 127.0.0.0/8
        if (preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host) === 1) {
            return true;
        }

        return false;
    }
}

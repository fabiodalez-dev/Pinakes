<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\SessionPolicy;
use App\Support\SessionRuntime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;

/**
 * Starts a session for conservative/unknown GET routes after Slim has routed
 * the request. This lets the audited canonical book route remain sessionless
 * without accidentally classifying a similarly-shaped plugin route as public.
 */
final class RoutedSessionMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $route = RouteContext::fromRequest($request)->getRoute();
            $pattern = $route !== null ? $route->getPattern() : null;
            if (SessionPolicy::requiresRoutedSession(
                $request->getMethod(),
                $request->getCookieParams(),
                $request->getUri()->getPath(),
                $pattern
            )) {
                SessionRuntime::start();
            }
        }

        return $handler->handle($request);
    }
}

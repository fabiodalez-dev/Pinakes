<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Middleware to protect admin routes
 * Only allows users with 'admin' or 'staff' tipo_utente
 */
class AdminAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $user = $_SESSION['user'] ?? null;

        // Not authenticated - redirect to login
        if (!$user) {
            $res = new SlimResponse(302);
            return $res->withHeader('Location', '/login?error=auth_required');
        }

        // Check for admin or staff role
        $tipo_utente = $user['tipo_utente'] ?? null;
        if ($tipo_utente !== 'admin' && $tipo_utente !== 'staff') {
            // Regular user trying to access admin area - redirect to profile
            return (new SlimResponse(302))->withHeader('Location', '/profilo');
        }

        // Admin/staff user - allow access
        return $handler->handle($request);
    }
}

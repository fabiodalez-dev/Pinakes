<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\RouteTranslator;
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

        // Check if this is an API request (starts with /api/)
        $uri = $request->getUri()->getPath();
        $isApiRequest = strpos($uri, '/api/') === 0;

        // Not authenticated
        if (!$user) {
            if ($isApiRequest) {
                // Return JSON error for API requests
                $res = new SlimResponse(401);
                $res->getBody()->write(json_encode([
                    'error' => true,
                    'message' => __('Autenticazione richiesta.')
                ], JSON_UNESCAPED_UNICODE));
                return $res->withHeader('Content-Type', 'application/json');
            }

            // Redirect to login for regular requests
            $res = new SlimResponse(302);
            $loginUrl = RouteTranslator::route('login') . '?error=auth_required';
            return $res->withHeader('Location', $loginUrl);
        }

        // Check for admin or staff role
        $tipo_utente = $user['tipo_utente'] ?? null;
        if ($tipo_utente !== 'admin' && $tipo_utente !== 'staff') {
            if ($isApiRequest) {
                // Return JSON error for API requests
                $res = new SlimResponse(403);
                $res->getBody()->write(json_encode([
                    'error' => true,
                    'message' => __('Accesso negato. Permessi insufficienti.')
                ], JSON_UNESCAPED_UNICODE));
                return $res->withHeader('Content-Type', 'application/json');
            }

            // Regular user trying to access admin area - redirect to profile
            $profileUrl = RouteTranslator::route('profile');
            return (new SlimResponse(302))->withHeader('Location', $profileUrl);
        }

        // Admin/staff user - allow access
        return $handler->handle($request);
    }
}

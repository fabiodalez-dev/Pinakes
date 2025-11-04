<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class AuthMiddleware implements MiddlewareInterface
{
    /** @var string[] */
    private array $roles;

    /**
     * @param string[] $roles Allowed roles; empty = any authenticated user
     */
    public function __construct(array $roles = [])
    {
        $this->roles = $roles;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $user = $_SESSION['user'] ?? null;

        // Utente non autenticato
        if (!$user) {
            $res = new SlimResponse(302);
            return $res->withHeader('Location', '/login?error=auth_required');
        }

        // FIX: Array vuoto = nessun ruolo permesso = DENY
        if ($this->roles === []) {
            return $this->denyAccess($request, $user);
        }

        if (!isset($user['tipo_utente']) || !in_array($user['tipo_utente'], $this->roles, true)) {
            return $this->denyAccess($request, $user);
        }

        return $handler->handle($request);
    }

    private function denyAccess(Request $request, array $user): Response
    {
        $res = new SlimResponse(403);

        if (str_starts_with($request->getUri()->getPath(), '/api/')) {
            $res->getBody()->write(json_encode([
                'error' => 'Insufficient privileges',
                'code' => 'FORBIDDEN'
            ], JSON_UNESCAPED_UNICODE));
            return $res->withHeader('Content-Type', 'application/json');
        }

        return (new SlimResponse(302))->withHeader('Location', '/403?reason=insufficient_privileges');
    }
}

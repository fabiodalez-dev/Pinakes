<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;
use App\Support\Csrf;

/**
 * CSRF Protection Middleware
 * Valida token CSRF su richieste POST/PUT/DELETE/PATCH
 */
class CsrfMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $method = $request->getMethod();

        // Applica protezione CSRF solo su metodi mutating
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            $token = null;

            // Cerca token in diversi posti
            $parsedBody = $request->getParsedBody();
            if (is_array($parsedBody) && isset($parsedBody['csrf_token'])) {
                $token = $parsedBody['csrf_token'];
            } else {
                // Prova header X-CSRF-Token (per AJAX)
                $headers = $request->getHeader('X-CSRF-Token');
                if (!empty($headers)) {
                    $token = $headers[0];
                }
            }

            // Valida token con dettaglio del motivo
            $csrfValidation = Csrf::validateWithReason($token);
            if (!$csrfValidation['valid']) {
                error_log('[CSRF] Validation failed. Reason: ' . $csrfValidation['reason'] . ' Token provided: ' . var_export($token, true) . ' Session token: ' . var_export($_SESSION['csrf_token'] ?? null, true));

                $response = new SlimResponse(403);

                // Messaggio user-friendly per sessione scaduta
                if ($csrfValidation['reason'] === 'session_expired') {
                    $response->getBody()->write(json_encode([
                        'error' => 'La tua sessione è scaduta. Per motivi di sicurezza, ricarica la pagina ed effettua nuovamente l\'accesso',
                        'code' => 'SESSION_EXPIRED',
                        'redirect' => '/login?error=session_expired'
                    ], JSON_UNESCAPED_UNICODE));
                } else {
                    // Altri errori CSRF
                    $response->getBody()->write(json_encode([
                        'error' => 'Errore di sicurezza. Ricarica la pagina e riprova',
                        'code' => 'CSRF_INVALID'
                    ], JSON_UNESCAPED_UNICODE));
                }

                return $response->withHeader('Content-Type', 'application/json');
            }
        }

        return $handler->handle($request);
    }
}

<?php
declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Support\RouteTranslator;

/**
 * Helper centralizzato per gestione CSRF con messaggi user-friendly
 * Gestisce automaticamente sessioni scadute e redirect appropriati
 */
final class CsrfHelper
{
    /**
     * Valida CSRF token e restituisce Response di errore se invalido
     *
     * @param string|null $token Token CSRF da validare
     * @param Response $response Response object per creare redirect
     * @param string|null $returnUrl URL dove tornare dopo errore (default: login)
     * @return Response|null Null se valido, Response di redirect se invalido
     */
    public static function validateOrRedirect(
        ?string $token,
        Response $response,
        ?string $returnUrl = null
    ): ?Response {
        $validation = Csrf::validateWithReason($token);

        if ($validation['valid']) {
            return null; // Token valido, nessun errore
        }

        // Token invalido - determina il redirect appropriato
        if ($validation['reason'] === 'session_expired') {
            // Sessione scaduta - redirect a login con messaggio chiaro
            $location = RouteTranslator::route('login') . '?error=session_expired';
            if ($returnUrl) {
                $location .= '&return=' . urlencode($returnUrl);
            }
            return $response->withHeader('Location', $location)->withStatus(302);
        }

        // Altri errori CSRF - redirect alla pagina precedente o login
        if ($returnUrl) {
            return $response->withHeader('Location', $returnUrl . '?error=csrf')->withStatus(302);
        }

        return $response->withHeader('Location', RouteTranslator::route('login') . '?error=csrf')->withStatus(302);
    }

    /**
     * Valida CSRF per richieste JSON/API
     *
     * @param string|null $token Token CSRF da validare
     * @param Response $response Response object per creare risposta JSON
     * @return Response|null Null se valido, Response JSON se invalido
     */
    public static function validateOrJsonError(?string $token, Response $response): ?Response
    {
        $validation = Csrf::validateWithReason($token);

        if ($validation['valid']) {
            return null; // Token valido, nessun errore
        }

        // Token invalido - risposta JSON con dettagli
        if ($validation['reason'] === 'session_expired') {
            $body = json_encode([
                'success' => false,
                'error' => __('La tua sessione è scaduta. Per motivi di sicurezza, ricarica la pagina ed effettua nuovamente l\'accesso.'),
                'code' => 'SESSION_EXPIRED',
                'redirect' => RouteTranslator::route('login') . '?error=session_expired'
            ], JSON_UNESCAPED_UNICODE);
        } else {
            $body = json_encode([
                'success' => false,
                'error' => __('Errore di sicurezza. Ricarica la pagina e riprova.'),
                'code' => 'CSRF_INVALID'
            ], JSON_UNESCAPED_UNICODE);
        }

        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
    }

    /**
     * Estrae token CSRF da Request (body o header)
     *
     * @param Request $request Request object
     * @return string|null Token CSRF se presente
     */
    public static function extractToken(Request $request): ?string
    {
        // Prova dal body
        $data = $request->getParsedBody();
        if (is_array($data) && isset($data['csrf_token'])) {
            return (string)$data['csrf_token'];
        }

        // Prova dall'header X-CSRF-Token
        $headers = $request->getHeader('X-CSRF-Token');
        if (!empty($headers)) {
            return (string)$headers[0];
        }

        return null;
    }

    /**
     * Shortcut: estrae e valida token da Request, ritorna redirect se invalido
     * Uso tipico nei controller:
     *
     * if ($error = CsrfHelper::validateRequest($request, $response, '/current-page')) {
     *     return $error;
     * }
     *
     * @param Request $request Request object
     * @param Response $response Response object
     * @param string|null $returnUrl URL dove tornare in caso di errore
     * @return Response|null Null se valido, Response di redirect se invalido
     */
    public static function validateRequest(
        Request $request,
        Response $response,
        ?string $returnUrl = null
    ): ?Response {
        $token = self::extractToken($request);
        return self::validateOrRedirect($token, $response, $returnUrl);
    }

    /**
     * Shortcut: estrae e valida token da Request per API JSON
     *
     * @param Request $request Request object
     * @param Response $response Response object
     * @return Response|null Null se valido, Response JSON se invalido
     */
    public static function validateJsonRequest(Request $request, Response $response): ?Response
    {
        $token = self::extractToken($request);
        return self::validateOrJsonError($token, $response);
    }
}

<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\TokenService;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Bearer-token authentication for the authenticated /api/v1 surface.
 *
 * Flow (spec §Auth flow step 3):
 *   1. Require the dedicated app-access gate (mobile_api.enabled) — 403 otherwise,
 *      so that disabling the gate immediately locks every authed endpoint, not
 *      just login.
 *   2. Extract the `Authorization: Bearer <token>` header.
 *   3. Hash + look up the token (not revoked, not expired) via TokenService; the
 *      lookup itself updates last_used_at.
 *   4. Load the owning utente, enforce stato='attivo' (a suspended account must
 *      not keep API access on an old token).
 *   5. Attach the authenticated identity to the request as attributes
 *      (`mobile_user`, `mobile_token_id`) AND mirror it into $_SESSION['user']
 *      so reused core services that read the session keep working.
 *
 * Never trusts any client-supplied user_id: identity comes only from the token.
 * Error envelopes are generic — no internals leak (spec §Security).
 */
final class AppAuthMiddleware implements MiddlewareInterface
{
    public const ATTR_USER     = 'mobile_user';
    public const ATTR_TOKEN_ID = 'mobile_token_id';

    private mysqli $db;
    private bool $appAccessEnabled;

    public function __construct(mysqli $db, bool $appAccessEnabled)
    {
        $this->db               = $db;
        $this->appAccessEnabled = $appAccessEnabled;
    }

    public function process(Request $request, RequestHandler $handler): ResponseInterface
    {
        if (!$this->appAccessEnabled) {
            return $this->deny('app_access_disabled', __('L\'accesso da app non è abilitato su questa istanza.'), 403);
        }

        $token = $this->extractBearer($request);
        if ($token === null) {
            return $this->deny('unauthorized', __('Token di autenticazione mancante o non valido.'), 401);
        }

        try {
            $service = new TokenService($this->db);
            $row     = $service->resolveActive($token);
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] token resolution failed: ' . $e->getMessage());
            return $this->deny('internal_error', __('Errore di autenticazione.'), 500);
        }

        if ($row === null) {
            return $this->deny('unauthorized', __('Token non valido, revocato o scaduto.'), 401);
        }

        $user = $this->loadUser($row['user_id']);
        if ($user === null) {
            return $this->deny('unauthorized', __('Account non disponibile.'), 401);
        }

        // Mirror into the session so reused core services (e.g. session-scoped
        // helpers) see a consistent identity. The API is stateless per-request;
        // this is request-local population, not a persisted web session.
        $_SESSION['user'] = [
            'id'          => $user['id'],
            'email'       => $user['email'],
            'tipo_utente' => $user['tipo_utente'],
            'name'        => trim((string) $user['nome'] . ' ' . (string) $user['cognome']),
        ];

        $request = $request
            ->withAttribute(self::ATTR_USER, $user)
            ->withAttribute(self::ATTR_TOKEN_ID, $row['id']);

        return $handler->handle($request);
    }

    /**
     * @return array{id:int, email:string, tipo_utente:string, nome:string, cognome:string, locale:?string, stato:string}|null
     */
    private function loadUser(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, email, tipo_utente, nome, cognome, locale, stato, email_verificata
               FROM utenti
              WHERE id = ?
              LIMIT 1'
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row === null) {
            return null;
        }

        // A suspended/expired or unverified account must not retain API access.
        if (((int) ($row['email_verificata'] ?? 0)) !== 1 || ($row['stato'] ?? '') !== 'attivo') {
            return null;
        }

        return [
            'id'          => (int) $row['id'],
            'email'       => (string) $row['email'],
            'tipo_utente' => (string) $row['tipo_utente'],
            'nome'        => (string) ($row['nome'] ?? ''),
            'cognome'     => (string) ($row['cognome'] ?? ''),
            'locale'      => $row['locale'] !== null ? (string) $row['locale'] : null,
            'stato'       => (string) $row['stato'],
        ];
    }

    private function extractBearer(Request $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header === '') {
            return null;
        }
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m) !== 1) {
            return null;
        }

        $token = trim($m[1]);

        return $token !== '' ? $token : null;
    }

    private function deny(string $code, string $message, int $status): ResponseInterface
    {
        return ResponseEnvelope::error(new SlimResponse(), $code, $message, $status);
    }
}

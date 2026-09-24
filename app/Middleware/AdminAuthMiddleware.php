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
    /** Roles this middleware admits. */
    private const ALLOWED_ROLES = ['admin', 'staff'];

    private ?\mysqli $db;

    /**
     * $db is optional: the app instantiates this middleware without arguments
     * (see app/Routes/web.php), so when none is injected
     * SessionRoleRevalidator opens a connection from the app's own DB config.
     */
    public function __construct(?\mysqli $db = null)
    {
        $this->db = $db;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $user = $_SESSION['user'] ?? null;

        // Check if this is an API request (starts with /api/)
        $uri = $request->getUri()->getPath();
        // A refusal has to arrive in the shape the caller can read. Path was the
        // only signal, which is right for /api/* and wrong for an admin
        // endpoint called by XHR — a plugin's upload, for instance: a 302 to an
        // HTML page reaches JavaScript expecting JSON as an unexplained
        // success-shaped blob, so the interface reports nothing while the
        // upload silently did not happen. Asking what the caller wants back
        // answers it for every such route, present and future.
        $isApiRequest = strpos($uri, '/api/') === 0
            || strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest'
            || str_contains($request->getHeaderLine('Accept'), 'application/json');

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

        // Security scan F8 (CWE-613): the session role is a snapshot captured at
        // login, so the decision is taken on the role read from the DB on this
        // request — never on the snapshot. Demotions and suspensions take effect
        // immediately, and so do PROMOTIONS: an earlier version rejected a
        // session whose snapshot said 'standard' before asking the database, so
        // an account just promoted to staff stayed locked out of every admin
        // route until it logged in again. Fails CLOSED on any DB/lookup failure.
        if (!$this->revalidateRole($user)) {
            return $this->denyRole($isApiRequest);
        }

        // Admin/staff user - allow access
        return $handler->handle($request);
    }

    /**
     * Deny response for an insufficient/void admin role.
     * Mirrors the existing role-gate behaviour: JSON 403 for API requests,
     * a 302 redirect to the profile page for browser requests.
     */
    private function denyRole(bool $isApiRequest): Response
    {
        if ($isApiRequest) {
            $res = new SlimResponse(403);
            $res->getBody()->write(json_encode([
                'error' => true,
                'message' => __('Accesso negato. Permessi insufficienti.')
            ], JSON_UNESCAPED_UNICODE));
            return $res->withHeader('Content-Type', 'application/json');
        }

        $profileUrl = RouteTranslator::route('profile');
        return (new SlimResponse(302))->withHeader('Location', $profileUrl);
    }

    /**
     * Re-validate the session user against the DB (fail-closed).
     *
     * Returns true only when the user still exists, is `stato = 'attivo'`, and
     * still holds an allowed role. The query, the per-request memo and the
     * write-back of the fresh role into the session live in
     * SessionRoleRevalidator, shared with AuthMiddleware and
     * SessionRoleRefreshMiddleware so the three can never disagree about what
     * "still valid" means.
     *
     * The write-back is what keeps the inline `tipo_utente === 'admin'` guards
     * downstream honest: this middleware admits BOTH admin and staff, so a
     * demoted admin (admin → staff) still passes here, and without the fresh
     * role in the session the destructive admin-only operations (update
     * install/perform, saveToken, reCAPTCHA secret) would keep reading the
     * stale login-time 'admin'.
     *
     * @param array<string,mixed> $user
     */
    private function revalidateRole(array $user): bool
    {
        $userId = isset($user['id']) ? (int) $user['id'] : 0;
        if ($userId <= 0) {
            // No usable id in the session → cannot re-validate → fail closed.
            \App\Support\SecureLogger::error('[AdminAuthMiddleware] Missing user id in session during admin re-validation');
            return false;
        }

        return \App\Support\SessionRoleRevalidator::holdsActiveRole(self::ALLOWED_ROLES, $this->db);
    }
}

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
 * Requires a logged-in, ACTIVE account holding one of the given roles.
 *
 * The role and state are re-read from the database on every request through
 * SessionRoleRevalidator, never taken from the login-time session snapshot.
 * Until this re-check existed the gate below compared the snapshot directly,
 * so an account demoted from admin, suspended or deleted kept its old access
 * for as long as its session lived (CWE-613) — including on the nine
 * admin-only routes that use this middleware with ['admin'] (the eight
 * maintenance actions and increase-copies), and
 * on the routes that pass every role and then branch on the role inline
 * (the borrower PII on /admin/books/{id}, the activity feed on the
 * dashboard). The re-check also writes the fresh role back into the session,
 * which is what makes those inline branches trustworthy.
 *
 * `stato = 'attivo'` is required because the login form and the remember-me
 * cookie already require it: a session must not outlast the permission to
 * open one.
 */
class AuthMiddleware implements MiddlewareInterface
{
    /** @var string[] */
    private array $roles;

    private ?\mysqli $db;

    /**
     * @param string[] $roles Allowed roles; empty = deny everyone (see process()).
     * @param \mysqli|null $db Optional handle; routes pass none and the
     *   revalidator opens one from the app's own configuration.
     */
    public function __construct(array $roles = [], ?\mysqli $db = null)
    {
        $this->roles = $roles;
        $this->db = $db;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $user = $_SESSION['user'] ?? null;

        // Utente non autenticato
        if (!$user) {
            $res = new SlimResponse(302);
            $loginUrl = RouteTranslator::route('login') . '?error=auth_required';
            return $res->withHeader('Location', $loginUrl);
        }

        // FIX: Array vuoto = nessun ruolo permesso = DENY
        if ($this->roles === []) {
            return $this->denyAccess($request, $user);
        }

        $verdict = \App\Support\SessionRoleRevalidator::resolve($this->db);

        // The database could not answer: fail CLOSED. Nothing is known about
        // the account, and admitting it on the strength of the snapshot is
        // exactly the stale-role trust this check exists to remove.
        if ($verdict['status'] === \App\Support\SessionRoleRevalidator::ERROR
            || $verdict['status'] === \App\Support\SessionRoleRevalidator::NO_SESSION) {
            return $this->denyAccess($request, $user);
        }

        // The account is gone or no longer active. The session is no longer
        // entitled to exist, so it is ended rather than merely refused: a 403
        // would leave a zombie login that every later request trips over.
        if ($verdict['status'] === \App\Support\SessionRoleRevalidator::ABSENT
            || $verdict['stato'] !== 'attivo') {
            unset($_SESSION['user']);
            $loginUrl = RouteTranslator::route('login') . '?error=auth_required';
            return (new SlimResponse(302))->withHeader('Location', $loginUrl);
        }

        if (!in_array($verdict['role'], $this->roles, true)) {
            return $this->denyAccess($request, $_SESSION['user'] ?? $user);
        }

        return $handler->handle($request);
    }

    private function denyAccess(Request $request, array $user): Response
    {
        $res = new SlimResponse(403);

        if (str_starts_with($request->getUri()->getPath(), '/api/')) {
            $res->getBody()->write(json_encode([
                'error' => __('Insufficient privileges'),
                'code' => 'FORBIDDEN'
            ], JSON_UNESCAPED_UNICODE));
            return $res->withHeader('Content-Type', 'application/json');
        }

        return (new SlimResponse(302))->withHeader('Location', '/403?reason=insufficient_privileges');
    }
}

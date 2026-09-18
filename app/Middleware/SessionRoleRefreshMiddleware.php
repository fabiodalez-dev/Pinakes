<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Re-validates the session's operator claim against the DB WITHOUT requiring
 * one, and publishes the verdict as the `is_operator_session` request
 * attribute.
 *
 * Why this exists as a middleware rather than an inline check.
 * `$_SESSION['user']['tipo_utente']` is a snapshot taken at login, and roughly
 * sixty places across the controllers read it directly. They are sound
 * because they sit behind AdminAuthMiddleware or AuthMiddleware, which re-read
 * the account through SessionRoleRevalidator and write the fresh role back
 * into the session, so every downstream reader on that route sees a current
 * value. The invariant "the session role is fresh" is maintained by the
 * middleware chain, not by the readers.
 *
 * A route registered with no middleware therefore has no one maintaining it,
 * and a role read there is a stale login-time claim that survives a demotion or
 * a suspension until the session expires (CWE-613). That is the gap this fills
 * for endpoints that must stay reachable by anonymous callers and so cannot
 * take an authorising middleware, which would answer 401 or redirect.
 *
 * Two deliberate differences from the authorising middlewares:
 *
 * 1. It never denies. The privilege fails closed, the request does not: on a
 *    missing session, a suspended account, an unreachable DB or a failed query
 *    the attribute is false and the handler still runs. A public endpoint that
 *    started 500-ing because the role lookup hiccuped would be a worse bug than
 *    the one being fixed.
 * 2. It writes the refreshed role back into the session ONLY on a successful
 *    read. Clearing it on a transient DB failure would turn a momentary blip
 *    into a logged-out user for every later request in that session.
 *
 * Consumers must read the attribute, never the session, or the freshness this
 * establishes is thrown away at the point of use.
 */
class SessionRoleRefreshMiddleware implements MiddlewareInterface
{
    /** The request attribute this middleware publishes. */
    public const ATTRIBUTE = 'is_operator_session';

    /** Roles that count as operator. Mirrors AdminAuthMiddleware::ALLOWED_ROLES. */
    private const OPERATOR_ROLES = ['admin', 'staff'];

    private ?\mysqli $db;
    private ?ContainerInterface $container;

    /**
     * Either an open handle or the app container to pull `db` from lazily.
     * The container form is what the routes use, so no connection is opened
     * for a request that turns out to be anonymous.
     */
    public function __construct(?\mysqli $db = null, ?ContainerInterface $container = null)
    {
        $this->db = $db;
        $this->container = $container;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        return $handler->handle(
            $request->withAttribute(self::ATTRIBUTE, $this->resolveOperator())
        );
    }

    /**
     * The query, the per-request memo and the write-back of the fresh role
     * into the session all live in SessionRoleRevalidator, shared with the
     * two authorising middlewares. Any failure to establish an active
     * operator account — no session, unreachable database, deleted or
     * suspended account — is simply "not an operator" here.
     */
    private function resolveOperator(): bool
    {
        if (!isset($_SESSION['user']['id'])) {
            // Anonymous: the common case on this public endpoint, and not an
            // error — so no connection is opened for it.
            return false;
        }

        $db = $this->resolveDb();
        if (!$db instanceof \mysqli) {
            \App\Support\SecureLogger::error('[SessionRoleRefreshMiddleware] Database unavailable; treating session as non-operator');
            return false;
        }

        return \App\Support\SessionRoleRevalidator::holdsActiveRole(self::OPERATOR_ROLES, $db);
    }

    private function resolveDb(): ?\mysqli
    {
        if ($this->db instanceof \mysqli) {
            return $this->db;
        }
        if (!$this->container instanceof ContainerInterface) {
            return null;
        }

        try {
            $db = $this->container->get('db');
        } catch (\Throwable $e) {
            return null;
        }

        if ($db instanceof \mysqli) {
            $this->db = $db;
            return $db;
        }

        return null;
    }
}

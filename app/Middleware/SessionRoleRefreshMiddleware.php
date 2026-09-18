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
 * sixty places across the controllers read it directly. They are all sound
 * because they sit behind AdminAuthMiddleware, whose revalidateRole() does not
 * merely decide yes/no — it writes the fresh DB role back into the session, so
 * every downstream reader on that route sees a current value. The invariant
 * "the session role is fresh" is maintained by the middleware chain, not by the
 * readers.
 *
 * A route registered with no middleware therefore has no one maintaining it,
 * and a role read there is a stale login-time claim that survives a demotion or
 * a suspension until the session expires (CWE-613). That is the gap this fills
 * for endpoints that must stay reachable by anonymous callers and so cannot
 * take AdminAuthMiddleware, which would answer 401.
 *
 * Two deliberate differences from AdminAuthMiddleware:
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

    /**
     * Per-request memo keyed by user id, so a route carrying this middleware
     * alongside another role check pays one query. PHP is shared-nothing
     * (PHP-FPM included), so no verdict outlives the request that made it.
     *
     * @var array<int,bool>
     */
    private static array $cache = [];

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

    private function resolveOperator(): bool
    {
        $userId = isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : 0;
        if ($userId <= 0) {
            // Anonymous, or a session without a usable id. Not an error: this
            // endpoint is public and most of its traffic arrives this way.
            return false;
        }

        if (isset(self::$cache[$userId])) {
            return self::$cache[$userId];
        }

        $db = $this->resolveDb();
        if (!$db instanceof \mysqli) {
            // Do NOT cache: a later call in the same request may recover.
            \App\Support\SecureLogger::error('[SessionRoleRefreshMiddleware] Database unavailable; treating session as non-operator');
            return false;
        }

        try {
            $stmt = $db->prepare('SELECT tipo_utente, stato FROM utenti WHERE id = ? LIMIT 1');
            if ($stmt === false) {
                return false;
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = ($result instanceof \mysqli_result) ? $result->fetch_assoc() : null;
            $stmt->close();
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('[SessionRoleRefreshMiddleware] Role re-validation query failed; treating session as non-operator', ['exception' => $e->getMessage()]);
            return false;
        }

        if (!is_array($row)) {
            // The account is gone. Cache it: it will not reappear mid-request.
            self::$cache[$userId] = false;
            return false;
        }

        $role   = $row['tipo_utente'] ?? null;
        $active = ($row['stato'] ?? '') === 'attivo';

        // The read succeeded, so the session snapshot can be realigned — this
        // is the same write-back AdminAuthMiddleware performs, and it is what
        // keeps the inline role reads elsewhere honest for the rest of this
        // request. Only reached when the DB actually answered.
        $_SESSION['user']['tipo_utente'] = $role;
        $_SESSION['user']['stato'] = $row['stato'] ?? null;

        $isOperator = $active && in_array($role, self::OPERATOR_ROLES, true);
        self::$cache[$userId] = $isOperator;

        return $isOperator;
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

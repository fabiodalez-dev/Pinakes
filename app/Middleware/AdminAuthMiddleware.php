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

    /**
     * Per-request cache of DB re-validation results, keyed by user id.
     * PHP resets static state between requests (shared-nothing, incl. PHP-FPM),
     * so this never leaks a stale verdict across requests.
     *
     * @var array<int,bool>
     */
    private static array $revalidationCache = [];

    /** Lazily-opened shared mysqli handle for this request (see getDb()). */
    private static ?\mysqli $sharedDb = null;

    /** True once we've attempted to open the shared connection this request. */
    private static bool $dbInitAttempted = false;

    private ?\mysqli $db;

    /**
     * $db is optional: the app instantiates this middleware without arguments
     * (see app/Routes/web.php), so when none is injected we lazily open a
     * connection from the app's own DB config (see getDb()).
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

        // Check for admin or staff role (from the session snapshot set at login)
        $tipo_utente = $user['tipo_utente'] ?? null;
        if (!in_array($tipo_utente, self::ALLOWED_ROLES, true)) {
            return $this->denyRole($isApiRequest);
        }

        // Security scan F8 (CWE-613): the session role above is a stale snapshot
        // captured at login. Re-validate the user against the DB once per request
        // so demotions/suspensions take effect immediately instead of lingering
        // until the session expires. Fails CLOSED on any DB/lookup failure.
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
     * still holds an allowed role. The verdict is cached per-request per user id
     * so multiple admin-guarded middleware hits in one request issue one query.
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

        if (isset(self::$revalidationCache[$userId])) {
            return self::$revalidationCache[$userId];
        }

        $db = $this->getDb();
        if (!$db instanceof \mysqli) {
            // DB unreachable → fail closed (do NOT cache: a later hit may recover).
            \App\Support\SecureLogger::error('[AdminAuthMiddleware] Database unreachable during admin re-validation; denying access');
            return false;
        }

        try {
            $stmt = $db->prepare('SELECT tipo_utente, stato FROM utenti WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('[AdminAuthMiddleware] Admin re-validation query failed; denying access', ['exception' => $e->getMessage()]);
            return false;
        }

        $valid = is_array($row)
            && (($row['stato'] ?? '') === 'attivo')
            && in_array($row['tipo_utente'] ?? null, self::ALLOWED_ROLES, true);

        self::$revalidationCache[$userId] = $valid;
        return $valid;
    }

    /**
     * Obtain a mysqli handle: the injected one if present, otherwise a shared
     * connection lazily built from the app's own DB config (config/settings.php),
     * the same source config/container.php uses. Returns null on failure so the
     * caller can fail closed.
     */
    private function getDb(): ?\mysqli
    {
        if ($this->db instanceof \mysqli) {
            return $this->db;
        }

        if (self::$sharedDb instanceof \mysqli) {
            return self::$sharedDb;
        }

        if (self::$dbInitAttempted) {
            return null; // Already tried and failed this request.
        }
        self::$dbInitAttempted = true;

        $settingsPath = __DIR__ . '/../../config/settings.php';
        if (!is_file($settingsPath)) {
            return null;
        }

        try {
            $settings = require $settingsPath;
            $cfg = is_array($settings) ? ($settings['db'] ?? null) : null;
            if (!is_array($cfg)) {
                return null;
            }

            $hostname = (string) ($cfg['hostname'] ?? 'localhost');
            $username = (string) ($cfg['username'] ?? '');
            $password = (string) ($cfg['password'] ?? '');
            $database = (string) ($cfg['database'] ?? '');
            $port     = (int) ($cfg['port'] ?? 3306);
            $charset  = (string) ($cfg['charset'] ?? 'utf8mb4');
            $socket   = $cfg['socket'] ?? null;

            if ($database === '' || $username === '') {
                return null;
            }

            // Auto-detect a socket for localhost installs without an explicit one
            // (mirrors config/container.php).
            if (empty($socket) && $hostname === 'localhost') {
                foreach ([
                    '/tmp/mysql.sock',
                    '/var/run/mysqld/mysqld.sock',
                    '/var/lib/mysql/mysql.sock',
                    '/opt/homebrew/var/mysql/mysql.sock',
                    '/usr/local/var/mysql/mysql.sock',
                    '/Applications/MAMP/tmp/mysql/mysql.sock',
                    '/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock',
                ] as $candidate) {
                    if (file_exists($candidate)) {
                        $socket = $candidate;
                        break;
                    }
                }
            }

            // Ordered connection attempts: explicit/detected socket first, then TCP.
            $attempts = [];
            if (!empty($socket)) {
                $sock = (string) $socket;
                $attempts[] = static function () use ($username, $password, $database, $port, $sock): \mysqli {
                    return new \mysqli('localhost', $username, $password, $database, $port, $sock);
                };
            }
            $hostCandidates = ($hostname === 'localhost') ? ['127.0.0.1', 'localhost'] : [$hostname];
            foreach ($hostCandidates as $host) {
                $attempts[] = static function () use ($host, $username, $password, $database, $port): \mysqli {
                    return new \mysqli($host, $username, $password, $database, $port);
                };
            }

            $mysqli = null;
            foreach ($attempts as $attempt) {
                try {
                    $mysqli = $attempt();
                    break;
                } catch (\Throwable $connErr) {
                    $mysqli = null;
                    continue;
                }
            }

            if (!$mysqli instanceof \mysqli || $mysqli->connect_errno) {
                return null;
            }

            $mysqli->set_charset($charset);
            self::$sharedDb = $mysqli;
            return self::$sharedDb;
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('[AdminAuthMiddleware] Failed to open DB connection for admin re-validation', ['exception' => $e->getMessage()]);
            return null;
        }
    }
}

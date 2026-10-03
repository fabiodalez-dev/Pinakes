<?php
declare(strict_types=1);

namespace App\Support;

/**
 * The one place that decides whether the role stored in the session is still
 * true, by asking the database.
 *
 * `$_SESSION['user']['tipo_utente']` and `['stato']` are a snapshot taken at
 * login, and about sixty controllers and views read them directly. Those reads
 * are only trustworthy if something re-checks the snapshot before them on
 * every request; otherwise a demoted, suspended or deleted account keeps its
 * old powers until the session expires (CWE-613). Three middlewares do that
 * re-check — AdminAuthMiddleware, AuthMiddleware and
 * SessionRoleRefreshMiddleware — and all three go through this class, so there
 * is exactly one query, one cache and one definition of "still valid".
 *
 * Two things happen on a successful read, and both matter:
 *
 * 1. The caller gets the CURRENT role and state to decide on.
 * 2. The session snapshot is rewritten with them. That is what keeps every
 *    inline `$_SESSION['user']['tipo_utente'] === 'admin'` check further down
 *    the same request honest — a controller that re-checks the role for a
 *    destructive action reads the fresh value, not the login-time one.
 *
 * A failed read changes nothing in the session: turning a transient database
 * error into a rewritten (or cleared) session would log people out on a blip.
 * Each caller decides for itself whether a failed read denies the request
 * (the authorising middlewares) or merely withholds a privilege (the refresh
 * middleware on public routes).
 */
final class SessionRoleRevalidator
{
    /** No usable user id in the session: nothing to re-validate. */
    public const NO_SESSION = 'no_session';
    /** The account row was read. `role` and `stato` are current. */
    public const OK = 'ok';
    /** The account no longer exists. */
    public const ABSENT = 'absent';
    /** The database could not answer. Nothing is known. */
    public const ERROR = 'error';

    /**
     * Per-request memo keyed by user id. PHP is shared-nothing (PHP-FPM
     * included), so a verdict never outlives the request that produced it.
     * ERROR is never memoised: a later call in the same request may recover.
     *
     * @var array<int, array{status: string, role: ?string, stato: ?string}>
     */
    private static array $cache = [];

    /** Lazily-opened connection, shared by every caller in this request. */
    private static ?\mysqli $sharedDb = null;

    /** True once opening the shared connection has been attempted. */
    private static bool $dbInitAttempted = false;

    /**
     * Re-validate the account behind the current session.
     *
     * $db is optional: the middlewares are instantiated in app/Routes/web.php
     * without a connection, so when none is passed one is opened from the
     * application's own configuration.
     *
     * @return array{status: string, role: ?string, stato: ?string}
     */
    public static function resolve(?\mysqli $db = null): array
    {
        $userId = isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : 0;
        if ($userId <= 0) {
            return ['status' => self::NO_SESSION, 'role' => null, 'stato' => null];
        }

        if (isset(self::$cache[$userId])) {
            return self::$cache[$userId];
        }

        $db ??= self::openDb();
        if (!$db instanceof \mysqli) {
            SecureLogger::error('[SessionRoleRevalidator] Database unreachable during role re-validation');
            return ['status' => self::ERROR, 'role' => null, 'stato' => null];
        }

        try {
            $stmt = $db->prepare('SELECT tipo_utente, stato FROM utenti WHERE id = ? LIMIT 1');
            if ($stmt === false) {
                SecureLogger::error('[SessionRoleRevalidator] Role re-validation query could not be prepared', ['error' => $db->error]);
                return ['status' => self::ERROR, 'role' => null, 'stato' => null];
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = ($result instanceof \mysqli_result) ? $result->fetch_assoc() : null;
            $stmt->close();
        } catch (\Throwable $e) {
            SecureLogger::error('[SessionRoleRevalidator] Role re-validation query failed', ['exception' => $e->getMessage()]);
            return ['status' => self::ERROR, 'role' => null, 'stato' => null];
        }

        if (!is_array($row)) {
            return self::$cache[$userId] = ['status' => self::ABSENT, 'role' => null, 'stato' => null];
        }

        $role = isset($row['tipo_utente']) ? (string) $row['tipo_utente'] : null;
        $stato = isset($row['stato']) ? (string) $row['stato'] : null;

        // The database answered, so the snapshot can be realigned — see point 2
        // of the class docblock. Written only here, on a successful read.
        $_SESSION['user']['tipo_utente'] = $role;
        $_SESSION['user']['stato'] = $stato;

        return self::$cache[$userId] = ['status' => self::OK, 'role' => $role, 'stato' => $stato];
    }

    /**
     * True when the account exists, is active and holds one of $roles —
     * the question every authorising caller actually asks. Any failure to
     * establish that is false.
     *
     * @param list<string> $roles
     */
    public static function holdsActiveRole(array $roles, ?\mysqli $db = null): bool
    {
        $verdict = self::resolve($db);

        return $verdict['status'] === self::OK
            && $verdict['stato'] === 'attivo'
            && in_array($verdict['role'], $roles, true);
    }

    /**
     * Forget every memoised verdict. Production never needs this — each
     * request starts empty — but a test walking several identities, or
     * changing an account between checks, in one process does.
     */
    public static function reset(): void
    {
        self::$cache = [];
    }

    /**
     * Open a connection from config/settings.php, once per request.
     *
     * Moved here unchanged from AdminAuthMiddleware so the three callers share
     * it: socket first (explicit or auto-detected for localhost), then TCP.
     */
    private static function openDb(): ?\mysqli
    {
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
            SecureLogger::error('[SessionRoleRevalidator] Failed to open DB connection for role re-validation', ['exception' => $e->getMessage()]);
            return null;
        }
    }
}

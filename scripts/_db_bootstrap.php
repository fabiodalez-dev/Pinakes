<?php
declare(strict_types=1);

/**
 * Shared DB-from-env helper for cron scripts.
 *
 * The CLI cron scripts (bulk-enrich-cron.php, check-expired-reservations.php,
 * future ones) bypass the Slim/config/settings.php bootstrap and need a
 * minimal mysqli wired from .env. The logic for that — read DB_*, normalise
 * host when DB_SOCKET is set so mysqli actually honours the socket, then
 * connect — was duplicated verbatim across scripts. CodeRabbit round 8
 * flagged the drift risk; this helper centralises it.
 *
 * Caller is responsible for loading .env into $_ENV (typically via
 * `Dotenv::createImmutable($projectRoot)->load()`) BEFORE invoking this.
 */

use Dotenv\Dotenv;

if (!function_exists('pinakes_db_from_env')) {
    /**
     * Build a mysqli from the standard DB_* env vars.
     *
     * IMPORTANT: when DB_SOCKET is set we override $host to 'localhost'.
     * mysqli only honours the socket argument when the host string is
     * literally 'localhost' — any IP literal (127.0.0.1, ::1) silently
     * forces TCP and discards the socket.
     *
     * @throws \mysqli_sql_exception on connection failure (the caller
     *         decides how to log/abort).
     */
    function pinakes_db_from_env(): mysqli
    {
        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $user = $_ENV['DB_USER'] ?? 'root';
        $pass = $_ENV['DB_PASS'] ?? '';
        $name = $_ENV['DB_NAME'] ?? 'biblioteca';
        $port = (int) ($_ENV['DB_PORT'] ?? 3306);
        $sock = $_ENV['DB_SOCKET'] ?? null;

        if ($sock) {
            $host = 'localhost';
        }

        return new mysqli($host, $user, $pass, $name, $port, $sock ?: null);
    }
}

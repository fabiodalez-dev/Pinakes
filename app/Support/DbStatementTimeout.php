<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Per-session statement time limit for interactive web requests.
 *
 * Bounds every read query a web request runs (search amplification, heavy
 * catalogue filters) so a single request cannot hold a database thread for
 * minutes. The flavour of the server is detected once per connection:
 *
 *  - MySQL:   SET SESSION max_execution_time = <ms>    (SELECT statements only)
 *  - MariaDB: SET SESSION max_statement_time = <s>     (EVERY statement, writes too)
 *
 * Because the MariaDB variable also interrupts writes, the limit is only ever
 * applied on the web request path, never in CLI (cron, scripts, tests), and
 * long-running web operations (updater, installer, backup/restore, imports,
 * exports, maintenance, sitemap regeneration) are exempt by path. Classes that
 * run those operations call lift() on the connection they receive as a second
 * line of defence.
 *
 * Configuration: env DB_STATEMENT_TIMEOUT in seconds (0 disables). Without it
 * MySQL gets 30 s and MariaDB a more generous 60 s.
 *
 * Failure to set or detect the limit is logged and ignored: it must never
 * break the request.
 */
final class DbStatementTimeout
{
    public const DEFAULT_SECONDS_MYSQL = 30;
    public const DEFAULT_SECONDS_MARIADB = 60;

    /**
     * Path segments of web operations that legitimately run long statements.
     * Matched as a substring of any single path segment (e.g. "export-csv",
     * "export.xml", "import-sbn", "bulk-export", "restore-upload").
     */
    private const EXEMPT_SEGMENT_PATTERN = '#/[^/]*(import|export|backup|restore|sitemap)[^/]*(?=/|$)#i';

    /** Path prefixes (after any base path) that are exempt as a whole. */
    private const EXEMPT_AREA_PATTERN = '#(^|/)(installer|admin/updates|admin/maintenance)(/|$)#i';

    /** @var array<int, string> spl_object_id => variable name set on that connection */
    private static array $applied = [];

    /**
     * Apply the limit to a freshly opened web connection.
     *
     * @param string|null $requestPath request path; defaults to REQUEST_URI's path
     * @param bool|null   $isCli       injectable for tests; defaults to PHP_SAPI
     */
    public static function applyForWebRequest(\mysqli $db, ?string $requestPath = null, ?bool $isCli = null): void
    {
        $isCli ??= (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');
        if ($isCli) {
            return;
        }
        if ($requestPath === null) {
            $parsed = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            $requestPath = is_string($parsed) ? $parsed : '/';
        }
        if (self::isExemptPath($requestPath)) {
            return;
        }

        try {
            $version = '';
            $result = $db->query('SELECT VERSION()');
            if ($result instanceof \mysqli_result) {
                $row = $result->fetch_row();
                $version = is_array($row) ? (string) ($row[0] ?? '') : '';
                $result->free();
            }
            $sql = self::statementFor($version, self::configuredSeconds($version));
            if ($sql === null) {
                return;
            }
            $db->query($sql);
            self::$applied[spl_object_id($db)] = self::isMariaDb($version) ? 'max_statement_time' : 'max_execution_time';
        } catch (\Throwable $e) {
            SecureLogger::warning('[DbStatementTimeout] could not set the statement time limit', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remove the limit from a connection about to run a long operation
     * (updater, backup/restore). No query is sent unless the limit was set.
     */
    public static function lift(\mysqli $db): void
    {
        $id = spl_object_id($db);
        if (!isset(self::$applied[$id])) {
            return;
        }
        $variable = self::$applied[$id];
        try {
            $db->query('SET SESSION ' . $variable . ' = 0');
            unset(self::$applied[$id]);
        } catch (\Throwable $e) {
            SecureLogger::warning('[DbStatementTimeout] could not lift the statement time limit', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function isMariaDb(string $version): bool
    {
        return stripos($version, 'mariadb') !== false;
    }

    /**
     * Seconds to apply for the given server: env DB_STATEMENT_TIMEOUT when it
     * is a non-negative integer, otherwise the per-flavour default.
     */
    public static function configuredSeconds(string $version, ?string $envValue = null): int
    {
        if ($envValue === null) {
            $raw = $_ENV['DB_STATEMENT_TIMEOUT'] ?? getenv('DB_STATEMENT_TIMEOUT');
            $envValue = is_string($raw) ? $raw : '';
        }
        $envValue = trim($envValue);
        if ($envValue !== '' && preg_match('/^\d+$/D', $envValue) === 1) {
            return min((int) $envValue, 86400);
        }
        return self::isMariaDb($version) ? self::DEFAULT_SECONDS_MARIADB : self::DEFAULT_SECONDS_MYSQL;
    }

    /** The SET statement for this server flavour, or null when disabled. */
    public static function statementFor(string $version, int $seconds): ?string
    {
        if ($seconds <= 0) {
            return null;
        }
        if (self::isMariaDb($version)) {
            return 'SET SESSION max_statement_time = ' . $seconds;
        }
        return 'SET SESSION max_execution_time = ' . ($seconds * 1000);
    }

    public static function isExemptPath(string $path): bool
    {
        $path = '/' . ltrim($path, '/');
        return preg_match(self::EXEMPT_AREA_PATTERN, $path) === 1
            || preg_match(self::EXEMPT_SEGMENT_PATTERN, $path) === 1;
    }
}

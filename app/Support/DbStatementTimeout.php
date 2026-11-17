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
 * exports, maintenance, sitemap, harvesting protocols) are exempt by exact
 * route. Classes that run long work regardless of the route (updater, backups,
 * plugin activation, SchemaRepair, the admin-login maintenance run) call
 * lift() on the connection they receive.
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
     * Exact routes (path after the base path, anchored at both ends) of web
     * operations that legitimately run long statements. Listed one by one on
     * purpose: a substring match on any segment also exempted free-form
     * segments such as a book slug ("/libro/12/important-...").
     *
     * Plugin activation/installation, SchemaRepair and the admin-login
     * maintenance run do not depend on the path: they call lift() right
     * before their work.
     */
    private const EXEMPT_ROUTES = [
        // Long-running areas as a whole.
        'installer(?:/.*)?',
        'admin/updates(?:/.*)?',
        'admin/maintenance(?:/.*)?',
        // Imports.
        'admin/books/import(?:/.*)?',
        'admin/users/import(?:/.*)?',
        'admin/calendar/import-ics',
        'admin/archives/import',
        'admin/books/(?:bulk-)?import-sbn',
        'admin/books/import-unimarc',
        'admin/books/copy-cataloging/import',
        'api/dewey-editor/(?:import|export|restore)/[^/]+',
        // Exports.
        'admin/books/export/(?:csv|marc|librarything)',
        'admin/users/export/csv',
        'admin/users/export-pdf',
        'admin/loans/export-csv',
        'admin/attendance/export\.csv',
        'admin/inventory/\d+/report\.(?:csv|pdf)',
        'admin/imports/download-errors',
        'api/collocazione/export-csv',
        'api/(?:autori|editori)/bulk-export',
        'admin/archives/export\.(?:xml|ead3)',
        'admin/archives/(?:authorities/)?\d+/export\.xml',
        'admin/periodicals/export/(?:kbart|acnp)',
        'admin/books/\d+/export\.unimarc\.xml',
        // Sitemap.
        'sitemap\.xml',
        'admin/settings/advanced/regenerate-sitemap',
        // Harvesting protocols: whole-catalogue pages by design.
        'oai',
        'archives/oai',
        'resync/(?:capabilitylist|resourcelist|changelist)\.xml',
        '\.well-known/resourcesync',
        'api/sru',
        'api/archives/sru',
    ];

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
        $basePath = '';
        if ($requestPath === null) {
            $parsed = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            $requestPath = is_string($parsed) ? $parsed : '/';
            try {
                $basePath = HtmlHelper::getBasePath();
            } catch (\Throwable) {
                $basePath = '';
            }
        }
        if (self::isExemptPath($requestPath, $basePath)) {
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

    /**
     * @param string $path     request path, base path included
     * @param string $basePath the install's base path ("" at the domain root)
     */
    public static function isExemptPath(string $path, string $basePath = ''): bool
    {
        $path = '/' . ltrim($path, '/');
        $basePath = rtrim($basePath, '/');
        if ($basePath !== '') {
            if ($path !== $basePath && !str_starts_with($path, $basePath . '/')) {
                return false;
            }
            $path = '/' . ltrim(substr($path, strlen($basePath)), '/');
        }
        $route = rtrim(substr($path, 1), '/');
        foreach (self::EXEMPT_ROUTES as $pattern) {
            if (preg_match('#^' . $pattern . '$#iD', $route) === 1) {
                return true;
            }
        }
        return false;
    }
}

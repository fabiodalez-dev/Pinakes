<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Decides whether the current HTTP request needs a PHP session.
 *
 * Step 6 of the caching overhaul (issue #387): the anonymous public request
 * path must be sessionless so a later PR can put anonymous pages behind a
 * shared full-page/edge cache. A request is served WITHOUT a session only
 * when ALL of the following hold:
 *
 *   1. The method is read-only (GET/HEAD). Every state-changing method
 *      (POST/PUT/PATCH/DELETE/…) keeps the session so CSRF validation always
 *      has its session-backed token store — this also covers login and every
 *      other auth POST by construction.
 *   2. The request carries NO auth-related cookie: no session cookie
 *      (session_name()), no remember_token (persistent login) and no
 *      csrf_login (login form double-submit cookie). Any hint of an
 *      authenticated — or authenticating — browser keeps the exact current
 *      session behavior.
 *   3. The path is not an auth/contact route (in any registered locale).
 *      Those pages mint session-backed CSRF tokens into their HTML forms
 *      (login, register, forgot/reset password, contact) and must keep
 *      opening the session so the subsequent POST validates.
 *
 * Fail-safe direction: anything unknown (CLI, missing method, malformed
 * path) requires a session — correctness over cacheability.
 */
final class SessionPolicy
{
    /**
     * Route keys whose GET pages need a session (they render session-backed
     * CSRF form tokens, or belong to the authentication flow). Mirrors
     * PrivateModeMiddleware::AUTH_ROUTE_KEYS plus the contact form.
     *
     * @var string[]
     */
    private const SESSION_ROUTE_KEYS = [
        'login', 'logout', 'register', 'register_success',
        'verify_email', 'forgot_password', 'reset_password',
        'contact', 'contact_submit',
    ];

    /**
     * Legacy English aliases always registered in web.php regardless of the
     * install locale (see the login allow-list in PrivateModeMiddleware).
     *
     * @var string[]
     */
    private const LEGACY_SESSION_PATHS = ['/login', '/login.php', '/logout'];

    /** @var string[]|null Cached localized session-route prefixes. */
    private static ?array $sessionRoutesCache = null;

    /**
     * True when the request must be served with a PHP session (the default,
     * conservative outcome); false only for the anonymous no-cookie
     * read-only path described in the class docblock.
     *
     * @param string               $method   HTTP method ('' outside HTTP, e.g. CLI)
     * @param array<string, mixed> $cookies  Request cookies ($_COOKIE)
     * @param string               $path     Request URI path (no query string)
     * @param string|null          $basePath Base path override (null = autodetect)
     */
    public static function requiresSession(
        string $method,
        array $cookies,
        string $path,
        ?string $basePath = null
    ): bool {
        // 1. Only read-only methods can be sessionless. CLI/unknown ('') and
        //    every state-changing method keep the session.
        if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            return true;
        }

        // 2. Any auth-related cookie keeps the exact current behavior.
        if (isset($cookies[session_name()])) {
            return true;
        }
        if (isset($cookies['remember_token'])) {
            return true;
        }
        if (isset($cookies['csrf_login'])) {
            return true;
        }

        // 3. Auth/contact routes mint session-backed CSRF form tokens.
        return self::isSessionRoute($path, $basePath);
    }

    /**
     * True when the path matches an auth/contact route in any registered
     * locale (or a legacy English alias), after stripping the base path of
     * sub-folder installs.
     */
    private static function isSessionRoute(string $path, ?string $basePath = null): bool
    {
        $basePath = $basePath ?? HtmlHelper::getBasePath();
        $basePath = rtrim($basePath, '/');
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        foreach (self::sessionRoutes() as $route) {
            if ($path === $route || str_starts_with($path, $route . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * All localized variants of the session-requiring routes, computed from
     * the locale route files on disk (file-based, safe before the DB/container
     * are available) plus the static English fallbacks and legacy aliases.
     *
     * @return string[]
     */
    private static function sessionRoutes(): array
    {
        if (self::$sessionRoutesCache !== null) {
            return self::$sessionRoutesCache;
        }

        $routes = [];
        foreach (self::registeredLocales() as $locale) {
            foreach (self::SESSION_ROUTE_KEYS as $key) {
                $route = RouteTranslator::getRouteForLocale($key, $locale);
                if ($route !== '' && $route !== '/') {
                    $routes[rtrim($route, '/')] = true;
                }
            }
        }
        foreach (self::LEGACY_SESSION_PATHS as $legacy) {
            $routes[$legacy] = true;
        }

        self::$sessionRoutesCache = array_keys($routes);
        return self::$sessionRoutesCache;
    }

    /**
     * Locales that have a route-translation file on disk. Falls back to the
     * locales whose route variants web.php always registers.
     *
     * @return string[]
     */
    private static function registeredLocales(): array
    {
        $locales = [];
        $files = glob(__DIR__ . '/../../locale/routes_*.json');
        foreach (($files === false ? [] : $files) as $file) {
            if (preg_match('/routes_([A-Za-z]{2}_[A-Za-z]{2})\.json$/', $file, $m) === 1) {
                $locales[] = $m[1];
            }
        }
        if ($locales === []) {
            $locales = ['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'];
        }
        return $locales;
    }

    /**
     * Test hook: clear the computed route cache.
     */
    public static function clearCache(): void
    {
        self::$sessionRoutesCache = null;
    }
}

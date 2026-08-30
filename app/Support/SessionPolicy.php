<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Decides whether the current HTTP request needs a PHP session.
 *
 * Only explicitly known, read-only public routes may skip session_start().
 * Keeping this as an allow-list is intentional: plugin routes and future
 * pages may render session-backed forms or consume flash/auth state, so an
 * unknown route must remain sessionful until it is audited.
 */
final class SessionPolicy
{
    /** Exact Slim pattern for the public canonical book-detail route. */
    private const CANONICAL_BOOK_PATTERN = '/{authorSlug}/{bookSlug}/{id:\\d+}';

    /**
     * Audited public route families that do not require server-side session
     * state for an anonymous GET/HEAD. A translated route also matches its
     * descendants (for example /book/42 or /events/summer-reading).
     *
     * @var string[]
     */
    private const SESSIONLESS_ROUTE_KEYS = [
        'catalog', 'catalog_legacy',
        'book', 'book_legacy',
        'author', 'publisher', 'genre',
        'events',
        'about', 'privacy', 'cookies',
        'api_catalog', 'api_book', 'api_home', 'api_edge_availability',
    ];

    /**
     * Non-translated public endpoints audited for sessionless access.
     * Descendant matching is enabled only for entries ending in a slash.
     *
     * @var string[]
     */
    private const SESSIONLESS_PATHS = [
        '/',
        '/home.php',
        '/health',
        '/csrf-token',
        '/robots.txt',
        '/sitemap.xml',
        '/feed.xml',
        '/llms.txt',
        '/language/',
        // Only the genuinely public /uploads subtrees are sessionless. The
        // private ones — /uploads/digital/, /uploads/archives/documents/,
        // /uploads/storage/ — are deliberately NOT listed: PrivateModeMiddleware
        // keeps them behind the login wall, and a blanket '/uploads/' would stamp
        // them session-free / cache-eligible, which the later shared edge cache
        // would turn into unauthenticated content disclosure. Mirrors
        // PrivateModeMiddleware::ALLOWED_PREFIXES (which exposes only settings).
        '/uploads/copertine/', // public book covers (catalog + book page)
        '/uploads/autori/',    // public author photos
        '/uploads/settings/',  // admin-uploaded branding/logo
        '/proxy/cover',
    ];

    /** @var string[]|null Cached localized public-route prefixes. */
    private static ?array $sessionlessRoutesCache = null;

    /**
     * True when the request must be served with a PHP session (the default,
     * conservative outcome); false only for an audited anonymous public path.
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
        if (self::requiresEarlySession($method, $cookies)) {
            return true;
        }

        return !self::isSessionlessRoute($path, $basePath);
    }

    /** Mutations and authentication state must have a session before bootstrap. */
    public static function requiresEarlySession(string $method, array $cookies): bool
    {
        if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            return true;
        }

        return isset($cookies[session_name()])
            || isset($cookies['remember_token'])
            || isset($cookies['csrf_login']);
    }

    /**
     * Final decision once Slim has resolved the exact route pattern.
     * Unknown/plugin routes remain sessionful; only the canonical pattern is
     * added to the existing path-based public allow-list.
     */
    public static function requiresRoutedSession(
        string $method,
        array $cookies,
        string $path,
        ?string $routePattern,
        ?string $basePath = null
    ): bool {
        if (self::requiresEarlySession($method, $cookies)) {
            return true;
        }

        if ($routePattern === self::CANONICAL_BOOK_PATTERN) {
            return false;
        }

        return self::requiresSession($method, $cookies, $path, $basePath);
    }

    /**
     * Match only audited public routes after safely removing a subfolder base
     * path. The boundary check prevents /pinakes-other from being mistaken for
     * an installation mounted at /pinakes.
     */
    private static function isSessionlessRoute(string $path, ?string $basePath = null): bool
    {
        $basePath = rtrim($basePath ?? HtmlHelper::getBasePath(), '/');
        if ($basePath !== '') {
            if ($path === $basePath) {
                $path = '/';
            } elseif (str_starts_with($path, $basePath . '/')) {
                $path = substr($path, strlen($basePath));
            } else {
                return false;
            }
        }

        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        foreach (self::SESSIONLESS_PATHS as $publicPath) {
            if ($publicPath === '/') {
                if ($path === '/') {
                    return true;
                }
            } elseif (str_ends_with($publicPath, '/')) {
                $prefix = rtrim($publicPath, '/');
                if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                    return true;
                }
            } elseif ($path === $publicPath) {
                return true;
            }
        }

        foreach (self::sessionlessRoutes() as $route) {
            if ($path === $route || str_starts_with($path, $route . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build every localized variant directly from route files, before the
     * application container/database is available.
     *
     * @return string[]
     */
    private static function sessionlessRoutes(): array
    {
        if (self::$sessionlessRoutesCache !== null) {
            return self::$sessionlessRoutesCache;
        }

        $routes = [];
        foreach (self::registeredLocales() as $locale) {
            foreach (self::SESSIONLESS_ROUTE_KEYS as $key) {
                $route = RouteTranslator::getRouteForLocale($key, $locale);
                if ($route !== '' && $route !== '/') {
                    $routes[rtrim($route, '/')] = true;
                }
            }
        }

        self::$sessionlessRoutesCache = array_keys($routes);
        return self::$sessionlessRoutesCache;
    }

    /** @return string[] */
    private static function registeredLocales(): array
    {
        $locales = [];
        $files = glob(__DIR__ . '/../../locale/routes_*.json');
        foreach (($files === false ? [] : $files) as $file) {
            if (preg_match('/routes_([A-Za-z]{2}_[A-Za-z]{2})\.json$/', $file, $matches) === 1) {
                $locales[] = $matches[1];
            }
        }

        return $locales !== [] ? $locales : ['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'];
    }

    /** Test hook: clear the computed route cache. */
    public static function clearCache(): void
    {
        self::$sessionlessRoutesCache = null;
    }
}

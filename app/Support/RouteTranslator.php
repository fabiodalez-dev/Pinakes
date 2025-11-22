<?php

declare(strict_types=1);

namespace App\Support;

/**
 * RouteTranslator - Manages translated route patterns
 *
 * Provides locale-aware route patterns based on the current language.
 * Routes are loaded from JSON files (locale/routes_{locale}.json) with
 * automatic fallback to default English routes if translation is missing.
 *
 * Example usage:
 *   RouteTranslator::route('catalog');  // Returns '/catalogo' for IT, '/catalog' for EN
 *   RouteTranslator::route('book');     // Returns legacy base '/libro' for IT, '/book' for EN
 */
class RouteTranslator
{
    /**
     * In-memory cache of loaded routes
     * @var array<string, array<string, string>>
     */
    private static array $cache = [];

    /**
     * Default fallback routes (English)
     * Used when translation is missing from JSON file
     * @var array<string, string>
     */
    private static array $fallbackRoutes = [
        'login' => '/login',
        'logout' => '/logout',
        'register' => '/register',
        'register_success' => '/register/success',
        'verify_email' => '/verify-email',
        'forgot_password' => '/forgot-password',
        'reset_password' => '/reset-password',
        'profile' => '/profile',
        'profile_update' => '/profile/update',
        'profile_password' => '/profile/password',
        'user_dashboard' => '/user/dashboard',
        'wishlist' => '/wishlist',
        'reservations' => '/reservations',
        'catalog' => '/catalog',
        'catalog_legacy' => '/catalog.php',
        'book' => '/book',
        'book_legacy' => '/book-detail.php',
        'author' => '/author',
        'publisher' => '/publisher',
        'genre' => '/genre',
        'about' => '/about-us',
        'contact' => '/contact',
        'contact_submit' => '/contact/submit',
        'privacy' => '/privacy-policy',
        'cookies' => '/cookie-policy',
        'api_catalog' => '/api/catalog',
        'api_book' => '/api/book',
        'api_home' => '/api/home',
        'language_switch' => '/language',
    ];

    /**
     * Get translated route pattern for the current session locale
     *
     * IMPORTANT: Uses the current session locale, so routes change
     * dynamically when the user switches language. This allows multiple
     * route variants (e.g., legacy /libro/123 and /book/123) to point to the
     * same content.
     *
     * @param string $key Route key (e.g., 'catalog', 'book', 'login')
     * @return string Route pattern (e.g., '/catalogo', legacy '/libro', '/accedi')
     */
    public static function route(string $key): string
    {
        // Use current session locale (changes when user switches language)
        $locale = I18n::getLocale();
        $routes = self::loadRoutes($locale);

        // Return translated route if exists
        if (isset($routes[$key])) {
            return $routes[$key];
        }

        // Fallback to default English route
        return self::fallback($key);
    }

    /**
     * Get translated route pattern for a specific locale
     *
     * Used to register multiple route variants in web.php that point
     * to the same controller (e.g., legacy /libro/123 and /book/123).
     *
     * @param string $key Route key (e.g., 'catalog', 'book', 'login')
     * @param string $locale Specific locale code (e.g., 'it_IT', 'en_US')
     * @return string Route pattern for the specified locale
     */
    public static function getRouteForLocale(string $key, string $locale): string
    {
        $routes = self::loadRoutes($locale);

        // Return translated route if exists
        if (isset($routes[$key])) {
            return $routes[$key];
        }

        // Fallback to default English route
        return self::fallback($key);
    }

    /**
     * Load routes from JSON file for given locale
     *
     * @param string $locale Locale code (e.g., 'it_IT', 'en_US')
     * @return array<string, string> Route key => pattern map
     */
    private static function loadRoutes(string $locale): array
    {
        // Return from cache if already loaded
        if (isset(self::$cache[$locale])) {
            return self::$cache[$locale];
        }

        // Construct file path
        $filePath = __DIR__ . '/../../locale/routes_' . $locale . '.json';

        // If file doesn't exist, return empty array (will use fallback)
        if (!file_exists($filePath)) {
            self::$cache[$locale] = [];
            return [];
        }

        // Read and decode JSON
        $jsonContent = file_get_contents($filePath);
        if ($jsonContent === false) {
            self::$cache[$locale] = [];
            return [];
        }

        $decoded = json_decode($jsonContent, true);
        if (!is_array($decoded)) {
            self::$cache[$locale] = [];
            return [];
        }

        // Validate all values start with /
        $validated = [];
        foreach ($decoded as $key => $value) {
            if (is_string($value) && str_starts_with($value, '/')) {
                $validated[$key] = $value;
            }
        }

        // Cache and return
        self::$cache[$locale] = $validated;
        return $validated;
    }

    /**
     * Get fallback route when translation is missing
     *
     * @param string $key Route key
     * @return string Fallback route pattern (English)
     */
    private static function fallback(string $key): string
    {
        return self::$fallbackRoutes[$key] ?? '/' . str_replace('_', '-', $key);
    }

    /**
     * Get all available route keys
     *
     * @return array<int, string> List of route keys
     */
    public static function getAvailableKeys(): array
    {
        return array_keys(self::$fallbackRoutes);
    }

    /**
     * Get all routes for a specific locale
     *
     * @param string $locale Locale code
     * @return array<string, string> Route key => pattern map
     */
    public static function getAllRoutes(string $locale): array
    {
        $routes = self::loadRoutes($locale);
        $result = [];

        // Merge fallback routes with locale-specific routes
        foreach (self::$fallbackRoutes as $key => $fallbackValue) {
            $result[$key] = $routes[$key] ?? $fallbackValue;
        }

        return $result;
    }

    /**
     * Clear route cache (useful for testing or after updating route files)
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Check if a route key exists
     *
     * @param string $key Route key
     * @return bool True if key exists in fallback routes
     */
    public static function hasKey(string $key): bool
    {
        return isset(self::$fallbackRoutes[$key]);
    }
}

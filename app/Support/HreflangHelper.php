<?php
declare(strict_types=1);

namespace App\Support;

use App\Controllers\SeoController;

/**
 * Generates hreflang alternate URLs for the current page.
 *
 * Given the current REQUEST_URI, this class produces alternate URLs
 * for every active locale so search engines and LLMs can link
 * language variants together.
 */
class HreflangHelper
{
    /** @var array<string, array<string, string>> Cached reverse maps per locale */
    private static array $reverseMapCache = [];

    /** @var ?array<int, string> Cached route keys */
    private static ?array $allKeysCache = null;

    /**
     * Get hreflang alternate links for the current URL.
     *
     * @return array<int, array{hreflang: string, href: string}>
     */
    public static function getAlternates(): array
    {
        $locales = I18n::getAvailableLocales();
        if (count($locales) < 2) {
            return [];
        }

        $defaultLocale = I18n::getInstallationLocale();
        $basePath = HtmlHelper::getBasePath();
        $baseUrl = SeoController::resolveBaseUrl();

        // Current request path, stripped of query string and base path
        $rawUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $requestUri = strtok($rawUri, '?') ?: '/';

        // Strip base path prefix
        $corePath = $requestUri;
        if ($basePath !== '' && str_starts_with($corePath, $basePath)) {
            $corePath = substr($corePath, strlen($basePath)) ?: '/';
        }

        // Detect current locale from path prefix and strip it
        $currentLocale = $defaultLocale;
        foreach ($locales as $localeCode => $langName) {
            if ($localeCode === $defaultLocale) {
                continue;
            }
            $prefix = '/' . strtolower(substr($localeCode, 0, 2));
            if ($corePath === $prefix || str_starts_with($corePath, $prefix . '/')) {
                $currentLocale = $localeCode;
                $corePath = substr($corePath, strlen($prefix)) ?: '/';
                break;
            }
        }

        // Build reverse map: translated path => route key (for the current locale)
        $reverseMap = self::buildReverseMap($currentLocale);

        // Try to match corePath against known routes
        $matchedKey = null;
        $suffix = '';
        foreach ($reverseMap as $routePath => $routeKey) {
            if ($corePath === $routePath) {
                $matchedKey = $routeKey;
                $suffix = '';
                break;
            }
            // Prefix match for entity routes (e.g. /autore/Name or /eventi/slug)
            if (str_starts_with($corePath, $routePath . '/')) {
                $matchedKey = $routeKey;
                $suffix = substr($corePath, strlen($routePath));
                break;
            }
        }

        $alternates = [];
        foreach ($locales as $localeCode => $langName) {
            $langCode = strtolower(substr($localeCode, 0, 2));
            $localePrefix = ($localeCode === $defaultLocale) ? '' : '/' . $langCode;

            if ($matchedKey !== null) {
                $translatedPath = RouteTranslator::getRouteForLocale($matchedKey, $localeCode);
                $fullPath = $localePrefix . $translatedPath . $suffix;
            } else {
                // No known route matched — keep path as-is, just swap prefix
                $fullPath = $localePrefix . $corePath;
            }

            $alternates[] = [
                'hreflang' => $langCode,
                'href' => $baseUrl . $fullPath,
            ];
        }

        // Add x-default pointing to the default locale version
        $defaultLangCode = strtolower(substr($defaultLocale, 0, 2));
        foreach ($alternates as $alt) {
            if ($alt['hreflang'] === $defaultLangCode) {
                $alternates[] = [
                    'hreflang' => 'x-default',
                    'href' => $alt['href'],
                ];
                break;
            }
        }

        return $alternates;
    }

    /**
     * Build a reverse map from translated route path => route key
     * for the current locale, sorted longest-first for greedy matching.
     *
     * @return array<string, string> path => route key
     */
    private static function buildReverseMap(string $currentLocale): array
    {
        if (isset(self::$reverseMapCache[$currentLocale])) {
            return self::$reverseMapCache[$currentLocale];
        }

        $reverseMap = [];

        // Get all route keys from JSON + fallback
        $allKeys = self::getAllRouteKeys();

        foreach ($allKeys as $key) {
            $path = RouteTranslator::getRouteForLocale($key, $currentLocale);
            // Skip API and admin routes — only map public-facing routes
            if (str_starts_with($path, '/api/') || str_starts_with($path, '/admin/')) {
                continue;
            }
            $reverseMap[$path] = $key;
        }

        // Sort by path length descending (longest first for greedy prefix matching)
        uksort($reverseMap, function (string $a, string $b): int {
            return strlen($b) <=> strlen($a);
        });

        self::$reverseMapCache[$currentLocale] = $reverseMap;
        return $reverseMap;
    }

    /**
     * Get all route keys from JSON files + fallback routes.
     *
     * @return array<int, string>
     */
    private static function getAllRouteKeys(): array
    {
        if (self::$allKeysCache !== null) {
            return self::$allKeysCache;
        }

        $keys = RouteTranslator::getAvailableKeys();

        // Also scan JSON files for keys not in fallbackRoutes (e.g. "events")
        $localeDir = __DIR__ . '/../../locale';
        $pattern = $localeDir . '/routes_*.json';
        foreach (glob($pattern) ?: [] as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                error_log('HreflangHelper: could not read route file: ' . $file);
                continue;
            }
            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                error_log('HreflangHelper: invalid JSON in route file: ' . $file . ' - ' . json_last_error_msg());
                continue;
            }
            foreach (array_keys($decoded) as $key) {
                if (is_string($key) && !in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
        }

        self::$allKeysCache = $keys;
        return $keys;
    }
}

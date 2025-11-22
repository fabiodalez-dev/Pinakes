<?php
declare(strict_types=1);

namespace App\Support;

/**
 * CMS Helper - Multilingual slug management for CMS pages
 */
final class CmsHelper
{
    /**
     * Map of CMS page identifiers to their slugs by locale
     *
     * @var array<string, array<string, string>>
     */
    private static array $slugMap = [
        'about' => [
            'it_IT' => 'chi-siamo',
            'en_US' => 'about-us',
        ],
        'contact' => [
            'it_IT' => 'contatti',
            'en_US' => 'contact',
        ],
        'privacy' => [
            'it_IT' => 'privacy',
            'en_US' => 'privacy',
        ],
    ];

    /**
     * Get the correct slug for a CMS page based on current locale
     *
     * @param string $pageId Page identifier (e.g., 'about', 'contact')
     * @param string|null $locale Optional locale override
     * @return string The localized slug
     */
    public static function getSlug(string $pageId, ?string $locale = null): string
    {
        $locale = $locale ?? I18n::getLocale();

        // Normalize locale
        $locale = self::normalizeLocale($locale);

        // Return mapped slug or fallback to page ID
        return self::$slugMap[$pageId][$locale] ?? self::$slugMap[$pageId]['it_IT'] ?? $pageId;
    }

    /**
     * Get page ID from slug (reverse lookup)
     *
     * @param string $slug The slug to lookup
     * @return string|null The page ID or null if not found
     */
    public static function getPageIdFromSlug(string $slug): ?string
    {
        foreach (self::$slugMap as $pageId => $locales) {
            foreach ($locales as $locale => $mappedSlug) {
                if ($mappedSlug === $slug) {
                    return $pageId;
                }
            }
        }

        return null;
    }

    /**
     * Get the correct slug for the current locale
     * If the given slug is in a different language, return the current locale version
     *
     * @param string $slug Current slug
     * @param string|null $locale Optional locale override
     * @return string The correct slug for current locale, or original if no mapping
     */
    public static function getLocalizedSlug(string $slug, ?string $locale = null): string
    {
        $pageId = self::getPageIdFromSlug($slug);

        if ($pageId === null) {
            return $slug; // No mapping found, return original
        }

        return self::getSlug($pageId, $locale);
    }

    /**
     * Check if a slug needs to be redirected to its localized version
     *
     * @param string $slug The slug to check
     * @param string|null $locale Optional locale override
     * @return string|null The correct slug if redirect needed, null otherwise
     */
    public static function getRedirectSlug(string $slug, ?string $locale = null): ?string
    {
        $correctSlug = self::getLocalizedSlug($slug, $locale);

        return $correctSlug !== $slug ? $correctSlug : null;
    }

    /**
     * Get CMS page URL (frontend)
     *
     * @param string $pageId Page identifier (e.g., 'about')
     * @param string|null $locale Optional locale override
     * @return string The full URL path
     */
    public static function getUrl(string $pageId, ?string $locale = null): string
    {
        $slug = self::getSlug($pageId, $locale);
        return '/' . $slug;
    }

    /**
     * Get admin edit URL for CMS page
     *
     * @param string $pageId Page identifier (e.g., 'about')
     * @param string|null $locale Optional locale override
     * @return string The admin edit URL
     */
    public static function getAdminUrl(string $pageId, ?string $locale = null): string
    {
        $slug = self::getSlug($pageId, $locale);
        return '/admin/cms/' . $slug;
    }

    /**
     * Normalize locale code
     *
     * @param string $locale Locale code (e.g., 'it', 'it_IT', 'en', 'en_US')
     * @return string Normalized locale (e.g., 'it_IT', 'en_US')
     */
    private static function normalizeLocale(string $locale): string
    {
        $locale = str_replace('-', '_', $locale);

        $map = [
            'it' => 'it_IT',
            'en' => 'en_US',
        ];

        return $map[$locale] ?? $locale;
    }

    /**
     * Get all available slugs for a page ID across all locales
     *
     * @param string $pageId Page identifier
     * @return array<string, string> Array of locale => slug
     */
    public static function getAllSlugs(string $pageId): array
    {
        return self::$slugMap[$pageId] ?? [];
    }

    /**
     * Check if a page ID exists in the slug map
     *
     * @param string $pageId Page identifier
     * @return bool True if page exists
     */
    public static function pageExists(string $pageId): bool
    {
        return isset(self::$slugMap[$pageId]);
    }
}

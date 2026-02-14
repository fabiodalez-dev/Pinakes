<?php
declare(strict_types=1);

namespace App\Support;

final class Branding
{
    public const DEFAULT_LOGO = '/assets/brand/logo_small.png';
    public const DEFAULT_FULL_LOGO = '/assets/brand/logo.png';
    public const DEFAULT_SOCIAL_IMAGE = '/assets/brand/social.jpg';

    /**
     * Return the configured logo or the default brand asset.
     */
    public static function logo(): string
    {
        $configured = (string)ConfigStore::get('app.logo', '');

        if ($configured !== '' && self::assetExists($configured)) {
            return HtmlHelper::getBasePath() . $configured;
        }

        return self::assetExists(self::DEFAULT_LOGO) ? HtmlHelper::getBasePath() . self::DEFAULT_LOGO : '';
    }

    /**
     * High resolution logo (used by installer / hero sections).
     * Respects user-configured logo, falls back to default full logo.
     */
    public static function fullLogo(): string
    {
        $basePath = HtmlHelper::getBasePath();

        // First check for user-configured logo
        $configured = (string)ConfigStore::get('app.logo', '');
        if ($configured !== '' && self::assetExists($configured)) {
            return $basePath . $configured;
        }

        // Fall back to default full logo
        if (self::assetExists(self::DEFAULT_FULL_LOGO)) {
            return $basePath . self::DEFAULT_FULL_LOGO;
        }

        // Final fallback to small logo
        return self::assetExists(self::DEFAULT_LOGO) ? $basePath . self::DEFAULT_LOGO : '';
    }

    /**
     * Default image for Open Graph / social cards.
     */
    public static function socialImage(): string
    {
        if (self::assetExists(self::DEFAULT_SOCIAL_IMAGE)) {
            return HtmlHelper::getBasePath() . self::DEFAULT_SOCIAL_IMAGE;
        }

        return self::logo();
    }

    /**
     * Checks whether a given public asset exists.
     */
    private static function assetExists(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // Absolute URLs are assumed to be valid
        if (preg_match('/^https?:\\/\\//i', $path)) {
            return true;
        }

        $parsed = parse_url($path, PHP_URL_PATH) ?? $path;
        if ($parsed === '') {
            return false;
        }

        $publicDir = realpath(__DIR__ . '/../../public');
        if ($publicDir === false) {
            return false;
        }

        $normalized = '/' . ltrim($parsed, '/');
        $absolute = realpath($publicDir . $normalized) ?: ($publicDir . $normalized);
        return is_file($absolute);
    }
}

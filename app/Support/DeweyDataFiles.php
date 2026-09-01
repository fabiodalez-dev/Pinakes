<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Single source of truth for locale-aware Dewey JSON paths.
 *
 * New writes use the full locale (it_IT, en_US, ...). Reads prefer that file
 * and fall back to the legacy language-only filename so upgrades remain
 * compatible until the first write migrates a locale.
 */
final class DeweyDataFiles
{
    public static function canonicalPath(string $locale): string
    {
        return self::dataDir() . '/dewey_completo_' . self::normalizeLocale($locale) . '.json';
    }

    public static function resolveReadPath(string $locale, ?string $fallbackLocale = null): string
    {
        $locale = self::normalizeLocale($locale);
        $canonical = self::canonicalPath($locale);
        if (is_file($canonical)) {
            return $canonical;
        }

        $legacy = self::dataDir() . '/dewey_completo_' . substr($locale, 0, 2) . '.json';
        if (is_file($legacy)) {
            return $legacy;
        }

        // Very old installations used these pre-"completo" filenames. Keep
        // the core API's historical last-resort compatibility while all new
        // writes continue to migrate into the full-locale canonical file.
        $language = substr($locale, 0, 2);
        $historicName = match ($language) {
            'it' => 'dewey.json',
            'en' => 'dewey_en.json',
            default => null,
        };
        if ($historicName !== null) {
            $historic = self::dataDir() . '/' . $historicName;
            if (is_file($historic)) {
                return $historic;
            }
        }

        if ($fallbackLocale !== null) {
            return self::resolveReadPath($fallbackLocale);
        }

        return $canonical;
    }

    private static function dataDir(): string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        return $basePath . '/data/dewey';
    }

    private static function normalizeLocale(string $locale): string
    {
        $locale = str_replace('-', '_', trim($locale));
        if (preg_match('/^([A-Za-z]{2,3})(?:_([A-Za-z]{2}))?$/', $locale, $matches) !== 1) {
            return 'it_IT';
        }

        $language = strtolower($matches[1]);
        return isset($matches[2]) ? $language . '_' . strtoupper($matches[2]) : $language;
    }
}

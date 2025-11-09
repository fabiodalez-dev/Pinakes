<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Internationalization (i18n) Support
 *
 * Provides translation functions for multilingual support.
 * Currently uses a simple no-op implementation that returns the original string.
 *
 * Future enhancement: Integrate with gettext (.po/.mo files) for full i18n support.
 *
 * @package App\Support
 */
final class I18n
{
    private const LOCALE_PATTERN = '/^[a-z]{2}_[A-Z]{2}$/';
    /**
     * Current locale (default: Italian)
     */
    private static string $locale = 'it_IT';

    /**
     * Installation locale (fixed at installation time, never changes)
     * This is the default locale from the database (is_default=1)
     */
    private static string $installationLocale = 'it_IT';

    /**
     * Available locales (fallback/default when DB not available)
     */
    private static array $availableLocales = [
        'it_IT' => 'Italiano',
        'en_US' => 'English',
    ];

    /**
     * Languages cache loaded from database
     */
    private static ?array $languagesCache = null;

    /**
     * Flag to track if languages have been loaded from database
     */
    private static bool $languagesLoadedFromDb = false;

    /**
     * Translation cache
     */
    private static array $translations = [];

    /**
     * Flag to track if translations have been loaded
     */
    private static bool $translationsLoaded = false;

    /**
     * Load languages from database
     *
     * This method should be called during application bootstrap to load
     * available languages from the database. Falls back to hardcoded locales
     * if database is not available.
     *
     * @param \mysqli $db Database connection
     * @return bool True if languages loaded successfully
     */
    public static function loadFromDatabase(\mysqli $db): bool
    {
        if (self::$languagesLoadedFromDb) {
            return true; // Already loaded
        }

        try {
            // Query active languages from database
            $result = $db->query("
                SELECT code, native_name, is_default
                FROM languages
                WHERE is_active = 1
                ORDER BY is_default DESC, code ASC
            ");

            if (!$result) {
                return false; // Query failed, use fallback
            }

            $languages = [];
            $defaultLocale = null;

            while ($row = $result->fetch_assoc()) {
                $code = self::normalizeLocaleCode((string)$row['code']);

                if (!self::isValidLocaleCode($code)) {
                    continue; // Skip invalid locale codes to prevent injection
                }

                $languages[$code] = $row['native_name'];

                if ($row['is_default']) {
                    $defaultLocale = $code;
                }
            }

            if (!empty($languages)) {
                self::$languagesCache = $languages;
                self::$languagesLoadedFromDb = true;

                // Set default locale from database (installation locale)
                if ($defaultLocale) {
                    self::$locale = $defaultLocale;
                    self::$installationLocale = $defaultLocale;
                }

                return true;
            }

            return false;
        } catch (\Exception $e) {
            // If database query fails, fall back to hardcoded locales
            return false;
        }
    }

    /**
     * Load translations from JSON file for current locale
     */
    private static function loadTranslations(): void
    {
        if (self::$translationsLoaded) {
            return;
        }

        $locale = self::$locale;
        $translationFile = __DIR__ . '/../../locale/' . $locale . '.json';

        if (file_exists($translationFile)) {
            $json = file_get_contents($translationFile);
            $decoded = json_decode($json, true);

            if (is_array($decoded)) {
                self::$translations = $decoded;
            }
        }

        self::$translationsLoaded = true;
    }

    /**
     * Translate a string
     *
     * This is the main translation function. Looks up translations from JSON files.
     *
     * Usage:
     *   I18n::translate('Hello World');
     *   I18n::translate('Welcome %s', 'John');
     *   I18n::translate('You have %d messages', 5);
     *
     * @param string $message The message to translate
     * @param mixed ...$args Optional arguments for sprintf formatting
     * @return string Translated message
     */
    public static function translate(string $message, ...$args): string
    {
        // Load translations if not already loaded
        self::loadTranslations();

        // Look for translation in current locale
        $translated = self::$translations[$message] ?? $message;

        // Apply sprintf formatting if args provided
        if (empty($args)) {
            return $translated;
        }

        return sprintf($translated, ...$args);
    }

    /**
     * Set the current locale
     *
     * @param string $locale Locale code (e.g., 'it_IT', 'en_US')
     * @return bool True if locale was set successfully, false otherwise
     */
    public static function setLocale(string $locale): bool
    {
        $locale = self::normalizeLocaleCode($locale);

        // Check against database languages if loaded, otherwise use fallback
        $availableLocales = self::$languagesLoadedFromDb && self::$languagesCache !== null
            ? self::$languagesCache
            : self::$availableLocales;

        if (!isset($availableLocales[$locale])) {
            return false;
        }

        self::$locale = $locale;

        // Reset translations cache to force reload for new locale
        self::$translations = [];
        self::$translationsLoaded = false;

        return true;
    }

    /**
     * Get current locale
     *
     * @return string Current locale code
     */
    public static function getLocale(): string
    {
        return self::$locale;
    }

    /**
     * Get installation locale (fixed at installation time)
     *
     * This returns the default locale from the database (is_default=1).
     * This locale is set during installation and never changes,
     * unlike getLocale() which can be overridden by session.
     *
     * Use this for route definitions and other system-level translations
     * that should be fixed at installation time.
     *
     * @return string Installation locale code
     */
    public static function getInstallationLocale(): string
    {
        return self::$installationLocale;
    }

    /**
     * Get available locales
     *
     * Returns languages from database if loaded, otherwise returns fallback locales.
     *
     * @return array<string, string> Array of locale codes => language names
     */
    public static function getAvailableLocales(): array
    {
        // Return database languages if loaded, otherwise use fallback
        if (self::$languagesLoadedFromDb && self::$languagesCache !== null) {
            return self::$languagesCache;
        }

        return self::$availableLocales;
    }

    /**
     * Normalize locale codes to canonical format (xx_YY)
     */
    public static function normalizeLocaleCode(string $locale): string
    {
        $locale = trim(str_replace('-', '_', $locale));

        if (preg_match('/^([a-zA-Z]{2})_([a-zA-Z]{2})$/', $locale, $matches)) {
            return strtolower($matches[1]) . '_' . strtoupper($matches[2]);
        }

        return $locale;
    }

    /**
     * Validate locale codes
     */
    public static function isValidLocaleCode(?string $locale): bool
    {
        if ($locale === null) {
            return false;
        }

        $normalized = self::normalizeLocaleCode($locale);
        return (bool)preg_match(self::LOCALE_PATTERN, $normalized);
    }

    /**
     * Translate a plural form
     *
     * Usage:
     *   I18n::translatePlural('%d book', '%d books', $count);
     *
     * @param string $singular Singular form
     * @param string $plural Plural form
     * @param int $count Count to determine which form to use
     * @param mixed ...$args Optional arguments for sprintf formatting
     * @return string Translated message
     */
    public static function translatePlural(string $singular, string $plural, int $count, ...$args): string
    {
        // TODO: Implement ngettext for proper plural handling
        // For now, simple singular/plural logic

        // 1. Choose singular or plural form
        $message = ($count === 1) ? $singular : $plural;

        // 2. Translate the chosen form
        $translated = self::translate($message);

        // 3. Format with sprintf
        if (empty($args)) {
            return sprintf($translated, $count);
        }

        return sprintf($translated, $count, ...$args);
    }
}

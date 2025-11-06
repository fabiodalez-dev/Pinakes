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
    /**
     * Current locale (default: Italian)
     */
    private static string $locale = 'it_IT';

    /**
     * Available locales
     */
    private static array $availableLocales = [
        'it_IT' => 'Italiano',
        'en_US' => 'English',
    ];

    /**
     * Translation cache
     */
    private static array $translations = [];

    /**
     * Flag to track if translations have been loaded
     */
    private static bool $translationsLoaded = false;

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
        if (!isset(self::$availableLocales[$locale])) {
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
     * Get available locales
     *
     * @return array<string, string> Array of locale codes => language names
     */
    public static function getAvailableLocales(): array
    {
        return self::$availableLocales;
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

        $message = ($count === 1) ? $singular : $plural;

        if (empty($args)) {
            return sprintf($message, $count);
        }

        return sprintf($message, $count, ...$args);
    }
}

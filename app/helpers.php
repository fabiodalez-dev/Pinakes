<?php
/**
 * Global Helper Functions
 *
 * This file contains global helper functions available throughout the application.
 * These functions are autoloaded via composer.json.
 */

if (!function_exists('__')) {
    /**
     * Translate a string (shorthand for I18n::translate)
     *
     * This is the primary translation function used throughout the application.
     * It's intentionally kept simple and compatible with standard gettext conventions.
     *
     * Usage examples:
     *   echo __('Hello World');
     *   echo __('Welcome %s', $username);
     *   echo __('You have %d new messages', $count);
     *
     * @param string $message The message to translate
     * @param mixed ...$args Optional arguments for sprintf formatting
     * @return string Translated message
     */
    function __(string $message, ...$args): string
    {
        return App\Support\I18n::translate($message, ...$args);
    }
}

if (!function_exists('__n')) {
    /**
     * Translate a plural form (shorthand for I18n::translatePlural)
     *
     * Usage examples:
     *   echo __n('%d book', '%d books', $count);
     *   echo __n('One item', '%d items', $count);
     *
     * @param string $singular Singular form
     * @param string $plural Plural form
     * @param int $count Count to determine which form to use
     * @param mixed ...$args Optional arguments for sprintf formatting
     * @return string Translated message
     */
    function __n(string $singular, string $plural, int $count, ...$args): string
    {
        return App\Support\I18n::translatePlural($singular, $plural, $count, ...$args);
    }
}

if (!function_exists('route_path')) {
    /**
     * Resolve a localized route path using RouteTranslator
     *
     * @param string $key Route key (e.g., 'catalog', 'login')
     * @return string Localized route path starting with /
     */
    function route_path(string $key): string
    {
        return App\Support\RouteTranslator::route($key);
    }
}

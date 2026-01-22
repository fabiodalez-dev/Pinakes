<?php
/**
 * PHPStan Stubs for Pinakes Library Management System
 *
 * These stubs help PHPStan understand global helper functions
 * that are conditionally defined with function_exists().
 */

/**
 * Translate a string (shorthand for I18n::translate)
 *
 * @param string $message The message to translate
 * @param mixed ...$args Optional arguments for sprintf formatting
 * @return string Translated message
 */
function __(string $message, mixed ...$args): string
{
    return '';
}

/**
 * Translate a plural form (shorthand for I18n::translatePlural)
 *
 * @param string $singular Singular form
 * @param string $plural Plural form
 * @param int $count Count to determine which form to use
 * @param mixed ...$args Optional arguments for sprintf formatting
 * @return string Translated message
 */
function __n(string $singular, string $plural, int $count, mixed ...$args): string
{
    return '';
}

/**
 * Resolve a localized route path
 *
 * @param string $key Route key
 * @return string Localized route path
 */
function route_path(string $key): string
{
    return '';
}

/**
 * Convert text to URL-friendly slug
 *
 * @param string|null $text
 * @return string
 */
function slugify_text(?string $text): string
{
    return '';
}

/**
 * Get primary author name from book array
 *
 * @param array<string, mixed> $book
 * @return string
 */
function book_primary_author_name(array $book): string
{
    return '';
}

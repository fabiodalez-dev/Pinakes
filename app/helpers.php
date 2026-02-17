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

if (!function_exists('url')) {
    /**
     * Generate a path-only URL with base path prepended.
     * Use for href/action attributes in views.
     *
     * @param string $path Absolute path starting with /
     * @return string Path with base path prefix
     */
    function url(string $path = '/'): string
    {
        // Don't modify absolute URLs (e.g. cover images from external APIs)
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '//')) {
            return $path;
        }
        // Ensure path starts with /
        if ($path !== '' && !str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        return App\Support\HtmlHelper::getBasePath() . $path;
    }
}

if (!function_exists('route_path')) {
    /**
     * Resolve a localized route path using RouteTranslator
     *
     * @param string $key Route key (e.g., 'catalog', 'login')
     * @return string Localized route path starting with / (includes base path)
     */
    function route_path(string $key): string
    {
        return App\Support\HtmlHelper::getBasePath() . App\Support\RouteTranslator::route($key);
    }
}

if (!function_exists('slugify_text')) {
    /**
     * Convert a string into a URL-friendly slug.
     */
    function slugify_text(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        $decoded = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        // Transliterate Unicode → ASCII. Prefer intl Transliterator (handles all
        // Unicode correctly), fall back to iconv (buggy on macOS with some sequences)
        if (class_exists('Transliterator')) {
            $t = \Transliterator::create('Any-Latin; Latin-ASCII');
            if ($t !== null) {
                $decoded = $t->transliterate($decoded);
            }
        } else {
            $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $decoded);
            if ($transliterated !== false) {
                $decoded = $transliterated;
            }
        }
        $decoded = strtolower($decoded);

        $decoded = preg_replace('/[^a-z0-9\s-]/', '', $decoded) ?? '';
        $decoded = preg_replace('/[\s-]+/', '-', $decoded) ?? '';

        return trim($decoded, '-');
    }
}

if (!function_exists('book_primary_author_name')) {
    /**
     * Attempt to extract the primary author name from a book array.
     */
    function book_primary_author_name(array $book): string
    {
        $candidates = [
            $book['autore_principale'] ?? null,
            $book['autore'] ?? null,
            $book['author'] ?? null,
            $book['libro_autore'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!empty($candidate)) {
                return trim(html_entity_decode((string)$candidate, ENT_QUOTES, 'UTF-8'));
            }
        }

        if (!empty($book['autori'])) {
            // Handle if autori is an array of author objects
            if (is_array($book['autori'])) {
                $firstAuthor = $book['autori'][0] ?? null;
                if (is_array($firstAuthor) && !empty($firstAuthor['nome'])) {
                    return trim(html_entity_decode((string)$firstAuthor['nome'], ENT_QUOTES, 'UTF-8'));
                }
                if (is_string($firstAuthor) && $firstAuthor !== '') {
                    return trim(html_entity_decode($firstAuthor, ENT_QUOTES, 'UTF-8'));
                }
            }

            // Handle if autori is a comma-separated string
            if (is_string($book['autori'])) {
                $parts = preg_split('/[,;]+/', $book['autori']);
                if (!empty($parts[0])) {
                    return trim(html_entity_decode($parts[0], ENT_QUOTES, 'UTF-8'));
                }
            }
        }

        if (!empty($book['authors']) && is_array($book['authors'])) {
            $firstAuthor = $book['authors'][0] ?? null;
            if (is_array($firstAuthor) && !empty($firstAuthor['nome'])) {
                return trim(html_entity_decode((string)$firstAuthor['nome'], ENT_QUOTES, 'UTF-8'));
            }
            if (is_string($firstAuthor) && $firstAuthor !== '') {
                return trim(html_entity_decode($firstAuthor, ENT_QUOTES, 'UTF-8'));
            }
        }

        return 'autore';
    }
}

if (!function_exists('book_path')) {
    /**
     * Build the canonical path for a book (author slug + book slug + ID) WITHOUT base path.
     * Used by book_url() and SitemapGenerator::buildBookPath().
     */
    function book_path(array $book): string
    {
        $bookId = (int)($book['id'] ?? $book['libro_id'] ?? 0);
        if ($bookId <= 0) {
            return '/';
        }

        $title = (string)($book['titolo'] ?? $book['libro_titolo'] ?? $book['title'] ?? '');
        $authorName = book_primary_author_name($book);

        $bookSlug = slugify_text($title);
        if ($bookSlug === '') {
            $bookSlug = 'libro';
        }

        $authorSlug = slugify_text($authorName);
        if ($authorSlug === '') {
            $authorSlug = 'autore';
        }

        return '/' . $authorSlug . '/' . $bookSlug . '/' . $bookId;
    }
}

if (!function_exists('book_url')) {
    /**
     * Build the canonical frontend URL for a book (author slug + book slug + ID).
     */
    function book_url(array $book): string
    {
        return url(book_path($book));
    }
}

// ============================================================================
// Hook System Helper Functions
// ============================================================================

if (!function_exists('format_date')) {
    /**
     * Format a date according to the current locale
     *
     * Italian (it_IT): DD-MM-YYYY or DD/MM/YYYY
     * English (en_US): YYYY-MM-DD
     *
     * @param string|null $dateString Date string (any format parseable by strtotime)
     * @param bool $includeTime Include time in output (H:i)
     * @param string $separator Date separator for Italian format ('-' or '/')
     * @return string Formatted date or original string if not a valid date
     */
    function format_date(?string $dateString, bool $includeTime = false, string $separator = '-'): string
    {
        if (empty($dateString)) {
            return '';
        }

        $timestamp = strtotime($dateString);
        if ($timestamp === false) {
            return $dateString;
        }

        // Cache locale detection to avoid repeated calls in loops
        static $isItalian = null;
        if ($isItalian === null) {
            $locale = App\Support\I18n::getLocale();
            $isItalian = str_starts_with($locale, 'it');
        }

        if ($isItalian) {
            // Italian format: DD-MM-YYYY or DD/MM/YYYY
            $format = $separator === '/' ? 'd/m/Y' : 'd-m-Y';
        } else {
            // English format: YYYY-MM-DD
            $format = 'Y-m-d';
        }

        if ($includeTime) {
            $format .= ' H:i';
        }

        return date($format, $timestamp);
    }
}

if (!function_exists('format_date_short')) {
    /**
     * Format a date with short day/month (for calendars)
     *
     * Italian (it_IT): DD/MM
     * English (en_US): MM/DD
     *
     * @param string|null $dateString Date string
     * @return string Formatted short date
     */
    function format_date_short(?string $dateString): string
    {
        if (empty($dateString)) {
            return '';
        }

        $timestamp = strtotime($dateString);
        if ($timestamp === false) {
            return $dateString;
        }

        // Cache locale detection to avoid repeated calls in loops
        static $isItalian = null;
        if ($isItalian === null) {
            $locale = App\Support\I18n::getLocale();
            $isItalian = str_starts_with($locale, 'it');
        }

        // Italian: DD/MM, English: MM/DD
        return $isItalian ? date('d/m', $timestamp) : date('m/d', $timestamp);
    }
}

if (!function_exists('translate_loan_status')) {
    /**
     * Translate a loan status code to its localized label
     *
     * @param string $status Status code from database (e.g., 'in_corso', 'restituito')
     * @return string Localized status label
     */
    function translate_loan_status(string $status): string
    {
        return match ($status) {
            'pendente' => __('Pendente'),
            'prenotato' => __('Prenotato'),
            'da_ritirare' => __('Da Ritirare'),
            'in_corso' => __('In Corso'),
            'in_ritardo' => __('In Ritardo'),
            'restituito' => __('Restituito'),
            'perso' => __('Perso'),
            'danneggiato' => __('Danneggiato'),
            'annullato' => __('Annullato'),
            'scaduto' => __('Scaduto'),
            default => $status
        };
    }
}

if (!function_exists('absoluteUrl')) {
    /**
     * Generate an absolute URL for the given path.
     *
     * @param string $path The path to append to the base URL
     * @return string Absolute URL
     */
    function absoluteUrl(string $path = ''): string
    {
        return App\Support\HtmlHelper::absoluteUrl($path);
    }
}

if (!function_exists('assetUrl')) {
    /**
     * Generate an absolute URL for an asset file.
     *
     * Usage examples:
     *   echo assetUrl('main.css');        // => http://example.com/assets/main.css
     *   echo assetUrl('tinymce/tinymce.min.js');
     *
     * @param string $path The asset path relative to /assets/
     * @return string Absolute asset URL
     */
    function assetUrl(string $path): string
    {
        $normalizedPath = '/' . ltrim($path, '/');
        return App\Support\HtmlHelper::absoluteUrl('/assets' . $normalizedPath);
    }
}

if (!function_exists('do_action')) {
    /**
     * Execute an action hook
     *
     * Action hooks allow plugins to inject custom functionality at specific points
     * in the application without modifying core code.
     *
     * Usage example:
     *   do_action('book.detail.digital_buttons', $book);
     *
     * @param string $hookName The name of the hook to execute
     * @param mixed ...$args Arguments to pass to the hook callbacks
     * @return void
     */
    function do_action(string $hookName, ...$args): void
    {
        if (isset($GLOBALS['hookManager'])) {
            $GLOBALS['hookManager']->doAction($hookName, $args);
        }
    }
}

if (!function_exists('apply_filters')) {
    /**
     * Apply a filter hook
     *
     * Filter hooks allow plugins to modify and return values at specific points
     * in the application.
     *
     * Usage example:
     *   $bookData = apply_filters('book.data.get', $bookData, $bookId);
     *
     * @param string $hookName The name of the hook to apply
     * @param mixed $value The initial value to filter
     * @param mixed ...$args Additional arguments to pass to the hook callbacks
     * @return mixed The filtered value
     */
    function apply_filters(string $hookName, $value, ...$args)
    {
        if (isset($GLOBALS['hookManager'])) {
            return $GLOBALS['hookManager']->applyFilters($hookName, $value, $args);
        }
        return $value;
    }
}

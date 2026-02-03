<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Centralized service for book scraping operations
 *
 * Provides a unified interface for scraping book data from various sources
 * using the plugin hook system. Includes retry logic and proper error handling.
 */
class ScrapingService
{
    /**
     * Scrape book data from available sources
     *
     * @param string $isbn ISBN or EAN to scrape
     * @param int $maxAttempts Maximum number of retry attempts
     * @param string $context Context identifier for logging (e.g., 'LibraryThing Import', 'CSV Import')
     * @return array Scraped book data or empty array if scraping fails/unavailable
     */
    public static function scrapeBookData(string $isbn, int $maxAttempts = 3, string $context = 'Scraping'): array
    {
        // Check if scraping is available
        if (!\App\Support\Hooks::has('scrape.fetch.custom')) {
            return [];
        }

        $delaySeconds = 1;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                // Get sources configuration from plugins
                // The scrape.sources hook allows plugins to register their scraping sources
                $sources = \App\Support\Hooks::apply('scrape.sources', [], [$isbn]);

                // Call scraping hook with sources and ISBN (positional arguments required by plugins)
                // Plugins like ScrapingPro, OpenLibrary, and ApiBookScraper expect: ($current, $sources, $isbn)
                $result = \App\Support\Hooks::apply('scrape.fetch.custom', null, [$sources, $isbn]);

                if (!empty($result) && is_array($result)) {
                    return $result;
                }
            } catch (\Throwable $e) {
                // Silent failure, will retry
                $lastError = $e;
            }

            // Exponential backoff with cap at 8 seconds
            if ($attempt < $maxAttempts) {
                sleep($delaySeconds);
                $delaySeconds = min($delaySeconds * 2, 8);
            }
        }

        // Log failure after all attempts exhausted
        if ($lastError !== null || $context) {
            \App\Support\SecureLogger::warning('Scraping fallito dopo tutti i tentativi', [
                'context' => $context,
                'isbn' => $isbn,
                'maxAttempts' => $maxAttempts,
                'error' => $lastError ? $lastError->getMessage() : 'Hook non ha restituito risultati'
            ]);
        }

        return [];
    }

    /**
     * Scrape only cover image from available sources
     *
     * @param string $isbn ISBN or EAN to scrape
     * @param int $maxAttempts Maximum number of retry attempts
     * @return array Array with 'image' key or empty array
     */
    public static function scrapeBookCover(string $isbn, int $maxAttempts = 3): array
    {
        $result = self::scrapeBookData($isbn, $maxAttempts, 'Cover Sync');

        if (!empty($result['image'])) {
            return ['image' => $result['image']];
        }

        return [];
    }

    /**
     * Check if scraping functionality is available
     *
     * @return bool True if at least one scraping plugin is registered
     */
    public static function isAvailable(): bool
    {
        return \App\Support\Hooks::has('scrape.fetch.custom');
    }
}

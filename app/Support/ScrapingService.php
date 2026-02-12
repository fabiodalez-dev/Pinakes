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
        // Try plugin-based scraping first
        if (\App\Support\Hooks::has('scrape.fetch.custom')) {
            $delaySeconds = 1;
            $lastError = null;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    $sources = \App\Support\Hooks::apply('scrape.sources', [], [$isbn]);
                    $result = \App\Support\Hooks::apply('scrape.fetch.custom', null, [$sources, $isbn]);

                    if (!empty($result) && is_array($result)) {
                        return $result;
                    }
                } catch (\Throwable $e) {
                    $lastError = $e;
                }

                if ($attempt < $maxAttempts) {
                    sleep($delaySeconds);
                    $delaySeconds = min($delaySeconds * 2, 8);
                }
            }

            if ($lastError !== null) {
                \App\Support\SecureLogger::warning('Scraping plugin fallito dopo tutti i tentativi', [
                    'context' => $context,
                    'isbn' => $isbn,
                    'maxAttempts' => $maxAttempts,
                    'error' => $lastError->getMessage()
                ]);
            }
        }

        // Built-in fallback: use ScrapeController's byIsbn which includes
        // Google Books and Open Library fallbacks (works without any plugin)
        try {
            $scrapeController = new \App\Controllers\ScrapeController();
            $mockRequest = (new \Slim\Psr7\Factory\ServerRequestFactory())
                ->createServerRequest('GET', '/api/scrape/isbn')
                ->withQueryParams(['isbn' => $isbn]);
            $mockResponse = new \Slim\Psr7\Response();

            $scrapeResponse = $scrapeController->byIsbn($mockRequest, $mockResponse);

            if ($scrapeResponse->getStatusCode() === 200) {
                $body = (string) $scrapeResponse->getBody();
                $data = json_decode($body, true);
                if (!empty($data) && is_array($data) && !isset($data['error'])) {
                    return $data;
                }
            }
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::warning('Scraping built-in fallback fallito', [
                'context' => $context,
                'isbn' => $isbn,
                'error' => $e->getMessage()
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
     * @return bool Always true — built-in Google Books/Open Library fallback is always available
     */
    public static function isAvailable(): bool
    {
        return true;
    }
}

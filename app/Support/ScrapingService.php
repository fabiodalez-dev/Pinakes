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
        // Use the web pipeline for plugin results as well as built-in fallbacks:
        // validation, normalized identifiers, enrichment and final hooks must
        // not depend on whether the caller is an import, a job or the web form.
        try {
            $scrapeController = new \App\Controllers\ScrapeController();
            $mockRequest = (new \Slim\Psr7\Factory\ServerRequestFactory())
                ->createServerRequest('GET', '/api/scrape/isbn')
                ->withQueryParams(['isbn' => $isbn]);
            $mockResponse = new \Slim\Psr7\Response();

            // No scrape.http_entry attribute: the caller still owns its session.
            $scrapeResponse = $scrapeController->byIsbn($mockRequest, $mockResponse, $maxAttempts, $context);

            if ($scrapeResponse->getStatusCode() === 200) {
                $body = (string) $scrapeResponse->getBody();
                $data = json_decode($body, true);
                if (!empty($data) && is_array($data) && !isset($data['error'])) {
                    return $data;
                }
            }
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::warning('Scraping fallito', [
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

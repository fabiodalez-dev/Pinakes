<?php
/**
 * Book Service
 * Business logic for book lookup and management
 */
class BookService
{
    private ScraperManager $scraperManager;

    public function __construct(ScraperManager $scraperManager)
    {
        $this->scraperManager = $scraperManager;
    }

    /**
     * Find book by ISBN
     */
    public function findByIsbn(string $isbn, string $apiKey): ?array
    {
        // Validate ISBN format
        if (!$this->validateIsbn($isbn)) {
            throw new InvalidArgumentException('Invalid ISBN format. Must be 10 or 13 digits.');
        }

        $startTime = microtime(true);

        // Scrape book data
        $bookData = $this->scraperManager->scrape($isbn);

        $responseTime = (int)((microtime(true) - $startTime) * 1000);

        // Log statistics
        if (Config::get('STATS_ENABLED', true)) {
            Database::logStats(
                $apiKey,
                $isbn,
                $bookData !== null,
                $bookData['scraper'] ?? null,
                $responseTime
            );
        }

        return $bookData;
    }

    /**
     * Validate ISBN format
     */
    private function validateIsbn(string $isbn): bool
    {
        // Remove hyphens and spaces
        $isbn = str_replace(['-', ' '], '', $isbn);

        // Must be 10 or 13 digits
        if (!preg_match('/^\d{10}(\d{3})?$/', $isbn)) {
            return false;
        }

        return true;
    }

    /**
     * Get scraper manager
     */
    public function getScraperManager(): ScraperManager
    {
        return $this->scraperManager;
    }

    /**
     * Get available scrapers info
     */
    public function getScrapersInfo(): array
    {
        return $this->scraperManager->getStats();
    }
}

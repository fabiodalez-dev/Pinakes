<?php
/**
 * Scraper Manager
 * Manages the execution of book scrapers
 */
class ScraperManager
{
    private ScraperRegistry $registry;

    public function __construct(ScraperRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Scrape book data by ISBN
     * Tries all enabled scrapers in priority order until one succeeds
     */
    public function scrape(string $isbn): ?array
    {
        $scrapers = $this->registry->getEnabledScrapers();

        if (empty($scrapers)) {
            logRequest('No scrapers enabled', ['isbn' => $isbn]);
            return null;
        }

        $errors = [];

        foreach ($scrapers as $scraper) {
            $scraperName = $scraper->getName();

            try {
                logRequest('Attempting scraper', [
                    'scraper' => $scraperName,
                    'isbn' => $isbn
                ]);

                $result = $scraper->scrape($isbn);

                if ($result !== null) {
                    logRequest('Scraper succeeded', [
                        'scraper' => $scraperName,
                        'isbn' => $isbn
                    ]);

                    return $result;
                }

                logRequest('Scraper returned no data', [
                    'scraper' => $scraperName,
                    'isbn' => $isbn
                ]);

            } catch (Exception $e) {
                $errorMsg = $e->getMessage();
                $errors[$scraperName] = $errorMsg;

                logRequest('Scraper failed', [
                    'scraper' => $scraperName,
                    'isbn' => $isbn,
                    'error' => $errorMsg
                ]);

                // Continue to next scraper
                continue;
            }
        }

        // All scrapers failed
        logRequest('All scrapers failed', [
            'isbn' => $isbn,
            'errors' => $errors,
            'tried_count' => count($scrapers)
        ]);

        return null;
    }

    /**
     * Get enabled scrapers
     */
    public function getEnabledScrapers(): array
    {
        return $this->registry->getEnabledScrapers();
    }

    /**
     * Get scraper registry
     */
    public function getRegistry(): ScraperRegistry
    {
        return $this->registry;
    }

    /**
     * Get scraper statistics
     */
    public function getStats(): array
    {
        return [
            'total_scrapers' => $this->registry->count(),
            'enabled_scrapers' => $this->registry->countEnabled(),
            'disabled_scrapers' => $this->registry->count() - $this->registry->countEnabled(),
            'scraper_names' => $this->registry->getEnabledNames()
        ];
    }
}

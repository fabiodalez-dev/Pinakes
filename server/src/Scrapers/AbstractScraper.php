<?php
/**
 * Abstract Scraper Base Class
 */
abstract class AbstractScraper
{
    protected int $timeout;
    protected string $userAgent;

    public function __construct()
    {
        $this->timeout = (int)($_ENV['SCRAPER_TIMEOUT'] ?? 10);
        $this->userAgent = $_ENV['SCRAPER_USER_AGENT'] ?? 'Mozilla/5.0 (compatible; PinakesBot/1.0)';
    }

    /**
     * Scrape book data by ISBN
     * Must be implemented by child classes
     */
    abstract public function scrape(string $isbn): ?array;

    /**
     * Get scraper name
     */
    abstract public function getName(): string;

    /**
     * Fetch HTML content from URL
     */
    protected function fetchHtml(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => false, // For shared hosting compatibility
            CURLOPT_ENCODING => '', // Accept gzip
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $html === false) {
            return null;
        }

        return $html;
    }

    /**
     * Create DOMXPath from HTML
     */
    protected function getDomXPath(string $html): ?DOMXPath
    {
        libxml_use_internal_errors(true);

        $dom = new DOMDocument();
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    /**
     * Clean text (remove extra whitespace)
     */
    protected function cleanText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Extract first match from XPath query
     */
    protected function extractText(DOMXPath $xpath, string $query, ?DOMNode $context = null): ?string
    {
        $nodes = $xpath->query($query, $context);
        if ($nodes && $nodes->length > 0) {
            return $this->cleanText($nodes->item(0)->textContent);
        }
        return null;
    }

    /**
     * Normalize book data structure
     */
    protected function normalizeBookData(array $data): array
    {
        return [
            'isbn' => $data['isbn'] ?? null,
            'title' => $data['title'] ?? null,
            'author' => $data['author'] ?? null,
            'publisher' => $data['publisher'] ?? null,
            'year' => $data['year'] ?? null,
            'pages' => $data['pages'] ?? null,
            'language' => $data['language'] ?? null,
            'description' => $data['description'] ?? null,
            'cover_url' => $data['cover_url'] ?? null,
            'price' => $data['price'] ?? null,
            'scraper' => $this->getName(),
            'scraped_at' => date('Y-m-d H:i:s')
        ];
    }
}

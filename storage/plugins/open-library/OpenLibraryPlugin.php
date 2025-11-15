<?php

namespace App\Plugins\OpenLibrary;

use App\Support\Hooks;

/**
 * Open Library API Plugin
 *
 * Integrates Open Library APIs (openlibrary.org) for book metadata scraping.
 * Provides comprehensive book information including covers, authors, editions, and works.
 *
 * @see https://openlibrary.org/developers/api
 */
class OpenLibraryPlugin
{
    private const API_BASE = 'https://openlibrary.org';
    private const COVERS_BASE = 'https://covers.openlibrary.org';
    private const TIMEOUT = 15;
    private const USER_AGENT = 'Mozilla/5.0 (compatible; BibliotecaBot/1.0) Safari/537.36';

    private ?\mysqli $db = null;
    private ?object $hookManager = null;
    private ?int $pluginId = null;

    public function __construct(?\mysqli $db = null, ?object $hookManager = null)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;
    }

    /**
     * Activate the plugin and register all hooks
     */
    public function activate(): void
    {
        // Add Open Library as a scraping source with high priority
        Hooks::add('scrape.sources', [$this, 'addOpenLibrarySource'], 5);

        // Use custom scraping logic for Open Library API
        Hooks::add('scrape.fetch.custom', [$this, 'fetchFromOpenLibrary'], 5);

        // Enrich data with additional information
        Hooks::add('scrape.data.modify', [$this, 'enrichWithOpenLibraryData'], 10);
    }

    /**
     * Called when plugin is installed via PluginManager
     */
    public function onInstall(): void
    {
        error_log('[OpenLibrary] Plugin installed');
        $this->registerHooks();
    }

    /**
     * Called when plugin is activated via PluginManager
     */
    public function onActivate(): void
    {
        $this->activate();
        error_log('[OpenLibrary] Plugin activated via PluginManager');
    }

    /**
     * Set the plugin ID (called by PluginManager after installation)
     */
    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
    }

    /**
     * Register hooks in the database for persistence
     */
    private function registerHooks(): void
    {
        if ($this->db === null || $this->pluginId === null) {
            error_log('[OpenLibrary] Cannot register hooks: missing DB or plugin ID');
            return;
        }

        $hooks = [
            ['scrape.sources', 'addOpenLibrarySource', 5],
            ['scrape.fetch.custom', 'fetchFromOpenLibrary', 5],
            ['scrape.data.modify', 'enrichWithOpenLibraryData', 10],
        ];

        // Delete existing hooks for this plugin
        $this->deleteHooks();

        foreach ($hooks as [$hookName, $method, $priority]) {
            $stmt = $this->db->prepare(
                "INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active, created_at)
                 VALUES (?, ?, ?, ?, ?, 1, NOW())"
            );

            if ($stmt === false) {
                error_log("[OpenLibrary] Failed to prepare statement: " . $this->db->error);
                continue;
            }

            $callbackClass = 'OpenLibraryPlugin';
            $stmt->bind_param('isssi', $this->pluginId, $hookName, $callbackClass, $method, $priority);

            if (!$stmt->execute()) {
                error_log("[OpenLibrary] Failed to register hook {$hookName}: " . $stmt->error);
            }

            $stmt->close();
        }

        error_log('[OpenLibrary] Hooks registered in database');
    }

    /**
     * Delete all hooks for this plugin
     */
    private function deleteHooks(): void
    {
        if ($this->db === null || $this->pluginId === null) {
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM plugin_hooks WHERE plugin_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $this->pluginId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Add Open Library as a scraping source
     *
     * @param array $sources Existing sources
     * @param string $isbn ISBN being scraped
     * @return array Modified sources
     */
    public function addOpenLibrarySource(array $sources, string $isbn): array
    {
        // Add Google Books if API key is configured
        $googleApiKey = $this->getGoogleBooksApiKey();
        if (!empty($googleApiKey)) {
            $sources['google_books'] = [
                'name' => 'Google Books',
                'url_pattern' => 'https://www.googleapis.com/books/v1/volumes?q=isbn:{isbn}',
                'enabled' => true,
                'priority' => 4, // Higher priority than Open Library, lower than scraping
                'fields' => ['title', 'subtitle', 'authors', 'publisher', 'publish_date',
                            'page_count', 'isbn', 'description', 'image'],
            ];
        }

        $sources['openlibrary'] = [
            'name' => 'Open Library',
            'url_pattern' => self::API_BASE . '/isbn/{isbn}.json',
            'enabled' => true,
            'priority' => 5, // Fallback source
            'fields' => ['title', 'subtitle', 'authors', 'publisher', 'publish_date',
                        'number_of_pages', 'isbn', 'description', 'image', 'subjects'],
        ];

        $sources['openlibrary_cover'] = [
            'name' => 'Open Library Covers',
            'url_pattern' => self::COVERS_BASE . '/b/isbn/{isbn}-L.jpg',
            'enabled' => true,
            'priority' => 3, // Very high priority for covers
            'fields' => ['image'],
        ];

        return $sources;
    }

    /**
     * Fetch book data from Open Library APIs
     *
     * @param mixed $current Current result (null if no plugin handled it yet)
     * @param array $sources Available sources
     * @param string $isbn ISBN to search
     * @return array|null Book data or null to let other plugins handle
     */
    public function fetchFromOpenLibrary($current, array $sources, string $isbn): ?array
    {
        // Let other plugins handle if they already did
        if ($current !== null) {
            return $current;
        }

        // Only handle if Open Library source is enabled
        if (!isset($sources['openlibrary']) || !$sources['openlibrary']['enabled']) {
            return null;
        }

        try {
            // Fetch edition data by ISBN
            $editionData = $this->fetchEditionByISBN($isbn);

            if (empty($editionData)) {
                error_log("[OpenLibrary] No data found for ISBN: $isbn");
                // Try Google Books as fallback
                return $this->tryGoogleBooks($isbn);
            }

            // Check if we have an error response from API
            if (isset($editionData['error'])) {
                error_log("[OpenLibrary] API error for ISBN $isbn: {$editionData['error']}");
                // Try Google Books as fallback
                return $this->tryGoogleBooks($isbn);
            }

            // Validate we have at least a title
            if (empty($editionData['title'])) {
                error_log("[OpenLibrary] No title found for ISBN: $isbn");
                // Try Google Books as fallback
                return $this->tryGoogleBooks($isbn);
            }

            // Fetch work data if available
            $workData = null;
            if (!empty($editionData['works'][0]['key'])) {
                $workKey = $editionData['works'][0]['key'];
                $workData = $this->fetchWork($workKey);
            }

            // Fetch author data
            $authorNames = [];
            $authorsList = [];
            if (!empty($editionData['authors'])) {
                foreach ($editionData['authors'] as $author) {
                    if (!empty($author['key'])) {
                        $authorData = $this->fetchAuthor($author['key']);
                        if ($authorData && !empty($authorData['name'])) {
                            $authorNames[] = $authorData['name'];
                            $authorsList[] = $authorData['name'];
                        }
                    }
                }
            }

            // Fetch cover image
            $coverUrl = $this->getCoverUrl($isbn, $editionData);

            // Build response in the format expected by the application
            $result = [
                'title' => $editionData['title'] ?? '',
                'subtitle' => $editionData['subtitle'] ?? '',
                'author' => implode(', ', $authorNames),
                'authors' => $authorsList,
                'publisher' => $this->extractPublisher($editionData),
                'isbn' => $isbn,
                'ean' => $this->extractEAN($editionData),
                'year' => $this->extractYear($editionData),
                'pages' => $editionData['number_of_pages'] ?? null,
                'weight' => $editionData['weight'] ?? null,
                'format' => $this->extractFormat($editionData),
                'description' => $this->extractDescription($editionData, $workData),
                'image' => $coverUrl,
                'series' => $this->extractSeries($editionData),
                'notes' => $this->buildNotes($editionData, $workData),
                'tipologia' => $this->extractTipologia($workData),
                'source' => self::API_BASE . '/isbn/' . $isbn,
                '_openlibrary_edition_key' => $editionData['key'] ?? null,
                '_openlibrary_work_key' => $workData['key'] ?? null,
            ];

            return $result;

        } catch (\Exception $e) {
            // Log error but don't fail - let default scraper handle it
            error_log('OpenLibrary Plugin Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Enrich existing data with Open Library information
     *
     * @param array $payload Current payload
     * @param string $isbn ISBN
     * @param array $source Source information
     * @param array $originalPayload Original payload before modifications
     * @return array Enriched payload
     */
    public function enrichWithOpenLibraryData(array $payload, string $isbn, array $source, array $originalPayload): array
    {
        // If we already fetched from Open Library, skip enrichment
        if (!empty($payload['_openlibrary_edition_key'])) {
            return $payload;
        }

        // Try to fetch cover if missing
        if (empty($payload['image'])) {
            $coverUrl = $this->getCoverUrl($isbn, []);
            if ($coverUrl) {
                $payload['image'] = $coverUrl;
            }
        }

        return $payload;
    }

    /**
     * Fetch edition data by ISBN from Open Library
     *
     * @param string $isbn ISBN to search
     * @return array|null Edition data or null
     */
    private function fetchEditionByISBN(string $isbn): ?array
    {
        $url = self::API_BASE . '/isbn/' . $isbn . '.json';
        return $this->makeApiRequest($url);
    }

    /**
     * Fetch work data from Open Library
     *
     * @param string $workKey Work key (e.g., /works/OL45804W)
     * @return array|null Work data or null
     */
    private function fetchWork(string $workKey): ?array
    {
        $url = self::API_BASE . $workKey . '.json';
        return $this->makeApiRequest($url);
    }

    /**
     * Fetch author data from Open Library
     *
     * @param string $authorKey Author key (e.g., /authors/OL23919A)
     * @return array|null Author data or null
     */
    private function fetchAuthor(string $authorKey): ?array
    {
        $url = self::API_BASE . $authorKey . '.json';
        return $this->makeApiRequest($url);
    }

    /**
     * Get cover URL for ISBN
     *
     * @param string $isbn ISBN
     * @param array $editionData Edition data (optional)
     * @return string|null Cover URL or null
     */
    private function getCoverUrl(string $isbn, array $editionData = []): ?string
    {
        // Try to get cover ID from edition data first
        if (!empty($editionData['covers'][0])) {
            $coverId = $editionData['covers'][0];
            $url = self::COVERS_BASE . '/b/id/' . $coverId . '-L.jpg';
            if ($this->checkCoverExists($url)) {
                return $url;
            }
        }

        // Fallback to ISBN-based cover
        $url = self::COVERS_BASE . '/b/isbn/' . $isbn . '-L.jpg';
        if ($this->checkCoverExists($url)) {
            return $url;
        }

        return null;
    }

    /**
     * Check if a cover exists
     *
     * @param string $url Cover URL
     * @return bool True if cover exists
     */
    private function checkCoverExists(string $url): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        // Check if it's an actual image and not a placeholder
        return $httpCode === 200 && strpos($contentType, 'image/') === 0;
    }

    /**
     * Make an API request to Open Library
     *
     * @param string $url API URL
     * @return array|null Response data or null
     */
    private function makeApiRequest(string $url): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return null;
        }

        $data = json_decode($response, true);
        return $data ?: null;
    }

    /**
     * Extract publisher from edition data
     */
    private function extractPublisher(array $editionData): string
    {
        if (!empty($editionData['publishers'][0])) {
            return $editionData['publishers'][0];
        }
        return '';
    }

    /**
     * Extract EAN from edition data
     */
    private function extractEAN(array $editionData): string
    {
        // EAN is usually ISBN-13
        if (!empty($editionData['isbn_13'][0])) {
            return $editionData['isbn_13'][0];
        }
        return '';
    }

    /**
     * Extract publication year from edition data
     */
    private function extractYear(array $editionData): ?int
    {
        $dateStr = $editionData['publish_date'] ?? '';
        if (preg_match('/(\d{4})/', $dateStr, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    /**
     * Extract format from edition data
     */
    private function extractFormat(array $editionData): string
    {
        $format = $editionData['physical_format'] ?? '';

        // Map common formats
        $formatMap = [
            'Paperback' => 'Brossura',
            'Hardcover' => 'Rilegato',
            'Mass Market Paperback' => 'Tascabile',
            'eBook' => 'eBook',
        ];

        foreach ($formatMap as $en => $it) {
            if (stripos($format, $en) !== false) {
                return $it;
            }
        }

        return $format;
    }

    /**
     * Extract description from edition or work data
     */
    private function extractDescription(array $editionData, ?array $workData): string
    {
        // Try work description first (usually more complete)
        if ($workData && !empty($workData['description'])) {
            if (is_string($workData['description'])) {
                return $workData['description'];
            }
            if (is_array($workData['description']) && !empty($workData['description']['value'])) {
                return $workData['description']['value'];
            }
        }

        // Fallback to edition description
        if (!empty($editionData['description'])) {
            if (is_string($editionData['description'])) {
                return $editionData['description'];
            }
            if (is_array($editionData['description']) && !empty($editionData['description']['value'])) {
                return $editionData['description']['value'];
            }
        }

        return '';
    }

    /**
     * Extract series from edition data
     */
    private function extractSeries(array $editionData): string
    {
        if (!empty($editionData['series'][0])) {
            return $editionData['series'][0];
        }
        return '';
    }

    /**
     * Build notes from edition and work data
     */
    private function buildNotes(array $editionData, ?array $workData): string
    {
        $notes = [];

        // Add subjects from work
        if ($workData && !empty($workData['subjects'])) {
            $subjects = array_slice($workData['subjects'], 0, 5);
            $notes[] = 'Soggetti: ' . implode(', ', $subjects);
        }

        // Add physical dimensions if available
        if (!empty($editionData['physical_dimensions'])) {
            $notes[] = 'Dimensioni: ' . $editionData['physical_dimensions'];
        }

        // Add edition info
        if (!empty($editionData['edition_name'])) {
            $notes[] = 'Edizione: ' . $editionData['edition_name'];
        }

        // Add language
        if (!empty($editionData['languages'][0]['key'])) {
            $langKey = basename($editionData['languages'][0]['key']);
            $notes[] = 'Lingua: ' . $this->mapLanguage($langKey);
        }

        return implode("\n", $notes);
    }

    /**
     * Extract tipologia from work data
     */
    private function extractTipologia(?array $workData = null): string
    {
        if (!$workData || empty($workData['subjects'])) {
            return '';
        }

        // Map subjects to tipologia
        $subjects = $workData['subjects'];

        // Fiction keywords
        $fictionKeywords = ['fiction', 'novel', 'fantasy', 'science fiction', 'mystery', 'thriller'];
        foreach ($fictionKeywords as $keyword) {
            foreach ($subjects as $subject) {
                if (stripos($subject, $keyword) !== false) {
                    return 'Narrativa';
                }
            }
        }

        // Non-fiction keywords
        $nonfictionKeywords = ['history', 'biography', 'science', 'philosophy', 'psychology'];
        foreach ($nonfictionKeywords as $keyword) {
            foreach ($subjects as $subject) {
                if (stripos($subject, $keyword) !== false) {
                    return 'Saggistica';
                }
            }
        }

        return '';
    }

    /**
     * Map language code to readable name
     */
    private function mapLanguage(string $code): string
    {
        $map = [
            'eng' => 'Inglese',
            'ita' => 'Italiano',
            'fra' => 'Francese',
            'spa' => 'Spagnolo',
            'ger' => 'Tedesco',
            'por' => 'Portoghese',
        ];

        return $map[$code] ?? ucfirst($code);
    }

    /**
     * Try Google Books API as fallback
     *
     * @param string $isbn
     * @return array|null
     */
    private function tryGoogleBooks(string $isbn): ?array
    {
        $apiKey = $this->getGoogleBooksApiKey();

        if (empty($apiKey)) {
            error_log("[OpenLibrary] Google Books API key not configured, skipping");
            return null;
        }

        error_log("[OpenLibrary] Trying Google Books API for ISBN: $isbn");
        return $this->fetchFromGoogleBooks($isbn, $apiKey);
    }

    /**
     * Get Google Books API key from settings
     *
     * @return string|null
     */
    private function getGoogleBooksApiKey(): ?string
    {
        if ($this->db === null) {
            return null;
        }

        // Get plugin ID if not set
        $pluginId = $this->pluginId;
        if ($pluginId === null) {
            $result = $this->db->query("SELECT id FROM plugins WHERE name = 'open-library' LIMIT 1");
            if ($result) {
                $row = $result->fetch_assoc();
                $pluginId = $row['id'] ?? null;
                $result->free();
            }
        }

        if ($pluginId === null) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT setting_value FROM plugin_settings
             WHERE plugin_id = ? AND setting_key = 'google_books_api_key'"
        );

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $pluginId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        $value = $row['setting_value'] ?? null;

        // Decrypt value if encrypted (starts with "ENC:")
        if ($value !== null && strpos($value, 'ENC:') === 0) {
            $value = $this->decryptSettingValue($value);
        }

        return $value;
    }

    /**
     * Decrypt an encrypted setting value
     *
     * @param string $encryptedValue
     * @return string|null
     */
    private function decryptSettingValue(string $encryptedValue): ?string
    {
        // Get encryption key from environment
        $rawKey = $_ENV['PLUGIN_ENCRYPTION_KEY']
            ?? getenv('PLUGIN_ENCRYPTION_KEY')
            ?? $_ENV['APP_KEY']
            ?? getenv('APP_KEY')
            ?? null;

        if (!$rawKey || $rawKey === '') {
            error_log('[OpenLibrary] Encryption key not found, cannot decrypt setting');
            return null;
        }

        $key = hash('sha256', (string)$rawKey, true);

        // Remove "ENC:" prefix and decode
        $payload = base64_decode(substr($encryptedValue, 4), true);
        if ($payload === false || strlen($payload) <= 28) {
            error_log('[OpenLibrary] Invalid encrypted payload');
            return null;
        }

        // Extract IV (12 bytes), tag (16 bytes), and ciphertext
        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $ciphertext = substr($payload, 28);

        try {
            $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plaintext === false) {
                error_log('[OpenLibrary] Failed to decrypt setting value');
                return null;
            }
            return $plaintext;
        } catch (\Throwable $e) {
            error_log('[OpenLibrary] Decryption exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch book data from Google Books API
     *
     * @param string $isbn
     * @param string $apiKey
     * @return array|null
     */
    private function fetchFromGoogleBooks(string $isbn, string $apiKey): ?array
    {
        $url = sprintf(
            'https://www.googleapis.com/books/v1/volumes?q=isbn:%s&key=%s',
            urlencode($isbn),
            urlencode($apiKey)
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($response)) {
            error_log("[OpenLibrary] Google Books API returned HTTP $httpCode for ISBN: $isbn");
            return null;
        }

        $data = json_decode($response, true);

        if (empty($data['items'][0]['volumeInfo'])) {
            error_log("[OpenLibrary] No volume info in Google Books response for ISBN: $isbn");
            return null;
        }

        $volume = $data['items'][0]['volumeInfo'];

        // Extract authors
        $authors = $volume['authors'] ?? [];
        $authorString = implode(', ', $authors);

        // Extract ISBN-13 or ISBN-10
        $isbnValue = $isbn;
        if (!empty($volume['industryIdentifiers'])) {
            foreach ($volume['industryIdentifiers'] as $id) {
                if ($id['type'] === 'ISBN_13') {
                    $isbnValue = $id['identifier'];
                    break;
                }
            }
        }

        // Extract cover image
        $coverUrl = null;
        if (!empty($volume['imageLinks']['thumbnail'])) {
            $coverUrl = str_replace('http:', 'https:', $volume['imageLinks']['thumbnail']);
        }

        // Extract publication date (format: YYYY-MM-DD or YYYY)
        $pubDate = $volume['publishedDate'] ?? null;
        $year = null;
        if ($pubDate) {
            $year = substr($pubDate, 0, 4);
            // Convert to Italian format if full date
            if (strlen($pubDate) === 10) {
                $date = \DateTime::createFromFormat('Y-m-d', $pubDate);
                if ($date) {
                    $pubDate = $date->format('d F Y');
                }
            }
        }

        error_log("[OpenLibrary] Google Books found data for ISBN: $isbn");

        return [
            'title' => $volume['title'] ?? '',
            'subtitle' => $volume['subtitle'] ?? '',
            'author' => $authorString,
            'authors' => $authors,
            'publisher' => $volume['publisher'] ?? '',
            'isbn' => $isbnValue,
            'ean' => $isbnValue,
            'year' => $year,
            'pubDate' => $pubDate,
            'pages' => $volume['pageCount'] ?? null,
            'description' => $volume['description'] ?? '',
            'image' => $coverUrl,
            'series' => '',
            'format' => '',
            'notes' => 'Retrieved from Google Books API',
            'source' => 'https://books.google.com/books?isbn=' . $isbn,
        ];
    }
}

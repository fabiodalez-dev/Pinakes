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
    private const GOOGLE_BOOKS_BASE = 'https://www.googleapis.com/books/v1/volumes';
    private const TIMEOUT = 15;
    private const USER_AGENT = 'Mozilla/5.0 (compatible; BibliotecaBot/1.0) Safari/537.36';

    private ?\mysqli $db = null;
    private ?object $hookManager = null;
    private ?int $pluginId = null;
    private ?string $googleBooksApiKey = null;
    private bool $settingsLoaded = false;
    private array $googleBooksCache = [];

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
     * Determine if Google Books integration is available
     */
    private function hasGoogleBooksApiKey(): bool
    {
        return $this->getGoogleBooksApiKey() !== '';
    }

    /**
     * Retrieve the cached Google Books API key
     */
    private function getGoogleBooksApiKey(): string
    {
        if (!$this->settingsLoaded) {
            $this->loadSettings();
        }

        return $this->googleBooksApiKey ?? '';
    }

    /**
     * Load plugin settings from the database
     */
    private function loadSettings(): void
    {
        if ($this->settingsLoaded) {
            return;
        }

        $this->settingsLoaded = true;
        $this->googleBooksApiKey = null;

        if ($this->db === null) {
            return;
        }

        if ($this->pluginId === null) {
            $this->pluginId = $this->resolvePluginId();
        }

        if ($this->pluginId === null) {
            return;
        }

        $stmt = $this->db->prepare("
            SELECT setting_value
            FROM plugin_settings
            WHERE plugin_id = ? AND setting_key = 'google_books_api_key'
            LIMIT 1
        ");

        if ($stmt === false) {
            return;
        }

        $stmt->bind_param('i', $this->pluginId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row && trim((string)$row['setting_value']) !== '') {
            $this->googleBooksApiKey = trim((string)$row['setting_value']);
        }
    }

    /**
     * Resolve the plugin ID by name (fallback when runtime loading skips setPluginId)
     */
    private function resolvePluginId(): ?int
    {
        if ($this->db === null) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT id FROM plugins WHERE name = 'open-library' LIMIT 1");
        if ($stmt === false) {
            return null;
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row ? (int)$row['id'] : null;
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
        if ($this->hasGoogleBooksApiKey()) {
            $sources['googlebooks'] = [
                'name' => 'Google Books API',
                'url_pattern' => self::GOOGLE_BOOKS_BASE . '?q=isbn:{isbn}',
                'enabled' => true,
                'priority' => 4, // Higher priority than Open Library but lower than Scraping Pro
                'fields' => ['title', 'subtitle', 'authors', 'publisher', 'publishedDate',
                            'pageCount', 'isbn', 'description', 'image', 'categories', 'language'],
            ];
        }

        $sources['openlibrary'] = [
            'name' => 'Open Library',
            'url_pattern' => self::API_BASE . '/isbn/{isbn}.json',
            'enabled' => true,
            'priority' => 5, // High priority - reliable source
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

        // Try Google Books first if API key is available
        if ($this->isGoogleBooksEnabled($sources)) {
            $googleResult = $this->fetchFromGoogleBooks($isbn);
            if ($googleResult !== null) {
                return $googleResult;
            }
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
                return null; // Let default scraper handle it
            }

            // Check if we have an error response from API
            if (isset($editionData['error'])) {
                error_log("[OpenLibrary] API error for ISBN $isbn: {$editionData['error']}");
                return null;
            }

            // Validate we have at least a title
            if (empty($editionData['title'])) {
                error_log("[OpenLibrary] No title found for ISBN: $isbn");
                return null;
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
            } elseif ($this->hasGoogleBooksApiKey()) {
                $googleCover = $this->fetchFromGoogleBooks($isbn);
                if ($googleCover && !empty($googleCover['image'])) {
                    $payload['image'] = $googleCover['image'];
                }
            }
        }

        return $payload;
    }

    /**
     * Check if Google Books is enabled as a source
     */
    private function isGoogleBooksEnabled(array $sources): bool
    {
        if (!$this->hasGoogleBooksApiKey()) {
            return false;
        }

        if (!isset($sources['googlebooks'])) {
            return true;
        }

        return !empty($sources['googlebooks']['enabled']);
    }

    /**
     * Fetch book data from Google Books API
     */
    private function fetchFromGoogleBooks(string $isbn): ?array
    {
        if (!$this->hasGoogleBooksApiKey()) {
            return null;
        }

        if (array_key_exists($isbn, $this->googleBooksCache)) {
            return $this->googleBooksCache[$isbn];
        }

        $apiKey = $this->getGoogleBooksApiKey();
        if ($apiKey === '') {
            $this->googleBooksCache[$isbn] = null;
            return null;
        }

        $params = http_build_query([
            'q' => 'isbn:' . $isbn,
            'key' => $apiKey,
            'maxResults' => 5,
            'projection' => 'full'
        ]);

        $url = self::GOOGLE_BOOKS_BASE . '?' . $params;
        $response = $this->makeGoogleApiRequest($url);

        if (!$response || empty($response['items'])) {
            $this->googleBooksCache[$isbn] = null;
            return null;
        }

        foreach ($response['items'] as $item) {
            if (!empty($item['volumeInfo'])) {
                $mapped = $this->mapGoogleVolumeToPayload($item, $isbn);
                if ($mapped !== null) {
                    $this->googleBooksCache[$isbn] = $mapped;
                    return $mapped;
                }
            }
        }

        $this->googleBooksCache[$isbn] = null;
        return null;
    }

    /**
     * Perform an HTTP request to Google Books API
     */
    private function makeGoogleApiRequest(string $url): ?array
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
            error_log("[OpenLibrary] Google Books API request failed (HTTP {$httpCode})");
            return null;
        }

        $data = json_decode($response, true);
        return $data ?: null;
    }

    /**
     * Map Google Books API data to the scraper payload
     */
    private function mapGoogleVolumeToPayload(array $item, string $fallbackIsbn): ?array
    {
        $volumeInfo = $item['volumeInfo'] ?? [];
        if (empty($volumeInfo['title'])) {
            return null;
        }

        $identifiers = $volumeInfo['industryIdentifiers'] ?? [];
        $isbn13 = $this->extractGoogleIdentifier($identifiers, 'ISBN_13');
        $isbn10 = $this->extractGoogleIdentifier($identifiers, 'ISBN_10');
        $primaryIsbn = $isbn13 ?: ($isbn10 ?: $fallbackIsbn);

        return [
            'title' => $volumeInfo['title'] ?? '',
            'subtitle' => $volumeInfo['subtitle'] ?? '',
            'author' => !empty($volumeInfo['authors']) ? implode(', ', $volumeInfo['authors']) : '',
            'authors' => $volumeInfo['authors'] ?? [],
            'publisher' => $volumeInfo['publisher'] ?? '',
            'isbn' => $primaryIsbn,
            'ean' => $isbn13 ?: '',
            'year' => $this->parseYearFromString($volumeInfo['publishedDate'] ?? ''),
            'pages' => $volumeInfo['pageCount'] ?? null,
            'weight' => $volumeInfo['weight'] ?? null,
            'format' => $volumeInfo['printType'] ?? '',
            'description' => $volumeInfo['description'] ?? '',
            'image' => $this->extractGoogleCover($volumeInfo),
            'series' => $this->extractGoogleSeries($volumeInfo),
            'notes' => $this->buildGoogleNotes($item),
            'tipologia' => $this->inferTipologiaFromSubjects($volumeInfo['categories'] ?? []),
            'source' => isset($item['id']) ? self::GOOGLE_BOOKS_BASE . '/' . urlencode($item['id']) : self::GOOGLE_BOOKS_BASE,
            '_googlebooks_volume_id' => $item['id'] ?? null,
        ];
    }

    /**
     * Extract identifiers from Google Books data
     */
    private function extractGoogleIdentifier(array $identifiers, string $type): ?string
    {
        foreach ($identifiers as $identifier) {
            if (($identifier['type'] ?? '') === $type && !empty($identifier['identifier'])) {
                return $identifier['identifier'];
            }
        }

        return null;
    }

    /**
     * Extract best cover URL from Google Books image links
     */
    private function extractGoogleCover(array $volumeInfo): ?string
    {
        if (empty($volumeInfo['imageLinks']) || !is_array($volumeInfo['imageLinks'])) {
            return null;
        }

        $order = ['extraLarge', 'large', 'medium', 'small', 'thumbnail', 'smallThumbnail'];
        foreach ($order as $size) {
            if (!empty($volumeInfo['imageLinks'][$size])) {
                return $volumeInfo['imageLinks'][$size];
            }
        }

        return null;
    }

    /**
     * Extract series/collana information from Google Books
     */
    private function extractGoogleSeries(array $volumeInfo): string
    {
        if (!empty($volumeInfo['series'])) {
            return is_array($volumeInfo['series']) ? implode(', ', $volumeInfo['series']) : (string)$volumeInfo['series'];
        }

        if (!empty($volumeInfo['seriesInfo']['series'])) {
            return (string)$volumeInfo['seriesInfo']['series'];
        }

        if (!empty($volumeInfo['seriesInfo']['bookDisplayNumber'])) {
            return (string)$volumeInfo['seriesInfo']['bookDisplayNumber'];
        }

        return '';
    }

    /**
     * Build additional notes from Google Books data
     */
    private function buildGoogleNotes(array $item): string
    {
        $volumeInfo = $item['volumeInfo'] ?? [];
        $notes = [];

        if (!empty($volumeInfo['categories'])) {
            $notes[] = 'Categorie: ' . implode(', ', $volumeInfo['categories']);
        }

        if (!empty($volumeInfo['dimensions']) && is_array($volumeInfo['dimensions'])) {
            $dimensionParts = [];
            foreach (['height' => 'H', 'width' => 'L', 'thickness' => 'P'] as $key => $label) {
                if (!empty($volumeInfo['dimensions'][$key])) {
                    $dimensionParts[] = "{$label}: {$volumeInfo['dimensions'][$key]}";
                }
            }
            if (!empty($dimensionParts)) {
                $notes[] = 'Dimensioni: ' . implode(', ', $dimensionParts);
            }
        }

        if (!empty($volumeInfo['language'])) {
            $notes[] = 'Lingua: ' . strtoupper((string)$volumeInfo['language']);
        }

        if (!empty($volumeInfo['infoLink'])) {
            $notes[] = 'Info: ' . $volumeInfo['infoLink'];
        } elseif (!empty($item['accessInfo']['webReaderLink'])) {
            $notes[] = 'Google Books: ' . $item['accessInfo']['webReaderLink'];
        }

        return implode("\n", $notes);
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
        return $this->parseYearFromString($editionData['publish_date'] ?? '');
    }

    /**
     * Parse a year from arbitrary date strings
     */
    private function parseYearFromString(?string $dateStr): ?int
    {
        if (empty($dateStr)) {
            return null;
        }

        if (preg_match('/(\d{4})/', (string)$dateStr, $matches)) {
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
        if (!$workData) {
            return '';
        }

        return $this->inferTipologiaFromSubjects($workData['subjects'] ?? []);
    }

    /**
     * Infer tipologia (Narrativa/Saggistica) from generic subject/category lists
     */
    private function inferTipologiaFromSubjects(array $subjects): string
    {
        if (empty($subjects)) {
            return '';
        }

        $fictionKeywords = ['fiction', 'novel', 'fantasy', 'science fiction', 'mystery', 'thriller'];
        foreach ($subjects as $subject) {
            foreach ($fictionKeywords as $keyword) {
                if (stripos($subject, $keyword) !== false) {
                    return 'Narrativa';
                }
            }
        }

        $nonfictionKeywords = ['history', 'biography', 'science', 'philosophy', 'psychology', 'essay'];
        foreach ($subjects as $subject) {
            foreach ($nonfictionKeywords as $keyword) {
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
}

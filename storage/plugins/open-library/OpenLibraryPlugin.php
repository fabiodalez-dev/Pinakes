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
    private const TIMEOUT = 6;
    private const USER_AGENT = 'Mozilla/5.0 (compatible; BibliotecaBot/1.0) Safari/537.36';
    private const MIN_COVER_SIZE_BYTES = 1000;

    private ?\mysqli $db = null;
    /** @phpstan-ignore property.onlyWritten */
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
        \App\Support\SecureLogger::debug('[OpenLibrary] Plugin installed');
        $this->registerHooks();
    }

    /**
     * Called when plugin is activated via PluginManager
     */
    public function onActivate(): void
    {
        $this->registerHooks();
        \App\Support\SecureLogger::debug('[OpenLibrary] Plugin activated');
    }

    /**
     * Set the plugin ID (called by PluginManager after installation)
     */
    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
        $this->ensureHooksRegistered();
    }

    /**
     * Register hooks in the database for persistence
     */
    private function registerHooks(): void
    {
        if ($this->db === null || $this->pluginId === null) {
            \App\Support\SecureLogger::warning('[OpenLibrary] Cannot register hooks: missing DB or plugin ID');
            return;
        }

        $hooks = [
            ['scrape.sources', 'addOpenLibrarySource', 5],
            ['scrape.fetch.custom', 'fetchFromOpenLibrary', 5],
            ['scrape.data.modify', 'enrichWithOpenLibraryData', 10],
            ['app.routes.register', 'registerRoutes', 10],
        ];

        // Delete existing hooks for this plugin
        $this->deleteHooks();

        foreach ($hooks as [$hookName, $method, $priority]) {
            $stmt = $this->db->prepare(
                "INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active, created_at)
                 VALUES (?, ?, ?, ?, ?, 1, NOW())"
            );

            if ($stmt === false) {
                \App\Support\SecureLogger::error("[OpenLibrary] Failed to prepare statement: " . $this->db->error);
                continue;
            }

            $callbackClass = 'OpenLibraryPlugin';
            $stmt->bind_param('isssi', $this->pluginId, $hookName, $callbackClass, $method, $priority);

            if (!$stmt->execute()) {
                \App\Support\SecureLogger::error("[OpenLibrary] Failed to register hook {$hookName}: " . $stmt->error);
            }

            $stmt->close();
        }

        \App\Support\SecureLogger::debug('[OpenLibrary] Hooks registered');
    }

    private function ensureHooksRegistered(): void
    {
        if ($this->db === null || $this->pluginId === null) {
            return;
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) AS total FROM plugin_hooks WHERE plugin_id = ?");
        if ($stmt === false) {
            return;
        }

        $stmt->bind_param('i', $this->pluginId);

        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            if ((int)($row['total'] ?? 0) === 0) {
                $this->registerHooks();
            }
        }

        $stmt->close();
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
     * Uses intelligent merging to combine data from Open Library with existing data
     * from other sources, filling empty fields without overwriting existing data.
     *
     * @param mixed $existing Previous accumulated result from other plugins
     * @param array $sources Available sources
     * @param string $isbn ISBN to search
     * @return array|null Merged book data or previous result if no new data
     */
    public function fetchFromOpenLibrary($existing, array $sources, string $isbn): ?array
    {
        // Only handle if Open Library source is enabled
        if (!isset($sources['openlibrary']) || !$sources['openlibrary']['enabled']) {
            return $existing; // Pass through existing data unchanged
        }

        try {
            // Try Google Books FIRST if API key is available (most complete data source)
            $googleBooksData = $this->tryGoogleBooks($isbn);
            if ($googleBooksData !== null) {
                // Merge Google Books data with existing
                return $this->mergeBookData($existing, $googleBooksData, 'google-books');
            }

            // If Google Books fails, try Open Library API
            $editionData = $this->fetchEditionByISBN($isbn);

            if (empty($editionData) || isset($editionData['error']) || empty($editionData['title'])) {
                // Nothing found - return existing data unchanged
                return $existing;
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
            $openLibraryData = [
                'title' => (string) $editionData['title'],
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

            // Merge with existing data
            return $this->mergeBookData($existing, $openLibraryData, 'open-library');

        } catch (\Throwable $e) {
            // Log error but don't fail - pass through existing data
            \App\Support\SecureLogger::error('OpenLibrary Plugin Error: ' . $e->getMessage());
            return $existing;
        }
    }

    /**
     * Merge book data from a new source into existing data
     *
     * @param array|null $existing Existing accumulated data
     * @param array|null $new New data from current source
     * @param string $source Source identifier for quality scoring
     * @return array|null Merged data
     */
    private function mergeBookData(?array $existing, ?array $new, string $source = 'default'): ?array
    {
        // Use BookDataMerger if available
        if (class_exists('\\App\\Support\\BookDataMerger')) {
            return \App\Support\BookDataMerger::merge($existing, $new, $source);
        }

        // Fallback: simple merge
        if ($new === null || empty($new)) {
            return $existing;
        }

        if ($existing === null || empty($existing)) {
            return $new;
        }

        // Fill empty fields in existing data with new data
        foreach ($new as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue; // Skip internal fields
            }
            // FIX F072: restore || === null branch (matches "fill empty fields" intent; aligns with api-book-scraper)
            // Use array_key_exists so === null branch remains meaningful for PHPStan
            if (!array_key_exists($key, $existing) || $existing[$key] === '' || $existing[$key] === null ||
                (is_array($existing[$key]) && empty($existing[$key]))) {
                $existing[$key] = $value;
            }
        }

        return $existing;
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
            $editionData = [];
            if (!empty($payload['title'])) {
                $editionData['title'] = $payload['title'];
            }

            $coverUrl = $this->getCoverUrl($isbn, $editionData);
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
     * Scrape a book cover from Goodreads' public book page.
     *
     * Goodreads' /search endpoint sits behind an anti-bot challenge (HTTP 202),
     * but the canonical /book/isbn/{isbn} page returns full HTML (200). We read
     * the Open Graph og:image meta tag — Goodreads fills it with the cover on
     * Amazon's CDN, and it is stable across layout changes (unlike the CSS-class
     * scraping the retired bookcover.longitood.com service relied on).
     *
     * @param string $isbn ISBN-10 or ISBN-13
     * @return string|null Cover image URL, or null if not on Goodreads / on error
     */
    private function getGoodreadsCover(string $isbn): ?string
    {
        $clean = preg_replace('/[^0-9Xx]/', '', $isbn);
        if (!is_string($clean) || (strlen($clean) !== 10 && strlen($clean) !== 13)) {
            return null;
        }

        $html = $this->fetchGoodreadsHtml($clean);
        if ($html === null) {
            return null;
        }
        return $this->extractGoodreadsCoverFromHtml($html);
    }

    /**
     * Fetch the Goodreads book page HTML.
     *
     * Goodreads sits behind Cloudflare, which serves a 202 anti-bot interstitial
     * to PHP's HTTP stack (Guzzle/libcurl present a TLS/JA3 fingerprint Cloudflare
     * challenges) — so an in-process HTTP client never gets the real page. The
     * system `curl` binary's fingerprint passes, so we shell out to it when the
     * host allows process execution; otherwise we give up gracefully (the caller
     * simply gets no cover). $isbn is already validated to [0-9Xx]{10,13} by the
     * caller and is passed through escapeshellarg, so no shell injection is
     * possible.
     *
     * @param string $isbn Validated ISBN-10/13 (digits and X only)
     * @return string|null Raw HTML, or null when unreachable / exec unavailable
     */
    private function fetchGoodreadsHtml(string $isbn): ?string
    {
        $curl = $this->findCurlBinary();
        if ($curl === null) {
            return null;
        }
        $url = 'https://www.goodreads.com/book/isbn/' . $isbn;
        // Hardening (#303/rc.2 review): --proto =https and --max-redirs cap where a
        // redirect can go (only Goodreads-controlled responses matter, but block
        // scheme/host abuse if Goodreads ever redirected oddly); --max-filesize caps
        // the buffered body (shell_exec reads all stdout into memory).
        $cmd = sprintf(
            '%s -sL --proto =https --max-time 8 --max-redirs 3 --max-filesize 5242880 -A %s %s 2>/dev/null',
            escapeshellarg($curl),
            escapeshellarg(self::USER_AGENT),
            escapeshellarg($url)
        );
        try {
            $out = shell_exec($cmd);
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::warning('[OpenLibrary] Goodreads cover fetch error: ' . $e->getMessage());
            return null;
        }
        return (is_string($out) && $out !== '') ? $out : null;
    }

    /**
     * Locate a usable system `curl` binary, or null when process execution is
     * unavailable (shell_exec / escapeshellarg disabled — common on hardened
     * shared hosting) or no curl is installed. Memoized per process.
     *
     * @return string|null Absolute path to curl, or null
     */
    private function findCurlBinary(): ?string
    {
        static $resolved = false;
        static $path = null;
        if ($resolved) {
            return $path;
        }
        $resolved = true;

        // Both are required by fetchGoodreadsHtml; function_exists() already
        // returns false for a disable_functions-disabled function, so guarding
        // here means the caller never reaches an uncaught "undefined function".
        if (!function_exists('shell_exec') || !function_exists('escapeshellarg')) {
            return $path = null;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('shell_exec', $disabled, true)) {
            return $path = null;
        }
        foreach (['/usr/bin/curl', '/bin/curl', '/usr/local/bin/curl'] as $candidate) {
            if (@is_executable($candidate)) {
                return $path = $candidate;
            }
        }
        $found = @shell_exec('command -v curl 2>/dev/null');
        $found = is_string($found) ? trim($found) : '';
        if ($found !== '' && @is_executable($found)) {
            return $path = $found;
        }
        return $path = null;
    }

    /**
     * Extract the cover URL from a Goodreads book page's Open Graph og:image tag,
     * accepting only the known Goodreads/Amazon image CDNs. Pure string parsing,
     * separated from the HTTP fetch so it can be unit-tested deterministically.
     *
     * @param string $html Raw HTML of the Goodreads book page
     * @return string|null Cover image URL, or null if absent / not a trusted CDN
     */
    private function extractGoodreadsCoverFromHtml(string $html): ?string
    {
        // Open Graph cover image — match property/content in either order.
        if (!preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)
            && !preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m)) {
            return null;
        }

        $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        // Only trust covers served from the known Goodreads/Amazon image CDNs.
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($scheme) || strtolower($scheme) !== 'https' || !is_string($host)) {
            return null;
        }

        $host = strtolower(rtrim($host, '.'));
        $trustedHost = $host === 'gr-assets.com'
            || str_ends_with($host, '.gr-assets.com')
            || $host === 'media-amazon.com'
            || str_ends_with($host, '.media-amazon.com');
        if (!$trustedHost) {
            return null;
        }

        return $url;
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

        // Fallback to ISBN-based cover from OpenLibrary
        $url = self::COVERS_BASE . '/b/isbn/' . $isbn . '-L.jpg';
        if ($this->checkCoverExists($url)) {
            return $url;
        }

        // Last resort: scrape the cover from Goodreads' public book page.
        $goodreadsCover = $this->getGoodreadsCover($isbn);
        if ($goodreadsCover !== null) {
            return $goodreadsCover;
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
            CURLOPT_TIMEOUT => 3,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        /* curl_close(): no-op since PHP 8.0, deprecated 8.5 */

        if ($httpCode !== 200) {
            return false;
        }

        // Check Content-Type if available
        if (!empty($contentType) && strpos($contentType, 'image/') === 0) {
            return true;
        }

        // Some servers (e.g. covers.openlibrary.org) omit Content-Type;
        // accept if URL has an image extension and response is non-trivial.
        // Open Library returns a tiny 1x1 GIF placeholder (43 bytes) instead of 404,
        // so we must verify actual content size.
        $urlPath = parse_url($url, PHP_URL_PATH);
        if (empty($contentType) && is_string($urlPath) && preg_match('/\.(jpe?g|png|gif|webp)$/i', $urlPath)) {
            if ($contentLength > self::MIN_COVER_SIZE_BYTES) {
                return true;
            }
            // Content-Length unknown (-1) or too small: do a GET to check actual size
            if ($contentLength === -1.0) {
                $ch2 = curl_init();
                curl_setopt_array($ch2, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 3,
                    CURLOPT_USERAGENT => self::USER_AGENT,
                    CURLOPT_RANGE => '0-1023',
                ]);
                $partial = curl_exec($ch2);
                $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                /* curl_close(): no-op since PHP 8.0, deprecated 8.5 */
                return in_array($httpCode2, [200, 206], true)
                    && is_string($partial) && strlen($partial) > self::MIN_COVER_SIZE_BYTES;
            }
            return false;
        }

        return false;
    }

    /**
     * Make an API request to Open Library
     *
     * @param string $url API URL
     * @return array|null Response data or null
     */
    private function makeApiRequest(string $url): ?array
    {
        $res = \App\Support\HttpClient::get(
            $url,
            ['Accept' => 'application/json'],
            ['timeout' => self::TIMEOUT, 'user_agent' => self::USER_AGENT]
        );

        if (!$res['ok'] || $res['status'] !== 200 || !$res['body']) {
            return null;
        }

        $data = json_decode($res['body'], true);
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
            return null;
        }

        return $this->fetchFromGoogleBooks($isbn, $apiKey);
    }

    /**
     * Get application locale from system settings
     * Returns language code (e.g., "en" from "en_US", "it" from "it_IT")
     *
     * @return string Default: "en"
     */
    private function getAppLocale(): string
    {
        if ($this->db === null) {
            return 'en';
        }

        $stmt = $this->db->prepare(
            "SELECT setting_value FROM system_settings
             WHERE category = 'app' AND setting_key = 'locale' LIMIT 1"
        );

        if (!$stmt) {
            return 'en';
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        $locale = $row['setting_value'] ?? 'en_US';

        // Extract language code from locale (en_US → en, it_IT → it)
        $parts = explode('_', $locale);
        return strtolower($parts[0]);
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
            $stmt2 = $this->db->prepare("SELECT id FROM plugins WHERE name = ? LIMIT 1");
            if ($stmt2 === false) {
                return null;
            }
            $pluginName = 'open-library';
            $stmt2->bind_param('s', $pluginName);
            $stmt2->execute();
            $result = $stmt2->get_result();
            if ($result) {
                $row = $result->fetch_assoc();
                $pluginId = $row['id'] ?? null;
                $result->free();
            }
            $stmt2->close();
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
        $pluginKey = ($_ENV['PLUGIN_ENCRYPTION_KEY'] ?? '') ?: (getenv('PLUGIN_ENCRYPTION_KEY') ?: '');
        $appKey    = ($_ENV['APP_KEY'] ?? '') ?: (getenv('APP_KEY') ?: '');
        $rawKey    = $pluginKey !== '' ? $pluginKey : ($appKey !== '' ? $appKey : null);

        if ($rawKey !== null && $pluginKey === '') {
            \App\Support\SecureLogger::warning('[OpenLibrary] Using APP_KEY as fallback for plugin encryption. Set PLUGIN_ENCRYPTION_KEY for isolation.');
        }

        if (!$rawKey) {
            \App\Support\SecureLogger::error('[OpenLibrary] Encryption key not found, cannot decrypt setting');
            return null;
        }

        $key = hash('sha256', (string)$rawKey, true);

        // Remove "ENC:" prefix and decode
        $payload = base64_decode(substr($encryptedValue, 4), true);
        if ($payload === false || strlen($payload) <= 28) {
            \App\Support\SecureLogger::error('[OpenLibrary] Invalid encrypted payload');
            return null;
        }

        // Extract IV (12 bytes), tag (16 bytes), and ciphertext
        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $ciphertext = substr($payload, 28);

        try {
            $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plaintext === false) {
                \App\Support\SecureLogger::error('[OpenLibrary] Failed to decrypt setting value');
                return null;
            }
            return $plaintext;
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('[OpenLibrary] Decryption exception: ' . $e->getMessage());
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
        // Get application locale for language-specific results
        $language = $this->getAppLocale();

        $url = sprintf(
            'https://www.googleapis.com/books/v1/volumes?q=isbn:%s&key=%s&langRestrict=%s',
            urlencode($isbn),
            urlencode($apiKey),
            urlencode($language)
        );

        $res = \App\Support\HttpClient::get(
            $url,
            [],
            ['timeout' => 10, 'user_agent' => self::USER_AGENT]
        );

        if (!$res['ok'] || $res['status'] !== 200 || empty($res['body'])) {
            return null;
        }

        $data = json_decode($res['body'], true);

        if (empty($data['items'][0]['volumeInfo'])) {
            return null;
        }

        $item = $data['items'][0];  // Full item with volumeInfo and saleInfo
        $volume = $item['volumeInfo'];

        // Extract authors
        $authors = $volume['authors'] ?? [];
        $authorString = implode(', ', $authors);

        // Extract ISBN-13 and ISBN-10
        $isbn13 = '';
        $isbn10 = '';
        $isbnValue = $isbn;

        if (!empty($volume['industryIdentifiers'])) {
            foreach ($volume['industryIdentifiers'] as $id) {
                if ($id['type'] === 'ISBN_13') {
                    $isbn13 = $id['identifier'];
                    $isbnValue = $isbn13;  // Prefer ISBN-13 as primary
                } elseif ($id['type'] === 'ISBN_10') {
                    $isbn10 = $id['identifier'];
                    // Use ISBN-10 as fallback if no ISBN-13
                    if (!$isbn13) {
                        $isbnValue = $isbn10;
                    }
                }
            }
        }

        // Extract categories for keywords
        $keywords = '';
        if (!empty($volume['categories'])) {
            $keywords = implode(', ', $volume['categories']);
        }

        // Extract cover image - use largest available version
        $coverUrl = null;
        if (!empty($volume['imageLinks'])) {
            $imageLinks = $volume['imageLinks'];
            // Priority: extraLarge > large > medium > small > thumbnail > smallThumbnail
            if (!empty($imageLinks['extraLarge'])) {
                $coverUrl = $imageLinks['extraLarge'];
            } elseif (!empty($imageLinks['large'])) {
                $coverUrl = $imageLinks['large'];
            } elseif (!empty($imageLinks['medium'])) {
                $coverUrl = $imageLinks['medium'];
            } elseif (!empty($imageLinks['small'])) {
                $coverUrl = $imageLinks['small'];
            } elseif (!empty($imageLinks['thumbnail'])) {
                $coverUrl = $imageLinks['thumbnail'];
            } elseif (!empty($imageLinks['smallThumbnail'])) {
                $coverUrl = $imageLinks['smallThumbnail'];
            }

            // Ensure HTTPS
            if ($coverUrl !== null) {
                $coverUrl = str_replace('http:', 'https:', $coverUrl);
            }
        }

        // Extract publication date (format: YYYY-MM-DD or YYYY or ISO 8601)
        // Google Books may return: "2018-05-03T00:00:00+02:00", "2018-05-03", or "2018"
        $pubDate = $volume['publishedDate'] ?? null;
        $year = null;
        if ($pubDate) {
            // Normalize ISO 8601 datetime to simple date
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T/', $pubDate, $matches)) {
                // ISO format with time: "2018-05-03T00:00:00+02:00" -> "2018-05-03"
                $pubDate = "{$matches[1]}-{$matches[2]}-{$matches[3]}";
            }

            $year = substr($pubDate, 0, 4);
            // Convert to Italian format if full date (YYYY-MM-DD)
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $pubDate)) {
                $date = \DateTime::createFromFormat('Y-m-d', $pubDate);
                if ($date) {
                    $pubDate = $date->format('d/m/Y');
                }
            }
        }

        // Extract language
        $bookLanguage = $volume['language'] ?? null;
        if ($bookLanguage) {
            // Convert language code to full name if needed (e.g., 'it' -> 'Italiano', 'en' -> 'English')
            $languageNames = [
                'it' => 'Italiano',
                'en' => 'English',
                'fr' => 'Français',
                'de' => 'Deutsch',
                'es' => 'Español',
                'pt' => 'Português',
            ];
            $bookLanguage = $languageNames[$bookLanguage] ?? strtoupper($bookLanguage);
        }

        // Extract price from saleInfo (from parent response, not volume)
        // Try retailPrice first, fallback to listPrice
        $price = null;
        if (!empty($item['saleInfo']['retailPrice'])) {
            $priceData = $item['saleInfo']['retailPrice'];
            $amount = $priceData['amount'] ?? null;
            $currency = $priceData['currencyCode'] ?? 'EUR';

            if ($amount !== null) {
                $price = number_format((float)$amount, 2, '.', '') . ' ' . $currency;
            }
        } elseif (!empty($item['saleInfo']['listPrice'])) {
            // Fallback to listPrice if retailPrice not available
            $priceData = $item['saleInfo']['listPrice'];
            $amount = $priceData['amount'] ?? null;
            $currency = $priceData['currencyCode'] ?? 'EUR';

            if ($amount !== null) {
                $price = number_format((float)$amount, 2, '.', '') . ' ' . $currency;
            }
        }

        // Extract print type and maturity rating
        $printType = $volume['printType'] ?? 'BOOK';
        $maturityRating = $volume['maturityRating'] ?? '';

        // Build format string
        $formatParts = [];
        if ($printType && $printType !== 'BOOK') {
            $formatParts[] = $printType;
        }
        $formatString = implode(', ', $formatParts);

        // Build notes with additional info
        $notesParts = ['Retrieved from Google Books API'];
        if ($maturityRating && $maturityRating !== 'NOT_MATURE') {
            $notesParts[] = "Maturity: $maturityRating";
        }
        $notesString = implode(' | ', $notesParts);

        return [
            'title' => $volume['title'] ?? '',
            'subtitle' => $volume['subtitle'] ?? '',
            'author' => $authorString,
            'authors' => $authors,
            'publisher' => $volume['publisher'] ?? '',
            'isbn' => $isbnValue,
            'isbn10' => $isbn10,
            'isbn13' => $isbn13,
            'ean' => $isbnValue,
            'year' => $year,
            'pubDate' => $pubDate,
            'pages' => $volume['pageCount'] ?? null,
            'description' => $volume['description'] ?? '',
            'image' => $coverUrl,
            'language' => $bookLanguage,
            'keywords' => $keywords,
            'price' => $price,
            'series' => '',
            'format' => $formatString,
            'notes' => $notesString,
            'source' => 'https://books.google.com/books?isbn=' . $isbn,
        ];
    }

    /**
     * Fetch book data from Open Library API directly
     *
     * @param string $isbn ISBN to search
     * @return array|null Book data or null if not found
     */
    public function fetchFromOpenLibraryApi(string $isbn): ?array
    {
        // Create a minimal sources array to enable Open Library
        $sources = [
            'openlibrary' => [
                'name' => 'Open Library',
                'enabled' => true,
            ]
        ];

        return $this->fetchFromOpenLibrary(null, $sources, $isbn);
    }

    /**
     * Register API routes for the plugin
     * Called by app.routes.register hook
     *
     * @param \Slim\App $app Slim application instance
     */
    public function registerRoutes($app): void
    {
        // Register test endpoint for Open Library plugin (admin-only)
        $app->get('/api/open-library/test', function ($request, $response) {
            $queryParams = $request->getQueryParams();
            $isbn = $queryParams['isbn'] ?? '9788804666592'; // Default ISBN for testing

            // Clean ISBN
            $cleanIsbn = preg_replace('/[^0-9X]/', '', strtoupper($isbn));

            // Try to fetch from Open Library
            $result = $this->fetchFromOpenLibraryApi($cleanIsbn);

            if ($result) {
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'plugin' => 'Open Library',
                    'isbn' => $cleanIsbn,
                    'data' => $result,
                    'message' => __('Dati libro recuperati con successo da Open Library')
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                return $response->withHeader('Content-Type', 'application/json');
            } else {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'plugin' => 'Open Library',
                    'isbn' => $cleanIsbn,
                    'message' => __('Libro non trovato nel database Open Library')
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }
        })->add(new \App\Middleware\AdminAuthMiddleware());

        \App\Support\SecureLogger::debug('[OpenLibrary Plugin] Route registered', [
            'route' => '/api/open-library/test'
        ]);
    }
}

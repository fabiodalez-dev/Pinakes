<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Support\DeweyAutoPopulator;
use App\Support\IsbnFormatter;
use App\Support\SecureLogger;

class ScrapeController
{
    /**
     * Normalize text by removing MARC-8 control characters and collapsing whitespace
     * MARC-8 uses NSB (0x88, 0x98) and NSE (0x89, 0x9C) for non-sorting blocks
     */
    private function normalizeText(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        // Remove MARC-8 control characters (NSB/NSE non-sorting markers)
        // These are single-byte characters in MARC-8 encoding: 0x88, 0x89, 0x98, 0x9C
        $text = preg_replace('/[\x{0088}\x{0089}\x{0098}\x{009C}]/u', '', $text) ?? $text;
        // Also remove any other C1 control characters (0x80-0x9F) that might cause issues
        $text = preg_replace('/[\x{0080}-\x{009F}]/u', '', $text) ?? $text;
        // Ensure valid UTF-8 (replace invalid sequences)
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        // Collapse multiple whitespace into single space and trim
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Normalize all text fields in scraped data
     * Removes MARC-8 control characters and normalizes whitespace for ALL text fields
     */
    private function normalizeScrapedData(array $data): array
    {
        // Normalize ALL text fields that could come from any scraping source
        // This includes Z39.50/SRU (MARC), SBN, Open Library, Google Books, etc.
        $textFields = [
            'title', 'subtitle', 'publisher', 'description',
            'series', 'collana',  // Series/collection name
            'keywords', 'language',
            'author',  // Single author string
            'translator',
            'illustrator',
            'source', 'notes',
            'edition', 'format',
            'place', 'country',  // Publication place
        ];
        foreach ($textFields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = $this->normalizeText($data[$field]);
            }
        }

        // Normalize authors array
        if (isset($data['authors']) && is_array($data['authors'])) {
            $data['authors'] = array_map(fn($a) => $this->normalizeText((string)$a), $data['authors']);
        }

        // Normalize any other string fields we might have missed
        // This is a safety net for plugin-provided data
        $skipFields = ['isbn', 'isbn10', 'isbn13', 'ean', 'image', 'pubDate', 'year', 'pages', 'price', 'classificazione_dewey', 'classificazione_dowey'];
        foreach ($data as $key => $value) {
            if (is_string($value) && !in_array($key, $textFields) && !in_array($key, $skipFields)) {
                // Skip URLs and date-like values
                if (!preg_match('/^https?:\/\//i', $value) && !preg_match('/^\d{4}(-\d{2})?(-\d{2})?$/', $value)) {
                    $data[$key] = $this->normalizeText($value);
                }
            }
        }

        return $data;
    }

    public function byIsbn(Request $request, Response $response): Response
    {
        $isbn = trim((string)($request->getQueryParams()['isbn'] ?? ''));
        if ($isbn === '') {
            $response->getBody()->write(json_encode([
                'error' => __('Parametro ISBN mancante.'),
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        // SSRF Protection: Validate ISBN format before constructing URL
        $cleanIsbn = preg_replace('/[^0-9X]/', '', strtoupper($isbn));

        // Validate ISBN format (ISBN-10 or ISBN-13)
        $isValid = $this->isValidIsbn($cleanIsbn);

        // Hook: scrape.isbn.validate - Allow custom ISBN validation
        $isValid = \App\Support\Hooks::apply('scrape.isbn.validate', $isValid, [$cleanIsbn, 'user_input']);

        if (!$isValid) {
            $response->getBody()->write(json_encode([
                'error' => __('Formato ISBN non valido.'),
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Get available scraping sources
        $sources = $this->getDefaultSources();

        // Hook: scrape.sources - Allow plugins to add custom scraping sources
        $sources = \App\Support\Hooks::apply('scrape.sources', $sources, [$cleanIsbn]);

        // Check if any sources are available
        if (empty($sources)) {
            SecureLogger::debug('[ScrapeController] No scraping sources available');
            $response->getBody()->write(json_encode([
                'error' => __('Nessuna fonte di scraping disponibile. Installa almeno un plugin di scraping (es. Open Library o Scraping Pro).'),
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
        }

        SecureLogger::debug('[ScrapeController] Available sources', ['sources' => array_keys($sources)]);

        // Hook: scrape.fetch.custom - Allow plugins to completely replace scraping logic
        $customResult = \App\Support\Hooks::apply('scrape.fetch.custom', null, [$sources, $cleanIsbn]);

        // Check if plugin result has a title (complete data) or only partial data (e.g., cover only)
        $hasCompleteData = is_array($customResult) && !empty($customResult['title']);

        if ($hasCompleteData) {
            SecureLogger::debug('[ScrapeController] ISBN found via plugins', ['isbn' => $cleanIsbn]);

            // Plugin handled scraping completely, use its result
            $payload = $customResult;

            // Plugin returned metadata but no cover image — try built-in sources for cover
            if (empty($payload['image'])) {
                SecureLogger::debug('[ScrapeController] Plugin data has no cover, trying built-in sources', ['isbn' => $cleanIsbn]);
                $coverUrl = $this->findCoverFromBuiltinSources($cleanIsbn);
                if ($coverUrl !== null) {
                    $payload['image'] = $coverUrl;
                    SecureLogger::debug('[ScrapeController] Cover found from built-in source', ['isbn' => $cleanIsbn]);
                }
            }

            // Normalize text fields (remove MARC-8 control characters, collapse whitespace)
            $payload = $this->normalizeScrapedData($payload);

            // Try to enrich with SBN data if Dewey is missing
            if (empty($payload['classificazione_dewey'])) {
                $payload = $this->enrichWithSbnData($payload, $cleanIsbn);
            }

            // Normalize ISBN fields (auto-calculate missing isbn10/isbn13)
            $payload = $this->normalizeIsbnFields($payload, $cleanIsbn);

            // Hook: scrape.response - Modify final JSON response
            $payload = \App\Support\Hooks::apply('scrape.response', $payload, [$cleanIsbn, $sources, ['timestamp' => time()]]);

            // Auto-populate Dewey JSON if classification found (language-aware)
            DeweyAutoPopulator::processBookData($payload);

            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) {
                SecureLogger::error('[ScrapeController] JSON encode failed', [
                    'isbn' => $cleanIsbn,
                    'json_error' => json_last_error_msg()
                ]);
                $response->getBody()->write(json_encode([
                    'error' => __('Impossibile generare la risposta JSON.'),
                ], JSON_UNESCAPED_UNICODE));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write($json);
            return $response->withHeader('Content-Type', 'application/json');
        }

        // Plugins returned no data or only partial data (e.g., cover only) - try built-in fallbacks
        SecureLogger::debug('[ScrapeController] Trying built-in fallbacks', ['isbn' => $cleanIsbn]);

        // Built-in fallback: try Google Books (no key or env GOOGLE_BOOKS_API_KEY) then Open Library
        $fallbackData = $this->fallbackFromGoogleBooks($cleanIsbn);
        if ($fallbackData === null) {
            $fallbackData = $this->fallbackFromOpenLibrary($cleanIsbn);
        }

        // If fallback found data but no cover, try Goodreads cover API
        if ($fallbackData !== null && empty($fallbackData['image'])) {
            $goodreadsCover = $this->fallbackCoverFromGoodreads($cleanIsbn);
            if ($goodreadsCover !== null) {
                $fallbackData['image'] = $goodreadsCover;
            }
        }

        if ($fallbackData !== null) {
            // Merge partial plugin data (e.g., cover from Goodreads) into fallback data
            if (is_array($customResult)) {
                // Fallback data is the base, plugin data fills gaps (like cover)
                foreach ($customResult as $key => $value) {
                    // Check if value from plugin is not empty (handles strings, arrays, null)
                    $valueNotEmpty = $value !== '' && $value !== null && $value !== [];
                    // Check if fallback value is empty or missing
                    $fallbackEmpty = !isset($fallbackData[$key])
                        || $fallbackData[$key] === ''
                        || $fallbackData[$key] === null
                        || $fallbackData[$key] === [];
                    if ($valueNotEmpty && $fallbackEmpty) {
                        $fallbackData[$key] = $value;
                    }
                }
                SecureLogger::debug('[ScrapeController] Merged plugin partial data', ['isbn' => $cleanIsbn]);
            }

            // Normalize text fields (remove MARC-8 control characters, collapse whitespace)
            $fallbackData = $this->normalizeScrapedData($fallbackData);

            // Try to enrich with SBN data if Dewey is missing
            if (empty($fallbackData['classificazione_dewey'])) {
                $fallbackData = $this->enrichWithSbnData($fallbackData, $cleanIsbn);
            }

            // Normalize ISBN fields (auto-calculate missing isbn10/isbn13)
            $fallbackData = $this->normalizeIsbnFields($fallbackData, $cleanIsbn);

            // Ensure plugins can still modify/log the final payload just like regular results
            $fallbackData = \App\Support\Hooks::apply('scrape.response', $fallbackData, [$cleanIsbn, $sources, ['timestamp' => time()]]);

            // Auto-populate Dewey JSON if classification found (language-aware)
            DeweyAutoPopulator::processBookData($fallbackData);

            $json = json_encode($fallbackData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) {
                SecureLogger::error('[ScrapeController] JSON encode failed (fallback)', [
                    'isbn' => $cleanIsbn,
                    'json_error' => json_last_error_msg()
                ]);
                $response->getBody()->write(json_encode([
                    'error' => __('Impossibile generare la risposta JSON.'),
                ], JSON_UNESCAPED_UNICODE));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write($json);
            return $response->withHeader('Content-Type', 'application/json');
        }

        // Even for 404, provide normalized ISBN variants for form population
        $errorData = [
            'error' => sprintf(
                __('ISBN non trovato. Fonti consultate: %s'),
                implode(', ', array_map(fn($s) => $s['name'] ?? 'Unknown', $sources))
            ),
            'isbn' => $cleanIsbn,
            'sources_checked' => array_keys($sources),
        ];

        // Add calculated ISBN variants so form can populate isbn10/isbn13 fields
        $variants = IsbnFormatter::getAllVariants($cleanIsbn);
        if (!empty($variants['isbn10'])) {
            $errorData['isbn10'] = $variants['isbn10'];
        }
        if (!empty($variants['isbn13'])) {
            $errorData['isbn13'] = $variants['isbn13'];
        }

        $response->getBody()->write(json_encode($errorData, JSON_UNESCAPED_UNICODE));

        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    /**
     * Get default scraping sources
     *
     * @return array Array of scraping sources
     */
    private function getDefaultSources(): array
    {
        // Built-in sources always available (no plugin required)
        return [
            'google_books' => [
                'name' => 'Google Books',
                'url_pattern' => 'https://www.googleapis.com/books/v1/volumes?q=isbn:{isbn}',
                'priority' => 50,
                'fields' => ['title', 'authors', 'publisher', 'description', 'image', 'pages', 'isbn'],
            ],
            'openlibrary' => [
                'name' => 'Open Library',
                'url_pattern' => 'https://openlibrary.org/isbn/{isbn}.json',
                'priority' => 60,
                'fields' => ['title', 'authors', 'publisher', 'description', 'image'],
            ],
        ];
    }

    /**
     * Validate ISBN format (ISBN-10 or ISBN-13)
     */
    private function isValidIsbn(string $isbn): bool
    {
        $isbn = preg_replace('/[^0-9X]/', '', strtoupper($isbn));

        // Check ISBN-13 format
        if (strlen($isbn) === 13) {
            if (!ctype_digit($isbn)) {
                return false;
            }
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += (int)$isbn[$i] * (($i % 2) === 0 ? 1 : 3);
            }
            $checkDigit = (10 - ($sum % 10)) % 10;
            return ((int)$isbn[12]) === $checkDigit;
        }

        // Check ISBN-10 format
        if (strlen($isbn) === 10) {
            $sum = 0;
            for ($i = 0; $i < 9; $i++) {
                if (!ctype_digit($isbn[$i])) {
                    return false;
                }
                $sum += (int)$isbn[$i] * (10 - $i);
            }
            $checkChar = $isbn[9];
            $checkDigit = (11 - ($sum % 11)) % 11;
            $expectedCheck = ($checkDigit === 10) ? 'X' : (string)$checkDigit;
            return $checkChar === $expectedCheck;
        }

        return false;
    }

    /**
     * Minimal Google Books fallback when no plugin handled the ISBN.
     */
    private function fallbackFromGoogleBooks(string $isbn): ?array
    {
        // Rate limiting to prevent API bans
        if (!$this->checkRateLimit('google_books', 60)) {
            SecureLogger::debug('[ScrapeController] Google Books API rate limit exceeded', ['isbn' => $isbn]);
            return null;
        }

        $apiKey = getenv('GOOGLE_BOOKS_API_KEY') ?: '';
        $url = "https://www.googleapis.com/books/v1/volumes?q=isbn:" . urlencode($isbn);
        if ($apiKey !== '') {
            $url .= '&key=' . urlencode($apiKey);
        }

        $json = $this->safeHttpGet($url, 10);
        if (!$json) {
            return null;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload) || empty($payload['items'][0]['volumeInfo'])) {
            return null;
        }

        $item = $payload['items'][0];  // Full item with volumeInfo and saleInfo
        $info = $item['volumeInfo'];
        $authors = isset($info['authors']) && is_array($info['authors']) ? $info['authors'] : [];

        // Extract best quality image
        $image = null;
        if (!empty($info['imageLinks'])) {
            $imageLinks = $info['imageLinks'];
            $image = $imageLinks['extraLarge'] ?? $imageLinks['large'] ?? $imageLinks['medium'] ??
                     $imageLinks['small'] ?? $imageLinks['thumbnail'] ?? $imageLinks['smallThumbnail'] ?? null;
        }

        // Extract ISBN-13 and ISBN-10
        $isbn13 = '';
        $isbn10 = '';
        $isbnField = $isbn;
        if (!empty($info['industryIdentifiers']) && is_array($info['industryIdentifiers'])) {
            foreach ($info['industryIdentifiers'] as $id) {
                if (($id['type'] ?? '') === 'ISBN_13' && !empty($id['identifier'])) {
                    $isbn13 = $id['identifier'];
                    $isbnField = $isbn13;  // Prefer ISBN-13 as primary
                } elseif (($id['type'] ?? '') === 'ISBN_10' && !empty($id['identifier'])) {
                    $isbn10 = $id['identifier'];
                    // Use ISBN-10 as fallback if no ISBN-13
                    if (!$isbn13) {
                        $isbnField = $isbn10;
                    }
                }
            }
        }

        // Extract categories for keywords
        $keywords = '';
        if (!empty($info['categories']) && is_array($info['categories'])) {
            $keywords = implode(', ', $info['categories']);
        }

        // Extract price from saleInfo
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

        // Extract and normalize publication date
        $pubDate = $info['publishedDate'] ?? '';
        $year = '';
        if ($pubDate) {
            // Normalize ISO 8601 datetime to simple date
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T/', $pubDate, $matches)) {
                // ISO format with time: "2018-05-03T00:00:00+02:00" -> "2018-05-03"
                $pubDate = "{$matches[1]}-{$matches[2]}-{$matches[3]}";
            }

            $year = substr($pubDate, 0, 4);
            // Keep YYYY-MM-DD ISO format for API consistency; frontend will format for display
        }

        // Extract language
        $language = $info['language'] ?? '';
        if ($language) {
            $languageNames = [
                'it' => 'Italiano', 'en' => 'English', 'fr' => 'Français',
                'de' => 'Deutsch', 'es' => 'Español', 'pt' => 'Português',
                'ru' => 'Русский', 'zh' => '中文', 'ja' => '日本語',
                'ar' => 'العربية', 'nl' => 'Nederlands', 'sv' => 'Svenska',
                'no' => 'Norsk', 'da' => 'Dansk', 'fi' => 'Suomi',
                'pl' => 'Polski', 'cs' => 'Čeština', 'hu' => 'Magyar',
                'ro' => 'Română', 'el' => 'Ελληνικά', 'tr' => 'Türkçe',
                'he' => 'עברית', 'hi' => 'हिन्दी', 'ko' => '한국어',
                'th' => 'ไทย', 'la' => 'Latina',
            ];
            $language = $languageNames[$language] ?? strtoupper($language);
        }

        return [
            'title' => $info['title'] ?? '',
            'subtitle' => $info['subtitle'] ?? '',
            'authors' => $authors,
            'publisher' => $info['publisher'] ?? '',
            'pubDate' => $pubDate,
            'year' => $year,
            'pages' => $info['pageCount'] ?? '',
            'isbn' => $isbnField ?: $isbn,
            'isbn10' => $isbn10,
            'isbn13' => $isbn13,
            'ean' => $isbnField ?: $isbn,
            'description' => $info['description'] ?? '',
            'image' => $image,
            'language' => $language,
            'keywords' => $keywords,
            'price' => $price,
        ];
    }

    /**
     * Minimal Open Library fallback when no plugin handled the ISBN.
     */
    private function fallbackFromOpenLibrary(string $isbn): ?array
    {
        // Rate limiting to prevent API bans
        if (!$this->checkRateLimit('openlibrary', 60)) {
            SecureLogger::debug('[ScrapeController] Open Library API rate limit exceeded', ['isbn' => $isbn]);
            return null;
        }

        $url = "https://openlibrary.org/isbn/" . urlencode($isbn) . ".json";
        $json = $this->safeHttpGet($url, 10);
        if (!$json) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        $title = $data['title'] ?? '';
        if ($title === '') {
            return null;
        }

        $authors = [];
        if (!empty($data['authors']) && is_array($data['authors'])) {
            foreach ($data['authors'] as $author) {
                if (!empty($author['key'])) {
                    $authorJson = $this->safeHttpGet('https://openlibrary.org' . $author['key'] . '.json', 5);
                    if ($authorJson) {
                        $a = json_decode($authorJson, true);
                        if (!empty($a['name'])) {
                            $authors[] = $a['name'];
                        }
                    }
                }
            }
        }

        $cover = null;
        if (!empty($data['covers'][0])) {
            $cover = "https://covers.openlibrary.org/b/id/{$data['covers'][0]}-L.jpg";
        }

        return [
            'title' => $title,
            'subtitle' => $data['subtitle'] ?? '',
            'authors' => $authors,
            'publisher' => $data['publishers'][0] ?? '',
            'pubDate' => $data['publish_date'] ?? '',
            'pages' => $data['number_of_pages'] ?? '',
            'isbn' => $isbn,
            'description' => is_array($data['description'] ?? null) ? ($data['description']['value'] ?? '') : ($data['description'] ?? ''),
            'image' => $cover
        ];
    }

    /**
     * Fallback: get cover image URL from Goodreads via bookcover.longitood.com API
     */
    private function fallbackCoverFromGoodreads(string $isbn): ?string
    {
        if (!$this->checkRateLimit('goodreads_cover', 60)) {
            SecureLogger::debug('[ScrapeController] Goodreads cover API rate limit exceeded', ['isbn' => $isbn]);
            return null;
        }

        $url = 'https://bookcover.longitood.com/bookcover/' . urlencode($isbn);
        $json = $this->safeHttpGet($url, 8);
        if (!$json) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['url'])) {
            return null;
        }

        $coverUrl = $data['url'];
        if (!filter_var($coverUrl, FILTER_VALIDATE_URL)) {
            return null;
        }

        SecureLogger::debug('[ScrapeController] Goodreads cover found', ['isbn' => $isbn, 'cover' => $coverUrl]);
        return $coverUrl;
    }

    /**
     * Try built-in sources (Google Books, Open Library, Goodreads) for cover image only.
     * Used when a plugin returned complete metadata but no cover image.
     *
     * @param string $isbn ISBN to search
     * @return string|null Cover image URL or null
     */
    public function findCoverFromBuiltinSources(string $isbn): ?string
    {
        // 1. Try Goodreads cover API (lightest — single URL check)
        $goodreads = $this->fallbackCoverFromGoodreads($isbn);
        if ($goodreads !== null) {
            return $goodreads;
        }

        // 2. Try Google Books (reliable, has good cover database)
        $gbData = $this->fallbackFromGoogleBooks($isbn);
        if (!empty($gbData['image'])) {
            return $gbData['image'];
        }

        // 3. Try Open Library (has covers for many editions)
        $olData = $this->fallbackFromOpenLibrary($isbn);
        if (!empty($olData['image'])) {
            return $olData['image'];
        }

        return null;
    }

    /**
     * Safe HTTP GET with timeout and basic validation.
     */
    private function safeHttpGet(string $url, int $timeout = 10): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_CONNECTTIMEOUT => max(1, $timeout),
            CURLOPT_TIMEOUT => max(2, $timeout + 2),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BibliotecaBot/1.0)'
        ]);
        $result = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($result === false || $httpCode >= 400) {
            curl_close($ch);
            return null;
        }
        curl_close($ch);
        return $result;
    }

    /**
     * Check rate limit for external API calls
     * Prevents IP bans from Google Books and Open Library due to excessive requests
     *
     * @param string $apiName Nome API (es. 'google_books', 'openlibrary')
     * @param int $maxCallsPerMinute Max chiamate al minuto (default 60 — safe for all public APIs, allows batch imports)
     * @return bool True se OK, False se rate limit superato
     */
    private function checkRateLimit(string $apiName, int $maxCallsPerMinute = 60): bool
    {
        $storageDir = __DIR__ . '/../../storage/rate_limits';
        if (!is_dir($storageDir)) {
            if (!@mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
                return true; // Fail open: allow call if storage unavailable
            }
        }

        $rateLimitFile = $storageDir . '/' . $apiName . '.json';

        // Use flock for atomic read-modify-write to prevent TOCTOU race condition
        $fp = fopen($rateLimitFile, 'c+');
        if (!$fp) {
            return true; // Fail open if file can't be opened
        }

        flock($fp, LOCK_EX);
        $now = time();

        // Load existing rate limit data
        $data = ['calls' => [], 'last_cleanup' => $now];
        $json = stream_get_contents($fp);
        if ($json !== false && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        // Remove calls older than 60 seconds
        $data['calls'] = array_filter($data['calls'], fn($timestamp) => ($now - $timestamp) < 60);

        // Check if rate limit exceeded
        if (\count($data['calls']) >= $maxCallsPerMinute) {
            SecureLogger::debug('[ScrapeController] Rate limit exceeded', [
                'api' => $apiName,
                'calls' => \count($data['calls']),
                'limit' => $maxCallsPerMinute
            ]);
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }

        // Record this call
        $data['calls'][] = $now;
        $data['last_cleanup'] = $now;

        // Write back atomically
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        flock($fp, LOCK_UN);
        fclose($fp);

        return true;
    }

    /**
     * Try to enrich book data with SBN-specific fields (Dewey, etc.)
     * by querying with alternate ISBN variants.
     *
     * This method gracefully handles the case where the SBN plugin is not installed.
     *
     * @param array $data Existing book data
     * @param string $originalIsbn The ISBN that was originally searched
     * @return array Enriched book data
     */
    private function enrichWithSbnData(array $data, string $originalIsbn): array
    {
        // Get all ISBN variants (including from scraped data)
        $variants = IsbnFormatter::getAllVariants($originalIsbn);

        if (!empty($data['isbn13'])) {
            $variants['isbn13'] = IsbnFormatter::clean($data['isbn13']);
        }
        if (!empty($data['isbn10'])) {
            $variants['isbn10'] = IsbnFormatter::clean($data['isbn10']);
        }

        // No variants to try
        if (empty($variants)) {
            return $data;
        }

        // Check if SBN plugin is installed
        $sbnClientPath = dirname(__DIR__, 2) . '/storage/plugins/z39-server/classes/SbnClient.php';
        if (!file_exists($sbnClientPath)) {
            SecureLogger::debug('[ScrapeController] SBN plugin not installed, skipping enrichment');
            return $data;
        }

        try {
            require_once $sbnClientPath;
            /** @phpstan-ignore class.notFound (dynamically loaded plugin) */
            $sbnClient = new \Plugins\Z39Server\Classes\SbnClient(timeout: 10);

            foreach ($variants as $format => $isbn) {
                // Skip the original ISBN (already tried via hooks)
                if ($isbn === $originalIsbn) {
                    continue;
                }

                SecureLogger::debug('[ScrapeController] Trying SBN enrichment', ['isbn' => $isbn, 'format' => $format]);
                $sbnResult = $sbnClient->searchByIsbn($isbn);

                if ($sbnResult && !empty($sbnResult['classificazione_dewey'])) {
                    $data['classificazione_dewey'] = $sbnResult['classificazione_dewey'];
                    if (!empty($sbnResult['_dewey_name_sbn'])) {
                        $data['_dewey_name_sbn'] = $sbnResult['_dewey_name_sbn'];
                    }

                    // Fill other missing SBN fields
                    $sbnFields = ['collana', 'numero_pagine', 'lingua', 'isbn13', 'isbn10', 'series', 'pages'];
                    foreach ($sbnFields as $field) {
                        if (empty($data[$field]) && !empty($sbnResult[$field])) {
                            $data[$field] = $sbnResult[$field];
                        }
                    }

                    SecureLogger::debug('[ScrapeController] SBN enrichment successful', [
                        'isbn' => $isbn,
                        'dewey' => $data['classificazione_dewey']
                    ]);
                    break;
                }
            }
        } catch (\Throwable $e) {
            // Silently fail if SBN client has issues - enrichment is optional
            SecureLogger::debug('[ScrapeController] SBN enrichment failed', ['error' => $e->getMessage()]);
        }

        return $data;
    }

    /**
     * Normalize ISBN fields in book data.
     *
     * Ensures both isbn10 and isbn13 fields are populated when possible,
     * by calculating the missing variant from the available one.
     *
     * @param array $data Book data
     * @param string $originalIsbn The ISBN that was originally searched
     * @return array Data with normalized ISBN fields
     */
    private function normalizeIsbnFields(array $data, string $originalIsbn): array
    {
        // First, try to get variants from original search term
        $variants = IsbnFormatter::getAllVariants($originalIsbn);

        // Also check if data already has ISBNs we can use
        if (!empty($data['isbn13']) && empty($variants['isbn13'])) {
            $isbn13Variants = IsbnFormatter::getAllVariants($data['isbn13']);
            $variants = array_merge($variants, $isbn13Variants);
        }
        if (!empty($data['isbn10']) && empty($variants['isbn10'])) {
            $isbn10Variants = IsbnFormatter::getAllVariants($data['isbn10']);
            $variants = array_merge($variants, $isbn10Variants);
        }

        // Also check generic 'isbn' field
        if (!empty($data['isbn'])) {
            $isbnVariants = IsbnFormatter::getAllVariants($data['isbn']);
            $variants = array_merge($variants, $isbnVariants);
        }

        // Populate missing ISBN fields
        if (!empty($variants['isbn13']) && empty($data['isbn13'])) {
            $data['isbn13'] = $variants['isbn13'];
            SecureLogger::debug('[ScrapeController] Auto-populated isbn13', ['isbn13' => $variants['isbn13']]);
        }
        if (!empty($variants['isbn10']) && empty($data['isbn10'])) {
            $data['isbn10'] = $variants['isbn10'];
            SecureLogger::debug('[ScrapeController] Auto-populated isbn10', ['isbn10' => $variants['isbn10']]);
        }

        // Also ensure generic 'isbn' field is set (prefer ISBN-13)
        if (empty($data['isbn'])) {
            if (!empty($data['isbn13'])) {
                $data['isbn'] = $data['isbn13'];
            } elseif (!empty($data['isbn10'])) {
                $data['isbn'] = $data['isbn10'];
            }
        }

        return $data;
    }
}

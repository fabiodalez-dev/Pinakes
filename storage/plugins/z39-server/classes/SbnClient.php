<?php
declare(strict_types=1);

namespace Plugins\Z39Server\Classes;

/**
 * SBN (Servizio Bibliotecario Nazionale) JSON API Client
 *
 * Fetches book metadata from the Italian national library catalog (OPAC SBN)
 * using the undocumented mobile JSON API.
 *
 * @package Plugins\Z39Server\Classes
 * @see https://opac.sbn.it/
 */
class SbnClient
{
    private const BASE_URL = 'https://opac.sbn.it/opacmobilegw';
    private const SEARCH_ENDPOINT = '/search.json';
    private const FULL_ENDPOINT = '/full.json';

    private int $timeout;
    private bool $enabled;

    /**
     * Constructor
     *
     * @param int $timeout Request timeout in seconds
     * @param bool $enabled Whether the client is enabled
     */
    public function __construct(int $timeout = 15, bool $enabled = true)
    {
        $this->timeout = $timeout;
        $this->enabled = $enabled;
    }

    /**
     * Search for a book by ISBN
     *
     * @param string $isbn ISBN-10 or ISBN-13
     * @return array|null Book data or null if not found
     */
    public function searchByIsbn(string $isbn): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        // Normalize ISBN (remove hyphens)
        $isbn = preg_replace('/[^0-9X]/i', '', $isbn);

        if (empty($isbn)) {
            return null;
        }

        $url = self::BASE_URL . self::SEARCH_ENDPOINT . '?isbn=' . urlencode($isbn) . '&rows=1';
        \App\Support\SecureLogger::debug('[SBN] Searching', ['isbn' => $isbn, 'url' => $url]);

        $searchResult = $this->makeRequest($url);

        if ($searchResult === null) {
            \App\Support\SecureLogger::warning('[SBN] Search returned null', ['isbn' => $isbn]);
            return null;
        }

        if (!isset($searchResult['briefRecords']) || empty($searchResult['briefRecords'])) {
            \App\Support\SecureLogger::warning('[SBN] No records found', ['isbn' => $isbn, 'numFound' => $searchResult['numFound'] ?? 0]);
            return null;
        }

        $record = $searchResult['briefRecords'][0];
        \App\Support\SecureLogger::debug('[SBN] Found record', ['isbn' => $isbn, 'title' => $record['titolo'] ?? 'N/A']);

        // Get full record for complete metadata
        $bid = $record['codiceIdentificativo'] ?? null;
        if ($bid) {
            \App\Support\SecureLogger::debug('[SBN] Fetching full record', ['bid' => $bid]);
            $fullRecord = $this->getFullRecord($bid);
            if ($fullRecord) {
                \App\Support\SecureLogger::debug('[SBN] Full record OK', ['isbn' => $isbn]);
                $result = $this->parseFullRecord($fullRecord);
                return $result ? $this->sanitizeForJson($result) : null;
            } else {
                \App\Support\SecureLogger::warning('[SBN] Full record failed, using brief', ['bid' => $bid]);
            }
        }

        // Fallback to brief record
        $result = $this->parseBriefRecord($record);
        return $result ? $this->sanitizeForJson($result) : null;
    }

    /**
     * Search for books by title (uses 'any' field for broader results)
     *
     * @param string $title Book title
     * @param int $maxResults Maximum results to return
     * @return array Array of book data
     */
    public function searchByTitle(string $title, int $maxResults = 10): array
    {
        if (!$this->enabled) {
            return [];
        }

        // SBN requires 'any' for general searches - 'titolo' alone returns validation error
        $url = self::BASE_URL . self::SEARCH_ENDPOINT . '?any=' . urlencode($title) . '&rows=' . $maxResults;

        $searchResult = $this->makeRequest($url);

        if ($searchResult === null || !isset($searchResult['briefRecords'])) {
            return [];
        }

        $results = [];
        foreach ($searchResult['briefRecords'] as $record) {
            $parsed = $this->parseBriefRecord($record);
            if ($parsed) {
                $results[] = $this->sanitizeForJson($parsed);
            }
        }

        return $results;
    }

    /**
     * Search for books by author
     *
     * @param string $author Author name
     * @param int $maxResults Maximum results to return
     * @return array Array of book data
     */
    public function searchByAuthor(string $author, int $maxResults = 10): array
    {
        if (!$this->enabled) {
            return [];
        }

        $url = self::BASE_URL . self::SEARCH_ENDPOINT . '?autore=' . urlencode($author) . '&rows=' . $maxResults;

        $searchResult = $this->makeRequest($url);

        if ($searchResult === null || !isset($searchResult['briefRecords'])) {
            return [];
        }

        $results = [];
        foreach ($searchResult['briefRecords'] as $record) {
            $parsed = $this->parseBriefRecord($record);
            if ($parsed) {
                $results[] = $this->sanitizeForJson($parsed);
            }
        }

        return $results;
    }

    /**
     * Get full record details by BID (Bibliographic ID)
     *
     * @param string $bid SBN Bibliographic ID (e.g., "IT\ICCU\RMB\0769708")
     * @return array|null Full record data or null
     */
    public function getFullRecord(string $bid): ?array
    {
        $url = self::BASE_URL . self::FULL_ENDPOINT . '?bid=' . urlencode($bid);
        return $this->makeRequest($url);
    }

    /**
     * Get multiple full records in parallel using curl_multi
     *
     * This eliminates the N+1 query problem by fetching all full records
     * concurrently instead of sequentially.
     *
     * Performance: With 20 records and 1s latency each:
     * - Sequential: ~20s total
     * - Parallel: ~2-3s total (limited by slowest response)
     *
     * @param array $bids Array of SBN Bibliographic IDs
     * @return array Associative array [bid => fullRecord|null]
     */
    public function getFullRecordsParallel(array $bids): array
    {
        if (empty($bids)) {
            return [];
        }

        // Remove duplicates and empty values
        $bids = array_filter(array_unique($bids));

        if (empty($bids)) {
            return [];
        }

        $multiHandle = curl_multi_init();
        $handles = [];
        $results = [];

        // Initialize all curl handles
        foreach ($bids as $bid) {
            $url = self::BASE_URL . self::FULL_ENDPOINT . '?bid=' . urlencode($bid);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_USERAGENT => 'Pinakes Library System/1.0',
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Accept-Language: it-IT,it;q=0.9'
                ]
            ]);

            curl_multi_add_handle($multiHandle, $ch);
            $handles[$bid] = $ch;
        }

        // Execute all requests in parallel
        $running = null;
        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($status > CURLM_OK) {
                error_log("[SBN Client] curl_multi_exec error: " . curl_multi_strerror($status));
                break;
            }
            // Wait for activity (avoids busy-waiting)
            if ($running > 0) {
                curl_multi_select($multiHandle, 0.1);
            }
        } while ($running > 0);

        // Collect results
        foreach ($handles as $bid => $ch) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $response = curl_multi_getcontent($ch);

            if ($error || $httpCode !== 200 || empty($response)) {
                error_log("[SBN Client] Parallel request failed for BID=$bid: HTTP=$httpCode, Error=$error");
                $results[$bid] = null;
            } else {
                $data = json_decode($response, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $results[$bid] = $data;
                } else {
                    error_log("[SBN Client] JSON parse error for BID=$bid: " . json_last_error_msg());
                    $results[$bid] = null;
                }
            }

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);

        return $results;
    }

    /**
     * Enrich books with full record data using parallel fetching
     *
     * @param array $books Array of parsed book records with _sbn_bid
     * @param bool $includeLocations Whether to include location data
     * @return array Enriched books array
     */
    public function enrichBooksParallel(array $books, bool $includeLocations = true): array
    {
        if (!$this->enabled || empty($books)) {
            return $books;
        }

        // Extract BIDs from books
        $bids = [];
        $bidToIndex = [];
        foreach ($books as $index => $book) {
            $bid = $book['_sbn_bid'] ?? null;
            if ($bid) {
                $bids[] = $bid;
                $bidToIndex[$bid] = $index;
            }
        }

        if (empty($bids)) {
            return $books;
        }

        // Fetch all full records in parallel
        $fullRecords = $this->getFullRecordsParallel($bids);

        // Merge full record data into books
        foreach ($fullRecords as $bid => $fullRecord) {
            if ($fullRecord === null) {
                continue;
            }

            $index = $bidToIndex[$bid] ?? null;
            if ($index === null) {
                continue;
            }

            // Add locations if requested and available
            if ($includeLocations && isset($fullRecord['localizzazioni'])) {
                $books[$index]['locations'] = $fullRecord['localizzazioni'];
            }

            // Enrich with additional full record data not in brief record
            if (empty($books[$index]['pages']) && !empty($fullRecord['descrizioneFisica'])) {
                $pages = $this->extractPages($fullRecord['descrizioneFisica']);
                if ($pages > 0) {
                    $books[$index]['pages'] = $pages;
                    $books[$index]['numero_pagine'] = $pages;
                }
            }

            if (empty($books[$index]['series']) && !empty($fullRecord['collezione'])) {
                $books[$index]['series'] = $fullRecord['collezione'];
                $books[$index]['collana'] = $fullRecord['collezione'];
            }

            if (empty($books[$index]['language']) && !empty($fullRecord['linguaPubblicazione'])) {
                $lang = strtolower($fullRecord['linguaPubblicazione']);
                $books[$index]['language'] = $lang;
                $books[$index]['lingua'] = $this->mapLanguageToCode($lang);
            }

            // Dewey classification
            // Note: Use explicit check instead of empty() to allow valid codes like '000'
            $hasDewey = isset($books[$index]['classificazione_dewey']) && $books[$index]['classificazione_dewey'] !== '';
            if (!$hasDewey && !empty($fullRecord['classificazioneDewey'])) {
                $deweyData = $this->extractDeweyData($fullRecord['classificazioneDewey']);
                if ($deweyData && $deweyData['code']) {
                    $books[$index]['classificazione_dewey'] = $deweyData['code'];
                    if (!empty($deweyData['name'])) {
                        $books[$index]['_dewey_name_sbn'] = $deweyData['name'];
                    }
                }
            }
        }

        return $books;
    }

    /**
     * Parse a full record into standardized book format
     *
     * @param array $record Full record from SBN API
     * @return array|null Parsed book data
     */
    private function parseFullRecord(array $record): ?array
    {
        $book = [];

        // Title (parse out author info from statement of responsibility)
        $fullTitle = $record['titolo'] ?? '';
        if (str_contains($fullTitle, '/')) {
            $parts = explode('/', $fullTitle, 2);
            $book['title'] = trim($parts[0]);
        } else {
            $book['title'] = trim($fullTitle);
        }
        // Strip MARC-8 control characters (NSB/NSE non-sorting markers)
        $book['title'] = $this->stripMarcControlChars($book['title']);

        if (empty($book['title'])) {
            return null;
        }

        // ISBN
        $isbn = $this->extractIsbn($record['numeri'] ?? []);
        if ($isbn) {
            if (strlen($isbn) === 13) {
                $book['isbn13'] = $isbn;
            } else {
                $book['isbn10'] = $isbn;
            }
        }

        // Authors
        $authors = $this->extractAuthors($record);
        if (!empty($authors)) {
            $book['authors'] = $authors;
            $book['author'] = implode(', ', $authors);
        }

        // Publisher and publication info
        $pubInfo = $this->parsePublicationInfo($record['pubblicazione'] ?? '');
        if ($pubInfo) {
            if (!empty($pubInfo['publisher'])) {
                $book['publisher'] = $pubInfo['publisher'];
            }
            if (!empty($pubInfo['year'])) {
                $book['year'] = $pubInfo['year'];
                $book['anno_pubblicazione'] = $pubInfo['year'];
            }
            if (!empty($pubInfo['place'])) {
                $book['place'] = $pubInfo['place'];
            }
        }

        // Physical description (pages)
        $pages = $this->extractPages($record['descrizioneFisica'] ?? '');
        if ($pages > 0) {
            $book['pages'] = $pages;
            $book['numero_pagine'] = $pages;
        }

        // Collection/Series
        if (!empty($record['collezione'])) {
            $series = $this->stripMarcControlChars($record['collezione']);
            $book['series'] = $series;
            $book['collana'] = $series;
        }

        // Language
        if (!empty($record['linguaPubblicazione'])) {
            $lang = strtolower($record['linguaPubblicazione']);
            $book['language'] = $lang;
            $book['lingua'] = $this->mapLanguageToCode($lang);
        }

        // Cover image (from LibraryThing)
        if (!empty($record['copertina'])) {
            // Request larger image (change small to medium)
            $coverUrl = str_replace('/small/', '/medium/', $record['copertina']);
            $book['image'] = $coverUrl;
            $book['copertina_url'] = $coverUrl;
        }

        // Dewey Classification
        $deweyData = $this->extractDeweyData($record['classificazioneDewey'] ?? '');
        if ($deweyData && $deweyData['code']) {
            $book['classificazione_dewey'] = $deweyData['code'];
            // Pass raw name for auto-population (prefixed with _ to indicate internal use)
            if (!empty($deweyData['name'])) {
                $book['_dewey_name_sbn'] = $deweyData['name'];
            }
        }

        // Source identification
        $book['_source'] = 'sbn';
        $book['_sbn_bid'] = $record['codiceIdentificativo'] ?? '';

        // Map BID to numero_inventario for form auto-fill
        if (!empty($book['_sbn_bid'])) {
            $book['numero_inventario'] = 'SBN-' . $book['_sbn_bid'];
        }

        return $book;
    }

    /**
     * Parse a brief record (from search results)
     *
     * @param array $record Brief record from search
     * @return array|null Parsed book data
     */
    private function parseBriefRecord(array $record): ?array
    {
        $book = [];

        // Title
        $fullTitle = $record['titolo'] ?? '';
        if (str_contains($fullTitle, '/')) {
            $parts = explode('/', $fullTitle, 2);
            $book['title'] = trim($parts[0]);
        } else {
            $book['title'] = trim($fullTitle);
        }
        // Strip MARC-8 control characters (NSB/NSE non-sorting markers)
        $book['title'] = $this->stripMarcControlChars($book['title']);

        if (empty($book['title'])) {
            return null;
        }

        // ISBN (from brief record)
        $isbn = $record['isbn'] ?? '';
        $isbn = preg_replace('/[^0-9X]/i', '', $isbn);
        if (!empty($isbn)) {
            if (strlen($isbn) === 13) {
                $book['isbn13'] = $isbn;
            } else {
                $book['isbn10'] = $isbn;
            }
        }

        // Main author
        if (!empty($record['autorePrincipale'])) {
            $author = $this->cleanAuthorName($record['autorePrincipale']);
            $book['authors'] = [$author];
            $book['author'] = $author;
        }

        // Publisher info
        $pubInfo = $this->parsePublicationInfo($record['pubblicazione'] ?? '');
        if ($pubInfo) {
            if (!empty($pubInfo['publisher'])) {
                $book['publisher'] = $pubInfo['publisher'];
            }
            if (!empty($pubInfo['year'])) {
                $book['year'] = $pubInfo['year'];
            }
        }

        // Cover
        if (!empty($record['copertina'])) {
            $book['image'] = str_replace('/small/', '/medium/', $record['copertina']);
        }

        // Source
        $book['_source'] = 'sbn';
        $book['_sbn_bid'] = $record['codiceIdentificativo'] ?? '';

        // Map BID to numero_inventario for form auto-fill
        if (!empty($book['_sbn_bid'])) {
            $book['numero_inventario'] = 'SBN-' . $book['_sbn_bid'];
        }

        return $book;
    }

    /**
     * Extract ISBN from numeri array
     *
     * @param array $numeri Numbers array from SBN
     * @return string|null ISBN or null
     */
    private function extractIsbn(array $numeri): ?string
    {
        foreach ($numeri as $num) {
            // Defensive cast to string for non-string values from API
            $numStr = (string)$num;
            if (str_contains(strtoupper($numStr), '[ISBN]')) {
                // Extract ISBN from format like "[ISBN]  978-88-420-5894-6"
                $isbn = preg_replace('/[^0-9X]/i', '', $numStr);
                if (!empty($isbn)) {
                    return $isbn;
                }
            }
        }
        return null;
    }

    /**
     * Extract authors from record
     *
     * @param array $record Full record
     * @return array List of author names
     */
    private function extractAuthors(array $record): array
    {
        $authors = [];

        // Main author
        if (!empty($record['autorePrincipale'])) {
            $authors[] = $this->cleanAuthorName($record['autorePrincipale']);
        }

        // Additional names
        if (!empty($record['nomi']) && is_array($record['nomi'])) {
            foreach ($record['nomi'] as $nome) {
                // Defensive cast to string for non-string values from API
                $nomeStr = (string)$nome;
                // Skip entries like "[Autore]  Marx, Karl" if already have main author
                if (str_contains($nomeStr, '[Autore]')) {
                    $cleanName = trim(preg_replace('/^\[Autore\]\s*/', '', $nomeStr));
                    $cleanName = $this->cleanAuthorName($cleanName);
                    if (!in_array($cleanName, $authors, true) && !empty($cleanName)) {
                        $authors[] = $cleanName;
                    }
                }
            }
        }

        return $authors;
    }

    /**
     * Clean and normalize author name
     *
     * Removes date ranges and normalizes format from "Surname, Name" to "Name Surname"
     * to ensure consistency with other sources (Google Books, Open Library)
     *
     * @param string $name Raw author name (typically "Surname, Name" from SBN)
     * @return string Normalized name in "Name Surname" format
     */
    private function cleanAuthorName(string $name): string
    {
        // Remove date ranges like <1818-1883>
        $name = preg_replace('/<[^>]+>/', '', $name);
        $name = trim($name);

        // Normalize name format: "Surname, Name" → "Name Surname"
        // This ensures consistency with other scraping sources (Google Books, Open Library)
        if (str_contains($name, ',')) {
            $parts = explode(',', $name, 2);
            if (count($parts) === 2) {
                $surname = trim($parts[0]);
                $firstName = trim($parts[1]);
                if ($surname !== '' && $firstName !== '') {
                    $name = $firstName . ' ' . $surname;
                }
            }
        }

        return $name;
    }

    /**
     * Parse publication information
     *
     * @param string $pubInfo Publication string like "Roma ; Bari : Laterza, 2009"
     * @return array|null Parsed info with keys: place, publisher, year
     */
    private function parsePublicationInfo(string $pubInfo): ?array
    {
        if (empty($pubInfo)) {
            return null;
        }

        $result = [];

        // Extract year (4 digits, typically at the end) - supports 1000-2099
        if (preg_match('/\b(1[0-9]{3}|20[0-9]{2})\b/', $pubInfo, $yearMatch)) {
            $result['year'] = (int)$yearMatch[1];
        }

        // Parse format: "Place : Publisher, Year"
        if (str_contains($pubInfo, ':')) {
            $parts = explode(':', $pubInfo, 2);
            $place = trim($parts[0]);

            // Handle multiple places like "Roma ; Bari"
            $place = str_replace(';', ',', $place);
            $result['place'] = trim(explode(',', $place)[0]);

            // Publisher is between : and ,year or end
            $afterColon = trim($parts[1]);
            if (preg_match('/^([^,]+)/', $afterColon, $pubMatch)) {
                $result['publisher'] = trim($pubMatch[1]);
            }
        }

        return empty($result) ? null : $result;
    }

    /**
     * Extract Dewey classification code and name from SBN format
     *
     * @param string $deweyStr Format: "808.81 (12.) RACCOLTE DI PIU LETTERATURE. POESIA"
     * @return array{code: string, name: string|null}|null Dewey data or null
     */
    private function extractDeweyData(string $deweyStr): ?array
    {
        $deweyStr = trim($deweyStr);

        if ($deweyStr === '') {
            return null;
        }

        // Extract code, optional edition, and name
        // Format: "808.81 (12.) RACCOLTE DI PIU LETTERATURE. POESIA"
        // Or: "808.81 RACCOLTE DI PIU LETTERATURE. POESIA"
        if (preg_match('/^(\d{3}(?:\.\d+)?)\s*(?:\([^)]+\)\s*)?(.+)?$/u', $deweyStr, $match)) {
            $code = $match[1];
            $name = isset($match[2]) ? trim($match[2]) : null;

            // Clean up the name - remove trailing dots, normalize case
            if ($name) {
                $name = rtrim($name, '. ');
                // Convert from ALL CAPS to Title Case if needed
                if ($name === mb_strtoupper($name, 'UTF-8')) {
                    $name = mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
                }
            }

            return [
                'code' => $code,
                'name' => $name
            ];
        }

        return null;
    }

    /**
     * Extract page count from physical description
     *
     * @param string $desc Description like "LXXIII, 62 p. ; 21 cm."
     * @return int Page count or 0
     */
    private function extractPages(string $desc): int
    {
        // Match patterns like "62 p." or "123 pages"
        if (preg_match('/(\d+)\s*p\.?/i', $desc, $match)) {
            return (int)$match[1];
        }

        // Match Roman numerals + pages
        if (preg_match('/[IVXLCDM]+,?\s*(\d+)\s*p/i', $desc, $match)) {
            return (int)$match[1];
        }

        return 0;
    }

    /**
     * Map language name to ISO code
     *
     * @param string $lang Language name
     * @return string ISO 639-1 code
     */
    private function mapLanguageToCode(string $lang): string
    {
        $map = [
            'italiano' => 'it',
            'italian' => 'it',
            'inglese' => 'en',
            'english' => 'en',
            'francese' => 'fr',
            'french' => 'fr',
            'tedesco' => 'de',
            'german' => 'de',
            'spagnolo' => 'es',
            'spanish' => 'es',
            'portoghese' => 'pt',
            'portuguese' => 'pt',
            'latino' => 'la',
            'latin' => 'la',
        ];

        return $map[$lang] ?? $lang;
    }

    /**
     * Strip MARC-8 control characters from text
     *
     * @param string $text Text that may contain MARC control characters
     * @return string Cleaned text
     */
    private function stripMarcControlChars(string $text): string
    {
        // MARC-8 control characters:
        // \x88 = NSB (Non-Sorting Begin) - marks start of non-filing chars like "Il", "La", "The"
        // \x89 = NSE (Non-Sorting End) - marks end of non-filing characters
        // \x98 = Joiner
        // \x9C = Superscript markers
        return preg_replace('/[\x88\x89\x98\x9C]/', '', $text);
    }

    /**
     * Recursively sanitize all strings in an array to ensure valid UTF-8 for JSON encoding
     * Removes MARC control chars and all other non-printable characters
     *
     * @param array $data Data to sanitize
     * @return array Sanitized data safe for json_encode
     */
    private function sanitizeForJson(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sanitizeForJson($value);
            } elseif (is_string($value)) {
                // Strip ALL C1 control characters (0x80-0x9F) which includes MARC-8 controls
                $value = preg_replace('/[\x80-\x9F]/', '', $value);
                // Remove C0 control characters except newline/tab/carriage return
                $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
                // Remove invalid UTF-8 sequences (iconv with //IGNORE strips them)
                $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
                $data[$key] = $converted !== false ? $converted : $value;
            }
        }
        return $data;
    }

    /**
     * Make HTTP request to SBN API
     *
     * @param string $url Full URL
     * @return array|null Decoded JSON or null on error
     */
    private function makeRequest(string $url): ?array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'Pinakes Library System/1.0 (+https://github.com/biblioteche)',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Accept-Language: it-IT,it;q=0.9'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);

        curl_close($ch);

        \App\Support\SecureLogger::debug('[SBN] HTTP Request', [
            'http_code' => $httpCode,
            'time' => round($totalTime, 3),
            'size' => strlen($response ?: '')
        ]);

        if ($error) {
            \App\Support\SecureLogger::error('[SBN] CURL error', ['error' => $error, 'url' => $url]);
            return null;
        }

        if ($httpCode !== 200) {
            \App\Support\SecureLogger::error('[SBN] HTTP error', ['http_code' => $httpCode, 'response' => substr($response ?: '', 0, 200)]);
            return null;
        }

        if (empty($response)) {
            \App\Support\SecureLogger::error('[SBN] Empty response', ['url' => $url]);
            return null;
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            \App\Support\SecureLogger::error('[SBN] JSON parse error', ['error' => json_last_error_msg()]);
            return null;
        }

        return $data;
    }
}

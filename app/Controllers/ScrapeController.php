<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Support\DeweyAutoPopulator;
use App\Support\SecureLogger;

class ScrapeController
{
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

            // Hook: scrape.response - Modify final JSON response
            $payload = \App\Support\Hooks::apply('scrape.response', $payload, [$cleanIsbn, $sources, ['timestamp' => time()]]);

            // Auto-populate Dewey JSON if classification found (language-aware)
            DeweyAutoPopulator::processBookData($payload);

            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
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

            // Ensure plugins can still modify/log the final payload just like regular results
            $fallbackData = \App\Support\Hooks::apply('scrape.response', $fallbackData, [$cleanIsbn, $sources, ['timestamp' => time()]]);

            // Auto-populate Dewey JSON if classification found (language-aware)
            DeweyAutoPopulator::processBookData($fallbackData);

            $json = json_encode($fallbackData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $response->getBody()->write(json_encode([
                    'error' => __('Impossibile generare la risposta JSON.'),
                ], JSON_UNESCAPED_UNICODE));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write($json);
            return $response->withHeader('Content-Type', 'application/json');
        }

        $sourceNames = array_map(fn($s) => $s['name'] ?? 'Unknown', $sources);
        $response->getBody()->write(json_encode([
            'error' => sprintf(
                __('ISBN non trovato. Fonti consultate: %s'),
                implode(', ', $sourceNames)
            ),
            'isbn' => $cleanIsbn,
            'sources_checked' => array_keys($sources),
        ], JSON_UNESCAPED_UNICODE));

        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    /**
     * Get default scraping sources
     *
     * @return array Array of scraping sources
     */
    private function getDefaultSources(): array
    {
        // Le fonti vengono dichiarate dinamicamente dai plugin attivi.
        return [];
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
        if (!$this->checkRateLimit('google_books', 10)) {
            error_log("[ScrapeController] Google Books API rate limit exceeded for ISBN: $isbn");
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
            // Convert to Italian format if full date (YYYY-MM-DD)
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $pubDate)) {
                $date = \DateTime::createFromFormat('Y-m-d', $pubDate);
                if ($date) {
                    $pubDate = $date->format('d/m/Y');
                }
            }
        }

        // Extract language
        $language = $info['language'] ?? '';
        if ($language) {
            $languageNames = [
                'it' => 'Italiano',
                'en' => 'English',
                'fr' => 'Français',
                'de' => 'Deutsch',
                'es' => 'Español',
                'pt' => 'Português',
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
        if (!$this->checkRateLimit('openlibrary', 10)) {
            error_log("[ScrapeController] Open Library API rate limit exceeded for ISBN: $isbn");
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
            'description' => is_array($data['description']) ? ($data['description']['value'] ?? '') : ($data['description'] ?? ''),
            'image' => $cover
        ];
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
            CURLOPT_FOLLOWLOCATION => false,
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
     * @param int $maxCallsPerMinute Max chiamate al minuto (default 10)
     * @return bool True se OK, False se rate limit superato
     */
    private function checkRateLimit(string $apiName, int $maxCallsPerMinute = 10): bool
    {
        $storageDir = __DIR__ . '/../../storage/rate_limits';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $rateLimitFile = $storageDir . '/' . $apiName . '.json';
        $now = time();

        // Load existing rate limit data
        $data = ['calls' => [], 'last_cleanup' => $now];
        if (file_exists($rateLimitFile)) {
            $json = file_get_contents($rateLimitFile);
            if ($json !== false) {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }

        // Remove calls older than 60 seconds
        $data['calls'] = array_filter($data['calls'], fn($timestamp) => ($now - $timestamp) < 60);

        // Check if rate limit exceeded
        if (count($data['calls']) >= $maxCallsPerMinute) {
            error_log("[ScrapeController] Rate limit exceeded for $apiName: " . count($data['calls']) . " calls in last minute");
            return false;
        }

        // Record this call
        $data['calls'][] = $now;
        $data['last_cleanup'] = $now;

        // Save with lock
        file_put_contents($rateLimitFile, json_encode($data), LOCK_EX);

        return true;
    }
}

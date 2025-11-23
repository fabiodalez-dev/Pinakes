<?php
declare(strict_types=1);

namespace App\Support;

class ScrapingService
{
    public function byIsbn(string $isbn): ?array
    {
        if ($isbn === '') {
            return ['error' => __('Parametro ISBN mancante.')];
        }
        $cleanIsbn = preg_replace('/[^0-9X]/', '', strtoupper($isbn));

        $isValid = $this->isValidIsbn($cleanIsbn);

        $isValid = \App\Support\Hooks::apply('scrape.isbn.validate', $isValid, [$cleanIsbn, 'user_input']);

        if (!$isValid) {
            return ['error' => __('Formato ISBN non valido.')];
        }

        $sources = $this->getDefaultSources();

        $sources = \App\Support\Hooks::apply('scrape.sources', $sources, [$cleanIsbn]);

        if (empty($sources)) {
            error_log("[ScrapingService] No scraping sources available");
            return ['error' => __('Nessuna fonte di scraping disponibile. Installa almeno un plugin di scraping (es. Open Library o Scraping Pro).')];
        }

        error_log("[ScrapingService] Available sources: " . implode(', ', array_keys($sources)));

        $customResult = \App\Support\Hooks::apply('scrape.fetch.custom', null, [$sources, $cleanIsbn]);

        if ($customResult !== null) {
            error_log("[ScrapingService] ISBN $cleanIsbn found via plugins");
            $payload = $customResult;
            $payload = \App\Support\Hooks::apply('scrape.response', $payload, [$cleanIsbn, $sources, ['timestamp' => time()]]);
            return $payload;
        }

        error_log("[ScrapingService] ISBN $cleanIsbn not found in any source, trying built-in fallbacks");

        $fallbackData = $this->fallbackFromGoogleBooks($cleanIsbn);
        if ($fallbackData === null) {
            $fallbackData = $this->fallbackFromOpenLibrary($cleanIsbn);
        }

        if ($fallbackData !== null) {
            $fallbackData = \App\Support\Hooks::apply('scrape.response', $fallbackData, [$cleanIsbn, $sources, ['timestamp' => time()]]);
            return $fallbackData;
        }

        $sourceNames = array_map(fn($s) => $s['name'] ?? 'Unknown', $sources);
        return [
            'error' => sprintf(
                __('ISBN non trovato. Fonti consultate: %s'),
                implode(', ', $sourceNames)
            ),
            'isbn' => $cleanIsbn,
            'sources_checked' => array_keys($sources),
        ];
    }

    private function getDefaultSources(): array
    {
        return [];
    }

    private function isValidIsbn(string $isbn): bool
    {
        $isbn = preg_replace('/[^0-9X]/', '', strtoupper($isbn));

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

    private function fallbackFromGoogleBooks(string $isbn): ?array
    {
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

        $item = $payload['items'][0];
        $info = $item['volumeInfo'];
        $authors = isset($info['authors']) && is_array($info['authors']) ? $info['authors'] : [];

        $image = null;
        if (!empty($info['imageLinks'])) {
            $imageLinks = $info['imageLinks'];
            $image = $imageLinks['extraLarge'] ?? $imageLinks['large'] ?? $imageLinks['medium'] ??
                     $imageLinks['small'] ?? $imageLinks['thumbnail'] ?? $imageLinks['smallThumbnail'] ?? null;
        }

        $isbn13 = '';
        $isbn10 = '';
        $isbnField = $isbn;
        if (!empty($info['industryIdentifiers']) && is_array($info['industryIdentifiers'])) {
            foreach ($info['industryIdentifiers'] as $id) {
                if (($id['type'] ?? '') === 'ISBN_13' && !empty($id['identifier'])) {
                    $isbn13 = $id['identifier'];
                    $isbnField = $isbn13;
                } elseif (($id['type'] ?? '') === 'ISBN_10' && !empty($id['identifier'])) {
                    $isbn10 = $id['identifier'];
                    if (!$isbn13) {
                        $isbnField = $isbn10;
                    }
                }
            }
        }

        $keywords = '';
        if (!empty($info['categories']) && is_array($info['categories'])) {
            $keywords = implode(', ', $info['categories']);
        }

        $price = null;
        if (!empty($item['saleInfo']['retailPrice'])) {
            $priceData = $item['saleInfo']['retailPrice'];
            $amount = $priceData['amount'] ?? null;
            $currency = $priceData['currencyCode'] ?? 'EUR';
            if ($amount !== null) {
                $price = number_format((float)$amount, 2, '.', '') . ' ' . $currency;
            }
        } elseif (!empty($item['saleInfo']['listPrice'])) {
            $priceData = $item['saleInfo']['listPrice'];
            $amount = $priceData['amount'] ?? null;
            $currency = $priceData['currencyCode'] ?? 'EUR';
            if ($amount !== null) {
                $price = number_format((float)$amount, 2, '.', '') . ' ' . $currency;
            }
        }

        $pubDate = $info['publishedDate'] ?? '';
        $year = '';
        if ($pubDate) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T/', $pubDate, $matches)) {
                $pubDate = "{$matches[1]}-{$matches[2]}-{$matches[3]}";
            }

            $year = substr($pubDate, 0, 4);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $pubDate)) {
                $date = \DateTime::createFromFormat('Y-m-d', $pubDate);
                if ($date) {
                    $pubDate = $date->format('d/m/Y');
                }
            }
        }

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

    private function fallbackFromOpenLibrary(string $isbn): ?array
    {
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
}

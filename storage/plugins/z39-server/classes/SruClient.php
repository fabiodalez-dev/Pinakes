<?php
declare(strict_types=1);

namespace Plugins\Z39Server\Classes;

use DOMDocument;
use DOMXPath;

/**
 * SRU Client Implementation
 *
 * Handles connections to external Z39.50/SRU servers for:
 * - Copy Cataloging (importing book metadata)
 * - Federated Search (searching across multiple libraries)
 */
class SruClient
{
    private array $servers = [];
    private int $timeout = 10;
    private int $maxRetries = 2;
    private bool $verifySsl = true;

    public function __construct(array $servers = [])
    {
        $this->servers = $servers;
    }

    /**
     * Configure client options
     */
    public function setOptions(array $options): self
    {
        if (isset($options['timeout'])) {
            $this->timeout = max(1, min(30, (int)$options['timeout']));
        }
        if (isset($options['max_retries'])) {
            $this->maxRetries = max(0, min(5, (int)$options['max_retries']));
        }
        if (isset($options['verify_ssl'])) {
            $this->verifySsl = (bool)$options['verify_ssl'];
        }
        return $this;
    }

    /**
     * Search for a book by ISBN across all configured servers
     *
     * @param string $isbn
     * @return array|null Book data or null if not found
     */
    public function searchByIsbn(string $isbn): ?array
    {
        // Normalize ISBN (remove dashes/spaces)
        $isbn = preg_replace('/[^0-9X]/i', '', $isbn);

        foreach ($this->servers as $server) {
            if (empty($server['enabled']) || empty($server['url'])) {
                continue;
            }

            try {
                $result = $this->queryServerWithRetry($server, 'isbn', $isbn);
                if ($result) {
                    return $result;
                }
            } catch (\Throwable $e) {
                error_log("[SruClient] Error querying server {$server['name']}: " . $e->getMessage());
                continue;
            }
        }

        return null;
    }

    /**
     * Query server with retry logic
     */
    private function queryServerWithRetry(array $server, string $index, string $term): ?array
    {
        $lastException = null;
        $attempts = 0;

        while ($attempts <= $this->maxRetries) {
            try {
                return $this->queryServer($server, $index, $term);
            } catch (\Exception $e) {
                $lastException = $e;
                $attempts++;

                // Don't retry on 4xx errors (client errors)
                if (str_contains($e->getMessage(), 'HTTP 4')) {
                    break;
                }

                // Exponential backoff: 100ms, 200ms, 400ms...
                if ($attempts <= $this->maxRetries) {
                    usleep(100000 * (int)pow(2, $attempts - 1));
                }
            }
        }

        if ($lastException) {
            throw $lastException;
        }

        return null;
    }

    /**
     * Execute a query against a specific server
     */
    private function queryServer(array $server, string $index, string $term): ?array
    {
        $url = $server['url'];

        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \Exception("Invalid server URL: $url");
        }

        $version = $server['version'] ?? '1.1';
        $recordSchema = strtolower($server['syntax'] ?? 'marcxml');

        // Build CQL query with custom index mapping
        $cqlIndex = $index;
        if (isset($server['indexes'][$index])) {
            $cqlIndex = $server['indexes'][$index];
        } elseif ($index === 'isbn') {
            $cqlIndex = 'isbn';
        }

        // Build query parameters
        $params = [
            'operation' => 'searchRetrieve',
            'version' => $version,
            'query' => $cqlIndex . '=' . $term,
            'recordSchema' => $recordSchema,
            'maximumRecords' => 1
        ];

        $finalUrl = $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);

        // Fetch content with proper error handling
        $response = $this->fetchUrl($finalUrl);

        if ($response === null) {
            return null;
        }

        // Parse XML
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if (!$dom->loadXML($response)) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            throw new \Exception("Invalid XML response from $url: " . ($errors[0]->message ?? 'Parse error'));
        }

        $xpath = new DOMXPath($dom);

        // Register namespaces
        $xpath->registerNamespace('sru', 'http://www.loc.gov/zing/srw/');
        $xpath->registerNamespace('marc', 'http://www.loc.gov/MARC21/slim');
        $xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $xpath->registerNamespace('oai_dc', 'http://www.openarchives.org/OAI/2.0/oai_dc/');

        // Check for records
        $numberOfRecords = $xpath->query('//sru:numberOfRecords');
        if ($numberOfRecords->length > 0 && (int) $numberOfRecords->item(0)->nodeValue === 0) {
            return null;
        }

        // Extract record data based on schema
        return match ($recordSchema) {
            'marcxml' => $this->parseMarcXml($xpath),
            'dc', 'oai_dc' => $this->parseDublinCore($xpath),
            default => $this->parseMarcXml($xpath),
        };
    }

    /**
     * Fetch URL with cURL for better error handling and TLS validation
     */
    private function fetchUrl(string $url): ?string
    {
        // Try cURL first (better SSL/TLS handling)
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_USERAGENT => 'Pinakes/1.0 (Z39.50/SRU Client)',
                CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
                CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false || !empty($error)) {
                throw new \Exception("Connection failed: $error");
            }

            if ($httpCode >= 400) {
                throw new \Exception("HTTP $httpCode error from server");
            }

            return $response ?: null;
        }

        // Fallback to file_get_contents
        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeout,
                'user_agent' => 'Pinakes/1.0 (Z39.50/SRU Client)',
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => $this->verifySsl,
                'verify_peer_name' => $this->verifySsl,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new \Exception("Failed to connect to server");
        }

        // Check HTTP status from headers
        if (isset($http_response_header[0])) {
            if (preg_match('/HTTP\/\d+\.\d+\s+(\d+)/', $http_response_header[0], $matches)) {
                $statusCode = (int)$matches[1];
                if ($statusCode >= 400) {
                    throw new \Exception("HTTP $statusCode error from server");
                }
            }
        }

        return $response;
    }

    /**
     * Parse MARCXML response
     */
    private function parseMarcXml(DOMXPath $xpath): ?array
    {
        // Find the first record
        $record = $xpath->query('//marc:record')->item(0);
        if (!$record) {
            $record = $xpath->query('//record')->item(0);
        }

        if (!$record) {
            return null;
        }

        $book = [
            'title' => '',
            'subtitle' => '',
            'authors' => [],
            'publisher' => '',
            'pubDate' => '',
            'year' => '',
            'isbn13' => '',
            'isbn10' => '',
            'language' => '',
            'pages' => '',
            'description' => '',
            'classificazione_dewey' => '',
            'source' => 'Z39.50/SRU'
        ];

        // Helper to get subfield
        $getSubfield = function ($tag, $code) use ($xpath, $record) {
            $nodes = $xpath->query(".//marc:datafield[@tag='$tag']/marc:subfield[@code='$code']", $record);
            if ($nodes->length === 0) {
                $nodes = $xpath->query(".//datafield[@tag='$tag']/subfield[@code='$code']", $record);
            }
            return $nodes->length > 0 ? trim($nodes->item(0)->nodeValue) : null;
        };

        // Title (245 $a $b)
        $book['title'] = $getSubfield('245', 'a') ?? '';
        $book['subtitle'] = $getSubfield('245', 'b') ?? '';

        // Clean title (remove trailing slash/punctuation)
        $book['title'] = trim(preg_replace('/[\/\s:;]+$/', '', $book['title']));
        $book['subtitle'] = trim(preg_replace('/[\/\s:;]+$/', '', $book['subtitle']));

        // Remove MARC-8 control characters using Unicode code points with /u flag
        // U+0088 = NSB (Non-Sorting Begin), U+0089 = NSE (Non-Sorting End)
        // U+0098 = Joiner, U+009C = Superscript markers
        // Remove all C1 control characters (U+0080-U+009F)
        $book['title'] = preg_replace('/[\x{0080}-\x{009F}]/u', '', $book['title']);
        $book['subtitle'] = preg_replace('/[\x{0080}-\x{009F}]/u', '', $book['subtitle']);
        $book['publisher'] = preg_replace('/[\x{0080}-\x{009F}]/u', '', $book['publisher']);
        $book['description'] = preg_replace('/[\x{0080}-\x{009F}]/u', '', $book['description']);
        // Normalize whitespace (collapse multiple spaces into one)
        $book['title'] = trim(preg_replace('/\s+/u', ' ', $book['title']));
        $book['subtitle'] = trim(preg_replace('/\s+/u', ' ', $book['subtitle']));
        $book['publisher'] = trim(preg_replace('/\s+/u', ' ', $book['publisher']));
        $book['description'] = trim(preg_replace('/\s+/u', ' ', $book['description']));

        // Author (100 $a) and additional authors (700 $a)
        $author = $getSubfield('100', 'a');
        if ($author) {
            $book['authors'][] = trim(preg_replace('/,$/', '', $author));
        }

        // Additional authors from 700 field
        $additionalAuthors = $xpath->query(".//marc:datafield[@tag='700']/marc:subfield[@code='a']", $record);
        if ($additionalAuthors->length === 0) {
            $additionalAuthors = $xpath->query(".//datafield[@tag='700']/subfield[@code='a']", $record);
        }
        foreach ($additionalAuthors as $addAuthor) {
            $book['authors'][] = trim(preg_replace('/,$/', '', $addAuthor->nodeValue));
        }

        // Publisher (260 $b or 264 $b)
        $publisher = $getSubfield('260', 'b') ?? $getSubfield('264', 'b');
        if ($publisher) {
            $book['publisher'] = trim(preg_replace('/[,;]+$/', '', $publisher));
        }

        // Year (260 $c or 264 $c)
        $year = $getSubfield('260', 'c') ?? $getSubfield('264', 'c');
        if ($year) {
            if (preg_match('/\d{4}/', $year, $matches)) {
                $book['year'] = $matches[0];
                $book['pubDate'] = $matches[0] . '-01-01';
            }
        }

        // ISBN (020 $a) - get all ISBNs
        $isbnNodes = $xpath->query(".//marc:datafield[@tag='020']/marc:subfield[@code='a']", $record);
        if ($isbnNodes->length === 0) {
            $isbnNodes = $xpath->query(".//datafield[@tag='020']/subfield[@code='a']", $record);
        }
        foreach ($isbnNodes as $isbnNode) {
            $isbn = preg_replace('/^([0-9X]+).*$/i', '$1', $isbnNode->nodeValue);
            if (strlen($isbn) === 13 && empty($book['isbn13'])) {
                $book['isbn13'] = $isbn;
            } elseif (strlen($isbn) === 10 && empty($book['isbn10'])) {
                $book['isbn10'] = $isbn;
            }
        }

        // Pages (300 $a)
        $pages = $getSubfield('300', 'a');
        if ($pages && preg_match('/(\d+)/', $pages, $matches)) {
            $book['pages'] = $matches[1];
        }

        // Language (041 $a)
        $lang = $getSubfield('041', 'a');
        if ($lang) {
            $book['language'] = strtolower(substr($lang, 0, 3));
        }

        // Description/Summary (520 $a)
        $description = $getSubfield('520', 'a');
        if ($description) {
            $book['description'] = $description;
        }

        // Dewey Classification (082 $a)
        // MARC field 082 contains Dewey Decimal Classification number
        $dewey = $getSubfield('082', 'a');
        if ($dewey) {
            // Clean Dewey code: extract just the numeric code (e.g., "823.912" from "823.912 20")
            // Note: No ^ anchor - some MARC 082 fields have content before the Dewey code
            $dewey = trim($dewey);
            if (preg_match('/(\d{3}(?:\.\d+)?)/', $dewey, $matches)) {
                $book['classificazione_dewey'] = $matches[1];
            }
        }

        // Add 'author' field as string for compatibility
        if (!empty($book['authors'])) {
            $book['author'] = implode(', ', $book['authors']);
        }

        return $book;
    }

    /**
     * Parse Dublin Core response
     */
    private function parseDublinCore(DOMXPath $xpath): ?array
    {
        // Find DC record (try different namespaces)
        $dcPaths = [
            '//oai_dc:dc',
            '//dc:dc',
            '//sru:recordData/dc:dc',
            '//sru:recordData/*[local-name()="dc"]',
        ];

        $record = null;
        foreach ($dcPaths as $path) {
            $nodes = $xpath->query($path);
            if ($nodes->length > 0) {
                $record = $nodes->item(0);
                break;
            }
        }

        if (!$record) {
            return null;
        }

        $book = [
            'title' => '',
            'subtitle' => '',
            'authors' => [],
            'publisher' => '',
            'pubDate' => '',
            'year' => '',
            'isbn13' => '',
            'isbn10' => '',
            'language' => '',
            'pages' => '',
            'description' => '',
            'classificazione_dewey' => '',
            'source' => 'Z39.50/SRU (DC)'
        ];

        // Helper to get DC element
        $getDcElement = function ($name) use ($xpath, $record) {
            $nodes = $xpath->query("dc:$name", $record);
            if ($nodes->length === 0) {
                $nodes = $xpath->query("*[local-name()='$name']", $record);
            }
            return $nodes->length > 0 ? trim($nodes->item(0)->nodeValue) : null;
        };

        $getAllDcElements = function ($name) use ($xpath, $record) {
            $results = [];
            $nodes = $xpath->query("dc:$name", $record);
            if ($nodes->length === 0) {
                $nodes = $xpath->query("*[local-name()='$name']", $record);
            }
            foreach ($nodes as $node) {
                $results[] = trim($node->nodeValue);
            }
            return $results;
        };

        // Title
        $book['title'] = $getDcElement('title') ?? '';
        // Remove MARC-8 control characters using Unicode code points with /u flag
        $book['title'] = preg_replace('/[\x{0080}-\x{009F}]/u', '', $book['title']);
        // Normalize whitespace (collapse multiple spaces into one)
        $book['title'] = trim(preg_replace('/\s+/u', ' ', $book['title']));

        // Creators/Authors
        $creators = $getAllDcElements('creator');
        // Normalize authors too
        $book['authors'] = array_map(function($author) {
            $author = preg_replace('/[\x{0080}-\x{009F}]/u', '', $author);
            return trim(preg_replace('/\s+/u', ' ', $author));
        }, $creators);

        // Publisher
        $book['publisher'] = $getDcElement('publisher') ?? '';
        $book['publisher'] = preg_replace('/[\x{0080}-\x{009F}]/u', '', $book['publisher']);
        $book['publisher'] = trim(preg_replace('/\s+/u', ' ', $book['publisher']));

        // Date
        $date = $getDcElement('date');
        if ($date && preg_match('/(\d{4})/', $date, $matches)) {
            $book['year'] = $matches[1];
            $book['pubDate'] = $matches[1] . '-01-01';
        }

        // Identifier (ISBN)
        $identifiers = $getAllDcElements('identifier');
        foreach ($identifiers as $identifier) {
            if (preg_match('/isbn[:\s]*([0-9X-]+)/i', $identifier, $matches)) {
                $isbn = preg_replace('/[^0-9X]/i', '', $matches[1]);
                if (strlen($isbn) === 13) {
                    $book['isbn13'] = $isbn;
                } elseif (strlen($isbn) === 10) {
                    $book['isbn10'] = $isbn;
                }
            } elseif (preg_match('/^[0-9X-]{10,17}$/i', $identifier)) {
                $isbn = preg_replace('/[^0-9X]/i', '', $identifier);
                if (strlen($isbn) === 13) {
                    $book['isbn13'] = $isbn;
                } elseif (strlen($isbn) === 10) {
                    $book['isbn10'] = $isbn;
                }
            }
        }

        // Language
        $book['language'] = $getDcElement('language') ?? '';

        // Description
        $book['description'] = $getDcElement('description') ?? '';
        $book['description'] = preg_replace('/[\x{0080}-\x{009F}]/u', '', $book['description']);
        $book['description'] = trim(preg_replace('/\s+/u', ' ', $book['description']));

        // Subject (as keywords)
        $subjects = $getAllDcElements('subject');
        if (!empty($subjects)) {
            $book['keywords'] = implode(', ', $subjects);

            // Check subjects for Dewey classification (some servers include it here)
            foreach ($subjects as $subject) {
                // Look for patterns like "DDC: 823.912" or pure Dewey codes like "823.912"
                if (preg_match('/(?:DDC|Dewey)[:\s]*(\d{3}(?:\.\d+)?)/i', $subject, $matches)
                    || preg_match('/^(\d{3}(?:\.\d+)?)$/', trim($subject), $matches)) {
                    $book['classificazione_dewey'] = $matches[1];
                    break;
                }
            }
        }

        // Check dc:coverage for Dewey (some servers use this field)
        $coverage = $getDcElement('coverage');
        if ($coverage && empty($book['classificazione_dewey'])) {
            if (preg_match('/(?:DDC|Dewey)[:\s]*(\d{3}(?:\.\d+)?)/i', $coverage, $matches)
                || preg_match('/^(\d{3}(?:\.\d+)?)$/', trim($coverage), $matches)) {
                $book['classificazione_dewey'] = $matches[1];
            }
        }

        // Add 'author' field as string for compatibility
        if (!empty($book['authors'])) {
            $book['author'] = implode(', ', $book['authors']);
        }

        return $book;
    }
}

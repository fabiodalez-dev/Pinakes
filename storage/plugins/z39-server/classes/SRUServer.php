<?php
/**
 * SRU Server Implementation
 *
 * Implements the SRU (Search/Retrieve via URL) protocol for library catalog access.
 * Supports SRU version 1.2 with CQL query language.
 *
 * @see https://www.loc.gov/standards/sru/
 */

declare(strict_types=1);

namespace Z39Server;

use mysqli;

class SRUServer
{
    private mysqli $db;
    private array $settings;
    private ?int $pluginId;

    // SRU namespaces
    private const NS_SRU = 'http://www.loc.gov/zing/srw/';
    private const NS_DIAG = 'http://www.loc.gov/zing/srw/diagnostic/';

    /**
     * Constructor
     *
     * @param mysqli $db Database connection
     * @param array $settings Plugin settings
     * @param int|null $pluginId Plugin ID for logging
     */
    public function __construct(mysqli $db, array $settings, ?int $pluginId = null)
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->pluginId = $pluginId;
    }

    /**
     * Handle SRU request
     *
     * @param array $params Request parameters
     * @return string XML response
     */
    public function handleRequest(array $params): string
    {
        $startTime = microtime(true);

        // Sanitize input parameters (OWASP: Input Validation)
        $operation = $this->sanitizeString($params['operation'] ?? '');
        $version = $this->sanitizeString($params['version'] ?? '1.2');

        // Log request
        $this->logAccess($operation, $params);

        try {
            // Validate operation
            if (empty($operation)) {
                return $this->errorResponse(7, 'Mandatory parameter not supplied: operation', $version);
            }

            // Route to appropriate handler
            switch ($operation) {
                case 'explain':
                    $response = $this->handleExplain($params);
                    break;

                case 'searchRetrieve':
                    $response = $this->handleSearchRetrieve($params);
                    break;

                case 'scan':
                    $response = $this->handleScan($params);
                    break;

                default:
                    $response = $this->errorResponse(4, "Unsupported operation: {$operation}", $version);
            }

            // Calculate response time
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            $this->updateAccessLog($responseTime, 200);

            return $response;
        } catch (\Exception $e) {
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            $this->updateAccessLog($responseTime, 500, $e->getMessage());

            return $this->errorResponse(1, 'General system error: ' . $e->getMessage(), $version);
        }
    }

    /**
     * Handle 'explain' operation
     * Returns server capabilities and configuration
     *
     * @param array $params Request parameters
     * @return string XML response
     */
    private function handleExplain(array $params): string
    {
        $version = $this->sanitizeString($params['version'] ?? '1.2');
        $recordPacking = $this->sanitizeString($params['recordPacking'] ?? 'xml');

        $host = $this->settings['server_host'] ?? 'localhost';
        $port = $this->settings['server_port'] ?? '80';
        $database = $this->settings['server_database'] ?? 'catalog';

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        // Root element
        $root = $xml->createElementNS(self::NS_SRU, 'explainResponse');
        $xml->appendChild($root);

        // Version
        $versionEl = $xml->createElement('version', $this->escapeXml($version));
        $root->appendChild($versionEl);

        // Record
        $record = $xml->createElement('record');
        $root->appendChild($record);

        $recordSchema = $xml->createElement('recordSchema', 'http://explain.z3950.org/dtd/2.1/');
        $record->appendChild($recordSchema);

        $recordPacking = $xml->createElement('recordPacking', $this->escapeXml($recordPacking));
        $record->appendChild($recordPacking);

        // Record data
        $recordData = $xml->createElement('recordData');
        $record->appendChild($recordData);

        // Explain record
        $explain = $xml->createElement('explain');
        $recordData->appendChild($explain);

        // Server info
        $serverInfo = $xml->createElement('serverInfo');
        $serverInfo->setAttribute('protocol', 'SRU');
        $serverInfo->setAttribute('version', '1.2');
        $explain->appendChild($serverInfo);

        $host = $xml->createElement('host', $this->escapeXml($host));
        $serverInfo->appendChild($host);

        $port = $xml->createElement('port', $this->escapeXml($port));
        $serverInfo->appendChild($port);

        $database = $xml->createElement('database', $this->escapeXml($database));
        $serverInfo->appendChild($database);

        // Database info
        $databaseInfo = $xml->createElement('databaseInfo');
        $explain->appendChild($databaseInfo);

        $title = $xml->createElement('title', 'Library Catalog - Pinakes');
        $databaseInfo->appendChild($title);

        $description = $xml->createElement('description', 'SRU interface to library catalog');
        $databaseInfo->appendChild($description);

        // Index info
        $indexInfo = $xml->createElement('indexInfo');
        $explain->appendChild($indexInfo);

        // Define searchable indexes
        $indexes = [
            ['title' => 'Title', 'name' => 'dc.title'],
            ['title' => 'Author', 'name' => 'dc.creator'],
            ['title' => 'Subject', 'name' => 'dc.subject'],
            ['title' => 'ISBN', 'name' => 'bath.isbn'],
            ['title' => 'Publisher', 'name' => 'dc.publisher'],
            ['title' => 'Date', 'name' => 'dc.date'],
            ['title' => 'Any', 'name' => 'cql.anywhere']
        ];

        foreach ($indexes as $idx) {
            $index = $xml->createElement('index');
            $indexInfo->appendChild($index);

            $indexTitle = $xml->createElement('title', $this->escapeXml($idx['title']));
            $index->appendChild($indexTitle);

            $map = $xml->createElement('map');
            $index->appendChild($map);

            $indexName = $xml->createElement('name', $this->escapeXml($idx['name']));
            $map->appendChild($indexName);
        }

        // Schema info
        $schemaInfo = $xml->createElement('schemaInfo');
        $explain->appendChild($schemaInfo);

        $supportedFormats = explode(',', $this->settings['supported_formats'] ?? 'marcxml,dc');
        $formatSchemas = [
            'marcxml' => 'info:srw/schema/1/marcxml-v1.1',
            'dc' => 'info:srw/schema/1/dc-v1.1',
            'mods' => 'info:srw/schema/1/mods-v3.6',
            'oai_dc' => 'http://www.openarchives.org/OAI/2.0/oai_dc/'
        ];

        foreach ($supportedFormats as $format) {
            $format = trim($format);
            if (isset($formatSchemas[$format])) {
                $schema = $xml->createElement('schema');
                $schema->setAttribute('identifier', $formatSchemas[$format]);
                $schema->setAttribute('name', $format);
                $schemaInfo->appendChild($schema);

                $schemaTitle = $xml->createElement('title', ucfirst($format));
                $schema->appendChild($schemaTitle);
            }
        }

        // Config info
        $configInfo = $xml->createElement('configInfo');
        $explain->appendChild($configInfo);

        $maxRecords = $xml->createElement('default', $this->escapeXml($this->settings['max_records'] ?? '100'));
        $maxRecords->setAttribute('type', 'numberOfRecords');
        $configInfo->appendChild($maxRecords);

        return $xml->saveXML();
    }

    /**
     * Handle 'searchRetrieve' operation
     * Performs catalog search and returns results
     *
     * @param array $params Request parameters
     * @return string XML response
     */
    private function handleSearchRetrieve(array $params): string
    {
        $version = $this->sanitizeString($params['version'] ?? '1.2');
        $query = $this->sanitizeString($params['query'] ?? '');
        $startRecord = max(1, (int)($params['startRecord'] ?? 1));
        $maximumRecords = min(
            (int)($params['maximumRecords'] ?? $this->settings['default_records'] ?? 10),
            (int)($this->settings['max_records'] ?? 100)
        );
        $recordSchema = $this->sanitizeString($params['recordSchema'] ?? $this->settings['default_format'] ?? 'marcxml');

        // Validate query parameter
        if (empty($query)) {
            return $this->errorResponse(7, 'Mandatory parameter not supplied: query', $version);
        }

        try {
            // Parse CQL query
            $cqlParser = new CQLParser();
            $sqlConditions = $cqlParser->parse($query);

            // Build SQL query with proper escaping (OWASP: SQL Injection Prevention)
            $sqlQuery = $this->buildSearchQuery($sqlConditions, $startRecord, $maximumRecords);

            // Execute query
            $result = $this->db->query($sqlQuery['count']);
            $totalRecords = $result ? $result->fetch_row()[0] : 0;
            if ($result) {
                $result->free();
            }

            $result = $this->db->query($sqlQuery['data']);
            $records = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $records[] = $row;
                }
                $result->free();
            }

            // Format response
            return $this->formatSearchResponse($version, $query, $totalRecords, $startRecord, count($records), $records, $recordSchema);
        } catch (\Exception $e) {
            return $this->errorResponse(10, 'Query syntax error: ' . $e->getMessage(), $version);
        }
    }

    /**
     * Handle 'scan' operation
     * Browse index terms
     *
     * @param array $params Request parameters
     * @return string XML response
     */
    private function handleScan(array $params): string
    {
        $version = $this->sanitizeString($params['version'] ?? '1.2');
        $scanClause = $this->sanitizeString($params['scanClause'] ?? '');
        $responsePosition = max(1, (int)($params['responsePosition'] ?? 1));
        $maximumTerms = min((int)($params['maximumTerms'] ?? 10), 100);

        if (empty($scanClause)) {
            return $this->errorResponse(7, 'Mandatory parameter not supplied: scanClause', $version);
        }

        // For now, return a basic scan response
        // Full implementation would scan the index
        return $this->formatScanResponse($version, $scanClause, $responsePosition, $maximumTerms);
    }

    /**
     * Build SQL query from CQL conditions
     *
     * @param array $conditions CQL conditions
     * @param int $startRecord Start record (1-based)
     * @param int $maximumRecords Maximum records to return
     * @return array Array with 'count' and 'data' queries
     */
    private function buildSearchQuery(array $conditions, int $startRecord, int $maximumRecords): array
    {
        $whereClause = $this->buildWhereClause($conditions);

        // Calculate offset (convert from 1-based to 0-based)
        $offset = $startRecord - 1;

        $baseQuery = "
            FROM libri l
            LEFT JOIN libri_autori la ON l.id = la.libro_id
            LEFT JOIN autori a ON la.autore_id = a.id
            LEFT JOIN editori e ON l.editore_id = e.id
            LEFT JOIN generi g ON l.genere_id = g.id
            WHERE {$whereClause}
            GROUP BY l.id
        ";

        return [
            'count' => "SELECT COUNT(DISTINCT l.id) " . $baseQuery,
            'data' => "
                SELECT
                    l.*,
                    GROUP_CONCAT(DISTINCT a.nome ORDER BY la.ordine_credito SEPARATOR '; ') as autori,
                    e.nome as editore,
                    g.nome as genere
                {$baseQuery}
                ORDER BY l.id
                LIMIT " . (int)$maximumRecords . " OFFSET " . (int)$offset
        ];
    }

    /**
     * Build WHERE clause from conditions
     *
     * @param array $conditions CQL conditions
     * @return string WHERE clause
     */
    private function buildWhereClause(array $conditions): string
    {
        if (empty($conditions)) {
            return '1=1';
        }

        $clauses = [];
        foreach ($conditions as $condition) {
            $index = $condition['index'] ?? 'cql.anywhere';
            $value = $this->db->real_escape_string($condition['value'] ?? '');

            switch ($index) {
                case 'dc.title':
                    $clauses[] = "(l.titolo LIKE '%{$value}%' OR l.sottotitolo LIKE '%{$value}%')";
                    break;

                case 'dc.creator':
                    $clauses[] = "a.nome LIKE '%{$value}%'";
                    break;

                case 'dc.subject':
                    $clauses[] = "(l.parole_chiave LIKE '%{$value}%' OR g.nome LIKE '%{$value}%')";
                    break;

                case 'bath.isbn':
                    $cleanIsbn = preg_replace('/[^0-9X]/', '', strtoupper($value));
                    $clauses[] = "(l.isbn10 = '{$cleanIsbn}' OR l.isbn13 = '{$cleanIsbn}')";
                    break;

                case 'dc.publisher':
                    $clauses[] = "e.nome LIKE '%{$value}%'";
                    break;

                case 'dc.date':
                    $clauses[] = "l.anno_pubblicazione = '{$value}'";
                    break;

                case 'cql.anywhere':
                default:
                    $clauses[] = "(
                        l.titolo LIKE '%{$value}%' OR
                        l.sottotitolo LIKE '%{$value}%' OR
                        l.descrizione LIKE '%{$value}%' OR
                        a.nome LIKE '%{$value}%' OR
                        e.nome LIKE '%{$value}%' OR
                        l.isbn10 LIKE '%{$value}%' OR
                        l.isbn13 LIKE '%{$value}%'
                    )";
            }
        }

        return implode(' AND ', $clauses);
    }

    /**
     * Format search response as XML
     *
     * @param string $version SRU version
     * @param string $query Original query
     * @param int $totalRecords Total records found
     * @param int $startRecord Start record position
     * @param int $returnedRecords Number of records returned
     * @param array $records Record data
     * @param string $recordSchema Record format
     * @return string XML response
     */
    private function formatSearchResponse(
        string $version,
        string $query,
        int $totalRecords,
        int $startRecord,
        int $returnedRecords,
        array $records,
        string $recordSchema
    ): string {
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $root = $xml->createElementNS(self::NS_SRU, 'searchRetrieveResponse');
        $xml->appendChild($root);

        // Add version
        $versionEl = $xml->createElement('version', $this->escapeXml($version));
        $root->appendChild($versionEl);

        // Add number of records
        $numRecords = $xml->createElement('numberOfRecords', (string)$totalRecords);
        $root->appendChild($numRecords);

        // Add records
        $formatter = RecordFormatter::create($recordSchema, $xml);

        $position = $startRecord;
        foreach ($records as $record) {
            $recordEl = $xml->createElement('record');
            $root->appendChild($recordEl);

            $recordSchema = $xml->createElement('recordSchema', $this->escapeXml($recordSchema));
            $recordEl->appendChild($recordSchema);

            $recordPacking = $xml->createElement('recordPacking', 'xml');
            $recordEl->appendChild($recordPacking);

            $recordPosition = $xml->createElement('recordPosition', (string)$position);
            $recordEl->appendChild($recordPosition);

            $recordData = $xml->createElement('recordData');
            $recordEl->appendChild($recordData);

            $formattedRecord = $formatter->format($record);
            $recordData->appendChild($formattedRecord);

            $position++;
        }

        // Echo query
        $echoedQuery = $xml->createElement('echoedSearchRetrieveRequest');
        $root->appendChild($echoedQuery);

        $queryEl = $xml->createElement('query', $this->escapeXml($query));
        $echoedQuery->appendChild($queryEl);

        return $xml->saveXML();
    }

    /**
     * Format scan response
     *
     * @param string $version SRU version
     * @param string $scanClause Scan clause
     * @param int $responsePosition Response position
     * @param int $maximumTerms Maximum terms
     * @return string XML response
     */
    private function formatScanResponse(
        string $version,
        string $scanClause,
        int $responsePosition,
        int $maximumTerms
    ): string {
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $root = $xml->createElementNS(self::NS_SRU, 'scanResponse');
        $xml->appendChild($root);

        $versionEl = $xml->createElement('version', $this->escapeXml($version));
        $root->appendChild($versionEl);

        $terms = $xml->createElement('terms');
        $root->appendChild($terms);

        // This is a basic implementation
        // A full implementation would scan the actual index

        return $xml->saveXML();
    }

    /**
     * Generate error response
     *
     * @param int $code Error code
     * @param string $message Error message
     * @param string $version SRU version
     * @return string XML error response
     */
    private function errorResponse(int $code, string $message, string $version = '1.2'): string
    {
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $root = $xml->createElementNS(self::NS_SRU, 'searchRetrieveResponse');
        $xml->appendChild($root);

        $versionEl = $xml->createElement('version', $this->escapeXml($version));
        $root->appendChild($versionEl);

        $diagnostics = $xml->createElement('diagnostics');
        $root->appendChild($diagnostics);

        $diagnostic = $xml->createElement('diagnostic');
        $diagnostics->appendChild($diagnostic);

        $uri = $xml->createElement('uri', self::NS_DIAG . $code);
        $diagnostic->appendChild($uri);

        $details = $xml->createElement('details', $this->escapeXml($message));
        $diagnostic->appendChild($details);

        $messageEl = $xml->createElement('message', $this->escapeXml($message));
        $diagnostic->appendChild($messageEl);

        return $xml->saveXML();
    }

    /**
     * Sanitize string input (OWASP: Input Validation)
     *
     * @param mixed $input Input value
     * @return string Sanitized string
     */
    private function sanitizeString($input): string
    {
        if (!is_string($input)) {
            return '';
        }

        // Remove null bytes
        $input = str_replace("\0", '', $input);

        // Trim whitespace
        $input = trim($input);

        return $input;
    }

    /**
     * Escape XML special characters
     *
     * @param string $text Text to escape
     * @return string Escaped text
     */
    private function escapeXml(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Log access
     *
     * @param string $operation SRU operation
     * @param array $params Request parameters
     */
    private function logAccess(string $operation, array $params): void
    {
        if ($this->settings['enable_logging'] !== 'true') {
            return;
        }

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $query = $params['query'] ?? null;
        $format = $params['recordSchema'] ?? null;

        $stmt = $this->db->prepare("
            INSERT INTO z39_access_logs (ip_address, user_agent, operation, query, format, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");

        $stmt->bind_param('sssss', $ipAddress, $userAgent, $operation, $query, $format);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Update access log with response info
     *
     * @param int $responseTime Response time in milliseconds
     * @param int $httpStatus HTTP status code
     * @param string|null $errorMessage Error message if any
     */
    private function updateAccessLog(int $responseTime, int $httpStatus, ?string $errorMessage = null): void
    {
        if ($this->settings['enable_logging'] !== 'true') {
            return;
        }

        // Update the most recent log entry
        $this->db->query("
            UPDATE z39_access_logs
            SET response_time_ms = {$responseTime},
                http_status = {$httpStatus},
                error_message = " . ($errorMessage ? "'" . $this->db->real_escape_string($errorMessage) . "'" : "NULL") . "
            WHERE id = (SELECT MAX(id) FROM (SELECT id FROM z39_access_logs) as t)
        ");
    }
}

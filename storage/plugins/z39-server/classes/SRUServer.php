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
    /** @var array<string,array<string,mixed>> */
    private array $indexDefinitions = [
        'dc.title' => [
            'type' => 'text',
            'columns' => ['l.titolo', 'l.sottotitolo'],
        ],
        'dc.creator' => [
            'type' => 'text',
            'columns' => ['a.nome'],
        ],
        'dc.subject' => [
            'type' => 'text',
            'columns' => ['l.parole_chiave', 'g.nome'],
        ],
        'dc.publisher' => [
            'type' => 'text',
            'columns' => ['e.nome'],
        ],
        'dc.date' => [
            'type' => 'numeric',
            'column' => 'l.anno_pubblicazione',
        ],
        'bath.isbn' => [
            'type' => 'isbn',
        ],
        'cql.anywhere' => [
            'type' => 'text',
            'columns' => [
                'l.titolo',
                'l.sottotitolo',
                'l.descrizione',
                'a.nome',
                'e.nome',
                'l.isbn10',
                'l.isbn13',
                'g.nome',
            ],
        ],
    ];

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
            $cqlParser = new CQLParser();
            $ast = $cqlParser->parse($query);

            $sqlQuery = $this->buildSearchQuery($ast, $startRecord, $maximumRecords);

            // Execute query
            $result = $this->db->query($sqlQuery['count']);
            if ($result) {
                $row = $result->fetch_row();
                $totalRecords = $row ? (int)$row[0] : 0;
                $result->free();
            } else {
                $totalRecords = 0;
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

        try {
            $parser = new CQLParser();
            $ast = $parser->parse($scanClause);
            $condition = $this->extractScanCondition($ast);

            $terms = $this->performScanQuery($condition['index'], $condition['value'], $maximumTerms);

            return $this->formatScanResponse($version, $scanClause, $terms, $responsePosition);
        } catch (\Exception $e) {
            return $this->errorResponse(10, 'Scan clause syntax error: ' . $e->getMessage(), $version);
        }
    }

    /**
     * Build SQL query from CQL conditions
     *
     * @param array $ast Parsed AST
     * @param int $startRecord Start record (1-based)
     * @param int $maximumRecords Maximum records to return
     * @return array Array with 'count' and 'data' queries
     */
    private function buildSearchQuery(array $ast, int $startRecord, int $maximumRecords): array
    {
        $whereClause = $this->buildWhereClause($ast);

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
     * Build WHERE clause from AST
     */
    private function buildWhereClause(?array $node): string
    {
        if ($node === null) {
            return '1=1';
        }

        $type = $node['type'] ?? '';
        switch ($type) {
            case 'boolean':
                $left = $this->buildWhereClause($node['left'] ?? null);
                $right = $this->buildWhereClause($node['right'] ?? null);
                $operator = strtoupper($node['operator'] ?? 'AND');
                if ($left === '' || $right === '') {
                    return $left ?: $right ?: '1=1';
                }
                return "({$left} {$operator} {$right})";

            case 'not':
                $operand = $this->buildWhereClause($node['operand'] ?? null);
                if ($operand === '') {
                    return '1=1';
                }
                return "(NOT {$operand})";

            case 'condition':
                $index = strtolower($node['index'] ?? 'cql.anywhere');
                $relation = $node['relation'] ?? '=';
                $value = $node['value'] ?? '';
                return $this->compileConditionClause($index, $relation, $value);

            default:
                return '1=1';
        }
    }

    private function compileConditionClause(string $index, string $relation, string $value): string
    {
        $definition = $this->indexDefinitions[$index] ?? $this->indexDefinitions['cql.anywhere'];
        $relation = $this->normalizeRelation($relation);
        $value = trim($value);

        if ($value === '' && $definition['type'] !== 'numeric') {
            return '1=1';
        }

        switch ($definition['type']) {
            case 'isbn':
                return $this->compileIsbnClause($relation, $value);

            case 'numeric':
                $column = $definition['column'] ?? 'l.anno_pubblicazione';
                return $this->compileNumericClause($column, $relation, $value);

            case 'text':
            default:
                $columns = $definition['columns'] ?? $this->indexDefinitions['cql.anywhere']['columns'];
                return $this->buildTextMatchClause($columns, $relation, $value);
        }
    }

    private function normalizeRelation(string $relation): string
    {
        $relation = strtolower(trim($relation));
        return match ($relation) {
            '==' => '=',
            '<>' => '!=',
            'exact' => 'exact',
            'all' => 'all',
            'any' => 'any',
            default => $relation === '' ? '=' : $relation,
        };
    }

    private function buildTextMatchClause(array $columns, string $relation, string $value): string
    {
        $columns = !empty($columns) ? $columns : $this->indexDefinitions['cql.anywhere']['columns'];
        $relation = $relation ?: '=';

        $like = function (string $term) use ($columns): string {
            $escaped = $this->escapeForLike($term);
            $clauses = array_map(
                fn ($column) => "{$column} LIKE '%{$escaped}%' ESCAPE '\\\\'",
                $columns
            );
            return '(' . implode(' OR ', $clauses) . ')';
        };

        return match ($relation) {
            'exact' => (function () use ($columns, $value): string {
                $escaped = $this->db->real_escape_string($value);
                $clauses = array_map(
                    fn ($column) => "{$column} = '{$escaped}'",
                    $columns
                );
                return '(' . implode(' OR ', $clauses) . ')';
            })(),
            '!=' => (function () use ($columns, $value): string {
                $escaped = $this->escapeForLike($value);
                $clauses = array_map(
                    fn ($column) => "{$column} NOT LIKE '%{$escaped}%' ESCAPE '\\\\'",
                    $columns
                );
                return '(' . implode(' AND ', $clauses) . ')';
            })(),
            'all' => (function () use ($value, $like): string {
                $terms = $this->splitTerms($value);
                if (empty($terms)) {
                    return '1=1';
                }
                $clauses = array_map($like, $terms);
                return '(' . implode(' AND ', $clauses) . ')';
            })(),
            'any' => (function () use ($value, $like): string {
                $terms = $this->splitTerms($value);
                if (empty($terms)) {
                    return '1=1';
                }
                $clauses = array_map($like, $terms);
                return '(' . implode(' OR ', $clauses) . ')';
            })(),
            default => $like($value),
        };
    }

    private function compileIsbnClause(string $relation, string $value): string
    {
        $clean = preg_replace('/[^0-9X]/i', '', strtoupper($value));
        if ($clean === '') {
            return '1=0';
        }
        $escaped = $this->db->real_escape_string($clean);

        if ($relation === '!=' ) {
            return "(l.isbn10 <> '{$escaped}' AND l.isbn13 <> '{$escaped}')";
        }

        return "(l.isbn10 = '{$escaped}' OR l.isbn13 = '{$escaped}')";
    }

    private function compileNumericClause(string $column, string $relation, string $value): string
    {
        if (!is_numeric($value)) {
            return '1=0';
        }

        $intValue = (int)$value;

        return match ($relation) {
            '>' => "{$column} > {$intValue}",
            '>=' => "{$column} >= {$intValue}",
            '<' => "{$column} < {$intValue}",
            '<=' => "{$column} <= {$intValue}",
            '!=' => "{$column} <> {$intValue}",
            default => "{$column} = {$intValue}",
        };
    }

    private function splitTerms(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/\\s+/u', $value);
        return array_values(array_filter($parts, fn ($part) => $part !== ''));
    }

    private function escapeForLike(string $value): string
    {
        $escaped = $this->db->real_escape_string($value);
        return str_replace(['%', '_'], ['\\%', '\\_'], $escaped);
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

            $recordSchemaEl = $xml->createElement('recordSchema', $this->escapeXml($recordSchema));
            $recordEl->appendChild($recordSchemaEl);

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

    private function extractScanCondition(array $ast): array
    {
        if (($ast['type'] ?? '') !== 'condition') {
            throw new \Exception('Scan clause must be a single index condition');
        }

        return [
            'index' => $ast['index'] ?? 'cql.anywhere',
            'value' => $ast['value'] ?? '',
        ];
    }

    private function performScanQuery(string $index, string $prefix, int $limit): array
    {
        $index = strtolower($index);
        $prefix = trim($prefix);
        $likePrefix = $this->escapeForLike($prefix);
        $pattern = $likePrefix . '%';

        switch ($index) {
            case 'dc.creator':
                $sql = "
                    SELECT nome AS term, COUNT(*) AS frequency
                    FROM autori
                    WHERE nome <> '' AND nome LIKE '{$pattern}' ESCAPE '\\\\'
                    GROUP BY nome
                    ORDER BY nome
                    LIMIT " . (int)$limit;
                break;

            case 'dc.subject':
                $sql = "
                    SELECT nome AS term, COUNT(*) AS frequency
                    FROM generi
                    WHERE nome <> '' AND nome LIKE '{$pattern}' ESCAPE '\\\\'
                    GROUP BY nome
                    ORDER BY nome
                    LIMIT " . (int)$limit;
                break;

            case 'bath.isbn':
                $sql = "
                    SELECT value AS term, COUNT(*) AS frequency FROM (
                        SELECT l.isbn10 AS value FROM libri l WHERE l.isbn10 <> ''
                        UNION ALL
                        SELECT l.isbn13 AS value FROM libri l WHERE l.isbn13 <> ''
                    ) AS isbns
                    WHERE value LIKE '{$pattern}' ESCAPE '\\\\'
                    GROUP BY value
                    ORDER BY value
                    LIMIT " . (int)$limit;
                break;

            case 'dc.title':
            case 'cql.anywhere':
            default:
                $sql = "
                    SELECT l.titolo AS term, COUNT(*) AS frequency
                    FROM libri l
                    WHERE l.titolo <> '' AND l.titolo LIKE '{$pattern}' ESCAPE '\\\\'
                    GROUP BY l.titolo
                    ORDER BY l.titolo
                    LIMIT " . (int)$limit;
                break;
        }

        $terms = [];
        $result = $this->db->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                if (!empty($row['term'])) {
                    $terms[] = [
                        'value' => $row['term'],
                        'frequency' => (int)($row['frequency'] ?? 0),
                    ];
                }
            }
            $result->free();
        }

        return $terms;
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
        array $terms,
        int $responsePosition
    ): string {
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $root = $xml->createElementNS(self::NS_SRU, 'scanResponse');
        $xml->appendChild($root);

        $versionEl = $xml->createElement('version', $this->escapeXml($version));
        $root->appendChild($versionEl);

        $termsEl = $xml->createElement('terms');
        $root->appendChild($termsEl);

        foreach ($terms as $offset => $termData) {
            $termEl = $xml->createElement('term');
            $termsEl->appendChild($termEl);

            $value = $xml->createElement('value', $this->escapeXml($termData['value'] ?? ''));
            $termEl->appendChild($value);

            $number = $xml->createElement('numberOfRecords', (string)($termData['frequency'] ?? 0));
            $termEl->appendChild($number);

            $position = $xml->createElement('position', (string)($responsePosition + $offset));
            $termEl->appendChild($position);
        }

        $echoed = $xml->createElement('echoedScanRequest');
        $root->appendChild($echoed);
        $echoed->appendChild($xml->createElement('scanClause', $this->escapeXml($scanClause)));

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

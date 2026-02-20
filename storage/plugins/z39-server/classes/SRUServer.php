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
    private ?int $lastLogId = null;
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
        // Library-specific indexes for advanced searching
        'library.location' => [
            'type' => 'text',
            'columns' => ['s.nome', 's.codice', 'l.collocazione'],
        ],
        'library.shelf' => [
            'type' => 'text',
            'columns' => ['s.nome', 's.codice'],
        ],
        'library.available' => [
            'type' => 'availability',
        ],
        'library.inventory' => [
            'type' => 'text',
            'columns' => ['c.numero_inventario'],
        ],
        'dc.identifier' => [
            'type' => 'text',
            'columns' => ['l.isbn10', 'l.isbn13', 'l.ean'],
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
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);
            $this->updateAccessLog($responseTime, 200);

            return $response;
        } catch (\Exception $e) {
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);
            // SECURITY FIX: Log detailed error internally, don't expose to client
            error_log("[SRU Server] Error in handleRequest: " . $e->getMessage());
            $this->updateAccessLog($responseTime, 500, 'Internal error');

            return $this->errorResponse(1, 'An internal error occurred. Please contact the administrator.', $version);
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
            ['title' => 'Identifier', 'name' => 'dc.identifier'],
            ['title' => 'Location', 'name' => 'library.location'],
            ['title' => 'Shelf', 'name' => 'library.shelf'],
            ['title' => 'Available', 'name' => 'library.available'],
            ['title' => 'Inventory Number', 'name' => 'library.inventory'],
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
        $startRecord = max(1, (int) ($params['startRecord'] ?? 1));
        $maximumRecords = min(
            (int) ($params['maximumRecords'] ?? $this->settings['default_records'] ?? 10),
            (int) ($this->settings['max_records'] ?? 100)
        );
        $recordSchema = $this->sanitizeString($params['recordSchema'] ?? $this->settings['default_format'] ?? 'marcxml');

        // Validate query parameter
        if (empty($query)) {
            return $this->errorResponse(7, 'Mandatory parameter not supplied: query', $version);
        }

        // DOS PROTECTION: Limit pagination offset to prevent resource exhaustion
        if ($startRecord > 10000) {
            return $this->errorResponse(6, 'Start record too high (max 10000)', $version);
        }

        try {
            $cqlParser = new CQLParser();
            $ast = $cqlParser->parse($query);

            $sqlQuery = $this->buildSearchQuery($ast, $startRecord, $maximumRecords, $params['sortKeys'] ?? '');

            $totalRecords = $this->executeCountQuery($sqlQuery['count']);
            $records = $this->executeDataQuery($sqlQuery['data']);

            // Format response
            return $this->formatSearchResponse($version, $query, $totalRecords, $startRecord, count($records), $records, $recordSchema);
        } catch (\Z39Server\Exceptions\UnsupportedIndexException $e) {
            return $this->errorResponse(16, $e->getMessage(), $version);
        } catch (\Z39Server\Exceptions\InvalidCQLSyntaxException | \Z39Server\Exceptions\UnsupportedRelationException $e) {
            return $this->errorResponse(10, $e->getMessage(), $version);
        } catch (\Z39Server\Exceptions\DatabaseException $e) {
            // SECURITY FIX: Don't expose database error details
            error_log("[SRU Server] Database error in searchRetrieve: " . $e->getMessage());
            return $this->errorResponse(1, 'A database error occurred. Please try again later.', $version);
        } catch (\Exception $e) {
            // SECURITY FIX: Don't expose system error details
            error_log("[SRU Server] Error in searchRetrieve: " . $e->getMessage());
            return $this->errorResponse(1, 'An error occurred while processing your request.', $version);
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
        $responsePosition = max(1, (int) ($params['responsePosition'] ?? 1));
        $maximumTerms = min((int) ($params['maximumTerms'] ?? 10), 100);

        if (empty($scanClause)) {
            return $this->errorResponse(7, 'Mandatory parameter not supplied: scanClause', $version);
        }

        try {
            $parser = new CQLParser();
            $ast = $parser->parse($scanClause);
            $condition = $this->extractScanCondition($ast);

            $terms = $this->performScanQuery($condition['index'], $condition['value'], $maximumTerms);

            return $this->formatScanResponse($version, $scanClause, $terms, $responsePosition);
        } catch (\Z39Server\Exceptions\UnsupportedIndexException $e) {
            return $this->errorResponse(16, $e->getMessage(), $version);
        } catch (\Z39Server\Exceptions\InvalidCQLSyntaxException | \Z39Server\Exceptions\UnsupportedRelationException $e) {
            return $this->errorResponse(10, $e->getMessage(), $version);
        } catch (\Z39Server\Exceptions\DatabaseException $e) {
            // SECURITY FIX: Don't expose database error details
            error_log("[SRU Server] Database error in scan: " . $e->getMessage());
            return $this->errorResponse(1, 'A database error occurred. Please try again later.', $version);
        } catch (\Exception $e) {
            // SECURITY FIX: Don't expose system error details
            error_log("[SRU Server] Error in scan: " . $e->getMessage());
            return $this->errorResponse(1, 'An error occurred while scanning the index.', $version);
        }
    }

    /**
     * Build SQL query from CQL conditions
     *
     * @param array $ast Parsed AST
     * @param int $startRecord Start record (1-based)
     * @param int $maximumRecords Maximum records to return
     * @param string $sortKeys SRU sort keys
     * @return array Array with 'count' and 'data' queries
     */
    private function buildSearchQuery(array $ast, int $startRecord, int $maximumRecords, string $sortKeys = ''): array
    {
        $whereClause = $this->buildWhereClause($ast);

        // Calculate offset (convert from 1-based to 0-based)
        $offset = $startRecord - 1;

        // Pass sortKeys to buildSortClause via the ast or argument? 
        // We passed it as argument. We'll use it in the data query construction.
        // The original code passed $ast only. I updated the signature.

        $baseQuery = "
            FROM libri l
            LEFT JOIN libri_autori la ON l.id = la.libro_id
            LEFT JOIN autori a ON la.autore_id = a.id
            LEFT JOIN editori e ON l.editore_id = e.id
            LEFT JOIN generi g ON l.genere_id = g.id
            LEFT JOIN posizioni p ON l.posizione_id = p.id
            LEFT JOIN scaffali s ON p.scaffale_id = s.id
            LEFT JOIN mensole m ON p.mensola_id = m.id
            LEFT JOIN copie c ON l.id = c.libro_id
            WHERE l.deleted_at IS NULL AND ({$whereClause})
            GROUP BY l.id
        ";

        return [
            'count' => "SELECT COUNT(DISTINCT l.id) " . $baseQuery,
            'data' => "
                SELECT
                    l.*,
                    GROUP_CONCAT(DISTINCT a.nome ORDER BY la.ordine_credito SEPARATOR '; ') as autori,
                    e.nome as editore,
                    g.nome as genere,
                    s.nome as scaffale,
                    m.numero_livello as mensola,
                    p.scaffale_id,
                    p.mensola_id
                {$baseQuery}
                " . $this->buildSortClause($ast['sortKeys'] ?? '') . "
                LIMIT " . (int) $maximumRecords . " OFFSET " . (int) $offset
        ];
    }

    private function executeCountQuery(string $sql): int
    {
        try {
            $result = $this->db->query($sql);
            if ($result) {
                $row = $result->fetch_row();
                $count = $row ? (int) $row[0] : 0;
                $result->free();
                return $count;
            }
            return 0;
        } catch (\mysqli_sql_exception $e) {
            throw new \Z39Server\Exceptions\DatabaseException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    private function executeDataQuery(string $sql): array
    {
        try {
            $result = $this->db->query($sql);
            $records = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $records[] = $row;
                }
                $result->free();
            }

            // Fetch detailed copy information
            $this->fetchCopiesForRecords($records);

            return $records;
        } catch (\mysqli_sql_exception $e) {
            throw new \Z39Server\Exceptions\DatabaseException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    private function fetchCopiesForRecords(array &$records): void
    {
        if (empty($records)) {
            return;
        }

        $bookIds = array_column($records, 'id');
        $idsStr = implode(',', array_map('intval', $bookIds));

        $sql = "SELECT * FROM copie WHERE libro_id IN ($idsStr) ORDER BY libro_id, numero_inventario";
        try {
            $result = $this->db->query($sql);

            $copiesByBook = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $copiesByBook[$row['libro_id']][] = $row;
                }
                $result->free();
            }

            foreach ($records as &$record) {
                $record['copies'] = $copiesByBook[$record['id']] ?? [];
            }
        } catch (\Exception $e) {
            // If copy fetch fails, just continue without copies
            error_log("SRU Server: Failed to fetch copies: " . $e->getMessage());
        }
    }

    private function buildSortClause(string $sortKeys): string
    {
        if (empty($sortKeys)) {
            return 'ORDER BY l.id';
        }

        // Simple sort key parsing (e.g. "dc.title,,1" or just "dc.title")
        // SRU 1.2 sortKeys format: path [schema], [ascending/descending], [caseSensitive], [missingValue]
        // We'll implement a simplified version

        $parts = explode(',', $sortKeys);
        $path = trim($parts[0]);
        $ascending = isset($parts[1]) ? (trim($parts[1]) !== '0') : true; // 1=asc, 0=desc

        $direction = $ascending ? 'ASC' : 'DESC';

        switch ($path) {
            case 'dc.title':
                return "ORDER BY l.titolo $direction";
            case 'dc.creator':
            case 'author':
                // Note: Sorting by GROUP_CONCAT column might be slow or behave unexpectedly in some SQL modes,
                // but usually works for basic sorting.
                // However, we can't easily sort by the aggregated column in the WHERE clause context without a subquery or using the alias in HAVING/ORDER BY.
                // Since we are using GROUP BY, we can order by the aggregate.
                // But 'a.nome' is not aggregated in the ORDER BY clause unless we use the alias or an aggregate function.
                // We'll use the alias 'autori' which is defined in the SELECT list.
                // BUT: buildSearchQuery puts this clause at the end.
                // MySQL allows ORDER BY alias.
                return "ORDER BY autori $direction";
            case 'dc.date':
                return "ORDER BY l.anno_pubblicazione $direction";
            case 'bath.isbn':
                return "ORDER BY l.isbn13 $direction";
            default:
                return 'ORDER BY l.id';
        }
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

            case 'availability':
                return $this->compileAvailabilityClause($relation, $value);

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
                fn($column) => "{$column} LIKE '%{$escaped}%' ESCAPE '\\\\'",
                $columns
            );
            return '(' . implode(' OR ', $clauses) . ')';
        };

        return match ($relation) {
            'exact' => (function () use ($columns, $value): string{
                    $escaped = $this->db->real_escape_string($value);
                    $clauses = array_map(
                    fn($column) => "{$column} = '{$escaped}'",
                    $columns
                    );
                    return '(' . implode(' OR ', $clauses) . ')';
                })(),
            '!=' => (function () use ($columns, $value): string{
                    $escaped = $this->escapeForLike($value);
                    $clauses = array_map(
                    fn($column) => "{$column} NOT LIKE '%{$escaped}%' ESCAPE '\\\\'",
                    $columns
                    );
                    return '(' . implode(' AND ', $clauses) . ')';
                })(),
            'all' => (function () use ($value, $like): string{
                    $terms = $this->splitTerms($value);
                    if (empty($terms)) {
                        return '1=1';
                    }
                    $clauses = array_map($like, $terms);
                    return '(' . implode(' AND ', $clauses) . ')';
                })(),
            'any' => (function () use ($value, $like): string{
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

        if ($relation === '!=') {
            return "(l.isbn10 <> '{$escaped}' AND l.isbn13 <> '{$escaped}')";
        }

        return "(l.isbn10 = '{$escaped}' OR l.isbn13 = '{$escaped}')";
    }

    private function compileNumericClause(string $column, string $relation, string $value): string
    {
        if (!is_numeric($value)) {
            return '1=0';
        }

        $intValue = (int) $value;

        return match ($relation) {
            '>' => "{$column} > {$intValue}",
            '>=' => "{$column} >= {$intValue}",
            '<' => "{$column} < {$intValue}",
            '<=' => "{$column} <= {$intValue}",
            '!=' => "{$column} <> {$intValue}",
            default => "{$column} = {$intValue}",
        };
    }

    /**
     * Compile availability clause for library.available searches
     * Searches for books with copies in specific availability states
     *
     * @param string $relation Relation operator
     * @param string $value Status value (disponibile, prestato, etc.) or boolean
     * @return string SQL WHERE clause
     */
    private function compileAvailabilityClause(string $relation, string $value): string
    {
        $value = strtolower(trim($value));

        // Handle boolean searches: "true", "yes", "available"
        if (in_array($value, ['true', 'yes', '1', 'available', 'disponibile'], true)) {
            // Find books with at least one available copy
            return "EXISTS (
                SELECT 1 FROM copie c 
                WHERE c.libro_id = l.id 
                AND c.stato = 'disponibile'
            )";
        }

        if (in_array($value, ['false', 'no', '0', 'unavailable', 'non_disponibile'], true)) {
            // Find books with NO available copies
            return "NOT EXISTS (
                SELECT 1 FROM copie c 
                WHERE c.libro_id = l.id 
                AND c.stato = 'disponibile'
            )";
        }

        // Specific status search
        $escaped = $this->db->real_escape_string($value);
        return "EXISTS (
            SELECT 1 FROM copie c 
            WHERE c.libro_id = l.id 
            AND c.stato = '{$escaped}'
        )";
    }

    private function splitTerms(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/\\s+/u', $value);
        return array_values(array_filter($parts, fn($part) => $part !== ''));
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
        $numRecords = $xml->createElement('numberOfRecords', (string) $totalRecords);
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

            $recordPosition = $xml->createElement('recordPosition', (string) $position);
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
        $limit = (int) $limit;

        // Escape LIKE wildcards in prefix to prevent unintended pattern matching
        $escapedPrefix = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $prefix);
        $pattern = $escapedPrefix . '%';

        // SECURITY FIX: Use prepared statements instead of string interpolation
        $terms = [];
        $stmt = null;
        $result = null;

        try {
            switch ($index) {
                case 'dc.creator':
                    $stmt = $this->db->prepare("
                        SELECT nome AS term, COUNT(*) AS frequency
                        FROM autori
                        WHERE nome <> '' AND nome LIKE ?
                        GROUP BY nome
                        ORDER BY nome
                        LIMIT ?
                    ");
                    if ($stmt) {
                        $stmt->bind_param('si', $pattern, $limit);
                        $stmt->execute();
                        $result = $stmt->get_result();
                    }
                    break;

                case 'dc.subject':
                    $stmt = $this->db->prepare("
                        SELECT nome AS term, COUNT(*) AS frequency
                        FROM generi
                        WHERE nome <> '' AND nome LIKE ?
                        GROUP BY nome
                        ORDER BY nome
                        LIMIT ?
                    ");
                    if ($stmt) {
                        $stmt->bind_param('si', $pattern, $limit);
                        $stmt->execute();
                        $result = $stmt->get_result();
                    }
                    break;

                case 'bath.isbn':
                    $stmt = $this->db->prepare("
                        SELECT value AS term, COUNT(*) AS frequency FROM (
                            SELECT isbn10 AS value FROM libri WHERE deleted_at IS NULL AND isbn10 <> '' AND isbn10 LIKE ?
                            UNION ALL
                            SELECT isbn13 AS value FROM libri WHERE deleted_at IS NULL AND isbn13 <> '' AND isbn13 LIKE ?
                        ) AS isbns
                        GROUP BY value
                        ORDER BY value
                        LIMIT ?
                    ");
                    if ($stmt) {
                        $stmt->bind_param('ssi', $pattern, $pattern, $limit);
                        $stmt->execute();
                        $result = $stmt->get_result();
                    }
                    break;

                case 'dc.title':
                case 'cql.anywhere':
                default:
                    $stmt = $this->db->prepare("
                        SELECT titolo AS term, COUNT(*) AS frequency
                        FROM libri
                        WHERE titolo <> '' AND titolo LIKE ?
                        GROUP BY titolo
                        ORDER BY titolo
                        LIMIT ?
                    ");
                    if ($stmt) {
                        $stmt->bind_param('si', $pattern, $limit);
                        $stmt->execute();
                        $result = $stmt->get_result();
                    }
                    break;
            }

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    if (!empty($row['term'])) {
                        $terms[] = [
                            'value' => $row['term'],
                            'frequency' => (int) ($row['frequency'] ?? 0),
                        ];
                    }
                }
                $result->free();
            }

            if ($stmt) {
                $stmt->close();
            }
        } catch (\mysqli_sql_exception $e) {
            throw new \Z39Server\Exceptions\DatabaseException($e->getMessage(), (int) $e->getCode(), $e);
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

            $number = $xml->createElement('numberOfRecords', (string) ($termData['frequency'] ?? 0));
            $termEl->appendChild($number);

            $position = $xml->createElement('position', (string) ($responsePosition + $offset));
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

        // Store insert_id to avoid race condition in updateAccessLog
        $this->lastLogId = $stmt->insert_id ?: null;

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
        if ($this->settings['enable_logging'] !== 'true' || $this->lastLogId === null) {
            return;
        }

        // Update the specific log entry by ID (avoids race condition)
        $stmt = $this->db->prepare("
            UPDATE z39_access_logs
            SET response_time_ms = ?,
                http_status = ?,
                error_message = ?
            WHERE id = ?
        ");

        if ($stmt) {
            $stmt->bind_param('iisi', $responseTime, $httpStatus, $errorMessage, $this->lastLogId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

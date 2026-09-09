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
    /** @phpstan-ignore property.onlyWritten */
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
            'columns' => ['a.nome', 'a.pseudonimo'],
        ],
        'dc.subject' => [
            'type' => 'text',
            'columns' => ['l.parole_chiave', 'g.nome'],
        ],
        'dc.publisher' => [
            'type' => 'text',
            // Primary publisher (editori) plus secondary publishers via the
            // libri_editori junction (#143), mirroring OAI-PMH/web parity.
            'columns' => ['e.nome', self::PUBLISHER_MATCH],
        ],
        'dc.date' => [
            'type' => 'numeric',
            'column' => 'l.anno_pubblicazione',
        ],
        'bath.isbn' => [
            'type' => 'isbn',
        ],
        // Bath profile ISSN index (Z39.50 bib-1 Use attribute 8). Books carry
        // an optional libri.issn; the serials arm (issue #140) resolves the
        // same index against the emeroteca masthead ISSNs.
        'bath.issn' => [
            'type' => 'text',
            'columns' => ['l.issn'],
        ],
        'cql.anywhere' => [
            'type' => 'text',
            'columns' => [
                'l.titolo',
                'l.sottotitolo',
                'l.descrizione',
                'a.nome',
                'a.pseudonimo',
                'e.nome',
                self::PUBLISHER_MATCH,
                'l.isbn10',
                'l.isbn13',
                'l.ean',
                'l.issn',
                'l.parole_chiave',
                'l.collana',
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
            'columns' => ['l.isbn10', 'l.isbn13', 'l.ean', 'l.issn'],
        ],
    ];

    /**
     * Issue #140 — serials arm. Which CQL indexes can be resolved against the
     * Emeroteca masthead table, and how. An index that is not listed here contributes a false leaf, preserving
     * OR/AND/NOT semantics when book-only and serial indexes are combined.
     *
     * @var array<string,array<string,mixed>>
     */
    private array $serialIndexDefinitions = [
        'dc.title'      => ['type' => 'text',    'columns' => ['t.titolo', 't.sottotitolo']],
        'bath.issn'     => ['type' => 'issn'],
        'dc.identifier' => ['type' => 'issn'],
        'dc.publisher'  => ['type' => 'text',    'columns' => ['pe.nome']],
        'dc.subject'    => ['type' => 'text',    'columns' => ['tg.nome']],
        'dc.date'       => ['type' => 'numeric', 'column'  => 't.anno_inizio'],
        'cql.anywhere'  => ['type' => 'text',    'columns' => [
            't.titolo', 't.sottotitolo', 't.descrizione',
            't.issn', 't.e_issn', 't.issn_l',
            't.luogo_pubblicazione', 'pe.nome', 'tg.nome',
        ]],
    ];

    /** Cached information_schema probes for the optional Emeroteca tables. */
    private ?bool $serialsExposedCache = null;
    /** @var array<string,bool> */
    private array $tableProbeCache = [];
    /** Cached information_schema probes for optional columns ("table.column"). */
    /** @var array<string,bool> */
    private array $columnProbeCache = [];

    // SRU namespaces
    // Sentinel "column" for secondary-publisher matching: expanded to a correlated
    // EXISTS on libri_editori in buildTextMatchClause, so no base-query JOIN is needed.
    private const PUBLISHER_MATCH = '__pub_exists__';

    private const NS_SRU = 'http://www.loc.gov/zing/srw/';
    private const NS_DIAG = 'info:srw/diagnostic/1/';

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
        } catch (\Throwable $e) {
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);
            // SECURITY FIX: Log detailed error internally, don't expose to client
            \App\Support\SecureLogger::error("[SRU Server] Error in handleRequest: " . $e->getMessage());
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
        $explain = $xml->createElementNS('http://explain.z3950.org/dtd/2.1/', 'explain');
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
            ['title' => 'ISSN', 'name' => 'bath.issn'],
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
            'marcxml'    => 'info:srw/schema/1/marcxml-v1.1',
            'dc'         => 'info:srw/schema/1/dc-v1.1',
            'mods'       => 'info:srw/schema/1/mods-v3.6',
            'oai_dc'     => 'http://www.openarchives.org/OAI/2.0/oai_dc/',
            'unimarcxml' => 'info:srw/schema/8/unimarcxml-v0.1',
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

            // ── Issue #140: serials tail ───────────────────────────────────
            // Periodical mastheads live in their own table, so the result set
            // is the CONCATENATION books-then-serials over a single SRU window:
            // numberOfRecords covers both, and the page is filled from the
            // serials only once the book rows for this window are exhausted.
            // sortKeys therefore orders WITHIN the books block; the serials
            // block always follows in (title, id) order.
            // Absent/deactivated Emeroteca (or a query whose indexes have no
            // serial meaning) leaves everything below untouched.
            $serialWhere = $this->serialsExposed() ? $this->buildSerialWhereClause($ast) : null;
            if ($serialWhere !== null) {
                $totalSerials = $this->countSerialRecords($serialWhere);
                if ($totalSerials > 0) {
                    $slots = $maximumRecords - count($records);
                    // Offset INTO the serials block: computed against the book
                    // total BEFORE it absorbs $totalSerials.
                    $serialOffset = max(0, ($startRecord - 1) - $totalRecords);
                    if ($slots > 0 && $serialOffset < $totalSerials) {
                        $records = array_merge(
                            $records,
                            $this->fetchSerialRecords($serialWhere, $slots, $serialOffset)
                        );
                    }
                    $totalRecords += $totalSerials;
                }
            }

            // Format response
            return $this->formatSearchResponse($version, $query, $totalRecords, $startRecord, count($records), $records, $recordSchema, $maximumRecords);
        } catch (\Z39Server\Exceptions\UnsupportedIndexException $e) {
            return $this->errorResponse(16, $e->getMessage(), $version);
        } catch (\Z39Server\Exceptions\InvalidCQLSyntaxException | \Z39Server\Exceptions\UnsupportedRelationException $e) {
            return $this->errorResponse(10, $e->getMessage(), $version);
        } catch (\Z39Server\Exceptions\DatabaseException $e) {
            // SECURITY FIX: Don't expose database error details
            \App\Support\SecureLogger::error("[SRU Server] Database error in searchRetrieve: " . $e->getMessage());
            return $this->errorResponse(1, 'A database error occurred. Please try again later.', $version);
        } catch (\Throwable $e) {
            // SECURITY FIX: Don't expose system error details
            \App\Support\SecureLogger::error("[SRU Server] Error in searchRetrieve: " . $e->getMessage());
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
            return $this->errorResponse(7, 'Mandatory parameter not supplied: scanClause', $version, 'scan');
        }

        try {
            $parser = new CQLParser();
            $ast = $parser->parse($scanClause);
            $condition = $this->extractScanCondition($ast);

            $terms = $this->performScanQuery($condition['index'], $condition['value'], $maximumTerms);

            return $this->formatScanResponse($version, $scanClause, $terms, $responsePosition);
        } catch (\Z39Server\Exceptions\UnsupportedIndexException $e) {
            return $this->errorResponse(16, $e->getMessage(), $version, 'scan');
        } catch (\Z39Server\Exceptions\InvalidCQLSyntaxException | \Z39Server\Exceptions\UnsupportedRelationException $e) {
            return $this->errorResponse(10, $e->getMessage(), $version, 'scan');
        } catch (\Z39Server\Exceptions\DatabaseException $e) {
            // SECURITY FIX: Don't expose database error details
            \App\Support\SecureLogger::error("[SRU Server] Database error in scan: " . $e->getMessage());
            return $this->errorResponse(1, 'A database error occurred. Please try again later.', $version, 'scan');
        } catch (\Throwable $e) {
            // SECURITY FIX: Don't expose system error details
            \App\Support\SecureLogger::error("[SRU Server] Error in scan: " . $e->getMessage());
            return $this->errorResponse(1, 'An error occurred while scanning the index.', $version, 'scan');
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
                                      AND la.ruolo IN ('principale', 'co-autore')
            LEFT JOIN autori a ON la.autore_id = a.id
            LEFT JOIN editori e ON l.editore_id = e.id
            -- Secondary publishers (#143) are matched via a correlated EXISTS in
            -- the WHERE (see PUBLISHER_MATCH) rather than a LEFT JOIN, so the base
            -- query doesn't multiply rows (libri_autori × copie × libri_editori)
            -- before GROUP BY. Payload publishers come from fetchPublishersForRecords().
            LEFT JOIN generi g ON l.genere_id = g.id
            -- #5: resolve shelf/location from the CURRENT columns. The admin
            -- book save hard-sets libri.posizione_id = NULL and stores the
            -- location in libri.scaffale_id / libri.mensola_id / libri.collocazione,
            -- so the legacy posizioni chain is always empty for UI-saved books.
            LEFT JOIN scaffali s ON l.scaffale_id = s.id
            LEFT JOIN mensole m ON l.mensola_id = m.id
            LEFT JOIN copie c ON l.id = c.libro_id
            WHERE l.deleted_at IS NULL AND ({$whereClause})
            GROUP BY l.id
        ";

        return [
            // The grouped base query returns one row per book. Counting inside
            // that same GROUP BY yields one `1` row per book and the caller
            // reads only the first; wrap it to obtain the real total.
            'count' => "SELECT COUNT(*) FROM (SELECT l.id {$baseQuery}) sru_matches",
            'data' => "
                SELECT
                    l.*,
                    GROUP_CONCAT(DISTINCT a.nome ORDER BY la.ordine_credito SEPARATOR '; ') as autori,
                    (SELECT GROUP_CONCAT(
                                CONCAT(HEX(la_all.ruolo), ':', HEX(" . \App\Support\AuthorName::displaySql('a_all') . "))
                                ORDER BY (la_all.ruolo = 'principale') DESC,
                                         (la_all.ruolo = 'co-autore') DESC,
                                         la_all.ordine_credito IS NULL,
                                         la_all.ordine_credito,
                                         la_all.autore_id
                                SEPARATOR ',')
                       FROM libri_autori la_all
                       JOIN autori a_all ON a_all.id = la_all.autore_id
                      WHERE la_all.libro_id = l.id) AS contributors_encoded,
                    e.nome as editore,
                    g.nome as genere,
                    s.nome as scaffale,
                    m.numero_livello as mensola
                {$baseQuery}
                " . $this->buildSortClause($sortKeys) . "
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
                    $row['contributors'] = $this->decodeContributors((string) ($row['contributors_encoded'] ?? ''));
                    unset($row['contributors_encoded']);
                    $records[] = $row;
                }
                $result->free();
            }

            // Fetch detailed copy information
            $this->fetchCopiesForRecords($records);

            // Fetch every publisher (primary + secondary co-publishers, #143)
            $this->fetchPublishersForRecords($records);

            return $records;
        } catch (\mysqli_sql_exception $e) {
            throw new \Z39Server\Exceptions\DatabaseException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Decode the delimiter-safe HEX payload produced by buildSearchQuery().
     *
     * @return list<array{nome:string,ruolo:string}>
     */
    private function decodeContributors(string $encoded): array
    {
        if ($encoded === '') {
            return [];
        }
        $rows = [];
        foreach (explode(',', $encoded) as $pair) {
            [$roleHex, $nameHex] = array_pad(explode(':', $pair, 2), 2, '');
            if ($roleHex === '' || $nameHex === ''
                || preg_match('/^[0-9A-F]+$/i', $roleHex) !== 1
                || preg_match('/^[0-9A-F]+$/i', $nameHex) !== 1
            ) {
                continue;
            }
            $role = hex2bin($roleHex);
            $name = hex2bin($nameHex);
            if ($role === false || $name === false || trim($name) === '') {
                continue;
            }
            $rows[] = ['nome' => trim($name), 'ruolo' => $role];
        }
        return $rows;
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
        } catch (\Throwable $e) {
            // If copy fetch fails, just continue without copies
            \App\Support\SecureLogger::warning("SRU Server: Failed to fetch copies: " . $e->getMessage());
        }
    }

    /**
     * Batch-fetch every publisher for the result page (primary + secondary
     * co-publishers via libri_editori, #143) and attach an ordered list of
     * names to each record. Falls back to the primary editore when the
     * junction is empty, matching OaiPmhServerPlugin::fetchPublishersForBook.
     *
     * @param array<int,array<string,mixed>> $records
     */
    private function fetchPublishersForRecords(array &$records): void
    {
        if (empty($records)) {
            return;
        }

        $bookIds = array_column($records, 'id');
        $idsStr = implode(',', array_map('intval', $bookIds));
        if ($idsStr === '') {
            return;
        }

        $publishersByBook = [];
        try {
            $sql = "SELECT le.libro_id, e.nome
                    FROM libri_editori le
                    JOIN editori e ON e.id = le.editore_id
                    WHERE le.libro_id IN ($idsStr)
                    ORDER BY le.libro_id, le.ordine, e.nome";
            $result = $this->db->query($sql);
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $name = trim((string) ($row['nome'] ?? ''));
                    if ($name !== '') {
                        $publishersByBook[$row['libro_id']][] = $name;
                    }
                }
                $result->free();
            }
        } catch (\Throwable $e) {
            // If the junction lookup fails, fall back to the primary publisher.
            \App\Support\SecureLogger::warning("SRU Server: Failed to fetch publishers: " . $e->getMessage());
        }

        foreach ($records as &$record) {
            $names = $publishersByBook[$record['id']] ?? [];
            if ($names === []) {
                $primary = trim((string) ($record['editore'] ?? ''));
                if ($primary !== '') {
                    $names[] = $primary;
                }
            }
            $record['publishers'] = $names;
        }
        unset($record);
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
                /** @var list<string> $columns */
                $columns = array_values(array_filter(
                    $columns,
                    fn (string $column): bool => $this->bookColumnAvailable($column)
                ));
                // Every column of this index is missing from this schema, so no
                // book can match. '1=0' keeps the books arm a valid clause (the
                // serials arm may still answer the same query).
                if ($columns === []) {
                    return '1=0';
                }
                return $this->buildTextMatchClause($columns, $relation, $value);
        }
    }

    /**
     * FIX (issue #140 review): `libri.issn` is NOT part of the original schema
     * — it arrives with migrate_0.4.7, which is exactly why the core
     * BookRepository guards every read/write of it behind hasColumn(). This
     * class referenced it unguarded from three index definitions
     * (bath.issn, dc.identifier, cql.anywhere), so on an install where that
     * migration had not run every cql.anywhere search — i.e. the default index,
     * i.e. nearly every SRU request — would have died with
     * "Unknown column 'l.issn' in 'where clause'".
     *
     * Only genuinely optional columns are probed: the rest of the index
     * definitions reference columns the base query already JOINs on, so a
     * blanket probe here would give false confidence without making those
     * installs work.
     */
    private function bookColumnAvailable(string $column): bool
    {
        if ($column !== 'l.issn') {
            return true;
        }

        return $this->columnProbe('libri', 'issn');
    }

    /** Cached information_schema existence probe for an optional column. */
    private function columnProbe(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, $this->columnProbeCache)) {
            return $this->columnProbeCache[$key];
        }
        $exists = false;
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            if ($stmt !== false) {
                $stmt->bind_param('ss', $table, $column);
                if ($stmt->execute()) {
                    $res = $stmt->get_result();
                    $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
                    $exists = ((int) ($row['c'] ?? 0)) > 0;
                }
                $stmt->close();
            }
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::warning(
                '[SRU Server] column probe failed for ' . $key . ': ' . $e->getMessage()
            );
            $exists = false;
        }

        return $this->columnProbeCache[$key] = $exists;
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

    /**
     * SQL fragment matching one search column against an already-escaped value.
     * The PUBLISHER_MATCH sentinel expands to a correlated EXISTS on
     * libri_editori (secondary publishers, #143) so the base query needs no
     * fan-out JOIN; every other column is a plain LIKE/=/NOT LIKE.
     *
     * @param string $mode 'like' | 'notlike' | 'exact'
     */
    private function textColumnClause(string $column, string $escaped, string $mode): string
    {
        if ($column === self::PUBLISHER_MATCH) {
            $inner = $mode === 'exact'
                ? "e2.nome = '{$escaped}'"
                : "e2.nome LIKE '%{$escaped}%' ESCAPE '\\\\'";
            $exists = "EXISTS (SELECT 1 FROM libri_editori le_pub JOIN editori e2 ON e2.id = le_pub.editore_id WHERE le_pub.libro_id = l.id AND {$inner})";
            return $mode === 'notlike' ? "NOT {$exists}" : $exists;
        }

        return match ($mode) {
            'exact'   => "{$column} = '{$escaped}'",
            'notlike' => "({$column} IS NULL OR {$column} NOT LIKE '%{$escaped}%' ESCAPE '\\\\')",
            default   => "{$column} LIKE '%{$escaped}%' ESCAPE '\\\\'",
        };
    }

    private function buildTextMatchClause(array $columns, string $relation, string $value): string
    {
        $columns = !empty($columns) ? $columns : $this->indexDefinitions['cql.anywhere']['columns'];
        $relation = $relation ?: '=';

        $like = function (string $term) use ($columns): string {
            $escaped = $this->escapeForLike($term);
            $clauses = array_map(
                fn($column) => $this->textColumnClause($column, $escaped, 'like'),
                $columns
            );
            return '(' . implode(' OR ', $clauses) . ')';
        };

        return match ($relation) {
            'exact' => (function () use ($columns, $value): string{
                    $escaped = $this->db->real_escape_string($value);
                    $clauses = array_map(
                    fn($column) => $this->textColumnClause($column, $escaped, 'exact'),
                    $columns
                    );
                    return '(' . implode(' OR ', $clauses) . ')';
                })(),
            '!=' => (function () use ($columns, $value): string{
                    $escaped = $this->escapeForLike($value);
                    $clauses = array_map(
                    fn($column) => $this->textColumnClause($column, $escaped, 'notlike'),
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
        // #4: answer availability from the canonical counter libri.copie_disponibili
        // (maintained by App\Support\DataIntegrity, used by web + Mobile API +
        // NCIP). It subtracts active reservations and pending loans holding a
        // copy and excludes perso/danneggiato/manutenzione/in_restauro/
        // in_trasferimento copies from the total — none of which a raw
        // EXISTS(copie.stato='disponibile') probe accounts for.
        if (in_array($value, ['true', 'yes', '1', 'available', 'disponibile'], true)) {
            // Books with at least one canonically available copy
            return "(COALESCE(l.copie_disponibili, 0) > 0)";
        }

        if (in_array($value, ['false', 'no', '0', 'unavailable', 'non_disponibile'], true)) {
            // Books with no canonically available copy
            return "(COALESCE(l.copie_disponibili, 0) <= 0)";
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

    // ── Serials (Emeroteca mastheads) — issue #140 ────────────────────────────

    /**
     * Are periodical mastheads searchable right now? Requires the Emeroteca
     * plugin to be ACTIVE and its masthead table to exist — the same
     * plugin-active AND table-exists gate the OAI-PMH `periodicals` set uses.
     * Any failure degrades to "not exposed", so a missing/deactivated plugin
     * changes nothing in the SRU behaviour.
     */
    private function serialsExposed(): bool
    {
        if ($this->serialsExposedCache !== null) {
            return $this->serialsExposedCache;
        }

        $active = false;
        try {
            $stmt = $this->db->prepare("SELECT is_active FROM plugins WHERE name = 'emeroteca' LIMIT 1");
            if ($stmt !== false) {
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
                $stmt->close();
                $active = (int) ($row['is_active'] ?? 0) === 1;
            }
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::warning('[SRU Server] emeroteca activation probe failed: ' . $e->getMessage());
            $active = false;
        }

        return $this->serialsExposedCache = ($active && $this->tableProbe('emeroteca_testate'));
    }

    /** Cached information_schema existence probe for an optional table. */
    private function tableProbe(string $table): bool
    {
        if (array_key_exists($table, $this->tableProbeCache)) {
            return $this->tableProbeCache[$table];
        }
        $exists = false;
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) AS c FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            if ($stmt !== false) {
                $stmt->bind_param('s', $table);
                if ($stmt->execute()) {
                    $res = $stmt->get_result();
                    $exists = $res instanceof \mysqli_result
                        && ((int) ($res->fetch_assoc()['c'] ?? 0)) > 0;
                }
                $stmt->close();
            }
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::warning('[SRU Server] table probe failed for ' . $table . ': ' . $e->getMessage());
        }

        return $this->tableProbeCache[$table] = $exists;
    }

    /**
     * Compile the CQL AST against the masthead table.
     *
     * A leaf with no serial counterpart is false for serials. Preserve it in
     * the boolean expression: title OR author can still match a title, while
     * title AND author cannot. Null is reserved for malformed AST nodes.
     *
     * @param array<string,mixed>|null $node
     */
    private function buildSerialWhereClause(?array $node): ?string
    {
        if ($node === null) {
            return null;
        }

        switch ($node['type'] ?? '') {
            case 'boolean':
                $left  = $this->buildSerialWhereClause(is_array($node['left'] ?? null) ? $node['left'] : null);
                $right = $this->buildSerialWhereClause(is_array($node['right'] ?? null) ? $node['right'] : null);
                if ($left === null || $right === null) {
                    return null;
                }
                // FIX (issue #140 review): 'NOT' removed from the allow-list.
                // CQLParser only ever sets operator = AND|OR on a 'boolean'
                // node (negation is its own 'not' node, handled below), so
                // 'NOT' was unreachable — but had it ever been produced it
                // would have compiled to `a NOT b`, which is not SQL. An
                // allow-list must not list a value it cannot render.
                $operator = strtoupper((string) ($node['operator'] ?? 'AND'));
                if (!in_array($operator, ['AND', 'OR'], true)) {
                    return null;
                }
                return "({$left} {$operator} {$right})";

            case 'not':
                $operand = $this->buildSerialWhereClause(is_array($node['operand'] ?? null) ? $node['operand'] : null);
                return $operand === null ? null : "(NOT {$operand})";

            case 'condition':
                return $this->compileSerialCondition(
                    strtolower((string) ($node['index'] ?? 'cql.anywhere')),
                    (string) ($node['relation'] ?? '='),
                    (string) ($node['value'] ?? '')
                );

            default:
                return null;
        }
    }

    /** One serial-side CQL condition; absent indexes match no serial record. */
    private function compileSerialCondition(string $index, string $relation, string $value): ?string
    {
        $definition = $this->serialIndexDefinitions[$index] ?? null;
        if ($definition === null) {
            return '1=0';
        }
        $relation = $this->normalizeRelation($relation);
        $value    = trim($value);

        if ($value === '' && $definition['type'] !== 'numeric') {
            return '1=1';
        }

        switch ($definition['type']) {
            case 'issn':
                return $this->compileSerialIssnClause($relation, $value);

            case 'numeric':
                return $this->compileNumericClause(
                    (string) ($definition['column'] ?? 't.anno_inizio'),
                    $relation,
                    $value
                );

            case 'text':
            default:
                /** @var list<string> $columns */
                $columns = array_values(array_filter(
                    $definition['columns'] ?? [],
                    fn (string $column): bool => $this->serialColumnAvailable($column)
                ));
                // Every column of this index lives on a core table the install
                // does not have: the index has no serial meaning here.
                if ($columns === []) {
                    return null;
                }
                return $this->buildTextMatchClause($columns, $relation, $value);
        }
    }

    /**
     * The masthead schema legitimately degrades without the optional core
     * registries (same guard the Emeroteca controllers apply): a column on a
     * missing table must never reach the SQL, because its JOIN is skipped too.
     */
    private function serialColumnAvailable(string $column): bool
    {
        if (str_starts_with($column, 'pe.')) {
            return $this->tableProbe('editori');
        }
        if (str_starts_with($column, 'tg.')) {
            return $this->tableProbe('generi');
        }

        return true;
    }

    /**
     * ISSN matching across the three masthead ISSN columns (issn, e_issn,
     * issn_l). Hyphens and case are normalised on both sides so "1234-5678",
     * "12345678" and "1234-567x" all resolve to the same record.
     */
    private function compileSerialIssnClause(string $relation, string $value): string
    {
        $clean = preg_replace('/[^0-9X]/i', '', strtoupper($value)) ?? '';
        if ($clean === '') {
            return '1=0';
        }
        $escaped = $this->db->real_escape_string($clean);

        $columns  = ['t.issn', 't.e_issn', 't.issn_l'];
        $normalize = static fn (string $column): string =>
            "REPLACE(UPPER(COALESCE({$column}, '')), '-', '')";

        if ($relation === '!=') {
            $clauses = array_map(
                static fn (string $c): string => $normalize($c) . " <> '{$escaped}'",
                $columns
            );
            return '(' . implode(' AND ', $clauses) . ')';
        }

        $clauses = array_map(
            static fn (string $c): string => $normalize($c) . " = '{$escaped}'",
            $columns
        );

        return '(' . implode(' OR ', $clauses) . ')';
    }

    /**
     * FROM/JOIN block shared by the serial count and data queries. The two
     * core registries are joined only when they exist — see
     * serialColumnAvailable(), which keeps their columns out of the WHERE in
     * exactly the same cases.
     */
    private function serialBaseFrom(string $whereClause): string
    {
        $joins = '';
        if ($this->tableProbe('editori')) {
            $joins .= ' LEFT JOIN editori pe ON t.editore_id = pe.id';
        }
        if ($this->tableProbe('generi')) {
            $joins .= ' LEFT JOIN generi tg ON t.genere_id = tg.id';
        }

        return "
            FROM emeroteca_testate t
            {$joins}
            WHERE ({$whereClause})
        ";
    }

    private function countSerialRecords(string $whereClause): int
    {
        return $this->executeCountQuery('SELECT COUNT(*) ' . $this->serialBaseFrom($whereClause));
    }

    /**
     * One page of masthead records, already mapped into the record shape the
     * formatters consume. Ordered by (titolo, id) so paging is stable.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchSerialRecords(string $whereClause, int $limit, int $offset): array
    {
        // The holdings statement (MARC 362) is derived from the years table
        // when it exists; on a partial schema the record simply carries none.
        $hasAnnate = $this->tableProbe('emeroteca_annate');
        $holdings = $hasAnnate && $this->tableProbe('emeroteca_fascicoli')
            ? "(SELECT CONCAT(MIN(a.anno), '-', MAX(a.anno)) FROM emeroteca_annate a
                 JOIN emeroteca_fascicoli f ON f.annata_id = a.id AND f.stato = 'posseduto'
                 WHERE a.testata_id = t.id)"
            : 'NULL';

        $editoreSel = $this->tableProbe('editori') ? 'pe.nome' : 'NULL';
        $genereSel  = $this->tableProbe('generi')  ? 'tg.nome' : 'NULL';

        $sql = "SELECT t.*, {$editoreSel} AS editore_nome, {$genereSel} AS genere_nome,
                       {$holdings} AS annate_range "
            . $this->serialBaseFrom($whereClause)
            . ' ORDER BY t.titolo ASC, t.id ASC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        try {
            $result = $this->db->query($sql);
        } catch (\mysqli_sql_exception $e) {
            throw new \Z39Server\Exceptions\DatabaseException($e->getMessage(), (int) $e->getCode(), $e);
        }
        if (!($result instanceof \mysqli_result)) {
            return [];
        }

        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        $declared = [];
        if ($hasAnnate && $rows !== []) {
            $ids = implode(',', array_map(static fn(array $row): int => (int) $row['id'], $rows));
            // The page contains at most maximumRecords titles. Aggregate their
            // declarations in PHP without GROUP_CONCAT's silent size ceiling.
            $res = $this->db->query(
                "SELECT testata_id, consistenza_dichiarata FROM emeroteca_annate
                  WHERE testata_id IN ({$ids}) AND consistenza_dichiarata IS NOT NULL
                    AND consistenza_dichiarata <> '' ORDER BY testata_id, anno, volume, id"
            );
            if ($res instanceof \mysqli_result) {
                while ($row = $res->fetch_assoc()) {
                    $declared[(int) $row['testata_id']][] = (string) $row['consistenza_dichiarata'];
                }
                $res->free();
            }
        }
        return array_map(fn(array $row): array => $this->mapSerialRecord($row + [
            'consistenza_dichiarata' => implode(' ; ', $declared[(int) $row['id']] ?? []),
        ]), $rows);
    }

    /**
     * Masthead row → record array consumed by every RecordFormatter.
     *
     * Field map (emeroteca_testate → record keys → MARC21 / UNIMARC):
     *   titolo, sottotitolo        → titolo/sottotitolo   → 245 $a$b / 200 $a$e
     *   issn, e_issn, issn_l       → issn/e_issn/issn_l   → 022 $a$l   / 011 $a
     *   editori.nome               → editore              → 264 $b     / 210 $c
     *   luogo_pubblicazione        → luogo_pubblicazione  → 264 $a     / 210 $a
     *   anno_inizio / anno_fine    → anno_pubblicazione / anno_fine → 264 $c / 210 $d
     *   periodicita                → periodicita          → 310 $a     / 326 $a
     *   annate range + declared    → numerazione          → 362 $a     / 207 $a
     *   lingua                     → lingua               → 041 / 101
     *   generi.nome                → genere               → 650 / 606
     *   descrizione                → descrizione          → 520 / 330
     *   public masthead URL        → public_url           → 856 $u
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function mapSerialRecord(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);

        $numbering = trim((string) ($row['annate_range'] ?? ''));
        $declared  = trim((string) ($row['consistenza_dichiarata'] ?? ''));
        if ($declared !== '') {
            $numbering = $numbering === '' ? $declared : $numbering . ' ; ' . $declared;
        }

        $publicUrl = '';
        if ($id > 0 && function_exists('absoluteUrl')) {
            $publicUrl = (string) \absoluteUrl('/emeroteca/' . $id);
        }

        return [
            // Namespaced control number: masthead ids share the numeric space
            // with book ids, so the raw id alone would collide in MARC 001.
            'id'                  => 'periodical:' . $id,
            '_record_type'        => 'periodical',
            'periodical_id'       => $id,
            'titolo'              => (string) ($row['titolo'] ?? ''),
            'sottotitolo'         => (string) ($row['sottotitolo'] ?? ''),
            'issn'                => (string) ($row['issn'] ?? ''),
            'e_issn'              => (string) ($row['e_issn'] ?? ''),
            'issn_l'              => (string) ($row['issn_l'] ?? ''),
            'editore'             => (string) ($row['editore_nome'] ?? ''),
            'genere'              => (string) ($row['genere_nome'] ?? ''),
            'luogo_pubblicazione' => (string) ($row['luogo_pubblicazione'] ?? ''),
            'lingua'              => (string) ($row['lingua'] ?? ''),
            'periodicita'         => (string) ($row['periodicita'] ?? ''),
            'tipo_periodico'      => (string) ($row['tipo'] ?? ''),
            'anno_pubblicazione'  => (string) ($row['anno_inizio'] ?? ''),
            'anno_fine'           => (string) ($row['anno_fine'] ?? ''),
            'numerazione'         => $numbering,
            'descrizione'         => (string) ($row['descrizione'] ?? ''),
            'stato_raccolta'      => (string) ($row['stato_raccolta'] ?? ''),
            'public_url'          => $publicUrl,
            // No author entities and no copies on a masthead record: keep the
            // keys present so the shared formatter helpers short-circuit.
            'contributors'        => [],
            'copies'              => [],
        ];
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
        string $recordSchema,
        int $maximumRecords = 10
    ): string {
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $root = $xml->createElementNS(self::NS_SRU, 'searchRetrieveResponse');
        $xml->appendChild($root);

        $ns = self::NS_SRU;

        // Add version (FIX 1: use createElementNS for SRU child elements)
        $versionEl = $xml->createElementNS($ns, 'version', $this->escapeXml($version));
        $root->appendChild($versionEl);

        // Add number of records (FIX 1)
        $numRecords = $xml->createElementNS($ns, 'numberOfRecords', (string) $totalRecords);
        $root->appendChild($numRecords);

        // FIX 3: nextRecordPosition when more records exist beyond this page
        $nextPos = $startRecord + $returnedRecords;
        if ($returnedRecords > 0 && $nextPos <= $totalRecords) {
            $root->appendChild($xml->createElementNS($ns, 'nextRecordPosition', (string) $nextPos));
        }

        // Add records
        $schemaKey = strtolower($recordSchema);
        $formatter = RecordFormatter::create($schemaKey, $xml);

        // FIX 5: use mods-v3.6 consistently
        $schemaUriMap = [
            'marcxml'    => 'info:srw/schema/1/marcxml-v1.1',
            'dc'         => 'info:srw/schema/1/dc-v1.1',
            'mods'       => 'info:srw/schema/1/mods-v3.6',
            'oai_dc'     => 'http://www.openarchives.org/OAI/2.0/oai_dc/',
            'unimarcxml' => 'info:srw/schema/8/unimarcxml-v0.1',
        ];
        $schemaUri = $schemaUriMap[$schemaKey] ?? $recordSchema;

        $recordsEl = $xml->createElementNS($ns, 'records');
        $root->appendChild($recordsEl);

        $position = $startRecord;
        foreach ($records as $record) {
            $recordEl = $xml->createElementNS($ns, 'record');
            $recordsEl->appendChild($recordEl);

            $recordSchemaEl = $xml->createElementNS($ns, 'recordSchema', $this->escapeXml($schemaUri));
            $recordEl->appendChild($recordSchemaEl);

            $recordPacking = $xml->createElementNS($ns, 'recordPacking', 'xml');
            $recordEl->appendChild($recordPacking);

            $recordPosition = $xml->createElementNS($ns, 'recordPosition', (string) $position);
            $recordEl->appendChild($recordPosition);

            $recordData = $xml->createElementNS($ns, 'recordData');
            $recordEl->appendChild($recordData);

            $formattedRecord = $formatter->format($record);
            $recordData->appendChild($formattedRecord);

            $position++;
        }

        // Echo query (FIX 1)
        $echoedQuery = $xml->createElementNS($ns, 'echoedSearchRetrieveRequest');
        $root->appendChild($echoedQuery);

        $queryEl = $xml->createElementNS($ns, 'query', $this->escapeXml($query));
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
                    // Frequency must reflect the number of non-deleted catalogue
                    // records a searchRetrieve would return for the author, not
                    // the number of authority rows sharing that name. The INNER
                    // JOINs drop authors whose only books are soft-deleted.
                    $stmt = $this->db->prepare("
                        SELECT a.nome AS term, COUNT(DISTINCT l.id) AS frequency
                        FROM autori a
                        JOIN libri_autori la ON la.autore_id = a.id
                        JOIN libri l ON l.id = la.libro_id AND l.deleted_at IS NULL
                        WHERE a.nome <> '' AND a.nome LIKE ?
                        GROUP BY a.nome
                        ORDER BY a.nome
                        LIMIT ?
                    ");
                    if ($stmt) {
                        $stmt->bind_param('si', $pattern, $limit);
                        $stmt->execute();
                        $result = $stmt->get_result();
                    }
                    break;

                case 'dc.subject':
                    // Count non-deleted records per genre name across both the
                    // primary genre and the subgenre; drop genres with no books.
                    $stmt = $this->db->prepare("
                        SELECT g.nome AS term, COUNT(DISTINCT l.id) AS frequency
                        FROM generi g
                        JOIN libri l ON (l.genere_id = g.id OR l.sottogenere_id = g.id)
                                    AND l.deleted_at IS NULL
                        WHERE g.nome <> '' AND g.nome LIKE ?
                        GROUP BY g.nome
                        ORDER BY g.nome
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
                        WHERE titolo <> '' AND titolo LIKE ? AND deleted_at IS NULL
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

        $ns = self::NS_SRU;

        // FIX 1b: use createElementNS for all SRU root-level child elements
        $versionEl = $xml->createElementNS($ns, 'version', $this->escapeXml($version));
        $root->appendChild($versionEl);

        $termsEl = $xml->createElementNS($ns, 'terms');
        $root->appendChild($termsEl);

        foreach ($terms as $offset => $termData) {
            $termEl = $xml->createElementNS($ns, 'term');
            $termsEl->appendChild($termEl);

            $value = $xml->createElementNS($ns, 'value', $this->escapeXml($termData['value'] ?? ''));
            $termEl->appendChild($value);

            $number = $xml->createElementNS($ns, 'numberOfRecords', (string) ($termData['frequency'] ?? 0));
            $termEl->appendChild($number);

            $position = $xml->createElementNS($ns, 'position', (string) ($responsePosition + $offset));
            $termEl->appendChild($position);
        }

        $echoed = $xml->createElementNS($ns, 'echoedScanRequest');
        $root->appendChild($echoed);
        $echoed->appendChild($xml->createElementNS($ns, 'scanClause', $this->escapeXml($scanClause)));

        return $xml->saveXML();
    }

    /**
     * Generate error response
     *
     * @param int $code Error code
     * @param string $message Error message
     * @param string $version SRU version
     * @param string $operation SRU operation context ('searchRetrieve', 'scan', 'explain')
     * @return string XML error response
     */
    private function errorResponse(int $code, string $message, string $version = '1.2', string $operation = 'searchRetrieve'): string
    {
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $rootElement = match ($operation) {
            'scan'    => 'scanResponse',
            'explain' => 'explainResponse',
            default   => 'searchRetrieveResponse',
        };

        $root = $xml->createElementNS(self::NS_SRU, $rootElement);
        $xml->appendChild($root);

        $ns = self::NS_SRU;

        $versionEl = $xml->createElementNS($ns, 'version', $this->escapeXml($version));
        $root->appendChild($versionEl);

        $diagnostics = $xml->createElementNS($ns, 'diagnostics');
        $root->appendChild($diagnostics);

        $diagnostic = $xml->createElementNS($ns, 'diagnostic');
        $diagnostics->appendChild($diagnostic);

        // FIX F086: diagnostic <uri>/<details>/<message> belong in NS_DIAG per SRU spec
        $uri = $xml->createElementNS(self::NS_DIAG, 'uri', self::NS_DIAG . $code);
        $diagnostic->appendChild($uri);

        $details = $xml->createElementNS(self::NS_DIAG, 'details', $this->escapeXml($message));
        $diagnostic->appendChild($details);

        $messageEl = $xml->createElementNS(self::NS_DIAG, 'message', $this->escapeXml($message));
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

        // FIX 4: null guard — table may be unavailable (e.g. during migration)
        if ($stmt === false) {
            return;
        }

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

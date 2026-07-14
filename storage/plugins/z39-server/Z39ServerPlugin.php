<?php
/**
 * Z39.50/SRU Server Plugin
 *
 * Implements a full SRU (Search/Retrieve via URL) server for exposing the library catalog.
 * SRU is the HTTP-based successor to Z39.50, providing standard interoperability with
 * library systems worldwide.
 *
 * Features:
 * - SRU protocol implementation (explain, searchRetrieve, scan)
 * - Multiple output formats (MARCXML, Dublin Core, MODS)
 * - CQL (Contextual Query Language) query support
 * - OWASP security best practices
 * - Rate limiting protection
 * - Comprehensive logging
 *
 * @package Z39ServerPlugin
 * @version 1.0.0
 * @see https://www.loc.gov/standards/sru/
 */

declare(strict_types=1);

use App\Support\HookManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class Z39ServerPlugin
{
    private mysqli $db;
    private ?int $pluginId = null;
    private static bool $routesRegistered = false;

    // Default settings
    private const DEFAULT_SETTINGS = [
        'server_enabled' => 'true',
        'server_host' => 'localhost',
        'server_port' => '80',
        'server_database' => 'catalog',
        'max_records' => '100',
        'default_records' => '10',
        'supported_formats' => 'marcxml,dc,mods,oai_dc,unimarcxml',
        'default_format' => 'marcxml',
        'require_authentication' => 'false',
        'rate_limit_enabled' => 'true',
        'rate_limit_requests' => '100',
        'rate_limit_window' => '3600',
        'enable_logging' => 'true',
        'cql_version' => '1.2',
        'sru_version' => '1.2',
        // Client settings (for scraping)
        'enable_client' => '1',
        'enable_sbn' => '1',
        'sbn_timeout' => '15',
        'sru_timeout' => '15'
    ];

    // Pre-configured Nordic SRU servers (publicly accessible, no authentication required)
    private const NORDIC_SERVERS = [
        [
            'name' => 'BIBSYS - Norwegian Union Catalogue',
            'url' => 'https://bibsys.alma.exlibrisgroup.com/view/sru/47BIBSYS_NETWORK',
            'version' => '1.2',
            'syntax' => 'marcxml',
            'enabled' => true,
            'indexes' => ['isbn' => 'alma.isbn'],
        ],
        [
            'name' => 'LIBRIS - Swedish Union Catalogue',
            'url' => 'https://libris.kb.se/sru/libris',
            'version' => '1.1',
            'syntax' => 'marcxml',
            'enabled' => true,
            'indexes' => ['isbn' => 'bath.isbn'],
        ],
    ];

    /**
     * Constructor - Initialize when plugin is loaded
     *
     * @param mysqli $db Database connection
     * @param HookManager $hookManager Hook manager instance (required by PluginManager API)
     * @phpstan-ignore constructor.unusedParameter
     */
    public function __construct(mysqli $db, HookManager $hookManager)
    {
        $this->db = $db;

        // Get plugin ID from database
        $result = $db->query("SELECT id FROM plugins WHERE name = 'z39-server' LIMIT 1");
        if ($result && $row = $result->fetch_assoc()) {
            $this->pluginId = (int) $row['id'];
        }
    }

    /**
     * Set plugin ID (called by PluginManager)
     *
     * @param int $pluginId
     */
    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
    }

    /**
     * Idempotent schema creation — called by both onInstall() and onActivate()
     * so tables are guaranteed to exist after any upgrade path.
     *
     * @return array{created: string[], failed: string[]}
     */
    /**
     * Tables this plugin's ensureSchema() always creates. Declared so
     * PluginManager's boot-time self-heal re-runs ensureSchema when any is
     * missing on an already-active plugin (a partial/aborted upgrade). One
     * cheap read-only probe; DDL only runs when a table is actually absent.
     *
     * @return list<string>
     */
    public function expectedTables(): array
    {
        return ['autore_varianti', 'libri_soggetti', 'soggetti', 'z39_access_logs', 'z39_rate_limits'];
    }

    public function ensureSchema(): array
    {
        $result = ['created' => [], 'failed' => []];

        $tables = [
            'z39_access_logs' => "CREATE TABLE IF NOT EXISTS z39_access_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL COMMENT 'Client IP address',
                user_agent TEXT COMMENT 'Client user agent',
                operation VARCHAR(50) NOT NULL COMMENT 'SRU operation (explain, searchRetrieve, scan)',
                query TEXT COMMENT 'CQL query string',
                format VARCHAR(20) COMMENT 'Record format requested',
                num_records INT DEFAULT 0 COMMENT 'Number of records returned',
                response_time_ms INT COMMENT 'Response time in milliseconds',
                http_status INT COMMENT 'HTTP status code',
                error_message TEXT COMMENT 'Error message if any',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ip (ip_address),
                INDEX idx_operation (operation),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'z39_rate_limits' => "CREATE TABLE IF NOT EXISTS z39_rate_limits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                request_count INT DEFAULT 1,
                window_start DATETIME NOT NULL,
                last_request DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_ip_window (ip_address, window_start),
                INDEX idx_window (window_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            // REICAT/SBN (issue #133): Nuovo Soggettario BNCF subject headings.
            // bncf_id is the BNCF thesaurus identifier; NULL for free-text subjects.
            // MySQL allows multiple NULLs in a UNIQUE column, so free terms don't clash.
            'soggetti' => "CREATE TABLE IF NOT EXISTS soggetti (
                id INT AUTO_INCREMENT PRIMARY KEY,
                termine VARCHAR(512) NOT NULL COMMENT 'Subject heading label',
                schema_soggetto VARCHAR(32) NOT NULL DEFAULT 'nuovo-soggettario' COMMENT 'Controlled vocabulary',
                bncf_id VARCHAR(32) NULL COMMENT 'Nuovo Soggettario BNCF identifier',
                uri VARCHAR(512) NULL COMMENT 'Authority URI (thes.bncf.firenze.sbn.it)',
                tipo VARCHAR(32) NULL COMMENT 'concept / place / name / form',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_bncf (bncf_id),
                INDEX idx_termine (termine)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'libri_soggetti' => "CREATE TABLE IF NOT EXISTS libri_soggetti (
                libro_id INT NOT NULL,
                soggetto_id INT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (libro_id, soggetto_id),
                INDEX idx_soggetto (soggetto_id),
                CONSTRAINT fk_libsog_soggetto FOREIGN KEY (soggetto_id)
                    REFERENCES soggetti (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            // REICAT 18.0 / 7.0: variant + earlier name forms and cross-references
            // for author authority records. tipo distinguishes pseudonym, earlier
            // form, generic variant, and 'rinvio' (see-reference).
            'autore_varianti' => "CREATE TABLE IF NOT EXISTS autore_varianti (
                id INT AUTO_INCREMENT PRIMARY KEY,
                autore_id INT NOT NULL,
                forma_variante VARCHAR(512) NOT NULL,
                tipo ENUM('pseudonimo','forma_precedente','variante','rinvio') NOT NULL DEFAULT 'variante',
                fonte VARCHAR(64) NULL COMMENT 'Authority source (SBN, VIAF, manual)',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_autore (autore_id),
                INDEX idx_forma (forma_variante)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($tables as $table => $ddl) {
            if ($this->db->query($ddl) !== false) {
                $result['created'][] = $table;
            } else {
                $result['failed'][] = $table;
            }
        }

        // REICAT/SBN nullable columns on the core libri/autori tables.
        // Idempotent: each ALTER is INFORMATION_SCHEMA-guarded so re-running
        // ensureSchema() (every activate / upgrade) is a no-op once applied.
        $columns = [
            ['libri',  'sbn_bid',             "VARCHAR(64) NULL COMMENT 'SBN Bibliographic ID (e.g. IT\\\\ICCU\\\\RMB\\\\0769708)'"],
            ['libri',  'sbn_authority_level', "VARCHAR(8) NULL COMMENT 'SBN cataloguing level: 95=original, 51=derived'"],
            ['libri',  'sbn_polo',            "VARCHAR(16) NULL COMMENT 'SBN polo/library code'"],
            ['autori', 'ccn',                 "VARCHAR(32) NULL COMMENT 'Codice di Controllo Nazionale (SBN authority ID)'"],
            ['autori', 'sbn_authorized_form', "VARCHAR(512) NULL COMMENT 'REICAT 18.0 authorized form of name'"],
            ['autori', 'qualifier_dates',     "VARCHAR(64) NULL COMMENT 'REICAT 7.0 homonym qualifier: dates'"],
            ['autori', 'qualifier_role',      "VARCHAR(128) NULL COMMENT 'REICAT 7.0 homonym qualifier: role/title'"],
        ];
        foreach ($columns as [$table, $column, $definition]) {
            if ($this->addColumnIfMissing($table, $column, $definition)) {
                $result['created'][] = "{$table}.{$column}";
            }
        }

        return $result;
    }

    /**
     * Idempotently add a nullable column to an existing table.
     *
     * Guarded by INFORMATION_SCHEMA so it is safe to call on every activate
     * and upgrade — returns false (no-op) when the column already exists.
     * The column name is validated against a strict identifier pattern before
     * interpolation since column names cannot be bound as prepared parameters.
     *
     * @return bool true if the column was just added, false if it already existed
     */
    private function addColumnIfMissing(string $table, string $column, string $definition): bool
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $table) || !preg_match('/^[a-z_][a-z0-9_]*$/i', $column)) {
            \App\Support\SecureLogger::error('[Z39 Server Plugin] Invalid identifier in addColumnIfMissing', [
                'table' => $table,
                'column' => $column,
            ]);
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = (int) ($stmt->get_result()->fetch_row()[0] ?? 0) > 0;
        $stmt->close();

        if ($exists) {
            return false;
        }

        // $table/$column validated above; $definition is a developer-controlled
        // constant (never user input). Safe to interpolate.
        return $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}") !== false;
    }

    /**
     * Hook: Executed during plugin installation
     * Creates necessary tables and sets up initial configuration
     */
    public function onInstall(): void
    {
        $result = $this->ensureSchema();
        if (!empty($result['failed'])) {
            throw new \RuntimeException(
                '[Z39Server] Schema install failed for: ' . implode(', ', $result['failed'])
            );
        }

        // Set default settings
        foreach (self::DEFAULT_SETTINGS as $key => $value) {
            $this->setSetting($key, $value);
        }

        // Set pre-configured Nordic SRU servers
        $this->setSetting('servers', json_encode(self::NORDIC_SERVERS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->log('info', 'Z39.50/SRU Server Plugin installed successfully', [
            'tables_created' => $result['created'],
            'default_settings' => count(self::DEFAULT_SETTINGS),
            'nordic_servers' => count(self::NORDIC_SERVERS),
        ]);
    }

    /**
     * Hook: Executed when plugin is activated
     * Registers hooks and starts the SRU server
     */
    public function onActivate(): void
    {
        $result = $this->ensureSchema();
        if (!empty($result['failed'])) {
            throw new \RuntimeException(
                '[Z39Server] Schema activation failed for: ' . implode(', ', $result['failed'])
            );
        }

        // Register hooks
        $this->registerHooks();

        // Auto-upgrade: add Nordic servers if not already configured
        $this->ensureNordicServers();

        // Ensure all default formats are present (non-destructive: preserves user additions)
        $current = array_filter(array_map('trim', explode(',', $this->getSetting('supported_formats', 'marcxml'))));
        $required = array_filter(array_map('trim', explode(',', self::DEFAULT_SETTINGS['supported_formats'])));
        $merged = array_values(array_unique(array_merge($current, $required)));
        $this->setSetting('supported_formats', implode(',', $merged));

        // Log activation
        $this->log('info', 'Z39.50/SRU Server Plugin activated', [
            'server_enabled' => $this->isSettingEnabled('enable_server') || $this->isSettingEnabled('server_enabled'),
            'supported_formats' => $this->getSetting('supported_formats')
        ]);
    }

    /**
     * Hook: Executed when plugin is deactivated
     */
    public function onDeactivate(): void
    {
        $this->setHooksActive(false);
        $this->log('info', 'Z39.50/SRU Server Plugin deactivated', [
            'data_preserved' => true
        ]);
    }

    /**
     * Hook: Executed during uninstallation
     * Cleans up all tables and data
     */
    public function onUninstall(): void
    {
        // Drop custom tables
        $this->db->query("DROP TABLE IF EXISTS z39_access_logs");
        $this->db->query("DROP TABLE IF EXISTS z39_rate_limits");

        $this->log('info', 'Z39.50/SRU Server Plugin uninstalled', [
            'tables_dropped' => ['z39_access_logs', 'z39_rate_limits']
        ]);
    }

    /**
     * Ensure Nordic SRU servers are present in configuration.
     * Adds any missing Nordic servers without overwriting user customizations.
     */
    private function ensureNordicServers(): void
    {
        $serversJson = $this->getSetting('servers', '[]');
        $servers = json_decode($serversJson, true);
        if (!is_array($servers)) {
            $servers = [];
        }

        // Index existing servers by URL for deduplication
        $existingUrls = [];
        foreach ($servers as $s) {
            if (!empty($s['url'])) {
                $existingUrls[] = $s['url'];
            }
        }

        $added = 0;
        foreach (self::NORDIC_SERVERS as $nordic) {
            if (!in_array($nordic['url'], $existingUrls, true)) {
                $servers[] = $nordic;
                $added++;
            }
        }

        if ($added > 0) {
            $this->setSetting('servers', json_encode($servers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->log('info', 'Nordic SRU servers added during upgrade', ['added' => $added]);
        }
    }

    /**
     * Register plugin hooks
     */
    private function registerHooks(): void
    {
        if ($this->pluginId === null) {
            return;
        }

        $hooks = [
            // Register API routes
            [
                'hook_name' => 'app.routes.register',
                'callback_method' => 'registerRoutes',
                'priority' => 10
            ],
            // Register SRU sources (so they appear in "sources consulted" lists)
            [
                'hook_name' => 'scrape.sources',
                'callback_method' => 'addSruSources',
                'priority' => 3
            ],
            // Register SRU Client for scraping (priority 3 = after Scraping Pro and API Book Scraper)
            // Z39.50/SRU provides excellent metadata but NO covers
            [
                'hook_name' => 'scrape.fetch.custom',
                'callback_method' => 'fetchBookMetadata',
                'priority' => 3
            ],
            // REICAT/SBN (issue #133): inject the cataloguing tab into the book
            // form and persist the REICAT fields + subjects on save.
            [
                'hook_name' => 'book.form.fields',
                'callback_method' => 'renderReicatBookFields',
                'priority' => 20
            ],
            [
                'hook_name' => 'book.save.after',
                'callback_method' => 'persistReicatBookFields',
                'priority' => 20
            ],
            // REICAT/SBN: inject the authority panel into the author edit form.
            [
                'hook_name' => 'author.form.fields',
                'callback_method' => 'renderReicatAuthorFields',
                'priority' => 20
            ]
        ];

        $this->deleteHooks();

        $callbackClass = 'Z39ServerPlugin';

        foreach ($hooks as $hook) {
            $stmt = $this->db->prepare("
                INSERT INTO plugin_hooks (plugin_id, hook_name, callback_class, callback_method, priority, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, 1, NOW())
                ON DUPLICATE KEY UPDATE
                    callback_class = VALUES(callback_class),
                    callback_method = VALUES(callback_method),
                    priority = VALUES(priority),
                    is_active = 1
            ");

            if ($stmt === false) {
                \App\Support\SecureLogger::error('[Z39 Server Plugin] Failed to prepare hook registration', ['error' => $this->db->error]);
                continue;
            }

            $stmt->bind_param(
                'isssi',
                $this->pluginId,
                $hook['hook_name'],
                $callbackClass,
                $hook['callback_method'],
                $hook['priority']
            );

            if (!$stmt->execute()) {
                \App\Support\SecureLogger::error('[Z39 Server Plugin] Failed to register hook', ['hook' => $hook['hook_name'], 'error' => $stmt->error]);
            }

            $stmt->close();
        }
    }

    /**
     * Disable hooks without deleting them (used during deactivate)
     */
    private function setHooksActive(bool $active): void
    {
        if ($this->pluginId === null) {
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE plugin_hooks
            SET is_active = ?
            WHERE plugin_id = ?
        ");

        if ($stmt === false) {
            return;
        }

        $activeInt = $active ? 1 : 0;
        $stmt->bind_param('ii', $activeInt, $this->pluginId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Remove all hooks for this plugin (used before re-registering)
     */
    private function deleteHooks(): void
    {
        if ($this->pluginId === null) {
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM plugin_hooks WHERE plugin_id = ?");
        if ($stmt === false) {
            return;
        }

        $stmt->bind_param('i', $this->pluginId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Register SRU API routes
     * Called by app.routes.register hook
     *
     * @param \Slim\App $app Slim application instance
     */
    public function registerRoutes($app): void
    {
        if (self::$routesRegistered) {
            return;
        }

        // Check if plugin is active before registering routes
        if (!$this->isPluginActive()) {
            return;
        }

        self::$routesRegistered = true;

        // Register SRU endpoint
        $app->get('/api/sru', function ($request, $response) use ($app) {
            $db = $app->getContainer()->get('db');
            $pluginManager = $app->getContainer()->get('pluginManager');
            $plugin = $pluginManager->getPluginByName('z39-server');
            $pluginId = ($plugin && isset($plugin['id'])) ? (int) $plugin['id'] : null;

            // Load endpoint handler
            $endpointFile = __DIR__ . '/endpoint.php';
            if (file_exists($endpointFile)) {
                require_once $endpointFile;
                return handleSRURequest($request, $response, $db, $pluginId);
            } else {
                // Fallback error response
                $response->getBody()->write('<?xml version="1.0"?><error>SRU endpoint not found</error>');
                return $response->withHeader('Content-Type', 'application/xml')->withStatus(500);
            }
        });

        // Register SBN search endpoint for catalog integration
        // Note: This endpoint does not support pagination - SBN API returns first page only
        // Rate limited to prevent abuse of external SBN API
        $app->get('/api/sbn/search', function ($request, $response) use ($app) {
            // Rate limiting - 60 requests per hour per IP
            $db = $app->getContainer()->get('db');
            require_once __DIR__ . '/classes/RateLimiter.php';
            $rateLimiter = new \Z39Server\RateLimiter($db, 60, 3600);

            // Secure IP extraction - trust X-Forwarded-For only from known proxies
            // Adjust $trustedProxies based on your infrastructure (load balancer IPs)
            $trustedProxies = ['127.0.0.1', '::1'];
            $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

            if (in_array($remoteAddr, $trustedProxies, true) && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                // When behind trusted proxy, use rightmost non-trusted IP in chain.
                // The rightmost entry is appended by the nearest trusted proxy and cannot
                // be spoofed by the client, unlike the leftmost entry.
                $forwardedIps = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
                $clientIp = $remoteAddr; // fallback
                foreach (array_reverse($forwardedIps) as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, $trustedProxies, true)) {
                        $clientIp = $ip;
                        break;
                    }
                }
            } else {
                $clientIp = $remoteAddr;
            }

            if (!$rateLimiter->checkLimit($clientIp)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Rate limit exceeded. Please try again later.'
                ]));
                return $response->withHeader('Content-Type', 'application/json')
                    ->withHeader('Retry-After', '3600')
                    ->withStatus(429);
            }

            $params = $request->getQueryParams();
            $query = trim($params['q'] ?? '');
            $type = $params['type'] ?? 'any'; // isbn, title, author, any
            $limit = min(20, max(1, (int)($params['limit'] ?? 10)));

            if (empty($query)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Query parameter "q" is required'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Validate search type
            $validTypes = ['isbn', 'title', 'author', 'any'];
            if (!in_array($type, $validTypes, true)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Invalid type parameter. Must be one of: ' . implode(', ', $validTypes)
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            // Load SBN Client
            $clientFile = __DIR__ . '/classes/SbnClient.php';
            if (!file_exists($clientFile)) {
                \App\Support\SecureLogger::error('[SBN Search API] Client file not found', ['path' => $clientFile]);
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'SBN client not available'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }

            require_once $clientFile;

            try {
                $client = new \Plugins\Z39Server\Classes\SbnClient(15, true);
                $results = [];

                // Search based on type
                if ($type === 'isbn' || ($type === 'any' && preg_match('/^[0-9X-]{10,17}$/i', $query))) {
                    // ISBN search - single result, no N+1 issue
                    $book = $client->searchByIsbn(preg_replace('/[^0-9X]/i', '', $query));
                    if ($book) {
                        // Get full record with locations (single request)
                        $bid = $book['_sbn_bid'] ?? null;
                        if ($bid) {
                            $fullRecord = $client->getFullRecord($bid);
                            if ($fullRecord && isset($fullRecord['localizzazioni'])) {
                                $book['locations'] = $fullRecord['localizzazioni'];
                            }
                        }
                        $results[] = $book;
                    }
                } elseif ($type === 'title' || $type === 'any') {
                    // Title search - use parallel fetching to eliminate N+1
                    $books = $client->searchByTitle($query, $limit);
                    // Enrich all books with full record data in parallel
                    $results = $client->enrichBooksParallel($books, true);
                } elseif ($type === 'author') {
                    // Author search - use parallel fetching to eliminate N+1
                    $books = $client->searchByAuthor($query, $limit);
                    // Enrich all books with full record data in parallel
                    $results = $client->enrichBooksParallel($books, true);
                }

                $response->getBody()->write(json_encode([
                    'success' => true,
                    'query' => $query,
                    'type' => $type,
                    'results' => $results,
                    'count' => count($results),
                    'limit' => $limit
                ], JSON_UNESCAPED_UNICODE));

                return $response->withHeader('Content-Type', 'application/json');

            } catch (\Throwable $e) {
                \App\Support\SecureLogger::error('[SBN Search API] Error', ['error' => $e->getMessage()]);
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Search failed. Please try again later.'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
        });

        // ── REICAT/SBN admin routes (issue #133) ───────────────────────────
        $plugin = $this;
        $adminMiddleware = new \App\Middleware\AdminAuthMiddleware();
        $csrfMiddleware  = new \App\Middleware\CsrfMiddleware();

        // POST /admin/books/import-sbn — fetch a record from SBN by ISBN or BID.
        $app->post('/admin/books/import-sbn', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->importSbnAction($request, $response);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // POST /admin/books/bulk-import-sbn — batch import from a CSV of ISBNs.
        $app->post('/admin/books/bulk-import-sbn', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->bulkImportSbnAction($request, $response);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // GET /admin/books/{id}/export.unimarc.xml — single-book UNIMARC export.
        $app->get('/admin/books/{id:[0-9]+}/export.unimarc.xml', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->exportUnimarcAction($request, $response, (int) $args['id']);
        })->add($adminMiddleware);

        // GET /admin/books/soggettario-search?q= — Nuovo Soggettario autocomplete.
        $app->get('/admin/books/soggettario-search', function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($plugin): ResponseInterface {
            return $plugin->soggettarioSearchAction($request, $response);
        })->add($adminMiddleware);

        // POST /admin/authors/{id}/lookup-ccn — SBN authority lookup for a name.
        $app->post('/admin/authors/{id:[0-9]+}/lookup-ccn', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->lookupCcnAction($request, $response, (int) $args['id']);
        })->add($csrfMiddleware)->add($adminMiddleware);

        // POST /admin/authors/{id}/apply-authority — persist the chosen form.
        $app->post('/admin/authors/{id:[0-9]+}/apply-authority', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($plugin): ResponseInterface {
            return $plugin->applyAuthorityAction($request, $response, (int) $args['id']);
        })->add($csrfMiddleware)->add($adminMiddleware);

        \App\Support\SecureLogger::debug('[Z39 Server Plugin] Routes registered', [
            'routes' => [
                '/api/sru', '/api/sbn/search',
                '/admin/books/import-sbn', '/admin/books/bulk-import-sbn',
                '/admin/books/{id}/export.unimarc.xml', '/admin/books/soggettario-search',
                '/admin/authors/{id}/lookup-ccn', '/admin/authors/{id}/apply-authority',
            ]
        ]);
    }

    /**
     * Hook: Fetch book metadata from Z39.50/SRU servers and SBN (Italian catalog)
     *
     * Uses intelligent merging to combine data from Z39.50/SBN with existing data
     * Hook: scrape.sources — Register SRU/SBN as scraping sources
     * so they appear in the "sources consulted" error messages.
     *
     * @param array $sources Current sources list
     * @param string $isbn ISBN being searched
     * @return array Updated sources list
     */
    public function addSruSources(array $sources, string $isbn): array
    {
        // SBN source
        if ($this->isSettingEnabled('enable_sbn', true)) {
            $sources['sbn'] = [
                'name' => 'SBN (OPAC Nazionale)',
                'priority' => 3,
                'fields' => ['title', 'author', 'publisher', 'year', 'isbn', 'dewey', 'language', 'pages']
            ];
        }

        // SRU servers
        $serversJson = $this->getSetting('servers', '[]');
        $servers = json_decode($serversJson, true);
        if (is_array($servers)) {
            foreach ($servers as $server) {
                if (!empty($server['enabled']) && !empty($server['name'])) {
                    $baseKey = preg_replace('/[^a-z0-9]+/', '_', strtolower($server['name']));
                    $urlHash = substr(md5($server['url'] ?? ''), 0, 6);
                    $key = 'sru_' . $baseKey . '_' . $urlHash;
                    $sources[$key] = [
                        'name' => $server['name'],
                        'priority' => 3,
                        'fields' => ['title', 'author', 'publisher', 'year', 'isbn', 'dewey', 'language', 'pages']
                    ];
                }
            }
        }

        return $sources;
    }

    /**
     * Hook: scrape.fetch.custom — Fetch book metadata via SBN and SRU servers
     *
     * from other sources, filling empty fields without overwriting existing data.
     *
     * Priority order:
     * 1. SBN (Italian National Library) - if enabled
     * 2. Configured SRU servers (K10plus, SUDOC, etc.)
     *
     * @param mixed $existing Previous accumulated result from other plugins
     * @param array $sources List of available sources
     * @param string $isbn ISBN to search for
     * @return array|null Merged book data or previous result if no new data
     */
    public function fetchBookMetadata($existing, $sources, $isbn): ?array
    {
        // $sources is required by hook signature but not used here
        unset($sources);

        \App\Support\SecureLogger::debug('[Z39] fetchBookMetadata called', ['isbn' => $isbn, 'has_existing' => $existing !== null]);

        // Check if client is enabled
        if (!$this->isSettingEnabled('enable_client')) {
            \App\Support\SecureLogger::warning('[Z39] SRU client is disabled', ['isbn' => $isbn]);
            return $existing; // Pass through existing data unchanged
        }

        $result = $existing;

        // Try SBN first (Italian National Library)
        $sbnEnabled = $this->isSettingEnabled('enable_sbn', true);
        \App\Support\SecureLogger::debug('[Z39] SBN status', ['isbn' => $isbn, 'sbn_enabled' => $sbnEnabled]);

        if ($sbnEnabled) {
            $sbnData = $this->fetchFromSbn($isbn);
            if ($sbnData) {
                \App\Support\SecureLogger::debug('[Z39] Book found via SBN', ['isbn' => $isbn, 'title' => $sbnData['title'] ?? '']);
                $result = $this->mergeBookData($result, $sbnData, 'sbn');
            } else {
                \App\Support\SecureLogger::warning('[Z39] SBN returned no data', ['isbn' => $isbn]);
            }
        }

        // Then try configured SRU servers
        $serversJson = $this->getSetting('servers', '[]');
        $servers = json_decode($serversJson, true);

        // Validate JSON decode result
        if ($servers === null && $serversJson !== 'null' && $serversJson !== '[]') {
            \App\Support\SecureLogger::error('[Z39] Invalid servers JSON', ['json_error' => json_last_error_msg()]);
            return $result;
        }

        if (!empty($servers) && is_array($servers)) {
            $z39Data = $this->fetchFromSru($isbn, $servers);
            if ($z39Data) {
                \App\Support\SecureLogger::debug('[Z39] Book found via SRU', ['isbn' => $isbn, 'title' => $z39Data['title'] ?? '']);
                $result = $this->mergeBookData($result, $z39Data, 'z39');
            }
        }

        return $result;
    }

    /**
     * Fetch book data from SBN (Italian National Library)
     *
     * @param string $isbn ISBN to search
     * @return array|null Book data or null
     */
    private function fetchFromSbn(string $isbn): ?array
    {
        $clientFile = __DIR__ . '/classes/SbnClient.php';
        if (!file_exists($clientFile)) {
            \App\Support\SecureLogger::error('[Z39] SBN client file not found', ['path' => $clientFile]);
            return null;
        }

        require_once $clientFile;

        try {
            $timeout = (int)$this->getSetting('sbn_timeout', '15');
            $client = new \Plugins\Z39Server\Classes\SbnClient($timeout, true);
            return $client->searchByIsbn($isbn);
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('[Z39] SBN client error', [
                'isbn' => $isbn,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return null;
        }
    }

    /**
     * Fetch book data from SRU servers
     *
     * @param string $isbn ISBN to search
     * @param array $servers Server configuration
     * @return array|null Book data or null
     */
    private function fetchFromSru(string $isbn, array $servers): ?array
    {
        $clientFile = __DIR__ . '/classes/SruClient.php';
        if (!file_exists($clientFile)) {
            \App\Support\SecureLogger::error('[Z39] SRU client file not found', ['path' => $clientFile]);
            return null;
        }

        require_once $clientFile;

        try {
            $client = new \Plugins\Z39Server\Classes\SruClient($servers);
            // Configure timeout to match SBN client behavior (CodeRabbit review)
            $timeout = (int)$this->getSetting('sru_timeout', '15');
            $client->setOptions(['timeout' => $timeout]);
            return $client->searchByIsbn($isbn);
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('[Z39] Error in SRU client', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Merge book data using BookDataMerger or simple merge
     *
     * @param array|null $existing Existing book data
     * @param array $new New book data
     * @param string $source Source identifier
     * @return array|null Merged data
     */
    private function mergeBookData(?array $existing, array $new, string $source): ?array
    {
        // Use BookDataMerger if available
        if (class_exists('\\App\\Support\\BookDataMerger')) {
            return \App\Support\BookDataMerger::merge($existing, $new, $source);
        }

        // Fallback: simple merge for empty fields only
        if ($existing === null) {
            return $new;
        }

        // FIX F083: restore || === null branch (aligns with comment "fill empty fields" + api-book-scraper / open-library)
        // Use array_key_exists so === null branch remains meaningful for PHPStan
        foreach ($new as $key => $value) {
            if (!array_key_exists($key, $existing) || $existing[$key] === '' || $existing[$key] === null) {
                $existing[$key] = $value;
            }
        }

        return $existing;
    }

    /**
     * Check if this plugin is currently active
     *
     * @return bool
     */
    private function isPluginActive(): bool
    {
        if ($this->pluginId === null) {
            return false;
        }

        $stmt = $this->db->prepare("
            SELECT is_active
            FROM plugins
            WHERE id = ?
        ");

        if ($stmt === false) {
            return false;
        }

        $stmt->bind_param('i', $this->pluginId);

        // Add error handling for execute() - may fail on connection issues
        if (!$stmt->execute()) {
            \App\Support\SecureLogger::error('[Z39 Server Plugin] Failed to check plugin status', ['error' => $stmt->error]);
            $stmt->close();
            return false;
        }

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row && (int)$row['is_active'] === 1;
    }

    /**
     * Get plugin setting
     *
     * Handles decryption of values encrypted by PluginManager.
     *
     * @param string $key Setting key
     * @param string $default Default value
     * @return string
     */
    private function getSetting(string $key, string $default = ''): string
    {
        if ($this->pluginId === null) {
            return $default;
        }

        $stmt = $this->db->prepare("
            SELECT setting_value
            FROM plugin_settings
            WHERE plugin_id = ? AND setting_key = ?
        ");

        if ($stmt === false) {
            return $default;
        }

        $stmt->bind_param('is', $this->pluginId, $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            return $default;
        }

        $value = $row['setting_value'];

        // Handle encrypted values (ENC: prefix from PluginManager)
        if (str_starts_with($value, 'ENC:')) {
            $value = $this->decryptSettingValue($value);
        }

        return $value ?? $default;
    }

    /**
     * Check if a boolean setting is enabled
     * Accepts '1', 'true' as enabled values
     *
     * @param string $key Setting key
     * @param bool $default Default value if setting not found
     * @return bool
     */
    private function isSettingEnabled(string $key, bool $default = false): bool
    {
        $value = $this->getSetting($key, $default ? '1' : '0');
        return in_array($value, ['1', 'true'], true);
    }

    /**
     * Decrypt a setting value encrypted by PluginManager
     *
     * @param string $encrypted Encrypted value with ENC: prefix
     * @return string|null Decrypted value or null on failure
     */
    private function decryptSettingValue(string $encrypted): ?string
    {
        // Validate ENC: prefix
        if (!str_starts_with($encrypted, 'ENC:')) {
            \App\Support\SecureLogger::error('[Z39 Server Plugin] Invalid encrypted value: missing ENC: prefix');
            return null;
        }

        // Remove ENC: prefix
        $payload = substr($encrypted, 4);
        /** @var string|false $decoded */
        $decoded = base64_decode($payload);

        if ($decoded === false || strlen($decoded) < 28) {
            \App\Support\SecureLogger::error('[Z39 Server Plugin] Invalid encrypted payload: decode failed or too short');
            return null;
        }

        // FIX F084: match PluginManager::getEncryptionKey() order — $_ENV first then getenv
        // (phpdotenv createImmutable populates $_ENV but not getenv by default)
        $envPluginKey = ($_ENV['PLUGIN_ENCRYPTION_KEY'] ?? '') ?: (getenv('PLUGIN_ENCRYPTION_KEY') ?: '');
        $envAppKey    = ($_ENV['APP_KEY'] ?? '') ?: (getenv('APP_KEY') ?: '');
        $rawKey = $envPluginKey !== '' ? $envPluginKey : ($envAppKey !== '' ? $envAppKey : null);
        if ($rawKey === null) {
            // No key available, cannot decrypt
            \App\Support\SecureLogger::error('[Z39 Server Plugin] Cannot decrypt setting: PLUGIN_ENCRYPTION_KEY not available');
            return null;
        }

        // Hash key exactly like PluginManager does
        $key = hash('sha256', (string)$rawKey, true);

        try {
            // Extract IV (12 bytes), tag (16 bytes), and ciphertext
            $iv = substr($decoded, 0, 12);
            $tag = substr($decoded, 12, 16);
            $ciphertext = substr($decoded, 28);

            $decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($decrypted === false) {
                \App\Support\SecureLogger::error('[Z39 Server Plugin] openssl_decrypt failed (bad key, tag, or ciphertext)');
                return null;
            }

            return $decrypted;
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('[Z39 Server Plugin] Decryption error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Set plugin setting
     *
     * @param string $key Setting key
     * @param string $value Value to save
     */
    private function setSetting(string $key, string $value): void
    {
        if ($this->pluginId === null) {
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO plugin_settings (plugin_id, setting_key, setting_value, created_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_at = NOW()
        ");

        if ($stmt === false) {
            return;
        }

        $stmt->bind_param('iss', $this->pluginId, $key, $value);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Log a message
     *
     * @param string $level Log level (info, warning, error, debug)
     * @param string $message Message
     * @param array $context Additional context data
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->pluginId === null) {
            return;
        }

        $contextJson = json_encode($context);

        $stmt = $this->db->prepare("
            INSERT INTO plugin_logs (plugin_id, level, message, context, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");

        if ($stmt === false) {
            return;
        }

        $stmt->bind_param('isss', $this->pluginId, $level, $message, $contextJson);
        $stmt->execute();
        $stmt->close();
    }

    // ========================================================================
    // REICAT/SBN cataloguing layer (issue #133)
    // ========================================================================

    /**
     * Lazy-load the REICAT/SBN helper classes (no PSR-4 autoload for plugins).
     */
    private function loadReicatClasses(): void
    {
        require_once __DIR__ . '/classes/SbnClient.php';
        require_once __DIR__ . '/classes/SbnAuthorityClient.php';
        require_once __DIR__ . '/classes/SoggettarioClient.php';
        require_once __DIR__ . '/classes/RecordFormatter.php';
        require_once __DIR__ . '/classes/UNIMARCXMLFormatter.php';
        require_once __DIR__ . '/classes/UnimarcLibriParser.php';
        require_once __DIR__ . '/classes/SoggettiRepository.php';
    }

    /**
     * Write a JSON body to a PSR-7 response.
     *
     * @param array<string,mixed> $payload
     */
    private function jsonResponse(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /**
     * POST /admin/books/import-sbn — fetch a record from SBN by ISBN.
     *
     * Returns the parsed book data for the form to pre-fill (copy cataloguing).
     * When `libro_id` is supplied (editing an existing book) the SBN BID, polo
     * and authority level are persisted immediately on the libri row.
     */
    public function importSbnAction(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->loadReicatClasses();
        $body = (array) $request->getParsedBody();
        $isbn = preg_replace('/[^0-9Xx]/', '', (string) ($body['isbn'] ?? '')) ?? '';
        $libroId = (int) ($body['libro_id'] ?? 0);

        if ($isbn === '') {
            return $this->jsonResponse($response, ['success' => false, 'error' => 'ISBN richiesto'], 400);
        }

        try {
            $timeout = (int) $this->getSetting('sbn_timeout', '15');
            $client = new \Plugins\Z39Server\Classes\SbnClient($timeout, true);
            $book = $client->searchByIsbn($isbn);
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('[Z39 import-sbn] error', ['error' => $e->getMessage()]);
            return $this->jsonResponse($response, ['success' => false, 'error' => 'Errore durante la ricerca SBN'], 502);
        }

        if (!is_array($book)) {
            return $this->jsonResponse($response, ['success' => false, 'error' => 'Nessun record trovato su SBN per questo ISBN'], 404);
        }

        $bid = (string) ($book['_sbn_bid'] ?? '');
        $polo = $this->poloFromBid($bid);

        if ($libroId > 0 && $bid !== '') {
            $this->persistSbnIdentifiers($libroId, $bid, $polo, null);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'book'    => $book,
            'sbn_bid' => $bid,
            'sbn_polo' => $polo,
        ]);
    }

    /**
     * POST /admin/books/bulk-import-sbn — fetch metadata for a CSV/list of ISBNs.
     *
     * Returns a per-ISBN result set for review; it does not auto-create books
     * (creation goes through the standard catalogue validation flow).
     */
    public function bulkImportSbnAction(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->loadReicatClasses();
        $body = (array) $request->getParsedBody();

        $raw = (string) ($body['isbns'] ?? '');
        // Accept an uploaded CSV file as well.
        $files = $request->getUploadedFiles();
        if (isset($files['csv']) && $files['csv'] instanceof \Psr\Http\Message\UploadedFileInterface
            && $files['csv']->getError() === UPLOAD_ERR_OK) {
            $raw .= "\n" . (string) $files['csv']->getStream();
        }

        // Split on any non-ISBN separator, keep 10/13-digit tokens.
        $tokens = preg_split('/[^0-9Xx]+/', $raw) ?: [];
        $isbns = [];
        foreach ($tokens as $t) {
            $t = strtoupper(trim($t));
            if ($t !== '' && (strlen($t) === 10 || strlen($t) === 13)) {
                $isbns[$t] = true; // dedup
            }
        }
        $isbns = array_keys($isbns);
        if ($isbns === []) {
            return $this->jsonResponse($response, ['success' => false, 'error' => 'Nessun ISBN valido fornito'], 400);
        }
        $isbns = array_slice($isbns, 0, 50); // cap to protect the external API

        $timeout = (int) $this->getSetting('sbn_timeout', '15');
        $results = [];
        $found = 0;
        try {
            $client = new \Plugins\Z39Server\Classes\SbnClient($timeout, true);
            foreach ($isbns as $isbn) {
                $book = $client->searchByIsbn($isbn);
                $ok = is_array($book);
                if ($ok) {
                    $found++;
                }
                $results[] = [
                    'isbn'    => $isbn,
                    'found'   => $ok,
                    'title'   => $ok ? (string) ($book['title'] ?? '') : null,
                    'author'  => $ok ? (string) ($book['author'] ?? '') : null,
                    'year'    => $ok ? ($book['year'] ?? null) : null,
                    'sbn_bid' => $ok ? (string) ($book['_sbn_bid'] ?? '') : null,
                ];
            }
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('[Z39 bulk-import-sbn] error', ['error' => $e->getMessage()]);
            return $this->jsonResponse($response, ['success' => false, 'error' => 'Errore durante l\'import batch'], 502);
        }

        return $this->jsonResponse($response, [
            'success'   => true,
            'requested' => count($isbns),
            'found'     => $found,
            'results'   => $results,
        ]);
    }

    /**
     * GET /admin/books/{id}/export.unimarc.xml — UNIMARC (MARCXchange) export.
     */
    public function exportUnimarcAction(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        unset($request);
        $this->loadReicatClasses();

        $record = $this->buildUnimarcRecord($id);
        if ($record === null) {
            $response->getBody()->write('<?xml version="1.0" encoding="UTF-8"?><error>Book not found</error>');
            return $response->withHeader('Content-Type', 'application/xml')->withStatus(404);
        }

        $repo = new \Z39Server\SoggettiRepository($this->db);
        $subjects = array_map(
            static fn(array $s): array => ['termine' => $s['termine'], 'bncf_id' => $s['bncf_id']],
            $repo->listForBook($id)
        );

        $parser = new \Z39Server\UnimarcLibriParser();
        $xml = $parser->toUnimarcXml($record, $subjects);

        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="libro-' . $id . '.unimarc.xml"');
    }

    /**
     * GET /admin/books/soggettario-search?q= — Nuovo Soggettario autocomplete.
     */
    public function soggettarioSearchAction(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->loadReicatClasses();
        $params = $request->getQueryParams();
        $q = trim((string) ($params['q'] ?? ''));
        $mode = (string) ($params['mode'] ?? 'cominciaper');

        if (mb_strlen($q) < 2) {
            return $this->jsonResponse($response, ['success' => true, 'results' => []]);
        }

        $client = new \Z39Server\SoggettarioClient();
        $results = $client->search($q, $mode, 20);
        return $this->jsonResponse($response, ['success' => true, 'results' => $results]);
    }

    /**
     * POST /admin/authors/{id}/lookup-ccn — SBN authority candidates for a name.
     */
    public function lookupCcnAction(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $this->loadReicatClasses();
        $body = (array) $request->getParsedBody();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            $name = $this->authorName($id);
        }
        if ($name === '') {
            return $this->jsonResponse($response, ['success' => false, 'error' => 'Nome autore non disponibile'], 400);
        }

        try {
            $timeout = (int) $this->getSetting('sbn_timeout', '15');
            $auth = new \Z39Server\SbnAuthorityClient(new \Plugins\Z39Server\Classes\SbnClient($timeout, true));
            $candidates = $auth->lookupByName($name, 6);
        } catch (\Throwable $e) {
            \App\Support\SecureLogger::error('[Z39 lookup-ccn] error', ['error' => $e->getMessage()]);
            return $this->jsonResponse($response, ['success' => false, 'error' => 'Errore durante il lookup authority'], 502);
        }

        return $this->jsonResponse($response, [
            'success'    => true,
            'name'       => $name,
            'candidates' => $candidates,
        ]);
    }

    /**
     * POST /admin/authors/{id}/apply-authority — persist the chosen authorized form.
     */
    public function applyAuthorityAction(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $form  = trim((string) ($body['authorized_form'] ?? ''));
        $dates = trim((string) ($body['qualifier_dates'] ?? ''));
        $role  = trim((string) ($body['qualifier_role'] ?? ''));
        $ccn   = trim((string) ($body['ccn'] ?? ''));

        if ($form === '') {
            return $this->jsonResponse($response, ['success' => false, 'error' => 'Forma autorizzata richiesta'], 400);
        }
        if ($this->authorName($id) === '') {
            return $this->jsonResponse($response, ['success' => false, 'error' => 'Autore inesistente'], 404);
        }

        $stmt = $this->db->prepare(
            'UPDATE autori SET sbn_authorized_form = ?, qualifier_dates = ?, qualifier_role = ?, ccn = ?, authority_source = ?
             WHERE id = ?'
        );
        if ($stmt === false) {
            return $this->jsonResponse($response, ['success' => false, 'error' => 'DB error'], 500);
        }
        $datesParam = $dates !== '' ? $dates : null;
        $roleParam  = $role !== '' ? $role : null;
        $ccnParam   = $ccn !== '' ? $ccn : null;
        $source     = 'SBN';
        $stmt->bind_param('sssssi', $form, $datesParam, $roleParam, $ccnParam, $source, $id);
        $stmt->execute();
        $stmt->close();

        return $this->jsonResponse($response, ['success' => true]);
    }

    /**
     * Hook: book.save.after — persist the REICAT/SBN fields + subjects that the
     * core LibriController does not know about, read from the raw POST.
     *
     * @param array<string,mixed> $fields Whitelisted core fields (unused here)
     */
    public function persistReicatBookFields(int $bookId, array $fields): void
    {
        unset($fields);
        if ($bookId <= 0) {
            return;
        }

        $bid   = isset($_POST['sbn_bid']) ? trim((string) $_POST['sbn_bid']) : '';
        $level = isset($_POST['sbn_authority_level']) ? trim((string) $_POST['sbn_authority_level']) : '';
        $polo  = isset($_POST['sbn_polo']) ? trim((string) $_POST['sbn_polo']) : '';
        if ($polo === '' && $bid !== '') {
            $polo = $this->poloFromBid($bid);
        }

        // Only touch the columns when at least one REICAT input was submitted,
        // so saving an unrelated book never nulls these fields.
        if (array_key_exists('sbn_bid', $_POST) || array_key_exists('sbn_authority_level', $_POST) || array_key_exists('sbn_polo', $_POST)) {
            $this->persistSbnIdentifiers(
                $bookId,
                $bid !== '' ? $bid : null,
                $polo !== '' ? $polo : null,
                in_array($level, ['95', '51'], true) ? $level : null
            );
        }

        // Subjects: a JSON array of {termine, bncf_id?, uri?} from the picker.
        if (array_key_exists('reicat_soggetti', $_POST)) {
            $this->loadReicatClasses();
            $decoded = json_decode((string) $_POST['reicat_soggetti'], true);
            $subjects = [];
            if (is_array($decoded)) {
                foreach ($decoded as $row) {
                    if (is_array($row) && isset($row['termine']) && trim((string) $row['termine']) !== '') {
                        $subjects[] = [
                            'termine' => (string) $row['termine'],
                            'bncf_id' => isset($row['bncf_id']) ? (string) $row['bncf_id'] : null,
                            'uri'     => isset($row['uri']) ? (string) $row['uri'] : null,
                        ];
                    }
                }
            }
            (new \Z39Server\SoggettiRepository($this->db))->syncBookSubjects($bookId, $subjects);
        }
    }

    /**
     * Hook: book.form.fields — render the REICAT/SBN tab in the book form.
     */
    public function renderReicatBookFields(?array $book, ?int $bookId): void
    {
        $reicat = ['sbn_bid' => '', 'sbn_authority_level' => '', 'sbn_polo' => '', 'soggetti' => []];
        $id = $bookId ?? (is_array($book) ? (int) ($book['id'] ?? 0) : 0);
        if ($id > 0) {
            $reicat = $this->getReicatBookData($id);
        }
        $csrf = \App\Support\Csrf::ensureToken();
        include __DIR__ . '/views/reicat/book-fields.php';
    }

    /**
     * Hook: author.form.fields — render the SBN authority panel in the author form.
     */
    public function renderReicatAuthorFields(?array $autore): void
    {
        $authorId = is_array($autore) ? (int) ($autore['id'] ?? 0) : 0;
        $authorName = is_array($autore) ? (string) ($autore['nome'] ?? '') : '';
        $authData = [
            'ccn'                 => is_array($autore) ? (string) ($autore['ccn'] ?? '') : '',
            'sbn_authorized_form' => is_array($autore) ? (string) ($autore['sbn_authorized_form'] ?? '') : '',
            'qualifier_dates'     => is_array($autore) ? (string) ($autore['qualifier_dates'] ?? '') : '',
            'qualifier_role'      => is_array($autore) ? (string) ($autore['qualifier_role'] ?? '') : '',
        ];
        $csrf = \App\Support\Csrf::ensureToken();
        include __DIR__ . '/views/reicat/author-fields.php';
    }

    // ── REICAT helpers ──────────────────────────────────────────────────────

    /**
     * Persist SBN identifiers on a libri row. Null arguments leave the
     * corresponding column untouched (COALESCE keeps the existing value).
     */
    private function persistSbnIdentifiers(int $bookId, ?string $bid, ?string $polo, ?string $level): void
    {
        $stmt = $this->db->prepare(
            'UPDATE libri
             SET sbn_bid = COALESCE(?, sbn_bid),
                 sbn_polo = COALESCE(?, sbn_polo),
                 sbn_authority_level = COALESCE(?, sbn_authority_level)
             WHERE id = ? AND deleted_at IS NULL'
        );
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('sssi', $bid, $polo, $level, $bookId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Extract the SBN polo code (3rd segment) from a BID like
     * "IT\ICCU\RMB\0769708" → "RMB".
     */
    private function poloFromBid(string $bid): string
    {
        $parts = preg_split('/[\\\\\/]/', $bid) ?: [];
        return isset($parts[2]) ? trim((string) $parts[2]) : '';
    }

    private function authorName(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        $stmt = $this->db->prepare('SELECT nome FROM autori WHERE id = ? LIMIT 1');
        if ($stmt === false) {
            return '';
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? trim((string) ($row['nome'] ?? '')) : '';
    }

    /**
     * @return array{sbn_bid:string,sbn_authority_level:string,sbn_polo:string,soggetti:list<array{id:int,termine:string,bncf_id:?string,uri:?string,schema:string}>}
     */
    private function getReicatBookData(int $id): array
    {
        $data = ['sbn_bid' => '', 'sbn_authority_level' => '', 'sbn_polo' => '', 'soggetti' => []];
        $stmt = $this->db->prepare('SELECT sbn_bid, sbn_authority_level, sbn_polo FROM libri WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        if ($stmt !== false) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (is_array($row)) {
                $data['sbn_bid'] = (string) ($row['sbn_bid'] ?? '');
                $data['sbn_authority_level'] = (string) ($row['sbn_authority_level'] ?? '');
                $data['sbn_polo'] = (string) ($row['sbn_polo'] ?? '');
            }
        }
        $this->loadReicatClasses();
        $data['soggetti'] = (new \Z39Server\SoggettiRepository($this->db))->listForBook($id);
        return $data;
    }

    /**
     * Build the record array consumed by UNIMARCXMLFormatter from a libri row.
     *
     * @return array<string,mixed>|null
     */
    private function buildUnimarcRecord(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT l.id, l.titolo, l.sottotitolo, l.anno_pubblicazione, l.lingua, l.edizione,
                    l.numero_pagine, l.isbn13, l.isbn10, l.collana, l.numero_serie,
                    l.descrizione_plain, l.parole_chiave, l.classificazione_dewey,
                    e.nome AS editore,
                    (SELECT GROUP_CONCAT(a.nome SEPARATOR '; ')
                       FROM libri_autori la JOIN autori a ON a.id = la.autore_id
                      WHERE la.libro_id = l.id
                        AND la.ruolo IN ('principale', 'co-autore')) AS autori
             FROM libri l
             LEFT JOIN editori e ON e.id = l.editore_id
             WHERE l.id = ? AND l.deleted_at IS NULL
             LIMIT 1"
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }

        return [
            'id'                  => (int) $row['id'],
            'titolo'              => (string) ($row['titolo'] ?? ''),
            'sottotitolo'         => (string) ($row['sottotitolo'] ?? ''),
            'anno_pubblicazione'  => (string) ($row['anno_pubblicazione'] ?? ''),
            'lingua'              => (string) ($row['lingua'] ?? ''),
            'edizione'            => (string) ($row['edizione'] ?? ''),
            'numero_pagine'       => (string) ($row['numero_pagine'] ?? ''),
            'isbn13'              => (string) ($row['isbn13'] ?? ''),
            'isbn10'              => (string) ($row['isbn10'] ?? ''),
            'collana'             => (string) ($row['collana'] ?? ''),
            'numero_serie'        => (string) ($row['numero_serie'] ?? ''),
            'descrizione'         => (string) ($row['descrizione_plain'] ?? ''),
            'parole_chiave'       => (string) ($row['parole_chiave'] ?? ''),
            'classificazione_dewey' => (string) ($row['classificazione_dewey'] ?? ''),
            'editore'             => (string) ($row['editore'] ?? ''),
            'autori'              => (string) ($row['autori'] ?? ''),
        ];
    }
}

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

class Z39ServerPlugin
{
    private mysqli $db;
    private HookManager $hookManager;
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
        'supported_formats' => 'marcxml,dc,mods,oai_dc',
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

    /**
     * Constructor - Initialize when plugin is loaded
     *
     * @param mysqli $db Database connection
     * @param HookManager $hookManager Hook manager instance
     */
    public function __construct(mysqli $db, HookManager $hookManager)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;

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
     * Hook: Executed during plugin installation
     * Creates necessary tables and sets up initial configuration
     */
    public function onInstall(): void
    {
        // Create table for SRU access logs
        $this->db->query("
            CREATE TABLE IF NOT EXISTS z39_access_logs (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create table for rate limiting
        $this->db->query("
            CREATE TABLE IF NOT EXISTS z39_rate_limits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                request_count INT DEFAULT 1,
                window_start DATETIME NOT NULL,
                last_request DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_ip_window (ip_address, window_start),
                INDEX idx_window (window_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Set default settings
        foreach (self::DEFAULT_SETTINGS as $key => $value) {
            $this->setSetting($key, $value);
        }

        // Log installation
        $this->log('info', 'Z39.50/SRU Server Plugin installed successfully', [
            'tables_created' => ['z39_access_logs', 'z39_rate_limits'],
            'default_settings' => count(self::DEFAULT_SETTINGS)
        ]);
    }

    /**
     * Hook: Executed when plugin is activated
     * Registers hooks and starts the SRU server
     */
    public function onActivate(): void
    {
        // Register hooks
        $this->registerHooks();

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
            // Add admin menu item
            [
                'hook_name' => 'admin.menu.items',
                'callback_method' => 'addAdminMenuItem',
                'priority' => 10
            ],
            // Register SRU Client for scraping (priority 3 = after Scraping Pro and API Book Scraper)
            // Z39.50/SRU provides excellent metadata but NO covers
            [
                'hook_name' => 'scrape.fetch.custom',
                'callback_method' => 'fetchBookMetadata',
                'priority' => 3
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
                error_log('[Z39 Server Plugin] Failed to prepare hook registration statement: ' . $this->db->error);
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
                error_log('[Z39 Server Plugin] Failed to register hook ' . $hook['hook_name'] . ': ' . $stmt->error);
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
                // When behind trusted proxy, use last IP in chain (real client)
                $forwardedIps = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
                $clientIp = end($forwardedIps);
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
                error_log('[SBN Search API] Client file not found: ' . $clientFile);
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
                error_log('[SBN Search API] Error: ' . $e->getMessage());
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Search failed: ' . $e->getMessage()
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
        });

        error_log('[Z39 Server Plugin] SRU route registered at /api/sru');
        error_log('[Z39 Server Plugin] SBN search route registered at /api/sbn/search');
    }

    /**
     * Add menu item to admin panel
     */
    public function addAdminMenuItem(array $menuItems): array
    {
        $menuItems[] = [
            'title' => 'Z39.50/SRU Server',
            'url' => '/admin/plugins/z39-server/settings',
            'icon' => 'fa-server',
            'section' => 'plugins'
        ];

        return $menuItems;
    }

    /**
     * Hook: Fetch book metadata from Z39.50/SRU servers and SBN (Italian catalog)
     *
     * Uses intelligent merging to combine data from Z39.50/SBN with existing data
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
            $this->log('error', 'SRU client file not found', ['path' => $clientFile]);
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
            $this->log('error', 'Error in SRU client', ['error' => $e->getMessage()]);
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

        foreach ($new as $key => $value) {
            if (!isset($existing[$key]) || $existing[$key] === '' || $existing[$key] === null) {
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
            error_log('[Z39 Server Plugin] Failed to check plugin status: ' . $stmt->error);
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

        $stmt->bind_param('is', $this->pluginId, $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
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
            error_log('[Z39 Server Plugin] Invalid encrypted value: missing ENC: prefix');
            return null;
        }

        // Remove ENC: prefix
        $payload = substr($encrypted, 4);
        $decoded = base64_decode($payload);

        if ($decoded === false || strlen($decoded) < 28) {
            error_log('[Z39 Server Plugin] Invalid encrypted payload: decode failed or too short');
            return null;
        }

        // Get encryption key from environment (same order as PluginManager)
        $rawKey = $_ENV['PLUGIN_ENCRYPTION_KEY']
            ?? getenv('PLUGIN_ENCRYPTION_KEY')
            ?? $_ENV['APP_KEY']
            ?? getenv('APP_KEY')
            ?? null;
        if ($rawKey === null || $rawKey === '') {
            // No key available, cannot decrypt
            error_log('[Z39 Server Plugin] Cannot decrypt setting: PLUGIN_ENCRYPTION_KEY not available');
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

            return $decrypted !== false ? $decrypted : null;
        } catch (\Throwable $e) {
            error_log('[Z39 Server Plugin] Decryption error: ' . $e->getMessage());
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

        $stmt->bind_param('isss', $this->pluginId, $level, $message, $contextJson);
        $stmt->execute();
        $stmt->close();
    }
}

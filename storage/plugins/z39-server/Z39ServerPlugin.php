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
use mysqli;

class Z39ServerPlugin
{
    private mysqli $db;
    private HookManager $hookManager;
    private ?int $pluginId = null;

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
        'sru_version' => '1.2'
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
            $this->pluginId = (int)$row['id'];
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
            'server_enabled' => $this->getSetting('server_enabled') === 'true',
            'supported_formats' => $this->getSetting('supported_formats')
        ]);
    }

    /**
     * Hook: Executed when plugin is deactivated
     */
    public function onDeactivate(): void
    {
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
            // Register API endpoint for SRU server
            [
                'hook_name' => 'api.routes.register',
                'callback_method' => 'registerSRUEndpoint',
                'priority' => 10
            ],
            // Add admin menu item
            [
                'hook_name' => 'admin.menu.items',
                'callback_method' => 'addAdminMenuItem',
                'priority' => 10
            ]
        ];

        foreach ($hooks as $hook) {
            $stmt = $this->db->prepare("
                INSERT INTO plugin_hooks (plugin_id, hook_name, callback_method, priority, is_active)
                VALUES (?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE
                    callback_method = VALUES(callback_method),
                    priority = VALUES(priority),
                    is_active = 1
            ");

            $stmt->bind_param(
                'issi',
                $this->pluginId,
                $hook['hook_name'],
                $hook['callback_method'],
                $hook['priority']
            );

            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Register SRU API endpoint
     */
    public function registerSRUEndpoint(): void
    {
        // This hook will be called to register custom routes
        // The actual endpoint will be created in a separate controller file
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
     * Get plugin setting
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

        return $row ? $row['setting_value'] : $default;
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

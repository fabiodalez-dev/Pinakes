<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;
use ZipArchive;

/**
 * Plugin Manager
 *
 * Core class for managing plugins: installation, activation, deactivation, and uninstallation.
 * Provides safe plugin lifecycle management with validation and error handling.
 */
class PluginManager
{
    private mysqli $db;
    private string $pluginsDir;
    private string $uploadsDir;
    private HookManager $hookManager;

    public function __construct(mysqli $db, HookManager $hookManager)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;
        $this->pluginsDir = __DIR__ . '/../../storage/plugins';
        $this->uploadsDir = __DIR__ . '/../../uploads/plugins';

        // Ensure directories exist
        if (!is_dir($this->pluginsDir)) {
            mkdir($this->pluginsDir, 0755, true);
        }
        if (!is_dir($this->uploadsDir)) {
            mkdir($this->uploadsDir, 0755, true);
        }
    }

    /**
     * Get all installed plugins
     *
     * @return array
     */
    public function getAllPlugins(): array
    {
        $query = "SELECT * FROM plugins ORDER BY display_name ASC";
        $result = $this->db->query($query);

        $plugins = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $row['metadata'] = $row['metadata'] ? json_decode($row['metadata'], true) : [];
                $plugins[] = $row;
            }
            $result->free();
        }

        return $plugins;
    }

    /**
     * Get active plugins only
     *
     * @return array
     */
    public function getActivePlugins(): array
    {
        $query = "SELECT * FROM plugins WHERE is_active = 1 ORDER BY display_name ASC";
        $result = $this->db->query($query);

        $plugins = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $row['metadata'] = $row['metadata'] ? json_decode($row['metadata'], true) : [];
                $plugins[] = $row;
            }
            $result->free();
        }

        return $plugins;
    }

    /**
     * Get plugin by ID
     *
     * @param int $pluginId
     * @return array|null
     */
    public function getPlugin(int $pluginId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM plugins WHERE id = ?");
        $stmt->bind_param('i', $pluginId);
        $stmt->execute();
        $result = $stmt->get_result();
        $plugin = $result->fetch_assoc();
        $stmt->close();

        if ($plugin) {
            $plugin['metadata'] = $plugin['metadata'] ? json_decode($plugin['metadata'], true) : [];
        }

        return $plugin ?: null;
    }

    /**
     * Get plugin by name
     *
     * @param string $name
     * @return array|null
     */
    public function getPluginByName(string $name): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM plugins WHERE name = ?");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $result = $stmt->get_result();
        $plugin = $result->fetch_assoc();
        $stmt->close();

        if ($plugin) {
            $plugin['metadata'] = $plugin['metadata'] ? json_decode($plugin['metadata'], true) : [];
        }

        return $plugin ?: null;
    }

    /**
     * Install plugin from uploaded ZIP file
     *
     * @param string $zipPath Path to uploaded ZIP file
     * @return array ['success' => bool, 'message' => string, 'plugin_id' => int|null]
     */
    public function installFromZip(string $zipPath): array
    {
        try {
            error_log("🔌 [PluginManager] Starting plugin installation from: $zipPath");

            // Validate ZIP file
            if (!file_exists($zipPath)) {
                error_log("❌ [PluginManager] ZIP file not found: $zipPath");
                return ['success' => false, 'message' => 'File ZIP non trovato.', 'plugin_id' => null];
            }

        $zip = new ZipArchive();
        $zipResult = $zip->open($zipPath);

        if ($zipResult !== true) {
            return ['success' => false, 'message' => 'Impossibile aprire il file ZIP.', 'plugin_id' => null];
        }

        // Look for plugin.json in root or first directory
        $pluginJsonPath = null;
        $pluginRootDir = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            if (basename($filename) === 'plugin.json') {
                $pluginJsonPath = $filename;
                $pluginRootDir = dirname($filename);
                if ($pluginRootDir === '.') {
                    $pluginRootDir = '';
                }
                break;
            }
        }

        if (!$pluginJsonPath) {
            $zip->close();
            return ['success' => false, 'message' => 'File plugin.json non trovato nel pacchetto.', 'plugin_id' => null];
        }

        // Read and validate plugin.json
        $pluginJsonContent = $zip->getFromName($pluginJsonPath);
        $pluginMeta = json_decode($pluginJsonContent, true);

        if (!$pluginMeta) {
            $zip->close();
            return ['success' => false, 'message' => 'File plugin.json non valido.', 'plugin_id' => null];
        }

        // Validate required fields
        $requiredFields = ['name', 'display_name', 'version', 'main_file'];
        foreach ($requiredFields as $field) {
            if (empty($pluginMeta[$field])) {
                $zip->close();
                return ['success' => false, 'message' => "Campo obbligatorio mancante: {$field}", 'plugin_id' => null];
            }
        }

        // Check if plugin already exists
        $existingPlugin = $this->getPluginByName($pluginMeta['name']);
        if ($existingPlugin) {
            $zip->close();
            return ['success' => false, 'message' => 'Plugin già installato.', 'plugin_id' => null];
        }

        // Check PHP version compatibility
        if (!empty($pluginMeta['requires_php'])) {
            if (version_compare(PHP_VERSION, $pluginMeta['requires_php'], '<')) {
                $zip->close();
                return [
                    'success' => false,
                    'message' => "Plugin richiede PHP {$pluginMeta['requires_php']} o superiore.",
                    'plugin_id' => null
                ];
            }
        }

        // Extract plugin to storage/plugins directory
        $pluginPath = $this->pluginsDir . '/' . $pluginMeta['name'];

        if (is_dir($pluginPath)) {
            $zip->close();
            return ['success' => false, 'message' => 'Directory plugin già esistente.', 'plugin_id' => null];
        }

        // Extract files
        if ($pluginRootDir) {
            // Plugin files are in a subdirectory, extract only that subdirectory
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (strpos($filename, $pluginRootDir . '/') === 0) {
                    $relativePath = substr($filename, strlen($pluginRootDir) + 1);
                    if ($relativePath) {
                        $targetPath = $pluginPath . '/' . $relativePath;
                        if (substr($filename, -1) === '/') {
                            // Directory
                            if (!is_dir($targetPath)) {
                                mkdir($targetPath, 0755, true);
                            }
                        } else {
                            // File
                            $dir = dirname($targetPath);
                            if (!is_dir($dir)) {
                                mkdir($dir, 0755, true);
                            }
                            $content = $zip->getFromIndex($i);
                            file_put_contents($targetPath, $content);
                        }
                    }
                }
            }
        } else {
            // Plugin files are in root, extract all
            $zip->extractTo($pluginPath);
        }

        $zip->close();

        // Verify main file exists
        $mainFilePath = $pluginPath . '/' . $pluginMeta['main_file'];
        if (!file_exists($mainFilePath)) {
            $this->deleteDirectory($pluginPath);
            return ['success' => false, 'message' => 'File principale del plugin non trovato.', 'plugin_id' => null];
        }

        // Insert plugin into database
        $stmt = $this->db->prepare("
            INSERT INTO plugins (
                name, display_name, description, version, author, author_url, plugin_url,
                is_active, path, main_file, requires_php, requires_app, metadata, installed_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, NOW())
        ");

        $metadata = json_encode($pluginMeta['metadata'] ?? []);

        // Prepare values with defaults for optional fields
        $name = $pluginMeta['name'];
        $displayName = $pluginMeta['display_name'];
        $description = $pluginMeta['description'] ?? '';
        $version = $pluginMeta['version'];
        $author = $pluginMeta['author'] ?? '';
        $authorUrl = $pluginMeta['author_url'] ?? '';
        $pluginUrl = $pluginMeta['plugin_url'] ?? '';
        $path = $pluginMeta['name'];
        $mainFile = $pluginMeta['main_file'];
        $requiresPhp = $pluginMeta['requires_php'] ?? '';
        $requiresApp = $pluginMeta['requires_app'] ?? '';

        $stmt->bind_param(
            'ssssssssssss',
            $name,
            $displayName,
            $description,
            $version,
            $author,
            $authorUrl,
            $pluginUrl,
            $path,
            $mainFile,
            $requiresPhp,
            $requiresApp,
            $metadata
        );

        $result = $stmt->execute();
        $pluginId = $this->db->insert_id;
        $stmt->close();

        if (!$result) {
            $this->deleteDirectory($pluginPath);
            return ['success' => false, 'message' => 'Errore durante il salvataggio nel database.', 'plugin_id' => null];
        }

            // Run plugin installation hook if exists
            $this->runPluginMethod($pluginMeta['name'], 'onInstall');

            error_log("✅ [PluginManager] Plugin installed successfully: {$pluginMeta['name']} (ID: $pluginId)");

            return [
                'success' => true,
                'message' => 'Plugin installato con successo.',
                'plugin_id' => $pluginId
            ];
        } catch (Exception $e) {
            error_log("❌ [PluginManager] Installation error: " . $e->getMessage());
            error_log("❌ [PluginManager] Stack trace: " . $e->getTraceAsString());
            return [
                'success' => false,
                'message' => 'Errore durante l\'installazione: ' . $e->getMessage(),
                'plugin_id' => null
            ];
        }
    }

    /**
     * Activate a plugin
     *
     * @param int $pluginId
     * @return array ['success' => bool, 'message' => string]
     */
    public function activatePlugin(int $pluginId): array
    {
        $plugin = $this->getPlugin($pluginId);

        if (!$plugin) {
            return ['success' => false, 'message' => 'Plugin non trovato.'];
        }

        if ($plugin['is_active']) {
            return ['success' => false, 'message' => 'Plugin già attivo.'];
        }

        // Load plugin main file
        $pluginPath = $this->pluginsDir . '/' . $plugin['path'];
        $mainFile = $pluginPath . '/' . $plugin['main_file'];

        if (!file_exists($mainFile)) {
            return ['success' => false, 'message' => 'File principale del plugin non trovato.'];
        }

        // Run plugin activation hook
        try {
            $this->runPluginMethod($plugin['name'], 'onActivate');
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Errore durante l\'attivazione: ' . $e->getMessage()];
        }

        // Update database
        $stmt = $this->db->prepare("UPDATE plugins SET is_active = 1, activated_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $pluginId);
        $result = $stmt->execute();
        $stmt->close();

        if (!$result) {
            return ['success' => false, 'message' => 'Errore durante l\'attivazione del plugin.'];
        }

        return ['success' => true, 'message' => 'Plugin attivato con successo.'];
    }

    /**
     * Deactivate a plugin
     *
     * @param int $pluginId
     * @return array ['success' => bool, 'message' => string]
     */
    public function deactivatePlugin(int $pluginId): array
    {
        $plugin = $this->getPlugin($pluginId);

        if (!$plugin) {
            return ['success' => false, 'message' => 'Plugin non trovato.'];
        }

        if (!$plugin['is_active']) {
            return ['success' => false, 'message' => 'Plugin già disattivato.'];
        }

        // Run plugin deactivation hook
        try {
            $this->runPluginMethod($plugin['name'], 'onDeactivate');
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Errore durante la disattivazione: ' . $e->getMessage()];
        }

        // Update database
        $stmt = $this->db->prepare("UPDATE plugins SET is_active = 0, activated_at = NULL WHERE id = ?");
        $stmt->bind_param('i', $pluginId);
        $result = $stmt->execute();
        $stmt->close();

        if (!$result) {
            return ['success' => false, 'message' => 'Errore durante la disattivazione del plugin.'];
        }

        return ['success' => true, 'message' => 'Plugin disattivato con successo.'];
    }

    /**
     * Uninstall a plugin (delete from database and filesystem)
     *
     * @param int $pluginId
     * @return array ['success' => bool, 'message' => string]
     */
    public function uninstallPlugin(int $pluginId): array
    {
        $plugin = $this->getPlugin($pluginId);

        if (!$plugin) {
            return ['success' => false, 'message' => 'Plugin non trovato.'];
        }

        // Deactivate first if active
        if ($plugin['is_active']) {
            $deactivateResult = $this->deactivatePlugin($pluginId);
            if (!$deactivateResult['success']) {
                return $deactivateResult;
            }
        }

        // Run plugin uninstall hook
        try {
            $this->runPluginMethod($plugin['name'], 'onUninstall');
        } catch (\Throwable $e) {
            // Continue with uninstall even if hook fails
            error_log("Plugin uninstall hook error: " . $e->getMessage());
        }

        // Delete from database (cascade will delete hooks, settings, data, logs)
        $stmt = $this->db->prepare("DELETE FROM plugins WHERE id = ?");
        $stmt->bind_param('i', $pluginId);
        $result = $stmt->execute();
        $stmt->close();

        if (!$result) {
            return ['success' => false, 'message' => 'Errore durante la rimozione dal database.'];
        }

        // Delete plugin directory
        $pluginPath = $this->pluginsDir . '/' . $plugin['path'];
        if (is_dir($pluginPath)) {
            $this->deleteDirectory($pluginPath);
        }

        return ['success' => true, 'message' => 'Plugin disinstallato con successo.'];
    }

    /**
     * Run a plugin method if it exists
     *
     * @param string $pluginName
     * @param string $method
     * @return mixed
     */
    private function runPluginMethod(string $pluginName, string $method)
    {
        $plugin = $this->getPluginByName($pluginName);

        if (!$plugin) {
            return null;
        }

        $pluginPath = $this->pluginsDir . '/' . $plugin['path'];
        $mainFile = $pluginPath . '/' . $plugin['main_file'];

        if (!file_exists($mainFile)) {
            return null;
        }

        // Load plugin main file
        require_once $mainFile;

        // Try to find and instantiate plugin class
        // Convention: Plugin class name should be in format {PluginName}Plugin
        $className = $this->getPluginClassName($pluginName);

        if (class_exists($className)) {
            $instance = new $className($this->db, $this->hookManager);
            if (method_exists($instance, $method)) {
                return $instance->$method();
            }
        }

        return null;
    }

    /**
     * Get plugin class name from plugin name
     *
     * @param string $pluginName
     * @return string
     */
    private function getPluginClassName(string $pluginName): string
    {
        // Convert plugin-name to PluginNamePlugin
        $parts = explode('-', $pluginName);
        $className = '';
        foreach ($parts as $part) {
            $className .= ucfirst($part);
        }
        return $className . 'Plugin';
    }

    /**
     * Delete directory recursively
     *
     * @param string $dir
     * @return bool
     */
    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($dir);
    }

    /**
     * Get plugin setting
     *
     * @param int $pluginId
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getSetting(int $pluginId, string $key, $default = null)
    {
        $stmt = $this->db->prepare("SELECT setting_value FROM plugin_settings WHERE plugin_id = ? AND setting_key = ?");
        $stmt->bind_param('is', $pluginId, $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row ? $row['setting_value'] : $default;
    }

    /**
     * Set plugin setting
     *
     * @param int $pluginId
     * @param string $key
     * @param mixed $value
     * @param bool $autoload
     * @return bool
     */
    public function setSetting(int $pluginId, string $key, $value, bool $autoload = true): bool
    {
        $autoloadInt = $autoload ? 1 : 0;

        $stmt = $this->db->prepare("
            INSERT INTO plugin_settings (plugin_id, setting_key, setting_value, autoload, created_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), autoload = VALUES(autoload), updated_at = NOW()
        ");

        $valueStr = is_array($value) || is_object($value) ? json_encode($value) : (string)$value;
        $stmt->bind_param('issi', $pluginId, $key, $valueStr, $autoloadInt);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Get plugin data
     *
     * @param int $pluginId
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getData(int $pluginId, string $key, $default = null)
    {
        $stmt = $this->db->prepare("SELECT data_value, data_type FROM plugin_data WHERE plugin_id = ? AND data_key = ?");
        $stmt->bind_param('is', $pluginId, $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return $default;
        }

        // Parse value based on type
        $value = $row['data_value'];
        $type = $row['data_type'];

        switch ($type) {
            case 'json':
                return json_decode($value, true);
            case 'int':
                return (int)$value;
            case 'float':
                return (float)$value;
            case 'bool':
                return (bool)$value;
            default:
                return $value;
        }
    }

    /**
     * Set plugin data
     *
     * @param int $pluginId
     * @param string $key
     * @param mixed $value
     * @param string $type
     * @return bool
     */
    public function setData(int $pluginId, string $key, $value, string $type = 'string'): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO plugin_data (plugin_id, data_key, data_value, data_type, created_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE data_value = VALUES(data_value), data_type = VALUES(data_type), updated_at = NOW()
        ");

        $valueStr = is_array($value) || is_object($value) ? json_encode($value) : (string)$value;
        $stmt->bind_param('isss', $pluginId, $key, $valueStr, $type);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Log plugin activity
     *
     * @param int|null $pluginId
     * @param string $level
     * @param string $message
     * @param array $context
     * @return bool
     */
    public function log(?int $pluginId, string $level, string $message, array $context = []): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO plugin_logs (plugin_id, level, message, context, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");

        $contextJson = json_encode($context);
        $stmt->bind_param('isss', $pluginId, $level, $message, $contextJson);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Load and initialize all active plugins
     * This method should be called at application bootstrap
     *
     * @return void
     */
    public function loadActivePlugins(): void
    {
        // Get all active plugins
        $activePlugins = $this->getActivePlugins();

        if (empty($activePlugins)) {
            return;
        }

        foreach ($activePlugins as $plugin) {
            try {
                $this->loadPlugin($plugin);
            } catch (\Throwable $e) {
                error_log("[PluginManager] Failed to load plugin '{$plugin['name']}': " . $e->getMessage());
                // Continue loading other plugins even if one fails
            }
        }

        // Prevent HookManager from loading hooks from database
        $this->hookManager->setPluginsLoadedRuntime();
    }

    /**
     * Load a single plugin and register its hooks
     *
     * @param array $plugin
     * @return void
     */
    private function loadPlugin(array $plugin): void
    {
        $pluginName = $plugin['name'];
        $pluginPath = $this->pluginsDir . '/' . $plugin['path'];
        $mainFile = $pluginPath . '/' . $plugin['main_file'];

        if (!file_exists($mainFile)) {
            throw new \Exception("Main file not found: {$mainFile}");
        }

        // Load plugin main file
        require_once $mainFile;

        // Get plugin class name
        $className = $this->getPluginClassName($pluginName);

        if (!class_exists($className)) {
            throw new \Exception("Plugin class not found: {$className}");
        }

        // Instantiate plugin
        $instance = new $className($this->db, $this->hookManager);

        // Load and register hooks for this plugin
        $this->registerPluginHooks((int)$plugin['id'], $instance);
    }

    /**
     * Register hooks for a plugin instance
     *
     * @param int $pluginId
     * @param object $pluginInstance
     * @return void
     */
    private function registerPluginHooks(int $pluginId, object $pluginInstance): void
    {
        // Get hooks from database
        $stmt = $this->db->prepare("
            SELECT hook_name, callback_method, priority
            FROM plugin_hooks
            WHERE plugin_id = ?
            ORDER BY priority ASC
        ");
        $stmt->bind_param('i', $pluginId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $hookName = $row['hook_name'];
            $callbackMethod = $row['callback_method'];
            $priority = (int)$row['priority'];

            // Register hook in HookManager
            if (method_exists($pluginInstance, $callbackMethod)) {
                $this->hookManager->addHook($hookName, [$pluginInstance, $callbackMethod], $priority);
            } else {
                error_log("[PluginManager] Method not found: {$callbackMethod} for hook {$hookName}");
            }
        }

        $stmt->close();
    }
}

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
    private const MAX_UPLOAD_BYTES = 104857600; // 100 MB
    private mysqli $db;
    private string $pluginsDir;
    private string $uploadsDir;
    private HookManager $hookManager;
    private ?string $cachedEncryptionKey = null;
    private bool $encryptionKeyResolved = false;

    public function __construct(mysqli $db, HookManager $hookManager)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;
        $this->pluginsDir = __DIR__ . '/../../storage/plugins';
        $this->uploadsDir = __DIR__ . '/../../storage/uploads/plugins';

        // Ensure directories exist
        if (!is_dir($this->pluginsDir)) {
            mkdir($this->pluginsDir, 0755, true);
        }
        if (!is_dir($this->uploadsDir)) {
            mkdir($this->uploadsDir, 0755, true);
        }
    }

    /**
     * Bundled plugins that ship with Pinakes
     * These are auto-registered and activated if their folders exist
     */
    private const BUNDLED_PLUGINS = [
        'open-library',
        'z39-server',
        'api-book-scraper',
        'digital-library',
        'dewey-editor',
    ];

    /**
     * Auto-register bundled plugins that exist on disk but not in database
     * This ensures bundled plugins survive updates even if DB entries were lost
     *
     * @return int Number of plugins auto-registered
     */
    public function autoRegisterBundledPlugins(): int
    {
        $registered = 0;

        foreach (self::BUNDLED_PLUGINS as $pluginName) {
            $pluginPath = $this->pluginsDir . '/' . $pluginName;
            $jsonPath = $pluginPath . '/plugin.json';

            // Skip if folder doesn't exist
            if (!is_dir($pluginPath) || !file_exists($jsonPath)) {
                continue;
            }

            // Check if already registered
            $stmt = $this->db->prepare("SELECT id FROM plugins WHERE name = ?");
            $stmt->bind_param('s', $pluginName);
            $stmt->execute();
            $result = $stmt->get_result();
            $exists = $result->num_rows > 0;
            $stmt->close();

            if ($exists) {
                continue; // Already registered
            }

            // Read plugin.json
            $json = file_get_contents($jsonPath);
            $pluginMeta = json_decode($json, true);

            if (!$pluginMeta || empty($pluginMeta['name'])) {
                error_log("[PluginManager] Invalid plugin.json for bundled plugin: $pluginName");
                continue;
            }

            // Insert into database
            $stmt = $this->db->prepare("
                INSERT INTO plugins (
                    name, display_name, description, version, author, author_url, plugin_url,
                    is_active, path, main_file, requires_php, requires_app, metadata, installed_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, NOW())
            ");

            $metadata = json_encode($pluginMeta['metadata'] ?? []);
            $name = $pluginMeta['name'];
            $displayName = $pluginMeta['display_name'] ?? $pluginName;
            $description = $pluginMeta['description'] ?? '';
            $version = $pluginMeta['version'] ?? '1.0.0';
            $author = $pluginMeta['author'] ?? '';
            $authorUrl = $pluginMeta['author_url'] ?? '';
            $pluginUrl = $pluginMeta['plugin_url'] ?? '';
            $path = $pluginMeta['name'];
            $mainFile = $pluginMeta['main_file'] ?? 'wrapper.php';
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

            if ($stmt->execute()) {
                $pluginId = $this->db->insert_id;
                $registered++;
                error_log("[PluginManager] Auto-registered bundled plugin: $pluginName (ID: $pluginId, active)");

                // Run onInstall if exists
                try {
                    $this->runPluginMethod($pluginName, 'setPluginId', [$pluginId]);
                } catch (\Throwable $e) {
                    // Optional method
                }

                try {
                    $this->runPluginMethod($pluginName, 'onInstall');
                } catch (\Throwable $e) {
                    error_log("[PluginManager] Note: onInstall failed for $pluginName: " . $e->getMessage());
                }

                // Register hooks for active plugin
                try {
                    $this->runPluginMethod($pluginName, 'onActivate');
                } catch (\Throwable $e) {
                    error_log("[PluginManager] Note: onActivate failed for $pluginName: " . $e->getMessage());
                }
            } else {
                error_log("[PluginManager] Failed to auto-register $pluginName: " . $this->db->error);
            }

            $stmt->close();
        }

        if ($registered > 0) {
            error_log("[PluginManager] Auto-registered $registered bundled plugin(s)");
        }

        return $registered;
    }

    /**
     * Get all installed plugins
     * Automatically cleans up orphan plugins (missing folders)
     * and auto-registers bundled plugins if needed
     *
     * @return array
     */
    public function getAllPlugins(): array
    {
        // First, auto-register bundled plugins that exist on disk but not in DB
        $this->autoRegisterBundledPlugins();

        // Then clean up any orphan plugins (non-bundled)
        $this->cleanupOrphanPlugins();

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
     * Clean up orphan plugins (plugins in database but missing folders)
     * Automatically deactivates and removes them from database
     *
     * @return int Number of orphan plugins removed
     */
    public function cleanupOrphanPlugins(): int
    {
        $query = "SELECT id, name, path, is_active FROM plugins";
        $result = $this->db->query($query);

        if (!$result) {
            return 0;
        }

        $orphanIds = [];
        while ($row = $result->fetch_assoc()) {
            $pluginPath = $this->pluginsDir . '/' . $row['path'];

            // Check if plugin folder exists
            if (!is_dir($pluginPath)) {
                $orphanIds[] = (int)$row['id'];
                error_log("[PluginManager] Orphan plugin detected: '{$row['name']}' - folder missing at {$pluginPath}");
            }
        }
        $result->free();

        if (empty($orphanIds)) {
            return 0;
        }

        // Delete orphan plugins from database (cascade will delete hooks, settings, data, logs)
        // Use a loop to avoid mysqli bind_param by-reference issues with spread operator
        $stmt = $this->db->prepare("DELETE FROM plugins WHERE id = ?");
        if ($stmt === false) {
            error_log('[PluginManager] Failed to prepare orphan plugin cleanup statement: ' . $this->db->error);
            return 0;
        }

        $pluginId = 0;
        $stmt->bind_param('i', $pluginId);
        $deleted = 0;

        foreach ($orphanIds as $pluginId) {
            if ($stmt->execute()) {
                $deleted += $stmt->affected_rows;
            }
        }

        $stmt->close();

        if ($deleted > 0) {
            error_log("[PluginManager] Cleaned up {$deleted} orphan plugin(s) from database");
        }

        return $deleted;
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
                return ['success' => false, 'message' => __('File ZIP non trovato.'), 'plugin_id' => null];
            }

            $fileSize = filesize($zipPath);
            if ($fileSize !== false && $fileSize > self::MAX_UPLOAD_BYTES) {
                error_log("❌ [PluginManager] ZIP too large: {$fileSize} bytes");
                return ['success' => false, 'message' => __('File ZIP troppo grande. Dimensione massima: 100 MB.'), 'plugin_id' => null];
            }

        $zip = new ZipArchive();
        $zipResult = $zip->open($zipPath);

        if ($zipResult !== true) {
            return ['success' => false, 'message' => __('Impossibile aprire il file ZIP.'), 'plugin_id' => null];
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
            return ['success' => false, 'message' => __('File plugin.json non trovato nel pacchetto.'), 'plugin_id' => null];
        }

        // Read and validate plugin.json
        $pluginJsonContent = $zip->getFromName($pluginJsonPath);
        $pluginMeta = json_decode($pluginJsonContent, true);

        if (!$pluginMeta) {
            $zip->close();
            return ['success' => false, 'message' => __('File plugin.json non valido.'), 'plugin_id' => null];
        }

        // Validate required fields
        $requiredFields = ['name', 'display_name', 'version', 'main_file'];
        foreach ($requiredFields as $field) {
            if (empty($pluginMeta[$field])) {
                $zip->close();
                return ['success' => false, 'message' => __('Campo obbligatorio mancante: %s', $field), 'plugin_id' => null];
            }
        }

        if (!preg_match('/^[a-z0-9_\-]+$/i', $pluginMeta['name'])) {
            $zip->close();
            return ['success' => false, 'message' => __('Nome plugin non valido. Usa solo lettere, numeri, trattini o underscore.'), 'plugin_id' => null];
        }

        // Check if plugin already exists
        $existingPlugin = $this->getPluginByName($pluginMeta['name']);
        if ($existingPlugin) {
            $zip->close();
            return ['success' => false, 'message' => __('Plugin già installato.'), 'plugin_id' => null];
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
        $pluginsBaseDir = realpath($this->pluginsDir) ?: $this->pluginsDir;
        $pluginPath = $pluginsBaseDir . '/' . $pluginMeta['name'];

        if (is_dir($pluginPath)) {
            $zip->close();
            return ['success' => false, 'message' => __('Directory plugin già esistente.'), 'plugin_id' => null];
        }

        if (!mkdir($pluginPath, 0755, true)) {
            $zip->close();
            return ['success' => false, 'message' => __('Impossibile creare la directory del plugin.'), 'plugin_id' => null];
        }

        $pluginRealPath = realpath($pluginPath);
        if ($pluginRealPath === false || strpos($pluginRealPath, rtrim($pluginsBaseDir, DIRECTORY_SEPARATOR)) !== 0) {
            $zip->close();
            $this->deleteDirectory($pluginPath);
            return ['success' => false, 'message' => __('Percorso di installazione del plugin non valido.'), 'plugin_id' => null];
        }
        $pluginPath = $pluginRealPath;

        $extractedFiles = false;
        $pluginRootPrefix = $pluginRootDir ? rtrim($pluginRootDir, '/') . '/' : null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            if ($pluginRootPrefix !== null) {
                if ($filename === $pluginRootDir || $filename === $pluginRootPrefix) {
                    continue; // skip directory root entry
                }

                if (strpos($filename, $pluginRootPrefix) !== 0) {
                    continue;
                }

                $relativePath = substr($filename, strlen($pluginRootPrefix));
            } else {
                $relativePath = $filename;
            }

            if ($relativePath === '' || $relativePath === false) {
                continue;
            }

            $targetPath = $this->resolveExtractionPath($pluginRealPath, $relativePath);

            if ($targetPath === null) {
                $zip->close();
                $this->deleteDirectory($pluginPath);
                return ['success' => false, 'message' => __('Il pacchetto contiene percorsi non validi.'), 'plugin_id' => null];
            }

            if (str_ends_with($filename, '/')) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0755, true)) {
                    $zip->close();
                    $this->deleteDirectory($pluginPath);
                    return ['success' => false, 'message' => __('Impossibile creare la struttura del plugin.'), 'plugin_id' => null];
                }
                continue;
            }

            $dir = dirname($targetPath);
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                $zip->close();
                $this->deleteDirectory($pluginPath);
                return ['success' => false, 'message' => __('Impossibile creare la struttura del plugin.'), 'plugin_id' => null];
            }

            $content = $zip->getFromIndex($i);
            if ($content === false || file_put_contents($targetPath, $content) === false) {
                $zip->close();
                $this->deleteDirectory($pluginPath);
                return ['success' => false, 'message' => __('Errore durante l\'estrazione del plugin.'), 'plugin_id' => null];
            }

            $extractedFiles = true;
        }

        $zip->close();

        if (!$extractedFiles) {
            $this->deleteDirectory($pluginPath);
            return ['success' => false, 'message' => __('Il pacchetto non contiene file validi.'), 'plugin_id' => null];
        }

        // Verify main file exists
        $mainFilePath = $pluginPath . '/' . $pluginMeta['main_file'];
        if (!file_exists($mainFilePath)) {
            $this->deleteDirectory($pluginPath);
            return ['success' => false, 'message' => __('File principale del plugin non trovato.'), 'plugin_id' => null];
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
            return ['success' => false, 'message' => __('Errore durante il salvataggio nel database.'), 'plugin_id' => null];
        }

            // Set plugin ID before running installation hook
            try {
                $this->runPluginMethod($pluginMeta['name'], 'setPluginId', [$pluginId]);
            } catch (\Throwable $e) {
                error_log("[PluginManager] Note: Plugin doesn't have setPluginId method (not required): " . $e->getMessage());
            }

            // Run plugin installation hook if exists
            $this->runPluginMethod($pluginMeta['name'], 'onInstall');

            error_log("✅ [PluginManager] Plugin installed successfully: {$pluginMeta['name']} (ID: $pluginId)");

            return [
                'success' => true,
                'message' => 'Plugin installato con successo.',
                'plugin_id' => $pluginId
            ];
        } catch (\Throwable $e) {
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
            return ['success' => false, 'message' => __('Plugin non trovato.')];
        }

        if ($plugin['is_active']) {
            return ['success' => false, 'message' => __('Plugin già attivo.')];
        }

        // Load plugin main file
        $pluginPath = $this->pluginsDir . '/' . $plugin['path'];
        $mainFile = $pluginPath . '/' . $plugin['main_file'];

        if (!file_exists($mainFile)) {
            return ['success' => false, 'message' => __('File principale del plugin non trovato.')];
        }

        // Run plugin activation hook
        try {
            $this->runPluginMethod($plugin['name'], 'onActivate');
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => __('Errore durante l\'attivazione: %s', $e->getMessage())];
        }

        // Update database
        $stmt = $this->db->prepare("UPDATE plugins SET is_active = 1, activated_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $pluginId);
        $result = $stmt->execute();
        $stmt->close();

        if (!$result) {
            return ['success' => false, 'message' => __('Errore durante l\'attivazione del plugin.')];
        }

        return ['success' => true, 'message' => __('Plugin attivato con successo.')];
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
            return ['success' => false, 'message' => __('Plugin non trovato.')];
        }

        if (!$plugin['is_active']) {
            return ['success' => false, 'message' => __('Plugin già disattivato.')];
        }

        // Run plugin deactivation hook
        try {
            $this->runPluginMethod($plugin['name'], 'onDeactivate');
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => __('Errore durante la disattivazione: %s', $e->getMessage())];
        }

        // Update database
        $stmt = $this->db->prepare("UPDATE plugins SET is_active = 0, activated_at = NULL WHERE id = ?");
        $stmt->bind_param('i', $pluginId);
        $result = $stmt->execute();
        $stmt->close();

        if (!$result) {
            return ['success' => false, 'message' => __('Errore durante la disattivazione del plugin.')];
        }

        return ['success' => true, 'message' => __('Plugin disattivato con successo.')];
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
            return ['success' => false, 'message' => __('Plugin non trovato.')];
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
            return ['success' => false, 'message' => __('Errore durante la rimozione dal database.')];
        }

        // Delete plugin directory
        $pluginPath = $this->pluginsDir . '/' . $plugin['path'];
        if (is_dir($pluginPath)) {
            $this->deleteDirectory($pluginPath);
        }

        return ['success' => true, 'message' => __('Plugin disinstallato con successo.')];
    }

    /**
     * Run a plugin method if it exists
     *
     * @param string $pluginName
     * @param string $method
     * @return mixed
     */
    private function runPluginMethod(string $pluginName, string $method, array $args = [])
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
                return $instance->$method(...$args);
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
     * Resolve a ZIP entry path inside the plugin directory and prevent traversal
     */
    private function resolveExtractionPath(string $baseDir, string $relativePath): ?string
    {
        $baseRealPath = realpath($baseDir);
        if ($baseRealPath === false) {
            return null;
        }

        $relativePath = str_replace('\\', '/', $relativePath);

        if ($relativePath === '' || preg_match('#^(?:[A-Za-z]:)?/#', $relativePath)) {
            return null;
        }

        $segments = explode('/', $relativePath);
        $safeSegments = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($safeSegments);
                continue;
            }
            $safeSegments[] = $segment;
        }

        $normalizedBase = rtrim($baseRealPath, DIRECTORY_SEPARATOR);
        $fullPath = $normalizedBase;
        if (!empty($safeSegments)) {
            $fullPath .= DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $safeSegments);
        }

        $fullPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
        $normalizedBaseWithSep = $normalizedBase . DIRECTORY_SEPARATOR;

        if ($fullPath !== $normalizedBase && strpos($fullPath, $normalizedBaseWithSep) !== 0) {
            return null;
        }

        return $fullPath;
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

        if (!$row) {
            return $default;
        }

        $value = $this->decryptPluginSettingValue($row['setting_value']);
        return $value ?? $default;
    }

    /**
     * Get all settings for a plugin
     *
     * @param int $pluginId
     * @return array
     */
    public function getSettings(int $pluginId): array
    {
        $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM plugin_settings WHERE plugin_id = ?");
        $stmt->bind_param('i', $pluginId);
        $stmt->execute();
        $result = $stmt->get_result();

        $settings = [];
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = $this->decryptPluginSettingValue($row['setting_value']) ?? '';
        }

        $stmt->close();
        return $settings;
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
        $valueStr = $this->encryptPluginSettingValue($valueStr);
        $stmt->bind_param('issi', $pluginId, $key, $valueStr, $autoloadInt);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Encrypt sensitive plugin setting values before persisting them
     */
    private function encryptPluginSettingValue(string $value): string
    {
        $key = $this->getEncryptionKey();

        if ($key === null || $value === '') {
            return $value;
        }

        try {
            $iv = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

            if ($ciphertext === false) {
                return $value;
            }

            $payload = base64_encode($iv . $tag . $ciphertext);
            return 'ENC:' . $payload;
        } catch (\Throwable $e) {
            error_log('[PluginManager] Errore durante la cifratura del setting: ' . $e->getMessage());
            return $value;
        }
    }

    /**
     * Decrypt settings on read
     */
    private function decryptPluginSettingValue(?string $value): ?string
    {
        if ($value === null || $value === '' || strpos($value, 'ENC:') !== 0) {
            return $value;
        }

        $key = $this->getEncryptionKey();
        if ($key === null) {
            error_log('[PluginManager] Chiave di cifratura mancante: impossibile decrittare il valore.');
            return null;
        }

        $payload = base64_decode(substr($value, 4), true);
        if ($payload === false || strlen($payload) <= 28) {
            error_log('[PluginManager] Payload cifrato non valido.');
            return null;
        }

        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $ciphertext = substr($payload, 28);

        try {
            $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plaintext === false) {
                error_log('[PluginManager] Impossibile decrittare il valore del plugin setting.');
                return null;
            }
            return $plaintext;
        } catch (\Throwable $e) {
            error_log('[PluginManager] Eccezione durante la decrittazione: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve encryption key from environment
     */
    private function getEncryptionKey(): ?string
    {
        if ($this->encryptionKeyResolved) {
            return $this->cachedEncryptionKey;
        }

        $rawKey = $_ENV['PLUGIN_ENCRYPTION_KEY']
            ?? (getenv('PLUGIN_ENCRYPTION_KEY') ?: null)
            ?? $_ENV['APP_KEY']
            ?? (getenv('APP_KEY') ?: null);
        if ($rawKey) {
            $this->cachedEncryptionKey = hash('sha256', (string)$rawKey, true);
        } else {
            $this->cachedEncryptionKey = null;
        }

        $this->encryptionKeyResolved = true;
        return $this->cachedEncryptionKey;
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
        // Clean up orphan plugins first
        $this->cleanupOrphanPlugins();

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
        // Save plugin data to prefixed variables before require_once
        // This prevents plugin files from overwriting $plugin variable (which some do)
        $_pluginId = (int)$plugin['id'];
        $_pluginName = $plugin['name'];
        $_pluginPath = $this->pluginsDir . '/' . $plugin['path'];
        $_mainFile = $_pluginPath . '/' . $plugin['main_file'];

        if (!file_exists($_mainFile)) {
            throw new \Exception("Main file not found: {$_mainFile}");
        }

        // Load plugin main file
        // NOTE: Plugin files may define variables that collide with local scope
        require_once $_mainFile;

        // Get plugin class name
        $className = $this->getPluginClassName($_pluginName);

        if (!class_exists($className)) {
            throw new \Exception("Plugin class not found: {$className}");
        }

        // Instantiate plugin
        $instance = new $className($this->db, $this->hookManager);

        // Pass plugin ID to the instance when supported (needed for plugin settings)
        if (is_callable([$instance, 'setPluginId'])) {
            try {
                $instance->setPluginId($_pluginId);
            } catch (\Throwable $e) {
                SecureLogger::warning("[PluginManager] setPluginId failed for {$_pluginName}", [
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Load and register hooks for this plugin
        $this->registerPluginHooks($_pluginId, $instance);
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
            // Support both direct methods and __call magic methods
            if (method_exists($pluginInstance, $callbackMethod) || $this->hasMagicMethod($pluginInstance, $callbackMethod)) {
                $this->hookManager->addHook($hookName, [$pluginInstance, $callbackMethod], $priority);
            } else {
                error_log("[PluginManager] Method not found: {$callbackMethod} for hook {$hookName}");
            }
        }

        $stmt->close();
    }

    /**
     * Check if a plugin instance has a magic __call method that can handle the given method
     *
     * @param object $pluginInstance
     * @param string $method
     * @return bool
     */
    private function hasMagicMethod(object $pluginInstance, string $method): bool
    {
        // Check if the instance has __call method
        if (!method_exists($pluginInstance, '__call')) {
            return false;
        }

        // Try to access the wrapped instance (common pattern in wrapper classes)
        if (property_exists($pluginInstance, 'instance')) {
            try {
                $reflection = new \ReflectionClass($pluginInstance);
                $instanceProperty = $reflection->getProperty('instance');
                $instanceProperty->setAccessible(true);
                $wrappedInstance = $instanceProperty->getValue($pluginInstance);

                if (is_object($wrappedInstance) && method_exists($wrappedInstance, $method)) {
                    return true;
                }
            } catch (\ReflectionException $e) {
                // If we can't access the instance property, fall back to a simpler check
            }
        }

        // Check if this is likely a wrapper class by looking for common patterns
        $reflection = new \ReflectionClass($pluginInstance);
        $docComment = $reflection->getDocComment();

        // If the class has a doc comment mentioning it's a wrapper or proxy, assume it can handle the method
        if ($docComment && (strpos($docComment, 'wrapper') !== false || strpos($docComment, 'proxy') !== false)) {
            return true;
        }

        // If the class name suggests it's a wrapper (ends with Plugin) — __call already confirmed above
        $className = $reflection->getShortName();
        if (str_ends_with($className, 'Plugin')) {
            return true;
        }

        return false;
    }
}

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
    /** Filename prefix for deferred plugin-update markers; public so tests reference it instead of duplicating the literal. */
    public const PENDING_UPDATE_PREFIX = '.pinakes-plugin-update-';
    private mysqli $db;
    private string $pluginsDir;
    private string $uploadsDir;
    private HookManager $hookManager;
    private ?string $cachedEncryptionKey = null;
    private bool $encryptionKeyResolved = false;

    /**
     * @var array<int,true> Plugins rolled back after their replacement class was
     * loaded. Static so the skip covers the whole PHP request even when a
     * controller builds a second PluginManager instance: the broken replacement
     * class stays defined process-wide, so every instance must skip it.
     */
    private static array $skipPluginIdsThisRequest = [];

    /**
     * Per-process cache for {@see isActive()} lookups.
     *
     * Static (not per-instance) so that every code path that asks the same
     * question — controllers, views, plugin bootstrap, hooks — pays the DB
     * round-trip at most once per request lifetime per plugin name. Keyed
     * by plugin name; value is the boolean is_active result.
     *
     * @var array<string, bool>
     */
    private static array $isActiveCache = [];

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
     * Return a user-facing error when a manifest targets a newer core.
     * Plugin ZIPs are a supported distribution path, so merely persisting
     * requires_app without enforcing it lets plugins fatal while loading
     * classes introduced by a later Pinakes release.
     *
     * @param array<string,mixed> $pluginMeta
     */
    private function appCompatibilityError(array $pluginMeta): ?string
    {
        $required = trim((string) ($pluginMeta['requires_app'] ?? ''));
        if ($required === '') {
            return null;
        }

        $versionFile = dirname(__DIR__, 2) . '/version.json';
        $versionData = is_file($versionFile)
            ? json_decode((string) file_get_contents($versionFile), true)
            : null;
        $current = is_array($versionData) ? trim((string) ($versionData['version'] ?? '')) : '';
        if ($current === '') {
            return __('Impossibile verificare la versione di Pinakes richiesta dal plugin.');
        }
        if (version_compare($current, $required, '<')) {
            return sprintf(
                __('Plugin richiede Pinakes %s o superiore; versione installata: %s.'),
                $required,
                $current
            );
        }

        return null;
    }

    /**
     * Auto-register bundled plugins that exist on disk but not in database
     * This ensures bundled plugins survive updates even if DB entries were lost
     *
     * @return int Number of plugins auto-registered
     */
    /**
     * Cheap boot-time schema-presence probe used by the self-heal in
     * autoRegisterBundledPlugins(). A schema-owning plugin may declare
     * `expectedTables(): list<string>`; if any of those tables is missing the
     * plugin's schema is behind and ensureSchema must be re-run. Plugins that
     * do not declare the method never trigger a rebuild. One read-only
     * information_schema query, no locks — safe to call on every boot.
     */
    private function bundledSchemaIncomplete(object $instance): bool
    {
        if (!method_exists($instance, 'expectedTables')) {
            return false;
        }
        try {
            $tables = $instance->expectedTables();
        } catch (\Throwable $e) {
            return false;
        }
        if (!is_array($tables) || $tables === []) {
            return false;
        }
        $escaped = [];
        foreach ($tables as $t) {
            if (is_string($t) && $t !== '') {
                $escaped[] = "'" . $this->db->real_escape_string($t) . "'";
            }
        }
        if ($escaped === []) {
            return false;
        }
        $sql = "SELECT COUNT(DISTINCT TABLE_NAME) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (" . implode(',', $escaped) . ")";
        $res = $this->db->query($sql);
        if ($res === false) {
            return false;
        }
        $present = (int) $res->fetch_row()[0];
        return $present < count($escaped);
    }

    public function autoRegisterBundledPlugins(): int
    {
        $registered = 0;

        foreach (BundledPlugins::LIST as $pluginName) {
            $pluginPath = $this->pluginsDir . '/' . $pluginName;
            $jsonPath = $pluginPath . '/plugin.json';

            // Skip if folder doesn't exist
            if (!is_dir($pluginPath) || !file_exists($jsonPath)) {
                continue;
            }

            // Read plugin.json
            $json = file_get_contents($jsonPath);
            $pluginMeta = json_decode($json, true);

            if (!$pluginMeta || empty($pluginMeta['name'])) {
                SecureLogger::warning("[PluginManager] Invalid plugin.json for bundled plugin: $pluginName");
                continue;
            }

            // Check if already registered
            $stmt = $this->db->prepare(
                "SELECT id, version, is_active, requires_php, requires_app
                   FROM plugins WHERE name = ?"
            );
            if ($stmt === false) {
                SecureLogger::error("[PluginManager] Failed to prepare bundled plugin lookup for $pluginName", ['db_error' => $this->db->error]);
                continue;
            }
            $stmt->bind_param('s', $pluginName);
            if (!$stmt->execute()) {
                SecureLogger::error("[PluginManager] Failed bundled plugin lookup execute for $pluginName", ['stmt_error' => $stmt->error]);
                $stmt->close();
                continue;
            }
            $result = $stmt->get_result();
            if ($result === false) {
                SecureLogger::error("[PluginManager] Failed bundled plugin lookup result for $pluginName", ['stmt_error' => $stmt->error]);
                $stmt->close();
                continue;
            }
            $row = $result->fetch_assoc();
            $stmt->close();

            if ($row) {
                if (isset(self::$skipPluginIdsThisRequest[(int) $row['id']])) {
                    continue;
                }
                // Manifest compatibility metadata is operational, not merely
                // descriptive. Keep it in sync even when a bundled plugin's
                // own version did not change in this core release.
                $diskRequiresPhp = (string) ($pluginMeta['requires_php'] ?? '');
                $diskRequiresApp = (string) ($pluginMeta['requires_app'] ?? '');
                if ($diskRequiresPhp !== (string) ($row['requires_php'] ?? '')
                    || $diskRequiresApp !== (string) ($row['requires_app'] ?? '')
                ) {
                    $requirementsStmt = $this->db->prepare(
                        'UPDATE plugins SET requires_php = ?, requires_app = ? WHERE id = ?'
                    );
                    if ($requirementsStmt !== false) {
                        $requirementsId = (int) $row['id'];
                        $requirementsStmt->bind_param(
                            'ssi',
                            $diskRequiresPhp,
                            $diskRequiresApp,
                            $requirementsId
                        );
                        $requirementsStmt->execute();
                        $requirementsStmt->close();
                    }
                }

                // Sync version/metadata if disk version is newer
                $diskVersion = $pluginMeta['version'] ?? '1.0.0';
                $dbVersion = $row['version'] ?? '0.0.0';
                if (version_compare($diskVersion, $dbVersion, '>')) {
                    $updStmt = $this->db->prepare(
                        "UPDATE plugins SET version = ?, display_name = ?, description = ?, metadata = ? WHERE id = ?"
                    );
                    if ($updStmt === false) {
                        SecureLogger::error("[PluginManager] Failed to prepare bundled plugin update for $pluginName", ['db_error' => $this->db->error]);
                        continue;
                    }
                    $updDisplayName = $pluginMeta['display_name'] ?? $pluginName;
                    $updDescription = $pluginMeta['description'] ?? '';
                    $updMetadata = json_encode($pluginMeta['metadata'] ?? []);
                    $updId = (int) $row['id'];
                    $updStmt->bind_param('ssssi', $diskVersion, $updDisplayName, $updDescription, $updMetadata, $updId);
                    $updated = $updStmt->execute();
                    $updStmt->close();
                    if (!$updated) {
                        SecureLogger::error("[PluginManager] Failed to update bundled plugin $pluginName", ['db_error' => $this->db->error]);
                        continue;
                    }
                    SecureLogger::info("[PluginManager] Updated bundled plugin: $pluginName $dbVersion → $diskVersion");

                    // Re-register hooks only if plugin is active.
                    // Use a single instance (instantiatePlugin sets pluginId
                    // before calling onActivate) — runPluginMethod() creates
                    // a fresh object and would leave pluginId null, causing
                    // hook registration to skip DB writes.
                    if ((int) ($row['is_active'] ?? 0) === 1) {
                        try {
                            $upgradeInstance = $this->instantiatePlugin([
                                'id'        => (int) $row['id'],
                                'name'      => $pluginName,
                                'path'      => $pluginName,
                                'main_file' => $pluginMeta['main_file'] ?? 'wrapper.php',
                            ]);
                            if (method_exists($upgradeInstance, 'onActivate')) {
                                $upgradeInstance->onActivate();
                            }
                        } catch (\Throwable $e) {
                            $revertStmt = $this->db->prepare(
                                "UPDATE plugins SET version = ? WHERE id = ?"
                            );
                            if ($revertStmt !== false) {
                                $revertStmt->bind_param('si', $dbVersion, $updId);
                                $revertStmt->execute();
                                $revertStmt->close();
                            } else {
                                SecureLogger::error("[PluginManager] Failed to prepare bundled plugin upgrade rollback for $pluginName", [
                                    'db_error' => $this->db->error,
                                ]);
                            }
                            // Do NOT rethrow: one bundled plugin's activation
                            // failure must never abort the sync of the others —
                            // that left every plugin after it un-synced, so an
                            // already-active plugin (Uwe #138, book-club) never
                            // got its new tables. The version is rolled back to
                            // $dbVersion, so the next boot retries this path; and
                            // the same-version branch below self-heals a schema
                            // that is behind. Each plugin now syncs independently.
                            SecureLogger::error("[PluginManager] onActivate failed during upgrade for $pluginName; version rolled back to $dbVersion, other plugins keep syncing, retry next boot. Error: " . $e->getMessage());
                        }
                    }
                } elseif ($diskVersion === $dbVersion && (int) ($row['is_active'] ?? 0) === 1) {
                    // Same disk/DB version, active plugin. Re-run onActivate when
                    // EITHER:
                    //  (a) hooks are missing — code added hooks not yet in DB
                    //      (e.g. merging branches that extend the same version), or
                    //      a wipe left plugin_hooks empty; OR
                    //  (b) the plugin's declared schema is INCOMPLETE — a partial
                    //      or aborted upgrade can leave version == disk with a
                    //      table missing, and without this the "same version"
                    //      branch would skip ensureSchema FOREVER (Uwe #138:
                    //      book-club upgraded while active never got
                    //      bookclub_external_books → permanent 1146 on every page).
                    // Both are gated by a cheap read: hooks are one COUNT, the
                    // schema probe is one read-only information_schema query with
                    // NO locks. The heavy ensureSchema DDL runs ONLY when hooks or
                    // a table are actually absent, so a healthy install pays
                    // nothing and the historical deadlock (running DDL on every
                    // admin-page poll) cannot recur.
                    $pluginIdInt = (int) ($row['id'] ?? 0);
                    $hookCount = 0;
                    $hookStmt = $this->db->prepare('SELECT COUNT(*) FROM plugin_hooks WHERE plugin_id = ?');
                    if ($hookStmt !== false) {
                        $hookStmt->bind_param('i', $pluginIdInt);
                        if ($hookStmt->execute()) {
                            $hookStmt->bind_result($hookCount);
                            $hookStmt->fetch();
                        }
                        $hookStmt->close();
                    }
                    try {
                        $syncInstance = $this->instantiatePlugin([
                            'id'        => $pluginIdInt,
                            'name'      => $pluginName,
                            'path'      => $pluginName,
                            'main_file' => $pluginMeta['main_file'] ?? 'wrapper.php',
                        ]);
                        $needsSync = ((int) $hookCount === 0)
                            || $this->bundledSchemaIncomplete($syncInstance);
                        if ($needsSync && method_exists($syncInstance, 'onActivate')) {
                            $syncInstance->onActivate();
                        }
                    } catch (\Throwable $e) {
                        // Non-fatal: log and continue. The next boot retries.
                        SecureLogger::warning("[PluginManager] Schema/hook self-heal skipped for $pluginName (same version): " . $e->getMessage());
                    }
                }
                continue;
            }

            // Optional plugins (e.g. network-backed scrapers) start inactive
            $isOptional = !empty($pluginMeta['metadata']['optional']);
            $isActiveValue = $isOptional ? 0 : 1;

            // Insert into database
            $stmt = $this->db->prepare("
                INSERT INTO plugins (
                    name, display_name, description, version, author, author_url, plugin_url,
                    is_active, path, main_file, requires_php, requires_app, metadata, installed_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            if ($stmt === false) {
                SecureLogger::error("[PluginManager] Failed to prepare bundled plugin insert for $pluginName", [
                    'db_error' => $this->db->error,
                ]);
                continue;
            }

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

            // Types must line up with the INSERT column order:
            // s×7 (name, display_name, description, version, author, author_url, plugin_url),
            // i (is_active), s (path), s×4 (main_file, requires_php, requires_app, metadata).
            // Prior typo 'ssssssssissss' put `i` at position 9 (path) and `s` at position 8
            // (is_active), causing path='discogs'/'goodlib' to be cast to int 0 — the orphan
            // plugin cleanup then deleted the rows on the very next request.
            $stmt->bind_param(
                'sssssssisssss',
                $name,
                $displayName,
                $description,
                $version,
                $author,
                $authorUrl,
                $pluginUrl,
                $isActiveValue,
                $path,
                $mainFile,
                $requiresPhp,
                $requiresApp,
                $metadata
            );

            if ($stmt->execute()) {
                $pluginId = $this->db->insert_id;
                $registered++;
                $activeLabel = $isOptional ? 'inactive (optional)' : 'active';
                SecureLogger::info("[PluginManager] Auto-registered bundled plugin: $pluginName (ID: $pluginId, $activeLabel)");

                // Build a single instance so setPluginId + onInstall +
                // onActivate share state — runPluginMethod() would create
                // a fresh object per call and pluginId would be null
                // during the hook phase. See activatePlugin() — same bug,
                // same fix (commit 21cb67d).
                $pluginForInstance = [
                    'id'        => (int) $pluginId,
                    'name'      => $pluginName,
                    'path'      => $path,
                    'main_file' => $mainFile,
                ];
                try {
                    $instance = $this->instantiatePlugin($pluginForInstance);
                    if (method_exists($instance, 'onInstall')) {
                        try {
                            $instance->onInstall();
                        } catch (\Throwable $e) {
                            SecureLogger::warning("[PluginManager] Note: onInstall failed for $pluginName: " . $e->getMessage());
                        }
                    }
                    // Register hooks only for active (non-optional) plugins
                    if (!$isOptional && method_exists($instance, 'onActivate')) {
                        try {
                            $instance->onActivate();
                        } catch (\Throwable $e) {
                            SecureLogger::warning("[PluginManager] Note: onActivate failed for $pluginName: " . $e->getMessage());
                        }
                    }
                } catch (\Throwable $e) {
                    SecureLogger::warning("[PluginManager] Note: could not instantiate $pluginName for lifecycle hooks: " . $e->getMessage());
                }
            } else {
                // This is the failure mode that masked the bind_param type-swap
                // bug (commit fb1e881). MUST be error severity so it surfaces in
                // monitoring instead of being lost in warning-level noise.
                SecureLogger::error("[PluginManager] Failed to auto-register $pluginName", ['db_error' => $this->db->error]);
            }

            $stmt->close();
        }

        if ($registered > 0) {
            SecureLogger::info("[PluginManager] Auto-registered $registered bundled plugin(s)");
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

            if (is_dir($pluginPath)) {
                continue;
            }

            // NEVER delete a bundled plugin, even if the folder is temporarily
            // missing. Bundled plugins are part of the release ZIP and get
            // re-materialised on the next upgrade. If we delete the DB row now,
            // the post-install-patch SQL inserted during the upgrade would be
            // wiped out too, and the admin panel would show a broken plugin
            // list until manual intervention. Deactivate if still active,
            // surface a WARNING, move on.
            if (in_array($row['name'], BundledPlugins::LIST, true)) {
                SecureLogger::warning(
                    "[PluginManager] Bundled plugin '{$row['name']}' folder missing at {$pluginPath} — " .
                    "NOT removing from DB (bundled plugins stay registered, waiting for files to be re-copied)"
                );
                if ((int)($row['is_active'] ?? 0) === 1) {
                    $deactivate = $this->db->prepare("UPDATE plugins SET is_active = 0 WHERE id = ?");
                    if ($deactivate !== false) {
                        $pid = (int)$row['id'];
                        $deactivate->bind_param('i', $pid);
                        $deactivate->execute();
                        $deactivate->close();
                        SecureLogger::info("[PluginManager] Deactivated bundled plugin '{$row['name']}' until folder is restored");
                    }
                }
                continue;
            }

            $orphanIds[] = (int)$row['id'];
            SecureLogger::warning("[PluginManager] Orphan plugin detected: '{$row['name']}' - folder missing at {$pluginPath}");
        }
        $result->free();

        if (empty($orphanIds)) {
            return 0;
        }

        // Delete orphan plugins from database (cascade will delete hooks, settings, data, logs)
        // Use a loop to avoid mysqli bind_param by-reference issues with spread operator
        $stmt = $this->db->prepare("DELETE FROM plugins WHERE id = ?");
        if ($stmt === false) {
            SecureLogger::error('[PluginManager] Failed to prepare orphan plugin cleanup statement', ['db_error' => $this->db->error]);
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
            SecureLogger::info("[PluginManager] Cleaned up {$deleted} orphan plugin(s) from database");
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
     * Cheap "is this plugin active?" check with a per-process static cache.
     *
     * Hot paths (book detail, frontend layout) used to issue an uncached
     * `SELECT 1 FROM plugins WHERE name = ? AND is_active = 1` on every
     * render — a guaranteed extra round-trip per request, including for
     * anonymous catalog crawlers. This helper does the same query once per
     * plugin name per PHP request and caches the boolean result.
     *
     * The cache is static for the lifetime of the process: an activation
     * or deactivation done in the same request will not be reflected here
     * after the first lookup. That is acceptable because plugin lifecycle
     * actions happen on admin endpoints (separate request) and the cache
     * dies with the request anyway.
     *
     * @param string $name Plugin name (e.g. 'bibframe-linked-data', 'archives')
     * @return bool        true if the plugin row exists and is_active=1
     */
    public function isActive(string $name): bool
    {
        if (isset(self::$isActiveCache[$name])) {
            return self::$isActiveCache[$name];
        }

        $active = false;
        $stmt = $this->db->prepare("SELECT is_active FROM plugins WHERE name = ? LIMIT 1");
        if ($stmt !== false) {
            $stmt->bind_param('s', $name);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result instanceof \mysqli_result) {
                    $row = $result->fetch_assoc();
                    if ($row !== null) {
                        $active = ((int) $row['is_active']) === 1;
                    }
                    $result->free();
                }
            }
            $stmt->close();
        } else {
            SecureLogger::warning('[PluginManager] isActive() prepare failed', [
                'plugin'   => $name,
                'db_error' => $this->db->error,
            ]);
        }

        self::$isActiveCache[$name] = $active;
        return $active;
    }

    /**
     * Reset the per-process isActive() cache.
     *
     * Lifecycle code (activate / deactivate / uninstall) calls this so a
     * later isActive() check in the same request returns fresh data.
     * Tests can also use it between cases.
     */
    public static function clearIsActiveCache(): void
    {
        self::$isActiveCache = [];
    }

    public function getPluginInstance(int $pluginId): ?object
    {
        $plugin = $this->getPlugin($pluginId);
        if ($plugin === null) {
            return null;
        }

        try {
            return $this->instantiatePlugin($plugin);
        } catch (\Throwable $e) {
            SecureLogger::error("[PluginManager] Failed to instantiate plugin {$plugin['name']}", [
                'plugin_id' => $pluginId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
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
            SecureLogger::warning("🔌 [PluginManager] Starting plugin installation from: $zipPath");

            // Validate ZIP file
            if (!file_exists($zipPath)) {
                SecureLogger::warning("❌ [PluginManager] ZIP file not found: $zipPath");
                return ['success' => false, 'message' => __('File ZIP non trovato.'), 'plugin_id' => null];
            }

            $fileSize = filesize($zipPath);
            if ($fileSize !== false && $fileSize > self::MAX_UPLOAD_BYTES) {
                SecureLogger::warning("❌ [PluginManager] ZIP too large: {$fileSize} bytes");
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

        if (!is_array($pluginMeta)) {
            $zip->close();
            return ['success' => false, 'message' => __('File plugin.json non valido.'), 'plugin_id' => null];
        }

        // Validate required fields
        $requiredFields = ['name', 'display_name', 'version', 'main_file'];
        foreach ($requiredFields as $field) {
            if (!isset($pluginMeta[$field]) || !is_string($pluginMeta[$field]) || trim($pluginMeta[$field]) === '') {
                $zip->close();
                return ['success' => false, 'message' => __('Campo obbligatorio mancante: %s', $field), 'plugin_id' => null];
            }
        }

        if (!preg_match('/^[a-z0-9_\-]+$/i', $pluginMeta['name'])) {
            $zip->close();
            return ['success' => false, 'message' => __('Nome plugin non valido. Usa solo lettere, numeri, trattini o underscore.'), 'plugin_id' => null];
        }

        // An uploaded package with an existing name is an in-place update. Its
        // database ID deliberately remains stable, so plugin settings, data and
        // hook rows continue to point to the same plugin after the update.
        $existingPlugin = $this->getPluginByName($pluginMeta['name']);

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

        $appCompatibilityError = $this->appCompatibilityError($pluginMeta);
        if ($appCompatibilityError !== null) {
            $zip->close();
            return ['success' => false, 'message' => $appCompatibilityError, 'plugin_id' => null];
        }

        // Extract into a sibling staging directory first. Never touch the
        // installed copy until the whole archive has been validated, so a bad
        // update cannot leave an otherwise working plugin half-extracted.
        $pluginsBaseDir = realpath($this->pluginsDir) ?: $this->pluginsDir;
        $targetDirectory = $existingPlugin !== null
            ? (string) ($existingPlugin['path'] ?? '')
            : (string) $pluginMeta['name'];

        if (!$this->isSafePluginDirectoryName($targetDirectory)) {
            $zip->close();
            return ['success' => false, 'message' => __('Percorso di installazione del plugin non valido.'), 'plugin_id' => null];
        }

        $pluginPath = $pluginsBaseDir . '/' . $targetDirectory;
        if ($existingPlugin === null && is_dir($pluginPath)) {
            $zip->close();
            return ['success' => false, 'message' => __('Directory plugin già esistente.'), 'plugin_id' => null];
        }

        $stagingPath = $this->createPluginStagingDirectory($pluginsBaseDir, $targetDirectory);
        if ($stagingPath === null) {
            $zip->close();
            return ['success' => false, 'message' => __('Impossibile creare la directory temporanea del plugin.'), 'plugin_id' => null];
        }

        $pluginRealPath = realpath($stagingPath);
        if ($pluginRealPath === false || strpos($pluginRealPath, rtrim($pluginsBaseDir, DIRECTORY_SEPARATOR)) !== 0) {
            $zip->close();
            $this->deleteDirectory($stagingPath);
            return ['success' => false, 'message' => __('Percorso di installazione del plugin non valido.'), 'plugin_id' => null];
        }

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
                $this->deleteDirectory($stagingPath);
                return ['success' => false, 'message' => __('Il pacchetto contiene percorsi non validi.'), 'plugin_id' => null];
            }

            if (str_ends_with($filename, '/')) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0755, true)) {
                    $zip->close();
                    $this->deleteDirectory($stagingPath);
                    return ['success' => false, 'message' => __('Impossibile creare la struttura del plugin.'), 'plugin_id' => null];
                }
                continue;
            }

            $dir = dirname($targetPath);
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                $zip->close();
                $this->deleteDirectory($stagingPath);
                return ['success' => false, 'message' => __('Impossibile creare la struttura del plugin.'), 'plugin_id' => null];
            }

            $content = $zip->getFromIndex($i);
            if ($content === false || file_put_contents($targetPath, $content) === false) {
                $zip->close();
                $this->deleteDirectory($stagingPath);
                return ['success' => false, 'message' => __('Errore durante l\'estrazione del plugin.'), 'plugin_id' => null];
            }

            $extractedFiles = true;
        }

        $zip->close();

        if (!$extractedFiles) {
            $this->deleteDirectory($stagingPath);
            return ['success' => false, 'message' => __('Il pacchetto non contiene file validi.'), 'plugin_id' => null];
        }

        // Verify main file exists
        if (!$this->isSafePluginFilePath((string) $pluginMeta['main_file'])) {
            $this->deleteDirectory($stagingPath);
            return ['success' => false, 'message' => __('File principale del plugin non valido.'), 'plugin_id' => null];
        }
        $mainFilePath = $pluginRealPath . '/' . $pluginMeta['main_file'];
        if (!file_exists($mainFilePath)) {
            $this->deleteDirectory($stagingPath);
            return ['success' => false, 'message' => __('File principale del plugin non trovato.'), 'plugin_id' => null];
        }

        if ($existingPlugin !== null) {
            return $this->updatePluginFromStaging(
                $existingPlugin,
                $pluginMeta,
                $stagingPath,
                $pluginsBaseDir
            );
        }

        if (!rename($pluginRealPath, $pluginPath)) {
            $this->deleteDirectory($stagingPath);
            return ['success' => false, 'message' => __('Impossibile finalizzare l\'installazione del plugin.'), 'plugin_id' => null];
        }
        $pluginPath = realpath($pluginPath) ?: $pluginPath;

        // Insert plugin into database
        $stmt = $this->db->prepare("
            INSERT INTO plugins (
                name, display_name, description, version, author, author_url, plugin_url,
                is_active, path, main_file, requires_php, requires_app, metadata, installed_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, NOW())
        ");
        if ($stmt === false) {
            // prepare() can fail on schema issues; the extracted plugin dir
            // would otherwise sit on disk and make the next install retry
            // trip "Directory plugin già esistente" even though no plugin
            // row exists. Clean up before returning.
            $this->deleteDirectory($pluginPath);
            SecureLogger::error('[PluginManager] Failed to prepare plugin INSERT', [
                'plugin'   => $pluginMeta['name'],
                'db_error' => $this->db->error,
            ]);
            return [
                'success'   => false,
                'message'   => __('Errore nel salvataggio delle impostazioni.'),
                'plugin_id' => null,
            ];
        }

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

            // Build a single instance so setPluginId + onInstall share
            // state — runPluginMethod would create separate objects and
            // pluginId would be null during onInstall. Same pattern as
            // activatePlugin() (21cb67d).
            $pluginForInstance = [
                'id'        => (int) $pluginId,
                'name'      => $pluginMeta['name'],
                'path'      => $path,
                'main_file' => $mainFile,
            ];
            try {
                $instance = $this->instantiatePlugin($pluginForInstance);
                if (method_exists($instance, 'onInstall')) {
                    $instance->onInstall();
                }
            } catch (\Throwable $e) {
                SecureLogger::error("[PluginManager] onInstall failed, rolling back", ['error' => $e->getMessage()]);
                // Remove the plugins row
                $delStmt = $this->db->prepare("DELETE FROM plugins WHERE id = ?");
                $delStmt->bind_param('i', $pluginId);
                $delStmt->execute();
                $delStmt->close();
                // Remove extracted files
                $this->deleteDirectory($pluginPath);
                throw $e;
            }

            SecureLogger::info("[PluginManager] Plugin installed successfully: {$pluginMeta['name']} (ID: $pluginId)");

            self::clearPluginCache();

            return [
                'success' => true,
                'message' => 'Plugin installato con successo.',
                'plugin_id' => $pluginId
            ];
        } catch (\Throwable $e) {
            SecureLogger::error("[PluginManager] Installation error", ['error' => $e->getMessage()]);
            SecureLogger::error("[PluginManager] Stack trace: " . $e->getTraceAsString());
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

        // The DB column may contain metadata from an older bundled copy. Read
        // the on-disk manifest that will actually be loaded and enforce its
        // minimum core version before any plugin PHP is required.
        $manifestPath = $this->pluginsDir . '/' . $plugin['path'] . '/plugin.json';
        $manifest = is_file($manifestPath)
            ? json_decode((string) file_get_contents($manifestPath), true)
            : null;
        $compatibilityMeta = is_array($manifest) ? array_replace($plugin, $manifest) : $plugin;
        $appCompatibilityError = $this->appCompatibilityError($compatibilityMeta);
        if ($appCompatibilityError !== null) {
            return ['success' => false, 'message' => $appCompatibilityError];
        }

        // Load plugin main file
        $pluginPath = $this->pluginsDir . '/' . $plugin['path'];
        $mainFile = $pluginPath . '/' . $plugin['main_file'];

        if (!file_exists($mainFile)) {
            return ['success' => false, 'message' => __('File principale del plugin non trovato.')];
        }

        // Run plugin activation hook on a single instance so the pluginId
        // set by instantiatePlugin() (via setPluginId) persists through
        // the onActivate() call. Without this, plugins that write to
        // plugin_hooks during onActivate() — e.g. archives — would see
        // pluginId=null and silently no-op, shipping an installed-but-
        // unrouted plugin. See ArchivesPlugin::registerHookInDb() guard.
        try {
            $instance = $this->instantiatePlugin($plugin);
            if (method_exists($instance, 'onActivate')) {
                $instance->onActivate();
            }
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

        // Drop the per-process is_active cache so a follow-up isActive()
        // call in the same request sees the new state.
        self::clearIsActiveCache();

        self::clearPluginCache();

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

        // Run plugin deactivation hook on a single instance (see
        // activatePlugin() — same reasoning). Plugins that prune
        // plugin_hooks rows during onDeactivate() need pluginId set.
        try {
            $instance = $this->instantiatePlugin($plugin);
            if (method_exists($instance, 'onDeactivate')) {
                $instance->onDeactivate();
            }
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

        // Drop the per-process is_active cache so a follow-up isActive()
        // call in the same request sees the new state.
        self::clearIsActiveCache();

        self::clearPluginCache();

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

        // Run plugin uninstall hook on a single instance (see
        // activatePlugin() — same reasoning). onUninstall() can perform
        // FK-aware cleanup with pluginId set before the plugins row is
        // deleted.
        try {
            $instance = $this->instantiatePlugin($plugin);
            if (method_exists($instance, 'onUninstall')) {
                $instance->onUninstall();
            }
        } catch (\Throwable $e) {
            // Continue with uninstall even if hook fails
            SecureLogger::warning("Plugin uninstall hook error: " . $e->getMessage());
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

        // Drop the per-process is_active cache so a follow-up isActive()
        // call in the same request sees the row is gone.
        self::clearPluginCache();

        return ['success' => true, 'message' => __('Plugin disinstallato con successo.')];
    }

    /**
     * Run a plugin method if it exists. Each call creates a fresh plugin
     * object — for lifecycle flows where instance state must persist
     * between multiple calls (e.g. setPluginId followed by onActivate),
     * build the instance via {@see instantiatePlugin()} (which also
     * wires up setPluginId) and invoke methods on it directly.
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
     * Replace the on-disk package and its metadata without changing the plugin
     * identity. Settings, plugin_data, logs and hooks all refer to the existing
     * ID through foreign keys and are therefore intentionally left untouched.
     *
     * Active plugins have already been required by the time the admin upload
     * endpoint runs, so PHP cannot safely instantiate the replacement class in
     * this request. Keep the old package as a sibling backup and persist a
     * pending-update marker. The next bootstrap runs the new onActivate() before
     * hooks are loaded, then removes the backup; a lifecycle failure restores
     * the old package, metadata and hook rows.
     *
     * @param array<string,mixed> $existingPlugin
     * @param array<string,mixed> $pluginMeta
     * @return array{success:bool,message:string,plugin_id:int|null,updated?:bool}
     */
    private function updatePluginFromStaging(
        array $existingPlugin,
        array $pluginMeta,
        string $stagingPath,
        string $pluginsBaseDir
    ): array {
        $pluginId = (int) ($existingPlugin['id'] ?? 0);
        $directory = (string) ($existingPlugin['path'] ?? '');
        if ($pluginId <= 0 || !$this->isSafePluginDirectoryName($directory)) {
            $this->deleteDirectory($stagingPath);
            return ['success' => false, 'message' => __('Plugin installato non valido.'), 'plugin_id' => null];
        }

        $pluginPath = rtrim($pluginsBaseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $directory;
        try {
            $backupPath = rtrim($pluginsBaseDir, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . '.' . $directory . '.backup-' . bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            $this->deleteDirectory($stagingPath);
            return ['success' => false, 'message' => __('Impossibile preparare l\'aggiornamento del plugin.'), 'plugin_id' => null];
        }
        $hasBackup = false;
        $newPackageInstalled = false;
        $metadataUpdated = false;
        $pendingMarkerPath = null;
        $pendingMarkerHandle = null;

        try {
            $oldPluginSnapshot = $this->pluginMetadataSnapshot($existingPlugin);
            $newPluginSnapshot = $this->pluginMetadataSnapshot($pluginMeta);
            $oldHooks = $this->snapshotPluginHooks($pluginId);
        } catch (\Throwable $e) {
            $this->deleteDirectory($stagingPath);
            return [
                'success' => false,
                'message' => __('Impossibile preparare lo stato dell\'aggiornamento del plugin.'),
                'plugin_id' => null,
            ];
        }

        try {
            if (file_exists($pluginPath) && !is_dir($pluginPath)) {
                throw new \RuntimeException('Il percorso del plugin esistente non è una directory.');
            }

            if (is_dir($pluginPath)) {
                if (!rename($pluginPath, $backupPath)) {
                    throw new \RuntimeException('Impossibile preparare il backup del plugin esistente.');
                }
                $hasBackup = true;
            }

            // Create the deferred-update marker BEFORE the new package becomes
            // visible, not after. It holds an exclusive lock for the rest of this
            // request (released in the finally), so a concurrent bootstrap's
            // finalizePendingPluginUpdates() blocks on it instead of loading the
            // new files against the still-old metadata, hooks and schema — the
            // window between promotion and marker creation is thereby closed.
            // If this request dies before the promotion below, the marker's
            // finalize cannot instantiate the not-yet-moved package
            // (instantiatePlugin throws on the missing main file) and rolls back
            // to the backup — it never activates a half-updated state.
            if ((int) ($existingPlugin['is_active'] ?? 0) === 1) {
                $pendingMarkerPath = $this->pendingPluginUpdatePath($pluginId);
                $pendingState = [
                    'plugin_id' => $pluginId,
                    'plugin_name' => (string) ($existingPlugin['name'] ?? $pluginMeta['name']),
                    'directory' => $directory,
                    'backup_directory' => $hasBackup ? basename($backupPath) : null,
                    'old_plugin' => $oldPluginSnapshot,
                    'new_plugin' => $newPluginSnapshot,
                    'old_hooks' => $oldHooks,
                ];
                $pendingMarkerHandle = $this->createPendingPluginUpdateMarker(
                    $pendingMarkerPath,
                    $pendingState
                );
            }

            if (!rename($stagingPath, $pluginPath)) {
                throw new \RuntimeException('Impossibile sostituire i file del plugin.');
            }
            $newPackageInstalled = true;

            $this->applyPluginMetadataSnapshot($pluginId, $newPluginSnapshot);
            $metadataUpdated = true;

            // Inactive plugins run onActivate() when the administrator enables
            // them, so there is no lifecycle to defer and no backup to retain.
            if ((int) ($existingPlugin['is_active'] ?? 0) !== 1
                && $hasBackup
                && !$this->deleteDirectory($backupPath)
            ) {
                SecureLogger::warning('[PluginManager] Updated plugin backup could not be removed', [
                    'plugin' => $pluginMeta['name'],
                    'path' => $backupPath,
                ]);
            }

            self::clearPluginCache();
            SecureLogger::info("[PluginManager] Plugin updated successfully: {$pluginMeta['name']} (ID: $pluginId)");
            return [
                'success' => true,
                'message' => __('Plugin aggiornato con successo.'),
                'plugin_id' => $pluginId,
                'updated' => true,
            ];
        } catch (\Throwable $e) {
            if (is_resource($pendingMarkerHandle)) {
                if (is_string($pendingMarkerPath)) {
                    @unlink($pendingMarkerPath);
                }
                flock($pendingMarkerHandle, LOCK_UN);
                fclose($pendingMarkerHandle);
                $pendingMarkerHandle = null;
            }
            if ($metadataUpdated) {
                try {
                    $this->applyPluginMetadataSnapshot($pluginId, $oldPluginSnapshot);
                } catch (\Throwable $rollbackError) {
                    SecureLogger::error('[PluginManager] Failed to restore plugin metadata after update rollback', [
                        'plugin' => $pluginMeta['name'],
                        'error' => $rollbackError->getMessage(),
                    ]);
                }
            }
            if ($newPackageInstalled && is_dir($pluginPath)) {
                $this->deleteDirectory($pluginPath);
            }
            if ($hasBackup && is_dir($backupPath) && !rename($backupPath, $pluginPath)) {
                SecureLogger::error('[PluginManager] Failed to restore plugin after update rollback', [
                    'plugin' => $pluginMeta['name'],
                    'path' => $backupPath,
                ]);
            }
            if (is_dir($stagingPath)) {
                $this->deleteDirectory($stagingPath);
            }
            SecureLogger::error('[PluginManager] Plugin update failed', [
                'plugin' => $pluginMeta['name'],
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'message' => __('Errore durante l\'aggiornamento del plugin: %s', $e->getMessage()),
                'plugin_id' => null,
            ];
        } finally {
            if (is_resource($pendingMarkerHandle)) {
                flock($pendingMarkerHandle, LOCK_UN);
                fclose($pendingMarkerHandle);
            }
        }
    }

    /** @return array<string,string> */
    private function pluginMetadataSnapshot(array $plugin): array
    {
        $metadata = $plugin['metadata'] ?? [];
        if (!is_array($metadata)) {
            $decoded = json_decode((string) $metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        return [
            'display_name' => (string) ($plugin['display_name'] ?? $plugin['name'] ?? ''),
            'description' => (string) ($plugin['description'] ?? ''),
            'version' => (string) ($plugin['version'] ?? ''),
            'author' => (string) ($plugin['author'] ?? ''),
            'author_url' => (string) ($plugin['author_url'] ?? ''),
            'plugin_url' => (string) ($plugin['plugin_url'] ?? ''),
            'main_file' => (string) ($plugin['main_file'] ?? ''),
            'requires_php' => (string) ($plugin['requires_php'] ?? ''),
            'requires_app' => (string) ($plugin['requires_app'] ?? ''),
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ];
    }

    /** @param array<string,string> $snapshot */
    private function applyPluginMetadataSnapshot(int $pluginId, array $snapshot): void
    {
        $stmt = $this->db->prepare(
            'UPDATE plugins SET display_name = ?, description = ?, version = ?, author = ?, author_url = ?, '
            . 'plugin_url = ?, main_file = ?, requires_php = ?, requires_app = ?, metadata = ? WHERE id = ?'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Impossibile aggiornare i metadati del plugin.');
        }

        $displayName = $snapshot['display_name'];
        $description = $snapshot['description'];
        $version = $snapshot['version'];
        $author = $snapshot['author'];
        $authorUrl = $snapshot['author_url'];
        $pluginUrl = $snapshot['plugin_url'];
        $mainFile = $snapshot['main_file'];
        $requiresPhp = $snapshot['requires_php'];
        $requiresApp = $snapshot['requires_app'];
        $metadata = $snapshot['metadata'];
        $stmt->bind_param(
            'ssssssssssi',
            $displayName,
            $description,
            $version,
            $author,
            $authorUrl,
            $pluginUrl,
            $mainFile,
            $requiresPhp,
            $requiresApp,
            $metadata,
            $pluginId
        );
        $updated = $stmt->execute();
        $stmt->close();
        if (!$updated) {
            throw new \RuntimeException('Impossibile salvare i metadati aggiornati del plugin.');
        }
    }

    /**
     * @return list<array{hook_name:string,callback_class:string,callback_method:string,priority:int,is_active:int,created_at:string}>
     */
    private function snapshotPluginHooks(int $pluginId): array
    {
        $stmt = $this->db->prepare(
            'SELECT hook_name, callback_class, callback_method, priority, is_active, created_at '
            . 'FROM plugin_hooks WHERE plugin_id = ? ORDER BY id ASC'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Impossibile leggere gli hook del plugin.');
        }
        $stmt->bind_param('i', $pluginId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new \RuntimeException('Impossibile leggere gli hook del plugin.');
        }
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new \RuntimeException('Impossibile leggere gli hook del plugin.');
        }

        $hooks = [];
        while ($row = $result->fetch_assoc()) {
            $hooks[] = [
                'hook_name' => (string) $row['hook_name'],
                'callback_class' => (string) $row['callback_class'],
                'callback_method' => (string) $row['callback_method'],
                'priority' => (int) $row['priority'],
                'is_active' => (int) $row['is_active'],
                'created_at' => (string) $row['created_at'],
            ];
        }
        $stmt->close();
        return $hooks;
    }

    private function pendingPluginUpdatePath(int $pluginId): string
    {
        return $this->pluginsDir . DIRECTORY_SEPARATOR . self::PENDING_UPDATE_PREFIX . $pluginId . '.json';
    }

    /**
     * Write and exclusively lock the marker before changing DB metadata. A
     * concurrent bootstrap waits for the upload request to finish, then sees a
     * complete package plus a complete rollback snapshot.
     *
     * @param array<string,mixed> $state
     * @return resource
     */
    private function createPendingPluginUpdateMarker(string $path, array $state)
    {
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            // The exclusive create failed: a marker already exists (another
            // pending update) or the directory is unwritable. Nothing to clean.
            throw new \RuntimeException('Esiste già un aggiornamento pendente per questo plugin.');
        }
        if (!flock($handle, LOCK_EX)) {
            // fopen('x+b') just created this file. If it cannot be locked, remove
            // the empty marker before throwing: leaving it would block every
            // future update of this plugin (fopen('x+b') keeps failing with
            // "already pending") and the caller's catch cannot clean it up
            // because this method threw before returning the handle.
            fclose($handle);
            @unlink($path);
            throw new \RuntimeException('Impossibile bloccare il marker di aggiornamento del plugin.');
        }

        try {
            $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new \RuntimeException('Impossibile salvare lo stato dell\'aggiornamento del plugin.');
            }
            return $handle;
        } catch (\Throwable $e) {
            @unlink($path);
            flock($handle, LOCK_UN);
            fclose($handle);
            throw $e;
        }
    }

    private function createPluginStagingDirectory(string $pluginsBaseDir, string $directory): ?string
    {
        try {
            $path = rtrim($pluginsBaseDir, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . '.' . $directory . '.staging-' . bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            return null;
        }

        return mkdir($path, 0755, true) ? $path : null;
    }

    private function isSafePluginDirectoryName(string $directory): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]+$/D', $directory);
    }

    private function isSafePluginFilePath(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_contains($path, "\0") || preg_match('#^(?:[A-Za-z]:)?/#', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
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
        if ($stmt === false) {
            SecureLogger::error('[PluginManager] setSetting prepare failed', [
                'plugin_id' => $pluginId,
                'key' => $key,
                'db_error' => $this->db->error,
            ]);
            return false;
        }

        $valueStr = is_array($value) || is_object($value) ? json_encode($value) : (string)$value;

        try {
            $valueStr = $this->encryptPluginSettingValue($valueStr);
            $stmt->bind_param('issi', $pluginId, $key, $valueStr, $autoloadInt);
            $result = $stmt->execute();
        } catch (\Throwable $e) {
            SecureLogger::error('[PluginManager] setSetting failed', [
                'plugin_id' => $pluginId,
                'key'       => $key,
                'error'     => $e->getMessage(),
            ]);
            return false;
        } finally {
            $stmt->close();
        }

        return $result;
    }

    /**
     * Encrypt sensitive plugin setting values before persisting them
     */
    private function encryptPluginSettingValue(string $value): string
    {
        $key = $this->getEncryptionKey();

        // Empty strings bypass encryption (idempotent no-op).
        if ($value === '') {
            return $value;
        }

        // If no encryption key is configured, refuse to persist the secret.
        // Returning plaintext would silently store API tokens unencrypted.
        if ($key === null) {
            SecureLogger::error('[PluginManager] Encryption key unavailable — refusing to persist plaintext plugin setting. Configure PLUGIN_ENCRYPTION_KEY or APP_KEY in .env.');
            throw new \RuntimeException('Plugin encryption key not configured — cannot persist secret setting.');
        }

        try {
            $iv = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

            if ($ciphertext === false) {
                SecureLogger::error('[PluginManager] openssl_encrypt failed — refusing plaintext fallback', [
                    'openssl_error' => openssl_error_string() ?: 'unknown',
                ]);
                throw new \RuntimeException('Plugin setting encryption failed.');
            }

            $payload = base64_encode($iv . $tag . $ciphertext);
            return 'ENC:' . $payload;
        } catch (\RuntimeException $e) {
            // Re-raise our own guards (set above) without wrapping.
            throw $e;
        } catch (\Throwable $e) {
            SecureLogger::error('[PluginManager] Errore durante la cifratura del setting — refusing plaintext fallback', [
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Plugin setting encryption failed: ' . $e->getMessage(), 0, $e);
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
            SecureLogger::warning('[PluginManager] Chiave di cifratura mancante: impossibile decrittare il valore.');
            return null;
        }

        $payload = base64_decode(substr($value, 4), true);
        if ($payload === false || strlen($payload) <= 28) {
            SecureLogger::warning('[PluginManager] Payload cifrato non valido.');
            return null;
        }

        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $ciphertext = substr($payload, 28);

        try {
            $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plaintext === false) {
                SecureLogger::warning('[PluginManager] Impossibile decrittare il valore del plugin setting.');
                return null;
            }
            return $plaintext;
        } catch (\Throwable $e) {
            SecureLogger::warning('[PluginManager] Eccezione durante la decrittazione: ' . $e->getMessage());
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
        // A ZIP upload replaces files after active plugin classes have already
        // been loaded for that request. Complete those upgrades now, in a fresh
        // PHP request, before any active plugin or hook is instantiated.
        $this->finalizePendingPluginUpdates();

        // Bundled-plugin registration and orphan cleanup are maintenance
        // operations: they scan the filesystem and issue several queries, yet
        // their outcome only changes after an update or an admin plugin action.
        // Run them at most once per interval (per app version) instead of on
        // every request; plugin lifecycle methods clear the marker so admin
        // actions re-trigger the sync immediately.
        $maintenanceKey = 'plugins_maintenance_' . self::appVersionStamp();
        if (QueryCache::get($maintenanceKey) === null) {
            // Sync bundled plugin versions and register any new ones
            $this->autoRegisterBundledPlugins();

            // Clean up orphan plugins first
            $this->cleanupOrphanPlugins();

            // The sync above may have registered, upgraded or deactivated
            // plugins: drop the cached plugin/hook payload so this very
            // request (and the next ones) reads the fresh state instead of a
            // pre-maintenance snapshot.
            QueryCache::delete('plugins_active_with_hooks');
            self::$isActiveCache = [];

            QueryCache::set($maintenanceKey, 1, 900);
        }

        // Active plugins + their hook rows, cached across requests. One cache
        // payload replaces 1 + N queries per request (plugin list + one hooks
        // query per plugin).
        $cached = QueryCache::get('plugins_active_with_hooks');
        if (is_array($cached) && isset($cached['plugins'], $cached['hooks'])) {
            $activePlugins = $cached['plugins'];
            $hooksByPlugin = $cached['hooks'];
            $hooksCacheHit = true;
        } else {
            $activePlugins = $this->getActivePlugins();
            $hooksByPlugin = null;
            $hooksCacheHit = false;
        }

        if (empty($activePlugins)) {
            // Prevent HookManager from loading hooks from database
            $this->hookManager->setPluginsLoadedRuntime();
            return;
        }

        // Instantiate before a cache-miss prefetch. Bundled plugins perform
        // hook self-healing from setPluginId(); fetching/caching first would
        // preserve the pre-heal empty snapshot and register no hooks for the
        // current request or the following five minutes.
        $instances = [];
        foreach ($activePlugins as $plugin) {
            $pluginId = (int) $plugin['id'];
            if (isset(self::$skipPluginIdsThisRequest[$pluginId])) {
                // The failed replacement class remains defined until this PHP
                // request ends. The old files are already restored and will be
                // loaded normally by the next request.
                continue;
            }
            try {
                $instances[$pluginId] = $this->instantiatePlugin($plugin);
            } catch (\Throwable $e) {
                SecureLogger::error("[PluginManager] Failed to load plugin '{$plugin['name']}'", ['error' => $e->getMessage()]);
            }
        }

        if (!$hooksCacheHit) {
            $hooksByPlugin = $this->fetchHooksForPlugins(array_map(
                static fn(array $p): int => (int) $p['id'],
                $activePlugins
            ));
            if ($hooksByPlugin !== null) {
                QueryCache::set('plugins_active_with_hooks', [
                    'plugins' => $activePlugins,
                    'hooks' => $hooksByPlugin,
                ], 300);
            }
        }

        foreach ($activePlugins as $plugin) {
            $pluginId = (int) $plugin['id'];
            if (!isset($instances[$pluginId])) {
                continue;
            }

            try {
                // With a healthy prefetch, a plugin without rows gets [] (no
                // hooks); when the prefetch failed ($hooksByPlugin === null)
                // every plugin falls back to its own per-plugin query.
                $prefetchedHooks = $hooksByPlugin[$pluginId] ?? ($hooksByPlugin === null ? null : []);
                if ($prefetchedHooks === null) {
                    $this->registerPluginHooks($pluginId, $instances[$pluginId]);
                } else {
                    $this->registerPluginHookRows($pluginId, $instances[$pluginId], $prefetchedHooks);
                }
            } catch (\Throwable $e) {
                SecureLogger::error("[PluginManager] Failed to register hooks for '{$plugin['name']}'", ['error' => $e->getMessage()]);
            }
        }

        // Prevent HookManager from loading hooks from database
        $this->hookManager->setPluginsLoadedRuntime();
    }

    private function finalizePendingPluginUpdates(): void
    {
        $pattern = $this->pluginsDir . DIRECTORY_SEPARATOR . self::PENDING_UPDATE_PREFIX . '*.json';
        $markers = glob($pattern) ?: [];
        sort($markers);

        foreach ($markers as $markerPath) {
            $state = null;
            $handle = @fopen($markerPath, 'r+b');
            if ($handle === false) {
                // Retry next request rather than retiring the marker: retiring on
                // a transient open failure would silently drop the deferred
                // onActivate (schema migration) this whole mechanism exists to
                // run. Log so a genuinely permanent failure is diagnosable, not
                // silent.
                SecureLogger::warning('[PluginManager] Could not open pending plugin update marker; will retry next request', [
                    'path' => $markerPath,
                ]);
                continue;
            }
            if (!flock($handle, LOCK_EX)) {
                // LOCK_EX blocks on contention, so a false return is a real lock
                // error, not another request finalizing. Same rationale as above:
                // retry (don't drop the deferred lifecycle), but make it loud.
                SecureLogger::warning('[PluginManager] Could not lock pending plugin update marker; will retry next request', [
                    'path' => $markerPath,
                ]);
                fclose($handle);
                continue;
            }

            try {
                // Another request may have completed and unlinked this marker
                // while this request was waiting for its lock.
                if (!is_file($markerPath)) {
                    continue;
                }
                rewind($handle);
                $raw = stream_get_contents($handle);
                $state = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
                if (!is_array($state)) {
                    throw new \RuntimeException('Stato di aggiornamento non valido.');
                }

                $this->finalizePendingPluginUpdate($state);
                @unlink($markerPath);
            } catch (\Throwable $e) {
                if (is_array($state)) {
                    $this->rollbackPendingPluginUpdate($state, $e);
                    @unlink($markerPath);
                } else {
                    SecureLogger::error('[PluginManager] Invalid pending plugin update marker', [
                        'path' => $markerPath,
                        'error' => $e->getMessage(),
                    ]);
                    // An unreadable marker holds no state to roll back, but it
                    // must not brick future updates: createPendingPluginUpdateMarker()
                    // opens with fopen('x+b'), so a leftover marker makes every
                    // later update of this plugin fail with "already pending".
                    // Keep the corrupted file for diagnosis under a name that no
                    // longer matches the *.json marker glob; unlink if that fails.
                    if (!@rename($markerPath, $markerPath . '.invalid-' . time())) {
                        @unlink($markerPath);
                    }
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    /** @param array<string,mixed> $state */
    private function finalizePendingPluginUpdate(array $state): void
    {
        $pluginId = (int) ($state['plugin_id'] ?? 0);
        $directory = (string) ($state['directory'] ?? '');
        $newPlugin = $state['new_plugin'] ?? null;
        if ($pluginId <= 0 || !$this->isSafePluginDirectoryName($directory) || !is_array($newPlugin)) {
            throw new \RuntimeException('Stato di aggiornamento incompleto.');
        }

        $plugin = $this->getPlugin($pluginId);
        if ($plugin === null || (string) ($plugin['path'] ?? '') !== $directory) {
            throw new \RuntimeException('Il plugin aggiornato non è più registrato.');
        }

        // A crash between promoting the package and updating its DB row is
        // recoverable because the marker contains the complete new metadata.
        $this->applyPluginMetadataSnapshot($pluginId, $newPlugin);
        $plugin = $this->getPlugin($pluginId);
        if ($plugin === null) {
            throw new \RuntimeException('Il plugin aggiornato non è più disponibile.');
        }

        if ((int) ($plugin['is_active'] ?? 0) !== 1) {
            self::clearPluginCache();
            $this->deletePendingPluginBackup($state);
            return;
        }

        $instance = $this->instantiatePlugin($plugin);
        if (method_exists($instance, 'onActivate')) {
            $instance->onActivate();
        }

        self::clearPluginCache();
        SecureLogger::info('[PluginManager] Pending plugin update lifecycle completed', [
            'plugin' => (string) ($state['plugin_name'] ?? ''),
            'plugin_id' => $pluginId,
            'version' => (string) ($newPlugin['version'] ?? ''),
        ]);
        // Destructive cleanup is last: any earlier exception can still restore
        // the package from this backup.
        $this->deletePendingPluginBackup($state);
    }

    /** @param array<string,mixed> $state */
    private function deletePendingPluginBackup(array $state): void
    {
        $directory = (string) ($state['directory'] ?? '');
        $backupDirectory = $state['backup_directory'] ?? null;
        if (!is_string($backupDirectory) || $backupDirectory === '') {
            return;
        }
        if (!$this->isSafePluginDirectoryName($directory)
            || !preg_match('/^\.' . preg_quote($directory, '/') . '\.backup-[a-f0-9]{16}$/D', $backupDirectory)
        ) {
            // Best-effort cleanup only: this runs AFTER the update is already
            // applied and (re)activated. Throwing here would propagate into
            // finalizePendingPluginUpdates()'s catch and roll back a committed
            // update — a far worse outcome than an orphaned backup directory.
            SecureLogger::warning('[PluginManager] Unsafe backup path on completed update; leaving backup in place', [
                'directory' => $directory,
                'backup_directory' => $backupDirectory,
            ]);
            return;
        }

        $backupPath = $this->pluginsDir . DIRECTORY_SEPARATOR . $backupDirectory;
        if (is_dir($backupPath) && !$this->deleteDirectory($backupPath)) {
            SecureLogger::warning('[PluginManager] Completed plugin update backup could not be removed', [
                'path' => $backupPath,
            ]);
        }
    }

    /** @param array<string,mixed> $state */
    private function rollbackPendingPluginUpdate(array $state, \Throwable $cause): void
    {
        $pluginId = (int) ($state['plugin_id'] ?? 0);
        $directory = (string) ($state['directory'] ?? '');
        $backupDirectory = $state['backup_directory'] ?? null;
        $oldPlugin = $state['old_plugin'] ?? null;
        $oldHooks = $state['old_hooks'] ?? null;
        $restored = false;

        if ($pluginId > 0
            && $this->isSafePluginDirectoryName($directory)
            && is_string($backupDirectory)
            && preg_match('/^\.' . preg_quote($directory, '/') . '\.backup-[a-f0-9]{16}$/D', $backupDirectory)
            && is_array($oldPlugin)
            && is_array($oldHooks)
        ) {
            $pluginPath = $this->pluginsDir . DIRECTORY_SEPARATOR . $directory;
            $backupPath = $this->pluginsDir . DIRECTORY_SEPARATOR . $backupDirectory;
            if (is_dir($backupPath)) {
                if (is_dir($pluginPath)) {
                    $this->deleteDirectory($pluginPath);
                }
                if (rename($backupPath, $pluginPath)) {
                    try {
                        $this->applyPluginMetadataSnapshot($pluginId, $oldPlugin);
                        $this->restorePluginHooks($pluginId, $oldHooks);
                        self::$skipPluginIdsThisRequest[$pluginId] = true;
                        $restored = true;
                    } catch (\Throwable $rollbackError) {
                        SecureLogger::error('[PluginManager] Pending plugin DB rollback failed', [
                            'plugin_id' => $pluginId,
                            'error' => $rollbackError->getMessage(),
                        ]);
                    }
                }
            }
        }

        if (!$restored && $pluginId > 0) {
            // With no trustworthy old package, fail closed: a broken active
            // plugin must not be required on every request.
            $stmt = $this->db->prepare('UPDATE plugins SET is_active = 0, activated_at = NULL WHERE id = ?');
            if ($stmt !== false) {
                $stmt->bind_param('i', $pluginId);
                $stmt->execute();
                $stmt->close();
            }
        }

        self::clearPluginCache();
        SecureLogger::error('[PluginManager] Plugin lifecycle failed after ZIP update; rollback applied', [
            'plugin' => (string) ($state['plugin_name'] ?? ''),
            'plugin_id' => $pluginId,
            'restored' => $restored,
            'error' => $cause->getMessage(),
        ]);
    }

    /** @param list<array<string,mixed>> $hooks */
    private function restorePluginHooks(int $pluginId, array $hooks): void
    {
        // DELETE + N INSERTs must be atomic: a mid-loop INSERT failure must not
        // leave plugin_hooks with an arbitrary subset of the original rows.
        // Detect an already-open transaction with a savepoint probe — NOT
        // @@autocommit, which stays 1 after begin_transaction() and would let us
        // nest begin_transaction() and implicitly commit the caller's outer
        // transaction. Inside one, scope our work to a savepoint; otherwise own
        // a fresh transaction.
        $inTransaction = $this->hasActiveTransaction();
        // SAVEPOINT reuses (deletes) an existing savepoint of the same name,
        // so a fixed identifier could silently clobber a caller's savepoint
        // boundary. Generate a unique identifier per invocation — savepoint
        // names are identifiers, not bindable params, and hex is injection-safe.
        $savepoint = 'pinakes_sp_' . bin2hex(random_bytes(6));
        if ($inTransaction) {
            $this->db->query('SAVEPOINT ' . $savepoint);
        } else {
            $this->db->begin_transaction();
        }

        try {
            $delete = $this->db->prepare('DELETE FROM plugin_hooks WHERE plugin_id = ?');
            if ($delete === false) {
                throw new \RuntimeException('Impossibile ripristinare gli hook del plugin.');
            }
            $delete->bind_param('i', $pluginId);
            if (!$delete->execute()) {
                $delete->close();
                throw new \RuntimeException('Impossibile ripristinare gli hook del plugin.');
            }
            $delete->close();

            if ($hooks !== []) {
                $insert = $this->db->prepare(
                    'INSERT INTO plugin_hooks '
                    . '(plugin_id, hook_name, callback_class, callback_method, priority, is_active, created_at) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                if ($insert === false) {
                    throw new \RuntimeException('Impossibile ripristinare gli hook del plugin.');
                }

                foreach ($hooks as $hook) {
                    $hookName = (string) ($hook['hook_name'] ?? '');
                    $callbackClass = (string) ($hook['callback_class'] ?? '');
                    $callbackMethod = (string) ($hook['callback_method'] ?? '');
                    $priority = (int) ($hook['priority'] ?? 10);
                    $isActive = (int) ($hook['is_active'] ?? 1);
                    $createdAt = (string) ($hook['created_at'] ?? date('Y-m-d H:i:s'));
                    $insert->bind_param(
                        'isssiis',
                        $pluginId,
                        $hookName,
                        $callbackClass,
                        $callbackMethod,
                        $priority,
                        $isActive,
                        $createdAt
                    );
                    if (!$insert->execute()) {
                        $insert->close();
                        throw new \RuntimeException('Impossibile ripristinare gli hook del plugin.');
                    }
                }
                $insert->close();
            }

            if ($inTransaction) {
                $this->db->query('RELEASE SAVEPOINT ' . $savepoint);
            } else {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($inTransaction) {
                $this->db->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
            } else {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    /**
     * Whether a transaction is already open on the connection. @@autocommit is
     * unreliable here — it stays 1 after begin_transaction() — so probe with a
     * savepoint instead: outside a transaction the SAVEPOINT is a no-op that
     * autocommit discards, so the following RELEASE cannot find it. Works under
     * both mysqli exception and silent error modes.
     */
    private function hasActiveTransaction(): bool
    {
        // Unique per call: SAVEPOINT reuses (deletes) an existing savepoint of
        // the same name, so a fixed probe name could clobber a caller's own
        // savepoint boundary. Hex from random_bytes() is injection-safe.
        $probe = 'pinakes_sp_' . bin2hex(random_bytes(6));
        try {
            if ($this->db->query('SAVEPOINT ' . $probe) === false) {
                return false;
            }
        } catch (\mysqli_sql_exception $e) {
            return false;
        }
        try {
            // Always release the probe after a successful SAVEPOINT so it never
            // lingers inside a caller's transaction. Outside one, autocommit
            // already discarded it and the RELEASE fails — that failure IS the
            // "no transaction" signal.
            return $this->db->query('RELEASE SAVEPOINT ' . $probe) !== false;
        } catch (\mysqli_sql_exception $e) {
            return false;
        }
    }

    /**
     * Invalidate the cross-request plugin caches. Must be called by every
     * plugin lifecycle mutation (install/activate/deactivate/uninstall).
     */
    public static function clearPluginCache(): void
    {
        QueryCache::delete('plugins_active_with_hooks');
        QueryCache::clearByPrefix('plugins_maintenance_');
        self::$isActiveCache = [];
    }

    /**
     * Version stamp used to scope the maintenance marker: a new release (new
     * version.json) invalidates it so bundled plugins re-sync right away.
     */
    private static function appVersionStamp(): string
    {
        $versionFile = __DIR__ . '/../../version.json';
        $mtime = @filemtime($versionFile);
        return $mtime !== false ? (string) $mtime : '0';
    }

    /**
     * Fetch hook rows for the given plugin ids in a single query.
     *
     * @param int[] $pluginIds
     * @return array<int, array<int, array{hook_name:string, callback_method:string, priority:int}>>|null
     *         Hook rows grouped by plugin id, or null when the query could not
     *         run (callers must NOT cache and should fall back to per-plugin
     *         queries).
     */
    private function fetchHooksForPlugins(array $pluginIds): ?array
    {
        if (empty($pluginIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($pluginIds), '?'));
        $stmt = $this->db->prepare("
            SELECT plugin_id, hook_name, callback_method, priority
            FROM plugin_hooks
            WHERE plugin_id IN ({$placeholders})
              AND is_active = 1
            ORDER BY priority ASC
        ");
        if ($stmt === false) {
            SecureLogger::error('[PluginManager] fetchHooksForPlugins prepare failed', ['db_error' => $this->db->error]);
            return null;
        }
        $stmt->bind_param(str_repeat('i', count($pluginIds)), ...$pluginIds);
        if (!$stmt->execute()) {
            SecureLogger::error('[PluginManager] fetchHooksForPlugins execute failed', [
                'stmt_error' => $stmt->error,
                'plugin_count' => count($pluginIds),
            ]);
            $stmt->close();
            return null;
        }
        $result = $stmt->get_result();
        if (!$result instanceof \mysqli_result) {
            SecureLogger::error('[PluginManager] fetchHooksForPlugins get_result failed', [
                'stmt_error' => $stmt->error,
                'plugin_count' => count($pluginIds),
            ]);
            $stmt->close();
            return null;
        }

        $hooksByPlugin = [];
        while ($row = $result->fetch_assoc()) {
            $hooksByPlugin[(int) $row['plugin_id']][] = [
                'hook_name' => (string) $row['hook_name'],
                'callback_method' => (string) $row['callback_method'],
                'priority' => (int) $row['priority'],
            ];
        }
        $stmt->close();

        return $hooksByPlugin;
    }

    /**
     * Register prefetched hook rows on a plugin instance.
     *
     * @param array<int, array{hook_name:string, callback_method:string, priority:int}> $hookRows
     */
    private function registerPluginHookRows(int $pluginId, object $pluginInstance, array $hookRows): void
    {
        foreach ($hookRows as $row) {
            $callbackMethod = $row['callback_method'];
            if (method_exists($pluginInstance, $callbackMethod) || $this->hasMagicMethod($pluginInstance, $callbackMethod)) {
                $this->hookManager->addHook($row['hook_name'], [$pluginInstance, $callbackMethod], $row['priority']);
            } elseif ($this->disableOrphanHook($pluginId, $row['hook_name'], $callbackMethod)) {
                SecureLogger::warning("[PluginManager] Disabled orphan hook '{$row['hook_name']}' → {$callbackMethod} (method missing on plugin class; likely dropped by a plugin upgrade — row kept, re-enabled on re-activation)");
            }
        }
    }

    /**
     * Disable a hook row whose callback method no longer exists on the plugin
     * class. Upgrades replace plugin files but do NOT re-run a plugin's
     * onActivate() (which resyncs hooks via deleteHooks + reinsert), so a hook
     * registered by an OLD plugin version and dropped in a NEW one lingers in
     * plugin_hooks and logs "Method not found" on every request.
     *
     * The row is DISABLED (is_active = 0), not deleted: the "method missing"
     * signal is also what a partially-deployed upgrade or a transient class-load
     * glitch would produce, and destroying the registration would be
     * unrecoverable without a full re-activation. Disabling stops the loader from
     * re-loading it (both hook SELECTs filter is_active = 1) — the same "stop
     * logging every request" outcome — while keeping the row for audit; a
     * legitimate re-activation (deleteHooks + reinsert) cleans or restores it.
     * Reached only for an already-instantiated plugin, so the class did load.
     *
     * Returns true only when a row actually flipped 1 -> 0, so the caller logs
     * once. Concurrent requests are safe: the WHERE is_active = 1 clause is
     * re-evaluated under the row lock, so at most one request sees the
     * transition (a cached hook list can re-present the orphan for up to the
     * QueryCache TTL; the UPDATE then matches zero rows silently).
     */
    private function disableOrphanHook(int $pluginId, string $hookName, string $callbackMethod): bool
    {
        $stmt = $this->db->prepare('UPDATE plugin_hooks SET is_active = 0 WHERE plugin_id = ? AND hook_name = ? AND callback_method = ? AND is_active = 1');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('iss', $pluginId, $hookName, $callbackMethod);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected > 0;
    }

    private function instantiatePlugin(array $plugin): object
    {
        // Save plugin data to prefixed variables before require_once
        // This prevents plugin files from overwriting $plugin variable (which some do)
        $_pluginId = (int) $plugin['id'];
        $_pluginName = $plugin['name'];
        $_pluginPath = $this->pluginsDir . '/' . $plugin['path'];
        $_mainFile = $_pluginPath . '/' . $plugin['main_file'];

        if (!file_exists($_mainFile)) {
            throw new \Exception("Main file not found: {$_mainFile}");
        }

        require_once $_mainFile;

        $className = $this->getPluginClassName($_pluginName);
        if (!class_exists($className)) {
            throw new \Exception("Plugin class not found: {$className}");
        }

        $instance = new $className($this->db, $this->hookManager);

        if (is_callable([$instance, 'setPluginId'])) {
            try {
                $instance->setPluginId($_pluginId);
            } catch (\Throwable $e) {
                SecureLogger::warning("[PluginManager] setPluginId failed for {$_pluginName}", [
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $instance;
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
              AND is_active = 1
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
            } elseif ($this->disableOrphanHook($pluginId, $hookName, $callbackMethod)) {
                SecureLogger::warning("[PluginManager] Disabled orphan hook '{$hookName}' → {$callbackMethod} (method missing on plugin class; likely dropped by a plugin upgrade — row kept, re-enabled on re-activation)");
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
                // No setAccessible(): it is a no-op since PHP 8.1 and emits a
                // deprecation notice under PHP 8.5 that pollutes page output.
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

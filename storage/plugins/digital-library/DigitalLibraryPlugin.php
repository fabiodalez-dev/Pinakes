<?php
declare(strict_types=1);

/**
 * Digital Library Plugin
 *
 * Enables eBook (PDF/ePub) and audiobook management with integrated
 * Green Audio Player for audiobook playback.
 *
 * Features:
 * - File upload via Uppy (reusing existing infrastructure)
 * - eBook download buttons (PDF/ePub)
 * - Audiobook player with Green Audio Player
 * - Status badge icons showing digital content availability
 * - Optional and fully disableable
 *
 * @package Pinakes\Plugins\DigitalLibrary
 * @version 1.0.0
 */
class DigitalLibraryPlugin
{
    private ?\mysqli $db = null;
    private ?object $hookManager = null;
    private int $pluginId = 0;
    private array $settings = [];

    /**
     * Constructor
     *
     * @param \mysqli|null $db Database connection
     * @param object|null $hookManager Hook manager instance
     */
    public function __construct(?\mysqli $db = null, ?object $hookManager = null)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;
    }

    /**
     * Set plugin ID (called by PluginManager after installation)
     */
    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
        $this->loadSettings();
        $this->registerHooks();
    }

    /**
     * Load plugin settings from database
     */
    private function loadSettings(): void
    {
        if (!$this->db || $this->pluginId === 0) {
            return;
        }

        $stmt = $this->db->prepare("
            SELECT setting_key, setting_value
            FROM plugin_settings
            WHERE plugin_id = ?
        ");
        $stmt->bind_param("i", $this->pluginId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $this->settings[$row['setting_key']] = $row['setting_value'];
        }

        $stmt->close();
    }

    /**
     * Plugin activation hook
     */
    public function onActivate(): void
    {
        // Create digital uploads directory if it doesn't exist
        $uploadsDir = __DIR__ . '/../../../public/uploads/digital';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }

        // Create .htaccess for security
        $htaccess = $uploadsDir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "# Protect directory listing\nOptions -Indexes\n");
        }

        // Register hooks in database
        $this->registerHooks();
    }

    /**
     * Plugin deactivation hook
     */
    public function onDeactivate(): void
    {
        // Remove registered hooks from database
        if (!$this->db || $this->pluginId === 0) {
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM plugin_hooks WHERE plugin_id = ?");
        $stmt->bind_param("i", $this->pluginId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Plugin installation hook
     */
    public function onInstall(): void
    {
        // Verify database columns exist
        if (!$this->db) {
            return;
        }

        $result = $this->db->query("SHOW COLUMNS FROM libri LIKE 'file_url'");
        if ($result->num_rows === 0) {
            // Add file_url column if missing
            $this->db->query("ALTER TABLE libri ADD COLUMN file_url VARCHAR(500) DEFAULT NULL COMMENT 'eBook file URL' AFTER note_varie");
        }

        $result = $this->db->query("SHOW COLUMNS FROM libri LIKE 'audio_url'");
        if ($result->num_rows === 0) {
            // Add audio_url column if missing
            $this->db->query("ALTER TABLE libri ADD COLUMN audio_url VARCHAR(500) DEFAULT NULL COMMENT 'Audiobook file URL' AFTER file_url");
        }
    }

    /**
     * Plugin uninstallation hook
     */
    public function onUninstall(): void
    {
        // Clean up hooks
        $this->onDeactivate();

        // Note: We don't drop the columns as they might contain data
        // Administrator can manually remove them if needed
    }

    /**
     * Register plugin hooks in database
     */
    private function registerHooks(): void
    {
        if (!$this->db || $this->pluginId === 0) {
            return;
        }

        error_log("[DigitalLibrary] registerHooks called for plugin ID {$this->pluginId}");

        $hooks = [
            [
                'hook_name' => 'book.form.digital_fields',
                'callback_class' => 'DigitalLibraryPlugin',
                'callback_method' => 'renderAdminFormFields',
                'priority' => 10,
                'is_active' => 1
            ],
            [
                'hook_name' => 'book.detail.digital_buttons',
                'callback_class' => 'DigitalLibraryPlugin',
                'callback_method' => 'renderFrontendButtons',
                'priority' => 10,
                'is_active' => 1
            ],
            [
                'hook_name' => 'book.detail.digital_player',
                'callback_class' => 'DigitalLibraryPlugin',
                'callback_method' => 'renderAudioPlayer',
                'priority' => 10,
                'is_active' => 1
            ],
            [
                'hook_name' => 'book.badge.digital_icons',
                'callback_class' => 'DigitalLibraryPlugin',
                'callback_method' => 'renderBadgeIcons',
                'priority' => 10,
                'is_active' => 1
            ],
            [
                'hook_name' => 'assets.head',
                'callback_class' => 'DigitalLibraryPlugin',
                'callback_method' => 'enqueueAssets',
                'priority' => 10,
                'is_active' => 1
            ]
        ];

        foreach ($hooks as $hook) {
            // Check if hook already exists
            $stmt = $this->db->prepare("
                SELECT id FROM plugin_hooks
                WHERE plugin_id = ? AND hook_name = ?
            ");
            $stmt->bind_param("is", $this->pluginId, $hook['hook_name']);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                // Insert new hook
                $stmt = $this->db->prepare("
                    INSERT INTO plugin_hooks
                    (plugin_id, hook_name, callback_class, callback_method, priority, is_active)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    "isssii",
                    $this->pluginId,
                    $hook['hook_name'],
                    $hook['callback_class'],
                    $hook['callback_method'],
                    $hook['priority'],
                    $hook['is_active']
                );
                $stmt->execute();
            }

            $stmt->close();
        }
    }

    // ========================================================================
    // Hook Callback Methods
    // ========================================================================

    /**
     * Render admin form fields for digital content upload
     * Hook: book.form.digital_fields
     */
    public function renderAdminFormFields(array $book): void
    {
        include __DIR__ . '/views/admin-form-fields.php';
    }

    /**
     * Render frontend download buttons
     * Hook: book.detail.digital_buttons
     */
    public function renderFrontendButtons(array $book): void
    {
        include __DIR__ . '/views/frontend-buttons.php';
    }

    /**
     * Render audio player
     * Hook: book.detail.digital_player
     */
    public function renderAudioPlayer(array $book): void
    {
        if ($this->hasAudiobook($book)) {
            include __DIR__ . '/views/frontend-player.php';
        }
    }

    /**
     * Render badge icons
     * Hook: book.badge.digital_icons
     */
    public function renderBadgeIcons(array $book): void
    {
        include __DIR__ . '/views/badge-icons.php';
    }

    /**
     * Enqueue CSS assets
     * Hook: assets.head
     */
    public function enqueueAssets(): void
    {
        // Green Audio Player CSS (local with CDN fallback)
        $localCSS = '/assets/vendor/green-audio-player/css/green-audio-player.min.css';
        $cdnCSS = 'https://cdn.jsdelivr.net/gh/greghub/green-audio-player/dist/css/green-audio-player.min.css';

        $cssPath = file_exists($_SERVER['DOCUMENT_ROOT'] . $localCSS) ? $localCSS : $cdnCSS;

        echo '<link rel="stylesheet" href="' . htmlspecialchars($cssPath, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        echo '<link rel="stylesheet" href="/plugins/digital-library/assets/css/digital-library.css">' . "\n";

        // Green Audio Player JS (local with CDN fallback)
        $localJS = '/assets/vendor/green-audio-player/js/green-audio-player.min.js';
        $cdnJS = 'https://cdn.jsdelivr.net/gh/greghub/green-audio-player/dist/js/green-audio-player.min.js';

        $jsPath = file_exists($_SERVER['DOCUMENT_ROOT'] . $localJS) ? $localJS : $cdnJS;

        echo '<script src="' . htmlspecialchars($jsPath, ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    /**
     * Check if book has eBook file
     */
    private function hasEbook(array $book): bool
    {
        return !empty($book['file_url'] ?? '');
    }

    /**
     * Check if book has audiobook file
     */
    private function hasAudiobook(array $book): bool
    {
        return !empty($book['audio_url'] ?? '');
    }

    /**
     * Get safe file URL
     */
    private function getSafeUrl(?string $url): string
    {
        if (empty($url)) {
            return '';
        }
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}

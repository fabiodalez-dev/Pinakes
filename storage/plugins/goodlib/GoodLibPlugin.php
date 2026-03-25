<?php
declare(strict_types=1);

/**
 * GoodLib Plugin — External Sources
 *
 * Adds clickable badges to book detail pages for searching books
 * on Anna's Archive, Z-Library, and Project Gutenberg.
 *
 * Inspired by the GoodLib browser extension.
 *
 * @package Pinakes\Plugins\GoodLib
 * @version 1.0.0
 */
class GoodLibPlugin
{
    private ?\mysqli $db = null;
    private ?object $hookManager = null;
    private int $pluginId = 0;

    /** @var array<string, array{icon: string, url_pattern: string, default_domain: string, mirrors: list<string>}> */
    private const SOURCES = [
        'anna' => [
            'icon' => 'fas fa-book-open',
            'url_pattern' => 'https://%s/search?q=%s',
            'default_domain' => 'annas-archive.gd',
            'mirrors' => ['annas-archive.gd', 'annas-archive.gl', 'annas-archive.pk'],
        ],
        'zlib' => [
            'icon' => 'fas fa-search',
            'url_pattern' => 'https://%s/s/%s',
            'default_domain' => 'z-lib.gd',
            'mirrors' => ['z-lib.gd', 'z-lib.gl', 'z-lib.fm', '1lib.sk', 'z-library.ec', 'zliba.ru'],
        ],
        'gutenberg' => [
            'icon' => 'fas fa-feather-alt',
            'url_pattern' => 'https://%s/ebooks/search/?query=%s',
            'default_domain' => 'www.gutenberg.org',
            'mirrors' => ['www.gutenberg.org'],
        ],
    ];

    /**
     * @phpstan-ignore constructor.unusedParameter
     */
    public function __construct(?\mysqli $db = null, ?object $hookManager = null)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;
    }

    public function setPluginId(int $pluginId): void
    {
        $this->pluginId = $pluginId;
        $this->registerHooks();
    }

    public function onActivate(): void
    {
        $this->registerHooks();

        // Set default settings: all sources enabled, show in both frontend and admin
        if ($this->db && $this->pluginId > 0) {
            if ($this->dbGetSetting('anna_enabled') === null) {
                $this->dbSetSetting('anna_enabled', '1');
                $this->dbSetSetting('zlib_enabled', '1');
                $this->dbSetSetting('gutenberg_enabled', '1');
                $this->dbSetSetting('show_frontend', '1');
                $this->dbSetSetting('show_admin', '1');
                $this->dbSetSetting('anna_domain', self::SOURCES['anna']['default_domain']);
                $this->dbSetSetting('zlib_domain', self::SOURCES['zlib']['default_domain']);
            }
        }
    }

    public function onDeactivate(): void
    {
        if (!$this->db || $this->pluginId === 0) {
            return;
        }
        $stmt = $this->db->prepare("DELETE FROM plugin_hooks WHERE plugin_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $this->pluginId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * @phpstan-ignore method.unused
     */
    public function onInstall(): void
    {
        // No DB schema needed
    }

    /**
     * @phpstan-ignore method.unused
     */
    public function onUninstall(): void
    {
        if (!$this->db || $this->pluginId === 0) {
            return;
        }
        $this->onDeactivate();
        $stmt = $this->db->prepare("DELETE FROM plugin_settings WHERE plugin_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $this->pluginId);
            $stmt->execute();
            $stmt->close();
        }
    }

    // ─── Direct DB helpers for plugin_settings ──────────────────────────

    private function dbGetSetting(string $key): ?string
    {
        if (!$this->db || $this->pluginId === 0) {
            return null;
        }

        if ($this->hookManager !== null) {
            try {
                $pm = new \App\Support\PluginManager($this->db, $this->hookManager);
                $value = $pm->getSetting($this->pluginId, $key);
                return $value !== null ? (string) $value : null;
            } catch (\Throwable $e) {
                // Fall back to direct DB access
            }
        }

        $stmt = $this->db->prepare("SELECT setting_value FROM plugin_settings WHERE plugin_id = ? AND setting_key = ?");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('is', $this->pluginId, $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row ? (string) $row['setting_value'] : null;
    }

    private function dbGetAllSettings(): array
    {
        if (!$this->db || $this->pluginId === 0) {
            return [];
        }

        // Use PluginManager to read settings (handles decryption transparently)
        if ($this->hookManager !== null) {
            try {
                $pm = new \App\Support\PluginManager($this->db, $this->hookManager);
                return $pm->getSettings($this->pluginId);
            } catch (\Throwable $e) {
                // Fall through to direct DB query
            }
        }

        // Fallback: direct DB query (unencrypted values only)
        $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM plugin_settings WHERE plugin_id = ?");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $this->pluginId);
        $stmt->execute();
        $result = $stmt->get_result();
        $settings = [];
        while ($row = $result->fetch_assoc()) {
            $val = (string) $row['setting_value'];
            // Skip encrypted values we can't decrypt without PluginManager
            if (str_starts_with($val, 'ENC:')) {
                continue;
            }
            $settings[(string) $row['setting_key']] = $val;
        }
        $stmt->close();
        return $settings;
    }

    private function dbSetSetting(string $key, string $value): void
    {
        if (!$this->db || $this->pluginId === 0) {
            return;
        }

        if ($this->hookManager !== null) {
            try {
                $pm = new \App\Support\PluginManager($this->db, $this->hookManager);
                $pm->setSetting($this->pluginId, $key, $value, true);
                return;
            } catch (\Throwable $e) {
                // Fall back to direct DB write
            }
        }

        $stmt = $this->db->prepare("
            INSERT INTO plugin_settings (plugin_id, setting_key, setting_value, autoload, created_at)
            VALUES (?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('iss', $this->pluginId, $key, $value);
        $stmt->execute();
        $stmt->close();
    }

    private static function normalizeDomain(string $value, string $defaultDomain): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return $defaultDomain;
        }

        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
            $value = 'https://' . $value;
        }

        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        $host = strtolower(trim((string) $parts['host']));
        if (
            $host === ''
            || !preg_match(
                '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/i',
                $host
            )
        ) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return $host . $port;
    }

    private static function sourceLabel(string $key): string
    {
        return match ($key) {
            'anna' => __("Anna's Archive"),
            'zlib' => __("Z-Library"),
            'gutenberg' => __("Project Gutenberg"),
            default => $key,
        };
    }

    // ─── Hook registration ──────────────────────────────────────────────

    private function registerHooks(): void
    {
        if (!$this->db || $this->pluginId === 0) {
            return;
        }

        $hooks = [
            [
                'hook_name' => 'book.detail.digital_buttons',
                'callback_class' => 'GoodLibPlugin',
                'callback_method' => 'renderFrontendBadges',
                'priority' => 20,
                'is_active' => 1,
            ],
            [
                'hook_name' => 'book.admin.external_links',
                'callback_class' => 'GoodLibPlugin',
                'callback_method' => 'renderAdminBadges',
                'priority' => 10,
                'is_active' => 1,
            ],
        ];

        foreach ($hooks as $hook) {
            $stmt = $this->db->prepare("
                INSERT INTO plugin_hooks
                (plugin_id, hook_name, callback_class, callback_method, priority, is_active)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    priority  = VALUES(priority),
                    is_active = VALUES(is_active)
            ");
            if (!$stmt) {
                continue;
            }
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
            $stmt->close();
        }
    }

    // ─── Settings ───────────────────────────────────────────────────────

    /**
     * @return array{anna_enabled: bool, zlib_enabled: bool, gutenberg_enabled: bool, show_frontend: bool, show_admin: bool, anna_domain: string, zlib_domain: string, gutenberg_domain: string}
     */
    private function getSettings(): array
    {
        $defaults = [
            'anna_enabled' => true,
            'zlib_enabled' => true,
            'gutenberg_enabled' => true,
            'show_frontend' => true,
            'show_admin' => true,
            'anna_domain' => self::SOURCES['anna']['default_domain'],
            'zlib_domain' => self::SOURCES['zlib']['default_domain'],
            'gutenberg_domain' => self::SOURCES['gutenberg']['default_domain'],
        ];

        try {
            $all = $this->dbGetAllSettings();
        } catch (\Throwable $e) {
            return $defaults;
        }

        if (empty($all)) {
            return $defaults;
        }

        return [
            'anna_enabled' => ($all['anna_enabled'] ?? '1') === '1',
            'zlib_enabled' => ($all['zlib_enabled'] ?? '1') === '1',
            'gutenberg_enabled' => ($all['gutenberg_enabled'] ?? '1') === '1',
            'show_frontend' => ($all['show_frontend'] ?? '1') === '1',
            'show_admin' => ($all['show_admin'] ?? '1') === '1',
            'anna_domain' => !empty($all['anna_domain']) ? (string) $all['anna_domain'] : self::SOURCES['anna']['default_domain'],
            'zlib_domain' => !empty($all['zlib_domain']) ? (string) $all['zlib_domain'] : self::SOURCES['zlib']['default_domain'],
            'gutenberg_domain' => self::SOURCES['gutenberg']['default_domain'],
        ];
    }

    /**
     * Get enabled sources with resolved URLs (domain from settings or default).
     *
     * @return array<string, array{label: string, icon: string, url: string}>
     */
    private function getEnabledSources(): array
    {
        $settings = $this->getSettings();
        $sources = [];

        foreach (self::SOURCES as $key => $source) {
            $settingKey = $key . '_enabled';
            if ($settings[$settingKey]) {
                $domainKey = $key . '_domain';
                $domain = $settings[$domainKey];

                $sources[$key] = [
                    'label' => self::sourceLabel($key),
                    'icon' => $source['icon'],
                    'url' => sprintf($source['url_pattern'], $domain, '%s'),
                ];
            }
        }

        return $sources;
    }

    // ─── Search query builder ───────────────────────────────────────────

    private function buildSearchQuery(array $bookData, string $context = 'frontend'): string
    {
        $title = trim((string) ($bookData['titolo'] ?? ''));

        $author = '';
        if ($context === 'admin') {
            $autori = $bookData['autori'] ?? [];
            if (is_array($autori) && !empty($autori)) {
                $author = trim((string) ($autori[0]['nome'] ?? ''));
            }
        } else {
            $author = trim((string) ($bookData['autore_principale'] ?? ''));
        }

        return trim("$title $author");
    }

    // ─── Renderers ──────────────────────────────────────────────────────

    /**
     * Render badges for frontend book detail.
     * Called via do_action('book.detail.digital_buttons', $book)
     */
    public function renderFrontendBadges(array $book): void
    {
        $settings = $this->getSettings();
        if (!$settings['show_frontend']) {
            return;
        }

        $sources = $this->getEnabledSources();
        if (empty($sources)) {
            return;
        }

        $query = $this->buildSearchQuery($book, 'frontend');
        if ($query === '') {
            return;
        }

        require __DIR__ . '/views/badges.php';
    }

    /**
     * Render badges for admin book detail (scheda libro).
     * Called via do_action('book.admin.external_links', $libro)
     */
    public function renderAdminBadges(array $libro): void
    {
        $settings = $this->getSettings();
        if (!$settings['show_admin']) {
            return;
        }

        $sources = $this->getEnabledSources();
        if (empty($sources)) {
            return;
        }

        $query = $this->buildSearchQuery($libro, 'admin');
        if ($query === '') {
            return;
        }

        $context = 'admin';
        require __DIR__ . '/views/badges.php';
    }

    /**
     * Render settings HTML for the plugin admin page.
     */
    public function renderSettingsHtml(): string
    {
        $settings = $this->getSettings();
        ob_start();
        require __DIR__ . '/views/settings.php';
        return ob_get_clean() ?: '';
    }

    /**
     * Save settings from POST data.
     */
    public function saveSettings(array $data): bool
    {
        if (!$this->db || $this->pluginId === 0) {
            return false;
        }

        // Boolean toggles
        $keys = ['anna_enabled', 'zlib_enabled', 'gutenberg_enabled', 'show_frontend', 'show_admin'];
        foreach ($keys as $key) {
            $value = isset($data[$key]) && $data[$key] === '1' ? '1' : '0';
            $this->dbSetSetting($key, $value);
        }

        // Domain settings — allow presets and validated custom domains
        $domainKeys = ['anna_domain', 'zlib_domain'];
        foreach ($domainKeys as $domainKey) {
            if (isset($data[$domainKey])) {
                $sourceKey = str_replace('_domain', '', $domainKey);
                $defaultDomain = self::SOURCES[$sourceKey]['default_domain'] ?? '';
                $domain = self::normalizeDomain((string) $data[$domainKey], $defaultDomain);
                if ($domain !== null) {
                    $this->dbSetSetting($domainKey, $domain);
                }
            }
        }

        return true;
    }

    /**
     * Get available mirrors for use in views.
     *
     * @return array<string, list<string>>
     */
    public static function getMirrors(): array
    {
        return [
            'anna' => self::SOURCES['anna']['mirrors'],
            'zlib' => self::SOURCES['zlib']['mirrors'],
        ];
    }
}

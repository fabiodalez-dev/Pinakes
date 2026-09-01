<?php
declare(strict_types=1);

namespace App\Support;

/**
 * ThemeManager
 *
 * Manages theme activation, settings, and colors.
 * Provides methods to retrieve active theme and update theme configuration.
 */
class ThemeManager
{
    public const DEFAULT_LAYOUT_VARIANT = 'editorial';

    public const LAYOUT_VARIANTS = [
        'editorial',
        'workspace',
        'command',
        'soft',
    ];

    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Get the currently active theme
     *
     * @return array|null Theme data or null if no active theme
     */
    /** Per-process memo of the active theme (row or null). */
    private static array $activeThemeMemo = [];

    public function getActiveTheme(): ?array
    {
        // The active theme is needed by every public page render; memoize it
        // per request and cache it across requests (invalidated by every
        // theme mutation below).
        if (array_key_exists('theme', self::$activeThemeMemo)) {
            return self::$activeThemeMemo['theme'];
        }

        $cached = QueryCache::get('active_theme_row');
        if (is_array($cached) && array_key_exists('theme', $cached)) {
            self::$activeThemeMemo['theme'] = $cached['theme'];
            return $cached['theme'];
        }

        $stmt = $this->db->prepare("SELECT * FROM themes WHERE active = 1 LIMIT 1");
        if (!$stmt) {
            error_log("ThemeManager: Failed to prepare statement - " . $this->db->error);
            return null;
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $theme = $result->fetch_assoc();
        $stmt->close();

        $theme = $theme ?: null;
        self::$activeThemeMemo['theme'] = $theme;
        QueryCache::set('active_theme_row', ['theme' => $theme], 300);

        return $theme;
    }

    /**
     * Invalidate the active-theme caches; called by every theme mutation.
     */
    public static function clearThemeCache(): void
    {
        self::$activeThemeMemo = [];
        QueryCache::delete('active_theme_row');
    }

    /**
     * Get all installed themes
     *
     * @return array List of all themes
     */
    public function getAllThemes(): array
    {
        $result = $this->db->query("SELECT * FROM themes ORDER BY active DESC, name ASC");
        if (!$result) {
            error_log("ThemeManager: Failed to get themes - " . $this->db->error);
            return [];
        }

        $themes = [];
        while ($row = $result->fetch_assoc()) {
            $themes[] = $row;
        }

        return $themes;
    }

    /**
     * Get a specific theme by ID
     *
     * @param int $themeId
     * @return array|null Theme data or null if not found
     */
    public function getThemeById(int $themeId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM themes WHERE id = ?");
        if (!$stmt) {
            error_log("ThemeManager: Failed to prepare statement - " . $this->db->error);
            return null;
        }

        $stmt->bind_param('i', $themeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $theme = $result->fetch_assoc();
        $stmt->close();

        return $theme ?: null;
    }

    /**
     * Activate a theme (deactivates all others)
     *
     * @param int $themeId ID of theme to activate
     * @return bool Success status
     */
    public function activateTheme(int $themeId): bool
    {
        $this->db->begin_transaction();

        try {
            // Deactivate all themes
            $this->db->query("UPDATE themes SET active = 0");

            // Activate the selected theme
            $stmt = $this->db->prepare("UPDATE themes SET active = 1 WHERE id = ?");
            if (!$stmt) {
                throw new \Exception("Failed to prepare activate statement: " . $this->db->error);
            }

            $stmt->bind_param('i', $themeId);
            $success = $stmt->execute();
            $affectedRows = $stmt->affected_rows;
            $stmt->close();

            if (!$success) {
                throw new \Exception("Failed to activate theme");
            }

            // No row matched: the theme id does not exist. Roll back so the
            // previously active theme survives instead of leaving none active.
            if ($affectedRows < 1) {
                throw new \Exception("Theme not found: id {$themeId}");
            }

            $this->db->commit();
            self::clearThemeCache();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            error_log("ThemeManager: Error activating theme - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update theme colors
     *
     * @param int $themeId Theme ID
     * @param array $colors Color configuration ['primary' => '#xxx', 'secondary' => '#xxx', ...]
     * @param string|null $layoutVariant Validated public layout to persist in
     *        the same JSON update, avoiding an additional admin-save query.
     * @return bool Success status
     */
    public function updateThemeColors(int $themeId, array $colors, ?string $layoutVariant = null): bool
    {
        if ($layoutVariant !== null && !in_array($layoutVariant, self::LAYOUT_VARIANTS, true)) {
            return false;
        }

        // Get current settings
        $stmt = $this->db->prepare("SELECT settings FROM themes WHERE id = ?");
        if (!$stmt) {
            error_log("ThemeManager: Failed to prepare statement - " . $this->db->error);
            return false;
        }

        $stmt->bind_param('i', $themeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $theme = $result->fetch_assoc();
        $stmt->close();

        if (!$theme) {
            error_log("ThemeManager: Theme not found - ID: $themeId");
            return false;
        }

        // Decode current settings
        $settings = json_decode($theme['settings'], true) ?? [];

        // Update colors
        $settings['colors'] = $colors;
        if ($layoutVariant !== null) {
            $settings['layout_variant'] = $layoutVariant;
        }

        // Encode back to JSON
        $settingsJson = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Update database
        $stmt = $this->db->prepare("UPDATE themes SET settings = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        if (!$stmt) {
            error_log("ThemeManager: Failed to prepare update statement - " . $this->db->error);
            return false;
        }

        $stmt->bind_param('si', $settingsJson, $themeId);
        $success = $stmt->execute();
        $stmt->close();

        if (!$success) {
            error_log("ThemeManager: Failed to update theme colors - " . $this->db->error);
        } else {
            self::clearThemeCache();
        }

        return $success;
    }

    /**
     * Update theme advanced settings (custom CSS/JS)
     *
     * @param int $themeId Theme ID
     * @param array $advanced Advanced settings ['custom_css' => '...', 'custom_js' => '...']
     * @return bool Success status
     */
    public function updateAdvancedSettings(int $themeId, array $advanced): bool
    {
        // Get current settings
        $stmt = $this->db->prepare("SELECT settings FROM themes WHERE id = ?");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $themeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $theme = $result->fetch_assoc();
        $stmt->close();

        if (!$theme) {
            return false;
        }

        // Decode current settings
        $settings = json_decode($theme['settings'], true) ?? [];

        // Update advanced
        $settings['advanced'] = $advanced;

        // Encode back to JSON
        $settingsJson = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Update database
        $stmt = $this->db->prepare("UPDATE themes SET settings = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('si', $settingsJson, $themeId);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            self::clearThemeCache();
        }

        return $success;
    }

    /**
     * Persist the public layout independently from the color palette.
     * Every variant keeps the same views, CMS fields and frontend behavior.
     */
    public function updateLayoutVariant(int $themeId, string $variant): bool
    {
        if (!in_array($variant, self::LAYOUT_VARIANTS, true)) {
            return false;
        }

        $stmt = $this->db->prepare("SELECT settings FROM themes WHERE id = ?");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $themeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $theme = $result->fetch_assoc();
        $stmt->close();

        if (!$theme) {
            return false;
        }

        $settings = json_decode($theme['settings'], true) ?? [];
        $settings['layout_variant'] = $variant;
        $settingsJson = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($settingsJson === false) {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE themes SET settings = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('si', $settingsJson, $themeId);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            self::clearThemeCache();
        }

        return $success;
    }

    public function getLayoutVariant(?array $theme = null): string
    {
        if ($theme === null) {
            $theme = $this->getActiveTheme();
        }

        $settings = $theme && !empty($theme['settings'])
            ? (json_decode($theme['settings'], true) ?? [])
            : [];
        $variant = (string) ($settings['layout_variant'] ?? self::DEFAULT_LAYOUT_VARIANT);

        return in_array($variant, self::LAYOUT_VARIANTS, true)
            ? $variant
            : self::DEFAULT_LAYOUT_VARIANT;
    }

    /**
     * Reset theme colors to defaults
     *
     * @param int $themeId Theme ID
     * @return bool Success status
     */
    public function resetThemeColors(int $themeId): bool
    {
        $defaultColors = [
            'primary' => '#d70161',
            'secondary' => '#111827',
            'button' => '#d70262',
            'button_text' => '#ffffff'
        ];

        return $this->updateThemeColors($themeId, $defaultColors);
    }

    /**
     * Get theme colors with fallback to defaults
     *
     * @param array|null $theme Theme data (optional, will fetch active if null)
     * @return array Color configuration
     */
    public function getThemeColors(?array $theme = null): array
    {
        if ($theme === null) {
            $theme = $this->getActiveTheme();
        }

        if (!$theme || empty($theme['settings'])) {
            // Return default colors if no theme configured
            return [
                'primary' => '#d70161',
                'secondary' => '#111827',
                'button' => '#d70262',
                'button_text' => '#ffffff'
            ];
        }

        $settings = json_decode($theme['settings'], true) ?? [];
        $colors = $settings['colors'] ?? [];

        // Ensure all required colors exist with fallbacks
        return [
            'primary' => $colors['primary'] ?? '#d70161',
            'secondary' => $colors['secondary'] ?? '#111827',
            'button' => $colors['button'] ?? '#d70262',
            'button_text' => $colors['button_text'] ?? '#ffffff'
        ];
    }

    /**
     * Get advanced settings (custom CSS/JS)
     *
     * @param array|null $theme Theme data (optional, will fetch active if null)
     * @return array Advanced settings
     */
    public function getAdvancedSettings(?array $theme = null): array
    {
        if ($theme === null) {
            $theme = $this->getActiveTheme();
        }

        if (!$theme || empty($theme['settings'])) {
            return [
                'custom_css' => '',
                'custom_js' => ''
            ];
        }

        $settings = json_decode($theme['settings'], true) ?? [];
        $advanced = $settings['advanced'] ?? [];

        return [
            'custom_css' => $advanced['custom_css'] ?? '',
            'custom_js' => $advanced['custom_js'] ?? ''
        ];
    }
}

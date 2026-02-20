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
    public function getActiveTheme(): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM themes WHERE active = 1 LIMIT 1");
        if (!$stmt) {
            error_log("ThemeManager: Failed to prepare statement - " . $this->db->error);
            return null;
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $theme = $result->fetch_assoc();
        $stmt->close();

        return $theme ?: null;
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
            $stmt->close();

            if (!$success) {
                throw new \Exception("Failed to activate theme");
            }

            $this->db->commit();
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
     * @return bool Success status
     */
    public function updateThemeColors(int $themeId, array $colors): bool
    {
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

        return $success;
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

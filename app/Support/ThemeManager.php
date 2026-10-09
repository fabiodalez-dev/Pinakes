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
    /**
     * Public site style, the two choices of the 2026 design: the home hero
     * with a fan of covers or centred text, and book cards as the book alone
     * ("classic") or on a panel tinted from the cover ("tinted"). A theme
     * without them (a fresh install, an upgrade) gets the defaults.
     */
    public const HERO_STYLES = ['covers', 'centered'];
    public const CARD_STYLES = ['classic', 'tinted'];
    public const DEFAULT_HERO_STYLE = 'covers';
    public const DEFAULT_CARD_STYLE = 'classic';

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
     * @param array{hero_style:string,card_style:string}|null $publicStyle
     *        Validated public style to persist in the same JSON update.
     * @param array<string,string>|null $advanced Optional advanced settings to
     *        persist atomically with colors and style.
     * @return bool Success status
     */
    public function updateThemeColors(
        int $themeId,
        array $colors,
        ?array $publicStyle = null,
        ?array $advanced = null
    ): bool
    {
        if ($publicStyle !== null && !self::isValidPublicStyle($publicStyle)) {
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
        if ($publicStyle !== null) {
            $settings['hero_style'] = $publicStyle['hero_style'];
            $settings['card_style'] = $publicStyle['card_style'];
        }
        if ($advanced !== null) {
            $settings['advanced'] = $advanced;
        }

        // Encode back to JSON
        $settingsJson = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($settingsJson === false) {
            error_log('ThemeManager: Failed to encode theme settings');
            return false;
        }

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
        if ($settingsJson === false) {
            return false;
        }

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
     * @param array<string, mixed> $style
     */
    public static function isValidPublicStyle(array $style): bool
    {
        return in_array($style['hero_style'] ?? null, self::HERO_STYLES, true)
            && in_array($style['card_style'] ?? null, self::CARD_STYLES, true);
    }

    /**
     * Persist the public style (hero, cards) independently from the palette.
     *
     * @param array{hero_style:string,card_style:string} $publicStyle
     */
    public function updatePublicStyle(int $themeId, array $publicStyle): bool
    {
        if (!self::isValidPublicStyle($publicStyle)) {
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
        $settings['hero_style'] = $publicStyle['hero_style'];
        $settings['card_style'] = $publicStyle['card_style'];
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

    /**
     * The theme's public style, each value validated, defaults filled in.
     *
     * @return array{hero_style:string,card_style:string}
     */
    public function getPublicStyle(?array $theme = null): array
    {
        if ($theme === null) {
            $theme = $this->getActiveTheme();
        }

        $settings = $theme && !empty($theme['settings'])
            ? (json_decode($theme['settings'], true) ?? [])
            : [];
        $hero = (string) ($settings['hero_style'] ?? '');
        $card = (string) ($settings['card_style'] ?? '');

        return [
            'hero_style' => in_array($hero, self::HERO_STYLES, true) ? $hero : self::DEFAULT_HERO_STYLE,
            'card_style' => in_array($card, self::CARD_STYLES, true) ? $card : self::DEFAULT_CARD_STYLE,
        ];
    }

    /**
     * The <body> classes that carry the public style to pinakes-2026.css.
     *
     * @param array{hero_style:string,card_style:string} $publicStyle
     */
    public static function publicStyleClasses(array $publicStyle): string
    {
        $classes = [];
        if ($publicStyle['hero_style'] === 'centered') {
            $classes[] = 'pk-hero-centered';
        }
        if ($publicStyle['card_style'] === 'tinted') {
            $classes[] = 'pk-cards-tinted';
        }
        return implode(' ', $classes);
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
            'secondary' => '#1b1720',
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
                'secondary' => '#1b1720',
                'button' => '#d70262',
                'button_text' => '#ffffff'
            ];
        }

        $settings = json_decode($theme['settings'], true) ?? [];
        $colors = $settings['colors'] ?? [];
        if (!is_array($colors)) {
            $colors = [];
        }

        // Ensure all required colors exist with fallbacks. The save path
        // validates every value, but the stored JSON can still be hand-edited
        // or come from an older version: a value that is not a 3- or 6-digit
        // hex colour would make ThemeColorizer's maths warn on every public
        // page, so it falls back to the default here.
        $hex = static fn (mixed $value, string $default): string =>
            is_string($value) && preg_match('/^#?(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1
                ? ($value[0] === '#' ? $value : '#' . $value)
                : $default;
        return [
            'primary' => $hex($colors['primary'] ?? null, '#d70161'),
            'secondary' => $hex($colors['secondary'] ?? null, '#1b1720'),
            'button' => $hex($colors['button'] ?? null, '#d70262'),
            'button_text' => $hex($colors['button_text'] ?? null, '#ffffff')
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

<?php

/**
 * The active theme's "CSS Personalizzato" (settings.advanced.custom_css, saved
 * by ThemeController), shared by every public layout: the frontend layout, the
 * reader's account pages (user_layout.php) and the standalone auth pages.
 * Include it after the page's own stylesheets and before custom-css.php, so the
 * site-wide custom CSS still has the last word.
 *
 * Uses the caller's $themeManager / $activeTheme when it has them, otherwise
 * resolves the active theme itself (the auth pages render without the layout).
 *
 * SECURITY: sanitized at render time with ContentSanitizer::sanitizeCustomCss(),
 * as in custom-css.php, so a stored `</style><script>` cannot break out.
 */

use App\Support\ContentSanitizer;
use App\Support\ThemeManager;

$themeCustomCss = '';
try {
    $themeCssManager = (isset($themeManager) && $themeManager instanceof ThemeManager) ? $themeManager : null;
    if ($themeCssManager === null) {
        $themeCssDb = (isset($db) && $db instanceof \mysqli) ? $db : \App\Support\ConfigStore::sharedConnection();
        $themeCssManager = $themeCssDb instanceof \mysqli ? new ThemeManager($themeCssDb) : null;
    }
    if ($themeCssManager !== null) {
        $themeCssAdvanced = $themeCssManager->getAdvancedSettings($activeTheme ?? $themeCssManager->getActiveTheme());
        $themeCustomCss = is_string($themeCssAdvanced['custom_css'] ?? null)
            ? ContentSanitizer::sanitizeCustomCss($themeCssAdvanced['custom_css'])
            : '';
    }
} catch (\Throwable $e) {
    // No theme CSS: the page must still render.
    $themeCustomCss = '';
}
if ($themeCustomCss !== ''):
    ?>
    <style>
        <?= $themeCustomCss ?>
    </style>
<?php endif; ?>

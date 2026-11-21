<?php

/**
 * Shared custom-CSS <style> block (issue #262 / PR #265).
 *
 * Emits the admin-configured `advanced.custom_header_css` so it applies to the
 * unauthenticated auth pages (login/register/forgot/reset) and the frontend
 * layout, not just the app chrome.
 *
 * SECURITY: MUST use ContentSanitizer::sanitizeCustomCss() — the same
 * render-time sanitizer used by frontend/layout.php. It strips <style>/<script>
 * openings/closings and HTML comment markers, preventing a stored
 * `</style><script>…` payload from breaking out of the raw-text <style> context
 * and executing JS (stored XSS). normalizeExternalAssets() alone (fonts-only)
 * does NOT stop that breakout — do not swap it in here.
 */

use App\Support\ConfigStore;
use App\Support\ContentSanitizer;

$customCss = ConfigStore::get('advanced.custom_header_css', '');
$customCss = is_string($customCss) ? ContentSanitizer::sanitizeCustomCss($customCss) : '';
if ($customCss !== ''):
    ?>
    <style<?= csp_nonce_attr() ?>>
        <?= $customCss ?>
    </style>
<?php endif; ?>

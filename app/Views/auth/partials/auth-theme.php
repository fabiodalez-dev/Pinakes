<?php

/**
 * Shared look of the standalone auth pages (login, register, forgot/reset
 * password, registration success).
 *
 * The pages are rendered by controllers without the frontend layout, so the
 * theme colours are not injected for them: resolve the active palette here
 * (same source as frontend/layout.php) and expose the same custom properties
 * (--primary-color, --button-color, --button-text-color, --button-hover).
 *
 * Do not include custom-css.php from here: it is shared with the layouts and
 * must stay a separate include in each view.
 */

$authPalette = [
    'primary' => '#d70161',
    'primary_dark' => '#b8014f',
    'button' => '#d70262',
    'button_text' => '#ffffff',
    'button_hover' => '#c20258',
    'primary_text' => '#ce015d',
    'button_surface' => '#d70262',
];
try {
    $authDb = $db ?? \App\Support\ConfigStore::sharedConnection();
    if ($authDb instanceof \mysqli) {
        $authThemeManager = new \App\Support\ThemeManager($authDb);
        $authColors = $authThemeManager->getThemeColors($authThemeManager->getActiveTheme());
        $authPalette = array_merge(
            $authPalette,
            array_filter((new \App\Support\ThemeColorizer())->generateColorPalette($authColors), 'is_string')
        );
    }
} catch (\Throwable $e) {
    // Keep the default palette: the auth pages must always render.
}
$authColor = static fn (string $key): string => htmlspecialchars((string) $authPalette[$key], ENT_QUOTES, 'UTF-8');
?>
    <style>
        :root {
            --primary-color: <?= $authColor('primary') ?>;
            --primary-dark: <?= $authColor('primary_dark') ?>;
            --primary-text: <?= $authColor('primary_text') ?>;
            --button-color: <?= $authColor('button') ?>;
            --button-text-color: <?= $authColor('button_text') ?>;
            --button-hover: <?= $authColor('button_hover') ?>;
            /* The button colour moved only as far as AA with its text needs. */
            --button-surface: <?= $authColor('button_surface') ?>;
            /* 2026 design (public/assets/pinakes-2026.css): same ink, lines and type. */
            --text-color: #1b1720;
            --text-light: #6b6470;
            --border-color: #ece8ea;
            /* Form fields as in pinakes-2026.css (--pk-field-*): white, with a rule tinted by the theme accent. */
            --auth-field-bg: #fff;
            --auth-field-border: color-mix(in srgb, var(--primary-color) 15%, #918990);
            --auth-field-border-hover: color-mix(in srgb, var(--primary-color) 35%, #7a7378);
            --serif: 'Fraunces', Georgia, 'Times New Roman', serif;
            --sans: 'Geist', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            color-scheme: light;
        }
        .auth-body { margin: 0; background: linear-gradient(180deg, color-mix(in srgb, var(--primary-color) 5%, #fff) 0%, #fbfaf9 420px) no-repeat, #fbfaf9; color: var(--text-color); font-family: var(--sans) !important; /* main.css forces Inter on body */ -webkit-font-smoothing: antialiased; }
        .auth-page { min-height: 100vh; padding: 48px 16px; }
        .auth-wrap { max-width: 28rem; width: 100%; margin: 0 auto; }
        .auth-wrap--wide { max-width: 42rem; }
        .auth-brand { text-align: center; margin-bottom: 24px; }
        .auth-brand-logo { display: block; height: 56px; width: auto; max-width: 100%; margin: 0 auto 8px; object-fit: contain; }
        .auth-brand-tile { width: 56px; height: 56px; margin: 0 auto 8px; display: flex; align-items: center; justify-content: center; border-radius: 16px; background: var(--primary-color); color: var(--button-text-color); font-size: 1.5rem; }
        .auth-brand-name { margin: 0; font-size: .9375rem; font-weight: 600; color: var(--text-color); }
        .auth-card { background: #fff; border: 1px solid var(--border-color); border-radius: 20px; box-shadow: 0 16px 40px -28px color-mix(in srgb, color-mix(in srgb, var(--primary-color) 25%, #1b1720) 35%, transparent); padding: 36px; }
        .auth-title { margin: 0 0 8px; font-family: var(--serif); font-size: 2.5rem; line-height: 1.05; font-weight: 500; letter-spacing: -.025em; color: var(--text-color); }
        .auth-subtitle { margin: 0 0 24px; color: var(--text-light); font-size: .9375rem; }
        .auth-form > * + * { margin-top: 20px; }
        .auth-label { display: block; margin-bottom: 6px; font-size: .875rem; font-weight: 600; color: var(--text-color); }
        .auth-input { display: block; width: 100%; min-height: 44px; padding: 10px 12px; box-sizing: border-box; font: inherit; font-size: 1rem; color: var(--text-color); background-color: var(--auth-field-bg); border: 1px solid var(--auth-field-border); border-radius: 10px; }
        .auth-input:hover { border-color: var(--auth-field-border-hover); }
        textarea.auth-input { min-height: 88px; }
        .auth-input:focus-visible { outline: none; border-color: var(--primary-color); background-color: #fff; box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary-color) 18%, transparent); }
        .auth-check input:focus-visible { outline: 3px solid var(--primary-color); outline-offset: 1px; }
        .auth-help { margin: 6px 0 0; font-size: .8125rem; color: var(--text-light); }
        .auth-field-error { display: block; margin-top: 4px; font-size: .875rem; color: #b91c1c; }
        .auth-field-error.hidden { display: none; }
        .auth-check { display: flex; align-items: center; gap: 10px; min-height: 44px; font-size: .875rem; font-weight: 500; color: var(--text-color); cursor: pointer; }
        .auth-check input { width: 20px; height: 20px; margin: 0; flex: none; accent-color: var(--primary-color); }
        .auth-row { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 4px 16px; }
        .auth-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 20px 16px; }
        @media (min-width: 640px) { .auth-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .auth-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 100%; min-height: 44px; padding: 10px 16px; box-sizing: border-box; font: inherit; font-size: 1rem; font-weight: 600; text-decoration: none; cursor: pointer; color: var(--button-text-color); background: var(--button-surface); border: 1px solid var(--button-surface); border-radius: 12px; box-shadow: none; padding-top: 13px; padding-bottom: 13px; }
        .auth-btn:hover { background: var(--button-hover); border-color: var(--button-hover); color: var(--button-text-color); }
        .auth-btn:focus-visible { outline: 3px solid var(--primary-color); outline-offset: 2px; }
        .auth-btn:disabled { opacity: .7; cursor: not-allowed; }
        .auth-link { display: inline-flex; align-items: center; min-height: 44px; color: var(--primary-text, var(--primary-color)); font-weight: 500; text-decoration: none; }
        .auth-link:hover { color: var(--primary-dark); text-decoration: underline; text-underline-offset: 2px; }
        .auth-link:focus-visible { outline: 3px solid var(--primary-color); outline-offset: 1px; }
        .auth-switch { margin: 16px 0 0; text-align: center; font-size: .875rem; color: var(--text-light); }
        .auth-footer { margin-top: 24px; text-align: center; font-size: .875rem; color: var(--text-light); }
        .auth-footer-links { display: flex; justify-content: center; flex-wrap: wrap; gap: 0 24px; }
        .auth-footer .auth-link { font-weight: 400; color: var(--text-light); }
        .auth-copy { margin: 8px 0 0; font-size: .75rem; color: var(--text-light); }
        .auth-alert { padding: 12px 16px; font-size: .875rem; border: 1px solid; border-radius: 12px; }
        .auth-alert-body { display: flex; align-items: flex-start; gap: 12px; }
        .auth-alert i { margin-top: 2px; }
        .auth-strength { display: flex; align-items: center; gap: 8px; }
        .auth-strength-track { flex: 1; height: 4px; background: var(--border-color); border-radius: 2px; overflow: hidden; }
        .auth-strength .auth-help { margin: 0; }
        .auth-alert--error { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
        .auth-alert--success { background: #f0fdf4; border-color: #bbf7d0; color: #166534; }
        .auth-alert p { margin: 0; }
        .auth-alert p + p { margin-top: 8px; }
        .auth-success-icon { width: 56px; height: 56px; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: #f0fdf4; color: #166534; font-size: 1.5rem; }
        .auth-center { text-align: center; }
        @media (max-width: 480px) { .auth-card { padding: 24px 16px; } }
    </style>

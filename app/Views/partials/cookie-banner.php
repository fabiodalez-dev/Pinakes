<?php
declare(strict_types=1);

use App\Support\ConfigStore;

if (!function_exists('assetUrl')) {
    function assetUrl($path) {
        $normalizedPath = '/' . ltrim($path, '/');
        return '/assets' . $normalizedPath;
    }
}

if (!defined('SILKTIDE_COOKIE_BANNER_CSS_LOADED')) {
    define('SILKTIDE_COOKIE_BANNER_CSS_LOADED', true);
    echo '<link rel="stylesheet" href="' . assetUrl('/css/silktide-consent-manager.css') . '">';
}

if (!defined('SILKTIDE_COOKIE_BANNER_JS_LOADED')) {
    define('SILKTIDE_COOKIE_BANNER_JS_LOADED', true);
    echo '<script src="' . assetUrl('/js/silktide-consent-manager.js') . '"></script>';
}

$hasAnalyticsCode = !empty(ConfigStore::get('advanced.custom_js_analytics'));
$hasMapIframe = !empty(ConfigStore::get('contacts.google_maps_embed'));
$showAnalytics = (bool)ConfigStore::get('cookie_banner.show_analytics', true) || $hasAnalyticsCode || $hasMapIframe;
$showMarketing = (bool)ConfigStore::get('cookie_banner.show_marketing', true);
$cookieBannerTexts = [
    'banner_description' => (string)ConfigStore::get('cookie_banner.banner_description', '<p>Utilizziamo i cookie per migliorare la tua esperienza. Continuando a visitare questo sito, accetti il nostro uso dei cookie.</p>'),
    'accept_all_text' => (string)ConfigStore::get('cookie_banner.accept_all_text', 'Accetta tutti'),
    'reject_non_essential_text' => (string)ConfigStore::get('cookie_banner.reject_non_essential_text', 'Rifiuta non essenziali'),
    'preferences_button_text' => (string)ConfigStore::get('cookie_banner.preferences_button_text', 'Preferenze'),
    'save_selected_text' => (string)ConfigStore::get('cookie_banner.save_selected_text', 'Accetta selezionati'),
    'preferences_title' => (string)ConfigStore::get('cookie_banner.preferences_title', 'Personalizza le tue preferenze sui cookie'),
    'preferences_description' => (string)ConfigStore::get('cookie_banner.preferences_description', '<p>Rispettiamo il tuo diritto alla privacy. Puoi scegliere di non consentire alcuni tipi di cookie. Le tue preferenze si applicheranno all\'intero sito web.</p>'),
    'cookie_essential_name' => (string)ConfigStore::get('cookie_banner.cookie_essential_name', 'Cookie Essenziali'),
    'cookie_essential_description' => (string)ConfigStore::get('cookie_banner.cookie_essential_description', 'Questi cookie sono necessari per il funzionamento del sito e non possono essere disabilitati.'),
    'cookie_analytics_name' => (string)ConfigStore::get('cookie_banner.cookie_analytics_name', 'Cookie Analitici'),
    'cookie_analytics_description' => (string)ConfigStore::get('cookie_banner.cookie_analytics_description', 'Questi cookie ci aiutano a capire come i visitatori interagiscono con il sito web.'),
    'cookie_marketing_name' => (string)ConfigStore::get('cookie_banner.cookie_marketing_name', 'Cookie di Marketing'),
    'cookie_marketing_description' => (string)ConfigStore::get('cookie_banner.cookie_marketing_description', 'Questi cookie vengono utilizzati per fornire annunci personalizzati.'),
];

?>
<!-- Silktide Consent Manager -->
<script>
    if (typeof silktideCookieBannerManager !== 'undefined') {
        silktideCookieBannerManager.updateCookieBannerConfig({
            cookieTypes: [
                {
                    id: 'essential',
                    name: <?= json_encode($cookieBannerTexts['cookie_essential_name'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    description: <?= json_encode($cookieBannerTexts['cookie_essential_description'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    required: true,
                    defaultValue: true,
                },
                <?php if ($showAnalytics): ?>
                {
                    id: 'analytics',
                    name: <?= json_encode($cookieBannerTexts['cookie_analytics_name'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    description: <?= json_encode($cookieBannerTexts['cookie_analytics_description'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    defaultValue: false,
                    onAccept: function() {},
                    onReject: function() {},
                },
                <?php endif; ?>
                <?php if ($showMarketing): ?>
                {
                    id: 'marketing',
                    name: <?= json_encode($cookieBannerTexts['cookie_marketing_name'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    description: <?= json_encode($cookieBannerTexts['cookie_marketing_description'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    defaultValue: false,
                    onAccept: function() {},
                    onReject: function() {},
                },
                <?php endif; ?>
            ],
            text: {
                banner: {
                    description: <?= json_encode($cookieBannerTexts['banner_description'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    acceptAllButtonText: <?= json_encode($cookieBannerTexts['accept_all_text'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    acceptAllButtonAccessibleLabel: <?= json_encode($cookieBannerTexts['accept_all_text'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    rejectNonEssentialButtonText: <?= json_encode($cookieBannerTexts['reject_non_essential_text'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    rejectNonEssentialButtonAccessibleLabel: <?= json_encode($cookieBannerTexts['reject_non_essential_text'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    preferencesButtonText: <?= json_encode($cookieBannerTexts['preferences_button_text'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    preferencesButtonAccessibleLabel: <?= json_encode($cookieBannerTexts['preferences_button_text'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    saveSelectedButtonText: <?= json_encode($cookieBannerTexts['save_selected_text'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    saveSelectedButtonAccessibleLabel: <?= json_encode($cookieBannerTexts['save_selected_text'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                },
                preferences: {
                    title: <?= json_encode($cookieBannerTexts['preferences_title'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    description: <?= json_encode($cookieBannerTexts['preferences_description'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    statementUrl: '/cookies',
                    statementAccessibleLabel: 'Maggiori informazioni sui cookie',
                },
            },
            position: {
                banner: 'bottomRight',
                cookieIcon: 'bottomLeft',
            },
        });
    } else {
        console.warn('silktideCookieBannerManager non è stato caricato.');
    }
</script>

<?php

use App\Support\ConfigStore;
use App\Support\ContentSanitizer;
use App\Support\HtmlHelper;

$appName = (string)ConfigStore::get('app.name', 'Pinakes');
$appLogo = (string)ConfigStore::get('app.logo', '');
if ($appLogo !== '') {
    $parsedLogoPath = parse_url($appLogo, PHP_URL_PATH) ?? $appLogo;
    $publicDir = realpath(dirname(__DIR__, 2) . '/public');
    if ($publicDir !== false) {
        $absoluteLogoPath = realpath($publicDir . $parsedLogoPath) ?: ($publicDir . $parsedLogoPath);
        if (!is_file($absoluteLogoPath)) {
            $appLogo = '';
        }
    }
}
$appInitial = mb_strtoupper(mb_substr($appName, 0, 1));
$footerDescription = (string)ConfigStore::get('app.footer_description', 'Il tuo sistema Pinakes per catalogare, gestire e condividere la tua collezione libraria.');

// Cookie Banner Texts and Flags
// Show analytics if explicitly enabled, OR if analytics code exists, OR if map iframe exists
$hasAnalyticsCode = !empty(ConfigStore::get('advanced.custom_js_analytics'));
$hasMapIframe = !empty(ConfigStore::get('contacts.google_maps_embed'));
$showAnalytics = (bool)ConfigStore::get('cookie_banner.show_analytics', true) || $hasAnalyticsCode || $hasMapIframe;
$showMarketing = (bool)ConfigStore::get('cookie_banner.show_marketing', true);
$cookieBannerTexts = [
    'banner_description' => (string)ConfigStore::get('cookie_banner.banner_description', '<p>Utilizziamo i cookie per migliorare la tua esperienza. Continuando a visitare questo sito, accetti il nostro uso dei cookie.</p>'),
    'accept_all_text' => (string)ConfigStore::get('cookie_banner.accept_all_text', 'Accetta tutti'),
    'reject_non_essential_text' => (string)ConfigStore::get('cookie_banner.reject_non_essential_text', 'Rifiuta non essenziali'),
    'save_selected_text' => (string)ConfigStore::get('cookie_banner.save_selected_text', 'Accetta selezionati'),
    'preferences_button_text' => (string)ConfigStore::get('cookie_banner.preferences_button_text', 'Preferenze'),
    'preferences_title' => (string)ConfigStore::get('cookie_banner.preferences_title', 'Personalizza le tue preferenze sui cookie'),
    'preferences_description' => (string)ConfigStore::get('cookie_banner.preferences_description', '<p>Rispettiamo il tuo diritto alla privacy. Puoi scegliere di non consentire alcuni tipi di cookie. Le tue preferenze si applicheranno all\'intero sito web.</p>'),
    'cookie_essential_name' => (string)ConfigStore::get('cookie_banner.cookie_essential_name', 'Cookie Essenziali'),
    'cookie_essential_description' => (string)ConfigStore::get('cookie_banner.cookie_essential_description', 'Questi cookie sono necessari per il funzionamento del sito e non possono essere disabilitati.'),
    'cookie_analytics_name' => (string)ConfigStore::get('cookie_banner.cookie_analytics_name', 'Cookie Analitici'),
    'cookie_analytics_description' => (string)ConfigStore::get('cookie_banner.cookie_analytics_description', 'Questi cookie ci aiutano a capire come i visitatori interagiscono con il sito web.'),
    'cookie_marketing_name' => (string)ConfigStore::get('cookie_banner.cookie_marketing_name', 'Cookie di Marketing'),
    'cookie_marketing_description' => (string)ConfigStore::get('cookie_banner.cookie_marketing_description', 'Questi cookie vengono utilizzati per fornire annunci personalizzati.'),
];
$socialFacebook = (string)ConfigStore::get('app.social_facebook', '');
$socialTwitter = (string)ConfigStore::get('app.social_twitter', '');
$socialInstagram = (string)ConfigStore::get('app.social_instagram', '');
$socialLinkedin = (string)ConfigStore::get('app.social_linkedin', '');
$socialBluesky = (string)ConfigStore::get('app.social_bluesky', '');

// Helper function for generating absolute URLs
if (!function_exists('absoluteUrl')) {
    function absoluteUrl($path = '') {
        if ($path !== '' && (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '//'))) {
            return $path;
        }

        $normalizedPath = '/' . ltrim($path, '/');

        $protocol = 'http';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $forwardedProto = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0];
            $protocol = strtolower($forwardedProto) === 'https' ? 'https' : 'http';
        } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $protocol = 'https';
        } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
            $protocol = $_SERVER['REQUEST_SCHEME'] === 'https' ? 'https' : 'http';
        } elseif (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            $protocol = 'https';
        }

        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));

        if (!str_contains($host, ':')) {
            $port = null;
            if (!empty($_SERVER['HTTP_X_FORWARDED_PORT'])) {
                $port = (int)$_SERVER['HTTP_X_FORWARDED_PORT'];
            } elseif (isset($_SERVER['SERVER_PORT'])) {
                $port = (int)$_SERVER['SERVER_PORT'];
            }

            if ($port !== null && !in_array([$protocol, $port], [['http', 80], ['https', 443]], true)) {
                $host .= ':' . $port;
            }
        }

        return $protocol . '://' . $host . $normalizedPath;
    }
}

// Helper function for asset URLs
if (!function_exists('assetUrl')) {
    function assetUrl($path) {
        $normalizedPath = '/' . ltrim($path, '/');
        return absoluteUrl('/assets' . $normalizedPath);
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= HtmlHelper::e($seoTitle ?? $title ?? $appName) ?></title>

    <!-- SEO Meta Tags -->
    <meta name="description" content="<?= HtmlHelper::e($seoDescription ?? ($appName . ' digitale con catalogo completo di libri disponibili per il prestito')) ?>">
    <link rel="canonical" href="<?= htmlspecialchars($seoCanonical ?? (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>">

    <!-- Open Graph Meta Tags -->
    <?php if (isset($seoTitle)): ?>
    <meta property="og:title" content="<?= htmlspecialchars($seoTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($seoDescription) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($seoImage) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($seoCanonical) ?>">
    <meta property="og:type" content="book">
    <meta property="og:site_name" content="<?= HtmlHelper::e($appName) ?>">
    <?php endif; ?>

    <!-- Twitter Card Meta Tags -->
    <?php if (isset($seoTitle)): ?>
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($seoTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($seoDescription) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($seoImage) ?>">
    <?php endif; ?>

    <!-- Schema.org JSON-LD -->
    <?php if (isset($seoSchema)): ?>
    <script type="application/ld+json">
    <?= $seoSchema ?>
    </script>
    <?php endif; ?>

    <meta name="csrf-token" content="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>" />
    <link rel="icon" type="image/svg+xml" href="<?= absoluteUrl('/favicon.svg') ?>">

    <!-- CSS moderno e minimale -->
    <link href="<?= assetUrl('/vendor.css') ?>" rel="stylesheet">
    <link href="<?= assetUrl('/flatpickr-custom.css') ?>" rel="stylesheet">
    <link href="<?= assetUrl('/main.css') ?>" rel="stylesheet">
    <link href="<?= assetUrl('/css/swal-theme.css') ?>" rel="stylesheet">

    <style>
        :root {
            --primary-color: #000000;
            --secondary-color: #333333;
            --accent-color: #666666;
            --text-color: #000000;
            --text-light: #666666;
            --text-muted: #999999;
            --light-bg: #f8f9fa;
            --white: #ffffff;
            --card-shadow: 0 4px 20px rgba(0,0,0,0.08);
            --card-shadow-hover: 0 8px 30px rgba(0,0,0,0.12);
            --border-color: #e5e7eb;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.7;
            color: var(--text-color);
            background-color: var(--white);
            padding-top: 0;
            font-weight: 400;
            letter-spacing: -0.01em;
        }

        main {
            padding-top: 90px;
        }

        /* Minimalist Header */
        .header-container {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border-color);
        }

        .header-main {
            padding: 1.25rem 0;
        }

        .header-brand {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary-color);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            letter-spacing: -0.02em;
            transition: all 0.3s ease;
        }

        .header-brand .logo-image {
            width: 2.5rem;
            height: 2.5rem;
            object-fit: contain;
        }

        .header-brand .brand-text {
            font-weight: 800;
            font-size: 1.35rem;
            letter-spacing: -0.03em;
        }

        .header-brand:hover {
            color: var(--secondary-color);
            text-decoration: none;
            transform: translateY(-1px);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 3rem;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .nav-links a {
            color: var(--text-color);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            padding: 0.75rem 0;
            position: relative;
            letter-spacing: -0.01em;
        }

        .nav-links a:hover, .nav-links a.active {
            color: var(--primary-color);
            font-weight: 600;
        }

        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 2px;
            background: var(--primary-color);
            transition: width 0.3s ease;
        }

        .nav-links a:hover::after, .nav-links a.active::after {
            width: 100%;
        }

        .search-form {
            flex: 1;
            max-width: 400px;
        }

        .search-input {
            border: 1px solid var(--border-color);
            border-radius: 50px;
            padding: 0.75rem 1.25rem;
            width: 100%;
            font-size: 0.95rem;
            background: var(--white);
            transition: all 0.3s ease;
            box-shadow: none;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: none;
            transform: translateY(-1px);
        }

        /* Mobile search toggle */
        .mobile-search-toggle {
            background: transparent;
            border: none;
            color: var(--text-color);
            font-size: 1.3rem;
            padding: 0.5rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.3s ease;
        }

        .mobile-search-toggle:hover {
            color: var(--primary-color);
        }

        /* Mobile search container animation */
        .mobile-search-container {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-in-out;
            width: 100%;
        }

        .mobile-search-container.active {
            max-height: 60px;
        }

        .btn-header {
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            letter-spacing: -0.01em;
            border: 1px solid transparent;
        }

        .btn-primary-header {
            background: var(--primary-color);
            color: white;
            border: 1px solid var(--primary-color);
        }

        .btn-primary-header:hover {
            background: var(--secondary-color);
            color: white;
            transform: translateY(-2px);
            box-shadow: none;
        }

        .btn-outline-header {
            background: transparent;
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }

        .btn-outline-header:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            background: rgba(0,0,0,0.02);
            transform: translateY(-1px);
        }

        /* Frontend button system */
        .btn-cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            padding: 0.9rem 2.2rem;
            border-radius: 999px;
            border: 1.5px solid #111827;
            background: #111827;
            color: #ffffff;
            font-weight: 600;
            font-size: 1rem;
            letter-spacing: -0.01em;
            text-decoration: none;
            box-shadow: none;
            transition: all 0.2s ease;
            cursor: pointer;
            min-height: 52px;
        }

        .btn-cta:hover,
        .btn-cta:focus {
            color: #ffffff;
            background: #000000;
            border-color: #000000;
            transform: translateY(-2px);
            box-shadow: none;
            text-decoration: none;
        }

        .btn-cta i {
            transition: transform 0.2s ease;
        }

        .btn-cta:hover i,
        .btn-cta:focus i {
            transform: translateX(2px);
        }

        .btn-cta-outline {
            background: transparent;
            color: #111827;
            border: 1.5px solid #111827;
            box-shadow: none;
        }

        .btn-cta-outline:hover,
        .btn-cta-outline:focus {
            background: #111827;
            color: #ffffff;
            box-shadow: none;
        }

        .btn-cta-lg {
            padding: 1rem 2.6rem;
            font-size: 1.1rem;
        }

        .btn-cta-sm {
            padding: 0.65rem 1.6rem;
            font-size: 0.9rem;
            min-height: 44px;
            box-shadow: none;
        }

        .btn.btn-primary,
        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            padding: 0.8rem 2rem;
            border-radius: 999px;
            border: 1.5px solid #111827;
            background: #111827;
            color: #ffffff;
            font-weight: 600;
            letter-spacing: -0.01em;
            box-shadow: none;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn.btn-primary:hover,
        .btn.btn-primary:focus,
        .btn-primary:hover,
        .btn-primary:focus {
            background: #000000;
            border-color: #000000;
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: none;
            text-decoration: none;
        }

        .btn.btn-outline-primary,
        .btn-outline-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            padding: 0.8rem 2rem;
            border-radius: 999px;
            border: 1.5px solid #111827;
            background: transparent;
            color: #111827;
            font-weight: 600;
            letter-spacing: -0.01em;
            transition: all 0.2s ease;
        }

        .btn.btn-outline-primary:hover,
        .btn.btn-outline-primary:focus,
        .btn-outline-primary:hover,
        .btn-outline-primary:focus {
            background: #111827;
            color: #ffffff;
            box-shadow: none;
            text-decoration: none;
        }

        .btn.btn-primary.btn-sm,
        .btn.btn-outline-primary.btn-sm,
        .btn-primary.btn-sm,
        .btn-outline-primary.btn-sm {
            padding: 0.55rem 1.5rem;
            font-size: 0.85rem;
            min-height: 42px;
        }

        @media (max-width: 480px) {
            .btn-cta {
                padding: 0.75rem 1.75rem;
                font-size: 0.95rem;
                min-height: 48px;
            }
        }

        .badge-notification {
            position: absolute;
            top: -6px;
            right: -6px;
            background: var(--danger-color);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: none;
        }

        /* Responsive Header */
        .mobile-menu-toggle {
            background: transparent;
            border: none;
            color: var(--text-color);
            font-size: 1.5rem;
            padding: 0.5rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.3s ease;
        }

        .mobile-menu-toggle:hover {
            color: var(--primary-color);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
        }

        /* Elegant Hero Section */
        .hero {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 6rem 0 4rem;
            position: relative;
            overflow: hidden;
            margin-top: 0;
        }

        .hero-content {
            position: relative;
            z-index: 2;
            text-align: center;
        }

        .hero h1 {
            font-size: 3.5rem;
            font-weight: 800;
            margin-bottom: 1.5rem;
            letter-spacing: -0.03em;
            line-height: 1.1;
        }

        .hero p {
            font-size: 1.3rem;
            margin-bottom: 2.5rem;
            opacity: 0.9;
            font-weight: 300;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Elegant Book Cards */
        .book-card {
            background: var(--white);
            border-radius: 16px;
            box-shadow: none;
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            overflow: hidden;
            height: 100%;
            border: 1px solid transparent;
        }

        .book-card:hover {
            transform: translateY(-8px);
            box-shadow: none;
            border-color: var(--border-color);
        }

        .book-image {
            height: 280px;
            overflow: hidden;
            position: relative;
            background: var(--light-bg);
        }

        .book-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        .book-card:hover .book-image img {
            transform: scale(1.08);
        }

        /* Elegant Status Badges */
        .book-status {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            backdrop-filter: blur(10px);
        }

        .status-available {
            background: rgba(16, 185, 129, 0.9);
            color: white;
            box-shadow: none;
        }

        .status-borrowed {
            background: rgba(239, 68, 68, 0.9);
            color: white;
            box-shadow: none;
        }

        .book-content {
            padding: 1.5rem;
        }

        .book-title {
            font-size: 1.1rem;
            font-weight: 700;
            line-height: 1.4;
            margin-bottom: 0.75rem;
            color: var(--primary-color);
            letter-spacing: -0.01em;
        }

        .book-author {
            font-size: 0.9rem;
            color: var(--text-light);
            margin-bottom: 0.75rem;
            font-weight: 400;
        }

        .book-meta {
            font-size: 0.85rem;
            color: var(--text-muted);
            line-height: 1.5;
        }

        /* Elegant Footer */
        .footer {
            background: #f8fafc;
            color: #0f172a;
            padding: 4rem 0 1.5rem;
            margin-top: 6rem;
            border-top: 1px solid #e5e7eb;
        }

        /* Remove footer margin on home page */
        body.home .footer {
            margin-top: 0;
        }

        .footer h5 {
            font-weight: 700;
            margin-bottom: 1.25rem;
            letter-spacing: -0.01em;
            color: #0f172a;
        }

        .footer-logo {
            max-height: 48px;
            width: auto;
            object-fit: contain;
            margin-bottom: 1rem;
        }

        .footer a {
            color: #1f2937;
            text-decoration: none;
            transition: color 0.2s ease;
            font-weight: 500;
        }

        .footer a:hover {
            color: #111827;
        }

        .footer .list-unstyled li {
            margin-bottom: 0.5rem;
        }

        .footer .social-links a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #0f172a;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .footer .social-links a:hover {
            background: #cbd5f5;
            color: #0f172a;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .nav-links {
                gap: 2rem;
            }
        }

        @media (max-width: 768px) {
            main {
                padding-top: 80px;
            }

            .header-main {
                padding: 1rem 0;
            }

            .header-brand {
                font-size: 1.3rem;
            }

            .nav-links {
                display: none;
            }

            .search-form {
                max-width: 100%;
                margin: 1rem 0;
            }

            .hero h1 {
                font-size: 2.5rem;
            }

            .hero p {
                font-size: 1.1rem;
                margin-bottom: 2rem;
            }

            .hero {
                padding: 4rem 0 3rem;
            }

            .header-content {
                flex-wrap: wrap;
            }

            .header-brand {
                order: 1;
            }

            .mobile-search-toggle {
                order: 2;
                margin-left: auto;
            }

            .mobile-menu-toggle {
                order: 3;
                margin-left: 0.5rem;
            }

            .user-menu {
                display: none !important;
            }

            .header-content .mobile-search-container {
                order: 4;
                width: 100%;
            }

            .btn-header {
                padding: 0.6rem 1.2rem;
                font-size: 0.85rem;
            }
        }

        @media (max-width: 576px) {
            main {
                padding-top: 75px;
            }

            .header-brand {
                font-size: 1.2rem;
                gap: 0.5rem;
            }

            .hero h1 {
                font-size: 2rem;
            }

            .hero p {
                font-size: 1rem;
            }

            .btn-header {
                padding: 0.5rem 1rem;
                font-size: 0.8rem;
            }

            .search-input {
                padding: 0.6rem 1rem;
                font-size: 0.9rem;
            }

            .user-menu {
                gap: 0.5rem;
            }
        }

        /* Animations */
        .fade-in {
            animation: fadeIn 0.6s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Utility Classes */
        .text-muted {
            color: #6c757d !important;
        }

        .text-center {
            text-align: center;
        }

        .d-flex {
            display: flex;
        }

        .align-items-center {
            align-items: center;
        }

        .justify-content-between {
            justify-content: space-between;
        }

        .gap-1 { gap: 0.25rem; }
        .gap-2 { gap: 0.5rem; }
        .gap-3 { gap: 1rem; }
        .gap-4 { gap: 1.5rem; }

        /* Mobile Menu Styles */
        .mobile-menu-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100vh;
            background: rgba(0, 0, 0, 0.5);
            z-index: 99999;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .mobile-menu-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .mobile-menu-content {
            position: fixed;
            top: 0;
            right: 0;
            width: 80%;
            max-width: 320px;
            height: 100vh;
            background: white;
            box-shadow: -4px 0 20px rgba(0, 0, 0, 0.1);
            transform: translateX(100%);
            transition: transform 0.3s ease;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .mobile-menu-overlay.active .mobile-menu-content {
            transform: translateX(0);
        }

        .mobile-menu-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            background: var(--bg-primary);
        }

        .mobile-menu-header .brand-text {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-color);
        }

        .mobile-menu-close {
            background: transparent;
            border: none;
            color: var(--text-color);
            font-size: 1.5rem;
            padding: 0.5rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.3s ease;
        }

        .mobile-menu-close:hover {
            color: var(--primary-color);
        }

        .mobile-nav {
            padding: 1rem 0;
        }

        .mobile-nav-link {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            color: var(--text-color);
            text-decoration: none;
            font-size: 1rem;
            font-weight: 500;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .mobile-nav-link:hover {
            background: rgba(0, 0, 0, 0.05);
            color: var(--primary-color);
        }

        .mobile-nav-link.active {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary-color);
            border-left: 3px solid var(--primary-color);
        }

        .mobile-nav-link i {
            width: 24px;
            font-size: 1.1rem;
        }

        .mobile-menu-divider {
            margin: 0.5rem 1.5rem;
            border: none;
            border-top: 1px solid #e5e7eb;
        }

        <?= $additional_css ?? '' ?>
    </style>

    <?php
    // Load custom CSS from settings
    $customCss = ConfigStore::get('advanced.custom_header_css', '');
    $customCss = is_string($customCss) ? ContentSanitizer::normalizeExternalAssets($customCss) : $customCss;
    if (!empty($customCss)):
    ?>
    <style>
        <?= $customCss ?>
    </style>
    <?php endif; ?>

    <?php
    // Load custom JavaScript from settings (granular by cookie category)
    $customJsEssential = ConfigStore::get('advanced.custom_js_essential', '');
    $customJsEssential = is_string($customJsEssential) ? ContentSanitizer::normalizeExternalAssets($customJsEssential) : $customJsEssential;

    $customJsAnalytics = ConfigStore::get('advanced.custom_js_analytics', '');
    $customJsAnalytics = is_string($customJsAnalytics) ? ContentSanitizer::normalizeExternalAssets($customJsAnalytics) : $customJsAnalytics;

    $customJsMarketing = ConfigStore::get('advanced.custom_js_marketing', '');
    $customJsMarketing = is_string($customJsMarketing) ? ContentSanitizer::normalizeExternalAssets($customJsMarketing) : $customJsMarketing;

    // JavaScript Essenziali: sempre caricati
    if (!empty($customJsEssential)):
    ?>
    <script id="custom-js-essential">
        <?= $customJsEssential ?>
    </script>
    <?php endif; ?>

    <?php
    // JavaScript Analitici e Marketing: caricati solo con consenso
    // Preparazione script per caricamento condizionato
    if (!empty($customJsAnalytics) || !empty($customJsMarketing)):
    ?>
    <script id="custom-js-loader">
        (function() {
            'use strict';

            // Script analytics
            const analyticsScript = <?= json_encode($customJsAnalytics, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

            // Script marketing
            const marketingScript = <?= json_encode($customJsMarketing, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

            // Funzione per iniettare script
            function injectScript(scriptContent, id) {
                if (!scriptContent || document.getElementById(id)) {
                    return; // Skip se vuoto o già iniettato
                }

                // Verifica che il contenuto sia JavaScript valido (non HTML)
                if (scriptContent.trim().startsWith('<') || scriptContent.includes('<iframe') || scriptContent.includes('<script')) {
                    console.warn('Custom script contains HTML tags and will be skipped. Use JavaScript code only.', id);
                    return;
                }

                try {
                    const script = document.createElement('script');
                    script.id = id;
                    script.textContent = scriptContent;
                    document.head.appendChild(script);
                } catch (error) {
                    console.error('Failed to inject custom script:', id, error);
                }
            }

            // Funzione per controllare consenso e caricare script
            function loadCustomScripts() {
                if (!window.CookieControl || !window.CookieControl.getCategoryConsent) {
                    return; // Cookie Control non ancora pronto
                }

                // Carica analytics se consenso granted
                if (analyticsScript && window.CookieControl.getCategoryConsent('analytics')) {
                    injectScript(analyticsScript, 'custom-js-analytics');
                }

                // Carica marketing se consenso granted
                if (marketingScript && window.CookieControl.getCategoryConsent('marketing')) {
                    injectScript(marketingScript, 'custom-js-marketing');
                }
            }

            // Prova a caricare al DOMContentLoaded
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    setTimeout(loadCustomScripts, 200);
                });
            } else {
                setTimeout(loadCustomScripts, 200);
            }

            // Ascolta cambiamenti consenso
            window.addEventListener('silktideConsentChanged', function() {
                setTimeout(loadCustomScripts, 100);
            });

            // Retry per i primi 3 secondi (in caso Cookie Control si carica lentamente)
            let attempts = 0;
            const retryInterval = setInterval(function() {
                attempts++;
                loadCustomScripts();

                if (attempts >= 6 || (window.CookieControl && window.CookieControl.getCategoryConsent)) {
                    clearInterval(retryInterval);
                }
            }, 500);
        })();
    </script>
    <?php endif; ?>

    <!-- Silktide Consent Manager CSS -->
    <link rel="stylesheet" href="<?= assetUrl('/css/silktide-consent-manager.css') ?>">
</head>
<body class="<?= $_SERVER['REQUEST_URI'] === '/' || $_SERVER['REQUEST_URI'] === '/index.php' ? 'home' : '' ?>">
    <!-- Minimalist Header -->
    <div class="header-container">
        <div class="header-main">
            <div class="container">
                <div class="header-content">
                    <a class="header-brand" href="<?= absoluteUrl('/') ?>">
                        <?php if ($appLogo !== ''): ?>
                            <img src="<?= HtmlHelper::e($appLogo) ?>" alt="<?= HtmlHelper::e($appName) ?>" class="logo-image">
                        <?php else: ?>
                            <span class="brand-text"><?= HtmlHelper::e($appName) ?></span>
                        <?php endif; ?>
                    </a>

                    <ul class="nav-links d-none d-md-flex">
                        <li><a href="<?= absoluteUrl('/catalogo') ?>" class="<?= strpos($_SERVER['REQUEST_URI'], '/catalogo') !== false ? 'active' : '' ?>"><?= __('Catalogo') ?></a></li>
                    </ul>

                    <!-- Mobile Search Toggle -->
                    <button class="mobile-search-toggle d-md-none" id="mobileSearchToggle" aria-label="<?= __('Toggle search') ?>">
                        <i class="fas fa-search"></i>
                    </button>

                    <!-- Mobile Menu Toggle -->
                    <button class="mobile-menu-toggle d-md-none" id="mobileMenuToggle" aria-label="<?= __('Toggle menu') ?>">
                        <i class="fas fa-bars"></i>
                    </button>

                    <form class="search-form d-none d-md-block" action="<?= absoluteUrl('/catalogo') ?>" method="get">
                        <input class="search-input" type="search" name="q" placeholder="<?= __('Cerca libri, autori...') ?>" aria-label="<?= __('Search') ?>">
                    </form>

                    <div class="user-menu d-none d-md-flex">
                        <?php $isLogged = !empty($_SESSION['user'] ?? null); ?>

                        <?php if ($isLogged): ?>
                            <div class="d-flex align-items-center gap-2">
                                <a class="btn btn-outline-header" href="<?= absoluteUrl('/prenotazioni') ?>">
                                    <i class="fas fa-bookmark"></i>
                                    <span class="d-none d-sm-inline"><?= __('Prenotazioni') ?></span>
                                    <span id="nav-res-count" class="badge-notification d-none">0</span>
                                </a>
                                <a class="btn btn-outline-header" href="<?= absoluteUrl('/wishlist') ?>">
                                    <i class="fas fa-heart"></i>
                                    <span class="d-none d-sm-inline"><?= __('Preferiti') ?></span>
                                </a>
                                <?php if (isset($_SESSION['user']['tipo_utente']) && ($_SESSION['user']['tipo_utente'] === 'admin' || $_SESSION['user']['tipo_utente'] === 'staff')): ?>
                                <a class="btn btn-primary-header" href="<?= absoluteUrl('/admin/dashboard') ?>">
                                    <i class="fas fa-user-shield"></i>
                                    <span class="d-none d-md-inline"><?= __('Admin') ?></span>
                                </a>
                                <?php else: ?>
                                <a class="btn btn-primary-header" href="<?= absoluteUrl('/profilo') ?>">
                                    <i class="fas fa-user"></i>
                                    <span class="d-none d-md-inline"><?= HtmlHelper::safe($_SESSION['user']['name'] ?? $_SESSION['user']['username'] ?? 'Profilo') ?></span>
                                </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="d-flex align-items-center gap-2">
                                <a class="btn btn-outline-header" href="<?= absoluteUrl('/login') ?>">
                                    <i class="fas fa-sign-in-alt"></i>
                                    <span class="d-none d-sm-inline"><?= __('Accedi') ?></span>
                                </a>
                                <a class="btn btn-primary-header" href="<?= absoluteUrl('/register') ?>">
                                    <i class="fas fa-user-plus"></i>
                                    <span class="d-none d-sm-inline"><?= __('Registrati') ?></span>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Mobile search container with animation -->
                    <div class="mobile-search-container d-md-none" id="mobileSearchContainer">
                        <form class="search-form w-100" action="<?= absoluteUrl('/catalogo') ?>" method="get">
                            <input class="search-input" type="search" name="q" placeholder="<?= __('Cerca libri...') ?>" aria-label="<?= __('Search') ?>">
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile Menu Overlay -->
        <div class="mobile-menu-overlay d-md-none" id="mobileMenuOverlay">
            <div class="mobile-menu-content">
                <div class="mobile-menu-header">
                    <span class="brand-text"><?= HtmlHelper::e($appName) ?></span>
                    <button class="mobile-menu-close" id="mobileMenuClose" aria-label="<?= __('Close menu') ?>">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <nav class="mobile-nav">
                    <a href="<?= absoluteUrl('/catalogo') ?>" class="mobile-nav-link <?= strpos($_SERVER['REQUEST_URI'], '/catalogo') !== false ? 'active' : '' ?>">
                        <i class="fas fa-book me-2"></i>Catalogo
                    </a>
                    <?php if ($isLogged): ?>
                    <hr class="mobile-menu-divider">
                    <a href="<?= absoluteUrl('/user/dashboard') ?>" class="mobile-nav-link">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                    <a href="<?= absoluteUrl('/prenotazioni') ?>" class="mobile-nav-link">
                        <i class="fas fa-bookmark me-2"></i>Prenotazioni
                    </a>
                    <a href="<?= absoluteUrl('/wishlist') ?>" class="mobile-nav-link">
                        <i class="fas fa-heart me-2"></i>Preferiti
                    </a>
                    <?php if (isset($_SESSION['user']['tipo_utente']) && ($_SESSION['user']['tipo_utente'] === 'admin' || $_SESSION['user']['tipo_utente'] === 'staff')): ?>
                    <a href="<?= absoluteUrl('/admin/dashboard') ?>" class="mobile-nav-link">
                        <i class="fas fa-user-shield me-2"></i><?= __("Admin") ?>
                    </a>
                    <?php else: ?>
                    <a href="<?= absoluteUrl('/profilo') ?>" class="mobile-nav-link">
                        <i class="fas fa-user me-2"></i><?= __("Profilo") ?>
                    </a>
                    <?php endif; ?>
                    <?php else: ?>
                    <hr class="mobile-menu-divider">
                    <a href="<?= absoluteUrl('/login') ?>" class="mobile-nav-link">
                        <i class="fas fa-sign-in-alt me-2"></i><?= __("Accedi") ?>
                    </a>
                    <a href="<?= absoluteUrl('/register') ?>" class="mobile-nav-link">
                        <i class="fas fa-user-plus me-2"></i><?= __("Registrati") ?>
                    </a>
                    <?php endif; ?>
                </nav>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main>
        <?= $content ?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-lg-3">
                    <?php if ($appLogo !== ''): ?>
                        <img src="<?= HtmlHelper::e($appLogo) ?>" alt="<?= HtmlHelper::e($appName) ?>" class="footer-logo">
                    <?php else: ?>
                        <h5><i class="fas fa-book-open me-2"></i><?= HtmlHelper::e($appName) ?></h5>
                    <?php endif; ?>
                    <p><?= HtmlHelper::e($footerDescription) ?></p>
                </div>
                <div class="col-lg-3">
                    <h5><?= __("Menu") ?></h5>
                    <ul class="list-unstyled">
                        <li><a href="<?= absoluteUrl('/chi-siamo') ?>"><?= __("Chi Siamo") ?></a></li>
                        <li><a href="<?= absoluteUrl('/contatti') ?>"><?= __("Contatti") ?></a></li>
                        <li><a href="<?= absoluteUrl('/privacy-policy') ?>"><?= __("Privacy Policy") ?></a></li>
                        <li><a href="<?= absoluteUrl('/cookies') ?>"><?= __("Cookies") ?></a></li>
                    </ul>
                </div>
                <div class="col-lg-3">
                    <h5><?= __("Account") ?></h5>
                    <ul class="list-unstyled">
                        <li><a href="<?= absoluteUrl('/user/dashboard') ?>"><?= __("Dashboard") ?></a></li>
                        <li><a href="<?= absoluteUrl('/profilo') ?>"><?= __("Profilo") ?></a></li>
                        <li><a href="<?= absoluteUrl('/wishlist') ?>"><?= __("Wishlist") ?></a></li>
                        <li><a href="<?= absoluteUrl('/prenotazioni') ?>"><?= __("Prenotazioni") ?></a></li>
                    </ul>
                </div>
                <div class="col-lg-3">
                    <h5><?= __("Seguici") ?></h5>
                    <div class="d-flex gap-3 social-links">
                        <?php if ($socialFacebook !== ''): ?>
                            <a href="<?= HtmlHelper::e($socialFacebook) ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-facebook"></i></a>
                        <?php endif; ?>
                        <?php if ($socialTwitter !== ''): ?>
                            <a href="<?= HtmlHelper::e($socialTwitter) ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-twitter"></i></a>
                        <?php endif; ?>
                        <?php if ($socialInstagram !== ''): ?>
                            <a href="<?= HtmlHelper::e($socialInstagram) ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-instagram"></i></a>
                        <?php endif; ?>
                        <?php if ($socialLinkedin !== ''): ?>
                            <a href="<?= HtmlHelper::e($socialLinkedin) ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-linkedin"></i></a>
                        <?php endif; ?>
                        <?php if ($socialBluesky !== ''): ?>
                            <a href="<?= HtmlHelper::e($socialBluesky) ?>" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-bluesky"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <hr class="my-4">
            <div class="text-center">
                <?php
                $versionFile = dirname(dirname(dirname(__DIR__))) . '/version.json';
                $versionData = file_exists($versionFile) ? json_decode(file_get_contents($versionFile), true) : null;
                $version = $versionData['version'] ?? '0.1.1';
                ?>
                <p><?= date('Y') ?> • <?= HtmlHelper::e($appName) ?> • Powered by Pinakes v<?= HtmlHelper::e($version) ?></p>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="<?= assetUrl('/vendor.bundle.js') ?>"></script>
    <script src="<?= assetUrl('/flatpickr-init.js') ?>"></script>
    <script src="<?= assetUrl('/main.bundle.js') ?>" defer></script>
    <script src="<?= assetUrl('/js/swal-config.js') ?>" defer></script>
    <script>
        // Smooth scrolling
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const href = this.getAttribute('href');
                if (href && href.length > 1 && href !== '#') {
                    const target = document.querySelector(href);
                    if (target) {
                        target.scrollIntoView({
                            behavior: 'smooth'
                        });
                    }
                }
            });
        });

        // Add fade-in animation to cards on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in');
                }
            });
        }, observerOptions);

        document.querySelectorAll('.book-card').forEach(card => {
            observer.observe(card);
        });

        // Load user reservations count for badge
        (async function(){
            const badge = document.getElementById('nav-res-count');
            if (!badge) return;
            try {
                const r = await fetch('/api/user/reservations/count');
                if (!r.ok) return;
                const data = await r.json();
                const c = parseInt(data.count || 0, 10);
                if (c > 0) {
                    badge.textContent = String(c);
                    badge.classList.remove('d-none');
                }
            } catch(_){}
        })();

        // Search functionality with preview
        (function() {
            const searchInputs = document.querySelectorAll('.search-input');
            let searchTimeout;
            let currentSearchInput = null;

            searchInputs.forEach(input => {
                // Create search results container
                const searchContainer = input.closest('.search-form');
                const resultsContainer = document.createElement('div');
                resultsContainer.className = 'search-results';
                // Responsive sizing based on screen width
                const isMobile = window.innerWidth <= 768;
                resultsContainer.style.cssText =
                    'position: absolute;' +
                    'top: 100%;' +
                    (isMobile ? 'left: -10px; right: -10px;' : 'left: -20px; right: -20px;') +
                    'background: white;' +
                    'border: 1px solid #e5e7eb;' +
                    'border-radius: 0.75rem;' +
                    'box-shadow: none;' +
                    (isMobile ? 'max-height: 70vh;' : 'max-height: 600px;') +
                    'overflow-y: auto;' +
                    'z-index: 1000;' +
                    'display: none;' +
                    (isMobile ? '' : 'min-width: 500px;');
                searchContainer.style.position = 'relative';
                searchContainer.appendChild(resultsContainer);

                // Search input event
                input.addEventListener('input', function(e) {
                    const query = e.target.value.trim();
                    currentSearchInput = input;

                    clearTimeout(searchTimeout);

                    if (query.length < 2) {
                        hideSearchResults();
                        return;
                    }

                    searchTimeout = setTimeout(() => {
                        performSearch(query, resultsContainer);
                    }, 300);
                });

                // Handle form submission - allow normal submission to catalogo
                input.closest('form').addEventListener('submit', function(e) {
                    hideSearchResults();
                });

                // Hide results when clicking outside
                document.addEventListener('click', function(e) {
                    if (!searchContainer.contains(e.target)) {
                        hideSearchResults();
                    }
                });
            });

            const escapeHtml = (value) => {
                if (value === undefined || value === null) {
                    return '';
                }
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            };

            const sanitizeUrl = (value) => {
                if (typeof value !== 'string') {
                    return '#';
                }
                const trimmed = value.trim();
                if (trimmed.startsWith('javascript:')) {
                    return '#';
                }
                if (trimmed.startsWith('/') || trimmed.startsWith('http')) {
                    return trimmed;
                }
                return '#';
            };

            function performSearch(query, resultsContainer) {
                fetch('<?= absoluteUrl('/api/search/preview') ?>?q=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        displaySearchResults(data, resultsContainer);
                    })
                    .catch(error => {
                        console.error('Search error:', error);
                        hideSearchResults();
                    });
            }

            function displaySearchResults(results, container) {
                if (results.length === 0) {
                    container.innerHTML = '<div class="search-no-results" style="padding: 1rem; text-align: center; color: #9ca3af;">Nessun risultato trovato</div>';
                    container.style.display = 'block';
                    return;
                }

                let html = '';

                // Group results by type
                const books = results.filter(r => r.type === 'book');
                const authors = results.filter(r => r.type === 'author');
                const publishers = results.filter(r => r.type === 'publisher');

                // Books section
                if (books.length > 0) {
                    html += '<div class="search-section" style="padding: 0.75rem 0; border-bottom: 1px solid #f3f4f6;"><h6 class="search-section-title" style="margin: 0 1rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;">Libri</h6>';
                    books.forEach(book => {
                        const bookUrl = sanitizeUrl(book.url ?? '#');
                        const coverUrl = sanitizeUrl(book.cover ?? '');
                        const bookTitle = escapeHtml(book.title ?? '');
                        const bookAuthor = escapeHtml(book.author ?? '');
                        const bookYear = escapeHtml(book.year ?? '');

                        html += '<a href="' + bookUrl + '" class="search-result-item book-result" style="display: flex; align-items: center; padding: 0.75rem 1rem; text-decoration: none; color: #000000; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor=\'#f9fafb\'" onmouseout="this.style.backgroundColor=\'transparent\'">' +
                                '<img src="' + coverUrl + '" alt="' + bookTitle + '" class="search-book-cover" style="width: 40px; height: 60px; object-fit: cover; border-radius: 0.25rem; margin-right: 0.75rem;">' +
                                '<div class="search-book-info">' +
                                    '<div class="search-book-title" style="font-weight: 600; font-size: 0.875rem; margin-bottom: 0.25rem; line-height: 1.2; color: #000000;">' + bookTitle + '</div>' +
                                    (book.author ? '<div class="search-book-author" style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.125rem;">' + bookAuthor + '</div>' : '') +
                                    (book.year ? '<div class="search-book-year" style="font-size: 0.75rem; color: #9ca3af;">' + bookYear + '</div>' : '') +
                                '</div>' +
                            '</a>';
                    });
                    html += '</div>';
                }

                // Authors section
                if (authors.length > 0) {
                    html += '<div class="search-section" style="padding: 0.75rem 0; border-bottom: 1px solid #f3f4f6;"><h6 class="search-section-title" style="margin: 0 1rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;">Autori</h6>';
                    authors.forEach(author => {
                        const authorUrl = sanitizeUrl(author.url ?? '#');
                        const authorName = escapeHtml(author.name ?? '');
                        const authorBooks = escapeHtml(author.book_count ?? '0') + ' libri';
                        const authorBio = escapeHtml(author.biography ?? '');

                        html += '<a href="' + authorUrl + '" class="search-result-item author-result" style="display: flex; align-items: center; padding: 0.75rem 1rem; text-decoration: none; color: #000000; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor=\'#f9fafb\'" onmouseout="this.style.backgroundColor=\'transparent\'">' +
                                '<div class="search-author-icon" style="width: 40px; height: 40px; background: #f3f4f6; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 0.75rem; color: #6b7280;"><i class="fas fa-user"></i></div>' +
                                '<div class="search-author-info">' +
                                    '<div class="search-author-name" style="font-weight: 600; font-size: 0.875rem; margin-bottom: 0.25rem; color: #000000;">' + authorName + '</div>' +
                                    '<div class="search-author-books" style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.125rem;">' + authorBooks + '</div>' +
                                    (author.biography ? '<div class="search-author-bio" style="font-size: 0.75rem; color: #9ca3af; line-height: 1.2;">' + authorBio + '</div>' : '') +
                                '</div>' +
                            '</a>';
                    });
                    html += '</div>';
                }

                // Publishers section
                if (publishers.length > 0) {
                    html += '<div class="search-section" style="padding: 0.75rem 0; border-bottom: 1px solid #f3f4f6;"><h6 class="search-section-title" style="margin: 0 1rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;">Editori</h6>';
                    publishers.forEach(publisher => {
                        const publisherUrl = sanitizeUrl(publisher.url ?? '#');
                        const publisherName = escapeHtml(publisher.name ?? '');
                        const publisherBooks = escapeHtml(publisher.book_count ?? '0') + ' libri';
                        const publisherDesc = escapeHtml(publisher.description ?? '');

                        html += '<a href="' + publisherUrl + '" class="search-result-item publisher-result" style="display: flex; align-items: center; padding: 0.75rem 1rem; text-decoration: none; color: #000000; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor=\'#f9fafb\'" onmouseout="this.style.backgroundColor=\'transparent\'">' +
                                '<div class="search-publisher-icon" style="width: 40px; height: 40px; background: #f3f4f6; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 0.75rem; color: #6b7280;"><i class="fas fa-building"></i></div>' +
                                '<div class="search-publisher-info">' +
                                    '<div class="search-publisher-name" style="font-weight: 600; font-size: 0.875rem; margin-bottom: 0.25rem; color: #000000;">' + publisherName + '</div>' +
                                    '<div class="search-publisher-books" style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.125rem;">' + publisherBooks + '</div>' +
                                    (publisher.description ? '<div class="search-publisher-desc" style="font-size: 0.75rem; color: #9ca3af; line-height: 1.2;">' + publisherDesc + '</div>' : '') +
                                '</div>' +
                            '</a>';
                    });
                    html += '</div>';
                }

                // Add "View all results" link
                html += '<div class="search-section" style="padding: 0.75rem 1rem;">' +
                        '<a href="<?= absoluteUrl('/catalogo') ?>?search=' + encodeURIComponent(currentSearchInput.value) + '"' +
                           ' class="search-view-all" style="display: flex; align-items: center; justify-content: center; padding: 0.5rem; background: #f3f4f6; border-radius: 0.375rem; text-decoration: none; color: #000000; font-weight: 500; font-size: 0.875rem; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor=\'#e5e7eb\'" onmouseout="this.style.backgroundColor=\'#f3f4f6\'">' +
                            'Vedi tutti i risultati <i class="fas fa-arrow-right" style="margin-left: 0.5rem; font-size: 0.75rem;"></i>' +
                        '</a>' +
                    '</div>';

                container.innerHTML = html;
                container.style.display = 'block';
            }

            function hideSearchResults() {
                document.querySelectorAll('.search-results').forEach(container => {
                    container.style.display = 'none';
                });
            }

            // Update search results sizing on window resize
            function updateSearchResultsSize() {
                const isMobile = window.innerWidth <= 768;
                document.querySelectorAll('.search-results').forEach(container => {
                    const leftRight = isMobile ? 'left: -10px; right: -10px;' : 'left: -20px; right: -20px;';
                    const maxHeight = isMobile ? 'max-height: 70vh;' : 'max-height: 600px;';
                    const minWidth = isMobile ? '' : 'min-width: 500px;';

                    // Update only the relevant styles
                    container.style.left = isMobile ? '-10px' : '-20px';
                    container.style.right = isMobile ? '-10px' : '-20px';
                    container.style.maxHeight = isMobile ? '70vh' : '600px';
                    if (!isMobile) {
                        container.style.minWidth = '500px';
                    } else {
                        container.style.minWidth = '';
                    }
                });
            }

            window.addEventListener('resize', updateSearchResultsSize);
        })();
    </script>

    <!-- Mobile Menu Script -->
    <script>
        (function() {
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const mobileMenuClose = document.getElementById('mobileMenuClose');
            const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');

            if (mobileMenuToggle && mobileMenuClose && mobileMenuOverlay) {
                // Open menu
                mobileMenuToggle.addEventListener('click', function() {
                    mobileMenuOverlay.classList.add('active');
                    document.body.style.overflow = 'hidden';
                });

                // Close menu
                mobileMenuClose.addEventListener('click', function() {
                    mobileMenuOverlay.classList.remove('active');
                    document.body.style.overflow = '';
                });

                // Close on overlay click
                mobileMenuOverlay.addEventListener('click', function(e) {
                    if (e.target === mobileMenuOverlay) {
                        mobileMenuOverlay.classList.remove('active');
                        document.body.style.overflow = '';
                    }
                });
            }
        })();

        // Mobile Search Toggle Script
        (function() {
            const mobileSearchToggle = document.getElementById('mobileSearchToggle');
            const mobileSearchContainer = document.getElementById('mobileSearchContainer');

            if (mobileSearchToggle && mobileSearchContainer) {
                mobileSearchToggle.addEventListener('click', function() {
                    // Toggle active class with smooth animation
                    mobileSearchContainer.classList.toggle('active');

                    // Toggle icon between search and close
                    const icon = mobileSearchToggle.querySelector('i');
                    if (icon) {
                        if (mobileSearchContainer.classList.contains('active')) {
                            icon.classList.remove('fa-search');
                            icon.classList.add('fa-times');

                            // Focus on search input after animation
                            setTimeout(() => {
                                const searchInput = mobileSearchContainer.querySelector('.search-input');
                                if (searchInput) {
                                    searchInput.focus();
                                }
                            }, 300);
                        } else {
                            icon.classList.remove('fa-times');
                            icon.classList.add('fa-search');
                        }
                    }
                });
            }
        })();

        // Keyboard shortcuts
        function initializeKeyboardShortcuts() {
            document.addEventListener('keydown', function(e) {
                // ESC to close all popups
                if (e.key === 'Escape') {
                    // Close SweetAlert2 if open
                    if (window.Swal && typeof window.Swal.close === 'function') {
                        window.Swal.close();
                    }

                    // Close mobile search bar
                    const mobileSearchContainer = document.getElementById('mobile-search-container');
                    if (mobileSearchContainer && mobileSearchContainer.classList.contains('active')) {
                        mobileSearchContainer.classList.remove('active');
                    }

                    // Blur focused input/button
                    if (document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'BUTTON')) {
                        document.activeElement.blur();
                    }
                }
            });
        }

        // Cookie Settings Button functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize keyboard shortcuts
            initializeKeyboardShortcuts();

        });
    </script>

    <?= $additional_js ?? '' ?>

    <!-- Silktide Consent Manager -->
    <script src="<?= assetUrl('/js/silktide-consent-manager.js') ?>"></script>
    <script>
        // Configurazione Cookie Banner (caricata da ConfigStore)
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
                    onAccept: function() {
                        // Qui puoi attivare Google Analytics o altri tool
                    },
                    onReject: function() {
                        // Cookie analitici rifiutati
                    },
                },
                <?php endif; ?>
                <?php if ($showMarketing): ?>
                {
                    id: 'marketing',
                    name: <?= json_encode($cookieBannerTexts['cookie_marketing_name'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    description: <?= json_encode($cookieBannerTexts['cookie_marketing_description'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    defaultValue: false,
                    onAccept: function() {
                        // Cookie di marketing accettati
                    },
                    onReject: function() {
                        // Cookie di marketing rifiutati
                    },
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
                    saveSelectedButtonText: <?= json_encode($cookieBannerTexts['save_selected_text'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    saveSelectedButtonAccessibleLabel: <?= json_encode($cookieBannerTexts['save_selected_text'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    preferencesButtonText: <?= json_encode($cookieBannerTexts['preferences_button_text'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    preferencesButtonAccessibleLabel: <?= json_encode($cookieBannerTexts['preferences_button_text'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                },
                preferences: {
                    title: <?= json_encode($cookieBannerTexts['preferences_title'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    description: <?= json_encode($cookieBannerTexts['preferences_description'], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    statementUrl: '/cookies',
                    statementAccessibleLabel: 'Maggiori informazioni sui cookie',
                },
            },
            position: {
                banner: 'bottomRight', // Opzioni: 'bottomRight', 'bottomLeft', 'center', 'bottomCenter'
                cookieIcon: 'bottomLeft', // Opzioni: 'bottomRight', 'bottomLeft'
            },
        });
    </script>
</body>
</html>

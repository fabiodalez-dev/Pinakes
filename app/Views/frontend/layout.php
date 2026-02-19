<?php $content = $content ?? '';
// $footerDescription is always set from ConfigStore (line ~16). For custom OG description, use $ogDescription.
$ogTitle = $ogTitle ?? null;
$twitterCard = $twitterCard ?? null;

use App\Support\Branding;
use App\Support\ConfigStore;
use App\Support\ContentSanitizer;
use App\Support\HtmlHelper;
use App\Support\I18n;

$appName = (string) ConfigStore::get('app.name', 'Pinakes');
$brandingLogo = Branding::logo();
$appLogo = $brandingLogo !== '' ? url($brandingLogo) : '';
$appInitial = mb_strtoupper(mb_substr($appName, 0, 1));
$footerDescription = (string) ConfigStore::get('app.footer_description', __('Il tuo sistema Pinakes per catalogare, gestire e condividere la tua collezione libraria.'));

$socialFacebook = (string) ConfigStore::get('app.social_facebook', '');
$socialTwitter = (string) ConfigStore::get('app.social_twitter', '');
$socialInstagram = (string) ConfigStore::get('app.social_instagram', '');
$socialLinkedin = (string) ConfigStore::get('app.social_linkedin', '');
$socialBluesky = (string) ConfigStore::get('app.social_bluesky', '');
$catalogRoute = route_path('catalog');
$reservationsRoute = route_path('reservations');
$wishlistRoute = route_path('wishlist');
$profileRoute = route_path('profile');
$loginRoute = route_path('login');
$registerRoute = route_path('register');

// Check if catalogue-only mode is enabled (hides loans, reservations, wishlist)
$isCatalogueMode = ConfigStore::isCatalogueMode();

// Load app version for cache busting
$versionFile = __DIR__ . '/../../../version.json';
$versionData = file_exists($versionFile) ? json_decode(file_get_contents($versionFile), true) : null;
$appVersion = $versionData['version'] ?? '0.1.0';

// Load theme colors
if (isset($container)) {
    $themeManager = $container->get('themeManager');
    $themeColorizer = $container->get('themeColorizer');
    $activeTheme = $themeManager->getActiveTheme();
    $themeColors = $themeManager->getThemeColors($activeTheme);
    $themePalette = $themeColorizer->generateColorPalette($themeColors);
} else {
    // Fallback colors when container is not available
    $themePalette = [
        'primary' => '#d70161',
        'secondary' => '#111827',
        'button' => '#d70262',
        'button_text' => '#ffffff',
        'primary_light' => '#f9e6ef',
        'primary_dark' => '#b8014f',
        'primary_hover' => '#c20157',
        'primary_focus' => '#b70152',
        'secondary_hover' => '#0f1623',
        'button_hover' => '#c20258',
        'primary_rgb' => '215, 1, 97',
        'button_rgb' => '215, 2, 98',
    ];
}

// Get events page status using ConfigStore (has its own DB connection)
$eventsEnabled = ConfigStore::get('cms.events_page_enabled', '1') === '1';

$currentLocale = I18n::getLocale();
$htmlLang = substr($currentLocale, 0, 2);
?>
<!DOCTYPE html>
<html lang="<?= $htmlLang ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= HtmlHelper::e($seoTitle ?? $title ?? $appName) ?></title>

    <!-- SEO Meta Tags -->
    <meta name="description"
        content="<?= HtmlHelper::e($seoDescription ?? ($appName . ' digitale con catalogo completo di libri disponibili per il prestito')) ?>">
    <?php if (isset($seoKeywords) && !empty($seoKeywords)): ?>
        <meta name="keywords" content="<?= htmlspecialchars($seoKeywords) ?>">
    <?php endif; ?>
    <link rel="canonical"
        href="<?= htmlspecialchars($seoCanonical ?? HtmlHelper::getCurrentUrl()) ?>">

    <!-- Hreflang Tags for Multilingual SEO -->
    <?php
    $currentUrl = HtmlHelper::getCurrentUrl();
    $currentLang = $_SESSION['locale'] ?? 'it_IT';
    $isItalian = str_starts_with($currentLang, 'it');

    // Remove existing lang parameter if present
    $baseUrlClean = preg_replace('/([?&])lang=[^&]*(&|$)/', '$1', $currentUrl);
    $baseUrlClean = rtrim($baseUrlClean, '?&');

    // Build hreflang URLs
    $separator = (str_contains($baseUrlClean, '?')) ? '&' : '?';
    $hreflangIt = $baseUrlClean . $separator . 'lang=it';
    $hreflangEn = $baseUrlClean . $separator . 'lang=en';
    ?>
    <link rel="alternate" hreflang="it" href="<?= htmlspecialchars($hreflangIt) ?>">
    <link rel="alternate" hreflang="en" href="<?= htmlspecialchars($hreflangEn) ?>">
    <link rel="alternate" hreflang="x-default" href="<?= htmlspecialchars($baseUrlClean) ?>">

    <?php
    $defaultOgImagePath = Branding::socialImage();
    $resolvedDefaultOgImage = $defaultOgImagePath !== '' ? absoluteUrl($defaultOgImagePath) : '';

    $ogTitle = $ogTitle ?? ($seoTitle ?? $title ?? $appName);
    $ogDescription = $ogDescription ?? ($seoDescription ?? ($footerDescription ?: __('Esplora il nostro catalogo digitale')));
    $ogType = $ogType ?? 'website';
    $ogUrl = $ogUrl ?? $baseUrlClean;
    $ogImage = $ogImage ?? $resolvedDefaultOgImage;
    $ogImage = $ogImage !== '' ? absoluteUrl($ogImage) : '';

    $twitterCard = $twitterCard ?? 'summary_large_image';
    $twitterTitle = $twitterTitle ?? $ogTitle;
    $twitterDescription = $twitterDescription ?? $ogDescription;
    $twitterImage = $twitterImage ?? $ogImage;
    $twitterImage = $twitterImage !== '' ? absoluteUrl($twitterImage) : '';
    ?>

    <!-- Open Graph Meta Tags -->
    <?php if ($ogTitle !== null): ?>
        <meta property="og:title" content="<?= htmlspecialchars($ogTitle) ?>">
        <meta property="og:description" content="<?= htmlspecialchars($ogDescription) ?>">
        <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
        <meta property="og:url" content="<?= htmlspecialchars($ogUrl) ?>">
        <meta property="og:type" content="<?= htmlspecialchars($ogType) ?>">
        <meta property="og:site_name" content="<?= HtmlHelper::e($appName) ?>">
    <?php endif; ?>

    <!-- Twitter Card Meta Tags -->
    <?php if ($twitterCard !== null): ?>
        <meta name="twitter:card" content="<?= htmlspecialchars($twitterCard) ?>">
        <meta name="twitter:title" content="<?= htmlspecialchars($twitterTitle) ?>">
        <meta name="twitter:description" content="<?= htmlspecialchars($twitterDescription) ?>">
        <meta name="twitter:image" content="<?= htmlspecialchars($twitterImage) ?>">
        <?php
        // Add Twitter handle if available (extract from social_twitter URL)
        if (!empty($socialTwitter)) {
            // Extract Twitter handle from URL (e.g., https://twitter.com/handle -> @handle)
            $twitterHandle = '';
            if (preg_match('/twitter\.com\/([^\/\?]+)/', $socialTwitter, $matches)) {
                $twitterHandle = '@' . $matches[1];
            } elseif (preg_match('/x\.com\/([^\/\?]+)/', $socialTwitter, $matches)) {
                $twitterHandle = '@' . $matches[1];
            }
            if ($twitterHandle):
                ?>
                <meta name="twitter:site" content="<?= htmlspecialchars($twitterHandle) ?>">
                <meta name="twitter:creator" content="<?= htmlspecialchars($twitterHandle) ?>">
            <?php endif;
        } ?>
    <?php endif; ?>

    <!-- Schema.org JSON-LD -->
    <?php if (isset($seoSchema)): ?>
        <script type="application/ld+json">
                <?= $seoSchema ?>
                </script>
    <?php endif; ?>

    <meta name="csrf-token"
        content="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>" />
    <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars(url('/favicon.ico'), ENT_QUOTES, 'UTF-8') ?>">
    <script>window.BASE_PATH = <?= json_encode(\App\Support\HtmlHelper::getBasePath(), JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>

    <!-- CSS moderno e minimale -->
    <link href="<?= htmlspecialchars(assetUrl('/vendor.css'), ENT_QUOTES, 'UTF-8') ?>?v=<?= $appVersion ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(assetUrl('/flatpickr-custom.css'), ENT_QUOTES, 'UTF-8') ?>?v=<?= $appVersion ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(assetUrl('/main.css'), ENT_QUOTES, 'UTF-8') ?>?v=<?= $appVersion ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(assetUrl('/css/swal-theme.css'), ENT_QUOTES, 'UTF-8') ?>?v=<?= $appVersion ?>" rel="stylesheet">

    <style>
        :root {
            /* Theme colors - Dynamically loaded from database */
            --primary-color:
                <?= htmlspecialchars($themePalette['primary'], ENT_QUOTES, 'UTF-8') ?>
            ;
            --primary-hover:
                <?= htmlspecialchars($themePalette['primary_hover'], ENT_QUOTES, 'UTF-8') ?>
            ;
            --primary-focus:
                <?= htmlspecialchars($themePalette['primary_focus'], ENT_QUOTES, 'UTF-8') ?>
            ;
            --secondary-color:
                <?= htmlspecialchars($themePalette['secondary'], ENT_QUOTES, 'UTF-8') ?>
            ;
            --secondary-hover:
                <?= htmlspecialchars($themePalette['secondary_hover'], ENT_QUOTES, 'UTF-8') ?>
            ;
            --button-color:
                <?= htmlspecialchars($themePalette['button'], ENT_QUOTES, 'UTF-8') ?>
            ;
            --button-text-color:
                <?= htmlspecialchars($themePalette['button_text'], ENT_QUOTES, 'UTF-8') ?>
            ;
            --button-hover:
                <?= htmlspecialchars($themePalette['button_hover'], ENT_QUOTES, 'UTF-8') ?>
            ;

            /* System colors - Not configurable (semantic colors) */
            --accent-color: #f1f5f9;
            --text-color: #0f172a;
            --text-light: #6b7280;
            --text-muted: #94a3b8;
            --light-bg: #f8f9fa;
            --white: #ffffff;
            --card-shadow: 0 4px 20px rgba(15, 23, 42, 0.08);
            --card-shadow-hover: 0 8px 30px rgba(15, 23, 42, 0.12);
            --border-color: #e2e8f0;
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
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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
            overflow: visible !important;
        }

        .header-main {
            padding: 1.25rem 0;
            overflow: visible !important;
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
            max-height: 45px;
            width: auto;
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

        .footer-logo img {
            max-height: 40px;
            object-fit: contain;
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

        .nav-links a:hover,
        .nav-links a.active {
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

        .nav-links a:hover::after,
        .nav-links a.active::after {
            width: 100%;
        }

        .search-form {
            flex: 1;
            max-width: 600px;
            overflow: visible !important;
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
            display: none;
            max-height: 0;
            transition: max-height 0.3s ease-in-out;
            width: 100%;
        }

        .mobile-search-container.active {
            display: block;
            max-height: 60px;
        }

        .btn-search-mobile {
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 0.75rem 1.25rem;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            white-space: nowrap;
        }

        .btn-search-mobile:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
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

        /* User Profile Dropdown */
        .user-dropdown {
            position: relative;
            display: inline-block;
        }

        .user-dropdown-toggle {
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .user-dropdown-toggle::after {
            content: '';
            border: solid white;
            border-width: 0 2px 2px 0;
            display: inline-block;
            padding: 3px;
            transform: rotate(45deg);
            margin-left: 4px;
            transition: transform 0.2s ease;
        }

        .user-dropdown.open .user-dropdown-toggle::after {
            transform: rotate(-135deg);
        }

        .user-dropdown-menu {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            min-width: 200px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.2s ease;
            z-index: 1050;
            border: 1px solid var(--border-color);
            overflow: hidden;
        }

        .user-dropdown.open .user-dropdown-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .user-dropdown-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: var(--text-color);
            text-decoration: none;
            font-size: 0.9rem;
            transition: background 0.15s ease;
        }

        .user-dropdown-menu a:hover {
            background: var(--accent-color);
        }

        .user-dropdown-menu a i {
            width: 18px;
            text-align: center;
            color: var(--text-light);
        }

        .user-dropdown-divider {
            height: 1px;
            background: var(--border-color);
            margin: 4px 0;
        }

        .user-dropdown-menu a.logout-link {
            color: var(--danger-color);
        }

        .user-dropdown-menu a.logout-link i {
            color: var(--danger-color);
        }

        .btn-outline-header {
            background: transparent;
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }

        .btn-outline-header:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            background: rgba(0, 0, 0, 0.02);
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
            border: 1.5px solid var(--button-color);
            background: var(--button-color);
            color: var(--button-text-color);
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
            color: var(--button-text-color);
            background: var(--button-hover);
            border-color: var(--button-hover);
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
            color: var(--button-color);
            border: 1.5px solid var(--button-color);
            box-shadow: none;
        }

        .btn-cta-outline:hover,
        .btn-cta-outline:focus {
            background: var(--button-color);
            color: var(--button-text-color);
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
            border: 1.5px solid var(--secondary-color);
            background: var(--secondary-color);
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
            background: var(--secondary-hover);
            border-color: var(--secondary-hover);
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
            border: 1.5px solid var(--secondary-color);
            background: transparent;
            color: var(--secondary-color);
            font-weight: 600;
            letter-spacing: -0.01em;
            transition: all 0.2s ease;
        }

        .btn.btn-outline-primary:hover,
        .btn.btn-outline-primary:focus,
        .btn-outline-primary:hover,
        .btn-outline-primary:focus {
            background: var(--secondary-color);
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
            gap: 1.5rem;
        }

        /* Header left wrapper - groups brand + nav on large screens */
        .header-left {
            display: contents; /* Invisible wrapper on small/medium screens */
        }

        @media (min-width: 1200px) {
            .header-left {
                display: flex;
                align-items: center;
                gap: 3rem;
            }
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
            object-fit: contain;
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

        .status-reserved {
            background: rgba(139, 92, 246, 0.9);
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
            border-top: 1px solid #e5e7eb;
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
                padding-top: 73px;
            }

            .header-brand {
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
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
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

        .gap-1 {
            gap: 0.25rem;
        }

        .gap-2 {
            gap: 0.5rem;
        }

        .gap-3 {
            gap: 1rem;
        }

        .gap-4 {
            gap: 1.5rem;
        }

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
                (function () {
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
                        document.addEventListener('DOMContentLoaded', function () {
                            setTimeout(loadCustomScripts, 200);
                        });
                    } else {
                        setTimeout(loadCustomScripts, 200);
                    }

                    // Ascolta cambiamenti consenso
                    window.addEventListener('silktideConsentChanged', function () {
                        setTimeout(loadCustomScripts, 100);
                    });

                    // Retry per i primi 3 secondi (in caso Cookie Control si carica lentamente)
                    let attempts = 0;
                    const retryInterval = setInterval(function () {
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
    <link rel="stylesheet" href="<?= htmlspecialchars(assetUrl('/css/silktide-consent-manager.css'), ENT_QUOTES, 'UTF-8') ?>">
    <script>
        window.__ = window.__ || function (key) { return key; };
    </script>
</head>

<?php
  $basePath = \App\Support\HtmlHelper::getBasePath();
  $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
  $isHome = ($requestPath === ($basePath ?: '/') || $requestPath === $basePath . '/' || $requestPath === $basePath . '/index.php');
?>
<body class="<?= $isHome ? 'home' : '' ?>">
    <!-- Minimalist Header -->
    <div class="header-container">
        <div class="header-main">
            <div class="container">
                <div class="header-content">
                    <!-- Wrapper for brand + nav on large screens -->
                    <div class="header-left">
                        <a class="header-brand" href="<?= absoluteUrl('/') ?>">
                            <?php if ($appLogo !== ''): ?>
                                <img src="<?= HtmlHelper::e($appLogo) ?>" alt="<?= HtmlHelper::e($appName) ?>"
                                    class="logo-image">
                            <?php else: ?>
                                <span class="brand-text"><?= HtmlHelper::e($appName) ?></span>
                            <?php endif; ?>
                        </a>

                        <ul class="nav-links d-none d-md-flex">
                            <li><a href="<?= absoluteUrl($catalogRoute) ?>"
                                    class="<?= strpos($_SERVER['REQUEST_URI'] ?? '', $catalogRoute) !== false ? 'active' : '' ?>"><?= __('Catalogo') ?></a>
                            </li>
                            <?php if ($eventsEnabled): ?>
                                <li><a href="<?= absoluteUrl('/events') ?>"
                                        class="<?= strpos($_SERVER['REQUEST_URI'] ?? '', '/events') !== false ? 'active' : '' ?>"><?= __('Eventi') ?></a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <!-- Mobile Search Toggle -->
                    <button class="mobile-search-toggle d-md-none" id="mobileSearchToggle"
                        aria-label="<?= __('Toggle search') ?>">
                        <i class="fas fa-search"></i>
                    </button>

                    <!-- Mobile Menu Toggle -->
                    <button class="mobile-menu-toggle d-md-none" id="mobileMenuToggle"
                        aria-label="<?= __('Toggle menu') ?>">
                        <i class="fas fa-bars"></i>
                    </button>

                    <form class="search-form d-none d-md-block" action="<?= absoluteUrl($catalogRoute) ?>" method="get">
                        <input class="search-input" type="search" name="q"
                            placeholder="<?= __('Cerca libri, autori, ISBN...') ?>" aria-label="<?= __('Search') ?>">
                    </form>

                    <div class="user-menu d-none d-md-flex">
                        <?php $isLogged = !empty($_SESSION['user'] ?? null); ?>

                        <?php if ($isLogged): ?>
                            <div class="d-flex align-items-center gap-2">
                                <?php if (!$isCatalogueMode): ?>
                                <a class="btn btn-outline-header" href="<?= absoluteUrl($reservationsRoute) ?>">
                                    <i class="fas fa-bookmark"></i>
                                    <span class="d-none d-sm-inline"><?= __('Prenotazioni') ?></span>
                                    <span id="nav-res-count" class="badge-notification d-none">0</span>
                                </a>
                                <a class="btn btn-outline-header" href="<?= absoluteUrl($wishlistRoute) ?>">
                                    <i class="fas fa-heart"></i>
                                    <span class="d-none d-sm-inline"><?= __('Preferiti') ?></span>
                                </a>
                                <?php endif; ?>
                                <?php if (isset($_SESSION['user']['tipo_utente']) && ($_SESSION['user']['tipo_utente'] === 'admin' || $_SESSION['user']['tipo_utente'] === 'staff')): ?>
                                    <a class="btn btn-primary-header" href="<?= absoluteUrl('/admin/dashboard') ?>">
                                        <i class="fas fa-user-shield"></i>
                                        <span class="d-none d-md-inline"><?= $_SESSION['user']['tipo_utente'] === 'admin' ? __('Admin') : __('Staff') ?></span>
                                    </a>
                                <?php else: ?>
                                    <div class="user-dropdown" id="userDropdown">
                                        <button class="btn btn-primary-header user-dropdown-toggle" type="button" aria-expanded="false" aria-haspopup="true">
                                            <i class="fas fa-user"></i>
                                            <span class="d-none d-md-inline"><?= HtmlHelper::safe($_SESSION['user']['name'] ?? $_SESSION['user']['username'] ?? __('Profilo')) ?></span>
                                        </button>
                                        <div class="user-dropdown-menu" role="menu">
                                            <a href="<?= absoluteUrl('/user/dashboard') ?>" role="menuitem">
                                                <i class="fas fa-tachometer-alt"></i>
                                                <?= __('La mia bacheca') ?>
                                            </a>
                                            <a href="<?= absoluteUrl($profileRoute) ?>" role="menuitem">
                                                <i class="fas fa-user-circle"></i>
                                                <?= __('Il mio profilo') ?>
                                            </a>
                                            <div class="user-dropdown-divider"></div>
                                            <a href="<?= htmlspecialchars(route_path('logout'), ENT_QUOTES, 'UTF-8') ?>" class="logout-link" role="menuitem">
                                                <i class="fas fa-sign-out-alt"></i>
                                                <?= __('Esci') ?>
                                            </a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="d-flex align-items-center gap-2">
                                <a class="btn btn-outline-header" href="<?= absoluteUrl($loginRoute) ?>">
                                    <i class="fas fa-sign-in-alt"></i>
                                    <span class="d-none d-sm-inline"><?= __('Accedi') ?></span>
                                </a>
                                <a class="btn btn-primary-header" href="<?= absoluteUrl($registerRoute) ?>">
                                    <i class="fas fa-user-plus"></i>
                                    <span class="d-none d-sm-inline"><?= __('Registrati') ?></span>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Mobile search container with animation -->
                    <div class="mobile-search-container d-md-none" id="mobileSearchContainer">
                        <form class="search-form w-100" action="<?= absoluteUrl($catalogRoute) ?>" method="get" style="display: flex; gap: 0.5rem;">
                            <input class="search-input" type="search" name="q" placeholder="<?= __('Cerca libri...') ?>"
                                aria-label="<?= __('Search') ?>" style="flex: 1;">
                            <button type="submit" class="btn-search-mobile" aria-label="<?= __('Cerca') ?>">
                                <i class="fas fa-search"></i>
                                <span class="d-none d-sm-inline"><?= __('Cerca') ?></span>
                            </button>
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
                    <a href="<?= absoluteUrl($catalogRoute) ?>"
                        class="mobile-nav-link <?= strpos($_SERVER['REQUEST_URI'] ?? '', $catalogRoute) !== false ? 'active' : '' ?>">
                        <i class="fas fa-book me-2"></i><?= __('Catalogo') ?>
                    </a>
                    <?php if ($eventsEnabled): ?>
                        <a href="<?= absoluteUrl('/events') ?>"
                            class="mobile-nav-link <?= strpos($_SERVER['REQUEST_URI'] ?? '', '/events') !== false ? 'active' : '' ?>">
                            <i class="fas fa-calendar-alt me-2"></i><?= __('Eventi') ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($isLogged): ?>
                        <hr class="mobile-menu-divider">
                        <a href="<?= absoluteUrl('/user/dashboard') ?>" class="mobile-nav-link">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                        </a>
                        <?php if (!$isCatalogueMode): ?>
                        <a href="<?= absoluteUrl($reservationsRoute) ?>" class="mobile-nav-link">
                            <i class="fas fa-bookmark me-2"></i><?= __("Prenotazioni") ?>
                        </a>
                        <a href="<?= absoluteUrl($wishlistRoute) ?>" class="mobile-nav-link">
                            <i class="fas fa-heart me-2"></i><?= __("Preferiti") ?>
                        </a>
                        <?php endif; ?>
                        <?php if (isset($_SESSION['user']['tipo_utente']) && ($_SESSION['user']['tipo_utente'] === 'admin' || $_SESSION['user']['tipo_utente'] === 'staff')): ?>
                            <a href="<?= absoluteUrl('/admin/dashboard') ?>" class="mobile-nav-link">
                                <i class="fas fa-user-shield me-2"></i><?= $_SESSION['user']['tipo_utente'] === 'admin' ? __("Admin") : __("Staff") ?>
                            </a>
                        <?php else: ?>
                            <a href="<?= absoluteUrl($profileRoute) ?>" class="mobile-nav-link">
                                <i class="fas fa-user me-2"></i><?= __("Profilo") ?>
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <hr class="mobile-menu-divider">
                        <a href="<?= absoluteUrl($loginRoute) ?>" class="mobile-nav-link">
                            <i class="fas fa-sign-in-alt me-2"></i><?= __("Accedi") ?>
                        </a>
                        <a href="<?= absoluteUrl($registerRoute) ?>" class="mobile-nav-link">
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
                <div class="col-lg-3 mb-lg-0" style="margin-bottom: 1.7rem;">
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
                        <li><a
                                href="<?= absoluteUrl(\App\Support\RouteTranslator::route('about')) ?>"><?= __("Chi Siamo") ?></a>
                        </li>
                        <li><a
                                href="<?= absoluteUrl(\App\Support\RouteTranslator::route('contact')) ?>"><?= __("Contatti") ?></a>
                        </li>
                        <li><a
                                href="<?= absoluteUrl(\App\Support\RouteTranslator::route('privacy')) ?>"><?= __("Privacy Policy") ?></a>
                        </li>
                        <li><a
                                href="<?= absoluteUrl(\App\Support\RouteTranslator::route('cookies')) ?>"><?= __("Cookies") ?></a>
                        </li>
                    </ul>
                </div>
                <div class="col-lg-3">
                    <h5><?= __("Account") ?></h5>
                    <ul class="list-unstyled">
                        <li><a
                                href="<?= absoluteUrl(\App\Support\RouteTranslator::route('user_dashboard')) ?>"><?= __("Dashboard") ?></a>
                        </li>
                        <li><a
                                href="<?= absoluteUrl(\App\Support\RouteTranslator::route('profile')) ?>"><?= __("Profilo") ?></a>
                        </li>
                        <?php if (!$isCatalogueMode): ?>
                        <li><a
                                href="<?= absoluteUrl(\App\Support\RouteTranslator::route('wishlist')) ?>"><?= __("Wishlist") ?></a>
                        </li>
                        <li><a
                                href="<?= absoluteUrl(\App\Support\RouteTranslator::route('reservations')) ?>"><?= __("Prenotazioni") ?></a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="col-lg-3">
                    <h5><?= __("Seguici") ?></h5>
                    <div class="d-flex gap-3 social-links">
                        <?php if ($socialFacebook !== ''): ?>
                            <a href="<?= HtmlHelper::e($socialFacebook) ?>" target="_blank" rel="noopener noreferrer"><i
                                    class="fab fa-facebook"></i></a>
                        <?php endif; ?>
                        <?php if ($socialTwitter !== ''): ?>
                            <a href="<?= HtmlHelper::e($socialTwitter) ?>" target="_blank" rel="noopener noreferrer"><i
                                    class="fab fa-twitter"></i></a>
                        <?php endif; ?>
                        <?php if ($socialInstagram !== ''): ?>
                            <a href="<?= HtmlHelper::e($socialInstagram) ?>" target="_blank" rel="noopener noreferrer"><i
                                    class="fab fa-instagram"></i></a>
                        <?php endif; ?>
                        <?php if ($socialLinkedin !== ''): ?>
                            <a href="<?= HtmlHelper::e($socialLinkedin) ?>" target="_blank" rel="noopener noreferrer"><i
                                    class="fab fa-linkedin"></i></a>
                        <?php endif; ?>
                        <?php if ($socialBluesky !== ''): ?>
                            <a href="<?= HtmlHelper::e($socialBluesky) ?>" target="_blank" rel="noopener noreferrer"><i
                                    class="fa-brands fa-bluesky"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <hr class="my-4">
            <div class="text-center">
                <p><?= date('Y') ?> • <?= HtmlHelper::e($appName) ?> • Powered by Pinakes
                    v<?= HtmlHelper::e($appVersion) ?></p>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="<?= htmlspecialchars(assetUrl('/vendor.bundle.js'), ENT_QUOTES, 'UTF-8') ?>?v=<?= $appVersion ?>"></script>
    <script src="<?= htmlspecialchars(assetUrl('/flatpickr-init.js'), ENT_QUOTES, 'UTF-8') ?>?v=<?= $appVersion ?>" defer></script>
    <script src="<?= htmlspecialchars(assetUrl('/main.bundle.js'), ENT_QUOTES, 'UTF-8') ?>?v=<?= $appVersion ?>" defer></script>
    <script src="<?= htmlspecialchars(assetUrl('/js/swal-config.js'), ENT_QUOTES, 'UTF-8') ?>?v=<?= $appVersion ?>" defer></script>
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
        (async function () {
            const badge = document.getElementById('nav-res-count');
            if (!badge) return;
            try {
                const r = await fetch(window.BASE_PATH + '/api/user/reservations/count');
                if (!r.ok) return;
                const data = await r.json();
                const c = parseInt(data.count || 0, 10);
                if (c > 0) {
                    badge.textContent = String(c);
                    badge.classList.remove('d-none');
                }
            } catch (_) { }
        })();

        // Search functionality with preview - wrapped in DOMContentLoaded for reliability
        document.addEventListener('DOMContentLoaded', function () {
            const searchInputs = document.querySelectorAll('.search-input');
            let searchTimeout;
            let currentSearchInput = null;

            searchInputs.forEach(input => {
                // Create search results container
                const searchContainer = input.closest('.search-form');
                if (!searchContainer) return;

                // Set parent to relative for absolute positioning
                searchContainer.style.position = 'relative';

                const resultsContainer = document.createElement('div');
                resultsContainer.className = 'search-results';
                resultsContainer.dataset.inputId = 'search-' + Math.random().toString(36).substr(2, 9);

                // Use simple absolute positioning inside parent
                const isMobile = window.innerWidth <= 768;
                resultsContainer.style.cssText =
                    'position: absolute;' +
                    'top: calc(100% + 15px);' +
                    'left: -20px;' +
                    'right: -20px;' +
                    'background: white;' +
                    'border: 1px solid #e5e7eb;' +
                    'border-radius: 0.75rem;' +
                    'box-shadow: 0 10px 40px rgba(0,0,0,0.15);' +
                    (isMobile ? 'max-height: 70vh;' : 'max-height: 600px;') +
                    'overflow-y: auto;' +
                    'overscroll-behavior: contain;' +
                    'z-index: 99999;' +
                    'display: none;' +
                    (isMobile ? 'min-width: 300px;' : 'min-width: 500px;') +
                    'pointer-events: auto;';

                // Append to parent
                searchContainer.appendChild(resultsContainer);

                // Search input event
                input.addEventListener('input', function (e) {
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
                input.closest('form').addEventListener('submit', function (e) {
                    hideSearchResults();
                });

                // Hide results when clicking outside
                document.addEventListener('click', function (e) {
                    if (!input.contains(e.target) && !resultsContainer.contains(e.target)) {
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
                const url = '<?= absoluteUrl('/api/search/preview') ?>?q=' + encodeURIComponent(query);
                fetch(url)
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
                if (!Array.isArray(results) || results.length === 0) {
                    container.innerHTML = '<div class="search-no-results" style="padding: 1rem; text-align: center; color: #9ca3af;">' + __('Nessun risultato trovato') + '</div>';
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
                    html += '<div class="search-section" style="padding: 0.75rem 0; border-bottom: 1px solid #f3f4f6;"><h6 class="search-section-title" style="margin: 0 1rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;">' + __('Libri') + '</h6>';
                    books.forEach(book => {
                        const bookUrl = sanitizeUrl(book.url ?? '#');
                        const coverUrl = sanitizeUrl(book.cover ?? '');
                        const bookTitle = escapeHtml(book.title ?? '');
                        const bookAuthor = escapeHtml(book.author ?? '');
                        const bookYear = escapeHtml(book.year ?? '');

                        html += '<a href="' + bookUrl + '" class="search-result-item book-result" style="display: flex; align-items: center; padding: 0.75rem 1rem; text-decoration: none; color: #000000; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor=\'#f9fafb\'" onmouseout="this.style.backgroundColor=\'transparent\'">' +
                            '<img src="' + coverUrl + '" alt="' + bookTitle + '" class="search-book-cover" style="width: 40px; height: 60px; object-fit: contain; border-radius: 0.25rem; margin-right: 0.75rem;">' +
                            '<div class="search-book-info">' +
                            '<div class="search-book-title" style="font-weight: 600; font-size: 0.875rem; margin-bottom: 0.25rem; line-height: 1.2; color: #000000;">' + bookTitle + '</div>' +
                            (book.author ? '<div class="search-book-author" style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.125rem; text-align: left;">' + bookAuthor + '</div>' : '') +
                            (book.year ? '<div class="search-book-year" style="font-size: 0.75rem; color: #9ca3af;">' + bookYear + '</div>' : '') +
                            '</div>' +
                            '</a>';
                    });
                    html += '</div>';
                }

                // Authors section
                if (authors.length > 0) {
                    html += '<div class="search-section" style="padding: 0.75rem 0; border-bottom: 1px solid #f3f4f6;"><h6 class="search-section-title" style="margin: 0 1rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;">' + __('Autori') + '</h6>';
                    authors.forEach(author => {
                        const authorUrl = sanitizeUrl(author.url ?? '#');
                        const authorName = escapeHtml(author.name ?? '');
                        const authorBooks = escapeHtml(author.book_count ?? '0') + __(' libri');
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
                    html += '<div class="search-section" style="padding: 0.75rem 0; border-bottom: 1px solid #f3f4f6;"><h6 class="search-section-title" style="margin: 0 1rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;">' + __('Editori') + '</h6>';
                    publishers.forEach(publisher => {
                        const publisherUrl = sanitizeUrl(publisher.url ?? '#');
                        const publisherName = escapeHtml(publisher.name ?? '');
                        const publisherBooks = escapeHtml(publisher.book_count ?? '0') + __(' libri');
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
                    '<a href="<?= absoluteUrl($catalogRoute) ?>?search=' + encodeURIComponent(currentSearchInput.value) + '"' +
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
        }); // End of DOMContentLoaded
    </script>

    <!-- Mobile Menu Script -->
    <script>
        (function () {
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const mobileMenuClose = document.getElementById('mobileMenuClose');
            const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');

            if (mobileMenuToggle && mobileMenuClose && mobileMenuOverlay) {
                // Open menu
                mobileMenuToggle.addEventListener('click', function () {
                    mobileMenuOverlay.classList.add('active');
                    document.body.style.overflow = 'hidden';
                });

                // Close menu
                mobileMenuClose.addEventListener('click', function () {
                    mobileMenuOverlay.classList.remove('active');
                    document.body.style.overflow = '';
                });

                // Close on overlay click
                mobileMenuOverlay.addEventListener('click', function (e) {
                    if (e.target === mobileMenuOverlay) {
                        mobileMenuOverlay.classList.remove('active');
                        document.body.style.overflow = '';
                    }
                });
            }
        })();

        // Mobile Search Toggle Script
        (function () {
            const mobileSearchToggle = document.getElementById('mobileSearchToggle');
            const mobileSearchContainer = document.getElementById('mobileSearchContainer');

            if (mobileSearchToggle && mobileSearchContainer) {
                mobileSearchToggle.addEventListener('click', function () {
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
            document.addEventListener('keydown', function (e) {
                // ESC to close all popups
                if (e.key === 'Escape') {
                    // Close SweetAlert2 if open
                    if (window.Swal && typeof window.Swal.close === 'function') {
                        window.Swal.close();
                    }

                    // Close mobile search bar (ID is mobileSearchContainer)
                    const mobileSearchEl = document.getElementById('mobileSearchContainer');
                    if (mobileSearchEl && mobileSearchEl.classList.contains('active')) {
                        mobileSearchEl.classList.remove('active');
                    }

                    // Blur focused input/button
                    if (document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'BUTTON')) {
                        document.activeElement.blur();
                    }
                }
            });
        }

        // Cookie Settings Button functionality
        document.addEventListener('DOMContentLoaded', function () {
            // Initialize keyboard shortcuts
            initializeKeyboardShortcuts();

        });
    </script>

    <script>
        (function () {
            const nativeAlert = typeof window.alert === 'function' ? window.alert.bind(window) : null;
            const alertTitle = <?= json_encode(__('Avviso')) ?>;
            const alertButton = <?= json_encode(__('OK')) ?>;

            window.alert = function (message) {
                const text = (message === undefined || message === null) ? '' : String(message);
                if (window.Swal && typeof window.Swal.fire === 'function') {
                    window.Swal.fire({
                        icon: 'info',
                        title: alertTitle,
                        text,
                        confirmButtonText: alertButton
                    });
                } else if (nativeAlert) {
                    nativeAlert(text);
                }
            };
        })();
    </script>

    <!-- User Dropdown Toggle -->
    <script>
    (function() {
        const dropdown = document.getElementById('userDropdown');
        if (!dropdown) return;

        const toggle = dropdown.querySelector('.user-dropdown-toggle');
        const menu = dropdown.querySelector('.user-dropdown-menu');

        if (!toggle || !menu) return;

        // Toggle dropdown on click
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const isOpen = dropdown.classList.contains('open');
            dropdown.classList.toggle('open');
            toggle.setAttribute('aria-expanded', !isOpen);
        });

        // Close on click outside
        document.addEventListener('click', function(e) {
            if (!dropdown.contains(e.target)) {
                dropdown.classList.remove('open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });

        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && dropdown.classList.contains('open')) {
                dropdown.classList.remove('open');
                toggle.setAttribute('aria-expanded', 'false');
                toggle.focus();
            }
        });
    })();
    </script>

    <?= $additional_js ?? '' ?>

    <?php require __DIR__ . '/../partials/cookie-banner.php'; ?>
</body>

</html>
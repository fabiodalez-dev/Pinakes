<?php $content = $content ?? '';
// $footerDescription is always set from ConfigStore (line ~16). For custom OG description, use $ogDescription.
$ogTitle = $ogTitle ?? null;
$ogBookMeta = is_iterable($ogBookMeta ?? null) ? $ogBookMeta : [];
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

$socialFacebook = HtmlHelper::sanitizePublicHttpUrl((string) ConfigStore::get('app.social_facebook', ''));
$socialTwitter = HtmlHelper::sanitizePublicHttpUrl((string) ConfigStore::get('app.social_twitter', ''));
$socialInstagram = HtmlHelper::sanitizePublicHttpUrl((string) ConfigStore::get('app.social_instagram', ''));
$socialLinkedin = HtmlHelper::sanitizePublicHttpUrl((string) ConfigStore::get('app.social_linkedin', ''));
$socialBluesky = HtmlHelper::sanitizePublicHttpUrl((string) ConfigStore::get('app.social_bluesky', ''));
$socialTelegram = HtmlHelper::sanitizePublicHttpUrl((string) ConfigStore::get('app.social_telegram', ''));
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
$frontendLayoutsMtime = @filemtime(dirname(__DIR__, 3) . '/public/assets/frontend-layouts.css');
$frontendLayoutsVersion = $frontendLayoutsMtime !== false ? (string)$frontendLayoutsMtime : $appVersion;
$frontendMainMtime = @filemtime(dirname(__DIR__, 3) . '/public/assets/main.css');
$frontendMainVersion = $frontendMainMtime !== false ? (string)$frontendMainMtime : $appVersion;
$frontendVendorMtime = @filemtime(dirname(__DIR__, 3) . '/public/assets/vendor.css');
$frontendVendorVersion = $frontendVendorMtime !== false ? (string)$frontendVendorMtime : $appVersion;
$frontendBundleMtime = @filemtime(dirname(__DIR__, 3) . '/public/assets/main.bundle.js');
$frontendBundleVersion = $frontendBundleMtime !== false ? (string)$frontendBundleMtime : $appVersion;
$flatpickrCustomMtime = @filemtime(dirname(__DIR__, 3) . '/public/assets/flatpickr-custom.css');
$flatpickrCustomVersion = $flatpickrCustomMtime !== false ? (string)$flatpickrCustomMtime : $appVersion;
$swalThemeMtime = @filemtime(dirname(__DIR__, 3) . '/public/assets/css/swal-theme.css');
$swalThemeVersion = $swalThemeMtime !== false ? (string)$swalThemeMtime : $appVersion;
$archivePagesMtime = @filemtime(dirname(__DIR__, 3) . '/public/assets/archive-pages.css');
$archivePagesVersion = $archivePagesMtime !== false ? (string)$archivePagesMtime : $appVersion;

// Load theme colors
if (isset($container)) {
    $themeManager = $container->get('themeManager');
    $themeColorizer = $container->get('themeColorizer');
    $activeTheme = $themeManager->getActiveTheme();
    $themeColors = $themeManager->getThemeColors($activeTheme);
    $themePalette = $themeColorizer->generateColorPalette($themeColors);
    $layoutVariant = $themeManager->getLayoutVariant($activeTheme);
} elseif (isset($db) && $db instanceof mysqli) {
    // Public views rendered by standalone controllers or plugins do not always
    // receive the DI container, but they do share the request's DB handle.
    // Resolve the same active theme from that handle so CMS/contact/plugin
    // pages never silently fall back to Editoriale.
    $themeManager = new \App\Support\ThemeManager($db);
    $themeColorizer = new \App\Support\ThemeColorizer();
    $activeTheme = $themeManager->getActiveTheme();
    $themeColors = $themeManager->getThemeColors($activeTheme);
    $themePalette = $themeColorizer->generateColorPalette($themeColors);
    $layoutVariant = $themeManager->getLayoutVariant($activeTheme);
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
    $layoutVariant = \App\Support\ThemeManager::DEFAULT_LAYOUT_VARIANT;
}

// Get events page status using ConfigStore (has its own DB connection)
$eventsEnabled = ConfigStore::get('cms.events_page_enabled', '1') === '1';

// Archive menu entry — shown only when the archives plugin is active AND
// at least one archival_unit is published. Plugin views can pre-populate
// $archivesAvailable / $archivesRoute (e.g. the /archivio page already
// knows the plugin is active and doesn't need a second round-trip); the
// fallback path below covers other pages (home, catalog, book-detail)
// using the $container DB connection.
$archivesAvailable = $archivesAvailable ?? false;
$archivesRoute = $archivesRoute ?? '/archive';
try {
    if (!$archivesAvailable && isset($container)) {
        // Use PluginManager::isActive() (per-process cached) instead of an
        // ad-hoc `SELECT 1 FROM plugins ...` query — this layout renders on
        // every frontend page, including anonymous catalog crawls, so the
        // raw lookup was an unconditional extra DB round-trip per request.
        $archivesPluginActive = false;
        if ($container->has('pluginManager')) {
            /** @var \App\Support\PluginManager $pluginManager */
            $pluginManager = $container->get('pluginManager');
            $archivesPluginActive = $pluginManager->isActive('archives');
        }
        if ($archivesPluginActive) {
            $dbConn = $container->get('db');
            if ($dbConn instanceof mysqli) {
                $unitCheck = $dbConn->query("SELECT 1 FROM archival_units WHERE deleted_at IS NULL LIMIT 1");
                if ($unitCheck instanceof mysqli_result && $unitCheck->num_rows === 1) {
                    $archivesAvailable = true;
                }
                if ($unitCheck instanceof mysqli_result) { $unitCheck->free(); }
            }
        }
    }
    if ($archivesAvailable) {
        $archivesRoute = \App\Support\RouteTranslator::route('archives') ?: '/archive';
    }
} catch (\Throwable $e) {
    // Table missing or plugin not fully activated yet — hide the menu entry.
    $archivesAvailable = false;
}

$currentLocale = I18n::getLocale();
$htmlLang = substr($currentLocale, 0, 2);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($htmlLang, ENT_QUOTES, 'UTF-8') ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Colour the mobile browser chrome (Android address bar / task switcher)
         with the active theme's primary colour, resolved server-side. -->
    <meta name="theme-color" content="<?= htmlspecialchars($themePalette['primary'] ?? '#d70161', ENT_QUOTES, 'UTF-8') ?>">
    <title><?= HtmlHelper::e($seoTitle ?? $title ?? $appName) ?></title>

    <!-- SEO Meta Tags -->
    <meta name="description"
        content="<?= htmlspecialchars($seoDescription ?? __('Biblioteca digitale con catalogo completo di libri disponibili per il prestito'), ENT_QUOTES, 'UTF-8') ?>">
    <?php if (isset($seoKeywords) && !empty($seoKeywords)): ?>
        <meta name="keywords" content="<?= htmlspecialchars($seoKeywords) ?>">
    <?php endif; ?>
    <?php if (!empty($seoRobots)): ?>
        <meta name="robots" content="<?= htmlspecialchars($seoRobots, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <link rel="canonical"
        href="<?= htmlspecialchars($seoCanonical ?? HtmlHelper::getCurrentUrlWithoutQuery(), ENT_QUOTES, 'UTF-8') ?>">
    <?php foreach (\App\Support\HreflangHelper::getAlternates() as $hreflangAlt): ?>
    <link rel="alternate" hreflang="<?= htmlspecialchars($hreflangAlt['hreflang'], ENT_QUOTES, 'UTF-8') ?>"
          href="<?= htmlspecialchars($hreflangAlt['href'], ENT_QUOTES, 'UTF-8') ?>">
    <?php endforeach; ?>
    <link rel="alternate" type="application/rss+xml"
          title="<?= HtmlHelper::e($appName) ?> - RSS"
          href="<?= htmlspecialchars(absoluteUrl('/feed.xml'), ENT_QUOTES, 'UTF-8') ?>">

    <?php
    $defaultOgImagePath = Branding::socialImage();
    $resolvedDefaultOgImage = $defaultOgImagePath !== '' ? absoluteUrl($defaultOgImagePath) : '';

    $ogTitle = $ogTitle ?? ($seoTitle ?? $title ?? $appName);
    $ogDescription = $ogDescription ?? ($seoDescription ?? ($footerDescription ?: __('Esplora il nostro catalogo digitale')));
    $ogType = $ogType ?? 'website';
    $ogUrl = $ogUrl ?? ($seoCanonical ?? HtmlHelper::getCurrentUrlWithoutQuery());
    $ogImage = $ogImage ?? $resolvedDefaultOgImage;
    $ogImage = $ogImage !== '' ? absoluteUrl($ogImage) : '';

    $twitterCard = $twitterCard ?? 'summary_large_image';
    $twitterTitle = $twitterTitle ?? $ogTitle;
    $twitterDescription = $twitterDescription ?? $ogDescription;
    $twitterImage = $twitterImage ?? $ogImage;
    $twitterImage = $twitterImage !== '' ? absoluteUrl($twitterImage) : '';
    ?>

    <!-- Open Graph Meta Tags -->
    <?php if ($ogTitle !== ''): ?>
        <meta property="og:title" content="<?= htmlspecialchars($ogTitle) ?>">
        <meta property="og:description" content="<?= htmlspecialchars($ogDescription) ?>">
        <?php if ($ogImage !== ''): ?>
        <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
        <?php endif; ?>
        <meta property="og:url" content="<?= htmlspecialchars($ogUrl) ?>">
        <meta property="og:type" content="<?= htmlspecialchars($ogType) ?>">
        <meta property="og:site_name" content="<?= HtmlHelper::e($appName) ?>">
        <meta property="og:locale" content="<?= htmlspecialchars(I18n::getLocale() ?: 'it_IT') ?>">
        <?php if (!empty($ogBookMeta)): ?>
            <?php foreach ($ogBookMeta as $bm): ?>
                <?php
                $prop = is_array($bm) ? (string) ($bm['property'] ?? '') : '';
                $cont = is_array($bm) ? (string) ($bm['content'] ?? '') : '';
                if ($prop === '' || $cont === '') { continue; }
                ?>
        <meta property="<?= htmlspecialchars($prop, ENT_QUOTES, 'UTF-8') ?>" content="<?= htmlspecialchars($cont, ENT_QUOTES, 'UTF-8') ?>">
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Twitter Card Meta Tags -->
    <?php if ($twitterCard !== ''): ?>
        <meta name="twitter:card" content="<?= htmlspecialchars($twitterCard) ?>">
        <meta name="twitter:title" content="<?= htmlspecialchars($twitterTitle) ?>">
        <meta name="twitter:description" content="<?= htmlspecialchars($twitterDescription) ?>">
        <?php if ($twitterImage !== ''): ?>
        <meta name="twitter:image" content="<?= htmlspecialchars($twitterImage) ?>">
        <?php endif; ?>
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
    <link href="<?= htmlspecialchars(assetUrl('/vendor.css'), ENT_QUOTES, 'UTF-8') ?>?v=<?= htmlspecialchars($frontendVendorVersion, ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(assetUrl('/flatpickr-custom.css'), ENT_QUOTES, 'UTF-8') ?>?v=<?= htmlspecialchars($flatpickrCustomVersion, ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(assetUrl('/main.css'), ENT_QUOTES, 'UTF-8') ?>?v=<?= htmlspecialchars($frontendMainVersion, ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(assetUrl('/frontend-layouts.css'), ENT_QUOTES, 'UTF-8') ?>?v=<?= htmlspecialchars($frontendLayoutsVersion, ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(assetUrl('/css/swal-theme.css'), ENT_QUOTES, 'UTF-8') ?>?v=<?= htmlspecialchars($swalThemeVersion, ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <?php if (!empty($archivePageStyles)): ?>
        <link href="<?= htmlspecialchars(assetUrl('/archive-pages.css'), ENT_QUOTES, 'UTF-8') ?>?v=<?= htmlspecialchars($archivePagesVersion, ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
    <?php endif; ?>
    <link href="<?= htmlspecialchars(assetUrl('fonts/fonts.css'), ENT_QUOTES, 'UTF-8') ?>?v=<?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">

    <?php
    // Hook: Allow plugins to enqueue assets in the head (e.g., CSS, fonts, meta tags)
    do_action('assets.head');

    if (!empty($headLinks) && is_array($headLinks)) {
        foreach ($headLinks as $hl) {
            if (!is_array($hl)) { continue; }
            $out = '<link';
            foreach (['rel', 'type', 'title', 'href', 'as', 'fetchpriority', 'crossorigin'] as $attr) {
                if (!empty($hl[$attr])) {
                    $val = (string) $hl[$attr];
                    if ($attr === 'href') {
                        $sanitized = filter_var($val, FILTER_SANITIZE_URL);
                        if ($sanitized === false || !preg_match('#^(https?://|/)#i', $sanitized)) { continue; }
                        $val = $sanitized;
                    }
                    $out .= ' ' . $attr . '="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"';
                }
            }
            echo $out . ">\n";
        }
    }
    ?>

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
            /* WCAG AA on white for normal-size metadata text. */
            --text-muted: #64748b;
            --light-bg: #f8f9fa;
            --white: #ffffff;
            /* Aliases so views that reference these names resolve globally
               (the mobile-menu header and the catalog use them; without a
               global definition they fell back to transparent/inherited). */
            --bg-primary: var(--white);
            --dark-color: var(--text-color);
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
            width: 44px;
            height: 44px;
            transition: color 0.3s ease;
            margin-left: auto;
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
            padding-top: 0.75rem;
        }

        .mobile-search-container.active {
            display: flex;
            justify-content: center;
            max-height: 76px;
        }

        .mobile-search-form {
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            width: min(100%, 42rem);
            max-width: none;
            margin-inline: auto;
        }

        .mobile-search-input {
            min-width: 0;
            flex: 1 1 auto;
        }

        .mobile-search-input:focus {
            border-color: transparent;
            outline: 2px solid var(--primary-color);
            outline-offset: 0;
            transform: none;
        }

        .hero-search-input.search-input {
            border: 0 !important;
            border-radius: 0 !important;
            background: transparent;
            transform: none;
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

        .search-form {
            position: relative;
        }

        .search-results {
            position: absolute;
            top: calc(100% + 0.625rem);
            left: 0;
            right: 0;
            z-index: 99999;
            display: none;
            width: 100%;
            min-width: 0;
            max-height: min(70vh, 38rem);
            overflow-y: auto;
            overscroll-behavior: contain;
            border: 1px solid var(--border-color);
            border-radius: 0.75rem;
            background: var(--white);
            box-shadow: 0 18px 48px rgba(15, 23, 42, 0.16);
            color: var(--text-color);
            /* Left-align every result label (titles, author/publisher names,
               section headers) regardless of any inherited text-align from the
               centered header; the "no results" message keeps its inline center. */
            text-align: left;
            pointer-events: auto;
        }

        .search-results.is-visible {
            display: block;
        }

        .btn-header {
            min-height: 38px;
            padding: 0.5rem 0.8rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
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
            background: var(--white);
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
            position: relative; /* anchor .badge-notification to this button, not the viewport */
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

        .ui-button.btn-primary,
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

        .ui-button.btn-primary:hover,
        .ui-button.btn-primary:focus,
        .btn-primary:hover,
        .btn-primary:focus {
            background: var(--secondary-hover);
            border-color: var(--secondary-hover);
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: none;
            text-decoration: none;
        }

        .ui-button.btn-outline-primary,
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

        .ui-button.btn-outline-primary:hover,
        .ui-button.btn-outline-primary:focus,
        .btn-outline-primary:hover,
        .btn-outline-primary:focus {
            background: var(--secondary-color);
            color: #ffffff;
            box-shadow: none;
            text-decoration: none;
        }

        .ui-button.btn-primary.btn-sm,
        .ui-button.btn-outline-primary.btn-sm,
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

        /* The badge styling sets display:flex, so the generic Tailwind
           .hidden utility alone loses on source order. Keep zero counts out
           of the layout until JavaScript has a positive count to show. */
        .badge-notification.hidden {
            display: none;
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
            width: 44px;
            height: 44px;
            transition: color 0.3s ease;
        }

        .mobile-menu-toggle:hover {
            color: var(--primary-color);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            flex-wrap: wrap;
            gap: 0.75rem;
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
            background: var(--success-color); /* fallback for browsers without color-mix() */
            background: color-mix(in srgb, var(--success-color) 90%, transparent);
            color: white;
            box-shadow: none;
        }

        .status-borrowed {
            background: var(--danger-color); /* fallback for browsers without color-mix() */
            background: color-mix(in srgb, var(--danger-color) 90%, transparent);
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
            background: var(--light-bg);
            color: var(--text-color);
            padding: 4rem 0 1.5rem;
            border-top: 1px solid var(--border-color);
        }

        .footer h5 {
            font-weight: 700;
            margin-bottom: 1.25rem;
            letter-spacing: -0.01em;
            color: var(--text-color);
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

        .footer ul li {
            margin-bottom: 0.5rem;
        }

        .footer .social-links a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--border-color);
            color: var(--text-color);
            transition: background 0.2s ease, color 0.2s ease;
        }

        .footer .social-links a:hover {
            background: #cbd5f5;
            color: var(--text-color);
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .nav-links {
                gap: 2rem;
            }
        }

        @media (min-width: 769px) {
            .mobile-menu-toggle,
            .mobile-menu-overlay {
                display: none !important;
            }

            .mobile-search-container {
                order: 10;
                flex-basis: 100%;
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
                padding: 0.45rem 0.65rem;
                font-size: 0.8rem;
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
                padding: 0.45rem 0.65rem;
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
            color: var(--text-light) !important;
        }

        .text-center {
            text-align: center;
        }

        .flex {
            display: flex;
        }

        .items-center {
            align-items: center;
        }

        .justify-between {
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
            background: var(--white);
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
            border-bottom: 1px solid var(--border-color);
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
            min-width: 44px;
            min-height: 44px;
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
            background: color-mix(in srgb, var(--primary-color) 10%, transparent);
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
            border-top: 1px solid var(--border-color);
        }

        /* ==========================================================
           PINAKES EDITORIAL — bold restyle (branch design/modern-frontend)
           Shared system across home, catalog, book. Colours + theme
           variables untouched; --primary-color is used BOLDLY as a
           committed surface. Self-hosted fonts. Crisp, minimal, few
           borders. Scoped to .main-content / frontend body.
           ========================================================== */
        :root{
            --serif:'Fraunces', Georgia, 'Times New Roman', serif;
            --sans:'Instrument Sans', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            --radius-sm:2px; --radius-md:2px; --radius-lg:2px; --radius-xl:3px;
            --card-shadow:none;
            --card-shadow-hover:0 1px 2px rgba(15,23,42,.05);
            --ink:var(--text-color);
            --edge:var(--border-color);
        }
        body{ font-family:var(--sans); letter-spacing:-.008em; }
        body, main, .main-content{ background:var(--white); }

        /* Fraunces display voice — light, elegant, big. */
        h1,h2,h3,h4,
        .hero-title,.section-title,.page-title,.book-title,.book-title-hero,
        .book-detail-title,.event-title,.cms-title,.page-hero__title,
        .related-book-title,.feature-title,.cta-title,.genre-carousel-title,
        .home-events__title{
            font-family:var(--serif) !important;
            font-weight:420 !important;
            letter-spacing:-.025em !important;
            line-height:1.05;
        }
        .header-brand,.header-brand .brand-text{ font-family:var(--serif) !important; font-weight:560 !important; letter-spacing:-.03em !important; }

        /* Eyebrow / micro-label motif (uppercase, tracked, accent). */
        .plabel, .eyebrow{ font:600 .72rem/1 var(--sans); letter-spacing:.16em; text-transform:uppercase; color:var(--primary-color); }

        /* --- De-round EVERYTHING (no pills) + flatten + de-box --- */
        .main-content input, .main-content select, .main-content textarea,
        .search-input,.hero-search-input,.hero-search-input-group,
        .btn-cta,.btn-cta-outline,.btn-cta-sm,.btn-cta-lg,.btn-header,.btn-search-mobile,
        .ui-button,.btn-primary,.btn-outline-primary,.btn-view,.btn-related-view,.btn-catalog,
        .book-card,.book-image-container,.book-status,.book-status-badge,.book-media-badge,
        .genre-tag,.keyword-chip,.availability-badge,.author-item,.status-badge,.chip,
        .filter-tag,.facet-collapsed,.feature-card,.feature-icon,.related-book-card,
        .event-card,.event-cover,.related-card,.page-link,.user-dropdown-menu,
        .cover-img,.book-cover-large,.hero-quick-link,.card{
            border-radius:2px !important;
        }
        .main-content .book-card,.main-content .feature-card,.main-content .related-book-card,
        .main-content .event-card,.main-content .card,.main-content .author-info,
        .main-content .archive-icon,.book-description-section,.book-details-section,
        .book-reviews-section,.book-meta{
            box-shadow:none !important;
            backdrop-filter:none !important;
            -webkit-backdrop-filter:none !important;
        }
        /* covers get the only real elevation */
        .book-card .book-image-container, .book-cover-large, .related-book-card .book-image-container{
            box-shadow:0 1px 3px rgba(15,23,42,.10) !important;
        }

        /* Buttons: crisp, confident. */
        .btn-cta,.btn-primary,.btn-outline-primary,.btn-view,.btn-catalog,.btn-related-view{
            letter-spacing:.01em; font-weight:600; text-transform:none;
        }

        /* ---- KILL THE "OLD" LOOK: no shadows, no gradients-as-decoration, no
           glass, everywhere inside the frontend. This is what makes it modern. */
        .main-content [class*="card"],
        .main-content [class*="section"],
        .main-content [class*="panel"],
        .main-content [class*="box"],
        .main-content [class*="feature"],
        .main-content [class*="stat"],
        .main-content [class*="badge"],
        .main-content [class*="tag"],
        .main-content [class*="tile"],
        .main-content .card, .main-content .well{
            box-shadow:none !important;
            backdrop-filter:none !important;
            -webkit-backdrop-filter:none !important;
            text-shadow:none !important;
        }
        /* covers are the only elevated objects */
        .main-content .book-image-container,
        .main-content .book-cover-large,
        .main-content .cover-img{
            box-shadow:0 1px 3px rgba(15,23,42,.12) !important;
        }
        /* generous editorial rhythm */
        .main-content .section,
        .main-content section[class*="section"]{ padding-top:clamp(3.5rem,8vw,6rem); padding-bottom:clamp(3.5rem,8vw,6rem); }

        /* Header: clean, crisp hairline, no heavy blur. */
        .header-container{ background:rgba(255,255,255,.94); backdrop-filter:saturate(1.03) blur(6px); -webkit-backdrop-filter:saturate(1.03) blur(6px); }

        /* ---- Elegant motion: fade-up on scroll (opt-in via .reveal, added by
           the observer below). Respects reduced-motion. ---- */
        .reveal{ opacity:0; transform:translateY(18px); }
        .reveal.is-in{ opacity:1; transform:none; transition:opacity .7s cubic-bezier(.22,1,.36,1), transform .7s cubic-bezier(.22,1,.36,1); }
        @media (prefers-reduced-motion: reduce){
            .reveal,.reveal.is-in{ opacity:1 !important; transform:none !important; transition:none !important; }
        }
        /* elegant hover: cover lift + link underline reveal */
        .main-content .book-card{ transition:transform .5s cubic-bezier(.22,1,.36,1); }
        .main-content .book-card:hover{ transform:translateY(-6px); }
        .main-content a.book-title-link, .main-content .book-title a{ background-image:linear-gradient(var(--primary-color),var(--primary-color)); background-size:0% 1px; background-position:0 100%; background-repeat:no-repeat; transition:background-size .4s cubic-bezier(.22,1,.36,1); }
        .main-content a.book-title-link:hover, .main-content .book-title a:hover{ background-size:100% 1px; }

        <?= $additional_css ?? '' ?>
    </style>

    <?php
    // Load custom CSS from settings (shared partial — also used by the auth
    // pages). Uses ContentSanitizer::sanitizeCustomCss() internally.
    require __DIR__ . '/../auth/partials/custom-css.php';
    ?>

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
        // Real translation table + helper for the public frontend (was an
        // identity-function no-op before, leaving JS strings in Italian for
        // non-IT visitors). Mirrors the admin and user-area layouts.
        <?php
        $frontendLocale = \App\Support\I18n::getLocale();
        $frontendTranslationFile = __DIR__ . '/../../../locale/' . $frontendLocale . '.json';
        $frontendTranslations = [];
        if (file_exists($frontendTranslationFile)) {
            $frontendTranslations = json_decode((string) file_get_contents($frontendTranslationFile), true) ?? [];
        }
        ?>
        window.i18nTranslations = <?= json_encode($frontendTranslations, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS) ?>;
        window.__ = function (key, ...args) {
            let translated = window.i18nTranslations[key] || key;
            if (args.length > 0) {
                let argIndex = 0;
                translated = translated.replace(/%(\d+\$)?[sd]/g, function (match, position) {
                    const resolvedIndex = position ? parseInt(position, 10) - 1 : argIndex++;
                    const value = args[resolvedIndex];
                    return value !== undefined ? String(value) : '';
                });
            }
            return translated;
        };
    </script>
</head>

<?php
  $basePath = \App\Support\HtmlHelper::getBasePath();
  $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
  $isHome = ($requestPath === ($basePath ?: '/') || $requestPath === $basePath . '/' || $requestPath === $basePath . '/index.php');
?>
<body class="<?= $isHome ? 'home ' : '' ?>layout-<?= htmlspecialchars($layoutVariant, ENT_QUOTES, 'UTF-8') ?>" data-layout="<?= htmlspecialchars($layoutVariant, ENT_QUOTES, 'UTF-8') ?>">
    <!-- Minimalist Header -->
    <div class="header-container">
        <div class="header-main">
            <div class="container">
                <div class="header-content">
                    <!-- Wrapper for brand + nav on large screens -->
                    <div class="header-left">
                        <a class="header-brand" href="<?= htmlspecialchars(absoluteUrl('/'), ENT_QUOTES, 'UTF-8') ?>">
                            <?php if ($appLogo !== ''): ?>
                                <img src="<?= HtmlHelper::e($appLogo) ?>" alt="<?= HtmlHelper::e($appName) ?>"
                                    class="logo-image">
                            <?php else: ?>
                                <span class="brand-text"><?= HtmlHelper::e($appName) ?></span>
                            <?php endif; ?>
                        </a>

                        <ul class="nav-links hidden md:flex">
                            <li><a href="<?= htmlspecialchars(absoluteUrl($catalogRoute), ENT_QUOTES, 'UTF-8') ?>"
                                    class="<?= strpos($_SERVER['REQUEST_URI'] ?? '', $catalogRoute) !== false ? 'active' : '' ?>"><?= __('Catalogo') ?></a>
                            </li>
                            <?php if ($archivesAvailable): ?>
                                <li><a href="<?= htmlspecialchars(absoluteUrl($archivesRoute), ENT_QUOTES, 'UTF-8') ?>"
                                        class="<?= strpos($_SERVER['REQUEST_URI'] ?? '', $archivesRoute) !== false ? 'active' : '' ?>"><?= __('Archivio') ?></a>
                                </li>
                            <?php endif; ?>
                            <?php if ($eventsEnabled): ?>
                                <li><a href="<?= htmlspecialchars(absoluteUrl('/events'), ENT_QUOTES, 'UTF-8') ?>"
                                        class="<?= strpos($_SERVER['REQUEST_URI'] ?? '', '/events') !== false ? 'active' : '' ?>"><?= __('Eventi') ?></a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <!-- Compact search toggle -->
                    <button class="mobile-search-toggle md:hidden" id="mobileSearchToggle"
                        aria-label="<?= __('Toggle search') ?>">
                        <i class="fas fa-search"></i>
                    </button>

                    <!-- Mobile Menu Toggle -->
                    <button class="mobile-menu-toggle md:hidden" id="mobileMenuToggle"
                        aria-label="<?= __('Toggle menu') ?>">
                        <i class="fas fa-bars"></i>
                    </button>

                    <form class="search-form hidden md:block" action="<?= htmlspecialchars(absoluteUrl($catalogRoute), ENT_QUOTES, 'UTF-8') ?>" method="get">
                        <input class="search-input" type="search" name="q"
                            placeholder="<?= __('Cerca libri, autori, ISBN...') ?>" aria-label="<?= __('Search') ?>">
                    </form>

                    <div class="user-menu hidden md:flex">
                        <?php $isLogged = !empty($_SESSION['user'] ?? null); ?>

                        <?php if ($isLogged): ?>
                            <div class="flex items-center gap-2">
                                <?php if (!$isCatalogueMode): ?>
                                <a class="btn-header btn-outline-header" href="<?= htmlspecialchars(absoluteUrl($reservationsRoute), ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="fas fa-bookmark"></i>
                                    <span class="hidden sm:inline"><?= __('Prenotazioni') ?></span>
                                    <span id="nav-res-count" class="badge-notification hidden">0</span>
                                </a>
                                <a class="btn-header btn-outline-header" href="<?= htmlspecialchars(absoluteUrl($wishlistRoute), ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="fas fa-heart"></i>
                                    <span class="hidden sm:inline"><?= __('Preferiti') ?></span>
                                </a>
                                <?php endif; ?>
                                <?php if (isset($_SESSION['user']['tipo_utente']) && ($_SESSION['user']['tipo_utente'] === 'admin' || $_SESSION['user']['tipo_utente'] === 'staff')): ?>
                                    <a class="btn-header btn-primary-header" href="<?= htmlspecialchars(absoluteUrl('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>">
                                        <i class="fas fa-user-shield"></i>
                                        <span class="hidden md:inline"><?= $_SESSION['user']['tipo_utente'] === 'admin' ? __('Admin') : __('Staff') ?></span>
                                    </a>
                                <?php else: ?>
                                    <div class="user-dropdown" id="userDropdown">
                                        <button class="btn-header btn-primary-header user-dropdown-toggle" type="button" aria-expanded="false" aria-haspopup="true">
                                            <i class="fas fa-user"></i>
                                            <span class="hidden md:inline"><?= HtmlHelper::safe($_SESSION['user']['name'] ?? $_SESSION['user']['username'] ?? __('Profilo')) ?></span>
                                        </button>
                                        <div class="user-dropdown-menu" role="menu">
                                            <a href="<?= htmlspecialchars(absoluteUrl('/user/dashboard'), ENT_QUOTES, 'UTF-8') ?>" role="menuitem">
                                                <i class="fas fa-tachometer-alt"></i>
                                                <?= __('La mia bacheca') ?>
                                            </a>
                                            <a href="<?= htmlspecialchars(absoluteUrl($profileRoute), ENT_QUOTES, 'UTF-8') ?>" role="menuitem">
                                                <i class="fas fa-user-circle"></i>
                                                <?= __('Il mio profilo') ?>
                                            </a>
                                            <div class="user-dropdown-divider"></div>
                                            <form method="post" action="<?= htmlspecialchars(route_path('logout'), ENT_QUOTES, 'UTF-8') ?>" style="display:contents;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                <a href="<?= htmlspecialchars(route_path('logout'), ENT_QUOTES, 'UTF-8') ?>" onclick="event.preventDefault();this.closest('form').submit();" class="logout-link" role="menuitem">
                                                    <i class="fas fa-sign-out-alt"></i>
                                                    <?= __('Esci') ?>
                                                </a>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="flex items-center gap-2">
                                <a class="btn-header btn-outline-header" href="<?= htmlspecialchars(absoluteUrl($loginRoute), ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="fas fa-sign-in-alt"></i>
                                    <span class="hidden sm:inline"><?= __('Accedi') ?></span>
                                </a>
                                <a class="btn-header btn-primary-header" href="<?= htmlspecialchars(absoluteUrl($registerRoute), ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="fas fa-user-plus"></i>
                                    <span class="hidden sm:inline"><?= __('Registrati') ?></span>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Mobile search container with animation -->
                    <div class="mobile-search-container md:hidden" id="mobileSearchContainer">
                        <form class="search-form mobile-search-form" action="<?= htmlspecialchars(absoluteUrl($catalogRoute), ENT_QUOTES, 'UTF-8') ?>" method="get">
                            <input class="search-input mobile-search-input" type="search" name="q" placeholder="<?= __('Cerca libri, autori, ISBN...') ?>"
                                aria-label="<?= __('Search') ?>" autocomplete="off">
                            <button type="submit" class="btn-search-mobile" aria-label="<?= __('Cerca') ?>">
                                <i class="fas fa-search"></i>
                                <span class="hidden sm:inline"><?= __('Cerca') ?></span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile Menu Overlay -->
        <div class="mobile-menu-overlay md:hidden" id="mobileMenuOverlay">
            <div class="mobile-menu-content">
                <div class="mobile-menu-header">
                    <span class="brand-text"><?= HtmlHelper::e($appName) ?></span>
                    <button class="mobile-menu-close" id="mobileMenuClose" aria-label="<?= __('Close menu') ?>">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <nav class="mobile-nav">
                    <a href="<?= htmlspecialchars(absoluteUrl($catalogRoute), ENT_QUOTES, 'UTF-8') ?>"
                        class="mobile-nav-link <?= strpos($_SERVER['REQUEST_URI'] ?? '', $catalogRoute) !== false ? 'active' : '' ?>">
                        <i class="fas fa-book mr-2"></i><?= __('Catalogo') ?>
                    </a>
                    <?php if ($archivesAvailable): ?>
                        <a href="<?= htmlspecialchars(absoluteUrl($archivesRoute), ENT_QUOTES, 'UTF-8') ?>"
                            class="mobile-nav-link <?= strpos($_SERVER['REQUEST_URI'] ?? '', $archivesRoute) !== false ? 'active' : '' ?>">
                            <i class="fas fa-archive mr-2"></i><?= __('Archivio') ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($eventsEnabled): ?>
                        <a href="<?= htmlspecialchars(absoluteUrl('/events'), ENT_QUOTES, 'UTF-8') ?>"
                            class="mobile-nav-link <?= strpos($_SERVER['REQUEST_URI'] ?? '', '/events') !== false ? 'active' : '' ?>">
                            <i class="fas fa-calendar-alt mr-2"></i><?= __('Eventi') ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($isLogged): ?>
                        <hr class="mobile-menu-divider">
                        <a href="<?= htmlspecialchars(absoluteUrl('/user/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="mobile-nav-link">
                            <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
                        </a>
                        <?php if (!$isCatalogueMode): ?>
                        <a href="<?= htmlspecialchars(absoluteUrl($reservationsRoute), ENT_QUOTES, 'UTF-8') ?>" class="mobile-nav-link">
                            <i class="fas fa-bookmark mr-2"></i><?= __("Prenotazioni") ?>
                        </a>
                        <a href="<?= htmlspecialchars(absoluteUrl($wishlistRoute), ENT_QUOTES, 'UTF-8') ?>" class="mobile-nav-link">
                            <i class="fas fa-heart mr-2"></i><?= __("Preferiti") ?>
                        </a>
                        <?php endif; ?>
                        <?php if (isset($_SESSION['user']['tipo_utente']) && ($_SESSION['user']['tipo_utente'] === 'admin' || $_SESSION['user']['tipo_utente'] === 'staff')): ?>
                            <a href="<?= htmlspecialchars(absoluteUrl('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="mobile-nav-link">
                                <i class="fas fa-user-shield mr-2"></i><?= $_SESSION['user']['tipo_utente'] === 'admin' ? __("Admin") : __("Staff") ?>
                            </a>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars(absoluteUrl($profileRoute), ENT_QUOTES, 'UTF-8') ?>" class="mobile-nav-link">
                                <i class="fas fa-user mr-2"></i><?= __("Profilo") ?>
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <hr class="mobile-menu-divider">
                        <a href="<?= htmlspecialchars(absoluteUrl($loginRoute), ENT_QUOTES, 'UTF-8') ?>" class="mobile-nav-link">
                            <i class="fas fa-sign-in-alt mr-2"></i><?= __("Accedi") ?>
                        </a>
                        <a href="<?= htmlspecialchars(absoluteUrl($registerRoute), ENT_QUOTES, 'UTF-8') ?>" class="mobile-nav-link">
                            <i class="fas fa-user-plus mr-2"></i><?= __("Registrati") ?>
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
            <div class="flex flex-wrap -mx-3">
                <div class="w-full lg:w-1/4 px-3" style="margin-bottom: 1.7rem;">
                    <?php if ($appLogo !== ''): ?>
                        <img src="<?= HtmlHelper::e($appLogo) ?>" alt="<?= HtmlHelper::e($appName) ?>" class="footer-logo">
                    <?php else: ?>
                        <h5><i class="fas fa-book-open mr-2"></i><?= HtmlHelper::e($appName) ?></h5>
                    <?php endif; ?>
                    <p><?= HtmlHelper::e($footerDescription) ?></p>
                </div>
                <div class="w-full lg:w-1/4 px-3">
                    <h5><?= __("Menu") ?></h5>
                    <ul class="list-none">
                        <li><a
                                href="<?= htmlspecialchars(absoluteUrl(\App\Support\RouteTranslator::route('about')), ENT_QUOTES, 'UTF-8') ?>"><?= __("Chi Siamo") ?></a>
                        </li>
                        <li><a
                                href="<?= htmlspecialchars(absoluteUrl(\App\Support\RouteTranslator::route('contact')), ENT_QUOTES, 'UTF-8') ?>"><?= __("Contatti") ?></a>
                        </li>
                        <li><a
                                href="<?= htmlspecialchars(absoluteUrl(\App\Support\RouteTranslator::route('privacy')), ENT_QUOTES, 'UTF-8') ?>"><?= __("Privacy Policy") ?></a>
                        </li>
                        <li><a
                                href="<?= htmlspecialchars(absoluteUrl(\App\Support\RouteTranslator::route('cookies')), ENT_QUOTES, 'UTF-8') ?>"><?= __("Cookies") ?></a>
                        </li>
                    </ul>
                </div>
                <div class="w-full lg:w-1/4 px-3">
                    <h5><?= __("Account") ?></h5>
                    <ul class="list-none">
                        <li><a
                                href="<?= htmlspecialchars(absoluteUrl(\App\Support\RouteTranslator::route('user_dashboard')), ENT_QUOTES, 'UTF-8') ?>"><?= __("Dashboard") ?></a>
                        </li>
                        <li><a
                                href="<?= htmlspecialchars(absoluteUrl(\App\Support\RouteTranslator::route('profile')), ENT_QUOTES, 'UTF-8') ?>"><?= __("Profilo") ?></a>
                        </li>
                        <?php if (!$isCatalogueMode): ?>
                        <li><a
                                href="<?= htmlspecialchars(absoluteUrl(\App\Support\RouteTranslator::route('wishlist')), ENT_QUOTES, 'UTF-8') ?>"><?= __("Wishlist") ?></a>
                        </li>
                        <li><a
                                href="<?= htmlspecialchars(absoluteUrl(\App\Support\RouteTranslator::route('reservations')), ENT_QUOTES, 'UTF-8') ?>"><?= __("Prenotazioni") ?></a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="w-full lg:w-1/4 px-3">
                    <h5><?= __("Seguici") ?></h5>
                    <div class="flex gap-3 social-links">
                        <?php if ($socialFacebook !== ''): ?>
                            <a href="<?= htmlspecialchars($socialFacebook, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><i
                                    class="fab fa-facebook"></i></a>
                        <?php endif; ?>
                        <?php if ($socialTwitter !== ''): ?>
                            <a href="<?= htmlspecialchars($socialTwitter, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><i
                                    class="fab fa-twitter"></i></a>
                        <?php endif; ?>
                        <?php if ($socialInstagram !== ''): ?>
                            <a href="<?= htmlspecialchars($socialInstagram, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><i
                                    class="fab fa-instagram"></i></a>
                        <?php endif; ?>
                        <?php if ($socialLinkedin !== ''): ?>
                            <a href="<?= htmlspecialchars($socialLinkedin, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><i
                                    class="fab fa-linkedin"></i></a>
                        <?php endif; ?>
                        <?php if ($socialBluesky !== ''): ?>
                            <a href="<?= htmlspecialchars($socialBluesky, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><i
                                    class="fa-brands fa-bluesky"></i></a>
                        <?php endif; ?>
                        <?php if ($socialTelegram !== ''): ?>
                            <a href="<?= htmlspecialchars($socialTelegram, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><i
                                    class="fa-brands fa-telegram"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <hr class="my-4">
            <div class="flex justify-center items-center gap-2">
                <p class="mb-0"><?= date('Y') ?> • <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?> • <a href="<?= htmlspecialchars('https://github.com/fabiodalez-dev/Pinakes', ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" title="GitHub" aria-label="Pinakes GitHub" class="hover:underline" style="color: inherit;"><i class="fa-brands fa-github"></i> Powered by Pinakes
                    v<?= htmlspecialchars((string) $appVersion, ENT_QUOTES, 'UTF-8') ?></a></p>
                <a href="<?= htmlspecialchars(url('/feed.xml'), ENT_QUOTES, 'UTF-8') ?>" title="<?= HtmlHelper::e(__('Feed RSS')) ?>" class="text-gray-500" aria-label="<?= HtmlHelper::e(__('Feed RSS')) ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><circle cx="6.18" cy="17.82" r="2.18"/><path d="M4 4.44v2.83c7.03 0 12.73 5.7 12.73 12.73h2.83c0-8.59-6.97-15.56-15.56-15.56zm0 5.66v2.83c3.9 0 7.07 3.17 7.07 7.07h2.83c0-5.47-4.43-9.9-9.9-9.9z"/></svg>
                </a>
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="<?= htmlspecialchars(assetUrl('/vendor.bundle.js'), ENT_QUOTES, 'UTF-8') ?>?v=<?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
    <script src="<?= htmlspecialchars(assetUrl('/flatpickr-init.js'), ENT_QUOTES, 'UTF-8') ?>?v=<?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <script src="<?= htmlspecialchars(assetUrl('/main.bundle.js'), ENT_QUOTES, 'UTF-8') ?>?v=<?= htmlspecialchars($frontendBundleVersion, ENT_QUOTES, 'UTF-8') ?>" defer></script>
    <script src="<?= htmlspecialchars(assetUrl('/js/swal-config.js'), ENT_QUOTES, 'UTF-8') ?>?v=<?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?>" defer></script>
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
                    badge.classList.remove('hidden');
                } else {
                    badge.textContent = '';
                    badge.classList.add('hidden');
                }
            } catch (_) { }
        })();

        // Search functionality with preview - wrapped in DOMContentLoaded for reliability
        document.addEventListener('DOMContentLoaded', function () {
            const searchInputs = document.querySelectorAll('.search-input');
            const searchTimers = new WeakMap();
            const activeRequests = new WeakMap();
            const searchCache = new Map();
            const SEARCH_CACHE_LIMIT = 30;
            const searchViewAllLabel = <?= json_encode(__('Vedi tutti i risultati'), JSON_HEX_TAG) ?>;

            searchInputs.forEach(input => {
                // Create search results container
                const searchContainer = input.closest('.search-form');
                if (!searchContainer) return;

                const resultsContainer = document.createElement('div');
                resultsContainer.className = 'search-results';
                resultsContainer.dataset.inputId = 'search-' + Math.random().toString(36).substr(2, 9);
                resultsContainer.setAttribute('role', 'listbox');

                // Append to parent
                searchContainer.appendChild(resultsContainer);

                // Search input event
                input.addEventListener('input', function (e) {
                    const query = e.target.value.trim();
                    clearTimeout(searchTimers.get(input));
                    const previousRequest = activeRequests.get(input);
                    if (previousRequest) {
                        previousRequest.abort();
                    }

                    if (query.length < 2) {
                        hideSearchResults(resultsContainer);
                        return;
                    }

                    const timer = setTimeout(() => {
                        performSearch(query, resultsContainer, input);
                    }, 180);
                    searchTimers.set(input, timer);
                });

                // Handle form submission - allow normal submission to catalogo
                input.closest('form').addEventListener('submit', function (e) {
                    hideSearchResults(resultsContainer);
                });

                // Hide results when clicking outside
                document.addEventListener('click', function (e) {
                    if (!input.contains(e.target) && !resultsContainer.contains(e.target)) {
                        hideSearchResults(resultsContainer);
                    }
                });

                input.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') {
                        hideSearchResults(resultsContainer);
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
                // Reject control characters (chr 0–31)
                if (/[\x00-\x1F]/.test(trimmed)) {
                    return '#';
                }
                // Reject dangerous schemes and protocol-relative URLs
                const lower = trimmed.toLowerCase();
                if (lower.startsWith('data:') || lower.startsWith('file:') || trimmed.startsWith('//')) {
                    return '#';
                }
                // Accept only https?:// absolute URLs or /path relative URLs
                if (/^https?:\/\//i.test(trimmed) || trimmed.startsWith('/')) {
                    return escapeHtml(trimmed);
                }
                return '#';
            };

            async function performSearch(query, resultsContainer, input) {
                const cacheKey = query.toLocaleLowerCase();
                if (searchCache.has(cacheKey)) {
                    displaySearchResults(searchCache.get(cacheKey), resultsContainer, query);
                    return;
                }

                const controller = new AbortController();
                activeRequests.set(input, controller);
                const url = <?= json_encode(absoluteUrl('/api/search/preview'), JSON_HEX_TAG | JSON_HEX_AMP) ?> + '?q=' + encodeURIComponent(query);
                try {
                    const response = await fetch(url, {
                        signal: controller.signal,
                        headers: { 'Accept': 'application/json' }
                    });
                    if (!response.ok) {
                        throw new Error('Search request failed: ' + response.status);
                    }
                    const data = await response.json();
                    if (input.value.trim().toLocaleLowerCase() !== cacheKey) {
                        return;
                    }
                    searchCache.set(cacheKey, data);
                    if (searchCache.size > SEARCH_CACHE_LIMIT) {
                        searchCache.delete(searchCache.keys().next().value);
                    }
                    displaySearchResults(data, resultsContainer, query);
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        console.error('Search error:', error);
                        hideSearchResults(resultsContainer);
                    }
                } finally {
                    if (activeRequests.get(input) === controller) {
                        activeRequests.delete(input);
                    }
                }
            }

            function displaySearchResults(results, container, query) {
                if (!Array.isArray(results) || results.length === 0) {
                    container.innerHTML = '<div class="search-no-results" style="padding: 1rem; text-align: center; color: var(--text-muted);">' + __('Nessun risultato trovato') + '</div>';
                    container.classList.add('is-visible');
                    return;
                }

                let html = '';

                // Group results by type
                const books = results.filter(r => r.type === 'book');
                const authors = results.filter(r => r.type === 'author');
                const publishers = results.filter(r => r.type === 'publisher');
                const archives = results.filter(r => r.type === 'archive');

                // Books section
                if (books.length > 0) {
                    html += '<div class="search-section" style="padding: 0.75rem 0; border-bottom: 1px solid var(--accent-color);"><h6 class="search-section-title" style="margin: 0 1rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;">' + __('Libri') + '</h6>';
                    books.forEach(book => {
                        const bookUrl = sanitizeUrl(book.url ?? '#');
                        const coverUrl = sanitizeUrl(book.cover ?? '');
                        const bookTitle = escapeHtml(book.title ?? '');
                        const bookSubtitle = escapeHtml(book.subtitle ?? '');
                        const bookAuthor = escapeHtml(book.author ?? '');
                        const bookYear = escapeHtml(book.year ?? '');

                        html += '<a href="' + bookUrl + '" class="search-result-item book-result" style="display: flex; align-items: center; padding: 0.75rem 1rem; text-decoration: none; color: var(--text-color); transition: background-color 0.2s;" onmouseover="this.style.backgroundColor=\'var(--light-bg)\'" onmouseout="this.style.backgroundColor=\'transparent\'">' +
                            '<img src="' + coverUrl + '" alt="' + bookTitle + '" class="search-book-cover" style="width: 40px; height: 60px; object-fit: contain; border-radius: 0.25rem; margin-right: 0.75rem;">' +
                            '<div class="search-book-info">' +
                            '<div class="search-book-title" style="font-weight: 600; font-size: 0.875rem; margin-bottom: 0.25rem; line-height: 1.2; color: var(--text-color); text-align: left;">' + bookTitle + '</div>' +
                            (book.subtitle ? '<div class="search-book-subtitle" style="font-size: 0.75rem; font-style: italic; color: var(--text-light); margin-bottom: 0.125rem; text-align: left;">' + bookSubtitle + '</div>' : '') +
                            (book.author ? '<div class="search-book-author" style="font-size: 0.75rem; color: var(--text-light); margin-bottom: 0.125rem; text-align: left;">' + bookAuthor + '</div>' : '') +
                            (book.year ? '<div class="search-book-year" style="font-size: 0.75rem; color: var(--text-muted); text-align: left;">' + bookYear + '</div>' : '') +
                            '</div>' +
                            '</a>';
                    });
                    html += '</div>';
                }

                // Authors section
                if (authors.length > 0) {
                    html += '<div class="search-section" style="padding: 0.75rem 0; border-bottom: 1px solid var(--accent-color);"><h6 class="search-section-title" style="margin: 0 1rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;">' + __('Autori') + '</h6>';
                    authors.forEach(author => {
                        const authorUrl = sanitizeUrl(author.url ?? '#');
                        const authorName = escapeHtml(author.name ?? '');
                        const authorBooks = escapeHtml(author.book_count ?? '0') + __(' libri');
                        const authorBio = escapeHtml(author.biography ?? '');

                        html += '<a href="' + authorUrl + '" class="search-result-item author-result" style="display: flex; align-items: center; padding: 0.75rem 1rem; text-decoration: none; color: var(--text-color); transition: background-color 0.2s;" onmouseover="this.style.backgroundColor=\'var(--light-bg)\'" onmouseout="this.style.backgroundColor=\'transparent\'">' +
                            '<div class="search-author-icon" style="width: 40px; height: 40px; background: var(--accent-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 0.75rem; color: var(--text-light);"><i class="fas fa-user"></i></div>' +
                            '<div class="search-author-info">' +
                            '<div class="search-author-name" style="font-weight: 600; font-size: 0.875rem; margin-bottom: 0.25rem; color: var(--text-color);">' + authorName + '</div>' +
                            '<div class="search-author-books" style="font-size: 0.75rem; color: var(--text-light); margin-bottom: 0.125rem;">' + authorBooks + '</div>' +
                            (author.biography ? '<div class="search-author-bio" style="font-size: 0.75rem; color: var(--text-muted); line-height: 1.2;">' + authorBio + '</div>' : '') +
                            '</div>' +
                            '</a>';
                    });
                    html += '</div>';
                }

                // Publishers section
                if (publishers.length > 0) {
                    html += '<div class="search-section" style="padding: 0.75rem 0; border-bottom: 1px solid var(--accent-color);"><h6 class="search-section-title" style="margin: 0 1rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;">' + __('Editori') + '</h6>';
                    publishers.forEach(publisher => {
                        const publisherUrl = sanitizeUrl(publisher.url ?? '#');
                        const publisherName = escapeHtml(publisher.name ?? '');
                        const publisherBooks = escapeHtml(publisher.book_count ?? '0') + __(' libri');
                        const publisherDesc = escapeHtml(publisher.description ?? '');

                        html += '<a href="' + publisherUrl + '" class="search-result-item publisher-result" style="display: flex; align-items: center; padding: 0.75rem 1rem; text-decoration: none; color: var(--text-color); transition: background-color 0.2s;" onmouseover="this.style.backgroundColor=\'var(--light-bg)\'" onmouseout="this.style.backgroundColor=\'transparent\'">' +
                            '<div class="search-publisher-icon" style="width: 40px; height: 40px; background: var(--accent-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 0.75rem; color: var(--text-light);"><i class="fas fa-building"></i></div>' +
                            '<div class="search-publisher-info">' +
                            '<div class="search-publisher-name" style="font-weight: 600; font-size: 0.875rem; margin-bottom: 0.25rem; color: var(--text-color);">' + publisherName + '</div>' +
                            '<div class="search-publisher-books" style="font-size: 0.75rem; color: var(--text-light); margin-bottom: 0.125rem;">' + publisherBooks + '</div>' +
                            (publisher.description ? '<div class="search-publisher-desc" style="font-size: 0.75rem; color: var(--text-muted); line-height: 1.2;">' + publisherDesc + '</div>' : '') +
                            '</div>' +
                            '</a>';
                    });
                    html += '</div>';
                }

                // Archives section
                if (archives.length > 0) {
                    html += '<div class="search-section" style="padding: 0.75rem 0; border-bottom: 1px solid var(--accent-color);"><h6 class="search-section-title" style="margin: 0 1rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;">' + __('Archivio') + '</h6>';
                    archives.forEach(arc => {
                        const arcUrl = sanitizeUrl(arc.url ?? '#');
                        const arcLabel = escapeHtml(arc.label ?? '');
                        const arcRef = escapeHtml(arc.identifier ?? '');
                        html += '<a href="' + arcUrl + '" class="search-result-item archive-result" style="display: flex; align-items: center; padding: 0.75rem 1rem; text-decoration: none; color: var(--text-color); transition: background-color 0.2s;" onmouseover="this.style.backgroundColor=\'var(--light-bg)\'" onmouseout="this.style.backgroundColor=\'transparent\'">' +
                            '<div style="width: 40px; height: 40px; background: color-mix(in srgb, var(--success-color) 15%, transparent); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 0.75rem; color: var(--success-color); flex-shrink: 0;"><i class="fas fa-archive" aria-hidden="true"></i></div>' +
                            '<div>' +
                            '<div style="font-weight: 600; font-size: 0.875rem; margin-bottom: 0.125rem; color: var(--text-color);">' + arcLabel + '</div>' +
                            (arcRef ? '<div style="font-size: 0.75rem; color: var(--text-light); font-family: monospace;">' + arcRef + '</div>' : '') +
                            '</div>' +
                            '</a>';
                    });
                    html += '</div>';
                }

                // Add "View all results" link
                html += '<div class="search-section" style="padding: 0.75rem 1rem;">' +
                    '<a href="' + <?= json_encode(absoluteUrl($catalogRoute), JSON_HEX_TAG | JSON_HEX_AMP) ?> + '?q=' + encodeURIComponent(query) + '"' +
                    ' class="search-view-all" style="display: flex; align-items: center; justify-content: center; padding: 0.5rem; background: var(--accent-color); border-radius: 0.375rem; text-decoration: none; color: var(--text-color); font-weight: 500; font-size: 0.875rem; transition: background-color 0.2s;" onmouseover="this.style.backgroundColor=\'var(--border-color)\'" onmouseout="this.style.backgroundColor=\'var(--accent-color)\'">' +
                    searchViewAllLabel + ' <i class="fas fa-arrow-right" style="margin-left: 0.5rem; font-size: 0.75rem;"></i>' +
                    '</a>' +
                    '</div>';

                container.innerHTML = html;
                container.classList.add('is-visible');
            }

            function hideSearchResults(container = null) {
                const containers = container ? [container] : document.querySelectorAll('.search-results');
                containers.forEach(resultContainer => {
                    resultContainer.classList.remove('is-visible');
                });
            }
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
            const alertTitle = <?= json_encode(__('Avviso'), JSON_HEX_TAG) ?>;
            const alertButton = <?= json_encode(__('OK'), JSON_HEX_TAG) ?>;

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

    <?php
    // Hook: Allow plugins to enqueue assets in the footer (e.g., JS scripts)
    do_action('assets.footer');
    ?>

    <?php require __DIR__ . '/../partials/cookie-banner.php'; ?>
    <?php require __DIR__ . '/../partials/scroll-to-top.php'; ?>

    <script>
    /* Editorial fade-up on scroll — adds .reveal to sections/cards at runtime
       (no markup changed) and reveals them as they enter the viewport.
       Fully skipped when the user prefers reduced motion. */
    (function () {
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        var sel = '.main-content .book-card, .main-content .feature-card, .main-content .event-card, ' +
                  '.main-content .related-book-card, .main-content .book-details-section, ' +
                  '.main-content .book-description-section, .main-content .book-reviews-section, ' +
                  '.main-content .section-title, .main-content .genre-carousel';
        var els = Array.prototype.slice.call(document.querySelectorAll(sel));
        if (!els.length || !('IntersectionObserver' in window)) return;
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); }
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
        els.forEach(function (el, i) {
            el.classList.add('reveal');
            el.style.transitionDelay = Math.min(i % 6, 5) * 40 + 'ms';
            io.observe(el);
        });
    })();
    </script>
</body>

</html>

<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\ThemeManager;

$manager = (new ReflectionClass(ThemeManager::class))->newInstanceWithoutConstructor();
$layout = file_get_contents($root . '/app/Views/frontend/layout.php');
$userLayout = file_get_contents($root . '/app/Views/user_layout.php');
$admin = file_get_contents($root . '/app/Views/admin/theme-customize.php');
$adminThemes = file_get_contents($root . '/app/Views/admin/themes.php');
$adminLayoutSelector = file_get_contents($root . '/app/Views/admin/partials/layout-variant-selector.php');
$routes = file_get_contents($root . '/app/Routes/web.php');
$controller = file_get_contents($root . '/app/Controllers/ThemeController.php');
$frontendController = file_get_contents($root . '/app/Controllers/FrontendController.php');
$contactController = file_get_contents($root . '/app/Controllers/ContactController.php');
$profileController = file_get_contents($root . '/app/Controllers/ProfileController.php');
$catalog = file_get_contents($root . '/app/Views/frontend/catalog.php');
$bookDetail = file_get_contents($root . '/app/Views/frontend/book-detail.php');
$css = file_get_contents($root . '/public/assets/frontend-layouts.css');
$archiveIndex = file_get_contents($root . '/storage/plugins/archives/views/public/index.php');
$bookClubBase = file_get_contents($root . '/storage/plugins/book-club/src/BaseController.php');
$frbrPlugin = file_get_contents($root . '/storage/plugins/frbr-lrm/FrbrLrmPlugin.php');
$frbrOpera = file_get_contents($root . '/storage/plugins/frbr-lrm/views/frontend/opera.php');
$goodLibBadges = file_get_contents($root . '/storage/plugins/goodlib/views/badges.php');
$digitalLibraryButtons = file_get_contents($root . '/storage/plugins/digital-library/views/frontend-buttons.php');
$digitalLibraryViewer = file_get_contents($root . '/storage/plugins/digital-library/views/frontend-pdf-viewer.php');
$digitalLibraryPlayer = file_get_contents($root . '/storage/plugins/digital-library/views/frontend-player.php');
$digitalLibraryCss = file_get_contents($root . '/storage/plugins/digital-library/assets/css/digital-library.css');
$homeHero = file_get_contents($root . '/app/Views/frontend/home-sections/hero.php');
$homeFeatures = file_get_contents($root . '/app/Views/frontend/home-sections/features_title.php');
$homeBooksGrid = file_get_contents($root . '/app/Views/frontend/home-books-grid.php');
$catalogGrid = file_get_contents($root . '/app/Views/frontend/catalog-grid.php');
$home = file_get_contents($root . '/app/Views/frontend/home.php');
$accountCss = file_get_contents($root . '/public/assets/account-pages.css');
$dashboardReservations = file_get_contents($root . '/app/Views/user_dashboard/prenotazioni.php');
$demoCatalogSeed = file_get_contents($root . '/scripts/seed-demo-catalog.php');
$profileReservations = file_get_contents($root . '/app/Views/profile/reservations.php');
$wishlist = file_get_contents($root . '/app/Views/profile/wishlist.php');
$adminBooks = file_get_contents($root . '/app/Views/libri/index.php');
$adminLayout = file_get_contents($root . '/app/Views/layout.php');
$adminSettings = file_get_contents($root . '/app/Views/settings/index.php');
$adminUiCss = file_get_contents($root . '/public/assets/admin-ui.css');
$vendorSource = file_get_contents($root . '/frontend/js/vendor.js');
$mainSource = file_get_contents($root . '/frontend/js/index.js');
$tailwindSource = file_get_contents($root . '/frontend/css/input.css');
$frontendPackage = json_decode((string) file_get_contents($root . '/frontend/package.json'), true);
$vendorCss = file_get_contents($root . '/public/assets/vendor.css');
$mainCss = file_get_contents($root . '/public/assets/main.css');
$swalThemeCss = file_get_contents($root . '/public/assets/css/swal-theme.css');
$archiveView = file_get_contents($root . '/app/Views/frontend/archive.php');
$archiveCss = file_get_contents($root . '/public/assets/archive-pages.css');
$designGuide = file_get_contents($root . '/DESIGN.md');
$bootstrapClassReference = false;
$bootstrapClassPattern = '/class\s*=\s*["\'][^"\'\n]*(?:\bd-(?:none|flex|block|inline(?:-flex)?)\b|\bcol-(?:\d+|(?:sm|md|lg|xl)-\d+)\b|\b(?:me|ms|pe|ps)-\d+\b|\bform-(?:control|select|check(?:-input|-label)?)\b|\bspinner-border\b|\bvisually-hidden\b)/';
$viewDirectories = [$root . '/app/Views', $root . '/storage/plugins'];
foreach ($viewDirectories as $viewDirectory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewDirectory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $viewFile) {
        if (!$viewFile->isFile() || strtolower($viewFile->getExtension()) !== 'php') {
            continue;
        }
        if (preg_match($bootstrapClassPattern, (string) file_get_contents($viewFile->getPathname()))) {
            $bootstrapClassReference = true;
            break 2;
        }
    }
}
$remoteFontAwesomeReference = false;
$publicFrontendViewsUseSharedLayout = true;
foreach (['home.php', 'catalog.php', 'book-detail.php', 'archive.php', 'events.php', 'event-detail.php', 'cms-page.php', 'contact.php', 'privacy-page.php', 'cookies-page.php'] as $publicViewName) {
    $publicViewContents = (string) file_get_contents($root . '/app/Views/frontend/' . $publicViewName);
    if (!preg_match('/(?:include|require)(?:_once)?\s+(?:__DIR__\s*\.\s*)?[\'\"]\/?layout\.php[\'\"]/', $publicViewContents)) {
        $publicFrontendViewsUseSharedLayout = false;
        break;
    }
}
$sourceDirectories = [
    $root . '/app/Views',
    $root . '/frontend/js',
    $root . '/installer',
    $root . '/storage/plugins',
];
foreach ($sourceDirectories as $sourceDirectory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $sourceFile) {
        if (!$sourceFile->isFile() || !in_array(strtolower($sourceFile->getExtension()), ['php', 'html', 'css', 'js'], true)) {
            continue;
        }
        $sourceContents = file_get_contents($sourceFile->getPathname());
        if (preg_match('#https?://[^\s"\']*(?:fontawesome|font-awesome)|(?:cdnjs|cdn\.jsdelivr|unpkg)[^\s"\']*(?:fontawesome|font-awesome)#i', $sourceContents)) {
            $remoteFontAwesomeReference = true;
            break 2;
        }
    }
}

$checks = [
    'editorial is the default layout' => ThemeManager::DEFAULT_LAYOUT_VARIANT === 'editorial',
    'four layout variants are exposed' => ThemeManager::LAYOUT_VARIANTS === ['editorial', 'workspace', 'command', 'soft'],
    'missing setting falls back to editorial' => $manager->getLayoutVariant(['settings' => '{}']) === 'editorial',
    'invalid stored setting falls back to editorial' => $manager->getLayoutVariant(['settings' => '{"layout_variant":"unknown"}']) === 'editorial',
    'valid stored setting is returned' => $manager->getLayoutVariant(['settings' => '{"layout_variant":"soft"}']) === 'soft',
    'public layout links the single shared stylesheet' => str_contains($layout, "assetUrl('/frontend-layouts.css')"),
    'layout stylesheet cache key follows the file modification time' => str_contains($layout, '$frontendLayoutsMtime') && str_contains($layout, '$frontendLayoutsVersion'),
    'public body receives the validated layout class' => str_contains($layout, 'layout-<?= htmlspecialchars($layoutVariant'),
    'standalone public views resolve the active theme from their database handle' => str_contains($layout, 'elseif (isset($db) && $db instanceof mysqli)') && str_contains($layout, 'new \\App\\Support\\ThemeManager($db)'),
    'account layout receives the validated layout class and shared stylesheets' => str_contains($userLayout, 'ThemeManager::DEFAULT_LAYOUT_VARIANT') && str_contains($userLayout, 'body class="layout-') && str_contains($userLayout, "assetUrl('frontend-layouts.css')") && str_contains($userLayout, "assetUrl('account-pages.css')"),
    'contact and plugin wrappers forward a theme-capable dependency' => str_contains($contactController, 'mixed $container = null') && str_contains($bookClubBase, '$db = $this->db') && str_contains($frbrPlugin, '$db = $this->db'),
    'normal profiles use the frontend shell while staff retain the admin shell' => str_contains($profileController, '$isAdminOrStaff') && str_contains($profileController, "Views/frontend/layout.php") && str_contains($profileController, "Views/layout.php"),
    'admin customize form exposes the shared layout radio group' => str_contains($admin, 'layout-variant-selector.php') && str_contains($adminLayoutSelector, 'name="layout_variant"'),
    'themes overview exposes the shared layout selector' => str_contains($adminThemes, 'layout-variant-selector.php') && str_contains($adminLayoutSelector, 'name="layout_variant"'),
    'themes overview has a dedicated protected layout route' => str_contains($routes, "post('/admin/themes/{id}/layout'") && str_contains($routes, 'saveLayout($request, $response, $args)'),
    'controller validates against the allow-list' => str_contains($controller, 'ThemeManager::LAYOUT_VARIANTS'),
    'full customization saves colors, layout and advanced CSS in one settings update' => str_contains($controller, 'updateThemeColors($themeId, $colors, $layoutVariant, $advanced)'),
    'stylesheet includes editorial rules' => str_contains($css, 'body.layout-editorial'),
    'stylesheet includes workspace rules' => str_contains($css, 'body.layout-workspace'),
    'stylesheet includes command rules' => str_contains($css, 'body.layout-command'),
    'stylesheet includes soft rules' => str_contains($css, 'body.layout-soft'),
    'event pages are covered by layout variants' => str_contains($css, '.event-card') && str_contains($css, '.event-hero'),
    'CMS pages are covered by layout variants' => str_contains($css, '.cms-content') && str_contains($css, '.cms-title'),
    'profile and dashboard pages are covered' => str_contains($css, '.profile-container') && str_contains($css, '.dashboard-hero'),
    'wishlist and reservations are covered' => str_contains($css, '.wishlist-card') && str_contains($css, '.loans-container'),
    'native archive, contact and legal pages are covered' => str_contains($css, '.archive-info-card') && str_contains($css, '.contact-page') && str_contains($css, '.cookie-page') && str_contains($css, '.privacy-page'),
    'every native public page view uses the shared themed layout' => $publicFrontendViewsUseSharedLayout,
    'author and publisher routes share the redesigned archive surface' => str_contains($archiveView, 'class="archive-page archive-page-') && str_contains($archiveView, '$archivePageStyles = true') && str_contains($layout, '$archivePagesVersion') && str_contains($archiveCss, '.archive-books-grid'),
    'publisher book cards link their canonical author without extra queries' => str_contains($archiveView, '$authorCanonicalName') && str_contains($archiveView, '$authorRoute . \'/\' . urlencode($authorCanonicalName)') && str_contains($frontendController, 'AS autore_principale_nome'),
    'name and id author routes expose the same public profile fields' => substr_count($frontendController, 'biografia, sito_web, foto, collegamenti FROM autori') >= 2,
    'archive cards keep responsive grids and reduced motion support' => str_contains($archiveCss, 'grid-template-columns: repeat(4, minmax(0, 1fr))') && str_contains($archiveCss, '@media (max-width: 44rem)') && str_contains($archiveCss, '@media (prefers-reduced-motion: reduce)'),
    'soft layout starts public content immediately below its floating header' => str_contains($css, "body.layout-soft main {\n  padding-top: 84px;") && str_contains($css, "body.layout-soft main {\n    padding-top: 80px;") && str_contains($archiveCss, 'margin: 0 1rem;'),
    'command layout joins header and content without a white strip' => str_contains($css, "body.layout-command main {\n  padding-top: 69px;"),
    'command catalog uses a compact left-aligned sans hierarchy' => str_contains($css, 'body.layout-command .catalog-header-content') && str_contains($css, 'min-height: 260px;') && str_contains($css, 'font-family: var(--sans, Inter, system-ui, sans-serif) !important;'),
    'command mobile header keeps search and burger controls visible' => str_contains($css, 'body.layout-command .mobile-menu-toggle') && str_contains($css, 'body.layout-command .mobile-search-toggle'),
    'workspace removes the header strip and gives hero copy deliberate breathing room' => str_contains($css, "body.layout-workspace main {\n  padding-top: 67px;") && str_contains($css, 'padding-block: 8.5rem 4.5rem;') && str_contains($css, 'padding-block: 4rem 3.5rem;') && str_contains($archiveCss, "body.layout-workspace .archive-hero {\n  background: var(--light-bg);\n  padding-block: 4rem 3.5rem;"),
    'native error pages are covered' => str_contains($css, '.error-404-content') && str_contains($css, '.error-500-content'),
    'archives plugin keeps the shared public layout surface' => str_contains($archiveIndex, 'archive-hero-index') && str_contains($css, '.archive-hero-index') && str_contains($css, '.archive-body'),
    'book club public views use shared layout and namespace' => str_contains($bookClubBase, 'frontend/layout.php') && str_contains($css, '.bc-card') && str_contains($css, '.bc-hero'),
    'FRBR public opera uses shared layout and namespace' => str_contains($frbrPlugin, 'frontend/layout.php') && str_contains($frbrOpera, 'frbr-opera-page') && str_contains($css, '.frbr-opera-page'),
    'header keeps reservations action' => str_contains($layout, 'absoluteUrl($reservationsRoute)'),
    'header keeps admin action' => str_contains($layout, "absoluteUrl('/admin/dashboard')"),
    'cookie banner remains in the shared layout' => str_contains($layout, "require __DIR__ . '/../partials/cookie-banner.php'"),
    'book loan and favorite actions remain' => str_contains($bookDetail, 'id="btn-request-loan"') && str_contains($bookDetail, 'id="btn-fav"'),
    'digital book action and player hooks remain' => str_contains($bookDetail, "do_action('book.detail.digital_buttons', \$book)") && str_contains($bookDetail, "do_action('book.detail.digital_player', \$book)"),
    'GoodLib external actions use the shared semantic button contract' => str_contains($goodLibBadges, 'plugin-source-search') && str_contains($goodLibBadges, 'plugin-source-link') && str_contains($goodLibBadges, 'ui-button btn-outline') && !str_contains($goodLibBadges, 'style='),
    'digital library actions keep handlers while using the shared contract' => str_contains($digitalLibraryButtons, 'id="btn-toggle-pdf-viewer"') && str_contains($digitalLibraryButtons, 'id="btn-toggle-audiobook"') && substr_count($digitalLibraryButtons, 'plugin-book-action') >= 4 && !str_contains($digitalLibraryButtons, '<style>'),
    'digital players use themeable semantic actions' => substr_count($digitalLibraryViewer . $digitalLibraryPlayer, 'plugin-player-action') >= 4,
    // Editorial no longer de-emphasises plugin actions into an underline: they are
    // harmonised with the native buttons via the base `body[class*="layout-"]` rule
    // (+ btn-outline-primary). Workspace/command/soft keep their per-layout radii,
    // and the actions now drop onto their own row via `.plugin-book-actions--frontend`.
    'plugin actions adapt to all four public layouts' => str_contains($css, 'body[class*="layout-"] #book-action-buttons .plugin-book-action') && str_contains($css, 'body.layout-workspace #book-action-buttons .plugin-book-action') && str_contains($css, 'body.layout-command #book-action-buttons .plugin-book-action') && str_contains($css, 'body.layout-soft #book-action-buttons .plugin-book-action') && str_contains($css, '.plugin-book-actions--frontend') && str_contains($css, '.plugin-source-search--frontend'),
    'all public plugin button families share one accessible action language' => str_contains($css, ':is(.bc-btn, .archive-page .ui-button, .archive-body .ui-button, .archive-search-form .ui-button, .frbr-opera-page .ui-button)') && str_contains($css, 'min-height: 44px;') && str_contains($css, '.bc-btn-danger') && str_contains($css, '[aria-disabled="true"]'),
    'digital library no longer overrides book actions with hardcoded colors' => str_contains($digitalLibraryCss, '.action-buttons .plugin-book-action') && !str_contains($digitalLibraryCss, '.action-buttons .btn-danger-outline') && !str_contains($digitalLibraryCss, '.action-buttons .btn-outline'),
    'related books always render an author label' => str_contains($bookDetail, '$relatedAuthorDisplay') && str_contains($bookDetail, 'Autore sconosciuto'),
    'catalog does not duplicate the active-theme query' => !str_contains($catalog, 'getActiveTheme()'),
    'catalog filter sidebar widens responsively on laptops' => str_contains($catalog, 'catalog-filters-column w-full lg:w-1/3') && str_contains($catalog, 'catalog-results-column w-full lg:w-2/3'),
    'catalog filter controls retain touch-safe spacing' => str_contains($catalog, 'min-height: 44px;') && str_contains($catalog, 'padding: 0.7rem 0.75rem;'),
    'catalog filters collapse behind an accessible mobile control' => str_contains($catalog, 'id="catalog-filters-toggle"') && str_contains($catalog, 'aria-controls="catalog-filters-content"') && str_contains($catalog, 'mobileFilters.matches'),
    'catalog pagination emits a syntactically complete active class' => str_contains($catalog, "' + activeClass + '\"><a class=\"page-link\""),
    'related-book fallback uses one ranked query' => str_contains($frontendController, 'Priorities 1-3 in one ranked query') && str_contains($frontendController, 'ORDER BY {$priorityOrder}'),
    'all homepage variants share a centered hero' => str_contains($css, 'body[class*="layout-"].home .hero-content') && str_contains($css, 'text-align: center;') && str_contains($css, 'justify-content: center;'),
    'homepage async states contain no bootstrap compatibility markup' => !preg_match('/spinner-border|visually-hidden|\\bcol-12\\b|alert-danger/', $home),
    'homepage hero starts beneath the fixed header without a spacer' => str_contains($css, 'body[class*="layout-"].home main') && str_contains($css, 'padding-top: 0 !important;'),
    'seeded hero action text and link are rendered' => str_contains($homeHero, "\$heroData['button_text']") && str_contains($homeHero, "\$heroData['button_link']") && str_contains($homeHero, '$heroButtonLink'),
    'latest-books hero link reuses seeded section title' => str_contains($homeHero, "\$homeContent['latest_books_title']['title']"),
    'mobile home stats use an aligned two-column grid' => str_contains($home, 'grid-template-columns: repeat(2, minmax(0, 1fr))') && str_contains($home, '.hero-stat:nth-child(even)'),
    'mobile feature icons share one heading row with their title' => str_contains($homeFeatures, 'class="feature-heading"') && str_contains($home, '.feature-heading .feature-icon') && str_contains($home, '.feature-heading .feature-title'),
    'empty publisher metadata collapses only on mobile' => str_contains($homeBooksGrid, 'book-meta book-meta-empty') && str_contains($catalogGrid, 'book-meta book-meta-empty') && str_contains($css, '.book-meta-empty') && str_contains($css, 'display: none !important;'),
    'catalog detail actions have a visible themed border' => str_contains($css, '.book-actions .btn-cta') && str_contains($css, 'border: 1px solid color-mix'),
    'catalog covers preserve the full artwork with only a minimal hover crop' => str_contains($catalogGrid, 'aspect-ratio: 2/3;') && str_contains($catalogGrid, 'object-fit: contain;') && str_contains($catalogGrid, 'scale(1.012)') && str_contains($homeBooksGrid, 'scale(1.012)'),
    'admin books media icon keeps a syntactically complete class concatenation' => str_contains($adminBooks, "(icons[data] || 'fa-book') + ' text-gray-400\"") && !str_contains($adminBooks, "(icons[data] || 'fa-book') text-gray-400\""),
    'admin shell loads one cache-busted shared action stylesheet' => str_contains($adminLayout, "assetUrl('admin-ui.css')") && str_contains($adminLayout, 'adminUiVersion') && str_contains($adminLayout, 'class="admin-shell '),
    'backend action groups wrap with visible primary and secondary controls' => str_contains($adminUiCss, '--admin-action-border: #cbd0d8') && str_contains($adminUiCss, ":has(\n  > :is(a, button") && str_contains($adminUiCss, "[class~='bg-gray-900']"),
    'datatable icon actions have a visible 34px surface' => str_contains($adminUiCss, 'width: 34px !important;') && str_contains($adminUiCss, 'border: 1px solid var(--admin-action-border);'),
    'settings tabs form an accessible responsive navigation system' => str_contains($adminSettings, 'class="settings-tabs" role="tablist"') && str_contains($adminSettings, "setAttribute('aria-selected'") && str_contains($adminSettings, "'ArrowLeft', 'ArrowRight', 'Home', 'End'") && str_contains($adminUiCss, 'scroll-snap-type: x proximity;'),
    'mobile book kicker and action groups are centered or stacked' => str_contains($css, '.book-kicker {') && str_contains($css, 'justify-content: center;') && str_contains($css, ':is(#book-action-buttons, .event-card__actions, .archive-actions)'),
    'mobile share card uses symmetric vertical spacing' => str_contains($css, '#book-share-card .card-header') && str_contains($css, 'padding-block: 1rem;'),
    'account pages share one cache-busted stylesheet' => str_contains($dashboardReservations, "assetUrl('account-pages.css')") && str_contains($profileReservations, "assetUrl('account-pages.css')") && str_contains($wishlist, "assetUrl('account-pages.css')") && str_contains($dashboardReservations, 'filemtime'),
    'account stylesheet adapts to all four layouts' => str_contains($accountCss, 'body.layout-editorial') && str_contains($accountCss, 'body.layout-workspace') && str_contains($accountCss, 'body.layout-command') && str_contains($accountCss, 'body.layout-soft'),
    'reservation headings use dependency-free line icons' => str_contains($dashboardReservations, 'function accountLineIcon') && str_contains($dashboardReservations, 'account-line-icon') && str_contains($accountCss, 'stroke: currentColor'),
    'active loans empty state does not repeat its section icon' => !preg_match('/empty\(\$activePrestiti\).*?empty-state-icon.*?Nessun prestito attivo/s', $dashboardReservations),
    'demo catalog seed rebuilds the denormalized search index in one batch' => str_contains($demoCatalogSeed, 'SearchIndexBuilder::rebuildMany($db, $seededIds)'),
    'autocomplete cancels stale requests and caches recent results' => str_contains($layout, 'new AbortController()') && str_contains($layout, 'const searchCache = new Map()') && str_contains($layout, 'SEARCH_CACHE_LIMIT'),
    'mobile autocomplete is CSS-sized without fixed inline widths' => str_contains($layout, 'class="search-form mobile-search-form"') && str_contains($layout, '.search-results {') && !str_contains($layout, "'min-width: 500px;'"),
    'font awesome is bundled from the local npm package' => str_contains($vendorSource, "import '@fortawesome/fontawesome-free/css/all.min.css';"),
    'no frontend source loads font awesome from a CDN' => !$remoteFontAwesomeReference,
    'bootstrap is absent from frontend dependencies' => !isset($frontendPackage['dependencies']['bootstrap']) && !isset($frontendPackage['devDependencies']['bootstrap']),
    'vendor source imports no bootstrap assets' => !str_contains(strtolower($vendorSource), 'bootstrap'),
    'compiled vendor stylesheet contains no bootstrap variables' => !str_contains($vendorCss, '--bs-') && !str_contains($vendorCss, 'Bootstrap v'),
    'views contain no bootstrap-only layout classes' => !$bootstrapClassReference,
    'container centering and page padding come from the tailwind source' => str_contains($tailwindSource, 'margin-left: auto;') && str_contains($tailwindSource, 'margin-right: auto;'),
    'book hero uses a real two-column grid' => str_contains($bookDetail, 'grid-template-columns: minmax(0, 1fr) minmax(0, 2fr)') && str_contains($bookDetail, 'class="book-info-column"'),
    'book identity is ordered and shared by every layout' => str_contains($bookDetail, 'class="book-breadcrumb"') && str_contains($bookDetail, 'class="book-kicker"') && str_contains($css, 'body[class*="layout-"] .book-breadcrumb') && str_contains($css, "content: '›'"),
    'book subtitle has no oversized inline typography' => str_contains($bookDetail, 'class="book-subtitle-hero mb-3"') && !str_contains($bookDetail, 'id="book-subtitle" style='),
    'book status is a compact semantic text-and-dot component' => str_contains($bookDetail, 'book-status-inline') && str_contains($css, '.book-status-inline::before'),
    'workspace book metadata avoids boxed grid separators' => str_contains($css, 'body.layout-workspace .details-grid') && str_contains($css, 'gap: clamp(2rem, 5vw, 4rem);') && str_contains($css, 'body.layout-workspace #book-info-card'),
    'core tailwind buttons use solid colors without gradients' => !str_contains($tailwindSource, 'linear-gradient'),
    'versioned design system guide is a valid project artifact' => str_contains($designGuide, '## Overview') && str_contains($designGuide, "## Do's and Don'ts"),
    'loan date calendars stay inside the scrollable modal flow' => str_contains($bookDetail, 'static: true') && str_contains($bookDetail, "popup: 'loan-request-popup'") && str_contains($bookDetail, 'heightAuto: false') && str_contains($swalThemeCss, '.loan-request-popup .flatpickr-calendar.static') && str_contains($swalThemeCss, 'position: relative !important;'),
    'loan modal locks the document and contains its own overflow' => str_contains($swalThemeCss, 'html.swal2-shown') && str_contains($swalThemeCss, 'overflow-y: auto !important;') && str_contains($swalThemeCss, 'overscroll-behavior: contain;'),
    'loan modal stylesheets are cache-busted from their file timestamps' => str_contains($layout, '$flatpickrCustomMtime') && str_contains($layout, '$swalThemeMtime') && str_contains($layout, '$swalThemeVersion'),
    'design system documents the date selection modal' => str_contains($designGuide, '### Date Selection Modal'),
    'header keeps compact search beside account actions' => str_contains($layout, 'class="mobile-search-toggle md:hidden"') && str_contains($layout, 'class="search-form hidden md:block"') && str_contains($layout, 'justify-content: flex-start;'),
    'dismissible alerts use framework-independent javascript' => str_contains($mainSource, 'data-dismiss-alert') && !str_contains($bookDetail, 'data-bs-dismiss'),
    'main stylesheet is generated by tailwind' => str_contains($mainCss, '.md\\:w-1\\/3') && str_contains($mainCss, '.container'),
    'account pages retain reduced-motion support' => str_contains($accountCss, '@media (prefers-reduced-motion: reduce)'),
    'reduced motion is respected' => str_contains($css, '@media (prefers-reduced-motion: reduce)'),
];

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed += $ok ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);

<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\ThemeManager;

$manager = (new ReflectionClass(ThemeManager::class))->newInstanceWithoutConstructor();
$layout = file_get_contents($root . '/app/Views/frontend/layout.php');
$admin = file_get_contents($root . '/app/Views/admin/theme-customize.php');
$adminThemes = file_get_contents($root . '/app/Views/admin/themes.php');
$adminLayoutSelector = file_get_contents($root . '/app/Views/admin/partials/public-style-selector.php');
$routes = file_get_contents($root . '/app/Routes/web.php');
$controller = file_get_contents($root . '/app/Controllers/ThemeController.php');
$frontendController = file_get_contents($root . '/app/Controllers/FrontendController.php');
$contactController = file_get_contents($root . '/app/Controllers/ContactController.php');
$profileController = file_get_contents($root . '/app/Controllers/ProfileController.php');
$catalog = file_get_contents($root . '/app/Views/frontend/catalog.php');
$bookDetail = file_get_contents($root . '/app/Views/frontend/book-detail.php');
// The 2026 design system: the only live public/account stylesheet for the layout chrome.
// (frontend-layouts.css, account-pages.css and user_layout.php were removed as dead code:
// their selectors needed body.layout-* classes the 2026 <body class="pk …"> never emits.)
$pk2026 = file_get_contents($root . '/public/assets/pinakes-2026.css');
$pkBookCard = file_get_contents($root . '/app/Views/frontend/partials/pk-book-card.php');
$archiveIndex = file_get_contents($root . '/storage/plugins/archives/views/public/index.php');
$bookClubBase = file_get_contents($root . '/storage/plugins/book-club/src/BaseController.php');
$bookClubIndex = file_get_contents($root . '/storage/plugins/book-club/views/public/index.php');
$bookClubShow = file_get_contents($root . '/storage/plugins/book-club/views/public/show.php');
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
$dashboardReservations = file_get_contents($root . '/app/Views/user_dashboard/prenotazioni.php');
$demoCatalogSeed = file_get_contents($root . '/scripts/seed-demo-catalog.php');
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
$catalogCss = file_get_contents($root . '/public/assets/catalog-pages.css');
$bookDetailCss = file_get_contents($root . '/public/assets/book-detail.css');
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
$deadStylesheetReference = false;
foreach ([$root . '/app', $root . '/storage/plugins'] as $linkDirectory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($linkDirectory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $linkFile) {
        if (!$linkFile->isFile() || strtolower($linkFile->getExtension()) !== 'php') {
            continue;
        }
        if (preg_match('/account-pages\.css|frontend-layouts\.css/', (string) file_get_contents($linkFile->getPathname()))) {
            $deadStylesheetReference = true;
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
    'the public style defaults to the cover hero and classic cards' => ThemeManager::DEFAULT_HERO_STYLE === 'covers' && ThemeManager::DEFAULT_CARD_STYLE === 'classic',
    'two hero and two card styles are exposed' => ThemeManager::HERO_STYLES === ['covers', 'centered'] && ThemeManager::CARD_STYLES === ['classic', 'tinted'],
    'a theme without the setting (install, upgrade) gets the defaults' => $manager->getPublicStyle(['settings' => '{"layout_variant":"soft"}']) === ['hero_style' => 'covers', 'card_style' => 'classic'],
    'invalid stored values fall back to the defaults' => $manager->getPublicStyle(['settings' => '{"hero_style":"x","card_style":"<b>"}']) === ['hero_style' => 'covers', 'card_style' => 'classic'],
    'valid stored values are returned' => $manager->getPublicStyle(['settings' => '{"hero_style":"centered","card_style":"tinted"}']) === ['hero_style' => 'centered', 'card_style' => 'tinted'],
    'the defaults add no body class; the alternatives add theirs' => ThemeManager::publicStyleClasses(['hero_style' => 'covers', 'card_style' => 'classic']) === '' && ThemeManager::publicStyleClasses(['hero_style' => 'centered', 'card_style' => 'tinted']) === 'pk-hero-centered pk-cards-tinted',
    'no installer seed pins a public style' => !preg_match('/hero_style|card_style|layout_variant/', implode('', array_map('file_get_contents', glob($root . '/installer/database/data_*.sql') ?: []))),
    'the stylesheet styles both alternatives' => str_contains($pk2026, 'body.pk.pk-hero-centered .pk-fan { display: none; }') && str_contains($pk2026, 'body.pk-cards-tinted :is(.pk-card__panel'),
    'public layout links the shared design stylesheet' => str_contains($layout, '/assets/pinakes-2026.css') && str_contains($layout, '$pinakes2026Version'),
    'design stylesheet cache key follows the file modification time' => str_contains($layout, '$pinakes2026Mtime') && str_contains($layout, "filemtime(dirname(__DIR__, 3) . '/public/assets/pinakes-2026.css')") && str_contains($layout, '$pinakes2026Version'),
    'public body receives the validated style classes' => str_contains($layout, 'ThemeManager::publicStyleClasses($publicStyle)') && str_contains($layout, '$publicStyle = $themeManager->getPublicStyle($activeTheme);'),
    'standalone public views resolve the active theme from their database handle' => str_contains($layout, 'elseif (isset($db) && $db instanceof mysqli)') && str_contains($layout, 'new \\App\\Support\\ThemeManager($db)'),
    'the public body is the 2026 design root carrying the style classes' => str_contains($layout, '<body class="pk<?= $isHome ? \' home\' : \'\' ?><?= $pkStyleClasses !== \'\''),
    'dead layout files are gone' => !file_exists($root . '/app/Views/user_layout.php') && !file_exists($root . '/public/assets/frontend-layouts.css') && !file_exists($root . '/public/assets/account-pages.css'),
    'no view or plugin links the removed account/layout stylesheets' => !$deadStylesheetReference,
    'contact and plugin wrappers forward a theme-capable dependency' => str_contains($contactController, 'mixed $container = null') && str_contains($bookClubBase, '$db = $this->db') && str_contains($frbrPlugin, '$db = $this->db'),
    'normal profiles use the frontend shell while staff retain the admin shell' => str_contains($profileController, '$isAdminOrStaff') && str_contains($profileController, "Views/frontend/layout.php") && str_contains($profileController, "Views/layout.php"),
    'admin customize form exposes the shared style radio groups' => str_contains($admin, 'public-style-selector.php') && str_contains($adminLayoutSelector, "'hero_style' =>") && str_contains($adminLayoutSelector, "'card_style' =>") && str_contains($adminLayoutSelector, 'name="<?= htmlspecialchars($field'),
    'themes overview exposes the shared style selector' => str_contains($adminThemes, 'public-style-selector.php'),
    'themes overview has a dedicated protected layout route' => str_contains($routes, "post('/admin/themes/{id}/layout'") && str_contains($routes, 'saveLayout($request, $response, $args)'),
    'controller validates against the allow-list' => str_contains($controller, 'ThemeManager::isValidPublicStyle($style)'),
    'full customization saves colors, style and advanced CSS in one settings update' => str_contains($controller, 'updateThemeColors($themeId, $colors, $publicStyle, $advanced)'),
    'home event cards are covered by the design' => str_contains($pk2026, 'body.pk .home-events-grid .event-card {'),
    'dashboard and wishlist heroes are covered by the design' => str_contains($pk2026, 'body.pk .dashboard-hero {'),
    'wishlist and reservations are covered by the design' => str_contains($pk2026, 'body.pk .pk-wishlist .wishlist-card {') && str_contains($pk2026, 'body.pk main .loans-container {'),
    'native contact, about and legal pages share one static surface' => (static function () use ($root): bool {
        foreach (['cms-page.php', 'contact.php', 'privacy-page.php', 'cookies-page.php'] as $staticView) {
            $staticSrc = (string) file_get_contents($root . '/app/Views/frontend/' . $staticView);
            if (!str_contains($staticSrc, 'class="static-page"') || !str_contains($staticSrc, "partials/catalog-hero.php")) {
                return false;
            }
        }
        return true;
    })(),
    'every native public page view uses the shared themed layout' => $publicFrontendViewsUseSharedLayout,
    'author and publisher routes share the redesigned archive surface' => str_contains($archiveView, 'class="archive-page archive-page-') && str_contains($archiveView, '$archivePageStyles = true') && str_contains($layout, '$archivePagesVersion') && str_contains($archiveCss, '.archive-books-grid'),
    'publisher book cards link their canonical author without extra queries' => str_contains($archiveView, '$authorCanonicalName') && str_contains($archiveView, '$authorRoute . \'/\' . urlencode($authorCanonicalName)') && str_contains($frontendController, 'AS autore_principale_nome'),
    'name and id author routes expose the same public profile fields' => substr_count($frontendController, 'biografia, sito_web, foto, collegamenti FROM autori') >= 2,
    'archive cards keep responsive grids and reduced motion support' => str_contains($archiveCss, 'grid-template-columns: repeat(4, minmax(0, 1fr))') && str_contains($archiveCss, '@media (max-width: 44rem)') && str_contains($archiveCss, '@media (prefers-reduced-motion: reduce)'),
    'native error pages carry their own styles inside the shared layout' => (static function () use ($root): bool {
        foreach (['404', '500'] as $code) {
            $errorSrc = (string) file_get_contents($root . '/app/Views/errors/' . $code . '.php');
            if (!str_contains($errorSrc, '.error-' . $code . '-content') || !str_contains($errorSrc, "require __DIR__ . '/../frontend/layout.php'")) {
                return false;
            }
        }
        return true;
    })(),
    'archives plugin keeps the shared public layout surface' => str_contains($archiveIndex, "/catalog-hero.php'") && str_contains($archiveIndex, "/filters-sidebar.php'") && str_contains($archiveIndex, "/pagination.php'"),
    'book club public views use shared layout and namespace' => str_contains($bookClubBase, 'frontend/layout.php') && str_contains($bookClubIndex, '.bc-card{') && str_contains($bookClubShow, '.bc-hero-meta{'),
    'FRBR public opera uses shared layout and namespace' => str_contains($frbrPlugin, 'frontend/layout.php') && str_contains($frbrOpera, 'frbr-opera-page'),
    'header keeps reservations action' => str_contains($layout, 'absoluteUrl($reservationsRoute)'),
    'header keeps admin action' => str_contains($layout, "absoluteUrl('/admin/dashboard')"),
    'cookie banner remains in the shared layout' => str_contains($layout, "require __DIR__ . '/../partials/cookie-banner.php'"),
    'book loan and favorite actions remain' => str_contains($bookDetail, 'id="btn-request-loan"') && str_contains($bookDetail, 'id="btn-fav"'),
    'digital book action and player hooks remain' => str_contains($bookDetail, "do_action('book.detail.digital_buttons', \$book)") && str_contains($bookDetail, "do_action('book.detail.digital_player', \$book)"),
    'GoodLib external actions use the shared semantic button contract' => str_contains($goodLibBadges, 'plugin-source-search') && str_contains($goodLibBadges, 'plugin-source-link') && str_contains($goodLibBadges, 'ui-button btn-outline') && !str_contains($goodLibBadges, 'style='),
    'digital library actions keep handlers while using the shared contract' => str_contains($digitalLibraryButtons, 'id="btn-toggle-pdf-viewer"') && str_contains($digitalLibraryButtons, 'id="btn-toggle-audiobook"') && substr_count($digitalLibraryButtons, 'plugin-book-action') >= 4 && !str_contains($digitalLibraryButtons, '<style>'),
    'digital players use themeable semantic actions' => substr_count($digitalLibraryViewer . $digitalLibraryPlayer, 'plugin-player-action') >= 4,
    // Plugin actions (digital library buttons, GoodLib "Cerca su" links) are styled
    // by the 2026 design inside the book page's digital panel (.pk-digital).
    'plugin actions are styled by the design' => str_contains($pk2026, 'body.pk .pk-digital .plugin-book-action.ui-button {') && str_contains($pk2026, 'body.pk .pk-digital .plugin-source-search {'),
    'book club buttons keep a touch-safe target' => (bool) preg_match('/\.bc-btn\{[^}]*min-height:44px/', $bookClubIndex),
    'digital library no longer overrides book actions with hardcoded colors' => str_contains($digitalLibraryCss, '.action-buttons .plugin-book-action') && !str_contains($digitalLibraryCss, '.action-buttons .btn-danger-outline') && !str_contains($digitalLibraryCss, '.action-buttons .btn-outline'),
    'related books always render an author label' => str_contains($bookDetail, "partials/pk-book-card.php") && str_contains($pkBookCard, "__('Autore sconosciuto')"),
    'catalog does not duplicate the active-theme query' => !str_contains($catalog, 'getActiveTheme()'),
    'catalog filter sidebar has a fixed column beside fluid results' => str_contains($catalog, 'catalog-filters-column pk-filters') && str_contains($catalog, 'catalog-results-column pk-results') && str_contains($pk2026, '.pk-catalog__layout {'),
    'catalog filter controls retain touch-safe spacing' => str_contains($catalogCss, 'min-height: 44px;') && str_contains($catalogCss, 'padding: 0.7rem 0.75rem;'),
    'book detail surface is a shared stylesheet linked by the layout' => str_contains($bookDetail, '$bookDetailStyles = true') && str_contains($layout, '$bookDetailVersion') && !str_contains($bookDetailCss, '<?') && !str_contains($pk2026, '<?'),
    // The hero band takes the cover's own tone (pinakes-2026.js sets --pk-tone on the
    // [data-pk-tone-target] ancestor of the img[data-pk-tone]); the theme accent is the fallback.
    'book hero band takes the cover tone, theme accent as fallback' => str_contains($bookDetail, '<section class="book-hero pk-bookhero" data-pk-tone-target>') && str_contains($bookDetail, 'data-pk-tone') && str_contains($pk2026, 'background: linear-gradient(180deg, var(--pk-tone, color-mix(in srgb, var(--pk-accent) 6%, #f7f1f3)) 0%, var(--pk-bg) 520px);'),
    'catalog surface is a shared stylesheet linked by the layout' => str_contains($catalog, '$catalogPageStyles = true') && !str_contains($catalog, '<style>') && str_contains($layout, '$catalogPagesVersion') && str_contains($catalogCss, '.books-grid') && !str_contains($catalogCss, ':root {'),
    'catalog filters collapse behind an accessible mobile control' => str_contains($catalog, 'id="catalog-filters-toggle"') && str_contains($catalog, 'aria-controls="catalog-filters-content"') && str_contains($catalog, 'mobileFilters.matches'),
    'catalog pagination emits a syntactically complete active class' => str_contains($catalog, "' + activeClass + '\"><a class=\"page-link\""),
    'related-book fallback uses one ranked query' => str_contains($frontendController, 'Priorities 1-3 in one ranked query') && str_contains($frontendController, 'ORDER BY {$priorityOrder}'),
    'homepage async states contain no bootstrap compatibility markup' => !preg_match('/spinner-border|visually-hidden|\\bcol-12\\b|alert-danger/', $home),
    'header sits in the page flow so the hero needs no spacer' => str_contains($pk2026, 'body.pk main { padding-top: 0; }') && (bool) preg_match('/body\.pk \.header-container \{\s*position: sticky; top: 0;/', $pk2026),
    'seeded hero action text and link are rendered' => str_contains($homeHero, "\$heroData['button_text']") && str_contains($homeHero, "\$heroData['button_link']") && str_contains($homeHero, '$heroButtonLink'),
    'latest-books hero link reuses seeded section title' => str_contains($homeHero, "\$homeContent['latest_books_title']['title']"),
    'mobile home stats use an aligned two-column grid' => str_contains($pk2026, '@media (max-width: 520px) { .pk-stats__inner { grid-template-columns: repeat(2, 1fr);'),
    'feature icons share one heading row with their number' => str_contains($homeFeatures, 'class="feature-heading pk-feature__top"') && str_contains($homeFeatures, 'class="feature-icon pk-feature__icon"') && str_contains($pk2026, '.pk-feature__top'),
    'empty publisher metadata collapses only on mobile' => str_contains($pkBookCard, 'book-meta book-meta-empty') && str_contains($homeBooksGrid, 'partials/pk-book-card.php') && str_contains($catalogGrid, 'partials/pk-book-card.php') && str_contains($pk2026, '@media (max-width: 520px) { body.pk .book-meta-empty { display: none !important; } }'),
    'catalog covers preserve the full artwork with only a minimal hover crop' => str_contains($catalogCss, 'aspect-ratio: 2/3;') && str_contains($catalogCss, 'object-fit: contain;') && str_contains($catalogCss, 'scale(1.012)') && !str_contains($catalogGrid, '<style>') && str_contains($home, '$catalogPageStyles = true') && !str_contains($homeBooksGrid, '<style>'),
    'admin books media icon keeps a syntactically complete class concatenation' => str_contains($adminBooks, "(icons[data] || 'fa-book') + ' text-gray-400\"") && !str_contains($adminBooks, "(icons[data] || 'fa-book') text-gray-400\""),
    'admin shell loads one cache-busted shared action stylesheet' => str_contains($adminLayout, "assetUrl('admin-ui.css')") && str_contains($adminLayout, 'adminUiVersion') && str_contains($adminLayout, 'class="admin-shell '),
    'backend action groups wrap with visible primary and secondary controls' => str_contains($adminUiCss, '--admin-action-border: #cbd0d8') && str_contains($adminUiCss, ":has(\n  > :is(a, button") && str_contains($adminUiCss, "[class~='bg-gray-900']"),
    'datatable icon actions have a visible 34px surface' => str_contains($adminUiCss, 'width: 34px !important;') && str_contains($adminUiCss, 'border: 1px solid var(--admin-action-border);'),
    'settings tabs form an accessible responsive navigation system' => str_contains($adminSettings, 'class="settings-tabs" role="tablist"') && str_contains($adminSettings, "setAttribute('aria-selected'") && str_contains($adminSettings, "'ArrowLeft', 'ArrowRight', 'Home', 'End'") && str_contains($adminUiCss, 'scroll-snap-type: x proximity;'),
    'reservation status renders as canonical text badges' => str_contains($dashboardReservations, 'translate_loan_status(') && str_contains($dashboardReservations, 'status-badge') && str_contains($pk2026, 'body.pk .loans-container .status-badge {'),
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
    'book hero puts the cover beside the identity' => str_contains($pk2026, 'body.pk .pk-bookhero__grid { display: flex;') && str_contains($bookDetail, 'class="book-info-column pk-bookhero__info"'),
    'book identity is ordered and shared by every layout' => str_contains($bookDetail, 'class="book-breadcrumb"') && str_contains($bookDetail, 'class="book-kicker pk-kicker"') && strpos($bookDetail, 'class="book-kicker pk-kicker"') < strpos($bookDetail, 'id="book-title"'),
    'book subtitle has no oversized inline typography' => str_contains($bookDetail, 'class="book-subtitle-hero pk-bookhero__subtitle"') && !str_contains($bookDetail, 'id="book-subtitle" style='),
    'book status is a compact semantic text component' => str_contains($bookDetail, 'class="book-status-inline') && str_contains($pk2026, '.book-status-inline {'),
    'core tailwind buttons use solid colors without gradients' => !str_contains($tailwindSource, 'linear-gradient'),
    'loan date calendars stay inside the scrollable modal flow' => str_contains($bookDetail, 'static: true') && str_contains($bookDetail, "popup: 'loan-request-popup'") && str_contains($bookDetail, 'heightAuto: false') && str_contains($swalThemeCss, '.loan-request-popup .flatpickr-calendar.static') && str_contains($swalThemeCss, 'position: relative !important;'),
    'loan modal locks the document and contains its own overflow' => str_contains($swalThemeCss, 'html.swal2-shown') && str_contains($swalThemeCss, 'overflow-y: auto !important;') && str_contains($swalThemeCss, 'overscroll-behavior: contain;'),
    'loan modal stylesheets are cache-busted from their file timestamps' => str_contains($layout, '$flatpickrCustomMtime') && str_contains($layout, '$swalThemeMtime') && str_contains($layout, '$swalThemeVersion'),
    'header keeps compact search beside account actions' => str_contains($layout, 'class="mobile-search-toggle md:hidden"') && str_contains($layout, 'class="search-form hidden md:block"') && str_contains($layout, 'justify-content: flex-start;'),
    'dismissible alerts use framework-independent javascript' => str_contains($mainSource, 'data-dismiss-alert') && !str_contains($bookDetail, 'data-bs-dismiss'),
    'main stylesheet is generated by tailwind' => str_contains($mainCss, '.md\\:w-1\\/3') && str_contains($mainCss, '.container'),
    'reduced motion is respected by the design' => str_contains($pk2026, '@media (prefers-reduced-motion: reduce)'),
];

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed += $ok ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);

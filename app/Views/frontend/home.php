<?php
use App\Support\HtmlHelper;

$title = __("Biblioteca Digitale - La tua biblioteca online");
$catalogRoute = route_path('catalog');
$apiCatalogRoute = route_path('api_catalog');
$apiCatalogRouteJs = json_encode($apiCatalogRoute, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
$registerRoute = route_path('register');
$homeEvents = $homeEvents ?? [];
$homeEventsEnabled = $homeEventsEnabled ?? false;
// The latest-books grid (home-books-grid.php) renders the catalogue card
// markup with no CSS of its own: link the catalogue stylesheet for it.
$catalogPageStyles = true;

// Preload the first cover of the hero fan: it is the largest image above the
// fold, so the LCP element of the home page. Only when the hero is active.
$heroCovers = $heroCovers ?? [];
if (isset($homeContent['hero']) && $heroCovers !== [] && !empty($heroCovers[0]['copertina_url'])) {
    $headLinks = $headLinks ?? [];
    $headLinks[] = [
        'rel' => 'preload',
        'as' => 'image',
        'href' => absoluteUrl((string) $heroCovers[0]['copertina_url']),
        'fetchpriority' => 'high',
    ];
}

// SEO Variables are now passed from FrontendController::home()
// No need to override them here - the controller handles all SEO logic with proper fallbacks
$additional_css = "
    /* Hero, counters, sections, features and the call to action are styled
       by public/assets/pinakes-2026.css (2026 design). */
    .loading-placeholder {
        text-align: center;
        padding: 4rem 2rem;
        color: var(--text-muted);
    }

    .loading-placeholder .loading-ring {
        width: 3rem;
        height: 3rem;
        border: 3px solid color-mix(in srgb, var(--primary-color) 20%, transparent);
        border-top-color: var(--primary-color);
        border-radius: 9999px;
        margin-inline: auto;
        animation: loadingRingSpin 0.75s linear infinite;
    }

    @keyframes loadingRingSpin { to { transform: rotate(360deg); } }

        h6.search-section-title {
    text-align: left;
}

    section#genre-carousels {
        padding-bottom: 0;
    }

    /* Genre Carousel Styles */
    .genre-carousel-section {
        padding: 4rem 0;
        background: var(--white);
    }

    .genre-carousel-section:nth-child(even) {
        background: var(--light-bg);
    }

    .genre-carousel-header {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.75rem 1.5rem;
        /* Same inset as the books: navigation button (48px) + grid gap. */
        margin: 0 calc(48px + 1rem) 2.5rem;
        text-align: left;
    }

    .genre-carousel-title {
        font-size: 2rem;
        font-weight: 800;
        color: var(--text-color);
        margin: 0;
        letter-spacing: -0.02em;
        text-align: left;
    }

    .genre-carousel-viewall {
        display: flex;
        width: fit-content;
        margin-inline: 0;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.95rem;
        font-weight: 600;
        color: var(--primary-color);
        text-decoration: none;
        transition: opacity 0.2s ease;
    }

    .genre-carousel-viewall:hover {
        opacity: 0.7;
    }

    .genre-carousel-viewall:focus-visible {
        outline: 2px solid var(--primary-color);
        outline-offset: 2px;
        opacity: 0.85;
    }

    .carousel-container {
        display: grid;
        grid-template-columns: auto 1fr auto;
        align-items: center;
        gap: 1rem;
        width: 100%;
    }

    .carousel-wrapper {
        overflow: hidden;
        width: 100%;
        grid-column: 2;
    }

    .carousel-nav-btn {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        border: none;
        background: #c0c0c0;
        color: white;
        font-size: 1.25rem;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0.8;
    }

    .carousel-nav-btn[data-direction=\"prev\"] {
        justify-self: end;
    }

    .carousel-nav-btn[data-direction=\"next\"] {
        justify-self: start;
    }

    .carousel-nav-btn:hover:not(:disabled) {
        background: #a0a0a0;
        opacity: 1;
        transform: scale(1.1);
    }

    .carousel-nav-btn:disabled {
        opacity: 0.3;
        cursor: not-allowed;
    }

    .carousel-track {
        display: flex;
        gap: 1.5rem;
        transition: transform 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        will-change: transform;
    }

    .carousel-book-card {
        flex: 0 0 280px;
        background: var(--white);
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        transition: all 0.3s ease;
        cursor: pointer;
        text-decoration: none;
        color: inherit;
        display: block;
    }

    .carousel-book-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 16px rgba(0,0,0,0.12);
        text-decoration: none;
    }

    .carousel-book-cover {
        width: 100%;
        height: 380px;
        object-fit: cover;
        background: var(--light-bg);
    }

    .carousel-book-info {
        padding: 1rem;
    }

    .carousel-book-title {
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-color);
        margin-bottom: 0.5rem;
        line-height: 1.3;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .carousel-book-author {
        font-size: 0.875rem;
        color: var(--text-light);
        display: -webkit-box;
        -webkit-line-clamp: 1;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .carousel-book-year {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-top: 0.25rem;
    }

    @media (max-width: 768px) {
        .genre-carousel-section {
            padding: 3rem 0;
        }

        .genre-carousel-header {
            margin-inline: 0;
        }

        .genre-carousel-title {
            font-size: 1.5rem;
        }

        .carousel-container {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            grid-template-areas:
                \"wrapper wrapper\"
                \"prev next\";
            row-gap: 1.5rem;
        }

        .carousel-wrapper {
            grid-area: wrapper;
        }

        .carousel-nav-btn {
            width: 60px;
            height: 60px;
            border-radius: 50%;
        }

        .carousel-nav-btn[data-direction=\"prev\"] {
            grid-area: prev;
            justify-self: start;
        }

        .carousel-nav-btn[data-direction=\"next\"] {
            grid-area: next;
            justify-self: end;
        }

        .carousel-book-card {
            flex: 0 0 calc(100% - 1.5rem);
            max-width: 360px;
            margin: 0 auto;
        }

        .carousel-book-cover {
            height: 380px;
        }
    }

    @media (max-width: 480px) {
        .carousel-track {
            gap: 1rem;
        }

        .carousel-book-card {
            flex: 0 0 100%;
            max-width: 320px;
        }

        .carousel-book-cover {
            height: 380px;
        }

        .carousel-book-info {
            padding: 0.75rem;
        }
    }

    .home-events {
        padding: 4rem 0;
        background: var(--white);
    }

    .home-events__header {
        display: flex;
        flex-direction: column;
        gap: 1rem;
        margin-bottom: 2rem;
    }

    .home-events__title {
        font-size: clamp(2rem, 3vw, 2.5rem);
        font-weight: 800;
        color: var(--text-color);
        margin: 0;
    }

    .home-events__subtitle {
        color: var(--text-light);
        max-width: 640px;
        margin: 0;
        font-size: 1rem;
    }

    /* Spinner / error rows inside the shared .books-grid span the full row. */
    #latest-books-grid > :not(.book-card) {
        grid-column: 1 / -1;
    }

    .home-events__all-link {
        align-self: flex-start;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        min-height: 44px;
        padding: 0.65rem 1.5rem;
        border-radius: 2px;
        border: 1px solid var(--secondary-color);
        color: var(--secondary-color);
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s ease;
    }

    .home-events__all-link:hover {
        background: var(--secondary-color);
        color: #ffffff;
    }

    .home-events-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 1.5rem;
    }

    @media (max-width: 1200px) {
        .home-events__header {
            flex-direction: column;
        }

        .home-events-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 640px) {
        .home-events-grid {
            grid-template-columns: 1fr;
        }
    }

    .home-events-grid .event-card {
        background: var(--white);
        border: 1px solid var(--border-color);
        border-radius: 2px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        transition: box-shadow 0.2s ease, transform 0.2s ease;
    }

    .home-events-grid .event-card:hover {
        transform: translateY(-2px);
        box-shadow: none;
    }

    .home-events-grid .event-card__thumb {
        height: 230px;
        background: var(--light-bg);
    }

    .home-events-grid .event-card__thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .home-events-grid .event-card__placeholder {
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-muted);
        font-size: 2rem;
        height: 100%;
    }

    .home-events-grid .event-card__body {
        padding: 1.25rem 1.5rem 1.75rem;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        flex: 1;
    }

    .home-events-grid .event-card__title {
        font-size: 1.15rem;
        font-weight: 700;
        color: var(--text-color);
        margin: 0;
    }

    .home-events-grid .event-card__title a {
        color: inherit;
        text-decoration: none;
    }

    .home-events-grid .event-card__title a:hover {
        color: var(--primary-color, #d70161);
    }

    .home-events-grid .event-card__meta {
        font-size: 0.95rem;
        font-weight: 600;
        color: var(--text-light);
    }

    .home-events-grid .event-card__button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        gap: 0.4rem;
        min-height: 44px;
        padding: 0.65rem 1rem;
        border-radius: 2px;
        border: 1px solid var(--secondary-color);
        color: var(--secondary-color);
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s ease;
    }

    .home-events-grid .event-card__button:hover {
        background: var(--secondary-color);
        color: #ffffff;
    }
";

ob_start();
?>

<?php
/**
 * Dynamic Section Rendering
 * Render all active sections in the order specified by display_order
 */
if (!empty($sectionsOrdered)) {
    foreach ($sectionsOrdered as $sectionKey => $section) {
        // Skip inactive sections
        if (empty($section['is_active'])) {
            continue;
        }

        // Determine template file path
        $templateFile = __DIR__ . "/home-sections/{$sectionKey}.php";

        // Include template if it exists
        if (file_exists($templateFile)) {
            include $templateFile;
        } else {
            // A home_content row without a core template belongs to a plugin
            // (it owns the row through its own lifecycle, see the cms.home.*
            // hooks). Firing here — inside the ordered loop — is what makes a
            // plugin section obey display_order and is_active like every other
            // section, instead of always landing last via frontend.home.sections.
            \App\Support\Hooks::do('frontend.home.section', [$sectionKey, $section]);
        }
    } // End foreach
} // End if sectionsOrdered
\App\Support\Hooks::do('frontend.home.sections');
?>





<?php
$additional_js = "
<script>
// Traduzioni per JavaScript
const i18n = {
    loading: " . json_encode(__("Caricamento..."), JSON_HEX_TAG) . ",
    loadingBooks: " . json_encode(__("Caricamento libri..."), JSON_HEX_TAG) . ",
    loadingCategories: " . json_encode(__("Caricamento categorie..."), JSON_HEX_TAG) . ",
    errorLoadingBooks: " . json_encode(__("Errore nel caricamento dei libri"), JSON_HEX_TAG) . ",
    exploreByCategory: " . json_encode(__("Esplora per Categoria"), JSON_HEX_TAG) . ",
    viewAllCategories: " . json_encode(__("Visualizza Tutte le Categorie"), JSON_HEX_TAG) . "
};

let currentLatestPage = 1;
let hasMoreLatestBooks = true;
const API_CATALOG_ROUTE = {$apiCatalogRouteJs};

// Load initial content
document.addEventListener('DOMContentLoaded', function() {
    loadStats();
    // Latest books are server-rendered when the controller prefetched them;
    // fetch only as a fallback (e.g. cache write failure) and just wire up
    // the pagination state otherwise.
    const latestGrid = document.getElementById('latest-books-grid');
    if (latestGrid && latestGrid.dataset.serverRendered === '1') {
        currentLatestPage = 1;
        hasMoreLatestBooks = latestGrid.dataset.hasMore === '1';
    } else {
        loadLatestBooks();
    }
    initCarousels();
    initLoadMoreButton();
});

function loadStats() {
    const totalBooksEl = document.getElementById('total-books');
    const availableBooksEl = document.getElementById('available-books');

    // Only load stats if elements exist
    if (!totalBooksEl || !availableBooksEl) return;

    // Values already rendered server-side: skip the aggregate API call.
    if (totalBooksEl.dataset.serverRendered === '1' && availableBooksEl.dataset.serverRendered === '1') return;
    // Edge-cached pages hydrate the availability counter through the dedicated
    // no-store endpoint. Avoid racing that request with the broader catalog API.
    if (availableBooksEl.dataset.liveStat) return;

    // Ask the catalog API for the available-books aggregate. The endpoint only
    // computes that extra COUNT when with_stats=1 is present, so the live
    // search/filter path (which omits the flag) never pays for it.
    const statsUrl = API_CATALOG_ROUTE + (API_CATALOG_ROUTE.indexOf('?') === -1 ? '?' : '&') + 'with_stats=1';

    fetch(statsUrl)
        .then(response => response.json())
        .then(data => {
            totalBooksEl.textContent = data.pagination.total_books;
            // Real available-books count from the API (falls back to the total
            // if the field is missing on an older backend).
            availableBooksEl.textContent = (data.pagination.available_books ?? data.pagination.total_books);
        })
        .catch(error => {
            console.error('Error loading stats:', error);
            totalBooksEl.textContent = '\u2014';
            availableBooksEl.textContent = '\u2014';
        });
}

function loadLatestBooks(page = 1) {
    const grid = document.getElementById('latest-books-grid');

    // Only load if grid exists (section is active)
    if (!grid) return;

    if (page === 1) {
        grid.innerHTML = '<div class=\"loading-placeholder\"><div class=\"loading-ring\" role=\"status\"><span class=\"sr-only\">' + i18n.loading + '</span></div><p class=\"mt-3\">' + i18n.loadingBooks + '</p></div>';
    }

    fetch((window.BASE_PATH || '') + '/api/home/latest?page=' + page)
        .then(response => response.json())
        .then(data => {
            if (page === 1) {
                grid.innerHTML = data.html;
            } else {
                grid.innerHTML += data.html;
            }
            // When edge caching is enabled, card availability is deliberately
            // absent from the HTML returned by this endpoint as well.
            grid.dispatchEvent(new Event('pinakes:catalog-grid-updated', { bubbles: true }));

            currentLatestPage = data.pagination.current_page;
            hasMoreLatestBooks = data.pagination.current_page < data.pagination.total_pages;

            const loadMoreBtn = document.getElementById('load-more-latest');
            if (loadMoreBtn) {
                if (hasMoreLatestBooks) {
                    loadMoreBtn.style.display = 'inline-flex';
                } else {
                    loadMoreBtn.style.display = 'none';
                }
            }
        })
        .catch(error => {
            console.error('Error loading latest books:', error);
            grid.innerHTML = '<div class=\"w-full py-4 text-center\"><div class=\"rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800\">' + i18n.errorLoadingBooks + '</div></div>';
        });
}

// Initialize carousels
function initCarousels() {
    const AUTO_SCROLL_DELAY = 5000;
    const carousels = document.querySelectorAll('.carousel-track');

    carousels.forEach(carousel => {
        const carouselId = carousel.id;
        const prevBtn = document.querySelector('[data-carousel=\"' + carouselId + '\"][data-direction=\"prev\"]');
        const nextBtn = document.querySelector('[data-carousel=\"' + carouselId + '\"][data-direction=\"next\"]');
        const container = prevBtn ? prevBtn.closest('.carousel-container') : null;
        const wrapper = container ? container.querySelector('.carousel-wrapper') : null;

        if (!prevBtn || !nextBtn || !container || !wrapper) return;

        const cards = carousel.querySelectorAll('.carousel-book-card');
        if (cards.length === 0) return;

        let currentIndex = 0;
        let autoplayTimer = null;
        let metrics = calculateMetrics();

        function calculateMetrics() {
            const computed = window.getComputedStyle(carousel);
            const gapValue = parseFloat(computed.gap || computed.columnGap || 0) || 0;
            const cardWidth = cards[0].offsetWidth || 0;
            const step = (cardWidth + gapValue) || wrapper.offsetWidth || 1;
            const wrapperWidth = wrapper.offsetWidth || 0;
            const visibleCount = step > 0 ? Math.max(1, Math.round(wrapperWidth / step)) : 1;
            const maxIndex = Math.max(0, cards.length - visibleCount);
            return { step, maxIndex };
        }

        function goTo(index) {
            if (metrics.maxIndex === 0) {
                currentIndex = 0;
            } else if (index > metrics.maxIndex) {
                currentIndex = 0;
            } else if (index < 0) {
                currentIndex = metrics.maxIndex;
            } else {
                currentIndex = index;
            }

            const offset = -(currentIndex * metrics.step);
            carousel.style.transform = 'translateX(' + offset + 'px)';
        }

        function handleNext() {
            goTo(currentIndex + 1);
        }

        function handlePrev() {
            goTo(currentIndex - 1);
        }

        function startAutoplay() {
            if (cards.length <= 1) return;
            stopAutoplay();
            autoplayTimer = setInterval(() => goTo(currentIndex + 1), AUTO_SCROLL_DELAY);
        }

        function stopAutoplay() {
            if (autoplayTimer) {
                clearInterval(autoplayTimer);
                autoplayTimer = null;
            }
        }

        function restartAutoplay() {
            stopAutoplay();
            startAutoplay();
        }

        prevBtn.addEventListener('click', () => {
            handlePrev();
            restartAutoplay();
        });

        nextBtn.addEventListener('click', () => {
            handleNext();
            restartAutoplay();
        });

        container.addEventListener('mouseenter', stopAutoplay);
        container.addEventListener('mouseleave', startAutoplay);
        container.addEventListener('touchstart', stopAutoplay, { passive: true });
        container.addEventListener('touchend', startAutoplay);
        container.addEventListener('focusin', stopAutoplay);
        container.addEventListener('focusout', startAutoplay);

        const recalibrate = debounce(() => {
            metrics = calculateMetrics();
            goTo(currentIndex);
        }, 200);

        window.addEventListener('resize', recalibrate);

        goTo(0);
        startAutoplay();
    });
}

function debounce(fn, delay) {
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => fn.apply(this, args), delay);
    };
}
// Initialize load more button listener
function initLoadMoreButton() {
    const loadMoreBtn = document.getElementById('load-more-latest');

    // Only attach listener if button exists (section is active)
    if (!loadMoreBtn) return;

    loadMoreBtn.addEventListener('click', function() {
        if (hasMoreLatestBooks) {
            loadLatestBooks(currentLatestPage + 1);
        }
    });
}
</script>
";

$content = ob_get_clean();
include 'layout.php';
?>

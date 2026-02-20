<?php
use App\Support\HtmlHelper;

$title = __("Biblioteca Digitale - La tua biblioteca online");
$catalogRoute = route_path('catalog');
$legacyCatalogRoute = route_path('catalog_legacy');
$apiCatalogRoute = route_path('api_catalog');
$apiCatalogRouteJs = json_encode($apiCatalogRoute, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
$registerRoute = route_path('register');
$homeEvents = $homeEvents ?? [];
$homeEventsEnabled = $homeEventsEnabled ?? false;

// SEO Variables are now passed from FrontendController::home()
// No need to override them here - the controller handles all SEO logic with proper fallbacks
$additional_css = "
    .hero-section {
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
        background-attachment: fixed;
        color: #ffffff;
        padding: 8rem 0 6rem;
        position: relative;
        min-height: 100vh;
        display: flex;
        align-items: center;
    }

    .hero-content {
        position: relative;
        z-index: 2;
    }

    .hero-title {
        font-size: 4rem;
        font-weight: 900;
        letter-spacing: -0.04em;
        line-height: 1.1;
        margin-bottom: 2rem;
        color: #ffffff !important;
        text-shadow: 0 2px 4px rgba(0,0,0,0.3);
    }

    .hero-subtitle {
        font-size: 1.4rem;
        font-weight: 300;
        opacity: 0.9;
        margin-bottom: 3rem;
        max-width: 600px;
        margin-left: auto;
        margin-right: auto;
        line-height: 1.6;
        color: #f8f9fa !important;
        text-shadow: 0 1px 2px rgba(0,0,0,0.2);
    }

    .hero-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 2rem;
        margin-top: 4rem;
    }

    .hero-stat {
        text-align: center;
        padding: 2rem 1rem;
        background: rgba(255, 255, 255, 0.08);
        border-radius: 20px;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.1);
        transition: all 0.3s ease;
    }

    .hero-stat:hover {
        transform: translateY(-4px);
        background: rgba(255, 255, 255, 0.12);
    }

    .hero-stat-number {
        font-size: 2.5rem;
        font-weight: 800;
        display: block;
        margin-bottom: 0.5rem;
        letter-spacing: -0.02em;
        color: #ffffff !important;
        text-shadow: 0 1px 3px rgba(0,0,0,0.2);
    }

    .hero-stat-label {
        font-size: 0.9rem;
        opacity: 0.9;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #f8f9fa !important;
        text-shadow: 0 1px 2px rgba(0,0,0,0.1);
    }

    .section {
        padding: 6rem 0;
    }

    /* Remove bottom padding from last section (genre carousels) to avoid gap before footer */
    section#genre-carousels {
        padding-bottom: 0;
    }

    .section-alt {
        background: var(--light-bg);
    }

    .section-title {
        text-align: center;
        margin-bottom: 1rem;
        font-size: 3rem;
        font-weight: 800;
        color: var(--primary-color);
        letter-spacing: -0.03em;
        line-height: 1.2;
    }

    .section-subtitle {
        text-align: center;
        font-size: 1.2rem;
        color: var(--text-light);
        margin-bottom: 3rem;
        max-width: 600px;
        margin-left: auto;
        margin-right: auto;
        font-weight: 400;
    }

    .feature-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 3rem;
        margin-top: 4rem;
    }

    .feature-card {
        text-align: center;
        padding: 3rem 2rem;
        background: var(--white);
        border-radius: 20px;
        box-shadow: none;
        transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        border: 1px solid transparent;
        position: relative;
        overflow: hidden;
    }

    .feature-card:hover {
        transform: translateY(-8px);
        box-shadow: none;
        border-color: var(--border-color);
    }

    .feature-icon {
        width: 80px;
        height: 80px;
        margin: 0 auto 2rem;
        background: var(--primary-color);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        color: white;
        box-shadow: none;
    }

    .feature-title {
        font-size: 1.4rem;
        font-weight: 700;
        color: var(--primary-color);
        margin-bottom: 1rem;
        letter-spacing: -0.01em;
    }

    .feature-description {
        color: var(--text-light);
        line-height: 1.6;
        font-size: 1rem;
    }

    .cta-section {
        background: var(--light-bg);
        color: var(--primary-color);
        padding: 6rem 0;
        text-align: center;
        position: relative;
        overflow: hidden;
        border-top: 1px solid var(--border-color);
    }

    .cta-section::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: url('data:image/svg+xml,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 100 100\"><defs><pattern id=\"grain\" width=\"100\" height=\"100\" patternUnits=\"userSpaceOnUse\"><circle cx=\"50\" cy=\"50\" r=\"1\" fill=\"black\" opacity=\"0.02\"/></pattern></defs><rect width=\"100\" height=\"100\" fill=\"url(%23grain)\"/></svg>');
    }

    .cta-content {
        position: relative;
        z-index: 2;
    }

    .cta-title {
        font-size: 3rem;
        font-weight: 800;
        margin-bottom: 1.5rem;
        letter-spacing: -0.03em;
        color: var(--primary-color);
    }

    .cta-subtitle {
        font-size: 1.3rem;
        margin-bottom: 3rem;
        opacity: 0.8;
        font-weight: 400;
        max-width: 500px;
        margin-left: auto;
        margin-right: auto;
        color: var(--text-light);
    }

    /* Hero Search Styles */
    .hero-search-container {
        max-width: 1200px;
        margin: 0 auto 4rem;
    }

    .hero-search-form {
        margin-bottom: 3rem;
        position: relative;
    }
form.hero-search-form {
    max-width: 90%;
    margin: auto;
}
    input.hero-search-input.search-input {
    box-shadow: none;
}
    .hero-search-input-group {
        position: relative;
        display: flex;
        align-items: center;
        background: rgba(255, 255, 255, 0.95);
        border-radius: 50px;
        padding: 0.75rem 1.5rem;
        box-shadow: none;
        backdrop-filter: blur(10px);
        transition: all 0.3s ease;
    }

    .hero-search-input-group:focus-within {
        background: white;
        box-shadow: none;
        transform: translateY(-2px);
    }

    .hero-search-icon {
        color: var(--primary-color);
        font-size: 1.125rem;
        margin-right: 1rem;
        opacity: 0.7;
    }

    .hero-search-input {
        flex: 1;
        border: none;
        background: transparent;
        font-size: 1.125rem;
        color: var(--primary-color);
        font-weight: 500;
        outline: none;
        padding: 0.5rem 0;
    }

    .hero-search-input:focus {
        border: none;
        outline: none;
        box-shadow: none;
    }

    .hero-search-input::placeholder {
        color: rgba(44, 62, 80, 0.6);
        font-weight: 400;
    }

    /* Override browser autofill background */
    .hero-search-input:-webkit-autofill,
    .hero-search-input:-webkit-autofill:hover,
    .hero-search-input:-webkit-autofill:focus,
    .hero-search-input:-webkit-autofill:active {
        -webkit-box-shadow: 0 0 0 30px white inset !important;
        -webkit-text-fill-color: var(--primary-color) !important;
        background-color: transparent !important;
        transition: background-color 5000s ease-in-out 0s;
    }

    .hero-search-button {
        background: var(--primary-color);
        color: white;
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: 25px;
        font-weight: 600;
        font-size: 0.875rem;
        letter-spacing: 0.025em;
        transition: all 0.3s ease;
        margin-left: 1rem;
    }

    .hero-search-button:hover {
        background: var(--secondary-color);
        transform: translateY(-1px);
        box-shadow: none;
    }

    .hero-quick-links {
        display: flex;
        justify-content: center;
        gap: 2rem;
        flex-wrap: wrap;
        margin-top: 2rem; /* added spacing between search bar and quick links */
    }

    .hero-quick-link {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        color: #495057 !important;
        text-decoration: none;
        font-weight: 500;
        font-size: 0.875rem;
        transition: all 0.3s ease;
        padding: 0.5rem 1rem;
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.85);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(0, 0, 0, 0.1);
    }

    .hero-quick-link:hover {
        color: #2c3e50 !important;
        background: rgba(255, 255, 255, 0.95);
        transform: translateY(-1px);
        text-decoration: none;
        border-color: #2c3e50;
        box-shadow: none;
    }

    .hero-quick-link i {
        font-size: 0.75rem;
        opacity: 0.8;
    }

    .loading-placeholder {
        text-align: center;
        padding: 4rem 2rem;
        color: var(--text-muted);
    }

    .loading-placeholder .spinner-border {
        width: 3rem;
        height: 3rem;
        border-width: 3px;
    }

    /* Responsive adjustments */
    /* Tablet: 2 columns */
    @media (max-width: 1024px) {
        .feature-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 2.5rem;
        }
    }

    /* Mobile: 1 column */
    @media (max-width: 768px) {
        .hero-section {
            padding: 6rem 0 4rem;
            min-height: 85vh;
            background-attachment: scroll;
        }

        .hero-title {
            font-size: 2.8rem;
        }

        .hero-subtitle {
            font-size: 1.2rem;
        }

        .section-title {
            font-size: 2.2rem;
        }

        .cta-title {
            font-size: 2.2rem;
        }

        .feature-grid {
            grid-template-columns: 1fr;
            gap: 2rem;
        }

        .hero-stats {
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }
    }

    @media (max-width: 480px) {
        .hero-title {
            font-size: 2.2rem;
        }

        .section-title {
            font-size: 1.8rem;
        }

        .cta-title {
            font-size: 1.8rem;
        }

        .hero-stats {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .hero-search-container {
            max-width: 100%;
            margin-bottom: 3rem;
        }

        .hero-search-form {
            margin-bottom: 2rem;
        }

        .hero-search-input-group {
            padding: 0.625rem 1.25rem;
        }

        .hero-search-input {
            font-size: 1rem;
        }

        .hero-search-button {
            padding: 0.625rem 1.25rem;
            font-size: 0.75rem;
        }

        .hero-quick-links {
            gap: 1rem;
        }

        .hero-quick-link {
            font-size: 0.75rem;
            padding: 0.375rem 0.75rem;
        }
    }
        h6.search-section-title {
    text-align: left;
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
        margin-bottom: 2.5rem;
        text-align: center;
    }

    .genre-carousel-title {
        font-size: 2rem;
        font-weight: 800;
        color: var(--primary-color);
        margin: 0;
        letter-spacing: -0.02em;
        text-align: center;
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
        background: white;
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
        color: var(--primary-color);
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
        background: #ffffff;
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
        color: #111827;
        margin: 0;
    }

    .home-events__subtitle {
        color: #4b5563;
        max-width: 640px;
        margin: 0;
        font-size: 1rem;
    }

    .home-events__all-link {
        align-self: flex-start;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.65rem 1.5rem;
        border-radius: 999px;
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
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        transition: box-shadow 0.2s ease, transform 0.2s ease;
    }

    .home-events-grid .event-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
    }

    .home-events-grid .event-card__thumb {
        height: 230px;
        background: #f3f4f6;
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
        color: #9ca3af;
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
        color: #111827;
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
        color: #4b5563;
    }

    .home-events-grid .event-card__button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        gap: 0.4rem;
        padding: 0.65rem 1rem;
        border-radius: 999px;
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
</style>
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
        }
    } // End foreach
} // End if sectionsOrdered
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
    loadLatestBooks();
    initCarousels();
    initLoadMoreButton();
});

function loadStats() {
    const totalBooksEl = document.getElementById('total-books');
    const availableBooksEl = document.getElementById('available-books');

    // Only load stats if elements exist
    if (!totalBooksEl || !availableBooksEl) return;

    fetch(API_CATALOG_ROUTE)
        .then(response => response.json())
        .then(data => {
            totalBooksEl.innerHTML = data.pagination.total_books;
            availableBooksEl.innerHTML = '📚';
        })
        .catch(error => {
            console.error('Error loading stats:', error);
            totalBooksEl.innerHTML = '📚';
            availableBooksEl.innerHTML = '✓';
        });
}

function loadLatestBooks(page = 1) {
    const grid = document.getElementById('latest-books-grid');

    // Only load if grid exists (section is active)
    if (!grid) return;

    if (page === 1) {
        grid.innerHTML = '<div class=\"loading-placeholder\"><div class=\"spinner-border text-primary\" role=\"status\"><span class=\"visually-hidden\">' + i18n.loading + '</span></div><p class=\"mt-3\">' + i18n.loadingBooks + '</p></div>';
    }

    fetch((window.BASE_PATH || '') + '/api/home/latest?page=' + page)
        .then(response => response.json())
        .then(data => {
            if (page === 1) {
                grid.innerHTML = data.html;
            } else {
                grid.innerHTML += data.html;
            }

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
            grid.innerHTML = '<div class=\"col-12 text-center py-4\"><div class=\"alert alert-danger\">' + i18n.errorLoadingBooks + '</div></div>';
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
<?php
use App\Support\HtmlHelper;

$title = __("Catalogo Libri - Biblioteca");
if (!isset($filters)) {
    $filters = [];
}

// SEO Variables
$searchQuery = $filters['search'] ?? '';
if ($searchQuery) {
    $sanitizedSearchQuery = htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8');
    $seoTitle = __("Risultati per '%s' - Catalogo Biblioteca", $sanitizedSearchQuery);
    $seoDescription = __("Scopri tutti i libri che contengono '%s' nel nostro catalogo. Trova autori, titoli e argomenti correlati alla tua ricerca.", $sanitizedSearchQuery);
} else {
    $seoTitle = __("Catalogo Completo Libri - Biblioteca Digitale");
    $seoDescription = __("Sfoglia il nostro catalogo completo di libri disponibili per il prestito. Filtra per categoria, autore, editore e anno di pubblicazione per trovare la tua prossima lettura.");
}
$catalogRoute = route_path('catalog');
$apiCatalogRoute = route_path('api_catalog');
$seoCanonical = HtmlHelper::getBaseUrl() . $catalogRoute;
$seoImage = HtmlHelper::getBaseUrl() . '/uploads/copertine/placeholder.jpg';

// Schema.org structured data
$seoSchema = json_encode([
    "@context" => "https://schema.org",
    "@type" => "CollectionPage",
    "name" => $seoTitle,
    "description" => $seoDescription,
    "url" => $seoCanonical,
    "isPartOf" => [
        "@type" => "Library",
        "name" => __("Biblioteca Digitale"),
        "url" => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/'
    ],
    "potentialAction" => [
        "@type" => "SearchAction",
        "target" => [
            "@type" => "EntryPoint",
            "urlTemplate" => $seoCanonical . "?q={search_term_string}"
        ],
        "query-input" => "required name=search_term_string"
    ]
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// Load theme colors for catalog-specific styles
$themeManager = $container->get('themeManager');
$themeColorizer = $container->get('themeColorizer');
$activeTheme = $themeManager->getActiveTheme();
$themeColors = $themeManager->getThemeColors($activeTheme);
$themePalette = $themeColorizer->generateColorPalette($themeColors);

$additional_css = "
<style>
    :root {
        --accent-color: #f59e0b;
        --success-color: #10b981;
        --warning-color: #f59e0b;
        --error-color: #ef4444;
        --text-primary: #1f2937;
        --text-secondary: #6b7280;
        --text-muted: #9ca3af;
        --bg-primary: #ffffff;
        --bg-secondary: #f9fafb;
        --bg-tertiary: #f3f4f6;
        --border-color: #e5e7eb;
        --border-light: #f3f4f6;
        --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        --radius-sm: 0.375rem;
        --radius-md: 0.5rem;
        --radius-lg: 0.75rem;
        --radius-xl: 1rem;
        --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .catalog-header {
        background: " . htmlspecialchars($themePalette['primary'], ENT_QUOTES, 'UTF-8') . ";
        color: white;
        padding: 4rem 0 3rem;
        position: relative;
        overflow: hidden;
    }

    .catalog-header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 255, 255, 0.05);
    }

    .catalog-header-content {
        position: relative;
        z-index: 2;
    }

    .catalog-title {
        font-size: 3.5rem;
        font-weight: 800;
        letter-spacing: -0.025em;
        margin-bottom: 0.5rem;
        color: #fff;
    }

    .catalog-subtitle {
        font-size: 1.25rem;
        opacity: 0.9;
        font-weight: 300;
        margin-bottom: 1rem;
    }

    /* Enhanced Filters */
    .filters-panel {
        background: var(--bg-primary);
        border-radius: var(--radius-xl);
        border: 1px solid var(--border-color);
        box-shadow: none;
        position: sticky;
        top: 2rem;
        max-height: calc(100vh - 4rem);
        display: flex;
        flex-direction: column;
    }

    .filters-header {
        background: var(--bg-secondary);
        padding: 1.5rem;
        border-bottom: 1px solid var(--border-light);
        flex-shrink: 0;
    }

    .filters-title {
        font-size: 1.25rem;
        font-weight: 700;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .filters-content {
        flex: 1;
        overflow-y: auto;
        overflow-x: hidden;
    }

    .filter-section {
        padding: 1.5rem;
        border-bottom: 1px solid var(--border-light);
    }

    .filter-section:last-child {
        border-bottom: none;
    }

    .filter-title {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .search-box {
        position: relative;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .search-box input {
        width: 100%;
        padding: 0.75rem 1rem 0.75rem 2.5rem;
        border: 1px solid var(--border-color);
        border-radius: var(--radius-lg);
        font-size: 0.875rem;
        transition: var(--transition);
        background: var(--bg-primary);
    }

    .search-box input:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: none;
    }

    .search-box svg {
        width: 1rem;
        height: 1rem;
        position: absolute;
        left: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
        pointer-events: none;
    }

    .search-box i {
        position: absolute;
        left: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
        font-size: 0.875rem;
        width: 18px;
    }

    .filter-options {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .filter-option {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.5rem 0.75rem;
        border-radius: var(--radius-md);
        color: var(--text-secondary);
        text-decoration: none;
        font-size: 0.875rem;
        transition: var(--transition);
        cursor: pointer;
        gap: 0.5rem;
        word-break: break-word;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }

    .filter-option:hover {
        background: var(--bg-secondary);
        color: var(--text-primary);
        transform: translateX(2px);
    }

    .filter-option.active {
        background: var(--primary-color);
        color: white;
        font-weight: 600;
    }

    .filter-option.subgenre {
        margin-left: 1rem;
        font-size: 0.8125rem;
    }

    .filter-option .count-badge {
        background: var(--bg-tertiary);
        color: var(--text-secondary);
        padding: 0.125rem 0.5rem;
        border-radius: var(--radius-sm);
        font-size: 0.75rem;
        font-weight: 600;
    }

    .filter-option.active .count-badge {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    /* Year Range Slider */
    .year-range {
        position: relative;
        margin: 1rem 0;
    }

    .year-range-label {
        display: flex;
        justify-content: space-between;
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-bottom: 0.5rem;
    }

    .year-slider-container {
        position: relative;
        height: 6px;
        background: #000000;
        border-radius: 3px;
        margin: 1rem 0;
    }

    .year-slider-track {
        position: absolute;
        height: 100%;
        background: var(--primary-color);
        border-radius: 3px;
    }

    .year-slider {
        position: absolute;
        width: 100%;
        height: 6px;
        background: transparent;
        outline: none;
        pointer-events: none;
        -webkit-appearance: none;
    }

    .year-slider::-webkit-slider-thumb {
        appearance: none;
        height: 20px;
        width: 20px;
        border-radius: 50%;
        background: var(--primary-color);
        cursor: pointer;
        pointer-events: all;
        border: 3px solid white;
        box-shadow: none;
        transition: var(--transition);
    }

    .year-slider::-webkit-slider-thumb:hover {
        transform: scale(1.1);
        box-shadow: none;
    }

    .year-slider::-moz-range-thumb {
        height: 20px;
        width: 20px;
        border-radius: 50%;
        background: var(--primary-color);
        cursor: pointer;
        pointer-events: all;
        border: 3px solid white;
        box-shadow: none;
    }

    .year-values {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 0.75rem;
        font-size: 0.875rem;
        color: var(--text-secondary);
    }

    .year-value {
        background: var(--primary-color);
        color: white;
        padding: 0.25rem 0.75rem;
        border-radius: var(--radius-md);
        font-weight: 600;
        min-width: 60px;
        text-align: center;
    }

    .year-reset {
        background: var(--bg-secondary);
        color: var(--text-secondary);
        border: 1px solid var(--border-color);
        padding: 0.25rem 0.5rem;
        border-radius: var(--radius-md);
        font-size: 0.75rem;
        cursor: pointer;
        transition: var(--transition);
    }

    .year-reset:hover {
        background: var(--bg-tertiary);
        color: var(--text-primary);
    }

    /* Enhanced Availability Filter */
    .availability-options {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.5rem;
    }

    .availability-option {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem;
        border: 2px solid var(--border-color);
        border-radius: var(--radius-lg);
        background: var(--bg-primary);
        cursor: pointer;
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }

    .availability-option:hover {
        border-color: var(--primary-color);
        transform: translateY(-1px);
        box-shadow: none;
    }

    .availability-option.active {
        border-color: var(--primary-color);
        background: var(--primary-color);
        color: white;
        box-shadow: none;
    }

    .availability-option.active::before {
        display: none;
    }

    .availability-icon {
        font-size: 1.25rem;
        width: 2rem;
        height: 2rem;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .availability-option .availability-icon {
        background: var(--bg-secondary);
        color: var(--primary-color);
        transition: var(--transition);
    }

    .availability-option.active .availability-icon,
    .availability-option.active .availability-icon svg {
        background: rgba(255, 255, 255, 0.2);
        color: white;
        fill: white;
    }

    .availability-text {
        flex: 1;
    }

    .availability-title {
        font-weight: 600;
        font-size: 0.875rem;
        line-height: 1.2;
        margin-bottom: 0.125rem;
    }

    .availability-desc {
        font-size: 0.75rem;
        opacity: 0.8;
        line-height: 1.3;
    }

    .availability-option.active .availability-desc {
        opacity: 0.9;
    }

    .availability-count {
        background: var(--bg-tertiary);
        color: var(--text-secondary);
        padding: 0.25rem 0.5rem;
        border-radius: var(--radius-sm);
        font-size: 0.75rem;
        font-weight: 700;
        flex-shrink: 0;
    }

    .availability-option.active .availability-count {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    /* Pages Range Filter */
    .pages-filter {
        position: relative;
        margin: 1rem 0;
    }

    .pages-options {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.5rem;
        margin-bottom: 1rem;
    }

    .pages-option {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.25rem;
        padding: 0.75rem 0.5rem;
        border: 1px solid var(--border-color);
        border-radius: var(--radius-md);
        background: var(--bg-primary);
        cursor: pointer;
        transition: var(--transition);
        font-size: 0.75rem;
    }

    .pages-option:hover {
        border-color: var(--primary-color);
        background: var(--bg-secondary);
    }

    .pages-option.active {
        border-color: var(--primary-color);
        background: var(--primary-color);
        color: white;
    }

    .pages-option-icon {
        font-size: 1rem;
        opacity: 0.7;
    }

    .pages-option.active .pages-option-icon {
        opacity: 1;
    }

    .pages-option-text {
        font-weight: 600;
        text-align: center;
        line-height: 1.2;
    }

    .custom-range-toggle {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.5rem;
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-md);
        cursor: pointer;
        font-size: 0.75rem;
        color: var(--text-secondary);
        transition: var(--transition);
        margin-bottom: 0.5rem;
    }

    .custom-range-toggle:hover,
    .custom-range-toggle.active {
        border-color: var(--primary-color);
        color: var(--primary-color);
        background: var(--bg-primary);
    }

    .custom-pages-inputs {
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        gap: 0.5rem;
        align-items: center;
        margin-top: 0.5rem;
    }

    .custom-pages-inputs.hidden {
        display: none;
    }

    .pages-input {
        padding: 0.5rem;
        border: 1px solid var(--border-color);
        border-radius: var(--radius-md);
        font-size: 0.875rem;
        text-align: center;
    }

    .pages-input:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: none;
    }

    .pages-separator {
        font-size: 0.75rem;
        color: var(--text-muted);
        font-weight: 600;
    }

    .clear-all-btn {
        width: 100%;
        padding: 0.75rem;
        background: #0f172a;
        color: #fff;
        border: 1px solid #0f172a;
        border-radius: var(--radius-md);
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        box-shadow: none;
    }

    .clear-all-btn:hover {
        background: #000000;
        border-color: #000000;
        transform: translateY(-1px);
    }

    .clear-all-btn i {
        font-size: 0.875rem;
    }

    /* Results Header */
    .results-header {
        background: var(--bg-primary);
        padding: 1.5rem;
        border-radius: var(--radius-xl);
        border: 1px solid var(--border-color);
        box-shadow: none;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .results-info {
        font-size: 1rem;
        font-weight: 500;
        color: var(--text-secondary);
    }

    .results-info strong {
        color: var(--primary-color);
        font-weight: 700;
    }

    .sort-select {
        padding: 0.5rem 1rem;
        border: 1px solid var(--border-color);
        border-radius: var(--radius-md);
        background: var(--bg-primary);
        color: var(--text-primary);
        font-size: 0.875rem;
        transition: var(--transition);
    }

    .sort-select:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: none;
    }

    .clear-filters-top-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        background: #1f2937;
        color: white;
        border: none;
        border-radius: var(--radius-md);
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        white-space: nowrap;
    }

    .clear-filters-top-btn:hover {
        background: #111827;
        transform: translateY(-1px);
    }

    .clear-filters-top-btn i {
        font-size: 1rem;
    }

    /* Active Filters */
    .active-filters {
        background: var(--bg-secondary);
        padding: 1rem;
        border-radius: var(--radius-lg);
        border: 1px solid var(--border-color);
        margin-bottom: 1.5rem;
    }

    .active-filters-title {
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .filter-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .filter-tag {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.375rem 0.75rem;
        background: #1f2937;
        color: white;
        border-radius: var(--radius-md);
        font-size: 0.75rem;
        font-weight: 600;
        transition: var(--transition);
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .filter-tag:hover {
        background: #111827;
        transform: translateY(-1px);
        box-shadow: none;
    }

    .filter-tag-remove {
        cursor: pointer;
        font-weight: 700;
        font-size: 1rem;
        line-height: 1;
        transition: var(--transition);
        color: rgba(255, 255, 255, 0.9);
        background: rgba(0, 0, 0, 0.1);
        border-radius: 50%;
        width: 1.25rem;
        height: 1.25rem;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-left: 0.25rem;
    }

    .filter-tag-remove:hover {
        background: rgba(0, 0, 0, 0.2);
        color: white;
        transform: scale(1.1);
    }

    /* Enhanced Book Cards */
    .books-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 1.5rem;
    }

    .book-card {
        background: var(--bg-primary);
        border-radius: var(--radius-xl);
        overflow: hidden;
        box-shadow: none;
        border: 1px solid var(--border-color);
        transition: var(--transition);
        position: relative;
    }

    .book-card:hover {
        transform: translateY(-4px);
        box-shadow: none;
    }

    .book-image-container {
        position: relative;
        aspect-ratio: 3/4;
        overflow: hidden;
        background: var(--bg-tertiary);
    }

    .book-image {
        width: 100%;
        height: 100%;
        object-fit: contain;
        transition: var(--transition);
    }

    .book-card:hover .book-image {
        transform: scale(1.05);
    }

    .book-status-badge {
        position: absolute;
        top: 0.75rem;
        right: 0.75rem;
        padding: 0.375rem 0.75rem;
        border-radius: var(--radius-md);
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        backdrop-filter: blur(10px);
    }

    .status-available {
        background: rgba(16, 185, 129, 0.9);
        color: white;
    }

    .status-borrowed {
        background: rgba(239, 68, 68, 0.9);
        color: white;
    }

    .book-content {
    flex: 1;
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
        flex: 1;
    }

    .book-title {
        font-size: 1.125rem;
        font-weight: 700;
        line-height: 1.4;
        margin-bottom: 0.5rem;
        color: var(--text-primary);
    }

    .book-title a {
        color: inherit;
        text-decoration: none;
        transition: var(--transition);
    }

    .book-title a:hover {
        color: var(--dark-color);
    }

    .book-author {
        font-size: 0.875rem;
        color: var(--text-secondary);
        margin-bottom: 0.5rem;
        min-height: 1.2em;
    }

    .book-author a {
        color: inherit;
        text-decoration: none;
    }

    .book-author a:hover {
        color: var(--primary-color);
    }

    .book-meta {
        font-size: 0.75rem;
        color: var(--text-muted);
        line-height: 1.5;
        margin-bottom: auto;
        min-height: 1.5em;
    }

    .book-actions {
    margin-top: auto;
        margin-top: auto;
        display: flex;
        gap: 0.5rem;
        padding-top: 1rem;
    }

    .book-actions .btn-cta {
        width: 100%;
        justify-content: center;
    }

    .btn-secondary {
        padding: 0.5rem;
        background: var(--bg-secondary);
        color: var(--text-secondary);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-md);
        cursor: pointer;
        transition: var(--transition);
    }

    .btn-secondary:hover {
        background: var(--bg-tertiary);
        color: var(--text-primary);
    }

    /* Loading States */
    .loading-skeleton {
        background: var(--bg-tertiary);
        animation: pulse 1.2s ease-in-out infinite alternate;
    }

    @keyframes pulse {
        from { opacity: 0.6; }
        to { opacity: 1; }
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        color: var(--text-secondary);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 400px;
        width: 100%;
        background: var(--bg-primary);
        border-radius: var(--radius-xl);
        border: 1px solid var(--border-color);
        grid-column: 1 / -1;
    }

    #books-container {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }

    .empty-state-icon {
        font-size: 4rem;
        margin-bottom: 1rem;
        opacity: 0.5;
    }

    .empty-state-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .empty-state-text {
        font-size: 1rem;
        margin-bottom: 1.5rem;
    }

    .btn-cta {
        display: inline-flex;
        align-items: center;
        padding: 0.75rem 1.5rem;
        background: " . htmlspecialchars($themePalette['button'], ENT_QUOTES, 'UTF-8') . ";
        color: " . htmlspecialchars($themePalette['button_text'], ENT_QUOTES, 'UTF-8') . ";
        border: 1px solid " . htmlspecialchars($themePalette['button'], ENT_QUOTES, 'UTF-8') . ";
        border-radius: var(--radius-md);
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
    }

    .btn-cta:hover {
        background: " . htmlspecialchars($themePalette['button_hover'], ENT_QUOTES, 'UTF-8') . ";
        border-color: " . htmlspecialchars($themePalette['button_hover'], ENT_QUOTES, 'UTF-8') . ";
        transform: translateY(-1px);
    }

    .btn-cta-sm {
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
    }

    /* Responsive Design */
    @media (max-width: 1024px) {
        .catalog-title {
            font-size: 2.5rem;
        }

        .filters-panel {
            position: static;
            max-height: none;
            margin-bottom: 2rem;
        }
    }

    @media (max-width: 768px) {
        .catalog-header {
            padding: 2rem 0 1.5rem;
        }

        .catalog-title {
            font-size: 2rem;
        }

        .books-grid {
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
        }

        .results-header {
            flex-direction: column;
            align-items: stretch;
            gap: 1rem;
        }

        .filter-section {
            padding: 1rem;
        }

        .clear-filters-text {
            display: none;
        }

        .clear-filters-top-btn {
            padding: 0.5rem 0.75rem;
        }
    }

    @media (max-width: 480px) {
        .catalog-title {
            font-size: 1.75rem;
        }

        .books-grid {
            grid-template-columns: 1fr;
        }

        .book-actions {
    margin-top: auto;
            flex-direction: column;
        }
    }

    /* Smooth Transitions */
    .fade-in {
        animation: fadeIn 0.3s ease-out;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Pagination - keep in sync with global dark accent */
    .pagination .page-link {
        color: #111827;
        border-color: #111827;
        font-weight: 600;
        border-radius: var(--radius-md);
        padding: 0.5rem 0.9rem;
        transition: var(--transition);
    }

    .pagination .page-link:hover,
    .pagination .page-link:focus {
        color: #ffffff;
        background-color: #111827;
        border-color: #111827;
        box-shadow: none;
        text-decoration: none;
    }

    .pagination .page-item.active .page-link {
        color: #ffffff;
        background-color: #000000;
        border-color: #000000;
    }

    ul.pagination.justify-content-center {
        gap: 20px;
    }

    .page-item:first-child .page-link {
        border-top-left-radius: var(--radius-md);
        border-bottom-left-radius: var(--radius-md);
    }

    .pagination .page-item.disabled .page-link {
        color: #9ca3af;
        border-color: #d1d5db;
        background-color: transparent;
        box-shadow: none;
    }

    /* Genre filter back button container */
    .filter-back-container {
        margin-bottom: 1rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid var(--border-light);
    }

    /* Genre filter back button */
    .filter-back-btn {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 0.75rem;
        border-radius: var(--radius-md);
        background: var(--bg-secondary);
        color: var(--text-secondary);
        font-size: 0.875rem;
        transition: var(--transition);
        cursor: pointer;
        text-decoration: none;
        width: fit-content;
    }

    .filter-back-btn:hover {
        background: var(--primary-color);
        color: white;
    }

    .filter-back-btn i {
        font-size: 0.75rem;
    }

    .filter-back-btn span {
        font-weight: 500;
    }
</style>
";

ob_start();
?>

<!-- Catalog Header -->
<section class="catalog-header">
    <div class="container">
        <div class="catalog-header-content text-center">
            <h1 class="catalog-title"><?= __("Catalogo Libri") ?></h1>
            <p class="catalog-subtitle"><?= __("Scopri migliaia di titoli nella nostra collezione digitale") ?></p>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb justify-content-center bg-transparent p-0 mb-0">
                    <li class="breadcrumb-item">
                        <a href="/" class="text-white opacity-75"><?= __("Home") ?></a>
                    </li>
                    <li class="breadcrumb-item text-white active" aria-current="page">
                        <?= __("Catalogo") ?>
                    </li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<!-- Main Content -->
<section class="py-5">
    <div class="container">
        <div class="row">
            <!-- Enhanced Filters Sidebar -->
            <div class="col-lg-3 mb-4">
                <div class="filters-panel">
                    <div class="filters-header">
                        <h5 class="filters-title">
                            <i class="fas fa-filter"></i>
                            <?= __("Filtri") ?>
                        </h5>
                    </div>

                    <div class="filters-content">
                        <!-- Search -->
                        <div class="filter-section">
                        <div class="filter-title">
                            <i class="fas fa-search"></i>
                            <?= __("Ricerca") ?>
                        </div>
                        <div class="search-box">
                            <input type="text"
                                   id="search-input"
                                   placeholder="<?= __("Cerca titoli, autori, ISBN...") ?>"
                                   value="<?= htmlspecialchars($filters['search'] ?? '') ?>"
                                   onkeyup="debounceSearch(this.value)">
                            <svg class="svg-inline--fa fa-magnifying-glass" data-prefix="fas" data-icon="magnifying-glass" role="img" viewBox="0 0 512 512" aria-hidden="true">
                                <path fill="currentColor" d="M416 208c0 45.9-14.9 88.3-40 122.7L502.6 457.4c12.5 12.5 12.5 32.8 0 45.3s-32.8 12.5-45.3 0L330.7 376C296.3 401.1 253.9 416 208 416 93.1 416 0 322.9 0 208S93.1 0 208 0 416 93.1 416 208zM208 352a144 144 0 1 0 0-288 144 144 0 1 0 0 288z"></path>
                            </svg>
                        </div>
                    </div>

                    <!-- Genres -->
                    <div class="filter-section">
                        <div class="filter-title">
                            <i class="fas fa-tags"></i>
                            <?= __("Generi") ?>
                        </div>
                        <div class="filter-options" id="genres-filter">
                            <?php if($genre_display['level'] > 0): ?>
                            <div class="filter-back-container">
                                <a href="#" class="filter-back-btn" onclick="updateFilter('genere', '<?= $genre_display['level'] === 1 ? '' : addslashes($genre_display['parent']['nome'] ?? '') ?>'); return false;" title="<?= __("Torna alla categoria superiore") ?>">
                                    <i class="fas fa-arrow-left"></i>
                                    <span><?= __("Torna alla categoria superiore") ?></span>
                                </a>
                            </div>
                            <?php endif; ?>
                            <?php if($genre_display['level'] === 0): ?>
                                <!-- Display Level 1 Genres (Radici) -->
                                <?php foreach($genre_display['genres'] as $genere): ?>
                                    <?php if (($genere['cnt'] ?? 0) > 0): ?>
                                    <a href="#"
                                       class="filter-option count"
                                       onclick="updateFilter('genere', '<?= addslashes($genere['nome']) ?>'); return false;"
                                       title="<?= htmlspecialchars($genere['nome']) ?>">
                                        <span><?= htmlspecialchars($genere['nome']) ?></span>
                                        <span class="count-badge"><?= $genere['cnt'] ?></span>
                                    </a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <!-- Display Level 2 or 3 Genres (children of selected parent) -->
                                <?php foreach($genre_display['genres'] as $genere): ?>
                                    <?php if (($genere['cnt'] ?? 0) > 0): ?>
                                    <?php
                                        $displayName = $genere['nome'];
                                        if (strpos($genere['nome'], ' - ') !== false) {
                                            $parts = explode(' - ', $genere['nome']);
                                            $displayName = end($parts);
                                        }
                                    ?>
                                    <a href="#"
                                       class="filter-option count"
                                       onclick="updateFilter('genere', '<?= addslashes($genere['nome']) ?>'); return false;"
                                       title="<?= htmlspecialchars($genere['nome']) ?>">
                                        <span><?= htmlspecialchars($displayName) ?></span>
                                        <span class="count-badge"><?= $genere['cnt'] ?></span>
                                    </a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Publishers -->
                    <div class="filter-section">
                        <div class="filter-title">
                            <i class="fas fa-building"></i>
                            <?= __("Editori") ?>
                        </div>
                        <div class="filter-options" id="publishers-filter">
                            <?php foreach($filter_options['editori'] as $editore): ?>
                                <a href="#"
                                   class="filter-option count <?= ($filters['editore'] ?? '') == $editore['nome'] ? 'active' : '' ?>"
                                   onclick="updateFilter('editore', '<?= addslashes($editore['nome']) ?>'); return false;">
                                    <span><?= htmlspecialchars(html_entity_decode($editore['nome'], ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?></span>
                                    <span class="count-badge"><?= $editore['cnt'] ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Availability -->
                    <div class="filter-section">
                        <div class="filter-title">
                            <i class="fas fa-bookmark"></i>
                            <?= __("Disponibilità") ?>
                        </div>
                        <div class="availability-options">
                        <div class="availability-option <?= empty($filters['disponibilita']) ? 'active' : '' ?>"
                             data-filter-value=""
                             onclick="updateFilter('disponibilita', '')">
                                <div class="availability-icon">
                                    <i class="fas fa-th-large"></i>
                                </div>
                                <div class="availability-text">
                                    <div class="availability-title"><?= __("Tutti i libri") ?></div>
                                    <div class="availability-desc"><?= __("Disponibili e in prestito") ?></div>
                                </div>
                                <div class="availability-count" id="total-books-count">
                                    <?= number_format($filter_options['availability_stats']['total'] ?? $total_books) ?>
                                </div>
                            </div>

                        <div class="availability-option <?= ($filters['disponibilita'] ?? '') === 'disponibile' ? 'active' : '' ?>"
                             data-filter-value="disponibile"
                             onclick="updateFilter('disponibilita', 'disponibile')">
                                <div class="availability-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="availability-text">
                                    <div class="availability-title"><?= __("Disponibili") ?></div>
                                    <div class="availability-desc"><?= __("Pronti per il prestito") ?></div>
                                </div>
                                <div class="availability-count" id="available-books-count">
                                    <?= number_format($filter_options['availability_stats']['available'] ?? 0) ?>
                                </div>
                            </div>

                        <div class="availability-option <?= ($filters['disponibilita'] ?? '') === 'prestato' ? 'active' : '' ?>"
                             data-filter-value="prestato"
                             onclick="updateFilter('disponibilita', 'prestato')">
                                <div class="availability-icon">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="availability-text">
                                    <div class="availability-title"><?= __("In prestito") ?></div>
                                    <div class="availability-desc"><?= __("Attualmente prestati") ?></div>
                                </div>
                                <div class="availability-count" id="borrowed-books-count">
                                    <?= number_format($filter_options['availability_stats']['borrowed'] ?? 0) ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Year Range -->
                    <div class="filter-section">
                        <div class="filter-title">
                            <i class="fas fa-calendar-alt"></i>
                            <?= __("Anno di pubblicazione") ?>
                        </div>
                        <div class="year-range">
                            <div class="year-range-label">
                                <span>1900</span>
                                <span><?= date('Y') ?></span>
                            </div>
                            <div class="year-slider-container">
                                <div class="year-slider-track" id="year-track"></div>
                                <input type="range"
                                       id="year-min"
                                       class="year-slider"
                                       min="1900"
                                       max="<?= date('Y') ?>"
                                       value="<?= $filters['anno_min'] ?? 1900 ?>"
                                       oninput="updateYearRange()">
                                <input type="range"
                                       id="year-max"
                                       class="year-slider"
                                       min="1900"
                                       max="<?= date('Y') ?>"
                                       value="<?= $filters['anno_max'] ?? date('Y') ?>"
                                       oninput="updateYearRange()">
                            </div>
                            <div class="year-values">
                                <span class="year-value" id="year-min-value"><?= $filters['anno_min'] ?? 1900 ?></span>
                                <button type="button" class="year-reset" onclick="resetYearRange()" title="<?= __("Reset anni") ?>">
                                    <i class="fas fa-undo"></i>
                                </button>
                                <span class="year-value" id="year-max-value"><?= $filters['anno_max'] ?? date('Y') ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Clear All -->
                    <div class="filter-section">
                        <button class="clear-all-btn" onclick="clearAllFilters()">
                            <i class="fas fa-times"></i>
                            <?= __("Pulisci tutti i filtri") ?>
                        </button>
                    </div>
                    </div><!-- /filters-content -->
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-lg-9">
                <!-- Active Filters Display -->
                <div id="active-filters" class="active-filters" style="display: none;">
                    <div class="active-filters-title"><?= __("Filtri attivi:") ?></div>
                    <div class="filter-tags" id="active-filters-list"></div>
                </div>

                <!-- Results Header -->
                <div class="results-header">
                    <div class="results-info">
                        <strong id="total-count"><?= number_format($total_books) ?></strong>
                        <span id="results-text"><?= $total_books == 1 ? __('libro trovato') : __('libri trovati') ?></span>
                    </div>
                    <div style="display: flex; gap: 0.75rem; align-items: center;">
                        <button class="clear-filters-top-btn" onclick="clearAllFilters()" title="<?= __("Rimuovi tutti i filtri") ?>">
                            <i class="fas fa-filter-circle-xmark"></i>
                            <span class="clear-filters-text"><?= __("Pulisci filtri") ?></span>
                        </button>
                        <select class="sort-select" onchange="updateFilter('sort', this.value)" id="sort-select">
                            <option value="newest" <?= ($filters['sort'] ?? 'newest') === 'newest' ? 'selected' : '' ?>><?= __("Più recenti") ?></option>
                            <option value="oldest" <?= ($filters['sort'] ?? 'newest') === 'oldest' ? 'selected' : '' ?>><?= __("Più vecchi") ?></option>
                            <option value="title_asc" <?= ($filters['sort'] ?? 'newest') === 'title_asc' ? 'selected' : '' ?>><?= __("Titolo A-Z") ?></option>
                            <option value="title_desc" <?= ($filters['sort'] ?? 'newest') === 'title_desc' ? 'selected' : '' ?>><?= __("Titolo Z-A") ?></option>
                            <option value="author_asc" <?= ($filters['sort'] ?? 'newest') === 'author_asc' ? 'selected' : '' ?>><?= __("Autore A-Z") ?></option>
                            <option value="author_desc" <?= ($filters['sort'] ?? 'newest') === 'author_desc' ? 'selected' : '' ?>><?= __("Autore Z-A") ?></option>
                        </select>
                    </div>
                </div>

                <!-- Books Grid -->
                <div id="books-container">
                    <div class="books-grid" id="books-grid">
                        <?php include 'catalog-grid.php'; ?>
                    </div>

                    <!-- Loading State -->
                    <div id="loading-state" style="display: none;" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden"><?= __("Caricamento...") ?></span>
                        </div>
                    </div>

                    <!-- Empty State -->
                    <div id="empty-state" style="display: none;" class="empty-state">
                        <i class="fas fa-search empty-state-icon"></i>
                        <h4 class="empty-state-title"><?= __("Nessun libro trovato") ?></h4>
                        <p class="empty-state-text"><?= __("Prova a modificare i filtri o la tua ricerca") ?></p>
                        <button type="button" class="btn-cta btn-cta-sm" onclick="clearAllFilters()">
                            <i class="fas fa-redo me-2"></i>
                            <?= __("Pulisci filtri") ?>
                        </button>
                    </div>
                </div>

                <!-- Pagination -->
                <div id="pagination-container" class="mt-4">
                    <!-- Pagination will be added here -->
                </div>
            </div>
        </div>
    </div>
</section>

<?php
$initialPaginationConfig = [
    'current_page' => max(1, (int)($current_page ?? 1)),
    'total_pages' => max(1, (int)($total_pages ?? 1)),
    'total_books' => max(0, (int)($total_books ?? 0)),
];
$initialPaginationJson = json_encode($initialPaginationConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$currentYear = (int)date('Y');

// Create i18n translations object for JavaScript
$i18nTranslations = [
    // Filter labels
    'search' => __('Ricerca'),
    'genere' => __('Genere'),
    'editore' => __('Editore'),
    'disponibilita' => __('Disponibilità'),
    'anno_min' => __('Anno min'),
    'anno_max' => __('Anno max'),
    'sort' => __('Ordinamento'),

    // Sort labels
    'newest' => __('Più recenti'),
    'oldest' => __('Più vecchi'),
    'title_asc' => __('Titolo A-Z'),
    'title_desc' => __('Titolo Z-A'),
    'author_asc' => __('Autore A-Z'),
    'author_desc' => __('Autore Z-A'),

    // Status labels
    'disponibile' => __('Disponibile'),
    'in_prestito' => __('In prestito'),

    // Actions
    'rimuovi_filtro' => __('Rimuovi filtro'),
    'pagina_precedente' => __('Pagina precedente'),
    'pagina_successiva' => __('Pagina successiva'),
    'torna_categoria_superiore' => __('Torna alla categoria superiore'),

    // Plurals
    'libro_trovato' => __('libro trovato'),
    'libri_trovati' => __('libri trovati'),

    // Errors
    'errore_caricamento' => __('Errore nel caricamento. Riprova.')
];
$i18nJson = json_encode($i18nTranslations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$catalogRouteJs = json_encode($catalogRoute, JSON_UNESCAPED_SLASHES);
$apiCatalogRouteJs = json_encode($apiCatalogRoute, JSON_UNESCAPED_SLASHES);

$additional_js = <<<JS
<script>
// Translations object (PHP-rendered for JavaScript)
const i18n = {$i18nJson};
const CATALOG_ROUTE = {$catalogRouteJs};
const API_CATALOG_ROUTE = {$apiCatalogRouteJs};

let currentFilters = {};
let searchTimeout;
let loadingTimeout;
const CURRENT_YEAR = {$currentYear};

document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.forEach((value, key) => {
        if (!value) {
            return;
        }

        if (key === 'q') {
            currentFilters.search = value;
            const searchInput = document.getElementById('search-input');
            if (searchInput) {
                // Sanitize the value before setting it to the input field to prevent XSS
                const sanitizedValue = value.replace(/[<>&"']/g, function(match) {
                    return {
                        '<': '&lt;',
                        '>': '&gt;',
                        '&': '&amp;',
                        '"': '&quot;',
                        "'": '&#x27;'
                    }[match];
                });
                searchInput.value = sanitizedValue;
            }
        } else {
            currentFilters[key] = value;
        }
    });

    updateActiveFiltersDisplay();
    updateURL();
    updateYearRange(false);

    const initialPagination = {$initialPaginationJson};
    updatePagination(initialPagination);
    syncAvailabilityActiveState();
});

function debounceSearch(value) {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        updateFilter('search', value);
    }, 300);
}

function updateFilter(key, value) {
    if (value && value !== '') {
        currentFilters[key] = value;
    } else {
        delete currentFilters[key];
    }

    currentFilters.page = 1;
    if (key === 'disponibilita') {
        syncAvailabilityActiveState();
    }
    updateActiveFiltersDisplay();
    updateURL();
    loadBooks();
}

function syncAvailabilityActiveState() {
    const currentValue = currentFilters.disponibilita || '';
    const options = document.querySelectorAll('.availability-option');
    options.forEach(option => {
        const targetValue = option.dataset.filterValue || '';
        if (targetValue === currentValue) {
            option.classList.add('active');
        } else {
            option.classList.remove('active');
        }
    });
}

function clearAllFilters() {
    // Simply redirect to catalog without any query parameters
    // This will reload the page and show all filter options
    window.location.href = CATALOG_ROUTE;
}

function removeFilter(key) {
    delete currentFilters[key];
    currentFilters.page = 1;

    updateActiveFiltersDisplay();
    updateURL();
    loadBooks();
}

function updateURL() {
    const params = new URLSearchParams();
    Object.keys(currentFilters).forEach((filterKey) => {
        const value = currentFilters[filterKey];
        if (value !== '' && value !== null && value !== undefined) {
            params.set(filterKey, value);
        }
    });

    const query = params.toString();
    const newURL = CATALOG_ROUTE + (query ? '?' + query : '');
    window.history.replaceState({}, '', newURL);
}

function updateActiveFiltersDisplay() {
    const container = document.getElementById('active-filters');
    const list = document.getElementById('active-filters-list');

    if (!container || !list) {
        return;
    }

    list.innerHTML = '';
    let hasActiveFilters = false;

    const filterLabels = {
        search: i18n.search,
        genere: i18n.genere,
        editore: i18n.editore,
        disponibilita: i18n.disponibilita,
        anno_min: i18n.anno_min,
        anno_max: i18n.anno_max,
        sort: i18n.sort,
    };

    const sortLabels = {
        newest: i18n.newest,
        oldest: i18n.oldest,
        title_asc: i18n.title_asc,
        title_desc: i18n.title_desc,
        author_asc: i18n.author_asc,
        author_desc: i18n.author_desc,
    };

    Object.keys(currentFilters).forEach((filterKey) => {
        if (filterKey === 'page') {
            return;
        }

        const value = currentFilters[filterKey];
        if (!value) {
            return;
        }

        hasActiveFilters = true;

        let displayValue = value;
        if (filterKey === 'sort') {
            displayValue = sortLabels[value] || value;
        } else if (filterKey === 'disponibilita') {
            displayValue = value === 'disponibile' ? i18n.disponibile : i18n.in_prestito;
        }

        const tag = document.createElement('span');
        tag.className = 'filter-tag';
        tag.textContent = (filterLabels[filterKey] || filterKey) + ': ' + displayValue;

        const closeBtn = document.createElement('span');
        closeBtn.className = 'filter-tag-remove';
        closeBtn.innerHTML = '&times;';
        closeBtn.title = i18n.rimuovi_filtro;
        closeBtn.addEventListener('click', () => removeFilter(filterKey));

        tag.appendChild(closeBtn);
        list.appendChild(tag);
    });

    container.style.display = hasActiveFilters ? 'block' : 'none';
}

function loadBooks() {
    const container = document.getElementById('books-grid');
    const loading = document.getElementById('loading-state');
    const empty = document.getElementById('empty-state');

    if (!container || !loading || !empty) {
        return;
    }

    container.style.display = 'none';
    loading.style.display = 'block';
    empty.style.display = 'none';

    const params = new URLSearchParams(currentFilters);

    fetch(API_CATALOG_ROUTE + '?' + params.toString())
        .then((response) => response.json())
        .then((data) => {
            loading.style.display = 'none';

            const hasNoResults = !data.html || data.html.trim() === '';

            if (hasNoResults) {
                empty.style.display = 'block';
                container.style.display = 'none';
            } else {
                container.style.display = 'grid';
                container.innerHTML = data.html;
                container.classList.add('fade-in');
            }

            const totalCount = document.getElementById('total-count');
            const resultsText = document.getElementById('results-text');
            if (totalCount && resultsText && data.pagination) {
                totalCount.textContent = data.pagination.total_books.toLocaleString();
                resultsText.textContent = data.pagination.total_books === 1 ? i18n.libro_trovato : i18n.libri_trovati;
            }

            // Update filter options if provided
            if (data.filter_options) {
                updateFilterOptions(data.filter_options, data.genre_display);
            }

            updatePagination(data.pagination);
        })
        .catch((error) => {
            console.error('Error loading books:', error);
            loading.style.display = 'none';
            container.style.display = 'grid';
            container.innerHTML = '<div class="col-12"><div class="alert alert-danger">' + i18n.errore_caricamento + '</div></div>';
        });
}

function updatePagination(pagination) {
    const container = document.getElementById('pagination-container');
    if (!container) {
        return;
    }

    if (!pagination || pagination.total_pages <= 1) {
        container.innerHTML = '';
        return;
    }

    const current = pagination.current_page;
    const total = pagination.total_pages;

    let html = '<nav aria-label="Page navigation"><ul class="pagination justify-content-center">';

    if (current > 1) {
        html += '<li class="page-item"><a class="page-link" href="#" onclick="goToPage(' + (current - 1) + ')" title="' + i18n.pagina_precedente + '"><i class="fas fa-chevron-left"></i></a></li>';
    }

    const visiblePages = 5;
    let startPage = Math.max(1, current - Math.floor(visiblePages / 2));
    let endPage = startPage + visiblePages - 1;

    if (endPage > total) {
        endPage = total;
        startPage = Math.max(1, endPage - visiblePages + 1);
    }

    if (startPage > 1) {
        html += '<li class="page-item"><a class="page-link" href="#" onclick="goToPage(1)">1</a></li>';
        if (startPage > 2) {
            html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }

    for (let i = startPage; i <= endPage; i += 1) {
        const activeClass = i === current ? ' active' : '';
        html += '<li class="page-item' + activeClass + '"><a class="page-link" href="#" onclick="goToPage(' + i + ')">' + i + '</a></li>';
    }

    if (endPage < total) {
        if (endPage < total - 1) {
            html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        html += '<li class="page-item"><a class="page-link" href="#" onclick="goToPage(' + total + ')">' + total + '</a></li>';
    }

    if (current < total) {
        html += '<li class="page-item"><a class="page-link" href="#" onclick="goToPage(' + (current + 1) + ')" title="' + i18n.pagina_successiva + '"><i class="fas fa-chevron-right"></i></a></li>';
    }

    html += '</ul></nav>';
    container.innerHTML = html;
}

function goToPage(page) {
    if (page === currentFilters.page) {
        return;
    }

    currentFilters.page = page;
    updateURL();
    loadBooks();
}

function updateFilterOptions(filterOptions, genreDisplay) {
    // Update genres using genre_display for correct hierarchy level
    if (genreDisplay && genreDisplay.genres) {
        const genresContainer = document.getElementById('genres-filter');
        if (genresContainer) {
            let html = '';

            // Add back button if not at level 0
            if (genreDisplay.level > 0) {
                const backValue = genreDisplay.level === 1 ? '' : (genreDisplay.parent?.nome || '');
                const backEscaped = backValue.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                html += '<div class="filter-back-container">';
                html += '<a href="#" class="filter-back-btn" onclick="updateFilter(\'genere\', \'' + backEscaped + '\'); return false;" title="' + i18n.torna_categoria_superiore + '">';
                html += '<i class="fas fa-arrow-left"></i>';
                html += '<span>' + i18n.torna_categoria_superiore + '</span>';
                html += '</a>';
                html += '</div>';
            }

            genreDisplay.genres.forEach(gen => {
                // Skip genres with 0 count
                if (!gen.cnt || gen.cnt <= 0) return;

                const isActive = currentFilters.genere === gen.nome ? 'active' : '';
                const escapedName = gen.nome.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                let displayName = gen.nome;

                // Shorten display name for lower levels
                if (genreDisplay.level > 0 && gen.nome.includes(' - ')) {
                    const parts = gen.nome.split(' - ');
                    displayName = parts[parts.length - 1];
                }

                // Sanitize title attribute to prevent XSS (escape HTML entities including double quotes)
                const safeTitle = escapeHtml(gen.nome).replace(/"/g, '&quot;');

                html += '<a href="#" class="filter-option count ' + isActive + '" onclick="updateFilter(\'genere\', \'' + escapedName + '\'); return false;" title="' + safeTitle + '">';
                html += '<span>' + escapeHtml(displayName) + '</span>';
                html += '<span class="count-badge">' + gen.cnt + '</span>';
                html += '</a>';
            });
            genresContainer.innerHTML = html;
        }
    } else if (filterOptions.generi) {
        // Fallback: if genre_display not provided, use old method (for backwards compatibility)
        const genresContainer = document.getElementById('genres-filter');
        if (genresContainer) {
            let html = '';
            filterOptions.generi.forEach(gen => {
                if (gen.cnt > 0) {
                    const isActive = currentFilters.genere === gen.nome ? 'active' : '';
                    const escapedName = gen.nome.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                    html += '<a href="#" class="filter-option count ' + isActive + '" onclick="updateFilter(\'genere\', \'' + escapedName + '\'); return false;">';
                    html += '<span>' + escapeHtml(gen.nome) + '</span>';
                    html += '<span class="count-badge">' + gen.cnt + '</span>';
                    html += '</a>';

                    // Add subgenres if present
                    if (gen.children && gen.children.length > 0) {
                        gen.children.forEach(subgen => {
                            if (subgen.cnt > 0) {
                                const isSubActive = currentFilters.genere === subgen.nome ? 'active' : '';
                                const escapedSubName = subgen.nome.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                                html += '<a href="#" class="filter-option subgenre count ' + isSubActive + '" onclick="updateFilter(\'genere\', \'' + escapedSubName + '\'); return false;">';
                                html += '<span>' + escapeHtml(subgen.nome) + '</span>';
                                html += '<span class="count-badge">' + subgen.cnt + '</span>';
                                html += '</a>';
                            }
                        });
                    }
                }
            });
            genresContainer.innerHTML = html;
        }
    }

    // Update publishers
    if (filterOptions.editori) {
        const publishersContainer = document.getElementById('publishers-filter');
        if (publishersContainer) {
            let html = '';
            filterOptions.editori.forEach(ed => {
                if (ed.cnt > 0) {
                    const isActive = currentFilters.editore === ed.nome ? 'active' : '';
                    const decodedName = decodeHtmlEntities(ed.nome);
                    const escapedName = ed.nome.replace(/'/g, "\\'");
                    html += '<a href="#" class="filter-option count ' + isActive + '" onclick="updateFilter(\'editore\', \'' + escapedName + '\'); return false;">';
                    html += '<span>' + escapeHtml(decodedName) + '</span>';
                    html += '<span class="count-badge">' + ed.cnt + '</span>';
                    html += '</a>';
                }
            });
            publishersContainer.innerHTML = html;
        }
    }

    // Update availability counts
    if (filterOptions.availability_stats) {
        const totalCount = document.getElementById('total-books-count');
        const availableCount = document.getElementById('available-books-count');
        const borrowedCount = document.getElementById('borrowed-books-count');

        if (totalCount) totalCount.textContent = filterOptions.availability_stats.total.toLocaleString();
        if (availableCount) availableCount.textContent = filterOptions.availability_stats.available.toLocaleString();
        if (borrowedCount) borrowedCount.textContent = filterOptions.availability_stats.borrowed.toLocaleString();
        syncAvailabilityActiveState();
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function decodeHtmlEntities(text) {
    const textarea = document.createElement('textarea');
    textarea.innerHTML = text;
    return textarea.value;
}

function updateYearRange(updateFilters = true) {
    const minSlider = document.getElementById('year-min');
    const maxSlider = document.getElementById('year-max');
    const minValue = document.getElementById('year-min-value');
    const maxValue = document.getElementById('year-max-value');
    const track = document.getElementById('year-track');

    if (!minSlider || !maxSlider || !minValue || !maxValue || !track) {
        return;
    }

    let min = parseInt(minSlider.value, 10);
    let max = parseInt(maxSlider.value, 10);

    if (min > max) {
        if (typeof event !== 'undefined' && event.target === minSlider) {
            max = min;
            maxSlider.value = max;
        } else {
            min = max;
            minSlider.value = min;
        }
    }

    minValue.textContent = min;
    maxValue.textContent = max;

    const minPercent = ((min - 1900) / (CURRENT_YEAR - 1900)) * 100;
    const maxPercent = ((max - 1900) / (CURRENT_YEAR - 1900)) * 100;

    track.style.left = minPercent + '%';
    track.style.width = (maxPercent - minPercent) + '%';

    if (updateFilters) {
        if (min !== 1900) {
            currentFilters.anno_min = min.toString();
        } else {
            delete currentFilters.anno_min;
        }

        if (max !== CURRENT_YEAR) {
            currentFilters.anno_max = max.toString();
        } else {
            delete currentFilters.anno_max;
        }

        currentFilters.page = 1;
        updateActiveFiltersDisplay();
        updateURL();
        loadBooks();
    }
}

function resetYearRange() {
    const minSlider = document.getElementById('year-min');
    const maxSlider = document.getElementById('year-max');

    if (!minSlider || !maxSlider) {
        return;
    }

    minSlider.value = 1900;
    maxSlider.value = CURRENT_YEAR;

    delete currentFilters.anno_min;
    delete currentFilters.anno_max;

    updateYearRange();
    updateActiveFiltersDisplay();
    updateURL();
    loadBooks();
}

document.addEventListener('click', (e) => {
    if (e.target.tagName === 'A' && e.target.getAttribute('href') === '#') {
        e.preventDefault();
    }
});
</script>
JS;


$content = ob_get_clean();
include 'layout.php';

<?php
/** @var \Psr\Container\ContainerInterface $container */
/** @var array $genre_display */
/** @var array $filter_options */
/** @var ?int $total_books */
/** @var array<int, array<string, mixed>> $archiveResults */
/** @var array<int, array{label: string, url: string}> $externalSearchSuggestions */
/** @var string $searchTerm */
/** @var int $current_page */

use App\Support\HtmlHelper;

// Header texts are editable per language (Settings → CMS); the page title follows them.
$catalogHeader = $catalogHeader ?? [
    'title' => __(\App\Support\CatalogHeader::DEFAULT_TITLE),
    'subtitle' => __(\App\Support\CatalogHeader::DEFAULT_SUBTITLE),
];
$title = $catalogHeader['title'];
if (!isset($filters)) {
    $filters = [];
}

// SEO Variables
$searchQuery = $filters['search'] ?? '';
if ($searchQuery) {
    $sanitizedSearchQuery = htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8');
    // The catalogue is called what its header says, in the results title too.
    $seoTitle = __("Risultati per '%s' - %s", $sanitizedSearchQuery, $catalogHeader['title']);
    $seoDescription = __("Scopri tutti i libri che contengono '%s' nel nostro catalogo. Trova autori, titoli e argomenti correlati alla tua ricerca.", $sanitizedSearchQuery);
    // Internal search results must not enter the index (infinite query space,
    // thin/duplicate content); links are still followed toward the books.
    $seoRobots = 'noindex,follow';
} else {
    $seoTitle = $catalogHeader['title'];
    $seoDescription = __("Sfoglia il nostro catalogo completo di libri disponibili per il prestito. Filtra per categoria, autore, editore e anno di pubblicazione per trovare la tua prossima lettura.");
}
if (!empty($filters['autore'])) { $seoRobots = 'noindex,follow'; }
$catalogRoute = route_path('catalog');
$apiCatalogRoute = route_path('api_catalog');
$catalogBaseUrl = rtrim(HtmlHelper::getBaseUrl(), '/') . \App\Support\RouteTranslator::route('catalog');
$seoCanonical = $catalogBaseUrl;
if ((int) $current_page > 1) {
    // Paginated pages self-canonicalize (same policy as the archive pages): page
    // 2 is not a duplicate of page 1, so books reachable only on later pages stay
    // indexable instead of collapsing onto the first page's canonical.
    $seoCanonical .= '?page=' . (int) $current_page;
}
$seoImage = absoluteUrl('/uploads/copertine/placeholder.jpg');

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
        "url" => rtrim(HtmlHelper::getBaseUrl(), '/') . '/'
    ],
    "potentialAction" => [
        "@type" => "SearchAction",
        "target" => [
            "@type" => "EntryPoint",
            "urlTemplate" => $catalogBaseUrl . "?q={search_term_string}"
        ],
        "query-input" => "required name=search_term_string"
    ]
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

// The catalogue surface lives in public/assets/catalog-pages.css so other
// public listings (e.g. /emeroteca/articoli) can reuse it; the layout links it.
$catalogPageStyles = true;

// Faceted search: dynamic filter data with safe fallbacks (backend contract)
$facetSuppress = $filter_options['suppress'] ?? [];
$facetAutori = array_values($filter_options['autori'] ?? []);
$facetMediaTypes = array_values($filter_options['media_types'] ?? []);
$facetAnnoBounds = $filter_options['anno_bounds'] ?? [];
$yearMinBound = (int)($facetAnnoBounds['min'] ?? 1900);
$yearMaxBound = (int)($facetAnnoBounds['max'] ?? (int)date('Y'));
if ($yearMaxBound < $yearMinBound) {
    $yearMaxBound = $yearMinBound;
}
$selectedAutoreId = (int)($filters['autore_id'] ?? 0);
$hideEditoreSection = !empty($facetSuppress['editore']) && ($filters['editore'] ?? '') === '';
$hideAutoreSection = !empty($facetSuppress['autore']) && $selectedAutoreId <= 0;
$hideMediaSection = !empty($facetSuppress['tipo_media']) && (string)($filters['tipo_media'] ?? '') === '';
$hideAnnoSection = !empty($facetSuppress['anno']) && empty($filters['anno_min']) && empty($filters['anno_max']);
$annoMinValue = max($yearMinBound, min($yearMaxBound, (int)($filters['anno_min'] ?? $yearMinBound)));
$annoMaxValue = max($yearMinBound, min($yearMaxBound, (int)($filters['anno_max'] ?? $yearMaxBound)));
if ($annoMaxValue < $annoMinValue) {
    $annoMaxValue = $annoMinValue;
}

ob_start();
?>

<!-- Catalog Header -->
<section class="catalog-header pk-catalog-head">
    <div class="pk-wrap">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb pk-crumbs">
                <li class="breadcrumb-item"><a href="<?= htmlspecialchars(url('/'), ENT_QUOTES, 'UTF-8') ?>"><?= __("Home") ?></a></li>
                <li class="pk-crumbs__sep" aria-hidden="true">/</li>
                <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($catalogHeader['title'], ENT_QUOTES, 'UTF-8') ?></li>
            </ol>
        </nav>
        <div class="catalog-header-content pk-page-head">
            <div class="pk-page-head__text">
                <h1 class="catalog-title pk-h1"><?= htmlspecialchars($catalogHeader['title'], ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="catalog-subtitle pk-lead"><?= htmlspecialchars($catalogHeader['subtitle'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>
    </div>
</section>

<!-- Main Content -->
<section class="pk-catalog">
    <div class="pk-wrap">
        <div class="pk-catalog__layout">
            <!-- Enhanced Filters Sidebar -->
            <aside class="catalog-filters-column pk-filters" aria-label="<?= htmlspecialchars(__("Filtri"), ENT_QUOTES, 'UTF-8') ?>">
                <div class="filters-panel">
                    <div class="filters-header">
                        <h5 class="filters-title">
                            <i class="fas fa-filter"></i>
                            <?= __("Filtri") ?>
                        </h5>
                        <button type="button"
                                class="filters-mobile-toggle"
                                id="catalog-filters-toggle"
                                aria-controls="catalog-filters-content"
                                aria-expanded="true"
                                aria-label="<?= htmlspecialchars(__("Filtri"), ENT_QUOTES, 'UTF-8') ?>">
                            <i class="fas fa-chevron-down" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div class="filters-content" id="catalog-filters-content">
                    <!-- Clear All, also at the top: no scrolling down a long filter column to reset it -->
                    <div class="filter-section filter-section--clear-top">
                        <button class="clear-all-btn" onclick="clearAllFilters()">
                            <i class="fas fa-times"></i>
                            <?= __("Pulisci tutti i filtri") ?>
                        </button>
                    </div>

                    <!-- Availability -->
                    <div class="filter-section">
                        <div class="filter-title">
                            <i class="fas fa-bookmark"></i>
                            <?= __("Disponibilità") ?>
                        </div>
                        <div class="availability-options" role="group" aria-label="<?= htmlspecialchars(__("Disponibilità"), ENT_QUOTES, 'UTF-8') ?>">
                        <button type="button" class="availability-option <?= empty($filters['disponibilita']) ? 'active' : '' ?>"
                             data-filter-value=""
                             aria-pressed="<?= empty($filters['disponibilita']) ? 'true' : 'false' ?>"
                             onclick="updateFilter('disponibilita', '')">
                                <span class="availability-icon" aria-hidden="true">
                                    <i class="fas fa-th-large"></i>
                                </span>
                                <span class="availability-text">
                                    <span class="availability-title"><?= __("Tutti") ?></span>
                                    <span class="availability-desc"><?= __("Tutto il catalogo") ?></span>
                                </span>
                                <span class="availability-count" id="total-books-count">
                                    <?= number_format($filter_options['availability_stats']['total'] ?? $total_books) ?>
                                </span>
                            </button>

                        <button type="button" class="availability-option <?= ($filters['disponibilita'] ?? '') === 'disponibile' ? 'active' : '' ?>"
                             data-filter-value="disponibile"
                             aria-pressed="<?= ($filters['disponibilita'] ?? '') === 'disponibile' ? 'true' : 'false' ?>"
                             onclick="updateFilter('disponibilita', 'disponibile')">
                                <span class="availability-icon" aria-hidden="true">
                                    <i class="fas fa-check-circle"></i>
                                </span>
                                <span class="availability-text">
                                    <span class="availability-title"><?= __("Disponibili") ?></span>
                                    <span class="availability-desc"><?= __("Pronti per il prestito") ?></span>
                                </span>
                                <span class="availability-count" id="available-books-count">
                                    <?= number_format($filter_options['availability_stats']['available'] ?? 0) ?>
                                </span>
                            </button>

                        <button type="button" class="availability-option <?= ($filters['disponibilita'] ?? '') === 'prenotato' ? 'active' : '' ?>"
                             data-filter-value="prenotato"
                             aria-pressed="<?= ($filters['disponibilita'] ?? '') === 'prenotato' ? 'true' : 'false' ?>"
                             onclick="updateFilter('disponibilita', 'prenotato')">
                                <span class="availability-icon" aria-hidden="true">
                                    <i class="fas fa-bookmark"></i>
                                </span>
                                <span class="availability-text">
                                    <span class="availability-title"><?= __("Prenotati") ?></span>
                                    <span class="availability-desc"><?= __("Attualmente riservati") ?></span>
                                </span>
                                <span class="availability-count" id="reserved-books-count">
                                    <?= number_format($filter_options['availability_stats']['reserved'] ?? 0) ?>
                                </span>
                            </button>

                        <button type="button" class="availability-option <?= ($filters['disponibilita'] ?? '') === 'prestato' ? 'active' : '' ?>"
                             data-filter-value="prestato"
                             aria-pressed="<?= ($filters['disponibilita'] ?? '') === 'prestato' ? 'true' : 'false' ?>"
                             onclick="updateFilter('disponibilita', 'prestato')">
                                <span class="availability-icon" aria-hidden="true">
                                    <i class="fas fa-clock"></i>
                                </span>
                                <span class="availability-text">
                                    <span class="availability-title"><?= __("In prestito") ?></span>
                                    <span class="availability-desc"><?= __("Attualmente prestati") ?></span>
                                </span>
                                <span class="availability-count" id="borrowed-books-count">
                                    <?= number_format($filter_options['availability_stats']['borrowed'] ?? 0) ?>
                                </span>
                            </button>
                        </div>
                    </div>

                    <!-- Search -->
                        <div class="filter-section">
                        <div class="filter-title">
                            <i class="fas fa-search"></i>
                            <?= __("Ricerca") ?>
                        </div>
                        <div class="search-box">
                            <input type="text"
                                   id="search-input"
                                   placeholder="<?= htmlspecialchars(__("Cerca titoli, autori, ISBN..."), ENT_QUOTES, 'UTF-8') ?>"
                                   value="<?= htmlspecialchars($filters['search'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   onkeyup="debounceSearch(this.value)">
                            <svg class="svg-inline--fa fa-magnifying-glass" data-prefix="fas" data-icon="magnifying-glass" role="img" viewBox="0 0 512 512" aria-hidden="true">
                                <path fill="currentColor" d="M416 208c0 45.9-14.9 88.3-40 122.7L502.6 457.4c12.5 12.5 12.5 32.8 0 45.3s-32.8 12.5-45.3 0L330.7 376C296.3 401.1 253.9 416 208 416 93.1 416 0 322.9 0 208S93.1 0 208 0 416 93.1 416 208zM208 352a144 144 0 1 0 0-288 144 144 0 1 0 0 288z"></path>
                            </svg>
                        </div>
                    </div>

                    <!-- Authors -->
                    <div class="filter-section" id="author-filter-section"<?= $hideAutoreSection ? ' style="display:none"' : '' ?>>
                        <div class="filter-title">
                            <i class="fas fa-feather"></i>
                            <?= __("Autori") ?>
                        </div>
                        <div class="pk-filter-search">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                            <input type="search" data-pk-filter-list="authors-filter" placeholder="<?= htmlspecialchars(__("Cerca autore…"), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(__("Cerca autore…"), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="filter-options" id="authors-filter">
                            <?php foreach($facetAutori as $autore): ?>
                                <a href="#"
                                   class="filter-option count <?= $selectedAutoreId === (int)$autore['id'] ? 'active' : '' ?>"
                                   onclick="updateFilter('autore_id', <?= (int)$autore['id'] ?>); return false;"
                                   title="<?= htmlspecialchars((string)$autore['nome'], ENT_QUOTES, 'UTF-8') ?>">
                                    <span><?= htmlspecialchars((string)$autore['nome'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="count-badge"><?= (int)$autore['cnt'] ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <div class="pk-filter-total" data-pk-count-of="authors-filter" data-pk-count-label="<?= htmlspecialchars(__('%d autori'), ENT_QUOTES, 'UTF-8') ?>" data-pk-count-label-one="<?= htmlspecialchars(__('%d autore'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(__n('%d autore', '%d autori', count($facetAutori)), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>

                    <!-- Publishers -->
                    <div class="filter-section" id="publisher-filter-section"<?= $hideEditoreSection ? ' style="display:none"' : '' ?>>
                        <div class="filter-title">
                            <i class="fas fa-building"></i>
                            <?= __("Editori") ?>
                        </div>
                        <div class="filter-options" id="publishers-filter">
                            <?php foreach($filter_options['editori'] as $editore): ?>
                                <a href="#"
                                   class="filter-option count <?= ($filters['editore'] ?? '') == $editore['nome'] ? 'active' : '' ?>"
                                   onclick="updateFilter('editore', <?= htmlspecialchars(json_encode($editore['nome'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>); return false;">
                                    <span><?= htmlspecialchars(html_entity_decode($editore['nome'], ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?></span>
                                    <span class="count-badge"><?= $editore['cnt'] ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Genres -->
                    <div class="filter-section" id="genre-filter-section"<?= !empty($facetSuppress['genere']) && empty($filters['genere_id']) ? ' style="display:none;"' : '' ?>>
                        <div class="filter-title">
                            <i class="fas fa-tags"></i>
                            <?= __("Generi") ?>
                        </div>
                        <div class="filter-options" id="genres-filter">
                            <?php if($genre_display['level'] > 0): ?>
                            <div class="filter-back-container">
                                <a href="#" class="filter-back-btn" onclick="updateFilter('genere_id', <?= $genre_display['level'] === 1 ? 0 : (int)($genre_display['parent']['id'] ?? 0) ?>); return false;" title="<?= htmlspecialchars(__("Torna alla categoria superiore"), ENT_QUOTES, 'UTF-8') ?>">
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
                                       onclick="updateFilter('genere_id', <?= (int)$genere['id'] ?>); return false;"
                                       title="<?= htmlspecialchars($genere['nome'], ENT_QUOTES, 'UTF-8') ?>">
                                        <span><?= htmlspecialchars($genere['nome'], ENT_QUOTES, 'UTF-8') ?></span>
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
                                       onclick="updateFilter('genere_id', <?= (int)$genere['id'] ?>); return false;"
                                       title="<?= htmlspecialchars($genere['nome'], ENT_QUOTES, 'UTF-8') ?>">
                                        <span><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="count-badge"><?= $genere['cnt'] ?></span>
                                    </a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Media Type (dynamic from backend media_types) -->
                    <div class="filter-section" id="media-filter-section"<?= $hideMediaSection ? ' style="display:none"' : '' ?>>
                        <div class="filter-title">
                            <i class="fas fa-compact-disc"></i>
                            <?= __("Tipo Media") ?>
                        </div>
                        <div class="filter-options" id="media-types-filter">
                          <?php
                          $currentTipo = (string)($filters['tipo_media'] ?? '');
                          foreach ($facetMediaTypes as $mt):
                            $mtValue = (string)($mt['value'] ?? '');
                            $mtLabel = (string)($mt['label'] ?? $mtValue);
                            $mtIcon = (string)($mt['icon'] ?? '');
                            if (!preg_match('/^fa-[a-z0-9-]+$/i', $mtIcon)) {
                                $mtIcon = 'fa-circle';
                            }
                            $mtCnt = (int)($mt['cnt'] ?? 0);
                            $isActive = $currentTipo !== '' && $currentTipo === $mtValue;
                          ?>
                            <a href="#"
                               class="filter-option count <?= $isActive ? 'active' : '' ?>"
                               onclick="updateFilter('tipo_media', <?= htmlspecialchars(json_encode($mtValue, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>); return false;"
                               title="<?= htmlspecialchars($mtLabel, ENT_QUOTES, 'UTF-8') ?>">
                              <span><i class="fas <?= htmlspecialchars($mtIcon, ENT_QUOTES, 'UTF-8') ?> mr-1"></i><?= htmlspecialchars($mtLabel, ENT_QUOTES, 'UTF-8') ?></span>
                              <span class="count-badge"><?= $mtCnt ?></span>
                            </a>
                          <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Year Range (dynamic bounds from backend anno_bounds) -->
                    <div class="filter-section" id="year-filter-section"<?= $hideAnnoSection ? ' style="display:none"' : '' ?>>
                        <div class="filter-title">
                            <i class="fas fa-calendar-alt"></i>
                            <?= __("Anno di pubblicazione") ?>
                        </div>
                        <div class="year-range">
                            <div class="year-range-label">
                                <span id="year-bound-min-label"><?= $yearMinBound ?></span>
                                <span id="year-bound-max-label"><?= $yearMaxBound ?></span>
                            </div>
                            <div class="year-slider-container">
                                <div class="year-slider-track" id="year-track"></div>
                                <input type="range"
                                       id="year-min"
                                       class="year-slider"
                                       min="<?= $yearMinBound ?>"
                                       max="<?= $yearMaxBound ?>"
                                       value="<?= $annoMinValue ?>"
                                       aria-label="<?= htmlspecialchars(__("Anno minimo"), ENT_QUOTES, 'UTF-8') ?>"
                                       oninput="updateYearRange(false, this)"
                                       onchange="updateYearRange(true, this)">
                                <input type="range"
                                       id="year-max"
                                       class="year-slider"
                                       min="<?= $yearMinBound ?>"
                                       max="<?= $yearMaxBound ?>"
                                       value="<?= $annoMaxValue ?>"
                                       aria-label="<?= htmlspecialchars(__("Anno massimo"), ENT_QUOTES, 'UTF-8') ?>"
                                       oninput="updateYearRange(false, this)"
                                       onchange="updateYearRange(true, this)">
                            </div>
                            <div class="year-values">
                                <span class="year-value" id="year-min-value"><?= $annoMinValue ?></span>
                                <button type="button" class="year-reset" onclick="resetYearRange()" title="<?= htmlspecialchars(__("Reset anni"), ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="fas fa-undo"></i>
                                </button>
                                <span class="year-value" id="year-max-value"><?= $annoMaxValue ?></span>
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
            </aside>

            <!-- Main Content -->
            <div class="catalog-results-column pk-results">
                <!-- Active Filters Display -->
                <div id="active-filters" class="active-filters" style="display: none;">
                    <div class="active-filters-title"><?= __("Filtri attivi:") ?></div>
                    <div class="filter-tags" id="active-filters-list"></div>
                </div>

                <!-- Results Header -->
                <div class="results-header">
                    <div class="results-info">
                        <strong id="total-count"><?= number_format($total_books) ?></strong>
                        <span id="results-text"><?= ($total_articles ?? 0) > 0 ? __('Risultati') : ($total_books == 1 ? __('libro trovato') : __('libri trovati')) ?></span>
                    </div>
                    <div class="pk-results__tools">
                        <button class="clear-filters-top-btn" onclick="clearAllFilters()" title="<?= htmlspecialchars(__("Rimuovi tutti i filtri"), ENT_QUOTES, 'UTF-8') ?>">
                            <i class="fas fa-filter-circle-xmark"></i>
                            <span class="clear-filters-text"><?= __("Pulisci filtri") ?></span>
                        </button>
                        <select class="sort-select" onchange="updateFilter('sort', this.value)" id="sort-select"
                            aria-label="<?= htmlspecialchars(__('Ordina per'), ENT_QUOTES, 'UTF-8') ?>">
                            <option value="newest" <?= ($filters['sort'] ?? 'newest') === 'newest' ? 'selected' : '' ?>><?= __("Più recenti") ?></option>
                            <option value="oldest" <?= ($filters['sort'] ?? 'newest') === 'oldest' ? 'selected' : '' ?>><?= __("Più vecchi") ?></option>
                            <option value="title_asc" <?= ($filters['sort'] ?? 'newest') === 'title_asc' ? 'selected' : '' ?>><?= __("Titolo A-Z") ?></option>
                            <option value="title_desc" <?= ($filters['sort'] ?? 'newest') === 'title_desc' ? 'selected' : '' ?>><?= __("Titolo Z-A") ?></option>
                            <option value="author_asc" <?= ($filters['sort'] ?? 'newest') === 'author_asc' ? 'selected' : '' ?>><?= __("Autore A-Z") ?></option>
                            <option value="author_desc" <?= ($filters['sort'] ?? 'newest') === 'author_desc' ? 'selected' : '' ?>><?= __("Autore Z-A") ?></option>
                        </select>
                        <div class="pk-view" role="group" aria-label="<?= htmlspecialchars(__("Visualizzazione"), ENT_QUOTES, 'UTF-8') ?>">
                            <button type="button" class="pk-view__btn is-active" data-pk-view="grid" aria-pressed="true"><?= __("Griglia") ?></button>
                            <button type="button" class="pk-view__btn" data-pk-view="list" aria-pressed="false"><?= __("Lista") ?></button>
                        </div>
                    </div>
                </div>

                <?php // Federated-search hint from plugins (empty = nothing rendered). ?>
                <?php include __DIR__ . '/partials/search-external-suggestions.php'; ?>

                <!-- Books Grid -->
                <div id="books-container">
                    <div class="books-grid pk-grid pk-grid--catalog" id="books-grid">
                        <?php include 'catalog-grid.php'; ?>
                    </div>

                    <!-- Loading State -->
                    <div id="loading-state" style="display: none;" class="text-center py-5">
                        <div class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-r-transparent text-gray-900" role="status">
                            <span class="sr-only"><?= __("Caricamento...") ?></span>
                        </div>
                    </div>

                    <!-- Empty State -->
                    <div id="empty-state" style="display: none;" class="empty-state">
                        <i class="fas fa-search empty-state-icon"></i>
                        <h4 class="empty-state-title"><?= __("Nessun risultato trovato") ?></h4>
                        <p class="empty-state-text"><?= __("Prova a modificare i filtri o la tua ricerca") ?></p>
                        <button type="button" class="btn-cta btn-cta-sm" onclick="clearAllFilters()">
                            <i class="fas fa-redo mr-2"></i>
                            <?= __("Pulisci filtri") ?>
                        </button>
                    </div>

                    <?php // FIX F014: only show archive fallback when book results are empty, keep as sibling of #empty-state ?>
                    <?php if (!empty($archiveResults) && empty($books)): ?>
                    <?php $e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); ?>
                    <div class="mt-4 p-3 rounded border" style="background:var(--light-bg,#f8f9fa);border-color:var(--border-color,#e5e7eb)!important;">
                        <p class="text-sm font-semibold text-gray-500 mb-2">
                            <i class="fas fa-archive mr-1"></i>
                            <?= __("Trovato anche nell'archivio:") ?>
                        </p>
                        <ul class="mb-0 list-none">
                            <?php foreach ($archiveResults as $ar): ?>
                            <li class="mb-1">
                                <?php
                                $rawHref = (string) ($ar['url'] ?? '');
                                // FIX F015: allow standard URL chars (query, fragment, percent-encoded)
                                // but reject schemes (javascript:/data:) and CRLF injection by requiring
                                // a leading slash and disallowing control characters.
                                if (!preg_match('{^/[\w/\-.~%?&=:;,@!$\'()*+\[\]#]*$}', $rawHref)) {
                                    $rawHref = '#';
                                }
                                ?>
                                <a href="<?= htmlspecialchars($rawHref, ENT_QUOTES, 'UTF-8') ?>" class="no-underline">
                                    <?= $e($ar['label']) ?>
                                    <?php if (($ar['reference_code'] ?? '') !== ''): ?>
                                        <span class="text-gray-500 text-sm ml-1">(<?= $e($ar['reference_code']) ?>)</span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Pagination: server-rendered with real hrefs so page 2+ is
                     crawlable without JavaScript; the JS re-render keeps the
                     same href + goToPage() enhancement on filter changes. -->
                <div id="pagination-container" class="mt-4">
                    <?php
                    $srCurrentPage = max(1, (int) $current_page);
                    $srTotalPages = max(1, (int)($total_pages ?? 1));
                    $srPageUrl = static function (int $p): string {
                        $qs = $_GET;
                        $qs['page'] = $p;
                        return '?' . http_build_query($qs);
                    };
                    if ($srTotalPages > 1):
                        $srStart = max(1, $srCurrentPage - 2);
                        $srEnd = min($srTotalPages, $srStart + 4);
                        $srStart = max(1, $srEnd - 4);
                    ?>
                    <nav aria-label="<?= htmlspecialchars(__('Navigazione pagine'), ENT_QUOTES, 'UTF-8') ?>"><ul class="pagination justify-center">
                        <?php if ($srCurrentPage > 1): ?>
                            <li class="page-item"><a class="page-link" href="<?= htmlspecialchars($srPageUrl($srCurrentPage - 1), ENT_QUOTES, 'UTF-8') ?>" onclick="goToPage(<?= $srCurrentPage - 1 ?>); return false;" title="<?= htmlspecialchars(__('Pagina precedente'), ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-chevron-left"></i></a></li>
                        <?php endif; ?>
                        <?php for ($srI = $srStart; $srI <= $srEnd; $srI++): ?>
                            <li class="page-item<?= $srI === $srCurrentPage ? ' active' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($srPageUrl($srI), ENT_QUOTES, 'UTF-8') ?>" onclick="goToPage(<?= $srI ?>); return false;"><?= $srI ?></a></li>
                        <?php endfor; ?>
                        <?php if ($srCurrentPage < $srTotalPages): ?>
                            <li class="page-item"><a class="page-link" href="<?= htmlspecialchars($srPageUrl($srCurrentPage + 1), ENT_QUOTES, 'UTF-8') ?>" onclick="goToPage(<?= $srCurrentPage + 1 ?>); return false;" title="<?= htmlspecialchars(__('Pagina successiva'), ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-chevron-right"></i></a></li>
                        <?php endif; ?>
                    </ul></nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php
$initialPaginationConfig = [
    'current_page' => max(1, (int) $current_page),
    'total_pages' => max(1, (int)($total_pages ?? 1)),
    'total_books' => max(0, (int)($total_books ?? 0)),
];
$initialPaginationJson = json_encode($initialPaginationConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$currentYear = (int)date('Y');

// Create i18n translations object for JavaScript
$i18nTranslations = [
    // Filter labels
    'search' => __('Ricerca'),
    'genere_id' => __('Genere'),
    'editore' => __('Editore'),
    'disponibilita' => __('Disponibilità'),
    'anno_min' => __('Anno min'),
    'anno_max' => __('Anno max'),
    'sort' => __('Ordinamento'),
    'tipo_media' => __('Tipo Media'),
    'autore' => __('Autore'),
    'cambia' => __('Cambia'),

    // Sort labels
    'newest' => __('Più recenti'),
    'oldest' => __('Più vecchi'),
    'title_asc' => __('Titolo A-Z'),
    'title_desc' => __('Titolo Z-A'),
    'author_asc' => __('Autore A-Z'),
    'author_desc' => __('Autore Z-A'),

    // Status labels
    'disponibile' => __('Disponibile'),
    'prenotato' => __('Prenotato'),
    'in_prestito' => __('In prestito'),

    // Actions
    'rimuovi_filtro' => __('Rimuovi filtro'),
    'pagina_precedente' => __('Pagina precedente'),
    'pagina_successiva' => __('Pagina successiva'),
    'torna_categoria_superiore' => __('Torna alla categoria superiore'),

    // Plurals
    'libro_trovato' => __('libro trovato'),
    'libri_trovati' => __('libri trovati'),
    'risultati' => __('Risultati'),

    // Errors
    'errore_caricamento' => __('Errore nel caricamento. Riprova.')
];
$i18nJson = json_encode($i18nTranslations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
$catalogRouteJs = json_encode($catalogRoute, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
$apiCatalogRouteJs = json_encode($apiCatalogRoute, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
$currentGenreNameJs = json_encode(isset($genre_display['selectedGenre']) ? $genre_display['selectedGenre']['nome'] : '', JSON_HEX_TAG);
$facetJsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG;
$autoriJson = json_encode($facetAutori, $facetJsonFlags) ?: '[]';
$mediaTypesJson = json_encode($facetMediaTypes, $facetJsonFlags) ?: '[]';
$editoriJson = json_encode(array_values($filter_options['editori'] ?? []), $facetJsonFlags) ?: '[]';
$suppressJson = json_encode($facetSuppress, $facetJsonFlags) ?: '{}';

$additional_js = <<<JS
<script>
// Translations object (PHP-rendered for JavaScript)
const i18n = {$i18nJson};
const CATALOG_ROUTE = {$catalogRouteJs};
const API_CATALOG_ROUTE = {$apiCatalogRouteJs};

let currentFilters = {};
let searchTimeout;
let loadingTimeout;
let catalogRequestController = null;
let currentGenreName = {$currentGenreNameJs};
const CURRENT_YEAR = {$currentYear};

// Faceted search state (dynamic data from filter_options, refreshed on each AJAX response)
let YEAR_MIN = {$yearMinBound};
let YEAR_MAX = {$yearMaxBound};
let autoriData = {$autoriJson};
let mediaTypesData = {$mediaTypesJson};
let editoriData = {$editoriJson};
let suppressData = {$suppressJson};
const facetExpanded = {};       // key -> true when user clicked "Cambia" on a collapsed facet
const facetOptionsRender = {};  // key -> options content (HTML string or render function)

document.addEventListener('DOMContentLoaded', () => {
    const filtersToggle = document.getElementById('catalog-filters-toggle');
    const filtersContent = document.getElementById('catalog-filters-content');
    const mobileFilters = window.matchMedia('(max-width: 768px)');

    const syncMobileFilters = () => {
        if (!filtersToggle || !filtersContent) {
            return;
        }
        if (mobileFilters.matches) {
            filtersContent.hidden = filtersToggle.getAttribute('aria-expanded') !== 'true';
        } else {
            filtersContent.hidden = false;
            filtersToggle.setAttribute('aria-expanded', 'true');
        }
        // A hidden box has no height, so every measurement taken while the
        // panel was closed said "nothing below the fold". Revealing it is the
        // first moment the question can honestly be asked.
        markScrollableFacets();
    };

    if (filtersToggle && filtersContent) {
        if (mobileFilters.matches) {
            filtersToggle.setAttribute('aria-expanded', 'false');
        }
        filtersToggle.addEventListener('click', () => {
            const expanded = filtersToggle.getAttribute('aria-expanded') === 'true';
            filtersToggle.setAttribute('aria-expanded', String(!expanded));
            filtersContent.hidden = expanded;
            markScrollableFacets();
        });
        if (typeof mobileFilters.addEventListener === 'function') {
            mobileFilters.addEventListener('change', syncMobileFilters);
        } else {
            mobileFilters.addListener(syncMobileFilters);
        }
        syncMobileFilters();
    }

    const urlParams = new URLSearchParams(window.location.search);
    urlParams.forEach((value, key) => {
        if (!value) {
            return;
        }

        if (key === 'q') {
            currentFilters.search = value;
            const searchInput = document.getElementById('search-input');
            if (searchInput) {
                searchInput.value = value;
            }
        } else {
            currentFilters[key] = value;
        }
    });

    updateActiveFiltersDisplay();
    updateURL();
    applyYearBounds(null);

    // Apply collapse-on-select to the server-rendered genre list, then render the other facets
    const genresInit = document.getElementById('genres-filter');
    if (genresInit) {
        applyFacetCollapse(genresInit, 'genere_id', genereSelectedLabel(), genresInit.innerHTML);
    }
    renderFacets();

    // A narrower window changes how much of a list fits, so the cue has to be
    // recomputed. Debounced: resize fires continuously while dragging.
    let facetCueResizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(facetCueResizeTimer);
        facetCueResizeTimer = setTimeout(markScrollableFacets, 150);
    }, { passive: true });

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
    if (value && value !== '' && value !== 0) {
        currentFilters[key] = value;
    } else {
        delete currentFilters[key];
        if (key === 'genere_id') {
            currentGenreName = '';
        }
    }

    // A new selection (or a clear) always re-collapses that facet
    facetExpanded[key] = false;

    currentFilters.page = 1;
    if (key === 'disponibilita') {
        syncAvailabilityActiveState();
    }
    updateActiveFiltersDisplay();
    renderFacets();
    updateURL();
    loadBooks();
}

function syncAvailabilityActiveState() {
    const currentValue = currentFilters.disponibilita || '';
    const options = document.querySelectorAll('.availability-option');
    options.forEach(option => {
        const targetValue = option.dataset.filterValue || '';
        const isActive = targetValue === currentValue;
        option.classList.toggle('active', isActive);
        option.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
}

function clearAllFilters() {
    // Simply redirect to catalog without any query parameters
    // This will reload the page and show all filter options
    window.location.href = CATALOG_ROUTE;
}

function removeFilter(key) {
    delete currentFilters[key];
    if (key === 'genere_id') {
        currentGenreName = '';
    }
    facetExpanded[key] = false;
    currentFilters.page = 1;

    updateActiveFiltersDisplay();
    renderFacets();
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
        genere_id: i18n.genere_id,
        editore: i18n.editore,
        autore_id: i18n.autore,
        autore: i18n.autore,
        disponibilita: i18n.disponibilita,
        anno_min: i18n.anno_min,
        anno_max: i18n.anno_max,
        sort: i18n.sort,
        tipo_media: i18n.tipo_media,
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
            const availabilityLabels = {
                disponibile: i18n.disponibile,
                prenotato: i18n.prenotato,
                prestato: i18n.in_prestito,
            };
            displayValue = availabilityLabels[value] || value;
        } else if (filterKey === 'genere_id') {
            displayValue = currentGenreName || value;
        } else if (filterKey === 'autore_id') {
            displayValue = autoreSelectedLabel() || i18n.autore;
        } else if (filterKey === 'tipo_media') {
            displayValue = mediaSelectedLabel() || value;
        } else if (filterKey === 'editore') {
            displayValue = decodeHtmlEntities(String(value));
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

    if (catalogRequestController) {
        catalogRequestController.abort();
    }
    const requestController = new AbortController();
    catalogRequestController = requestController;

    container.classList.remove('fade-in');
    container.style.display = 'none';
    loading.style.display = 'block';
    empty.style.display = 'none';

    const params = new URLSearchParams(currentFilters);

    fetch(API_CATALOG_ROUTE + '?' + params.toString(), { signal: requestController.signal })
        .then((response) => {
            if (!response.ok) {
                throw new Error('Catalog request failed with status ' + response.status);
            }
            return response.json();
        })
        .then((data) => {
            if (catalogRequestController !== requestController) {
                return;
            }
            loading.style.display = 'none';

            const hasNoResults = !data.html || data.html.trim() === '';

            if (hasNoResults) {
                empty.style.display = 'block';
                container.style.display = 'none';
            } else {
                container.style.display = '';
                container.innerHTML = data.html;
                container.dispatchEvent(new Event('pinakes:catalog-grid-updated', { bubbles: true }));
            }

            const totalCount = document.getElementById('total-count');
            const resultsText = document.getElementById('results-text');
            if (totalCount && resultsText && data.pagination) {
                totalCount.textContent = data.pagination.total_books.toLocaleString();
                resultsText.textContent = data.pagination.total_articles > 0 ? i18n.risultati : (data.pagination.total_books === 1 ? i18n.libro_trovato : i18n.libri_trovati);
            }

            // Update filter options if provided
            if (data.filter_options) {
                if (data.genre_display && data.genre_display.selectedGenre) {
                    currentGenreName = data.genre_display.selectedGenre.nome;
                } else {
                    currentGenreName = '';
                }
                updateFilterOptions(data.filter_options, data.genre_display);
            }

            updatePagination(data.pagination);
        })
        .catch((error) => {
            if (error.name === 'AbortError' || catalogRequestController !== requestController) {
                return;
            }
            console.error('Error loading books:', error);
            loading.style.display = 'none';
            container.style.display = '';
            container.innerHTML = '<div class="w-full px-3"><div class="alert alert-error">' + i18n.errore_caricamento + '</div></div>';
        })
        .finally(() => {
            if (catalogRequestController === requestController) {
                catalogRequestController = null;
            }
        });
}

function buildPageHref(page) {
    // Real hrefs keep pagination crawlable; the URL always mirrors the active
    // filters because applyFilters() syncs them via history.replaceState.
    const params = new URLSearchParams(window.location.search);
    params.set('page', page);
    return '?' + params.toString();
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

    let html = '<nav aria-label="' + escapeHtml(window.__('Page navigation')) + '"><ul class="pagination justify-center">';

    if (current > 1) {
        html += '<li class="page-item"><a class="page-link" href="' + buildPageHref(current - 1) + '" onclick="goToPage(' + (current - 1) + '); return false;" title="' + i18n.pagina_precedente + '"><i class="fas fa-chevron-left"></i></a></li>';
    }

    const visiblePages = 5;
    let startPage = Math.max(1, current - Math.floor(visiblePages / 2));
    let endPage = startPage + visiblePages - 1;

    if (endPage > total) {
        endPage = total;
        startPage = Math.max(1, endPage - visiblePages + 1);
    }

    if (startPage > 1) {
        html += '<li class="page-item"><a class="page-link" href="' + buildPageHref(1) + '" onclick="goToPage(1); return false;">1</a></li>';
        if (startPage > 2) {
            html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }

    for (let i = startPage; i <= endPage; i += 1) {
        const activeClass = i === current ? ' active' : '';
        html += '<li class="page-item' + activeClass + '"><a class="page-link" href="' + buildPageHref(i) + '" onclick="goToPage(' + i + '); return false;">' + i + '</a></li>';
    }

    if (endPage < total) {
        if (endPage < total - 1) {
            html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        html += '<li class="page-item"><a class="page-link" href="' + buildPageHref(total) + '" onclick="goToPage(' + total + '); return false;">' + total + '</a></li>';
    }

    if (current < total) {
        html += '<li class="page-item"><a class="page-link" href="' + buildPageHref(current + 1) + '" onclick="goToPage(' + (current + 1) + '); return false;" title="' + i18n.pagina_successiva + '"><i class="fas fa-chevron-right"></i></a></li>';
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

    // Scroll to the top of the results so that, after clicking a page number at
    // the bottom of the list, the reader lands on the first book of the new page
    // instead of staying scrolled down (mobile and desktop alike). The
    // fixed-header offset is handled declaratively by
    // `.results-header { scroll-margin-top }`.
    const anchor = document.querySelector('.results-header') || document.getElementById('books-grid');
    if (anchor) {
        const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        anchor.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
    }
}

function updateFilterOptions(filterOptions, genreDisplay) {
    // Update genres using genre_display for correct hierarchy level
    if (genreDisplay && genreDisplay.genres) {
        const genresContainer = document.getElementById('genres-filter');
        if (genresContainer) {
            let html = '';

            // Add back button if not at level 0
            if (genreDisplay.level > 0) {
                const backId = genreDisplay.level === 1 ? 0 : (genreDisplay.parent?.id || 0);
                html += '<div class="filter-back-container">';
                html += '<a href="#" class="filter-back-btn" onclick="updateFilter(\'genere_id\', ' + backId + '); return false;" title="' + i18n.torna_categoria_superiore + '">';
                html += '<i class="fas fa-arrow-left"></i>';
                html += '<span>' + i18n.torna_categoria_superiore + '</span>';
                html += '</a>';
                html += '</div>';
            }

            genreDisplay.genres.forEach(gen => {
                // Skip genres with 0 count
                if ((gen.cnt ?? 0) <= 0) return;

                const isActive = parseInt(currentFilters.genere_id) === gen.id ? 'active' : '';
                let displayName = gen.nome;

                // Shorten display name for lower levels
                if (genreDisplay.level > 0 && gen.nome.includes(' - ')) {
                    const parts = gen.nome.split(' - ');
                    displayName = parts[parts.length - 1];
                }

                // Sanitize title attribute to prevent XSS
                const safeTitle = escapeHtml(gen.nome);

                html += '<a href="#" class="filter-option count ' + isActive + '" onclick="updateFilter(\'genere_id\', ' + gen.id + '); return false;" title="' + safeTitle + '">';
                html += '<span>' + escapeHtml(displayName) + '</span>';
                html += '<span class="count-badge">' + gen.cnt + '</span>';
                html += '</a>';
            });
            applyFacetCollapse(genresContainer, 'genere_id', genereSelectedLabel(), html);
        }
    } else if (filterOptions.generi) {
        // Fallback: if genre_display not provided, use old method (for backwards compatibility)
        const genresContainer = document.getElementById('genres-filter');
        if (genresContainer) {
            let html = '';
            filterOptions.generi.forEach(gen => {
                if ((gen.cnt ?? 0) > 0) {
                    const isActive = parseInt(currentFilters.genere_id) === gen.id ? 'active' : '';
                    html += '<a href="#" class="filter-option count ' + isActive + '" onclick="updateFilter(\'genere_id\', ' + gen.id + '); return false;">';
                    html += '<span>' + escapeHtml(gen.nome) + '</span>';
                    html += '<span class="count-badge">' + gen.cnt + '</span>';
                    html += '</a>';

                    // Add subgenres if present
                    if (gen.children && gen.children.length > 0) {
                        gen.children.forEach(subgen => {
                            if ((subgen.cnt ?? 0) > 0) {
                                const isSubActive = parseInt(currentFilters.genere_id) === subgen.id ? 'active' : '';
                                html += '<a href="#" class="filter-option subgenre count ' + isSubActive + '" onclick="updateFilter(\'genere_id\', ' + subgen.id + '); return false;">';
                                html += '<span>' + escapeHtml(subgen.nome) + '</span>';
                                html += '<span class="count-badge">' + subgen.cnt + '</span>';
                                html += '</a>';
                            }
                        });
                    }
                }
            });
            applyFacetCollapse(genresContainer, 'genere_id', genereSelectedLabel(), html);
        }
    }

    // Refresh facet data from the API response, then re-render all facets
    // (publishers/authors/media are built via DOM API — textContent only —
    // to avoid stored XSS from names containing quotes/HTML chars).
    if (filterOptions.editori) {
        editoriData = filterOptions.editori;
    }
    if (filterOptions.autori) {
        autoriData = filterOptions.autori;
    }
    if (filterOptions.media_types) {
        mediaTypesData = filterOptions.media_types;
    }
    if (filterOptions.suppress) {
        suppressData = filterOptions.suppress;
    }
    if (filterOptions.anno_bounds) {
        applyYearBounds(filterOptions.anno_bounds);
    }
    renderFacets();

    // Update availability counts
    if (filterOptions.availability_stats) {
        const totalCount = document.getElementById('total-books-count');
        const availableCount = document.getElementById('available-books-count');
        const reservedCount = document.getElementById('reserved-books-count');
        const borrowedCount = document.getElementById('borrowed-books-count');

        if (totalCount) totalCount.textContent = filterOptions.availability_stats.total.toLocaleString();
        if (availableCount) availableCount.textContent = filterOptions.availability_stats.available.toLocaleString();
        if (reservedCount && filterOptions.availability_stats.reserved != null) reservedCount.textContent = filterOptions.availability_stats.reserved.toLocaleString();
        if (borrowedCount) borrowedCount.textContent = filterOptions.availability_stats.borrowed.toLocaleString();
        syncAvailabilityActiveState();
    }
}

// ---------------------------------------------------------------------------
// Faceted search helpers: collapse-on-select, suppression, dynamic rendering
// ---------------------------------------------------------------------------

function facetLabel(key) {
    const labels = {
        editore: i18n.editore,
        autore_id: i18n.autore,
        autore: i18n.autore,
        genere_id: i18n.genere_id,
        tipo_media: i18n.tipo_media,
    };
    return labels[key] || key;
}

function editoreSelectedLabel() {
    return currentFilters.editore ? decodeHtmlEntities(String(currentFilters.editore)) : '';
}

function autoreSelectedLabel() {
    const id = parseInt(currentFilters.autore_id, 10);
    if (Number.isNaN(id)) {
        return '';
    }
    const found = (autoriData || []).find((au) => parseInt(au.id, 10) === id);
    return found ? String(found.nome) : i18n.autore;
}

function mediaSelectedLabel() {
    const value = String(currentFilters.tipo_media || '');
    if (value === '') {
        return '';
    }
    const found = (mediaTypesData || []).find((mt) => String(mt.value) === value);
    return found ? String(found.label) : value;
}

function genereSelectedLabel() {
    return currentGenreName || String(currentFilters.genere_id || '');
}

// Shared collapse-on-select: when the facet has a selected value, replace the
// option list with a compact pill ("Label: Value" + clear button) and a small
// "Cambia" link that re-expands the scoped options on demand.
// optionsContent is an HTML string (already escaped) OR a function(container).
function applyFacetCollapse(sectionEl, key, selectedLabel, optionsContent) {
    if (!sectionEl) {
        return;
    }
    facetOptionsRender[key] = optionsContent;

    const hasSelection = !!currentFilters[key];
    if (hasSelection && !facetExpanded[key]) {
        renderCollapsedPill(sectionEl, key, selectedLabel);
    } else {
        renderFacetOptions(sectionEl, key);
    }
}

function renderFacetOptions(container, key) {
    const content = facetOptionsRender[key];
    container.classList.remove('facet-is-collapsed'); // restore scroll box + border
    if (typeof content === 'function') {
        container.replaceChildren();
        content(container);
    } else {
        container.innerHTML = content || '';
    }
    container.classList.remove('facet-fade-in');
    void container.offsetWidth; // restart animation
    container.classList.add('facet-fade-in');
    // The cue belongs to this function, not only to the renderFacets() sweep.
    // Reopening a collapsed facet from its Cambia link comes through here and
    // nowhere else, and it can restore a list long enough to need the cue.
    markScrollableFacets();
}

function renderCollapsedPill(container, key, selectedLabel) {
    container.replaceChildren();
    container.classList.add('facet-is-collapsed'); // single pill: no scroll box / border

    const pill = document.createElement('div');
    pill.className = 'facet-collapsed';

    const labelSpan = document.createElement('span');
    labelSpan.className = 'facet-collapsed-label';
    labelSpan.textContent = facetLabel(key) + ': ' + (selectedLabel || '');
    labelSpan.title = String(selectedLabel || '');

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'facet-collapsed-remove';
    removeBtn.innerHTML = '&times;';
    removeBtn.title = i18n.rimuovi_filtro;
    removeBtn.setAttribute('aria-label', i18n.rimuovi_filtro);
    removeBtn.addEventListener('click', () => {
        facetExpanded[key] = false;
        updateFilter(key, key === 'genere_id' || key === 'autore_id' ? 0 : '');
    });

    pill.append(labelSpan, removeBtn);

    const changeLink = document.createElement('a');
    changeLink.href = '#';
    changeLink.className = 'facet-change-link';
    changeLink.textContent = i18n.cambia;
    changeLink.addEventListener('click', (e) => {
        e.preventDefault();
        facetExpanded[key] = true;
        renderFacetOptions(container, key);
    });

    container.append(pill, changeLink);
    container.classList.remove('facet-fade-in');
    void container.offsetWidth;
    container.classList.add('facet-fade-in');
}

function buildPublisherOptions(container) {
    (editoriData || []).forEach((ed) => {
        if ((ed.cnt ?? 0) <= 0) {
            return;
        }
        const decodedName = decodeHtmlEntities(String(ed.nome));
        const a = document.createElement('a');
        a.href = '#';
        a.className = 'filter-option count' + (currentFilters.editore === ed.nome ? ' active' : '');
        a.dataset.editore = ed.nome;
        a.title = decodedName;
        a.addEventListener('click', (e) => {
            e.preventDefault();
            updateFilter('editore', a.dataset.editore);
        });
        const labelSpan = document.createElement('span');
        labelSpan.textContent = decodedName;
        const countSpan = document.createElement('span');
        countSpan.className = 'count-badge';
        countSpan.textContent = String(ed.cnt);
        a.append(labelSpan, countSpan);
        container.append(a);
    });
}

function buildAuthorOptions(container) {
    (autoriData || []).forEach((au) => {
        if ((au.cnt ?? 0) <= 0) {
            return;
        }
        const id = parseInt(au.id, 10);
        const a = document.createElement('a');
        a.href = '#';
        a.className = 'filter-option count' + (parseInt(currentFilters.autore_id, 10) === id ? ' active' : '');
        a.title = String(au.nome);
        a.addEventListener('click', (e) => {
            e.preventDefault();
            updateFilter('autore_id', id);
        });
        const labelSpan = document.createElement('span');
        labelSpan.textContent = String(au.nome);
        const countSpan = document.createElement('span');
        countSpan.className = 'count-badge';
        countSpan.textContent = String(au.cnt);
        a.append(labelSpan, countSpan);
        container.append(a);
    });
}

function buildMediaTypeOptions(container) {
    (mediaTypesData || []).forEach((mt) => {
        if ((mt.cnt ?? 0) <= 0) {
            return;
        }
        const value = String(mt.value ?? '');
        const a = document.createElement('a');
        a.href = '#';
        a.className = 'filter-option count' + (String(currentFilters.tipo_media || '') === value && value !== '' ? ' active' : '');
        a.title = String(mt.label ?? value);
        a.addEventListener('click', (e) => {
            e.preventDefault();
            updateFilter('tipo_media', value);
        });
        const labelSpan = document.createElement('span');
        const icon = document.createElement('i');
        const iconName = /^fa-[a-z0-9-]+$/i.test(String(mt.icon || '')) ? String(mt.icon) : 'fa-circle';
        icon.className = 'fas ' + iconName;
        icon.style.marginRight = '0.25rem';
        labelSpan.append(icon, document.createTextNode(String(mt.label ?? value)));
        const countSpan = document.createElement('span');
        countSpan.className = 'count-badge';
        countSpan.textContent = String(mt.cnt);
        a.append(labelSpan, countSpan);
        container.append(a);
    });
}

// Re-render the editore/autore/tipo_media facets (collapse-aware) and apply
// suppression of noise facets. The genere facet is handled by
// updateFilterOptions (it depends on genre_display drill-down data).
function renderFacets() {
    const publishers = document.getElementById('publishers-filter');
    if (publishers) {
        applyFacetCollapse(publishers, 'editore', editoreSelectedLabel(), buildPublisherOptions);
    }
    const authors = document.getElementById('authors-filter');
    if (authors) {
        applyFacetCollapse(authors, 'autore_id', autoreSelectedLabel(), buildAuthorOptions);
    }
    const mediaTypes = document.getElementById('media-types-filter');
    if (mediaTypes) {
        applyFacetCollapse(mediaTypes, 'tipo_media', mediaSelectedLabel(), buildMediaTypeOptions);
    }
    applySuppression();
    markScrollableFacets();
}

// Which facet lists have something below the fold, right now.
//
// CSS cannot ask whether a box overflows, so the cue that tells a reader to
// scroll has to be set from here. It runs on load and after every facet
// re-render, because an AJAX refresh replaces the options and a list that was
// long can become short (or the reverse) without the page reloading.
function markScrollableFacets() {
    document.querySelectorAll('.filter-options').forEach((list) => {
        if (list.classList.contains('facet-is-collapsed')) {
            list.classList.remove('facet-has-more');
            return;
        }
        updateFacetOverflowCue(list);
        if (!list.dataset.scrollCueBound) {
            // Once per element, not once per render: renderFacets() runs on
            // every keystroke of the search box.
            list.dataset.scrollCueBound = '1';
            list.addEventListener('scroll', () => updateFacetOverflowCue(list), { passive: true });
        }
    });
}

function updateFacetOverflowCue(list) {
    // The 2px tolerance is for sub-pixel heights: a list scrolled fully to the
    // bottom can report a remainder of a fraction of a pixel, which would keep
    // the cue on for ever and teach the reader to ignore it.
    const more = list.scrollHeight - list.clientHeight - list.scrollTop > 2;
    list.classList.toggle('facet-has-more', more);
}

// Hide a whole facet section when the backend marks it as noise
// (<= 1 reachable value) AND it is not currently selected.
function applySuppression() {
    const sectionMap = {
        genere: 'genre-filter-section',
        editore: 'publisher-filter-section',
        autore: 'author-filter-section',
        tipo_media: 'media-filter-section',
        anno: 'year-filter-section',
    };
    const selectedMap = {
        genere: !!currentFilters.genere_id,
        editore: !!currentFilters.editore,
        autore: !!currentFilters.autore_id,
        tipo_media: !!currentFilters.tipo_media,
        anno: !!(currentFilters.anno_min || currentFilters.anno_max),
    };
    Object.keys(sectionMap).forEach((key) => {
        const section = document.getElementById(sectionMap[key]);
        if (!section) {
            return;
        }
        const hide = !!(suppressData && suppressData[key]) && !selectedMap[key];
        section.style.display = hide ? 'none' : '';
    });
}

// Clamp the year sliders to the real data range (anno_bounds) — never beyond.
function applyYearBounds(bounds) {
    if (bounds) {
        const boundMin = parseInt(bounds.min, 10);
        const boundMax = parseInt(bounds.max, 10);
        if (!Number.isNaN(boundMin)) {
            YEAR_MIN = boundMin;
        }
        if (!Number.isNaN(boundMax)) {
            YEAR_MAX = boundMax;
        }
    }
    if (YEAR_MAX < YEAR_MIN) {
        YEAR_MAX = YEAR_MIN;
    }

    const minSlider = document.getElementById('year-min');
    const maxSlider = document.getElementById('year-max');
    if (!minSlider || !maxSlider) {
        return;
    }

    minSlider.min = YEAR_MIN;
    minSlider.max = YEAR_MAX;
    maxSlider.min = YEAR_MIN;
    maxSlider.max = YEAR_MAX;

    const filterMin = parseInt(currentFilters.anno_min, 10);
    const filterMax = parseInt(currentFilters.anno_max, 10);
    minSlider.value = Number.isNaN(filterMin) ? YEAR_MIN : Math.min(Math.max(filterMin, YEAR_MIN), YEAR_MAX);
    maxSlider.value = Number.isNaN(filterMax) ? YEAR_MAX : Math.min(Math.max(filterMax, YEAR_MIN), YEAR_MAX);

    const minBoundLabel = document.getElementById('year-bound-min-label');
    const maxBoundLabel = document.getElementById('year-bound-max-label');
    if (minBoundLabel) minBoundLabel.textContent = YEAR_MIN;
    if (maxBoundLabel) maxBoundLabel.textContent = YEAR_MAX;

    updateYearRange(false);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function decodeHtmlEntities(text) {
    const textarea = document.createElement('textarea');
    textarea.innerHTML = text;
    return textarea.value;
}

function updateYearRange(updateFilters = true, changedSlider = null) {
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
        if (changedSlider === minSlider) {
            max = min;
            maxSlider.value = max;
        } else {
            min = max;
            minSlider.value = min;
        }
    }

    minValue.textContent = min;
    maxValue.textContent = max;

    const span = Math.max(1, YEAR_MAX - YEAR_MIN);
    const minPercent = ((min - YEAR_MIN) / span) * 100;
    const maxPercent = ((max - YEAR_MIN) / span) * 100;

    track.style.left = Math.max(0, minPercent) + '%';
    track.style.width = Math.max(0, maxPercent - minPercent) + '%';

    if (updateFilters) {
        if (min !== YEAR_MIN) {
            currentFilters.anno_min = min.toString();
        } else {
            delete currentFilters.anno_min;
        }

        if (max !== YEAR_MAX) {
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

    minSlider.value = YEAR_MIN;
    maxSlider.value = YEAR_MAX;

    delete currentFilters.anno_min;
    delete currentFilters.anno_max;

    updateYearRange(false);
    currentFilters.page = 1;
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

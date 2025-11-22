<?php
/**
 * Latest Books Section Template
 * Displays latest books with dynamic loading
 */
$latestBooksData = $section ?? [];
$legacyCatalogRoute = $legacyCatalogRoute ?? route_path('catalog_legacy');
?>

<!-- Latest Books Section -->
<section id="latest-books" class="section" data-section="latest_books_title">
    <div class="container">
        <h2 class="section-title"><?php echo htmlspecialchars($latestBooksData['title'] ?? __("Ultimi Libri Aggiunti"), ENT_QUOTES, 'UTF-8'); ?></h2>
        <p class="section-subtitle">
            <?php echo htmlspecialchars($latestBooksData['subtitle'] ?? __("Scopri le ultime novità della nostra collezione"), ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <div id="latest-books-grid">
            <div class="loading-placeholder">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden"><?= __("Caricamento...") ?></span>
                </div>
                <p class="mt-3"><?= __("Caricamento libri...") ?></p>
            </div>
        </div>
        <div class="text-center mt-5">
            <button id="load-more-latest" class="btn-cta me-3" style="display: none;" type="button">
                <i class="fas fa-plus"></i>
                <?= __("Carica Altri") ?>
            </button>
            <a href="<?= $legacyCatalogRoute ?>" class="btn-cta">
                <i class="fas fa-th-large"></i>
                <?= __("Visualizza Tutto il Catalogo") ?>
            </a>
        </div>
    </div>
</section>

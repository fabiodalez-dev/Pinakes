<?php
/**
 * Latest Books Section Template
 * Displays latest books with dynamic loading
 *
 * When FrontendController::home() has already fetched the first page of
 * latest books (cached server-side), render it inline: the visitor sees
 * content on first paint instead of a spinner + XHR round-trip. The
 * "load more" button keeps paging through /api/home/latest from page 2.
 */
$latestBooksData = $section ?? [];
$legacyCatalogRoute = $legacyCatalogRoute ?? route_path('catalog_legacy');
$latestBooksPrefetched = isset($latest_books) && is_array($latest_books) && $latest_books !== [];
$latestBooksTotal = (int) ($latestBooksTotal ?? 0);
$latestHasMore = $latestBooksPrefetched && $latestBooksTotal > count($latest_books);
?>

<!-- Latest Books Section -->
<section id="latest-books" class="section" data-section="latest_books_title">
    <div class="container">
        <h2 class="section-title"><?php echo htmlspecialchars($latestBooksData['title'] ?? __("Ultimi Libri Aggiunti"), ENT_QUOTES, 'UTF-8'); ?></h2>
        <p class="section-subtitle">
            <?php echo htmlspecialchars($latestBooksData['subtitle'] ?? __("Scopri le ultime novità della nostra collezione"), ENT_QUOTES, 'UTF-8'); ?>
        </p>
        <div id="latest-books-grid"<?= $latestBooksPrefetched ? ' data-server-rendered="1" data-has-more="' . ($latestHasMore ? '1' : '0') . '"' : '' ?>>
            <?php if ($latestBooksPrefetched): ?>
                <?php $books = $latest_books; include __DIR__ . '/../home-books-grid.php'; unset($books); ?>
            <?php else: ?>
            <div class="loading-placeholder">
                <div class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-r-transparent" role="status">
                    <span class="sr-only"><?= __("Caricamento...") ?></span>
                </div>
                <p class="mt-3"><?= __("Caricamento libri...") ?></p>
            </div>
            <?php endif; ?>
        </div>
        <div class="text-center mt-5">
            <button id="load-more-latest" class="btn-cta mr-3" style="display: <?= $latestHasMore ? 'inline-flex' : 'none' ?>;" type="button">
                <i class="fas fa-plus"></i>
                <?= __("Carica Altri") ?>
            </button>
            <a href="<?= htmlspecialchars($legacyCatalogRoute, ENT_QUOTES, 'UTF-8') ?>" class="btn-cta">
                <i class="fas fa-th-large"></i>
                <?= __("Visualizza Tutto il Catalogo") ?>
            </a>
        </div>
    </div>
</section>

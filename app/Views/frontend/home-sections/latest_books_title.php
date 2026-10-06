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
$catalogRoute = $catalogRoute ?? route_path('catalog');
$latestBooksPrefetched = isset($latest_books) && is_array($latest_books) && $latest_books !== [];
$latestBooksTotal = (int) ($latestBooksTotal ?? 0);
$latestHasMore = $latestBooksPrefetched && $latestBooksTotal > count($latest_books);
?>

<!-- Latest Books Section -->
<section id="latest-books" class="section pk-section" data-section="latest_books_title">
    <div class="pk-section-head">
        <div class="pk-section-head__text">
            <div class="pk-eyebrow"><?= __("Novità") ?></div>
            <h2 class="section-title pk-h2"><?php echo htmlspecialchars($latestBooksData['title'] ?? __("Ultimi Libri Aggiunti"), ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="section-subtitle pk-lead"><?php echo htmlspecialchars($latestBooksData['subtitle'] ?? __("Scopri le ultime novità della nostra collezione"), ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <a href="<?= htmlspecialchars($catalogRoute, ENT_QUOTES, 'UTF-8') ?>" class="pk-btn pk-btn--ghost"><i class="fas fa-th-large" aria-hidden="true"></i><?= __("Visualizza Tutto il Catalogo") ?> <span aria-hidden="true">→</span></a>
    </div>
    <div id="latest-books-grid" class="books-grid pk-grid"<?= $latestBooksPrefetched ? ' data-server-rendered="1" data-has-more="' . ($latestHasMore ? '1' : '0') . '"' : '' ?>>
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
    <div class="pk-more">
        <button id="load-more-latest" class="pk-btn pk-btn--dark" style="display: <?= $latestHasMore ? 'inline-flex' : 'none' ?>;" type="button"><i class="fas fa-plus" aria-hidden="true"></i><?= __("Carica Altri") ?></button>
    </div>
</section>

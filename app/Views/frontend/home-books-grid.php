<?php
/**
 * Home "latest books" grid: the server-rendered first page and the
 * /api/home/<section> "load more" fragments.
 *
 * Same card as the catalogue (catalog-grid.php): .book-card, .book-status-badge,
 * "Dettagli" as .btn-cta btn-cta-sm. It carries no CSS of its own: home.php
 * opts into the catalogue stylesheet (`$catalogPageStyles = true`) and the
 * grid container is a `.books-grid` (home-sections/latest_books_title.php).
 */
// Every card is the shared 2026 card (partials/pk-book-card.php), the same
// one the catalogue and the book page use.
?>
<?php if (!empty($books)): ?>
    <?php foreach ($books as $book): ?>
        <?php include __DIR__ . '/partials/pk-book-card.php'; ?>
    <?php endforeach; ?>
<?php else: ?>
    <div class="empty-state">
        <i class="fas fa-search empty-state-icon"></i>
        <h4 class="empty-state-title"><?= __("Nessun libro trovato") ?></h4>
        <p class="empty-state-text"><?= __("Prova a modificare i filtri o la tua ricerca") ?></p>
        <button type="button" class="btn-cta btn-cta-sm" onclick="clearAllFilters()">
            <i class="fas fa-redo mr-2"></i>
            <?= __("Pulisci filtri") ?>
        </button>
    </div>
<?php endif; ?>

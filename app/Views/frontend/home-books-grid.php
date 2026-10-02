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
use App\Support\HtmlHelper;
// Also require an actual LiteSpeed server: enabled() only checks config + the
// installed .htaccess bypass, so on Apache the client-hydrated "pending" badge
// would otherwise reach no-JS visitors and crawlers instead of the real status.
$edgeCacheEnabled = \App\Support\LiteSpeedCache::enabled() && \App\Support\LiteSpeedCache::serverDetected();

$createBookUrl = static function ($book) {
    return book_url($book);
};

$getBookStatusBadge = static function ($book) use ($edgeCacheEnabled) {
    ob_start();
    // Neutral, client-hydrated badge when the shared edge cache is on OR when
    // the server-side availability read failed (_availability_unknown) — never
    // fall through to a false "Non disponibile" on a missing availability field.
    if ($edgeCacheEnabled || !empty($book['_availability_unknown'])) {
        echo '<span class="book-status-badge availability-pending" data-live-book-id="' . (int) $book['id'] . '" data-live-role="badge" data-live-pending="1"><span data-live-label>'
            . htmlspecialchars(__("Verifica disponibilità"), ENT_QUOTES, 'UTF-8') . '</span>';
        $staticBook = $book;
        unset($staticBook['copie_disponibili'], $staticBook['copie_totali'], $staticBook['stato']);
        do_action('book.badge.digital_icons', $staticBook);
        echo '</span>';
        return ob_get_clean();
    }
    $available = ($book['copie_disponibili'] ?? 0) > 0;
    $stato = $book['stato'] ?? '';
    // "In prestito" is shown ONLY for stato='prestato'. Every other not-available
    // case — 'non_disponibile', or a stale/unexpected stato on a zero-copy book —
    // falls to "Non disponibile" rather than being mislabelled as on loan (#303 review).
    if ($available) {
        echo '<span class="book-status-badge status-available"><span data-live-label>' . htmlspecialchars(__("Disponibile"), ENT_QUOTES, 'UTF-8') . '</span>';
    } elseif ($stato === 'prenotato') {
        echo '<span class="book-status-badge status-reserved"><span data-live-label>' . htmlspecialchars(__("Prenotato"), ENT_QUOTES, 'UTF-8') . '</span>';
    } elseif ($stato === 'prestato') {
        echo '<span class="book-status-badge status-borrowed"><span data-live-label>' . htmlspecialchars(__("In prestito"), ENT_QUOTES, 'UTF-8') . '</span>';
    } else {
        echo '<span class="book-status-badge status-unavailable"><span data-live-label>' . htmlspecialchars(__("Non disponibile"), ENT_QUOTES, 'UTF-8') . '</span>';
    }
    // Hook: Allow plugins to add icons to status badge (e.g., eBook/audio icons)
    do_action('book.badge.digital_icons', $book);
    echo '</span>';
    return ob_get_clean();
};
?>
<?php $defaultCoverUrl = absoluteUrl('/uploads/copertine/placeholder.jpg'); ?>
<?php if (!empty($books)): ?>
    <?php foreach($books as $book): ?>
        <div class="book-card">
            <div class="book-image-container">
                <a href="<?= htmlspecialchars($createBookUrl($book), ENT_QUOTES, 'UTF-8') ?>">
                    <?php
                    $coverUrl = ($book['copertina_url'] ?? '') ?: '/uploads/copertine/placeholder.jpg';
                    $absoluteCoverUrl = absoluteUrl($coverUrl);
                    ?>
                    <img class="book-image"
                         src="<?= htmlspecialchars($absoluteCoverUrl, ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($book['titolo'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                         loading="lazy" decoding="async"
                         onerror="this.onerror=null;this.src=<?= htmlspecialchars(json_encode($defaultCoverUrl), ENT_QUOTES, 'UTF-8') ?>">
                </a>
                <?= $getBookStatusBadge($book) ?>
            </div>
            <div class="book-content">
                <h3 class="book-title">
                    <a href="<?= htmlspecialchars($createBookUrl($book), ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars(html_entity_decode($book['titolo'] ?? '', ENT_QUOTES, 'UTF-8')) ?>
                    </a>
                </h3>
                <?php if (!empty($book['sottotitolo'])): ?>
                    <p class="book-subtitle">
                        <?= htmlspecialchars(html_entity_decode($book['sottotitolo'], ENT_QUOTES, 'UTF-8')) ?>
                    </p>
                <?php endif; ?>
                <?php if (!empty($book['autore'])): ?>
                    <p class="book-author">
                        <?= htmlspecialchars(html_entity_decode($book['autore'], ENT_QUOTES, 'UTF-8')) ?>
                    </p>
                <?php else: ?>
                    <p class="book-author" style="visibility: hidden;">&nbsp;</p>
                <?php endif; ?>
                <?php if (!empty($book['editore'])): ?>
                    <p class="book-meta">
                        <span class="text-gray-500"><?= __("Editore:") ?></span>
                        <?= htmlspecialchars(html_entity_decode($book['editore'], ENT_QUOTES, 'UTF-8')) ?>
                    </p>
                <?php else: ?>
                    <p class="book-meta book-meta-empty" aria-hidden="true">&nbsp;</p>
                <?php endif; ?>
                <div class="book-actions">
                    <a href="<?= htmlspecialchars($createBookUrl($book), ENT_QUOTES, 'UTF-8') ?>" class="btn-cta btn-cta-sm">
                        <i class="fas fa-eye"></i>
                        <?= __("Dettagli") ?>
                    </a>
                </div>
            </div>
        </div>
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

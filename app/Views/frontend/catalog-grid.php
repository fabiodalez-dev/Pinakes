<?php
use App\Support\HtmlHelper;

$createBookUrl = static function ($book) {
    return book_url($book);
};

$getBookStatusBadge = static function ($book) {
    ob_start();
    $available = ($book['copie_disponibili'] ?? 0) > 0;
    $reserved = !$available && ($book['stato'] ?? '') === 'prenotato';
    if ($available) {
        echo '<span class="book-status-badge status-available">' . __("Disponibile");
    } elseif ($reserved) {
        echo '<span class="book-status-badge status-reserved">' . __("Prenotato");
    } else {
        echo '<span class="book-status-badge status-borrowed">' . __("In prestito");
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
        <div class="book-card fade-in">
            <div class="book-image-container">
                <a href="<?= htmlspecialchars($createBookUrl($book), ENT_QUOTES, 'UTF-8') ?>">
                    <?php
                    $coverUrl = ($book['copertina_url'] ?? '') ?: '/uploads/copertine/placeholder.jpg';
                    $absoluteCoverUrl = absoluteUrl($coverUrl);
                    ?>
                    <img class="book-image"
                         src="<?= htmlspecialchars($absoluteCoverUrl, ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($book['titolo'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
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
                <?php if (!empty($book['autore'])): ?>
                    <p class="book-author">
                        <?= htmlspecialchars(html_entity_decode($book['autore'], ENT_QUOTES, 'UTF-8')) ?>
                    </p>
                <?php else: ?>
                    <p class="book-author" style="visibility: hidden;">&nbsp;</p>
                <?php endif; ?>
                <?php if (!empty($book['editore'])): ?>
                    <p class="book-meta">
                        <span class="text-muted"><?= __("Editore:") ?></span>
                        <?= htmlspecialchars(html_entity_decode($book['editore'], ENT_QUOTES, 'UTF-8')) ?>
                    </p>
                <?php else: ?>
                    <p class="book-meta" style="visibility: hidden;">&nbsp;</p>
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
            <i class="fas fa-redo me-2"></i>
            <?= __("Pulisci filtri") ?>
        </button>
    </div>
<?php endif; ?>

<style>
/* Enhanced book card styling */
.book-card {
    display: flex;
    flex-direction: column;
    height: 100%;
    background: var(--bg-primary);
    border-radius: var(--radius-xl);
    overflow: hidden;
    box-shadow: none;
    border: 1px solid var(--border-color);
    transition: var(--transition);
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
    border-radius: var(--radius-xl) var(--radius-xl) 0 0;
}

.book-image {
    width: 100%;
    height: 100%;
    object-fit: contain;
    transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1), filter 0.3s ease;
    will-change: transform;
}

.book-card:hover .book-image {
    transform: scale(1.08) translateZ(0);
    filter: brightness(1.05);
}

.book-image-container::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(180deg, transparent 0%, rgba(0,0,0,0.1) 100%);
    opacity: 0;
    transition: opacity 0.3s ease;
    pointer-events: none;
}

.book-card:hover .book-image-container::after {
    opacity: 1;
}

.book-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    flex: 1;
    padding: 1.5rem;
}

.book-title {
    font-size: 1.125rem;
    font-weight: 700;
    line-height: 1.4;
    margin-bottom: 0.5rem;
    color: var(--text-primary);
    min-height: 2.8em;
}

.book-title a {
    color: inherit;
    text-decoration: none;
    transition: color 0.2s ease;
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

.book-meta {
    font-size: 0.75rem;
    color: var(--text-muted);
    line-height: 1.5;
    margin-bottom: auto;
    min-height: 1.5em;
}

.book-actions {
    margin-top: auto;
    display: flex;
    gap: 0.5rem;
    margin-top: auto;
    padding-top: 1rem;
}

.book-actions .btn-cta {
    width: 100%;
    justify-content: center;
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
    transition: transform 0.3s ease;
}

.book-card:hover .book-status-badge {
    transform: translateY(-2px);
}

.status-available {
    background: rgba(16, 185, 129, 0.9);
    color: white;
}

.status-borrowed {
    background: rgba(239, 68, 68, 0.9);
    color: white;
}

.status-reserved {
    background: rgba(245, 158, 11, 0.9);
    color: white;
}

/* Ensure consistent grid layout */
.books-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.5rem;
    align-items: stretch;
}

/* Hide empty elements but maintain spacing */
.book-author[style*="visibility: hidden"],
.book-meta[style*="visibility: hidden"] {
    visibility: hidden !important;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .book-content {
    flex: 1;
        padding: 1rem;
    }
    
    .book-title {
        font-size: 1rem;
        min-height: 2.4em;
    }
}

.empty-state .btn-cta {
    justify-content: center;
}
</style>

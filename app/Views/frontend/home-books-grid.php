<?php
use App\Support\HtmlHelper;

function createBookUrl($book) {
    return book_url($book);
}

function getBookStatusBadge($book) {
    ob_start();
    if (($book['copie_disponibili'] ?? 0) > 0) {
        echo '<span class="book-status-badge status-available">' . __("Disponibile");
    } else {
        echo '<span class="book-status-badge status-borrowed">' . __("In prestito");
    }
    // Hook: Allow plugins to add icons to status badge (e.g., eBook/audio icons)
    do_action('book.badge.digital_icons', $book);
    echo '</span>';
    return ob_get_clean();
}
?>
<?php if (!empty($books)): ?>
    <?php foreach($books as $book): ?>
        <div class="book-card fade-in">
            <div class="book-image-container">
                <a href="<?= createBookUrl($book) ?>">
                    <?php
                    $coverUrl = $book['copertina_url'] ?? '/uploads/copertine/placeholder.jpg';
                    $absoluteCoverUrl = (strpos($coverUrl, 'http') === 0) ? $coverUrl : HtmlHelper::getBaseUrl() . $coverUrl;
                    $defaultCoverUrl = HtmlHelper::getBaseUrl() . '/uploads/copertine/placeholder.jpg';
                    ?>
                    <img class="book-image"
                         src="<?= htmlspecialchars($absoluteCoverUrl) ?>"
                         alt="<?= htmlspecialchars($book['titolo'] ?? '') ?>"
                         onerror="this.src='<?= htmlspecialchars($defaultCoverUrl) ?>'">
                </a>
                <?= getBookStatusBadge($book) ?>
            </div>
            <div class="book-content">
                <h3 class="book-title">
                    <a href="<?= createBookUrl($book) ?>">
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
                    <a href="<?= createBookUrl($book) ?>" class="btn-cta btn-cta-sm">
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
/* Exact same styling as catalog.php */
:root {
    --dark-color: #111827;
    --dark-hover: #374151;
    --text-primary: #1f2937;
    --text-secondary: #6b7280;
    --text-muted: #9ca3af;
    --bg-primary: #ffffff;
    --bg-secondary: #f9fafb;
    --bg-tertiary: #f3f4f6;
    --border-color: #e5e7eb;
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
    --radius-md: 0.5rem;
    --radius-xl: 1rem;
    --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Enhanced book card styling identical to catalog */
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

/* Empty state styling */
.empty-state {
    grid-column: 1 / -1; /* Span full grid width */
    text-align: center;
    padding: 4rem 2rem;
    color: var(--text-secondary);
    max-width: 600px;
    margin: 0 auto; /* Center horizontally */
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

/* Responsive adjustments */
@media (max-width: 768px) {
    .book-content {
    flex: 1;
        padding: 1rem;
    }
    
    .book-title {
        font-size: 1rem;
    }
}

/* Grid layout for home page */
#latest-books-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.5rem;
}

/* Fade in animation */
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
</style>

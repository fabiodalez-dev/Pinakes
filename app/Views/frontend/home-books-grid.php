<?php
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

<style>
/* Exact same styling as catalog.php */
:root {
    --dark-color: var(--text-color);
    --dark-hover: #374151;
    --text-primary: var(--text-color);
    --text-secondary: var(--text-light);
    --text-muted: #64748b;
    --bg-primary: var(--white);
    --bg-secondary: var(--light-bg);
    --bg-tertiary: var(--accent-color);
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
    background: none;
    border-radius: 0;
    overflow: visible;
    box-shadow: none;
    border: none;
    transition: transform .5s cubic-bezier(.22,1,.36,1);
    position: relative;
}

.book-card:hover {
    transform: translateY(-6px);
    box-shadow: none;
}

.book-image-container {
    position: relative;
    aspect-ratio: 2/3;
    overflow: hidden;
    background: var(--light-bg);
    border-radius: 3px;
    box-shadow: 0 1px 3px rgba(15,23,42,.12);
}

.book-image {
    width: 100%;
    height: 100%;
    object-fit: contain;
    object-position: center;
    transition: var(--transition);
}

.book-card:hover .book-image {
    transform: scale(1.012);
}

.book-status-badge {
    position: absolute;
    top: 0.6rem;
    right: 0.6rem;
    padding: 0.28rem 0.55rem;
    border-radius: 2px;
    font-size: 0.65rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    backdrop-filter: none;
}

.status-available {
    background: var(--success-color); /* fallback for browsers without color-mix() */
    background: color-mix(in srgb, var(--success-color) 90%, transparent);
    color: white;
}

.status-borrowed {
    background: var(--danger-color); /* fallback for browsers without color-mix() */
    background: color-mix(in srgb, var(--danger-color) 90%, transparent);
    color: white;
}

.status-reserved {
    background: var(--warning-color); /* fallback for browsers without color-mix() */
    background: color-mix(in srgb, var(--warning-color) 90%, transparent);
    color: white;
}

.status-unavailable {
    /* Neutral grey — theme-agnostic, signals "not circulating" without alarm */
    background: #6b7280;
    color: white;
}

.availability-pending {
    background: #6b7280;
    color: white;
}

.book-content {
    flex: 1;
    padding: 0.9rem 0 0;
    display: flex;
    flex-direction: column;
    flex: 1;
}

.book-title {
    font-family: var(--serif);
    font-size: 1.15rem;
    font-weight: 460;
    line-height: 1.2;
    letter-spacing: -0.02em;
    margin-bottom: 0.3rem;
    color: var(--text-color);
}

.book-title a {
    color: inherit;
    text-decoration: none;
    transition: var(--transition);
}

.book-title a:hover {
    color: var(--dark-color);
}

.book-subtitle {
    font-size: 0.9rem;
    font-style: italic;
    line-height: 1.35;
    margin-top: -0.25rem;
    margin-bottom: 0.5rem;
    color: var(--text-secondary, var(--text-light));
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
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
    padding-top: 0.75rem;
}

/* Detail action becomes a quiet text link, not a big filled button. */
.book-actions .btn-cta {
    width: auto;
    justify-content: flex-start;
    background: none !important;
    color: var(--primary-color) !important;
    border: none !important;
    padding: 0 !important;
    font-size: 0.82rem;
    font-weight: 600;
    letter-spacing: 0.02em;
    box-shadow: none !important;
    gap: 0.4rem;
}
.book-actions .btn-cta:hover {
    background: none !important;
    color: var(--primary-color) !important;
    transform: none;
    gap: 0.6rem;
    transition: gap .3s cubic-bezier(.22,1,.36,1);
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

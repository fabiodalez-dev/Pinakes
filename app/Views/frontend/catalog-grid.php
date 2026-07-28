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
                <?php if (($book['tipo_media'] ?? 'libro') !== 'libro'): ?>
                  <span class="book-media-badge" title="<?= htmlspecialchars(\App\Support\MediaLabels::tipoMediaDisplayName($book['tipo_media']), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(\App\Support\MediaLabels::tipoMediaDisplayName($book['tipo_media']), ENT_QUOTES, 'UTF-8') ?>">
                    <i class="fas <?= htmlspecialchars(\App\Support\MediaLabels::icon($book['tipo_media']), ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                  </span>
                <?php endif; ?>
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
    /* #298: cap at two lines; the exact height is equalised per grid row by the
       script below, so a row of short titles stays compact while a row that
       needs two lines lines up. */
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.book-title a {
    color: inherit;
    text-decoration: none;
    transition: color 0.2s ease;
}

.book-subtitle {
    font-size: 0.9rem;
    font-style: italic;
    line-height: 1.35;
    margin-top: -0.25rem;
    margin-bottom: 0.5rem;
    color: var(--text-secondary, #6b7280);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    /* #298: the subtitle is rendered only when the book has one, so it takes
       space conditionally — no reserved gap on the (majority) of cards without
       a subtitle. */
}

.book-title a:hover {
    color: var(--dark-color);
}

.book-author {
    font-size: 0.875rem;
    color: var(--text-secondary);
    margin-bottom: 0.5rem;
    /* #298: fixed single-line box so a long author name can't push the row
       below out of alignment with adjacent cards. */
    height: 1.3em;
    display: -webkit-box;
    -webkit-line-clamp: 1;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.book-meta {
    font-size: 0.75rem;
    color: var(--text-muted);
    line-height: 1.5;
    margin-bottom: auto;
    /* #298: fixed single-line box (see .book-author). */
    height: 1.5em;
    display: -webkit-box;
    -webkit-line-clamp: 1;
    -webkit-box-orient: vertical;
    overflow: hidden;
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
    }
}

.empty-state .btn-cta {
    justify-content: center;
}

.book-media-badge {
    position: absolute;
    top: 0.75rem;
    left: 0.75rem;
    background: rgba(255, 255, 255, 0.85);
    border-radius: 9999px;
    padding: 0.375rem;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
}

.book-media-badge i {
    color: var(--text-secondary, #6b7280);
    font-size: 0.75rem;
}
</style>
<script>
/* #298: equalise title AND subtitle height PER GRID ROW. A row where no card
   has a subtitle stays compact; a row where at least one card has a subtitle
   gets the same reserved subtitle height on every card of that row — including
   a hidden placeholder on the cards that have none — so title, author, publisher
   and "Details" line up. Pure layout — no theme colours. */
(function () {
  function rowsOf(cards) {
    var rows = [];
    cards.forEach(function (c) {
      var top = Math.round(c.getBoundingClientRect().top);
      var row = null;
      for (var i = 0; i < rows.length; i++) { if (Math.abs(rows[i].top - top) < 8) { row = rows[i]; break; } }
      if (!row) { row = { top: top, cards: [] }; rows.push(row); }
      row.cards.push(c);
    });
    return rows;
  }
  function equalise(els, prop) {
    var max = 0;
    els.forEach(function (e) { if (e.offsetHeight > max) max = e.offsetHeight; });
    els.forEach(function (e) { e.style[prop] = max + 'px'; });
    return max;
  }
  function align() {
    var cards = Array.prototype.slice.call(document.querySelectorAll('.books-grid .book-card'));
    if (!cards.length) return;
    // undo previous adjustments so a resize recomputes from scratch
    cards.forEach(function (c) {
      var t = c.querySelector('.book-title'); if (t) t.style.height = '';
      var s = c.querySelector('.book-subtitle:not(.subtitle-ph)'); if (s) s.style.height = '';
      var ph = c.querySelector('.subtitle-ph'); if (ph) ph.parentNode.removeChild(ph);
    });
    rowsOf(cards).forEach(function (row) {
      // titles: same height as the tallest title in the row
      var titles = row.cards.map(function (c) { return c.querySelector('.book-title'); }).filter(Boolean);
      if (titles.length) equalise(titles, 'height');
      // subtitles: only if the row has at least one
      var realSubs = row.cards.map(function (c) { return c.querySelector('.book-subtitle'); }).filter(Boolean);
      if (!realSubs.length) return;
      var maxS = equalise(realSubs, 'height'); // pad the shorter real subtitles too
      row.cards.forEach(function (c) {
        if (c.querySelector('.book-subtitle')) return; // already has one
        var t = c.querySelector('.book-title'); if (!t) return;
        // a hidden .book-subtitle placeholder inherits the same margins, so the
        // author below lines up exactly with the subtitled cards.
        var ph = document.createElement('p');
        ph.className = 'book-subtitle subtitle-ph';
        ph.setAttribute('aria-hidden', 'true');
        ph.style.visibility = 'hidden';
        ph.style.height = maxS + 'px';
        ph.innerHTML = '&nbsp;';
        t.insertAdjacentElement('afterend', ph);
      });
    });
  }
  var timer;
  function schedule() { clearTimeout(timer); timer = setTimeout(align, 120); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', align);
  else align();
  window.addEventListener('load', align);
  window.addEventListener('resize', schedule);
})();
</script>

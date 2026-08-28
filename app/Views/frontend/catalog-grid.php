<?php
use App\Support\HtmlHelper;
$edgeCacheEnabled = \App\Support\LiteSpeedCache::enabled();

$createBookUrl = static function ($book) {
    return book_url($book);
};

$getBookStatusBadge = static function ($book) use ($edgeCacheEnabled) {
    ob_start();
    if ($edgeCacheEnabled) {
        echo '<span class="book-status-badge status-unavailable" data-live-book-id="' . (int) $book['id'] . '" data-live-role="badge" data-live-pending="1"><span data-live-label></span>';
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
        echo '<span class="book-status-badge status-available"><span data-live-label>' . __("Disponibile") . '</span>';
    } elseif ($stato === 'prenotato') {
        echo '<span class="book-status-badge status-reserved"><span data-live-label>' . __("Prenotato") . '</span>';
    } elseif ($stato === 'prestato') {
        echo '<span class="book-status-badge status-borrowed"><span data-live-label>' . __("In prestito") . '</span>';
    } else {
        echo '<span class="book-status-badge status-unavailable"><span data-live-label>' . __("Non disponibile") . '</span>';
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
/* Enhanced book card styling */
.book-card {
    display: flex;
    flex-direction: column;
    height: 100%;
    background: none;
    border-radius: 0;
    overflow: visible;
    box-shadow: none;
    border: none;
    transition: var(--transition);
}

.book-card:hover {
    transform: none;
    box-shadow: none;
}

/* The cover is the visual hero: the only element carrying elevation, lifting
   subtly on hover instead of the whole card. */
.book-image-container {
    position: relative;
    aspect-ratio: 2/3;
    overflow: hidden;
    background: var(--bg-tertiary);
    border-radius: 3px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
    transition: var(--transition);
}

.book-card:hover .book-image-container {
    transform: translateY(-3px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.18);
}

.book-image {
    width: 100%;
    height: 100%;
    object-fit: contain;
    object-position: center;
    transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1), filter 0.3s ease;
    will-change: transform;
}

.book-card:hover .book-image {
    transform: scale(1.012) translateZ(0);
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
       below out of alignment with adjacent cards. line-height matches the
       height so the single line isn't clipped by overflow:hidden. */
    height: 1.3em;
    line-height: 1.3;
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
    padding-top: 0.75rem;
}

/* Detail action = quiet text link, not a filled button (matches home). */
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

.status-unavailable {
    /* Neutral grey — theme-agnostic, signals "not circulating" without alarm */
    background: #6b7280;
    color: white;
}

/* Ensure consistent grid layout */
.books-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(180px, 100%), 1fr));
    gap: 1.5rem;
    align-items: stretch;
}

/* Hide empty elements but maintain spacing */
.book-author[style*="visibility: hidden"],
.book-meta-empty {
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
    border-radius: 50%;
    padding: 0.375rem;
    box-shadow: none;
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
  function maxHeight(els) {
    var max = 0;
    els.forEach(function (e) { if (e.offsetHeight > max) max = e.offsetHeight; });
    return max;
  }
  function alignGrid(grid) {
    var cards = Array.prototype.slice.call(grid.querySelectorAll('.book-card'));
    if (!cards.length) return;
    // 1) RESET (writes only): undo previous adjustments so the READ phase below
    //    measures natural heights.
    cards.forEach(function (c) {
      var t = c.querySelector('.book-title'); if (t) t.style.height = '';
      var s = c.querySelector('.book-subtitle:not(.subtitle-ph)'); if (s) s.style.height = '';
      var ph = c.querySelector('.subtitle-ph'); if (ph) ph.parentNode.removeChild(ph);
    });
    // 2) READ (measurements only): batch every getBoundingClientRect/offsetHeight
    //    read here so the WRITE phase can't interleave reads and force repeated
    //    synchronous reflows (#302 review, layout-thrashing fix).
    var plan = rowsOf(cards).map(function (row) {
      var titles = row.cards.map(function (c) { return c.querySelector('.book-title'); }).filter(Boolean);
      var realSubs = row.cards.map(function (c) { return c.querySelector('.book-subtitle'); }).filter(Boolean);
      return { row: row, titles: titles, realSubs: realSubs, maxT: maxHeight(titles), maxS: maxHeight(realSubs) };
    });
    // 3) WRITE (mutations only): apply heights and inject placeholders. No reads
    //    here, so the browser reflows at most once after this batch.
    plan.forEach(function (p) {
      // maxT === 0 means the grid is not laid out (e.g. an ancestor is
      // display:none) — skip rather than collapse every title to 0px.
      if (!p.maxT) return;
      p.titles.forEach(function (e) { e.style.height = p.maxT + 'px'; });
      if (!p.realSubs.length) return; // row has no subtitle → stays compact
      p.realSubs.forEach(function (e) { e.style.height = p.maxS + 'px'; });
      p.row.cards.forEach(function (c) {
        if (c.querySelector('.book-subtitle')) return; // already has one
        var t = c.querySelector('.book-title'); if (!t) return;
        // a hidden .book-subtitle placeholder inherits the same margins, so the
        // author below lines up exactly with the subtitled cards.
        var ph = document.createElement('p');
        ph.className = 'book-subtitle subtitle-ph';
        ph.setAttribute('aria-hidden', 'true');
        ph.style.visibility = 'hidden';
        ph.style.height = p.maxS + 'px';
        ph.innerHTML = '&nbsp;';
        t.insertAdjacentElement('afterend', ph);
      });
    });
  }
  var frame = null;
  function align() {
    // Coalesce bursts of triggers (DOMContentLoaded+load, rapid resize/AJAX)
    // into a single alignment per animation frame (#302 review, thrashing fix).
    if (frame) return;
    frame = (window.requestAnimationFrame || window.setTimeout)(function () {
      frame = null;
      // Scope per grid: two .books-grid on the same page must not have their rows
      // merged just because cards happen to share a vertical position.
      Array.prototype.slice.call(document.querySelectorAll('.books-grid')).forEach(alignGrid);
    }, 16);
  }
  var timer;
  function schedule() { clearTimeout(timer); timer = setTimeout(align, 120); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', align);
  else align();
  window.addEventListener('load', align);
  window.addEventListener('resize', schedule);
  // Re-align once web fonts settle: a late font swap can reflow a title from one
  // line to two after the initial pass, leaving the row misaligned until a resize
  // (#302 review). Harmless where fonts are already loaded.
  if (document.fonts && document.fonts.ready && typeof document.fonts.ready.then === 'function') {
    document.fonts.ready.then(align);
  }
  // The catalogue replaces this partial through AJAX after filters, sorting and
  // pagination. Scripts inserted through innerHTML do not execute, so the page
  // explicitly emits this event once the replacement cards are in the DOM.
  document.addEventListener('pinakes:catalog-grid-updated', align);
})();
</script>

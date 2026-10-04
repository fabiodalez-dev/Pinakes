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
        <?php if (($book['_record_kind'] ?? '') === 'article') { include __DIR__ . '/partials/catalog-article-card.php'; continue; } ?>
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
                <?php if (!empty($book['is_desiderata'])): ?>
                    <?php
                    // A book the library WANTS, not one it holds: it has no
                    // availability to hydrate, so this badge deliberately
                    // carries no data-live-* attributes — the edge-cache
                    // hydration in live-availability.js only rewrites those,
                    // and would otherwise relabel it "Non disponibile".
                    ?>
                    <span class="book-status-badge status-unavailable dw-wanted-badge"><span><i class="fas fa-hand-holding-heart" aria-hidden="true"></i> <?= htmlspecialchars(__('Cercato dalla biblioteca'), ENT_QUOTES, 'UTF-8') ?></span></span>
                <?php else: ?>
                    <?= $getBookStatusBadge($book) ?>
                <?php endif; ?>
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
        <h4 class="empty-state-title"><?= __("Nessun risultato trovato") ?></h4>
        <p class="empty-state-text"><?= __("Prova a modificare i filtri o la tua ricerca") ?></p>
        <button type="button" class="btn-cta btn-cta-sm" onclick="clearAllFilters()">
            <i class="fas fa-redo mr-2"></i>
            <?= __("Pulisci filtri") ?>
        </button>
    </div>
<?php endif; ?>
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

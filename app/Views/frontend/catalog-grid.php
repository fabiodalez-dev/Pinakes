<?php
use App\Support\HtmlHelper;
// Every card is the shared 2026 card (partials/pk-book-card.php).

?>
<?php if (!empty($books)): ?>
    <?php foreach($books as $book): ?>
        <?php if (($book['_record_kind'] ?? '') === 'article') { include __DIR__ . '/partials/catalog-article-card.php'; continue; } ?>
        <?php include __DIR__ . '/partials/pk-book-card.php'; ?>
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
    // The list view has one card per row: nothing to line up, and heights
    // measured there would clip the titles once the grid comes back.
    if (grid.classList.contains('is-list')) return;
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
  // Switching between grid and list changes which cards share a row.
  document.addEventListener('pinakes:catalog-view-changed', align);
})();
</script>

/*
 * Pinakes 2026 frontend behaviour, shared by every public page.
 *
 *  - Card tone: each card's panel is tinted from its cover's average colour,
 *    mixed towards white, as in the design. A cover from another origin taints
 *    the canvas; that card keeps the neutral default.
 *  - Wishlist hearts: signed in, a heart toggles the book in the wishlist
 *    through the same endpoint the book page uses; signed out, it leads to
 *    the login page. window.PK carries the state (see layout.php).
 *
 * Cards added later (the catalogue's AJAX refresh) are picked up through the
 * `pinakes:catalog-grid-updated` event and a MutationObserver.
 */
(function () {
  'use strict';
  var PK = window.PK || {};
  var wished = new Set((PK.wish || []).map(String));
  var canvas = null;

  function tone(img) {
    var panel = img.closest('[data-pk-tone-target]') || img.closest('.pk-card__panel');
    if (!panel || !img.naturalWidth) return;
    try {
      canvas = canvas || document.createElement('canvas');
      canvas.width = 8; canvas.height = 8;
      var ctx = canvas.getContext('2d', { willReadFrequently: true });
      ctx.drawImage(img, 0, 0, 8, 8);
      var d = ctx.getImageData(0, 0, 8, 8).data, r = 0, g = 0, b = 0, n = 0;
      for (var i = 0; i < d.length; i += 4) { r += d[i]; g += d[i + 1]; b += d[i + 2]; n++; }
      r = Math.round(r / n); g = Math.round(g / n); b = Math.round(b / n);
      var mix = function (c) { return Math.round(c * 0.16 + 255 * 0.84); };
      panel.style.setProperty('--pk-tone', 'rgb(' + mix(r) + ',' + mix(g) + ',' + mix(b) + ')');
    } catch (e) { /* cross-origin cover: keep the default tone */ }
  }

  function toneAll(root) {
    (root || document).querySelectorAll('img[data-pk-tone]').forEach(function (img) {
      if (img.dataset.pkToned) return;
      img.dataset.pkToned = '1';
      if (img.complete && img.naturalWidth) tone(img);
      else img.addEventListener('load', function () { tone(img); }, { once: true });
    });
  }

  function paint(btn, on) {
    btn.classList.toggle('is-on', on);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    var label = on ? (PK.wishOn || '') : (PK.wishOff || '');
    if (label) { btn.setAttribute('aria-label', label); btn.title = label; }
  }

  function hearts(root) {
    (root || document).querySelectorAll('[data-pk-wish]').forEach(function (btn) {
      if (btn.dataset.pkBound) return;
      btn.dataset.pkBound = '1';
      paint(btn, wished.has(btn.dataset.pkWish));
    });
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-pk-wish]');
    if (!btn) return;
    ev.preventDefault();
    ev.stopPropagation();
    if (!PK.logged) {
      if (PK.login) window.location.href = PK.login;
      return;
    }
    var id = btn.dataset.pkWish;
    btn.disabled = true;
    fetch((window.BASE_PATH || '') + '/api/user/wishlist/toggle', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ csrf_token: PK.csrf || '', libro_id: id })
    }).then(function (r) { if (!r.ok) throw new Error('wishlist'); return r.json(); })
      .then(function (data) {
        var on = !!data.favorite;
        if (on) wished.add(id); else wished.delete(id);
        document.querySelectorAll('[data-pk-wish="' + id + '"]').forEach(function (b) { paint(b, on); });
        document.dispatchEvent(new CustomEvent('pinakes:wishlist-changed', { detail: { id: id, favorite: on } }));
      })
      .catch(function () {
        if (window.SwalApp && window.SwalApp.error) window.SwalApp.error(undefined, PK.wishError || '');
      })
      .finally(function () { btn.disabled = false; });
  });

  function scan(root) { toneAll(root); hearts(root); }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { scan(); });
  else scan();
  document.addEventListener('pinakes:catalog-grid-updated', function () { scan(); });
  if ('MutationObserver' in window) {
    new MutationObserver(function (list) {
      for (var i = 0; i < list.length; i++) { if (list[i].addedNodes.length) { scan(); return; } }
    }).observe(document.documentElement, { childList: true, subtree: true });
  }
  window.PinakesDesign = { scan: scan };
})();

/* Catalogue: the author finder filters the author list as you type (also
   after the AJAX refresh re-renders it), and Grid / List switches the view,
   remembered per visitor. */
(function () {
  'use strict';
  function applyFilter(input) {
    var list = document.getElementById(input.getAttribute('data-pk-filter-list'));
    if (!list) return;
    var q = input.value.trim().toLowerCase();
    list.querySelectorAll('.filter-option').forEach(function (opt) {
      opt.classList.toggle('is-filtered-out', q !== '' && opt.textContent.toLowerCase().indexOf(q) === -1);
    });
  }
  document.addEventListener('input', function (ev) {
    if (ev.target.matches && ev.target.matches('[data-pk-filter-list]')) applyFilter(ev.target);
  });
  document.addEventListener('pinakes:catalog-grid-updated', function () {
    document.querySelectorAll('[data-pk-filter-list]').forEach(applyFilter);
  });

  var KEY = 'pinakes-catalog-view';
  function setView(view) {
    var grid = document.getElementById('books-grid');
    if (!grid) return;
    grid.classList.toggle('is-list', view === 'list');
    document.querySelectorAll('[data-pk-view]').forEach(function (b) {
      var on = b.getAttribute('data-pk-view') === view;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    grid.dispatchEvent(new Event('pinakes:catalog-view-changed', { bubbles: true }));
  }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest && ev.target.closest('[data-pk-view]');
    if (!b) return;
    var view = b.getAttribute('data-pk-view');
    setView(view);
    try { localStorage.setItem(KEY, view); } catch (e) { /* private mode */ }
  });
  function restore() {
    var view = 'grid';
    try { view = localStorage.getItem(KEY) || 'grid'; } catch (e) { /* private mode */ }
    if (view === 'list') setView('list');
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', restore); else restore();
})();

/* "N autori" under the author list follows the list the catalogue script
   re-renders. */
(function () {
  'use strict';
  function recount() {
    document.querySelectorAll('[data-pk-count-of]').forEach(function (el) {
      var list = document.getElementById(el.getAttribute('data-pk-count-of'));
      if (!list) return;
      var n = list.querySelectorAll('.filter-option').length;
      el.textContent = (el.getAttribute('data-pk-count-label') || '%d').replace('%d', String(n));
      el.hidden = n === 0;
    });
  }
  document.addEventListener('pinakes:catalog-grid-updated', recount);
  if ('MutationObserver' in window) {
    var start = function () {
      var list = document.getElementById('authors-filter');
      if (list) new MutationObserver(recount).observe(list, { childList: true });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
  }
})();

/* Inline citation: style tabs and Copy. */
(function () {
  'use strict';
  document.addEventListener('click', function (ev) {
    var tab = ev.target.closest && ev.target.closest('[data-pk-cite-tab]');
    if (tab) {
      var box = tab.closest('[data-pk-cite]');
      var key = tab.getAttribute('data-pk-cite-tab');
      box.querySelectorAll('[data-pk-cite-tab]').forEach(function (t) {
        var on = t === tab; t.classList.toggle('is-active', on); t.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      box.querySelectorAll('[data-pk-cite-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-pk-cite-panel') !== key; });
      return;
    }
    var copy = ev.target.closest && ev.target.closest('[data-pk-cite-copy]');
    if (!copy) return;
    var panel = copy.closest('[data-pk-cite]').querySelector('[data-pk-cite-panel]:not([hidden])');
    var text = panel ? panel.getAttribute('data-pk-cite-text') : '';
    var label = copy.querySelector('span');
    var done = function () {
      if (!label) return;
      var original = label.textContent;
      label.textContent = (copy.getAttribute('data-pk-cite-done') || original) + ' ✓';
      setTimeout(function () { label.textContent = original; }, 2000);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, function () {});
  });
})();

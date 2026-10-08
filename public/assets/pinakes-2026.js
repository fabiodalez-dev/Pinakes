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

  // The state is carried by aria-pressed alone: the accessible name stays
  // "Aggiungi ai preferiti" (a toggle whose name flipped as well would be
  // announced as "Nei preferiti, not pressed"). Only the hover tooltip
  // follows the state.
  function paint(btn, on) {
    btn.classList.toggle('is-on', on);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    if (PK.wishOff) btn.setAttribute('aria-label', PK.wishOff);
    var tip = on ? (PK.wishOn || '') : (PK.wishOff || '');
    if (tip) btn.title = tip;
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

  // Catalogue facets are links styled as checkboxes: tell assistive tech
  // they are toggle buttons and which ones are on, as the look does.
  function facetStates(root) {
    var opts = (root || document).querySelectorAll('.filter-options .filter-option');
    for (var i = 0; i < opts.length; i++) {
      var o = opts[i];
      if (o.tagName === 'A' && !o.hasAttribute('role')) o.setAttribute('role', 'button');
      o.setAttribute('aria-pressed', o.classList.contains('active') ? 'true' : 'false');
    }
  }
  // A link with role=button answers Space as well as Enter.
  document.addEventListener('keydown', function (ev) {
    var t = ev.target;
    if ((ev.key === ' ' || ev.key === 'Spacebar') && t && t.matches && t.matches('.filter-options a.filter-option[role="button"]')) {
      ev.preventDefault();
      t.click();
    }
  });

  function scan(root) { toneAll(root); hearts(root); facetStates(root); }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { scan(); });
  else scan();
  document.addEventListener('pinakes:catalog-grid-updated', function () { scan(); });
  if ('MutationObserver' in window) {
    // Many insertions in a row (a re-rendered grid, a third-party widget)
    // collapse into a single scan on the next frame.
    var pending = false;
    var flush = function () { pending = false; scan(); };
    new MutationObserver(function (list) {
      if (pending) return;
      for (var i = 0; i < list.length; i++) {
        if (list[i].addedNodes.length) {
          pending = true;
          if (window.requestAnimationFrame) window.requestAnimationFrame(flush); else setTimeout(flush, 16);
          return;
        }
      }
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
      opt.classList.toggle('is-filtered-out', q !== '' && optionName(opt).toLowerCase().indexOf(q) === -1);
    });
  }
  // The author's name only: the option also holds its book count
  // (.count-badge), which must not match a typed number.
  function optionName(opt) {
    if (opt.title) return opt.title;
    var name = '';
    opt.childNodes.forEach(function (node) {
      if (node.nodeType === 1 && node.classList.contains('count-badge')) return;
      name += node.textContent;
    });
    return name;
  }
  document.addEventListener('input', function (ev) {
    if (ev.target.matches && ev.target.matches('[data-pk-filter-list]')) applyFilter(ev.target);
  });
  document.addEventListener('pinakes:catalog-grid-updated', function () {
    document.querySelectorAll('[data-pk-filter-list]').forEach(applyFilter);
  });

  // Facets are rebuilt after the grid-updated event, and also when "Cambia"
  // reopens a selected facet. Keep the finder applied to the new options in
  // both cases. Only observe children: changing their classes cannot loop.
  if ('MutationObserver' in window) {
    var observeFilterLists = function () {
      document.querySelectorAll('[data-pk-filter-list]').forEach(function (input) {
        var list = document.getElementById(input.getAttribute('data-pk-filter-list'));
        if (!list) return;
        new MutationObserver(function () { applyFilter(input); }).observe(list, { childList: true });
        applyFilter(input);
      });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', observeFilterLists);
    else observeFilterLists();
  }

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
      var label = (n === 1 && el.getAttribute('data-pk-count-label-one')) || el.getAttribute('data-pk-count-label') || '%d';
      el.textContent = label.replace('%d', String(n));
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

/* Inline citation: style tabs (WAI-ARIA tabs: arrows, Home and End move
   between styles) and Copy, whose outcome is announced in the box's status
   region. */
(function () {
  'use strict';
  function tabsOf(box) { return Array.prototype.slice.call(box.querySelectorAll('[data-pk-cite-tab]')); }

  function select(tab, focus) {
    var box = tab.closest('[data-pk-cite]');
    if (!box) return;
    var key = tab.getAttribute('data-pk-cite-tab');
    tabsOf(box).forEach(function (t) {
      var on = t === tab;
      t.classList.toggle('is-active', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.setAttribute('tabindex', on ? '0' : '-1');
    });
    box.querySelectorAll('[data-pk-cite-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-pk-cite-panel') !== key; });
    if (focus) tab.focus();
  }

  document.addEventListener('keydown', function (ev) {
    var tab = ev.target.closest && ev.target.closest('[data-pk-cite-tab]');
    if (!tab) return;
    var box = tab.closest('[data-pk-cite]');
    if (!box) return;
    var tabs = tabsOf(box), i = tabs.indexOf(tab), next = -1;
    if (ev.key === 'ArrowRight') next = (i + 1) % tabs.length;
    else if (ev.key === 'ArrowLeft') next = (i - 1 + tabs.length) % tabs.length;
    else if (ev.key === 'Home') next = 0;
    else if (ev.key === 'End') next = tabs.length - 1;
    if (next === -1) return;
    ev.preventDefault();
    select(tabs[next], true);
  });

  function announce(box, text, state) {
    var status = box && box.querySelector('[data-pk-cite-status]');
    if (!status) return;
    status.setAttribute('data-state', state);
    // Cleared first so the same message is announced again on a repeat click.
    status.textContent = '';
    setTimeout(function () { status.textContent = text; }, 50);
  }

  // Without the Clipboard API (or when it refuses), the citation is selected
  // so the reader can copy it by hand.
  function selectText(panel) {
    if (!panel || !window.getSelection || !document.createRange) return;
    var range = document.createRange();
    range.selectNodeContents(panel);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
  }

  document.addEventListener('click', function (ev) {
    var tab = ev.target.closest && ev.target.closest('[data-pk-cite-tab]');
    if (tab) { select(tab, false); return; }
    var copy = ev.target.closest && ev.target.closest('[data-pk-cite-copy]');
    if (!copy) return;
    var box = copy.closest('[data-pk-cite]');
    var panel = box ? box.querySelector('[data-pk-cite-panel]:not([hidden])') : null;
    var text = panel ? panel.getAttribute('data-pk-cite-text') || '' : '';
    var label = copy.querySelector('span');
    // The original label is kept once, so a second click while "Copiato" is
    // shown does not make "Copiato" the label to restore.
    if (label && !label.hasAttribute('data-pk-cite-label')) label.setAttribute('data-pk-cite-label', label.textContent);
    var original = label ? label.getAttribute('data-pk-cite-label') : '';
    var doneText = copy.getAttribute('data-pk-cite-done') || original;
    var done = function () {
      if (label) {
        label.textContent = doneText + ' ✓';
        clearTimeout(copy.pkCiteTimer);
        copy.pkCiteTimer = setTimeout(function () { label.textContent = original; }, 2000);
      }
      announce(box, doneText, 'ok');
    };
    var fail = function () {
      selectText(panel);
      announce(box, copy.getAttribute('data-pk-cite-fail') || '', 'error');
    };
    if (text !== '' && navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, fail);
    else fail();
  });
})();

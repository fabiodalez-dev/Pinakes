(function () {
  'use strict';

  function updateElement(element, data) {
    var role = element.getAttribute('data-live-role') || 'badge';
    if (role === 'badge') {
      element.classList.remove('status-available', 'status-reserved', 'status-borrowed', 'status-unavailable', 'available', 'unavailable');
      element.classList.add(data.state === 'available' ? 'status-available' : 'status-' + data.state);
      var label = element.querySelector('[data-live-label]');
      (label || element).textContent = data.label;
    } else if (role === 'detail-badge') {
      element.classList.toggle('available', data.available);
      element.classList.toggle('unavailable', !data.available);
      var icon = element.querySelector('i');
      if (icon) icon.className = 'fas fa-' + (data.available ? 'check-circle' : 'times-circle') + ' mr-2';
      var detailLabel = element.querySelector('[data-live-label]');
      if (detailLabel) detailLabel.textContent = data.detail_label;
    } else if (role === 'status') {
      element.classList.toggle('is-available', data.available);
      element.classList.toggle('is-unavailable', !data.available);
      element.textContent = data.available ? data.label : data.detail_label;
    } else if (role === 'count') {
      element.textContent = data.copies_available + ' / ' + data.copies_total;
    } else if (role === 'action') {
      element.classList.toggle('btn-primary', data.available);
      element.classList.toggle('btn-outline-primary', !data.available);
      var actionIcon = element.querySelector('i');
      if (actionIcon) actionIcon.className = 'fas fa-' + (data.available ? 'book-reader' : 'calendar-alt') + ' mr-2';
      var actionLabel = element.querySelector('[data-live-label]');
      if (actionLabel) actionLabel.textContent = data.action_label;
      element.disabled = false;
    } else if (role === 'related') {
      element.hidden = !data.available;
    }
    element.removeAttribute('data-live-pending');
  }

  function hydrate() {
    var elements = Array.prototype.slice.call(document.querySelectorAll('[data-live-book-id]'));
    var statElements = Array.prototype.slice.call(document.querySelectorAll('[data-live-stat]'));
    if (!elements.length && !statElements.length) return;
    var ids = Array.from(new Set(elements.map(function (el) {
      return el.getAttribute('data-live-book-id');
    }).filter(function (id) { return /^\d+$/.test(id || ''); })));
    var base = typeof window.BASE_PATH === 'string' ? window.BASE_PATH.replace(/\/$/, '') : '';
    var batches = [];
    for (var offset = 0; offset < ids.length; offset += 100) batches.push(ids.slice(offset, offset + 100));
    if (!batches.length) batches.push([]);

    Promise.all(batches.map(function (batch, index) {
      var query = 'ids=' + encodeURIComponent(batch.join(','));
      if (statElements.length && index === 0) query += '&stats=1';
      return fetch(base + '/api/edge/availability?' + query, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        cache: 'no-store'
      }).then(function (response) {
        if (!response.ok) throw new Error('availability request failed');
        return response.json();
      }).then(function (payload) {
        if (!payload || !payload.success || !payload.books) throw new Error('invalid availability payload');
        return payload;
      });
    })).then(function (payloads) {
      var books = {};
      var stats = null;
      payloads.forEach(function (payload) {
        Object.keys(payload.books).forEach(function (id) { books[id] = payload.books[id]; });
        if (payload.stats) stats = payload.stats;
      });
      elements.forEach(function (element) {
        var data = books[element.getAttribute('data-live-book-id')];
        if (data) updateElement(element, data);
        else element.remove();
      });
      statElements.forEach(function (element) {
        var key = element.getAttribute('data-live-stat');
        if (stats && Object.prototype.hasOwnProperty.call(stats, key)) {
          element.textContent = stats[key];
          element.removeAttribute('data-live-pending');
        }
      });
    }).catch(function () {
      // Fail closed: pending availability stays hidden/disabled. The server-side
      // loan gate remains authoritative, but stale cached counts are never shown.
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', hydrate);
  else hydrate();
  document.addEventListener('pinakes:catalog-grid-updated', hydrate);
})();

<?php
/**
 * @var array $data { editori: array }
 */
$title = __("Editori");
$editori = $data['editori'];
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Header -->
    <div class="mb-6">
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <nav class="flex items-center text-sm text-gray-500 mb-2">
            <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="hover:text-gray-700"><i class="fas fa-home"></i></a>
            <i class="fas fa-chevron-right mx-2 text-xs text-gray-400"></i>
            <span class="text-gray-900 font-medium"><?= __("Editori") ?></span>
          </nav>
          <h1 class="text-3xl font-bold text-gray-900"><?= __("Editori") ?></h1>
          <p class="mt-1 text-sm text-gray-600"><?= __("Gestisci le case editrici e i relativi contatti") ?></p>
          <span id="total-badge" class="status-badge bg-gray-100 text-gray-700 mt-3"></span>
        </div>
        <div class="flex items-center gap-2">
          <a href="<?= htmlspecialchars(url('/admin/publishers/create'), ENT_QUOTES, 'UTF-8') ?>" class="btn-primary">
            <i class="fas fa-plus mr-2"></i><?= __("Nuovo Editore") ?>
          </a>
        </div>
      </div>
    </div>

    <!-- Main Card with Integrated Filters -->
    <div class="card entity-list-card">
      <!-- Primary Filters Bar -->
      <div class="p-4 border-b border-gray-100">
        <div class="entity-filter-grid publisher-filter-grid">
          <!-- Search Text -->
          <div>
            <label class="form-label">
              <i class="fas fa-search mr-1"></i><?= __("Cerca") ?>
            </label>
            <input id="search_nome" type="text" placeholder="<?= __('Nome editore...') ?>"
                   class="form-input" />
          </div>

          <!-- Books Count Filter -->
          <div>
            <label class="form-label">
              <i class="fas fa-book mr-1"></i><?= __("N. Libri") ?>
            </label>
            <select id="filter_libri_count" class="form-input">
              <option value=""><?= __("Tutti") ?></option>
              <option value="0"><?= __("Nessun libro") ?></option>
              <option value="1-10">1-10 <?= __("libri") ?></option>
              <option value="11-50">11-50 <?= __("libri") ?></option>
              <option value="51+"><?= __("Più di 50") ?></option>
            </select>
          </div>

          <!-- Toggle Advanced Filters -->
          <button id="toggle-advanced" class="btn-secondary justify-center">
            <i class="fas fa-sliders-h mr-1"></i>
            <span class="hidden sm:inline"><?= __("Altri filtri") ?></span>
            <i id="toggle-advanced-icon" class="fas fa-chevron-down text-xs ml-1 transition-transform"></i>
          </button>

          <!-- Clear All -->
          <button id="clear-filters" class="btn-secondary justify-center" title="<?= __('Cancella tutti i filtri') ?>">
            <i class="fas fa-times mr-2"></i><span><?= __("Azzera") ?></span>
          </button>
        </div>

        <!-- Active Filters Chips -->
        <div id="active-filters" class="hidden mt-3 flex flex-wrap gap-2"></div>
      </div>

      <!-- Advanced Filters - Collapsible -->
      <div id="advanced-filters" class="hidden border-b border-gray-100 bg-gray-50/50 p-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
          <!-- Website -->
          <div>
            <label class="form-label">
              <i class="fas fa-globe mr-1"></i><?= __("Sito web") ?>
            </label>
            <input id="search_sito" type="text" placeholder="<?= __('URL...') ?>"
                   class="form-input" />
          </div>

          <!-- Address -->
          <div>
            <label class="form-label">
              <i class="fas fa-road mr-1"></i><?= __("Indirizzo") ?>
            </label>
            <input id="search_via" type="text" placeholder="<?= __('Via, numero...') ?>"
                   class="form-input" />
          </div>

          <!-- CAP -->
          <div>
            <label class="form-label">
              <i class="fas fa-hashtag mr-1"></i><?= __("CAP") ?>
            </label>
            <input id="search_cap" type="text" placeholder="<?= __('Codice postale...') ?>"
                   class="form-input" />
          </div>
        </div>
      </div>

      <!-- Table -->
      <div class="p-4">
        <div class="entity-table-wrap">
          <table id="editori-table" class="display" style="width:100%">
            <thead>
              <tr>
                <th class="text-center">
                  <input type="checkbox" id="select-all" class="w-4 h-4 rounded border-gray-300 text-gray-800 focus:ring-gray-500 cursor-pointer" />
                </th>
                <th><?= __("Nome") ?></th>
                <th><?= __("Sito Web") ?></th>
                <th><?= __("Indirizzo") ?></th>
                <th><?= __("N. Libri") ?></th>
                <th><?= __("Azioni") ?></th>
              </tr>
            </thead>
          </table>
        </div>
      </div>
    </div>

    <!-- Bulk Actions Bar -->
    <div id="bulk-actions-bar" class="hidden fixed bottom-0 right-0 bg-white border-t border-gray-200 shadow-lg z-40" style="left: 0;">
      <div class="px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="flex items-center gap-3">
          <span class="text-sm font-medium text-gray-700">
            <span id="selected-count">0</span> <?= __("selezionati") ?>
          </span>
          <button id="deselect-all" class="text-sm text-gray-500 hover:text-gray-700 transition-colors">
            <?= __("Deseleziona tutti") ?>
          </button>
        </div>
        <div class="grid grid-cols-3 gap-2">
          <button id="bulk-merge" class="btn-primary">
            <i class="fas fa-compress-arrows-alt mr-2"></i><?= __("Unisci") ?>
          </button>
          <button id="bulk-export" class="btn-secondary">
            <i class="fas fa-download mr-2"></i><?= __("Esporta") ?>
          </button>
          <button id="bulk-delete" class="btn-danger">
            <i class="fas fa-trash mr-2"></i><?= __("Elimina") ?>
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
window.i18nLocale = <?= json_encode(\App\Support\I18n::getLocale(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.__ = function(key) { return key; };

document.addEventListener('DOMContentLoaded', function() {
  if (typeof DataTable === 'undefined') {
    console.error('DataTable is not loaded!');
    return;
  }

  const debounce = (fn, ms=300) => { let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); }; };
  const selectedPublishers = new Set();

  // HTML escape helper to prevent XSS
  const escapeHtml = (str) => {
    if (str === null || str === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
  };

  // URL validation helper
  const isValidUrl = (str) => {
    try {
      const url = new URL(str);
      return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
      return false;
    }
  };

  // Toggle advanced filters
  const advancedBtn = document.getElementById('toggle-advanced');
  const advancedFilters = document.getElementById('advanced-filters');
  const advancedIcon = document.getElementById('toggle-advanced-icon');
  advancedBtn.addEventListener('click', function() {
    advancedFilters.classList.toggle('hidden');
    advancedIcon.classList.toggle('rotate-180');
  });

  // Initialize DataTable
  const table = new DataTable('#editori-table', {
    processing: true,
    serverSide: true,
    responsive: false,
    scrollX: false,
    autoWidth: true,
    searching: false,
    stateSave: true,
    stateDuration: 60 * 60 * 24,
    dom: '<"top"l>rt<"bottom"ip><"clear">',
    ajax: {
      url: window.BASE_PATH + '/api/editori',
      type: 'GET',
      data: function(d) {
        d.search_nome = document.getElementById('search_nome').value;
        d.search_text = document.getElementById('search_nome').value;
        d.search_sito = document.getElementById('search_sito').value;
        d.search_via = document.getElementById('search_via').value;
        d.search_cap = document.getElementById('search_cap').value;
        d.filter_libri_count = document.getElementById('filter_libri_count').value;
        return d;
      },
      dataSrc: function(json) {
        document.getElementById('total-badge').textContent = (json.recordsFiltered || 0).toLocaleString() + ' ' + <?= json_encode(__("editori"), JSON_HEX_TAG) ?>;
        return json.data;
      }
    },
    columns: [
      { // Checkbox
        data: null,
        orderable: false,
        searchable: false,
        width: '40px',
        className: 'text-center align-middle',
        render: function(_, __, row) {
          const id = parseInt(row.id, 10);
          const checked = selectedPublishers.has(id) ? 'checked' : '';
          return `<input type="checkbox" class="row-select w-4 h-4 rounded border-gray-300 text-gray-800 focus:ring-gray-500 cursor-pointer" data-id="${id}" ${checked} />`;
        }
      },
      { // Name
        data: null,
        width: '220px',
        className: 'align-middle',
        render: function(_, __, row) {
          const id = parseInt(row.id, 10);
          const nome = escapeHtml(row.nome) || <?= json_encode(__("Editore sconosciuto"), JSON_HEX_TAG) ?>;
          return `<div>
            <a href="${window.BASE_PATH}/admin/publishers/${id}" class="font-medium text-gray-900 hover:text-gray-700 hover:underline">${nome}</a>
          </div>`;
        }
      },
      { // Website
        data: 'sito_web',
        width: '200px',
        className: 'align-middle',
        render: function(data) {
          if (!data) return '<span class="text-gray-400">-</span>';
          if (!isValidUrl(data)) return `<span class="text-gray-500 text-sm">${escapeHtml(data.substring(0, 30))}...</span>`;
          const shortUrl = data.replace(/^https?:\/\/(www\.)?/, '').substring(0, 30);
          return `<a href="${escapeHtml(data)}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-800 text-sm inline-flex items-center">
            <i class="fas fa-external-link-alt mr-1 text-xs"></i>${escapeHtml(shortUrl)}${data.length > 40 ? '...' : ''}
          </a>`;
        }
      },
      { // Address
        data: null,
        width: '180px',
        className: 'align-middle',
        render: function(_, __, row) {
          const parts = [];
          if (row.via) parts.push(escapeHtml(row.via));
          if (row.cap) parts.push(escapeHtml(row.cap));
          if (parts.length === 0) return '<span class="text-gray-400">-</span>';
          return `<span class="text-sm text-gray-600">${parts.join(', ')}</span>`;
        }
      },
      { // Numero Libri
        data: null,
        width: '100px',
        className: 'text-center align-middle',
        render: function(_, __, row) {
          const count = parseInt(row.libri_count, 10) || 0;
          const cls = count > 0 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500';
          return `<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}">
            <i class="fas fa-book mr-1"></i>${count}
          </span>`;
        }
      },
      { // Actions
        data: 'id',
        orderable: false,
        searchable: false,
        width: '100px',
        className: 'text-center align-middle',
        render: function(data) {
          const id = parseInt(data, 10);
          return `<div class="flex items-center justify-center gap-0.5">
            <a href="${window.BASE_PATH}/admin/publishers/${id}" class="w-7 h-7 inline-flex items-center justify-center text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded transition-all" title="${<?= json_encode(__('Visualizza'), JSON_HEX_TAG) ?>}">
              <i class="fas fa-eye text-xs"></i>
            </a>
            <a href="${window.BASE_PATH}/admin/publishers/edit/${id}" class="w-7 h-7 inline-flex items-center justify-center text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded transition-all" title="${<?= json_encode(__('Modifica'), JSON_HEX_TAG) ?>}">
              <i class="fas fa-edit text-xs"></i>
            </a>
            <button onclick="deletePublisher(${id})" class="w-7 h-7 inline-flex items-center justify-center text-gray-500 hover:text-red-600 hover:bg-red-50 rounded transition-all" title="${<?= json_encode(__('Elimina'), JSON_HEX_TAG) ?>}">
              <i class="fas fa-trash text-xs"></i>
            </button>
          </div>`;
        }
      }
    ],
    order: [[1, 'asc']],
    pageLength: 25,
    lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
    language: typeof window.getDtLanguage === 'function' ? window.getDtLanguage() : {},
    drawCallback: function() {
      // Restore checkbox states
      document.querySelectorAll('.row-select').forEach(cb => {
        cb.checked = selectedPublishers.has(parseInt(cb.dataset.id));
      });
      updateSelectAllState();
    }
  });

  // Filter handlers
  const reloadDebounced = debounce(() => { table.ajax.reload(); updateActiveFilters(); });
  ['search_nome', 'search_sito', 'search_via', 'search_cap', 'filter_libri_count'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      el.addEventListener('input', reloadDebounced);
      el.addEventListener('change', reloadDebounced);
    }
  });

  // Clear filters
  document.getElementById('clear-filters').addEventListener('click', function() {
    ['search_nome', 'search_sito', 'search_via', 'search_cap'].forEach(id => {
      document.getElementById(id).value = '';
    });
    document.getElementById('filter_libri_count').value = '';
    table.ajax.reload();
    updateActiveFilters();
  });

  // Active filters display
  function updateActiveFilters() {
    const container = document.getElementById('active-filters');
    container.innerHTML = '';
    const filters = [];

    const nome = document.getElementById('search_nome').value;
    if (nome) filters.push({ key: 'search_nome', label: `"${escapeHtml(nome)}"`, icon: 'fa-search' });

    const libri = document.getElementById('filter_libri_count').value;
    if (libri) filters.push({ key: 'filter_libri_count', label: `${<?= json_encode(__("Libri") . ": ", JSON_HEX_TAG) ?>}${libri}`, icon: 'fa-book' });

    const sito = document.getElementById('search_sito').value;
    if (sito) filters.push({ key: 'search_sito', label: `${<?= json_encode(__("Sito") . ": ", JSON_HEX_TAG) ?>}${escapeHtml(sito)}`, icon: 'fa-globe' });

    const via = document.getElementById('search_via').value;
    if (via) filters.push({ key: 'search_via', label: `${<?= json_encode(__("Indirizzo") . ": ", JSON_HEX_TAG) ?>}${escapeHtml(via)}`, icon: 'fa-road' });

    const cap = document.getElementById('search_cap').value;
    if (cap) filters.push({ key: 'search_cap', label: `${<?= json_encode(__("CAP") . ": ", JSON_HEX_TAG) ?>}${escapeHtml(cap)}`, icon: 'fa-hashtag' });

    if (filters.length === 0) {
      container.classList.add('hidden');
      return;
    }

    container.classList.remove('hidden');
    filters.forEach(f => {
      const chip = document.createElement('span');
      chip.className = 'inline-flex items-center gap-1 px-2 py-1 bg-gray-100 text-gray-700 text-xs rounded-full';
      chip.innerHTML = `<i class="fas ${f.icon} text-gray-400"></i>${f.label}<button class="ml-1 text-gray-400 hover:text-red-500" data-filter="${f.key}"><i class="fas fa-times"></i></button>`;
      chip.querySelector('button').addEventListener('click', function() {
        const el = document.getElementById(this.dataset.filter);
        if (el) { el.value = ''; table.ajax.reload(); updateActiveFilters(); }
      });
      container.appendChild(chip);
    });
  }

  // Selection handling
  document.getElementById('editori-table').addEventListener('change', function(e) {
    if (e.target.classList.contains('row-select')) {
      const id = parseInt(e.target.dataset.id);
      if (e.target.checked) {
        selectedPublishers.add(id);
      } else {
        selectedPublishers.delete(id);
      }
      updateBulkActionsBar();
      updateSelectAllState();
    }
  });

  document.getElementById('select-all').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.row-select');
    checkboxes.forEach(cb => {
      const id = parseInt(cb.dataset.id);
      cb.checked = this.checked;
      if (this.checked) {
        selectedPublishers.add(id);
      } else {
        selectedPublishers.delete(id);
      }
    });
    updateBulkActionsBar();
  });

  function updateSelectAllState() {
    const all = document.querySelectorAll('.row-select');
    const checked = document.querySelectorAll('.row-select:checked');
    document.getElementById('select-all').checked = all.length > 0 && all.length === checked.length;
  }

  function updateBulkActionsBar() {
    const bar = document.getElementById('bulk-actions-bar');
    document.getElementById('selected-count').textContent = selectedPublishers.size;
    if (selectedPublishers.size > 0) {
      bar.classList.remove('hidden');
    } else {
      bar.classList.add('hidden');
    }
  }

  document.getElementById('deselect-all').addEventListener('click', function() {
    selectedPublishers.clear();
    document.querySelectorAll('.row-select').forEach(cb => cb.checked = false);
    document.getElementById('select-all').checked = false;
    updateBulkActionsBar();
  });

  // Bulk delete
  document.getElementById('bulk-delete').addEventListener('click', async function() {
    if (selectedPublishers.size === 0) return;

    const confirmed = await Swal.fire({
      title: <?= json_encode(__("Conferma eliminazione"), JSON_HEX_TAG) ?>,
      text: <?= json_encode(__("Stai per eliminare %d editori. Questa azione non può essere annullata."), JSON_HEX_TAG) ?>.replace('%d', selectedPublishers.size),
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#dc2626',
      cancelButtonColor: '#6b7280',
      confirmButtonText: <?= json_encode(__("Sì, elimina"), JSON_HEX_TAG) ?>,
      cancelButtonText: <?= json_encode(__("Annulla"), JSON_HEX_TAG) ?>
    });

    if (!confirmed.isConfirmed) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const ids = Array.from(selectedPublishers);

    try {
      const response = await fetch(window.BASE_PATH + '/api/editori/bulk-delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ ids })
      });
      const data = await response.json().catch(() => ({
        success: false,
        error: <?= json_encode(__("Errore nel parsing della risposta"), JSON_HEX_TAG) ?>
      }));

      if (data.success) {
        Swal.fire({ icon: 'success', title: <?= json_encode(__("Eliminati"), JSON_HEX_TAG) ?>, text: data.message, timer: 2000, showConfirmButton: false });
        selectedPublishers.clear();
        updateBulkActionsBar();
        table.ajax.reload();
      } else {
        Swal.fire({ icon: 'error', title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>, text: data.error || data.message });
      }
    } catch (err) {
      console.error(err);
      Swal.fire({ icon: 'error', title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>, text: <?= json_encode(__("Errore di connessione"), JSON_HEX_TAG) ?> });
    }
  });

  // Bulk merge
  document.getElementById('bulk-merge').addEventListener('click', async function() {
    if (selectedPublishers.size < 2) {
      Swal.fire({
        icon: 'warning',
        title: <?= json_encode(__("Seleziona almeno 2 editori"), JSON_HEX_TAG) ?>,
        text: <?= json_encode(__("Per unire gli editori devi selezionarne almeno 2."), JSON_HEX_TAG) ?>
      });
      return;
    }

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const ids = Array.from(selectedPublishers);

    // First get the names of selected publishers
    try {
      const response = await fetch(window.BASE_PATH + '/api/editori/bulk-export', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ ids })
      });
      const result = await response.json().catch(() => ({
        success: false,
        error: <?= json_encode(__("Risposta del server non valida"), JSON_HEX_TAG) ?>
      }));

      if (!result.success || !result.data) {
        Swal.fire({ icon: 'error', title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>, text: <?= json_encode(__("Impossibile recuperare i dati degli editori"), JSON_HEX_TAG) ?> });
        return;
      }

      // Build options for the select
      let optionsHtml = result.data.map(p =>
        `<option value="${p.id}">${escapeHtml(p.nome)}${p.libri_count ? ` (${p.libri_count} ${<?= json_encode(__("libri"), JSON_HEX_TAG) ?>})` : ''}</option>`
      ).join('');

      const { value: formValues } = await Swal.fire({
        title: <?= json_encode(__("Unisci editori"), JSON_HEX_TAG) ?>,
        html: `
          <div class="text-left">
            <p class="text-sm text-gray-600 mb-4">${<?= json_encode(__("Stai per unire"), JSON_HEX_TAG) ?>} ${ids.length} ${<?= json_encode(__("editori. Tutti i libri verranno assegnati all'editore risultante."), JSON_HEX_TAG) ?>}</p>
            <div class="mb-4">
              <label class="block text-sm font-medium text-gray-700 mb-1">${<?= json_encode(__("Editore principale"), JSON_HEX_TAG) ?>}</label>
              <select id="swal-primary-publisher" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                ${optionsHtml}
              </select>
              <p class="text-xs text-gray-500 mt-1">${<?= json_encode(__("I libri degli altri editori verranno assegnati a questo"), JSON_HEX_TAG) ?>}</p>
            </div>
            <div class="mb-4">
              <label class="block text-sm font-medium text-gray-700 mb-1">${<?= json_encode(__("Nuovo nome (opzionale)"), JSON_HEX_TAG) ?>}</label>
              <input id="swal-new-name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="${<?= json_encode(__("Lascia vuoto per mantenere il nome attuale"), JSON_HEX_TAG) ?>}">
              <p class="text-xs text-gray-500 mt-1">${<?= json_encode(__("Se compilato, l'editore principale verrà rinominato"), JSON_HEX_TAG) ?>}</p>
            </div>
          </div>
        `,
        showCancelButton: true,
        confirmButtonColor: '#3b82f6',
        cancelButtonColor: '#6b7280',
        confirmButtonText: <?= json_encode(__("Unisci"), JSON_HEX_TAG) ?>,
        cancelButtonText: <?= json_encode(__("Annulla"), JSON_HEX_TAG) ?>,
        preConfirm: () => {
          return {
            primaryId: parseInt(document.getElementById('swal-primary-publisher').value),
            newName: document.getElementById('swal-new-name').value.trim()
          };
        }
      });

      if (!formValues) return;

      // Perform the merge
      const mergeResponse = await fetch(window.BASE_PATH + '/api/editori/merge', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({
          ids: ids,
          primary_id: formValues.primaryId,
          new_name: formValues.newName
        })
      });

      // Check for HTTP errors
      if (!mergeResponse.ok) {
        const errorData = await mergeResponse.json().catch(() => ({ message: 'HTTP ' + mergeResponse.status }));
        Swal.fire({
          icon: 'error',
          title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>,
          text: errorData.message || errorData.error || <?= json_encode(__("Errore del server"), JSON_HEX_TAG) ?>
        });
        return;
      }

      const mergeResult = await mergeResponse.json().catch(() => ({
        success: false,
        error: <?= json_encode(__("Risposta del server non valida"), JSON_HEX_TAG) ?>
      }));

      if (mergeResult.success) {
        Swal.fire({
          icon: 'success',
          title: <?= json_encode(__("Editori uniti"), JSON_HEX_TAG) ?>,
          text: mergeResult.message,
          timer: 2000,
          showConfirmButton: false
        });
        selectedPublishers.clear();
        updateBulkActionsBar();
        table.ajax.reload();
      } else {
        Swal.fire({ icon: 'error', title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>, text: mergeResult.error || mergeResult.message || <?= json_encode(__("Errore sconosciuto"), JSON_HEX_TAG) ?> });
      }
    } catch (err) {
      console.error(err);
      Swal.fire({ icon: 'error', title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>, text: err.message || <?= json_encode(__("Errore di connessione"), JSON_HEX_TAG) ?> });
    }
  });

  // Bulk export - server-side to get all selected data across pages
  document.getElementById('bulk-export').addEventListener('click', async function() {
    if (selectedPublishers.size === 0) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const ids = Array.from(selectedPublishers);

    try {
      const response = await fetch(window.BASE_PATH + '/api/editori/bulk-export', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ ids })
      });
      const result = await response.json().catch(() => ({
        success: false,
        error: <?= json_encode(__("Errore nel parsing della risposta"), JSON_HEX_TAG) ?>
      }));

      if (!result.success) {
        Swal.fire({ icon: 'error', title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>, text: result.error || result.message || <?= json_encode(__("Errore sconosciuto"), JSON_HEX_TAG) ?> });
        return;
      }

      // Generate CSV from server data
      let csvContent = <?= json_encode(__("Nome") . "," . __("Sito Web") . "," . __("Indirizzo") . "," . __("N. Libri") . "\n", JSON_HEX_TAG) ?>;
      result.data.forEach(row => {
        const nome = (row.nome || '').replace(/"/g, '""');
        const sito = (row.sito_web || '').replace(/"/g, '""');
        const indirizzo = (row.indirizzo || '').replace(/"/g, '""');
        const libri = row.libri_count || 0;
        csvContent += `"${nome}","${sito}","${indirizzo}","${libri}"\n`;
      });

      const blob = new Blob(["\ufeff", csvContent], { type: 'text/csv;charset=utf-8;' });
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = 'editori_export_' + new Date().toISOString().slice(0, 10) + '.csv';
      link.click();
    } catch (err) {
      console.error(err);
      Swal.fire({ icon: 'error', title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>, text: <?= json_encode(__("Errore di connessione"), JSON_HEX_TAG) ?> });
    }
  });

  // Single delete
  window.deletePublisher = function(id) {
    Swal.fire({
      title: <?= json_encode(__("Sei sicuro?"), JSON_HEX_TAG) ?>,
      text: <?= json_encode(__("Questa azione non può essere annullata!"), JSON_HEX_TAG) ?>,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#dc2626',
      cancelButtonColor: '#6b7280',
      confirmButtonText: <?= json_encode(__("Sì, elimina!"), JSON_HEX_TAG) ?>,
      cancelButtonText: <?= json_encode(__("Annulla"), JSON_HEX_TAG) ?>
    }).then((result) => {
      if (result.isConfirmed) {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = `${window.BASE_PATH}/admin/publishers/delete/${id}`;
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'csrf_token';
        inp.value = csrf;
        form.appendChild(inp);
        document.body.appendChild(form);
        form.submit();
      }
    });
  };
});
</script>

<style>
.entity-table-wrap { overflow-x: auto; }
/* Bulk actions bar - rispetta la sidebar */
@media (min-width: 1024px) {
  #bulk-actions-bar { left: 16rem !important; }
}
@media (min-width: 1280px) {
  #bulk-actions-bar { left: 18rem !important; }
}

/* DataTables styling */
table#editori-table { border:1px solid #e5e7eb; border-radius:.5rem; width:100% !important; }
#editori-table thead th { background:#f9fafb; color:#4b5563; font-size:.75rem; font-weight:600; letter-spacing:.025em; padding:.75rem; text-transform:uppercase; }
#editori-table tbody td { border-bottom:1px solid #f3f4f6; font-size:.875rem; padding:.75rem; }
#editori-table tbody tr:hover { background:#f9fafb; }

.dataTables_wrapper .dataTables_length select { background:#fff; border:1px solid #d1d5db; border-radius:.5rem; font-size:.875rem; padding:.375rem 2rem .375rem .5rem; }
.dataTables_wrapper .dataTables_info { color:#4b5563; font-size:.875rem; padding:.75rem 0; }
.dataTables_wrapper .dataTables_paginate .paginate_button { background:#fff; border:1px solid #d1d5db; border-radius:.375rem; font-size:.875rem; margin:0 .125rem; padding:.375rem .625rem; }
.dataTables_wrapper .dataTables_paginate .paginate_button.current { background:#1f2937; border-color:#1f2937; color:#fff !important; }
.dataTables_wrapper .dataTables_paginate .paginate_button.disabled.first,
.dataTables_wrapper .dataTables_paginate .paginate_button.disabled.previous,
.dataTables_wrapper .dataTables_paginate .paginate_button.disabled.next,
.dataTables_wrapper .dataTables_paginate .paginate_button.disabled.last { display: none !important; }

@media (max-width: 639px) {
  .entity-list-card { padding:0; }
  .entity-list-card > .p-4 { padding:1rem; }
  #editori-table { table-layout:fixed; }
  #editori-table col:nth-child(3), #editori-table col:nth-child(4), #editori-table col:nth-child(5),
  #editori-table th:nth-child(3), #editori-table td:nth-child(3),
  #editori-table th:nth-child(4), #editori-table td:nth-child(4),
  #editori-table th:nth-child(5), #editori-table td:nth-child(5) { display:none; }
  #editori-table col:first-child, #editori-table th:first-child, #editori-table td:first-child { width:2.75rem !important; padding-left:.5rem; padding-right:.5rem; }
  #editori-table col:nth-child(2), #editori-table th:nth-child(2), #editori-table td:nth-child(2) { width:auto !important; min-width:0; word-break:normal; }
  #editori-table col:last-child, #editori-table th:last-child, #editori-table td:last-child { width:7rem !important; padding-left:.25rem; padding-right:.25rem; }
  .dataTables_wrapper .top, .dataTables_wrapper .bottom { display:flex; flex-direction:column; align-items:stretch; gap:.5rem; }
  .dataTables_wrapper .dataTables_length, .dataTables_wrapper .dataTables_info, .dataTables_wrapper .dataTables_paginate { float:none !important; text-align:left !important; }
  .dataTables_wrapper .dataTables_paginate { display:flex; flex-wrap:wrap; gap:.25rem; }
  #bulk-actions-bar .btn-primary, #bulk-actions-bar .btn-secondary, #bulk-actions-bar .btn-danger { min-width:0; padding-left:.5rem; padding-right:.5rem; justify-content:center; }
}


@media (min-width: 640px) {
  .entity-filter-grid { align-items:end; display:grid; gap:.75rem; }
  /* Stesso vincolo della griglia autori: le colonne minime piene solo da 1280px. */
  .publisher-filter-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
}
@media (min-width: 1280px) {
  .publisher-filter-grid { grid-template-columns:minmax(16rem, 1fr) minmax(9rem, .45fr) auto auto; }
}
</style>

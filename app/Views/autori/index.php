<?php
/**
 * @var array $data { autori: array }
 */
$title = __("Autori");
$autori = $data['autori'];
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
            <span class="text-gray-900 font-medium"><?= __("Autori") ?></span>
          </nav>
          <h1 class="text-2xl font-bold text-gray-900 flex flex-wrap items-center gap-3">
            <span class="flex items-center gap-3">
              <i class="fas fa-user-edit text-gray-600"></i>
              <?= __("Gestione Autori") ?>
            </span>
            <span id="total-badge" class="text-sm font-normal bg-gray-100 text-gray-600 px-2 py-1 rounded-full w-full md:w-auto"></span>
          </h1>
        </div>
        <div class="flex items-center gap-2">
          <a href="<?= htmlspecialchars(url('/admin/autori/crea'), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors text-sm font-medium inline-flex items-center">
            <i class="fas fa-plus mr-2"></i><?= __("Nuovo Autore") ?>
          </a>
        </div>
      </div>
    </div>

    <!-- Main Card with Integrated Filters -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
      <!-- Primary Filters Bar -->
      <div class="p-4 border-b border-gray-100">
        <div class="flex flex-wrap items-end gap-3">
          <!-- Search Text -->
          <div class="flex-1 min-w-[200px]">
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-search mr-1"></i><?= __("Cerca") ?>
            </label>
            <input id="search_nome" type="text" placeholder="<?= __('Nome, pseudonimo, biografia...') ?>"
                   class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:border-transparent text-sm" />
          </div>

          <!-- Nationality -->
          <div class="w-40">
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-flag mr-1"></i><?= __("Nazionalità") ?>
            </label>
            <input id="search_nazionalita" type="text" placeholder="<?= __('Es. Italiana...') ?>"
                   class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-400 text-sm" />
          </div>

          <!-- Books Count Filter -->
          <div class="w-36">
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-book mr-1"></i><?= __("N. Libri") ?>
            </label>
            <select id="filter_libri_count" class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-400 text-sm">
              <option value=""><?= __("Tutti") ?></option>
              <option value="0"><?= __("Nessun libro") ?></option>
              <option value="1-5">1-5 <?= __("libri") ?></option>
              <option value="6-20">6-20 <?= __("libri") ?></option>
              <option value="21+"><?= __("Più di 20") ?></option>
            </select>
          </div>

          <!-- Toggle Advanced Filters -->
          <button id="toggle-advanced" class="px-3 py-2 text-gray-500 hover:text-gray-700 hover:bg-gray-50 rounded-lg transition-colors text-sm border border-gray-200">
            <i class="fas fa-sliders-h mr-1"></i>
            <span class="hidden sm:inline"><?= __("Altri filtri") ?></span>
            <i id="toggle-advanced-icon" class="fas fa-chevron-down text-xs ml-1 transition-transform"></i>
          </button>

          <!-- Clear All -->
          <button id="clear-filters" class="px-3 py-2 text-gray-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition-colors text-sm" title="<?= __('Cancella tutti i filtri') ?>">
            <i class="fas fa-times"></i>
          </button>
        </div>

        <!-- Active Filters Chips -->
        <div id="active-filters" class="hidden mt-3 flex flex-wrap gap-2"></div>
      </div>

      <!-- Advanced Filters - Collapsible -->
      <div id="advanced-filters" class="hidden border-b border-gray-100 bg-gray-50/50 p-4">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <!-- Pseudonimo -->
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-id-card mr-1"></i><?= __("Pseudonimo") ?>
            </label>
            <input id="search_pseudonimo" type="text" placeholder="<?= __('Cerca pseudonimo...') ?>"
                   class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-400 text-sm" />
          </div>

          <!-- Birth Date From -->
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-birthday-cake mr-1"></i><?= __("Nascita da") ?>
            </label>
            <input id="nascita_from" type="date"
                   class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-400 text-sm" />
          </div>

          <!-- Birth Date To -->
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-birthday-cake mr-1"></i><?= __("Nascita a") ?>
            </label>
            <input id="nascita_to" type="date"
                   class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-400 text-sm" />
          </div>

          <!-- Website -->
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-globe mr-1"></i><?= __("Sito web") ?>
            </label>
            <input id="search_sito" type="text" placeholder="<?= __('URL...') ?>"
                   class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-400 text-sm" />
          </div>
        </div>
      </div>

      <!-- Table -->
      <div class="p-4">
        <div class="md:hidden mb-3 p-2 bg-gray-50 border border-gray-200 rounded-lg text-xs text-gray-600 flex items-center gap-2">
          <i class="fas fa-hand-point-right"></i>
          <span><?= __("Scorri a destra per vedere tutte le colonne") ?></span>
        </div>

        <div class="overflow-x-auto">
          <table id="autori-table" class="display" style="width:100%">
            <thead>
              <tr>
                <th class="text-center">
                  <input type="checkbox" id="select-all" class="w-4 h-4 rounded border-gray-300 text-gray-800 focus:ring-gray-500 cursor-pointer" />
                </th>
                <th><?= __("Nome") ?></th>
                <th><?= __("Pseudonimo") ?></th>
                <th><?= __("Nazionalità") ?></th>
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
      <div class="px-4 py-3 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <span class="text-sm font-medium text-gray-700">
            <span id="selected-count">0</span> <?= __("selezionati") ?>
          </span>
          <button id="deselect-all" class="text-sm text-gray-500 hover:text-gray-700 transition-colors">
            <?= __("Deseleziona tutti") ?>
          </button>
        </div>
        <div class="flex items-center gap-2">
          <button id="bulk-merge" class="px-4 py-2 bg-blue-500 text-white hover:bg-blue-600 rounded-lg transition-colors text-sm">
            <i class="fas fa-compress-arrows-alt mr-2"></i><?= __("Unisci") ?>
          </button>
          <button id="bulk-export" class="px-4 py-2 bg-white text-gray-700 hover:bg-gray-50 rounded-lg transition-colors text-sm border border-gray-300">
            <i class="fas fa-download mr-2"></i><?= __("Esporta") ?>
          </button>
          <button id="bulk-delete" class="px-4 py-2 bg-red-500 text-white hover:bg-red-600 rounded-lg transition-colors text-sm">
            <i class="fas fa-trash mr-2"></i><?= __("Elimina") ?>
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
window.i18nLocale = <?= json_encode(\App\Support\I18n::getLocale()) ?>;
window.__ = function(key) { return key; };

document.addEventListener('DOMContentLoaded', function() {
  if (typeof DataTable === 'undefined') {
    console.error('DataTable is not loaded!');
    return;
  }

  const debounce = (fn, ms=300) => { let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); }; };
  const selectedAuthors = new Set();

  // HTML escape helper to prevent XSS
  const escapeHtml = (str) => {
    if (str === null || str === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
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
  const table = new DataTable('#autori-table', {
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
      url: (window.BASE_PATH || '') + '/api/autori',
      type: 'GET',
      data: function(d) {
        d.search_nome = document.getElementById('search_nome').value;
        d.search_pseudonimo = document.getElementById('search_pseudonimo').value;
        d.search_nazionalita = document.getElementById('search_nazionalita').value;
        d.search_sito = document.getElementById('search_sito').value;
        d.nascita_from = document.getElementById('nascita_from').value;
        d.nascita_to = document.getElementById('nascita_to').value;
        d.filter_libri_count = document.getElementById('filter_libri_count').value;
        return d;
      },
      dataSrc: function(json) {
        document.getElementById('total-badge').textContent = (json.recordsFiltered || 0).toLocaleString() + ' ' + <?= json_encode(__("autori"), JSON_HEX_TAG) ?>;
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
          const checked = selectedAuthors.has(id) ? 'checked' : '';
          return `<input type="checkbox" class="row-select w-4 h-4 rounded border-gray-300 text-gray-800 focus:ring-gray-500 cursor-pointer" data-id="${id}" ${checked} />`;
        }
      },
      { // Name
        data: null,
        width: '250px',
        className: 'align-middle',
        render: function(_, __, row) {
          const nome = escapeHtml(row.nome) || <?= json_encode(__("Autore sconosciuto"), JSON_HEX_TAG) ?>;
          const bio = row.biografia ? `<p class="text-xs text-gray-500 mt-0.5 line-clamp-1">${escapeHtml(row.biografia.substring(0, 80))}...</p>` : '';
          return `<div>
            <a href="${window.BASE_PATH || ''}/admin/autori/${parseInt(row.id, 10)}" class="font-medium text-gray-900 hover:text-gray-700 hover:underline">${nome}</a>
            ${bio}
          </div>`;
        }
      },
      { // Pseudonimo
        data: 'pseudonimo',
        width: '150px',
        className: 'align-middle',
        render: function(data) {
          return data ? `<span class="italic text-gray-600">"${escapeHtml(data)}"</span>` : '<span class="text-gray-400">-</span>';
        }
      },
      { // Nazionalità
        data: 'nazionalita',
        width: '120px',
        className: 'align-middle',
        render: function(data) {
          if (!data) return '<span class="text-gray-400">-</span>';
          return `<span class="inline-flex items-center px-2 py-0.5 rounded text-xs bg-blue-100 text-blue-800">
            <i class="fas fa-flag mr-1"></i>${escapeHtml(data)}
          </span>`;
        }
      },
      { // Numero Libri
        data: null,
        width: '100px',
        className: 'text-center align-middle',
        render: function(_, __, row) {
          const count = row.libri_count || 0;
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
            <a href="${window.BASE_PATH || ''}/admin/autori/${id}" class="w-7 h-7 inline-flex items-center justify-center text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded transition-all" title="${<?= json_encode(__('Visualizza'), JSON_HEX_TAG) ?>}">
              <i class="fas fa-eye text-xs"></i>
            </a>
            <a href="${window.BASE_PATH || ''}/admin/autori/modifica/${id}" class="w-7 h-7 inline-flex items-center justify-center text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded transition-all" title="${<?= json_encode(__('Modifica'), JSON_HEX_TAG) ?>}">
              <i class="fas fa-edit text-xs"></i>
            </a>
            <button onclick="deleteAuthor(${id})" class="w-7 h-7 inline-flex items-center justify-center text-gray-500 hover:text-red-600 hover:bg-red-50 rounded transition-all" title="${<?= json_encode(__('Elimina'), JSON_HEX_TAG) ?>}">
              <i class="fas fa-trash text-xs"></i>
            </button>
          </div>`;
        }
      }
    ],
    order: [[1, 'asc']],
    pageLength: 25,
    lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
    language: (window.i18nLocale === 'en_US' ? window.DT_LANG_EN : window.DT_LANG_IT),
    drawCallback: function() {
      // Restore checkbox states
      document.querySelectorAll('.row-select').forEach(cb => {
        cb.checked = selectedAuthors.has(parseInt(cb.dataset.id));
      });
      updateSelectAllState();
    }
  });

  // Filter handlers
  const reloadDebounced = debounce(() => { table.ajax.reload(); updateActiveFilters(); });
  ['search_nome', 'search_pseudonimo', 'search_nazionalita', 'search_sito', 'nascita_from', 'nascita_to', 'filter_libri_count'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      el.addEventListener('input', reloadDebounced);
      el.addEventListener('change', reloadDebounced);
    }
  });

  // Clear filters
  document.getElementById('clear-filters').addEventListener('click', function() {
    ['search_nome', 'search_pseudonimo', 'search_nazionalita', 'search_sito', 'nascita_from', 'nascita_to'].forEach(id => {
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

    const naz = document.getElementById('search_nazionalita').value;
    if (naz) filters.push({ key: 'search_nazionalita', label: `${<?= json_encode(__("Nazionalità") . ": ", JSON_HEX_TAG) ?>}${escapeHtml(naz)}`, icon: 'fa-flag' });

    const libri = document.getElementById('filter_libri_count').value;
    if (libri) filters.push({ key: 'filter_libri_count', label: `${<?= json_encode(__("Libri") . ": ", JSON_HEX_TAG) ?>}${libri}`, icon: 'fa-book' });

    const pseudo = document.getElementById('search_pseudonimo').value;
    if (pseudo) filters.push({ key: 'search_pseudonimo', label: `${<?= json_encode(__("Pseudonimo") . ": ", JSON_HEX_TAG) ?>}${escapeHtml(pseudo)}`, icon: 'fa-id-card' });

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
  document.getElementById('autori-table').addEventListener('change', function(e) {
    if (e.target.classList.contains('row-select')) {
      const id = parseInt(e.target.dataset.id);
      if (e.target.checked) {
        selectedAuthors.add(id);
      } else {
        selectedAuthors.delete(id);
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
        selectedAuthors.add(id);
      } else {
        selectedAuthors.delete(id);
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
    document.getElementById('selected-count').textContent = selectedAuthors.size;
    if (selectedAuthors.size > 0) {
      bar.classList.remove('hidden');
    } else {
      bar.classList.add('hidden');
    }
  }

  document.getElementById('deselect-all').addEventListener('click', function() {
    selectedAuthors.clear();
    document.querySelectorAll('.row-select').forEach(cb => cb.checked = false);
    document.getElementById('select-all').checked = false;
    updateBulkActionsBar();
  });

  // Bulk delete
  document.getElementById('bulk-delete').addEventListener('click', async function() {
    if (selectedAuthors.size === 0) return;

    const confirmed = await Swal.fire({
      title: <?= json_encode(__("Conferma eliminazione"), JSON_HEX_TAG) ?>,
      text: <?= json_encode(__("Stai per eliminare"), JSON_HEX_TAG) ?> + ' ' + selectedAuthors.size + ' ' + <?= json_encode(__("autori. Questa azione non può essere annullata."), JSON_HEX_TAG) ?>,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#dc2626',
      cancelButtonColor: '#6b7280',
      confirmButtonText: <?= json_encode(__("Sì, elimina"), JSON_HEX_TAG) ?>,
      cancelButtonText: <?= json_encode(__("Annulla"), JSON_HEX_TAG) ?>
    });

    if (!confirmed.isConfirmed) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const ids = Array.from(selectedAuthors);

    try {
      const response = await fetch((window.BASE_PATH || '') + '/api/autori/bulk-delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ ids })
      });
      if (!response.ok) {
        const errorData = await response.json().catch(() => ({ error: 'HTTP ' + response.status }));
        Swal.fire({ icon: 'error', title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>, text: errorData.error || errorData.message || <?= json_encode(__("Errore del server"), JSON_HEX_TAG) ?> });
        return;
      }
      const data = await response.json().catch(() => ({
        success: false,
        error: <?= json_encode(__("Errore nel parsing della risposta"), JSON_HEX_TAG) ?>
      }));

      if (data.success) {
        Swal.fire({ icon: 'success', title: <?= json_encode(__("Eliminati"), JSON_HEX_TAG) ?>, text: data.message, timer: 2000, showConfirmButton: false });
        selectedAuthors.clear();
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
    if (selectedAuthors.size < 2) {
      Swal.fire({
        icon: 'warning',
        title: <?= json_encode(__("Seleziona almeno 2 autori"), JSON_HEX_TAG) ?>,
        text: <?= json_encode(__("Per unire gli autori devi selezionarne almeno 2."), JSON_HEX_TAG) ?>
      });
      return;
    }

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const ids = Array.from(selectedAuthors);

    // First get the names of selected authors
    try {
      const response = await fetch((window.BASE_PATH || '') + '/api/autori/bulk-export', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ ids })
      });
      if (!response.ok) {
        const errorData = await response.json().catch(() => ({ error: 'HTTP ' + response.status }));
        Swal.fire({ icon: 'error', title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>, text: errorData.error || errorData.message || <?= json_encode(__("Errore del server"), JSON_HEX_TAG) ?> });
        return;
      }
      const result = await response.json().catch(() => ({
        success: false,
        error: <?= json_encode(__("Risposta del server non valida"), JSON_HEX_TAG) ?>
      }));

      if (!result.success || !result.data) {
        Swal.fire({ icon: 'error', title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>, text: <?= json_encode(__("Impossibile recuperare i dati degli autori"), JSON_HEX_TAG) ?> });
        return;
      }

      // Build options for the select
      let optionsHtml = result.data.map(a =>
        `<option value="${a.id}">${escapeHtml(a.nome)}${a.libri_count ? ` (${a.libri_count} ${<?= json_encode(__("libri"), JSON_HEX_TAG) ?>})` : ''}</option>`
      ).join('');

      const { value: formValues } = await Swal.fire({
        title: <?= json_encode(__("Unisci autori"), JSON_HEX_TAG) ?>,
        html: `
          <div class="text-left">
            <p class="text-sm text-gray-600 mb-4">${<?= json_encode(__("Stai per unire"), JSON_HEX_TAG) ?>} ${ids.length} ${<?= json_encode(__("autori. Tutti i libri verranno assegnati all'autore risultante."), JSON_HEX_TAG) ?>}</p>
            <div class="mb-4">
              <label class="block text-sm font-medium text-gray-700 mb-1">${<?= json_encode(__("Autore principale"), JSON_HEX_TAG) ?>}</label>
              <select id="swal-primary-author" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                ${optionsHtml}
              </select>
              <p class="text-xs text-gray-500 mt-1">${<?= json_encode(__("I libri degli altri autori verranno assegnati a questo"), JSON_HEX_TAG) ?>}</p>
            </div>
            <div class="mb-4">
              <label class="block text-sm font-medium text-gray-700 mb-1">${<?= json_encode(__("Nuovo nome (opzionale)"), JSON_HEX_TAG) ?>}</label>
              <input id="swal-new-name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="${<?= json_encode(__("Lascia vuoto per mantenere il nome attuale"), JSON_HEX_TAG) ?>}">
              <p class="text-xs text-gray-500 mt-1">${<?= json_encode(__("Se compilato, l'autore principale verrà rinominato"), JSON_HEX_TAG) ?>}</p>
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
            primaryId: parseInt(document.getElementById('swal-primary-author').value),
            newName: document.getElementById('swal-new-name').value.trim()
          };
        }
      });

      if (!formValues) return;

      // Perform the merge
      const mergeResponse = await fetch((window.BASE_PATH || '') + '/api/autori/merge', {
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
          title: <?= json_encode(__("Autori uniti"), JSON_HEX_TAG) ?>,
          text: mergeResult.message,
          timer: 2000,
          showConfirmButton: false
        });
        selectedAuthors.clear();
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
    if (selectedAuthors.size === 0) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const ids = Array.from(selectedAuthors);

    try {
      const response = await fetch((window.BASE_PATH || '') + '/api/autori/bulk-export', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ ids })
      });
      if (!response.ok) {
        const errorData = await response.json().catch(() => ({ error: 'HTTP ' + response.status }));
        Swal.fire({ icon: 'error', title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>, text: errorData.error || errorData.message || <?= json_encode(__("Errore del server"), JSON_HEX_TAG) ?> });
        return;
      }
      const result = await response.json().catch(() => ({
        success: false,
        error: <?= json_encode(__("Errore nel parsing della risposta"), JSON_HEX_TAG) ?>
      }));

      if (!result.success) {
        Swal.fire({ icon: 'error', title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>, text: result.error || result.message || <?= json_encode(__("Errore sconosciuto"), JSON_HEX_TAG) ?> });
        return;
      }

      // Generate CSV from server data
      let csvContent = <?= json_encode(__("Nome") . "," . __("Pseudonimo") . "," . __("Nazionalità") . "," . __("N. Libri") . "\n", JSON_HEX_TAG) ?>;
      result.data.forEach(row => {
        const nome = (row.nome || '').replace(/"/g, '""');
        const pseudo = (row.pseudonimo || '').replace(/"/g, '""');
        const naz = (row.nazionalita || '').replace(/"/g, '""');
        const libri = row.libri_count || 0;
        csvContent += `"${nome}","${pseudo}","${naz}","${libri}"\n`;
      });

      const blob = new Blob(["\ufeff", csvContent], { type: 'text/csv;charset=utf-8;' });
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = 'autori_export_' + new Date().toISOString().slice(0, 10) + '.csv';
      link.click();
    } catch (err) {
      console.error(err);
      Swal.fire({ icon: 'error', title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>, text: <?= json_encode(__("Errore di connessione"), JSON_HEX_TAG) ?> });
    }
  });

  // Single delete
  window.deleteAuthor = function(id) {
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
        form.action = `${window.BASE_PATH || ''}/admin/autori/delete/${id}`;
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
.line-clamp-1 { display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; }

/* Bulk actions bar - rispetta la sidebar */
@media (min-width: 1024px) {
  #bulk-actions-bar { left: 16rem !important; }
}
@media (min-width: 1280px) {
  #bulk-actions-bar { left: 18rem !important; }
}

/* DataTables styling */
table#autori-table { border: 1px solid gainsboro; width: 100% !important; }
#autori-table thead th { @apply bg-gray-50 font-medium text-gray-600 text-xs uppercase tracking-wide border-b border-gray-200 px-3 py-3; }
#autori-table tbody td { @apply px-3 py-3 border-b border-gray-100 text-sm; }
#autori-table tbody tr:hover { @apply bg-gray-50; }

.dataTables_wrapper .dataTables_length select { @apply py-1.5 px-2 text-sm border border-gray-300 rounded-lg bg-white; }
.dataTables_wrapper .dataTables_info { @apply text-sm text-gray-600 py-3; }
/* Pagination buttons - hide disabled navigation buttons */
.dataTables_wrapper .dataTables_paginate .paginate_button { @apply px-3 py-1.5 text-sm border border-gray-300 bg-white hover:bg-gray-50 rounded mx-0.5; }
.dataTables_wrapper .dataTables_paginate .paginate_button.current { @apply bg-gray-800 text-white border-gray-800; }
.dataTables_wrapper .dataTables_paginate .paginate_button.disabled.first,
.dataTables_wrapper .dataTables_paginate .paginate_button.disabled.previous,
.dataTables_wrapper .dataTables_paginate .paginate_button.disabled.next,
.dataTables_wrapper .dataTables_paginate .paginate_button.disabled.last { display: none !important; }
</style>

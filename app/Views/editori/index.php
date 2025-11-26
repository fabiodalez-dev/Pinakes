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
            <a href="/admin/dashboard" class="hover:text-gray-700"><i class="fas fa-home"></i></a>
            <i class="fas fa-chevron-right mx-2 text-xs text-gray-400"></i>
            <span class="text-gray-900 font-medium"><?= __("Editori") ?></span>
          </nav>
          <h1 class="text-2xl font-bold text-gray-900 flex flex-wrap items-center gap-3">
            <span class="flex items-center gap-3">
              <i class="fas fa-building text-gray-600"></i>
              <?= __("Gestione Editori") ?>
            </span>
            <span id="total-badge" class="text-sm font-normal bg-gray-100 text-gray-600 px-2 py-1 rounded-full w-full md:w-auto"></span>
          </h1>
        </div>
        <div class="flex items-center gap-2">
          <a href="/admin/editori/crea" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors text-sm font-medium inline-flex items-center">
            <i class="fas fa-plus mr-2"></i><?= __("Nuovo Editore") ?>
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
            <input id="search_nome" type="text" placeholder="<?= __('Nome editore...') ?>"
                   class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:border-transparent text-sm" />
          </div>

          <!-- City -->
          <div class="w-40">
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-map-marker-alt mr-1"></i><?= __("Città") ?>
            </label>
            <input id="search_citta" type="text" placeholder="<?= __('Es. Milano...') ?>"
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
              <option value="1-10">1-10 <?= __("libri") ?></option>
              <option value="11-50">11-50 <?= __("libri") ?></option>
              <option value="51+"><?= __("Più di 50") ?></option>
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
        <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
          <!-- Website -->
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-globe mr-1"></i><?= __("Sito web") ?>
            </label>
            <input id="search_sito" type="text" placeholder="<?= __('URL...') ?>"
                   class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-400 text-sm" />
          </div>

          <!-- Address -->
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-road mr-1"></i><?= __("Indirizzo") ?>
            </label>
            <input id="search_via" type="text" placeholder="<?= __('Via, numero...') ?>"
                   class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-400 text-sm" />
          </div>

          <!-- CAP -->
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-hashtag mr-1"></i><?= __("CAP") ?>
            </label>
            <input id="search_cap" type="text" placeholder="<?= __('Codice postale...') ?>"
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
      url: '/api/editori',
      type: 'GET',
      data: function(d) {
        d.search_nome = document.getElementById('search_nome').value;
        d.search_text = document.getElementById('search_nome').value;
        d.search_sito = document.getElementById('search_sito').value;
        d.search_citta = document.getElementById('search_citta').value;
        d.search_via = document.getElementById('search_via').value;
        d.search_cap = document.getElementById('search_cap').value;
        d.filter_libri_count = document.getElementById('filter_libri_count').value;
        return d;
      },
      dataSrc: function(json) {
        document.getElementById('total-badge').textContent = (json.recordsFiltered || 0).toLocaleString() + ' <?= __("editori") ?>';
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
          const nome = escapeHtml(row.nome) || '<?= __("Editore sconosciuto") ?>';
          const citta = row.citta ? `<p class="text-xs text-gray-500 mt-0.5"><i class="fas fa-map-marker-alt mr-1"></i>${escapeHtml(row.citta)}</p>` : '';
          return `<div>
            <a href="/admin/editori/${id}" class="font-medium text-gray-900 hover:text-gray-700 hover:underline">${nome}</a>
            ${citta}
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
            <a href="/admin/editori/${id}" class="w-7 h-7 inline-flex items-center justify-center text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded transition-all" title="<?= __('Visualizza') ?>">
              <i class="fas fa-eye text-xs"></i>
            </a>
            <a href="/admin/editori/modifica/${id}" class="w-7 h-7 inline-flex items-center justify-center text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded transition-all" title="<?= __('Modifica') ?>">
              <i class="fas fa-edit text-xs"></i>
            </a>
            <button onclick="deletePublisher(${id})" class="w-7 h-7 inline-flex items-center justify-center text-gray-500 hover:text-red-600 hover:bg-red-50 rounded transition-all" title="<?= __('Elimina') ?>">
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
        cb.checked = selectedPublishers.has(parseInt(cb.dataset.id));
      });
      updateSelectAllState();
    }
  });

  // Filter handlers
  const reloadDebounced = debounce(() => { table.ajax.reload(); updateActiveFilters(); });
  ['search_nome', 'search_sito', 'search_citta', 'search_via', 'search_cap', 'filter_libri_count'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      el.addEventListener('input', reloadDebounced);
      el.addEventListener('change', reloadDebounced);
    }
  });

  // Clear filters
  document.getElementById('clear-filters').addEventListener('click', function() {
    ['search_nome', 'search_sito', 'search_citta', 'search_via', 'search_cap'].forEach(id => {
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
    if (nome) filters.push({ key: 'search_nome', label: `"${nome}"`, icon: 'fa-search' });

    const citta = document.getElementById('search_citta').value;
    if (citta) filters.push({ key: 'search_citta', label: `<?= __("Città") ?>: ${citta}`, icon: 'fa-map-marker-alt' });

    const libri = document.getElementById('filter_libri_count').value;
    if (libri) filters.push({ key: 'filter_libri_count', label: `<?= __("Libri") ?>: ${libri}`, icon: 'fa-book' });

    const sito = document.getElementById('search_sito').value;
    if (sito) filters.push({ key: 'search_sito', label: `<?= __("Sito") ?>: ${sito}`, icon: 'fa-globe' });

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
      title: '<?= __("Conferma eliminazione") ?>',
      text: `<?= __("Stai per eliminare") ?> ${selectedPublishers.size} <?= __("editori. Questa azione non può essere annullata.") ?>`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#dc2626',
      cancelButtonColor: '#6b7280',
      confirmButtonText: '<?= __("Sì, elimina") ?>',
      cancelButtonText: '<?= __("Annulla") ?>'
    });

    if (!confirmed.isConfirmed) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const ids = Array.from(selectedPublishers);

    try {
      const response = await fetch('/api/editori/bulk-delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ ids })
      });
      const data = await response.json();

      if (data.success) {
        Swal.fire({ icon: 'success', title: '<?= __("Eliminati") ?>', text: data.message, timer: 2000, showConfirmButton: false });
        selectedPublishers.clear();
        updateBulkActionsBar();
        table.ajax.reload();
      } else {
        Swal.fire({ icon: 'error', title: '<?= __("Errore") ?>', text: data.error });
      }
    } catch (err) {
      console.error(err);
      Swal.fire({ icon: 'error', title: '<?= __("Errore") ?>', text: '<?= __("Errore di connessione") ?>' });
    }
  });

  // Bulk export - server-side to get all selected data across pages
  document.getElementById('bulk-export').addEventListener('click', async function() {
    if (selectedPublishers.size === 0) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const ids = Array.from(selectedPublishers);

    try {
      const response = await fetch('/api/editori/bulk-export', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ ids })
      });
      const result = await response.json();

      if (!result.success) {
        Swal.fire({ icon: 'error', title: '<?= __("Errore") ?>', text: result.error });
        return;
      }

      // Generate CSV from server data
      let csvContent = '<?= __("Nome") ?>,<?= __("Sito Web") ?>,<?= __("Città") ?>,<?= __("N. Libri") ?>\n';
      result.data.forEach(row => {
        const nome = (row.nome || '').replace(/"/g, '""');
        const sito = (row.sito_web || '').replace(/"/g, '""');
        const citta = (row.citta || '').replace(/"/g, '""');
        const libri = row.libri_count || 0;
        csvContent += `"${nome}","${sito}","${citta}","${libri}"\n`;
      });

      const blob = new Blob(["\ufeff", csvContent], { type: 'text/csv;charset=utf-8;' });
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = 'editori_export_' + new Date().toISOString().slice(0, 10) + '.csv';
      link.click();
    } catch (err) {
      console.error(err);
      Swal.fire({ icon: 'error', title: '<?= __("Errore") ?>', text: '<?= __("Errore di connessione") ?>' });
    }
  });

  // Single delete
  window.deletePublisher = function(id) {
    Swal.fire({
      title: '<?= __("Sei sicuro?") ?>',
      text: '<?= __("Questa azione non può essere annullata!") ?>',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#dc2626',
      cancelButtonColor: '#6b7280',
      confirmButtonText: '<?= __("Sì, elimina!") ?>',
      cancelButtonText: '<?= __("Annulla") ?>'
    }).then((result) => {
      if (result.isConfirmed) {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = `/admin/editori/delete/${id}`;
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
/* Bulk actions bar - rispetta la sidebar */
@media (min-width: 1024px) {
  #bulk-actions-bar { left: 16rem !important; }
}
@media (min-width: 1280px) {
  #bulk-actions-bar { left: 18rem !important; }
}

/* DataTables styling */
table#editori-table { border: 1px solid gainsboro; width: 100% !important; }
#editori-table thead th { @apply bg-gray-50 font-medium text-gray-600 text-xs uppercase tracking-wide border-b border-gray-200 px-3 py-3; }
#editori-table tbody td { @apply px-3 py-3 border-b border-gray-100 text-sm; }
#editori-table tbody tr:hover { @apply bg-gray-50; }

.dataTables_wrapper .dataTables_length select { @apply py-1.5 px-2 text-sm border border-gray-300 rounded-lg bg-white; }
.dataTables_wrapper .dataTables_info { @apply text-sm text-gray-600 py-3; }
.dataTables_wrapper .dataTables_paginate .paginate_button { @apply px-3 py-1.5 text-sm border border-gray-300 bg-white hover:bg-gray-50 rounded mx-0.5; }
.dataTables_wrapper .dataTables_paginate .paginate_button.current { @apply bg-gray-800 text-white border-gray-800; }
.dataTables_wrapper .dataTables_paginate .paginate_button.disabled { @apply opacity-50 cursor-not-allowed; }
</style>

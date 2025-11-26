<?php
/**
 * @var array $data { libri: array }
 */
$title = "Libri";
$libri = $data['libri'];
?>
<!-- Enhanced Books Management Interface -->
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-2">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li>
          <a href="/admin/dashboard" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i><?= __("Home") ?>
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li class="text-gray-900 font-medium">
          <a href="/admin/libri" class="text-gray-900 hover:text-gray-700">
            <i class="fas fa-book mr-1"></i><?= __("Libri") ?>
          </a>
        </li>
      </ol>
    </nav>

    <!-- Header with Actions -->
    <div class="mb-5 fade-in">
      <div class="flex items-center justify-between mb-3">
        <div>
          <h1 class="text-3xl font-bold text-gray-900 flex flex-wrap items-center">
            <span class="flex items-center">
              <i class="fas fa-book text-gray-600 mr-3"></i>
              <?= __("Gestione Libri") ?>
            </span>
            <span id="total-count" class="ml-3 md:ml-3 mt-2 md:mt-0 w-full md:w-auto px-3 py-1 bg-gray-100 text-gray-600 text-sm font-normal rounded-full"></span>
          </h1>
          <p class="text-sm text-gray-600 mt-1"><?= __("Esplora e gestisci la collezione della biblioteca") ?></p>
        </div>
        <div class="hidden md:flex items-center gap-2">
          <!-- View Toggle -->
          <div class="flex items-center bg-gray-100 rounded-lg p-1 border border-gray-200">
            <button id="view-table" class="px-3 py-1.5 rounded-md text-sm font-medium transition-all bg-white shadow-sm text-gray-900" title="<?= __('Vista tabella') ?>">
              <i class="fas fa-list"></i>
            </button>
            <button id="view-grid" class="px-3 py-1.5 rounded-md text-sm font-medium transition-all text-gray-500 hover:text-gray-700" title="<?= __('Vista griglia') ?>">
              <i class="fas fa-th-large"></i>
            </button>
          </div>
          <a href="/admin/libri/export/csv" class="px-3 py-2 bg-white text-gray-700 hover:bg-gray-50 rounded-lg transition-colors duration-200 inline-flex items-center border border-gray-300 text-sm" title="<?= __("Esporta CSV") ?>">
            <i class="fas fa-download mr-2"></i><?= __("Export") ?>
          </a>
          <a href="/admin/libri/import" class="px-3 py-2 bg-white text-gray-700 hover:bg-gray-50 rounded-lg transition-colors duration-200 inline-flex items-center border border-gray-300 text-sm" title="<?= __("Import CSV") ?>">
            <i class="fas fa-upload mr-2"></i><?= __("Import") ?>
          </a>
          <a href="/admin/libri/crea" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center text-sm">
            <i class="fas fa-plus mr-2"></i><?= __("Nuovo Libro") ?>
          </a>
          <!-- Keyboard Shortcuts Help -->
          <button id="shortcuts-help" class="w-8 h-8 flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors" title="<?= __('Scorciatoie da tastiera') ?>">
            <i class="fas fa-keyboard"></i>
          </button>
        </div>
      </div>
      <!-- Mobile Actions -->
      <div class="flex md:hidden gap-2 mb-3">
        <div class="flex items-center bg-gray-100 rounded-lg p-1 border border-gray-200">
          <button id="view-table-mobile" class="px-2 py-1 rounded text-xs font-medium transition-all bg-white shadow-sm text-gray-900">
            <i class="fas fa-list"></i>
          </button>
          <button id="view-grid-mobile" class="px-2 py-1 rounded text-xs font-medium transition-all text-gray-500">
            <i class="fas fa-th-large"></i>
          </button>
        </div>
        <a href="/admin/libri/export/csv" class="flex-1 px-3 py-2 bg-white text-gray-700 hover:bg-gray-50 rounded-lg transition-colors duration-200 inline-flex items-center justify-center border border-gray-300 text-sm">
          <i class="fas fa-download mr-1"></i><?= __("Export") ?>
        </a>
        <a href="/admin/libri/crea" class="flex-1 px-3 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center justify-center text-sm">
          <i class="fas fa-plus mr-1"></i><?= __("Nuovo") ?>
        </a>
      </div>
    </div>

    <!-- Main Card with Integrated Filters -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
      <!-- Primary Filters Bar - Always Visible -->
      <div class="p-4 border-b border-gray-100">
        <div class="flex flex-wrap items-end gap-3">
          <!-- Search Text -->
          <div class="w-full md:flex-1 md:min-w-[200px] md:w-auto">
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-search mr-1"></i><?= __("Cerca") ?>
            </label>
            <input id="search_text" type="text" placeholder="<?= __('Titolo, sottotitolo, descrizione...') ?>"
                   class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-400 focus:border-transparent text-sm" />
          </div>

          <!-- ISBN/EAN -->
          <div class="w-full md:w-44">
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-barcode mr-1"></i>ISBN/EAN
            </label>
            <input id="search_isbn" type="text" placeholder="<?= __('ISBN o EAN...') ?>"
                   class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-400 text-sm" />
          </div>

          <!-- Author Autocomplete -->
          <div class="w-[calc(50%-0.375rem)] md:w-44 relative">
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-user-edit mr-1"></i><?= __("Autore") ?>
            </label>
            <input id="filter_autore" type="text" placeholder="<?= __('Cerca...') ?>" autocomplete="off"
                   class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-400 text-sm" />
            <ul id="filter_autore_suggest" class="autocomplete-suggestions"></ul>
            <input type="hidden" id="autore_id" />
          </div>

          <!-- Publisher Autocomplete -->
          <div class="w-[calc(50%-0.375rem)] md:w-44 relative">
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-building mr-1"></i><?= __("Editore") ?>
            </label>
            <input id="filter_editore" type="text" placeholder="<?= __('Cerca...') ?>" autocomplete="off"
                   class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-400 text-sm" />
            <ul id="filter_editore_suggest" class="autocomplete-suggestions"></ul>
            <input type="hidden" id="editore_filter" />
          </div>

          <!-- Genre Autocomplete -->
          <div class="w-[calc(50%-0.375rem)] md:w-44 relative">
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-tags mr-1"></i><?= __("Genere") ?>
            </label>
            <input id="filter_genere" type="text" placeholder="<?= __('Cerca...') ?>" autocomplete="off"
                   class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-400 text-sm" />
            <ul id="filter_genere_suggest" class="autocomplete-suggestions"></ul>
            <input type="hidden" id="genere_id" />
          </div>

          <!-- Status -->
          <div class="w-[calc(50%-0.375rem)] md:w-36">
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-info-circle mr-1"></i><?= __("Stato") ?>
            </label>
            <select id="stato_filter" class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-400 text-sm">
              <option value=""><?= __("Tutti") ?></option>
              <option value="Disponibile"><?= __("Disponibile") ?></option>
              <option value="Prestato"><?= __("Prestato") ?></option>
              <option value="Riservato"><?= __("Riservato") ?></option>
              <option value="Danneggiato"><?= __("Danneggiato") ?></option>
              <option value="Perso"><?= __("Perso") ?></option>
            </select>
          </div>

          <!-- More Filters Toggle -->
          <button id="toggle-advanced" class="px-3 py-2 text-gray-500 hover:text-gray-700 hover:bg-gray-50 rounded-lg transition-colors text-sm flex items-center gap-1 border border-gray-200">
            <i class="fas fa-sliders-h"></i>
            <span class="hidden sm:inline"><?= __("Altri filtri") ?></span>
            <i id="toggle-advanced-icon" class="fas fa-chevron-down text-xs ml-1 transition-transform"></i>
          </button>

          <!-- Clear All -->
          <button id="clear-filters" class="px-3 py-2 text-gray-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition-colors text-sm" title="<?= __('Cancella tutti i filtri') ?>">
            <i class="fas fa-times"></i>
          </button>

          <!-- Recent Searches -->
          <div class="relative">
            <button id="recent-searches-btn" class="px-3 py-2 text-gray-400 hover:text-gray-600 hover:bg-gray-50 rounded-lg transition-colors text-sm border border-gray-200" type="button" title="<?= __('Ricerche recenti') ?>">
              <i class="fas fa-history"></i>
            </button>
            <div id="recent-searches-dropdown" class="hidden absolute top-full right-0 mt-1 w-64 bg-white border border-gray-200 rounded-lg shadow-lg z-30 max-h-64 overflow-y-auto">
              <div class="p-2 border-b border-gray-100 flex items-center justify-between">
                <span class="text-xs font-medium text-gray-500"><?= __("Ricerche recenti") ?></span>
                <button id="clear-recent-searches" class="text-xs text-gray-400 hover:text-red-500 transition-colors">
                  <i class="fas fa-trash-alt mr-1"></i><?= __("Cancella") ?>
                </button>
              </div>
              <ul id="recent-searches-list" class="py-1"></ul>
              <div id="no-recent-searches" class="hidden p-3 text-center text-sm text-gray-400">
                <?= __("Nessuna ricerca recente") ?>
              </div>
            </div>
          </div>
        </div>

        <!-- Active Filters Chips -->
        <div id="active-filters" class="hidden mt-3 flex flex-wrap gap-2"></div>
      </div>

      <!-- Advanced Filters - Collapsible -->
      <div id="advanced-filters" class="hidden border-b border-gray-100 bg-gray-50/50 p-4">
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
          <!-- Position -->
          <div class="relative">
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-map-marker-alt mr-1"></i><?= __("Posizione") ?>
            </label>
            <input id="filter_posizione" type="text" placeholder="<?= __('Cerca...') ?>" autocomplete="off"
                   class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-400 text-sm" />
            <ul id="filter_posizione_suggest" class="autocomplete-suggestions"></ul>
            <input type="hidden" id="posizione_id" />
          </div>

          <!-- Year From -->
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-calendar mr-1"></i><?= __("Anno da") ?>
            </label>
            <input id="anno_from" type="number" placeholder="<?= __('es. 2020') ?>" min="1800" max="2030"
                   class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-400 text-sm" />
          </div>

          <!-- Year To -->
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-calendar mr-1"></i><?= __("Anno a") ?>
            </label>
            <input id="anno_to" type="number" placeholder="<?= __('es. 2024') ?>" min="1800" max="2030"
                   class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-400 text-sm" />
          </div>

          <!-- Acquisition Date From -->
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-calendar-plus mr-1"></i><?= __("Acquisito da") ?>
            </label>
            <input id="acq_from" type="date"
                   class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-400 text-sm" />
          </div>

          <!-- Acquisition Date To -->
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">
              <i class="fas fa-calendar-plus mr-1"></i><?= __("Acquisito a") ?>
            </label>
            <input id="acq_to" type="date"
                   class="w-full px-3 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-400 text-sm" />
          </div>
        </div>
      </div>

      <!-- Hidden date fields for compatibility -->
      <input type="hidden" id="pub_from" value="" />

      <!-- Table View -->
      <div id="table-view" class="p-4">
        <!-- Mobile scroll hint -->
        <div class="md:hidden mb-3 p-2 bg-gray-50 border border-gray-200 rounded-lg text-xs text-gray-600 flex items-center gap-2">
          <i class="fas fa-hand-point-right"></i>
          <span><?= __("Scorri a destra per vedere tutte le colonne") ?></span>
        </div>

        <div class="overflow-x-auto">
          <table id="libri-table" class="display" style="width:100%">
            <thead>
              <tr>
                <th class="text-center">
                  <input type="checkbox" id="select-all" class="w-4 h-4 rounded border-gray-300 text-gray-800 focus:ring-gray-500 cursor-pointer" />
                </th>
                <th><?= __("Stato") ?></th>
                <th><?= __("Cover") ?></th>
                <th><?= __("Informazioni") ?></th>
                <th><?= __("Genere") ?></th>
                <th><?= __("Posizione") ?></th>
                <th><?= __("Anno") ?></th>
                <th><?= __("Azioni") ?></th>
              </tr>
            </thead>
          </table>
        </div>
      </div>

      <!-- Grid View -->
      <div id="grid-view" class="hidden p-4">
        <div id="grid-container" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
          <!-- Grid items will be populated by JavaScript -->
        </div>
        <div id="grid-pagination" class="mt-6 flex justify-center items-center gap-2">
          <!-- Pagination will be added by JavaScript -->
        </div>
      </div>
    </div>

    <!-- Bulk Actions Bar (Fixed at bottom viewport, respects sidebar) -->
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
            <i class="fas fa-download mr-2"></i><?= __("Esporta selezionati") ?>
          </button>
          <div class="relative">
            <button id="bulk-status-btn" class="px-4 py-2 bg-white text-gray-700 hover:bg-gray-50 rounded-lg transition-colors text-sm border border-gray-300">
              <i class="fas fa-exchange-alt mr-2"></i><?= __("Cambia stato") ?>
              <i class="fas fa-chevron-down ml-1 text-xs"></i>
            </button>
            <div id="bulk-status-dropdown" class="hidden absolute bottom-full mb-2 right-0 w-48 bg-white border border-gray-200 rounded-lg shadow-lg z-50">
              <button data-status="Disponibile" class="bulk-status-option w-full text-left px-4 py-2 text-sm hover:bg-gray-50 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-green-500"></span><?= __("Disponibile") ?>
              </button>
              <button data-status="Prestato" class="bulk-status-option w-full text-left px-4 py-2 text-sm hover:bg-gray-50 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-red-500"></span><?= __("Prestato") ?>
              </button>
              <button data-status="Riservato" class="bulk-status-option w-full text-left px-4 py-2 text-sm hover:bg-gray-50 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-yellow-500"></span><?= __("Riservato") ?>
              </button>
              <button data-status="Danneggiato" class="bulk-status-option w-full text-left px-4 py-2 text-sm hover:bg-gray-50 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-orange-500"></span><?= __("Danneggiato") ?>
              </button>
            </div>
          </div>
          <button id="bulk-delete" class="px-4 py-2 bg-red-500 text-white hover:bg-red-600 rounded-lg transition-colors text-sm">
            <i class="fas fa-trash mr-2"></i><?= __("Elimina") ?>
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Keyboard Shortcuts Modal -->
<div id="shortcuts-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center">
  <div class="bg-white rounded-xl shadow-xl max-w-md w-full mx-4">
    <div class="p-4 border-b border-gray-200 flex items-center justify-between">
      <h3 class="font-semibold text-gray-900 flex items-center gap-2">
        <i class="fas fa-keyboard text-gray-500"></i>
        <?= __("Scorciatoie da tastiera") ?>
      </h3>
      <button id="close-shortcuts" class="text-gray-400 hover:text-gray-600 transition-colors">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="p-4 space-y-3">
      <div class="flex items-center justify-between text-sm">
        <span class="text-gray-600"><?= __("Nuova ricerca") ?></span>
        <kbd class="px-2 py-1 bg-gray-100 rounded text-xs font-mono">/</kbd>
      </div>
      <div class="flex items-center justify-between text-sm">
        <span class="text-gray-600"><?= __("Nuovo libro") ?></span>
        <div class="flex gap-1">
          <kbd class="px-2 py-1 bg-gray-100 rounded text-xs font-mono">Ctrl</kbd>
          <kbd class="px-2 py-1 bg-gray-100 rounded text-xs font-mono">N</kbd>
        </div>
      </div>
      <div class="flex items-center justify-between text-sm">
        <span class="text-gray-600"><?= __("Cancella filtri") ?></span>
        <kbd class="px-2 py-1 bg-gray-100 rounded text-xs font-mono">Esc</kbd>
      </div>
      <div class="flex items-center justify-between text-sm">
        <span class="text-gray-600"><?= __("Seleziona tutti") ?></span>
        <div class="flex gap-1">
          <kbd class="px-2 py-1 bg-gray-100 rounded text-xs font-mono">Ctrl</kbd>
          <kbd class="px-2 py-1 bg-gray-100 rounded text-xs font-mono">A</kbd>
        </div>
      </div>
      <div class="flex items-center justify-between text-sm">
        <span class="text-gray-600"><?= __("Cambia vista") ?></span>
        <div class="flex gap-1">
          <kbd class="px-2 py-1 bg-gray-100 rounded text-xs font-mono">Ctrl</kbd>
          <kbd class="px-2 py-1 bg-gray-100 rounded text-xs font-mono">G</kbd>
        </div>
      </div>
      <div class="flex items-center justify-between text-sm">
        <span class="text-gray-600"><?= __("Mostra questa guida") ?></span>
        <kbd class="px-2 py-1 bg-gray-100 rounded text-xs font-mono">?</kbd>
      </div>
    </div>
  </div>
</div>

<script>
window.i18nLocale = <?= json_encode(\App\Support\I18n::getLocale()) ?>;

document.addEventListener('DOMContentLoaded', function() {
  const urlParams = new URLSearchParams(window.location.search);
  const initialGenere = parseInt(urlParams.get('genere') || urlParams.get('genere_filter') || '0', 10) || 0;
  const initialSottogenere = parseInt(urlParams.get('sottogenere') || urlParams.get('sottogenere_filter') || '0', 10) || 0;

  if (typeof DataTable === 'undefined') {
    console.error('DataTable is not loaded!');
    return;
  }

  // State
  let selectedBooks = new Set();
  let currentView = 'table';
  let gridData = [];
  let gridPage = 1;
  const gridPageSize = 24;

  // Debounce helper
  const debounce = (fn, ms=300) => { let t; return (...args)=>{ clearTimeout(t); t=setTimeout(()=>fn(...args),ms); }; };

  // Recent searches management
  const RECENT_SEARCHES_KEY = 'pinakes_recent_searches';
  const MAX_RECENT_SEARCHES = 10;

  function getRecentSearches() {
    try {
      return JSON.parse(localStorage.getItem(RECENT_SEARCHES_KEY) || '[]');
    } catch { return []; }
  }

  function saveRecentSearch(query) {
    if (!query || query.trim().length < 2) return;
    let searches = getRecentSearches();
    searches = searches.filter(s => s !== query);
    searches.unshift(query);
    searches = searches.slice(0, MAX_RECENT_SEARCHES);
    localStorage.setItem(RECENT_SEARCHES_KEY, JSON.stringify(searches));
  }

  function renderRecentSearches() {
    const list = document.getElementById('recent-searches-list');
    const noResults = document.getElementById('no-recent-searches');
    const searches = getRecentSearches();

    list.innerHTML = '';
    if (searches.length === 0) {
      noResults.classList.remove('hidden');
      return;
    }
    noResults.classList.add('hidden');

    searches.forEach(search => {
      const li = document.createElement('li');
      li.className = 'px-3 py-2 hover:bg-gray-50 cursor-pointer text-sm flex items-center gap-2 text-gray-700';
      li.innerHTML = `<i class="fas fa-history text-gray-400 text-xs"></i><span>${search}</span>`;
      li.addEventListener('click', () => {
        document.getElementById('search_text').value = search;
        document.getElementById('recent-searches-dropdown').classList.add('hidden');
        table.ajax.reload();
      });
      list.appendChild(li);
    });
  }

  // Recent searches toggle
  document.getElementById('recent-searches-btn').addEventListener('click', function(e) {
    e.stopPropagation();
    const dropdown = document.getElementById('recent-searches-dropdown');
    dropdown.classList.toggle('hidden');
    if (!dropdown.classList.contains('hidden')) {
      renderRecentSearches();
    }
  });

  document.getElementById('clear-recent-searches').addEventListener('click', function() {
    localStorage.removeItem(RECENT_SEARCHES_KEY);
    renderRecentSearches();
  });

  // Close dropdown when clicking outside
  document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('recent-searches-dropdown');
    if (!dropdown.contains(e.target) && e.target.id !== 'recent-searches-btn') {
      dropdown.classList.add('hidden');
    }
  });

  // Advanced filters toggle
  document.getElementById('toggle-advanced').addEventListener('click', function() {
    const panel = document.getElementById('advanced-filters');
    const icon = document.getElementById('toggle-advanced-icon');
    panel.classList.toggle('hidden');
    icon.classList.toggle('rotate-180');
  });

  // Initialize DataTable
  const table = new DataTable('#libri-table', {
    processing: true,
    serverSide: true,
    responsive: false,
    scrollX: false,
    autoWidth: true,
    searching: false,
    stateSave: true,
    stateDuration: 60 * 60 * 24,
    dom: '<"top"l>rt<"bottom"ip><"clear">',
    deferRender: true,
    ajax: {
      url: '/api/libri',
      type: 'GET',
      data: function(d) {
        return {
          ...d,
          search_text: document.getElementById('search_text').value,
          search_isbn: document.getElementById('search_isbn').value,
          stato_filter: document.getElementById('stato_filter').value,
          acq_from: document.getElementById('acq_from').value,
          acq_to: document.getElementById('acq_to').value,
          pub_from: document.getElementById('pub_from').value,
          autore_id: document.getElementById('autore_id').value || 0,
          editore_filter: document.getElementById('editore_filter').value || 0,
          genere_filter: document.getElementById('genere_id').value || initialGenere || 0,
          sottogenere_filter: initialSottogenere || 0,
          posizione_id: document.getElementById('posizione_id').value || 0,
          anno_from: document.getElementById('anno_from').value,
          anno_to: document.getElementById('anno_to').value
        };
      },
      dataSrc: function(json) {
        document.getElementById('total-count').textContent = (json.recordsTotal || 0).toLocaleString() + ' ' + window.__('libri');
        gridData = json.data;
        if (currentView === 'grid') renderGrid();
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
          const checked = selectedBooks.has(row.id) ? 'checked' : '';
          return `<input type="checkbox" class="row-select w-4 h-4 rounded border-gray-300 text-gray-800 focus:ring-gray-500 cursor-pointer" data-id="${row.id}" ${checked} />`;
        }
      },
      { // Status with tooltip
        data: null,
        orderable: false,
        searchable: false,
        width: '50px',
        className: 'text-center align-middle',
        render: function(_, __, row) {
          const s = (row.stato || '').toString().trim().toLowerCase();
          let cls = 'bg-gray-400';
          let icon = 'fa-question-circle';
          if (s === 'disponibile') { cls = 'bg-green-500'; icon = 'fa-check-circle'; }
          else if (s === 'prestato' || s === 'non disponibile') { cls = 'bg-red-500'; icon = 'fa-times-circle'; }
          else if (s === 'riservato') { cls = 'bg-yellow-500'; icon = 'fa-clock'; }
          else if (s === 'danneggiato') { cls = 'bg-orange-500'; icon = 'fa-exclamation-circle'; }

          let tooltip = row.stato || '<?= __("Sconosciuto") ?>';
          if (row.prestito_info) {
            tooltip += `\n<?= __("Utente") ?>: ${row.prestito_info.utente}\n<?= __("Scadenza") ?>: ${row.prestito_info.scadenza}`;
            if (row.prestito_info.in_ritardo) {
              tooltip += `\n<?= __("IN RITARDO") ?>`;
            }
          }

          return `<span class="inline-flex items-center justify-center w-6 h-6 rounded-full ${cls} text-white text-xs cursor-help" title="${tooltip.replace(/"/g, '&quot;')}">
            <i class="fas ${icon} text-xs"></i>
          </span>`;
        }
      },
      { // Cover
        data: 'copertina_url',
        orderable: false,
        searchable: false,
        width: '60px',
        className: 'text-center align-middle',
        render: function(data, type, row) {
          const imageUrl = data || '/uploads/copertine/placeholder.jpg';
          return `<div class="w-12 h-16 mx-auto bg-gray-100 rounded shadow-sm overflow-hidden cursor-pointer hover:opacity-80 transition-opacity" onclick='showImageModal(${JSON.stringify(row)})'>
            <img src="${imageUrl}" alt="" class="w-full h-full object-cover" onerror="this.onerror=null; this.src='/uploads/copertine/placeholder.jpg'; this.classList.add('p-2', 'object-contain');">
          </div>`;
        }
      },
      { // Info
        data: null,
        width: '250px',
        className: 'align-top',
        render: function(_, type, row) {
          const titolo = row.titolo || window.__('Senza titolo');
          const sottotitolo = row.sottotitolo ? `<div class="text-xs text-gray-500 italic mt-0.5 line-clamp-1">${row.sottotitolo}</div>` : '';

          let autoriHtml = '';
          if (row.autori) {
            const autoriArray = row.autori.split(', ').slice(0, 2);
            const idsArray = row.autori_order_key ? row.autori_order_key.split(',') : [];
            const linkedAutori = autoriArray.map((nome, i) => {
              const id = idsArray[i];
              if (id) return `<a href="/admin/autori/${id}" class="text-gray-600 hover:text-gray-900 hover:underline">${nome}</a>`;
              return nome;
            });
            autoriHtml = `<div class="text-xs text-gray-600 mt-1"><i class="fas fa-user text-gray-400 mr-1"></i>${linkedAutori.join(', ')}${row.autori.split(', ').length > 2 ? ' ...' : ''}</div>`;
          }

          let editoreHtml = '';
          if (row.editore_nome) {
            editoreHtml = `<div class="text-xs text-gray-500 mt-0.5"><i class="fas fa-building text-gray-400 mr-1"></i>${row.editore_nome}</div>`;
          }

          let isbnHtml = '';
          if (row.isbn13 || row.isbn10) {
            isbnHtml = `<div class="text-xs text-gray-400 mt-0.5 font-mono">${row.isbn13 || row.isbn10}</div>`;
          }

          return `<div class="min-w-0">
            <a href="/admin/libri/${row.id}" class="font-medium text-gray-900 hover:text-gray-700 hover:underline line-clamp-2 leading-tight">${titolo}</a>
            ${sottotitolo}${autoriHtml}${editoreHtml}${isbnHtml}
          </div>`;
        }
      },
      { // Genre
        data: 'genere_display',
        width: '120px',
        className: 'text-sm align-middle',
        render: function(data) {
          if (!data || data.trim() === '') return '<span class="text-gray-400 text-xs">-</span>';
          const genres = data.split(' / ');
          return genres.map((g, i) =>
            `<span class="inline-block px-2 py-0.5 rounded text-xs ${i === 0 ? 'bg-gray-200 text-gray-800' : 'bg-gray-100 text-gray-600'} mb-0.5">${g}</span>`
          ).join('<br>');
        }
      },
      { // Position
        data: 'posizione_display',
        width: '120px',
        className: 'text-xs align-middle',
        render: function(data) {
          if (!data || data === 'N/D') return '<span class="text-gray-400 text-xs">-</span>';
          const parts = data.split(' - ').slice(0, 2);
          return `<div class="text-xs leading-tight">${parts.map((p, i) => `<div class="${i === 0 ? 'font-medium text-gray-700' : 'text-gray-500'}">${p}</div>`).join('')}</div>`;
        }
      },
      { // Year
        data: 'anno_pubblicazione_formatted',
        width: '60px',
        className: 'text-center align-middle',
        render: function(data) {
          if (!data) return '<span class="text-gray-400">-</span>';
          return `<span class="text-xs font-mono text-gray-600">${data}</span>`;
        }
      },
      { // Actions
        data: 'id',
        orderable: false,
        searchable: false,
        width: '100px',
        className: 'text-center align-middle',
        render: function(data, type, row) {
          return `<div class="flex items-center justify-center gap-0.5">
            <a href="/admin/libri/${data}" class="w-7 h-7 inline-flex items-center justify-center text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded transition-all" title="<?= __('Visualizza') ?>">
              <i class="fas fa-eye text-xs"></i>
            </a>
            <a href="/admin/libri/modifica/${data}" class="w-7 h-7 inline-flex items-center justify-center text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded transition-all" title="<?= __('Modifica') ?>">
              <i class="fas fa-edit text-xs"></i>
            </a>
            <div class="relative">
              <button onclick="toggleStatusMenu(${data}, event)" class="w-7 h-7 inline-flex items-center justify-center text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded transition-all" title="<?= __('Cambia stato') ?>">
                <i class="fas fa-exchange-alt text-xs"></i>
              </button>
              <div id="status-menu-${data}" class="hidden absolute right-0 top-full mt-1 w-36 bg-white border border-gray-200 rounded-lg shadow-lg z-20">
                <button onclick="changeBookStatus(${data}, 'Disponibile')" class="w-full text-left px-3 py-1.5 text-xs hover:bg-gray-50 flex items-center gap-2">
                  <span class="w-2 h-2 rounded-full bg-green-500"></span><?= __("Disponibile") ?>
                </button>
                <button onclick="changeBookStatus(${data}, 'Prestato')" class="w-full text-left px-3 py-1.5 text-xs hover:bg-gray-50 flex items-center gap-2">
                  <span class="w-2 h-2 rounded-full bg-red-500"></span><?= __("Prestato") ?>
                </button>
                <button onclick="changeBookStatus(${data}, 'Riservato')" class="w-full text-left px-3 py-1.5 text-xs hover:bg-gray-50 flex items-center gap-2">
                  <span class="w-2 h-2 rounded-full bg-yellow-500"></span><?= __("Riservato") ?>
                </button>
              </div>
            </div>
            <button onclick="deleteBook(${data})" class="w-7 h-7 inline-flex items-center justify-center text-gray-500 hover:text-red-500 hover:bg-red-50 rounded transition-all" title="<?= __('Elimina') ?>">
              <i class="fas fa-trash text-xs"></i>
            </button>
          </div>`;
        }
      }
    ],
    order: [[3, 'asc']],
    pageLength: 25,
    lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
    language: (window.i18nLocale === 'en_US' ? window.DT_LANG_EN : window.DT_LANG_IT),
    drawCallback: function() {
      // Reattach checkbox handlers
      document.querySelectorAll('.row-select').forEach(cb => {
        cb.addEventListener('change', handleRowSelect);
      });
      updateBulkActionsBar();
    }
  });

  // Filter event handlers
  const reloadDebounced = debounce(() => {
    const searchText = document.getElementById('search_text').value;
    if (searchText && searchText.trim().length >= 2) {
      saveRecentSearch(searchText.trim());
    }
    table.ajax.reload();
    updateActiveFilters();
  });

  ['search_text', 'search_isbn', 'stato_filter', 'acq_from', 'acq_to', 'anno_from', 'anno_to'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      el.addEventListener('input', reloadDebounced);
      el.addEventListener('change', reloadDebounced);
    }
  });

  // Active filters display
  function updateActiveFilters() {
    const container = document.getElementById('active-filters');
    container.innerHTML = '';
    const filters = [];

    const searchText = document.getElementById('search_text').value;
    if (searchText) filters.push({ key: 'search_text', label: `"${searchText}"`, icon: 'fa-search' });

    const isbn = document.getElementById('search_isbn').value;
    if (isbn) filters.push({ key: 'search_isbn', label: `ISBN/EAN: ${isbn}`, icon: 'fa-barcode' });

    const autore = document.getElementById('filter_autore').value;
    if (autore && document.getElementById('autore_id').value) {
      filters.push({ key: 'autore', label: `<?= __("Autore") ?>: ${autore}`, icon: 'fa-user' });
    }

    const editore = document.getElementById('filter_editore').value;
    if (editore && document.getElementById('editore_filter').value) {
      filters.push({ key: 'editore', label: `<?= __("Editore") ?>: ${editore}`, icon: 'fa-building' });
    }

    const stato = document.getElementById('stato_filter').value;
    if (stato) filters.push({ key: 'stato_filter', label: `<?= __("Stato") ?>: ${stato}`, icon: 'fa-info-circle' });

    const genere = document.getElementById('filter_genere').value;
    if (genere && document.getElementById('genere_id').value) {
      filters.push({ key: 'genere', label: `<?= __("Genere") ?>: ${genere}`, icon: 'fa-tags' });
    }

    if (filters.length === 0) {
      container.classList.add('hidden');
      return;
    }

    container.classList.remove('hidden');
    filters.forEach(f => {
      const chip = document.createElement('span');
      chip.className = 'inline-flex items-center gap-1.5 px-2.5 py-1 bg-gray-100 text-gray-700 rounded-full text-xs';
      chip.innerHTML = `<i class="fas ${f.icon} text-gray-400"></i>${f.label}<button class="ml-1 text-gray-400 hover:text-red-500 transition-colors" data-clear="${f.key}"><i class="fas fa-times"></i></button>`;
      chip.querySelector('button').addEventListener('click', () => clearFilter(f.key));
      container.appendChild(chip);
    });
  }

  function clearFilter(key) {
    if (key === 'search_text') document.getElementById('search_text').value = '';
    else if (key === 'search_isbn') document.getElementById('search_isbn').value = '';
    else if (key === 'autore') { document.getElementById('filter_autore').value = ''; document.getElementById('autore_id').value = ''; }
    else if (key === 'editore') { document.getElementById('filter_editore').value = ''; document.getElementById('editore_filter').value = ''; }
    else if (key === 'stato_filter') document.getElementById('stato_filter').value = '';
    else if (key === 'genere') { document.getElementById('filter_genere').value = ''; document.getElementById('genere_id').value = ''; }
    table.ajax.reload();
    updateActiveFilters();
  }

  // Clear all filters
  document.getElementById('clear-filters').addEventListener('click', function() {
    ['search_text', 'search_isbn', 'stato_filter', 'acq_from', 'acq_to', 'anno_from', 'anno_to', 'filter_autore', 'filter_editore', 'filter_genere', 'filter_posizione'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    ['autore_id', 'editore_filter', 'genere_id', 'posizione_id'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    selectedBooks.clear();
    table.ajax.reload();
    updateActiveFilters();
    updateBulkActionsBar();
  });

  // Autocomplete
  async function fetchJSON(url) {
    try { return await (await fetch(url)).json(); } catch { return []; }
  }

  function setupAutocomplete(inputId, suggestId, url, onSelect) {
    const input = document.getElementById(inputId);
    const suggest = document.getElementById(suggestId);
    if (!input || !suggest) return;

    input.addEventListener('input', debounce(async function() {
      const q = this.value.trim();
      if (!q) { suggest.classList.add('hidden'); return; }

      const data = await fetchJSON(url + encodeURIComponent(q));
      suggest.innerHTML = '';

      if (data.length === 0) {
        suggest.innerHTML = '<li class="px-3 py-2 text-gray-400 text-sm"><?= __("Nessun risultato") ?></li>';
      } else {
        data.slice(0, 6).forEach(item => {
          const li = document.createElement('li');
          li.className = 'px-3 py-2 hover:bg-gray-50 cursor-pointer text-sm';
          li.textContent = item.label;
          li.onclick = () => { onSelect(item); suggest.classList.add('hidden'); table.ajax.reload(); updateActiveFilters(); };
          suggest.appendChild(li);
        });
      }
      suggest.classList.remove('hidden');
    }, 250));

    input.addEventListener('blur', () => setTimeout(() => suggest.classList.add('hidden'), 200));
  }

  setupAutocomplete('filter_autore', 'filter_autore_suggest', '/api/search/autori?q=', item => {
    document.getElementById('autore_id').value = item.id;
    document.getElementById('filter_autore').value = item.label;
  });
  setupAutocomplete('filter_editore', 'filter_editore_suggest', '/api/search/editori?q=', item => {
    document.getElementById('editore_filter').value = item.id;
    document.getElementById('filter_editore').value = item.label;
  });
  setupAutocomplete('filter_genere', 'filter_genere_suggest', '/api/search/generi?q=', item => {
    document.getElementById('genere_id').value = item.id;
    document.getElementById('filter_genere').value = item.label;
  });
  setupAutocomplete('filter_posizione', 'filter_posizione_suggest', '/api/search/collocazione?q=', item => {
    document.getElementById('posizione_id').value = item.id;
    document.getElementById('filter_posizione').value = item.label;
  });

  // Bulk selection
  function handleRowSelect(e) {
    const id = parseInt(e.target.dataset.id);
    if (e.target.checked) selectedBooks.add(id);
    else selectedBooks.delete(id);
    updateBulkActionsBar();
    document.getElementById('select-all').checked = selectedBooks.size > 0 && selectedBooks.size === document.querySelectorAll('.row-select').length;
  }

  document.getElementById('select-all').addEventListener('change', function() {
    document.querySelectorAll('.row-select').forEach(cb => {
      cb.checked = this.checked;
      const id = parseInt(cb.dataset.id);
      if (this.checked) selectedBooks.add(id);
      else selectedBooks.delete(id);
    });
    updateBulkActionsBar();
  });

  function updateBulkActionsBar() {
    const bar = document.getElementById('bulk-actions-bar');
    const count = document.getElementById('selected-count');
    count.textContent = selectedBooks.size;

    if (selectedBooks.size > 0) {
      bar.classList.remove('hidden');
    } else {
      bar.classList.add('hidden');
    }
  }

  document.getElementById('deselect-all').addEventListener('click', function() {
    selectedBooks.clear();
    document.querySelectorAll('.row-select').forEach(cb => cb.checked = false);
    document.getElementById('select-all').checked = false;
    updateBulkActionsBar();
  });

  // Bulk status dropdown
  document.getElementById('bulk-status-btn').addEventListener('click', function(e) {
    e.stopPropagation();
    document.getElementById('bulk-status-dropdown').classList.toggle('hidden');
  });

  document.querySelectorAll('.bulk-status-option').forEach(btn => {
    btn.addEventListener('click', function() {
      const status = this.dataset.status;
      bulkChangeStatus(status);
    });
  });

  async function bulkChangeStatus(status) {
    if (selectedBooks.size === 0) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const ids = Array.from(selectedBooks);

    try {
      const response = await fetch('/api/libri/bulk-status', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ ids, stato: status })
      });
      const data = await response.json();

      if (data.success) {
        if (window.Swal) {
          Swal.fire({ icon: 'success', title: '<?= __("Stato aggiornato") ?>', text: `${ids.length} <?= __("libri aggiornati") ?>`, timer: 2000, showConfirmButton: false });
        }
        selectedBooks.clear();
        table.ajax.reload();
        updateBulkActionsBar();
      }
    } catch (err) {
      console.error(err);
    }
    document.getElementById('bulk-status-dropdown').classList.add('hidden');
  }

  // Bulk delete
  document.getElementById('bulk-delete').addEventListener('click', function() {
    if (selectedBooks.size === 0) return;

    if (window.Swal) {
      Swal.fire({
        title: '<?= __("Eliminare i libri selezionati?") ?>',
        text: `<?= __("Stai per eliminare") ?> ${selectedBooks.size} <?= __("libri. Questa azione non può essere annullata.") ?>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: '<?= __("Sì, elimina") ?>',
        cancelButtonText: '<?= __("Annulla") ?>'
      }).then(async (result) => {
        if (result.isConfirmed) {
          const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
          const ids = Array.from(selectedBooks);

          try {
            const response = await fetch('/api/libri/bulk-delete', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
              body: JSON.stringify({ ids })
            });
            const data = await response.json();

            if (data.success) {
              Swal.fire({ icon: 'success', title: '<?= __("Eliminati") ?>', text: `${ids.length} <?= __("libri eliminati") ?>`, timer: 2000, showConfirmButton: false });
              selectedBooks.clear();
              table.ajax.reload();
              updateBulkActionsBar();
            }
          } catch (err) {
            console.error(err);
          }
        }
      });
    }
  });

  // Bulk export
  document.getElementById('bulk-export').addEventListener('click', function() {
    if (selectedBooks.size === 0) return;
    const ids = Array.from(selectedBooks).join(',');
    window.location.href = `/admin/libri/export/csv?ids=${ids}`;
  });

  // View toggle
  function setView(view) {
    currentView = view;
    const tableView = document.getElementById('table-view');
    const gridView = document.getElementById('grid-view');
    const btnTable = document.getElementById('view-table');
    const btnGrid = document.getElementById('view-grid');
    const btnTableMobile = document.getElementById('view-table-mobile');
    const btnGridMobile = document.getElementById('view-grid-mobile');

    if (view === 'table') {
      tableView.classList.remove('hidden');
      gridView.classList.add('hidden');
      btnTable.classList.add('bg-white', 'shadow-sm', 'text-gray-900');
      btnTable.classList.remove('text-gray-500');
      btnGrid.classList.remove('bg-white', 'shadow-sm', 'text-gray-900');
      btnGrid.classList.add('text-gray-500');
      btnTableMobile?.classList.add('bg-white', 'shadow-sm', 'text-gray-900');
      btnTableMobile?.classList.remove('text-gray-500');
      btnGridMobile?.classList.remove('bg-white', 'shadow-sm', 'text-gray-900');
      btnGridMobile?.classList.add('text-gray-500');
    } else {
      tableView.classList.add('hidden');
      gridView.classList.remove('hidden');
      btnGrid.classList.add('bg-white', 'shadow-sm', 'text-gray-900');
      btnGrid.classList.remove('text-gray-500');
      btnTable.classList.remove('bg-white', 'shadow-sm', 'text-gray-900');
      btnTable.classList.add('text-gray-500');
      btnGridMobile?.classList.add('bg-white', 'shadow-sm', 'text-gray-900');
      btnGridMobile?.classList.remove('text-gray-500');
      btnTableMobile?.classList.remove('bg-white', 'shadow-sm', 'text-gray-900');
      btnTableMobile?.classList.add('text-gray-500');
      renderGrid();
    }
  }

  document.getElementById('view-table').addEventListener('click', () => setView('table'));
  document.getElementById('view-grid').addEventListener('click', () => setView('grid'));
  document.getElementById('view-table-mobile')?.addEventListener('click', () => setView('table'));
  document.getElementById('view-grid-mobile')?.addEventListener('click', () => setView('grid'));

  function renderGrid() {
    const container = document.getElementById('grid-container');
    const start = (gridPage - 1) * gridPageSize;
    const items = gridData.slice(start, start + gridPageSize);

    container.innerHTML = items.map(book => {
      const img = book.copertina_url || '/uploads/copertine/placeholder.jpg';
      const statusClass = (book.stato || '').toLowerCase() === 'disponibile' ? 'bg-green-500' :
                          (book.stato || '').toLowerCase() === 'prestato' ? 'bg-red-500' : 'bg-yellow-500';
      return `
        <div class="group relative bg-white rounded-lg border border-gray-200 overflow-hidden hover:shadow-md transition-shadow h-full flex flex-col">
          <a href="/admin/libri/${book.id}" class="flex flex-col h-full">
            <div class="aspect-[2/3] bg-gray-100 relative flex-shrink-0">
              <img src="${img}" alt="" class="w-full h-full object-cover" onerror="this.src='/uploads/copertine/placeholder.jpg'">
              <span class="absolute top-2 right-2 w-3 h-3 rounded-full ${statusClass} ring-2 ring-white"></span>
            </div>
            <div class="p-3 mt-auto">
              <h3 class="font-medium text-sm text-gray-900 line-clamp-2 leading-tight">${book.titolo || '<?= __("Senza titolo") ?>'}</h3>
              <p class="text-xs text-gray-500 mt-1 line-clamp-1">${book.autori || ''}</p>
              ${book.anno_pubblicazione_formatted ? `<p class="text-xs text-gray-400 mt-0.5">${book.anno_pubblicazione_formatted}</p>` : ''}
            </div>
          </a>
        </div>
      `;
    }).join('');
  }

  // Keyboard shortcuts
  document.addEventListener('keydown', function(e) {
    // Ignore if typing in input
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
      if (e.key === 'Escape') {
        e.target.blur();
        document.getElementById('clear-filters').click();
      }
      return;
    }

    if (e.key === '/') {
      e.preventDefault();
      document.getElementById('search_text').focus();
    } else if (e.key === '?' && !e.ctrlKey && !e.metaKey) {
      e.preventDefault();
      document.getElementById('shortcuts-modal').classList.remove('hidden');
    } else if (e.key === 'Escape') {
      document.getElementById('shortcuts-modal').classList.add('hidden');
      document.getElementById('clear-filters').click();
    } else if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
      e.preventDefault();
      window.location.href = '/admin/libri/crea';
    } else if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
      e.preventDefault();
      document.getElementById('select-all').click();
    } else if ((e.ctrlKey || e.metaKey) && e.key === 'g') {
      e.preventDefault();
      setView(currentView === 'table' ? 'grid' : 'table');
    }
  });

  document.getElementById('shortcuts-help').addEventListener('click', () => {
    document.getElementById('shortcuts-modal').classList.remove('hidden');
  });
  document.getElementById('close-shortcuts').addEventListener('click', () => {
    document.getElementById('shortcuts-modal').classList.add('hidden');
  });
  document.getElementById('shortcuts-modal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.add('hidden');
  });

  // Status menu toggle
  window.toggleStatusMenu = function(id, e) {
    e.stopPropagation();
    document.querySelectorAll('[id^="status-menu-"]').forEach(m => {
      if (m.id !== `status-menu-${id}`) m.classList.add('hidden');
    });
    document.getElementById(`status-menu-${id}`).classList.toggle('hidden');
  };

  document.addEventListener('click', () => {
    document.querySelectorAll('[id^="status-menu-"]').forEach(m => m.classList.add('hidden'));
    document.getElementById('bulk-status-dropdown').classList.add('hidden');
  });

  // Quick status change
  window.changeBookStatus = async function(id, status) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    try {
      const response = await fetch('/api/libri/bulk-status', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ ids: [id], stato: status })
      });
      const data = await response.json();
      if (data.success) {
        table.ajax.reload(null, false);
      }
    } catch (err) {
      console.error(err);
    }
  };

  // Delete book
  window.deleteBook = function(bookId) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    if (window.Swal) {
      Swal.fire({
        title: '<?= __("Sei sicuro?") ?>',
        text: '<?= __("Questa azione non può essere annullata!") ?>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: '<?= __("Sì, elimina") ?>',
        cancelButtonText: '<?= __("Annulla") ?>'
      }).then((result) => {
        if (result.isConfirmed) {
          const form = document.createElement('form');
          form.method = 'POST';
          form.action = `/admin/libri/delete/${bookId}`;
          form.innerHTML = `<input type="hidden" name="csrf_token" value="${csrf}">`;
          document.body.appendChild(form);
          form.submit();
        }
      });
    }
  };

  // Image modal
  window.showImageModal = function(bookData) {
    const img = bookData.copertina_url || '/uploads/copertine/placeholder.jpg';
    if (window.Swal) {
      Swal.fire({
        html: `
          <div class="text-left">
            <img src="${img}" class="w-full max-h-96 object-contain rounded-lg mb-4" onerror="this.src='/uploads/copertine/placeholder.jpg'">
            <h3 class="font-semibold text-lg">${bookData.titolo || ''}</h3>
            ${bookData.autori ? `<p class="text-sm text-gray-600 mt-1">${bookData.autori}</p>` : ''}
            ${bookData.editore_nome ? `<p class="text-sm text-gray-500">${bookData.editore_nome}</p>` : ''}
            <div class="flex gap-2 mt-4">
              <a href="/admin/libri/${bookData.id}" class="flex-1 px-4 py-2 bg-gray-800 text-white text-center rounded-lg text-sm hover:bg-gray-700"><?= __("Dettagli") ?></a>
              <a href="/admin/libri/modifica/${bookData.id}" class="flex-1 px-4 py-2 bg-gray-100 text-gray-800 text-center rounded-lg text-sm hover:bg-gray-200"><?= __("Modifica") ?></a>
            </div>
          </div>
        `,
        showConfirmButton: false,
        showCloseButton: true,
        width: '400px'
      });
    }
  };
});
</script>

<style>
.autocomplete-suggestions {
  @apply absolute z-30 bg-white border border-gray-200 rounded-lg mt-1 w-full hidden shadow-lg max-h-48 overflow-y-auto;
}

.line-clamp-1 { display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; }
.line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

.fade-in { animation: fadeIn 0.3s ease-out; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

/* Bulk actions bar - rispetta la sidebar */
@media (min-width: 1024px) {
  #bulk-actions-bar { left: 16rem !important; } /* lg:left-64 = 256px = 16rem */
}
@media (min-width: 1280px) {
  #bulk-actions-bar { left: 18rem !important; } /* xl:left-72 = 288px = 18rem */
}

/* DataTables styling */
table#libri-table { border: 1px solid gainsboro; width: 100% !important; }
#libri-table thead th { @apply bg-gray-50 font-medium text-gray-600 text-xs uppercase tracking-wide border-b border-gray-200 px-2 py-3; }
#libri-table tbody td { @apply px-2 py-3 border-b border-gray-100 text-sm; }
#libri-table tbody tr:hover { @apply bg-gray-50; }

/* Info column text wrapping */
#libri-table tbody td:nth-child(4) { white-space: normal !important; word-wrap: break-word; }

.dataTables_wrapper .dataTables_length select { @apply py-1.5 px-2 text-sm border border-gray-300 rounded-lg bg-white; }
.dataTables_wrapper .dataTables_info { @apply text-sm text-gray-600 py-3; }
.dataTables_wrapper .dataTables_paginate .paginate_button { @apply px-3 py-1.5 text-sm border border-gray-300 bg-white hover:bg-gray-50 rounded mx-0.5; }
.dataTables_wrapper .dataTables_paginate .paginate_button.current { @apply bg-gray-800 text-white border-gray-800; }
.dataTables_wrapper .dataTables_paginate .paginate_button.disabled { @apply opacity-50 cursor-not-allowed; }

@media (max-width: 768px) {
  .dataTables_wrapper .dataTables_length,
  .dataTables_wrapper .dataTables_info { @apply text-xs; }

  /* Hide cover column on mobile */
  #libri-table thead th:nth-child(3),
  #libri-table tbody td:nth-child(3) { display: none; }
}
</style>

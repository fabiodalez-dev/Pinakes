<?php
/**
 * @var array $data { libri: array }
 */
$title = "Libri";
$libri = $data['libri'];
?>
<!-- Minimal White Books Management Interface -->
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
    <!-- Minimal Header -->
    <div class="mb-6 fade-in">
      <div class="flex items-center justify-between mb-3">
        <div>
          <h1 class="text-3xl font-bold text-gray-900 flex items-center">
            <i class="fas fa-book text-gray-600 mr-3"></i>
            <?= __("Gestione Libri") ?>
          </h1>
          <p class="text-sm text-gray-600 mt-1"><?= __("Esplora e gestisci la collezione della biblioteca") ?></p>
        </div>
        <div class="hidden md:flex items-center gap-3">
          <div class="hidden md:block">
            <input id="global_search" type="text" placeholder="<?= __('Cerca rapido...') ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 w-64" />
          </div>
          <a href="/admin/libri/import" class="px-4 py-2 bg-gray-100 text-gray-900 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center border border-gray-300" title="<?= __("Import massivo da CSV") ?>">
            <i class="fas fa-file-csv mr-2"></i>
            <?= __("Import CSV") ?>
          </a>
          <a href="/admin/libri/crea" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center">
            <i class="fas fa-plus mr-2"></i>
            <?= __("Nuovo Libro") ?>
          </a>
        </div>
      </div>
      <div class="flex md:hidden gap-3 mb-3">
        <a href="/admin/libri/import" class="flex-1 px-4 py-2 bg-gray-100 text-gray-900 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center justify-center border border-gray-300" title="<?= __("Import massivo da CSV") ?>">
          <i class="fas fa-file-csv mr-2"></i>
          <?= __("Import CSV") ?>
        </a>
        <a href="/admin/libri/crea" class="flex-1 px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center justify-center">
          <i class="fas fa-plus mr-2"></i>
          <?= __("Nuovo Libro") ?>
        </a>
      </div>
    </div>

    <!-- White Filters Card -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-5 slide-in-up">
      <div class="p-6 border-b border-gray-200">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-filter text-gray-600 mr-2"></i>
          <?= __("Filtri di Ricerca") ?>
        </h2>
        <button id="toggle-filters" class="text-sm text-gray-600 hover:text-gray-800">
          <i class="fas fa-chevron-up"></i>
          <span><?= __("Nascondi filtri") ?></span>
        </button>
      </div>
      <div class="p-6" id="filters-container">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 mb-8">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-search mr-1 text-gray-500"></i>
              <?= __("Cerca testo") ?>
            </label>
            <input id="search_text" placeholder="<?= __('Titolo, sottotitolo, descrizione...') ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-barcode mr-1 text-gray-500"></i>
              ISBN
            </label>
            <input id="search_isbn" placeholder="<?= __('ISBN10 o ISBN13') ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-info-circle mr-1 text-gray-500"></i>
              Stato
            </label>
            <select id="stato_filter" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full">
              <option value=""><?= __("Tutti gli stati") ?></option>
              <option value="Disponibile"><?= __("Disponibile") ?></option>
              <option value="Prestato"><?= __("Prestato") ?></option>
              <option value="Riservato"><?= __("Riservato") ?></option>
              <option value="Danneggiato"><?= __("Danneggiato") ?></option>
              <option value="Perso"><?= __("Perso") ?></option>
              <option value="In Riparazione"><?= __("In Riparazione") ?></option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-calendar mr-1 text-gray-500"></i>
              <?= __("Data acquisizione da") ?>
            </label>
            <input id="acq_from" type="date" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" />
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-calendar mr-1 text-gray-500"></i>
              <?= __("Data acquisizione a") ?>
            </label>
            <input id="acq_to" type="date" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-calendar-alt mr-1 text-gray-500"></i>
              <?= __("Data pubblicazione da") ?>
            </label>
            <input id="pub_from" type="date" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" />
          </div>

          <div class="relative">
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-user-edit mr-1 text-gray-500"></i>
              <?= __("Autore") ?>
            </label>
            <input id="filter_autore" placeholder="<?= __('Cerca autore...') ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" autocomplete="off" />
            <ul id="filter_autore_suggest" class="autocomplete-suggestions"></ul>
            <input type="hidden" id="autore_id" />
          </div>

          <div class="relative">
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-building mr-1 text-gray-500"></i>
              <?= __("Editore") ?>
            </label>
            <input id="filter_editore" placeholder="<?= __('Cerca editore...') ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" autocomplete="off" />
            <ul id="filter_editore_suggest" class="autocomplete-suggestions"></ul>
            <input type="hidden" id="editore_filter" />
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
          <div class="relative">
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-tags mr-1 text-gray-500"></i>
              <?= __("Genere") ?>
            </label>
            <input id="filter_genere" placeholder="<?= __('Cerca genere...') ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" autocomplete="off" />
            <ul id="filter_genere_suggest" class="autocomplete-suggestions"></ul>
            <input type="hidden" id="genere_id" />
          </div>

          <div class="relative">
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-map-marker-alt mr-1 text-gray-500"></i>
              <?= __("Posizione") ?>
            </label>
            <input id="filter_posizione" placeholder="<?= __('Cerca posizione...') ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" autocomplete="off" />
            <ul id="filter_posizione_suggest" class="autocomplete-suggestions"></ul>
            <input type="hidden" id="posizione_id" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-calendar mr-1 text-gray-500"></i>
              <?= __("Anno pubblicazione da") ?>
            </label>
            <input id="anno_from" type="number" placeholder="<?= __('es. 2020') ?>" min="1800" max="2030" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-calendar mr-1 text-gray-500"></i>
              <?= __("Anno pubblicazione a") ?>
            </label>
            <input id="anno_to" type="number" placeholder="<?= __('es. 2024') ?>" min="1800" max="2030" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" />
          </div>
        </div>

        <div class="flex justify-between items-center pt-4 border-t border-gray-200">
          <div class="flex items-center text-sm text-gray-500">
            <i class="fas fa-info-circle text-gray-400 mr-2"></i>
            <span><?= __("I filtri vengono applicati automaticamente mentre digiti") ?></span>
          </div>
          <div class="flex items-center gap-2">
            <button id="save-filters" class="px-4 py-2 bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 text-sm" title="Salva filtri correnti">
              <i class="fas fa-save mr-2"></i>
              <?= __("Salva") ?>
            </button>
            <button id="clear-filters" class="px-4 py-2 bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200">
              <i class="fas fa-times mr-2"></i>
              <?= __("Cancella filtri") ?>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- White Data Table Card -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
      <div class="p-6 border-b border-gray-200 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-table text-gray-600 mr-2"></i>
          <?= __("Elenco Libri") ?>
          <span id="total-count" class="ml-2 px-2 py-1 bg-gray-100 text-gray-700 text-xs rounded-full"></span>
        </h2>
        <div id="export-buttons" class="flex items-center space-x-2">
          <button id="export-excel" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="Esporta CSV (formato compatibile per import)">
            <i class="fas fa-file-csv mr-1"></i>
            <?= __("CSV") ?>
          </button>
          <button id="export-pdf" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="Esporta PDF">
            <i class="fas fa-file-pdf mr-1"></i>
            <?= __("PDF") ?>
          </button>
          <button id="print-table" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="Stampa">
            <i class="fas fa-print mr-1"></i>
            <?= __("Stampa") ?>
          </button>
        </div>
      </div>
      <div class="p-6">
        <!-- Mobile scroll hint -->
        <div class="md:hidden mb-3 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-800 flex items-center gap-2">
          <i class="fas fa-hand-point-right"></i>
          <span><?= __("Scorri a destra per vedere tutte le colonne") ?></span>
        </div>

        <div class="overflow-x-auto">
              <table id="libri-table" class="display nowrap" style="width:100%">
                <thead>
                  <tr>
                    <th style="width:40px"><?= __("Stato") ?></th>
                    <th><?= __("Copertina") ?></th>
                    <th><?= __("Informazioni") ?></th>
                    <th><?= __("Genere") ?></th>
                    <th><?= __("Posizione") ?></th>
                    <th><?= __("Anno") ?></th>
                    <th style="width:12%"><?= __("Azioni") ?></th>
                  </tr>
                </thead>
              </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modern DataTables with Advanced Features -->
<script>
// Set current locale for DataTables language selection
window.i18nLocale = <?= json_encode(\App\Support\I18n::getLocale()) ?>;

document.addEventListener('DOMContentLoaded', function() {
  const urlParams = new URLSearchParams(window.location.search);
  const initialGenere = parseInt(urlParams.get('genere') || urlParams.get('genere_filter') || '0', 10) || 0;
  const initialSottogenere = parseInt(urlParams.get('sottogenere') || urlParams.get('sottogenere_filter') || '0', 10) || 0;
  
  // Check if DataTables is available
  if (typeof DataTable === 'undefined') {
    console.error('DataTable is not loaded!');
    return;
  }

  // Debounce helper
  const debounce = (fn, ms=300) => { let t; return (...args)=>{ clearTimeout(t); t=setTimeout(()=>fn(...args),ms); }; };

  // Initialize DataTable with enhanced features
  const table = new DataTable('#libri-table', {
    processing: true,
    serverSide: true,
    responsive: false, // Disabilitiamo responsive mode - usiamo solo scroll orizzontale
    scrollX: true,
    autoWidth: false,
    searching: false, // Using custom search
    stateSave: true,
    stateDuration: 60 * 60 * 24, // 24 hours
    dom: '<"top"lf>rt<"bottom"ip><"clear">',
    deferRender: true,
    scrollCollapse: true,
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
      error: function(xhr, status, err) {
        console.error('Errore caricamento /api/libri:', { status, err, responseText: xhr && xhr.responseText });
        if (window.Swal) {
          Swal.fire({ icon: 'error', title: '<?= addslashes(__("Errore")) ?>', text: '<?= addslashes(__("Impossibile caricare i libri. Controlla la console per i dettagli.")) ?>' });
        }
      },
      dataSrc: function(json) {
        // Update total count
        const totalCount = json.recordsTotal || 0;
        document.getElementById('total-count').textContent = totalCount.toLocaleString() + ' ' + window.__('libri');
        return json.data;
      }
    },
    columns: [
      { // 0 - Status indicator
        data: null,
        orderable: false,
        searchable: false,
        className: 'text-center align-middle all',
        responsivePriority: 1,
        render: function(_, __, row){
          const s = (row.stato || '').toString().trim().toLowerCase();
          let cls = 'bg-orange-400';
          let icon = 'fa-question-circle';
          if (s === 'disponibile') { cls = 'bg-green-500'; icon = 'fa-check-circle'; }
          else if (s === 'prestato' || s === 'non disponibile') { cls = 'bg-red-500'; icon = 'fa-times-circle'; }
          else if (s === 'riservato') { cls = 'bg-yellow-500'; icon = 'fa-clock'; }
          return `<div class="flex justify-center items-center h-full min-h-16">
            <span class="inline-flex items-center justify-center w-7 h-7 rounded-full ${cls} text-white text-xs" title="${row.stato || ''}">
              <i class="fas ${icon}"></i>
            </span>
          </div>`;
        }
      },
      { // 1 - Cover image (larger thumbnail)
        data: 'copertina_url',
        orderable: false,
        searchable: false,
        className: 'text-center align-middle all',
        responsivePriority: 1,
        render: function(data, type, row) {
          const imageUrl = data || '/uploads/copertine/placeholder.jpg';
          return `<div class="flex justify-center">
            <div class="relative group">
              <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-10 rounded-lg transition-all duration-300 pointer-events-none"></div>
              <img src="${imageUrl}"
                   alt="Copertina ${row.titolo}"
                   class="w-16 h-20 object-cover rounded-lg shadow-sm hover:shadow-lg transition-all duration-300 cursor-pointer group-hover:scale-105 relative z-10"
                   onerror="this.src='/uploads/copertine/placeholder.jpg'"
                   onclick='showImageModal(${JSON.stringify(row)})'>
            </div>
          </div>`;
        }
      },
      { // 2 - Main information with wrapping titles
        data: null,
        className: 'all align-top',
        responsivePriority: 1,
        render: function(_, type, row) {
          const titolo = row.titolo || window.__('Senza titolo');
          const sottotitolo = row.sottotitolo ? `<div class="text-xs text-gray-500 italic mt-1">${row.sottotitolo}</div>` : '';

          // Create linked authors
          let autoriHtml = '';
          if (row.autori && row.autori_order_key) {
            const autoriArray = row.autori.split(', ');
            const idsArray = row.autori_order_key.split(',');
            if (autoriArray.length === idsArray.length) {
              const linkedAutori = autoriArray.map((nome, index) => {
                const id = idsArray[index];
                return `<a href="/admin/autori/${id}" class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-gray-100 text-gray-700 hover:bg-gray-200 transition mr-1 mb-1">
                  <i class="fas fa-user mr-1"></i>${nome}
                </a>`;
              });
              autoriHtml = `<div class="text-sm mt-1">${linkedAutori.join('')}</div>`;
            } else {
              autoriHtml = `<div class="text-sm mt-1">
                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-gray-100 text-gray-700 mr-1 mb-1">
                  <i class="fas fa-user mr-1"></i>${row.autori}
                </span>
              </div>`;
            }
          } else if (row.autori) {
            autoriHtml = `<div class="text-sm mt-1">
              <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-gray-100 text-gray-700 mr-1 mb-1">
                <i class="fas fa-user mr-1"></i>${row.autori}
              </span>
            </div>`;
          }

          // Create linked publisher
          let editoreHtml = '';
          if (row.editore_nome && row.editore_id) {
            editoreHtml = `<div class="text-sm text-gray-600 mt-1"><i class="fas fa-building text-gray-400 mr-1"></i><a href="/admin/editori/${row.editore_id}" class="text-gray-700 hover:text-gray-900 hover:underline transition-colors">${row.editore_nome}</a></div>`;
          }

          // Add ISBN info if available
          let isbnHtml = '';
          if (row.isbn13 || row.isbn10) {
            const isbn = row.isbn13 || row.isbn10;
            isbnHtml = `<div class="text-xs text-gray-500 mt-1"><i class="fas fa-barcode text-gray-400 mr-1"></i>${isbn}</div>`;
          }

          return `<div class="min-w-0 flex-1">
            <div class="font-medium text-gray-900 leading-tight">
              <a href="/admin/libri/${row.id}" class="text-gray-800 hover:text-gray-900 transition-colors hover:underline font-medium" title="Visualizza dettagli libro">
                ${titolo}
              </a>
            </div>
            ${sottotitolo}
            ${autoriHtml}
            ${editoreHtml}
            ${isbnHtml}
          </div>`;
        }
      },
      { // 3 - Genre information
        data: 'genere_display',
        className: 'text-sm align-middle',
        responsivePriority: 3,
        render: function(data, type, row) {
          if (!data || data.trim() === '') {
            return '<span class="text-gray-400 italic"><?= __("Non specificato") ?></span>';
          }
          const genres = data.split(' / ');
          let html = '';
          genres.forEach((genre, index) => {
            const isMain = index === 0;
            const badgeClass = isMain ? 'bg-gray-200 text-gray-900 border border-gray-300' : 'bg-gray-100 text-gray-700';
            html += `<span class="inline-block px-2 py-1 rounded-full text-xs font-medium ${badgeClass} mb-1">${genre}</span>`;
            if (index < genres.length - 1) html += '<br>';
          });
          return html;
        }
      },
      { // 4 - Position/Location
        data: 'posizione_display',
        className: 'text-xs align-middle',
        responsivePriority: 4,
        render: function(data, type, row) {
          if (!data || data.trim() === '' || data === 'N/D') {
            return '<span class="text-gray-400 italic text-xs"><?= __("Non assegnata") ?></span>';
          }

          // Parse the position string to separate components
          // Expected format: "Scaffale A - Livello 1 - Fantasy - Prima Mensola"
          const parts = data.split(' - ');
          let html = '<div class="text-xs leading-tight">';

          if (parts.length >= 1 && parts[0]) {
            html += `<div class="font-medium text-gray-800">${parts[0]}</div>`;
          }
          if (parts.length >= 2 && parts[1]) {
            html += `<div class="text-gray-600">${parts[1]}</div>`;
          }
          if (parts.length >= 3 && parts[2]) {
            html += `<div class="text-gray-500">${parts[2]}</div>`;
          }

          html += '</div>';
          return html;
        }
      },
      { // 5 - Publication year
        data: 'anno_pubblicazione_formatted',
        className: 'text-sm text-center align-middle',
        responsivePriority: 5,
        render: function(data, type, row) {
          if (!data) {
            return '<span class="text-gray-400">-</span>';
          }
          return `<span class="inline-block px-2 py-1 bg-gray-100 text-gray-700 rounded text-xs font-mono">${data}</span>`;
        }
      },
      { // 6 - Actions
        data: 'id',
        orderable: false,
        searchable: false,
        className: 'text-center align-middle',
        responsivePriority: 1,
        render: function(data, type, row) {
          return `
            <div class="flex items-center justify-center gap-1">
              <a href="/admin/libri/${data}"
                 class="inline-flex items-center justify-center w-8 h-8 text-gray-600 hover:text-gray-800 hover:bg-gray-100 rounded-lg transition-all duration-200"
                 title="Visualizza dettagli">
                <i class="fas fa-eye text-sm"></i>
              </a>
              <a href="/admin/libri/modifica/${data}"
                 class="inline-flex items-center justify-center w-8 h-8 text-gray-600 hover:text-gray-900 hover:bg-gray-200 rounded-lg transition-all duration-200"
                 title="<?= __("Modifica") ?>">
                <i class="fas fa-edit text-sm"></i>
              </a>
              <button onclick="deleteBook(${data})"
                      class="inline-flex items-center justify-center w-8 h-8 text-gray-600 hover:text-red-600 hover:bg-red-50 rounded-lg transition-all duration-200"
                      title="<?= __("Elimina") ?>">
                <i class="fas fa-trash text-sm"></i>
              </button>
            </div>`;
        }
      }
    ],
    order: [[2, 'asc']], // Order by main information column
    pageLength: 25,
    lengthMenu: [
      [10, 25, 50, 100, 250],
      [10, 25, 50, 100, 250]
    ],
    language: (window.i18nLocale === 'en_US' ? window.DT_LANG_EN : window.DT_LANG_IT),
    drawCallback: function(settings) {
      // Hide pagination if there's only one page
      const api = this.api();
      const pagination = document.querySelector('.dataTables_paginate');
      const info = document.querySelector('.dataTables_info');
      
      if (pagination) {
        const recordsDisplay = api.page.info().recordsDisplay;
        const pageLength = api.page.len();
        
        if (recordsDisplay <= pageLength) {
          pagination.style.display = 'none';
        } else {
          pagination.style.display = 'block';
        }
      }
      
      // Add spacing around info display
      if (info) {
        info.style.marginTop = '1rem';
        info.style.marginBottom = '1rem';
        info.style.padding = '0.5rem 0';
      }
      
      // Ensure proper spacing for the entire table wrapper
      const wrapper = document.querySelector('.dataTables_wrapper');
      if (wrapper) {
        wrapper.style.marginTop = '1rem';
        wrapper.style.marginBottom = '1rem';
      }
    },
    initComplete: function() {
      
      // Initialize filter toggle
      initializeFilterToggle();
      
      // Initialize clear filters
      initializeClearFilters();
      
      // Initialize export buttons
      initializeExportButtons();
      
      // If filtering by genere/sottogenere from URL, show a notice badge
      const filterBar = document.createElement('div');
      filterBar.className = 'mt-2 flex flex-wrap gap-2';
      if (initialGenere) {
        const b = document.createElement('span');
        b.className = 'px-2 py-1 rounded-full text-xs bg-gray-100 text-gray-700';
        b.textContent = '<?= __("Filtro genere attivo") ?>';
        filterBar.appendChild(b);
      }
      if (initialSottogenere) {
        const b2 = document.createElement('span');
        b2.className = 'px-2 py-1 rounded-full text-xs bg-green-100 text-green-700';
        b2.textContent = '<?= __("Filtro sottogenere attivo") ?>';
        filterBar.appendChild(b2);
      }
      if (filterBar.children.length) {
        document.querySelector('.card-header').appendChild(filterBar);
      }
    }
  });

  // Filter event handlers
  const reloadDebounced = debounce(()=> table.ajax.reload());
  
  // Add event listeners for filter inputs
  const filterInputs = ['search_text', 'search_isbn', 'stato_filter', 'acq_from', 'acq_to', 'pub_from', 'anno_from', 'anno_to'];
  filterInputs.forEach(id => {
    const element = document.getElementById(id);
    if (element) {
      element.addEventListener('keyup', reloadDebounced);
      element.addEventListener('change', reloadDebounced);
    }
  });
  
  // Global search
  const globalSearch = document.getElementById('global_search');
  if (globalSearch) {
    globalSearch.addEventListener('input', debounce(()=>{ 
      document.getElementById('search_text').value = globalSearch.value; 
      table.ajax.reload(); 
    }, 300));
  }

  // Initialize autocomplete for authors and publishers
  initializeAutocomplete();

  // Enhanced autocomplete function
  async function fetchJSON(url) {
    try {
      const response = await fetch(url);
      return await response.json();
    } catch (error) {
      console.error('Fetch error:', error);
      return [];
    }
  }

  function setupEnhancedAutocomplete(inputId, suggestId, fetchUrl, onSelect) {
    const input = document.getElementById(inputId);
    const suggestions = document.getElementById(suggestId);
    let timeout;

    if (!input || !suggestions) return;

    input.addEventListener('input', async function() {
      clearTimeout(timeout);
      const query = this.value.trim();

      if (!query) {
        suggestions.classList.add('hidden');
        return;
      }

      timeout = setTimeout(async () => {
        try {
          const data = await fetchJSON(fetchUrl + encodeURIComponent(query));
          suggestions.innerHTML = '';

          if (data.length === 0) {
            suggestions.innerHTML = '<li class="px-3 py-2 text-gray-500 text-sm"><?= __("Nessun risultato trovato") ?></li>';
          } else {
            data.slice(0, 8).forEach(item => {
              const li = document.createElement('li');
              li.className = 'px-3 py-2 hover:bg-gray-50 cursor-pointer text-sm border-b border-gray-100 last:border-b-0 flex items-center gap-2';
              li.innerHTML = `
                <i class="fas ${inputId.includes('autore') ? 'fa-user' : 'fa-building'} text-gray-400 text-xs"></i>
                <span>${item.label}</span>
              `;
              li.onclick = () => {
                onSelect(item);
                suggestions.classList.add('hidden');
                table.ajax.reload();
              };
              suggestions.appendChild(li);
            });
          }

          suggestions.classList.remove('hidden');
        } catch (error) {
          console.error('Autocomplete error:', error);
        }
      }, 300);
    });

    // Hide on blur
    input.addEventListener('blur', () => {
      setTimeout(() => suggestions.classList.add('hidden'), 200);
    });
  }

  function initializeAutocomplete() {
    setupEnhancedAutocomplete('filter_autore', 'filter_autore_suggest', '/api/search/autori?q=',
      item => {
        document.getElementById('autore_id').value = item.id;
        document.getElementById('filter_autore').value = item.label;
      });

    setupEnhancedAutocomplete('filter_editore', 'filter_editore_suggest', '/api/search/editori?q=',
      item => {
        document.getElementById('editore_filter').value = item.id;
        document.getElementById('filter_editore').value = item.label;
      });

    setupEnhancedAutocomplete('filter_genere', 'filter_genere_suggest', '/api/search/generi?q=',
      item => {
        document.getElementById('genere_id').value = item.id;
        document.getElementById('filter_genere').value = item.label;
      });

    setupEnhancedAutocomplete('filter_posizione', 'filter_posizione_suggest', '/api/search/collocazione?q=',
      item => {
        document.getElementById('posizione_id').value = item.id;
        document.getElementById('filter_posizione').value = item.label;
      });
  }

  function initializeFilterToggle() {
    const toggleBtn = document.getElementById('toggle-filters');
    const filtersContainer = document.getElementById('filters-container');
    let filtersVisible = true;

    if (toggleBtn && filtersContainer) {
      toggleBtn.addEventListener('click', function() {
        filtersVisible = !filtersVisible;
        filtersContainer.style.display = filtersVisible ? 'block' : 'none';
        
        const icon = this.querySelector('i');
        const text = this.querySelector('span');
        
        if (filtersVisible) {
          icon.className = 'fas fa-chevron-up';
          text.textContent = '<?= __("Nascondi filtri") ?>';
        } else {
          icon.className = 'fas fa-chevron-down';
          text.textContent = '<?= __("Mostra filtri") ?>';
        }
      });
    }
  }

  function initializeClearFilters() {
    const clearBtn = document.getElementById('clear-filters');
    const saveBtn = document.getElementById('save-filters');

    if (clearBtn) {
      clearBtn.addEventListener('click', function() {
        // Clear all filter inputs
        const filterIds = ['search_text', 'search_isbn', 'stato_filter', 'acq_from', 'acq_to', 'pub_from', 'filter_autore', 'filter_editore', 'filter_genere', 'filter_posizione', 'anno_from', 'anno_to'];
        filterIds.forEach(id => {
          const element = document.getElementById(id);
          if (element) element.value = '';
        });

        // Clear hidden inputs
        document.getElementById('autore_id').value = '';
        document.getElementById('editore_filter').value = '';
        document.getElementById('genere_id').value = '';
        document.getElementById('posizione_id').value = '';

        // Hide autocomplete suggestions
        const suggestions = document.querySelectorAll('.autocomplete-suggestions');
        suggestions.forEach(el => el.classList.add('hidden'));

        // Clear URL parameters
        const url = new URL(window.location);
        url.search = '';
        window.history.replaceState({}, '', url);

        // Reload table
        table.ajax.reload();

        // Show success message
        if (window.Swal) {
          Swal.fire({
            icon: 'success',
            title: '<?= addslashes(__("Filtri cancellati")) ?>',
            text: '<?= addslashes(__("Tutti i filtri sono stati rimossi")) ?>',
            timer: 2000,
            showConfirmButton: false
          });
        }
      });
    }

    if (saveBtn) {
      saveBtn.addEventListener('click', function() {
        // Collect current filter values
        const filters = {};
        const filterIds = ['search_text', 'search_isbn', 'stato_filter', 'acq_from', 'acq_to', 'pub_from', 'anno_from', 'anno_to'];

        filterIds.forEach(id => {
          const element = document.getElementById(id);
          if (element && element.value.trim() !== '') {
            filters[id] = element.value.trim();
          }
        });

        // Add hidden filter values
        const hiddenIds = ['autore_id', 'editore_filter', 'genere_id', 'posizione_id'];
        hiddenIds.forEach(id => {
          const element = document.getElementById(id);
          if (element && element.value.trim() !== '') {
            filters[id] = element.value.trim();
          }
        });

        // Update URL with filters
        const url = new URL(window.location);
        Object.keys(filters).forEach(key => {
          url.searchParams.set(key, filters[key]);
        });
        window.history.replaceState({}, '', url);

        // Show success message
        if (window.Swal) {
          Swal.fire({
            icon: 'success',
            title: '<?= addslashes(__("Filtri salvati")) ?>',
            text: '<?= addslashes(__("I filtri correnti sono stati salvati nell\'URL")) ?>',
            timer: 2000,
            showConfirmButton: false
          });
        }
      });
    }
  }

  function initializeExportButtons() {
    // CSV export - export filtered data with import-compatible format
    document.getElementById('export-excel').addEventListener('click', function() {
      // Get current filters
      const params = new URLSearchParams();

      // Global search
      const globalSearch = document.getElementById('global_search')?.value || '';
      if (globalSearch) {
        params.append('search', globalSearch);
      }

      // Status filter
      const statoFilter = document.getElementById('stato_filter')?.value || '';
      if (statoFilter) {
        params.append('stato', statoFilter);
      }

      // Editore filter
      const editoreFilter = document.getElementById('editore_filter')?.value || '';
      if (editoreFilter) {
        params.append('editore_id', editoreFilter);
      }

      // Genere filter
      const genereFilter = document.getElementById('genere_id')?.value || '';
      if (genereFilter) {
        params.append('genere_id', genereFilter);
      }

      // Autore filter (if exists in hidden input)
      const autoreFilter = document.getElementById('autore_filter')?.value || '';
      if (autoreFilter) {
        params.append('autore_id', autoreFilter);
      }

      // Check if any filters are applied
      const hasFilters = params.toString().length > 0;
      const filteredCount = table.rows({search: 'applied'}).count();
      const totalCount = table.rows().count();

      const message = hasFilters
        ? `Esportazione di ${filteredCount} libri filtrati su ${totalCount} totali`
        : `Esportazione di tutti i ${totalCount} libri del catalogo`;

      if (window.Swal) {
        Swal.fire({
          icon: 'info',
          title: '<?= addslashes(__("Generazione CSV in corso...")) ?>',
          text: message,
          showConfirmButton: false,
          timer: 1500
        });
      }

      // Redirect to server-side export endpoint with filters
      const url = '/admin/libri/export/csv' + (params.toString() ? '?' + params.toString() : '');
      window.location.href = url;
    });
    
    // Print
    document.getElementById('print-table').addEventListener('click', function() {
      window.print();
    });
    
    // PDF export
    document.getElementById('export-pdf').addEventListener('click', function() {
      const currentData = table.rows({search: 'applied'}).data().toArray();
      if (currentData.length === 0) {
        if (window.Swal) {
          Swal.fire({
            icon: 'info',
            title: '<?= addslashes(__("Nessun dato")) ?>',
            text: '<?= addslashes(__("Non ci sono dati da esportare")) ?>'
          });
        }
        return;
      }
      
      // Create PDF content using jsPDF
      if (typeof window.jspdf === 'undefined') {
        // Load jsPDF if not available
        const script = document.createElement('script');
        script.src = '/assets/js/jspdf.umd.min.js';
        script.onload = function() {
          generatePDF(currentData);
        };
        document.head.appendChild(script);
      } else {
        generatePDF(currentData);
      }
    });

    function generatePDF(data) {
      const { jsPDF } = window.jspdf;
      const doc = new jsPDF();
      
      // Title
      doc.setFontSize(18);
      doc.text(window.__('Elenco Libri - Biblioteca'), 14, 22);

      // Date
      doc.setFontSize(11);
      doc.text(`${window.__('Generato il')}: ${new Date().toLocaleDateString('it-IT')}`, 14, 30);

      // Total count
      doc.text(`${window.__('Totale libri')}: ${data.length}`, 14, 38);

      // Table headers
      const headers = [window.__('Titolo'), window.__('Autore/i'), window.__('Editore'), window.__('Stato'), window.__('Data Acq.')];
      let yPos = 50;
      
      // Set font for table
      doc.setFontSize(10);
      
      // Column widths
      const colWidths = [70, 45, 35, 20, 20];
      const startX = 14;
      
      // Draw headers
      headers.forEach((header, i) => {
        doc.text(header, startX + colWidths.slice(0, i).reduce((a, b) => a + b, 0), yPos);
      });
      
      yPos += 8;
      
      // Draw data
      data.forEach((row, index) => {
        if (yPos > 280) {
          doc.addPage();
          yPos = 20;
        }
        
        const rowData = [
          (row.titolo || '').substring(0, 35),
          (row.autori || '').substring(0, 25),
          (row.editore_nome || '').substring(0, 18),
          (row.stato || ''),
          (row.data_acquisizione || '').substring(0, 10)
        ];
        
        rowData.forEach((cell, i) => {
          doc.text(cell, startX + colWidths.slice(0, i).reduce((a, b) => a + b, 0), yPos);
        });
        
        yPos += 7;
      });
      
      // Save the PDF
      doc.save(`libri_export_${new Date().toISOString().slice(0, 10)}.pdf`);
    }
  }

  // Delete book function (POST with CSRF)
  window.deleteBook = function(bookId) {
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const confirmAndSubmit = () => {
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = `/admin/libri/delete/${bookId}`;
      const inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = 'csrf_token';
      inp.value = csrf;
      form.appendChild(inp);
      document.body.appendChild(form);
      form.submit();
    };
    if (window.Swal) {
      Swal.fire({
        title: '<?= addslashes(__("Sei sicuro?")) ?>',
        text: '<?= addslashes(__("Questa azione non può essere annullata!")) ?>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: '<?= addslashes(__("Sì, elimina!")) ?>',
        cancelButtonText: '<?= addslashes(__("Annulla")) ?>'
      }).then((result) => { if (result.isConfirmed) confirmAndSubmit(); });
    } else {
      if (confirm('<?= addslashes(__("Sei sicuro di voler eliminare questo libro?")) ?>')) confirmAndSubmit();
    }
  };

  // Image modal functionality with book details
  window.showImageModal = function(bookData) {
    const imageUrl = bookData.copertina_url || '/uploads/copertine/placeholder.jpg';
    const title = bookData.titolo || window.__('Libro senza titolo');

    if (window.Swal) {
      // Build HTML content with book information
      let htmlContent = `
        <div class="text-left space-y-3">
          <div class="flex justify-center mb-4">
            <img src="${imageUrl}"
                 alt="Copertina ${title}"
                 class="max-w-full h-auto rounded-lg shadow-lg"
                 style="max-height: 500px; object-fit: contain;"
                 onerror="this.src='/uploads/copertine/placeholder.jpg'">
          </div>
          <div class="bg-gray-50 p-4 rounded-lg">
            <h3 class="font-semibold text-lg text-gray-900 mb-3">${title}</h3>
      `;

      if (bookData.sottotitolo) {
        htmlContent += `<p class="text-sm text-gray-600 italic mb-2">${bookData.sottotitolo}</p>`;
      }

      if (bookData.autori) {
        htmlContent += `
          <div class="flex items-start gap-2 mb-2">
            <i class="fas fa-user text-gray-400 mt-1"></i>
            <div>
              <span class="text-xs text-gray-500">${window.__('Autore/i:')}</span>
              <span class="text-sm text-gray-700 font-medium ml-2">${bookData.autori}</span>
            </div>
          </div>
        `;
      }

      if (bookData.editore_nome) {
        htmlContent += `
          <div class="flex items-start gap-2 mb-2">
            <i class="fas fa-building text-gray-400 mt-1"></i>
            <div>
              <span class="text-xs text-gray-500">${window.__('Editore:')}</span>
              <span class="text-sm text-gray-700 ml-2">${bookData.editore_nome}</span>
            </div>
          </div>
        `;
      }

      if (bookData.anno_pubblicazione_formatted) {
        htmlContent += `
          <div class="flex items-start gap-2 mb-2">
            <i class="fas fa-calendar text-gray-400 mt-1"></i>
            <div>
              <span class="text-xs text-gray-500">${window.__('Anno:')}</span>
              <span class="text-sm text-gray-700 ml-2">${bookData.anno_pubblicazione_formatted}</span>
            </div>
          </div>
        `;
      }

      if (bookData.isbn13 || bookData.isbn10) {
        const isbn = bookData.isbn13 || bookData.isbn10;
        htmlContent += `
          <div class="flex items-start gap-2 mb-2">
            <i class="fas fa-barcode text-gray-400 mt-1"></i>
            <div>
              <span class="text-xs text-gray-500">${window.__('ISBN:')}</span>
              <span class="text-sm text-gray-700 font-mono ml-2">${isbn}</span>
            </div>
          </div>
        `;
      }

      if (bookData.genere_display) {
        htmlContent += `
          <div class="flex items-start gap-2 mb-2">
            <i class="fas fa-tags text-gray-400 mt-1"></i>
            <div>
              <span class="text-xs text-gray-500">${window.__('Genere:')}</span>
              <span class="text-sm text-gray-700 ml-2">${bookData.genere_display.replace(' / ', ' → ')}</span>
            </div>
          </div>
        `;
      }

      if (bookData.posizione_display && bookData.posizione_display !== 'N/D') {
        htmlContent += `
          <div class="flex items-start gap-2 mb-2">
            <i class="fas fa-map-marker-alt text-gray-400 mt-1"></i>
            <div>
              <span class="text-xs text-gray-500">${window.__('Posizione:')}</span>
              <span class="text-sm text-gray-700 ml-2">${bookData.posizione_display.replace(/ - /g, ' → ')}</span>
            </div>
          </div>
        `;
      }

      if (bookData.stato) {
        const statoClass = bookData.stato.toLowerCase() === 'disponibile' ? 'text-green-600' :
                          bookData.stato.toLowerCase() === 'prestato' ? 'text-red-600' :
                          bookData.stato.toLowerCase() === 'riservato' ? 'text-yellow-600' : 'text-gray-600';
        htmlContent += `
          <div class="flex items-start gap-2 mb-2">
            <i class="fas fa-info-circle text-gray-400 mt-1"></i>
            <div>
              <span class="text-xs text-gray-500">${window.__('Stato:')}</span>
              <span class="text-sm font-semibold ${statoClass} ml-2">${bookData.stato}</span>
            </div>
          </div>
        `;
      }

      htmlContent += `
          </div>
          <div class="flex gap-2 mt-4">
            <a href="/admin/libri/${bookData.id}" class="flex-1 px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-700 transition-colors text-center text-sm">
              <i class="fas fa-eye mr-2"></i>${window.__('Visualizza dettagli')}
            </a>
            <a href="/admin/libri/modifica/${bookData.id}" class="flex-1 px-4 py-2 bg-gray-100 text-gray-800 rounded-lg hover:bg-gray-200 transition-colors text-center text-sm">
              <i class="fas fa-edit mr-2"></i>${window.__('Modifica')}
            </a>
          </div>
        </div>
      `;

      Swal.fire({
        html: htmlContent,
        showCloseButton: true,
        showConfirmButton: false,
        width: '600px',
        customClass: {
          popup: 'rounded-xl',
          htmlContainer: 'p-0'
        }
      });
    } else {
      // Fallback: open in new window
      window.open(imageUrl, '_blank');
    }
  };

  // Load filters from URL on page load
  function loadFiltersFromURL() {
    const urlParams = new URLSearchParams(window.location.search);

    const filterIds = ['search_text', 'search_isbn', 'stato_filter', 'acq_from', 'acq_to', 'pub_from', 'anno_from', 'anno_to'];
    filterIds.forEach(id => {
      const value = urlParams.get(id);
      if (value) {
        const element = document.getElementById(id);
        if (element) element.value = value;
      }
    });

    const hiddenIds = ['autore_id', 'editore_filter', 'genere_id', 'posizione_id'];
    hiddenIds.forEach(id => {
      const value = urlParams.get(id);
      if (value) {
        const element = document.getElementById(id);
        if (element) element.value = value;
      }
    });
  }

  // Load filters from URL
  loadFiltersFromURL();

});

// Fix for select arrow overlap
document.addEventListener('DOMContentLoaded', function() {
  // Add custom CSS to fix select arrow overlap
  const style = document.createElement('style');
  style.textContent = `
    select.dt-input {
      padding-right: 2rem !important;
      background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
      background-position: right 0.5rem center;
      background-size: 1rem;
      background-repeat: no-repeat;
      appearance: none;
    }
    
    /* Ensure all table rows have the same background */
    #libri-table tbody tr {
      background-color: white;
    }
    
    /* Remove all zebra striping and borders between rows */
    #libri-table tbody tr td {
      background-color: transparent;
      border: none;
    }
    
    /* Force narrow status column */
    #libri-table th:first-child, #libri-table td:first-child { width: 28px; }
    
    #libri-table tbody tr:hover {
      @apply bg-gray-50;
    }
    
    /* Responsive design for mobile */
    @media (max-width: 768px) {
      .card-header {
        @apply flex-col items-start gap-3;
      }
      
      .card-header .flex {
        @apply w-full justify-center;
      }
      
      /* Mobile responsive table */
      .dtr-details {
        width: 100%;
      }
      
      .dtr-title {
        font-weight: 600;
        display: inline-block;
        min-width: 100px;
      }
      
      .dtr-data {
        display: inline;
      }
      
      /* Mobile card styling */
      table.dataTable.dtr-inline.collapsed > tbody > tr > td.dtr-control:before,
      table.dataTable.dtr-inline.collapsed > tbody > tr > th.dtr-control:before {
        background-color: #3b82f6;
        border: none;
        box-shadow: none;
        top: 50%;
        transform: translateY(-50%);
      }
      
      /* Adjust export buttons for mobile */
      #export-buttons {
        @apply flex-wrap gap-1;
      }
      
      #export-buttons .btn-outline {
        @apply px-2 py-1 text-xs;
      }
    }
  `;
  document.head.appendChild(style);
});
</script>

<!-- Custom Styles for Enhanced UI -->
<style>
.autocomplete-suggestions {
  @apply absolute z-20 bg-white border border-gray-200 rounded-lg mt-1 w-full hidden shadow-lg max-h-64 overflow-y-auto;
}

.fade-in {
  animation: fadeIn 0.5s ease-in-out;
}

.slide-in-up {
  animation: slideInUp 0.6s ease-out;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes slideInUp {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* Enhanced DataTables Styling */
.dataTables_wrapper .dataTables_processing {
  @apply bg-white/95 backdrop-blur-sm border border-gray-200 rounded-xl shadow-lg;
  padding: 1rem 2rem;
  font-weight: 500;
}

.dataTables_wrapper .dataTables_paginate .paginate_button {
  @apply px-3 py-2 text-sm border border-gray-300 bg-white hover:bg-gray-50 transition-all duration-200 rounded-lg margin-x-1;
}

.dataTables_wrapper .dataTables_paginate .paginate_button.current {
  @apply bg-gray-900 text-white border-gray-900 hover:bg-gray-800 shadow-md;
}

.dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
  @apply opacity-50 cursor-not-allowed;
}

.dataTables_wrapper .dataTables_length select {
  @apply py-2 px-3 text-sm border border-gray-300 rounded-lg bg-white focus:ring-2 focus:ring-gray-400 focus:border-transparent;
}

.dataTables_wrapper .dataTables_filter input {
  @apply py-2 px-3 text-sm border border-gray-300 rounded-lg bg-white focus:ring-2 focus:ring-gray-400 focus:border-transparent;
}

.dataTables_wrapper .dataTables_info {
  @apply text-sm text-gray-600 font-medium;
}

.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_filter {
  @apply mb-4;
}

/* Table Header Styling */
#libri-table thead th {
  @apply bg-gray-50 font-semibold text-gray-700 border-b-2 border-gray-200 px-4 py-4 text-left;
  white-space: nowrap;
}

#libri-table thead th:first-child {
  border-top-left-radius: 0.5rem;
}

#libri-table thead th:last-child {
  border-top-right-radius: 0.5rem;
}

/* Table Body Styling */
#libri-table tbody td {
  @apply px-4 py-4 border-b border-gray-100 align-top;
  vertical-align: top;
}

#libri-table tbody tr {
  @apply bg-white transition-colors duration-200;
}

#libri-table tbody tr:hover {
  @apply bg-gray-50;
}

/* Column width constraints */
#libri-table th:nth-child(1), #libri-table td:nth-child(1) { width: 40px; min-width: 40px; }
#libri-table th:nth-child(2), #libri-table td:nth-child(2) { width: 80px; min-width: 80px; }
#libri-table th:nth-child(3), #libri-table td:nth-child(3) { min-width: 250px; }
#libri-table th:nth-child(4), #libri-table td:nth-child(4) { width: 120px; min-width: 120px; }
#libri-table th:nth-child(5), #libri-table td:nth-child(5) { width: 120px; min-width: 120px; }
#libri-table th:nth-child(6), #libri-table td:nth-child(6) { width: 80px; min-width: 80px; }
#libri-table th:nth-child(7), #libri-table td:nth-child(7) { width: 140px; min-width: 140px; }

/* Title wrapping */
#libri-table .min-w-0 {
  word-wrap: break-word;
  word-break: break-word;
  hyphens: auto;
}

/* Action buttons styling */
.action-button {
  @apply inline-flex items-center justify-center w-8 h-8 rounded-lg transition-all duration-200;
}

.action-button:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

/* Improved pagination */
.dataTables_wrapper .dataTables_paginate {
  @apply mt-4 flex justify-center;
}

.dataTables_wrapper .dataTables_paginate .paginate_button {
  @apply mx-1;
}

/* Loading state improvements */
.dataTables_wrapper .dataTables_processing {
  @apply fixed top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 z-50;
  background: rgba(255, 255, 255, 0.98);
  backdrop-filter: blur(8px);
}

/* Table scroll improvements */
.overflow-x-auto {
  scrollbar-width: thin;
  scrollbar-color: #cbd5e0 #f7fafc;
}

.overflow-x-auto::-webkit-scrollbar {
  height: 6px;
}

.overflow-x-auto::-webkit-scrollbar-track {
  background: #f7fafc;
  border-radius: 3px;
}

.overflow-x-auto::-webkit-scrollbar-thumb {
  background: #cbd5e0;
  border-radius: 3px;
}

.overflow-x-auto::-webkit-scrollbar-thumb:hover {
  background: #a0aec0;
}

/* Add spacing around the table */
#libri-table {
  margin-top: 1.5rem !important;
  margin-bottom: 1.5rem !important;
}

/* Force book titles to wrap properly */
#libri-table tbody td:nth-child(3) {
  max-width: 320px !important;
  word-wrap: break-word !important;
  white-space: normal !important;
}

#libri-table tbody td:nth-child(3) .font-medium {
  word-break: break-word !important;
  hyphens: auto !important;
  line-height: 1.3 !important;
}

/* Ensure action column is always visible */
#libri-table tbody td:nth-child(7) {
  min-width: 140px !important;
}

/* Better vertical alignment for all cells */
#libri-table tbody td {
  vertical-align: middle !important;
}

/* Fix status indicator positioning */
#libri-table tbody td:nth-child(1) {
  padding: 8px !important;
}

/* Optimize position column spacing */
#libri-table tbody td:nth-child(5) {
  max-width: 120px !important;
  padding: 6px 8px !important;
  line-height: 1.2 !important;
}

#libri-table tbody td:nth-child(5) .leading-tight {
  line-height: 1.1 !important;
}

#libri-table tbody td:nth-child(5) div {
  margin-bottom: 1px !important;
}

/* Responsive design for mobile */
@media (max-width: 768px) {
  .card-header {
    @apply flex-col items-start gap-3;
  }

  .card-header .flex {
    @apply w-full justify-center;
  }

  /* Adjust export buttons for mobile */
  #export-buttons {
    @apply flex-wrap gap-1;
  }

  #export-buttons .btn-outline {
    @apply px-2 py-1 text-xs;
  }

  /* Mobile horizontal scroll: make Informazioni column wider */
  #libri-table th:nth-child(3),
  #libri-table td:nth-child(3) {
    min-width: 280px !important;
    width: 280px !important;
  }

  /* Custom scrollbar styling for mobile */
  .overflow-x-auto {
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: #3b82f6 #e0e7ff;
    position: relative;
  }

  .overflow-x-auto::-webkit-scrollbar {
    height: 12px;
  }

  .overflow-x-auto::-webkit-scrollbar-track {
    background: #e0e7ff;
    border-radius: 6px;
  }

  .overflow-x-auto::-webkit-scrollbar-thumb {
    background: #3b82f6;
    border-radius: 6px;
    border: 2px solid #e0e7ff;
  }

  .overflow-x-auto::-webkit-scrollbar-thumb:hover {
    background: #2563eb;
  }

  /* Add scroll indicator shadow */
  .overflow-x-auto::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: 30px;
    background: linear-gradient(to left, rgba(0,0,0,0.08), transparent);
    pointer-events: none;
    z-index: 1;
  }
}
</style>

<?php
/**
 * @var array $data { autori: array }
 */
$title = __("Autori");
$autori = $data['autori'];
?>
<!-- Modern Authors Management Interface -->
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
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
          <a href="/admin/autori" class="text-gray-900 hover:text-gray-900">
            <i class="fas fa-user-edit mr-1"></i><?= __("Autori") ?>
          </a>
        </li>
      </ol>
    </nav>
    <!-- Modern Header -->
    <div class="mb-6 fade-in">
      <div class="flex items-center justify-between mb-3">
        <div>
          <h1 class="text-3xl font-bold text-gray-900 flex items-center">
            <i class="fas fa-user-edit text-gray-800 mr-3"></i>
            <?= __("Gestione Autori") ?>
          </h1>
          <p class="text-sm text-gray-600 mt-1"><?= __("Esplora e gestisci gli autori della biblioteca") ?></p>
        </div>
        <div class="hidden md:flex items-center gap-3">
          <div class="hidden md:block">
            <input id="global_search" type="text" placeholder="<?= __('Cerca rapido...') ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 w-64" />
          </div>
          <a href="/admin/autori/crea" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center">
            <i class="fas fa-plus mr-2"></i>
            <?= __("Nuovo Autore") ?>
          </a>
        </div>
      </div>
      <div class="flex md:hidden mb-3">
        <a href="/admin/autori/crea" class="w-full px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center justify-center">
          <i class="fas fa-plus mr-2"></i>
          Nuovo Autore
        </a>
      </div>
    </div>

    <!-- Advanced Filters Card -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-5 slide-in-up">
      <div class="p-6 border-b border-gray-200 flex items-center justify-between">
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
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-search mr-1 text-gray-500"></i>
              <?= __("Nome autore") ?>
            </label>
            <input id="search_nome" placeholder="<?= __('Cerca per nome...') ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-id-card mr-1 text-gray-500"></i>
              <?= __("Pseudonimo") ?>
            </label>
            <input id="search_pseudonimo" placeholder="<?= __('Cerca per pseudonimo...') ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-flag mr-1 text-gray-500"></i>
              <?= __("Nazionalità") ?>
            </label>
            <input id="search_nazionalita" placeholder="<?= __('Es. Italiana, Americana...') ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-globe mr-1 text-gray-500"></i>
              <?= __("Sito web") ?>
            </label>
            <input id="search_sito" placeholder="<?= __('URL sito web...') ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" />
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-calendar mr-1 text-gray-500"></i>
              <?= __("Data nascita da") ?>
            </label>
            <input id="nascita_from" type="date" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-calendar mr-1 text-gray-500"></i>
              <?= __("Data nascita a") ?>
            </label>
            <input id="nascita_to" type="date" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-calendar mr-1 text-gray-500"></i>
              <?= __("Data morte da") ?>
            </label>
            <input id="morte_from" type="date" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-calendar mr-1 text-gray-500"></i>
              <?= __("Data morte a") ?>
            </label>
            <input id="morte_to" type="date" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" />
          </div>
        </div>

        <div class="flex justify-between items-center pt-4 border-t border-gray-200">
          <div class="flex items-center text-sm text-gray-500">
            <i class="fas fa-info-circle text-gray-400 mr-2"></i>
            <span><?= __("I filtri vengono applicati automaticamente mentre digiti") ?></span>
          </div>
          <button id="clear-filters" class="px-4 py-2 bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200">
            <i class="fas fa-times mr-2"></i>
            <?= __("Cancella filtri") ?>
          </button>
        </div>
      </div>
    </div>

    <!-- Data Table Card -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
      <div class="p-6 border-b border-gray-200 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-table text-gray-600 mr-2"></i>
          <?= __("Elenco Autori") ?>
          <span id="total-count" class="ml-2 px-2 py-1 bg-gray-100 text-gray-700 text-xs rounded-full"></span>
        </h2>
        <div id="export-buttons" class="flex items-center space-x-2">
          <button id="export-excel" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="<?= __("Esporta Excel") ?>">
            <i class="fas fa-file-excel mr-1"></i>
            <?= __("Excel") ?>
          </button>
          <button id="export-pdf" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="<?= __("Esporta PDF") ?>">
            <i class="fas fa-file-pdf mr-1"></i>
            <?= __("PDF") ?>
          </button>
          <button id="print-table" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="<?= __("Stampa") ?>">
            <i class="fas fa-print mr-1"></i>
            <?= __("Stampa") ?>
          </button>
        </div>
      </div>
      <div class="p-6">
        <div class="overflow-x-auto">
              <table id="autori-table" class="display responsive nowrap" style="width:100%">
                <thead>
                  <tr>
                    <th><?= __('Nome') ?></th>
                    <th><?= __('Pseudonimo') ?></th>
                    <th><?= __('Nazionalità') ?></th>
                    <th><?= __('Numero Libri') ?></th>
                    <th style="width:15%" class="text-center"><?= __('Azioni') ?></th>
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
// i18n translations for JavaScript
const i18nTranslations = <?= json_encode([
    'Autore sconosciuto' => __("Autore sconosciuto"),
    'Visualizza dettagli' => __("Visualizza dettagli"),
    'Tutti' => __("Tutti"),
    'Nascondi filtri' => __("Nascondi filtri"),
    'Mostra filtri' => __("Mostra filtri"),
    'Nessun risultato trovato' => __("Nessun risultato trovato"),
    'Sì' => __("Sì"),
    'No' => __("No"),
    'Nome' => __("Nome"),
    'Pseudonimo' => __("Pseudonimo"),
    'Nazionalità' => __("Nazionalità"),
    'Libri' => __("Libri"),
], JSON_UNESCAPED_UNICODE) ?>;

// Set current locale for DataTables language selection
window.i18nLocale = <?= json_encode(\App\Support\I18n::getLocale()) ?>;

// Global translation function for JavaScript
window.__ = function(key) {
    return i18nTranslations[key] || key;
};

document.addEventListener('DOMContentLoaded', function() {

  // Check if DataTables is available
  if (typeof DataTable === 'undefined') {
    console.error('DataTable is not loaded!');
    return;
  }

  // Debounce helper
  const debounce = (fn, ms=300) => { let t; return (...args)=>{ clearTimeout(t); t=setTimeout(()=>fn(...args),ms); }; };

  // Initialize DataTable with modern features
  const table = new DataTable('#autori-table', {
    processing: true,
    serverSide: true,
    responsive: true,
    searching: false, // custom
    stateSave: true,
    dom: 'lrtip', // Imposta un layout senza i pulsanti DataTables predefiniti ('B')
    ajax: {
      url: '/api/autori',
      type: 'GET',
      data: function(d) {
        return {
          ...d,
          search_nome: document.getElementById('search_nome').value,
          search_pseudonimo: document.getElementById('search_pseudonimo').value,
          search_nazionalita: document.getElementById('search_nazionalita').value,
          search_sito: document.getElementById('search_sito').value,
          nascita_from: document.getElementById('nascita_from').value,
          nascita_to: document.getElementById('nascita_to').value,
          morte_from: document.getElementById('morte_from').value,
          morte_to: document.getElementById('morte_to').value
        };
      },
      error: function(xhr, status, err) {
        console.error('Errore caricamento /api/autori:', { status, err, responseText: xhr && xhr.responseText });
        if (window.Swal) {
          Swal.fire({ icon: 'error', title: __('Errore'), text: __('Impossibile caricare gli autori. Controlla la console per i dettagli.') });
        }
      },
      dataSrc: function(json) {
        // Update total count
        const totalCount = json.recordsTotal || 0;
        document.getElementById('total-count').textContent = totalCount.toLocaleString() + ' autori';
        return json.data;
      }
    },
    columns: [
      {
        data: null, className: 'all',
        render: function(_, type, row) {
          const nome = row.nome || __('Autore sconosciuto');
          const nazionalita = row.nazionalita ? `<div class="text-xs text-gray-500 mt-1">${row.nazionalita}</div>` : '';

          return `<div>
            <div class="font-medium text-blue-600 hover:text-blue-800 transition">
              <a href="/admin/autori/${row.id}">${nome}</a>
            </div>
            ${nazionalita}
          </div>`;
        }
      },
      {
        data: 'pseudonimo',
        render: function(data, type, row) {
          return data ? `<span class="italic text-gray-700">"${data}"</span>` : '<span class="text-gray-400">-</span>';
        }
      },
      {
        data: 'nazionalita',
        className: 'text-center',
        render: function(data, type, row) {
          return data ? `<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
            <i class="fas fa-flag mr-1"></i>${data}
          </span>` : '<span class="text-gray-400">-</span>';
        }
      },
      {
        data: null,
        className: 'text-center',
        render: function(data, type, row) {
          const count = row.libri_count || row.numero_libri || 0;
          const badgeClass = count > 0 ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800';
          return `<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${badgeClass}">
            <i class="fas fa-book mr-1"></i>${count}
          </span>`;
        }
      },
      {
        data: 'id',
        orderable: false,
        searchable: false,
        className: 'all text-right',
        render: function(data, type, row) {
          return `
            <div class="flex items-center justify-center space-x-1">
              <a href="/admin/autori/${data}"
                 class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-gray-600 hover:text-blue-600 hover:bg-blue-50 transition-all duration-200"
                 title="<?= __("Visualizza dettagli") ?>">
                <i class="fas fa-eye text-sm"></i>
              </a>
              <a href="/admin/autori/modifica/${data}"
                 class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-gray-600 hover:text-green-600 hover:bg-green-50 transition-all duration-200"
                 title="<?= __("Modifica") ?>">
                <i class="fas fa-edit text-sm"></i>
              </a>
              <button onclick="deleteAuthor(${data})"
                      class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-gray-600 hover:text-red-600 hover:bg-red-50 transition-all duration-200"
                      title="<?= __("Elimina") ?>">
                <i class="fas fa-trash text-sm"></i>
              </button>
            </div>`;
        }
      }
    ],
    order: [[1, 'asc']],
    pageLength: 25,
    lengthMenu: [
      [10, 25, 50, 100, -1],
      [10, 25, 50, 100, __("Tutti")]
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
    }
  });

  // Filter event handlers
  const reloadDebounced = debounce(()=> table.ajax.reload());

  // Add event listeners for filter inputs
  const filterInputs = ['search_nome', 'search_pseudonimo', 'search_nazionalita', 'search_sito', 'nascita_from', 'nascita_to', 'morte_from', 'morte_to'];
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
      document.getElementById('search_nome').value = globalSearch.value;
      table.ajax.reload();
    }, 300));
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
          text.textContent = __('Nascondi filtri');
        } else {
          icon.className = 'fas fa-chevron-down';
          text.textContent = __('Mostra filtri');
        }
      });
    }
  }

  function initializeClearFilters() {
    const clearBtn = document.getElementById('clear-filters');
    if (clearBtn) {
      clearBtn.addEventListener('click', function() {
        // Clear all filter inputs
        const filterIds = ['search_nome', 'search_pseudonimo', 'search_nazionalita', 'search_sito', 'nascita_from', 'nascita_to', 'morte_from', 'morte_to'];
        filterIds.forEach(id => {
          const element = document.getElementById(id);
          if (element) element.value = '';
        });

        // Reload table
        table.ajax.reload();

        // Show success message
        if (window.Swal) {
          Swal.fire({
            icon: 'success',
            title: __('Filtri cancellati'),
            text: __('Tutti i filtri sono stati rimossi'),
            timer: 2000,
            showConfirmButton: false
          });
        }
      });
    }
  }

  function initializeExportButtons() {
    // Excel export
    document.getElementById('export-excel').addEventListener('click', function() {
      const currentData = table.rows({search: 'applied'}).data().toArray();
      if (currentData.length === 0) {
        if (window.Swal) {
          Swal.fire({
            icon: 'info',
            title: __('Nessun dato'),
            text: __('Non ci sono dati da esportare')
          });
        }
        return;
      }

      // Create CSV content
      let csvContent = "Nome,Pseudonimo,Nazionalita,Sito Web,Biografia\n";
      currentData.forEach(row => {
        const nome = (row.nome || '').replace(/"/g, '""');
        const pseudonimo = (row.pseudonimo || '').replace(/"/g, '""');
        const nazionalita = (row.nazionalita || '').replace(/"/g, '""');
        const sitoWeb = (row.sito_web || '').replace(/"/g, '""');
        const biografia = (row.biografia ? 'Sì' : 'No').replace(/"/g, '""');
        csvContent += `"${nome}","${pseudonimo}","${nazionalita}","${sitoWeb}","${biografia}"\n`;
      });

      // Create download link
      const blob = new Blob(["\ufeff", csvContent], {type: 'text/csv;charset=utf-8;'});
      const link = document.createElement("a");
      const url = URL.createObjectURL(blob);
      link.setAttribute("href", url);
      link.setAttribute("download", "autori_export_" + new Date().toISOString().slice(0, 10) + ".csv");
      link.style.visibility = 'hidden';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
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
            title: __('Nessun dato'),
            text: __('Non ci sono dati da esportare')
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
          generateAuthorsPDF(currentData);
        };
        document.head.appendChild(script);
      } else {
        generateAuthorsPDF(currentData);
      }
    });
  }

  function generateAuthorsPDF(data) {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    
    // Title
    doc.setFontSize(18);
    doc.text('Elenco Autori - Biblioteca', 14, 22);
    
    // Date
    doc.setFontSize(11);
    doc.text(`Generato il: ${new Date().toLocaleDateString('it-IT')}`, 14, 30);
    
    // Total count
    doc.text(`Totale autori: ${data.length}`, 14, 38);
    
    // Table headers
    const headers = [__('Nome'), __('Pseudonimo'), __('Nazionalità'), __('Libri')];
    let yPos = 50;
    
    // Set font for table
    doc.setFontSize(10);
    
    // Column widths
    const colWidths = [60, 45, 45, 20];
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
      
      const nome = (row.nome || '').substring(0, 25);
      const pseudonimo = (row.pseudonimo || '').substring(0, 20);
      const nazionalita = (row.nazionalita || '').substring(0, 15);
      const libri = (row.libri_count || row.numero_libri || 0).toString();
      
      const rowData = [nome, pseudonimo, nazionalita, libri];
      
      rowData.forEach((cell, i) => {
        doc.text(cell, startX + colWidths.slice(0, i).reduce((a, b) => a + b, 0), yPos);
      });
      
      yPos += 7;
    });
    
    // Save the PDF
    doc.save(`autori_export_${new Date().toISOString().slice(0, 10)}.pdf`);
  }

  // Delete author function (POST with CSRF)
  window.deleteAuthor = function(authorId) {
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const confirmAndSubmit = () => {
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = `/admin/autori/delete/${authorId}`;
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
        title: __('Sei sicuro?'),
        text: __('Questa azione non può essere annullata!'),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: __('Sì, elimina!'),
        cancelButtonText: __('Annulla')
      }).then((result) => { if (result.isConfirmed) confirmAndSubmit(); });
    } else {
      if (confirm(__('Sei sicuro di voler eliminare questo autore?'))) confirmAndSubmit();
    }
  };

});
</script>

<!-- Custom Styles for Enhanced UI -->
<style>
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

/* DataTables responsive enhancements */
.dataTables_wrapper .dataTables_processing {
  @apply bg-white/90 backdrop-blur-sm border border-gray-200 rounded-lg shadow-lg;
}

.dataTables_wrapper .dataTables_paginate .paginate_button {
  @apply px-3 py-2 text-sm border border-gray-300 bg-white hover:bg-gray-50 transition-colors duration-200;
}

.dataTables_wrapper .dataTables_paginate .paginate_button.current {
  @apply bg-gray-900 text-white border-blue-600 hover:bg-blue-700;
}

.dataTables_wrapper .dataTables_length select {
  @apply form-input py-1 text-sm;
}

.dataTables_wrapper .dataTables_filter input {
  @apply form-input;
}

.dataTables_wrapper .dataTables_info {
  @apply text-sm text-gray-600;
}

#autori-table thead th {
  @apply bg-gray-50 font-semibold text-gray-700 border-b-2 border-gray-200 px-4 py-3;
}

#autori-table tbody td {
  @apply px-4 py-2.5 border-b border-gray-100;
}

/* Remove all zebra striping and borders between rows */
#autori-table tbody tr td {
  background-color: transparent;
  border: none;
}

/* Ensure all table rows have the same background */
#autori-table tbody tr {
  background-color: white;
}

#autori-table tbody tr:hover {
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
</style>

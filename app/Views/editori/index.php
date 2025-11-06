<?php
/**
 * @var array $data { editori: array }
 */
$title = "Editori";
$editori = $data['editori'];
?>
<!-- Modern Publishers Management Interface -->
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li>
          <a href="/admin/dashboard" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i>Home
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li class="text-gray-900 font-medium">
          <a href="/admin/editori" class="text-gray-900 hover:text-gray-900">
            <i class="fas fa-building mr-1"></i>Editori
          </a>
        </li>
      </ol>
    </nav>

    <!-- Modern Header -->
    <div class="mb-6 fade-in">
      <div class="flex items-center justify-between mb-3">
        <div>
          <h1 class="text-3xl font-bold text-gray-900 flex items-center">
            <i class="fas fa-building text-gray-800 mr-3"></i>
            Gestione Editori
          </h1>
          <p class="text-sm text-gray-600 mt-1">Esplora e gestisci gli editori della biblioteca</p>
        </div>
        <div class="hidden md:flex items-center gap-3">
          <div class="hidden md:block">
            <input id="global_search" type="text" placeholder="Cerca rapido..." class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 w-64" />
          </div>
          <a href="/admin/editori/crea" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center">
            <i class="fas fa-plus mr-2"></i>
            Nuovo Editore
          </a>
        </div>
      </div>
      <div class="flex md:hidden mb-3">
        <a href="/admin/editori/crea" class="w-full px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center justify-center">
          <i class="fas fa-plus mr-2"></i>
          Nuovo Editore
        </a>
      </div>
    </div>

    <!-- Advanced Filters Card -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-5 slide-in-up">
      <div class="p-6 border-b border-gray-200 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-filter text-gray-600 mr-2"></i>
          Filtri di Ricerca
        </h2>
        <button id="toggle-filters" class="text-sm text-gray-600 hover:text-gray-800">
          <i class="fas fa-chevron-up"></i>
          <span>Nascondi filtri</span>
        </button>
      </div>
      <div class="p-6" id="filters-container">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
          <div>
            <label for="search_nome" class="form-label">
              <i class="fas fa-search mr-1 text-gray-700"></i>
              Nome editore
            </label>
            <input id="search_nome" placeholder="Cerca per nome..." class="form-input" />
          </div>

          <div>
            <label for="search_sito" class="form-label">
              <i class="fas fa-globe mr-1 text-gray-700"></i>
              Sito web
            </label>
            <input id="search_sito" placeholder="URL sito web..." class="form-input" />
          </div>

          <div>
            <label for="search_libri" class="form-label">
              <i class="fas fa-book mr-1 text-gray-700"></i>
              Numero di libri
            </label>
            <select id="search_libri" class="form-input">
              <option value="">Tutti gli editori</option>
              <option value="0-10">0-10 libri</option>
              <option value="11-50">11-50 libri</option>
              <option value="51-100">51-100 libri</option>
              <option value="101-500">101-500 libri</option>
              <option value="501+">Più di 500 libri</option>
            </select>
          </div>
        </div>

        <div class="flex justify-between items-center pt-4 border-t border-gray-200">
          <div class="flex items-center text-sm text-gray-500">
            <i class="fas fa-info-circle text-gray-700 mr-2"></i>
            <span>I filtri vengono applicati automaticamente mentre digiti</span>
          </div>
          <button id="clear-filters" class="btn-secondary">
            <i class="fas fa-times mr-2"></i>
            Cancella filtri
          </button>
        </div>
      </div>
    </div>

    <!-- Data Table Card -->
    <div class="card">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-table text-gray-800 mr-2"></i>
          Elenco Editori
          <span id="total-count" class="ml-2 px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded-full"></span>
        </h2>
        <div id="export-buttons" class="flex items-center gap-2">
          <button id="export-excel" class="btn-outline inline-flex items-center px-3 py-1.5 text-sm" title="Esporta Excel">
            <i class="fas fa-file-excel mr-1"></i>
            Excel
          </button>
          <button id="export-pdf" class="btn-outline inline-flex items-center px-3 py-1.5 text-sm" title="Esporta PDF">
            <i class="fas fa-file-pdf mr-1"></i>
            PDF
          </button>
          <button id="print-table" class="btn-outline inline-flex items-center px-3 py-1.5 text-sm" title="Stampa">
            <i class="fas fa-print mr-1"></i>
            Stampa
          </button>
        </div>
      </div>
      <div class="card-body">
        <div class="overflow-x-auto">
              <table id="editori-table" class="display responsive nowrap" style="width:100%">
                <thead>
                  <tr>
                    <th><?= __('Nome') ?></th>
                    <th><?= __('Sito Web') ?></th>
                    <th style="width:25%"><?= __('Indirizzo') ?></th>
                    <th><?= __('Città') ?></th>
                    <th style="width:10%" class="text-center"><?= __('Azioni') ?></th>
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
document.addEventListener('DOMContentLoaded', function() {

  // Check if DataTables is available
  if (typeof DataTable === 'undefined') {
    console.error('DataTable is not loaded!');
    return;
  }

  // Debounce helper
  const debounce = (fn, ms=300) => { let t; return (...args)=>{ clearTimeout(t); t=setTimeout(()=>fn(...args),ms); }; };

  // Initialize DataTable with modern features
  const table = new DataTable('#editori-table', {
    processing: true,
    serverSide: true,
    responsive: true,
    searching: false, // custom
    stateSave: true,
    dom: 'lrtip', // Configura il layout senza i pulsanti DataTables predefiniti ('B')
    ajax: {
      url: '/api/editori',
      type: 'GET',
      data: function(d) {
        return {
          ...d,
          search_nome: document.getElementById('search_nome').value,
          search_sito: document.getElementById('search_sito').value,
          search_libri: document.getElementById('search_libri').value
        };
      },
      error: function(xhr, status, err) {
        console.error('Errore caricamento /api/editori:', { status, err, responseText: xhr && xhr.responseText });
        if (window.Swal) {
          Swal.fire({ icon: 'error', title: __('Errore'), text: __('Impossibile caricare gli editori. Controlla la console per i dettagli.') });
        }
      },
      dataSrc: function(json) {
        // Update total count
        const totalCount = json.recordsTotal || 0;
        document.getElementById('total-count').textContent = totalCount.toLocaleString() + ' editori';
        return json.data;
      }
    },
    columns: [
      {
        data: null, className: 'all',
        render: function(_, type, row) {
          const nome = row.nome || 'Editore sconosciuto';
          
          return `<div>
            <div class="font-medium text-blue-600 hover:text-blue-800 transition">
              <a href="/admin/editori/${row.id}">${nome}</a>
            </div>
          </div>`;
        }
      },
      {
        data: 'sito_web',
        render: function(data, type, row) {
          return data ? `<a href="${data}" target="_blank" class="text-blue-600 hover:text-blue-800 hover:underline text-sm">
            <i class="fas fa-external-link-alt mr-1"></i>${data.substring(0, 30)}${data.length > 30 ? '...' : ''}
          </a>` : '<span class="text-gray-400">-</span>';
        }
      },
      {
        data: null,
        render: function(data, type, row) {
          const indirizzo = [];
          if (row.via) indirizzo.push(row.via);
          if (row.cap) indirizzo.push(row.cap);

          if (indirizzo.length === 0) {
            return '<span class="text-gray-400">-</span>';
          }

          return `<div class="text-sm">${indirizzo.join(', ')}</div>`;
        }
      },
      {
        data: null,
        className: 'text-center',
        render: function(data, type, row) {
          const city = row.citta || row.città || row.city || '';
          if (!city) {
            return '<span class="text-gray-400">-</span>';
          }
          return `<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
            <i class="fas fa-map-marker-alt mr-1"></i>${city}
          </span>`;
        }
      },
      {
        data: 'id',
        orderable: false,
        searchable: false,
        className: 'all text-center',
        render: function(data, type, row) {
          return `
            <div class="flex items-center gap-1 justify-center">
              <a href="/admin/editori/${data}"
                 class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors duration-200"
                 title="Visualizza dettagli">
                <i class="fas fa-eye text-sm"></i>
              </a>
              <a href="/admin/editori/modifica/${data}"
                 class="p-2 text-yellow-600 hover:bg-yellow-50 rounded-lg transition-colors duration-200"
                 title="Modifica">
                <i class="fas fa-edit text-sm"></i>
              </a>
              <button onclick="deletePublisher(${data})"
                      class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors duration-200"
                      title="Elimina">
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
      [10, 25, 50, 100, "Tutti"]
    ],
    language: window.DT_LANG_IT,
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
  const filterInputs = ['search_nome', 'search_sito', 'search_libri'];
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
          text.textContent = 'Nascondi filtri';
        } else {
          icon.className = 'fas fa-chevron-down';
          text.textContent = 'Mostra filtri';
        }
      });
    }
  }

  function initializeClearFilters() {
    const clearBtn = document.getElementById('clear-filters');
    if (clearBtn) {
      clearBtn.addEventListener('click', function() {
        // Clear all filter inputs
        const filterIds = ['search_nome', 'search_sito', 'search_libri'];
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

      let csvContent = "Nome,Sito Web,Stato\n";
      currentData.forEach(row => {
        const nome = (row.nome || '').replace(/"/g, '""');
        const sitoWeb = (row.sito_web || '').replace(/"/g, '""');
        const stato = "Attivo";
        csvContent += `"${nome}","${sitoWeb}","${stato}"\n`;
      });

      const blob = new Blob(["\ufeff", csvContent], {type: 'text/csv;charset=utf-8;'});
      const link = document.createElement("a");
      const url = URL.createObjectURL(blob);
      link.setAttribute("href", url);
      link.setAttribute("download", "editori_export_" + new Date().toISOString().slice(0, 10) + ".csv");
      link.style.visibility = 'hidden';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    });

    document.getElementById('print-table').addEventListener('click', function() {
      window.print();
    });

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

      if (typeof window.jspdf === 'undefined') {
        const script = document.createElement('script');
        script.src = '/assets/js/jspdf.umd.min.js';
        script.onload = function() {
          generatePublishersPDF(currentData);
        };
        document.head.appendChild(script);
      } else {
        generatePublishersPDF(currentData);
      }
    });
  }

  function generatePublishersPDF(data) {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    
    doc.setFontSize(18);
    doc.text('Elenco Editori - Biblioteca', 14, 22);
    doc.setFontSize(11);
    doc.text(`Generato il: ${new Date().toLocaleDateString('it-IT')}`, 14, 30);
    doc.text(`Totale editori: ${data.length}`, 14, 38);
    doc.setFontSize(10);
    
    const headers = ['Nome', 'Sito Web', 'Indirizzo', 'Città'];
    const colWidths = [50, 50, 60, 30];
    const startX = 14;
    let yPos = 50;
    
    headers.forEach((header, i) => {
      doc.text(header, startX + colWidths.slice(0, i).reduce((a, b) => a + b, 0), yPos);
    });
    yPos += 8;
    
    data.forEach((row, index) => {
      if (yPos > 280) {
        doc.addPage();
        yPos = 20;
      }

      const nome = (row.nome || '').substring(0, 25);
      const sitoWeb = (row.sito_web || '').substring(0, 25);
      const indirizzoParts = [];
      if (row.via) indirizzoParts.push(row.via);
      if (row.cap) indirizzoParts.push(row.cap);
      const indirizzo = indirizzoParts.join(' ') || '-';
      const citta = (row.citta || 'N/D');

      const rowData = [nome, sitoWeb, indirizzo, citta];
      rowData.forEach((cell, i) => {
        doc.text(cell, startX + colWidths.slice(0, i).reduce((a, b) => a + b, 0), yPos);
      });

      yPos += 7;
    });
    
    doc.save(`editori_export_${new Date().toISOString().slice(0, 10)}.pdf`);
  }
  // Delete publisher function (POST with CSRF)
  window.deletePublisher = function(publisherId) {
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const confirmAndSubmit = () => {
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = `/admin/editori/delete/${publisherId}`;
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
      if (confirm(__('Sei sicuro di voler eliminare questo editore?'))) confirmAndSubmit();
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

#editori-table thead th {
  @apply bg-gray-50 font-semibold text-gray-700 border-b-2 border-gray-200 px-4 py-3;
}

#editori-table tbody td {
  @apply px-4 py-2.5 border-b border-gray-100;
}

/* Remove all zebra striping and borders between rows */
#editori-table tbody tr td {
  background-color: transparent;
  border: none;
}

/* Ensure all table rows have the same background */
#editori-table tbody tr {
  background-color: white;
}

#editori-table tbody tr:hover {
  @apply bg-gray-50;
}

/* Force column widths for proper proportions */
#editori-table th:nth-child(1), #editori-table td:nth-child(1) { width: 25% !important; }
#editori-table th:nth-child(2), #editori-table td:nth-child(2) { width: 25% !important; }
#editori-table th:nth-child(3), #editori-table td:nth-child(3) { width: 35% !important; }
#editori-table th:nth-child(4), #editori-table td:nth-child(4) { width: 15% !important; }

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

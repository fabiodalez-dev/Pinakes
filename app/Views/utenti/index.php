<!-- Minimal White Users Management Interface -->
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li>
          <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i>Home
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li class="text-gray-900 font-medium">
          <a href="<?= htmlspecialchars(url('/admin/utenti'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-900 hover:text-gray-700">
            <i class="fas fa-users mr-1"></i>Utenti
          </a>
        </li>
      </ol>
    </nav>
    <!-- Minimal Header -->
    <div class="mb-8 fade-in">
      <div class="flex items-center justify-between mb-4">
        <div>
          <h1 class="text-3xl font-bold text-gray-900 mb-2 flex items-center gap-3">
            <i class="fas fa-users text-gray-600"></i>
            <?= __("Gestione Utenti") ?>
          </h1>
          <p class="text-gray-600"><?= __("Esplora e gestisci gli utenti registrati alla biblioteca") ?></p>
        </div>
        <div class="hidden md:flex items-center gap-3">
          <a href="<?= htmlspecialchars(url('/admin/utenti/crea'), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center">
            <i class="fas fa-user-plus mr-2"></i>
            <?= __("Nuovo Utente") ?>
          </a>
        </div>
      </div>
      <div class="flex md:hidden mb-4">
        <a href="<?= htmlspecialchars(url('/admin/utenti/crea'), ENT_QUOTES, 'UTF-8') ?>" class="w-full px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center justify-center">
          <i class="fas fa-user-plus mr-2"></i>
          <?= __("Nuovo Utente") ?>
        </a>
      </div>
    </div>

    <!-- Success Messages -->
    <?php if(isset($_GET['created']) && $_GET['created'] == '1'): ?>
      <div class="mb-6 p-4 bg-green-50 text-green-800 rounded-lg border border-green-200 slide-in-up" role="alert">
        <div class="flex items-center gap-2">
          <i class="fas fa-check-circle"></i>
          <span><?= __("Utente creato con successo!") ?></span>
        </div>
      </div>
    <?php endif; ?>
    <?php if(isset($_GET['updated']) && $_GET['updated'] == '1'): ?>
      <div class="mb-6 p-4 bg-green-50 text-green-800 rounded-lg border border-green-200 slide-in-up" role="alert">
        <div class="flex items-center gap-2">
          <i class="fas fa-check-circle"></i>
          <span><?= __("Utente aggiornato con successo!") ?></span>
        </div>
      </div>
    <?php endif; ?>

    <!-- Pending Users Approval Widget -->
    <?php if (!empty($pendingUsers)): ?>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6 slide-in-up">
      <div class="p-6 border-b border-gray-200 flex flex-col md:flex-row items-center justify-between gap-4">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-user-clock text-amber-600 mr-2"></i>
          <?= __("Utenti in Attesa di Approvazione") ?>
          <span class="ml-2 px-2 py-1 bg-amber-100 text-amber-800 text-xs rounded-full"><?= count($pendingUsers) ?></span>
        </h2>
      </div>
      <div class="p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
          <?php foreach ($pendingUsers as $user): ?>
            <div class="flex flex-col bg-gray-50 border border-gray-200 rounded-xl p-5 shadow-sm">
              <div class="flex flex-col gap-3">
                <div class="flex items-start justify-between">
                  <div>
                    <h3 class="font-semibold text-gray-900 mb-1">
                      <?= \App\Support\HtmlHelper::e($user['nome'] . ' ' . $user['cognome']) ?>
                    </h3>
                    <p class="text-xs text-gray-500 font-mono">
                      <i class="fas fa-id-card mr-1"></i><?= \App\Support\HtmlHelper::e($user['codice_tessera'] ?? 'N/A') ?>
                    </p>
                  </div>
                  <a href="<?= htmlspecialchars(url('/admin/utenti/dettagli/' . (int)$user['id']), ENT_QUOTES, 'UTF-8') ?>"
                     class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors"
                     title="<?= __("Visualizza dettagli") ?>">
                    <i class="fas fa-external-link-alt text-sm"></i>
                  </a>
                </div>

                <div class="space-y-1">
                  <?php if (!empty($user['email'])): ?>
                    <p class="text-sm text-gray-600 flex items-center">
                      <i class="fas fa-envelope mr-2 text-blue-500 w-4"></i>
                      <a href="mailto:<?= \App\Support\HtmlHelper::e($user['email']) ?>" class="hover:underline truncate">
                        <?= \App\Support\HtmlHelper::e($user['email']) ?>
                      </a>
                    </p>
                  <?php endif; ?>
                  <?php if (!empty($user['telefono'])): ?>
                    <p class="text-sm text-gray-600 flex items-center">
                      <i class="fas fa-phone mr-2 text-green-500 w-4"></i>
                      <a href="tel:<?= \App\Support\HtmlHelper::e($user['telefono']) ?>" class="hover:underline">
                        <?= \App\Support\HtmlHelper::e($user['telefono']) ?>
                      </a>
                    </p>
                  <?php endif; ?>
                </div>
              </div>

              <div class="mt-4 flex flex-col sm:flex-row gap-2">
                <form method="POST" action="<?= htmlspecialchars(url('/admin/utenti/' . (int)$user['id'] . '/approve-and-send-activation'), ENT_QUOTES, 'UTF-8') ?>" class="flex-1">
                  <input type="hidden" name="csrf_token" value="<?= \App\Support\Csrf::ensureToken() ?>">
                  <button type="submit" class="w-full bg-gray-900 hover:bg-gray-700 text-white font-medium py-2 px-3 rounded-lg transition-colors text-sm inline-flex items-center justify-center gap-2">
                    <i class="fas fa-envelope"></i>
                    <span><?= __("Invia Email") ?></span>
                  </button>
                </form>
                <form method="POST" action="<?= htmlspecialchars(url('/admin/utenti/' . (int)$user['id'] . '/activate-directly'), ENT_QUOTES, 'UTF-8') ?>" class="flex-1" onsubmit="return confirm('<?= addslashes(__('Confermi di voler attivare direttamente questo utente?')) ?>')">
                  <input type="hidden" name="csrf_token" value="<?= \App\Support\Csrf::ensureToken() ?>">
                  <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-3 rounded-lg transition-colors text-sm inline-flex items-center justify-center gap-2">
                    <i class="fas fa-user-check"></i>
                    <span><?= __("Attiva") ?></span>
                  </button>
                </form>
              </div>

              <div class="mt-3 text-xs text-gray-400 flex items-center">
                <i class="fas fa-clock mr-2"></i>
                <?= __("Registrato il") ?> <?= !empty($user['created_at']) ? format_date((string)$user['created_at'], true, '/') : 'N/D' ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- White Filters Card -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6 slide-in-up">
      <div class="p-6 border-b border-gray-200 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-filter text-gray-600"></i>
          <?= __("Filtri di Ricerca") ?>
        </h2>
        <div class="flex items-center gap-2">
          <button id="btn-pending-approvals" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200">
            <i class="fas fa-user-clock mr-2"></i> <?= __("Solo sospesi") ?>
          </button>
          <button id="toggle-filters" class="text-sm text-gray-600 hover:text-gray-800">
            <i class="fas fa-chevron-up"></i>
            <span><?= __("Nascondi filtri") ?></span>
          </button>
        </div>
      </div>
      <div class="p-6" id="filters-container">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
          <div>
            <label class="form-label">
              <i class="fas fa-search mr-1"></i>
              <?= __("Cerca testo") ?>
            </label>
            <input id="search_text" placeholder="<?= __('Nome, cognome, email...') ?>" class="form-input" />
          </div>
          
          <div>
            <label class="form-label">
              <i class="fas fa-user-tag mr-1"></i>
              <?= __("Ruolo") ?>
            </label>
            <select id="role_filter" class="form-input">
              <option value=""><?= __("Tutti i ruoli") ?></option>
              <option value="admin"><?= __("Amministratore") ?></option>
              <option value="staff"><?= __("Staff") ?></option>
              <option value="premium"><?= __("Premium") ?></option>
              <option value="standard"><?= __("Standard") ?></option>
            </select>
          </div>
          
          <div>
            <label class="form-label">
              <i class="fas fa-info-circle mr-1"></i>
              <?= __("Stato") ?>
            </label>
            <select id="status_filter" class="form-input">
              <option value=""><?= __("Tutti gli stati") ?></option>
              <option value="attivo"><?= __("Attivo") ?></option>
              <option value="sospeso"><?= __("Sospeso") ?></option>
              <option value="scaduto"><?= __("Scaduto") ?></option>
            </select>
          </div>
          
          <div>
            <label class="form-label">
              <i class="fas fa-calendar mr-1"></i>
              <?= __("Registrato da") ?>
            </label>
            <input id="created_from" type="date" class="form-input" />
          </div>
        </div>

        <div class="flex justify-between items-center pt-4 border-t border-gray-200">
          <div class="flex items-center gap-2 text-sm text-gray-500">
            <i class="fas fa-info-circle"></i>
            <span><?= __("I filtri vengono applicati automaticamente mentre digiti") ?></span>
          </div>
          <button id="clear-filters" class="btn-secondary">
            <i class="fas fa-times mr-2"></i>
            <?= __("Cancella filtri") ?>
          </button>
        </div>
      </div>
    </div>

    <!-- Data Table Card -->
    <div class="card">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-table text-primary"></i>
          <?= __("Elenco Utenti") ?>
          <span id="total-count" class="ml-2 px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded-full"></span>
        </h2>
        <div id="export-buttons" class="flex items-center space-x-2">
          <button id="export-excel" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="<?= __("Esporta CSV (formato compatibile per import)") ?>">
            <i class="fas fa-file-csv mr-1"></i>
            CSV
          </button>
          <button id="export-pdf" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="<?= __("Esporta PDF") ?>">
            <i class="fas fa-file-pdf mr-1"></i>
            PDF
          </button>
          <button id="print-table" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="<?= __("Stampa") ?>">
            <i class="fas fa-print mr-1"></i>
            <?= __("Stampa") ?>
          </button>
        </div>
      </div>
      <div class="card-body">
        <!-- Mobile scroll hint -->
        <div class="md:hidden mb-3 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-800 flex items-center gap-2">
          <i class="fas fa-hand-point-right"></i>
          <span><?= __("Scorri a destra per vedere tutte le colonne") ?></span>
        </div>
        <div class="overflow-x-auto">
          <table id="utenti-table" class="display nowrap" style="width:100%">
            <thead>
              <tr>
                <th><?= __("Nome") ?></th>
                <th><?= __("Email") ?></th>
                <th><?= __("Telefono") ?></th>
                <th><?= __("Tipo Utente") ?></th>
                <th><?= __("Stato") ?></th>
                <th><?= __("Azioni") ?></th>
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
    'utenti' => __("utenti"),
    'Mostra filtri' => __("Mostra filtri"),
    'Nascondi filtri' => __("Nascondi filtri"),
    'Filtri cancellati' => __("Filtri cancellati"),
    'Tutti i filtri sono stati rimossi' => __("Tutti i filtri sono stati rimossi"),
    'Esportazione di %d utenti filtrati su %d totali' => __("Exporting %d filtered users out of %d total"),
    'Esportazione di tutti i %d utenti' => __("Exporting all %d users"),
    'Totale utenti:' => __("Total users:"),
    'Scorri a destra per vedere tutte le colonne' => __("Scorri a destra per vedere tutte le colonne"),
], JSON_UNESCAPED_UNICODE) ?>;

// Global translation function for JavaScript
window.__ = function(key) {
    return i18nTranslations[key] || key;
};

// Set current locale for DataTables language selection
window.i18nLocale = <?= json_encode(\App\Support\I18n::getLocale()) ?>;
// formatDateLocale and appLocale are defined globally in layout.php

document.addEventListener('DOMContentLoaded', function() {
  
  // Check if DataTables is available
  if (typeof DataTable === 'undefined') {
    console.error('DataTable is not loaded!');
    return;
  }

  // Initialize DataTable with modern features
  const table = new DataTable('#utenti-table', {
    processing: true,
    serverSide: true,
    responsive: false, // Disabilitiamo responsive mode - usiamo solo scroll orizzontale
    searching: false, // We use custom search
    dom: 'lfrtip',
    scrollX: true,
    autoWidth: false,
    ajax: {
      url: window.BASE_PATH + '/api/utenti',
      type: 'GET',
      data: function(d) {
        return {
          ...d,
          search_text: $('#search_text').val(),
          role_filter: $('#role_filter').val(),
          status_filter: $('#status_filter').val(),
          created_from: $('#created_from').val()
        };
      },
      dataSrc: function(json) {
        // Update total count
        const totalCount = json.recordsTotal || 0;
        $('#total-count').text(totalCount.toLocaleString() + ' ' + __('utenti'));
        return json.data;
      }
    },
    columns: [
      {
        data: 'nome',
        render: function(data, type, row) {
          const nome = escapeHtml(decodeHtml(data)) || 'N/A';
          const cognome = escapeHtml(decodeHtml(row.cognome)) || '';
          const nomeCompleto = cognome ? `${nome} ${cognome}` : nome;
          const tessera = escapeHtml(decodeHtml(row.codice_tessera)) || 'N/A';
          const userId = encodeURIComponent(String(row.id ?? ''));

          return `
            <a href="${window.BASE_PATH}/admin/utenti/dettagli/${userId}"
               class="block hover:bg-blue-50 -m-2 p-2 rounded transition-colors duration-200">
              <div class="font-semibold text-gray-900 text-base">
                ${nomeCompleto}
              </div>
              <div class="text-xs text-gray-500 mt-0.5 font-mono">
                <i class="fas fa-id-card mr-1"></i>${tessera}
              </div>
            </a>`;
        }
      },
      {
        data: 'email',
        render: function(data, type, row) {
          if (!data) return '<span class="text-gray-400 text-sm">N/A</span>';
          const emailText = escapeHtml(decodeHtml(String(data)));
          return `<a href="mailto:${encodeURIComponent(data)}" class="text-blue-600 hover:text-blue-800 text-sm hover:underline">${emailText}</a>`;
        }
      },
      {
        data: 'telefono',
        render: function(data, type, row) {
          if (!data) return '<span class="text-gray-400 text-sm">N/A</span>';
          const telText = escapeHtml(decodeHtml(String(data)));
          return `<a href="tel:${encodeURIComponent(data)}" class="text-blue-600 hover:text-blue-800 text-sm hover:underline">${telText}</a>`;
        }
      },
      {
        data: 'tipo_utente',
        render: function(data, type, row) {
          const roleClass = {
            'admin': 'bg-red-100 text-red-800',
            'staff': 'bg-blue-100 text-blue-800', 
            'premium': 'bg-purple-100 text-purple-800',
            'standard': 'bg-gray-100 text-gray-800'
          };
          const roleIcon = {
            'admin': 'fas fa-crown',
            'staff': 'fas fa-user-tie',
            'premium': 'fas fa-star',
            'standard': 'fas fa-user'
          };
          const colorClass = roleClass[data] || 'bg-gray-100 text-gray-800';
          const iconClass = roleIcon[data] || 'fas fa-user';
          return `<span class="px-2 py-1 rounded-full text-xs font-medium ${colorClass} inline-flex items-center gap-1">
            <i class="${iconClass}"></i>
            ${data || 'N/D'}
          </span>`;
        }
      },
      {
        data: 'stato',
        render: function(data, type, row) {
          const statusClass = {
            'attivo': 'bg-green-100 text-green-800',
            'sospeso': 'bg-yellow-100 text-yellow-800',
            'scaduto': 'bg-red-100 text-red-800'
          };
          const statusIcon = {
            'attivo': 'fas fa-check-circle',
            'sospeso': 'fas fa-pause-circle',
            'scaduto': 'fas fa-times-circle'
          };
          const colorClass = statusClass[data] || 'bg-gray-100 text-gray-800';
          const iconClass = statusIcon[data] || 'fas fa-question-circle';
          return `<span class="px-2 py-1 rounded-full text-xs font-medium ${colorClass} inline-flex items-center gap-1">
            <i class="${iconClass}"></i>
            ${data || 'N/D'}
          </span>`;
        }
      },
      {
        data: 'id',
        orderable: false,
        searchable: false,
        render: function(data, type, row) {
          const id = encodeURIComponent(String(data ?? ''));
          return `
            <div class="flex items-center gap-1">
              <a href="${window.BASE_PATH}/admin/utenti/dettagli/${id}"
                 class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors duration-200"
                 title="<?= __("Visualizza dettagli") ?>">
                <i class="fas fa-eye text-sm"></i>
              </a>
              <a href="${window.BASE_PATH}/admin/utenti/modifica/${id}"
                 class="p-2 text-yellow-600 hover:bg-yellow-50 rounded-lg transition-colors duration-200"
                 title="<?= __("Modifica") ?>">
                <i class="fas fa-edit text-sm"></i>
              </a>
              <button onclick="deleteUser(${Number(data)})"
                      class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors duration-200"
                      title="<?= __("Elimina") ?>">
                <i class="fas fa-trash text-sm"></i>
              </button>
            </div>`;
        }
      }
    ],
    order: [[0, 'asc']], // Order by nome (utente column)
    pageLength: 25,
    lengthMenu: [
      [10, 25, 50, 100, -1],
      [10, 25, 50, 100, "Tutti"]
    ],
  language: (window.i18nLocale === 'en_US' ? window.DT_LANG_EN : window.DT_LANG_IT),
    initComplete: function() {

      // Initialize filter toggle
      initializeFilterToggle();

      // Initialize clear filters
      initializeClearFilters();

      // Initialize export buttons
      initializeExportButtons();

      // Initialize scroll shadow effect
      initializeScrollShadow();
    }
  });

  // Scroll shadow effect for mobile
  function initializeScrollShadow() {
    const scrollContainer = document.querySelector('.overflow-x-auto');
    if (!scrollContainer) return;

    scrollContainer.addEventListener('scroll', function() {
      const scrollLeft = this.scrollLeft;
      const scrollWidth = this.scrollWidth;
      const clientWidth = this.clientWidth;

      // Check if scrolled to the end
      if (scrollLeft + clientWidth >= scrollWidth - 10) {
        this.classList.add('scrolled-end');
      } else {
        this.classList.remove('scrolled-end');
      }
    });
  }

  // Filter event handlers
  $('#search_text, #role_filter, #status_filter, #created_from').on('keyup change', function() {
    table.ajax.reload();
  });

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
        $('#search_text, #role_filter, #status_filter, #created_from').val('');
        
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

  // Quick filter: pending approvals
  const btnPending = document.getElementById('btn-pending-approvals');
  if (btnPending) {
    btnPending.addEventListener('click', function() {
      const statusSel = document.getElementById('status_filter');
      if (statusSel) { statusSel.value = 'sospeso'; }
      table.ajax.reload();
    });
  }

  // Delete user function
  window.deleteUser = function(userId) {
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
      }).then((result) => {
        if (result.isConfirmed) {
          // Make DELETE request
          const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
          fetch(`${window.BASE_PATH}/admin/utenti/delete/${userId}`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `csrf_token=${encodeURIComponent(csrfToken)}`
          })
          .then(response => {
            if (response.ok || response.redirected) {
              table.ajax.reload(null, false);
              Swal.fire(__('Eliminato!'), __('L\'utente è stato eliminato.'), 'success');
            } else {
              return response.text().then(text => {
                console.error('Delete failed - Status:', response.status, 'Body:', text);
                Swal.fire(__('Errore!'), __('Non è stato possibile eliminare l\'utente. Controlla la console.'), 'error');
              });
            }
          })
          .catch(error => {
            console.error('Delete error:', error);
            Swal.fire(__('Errore!'), __('Si è verificato un errore: %s').replace('%s', error.message), 'error');
          });
        }
      });
    } else {
      if (confirm(__('Sei sicuro di voler eliminare questo utente?'))) {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        fetch(`${window.BASE_PATH}/admin/utenti/delete/${userId}`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: `csrf_token=${encodeURIComponent(csrfToken)}`
        })
        .then(() => table.ajax.reload(null, false))
        .catch(error => console.error('Delete error:', error));
      }
    }
  };

  // Utility function
  function decodeHtml(html) {
    if (!html) return '';
    var txt = document.createElement("textarea");
    txt.innerHTML = html;
    return txt.value;
  }

  function escapeHtml(value) {
    var div = document.createElement('div');
    div.textContent = value ?? '';
    return div.innerHTML;
  }

  // Initialize export buttons
  function initializeExportButtons() {
    // CSV export
    document.getElementById('export-excel').addEventListener('click', function() {
      // Get current filters
      const params = new URLSearchParams();

      // Search text
      const searchText = document.getElementById('search_text')?.value || '';
      if (searchText) {
        params.append('search_text', searchText);
      }

      // Role filter
      const roleFilter = document.getElementById('role_filter')?.value || '';
      if (roleFilter) {
        params.append('role_filter', roleFilter);
      }

      // Status filter
      const statusFilter = document.getElementById('status_filter')?.value || '';
      if (statusFilter) {
        params.append('status_filter', statusFilter);
      }

      // Created from filter
      const createdFrom = document.getElementById('created_from')?.value || '';
      if (createdFrom) {
        params.append('created_from', createdFrom);
      }

      // Check if any filters are applied
      const hasFilters = params.toString().length > 0;
      const filteredCount = table.rows({search: 'applied'}).count();
      const totalCount = table.rows().count();

      const message = hasFilters
        ? __('Esportazione di %d utenti filtrati su %d totali').replace('%d', filteredCount).replace('%d', totalCount)
        : __('Esportazione di tutti i %d utenti').replace('%d', totalCount);

      if (window.Swal) {
        Swal.fire({
          icon: 'info',
          title: __('Generazione CSV in corso...'),
          text: message,
          showConfirmButton: false,
          timer: 1500
        });
      }

      // Redirect to server-side export endpoint with filters
      const url = window.BASE_PATH + '/admin/utenti/export/csv' + (params.toString() ? '?' + params.toString() : '');
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
        script.src = '<?= assetUrl("js/jspdf.umd.min.js") ?>';
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
      doc.text(__('Elenco Utenti - Biblioteca'), 14, 22);

      // Date
      doc.setFontSize(11);
      doc.text(`${__('Generato il:')} ${formatDateLocale(new Date())}`, 14, 30);

      // Total count
      doc.text(`${__('Totale utenti:')} ${data.length}`, 14, 38);

      // Table headers
      const headers = [__('Nome'), __('Cognome'), __('Email'), __('Ruolo'), __('Stato')];
      let yPos = 50;

      // Set font for table
      doc.setFontSize(10);

      // Column widths
      const colWidths = [40, 40, 60, 25, 25];
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
          (decodeHtml(row.nome) || '').substring(0, 20),
          (decodeHtml(row.cognome) || '').substring(0, 20),
          (row.email || '').substring(0, 35),
          (row.tipo_utente || '').substring(0, 15),
          (row.stato || '').substring(0, 15)
        ];

        rowData.forEach((cell, i) => {
          doc.text(cell, startX + colWidths.slice(0, i).reduce((a, b) => a + b, 0), yPos);
        });

        yPos += 7;
      });

      // Save the PDF
      doc.save(`utenti_${new Date().toISOString().slice(0, 10)}.pdf`);

      if (window.Swal) {
        Swal.fire({
          icon: 'success',
          title: __('PDF generato!'),
          text: __('Il download dovrebbe iniziare automaticamente'),
          timer: 2000,
          showConfirmButton: false
        });
      }
    }
  }

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

/* Pagination buttons - hide disabled navigation buttons */
.dataTables_wrapper .dataTables_paginate .paginate_button {
  @apply px-3 py-2 text-sm border border-gray-300 bg-white hover:bg-gray-50 transition-colors duration-200;
}

.dataTables_wrapper .dataTables_paginate .paginate_button.current {
  @apply bg-gray-900 text-white border-blue-600 hover:bg-blue-700;
}

.dataTables_wrapper .dataTables_paginate .paginate_button.disabled.first,
.dataTables_wrapper .dataTables_paginate .paginate_button.disabled.previous,
.dataTables_wrapper .dataTables_paginate .paginate_button.disabled.next,
.dataTables_wrapper .dataTables_paginate .paginate_button.disabled.last { display: none !important; }

.dataTables_wrapper .dataTables_length select {
  @apply form-input py-1 text-sm;
}

.dataTables_wrapper .dataTables_filter input {
  @apply form-input;
}

.dataTables_wrapper .dataTables_info {
  @apply text-sm text-gray-600;
}

#utenti-table thead th {
  @apply bg-gray-50 font-semibold text-gray-700 border-b-2 border-gray-200 px-4 py-3;
}

#utenti-table tbody td {
  @apply px-4 py-3 border-b border-gray-100;
}

#utenti-table tbody tr:hover {
  @apply bg-gray-50;
}

/* Responsive table improvements */
@media (max-width: 768px) {
  .card-header {
    @apply flex-col items-start gap-3;
  }

  .card-header .flex {
    @apply w-full justify-center;
  }

  /* Improve horizontal scroll on mobile */
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
    border-radius: 8px;
    margin: 0 8px;
  }

  .overflow-x-auto::-webkit-scrollbar-thumb {
    background: #3b82f6;
    border-radius: 8px;
    border: 2px solid #e0e7ff;
  }

  .overflow-x-auto::-webkit-scrollbar-thumb:hover {
    background: #2563eb;
  }

  /* Make table scroll naturally without cutting anything */
  .dataTables_wrapper .dataTables_scroll {
    overflow-x: auto !important;
  }

  #utenti-table {
    width: max-content !important;
    min-width: 100%;
    table-layout: auto;
  }

  /* Ensure columns have enough space */
  #utenti-table th,
  #utenti-table td {
    white-space: normal;
    word-wrap: break-word;
    max-width: none;
  }

  /* Make Nome column much bigger on mobile */
  #utenti-table th:first-child,
  #utenti-table td:first-child {
    min-width: 250px !important;
    width: 250px !important;
  }

  /* Add shadow gradient to indicate more content */
  .overflow-x-auto::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: 30px;
    background: linear-gradient(to left, rgba(255, 255, 255, 0.9), transparent);
    pointer-events: none;
    opacity: 0.8;
  }

  .overflow-x-auto::-webkit-scrollbar-thumb:active ~ ::after,
  .overflow-x-auto.scrolled-end::after {
    opacity: 0;
  }
}

/* Improve table visibility on small screens */
@media (max-width: 640px) {
  #utenti-table {
    font-size: 0.875rem;
  }

  #utenti-table td,
  #utenti-table th {
    padding: 0.5rem !important;
  }

  /* Make action buttons more compact on mobile */
  #utenti-table .flex.items-center.gap-1 {
    gap: 0.25rem;
  }

  #utenti-table .p-2 {
    padding: 0.375rem;
  }

  #export-buttons {
    flex-wrap: wrap;
    gap: 0.5rem;
  }

  #export-buttons button {
    font-size: 0.75rem;
    padding: 0.375rem 0.75rem;
  }
}
</style>

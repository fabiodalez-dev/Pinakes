<?php
// Helper function to generate status badges for the loan status
function getStatusBadge($status) {
    $baseClasses = 'inline-flex items-center px-3 py-1 rounded-full text-xs font-medium';
    switch ($status) {
        case 'in_corso':
            return "<span class='$baseClasses bg-blue-100 text-blue-800'><i class='fas fa-clock mr-2'></i>In Corso</span>";
        case 'in_ritardo':
            return "<span class='$baseClasses bg-yellow-100 text-yellow-800'><i class='fas fa-exclamation-triangle mr-2'></i>In Ritardo</span>";
        case 'restituito':
            return "<span class='$baseClasses bg-green-100 text-green-800'><i class='fas fa-check-circle mr-2'></i>Restituito</span>";
        case 'perso':
        case 'danneggiato':
            return "<span class='$baseClasses bg-red-100 text-red-800'><i class='fas fa-times-circle mr-2'></i>" . ucfirst($status) . "</span>";
        default:
            return "<span class='$baseClasses bg-gray-100 text-gray-800'><i class='fas fa-question-circle mr-2'></i>" . ucfirst($status) . "</span>";
    }
}
?>

<!-- Modern Loans Management Interface -->
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
          <a href="/admin/prestiti" class="text-gray-900 hover:text-gray-900">
            <i class="fas fa-handshake mr-1"></i>Prestiti
          </a>
        </li>
      </ol>
    </nav>
    <!-- Modern Header -->
    <div class="mb-8 fade-in">
      <div class="flex items-center justify-between mb-3">
        <div>
          <h1 class="text-3xl font-bold text-gray-900 flex items-center">
            <i class="fas fa-handshake text-yellow-600 mr-3"></i>
            Gestione Prestiti
          </h1>
          <p class="text-sm text-gray-600 mt-1">Visualizza e gestisci tutti i prestiti della biblioteca</p>
        </div>
        <a href="/admin/prestiti/crea" class="hidden md:inline-flex btn-primary items-center">
            <i class="fas fa-plus mr-2"></i>
            Nuovo Prestito
        </a>
      </div>
      <div class="flex md:hidden mb-3">
        <a href="/admin/prestiti/crea" class="w-full btn-primary inline-flex items-center justify-center">
          <i class="fas fa-plus mr-2"></i>
          Nuovo Prestito
        </a>
      </div>
    </div>

    <!-- Success Messages -->
    <?php if(isset($_GET['created']) && $_GET['created'] == '1'): ?>
      <div class="mb-6 p-4 bg-green-100 text-green-800 rounded-lg border border-green-200 slide-in-up">
        <div class="flex items-center gap-2">
          <i class="fas fa-check-circle"></i>
          <span>Prestito creato con successo!</span>
        </div>
      </div>
    <?php endif; ?>
    <?php if(isset($_GET['updated']) && $_GET['updated'] == '1'): ?>
      <div class="mb-6 p-4 bg-green-100 text-green-800 rounded-lg border border-green-200 slide-in-up">
        <div class="flex items-center gap-2">
          <i class="fas fa-check-circle"></i>
          <span>Prestito aggiornato con successo!</span>
        </div>
      </div>
    <?php endif; ?>

    <!-- Pending Loan Requests Widget -->
    <?php if (!empty($pending_loans)): ?>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
      <div class="p-6 border-b border-gray-200 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-clock text-orange-600 mr-2"></i>
          Richieste di Prestito in Attesa (<?= count($pending_loans) ?>)
        </h2>
      </div>
      <div class="p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
          <?php foreach ($pending_loans as $loan): ?>
          <div class="flex flex-col bg-gray-50 border border-gray-200 rounded-xl p-5 shadow-sm" data-loan-card="">
            <div class="flex gap-4">
              <div class="flex-shrink-0">
                <img src="<?= htmlspecialchars($loan['copertina_url'] ?? '/uploads/copertine/default-cover.jpg') ?>" alt="<?= htmlspecialchars($loan['libro_titolo']) ?>" class="w-20 h-28 object-cover rounded-lg shadow-sm">
              </div>
              <div class="flex-1 min-w-0">
                <h3 class="font-semibold text-gray-900 mb-2 line-clamp-2">
                  <?= htmlspecialchars($loan['libro_titolo']) ?>
                </h3>
                <p class="text-sm text-gray-600 flex items-center">
                  <i class="fas fa-user mr-2 text-blue-500"></i>
                  <?= htmlspecialchars($loan['utente_nome']) ?>
                </p>
                <p class="text-sm text-gray-600 flex items-center mt-1">
                  <i class="fas fa-envelope mr-2 text-green-500"></i>
                  <?= htmlspecialchars($loan['utente_email']) ?>
                </p>
                <div class="mt-3 grid grid-cols-1 gap-1 text-xs text-gray-500">
                  <span class="flex items-center">
                    <i class="fas fa-play mr-2 text-green-500"></i>
                    Inizio: <?= date('d-m-Y', strtotime($loan['data_prestito'])) ?>
                  </span>
                  <span class="flex items-center">
                    <i class="fas fa-stop mr-2 text-red-500"></i>
                    Fine: <?= date('d-m-Y', strtotime($loan['data_scadenza'])) ?>
                  </span>
                </div>
              </div>
            </div>
            <div class="mt-4 flex gap-3">
              <button type="button" class="flex-1 bg-gray-900 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition-colors approve-btn shadow-sm" data-loan-id="<?= (int)$loan['id'] ?>">
                <i class="fas fa-check mr-2"></i>Approva
              </button>
              <button type="button" class="flex-1 bg-red-600 hover:bg-red-500 text-white font-medium py-2 px-4 rounded-lg transition-colors reject-btn shadow-sm" data-loan-id="<?= (int)$loan['id'] ?>">
                <i class="fas fa-times mr-2"></i>Rifiuta
              </button>
            </div>
            <div class="mt-3 text-xs text-gray-400 flex items-center">
              <i class="fas fa-clock mr-2"></i>
              Richiesto il <?= date('d-m-Y H:i', strtotime($loan['created_at'])) ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Advanced Filters Card -->
    <div class="card mb-6">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-filter text-yellow-600 mr-2"></i>
          Filtri di Ricerca
        </h2>
      </div>
      <div class="card-body" id="filters-container">
        <form method="get" action="/admin/prestiti">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
              <div>
                <label class="form-label">Cerca Utente</label>
                <input name="utente" placeholder="<?= __('Nome, cognome, email...') ?>" class="form-input" />
              </div>
              <div>
                <label class="form-label">Cerca Libro</label>
                <input name="libro" placeholder="<?= __('Titolo...') ?>" class="form-input" />
              </div>
              <div>
                <label class="form-label">Data prestito (Da)</label>
                <input name="from_date" type="date" class="form-input" />
              </div>
              <div>
                <label class="form-label">Data prestito (A)</label>
                <input name="to_date" type="date" class="form-input" />
              </div>
            </div>

            <div class="flex justify-between items-center pt-4 border-t border-gray-200">
              <a href="/admin/prestiti" class="text-sm text-gray-600 hover:text-gray-800">
                <i class="fas fa-times mr-2"></i>
                Cancella filtri
              </a>
              <button type="submit" class="btn-primary">
                <i class="fas fa-search mr-2"></i>
                Applica Filtri
              </button>
            </div>
        </form>
      </div>
    </div>

    <!-- Loans Table Card -->
    <div class="bg-white shadow-sm rounded-2xl border border-slate-200">
        <div class="px-6 py-4 border-b border-slate-200 flex justify-between items-center">
            <h2 class="text-lg font-semibold text-gray-800">Elenco Prestiti</h2>
            <div class="flex flex-wrap items-center gap-2 text-sm">
              <button data-status="in_corso" class="status-filter-btn btn-secondary px-3 py-1.5">In corso</button>
              <button data-status="in_ritardo" class="status-filter-btn btn-secondary px-3 py-1.5">In ritardo</button>
              <button data-status="restituito" class="status-filter-btn btn-secondary px-3 py-1.5">Restituito</button>
              <button data-status="" class="status-filter-btn btn-primary px-3 py-1.5"><?= __("Tutti") ?></button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table id="prestiti-table" class="min-w-full text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left font-medium"><?= __('Libro') ?></th>
                        <th scope="col" class="px-6 py-3 text-left font-medium"><?= __('Utente') ?></th>
                        <th scope="col" class="px-6 py-3 text-left font-medium"><?= __('Date') ?></th>
                        <th scope="col" class="px-6 py-3 text-center font-medium"><?= __('Stato') ?></th>
                        <th scope="col" class="px-6 py-3 text-right font-medium"><?= __('Azioni') ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <?php if (empty($prestiti)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-10 text-gray-500">
                                <i class="fas fa-folder-open fa-2x mb-2"></i>
                                <p>Nessun prestito trovato.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($prestiti as $prestito): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($prestito['libro_titolo'] ?? 'N/D'); ?></div>
                                    <div class="text-gray-500">ID Prestito: <?php echo $prestito['id']; ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($prestito['utente_nome'] ?? 'N/D'); ?></div>
                                    <div class="text-gray-500"><?php echo htmlspecialchars($prestito['utente_email'] ?? 'N/D'); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-gray-700">
                                    <div>
                                        <span class="font-semibold">Prestito:</span> <?php echo date("d/m/Y", strtotime($prestito['data_prestito'])); ?>
                                    </div>
                                    <div>
                                        <span class="font-semibold">Scadenza:</span> <?php echo date("d/m/Y", strtotime($prestito['data_scadenza'])); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <?php echo getStatusBadge($prestito['stato']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <div class="flex items-center justify-end space-x-2">
                                        <a href="/admin/prestiti/dettagli/<?php echo $prestito['id']; ?>" class="p-2 text-gray-500 hover:bg-gray-200 rounded-full transition-colors" title="<?= __("Dettagli") ?>">
                                            <i class="fas fa-eye w-4 h-4"></i>
                                        </a>
                                        <?php if ($prestito['attivo']): ?>
                                            <a href="/admin/prestiti/restituito/<?php echo $prestito['id']; ?>" class="p-2 text-blue-600 hover:bg-blue-100 rounded-full transition-colors" title="Registra Restituzione">
                                                <i class="fas fa-undo-alt w-4 h-4"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
  </div>
</div>

<style>
.dt-search {
    margin-left: 1rem;
}
.dt-info {
    padding-left: 1.5rem;
}
.dt-paging {
    padding-right: 1.5rem;
}
.dt-paging button {
    margin: 0 0.25rem;
}
</style>

<script>
// Set current locale for DataTables language selection
window.i18nLocale = '<?= $_SESSION['locale'] ?? 'it_IT' ?>';

document.addEventListener('DOMContentLoaded', function() {
    if (typeof DataTable === 'undefined') {
        console.error('DataTable is not loaded!');
        return;
    }

    let currentStatusFilter = '';

    // Initialize DataTable
    const table = new DataTable('#prestiti-table', {
        processing: true,
        serverSide: true,
        ajax: {
            url: '/api/prestiti',
            type: 'GET',
            data: function(d) {
                d.stato_specifico = currentStatusFilter;
                // Pass search value to server
                if (d.search && d.search.value) {
                    d.search_value = d.search.value;
                }
            }
        },
        columns: [
            {
                data: 'libro',
                render: function(data, type, row) {
                    return `<div class="font-semibold text-gray-900">${data || 'N/D'}</div>
                            <div class="text-gray-500">ID Prestito: ${row.id}</div>`;
                }
            },
            {
                data: 'utente',
                render: function(data, type, row) {
                    return `<div class="font-semibold text-gray-900">${data || 'N/D'}</div>`;
                }
            },
            {
                data: 'data_prestito',
                render: function(data, type, row) {
                    const dataPrestito = data ? new Date(data).toLocaleDateString('it-IT') : 'N/D';
                    return `<div class="text-gray-700">${dataPrestito}</div>`;
                }
            },
            {
                data: 'stato',
                className: 'text-center',
                render: function(data, type, row) {
                    const baseClasses = 'inline-flex items-center px-3 py-1 rounded-full text-xs font-medium';
                    switch (data) {
                        case 'in_corso':
                            return `<span class='${baseClasses} bg-blue-100 text-blue-800'><i class='fas fa-clock mr-2'></i>In Corso</span>`;
                        case 'in_ritardo':
                            return `<span class='${baseClasses} bg-yellow-100 text-yellow-800'><i class='fas fa-exclamation-triangle mr-2'></i>In Ritardo</span>`;
                        case 'restituito':
                            return `<span class='${baseClasses} bg-green-100 text-green-800'><i class='fas fa-check-circle mr-2'></i>Restituito</span>`;
                        case 'perso':
                        case 'danneggiato':
                            return `<span class='${baseClasses} bg-red-100 text-red-800'><i class='fas fa-times-circle mr-2'></i>${data.charAt(0).toUpperCase() + data.slice(1)}</span>`;
                        default:
                            return `<span class='${baseClasses} bg-gray-100 text-gray-800'><i class='fas fa-question-circle mr-2'></i>${data.charAt(0).toUpperCase() + data.slice(1)}</span>`;
                    }
                }
            },
            {
                data: null,
                className: 'text-right',
                orderable: false,
                render: function(data, type, row) {
                    let actions = `<div class="flex items-center justify-end space-x-2">
                        <a href="/admin/prestiti/dettagli/${row.id}" class="p-2 text-gray-500 hover:bg-gray-200 rounded-full transition-colors" title="<?= __("Dettagli") ?>">
                            <i class="fas fa-eye w-4 h-4"></i>
                        </a>`;
                    if (row.attivo === 1) {
                        actions += `<a href="/admin/prestiti/restituito/${row.id}" class="p-2 text-blue-600 hover:bg-blue-100 rounded-full transition-colors" title="Registra Restituzione">
                            <i class="fas fa-undo-alt w-4 h-4"></i>
                        </a>`;
                    }
                    actions += `</div>`;
                    return actions;
                }
            }
        ],
        order: [[0, 'desc']],
        language: (window.i18nLocale === 'en_US' ? window.DT_LANG_EN : window.DT_LANG_IT),
        pageLength: 25,
        dom: '<"px-6 py-4"<"flex items-center justify-between"<"flex items-center gap-4"l><"flex-1"f>>>rtip'
    });

    // Status filter buttons
    const filterButtons = document.querySelectorAll('.status-filter-btn');
    filterButtons.forEach(button => {
        button.addEventListener('click', function() {
            const status = this.getAttribute('data-status') || '';
            currentStatusFilter = status;

            // Update button styles
            filterButtons.forEach(btn => {
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-secondary');
            });
            this.classList.remove('btn-secondary');
            this.classList.add('btn-primary');

            // Reload table with new filter
            table.ajax.reload();
        });
    });

    // Pending loan requests widget - Approve/Reject buttons
    document.querySelectorAll('.approve-btn').forEach(button => {
        button.addEventListener('click', function() {
            const loanId = this.getAttribute('data-loan-id');
            if (!loanId) return;

            Swal.fire({
                title: __('Approva Prestito?'),
                text: __('Approverai questa richiesta di prestito?'),
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: __('Approva'),
                cancelButtonText: __('Annulla'),
                confirmButtonColor: '#111827'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('/admin/loans/approve', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                        },
                        body: JSON.stringify({ loan_id: parseInt(loanId) })
                    })
                    .then(resp => resp.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('Successo', 'Prestito approvato!', 'success').then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire('Errore', data.message || 'Errore nell\'approvazione', 'error');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        Swal.fire('Errore', 'Errore di comunicazione con il server', 'error');
                    });
                }
            });
        });
    });

    document.querySelectorAll('.reject-btn').forEach(button => {
        button.addEventListener('click', function() {
            const loanId = this.getAttribute('data-loan-id');
            if (!loanId) return;

            Swal.fire({
                title: __('Rifiuta Prestito?'),
                text: __('Rifiuterai questa richiesta di prestito?'),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: __('Rifiuta'),
                cancelButtonText: __('Annulla'),
                confirmButtonColor: '#dc2626'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('/admin/loans/reject', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                        },
                        body: JSON.stringify({ loan_id: parseInt(loanId) })
                    })
                    .then(resp => resp.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('Successo', 'Prestito rifiutato!', 'success').then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire('Errore', data.message || 'Errore nel rifiuto', 'error');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        Swal.fire('Errore', 'Errore di comunicazione con il server', 'error');
                    });
                }
            });
        });
    });
});
</script>
<!-- Minimal White Dashboard Interface -->
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Minimal Header -->
    <div class="mb-8 fade-in">
      <div>
        <h1 class="text-3xl font-bold text-gray-900 flex items-center">
          <i class="fas fa-tachometer-alt text-gray-600 mr-3"></i>
          <?= __("Dashboard") ?>
        </h1>
        <p class="text-sm text-gray-600 mt-2"><?= __("Panoramica generale del sistema bibliotecario") ?></p>
      </div>
    </div>

    <!-- Minimal Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
      <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-md transition-shadow duration-200">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-gray-600"><?= __("Libri") ?></p>
            <p class="text-3xl font-bold text-gray-900"><?php echo (int)$stats['libri']; ?></p>
            <p class="text-xs text-gray-500 mt-1"><?= __("Totale libri presenti") ?></p>
          </div>
          <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
            <i class="fas fa-book text-gray-600 text-xl"></i>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-md transition-shadow duration-200">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-gray-600"><?= __("Utenti") ?></p>
            <p class="text-3xl font-bold text-gray-900"><?php echo (int)$stats['utenti']; ?></p>
            <p class="text-xs text-gray-500 mt-1"><?= __("Utenti registrati") ?></p>
          </div>
          <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
            <i class="fas fa-users text-gray-600 text-xl"></i>
          </div>
        </div>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-md transition-shadow duration-200">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-gray-600"><?= __("Prestiti Attivi") ?></p>
            <p class="text-3xl font-bold text-gray-900"><?php echo (int)$stats['prestiti_in_corso']; ?></p>
            <p class="text-xs text-gray-500 mt-1"><?= __("In corso di restituzione") ?></p>
          </div>
          <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
            <i class="fas fa-handshake text-gray-600 text-xl"></i>
          </div>
        </div>
      </div>

      <!-- Pending Loans Card -->
      <?php if ((int)$stats['prestiti_pendenti'] > 0): ?>
        <a href="/admin/loans/pending" class="bg-red-50 rounded-xl border border-red-200 p-6 hover:bg-red-100 transition-colors duration-200">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-red-600"><?= __("Richieste Pendenti") ?></p>
              <p class="text-3xl font-bold text-red-800"><?php echo (int)$stats['prestiti_pendenti']; ?></p>
              <p class="text-xs text-red-500 mt-1"><?= __("Da approvare") ?></p>
            </div>
            <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center animate-pulse">
              <i class="fas fa-clock text-red-600 text-xl"></i>
            </div>
          </div>
        </a>
      <?php else: ?>
        <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-md transition-shadow duration-200">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-gray-600"><?= __("Richieste Pendenti") ?></p>
              <p class="text-3xl font-bold text-gray-900">0</p>
              <p class="text-xs text-gray-500 mt-1"><?= __("Nessuna richiesta") ?></p>
            </div>
            <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
              <i class="fas fa-check-circle text-gray-600 text-xl"></i>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-md transition-shadow duration-200">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-gray-600"><?= __("Autori") ?></p>
            <p class="text-3xl font-bold text-gray-900"><?php echo (int)$stats['autori']; ?></p>
            <p class="text-xs text-gray-500 mt-1"><?= __("Nella collezione") ?></p>
          </div>
          <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
            <i class="fas fa-user-edit text-gray-600 text-xl"></i>
          </div>
        </div>
      </div>
    </div>

    <!-- Pending Loans Section -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
      <div class="p-6 border-b border-gray-200 flex flex-col md:flex-row items-center justify-between gap-4">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-clock text-gray-600 mr-2"></i>
          <?= __("Richieste di Prestito in Attesa") ?>
        </h2>
        <a href="/admin/loans/pending" class="px-3 py-1.5 text-sm bg-gray-900 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 whitespace-nowrap">
          <i class="fas fa-external-link-alt mr-1"></i>
          <?= __("Gestisci tutte") ?>
        </a>
      </div>
      <div class="p-6">
        <?php if (empty($pending)): ?>
          <div class="text-center py-8">
            <i class="fas fa-check-circle text-4xl text-green-500 mb-4"></i>
            <p class="text-gray-500"><?= __("Nessuna richiesta in attesa di approvazione.") ?></p>
          </div>
        <?php else: ?>
          <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
            <?php foreach ($pending as $loan): ?>
              <div class="flex flex-col bg-gray-50 border border-gray-200 rounded-xl p-5 shadow-sm" data-loan-card>
                <div class="flex flex-col gap-4 items-center md:items-start">
                  <div class="flex-shrink-0">
                    <?php $cover = !empty($loan['copertina_url']) ? $loan['copertina_url'] : '/uploads/copertine/placeholder.jpg'; ?>
                    <img
                      src="<?= htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
                      alt="<?= App\Support\HtmlHelper::e($loan['titolo'] ?? 'Copertina libro'); ?>"
                      class="w-full md:w-20 h-auto md:h-28 object-cover rounded-lg shadow-sm"
                      onerror="this.src='/uploads/copertine/placeholder.jpg'"
                    >
                  </div>
                  <div class="flex-1 min-w-0">
                    <h3 class="font-semibold text-gray-900 mb-2 line-clamp-2">
                      <?= App\Support\HtmlHelper::e($loan['titolo'] ?? ''); ?>
                    </h3>
                    <p class="text-sm text-gray-600 flex items-center">
                      <i class="fas fa-user mr-2 text-blue-500"></i>
                      <?= App\Support\HtmlHelper::e($loan['utente_nome'] ?? ''); ?>
                    </p>
                    <?php if (!empty($loan['email'])): ?>
                      <p class="text-sm text-gray-600 flex items-center mt-1">
                        <i class="fas fa-envelope mr-2 text-green-500"></i>
                        <?= App\Support\HtmlHelper::e($loan['email']); ?>
                      </p>
                    <?php endif; ?>
                    <div class="mt-3 grid grid-cols-1 gap-1 text-xs text-gray-500">
                      <?php if (!empty($loan['data_richiesta_inizio'])): ?>
                        <span class="flex items-center">
                          <i class="fas fa-play mr-2 text-green-500"></i>
                          <?= __("Inizio:") ?> <?= date('d-m-Y', strtotime((string)$loan['data_richiesta_inizio'])); ?>
                        </span>
                      <?php endif; ?>
                      <?php if (!empty($loan['data_richiesta_fine'])): ?>
                        <span class="flex items-center">
                          <i class="fas fa-stop mr-2 text-red-500"></i>
                          <?= __("Fine:") ?> <?= date('d-m-Y', strtotime((string)$loan['data_richiesta_fine'])); ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <div class="mt-4 flex flex-col md:flex-row gap-3">
                  <button type="button" class="flex-1 bg-gray-900 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition-colors approve-btn shadow-sm" data-loan-id="<?= (int)$loan['id']; ?>">
                    <i class="fas fa-check mr-2"></i><?= __("Approva") ?>
                  </button>
                  <button type="button" class="flex-1 bg-red-600 hover:bg-red-500 text-white font-medium py-2 px-4 rounded-lg transition-colors reject-btn shadow-sm" data-loan-id="<?= (int)$loan['id']; ?>">
                    <i class="fas fa-times mr-2"></i><?= __("Rifiuta") ?>
                  </button>
                </div>
                <div class="mt-3 text-xs text-gray-400 flex items-center">
                  <i class="fas fa-clock mr-2"></i>
                  <?= __("Richiesto il") ?> <?= !empty($loan['created_at']) ? date('d-m-Y H:i', strtotime((string)$loan['created_at'])) : 'N/D'; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Recent Books Section -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
      <div class="p-6 border-b border-gray-200 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-clock text-gray-600 mr-2"></i>
          <?= __("Ultimi Libri Inseriti") ?>
        </h2>
        <a href="/admin/libri" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200">
          <i class="fas fa-eye mr-1"></i>
          <?= __("Vedi tutti") ?>
        </a>
      </div>
      <div class="p-6">
        <?php if (empty($lastBooks)): ?>
          <div class="text-center py-8">
            <i class="fas fa-book-open text-4xl text-gray-300 mb-4"></i>
            <p class="text-gray-500"><?= __("Nessun libro ancora inserito") ?></p>
          </div>
        <?php else: ?>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <?php foreach ($lastBooks as $libro): ?>
              <a href="/admin/libri/<?php echo (int)$libro['id']; ?>" class="group h-full">
                <div class="bg-gray-50 rounded-lg border border-gray-200 overflow-hidden hover:shadow-md transition-all duration-200 h-full flex flex-col">
                  <?php $coverUrl = !empty($libro['copertina_url']) ? $libro['copertina_url'] : '/uploads/copertine/placeholder.jpg'; ?>
                  <img src="<?php echo htmlspecialchars($coverUrl, ENT_QUOTES, 'UTF-8'); ?>"
                       alt="<?php echo App\Support\HtmlHelper::e($libro['titolo'] ?? ''); ?>"
                       class="w-full h-48 object-cover"
                       onerror="this.src='/uploads/copertine/placeholder.jpg'">
                  <div class="p-4 flex-1">
                    <h3 class="font-semibold text-gray-900 group-hover:text-gray-700 transition-colors truncate">
                      <?php echo App\Support\HtmlHelper::e($libro['titolo'] ?? ''); ?>
                    </h3>
                    <p class="text-sm text-gray-600 truncate">
                      <?php echo App\Support\HtmlHelper::e($libro['autore'] ?? ''); ?>
                    </p>
                    <?php if (!empty($libro['anno_pubblicazione'])): ?>
                      <p class="text-xs text-gray-500 mt-1">
                        <?php echo App\Support\HtmlHelper::e($libro['anno_pubblicazione']); ?>
                      </p>
                    <?php endif; ?>
                  </div>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Active Loans Section -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
      <div class="p-6 border-b border-gray-200 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-handshake text-gray-600 mr-2"></i>
          <?= __("Prestiti in Corso") ?>
        </h2>
        <a href="/admin/prestiti" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200">
          <i class="fas fa-eye mr-1"></i>
          <?= __("Vedi tutti") ?>
        </a>
      </div>
      <div class="p-6">
        <?php if (empty($active)): ?>
          <div class="text-center py-8">
            <i class="fas fa-handshake text-4xl text-gray-300 mb-4"></i>
            <p class="text-gray-500"><?= __("Nessun prestito in corso") ?></p>
          </div>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Libro") ?></th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Utente") ?></th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Data Prestito") ?></th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Scadenza") ?></th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Stato") ?></th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($active as $p): ?>
                  <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                      <?php echo App\Support\HtmlHelper::e($p['titolo'] ?? ''); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                      <?php echo App\Support\HtmlHelper::e($p['utente'] ?? ''); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      <?php echo $p['data_prestito'] ? date('d-m-Y', strtotime($p['data_prestito'])) : ''; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      <?php echo $p['data_scadenza'] ? date('d-m-Y', strtotime($p['data_scadenza'])) : ''; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                        <i class="fas fa-clock mr-1"></i>
                        <?= __("In corso") ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Overdue Loans Section -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-8">
      <div class="p-6 border-b border-gray-200 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-exclamation-triangle text-gray-600 mr-2"></i>
          <?= __("Prestiti Scaduti") ?>
        </h2>
        <a href="/admin/prestiti" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200">
          <i class="fas fa-eye mr-1"></i>
          <?= __("Gestisci") ?>
        </a>
      </div>
      <div class="p-6">
        <?php if (empty($overdue)): ?>
          <div class="text-center py-8">
            <i class="fas fa-check-circle text-4xl text-green-400 mb-4"></i>
            <p class="text-gray-500"><?= __("Nessun prestito scaduto") ?></p>
          </div>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Libro") ?></th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Utente") ?></th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Data Prestito") ?></th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Scadenza") ?></th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Stato") ?></th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($overdue as $p): ?>
                  <tr class="bg-red-50 hover:bg-red-100">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                      <?php echo App\Support\HtmlHelper::e($p['titolo'] ?? ''); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                      <?php echo App\Support\HtmlHelper::e($p['utente'] ?? ''); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      <?php echo $p['data_prestito'] ? date('d-m-Y', strtotime($p['data_prestito'])) : ''; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      <?php echo $p['data_scadenza'] ? date('d-m-Y', strtotime($p['data_scadenza'])) : ''; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        <?= __("Scaduto") ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
</div>
</div>

<?php
require __DIR__ . '/../partials/loan-actions-swal.php';
unset($loanActionTranslations);
?>

<!-- Custom Styles for Enhanced UI -->
<style>
.fade-in {
  animation: fadeIn 0.5s ease-in-out;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

/* Responsive design for mobile */
@media (max-width: 768px) {
  .grid-cols-1.md\:grid-cols-2.lg\:grid-cols-4 {
    grid-template-columns: 1fr;
  }
}
</style>

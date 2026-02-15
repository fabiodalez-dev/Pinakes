<?php
/**
 * Import History View
 * @var array $imports Import logs from database
 */
$title = __("Storico Import");
?>

<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li>
          <a href="<?= url('/admin/dashboard') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i><?= __("Home") ?>
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li class="text-gray-900 font-medium">
          <i class="fas fa-history mr-1"></i><?= __("Storico Import") ?>
        </li>
      </ol>
    </nav>

    <!-- Header -->
    <div class="mb-6">
      <div class="flex items-center justify-between mb-3">
        <div>
          <h1 class="text-3xl font-bold text-gray-900 flex items-center">
            <i class="fas fa-history text-blue-600 mr-3"></i>
            <?= __("Storico Import") ?>
          </h1>
          <p class="text-sm text-gray-600 mt-1">
            <?= __("Visualizza la cronologia degli import CSV e LibraryThing con report errori dettagliati") ?>
            <span class="ml-2 text-xs bg-gray-200 px-2 py-1 rounded">
              <?= __n('%d import registrato', '%d import registrati', count($imports), count($imports)) ?>
            </span>
          </p>
        </div>
        <div class="flex items-center gap-3">
          <button onclick="location.reload()" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center">
            <i class="fas fa-sync-alt mr-2"></i>
            <?= __("Aggiorna") ?>
          </button>
        </div>
      </div>
    </div>

    <!-- Stats Cards -->
    <?php
    $totalImports = count($imports);
    $completedImports = count(array_filter($imports, fn($i) => $i['status'] === 'completed'));
    $failedImports = count(array_filter($imports, fn($i) => $i['status'] === 'failed'));
    $processingImports = count(array_filter($imports, fn($i) => $i['status'] === 'processing'));
    $totalImported = array_sum(array_column($imports, 'imported'));
    $totalUpdated = array_sum(array_column($imports, 'updated'));
    $totalFailed = array_sum(array_column($imports, 'failed'));
    ?>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
      <div class="bg-white rounded-lg border border-gray-200 p-4 shadow-sm">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-600"><?= __("Completati") ?></p>
            <p class="text-2xl font-bold text-green-600"><?= $completedImports ?></p>
          </div>
          <i class="fas fa-check-circle text-3xl text-green-300"></i>
        </div>
      </div>
      <div class="bg-white rounded-lg border border-gray-200 p-4 shadow-sm">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-600"><?= __("Libri Importati") ?></p>
            <p class="text-2xl font-bold text-blue-600"><?= number_format($totalImported) ?></p>
          </div>
          <i class="fas fa-book text-3xl text-blue-300"></i>
        </div>
      </div>
      <div class="bg-white rounded-lg border border-gray-200 p-4 shadow-sm">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-600"><?= __("Aggiornati") ?></p>
            <p class="text-2xl font-bold text-indigo-600"><?= number_format($totalUpdated) ?></p>
          </div>
          <i class="fas fa-sync text-3xl text-indigo-300"></i>
        </div>
      </div>
      <div class="bg-white rounded-lg border border-gray-200 p-4 shadow-sm">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-600"><?= __("Errori Totali") ?></p>
            <p class="text-2xl font-bold text-red-600"><?= number_format($totalFailed) ?></p>
          </div>
          <i class="fas fa-exclamation-triangle text-3xl text-red-300"></i>
        </div>
      </div>
    </div>

    <!-- Imports Table -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
      <div class="p-6 border-b border-gray-200">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-list text-gray-600 mr-2"></i>
          <?= __("Cronologia Import") ?>
        </h2>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                <?= __("Data/Ora") ?>
              </th>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                <?= __("Tipo") ?>
              </th>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                <?= __("File") ?>
              </th>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                <?= __("Stato") ?>
              </th>
              <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                <?= __("Totali") ?>
              </th>
              <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                <?= __("Importati") ?>
              </th>
              <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                <?= __("Aggiornati") ?>
              </th>
              <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                <?= __("Errori") ?>
              </th>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                <?= __("Azioni") ?>
              </th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <?php if (empty($imports)): ?>
              <tr>
                <td colspan="9" class="px-6 py-12 text-center">
                  <div class="flex flex-col items-center">
                    <i class="fas fa-inbox text-gray-300 text-5xl mb-3"></i>
                    <p class="text-gray-500 text-sm"><?= __("Nessun import registrato") ?></p>
                    <p class="text-gray-400 text-xs mt-1"><?= __("Gli import CSV e LibraryThing verranno visualizzati qui") ?></p>
                  </div>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($imports as $import): ?>
                <?php
                $statusColors = [
                    'completed' => ['bg' => 'bg-green-100', 'text' => 'text-green-800', 'icon' => 'fa-check-circle'],
                    'failed' => ['bg' => 'bg-red-100', 'text' => 'text-red-800', 'icon' => 'fa-times-circle'],
                    'processing' => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-800', 'icon' => 'fa-spinner'],
                ];
                $status = $statusColors[$import['status']] ?? ['bg' => 'bg-gray-100', 'text' => 'text-gray-800', 'icon' => 'fa-question-circle'];

                $typeLabels = [
                    'csv' => ['label' => 'CSV', 'color' => 'bg-blue-100 text-blue-800'],
                    'librarything' => ['label' => 'LibraryThing', 'color' => 'bg-purple-100 text-purple-800'],
                ];
                $type = $typeLabels[$import['import_type']] ?? ['label' => 'Unknown', 'color' => 'bg-gray-100 text-gray-800'];

                $startedAt = new DateTime($import['started_at']);
                $completedAt = $import['completed_at'] ? new DateTime($import['completed_at']) : null;
                $duration = $completedAt ? $startedAt->diff($completedAt)->format('%H:%I:%S') : '-';
                ?>
                <tr class="hover:bg-gray-50 transition-colors">
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    <div class="flex flex-col">
                      <span class="font-medium"><?= $startedAt->format('d/m/Y') ?></span>
                      <span class="text-xs text-gray-500"><?= $startedAt->format('H:i:s') ?></span>
                      <?php if ($completedAt): ?>
                        <span class="text-xs text-gray-400" title="<?= __("Durata") ?>">
                          <i class="fas fa-clock mr-1"></i><?= $duration ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $type['color'] ?>">
                      <?= $type['label'] ?>
                    </span>
                  </td>
                  <td class="px-6 py-4 text-sm text-gray-900">
                    <div class="max-w-xs truncate" title="<?= htmlspecialchars($import['file_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>">
                      <?= htmlspecialchars($import['file_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $status['bg'] ?> <?= $status['text'] ?>">
                      <i class="fas <?= $status['icon'] ?> mr-1"></i>
                      <?= __(ucfirst($import['status'])) ?>
                    </span>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right font-mono">
                    <?= number_format($import['total_rows']) ?>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600 text-right font-mono font-semibold">
                    <?= number_format($import['imported']) ?>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-blue-600 text-right font-mono font-semibold">
                    <?= number_format($import['updated']) ?>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600 text-right font-mono font-semibold">
                    <?= number_format($import['failed']) ?>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm">
                    <?php if ($import['failed'] > 0): ?>
                      <a href="<?= url('/admin/imports/download-errors?import_id=' . urlencode($import['import_id'])) ?>"
                         class="inline-flex items-center px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded-lg hover:bg-blue-700 transition-colors duration-200">
                        <i class="fas fa-download mr-1.5"></i>
                        <?= __("Scarica Errori") ?>
                      </a>
                    <?php else: ?>
                      <span class="text-gray-400 text-xs">
                        <i class="fas fa-check-circle mr-1"></i><?= __("Nessun errore") ?>
                      </span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Footer info -->
      <?php if (!empty($imports)): ?>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
          <div class="flex items-center justify-between text-sm text-gray-600">
            <div class="flex items-center space-x-4">
              <span>
                <i class="fas fa-info-circle mr-1"></i>
                <?= __("Ultimi %d import", min(100, count($imports))) ?>
              </span>
              <?php if ($processingImports > 0): ?>
                <span class="text-yellow-600 font-medium">
                  <i class="fas fa-spinner fa-spin mr-1"></i>
                  <?= __n('%d import in elaborazione', '%d import in elaborazione', $processingImports, $processingImports) ?>
                </span>
              <?php endif; ?>
            </div>
            <div class="text-xs text-gray-500">
              <?= __("Retention: 90 giorni") ?>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Help Section -->
    <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
      <div class="flex items-start">
        <i class="fas fa-info-circle text-blue-500 text-xl mr-3 mt-0.5"></i>
        <div class="flex-1">
          <h3 class="text-sm font-semibold text-blue-900 mb-1"><?= __("Informazioni sullo Storico Import") ?></h3>
          <ul class="text-sm text-blue-800 space-y-1">
            <li><i class="fas fa-angle-right mr-2"></i><?= __("Gli import vengono tracciati automaticamente durante l'elaborazione") ?></li>
            <li><i class="fas fa-angle-right mr-2"></i><?= __("Scarica il report CSV per analizzare gli errori in dettaglio") ?></li>
            <li><i class="fas fa-angle-right mr-2"></i><?= __("I log vengono conservati per 90 giorni per conformità GDPR") ?></li>
            <li><i class="fas fa-angle-right mr-2"></i><?= __("Ogni errore include: numero riga, titolo libro, tipo errore e messaggio dettagliato") ?></li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// Auto-refresh per import in processing
<?php if ($processingImports > 0): ?>
  setTimeout(() => {
    location.reload();
  }, 10000); // Refresh ogni 10 secondi se ci sono import in elaborazione
<?php endif; ?>
</script>

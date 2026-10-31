<?php
/** @var array $loansByMonth */
/** @var array $loansByStatus */
?>
<section class="min-h-screen py-6 px-4">
  <!-- Header -->
  <?php
// Shared page anatomy; existing contextual controls are retained verbatim.
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title text-3xl font-bold text-gray-900 flex items-center gap-3"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?>

        <?= __("Statistiche Prestiti") ?>
      <?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?>


    <div class="flex items-center justify-between">

    </div>
  <?php
$workspaceHeader['context'] = (string) ob_get_clean();
ob_start();
?><nav class="pk-workspace-breadcrumb" aria-label="<?= htmlspecialchars(__('Percorso di navigazione'), ENT_QUOTES, 'UTF-8') ?>">
      <ol>
        <li>
          <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Home') ?></a>
        </li>

        <li aria-current="page">
          <span><?= __("Statistiche Prestiti") ?></span>
        </li>
      </ol>
    </nav><?php
$workspaceHeader['breadcrumb'] = (string) ob_get_clean();
require dirname(__DIR__, 3) . '/app/Views/partials/workspace-header.php';
unset($workspaceHeader);
?>

  <!-- Summary Cards -->
  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-xs uppercase text-gray-500 font-medium"><?= __("Libri Disponibili") ?></p>
          <p class="text-2xl font-bold text-green-600 mt-1"><?php echo number_format((int)($stats['libri_disponibili'] ?? 0)); ?></p>
        </div>
        <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center">
          <i class="fas fa-book text-green-600 text-xl"></i>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-xs uppercase text-gray-500 font-medium"><?= __("Libri Prestati") ?></p>
          <p class="text-2xl font-bold text-blue-600 mt-1"><?php echo number_format((int)($stats['libri_prestati'] ?? 0)); ?></p>
        </div>
        <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center">
          <i class="fas fa-book-open text-blue-600 text-xl"></i>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-xs uppercase text-gray-500 font-medium"><?= __("Prestiti Attivi") ?></p>
          <p class="text-2xl font-bold text-indigo-600 mt-1"><?php echo number_format((int)($stats['prestiti_attivi'] ?? 0)); ?></p>
        </div>
        <div class="w-12 h-12 bg-indigo-100 rounded-xl flex items-center justify-center">
          <i class="fas fa-exchange-alt text-indigo-600 text-xl"></i>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-xs uppercase text-gray-500 font-medium"><?= __("Prestiti in Ritardo") ?></p>
          <p class="text-2xl font-bold text-red-600 mt-1"><?php echo number_format((int)($stats['prestiti_in_ritardo'] ?? 0)); ?></p>
        </div>
        <div class="w-12 h-12 bg-red-100 rounded-xl flex items-center justify-center">
          <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-xs uppercase text-gray-500 font-medium"><?= __("Prestiti Completati") ?></p>
          <p class="text-2xl font-bold text-gray-600 mt-1"><?php echo number_format((int)($stats['prestiti_completati'] ?? 0)); ?></p>
        </div>
        <div class="w-12 h-12 bg-gray-100 rounded-xl flex items-center justify-center">
          <i class="fas fa-check-circle text-gray-600 text-xl"></i>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-xs uppercase text-gray-500 font-medium"><?= __("Utenti Attivi") ?></p>
          <p class="text-2xl font-bold text-purple-600 mt-1"><?php echo number_format((int)($stats['utenti_con_prestiti'] ?? 0)); ?></p>
        </div>
        <div class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center">
          <i class="fas fa-users text-purple-600 text-xl"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Charts Row -->
  <div class="grid grid-cols-1 gap-6 mb-6">
    <!-- Loans by Month Chart -->
    <div class="card">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-chart-line text-gray-900"></i>
          <?= __("Prestiti per Mese (Ultimi 12 mesi)") ?>
        </h2>
      </div>
      <div class="card-body h-[360px]">
        <canvas id="loansPerMonthChart" class="w-full h-full"></canvas>
      </div>
    </div>

    <!-- Loans by Status Chart -->
    <div class="card">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-chart-pie text-gray-900"></i>
          <?= __("Prestiti per Stato") ?>
        </h2>
      </div>
      <div class="card-body h-[360px] flex items-center justify-center">
        <canvas id="loansByStatusChart" class="w-full h-full max-w-[420px]"></canvas>
      </div>
    </div>
  </div>

  <!-- Loans by material + visitors (0.9.0) -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="card" id="stats-loans-by-media">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-compact-disc text-gray-900"></i>
          <?= __("Prestiti per tipo di materiale (ultimi 12 mesi)") ?>
        </h2>
      </div>
      <div class="card-body p-0">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Tipo Media") ?></th>
                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Prestiti") ?></th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php foreach (($loansByMedia ?? []) as $mediaRow): ?>
              <tr data-media="<?= htmlspecialchars($mediaRow['tipo_media'], ENT_QUOTES, 'UTF-8') ?>">
                <td class="px-6 py-3 text-sm text-gray-900"><i class="fas <?= htmlspecialchars($mediaRow['icon'], ENT_QUOTES, 'UTF-8') ?> text-gray-400 mr-2"></i><?= htmlspecialchars((string) ($mediaRow['label']), ENT_QUOTES, 'UTF-8') ?></td>
                <td class="px-6 py-3 text-center text-sm font-semibold" data-count><?= (int) $mediaRow['totale'] ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <?php if (!empty($visitors)): ?>
    <div class="card" id="stats-visitors">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-door-open text-gray-900"></i>
          <?= sprintf(__("Presenze in biblioteca (ultimi %d giorni)"), (int) $visitors['days']) ?>
        </h2>
      </div>
      <div class="card-body">
        <div class="grid grid-cols-3 gap-4 mb-4">
          <div>
            <p class="text-xs uppercase text-gray-500 font-medium"><?= __("Totale") ?></p>
            <p class="text-2xl font-bold text-gray-900 mt-1" data-visitors="totale"><?= number_format((int) $visitors['totale']) ?></p>
          </div>
          <div>
            <p class="text-xs uppercase text-gray-500 font-medium"><?= __("Soci") ?></p>
            <p class="text-2xl font-bold text-blue-600 mt-1" data-visitors="soci"><?= number_format((int) $visitors['soci']) ?></p>
          </div>
          <div>
            <p class="text-xs uppercase text-gray-500 font-medium"><?= __("Ospiti") ?></p>
            <p class="text-2xl font-bold text-purple-600 mt-1" data-visitors="ospiti"><?= number_format((int) $visitors['ospiti']) ?></p>
          </div>
        </div>
        <a href="<?= htmlspecialchars(url('/admin/attendance'), ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary">
          <i class="fas fa-chart-bar mr-2"></i><?= __("Dettaglio per giorno e fascia oraria") ?>
        </a>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?php
  // Extension sections (patron types, fines, stocktake): filled by other
  // branches through App\Support\StatisticsSections, hidden while NULL.
  foreach (($extraSections ?? []) as $sectionId => $section): ?>
  <div class="card mb-6" id="stats-<?= htmlspecialchars((string) $sectionId, ENT_QUOTES, 'UTF-8') ?>">
    <div class="card-header">
      <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
        <i class="fas <?= htmlspecialchars($section['icon'], ENT_QUOTES, 'UTF-8') ?> text-gray-900"></i>
        <?= htmlspecialchars((string) ($section['title']), ENT_QUOTES, 'UTF-8') ?>
      </h2>
    </div>
    <div class="card-body p-0">
      <table class="min-w-full divide-y divide-gray-200">
        <tbody class="bg-white divide-y divide-gray-200">
          <?php foreach ($section['data']['rows'] as $extraRow): ?>
          <tr>
            <td class="px-6 py-3 text-sm text-gray-900"><?= htmlspecialchars((string) ((string) $extraRow['label']), ENT_QUOTES, 'UTF-8') ?></td>
            <td class="px-6 py-3 text-right text-sm font-semibold"><?= htmlspecialchars((string) ((string) $extraRow['value']), ENT_QUOTES, 'UTF-8') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (isset($section['data']['note']) && (string) $section['data']['note'] !== ''): ?>
        <p class="px-6 py-3 text-xs text-gray-500"><?= htmlspecialchars((string) ((string) $section['data']['note']), ENT_QUOTES, 'UTF-8') ?></p>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- Top Books Table -->
  <div class="grid grid-cols-1 gap-6">
    <div class="card">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-trophy text-yellow-500"></i>
          <?= __("Top 10 Libri Più Prestati") ?>
        </h2>
      </div>
      <div class="card-body p-0">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Titolo") ?></th>
                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Totale") ?></th>
                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Completati") ?></th>
                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Attivi") ?></th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php if (empty($topBooks)): ?>
              <tr>
                <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                  <i class="fas fa-book text-4xl text-gray-300 mb-3"></i>
                  <p><?= __("Nessun prestito registrato") ?></p>
                </td>
              </tr>
              <?php else: ?>
                <?php $rank = 1; foreach ($topBooks as $book): ?>
                <tr class="hover:bg-gray-50 transition-colors">
                  <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center justify-center w-8 h-8 rounded-full <?php echo $rank <= 3 ? 'bg-yellow-100 text-yellow-800 font-bold' : 'bg-gray-100 text-gray-600'; ?>">
                      <?php echo $rank; ?>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                      <?php if (!empty($book['copertina_url'])): ?>
                        <img src="<?php echo htmlspecialchars(url($book['copertina_url']), ENT_QUOTES, 'UTF-8'); ?>"
                             alt="<?php echo App\Support\HtmlHelper::e($book['titolo'] . ' - Copertina'); ?>"
                             class="w-10 h-14 object-cover rounded shadow-sm"
                             onerror="this.onerror=null;this.src=(window.BASE_PATH||'')+'/uploads/copertine/placeholder.jpg'">
                      <?php endif; ?>
                      <div>
                        <a href="<?= htmlspecialchars(url('/admin/books/' . (int)$book['id']), ENT_QUOTES, 'UTF-8') ?>" class="text-sm font-medium text-gray-900 hover:text-blue-600 transition-colors">
                          <?php echo App\Support\HtmlHelper::e($book['titolo']); ?>
                        </a>
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-center">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-100 text-blue-800 font-bold text-sm">
                      <?php echo (int)$book['prestiti_totali']; ?>
                    </span>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-center">
                    <span class="inline-flex items-center justify-center px-2 py-1 rounded bg-green-100 text-green-800 text-xs font-medium">
                      <?php echo (int)$book['prestiti_completati']; ?>
                    </span>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-center">
                    <span class="inline-flex items-center justify-center px-2 py-1 rounded bg-indigo-100 text-indigo-800 text-xs font-medium">
                      <?php echo (int)$book['prestiti_attivi']; ?>
                    </span>
                  </td>
                </tr>
                <?php $rank++; endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Top Readers Table -->
    <div class="card">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-user-graduate text-blue-500"></i>
          <?= __("Top 10 Lettori Più Attivi") ?>
        </h2>
      </div>
      <div class="card-body p-0">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Lettore") ?></th>
                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Totale") ?></th>
                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Completati") ?></th>
                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Attivi") ?></th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php if (empty($topReaders)): ?>
              <tr>
                <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                  <i class="fas fa-users text-4xl text-gray-300 mb-3"></i>
                  <p><?= __("Nessun prestito registrato") ?></p>
                </td>
              </tr>
              <?php else: ?>
                <?php $rank = 1; foreach ($topReaders as $reader): ?>
                <tr class="hover:bg-gray-50 transition-colors">
                  <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center justify-center w-8 h-8 rounded-full <?php echo $rank <= 3 ? 'bg-blue-100 text-blue-800 font-bold' : 'bg-gray-100 text-gray-600'; ?>">
                      <?php echo $rank; ?>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div>
                      <a href="<?= htmlspecialchars(url('/admin/users/' . (int)$reader['id']), ENT_QUOTES, 'UTF-8') ?>" class="text-sm font-medium text-gray-900 hover:text-blue-600 transition-colors">
                        <?php echo htmlspecialchars(full_name($reader['nome'] ?? '', $reader['cognome'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                      </a>
                      <div class="text-xs text-gray-500"><?php echo App\Support\HtmlHelper::e($reader['email']); ?></div>
                    </div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-center">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-100 text-blue-800 font-bold text-sm">
                      <?php echo (int)$reader['prestiti_totali']; ?>
                    </span>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-center">
                    <span class="inline-flex items-center justify-center px-2 py-1 rounded bg-green-100 text-green-800 text-xs font-medium">
                      <?php echo (int)$reader['prestiti_completati']; ?>
                    </span>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-center">
                    <span class="inline-flex items-center justify-center px-2 py-1 rounded bg-indigo-100 text-indigo-800 text-xs font-medium">
                      <?php echo (int)$reader['prestiti_attivi']; ?>
                    </span>
                  </td>
                </tr>
                <?php $rank++; endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Fines report (plan §7) -->
  <?php
    $finesTotals = ['addebitato' => 0, 'incassato' => 0, 'condonato' => 0, 'stornato' => 0];
    foreach (($finesByMonth ?? []) as $finesRow) {
        foreach ($finesTotals as $finesKey => $finesValue) {
            $finesTotals[$finesKey] = $finesValue + (int) $finesRow[$finesKey];
        }
    }
    $finesCsvUrl = url('/admin/statistics/fines.csv') . '?' . http_build_query(['fines_from' => $finesFrom ?? '', 'fines_to' => $finesTo ?? '']);
  ?>
  <div class="grid grid-cols-1 gap-6 mt-6" id="fines-report">
    <div class="card">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-coins text-gray-900"></i>
          <?= __("Multe e pagamenti") ?>
        </h2>
      </div>
      <div class="card-body">
        <form method="GET" action="<?= htmlspecialchars(url('/admin/statistics'), ENT_QUOTES, 'UTF-8') ?>" class="flex flex-wrap items-end gap-4 mb-4">
          <div>
            <label for="fines_from" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Dal") ?></label>
            <input type="date" id="fines_from" name="fines_from" value="<?= htmlspecialchars((string) ($finesFrom ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="block w-full rounded-lg border border-gray-300 px-4 py-2">
          </div>
          <div>
            <label for="fines_to" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Al") ?></label>
            <input type="date" id="fines_to" name="fines_to" value="<?= htmlspecialchars((string) ($finesTo ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="block w-full rounded-lg border border-gray-300 px-4 py-2">
          </div>
          <button type="submit" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center">
            <i class="fas fa-filter mr-2"></i><?= __("Aggiorna") ?>
          </button>
          <a href="<?= htmlspecialchars($finesCsvUrl, ENT_QUOTES, 'UTF-8') ?>" id="fines-csv-link" class="px-4 py-2 bg-gray-100 text-gray-900 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center border border-gray-300">
            <i class="fas fa-file-csv mr-2"></i><?= __("Esporta CSV") ?>
          </a>
        </form>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200" id="fines-report-table">
            <thead class="bg-gray-50">
              <tr>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Mese") ?></th>
                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Addebitato") ?></th>
                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Incassato") ?></th>
                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Condonato") ?></th>
                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Stornato") ?></th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php if (empty($finesByMonth)): ?>
              <tr>
                <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                  <i class="fas fa-coins text-4xl text-gray-300 mb-3"></i>
                  <p><?= __("Nessun movimento nel periodo") ?></p>
                </td>
              </tr>
              <?php else: ?>
                <?php foreach ($finesByMonth as $finesRow): ?>
                <tr class="hover:bg-gray-50 transition-colors">
                  <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars((string) $finesRow['mese'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900"><?= htmlspecialchars(\App\Services\FineParameters::format((int) $finesRow['addebitato']), ENT_QUOTES, 'UTF-8') ?></td>
                  <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900"><?= htmlspecialchars(\App\Services\FineParameters::format((int) $finesRow['incassato']), ENT_QUOTES, 'UTF-8') ?></td>
                  <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900"><?= htmlspecialchars(\App\Services\FineParameters::format((int) $finesRow['condonato']), ENT_QUOTES, 'UTF-8') ?></td>
                  <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900"><?= htmlspecialchars(\App\Services\FineParameters::format((int) $finesRow['stornato']), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="bg-gray-50">
                  <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= __("Totale") ?></td>
                  <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium text-gray-900" data-fines-total="addebitato"><?= htmlspecialchars(\App\Services\FineParameters::format($finesTotals['addebitato']), ENT_QUOTES, 'UTF-8') ?></td>
                  <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium text-gray-900" data-fines-total="incassato"><?= htmlspecialchars(\App\Services\FineParameters::format($finesTotals['incassato']), ENT_QUOTES, 'UTF-8') ?></td>
                  <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium text-gray-900" data-fines-total="condonato"><?= htmlspecialchars(\App\Services\FineParameters::format($finesTotals['condonato']), ENT_QUOTES, 'UTF-8') ?></td>
                  <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium text-gray-900" data-fines-total="stornato"><?= htmlspecialchars(\App\Services\FineParameters::format($finesTotals['stornato']), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Chart.js is loaded from vendor.bundle.js (window.Chart) -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  const workspaceColors = getComputedStyle(document.body);
  const chartAccent = workspaceColors.getPropertyValue('--workspace-accent').trim() || '#d70161';
  const chartInk = workspaceColors.getPropertyValue('--pk-ink').trim() || '#202024';
  const chartPaper = workspaceColors.getPropertyValue('--pk-surface').trim() || '#fff';
  const chartLine = workspaceColors.getPropertyValue('--pk-line-2').trim() || '#e4e4e7';
  // Loans Per Month Chart
  const loansByMonthData = <?php echo json_encode($loansByMonth, JSON_HEX_TAG); ?>;
  const locale = <?= json_encode(str_replace('_', '-', $_SESSION['locale'] ?? 'it-IT'), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const monthLabels = loansByMonthData.map(item => {
    const parts = item.mese.split('-');
    const date = new Date(parts[0], parts[1] - 1);
    return date.toLocaleDateString(locale, { month: 'short', year: 'numeric' });
  });
  const monthValues = loansByMonthData.map(item => parseInt(item.totale_prestiti));

  const loansPerMonthCtx = document.getElementById('loansPerMonthChart');
  new Chart(loansPerMonthCtx, {
    type: 'line',
    data: {
      labels: monthLabels,
      datasets: [{
        label: <?= json_encode(__("Prestiti"), JSON_HEX_TAG) ?>,
        data: monthValues,
        borderColor: chartAccent,
        backgroundColor: 'rgba(113, 113, 123, 0.06)',
        fill: true,
        tension: 0.4,
        pointBackgroundColor: chartAccent,
        pointBorderColor: chartPaper,
        pointBorderWidth: 2,
        pointRadius: 4,
        pointHoverRadius: 6
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: false
        },
        tooltip: {
          backgroundColor: 'rgba(0, 0, 0, 0.8)',
          padding: 12,
          titleFont: { size: 14 },
          bodyFont: { size: 13 },
          cornerRadius: 8
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            precision: 0
          },
          grid: {
            color: chartLine
          }
        },
        x: {
          grid: {
            display: false
          }
        }
      }
    }
  });

  // Loans By Status Chart
  const loansByStatusData = <?php echo json_encode($loansByStatus, JSON_HEX_TAG); ?>;
  const statusLabels = loansByStatusData.map(item => {
    // Etichette canoniche degli stati prestito (loan_status_label_map, #333):
    // niente mappa locale da tenere allineata all'enum.
    const labels = <?= json_encode(loan_status_label_map(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    return labels[item.stato] || item.stato;
  });
  const statusValues = loansByStatusData.map(item => parseInt(item.totale));
  const statusColors = loansByStatusData.map(item => {
    const colors = {
      'in_corso': chartInk,
      'pendente': '#a1a1aa',
      'in_ritardo': chartAccent,
      'perso': '#52525b',
      'danneggiato': '#d4d4d8'
    };
    return colors[item.stato] || '#71717a';
  });

  const loansByStatusCanvas = document.getElementById('loansByStatusChart');
  const loansByStatusWrapper = loansByStatusCanvas?.closest('.card-body');
  const hasStatusData = loansByStatusData.length > 0 && statusValues.some(value => value > 0);

  if (!hasStatusData && loansByStatusWrapper) {
    loansByStatusWrapper.innerHTML = `
      <div class="flex h-full w-full flex-col items-center justify-center gap-3 text-slate-400">
        <i class="fas fa-chart-pie text-4xl"></i>
        <p class="text-sm"><?= htmlspecialchars(__("Nessun prestito disponibile per generare il grafico"), ENT_QUOTES, 'UTF-8') ?></p>
      </div>
    `;
  } else if (loansByStatusCanvas) {
    new Chart(loansByStatusCanvas, {
      type: 'doughnut',
      data: {
        labels: statusLabels,
        datasets: [{
          data: statusValues,
          backgroundColor: statusColors,
          borderWidth: 0,
          hoverOffset: 10
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              padding: 15,
              font: { size: 12 },
              usePointStyle: true,
              pointStyle: 'circle'
            }
          },
          tooltip: {
            backgroundColor: 'rgba(0, 0, 0, 0.8)',
            padding: 12,
            titleFont: { size: 14 },
            bodyFont: { size: 13 },
            cornerRadius: 8,
            callbacks: {
              label: function(context) {
                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                const percentage = total ? ((context.parsed / total) * 100).toFixed(1) : 0;
                return `${context.label}: ${context.parsed} (${percentage}%)`;
              }
            }
          }
        }
      }
    });
  }
});
</script>

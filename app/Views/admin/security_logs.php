<?php
/**
 * @var array $data { logs: array, total_lines: int }
 */
$title = __("Log di Sicurezza");
$logs = $data['logs'] ?? [];
$totalLines = $data['total_lines'] ?? 0;
?>

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
          <i class="fas fa-shield-alt mr-1"></i><?= __("Log di Sicurezza") ?>
        </li>
      </ol>
    </nav>

    <!-- Header -->
    <div class="mb-6">
      <div class="flex items-center justify-between mb-3">
        <div>
          <h1 class="text-3xl font-bold text-gray-900 flex items-center">
            <i class="fas fa-shield-alt text-red-600 mr-3"></i>
            <?= __("Log di Sicurezza") ?>
          </h1>
          <p class="text-sm text-gray-600 mt-1">
            <?= __("Monitora tentativi di login e eventi di sicurezza") ?>
            <span class="ml-2 text-xs bg-gray-200 px-2 py-1 rounded">
              <?= __("Totale: %s righe", number_format($totalLines)) ?>
            </span>
          </p>
        </div>
        <div class="flex items-center gap-3">
          <button id="refresh-logs" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors duration-200 inline-flex items-center">
            <i class="fas fa-sync-alt mr-2"></i>
            <?= __("Aggiorna") ?>
          </button>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-5">
      <div class="p-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-search mr-1 text-gray-500"></i>
              <?= __("Filtra per tipo") ?>
            </label>
            <select id="filter-type" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full">
              <option value=""><?= __("Tutti gli eventi") ?></option>
              <option value="csrf_failed"><?= __("CSRF Fallito") ?></option>
              <option value="invalid_credentials"><?= __("Credenziali Errate") ?></option>
              <option value="email_not_verified"><?= __("Email Non Verificata") ?></option>
              <option value="account_suspended"><?= __("Account Sospeso") ?></option>
              <option value="account_pending"><?= __("Account In Attesa") ?></option>
              <option value="success"><?= __("Login Riuscito") ?></option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-envelope mr-1 text-gray-500"></i>
              <?= __("Email utente") ?>
            </label>
            <input id="filter-email" type="text" placeholder="<?= __("Cerca email...") ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              <i class="fas fa-network-wired mr-1 text-gray-500"></i>
              <?= __("IP Address") ?>
            </label>
            <input id="filter-ip" type="text" placeholder="<?= __("Cerca IP...") ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-400 w-full" />
          </div>
        </div>
      </div>
    </div>

    <!-- Logs Table -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
      <div class="p-6 border-b border-gray-200">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center">
          <i class="fas fa-list text-gray-600 mr-2"></i>
          <?= __("Eventi Recenti") ?>
          <span id="filtered-count" class="ml-2 px-2 py-1 bg-gray-100 text-gray-700 text-xs rounded-full">
            <?= __n('%d evento', '%d eventi', count($logs), count($logs)) ?>
          </span>
        </h2>
      </div>
      <div class="p-6">
        <!-- Mobile scroll hint -->
        <div class="md:hidden mb-3 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-800 flex items-center gap-2">
          <i class="fas fa-hand-point-right"></i>
          <span><?= __("Scorri a destra per vedere tutte le colonne") ?></span>
        </div>

        <div class="overflow-x-auto">
          <table id="logs-table" class="w-full">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase"><?= __("Timestamp") ?></th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase"><?= __("Tipo") ?></th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase"><?= __("Email") ?></th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase"><?= __("IP") ?></th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase"><?= __("Dettagli") ?></th>
              </tr>
            </thead>
            <tbody id="logs-body" class="divide-y divide-gray-100">
              <?php if (empty($logs)): ?>
                <tr>
                  <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                    <i class="fas fa-inbox text-4xl mb-2"></i>
                    <p><?= __("Nessun log disponibile") ?></p>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($logs as $log): ?>
                  <tr class="log-row hover:bg-gray-50" data-type="<?= htmlspecialchars($log['type'], ENT_QUOTES, 'UTF-8') ?>" data-email="<?= htmlspecialchars($log['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-ip="<?= htmlspecialchars($log['ip'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                      <?= htmlspecialchars($log['timestamp'], ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td class="px-4 py-3">
                      <?php
                      $badges = [
                        'csrf_failed' => ['bg-red-100 text-red-800', __('CSRF')],
                        'invalid_credentials' => ['bg-orange-100 text-orange-800', __('Credenziali')],
                        'email_not_verified' => ['bg-yellow-100 text-yellow-800', __('Email')],
                        'account_suspended' => ['bg-purple-100 text-purple-800', __('Sospeso')],
                        'account_pending' => ['bg-blue-100 text-blue-800', __('In Attesa')],
                        'success' => ['bg-green-100 text-green-800', __('OK')]
                      ];
                      $badgeInfo = $badges[$log['type']] ?? ['bg-gray-100 text-gray-800', __('Altro')];
                      ?>
                      <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $badgeInfo[0] ?>">
                        <?= $badgeInfo[1] ?>
                      </span>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-900">
                      <?= htmlspecialchars($log['email'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600 font-mono">
                      <?= htmlspecialchars($log['ip'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600">
                      <details>
                        <summary class="cursor-pointer text-blue-600 hover:text-blue-800">
                          <i class="fas fa-eye mr-1"></i><?= __("Mostra") ?>
                        </summary>
                        <pre class="mt-2 p-2 bg-gray-50 rounded text-xs overflow-x-auto"><?= htmlspecialchars(json_encode($log['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?></pre>
                      </details>
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
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Filter functionality
  const filterType = document.getElementById('filter-type');
  const filterEmail = document.getElementById('filter-email');
  const filterIp = document.getElementById('filter-ip');
  const logRows = document.querySelectorAll('.log-row');
  const filteredCount = document.getElementById('filtered-count');

  function applyFilters() {
    const type = filterType.value.toLowerCase();
    const email = filterEmail.value.toLowerCase();
    const ip = filterIp.value.toLowerCase();
    let visibleCount = 0;

    logRows.forEach(row => {
      const rowType = row.dataset.type.toLowerCase();
      const rowEmail = row.dataset.email.toLowerCase();
      const rowIp = row.dataset.ip.toLowerCase();

      const typeMatch = !type || rowType.includes(type);
      const emailMatch = !email || rowEmail.includes(email);
      const ipMatch = !ip || rowIp.includes(ip);

      if (typeMatch && emailMatch && ipMatch) {
        row.style.display = '';
        visibleCount++;
      } else {
        row.style.display = 'none';
      }
    });

    filteredCount.textContent = visibleCount === 1 ? '<?= addslashes(__("1 evento")) ?>' : visibleCount + ' <?= addslashes(__("eventi")) ?>';
  }

  filterType.addEventListener('change', applyFilters);
  filterEmail.addEventListener('input', applyFilters);
  filterIp.addEventListener('input', applyFilters);

  // Refresh button
  document.getElementById('refresh-logs').addEventListener('click', () => {
    window.location.reload();
  });
});
</script>

<style>
.log-row {
  transition: background-color 0.2s;
}

details[open] summary {
  margin-bottom: 0.5rem;
}

pre {
  max-height: 300px;
  overflow-y: auto;
}

/* Mobile horizontal scroll styling */
@media (max-width: 768px) {
  /* Make timestamp column wider on mobile */
  #logs-table th:first-child,
  #logs-table td:first-child {
    min-width: 160px !important;
    width: 160px !important;
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

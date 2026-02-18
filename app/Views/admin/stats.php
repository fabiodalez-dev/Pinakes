<?php
/** @var array $loansByMonth */
/** @var array $loansByStatus */
?>
<section class="min-h-screen py-6 px-4">
  <!-- Header -->
  <div class="mb-6">
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li>
          <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i><?= __("Home") ?>
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li class="text-gray-900 font-medium">
          <span><?= __("Statistiche Prestiti") ?></span>
        </li>
      </ol>
    </nav>

    <div class="flex items-center justify-between">
      <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
        <i class="fas fa-chart-bar text-gray-700"></i>
        <?= __("Statistiche Prestiti") ?>
      </h1>
    </div>
  </div>

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
          <i class="fas fa-chart-line text-primary"></i>
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
          <i class="fas fa-chart-pie text-primary"></i>
          <?= __("Prestiti per Stato") ?>
        </h2>
      </div>
      <div class="card-body h-[360px] flex items-center justify-center">
        <canvas id="loansByStatusChart" class="w-full h-full max-w-[420px]"></canvas>
      </div>
    </div>
  </div>

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
                             onerror="this.onerror=null;this.src=window.BASE_PATH+'/uploads/copertine/placeholder.jpg'">
                      <?php endif; ?>
                      <div>
                        <a href="<?= htmlspecialchars(url('/admin/libri/' . (int)$book['id']), ENT_QUOTES, 'UTF-8') ?>" class="text-sm font-medium text-gray-900 hover:text-blue-600 transition-colors">
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
                      <a href="<?= htmlspecialchars(url('/admin/utenti/' . (int)$reader['id']), ENT_QUOTES, 'UTF-8') ?>" class="text-sm font-medium text-gray-900 hover:text-blue-600 transition-colors">
                        <?php echo App\Support\HtmlHelper::e($reader['nome'] . ' ' . $reader['cognome']); ?>
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
</section>

<!-- Chart.js is loaded from vendor.bundle.js (window.Chart) -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  // Loans Per Month Chart
  const loansByMonthData = <?php echo json_encode($loansByMonth); ?>;
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
        label: '<?= addslashes(__("Prestiti")) ?>',
        data: monthValues,
        borderColor: 'rgb(59, 130, 246)',
        backgroundColor: 'rgba(59, 130, 246, 0.1)',
        fill: true,
        tension: 0.4,
        pointBackgroundColor: 'rgb(59, 130, 246)',
        pointBorderColor: '#fff',
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
            color: 'rgba(0, 0, 0, 0.05)'
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
  const loansByStatusData = <?php echo json_encode($loansByStatus); ?>;
  const statusLabels = loansByStatusData.map(item => {
    const labels = {
      'in_corso': '<?= addslashes(__("In Corso")) ?>',
      'pendente': '<?= addslashes(__("Pendente")) ?>',
      'in_ritardo': '<?= addslashes(__("In Ritardo")) ?>',
      'perso': '<?= addslashes(__("Perso")) ?>',
      'danneggiato': '<?= addslashes(__("Danneggiato")) ?>'
    };
    return labels[item.stato] || item.stato;
  });
  const statusValues = loansByStatusData.map(item => parseInt(item.totale));
  const statusColors = loansByStatusData.map(item => {
    const colors = {
      'in_corso': 'rgb(59, 130, 246)',
      'pendente': 'rgb(99, 102, 241)',
      'in_ritardo': 'rgb(239, 68, 68)',
      'perso': 'rgb(245, 158, 11)',
      'danneggiato': 'rgb(234, 179, 8)'
    };
    return colors[item.stato] || 'rgb(107, 114, 128)';
  });

  const loansByStatusCanvas = document.getElementById('loansByStatusChart');
  const loansByStatusWrapper = loansByStatusCanvas?.closest('.card-body');
  const hasStatusData = loansByStatusData.length > 0 && statusValues.some(value => value > 0);

  if (!hasStatusData && loansByStatusWrapper) {
    loansByStatusWrapper.innerHTML = `
      <div class="flex h-full w-full flex-col items-center justify-center gap-3 text-slate-400">
        <i class="fas fa-chart-pie text-4xl"></i>
        <p class="text-sm"><?= addslashes(__("Nessun prestito disponibile per generare il grafico")) ?></p>
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

<?php
/**
 * @var array<int,array<string,mixed>> $transactions
 * @var array<int,array<string,mixed>> $partners
 * @var int $total
 * @var int $page
 * @var int $perPage
 */
use App\Support\HtmlHelper;

$totalPages = (int) ceil($total / max(1, $perPage));
$statusColors = [
    'ok'      => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
    'error'   => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
    'pending' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300',
];
$partnersById = [];
foreach ($partners as $p) {
    $partnersById[(int) $p['id']] = $p['name'];
}
?>
<div class="flex-1 overflow-x-hidden">

  <!-- Page Header -->
  <div class="bg-white/50 backdrop-blur-sm border-b border-gray-200/80 dark:bg-gray-900/50 dark:border-gray-800/80 sticky top-0 z-30">
    <div class="px-6 py-4">
      <div class="flex items-center justify-between">
        <div class="flex items-center space-x-3">
          <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-cyan-600 rounded-lg flex items-center justify-center">
            <i class="fas fa-list-alt text-white text-sm"></i>
          </div>
          <div>
            <h1 class="text-xl font-bold text-gray-900 dark:text-white"><?= __("Log Transazioni NCIP") ?></h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">
              <?= __("%d transazioni totali", $total) ?>
            </p>
          </div>
        </div>
        <a href="<?= htmlspecialchars(url('/admin/plugins/ncip-server/partners'), ENT_QUOTES, 'UTF-8') ?>"
           class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-700 text-white text-sm rounded-lg hover:bg-gray-700 dark:hover:bg-gray-600 transition-colors">
          <i class="fas fa-network-wired mr-2"></i>
          <?= __("Gestisci Partner") ?>
        </a>
      </div>
    </div>
  </div>

  <div class="p-6">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">

      <?php if (empty($transactions)): ?>
      <div class="p-10 text-center text-gray-400 dark:text-gray-500">
        <i class="fas fa-inbox text-4xl mb-3 block"></i>
        <p class="text-sm"><?= __("Nessuna transazione registrata.") ?></p>
      </div>
      <?php else: ?>
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
          <thead class="bg-gray-50 dark:bg-gray-700/50">
            <tr>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">#</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= __("Tipo Messaggio") ?></th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= __("Partner") ?></th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= __("ID Prestito") ?></th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= __("ID Richiesta") ?></th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= __("Stato") ?></th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider"><?= __("Data") ?></th>
            </tr>
          </thead>
          <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
            <?php foreach ($transactions as $tx): ?>
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
              <td class="px-5 py-3 text-sm text-gray-400 dark:text-gray-500 font-mono">
                <?= (int) ($tx['id'] ?? 0) ?>
              </td>
              <td class="px-5 py-3">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300">
                  <?= htmlspecialchars((string) ($tx['message_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </span>
              </td>
              <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300">
                <?php
                $pid = (int) ($tx['partner_id'] ?? 0);
                echo htmlspecialchars($pid > 0 ? ($partnersById[$pid] ?? '—') : '—', ENT_QUOTES, 'UTF-8');
                ?>
              </td>
              <td class="px-5 py-3 text-sm text-gray-600 dark:text-gray-300 font-mono">
                <?= (int) ($tx['prestito_id'] ?? 0) ?: '—' ?>
              </td>
              <td class="px-5 py-3 text-sm text-gray-500 dark:text-gray-400 font-mono max-w-xs truncate">
                <?= htmlspecialchars((string) ($tx['request_id'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
              </td>
              <td class="px-5 py-3">
                <?php
                $status = strtolower((string) ($tx['status'] ?? ''));
                $cls = $statusColors[$status] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300';
                ?>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $cls ?>">
                  <?= htmlspecialchars((string) ($tx['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </span>
              </td>
              <td class="px-5 py-3 text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">
                <?= htmlspecialchars((string) ($tx['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
      <div class="px-5 py-4 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
        <p class="text-sm text-gray-500 dark:text-gray-400">
          <?= __("Pagina %d di %d", $page, $totalPages) ?>
        </p>
        <div class="flex gap-2">
          <?php if ($page > 1): ?>
          <a href="<?= htmlspecialchars(url('/admin/plugins/ncip-server/transactions?page=' . ($page - 1)), ENT_QUOTES, 'UTF-8') ?>"
             class="px-3 py-1.5 text-sm bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
            &larr; <?= __("Precedente") ?>
          </a>
          <?php endif; ?>
          <?php if ($page < $totalPages): ?>
          <a href="<?= htmlspecialchars(url('/admin/plugins/ncip-server/transactions?page=' . ($page + 1)), ENT_QUOTES, 'UTF-8') ?>"
             class="px-3 py-1.5 text-sm bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
            <?= __("Successiva") ?> &rarr;
          </a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php endif; ?>

    </div>
  </div>
</div>

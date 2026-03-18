<?php
/** @var array $collane */
use App\Support\HtmlHelper;
?>

<div class="max-w-5xl mx-auto">
  <!-- Header -->
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
        <i class="fas fa-layer-group text-indigo-500"></i>
        <?= __("Gestione Collane") ?>
      </h1>
      <p class="text-sm text-gray-500 mt-1"><?= __("Gestisci le collane e le serie di libri") ?></p>
    </div>
  </div>

  <!-- Messages -->
  <?php if (!empty($_SESSION['success_message'])): ?>
  <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg text-green-800 text-sm">
    <i class="fas fa-check-circle mr-1"></i> <?= HtmlHelper::e($_SESSION['success_message']) ?>
  </div>
  <?php unset($_SESSION['success_message']); endif; ?>

  <?php if (!empty($_SESSION['error_message'])): ?>
  <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-800 text-sm">
    <i class="fas fa-exclamation-circle mr-1"></i> <?= HtmlHelper::e($_SESSION['error_message']) ?>
  </div>
  <?php unset($_SESSION['error_message']); endif; ?>

  <!-- Stats -->
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
      <div class="text-2xl font-bold text-indigo-600"><?= count($collane) ?></div>
      <div class="text-sm text-gray-500"><?= __("Collane totali") ?></div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
      <div class="text-2xl font-bold text-indigo-600"><?= array_sum(array_column($collane, 'book_count')) ?></div>
      <div class="text-sm text-gray-500"><?= __("Libri nelle collane") ?></div>
    </div>
  </div>

  <?php if (empty($collane)): ?>
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8 text-center">
    <i class="fas fa-layer-group text-gray-300 text-4xl mb-3"></i>
    <p class="text-gray-500"><?= __("Nessuna collana trovata. Aggiungi una collana a un libro per iniziare.") ?></p>
  </div>
  <?php else: ?>

  <!-- Collane List -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
      <thead class="bg-gray-50">
        <tr>
          <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= __("Collana") ?></th>
          <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?= __("Libri") ?></th>
          <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?= __("Volumi") ?></th>
          <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase"></th>
        </tr>
      </thead>
      <tbody class="bg-white divide-y divide-gray-200">
        <?php foreach ($collane as $c): ?>
        <tr class="hover:bg-gray-50">
          <td class="px-6 py-4">
            <a href="<?= htmlspecialchars(url('/admin/collane/dettaglio?nome=' . urlencode($c['collana'])), ENT_QUOTES, 'UTF-8') ?>" class="text-indigo-600 hover:underline font-medium">
              <?= HtmlHelper::e($c['collana']) ?>
            </a>
          </td>
          <td class="px-6 py-4 text-center">
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">
              <?= (int) $c['book_count'] ?>
            </span>
          </td>
          <td class="px-6 py-4 text-center text-sm text-gray-500">
            <?php if ($c['min_num'] && $c['max_num']): ?>
              <?= (int) $c['min_num'] ?> – <?= (int) $c['max_num'] ?>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td class="px-6 py-4 text-right">
            <a href="<?= htmlspecialchars(url('/admin/collane/dettaglio?nome=' . urlencode($c['collana'])), ENT_QUOTES, 'UTF-8') ?>" class="text-sm text-gray-500 hover:text-indigo-600">
              <i class="fas fa-chevron-right"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

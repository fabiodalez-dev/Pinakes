<?php
/**
 * Book Club — admin clubs list.
 *
 * @var list<array<string, mixed>> $clubs
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$privacyLabels = [
    'public' => __('Pubblico'),
    'private' => __('Privato'),
    'invite' => __('Su invito'),
    'hidden' => __('Nascosto'),
];
?>
<div class="p-6 max-w-7xl mx-auto">
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900"><?= $e(__('Book Club')) ?></h1>
      <p class="text-sm text-gray-500 mt-1"><?= $e(__('Gestione dei club di lettura')) ?></p>
    </div>
    <a href="<?= $e(url('/admin/book-club/new')) ?>"
       class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
      <i class="fas fa-plus mr-2"></i><?= $e(__('Nuovo club')) ?>
    </a>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="mb-4 px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <div class="bg-white rounded-xl shadow overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
      <thead class="bg-gray-50">
        <tr>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= $e(__('Club')) ?></th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= $e(__('Privacy')) ?></th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= $e(__('Membri')) ?></th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= $e(__('Stato')) ?></th>
          <th class="px-4 py-3"></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (empty($clubs)): ?>
          <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400"><?= $e(__('Nessun club ancora. Crea il primo!')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($clubs as $club): ?>
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-3">
              <div class="flex items-center">
                <span class="inline-block w-3 h-3 rounded-full mr-3" style="background: <?= $e($club['color']) ?>"></span>
                <div>
                  <a class="font-medium text-gray-900 hover:text-blue-600" href="<?= $e(url('/admin/book-club/' . (int) $club['id'])) ?>"><?= $e($club['name']) ?></a>
                  <div class="text-xs text-gray-400">/book-club/<?= $e($club['slug']) ?></div>
                </div>
              </div>
            </td>
            <td class="px-4 py-3 text-sm text-gray-600"><?= $e($privacyLabels[$club['privacy']] ?? $club['privacy']) ?></td>
            <td class="px-4 py-3 text-sm text-gray-600"><?= (int) $club['member_count'] ?><?= $club['max_members'] !== null ? ' / ' . (int) $club['max_members'] : '' ?></td>
            <td class="px-4 py-3">
              <?php if ((int) $club['is_active'] === 1): ?>
                <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800"><?= $e(__('Attivo')) ?></span>
              <?php else: ?>
                <span class="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-600"><?= $e(__('Disattivato')) ?></span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3 text-right text-sm whitespace-nowrap">
              <a class="text-blue-600 hover:underline mr-3" href="<?= $e(url('/admin/book-club/' . (int) $club['id'])) ?>"><?= $e(__('Gestisci')) ?></a>
              <a class="text-gray-500 hover:underline mr-3" href="<?= $e(url('/admin/book-club/' . (int) $club['id'] . '/edit')) ?>"><?= $e(__('Modifica')) ?></a>
              <a class="text-gray-500 hover:underline" target="_blank" href="<?= $e(url('/book-club/' . $club['slug'])) ?>"><?= $e(__('Vedi')) ?></a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

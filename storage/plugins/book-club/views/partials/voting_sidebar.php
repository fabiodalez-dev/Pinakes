<?php
/**
 * Book Club — voting2 sidebar block: link from the club page to the poll
 * list (where the advanced creation form lives for managers).
 *
 * @var array<string, mixed> $club
 * @var int $openCount number of open polls
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
?>
<section class="bg-white rounded-xl shadow p-6">
  <h2 class="text-sm font-semibold text-gray-400 uppercase mb-3"><?= $e(__('Votazioni avanzate')) ?></h2>
  <p class="text-sm text-gray-500 mb-3">
    <?php if ($openCount > 0): ?>
      <?= $e(sprintf(__n('%d votazione aperta', '%d votazioni aperte', $openCount), $openCount)) ?>
    <?php else: ?>
      <?= $e(__('Nessuna votazione aperta.')) ?>
    <?php endif; ?>
  </p>
  <a href="<?= $e(url('/book-club/' . $slug . '/polls')) ?>"
     class="block text-center w-full px-4 py-2 bg-gray-900 hover:bg-gray-700 text-white text-sm font-medium rounded-lg">
    <?= $e(__('Tutte le votazioni')) ?>
  </a>
</section>

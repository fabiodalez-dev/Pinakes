<?php
/**
 * Book Club — surveys module panel on the public club page: open surveys
 * with their answer count and a link to answer / see them. Rendered for
 * members/managers only (managers also get the "all surveys" shortcut even
 * when nothing is open).
 *
 * @var array<string, mixed> $club
 * @var list<array<string, mixed>> $open       open surveys with answer_count
 * @var list<int> $answeredIds                 survey ids the viewer answered
 * @var bool $isMember
 * @var bool $canManage
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$base = url('/book-club/' . $slug . '/surveys');
?>
<section class="bg-white rounded-xl shadow p-6">
  <div class="flex items-center justify-between mb-4">
    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-clipboard-list mr-2 text-gray-400"></i><?= $e(__('Questionari')) ?></h2>
    <a href="<?= $e($base) ?>" class="text-xs text-blue-600 hover:underline whitespace-nowrap">
      <?= $e(__('Tutti i questionari')) ?> <i class="fas fa-arrow-right ml-1"></i>
    </a>
  </div>

  <?php if ($open === []): ?>
    <p class="text-sm text-gray-400"><?= $e(__('Nessun questionario aperto al momento.')) ?></p>
  <?php endif; ?>

  <?php foreach ($open as $survey): ?>
    <?php $answered = in_array((int) $survey['id'], $answeredIds, true); ?>
    <div class="border rounded-lg px-4 py-3 mb-3 last:mb-0 flex flex-wrap items-center justify-between gap-3">
      <div>
        <a href="<?= $e($base . '/' . (int) $survey['id']) ?>" class="font-medium text-gray-900 hover:text-blue-600"><?= $e($survey['title']) ?></a>
        <div class="text-xs text-gray-400 mt-1 flex flex-wrap gap-x-4 gap-y-1">
          <span><i class="fas fa-reply mr-1"></i><?= $e(sprintf(__('%d risposte'), (int) $survey['answer_count'])) ?></span>
          <?php if ((int) $survey['anonymous'] === 1): ?>
            <span><i class="fas fa-user-secret mr-1"></i><?= $e(__('Anonimo')) ?></span>
          <?php endif; ?>
          <?php if (!empty($survey['closes_at'])): ?>
            <span><i class="far fa-clock mr-1"></i><?= $e(__('Chiude il')) ?> <?= $e(date('d/m/Y H:i', (int) strtotime((string) $survey['closes_at']))) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div>
        <?php if ($answered): ?>
          <span class="inline-flex items-center px-3 py-1.5 text-xs bg-green-50 text-green-700 rounded-lg"><i class="fas fa-check mr-1"></i><?= $e(__('Hai già risposto')) ?></span>
        <?php elseif ($isMember): ?>
          <a href="<?= $e($base . '/' . (int) $survey['id']) ?>" class="inline-flex items-center px-3 py-1.5 text-xs bg-blue-600 hover:bg-blue-700 text-white rounded-lg"><?= $e(__('Rispondi al questionario')) ?></a>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>

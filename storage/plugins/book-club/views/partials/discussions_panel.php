<?php
/**
 * Book Club — club-page panel of the discussions module: last five threads
 * by activity, link to the full list and a quick new-thread form.
 *
 * @var array<string, mixed> $club
 * @var list<array<string, mixed>> $threads
 * @var bool $isMember
 * @var bool $canManage
 * @var string $csrf
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
?>
<section class="bg-white rounded-xl shadow p-6">
  <div class="flex items-center justify-between mb-4">
    <h2 class="text-lg font-semibold text-gray-900"><?= $e(__('Discussioni')) ?></h2>
    <a class="text-sm text-blue-600 hover:underline" href="<?= $e(url('/book-club/' . $slug . '/discussions')) ?>">
      <?= $e(__('Tutte le discussioni')) ?> <i class="fas fa-arrow-right ml-1"></i>
    </a>
  </div>

  <?php if ($threads === []): ?>
    <p class="text-sm text-gray-400"><?= $e(__('Ancora nessuna discussione: apri la prima!')) ?></p>
  <?php endif; ?>

  <?php foreach ($threads as $thread): ?>
    <div class="flex items-center justify-between border-t first:border-t-0 py-2 text-sm">
      <div class="min-w-0 flex items-center gap-2">
        <?php if ((int) $thread['is_pinned'] === 1): ?>
          <i class="fas fa-thumbtack text-amber-500 text-xs" title="<?= $e(__('In evidenza')) ?>"></i>
        <?php endif; ?>
        <?php if ((int) $thread['is_locked'] === 1): ?>
          <i class="fas fa-lock text-gray-400 text-xs" title="<?= $e(__('Bloccata')) ?>"></i>
        <?php endif; ?>
        <a class="font-medium text-blue-600 hover:underline truncate"
           href="<?= $e(url('/book-club/' . $slug . '/discussions/' . (int) $thread['id'])) ?>"><?= $e($thread['title']) ?></a>
      </div>
      <div class="text-xs text-gray-400 whitespace-nowrap ml-3">
        <?= (int) $thread['post_count'] ?> <?= $e(__n('messaggio', 'messaggi', (int) $thread['post_count'])) ?>
        · <?= $e(date('d/m/Y', (int) strtotime((string) $thread['last_activity']))) ?>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($isMember || $canManage): ?>
    <form method="post" action="<?= $e(url('/book-club/' . $slug . '/discussions/new')) ?>" class="flex items-center gap-2 mt-4 border-t pt-4">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
      <input type="hidden" name="kind" value="free">
      <input type="text" name="title" required maxlength="190"
             placeholder="<?= $e(__('Apri una nuova discussione…')) ?>"
             class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm">
      <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg whitespace-nowrap"><?= $e(__('Apri')) ?></button>
    </form>
  <?php endif; ?>
</section>

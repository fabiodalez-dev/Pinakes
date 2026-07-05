<?php
/**
 * Book Club — stats module panel on the public club page: club headline
 * numbers, the viewing member's personal activity and a link to the full
 * statistics page. Rendered for members/managers only.
 *
 * @var array<string, mixed> $club
 * @var array{books_total: int, finished: int, members_active: int, meetings_done: int} $headline
 * @var array{votes_cast: int, rsvp_yes: int, posts_written: int|null}|null $mine
 * @var bool $canManage
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$tiles = [
    [__('Libri nel club'), (int) $headline['books_total'], 'fa-book'],
    [__('Libri conclusi'), (int) $headline['finished'], 'fa-flag-checkered'],
    [__('Membri attivi'), (int) $headline['members_active'], 'fa-users'],
    [__('Incontri svolti'), (int) $headline['meetings_done'], 'fa-calendar-check'],
];
?>
<section class="bg-white rounded-xl shadow p-6">
  <div class="flex items-center justify-between mb-4">
    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-chart-bar mr-2 text-gray-400"></i><?= $e(__('Statistiche del club')) ?></h2>
    <a href="<?= $e(url('/book-club/' . $slug . '/stats')) ?>" class="text-xs text-blue-600 hover:underline whitespace-nowrap">
      <?= $e(__('Vedi tutte le statistiche')) ?> <i class="fas fa-arrow-right ml-1"></i>
    </a>
  </div>

  <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
    <?php foreach ($tiles as [$label, $value, $icon]): ?>
      <div class="border rounded-lg px-3 py-3 text-center">
        <div class="text-2xl font-bold text-gray-900"><?= (int) $value ?></div>
        <div class="text-xs text-gray-400 mt-1"><i class="fas <?= $e($icon) ?> mr-1"></i><?= $e($label) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($mine !== null): ?>
    <div class="mt-4 border-t pt-4">
      <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2"><?= $e(__('La tua attività')) ?></h3>
      <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-500">
        <span><i class="fas fa-vote-yea mr-1 text-gray-300"></i><?= $e(__('Voti espressi')) ?>: <span class="font-medium text-gray-700"><?= (int) $mine['votes_cast'] ?></span></span>
        <span><i class="fas fa-check-circle mr-1 text-gray-300"></i><?= $e(__('Presenze confermate')) ?>: <span class="font-medium text-gray-700"><?= (int) $mine['rsvp_yes'] ?></span></span>
        <?php if ($mine['posts_written'] !== null): ?>
          <span><i class="fas fa-comments mr-1 text-gray-300"></i><?= $e(__('Post scritti')) ?>: <span class="font-medium text-gray-700"><?= (int) $mine['posts_written'] ?></span></span>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>

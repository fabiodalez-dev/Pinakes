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
<section class="bc-card">
  <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
    <div class="bc-section-header mb-0">
      <i class="fas fa-chart-bar"></i>
      <h2><?= $e(__('Statistiche del club')) ?></h2>
    </div>
    <a href="<?= $e(url('/book-club/' . $slug . '/stats')) ?>" class="bc-btn bc-btn-outline bc-btn-sm">
      <?= $e(__('Vedi tutte le statistiche')) ?> <i class="fas fa-arrow-right"></i>
    </a>
  </div>

  <div class="flex flex-wrap -mx-3 gap-y-3">
    <?php foreach ($tiles as [$label, $value, $icon]): ?>
      <div class="w-full w-1/2 px-3 md:w-1/4">
        <div class="border rounded-md px-2 py-3 text-center h-full">
          <div class="text-3xl font-bold"><?= (int) $value ?></div>
          <div class="bc-muted mt-1"><i class="fas <?= $e($icon) ?> mr-1"></i><?= $e($label) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($mine !== null): ?>
    <div class="mt-4 pt-4 border-t">
      <h3 class="text-sm font-semibold uppercase text-gray-500 mb-2"><?= $e(__('La tua attività')) ?></h3>
      <div class="flex flex-wrap gap-3 bc-muted">
        <span><i class="fas fa-vote-yea mr-1"></i><?= $e(__('Voti espressi')) ?>: <span class="font-semibold"><?= (int) $mine['votes_cast'] ?></span></span>
        <span><i class="fas fa-check-circle mr-1"></i><?= $e(__('Presenze confermate')) ?>: <span class="font-semibold"><?= (int) $mine['rsvp_yes'] ?></span></span>
        <?php if ($mine['posts_written'] !== null): ?>
          <span><i class="fas fa-comments mr-1"></i><?= $e(__('Post scritti')) ?>: <span class="font-semibold"><?= (int) $mine['posts_written'] ?></span></span>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>

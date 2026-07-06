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
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div class="bc-section-header mb-0">
      <i class="fas fa-chart-bar"></i>
      <h2><?= $e(__('Statistiche del club')) ?></h2>
    </div>
    <a href="<?= $e(url('/book-club/' . $slug . '/stats')) ?>" class="bc-btn bc-btn-outline bc-btn-sm">
      <?= $e(__('Vedi tutte le statistiche')) ?> <i class="fas fa-arrow-right"></i>
    </a>
  </div>

  <div class="row g-3">
    <?php foreach ($tiles as [$label, $value, $icon]): ?>
      <div class="col-6 col-md-3">
        <div class="border rounded-3 px-2 py-3 text-center h-100">
          <div class="fs-3 fw-bold"><?= (int) $value ?></div>
          <div class="bc-muted mt-1"><i class="fas <?= $e($icon) ?> me-1"></i><?= $e($label) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($mine !== null): ?>
    <div class="mt-4 pt-4 border-top">
      <h3 class="small fw-semibold text-uppercase text-muted mb-2"><?= $e(__('La tua attività')) ?></h3>
      <div class="d-flex flex-wrap gap-3 bc-muted">
        <span><i class="fas fa-vote-yea me-1"></i><?= $e(__('Voti espressi')) ?>: <span class="fw-semibold"><?= (int) $mine['votes_cast'] ?></span></span>
        <span><i class="fas fa-check-circle me-1"></i><?= $e(__('Presenze confermate')) ?>: <span class="fw-semibold"><?= (int) $mine['rsvp_yes'] ?></span></span>
        <?php if ($mine['posts_written'] !== null): ?>
          <span><i class="fas fa-comments me-1"></i><?= $e(__('Post scritti')) ?>: <span class="fw-semibold"><?= (int) $mine['posts_written'] ?></span></span>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>

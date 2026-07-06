<?php
/**
 * Book Club — sprints module panel on the public club page: the next sprint
 * (scheduled or running) with a server-rendered countdown text and a join
 * button, plus the link to the full sprints page.
 *
 * @var array<string, mixed> $club
 * @var array<string, mixed>|null $next        next sprint row (or null)
 * @var string|null $nextStatus                'scheduled'|'running' (derived)
 * @var bool $joined                           viewer already participates
 * @var bool $isMember
 * @var string $csrf
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$base = url('/book-club/' . $slug . '/sprints');

$countdown = static function (int $seconds): string {
    $minutes = max(1, (int) ceil($seconds / 60));
    $days = intdiv($minutes, 1440);
    $hours = intdiv($minutes % 1440, 60);
    $mins = $minutes % 60;
    $parts = [];
    if ($days > 0) {
        $parts[] = sprintf(__('%d g'), $days);
    }
    if ($hours > 0) {
        $parts[] = sprintf(__('%d h'), $hours);
    }
    if ($mins > 0 || $parts === []) {
        $parts[] = sprintf(__('%d min'), $mins);
    }
    return implode(' ', $parts);
};
$now = time();
?>
<section class="bc-card">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div class="bc-section-header mb-0">
      <i class="fas fa-stopwatch"></i>
      <h2><?= $e(__('Reading Sprint')) ?></h2>
    </div>
    <a href="<?= $e($base) ?>" class="bc-btn bc-btn-outline bc-btn-sm">
      <?= $e(__('Tutti gli sprint')) ?> <i class="fas fa-arrow-right"></i>
    </a>
  </div>

  <?php if ($next === null): ?>
    <p class="bc-muted mb-0">
      <?= $e(__('Nessuno sprint in programma.')) ?>
      <?php if ($isMember): ?>
        <a href="<?= $e($base) ?>"><?= $e(__('Organizzane uno!')) ?></a>
      <?php endif; ?>
    </p>
  <?php else: ?>
    <?php
      $startTs = (int) strtotime((string) $next['starts_at']);
      $endTs = $startTs + (int) $next['duration_min'] * 60;
    ?>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div class="overflow-hidden">
        <div class="fw-semibold text-truncate"><?= $e($next['title']) ?></div>
        <div class="bc-muted small mt-1">
          <i class="far fa-clock me-1"></i><?= $e(date('d/m/Y H:i', $startTs)) ?>
          · <?= $e(sprintf(__('%d minuti'), (int) $next['duration_min'])) ?>
          <?php if (!empty($next['book_title'])): ?>
            · <i class="fas fa-book me-1"></i><?= $e($next['book_title']) ?>
          <?php endif; ?>
          · <?= $e(sprintf(__('%d partecipanti'), (int) $next['participant_count'])) ?>
        </div>
        <?php if ($nextStatus === 'running'): ?>
          <p class="small text-success fw-semibold mt-1 mb-0"><i class="fas fa-book-open me-1"></i><?= $e(sprintf(__('In corso — termina tra %s'), $countdown($endTs - $now))) ?></p>
        <?php else: ?>
          <p class="small fw-semibold mt-1 mb-0"><i class="fas fa-play me-1"></i><?= $e(sprintf(__('Inizia tra %s'), $countdown($startTs - $now))) ?></p>
        <?php endif; ?>
      </div>

      <?php if ($isMember && $nextStatus === 'scheduled'): ?>
        <?php if ($joined): ?>
          <span class="bc-badge bc-badge-open text-nowrap">
            <i class="fas fa-check"></i><?= $e(__('Sei iscritto')) ?>
          </span>
        <?php else: ?>
          <form method="post" action="<?= $e($base . '/' . (int) $next['id'] . '/join') ?>">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <button type="submit" class="bc-btn bc-btn-sm">
              <i class="fas fa-user-plus"></i><?= $e(__('Partecipa')) ?>
            </button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

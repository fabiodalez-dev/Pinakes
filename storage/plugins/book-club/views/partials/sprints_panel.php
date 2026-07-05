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
<section class="bg-white rounded-xl shadow p-6">
  <div class="flex items-center justify-between mb-4">
    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-stopwatch mr-2 text-gray-400"></i><?= $e(__('Reading Sprint')) ?></h2>
    <a href="<?= $e($base) ?>" class="text-xs text-blue-600 hover:underline whitespace-nowrap">
      <?= $e(__('Tutti gli sprint')) ?> <i class="fas fa-arrow-right ml-1"></i>
    </a>
  </div>

  <?php if ($next === null): ?>
    <p class="text-sm text-gray-400">
      <?= $e(__('Nessuno sprint in programma.')) ?>
      <?php if ($isMember): ?>
        <a href="<?= $e($base) ?>" class="text-blue-600 hover:underline"><?= $e(__('Organizzane uno!')) ?></a>
      <?php endif; ?>
    </p>
  <?php else: ?>
    <?php
      $startTs = (int) strtotime((string) $next['starts_at']);
      $endTs = $startTs + (int) $next['duration_min'] * 60;
    ?>
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="min-w-0">
        <div class="font-medium text-gray-900 truncate"><?= $e($next['title']) ?></div>
        <div class="text-xs text-gray-400 mt-0.5">
          <i class="far fa-clock mr-1"></i><?= $e(date('d/m/Y H:i', $startTs)) ?>
          · <?= $e(sprintf(__('%d minuti'), (int) $next['duration_min'])) ?>
          <?php if (!empty($next['book_title'])): ?>
            · <i class="fas fa-book mr-1"></i><?= $e($next['book_title']) ?>
          <?php endif; ?>
          · <?= $e(sprintf(__('%d partecipanti'), (int) $next['participant_count'])) ?>
        </div>
        <?php if ($nextStatus === 'running'): ?>
          <p class="text-sm text-green-700 mt-1"><i class="fas fa-book-open mr-1"></i><?= $e(sprintf(__('In corso — termina tra %s'), $countdown($endTs - $now))) ?></p>
        <?php else: ?>
          <p class="text-sm text-blue-700 mt-1"><i class="fas fa-play mr-1"></i><?= $e(sprintf(__('Inizia tra %s'), $countdown($startTs - $now))) ?></p>
        <?php endif; ?>
      </div>

      <?php if ($isMember && $nextStatus === 'scheduled'): ?>
        <?php if ($joined): ?>
          <span class="px-3 py-1.5 text-xs bg-green-50 text-green-700 rounded-lg whitespace-nowrap">
            <i class="fas fa-check mr-1"></i><?= $e(__('Sei iscritto')) ?>
          </span>
        <?php else: ?>
          <form method="post" action="<?= $e($base . '/' . (int) $next['id'] . '/join') ?>">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <button type="submit" class="px-3 py-1.5 text-xs bg-gray-900 hover:bg-gray-700 text-white rounded-lg whitespace-nowrap">
              <i class="fas fa-user-plus mr-1"></i><?= $e(__('Partecipa')) ?>
            </button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

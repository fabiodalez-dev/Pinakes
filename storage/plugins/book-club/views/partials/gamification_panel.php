<?php
/**
 * Book Club — gamification module panel on the public club page: the
 * viewing member's XP, level, progress towards the next level and badges,
 * plus the club top 3 and a link to the full leaderboard. Rendered for
 * members/managers only.
 *
 * @var array<string, mixed> $club
 * @var array{xp: int, level: int, level_start: int, next_level_xp: int, badges: list<array<string, mixed>>}|null $mine
 * @var list<array{name: string, xp: int, level: int}> $top
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
?>
<section class="bc-card">
  <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
    <div class="bc-section-header mb-0">
      <i class="fas fa-trophy"></i>
      <h2><?= $e(__('Gamification')) ?></h2>
    </div>
    <a href="<?= $e(url('/book-club/' . $slug . '/leaderboard')) ?>" class="bc-btn bc-btn-outline bc-btn-sm">
      <?= $e(__('Vedi la classifica completa')) ?> <i class="fas fa-arrow-right"></i>
    </a>
  </div>

  <?php if ($mine !== null): ?>
    <?php
      $span = max(1, (int) $mine['next_level_xp'] - (int) $mine['level_start']);
      $pct = min(100.0, max(0.0, ((int) $mine['xp'] - (int) $mine['level_start']) / $span * 100));
    ?>
    <div class="flex items-center gap-3 mb-3">
      <div class="rounded-full flex items-center justify-center text-white text-2xl font-bold shrink-0"
           style="width: 56px; height: 56px; background: <?= $e($club['color']) ?>">
        <?= (int) $mine['level'] ?>
      </div>
      <div class="grow overflow-hidden">
        <div class="flex items-baseline justify-between">
          <span class="font-semibold"><?= $e(sprintf(__('Livello %d'), (int) $mine['level'])) ?></span>
          <span class="bc-muted"><?= (int) $mine['xp'] ?> XP</span>
        </div>
        <div class="bc-progress mt-1">
          <span style="width: <?= number_format($pct, 1, '.', '') ?>%; background: <?= $e($club['color']) ?>"></span>
        </div>
        <div class="bc-muted text-sm mt-1"><?= $e(sprintf(__('Prossimo livello a %d XP'), (int) $mine['next_level_xp'])) ?></div>
      </div>
    </div>

    <div class="mb-4">
      <?php if ($mine['badges'] === []): ?>
        <p class="bc-muted text-sm mb-0"><?= $e(__('Nessun badge ancora: partecipa alla vita del club per sbloccarli!')) ?></p>
      <?php else: ?>
        <div class="flex flex-wrap gap-2">
          <?php foreach ($mine['badges'] as $badge): ?>
            <span class="bc-badge bc-badge-closed" title="<?= $e($badge['description']) ?>">
              <i class="fas <?= $e($badge['icon']) ?> text-amber-600"></i><?= $e($badge['name']) ?>
            </span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="<?= $mine !== null ? 'border-top pt-4' : '' ?>">
    <h3 class="text-sm font-semibold uppercase text-gray-500 mb-2"><?= $e(__('Top lettori')) ?></h3>
    <?php if ($top === []): ?>
      <p class="bc-muted mb-0"><?= $e(__('La classifica è ancora vuota: i punti vengono calcolati dalle attività del club.')) ?></p>
    <?php else: ?>
      <?php foreach ($top as $i => $row): ?>
        <div class="flex items-center gap-3 mb-1">
          <?php if ($i === 0): ?>
            <i class="fas fa-medal" style="color: <?= $e($club['color']) ?>"></i>
          <?php else: ?>
            <i class="fas fa-medal text-gray-500"></i>
          <?php endif; ?>
          <span class="grow truncate"><?= $e($row['name']) ?></span>
          <span class="bc-muted text-sm whitespace-nowrap"><?= $e(sprintf(__('Livello %d'), (int) $row['level'])) ?></span>
          <span class="font-semibold whitespace-nowrap"><?= (int) $row['xp'] ?> XP</span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

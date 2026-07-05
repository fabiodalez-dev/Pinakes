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
<section class="bg-white rounded-xl shadow p-6">
  <div class="flex items-center justify-between mb-4">
    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-trophy mr-2 text-gray-400"></i><?= $e(__('Gamification')) ?></h2>
    <a href="<?= $e(url('/book-club/' . $slug . '/leaderboard')) ?>" class="text-xs text-blue-600 hover:underline whitespace-nowrap">
      <?= $e(__('Vedi la classifica completa')) ?> <i class="fas fa-arrow-right ml-1"></i>
    </a>
  </div>

  <?php if ($mine !== null): ?>
    <?php
      $span = max(1, (int) $mine['next_level_xp'] - (int) $mine['level_start']);
      $pct = min(100.0, max(0.0, ((int) $mine['xp'] - (int) $mine['level_start']) / $span * 100));
    ?>
    <div class="flex items-center gap-4 mb-3">
      <div class="w-14 h-14 rounded-full flex items-center justify-center text-white text-xl font-bold shrink-0" style="background: <?= $e($club['color']) ?>">
        <?= (int) $mine['level'] ?>
      </div>
      <div class="flex-1 min-w-0">
        <div class="flex items-baseline justify-between text-sm">
          <span class="font-semibold text-gray-900"><?= $e(sprintf(__('Livello %d'), (int) $mine['level'])) ?></span>
          <span class="text-gray-500"><?= (int) $mine['xp'] ?> XP</span>
        </div>
        <div class="h-2 bg-gray-100 rounded-full overflow-hidden mt-1">
          <div class="h-full rounded-full" style="width: <?= number_format($pct, 1, '.', '') ?>%; background: <?= $e($club['color']) ?>"></div>
        </div>
        <div class="text-xs text-gray-400 mt-1"><?= $e(sprintf(__('Prossimo livello a %d XP'), (int) $mine['next_level_xp'])) ?></div>
      </div>
    </div>

    <div class="mb-4">
      <?php if ($mine['badges'] === []): ?>
        <p class="text-xs text-gray-400"><?= $e(__('Nessun badge ancora: partecipa alla vita del club per sbloccarli!')) ?></p>
      <?php else: ?>
        <div class="flex flex-wrap gap-2">
          <?php foreach ($mine['badges'] as $badge): ?>
            <span class="inline-flex items-center px-2 py-1 rounded-full bg-gray-100 text-xs text-gray-700" title="<?= $e($badge['description']) ?>">
              <i class="fas <?= $e($badge['icon']) ?> mr-1 text-yellow-500"></i><?= $e($badge['name']) ?>
            </span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="<?= $mine !== null ? 'border-t pt-4' : '' ?>">
    <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2"><?= $e(__('Top lettori')) ?></h3>
    <?php if ($top === []): ?>
      <p class="text-sm text-gray-400"><?= $e(__('La classifica è ancora vuota: i punti vengono calcolati dalle attività del club.')) ?></p>
    <?php else: ?>
      <?php $medalColors = ['text-yellow-400', 'text-gray-400', 'text-amber-600']; ?>
      <?php foreach ($top as $i => $row): ?>
        <div class="flex items-center gap-3 mb-1 text-sm">
          <i class="fas fa-medal <?= $e($medalColors[$i] ?? 'text-gray-300') ?>"></i>
          <span class="flex-1 text-gray-700 truncate"><?= $e($row['name']) ?></span>
          <span class="text-xs text-gray-400 whitespace-nowrap"><?= $e(sprintf(__('Livello %d'), (int) $row['level'])) ?></span>
          <span class="font-medium text-gray-700 whitespace-nowrap"><?= (int) $row['xp'] ?> XP</span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

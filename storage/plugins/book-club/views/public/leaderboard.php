<?php
/**
 * Book Club — public leaderboard page (members/managers): full XP ranking
 * with level and badges per member, the viewer's own position, the XP
 * formula legend and the badge catalogue.
 *
 * Data comes from bookclub_xp_snapshot, recomputed from the club's activity
 * tables at most once per hour (lazily on view + maintenance tick).
 *
 * @var array<string, mixed> $club
 * @var list<array{rank: int, user_id: int, name: string, xp: int, level: int, badges: list<array<string, mixed>>, is_me: bool}> $ranking
 * @var array{rank: int, name: string, xp: int, level: int, badges: list<array<string, mixed>>}|null $me
 * @var list<array<string, mixed>> $allBadges
 * @var bool $canManage
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$medalColors = ['text-yellow-400', 'text-gray-400', 'text-amber-600'];
$xpRules = [
    [__('Libro concluso'), \App\Plugins\BookClub\GamificationRepo::XP_FINISHED_BOOK, 'fa-flag-checkered'],
    [__('Proposta di lettura accettata'), \App\Plugins\BookClub\GamificationRepo::XP_PROPOSAL, 'fa-lightbulb'],
    [__('Presenza confermata a un incontro'), \App\Plugins\BookClub\GamificationRepo::XP_RSVP_YES, 'fa-calendar-check'],
    [__('Voto espresso in una votazione'), \App\Plugins\BookClub\GamificationRepo::XP_VOTE, 'fa-vote-yea'],
    [__('Post scritto nelle discussioni'), \App\Plugins\BookClub\GamificationRepo::XP_POST, 'fa-comments'],
];
?>
<div class="max-w-4xl mx-auto px-4 py-10">
  <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="text-sm text-gray-500 hover:text-gray-700">
    <i class="fas fa-arrow-left mr-1"></i><?= $e(__('Torna al club')) ?>
  </a>

  <div class="flex flex-wrap items-center justify-between gap-3 mt-4 mb-6">
    <h1 class="text-2xl font-bold text-gray-900 flex items-center">
      <span class="inline-block w-3 h-3 rounded-full mr-3" style="background: <?= $e($club['color']) ?>"></span>
      <?= $e(__('Classifica')) ?> — <?= $e($club['name']) ?>
    </h1>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if ($me !== null): ?>
    <!-- My position -->
    <section class="bg-white rounded-xl shadow p-6 mb-8">
      <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3"><?= $e(__('La tua posizione')) ?></h2>
      <div class="flex flex-wrap items-center gap-4">
        <div class="w-14 h-14 rounded-full flex items-center justify-center text-white text-xl font-bold shrink-0" style="background: <?= $e($club['color']) ?>">
          <?= (int) $me['level'] ?>
        </div>
        <div>
          <div class="text-lg font-bold text-gray-900">#<?= (int) $me['rank'] ?> — <?= $e($me['name']) ?></div>
          <div class="text-sm text-gray-500"><?= $e(sprintf(__('Livello %d'), (int) $me['level'])) ?> · <?= (int) $me['xp'] ?> XP</div>
        </div>
        <?php if ($me['badges'] !== []): ?>
          <div class="flex flex-wrap gap-2 ml-auto">
            <?php foreach ($me['badges'] as $badge): ?>
              <span class="inline-flex items-center px-2 py-1 rounded-full bg-gray-100 text-xs text-gray-700" title="<?= $e($badge['description']) ?>">
                <i class="fas <?= $e($badge['icon']) ?> mr-1 text-yellow-500"></i><?= $e($badge['name']) ?>
              </span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- Ranking -->
  <section class="bg-white rounded-xl shadow mb-8 overflow-hidden">
    <div class="px-6 py-4 border-b">
      <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-trophy mr-2 text-gray-400"></i><?= $e(__('Classifica del club')) ?></h2>
    </div>
    <?php if ($ranking === []): ?>
      <p class="px-6 py-6 text-sm text-gray-400"><?= $e(__('La classifica è ancora vuota: i punti vengono calcolati dalle attività del club.')) ?></p>
    <?php endif; ?>
    <?php foreach ($ranking as $row): ?>
      <div class="flex items-center gap-3 px-6 py-3 border-b last:border-b-0 <?= $row['is_me'] ? 'bg-blue-50' : '' ?>">
        <div class="w-8 text-center shrink-0">
          <?php if ($row['rank'] <= 3): ?>
            <i class="fas fa-medal <?= $e($medalColors[$row['rank'] - 1]) ?>"></i>
          <?php else: ?>
            <span class="text-sm font-semibold text-gray-400"><?= (int) $row['rank'] ?></span>
          <?php endif; ?>
        </div>
        <div class="flex-1 min-w-0">
          <span class="text-sm font-medium text-gray-800 truncate">
            <?= $e($row['name']) ?>
            <?php if ($row['is_me']): ?>
              <span class="ml-1 px-1.5 py-0.5 rounded bg-blue-100 text-blue-700 text-xs"><?= $e(__('Tu')) ?></span>
            <?php endif; ?>
          </span>
          <?php if ($row['badges'] !== []): ?>
            <span class="ml-2 whitespace-nowrap">
              <?php foreach ($row['badges'] as $badge): ?>
                <i class="fas <?= $e($badge['icon']) ?> text-yellow-500 text-xs mr-1" title="<?= $e($badge['name']) ?> — <?= $e($badge['description']) ?>"></i>
              <?php endforeach; ?>
            </span>
          <?php endif; ?>
        </div>
        <div class="w-20 text-center text-xs text-gray-400 whitespace-nowrap shrink-0"><?= $e(sprintf(__('Livello %d'), (int) $row['level'])) ?></div>
        <div class="w-24 text-right text-sm font-semibold text-gray-800 whitespace-nowrap shrink-0"><?= (int) $row['xp'] ?> XP</div>
      </div>
    <?php endforeach; ?>
  </section>

  <!-- XP formula -->
  <section class="bg-white rounded-xl shadow p-6 mb-8">
    <h2 class="text-lg font-semibold text-gray-900 mb-4"><?= $e(__('Come si guadagnano i punti')) ?></h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
      <?php foreach ($xpRules as [$label, $xp, $icon]): ?>
        <div class="flex items-center gap-3 text-sm">
          <i class="fas <?= $e($icon) ?> w-5 text-center text-gray-300"></i>
          <span class="flex-1 text-gray-700"><?= $e($label) ?></span>
          <span class="font-semibold text-gray-800 whitespace-nowrap">+<?= (int) $xp ?> XP</span>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="text-xs text-gray-400 mt-4">
      <?= $e(__('Il livello si calcola come 1 + parte intera della radice quadrata di XP/100: livello 2 a 100 XP, livello 3 a 400 XP, livello 4 a 900 XP.')) ?>
      <?= $e(__('I punti vengono ricalcolati automaticamente al massimo una volta all\'ora.')) ?>
    </p>
  </section>

  <!-- Badge catalogue -->
  <section class="bg-white rounded-xl shadow p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4"><?= $e(__('Badge disponibili')) ?></h2>
    <?php if ($allBadges === []): ?>
      <p class="text-sm text-gray-400"><?= $e(__('Nessun badge configurato.')) ?></p>
    <?php endif; ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <?php foreach ($allBadges as $badge): ?>
        <div class="flex items-start gap-3 border rounded-lg px-3 py-3">
          <div class="w-9 h-9 rounded-full bg-yellow-50 flex items-center justify-center shrink-0">
            <i class="fas <?= $e($badge['icon']) ?> text-yellow-500"></i>
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-gray-800"><?= $e($badge['name']) ?></div>
            <div class="text-xs text-gray-400"><?= $e($badge['description']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>

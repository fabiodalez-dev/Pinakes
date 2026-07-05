<?php
/**
 * Book Club — public club statistics page (members/managers): headline
 * tiles, books per workflow state, top proposers and (managers) the full
 * history export links.
 *
 * @var array<string, mixed> $club
 * @var list<array{key: string, label: string, color: string, count: int}> $stateRows
 * @var int $pendingCount
 * @var int $finished
 * @var int $meetingsDone
 * @var int $membersActive
 * @var float|null $avgStars
 * @var list<array{name: string, n: int}> $topProposers
 * @var bool $canManage
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$maxCount = 1;
foreach ($stateRows as $row) {
    $maxCount = max($maxCount, (int) $row['count']);
}
$maxProposer = 1;
foreach ($topProposers as $proposer) {
    $maxProposer = max($maxProposer, (int) $proposer['n']);
}
?>
<div class="max-w-4xl mx-auto px-4 py-10">
  <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="text-sm text-gray-500 hover:text-gray-700">
    <i class="fas fa-arrow-left mr-1"></i><?= $e(__('Torna al club')) ?>
  </a>

  <div class="flex flex-wrap items-center justify-between gap-3 mt-4 mb-6">
    <h1 class="text-2xl font-bold text-gray-900 flex items-center">
      <span class="inline-block w-3 h-3 rounded-full mr-3" style="background: <?= $e($club['color']) ?>"></span>
      <?= $e(__('Statistiche')) ?> — <?= $e($club['name']) ?>
    </h1>
    <?php if ($canManage): ?>
      <div class="flex items-center gap-2">
        <a href="<?= $e(url('/book-club/' . $slug . '/export.json')) ?>"
           class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg"><i class="fas fa-file-code mr-1"></i><?= $e(__('Esporta JSON')) ?></a>
        <a href="<?= $e(url('/book-club/' . $slug . '/export.csv')) ?>"
           class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg"><i class="fas fa-file-csv mr-1"></i><?= $e(__('Esporta CSV')) ?></a>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Headline tiles -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
    <div class="bg-white rounded-xl shadow p-5 text-center">
      <div class="text-3xl font-bold text-gray-900"><?= (int) $finished ?></div>
      <div class="text-xs text-gray-400 mt-1"><i class="fas fa-flag-checkered mr-1"></i><?= $e(__('Libri conclusi')) ?></div>
    </div>
    <div class="bg-white rounded-xl shadow p-5 text-center">
      <div class="text-3xl font-bold text-gray-900"><?= (int) $meetingsDone ?></div>
      <div class="text-xs text-gray-400 mt-1"><i class="fas fa-calendar-check mr-1"></i><?= $e(__('Incontri svolti')) ?></div>
    </div>
    <div class="bg-white rounded-xl shadow p-5 text-center">
      <div class="text-3xl font-bold text-gray-900"><?= (int) $membersActive ?></div>
      <div class="text-xs text-gray-400 mt-1"><i class="fas fa-users mr-1"></i><?= $e(__('Membri attivi')) ?></div>
    </div>
    <div class="bg-white rounded-xl shadow p-5 text-center">
      <div class="text-3xl font-bold text-gray-900">
        <?= $avgStars !== null ? $e(number_format($avgStars, 1)) . ' <i class="fas fa-star text-yellow-400 text-xl"></i>' : '—' ?>
      </div>
      <div class="text-xs text-gray-400 mt-1"><?= $e(__('Media stelle recensioni')) ?></div>
    </div>
  </div>

  <!-- Books per workflow state -->
  <section class="bg-white rounded-xl shadow p-6 mb-8">
    <h2 class="text-lg font-semibold text-gray-900 mb-4"><?= $e(__('Libri per stato')) ?></h2>
    <?php foreach ($stateRows as $row): ?>
      <div class="flex items-center gap-3 mb-2">
        <div class="w-40 flex items-center text-sm text-gray-700 shrink-0">
          <span class="inline-block w-2.5 h-2.5 rounded-full mr-2 shrink-0" style="background: <?= $e($row['color']) ?>"></span>
          <span class="truncate"><?= $e($row['label']) ?></span>
        </div>
        <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
          <div class="h-full rounded-full" style="width: <?= number_format((int) $row['count'] / $maxCount * 100, 1, '.', '') ?>%; background: <?= $e($row['color']) ?>"></div>
        </div>
        <div class="w-8 text-right text-sm font-medium text-gray-700"><?= (int) $row['count'] ?></div>
      </div>
    <?php endforeach; ?>
    <?php if ($canManage && $pendingCount > 0): ?>
      <p class="text-xs text-gray-400 mt-3"><?= $e(sprintf(__('%d proposte in attesa di moderazione'), (int) $pendingCount)) ?></p>
    <?php endif; ?>
  </section>

  <!-- Top proposers -->
  <section class="bg-white rounded-xl shadow p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4"><?= $e(__('Top proponenti')) ?></h2>
    <?php if ($topProposers === []): ?>
      <p class="text-sm text-gray-400"><?= $e(__('Nessun libro proposto finora.')) ?></p>
    <?php endif; ?>
    <?php foreach ($topProposers as $proposer): ?>
      <div class="flex items-center gap-3 mb-2">
        <div class="w-40 text-sm text-gray-700 truncate shrink-0"><?= $e($proposer['name']) ?></div>
        <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
          <div class="h-full rounded-full" style="width: <?= number_format((int) $proposer['n'] / $maxProposer * 100, 1, '.', '') ?>%; background: <?= $e($club['color']) ?>"></div>
        </div>
        <div class="w-24 text-right text-xs text-gray-400"><?= $e(sprintf(__('%d proposte'), (int) $proposer['n'])) ?></div>
      </div>
    <?php endforeach; ?>
  </section>
</div>

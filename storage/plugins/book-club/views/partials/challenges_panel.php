<?php
/**
 * Book Club — challenges module panel on the public club page: the viewing
 * member's current-year challenges (own personal ones + club-wide ones)
 * with progress bars and a link to the full Reading Challenge page.
 * Rendered for active members only (see ChallengesModule::renderClubPanel).
 *
 * @var array<string, mixed> $club
 * @var list<array{challenge: array<string, mixed>, isClubWide: bool, current: int}> $items
 * @var int $year
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$metricLabels = [
    'books' => __('Libri finiti'),
    'pages' => __('Pagine lette'),
    'authors' => __('Autori diversi'),
];
?>
<section class="bg-white rounded-xl shadow p-6">
  <div class="flex items-center justify-between mb-4">
    <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-bullseye mr-2 text-gray-400"></i><?= $e(__('Le mie sfide di lettura')) ?> <?= (int) $year ?></h2>
    <a href="<?= $e(url('/book-club/' . $slug . '/challenges')) ?>" class="text-xs text-blue-600 hover:underline whitespace-nowrap">
      <?= $e(__('Vedi tutte le sfide')) ?> <i class="fas fa-arrow-right ml-1"></i>
    </a>
  </div>

  <?php foreach ($items as $item): ?>
    <?php
      $challenge = $item['challenge'];
      $isClubWide = !empty($item['isClubWide']);
      $current = (int) $item['current'];
      $target = max(1, (int) $challenge['target']);
      $percent = min(100.0, max(0.0, $current / $target * 100));
    ?>
    <div class="mb-3">
      <div class="flex items-center justify-between text-sm mb-0.5">
        <span class="text-gray-700 truncate">
          <?= $e($challenge['title']) ?>
          <?php if ($isClubWide): ?>
            <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600"><?= $e(__('Sfida di club')) ?></span>
          <?php endif; ?>
        </span>
        <span class="font-medium text-gray-600 whitespace-nowrap ml-3"><?= $current ?> / <?= (int) $challenge['target'] ?></span>
      </div>
      <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
        <div class="h-full rounded-full <?= $isClubWide ? '' : 'bg-blue-600' ?>"
             style="width: <?= number_format($percent, 1, '.', '') ?>%;<?= $isClubWide ? ' background: ' . $e($club['color']) . ';' : '' ?>"></div>
      </div>
      <div class="flex items-center justify-between text-xs text-gray-400 mt-0.5">
        <span><?= $e($metricLabels[(string) $challenge['metric']] ?? (string) $challenge['metric']) ?></span>
        <?php if ($current >= $target): ?>
          <span class="text-green-600 font-medium"><i class="fas fa-flag-checkered mr-1"></i><?= $e(__('Sfida completata!')) ?></span>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>

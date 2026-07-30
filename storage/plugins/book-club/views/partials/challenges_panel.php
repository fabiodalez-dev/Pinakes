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
<section class="bc-card">
  <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
    <div class="bc-section-header mb-0">
      <i class="fas fa-bullseye"></i>
      <h2><?= $e(__('Le mie sfide di lettura')) ?> <?= (int) $year ?></h2>
    </div>
    <a href="<?= $e(url('/book-club/' . $slug . '/challenges')) ?>" class="bc-btn bc-btn-outline bc-btn-sm">
      <?= $e(__('Vedi tutte le sfide')) ?> <i class="fas fa-arrow-right"></i>
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
      <div class="flex items-center justify-between mb-1">
        <span class="truncate">
          <?= $e($challenge['title']) ?>
          <?php if ($isClubWide): ?>
            <span class="bc-badge bc-badge-closed ml-1"><?= $e(__('Sfida di club')) ?></span>
          <?php endif; ?>
        </span>
        <span class="font-semibold whitespace-nowrap ml-3"><?= $current ?> / <?= (int) $challenge['target'] ?></span>
      </div>
      <div class="bc-progress">
        <span style="width: <?= number_format($percent, 1, '.', '') ?>%;<?= $isClubWide ? ' background: ' . $e($club['color']) . ';' : '' ?>"></span>
      </div>
      <div class="flex items-center justify-between bc-muted text-sm mt-1">
        <span><?= $e($metricLabels[(string) $challenge['metric']] ?? (string) $challenge['metric']) ?></span>
        <?php if ($current >= $target): ?>
          <span class="text-green-600 font-semibold"><i class="fas fa-flag-checkered mr-1"></i><?= $e(__('Sfida completata!')) ?></span>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>

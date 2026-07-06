<?php
/**
 * Book Club — reading module panel on the public club page: for every book
 * in a `current`-flagged state, personal + aggregate progress bars and a
 * link to the full reading tracker page.
 *
 * @var array<string, mixed> $club
 * @var list<array{book: array<string, mixed>, mine: array<string, mixed>|null, aggregate: array{avg_percent: float, avg_percent_all: float, finished: int, readers: int, active_readers: int, members: int}}> $items
 * @var int $memberCount
 * @var bool $isMember
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
?>
<section class="bc-card">
  <div class="bc-section-header">
    <i class="fas fa-book-open"></i>
    <h2><?= $e(__('Lettura condivisa')) ?></h2>
  </div>
  <?php foreach ($items as $item): ?>
    <?php
      $book = $item['book'];
      $mine = $item['mine'];
      $aggregate = $item['aggregate'];
      $myPercent = $mine !== null ? max(0, min(100, (int) $mine['percent'])) : 0;
      $avgPercent = max(0.0, min(100.0, (float) ($aggregate['avg_percent_all'] ?? $aggregate['avg_percent'])));
      $readingUrl = url('/book-club/' . $slug . '/reading/' . (int) $book['id']);
    ?>
    <div class="border rounded-3 px-3 py-3 mb-3">
      <div class="d-flex align-items-start gap-3">
        <?php if (!empty($book['copertina_url'])): ?>
          <img src="<?= $e($book['copertina_url']) ?>" alt="" class="bc-cover flex-shrink-0" loading="lazy">
        <?php endif; ?>
        <div class="flex-grow-1 overflow-hidden">
          <div class="fw-semibold"><?= $e($book['titolo']) ?></div>
          <?php if (!empty($book['autori'])): ?><div class="bc-muted"><?= $e($book['autori']) ?></div><?php endif; ?>

          <?php if ($isMember): ?>
            <div class="d-flex align-items-center justify-content-between bc-muted small mt-2 mb-1">
              <span><?= $e(__('Il mio progresso')) ?></span>
              <span class="fw-semibold"><?= $myPercent ?>%</span>
            </div>
            <div class="bc-progress">
              <span style="width: <?= $myPercent ?>%"></span>
            </div>
          <?php endif; ?>

          <div class="d-flex align-items-center justify-content-between bc-muted small mt-2 mb-1">
            <span><?= $e(__('avanzamento medio del club')) ?></span>
            <span class="fw-semibold"><?= number_format($avgPercent, 0) ?>%</span>
          </div>
          <div class="bc-progress">
            <span style="width: <?= number_format($avgPercent, 1, '.', '') ?>%; background: <?= $e($club['color']) ?>"></span>
          </div>

          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-2">
            <span class="bc-muted small">
              <?= $e(sprintf(__('%1$d lettori su %2$d membri'), (int) ($aggregate['active_readers'] ?? 0), (int) ($aggregate['members'] ?? $memberCount))) ?>
              · <?= $e(sprintf(__('%1$d membri su %2$d hanno finito il libro'), (int) $aggregate['finished'], (int) $memberCount)) ?>
            </span>
            <a href="<?= $e($readingUrl) ?>" class="small text-nowrap">
              <?= $e(__('Apri il tracker di lettura')) ?> <i class="fas fa-arrow-right ms-1"></i>
            </a>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</section>

<?php
/**
 * Book Club — reading module panel on the public club page: for every book
 * in a `current`-flagged state, personal + aggregate progress bars and a
 * link to the full reading tracker page.
 *
 * @var array<string, mixed> $club
 * @var list<array{book: array<string, mixed>, mine: array<string, mixed>|null, aggregate: array{avg_percent: float, finished: int, readers: int}}> $items
 * @var int $memberCount
 * @var bool $isMember
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
?>
<section class="bg-white rounded-xl shadow p-6">
  <h2 class="text-lg font-semibold text-gray-900 mb-4"><i class="fas fa-book-open mr-2 text-gray-400"></i><?= $e(__('Lettura condivisa')) ?></h2>
  <?php foreach ($items as $item): ?>
    <?php
      $book = $item['book'];
      $mine = $item['mine'];
      $aggregate = $item['aggregate'];
      $myPercent = $mine !== null ? max(0, min(100, (int) $mine['percent'])) : 0;
      $avgPercent = max(0.0, min(100.0, (float) $aggregate['avg_percent']));
      $readingUrl = url('/book-club/' . $slug . '/reading/' . (int) $book['id']);
    ?>
    <div class="border rounded-lg px-4 py-3 mb-3">
      <div class="flex items-start gap-3">
        <?php if (!empty($book['copertina_url'])): ?>
          <img src="<?= $e($book['copertina_url']) ?>" alt="" class="w-10 h-14 object-cover rounded shadow-sm" loading="lazy">
        <?php endif; ?>
        <div class="flex-1 min-w-0">
          <div class="font-medium text-gray-900"><?= $e($book['titolo']) ?></div>
          <?php if (!empty($book['autori'])): ?><div class="text-sm text-gray-500"><?= $e($book['autori']) ?></div><?php endif; ?>

          <?php if ($isMember): ?>
            <div class="flex items-center justify-between text-xs text-gray-400 mt-2 mb-0.5">
              <span><?= $e(__('Il mio progresso')) ?></span>
              <span class="font-medium text-gray-600"><?= $myPercent ?>%</span>
            </div>
            <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
              <div class="h-full rounded-full bg-blue-600" style="width: <?= $myPercent ?>%"></div>
            </div>
          <?php endif; ?>

          <div class="flex items-center justify-between text-xs text-gray-400 mt-2 mb-0.5">
            <span><?= $e(__('Avanzamento medio del club')) ?></span>
            <span class="font-medium text-gray-600"><?= number_format($avgPercent, 0) ?>%</span>
          </div>
          <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
            <div class="h-full rounded-full" style="width: <?= number_format($avgPercent, 1, '.', '') ?>%; background: <?= $e($club['color']) ?>"></div>
          </div>

          <div class="flex items-center justify-between mt-2">
            <span class="text-xs text-gray-400"><?= $e(sprintf(__('%1$d membri su %2$d hanno finito il libro'), (int) $aggregate['finished'], (int) $memberCount)) ?></span>
            <a href="<?= $e($readingUrl) ?>" class="text-xs text-blue-600 hover:underline whitespace-nowrap">
              <?= $e(__('Apri il tracker di lettura')) ?> <i class="fas fa-arrow-right ml-1"></i>
            </a>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</section>

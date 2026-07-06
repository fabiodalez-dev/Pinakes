<?php
/**
 * Book Club — seasons module panel on the public club page: season list
 * with manager CRUD (create, set current, edit dates/target, delete when
 * empty) and the per-season historical archive (storico) of archived books.
 *
 * @var array<string, mixed> $club
 * @var list<array<string, mixed>> $seasons        with book_count
 * @var list<array<string, mixed>> $archivedBooks  with season_name
 * @var list<array<string, mixed>> $assignBooks    non-pending club books (id, season_id, titolo, autori) — managers only
 * @var bool $canManage
 * @var string $csrf
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$fmtDate = static fn(?string $d): string => $d !== null && $d !== '' ? date('d/m/Y', (int) strtotime($d)) : '…';

$archivedBySeason = [];
foreach ($archivedBooks as $book) {
    $label = $book['season_name'] !== null && $book['season_name'] !== ''
        ? (string) $book['season_name']
        : __('Senza stagione');
    $archivedBySeason[$label][] = $book;
}
?>
<section class="bg-white rounded-xl shadow p-6">
  <h2 class="text-lg font-semibold text-gray-900 mb-4"><i class="fas fa-layer-group mr-2 text-gray-400"></i><?= $e(__('Stagioni')) ?></h2>

  <?php if ($seasons === []): ?>
    <p class="text-sm text-gray-400"><?= $e(__('Nessuna stagione definita.')) ?></p>
  <?php endif; ?>

  <?php foreach ($seasons as $season): ?>
    <?php
      $seasonId = (int) $season['id'];
      $isCurrent = (int) $season['is_current'] === 1;
      $bookCount = (int) $season['book_count'];
      $target = $season['books_target'] !== null ? (int) $season['books_target'] : null;
    ?>
    <div class="border rounded-lg px-4 py-3 mb-3 <?= $isCurrent ? 'border-blue-300 bg-blue-50/40' : 'border-gray-200' ?>">
      <div class="flex items-start justify-between gap-3">
        <div>
          <div class="font-medium text-gray-900">
            <?= $e($season['name']) ?>
            <?php if ($isCurrent): ?>
              <span class="ml-2 px-2 py-0.5 text-xs rounded-full bg-blue-100 text-blue-800"><?= $e(__('Stagione corrente')) ?></span>
            <?php endif; ?>
          </div>
          <div class="text-xs text-gray-400 mt-0.5">
            <i class="far fa-calendar mr-1"></i><?= $e($fmtDate($season['starts_on'] !== null ? (string) $season['starts_on'] : null)) ?>
            → <?= $e($fmtDate($season['ends_on'] !== null ? (string) $season['ends_on'] : null)) ?>
            · <?= $e(sprintf(__('%d libri'), $bookCount)) ?>
            <?php if ($target !== null): ?>
              · <?= $e(sprintf(__('Obiettivo: %d libri'), $target)) ?>
            <?php endif; ?>
          </div>
          <?php if ($target !== null && $target > 0): ?>
            <div class="mt-2 h-1.5 w-48 bg-gray-100 rounded-full overflow-hidden">
              <div class="h-full rounded-full" style="width: <?= number_format(min(100, $bookCount / $target * 100), 1, '.', '') ?>%; background: <?= $e($club['color']) ?>"></div>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($canManage): ?>
          <div class="flex items-center gap-2 whitespace-nowrap">
            <?php if (!$isCurrent): ?>
              <form method="post" action="<?= $e(url('/book-club/' . $slug . '/seasons/' . $seasonId . '/current')) ?>">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <button type="submit" class="px-2 py-1 text-xs bg-gray-100 hover:bg-gray-200 rounded-lg"><?= $e(__('Imposta come corrente')) ?></button>
              </form>
            <?php endif; ?>
            <?php if ($bookCount === 0): ?>
              <form method="post" action="<?= $e(url('/book-club/' . $slug . '/seasons/' . $seasonId . '/delete')) ?>"
                    onsubmit="return confirm('<?= $e(__('Eliminare questa stagione?')) ?>');">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <button type="submit" class="px-2 py-1 text-xs bg-red-50 hover:bg-red-100 text-red-700 rounded-lg" title="<?= $e(__('Puoi eliminare solo stagioni senza libri.')) ?>"><?= $e(__('Elimina')) ?></button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($canManage): ?>
        <details class="mt-2">
          <summary class="text-xs text-blue-600 cursor-pointer"><?= $e(__('Modifica')) ?></summary>
          <form method="post" action="<?= $e(url('/book-club/' . $slug . '/seasons/' . $seasonId . '/update')) ?>"
                class="mt-2 grid grid-cols-2 md:grid-cols-5 gap-2 text-sm items-end">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <input type="text" name="name" required maxlength="190" value="<?= $e($season['name']) ?>"
                   title="<?= $e(__('Nome')) ?>" class="border border-gray-300 rounded-lg px-2 py-1.5 col-span-2 md:col-span-1">
            <input type="date" name="starts_on" value="<?= $e($season['starts_on'] ?? '') ?>"
                   title="<?= $e(__('Inizio')) ?>" class="border border-gray-300 rounded-lg px-2 py-1.5">
            <input type="date" name="ends_on" value="<?= $e($season['ends_on'] ?? '') ?>"
                   title="<?= $e(__('Fine')) ?>" class="border border-gray-300 rounded-lg px-2 py-1.5">
            <input type="number" name="books_target" min="1" value="<?= $target !== null ? $target : '' ?>"
                   placeholder="<?= $e(__('Obiettivo libri')) ?>" title="<?= $e(__('Obiettivo libri')) ?>"
                   class="border border-gray-300 rounded-lg px-2 py-1.5">
            <button type="submit" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg"><?= $e(__('Salva')) ?></button>
          </form>
        </details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if ($canManage): ?>
    <details class="mt-4 border-t pt-4">
      <summary class="text-sm font-medium text-blue-600 cursor-pointer"><?= $e(__('Nuova stagione')) ?></summary>
      <form method="post" action="<?= $e(url('/book-club/' . $slug . '/seasons/new')) ?>" class="mt-3 space-y-3 text-sm">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <input type="text" name="name" required maxlength="190"
               placeholder="<?= $e(__('Nome della stagione (es. 2026 Primavera)')) ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2">
        <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
          <input type="date" name="starts_on" title="<?= $e(__('Inizio')) ?>" class="border border-gray-300 rounded-lg px-2 py-1.5">
          <input type="date" name="ends_on" title="<?= $e(__('Fine')) ?>" class="border border-gray-300 rounded-lg px-2 py-1.5">
          <input type="number" name="books_target" min="1" placeholder="<?= $e(__('Obiettivo libri')) ?>"
                 class="border border-gray-300 rounded-lg px-2 py-1.5">
        </div>
        <label class="flex items-center text-sm text-gray-700">
          <input type="checkbox" name="make_current" value="1" checked class="mr-2 rounded">
          <?= $e(__('Imposta come stagione corrente')) ?>
        </label>
        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg"><?= $e(__('Crea stagione')) ?></button>
      </form>
    </details>
  <?php endif; ?>

  <?php if ($canManage && !empty($assignBooks) && $seasons !== []): ?>
    <details class="mt-4 border-t pt-4">
      <summary class="text-sm font-medium text-blue-600 cursor-pointer"><?= $e(__('Assegna i libri alle stagioni')) ?></summary>
      <div class="mt-3 space-y-2">
        <?php foreach ($assignBooks as $assignBook): ?>
          <?php $currentSeasonId = $assignBook['season_id'] !== null ? (int) $assignBook['season_id'] : null; ?>
          <form method="post" action="<?= $e(url('/book-club/' . $slug . '/seasons/assign')) ?>"
                class="flex flex-wrap items-center gap-2 text-sm">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <input type="hidden" name="club_book_id" value="<?= (int) $assignBook['id'] ?>">
            <span class="flex-1 min-w-0 truncate text-gray-700">
              <i class="fas fa-book mr-1 text-gray-300"></i><?= $e($assignBook['titolo']) ?>
              <?php if (!empty($assignBook['autori'])): ?><span class="text-gray-400"> — <?= $e($assignBook['autori']) ?></span><?php endif; ?>
            </span>
            <select name="season_id" class="border border-gray-300 rounded-lg px-2 py-1.5 text-xs">
              <option value="" <?= $currentSeasonId === null ? 'selected' : '' ?>><?= $e(__('Nessuna stagione')) ?></option>
              <?php foreach ($seasons as $seasonOption): ?>
                <option value="<?= (int) $seasonOption['id'] ?>" <?= $currentSeasonId === (int) $seasonOption['id'] ? 'selected' : '' ?>><?= $e($seasonOption['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 rounded-lg"><?= $e(__('Assegna')) ?></button>
          </form>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endif; ?>

  <?php if ($archivedBySeason !== []): ?>
    <div class="mt-6 border-t pt-4">
      <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3"><?= $e(__('Storico letture')) ?></h3>
      <?php foreach ($archivedBySeason as $seasonLabel => $seasonBooks): ?>
        <div class="mb-4">
          <div class="text-xs font-semibold text-gray-400 uppercase mb-1"><?= $e($seasonLabel) ?> <span class="font-normal text-gray-300">(<?= count($seasonBooks) ?>)</span></div>
          <ul class="space-y-1">
            <?php foreach ($seasonBooks as $book): ?>
              <li class="text-sm text-gray-700">
                <i class="fas fa-book mr-1 text-gray-300"></i><?= $e($book['titolo']) ?>
                <?php if (!empty($book['autori'])): ?><span class="text-gray-400"> — <?= $e($book['autori']) ?></span><?php endif; ?>
                <?php if (!empty($book['reading_starts']) || !empty($book['reading_ends'])): ?>
                  <span class="text-xs text-gray-400 ml-1">
                    (<?= !empty($book['reading_starts']) ? $e(date('d/m/Y', (int) strtotime((string) $book['reading_starts']))) : '…' ?>
                    → <?= !empty($book['reading_ends']) ? $e(date('d/m/Y', (int) strtotime((string) $book['reading_ends']))) : '…' ?>)
                  </span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

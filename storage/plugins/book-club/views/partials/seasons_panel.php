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
<section class="bc-card">
  <div class="bc-section-header">
    <i class="fas fa-layer-group"></i>
    <h2><?= $e(__('Stagioni')) ?></h2>
  </div>

  <?php if ($seasons === []): ?>
    <p class="bc-muted mb-0"><?= $e(__('Nessuna stagione definita.')) ?></p>
  <?php endif; ?>

  <?php foreach ($seasons as $season): ?>
    <?php
      $seasonId = (int) $season['id'];
      $isCurrent = (int) $season['is_current'] === 1;
      $bookCount = (int) $season['book_count'];
      $target = $season['books_target'] !== null ? (int) $season['books_target'] : null;
    ?>
    <div class="border rounded-3 px-3 py-3 mb-3"<?= $isCurrent ? ' style="border-color: ' . $e($club['color']) . ' !important"' : '' ?>>
      <div class="d-flex align-items-start justify-content-between gap-3">
        <div>
          <div class="fw-semibold">
            <?= $e($season['name']) ?>
            <?php if ($isCurrent): ?>
              <span class="bc-badge bc-badge-open ms-2"><?= $e(__('Stagione corrente')) ?></span>
            <?php endif; ?>
          </div>
          <div class="bc-muted small mt-1">
            <i class="far fa-calendar me-1"></i><?= $e($fmtDate($season['starts_on'] !== null ? (string) $season['starts_on'] : null)) ?>
            → <?= $e($fmtDate($season['ends_on'] !== null ? (string) $season['ends_on'] : null)) ?>
            · <?= $e(sprintf(__('%d libri'), $bookCount)) ?>
            <?php if ($target !== null): ?>
              · <?= $e(sprintf(__('Obiettivo: %d libri'), $target)) ?>
            <?php endif; ?>
          </div>
          <?php if ($target !== null && $target > 0): ?>
            <div class="bc-progress mt-2" style="max-width: 12rem">
              <span style="width: <?= number_format(min(100, $bookCount / $target * 100), 1, '.', '') ?>%; background: <?= $e($club['color']) ?>"></span>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($canManage): ?>
          <div class="d-flex align-items-center gap-2 text-nowrap">
            <?php if (!$isCurrent): ?>
              <form method="post" action="<?= $e(url('/book-club/' . $slug . '/seasons/' . $seasonId . '/current')) ?>">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <button type="submit" class="bc-btn bc-btn-outline bc-btn-sm"><?= $e(__('Imposta come corrente')) ?></button>
              </form>
            <?php endif; ?>
            <?php if ($bookCount === 0): ?>
              <form method="post" action="<?= $e(url('/book-club/' . $slug . '/seasons/' . $seasonId . '/delete')) ?>"
                    onsubmit="return confirm('<?= $e(__('Eliminare questa stagione?')) ?>');">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm" title="<?= $e(__('Puoi eliminare solo stagioni senza libri.')) ?>"><?= $e(__('Elimina')) ?></button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($canManage): ?>
        <details class="mt-2">
          <summary class="small fw-semibold" style="cursor: pointer"><?= $e(__('Modifica')) ?></summary>
          <form method="post" action="<?= $e(url('/book-club/' . $slug . '/seasons/' . $seasonId . '/update')) ?>"
                class="mt-2 row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <div class="col-12 col-md-3">
              <input type="text" name="name" required maxlength="190" value="<?= $e($season['name']) ?>"
                     title="<?= $e(__('Nome')) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-2">
              <input type="date" name="starts_on" value="<?= $e($season['starts_on'] ?? '') ?>"
                     title="<?= $e(__('Inizio')) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-2">
              <input type="date" name="ends_on" value="<?= $e($season['ends_on'] ?? '') ?>"
                     title="<?= $e(__('Fine')) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-2">
              <input type="number" name="books_target" min="1" value="<?= $target !== null ? $target : '' ?>"
                     placeholder="<?= $e(__('Obiettivo libri')) ?>" title="<?= $e(__('Obiettivo libri')) ?>"
                     class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-3">
              <button type="submit" class="bc-btn bc-btn-sm w-100"><?= $e(__('Salva')) ?></button>
            </div>
          </form>
        </details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if ($canManage): ?>
    <details class="mt-4 pt-4 border-top">
      <summary class="fw-semibold" style="cursor: pointer"><?= $e(__('Nuova stagione')) ?></summary>
      <form method="post" action="<?= $e(url('/book-club/' . $slug . '/seasons/new')) ?>" class="mt-3">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <input type="text" name="name" required maxlength="190"
               placeholder="<?= $e(__('Nome della stagione (es. 2026 Primavera)')) ?>"
               class="form-control mb-3">
        <div class="row g-3 mb-3">
          <div class="col-6 col-md-4">
            <input type="date" name="starts_on" title="<?= $e(__('Inizio')) ?>" class="form-control">
          </div>
          <div class="col-6 col-md-4">
            <input type="date" name="ends_on" title="<?= $e(__('Fine')) ?>" class="form-control">
          </div>
          <div class="col-12 col-md-4">
            <input type="number" name="books_target" min="1" placeholder="<?= $e(__('Obiettivo libri')) ?>"
                   class="form-control">
          </div>
        </div>
        <div class="form-check mb-3">
          <input type="checkbox" name="make_current" value="1" checked class="form-check-input" id="bc-season-make-current">
          <label class="form-check-label" for="bc-season-make-current">
            <?= $e(__('Imposta come stagione corrente')) ?>
          </label>
        </div>
        <button type="submit" class="bc-btn"><?= $e(__('Crea stagione')) ?></button>
      </form>
    </details>
  <?php endif; ?>

  <?php if ($canManage && !empty($assignBooks) && $seasons !== []): ?>
    <details class="mt-4 pt-4 border-top">
      <summary class="fw-semibold" style="cursor: pointer"><?= $e(__('Assegna i libri alle stagioni')) ?></summary>
      <div class="mt-3 d-flex flex-column gap-2">
        <?php foreach ($assignBooks as $assignBook): ?>
          <?php $currentSeasonId = $assignBook['season_id'] !== null ? (int) $assignBook['season_id'] : null; ?>
          <form method="post" action="<?= $e(url('/book-club/' . $slug . '/seasons/assign')) ?>"
                class="d-flex flex-wrap align-items-center gap-2">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <input type="hidden" name="club_book_id" value="<?= (int) $assignBook['id'] ?>">
            <span class="flex-grow-1 overflow-hidden text-truncate">
              <i class="fas fa-book me-1 text-muted"></i><?= $e($assignBook['titolo']) ?>
              <?php if (!empty($assignBook['autori'])): ?><span class="bc-muted"> — <?= $e($assignBook['autori']) ?></span><?php endif; ?>
            </span>
            <select name="season_id" class="form-select form-select-sm w-auto">
              <option value="" <?= $currentSeasonId === null ? 'selected' : '' ?>><?= $e(__('Nessuna stagione')) ?></option>
              <?php foreach ($seasons as $seasonOption): ?>
                <option value="<?= (int) $seasonOption['id'] ?>" <?= $currentSeasonId === (int) $seasonOption['id'] ? 'selected' : '' ?>><?= $e($seasonOption['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="bc-btn bc-btn-outline bc-btn-sm"><?= $e(__('Assegna')) ?></button>
          </form>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endif; ?>

  <?php if ($archivedBySeason !== []): ?>
    <div class="mt-4 pt-4 border-top">
      <h3 class="small fw-semibold text-uppercase text-muted mb-3"><?= $e(__('Storico letture')) ?></h3>
      <?php foreach ($archivedBySeason as $seasonLabel => $seasonBooks): ?>
        <div class="mb-4">
          <div class="bc-muted small fw-semibold text-uppercase mb-1"><?= $e($seasonLabel) ?> <span class="fw-normal">(<?= count($seasonBooks) ?>)</span></div>
          <ul class="list-unstyled mb-0">
            <?php foreach ($seasonBooks as $book): ?>
              <li class="mb-1">
                <i class="fas fa-book me-1 text-muted"></i><?= $e($book['titolo']) ?>
                <?php if (!empty($book['autori'])): ?><span class="bc-muted"> — <?= $e($book['autori']) ?></span><?php endif; ?>
                <?php if (!empty($book['reading_starts']) || !empty($book['reading_ends'])): ?>
                  <span class="bc-muted small ms-1">
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

<?php
/**
 * Book Club — review bridge panel (plan §7.9): approved core reviews of the
 * club's finished books written by active members (with spoiler badge and
 * strengths/weaknesses from bookclub_review_meta), plus the submission form
 * for finished books the member has not reviewed yet. New reviews land in
 * the core moderation queue (recensioni stato 'pendente').
 *
 * @var array<string, mixed> $club
 * @var list<array<string, mixed>> $reviews          from LibraryRepo::clubReviews
 * @var list<array<string, mixed>> $reviewableBooks  finished books without a review by the current user
 * @var bool $isMember
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
?>
<section class="bc-card">
  <div class="bc-section-header">
    <i class="fas fa-star"></i>
    <h2><?= $e(__('Recensioni del club')) ?></h2>
  </div>

  <?php if ($reviews === []): ?>
    <p class="bc-muted mb-0"><?= $e(__('Nessuna recensione approvata per i libri conclusi dal club.')) ?></p>
  <?php endif; ?>

  <?php foreach ($reviews as $review): ?>
    <?php
      $stars = max(1, min(5, (int) $review['stelle']));
      $hasSpoiler = !empty($review['has_spoiler']);
      $reviewer = trim((string) $review['nome'] . ' ' . (string) $review['cognome']);
    ?>
    <div class="border-top py-3">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="d-flex flex-wrap align-items-center gap-2">
          <span class="text-warning small" aria-label="<?= $e(sprintf(__('%d stelle su 5'), $stars)) ?>">
            <?php for ($i = 1; $i <= 5; $i++): ?><i class="<?= $i <= $stars ? 'fas' : 'far' ?> fa-star"></i><?php endfor; ?>
          </span>
          <?php if (!empty($review['titolo'])): ?>
            <span class="fw-semibold"><?= $e($review['titolo']) ?></span>
          <?php endif; ?>
          <?php if ($hasSpoiler): ?>
            <span class="bc-badge bc-badge-warn"><i class="fas fa-eye-slash"></i><?= $e(__('Spoiler')) ?></span>
          <?php endif; ?>
        </div>
        <span class="bc-muted small">
          <?= $e($reviewer) ?>
          · <?= $e($review['libro_titolo']) ?>
          <?php if (!empty($review['data_recensione'])): ?>
            · <?= $e(date('d/m/Y', (int) strtotime((string) $review['data_recensione']))) ?>
          <?php endif; ?>
        </span>
      </div>

      <?php if (!empty($review['descrizione'])): ?>
        <?php if ($hasSpoiler): ?>
          <details class="mt-1 small">
            <summary class="small fw-semibold" style="cursor: pointer"><?= $e(__('Mostra la recensione (contiene spoiler)')) ?></summary>
            <p class="mt-1 mb-0" style="white-space: pre-line"><?= $e($review['descrizione']) ?></p>
          </details>
        <?php else: ?>
          <p class="small mt-1 mb-0" style="white-space: pre-line"><?= $e($review['descrizione']) ?></p>
        <?php endif; ?>
      <?php endif; ?>

      <?php if (!empty($review['strengths']) || !empty($review['weaknesses'])): ?>
        <div class="row g-2 mt-1">
          <?php if (!empty($review['strengths'])): ?>
            <div class="col-12 col-md-6">
              <div class="alert alert-success small py-2 px-3 mb-0 h-100">
                <span class="fw-semibold"><i class="fas fa-plus-circle me-1"></i><?= $e(__('Punti di forza')) ?>:</span>
                <span style="white-space: pre-line"><?= $e($review['strengths']) ?></span>
              </div>
            </div>
          <?php endif; ?>
          <?php if (!empty($review['weaknesses'])): ?>
            <div class="col-12 col-md-6">
              <div class="alert alert-danger small py-2 px-3 mb-0 h-100">
                <span class="fw-semibold"><i class="fas fa-minus-circle me-1"></i><?= $e(__('Punti deboli')) ?>:</span>
                <span style="white-space: pre-line"><?= $e($review['weaknesses']) ?></span>
              </div>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if ($isMember && $reviewableBooks !== []): ?>
    <details class="mt-4 pt-4 border-top">
      <summary class="fw-semibold" style="cursor: pointer"><?= $e(__('Scrivi una recensione')) ?></summary>
      <p class="bc-muted small mt-2"><?= $e(__('La recensione sarà pubblicata dopo l\'approvazione di un amministratore della biblioteca.')) ?></p>
      <form method="post" action="<?= $e(url('/book-club/' . $slug . '/reviews')) ?>" class="mt-3">
        <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
        <div class="row g-3 mb-3">
          <div class="col-12 col-md-6">
            <select name="libro_id" required class="form-select">
              <option value=""><?= $e(__('Scegli il libro concluso…')) ?></option>
              <?php foreach ($reviewableBooks as $book): ?>
                <option value="<?= (int) $book['libro_id'] ?>"><?= $e($book['titolo']) ?><?= !empty($book['autori']) ? ' — ' . $e($book['autori']) : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-6">
            <select name="stelle" required class="form-select">
              <option value=""><?= $e(__('Valutazione…')) ?></option>
              <?php for ($i = 5; $i >= 1; $i--): ?>
                <option value="<?= $i ?>"><?= $i ?> <?= $e(__n('stella', 'stelle', $i)) ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>
        <input type="text" name="titolo" maxlength="255" placeholder="<?= $e(__('Titolo della recensione (facoltativo)')) ?>"
               class="form-control mb-3">
        <textarea name="descrizione" rows="4" maxlength="2000" placeholder="<?= $e(__('La tua recensione…')) ?>"
                  class="form-control mb-3"></textarea>
        <div class="row g-3 mb-3">
          <div class="col-12 col-md-6">
            <textarea name="strengths" rows="2" maxlength="2000" placeholder="<?= $e(__('Punti di forza (facoltativo)')) ?>"
                      class="form-control"></textarea>
          </div>
          <div class="col-12 col-md-6">
            <textarea name="weaknesses" rows="2" maxlength="2000" placeholder="<?= $e(__('Punti deboli (facoltativo)')) ?>"
                      class="form-control"></textarea>
          </div>
        </div>
        <div class="form-check mb-3">
          <input type="checkbox" name="has_spoiler" value="1" class="form-check-input" id="bc-review-has-spoiler">
          <label class="form-check-label" for="bc-review-has-spoiler">
            <?= $e(__('La recensione contiene spoiler')) ?>
          </label>
        </div>
        <button type="submit" class="bc-btn"><?= $e(__('Invia recensione')) ?></button>
      </form>
    </details>
  <?php elseif ($isMember): ?>
    <p class="bc-muted small mt-4 pt-4 border-top mb-0"><?= $e(__('Hai già recensito tutti i libri conclusi dal club.')) ?></p>
  <?php endif; ?>
</section>

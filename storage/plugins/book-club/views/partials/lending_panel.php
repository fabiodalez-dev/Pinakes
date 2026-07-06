<?php
/**
 * Book Club — club-page panel of the lending module (Prestito tra membri,
 * plan §7.17): count of open member offers, the viewer's active loans with
 * their due dates and the link to the full lending page. Rendered for
 * members/managers only.
 *
 * @var array<string, mixed> $club
 * @var int $openCount                          offers with status 'offered'
 * @var list<array<string, mixed>> $activeLoans my active loans (both sides)
 * @var int $userId
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
?>
<section class="bc-card">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div class="bc-section-header mb-0">
      <i class="fas fa-hand-holding-heart"></i>
      <h2><?= $e(__('Prestito tra membri')) ?></h2>
    </div>
    <a class="bc-btn bc-btn-outline bc-btn-sm" href="<?= $e(url('/book-club/' . $slug . '/lending')) ?>">
      <?= $e(__('Vai ai prestiti tra membri')) ?> <i class="fas fa-arrow-right"></i>
    </a>
  </div>

  <p class="mb-0">
    <i class="fas fa-book-open me-1 text-muted"></i>
    <?= $e(sprintf(__n('%d copia offerta dai membri', '%d copie offerte dai membri', $openCount), $openCount)) ?>
  </p>

  <?php if ($activeLoans !== []): ?>
    <div class="mt-3">
      <div class="bc-muted fw-semibold mb-1"><?= $e(__('I miei prestiti attivi')) ?></div>
      <div>
        <?php foreach ($activeLoans as $loan): ?>
          <?php
            $iAmLender = (int) $loan['lender_id'] === $userId;
            $otherName = $iAmLender
                ? trim((string) ($loan['borrower_nome'] ?? '') . ' ' . (string) ($loan['borrower_cognome'] ?? ''))
                : trim((string) $loan['lender_nome'] . ' ' . (string) $loan['lender_cognome']);
          ?>
          <div class="bc-list-item align-items-center flex-wrap">
            <span class="overflow-hidden">
              <span class="fw-semibold"><?= $e($loan['titolo']) ?></span>
              <span class="bc-muted small">
                · <?= $e($iAmLender ? sprintf(__('Prestata a %s'), $otherName) : sprintf(__('Prestata da %s'), $otherName)) ?>
              </span>
            </span>
            <?php if (!empty($loan['due_on'])): ?>
              <span class="bc-muted small text-nowrap">
                <i class="far fa-calendar me-1"></i><?= $e(sprintf(__('Da restituire entro il %s'), date('d/m/Y', (int) strtotime((string) $loan['due_on'])))) ?>
              </span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</section>

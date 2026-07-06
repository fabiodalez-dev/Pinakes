<?php
/**
 * Book Club — club-page panel of the quotes module: the three most recent
 * club-visible quotes (italic, with book title + member name) and the link
 * to the full quotes & annotations page. Rendered for members/managers only.
 *
 * @var array<string, mixed> $club
 * @var list<array<string, mixed>> $quotes
 * @var bool $isMember
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
?>
<section class="bc-card">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div class="bc-section-header mb-0">
      <i class="fas fa-quote-left"></i>
      <h2><?= $e(__('Citazioni recenti')) ?></h2>
    </div>
    <a class="bc-btn bc-btn-outline bc-btn-sm" href="<?= $e(url('/book-club/' . $slug . '/quotes')) ?>">
      <?= $e(__('Tutte le citazioni')) ?> <i class="fas fa-arrow-right"></i>
    </a>
  </div>

  <?php if ($quotes === []): ?>
    <p class="bc-muted mb-0"><?= $e(__('Ancora nessuna citazione: aggiungi la prima!')) ?></p>
  <?php endif; ?>

  <div>
    <?php foreach ($quotes as $quote): ?>
      <?php $memberName = trim((string) $quote['member_nome'] . ' ' . (string) $quote['member_cognome']); ?>
      <div class="bc-list-item">
        <div class="flex-grow-1 overflow-hidden">
          <blockquote class="fst-italic ps-3 border-start border-3 mb-1" style="border-color: <?= $e($club['color']) ?> !important">
            “<?= $e(mb_strlen((string) $quote['quote']) > 220 ? mb_substr((string) $quote['quote'], 0, 220) . '…' : (string) $quote['quote']) ?>”
          </blockquote>
          <div class="bc-muted small mt-1">
            <span class="fw-semibold"><?= $e($quote['titolo']) ?></span>
            <?php if ($quote['page'] !== null): ?>
              · <?= $e(sprintf(__('pag. %d'), (int) $quote['page'])) ?>
            <?php endif; ?>
            · <i class="far fa-user me-1"></i><?= $e($memberName) ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

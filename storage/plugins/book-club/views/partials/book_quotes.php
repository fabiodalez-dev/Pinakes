<?php
/**
 * Book Club — public quotes section on the CORE book detail page
 * (app/Views/frontend/book-detail.php, hook book.frontend.details).
 * Compact list (max 5, newest first): quote text in italics, page number
 * when present, the member's first name and the club the quote comes from.
 * Bootstrap card markup to match the surrounding book-detail sections.
 *
 * @var list<array<string, mixed>> $quotes rows from QuoteRepo::publicQuotesForBook
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="card mb-4" id="book-club-quotes">
  <div class="card-header">
    <h6 class="mb-0"><i class="fas fa-quote-left me-2"></i><?= $e(__('Citazioni dai club di lettura')) ?></h6>
  </div>
  <div class="card-body">
    <?php foreach ($quotes as $i => $quote): ?>
      <div class="<?= $i < count($quotes) - 1 ? 'border-bottom pb-3 mb-3' : '' ?>">
        <blockquote class="fst-italic mb-1 ps-3 border-start border-3">
          &ldquo;<?= nl2br($e($quote['quote'])) ?>&rdquo;
        </blockquote>
        <div class="text-muted small">
          <?php if ($quote['page'] !== null): ?>
            <span class="me-2"><?= $e(sprintf(__('pag. %d'), (int) $quote['page'])) ?></span>
          <?php endif; ?>
          <i class="far fa-user me-1"></i><?= $e($quote['member_nome']) ?>
          <span class="mx-1">·</span>
          <i class="fas fa-book-reader me-1"></i><?= $e($quote['club_name']) ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

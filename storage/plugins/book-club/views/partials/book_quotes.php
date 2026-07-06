<?php
/**
 * Book Club — public quotes section on the CORE book detail page
 * (app/Views/frontend/book-detail.php, hook book.frontend.details).
 * Compact list (max 5, newest first): quote text in italics, page number
 * when present, the member's first name and the club the quote comes from.
 * Renders OUTSIDE the club page, so it carries its own tiny scoped
 * var()-based style block to match the frontend theme.
 *
 * @var list<array<string, mixed>> $quotes rows from QuoteRepo::publicQuotesForBook
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<style>
  #book-club-quotes{background:var(--white);border-radius:20px;box-shadow:var(--card-shadow);padding:clamp(1.5rem,3vw,2rem);margin-bottom:1.5rem}
  #book-club-quotes .bcq-header{display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem}
  #book-club-quotes .bcq-header i{color:var(--primary-color);font-size:1.15rem}
  #book-club-quotes .bcq-header h6{font-size:1.15rem;font-weight:700;letter-spacing:-.02em;margin:0;color:var(--text-color)}
  #book-club-quotes .bcq-item{padding:.9rem 0;border-top:1px solid var(--border-color)}
  #book-club-quotes .bcq-item:first-child{padding-top:0;border-top:none}
  #book-club-quotes .bcq-item:last-child{padding-bottom:0}
  #book-club-quotes blockquote{font-style:italic;color:var(--text-color);border-left:3px solid var(--primary-color);padding-left:.9rem;margin:0 0 .35rem}
  #book-club-quotes .bcq-meta{color:var(--text-light);font-size:.8rem}
  #book-club-quotes .bcq-meta i{color:var(--text-muted)}
</style>
<div id="book-club-quotes">
  <div class="bcq-header">
    <i class="fas fa-quote-left"></i>
    <h6><?= $e(__('Citazioni dai club di lettura')) ?></h6>
  </div>
  <div>
    <?php foreach ($quotes as $quote): ?>
      <div class="bcq-item">
        <blockquote>
          &ldquo;<?= nl2br($e($quote['quote'])) ?>&rdquo;
        </blockquote>
        <div class="bcq-meta">
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

<?php
/**
 * Book Club — voting2 sidebar block: link from the club page to the poll
 * list (where the advanced creation form lives for managers).
 *
 * @var array<string, mixed> $club
 * @var int $openCount number of open polls
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
?>
<section class="bc-card">
  <div class="bc-section-header">
    <i class="fas fa-vote-yea"></i>
    <h2><?= $e(__('Votazioni avanzate')) ?></h2>
  </div>
  <p class="bc-muted mb-3">
    <?php if ($openCount > 0): ?>
      <?= $e(sprintf(__n('%d votazione aperta', '%d votazioni aperte', $openCount), $openCount)) ?>
    <?php else: ?>
      <?= $e(__('Nessuna votazione aperta.')) ?>
    <?php endif; ?>
  </p>
  <a href="<?= $e(url('/book-club/' . $slug . '/polls')) ?>" class="bc-btn w-100">
    <?= $e(__('Tutte le votazioni')) ?>
  </a>
</section>

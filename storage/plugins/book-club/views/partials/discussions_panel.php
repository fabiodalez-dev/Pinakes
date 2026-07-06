<?php
/**
 * Book Club — club-page panel of the discussions module: last five threads
 * by activity, link to the full list and a quick new-thread form.
 *
 * @var array<string, mixed> $club
 * @var list<array<string, mixed>> $threads
 * @var bool $isMember
 * @var bool $canManage
 * @var string $csrf
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
?>
<section class="bc-card">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div class="bc-section-header mb-0">
      <i class="fas fa-comments"></i>
      <h2><?= $e(__('Discussioni')) ?></h2>
    </div>
    <a class="bc-btn bc-btn-outline bc-btn-sm" href="<?= $e(url('/book-club/' . $slug . '/discussions')) ?>">
      <?= $e(__('Tutte le discussioni')) ?> <i class="fas fa-arrow-right"></i>
    </a>
  </div>

  <?php if ($threads === []): ?>
    <p class="bc-muted mb-0"><?= $e(__('Ancora nessuna discussione: apri la prima!')) ?></p>
  <?php endif; ?>

  <div>
    <?php foreach ($threads as $thread): ?>
      <div class="bc-list-item align-items-center">
        <div class="d-flex align-items-center gap-2 overflow-hidden">
          <?php if ((int) $thread['is_pinned'] === 1): ?>
            <i class="fas fa-thumbtack text-warning small" title="<?= $e(__('In evidenza')) ?>"></i>
          <?php endif; ?>
          <?php if ((int) $thread['is_locked'] === 1): ?>
            <i class="fas fa-lock text-muted small" title="<?= $e(__('Bloccata')) ?>"></i>
          <?php endif; ?>
          <a class="fw-semibold text-truncate"
             href="<?= $e(url('/book-club/' . $slug . '/discussions/' . (int) $thread['id'])) ?>"><?= $e($thread['title']) ?></a>
        </div>
        <div class="bc-muted small text-nowrap ms-3">
          <?= (int) $thread['post_count'] ?> <?= $e(__n('messaggio', 'messaggi', (int) $thread['post_count'])) ?>
          · <?= $e(date('d/m/Y', (int) strtotime((string) $thread['last_activity']))) ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($isMember || $canManage): ?>
    <form method="post" action="<?= $e(url('/book-club/' . $slug . '/discussions/new')) ?>" class="d-flex align-items-center gap-2 mt-4 pt-4 border-top">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
      <input type="hidden" name="kind" value="free">
      <input type="text" name="title" required maxlength="190"
             placeholder="<?= $e(__('Apri una nuova discussione…')) ?>"
             class="form-control flex-grow-1">
      <button type="submit" class="bc-btn"><?= $e(__('Apri')) ?></button>
    </form>
  <?php endif; ?>
</section>

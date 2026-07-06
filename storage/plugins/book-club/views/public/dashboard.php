<?php
/**
 * Book Club — personal multi-club dashboard (/my/book-clubs).
 *
 * @var list<array{club: array<string, mixed>, snapshot: array{current_books: list<array<string, mixed>>, next_meeting: array<string, mixed>|null, open_polls: list<array<string, mixed>>}}> $cards
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<style>
  .bc-card{background:var(--white);border-radius:20px;box-shadow:var(--card-shadow);padding:clamp(1.5rem,3vw,2rem);margin-bottom:1.5rem}
  .bc-section-header{display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem}
  .bc-section-header i{color:var(--primary-color);font-size:1.15rem}
  .bc-section-header h2,.bc-section-header h1{font-size:1.35rem;font-weight:700;letter-spacing:-.02em;margin:0;color:var(--text-color)}
  .bc-btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;padding:.55rem 1.4rem;border-radius:999px;border:1.5px solid var(--button-color);background:var(--button-color);color:var(--button-text-color);font-weight:600;font-size:.9rem;cursor:pointer;text-decoration:none;transition:all .2s ease;white-space:nowrap}
  .bc-btn:hover{background:var(--button-hover);border-color:var(--button-hover);color:var(--button-text-color);transform:translateY(-1px)}
  .bc-btn-outline{background:transparent;color:var(--text-color);border:1px solid var(--border-color)}
  .bc-btn-outline:hover{border-color:var(--primary-color);color:var(--primary-color);background:transparent;transform:translateY(-1px)}
  .bc-btn-danger{background:transparent;border:1px solid var(--danger-color);color:var(--danger-color)}
  .bc-btn-danger:hover{background:var(--danger-color);border-color:var(--danger-color);color:#fff}
  .bc-btn-sm{padding:.3rem .9rem;font-size:.8rem}
  .bc-badge{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .75rem;border-radius:999px;font-size:.75rem;font-weight:600}
  .bc-badge-open{background:rgba(16,185,129,.12);color:var(--success-color)}
  .bc-badge-closed{background:var(--accent-color);color:var(--text-light)}
  .bc-badge-warn{background:rgba(245,158,11,.14);color:#92400e}
  .bc-muted{color:var(--text-light);font-size:.85rem}
  .bc-hero{background:var(--primary-color);color:#fff;border-radius:22px;padding:clamp(1.75rem,4vw,2.5rem);margin-bottom:2rem}
  .bc-hero h1{font-size:clamp(1.8rem,4vw,2.5rem);font-weight:800;letter-spacing:-.03em;margin:0 0 .5rem;color:#fff}
  .bc-hero p{opacity:.9;margin:0}
  .bc-progress{height:8px;background:var(--accent-color);border-radius:999px;overflow:hidden}
  .bc-progress>span{display:block;height:100%;border-radius:999px;background:var(--primary-color)}
  .bc-list-item{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;padding:.9rem 0;border-top:1px solid var(--border-color)}
  .bc-list-item:first-child{border-top:none}
  .bc-cover{width:44px;height:64px;object-fit:cover;border-radius:8px;box-shadow:var(--card-shadow)}
  .bc-chip{display:inline-block;width:.8rem;height:.8rem;border-radius:50%;flex:none}
</style>
<style>
  /* Page-local helpers (my-clubs dashboard only). */
  .bc-club-accent{position:absolute;top:0;left:0;right:0;height:6px}
  .bc-link{color:var(--primary-color);font-weight:600;text-decoration:none}
  .bc-link:hover{text-decoration:underline}
  .bc-kicker{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:.5rem}
  .bc-empty{text-align:center;padding:4rem 1rem;color:var(--text-light)}
  .bc-empty i{display:block;font-size:2.5rem;margin-bottom:1rem}
</style>
<div class="container py-4">
  <div class="bc-hero">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
      <h1 class="mb-0"><?= $e(__('I miei club di lettura')) ?></h1>
      <a href="<?= $e(url('/book-club')) ?>" class="bc-btn"><i class="fas fa-compass"></i><?= $e(__('Esplora i club')) ?></a>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if (empty($cards)): ?>
    <div class="bc-empty">
      <i class="fas fa-book-open"></i>
      <p><?= $e(__('Non fai ancora parte di nessun club.')) ?></p>
      <a href="<?= $e(url('/book-club')) ?>" class="bc-btn mt-3"><?= $e(__('Esplora i club')) ?></a>
    </div>
  <?php endif; ?>

  <div>
    <?php foreach ($cards as $card): ?>
      <?php $club = $card['club']; $snap = $card['snapshot']; ?>
      <div class="bc-card position-relative overflow-hidden">
        <span class="bc-club-accent" style="background: <?= $e($club['color']) ?>"></span>
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
          <a href="<?= $e(url('/book-club/' . $club['slug'])) ?>" class="bc-link fs-5"><?= $e($club['name']) ?></a>
          <span class="bc-muted small"><?= $e($club['role_name'] ?? '') ?><?= ($club['member_status'] ?? '') === 'pending' ? ' · ' . $e(__('adesione in attesa di approvazione')) : '' ?></span>
        </div>
        <div class="row g-4">
          <div class="col-12 col-md-4">
            <div class="bc-kicker"><?= $e(__('Lettura corrente')) ?></div>
            <?php if (empty($snap['current_books'])): ?>
              <p class="bc-muted mb-0"><?= $e(__('Nessun libro in lettura.')) ?></p>
            <?php endif; ?>
            <?php foreach ($snap['current_books'] as $book): ?>
              <div class="mb-1">
                <span class="fw-semibold"><?= $e($book['titolo']) ?></span>
                <?php if (!empty($book['reading_ends'])): ?>
                  <span class="bc-muted small ms-1"><?= $e(__('fino al')) ?> <?= $e(date('d/m/Y', (int) strtotime((string) $book['reading_ends']))) ?></span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="col-12 col-md-4">
            <div class="bc-kicker"><?= $e(__('Prossimo incontro')) ?></div>
            <?php if ($snap['next_meeting'] !== null): ?>
              <div class="fw-semibold"><?= $e($snap['next_meeting']['title']) ?></div>
              <div class="bc-muted small"><?= $e(date('d/m/Y H:i', (int) strtotime((string) $snap['next_meeting']['starts_at']))) ?></div>
            <?php else: ?>
              <p class="bc-muted mb-0"><?= $e(__('Nessun incontro in programma.')) ?></p>
            <?php endif; ?>
          </div>
          <div class="col-12 col-md-4">
            <div class="bc-kicker"><?= $e(__('Votazioni aperte')) ?></div>
            <?php if (empty($snap['open_polls'])): ?>
              <p class="bc-muted mb-0"><?= $e(__('Nessuna votazione aperta.')) ?></p>
            <?php endif; ?>
            <?php foreach ($snap['open_polls'] as $poll): ?>
              <div class="mb-1">
                <a class="bc-link" href="<?= $e(url('/book-club/' . $club['slug'] . '/polls/' . (int) $poll['id'])) ?>"><?= $e($poll['title']) ?></a>
                <?php if (!empty($poll['closes_at'])): ?>
                  <span class="bc-muted small ms-1"><?= $e(__('scade il')) ?> <?= $e(date('d/m/Y H:i', (int) strtotime((string) $poll['closes_at']))) ?></span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

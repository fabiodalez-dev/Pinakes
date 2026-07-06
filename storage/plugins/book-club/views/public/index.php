<?php
/**
 * Book Club — public directory of clubs.
 *
 * @var list<array<string, mixed>> $clubs
 * @var array<int, string> $mine  club_id → member_status for the logged-in user
 * @var bool $loggedIn
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$privacyLabels = [
    'public' => __('Pubblico'),
    'private' => __('Privato'),
    'invite' => __('Su invito'),
];
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
  /* Page-local helpers (directory page only). */
  .bc-club-card{display:block;position:relative;overflow:hidden;height:100%;color:inherit;text-decoration:none;transition:transform .2s ease,box-shadow .2s ease}
  .bc-club-card:hover{transform:translateY(-4px);box-shadow:var(--card-shadow-hover);color:inherit}
  .bc-club-card h2{font-size:1.1rem;font-weight:700;letter-spacing:-.02em;margin:0;color:var(--text-color)}
  .bc-club-accent{position:absolute;top:0;left:0;right:0;height:6px}
  .bc-empty{text-align:center;padding:4rem 1rem;color:var(--text-light)}
  .bc-empty i{display:block;font-size:2.5rem;margin-bottom:1rem}
</style>
<div class="container py-4">
  <div class="bc-hero">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div>
        <h1><?= $e(__('Club di lettura')) ?></h1>
        <p><?= $e(__('Leggi insieme: proposte, votazioni e incontri.')) ?></p>
      </div>
      <?php if ($loggedIn): ?>
        <a href="<?= $e(url('/my/book-clubs')) ?>" class="bc-btn">
          <i class="fas fa-book-reader"></i><?= $e(__('I miei club')) ?>
        </a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if (empty($clubs)): ?>
    <div class="bc-empty">
      <i class="fas fa-book-open"></i>
      <p class="mb-0"><?= $e(__('Nessun club di lettura attivo al momento.')) ?></p>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <?php foreach ($clubs as $club): ?>
      <div class="col-12 col-md-6 col-lg-4">
        <a href="<?= $e(url('/book-club/' . $club['slug'])) ?>" class="bc-card bc-club-card mb-0">
          <span class="bc-club-accent" style="background: <?= $e($club['color']) ?>"></span>
          <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
            <h2><?= $e($club['name']) ?></h2>
            <?php if (isset($mine[(int) $club['id']])): ?>
              <span class="bc-badge <?= $mine[(int) $club['id']] === 'active' ? 'bc-badge-open' : 'bc-badge-warn' ?>">
                <?= $mine[(int) $club['id']] === 'active' ? $e(__('Membro')) : $e(__('In attesa')) ?>
              </span>
            <?php endif; ?>
          </div>
          <p class="bc-muted"><?= $e(mb_substr((string) ($club['description'] ?? ''), 0, 180)) ?></p>
          <div class="d-flex align-items-center gap-3 bc-muted small mt-3">
            <span><i class="fas fa-users me-1"></i><?= (int) $club['member_count'] ?><?= $club['max_members'] !== null ? '/' . (int) $club['max_members'] : '' ?></span>
            <span><i class="fas fa-lock me-1"></i><?= $e($privacyLabels[$club['privacy']] ?? $club['privacy']) ?></span>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
</div>

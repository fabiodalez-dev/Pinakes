<?php
/**
 * Book Club — public club statistics page (members/managers): headline
 * tiles, books per workflow state, top proposers and (managers) the full
 * history export links.
 *
 * @var array<string, mixed> $club
 * @var list<array{key: string, label: string, color: string, count: int}> $stateRows
 * @var int $pendingCount
 * @var int $finished
 * @var int $meetingsDone
 * @var int $membersActive
 * @var float|null $avgStars
 * @var list<array{name: string, n: int}> $topProposers
 * @var bool $canManage
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$maxCount = 1;
foreach ($stateRows as $row) {
    $maxCount = max($maxCount, (int) $row['count']);
}
$maxProposer = 1;
foreach ($topProposers as $proposer) {
    $maxProposer = max($maxProposer, (int) $proposer['n']);
}
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
<div class="container py-4">
  <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="bc-muted text-decoration-none d-inline-flex align-items-center gap-2 mb-3">
    <i class="fas fa-arrow-left"></i><?= $e(__('Torna al club')) ?>
  </a>

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <h1 class="h3 fw-bold d-flex align-items-center mb-0">
      <span class="bc-chip me-2" style="background: <?= $e($club['color']) ?>"></span>
      <?= $e(__('Statistiche')) ?> — <?= $e($club['name']) ?>
    </h1>
    <?php if ($canManage): ?>
      <div class="d-flex align-items-center gap-2">
        <a href="<?= $e(url('/book-club/' . $slug . '/export.json')) ?>"
           class="bc-btn bc-btn-outline bc-btn-sm"><i class="fas fa-file-code"></i><?= $e(__('Esporta JSON')) ?></a>
        <a href="<?= $e(url('/book-club/' . $slug . '/export.csv')) ?>"
           class="bc-btn bc-btn-outline bc-btn-sm"><i class="fas fa-file-csv"></i><?= $e(__('Esporta CSV')) ?></a>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Headline tiles -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="bc-card text-center mb-0 h-100">
        <div class="fs-2 fw-bold"><?= (int) $finished ?></div>
        <div class="bc-muted small mt-1"><i class="fas fa-flag-checkered me-1"></i><?= $e(__('Libri conclusi')) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="bc-card text-center mb-0 h-100">
        <div class="fs-2 fw-bold"><?= (int) $meetingsDone ?></div>
        <div class="bc-muted small mt-1"><i class="fas fa-calendar-check me-1"></i><?= $e(__('Incontri svolti')) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="bc-card text-center mb-0 h-100">
        <div class="fs-2 fw-bold"><?= (int) $membersActive ?></div>
        <div class="bc-muted small mt-1"><i class="fas fa-users me-1"></i><?= $e(__('Membri attivi')) ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="bc-card text-center mb-0 h-100">
        <div class="fs-2 fw-bold">
          <?= $avgStars !== null ? $e(number_format($avgStars, 1)) . ' <i class="fas fa-star fs-5" style="color: var(--warning-color)"></i>' : '—' ?>
        </div>
        <div class="bc-muted small mt-1"><?= $e(__('Media stelle recensioni')) ?></div>
      </div>
    </div>
  </div>

  <!-- Books per workflow state -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-layer-group"></i>
      <h2><?= $e(__('Libri per stato')) ?></h2>
    </div>
    <?php foreach ($stateRows as $row): ?>
      <div class="row g-2 align-items-center mb-2">
        <div class="col-5 col-md-3 d-flex align-items-center small">
          <span class="bc-chip me-2" style="background: <?= $e($row['color']) ?>"></span>
          <span class="text-truncate"><?= $e($row['label']) ?></span>
        </div>
        <div class="col">
          <div class="bc-progress">
            <span style="width: <?= number_format((int) $row['count'] / $maxCount * 100, 1, '.', '') ?>%; background: <?= $e($row['color']) ?>"></span>
          </div>
        </div>
        <div class="col-auto small fw-semibold text-end"><?= (int) $row['count'] ?></div>
      </div>
    <?php endforeach; ?>
    <?php if ($canManage && $pendingCount > 0): ?>
      <p class="bc-muted small mt-3 mb-0"><?= $e(sprintf(__('%d proposte in attesa di moderazione'), (int) $pendingCount)) ?></p>
    <?php endif; ?>
  </section>

  <!-- Top proposers -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-medal"></i>
      <h2><?= $e(__('Top proponenti')) ?></h2>
    </div>
    <?php if ($topProposers === []): ?>
      <p class="bc-muted mb-0"><?= $e(__('Nessun libro proposto finora.')) ?></p>
    <?php endif; ?>
    <?php foreach ($topProposers as $proposer): ?>
      <div class="row g-2 align-items-center mb-2">
        <div class="col-5 col-md-3 small text-truncate"><?= $e($proposer['name']) ?></div>
        <div class="col">
          <div class="bc-progress">
            <span style="width: <?= number_format((int) $proposer['n'] / $maxProposer * 100, 1, '.', '') ?>%; background: <?= $e($club['color']) ?>"></span>
          </div>
        </div>
        <div class="col-auto bc-muted small text-end"><?= $e(sprintf(__('%d proposte'), (int) $proposer['n'])) ?></div>
      </div>
    <?php endforeach; ?>
  </section>
</div>

<?php
/**
 * Book Club — challenges module: per-year Reading Challenge page with a
 * year browser (?year=YYYY; past years are read-only, no create/delete
 * forms). Club-wide challenges (club total vs target + my contribution),
 * personal challenges (mine highlighted), creation forms (personal for
 * members, club-wide for managers) and deletion (own personal / managers).
 *
 * @var array<string, mixed> $club
 * @var int $year
 * @var list<int> $years                                years with data + current, DESC
 * @var bool $isCurrentYear                             past years render read-only
 * @var list<array<string, mixed>> $clubChallenges      user_id NULL rows
 * @var list<array<string, mixed>> $personalChallenges  user_id set rows
 * @var array<int, int> $mine                           challenge_id → my current
 * @var int|null $userId
 * @var bool $isMember
 * @var bool $canManage
 * @var int $memberCount
 * @var bool $readingReady
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
$base = url('/book-club/' . $slug . '/challenges');
$metricLabels = [
    'books' => __('Libri finiti'),
    'pages' => __('Pagine lette'),
    'authors' => __('Autori diversi'),
];
$pct = static fn(int $current, int $target): float => min(100.0, max(0.0, $current / max(1, $target) * 100));
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
  <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="bc-muted text-decoration-none">
    <i class="fas fa-arrow-left me-1"></i><?= $e(__('Torna al club')) ?>
  </a>

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mt-3 mb-2">
    <div class="bc-section-header mb-0">
      <span class="bc-chip" style="background: <?= $e($club['color']) ?>"></span>
      <h1><?= $e(__('Reading Challenge')) ?> <?= (int) $year ?> — <?= $e($club['name']) ?></h1>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2" aria-label="<?= $e(__('Anno')) ?>">
      <span class="bc-muted small me-1"><?= $e(__('Anno')) ?>:</span>
      <?php foreach ($years as $yearOption): ?>
        <a href="<?= $e($base . '?year=' . (int) $yearOption) ?>"
           class="bc-btn bc-btn-sm <?= (int) $yearOption === (int) $year ? '' : 'bc-btn-outline' ?>">
          <?= (int) $yearOption ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <p class="bc-muted mb-4">
    <?= $e(__('L\'avanzamento è ricalcolato automaticamente dal tracker di lettura: contano i libri del club segnati come finiti nell\'anno.')) ?>
  </p>

  <?php if (!$isCurrentYear): ?>
    <div class="mb-4 p-3 border rounded-3 bc-muted">
      <i class="fas fa-box-archive me-1"></i>
      <?= $e(sprintf(__('Stai consultando l\'archivio del %d: le sfide degli anni passati sono in sola lettura.'), (int) $year)) ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($flash)): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : ($flash['type'] === 'warning' ? 'alert-warning' : 'alert-danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if (!$readingReady): ?>
    <div class="alert alert-warning">
      <i class="fas fa-triangle-exclamation me-1"></i>
      <?= $e(__('Il modulo Lettura condivisa non è installato: l\'avanzamento delle sfide non può essere calcolato.')) ?>
    </div>
  <?php endif; ?>

  <!-- Club-wide challenges -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-users"></i>
      <h2><?= $e(__('Sfide del club')) ?></h2>
    </div>
    <?php if ($clubChallenges === []): ?>
      <p class="bc-muted mb-0"><?= $e(sprintf(__('Nessuna sfida di club per il %d.'), (int) $year)) ?></p>
    <?php endif; ?>
    <?php foreach ($clubChallenges as $challenge): ?>
      <?php
        $challengeId = (int) $challenge['id'];
        $target = max(1, (int) $challenge['target']);
        $total = (int) $challenge['total_current'];
        $myShare = (int) ($mine[$challengeId] ?? 0);
        $percent = $pct($total, $target);
      ?>
      <div class="border rounded-3 p-3 mb-3">
        <div class="d-flex align-items-start justify-content-between gap-3">
          <div style="min-width:0">
            <div class="fw-medium text-truncate"><?= $e($challenge['title']) ?></div>
            <div class="bc-muted small mt-1">
              <?= $e($metricLabels[(string) $challenge['metric']] ?? (string) $challenge['metric']) ?>
              · <?= $e(__('Obiettivo')) ?>: <?= (int) $challenge['target'] ?>
              · <?= $e(sprintf(__('%d partecipanti'), (int) $challenge['participant_count'])) ?>
            </div>
          </div>
          <?php if ($canManage && $isCurrentYear): ?>
            <form method="post" action="<?= $e($base . '/' . $challengeId . '/delete') ?>"
                  onsubmit="return confirm('<?= $e(__('Eliminare questa sfida?')) ?>');">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm">
                <i class="fas fa-trash"></i><?= $e(__('Elimina')) ?>
              </button>
            </form>
          <?php endif; ?>
        </div>
        <div class="d-flex align-items-center justify-content-between bc-muted small mt-2 mb-1">
          <span><?= $e(__('Avanzamento del club')) ?></span>
          <span class="fw-medium"><?= $total ?> / <?= $target ?></span>
        </div>
        <div class="bc-progress">
          <span style="width: <?= number_format($percent, 1, '.', '') ?>%; background: <?= $e($club['color']) ?>"></span>
        </div>
        <div class="d-flex align-items-center justify-content-between mt-1 bc-muted small">
          <?php if ($isMember): ?>
            <span><?= $e(__('Il mio contributo')) ?>: <span class="fw-medium"><?= $myShare ?></span></span>
          <?php else: ?>
            <span></span>
          <?php endif; ?>
          <?php if ($total >= $target): ?>
            <span class="fw-medium" style="color:var(--success-color)"><i class="fas fa-flag-checkered me-1"></i><?= $e(__('Sfida completata!')) ?></span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if ($canManage && $isCurrentYear): ?>
      <form method="post" action="<?= $e($base) ?>" class="row g-3 align-items-end mt-1 border-top pt-3">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="scope" value="club">
        <div class="col-12 col-md-6">
          <label class="form-label small"><?= $e(__('Titolo')) ?></label>
          <input type="text" name="title" required maxlength="190" class="form-control">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small"><?= $e(__('Metrica')) ?></label>
          <select name="metric" class="form-select">
            <?php foreach ($metricLabels as $key => $label): ?>
              <option value="<?= $e($key) ?>"><?= $e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small"><?= $e(__('Obiettivo annuale')) ?></label>
          <input type="number" name="target" min="1" max="1000000" required class="form-control">
        </div>
        <div class="col-12">
          <button type="submit" class="bc-btn">
            <i class="fas fa-plus"></i><?= $e(__('Nuova sfida di club')) ?>
          </button>
        </div>
      </form>
    <?php endif; ?>
  </section>

  <!-- Personal challenges -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-bullseye"></i>
      <h2><?= $e(__('Sfide personali')) ?></h2>
    </div>
    <?php if ($personalChallenges === []): ?>
      <p class="bc-muted mb-0"><?= $e(sprintf(__('Nessuna sfida personale per il %d.'), (int) $year)) ?></p>
    <?php endif; ?>
    <?php foreach ($personalChallenges as $challenge): ?>
      <?php
        $challengeId = (int) $challenge['id'];
        $target = max(1, (int) $challenge['target']);
        $current = (int) $challenge['total_current']; // personal → the owner's snapshot
        $isOwn = $userId !== null && (int) $challenge['user_id'] === $userId;
        $percent = $pct($current, $target);
      ?>
      <div class="border rounded-3 p-3 mb-3" <?= $isOwn ? 'style="border-color:var(--primary-color) !important;border-width:2px"' : '' ?>>
        <div class="d-flex align-items-start justify-content-between gap-3">
          <div style="min-width:0">
            <div class="fw-medium text-truncate">
              <?= $e($challenge['title']) ?>
              <?php if ($isOwn): ?>
                <span class="bc-badge ms-1" style="background:var(--accent-color);color:var(--primary-color)"><?= $e(__('La tua sfida')) ?></span>
              <?php endif; ?>
            </div>
            <div class="bc-muted small mt-1">
              <?= $e($challenge['owner_name']) ?>
              · <?= $e($metricLabels[(string) $challenge['metric']] ?? (string) $challenge['metric']) ?>
              · <?= $e(__('Obiettivo')) ?>: <?= (int) $challenge['target'] ?>
            </div>
          </div>
          <?php if (($canManage || $isOwn) && $isCurrentYear): ?>
            <form method="post" action="<?= $e($base . '/' . $challengeId . '/delete') ?>"
                  onsubmit="return confirm('<?= $e(__('Eliminare questa sfida?')) ?>');">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm">
                <i class="fas fa-trash"></i><?= $e(__('Elimina')) ?>
              </button>
            </form>
          <?php endif; ?>
        </div>
        <div class="d-flex align-items-center justify-content-between bc-muted small mt-2 mb-1">
          <span><?= $e(__('Avanzamento')) ?></span>
          <span class="fw-medium"><?= $current ?> / <?= $target ?></span>
        </div>
        <div class="bc-progress">
          <span style="width: <?= number_format($percent, 1, '.', '') ?>%;<?= $isOwn ? '' : 'background:var(--text-muted)' ?>"></span>
        </div>
        <?php if ($current >= $target): ?>
          <div class="text-end mt-1 small fw-medium" style="color:var(--success-color)"><i class="fas fa-flag-checkered me-1"></i><?= $e(__('Sfida completata!')) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($isMember && $isCurrentYear): ?>
      <form method="post" action="<?= $e($base) ?>" class="row g-3 align-items-end mt-1 border-top pt-3">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="scope" value="personal">
        <div class="col-12 col-md-6">
          <label class="form-label small"><?= $e(__('Titolo')) ?></label>
          <input type="text" name="title" required maxlength="190" placeholder="<?= $e(__('Es. 12 libri in un anno')) ?>" class="form-control">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small"><?= $e(__('Metrica')) ?></label>
          <select name="metric" class="form-select">
            <?php foreach ($metricLabels as $key => $label): ?>
              <option value="<?= $e($key) ?>"><?= $e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small"><?= $e(__('Obiettivo annuale')) ?></label>
          <input type="number" name="target" min="1" max="1000000" required class="form-control">
        </div>
        <div class="col-12">
          <button type="submit" class="bc-btn">
            <i class="fas fa-plus"></i><?= $e(__('Nuova sfida personale')) ?>
          </button>
        </div>
      </form>
    <?php endif; ?>
  </section>
</div>

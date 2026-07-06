<?php
/**
 * Book Club — reading module: shared-reading page for one club book.
 * Sections + discussion dates, personal progress form, club aggregate,
 * manager-only inline section CRUD.
 *
 * @var array<string, mixed> $club
 * @var array<string, mixed> $book
 * @var array{key: string, label: string, color: string, flags: array<string, bool>}|null $state
 * @var bool $isMember
 * @var bool $canManage
 * @var bool $loggedIn
 * @var list<array<string, mixed>> $sections
 * @var array<int, int> $sectionPassed          section_id → members past it
 * @var array<string, mixed>|null $myProgress
 * @var array<string, mixed> $aggregate
 * @var int $memberCount
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$bookId = (int) $book['id'];
$csrf = \App\Support\Csrf::ensureToken();
$base = url('/book-club/' . $slug . '/reading/' . $bookId);
$unitLabels = [
    'chapter' => __('Capitolo'),
    'part' => __('Parte'),
    'pages' => __('Pagine'),
    'custom' => __('Personalizzata'),
];
$myPercent = $myProgress !== null ? (int) $myProgress['percent'] : 0;
$myFinished = $myProgress !== null && !empty($myProgress['finished_at']);
$mySectionId = $myProgress !== null && $myProgress['section_id'] !== null ? (int) $myProgress['section_id'] : null;
$avgPercent = max(0.0, min(100.0, (float) ($aggregate['avg_percent_all'] ?? $aggregate['avg_percent'] ?? 0)));
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
    <i class="fas fa-arrow-left me-1"></i><?= $e($club['name']) ?>
  </a>

  <!-- Book header -->
  <div class="bc-card mt-3" style="border-top: 6px solid <?= $e($club['color']) ?>">
    <div class="d-flex align-items-start gap-3">
      <?php if (!empty($book['copertina_url'])): ?>
        <img src="<?= $e($book['copertina_url']) ?>" alt="" class="bc-cover" loading="lazy">
      <?php endif; ?>
      <div>
        <h1 class="h3 fw-bold mb-1"><?= $e($book['titolo']) ?></h1>
        <?php if (!empty($book['autori'])): ?>
          <p class="bc-muted mb-0"><?= $e($book['autori']) ?></p>
        <?php endif; ?>
        <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
          <?php if ($state !== null): ?>
            <span class="bc-badge" style="background: <?= $e($state['color']) ?>; color: #fff"><?= $e($state['label']) ?></span>
          <?php endif; ?>
          <?php if (!empty($book['reading_starts']) || !empty($book['reading_ends'])): ?>
            <span class="bc-muted">
              <i class="far fa-calendar me-1"></i>
              <?= !empty($book['reading_starts']) ? $e(date('d/m/Y', (int) strtotime((string) $book['reading_starts']))) : '…' ?>
              →
              <?= !empty($book['reading_ends']) ? $e(date('d/m/Y', (int) strtotime((string) $book['reading_ends']))) : '…' ?>
            </span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Club aggregate -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-users"></i>
      <h2><?= $e(__('Il club sta leggendo')) ?></h2>
    </div>
    <div class="d-flex align-items-center justify-content-between mb-1">
      <span class="bc-muted"><?= $e(__('avanzamento medio del club')) ?></span>
      <span class="fw-semibold"><?= number_format($avgPercent, 0) ?>%</span>
    </div>
    <div class="bc-progress">
      <span style="width: <?= number_format($avgPercent, 1, '.', '') ?>%; background: <?= $e($club['color']) ?>"></span>
    </div>
    <p class="bc-muted mt-2 mb-0">
      <?= $e(sprintf(__('%1$d lettori su %2$d membri'), (int) ($aggregate['active_readers'] ?? 0), (int) ($aggregate['members'] ?? $memberCount))) ?>
      · <?= $e(sprintf(__('%1$d membri su %2$d hanno finito il libro'), (int) ($aggregate['finished'] ?? 0), (int) $memberCount)) ?>
    </p>
  </section>

  <!-- My progress -->
  <?php if ($isMember): ?>
    <section class="bc-card">
      <div class="bc-section-header">
        <i class="fas fa-book-reader"></i>
        <h2><?= $e(__('Il mio progresso')) ?></h2>
      </div>
      <?php if ($myFinished): ?>
        <p class="mb-3"><span class="bc-badge bc-badge-open"><i class="fas fa-check-circle"></i><?= $e(__('Hai finito questo libro!')) ?></span></p>
      <?php endif; ?>
      <div class="d-flex align-items-center justify-content-between mb-1">
        <span class="bc-muted"><?= $e(__('Dove sono arrivato')) ?></span>
        <span class="fw-semibold"><?= $myPercent ?>%</span>
      </div>
      <div class="bc-progress mb-4">
        <span style="width: <?= $myPercent ?>%"></span>
      </div>
      <form method="post" action="<?= $e($base . '/progress') ?>" class="row g-3 align-items-end">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <div class="col-12 col-sm-6 col-md-3">
          <label class="form-label small"><?= $e(__('Percentuale letta')) ?></label>
          <input type="number" name="percent" min="0" max="100" value="<?= $myPercent ?>" class="form-control">
        </div>
        <?php if ($sections !== []): ?>
          <div class="col-12 col-sm-6 col-md-3">
            <label class="form-label small"><?= $e(__('Ultima sezione completata')) ?></label>
            <select name="section_id" class="form-select">
              <option value=""><?= $e(__('Nessuna')) ?></option>
              <?php foreach ($sections as $section): ?>
                <option value="<?= (int) $section['id'] ?>" <?= $mySectionId === (int) $section['id'] ? 'selected' : '' ?>><?= $e($section['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
        <div class="col-12 col-sm-6 col-md-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="finished" value="1" id="bc-finished" <?= $myFinished ? 'checked' : '' ?>>
            <label class="form-check-label small" for="bc-finished"><?= $e(__('Ho finito il libro')) ?></label>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-md-3">
          <button type="submit" class="bc-btn w-100"><?= $e(__('Aggiorna progresso')) ?></button>
        </div>
      </form>
    </section>
  <?php elseif ($loggedIn): ?>
    <p class="bc-muted mb-4"><?= $e(__('Solo i membri attivi del club possono registrare il proprio progresso.')) ?></p>
  <?php endif; ?>

  <!-- Sections -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-list-ol"></i>
      <h2><?= $e(__('Sezioni del libro')) ?></h2>
    </div>

    <?php if ($sections === []): ?>
      <p class="bc-muted mb-0"><?= $e(__('Nessuna sezione definita per questo libro.')) ?></p>
    <?php endif; ?>

    <?php foreach ($sections as $section): ?>
      <?php
        $sid = (int) $section['id'];
        $passed = (int) ($sectionPassed[$sid] ?? 0);
        $range = '';
        if ($section['range_from'] !== null || $section['range_to'] !== null) {
            $range = ($section['range_from'] !== null ? (int) $section['range_from'] : '…')
                . '–' . ($section['range_to'] !== null ? (int) $section['range_to'] : '…');
        }
      ?>
      <div class="border rounded-3 px-3 py-3 mb-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div>
            <span class="fw-semibold"><?= $e($section['title']) ?></span>
            <span class="bc-muted ms-2">
              <?= $e($unitLabels[(string) $section['unit']] ?? (string) $section['unit']) ?><?= $range !== '' ? ' ' . $e($range) : '' ?>
            </span>
          </div>
          <div class="bc-muted text-end">
            <?php if (!empty($section['discuss_from'])): ?>
              <div><i class="far fa-comments me-1"></i><?= $e(__('Discussione dal')) ?> <?= $e(date('d/m/Y', (int) strtotime((string) $section['discuss_from']))) ?></div>
            <?php endif; ?>
            <div><i class="fas fa-user-check me-1"></i><?= $e(sprintf(__('%d membri l\'hanno superata'), $passed)) ?></div>
          </div>
        </div>
        <?php if ($canManage): ?>
          <div class="mt-3 pt-3 border-top d-flex flex-wrap align-items-end gap-2">
            <form method="post" action="<?= $e($base . '/sections/' . $sid . '/update') ?>" class="d-flex flex-wrap align-items-end gap-2">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <div>
                <label class="form-label small mb-1"><?= $e(__('Titolo')) ?></label>
                <input type="text" name="title" value="<?= $e($section['title']) ?>" maxlength="190" required
                       class="form-control form-control-sm">
              </div>
              <div>
                <label class="form-label small mb-1"><?= $e(__('Ordine')) ?></label>
                <input type="number" name="sort" value="<?= (int) $section['sort'] ?>"
                       class="form-control form-control-sm">
              </div>
              <div>
                <label class="form-label small mb-1"><?= $e(__('Discussione dal')) ?></label>
                <input type="date" name="discuss_from" value="<?= $e($section['discuss_from'] ?? '') ?>"
                       class="form-control form-control-sm">
              </div>
              <button type="submit" class="bc-btn bc-btn-outline bc-btn-sm"><?= $e(__('Salva')) ?></button>
            </form>
            <form method="post" action="<?= $e($base . '/sections/' . $sid . '/delete') ?>"
                  onsubmit="return confirm('<?= $e(__('Eliminare questa sezione?')) ?>');">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm"><?= $e(__('Elimina')) ?></button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($canManage): ?>
      <!-- Add section -->
      <form method="post" action="<?= $e($base . '/sections') ?>" class="mt-4 border-top pt-4 row g-3 align-items-end">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <div class="col-12 col-sm-6 col-lg-4">
          <label class="form-label small"><?= $e(__('Nuova sezione')) ?></label>
          <input type="text" name="title" maxlength="190" required placeholder="<?= $e(__('Es. Capitoli 1–5')) ?>"
                 class="form-control">
        </div>
        <div class="col-6 col-sm-3 col-lg-2">
          <label class="form-label small"><?= $e(__('Tipo')) ?></label>
          <select name="unit" class="form-select">
            <?php foreach ($unitLabels as $unitKey => $unitLabel): ?>
              <option value="<?= $e($unitKey) ?>"><?= $e($unitLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-sm-3 col-lg-2">
          <label class="form-label small"><?= $e(__('Da')) ?></label>
          <input type="number" name="range_from" min="0" class="form-control">
        </div>
        <div class="col-6 col-sm-3 col-lg-2">
          <label class="form-label small"><?= $e(__('A')) ?></label>
          <input type="number" name="range_to" min="0" class="form-control">
        </div>
        <div class="col-6 col-sm-3 col-lg-2">
          <label class="form-label small"><?= $e(__('Discussione dal')) ?></label>
          <input type="date" name="discuss_from" class="form-control">
        </div>
        <div class="col-12">
          <button type="submit" class="bc-btn">
            <i class="fas fa-plus"></i><?= $e(__('Aggiungi sezione')) ?>
          </button>
        </div>
      </form>
    <?php endif; ?>
  </section>
</div>

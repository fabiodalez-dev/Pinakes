<?php
/**
 * Book Club — surveys module: list page. Open surveys (answer/status),
 * closed surveys (results), manager-only drafts and the create-draft form.
 *
 * @var array<string, mixed> $club
 * @var array{open: list<array<string, mixed>>, draft: list<array<string, mixed>>, closed: list<array<string, mixed>>} $grouped
 * @var bool $isMember
 * @var bool $canManage
 * @var list<int> $answeredIds
 * @var list<array{id: int|string, titolo: string}> $books
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
$base = url('/book-club/' . $slug . '/surveys');
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

  <div class="bc-section-header mt-3 mb-4">
    <span class="bc-chip" style="background: <?= $e($club['color']) ?>"></span>
    <h1><?= $e(__('Questionari')) ?> — <?= $e($club['name']) ?></h1>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : ($flash['type'] === 'warning' ? 'alert-warning' : 'alert-danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Open surveys -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-clipboard-list"></i>
      <h2><?= $e(__('Questionari aperti')) ?></h2>
    </div>
    <?php if ($grouped['open'] === []): ?>
      <p class="bc-muted mb-0"><?= $e(__('Nessun questionario aperto al momento.')) ?></p>
    <?php endif; ?>
    <?php foreach ($grouped['open'] as $survey): ?>
      <?php
        $answered = in_array((int) $survey['id'], $answeredIds, true);
        $scheduled = \App\Plugins\BookClub\SurveyRepo::notYetOpen($survey);
      ?>
      <div class="bc-list-item flex-wrap align-items-center">
        <div>
          <a href="<?= $e($base . '/' . (int) $survey['id']) ?>" class="fw-medium text-decoration-none" style="color:var(--text-color)"><?= $e($survey['title']) ?></a>
          <?php if ($scheduled): ?>
            <span class="bc-badge bc-badge-closed ms-2"><i class="far fa-clock"></i><?= $e(__('Programmato')) ?></span>
          <?php endif; ?>
          <div class="bc-muted small mt-1 d-flex flex-wrap gap-2">
            <?php if (!empty($survey['book_title'])): ?>
              <span><i class="fas fa-book me-1"></i><?= $e($survey['book_title']) ?></span>
            <?php endif; ?>
            <?php if ((int) $survey['anonymous'] === 1): ?>
              <span><i class="fas fa-user-secret me-1"></i><?= $e(__('Anonimo')) ?></span>
            <?php endif; ?>
            <span><i class="fas fa-reply me-1"></i><?= $e(sprintf(__('%d risposte'), (int) $survey['answer_count'])) ?></span>
            <?php if ($scheduled): ?>
              <span><i class="far fa-clock me-1"></i><?= $e(__('Apre il')) ?> <?= $e(date('d/m/Y H:i', (int) strtotime((string) $survey['opens_at']))) ?></span>
            <?php endif; ?>
            <?php if (!empty($survey['closes_at'])): ?>
              <span><i class="far fa-clock me-1"></i><?= $e(__('Chiude il')) ?> <?= $e(date('d/m/Y H:i', (int) strtotime((string) $survey['closes_at']))) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div>
          <?php if ($answered): ?>
            <span class="bc-badge bc-badge-open"><i class="fas fa-check"></i><?= $e(__('Hai già risposto')) ?></span>
          <?php elseif ($isMember && !$scheduled): ?>
            <a href="<?= $e($base . '/' . (int) $survey['id']) ?>" class="bc-btn bc-btn-sm"><?= $e(__('Rispondi al questionario')) ?></a>
          <?php else: ?>
            <a href="<?= $e($base . '/' . (int) $survey['id']) ?>" class="bc-btn bc-btn-outline bc-btn-sm"><?= $e(__('Apri')) ?></a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </section>

  <!-- Drafts (managers) -->
  <?php if ($canManage && $grouped['draft'] !== []): ?>
    <section class="bc-card">
      <div class="bc-section-header">
        <i class="fas fa-pen-ruler"></i>
        <h2><?= $e(__('Bozze')) ?></h2>
      </div>
      <?php foreach ($grouped['draft'] as $survey): ?>
        <?php $draftBase = $base . '/' . (int) $survey['id']; ?>
        <div class="border rounded-3 p-3 mb-3" style="border-style:dashed !important">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
              <span class="fw-medium"><?= $e($survey['title']) ?></span>
              <span class="bc-badge bc-badge-warn ms-2"><?= $e(__('Bozza')) ?></span>
              <?php if (!empty($survey['book_title'])): ?>
                <div class="bc-muted small mt-1"><i class="fas fa-book me-1"></i><?= $e($survey['book_title']) ?></div>
              <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
              <a href="<?= $e($draftBase) ?>" class="bc-btn bc-btn-sm">
                <i class="fas fa-pen"></i><?= $e(__('Modifica le domande')) ?>
              </a>
              <form method="post" action="<?= $e($draftBase . '/delete') ?>"
                    onsubmit="return confirm('<?= $e(__('Eliminare questa bozza? Le domande andranno perse.')) ?>');">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm">
                  <i class="fas fa-trash"></i><?= $e(__('Elimina bozza')) ?>
                </button>
              </form>
            </div>
          </div>
          <details class="mt-3">
            <summary class="small" style="color:var(--primary-color);cursor:pointer"><i class="fas fa-sliders me-1"></i><?= $e(__('Modifica dettagli')) ?></summary>
            <form method="post" action="<?= $e($draftBase . '/update') ?>" class="row g-3 mt-1 border-top pt-3">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <div class="col-12">
                <label class="form-label small"><?= $e(__('Titolo')) ?> *</label>
                <input type="text" name="title" maxlength="190" required value="<?= $e($survey['title']) ?>" class="form-control">
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label small"><?= $e(__('Libro collegato (facoltativo)')) ?></label>
                <select name="club_book_id" class="form-select">
                  <option value=""><?= $e(__('Nessun libro (questionario del club)')) ?></option>
                  <?php foreach ($books as $book): ?>
                    <option value="<?= (int) $book['id'] ?>" <?= (int) $book['id'] === (int) ($survey['club_book_id'] ?? 0) ? 'selected' : '' ?>><?= $e($book['titolo']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12 col-md-6 d-flex align-items-end">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="anonymous" value="1" id="draft-anon-<?= (int) $survey['id'] ?>" <?= (int) ($survey['anonymous'] ?? 0) === 1 ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="draft-anon-<?= (int) $survey['id'] ?>">
                    <?= $e(__('Questionario anonimo (i nomi dei rispondenti non saranno mai mostrati né esportati)')) ?>
                  </label>
                </div>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label small"><?= $e(__('Apertura programmata (facoltativa)')) ?></label>
                <input type="datetime-local" name="opens_at" class="form-control"
                       value="<?= !empty($survey['opens_at']) ? $e(date('Y-m-d\TH:i', (int) strtotime((string) $survey['opens_at']))) : '' ?>">
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label small"><?= $e(__('Chiusura automatica (facoltativa)')) ?></label>
                <input type="datetime-local" name="closes_at" class="form-control"
                       value="<?= !empty($survey['closes_at']) ? $e(date('Y-m-d\TH:i', (int) strtotime((string) $survey['closes_at']))) : '' ?>">
              </div>
              <div class="col-12">
                <button type="submit" class="bc-btn">
                  <i class="fas fa-check"></i><?= $e(__('Salva modifiche')) ?>
                </button>
              </div>
            </form>
          </details>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <!-- Closed surveys -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-box-archive"></i>
      <h2><?= $e(__('Questionari chiusi')) ?></h2>
    </div>
    <?php if ($grouped['closed'] === []): ?>
      <p class="bc-muted mb-0"><?= $e(__('Nessun questionario chiuso.')) ?></p>
    <?php endif; ?>
    <?php foreach ($grouped['closed'] as $survey): ?>
      <div class="bc-list-item flex-wrap align-items-center">
        <div>
          <a href="<?= $e($base . '/' . (int) $survey['id']) ?>" class="fw-medium text-decoration-none" style="color:var(--text-color)"><?= $e($survey['title']) ?></a>
          <div class="bc-muted small mt-1">
            <?php if (!empty($survey['book_title'])): ?>
              <i class="fas fa-book me-1"></i><?= $e($survey['book_title']) ?> ·
            <?php endif; ?>
            <?= $e(sprintf(__('%d risposte'), (int) $survey['answer_count'])) ?>
          </div>
        </div>
        <a href="<?= $e($base . '/' . (int) $survey['id']) ?>" class="bc-btn bc-btn-outline bc-btn-sm">
          <i class="fas fa-chart-simple"></i><?= $e(__('Risultati')) ?>
        </a>
      </div>
    <?php endforeach; ?>
  </section>

  <!-- Create draft (managers) -->
  <?php if ($canManage): ?>
    <section class="bc-card">
      <div class="bc-section-header mb-1">
        <i class="fas fa-plus"></i>
        <h2><?= $e(__('Nuovo questionario')) ?></h2>
      </div>
      <p class="bc-muted small mb-3"><?= $e(__('Il questionario viene creato come bozza: potrai aggiungere le domande e pubblicarlo quando è pronto.')) ?></p>
      <form method="post" action="<?= $e($base . '/create') ?>" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <div class="col-12">
          <label class="form-label small"><?= $e(__('Titolo')) ?> *</label>
          <input type="text" name="title" maxlength="190" required placeholder="<?= $e(__('Es. Il finale ti ha convinto?')) ?>" class="form-control">
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label small"><?= $e(__('Libro collegato (facoltativo)')) ?></label>
          <select name="club_book_id" class="form-select">
            <option value=""><?= $e(__('Nessun libro (questionario del club)')) ?></option>
            <?php foreach ($books as $book): ?>
              <option value="<?= (int) $book['id'] ?>"><?= $e($book['titolo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label small"><?= $e(__('Apertura programmata (facoltativa)')) ?></label>
          <input type="datetime-local" name="opens_at" class="form-control">
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label small"><?= $e(__('Chiusura automatica (facoltativa)')) ?></label>
          <input type="datetime-local" name="closes_at" class="form-control">
        </div>
        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="anonymous" value="1" id="create-anon">
            <label class="form-check-label small" for="create-anon">
              <?= $e(__('Questionario anonimo (i nomi dei rispondenti non saranno mai mostrati né esportati)')) ?>
            </label>
          </div>
        </div>
        <div class="col-12">
          <button type="submit" class="bc-btn">
            <i class="fas fa-plus"></i><?= $e(__('Crea bozza')) ?>
          </button>
        </div>
      </form>
    </section>
  <?php endif; ?>
</div>

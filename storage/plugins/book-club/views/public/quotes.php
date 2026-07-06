<?php
/**
 * Book Club — quotes module: club page with two tabs.
 *  - Citazioni: club-visible + public quotes of the club's books, add form,
 *    owner-only visibility change / delete.
 *  - Le mie annotazioni: the member's own per-book notes (add/edit/delete,
 *    private or club-shared) plus club-shared notes of other members.
 *
 * @var array<string, mixed> $club
 * @var string $tab                                'quotes' | 'notes'
 * @var int $userId
 * @var bool $canManage
 * @var list<array<string, mixed>> $books          club books (form selects)
 * @var list<array<string, mixed>> $quotes         quotes visible to the viewer
 * @var list<array<string, mixed>> $myNotes
 * @var list<array<string, mixed>> $clubNotes      club-shared notes of others
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
$base = url('/book-club/' . $slug . '/quotes');
$quoteVisibilityLabels = [
    'private' => __('Privata'),
    'club' => __('Solo club'),
    'public' => __('Pubblica'),
];
$noteVisibilityLabels = [
    'private' => __('Privata'),
    'club' => __('Solo club'),
];
$visibilityBadge = static function (string $visibility) use ($e, $quoteVisibilityLabels): string {
    $classes = match ($visibility) {
        'private' => 'bc-badge bc-badge-closed',
        'public' => 'bc-badge bc-badge-open',
        default => 'bc-badge bc-badge-club',
    };
    $icon = match ($visibility) {
        'private' => 'fa-lock',
        'public' => 'fa-globe',
        default => 'fa-users',
    };
    return '<span class="' . $classes . '">'
        . '<i class="fas ' . $icon . '"></i>' . $e($quoteVisibilityLabels[$visibility] ?? $visibility) . '</span>';
};
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
  .bc-quote{border-left:3px solid var(--primary-color);padding-left:1rem;font-style:italic;color:var(--text-color);margin:0}
  .bc-badge-club{background:var(--accent-color);color:var(--primary-color)}
</style>
<div class="container py-4">
  <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="bc-muted text-decoration-none">
    <i class="fas fa-arrow-left me-1"></i><?= $e(__('Torna al club')) ?>
  </a>

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mt-3 mb-4">
    <div class="bc-section-header mb-0">
      <span class="bc-chip" style="background: <?= $e($club['color']) ?>"></span>
      <h1><?= $e(__('Citazioni e annotazioni')) ?> — <?= $e($club['name']) ?></h1>
    </div>
    <div class="d-flex align-items-center gap-2">
      <a href="<?= $e($base . '/export.md') ?>" class="bc-btn bc-btn-outline bc-btn-sm"><i class="fab fa-markdown"></i><?= $e(__('Esporta i miei dati (Markdown)')) ?></a>
      <a href="<?= $e($base . '/export.csv') ?>" class="bc-btn bc-btn-outline bc-btn-sm"><i class="fas fa-file-csv"></i><?= $e(__('Esporta i miei dati (CSV)')) ?></a>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : ($flash['type'] === 'warning' ? 'alert-warning' : 'alert-danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-4">
    <li class="nav-item">
      <a href="<?= $e($base) ?>" class="nav-link <?= $tab === 'quotes' ? 'active' : '' ?>">
        <i class="fas fa-quote-left me-1"></i><?= $e(__('Citazioni')) ?>
      </a>
    </li>
    <li class="nav-item">
      <a href="<?= $e($base . '?tab=notes') ?>" class="nav-link <?= $tab === 'notes' ? 'active' : '' ?>">
        <i class="fas fa-pen-fancy me-1"></i><?= $e(__('Le mie annotazioni')) ?>
      </a>
    </li>
  </ul>

  <?php if ($tab === 'quotes'): ?>

    <!-- Add quote -->
    <section class="bc-card">
      <div class="bc-section-header">
        <i class="fas fa-quote-left"></i>
        <h2><?= $e(__('Aggiungi una citazione')) ?></h2>
      </div>
      <?php if ($books === []): ?>
        <p class="bc-muted mb-0"><?= $e(__('Nessun libro nel club: aggiungi prima un libro per citarlo.')) ?></p>
      <?php else: ?>
        <form method="post" action="<?= $e($base) ?>" class="row g-3">
          <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
          <div class="col-12 col-md-7">
            <label class="form-label small"><?= $e(__('Libro')) ?></label>
            <select name="libro_id" required class="form-select">
              <?php foreach ($books as $book): ?>
                <option value="<?= (int) $book['libro_id'] ?>">
                  <?= $e($book['titolo']) ?><?= !empty($book['autori']) ? ' — ' . $e($book['autori']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label small"><?= $e(__('Pagina')) ?></label>
            <input type="number" name="page" min="0" class="form-control">
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label small"><?= $e(__('Visibilità')) ?></label>
            <select name="visibility" class="form-select">
              <?php foreach ($quoteVisibilityLabels as $vKey => $vLabel): ?>
                <option value="<?= $e($vKey) ?>" <?= $vKey === 'club' ? 'selected' : '' ?>><?= $e($vLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small"><?= $e(__('Citazione')) ?></label>
            <textarea name="quote" rows="3" required maxlength="5000"
                      placeholder="<?= $e(__('Il testo della citazione…')) ?>" class="form-control"></textarea>
          </div>
          <div class="col-12">
            <label class="form-label small"><?= $e(__('Nota personale (facoltativa)')) ?></label>
            <textarea name="note" rows="2" maxlength="2000" class="form-control"></textarea>
          </div>
          <div class="col-12">
            <button type="submit" class="bc-btn">
              <i class="fas fa-plus"></i><?= $e(__('Salva citazione')) ?>
            </button>
          </div>
        </form>
      <?php endif; ?>
    </section>

    <!-- Quotes list -->
    <section class="bc-card">
      <div class="bc-section-header">
        <i class="fas fa-book-open"></i>
        <h2><?= $e(__('Citazioni del club')) ?></h2>
      </div>
      <?php if ($quotes === []): ?>
        <p class="bc-muted mb-0"><?= $e(__('Ancora nessuna citazione: aggiungi la prima!')) ?></p>
      <?php endif; ?>
      <?php foreach ($quotes as $quote): ?>
        <?php
          $qid = (int) $quote['id'];
          $isOwner = (int) $quote['user_id'] === $userId;
          $memberName = trim((string) $quote['member_nome'] . ' ' . (string) $quote['member_cognome']);
        ?>
        <article class="bc-list-item d-block">
          <blockquote class="bc-quote" style="border-left-color: <?= $e($club['color']) ?>">
            “<?= nl2br($e($quote['quote'])) ?>”
          </blockquote>
          <div class="d-flex flex-wrap align-items-center gap-2 mt-2 bc-muted small">
            <span class="fw-medium"><i class="fas fa-book me-1"></i><?= $e($quote['titolo']) ?></span>
            <?php if (!empty($quote['autori'])): ?>
              <span><?= $e($quote['autori']) ?></span>
            <?php endif; ?>
            <?php if ($quote['page'] !== null): ?>
              <span><?= $e(sprintf(__('pag. %d'), (int) $quote['page'])) ?></span>
            <?php endif; ?>
            <span><i class="far fa-user me-1"></i><?= $e($memberName) ?></span>
            <span><?= $e(date('d/m/Y', (int) strtotime((string) $quote['created_at']))) ?></span>
            <?= $visibilityBadge((string) $quote['visibility']) ?>
          </div>
          <?php if ((string) ($quote['note'] ?? '') !== '' && ($isOwner || (string) $quote['visibility'] !== 'private')): ?>
            <p class="bc-muted mt-2 mb-0"><i class="far fa-sticky-note me-1"></i><?= nl2br($e($quote['note'])) ?></p>
          <?php endif; ?>
          <?php if ($isOwner || $canManage): ?>
            <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
              <?php if ($isOwner): ?>
                <form method="post" action="<?= $e($base . '/' . $qid . '/visibility') ?>" class="d-flex align-items-center gap-2">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <select name="visibility" class="form-select form-select-sm w-auto">
                    <?php foreach ($quoteVisibilityLabels as $vKey => $vLabel): ?>
                      <option value="<?= $e($vKey) ?>" <?= (string) $quote['visibility'] === $vKey ? 'selected' : '' ?>><?= $e($vLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="bc-btn bc-btn-outline bc-btn-sm"><?= $e(__('Cambia visibilità')) ?></button>
                </form>
              <?php endif; ?>
              <form method="post" action="<?= $e($base . '/' . $qid . '/delete') ?>"
                    onsubmit="return confirm('<?= $e(__('Eliminare questa citazione?')) ?>');">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm"><?= $e(__('Elimina')) ?></button>
              </form>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </section>

  <?php else: ?>

    <!-- Add note -->
    <section class="bc-card">
      <div class="bc-section-header">
        <i class="fas fa-pen-fancy"></i>
        <h2><?= $e(__('Nuova annotazione')) ?></h2>
      </div>
      <?php if ($books === []): ?>
        <p class="bc-muted mb-0"><?= $e(__('Nessun libro nel club: aggiungi prima un libro per annotarlo.')) ?></p>
      <?php else: ?>
        <form method="post" action="<?= $e($base . '/notes') ?>" class="row g-3">
          <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
          <div class="col-12 col-md-8">
            <label class="form-label small"><?= $e(__('Libro')) ?></label>
            <select name="club_book_id" required class="form-select">
              <?php foreach ($books as $book): ?>
                <option value="<?= (int) $book['id'] ?>">
                  <?= $e($book['titolo']) ?><?= !empty($book['autori']) ? ' — ' . $e($book['autori']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label small"><?= $e(__('Visibilità')) ?></label>
            <select name="visibility" class="form-select">
              <?php foreach ($noteVisibilityLabels as $vKey => $vLabel): ?>
                <option value="<?= $e($vKey) ?>"><?= $e($vLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small"><?= $e(__('Annotazione')) ?></label>
            <textarea name="body" rows="4" required maxlength="20000"
                      placeholder="<?= $e(__('Le tue riflessioni su questo libro…')) ?>" class="form-control"></textarea>
          </div>
          <div class="col-12">
            <button type="submit" class="bc-btn">
              <i class="fas fa-plus"></i><?= $e(__('Salva annotazione')) ?>
            </button>
          </div>
        </form>
      <?php endif; ?>
    </section>

    <!-- My notes -->
    <section class="bc-card">
      <div class="bc-section-header">
        <i class="fas fa-book-bookmark"></i>
        <h2><?= $e(__('Le mie annotazioni')) ?></h2>
      </div>
      <?php if ($myNotes === []): ?>
        <p class="bc-muted mb-0"><?= $e(__('Non hai ancora scritto annotazioni.')) ?></p>
      <?php endif; ?>
      <?php foreach ($myNotes as $note): ?>
        <?php $nid = (int) $note['id']; ?>
        <article class="border rounded-3 p-3 mb-3">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
            <span class="fw-medium small"><i class="fas fa-book me-1" style="color:var(--text-muted)"></i><?= $e($note['titolo']) ?></span>
            <span class="bc-muted small">
              <?= $e($noteVisibilityLabels[(string) $note['visibility']] ?? (string) $note['visibility']) ?>
              · <?= $e(date('d/m/Y', (int) strtotime((string) $note['created_at']))) ?>
            </span>
          </div>
          <form method="post" action="<?= $e($base . '/notes/' . $nid . '/update') ?>" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <div class="col-12">
              <textarea name="body" rows="3" required maxlength="20000" class="form-control"><?= $e($note['body']) ?></textarea>
            </div>
            <div class="col-12 col-md-4">
              <select name="visibility" class="form-select form-select-sm">
                <?php foreach ($noteVisibilityLabels as $vKey => $vLabel): ?>
                  <option value="<?= $e($vKey) ?>" <?= (string) $note['visibility'] === $vKey ? 'selected' : '' ?>><?= $e($vLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-8 d-flex align-items-center gap-2">
              <button type="submit" class="bc-btn bc-btn-outline bc-btn-sm"><?= $e(__('Salva')) ?></button>
            </div>
          </form>
          <form method="post" action="<?= $e($base . '/notes/' . $nid . '/delete') ?>" class="mt-2"
                onsubmit="return confirm('<?= $e(__('Eliminare questa annotazione?')) ?>');">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm"><?= $e(__('Elimina')) ?></button>
          </form>
        </article>
      <?php endforeach; ?>
    </section>

    <!-- Club-shared notes of other members -->
    <section class="bc-card">
      <div class="bc-section-header">
        <i class="fas fa-users"></i>
        <h2><?= $e(__('Annotazioni condivise dal club')) ?></h2>
      </div>
      <?php if ($clubNotes === []): ?>
        <p class="bc-muted mb-0"><?= $e(__('Nessun altro membro ha condiviso annotazioni.')) ?></p>
      <?php endif; ?>
      <?php foreach ($clubNotes as $note): ?>
        <?php $memberName = trim((string) $note['member_nome'] . ' ' . (string) $note['member_cognome']); ?>
        <article class="bc-list-item d-block">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
            <span class="fw-medium small"><i class="fas fa-book me-1" style="color:var(--text-muted)"></i><?= $e($note['titolo']) ?></span>
            <span class="bc-muted small">
              <i class="far fa-user me-1"></i><?= $e($memberName) ?>
              · <?= $e(date('d/m/Y', (int) strtotime((string) $note['created_at']))) ?>
            </span>
          </div>
          <p class="small mb-0" style="color:var(--text-light)"><?= nl2br($e($note['body'])) ?></p>
          <?php if ($canManage): ?>
            <form method="post" action="<?= $e($base . '/notes/' . (int) $note['id'] . '/delete') ?>" class="mt-2"
                  onsubmit="return confirm('<?= $e(__('Eliminare questa annotazione?')) ?>');">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm"><?= $e(__('Elimina')) ?></button>
            </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </section>

  <?php endif; ?>
</div>

<?php
/**
 * Book Club — discussions: thread list (pinned first, then by activity)
 * plus the new-thread form for members.
 *
 * @var array<string, mixed> $club
 * @var list<array<string, mixed>> $threads
 * @var list<array<string, mixed>> $books     club books (non-pending)
 * @var list<array<string, mixed>> $sections  reading sections ([] when the reading module is absent)
 * @var bool $isMember
 * @var bool $canManage
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
$kindLabels = [
    'general' => __('Generale'),
    'chapter' => __('Capitolo'),
    'character' => __('Personaggio'),
    'free' => __('Libera'),
    'announcement' => __('Annuncio'),
];
$kindBadges = [
    'general' => 'bc-badge bc-badge-closed',
    'chapter' => 'bc-badge bc-badge-closed',
    'character' => 'bc-badge bc-badge-closed',
    'free' => 'bc-badge bc-badge-closed',
    'announcement' => 'bc-badge bc-badge-warn',
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
<div class="container py-4">
  <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="bc-muted text-decoration-none">
    <i class="fas fa-arrow-left me-1"></i><?= $e($club['name']) ?>
  </a>

  <div class="d-flex align-items-center justify-content-between mt-3 mb-4">
    <h1 class="h2 fw-bold mb-0"><?= $e(__('Discussioni')) ?></h1>
    <span class="bc-muted"><?= count($threads) ?> <?= $e(__n('discussione', 'discussioni', count($threads))) ?></span>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if ($isMember || $canManage): ?>
    <!-- New thread -->
    <section class="bc-card">
      <div class="bc-section-header">
        <i class="fas fa-comment-medical"></i>
        <h2><?= $e(__('Apri una nuova discussione')) ?></h2>
      </div>
      <form method="post" action="<?= $e(url('/book-club/' . $slug . '/discussions/new')) ?>">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <input type="text" name="title" required maxlength="190"
               placeholder="<?= $e(__('Titolo della discussione')) ?>"
               class="form-control mb-3">
        <div class="row g-3">
          <div class="col-12 col-md-4">
            <select name="kind" class="form-select">
              <?php foreach ($kindLabels as $value => $label): ?>
                <?php if ($value === 'announcement' && !$canManage) { continue; } ?>
                <option value="<?= $e($value) ?>" <?= $value === 'free' ? 'selected' : '' ?>><?= $e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-4">
            <select name="club_book_id" class="form-select">
              <option value=""><?= $e(__('Nessun libro collegato')) ?></option>
              <?php foreach ($books as $book): ?>
                <option value="<?= (int) $book['id'] ?>"><?= $e($book['titolo']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($sections !== []): ?>
            <div class="col-12 col-md-4">
              <select name="section_id" class="form-select">
                <option value=""><?= $e(__('Nessuna sezione collegata')) ?></option>
                <?php foreach ($sections as $section): ?>
                  <option value="<?= (int) $section['id'] ?>"><?= $e($section['book_title'] . ' — ' . $section['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
        </div>
        <button type="submit" class="bc-btn mt-3"><?= $e(__('Apri discussione')) ?></button>
      </form>
    </section>
  <?php endif; ?>

  <!-- Thread list -->
  <section class="bc-card">
    <?php if ($threads === []): ?>
      <p class="bc-muted mb-0"><?= $e(__('Ancora nessuna discussione: apri la prima!')) ?></p>
    <?php endif; ?>
    <?php foreach ($threads as $thread): ?>
      <div class="bc-list-item">
        <div style="min-width: 0">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if ((int) $thread['is_pinned'] === 1): ?>
              <i class="fas fa-thumbtack small" style="color: var(--warning-color)" title="<?= $e(__('In evidenza')) ?>"></i>
            <?php endif; ?>
            <?php if ((int) $thread['is_locked'] === 1): ?>
              <i class="fas fa-lock small bc-muted" title="<?= $e(__('Bloccata')) ?>"></i>
            <?php endif; ?>
            <a class="fw-semibold text-decoration-none" style="color: var(--primary-color)"
               href="<?= $e(url('/book-club/' . $slug . '/discussions/' . (int) $thread['id'])) ?>"><?= $e($thread['title']) ?></a>
            <span class="<?= $e($kindBadges[$thread['kind']] ?? 'bc-badge bc-badge-closed') ?>">
              <?= $e($kindLabels[$thread['kind']] ?? $thread['kind']) ?>
            </span>
          </div>
          <div class="bc-muted mt-1">
            <?php if (!empty($thread['book_title'])): ?>
              <i class="fas fa-book me-1"></i><?= $e($thread['book_title']) ?>
              <?php if (!empty($thread['section_title'])): ?> · <?= $e($thread['section_title']) ?><?php endif; ?>
              ·
            <?php endif; ?>
            <?php if (!empty($thread['creator_nome'])): ?>
              <?= $e(__('aperta da')) ?> <?= $e(trim($thread['creator_nome'] . ' ' . $thread['creator_cognome'])) ?> ·
            <?php endif; ?>
            <?= $e(__('ultima attività')) ?> <?= $e(date('d/m/Y H:i', (int) strtotime((string) $thread['last_activity']))) ?>
          </div>
        </div>
        <div class="bc-muted text-end text-nowrap ms-3">
          <?= (int) $thread['post_count'] ?> <?= $e(__n('messaggio', 'messaggi', (int) $thread['post_count'])) ?>
        </div>
      </div>
    <?php endforeach; ?>
  </section>
</div>

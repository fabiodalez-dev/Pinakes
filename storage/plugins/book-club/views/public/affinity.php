<?php
/**
 * Book Club — affinity module page (members/managers): privacy opt-in
 * toggle, the member's ranked affinity list (opted-in members only) and
 * the club suggestions (unread catalog books by top finished genres +
 * authors to rediscover).
 *
 * @var array<string, mixed> $club
 * @var bool $isMember
 * @var bool $canManage
 * @var bool $optedIn
 * @var int $optedInCount
 * @var list<array{name: string, score: int|null, computed_at: string}> $myAffinities
 * @var list<array{id: int, nome: string, n: int}> $topGenres
 * @var list<array<string, mixed>> $suggestedBooks
 * @var list<array{id: int, nome: string, unread_count: int}> $similarAuthors
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
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

  <div class="bc-hero">
    <h1 class="d-flex align-items-center gap-3 mb-0">
      <span class="bc-chip" style="background: <?= $e($club['color']) ?>"></span>
      <span><?= $e(__('Affinità e suggerimenti')) ?> — <?= $e($club['name']) ?></span>
    </h1>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Section 1: reader affinity (opt-in) -->
  <section class="bc-card">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
      <div class="bc-section-header mb-0">
        <i class="fas fa-people-arrows"></i>
        <h2><?= $e(__('Affinità tra lettori')) ?></h2>
      </div>
      <span class="bc-muted"><?= $e(sprintf(__('Membri con condivisione attiva: %d'), (int) $optedInCount)) ?></span>
    </div>

    <p class="bc-muted mt-3 mb-3">
      <i class="fas fa-lock me-1"></i>
      <?= $e(__('Le affinità sono calcolate solo tra i membri che hanno attivato la condivisione: chi non aderisce non compare mai negli elenchi degli altri.')) ?>
    </p>

    <?php if ($isMember): ?>
      <form method="post" action="<?= $e(url('/book-club/' . $slug . '/affinity/optin')) ?>" class="d-flex flex-wrap align-items-center gap-3 mb-4 border rounded-3 px-3 py-3">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <span class="small fw-semibold" style="color: <?= $optedIn ? 'var(--success-color)' : 'var(--text-light)' ?>">
          <i class="fas <?= $optedIn ? 'fa-toggle-on' : 'fa-toggle-off' ?> me-1"></i>
          <?= $e($optedIn ? __('Condivisione attiva') : __('Condivisione disattivata')) ?>
        </span>
        <button type="submit" class="bc-btn bc-btn-sm<?= $optedIn ? ' bc-btn-outline' : '' ?>">
          <?= $e($optedIn ? __('Disattiva condivisione') : __('Attiva condivisione')) ?>
        </button>
      </form>
    <?php endif; ?>

    <?php if ($optedIn): ?>
      <?php if ($myAffinities === []): ?>
        <p class="bc-muted mb-0"><?= $e(__('Nessun altro membro ha attivato la condivisione, per ora.')) ?></p>
      <?php else: ?>
        <?php foreach ($myAffinities as $row): ?>
          <div class="row align-items-center g-2 mb-2">
            <div class="col-12 col-md-4 small text-truncate">
              <?= $e(sprintf(__('Affinità con %s'), (string) $row['name'])) ?>
            </div>
            <div class="col">
              <div class="bc-progress">
                <?php if ($row['score'] !== null): ?>
                  <span style="width: <?= (int) $row['score'] ?>%; background: <?= $e($club['color']) ?>"></span>
                <?php endif; ?>
              </div>
            </div>
            <div class="col-3 col-md-2 text-end small <?= $row['score'] !== null ? 'fw-semibold' : 'bc-muted' ?>">
              <?= $row['score'] !== null ? (int) $row['score'] . '%' : $e(__('dati insufficienti')) ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    <?php elseif ($isMember): ?>
      <p class="bc-muted mb-0"><?= $e(__('Attiva la condivisione per scoprire la tua affinità di lettura con gli altri membri.')) ?></p>
    <?php endif; ?>
  </section>

  <!-- Section 2: club suggestions -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-lightbulb"></i>
      <h2><?= $e(__('Suggerimenti per il club')) ?></h2>
    </div>

    <?php if ($topGenres === []): ?>
      <p class="bc-muted mb-0"><?= $e(__('Nessun suggerimento disponibile: il club non ha ancora concluso letture con un genere assegnato.')) ?></p>
    <?php else: ?>
      <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <span class="bc-muted text-uppercase"><?= $e(__('Generi più letti dal club')) ?>:</span>
        <?php foreach ($topGenres as $genre): ?>
          <span class="bc-badge" style="background: <?= $e($club['color']) ?>; color: #fff">
            <?= $e($genre['nome']) ?> · <?= (int) $genre['n'] ?>
          </span>
        <?php endforeach; ?>
      </div>

      <?php if ($suggestedBooks === []): ?>
        <p class="bc-muted mb-0"><?= $e(__('Il club ha già letto tutti i libri in catalogo per i suoi generi preferiti.')) ?></p>
      <?php else: ?>
        <h3 class="h6 fw-bold text-uppercase mb-2"><?= $e(__('Libri dal catalogo che il club non ha ancora letto')) ?></h3>
        <div class="mb-3">
          <?php foreach ($suggestedBooks as $book): ?>
            <div class="bc-list-item">
              <?php if (!empty($book['copertina_url'])): ?>
                <img src="<?= $e($book['copertina_url']) ?>" alt="" class="bc-cover flex-shrink-0" loading="lazy">
              <?php else: ?>
                <div class="bc-cover flex-shrink-0 bg-light d-flex align-items-center justify-content-center"><i class="fas fa-book bc-muted"></i></div>
              <?php endif; ?>
              <div class="flex-grow-1">
                <p class="mb-0 fw-semibold"><?= $e($book['titolo']) ?></p>
                <p class="mb-0 bc-muted">
                  <?= $e((string) ($book['autori'] ?? '')) ?>
                  <?php if (!empty($book['anno_pubblicazione'])): ?> · <?= (int) $book['anno_pubblicazione'] ?><?php endif; ?>
                  · <?= $e($book['genere']) ?>
                </p>
              </div>
              <div class="bc-muted text-nowrap flex-shrink-0 pt-1">
                <?php if ($book['rating'] !== null): ?>
                  <i class="fas fa-star" style="color: var(--warning-color)"></i> <?= (int) $book['rating'] ?>/5
                <?php else: ?>
                  —
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="bc-muted mb-0">
          <i class="fas fa-hand-point-right me-1"></i><?= $e(__('Ti piace un titolo? Proponilo al club dalla pagina principale.')) ?>
          <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="fw-semibold"><?= $e(__('Vai alla pagina del club')) ?></a>
        </p>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <!-- Authors to rediscover -->
  <?php if ($similarAuthors !== []): ?>
    <section class="bc-card">
      <div class="bc-section-header mb-1">
        <i class="fas fa-feather-alt"></i>
        <h2><?= $e(__('Autori da riscoprire')) ?></h2>
      </div>
      <p class="bc-muted mb-3"><?= $e(__('Autori dei libri conclusi con altri titoli in catalogo non ancora letti dal club.')) ?></p>
      <div>
        <?php foreach ($similarAuthors as $author): ?>
          <div class="bc-list-item align-items-center">
            <span class="small text-truncate"><?= $e($author['nome']) ?></span>
            <span class="bc-muted text-nowrap flex-shrink-0">
              <?= $e(sprintf(__('%d libri non ancora letti dal club'), (int) $author['unread_count'])) ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div>

<?php
/**
 * Book Club — AI module page (club managers only): generate 5 discussion
 * questions for a club book or a structured summary of a meeting's minutes,
 * with copy-ready output and the history of previous generations.
 * When no API key is configured the page only explains how to enable the
 * module (link to the admin settings for Pinakes admins).
 *
 * @var array<string, mixed> $club
 * @var bool $configured
 * @var bool $isPinakesAdmin
 * @var string $model
 * @var list<array<string, mixed>> $books
 * @var list<array<string, mixed>> $meetings
 * @var list<array<string, mixed>> $outputs
 * @var int $recentCount
 * @var int $dailyCap
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
$capReached = $recentCount >= $dailyCap;
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
    <h1 class="d-flex align-items-center gap-3<?= $configured ? '' : ' mb-0' ?>">
      <span class="bc-chip" style="background: <?= $e($club['color']) ?>"></span>
      <span><?= $e(__('Assistente IA')) ?> — <?= $e($club['name']) ?></span>
    </h1>
    <?php if ($configured): ?>
      <p>
        <i class="fas fa-microchip me-1"></i><?= $e($model) ?>
        · <?= $e(sprintf(__('%1$d/%2$d generazioni nelle ultime 24 ore'), (int) $recentCount, (int) $dailyCap)) ?>
      </p>
    <?php endif; ?>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if (!$configured): ?>
    <section class="bc-card">
      <div class="bc-section-header">
        <i class="fas fa-wand-magic-sparkles"></i>
        <h2><?= $e(__('Modulo IA non configurato')) ?></h2>
      </div>
      <p class="bc-muted mb-0">
        <?= $e(__('Per usare l\'assistente IA (domande di discussione e riassunti dei verbali) l\'amministratore di Pinakes deve configurare una chiave API nelle impostazioni del plugin.')) ?>
      </p>
      <?php if ($isPinakesAdmin): ?>
        <a href="<?= $e(url('/admin/book-club/ai')) ?>" class="bc-btn mt-4">
          <i class="fas fa-cog"></i><?= $e(__('Apri le impostazioni IA')) ?>
        </a>
      <?php endif; ?>
    </section>
  <?php else: ?>

    <?php if ($capReached): ?>
      <div class="alert alert-warning">
        <i class="fas fa-hand me-1"></i><?= $e(sprintf(__('Limite di sicurezza raggiunto: massimo %d generazioni IA per club nelle ultime 24 ore. Riprova più tardi.'), (int) $dailyCap)) ?>
      </div>
    <?php endif; ?>

    <div class="row g-4 mb-2">
      <!-- Discussion questions -->
      <div class="col-12 col-md-6">
        <section class="bc-card h-100">
          <div class="bc-section-header mb-2">
            <i class="fas fa-comments"></i>
            <h2><?= $e(__('Domande di discussione')) ?></h2>
          </div>
          <p class="bc-muted mb-3"><?= $e(__('Genera 5 domande aperte per l\'incontro, a partire da titolo, autori e descrizione del libro nel catalogo.')) ?></p>
          <?php if ($books === []): ?>
            <p class="bc-muted mb-0"><?= $e(__('Nessun libro nel club: proponi prima un libro.')) ?></p>
          <?php else: ?>
            <form method="post" action="<?= $e(url('/book-club/' . $slug . '/ai/questions')) ?>">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <label class="form-label small fw-semibold" for="ai-book"><?= $e(__('Libro del club')) ?></label>
              <select id="ai-book" name="club_book_id" required class="form-select mb-3">
                <?php foreach ($books as $book): ?>
                  <option value="<?= (int) $book['id'] ?>">
                    <?= $e($book['titolo']) ?><?= (string) ($book['autori'] ?? '') !== '' ? ' — ' . $e($book['autori']) : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <button type="submit" <?= $capReached ? 'disabled' : '' ?> class="bc-btn w-100<?= $capReached ? ' bc-btn-outline' : '' ?>">
                <i class="fas fa-wand-magic-sparkles"></i><?= $e(__('Genera 5 domande')) ?>
              </button>
            </form>
          <?php endif; ?>
        </section>
      </div>

      <!-- Meeting minutes summary -->
      <div class="col-12 col-md-6">
        <section class="bc-card h-100">
          <div class="bc-section-header mb-2">
            <i class="fas fa-file-lines"></i>
            <h2><?= $e(__('Riassunto del verbale')) ?></h2>
          </div>
          <p class="bc-muted mb-3"><?= $e(__('Genera un riassunto strutturato (sintesi, decisioni prese, prossimi passi) dal verbale di un incontro.')) ?></p>
          <?php if ($meetings === []): ?>
            <p class="bc-muted mb-0"><?= $e(__('Nessun incontro con verbale: compila prima il verbale di un incontro.')) ?></p>
          <?php else: ?>
            <form method="post" action="<?= $e(url('/book-club/' . $slug . '/ai/minutes')) ?>">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <label class="form-label small fw-semibold" for="ai-meeting"><?= $e(__('Incontro con verbale')) ?></label>
              <select id="ai-meeting" name="meeting_id" required class="form-select mb-3">
                <?php foreach ($meetings as $meeting): ?>
                  <option value="<?= (int) $meeting['id'] ?>">
                    <?= $e($meeting['title']) ?> — <?= $e(date('d/m/Y', strtotime((string) $meeting['starts_at']) ?: 0)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <button type="submit" <?= $capReached ? 'disabled' : '' ?> class="bc-btn w-100<?= $capReached ? ' bc-btn-outline' : '' ?>">
                <i class="fas fa-wand-magic-sparkles"></i><?= $e(__('Genera riassunto')) ?>
              </button>
            </form>
          <?php endif; ?>
        </section>
      </div>
    </div>

    <!-- History -->
    <section class="bc-card">
      <div class="bc-section-header">
        <i class="fas fa-clock-rotate-left"></i>
        <h2><?= $e(__('Generazioni precedenti')) ?></h2>
      </div>
      <?php if ($outputs === []): ?>
        <p class="bc-muted mb-0"><?= $e(__('Ancora nessuna generazione per questo club.')) ?></p>
      <?php endif; ?>
      <?php foreach ($outputs as $output): ?>
        <?php
          $isQuestions = (string) $output['kind'] === 'questions';
          $sourceTitle = $isQuestions
              ? (string) ($output['book_title'] ?? '')
              : (string) ($output['meeting_title'] ?? '');
          $creator = trim((string) ($output['creator_nome'] ?? '') . ' ' . (string) ($output['creator_cognome'] ?? ''));
          $domId = 'ai-output-' . (int) $output['id'];
        ?>
        <div class="bc-list-item flex-column align-items-stretch">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="d-flex align-items-center flex-wrap gap-2">
              <span class="bc-badge bc-badge-closed">
                <i class="fas <?= $isQuestions ? 'fa-comments' : 'fa-file-lines' ?>"></i>
                <?= $e($isQuestions ? __('Domande di discussione') : __('Riassunto verbale')) ?>
              </span>
              <?php if ($sourceTitle !== ''): ?>
                <span class="fw-semibold"><?= $e($sourceTitle) ?></span>
              <?php endif; ?>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="bc-muted">
                <?= $e(date('d/m/Y H:i', strtotime((string) $output['created_at']) ?: 0)) ?>
                <?php if ($creator !== ''): ?> · <i class="far fa-user me-1"></i><?= $e($creator) ?><?php endif; ?>
                <?php if ((string) $output['model'] !== ''): ?> · <?= $e($output['model']) ?><?php endif; ?>
              </span>
              <button type="button" data-copy-target="<?= $e($domId) ?>"
                      class="bc-btn bc-btn-outline bc-btn-sm js-ai-copy">
                <i class="far fa-copy"></i><?= $e(__('Copia')) ?>
              </button>
            </div>
          </div>
          <pre id="<?= $e($domId) ?>" class="small bg-light border rounded-3 p-3 mb-0" style="white-space: pre-wrap; font-family: inherit"><?= $e($output['content']) ?></pre>
        </div>
      <?php endforeach; ?>
    </section>

    <script>
      document.addEventListener('click', function (event) {
        var button = event.target.closest('.js-ai-copy');
        if (!button) { return; }
        var target = document.getElementById(button.getAttribute('data-copy-target'));
        if (!target || !navigator.clipboard) { return; }
        navigator.clipboard.writeText(target.textContent).then(function () {
          var original = button.innerHTML;
          button.innerHTML = '<i class="fas fa-check"></i><?= $e(__('Copiato!')) ?>';
          setTimeout(function () { button.innerHTML = original; }, 1500);
        });
      });
    </script>
  <?php endif; ?>
</div>

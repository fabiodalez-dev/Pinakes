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
$corePartials = dirname(__DIR__, 5) . '/app/Views/frontend/partials';
$catalogPageStyles = true;
$heroTitle = __('Assistente IA');
$heroSubtitle = $configured ? $model . ' · ' . sprintf(__('%1$d/%2$d generazioni nelle ultime 24 ore'), (int) $recentCount, (int) $dailyCap) : '';
$breadcrumbItems = [
    ['label' => __('Home'), 'href' => url('/')],
    ['label' => __('Club di lettura'), 'href' => url('/book-club')],
    ['label' => (string) $club['name'], 'href' => url('/book-club/' . $slug)],
    ['label' => $heroTitle],
];
include $corePartials . '/catalog-hero.php';
?>
<style>
  .bc-card{background:var(--white);border:1px solid var(--border-color);border-radius:2px;box-shadow:none;padding:clamp(1.5rem,3vw,2rem);margin-bottom:1.5rem}
  .bc-section-header{display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem}
  .bc-section-header i{color:var(--primary-text, var(--primary-color));font-size:1.15rem}
  .bc-section-header h2,.bc-section-header h1{font-size:1.35rem;font-weight:700;letter-spacing:-.02em;margin:0;color:var(--text-color)}
  .bc-btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;padding:.55rem 1.4rem;border-radius:2px;border:1.5px solid var(--button-color);background:var(--button-color);color:var(--button-text-color);font-weight:600;font-size:.9rem;cursor:pointer;text-decoration:none;transition:background-color .2s ease,border-color .2s ease,color .2s ease;white-space:nowrap;min-height:44px}
  .bc-btn:hover{background:var(--button-hover);border-color:var(--button-hover);color:var(--button-text-color)}
  .bc-btn-outline{background:transparent;color:var(--text-color);border:1px solid var(--border-color)}
  .bc-btn-outline:hover{border-color:var(--primary-color);color:var(--primary-text, var(--primary-color));background:transparent}
  .bc-btn-danger{background:transparent;border:1px solid var(--danger-color);color:var(--danger-color)}
  .bc-btn-danger:hover{background:var(--danger-color);border-color:var(--danger-color);color:#fff}
  .bc-btn-sm{padding:.3rem .9rem;font-size:.8rem;min-height:44px}
  .bc-badge{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .75rem;border-radius:2px;font-size:.75rem;font-weight:600}
  .bc-badge-open{background:rgba(16,185,129,.12);color:var(--success-color)}
  .bc-badge-closed{background:var(--accent-color);color:var(--text-light)}
  .bc-badge-warn{background:rgba(245,158,11,.14);color:#92400e}
  .bc-muted{color:var(--text-light);font-size:.85rem}
  .bc-progress{height:8px;background:var(--accent-color);border-radius:2px;overflow:hidden}
  .bc-progress>span{display:block;height:100%;border-radius:2px;background:var(--primary-color)}
  .bc-list-item{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;padding:.9rem 0;border-top:1px solid var(--border-color)}
  .bc-list-item:first-child{border-top:none}
  .bc-cover{width:44px;height:64px;object-fit:cover;border-radius:3px}
  .bc-chip{display:inline-block;width:.8rem;height:.8rem;border-radius:2px;flex:none}
</style>
<div class="container py-4">
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
        <i class="fas fa-hand mr-1"></i><?= $e(sprintf(__('Limite di sicurezza raggiunto: massimo %d generazioni IA per club nelle ultime 24 ore. Riprova più tardi.'), (int) $dailyCap)) ?>
      </div>
    <?php endif; ?>

    <div class="flex flex-wrap -mx-3 gap-y-4 mb-2">
      <!-- Discussion questions -->
      <div class="w-full px-3 md:w-1/2">
        <section class="bc-card h-full">
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
              <label class="form-label text-sm font-semibold" for="ai-book"><?= $e(__('Libro del club')) ?></label>
              <select id="ai-book" name="club_book_id" required class="form-input mb-3">
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
      <div class="w-full px-3 md:w-1/2">
        <section class="bc-card h-full">
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
              <label class="form-label text-sm font-semibold" for="ai-meeting"><?= $e(__('Incontro con verbale')) ?></label>
              <select id="ai-meeting" name="meeting_id" required class="form-input mb-3">
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
        <div class="bc-list-item flex-col items-stretch">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center flex-wrap gap-2">
              <span class="bc-badge bc-badge-closed">
                <i class="fas <?= $isQuestions ? 'fa-comments' : 'fa-file-lines' ?>"></i>
                <?= $e($isQuestions ? __('Domande di discussione') : __('Riassunto verbale')) ?>
              </span>
              <?php if ($sourceTitle !== ''): ?>
                <span class="font-semibold"><?= $e($sourceTitle) ?></span>
              <?php endif; ?>
            </div>
            <div class="flex items-center gap-2">
              <span class="bc-muted">
                <?= $e(date('d/m/Y H:i', strtotime((string) $output['created_at']) ?: 0)) ?>
                <?php if ($creator !== ''): ?> · <i class="far fa-user mr-1"></i><?= $e($creator) ?><?php endif; ?>
                <?php if ((string) $output['model'] !== ''): ?> · <?= $e($output['model']) ?><?php endif; ?>
              </span>
              <button type="button" data-copy-target="<?= $e($domId) ?>"
                      class="bc-btn bc-btn-outline bc-btn-sm js-ai-copy">
                <i class="far fa-copy"></i><?= $e(__('Copia')) ?>
              </button>
            </div>
          </div>
          <pre id="<?= $e($domId) ?>" class="text-sm bg-gray-100 border p-3 mb-0" style="white-space: pre-wrap; font-family: inherit"><?= $e($output['content']) ?></pre>
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
          button.innerHTML = '<i class="fas fa-check"></i>' + <?= json_encode(__('Copiato!'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
          setTimeout(function () { button.innerHTML = original; }, 1500);
        });
      });
    </script>
  <?php endif; ?>
</div>

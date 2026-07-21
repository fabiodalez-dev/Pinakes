<?php
/**
 * Book Club — poll list + advanced poll creation (voting2 module).
 * Members see every poll of the club; holders of the `polls.create`
 * permission also get the full creation form with all six modes, quorum,
 * tie-break and weighted-vote weight settings.
 *
 * @var array<string, mixed> $club
 * @var list<array<string, mixed>> $polls
 * @var list<array<string, mixed>> $eligible  proposals usable as options
 * @var bool $isMember
 * @var bool $canManage  club managers (kept for non-creation UI)
 * @var bool|null $canCreate  granular polls.create permission → creation form
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
$canCreate = $canCreate ?? $canManage;
$modeLabels = [
    'simple'      => __('Voto singolo'),
    'multi'       => __('Preferenza multipla'),
    'stars'       => __('Stelle (1–5)'),
    'ranking'     => __('Classifica (Borda)'),
    'elimination' => __('Eliminazione progressiva'),
    'weighted'    => __('Voto ponderato'),
];
$modeHelp = [
    'simple'      => __('un voto a testa.'),
    'multi'       => __('ogni membro dispone di più voti (campo «voti per membro»).'),
    'stars'       => __('ogni membro valuta i libri che preferisce da 1 a 5 stelle; vince la somma più alta.'),
    'ranking'     => __('ogni membro ordina tutti i libri; conteggio Borda (1° = N punti).'),
    'elimination' => __('a ogni turno esce il libro ultimo classificato, fino alla finale a due.'),
    'weighted'    => __('come il voto singolo/multiplo, ma i voti del fondatore e dei moderatori valgono di più (pesi configurabili per votazione, predefiniti 2,0 e 1,5).'),
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
  <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="bc-muted text-decoration-none d-inline-flex align-items-center gap-2 mb-3">
    <i class="fas fa-arrow-left"></i><?= $e($club['name']) ?>
  </a>

  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <div class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-vote-yea"></i>
      <h1><?= $e(__('Votazioni')) ?></h1>
    </div>
    <?php if (empty($polls)): ?>
      <p class="bc-muted mb-0"><?= $e(__('Nessuna votazione al momento.')) ?></p>
    <?php endif; ?>
    <?php foreach ($polls as $poll): ?>
      <div class="bc-list-item align-items-center">
        <div>
          <a class="fw-semibold text-decoration-none" style="color: var(--primary-color)" href="<?= $e(url('/book-club/' . $slug . '/polls/' . (int) $poll['id'])) ?>"><?= $e($poll['title']) ?></a>
          <div class="bc-muted small mt-1">
            <?= $e($modeLabels[(string) $poll['mode']] ?? (string) $poll['mode']) ?>
            <?php if ((string) $poll['mode'] === 'elimination'): ?>
              · <?= $e(sprintf(__('turno %d'), max(1, (int) ($poll['round'] ?? 1)))) ?>
            <?php endif; ?>
            <?php if (!empty($poll['quorum_pct'])): ?>
              · <?= $e(sprintf(__('quorum %d%%'), (int) $poll['quorum_pct'])) ?>
            <?php endif; ?>
            · <?= (int) $poll['voter_count'] ?> <?= $e(__('votanti')) ?>
            <?php if (!empty($poll['closes_at'])): ?>
              · <?= $poll['status'] === 'open' ? $e(__('scade il')) : $e(__('scaduta il')) ?> <?= $e(date('d/m/Y H:i', (int) strtotime((string) $poll['closes_at']))) ?>
            <?php endif; ?>
          </div>
        </div>
        <span class="bc-badge <?= $poll['status'] === 'open' ? 'bc-badge-open' : 'bc-badge-closed' ?>">
          <?= $poll['status'] === 'open' ? $e(__('Aperta')) : $e(__('Chiusa')) ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (!empty($neverChosen)): ?>
    <div class="bc-card">
      <div class="bc-section-header mb-1">
        <i class="fas fa-clock-rotate-left"></i>
        <h2><?= $e(__('Proposte non selezionate')) ?></h2>
      </div>
      <p class="bc-muted mb-4"><?= $e(__('Libri che sono stati candidati in una votazione chiusa ma non sono mai stati scelti.')) ?></p>
      <?php foreach ($neverChosen as $prop): ?>
        <div class="bc-list-item align-items-center">
          <div>
            <span class="fw-semibold"><?= $e((string) ($prop['titolo'] ?? '')) ?></span>
            <?php if (!empty($prop['autori'])): ?>
              <span class="bc-muted small">· <?= $e((string) $prop['autori']) ?></span>
            <?php endif; ?>
            <div class="bc-muted small mt-1">
              <?= (int) $prop['times_in_poll'] ?> <?= $e((int) $prop['times_in_poll'] === 1 ? __('votazione') : __('votazioni')) ?>
              <?php if (!empty($prop['last_poll_at'])): ?>
                · <?= $e(__('ultima')) ?> <?= $e(date('d/m/Y', (int) strtotime((string) $prop['last_poll_at']))) ?>
              <?php endif; ?>
              <?php if (!empty($prop['is_external'])): ?>
                · <span class="bc-badge bc-badge-closed"><?= $e(__('Non in catalogo')) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($canCreate): ?>
    <div class="bc-card">
      <div class="bc-section-header mb-1">
        <i class="fas fa-plus-circle"></i>
        <h2><?= $e(__('Apri una nuova votazione')) ?></h2>
      </div>
      <p class="bc-muted mb-4"><?= $e(__('Tutte le modalità avanzate: stelle, classifica, eliminazione, voto ponderato, quorum e spareggio.')) ?></p>

      <?php if (count($eligible) < 2): ?>
        <p class="bc-muted mb-0"><?= $e(__('Servono almeno due proposte per aprire una votazione.')) ?></p>
      <?php else: ?>
        <form method="post" action="<?= $e(url('/book-club/' . $slug . '/polls/new')) ?>" class="d-flex flex-column gap-3">
          <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
          <input type="text" name="title" maxlength="190" placeholder="<?= $e(__('Titolo (es. Votazione autunno 2026)')) ?>"
                 class="form-control">
          <textarea name="description" rows="2" maxlength="3000" placeholder="<?= $e(__('Descrizione (facoltativa)')) ?>"
                    class="form-control"></textarea>

          <div class="row g-3">
            <div class="col-6 col-md-3">
              <select name="mode" class="form-select" title="<?= $e(__('Modalità di voto')) ?>">
                <?php foreach ($modeLabels as $value => $label): ?>
                  <option value="<?= $e($value) ?>"><?= $e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <input type="number" name="votes_per_member" min="1" max="20" value="3"
                     title="<?= $e(__('Voti per membro (preferenza multipla e voto ponderato)')) ?>"
                     class="form-control">
            </div>
            <div class="col-6 col-md-3">
              <select name="anonymity" class="form-select">
                <option value="public"><?= $e(__('Voto pubblico')) ?></option>
                <option value="secret"><?= $e(__('Voto segreto')) ?></option>
              </select>
            </div>
            <div class="col-6 col-md-3">
              <input type="datetime-local" name="closes_at" class="form-control"
                     title="<?= $e(__('Scadenza (facoltativa)')) ?>">
            </div>
          </div>

          <div class="row g-3">
            <div class="col-12 col-md-6">
              <input type="number" name="quorum_pct" min="1" max="100" placeholder="<?= $e(__('Quorum % (facoltativo)')) ?>"
                     title="<?= $e(__('Percentuale minima di membri attivi che devono votare perché ci sia un vincitore')) ?>"
                     class="form-control">
            </div>
            <div class="col-12 col-md-6">
              <select name="tiebreak" class="form-select" title="<?= $e(__('Spareggio in caso di parità')) ?>">
                <option value="oldest_proposal"><?= $e(__('Spareggio: vince la proposta più antica')) ?></option>
                <option value="random"><?= $e(__('Spareggio: sorteggio deterministico')) ?></option>
                <option value="admin"><?= $e(__('Spareggio: decide un moderatore')) ?></option>
              </select>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="d-block">
                <span class="form-label bc-muted small"><?= $e(__('Peso del voto del fondatore')) ?></span>
                <input type="number" name="weight_owner" step="0.5" min="1" max="5" value="2.0"
                       title="<?= $e(__('Solo per il voto ponderato: quanto vale il voto del fondatore (da 1 a 5)')) ?>"
                       class="form-control">
              </label>
            </div>
            <div class="col-12 col-md-6">
              <label class="d-block">
                <span class="form-label bc-muted small"><?= $e(__('Peso del voto dei moderatori')) ?></span>
                <input type="number" name="weight_moderator" step="0.5" min="1" max="5" value="1.5"
                       title="<?= $e(__('Solo per il voto ponderato: quanto vale il voto dei moderatori (da 1 a 5)')) ?>"
                       class="form-control">
              </label>
            </div>
          </div>
          <p class="bc-muted small mb-0"><?= $e(__('I pesi si applicano solo alla modalità «Voto ponderato»; gli altri membri valgono sempre 1,0.')) ?></p>

          <div class="border rounded-3 p-3 overflow-auto" style="max-height: 12rem">
            <?php foreach ($eligible as $book): ?>
              <label class="form-check d-flex align-items-center gap-2 mb-1">
                <input type="checkbox" name="options[]" value="<?= (int) $book['id'] ?>" class="form-check-input mt-0 flex-shrink-0">
                <span class="small"><?= $e($book['titolo']) ?><?php if (!empty($book['autori'])): ?><span class="bc-muted"> — <?= $e($book['autori']) ?></span><?php endif; ?></span>
              </label>
            <?php endforeach; ?>
          </div>

          <div>
            <button type="submit" class="bc-btn"><?= $e(__('Apri votazione')) ?></button>
          </div>
        </form>
      <?php endif; ?>

      <div class="mt-4 border-top pt-3 bc-muted small">
        <div class="fw-semibold text-uppercase mb-2"><?= $e(__('Le modalità in breve')) ?></div>
        <?php foreach ($modeLabels as $value => $label): ?>
          <p class="mb-1"><span class="fw-semibold" style="color: var(--text-color)"><?= $e($label) ?></span>: <?= $e($modeHelp[$value]) ?></p>
        <?php endforeach; ?>
        <p class="mb-0 pt-1"><span class="fw-semibold" style="color: var(--text-color)"><?= $e(__('Quorum')) ?></span>: <?= $e(__('se alla chiusura i votanti sono meno della percentuale indicata, non c\'è vincitore e le proposte tornano disponibili.')) ?></p>
      </div>
    </div>
  <?php endif; ?>
</div>

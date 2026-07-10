<?php
/**
 * Book Club — public club home: overview, books/workflow, proposals,
 * polls, meetings + RSVP, members.
 *
 * @var array<string, mixed> $club
 * @var list<array{key: string, label: string, color: string, flags: array<string, bool>}> $states
 * @var array<string, mixed>|null $membership
 * @var bool $isMember
 * @var bool $canManage
 * @var bool $loggedIn
 * @var list<array<string, mixed>> $books
 * @var list<array<string, mixed>> $polls
 * @var list<array<string, mixed>> $meetings
 * @var list<array<string, mixed>> $members
 * @var int $memberCount
 * @var array<string, mixed>|null $nextMeeting
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
$stateIndex = [];
foreach ($states as $s) {
    $stateIndex[$s['key']] = $s;
}
$booksByState = [];
foreach ($books as $book) {
    $booksByState[(string) $book['state']][] = $book;
}
$pendingProposals = $booksByState[\App\Plugins\BookClub\BookClubPlugin::STATE_PENDING] ?? [];
// Members always get the tokenized feed URL: the token proves membership
// and unlocks the members-only fields (e.g. video-conference links) that
// the anonymous public-club feed omits.
$icsUrl = url('/book-club/' . $slug . '/calendar.ics') . '?token=' . $club['ics_token'];
$kindLabels = ['in_person' => __('In presenza'), 'online' => __('Online'), 'hybrid' => __('Ibrido')];
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
  /* Page-local helpers (club page only; partials rely on the kit above). */
  .bc-hero-club{position:relative;overflow:hidden}
  .bc-hero-accent{position:absolute;top:0;left:0;right:0;height:6px}
  .bc-hero-meta{display:flex;flex-wrap:wrap;align-items:center;gap:1rem;margin-top:.75rem;font-size:.9rem;opacity:.85}
  .bc-hero-meta a{color:#fff;text-decoration:underline}
  .bc-preline{white-space:pre-line}
  .bc-row{padding:.9rem 0;border-top:1px solid var(--border-color)}
  .bc-cancelled{opacity:.5}
  .bc-link{color:var(--primary-color);font-weight:600;text-decoration:none}
  .bc-link:hover{text-decoration:underline}
  .bc-state-title{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-light);margin:0}
  .bc-card-flagged{border-left:4px solid var(--warning-color)}
  .bc-summary{color:var(--primary-color);font-weight:600;font-size:.9rem;cursor:pointer}
  .bc-scrollbox{max-height:12rem;overflow-y:auto;border:1px solid var(--border-color);border-radius:12px;padding:.75rem}
  .bc-autocomplete{position:absolute;z-index:10;left:0;right:0;margin-top:.25rem;background:var(--white);border:1px solid var(--border-color);border-radius:12px;box-shadow:var(--card-shadow);max-height:15rem;overflow-y:auto}
  .bc-autocomplete.hidden{display:none}
  .bc-autocomplete-item{padding:.5rem .75rem;font-size:.9rem;cursor:pointer}
  .bc-autocomplete-item:hover{background:var(--accent-color)}
</style>
<div class="container py-4">

  <!-- Header -->
  <div class="bc-hero bc-hero-club">
    <div class="bc-hero-accent" style="background: <?= $e($club['color']) ?>"></div>
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
      <div>
        <h1><?= $e($club['name']) ?></h1>
        <p class="bc-preline"><?= $e($club['description'] ?? '') ?></p>
        <div class="bc-hero-meta">
          <span><i class="fas fa-users me-1"></i><?= (int) $memberCount ?> <?= $e(__('membri')) ?><?= $club['max_members'] !== null ? ' / ' . (int) $club['max_members'] : '' ?></span>
          <?php if ($isMember || $canManage): ?>
            <a href="<?= htmlspecialchars($icsUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-calendar-alt me-1"></i><?= $e(__('Calendario iCal')) ?></a>
          <?php endif; ?>
        </div>
      </div>
      <div class="d-flex align-items-center gap-3">
        <?php if (!$loggedIn): ?>
          <a href="<?= $e(\App\Support\RouteTranslator::route('login')) ?>" class="bc-btn"><?= $e(__('Accedi per partecipare')) ?></a>
        <?php elseif ($membership === null || !in_array($membership['status'], ['active', 'pending'], true)): ?>
          <?php if (in_array($club['privacy'], ['public', 'private'], true)): ?>
            <form method="post" action="<?= $e(url('/book-club/' . $slug . '/join')) ?>">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="bc-btn">
                <?= $club['privacy'] === 'public' ? $e(__('Unisciti al club')) : $e(__('Richiedi di partecipare')) ?>
              </button>
            </form>
          <?php else: ?>
            <span class="bc-badge bc-badge-closed"><?= $e(__('Accesso solo su invito')) ?></span>
          <?php endif; ?>
        <?php elseif ($membership['status'] === 'pending'): ?>
          <span class="bc-badge bc-badge-warn"><?= $e(__('Richiesta in attesa di approvazione')) ?></span>
        <?php else: ?>
          <form method="post" action="<?= $e(url('/book-club/' . $slug . '/leave')) ?>"
                onsubmit="return confirm('<?= $e(__('Vuoi davvero lasciare il club?')) ?>');">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm"><?= $e(__('Lascia il club')) ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($club['rules']) && ($isMember || $canManage)): ?>
    <div class="alert alert-warning">
      <div class="fw-semibold mb-1"><i class="fas fa-scroll me-2"></i><?= $e(__('Regolamento del club')) ?></div>
      <div class="bc-preline small"><?= $e($club['rules']) ?></div>
    </div>
  <?php endif; ?>

  <?php if ($canManage && $pendingProposals !== []): ?>
    <!-- Moderation queue -->
    <section class="bc-card bc-card-flagged">
      <div class="bc-section-header">
        <i class="fas fa-inbox"></i>
        <h2><?= $e(__('Proposte da moderare')) ?> (<?= count($pendingProposals) ?>)</h2>
      </div>
      <?php foreach ($pendingProposals as $book): ?>
        <div class="bc-list-item">
          <div>
            <span class="fw-semibold"><?= $e($book['titolo']) ?></span>
            <?php if (!empty($book['autori'])): ?><span class="bc-muted"> — <?= $e($book['autori']) ?></span><?php endif; ?>
            <?php if (!empty($book['proposer_nome'])): ?>
              <span class="bc-muted small ms-2"><?= $e(__('proposto da')) ?> <?= $e($book['proposer_nome'] . ' ' . $book['proposer_cognome']) ?></span>
            <?php endif; ?>
            <?php if (!empty($book['motivation'])): ?><p class="bc-muted small mt-1 mb-0"><?= $e($book['motivation']) ?></p><?php endif; ?>
          </div>
          <div class="d-flex align-items-center gap-2">
            <form method="post" action="<?= $e(url('/book-club/' . $slug . '/books/' . (int) $book['id'] . '/state')) ?>">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <input type="hidden" name="state" value="<?= $e($states[0]['key'] ?? 'proposed') ?>">
              <button type="submit" class="bc-btn bc-btn-sm"><?= $e(__('Approva')) ?></button>
            </form>
            <form method="post" action="<?= $e(url('/book-club/' . $slug . '/books/' . (int) $book['id'] . '/state')) ?>">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <input type="hidden" name="state" value="reject-proposal">
              <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm"><?= $e(__('Rifiuta')) ?></button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-12 col-lg-8">

      <!-- Workflow board -->
      <section class="bc-card">
        <div class="bc-section-header">
          <i class="fas fa-book"></i>
          <h2><?= $e(__('I libri del club')) ?></h2>
        </div>
        <?php $hasAny = false; ?>
        <?php foreach ($states as $state): ?>
          <?php $stateBooks = $booksByState[$state['key']] ?? []; ?>
          <?php if ($stateBooks === [] && empty($state['flags']['current'])) { continue; } $hasAny = $hasAny || $stateBooks !== []; ?>
          <div class="mb-4">
            <div class="d-flex align-items-center gap-2 mb-2">
              <span class="bc-chip" style="background: <?= $e($state['color']) ?>"></span>
              <h3 class="bc-state-title"><?= $e($state['label']) ?> <span class="fw-normal">(<?= count($stateBooks) ?>)</span></h3>
            </div>
            <div>
              <?php foreach ($stateBooks as $book): ?>
                <div class="bc-list-item">
                  <div class="d-flex align-items-start gap-3">
                    <?php if (!empty($book['copertina_url'])): ?>
                      <img src="<?= $e($book['copertina_url']) ?>" alt="" class="bc-cover" loading="lazy">
                    <?php endif; ?>
                    <div>
                      <div class="fw-semibold">
                        <?= $e($book['titolo']) ?>
                        <?php if (!empty($book['is_external'])): ?>
                          <span class="bc-badge bc-badge-warn ms-1" title="<?= $e(__('Questo libro non è ancora nel catalogo della biblioteca.')) ?>"><i class="fas fa-book-medical me-1"></i><?= $e(__('Proposta esterna')) ?></span>
                        <?php endif; ?>
                      </div>
                      <?php if (!empty($book['autori'])): ?><div class="bc-muted"><?= $e($book['autori']) ?></div><?php endif; ?>
                      <?php if (!empty($book['reading_starts']) || !empty($book['reading_ends'])): ?>
                        <div class="bc-muted small mt-1">
                          <i class="far fa-calendar me-1"></i>
                          <?= !empty($book['reading_starts']) ? $e(date('d/m/Y', (int) strtotime((string) $book['reading_starts']))) : '…' ?>
                          →
                          <?= !empty($book['reading_ends']) ? $e(date('d/m/Y', (int) strtotime((string) $book['reading_ends']))) : '…' ?>
                        </div>
                      <?php endif; ?>
                      <?php if (!empty($book['motivation'])): ?>
                        <p class="bc-muted small fst-italic mt-1 mb-0">«<?= $e(mb_substr((string) $book['motivation'], 0, 240)) ?>»
                          <?php if (!empty($book['proposer_nome'])): ?>— <?= $e($book['proposer_nome']) ?><?php endif; ?></p>
                      <?php endif; ?>
                    </div>
                  </div>
                  <?php if ($canManage): ?>
                    <div class="d-flex flex-column align-items-end gap-2">
                      <form method="post" action="<?= $e(url('/book-club/' . $slug . '/books/' . (int) $book['id'] . '/state')) ?>" class="d-flex align-items-center gap-2">
                        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                        <select name="state" class="form-select form-select-sm w-auto">
                          <?php foreach ($states as $target): ?>
                            <option value="<?= $e($target['key']) ?>" <?= $target['key'] === $book['state'] ? 'selected' : '' ?>><?= $e($target['label']) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <button type="submit" class="bc-btn bc-btn-outline bc-btn-sm" title="<?= $e(__('Sposta')) ?>"><i class="fas fa-arrow-right"></i></button>
                      </form>
                      <?php if (!empty($book['is_external'])): ?>
                        <form method="post" action="<?= $e(url('/book-club/' . $slug . '/books/' . (int) $book['id'] . '/acquire')) ?>">
                          <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                          <button type="submit" class="bc-btn bc-btn-sm" title="<?= $e(__('Crea la voce di catalogo da questa proposta esterna.')) ?>"><i class="fas fa-plus me-1"></i><?= $e(__('Acquisisci in catalogo')) ?></button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if ($stateBooks === []): ?>
              <p class="bc-muted small mb-0"><?= $e(__('Nessun libro in questo stato.')) ?></p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (!$hasAny && !$isMember): ?>
          <p class="bc-muted mb-0"><?= $e(__('Ancora nessun libro: unisciti al club e proponi il primo!')) ?></p>
        <?php endif; ?>
      </section>

      <?php if ($isMember || $canManage): ?>
        <!-- Propose a book -->
        <section class="bc-card">
          <div class="bc-section-header">
            <i class="fas fa-lightbulb"></i>
            <h2><?= $e(__('Proponi un libro')) ?></h2>
          </div>
          <p class="bc-muted mb-3"><?= $e(__('Cerca nel catalogo della biblioteca e racconta al club perché vale la pena leggerlo.')) ?></p>
          <form method="post" action="<?= $e(url('/book-club/' . $slug . '/proposals')) ?>">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <input type="hidden" name="libro_id" id="bc-libro-id">
            <div class="position-relative mb-3">
              <input type="text" id="bc-book-search" autocomplete="off" required
                     placeholder="<?= $e(__('Cerca per titolo o ISBN…')) ?>"
                     class="form-control">
              <div id="bc-book-results" class="bc-autocomplete hidden"></div>
            </div>
            <textarea name="motivation" rows="2" maxlength="3000"
                      placeholder="<?= $e(__('Perché proponi questo libro? (facoltativo)')) ?>"
                      class="form-control mb-3"></textarea>
            <button type="submit" class="bc-btn"><?= $e(__('Invia proposta')) ?></button>
          </form>

          <details class="mt-3 pt-3 border-top bc-external-propose">
            <summary class="bc-summary"><?= $e(__('Il libro non è in catalogo?')) ?></summary>
            <p class="bc-muted small mt-2 mb-3"><?= $e(__('Proponi un libro non ancora presente in biblioteca. Non verrà aggiunto al catalogo finché il club non lo sceglie e un responsabile lo acquisisce.')) ?></p>
            <form method="post" action="<?= $e(url('/book-club/' . $slug . '/proposals')) ?>">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <input type="hidden" name="source" value="external">
              <input type="text" name="ext_titolo" required maxlength="500" placeholder="<?= $e(__('Titolo')) ?>" class="form-control mb-2">
              <div class="row g-2 mb-2">
                <div class="col-12 col-md-6"><input type="text" name="ext_autori" maxlength="500" placeholder="<?= $e(__('Autore/i')) ?>" class="form-control"></div>
                <div class="col-6 col-md-3"><input type="text" name="ext_isbn" maxlength="20" placeholder="<?= $e(__('ISBN')) ?>" class="form-control"></div>
                <div class="col-6 col-md-3"><input type="text" name="ext_anno" maxlength="10" placeholder="<?= $e(__('Anno')) ?>" class="form-control"></div>
              </div>
              <input type="text" name="ext_editore" maxlength="255" placeholder="<?= $e(__('Editore (facoltativo)')) ?>" class="form-control mb-2">
              <textarea name="motivation" rows="2" maxlength="3000" placeholder="<?= $e(__('Perché proponi questo libro? (facoltativo)')) ?>" class="form-control mb-3"></textarea>
              <button type="submit" class="bc-btn bc-btn-outline"><?= $e(__('Proponi libro esterno')) ?></button>
            </form>
          </details>
          <script>
            (function () {
              var input = document.getElementById('bc-book-search');
              var box = document.getElementById('bc-book-results');
              var hidden = document.getElementById('bc-libro-id');
              var timer = null;
              input.addEventListener('input', function () {
                hidden.value = '';
                clearTimeout(timer);
                var q = input.value.trim();
                if (q.length < 2) { box.classList.add('hidden'); return; }
                timer = setTimeout(function () {
                  fetch(<?= json_encode(url('/book-club/' . $slug . '/book-search'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> + '?q=' + encodeURIComponent(q), {headers: {'Accept': 'application/json'}})
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                      box.innerHTML = '';
                      (data.results || []).forEach(function (item) {
                        var div = document.createElement('div');
                        div.className = 'bc-autocomplete-item';
                        div.textContent = item.label;
                        div.addEventListener('click', function () {
                          hidden.value = item.id;
                          input.value = item.label;
                          box.classList.add('hidden');
                        });
                        box.appendChild(div);
                      });
                      box.classList.toggle('hidden', box.children.length === 0);
                    })
                    .catch(function () { box.classList.add('hidden'); });
                }, 250);
              });
              document.addEventListener('click', function (ev) {
                if (!box.contains(ev.target) && ev.target !== input) { box.classList.add('hidden'); }
              });
            })();
          </script>
        </section>
      <?php endif; ?>

      <!-- Polls -->
      <section class="bc-card">
        <div class="bc-section-header">
          <i class="fas fa-vote-yea"></i>
          <h2><?= $e(__('Votazioni')) ?></h2>
        </div>
        <?php if (empty($polls)): ?>
          <p class="bc-muted mb-0"><?= $e(__('Nessuna votazione al momento.')) ?></p>
        <?php endif; ?>
        <?php foreach ($polls as $poll): ?>
          <div class="bc-list-item align-items-center">
            <div>
              <a class="bc-link" href="<?= $e(url('/book-club/' . $slug . '/polls/' . (int) $poll['id'])) ?>"><?= $e($poll['title']) ?></a>
              <div class="bc-muted small mt-1">
                <?= (int) $poll['voter_count'] ?> <?= $e(__('votanti')) ?>
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

        <?php if ($canManage): ?>
          <?php
            // Proposals eligible as poll options: votable/entry states only,
            // never books already in another open poll (computed by the
            // controller via Repo::pollEligibleBooks).
            $eligible = $pollEligible ?? [];
          ?>
          <details class="mt-4 pt-3 border-top">
            <summary class="bc-summary"><?= $e(__('Apri una nuova votazione')) ?></summary>
            <?php if (count($eligible) < 2): ?>
              <p class="bc-muted mt-3 mb-0"><?= $e(__('Servono almeno due proposte per aprire una votazione.')) ?></p>
            <?php else: ?>
              <form method="post" action="<?= $e(url('/book-club/' . $slug . '/polls/new')) ?>" class="mt-3">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <input type="text" name="title" maxlength="190" placeholder="<?= $e(__('Titolo (es. Votazione autunno 2026)')) ?>"
                       class="form-control mb-3">
                <div class="row g-2 mb-3">
                  <div class="col-6 col-md-3">
                    <select name="mode" class="form-select form-select-sm" id="bc-poll-mode">
                      <option value="simple"><?= $e(__('Voto singolo')) ?></option>
                      <option value="multi"><?= $e(__('Preferenza multipla')) ?></option>
                    </select>
                  </div>
                  <div class="col-6 col-md-3">
                    <input type="number" name="votes_per_member" min="1" max="20" value="3"
                           title="<?= $e(__('Voti per membro (solo preferenza multipla)')) ?>"
                           class="form-control form-control-sm">
                  </div>
                  <div class="col-6 col-md-3">
                    <select name="anonymity" class="form-select form-select-sm">
                      <option value="public"><?= $e(__('Voto pubblico')) ?></option>
                      <option value="secret"><?= $e(__('Voto segreto')) ?></option>
                    </select>
                  </div>
                  <div class="col-6 col-md-3">
                    <input type="datetime-local" name="closes_at" class="form-control form-control-sm"
                           title="<?= $e(__('Scadenza (facoltativa)')) ?>">
                  </div>
                </div>
                <div class="bc-scrollbox mb-3">
                  <?php foreach ($eligible as $book): ?>
                    <div class="form-check">
                      <input type="checkbox" name="options[]" value="<?= (int) $book['id'] ?>" class="form-check-input" id="bc-poll-opt-<?= (int) $book['id'] ?>">
                      <label class="form-check-label small" for="bc-poll-opt-<?= (int) $book['id'] ?>">
                        <?= $e($book['titolo']) ?><?php if (!empty($book['autori'])): ?><span class="bc-muted"> — <?= $e($book['autori']) ?></span><?php endif; ?>
                      </label>
                    </div>
                  <?php endforeach; ?>
                </div>
                <button type="submit" class="bc-btn"><?= $e(__('Apri votazione')) ?></button>
              </form>
            <?php endif; ?>
          </details>
        <?php endif; ?>
      </section>

      <!-- Meetings -->
      <section class="bc-card">
        <div class="bc-section-header">
          <i class="fas fa-calendar-check"></i>
          <h2><?= $e(__('Incontri')) ?></h2>
        </div>
        <?php if (empty($meetings)): ?>
          <p class="bc-muted mb-0"><?= $e(__('Nessun incontro pianificato.')) ?></p>
        <?php endif; ?>
        <?php foreach ($meetings as $meeting): ?>
          <?php $isPast = strtotime((string) $meeting['starts_at']) < time(); ?>
          <div id="bc-meeting-<?= (int) $meeting['id'] ?>" class="bc-row <?= $meeting['status'] === 'cancelled' ? 'bc-cancelled' : '' ?>">
            <div class="d-flex align-items-start justify-content-between gap-3">
              <div>
                <div class="fw-semibold">
                  <?= $e($meeting['title']) ?>
                  <?php if ($meeting['status'] === 'cancelled'): ?><span class="bc-badge bc-badge-warn ms-2"><?= $e(__('Annullato')) ?></span><?php endif; ?>
                  <?php if ($meeting['status'] === 'done'): ?><span class="bc-badge bc-badge-closed ms-2"><?= $e(__('Svolto')) ?></span><?php endif; ?>
                </div>
                <div class="bc-muted mt-1">
                  <i class="far fa-clock me-1"></i><?= $e(date('d/m/Y H:i', (int) strtotime((string) $meeting['starts_at']))) ?>
                  · <?= $e($kindLabels[$meeting['kind']] ?? $meeting['kind']) ?>
                  <?php if (!empty($meeting['location'])): ?> · <i class="fas fa-map-marker-alt me-1"></i><?= $e($meeting['location']) ?><?php endif; ?>
                  <?php if (!empty($meeting['video_url']) && ($isMember || $canManage)): ?>
                    · <a class="bc-link" href="<?= $e($meeting['video_url']) ?>" target="_blank" rel="noopener"><?= $e(__('Collegati')) ?></a>
                  <?php endif; ?>
                </div>
                <?php if (!empty($meeting['book_title'])): ?>
                  <div class="bc-muted small mt-1"><i class="fas fa-book me-1"></i><?= $e($meeting['book_title']) ?></div>
                <?php endif; ?>
                <?php if (!empty($meeting['agenda'])): ?>
                  <p class="bc-muted bc-preline mt-1 mb-0"><?= $e($meeting['agenda']) ?></p>
                <?php endif; ?>
                <?php if (!empty($meeting['minutes']) && ($isMember || $canManage)): ?>
                  <details class="mt-1"><summary class="bc-summary"><?= $e(__('Verbale')) ?></summary><p class="bc-muted bc-preline mt-1 mb-0"><?= $e($meeting['minutes']) ?></p></details>
                <?php endif; ?>
              </div>
              <div class="text-end bc-muted small text-nowrap">
                <div><?= (int) $meeting['yes_count'] ?> <?= $e(__('sì')) ?><?= $meeting['seats'] !== null ? ' / ' . (int) $meeting['seats'] . ' ' . $e(__('posti')) : '' ?></div>
                <?php if ((int) $meeting['maybe_count'] > 0): ?><div><?= (int) $meeting['maybe_count'] ?> <?= $e(__('forse')) ?></div><?php endif; ?>
              </div>
            </div>
            <?php if ($isMember && $meeting['status'] === 'scheduled' && !$isPast): ?>
              <form method="post" action="<?= $e(url('/book-club/' . $slug . '/meetings/' . (int) $meeting['id'] . '/rsvp')) ?>" class="d-flex align-items-center gap-2 mt-2">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <span class="bc-muted small me-1"><?= $e(__('Parteciperai?')) ?></span>
                <button name="response" value="yes" class="bc-btn bc-btn-sm"><?= $e(__('Sì')) ?></button>
                <button name="response" value="maybe" class="bc-btn bc-btn-outline bc-btn-sm"><?= $e(__('Forse')) ?></button>
                <button name="response" value="no" class="bc-btn bc-btn-outline bc-btn-sm"><?= $e(__('No')) ?></button>
              </form>
            <?php endif; ?>
            <?php if ($canManage && $meeting['status'] === 'scheduled'): ?>
              <form method="post" action="<?= $e(url('/book-club/' . $slug . '/meetings/' . (int) $meeting['id'] . '/status')) ?>" class="d-flex align-items-center gap-2 mt-2">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <button name="status" value="done" class="bc-btn bc-btn-outline bc-btn-sm"><?= $e(__('Segna come svolto')) ?></button>
                <button name="status" value="cancelled" class="bc-btn bc-btn-danger bc-btn-sm"
                        onclick="return confirm('<?= $e(__('Annullare questo incontro?')) ?>');"><?= $e(__('Annulla incontro')) ?></button>
              </form>
              <details class="mt-2 bc-meeting-edit">
                <summary class="bc-summary"><?= $e(__('Modifica incontro')) ?></summary>
                <form method="post" action="<?= $e(url('/book-club/' . $slug . '/meetings/' . (int) $meeting['id'] . '/edit')) ?>" class="mt-3">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <input type="text" name="title" required maxlength="190" value="<?= $e((string) $meeting['title']) ?>" class="form-control mb-3">
                  <div class="row g-2 mb-3">
                    <div class="col-6 col-md-3">
                      <input type="datetime-local" name="starts_at" required value="<?= $e(date('Y-m-d\TH:i', (int) strtotime((string) $meeting['starts_at']))) ?>" class="form-control form-control-sm" title="<?= $e(__('Inizio')) ?>">
                    </div>
                    <div class="col-6 col-md-3">
                      <input type="datetime-local" name="ends_at" value="<?= !empty($meeting['ends_at']) ? $e(date('Y-m-d\TH:i', (int) strtotime((string) $meeting['ends_at']))) : '' ?>" class="form-control form-control-sm" title="<?= $e(__('Fine (facoltativa)')) ?>">
                    </div>
                    <div class="col-6 col-md-3">
                      <select name="kind" class="form-select form-select-sm">
                        <?php foreach ($kindLabels as $value => $label): ?>
                          <option value="<?= $e($value) ?>" <?= $meeting['kind'] === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-6 col-md-3">
                      <input type="number" name="seats" min="1" value="<?= $meeting['seats'] !== null ? (int) $meeting['seats'] : '' ?>" placeholder="<?= $e(__('Posti (illimitati)')) ?>" class="form-control form-control-sm">
                    </div>
                  </div>
                  <div class="row g-2 mb-3">
                    <div class="col-12 col-md-6">
                      <input type="text" name="location" maxlength="255" value="<?= $e((string) ($meeting['location'] ?? '')) ?>" placeholder="<?= $e(__('Luogo')) ?>" class="form-control">
                    </div>
                    <div class="col-12 col-md-6">
                      <input type="url" name="video_url" maxlength="500" value="<?= $e((string) ($meeting['video_url'] ?? '')) ?>" placeholder="<?= $e(__('Link videoconferenza')) ?>" class="form-control">
                    </div>
                  </div>
                  <select name="club_book_id" class="form-select mb-3">
                    <option value=""><?= $e(__('Nessun libro collegato')) ?></option>
                    <?php foreach ($books as $book): ?>
                      <?php if ($book['state'] === \App\Plugins\BookClub\BookClubPlugin::STATE_PENDING) { continue; } ?>
                      <option value="<?= (int) $book['id'] ?>" <?= (int) ($meeting['club_book_id'] ?? 0) === (int) $book['id'] ? 'selected' : '' ?>><?= $e($book['titolo']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <textarea name="agenda" rows="2" maxlength="5000" placeholder="<?= $e(__('Ordine del giorno (facoltativo)')) ?>" class="form-control mb-3"><?= $e((string) ($meeting['agenda'] ?? '')) ?></textarea>
                  <button type="submit" class="bc-btn bc-btn-sm"><?= $e(__('Salva modifiche')) ?></button>
                </form>
              </details>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <?php if ($canManage): ?>
          <details class="mt-4 pt-3 border-top">
            <summary class="bc-summary"><?= $e(__('Pianifica un incontro')) ?></summary>
            <form method="post" action="<?= $e(url('/book-club/' . $slug . '/meetings/new')) ?>" class="mt-3">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <input type="text" name="title" required maxlength="190" placeholder="<?= $e(__('Titolo dell\'incontro')) ?>"
                     class="form-control mb-3">
              <div class="row g-2 mb-3">
                <div class="col-6 col-md-3">
                  <input type="datetime-local" name="starts_at" required class="form-control form-control-sm" title="<?= $e(__('Inizio')) ?>">
                </div>
                <div class="col-6 col-md-3">
                  <input type="datetime-local" name="ends_at" class="form-control form-control-sm" title="<?= $e(__('Fine (facoltativa)')) ?>">
                </div>
                <div class="col-6 col-md-3">
                  <select name="kind" class="form-select form-select-sm">
                    <?php foreach ($kindLabels as $value => $label): ?>
                      <option value="<?= $e($value) ?>"><?= $e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-6 col-md-3">
                  <input type="number" name="seats" min="1" placeholder="<?= $e(__('Posti (illimitati)')) ?>" class="form-control form-control-sm">
                </div>
              </div>
              <div class="row g-2 mb-3">
                <div class="col-12 col-md-6">
                  <input type="text" name="location" maxlength="255" placeholder="<?= $e(__('Luogo')) ?>" class="form-control">
                </div>
                <div class="col-12 col-md-6">
                  <input type="url" name="video_url" maxlength="500" placeholder="<?= $e(__('Link videoconferenza')) ?>" class="form-control">
                </div>
              </div>
              <select name="club_book_id" class="form-select mb-3">
                <option value=""><?= $e(__('Nessun libro collegato')) ?></option>
                <?php foreach ($books as $book): ?>
                  <?php if ($book['state'] === \App\Plugins\BookClub\BookClubPlugin::STATE_PENDING) { continue; } ?>
                  <option value="<?= (int) $book['id'] ?>"><?= $e($book['titolo']) ?></option>
                <?php endforeach; ?>
              </select>
              <textarea name="agenda" rows="2" maxlength="5000" placeholder="<?= $e(__('Ordine del giorno (facoltativo)')) ?>"
                        class="form-control mb-3"></textarea>
              <button type="submit" class="bc-btn"><?= $e(__('Crea incontro')) ?></button>
            </form>
          </details>
        <?php endif; ?>
      </section>

      <?php foreach (($modulePanelsMain ?? []) as $panelHtml): ?>
        <?= $panelHtml /* module-rendered, already escaped inside the partial */ ?>
      <?php endforeach; ?>
    </div>

    <!-- Sidebar -->
    <div class="col-12 col-lg-4">
      <?php if ($nextMeeting !== null): ?>
        <section class="bc-card">
          <div class="bc-section-header">
            <i class="far fa-calendar-alt"></i>
            <h2><?= $e(__('Prossimo incontro')) ?></h2>
          </div>
          <div class="fw-semibold"><?= $e($nextMeeting['title']) ?></div>
          <div class="bc-muted mt-1"><i class="far fa-clock me-1"></i><?= $e(date('d/m/Y H:i', (int) strtotime((string) $nextMeeting['starts_at']))) ?></div>
          <?php if (!empty($nextMeeting['location'])): ?>
            <div class="bc-muted"><i class="fas fa-map-marker-alt me-1"></i><?= $e($nextMeeting['location']) ?></div>
          <?php endif; ?>
          <?php if ($canManage): ?>
            <a class="bc-btn bc-btn-outline bc-btn-sm mt-2" href="<?= $e(url('/book-club/' . $slug)) ?>#bc-meeting-<?= (int) $nextMeeting['id'] ?>"><i class="fas fa-pen me-1"></i><?= $e(__('Modifica incontro')) ?></a>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <?php if ($canManage): ?>
        <section class="bc-card">
          <div class="bc-section-header">
            <i class="fas fa-envelope"></i>
            <h2><?= $e(__('Invita un lettore')) ?></h2>
          </div>
          <form method="post" action="<?= $e(url('/book-club/' . $slug . '/invite')) ?>">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <input type="email" name="email" required placeholder="email@esempio.it"
                   class="form-control mb-2">
            <button type="submit" class="bc-btn w-100"><?= $e(__('Invia invito')) ?></button>
          </form>
        </section>
      <?php endif; ?>

      <?php if ($members !== []): ?>
        <section class="bc-card">
          <div class="bc-section-header">
            <i class="fas fa-users"></i>
            <h2><?= $e(__('Membri')) ?></h2>
          </div>
          <ul class="list-unstyled mb-0">
            <?php foreach ($members as $member): ?>
              <?php if (!in_array($member['status'], ['active', 'pending'], true)) { continue; } ?>
              <li class="d-flex align-items-center justify-content-between gap-2 py-1">
                <span><?= $e($member['nome'] . ' ' . $member['cognome']) ?></span>
                <span class="d-flex align-items-center gap-2">
                  <?php if (in_array($member['role_slug'], ['owner', 'moderator'], true)): ?>
                    <span class="bc-muted small"><?= $e($member['role_name']) ?></span>
                  <?php endif; ?>
                  <?php if ($member['status'] === 'pending' && $canManage): ?>
                    <form method="post" action="<?= $e(url('/book-club/' . $slug . '/members/' . (int) $member['id'] . '/approve')) ?>" class="d-inline-flex gap-1">
                      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                      <button name="action" value="approve" class="bc-btn bc-btn-sm"><?= $e(__('Approva')) ?></button>
                      <button name="action" value="reject" class="bc-btn bc-btn-danger bc-btn-sm"><?= $e(__('Rifiuta')) ?></button>
                    </form>
                  <?php elseif ($member['status'] === 'pending'): ?>
                    <span class="bc-badge bc-badge-warn"><?= $e(__('in attesa')) ?></span>
                  <?php endif; ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <?php foreach (($modulePanelsSidebar ?? []) as $sideHtml): ?>
        <?= $sideHtml /* module-rendered, already escaped inside the partial */ ?>
      <?php endforeach; ?>
    </div>
  </div>
</div>

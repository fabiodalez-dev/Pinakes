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
$icsUrl = url('/book-club/' . $slug . '/calendar.ics');
if ($club['privacy'] !== 'public') {
    $icsUrl .= '?token=' . $e($club['ics_token']);
}
$kindLabels = ['in_person' => __('In presenza'), 'online' => __('Online'), 'hybrid' => __('Ibrido')];
?>
<div class="max-w-6xl mx-auto px-4 py-10">

  <!-- Header -->
  <div class="bg-white rounded-xl shadow overflow-hidden mb-8">
    <div class="h-2" style="background: <?= $e($club['color']) ?>"></div>
    <div class="p-6">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 class="text-3xl font-bold text-gray-900"><?= $e($club['name']) ?></h1>
          <p class="text-gray-500 mt-2 max-w-2xl whitespace-pre-line"><?= $e($club['description'] ?? '') ?></p>
          <div class="flex items-center gap-4 mt-3 text-sm text-gray-400">
            <span><i class="fas fa-users mr-1"></i><?= (int) $memberCount ?> <?= $e(__('membri')) ?><?= $club['max_members'] !== null ? ' / ' . (int) $club['max_members'] : '' ?></span>
            <?php if ($isMember || $canManage): ?>
              <a class="text-blue-600 hover:underline" href="<?= $icsUrl ?>"><i class="fas fa-calendar-alt mr-1"></i><?= $e(__('Calendario iCal')) ?></a>
            <?php endif; ?>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <?php if (!$loggedIn): ?>
            <a href="<?= $e(\App\Support\RouteTranslator::route('login')) ?>" class="px-4 py-2 bg-gray-900 text-white text-sm rounded-lg"><?= $e(__('Accedi per partecipare')) ?></a>
          <?php elseif ($membership === null || !in_array($membership['status'], ['active', 'pending'], true)): ?>
            <?php if (in_array($club['privacy'], ['public', 'private'], true)): ?>
              <form method="post" action="<?= $e(url('/book-club/' . $slug . '/join')) ?>">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
                  <?= $club['privacy'] === 'public' ? $e(__('Unisciti al club')) : $e(__('Richiedi di partecipare')) ?>
                </button>
              </form>
            <?php else: ?>
              <span class="text-sm text-gray-400"><?= $e(__('Accesso solo su invito')) ?></span>
            <?php endif; ?>
          <?php elseif ($membership['status'] === 'pending'): ?>
            <span class="px-3 py-1.5 text-sm rounded-lg bg-yellow-50 text-yellow-800"><?= $e(__('Richiesta in attesa di approvazione')) ?></span>
          <?php else: ?>
            <form method="post" action="<?= $e(url('/book-club/' . $slug . '/leave')) ?>"
                  onsubmit="return confirm('<?= $e(__('Vuoi davvero lasciare il club?')) ?>');">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="px-3 py-1.5 text-sm text-gray-500 hover:text-red-600 border border-gray-200 rounded-lg"><?= $e(__('Lascia il club')) ?></button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($club['rules']) && ($isMember || $canManage)): ?>
    <div class="bg-amber-50 border border-amber-100 rounded-xl p-5 mb-8 text-sm text-amber-900">
      <div class="font-semibold mb-1"><i class="fas fa-scroll mr-2"></i><?= $e(__('Regolamento del club')) ?></div>
      <div class="whitespace-pre-line"><?= $e($club['rules']) ?></div>
    </div>
  <?php endif; ?>

  <?php if ($canManage && $pendingProposals !== []): ?>
    <!-- Moderation queue -->
    <section class="bg-white rounded-xl shadow p-6 mb-8 border-l-4 border-yellow-400">
      <h2 class="text-lg font-semibold text-gray-900 mb-3"><?= $e(__('Proposte da moderare')) ?> (<?= count($pendingProposals) ?>)</h2>
      <?php foreach ($pendingProposals as $book): ?>
        <div class="flex items-center justify-between border-t py-2 text-sm">
          <div>
            <span class="font-medium"><?= $e($book['titolo']) ?></span>
            <?php if (!empty($book['autori'])): ?><span class="text-gray-500"> — <?= $e($book['autori']) ?></span><?php endif; ?>
            <?php if (!empty($book['proposer_nome'])): ?>
              <span class="text-xs text-gray-400 ml-2"><?= $e(__('proposto da')) ?> <?= $e($book['proposer_nome'] . ' ' . $book['proposer_cognome']) ?></span>
            <?php endif; ?>
            <?php if (!empty($book['motivation'])): ?><p class="text-xs text-gray-500 mt-1"><?= $e($book['motivation']) ?></p><?php endif; ?>
          </div>
          <div class="flex items-center gap-2">
            <form method="post" action="<?= $e(url('/book-club/' . $slug . '/books/' . (int) $book['id'] . '/state')) ?>">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <input type="hidden" name="state" value="<?= $e($states[0]['key'] ?? 'proposed') ?>">
              <button type="submit" class="px-3 py-1 text-xs bg-green-600 hover:bg-green-700 text-white rounded-lg"><?= $e(__('Approva')) ?></button>
            </form>
            <form method="post" action="<?= $e(url('/book-club/' . $slug . '/books/' . (int) $book['id'] . '/state')) ?>">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <input type="hidden" name="state" value="reject-proposal">
              <button type="submit" class="px-3 py-1 text-xs bg-red-50 hover:bg-red-100 text-red-700 rounded-lg"><?= $e(__('Rifiuta')) ?></button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <div class="lg:col-span-2 space-y-8">

      <!-- Workflow board -->
      <section class="bg-white rounded-xl shadow p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4"><?= $e(__('I libri del club')) ?></h2>
        <?php $hasAny = false; ?>
        <?php foreach ($states as $state): ?>
          <?php $stateBooks = $booksByState[$state['key']] ?? []; ?>
          <?php if ($stateBooks === [] && empty($state['flags']['current'])) { continue; } $hasAny = $hasAny || $stateBooks !== []; ?>
          <div class="mb-5">
            <div class="flex items-center mb-2">
              <span class="inline-block w-2.5 h-2.5 rounded-full mr-2" style="background: <?= $e($state['color']) ?>"></span>
              <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide"><?= $e($state['label']) ?> <span class="text-gray-300 font-normal">(<?= count($stateBooks) ?>)</span></h3>
            </div>
            <?php foreach ($stateBooks as $book): ?>
              <div class="flex items-start justify-between border rounded-lg px-3 py-2 mb-2">
                <div class="flex items-start gap-3">
                  <?php if (!empty($book['copertina_url'])): ?>
                    <img src="<?= $e($book['copertina_url']) ?>" alt="" class="w-10 h-14 object-cover rounded shadow-sm" loading="lazy">
                  <?php endif; ?>
                  <div>
                    <div class="font-medium text-gray-900"><?= $e($book['titolo']) ?></div>
                    <?php if (!empty($book['autori'])): ?><div class="text-sm text-gray-500"><?= $e($book['autori']) ?></div><?php endif; ?>
                    <?php if (!empty($book['reading_starts']) || !empty($book['reading_ends'])): ?>
                      <div class="text-xs text-gray-400 mt-0.5">
                        <i class="far fa-calendar mr-1"></i>
                        <?= !empty($book['reading_starts']) ? $e(date('d/m/Y', (int) strtotime((string) $book['reading_starts']))) : '…' ?>
                        →
                        <?= !empty($book['reading_ends']) ? $e(date('d/m/Y', (int) strtotime((string) $book['reading_ends']))) : '…' ?>
                      </div>
                    <?php endif; ?>
                    <?php if (!empty($book['motivation'])): ?>
                      <p class="text-xs text-gray-500 mt-1 italic">«<?= $e(mb_substr((string) $book['motivation'], 0, 240)) ?>»
                        <?php if (!empty($book['proposer_nome'])): ?>— <?= $e($book['proposer_nome']) ?><?php endif; ?></p>
                    <?php endif; ?>
                  </div>
                </div>
                <?php if ($canManage): ?>
                  <form method="post" action="<?= $e(url('/book-club/' . $slug . '/books/' . (int) $book['id'] . '/state')) ?>" class="flex items-center gap-1 text-xs">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                    <select name="state" class="border border-gray-200 rounded px-1.5 py-1 text-xs">
                      <?php foreach ($states as $target): ?>
                        <option value="<?= $e($target['key']) ?>" <?= $target['key'] === $book['state'] ? 'selected' : '' ?>><?= $e($target['label']) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded" title="<?= $e(__('Sposta')) ?>"><i class="fas fa-arrow-right"></i></button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            <?php if ($stateBooks === []): ?>
              <p class="text-xs text-gray-300"><?= $e(__('Nessun libro in questo stato.')) ?></p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (!$hasAny && !$isMember): ?>
          <p class="text-sm text-gray-400"><?= $e(__('Ancora nessun libro: unisciti al club e proponi il primo!')) ?></p>
        <?php endif; ?>
      </section>

      <?php if ($isMember || $canManage): ?>
        <!-- Propose a book -->
        <section class="bg-white rounded-xl shadow p-6">
          <h2 class="text-lg font-semibold text-gray-900 mb-1"><?= $e(__('Proponi un libro')) ?></h2>
          <p class="text-sm text-gray-500 mb-4"><?= $e(__('Cerca nel catalogo della biblioteca e racconta al club perché vale la pena leggerlo.')) ?></p>
          <form method="post" action="<?= $e(url('/book-club/' . $slug . '/proposals')) ?>" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <input type="hidden" name="libro_id" id="bc-libro-id">
            <div class="relative">
              <input type="text" id="bc-book-search" autocomplete="off" required
                     placeholder="<?= $e(__('Cerca per titolo o ISBN…')) ?>"
                     class="w-full border border-gray-300 rounded-lg px-3 py-2">
              <div id="bc-book-results" class="absolute z-10 left-0 right-0 bg-white border border-gray-200 rounded-lg shadow-lg mt-1 hidden max-h-60 overflow-y-auto"></div>
            </div>
            <textarea name="motivation" rows="2" maxlength="3000"
                      placeholder="<?= $e(__('Perché proponi questo libro? (facoltativo)')) ?>"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2"></textarea>
            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg"><?= $e(__('Invia proposta')) ?></button>
          </form>
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
                  fetch('<?= $e(url('/book-club/' . $slug . '/book-search')) ?>?q=' + encodeURIComponent(q), {headers: {'Accept': 'application/json'}})
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                      box.innerHTML = '';
                      (data.results || []).forEach(function (item) {
                        var div = document.createElement('div');
                        div.className = 'px-3 py-2 text-sm hover:bg-gray-50 cursor-pointer';
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
      <section class="bg-white rounded-xl shadow p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4"><?= $e(__('Votazioni')) ?></h2>
        <?php if (empty($polls)): ?>
          <p class="text-sm text-gray-400"><?= $e(__('Nessuna votazione al momento.')) ?></p>
        <?php endif; ?>
        <?php foreach ($polls as $poll): ?>
          <div class="flex items-center justify-between border-t py-3">
            <div>
              <a class="font-medium text-blue-600 hover:underline" href="<?= $e(url('/book-club/' . $slug . '/polls/' . (int) $poll['id'])) ?>"><?= $e($poll['title']) ?></a>
              <div class="text-xs text-gray-400 mt-0.5">
                <?= (int) $poll['voter_count'] ?> <?= $e(__('votanti')) ?>
                <?php if (!empty($poll['closes_at'])): ?>
                  · <?= $poll['status'] === 'open' ? $e(__('scade il')) : $e(__('scaduta il')) ?> <?= $e(date('d/m/Y H:i', (int) strtotime((string) $poll['closes_at']))) ?>
                <?php endif; ?>
              </div>
            </div>
            <span class="px-2 py-1 text-xs rounded-full <?= $poll['status'] === 'open' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500' ?>">
              <?= $poll['status'] === 'open' ? $e(__('Aperta')) : $e(__('Chiusa')) ?>
            </span>
          </div>
        <?php endforeach; ?>

        <?php if ($canManage): ?>
          <?php
            // Proposals eligible as poll options: everything except pending.
            $eligible = [];
            foreach ($books as $book) {
                if ($book['state'] !== \App\Plugins\BookClub\BookClubPlugin::STATE_PENDING) {
                    $eligible[] = $book;
                }
            }
          ?>
          <details class="mt-4 border-t pt-4">
            <summary class="text-sm font-medium text-blue-600 cursor-pointer"><?= $e(__('Apri una nuova votazione')) ?></summary>
            <?php if (count($eligible) < 2): ?>
              <p class="text-sm text-gray-400 mt-3"><?= $e(__('Servono almeno due proposte per aprire una votazione.')) ?></p>
            <?php else: ?>
              <form method="post" action="<?= $e(url('/book-club/' . $slug . '/polls/new')) ?>" class="mt-3 space-y-3">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <input type="text" name="title" maxlength="190" placeholder="<?= $e(__('Titolo (es. Votazione autunno 2026)')) ?>"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                  <select name="mode" class="border border-gray-300 rounded-lg px-2 py-1.5" id="bc-poll-mode">
                    <option value="simple"><?= $e(__('Voto singolo')) ?></option>
                    <option value="multi"><?= $e(__('Preferenza multipla')) ?></option>
                  </select>
                  <input type="number" name="votes_per_member" min="1" max="20" value="3"
                         title="<?= $e(__('Voti per membro (solo preferenza multipla)')) ?>"
                         class="border border-gray-300 rounded-lg px-2 py-1.5">
                  <select name="anonymity" class="border border-gray-300 rounded-lg px-2 py-1.5">
                    <option value="public"><?= $e(__('Voto pubblico')) ?></option>
                    <option value="secret"><?= $e(__('Voto segreto')) ?></option>
                  </select>
                  <input type="datetime-local" name="closes_at" class="border border-gray-300 rounded-lg px-2 py-1.5"
                         title="<?= $e(__('Scadenza (facoltativa)')) ?>">
                </div>
                <div class="max-h-48 overflow-y-auto border rounded-lg p-3 space-y-1">
                  <?php foreach ($eligible as $book): ?>
                    <label class="flex items-center text-sm text-gray-700">
                      <input type="checkbox" name="options[]" value="<?= (int) $book['id'] ?>" class="mr-2 rounded">
                      <?= $e($book['titolo']) ?><?php if (!empty($book['autori'])): ?><span class="text-gray-400 ml-1">— <?= $e($book['autori']) ?></span><?php endif; ?>
                    </label>
                  <?php endforeach; ?>
                </div>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg"><?= $e(__('Apri votazione')) ?></button>
              </form>
            <?php endif; ?>
          </details>
        <?php endif; ?>
      </section>

      <!-- Meetings -->
      <section class="bg-white rounded-xl shadow p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4"><?= $e(__('Incontri')) ?></h2>
        <?php if (empty($meetings)): ?>
          <p class="text-sm text-gray-400"><?= $e(__('Nessun incontro pianificato.')) ?></p>
        <?php endif; ?>
        <?php foreach ($meetings as $meeting): ?>
          <?php $isPast = strtotime((string) $meeting['starts_at']) < time(); ?>
          <div class="border-t py-3 <?= $meeting['status'] === 'cancelled' ? 'opacity-50' : '' ?>">
            <div class="flex items-start justify-between">
              <div>
                <div class="font-medium text-gray-900">
                  <?= $e($meeting['title']) ?>
                  <?php if ($meeting['status'] === 'cancelled'): ?><span class="text-xs text-red-500 ml-2"><?= $e(__('Annullato')) ?></span><?php endif; ?>
                  <?php if ($meeting['status'] === 'done'): ?><span class="text-xs text-gray-400 ml-2"><?= $e(__('Svolto')) ?></span><?php endif; ?>
                </div>
                <div class="text-sm text-gray-500 mt-0.5">
                  <i class="far fa-clock mr-1"></i><?= $e(date('d/m/Y H:i', (int) strtotime((string) $meeting['starts_at']))) ?>
                  · <?= $e($kindLabels[$meeting['kind']] ?? $meeting['kind']) ?>
                  <?php if (!empty($meeting['location'])): ?> · <i class="fas fa-map-marker-alt mr-1"></i><?= $e($meeting['location']) ?><?php endif; ?>
                  <?php if (!empty($meeting['video_url']) && ($isMember || $canManage)): ?>
                    · <a class="text-blue-600 hover:underline" href="<?= $e($meeting['video_url']) ?>" target="_blank" rel="noopener"><?= $e(__('Collegati')) ?></a>
                  <?php endif; ?>
                </div>
                <?php if (!empty($meeting['book_title'])): ?>
                  <div class="text-xs text-gray-400 mt-0.5"><i class="fas fa-book mr-1"></i><?= $e($meeting['book_title']) ?></div>
                <?php endif; ?>
                <?php if (!empty($meeting['agenda'])): ?>
                  <p class="text-sm text-gray-500 mt-1 whitespace-pre-line"><?= $e($meeting['agenda']) ?></p>
                <?php endif; ?>
                <?php if (!empty($meeting['minutes']) && ($isMember || $canManage)): ?>
                  <details class="mt-1 text-sm text-gray-500"><summary class="cursor-pointer text-xs text-gray-400"><?= $e(__('Verbale')) ?></summary><p class="whitespace-pre-line mt-1"><?= $e($meeting['minutes']) ?></p></details>
                <?php endif; ?>
              </div>
              <div class="text-right text-xs text-gray-400 whitespace-nowrap">
                <div><?= (int) $meeting['yes_count'] ?> <?= $e(__('sì')) ?><?= $meeting['seats'] !== null ? ' / ' . (int) $meeting['seats'] . ' ' . $e(__('posti')) : '' ?></div>
                <?php if ((int) $meeting['maybe_count'] > 0): ?><div><?= (int) $meeting['maybe_count'] ?> <?= $e(__('forse')) ?></div><?php endif; ?>
              </div>
            </div>
            <?php if ($isMember && $meeting['status'] === 'scheduled' && !$isPast): ?>
              <form method="post" action="<?= $e(url('/book-club/' . $slug . '/meetings/' . (int) $meeting['id'] . '/rsvp')) ?>" class="flex items-center gap-2 mt-2">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <span class="text-xs text-gray-400 mr-1"><?= $e(__('Parteciperai?')) ?></span>
                <button name="response" value="yes" class="px-3 py-1 text-xs rounded-full bg-green-50 hover:bg-green-100 text-green-700"><?= $e(__('Sì')) ?></button>
                <button name="response" value="maybe" class="px-3 py-1 text-xs rounded-full bg-yellow-50 hover:bg-yellow-100 text-yellow-700"><?= $e(__('Forse')) ?></button>
                <button name="response" value="no" class="px-3 py-1 text-xs rounded-full bg-gray-50 hover:bg-gray-100 text-gray-600"><?= $e(__('No')) ?></button>
              </form>
            <?php endif; ?>
            <?php if ($canManage && $meeting['status'] === 'scheduled'): ?>
              <form method="post" action="<?= $e(url('/book-club/' . $slug . '/meetings/' . (int) $meeting['id'] . '/status')) ?>" class="flex items-center gap-2 mt-2">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <button name="status" value="done" class="text-xs text-gray-400 hover:text-gray-600 underline"><?= $e(__('Segna come svolto')) ?></button>
                <button name="status" value="cancelled" class="text-xs text-gray-400 hover:text-red-600 underline"
                        onclick="return confirm('<?= $e(__('Annullare questo incontro?')) ?>');"><?= $e(__('Annulla incontro')) ?></button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <?php if ($canManage): ?>
          <details class="mt-4 border-t pt-4">
            <summary class="text-sm font-medium text-blue-600 cursor-pointer"><?= $e(__('Pianifica un incontro')) ?></summary>
            <form method="post" action="<?= $e(url('/book-club/' . $slug . '/meetings/new')) ?>" class="mt-3 space-y-3 text-sm">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <input type="text" name="title" required maxlength="190" placeholder="<?= $e(__('Titolo dell\'incontro')) ?>"
                     class="w-full border border-gray-300 rounded-lg px-3 py-2">
              <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <input type="datetime-local" name="starts_at" required class="border border-gray-300 rounded-lg px-2 py-1.5" title="<?= $e(__('Inizio')) ?>">
                <input type="datetime-local" name="ends_at" class="border border-gray-300 rounded-lg px-2 py-1.5" title="<?= $e(__('Fine (facoltativa)')) ?>">
                <select name="kind" class="border border-gray-300 rounded-lg px-2 py-1.5">
                  <?php foreach ($kindLabels as $value => $label): ?>
                    <option value="<?= $e($value) ?>"><?= $e($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <input type="number" name="seats" min="1" placeholder="<?= $e(__('Posti (illimitati)')) ?>" class="border border-gray-300 rounded-lg px-2 py-1.5">
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <input type="text" name="location" maxlength="255" placeholder="<?= $e(__('Luogo')) ?>" class="border border-gray-300 rounded-lg px-3 py-2">
                <input type="url" name="video_url" maxlength="500" placeholder="<?= $e(__('Link videoconferenza')) ?>" class="border border-gray-300 rounded-lg px-3 py-2">
              </div>
              <select name="club_book_id" class="w-full border border-gray-300 rounded-lg px-2 py-1.5">
                <option value=""><?= $e(__('Nessun libro collegato')) ?></option>
                <?php foreach ($books as $book): ?>
                  <?php if ($book['state'] === \App\Plugins\BookClub\BookClubPlugin::STATE_PENDING) { continue; } ?>
                  <option value="<?= (int) $book['id'] ?>"><?= $e($book['titolo']) ?></option>
                <?php endforeach; ?>
              </select>
              <textarea name="agenda" rows="2" maxlength="5000" placeholder="<?= $e(__('Ordine del giorno (facoltativo)')) ?>"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2"></textarea>
              <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg"><?= $e(__('Crea incontro')) ?></button>
            </form>
          </details>
        <?php endif; ?>
      </section>

      <?php foreach (($modulePanelsMain ?? []) as $panelHtml): ?>
        <?= $panelHtml /* module-rendered, already escaped inside the partial */ ?>
      <?php endforeach; ?>
    </div>

    <!-- Sidebar -->
    <div class="space-y-8">
      <?php if ($nextMeeting !== null): ?>
        <section class="bg-white rounded-xl shadow p-6">
          <h2 class="text-sm font-semibold text-gray-400 uppercase mb-3"><?= $e(__('Prossimo incontro')) ?></h2>
          <div class="font-medium text-gray-900"><?= $e($nextMeeting['title']) ?></div>
          <div class="text-sm text-gray-500 mt-1"><i class="far fa-clock mr-1"></i><?= $e(date('d/m/Y H:i', (int) strtotime((string) $nextMeeting['starts_at']))) ?></div>
          <?php if (!empty($nextMeeting['location'])): ?>
            <div class="text-sm text-gray-500"><i class="fas fa-map-marker-alt mr-1"></i><?= $e($nextMeeting['location']) ?></div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <?php if ($canManage): ?>
        <section class="bg-white rounded-xl shadow p-6">
          <h2 class="text-sm font-semibold text-gray-400 uppercase mb-3"><?= $e(__('Invita un lettore')) ?></h2>
          <form method="post" action="<?= $e(url('/book-club/' . $slug . '/invite')) ?>" class="space-y-2">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <input type="email" name="email" required placeholder="email@esempio.it"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <button type="submit" class="w-full px-4 py-2 bg-gray-900 hover:bg-gray-700 text-white text-sm font-medium rounded-lg"><?= $e(__('Invia invito')) ?></button>
          </form>
        </section>
      <?php endif; ?>

      <?php if ($members !== []): ?>
        <section class="bg-white rounded-xl shadow p-6">
          <h2 class="text-sm font-semibold text-gray-400 uppercase mb-3"><?= $e(__('Membri')) ?></h2>
          <ul class="space-y-2 text-sm">
            <?php foreach ($members as $member): ?>
              <?php if (!in_array($member['status'], ['active', 'pending'], true)) { continue; } ?>
              <li class="flex items-center justify-between">
                <span class="text-gray-700"><?= $e($member['nome'] . ' ' . $member['cognome']) ?></span>
                <span class="flex items-center gap-2">
                  <?php if (in_array($member['role_slug'], ['owner', 'moderator'], true)): ?>
                    <span class="text-xs text-gray-400"><?= $e($member['role_name']) ?></span>
                  <?php endif; ?>
                  <?php if ($member['status'] === 'pending' && $canManage): ?>
                    <form method="post" action="<?= $e(url('/book-club/' . $slug . '/members/' . (int) $member['id'] . '/approve')) ?>" class="inline-flex gap-1">
                      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                      <button name="action" value="approve" class="px-2 py-0.5 text-xs bg-green-50 text-green-700 rounded"><?= $e(__('Approva')) ?></button>
                      <button name="action" value="reject" class="px-2 py-0.5 text-xs bg-red-50 text-red-700 rounded"><?= $e(__('Rifiuta')) ?></button>
                    </form>
                  <?php elseif ($member['status'] === 'pending'): ?>
                    <span class="text-xs text-yellow-600"><?= $e(__('in attesa')) ?></span>
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

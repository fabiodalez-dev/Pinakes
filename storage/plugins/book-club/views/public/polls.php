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
 * @var bool $canCreate  granular polls.create permission → creation form
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
<div class="max-w-3xl mx-auto px-4 py-10">
  <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="text-sm text-gray-500 hover:text-gray-700">
    <i class="fas fa-arrow-left mr-1"></i><?= $e($club['name']) ?>
  </a>

  <?php if (!empty($flash)): ?>
    <div class="mt-4 px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <div class="bg-white rounded-xl shadow p-6 mt-4">
    <h1 class="text-2xl font-bold text-gray-900 mb-4"><?= $e(__('Votazioni')) ?></h1>
    <?php if (empty($polls)): ?>
      <p class="text-sm text-gray-400"><?= $e(__('Nessuna votazione al momento.')) ?></p>
    <?php endif; ?>
    <?php foreach ($polls as $poll): ?>
      <div class="flex items-center justify-between border-t py-3">
        <div>
          <a class="font-medium text-blue-600 hover:underline" href="<?= $e(url('/book-club/' . $slug . '/polls/' . (int) $poll['id'])) ?>"><?= $e($poll['title']) ?></a>
          <div class="text-xs text-gray-400 mt-0.5">
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
        <span class="px-2 py-1 text-xs rounded-full <?= $poll['status'] === 'open' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500' ?>">
          <?= $poll['status'] === 'open' ? $e(__('Aperta')) : $e(__('Chiusa')) ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($canCreate): ?>
    <div class="bg-white rounded-xl shadow p-6 mt-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-1"><?= $e(__('Apri una nuova votazione')) ?></h2>
      <p class="text-sm text-gray-500 mb-4"><?= $e(__('Tutte le modalità avanzate: stelle, classifica, eliminazione, voto ponderato, quorum e spareggio.')) ?></p>

      <?php if (count($eligible) < 2): ?>
        <p class="text-sm text-gray-400"><?= $e(__('Servono almeno due proposte per aprire una votazione.')) ?></p>
      <?php else: ?>
        <form method="post" action="<?= $e(url('/book-club/' . $slug . '/polls/new')) ?>" class="space-y-3 text-sm">
          <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
          <input type="text" name="title" maxlength="190" placeholder="<?= $e(__('Titolo (es. Votazione autunno 2026)')) ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2">
          <textarea name="description" rows="2" maxlength="3000" placeholder="<?= $e(__('Descrizione (facoltativa)')) ?>"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2"></textarea>

          <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <select name="mode" class="border border-gray-300 rounded-lg px-2 py-1.5" title="<?= $e(__('Modalità di voto')) ?>">
              <?php foreach ($modeLabels as $value => $label): ?>
                <option value="<?= $e($value) ?>"><?= $e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="number" name="votes_per_member" min="1" max="20" value="3"
                   title="<?= $e(__('Voti per membro (preferenza multipla e voto ponderato)')) ?>"
                   class="border border-gray-300 rounded-lg px-2 py-1.5">
            <select name="anonymity" class="border border-gray-300 rounded-lg px-2 py-1.5">
              <option value="public"><?= $e(__('Voto pubblico')) ?></option>
              <option value="secret"><?= $e(__('Voto segreto')) ?></option>
            </select>
            <input type="datetime-local" name="closes_at" class="border border-gray-300 rounded-lg px-2 py-1.5"
                   title="<?= $e(__('Scadenza (facoltativa)')) ?>">
          </div>

          <div class="grid grid-cols-2 gap-3">
            <input type="number" name="quorum_pct" min="1" max="100" placeholder="<?= $e(__('Quorum % (facoltativo)')) ?>"
                   title="<?= $e(__('Percentuale minima di membri attivi che devono votare perché ci sia un vincitore')) ?>"
                   class="border border-gray-300 rounded-lg px-2 py-1.5">
            <select name="tiebreak" class="border border-gray-300 rounded-lg px-2 py-1.5" title="<?= $e(__('Spareggio in caso di parità')) ?>">
              <option value="oldest_proposal"><?= $e(__('Spareggio: vince la proposta più antica')) ?></option>
              <option value="random"><?= $e(__('Spareggio: sorteggio deterministico')) ?></option>
              <option value="admin"><?= $e(__('Spareggio: decide un moderatore')) ?></option>
            </select>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <label class="block">
              <span class="text-xs text-gray-500"><?= $e(__('Peso del voto del fondatore')) ?></span>
              <input type="number" name="weight_owner" step="0.5" min="1" max="5" value="2.0"
                     title="<?= $e(__('Solo per il voto ponderato: quanto vale il voto del fondatore (da 1 a 5)')) ?>"
                     class="w-full border border-gray-300 rounded-lg px-2 py-1.5">
            </label>
            <label class="block">
              <span class="text-xs text-gray-500"><?= $e(__('Peso del voto dei moderatori')) ?></span>
              <input type="number" name="weight_moderator" step="0.5" min="1" max="5" value="1.5"
                     title="<?= $e(__('Solo per il voto ponderato: quanto vale il voto dei moderatori (da 1 a 5)')) ?>"
                     class="w-full border border-gray-300 rounded-lg px-2 py-1.5">
            </label>
          </div>
          <p class="text-xs text-gray-400"><?= $e(__('I pesi si applicano solo alla modalità «Voto ponderato»; gli altri membri valgono sempre 1,0.')) ?></p>

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

      <div class="mt-6 border-t pt-4 text-xs text-gray-500 space-y-1">
        <div class="font-semibold text-gray-600 uppercase tracking-wide mb-1"><?= $e(__('Le modalità in breve')) ?></div>
        <?php foreach ($modeLabels as $value => $label): ?>
          <p><span class="font-medium text-gray-700"><?= $e($label) ?></span>: <?= $e($modeHelp[$value]) ?></p>
        <?php endforeach; ?>
        <p class="pt-1"><span class="font-medium text-gray-700"><?= $e(__('Quorum')) ?></span>: <?= $e(__('se alla chiusura i votanti sono meno della percentuale indicata, non c\'è vincitore e le proposte tornano disponibili.')) ?></p>
      </div>
    </div>
  <?php endif; ?>
</div>

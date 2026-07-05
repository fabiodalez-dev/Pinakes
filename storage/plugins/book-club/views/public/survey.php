<?php
/**
 * Book Club — surveys module: single survey page.
 *  - draft  → question builder (managers only; progressive enhancement,
 *             plain forms: add / move up-down / delete / publish);
 *  - open   → answer form for active members who have not answered yet,
 *             live results + close/export for managers;
 *  - closed → aggregated results (counts per option, average for scales,
 *             text answers — anonymized when the survey is anonymous).
 *
 * @var array<string, mixed> $club
 * @var array<string, mixed> $survey
 * @var list<array{key: string, type: string, label: string, options: list<string>, required: bool}> $schema
 * @var bool $isMember
 * @var bool $canManage
 * @var array<string, mixed>|null $myAnswer
 * @var array{total: int, questions: list<array<string, mixed>>}|null $results
 * @var array<string, string> $typeLabels
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$surveyId = (int) $survey['id'];
$status = (string) $survey['status'];
$anonymous = (int) ($survey['anonymous'] ?? 0) === 1;
$csrf = \App\Support\Csrf::ensureToken();
$base = url('/book-club/' . $slug . '/surveys/' . $surveyId);
$statusBadges = [
    'draft' => ['bg-yellow-50 text-yellow-700', __('Bozza')],
    'open' => ['bg-green-50 text-green-700', __('Aperto')],
    'closed' => ['bg-gray-100 text-gray-600', __('Chiuso')],
];
[$badgeClass, $badgeLabel] = $statusBadges[$status] ?? $statusBadges['draft'];
$yesNoLabels = ['yes' => __('Sì'), 'no' => __('No')];
?>
<div class="max-w-4xl mx-auto px-4 py-10">
  <a href="<?= $e(url('/book-club/' . $slug . '/surveys')) ?>" class="text-sm text-gray-500 hover:text-gray-700">
    <i class="fas fa-arrow-left mr-1"></i><?= $e(__('Tutti i questionari')) ?>
  </a>

  <!-- Header -->
  <div class="bg-white rounded-xl shadow overflow-hidden mt-4 mb-8">
    <div class="h-2" style="background: <?= $e($club['color']) ?>"></div>
    <div class="p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900"><?= $e($survey['title']) ?></h1>
          <div class="flex flex-wrap items-center gap-3 mt-2 text-xs text-gray-400">
            <span class="inline-flex items-center px-2 py-0.5 rounded-full <?= $e($badgeClass) ?>"><?= $e($badgeLabel) ?></span>
            <?php if (!empty($survey['book_title'])): ?>
              <span><i class="fas fa-book mr-1"></i><?= $e($survey['book_title']) ?></span>
            <?php endif; ?>
            <?php if ($anonymous): ?>
              <span><i class="fas fa-user-secret mr-1"></i><?= $e(__('Anonimo')) ?></span>
            <?php endif; ?>
            <span><i class="fas fa-reply mr-1"></i><?= $e(sprintf(__('%d risposte'), (int) $survey['answer_count'])) ?></span>
            <?php if (!empty($survey['closes_at'])): ?>
              <span><i class="far fa-clock mr-1"></i><?= $e(__('Chiude il')) ?> <?= $e(date('d/m/Y H:i', (int) strtotime((string) $survey['closes_at']))) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($canManage): ?>
          <div class="flex flex-wrap items-center gap-2">
            <?php if ($status === 'open'): ?>
              <form method="post" action="<?= $e($base . '/close') ?>"
                    onsubmit="return confirm('<?= $e(__('Chiudere il questionario? Non accetterà più risposte.')) ?>');">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <button type="submit" class="px-3 py-1.5 text-xs bg-red-50 hover:bg-red-100 text-red-700 rounded-lg">
                  <i class="fas fa-lock mr-1"></i><?= $e(__('Chiudi questionario')) ?>
                </button>
              </form>
            <?php endif; ?>
            <?php if ($status !== 'draft'): ?>
              <a href="<?= $e($base . '/export.csv') ?>" class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg">
                <i class="fas fa-file-csv mr-1"></i><?= $e(__('Esporta CSV')) ?>
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if ($status === 'draft' && $canManage): ?>
    <!-- ============================ BUILDER ============================ -->
    <section class="bg-white rounded-xl shadow p-6 mb-8">
      <h2 class="text-lg font-semibold text-gray-900 mb-1"><?= $e(__('Domande')) ?></h2>
      <p class="text-xs text-gray-400 mb-4"><?= $e(__('Dopo la pubblicazione le domande non saranno più modificabili.')) ?></p>

      <?php if ($schema === []): ?>
        <p class="text-sm text-gray-400 mb-4"><?= $e(__('Nessuna domanda: aggiungi la prima qui sotto.')) ?></p>
      <?php endif; ?>

      <?php foreach ($schema as $i => $q): ?>
        <div class="border rounded-lg px-4 py-3 mb-3">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <span class="text-xs text-gray-400 mr-2"><?= (int) $i + 1 ?>.</span>
              <span class="font-medium text-gray-900"><?= $e($q['label']) ?></span>
              <?php if ($q['required']): ?><span class="text-red-500 ml-1">*</span><?php endif; ?>
              <div class="text-xs text-gray-400 mt-1">
                <?= $e($typeLabels[$q['type']] ?? $q['type']) ?>
                <?php if ($q['options'] !== []): ?>
                  · <?= $e(implode(' / ', $q['options'])) ?>
                <?php endif; ?>
              </div>
            </div>
            <div class="flex items-center gap-1">
              <?php if ($i > 0): ?>
                <form method="post" action="<?= $e($base . '/questions/' . (int) $i . '/move') ?>">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <input type="hidden" name="dir" value="up">
                  <button type="submit" class="px-2 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-lg" title="<?= $e(__('Sposta su')) ?>"><i class="fas fa-arrow-up"></i></button>
                </form>
              <?php endif; ?>
              <?php if ($i < count($schema) - 1): ?>
                <form method="post" action="<?= $e($base . '/questions/' . (int) $i . '/move') ?>">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <input type="hidden" name="dir" value="down">
                  <button type="submit" class="px-2 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-lg" title="<?= $e(__('Sposta giù')) ?>"><i class="fas fa-arrow-down"></i></button>
                </form>
              <?php endif; ?>
              <form method="post" action="<?= $e($base . '/questions/' . (int) $i . '/delete') ?>"
                    onsubmit="return confirm('<?= $e(__('Eliminare questa domanda?')) ?>');">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <button type="submit" class="px-2 py-1.5 text-xs text-red-600 hover:bg-red-50 rounded-lg" title="<?= $e(__('Elimina')) ?>"><i class="fas fa-trash"></i></button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <!-- Add question -->
      <form method="post" action="<?= $e($base . '/questions/add') ?>" class="mt-5 border-t pt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Nuova domanda')) ?> *</label>
          <input type="text" name="label" maxlength="190" required placeholder="<?= $e(__('Es. Qual è il tuo personaggio preferito?')) ?>"
                 class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Tipo di domanda')) ?></label>
          <select name="type" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
            <?php foreach ($typeLabels as $typeKey => $typeLabel): ?>
              <option value="<?= $e($typeKey) ?>"><?= $e($typeLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-600 sm:pt-6">
          <input type="checkbox" name="required" value="1" class="rounded">
          <?= $e(__('Risposta obbligatoria')) ?>
        </label>
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Opzioni (una per riga, solo per scelta singola/multipla)')) ?></label>
          <textarea name="options" rows="3" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"
                    placeholder="<?= $e(__('Una opzione per riga')) ?>"></textarea>
        </div>
        <div class="sm:col-span-2">
          <button type="submit" class="px-4 py-2 bg-gray-900 hover:bg-gray-800 text-white text-sm font-medium rounded-lg">
            <i class="fas fa-plus mr-1"></i><?= $e(__('Aggiungi domanda')) ?>
          </button>
        </div>
      </form>
    </section>

    <!-- Publish -->
    <section class="bg-white rounded-xl shadow p-6">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-gray-500"><?= $e(__('Pronto? La pubblicazione apre il questionario ai membri e congela le domande.')) ?></p>
        <form method="post" action="<?= $e($base . '/publish') ?>"
              onsubmit="return confirm('<?= $e(__('Pubblicare il questionario? Le domande non saranno più modificabili.')) ?>');">
          <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
          <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg" <?= $schema === [] ? 'disabled' : '' ?>>
            <i class="fas fa-paper-plane mr-1"></i><?= $e(__('Pubblica')) ?>
          </button>
        </form>
      </div>
    </section>

  <?php else: ?>

    <?php if ($status === 'open'): ?>
      <?php if ($isMember && $myAnswer === null): ?>
        <!-- ========================= ANSWER FORM ========================= -->
        <section class="bg-white rounded-xl shadow p-6 mb-8">
          <h2 class="text-lg font-semibold text-gray-900 mb-1"><?= $e(__('Le tue risposte')) ?></h2>
          <?php if ($anonymous): ?>
            <p class="text-xs text-gray-400 mb-4"><i class="fas fa-user-secret mr-1"></i><?= $e(__('Questionario anonimo: il tuo nome non sarà mai mostrato. La partecipazione viene registrata solo per garantire una risposta a testa.')) ?></p>
          <?php else: ?>
            <p class="text-xs text-gray-400 mb-4"><?= $e(__('Puoi rispondere una sola volta.')) ?></p>
          <?php endif; ?>

          <form method="post" action="<?= $e($base . '/answer') ?>">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <?php foreach ($schema as $i => $q): ?>
              <?php $field = 'q_' . $q['key']; ?>
              <div class="border-b last:border-b-0 py-4">
                <label class="block text-sm font-medium text-gray-900 mb-2">
                  <?= (int) $i + 1 ?>. <?= $e($q['label']) ?>
                  <?php if ($q['required']): ?><span class="text-red-500">*</span><?php endif; ?>
                </label>

                <?php if ($q['type'] === 'short_text'): ?>
                  <input type="text" name="<?= $e($field) ?>" maxlength="500" <?= $q['required'] ? 'required' : '' ?>
                         class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">

                <?php elseif ($q['type'] === 'long_text'): ?>
                  <textarea name="<?= $e($field) ?>" rows="4" maxlength="5000" <?= $q['required'] ? 'required' : '' ?>
                            class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"></textarea>

                <?php elseif ($q['type'] === 'single_choice'): ?>
                  <div class="space-y-1.5">
                    <?php foreach ($q['options'] as $option): ?>
                      <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="radio" name="<?= $e($field) ?>" value="<?= $e($option) ?>" <?= $q['required'] ? 'required' : '' ?>>
                        <?= $e($option) ?>
                      </label>
                    <?php endforeach; ?>
                  </div>

                <?php elseif ($q['type'] === 'multi_choice'): ?>
                  <div class="space-y-1.5">
                    <?php foreach ($q['options'] as $option): ?>
                      <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="<?= $e($field) ?>[]" value="<?= $e($option) ?>" class="rounded">
                        <?= $e($option) ?>
                      </label>
                    <?php endforeach; ?>
                  </div>

                <?php elseif ($q['type'] === 'scale_1_5'): ?>
                  <div class="flex items-center gap-4 text-sm text-gray-700">
                    <?php for ($v = 1; $v <= 5; $v++): ?>
                      <label class="flex flex-col items-center gap-1">
                        <input type="radio" name="<?= $e($field) ?>" value="<?= $v ?>" <?= $q['required'] ? 'required' : '' ?>>
                        <span class="text-xs text-gray-400"><?= $v ?></span>
                      </label>
                    <?php endfor; ?>
                  </div>

                <?php elseif ($q['type'] === 'yes_no'): ?>
                  <div class="flex items-center gap-6 text-sm text-gray-700">
                    <label class="flex items-center gap-2">
                      <input type="radio" name="<?= $e($field) ?>" value="yes" <?= $q['required'] ? 'required' : '' ?>>
                      <?= $e(__('Sì')) ?>
                    </label>
                    <label class="flex items-center gap-2">
                      <input type="radio" name="<?= $e($field) ?>" value="no">
                      <?= $e(__('No')) ?>
                    </label>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>

            <div class="pt-4">
              <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
                <i class="fas fa-paper-plane mr-1"></i><?= $e(__('Invia le risposte')) ?>
              </button>
            </div>
          </form>
        </section>
      <?php elseif ($isMember && $myAnswer !== null): ?>
        <div class="bg-white rounded-xl shadow p-6 mb-8 text-sm text-gray-500">
          <i class="fas fa-check-circle text-green-500 mr-1"></i>
          <?= $e(__('Hai già risposto a questo questionario.')) ?>
          <?php if ($results === null): ?>
            <?= $e(__('I risultati saranno visibili alla chiusura.')) ?>
          <?php endif; ?>
        </div>
      <?php elseif (!$isMember && !$canManage): ?>
        <p class="text-sm text-gray-400 mb-8"><?= $e(__('Solo i membri attivi del club possono rispondere ai questionari.')) ?></p>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($results !== null): ?>
      <!-- =========================== RESULTS =========================== -->
      <section class="bg-white rounded-xl shadow p-6">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
          <h2 class="text-lg font-semibold text-gray-900"><i class="fas fa-chart-simple mr-2 text-gray-400"></i><?= $e(__('Risultati')) ?></h2>
          <span class="text-xs text-gray-400"><?= $e(sprintf(__('%d risposte'), (int) $results['total'])) ?><?= $status === 'open' ? ' · ' . $e(__('In corso')) : '' ?></span>
        </div>

        <?php if ((int) $results['total'] === 0): ?>
          <p class="text-sm text-gray-400"><?= $e(__('Nessuna risposta ricevuta.')) ?></p>
        <?php endif; ?>

        <?php foreach ($results['questions'] as $i => $item): ?>
          <?php
            $q = $item['q'];
            $answered = (int) $item['answered'];
            $maxCount = 1;
            foreach ($item['counts'] as $n) {
                $maxCount = max($maxCount, (int) $n);
            }
          ?>
          <div class="border-b last:border-b-0 py-4">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
              <h3 class="text-sm font-medium text-gray-900"><?= (int) $i + 1 ?>. <?= $e($q['label']) ?></h3>
              <span class="text-xs text-gray-400"><?= $e(sprintf(__('%d risposte'), $answered)) ?></span>
            </div>

            <?php if (in_array($q['type'], ['single_choice', 'multi_choice', 'yes_no', 'scale_1_5'], true)): ?>
              <?php if ($q['type'] === 'scale_1_5' && $item['avg'] !== null): ?>
                <p class="text-xs text-gray-500 mb-2"><?= $e(__('Media')) ?>: <span class="font-semibold text-gray-800"><?= $e(number_format((float) $item['avg'], 2)) ?></span> / 5</p>
              <?php endif; ?>
              <?php foreach ($item['counts'] as $optionKey => $count): ?>
                <?php $optionLabel = $q['type'] === 'yes_no' ? ($yesNoLabels[(string) $optionKey] ?? (string) $optionKey) : (string) $optionKey; ?>
                <div class="flex items-center gap-3 mb-1.5">
                  <div class="w-40 text-xs text-gray-600 truncate shrink-0" title="<?= $e($optionLabel) ?>"><?= $e($optionLabel) ?></div>
                  <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full rounded-full" style="width: <?= number_format((int) $count / $maxCount * 100, 1, '.', '') ?>%; background: <?= $e($club['color']) ?>"></div>
                  </div>
                  <div class="w-8 text-right text-xs font-medium text-gray-700"><?= (int) $count ?></div>
                </div>
              <?php endforeach; ?>

            <?php else: ?>
              <?php if ($item['texts'] === []): ?>
                <p class="text-xs text-gray-400"><?= $e(__('Nessuna risposta testuale.')) ?></p>
              <?php endif; ?>
              <ul class="space-y-2">
                <?php foreach ($item['texts'] as $entry): ?>
                  <li class="bg-gray-50 rounded-lg px-3 py-2 text-sm text-gray-700">
                    <?= nl2br($e($entry['text'])) ?>
                    <div class="text-xs text-gray-400 mt-1">
                      — <?= $entry['author'] !== null && $entry['author'] !== '' ? $e($entry['author']) : $e(__('Anonimo')) ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </section>
    <?php elseif ($status === 'closed'): ?>
      <p class="text-sm text-gray-400"><?= $e(__('Nessun risultato disponibile.')) ?></p>
    <?php endif; ?>

  <?php endif; ?>
</div>

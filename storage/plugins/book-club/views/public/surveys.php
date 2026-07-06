<?php
/**
 * Book Club — surveys module: list page. Open surveys (answer/status),
 * closed surveys (results), manager-only drafts and the create-draft form.
 *
 * @var array<string, mixed> $club
 * @var array{open: list<array<string, mixed>>, draft: list<array<string, mixed>>, closed: list<array<string, mixed>>} $grouped
 * @var bool $isMember
 * @var bool $canManage
 * @var list<int> $answeredIds
 * @var list<array{id: int|string, titolo: string}> $books
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
$base = url('/book-club/' . $slug . '/surveys');
?>
<div class="max-w-4xl mx-auto px-4 py-10">
  <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="text-sm text-gray-500 hover:text-gray-700">
    <i class="fas fa-arrow-left mr-1"></i><?= $e(__('Torna al club')) ?>
  </a>

  <h1 class="text-2xl font-bold text-gray-900 flex items-center mt-4 mb-6">
    <span class="inline-block w-3 h-3 rounded-full mr-3" style="background: <?= $e($club['color']) ?>"></span>
    <?= $e(__('Questionari')) ?> — <?= $e($club['name']) ?>
  </h1>

  <?php if (!empty($flash)): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Open surveys -->
  <section class="bg-white rounded-xl shadow p-6 mb-8">
    <h2 class="text-lg font-semibold text-gray-900 mb-4"><i class="fas fa-clipboard-list mr-2 text-gray-400"></i><?= $e(__('Questionari aperti')) ?></h2>
    <?php if ($grouped['open'] === []): ?>
      <p class="text-sm text-gray-400"><?= $e(__('Nessun questionario aperto al momento.')) ?></p>
    <?php endif; ?>
    <?php foreach ($grouped['open'] as $survey): ?>
      <?php
        $answered = in_array((int) $survey['id'], $answeredIds, true);
        $scheduled = \App\Plugins\BookClub\SurveyRepo::notYetOpen($survey);
      ?>
      <div class="border rounded-lg px-4 py-3 mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
          <a href="<?= $e($base . '/' . (int) $survey['id']) ?>" class="font-medium text-gray-900 hover:text-blue-600"><?= $e($survey['title']) ?></a>
          <?php if ($scheduled): ?>
            <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-blue-50 text-blue-700"><i class="far fa-clock mr-1"></i><?= $e(__('Programmato')) ?></span>
          <?php endif; ?>
          <div class="text-xs text-gray-400 mt-1 flex flex-wrap gap-x-4 gap-y-1">
            <?php if (!empty($survey['book_title'])): ?>
              <span><i class="fas fa-book mr-1"></i><?= $e($survey['book_title']) ?></span>
            <?php endif; ?>
            <?php if ((int) $survey['anonymous'] === 1): ?>
              <span><i class="fas fa-user-secret mr-1"></i><?= $e(__('Anonimo')) ?></span>
            <?php endif; ?>
            <span><i class="fas fa-reply mr-1"></i><?= $e(sprintf(__('%d risposte'), (int) $survey['answer_count'])) ?></span>
            <?php if ($scheduled): ?>
              <span><i class="far fa-clock mr-1"></i><?= $e(__('Apre il')) ?> <?= $e(date('d/m/Y H:i', (int) strtotime((string) $survey['opens_at']))) ?></span>
            <?php endif; ?>
            <?php if (!empty($survey['closes_at'])): ?>
              <span><i class="far fa-clock mr-1"></i><?= $e(__('Chiude il')) ?> <?= $e(date('d/m/Y H:i', (int) strtotime((string) $survey['closes_at']))) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div>
          <?php if ($answered): ?>
            <span class="inline-flex items-center px-3 py-1.5 text-xs bg-green-50 text-green-700 rounded-lg"><i class="fas fa-check mr-1"></i><?= $e(__('Hai già risposto')) ?></span>
          <?php elseif ($isMember && !$scheduled): ?>
            <a href="<?= $e($base . '/' . (int) $survey['id']) ?>" class="inline-flex items-center px-3 py-1.5 text-xs bg-blue-600 hover:bg-blue-700 text-white rounded-lg"><?= $e(__('Rispondi al questionario')) ?></a>
          <?php else: ?>
            <a href="<?= $e($base . '/' . (int) $survey['id']) ?>" class="inline-flex items-center px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg"><?= $e(__('Apri')) ?></a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </section>

  <!-- Drafts (managers) -->
  <?php if ($canManage && $grouped['draft'] !== []): ?>
    <section class="bg-white rounded-xl shadow p-6 mb-8">
      <h2 class="text-lg font-semibold text-gray-900 mb-4"><i class="fas fa-pen-ruler mr-2 text-gray-400"></i><?= $e(__('Bozze')) ?></h2>
      <?php foreach ($grouped['draft'] as $survey): ?>
        <?php $draftBase = $base . '/' . (int) $survey['id']; ?>
        <div class="border border-dashed rounded-lg px-4 py-3 mb-3">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
              <span class="font-medium text-gray-900"><?= $e($survey['title']) ?></span>
              <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-yellow-50 text-yellow-700"><?= $e(__('Bozza')) ?></span>
              <?php if (!empty($survey['book_title'])): ?>
                <div class="text-xs text-gray-400 mt-1"><i class="fas fa-book mr-1"></i><?= $e($survey['book_title']) ?></div>
              <?php endif; ?>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <a href="<?= $e($draftBase) ?>" class="inline-flex items-center px-3 py-1.5 text-xs bg-gray-900 hover:bg-gray-800 text-white rounded-lg">
                <i class="fas fa-pen mr-1"></i><?= $e(__('Modifica le domande')) ?>
              </a>
              <form method="post" action="<?= $e($draftBase . '/delete') ?>"
                    onsubmit="return confirm('<?= $e(__('Eliminare questa bozza? Le domande andranno perse.')) ?>');">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <button type="submit" class="inline-flex items-center px-3 py-1.5 text-xs text-red-600 hover:bg-red-50 rounded-lg">
                  <i class="fas fa-trash mr-1"></i><?= $e(__('Elimina bozza')) ?>
                </button>
              </form>
            </div>
          </div>
          <details class="mt-3">
            <summary class="text-xs text-blue-600 cursor-pointer hover:underline"><i class="fas fa-sliders mr-1"></i><?= $e(__('Modifica dettagli')) ?></summary>
            <form method="post" action="<?= $e($draftBase . '/update') ?>" class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3 border-t pt-4">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <div class="sm:col-span-2">
                <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Titolo')) ?> *</label>
                <input type="text" name="title" maxlength="190" required value="<?= $e($survey['title']) ?>"
                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Libro collegato (facoltativo)')) ?></label>
                <select name="club_book_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
                  <option value=""><?= $e(__('Nessun libro (questionario del club)')) ?></option>
                  <?php foreach ($books as $book): ?>
                    <option value="<?= (int) $book['id'] ?>" <?= (int) $book['id'] === (int) ($survey['club_book_id'] ?? 0) ? 'selected' : '' ?>><?= $e($book['titolo']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <label class="flex items-center gap-2 text-sm text-gray-600 sm:pt-6">
                <input type="checkbox" name="anonymous" value="1" class="rounded" <?= (int) ($survey['anonymous'] ?? 0) === 1 ? 'checked' : '' ?>>
                <?= $e(__('Questionario anonimo (i nomi dei rispondenti non saranno mai mostrati né esportati)')) ?>
              </label>
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Apertura programmata (facoltativa)')) ?></label>
                <input type="datetime-local" name="opens_at" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"
                       value="<?= !empty($survey['opens_at']) ? $e(date('Y-m-d\TH:i', (int) strtotime((string) $survey['opens_at']))) : '' ?>">
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Chiusura automatica (facoltativa)')) ?></label>
                <input type="datetime-local" name="closes_at" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"
                       value="<?= !empty($survey['closes_at']) ? $e(date('Y-m-d\TH:i', (int) strtotime((string) $survey['closes_at']))) : '' ?>">
              </div>
              <div class="sm:col-span-2">
                <button type="submit" class="px-4 py-2 bg-gray-900 hover:bg-gray-800 text-white text-sm font-medium rounded-lg">
                  <i class="fas fa-check mr-1"></i><?= $e(__('Salva modifiche')) ?>
                </button>
              </div>
            </form>
          </details>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <!-- Closed surveys -->
  <section class="bg-white rounded-xl shadow p-6 mb-8">
    <h2 class="text-lg font-semibold text-gray-900 mb-4"><i class="fas fa-box-archive mr-2 text-gray-400"></i><?= $e(__('Questionari chiusi')) ?></h2>
    <?php if ($grouped['closed'] === []): ?>
      <p class="text-sm text-gray-400"><?= $e(__('Nessun questionario chiuso.')) ?></p>
    <?php endif; ?>
    <?php foreach ($grouped['closed'] as $survey): ?>
      <div class="border rounded-lg px-4 py-3 mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
          <a href="<?= $e($base . '/' . (int) $survey['id']) ?>" class="font-medium text-gray-700 hover:text-blue-600"><?= $e($survey['title']) ?></a>
          <div class="text-xs text-gray-400 mt-1">
            <?php if (!empty($survey['book_title'])): ?>
              <i class="fas fa-book mr-1"></i><?= $e($survey['book_title']) ?> ·
            <?php endif; ?>
            <?= $e(sprintf(__('%d risposte'), (int) $survey['answer_count'])) ?>
          </div>
        </div>
        <a href="<?= $e($base . '/' . (int) $survey['id']) ?>" class="inline-flex items-center px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg">
          <i class="fas fa-chart-simple mr-1"></i><?= $e(__('Risultati')) ?>
        </a>
      </div>
    <?php endforeach; ?>
  </section>

  <!-- Create draft (managers) -->
  <?php if ($canManage): ?>
    <section class="bg-white rounded-xl shadow p-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-1"><i class="fas fa-plus mr-2 text-gray-400"></i><?= $e(__('Nuovo questionario')) ?></h2>
      <p class="text-xs text-gray-400 mb-4"><?= $e(__('Il questionario viene creato come bozza: potrai aggiungere le domande e pubblicarlo quando è pronto.')) ?></p>
      <form method="post" action="<?= $e($base . '/create') ?>" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Titolo')) ?> *</label>
          <input type="text" name="title" maxlength="190" required placeholder="<?= $e(__('Es. Il finale ti ha convinto?')) ?>"
                 class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Libro collegato (facoltativo)')) ?></label>
          <select name="club_book_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
            <option value=""><?= $e(__('Nessun libro (questionario del club)')) ?></option>
            <?php foreach ($books as $book): ?>
              <option value="<?= (int) $book['id'] ?>"><?= $e($book['titolo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Apertura programmata (facoltativa)')) ?></label>
          <input type="datetime-local" name="opens_at" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Chiusura automatica (facoltativa)')) ?></label>
          <input type="datetime-local" name="closes_at" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-600 sm:col-span-2">
          <input type="checkbox" name="anonymous" value="1" class="rounded">
          <?= $e(__('Questionario anonimo (i nomi dei rispondenti non saranno mai mostrati né esportati)')) ?>
        </label>
        <div class="sm:col-span-2">
          <button type="submit" class="px-4 py-2 bg-gray-900 hover:bg-gray-800 text-white text-sm font-medium rounded-lg">
            <i class="fas fa-plus mr-1"></i><?= $e(__('Crea bozza')) ?>
          </button>
        </div>
      </form>
    </section>
  <?php endif; ?>
</div>

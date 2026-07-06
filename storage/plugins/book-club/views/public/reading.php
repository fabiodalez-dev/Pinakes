<?php
/**
 * Book Club — reading module: shared-reading page for one club book.
 * Sections + discussion dates, personal progress form, club aggregate,
 * manager-only inline section CRUD.
 *
 * @var array<string, mixed> $club
 * @var array<string, mixed> $book
 * @var array{key: string, label: string, color: string, flags: array<string, bool>}|null $state
 * @var bool $isMember
 * @var bool $canManage
 * @var bool $loggedIn
 * @var list<array<string, mixed>> $sections
 * @var array<int, int> $sectionPassed          section_id → members past it
 * @var array<string, mixed>|null $myProgress
 * @var array{avg_percent: float, avg_percent_all: float, finished: int, readers: int, active_readers: int, members: int} $aggregate
 * @var int $memberCount
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$bookId = (int) $book['id'];
$csrf = \App\Support\Csrf::ensureToken();
$base = url('/book-club/' . $slug . '/reading/' . $bookId);
$unitLabels = [
    'chapter' => __('Capitolo'),
    'part' => __('Parte'),
    'pages' => __('Pagine'),
    'custom' => __('Personalizzata'),
];
$myPercent = $myProgress !== null ? (int) $myProgress['percent'] : 0;
$myFinished = $myProgress !== null && !empty($myProgress['finished_at']);
$mySectionId = $myProgress !== null && $myProgress['section_id'] !== null ? (int) $myProgress['section_id'] : null;
$avgPercent = max(0.0, min(100.0, (float) ($aggregate['avg_percent_all'] ?? $aggregate['avg_percent'])));
?>
<div class="max-w-4xl mx-auto px-4 py-10">
  <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="text-sm text-gray-500 hover:text-gray-700">
    <i class="fas fa-arrow-left mr-1"></i><?= $e($club['name']) ?>
  </a>

  <!-- Book header -->
  <div class="bg-white rounded-xl shadow overflow-hidden mt-4 mb-8">
    <div class="h-2" style="background: <?= $e($club['color']) ?>"></div>
    <div class="p-6 flex items-start gap-4">
      <?php if (!empty($book['copertina_url'])): ?>
        <img src="<?= $e($book['copertina_url']) ?>" alt="" class="w-16 h-24 object-cover rounded shadow-sm" loading="lazy">
      <?php endif; ?>
      <div>
        <h1 class="text-2xl font-bold text-gray-900"><?= $e($book['titolo']) ?></h1>
        <?php if (!empty($book['autori'])): ?>
          <p class="text-gray-500 mt-1"><?= $e($book['autori']) ?></p>
        <?php endif; ?>
        <div class="flex flex-wrap items-center gap-3 mt-2 text-xs text-gray-400">
          <?php if ($state !== null): ?>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-white" style="background: <?= $e($state['color']) ?>"><?= $e($state['label']) ?></span>
          <?php endif; ?>
          <?php if (!empty($book['reading_starts']) || !empty($book['reading_ends'])): ?>
            <span>
              <i class="far fa-calendar mr-1"></i>
              <?= !empty($book['reading_starts']) ? $e(date('d/m/Y', (int) strtotime((string) $book['reading_starts']))) : '…' ?>
              →
              <?= !empty($book['reading_ends']) ? $e(date('d/m/Y', (int) strtotime((string) $book['reading_ends']))) : '…' ?>
            </span>
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

  <!-- Club aggregate -->
  <section class="bg-white rounded-xl shadow p-6 mb-8">
    <h2 class="text-lg font-semibold text-gray-900 mb-4"><?= $e(__('Il club sta leggendo')) ?></h2>
    <div class="flex items-center justify-between text-sm text-gray-500 mb-1">
      <span><?= $e(__('avanzamento medio del club')) ?></span>
      <span class="font-medium text-gray-700"><?= number_format($avgPercent, 0) ?>%</span>
    </div>
    <div class="h-2.5 bg-gray-100 rounded-full overflow-hidden">
      <div class="h-full rounded-full" style="width: <?= number_format($avgPercent, 1, '.', '') ?>%; background: <?= $e($club['color']) ?>"></div>
    </div>
    <p class="text-xs text-gray-400 mt-2">
      <?= $e(sprintf(__('%1$d lettori su %2$d membri'), (int) ($aggregate['active_readers'] ?? 0), (int) ($aggregate['members'] ?? $memberCount))) ?>
      · <?= $e(sprintf(__('%1$d membri su %2$d hanno finito il libro'), (int) $aggregate['finished'], (int) $memberCount)) ?>
    </p>
  </section>

  <!-- My progress -->
  <?php if ($isMember): ?>
    <section class="bg-white rounded-xl shadow p-6 mb-8">
      <h2 class="text-lg font-semibold text-gray-900 mb-1"><?= $e(__('Il mio progresso')) ?></h2>
      <?php if ($myFinished): ?>
        <p class="text-sm text-green-700 mb-3"><i class="fas fa-check-circle mr-1"></i><?= $e(__('Hai finito questo libro!')) ?></p>
      <?php endif; ?>
      <div class="flex items-center justify-between text-sm text-gray-500 mb-1">
        <span><?= $e(__('Dove sono arrivato')) ?></span>
        <span class="font-medium text-gray-700"><?= $myPercent ?>%</span>
      </div>
      <div class="h-2.5 bg-gray-100 rounded-full overflow-hidden mb-4">
        <div class="h-full rounded-full bg-blue-600" style="width: <?= $myPercent ?>%"></div>
      </div>
      <form method="post" action="<?= $e($base . '/progress') ?>" class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Percentuale letta')) ?></label>
          <input type="number" name="percent" min="0" max="100" value="<?= $myPercent ?>"
                 class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
        </div>
        <?php if ($sections !== []): ?>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Ultima sezione completata')) ?></label>
            <select name="section_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
              <option value=""><?= $e(__('Nessuna')) ?></option>
              <?php foreach ($sections as $section): ?>
                <option value="<?= (int) $section['id'] ?>" <?= $mySectionId === (int) $section['id'] ? 'selected' : '' ?>><?= $e($section['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
        <label class="flex items-center gap-2 text-sm text-gray-600 py-2">
          <input type="checkbox" name="finished" value="1" <?= $myFinished ? 'checked' : '' ?> class="rounded">
          <?= $e(__('Ho finito il libro')) ?>
        </label>
        <div>
          <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
            <?= $e(__('Aggiorna progresso')) ?>
          </button>
        </div>
      </form>
    </section>
  <?php elseif ($loggedIn): ?>
    <p class="text-sm text-gray-400 mb-8"><?= $e(__('Solo i membri attivi del club possono registrare il proprio progresso.')) ?></p>
  <?php endif; ?>

  <!-- Sections -->
  <section class="bg-white rounded-xl shadow p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4"><?= $e(__('Sezioni del libro')) ?></h2>

    <?php if ($sections === []): ?>
      <p class="text-sm text-gray-400"><?= $e(__('Nessuna sezione definita per questo libro.')) ?></p>
    <?php endif; ?>

    <?php foreach ($sections as $section): ?>
      <?php
        $sid = (int) $section['id'];
        $passed = (int) ($sectionPassed[$sid] ?? 0);
        $range = '';
        if ($section['range_from'] !== null || $section['range_to'] !== null) {
            $range = ($section['range_from'] !== null ? (int) $section['range_from'] : '…')
                . '–' . ($section['range_to'] !== null ? (int) $section['range_to'] : '…');
        }
      ?>
      <div class="border rounded-lg px-4 py-3 mb-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <span class="font-medium text-gray-900"><?= $e($section['title']) ?></span>
            <span class="text-xs text-gray-400 ml-2">
              <?= $e($unitLabels[(string) $section['unit']] ?? (string) $section['unit']) ?><?= $range !== '' ? ' ' . $e($range) : '' ?>
            </span>
          </div>
          <div class="text-xs text-gray-400 text-right">
            <?php if (!empty($section['discuss_from'])): ?>
              <div><i class="far fa-comments mr-1"></i><?= $e(__('Discussione dal')) ?> <?= $e(date('d/m/Y', (int) strtotime((string) $section['discuss_from']))) ?></div>
            <?php endif; ?>
            <div><i class="fas fa-user-check mr-1"></i><?= $e(sprintf(__('%d membri l\'hanno superata'), $passed)) ?></div>
          </div>
        </div>
        <?php if ($canManage): ?>
          <div class="mt-3 pt-3 border-t flex flex-wrap items-end gap-2">
            <form method="post" action="<?= $e($base . '/sections/' . $sid . '/update') ?>" class="flex flex-wrap items-end gap-2">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <div>
                <label class="block text-xs text-gray-400 mb-0.5"><?= $e(__('Titolo')) ?></label>
                <input type="text" name="title" value="<?= $e($section['title']) ?>" maxlength="190" required
                       class="border border-gray-200 rounded px-2 py-1 text-xs w-40">
              </div>
              <div>
                <label class="block text-xs text-gray-400 mb-0.5"><?= $e(__('Ordine')) ?></label>
                <input type="number" name="sort" value="<?= (int) $section['sort'] ?>"
                       class="border border-gray-200 rounded px-2 py-1 text-xs w-16">
              </div>
              <div>
                <label class="block text-xs text-gray-400 mb-0.5"><?= $e(__('Discussione dal')) ?></label>
                <input type="date" name="discuss_from" value="<?= $e($section['discuss_from'] ?? '') ?>"
                       class="border border-gray-200 rounded px-2 py-1 text-xs">
              </div>
              <button type="submit" class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 rounded-lg"><?= $e(__('Salva')) ?></button>
            </form>
            <form method="post" action="<?= $e($base . '/sections/' . $sid . '/delete') ?>"
                  onsubmit="return confirm('<?= $e(__('Eliminare questa sezione?')) ?>');">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="px-3 py-1.5 text-xs text-red-600 hover:bg-red-50 rounded-lg"><?= $e(__('Elimina')) ?></button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($canManage): ?>
      <!-- Add section -->
      <form method="post" action="<?= $e($base . '/sections') ?>" class="mt-5 border-t pt-4 grid grid-cols-1 sm:grid-cols-6 gap-3 items-end">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Nuova sezione')) ?></label>
          <input type="text" name="title" maxlength="190" required placeholder="<?= $e(__('Es. Capitoli 1–5')) ?>"
                 class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Tipo')) ?></label>
          <select name="unit" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
            <?php foreach ($unitLabels as $unitKey => $unitLabel): ?>
              <option value="<?= $e($unitKey) ?>"><?= $e($unitLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Da')) ?></label>
          <input type="number" name="range_from" min="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('A')) ?></label>
          <input type="number" name="range_to" min="0" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Discussione dal')) ?></label>
          <input type="date" name="discuss_from" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
        </div>
        <div class="sm:col-span-6">
          <button type="submit" class="px-4 py-2 bg-gray-900 hover:bg-gray-800 text-white text-sm font-medium rounded-lg">
            <i class="fas fa-plus mr-1"></i><?= $e(__('Aggiungi sezione')) ?>
          </button>
        </div>
      </form>
    <?php endif; ?>
  </section>
</div>

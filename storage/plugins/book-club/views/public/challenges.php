<?php
/**
 * Book Club — challenges module: current-year Reading Challenge page.
 * Club-wide challenges (club total vs target + my contribution), personal
 * challenges (mine highlighted), creation forms (personal for members,
 * club-wide for managers) and deletion (own personal / managers).
 *
 * @var array<string, mixed> $club
 * @var int $year
 * @var list<array<string, mixed>> $clubChallenges      user_id NULL rows
 * @var list<array<string, mixed>> $personalChallenges  user_id set rows
 * @var array<int, int> $mine                           challenge_id → my current
 * @var int|null $userId
 * @var bool $isMember
 * @var bool $canManage
 * @var int $memberCount
 * @var bool $readingReady
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
$base = url('/book-club/' . $slug . '/challenges');
$metricLabels = [
    'books' => __('Libri finiti'),
    'pages' => __('Pagine lette'),
    'authors' => __('Autori diversi'),
];
$pct = static fn(int $current, int $target): float => min(100.0, max(0.0, $current / max(1, $target) * 100));
?>
<div class="max-w-4xl mx-auto px-4 py-10">
  <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="text-sm text-gray-500 hover:text-gray-700">
    <i class="fas fa-arrow-left mr-1"></i><?= $e(__('Torna al club')) ?>
  </a>

  <div class="flex flex-wrap items-center justify-between gap-3 mt-4 mb-2">
    <h1 class="text-2xl font-bold text-gray-900 flex items-center">
      <span class="inline-block w-3 h-3 rounded-full mr-3" style="background: <?= $e($club['color']) ?>"></span>
      <?= $e(__('Reading Challenge')) ?> <?= (int) $year ?> — <?= $e($club['name']) ?>
    </h1>
  </div>
  <p class="text-sm text-gray-500 mb-6">
    <?= $e(__('L\'avanzamento è ricalcolato automaticamente dal tracker di lettura: contano i libri del club segnati come finiti nell\'anno.')) ?>
  </p>

  <?php if (!empty($flash)): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if (!$readingReady): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm bg-yellow-50 text-yellow-800">
      <i class="fas fa-triangle-exclamation mr-1"></i>
      <?= $e(__('Il modulo Lettura condivisa non è installato: l\'avanzamento delle sfide non può essere calcolato.')) ?>
    </div>
  <?php endif; ?>

  <!-- Club-wide challenges -->
  <section class="bg-white rounded-xl shadow p-6 mb-8">
    <h2 class="text-lg font-semibold text-gray-900 mb-4"><i class="fas fa-users mr-2 text-gray-400"></i><?= $e(__('Sfide del club')) ?></h2>
    <?php if ($clubChallenges === []): ?>
      <p class="text-sm text-gray-400"><?= $e(sprintf(__('Nessuna sfida di club per il %d.'), (int) $year)) ?></p>
    <?php endif; ?>
    <?php foreach ($clubChallenges as $challenge): ?>
      <?php
        $challengeId = (int) $challenge['id'];
        $target = max(1, (int) $challenge['target']);
        $total = (int) $challenge['total_current'];
        $myShare = (int) ($mine[$challengeId] ?? 0);
        $percent = $pct($total, $target);
      ?>
      <div class="border rounded-lg px-4 py-3 mb-3">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="font-medium text-gray-900 truncate"><?= $e($challenge['title']) ?></div>
            <div class="text-xs text-gray-400 mt-0.5">
              <?= $e($metricLabels[(string) $challenge['metric']] ?? (string) $challenge['metric']) ?>
              · <?= $e(__('Obiettivo')) ?>: <?= (int) $challenge['target'] ?>
              · <?= $e(sprintf(__('%d partecipanti'), (int) $challenge['participant_count'])) ?>
            </div>
          </div>
          <?php if ($canManage): ?>
            <form method="post" action="<?= $e($base . '/' . $challengeId . '/delete') ?>"
                  onsubmit="return confirm('<?= $e(__('Eliminare questa sfida?')) ?>');">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="text-xs text-red-500 hover:text-red-700 whitespace-nowrap">
                <i class="fas fa-trash mr-1"></i><?= $e(__('Elimina')) ?>
              </button>
            </form>
          <?php endif; ?>
        </div>
        <div class="flex items-center justify-between text-xs text-gray-400 mt-2 mb-0.5">
          <span><?= $e(__('Avanzamento del club')) ?></span>
          <span class="font-medium text-gray-600"><?= $total ?> / <?= $target ?></span>
        </div>
        <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
          <div class="h-full rounded-full" style="width: <?= number_format($percent, 1, '.', '') ?>%; background: <?= $e($club['color']) ?>"></div>
        </div>
        <div class="flex items-center justify-between mt-1.5 text-xs text-gray-400">
          <?php if ($isMember): ?>
            <span><?= $e(__('Il mio contributo')) ?>: <span class="font-medium text-gray-600"><?= $myShare ?></span></span>
          <?php else: ?>
            <span></span>
          <?php endif; ?>
          <?php if ($total >= $target): ?>
            <span class="text-green-600 font-medium"><i class="fas fa-flag-checkered mr-1"></i><?= $e(__('Sfida completata!')) ?></span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if ($canManage): ?>
      <form method="post" action="<?= $e($base) ?>" class="mt-4 border-t pt-4 grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="scope" value="club">
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Titolo')) ?></label>
          <input type="text" name="title" required maxlength="190"
                 class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Metrica')) ?></label>
          <select name="metric" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
            <?php foreach ($metricLabels as $key => $label): ?>
              <option value="<?= $e($key) ?>"><?= $e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Obiettivo annuale')) ?></label>
          <input type="number" name="target" min="1" max="1000000" required
                 class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
        </div>
        <div class="sm:col-span-4">
          <button type="submit" class="px-4 py-2 text-sm bg-gray-900 hover:bg-gray-700 text-white rounded-lg">
            <i class="fas fa-plus mr-1"></i><?= $e(__('Nuova sfida di club')) ?>
          </button>
        </div>
      </form>
    <?php endif; ?>
  </section>

  <!-- Personal challenges -->
  <section class="bg-white rounded-xl shadow p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4"><i class="fas fa-bullseye mr-2 text-gray-400"></i><?= $e(__('Sfide personali')) ?></h2>
    <?php if ($personalChallenges === []): ?>
      <p class="text-sm text-gray-400"><?= $e(sprintf(__('Nessuna sfida personale per il %d.'), (int) $year)) ?></p>
    <?php endif; ?>
    <?php foreach ($personalChallenges as $challenge): ?>
      <?php
        $challengeId = (int) $challenge['id'];
        $target = max(1, (int) $challenge['target']);
        $current = (int) $challenge['total_current']; // personal → the owner's snapshot
        $isOwn = $userId !== null && (int) $challenge['user_id'] === $userId;
        $percent = $pct($current, $target);
      ?>
      <div class="border rounded-lg px-4 py-3 mb-3 <?= $isOwn ? 'ring-2 ring-blue-500 border-transparent' : '' ?>">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="font-medium text-gray-900 truncate">
              <?= $e($challenge['title']) ?>
              <?php if ($isOwn): ?>
                <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-blue-100 text-blue-800"><?= $e(__('La tua sfida')) ?></span>
              <?php endif; ?>
            </div>
            <div class="text-xs text-gray-400 mt-0.5">
              <?= $e($challenge['owner_name']) ?>
              · <?= $e($metricLabels[(string) $challenge['metric']] ?? (string) $challenge['metric']) ?>
              · <?= $e(__('Obiettivo')) ?>: <?= (int) $challenge['target'] ?>
            </div>
          </div>
          <?php if ($canManage || $isOwn): ?>
            <form method="post" action="<?= $e($base . '/' . $challengeId . '/delete') ?>"
                  onsubmit="return confirm('<?= $e(__('Eliminare questa sfida?')) ?>');">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="text-xs text-red-500 hover:text-red-700 whitespace-nowrap">
                <i class="fas fa-trash mr-1"></i><?= $e(__('Elimina')) ?>
              </button>
            </form>
          <?php endif; ?>
        </div>
        <div class="flex items-center justify-between text-xs text-gray-400 mt-2 mb-0.5">
          <span><?= $e(__('Avanzamento')) ?></span>
          <span class="font-medium text-gray-600"><?= $current ?> / <?= $target ?></span>
        </div>
        <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
          <div class="h-full rounded-full <?= $isOwn ? 'bg-blue-600' : 'bg-gray-400' ?>" style="width: <?= number_format($percent, 1, '.', '') ?>%"></div>
        </div>
        <?php if ($current >= $target): ?>
          <div class="text-right mt-1.5 text-xs text-green-600 font-medium"><i class="fas fa-flag-checkered mr-1"></i><?= $e(__('Sfida completata!')) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($isMember): ?>
      <form method="post" action="<?= $e($base) ?>" class="mt-4 border-t pt-4 grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="scope" value="personal">
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Titolo')) ?></label>
          <input type="text" name="title" required maxlength="190" placeholder="<?= $e(__('Es. 12 libri in un anno')) ?>"
                 class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Metrica')) ?></label>
          <select name="metric" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
            <?php foreach ($metricLabels as $key => $label): ?>
              <option value="<?= $e($key) ?>"><?= $e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Obiettivo annuale')) ?></label>
          <input type="number" name="target" min="1" max="1000000" required
                 class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
        </div>
        <div class="sm:col-span-4">
          <button type="submit" class="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
            <i class="fas fa-plus mr-1"></i><?= $e(__('Nuova sfida personale')) ?>
          </button>
        </div>
      </form>
    <?php endif; ?>
  </section>
</div>

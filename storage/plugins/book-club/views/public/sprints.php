<?php
/**
 * Book Club — sprints module: Reading Sprint page. List of the club's
 * sprints (status derived from the clock), creation form for members,
 * join/leave before the start, cancel (creator/manager) and — once a sprint
 * is over — the results board with pages per participant + total and the
 * personal pages-read form.
 *
 * @var array<string, mixed> $club
 * @var list<array<string, mixed>> $sprints        each row: + effective_status, participants, mine, total_pages
 * @var list<array<string, mixed>> $currentBooks   club books in current-flagged states (create form)
 * @var bool $isMember
 * @var bool $canManage
 * @var bool $loggedIn
 * @var int|null $userId
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
$base = url('/book-club/' . $slug . '/sprints');

$statusMeta = [
    'scheduled' => [__('In programma'), 'bg-blue-100 text-blue-800'],
    'running' => [__('In corso'), 'bg-green-100 text-green-800'],
    'done' => [__('Concluso'), 'bg-gray-100 text-gray-600'],
    'cancelled' => [__('Annullato'), 'bg-red-100 text-red-700'],
];

/** Server-rendered countdown text ("2 g 3 h 15 min"). */
$countdown = static function (int $seconds): string {
    $minutes = max(1, (int) ceil($seconds / 60));
    $days = intdiv($minutes, 1440);
    $hours = intdiv($minutes % 1440, 60);
    $mins = $minutes % 60;
    $parts = [];
    if ($days > 0) {
        $parts[] = sprintf(__('%d g'), $days);
    }
    if ($hours > 0) {
        $parts[] = sprintf(__('%d h'), $hours);
    }
    if ($mins > 0 || $parts === []) {
        $parts[] = sprintf(__('%d min'), $mins);
    }
    return implode(' ', $parts);
};
$now = time();
?>
<div class="max-w-4xl mx-auto px-4 py-10">
  <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="text-sm text-gray-500 hover:text-gray-700">
    <i class="fas fa-arrow-left mr-1"></i><?= $e(__('Torna al club')) ?>
  </a>

  <div class="flex flex-wrap items-center justify-between gap-3 mt-4 mb-2">
    <h1 class="text-2xl font-bold text-gray-900 flex items-center">
      <span class="inline-block w-3 h-3 rounded-full mr-3" style="background: <?= $e($club['color']) ?>"></span>
      <?= $e(__('Reading Sprint')) ?> — <?= $e($club['name']) ?>
    </h1>
  </div>
  <p class="text-sm text-gray-500 mb-6">
    <?= $e(__('Sessioni di lettura cronometrate: iscriviti prima dell\'inizio, leggi per la durata dello sprint e registra le pagine lette alla fine.')) ?>
  </p>

  <?php if (!empty($flash)): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if ($isMember || $canManage): ?>
    <!-- Create form -->
    <section class="bg-white rounded-xl shadow p-6 mb-8">
      <h2 class="text-lg font-semibold text-gray-900 mb-4"><i class="fas fa-stopwatch mr-2 text-gray-400"></i><?= $e(__('Nuovo sprint')) ?></h2>
      <form method="post" action="<?= $e($base) ?>" class="grid grid-cols-1 sm:grid-cols-6 gap-3 items-end">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <div class="sm:col-span-3">
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Titolo')) ?></label>
          <input type="text" name="title" required maxlength="190" placeholder="<?= $e(__('Es. Sprint del venerdì sera')) ?>"
                 class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
        </div>
        <div class="sm:col-span-3">
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Libro (facoltativo)')) ?></label>
          <select name="club_book_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
            <option value=""><?= $e(__('Nessun libro specifico')) ?></option>
            <?php foreach ($currentBooks as $book): ?>
              <option value="<?= (int) $book['id'] ?>"><?= $e($book['titolo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Inizio')) ?></label>
          <input type="datetime-local" name="starts_at" required
                 class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
        </div>
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Durata (minuti)')) ?></label>
          <input type="number" name="duration_min" required min="5" max="480" value="30"
                 class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
        </div>
        <div class="sm:col-span-2">
          <button type="submit" class="w-full px-4 py-2 bg-gray-900 hover:bg-gray-700 text-white text-sm rounded-lg">
            <i class="fas fa-plus mr-1"></i><?= $e(__('Crea sprint')) ?>
          </button>
        </div>
      </form>
    </section>
  <?php endif; ?>

  <!-- Sprint list -->
  <section class="space-y-4">
    <?php if ($sprints === []): ?>
      <div class="bg-white rounded-xl shadow p-6 text-sm text-gray-400">
        <?= $e(__('Nessuno sprint ancora: crea il primo!')) ?>
      </div>
    <?php endif; ?>

    <?php foreach ($sprints as $sprint): ?>
      <?php
        $sprintId = (int) $sprint['id'];
        $status = (string) $sprint['effective_status'];
        [$statusLabel, $statusClass] = $statusMeta[$status] ?? [$status, 'bg-gray-100 text-gray-600'];
        $startTs = (int) strtotime((string) $sprint['starts_at']);
        $endTs = $startTs + (int) $sprint['duration_min'] * 60;
        $joined = is_array($sprint['mine'] ?? null);
        $isCreator = $userId !== null && (int) ($sprint['created_by'] ?? 0) === $userId;
        $participants = is_array($sprint['participants'] ?? null) ? $sprint['participants'] : [];
      ?>
      <article class="bg-white rounded-xl shadow p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <h2 class="text-lg font-semibold text-gray-900 truncate"><?= $e($sprint['title']) ?></h2>
              <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $statusClass ?>"><?= $e($statusLabel) ?></span>
            </div>
            <div class="text-sm text-gray-500 mt-1 flex flex-wrap gap-x-4 gap-y-1">
              <span><i class="far fa-clock mr-1"></i><?= $e(date('d/m/Y H:i', $startTs)) ?></span>
              <span><i class="fas fa-hourglass-half mr-1"></i><?= $e(sprintf(__('%d minuti'), (int) $sprint['duration_min'])) ?></span>
              <?php if (!empty($sprint['book_title'])): ?>
                <span><i class="fas fa-book mr-1"></i><?= $e($sprint['book_title']) ?></span>
              <?php endif; ?>
              <?php if (!empty($sprint['creator_name'])): ?>
                <span><i class="far fa-user mr-1"></i><?= $e($sprint['creator_name']) ?></span>
              <?php endif; ?>
              <span><i class="fas fa-users mr-1"></i><?= $e(sprintf(__('%d partecipanti'), count($participants))) ?></span>
            </div>
            <?php if ($status === 'scheduled'): ?>
              <p class="text-sm text-blue-700 mt-2"><i class="fas fa-play mr-1"></i><?= $e(sprintf(__('Inizia tra %s'), $countdown($startTs - $now))) ?></p>
            <?php elseif ($status === 'running'): ?>
              <p class="text-sm text-green-700 mt-2"><i class="fas fa-book-open mr-1"></i><?= $e(sprintf(__('In corso — termina tra %s'), $countdown($endTs - $now))) ?></p>
            <?php endif; ?>
          </div>

          <div class="flex items-center gap-2 shrink-0">
            <?php if ($isMember && $status === 'scheduled'): ?>
              <?php if (!$joined): ?>
                <form method="post" action="<?= $e($base . '/' . $sprintId . '/join') ?>">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <button type="submit" class="px-3 py-1.5 text-xs bg-gray-900 hover:bg-gray-700 text-white rounded-lg">
                    <i class="fas fa-user-plus mr-1"></i><?= $e(__('Partecipa')) ?>
                  </button>
                </form>
              <?php else: ?>
                <form method="post" action="<?= $e($base . '/' . $sprintId . '/leave') ?>">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <button type="submit" class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg">
                    <i class="fas fa-user-minus mr-1"></i><?= $e(__('Ritirati')) ?>
                  </button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
            <?php if (($isCreator || $canManage) && ($status === 'scheduled' || $status === 'running')): ?>
              <form method="post" action="<?= $e($base . '/' . $sprintId . '/cancel') ?>"
                    onsubmit="return confirm('<?= $e(__('Annullare questo sprint?')) ?>');">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <button type="submit" class="px-3 py-1.5 text-xs text-red-500 hover:text-red-700">
                  <i class="fas fa-ban mr-1"></i><?= $e(__('Annulla')) ?>
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($participants !== [] && $status !== 'cancelled'): ?>
          <div class="mt-4 border-t pt-4">
            <?php if ($status === 'done'): ?>
              <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2">
                <i class="fas fa-trophy mr-1 text-yellow-400"></i><?= $e(__('Risultati')) ?>
                <span class="ml-2 font-normal normal-case text-gray-400"><?= $e(sprintf(__('%d pagine totali'), (int) $sprint['total_pages'])) ?></span>
              </h3>
              <ul class="text-sm text-gray-600 space-y-1">
                <?php foreach ($participants as $p): ?>
                  <li class="flex items-center justify-between gap-3">
                    <span class="truncate"><i class="far fa-user mr-1 text-gray-300"></i><?= $e($p['user_name']) ?></span>
                    <span class="font-medium text-gray-800 whitespace-nowrap">
                      <?= $p['pages_read'] !== null ? $e(sprintf(__('%d pagine'), (int) $p['pages_read'])) : $e(__('non registrate')) ?>
                    </span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <div class="text-sm text-gray-500">
                <i class="fas fa-users mr-1 text-gray-300"></i>
                <?= $e(implode(', ', array_map(static fn(array $p): string => (string) $p['user_name'], $participants))) ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($status === 'done' && $joined): ?>
          <form method="post" action="<?= $e($base . '/' . $sprintId . '/pages') ?>" class="mt-3 flex items-end gap-3">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Le tue pagine lette')) ?></label>
              <input type="number" name="pages_read" min="0" max="10000" required
                     value="<?= $sprint['mine']['pages_read'] !== null ? (int) $sprint['mine']['pages_read'] : '' ?>"
                     class="w-32 border border-gray-200 rounded-lg px-3 py-2 text-sm">
            </div>
            <button type="submit" class="px-3 py-2 text-xs bg-gray-900 hover:bg-gray-700 text-white rounded-lg">
              <i class="fas fa-check mr-1"></i><?= $e(__('Salva')) ?>
            </button>
          </form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </section>
</div>

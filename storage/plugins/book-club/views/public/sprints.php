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
$base = url(\App\Support\RouteTranslator::route('book_club') . '/' . $slug . '/sprints');

$statusMeta = [
    'scheduled' => [__('In programma'), 'bc-badge-warn'],
    'running' => [__('In corso'), 'bc-badge-open'],
    'done' => [__('Concluso'), 'bc-badge-closed'],
    'cancelled' => [__('Annullato'), 'bc-badge-closed'],
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
$corePartials = dirname(__DIR__, 5) . '/app/Views/frontend/partials';
$catalogPageStyles = true;
$heroTitle = __('Reading Sprint');
$heroSubtitle = __('Sessioni di lettura cronometrate: iscriviti prima dell\'inizio, leggi per la durata dello sprint e registra le pagine lette alla fine.');
$breadcrumbItems = [
    ['label' => __('Home'), 'href' => url('/')],
    ['label' => __('Club di lettura'), 'href' => url(\App\Support\RouteTranslator::route('book_club'))],
    ['label' => (string) $club['name'], 'href' => url(\App\Support\RouteTranslator::route('book_club') . '/' . $slug)],
    ['label' => $heroTitle],
];
include $corePartials . '/catalog-hero.php';
?>
<?php $bookClubStyles = true; ?>
<div class="container py-4">
  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if ($isMember || $canManage): ?>
    <!-- Create form -->
    <section class="bc-card">
      <div class="bc-section-header">
        <i class="fas fa-stopwatch"></i>
        <h2><?= $e(__('Nuovo sprint')) ?></h2>
      </div>
      <form method="post" action="<?= $e($base) ?>" class="flex flex-wrap -mx-3 gap-y-3 items-end">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <div class="w-full px-3 md:w-1/2">
          <label class="form-label text-sm font-semibold"><?= $e(__('Titolo')) ?></label>
          <input type="text" name="title" required maxlength="190" placeholder="<?= $e(__('Es. Sprint del venerdì sera')) ?>"
                 class="form-input">
        </div>
        <div class="w-full px-3 md:w-1/2">
          <label class="form-label text-sm font-semibold"><?= $e(__('Libro (facoltativo)')) ?></label>
          <select name="club_book_id" class="form-input">
            <option value=""><?= $e(__('Nessun libro specifico')) ?></option>
            <?php foreach ($currentBooks as $book): ?>
              <option value="<?= (int) $book['id'] ?>"><?= $e($book['titolo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="w-full px-3 md:w-1/3">
          <label class="form-label text-sm font-semibold"><?= $e(__('Inizio')) ?></label>
          <input type="datetime-local" name="starts_at" required class="form-input">
        </div>
        <div class="w-1/2 px-3 md:w-1/3">
          <label class="form-label text-sm font-semibold"><?= $e(__('Durata (minuti)')) ?></label>
          <input type="number" name="duration_min" required min="5" max="480" value="30" class="form-input">
        </div>
        <div class="w-1/2 px-3 md:w-1/3">
          <button type="submit" class="bc-btn w-full">
            <i class="fas fa-plus"></i><?= $e(__('Crea sprint')) ?>
          </button>
        </div>
      </form>
    </section>
  <?php endif; ?>

  <!-- Sprint list -->
  <section>
    <?php if ($sprints === []): ?>
      <div class="bc-card bc-muted">
        <?= $e(__('Nessuno sprint ancora: crea il primo!')) ?>
      </div>
    <?php endif; ?>

    <?php foreach ($sprints as $sprint): ?>
      <?php
        $sprintId = (int) $sprint['id'];
        $status = (string) $sprint['effective_status'];
        [$statusLabel, $statusClass] = $statusMeta[$status] ?? [$status, 'bc-badge-closed'];
        $startTs = (int) strtotime((string) $sprint['starts_at']);
        $endTs = $startTs + (int) $sprint['duration_min'] * 60;
        $joined = is_array($sprint['mine'] ?? null);
        $isCreator = $userId !== null && (int) ($sprint['created_by'] ?? 0) === $userId;
        $participants = is_array($sprint['participants'] ?? null) ? $sprint['participants'] : [];
      ?>
      <article class="bc-card">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="grow">
            <div class="flex items-center gap-2 flex-wrap">
              <h2 class="h5 font-bold mb-0"><?= $e($sprint['title']) ?></h2>
              <span class="bc-badge <?= $statusClass ?>"><?= $e($statusLabel) ?></span>
            </div>
            <div class="bc-muted mt-1 flex flex-wrap column-gap-3 row-gap-1">
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
              <p class="mt-2 mb-0"><span class="bc-badge bc-badge-warn"><i class="fas fa-play"></i><?= $e(sprintf(__('Inizia tra %s'), $countdown($startTs - $now))) ?></span></p>
            <?php elseif ($status === 'running'): ?>
              <p class="mt-2 mb-0"><span class="bc-badge bc-badge-open"><i class="fas fa-book-open"></i><?= $e(sprintf(__('In corso — termina tra %s'), $countdown($endTs - $now))) ?></span></p>
            <?php endif; ?>
          </div>

          <div class="flex items-center gap-2 shrink-0">
            <?php if ($isMember && $status === 'scheduled'): ?>
              <?php if (!$joined): ?>
                <form method="post" action="<?= $e($base . '/' . $sprintId . '/join') ?>">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <button type="submit" class="bc-btn bc-btn-sm">
                    <i class="fas fa-user-plus"></i><?= $e(__('Partecipa')) ?>
                  </button>
                </form>
              <?php else: ?>
                <form method="post" action="<?= $e($base . '/' . $sprintId . '/leave') ?>">
                  <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                  <button type="submit" class="bc-btn bc-btn-outline bc-btn-sm">
                    <i class="fas fa-user-minus"></i><?= $e(__('Ritirati')) ?>
                  </button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
            <?php if (($isCreator || $canManage) && ($status === 'scheduled' || $status === 'running')): ?>
              <form method="post" action="<?= $e($base . '/' . $sprintId . '/cancel') ?>"
                    data-pk-confirm="<?= $e(__('Annullare questo sprint?')) ?>">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm">
                  <i class="fas fa-ban"></i><?= $e(__('Annulla')) ?>
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($participants !== [] && $status !== 'cancelled'): ?>
          <div class="mt-3 pt-3 border-t">
            <?php if ($status === 'done'): ?>
              <h3 class="mb-2 text-base font-bold">
                <i class="fas fa-trophy mr-1" style="color: var(--warning-color)"></i><?= $e(__('Risultati')) ?>
                <span class="ml-2 font-normal bc-muted"><?= $e(sprintf(__('%d pagine totali'), (int) $sprint['total_pages'])) ?></span>
              </h3>
              <ul class="mb-0 list-none text-sm">
                <?php foreach ($participants as $p): ?>
                  <li class="flex items-center justify-between gap-3 py-1">
                    <span class="truncate"><i class="far fa-user mr-1 bc-muted"></i><?= $e($p['user_name']) ?></span>
                    <span class="font-semibold whitespace-nowrap">
                      <?= $p['pages_read'] !== null ? $e(sprintf(__('%d pagine'), (int) $p['pages_read'])) : $e(__('non registrate')) ?>
                    </span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <div class="bc-muted">
                <i class="fas fa-users mr-1"></i>
                <?= $e(implode(', ', array_map(static fn(array $p): string => (string) $p['user_name'], $participants))) ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($status === 'done' && $joined): ?>
          <form method="post" action="<?= $e($base . '/' . $sprintId . '/pages') ?>" class="flex flex-wrap -mx-3 gap-y-2 items-end mt-2">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <div class="w-auto px-3">
              <label class="form-label text-sm font-semibold"><?= $e(__('Le tue pagine lette')) ?></label>
              <input type="number" name="pages_read" min="0" max="10000" required
                     value="<?= $sprint['mine']['pages_read'] !== null ? (int) $sprint['mine']['pages_read'] : '' ?>"
                     class="form-input px-3 py-2 text-sm">
            </div>
            <div class="w-auto px-3">
              <button type="submit" class="bc-btn bc-btn-sm">
                <i class="fas fa-check"></i><?= $e(__('Salva')) ?>
              </button>
            </div>
          </form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </section>
</div>

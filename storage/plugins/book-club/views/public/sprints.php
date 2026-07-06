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
    <i class="fas fa-arrow-left"></i><?= $e(__('Torna al club')) ?>
  </a>

  <div class="bc-hero">
    <h1 class="d-flex align-items-center gap-3">
      <span class="bc-chip" style="background: <?= $e($club['color']) ?>"></span>
      <span><?= $e(__('Reading Sprint')) ?> — <?= $e($club['name']) ?></span>
    </h1>
    <p><?= $e(__('Sessioni di lettura cronometrate: iscriviti prima dell\'inizio, leggi per la durata dello sprint e registra le pagine lette alla fine.')) ?></p>
  </div>

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
      <form method="post" action="<?= $e($base) ?>" class="row g-3 align-items-end">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <div class="col-12 col-md-6">
          <label class="form-label small fw-semibold"><?= $e(__('Titolo')) ?></label>
          <input type="text" name="title" required maxlength="190" placeholder="<?= $e(__('Es. Sprint del venerdì sera')) ?>"
                 class="form-control">
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label small fw-semibold"><?= $e(__('Libro (facoltativo)')) ?></label>
          <select name="club_book_id" class="form-select">
            <option value=""><?= $e(__('Nessun libro specifico')) ?></option>
            <?php foreach ($currentBooks as $book): ?>
              <option value="<?= (int) $book['id'] ?>"><?= $e($book['titolo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label small fw-semibold"><?= $e(__('Inizio')) ?></label>
          <input type="datetime-local" name="starts_at" required class="form-control">
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label small fw-semibold"><?= $e(__('Durata (minuti)')) ?></label>
          <input type="number" name="duration_min" required min="5" max="480" value="30" class="form-control">
        </div>
        <div class="col-6 col-md-4">
          <button type="submit" class="bc-btn w-100">
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
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
          <div class="flex-grow-1">
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <h2 class="h5 fw-bold mb-0"><?= $e($sprint['title']) ?></h2>
              <span class="bc-badge <?= $statusClass ?>"><?= $e($statusLabel) ?></span>
            </div>
            <div class="bc-muted mt-1 d-flex flex-wrap column-gap-3 row-gap-1">
              <span><i class="far fa-clock me-1"></i><?= $e(date('d/m/Y H:i', $startTs)) ?></span>
              <span><i class="fas fa-hourglass-half me-1"></i><?= $e(sprintf(__('%d minuti'), (int) $sprint['duration_min'])) ?></span>
              <?php if (!empty($sprint['book_title'])): ?>
                <span><i class="fas fa-book me-1"></i><?= $e($sprint['book_title']) ?></span>
              <?php endif; ?>
              <?php if (!empty($sprint['creator_name'])): ?>
                <span><i class="far fa-user me-1"></i><?= $e($sprint['creator_name']) ?></span>
              <?php endif; ?>
              <span><i class="fas fa-users me-1"></i><?= $e(sprintf(__('%d partecipanti'), count($participants))) ?></span>
            </div>
            <?php if ($status === 'scheduled'): ?>
              <p class="mt-2 mb-0"><span class="bc-badge bc-badge-warn"><i class="fas fa-play"></i><?= $e(sprintf(__('Inizia tra %s'), $countdown($startTs - $now))) ?></span></p>
            <?php elseif ($status === 'running'): ?>
              <p class="mt-2 mb-0"><span class="bc-badge bc-badge-open"><i class="fas fa-book-open"></i><?= $e(sprintf(__('In corso — termina tra %s'), $countdown($endTs - $now))) ?></span></p>
            <?php endif; ?>
          </div>

          <div class="d-flex align-items-center gap-2 flex-shrink-0">
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
                    onsubmit="return confirm('<?= $e(__('Annullare questo sprint?')) ?>');">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm">
                  <i class="fas fa-ban"></i><?= $e(__('Annulla')) ?>
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($participants !== [] && $status !== 'cancelled'): ?>
          <div class="mt-3 pt-3 border-top">
            <?php if ($status === 'done'): ?>
              <h3 class="h6 fw-bold mb-2">
                <i class="fas fa-trophy me-1" style="color: var(--warning-color)"></i><?= $e(__('Risultati')) ?>
                <span class="ms-2 fw-normal bc-muted"><?= $e(sprintf(__('%d pagine totali'), (int) $sprint['total_pages'])) ?></span>
              </h3>
              <ul class="list-unstyled small mb-0">
                <?php foreach ($participants as $p): ?>
                  <li class="d-flex align-items-center justify-content-between gap-3 py-1">
                    <span class="text-truncate"><i class="far fa-user me-1 bc-muted"></i><?= $e($p['user_name']) ?></span>
                    <span class="fw-semibold text-nowrap">
                      <?= $p['pages_read'] !== null ? $e(sprintf(__('%d pagine'), (int) $p['pages_read'])) : $e(__('non registrate')) ?>
                    </span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <div class="bc-muted">
                <i class="fas fa-users me-1"></i>
                <?= $e(implode(', ', array_map(static fn(array $p): string => (string) $p['user_name'], $participants))) ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($status === 'done' && $joined): ?>
          <form method="post" action="<?= $e($base . '/' . $sprintId . '/pages') ?>" class="row g-2 align-items-end mt-2">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <div class="col-auto">
              <label class="form-label small fw-semibold"><?= $e(__('Le tue pagine lette')) ?></label>
              <input type="number" name="pages_read" min="0" max="10000" required
                     value="<?= $sprint['mine']['pages_read'] !== null ? (int) $sprint['mine']['pages_read'] : '' ?>"
                     class="form-control form-control-sm">
            </div>
            <div class="col-auto">
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

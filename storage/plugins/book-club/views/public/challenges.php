<?php
/**
 * Book Club — challenges module: per-year Reading Challenge page with a
 * year browser (?year=YYYY; past years are read-only, no create/delete
 * forms). Club-wide challenges (club total vs target + my contribution),
 * personal challenges (mine highlighted), creation forms (personal for
 * members, club-wide for managers) and deletion (own personal / managers).
 *
 * @var array<string, mixed> $club
 * @var int $year
 * @var list<int> $years                                years with data + current, DESC
 * @var bool $isCurrentYear                             past years render read-only
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
$base = url(\App\Support\RouteTranslator::route('book_club') . '/' . $slug . '/challenges');
$metricLabels = [
    'books' => __('Libri finiti'),
    'pages' => __('Pagine lette'),
    'authors' => __('Autori diversi'),
];
$pct = static fn(int $current, int $target): float => min(100.0, max(0.0, $current / max(1, $target) * 100));
?>
<?php $bookClubStyles = true; ?>
<div class="container py-4">
  <a href="<?= $e(url(\App\Support\RouteTranslator::route('book_club') . '/' . $slug)) ?>" class="bc-muted no-underline">
    <i class="fas fa-arrow-left mr-1"></i><?= $e(__('Torna al club')) ?>
  </a>

  <div class="flex flex-wrap items-center justify-between gap-3 mt-3 mb-2">
    <div class="bc-section-header mb-0">
      <span class="bc-chip" style="background: <?= $e($club['color']) ?>"></span>
      <h1><?= $e(__('Reading Challenge')) ?> <?= (int) $year ?> — <?= $e($club['name']) ?></h1>
    </div>
    <div class="flex flex-wrap items-center gap-2" aria-label="<?= $e(__('Anno')) ?>">
      <span class="bc-muted text-sm mr-1"><?= $e(__('Anno')) ?>:</span>
      <?php foreach ($years as $yearOption): ?>
        <a href="<?= $e($base . '?year=' . (int) $yearOption) ?>"
           class="bc-btn bc-btn-sm <?= (int) $yearOption === $year ? '' : 'bc-btn-outline' ?>">
          <?= (int) $yearOption ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <p class="bc-muted mb-4">
    <?= $e(__('L\'avanzamento è ricalcolato automaticamente dal tracker di lettura: contano i libri del club segnati come finiti nell\'anno.')) ?>
  </p>

  <?php if (!$isCurrentYear): ?>
    <div class="mb-4 p-3 border bc-muted">
      <i class="fas fa-box-archive mr-1"></i>
      <?= $e(sprintf(__('Stai consultando l\'archivio del %d: le sfide degli anni passati sono in sola lettura.'), (int) $year)) ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($flash)): ?>
    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : ($flash['type'] === 'warning' ? 'alert-warning' : 'alert-error') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <?php if (!$readingReady): ?>
    <div class="alert alert-warning">
      <i class="fas fa-triangle-exclamation mr-1"></i>
      <?= $e(__('Il modulo Lettura condivisa non è installato: l\'avanzamento delle sfide non può essere calcolato.')) ?>
    </div>
  <?php endif; ?>

  <!-- Club-wide challenges -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-users"></i>
      <h2><?= $e(__('Sfide del club')) ?></h2>
    </div>
    <?php if ($clubChallenges === []): ?>
      <p class="bc-muted mb-0"><?= $e(sprintf(__('Nessuna sfida di club per il %d.'), (int) $year)) ?></p>
    <?php endif; ?>
    <?php foreach ($clubChallenges as $challenge): ?>
      <?php
        $challengeId = (int) $challenge['id'];
        $target = max(1, (int) $challenge['target']);
        $total = (int) $challenge['total_current'];
        $myShare = (int) ($mine[$challengeId] ?? 0);
        $percent = $pct($total, $target);
      ?>
      <div class="border p-3 mb-3">
        <div class="flex items-start justify-between gap-3">
          <div style="min-width:0">
            <div class="font-medium truncate"><?= $e($challenge['title']) ?></div>
            <div class="bc-muted text-sm mt-1">
              <?= $e($metricLabels[(string) $challenge['metric']] ?? (string) $challenge['metric']) ?>
              · <?= $e(__('Obiettivo')) ?>: <?= (int) $challenge['target'] ?>
              · <?= $e(sprintf(__('%d partecipanti'), (int) $challenge['participant_count'])) ?>
            </div>
          </div>
          <?php if ($canManage && $isCurrentYear): ?>
            <form method="post" action="<?= $e($base . '/' . $challengeId . '/delete') ?>"
                  data-pk-confirm="<?= $e(__('Eliminare questa sfida?')) ?>">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm">
                <i class="fas fa-trash"></i><?= $e(__('Elimina')) ?>
              </button>
            </form>
          <?php endif; ?>
        </div>
        <div class="flex items-center justify-between bc-muted text-sm mt-2 mb-1">
          <span><?= $e(__('Avanzamento del club')) ?></span>
          <span class="font-medium"><?= $total ?> / <?= $target ?></span>
        </div>
        <div class="bc-progress">
          <span style="width: <?= number_format($percent, 1, '.', '') ?>%; background: <?= $e($club['color']) ?>"></span>
        </div>
        <div class="flex items-center justify-between mt-1 bc-muted text-sm">
          <?php if ($isMember): ?>
            <span><?= $e(__('Il mio contributo')) ?>: <span class="font-medium"><?= $myShare ?></span></span>
          <?php else: ?>
            <span></span>
          <?php endif; ?>
          <?php if ($total >= $target): ?>
            <span class="font-medium" style="color:var(--success-color)"><i class="fas fa-flag-checkered mr-1"></i><?= $e(__('Sfida completata!')) ?></span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if ($canManage && $isCurrentYear): ?>
      <form method="post" action="<?= $e($base) ?>" class="flex flex-wrap -mx-3 gap-y-3 items-end mt-1 border-t pt-3">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="scope" value="club">
        <div class="w-full px-3 md:w-1/2">
          <label class="form-label text-sm"><?= $e(__('Titolo')) ?></label>
          <input type="text" name="title" required maxlength="190" class="form-input">
        </div>
        <div class="w-1/2 px-3 md:w-1/4">
          <label class="form-label text-sm"><?= $e(__('Metrica')) ?></label>
          <select name="metric" class="form-input">
            <?php foreach ($metricLabels as $key => $label): ?>
              <option value="<?= $e($key) ?>"><?= $e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="w-1/2 px-3 md:w-1/4">
          <label class="form-label text-sm"><?= $e(__('Obiettivo annuale')) ?></label>
          <input type="number" name="target" min="1" max="1000000" required class="form-input">
        </div>
        <div class="w-full px-3">
          <button type="submit" class="bc-btn">
            <i class="fas fa-plus"></i><?= $e(__('Nuova sfida di club')) ?>
          </button>
        </div>
      </form>
    <?php endif; ?>
  </section>

  <!-- Personal challenges -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-bullseye"></i>
      <h2><?= $e(__('Sfide personali')) ?></h2>
    </div>
    <?php if ($personalChallenges === []): ?>
      <p class="bc-muted mb-0"><?= $e(sprintf(__('Nessuna sfida personale per il %d.'), (int) $year)) ?></p>
    <?php endif; ?>
    <?php foreach ($personalChallenges as $challenge): ?>
      <?php
        $challengeId = (int) $challenge['id'];
        $target = max(1, (int) $challenge['target']);
        $current = (int) $challenge['total_current']; // personal → the owner's snapshot
        $isOwn = $userId !== null && (int) $challenge['user_id'] === $userId;
        $percent = $pct($current, $target);
      ?>
      <div class="border p-3 mb-3" <?= $isOwn ? 'style="border-color:var(--primary-color) !important;border-width:2px"' : '' ?>>
        <div class="flex items-start justify-between gap-3">
          <div style="min-width:0">
            <div class="font-medium truncate">
              <?= $e($challenge['title']) ?>
              <?php if ($isOwn): ?>
                <span class="bc-badge ml-1" style="background:var(--accent-color);color:var(--primary-text, var(--primary-color))"><?= $e(__('La tua sfida')) ?></span>
              <?php endif; ?>
            </div>
            <div class="bc-muted text-sm mt-1">
              <?= $e($challenge['owner_name']) ?>
              · <?= $e($metricLabels[(string) $challenge['metric']] ?? (string) $challenge['metric']) ?>
              · <?= $e(__('Obiettivo')) ?>: <?= (int) $challenge['target'] ?>
            </div>
          </div>
          <?php if (($canManage || $isOwn) && $isCurrentYear): ?>
            <form method="post" action="<?= $e($base . '/' . $challengeId . '/delete') ?>"
                  data-pk-confirm="<?= $e(__('Eliminare questa sfida?')) ?>">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm">
                <i class="fas fa-trash"></i><?= $e(__('Elimina')) ?>
              </button>
            </form>
          <?php endif; ?>
        </div>
        <div class="flex items-center justify-between bc-muted text-sm mt-2 mb-1">
          <span><?= $e(__('Avanzamento')) ?></span>
          <span class="font-medium"><?= $current ?> / <?= $target ?></span>
        </div>
        <div class="bc-progress">
          <span style="width: <?= number_format($percent, 1, '.', '') ?>%;<?= $isOwn ? '' : 'background:var(--text-muted)' ?>"></span>
        </div>
        <?php if ($current >= $target): ?>
          <div class="text-right mt-1 text-sm font-medium" style="color:var(--success-color)"><i class="fas fa-flag-checkered mr-1"></i><?= $e(__('Sfida completata!')) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($isMember && $isCurrentYear): ?>
      <form method="post" action="<?= $e($base) ?>" class="flex flex-wrap -mx-3 gap-y-3 items-end mt-1 border-t pt-3">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="scope" value="personal">
        <div class="w-full px-3 md:w-1/2">
          <label class="form-label text-sm"><?= $e(__('Titolo')) ?></label>
          <input type="text" name="title" required maxlength="190" placeholder="<?= $e(__('Es. 12 libri in un anno')) ?>" class="form-input">
        </div>
        <div class="w-1/2 px-3 md:w-1/4">
          <label class="form-label text-sm"><?= $e(__('Metrica')) ?></label>
          <select name="metric" class="form-input">
            <?php foreach ($metricLabels as $key => $label): ?>
              <option value="<?= $e($key) ?>"><?= $e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="w-1/2 px-3 md:w-1/4">
          <label class="form-label text-sm"><?= $e(__('Obiettivo annuale')) ?></label>
          <input type="number" name="target" min="1" max="1000000" required class="form-input">
        </div>
        <div class="w-full px-3">
          <button type="submit" class="bc-btn">
            <i class="fas fa-plus"></i><?= $e(__('Nuova sfida personale')) ?>
          </button>
        </div>
      </form>
    <?php endif; ?>
  </section>
</div>

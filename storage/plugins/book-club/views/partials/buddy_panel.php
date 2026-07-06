<?php
/**
 * Book Club — buddy module panel on the public club page (active members
 * only): my pairings — pending invites with accept/decline, sent proposals
 * with withdraw, active pairings with mark-done — plus the propose form
 * (current-flagged club book + another active member).
 *
 * @var array<string, mixed> $club
 * @var list<array<string, mixed>> $pairings   rows involving the viewer
 * @var list<array<string, mixed>> $books      current-flagged club books
 * @var list<array{user_id: int, name: string}> $partners other active members
 * @var int $userId
 * @var string $csrf
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$base = url('/book-club/' . $slug . '/buddy');

$statusMeta = [
    'proposed' => [__('In attesa'), 'bc-badge-warn'],
    'active' => [__('Attiva'), 'bc-badge-open'],
    'done' => [__('Conclusa'), 'bc-badge-closed'],
];
?>
<section class="bc-card">
  <div class="bc-section-header">
    <i class="fas fa-user-friends"></i>
    <h2><?= $e(__('Buddy Reading')) ?></h2>
  </div>

  <?php if ($pairings === []): ?>
    <p class="bc-muted mb-2"><?= $e(__('Nessuna lettura in coppia: proponine una a un altro membro!')) ?></p>
  <?php endif; ?>

  <?php foreach ($pairings as $pairing): ?>
    <?php
      $pairingId = (int) $pairing['id'];
      $status = (string) $pairing['status'];
      [$statusLabel, $statusClass] = $statusMeta[$status] ?? [$status, 'bc-badge-closed'];
      $iAmA = (int) $pairing['user_a'] === $userId;
      $partnerName = $iAmA ? (string) $pairing['name_b'] : (string) $pairing['name_a'];
      $iProposed = (int) $pairing['created_by'] === $userId;
    ?>
    <div class="border rounded-3 px-3 py-3 mb-3">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="overflow-hidden">
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="fw-semibold text-truncate"><i class="fas fa-book me-1 text-muted"></i><?= $e($pairing['book_title']) ?></span>
            <span class="bc-badge <?= $statusClass ?>"><?= $e($statusLabel) ?></span>
          </div>
          <div class="bc-muted small mt-1">
            <i class="far fa-user me-1"></i><?= $e(sprintf(__('In coppia con %s'), $partnerName)) ?>
            <?php if ($status === 'proposed'): ?>
              · <?= $iProposed ? $e(__('proposta inviata')) : $e(__('ti ha invitato')) ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="d-flex align-items-center gap-2 flex-shrink-0">
          <?php if ($status === 'proposed' && !$iProposed): ?>
            <form method="post" action="<?= $e($base . '/' . $pairingId . '/accept') ?>">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="bc-btn bc-btn-sm">
                <i class="fas fa-check"></i><?= $e(__('Accetta')) ?>
              </button>
            </form>
            <form method="post" action="<?= $e($base . '/' . $pairingId . '/decline') ?>">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="bc-btn bc-btn-outline bc-btn-sm">
                <i class="fas fa-times"></i><?= $e(__('Rifiuta')) ?>
              </button>
            </form>
          <?php elseif ($status === 'proposed' && $iProposed): ?>
            <form method="post" action="<?= $e($base . '/' . $pairingId . '/decline') ?>"
                  onsubmit="return confirm('<?= $e(__('Ritirare questa proposta?')) ?>');">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm">
                <i class="fas fa-trash"></i><?= $e(__('Ritira')) ?>
              </button>
            </form>
          <?php elseif ($status === 'active'): ?>
            <form method="post" action="<?= $e($base . '/' . $pairingId . '/done') ?>"
                  onsubmit="return confirm('<?= $e(__('Segnare questa lettura in coppia come conclusa?')) ?>');">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="bc-btn bc-btn-outline bc-btn-sm">
                <i class="fas fa-flag-checkered"></i><?= $e(__('Concludi')) ?>
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($books !== [] && $partners !== []): ?>
    <form method="post" action="<?= $e($base . '/propose') ?>" class="mt-4 pt-4 border-top">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
      <div class="row g-3 align-items-end">
        <div class="col-12 col-sm-5">
          <label class="form-label bc-muted small fw-semibold mb-1"><?= $e(__('Libro in lettura')) ?></label>
          <select name="club_book_id" required class="form-select">
            <?php foreach ($books as $book): ?>
              <option value="<?= (int) $book['id'] ?>"><?= $e($book['titolo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-sm-5">
          <label class="form-label bc-muted small fw-semibold mb-1"><?= $e(__('Compagno di lettura')) ?></label>
          <select name="partner_id" required class="form-select">
            <?php foreach ($partners as $partner): ?>
              <option value="<?= (int) $partner['user_id'] ?>"><?= $e($partner['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-sm-2">
          <button type="submit" class="bc-btn w-100">
            <i class="fas fa-paper-plane"></i><?= $e(__('Proponi')) ?>
          </button>
        </div>
      </div>
    </form>
  <?php elseif ($books === []): ?>
    <p class="bc-muted small mt-2 mb-0"><?= $e(__('Nessun libro attualmente in lettura: la coppia si propone sui libri in corso.')) ?></p>
  <?php endif; ?>
</section>

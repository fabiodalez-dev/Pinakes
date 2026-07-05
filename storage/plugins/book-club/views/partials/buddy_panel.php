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
    'proposed' => [__('In attesa'), 'bg-yellow-100 text-yellow-800'],
    'active' => [__('Attiva'), 'bg-green-100 text-green-800'],
    'done' => [__('Conclusa'), 'bg-gray-100 text-gray-600'],
];
?>
<section class="bg-white rounded-xl shadow p-6">
  <h2 class="text-lg font-semibold text-gray-900 mb-4"><i class="fas fa-user-friends mr-2 text-gray-400"></i><?= $e(__('Buddy Reading')) ?></h2>

  <?php if ($pairings === []): ?>
    <p class="text-sm text-gray-400 mb-2"><?= $e(__('Nessuna lettura in coppia: proponine una a un altro membro!')) ?></p>
  <?php endif; ?>

  <?php foreach ($pairings as $pairing): ?>
    <?php
      $pairingId = (int) $pairing['id'];
      $status = (string) $pairing['status'];
      [$statusLabel, $statusClass] = $statusMeta[$status] ?? [$status, 'bg-gray-100 text-gray-600'];
      $iAmA = (int) $pairing['user_a'] === $userId;
      $partnerName = $iAmA ? (string) $pairing['name_b'] : (string) $pairing['name_a'];
      $iProposed = (int) $pairing['created_by'] === $userId;
    ?>
    <div class="border rounded-lg px-4 py-3 mb-3">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="font-medium text-gray-900 truncate"><i class="fas fa-book mr-1 text-gray-300"></i><?= $e($pairing['book_title']) ?></span>
            <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $statusClass ?>"><?= $e($statusLabel) ?></span>
          </div>
          <div class="text-xs text-gray-400 mt-0.5">
            <i class="far fa-user mr-1"></i><?= $e(sprintf(__('In coppia con %s'), $partnerName)) ?>
            <?php if ($status === 'proposed'): ?>
              · <?= $iProposed ? $e(__('proposta inviata')) : $e(__('ti ha invitato')) ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="flex items-center gap-2 shrink-0">
          <?php if ($status === 'proposed' && !$iProposed): ?>
            <form method="post" action="<?= $e($base . '/' . $pairingId . '/accept') ?>">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="px-3 py-1.5 text-xs bg-gray-900 hover:bg-gray-700 text-white rounded-lg">
                <i class="fas fa-check mr-1"></i><?= $e(__('Accetta')) ?>
              </button>
            </form>
            <form method="post" action="<?= $e($base . '/' . $pairingId . '/decline') ?>">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg">
                <i class="fas fa-times mr-1"></i><?= $e(__('Rifiuta')) ?>
              </button>
            </form>
          <?php elseif ($status === 'proposed' && $iProposed): ?>
            <form method="post" action="<?= $e($base . '/' . $pairingId . '/decline') ?>"
                  onsubmit="return confirm('<?= $e(__('Ritirare questa proposta?')) ?>');">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="text-xs text-red-500 hover:text-red-700 whitespace-nowrap">
                <i class="fas fa-trash mr-1"></i><?= $e(__('Ritira')) ?>
              </button>
            </form>
          <?php elseif ($status === 'active'): ?>
            <form method="post" action="<?= $e($base . '/' . $pairingId . '/done') ?>"
                  onsubmit="return confirm('<?= $e(__('Segnare questa lettura in coppia come conclusa?')) ?>');">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg">
                <i class="fas fa-flag-checkered mr-1"></i><?= $e(__('Concludi')) ?>
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($books !== [] && $partners !== []): ?>
    <form method="post" action="<?= $e($base . '/propose') ?>" class="mt-4 border-t pt-4 grid grid-cols-1 sm:grid-cols-5 gap-3 items-end">
      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
      <div class="sm:col-span-2">
        <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Libro in lettura')) ?></label>
        <select name="club_book_id" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
          <?php foreach ($books as $book): ?>
            <option value="<?= (int) $book['id'] ?>"><?= $e($book['titolo']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="sm:col-span-2">
        <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Compagno di lettura')) ?></label>
        <select name="partner_id" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
          <?php foreach ($partners as $partner): ?>
            <option value="<?= (int) $partner['user_id'] ?>"><?= $e($partner['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <button type="submit" class="w-full px-4 py-2 bg-gray-900 hover:bg-gray-700 text-white text-sm rounded-lg">
          <i class="fas fa-paper-plane mr-1"></i><?= $e(__('Proponi')) ?>
        </button>
      </div>
    </form>
  <?php elseif ($books === []): ?>
    <p class="text-xs text-gray-400 mt-2"><?= $e(__('Nessun libro attualmente in lettura: la coppia si propone sui libri in corso.')) ?></p>
  <?php endif; ?>
</section>

<?php
/**
 * Book Club — lending module page (Prestito tra membri, plan §7.17).
 *  - Offer form: any non-pending club book + optional notes.
 *  - Open offers of the other members with a request button.
 *  - My offers with their state + lender actions (decline / hand over /
 *    return / cancel).
 *  - My borrowings (requested → waiting, active → due date + return).
 *
 * @var array<string, mixed> $club
 * @var int $userId
 * @var list<array<string, mixed>> $books         non-pending club books
 * @var list<array<string, mixed>> $openOffers    status 'offered'
 * @var list<array<string, mixed>> $myOffers      rows where I am the lender
 * @var list<array<string, mixed>> $myBorrowings  rows where I am the borrower
 * @var array{type: string, message: string}|null $flash
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = (string) $club['slug'];
$csrf = \App\Support\Csrf::ensureToken();
$base = url('/book-club/' . $slug . '/lending');

$statusLabels = [
    'offered' => __('In offerta'),
    'requested' => __('Richiesta'),
    'active' => __('In prestito'),
    'returned' => __('Restituita'),
    'cancelled' => __('Annullata'),
];
$statusBadge = static function (string $status) use ($e, $statusLabels): string {
    $classes = match ($status) {
        'offered' => 'bg-blue-50 text-blue-700',
        'requested' => 'bg-amber-50 text-amber-700',
        'active' => 'bg-green-50 text-green-700',
        'returned' => 'bg-gray-100 text-gray-600',
        default => 'bg-gray-100 text-gray-400',
    };
    $icon = match ($status) {
        'offered' => 'fa-hand-holding-heart',
        'requested' => 'fa-hand-paper',
        'active' => 'fa-book-reader',
        'returned' => 'fa-check',
        default => 'fa-ban',
    };
    return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs whitespace-nowrap ' . $classes . '">'
        . '<i class="fas ' . $icon . ' mr-1"></i>' . $e($statusLabels[$status] ?? $status) . '</span>';
};
$formatDate = static fn(string $d): string => date('d/m/Y', (int) strtotime($d));
$bookLine = static function (array $loan) use ($e): string {
    $html = '<span class="font-medium text-gray-900">' . $e($loan['titolo']) . '</span>';
    if (!empty($loan['autori'])) {
        $html .= ' <span class="text-sm text-gray-500">— ' . $e($loan['autori']) . '</span>';
    }
    return $html;
};
?>
<div class="max-w-4xl mx-auto px-4 py-10">
  <a href="<?= $e(url('/book-club/' . $slug)) ?>" class="text-sm text-gray-500 hover:text-gray-700">
    <i class="fas fa-arrow-left mr-1"></i><?= $e(__('Torna al club')) ?>
  </a>

  <div class="flex flex-wrap items-center justify-between gap-3 mt-4 mb-2">
    <h1 class="text-2xl font-bold text-gray-900 flex items-center">
      <span class="inline-block w-3 h-3 rounded-full mr-3" style="background: <?= $e($club['color']) ?>"></span>
      <?= $e(__('Prestito tra membri')) ?> — <?= $e($club['name']) ?>
    </h1>
  </div>
  <p class="text-sm text-gray-500 mb-6"><?= $e(__('Qui i membri si prestano le proprie copie personali: le copie della biblioteca si prenotano dalla scheda del libro.')) ?></p>

  <?php if (!empty($flash)): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-800' : ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800' : 'bg-red-50 text-red-800') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Offer a personal copy -->
  <section class="bg-white rounded-xl shadow p-6 mb-8">
    <h2 class="text-lg font-semibold text-gray-900 mb-4"><i class="fas fa-hand-holding-heart mr-2 text-gray-400"></i><?= $e(__('Offri una tua copia')) ?></h2>
    <?php if ($books === []): ?>
      <p class="text-sm text-gray-400"><?= $e(__('Nessun libro nel club: aggiungi prima un libro per offrirne una copia.')) ?></p>
    <?php else: ?>
      <form method="post" action="<?= $e($base . '/offer') ?>" class="grid grid-cols-1 sm:grid-cols-6 gap-3">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <div class="sm:col-span-6">
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Libro')) ?></label>
          <select name="club_book_id" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
            <?php foreach ($books as $book): ?>
              <option value="<?= (int) $book['id'] ?>">
                <?= $e($book['titolo']) ?><?= !empty($book['autori']) ? ' — ' . $e($book['autori']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sm:col-span-6">
          <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Note sulla copia (facoltative)')) ?></label>
          <input type="text" name="notes" maxlength="500"
                 placeholder="<?= $e(__('Es. edizione tascabile, qualche sottolineatura a matita…')) ?>"
                 class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
        </div>
        <div class="sm:col-span-6">
          <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
            <i class="fas fa-plus mr-1"></i><?= $e(__('Offri la copia')) ?>
          </button>
        </div>
      </form>
    <?php endif; ?>
  </section>

  <!-- Open offers -->
  <section class="bg-white rounded-xl shadow p-6 mb-8">
    <h2 class="text-lg font-semibold text-gray-900 mb-4"><i class="fas fa-book-open mr-2 text-gray-400"></i><?= $e(__('Copie offerte dai membri')) ?></h2>
    <?php if ($openOffers === []): ?>
      <p class="text-sm text-gray-400"><?= $e(__('Nessuna copia disponibile al momento.')) ?></p>
    <?php endif; ?>
    <?php foreach ($openOffers as $offer): ?>
      <?php
        $lenderName = trim((string) $offer['lender_nome'] . ' ' . (string) $offer['lender_cognome']);
        $isMine = (int) $offer['lender_id'] === $userId;
      ?>
      <div class="border-t first:border-t-0 py-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <div><?= $bookLine($offer) ?></div>
            <div class="text-xs text-gray-400 mt-1">
              <i class="far fa-user mr-0.5"></i><?= $e(sprintf(__('Offerta da %s'), $lenderName)) ?>
              · <?= $e($formatDate((string) $offer['offered_at'])) ?>
            </div>
            <?php if ((string) ($offer['notes'] ?? '') !== ''): ?>
              <p class="text-sm text-gray-500 mt-1"><i class="far fa-sticky-note mr-1 text-gray-300"></i><?= $e($offer['notes']) ?></p>
            <?php endif; ?>
          </div>
          <?php if ($isMine): ?>
            <span class="text-xs text-gray-400"><?= $e(__('È una tua offerta')) ?></span>
          <?php else: ?>
            <form method="post" action="<?= $e($base . '/' . (int) $offer['id'] . '/request') ?>">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="px-3 py-1.5 text-xs bg-blue-600 hover:bg-blue-700 text-white rounded-lg whitespace-nowrap">
                <i class="fas fa-hand-paper mr-1"></i><?= $e(__('Richiedi in prestito')) ?>
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </section>

  <!-- My offers -->
  <section class="bg-white rounded-xl shadow p-6 mb-8">
    <h2 class="text-lg font-semibold text-gray-900 mb-4"><i class="fas fa-hand-holding mr-2 text-gray-400"></i><?= $e(__('Le mie offerte')) ?></h2>
    <?php if ($myOffers === []): ?>
      <p class="text-sm text-gray-400"><?= $e(__('Non hai ancora offerto nessuna copia.')) ?></p>
    <?php endif; ?>
    <?php foreach ($myOffers as $loan): ?>
      <?php
        $lid = (int) $loan['id'];
        $status = (string) $loan['status'];
        $borrowerName = trim((string) ($loan['borrower_nome'] ?? '') . ' ' . (string) ($loan['borrower_cognome'] ?? ''));
      ?>
      <div class="border-t first:border-t-0 py-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <div><?= $bookLine($loan) ?></div>
            <div class="text-xs text-gray-400 mt-1">
              <?= $e($formatDate((string) $loan['offered_at'])) ?>
              <?php if ($status === 'requested' && $borrowerName !== ''): ?>
                · <i class="far fa-user mr-0.5"></i><?= $e(sprintf(__('Richiesta da %s'), $borrowerName)) ?>
              <?php elseif ($status === 'active' && $borrowerName !== ''): ?>
                · <i class="far fa-user mr-0.5"></i><?= $e(sprintf(__('Prestata a %s'), $borrowerName)) ?>
                <?php if (!empty($loan['due_on'])): ?>
                  · <?= $e(sprintf(__('Da restituire entro il %s'), $formatDate((string) $loan['due_on']))) ?>
                <?php endif; ?>
              <?php elseif ($status === 'returned' && !empty($loan['returned_at'])): ?>
                · <?= $e(sprintf(__('Restituita il %s'), $formatDate((string) $loan['returned_at']))) ?>
              <?php endif; ?>
            </div>
            <?php if ((string) ($loan['notes'] ?? '') !== ''): ?>
              <p class="text-sm text-gray-500 mt-1"><i class="far fa-sticky-note mr-1 text-gray-300"></i><?= $e($loan['notes']) ?></p>
            <?php endif; ?>
          </div>
          <?= $statusBadge($status) ?>
        </div>

        <?php if ($status === 'requested'): ?>
          <div class="flex flex-wrap items-end gap-2 mt-3">
            <form method="post" action="<?= $e($base . '/' . $lid . '/handover') ?>" class="flex flex-wrap items-end gap-2">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1"><?= $e(__('Data di riconsegna (facoltativa)')) ?></label>
                <input type="date" name="due_on" class="border border-gray-200 rounded-lg px-3 py-1.5 text-xs">
              </div>
              <button type="submit" class="px-3 py-1.5 text-xs bg-green-600 hover:bg-green-700 text-white rounded-lg">
                <i class="fas fa-handshake mr-1"></i><?= $e(__('Consegna la copia')) ?>
              </button>
            </form>
            <form method="post" action="<?= $e($base . '/' . $lid . '/decline') ?>">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg">
                <?= $e(__('Rifiuta la richiesta')) ?>
              </button>
            </form>
          </div>
        <?php endif; ?>

        <?php if ($status === 'active'): ?>
          <form method="post" action="<?= $e($base . '/' . $lid . '/return') ?>" class="mt-3">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <button type="submit" class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg">
              <i class="fas fa-undo mr-1"></i><?= $e(__('Segna come restituita')) ?>
            </button>
          </form>
        <?php endif; ?>

        <?php if ($status === 'offered' || $status === 'requested'): ?>
          <form method="post" action="<?= $e($base . '/' . $lid . '/cancel') ?>" class="mt-2"
                onsubmit="return confirm('<?= $e(__('Annullare questa offerta?')) ?>');">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <button type="submit" class="px-3 py-1.5 text-xs text-red-600 hover:bg-red-50 rounded-lg"><?= $e(__('Annulla l\'offerta')) ?></button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>

  <!-- My borrowings -->
  <section class="bg-white rounded-xl shadow p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4"><i class="fas fa-book-reader mr-2 text-gray-400"></i><?= $e(__('I miei prestiti ricevuti')) ?></h2>
    <?php if ($myBorrowings === []): ?>
      <p class="text-sm text-gray-400"><?= $e(__('Non hai richiesto nessuna copia in prestito.')) ?></p>
    <?php endif; ?>
    <?php foreach ($myBorrowings as $loan): ?>
      <?php
        $lid = (int) $loan['id'];
        $status = (string) $loan['status'];
        $lenderName = trim((string) $loan['lender_nome'] . ' ' . (string) $loan['lender_cognome']);
      ?>
      <div class="border-t first:border-t-0 py-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <div><?= $bookLine($loan) ?></div>
            <div class="text-xs text-gray-400 mt-1">
              <i class="far fa-user mr-0.5"></i><?= $e(sprintf(__('Prestata da %s'), $lenderName)) ?>
              <?php if ($status === 'requested'): ?>
                · <?= $e(__('In attesa della consegna')) ?>
              <?php elseif ($status === 'active' && !empty($loan['due_on'])): ?>
                · <?= $e(sprintf(__('Da restituire entro il %s'), $formatDate((string) $loan['due_on']))) ?>
              <?php elseif ($status === 'returned' && !empty($loan['returned_at'])): ?>
                · <?= $e(sprintf(__('Restituita il %s'), $formatDate((string) $loan['returned_at']))) ?>
              <?php endif; ?>
            </div>
          </div>
          <?= $statusBadge($status) ?>
        </div>
        <?php if ($status === 'active'): ?>
          <form method="post" action="<?= $e($base . '/' . $lid . '/return') ?>" class="mt-3">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <button type="submit" class="px-3 py-1.5 text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg">
              <i class="fas fa-undo mr-1"></i><?= $e(__('Segna come restituita')) ?>
            </button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>
</div>

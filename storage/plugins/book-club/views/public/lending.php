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
        'offered' => 'bc-badge-open',
        'requested' => 'bc-badge-warn',
        'active' => 'bc-badge-warn',
        'returned' => 'bc-badge-closed',
        default => 'bc-badge-closed',
    };
    $icon = match ($status) {
        'offered' => 'fa-hand-holding-heart',
        'requested' => 'fa-hand-paper',
        'active' => 'fa-book-reader',
        'returned' => 'fa-check',
        default => 'fa-ban',
    };
    return '<span class="bc-badge text-nowrap ' . $classes . '">'
        . '<i class="fas ' . $icon . '"></i>' . $e($statusLabels[$status] ?? $status) . '</span>';
};
$formatDate = static fn(string $d): string => date('d/m/Y', (int) strtotime($d));
$bookLine = static function (array $loan) use ($e): string {
    $html = '<span class="fw-semibold">' . $e($loan['titolo']) . '</span>';
    if (!empty($loan['autori'])) {
        $html .= ' <span class="bc-muted">— ' . $e($loan['autori']) . '</span>';
    }
    return $html;
};
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
      <span><?= $e(__('Prestito tra membri')) ?> — <?= $e($club['name']) ?></span>
    </h1>
    <p><?= $e(__('Qui i membri si prestano le proprie copie personali: le copie della biblioteca si prenotano dalla scheda del libro.')) ?></p>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>">
      <?= $e($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- Offer a personal copy -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-hand-holding-heart"></i>
      <h2><?= $e(__('Offri una tua copia')) ?></h2>
    </div>
    <?php if ($books === []): ?>
      <p class="bc-muted mb-0"><?= $e(__('Nessun libro nel club: aggiungi prima un libro per offrirne una copia.')) ?></p>
    <?php else: ?>
      <form method="post" action="<?= $e($base . '/offer') ?>" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <div class="col-12">
          <label class="form-label small fw-semibold"><?= $e(__('Libro')) ?></label>
          <select name="club_book_id" required class="form-select">
            <?php foreach ($books as $book): ?>
              <option value="<?= (int) $book['id'] ?>">
                <?= $e($book['titolo']) ?><?= !empty($book['autori']) ? ' — ' . $e($book['autori']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold"><?= $e(__('Note sulla copia (facoltative)')) ?></label>
          <input type="text" name="notes" maxlength="500"
                 placeholder="<?= $e(__('Es. edizione tascabile, qualche sottolineatura a matita…')) ?>"
                 class="form-control">
        </div>
        <div class="col-12">
          <button type="submit" class="bc-btn">
            <i class="fas fa-plus"></i><?= $e(__('Offri la copia')) ?>
          </button>
        </div>
      </form>
    <?php endif; ?>
  </section>

  <!-- Open offers -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-book-open"></i>
      <h2><?= $e(__('Copie offerte dai membri')) ?></h2>
    </div>
    <?php if ($openOffers === []): ?>
      <p class="bc-muted mb-0"><?= $e(__('Nessuna copia disponibile al momento.')) ?></p>
    <?php endif; ?>
    <?php foreach ($openOffers as $offer): ?>
      <?php
        $lenderName = trim((string) $offer['lender_nome'] . ' ' . (string) $offer['lender_cognome']);
        $isMine = (int) $offer['lender_id'] === $userId;
      ?>
      <div class="bc-list-item flex-wrap">
        <div class="flex-grow-1">
          <div><?= $bookLine($offer) ?></div>
          <div class="bc-muted mt-1">
            <i class="far fa-user me-1"></i><?= $e(sprintf(__('Offerta da %s'), $lenderName)) ?>
            · <?= $e($formatDate((string) $offer['offered_at'])) ?>
          </div>
          <?php if ((string) ($offer['notes'] ?? '') !== ''): ?>
            <p class="bc-muted mt-1 mb-0"><i class="far fa-sticky-note me-1"></i><?= $e($offer['notes']) ?></p>
          <?php endif; ?>
        </div>
        <?php if ($isMine): ?>
          <span class="bc-muted flex-shrink-0"><?= $e(__('È una tua offerta')) ?></span>
        <?php else: ?>
          <form method="post" action="<?= $e($base . '/' . (int) $offer['id'] . '/request') ?>" class="flex-shrink-0">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <button type="submit" class="bc-btn bc-btn-sm">
              <i class="fas fa-hand-paper"></i><?= $e(__('Richiedi in prestito')) ?>
            </button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>

  <!-- My offers -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-hand-holding"></i>
      <h2><?= $e(__('Le mie offerte')) ?></h2>
    </div>
    <?php if ($myOffers === []): ?>
      <p class="bc-muted mb-0"><?= $e(__('Non hai ancora offerto nessuna copia.')) ?></p>
    <?php endif; ?>
    <?php foreach ($myOffers as $loan): ?>
      <?php
        $lid = (int) $loan['id'];
        $status = (string) $loan['status'];
        $borrowerName = trim((string) ($loan['borrower_nome'] ?? '') . ' ' . (string) ($loan['borrower_cognome'] ?? ''));
      ?>
      <div class="bc-list-item flex-wrap">
        <div class="flex-grow-1">
          <div><?= $bookLine($loan) ?></div>
          <div class="bc-muted mt-1">
            <?= $e($formatDate((string) $loan['offered_at'])) ?>
            <?php if ($status === 'requested' && $borrowerName !== ''): ?>
              · <i class="far fa-user me-1"></i><?= $e(sprintf(__('Richiesta da %s'), $borrowerName)) ?>
            <?php elseif ($status === 'active' && $borrowerName !== ''): ?>
              · <i class="far fa-user me-1"></i><?= $e(sprintf(__('Prestata a %s'), $borrowerName)) ?>
              <?php if (!empty($loan['due_on'])): ?>
                · <?= $e(sprintf(__('Da restituire entro il %s'), $formatDate((string) $loan['due_on']))) ?>
              <?php endif; ?>
            <?php elseif ($status === 'returned' && !empty($loan['returned_at'])): ?>
              · <?= $e(sprintf(__('Restituita il %s'), $formatDate((string) $loan['returned_at']))) ?>
            <?php endif; ?>
          </div>
          <?php if ((string) ($loan['notes'] ?? '') !== ''): ?>
            <p class="bc-muted mt-1 mb-0"><i class="far fa-sticky-note me-1"></i><?= $e($loan['notes']) ?></p>
          <?php endif; ?>

          <?php if ($status === 'requested'): ?>
            <div class="d-flex flex-wrap align-items-end gap-2 mt-3">
              <form method="post" action="<?= $e($base . '/' . $lid . '/handover') ?>" class="d-flex flex-wrap align-items-end gap-2">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <div>
                  <label class="form-label small fw-semibold"><?= $e(__('Data di riconsegna (facoltativa)')) ?></label>
                  <input type="date" name="due_on" class="form-control form-control-sm">
                </div>
                <button type="submit" class="bc-btn bc-btn-sm">
                  <i class="fas fa-handshake"></i><?= $e(__('Consegna la copia')) ?>
                </button>
              </form>
              <form method="post" action="<?= $e($base . '/' . $lid . '/decline') ?>">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <button type="submit" class="bc-btn bc-btn-outline bc-btn-sm">
                  <?= $e(__('Rifiuta la richiesta')) ?>
                </button>
              </form>
            </div>
          <?php endif; ?>

          <?php if ($status === 'active'): ?>
            <form method="post" action="<?= $e($base . '/' . $lid . '/return') ?>" class="mt-3">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="bc-btn bc-btn-outline bc-btn-sm">
                <i class="fas fa-undo"></i><?= $e(__('Segna come restituita')) ?>
              </button>
            </form>
          <?php endif; ?>

          <?php if ($status === 'offered' || $status === 'requested'): ?>
            <form method="post" action="<?= $e($base . '/' . $lid . '/cancel') ?>" class="mt-2"
                  onsubmit="return confirm('<?= $e(__('Annullare questa offerta?')) ?>');">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="bc-btn bc-btn-danger bc-btn-sm"><?= $e(__('Annulla l\'offerta')) ?></button>
            </form>
          <?php endif; ?>
        </div>
        <?= $statusBadge($status) ?>
      </div>
    <?php endforeach; ?>
  </section>

  <!-- My borrowings -->
  <section class="bc-card">
    <div class="bc-section-header">
      <i class="fas fa-book-reader"></i>
      <h2><?= $e(__('I miei prestiti ricevuti')) ?></h2>
    </div>
    <?php if ($myBorrowings === []): ?>
      <p class="bc-muted mb-0"><?= $e(__('Non hai richiesto nessuna copia in prestito.')) ?></p>
    <?php endif; ?>
    <?php foreach ($myBorrowings as $loan): ?>
      <?php
        $lid = (int) $loan['id'];
        $status = (string) $loan['status'];
        $lenderName = trim((string) $loan['lender_nome'] . ' ' . (string) $loan['lender_cognome']);
      ?>
      <div class="bc-list-item flex-wrap">
        <div class="flex-grow-1">
          <div><?= $bookLine($loan) ?></div>
          <div class="bc-muted mt-1">
            <i class="far fa-user me-1"></i><?= $e(sprintf(__('Prestata da %s'), $lenderName)) ?>
            <?php if ($status === 'requested'): ?>
              · <?= $e(__('In attesa della consegna')) ?>
            <?php elseif ($status === 'active' && !empty($loan['due_on'])): ?>
              · <?= $e(sprintf(__('Da restituire entro il %s'), $formatDate((string) $loan['due_on']))) ?>
            <?php elseif ($status === 'returned' && !empty($loan['returned_at'])): ?>
              · <?= $e(sprintf(__('Restituita il %s'), $formatDate((string) $loan['returned_at']))) ?>
            <?php endif; ?>
          </div>
          <?php if ($status === 'active'): ?>
            <form method="post" action="<?= $e($base . '/' . $lid . '/return') ?>" class="mt-3">
              <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
              <button type="submit" class="bc-btn bc-btn-outline bc-btn-sm">
                <i class="fas fa-undo"></i><?= $e(__('Segna come restituita')) ?>
              </button>
            </form>
          <?php endif; ?>
        </div>
        <?= $statusBadge($status) ?>
      </div>
    <?php endforeach; ?>
  </section>
</div>

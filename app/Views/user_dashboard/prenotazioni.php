<?php
use App\Support\Csrf;
use App\Support\HtmlHelper;

$csrfToken = Csrf::ensureToken();
?>

<link rel="stylesheet" href="/assets/star-rating/dist/star-rating.css">

<style>
  .loans-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 2rem 1rem;
  }

  .section-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.5rem;
  }

  .section-icon {
    width: 48px;
    height: 48px;
    background: #1f2937;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .section-icon i,
  .section-icon svg {
    color: #ffffff;
    width: 1.25rem;
    height: 1.25rem;
  }

  .section-title h2 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #111827;
    margin: 0 0 0.25rem 0;
  }

  .section-title p {
    font-size: 0.875rem;
    color: #6b7280;
    margin: 0;
  }

  .section-divider {
    margin: 3rem 0;
    border-top: 2px solid #e5e7eb;
  }

  .items-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(480px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
  }

  @media (max-width: 768px) {
    .items-grid {
      grid-template-columns: 1fr;
    }
  }

  .item-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e5e7eb;
    padding: 1.5rem;
    transition: all 0.2s ease;
  }

  .item-card:hover {
    box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
    border-color: #d1d5db;
  }

  .item-inner {
    display: flex;
    gap: 1.25rem;
  }

  .item-cover {
    flex-shrink: 0;
    width: 96px;
    height: 128px;
    background: #f3f4f6;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    cursor: pointer;
    transition: transform 0.2s ease;
  }

  .item-cover:hover {
    transform: scale(1.05);
  }

  .item-cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .item-info {
    flex: 1;
    min-width: 0;
  }

  .item-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: #111827;
    margin: 0 0 1rem 0;
    line-height: 1.4;
  }

  .item-title a {
    color: #111827;
    text-decoration: none;
    transition: color 0.2s ease;
  }

  .item-title a:hover {
    color: #3b82f6;
  }

  .item-badges {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
  }

  .badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.875rem;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 500;
  }

  .badge-active {
    background: #dcfce7;
    color: #15803d;
  }

  .badge-overdue {
    background: #fee2e2;
    color: #991b1b;
  }

  .badge-position {
    background: #dbeafe;
    color: #1e40af;
  }

  .badge-date {
    background: #e9d5ff;
    color: #6b21a8;
  }

  .badge-status {
    background: #f3f4f6;
    color: #4b5563;
  }

  .empty-state {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e5e7eb;
    padding: 3rem 2rem;
    text-align: center;
  }

  .empty-state-icon {
    font-size: 3rem;
    color: #d1d5db;
    margin-bottom: 1rem;
  }

  .empty-state h3 {
    font-size: 1.25rem;
    font-weight: 600;
    color: #111827;
    margin: 0 0 0.5rem 0;
  }

  .empty-state p {
    font-size: 0.875rem;
    color: #6b7280;
    margin: 0;
  }

  .btn-cancel,
  .btn-review {
    margin-top: 1rem;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.625rem 1.25rem;
    border: none;
    border-radius: 8px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
  }

  .btn-cancel {
    background: #ef4444;
    color: #ffffff;
  }

  .btn-cancel:hover {
    background: #dc2626;
  }

  .btn-review {
    background: #0f172a;
    color: #ffffff;
  }

  .btn-review:hover {
    background: #1f2937;
  }

  .btn-review:disabled {
    background: #374151;
    cursor: not-allowed;
  }

  .alert-overdue {
    background: #fef2f2;
    border: 2px solid #fecaca;
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 2rem;
    display: flex;
    align-items: center;
    gap: 1rem;
  }

  .alert-overdue-icon {
    flex-shrink: 0;
    width: 48px;
    height: 48px;
    background: #ef4444;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 1.25rem;
  }

  .alert-overdue-content h3 {
    font-size: 1rem;
    font-weight: 700;
    color: #991b1b;
    margin: 0 0 0.25rem 0;
  }

  .alert-overdue-content p {
    font-size: 0.875rem;
    color: #7f1d1d;
    margin: 0;
  }

  .review-stars {
    color: #fbbf24;
    font-size: 1rem;
  }

  .review-text {
    font-size: 0.875rem;
    color: #6b7280;
    margin-top: 0.5rem;
    line-height: 1.5;
  }

  .review-modal {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(15, 23, 42, 0.65);
    z-index: 1050;
    padding: 1.5rem;
  }

  .review-modal.is-active {
    display: flex;
  }

  .review-modal__dialog {
    background: #ffffff;
    border-radius: 16px;
    width: 100%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    padding: 2rem;
    box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.35);
  }

  .review-modal__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
  }

  .review-modal__title {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0;
    color: #111827;
  }

  .review-modal__close {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: #6b7280;
    cursor: pointer;
  }

  .review-modal__subtitle {
    font-size: 1.125rem;
    color: #6b7280;
    margin-bottom: 1.5rem;
  }

  .review-modal__field {
    margin-bottom: 1.5rem;
  }

  .review-modal__label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: #111827;
  }

  .review-modal__input,
  .review-modal__textarea,
  .review-modal__select {
    width: 100%;
    padding: 0.625rem 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.95rem;
    color: #111827;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
  }

  .review-modal__input:focus,
  .review-modal__textarea:focus,
  .review-modal__select:focus {
    border-color: #1f2937;
    outline: none;
    box-shadow: 0 0 0 3px rgba(31, 41, 55, 0.15);
  }

  .review-modal__textarea {
    resize: vertical;
    min-height: 140px;
  }

  .review-modal__actions {
    display: flex;
    gap: 1rem;
  }

  .review-modal__button {
    flex: 1;
    padding: 0.75rem 1rem;
    border-radius: 8px;
    border: none;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s ease, transform 0.2s ease;
  }

  .review-modal__button--secondary {
    background: #e5e7eb;
    color: #374151;
  }

  .review-modal__button--secondary:hover {
    background: #d1d5db;
  }

  .review-modal__button--primary {
    background: #0f172a;
    color: #ffffff;
  }

  .review-modal__button--primary:hover {
    background: #1f2937;
    transform: translateY(-1px);
  }

  .gl-star-rating {
    font-size: 2rem;
  }
</style>

<div class="loans-container">
  <?php
    $overdueCount = 0;
    foreach ($activePrestiti as $loan) {
        $dueAt = $loan['data_scadenza'] ?? '';
        if ($dueAt !== '' && strtotime($dueAt) < time()) {
            $overdueCount++;
        }
    }
  ?>

  <?php if ($overdueCount > 0): ?>
    <div class="alert-overdue" role="alert">
      <div class="alert-overdue-icon">
        <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
      </div>
      <div class="alert-overdue-content">
        <h3>Attenzione: <?= $overdueCount; ?> prestito<?= $overdueCount !== 1 ? 'i' : ''; ?> in ritardo</h3>
        <p>Hai libri che dovevano essere restituiti. Restituiscili al più presto per evitare sanzioni.</p>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($pendingRequests)): ?>
    <div class="section-header">
      <div class="section-icon" style="background: #fbbf24;">
        <i class="fas fa-hourglass-half" aria-hidden="true"></i>
      </div>
      <div class="section-title">
        <h$1><?= __("$2") ?></h$1>
        <p><?= count($pendingRequests); ?> richiesta<?= count($pendingRequests) !== 1 ? 'e' : ''; ?> in sospeso</p>
      </div>
    </div>

    <div class="items-grid">
      <?php foreach ($pendingRequests as $request):
        $cover = (string)($request['copertina_url'] ?? '');
        if ($cover !== '' && strncmp($cover, 'uploads/', 8) === 0) {
            $cover = '/' . $cover;
        }
        if ($cover === '') {
            $cover = '/uploads/copertine/placeholder.jpg';
        }
        $loanStart = $request['data_prestito'] ?? '';
        $loanEnd = $request['data_scadenza'] ?? '';
      ?>
        <div class="item-card">
          <div class="item-inner">
            <a href="/libro/<?= (int)$request['libro_id']; ?>" class="item-cover">
              <img src="<?= htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>" alt="Copertina" loading="lazy" onerror="this.src='/uploads/copertine/placeholder.jpg';">
            </a>
            <div class="item-info">
              <h3 class="item-title"><a href="/libro/<?= (int)$request['libro_id']; ?>"><?= HtmlHelper::e($request['titolo'] ?? ''); ?></a></h3>
              <div class="item-badges">
                <div class="badge" style="background: #fef3c7; color: #78350f; border: 1px solid #fcd34d;">
                  <i class="fas fa-clock" aria-hidden="true" style="color: #f59e0b;"></i>
                  <span>In attesa di approvazione</span>
                </div>
                <?php if ($loanStart && $loanEnd): ?>
                  <div class="badge badge-date">
                    <i class="fas fa-calendar-plus" aria-hidden="true"></i>
                    <span>Dal <?= date('d/m/Y', strtotime($loanStart)); ?> al <?= date('d/m/Y', strtotime($loanEnd)); ?></span>
                  </div>
                <?php endif; ?>
                <div class="badge badge-date" style="font-size: 0.75rem; color: #6b7280;">
                  <i class="fas fa-history" aria-hidden="true"></i>
                  <span>Richiesto il <?= date('d/m/Y H:i', strtotime($request['created_at'] ?? 'now')); ?></span>
                </div>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="section-divider"></div>
  <?php endif; ?>

  <div class="section-header">
    <div class="section-icon">
      <i class="fas fa-book-reader" aria-hidden="true"></i>
    </div>
    <div class="section-title">
      <h$1><?= __("$2") ?></h$1>
      <p><?= count($activePrestiti); ?> prestito<?= count($activePrestiti) !== 1 ? 'i' : ''; ?> attivo<?= count($activePrestiti) !== 1 ? 'i' : ''; ?></p>
    </div>
  </div>

  <?php if (empty($activePrestiti)): ?>
    <div class="empty-state">
      <i class="fas fa-book-open empty-state-icon" aria-hidden="true"></i>
      <h$1><?= __("$2") ?></h$1>
      <p>Non hai libri in prestito al momento</p>
    </div>
  <?php else: ?>
    <div class="items-grid">
      <?php foreach ($activePrestiti as $loan):
        $cover = (string)($loan['copertina_url'] ?? '');
        if ($cover !== '' && strncmp($cover, 'uploads/', 8) === 0) {
            $cover = '/' . $cover;
        }
        if ($cover === '') {
            $cover = '/uploads/copertine/placeholder.jpg';
        }
        $scadenza = $loan['data_scadenza'] ?? '';
        $isOverdue = ($scadenza !== '' && strtotime($scadenza) < time());
        $startDate = $loan['data_prestito'] ?? '';
        $hasReview = !empty($loan['has_review']);
      ?>
        <div class="item-card">
          <div class="item-inner">
            <a href="/libro/<?= (int)$loan['libro_id']; ?>" class="item-cover">
              <img src="<?= htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>" alt="Copertina" loading="lazy" onerror="this.src='/uploads/copertine/placeholder.jpg';">
            </a>
            <div class="item-info">
              <h3 class="item-title"><a href="/libro/<?= (int)$loan['libro_id']; ?>"><?= HtmlHelper::e($loan['titolo'] ?? ''); ?></a></h3>
              <div class="item-badges">
                <div class="badge <?= $isOverdue ? 'badge-overdue' : 'badge-active'; ?>">
                  <i class="fas fa-calendar" aria-hidden="true"></i>
                  <span><?= $isOverdue ? 'In ritardo' : 'Scadenza'; ?>: <?= $scadenza ? date('d/m/Y', strtotime($scadenza)) : 'N/D'; ?></span>
                </div>
                <?php if ($startDate): ?>
                  <div class="badge badge-date">
                    <i class="fas fa-clock" aria-hidden="true"></i>
                    <span>Dal <?= date('d/m/Y', strtotime($startDate)); ?></span>
                  </div>
                <?php endif; ?>
              </div>
              <button type="button" class="btn-review" <?= $hasReview ? 'disabled' : ''; ?> data-book-id="<?= (int)$loan['libro_id']; ?>" data-book-title="<?= HtmlHelper::e($loan['titolo'] ?? ''); ?>">
                <i class="fas fa-star" aria-hidden="true"></i>
                <span><?= $hasReview ? 'Già recensito' : 'Lascia una recensione'; ?></span>
              </button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="section-divider"></div>

  <div class="section-header">
    <div class="section-icon">
      <i class="fas fa-bookmark" aria-hidden="true"></i>
    </div>
    <div class="section-title">
      <h$1><?= __("$2") ?></h$1>
      <p><?= count($items); ?> prenotazione<?= count($items) !== 1 ? 'i' : ''; ?> attiva<?= count($items) !== 1 ? 'e' : ''; ?></p>
    </div>
  </div>

  <?php if (empty($items)): ?>
    <div class="empty-state">
      <i class="fas fa-calendar-times empty-state-icon" aria-hidden="true"></i>
      <h$1><?= __("$2") ?></h$1>
      <p>Non hai prenotazioni attive al momento</p>
    </div>
  <?php else: ?>
    <div class="items-grid">
      <?php foreach ($items as $reservation):
        $cover = (string)($reservation['copertina_url'] ?? '');
        if ($cover !== '' && strncmp($cover, 'uploads/', 8) === 0) {
            $cover = '/' . $cover;
        }
        if ($cover === '') {
            $cover = '/uploads/copertine/placeholder.jpg';
        }
        $deadline = $reservation['data_scadenza_prenotazione'] ?? '';
      ?>
        <div class="item-card">
          <div class="item-inner">
            <a href="/libro/<?= (int)$reservation['libro_id']; ?>" class="item-cover">
              <img src="<?= htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>" alt="Copertina" loading="lazy" onerror="this.src='/uploads/copertine/placeholder.jpg';">
            </a>
            <div class="item-info">
              <h3 class="item-title"><a href="/libro/<?= (int)$reservation['libro_id']; ?>"><?= HtmlHelper::e($reservation['titolo'] ?? ''); ?></a></h3>
              <div class="item-badges">
                <div class="badge badge-position">
                  <i class="fas fa-sort-numeric-up" aria-hidden="true"></i>
                  <span>Posizione: <?= (int)($reservation['queue_position'] ?? 0); ?></span>
                </div>
                <div class="badge badge-date">
                  <i class="fas fa-calendar" aria-hidden="true"></i>
                  <span><?= $deadline ? date('d/m/Y', strtotime($deadline)) : 'Non specificata'; ?></span>
                </div>
              </div>
              <form method="post" action="/reservation/cancel" onsubmit="return confirm(__('Annullare questa prenotazione?'));">
                <input type="hidden" name="csrf_token" value="<?= HtmlHelper::e($csrfToken); ?>">
                <input type="hidden" name="reservation_id" value="<?= (int)$reservation['id']; ?>">
                <button type="submit" class="btn-cancel">
                  <i class="fas fa-trash" aria-hidden="true"></i>
                  <span>Annulla prenotazione</span>
                </button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="section-divider"></div>

  <div class="section-header">
    <div class="section-icon">
      <i class="fas fa-history" aria-hidden="true"></i>
    </div>
    <div class="section-title">
      <h$1><?= __("$2") ?></h$1>
      <p><?= count($pastPrestiti); ?> prestito<?= count($pastPrestiti) !== 1 ? 'i' : ''; ?> passat<?= count($pastPrestiti) !== 1 ? 'i' : 'o'; ?></p>
    </div>
  </div>

  <?php if (empty($pastPrestiti)): ?>
    <div class="empty-state">
      <i class="fas fa-archive empty-state-icon" aria-hidden="true"></i>
      <h$1><?= __("$2") ?></h$1>
      <p>Non hai prestiti passati</p>
    </div>
  <?php else: ?>
    <div class="items-grid">
      <?php foreach ($pastPrestiti as $loan):
        $cover = (string)($loan['copertina_url'] ?? '');
        if ($cover !== '' && strncmp($cover, 'uploads/', 8) === 0) {
            $cover = '/' . $cover;
        }
        if ($cover === '') {
            $cover = '/uploads/copertine/placeholder.jpg';
        }
        $statusLabels = [
          'restituito' => 'Restituito',
          'in_ritardo' => 'Restituito in ritardo',
          'perso' => 'Perso',
          'danneggiato' => 'Danneggiato',
          'prestato' => 'Prestato',
          'in_corso' => 'In corso',
        ];
        $statusLabel = $statusLabels[$loan['stato']] ?? ucfirst(str_replace('_', ' ', (string)$loan['stato']));
        $hasReview = !empty($loan['has_review']);
        $returnDate = $loan['data_restituzione'] ?? '';
      ?>
        <div class="item-card">
          <div class="item-inner">
            <a href="/libro/<?= (int)$loan['libro_id']; ?>" class="item-cover">
              <img src="<?= htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>" alt="Copertina" loading="lazy" onerror="this.src='/uploads/copertine/placeholder.jpg';">
            </a>
            <div class="item-info">
              <h3 class="item-title"><a href="/libro/<?= (int)$loan['libro_id']; ?>"><?= HtmlHelper::e($loan['titolo'] ?? ''); ?></a></h3>
              <div class="item-badges">
                <div class="badge badge-status">
                  <i class="fas fa-check-circle" aria-hidden="true"></i>
                  <span><?= HtmlHelper::e($statusLabel); ?></span>
                </div>
                <?php if ($returnDate): ?>
                  <div class="badge badge-date">
                    <i class="fas fa-calendar" aria-hidden="true"></i>
                    <span><?= date('d/m/Y', strtotime($returnDate)); ?></span>
                  </div>
                <?php endif; ?>
              </div>
              <button type="button" class="btn-review" <?= $hasReview ? 'disabled' : ''; ?> data-book-id="<?= (int)$loan['libro_id']; ?>" data-book-title="<?= HtmlHelper::e($loan['titolo'] ?? ''); ?>">
                <i class="fas fa-star" aria-hidden="true"></i>
                <span><?= $hasReview ? 'Già recensito' : 'Lascia una recensione'; ?></span>
              </button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="section-divider"></div>

  <div class="section-header">
    <div class="section-icon" style="background: #fbbf24;">
      <i class="fas fa-star" aria-hidden="true"></i>
    </div>
    <div class="section-title">
      <h$1><?= __("$2") ?></h$1>
      <p><?= count($myReviews); ?> recensione<?= count($myReviews) !== 1 ? 'i' : ''; ?></p>
    </div>
  </div>

  <?php if (empty($myReviews)): ?>
    <div class="empty-state">
      <i class="fas fa-star empty-state-icon" aria-hidden="true"></i>
      <h$1><?= __("$2") ?></h$1>
      <p>Non hai ancora lasciato recensioni</p>
    </div>
  <?php else: ?>
    <div class="items-grid">
      <?php foreach ($myReviews as $review):
        $cover = (string)($review['libro_copertina'] ?? '');
        if ($cover !== '' && strncmp($cover, 'uploads/', 8) === 0) {
            $cover = '/' . $cover;
        }
        if ($cover === '') {
            $cover = '/uploads/copertine/placeholder.jpg';
        }
        $statusLabels = [
          'pendente' => 'In attesa di approvazione',
          'approvata' => 'Approvata',
          'rifiutata' => 'Rifiutata',
        ];
        $statusColors = [
          'pendente' => 'background: #fef3c7; color: #78350f;',
          'approvata' => 'background: #dcfce7; color: #15803d;',
          'rifiutata' => 'background: #fee2e2; color: #991b1b;',
        ];
        $status = (string)($review['stato'] ?? 'pendente');
        $statusLabel = $statusLabels[$status] ?? ucfirst($status);
        $statusColor = $statusColors[$status] ?? 'background: #f3f4f6; color: #4b5563;';
      ?>
        <div class="item-card">
          <div class="item-inner">
            <a href="/libro/<?= (int)$review['libro_id']; ?>" class="item-cover">
              <img src="<?= htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>" alt="Copertina" loading="lazy" onerror="this.src='/uploads/copertine/placeholder.jpg';">
            </a>
            <div class="item-info">
              <h3 class="item-title"><a href="/libro/<?= (int)$review['libro_id']; ?>"><?= HtmlHelper::e($review['libro_titolo'] ?? ''); ?></a></h3>
              <div class="item-badges">
                <div class="badge" style="<?= $statusColor; ?>">
                  <i class="fas fa-info-circle" aria-hidden="true"></i>
                  <span><?= HtmlHelper::e($statusLabel); ?></span>
                </div>
                <div class="badge badge-date">
                  <i class="fas fa-calendar" aria-hidden="true"></i>
                  <span><?= date('d/m/Y', strtotime($review['created_at'] ?? 'now')); ?></span>
                </div>
              </div>
              <div class="review-stars" aria-label="Valutazione: <?= (int)$review['stelle']; ?> su 5 stelle">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                  <i class="<?= $i <= (int)$review['stelle'] ? 'fas' : 'far'; ?> fa-star" aria-hidden="true"></i>
                <?php endfor; ?>
              </div>
              <?php if (!empty($review['titolo'])): ?>
                <div style="font-weight: 600; margin-top: 0.5rem; font-size: 0.875rem;">
                  "<?= HtmlHelper::e($review['titolo']); ?>"
                </div>
              <?php endif; ?>
              <?php if (!empty($review['descrizione'])): ?>
                <div class="review-text">
                  <?= nl2br(HtmlHelper::e($review['descrizione'])); ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div id="reviewModal" class="review-modal" role="dialog" aria-modal="true" aria-hidden="true">
  <div class="review-modal__dialog">
    <div class="review-modal__header">
      <h3 class="review-modal__title">
        <i class="fas fa-star" aria-hidden="true" style="color: #f59e0b; margin-right: 0.5rem;"></i>
        Lascia una recensione
      </h3>
      <button type="button" class="review-modal__close" aria-label="Chiudi" data-review-modal-close>&times;</button>
    </div>
    <div id="reviewBookTitle" class="review-modal__subtitle"></div>
    <form id="reviewForm">
      <input type="hidden" id="review-book-id" name="libro_id">
      <input type="hidden" name="csrf_token" value="<?= HtmlHelper::e($csrfToken); ?>">

      <div class="review-modal__field">
        <label class="review-modal__label" for="review-stelle">Valutazione *</label>
        <select id="review-stelle" name="stelle" class="review-modal__select" required aria-required="true">
          <option value="">Seleziona</option>
          <option value="5">★★★★★ - Eccellente</option>
          <option value="4">★★★★☆ - Molto buono</option>
          <option value="3">★★★☆☆ - Buono</option>
          <option value="2">★★☆☆☆ - Mediocre</option>
          <option value="1">★☆☆☆☆ - Scarso</option>
        </select>
      </div>

      <div class="review-modal__field">
        <label class="review-modal__label" for="review-titolo">Titolo (opzionale)</label>
        <input type="text" id="review-titolo" name="titolo" maxlength="255" class="review-modal__input" placeholder="Es. Un libro straordinario!">
      </div>

      <div class="review-modal__field">
        <label class="review-modal__label" for="review-descrizione">Recensione (opzionale)</label>
        <textarea id="review-descrizione" name="descrizione" rows="5" maxlength="2000" class="review-modal__textarea" placeholder="Condividi la tua opinione su questo libro..."></textarea>
      </div>

      <div class="review-modal__actions">
        <button type="button" class="review-modal__button review-modal__button--secondary" data-review-modal-close>__("Annulla")</button>
        <button type="submit" class="review-modal__button review-modal__button--primary">Invia recensione</button>
      </div>
    </form>
  </div>
</div>

<script src="/assets/star-rating/dist/star-rating.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const modal = document.getElementById('reviewModal');
  const form = document.getElementById('reviewForm');
  const bookTitleEl = document.getElementById('reviewBookTitle');
  const bookIdInput = document.getElementById('review-book-id');
  const starSelect = document.getElementById('review-stelle');
  const closeButtons = modal.querySelectorAll('[data-review-modal-close]');
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  const hiddenCsrfInput = form.querySelector('input[name="csrf_token"]');

  let starRatingInstance = null;

  if (starSelect && typeof StarRating !== 'undefined') {
    starRatingInstance = new StarRating(starSelect, {
      classNames: {
        active: 'gl-active',
        base: 'gl-star-rating',
        selected: 'gl-selected'
      },
      clearable: false,
      maxStars: 5,
      tooltip: 'Seleziona la valutazione'
    });
  }

  const resetStars = () => {
    if (!starSelect) {
      return;
    }
    starSelect.value = '';
    starSelect.dispatchEvent(new Event('change'));

  };

  const openModal = (bookId, title) => {
    form.reset();
    if (hiddenCsrfInput && csrfMeta) {
      hiddenCsrfInput.value = csrfMeta.getAttribute('content') || hiddenCsrfInput.value;
    }
    resetStars();
    bookIdInput.value = bookId;
    bookTitleEl.textContent = title;

    modal.classList.add('is-active');
    modal.setAttribute('aria-hidden', 'false');
  };

  const closeModal = () => {
    modal.classList.remove('is-active');
    modal.setAttribute('aria-hidden', 'true');
  };

  document.querySelectorAll('.btn-review').forEach(button => {
    if (button.disabled) {
      return;
    }

    button.addEventListener('click', () => {
      const bookId = button.dataset.bookId;
      const title = button.dataset.bookTitle || '';
      openModal(bookId, title);
    });
  });

  closeButtons.forEach(btn => {
    btn.addEventListener('click', closeModal);
  });

  modal.addEventListener('click', event => {
    if (event.target === modal) {
      closeModal();
    }
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && modal.classList.contains('is-active')) {
      closeModal();
    }
  });

  form.addEventListener('submit', async event => {
    event.preventDefault();

    const formData = new FormData(form);
    const stelleValue = formData.get('stelle');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : formData.get('csrf_token');

    if (!stelleValue) {
      Swal.fire({
        icon: 'warning',
        title: __('Attenzione'),
        text: __('Seleziona una valutazione prima di inviare la recensione.'),
        confirmButtonText: __('OK')
      });
      return;
    }

    const payload = {
      libro_id: formData.get('libro_id'),
      stelle: stelleValue,
      titolo: (formData.get('titolo') || '').trim(),
      descrizione: (formData.get('descrizione') || '').trim(),
      csrf_token: csrfToken || ''
    };

    try {
      const response = await fetch('/api/user/recensioni', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-Token': payload.csrf_token
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
      });

      const result = await response.json();

      if (result.success) {
        Swal.fire({
          icon: 'success',
          title: __('Successo!'),
          text: result.message || 'Recensione inviata con successo!',
          confirmButtonText: __('OK')
        }).then(() => {
          closeModal();
          window.location.reload();
        });
      } else {
        Swal.fire({
          icon: 'error',
          title: __('Errore'),
          text: result.message || 'Impossibile inviare la recensione.',
          confirmButtonText: __('OK')
        });
      }
    } catch (error) {
      console.error('Errore invio recensione:', error);
      Swal.fire({
        icon: 'error',
        title: __('Errore di connessione'),
        text: __('Impossibile comunicare con il server. Riprova più tardi.'),
        confirmButtonText: __('OK')
      });
    }
  });
});
</script>

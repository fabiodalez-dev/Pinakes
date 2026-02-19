<?php
/** @var array $activePrestiti */
/** @var array $items */
/** @var array $pastPrestiti */
use App\Support\Csrf;

$profileReservationBookUrl = static function (array $item): string {
    return book_url([
        'libro_id' => $item['libro_id'] ?? null,
        'libro_titolo' => $item['titolo'] ?? ($item['libro_titolo'] ?? ''),
        'autore' => $item['autore'] ?? ($item['libro_autore'] ?? ''),
    ]);
};

$profileReservationCoverUrl = static function (array $item): string {
    $cover = (string)($item['copertina_url'] ?? $item['libro_copertina'] ?? '');
    if ($cover === '' && !empty($item['copertina'])) {
        $cover = (string)$item['copertina'];
    }
    if ($cover !== '' && strncmp($cover, 'uploads/', 8) === 0) {
        $cover = '/' . $cover;
    }
    if ($cover === '') {
        $cover = '/uploads/copertine/placeholder.jpg';
    }
    // Don't apply base path to absolute URLs (e.g. covers from OpenLibrary)
    if (preg_match('#^(https?:)?//#', $cover)) {
        return $cover;
    }
    return url($cover);
};
?>
<!-- Link star-rating.js CSS -->
<link rel="stylesheet" href="<?= htmlspecialchars(assetUrl('star-rating/dist/star-rating.css'), ENT_QUOTES, 'UTF-8') ?>">

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

  .section-icon i {
    color: white;
    font-size: 1.25rem;
  }

  .section-icon svg {
    width: 1.25rem;
    height: 1.25rem;
    color: #ffffff;
    fill: #ffffff;
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
    background: white;
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

  .badge-scheduled {
    background: #dbeafe;
    color: #1e40af;
  }

  .empty-state {
    background: white;
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

  .btn-cancel, .btn-review {
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
    color: white;
  }

  .btn-cancel:hover {
    background: #dc2626;
  }

  .btn-review {
    background: var(--button-color);
    color: var(--button-text-color);
    border: 1px solid var(--button-color);
  }

  .btn-review:hover {
    background: var(--button-hover);
    border-color: var(--button-hover);
  }

  .btn-review:disabled {
    background: var(--button-color);
    border-color: var(--button-color);
    opacity: 0.7;
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
    color: white;
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
</style>

<div class="loans-container">
  <?php
  // Check for overdue loans
  $overdueCount = 0;
  foreach ($activePrestiti as $p) {
    if (!empty($p['data_scadenza']) && strtotime($p['data_scadenza']) < time()) {
      $overdueCount++;
    }
  }
  ?>

  <?php if ($overdueCount > 0): ?>
  <div class="alert-overdue">
    <div class="alert-overdue-icon">
      <i class="fas fa-exclamation-triangle"></i>
    </div>
    <div class="alert-overdue-content">
      <h3><?= __('Attenzione:') ?> <?= __n('%d prestito in ritardo', '%d prestiti in ritardo', $overdueCount, $overdueCount) ?></h3>
      <p><?= __('Hai libri che dovevano essere restituiti. Restituiscili al più presto per evitare sanzioni.') ?></p>
    </div>
  </div>
  <?php endif; ?>

  <!-- Richieste di prestito in sospeso -->
  <?php if (!empty($pendingRequests)): ?>
  <div class="section-header">
    <div class="section-icon" style="background: #fbbf24;">
      <i class="fas fa-hourglass-half" style="color: white;"></i>
    </div>
    <div class="section-title">
      <h2><?= __('Richieste in Sospeso') ?></h2>
      <p><?= __n('%d richiesta in sospeso', '%d richieste in sospeso', count($pendingRequests), count($pendingRequests)) ?></p>
    </div>
  </div>

  <div class="items-grid">
    <?php foreach ($pendingRequests as $p):
      $cover = $profileReservationCoverUrl($p);
    ?>
      <div class="item-card">
        <div class="item-inner">
          <a href="<?php echo htmlspecialchars($profileReservationBookUrl($p), ENT_QUOTES, 'UTF-8'); ?>" class="item-cover">
            <img src="<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
                 alt="<?php echo App\Support\HtmlHelper::e(($p['titolo'] ?? __('Libro')) . ' - ' . __('Copertina')); ?>"
                 onerror="this.onerror=null;this.src=(window.BASE_PATH||'')+'/uploads/copertine/placeholder.jpg'">
          </a>
          <div class="item-info">
            <h3 class="item-title"><a href="<?php echo htmlspecialchars($profileReservationBookUrl($p), ENT_QUOTES, 'UTF-8'); ?>"><?php echo App\Support\HtmlHelper::e($p['titolo'] ?? ''); ?></a></h3>
            <div class="item-badges">
              <div class="badge" style="background: #fef3c7; color: #78350f; border: 1px solid #fcd34d;">
                <i class="fas fa-clock" style="color: #f59e0b;"></i>
                <span><?= __('In attesa di approvazione') ?></span>
              </div>
              <div class="badge badge-date">
                <i class="fas fa-calendar-plus"></i>
                <span><?= sprintf('%s %s %s %s', __('Dal'), format_date($p['data_prestito'], false, '/'), __('al'), format_date($p['data_scadenza'], false, '/')) ?></span>
              </div>
              <div class="badge badge-date" style="font-size: 0.75rem; color: #999;">
                <i class="fas fa-history"></i>
                <span><?= sprintf('%s %s', __('Richiesto il'), format_date($p['created_at'], true, '/')) ?></span>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="section-divider"></div>
  <?php endif; ?>

  <!-- Prestiti in corso -->
  <div class="section-header">
    <div class="section-icon">
      <i class="fas fa-book-reader"></i>
    </div>
    <div class="section-title">
      <h2><?= __('Prestiti in Corso') ?></h2>
      <p><?= __n('%d prestito attivo', '%d prestiti attivi', count($activePrestiti), count($activePrestiti)) ?></p>
    </div>
  </div>

  <?php if (empty($activePrestiti)): ?>
    <div class="empty-state">
      <i class="fas fa-book-open empty-state-icon"></i>
      <h3><?= __('Nessun prestito in corso') ?></h3>
      <p><?= __('Non hai libri in prestito al momento') ?></p>
    </div>
  <?php else: ?>
    <div class="items-grid">
      <?php foreach ($activePrestiti as $p):
        $cover = $profileReservationCoverUrl($p);

        $scadenza = $p['data_scadenza'] ?? '';
        $dataPrestito = $p['data_prestito'] ?? '';
        $stato = $p['stato'] ?? 'in_corso';
        $isScheduled = ($stato === 'prenotato');
        $isOverdue = !$isScheduled && $scadenza && strtotime($scadenza) < time();
        $hasReview = !empty($p['has_review']);
      ?>
        <div class="item-card">
          <div class="item-inner">
            <a href="<?php echo htmlspecialchars($profileReservationBookUrl($p), ENT_QUOTES, 'UTF-8'); ?>" class="item-cover">
              <img src="<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
                   alt="<?= __('Copertina') ?>"
                   onerror="this.onerror=null;this.src=(window.BASE_PATH||'')+'/uploads/copertine/placeholder.jpg'">
            </a>
            <div class="item-info">
              <h3 class="item-title"><a href="<?php echo htmlspecialchars($profileReservationBookUrl($p), ENT_QUOTES, 'UTF-8'); ?>"><?php echo App\Support\HtmlHelper::e($p['titolo'] ?? ''); ?></a></h3>
              <div class="item-badges">
                <?php if ($isScheduled): ?>
                <div class="badge badge-scheduled">
                  <i class="fas fa-calendar-check"></i>
                  <span><?= __('Programmato') ?></span>
                </div>
                <div class="badge badge-date">
                  <i class="fas fa-clock"></i>
                  <span><?= sprintf('%s %s %s %s', __('Dal'), format_date($dataPrestito, false, '/'), __('al'), format_date($scadenza, false, '/')) ?></span>
                </div>
                <?php else: ?>
                <div class="badge <?php echo $isOverdue ? 'badge-overdue' : 'badge-active'; ?>">
                  <i class="fas fa-calendar"></i>
                  <span><?= sprintf('%s: %s', $isOverdue ? __('In ritardo') : __('Scadenza'), format_date($scadenza, false, '/')) ?></span>
                </div>
                <div class="badge badge-date">
                  <i class="fas fa-clock"></i>
                  <span><?= sprintf('%s %s', __('Dal'), format_date($dataPrestito, false, '/')) ?></span>
                </div>
                <?php endif; ?>
              </div>
              <?php if ($hasReview): ?>
              <button class="btn-review" disabled>
                <i class="fas fa-star"></i>
                <span><?= __('Già recensito') ?></span>
              </button>
              <?php elseif (!$isScheduled): ?>
              <button class="btn-review" onclick="openReviewModal(<?php echo (int)$p['libro_id']; ?>, <?php echo htmlspecialchars(json_encode($p['titolo'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)">
                <i class="fas fa-star"></i>
                <span><?= __('Lascia una recensione') ?></span>
              </button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="section-divider"></div>

  <!-- Prenotazioni attive -->
  <div class="section-header">
    <div class="section-icon">
      <i class="fas fa-bookmark"></i>
    </div>
    <div class="section-title">
      <h2><?= __('Prenotazioni Attive') ?></h2>
      <p><?= __n('%d prenotazione attiva', '%d prenotazioni attive', count($items), count($items)) ?></p>
    </div>
  </div>

  <?php if (empty($items)): ?>
    <div class="empty-state">
      <i class="fas fa-calendar-times empty-state-icon"></i>
      <h3><?= __('Nessuna prenotazione attiva') ?></h3>
      <p><?= __('Non hai prenotazioni attive al momento') ?></p>
    </div>
  <?php else: ?>
    <div class="items-grid">
      <?php foreach ($items as $p):
        $cover = $profileReservationCoverUrl($p);
      ?>
        <div class="item-card">
          <div class="item-inner">
            <a href="<?php echo htmlspecialchars($profileReservationBookUrl($p), ENT_QUOTES, 'UTF-8'); ?>" class="item-cover">
              <img src="<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
                   alt="<?= __('Copertina') ?>"
                   onerror="this.onerror=null;this.src=(window.BASE_PATH||'')+'/uploads/copertine/placeholder.jpg'">
            </a>
            <div class="item-info">
              <h3 class="item-title"><a href="<?php echo htmlspecialchars($profileReservationBookUrl($p), ENT_QUOTES, 'UTF-8'); ?>"><?php echo App\Support\HtmlHelper::e($p['titolo'] ?? ''); ?></a></h3>
              <div class="item-badges">
                <div class="badge badge-position">
                  <i class="fas fa-sort-numeric-up"></i>
                  <span><?= sprintf('%s: %d', __('Posizione'), (int)($p['queue_position'] ?? 0)) ?></span>
                </div>
                <div class="badge badge-date">
                  <i class="fas fa-calendar"></i>
                  <span><?= !empty($p['data_scadenza_prenotazione']) ? format_date($p['data_scadenza_prenotazione'], false, '/') : __('Non specificata') ?></span>
                </div>
              </div>
              <form method="post" action="<?= htmlspecialchars(url('/reservation/cancel'), ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirm(<?= htmlspecialchars(json_encode(__('Annullare questa prenotazione?'), JSON_HEX_TAG), ENT_QUOTES, 'UTF-8') ?>)">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="reservation_id" value="<?php echo (int)$p['id']; ?>">
                <button type="submit" class="btn-cancel">
                  <i class="fas fa-trash"></i>
                  <span><?= __('Annulla prenotazione') ?></span>
                </button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="section-divider"></div>

  <!-- Storico prestiti -->
  <div class="section-header">
    <div class="section-icon">
      <i class="fas fa-history"></i>
    </div>
    <div class="section-title">
      <h2><?= __('Storico Prestiti') ?></h2>
      <p><?= __n('%d prestito passato', '%d prestiti passati', count($pastPrestiti), count($pastPrestiti)) ?></p>
    </div>
  </div>

  <?php if (empty($pastPrestiti)): ?>
    <div class="empty-state">
      <i class="fas fa-archive empty-state-icon"></i>
      <h3><?= __('Nessuno storico') ?></h3>
      <p><?= __('Non hai prestiti passati') ?></p>
    </div>
  <?php else: ?>
    <div class="items-grid">
      <?php foreach ($pastPrestiti as $p):
        $cover = $profileReservationCoverUrl($p);

        $statusLabels = [
          'restituito' => __('Restituito'),
          'in_ritardo' => __('Restituito in ritardo'),
          'perso' => __('Perso'),
          'danneggiato' => __('Danneggiato'),
          'prestato' => __('Prestato'),
          'in_corso' => __('In corso')
        ];
        $statusLabel = $statusLabels[$p['stato']] ?? ucfirst(str_replace('_', ' ', $p['stato']));
        $hasReview = !empty($p['has_review']);
      ?>
        <div class="item-card">
          <div class="item-inner">
            <a href="<?php echo htmlspecialchars($profileReservationBookUrl($p), ENT_QUOTES, 'UTF-8'); ?>" class="item-cover">
              <img src="<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
                   alt="<?= __('Copertina') ?>"
                   onerror="this.onerror=null;this.src=(window.BASE_PATH||'')+'/uploads/copertine/placeholder.jpg'">
            </a>
            <div class="item-info">
              <h3 class="item-title"><a href="<?php echo htmlspecialchars($profileReservationBookUrl($p), ENT_QUOTES, 'UTF-8'); ?>"><?php echo App\Support\HtmlHelper::e($p['titolo'] ?? ''); ?></a></h3>
              <div class="item-badges">
                <div class="badge badge-status">
                  <i class="fas fa-check-circle"></i>
                  <span><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php if (!empty($p['data_restituzione'])): ?>
                <div class="badge badge-date">
                  <i class="fas fa-calendar"></i>
                  <span><?= format_date($p['data_restituzione'], false, '/') ?></span>
                </div>
                <?php endif; ?>
              </div>
              <?php if ($hasReview): ?>
              <button class="btn-review" disabled>
                <i class="fas fa-star"></i>
                <span><?= __('Già recensito') ?></span>
              </button>
              <?php else: ?>
              <button class="btn-review" onclick="openReviewModal(<?php echo (int)$p['libro_id']; ?>, <?php echo htmlspecialchars(json_encode($p['titolo'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)">
                <i class="fas fa-star"></i>
                <span><?= __('Lascia una recensione') ?></span>
              </button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="section-divider"></div>

  <!-- Le mie recensioni -->
  <div class="section-header">
    <div class="section-icon" style="background: #fbbf24;">
      <i class="fas fa-star"></i>
    </div>
    <div class="section-title">
      <h2><?= __('Le Mie Recensioni') ?></h2>
      <?php $reviewCount = isset($myReviews) ? count($myReviews) : 0; ?>
      <p><?= __n('%d recensione', '%d recensioni', $reviewCount, $reviewCount) ?></p>
    </div>
  </div>

  <?php if (empty($myReviews)): ?>
    <div class="empty-state">
      <i class="fas fa-star empty-state-icon"></i>
      <h3><?= __('Nessuna recensione') ?></h3>
  <p><?= __('Non hai ancora lasciato recensioni') ?></p>
    </div>
  <?php else: ?>
    <div class="items-grid">
      <?php foreach ($myReviews as $r):
        $cover = $profileReservationCoverUrl($r);

        $statusLabels = [
          'pendente' => __('In attesa di approvazione'),
          'approvata' => __('Approvata'),
          'rifiutata' => __('Rifiutata')
        ];
        $statusLabel = $statusLabels[$r['stato']] ?? $r['stato'];
        $statusColors = [
          'pendente' => 'background: #fef3c7; color: #78350f;',
          'approvata' => 'background: #dcfce7; color: #15803d;',
          'rifiutata' => 'background: #fee2e2; color: #991b1b;'
        ];
        $statusColor = $statusColors[$r['stato']] ?? 'background: #f3f4f6; color: #4b5563;';
      ?>
        <div class="item-card">
          <div class="item-inner">
            <a href="<?php echo htmlspecialchars($profileReservationBookUrl($r), ENT_QUOTES, 'UTF-8'); ?>" class="item-cover">
              <img src="<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
                   alt="<?= __('Copertina') ?>"
                   onerror="this.onerror=null;this.src=(window.BASE_PATH||'')+'/uploads/copertine/placeholder.jpg'">
            </a>
            <div class="item-info">
              <h3 class="item-title"><a href="<?php echo htmlspecialchars($profileReservationBookUrl($r), ENT_QUOTES, 'UTF-8'); ?>"><?php echo App\Support\HtmlHelper::e($r['libro_titolo'] ?? ''); ?></a></h3>
              <div class="item-badges">
                <div class="badge" style="<?php echo htmlspecialchars($statusColor, ENT_QUOTES, 'UTF-8'); ?>">
                  <i class="fas fa-info-circle"></i>
                  <span><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="badge badge-date">
                  <i class="fas fa-calendar"></i>
                  <span><?php echo format_date($r['created_at'], false, '/'); ?></span>
                </div>
              </div>
              <div class="review-stars" style="margin-top: 0.75rem;">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <i class="<?php echo $i <= $r['stelle'] ? 'fas' : 'far'; ?> fa-star"></i>
                <?php endfor; ?>
              </div>
              <?php if (!empty($r['titolo'])): ?>
              <div style="font-weight: 600; margin-top: 0.5rem; font-size: 0.875rem;">
                "<?php echo App\Support\HtmlHelper::e($r['titolo']); ?>"
              </div>
              <?php endif; ?>
              <?php if (!empty($r['descrizione'])): ?>
              <div class="review-text">
                <?php echo nl2br(App\Support\HtmlHelper::e($r['descrizione'])); ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Review Modal -->
<div id="reviewModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
  <div style="background: white; border-radius: 16px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto; padding: 2rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
      <h3 style="margin: 0; font-size: 1.5rem; font-weight: 700;"><?= __('Lascia una recensione') ?></h3>
      <button onclick="closeReviewModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #6b7280;" aria-label="<?= __('Chiudi') ?>">&times;</button>
    </div>
    <div id="reviewBookTitle" style="font-size: 1.125rem; color: #6b7280; margin-bottom: 1.5rem;"></div>
    <form id="reviewForm">
      <input type="hidden" id="review-book-id" name="libro_id">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">

      <div style="margin-bottom: 1.5rem;">
        <label style="display: block; font-weight: 600; margin-bottom: 0.5rem;"><?= __('Valutazione *') ?></label>
        <select id="review-stelle" name="stelle" required aria-required="true" style="width: 100%; padding: 0.625rem; border: 1px solid #d1d5db; border-radius: 8px;">
          <option value=""><?= __("Seleziona") ?></option>
          <option value="5">★★★★★ - <?= __('Eccellente') ?></option>
          <option value="4">★★★★☆ - <?= __('Molto buono') ?></option>
          <option value="3">★★★☆☆ - <?= __('Buono') ?></option>
          <option value="2">★★☆☆☆ - <?= __('Mediocre') ?></option>
          <option value="1">★☆☆☆☆ - <?= __('Scarso') ?></option>
        </select>
      </div>

      <div style="margin-bottom: 1.5rem;">
        <label style="display: block; font-weight: 600; margin-bottom: 0.5rem;"><?= __('Titolo (opzionale)') ?></label>
        <input type="text" id="review-titolo" name="titolo" maxlength="255" placeholder="<?= __('Es. Un libro fantastico!') ?>" style="width: 100%; padding: 0.625rem; border: 1px solid #d1d5db; border-radius: 8px;">
      </div>

      <div style="margin-bottom: 1.5rem;">
        <label style="display: block; font-weight: 600; margin-bottom: 0.5rem;"><?= __('Recensione (opzionale)') ?></label>
        <textarea id="review-descrizione" name="descrizione" rows="5" maxlength="2000" placeholder="<?= __('Cosa ne pensi di questo libro?') ?>" style="width: 100%; padding: 0.625rem; border: 1px solid #d1d5db; border-radius: 8px; resize: vertical;"></textarea>
      </div>

      <div style="display: flex; gap: 1rem;">
        <button type="button" onclick="closeReviewModal()" style="flex: 1; padding: 0.75rem; background: #e5e7eb; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;"><?= __('Annulla') ?></button>
        <button type="submit" style="flex: 1; padding: 0.75rem; background: #3b82f6; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;"><?= __('Invia recensione') ?></button>
      </div>
    </form>
  </div>
</div>

<script src="<?= htmlspecialchars(assetUrl('star-rating/dist/star-rating.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
// Global __ function for JavaScript inline handlers (onsubmit, onclick, etc.)
if (typeof window.__ === 'undefined') {
  window.__ = function(key) {
    return key; // Return key as-is if translation not available
  };
}

function openReviewModal(bookId, bookTitle) {
  document.getElementById('review-book-id').value = bookId;
  document.getElementById('reviewBookTitle').textContent = bookTitle;
  document.getElementById('reviewForm').reset();
  document.getElementById('reviewModal').style.display = 'flex';

  const starSelect = document.getElementById('review-stelle');
  if (starSelect && typeof StarRating !== 'undefined') {
    new StarRating(starSelect, {
      classNames: {
        active: 'gl-active',
        base: 'gl-star-rating',
        selected: 'gl-selected'
      },
      clearable: false,
      maxStars: 5,
      tooltip: <?= json_encode(__('Seleziona la valutazione'), JSON_HEX_TAG) ?>
    });
  }
}

function closeReviewModal() {
  document.getElementById('reviewModal').style.display = 'none';
}

document.getElementById('reviewForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const formData = new FormData(this);
  const data = Object.fromEntries(formData);

  try {
    const response = await fetch((window.BASE_PATH || '') + '/api/user/recensioni', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': data.csrf_token
      },
      body: JSON.stringify(data)
    });

    const text = await response.text();

    let result;
    try {
      result = JSON.parse(text);
    } catch (e) {
      console.error('Failed to parse JSON:', e);
      Swal.fire({
        icon: 'error',
        title: __('Errore del server'),
        text: __('Risposta non valida. Controlla la console per dettagli.')
      });
      return;
    }

    if (result.success) {
      Swal.fire({
        icon: 'success',
        title: __('Recensione inviata!'),
        text: __('Sarà pubblicata dopo l\'approvazione di un amministratore.'),
        confirmButtonText: __('OK')
      }).then(() => {
        closeReviewModal();
        location.reload();
      });
    } else {
      Swal.fire({
        icon: 'error',
        title: __('Errore'),
        text: result.message || __('Impossibile inviare la recensione')
      });
    }
  } catch (error) {
    console.error('Error:', error);
    Swal.fire({
      icon: 'error',
      title: __('Errore di connessione'),
      text: error.message
    });
  }
});

document.getElementById('reviewModal').addEventListener('click', function(e) {
  if (e.target === this) {
    closeReviewModal();
  }
});
</script>

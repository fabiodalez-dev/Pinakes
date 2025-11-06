<?php use App\Support\Csrf; ?>
<!-- Link star-rating.js CSS -->
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
    background: #3b82f6;
    color: white;
  }

  .btn-review:hover {
    background: #1f2937;
  }

  .btn-review:disabled {
    background: #9ca3af;
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
      <h3>Attenzione: <?php echo $overdueCount; ?> prestito<?php echo $overdueCount !== 1 ? 'i' : ''; ?> in ritardo</h3>
      <p>Hai libri che dovevano essere restituiti. Restituiscili al più presto per evitare sanzioni.</p>
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
      <h2>Richieste di prestito in attesa</h2>
      <p><?php echo count($pendingRequests); ?> richiesta<?php echo count($pendingRequests) !== 1 ? 'e' : ''; ?> in sospeso</p>
    </div>
  </div>

  <div class="items-grid">
    <?php foreach ($pendingRequests as $p):
      $cover = (string)($p['copertina_url'] ?? '');
      if ($cover === '' && !empty($p['copertina'])) { $cover = (string)$p['copertina']; }
      if ($cover !== '' && strncmp($cover, 'uploads/', 8) === 0) { $cover = '/' . $cover; }
      if ($cover === '') { $cover = '/uploads/copertine/placeholder.jpg'; }
    ?>
      <div class="item-card">
        <div class="item-inner">
          <a href="/libro/<?php echo (int)$p['libro_id']; ?>" class="item-cover">
            <img src="<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
                 alt="<?php echo App\Support\HtmlHelper::e(($p['titolo'] ?? 'Libro') . ' - Copertina'); ?>"
                 onerror="this.src='/uploads/copertine/placeholder.jpg'">
          </a>
          <div class="item-info">
            <h3 class="item-title"><a href="/libro/<?php echo (int)$p['libro_id']; ?>"><?php echo App\Support\HtmlHelper::e($p['titolo'] ?? ''); ?></a></h3>
            <div class="item-badges">
              <div class="badge" style="background: #fef3c7; color: #78350f; border: 1px solid #fcd34d;">
                <i class="fas fa-clock" style="color: #f59e0b;"></i>
                <span>In attesa di approvazione</span>
              </div>
              <div class="badge badge-date">
                <i class="fas fa-calendar-plus"></i>
                <span>Dal <?php echo date('d/m/Y', strtotime($p['data_prestito'])); ?> al <?php echo date('d/m/Y', strtotime($p['data_scadenza'])); ?></span>
              </div>
              <div class="badge badge-date" style="font-size: 0.75rem; color: #999;">
                <i class="fas fa-history"></i>
                <span>Richiesto il <?php echo date('d/m/Y H:i', strtotime($p['created_at'])); ?></span>
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
      <h2>Prestiti in corso</h2>
      <p><?php echo count($activePrestiti); ?> prestito<?php echo count($activePrestiti) !== 1 ? 'i' : ''; ?> attivo<?php echo count($activePrestiti) !== 1 ? 'i' : ''; ?></p>
    </div>
  </div>

  <?php if (empty($activePrestiti)): ?>
    <div class="empty-state">
      <i class="fas fa-book-open empty-state-icon"></i>
      <h3>Nessun prestito attivo</h3>
      <p>Non hai libri in prestito al momento</p>
    </div>
  <?php else: ?>
    <div class="items-grid">
      <?php foreach ($activePrestiti as $p):
        $cover = (string)($p['copertina_url'] ?? '');
        if ($cover === '' && !empty($p['copertina'])) { $cover = (string)$p['copertina']; }
        if ($cover !== '' && strncmp($cover, 'uploads/', 8) === 0) { $cover = '/' . $cover; }
        if ($cover === '') { $cover = '/uploads/copertine/placeholder.jpg'; }

        $scadenza = $p['data_scadenza'] ?? '';
        $isOverdue = $scadenza && strtotime($scadenza) < time();
        $hasReview = !empty($p['has_review']);
      ?>
        <div class="item-card">
          <div class="item-inner">
            <a href="/libro/<?php echo (int)$p['libro_id']; ?>" class="item-cover">
              <img src="<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
                   alt="Copertina"
                   onerror="this.src='/uploads/copertine/placeholder.jpg'">
            </a>
            <div class="item-info">
              <h3 class="item-title"><a href="/libro/<?php echo (int)$p['libro_id']; ?>"><?php echo App\Support\HtmlHelper::e($p['titolo'] ?? ''); ?></a></h3>
              <div class="item-badges">
                <div class="badge <?php echo $isOverdue ? 'badge-overdue' : 'badge-active'; ?>">
                  <i class="fas fa-calendar"></i>
                  <span><?php echo $isOverdue ? 'In ritardo' : 'Scadenza'; ?>: <?php echo date('d/m/Y', strtotime($scadenza)); ?></span>
                </div>
                <div class="badge badge-date">
                  <i class="fas fa-clock"></i>
                  <span>Dal <?php echo date('d/m/Y', strtotime($p['data_prestito'])); ?></span>
                </div>
              </div>
              <?php if ($hasReview): ?>
              <button class="btn-review" disabled>
                <i class="fas fa-star"></i>
                <span>Già recensito</span>
              </button>
              <?php else: ?>
              <button class="btn-review" onclick="openReviewModal(<?php echo (int)$p['libro_id']; ?>, '<?php echo App\Support\HtmlHelper::e($p['titolo'] ?? ''); ?>')">
                <i class="fas fa-star"></i>
                <span>Lascia una recensione</span>
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
      <h2>Prenotazioni attive</h2>
      <p><?php echo count($items); ?> prenotazione<?php echo count($items) !== 1 ? 'i' : ''; ?> attiva<?php echo count($items) !== 1 ? 'e' : ''; ?></p>
    </div>
  </div>

  <?php if (empty($items)): ?>
    <div class="empty-state">
      <i class="fas fa-calendar-times empty-state-icon"></i>
      <h3>Nessuna prenotazione</h3>
      <p>Non hai prenotazioni attive al momento</p>
    </div>
  <?php else: ?>
    <div class="items-grid">
      <?php foreach ($items as $p):
        $cover = (string)($p['copertina_url'] ?? '');
        if ($cover === '' && !empty($p['copertina'])) { $cover = (string)$p['copertina']; }
        if ($cover !== '' && strncmp($cover, 'uploads/', 8) === 0) { $cover = '/' . $cover; }
        if ($cover === '') { $cover = '/uploads/copertine/placeholder.jpg'; }
      ?>
        <div class="item-card">
          <div class="item-inner">
            <a href="/libro/<?php echo (int)$p['libro_id']; ?>" class="item-cover">
              <img src="<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
                   alt="Copertina"
                   onerror="this.src='/uploads/copertine/placeholder.jpg'">
            </a>
            <div class="item-info">
              <h3 class="item-title"><a href="/libro/<?php echo (int)$p['libro_id']; ?>"><?php echo App\Support\HtmlHelper::e($p['titolo'] ?? ''); ?></a></h3>
              <div class="item-badges">
                <div class="badge badge-position">
                  <i class="fas fa-sort-numeric-up"></i>
                  <span>Posizione: <?php echo (int)($p['queue_position'] ?? 0); ?></span>
                </div>
                <div class="badge badge-date">
                  <i class="fas fa-calendar"></i>
                  <span><?php echo !empty($p['data_scadenza_prenotazione']) ? date('d/m/Y', strtotime($p['data_scadenza_prenotazione'])) : 'Non specificata'; ?></span>
                </div>
              </div>
              <form method="post" action="/reservation/cancel" onsubmit="return confirm(__('Annullare questa prenotazione?'))">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="reservation_id" value="<?php echo (int)$p['id']; ?>">
                <button type="submit" class="btn-cancel">
                  <i class="fas fa-trash"></i>
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

  <!-- Storico prestiti -->
  <div class="section-header">
    <div class="section-icon">
      <i class="fas fa-history"></i>
    </div>
    <div class="section-title">
      <h2>Storico prestiti</h2>
      <p><?php echo count($pastPrestiti); ?> prestito<?php echo count($pastPrestiti) !== 1 ? 'i' : ''; ?> passat<?php echo count($pastPrestiti) !== 1 ? 'i' : 'o'; ?></p>
    </div>
  </div>

  <?php if (empty($pastPrestiti)): ?>
    <div class="empty-state">
      <i class="fas fa-archive empty-state-icon"></i>
      <h3>Nessuno storico</h3>
      <p>Non hai prestiti passati</p>
    </div>
  <?php else: ?>
    <div class="items-grid">
      <?php foreach ($pastPrestiti as $p):
        $cover = (string)($p['copertina_url'] ?? '');
        if ($cover === '' && !empty($p['copertina'])) { $cover = (string)$p['copertina']; }
        if ($cover !== '' && strncmp($cover, 'uploads/', 8) === 0) { $cover = '/' . $cover; }
        if ($cover === '') { $cover = '/uploads/copertine/placeholder.jpg'; }

        $statusLabels = [
          'restituito' => 'Restituito',
          'in_ritardo' => 'Restituito in ritardo',
          'perso' => 'Perso',
          'danneggiato' => 'Danneggiato',
          'prestato' => 'Prestato',
          'in_corso' => 'In corso'
        ];
        $statusLabel = $statusLabels[$p['stato']] ?? ucfirst(str_replace('_', ' ', $p['stato']));
        $hasReview = !empty($p['has_review']);
      ?>
        <div class="item-card">
          <div class="item-inner">
            <a href="/libro/<?php echo (int)$p['libro_id']; ?>" class="item-cover">
              <img src="<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
                   alt="Copertina"
                   onerror="this.src='/uploads/copertine/placeholder.jpg'">
            </a>
            <div class="item-info">
              <h3 class="item-title"><a href="/libro/<?php echo (int)$p['libro_id']; ?>"><?php echo App\Support\HtmlHelper::e($p['titolo'] ?? ''); ?></a></h3>
              <div class="item-badges">
                <div class="badge badge-status">
                  <i class="fas fa-check-circle"></i>
                  <span><?php echo $statusLabel; ?></span>
                </div>
                <?php if (!empty($p['data_restituzione'])): ?>
                <div class="badge badge-date">
                  <i class="fas fa-calendar"></i>
                  <span><?php echo date('d/m/Y', strtotime($p['data_restituzione'])); ?></span>
                </div>
                <?php endif; ?>
              </div>
              <?php if ($hasReview): ?>
              <button class="btn-review" disabled>
                <i class="fas fa-star"></i>
                <span>Già recensito</span>
              </button>
              <?php else: ?>
              <button class="btn-review" onclick="openReviewModal(<?php echo (int)$p['libro_id']; ?>, '<?php echo App\Support\HtmlHelper::e($p['titolo'] ?? ''); ?>')">
                <i class="fas fa-star"></i>
                <span>Lascia una recensione</span>
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
      <h2>Le mie recensioni</h2>
      <p><?php echo isset($myReviews) ? count($myReviews) : 0; ?> recensione<?php echo (isset($myReviews) && count($myReviews) !== 1) ? 'i' : ''; ?></p>
    </div>
  </div>

  <?php if (empty($myReviews)): ?>
    <div class="empty-state">
      <i class="fas fa-star empty-state-icon"></i>
      <h3>Nessuna recensione</h3>
      <p>Non hai ancora lasciato recensioni</p>
    </div>
  <?php else: ?>
    <div class="items-grid">
      <?php foreach ($myReviews as $r):
        $cover = (string)($r['libro_copertina'] ?? '');
        if ($cover !== '' && strncmp($cover, 'uploads/', 8) === 0) { $cover = '/' . $cover; }
        if ($cover === '') { $cover = '/uploads/copertine/placeholder.jpg'; }

        $statusLabels = [
          'pendente' => 'In attesa di approvazione',
          'approvata' => 'Approvata',
          'rifiutata' => 'Rifiutata'
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
            <a href="/libro/<?php echo (int)$r['libro_id']; ?>" class="item-cover">
              <img src="<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
                   alt="Copertina"
                   onerror="this.src='/uploads/copertine/placeholder.jpg'">
            </a>
            <div class="item-info">
              <h3 class="item-title"><a href="/libro/<?php echo (int)$r['libro_id']; ?>"><?php echo App\Support\HtmlHelper::e($r['libro_titolo'] ?? ''); ?></a></h3>
              <div class="item-badges">
                <div class="badge" style="<?php echo $statusColor; ?>">
                  <i class="fas fa-info-circle"></i>
                  <span><?php echo $statusLabel; ?></span>
                </div>
                <div class="badge badge-date">
                  <i class="fas fa-calendar"></i>
                  <span><?php echo date('d/m/Y', strtotime($r['created_at'])); ?></span>
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
      <h3 style="margin: 0; font-size: 1.5rem; font-weight: 700;">Lascia una recensione</h3>
      <button onclick="closeReviewModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #6b7280;">&times;</button>
    </div>
    <div id="reviewBookTitle" style="font-size: 1.125rem; color: #6b7280; margin-bottom: 1.5rem;"></div>
    <form id="reviewForm">
      <input type="hidden" id="review-book-id" name="libro_id">
      <input type="hidden" name="csrf_token" value="<?php echo Csrf::generateToken(); ?>">

      <div style="margin-bottom: 1.5rem;">
        <label style="display: block; font-weight: 600; margin-bottom: 0.5rem;">Valutazione *</label>
        <select id="review-stelle" name="stelle" required aria-required="true" style="width: 100%; padding: 0.625rem; border: 1px solid #d1d5db; border-radius: 8px;">
          <option value="">Seleziona</option>
          <option value="5">★★★★★ - Eccellente</option>
          <option value="4">★★★★☆ - Molto buono</option>
          <option value="3">★★★☆☆ - Buono</option>
          <option value="2">★★☆☆☆ - Mediocre</option>
          <option value="1">★☆☆☆☆ - Scarso</option>
        </select>
      </div>

      <div style="margin-bottom: 1.5rem;">
        <label style="display: block; font-weight: 600; margin-bottom: 0.5rem;">Titolo (opzionale)</label>
        <input type="text" id="review-titolo" name="titolo" maxlength="255" placeholder="Es. Un libro fantastico!" style="width: 100%; padding: 0.625rem; border: 1px solid #d1d5db; border-radius: 8px;">
      </div>

      <div style="margin-bottom: 1.5rem;">
        <label style="display: block; font-weight: 600; margin-bottom: 0.5rem;">Recensione (opzionale)</label>
        <textarea id="review-descrizione" name="descrizione" rows="5" maxlength="2000" placeholder="Cosa ne pensi di questo libro?" style="width: 100%; padding: 0.625rem; border: 1px solid #d1d5db; border-radius: 8px; resize: vertical;"></textarea>
      </div>

      <div style="display: flex; gap: 1rem;">
        <button type="button" onclick="closeReviewModal()" style="flex: 1; padding: 0.75rem; background: #e5e7eb; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">__("Annulla")</button>
        <button type="submit" style="flex: 1; padding: 0.75rem; background: #3b82f6; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">Invia recensione</button>
      </div>
    </form>
  </div>
</div>

<script src="/assets/star-rating/dist/star-rating.js"></script>
<script>
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
      tooltip: 'Seleziona la valutazione'
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
    const response = await fetch('/api/user/recensioni', {
      method: 'POST',
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
        text: __('Sarà pubblicata dopo l\')approvazione di un amministratore.',
        confirmButtonText: __('OK')
      }).then(() => {
        closeReviewModal();
        location.reload();
      });
    } else {
      Swal.fire({
        icon: 'error',
        title: __('Errore'),
        text: result.message || 'Impossibile inviare la recensione'
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

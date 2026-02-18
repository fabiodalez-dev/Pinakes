<?php
use App\Support\HtmlHelper;
use App\Support\ConfigStore;

$isCatalogueMode = ConfigStore::isCatalogueMode();
$stats = $stats ?? ['libri' => 0, 'prestiti_in_corso' => 0, 'preferiti' => 0, 'storico_prestiti' => 0];
$ultimiArrivi = $ultimiArrivi ?? [];
$prestitiAttivi = $prestitiAttivi ?? [];

$userName = HtmlHelper::e($_SESSION['user']['nome'] ?? __('Utente'));
$catalogRoute = route_path('catalog');
$wishlistRoute = route_path('wishlist');
$reservationsRoute = route_path('reservations');
$profileRoute = route_path('profile');
?>

<style>
  .dashboard-hero {
    background: var(--primary-color);
    color: var(--white);
    padding: 4.5rem 0 3.5rem;
    margin-bottom: 3rem;
  }

  .dashboard-hero .hero-title {
    font-size: 2.75rem;
    font-weight: 800;
    letter-spacing: -0.03em;
  }

  .dashboard-hero .hero-subtitle {
    font-size: 1.1rem;
    opacity: 0.85;
  }

  .dashboard-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
    margin-bottom: 3rem;
  }

  .stat-card {
    background: var(--white);
    border-radius: 20px;
    padding: clamp(1.5rem, 3vw, 2rem);
    box-shadow: var(--card-shadow);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    text-align: center;
  }

  .stat-card:hover {
    transform: translateY(-6px);
    box-shadow: var(--card-shadow-hover);
  }

  .stat-icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: var(--light-bg);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin: 0 auto 1rem;
    color: var(--primary-color);
  }

  .stat-number {
    font-size: 2.5rem;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: var(--text-color);
    line-height: 1;
    margin-bottom: 0.5rem;
  }

  .stat-label {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.08em;
  }

  .section-card {
    background: var(--white);
    border-radius: 22px;
    box-shadow: var(--card-shadow);
    padding: clamp(1.75rem, 4vw, 2.5rem);
    margin-bottom: 2rem;
  }

  .section-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1.5rem;
  }

  .section-header h2 {
    font-size: 1.5rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: var(--text-color);
    margin: 0;
  }

  .section-header i {
    font-size: 1.25rem;
    color: var(--primary-color);
  }

  .book-card {
    background: var(--light-bg);
    border-radius: 16px;
    padding: 1.25rem;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: all 0.3s ease;
  }

  .book-card:hover {
    background: rgba(0, 0, 0, 0.04);
    transform: translateX(4px);
  }

  .book-card-icon {
    width: 50px;
    height: 70px;
    background: var(--white);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: var(--text-muted);
    font-size: 1.25rem;
    border: 1px solid var(--border-color);
    overflow: hidden;
  }

  .book-card-icon img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .book-card-content {
    flex: 1;
    min-width: 0;
  }

  .book-card-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-color);
    margin-bottom: 0.25rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .book-card-meta {
    font-size: 0.85rem;
    color: var(--text-muted);
  }

  .book-card-action {
    flex-shrink: 0;
  }

  .btn-view {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: var(--white);
    border: 1px solid var(--border-color);
    color: var(--primary-color);
    transition: all 0.2s ease;
    text-decoration: none;
  }

  .btn-view:hover {
    background: var(--primary-color);
    border-color: var(--primary-color);
    color: var(--white);
    transform: scale(1.05);
    text-decoration: none;
  }

  .availability-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0.8rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
  }

  .availability-badge.available {
    background: rgba(16, 185, 129, 0.1);
    color: #059669;
  }

  .availability-badge.unavailable {
    background: rgba(239, 68, 68, 0.1);
    color: #dc2626;
  }

  .availability-badge.expiring {
    background: rgba(245, 158, 11, 0.12);
    color: #b45309;
  }

  .availability-badge.expired {
    background: rgba(239, 68, 68, 0.1);
    color: #dc2626;
  }

  .empty-state {
    text-align: center;
    padding: 3rem 1.5rem;
  }

  .empty-state-icon {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: var(--light-bg);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    margin: 0 auto 1.25rem;
    color: var(--text-muted);
  }

  .empty-state h3 {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text-color);
    margin-bottom: 0.5rem;
  }

  .empty-state p {
    color: var(--text-muted);
    margin-bottom: 1.5rem;
  }

  .action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    justify-content: center;
  }

  .btn-outline {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.7rem 1.4rem;
    border-radius: 999px;
    border: 1px solid var(--border-color);
    background: var(--white);
    color: var(--primary-color);
    font-weight: 600;
    transition: all 0.3s ease;
    text-decoration: none;
  }

  .btn-outline:hover {
    border-color: var(--primary-color);
    box-shadow: var(--card-shadow-hover);
    text-decoration: none;
    color: var(--primary-color);
  }

  @media (max-width: 768px) {
    .dashboard-hero {
      padding: 3.5rem 0 3rem;
    }

    .dashboard-hero .hero-title {
      font-size: 2.1rem;
    }

    .dashboard-stats-grid {
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 1rem;
    }

    .stat-icon {
      width: 50px;
      height: 50px;
      font-size: 1.25rem;
    }

    .stat-number {
      font-size: 2rem;
    }
  }
</style>

<section class="dashboard-hero">
  <div class="container text-center">
    <h1 class="hero-title"><?= sprintf(__("Benvenuto, %s!"), $userName) ?></h1>
    <p class="hero-subtitle"><?= $isCatalogueMode ? __("Esplora il catalogo e scopri nuovi titoli.") : __("Gestisci i tuoi prestiti, esplora il catalogo e scopri nuovi titoli.") ?></p>
  </div>
</section>

<section class="container">
  <div class="dashboard-stats-grid">
    <div class="stat-card">
      <div class="stat-icon">
        <i class="fas fa-book"></i>
      </div>
      <div class="stat-number"><?= number_format($stats['libri'], 0, ',', '.') ?></div>
      <div class="stat-label"><?= __("Libri Totali") ?></div>
    </div>

    <?php if (!$isCatalogueMode): ?>
    <div class="stat-card">
      <div class="stat-icon">
        <i class="fas fa-bookmark"></i>
      </div>
      <div class="stat-number"><?= $stats['prestiti_in_corso'] ?></div>
      <div class="stat-label"><?= __("Prestiti Attivi") ?></div>
    </div>

    <div class="stat-card">
      <div class="stat-icon">
        <i class="fas fa-heart"></i>
      </div>
      <div class="stat-number"><?= $stats['preferiti'] ?></div>
      <div class="stat-label"><?= __("Nei Preferiti") ?></div>
    </div>

    <div class="stat-card">
      <div class="stat-icon">
        <i class="fas fa-history"></i>
      </div>
      <div class="stat-number"><?= $stats['storico_prestiti'] ?></div>
      <div class="stat-label"><?= __("Storico Prestiti") ?></div>
    </div>
    <?php endif; ?>
  </div>
</section>

<section class="container">
  <div class="row g-4">
    <!-- Ultimi Arrivi -->
    <div class="col-lg-6">
      <div class="section-card">
        <div class="section-header">
          <i class="fas fa-plus-circle"></i>
          <h2><?= __("Ultimi Arrivi") ?></h2>
        </div>

        <?php if (empty($ultimiArrivi)): ?>
          <div class="empty-state">
            <div class="empty-state-icon">
              <i class="fas fa-book-open"></i>
            </div>
            <h3><?= __("Nessun libro recente") ?></h3>
            <p><?= __("Non ci sono nuovi arrivi al momento.") ?></p>
          </div>
        <?php else: ?>
          <?php foreach ($ultimiArrivi as $libro): ?>
            <?php
              $bookUrl = book_url($libro);
              $available = ((int)($libro['copie_disponibili'] ?? 0)) > 0;
              $coverUrl = $libro['copertina_url'] ?? '';
            ?>
            <div class="book-card">
              <div class="book-card-icon">
                <?php if (!empty($coverUrl)): ?>
                  <img src="<?= HtmlHelper::e(url($coverUrl)) ?>" alt="<?= HtmlHelper::e($libro['titolo'] ?? '') ?>" loading="lazy">
                <?php else: ?>
                  <i class="fas fa-book"></i>
                <?php endif; ?>
              </div>
              <div class="book-card-content">
                <div class="book-card-title"><?= HtmlHelper::e($libro['titolo'] ?? '') ?></div>
                <div class="book-card-meta">
                  <?= HtmlHelper::e($libro['autore'] ?? __('Autore non specificato')) ?>
                </div>
                <div class="mt-2">
                  <span class="availability-badge <?= $available ? 'available' : 'unavailable' ?>">
                    <i class="fas <?= $available ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                    <?= $available ? __('Disponibile') : __('Non disponibile') ?>
                  </span>
                </div>
              </div>
              <div class="book-card-action">
                <a href="<?= HtmlHelper::e($bookUrl) ?>" class="btn-view" title="<?= __('Visualizza dettagli') ?>">
                  <i class="fas fa-eye"></i>
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Prestiti Attivi (hidden in catalogue mode) -->
    <?php if (!$isCatalogueMode): ?>
    <div class="col-lg-6">
      <div class="section-card">
        <div class="section-header">
          <i class="fas fa-handshake"></i>
          <h2><?= __("Prestiti Attivi") ?></h2>
        </div>

        <?php if (empty($prestitiAttivi)): ?>
          <div class="empty-state">
            <div class="empty-state-icon">
              <i class="fas fa-book-reader"></i>
            </div>
            <h3><?= __("Nessun prestito attivo") ?></h3>
            <p><?= __("Non hai prestiti in corso al momento.") ?></p>
            <div class="action-buttons">
              <a href="<?= $catalogRoute ?>" class="btn-outline">
                <i class="fas fa-search"></i>
                <?= __("Esplora il catalogo") ?>
              </a>
            </div>
          </div>
        <?php else: ?>
          <?php foreach ($prestitiAttivi as $prestito): ?>
            <?php
              $bookUrl = book_url([
                'libro_id' => $prestito['libro_id'] ?? null,
                'libro_titolo' => $prestito['titolo_libro'] ?? '',
                'autore' => ''
              ]);
              $scadenza = strtotime($prestito['data_scadenza'] ?? '');
              $oggi = time();
              if ($scadenza === false || $scadenza === 0) {
                  $giorni_rimanenti = 0;
                  $scaduto = false;
                  $in_scadenza = true;
              } else {
                  $giorni_rimanenti = (int)ceil(($scadenza - $oggi) / 86400);
                  $scaduto = $giorni_rimanenti < 0;
                  $in_scadenza = $giorni_rimanenti >= 0 && $giorni_rimanenti <= 3;
              }
              $coverUrl = $prestito['copertina_url'] ?? '';
            ?>
            <div class="book-card">
              <div class="book-card-icon">
                <?php if (!empty($coverUrl)): ?>
                  <img src="<?= HtmlHelper::e(url($coverUrl)) ?>" alt="<?= HtmlHelper::e($prestito['titolo_libro'] ?? '') ?>" loading="lazy">
                <?php else: ?>
                  <i class="fas fa-book"></i>
                <?php endif; ?>
              </div>
              <div class="book-card-content">
                <div class="book-card-title"><?= HtmlHelper::e($prestito['titolo_libro'] ?? '') ?></div>
                <div class="book-card-meta">
                  <?= __("Scadenza:") ?> <?= ($scadenza !== false && $scadenza > 0) ? format_date(date('Y-m-d', $scadenza), false, '/') : __('N/D') ?>
                  <?php if ($scadenza !== false && $scadenza > 0 && $giorni_rimanenti >= 0): ?>
                    (<?= sprintf(__("%d giorni"), $giorni_rimanenti) ?>)
                  <?php endif; ?>
                </div>
                <div class="mt-2">
                  <span class="availability-badge <?= $scaduto ? 'expired' : ($in_scadenza ? 'expiring' : 'available') ?>">
                    <i class="fas <?= $scaduto ? 'fa-exclamation-triangle' : ($in_scadenza ? 'fa-clock' : 'fa-check-circle') ?>"></i>
                    <?= $scaduto ? __('Scaduto') : ($in_scadenza ? __('In scadenza') : __('In corso')) ?>
                  </span>
                </div>
              </div>
              <div class="book-card-action">
                <a href="<?= HtmlHelper::e($bookUrl) ?>" class="btn-view" title="<?= __('Visualizza dettagli') ?>">
                  <i class="fas fa-info-circle"></i>
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>

<section class="container mb-5">
  <div class="section-card">
    <div class="section-header">
      <i class="fas fa-bolt"></i>
      <h2><?= __("Azioni Veloci") ?></h2>
    </div>
    <div class="action-buttons">
      <a href="<?= $catalogRoute ?>" class="btn-outline">
        <i class="fas fa-search"></i>
        <?= __("Cerca Libri") ?>
      </a>
      <?php if (!$isCatalogueMode): ?>
      <a href="<?= $wishlistRoute ?>" class="btn-outline">
        <i class="fas fa-heart"></i>
        <?= __("I Miei Preferiti") ?>
      </a>
      <a href="<?= $reservationsRoute ?>" class="btn-outline">
        <i class="fas fa-bookmark"></i>
        <?= __("Le Mie Prenotazioni") ?>
      </a>
      <?php endif; ?>
      <a href="<?= $profileRoute ?>" class="btn-outline">
        <i class="fas fa-user"></i>
        <?= __("Il Mio Profilo") ?>
      </a>
    </div>
  </div>
</section>

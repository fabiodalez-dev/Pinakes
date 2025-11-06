<?php
use App\Support\HtmlHelper;

$items = $items ?? [];
$csrfToken = HtmlHelper::e($_SESSION['csrf_token'] ?? '');
$totalItems = count($items);
$availableCount = 0;
foreach ($items as $entry) {
    if ((int)($entry['copie_disponibili'] ?? 0) > 0) {
        $availableCount++;
    }
}
$pendingCount = $totalItems - $availableCount;
?>
<meta name="csrf-token" content="<?= $csrfToken ?>">

<style>
  .wishlist-hero {
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    color: var(--white);
    padding: 4.5rem 0 3.5rem;
    margin-bottom: 3rem;
  }

  .wishlist-hero .hero-title {
    font-size: 2.75rem;
    font-weight: 800;
    letter-spacing: -0.03em;
  }

  .wishlist-hero .hero-subtitle {
    font-size: 1.1rem;
    opacity: 0.85;
  }

  .wishlist-info-card {
    background: var(--white);
    border-radius: 20px;
    padding: clamp(1.75rem, 4vw, 2.5rem);
    box-shadow: var(--card-shadow);
    margin-top: -90px;
    position: relative;
    z-index: 2;
  }

  .wishlist-stat-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-top: 1.5rem;
  }

  .wishlist-stat {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    background: var(--light-bg);
    color: var(--primary-color);
    padding: 0.6rem 1.1rem;
    border-radius: 999px;
    font-size: 0.95rem;
    font-weight: 600;
  }

  .wishlist-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
  }

  .wishlist-actions .btn-outline {
    border: 1px solid var(--border-color);
    border-radius: 999px;
    padding: 0.65rem 1.4rem;
    font-weight: 600;
    color: var(--primary-color);
    background: var(--white);
    transition: all 0.3s ease;
  }

  .wishlist-actions .btn-outline:hover {
    border-color: var(--primary-color);
    color: var(--primary-color);
    box-shadow: var(--card-shadow-hover);
    text-decoration: none;
  }

  .wishlist-filter-card {
    background: var(--white);
    border-radius: 18px;
    padding: clamp(1.5rem, 3vw, 2rem);
    box-shadow: var(--card-shadow);
    margin-bottom: 2.5rem;
  }

  .wishlist-filter-card label {
    font-size: 0.8rem;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: var(--text-muted);
  }

  .wishlist-filter-card input[type="search"] {
    background: var(--light-bg);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 0.75rem 1rem;
    font-size: 0.95rem;
    color: var(--text-color);
  }

  .wishlist-filter-card input[type="search"]:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0,0,0,0.06);
  }

  .wishlist-filter-card button {
    border: none;
    background: none;
    color: var(--text-muted);
    font-weight: 600;
    letter-spacing: 0.08em;
  }

  .wishlist-filter-card button:hover {
    color: var(--primary-color);
  }

  .wishlist-empty {
    background: var(--white);
    border-radius: 24px;
    padding: clamp(2.5rem, 6vw, 3.5rem);
    box-shadow: var(--card-shadow);
    text-align: center;
  }

  .wishlist-empty-icon {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    background: var(--light-bg);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    margin: 0 auto 1.5rem;
    color: var(--text-muted);
  }

  .wishlist-card {
    background: var(--white);
    border-radius: 22px;
    box-shadow: var(--card-shadow);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    overflow: hidden;
    height: 100%;
    display: flex;
    flex-direction: column;
  }

  .wishlist-card:hover {
    transform: translateY(-6px);
    box-shadow: var(--card-shadow-hover);
  }

  .wishlist-card-cover {
    background: var(--light-bg);
    padding: 1.5rem;
  }

  .wishlist-card-cover img {
    width: 100%;
    height: 240px;
    object-fit: contain;
    transition: transform 0.4s ease;
  }

  .wishlist-card:hover .wishlist-card-cover img {
    transform: scale(1.03);
  }

  .wishlist-card-body {
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    flex: 1;
    gap: 1rem;
  }

  .wishlist-status {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    border-radius: 999px;
    padding: 0.45rem 0.9rem;
    font-size: 0.8rem;
    font-weight: 700;
    letter-spacing: 0.06em;
  }

  .wishlist-status.available {
    background: rgba(16, 185, 129, 0.1);
    color: #059669;
  }

  .wishlist-status.pending {
    background: rgba(245, 158, 11, 0.12);
    color: #b45309;
  }

  .wishlist-card-title {
    font-size: 1.1rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: var(--text-color);
  }

  .wishlist-card-footer {
    margin-top: auto;
    display: flex;
    align-items: center;
    gap: 0.75rem;
  }

  .wishlist-card-footer a {
    flex: 1;
  }

  .wishlist-card .btn-outline-dark {
    border-radius: 12px;
    font-weight: 600;
    padding: 0.6rem 1rem;
  }

  .wishlist-card .btn-light {
    border-radius: 12px;
    border: 1px solid var(--border-color);
    padding: 0.6rem;
  }

  #wishlist-no-results {
    border-radius: 16px;
    padding: 0.9rem 1.25rem;
    background: rgba(59, 130, 246, 0.08);
    color: #1d4ed8;
    font-weight: 600;
    display: none;
  }

  @media (max-width: 768px) {
    .wishlist-hero {
      padding: 3.5rem 0 3rem;
    }

    .wishlist-hero .hero-title {
      font-size: 2.1rem;
    }

    .wishlist-info-card {
      margin-top: -60px;
    }
  }
</style>

<section class="wishlist-hero">
  <div class="container text-center">
    <h1 class="hero-title">I tuoi preferiti</h1>
    <p class="hero-subtitle">Una panoramica dei libri che hai salvato per non perderli di vista.</p>
  </div>
</section>

<section class="container">
  <div class="wishlist-info-card">
    <div class="row g-4 align-items-center">
      <div class="col-md-6">
        <h2 class="h4 fw-bold mb-2">Riepilogo wishlist</h2>
        <p class="text-muted mb-0">Gestisci i tuoi titoli preferiti, scopri quando tornano disponibili e accedi rapidamente ai dettagli del libro.</p>
        <div class="wishlist-stat-badges">
          <span class="wishlist-stat"><i class="fas fa-heart"></i> <span id="wishlist-total-count"><?= $totalItems; ?></span> preferiti</span>
          <span class="wishlist-stat"><i class="fas fa-bolt"></i> <span id="wishlist-available-count"><?= $availableCount; ?></span> disponibili ora</span>
          <span class="wishlist-stat"><i class="fas fa-clock"></i> <span id="wishlist-pending-count"><?= max($pendingCount, 0); ?></span> in attesa</span>
        </div>
      </div>
      <div class="col-md-6 text-md-end">
        <div class="wishlist-actions justify-content-md-end">
          <a href="/catalogo" class="btn-outline"><i class="fas fa-search me-2"></i>Esplora catalogo</a>
          <a href="/prenotazioni" class="btn-outline"><i class="fas fa-bookmark me-2"></i>Prenotazioni</a>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="container">
  <div class="wishlist-filter-card d-flex flex-column flex-md-row align-items-md-center justify-content-md-between gap-3">
    <div class="flex-grow-1">
      <label for="wishlist_search" class="mb-2">Ricerca rapida</label>
      <input id="wishlist_search" type="search" class="form-control" placeholder="<?= __('Cerca per titolo o stato (es. disponibile)') ?>">
    </div>
    <button id="clear-search" type="button" class="text-uppercase">Pulisci filtro</button>
  </div>
</section>

<?php if ($totalItems === 0): ?>
  <section class="container">
    <div class="wishlist-empty">
      <div class="wishlist-empty-icon">
        <i class="fas fa-heart-broken"></i>
      </div>
      <h2 class="h4 fw-bold mb-2">La tua wishlist è vuota</h2>
      <p class="text-muted mb-4">Aggiungi i libri che ti interessano dalla scheda di dettaglio per ricevere un promemoria quando tornano disponibili.</p>
      <div class="wishlist-actions justify-content-center">
        <a href="/catalogo" class="btn-outline"><i class="fas fa-compass me-2"></i>Cerca titoli</a>
        <a href="/dashboard" class="btn-outline"><i class="fas fa-arrow-left me-2"></i>Torna alla dashboard</a>
      </div>
    </div>
  </section>
<?php else: ?>
  <section class="container mb-5">
    <div id="wishlist-no-results" role="alert">
      <i class="fas fa-info-circle me-2"></i>Nessun titolo corrisponde al filtro corrente.
    </div>
    <div class="row g-4" id="wishlist-grid">
      <?php foreach ($items as $it):
        $cover = (string)($it['copertina_url'] ?? '');
        if ($cover !== '' && strncmp($cover, 'uploads/', 8) === 0) {
            $cover = '/' . $cover;
        }
        if ($cover === '') {
            $cover = '/uploads/copertine/placeholder.jpg';
        }
        $available = ((int)($it['copie_disponibili'] ?? 0)) > 0;
        $dataTitle = HtmlHelper::e(mb_strtolower((string)($it['titolo'] ?? ''), 'UTF-8'));
        $statusLabel = $available ? 'disponibile' : 'attesa';
      ?>
        <div class="col-xl-4 col-md-6">
          <article class="wishlist-card" data-libro-id="<?= (int)$it['id']; ?>" data-title="<?= $dataTitle; ?>" data-status="<?= $statusLabel; ?>">
            <div class="wishlist-card-cover">
              <img src="<?= HtmlHelper::e($cover); ?>" alt="Copertina" onerror="this.src='/uploads/copertine/placeholder.jpg'">
            </div>
            <div class="wishlist-card-body">
              <span class="wishlist-status <?= $available ? 'available' : 'pending'; ?>">
                <i class="fas <?= $available ? 'fa-check-circle' : 'fa-clock'; ?>"></i>
                <?= $available ? 'Disponibile ora' : 'In attesa'; ?>
              </span>
              <h3 class="wishlist-card-title mb-0"><?= HtmlHelper::e($it['titolo'] ?? ''); ?></h3>
              <p class="text-muted small mb-0">Copie disponibili: <?= (int)($it['copie_disponibili'] ?? 0); ?></p>
              <div class="wishlist-card-footer">
                <a href="/libro/<?= (int)$it['id']; ?>" class="btn btn-outline-dark"><i class="fas fa-book-open me-2"></i><?= __("Dettagli") ?></a>
                <button type="button" class="btn btn-light remove-fav-btn" title="Rimuovi dalla wishlist">
                  <i class="fas fa-trash"></i>
                </button>
              </div>
            </div>
          </article>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($totalItems > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const grid = document.getElementById('wishlist-grid');
  if (!grid) return;

  const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content') || '';
  const searchInput = document.getElementById('wishlist_search');
  const clearBtn = document.getElementById('clear-search');
  const noResults = document.getElementById('wishlist-no-results');
  const totalBadge = document.getElementById('wishlist-total-count');
  const availableBadge = document.getElementById('wishlist-available-count');
  const pendingBadge = document.getElementById('wishlist-pending-count');

  const getCards = () => Array.from(grid.querySelectorAll('[data-libro-id]'));

  function updateBadges() {
    const cards = getCards();
    const total = cards.length;
    const available = cards.filter(card => card.dataset.status === 'disponibile').length;
    const pending = Math.max(total - available, 0);

    if (totalBadge) totalBadge.textContent = total;
    if (availableBadge) availableBadge.textContent = available;
    if (pendingBadge) pendingBadge.textContent = pending;
  }

  function applyFilter() {
    const term = (searchInput?.value || '').trim().toLowerCase();
    let visibleCount = 0;

    getCards().forEach(card => {
      const title = card.dataset.title || '';
      const status = card.dataset.status || '';
      const match = !term || title.includes(term) || status.includes(term);
      card.parentElement.classList.toggle('d-none', !match);
      if (match) visibleCount++;
    });

    if (noResults) {
      noResults.style.display = visibleCount === 0 ? 'block' : 'none';
    }
  }

  grid.addEventListener('click', async event => {
    const target = event.target.closest('.remove-fav-btn');
    if (!target) return;

    const card = target.closest('[data-libro-id]');
    if (!card) return;

    const libroId = parseInt(card.dataset.libroId || '0', 10);
    if (!libroId) {
      return;
    }

    // Use SweetAlert for confirmation
    const result = await Swal.fire({
      title: __('Rimuovere dalla wishlist?'),
      text: __('Sei sicuro di voler rimuovere questo libro dalla tua wishlist?'),
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: __('Sì, rimuovi'),
      cancelButtonText: __('Annulla'),
      confirmButtonColor: '#111827',
      cancelButtonColor: '#6b7280'
    });

    if (!result.isConfirmed) {
      return;
    }

    try {
      const res = await fetch('/api/user/wishlist/toggle', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf_token: csrf, libro_id: String(libroId) })
      });

      if (!res.ok) {
        throw new Error('request_failed');
      }

      const data = await res.json();
      if (!data.favorite) {
        const col = card.parentElement;
        card.remove();
        if (col) {
          col.remove();
        }

        if (getCards().length === 0) {
          window.location.reload();
          return;
        }

        updateBadges();
        applyFilter();
      }
    } catch (error) {
      Swal.fire({
        title: __('Errore'),
        text: __('Si è verificato un errore nella rimozione. Riprova.'),
        icon: 'error',
        confirmButtonText: __('OK'),
        confirmButtonColor: '#111827'
      });
    }
  });

  if (searchInput) {
    searchInput.addEventListener('input', applyFilter);
  }

  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      if (searchInput) {
        searchInput.value = '';
      }
      applyFilter();
    });
  }

  updateBadges();
});
</script>
<?php endif; ?>

<?php

$items = $items ?? [];
$csrfToken = htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8');
$totalItems = count($items);
$availableCount = 0;
foreach ($items as $entry) {
    // Use actual copy availability check (considers reservations and physical copies)
    if (!empty($entry['has_actual_copy'])) {
        $availableCount++;
    }
}
$pendingCount = $totalItems - $availableCount;
$catalogRoute = route_path('catalog');
$reservationsRoute = route_path('reservations');
?>
<meta name="csrf-token" content="<?= $csrfToken ?>">


<?php // 2026 design: the account page head, a summary with the three counters, the quick search, then the same book cards as the catalogue. Styles in pinakes-2026.css (.pk-wishlist). ?>
<div class="loans-container pk-wishlist">
  <header class="account-page-heading">
    <div>
      <p class="account-page-heading__eyebrow"><?= __('Area personale') ?></p>
      <h1><?= __("I tuoi preferiti") ?></h1>
      <p class="account-page-heading__subtitle"><?= __("Una panoramica dei libri che hai salvato per non perderli di vista.") ?></p>
    </div>
    <nav class="account-page-heading__actions wishlist-actions" aria-label="<?= htmlspecialchars(__('Collegamenti area personale'), ENT_QUOTES, 'UTF-8') ?>">
      <a href="<?= htmlspecialchars($catalogRoute, ENT_QUOTES, 'UTF-8') ?>" class="account-page-link"><i class="fas fa-search" aria-hidden="true"></i><?= __("Esplora catalogo") ?></a>
      <a href="<?= htmlspecialchars($reservationsRoute, ENT_QUOTES, 'UTF-8') ?>" class="account-page-link"><i class="fas fa-bookmark" aria-hidden="true"></i><?= __("Prenotazioni") ?></a>
    </nav>
  </header>

  <section class="wishlist-info-card pk-wishlist__summary" aria-labelledby="wishlist-summary-title">
    <div class="pk-wishlist__intro">
      <h2 id="wishlist-summary-title"><?= __("Riepilogo wishlist") ?></h2>
      <p><?= __("Gestisci i tuoi titoli preferiti, scopri quando tornano disponibili e accedi rapidamente ai dettagli del libro.") ?></p>
    </div>
    <div class="wishlist-stat-badges pk-wishlist__stats">
      <span class="wishlist-stat"><i class="fas fa-heart" aria-hidden="true"></i><strong id="wishlist-total-count"><?= $totalItems; ?></strong> <?= __("preferiti") ?></span>
      <span class="wishlist-stat"><i class="fas fa-bolt" aria-hidden="true"></i><strong id="wishlist-available-count"><?= $availableCount; ?></strong> <?= __("disponibili ora") ?></span>
      <span class="wishlist-stat"><i class="fas fa-clock" aria-hidden="true"></i><strong id="wishlist-pending-count"><?= max($pendingCount, 0); ?></strong> <?= __("in attesa") ?></span>
    </div>
  </section>

  <div class="wishlist-filter-card pk-wishlist__tools">
    <div class="wishlist-filter-field">
      <label for="wishlist_search"><?= __("Ricerca rapida") ?></label>
      <input id="wishlist_search" type="search" class="form-input" placeholder="<?= htmlspecialchars(__('Cerca per titolo o stato (es. disponibile)'), ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <button id="clear-search" type="button"><i class="fas fa-times" aria-hidden="true"></i><?= __("Pulisci filtro") ?></button>
  </div>

<?php if ($totalItems === 0): ?>
  <div class="wishlist-empty pk-wishlist__empty">
    <div class="wishlist-empty-icon">
      <svg class="account-line-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M20.5 8.75c0 5-8.5 10-8.5 10s-8.5-5-8.5-10A4.25 4.25 0 0 1 12 7.7a4.25 4.25 0 0 1 8.5 1.05Z"></path>
      </svg>
    </div>
    <h2><?= __("La tua wishlist è vuota") ?></h2>
    <p><?= __("Aggiungi i libri che ti interessano dalla scheda di dettaglio per ricevere un promemoria quando tornano disponibili.") ?></p>
    <div class="wishlist-actions">
      <a href="<?= htmlspecialchars($catalogRoute, ENT_QUOTES, 'UTF-8') ?>" class="account-page-link"><i class="fas fa-compass" aria-hidden="true"></i><?= __("Cerca titoli") ?></a>
      <a href="<?= htmlspecialchars(route_path('user_dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="account-page-link"><i class="fas fa-arrow-left" aria-hidden="true"></i><?= __("Torna alla dashboard") ?></a>
    </div>
  </div>
<?php else: ?>
  <div id="wishlist-no-results" role="alert" hidden>
    <i class="fas fa-info-circle" aria-hidden="true"></i><?= __("Nessun titolo corrisponde al filtro corrente.") ?>
  </div>
  <div class="books-grid pk-grid pk-wishlist__grid" id="wishlist-grid">
    <?php foreach ($items as $it):
      $cover = (string)($it['copertina_url'] ?? '');
      if ($cover !== '' && strncmp($cover, 'uploads/', 8) === 0) {
          $cover = '/' . $cover;
      }
      // No cover, or the placeholder image: the card draws a blank book with the title, as in the catalogue.
      if (str_contains($cover, 'placeholder')) {
          $cover = '';
      }
      if ($cover !== '' && !preg_match('#^(https?:)?//#', $cover)) {
          $cover = url($cover);
      }
      // Use actual copy availability (considers reservations and physical copy state)
      $available = !empty($it['has_actual_copy']);
      $nextAvailable = $it['next_available'] ?? null;
      // Decoded once, then escaped where printed, as the catalogue card does.
      $wishlistTitle = html_entity_decode((string) ($it['titolo'] ?? ''), ENT_QUOTES, 'UTF-8');
      $dataTitle = htmlspecialchars(mb_strtolower($wishlistTitle, 'UTF-8'), ENT_QUOTES, 'UTF-8');
      $statusLabel = $available ? 'disponibile' : 'attesa';
      $wishlistBookUrl = book_url($it);
      $wishlistAuthor = trim(html_entity_decode((string)($it['autore'] ?? ''), ENT_QUOTES, 'UTF-8'));
    ?>
      <article class="book-card pk-card wishlist-card" data-libro-id="<?= (int)$it['id']; ?>" data-title="<?= $dataTitle; ?>" data-status="<?= $statusLabel; ?>">
        <div class="book-image-container pk-card__panel">
          <a href="<?= htmlspecialchars($wishlistBookUrl, ENT_QUOTES, 'UTF-8'); ?>" class="pk-card__link" tabindex="-1" aria-hidden="true"></a>
          <div class="pk-book">
            <div class="pk-book__pages"></div>
            <div class="pk-book__cover">
              <div class="pk-book__blank" aria-hidden="true">
                <div class="pk-book__blank-title"><?= htmlspecialchars($wishlistTitle, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="pk-book__blank-foot"><div class="pk-book__rule"></div><div class="pk-book__brand"><?= htmlspecialchars((string) \App\Support\ConfigStore::get('app.name', 'Pinakes'), ENT_QUOTES, 'UTF-8'); ?></div></div>
              </div>
              <?php if ($cover !== ''): ?>
              <img class="book-image pk-book__img" src="<?= htmlspecialchars((string) $cover, ENT_QUOTES, 'UTF-8'); ?>" alt="<?= htmlspecialchars(__("Copertina"), ENT_QUOTES, 'UTF-8') ?>" loading="lazy" decoding="async" onerror="this.onerror=null;this.classList.add('is-missing')">
              <?php endif; ?>
              <div class="pk-book__spine"></div>
              <div class="pk-book__gloss"></div>
              <div class="pk-book__edge"></div>
            </div>
          </div>
          <span class="book-status-badge pk-card__status wishlist-status <?= $available ? 'status-available available' : 'status-unavailable pending'; ?>"><span><?= $available ? __("Disponibile ora") : __("In attesa"); ?></span></span>
        </div>
        <div class="book-content pk-card__body">
          <h3 class="book-title pk-card__title wishlist-card-title"><a href="<?= htmlspecialchars($wishlistBookUrl, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($wishlistTitle, ENT_QUOTES, 'UTF-8'); ?></a></h3>
          <?php if ($wishlistAuthor !== ''): ?>
          <p class="book-author pk-card__author"><?= htmlspecialchars((string) $wishlistAuthor, ENT_QUOTES, 'UTF-8'); ?></p>
          <?php endif; ?>
          <?php if ($available): ?>
          <p class="book-meta pk-card__meta"><?= __("Copie disponibili:") ?> <?= (int)($it['copie_disponibili'] ?? 0); ?></p>
          <?php elseif ($nextAvailable): ?>
          <p class="book-meta pk-card__meta"><i class="fas fa-calendar-alt" aria-hidden="true"></i> <?= __("Disponibile dal:") ?> <?= format_date($nextAvailable, false, '/'); ?></p>
          <?php else: ?>
          <p class="book-meta pk-card__meta"><?= __("Nessuna copia attualmente disponibile") ?></p>
          <?php endif; ?>
          <div class="book-actions pk-card__actions wishlist-card-footer">
            <a href="<?= htmlspecialchars($wishlistBookUrl, ENT_QUOTES, 'UTF-8'); ?>" class="pk-card__details"><i class="fas fa-eye" aria-hidden="true"></i><?= __("Dettagli") ?></a>
            <button type="button" class="remove-fav-btn" title="<?= htmlspecialchars(__("Rimuovi dalla wishlist"), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(__("Rimuovi dalla wishlist"), ENT_QUOTES, 'UTF-8') ?>">
              <i class="fas fa-trash" aria-hidden="true"></i>
            </button>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>

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
      card.hidden = !match;
      if (match) visibleCount++;
    });

    if (noResults) {
      noResults.hidden = visibleCount !== 0;
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
      const res = await fetch(window.BASE_PATH + '/api/user/wishlist/toggle', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf_token: csrf, libro_id: String(libroId) })
      });

      if (!res.ok) {
        throw new Error('request_failed');
      }

      const data = await res.json();
      if (!data.favorite) {
        card.remove();

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

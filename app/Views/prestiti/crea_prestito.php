<?php use App\Support\Csrf; $csrf = Csrf::ensureToken(); ?>
<section class="py-8 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
  <!-- Breadcrumb -->
  <nav aria-label="breadcrumb" class="mb-2">
    <ol class="flex items-center space-x-2 text-sm">
      <li>
        <a href="/admin/dashboard" class="text-gray-500 hover:text-gray-700 transition-colors">
          <i class="fas fa-home mr-1"></i>Home
        </a>
      </li>
      <li>
        <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
      </li>
      <li>
        <a href="/admin/prestiti" class="text-gray-500 hover:text-gray-700 transition-colors">
          <i class="fas fa-handshake mr-1"></i><?= __("Prestiti") ?></a>
      </li>
      <li>
        <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
      </li>
      <li class="text-gray-900 font-medium"><?= __("Nuovo") ?></li>
    </ol>
  </nav>
  <div class="flex items-center justify-between">
    <h1 class="text-2xl font-bold"><?= __("Crea Nuovo Prestito") ?></h1>
  </div>

  <!-- Visualizzazione eventuale messaggio d'errore -->
  <?php if(isset($_GET['error'])): ?>
    <div class="mb-4 p-4 bg-red-100 text-red-800 rounded">
      <?php 
      switch($_GET['error']) {
        case 'libro_in_prestito':
          echo 'Il libro selezionato è già in prestito. Seleziona un altro libro.';
          break;
        case 'missing_fields':
          echo 'Errore: tutti i campi obbligatori devono essere compilati.';
          break;
        case 'invalid_dates':
          echo 'Errore: la data di scadenza deve essere successiva alla data di prestito.';
          break;
        default:
          echo 'Errore durante la creazione del prestito.';
      }
      ?>
    </div>
  <?php endif; ?>

  <?php if(isset($_GET['created']) && $_GET['created'] == '1'): ?>
    <div class="mb-4 p-4 bg-green-100 text-green-800 rounded"><?= __("Prestito creato con successo.") ?></div>
  <?php endif; ?>

  <form method="post" action="/admin/prestiti/crea" class="space-y-6 bg-white p-6 rounded-2xl border border-gray-200 shadow">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

    <!-- Ricerca Utente -->
    <div class="relative">
      <label for="utente_search" class="block text-gray-700 dark:text-gray-300 font-medium">Ricerca Utente *</label>
      <input type="text" id="utente_search" placeholder="<?= __('Cerca per nome, cognome, telefono, email o tessera') ?>" class="mt-1 block w-full rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
      <div id="utente_suggest" class="suggestions-box"></div>
      <input type="hidden" name="utente_id" id="utente_id" value="0" />
    </div>

    <!-- Ricerca Libro -->
    <div class="relative">
      <label for="libro_search" class="block text-gray-700 dark:text-gray-300 font-medium">Ricerca Libro *</label>
      <input type="text" id="libro_search" placeholder="<?= __('Cerca per titolo o sottotitolo') ?>" class="mt-1 block w-full rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
      <div id="libro_suggest" class="suggestions-box"></div>
      <input type="hidden" name="libro_id" id="libro_id" value="0" />
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <!-- Data Prestito -->
      <div>
        <label for="data_prestito" class="block text-gray-700 dark:text-gray-300 font-medium">Data Prestito *</label>
        <input type="date" name="data_prestito" id="data_prestito" value="<?php echo date('Y-m-d'); ?>" class="mt-1 block w-full rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
      </div>

      <!-- Data Scadenza -->
      <div>
        <label for="data_scadenza" class="block text-gray-700 dark:text-gray-300 font-medium">Data Scadenza *</label>
        <input type="date" name="data_scadenza" id="data_scadenza" value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>" class="mt-1 block w-full rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
      </div>
    </div>

    <!-- Note sul prestito -->
    <div>
      <label for="note" class="block text-gray-700 dark:text-gray-300 font-medium"><?= __("Note (opzionali)") ?></label>
      <textarea id="note" name="note" rows="4" placeholder="<?= __('Aggiungi eventuali note sul prestito') ?>" class="mt-1 block w-full rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white"></textarea>
    </div>

    <!-- Pulsanti -->
    <div class="flex items-center gap-4">
      <button type="submit" class="px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-900 transition-colors font-medium">
        <i class="fas fa-save mr-2"></i><?= __("Crea Prestito") ?></button>
      <a href="/admin/prestiti" class="px-4 py-2 bg-gray-100 text-gray-900 border border-gray-300 rounded-lg hover:bg-gray-200 transition-colors font-medium">
        <i class="fas fa-times mr-2"></i>Annulla
      </a>
    </div>
  </form>

  <style>
    .suggestions-box {
      position: absolute;
      background: white;
      border: 1px solid #e2e8f0;
      z-index: 10;
      width: 100%;
      max-height: 200px;
      overflow-y: auto;
      border-radius: 0.375rem;
      display: none;
    }
    .suggestion-item {
      padding: 0.5rem;
      cursor: pointer;
    }
    .suggestion-item:hover {
      background-color: #f1f5f9;
    }
  </style>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const setupAutocomplete = ({ inputId, suggestId, hiddenId, endpoint, minLength = 2 }) => {
        const inputEl = document.getElementById(inputId);
        const suggestEl = document.getElementById(suggestId);
        const hiddenEl = document.getElementById(hiddenId);
        let debounceTimer = null;

        if (!inputEl || !suggestEl || !hiddenEl) {
          console.warn('Impossibile configurare autocomplete: elementi mancanti', { inputId, suggestId, hiddenId });
          return;
        }

        const hideSuggestions = () => {
          suggestEl.style.display = 'none';
          suggestEl.innerHTML = '';
        };

        const renderSuggestions = (items) => {
          if (!items || items.length === 0) {
            suggestEl.innerHTML = "<div class='suggestion-item suggestion-empty'><?= __("Nessun risultato") ?></div>";
            suggestEl.style.display = 'block';
            return;
          }

          suggestEl.innerHTML = items.map((item) => (
            `<div class='suggestion-item' data-id='${item.id}'>${item.label}</div>`
          )).join('');

          suggestEl.style.display = 'block';
        };

        inputEl.addEventListener('input', () => {
          hiddenEl.value = '0';
          const query = inputEl.value.trim();

          clearTimeout(debounceTimer);

          if (query.length < minLength) {
            hideSuggestions();
            return;
          }

          debounceTimer = setTimeout(async () => {
            try {
              const response = await fetch(`${endpoint}?q=${encodeURIComponent(query)}`);
              if (!response.ok) throw new Error(`Richiesta fallita: ${response.status}`);
              const data = await response.json();
              renderSuggestions(Array.isArray(data) ? data : []);
            } catch (error) {
              console.error(`Errore durante la ricerca su ${endpoint}:`, error);
              hideSuggestions();
            }
          }, 250);
        });

        suggestEl.addEventListener('click', (event) => {
          const item = event.target.closest('.suggestion-item');
          if (!item) return;

          const selectedId = item.getAttribute('data-id');
          if (!selectedId) return;

          hiddenEl.value = selectedId;
          inputEl.value = item.textContent.trim();
          hideSuggestions();
        });

        document.addEventListener('click', (event) => {
          if (!inputEl.contains(event.target) && !suggestEl.contains(event.target)) {
            hideSuggestions();
          }
        });
      };

      setupAutocomplete({
        inputId: 'utente_search',
        suggestId: 'utente_suggest',
        hiddenId: 'utente_id',
        endpoint: '/api/search/utenti'
      });

      setupAutocomplete({
        inputId: 'libro_search',
        suggestId: 'libro_suggest',
        hiddenId: 'libro_id',
        endpoint: '/api/search/libri'
      });
    });
  </script>
</section>
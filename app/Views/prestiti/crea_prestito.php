<?php
use App\Support\Csrf;

$csrf = Csrf::ensureToken();
// Get locale from session (same as frontend/layout.php)
$currentLocale = $_SESSION['locale'] ?? 'it_IT';
$isItalian = str_starts_with($currentLocale, 'it');

// Check if user_id was passed via query param (from user detail page)
$presetUserId = isset($_GET['utente_id']) ? (int)$_GET['utente_id'] : 0;
$presetUserName = '';
$presetUserLocked = false;

// If utente_id is provided, fetch user info to pre-fill the form
if ($presetUserId > 0) {
    global $container;
    $db = $container->get('db');
    $stmt = $db->prepare("SELECT id, nome, cognome, codice_tessera FROM utenti WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $presetUserId);
    $stmt->execute();
    $presetUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($presetUser) {
        $presetUserName = $presetUser['nome'] . ' ' . $presetUser['cognome'] . ' (' . $presetUser['codice_tessera'] . ')';
        $presetUserLocked = true;
    } else {
        $presetUserId = 0;
    }
}
?>
<section class="py-8 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
  <!-- Breadcrumb -->
  <nav aria-label="breadcrumb" class="mb-2">
    <ol class="flex items-center space-x-2 text-sm">
      <li>
        <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
          <i class="fas fa-home mr-1"></i><?= __("Home") ?>
        </a>
      </li>
      <li>
        <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
      </li>
      <li>
        <a href="<?= htmlspecialchars(url('/admin/prestiti'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
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
        case 'book_not_available':
          echo __('Il libro selezionato è già in prestito. Seleziona un altro libro.');
          break;
        case 'missing_fields':
          echo __('Errore: tutti i campi obbligatori devono essere compilati.');
          break;
        case 'invalid_dates':
          echo __('Errore: la data di scadenza deve essere successiva alla data di prestito.');
          break;
        case 'no_copies_available':
          echo __('Tutte le copie di questo libro hanno già un prestito attivo o prenotato. Attendi che una copia venga restituita.');
          break;
        case 'duplicate_reservation':
          echo __('Questo utente ha già un prestito o una prenotazione attiva per questo libro.');
          break;
        default:
          echo __('Errore durante la creazione del prestito.');
      }
      ?>
    </div>
  <?php endif; ?>

  <?php if(isset($_GET['created']) && $_GET['created'] == '1'): ?>
    <div class="mb-4 p-4 bg-green-100 text-green-800 rounded"><?= __("Prestito creato con successo.") ?></div>
  <?php endif; ?>

  <form method="post" action="<?= htmlspecialchars(url('/admin/prestiti/crea'), ENT_QUOTES, 'UTF-8') ?>" class="space-y-6 bg-white p-6 rounded-2xl border border-gray-200 shadow">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">

    <!-- Ricerca Utente -->
    <div class="relative">
      <label for="utente_search" class="block text-gray-700 dark:text-gray-300 font-medium"><?= __("Utente") ?> *</label>
      <input type="text" id="utente_search" placeholder="<?= __('Cerca per nome, cognome, telefono, email o tessera') ?>" class="mt-1 block w-full rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white <?= $presetUserLocked ? 'bg-gray-100 cursor-not-allowed' : '' ?>" autocomplete="off" value="<?= htmlspecialchars($presetUserName, ENT_QUOTES, 'UTF-8') ?>" <?= $presetUserLocked ? 'readonly' : '' ?>>
      <div id="utente_suggest" class="suggestions-box"></div>
      <input type="hidden" name="utente_id" id="utente_id" value="<?= $presetUserId ?>" required />
      <?php if ($presetUserLocked): ?>
      <p class="mt-1 text-xs text-gray-500"><i class="fas fa-lock mr-1"></i><?= __("Utente preselezionato") ?></p>
      <?php endif; ?>
    </div>

    <!-- Ricerca Libro -->
    <div class="relative">
      <label for="libro_search" class="block text-gray-700 dark:text-gray-300 font-medium"><?= __("Libro") ?> *</label>
      <input type="text" id="libro_search" placeholder="<?= __('Cerca per titolo, ISBN o EAN') ?>" class="mt-1 block w-full rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white" autocomplete="off">
      <div id="libro_suggest" class="suggestions-box"></div>
      <input type="hidden" name="libro_id" id="libro_id" value="0" required />
      <!-- Availability indicator -->
      <div id="libro_availability" class="mt-2 hidden">
        <div class="flex items-center gap-2 p-3 rounded-lg border" id="availability_card">
          <div id="availability_icon"></div>
          <div class="flex-1">
            <div id="availability_text" class="text-sm font-medium"></div>
            <div id="availability_detail" class="text-xs text-gray-500"></div>
          </div>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <!-- Data Prestito -->
      <div>
        <label for="data_prestito" class="block text-gray-700 dark:text-gray-300 font-medium"><?= __("Data Prestito") ?> *</label>
        <input type="text" name="data_prestito" id="data_prestito" value="<?php echo date('Y-m-d'); ?>" class="mt-1 block w-full rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white" data-no-flatpickr required>
        <p id="data_prestito_hint" class="mt-1 text-xs text-gray-500 hidden"></p>
      </div>

      <!-- Data Scadenza -->
      <div>
        <label for="data_scadenza" class="block text-gray-700 dark:text-gray-300 font-medium"><?= __("Data Scadenza") ?> *</label>
        <input type="text" name="data_scadenza" id="data_scadenza" value="<?php echo date('Y-m-d', strtotime('+1 month')); ?>" class="mt-1 block w-full rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900 dark:text-white" data-no-flatpickr required>
      </div>
    </div>

    <!-- Calendar Legend -->
    <div id="calendar_legend" class="hidden flex flex-wrap gap-4 text-xs">
      <div class="flex items-center gap-1">
        <span class="inline-block w-4 h-4 rounded" style="background-color: #dcfce7;"></span>
        <span><?= __("Disponibile") ?></span>
      </div>
      <div class="flex items-center gap-1">
        <span class="inline-block w-4 h-4 rounded" style="background-color: #fef3c7;"></span>
        <span><?= __("Occupato (prestito attivo)") ?></span>
      </div>
      <div class="flex items-center gap-1">
        <span class="inline-block w-4 h-4 rounded" style="background-color: #fee2e2;"></span>
        <span><?= __("Occupato (in ritardo)") ?></span>
      </div>
    </div>

    <!-- Consegna immediata (solo per prestiti con data_prestito <= oggi) -->
    <div id="consegna_immediata_container" class="flex items-start gap-3 p-4 bg-gray-50 rounded-lg border border-gray-200">
      <input type="checkbox" name="consegna_immediata" id="consegna_immediata" value="1" checked
             class="mt-0.5 h-4 w-4 rounded border-gray-300 text-gray-800 focus:ring-gray-500">
      <div class="flex-1">
        <label for="consegna_immediata" class="block text-sm font-medium text-gray-900 cursor-pointer">
          <?= __("Consegna immediata") ?>
        </label>
        <p class="text-xs text-gray-500 mt-0.5">
          <?= __("Il libro viene consegnato subito all'utente. Se deselezionato, il prestito rimarrà in stato 'Da ritirare' fino alla conferma del ritiro.") ?>
        </p>
      </div>
    </div>

    <!-- Scarica ricevuta PDF (visibile solo con consegna immediata) -->
    <div id="scarica_pdf_container" class="flex items-start gap-3 p-4 bg-gray-50 rounded-lg border border-gray-200">
      <input type="checkbox" name="scarica_pdf" id="scarica_pdf" value="1" checked
             class="mt-0.5 h-4 w-4 rounded border-gray-300 text-gray-800 focus:ring-gray-500">
      <div class="flex-1">
        <label for="scarica_pdf" class="block text-sm font-medium text-gray-900 cursor-pointer">
          <?= __("Scarica ricevuta PDF") ?>
        </label>
        <p class="text-xs text-gray-500 mt-0.5">
          <?= __("Scarica automaticamente la ricevuta PDF dopo la creazione del prestito.") ?>
        </p>
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
      <a href="<?= htmlspecialchars(url('/admin/prestiti'), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 bg-gray-100 text-gray-900 border border-gray-300 rounded-lg hover:bg-gray-200 transition-colors font-medium">
        <i class="fas fa-times mr-2"></i><?= __("Annulla") ?>
      </a>
    </div>
  </form>

  <style>
    .suggestions-box {
      position: absolute;
      background: white;
      border: 1px solid #e2e8f0;
      z-index: 50;
      width: 100%;
      max-height: 250px;
      overflow-y: auto;
      border-radius: 0.5rem;
      display: none;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }
    .suggestion-item {
      padding: 0.75rem 1rem;
      cursor: pointer;
      border-bottom: 1px solid #f1f5f9;
    }
    .suggestion-item:last-child {
      border-bottom: none;
    }
    .suggestion-item:hover {
      background-color: #f8fafc;
    }
    .suggestion-item.selected {
      background-color: #f1f5f9;
    }
    /* Flatpickr calendar date coloring */
    .flatpickr-day.date-available {
      background-color: #dcfce7 !important;
      border-color: #86efac !important;
    }
    .flatpickr-day.date-available:hover {
      background-color: #bbf7d0 !important;
    }
    .flatpickr-day.date-occupied {
      background-color: #fef3c7 !important;
      border-color: #fcd34d !important;
      color: #92400e !important;
    }
    .flatpickr-day.date-occupied:hover {
      background-color: #fde68a !important;
    }
    .flatpickr-day.date-overdue {
      background-color: #fee2e2 !important;
      border-color: #fca5a5 !important;
      color: #991b1b !important;
    }
    .flatpickr-day.date-overdue:hover {
      background-color: #fecaca !important;
    }
    .flatpickr-day.date-occupied.selected,
    .flatpickr-day.date-overdue.selected {
      color: white !important;
    }
  </style>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Translations
      const i18n = {
        noResults: <?= json_encode(__("Nessun risultato"), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
        available: <?= json_encode(__("Disponibile"), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
        notAvailable: <?= json_encode(__("Non disponibile ora"), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
        reservable: <?= json_encode(__("Prenotabile per date future"), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
        copies: <?= json_encode(__("copie"), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
        firstAvailable: <?= json_encode(__("Prima data disponibile: %s"), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
        availableOnDate: <?= json_encode(__("Disponibile nella data selezionata"), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
        notAvailableOnDate: <?= json_encode(__("Non disponibile nella data selezionata"), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
        selectBook: <?= json_encode(__("Seleziona un libro per vedere la disponibilità"), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>
      };

      // Book availability data
      let bookAvailability = {
        occupiedRanges: [],
        firstAvailable: null,
        isAvailableNow: true
      };

      // Date picker elements
      const dataPrestitoEl = document.getElementById('data_prestito');
      const dataScadenzaEl = document.getElementById('data_scadenza');
      const dataPrestitoHint = document.getElementById('data_prestito_hint');
      const calendarLegend = document.getElementById('calendar_legend');

      // Flatpickr instances
      let fpPrestito = null;
      let fpScadenza = null;

      // Availability data by date (same structure as frontend)
      let availabilityByDate = {};

      // Format date as YYYY-MM-DD
      function formatDate(date) {
        const d = new Date(date);
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
      }

      // Flatpickr onDayCreate callback to color dates (same logic as frontend)
      function colorCalendarDates(dObj, dStr, fp, dayElem) {
        if (!dayElem || !dayElem.dateObj) return;
        if (dayElem.classList.contains('prevMonthDay') || dayElem.classList.contains('nextMonthDay')) return;

        const isoDate = fp.formatDate(dayElem.dateObj, 'Y-m-d');
        const today = formatDate(new Date());
        const info = availabilityByDate[isoDate];

        // Don't color past dates
        if (isoDate < today) {
          return;
        }

        if (info) {
          // Apply inline styles to ensure colors show (same as frontend)
          if (info.state === 'borrowed') {
            dayElem.style.backgroundColor = '#fef2f2';
            dayElem.style.borderColor = '#fecaca';
            dayElem.style.color = '#b91c1c';
          } else if (info.state === 'reserved') {
            dayElem.style.backgroundColor = '#fffbeb';
            dayElem.style.borderColor = '#fef3c7';
            dayElem.style.color = '#b45309';
          } else if (info.state === 'free') {
            dayElem.style.backgroundColor = '#f0fdf4';
            dayElem.style.borderColor = '#bbf7d0';
            dayElem.style.color = '#166534';
          }
        } else if (Object.keys(availabilityByDate).length > 0) {
          // Default to free if we have data but this date isn't in it
          dayElem.style.backgroundColor = '#f0fdf4';
          dayElem.style.borderColor = '#bbf7d0';
          dayElem.style.color = '#166534';
        }
      }

      // Check if a date is occupied (for hint text)
      function isDateOccupied(dateStr) {
        const info = availabilityByDate[dateStr];
        if (info && info.state !== 'free') {
          return info.state === 'borrowed' ? 'in_ritardo' : 'in_corso';
        }
        return false;
      }

      // Show/hide "Consegna immediata" checkbox based on whether date is today or future
      const consegnaContainer = document.getElementById('consegna_immediata_container');
      const consegnaCheckbox = document.getElementById('consegna_immediata');
      const pdfContainer = document.getElementById('scarica_pdf_container');
      const pdfCheckbox = document.getElementById('scarica_pdf');

      function updatePdfVisibility() {
        if (!pdfContainer || !pdfCheckbox) return;
        if (consegnaCheckbox && consegnaCheckbox.checked && !consegnaContainer.classList.contains('hidden')) {
          pdfContainer.classList.remove('hidden');
        } else {
          pdfContainer.classList.add('hidden');
          pdfCheckbox.checked = false;
        }
      }

      if (consegnaCheckbox) {
        consegnaCheckbox.addEventListener('change', updatePdfVisibility);
      }

      function updateConsegnaImmediataVisibility(dateStr) {
        if (!consegnaContainer || !consegnaCheckbox) return;

        const today = formatDate(new Date());
        const isImmediate = dateStr <= today;

        if (isImmediate) {
          consegnaContainer.classList.remove('hidden');
        } else {
          consegnaContainer.classList.add('hidden');
          consegnaCheckbox.checked = false;
        }
        updatePdfVisibility();
      }

      // Initialize visibility on page load
      updateConsegnaImmediataVisibility(dataPrestitoEl.value);

      // Get locale for flatpickr
      const appLocale = document.documentElement.lang?.startsWith('it') ? 'it' : 'en';
      const isItalian = appLocale === 'it';
      const localeObj = window.flatpickrLocales ? window.flatpickrLocales[appLocale] : null;

      // Initialize flatpickr for data_prestito
      const fpConfig = {
        dateFormat: 'Y-m-d',
        altInput: true,
        altFormat: isItalian ? 'd/m/Y' : 'm/d/Y',
        allowInput: true,
        minDate: 'today',
        locale: localeObj || undefined,
        onDayCreate: colorCalendarDates,
        onChange: function(selectedDates, dateStr) {
          if (dateStr) {
            // Auto-update end date
            const startDate = new Date(dateStr);
            const endDate = new Date(startDate);
            endDate.setMonth(endDate.getMonth() + 1);
            if (fpScadenza) {
              fpScadenza.set('minDate', dateStr);
              fpScadenza.setDate(endDate);
            }

            // Update hint about availability
            updateDateHint(dateStr);

            // Show/hide "Consegna immediata" checkbox based on date
            updateConsegnaImmediataVisibility(dateStr);
          }
        }
      };

      fpPrestito = flatpickr(dataPrestitoEl, fpConfig);

      // Initialize flatpickr for data_scadenza (with same date coloring)
      fpScadenza = flatpickr(dataScadenzaEl, {
        dateFormat: 'Y-m-d',
        altInput: true,
        altFormat: isItalian ? 'd/m/Y' : 'm/d/Y',
        allowInput: true,
        minDate: dataPrestitoEl.value || 'today',
        locale: localeObj || undefined,
        onDayCreate: colorCalendarDates
      });

      // Update hint text based on selected date
      function updateDateHint(dateStr) {
        if (!dataPrestitoHint) return;

        const occupiedStatus = isDateOccupied(dateStr);

        if (occupiedStatus) {
          dataPrestitoHint.textContent = i18n.notAvailableOnDate;
          dataPrestitoHint.className = 'mt-1 text-xs text-amber-600';
          dataPrestitoHint.classList.remove('hidden');
        } else {
          dataPrestitoHint.textContent = i18n.availableOnDate;
          dataPrestitoHint.className = 'mt-1 text-xs text-green-600';
          dataPrestitoHint.classList.remove('hidden');
        }
      }

      // Fetch and apply book availability (same API as frontend)
      function fetchBookAvailability(bookId) {
        if (!bookId || bookId === '0') {
          availabilityByDate = {};
          bookAvailability = { occupiedRanges: [], firstAvailable: null, isAvailableNow: true };
          if (calendarLegend) calendarLegend.classList.add('hidden');
          if (dataPrestitoHint) dataPrestitoHint.classList.add('hidden');
          if (fpPrestito) fpPrestito.redraw();
          if (fpScadenza) fpScadenza.redraw();
          return;
        }

        // Use same API as frontend
        const safeBookId = parseInt(bookId, 10);
        fetch(window.BASE_PATH + '/api/libro/' + safeBookId + '/availability')
          .then(function(response) {
            if (!response.ok) throw new Error('Failed to fetch availability');
            return response.json();
          })
          .then(function(data) {
            if (data.success && data.availability) {
              // Build availabilityByDate map (same structure as frontend)
              availabilityByDate = {};
              if (Array.isArray(data.availability.days)) {
                data.availability.days.forEach(function(day) {
                  if (day && day.date) {
                    availabilityByDate[day.date] = day;
                  }
                });
              }

              // Also keep old structure for backward compatibility
              bookAvailability = {
                occupiedRanges: [],
                firstAvailable: data.availability.earliest_available || null,
                isAvailableNow: !data.availability.unavailable_dates || data.availability.unavailable_dates.length === 0
              };
            }

            // Show legend
            if (calendarLegend) calendarLegend.classList.remove('hidden');

            // Redraw calendars with new colors
            if (fpPrestito) fpPrestito.redraw();
            if (fpScadenza) fpScadenza.redraw();

            // Update hint if date is already selected
            if (dataPrestitoEl.value) {
              updateDateHint(dataPrestitoEl.value);
            }

            // If not available now, show first available date hint
            if (!bookAvailability.isAvailableNow && bookAvailability.firstAvailable && dataPrestitoHint) {
              const firstAvailFormatted = isItalian
                ? bookAvailability.firstAvailable.split('-').reverse().join('/')
                : bookAvailability.firstAvailable;
              dataPrestitoHint.textContent = i18n.firstAvailable.replace('%s', firstAvailFormatted);
              dataPrestitoHint.className = 'mt-1 text-xs text-amber-600';
              dataPrestitoHint.classList.remove('hidden');
            }
          })
          .catch(function(error) {
            console.error('Error fetching availability:', error);
          });
      }

      // Simple autocomplete setup
      function setupAutocomplete(inputId, suggestId, hiddenId, endpoint, isBook) {
        const inputEl = document.getElementById(inputId);
        const suggestEl = document.getElementById(suggestId);
        const hiddenEl = document.getElementById(hiddenId);
        let debounceTimer = null;

        if (!inputEl || !suggestEl || !hiddenEl) return;

        function hideSuggestions() {
          suggestEl.style.display = 'none';
          suggestEl.innerHTML = '';
        }

        function showSuggestions(items) {
          if (!items || items.length === 0) {
            suggestEl.innerHTML = '<div class="suggestion-item" style="color: #9ca3af; cursor: default;">' + i18n.noResults + '</div>';
            suggestEl.style.display = 'block';
            return;
          }

          suggestEl.innerHTML = items.map(function(item) {
            const itemId = parseInt(item.id, 10);
            if (isNaN(itemId)) return '';
            if (isBook) {
              const copieDisponibili = item.copie_disponibili || 0;
              const copieTotali = item.copie_totali || 0;
              const isAvailable = copieDisponibili > 0;
              // Green for available, Amber for reservable (not available now but can be scheduled)
              const iconColor = isAvailable ? '#22c55e' : '#f59e0b';
              const statusIcon = isAvailable ? 'fa-check-circle' : 'fa-clock';
              const countColor = isAvailable ? '#16a34a' : '#92400e';

              return '<div class="suggestion-item" data-id="' + itemId + '" data-copies="' + copieDisponibili + '" data-total="' + copieTotali + '">' +
                '<div style="display: flex; align-items: center; gap: 0.5rem;">' +
                '<i class="fas ' + statusIcon + '" style="color: ' + iconColor + ';"></i>' +
                '<span style="flex: 1;">' + escapeHtml(item.label) + '</span>' +
                '<span style="font-size: 0.75rem; font-weight: 600; color: ' + countColor + ';">' + copieDisponibili + '/' + copieTotali + '</span>' +
                '</div>' +
                '</div>';
            }
            return '<div class="suggestion-item" data-id="' + itemId + '">' + escapeHtml(item.label) + '</div>';
          }).join('');

          suggestEl.style.display = 'block';
        }

        function escapeHtml(str) {
          if (!str) return '';
          return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        inputEl.addEventListener('input', function() {
          hiddenEl.value = '0';
          hideAvailability();

          // Reset calendar availability when book changes
          if (isBook) {
            fetchBookAvailability(null);
          }

          const query = this.value.trim();
          clearTimeout(debounceTimer);

          if (query.length < 2) {
            hideSuggestions();
            return;
          }

          debounceTimer = setTimeout(function() {
            fetch(endpoint + '?q=' + encodeURIComponent(query))
              .then(function(response) {
                if (!response.ok) throw new Error('Request failed');
                return response.json();
              })
              .then(function(data) {
                showSuggestions(Array.isArray(data) ? data : []);
              })
              .catch(function(error) {
                console.error('Search error:', error);
                hideSuggestions();
              });
          }, 300);
        });

        suggestEl.addEventListener('click', function(event) {
          const item = event.target.closest('.suggestion-item');
          if (!item) return;

          const selectedId = item.getAttribute('data-id');
          if (!selectedId) return;

          hiddenEl.value = selectedId;

          // Get text content
          const textSpan = item.querySelector('span');
          inputEl.value = textSpan ? textSpan.textContent.trim() : item.textContent.trim();

          // Show availability for books and fetch calendar dates
          if (isBook) {
            const copies = parseInt(item.getAttribute('data-copies') || '0', 10);
            const total = parseInt(item.getAttribute('data-total') || '0', 10);
            showAvailability(copies, total);
            // Fetch availability dates for calendar coloring
            fetchBookAvailability(selectedId);
          }

          hideSuggestions();
        });

        document.addEventListener('click', function(event) {
          if (!inputEl.contains(event.target) && !suggestEl.contains(event.target)) {
            hideSuggestions();
          }
        });

        // Keyboard navigation
        inputEl.addEventListener('keydown', function(event) {
          if (event.key === 'Escape') {
            hideSuggestions();
          }
        });
      }

      // Availability display
      const availabilityContainer = document.getElementById('libro_availability');
      const availabilityCard = document.getElementById('availability_card');
      const availabilityIcon = document.getElementById('availability_icon');
      const availabilityText = document.getElementById('availability_text');
      const availabilityDetail = document.getElementById('availability_detail');

      function hideAvailability() {
        if (availabilityContainer) {
          availabilityContainer.classList.add('hidden');
        }
      }

      function showAvailability(copies, total) {
        if (!availabilityContainer) return;

        availabilityContainer.classList.remove('hidden');

        if (copies > 0) {
          // Green - available now
          availabilityCard.style.backgroundColor = '#f0fdf4';
          availabilityCard.style.borderColor = '#86efac';
          availabilityIcon.innerHTML = '<i class="fas fa-check-circle" style="color: #22c55e; font-size: 1.25rem;"></i>';
          availabilityText.textContent = i18n.available;
          availabilityText.style.color = '#166534';
          availabilityDetail.textContent = copies + '/' + total + ' ' + i18n.copies;
        } else {
          // Amber/Yellow - not available now but can be reserved for future
          availabilityCard.style.backgroundColor = '#fffbeb';
          availabilityCard.style.borderColor = '#fcd34d';
          availabilityIcon.innerHTML = '<i class="fas fa-clock" style="color: #f59e0b; font-size: 1.25rem;"></i>';
          availabilityText.textContent = i18n.notAvailable;
          availabilityText.style.color = '#92400e';
          availabilityDetail.textContent = i18n.reservable;
        }
      }

      // Initialize autocompletes
      setupAutocomplete('utente_search', 'utente_suggest', 'utente_id', window.BASE_PATH + '/api/search/utenti', false);
      setupAutocomplete('libro_search', 'libro_suggest', 'libro_id', window.BASE_PATH + '/api/search/libri', true);
    });
  </script>
</section>

<?php
/** @var string $activeTab */
/** @var string $csrfToken */
/** @var array $loansSettings */
?>
<section data-settings-panel="loans" class="settings-panel <?php echo $activeTab === 'loans' ? 'block' : 'hidden'; ?>">
  <form action="<?= htmlspecialchars(url('/admin/settings/loans'), ENT_QUOTES, 'UTF-8') ?>" method="post" class="space-y-6">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

    <!-- Automatic approval -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-check-double text-gray-500"></i>
          <?= __("Approvazione automatica") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Approva automaticamente le richieste di prestito e passa subito allo stato in attesa di ritiro.") ?></p>
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
          <div class="flex items-start gap-2">
            <i class="fas fa-info-circle text-gray-600 mt-0.5"></i>
            <div class="text-xs text-gray-700">
              <?= __("Le email per la nuova richiesta agli amministratori e per l'approvazione all'utente continueranno a essere inviate.") ?>
            </div>
          </div>
        </div>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4 max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
        <div class="flex items-center justify-between gap-4">
          <div>
            <span id="auto_approve_requests_label" class="text-sm font-semibold text-gray-900"><?= __("Auto-approva le richieste") ?></span>
            <p id="auto_approve_requests_desc" class="text-xs text-gray-600 mt-1"><?= __("Disattiva la fase di approvazione manuale per i nuovi prestiti richiesti dagli utenti.") ?></p>
          </div>
          <label class="relative inline-flex items-center cursor-pointer shrink-0">
            <input type="checkbox"
                   id="auto_approve_requests"
                   name="auto_approve_requests"
                   value="1"
                   aria-labelledby="auto_approve_requests_label"
                   aria-describedby="auto_approve_requests_desc"
                   <?php echo !empty($loansSettings['auto_approve_requests']) ? 'checked' : ''; ?>
                   class="toggle-checkbox sr-only">
            <div class="toggle-bg w-11 h-6 bg-gray-200 rounded-full transition-colors"></div>
            <div class="toggle-dot absolute top-[2px] left-[2px] bg-white border border-gray-300 rounded-full h-5 w-5 transition-transform"></div>
          </label>
        </div>
      </div>
    </div>

    <!-- Loan duration -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-calendar-alt text-gray-500"></i>
          <?= __("Durata predefinita prestito") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Numero di giorni di default assegnati a ogni prestito quando la data di scadenza non viene specificata manualmente.") ?></p>
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
          <div class="flex items-start gap-2">
            <i class="fas fa-info-circle text-gray-600 mt-0.5"></i>
            <div class="text-xs text-gray-700">
              <strong><?= __("Nota:") ?></strong> <?= __("Questo valore viene usato nelle richieste di prestito utente, nella creazione admin e nell'approvazione delle prenotazioni.") ?>
            </div>
          </div>
        </div>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4 max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
        <div>
          <label for="loan_duration_days" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __("Durata predefinita prestito (giorni)") ?>
          </label>
          <div class="flex items-center gap-4">
            <input type="number"
                   id="loan_duration_days"
                   name="loan_duration_days"
                   min="1"
                   max="365"
                   value="<?php echo (int) ($loansSettings['loan_duration_days'] ?? 30); ?>"
                   class="block w-32 rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 text-center font-semibold text-lg">
            <span class="text-sm text-gray-600"><?= __("giorni") ?></span>
          </div>
          <p class="text-xs text-gray-500 mt-3">
            <i class="fas fa-info-circle mr-1"></i>
            <?= __("Valore compreso tra 1 e 365 giorni. Predefinito: 30 giorni") ?>
          </p>
        </div>
      </div>
    </div>

    <!-- Closures calendar (own page: /admin/calendar) -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6" id="loans-closures-calendar">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-calendar-times text-gray-500"></i>
          <?= __("Calendario chiusure") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Giorni in cui la biblioteca è chiusa: le scadenze che vi cadono passano al primo giorno di apertura e i giorni per il ritiro contano solo i giorni di apertura.") ?></p>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4 max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
        <a href="<?= htmlspecialchars(url('/admin/calendar'), ENT_QUOTES, 'UTF-8') ?>" id="open-closures-calendar" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-black transition-colors">
          <i class="fas fa-calendar-alt"></i>
          <?= __("Gestisci il calendario chiusure") ?>
        </a>
      </div>
    </div>

    <!-- App timezone (loan clock for due dates and automatisms) -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-globe text-gray-500"></i>
          <?= __("Fuso orario") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Fuso orario usato per calcolare scadenze, attivazioni e automatismi dei prestiti. Deve corrispondere al fuso orario della tua biblioteca.") ?></p>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4 max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
        <div>
          <label for="app_timezone" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __("Fuso orario") ?>
          </label>
          <?php $currentTimezone = (string) ($loansSettings['app_timezone'] ?? \App\Support\ConfigStore::get('app.timezone', 'Europe/Rome')); ?>
          <select id="app_timezone"
                  name="app_timezone"
                  class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
            <?php foreach (\DateTimeZone::listIdentifiers() as $tzId): ?>
              <option value="<?= htmlspecialchars($tzId, ENT_QUOTES, 'UTF-8') ?>" <?= $currentTimezone === $tzId ? 'selected' : '' ?>><?= htmlspecialchars($tzId, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
          <p class="text-xs text-gray-500 mt-3">
            <i class="fas fa-info-circle mr-1"></i>
            <?= __("Fuso orario") ?>: <?= htmlspecialchars($currentTimezone, ENT_QUOTES, 'UTF-8') ?>
          </p>
        </div>
      </div>
    </div>

    <!-- Max requestable loan duration (reservation-window cap) -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-hourglass-half text-gray-500"></i>
          <?= __("Durata massima richiedibile") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Periodo massimo che un utente può richiedere per un prestito o una prenotazione. Le richieste che superano questo limite vengono rifiutate.") ?></p>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4 max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
        <div>
          <label for="max_loan_duration_days" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __("Durata massima prestito (giorni)") ?>
          </label>
          <div class="flex items-center gap-4">
            <input type="number"
                   id="max_loan_duration_days"
                   name="max_loan_duration_days"
                   min="1"
                   max="3650"
                   value="<?php echo (int) ($loansSettings['max_loan_duration_days'] ?? 90); ?>"
                   class="block w-32 rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 text-center font-semibold text-lg">
            <span class="text-sm text-gray-600"><?= __("giorni") ?></span>
          </div>
          <p class="text-xs text-gray-500 mt-3">
            <i class="fas fa-info-circle mr-1"></i>
            <?= __("Valore compreso tra 1 e 3650 giorni. Predefinito: 90 giorni") ?>
          </p>
        </div>
      </div>
    </div>

    <!-- Pickup expiry days -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-store text-gray-500"></i>
          <?= __("Giorni per il ritiro") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Numero di giorni che l'utente ha a disposizione per ritirare un prestito approvato prima che scada automaticamente.") ?></p>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4 max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
        <div>
          <label for="pickup_expiry_days" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __("Giorni per il ritiro del prestito approvato") ?>
          </label>
          <div class="flex items-center gap-4">
            <input type="number"
                   id="pickup_expiry_days"
                   name="pickup_expiry_days"
                   min="1"
                   max="30"
                   value="<?php echo (int) ($loansSettings['pickup_expiry_days'] ?? 3); ?>"
                   class="block w-32 rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 text-center font-semibold text-lg">
            <span class="text-sm text-gray-600"><?= __("giorni") ?></span>
          </div>
          <p class="text-xs text-gray-500 mt-3">
            <i class="fas fa-info-circle mr-1"></i>
            <?= __("Valore compreso tra 1 e 30 giorni. Predefinito: 3 giorni") ?>
          </p>
        </div>
        <div>
          <label for="pickup_reminder_days" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __("Promemoria di ritiro: giorni prima della scadenza") ?>
          </label>
          <div class="flex items-center gap-4">
            <input type="number"
                   id="pickup_reminder_days"
                   name="pickup_reminder_days"
                   min="0"
                   max="30"
                   value="<?php echo (int) ($loansSettings['pickup_reminder_days'] ?? 2); ?>"
                   class="block w-32 rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 text-center font-semibold text-lg">
            <span class="text-sm text-gray-600"><?= __("giorni") ?></span>
          </div>
          <p class="text-xs text-gray-500 mt-3">
            <i class="fas fa-info-circle mr-1"></i>
            <?= __("Se il libro non è ancora stato ritirato, l'utente riceve un promemoria questo numero di giorni prima della scadenza del ritiro (template «Promemoria ritiro»). 0 disattiva il promemoria. Predefinito: 2 giorni") ?>
          </p>
        </div>
      </div>
    </div>

    <!-- Card expiry warning (circulation policy) -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-id-card text-gray-500"></i>
          <?= __("Avviso scadenza tessera") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Il lettore riceve un'email quando la sua tessera sta per scadere, così può rinnovarla prima di non poter più chiedere prestiti.") ?></p>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4 max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
        <div>
          <label for="card_expiry_warning_days" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __("Avviso scadenza tessera: giorni prima della scadenza") ?>
          </label>
          <div class="flex items-center gap-4">
            <input type="number"
                   id="card_expiry_warning_days"
                   name="card_expiry_warning_days"
                   min="0"
                   max="365"
                   value="<?php echo (int) ($loansSettings['card_expiry_warning_days'] ?? 0); ?>"
                   class="block w-32 rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 text-center font-semibold text-lg">
            <span class="text-sm text-gray-600"><?= __("giorni") ?></span>
          </div>
          <p class="text-xs text-gray-500 mt-3">
            <i class="fas fa-info-circle mr-1"></i>
            <?= __("Inviato una sola volta per scadenza dal processo pianificato delle notifiche (template «Tessera in scadenza»). 0 disattiva l'avviso. Predefinito: 0") ?>
          </p>
        </div>
      </div>
    </div>

    <!-- Max renewals -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-redo text-gray-500"></i>
          <?= __("Rinnovi massimi") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Numero massimo di volte che un utente può rinnovare lo stesso prestito.") ?></p>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4 max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
        <div>
          <label for="max_renewals" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __("Numero massimo di rinnovi per prestito") ?>
          </label>
          <div class="flex items-center gap-4">
            <input type="number"
                   id="max_renewals"
                   name="max_renewals"
                   min="0"
                   max="99"
                   value="<?php echo (int) ($loansSettings['max_renewals'] ?? 3); ?>"
                   class="block w-32 rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 text-center font-semibold text-lg">
            <span class="text-sm text-gray-600"><?= __("rinnovi") ?></span>
          </div>
          <p class="text-xs text-gray-500 mt-3">
            <i class="fas fa-info-circle mr-1"></i>
            <?= __("Inserisci 0 per disabilitare i rinnovi. Predefinito: 3") ?>
          </p>
        </div>
      </div>
    </div>

    <!-- Max active loans per user -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-user-lock text-gray-500"></i>
          <?= __("Limite prestiti attivi per utente") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Numero massimo di prestiti contemporaneamente attivi per ogni utente. Impostare 0 per nessun limite.") ?></p>
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
          <div class="flex items-start gap-2">
            <i class="fas fa-info-circle text-gray-600 mt-0.5"></i>
            <div class="text-xs text-gray-700">
              <strong><?= __("Prestiti conteggiati:") ?></strong> <?= __("prenotato, da_ritirare, in_corso, in_ritardo.") ?>
            </div>
          </div>
        </div>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4 max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
        <div>
          <label for="max_active_loans_per_user" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __("Numero massimo di prestiti attivi per utente") ?>
          </label>
          <div class="flex items-center gap-4">
            <input type="number"
                   id="max_active_loans_per_user"
                   name="max_active_loans_per_user"
                   min="0"
                   max="999"
                   value="<?php echo (int) ($loansSettings['max_active_loans_per_user'] ?? 0); ?>"
                   class="block w-32 rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 text-center font-semibold text-lg">
            <span class="text-sm text-gray-600"><?= __("prestiti (0 = nessun limite)") ?></span>
          </div>
          <p class="text-xs text-gray-500 mt-3">
            <i class="fas fa-info-circle mr-1"></i>
            <?= __("Predefinito: 0 (nessun limite)") ?>
          </p>
        </div>
      </div>
    </div>

    <!-- Multiple physical copies of the same title -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-layer-group text-gray-500"></i>
          <?= __("Più copie dello stesso titolo") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Consenti allo stesso utente di avere più prestiti attivi dello stesso libro, purché ogni prestito sia associato a una copia fisica distinta.") ?></p>
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
          <div class="flex items-start gap-2">
            <i class="fas fa-info-circle text-gray-600 mt-0.5"></i>
            <div class="text-xs text-gray-700">
              <?= __("Richieste in attesa e prenotazioni restano uniche per utente e libro. Ogni copia conta nel limite dei prestiti attivi.") ?>
            </div>
          </div>
        </div>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4 max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
        <div class="flex items-center justify-between gap-4">
          <div>
            <span id="allow_multiple_loans_same_book_label" class="text-sm font-semibold text-gray-900"><?= __("Consenti più prestiti dello stesso titolo solo su copie fisiche distinte") ?></span>
            <p id="allow_multiple_loans_same_book_desc" class="text-xs text-gray-600 mt-1"><?= __("Ogni prestito deve essere associato a una copia fisica distinta; richieste in attesa e prenotazioni restano singole per utente e libro.") ?></p>
          </div>
          <label class="relative inline-flex items-center cursor-pointer shrink-0">
            <input type="checkbox"
                   id="allow_multiple_loans_same_book"
                   name="allow_multiple_loans_same_book"
                   value="1"
                   aria-labelledby="allow_multiple_loans_same_book_label"
                   aria-describedby="allow_multiple_loans_same_book_desc"
                   <?php echo !empty($loansSettings['allow_multiple_loans_same_book']) ? 'checked' : ''; ?>
                   class="toggle-checkbox sr-only">
            <div class="toggle-bg w-11 h-6 bg-gray-200 rounded-full transition-colors"></div>
            <div class="toggle-dot absolute top-[2px] left-[2px] bg-white border border-gray-300 rounded-full h-5 w-5 transition-transform"></div>
          </label>
        </div>
      </div>
    </div>

    <!-- #360: automatic recalls (solleciti) for overdue loans -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-bullhorn text-gray-500"></i>
          <?= __("Solleciti automatici") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Invia automaticamente email di sollecito agli utenti con prestiti scaduti, a intervalli regolari dopo la prima notifica di ritardo.") ?></p>
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
          <div class="flex items-start gap-2">
            <i class="fas fa-info-circle text-gray-600 mt-0.5"></i>
            <div class="text-xs text-gray-700">
              <?= __("I solleciti vengono inviati dal job delle notifiche automatiche (cron) o all'accesso di un amministratore. Il sollecito manuale dalla pagina del prestito resta sempre disponibile.") ?>
            </div>
          </div>
        </div>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4 max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
        <div class="flex items-center justify-between gap-4">
          <div>
            <span id="recall_auto_enabled_label" class="text-sm font-semibold text-gray-900"><?= __("Abilita solleciti automatici") ?></span>
            <p id="recall_auto_enabled_desc" class="text-xs text-gray-600 mt-1"><?= __("Invia solleciti ricorrenti per i prestiti scaduti non ancora restituiti.") ?></p>
          </div>
          <label class="relative inline-flex items-center cursor-pointer shrink-0">
            <input type="checkbox"
                   id="recall_auto_enabled"
                   name="recall_auto_enabled"
                   value="1"
                   aria-labelledby="recall_auto_enabled_label"
                   aria-describedby="recall_auto_enabled_desc"
                   <?php echo !empty($loansSettings['recall_auto_enabled']) ? 'checked' : ''; ?>
                   class="toggle-checkbox sr-only">
            <div class="toggle-bg w-11 h-6 bg-gray-200 rounded-full transition-colors"></div>
            <div class="toggle-dot absolute top-[2px] left-[2px] bg-white border border-gray-300 rounded-full h-5 w-5 transition-transform"></div>
          </label>
        </div>
        <div>
          <label for="recall_interval_days" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __("Intervallo tra i solleciti (giorni)") ?>
          </label>
          <div class="flex items-center gap-4">
            <input type="number"
                   id="recall_interval_days"
                   name="recall_interval_days"
                   min="1"
                   max="365"
                   value="<?php echo (int) ($loansSettings['recall_interval_days'] ?? 7); ?>"
                   class="block w-32 rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 text-center font-semibold text-lg">
            <span class="text-sm text-gray-600"><?= __("giorni") ?></span>
          </div>
          <p class="text-xs text-gray-500 mt-3">
            <i class="fas fa-info-circle mr-1"></i>
            <?= __("Il primo sollecito parte quando il ritardo raggiunge l'intervallo; i successivi a ogni multiplo. Predefinito: 7 giorni") ?>
          </p>
        </div>
        <div>
          <label for="recall_max_count" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __("Numero massimo di solleciti automatici") ?>
          </label>
          <div class="flex items-center gap-4">
            <input type="number"
                   id="recall_max_count"
                   name="recall_max_count"
                   min="1"
                   max="50"
                   value="<?php echo (int) ($loansSettings['recall_max_count'] ?? 3); ?>"
                   class="block w-32 rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 text-center font-semibold text-lg">
            <span class="text-sm text-gray-600"><?= __("solleciti") ?></span>
          </div>
          <p class="text-xs text-gray-500 mt-3">
            <i class="fas fa-info-circle mr-1"></i>
            <?= __("I solleciti manuali aggiornano il conteggio e possono ridurre il numero di invii automatici. Predefinito: 3") ?>
          </p>
        </div>
      </div>
    </div>

    <!-- Plan §7: late fines, patron ledger, debt block -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-coins text-gray-500"></i>
          <?= __("Multe e blocco per debito") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Addebita automaticamente una multa per ogni giorno di ritardo quando un prestito viene restituito dopo la scadenza. Con multa giornaliera a 0 le multe sono disattivate.") ?></p>
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
          <div class="flex items-start gap-2">
            <i class="fas fa-info-circle text-gray-600 mt-0.5"></i>
            <div class="text-xs text-gray-700">
              <?= __("Entro i giorni di tolleranza non si paga nulla; oltre, la multa conta ogni giorno di ritardo dal primo. Saldo, pagamenti e condoni si gestiscono dalla scheda del lettore, sezione Movimenti.") ?>
            </div>
          </div>
        </div>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4 max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
        <div>
          <label for="daily_fine" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __("Multa giornaliera") ?>
          </label>
          <div class="flex items-center gap-4">
            <input type="text"
                   inputmode="decimal"
                   id="daily_fine"
                   name="daily_fine"
                   pattern="[0-9]{1,8}([.,][0-9]{1,2})?"
                   placeholder="0"
                   value="<?php echo htmlspecialchars((string) ($loansSettings['daily_fine'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                   class="block w-32 rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 text-center font-semibold text-lg">
            <span class="text-sm text-gray-600"><?= htmlspecialchars((string) ($loansSettings['currency'] ?? 'EUR'), ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <p class="text-xs text-gray-500 mt-3">
            <i class="fas fa-info-circle mr-1"></i>
            <?= __("Importo addebitato per ogni giorno di ritardo. 0 = multe disattivate (predefinito).") ?>
          </p>
        </div>
        <div>
          <label for="fine_grace_days" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __("Giorni di tolleranza") ?>
          </label>
          <div class="flex items-center gap-4">
            <input type="number"
                   id="fine_grace_days"
                   name="fine_grace_days"
                   min="0"
                   max="365"
                   value="<?php echo (int) ($loansSettings['fine_grace_days'] ?? 0); ?>"
                   class="block w-32 rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 text-center font-semibold text-lg">
            <span class="text-sm text-gray-600"><?= __("giorni") ?></span>
          </div>
          <p class="text-xs text-gray-500 mt-3">
            <i class="fas fa-info-circle mr-1"></i>
            <?= __("Un ritardo fino a questo numero di giorni non viene multato. Predefinito: 0") ?>
          </p>
        </div>
        <div>
          <label for="fine_max_per_loan" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __("Multa massima per prestito") ?>
          </label>
          <div class="flex items-center gap-4">
            <input type="text"
                   inputmode="decimal"
                   id="fine_max_per_loan"
                   name="fine_max_per_loan"
                   pattern="[0-9]{1,8}([.,][0-9]{1,2})?"
                   placeholder="0"
                   value="<?php echo htmlspecialchars((string) ($loansSettings['fine_max_per_loan'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                   class="block w-32 rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 text-center font-semibold text-lg">
            <span class="text-sm text-gray-600"><?= htmlspecialchars((string) ($loansSettings['currency'] ?? 'EUR'), ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <p class="text-xs text-gray-500 mt-3">
            <i class="fas fa-info-circle mr-1"></i>
            <?= __("Tetto della multa di un singolo prestito. Vuoto o 0 = nessun tetto.") ?>
          </p>
        </div>
        <div>
          <label for="debt_block_threshold" class="block text-sm font-medium text-gray-700 mb-2">
            <?= __("Soglia di blocco per debito") ?>
          </label>
          <div class="flex items-center gap-4">
            <input type="text"
                   inputmode="decimal"
                   id="debt_block_threshold"
                   name="debt_block_threshold"
                   pattern="[0-9]{1,8}([.,][0-9]{1,2})?"
                   placeholder="0"
                   value="<?php echo htmlspecialchars((string) ($loansSettings['debt_block_threshold'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                   class="block w-32 rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 text-center font-semibold text-lg">
            <span class="text-sm text-gray-600"><?= htmlspecialchars((string) ($loansSettings['currency'] ?? 'EUR'), ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <p class="text-xs text-gray-500 mt-3">
            <i class="fas fa-info-circle mr-1"></i>
            <?= __("Con un saldo a debito superiore a questa soglia il lettore non può ricevere nuovi prestiti, rinnovi o prenotazioni. Vuoto o 0 = nessun blocco.") ?>
          </p>
        </div>
        <div class="flex items-center justify-between gap-4">
          <div>
            <span id="fine_charged_email_label" class="text-sm font-semibold text-gray-900"><?= __("Avvisa il lettore via email quando viene addebitata una multa") ?></span>
            <p id="fine_charged_email_desc" class="text-xs text-gray-600 mt-1"><?= __("Usa il modello email «Multa per ritardo addebitata». Disattivato per impostazione predefinita.") ?></p>
          </div>
          <label class="relative inline-flex items-center cursor-pointer shrink-0">
            <input type="checkbox"
                   id="fine_charged_email"
                   name="fine_charged_email"
                   value="1"
                   aria-labelledby="fine_charged_email_label"
                   aria-describedby="fine_charged_email_desc"
                   <?php echo !empty($loansSettings['fine_charged_email']) ? 'checked' : ''; ?>
                   class="toggle-checkbox sr-only">
            <div class="toggle-bg w-11 h-6 bg-gray-200 rounded-full transition-colors"></div>
            <div class="toggle-dot absolute top-[2px] left-[2px] bg-white border border-gray-300 rounded-full h-5 w-5 transition-transform"></div>
          </label>
        </div>
      </div>
    </div>

    <div class="flex justify-end">
      <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-black transition-colors">
        <i class="fas fa-save"></i>
        <?= __("Salva impostazioni prestiti") ?>
      </button>
    </div>
  </form>
</section>

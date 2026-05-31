<?php
/** @var string $activeTab */
/** @var string $csrfToken */
/** @var array $loansSettings */
use App\Support\HtmlHelper;
?>
<section data-settings-panel="loans" class="settings-panel <?php echo $activeTab === 'loans' ? 'block' : 'hidden'; ?>">
  <form action="<?= htmlspecialchars(url('/admin/settings/loans'), ENT_QUOTES, 'UTF-8') ?>" method="post" class="space-y-6">
    <input type="hidden" name="csrf_token" value="<?php echo HtmlHelper::e($csrfToken); ?>">

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
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4">
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

    <!-- Pickup expiry days -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-store text-gray-500"></i>
          <?= __("Giorni per il ritiro") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Numero di giorni che l'utente ha a disposizione per ritirare un prestito approvato prima che scada automaticamente.") ?></p>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4">
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
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4">
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
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4">
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

    <div class="flex justify-end">
      <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-black transition-colors">
        <i class="fas fa-save"></i>
        <?= __("Salva impostazioni prestiti") ?>
      </button>
    </div>
  </form>
</section>

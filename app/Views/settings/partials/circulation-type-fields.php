<?php
/**
 * Fields of the patron type form (create in the circulation tab, edit page).
 *
 * @var array<string, mixed>|null $typeForm current row, null for a new type
 * @var array<string, mixed> $cGlobals global layer (placeholders = inherited values)
 */
$tf = $typeForm ?? [];
$tfPrefix = $typeForm === null ? 'new-type' : 'type-' . (int) ($tf['id'] ?? 0);
$tfInt = static fn (string $k): string => isset($tf[$k]) && $tf[$k] !== '' ? (string) (int) $tf[$k] : '';
$tfGlobal = [
    'max_prestiti' => (int) ($cGlobals['max_active_loans'] ?? 0) > 0 ? (string) (int) $cGlobals['max_active_loans'] : '∞',
    'durata_prestito_giorni' => (string) (int) ($cGlobals['duration_days'] ?? 30),
    'max_durata_richiesta_giorni' => (string) (int) ($cGlobals['max_request_days'] ?? 90),
    'max_rinnovi' => (string) (int) ($cGlobals['max_renewals'] ?? 3),
    'giorni_rinnovo' => (string) (int) ($cGlobals['duration_days'] ?? 30),
    'max_prenotazioni' => '∞',
    'durata_tessera_giorni' => '—',
    'giorni_tolleranza' => (string) (int) ($cGlobals['grace_days'] ?? 0),
];
$tfMoney = static fn (string $k): string => isset($tf[$k]) && $tf[$k] !== '' ? (string) $tf[$k] : '';
$tfGlobalMoney = static function (mixed $v): string {
    $cents = \App\Services\FineParameters::toCents((string) ($v ?? ''));
    return $cents !== null && $cents > 0 ? \App\Services\FineParameters::format($cents) : '—';
};
$tfGlobalMoneyByField = [
    'multa_giornaliera' => $tfGlobalMoney($cGlobals['daily_fine'] ?? null),
    'multa_massima_prestito' => $tfGlobalMoney($cGlobals['max_fine_per_loan'] ?? null),
    'soglia_blocco_debito' => $tfGlobalMoney($cGlobals['debt_block_threshold'] ?? null),
];
?>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
  <div>
    <label for="<?= $tfPrefix ?>-nome" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Nome") ?> *</label>
    <input type="text" id="<?= $tfPrefix ?>-nome" name="nome" required maxlength="100" value="<?= htmlspecialchars((string) ($tf['nome'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
  </div>
  <div>
    <label for="<?= $tfPrefix ?>-codice" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Codice") ?></label>
    <input type="text" id="<?= $tfPrefix ?>-codice" name="codice" maxlength="40" pattern="[a-z0-9][a-z0-9_\-]{0,39}" value="<?= htmlspecialchars((string) ($tf['codice'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(__('generato dal nome'), ENT_QUOTES, 'UTF-8') ?>" class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
  </div>
  <div class="md:col-span-2">
    <label for="<?= $tfPrefix ?>-descrizione" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Descrizione") ?></label>
    <textarea id="<?= $tfPrefix ?>-descrizione" name="descrizione" rows="2" maxlength="2000" class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4"><?= htmlspecialchars((string) ($tf['descrizione'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
  </div>
  <?php foreach (['max_prestiti', 'durata_prestito_giorni', 'max_durata_richiesta_giorni', 'max_rinnovi', 'giorni_rinnovo', 'max_prenotazioni', 'durata_tessera_giorni', 'giorni_tolleranza'] as $tfField): ?>
  <div>
    <label for="<?= $tfPrefix . '-' . $tfField ?>" class="block text-sm font-medium text-gray-700 mb-2"><?= htmlspecialchars(\App\Controllers\CirculationSettingsController::fieldLabel($tfField), ENT_QUOTES, 'UTF-8') ?></label>
    <input type="number" min="0" id="<?= $tfPrefix . '-' . $tfField ?>" name="<?= $tfField ?>" value="<?= htmlspecialchars($tfInt($tfField), ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(sprintf(__('Eredita: %s'), $tfGlobal[$tfField]), ENT_QUOTES, 'UTF-8') ?>" class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 placeholder:text-gray-400">
    <p class="text-xs text-gray-500 mt-1"><?= in_array($tfField, ['max_prestiti', 'max_prenotazioni'], true) ? __('Vuoto = eredita; 0 = nessun limite.') : ($tfField === 'max_rinnovi' ? __('Vuoto = eredita; 0 = rinnovi vietati.') : ($tfField === 'giorni_tolleranza' ? __('Vuoto = eredita; 0 = nessuna tolleranza.') : __('Vuoto = eredita; inserisci un numero positivo.'))) ?></p>
  </div>
  <?php endforeach; ?>
  <?php foreach (\App\Controllers\CirculationSettingsController::TYPE_MONEY_FIELDS as $tfField): ?>
  <div>
    <label for="<?= $tfPrefix . '-' . $tfField ?>" class="block text-sm font-medium text-gray-700 mb-2"><?= htmlspecialchars(\App\Controllers\CirculationSettingsController::fieldLabel($tfField), ENT_QUOTES, 'UTF-8') ?></label>
    <input type="text" inputmode="decimal" pattern="[0-9]{1,8}([.,][0-9]{1,2})?" id="<?= $tfPrefix . '-' . $tfField ?>" name="<?= $tfField ?>" value="<?= htmlspecialchars($tfMoney($tfField), ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(sprintf(__('Eredita: %s'), $tfGlobalMoneyByField[$tfField]), ENT_QUOTES, 'UTF-8') ?>" class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4 placeholder:text-gray-400">
    <p class="text-xs text-gray-500 mt-1"><?= $tfField === 'multa_massima_prestito' ? __('Vuoto = eredita; 0 = nessun tetto.') : __('Vuoto = eredita; 0 = disattiva per questa tipologia.') ?></p>
  </div>
  <?php endforeach; ?>
</div>
<p class="text-xs text-gray-500">
  <i class="fas fa-info-circle mr-1"></i>
  <?= __("Prestiti attivi massimi 0 = nessun limite. La durata della tessera, se indicata, calcola la scadenza alla creazione, all'approvazione e al rinnovo della tessera.") ?>
</p>
<p class="text-xs text-gray-500">
  <i class="fas fa-info-circle mr-1"></i>
  <?= __("Multa giornaliera 0 = nessuna multa per questa tipologia; soglia di blocco 0 = nessun blocco per debito. Un campo vuoto eredita l'impostazione della scheda Prestiti.") ?>
</p>
<div class="space-y-2">
  <label class="flex items-center gap-2 text-sm text-gray-700">
    <input type="hidden" name="prenotazioni_abilitate" value="0">
    <input type="checkbox" name="prenotazioni_abilitate" value="1" id="<?= $tfPrefix ?>-prenotazioni" <?= ($typeForm === null || (int) ($tf['prenotazioni_abilitate'] ?? 1) === 1) ? 'checked' : '' ?> class="rounded border-gray-300">
    <?= __("Prenotazioni consentite") ?>
  </label>
  <label class="flex items-center gap-2 text-sm text-gray-700">
    <input type="hidden" name="attiva" value="0">
    <input type="checkbox" name="attiva" value="1" id="<?= $tfPrefix ?>-attiva" <?= ($typeForm === null || (int) ($tf['attiva'] ?? 1) === 1) ? 'checked' : '' ?> class="rounded border-gray-300">
    <?= __("Assegnabile ai lettori") ?>
  </label>
  <label class="flex items-center gap-2 text-sm text-gray-700">
    <input type="checkbox" name="predefinita" value="1" id="<?= $tfPrefix ?>-predefinita" <?= (int) ($tf['predefinita'] ?? 0) === 1 ? 'checked disabled' : '' ?> class="rounded border-gray-300">
    <?= __("Tipologia predefinita per i nuovi lettori") ?>
  </label>
</div>

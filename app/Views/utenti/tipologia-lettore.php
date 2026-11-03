<?php
/**
 * "Tipologia lettore" select of the user forms (circulation rules).
 *
 * @var array<int, array<string, mixed>> $patronTypes assignable types
 * @var int|null $patronTypeId current type (null = default)
 * @var int|null $defaultPatronTypeId
 */

$ptDefaultName = $defaultPatronTypeId !== null && isset($patronTypes[$defaultPatronTypeId])
    ? (string) $patronTypes[$defaultPatronTypeId]['nome']
    : __('Standard');
?>
<?php if (!empty($patronTypes)): ?>
<div>
  <label for="tipologia_id" class="block text-sm font-medium text-gray-700"><?= __("Tipologia lettore") ?></label>
  <select id="tipologia_id" name="tipologia_id" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900">
    <option value="" <?= $patronTypeId === null ? 'selected' : '' ?>><?= htmlspecialchars((string) (sprintf(__('Predefinita (%s)'), $ptDefaultName)), ENT_QUOTES, 'UTF-8') ?></option>
    <?php foreach ($patronTypes as $ptId => $pt): ?>
    <option value="<?= (int) $ptId ?>" data-card-days="<?= \App\Support\Features::enabled('automatic_card_expiry') && $pt['durata_tessera_giorni'] !== null ? (int) $pt['durata_tessera_giorni'] : '' ?>" <?= $patronTypeId === (int) $ptId ? 'selected' : '' ?>><?= htmlspecialchars((string) ((string) $pt['nome']), ENT_QUOTES, 'UTF-8') ?></option>
    <?php endforeach; ?>
  </select>
  <p class="text-xs text-gray-500 mt-1"><?= __("Decide le regole di prestito (limiti, durate, prenotazioni). Il tipo utente decide solo i permessi.") ?></p>
</div>
<?php endif; ?>

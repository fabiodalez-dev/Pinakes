<?php
/** @var string $activeTab */
/** @var string $csrfToken */
/** @var array{types: array<int, array<string, mixed>>, defaultTypeId: ?int, media: array<string, string>, rules: array<string, array<string, mixed>>, inherited: array<string, array<string, mixed>>, globals: array<string, mixed>, userCounts: array<int, int>, effective: array<int, \App\Services\Circulation\LoanPolicy>} $circulationData */

$cTypes = $circulationData['types'];
$cMedia = $circulationData['media'];
$cRules = $circulationData['rules'];
$cInherited = $circulationData['inherited'];
$cGlobals = $circulationData['globals'];
$cIntOrEmpty = static fn ($v): string => $v === null || $v === '' ? '' : (string) (int) $v;
$cMoneyOrEmpty = static fn ($v): string => $v === null || $v === '' ? '' : (string) $v;
// Inherited amount in the cell placeholder: "0.50", "—" when none applies.
$cMoneyPlaceholder = static fn (?float $v): string => $v !== null && $v > 0 ? number_format($v, 2, '.', '') : '—';
?>
<section data-settings-panel="circulation" class="settings-panel <?php echo $activeTab === 'circulation' ? 'block' : 'hidden'; ?>">
  <div class="space-y-6">

    <!-- Patron types -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="space-y-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-id-badge text-gray-500"></i>
          <?= __("Tipologie di lettore") ?>
        </h2>
        <p class="text-sm text-gray-600"><?= __("Ogni lettore appartiene a una tipologia (bambino, studente, docente…) che decide le sue regole di prestito. Il ruolo dell'utente continua a decidere solo i permessi.") ?></p>
        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
          <div class="flex items-start gap-2">
            <i class="fas fa-info-circle text-gray-600 mt-0.5"></i>
            <div class="text-xs text-gray-700">
              <?= __("Un campo lasciato vuoto eredita l'impostazione globale della scheda Prestiti. I lettori senza tipologia usano quella predefinita.") ?>
            </div>
          </div>
        </div>
      </div>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 space-y-4 max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
        <details id="circulation-new-type" class="space-y-4">
          <summary class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white border border-gray-300 text-gray-700 text-sm font-semibold hover:bg-gray-50 transition-colors cursor-pointer">
            <i class="fas fa-plus"></i>
            <?= __("Nuova tipologia") ?>
          </summary>
          <form action="<?= htmlspecialchars(url('/admin/settings/circulation/types'), ENT_QUOTES, 'UTF-8') ?>" method="post" class="space-y-4 pt-4" data-testid="circulation-type-create">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <?php $typeForm = null; require __DIR__ . '/partials/circulation-type-fields.php'; ?>
            <div class="flex justify-end">
              <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-black transition-colors">
                <i class="fas fa-save"></i>
                <?= __("Crea tipologia") ?>
              </button>
            </div>
          </form>
        </details>
      </div>
    </div>

    <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
      <div class="overflow-x-auto">
        <table class="w-full" id="circulation-types-table">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Tipologia") ?></th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Regole effettive") ?></th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Lettori") ?></th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Azioni") ?></th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <?php if ($cTypes === []): ?>
            <tr>
              <td colspan="4" class="px-6 py-12 text-center text-gray-500">
                <i class="fas fa-id-badge text-4xl mb-3 text-gray-300"></i>
                <p><?= __("Nessuna tipologia di lettore") ?></p>
              </td>
            </tr>
            <?php endif; ?>
            <?php $cIndex = 0; $cLast = count($cTypes) - 1; foreach ($cTypes as $cTypeId => $cType): $cEff = $circulationData['effective'][$cTypeId]; ?>
            <tr class="hover:bg-gray-50" data-type-code="<?= htmlspecialchars((string) $cType['codice'], ENT_QUOTES, 'UTF-8') ?>">
              <td class="px-6 py-4">
                <div class="text-sm font-medium text-gray-900">
                  <?= htmlspecialchars((string) ((string) $cType['nome']), ENT_QUOTES, 'UTF-8') ?>
                  <?php if ((int) $cType['predefinita'] === 1): ?>
                  <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800" data-testid="default-type-badge"><?= __("Predefinita") ?></span>
                  <?php endif; ?>
                  <?php if ((int) $cType['attiva'] !== 1): ?>
                  <span class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800"><?= __("Disattivata") ?></span>
                  <?php endif; ?>
                </div>
                <div class="text-sm text-gray-500"><?= htmlspecialchars((string) ((string) $cType['codice']), ENT_QUOTES, 'UTF-8') ?></div>
              </td>
              <td class="px-6 py-4 text-sm text-gray-700">
                <?= sprintf(__('%s prestiti · %d giorni · %d rinnovi'), $cEff->maxActiveLoans > 0 ? (string) $cEff->maxActiveLoans : '∞', $cEff->durationDays, $cEff->maxRenewals) ?>
                <div class="text-xs text-gray-500">
                  <?= $cEff->user->reservationsEnabled
                      ? sprintf(__('Prenotazioni: %s'), $cEff->user->maxReservations > 0 ? (string) $cEff->user->maxReservations : '∞')
                      : __('Prenotazioni disattivate') ?>
                  <?php if ($cEff->user->cardDurationDays !== null): ?> · <?= sprintf(__('Tessera: %d giorni'), $cEff->user->cardDurationDays) ?><?php endif; ?>
                  <?php $cFine = \App\Services\FineParameters::fromPolicy($cEff); if ($cFine->finesEnabled()): ?> · <span data-testid="type-fine-summary"><?= htmlspecialchars(sprintf(__('Multa: %s/giorno'), \App\Services\FineParameters::format($cFine->dailyFineCents)), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                  <?php if ($cFine->debtBlockEnabled()): ?> · <span data-testid="type-debt-summary"><?= htmlspecialchars(sprintf(__('Blocco oltre %s'), \App\Services\FineParameters::format((int) $cFine->debtBlockThresholdCents)), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                </div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= (int) ($circulationData['userCounts'][$cTypeId] ?? 0) ?></td>
              <td class="px-6 py-4 whitespace-nowrap text-sm">
                <div class="flex items-center gap-2">
                  <a href="<?= htmlspecialchars(url('/admin/settings/circulation/types/' . (int) $cTypeId), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-600 hover:text-gray-900" title="<?= htmlspecialchars(__('Modifica'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(__('Modifica'), ENT_QUOTES, 'UTF-8') ?>" data-testid="edit-type"><i class="fas fa-edit"></i></a>
                  <?php foreach (['up' => ['fa-arrow-up', __('Sposta su'), $cIndex > 0], 'down' => ['fa-arrow-down', __('Sposta giù'), $cIndex < $cLast]] as $cDir => [$cIcon, $cLabel, $cEnabled]): if (!$cEnabled) { continue; } ?>
                  <form action="<?= htmlspecialchars(url('/admin/settings/circulation/types/' . (int) $cTypeId . '/move'), ENT_QUOTES, 'UTF-8') ?>" method="post" class="inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="direction" value="<?= $cDir ?>">
                    <button type="submit" class="text-gray-600 hover:text-gray-900" title="<?= htmlspecialchars($cLabel, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($cLabel, ENT_QUOTES, 'UTF-8') ?>" data-testid="move-<?= $cDir ?>"><i class="fas <?= $cIcon ?>"></i></button>
                  </form>
                  <?php endforeach; ?>
                  <?php if ((int) $cType['predefinita'] !== 1): ?>
                  <form action="<?= htmlspecialchars(url('/admin/settings/circulation/types/' . (int) $cTypeId . '/default'), ENT_QUOTES, 'UTF-8') ?>" method="post" class="inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="text-gray-600 hover:text-gray-900" title="<?= htmlspecialchars(__('Rendi predefinita'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(__('Rendi predefinita'), ENT_QUOTES, 'UTF-8') ?>" data-testid="make-default"><i class="fas fa-star"></i></button>
                  </form>
                  <form action="<?= htmlspecialchars(url('/admin/settings/circulation/types/' . (int) $cTypeId . '/delete'), ENT_QUOTES, 'UTF-8') ?>" method="post" class="inline" onsubmit="return confirm(<?= htmlspecialchars(json_encode(__('Eliminare questa tipologia? I suoi lettori passeranno alla tipologia predefinita.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>);">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="text-red-600 hover:text-red-800" title="<?= htmlspecialchars(__('Elimina'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(__('Elimina'), ENT_QUOTES, 'UTF-8') ?>" data-testid="delete-type"><i class="fas fa-trash"></i></button>
                  </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php $cIndex++; endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Rules matrix -->
    <div class="space-y-4" id="circulation-matrix">
      <h2 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
        <i class="fas fa-table text-gray-500"></i>
        <?= __("Regole per tipo di materiale") ?>
      </h2>
      <p class="text-sm text-gray-600"><?= __("Eccezioni per tipologia di lettore e tipo di materiale: prestabilità, prestiti massimi di quel materiale (oltre al limite totale), durata, rinnovi e giorni per rinnovo. I valori in grigio sono quelli ereditati; scrivi un valore solo dove serve un'eccezione.") ?></p>
      <form action="<?= htmlspecialchars(url('/admin/settings/circulation/rules'), ENT_QUOTES, 'UTF-8') ?>" method="post" class="space-y-4" data-testid="circulation-matrix-form">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="bg-white border border-gray-200 rounded-2xl overflow-hidden max-sm:!bg-transparent max-sm:!border-0 max-sm:!rounded-none max-sm:!shadow-none max-sm:!p-0">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Tipologia") ?></th>
                  <?php foreach ($cMedia as $cMediaLabel): ?>
                  <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= htmlspecialchars((string) ($cMediaLabel), ENT_QUOTES, 'UTF-8') ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ([0 => ['nome' => __('Tutte le tipologie')]] + $cTypes as $cTypeId => $cType): ?>
                <tr data-matrix-row="<?= (int) $cTypeId ?>">
                  <td class="px-6 py-4 text-sm font-medium text-gray-900 align-top whitespace-nowrap"><?= htmlspecialchars((string) ((string) $cType['nome']), ENT_QUOTES, 'UTF-8') ?></td>
                  <?php foreach ($cMedia as $cM => $cMediaLabel):
                      $cKey = (int) $cTypeId . '|' . $cM;
                      $cRule = $cRules[$cKey] ?? [];
                      $cInh = $cInherited[$cKey];
                      $cName = 'rules[' . (int) $cTypeId . '][' . $cM . ']';
                      $cId = 'rule-' . (int) $cTypeId . '-' . $cM;
                      $cLoanable = isset($cRule['prestabile']) ? (string) (int) $cRule['prestabile'] : '';
                  ?>
                  <td class="px-3 py-2 align-top" data-matrix-cell="<?= htmlspecialchars($cKey, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="space-y-1">
                      <label for="<?= $cId ?>-loanable" class="sr-only"><?= htmlspecialchars((string) ($cMediaLabel . ' — ' . __('Prestabile')), ENT_QUOTES, 'UTF-8') ?></label>
                      <select id="<?= $cId ?>-loanable" name="<?= $cName ?>[loanable]" class="block w-28 rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-xs py-1 px-2">
                        <option value=""><?= htmlspecialchars((string) (sprintf(__('Eredita (%s)'), $cInh['loanable'] ? __('prestabile') : __('consultazione'))), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="1" <?= $cLoanable === '1' ? 'selected' : '' ?>><?= __("Prestabile") ?></option>
                        <option value="0" <?= $cLoanable === '0' ? 'selected' : '' ?>><?= __("Solo consultazione") ?></option>
                      </select>
                      <?php foreach (['max' => [__('Max prestiti'), 'max_prestiti', $cInh['max'] > 0 ? (string) $cInh['max'] : '∞'], 'duration' => [__('Giorni'), 'durata_prestito_giorni', (string) $cInh['duration']], 'renewals' => [__('Rinnovi'), 'max_rinnovi', (string) $cInh['renewals']], 'renewal_days' => [__('Gg/rinnovo'), 'giorni_rinnovo', (string) $cInh['renewal_days']], 'grace' => [__('Tolleranza'), 'giorni_tolleranza', (string) $cInh['grace']]] as $cField => [$cLabel, $cColumn, $cPlaceholder]): ?>
                      <label class="flex items-center gap-1 text-xs text-gray-500" for="<?= $cId . '-' . $cField ?>">
                        <span class="w-16"><?= htmlspecialchars((string) ($cLabel), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="number" min="0" id="<?= $cId . '-' . $cField ?>" name="<?= $cName ?>[<?= $cField ?>]" value="<?= htmlspecialchars($cIntOrEmpty($cRule[$cColumn] ?? null), ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars($cPlaceholder, ENT_QUOTES, 'UTF-8') ?>" class="block w-16 rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-xs py-1 px-2 placeholder:text-gray-400">
                      </label>
                      <?php endforeach; ?>
                      <?php foreach (['fine' => [__('Multa/giorno'), 'multa_giornaliera'], 'fine_max' => [__('Multa max'), 'multa_massima_prestito']] as $cField => [$cLabel, $cColumn]): ?>
                      <label class="flex items-center gap-1 text-xs text-gray-500" for="<?= $cId . '-' . $cField ?>">
                        <span class="w-16"><?= htmlspecialchars($cLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="text" inputmode="decimal" pattern="[0-9]{1,8}([.,][0-9]{1,2})?" id="<?= $cId . '-' . $cField ?>" name="<?= $cName ?>[<?= $cField ?>]" value="<?= htmlspecialchars($cMoneyOrEmpty($cRule[$cColumn] ?? null), ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars($cMoneyPlaceholder($cInh[$cField]), ENT_QUOTES, 'UTF-8') ?>" class="block w-16 rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-xs py-1 px-2 placeholder:text-gray-400">
                      </label>
                      <?php endforeach; ?>
                    </div>
                  </td>
                  <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="flex justify-end">
          <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-black transition-colors">
            <i class="fas fa-save"></i>
            <?= __("Salva regole") ?>
          </button>
        </div>
      </form>
    </div>
  </div>
</section>

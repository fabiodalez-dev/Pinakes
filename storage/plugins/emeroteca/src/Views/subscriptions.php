<?php
/**
 * Emeroteca — subscriptions of one testata: list + create form.
 *
 * Chrome copied verbatim from the core book pages: page shell, breadcrumb and
 * header row of app/Views/libri/index.php, one card for the table, and the
 * card / form-section-title / form-grid structure of
 * app/Views/libri/partials/book_form.php for the create form.
 *
 * @var array<string, mixed> $testata
 * @var list<array<string, mixed>> $abbonamenti  each with in_scadenza/scaduto flags
 * @var array<string, mixed> $values             re-populated create form after an error
 * @var array<string, string> $errors
 * @var int|null $giorni_preavviso               renewal warning window, in days
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$val = static fn(string $k): string => $e((string) ($values[$k] ?? ''));
$err = static fn(string $k): ?string => $errors[$k] ?? null;

$testataId = (int) $testata['id'];
$listUrl   = $e(url('/admin/periodicals/' . $testataId . '/subscriptions'));
$issuesUrl = $e(url('/admin/periodicals/' . $testataId . '/issues'));
$csrf      = $e(\App\Support\Csrf::ensureToken());
$giorni    = (int) ($giorni_preavviso ?? 60);
$total     = count($abbonamenti);
// A brand-new form has no posted body: default the subscription to active,
// which is what someone creating one almost always means.
$attivoChecked = $values === [] || (int) ($values['attivo'] ?? 0) === 1;
$rinnovoChecked = (int) ($values['rinnovo_automatico'] ?? 0) === 1;
?>
<div id="emeroteca-admin-subscriptions" class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center flex-wrap space-x-2 text-sm">
        <li>
          <a href="<?= $e(url('/admin/dashboard')) ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i><?= __('Home') ?>
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li>
          <a href="<?= $e(url('/admin/periodicals')) ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-newspaper mr-1"></i><?= __('Emeroteca') ?>
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li>
          <a href="<?= $issuesUrl ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <?= $e($testata['titolo']) ?>
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li class="text-gray-900 font-medium" aria-current="page">
          <?= __('Abbonamenti') ?>
        </li>
      </ol>
    </nav>

    <!-- Header with Actions -->
    <div class="mb-6">
      <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
        <div class="min-w-0">
          <h1 class="text-3xl font-bold text-gray-900"><?= __('Abbonamenti') ?></h1>
          <p class="text-sm text-gray-600 mt-1">
            <?= sprintf(
                __('Fornitori, costi e scadenze degli abbonamenti a «%s». Gli abbonamenti in scadenza entro %d giorni sono evidenziati.'),
                $e($testata['titolo']),
                $giorni
            ) ?>
          </p>
          <span class="status-badge bg-gray-100 text-gray-700 mt-3">
            <?= $e(sprintf($total === 1 ? __('%d risultato') : __('%d risultati'), $total)) ?>
          </span>
        </div>
        <div class="flex items-center gap-2">
          <a href="<?= $issuesUrl ?>" class="btn-secondary">
            <i class="fas fa-layer-group mr-2" aria-hidden="true"></i><?= __('Fascicoli') ?>
          </a>
        </div>
      </div>
    </div>

    <!-- Subscriptions list -->
    <?php if ($abbonamenti === []): ?>
      <div class="card mb-8 text-center" role="status">
        <i class="fas fa-file-signature text-gray-300 text-4xl mb-3" aria-hidden="true"></i>
        <p class="text-gray-700 font-medium"><?= __('Nessun abbonamento registrato.') ?></p>
        <p class="text-sm text-gray-500 mt-1"><?= __('Registra il primo abbonamento per tenere traccia di fornitore, costo e scadenza.') ?></p>
      </div>
    <?php else: ?>
      <div class="card mb-8 p-0 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= __('Fornitore') ?></th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= __('Costo') ?></th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= __('Validità') ?></th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= __('Rinnovo') ?></th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= __('Stato') ?></th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase"><?= __('Azioni') ?></th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php foreach ($abbonamenti as $ab): ?>
                <?php
                $abId = (int) $ab['id'];
                $inScadenza = (int) ($ab['in_scadenza'] ?? 0) === 1;
                $scaduto    = (int) ($ab['scaduto'] ?? 0) === 1;
                $attivo     = (int) ($ab['attivo'] ?? 0) === 1;
                $editUrl    = $e(url('/admin/periodicals/' . $testataId . '/subscriptions/' . $abId . '/edit'));
                $deleteUrl  = $e(url('/admin/periodicals/' . $testataId . '/subscriptions/' . $abId . '/delete'));
                ?>
                <tr class="hover:bg-gray-50">
                  <td class="px-6 py-4">
                    <div class="text-sm font-medium text-gray-900"><?= $e($ab['fornitore']) ?></div>
                    <?php if (!empty($ab['note'])): ?>
                      <div class="text-xs text-gray-500 mt-1"><?= $e($ab['note']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="px-6 py-4 text-sm text-gray-600 whitespace-nowrap">
                    <?php if ($ab['costo'] !== null): ?>
                      <?= $e($ab['costo']) ?> <span class="text-xs text-gray-500"><?= $e($ab['valuta']) ?></span>
                    <?php endif; ?>
                  </td>
                  <td class="px-6 py-4 text-sm text-gray-600 whitespace-nowrap">
                    <?= $e($ab['data_inizio'] ?? '') ?><?= !empty($ab['data_scadenza']) ? ' – ' . $e($ab['data_scadenza']) : '' ?>
                  </td>
                  <td class="px-6 py-4 text-sm text-gray-600">
                    <?= (int) ($ab['rinnovo_automatico'] ?? 0) === 1 ? __('Automatico') : __('Manuale') ?>
                  </td>
                  <td class="px-6 py-4">
                    <div class="flex flex-wrap items-center gap-2">
                      <span class="status-badge <?= $attivo ? 'bg-green-50 text-green-800' : 'bg-gray-100 text-gray-700' ?>">
                        <?= $attivo ? __('Attivo') : __('Non attivo') ?>
                      </span>
                      <?php if ($inScadenza): ?>
                        <span class="status-badge <?= $scaduto ? 'bg-red-50 text-red-800' : 'bg-yellow-50 text-yellow-800' ?>">
                          <?= $scaduto ? __('Scaduto') : __('In scadenza') ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td class="px-6 py-4 text-right">
                    <div class="flex items-center justify-end gap-0.5">
                      <a href="<?= $editUrl ?>" class="w-7 h-7 inline-flex items-center justify-center text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded transition-all" title="<?= $e(__('Modifica')) ?>" aria-label="<?= $e(__('Modifica')) ?>">
                        <i class="fas fa-edit text-xs" aria-hidden="true"></i>
                      </a>
                      <form method="POST" action="<?= $deleteUrl ?>" class="inline-flex"
                            onsubmit="return confirm(<?= $e(json_encode(__('Eliminare questo abbonamento?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <button type="submit" class="w-7 h-7 inline-flex items-center justify-center text-gray-500 hover:text-red-500 hover:bg-red-50 rounded transition-all" title="<?= $e(__('Elimina')) ?>" aria-label="<?= $e(__('Elimina')) ?>">
                          <i class="fas fa-trash text-xs" aria-hidden="true"></i>
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <!-- Create form -->
    <form method="POST" action="<?= $listUrl ?>" class="space-y-8">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-plus text-gray-900" aria-hidden="true"></i>
            <?= __('Nuovo abbonamento') ?>
          </h2>
        </div>
        <div class="card-body form-section">
          <div class="form-grid-3">
            <div>
              <label for="ab-fornitore" class="form-label">
                <?= __('Fornitore') ?> <span class="text-red-500">*</span>
              </label>
              <input id="ab-fornitore" type="text" name="fornitore" maxlength="255" required aria-required="true"
                     value="<?= $val('fornitore') ?>"
                     class="form-input <?= $err('fornitore') ? 'border-red-500' : '' ?>">
              <?php if ($err('fornitore')): ?>
                <p class="mt-1 text-xs text-red-600"><?= $e($err('fornitore')) ?></p>
              <?php endif; ?>
            </div>
            <div>
              <label for="ab-costo" class="form-label"><?= __('Costo') ?></label>
              <input id="ab-costo" type="text" name="costo" maxlength="20" inputmode="decimal"
                     placeholder="0.00" value="<?= $val('costo') ?>"
                     class="form-input <?= $err('costo') ? 'border-red-500' : '' ?>">
              <?php if ($err('costo')): ?>
                <p class="mt-1 text-xs text-red-600"><?= $e($err('costo')) ?></p>
              <?php endif; ?>
            </div>
            <?php $pickerId='ab-valuta'; $pickerName='valuta'; $pickerLabel=__('Valuta'); $pickerKind='currency'; $pickerCurrent=$values === [] ? 'EUR' : strtoupper((string)($values['valuta']??'')); $pickerHint=''; $pickerCodeHint=__('codice ISO, es. EUR'); $pickerMax=3; $pickerError=(string)($err('valuta') ?? ''); $pickerEmpty=false; include __DIR__.'/code-picker.php'; ?>
          </div>

          <div class="form-grid-2">
            <div>
              <label for="ab-inizio" class="form-label"><?= __('Data di inizio') ?></label>
              <input id="ab-inizio" type="date" name="data_inizio" value="<?= $val('data_inizio') ?>"
                     class="form-input <?= $err('data_inizio') ? 'border-red-500' : '' ?>">
              <?php if ($err('data_inizio')): ?>
                <p class="mt-1 text-xs text-red-600"><?= $e($err('data_inizio')) ?></p>
              <?php endif; ?>
            </div>
            <div>
              <label for="ab-scadenza" class="form-label"><?= __('Data di scadenza') ?></label>
              <input id="ab-scadenza" type="date" name="data_scadenza" value="<?= $val('data_scadenza') ?>"
                     class="form-input <?= $err('data_scadenza') ? 'border-red-500' : '' ?>">
              <?php if ($err('data_scadenza')): ?>
                <p class="mt-1 text-xs text-red-600"><?= $e($err('data_scadenza')) ?></p>
              <?php endif; ?>
            </div>
          </div>

          <div class="form-grid-2">
            <div>
              <label class="flex items-center space-x-2 text-sm py-1 cursor-pointer">
                <input type="checkbox" name="rinnovo_automatico" value="1"
                       class="w-4 h-4 rounded border-gray-300 text-gray-900 focus:ring-gray-500"
                    <?= $rinnovoChecked ? 'checked' : '' ?>>
                <span class="text-gray-700 font-medium"><?= __('Rinnovo automatico') ?></span>
              </label>
              <p class="text-xs text-gray-500 mt-1"><?= __('Il fornitore rinnova senza un nuovo ordine.') ?></p>
            </div>
            <div>
              <label class="flex items-center space-x-2 text-sm py-1 cursor-pointer">
                <input type="checkbox" name="attivo" value="1"
                       class="w-4 h-4 rounded border-gray-300 text-gray-900 focus:ring-gray-500"
                    <?= $attivoChecked ? 'checked' : '' ?>>
                <span class="text-gray-700 font-medium"><?= __('Attivo') ?></span>
              </label>
              <p class="text-xs text-gray-500 mt-1"><?= __('Solo gli abbonamenti attivi entrano nell\'avviso di scadenza.') ?></p>
            </div>
          </div>

          <div>
            <label for="ab-note" class="form-label"><?= __('Note') ?></label>
            <input id="ab-note" type="text" name="note" maxlength="255"
                   value="<?= $val('note') ?>" class="form-input">
          </div>
        </div>
      </div>

      <div class="flex flex-col sm:flex-row gap-4 justify-end">
        <button type="submit" class="btn-primary">
          <i class="fas fa-save mr-2" aria-hidden="true"></i><?= __('Crea abbonamento') ?>
        </button>
      </div>
    </form>
  </div>
</div>
<script src="<?= $e(url('/plugins/emeroteca/assets/js/emeroteca-code-picker.js?v=1.0.0')) ?>" defer></script>

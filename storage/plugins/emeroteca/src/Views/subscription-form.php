<?php
/**
 * Emeroteca — edit form for one subscription of a testata.
 *
 * Chrome copied verbatim from the core book form (app/Views/libri/crea_libro.php
 * + partials/book_form.php): page shell, breadcrumb, header, one .card per
 * section with a form-section-title, form-grid-2 fields and the
 * Annulla / Salva action row.
 *
 * @var array<string, mixed> $testata
 * @var int $id                        subscription id
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$val = static fn(string $k): string => $e((string) ($values[$k] ?? ''));
$err = static fn(string $k): ?string => $errors[$k] ?? null;

$testataId = (int) $testata['id'];
$subId     = (int) $id;
$listUrl   = $e(url('/admin/periodicals/' . $testataId . '/subscriptions'));
$issuesUrl = $e(url('/admin/periodicals/' . $testataId . '/issues'));
$formAction = $e(url('/admin/periodicals/' . $testataId . '/subscriptions/' . $subId . '/edit'));
?>
<div id="emeroteca-admin-subscription-form" class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Breadcrumb -->


    <!-- Header -->
    <?php
// Shared page anatomy; existing contextual controls are retained verbatim.
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title text-3xl font-bold text-gray-900 mb-2"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?><?= __('Modifica abbonamento') ?><?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?>

      <p class="text-gray-600"><?= $e($testata['titolo']) ?></p>
    <?php
$workspaceHeader['context'] = (string) ob_get_clean();
ob_start();
?><nav class="pk-workspace-breadcrumb" aria-label="<?= htmlspecialchars(__('Percorso di navigazione'), ENT_QUOTES, 'UTF-8') ?>">
      <ol>
        <li>
          <a href="<?= $e(url('/admin/dashboard')) ?>"><?= __('Home') ?></a>
        </li>

        <li>
          <a href="<?= $e(url('/admin/periodicals')) ?>">
            <?= __('Emeroteca') ?>
          </a>
        </li>

        <li>
          <a href="<?= $issuesUrl ?>">
            <?= $e($testata['titolo']) ?>
          </a>
        </li>

        <li>
          <a href="<?= $listUrl ?>">
            <?= __('Abbonamenti') ?>
          </a>
        </li>

        <li aria-current="page">
          <?= __('Modifica abbonamento') ?>
        </li>
      </ol>
    </nav><?php
$workspaceHeader['breadcrumb'] = (string) ob_get_clean();
require dirname(__DIR__, 5) . '/app/Views/partials/workspace-header.php';
unset($workspaceHeader);
?>

    <!-- Main Form -->
    <form method="POST" action="<?= $formAction ?>" class="space-y-8">
      <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">

      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-file-signature text-gray-900"></i>
            <?= __('Fornitore e costo') ?>
          </h2>
        </div>
        <div class="card-body form-section">
          <div>
            <label for="fornitore" class="form-label">
              <?= __('Fornitore') ?> <span class="text-red-500">*</span>
            </label>
            <input type="text" name="fornitore" id="fornitore"
                   value="<?= $val('fornitore') ?>" maxlength="255" required aria-required="true"
                   class="form-input <?= $err('fornitore') ? 'border-red-500' : '' ?>">
            <?php if ($err('fornitore')): ?>
              <p class="mt-1 text-xs text-red-600"><?= $e($err('fornitore')) ?></p>
            <?php endif; ?>
          </div>

          <div class="form-grid-2">
            <div>
              <label for="costo" class="form-label"><?= __('Costo') ?></label>
              <input type="text" name="costo" id="costo"
                     value="<?= $val('costo') ?>" maxlength="20" inputmode="decimal"
                     placeholder="0.00"
                     class="form-input <?= $err('costo') ? 'border-red-500' : '' ?>">
              <?php if ($err('costo')): ?>
                <p class="mt-1 text-xs text-red-600"><?= $e($err('costo')) ?></p>
              <?php endif; ?>
            </div>
            <?php $pickerId='valuta'; $pickerName='valuta'; $pickerLabel=__('Valuta'); $pickerKind='currency'; $pickerCurrent=strtoupper((string)($values['valuta']??'EUR')); $pickerHint=''; $pickerCodeHint=__('codice ISO, es. EUR'); $pickerMax=3; $pickerError=(string)($err('valuta') ?? ''); $pickerEmpty=false; include __DIR__.'/code-picker.php'; ?>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-calendar-alt text-gray-900"></i>
            <?= __('Validità') ?>
          </h2>
        </div>
        <div class="card-body form-section">
          <div class="form-grid-2">
            <div>
              <label for="data_inizio" class="form-label"><?= __('Data di inizio') ?></label>
              <input type="date" name="data_inizio" id="data_inizio"
                     value="<?= $val('data_inizio') ?>"
                     class="form-input <?= $err('data_inizio') ? 'border-red-500' : '' ?>">
              <?php if ($err('data_inizio')): ?>
                <p class="mt-1 text-xs text-red-600"><?= $e($err('data_inizio')) ?></p>
              <?php endif; ?>
            </div>
            <div>
              <label for="data_scadenza" class="form-label"><?= __('Data di scadenza') ?></label>
              <input type="date" name="data_scadenza" id="data_scadenza"
                     value="<?= $val('data_scadenza') ?>"
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
                    <?= (int) ($values['rinnovo_automatico'] ?? 0) === 1 ? 'checked' : '' ?>>
                <span class="text-gray-700 font-medium"><?= __('Rinnovo automatico') ?></span>
              </label>
              <p class="text-xs text-gray-500 mt-1"><?= __('Il fornitore rinnova senza un nuovo ordine.') ?></p>
            </div>
            <div>
              <label class="flex items-center space-x-2 text-sm py-1 cursor-pointer">
                <input type="checkbox" name="attivo" value="1"
                       class="w-4 h-4 rounded border-gray-300 text-gray-900 focus:ring-gray-500"
                    <?= (int) ($values['attivo'] ?? 0) === 1 ? 'checked' : '' ?>>
                <span class="text-gray-700 font-medium"><?= __('Attivo') ?></span>
              </label>
              <p class="text-xs text-gray-500 mt-1"><?= __('Solo gli abbonamenti attivi entrano nell\'avviso di scadenza.') ?></p>
            </div>
          </div>

          <div>
            <label for="note" class="form-label"><?= __('Note') ?></label>
            <textarea name="note" id="note" rows="3" class="form-input"><?= $val('note') ?></textarea>
          </div>
        </div>
      </div>

      <div class="pk-form-actions flex flex-col sm:flex-row gap-4 justify-end">
        <a href="<?= $listUrl ?>" class="btn-secondary order-2 sm:order-1">
          <i class="fas fa-times mr-2"></i>
          <?= __('Annulla') ?>
        </a>
        <button type="submit" class="btn-primary order-1 sm:order-2">
          <i class="fas fa-save mr-2"></i>
          <?= __('Salva modifiche') ?>
        </button>
      </div>
    </form>
  </div>
</div>
<script<?= csp_nonce_attr() ?> src="<?= $e(url('/plugins/emeroteca/assets/js/emeroteca-code-picker.js?v=1.0.0')) ?>" defer></script>

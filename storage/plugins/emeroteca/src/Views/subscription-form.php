<?php
/**
 * Emeroteca — edit form for one subscription of a testata.
 *
 * Chrome copied verbatim from the testata form view (form.php): same
 * emt-surface / emt-form-section / form-label / form-input structure.
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
$formAction = $e(url('/admin/periodicals/' . $testataId . '/subscriptions/' . $subId . '/edit'));
?>
<link rel="stylesheet" href="<?= $e(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.4.0')) ?>">
<div id="emeroteca-admin-subscription-form" class="emeroteca-admin emeroteca-admin--form">
    <div class="emt-page-header">
        <div>
        <nav class="text-sm text-gray-500 mb-2">
            <a href="<?= $e(url('/admin/periodicals')) ?>" class="hover:underline"><?= __('Emeroteca') ?></a>
            &nbsp;&raquo;&nbsp;
            <a href="<?= $listUrl ?>" class="hover:underline"><?= __('Abbonamenti') ?></a>
            &nbsp;&raquo;&nbsp; <?= __('Modifica abbonamento') ?>
        </nav>
        <h1 class="text-2xl font-bold text-gray-900"><?= __('Modifica abbonamento') ?></h1>
        <p class="text-sm text-gray-600 mt-1"><?= $e($testata['titolo']) ?></p>
        </div>
    </div>

    <form method="POST" action="<?= $formAction ?>" class="emt-surface p-6 space-y-5">
        <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">

        <section class="emt-form-section">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
                <?= __('Fornitore e costo') ?>
            </h2>

            <div>
                <label for="fornitore" class="form-label">
                    <?= __('Fornitore') ?> <span class="text-red-500">*</span>
                </label>
                <input type="text" name="fornitore" id="fornitore"
                       value="<?= $val('fornitore') ?>" maxlength="255" required
                       class="form-input <?= $err('fornitore') ? 'border-red-500' : '' ?>">
                <?php if ($err('fornitore')): ?>
                    <p class="mt-1 text-xs text-red-600"><?= $e($err('fornitore')) ?></p>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
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
                <div>
                    <label for="valuta" class="form-label">
                        <?= __('Valuta') ?>
                        <span class="text-xs text-gray-500 font-normal">(<?= __('codice ISO, es. EUR') ?>)</span>
                    </label>
                    <input type="text" name="valuta" id="valuta"
                           value="<?= $val('valuta') ?>" maxlength="3"
                           class="form-input <?= $err('valuta') ? 'border-red-500' : '' ?>">
                    <?php if ($err('valuta')): ?>
                        <p class="mt-1 text-xs text-red-600"><?= $e($err('valuta')) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="emt-form-section">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
                <?= __('Validità') ?>
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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

            <div class="emt-choice-list mt-4">
                <label class="emt-choice">
                    <input type="checkbox" name="rinnovo_automatico" value="1" class="form-checkbox"
                        <?= (int) ($values['rinnovo_automatico'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <span>
                        <strong><?= __('Rinnovo automatico') ?></strong>
                        <small><?= __('Il fornitore rinnova senza un nuovo ordine.') ?></small>
                    </span>
                </label>
                <label class="emt-choice">
                    <input type="checkbox" name="attivo" value="1" class="form-checkbox"
                        <?= (int) ($values['attivo'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <span>
                        <strong><?= __('Attivo') ?></strong>
                        <small><?= __('Solo gli abbonamenti attivi entrano nell\'avviso di scadenza.') ?></small>
                    </span>
                </label>
            </div>

            <div class="mt-4">
                <label for="note" class="form-label"><?= __('Note') ?></label>
                <textarea name="note" id="note" rows="3" class="form-input"><?= $val('note') ?></textarea>
            </div>
        </section>

        <div class="flex items-center justify-end space-x-3 pt-4 border-t">
            <a href="<?= $listUrl ?>" class="btn-secondary"><?= __('Annulla') ?></a>
            <button type="submit" class="btn-primary"><?= __('Salva modifiche') ?></button>
        </div>
    </form>
</div>

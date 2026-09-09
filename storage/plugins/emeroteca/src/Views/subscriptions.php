<?php
/**
 * Emeroteca — subscriptions of one testata: list + create form.
 *
 * Chrome copied verbatim from the sibling emeroteca admin views
 * (issues.php header/quick-form, index.php table) so the section looks
 * like the rest of the plugin.
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
// A brand-new form has no posted body: default the subscription to active,
// which is what someone creating one almost always means.
$attivoChecked = $values === [] || (int) ($values['attivo'] ?? 0) === 1;
$rinnovoChecked = (int) ($values['rinnovo_automatico'] ?? 0) === 1;
?>
<link rel="stylesheet" href="<?= $e(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.4.0')) ?>">
<div id="emeroteca-admin-subscriptions" class="emeroteca-admin">
    <header class="emt-page-header">
        <nav aria-label="breadcrumb" class="text-sm text-gray-500 mb-4">
            <a href="<?= $e(url('/admin/periodicals')) ?>" class="hover:underline"><?= __('Emeroteca') ?></a>
            &nbsp;&raquo;&nbsp;
            <a href="<?= $issuesUrl ?>" class="hover:underline"><?= $e($testata['titolo']) ?></a>
            &nbsp;&raquo;&nbsp; <?= __('Abbonamenti') ?>
        </nav>
        <div class="emt-page-header__main">
            <div class="min-w-0">
                <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
                    <i class="fas fa-file-signature text-gray-600" aria-hidden="true"></i>
                    <span><?= __('Abbonamenti') ?></span>
                </h1>
                <p class="text-gray-600 mt-2">
                    <?= sprintf(
                        __('Fornitori, costi e scadenze degli abbonamenti a «%s». Gli abbonamenti in scadenza entro %d giorni sono evidenziati.'),
                        $e($testata['titolo']),
                        $giorni
                    ) ?>
                </p>
            </div>
            <a href="<?= $issuesUrl ?>" class="btn-secondary inline-flex items-center gap-2 text-sm">
                <i class="fas fa-layer-group" aria-hidden="true"></i>
                <?= __('Fascicoli') ?>
            </a>
        </div>
    </header>

    <?php if ($abbonamenti === []): ?>
        <div class="emt-notice bg-yellow-50 text-yellow-900" role="status">
            <p class="text-sm text-yellow-800">
                <strong><?= __('Nessun abbonamento registrato.') ?></strong>
                <?= __('Registra il primo abbonamento per tenere traccia di fornitore, costo e scadenza.') ?>
            </p>
        </div>
    <?php else: ?>
        <div class="emt-surface overflow-hidden mb-6">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Fornitore') ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Costo') ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Validità') ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Rinnovo') ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Stato') ?></th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Azioni') ?></th>
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
                        <tr class="hover:bg-gray-50 border-b">
                            <td class="px-4 py-2">
                                <div class="text-gray-900 font-medium"><?= $e($ab['fornitore']) ?></div>
                                <?php if (!empty($ab['note'])): ?>
                                    <div class="text-xs text-gray-500"><?= $e($ab['note']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-600 whitespace-nowrap">
                                <?php if ($ab['costo'] !== null): ?>
                                    <?= $e($ab['costo']) ?> <span class="text-xs text-gray-500"><?= $e($ab['valuta']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-600 whitespace-nowrap">
                                <?= $e($ab['data_inizio'] ?? '') ?><?= !empty($ab['data_scadenza']) ? ' – ' . $e($ab['data_scadenza']) : '' ?>
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-600">
                                <?= (int) ($ab['rinnovo_automatico'] ?? 0) === 1 ? __('Automatico') : __('Manuale') ?>
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">
                                <span class="emt-status emt-status--<?= $attivo ? 'attiva' : 'dismessa' ?>">
                                    <i aria-hidden="true"></i><?= $attivo ? __('Attivo') : __('Non attivo') ?>
                                </span>
                                <?php if ($inScadenza): ?>
                                    <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded <?= $scaduto ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800' ?>">
                                        <?= $scaduto ? __('Scaduto') : __('In scadenza') ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-2 text-right text-sm whitespace-nowrap">
                                <a href="<?= $editUrl ?>" class="emt-table-action" title="<?= $e(__('Modifica')) ?>" aria-label="<?= $e(__('Modifica')) ?>"><i class="fas fa-pen" aria-hidden="true"></i><span class="emt-action-label"><?= __('modifica') ?></span></a>
                                <form method="POST" action="<?= $deleteUrl ?>" class="inline"
                                      onsubmit="return confirm(<?= $e(json_encode(__('Eliminare questo abbonamento?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                    <button type="submit" class="emt-table-action emt-table-action--danger" title="<?= $e(__('Elimina')) ?>" aria-label="<?= $e(__('Elimina')) ?>"><i class="fas fa-trash" aria-hidden="true"></i><span class="emt-action-label"><?= __('elimina') ?></span></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endif; ?>

    <section class="card emt-actions-panel" aria-label="<?= $e(__('Nuovo abbonamento')) ?>">
        <div class="card-header">
            <h2 class="form-section-title flex items-center gap-2">
                <i class="fas fa-plus text-gray-600" aria-hidden="true"></i>
                <?= __('Nuovo abbonamento') ?>
            </h2>
        </div>
        <form method="POST" action="<?= $listUrl ?>" class="emt-quick-form">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="emt-inline-fields">
                <div class="emt-field--volume">
                    <label for="ab-fornitore" class="form-label"><?= __('Fornitore') ?> *</label>
                    <input id="ab-fornitore" type="text" name="fornitore" maxlength="255" required
                           value="<?= $val('fornitore') ?>"
                           class="form-input <?= $err('fornitore') ? 'border-red-500' : '' ?>">
                    <?php if ($err('fornitore')): ?>
                        <p class="mt-1 text-xs text-red-600"><?= $e($err('fornitore')) ?></p>
                    <?php endif; ?>
                </div>
                <div class="emt-field--number">
                    <label for="ab-costo" class="form-label"><?= __('Costo') ?></label>
                    <input id="ab-costo" type="text" name="costo" maxlength="20" inputmode="decimal"
                           placeholder="0.00" value="<?= $val('costo') ?>"
                           class="form-input <?= $err('costo') ? 'border-red-500' : '' ?>">
                    <?php if ($err('costo')): ?>
                        <p class="mt-1 text-xs text-red-600"><?= $e($err('costo')) ?></p>
                    <?php endif; ?>
                </div>
                <div class="emt-field--number">
                    <label for="ab-valuta" class="form-label"><?= __('Valuta') ?></label>
                    <input id="ab-valuta" type="text" name="valuta" maxlength="3"
                           value="<?= $values === [] ? 'EUR' : $val('valuta') ?>"
                           class="form-input <?= $err('valuta') ? 'border-red-500' : '' ?>">
                    <?php if ($err('valuta')): ?>
                        <p class="mt-1 text-xs text-red-600"><?= $e($err('valuta')) ?></p>
                    <?php endif; ?>
                </div>
                <div class="emt-field--date">
                    <label for="ab-inizio" class="form-label"><?= __('Data di inizio') ?></label>
                    <input id="ab-inizio" type="date" name="data_inizio" value="<?= $val('data_inizio') ?>"
                           class="form-input <?= $err('data_inizio') ? 'border-red-500' : '' ?>">
                    <?php if ($err('data_inizio')): ?>
                        <p class="mt-1 text-xs text-red-600"><?= $e($err('data_inizio')) ?></p>
                    <?php endif; ?>
                </div>
                <div class="emt-field--date">
                    <label for="ab-scadenza" class="form-label"><?= __('Data di scadenza') ?></label>
                    <input id="ab-scadenza" type="date" name="data_scadenza" value="<?= $val('data_scadenza') ?>"
                           class="form-input <?= $err('data_scadenza') ? 'border-red-500' : '' ?>">
                    <?php if ($err('data_scadenza')): ?>
                        <p class="mt-1 text-xs text-red-600"><?= $e($err('data_scadenza')) ?></p>
                    <?php endif; ?>
                </div>
                <label class="emt-checkbox-label">
                    <input type="checkbox" name="rinnovo_automatico" value="1" class="form-checkbox"
                        <?= $rinnovoChecked ? 'checked' : '' ?>>
                    <span><?= __('Rinnovo automatico') ?></span>
                </label>
                <label class="emt-checkbox-label">
                    <input type="checkbox" name="attivo" value="1" class="form-checkbox"
                        <?= $attivoChecked ? 'checked' : '' ?>>
                    <span><?= __('Attivo') ?></span>
                </label>
                <button type="submit" class="btn-primary text-sm"><?= __('Crea abbonamento') ?></button>
            </div>
            <div class="emt-inline-fields">
                <div class="emt-field--volume" style="flex:1 1 100%">
                    <label for="ab-note" class="form-label"><?= __('Note') ?></label>
                    <input id="ab-note" type="text" name="note" maxlength="255"
                           value="<?= $val('note') ?>" class="form-input">
                </div>
            </div>
        </form>
    </section>
</div>

<?php
/**
 * Emeroteca — admin list of testate.
 *
 * Chrome copied verbatim from the Archives admin index view
 * (storage/plugins/archives/views/index.php) for consistency.
 *
 * @var array<int, array<string, mixed>> $rows
 * @var array<int, string> $editori   id => nome (empty on degraded schema)
 * @var string|null $f_tipo
 * @var int|null    $f_editore
 * @var string|null $f_stato
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$f_tipo    = $f_tipo    ?? '';
$f_editore = (int) ($f_editore ?? 0);
$f_stato   = $f_stato   ?? '';

$tipoLabels = [
    'rivista'    => __('Rivista'),
    'giornale'   => __('Giornale'),
    'magazine'   => __('Magazine'),
    'bollettino' => __('Bollettino'),
    'fanzine'    => __('Fanzine'),
];
$statoLabels = [
    'attiva'   => __('Attiva'),
    'chiusa'   => __('Chiusa'),
    'dismessa' => __('Dismessa'),
];
$periodicitaLabels = [
    'quotidiano'   => __('Quotidiano'),
    'settimanale'  => __('Settimanale'),
    'quindicinale' => __('Quindicinale'),
    'mensile'      => __('Mensile'),
    'bimestrale'   => __('Bimestrale'),
    'trimestrale'  => __('Trimestrale'),
    'semestrale'   => __('Semestrale'),
    'annuale'      => __('Annuale'),
    'irregolare'   => __('Irregolare'),
];
$statoBadge = [
    'attiva'   => 'bg-green-100 text-green-800',
    'chiusa'   => 'bg-yellow-100 text-yellow-800',
    'dismessa' => 'bg-gray-100 text-gray-800',
];
$isFiltered = $f_tipo !== '' || $f_editore > 0 || $f_stato !== '';
?>
<div class="p-6 max-w-7xl mx-auto">
    <div class="mb-5">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h1 class="text-2xl font-bold text-gray-900"><?= __("Emeroteca") ?></h1>
            <p class="text-sm text-gray-500">
                <?= __("Gestione di riviste, giornali e periodici: testate, annate e fascicoli.") ?>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2 mt-3">
            <a href="<?= $e(url('/admin/periodicals/create')) ?>"
               class="btn-primary inline-flex items-center text-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                <?= __("Nuova testata") ?>
            </a>
        </div>
    </div>

    <form method="GET" action="<?= $e(url('/admin/periodicals')) ?>"
          class="bg-white shadow rounded-lg p-4 mb-6 flex flex-wrap items-end gap-3">
        <div class="min-w-[150px]">
            <label for="emt-tipo" class="block text-xs font-medium text-gray-600 mb-1">
                <?= __("Tipo") ?>
            </label>
            <select id="emt-tipo" name="tipo"
                    class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500">
                <option value=""><?= __("Tutti i tipi") ?></option>
                <?php foreach ($tipoLabels as $value => $label): ?>
                    <option value="<?= $e($value) ?>" <?= $f_tipo === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($editori !== []): ?>
        <div class="min-w-[200px]">
            <label for="emt-editore" class="block text-xs font-medium text-gray-600 mb-1">
                <?= __("Editore") ?>
            </label>
            <select id="emt-editore" name="editore"
                    class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500">
                <option value=""><?= __("Tutti gli editori") ?></option>
                <?php foreach ($editori as $eid => $nome): ?>
                    <option value="<?= (int) $eid ?>" <?= $f_editore === (int) $eid ? 'selected' : '' ?>><?= $e($nome) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="min-w-[150px]">
            <label for="emt-stato" class="block text-xs font-medium text-gray-600 mb-1">
                <?= __("Stato raccolta") ?>
            </label>
            <select id="emt-stato" name="stato_raccolta"
                    class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500">
                <option value=""><?= __("Tutti gli stati") ?></option>
                <?php foreach ($statoLabels as $value => $label): ?>
                    <option value="<?= $e($value) ?>" <?= $f_stato === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary">
                <svg class="w-4 h-4 inline -mt-0.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <?= __("Filtra") ?>
            </button>
            <?php if ($isFiltered): ?>
                <a href="<?= $e(url('/admin/periodicals')) ?>" class="btn-secondary">
                    <?= __("Azzera") ?>
                </a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (empty($rows)): ?>
        <?php if ($isFiltered): ?>
            <div class="bg-gray-50 border border-gray-200 p-6 rounded text-center">
                <p class="text-sm text-gray-700"><?= __("Nessuna testata corrisponde ai filtri selezionati.") ?></p>
            </div>
        <?php else: ?>
            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
                <p class="text-sm text-yellow-800">
                    <strong><?= __("Nessuna testata registrata.") ?></strong>
                    <?= __("Crea la prima testata per iniziare a catalogare riviste e periodici.") ?>
                </p>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Testata") ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("ISSN") ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Editore") ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Periodicità") ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Tipo") ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Fascicoli") ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Consistenza") ?></th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Azioni") ?></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $rowId = (int) $row['id'];
                        $issuesUrl = $e(url('/admin/periodicals/' . $rowId . '/issues'));
                        $editUrl   = $e(url('/admin/periodicals/edit/' . $rowId));
                        $deleteUrl = $e(url('/admin/periodicals/delete/' . $rowId));
                        $logo = (string) ($row['logo_url'] ?? '');
                        $logoSrc = $logo === '' ? '' : (str_starts_with($logo, '/') ? url($logo) : $logo);
                        $statoVal = (string) ($row['stato_raccolta'] ?? 'attiva');
                        ?>
                        <tr class="hover:bg-gray-50 border-b">
                            <td class="px-4 py-2">
                                <div class="flex items-center">
                                    <?php if ($logoSrc !== ''): ?>
                                        <img src="<?= $e($logoSrc) ?>" alt="<?= $e($row['titolo']) ?>"
                                             class="w-10 h-10 rounded object-cover mr-3">
                                    <?php else: ?>
                                        <div class="flex items-center justify-center w-10 h-10 rounded-lg bg-gray-100 mr-3">
                                            <i class="fas fa-newspaper text-gray-600"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <a href="<?= $issuesUrl ?>" class="text-gray-900 font-medium hover:underline"><?= $e($row['titolo']) ?></a>
                                        <?php if (!empty($row['sottotitolo'])): ?>
                                            <div class="text-xs text-gray-500"><?= $e($row['sottotitolo']) ?></div>
                                        <?php endif; ?>
                                        <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded <?= $e($statoBadge[$statoVal] ?? 'bg-gray-100 text-gray-800') ?>">
                                            <?= $e($statoLabels[$statoVal] ?? $statoVal) ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-2 font-mono text-xs text-gray-500"><?= $e($row['issn'] ?? '') ?></td>
                            <td class="px-4 py-2 text-sm text-gray-600"><?= $e($row['editore_nome'] ?? '') ?></td>
                            <td class="px-4 py-2 text-sm text-gray-600">
                                <?= $e($periodicitaLabels[(string) ($row['periodicita'] ?? '')] ?? '') ?>
                            </td>
                            <td class="px-4 py-2">
                                <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded bg-blue-100 text-blue-800">
                                    <?= $e($tipoLabels[(string) ($row['tipo'] ?? '')] ?? (string) ($row['tipo'] ?? '')) ?>
                                </span>
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-600 whitespace-nowrap">
                                <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded bg-green-100 text-green-800"
                                      title="<?= $e(__('Posseduti')) ?>"><?= (int) $row['n_posseduti'] ?></span>
                                <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded bg-red-100 text-red-800"
                                      title="<?= $e(__('Mancanti')) ?>"><?= (int) $row['n_mancanti'] ?></span>
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-600 whitespace-nowrap"><?= $e($row['consistenza'] ?? '—') ?></td>
                            <td class="px-4 py-2 text-right text-sm whitespace-nowrap">
                                <a href="<?= $issuesUrl ?>" class="text-blue-600 hover:underline"><?= __('fascicoli') ?></a>
                                &middot;
                                <a href="<?= $editUrl ?>" class="text-blue-600 hover:underline"><?= __('modifica') ?></a>
                                &middot;
                                <form method="POST" action="<?= $deleteUrl ?>" class="inline"
                                      onsubmit="return confirm(<?= $e(json_encode(__('Eliminare questa testata con tutte le annate, i fascicoli e lo spoglio?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);">
                                    <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
                                    <button type="submit" class="text-red-600 hover:underline"><?= __('elimina') ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <p class="text-xs text-gray-500 mt-3">
            <?= sprintf(__("Testate registrate: %d."), count($rows)) ?>
        </p>
    <?php endif; ?>
</div>

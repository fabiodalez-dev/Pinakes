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
 * @var int|null    $page         current page (1-based)
 * @var int|null    $total_pages
 * @var int|null    $total        total testate matching the filters
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$f_tipo    = $f_tipo    ?? '';
$f_editore = (int) ($f_editore ?? 0);
$f_stato   = $f_stato   ?? '';
$page       = max(1, (int) ($page ?? 1));
$totalPages = max(1, (int) ($total_pages ?? 1));
$total      = (int) ($total ?? count($rows));

/** Page link keeping the active filters (page param last). */
$pageUrl = static function (int $p) use ($f_tipo, $f_editore, $f_stato): string {
    $qs = [];
    if ($f_tipo !== '') {
        $qs['tipo'] = $f_tipo;
    }
    if ($f_editore > 0) {
        $qs['editore'] = $f_editore;
    }
    if ($f_stato !== '') {
        $qs['stato_raccolta'] = $f_stato;
    }
    $qs['page'] = $p;
    return url('/admin/periodicals') . '?' . http_build_query($qs);
};

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
$isFiltered = $f_tipo !== '' || $f_editore > 0 || $f_stato !== '';
?>
<link rel="stylesheet" href="<?= $e(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.4.0')) ?>">
<div id="emeroteca-admin-index" class="emeroteca-admin">
    <div class="emt-page-header emt-page-header--index">
        <div>
            <h1 class="text-3xl font-bold text-gray-900"><?= __("Emeroteca") ?></h1>
            <p class="mt-1 text-sm text-gray-600">
                <?= __("Gestione di riviste, giornali e periodici: testate, annate e fascicoli.") ?>
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="<?= $e(url('/admin/periodicals/export/kbart')) ?>"
               class="btn-secondary inline-flex items-center text-sm"
               title="<?= $e(__('Esporta le consistenze in formato KBART (TSV) per i cataloghi collettivi')) ?>">
                <i class="fas fa-file-export mr-2" aria-hidden="true"></i>
                <?= __("Esporta KBART") ?>
            </a>
            <a href="<?= $e(url('/admin/periodicals/export/acnp')) ?>"
               class="btn-secondary inline-flex items-center text-sm"
               title="<?= $e(__('Esporta le consistenze in formato ACNP (CSV)')) ?>">
                <i class="fas fa-file-csv mr-2" aria-hidden="true"></i>
                <?= __("Esporta ACNP") ?>
            </a>
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
          class="emt-toolbar p-4 mb-6 flex flex-wrap items-end gap-3">
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
            <div class="emt-empty">
                <p class="text-sm text-gray-700"><?= __("Nessuna testata corrisponde ai filtri selezionati.") ?></p>
            </div>
        <?php else: ?>
            <div class="emt-notice bg-yellow-50 text-yellow-900" role="status">
                <p class="text-sm text-yellow-800">
                    <strong><?= __("Nessuna testata registrata.") ?></strong>
                    <?= __("Crea la prima testata per iniziare a catalogare riviste e periodici.") ?>
                </p>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <!--
            Duplicate merge, same UX as the core author/publisher merge: tick
            the duplicates in the list, review the preview, confirm there.
            The checkboxes belong to this form through the HTML `form`
            attribute, because the rows already contain the delete POST form
            and nesting forms is invalid markup.
        -->
        <form id="emt-merge-select" method="GET" action="<?= $e(url('/admin/periodicals/merge')) ?>"
              class="emt-toolbar p-4 mb-4 flex flex-wrap items-center gap-3">
            <span class="text-xs text-gray-600">
                <?= __("Seleziona due testate duplicate per unirle in una sola.") ?>
            </span>
            <button type="submit" class="btn-secondary text-sm">
                <i class="fas fa-code-merge mr-1" aria-hidden="true"></i>
                <?= __("Unisci le testate selezionate") ?>
            </button>
        </form>

        <div class="emt-surface overflow-hidden">
            <div class="overflow-x-auto">
            <table class="emt-periodicals-table min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <span class="sr-only"><?= __("Seleziona") ?></span>
                        </th>
                        <th class="emt-col-title px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Testata") ?></th>
                        <th class="emt-col-issn px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("ISSN") ?></th>
                        <th class="emt-col-publisher px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Editore") ?></th>
                        <th class="emt-col-frequency px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Periodicità") ?></th>
                        <th class="emt-col-type px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Tipo") ?></th>
                        <th class="emt-col-issues px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Fascicoli") ?></th>
                        <th class="emt-col-holdings px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Consistenza") ?></th>
                        <th class="emt-col-actions px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Azioni") ?></th>
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
                        $nScadenza = (int) ($row['n_abbonamenti_scadenza'] ?? 0);
                        ?>
                        <tr class="hover:bg-gray-50 border-b">
                            <td class="px-4 py-2">
                                <input type="checkbox" name="ids[]" value="<?= $rowId ?>"
                                       form="emt-merge-select" class="form-checkbox"
                                       aria-label="<?= $e(sprintf(__('Seleziona «%s» per l\'unione'), (string) $row['titolo'])) ?>">
                            </td>
                            <td class="emt-col-title px-4 py-2">
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
                                        <div class="emt-periodical-secondary-meta">
                                            <?php if (!empty($row['editore_nome'])): ?><span><?= $e($row['editore_nome']) ?></span><?php endif; ?>
                                            <?php if (!empty($row['periodicita'])): ?><span><?= $e($periodicitaLabels[(string) $row['periodicita']] ?? (string) $row['periodicita']) ?></span><?php endif; ?>
                                        </div>
                                        <span class="emt-status emt-status--<?= $e($statoVal) ?>">
                                            <i aria-hidden="true"></i><?= $e($statoLabels[$statoVal] ?? $statoVal) ?>
                                        </span>
                                        <?php if ($nScadenza > 0): ?>
                                            <a href="<?= $e(url('/admin/periodicals/' . $rowId . '/subscriptions')) ?>"
                                               class="inline-block px-2 py-0.5 text-xs font-semibold rounded bg-yellow-100 text-yellow-800"
                                               title="<?= $e(__('Abbonamenti in scadenza')) ?>">
                                                <i class="fas fa-file-signature" aria-hidden="true"></i>
                                                <?= sprintf(__('%d in scadenza'), $nScadenza) ?>
                                            </a>
                                        <?php endif; ?>
                                        <div class="emt-periodical-mobile-meta">
                                            <?php if (!empty($row['issn'])): ?><span class="font-mono"><?= $e($row['issn']) ?></span><?php endif; ?>
                                            <?php if (!empty($row['editore_nome'])): ?><span><?= $e($row['editore_nome']) ?></span><?php endif; ?>
                                            <?php if (!empty($row['periodicita'])): ?><span><?= $e($periodicitaLabels[(string) $row['periodicita']] ?? (string) $row['periodicita']) ?></span><?php endif; ?>
                                            <span><?= (int) $row['n_posseduti'] ?> <?= $e(__('Posseduti')) ?> · <?= (int) $row['n_mancanti'] ?> <?= $e(__('Mancanti')) ?></span>
                                            <span><?= $e($row['consistenza'] ?? '—') ?></span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="emt-col-issn px-4 py-2 font-mono text-xs text-gray-500"><?= $e($row['issn'] ?? '') ?></td>
                            <td class="emt-col-publisher px-4 py-2 text-sm text-gray-600"><?= $e($row['editore_nome'] ?? '') ?></td>
                            <td class="emt-col-frequency px-4 py-2 text-sm text-gray-600">
                                <?= $e($periodicitaLabels[(string) ($row['periodicita'] ?? '')] ?? '') ?>
                            </td>
                            <td class="emt-col-type px-4 py-2">
                                <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded bg-blue-100 text-blue-800">
                                    <?= $e($tipoLabels[(string) ($row['tipo'] ?? '')] ?? (string) ($row['tipo'] ?? '')) ?>
                                </span>
                            </td>
                            <td class="emt-col-issues px-4 py-2 text-sm text-gray-600 whitespace-nowrap">
                                <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded bg-green-100 text-green-800"
                                      title="<?= $e(__('Posseduti')) ?>"><?= (int) $row['n_posseduti'] ?></span>
                                <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded bg-red-100 text-red-800"
                                      title="<?= $e(__('Mancanti')) ?>"><?= (int) $row['n_mancanti'] ?></span>
                            </td>
                            <td class="emt-col-holdings px-4 py-2 text-sm text-gray-600 whitespace-nowrap"><?= $e($row['consistenza'] ?? '—') ?></td>
                            <td class="emt-col-actions px-4 py-2 text-right text-sm whitespace-nowrap">
                                <a href="<?= $issuesUrl ?>" class="emt-table-action" title="<?= $e(__('Fascicoli')) ?>" aria-label="<?= $e(__('Fascicoli')) ?>"><i class="fas fa-layer-group" aria-hidden="true"></i><span class="emt-action-label"><?= __('fascicoli') ?></span></a>
                                <a href="<?= $e(url('/admin/periodicals/' . $rowId . '/subscriptions')) ?>" class="emt-table-action" title="<?= $e(__('Abbonamenti')) ?>" aria-label="<?= $e(__('Abbonamenti')) ?>"><i class="fas fa-file-signature" aria-hidden="true"></i><span class="emt-action-label"><?= __('abbonamenti') ?></span></a>
                                <a href="<?= $editUrl ?>" class="emt-table-action" title="<?= $e(__('Modifica')) ?>" aria-label="<?= $e(__('Modifica')) ?>"><i class="fas fa-pen" aria-hidden="true"></i><span class="emt-action-label"><?= __('modifica') ?></span></a>
                                <form method="POST" action="<?= $deleteUrl ?>" class="inline"
                                      onsubmit="return confirm(<?= $e(json_encode(__('Eliminare questa testata con tutte le annate, i fascicoli e lo spoglio?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);">
                                    <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
                                    <button type="submit" class="emt-table-action emt-table-action--danger" title="<?= $e(__('Elimina')) ?>" aria-label="<?= $e(__('Elimina')) ?>"><i class="fas fa-trash" aria-hidden="true"></i><span class="emt-action-label"><?= __('elimina') ?></span></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <!-- Pagination (chrome copied verbatim from app/Views/events/index.php) -->
        <?php if ($totalPages > 1): ?>
          <div class="mt-8 flex items-center justify-center gap-2">
            <?php if ($page > 1): ?>
              <a href="<?= $e($pageUrl($page - 1)) ?>"
                class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-semibold">
                <i class="fas fa-chevron-left"></i>
                <?= __("Precedente") ?>
              </a>
            <?php endif; ?>

            <div class="flex items-center gap-1">
              <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <?php if ($i == $page): ?>
                  <span class="px-4 py-2 bg-gray-900 text-white rounded-lg font-semibold">
                    <?= $i ?>
                  </span>
                <?php elseif ($i == 1 || $i == $totalPages || abs($i - $page) <= 2): ?>
                  <a href="<?= $e($pageUrl($i)) ?>"
                    class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-semibold">
                    <?= $i ?>
                  </a>
                <?php elseif (abs($i - $page) == 3): ?>
                  <span class="px-2 text-gray-400">...</span>
                <?php endif; ?>
              <?php endfor; ?>
            </div>

            <?php if ($page < $totalPages): ?>
              <a href="<?= $e($pageUrl($page + 1)) ?>"
                class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-semibold">
                <?= __("Successivo") ?>
                <i class="fas fa-chevron-right"></i>
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <p class="text-xs text-gray-500 mt-3">
            <?= sprintf(__("Testate registrate: %d."), $total) ?>
        </p>
    <?php endif; ?>
</div>

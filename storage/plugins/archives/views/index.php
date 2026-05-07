<?php
/**
 * Archives — index (list) view.
 *
 * @var array<int, array<string, mixed>> $rows
 * @var string|null $q
 * @var string|null $level
 */
declare(strict_types=1);

use App\Support\HtmlHelper;

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$q     = $q     ?? '';
$level = $level ?? '';

// Build a parent_id → children index so we can render a lightweight tree by
// visiting top-level rows first and recursing. A real CTE-backed tree is
// roadmapped for Phase 2.
//
// Edge case: if a row's parent is missing from $rows (parent soft-deleted,
// out of the current page, or a stale parent_id), indexing it under the
// missing parent key would hide it from the tree walk that only visits
// $byParent[0]. Promote such orphans to root so nothing silently disappears
// from the admin view.
$presentIds = [];
foreach ($rows as $row) {
    $presentIds[(int) $row['id']] = true;
}
$byParent = [];
foreach ($rows as $row) {
    $pid = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
    if ($pid !== 0 && !isset($presentIds[$pid])) {
        $pid = 0;
    }
    $byParent[$pid][] = $row;
}

/**
 * @param array<int, array<int, array<string, mixed>>> $byParent
 */
$renderedIds = [];
$renderRow = null;
$renderRow = function (array $row, int $depth, array $visited = []) use (&$renderRow, &$renderedIds, $byParent, $e): string {
    // Cycle guard: if a row's id has already been rendered in this branch,
    // stop recursing. Should never happen on sane data but parent_id is a
    // user-controlled column and a stray import could create loops.
    // $renderedIds is the *global* (cross-tree) map used after the rootRows
    // walk to pick up nodes that belong to an orphan cycle (A→B, B→A) with
    // no path from a real root.
    $rowId = (int) $row['id'];
    if (isset($visited[$rowId])) {
        return '';
    }
    $visited[$rowId] = true;
    $renderedIds[$rowId] = true;
    $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth);
    $levelBadge = [
        'fonds'  => 'bg-purple-100 text-purple-800',
        'series' => 'bg-blue-100 text-blue-800',
        'file'   => 'bg-green-100 text-green-800',
        'item'   => 'bg-gray-100 text-gray-800',
    ];
    $levelLabel = [
        'fonds'  => __('Fondo'),
        'series' => __('Serie'),
        'file'   => __('Fascicolo'),
        'item'   => __('Unità'),
    ];
    $badgeClass  = $levelBadge[$row['level']] ?? 'bg-gray-100 text-gray-800';
    $levelText   = $levelLabel[$row['level']] ?? $e((string) $row['level']);
    $dateRange = '';
    if ($row['date_start'] !== null) {
        $dateRange = (string) $row['date_start'];
        if ($row['date_end'] !== null && $row['date_end'] !== $row['date_start']) {
            $dateRange .= '–' . (string) $row['date_end'];
        }
    }
    $viewUrl = $e(url('/admin/archives/' . $rowId));
    $editUrl = $e(url('/admin/archives/' . $rowId . '/edit'));
    $html  = '<tr class="hover:bg-gray-50 border-b">';
    $html .= '<td class="px-4 py-2 font-mono text-xs text-gray-500">';
    $html .= '<a href="' . $viewUrl . '" class="text-blue-600 hover:underline">' . $e((string) $row['reference_code']) . '</a>';
    $html .= '</td>';
    $html .= '<td class="px-4 py-2">';
    $html .= '<span class="inline-block px-2 py-0.5 text-xs font-semibold rounded ' . $badgeClass . '">' . $e($levelText) . '</span>';
    $html .= '</td>';
    $html .= '<td class="px-4 py-2">' . $indent;
    $html .= '<a href="' . $viewUrl . '" class="text-gray-900 hover:underline">' . $e((string) $row['constructed_title']) . '</a>';
    $html .= '</td>';
    $html .= '<td class="px-4 py-2 text-sm text-gray-600">' . $e($dateRange) . '</td>';
    $html .= '<td class="px-4 py-2 text-sm text-gray-600">' . $e((string) ($row['extent'] ?? '')) . '</td>';
    $html .= '<td class="px-4 py-2 text-right text-sm whitespace-nowrap">';
    $html .= '<a href="' . $editUrl . '" class="text-blue-600 hover:underline">' . __('modifica') . '</a>';
    $html .= '</td>';
    $html .= '</tr>';

    // Recurse into children, if any.
    $children = $byParent[$rowId] ?? [];
    foreach ($children as $child) {
        $html .= $renderRow($child, $depth + 1, $visited);
    }
    return $html;
};

// Root-level rows = parent_id IS NULL → indexed under 0.
$rootRows = $byParent[0] ?? [];
?>
<div class="p-6 max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900"><?= __("Archivi") ?></h1>
            <p class="text-sm text-gray-600 mt-1">
                <?= __("Gestione materiale archivistico secondo standard ISAD(G) / ISAAR(CPF).") ?>
                <a href="https://github.com/fabiodalez-dev/Pinakes/issues/103"
                   class="text-blue-600 hover:underline" target="_blank" rel="noopener">
                    Issue #103
                </a>
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="<?= $e(url('/admin/archives/search')) ?>"
               class="btn-secondary inline-flex items-center">
                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <?= __("Ricerca") ?>
            </a>
            <a href="<?= $e(url('/admin/archives/authorities')) ?>"
               class="btn-secondary inline-flex items-center">
                <?= __("Authority records") ?>
            </a>
            <a href="<?= $e(url('/admin/archives/import')) ?>"
               class="btn-secondary inline-flex items-center">
                <?= __("Importa MARCXML") ?>
            </a>
            <a href="<?= $e(url('/admin/archives/export.xml')) ?>"
               class="btn-secondary inline-flex items-center">
                <?= __("Esporta MARCXML") ?>
            </a>
            <a href="<?= $e(url('/admin/archives/export.ead3')) ?>"
               class="btn-secondary inline-flex items-center">
                <?= __("Esporta EAD3") ?>
            </a>
            <a href="<?= $e(url('/archives/oai?verb=Identify')) ?>"
               class="btn-secondary inline-flex items-center" target="_blank" rel="noopener">
                <?= __("OAI-PMH") ?>
            </a>
            <a href="<?= $e(url('/admin/archives/new')) ?>"
               class="btn-primary inline-flex items-center">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                <?= __("Nuovo record archivistico") ?>
            </a>
        </div>
    </div>

    <form method="GET" action="<?= $e(url('/admin/archives')) ?>"
          class="bg-white shadow rounded-lg p-4 mb-6 flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[200px]">
            <label for="arc-q" class="block text-xs font-medium text-gray-600 mb-1">
                <?= __("Ricerca (titolo, reference code, descrizione)") ?>
            </label>
            <input id="arc-q" type="search" name="q" value="<?= $e($q) ?>"
                   placeholder="<?= $e(__("es. IT-MI-001 o Fondo Rossi")) ?>"
                   class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500">
        </div>
        <div class="min-w-[150px]">
            <label for="arc-level" class="block text-xs font-medium text-gray-600 mb-1">
                <?= __("Livello") ?>
            </label>
            <select id="arc-level" name="level"
                    class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500">
                <option value=""><?= __("Tutti i livelli") ?></option>
                <option value="fonds"  <?= $level === 'fonds'  ? 'selected' : '' ?>><?= __("Fondo")      ?></option>
                <option value="series" <?= $level === 'series' ? 'selected' : '' ?>><?= __("Serie")      ?></option>
                <option value="file"   <?= $level === 'file'   ? 'selected' : '' ?>><?= __("Fascicolo")  ?></option>
                <option value="item"   <?= $level === 'item'   ? 'selected' : '' ?>><?= __("Unità")      ?></option>
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary">
                <svg class="w-4 h-4 inline -mt-0.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <?= __("Cerca") ?>
            </button>
            <?php if ($q !== '' || $level !== ''): ?>
                <a href="<?= $e(url('/admin/archives')) ?>" class="btn-secondary">
                    <?= __("Azzera") ?>
                </a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (($q !== '' || $level !== '') && !empty($rows)): ?>
        <p class="text-sm text-gray-600 mb-3">
            <?= sprintf(__("%d risultati"), count($rows)) ?>
            <?php if ($q !== ''): ?>
                <?= __("per") ?> <strong><?= $e($q) ?></strong>
            <?php endif; ?>
            <?php if ($level !== ''): ?>
                · <?= __("livello") ?>: <strong><?= $e($level) ?></strong>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php
    $isFiltered = $q !== '' || $level !== '';
    if (empty($rows)): ?>
        <?php if ($isFiltered): ?>
            <div class="bg-gray-50 border border-gray-200 p-6 rounded text-center">
                <p class="text-sm text-gray-700">
                    <?= __("Nessun risultato") ?>
                    <?php if ($q !== ''): ?> <?= __("per") ?> <strong><?= $e($q) ?></strong><?php endif; ?>
                    <?php if ($level !== ''): ?> · <?= __("livello") ?>: <strong><?= $e($level) ?></strong><?php endif; ?>.
                </p>
            </div>
        <?php else: ?>
            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
                <p class="text-sm text-yellow-800">
                    <strong><?= __("Nessun record archivistico.") ?></strong>
                    <?= __("Crea il primo fondo (fonds) per iniziare a strutturare l'archivio.") ?>
                </p>
                <p class="text-xs text-yellow-700 mt-2">
                    <?= __("Gerarchia consigliata: Fondo → Serie → Fascicolo → Unità (ISAD(G) 3.1.4).") ?>
                </p>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Reference") ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Livello") ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Titolo") ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Date") ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Estensione") ?></th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Azioni") ?></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if ($isFiltered): ?>
                        <?php foreach ($rows as $row): ?>
                            <?= $renderRow($row, 0) ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php
                        foreach ($rootRows as $row) {
                            echo $renderRow($row, 0);
                        }
                        // Second pass: orphan cycles (A↔B) with no path from a real root.
                        foreach ($rows as $row) {
                            if (!isset($renderedIds[(int) $row['id']])) {
                                echo $renderRow($row, 0);
                            }
                        }
                        ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-500 mt-3">
            <?= sprintf(__("Mostrati %d record su un massimo di %d per pagina."), count($rows), $isFiltered ? 500 : 500) ?>
        </p>
    <?php endif; ?>
</div>

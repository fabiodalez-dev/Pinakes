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

// Translated labels for ISAD(G) levels. Used both by the inner
// renderRow closure (per-row badge) and the outer "filtered results"
// banner so the raw enum value (`fonds`, `series`, …) never leaks
// untranslated into the UI.
$levelLabel = [
    'fonds'  => __('Fondo'),
    'series' => __('Serie'),
    'file'   => __('Fascicolo'),
    'item'   => __('Unità'),
];

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
/** @var bool $isFiltered */
$isFiltered  = false; // set after closure definition; captured by reference
$renderRow = null;
$renderRow = function (array $row, int $depth, array $visited = []) use (&$renderRow, &$renderedIds, &$isFiltered, $byParent, $e, $levelLabel): string {
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
    $html .= '<td data-label="' . $e(__("Reference")) . '" class="px-4 py-2 font-mono text-xs text-gray-500">';
    $html .= '<a href="' . $viewUrl . '" class="text-blue-600 hover:underline">' . $e((string) $row['reference_code']) . '</a>';
    $html .= '</td>';
    $html .= '<td data-label="' . $e(__("Livello")) . '" class="px-4 py-2">';
    $html .= '<span class="archive-level"><span class="archive-level-dot" aria-hidden="true"></span>' . $e($levelText) . '</span>';
    $html .= '</td>';
    $html .= '<td data-label="' . $e(__("Titolo")) . '" class="px-4 py-2">' . $indent;
    $html .= '<a href="' . $viewUrl . '" class="text-gray-900 hover:underline">' . $e((string) $row['constructed_title']) . '</a>';
    $html .= '</td>';
    $html .= '<td data-label="' . $e(__("Date")) . '" class="px-4 py-2 text-sm text-gray-600">' . $e($dateRange) . '</td>';
    $html .= '<td data-label="' . $e(__("Estensione")) . '" class="px-4 py-2 text-sm text-gray-600">' . $e((string) ($row['extent'] ?? '')) . '</td>';
    $html .= '<td data-label="' . $e(__("Azioni")) . '" class="px-4 py-2 text-right text-sm whitespace-nowrap">';
    $html .= '<a href="' . $editUrl . '" class="text-blue-600 hover:underline">' . __('modifica') . '</a>';
    $html .= '</td>';
    $html .= '</tr>';

    // Recurse into children, if any. In filtered mode the outer loop already
    // iterates over every matching row, so we must not recurse into children
    // here or they would appear indented under a parent AND again at depth=0.
    if (!$isFiltered) {
        $children = $byParent[$rowId] ?? [];
        foreach ($children as $child) {
            $html .= $renderRow($child, $depth + 1, $visited);
        }
    }
    return $html;
};

// Root-level rows = parent_id IS NULL → indexed under 0.
$rootRows = $byParent[0] ?? [];
?>
<link rel="stylesheet" href="<?= htmlspecialchars(url('/plugins/archives/assets/css/archives-admin.css?v=1.5.0'), ENT_QUOTES, 'UTF-8') ?>">
<div class="archive-admin-page p-6 max-w-7xl mx-auto">
    <header class="archive-page-header mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900"><?= __("Archivi") ?></h1>
            <p class="mt-1 text-sm text-gray-600"><?= __("Gestione materiale archivistico secondo standard ISAD(G) / ISAAR(CPF).") ?></p>
        </div>
        <div class="archive-header-actions">
            <a href="<?= $e(url('/admin/archives/search')) ?>" class="btn-secondary"><i class="fas fa-search mr-2"></i><?= __("Ricerca") ?></a>
            <a href="<?= $e(url('/admin/archives/authorities')) ?>" class="btn-secondary"><?= __("Authority records") ?></a>
            <details class="relative" id="arc-actions-details">
                <summary class="btn-secondary inline-flex items-center text-sm cursor-pointer select-none list-none">
                    <?= __("Importa / esporta") ?>
                    <svg class="w-3 h-3 ml-1.5 transition-transform arc-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </summary>
                <div class="absolute left-0 top-full mt-1 z-30 bg-white border border-gray-200 rounded-lg shadow-lg py-1 min-w-[200px]">
                    <a href="<?= $e(url('/admin/archives/search')) ?>"
                       class="flex items-center px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                        <svg class="w-4 h-4 mr-2.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <?= __("Ricerca") ?>
                    </a>
                    <a href="<?= $e(url('/admin/archives/authorities')) ?>"
                       class="flex items-center px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                        <svg class="w-4 h-4 mr-2.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        <?= __("Authority records") ?>
                    </a>
                    <hr class="my-1 border-gray-100">
                    <a href="<?= $e(url('/admin/archives/import')) ?>"
                       class="flex items-center px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                        <svg class="w-4 h-4 mr-2.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                        </svg>
                        <?= __("Importa MARCXML") ?>
                    </a>
                    <a href="<?= $e(url('/admin/archives/export.xml')) ?>"
                       class="flex items-center px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                        <svg class="w-4 h-4 mr-2.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        <?= __("Esporta MARCXML") ?>
                    </a>
                    <a href="<?= $e(url('/admin/archives/export.ead3')) ?>"
                       class="flex items-center px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                        <svg class="w-4 h-4 mr-2.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        <?= __("Esporta EAD3") ?>
                    </a>
                    <hr class="my-1 border-gray-100">
                    <a href="<?= $e(url('/archives/oai?verb=Identify')) ?>"
                       class="flex items-center px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50"
                       target="_blank" rel="noopener">
                        <svg class="w-4 h-4 mr-2.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                        <?= __("OAI-PMH") ?>
                    </a>
                </div>
            </details>
            <a href="<?= $e(url('/admin/archives/new')) ?>" class="btn-primary inline-flex items-center text-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                <?= __("Nuovo record archivistico") ?>
            </a>
        </div>
    </header>

    <form method="GET" action="<?= $e(url('/admin/archives')) ?>"
          class="card archive-filter-form mb-6">
        <div>
            <label for="arc-q" class="form-label">
                <?= __("Ricerca (titolo, reference code, descrizione)") ?>
            </label>
            <input id="arc-q" type="search" name="q" value="<?= $e($q) ?>"
                   placeholder="<?= $e(__("es. IT-MI-001 o Fondo Rossi")) ?>"
                   class="form-input">
        </div>
        <div>
            <label for="arc-level" class="form-label">
                <?= __("Livello") ?>
            </label>
            <select id="arc-level" name="level"
                    class="form-input">
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
            <?= __n("%d risultato", "%d risultati", count($rows)) ?>
            <?php if ($q !== ''): ?>
                <?= __("per") ?> <strong><?= $e($q) ?></strong>
            <?php endif; ?>
            <?php if ($level !== ''): ?>
                · <?= __("livello") ?>: <strong><?= $e($levelLabel[$level] ?? $level) ?></strong>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php
    $isFiltered = $q !== '' || $level !== '';
    if (empty($rows)): ?>
        <?php if ($isFiltered): ?>
            <div class="card text-center">
                <p class="text-sm text-gray-700">
                    <?= __("Nessun risultato") ?>
                    <?php if ($q !== ''): ?> <?= __("per") ?> <strong><?= $e($q) ?></strong><?php endif; ?>
                    <?php if ($level !== ''): ?> · <?= __("livello") ?>: <strong><?= $e($levelLabel[$level] ?? $level) ?></strong><?php endif; ?>.
                </p>
            </div>
        <?php else: ?>
            <div class="card text-center py-10">
                <i class="fas fa-box-archive mb-3 text-3xl text-gray-300"></i>
                <p class="text-sm text-gray-700">
                    <strong><?= __("Nessun record archivistico.") ?></strong>
                    <?= __("Crea il primo fondo (fonds) per iniziare a strutturare l'archivio.") ?>
                </p>
                <p class="text-xs text-gray-500 mt-2">
                    <?= __("Gerarchia consigliata: Fondo → Serie → Fascicolo → Unità (ISAD(G) 3.1.4).") ?>
                </p>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="card archive-table-card">
            <table class="archive-records-table min-w-full divide-y divide-gray-200">
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
                            <?php if (!isset($renderedIds[(int) $row['id']])): ?>
                                <?= $renderRow($row, 0) ?>
                            <?php endif; ?>
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
            <?= sprintf(__("Mostrati %d record su un massimo di %d per pagina."), count($rows), 500) ?>
        </p>
    <?php endif; ?>
</div>

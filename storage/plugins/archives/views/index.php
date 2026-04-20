<?php
/**
 * Archives — index (list) view.
 *
 * @var array<int, array<string, mixed>> $rows
 */
declare(strict_types=1);

use App\Support\HtmlHelper;

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// Build a parent_id → children index so we can render a lightweight tree by
// visiting top-level rows first and recursing. A real CTE-backed tree is
// roadmapped for Phase 2.
$byParent = [];
foreach ($rows as $row) {
    $pid = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
    $byParent[$pid][] = $row;
}

/**
 * @param array<int, array<int, array<string, mixed>>> $byParent
 */
$renderRow = null;
$renderRow = function (array $row, int $depth) use (&$renderRow, $byParent, $e): string {
    $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth);
    $levelBadge = [
        'fonds'  => 'bg-purple-100 text-purple-800',
        'series' => 'bg-blue-100 text-blue-800',
        'file'   => 'bg-green-100 text-green-800',
        'item'   => 'bg-gray-100 text-gray-800',
    ];
    $badgeClass = $levelBadge[$row['level']] ?? 'bg-gray-100 text-gray-800';
    $dateRange = '';
    if ($row['date_start'] !== null) {
        $dateRange = (string) $row['date_start'];
        if ($row['date_end'] !== null && $row['date_end'] !== $row['date_start']) {
            $dateRange .= '–' . (string) $row['date_end'];
        }
    }
    $rowId = (int) $row['id'];
    $viewUrl = $e(url('/admin/archives/' . $rowId));
    $editUrl = $e(url('/admin/archives/' . $rowId . '/edit'));
    $html  = '<tr class="hover:bg-gray-50 border-b">';
    $html .= '<td class="px-4 py-2 font-mono text-xs text-gray-500">';
    $html .= '<a href="' . $viewUrl . '" class="text-blue-600 hover:underline">' . $e((string) $row['reference_code']) . '</a>';
    $html .= '</td>';
    $html .= '<td class="px-4 py-2">';
    $html .= '<span class="inline-block px-2 py-0.5 text-xs font-semibold rounded ' . $badgeClass . '">' . $e((string) $row['level']) . '</span>';
    $html .= '</td>';
    $html .= '<td class="px-4 py-2">' . $indent;
    $html .= '<a href="' . $viewUrl . '" class="text-gray-900 hover:underline">' . $e((string) $row['constructed_title']) . '</a>';
    $html .= '</td>';
    $html .= '<td class="px-4 py-2 text-sm text-gray-600">' . $e($dateRange) . '</td>';
    $html .= '<td class="px-4 py-2 text-sm text-gray-600">' . $e((string) ($row['extent'] ?? '')) . '</td>';
    $html .= '<td class="px-4 py-2 text-right text-sm whitespace-nowrap">';
    $html .= '<a href="' . $editUrl . '" class="text-blue-600 hover:underline">modifica</a>';
    $html .= '</td>';
    $html .= '</tr>';

    // Recurse into children, if any.
    $children = $byParent[(int) $row['id']] ?? [];
    foreach ($children as $child) {
        $html .= $renderRow($child, $depth + 1);
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
        <a href="<?= $e(url('/admin/archives/new')) ?>"
           class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 transition">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 4v16m8-8H4"/>
            </svg>
            <?= __("Nuovo record archivistico") ?>
        </a>
    </div>

    <?php if (empty($rows)): ?>
        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
            <p class="text-sm text-yellow-800">
                <strong><?= __("Nessun record archivistico.") ?></strong>
                <?= __("Crea il primo fondo (fonds) per iniziare a strutturare l'archivio.") ?>
            </p>
            <p class="text-xs text-yellow-700 mt-2">
                <?= __("Gerarchia consigliata: Fondo → Serie → Fascicolo → Unità (ISAD(G) 3.1.4).") ?>
            </p>
        </div>
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
                    <?php
                    foreach ($rootRows as $row) {
                        echo $renderRow($row, 0);
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-500 mt-3">
            Mostrati <?= count($rows) ?> record. Limite pagina: 500 (paginazione in Phase 1c).
        </p>
    <?php endif; ?>
</div>

<?php
/**
 * Authorities — index (list) view.
 *
 * @var array<int, array<string, mixed>> $rows
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$typeBadge = [
    'person'    => 'bg-indigo-100 text-indigo-800',
    'corporate' => 'bg-amber-100 text-amber-800',
    'family'    => 'bg-pink-100 text-pink-800',
];
// Localised badge labels — reuses the keys already shipped by the
// authority form so admins see Italian/English/German labels instead
// of raw DB enum values.
$typeLabel = [
    'person'    => __('Persona (biografica)'),
    'corporate' => __('Ente (organizzazione, sindacato, partito)'),
    'family'    => __('Famiglia (genealogica)'),
];
?>
<div class="p-6 max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <nav class="text-sm text-gray-500 mb-1">
                <a href="<?= $e(url('/admin/archives')) ?>" class="hover:underline"><?= __("Archivi") ?></a>
                &nbsp;&raquo;&nbsp; <?= __("Authority records") ?>
            </nav>
            <h1 class="text-2xl font-bold text-gray-900"><?= __("Authority records") ?></h1>
            <p class="text-sm text-gray-600 mt-1">
                <?= __("Record di autorità (persone, enti, famiglie) secondo lo standard ISAAR(CPF).") ?>
            </p>
        </div>
        <a href="<?= $e(url('/admin/archives/authorities/new')) ?>"
           class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 transition">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            <?= __("Nuovo authority record") ?>
        </a>
    </div>

    <?php if (empty($rows)): ?>
        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
            <p class="text-sm text-yellow-800">
                <strong><?= __("Nessun authority record.") ?></strong>
                <?= __("Crea il primo authority record per associarlo poi a un archivio.") ?>
            </p>
        </div>
    <?php else: ?>
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Tipo") ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Forma autorizzata") ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Datazione") ?></th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Azioni") ?></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $type = (string) $row['type'];
                        $badge = $typeBadge[$type] ?? 'bg-gray-100 text-gray-800';
                        $label = $typeLabel[$type] ?? $type;
                        $rowId = (int) $row['id'];
                        $viewUrl = $e(url('/admin/archives/authorities/' . $rowId));
                        $editUrl = $e(url('/admin/archives/authorities/' . $rowId . '/edit'));
                        ?>
                        <tr class="hover:bg-gray-50 border-b">
                            <td class="px-4 py-2">
                                <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded <?= $badge ?>">
                                    <?= $e($label) ?>
                                </span>
                            </td>
                            <td class="px-4 py-2">
                                <a href="<?= $viewUrl ?>" class="text-blue-600 hover:underline">
                                    <?= $e((string) $row['authorised_form']) ?>
                                </a>
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-600">
                                <?= $e((string) ($row['dates_of_existence'] ?? '')) ?>
                            </td>
                            <td class="px-4 py-2 text-right text-sm whitespace-nowrap">
                                <a href="<?= $editUrl ?>" class="text-blue-600 hover:underline"><?= __("modifica") ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-500 mt-3">
            <?= __("Mostrati record:") ?> <?= count($rows) ?>. <?= __("Limite pagina: 500.") ?>
        </p>
    <?php endif; ?>
</div>

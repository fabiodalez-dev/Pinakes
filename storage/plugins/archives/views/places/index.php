<?php
/**
 * Archives — Luoghi (Phase 5 / v0.8.0) — index (list) view.
 *
 * @var list<array<string, mixed>>|null $rows
 */
declare(strict_types=1);

$e    = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$rows = $rows ?? [];

$typeLabel = [
    'country'            => __('Paese'),
    'region'             => __('Regione'),
    'province'           => __('Provincia'),
    'municipality'       => __('Comune'),
    'locality'           => __('Località'),
    'building'           => __('Edificio'),
    'room'               => __('Locale'),
    'geographic_feature' => __('Caratteristica geografica'),
    'other'              => __('Altro'),
];
?>
<div class="p-6 max-w-6xl mx-auto">
    <div class="mb-6">
        <nav class="text-sm text-gray-500 mb-2">
            <a href="<?= $e(url('/admin/archives')) ?>" class="hover:underline"><?= __('Archivi') ?></a>
            &nbsp;&raquo;&nbsp; <?= __('Luoghi') ?>
        </nav>
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900"><?= __('Luoghi (RiC-CM)') ?></h1>
                <p class="text-sm text-gray-600 mt-1">
                    <?= __('Entità geografiche (paesi, regioni, comuni, edifici) collegabili a unità archivistiche, agenti e attività. Supporto identificativi GeoNames, Wikidata e Getty TGN per Linked Data.') ?>
                </p>
            </div>
            <a href="<?= $e(url('/admin/archives/places/new')) ?>"
               class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 px-4 rounded shadow-sm">
                <?= __('+ Nuovo luogo') ?>
            </a>
        </div>
    </div>

    <?php if (empty($rows)): ?>
        <div class="bg-white shadow rounded-lg p-6">
            <p class="text-sm text-gray-500"><?= __('Nessun luogo registrato.') ?></p>
        </div>
    <?php else: ?>
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <table class="min-w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Nome') ?></th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Tipo') ?></th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __('Coordinate') ?></th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                            <span class="sr-only"><?= __('Azioni') ?></span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $rid  = (int) ($r['id'] ?? 0);
                    $name = (string) ($r['name'] ?? '');
                    $type = (string) ($r['place_type'] ?? '');
                    $lat  = $r['latitude']  ?? null;
                    $lng  = $r['longitude'] ?? null;
                ?>
                    <tr class="hover:bg-gray-50 border-b">
                        <td class="px-4 py-2 font-mono text-xs text-gray-500">#<?= $rid ?></td>
                        <td class="px-4 py-2">
                            <a class="text-blue-600 hover:underline"
                               href="<?= $e(url('/admin/archives/places/' . $rid)) ?>"><?= $e($name) ?></a>
                        </td>
                        <td class="px-4 py-2">
                            <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded bg-gray-100 text-gray-700">
                                <?= $e($typeLabel[$type] ?? $type) ?>
                            </span>
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-600">
                            <?php if ($lat !== null && $lng !== null): ?>
                                <?= $e(number_format((float) $lat, 4)) ?>, <?= $e(number_format((float) $lng, 4)) ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <a href="<?= $e(url('/admin/archives/places/' . $rid . '/edit')) ?>"
                               class="text-blue-600 hover:underline text-sm"><?= __('modifica') ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

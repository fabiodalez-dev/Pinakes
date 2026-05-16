<?php
/**
 * Archives — Luoghi (Phase 5 / v0.8.0) — detail view.
 *
 * @var array<string, mixed>|null $row
 */
declare(strict_types=1);

$e   = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$row = $row ?? [];
$id  = (int) ($row['id'] ?? 0);

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
$type = (string) ($row['place_type'] ?? '');
?>
<div class="p-6 max-w-4xl mx-auto">
    <div class="mb-6">
        <nav class="text-sm text-gray-500 mb-2">
            <a href="<?= $e(url('/admin/archives')) ?>" class="hover:underline"><?= __('Archivi') ?></a>
            &nbsp;&raquo;&nbsp;
            <a href="<?= $e(url('/admin/archives/places')) ?>" class="hover:underline"><?= __('Luoghi') ?></a>
            &nbsp;&raquo;&nbsp; #<?= $id ?>
        </nav>
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900"><?= $e((string) ($row['name'] ?? '')) ?></h1>
                <p class="text-sm text-gray-600 mt-1">
                    <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded bg-gray-100 text-gray-700">
                        <?= $e($typeLabel[$type] ?? $type) ?>
                    </span>
                </p>
            </div>
            <div class="space-x-2">
                <a href="<?= $e(url('/admin/archives/places/' . $id . '/edit')) ?>"
                   class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded text-sm font-semibold shadow-sm">
                    <?= __('Modifica') ?>
                </a>
                <form method="POST" action="<?= $e(url('/admin/archives/places/' . $id . '/delete')) ?>" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
                    <button type="submit"
                            onclick="return confirm('<?= $e(__('Eliminare questo luogo?')) ?>')"
                            class="bg-red-50 hover:bg-red-100 text-red-700 px-4 py-2 rounded text-sm font-semibold">
                        <?= __('Elimina') ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="bg-white shadow rounded-lg p-6 space-y-4">
        <?php if (!empty($row['description'])): ?>
            <div>
                <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2"><?= __('Descrizione') ?></h2>
                <div class="text-sm text-gray-800"><?= nl2br($e((string) $row['description'])) ?></div>
            </div>
        <?php endif; ?>

        <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <?php if (!empty($row['latitude']) && !empty($row['longitude'])): ?>
                <dt class="font-semibold text-gray-700"><?= __('Coordinate') ?></dt>
                <dd class="text-gray-800">
                    <?= $e((string) $row['latitude']) ?>, <?= $e((string) $row['longitude']) ?>
                </dd>
            <?php endif; ?>
            <?php if (!empty($row['geonames_id'])): ?>
                <dt class="font-semibold text-gray-700"><?= __('Identificativo GeoNames') ?></dt>
                <dd class="text-gray-800"><code class="text-xs"><?= $e((string) $row['geonames_id']) ?></code></dd>
            <?php endif; ?>
            <?php if (!empty($row['wikidata_id'])): ?>
                <dt class="font-semibold text-gray-700"><?= __('Identificativo Wikidata') ?></dt>
                <dd class="text-gray-800"><code class="text-xs"><?= $e((string) $row['wikidata_id']) ?></code></dd>
            <?php endif; ?>
            <?php if (!empty($row['tgn_id'])): ?>
                <dt class="font-semibold text-gray-700"><?= __('Identificativo Getty TGN') ?></dt>
                <dd class="text-gray-800"><code class="text-xs"><?= $e((string) $row['tgn_id']) ?></code></dd>
            <?php endif; ?>
            <?php if (!empty($row['parent_id'])): ?>
                <dt class="font-semibold text-gray-700"><?= __('Luogo padre') ?></dt>
                <dd>
                    <a class="text-blue-600 hover:underline"
                       href="<?= $e(url('/admin/archives/places/' . (int) $row['parent_id'])) ?>">#<?= (int) $row['parent_id'] ?></a>
                </dd>
            <?php endif; ?>
            <?php if (!empty($row['date_start']) || !empty($row['date_end'])): ?>
                <dt class="font-semibold text-gray-700"><?= __('Intervallo storico') ?></dt>
                <dd class="text-gray-800">
                    <?= $e((string) ($row['date_start'] ?? '')) ?>
                    <?php if (!empty($row['date_end'])): ?>–<?= $e((string) $row['date_end']) ?><?php endif; ?>
                </dd>
            <?php endif; ?>
        </dl>
    </div>

    <p class="mt-4 text-xs text-gray-500">
        <?= __('Linked Data:') ?>
        <a class="text-blue-600 hover:underline"
           href="<?= $e(url('/archives/places/' . $id . '/ric.json')) ?>">
            /archives/places/<?= $id ?>/ric.json
        </a>
    </p>
</div>

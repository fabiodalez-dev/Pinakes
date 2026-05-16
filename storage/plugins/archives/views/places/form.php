<?php
/**
 * Archives — Luoghi (Phase 5 / v0.8.0) — create/edit form view.
 *
 * @var string|null           $mode      'create' (default) or 'edit'
 * @var int|null              $id
 * @var list<string>          $types
 * @var list<array{id:int,name:string,type:string}> $parentOpts
 * @var array<string, mixed>  $values
 * @var array<string, string> $errors
 */
declare(strict_types=1);

$e          = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$val        = static fn (string $k): string => $e((string) ($values[$k] ?? ''));
$err        = static fn (string $k): ?string => $errors[$k] ?? null;
$mode       = ($mode ?? 'create') === 'edit' ? 'edit' : 'create';
$editId     = $mode === 'edit' ? (int) ($id ?? 0) : null;
$formAction = $mode === 'edit'
    ? url('/admin/archives/places/' . (int) $editId . '/edit')
    : url('/admin/archives/places/new');
$pageTitle   = $mode === 'edit' ? __('Modifica luogo') : __('Nuovo luogo');
$submitLabel = $mode === 'edit' ? __('Salva modifiche') : __('Crea luogo');

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
<div class="p-6 max-w-4xl mx-auto">
    <div class="mb-6">
        <nav class="text-sm text-gray-500 mb-2">
            <a href="<?= $e(url('/admin/archives')) ?>" class="hover:underline"><?= __('Archivi') ?></a>
            &nbsp;&raquo;&nbsp;
            <a href="<?= $e(url('/admin/archives/places')) ?>" class="hover:underline"><?= __('Luoghi') ?></a>
            &nbsp;&raquo;&nbsp;
            <?= $mode === 'edit' ? __('Modifica luogo') . ' #' . $e((string) $editId) : __('Nuovo luogo') ?>
        </nav>
        <h1 class="text-2xl font-bold text-gray-900"><?= $e($pageTitle) ?></h1>
        <p class="text-sm text-gray-600 mt-1">
            <?= __('Inserisci un luogo geografico con eventuali coordinate e identificativi GeoNames / Wikidata / Getty TGN per il collegamento Linked Data.') ?>
        </p>
    </div>

    <?php if (!empty($errors['_global'])): ?>
        <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-4 rounded">
            <p class="text-sm text-red-800"><strong><?= __('Errore:') ?></strong> <?= $e($errors['_global']) ?></p>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= $e($formAction) ?>" class="bg-white shadow rounded-lg p-6 space-y-5">
        <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">

        <div>
            <label for="name" class="form-label">
                <?= __('Nome') ?> <span class="text-red-500">*</span>
            </label>
            <input type="text" id="name" name="name" required maxlength="500"
                   value="<?= $val('name') ?>"
                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            <?php if ($err('name')): ?>
                <p class="mt-1 text-sm text-red-600"><?= $e($err('name')) ?></p>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label for="place_type" class="form-label"><?= __('Tipo di luogo') ?></label>
                <select id="place_type" name="place_type"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <?php foreach ($types as $t): ?>
                        <option value="<?= $e($t) ?>" <?= ((string) ($values['place_type'] ?? '') === $t) ? 'selected' : '' ?>>
                            <?= $e($typeLabel[$t] ?? $t) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($err('place_type')): ?>
                    <p class="mt-1 text-sm text-red-600"><?= $e($err('place_type')) ?></p>
                <?php endif; ?>
            </div>
            <div>
                <label for="parent_id" class="form-label"><?= __('Luogo padre') ?></label>
                <select id="parent_id" name="parent_id"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">— <?= __('nessuno') ?> —</option>
                    <?php foreach ($parentOpts as $opt): ?>
                        <option value="<?= (int) $opt['id'] ?>" <?= ((int) ($values['parent_id'] ?? 0) === (int) $opt['id']) ? 'selected' : '' ?>>
                            <?= $e((string) $opt['name']) ?> (<?= $e($typeLabel[$opt['type']] ?? (string) $opt['type']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($err('parent_id')): ?>
                    <p class="mt-1 text-sm text-red-600"><?= $e($err('parent_id')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label for="latitude" class="form-label"><?= __('Latitudine') ?></label>
                <input type="text" id="latitude" name="latitude" value="<?= $val('latitude') ?>"
                       placeholder="37.50213"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <?php if ($err('latitude')): ?>
                    <p class="mt-1 text-sm text-red-600"><?= $e($err('latitude')) ?></p>
                <?php endif; ?>
            </div>
            <div>
                <label for="longitude" class="form-label"><?= __('Longitudine') ?></label>
                <input type="text" id="longitude" name="longitude" value="<?= $val('longitude') ?>"
                       placeholder="15.08719"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <?php if ($err('longitude')): ?>
                    <p class="mt-1 text-sm text-red-600"><?= $e($err('longitude')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label for="geonames_id" class="form-label"><?= __('Identificativo GeoNames') ?></label>
                <input type="text" id="geonames_id" name="geonames_id" value="<?= $val('geonames_id') ?>"
                       placeholder="2525068"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <div>
                <label for="wikidata_id" class="form-label"><?= __('Identificativo Wikidata') ?></label>
                <input type="text" id="wikidata_id" name="wikidata_id" value="<?= $val('wikidata_id') ?>"
                       placeholder="Q40218"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <div>
                <label for="tgn_id" class="form-label"><?= __('Identificativo Getty TGN') ?></label>
                <input type="text" id="tgn_id" name="tgn_id" value="<?= $val('tgn_id') ?>"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
        </div>

        <div>
            <label for="description" class="form-label"><?= __('Descrizione') ?></label>
            <textarea id="description" name="description" rows="3"
                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"><?= $val('description') ?></textarea>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label for="date_start" class="form-label"><?= __('Esistente dal') ?></label>
                <input type="text" id="date_start" name="date_start" value="<?= $val('date_start') ?>"
                       placeholder="AAAA"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <div>
                <label for="date_end" class="form-label"><?= __('Esistente fino al') ?></label>
                <input type="text" id="date_end" name="date_end" value="<?= $val('date_end') ?>"
                       placeholder="AAAA"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
        </div>

        <div class="flex items-center justify-between pt-4 border-t">
            <a href="<?= $e(url('/admin/archives/places')) ?>" class="text-gray-600 hover:underline">
                <?= __('Annulla') ?>
            </a>
            <button type="submit"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 px-4 rounded shadow-sm">
                <?= $e($submitLabel) ?>
            </button>
        </div>
    </form>
</div>

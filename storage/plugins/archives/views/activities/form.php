<?php
/**
 * Archives — Attività (Phase 5 / v0.8.0) — create/edit form view.
 *
 * Mirrors the form chrome of storage/plugins/archives/views/form.php:
 * breadcrumb, p-6 max-w-4xl outer wrapper, `bg-white shadow rounded-lg
 * p-6 space-y-5` form container, `form-label` field labels. All strings
 * are Italian source.
 *
 * @var string|null           $mode      'create' (default) or 'edit'
 * @var int|null              $id
 * @var list<string>          $types
 * @var list<array{id:int,title:string}> $parentOpts
 * @var list<array{id:int,label:string}> $agentOpts
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
    ? url('/admin/archives/activities/' . (int) $editId . '/edit')
    : url('/admin/archives/activities/new');
$pageTitle   = $mode === 'edit' ? __('Modifica attività') : __('Nuova attività');
$submitLabel = $mode === 'edit' ? __('Salva modifiche') : __('Crea attività');

$typeLabel = [
    'function'    => __('Funzione (ISDF — livello superiore)'),
    'activity'    => __('Attività'),
    'transaction' => __('Transazione'),
    'task'        => __('Compito'),
    'mandate'     => __('Mandato'),
];
?>
<div class="p-6 max-w-4xl mx-auto">
    <div class="mb-6">
        <nav class="text-sm text-gray-500 mb-2">
            <a href="<?= $e(url('/admin/archives')) ?>" class="hover:underline"><?= __('Archivi') ?></a>
            &nbsp;&raquo;&nbsp;
            <a href="<?= $e(url('/admin/archives/activities')) ?>" class="hover:underline"><?= __('Attività') ?></a>
            &nbsp;&raquo;&nbsp;
            <?= $mode === 'edit' ? __('Modifica attività') . ' #' . $e((string) $editId) : __('Nuova attività') ?>
        </nav>
        <h1 class="text-2xl font-bold text-gray-900"><?= $e($pageTitle) ?></h1>
        <p class="text-sm text-gray-600 mt-1">
            <?= __("Definisci un'attività ISDF (funzione, attività, transazione, compito o mandato) e collegala a un agente esecutore e/o a un'attività padre.") ?>
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
            <label for="title" class="form-label">
                <?= __('Titolo') ?> <span class="text-red-500">*</span>
            </label>
            <input type="text" id="title" name="title" required maxlength="500"
                   value="<?= $val('title') ?>"
                   class="mt-1 block w-full rounded-md <?= $err('title') ? 'border-red-500' : 'border-gray-300' ?> shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            <?php if ($err('title')): ?>
                <p class="mt-1 text-sm text-red-600"><?= $e($err('title')) ?></p>
            <?php endif; ?>
        </div>

        <div>
            <label for="description" class="form-label"><?= __('Descrizione') ?></label>
            <textarea id="description" name="description" rows="4"
                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"><?= $val('description') ?></textarea>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label for="activity_type" class="form-label"><?= __('Tipo (ISDF)') ?></label>
                <select id="activity_type" name="activity_type"
                        class="mt-1 block w-full rounded-md <?= $err('activity_type') ? 'border-red-500' : 'border-gray-300' ?> shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <?php foreach ($types as $t): ?>
                        <option value="<?= $e($t) ?>" <?= ((string) ($values['activity_type'] ?? '') === $t) ? 'selected' : '' ?>>
                            <?= $e($typeLabel[$t] ?? $t) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($err('activity_type')): ?>
                    <p class="mt-1 text-sm text-red-600"><?= $e($err('activity_type')) ?></p>
                <?php endif; ?>
            </div>
            <div>
                <label for="parent_id" class="form-label"><?= __('Attività padre') ?></label>
                <select id="parent_id" name="parent_id"
                        class="mt-1 block w-full rounded-md <?= $err('parent_id') ? 'border-red-500' : 'border-gray-300' ?> shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">— <?= __('nessuna') ?> —</option>
                    <?php foreach ($parentOpts as $opt): ?>
                        <option value="<?= (int) $opt['id'] ?>" <?= ((int) ($values['parent_id'] ?? 0) === (int) $opt['id']) ? 'selected' : '' ?>>
                            <?= $e((string) $opt['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($err('parent_id')): ?>
                    <p class="mt-1 text-sm text-red-600"><?= $e($err('parent_id')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label for="date_start" class="form-label"><?= __('Data di inizio') ?></label>
                <input type="text" id="date_start" name="date_start" value="<?= $val('date_start') ?>"
                       placeholder="AAAA o AAAA-MM-GG"
                       class="mt-1 block w-full rounded-md <?= $err('date_start') ? 'border-red-500' : 'border-gray-300' ?> shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <div>
                <label for="date_end" class="form-label"><?= __('Data di fine') ?></label>
                <input type="text" id="date_end" name="date_end" value="<?= $val('date_end') ?>"
                       placeholder="AAAA o AAAA-MM-GG"
                       class="mt-1 block w-full rounded-md <?= $err('date_end') ? 'border-red-500' : 'border-gray-300' ?> shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <div class="flex items-end">
                <label class="inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="is_ongoing" value="1"
                           <?= !empty($values['is_ongoing']) ? 'checked' : '' ?>
                           class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    <span class="ml-2 text-sm text-gray-700"><?= __('Attività in corso') ?></span>
                </label>
            </div>
        </div>

        <div>
            <label for="agent_id" class="form-label"><?= __('Agente esecutore') ?></label>
            <select id="agent_id" name="agent_id"
                    class="mt-1 block w-full rounded-md <?= $err('agent_id') ? 'border-red-500' : 'border-gray-300' ?> shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="">— <?= __('nessuno') ?> —</option>
                <?php foreach ($agentOpts as $opt): ?>
                    <option value="<?= (int) $opt['id'] ?>" <?= ((int) ($values['agent_id'] ?? 0) === (int) $opt['id']) ? 'selected' : '' ?>>
                        <?= $e((string) $opt['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($err('agent_id')): ?>
                <p class="mt-1 text-sm text-red-600"><?= $e($err('agent_id')) ?></p>
            <?php endif; ?>
        </div>

        <div>
            <label for="source_ref" class="form-label"><?= __('Riferimento normativo (mandato giuridico)') ?></label>
            <input type="text" id="source_ref" name="source_ref" maxlength="500"
                   value="<?= $val('source_ref') ?>"
                   placeholder="<?= $e(__('es. RD 9 ottobre 1861 n. 250')) ?>"
                   class="mt-1 block w-full rounded-md <?= $err('source_ref') ? 'border-red-500' : 'border-gray-300' ?> shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        </div>

        <div class="flex items-center justify-between pt-4 border-t">
            <a href="<?= $e(url('/admin/archives/activities')) ?>" class="text-gray-600 hover:underline">
                <?= __('Annulla') ?>
            </a>
            <button type="submit"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 px-4 rounded shadow-sm">
                <?= $e($submitLabel) ?>
            </button>
        </div>
    </form>
</div>

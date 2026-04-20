<?php
/**
 * Authorities — create/edit form view.
 *
 * @var list<string> $types
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 * @var string|null $mode
 * @var int|null $id
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$val = static fn(string $k): string => $e((string) ($values[$k] ?? ''));
$err = static fn(string $k): ?string => $errors[$k] ?? null;

$mode   = ($mode ?? 'create') === 'edit' ? 'edit' : 'create';
$editId = $mode === 'edit' ? (int) ($id ?? 0) : null;
$formAction = $mode === 'edit'
    ? url('/admin/archives/authorities/' . (int) $editId . '/edit')
    : url('/admin/archives/authorities/new');
$pageTitle   = $mode === 'edit' ? __('Modifica authority record') : __('Nuovo authority record');
$submitLabel = $mode === 'edit' ? __('Salva modifiche') : __('Crea authority record');

$typeLabels = [
    'person'    => __('Persona (biografica)'),
    'corporate' => __('Ente (organizzazione, sindacato, partito)'),
    'family'    => __('Famiglia (genealogica)'),
];
?>
<div class="p-6 max-w-4xl mx-auto">
    <div class="mb-6">
        <nav class="text-sm text-gray-500 mb-2">
            <a href="<?= $e(url('/admin/archives')) ?>" class="hover:underline"><?= __("Archivi") ?></a>
            &nbsp;&raquo;&nbsp;
            <a href="<?= $e(url('/admin/archives/authorities')) ?>" class="hover:underline"><?= __("Authority records") ?></a>
            &nbsp;&raquo;&nbsp;
            <?= $mode === 'edit' ? __("Modifica") . ' #' . $e((string) $editId) : __("Nuovo") ?>
        </nav>
        <h1 class="text-2xl font-bold text-gray-900"><?= $e($pageTitle) ?></h1>
        <p class="text-sm text-gray-600 mt-1">
            <?= __("Campi minimi ISAAR(CPF) 5.1-5.2. Elementi aggiuntivi saranno disponibili nelle fasi successive.") ?>
        </p>
    </div>

    <?php if (!empty($errors['_global'])): ?>
        <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-4 rounded">
            <p class="text-sm text-red-800"><strong><?= __("Errore:") ?></strong> <?= $e($errors['_global']) ?></p>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= $e($formAction) ?>" class="bg-white shadow rounded-lg p-6 space-y-5">
        <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">

        <div>
            <label for="type" class="block text-sm font-medium text-gray-700 mb-1">
                <?= __("Tipo di entità") ?> <span class="text-red-500">*</span>
                <span class="text-xs text-gray-500 font-normal">(ISAAR 5.1.1)</span>
            </label>
            <select name="type" id="type" required
                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm <?= $err('type') ? 'border-red-500' : '' ?>">
                <option value="">— <?= __("Seleziona un tipo") ?> —</option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= $e($t) ?>" <?= ($values['type'] ?? '') === $t ? 'selected' : '' ?>>
                        <?= $e($typeLabels[$t] ?? $t) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($err('type')): ?>
                <p class="mt-1 text-xs text-red-600"><?= $e($err('type')) ?></p>
            <?php endif; ?>
        </div>

        <div>
            <label for="authorised_form" class="block text-sm font-medium text-gray-700 mb-1">
                <?= __("Forma autorizzata del nome") ?> <span class="text-red-500">*</span>
                <span class="text-xs text-gray-500 font-normal">(ISAAR 5.1.2)</span>
            </label>
            <input type="text" name="authorised_form" id="authorised_form"
                   value="<?= $val('authorised_form') ?>" maxlength="500" required
                   class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm <?= $err('authorised_form') ? 'border-red-500' : '' ?>">
            <?php if ($err('authorised_form')): ?>
                <p class="mt-1 text-xs text-red-600"><?= $e($err('authorised_form')) ?></p>
            <?php endif; ?>
        </div>

        <div>
            <label for="dates_of_existence" class="block text-sm font-medium text-gray-700 mb-1">
                <?= __("Date di esistenza") ?>
                <span class="text-xs text-gray-500 font-normal">(ISAAR 5.2.1 — <?= __("es. \"1888–1976\" o \"fl. 1920s\"") ?>)</span>
            </label>
            <input type="text" name="dates_of_existence" id="dates_of_existence"
                   value="<?= $val('dates_of_existence') ?>" maxlength="255"
                   class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm <?= $err('dates_of_existence') ? 'border-red-500' : '' ?>">
            <?php if ($err('dates_of_existence')): ?>
                <p class="mt-1 text-xs text-red-600"><?= $e($err('dates_of_existence')) ?></p>
            <?php endif; ?>
        </div>

        <div>
            <label for="history" class="block text-sm font-medium text-gray-700 mb-1">
                <?= __("Storia") ?>
                <span class="text-xs text-gray-500 font-normal">(ISAAR 5.2.2)</span>
            </label>
            <textarea name="history" id="history" rows="4"
                      class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"><?= $val('history') ?></textarea>
        </div>

        <div>
            <label for="functions" class="block text-sm font-medium text-gray-700 mb-1">
                <?= __("Funzioni, occupazioni, attività") ?>
                <span class="text-xs text-gray-500 font-normal">(ISAAR 5.2.5)</span>
            </label>
            <textarea name="functions" id="functions" rows="3"
                      class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"><?= $val('functions') ?></textarea>
        </div>

        <div class="flex items-center justify-end space-x-3 pt-4 border-t">
            <a href="<?= $e(url('/admin/archives/authorities')) ?>"
               class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                <?= __("Annulla") ?>
            </a>
            <button type="submit"
                    class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700">
                <?= $e($submitLabel) ?>
            </button>
        </div>
    </form>
</div>

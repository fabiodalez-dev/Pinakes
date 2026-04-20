<?php
/**
 * Archives — create/edit form view.
 *
 * @var list<string> $levels
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 * @var string|null $mode   'create' (default) or 'edit'
 * @var int|null $id        required when $mode === 'edit'
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$val = static fn(string $k): string => $e((string) ($values[$k] ?? ''));
$err = static fn(string $k): ?string => $errors[$k] ?? null;

$mode   = ($mode ?? 'create') === 'edit' ? 'edit' : 'create';
$editId = $mode === 'edit' ? (int) ($id ?? 0) : null;
$formAction = $mode === 'edit'
    ? url('/admin/archives/' . (int) $editId . '/edit')
    : url('/admin/archives/new');
$pageTitle = $mode === 'edit' ? 'Modifica record archivistico' : 'Nuovo record archivistico';
$submitLabel = $mode === 'edit' ? 'Salva modifiche' : 'Crea record';

$levelLabels = [
    'fonds'  => 'Fondo (archivio completo di un creatore)',
    'series' => 'Serie (raggruppamento per funzione/forma)',
    'file'   => 'Fascicolo (case file, volume)',
    'item'   => 'Unità (lettera, nota, memo)',
];
?>
<div class="p-6 max-w-4xl mx-auto">
    <div class="mb-6">
        <nav class="text-sm text-gray-500 mb-2">
            <a href="<?= $e(url('/admin/archives')) ?>" class="hover:underline">Archivi</a>
            &nbsp;&raquo;&nbsp; <?= $mode === 'edit' ? 'Modifica record #' . $e((string) $editId) : 'Nuovo record' ?>
        </nav>
        <h1 class="text-2xl font-bold text-gray-900"><?= $e($pageTitle) ?></h1>
        <p class="text-sm text-gray-600 mt-1">
            Compila i campi ISAD(G) 3.1 (area di identificazione). Campi aggiuntivi (3.2-3.7)
            saranno disponibili nella vista di modifica dopo la creazione.
        </p>
    </div>

    <?php if (!empty($errors['_global'])): ?>
        <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-4 rounded">
            <p class="text-sm text-red-800"><strong>Errore:</strong> <?= $e($errors['_global']) ?></p>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= $e($formAction) ?>" class="bg-white shadow rounded-lg p-6 space-y-5">
        <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- reference_code -->
            <div>
                <label for="reference_code" class="block text-sm font-medium text-gray-700 mb-1">
                    Reference Code <span class="text-red-500">*</span>
                    <span class="text-xs text-gray-500 font-normal">(ISAD(G) 3.1.1)</span>
                </label>
                <input type="text" name="reference_code" id="reference_code"
                       value="<?= $val('reference_code') ?>" maxlength="64" required
                       class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm <?= $err('reference_code') ? 'border-red-500' : '' ?>">
                <?php if ($err('reference_code')): ?>
                    <p class="mt-1 text-xs text-red-600"><?= $e($err('reference_code')) ?></p>
                <?php endif; ?>
            </div>

            <!-- institution_code -->
            <div>
                <label for="institution_code" class="block text-sm font-medium text-gray-700 mb-1">
                    Codice istituzione
                </label>
                <input type="text" name="institution_code" id="institution_code"
                       value="<?= $val('institution_code') !== '' ? $val('institution_code') : 'PINAKES' ?>"
                       maxlength="16"
                       class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
            </div>
        </div>

        <!-- level -->
        <div>
            <label for="level" class="block text-sm font-medium text-gray-700 mb-1">
                Livello di descrizione <span class="text-red-500">*</span>
                <span class="text-xs text-gray-500 font-normal">(ISAD(G) 3.1.4)</span>
            </label>
            <select name="level" id="level" required
                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm <?= $err('level') ? 'border-red-500' : '' ?>">
                <option value="">— Seleziona un livello —</option>
                <?php foreach ($levels as $lvl): ?>
                    <option value="<?= $e($lvl) ?>" <?= ($values['level'] ?? '') === $lvl ? 'selected' : '' ?>>
                        <?= $e($levelLabels[$lvl] ?? $lvl) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($err('level')): ?>
                <p class="mt-1 text-xs text-red-600"><?= $e($err('level')) ?></p>
            <?php endif; ?>
        </div>

        <!-- formal_title -->
        <div>
            <label for="formal_title" class="block text-sm font-medium text-gray-700 mb-1">
                Titolo formale
                <span class="text-xs text-gray-500 font-normal">(ISAD(G) 3.1.2 — MARC 241*a, se presente sul materiale)</span>
            </label>
            <input type="text" name="formal_title" id="formal_title"
                   value="<?= $val('formal_title') ?>" maxlength="500"
                   class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
        </div>

        <!-- constructed_title -->
        <div>
            <label for="constructed_title" class="block text-sm font-medium text-gray-700 mb-1">
                Titolo attribuito <span class="text-red-500">*</span>
                <span class="text-xs text-gray-500 font-normal">(ISAD(G) 3.1.2 — MARC 245*a, titolo dato dall'archivista)</span>
            </label>
            <input type="text" name="constructed_title" id="constructed_title"
                   value="<?= $val('constructed_title') ?>" maxlength="500" required
                   class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm <?= $err('constructed_title') ? 'border-red-500' : '' ?>">
            <?php if ($err('constructed_title')): ?>
                <p class="mt-1 text-xs text-red-600"><?= $e($err('constructed_title')) ?></p>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- date_start -->
            <div>
                <label for="date_start" class="block text-sm font-medium text-gray-700 mb-1">
                    Anno iniziale
                    <span class="text-xs text-gray-500 font-normal">(ISAD(G) 3.1.3)</span>
                </label>
                <input type="number" name="date_start" id="date_start"
                       value="<?= $val('date_start') ?>" min="-32768" max="32767"
                       class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm <?= $err('date_start') ? 'border-red-500' : '' ?>">
                <?php if ($err('date_start')): ?>
                    <p class="mt-1 text-xs text-red-600"><?= $e($err('date_start')) ?></p>
                <?php endif; ?>
            </div>

            <!-- date_end -->
            <div>
                <label for="date_end" class="block text-sm font-medium text-gray-700 mb-1">
                    Anno finale
                </label>
                <input type="number" name="date_end" id="date_end"
                       value="<?= $val('date_end') ?>" min="-32768" max="32767"
                       class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm <?= $err('date_end') ? 'border-red-500' : '' ?>">
                <?php if ($err('date_end')): ?>
                    <p class="mt-1 text-xs text-red-600"><?= $e($err('date_end')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- extent -->
        <div>
            <label for="extent" class="block text-sm font-medium text-gray-700 mb-1">
                Estensione e supporto
                <span class="text-xs text-gray-500 font-normal">(ISAD(G) 3.1.5 — es. "1357 scatole, 613 volumi")</span>
            </label>
            <input type="text" name="extent" id="extent"
                   value="<?= $val('extent') ?>" maxlength="500"
                   class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
        </div>

        <!-- scope_content -->
        <div>
            <label for="scope_content" class="block text-sm font-medium text-gray-700 mb-1">
                Ambito e contenuto
                <span class="text-xs text-gray-500 font-normal">(ISAD(G) 3.3.1 — abstract)</span>
            </label>
            <textarea name="scope_content" id="scope_content" rows="4"
                      class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"><?= $val('scope_content') ?></textarea>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- language_codes -->
            <div>
                <label for="language_codes" class="block text-sm font-medium text-gray-700 mb-1">
                    Codice lingua
                    <span class="text-xs text-gray-500 font-normal">(ISAD(G) 3.4.3 — ISO 639-2, es. "ita", "eng", "dan")</span>
                </label>
                <input type="text" name="language_codes" id="language_codes"
                       value="<?= $val('language_codes') !== '' ? $val('language_codes') : 'ita' ?>"
                       maxlength="64"
                       class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
            </div>

            <!-- parent_id -->
            <div>
                <label for="parent_id" class="block text-sm font-medium text-gray-700 mb-1">
                    ID unità padre (gerarchia)
                </label>
                <input type="number" name="parent_id" id="parent_id"
                       value="<?= $val('parent_id') ?>" min="1"
                       class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm <?= $err('parent_id') ? 'border-red-500' : '' ?>">
                <p class="mt-1 text-xs text-gray-500">
                    Lasciare vuoto per un record top-level (fondo). Per serie/fascicoli/unità: l'ID dell'unità padre.
                </p>
                <?php if ($err('parent_id')): ?>
                    <p class="mt-1 text-xs text-red-600"><?= $e($err('parent_id')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="flex items-center justify-end space-x-3 pt-4 border-t">
            <a href="<?= $e(url('/admin/archives')) ?>"
               class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                Annulla
            </a>
            <button type="submit"
                    class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700">
                <?= $e($submitLabel) ?>
            </button>
        </div>
    </form>
</div>

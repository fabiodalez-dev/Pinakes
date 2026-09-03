<?php
/**
 * Emeroteca — create/edit form for a testata.
 *
 * Chrome copied verbatim from the Archives admin form view
 * (storage/plugins/archives/views/form.php).
 *
 * @var string|null $mode                'create' (default) or 'edit'
 * @var int|null $id                     set when $mode === 'edit'
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 * @var array<int, string> $editori      id => nome
 * @var array<int, string> $generi       id => nome (top-level)
 * @var array<int, string> $testate      id => titolo (excluding self)
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$val = static fn(string $k): string => $e((string) ($values[$k] ?? ''));
$err = static fn(string $k): ?string => $errors[$k] ?? null;

$mode   = ($mode ?? 'create') === 'edit' ? 'edit' : 'create';
$editId = $mode === 'edit' ? (int) ($id ?? 0) : null;
$formAction = $mode === 'edit'
    ? url('/admin/periodicals/edit/' . (int) $editId)
    : url('/admin/periodicals/create');
$pageTitle = $mode === 'edit' ? __('Modifica testata') : __('Nuova testata');
$submitLabel = $mode === 'edit' ? __('Salva modifiche') : __('Crea testata');

$tipoLabels = [
    'rivista'    => __('Rivista'),
    'giornale'   => __('Giornale'),
    'magazine'   => __('Magazine'),
    'bollettino' => __('Bollettino'),
    'fanzine'    => __('Fanzine'),
];
$statoLabels = [
    'attiva'   => __('Attiva'),
    'chiusa'   => __('Chiusa'),
    'dismessa' => __('Dismessa'),
];
$periodicitaLabels = [
    'quotidiano'   => __('Quotidiano'),
    'settimanale'  => __('Settimanale'),
    'quindicinale' => __('Quindicinale'),
    'mensile'      => __('Mensile'),
    'bimestrale'   => __('Bimestrale'),
    'trimestrale'  => __('Trimestrale'),
    'semestrale'   => __('Semestrale'),
    'annuale'      => __('Annuale'),
    'irregolare'   => __('Irregolare'),
];
?>
<div class="p-6 max-w-4xl mx-auto">
    <div class="mb-6">
        <nav class="text-sm text-gray-500 mb-2">
            <a href="<?= $e(url('/admin/periodicals')) ?>" class="hover:underline"><?= __('Emeroteca') ?></a>
            &nbsp;&raquo;&nbsp; <?= $mode === 'edit' ? __('Modifica testata') . ' #' . $e((string) $editId) : __('Nuova testata') ?>
        </nav>
        <h1 class="text-2xl font-bold text-gray-900"><?= $e($pageTitle) ?></h1>
        <p class="text-sm text-gray-600 mt-1">
            <?= __("Anagrafica della testata: identificazione, editore, periodicità e stato della raccolta.") ?>
        </p>
    </div>

    <?php if (!empty($errors['_global'])): ?>
        <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-4 rounded">
            <p class="text-sm text-red-800"><strong><?= __("Errore:") ?></strong> <?= $e($errors['_global']) ?></p>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= $e($formAction) ?>" class="bg-white shadow rounded-lg p-6 space-y-5">
        <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">

        <!-- ── Identificazione ─────────────────────────────────────── -->
        <div class="border-l-4 border-gray-300 pl-4">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
                <?= __("Identificazione") ?>
            </h2>

            <div>
                <label for="titolo" class="form-label">
                    <?= __("Titolo") ?> <span class="text-red-500">*</span>
                </label>
                <input type="text" name="titolo" id="titolo"
                       value="<?= $val('titolo') ?>" maxlength="255" required
                       class="form-input <?= $err('titolo') ? 'border-red-500' : '' ?>">
                <?php if ($err('titolo')): ?>
                    <p class="mt-1 text-xs text-red-600"><?= $e($err('titolo')) ?></p>
                <?php endif; ?>
            </div>

            <div class="mt-4">
                <label for="sottotitolo" class="form-label">
                    <?= __("Sottotitolo") ?>
                </label>
                <input type="text" name="sottotitolo" id="sottotitolo"
                       value="<?= $val('sottotitolo') ?>" maxlength="255"
                       class="form-input">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div>
                    <label for="issn" class="form-label">
                        <?= __("ISSN") ?>
                        <span class="text-xs text-gray-500 font-normal">(<?= __("formato ####-####, es. 0028-0836") ?>)</span>
                    </label>
                    <input type="text" name="issn" id="issn"
                           value="<?= $val('issn') ?>" maxlength="9"
                           placeholder="0000-0000"
                           class="form-input font-mono text-sm <?= $err('issn') ? 'border-red-500' : '' ?>">
                    <?php if ($err('issn')): ?>
                        <p class="mt-1 text-xs text-red-600"><?= $e($err('issn')) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="lingua" class="form-label">
                        <?= __("Lingua") ?>
                        <span class="text-xs text-gray-500 font-normal">(<?= __("codice, es. it, en, fr") ?>)</span>
                    </label>
                    <input type="text" name="lingua" id="lingua"
                           value="<?= $val('lingua') ?>" maxlength="10"
                           class="form-input">
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 mt-4">
                <div>
                    <label for="tipo" class="form-label">
                        <?= __("Tipo") ?> <span class="text-red-500">*</span>
                    </label>
                    <select name="tipo" id="tipo" required
                            class="form-input <?= $err('tipo') ? 'border-red-500' : '' ?>">
                        <?php foreach ($tipoLabels as $value => $label): ?>
                            <option value="<?= $e($value) ?>" <?= ((string) ($values['tipo'] ?? 'rivista')) === $value ? 'selected' : '' ?>>
                                <?= $e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($err('tipo')): ?>
                        <p class="mt-1 text-xs text-red-600"><?= $e($err('tipo')) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="periodicita" class="form-label">
                        <?= __("Periodicità") ?>
                    </label>
                    <select name="periodicita" id="periodicita"
                            class="form-input <?= $err('periodicita') ? 'border-red-500' : '' ?>">
                        <option value="">— <?= __("Non specificata") ?> —</option>
                        <?php foreach ($periodicitaLabels as $value => $label): ?>
                            <option value="<?= $e($value) ?>" <?= ((string) ($values['periodicita'] ?? '')) === $value ? 'selected' : '' ?>>
                                <?= $e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($err('periodicita')): ?>
                        <p class="mt-1 text-xs text-red-600"><?= $e($err('periodicita')) ?></p>
                    <?php else: ?>
                        <p class="mt-1 text-xs text-gray-500"><?= __("Necessaria per generare il Kardex dei fascicoli attesi.") ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── Pubblicazione ───────────────────────────────────────── -->
        <div class="border-l-4 border-gray-300 pl-4">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
                <?= __("Pubblicazione") ?>
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="editore_id" class="form-label">
                        <?= __("Editore") ?>
                    </label>
                    <select name="editore_id" id="editore_id"
                            class="form-input <?= $err('editore_id') ? 'border-red-500' : '' ?>">
                        <option value="">— <?= __("Nessun editore") ?> —</option>
                        <?php foreach ($editori as $eid => $nome): ?>
                            <option value="<?= (int) $eid ?>" <?= ((int) ($values['editore_id'] ?? 0)) === (int) $eid ? 'selected' : '' ?>>
                                <?= $e($nome) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($err('editore_id')): ?>
                        <p class="mt-1 text-xs text-red-600"><?= $e($err('editore_id')) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="luogo_pubblicazione" class="form-label">
                        <?= __("Luogo di pubblicazione") ?>
                    </label>
                    <input type="text" name="luogo_pubblicazione" id="luogo_pubblicazione"
                           value="<?= $val('luogo_pubblicazione') ?>" maxlength="255"
                           class="form-input">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div>
                    <label for="anno_inizio" class="form-label">
                        <?= __("Anno di inizio pubblicazione") ?>
                    </label>
                    <input type="number" name="anno_inizio" id="anno_inizio"
                           value="<?= $val('anno_inizio') ?>" min="1400" max="2100"
                           class="form-input <?= $err('anno_inizio') ? 'border-red-500' : '' ?>">
                    <?php if ($err('anno_inizio')): ?>
                        <p class="mt-1 text-xs text-red-600"><?= $e($err('anno_inizio')) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="anno_fine" class="form-label">
                        <?= __("Anno di fine pubblicazione") ?>
                        <span class="text-xs text-gray-500 font-normal">(<?= __("vuoto se ancora attiva") ?>)</span>
                    </label>
                    <input type="number" name="anno_fine" id="anno_fine"
                           value="<?= $val('anno_fine') ?>" min="1400" max="2100"
                           class="form-input <?= $err('anno_fine') ? 'border-red-500' : '' ?>">
                    <?php if ($err('anno_fine')): ?>
                        <p class="mt-1 text-xs text-red-600"><?= $e($err('anno_fine')) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div>
                    <label for="testata_precedente_id" class="form-label">
                        <?= __("Testata precedente") ?>
                        <span class="text-xs text-gray-500 font-normal">(<?= __("storia della testata: \"continua da\"") ?>)</span>
                    </label>
                    <select name="testata_precedente_id" id="testata_precedente_id"
                            class="form-input <?= $err('testata_precedente_id') ? 'border-red-500' : '' ?>">
                        <option value="">— <?= __("Nessuna") ?> —</option>
                        <?php foreach ($testate as $tid => $titolo): ?>
                            <option value="<?= (int) $tid ?>" <?= ((int) ($values['testata_precedente_id'] ?? 0)) === (int) $tid ? 'selected' : '' ?>>
                                <?= $e($titolo) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($err('testata_precedente_id')): ?>
                        <p class="mt-1 text-xs text-red-600"><?= $e($err('testata_precedente_id')) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="genere_id" class="form-label">
                        <?= __("Genere") ?>
                    </label>
                    <select name="genere_id" id="genere_id"
                            class="form-input <?= $err('genere_id') ? 'border-red-500' : '' ?>">
                        <option value="">— <?= __("Nessun genere") ?> —</option>
                        <?php foreach ($generi as $gid => $nome): ?>
                            <option value="<?= (int) $gid ?>" <?= ((int) ($values['genere_id'] ?? 0)) === (int) $gid ? 'selected' : '' ?>>
                                <?= $e($nome) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($err('genere_id')): ?>
                        <p class="mt-1 text-xs text-red-600"><?= $e($err('genere_id')) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── Presentazione e note ────────────────────────────────── -->
        <div class="border-l-4 border-gray-300 pl-4">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
                <?= __("Presentazione e note") ?>
            </h2>

            <div>
                <label for="logo_url" class="form-label">
                    <?= __("Logo (URL)") ?>
                    <span class="text-xs text-gray-500 font-normal">(<?= __("URL assoluto o percorso che inizia con /") ?>)</span>
                </label>
                <input type="text" name="logo_url" id="logo_url"
                       value="<?= $val('logo_url') ?>" maxlength="500"
                       placeholder="/uploads/emeroteca/logo.png"
                       class="form-input font-mono text-sm <?= $err('logo_url') ? 'border-red-500' : '' ?>">
                <?php if ($err('logo_url')): ?>
                    <p class="mt-1 text-xs text-red-600"><?= $e($err('logo_url')) ?></p>
                <?php endif; ?>
            </div>

            <div class="mt-4">
                <label for="descrizione" class="form-label">
                    <?= __("Descrizione") ?>
                </label>
                <textarea name="descrizione" id="descrizione" rows="4"
                          class="form-input"><?= $val('descrizione') ?></textarea>
            </div>

            <div class="mt-4">
                <label for="note" class="form-label">
                    <?= __("Note interne") ?>
                </label>
                <textarea name="note" id="note" rows="3"
                          class="form-input"><?= $val('note') ?></textarea>
            </div>

            <div class="mt-4">
                <label for="stato_raccolta" class="form-label">
                    <?= __("Stato della raccolta") ?>
                </label>
                <select name="stato_raccolta" id="stato_raccolta"
                        class="form-input <?= $err('stato_raccolta') ? 'border-red-500' : '' ?>">
                    <?php foreach ($statoLabels as $value => $label): ?>
                        <option value="<?= $e($value) ?>" <?= ((string) ($values['stato_raccolta'] ?? 'attiva')) === $value ? 'selected' : '' ?>>
                            <?= $e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($err('stato_raccolta')): ?>
                    <p class="mt-1 text-xs text-red-600"><?= $e($err('stato_raccolta')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="flex items-center justify-end space-x-3 pt-4 border-t">
            <a href="<?= $e(url('/admin/periodicals')) ?>"
               class="btn-secondary">
                <?= __("Annulla") ?>
            </a>
            <button type="submit"
                    class="btn-primary">
                <?= $e($submitLabel) ?>
            </button>
        </div>
    </form>
</div>

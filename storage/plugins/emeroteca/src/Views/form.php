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
$logo = (string) ($values['logo_url'] ?? '');
$logoSrc = $logo === '' ? '' : (str_starts_with($logo, '/') ? url($logo) : $logo);

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
$acquisizioneLabels = [
    'abbonamento' => __('Abbonamento'),
    'acquisto'    => __('Acquisto'),
    'dono'        => __('Dono'),
    'scambio'     => __('Scambio'),
    'deposito'    => __('Deposito'),
];
$prestabileLabels = [
    'escluso'       => __('Escluso dal prestito'),
    'consultazione' => __('Solo consultazione in sede'),
    'prestabile'    => __('Prestabile'),
];
?>
<link rel="stylesheet" href="<?= $e(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.4.0')) ?>">
<div id="emeroteca-admin-form" class="emeroteca-admin emeroteca-admin--form">
    <div class="emt-page-header">
        <div>
        <nav class="text-sm text-gray-500 mb-2">
            <a href="<?= $e(url('/admin/periodicals')) ?>" class="hover:underline"><?= __('Emeroteca') ?></a>
            &nbsp;&raquo;&nbsp; <?= $mode === 'edit' ? __('Modifica testata') . ' #' . $e((string) $editId) : __('Nuova testata') ?>
        </nav>
        <h1 class="text-2xl font-bold text-gray-900"><?= $e($pageTitle) ?></h1>
        <p class="text-sm text-gray-600 mt-1">
            <?= __("Anagrafica della testata: identificazione, editore, periodicità e stato della raccolta.") ?>
        </p>
        </div>
    </div>

    <?php if (!empty($errors['_global'])): ?>
        <div class="emt-notice bg-red-50 text-red-800 mb-4" role="alert">
            <p class="text-sm text-red-800"><strong><?= __("Errore:") ?></strong> <?= $e($errors['_global']) ?></p>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= $e($formAction) ?>" enctype="multipart/form-data"
          class="emt-surface p-6 space-y-5">
        <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">

        <!-- ── Identificazione ─────────────────────────────────────── -->
        <section class="emt-form-section">
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

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div>
                    <label for="e_issn" class="form-label">
                        <?= __("e-ISSN") ?>
                        <span class="text-xs text-gray-500 font-normal">(<?= __("edizione elettronica") ?>)</span>
                    </label>
                    <input type="text" name="e_issn" id="e_issn"
                           value="<?= $val('e_issn') ?>" maxlength="9"
                           placeholder="0000-0000"
                           class="form-input font-mono text-sm <?= $err('e_issn') ? 'border-red-500' : '' ?>">
                    <?php if ($err('e_issn')): ?>
                        <p class="mt-1 text-xs text-red-600"><?= $e($err('e_issn')) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="issn_l" class="form-label">
                        <?= __("ISSN-L") ?>
                        <span class="text-xs text-gray-500 font-normal">(<?= __("ISSN di collegamento tra le edizioni") ?>)</span>
                    </label>
                    <input type="text" name="issn_l" id="issn_l"
                           value="<?= $val('issn_l') ?>" maxlength="9"
                           placeholder="0000-0000"
                           class="form-input font-mono text-sm <?= $err('issn_l') ? 'border-red-500' : '' ?>">
                    <?php if ($err('issn_l')): ?>
                        <p class="mt-1 text-xs text-red-600"><?= $e($err('issn_l')) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mt-4">
                <label for="barcode_base" class="form-label">
                    <?= __("Barcode di base") ?>
                    <span class="text-xs text-gray-500 font-normal">(<?= __("EAN-13 con prefisso 977, 13 cifre") ?>)</span>
                </label>
                <div class="flex flex-wrap items-center gap-2">
                    <input type="text" name="barcode_base" id="barcode_base"
                           value="<?= $val('barcode_base') ?>" maxlength="13"
                           inputmode="numeric" placeholder="9770000000000"
                           class="form-input font-mono text-sm flex-1 <?= $err('barcode_base') ? 'border-red-500' : '' ?>">
                    <button type="button" id="emt-barcode-derive" class="btn-secondary text-sm">
                        <?= __("Genera dall'ISSN") ?>
                    </button>
                </div>
                <?php if ($err('barcode_base')): ?>
                    <p class="mt-1 text-xs text-red-600"><?= $e($err('barcode_base')) ?></p>
                <?php else: ?>
                    <p class="mt-1 text-xs text-gray-500">
                        <?= __("Lascialo vuoto e verrà calcolato dall'ISSN al salvataggio, cifra di controllo compresa.") ?>
                    </p>
                <?php endif; ?>
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
        </section>

        <!-- ── Pubblicazione ───────────────────────────────────────── -->
        <section class="emt-form-section">
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
                    <label for="direttore_responsabile" class="form-label">
                        <?= __("Direttore responsabile") ?>
                    </label>
                    <input type="text" name="direttore_responsabile" id="direttore_responsabile"
                           value="<?= $val('direttore_responsabile') ?>" maxlength="255"
                           class="form-input">
                </div>
                <div>
                    <label for="registrazione_tribunale" class="form-label">
                        <?= __("Registrazione al tribunale") ?>
                        <span class="text-xs text-gray-500 font-normal">(<?= __("es. n. 123 del 4/5/1998") ?>)</span>
                    </label>
                    <input type="text" name="registrazione_tribunale" id="registrazione_tribunale"
                           value="<?= $val('registrazione_tribunale') ?>" maxlength="255"
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
        </section>

        <!-- ── Gestione amministrativa ─────────────────────────────── -->
        <section class="emt-form-section">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
                <?= __("Gestione amministrativa") ?>
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="prezzo_copertina" class="form-label">
                        <?= __("Prezzo di copertina") ?>
                    </label>
                    <input type="text" name="prezzo_copertina" id="prezzo_copertina"
                           value="<?= $val('prezzo_copertina') ?>" maxlength="20"
                           inputmode="decimal" placeholder="0.00"
                           class="form-input <?= $err('prezzo_copertina') ? 'border-red-500' : '' ?>">
                    <?php if ($err('prezzo_copertina')): ?>
                        <p class="mt-1 text-xs text-red-600"><?= $e($err('prezzo_copertina')) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="acquisizione_default" class="form-label">
                        <?= __("Acquisizione predefinita") ?>
                    </label>
                    <select name="acquisizione_default" id="acquisizione_default"
                            class="form-input <?= $err('acquisizione_default') ? 'border-red-500' : '' ?>">
                        <option value="">— <?= __("Non specificata") ?> —</option>
                        <?php foreach ($acquisizioneLabels as $value => $label): ?>
                            <option value="<?= $e($value) ?>" <?= ((string) ($values['acquisizione_default'] ?? '')) === $value ? 'selected' : '' ?>>
                                <?= $e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($err('acquisizione_default')): ?>
                        <p class="mt-1 text-xs text-red-600"><?= $e($err('acquisizione_default')) ?></p>
                    <?php else: ?>
                        <p class="mt-1 text-xs text-gray-500"><?= __("Proposta come canale per i nuovi fascicoli.") ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="prestabile" class="form-label">
                        <?= __("Politica di prestito") ?>
                    </label>
                    <select name="prestabile" id="prestabile"
                            class="form-input <?= $err('prestabile') ? 'border-red-500' : '' ?>">
                        <?php foreach ($prestabileLabels as $value => $label): ?>
                            <option value="<?= $e($value) ?>" <?= ((string) ($values['prestabile'] ?? 'consultazione')) === $value ? 'selected' : '' ?>>
                                <?= $e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($err('prestabile')): ?>
                        <p class="mt-1 text-xs text-red-600"><?= $e($err('prestabile')) ?></p>
                    <?php else: ?>
                        <p class="mt-1 text-xs text-gray-500">
                            <?= __("Dichiarazione di policy: l'emeroteca non gestisce ancora prestiti, il dato serve al banco e alle statistiche.") ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($mode === 'edit'): ?>
                <p class="mt-4 text-sm">
                    <a href="<?= $e(url('/admin/periodicals/' . (int) $editId . '/subscriptions')) ?>"
                       class="text-gray-700 hover:underline">
                        <i class="fas fa-file-signature" aria-hidden="true"></i>
                        <?= __("Gestisci gli abbonamenti di questa testata") ?>
                    </a>
                </p>
            <?php endif; ?>
        </section>

        <!-- ── Presentazione e note ────────────────────────────────── -->
        <section class="emt-form-section">
            <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">
                <?= __("Presentazione e note") ?>
            </h2>

            <div class="emt-media-editor">
                <label for="logo_url" class="form-label">
                    <?= __("Logo o immagine della rivista") ?>
                </label>
                <div class="emt-media-editor__grid">
                    <div id="emt-logo-preview" class="emt-upload-preview <?= $logoSrc === '' ? 'is-empty' : '' ?>">
                        <?php if ($logoSrc !== ''): ?>
                            <img id="emt-logo-preview-image" src="<?= $e($logoSrc) ?>"
                                 alt="<?= $e(__('Logo della rivista')) ?>">
                        <?php else: ?>
                            <img id="emt-logo-preview-image" src="" alt="<?= $e(__('Logo della rivista')) ?>" hidden>
                            <i class="fas fa-newspaper" aria-hidden="true"></i>
                        <?php endif; ?>
                    </div>
                    <div class="emt-media-editor__controls">
                        <div id="emt-logo-upload"
                             data-emt-uppy="image"
                             data-input="emt-logo-input"
                             data-progress="emt-logo-progress"
                             data-preview="emt-logo-preview"
                             data-preview-image="emt-logo-preview-image"
                             data-note="<?= $e(__('Immagini JPG, PNG o WebP (max 5MB)')) ?>"
                             data-drop="<?= $e(__("Trascina qui l'immagine o %{browse}")) ?>"
                             data-browse="<?= $e(__('seleziona file')) ?>"></div>
                        <div id="emt-logo-progress"></div>
                        <input type="file" name="logo_file" id="emt-logo-input"
                               accept="image/jpeg,image/jpg,image/png,image/webp" hidden>
                        <p class="text-xs text-gray-500">
                            <?= __('JPG, PNG o WebP, max 5MB. Il nuovo file sostituisce il logo attuale.') ?>
                        </p>
                    </div>
                </div>
                <details class="mt-3">
                    <summary class="text-sm text-gray-600 cursor-pointer"><?= __('In alternativa usa un URL') ?></summary>
                    <input type="text" name="logo_url" id="logo_url"
                           value="<?= $val('logo_url') ?>" maxlength="500"
                           placeholder="/uploads/emeroteca/logo.png"
                           class="form-input font-mono text-sm mt-2 <?= $err('logo_url') ? 'border-red-500' : '' ?>">
                    <p class="mt-1 text-xs text-gray-500"><?= __('URL assoluto o percorso che inizia con /') ?></p>
                </details>
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
        </section>

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
<script>
// "Genera dall'ISSN": the EAN-13 (977 prefix + check digit) is derived
// SERVER-SIDE at save time, by the same IssnHelper that validates the ISSN.
// Deriving it here too would mean two implementations of the same check
// digit, and a browser/server disagreement would store a barcode that looks
// well-formed (13 digits) but scans as another title. So the button just
// clears the field — which is exactly what asks the server to compute it —
// and says so.
(function () {
    var button = document.getElementById('emt-barcode-derive');
    var field = document.getElementById('barcode_base');
    if (!button || !field) {
        return;
    }
    var notice = <?= json_encode(__("Il barcode verrà calcolato dall'ISSN al salvataggio."), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    button.addEventListener('click', function () {
        field.value = '';
        field.placeholder = notice;
        field.focus();
    });
})();
</script>
<script src="<?= $e(url('/plugins/emeroteca/assets/js/emeroteca-upload.js?v=1.2.3')) ?>" defer></script>

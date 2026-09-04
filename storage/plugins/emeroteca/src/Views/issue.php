<?php
/**
 * Emeroteca — fascicolo detail: all fields + cover upload + spoglio
 * (article rows added/removed with a small vanilla-JS helper).
 *
 * Chrome copied from the Archives admin form view.
 *
 * @var array<string, mixed> $fascicolo   includes anno, volume, testata_id, testata_titolo
 * @var array<int, array<string, mixed>> $articoli
 * @var array<int, array<string, mixed>> $collocazioni
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$val = static fn(string $k): string => $e((string) ($fascicolo[$k] ?? ''));

$fid       = (int) $fascicolo['id'];
$testataId = (int) $fascicolo['testata_id'];
$issuesUrl = $e(url('/admin/periodicals/' . $testataId . '/issues'));
$formAction = $e(url('/admin/periodicals/issue/' . $fid));
$deleteAction = $e(url('/admin/periodicals/issue/' . $fid . '/delete'));
$csrf = $e(\App\Support\Csrf::ensureToken());

$statoLabels = [
    'posseduto'   => __('Posseduto'),
    'mancante'    => __('Mancante'),
    'danneggiato' => __('Danneggiato'),
    'in_restauro' => __('In restauro'),
    'smarrito'    => __('Smarrito'),
    'atteso'      => __('Atteso'),
];
$tipoArticoloLabels = [
    'articolo'   => __('Articolo'),
    'editoriale' => __('Editoriale'),
    'recensione' => __('Recensione'),
    'intervista' => __('Intervista'),
    'dossier'    => __('Dossier'),
    'rubrica'    => __('Rubrica'),
];
$cover = (string) ($fascicolo['copertina_url'] ?? '');
$coverSrc = $cover === '' ? '' : (str_starts_with($cover, '/') ? url($cover) : $cover);
$pdfPath = (string) ($fascicolo['pdf_path'] ?? '');
$pdfName = (string) ($fascicolo['pdf_nome_originale'] ?? '');
$pdfSize = (int) ($fascicolo['pdf_dimensione'] ?? 0);
$pdfSizeLabel = '';
if ($pdfSize > 0) {
    // Separatori decimali secondo la locale attiva, non hardcoded italiani —
    // anche nel fallback senza ext-intl.
    $pdfMb = $pdfSize / 1048576;
    $sizeLocale = \App\Support\I18n::getLocale();
    [$decSep, $thouSep] = match (substr($sizeLocale, 0, 2)) {
        'it', 'de', 'da' => [',', '.'],
        'fr' => [',', ' '],
        default => ['.', ','],
    };
    $formattedMb = false;
    if (class_exists(\NumberFormatter::class)) {
        $sizeFormatter = new \NumberFormatter($sizeLocale, \NumberFormatter::DECIMAL);
        $sizeFormatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 2);
        $sizeFormatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);
        $formattedMb = $sizeFormatter->format($pdfMb);
    }
    $pdfSizeLabel = ($formattedMb !== false ? $formattedMb : number_format($pdfMb, 2, $decSep, $thouSep)) . ' MB';
}
$pdfAdminUrl = $e(url('/admin/periodicals/issue/' . $fid . '/pdf'));
?>
<link rel="stylesheet" href="<?= $e(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.2.4')) ?>">
<div class="emeroteca-admin emeroteca-admin--form">
    <header class="emt-page-header">
        <nav aria-label="breadcrumb" class="text-sm text-gray-500 mb-4">
            <a href="<?= $e(url('/admin/periodicals')) ?>" class="hover:underline"><?= __('Emeroteca') ?></a>
            &nbsp;&raquo;&nbsp;
            <a href="<?= $issuesUrl ?>" class="hover:underline"><?= $e($fascicolo['testata_titolo']) ?></a>
            &nbsp;&raquo;&nbsp;
            <?= (int) $fascicolo['anno'] ?> · <?= __('n.') ?> <?= $e($fascicolo['numero']) ?>
        </nav>
        <h1 class="text-3xl font-bold text-gray-900">
            <?= $e($fascicolo['testata_titolo']) ?> — <?= (int) $fascicolo['anno'] ?>, <?= __('n.') ?> <?= $e($fascicolo['numero']) ?>
        </h1>
        <p class="text-gray-600 mt-2">
            <?= __("Scheda del fascicolo: dati, copertina e spoglio degli articoli.") ?>
        </p>
    </header>

    <form id="emt-issue-form" method="POST" action="<?= $formAction ?>" enctype="multipart/form-data"
          class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <!-- ── Dati del fascicolo ──────────────────────────────────── -->
        <section class="card">
            <div class="card-header">
                <h2 class="form-section-title flex items-center gap-2">
                    <i class="fas fa-info-circle text-gray-600" aria-hidden="true"></i>
                    <?= __("Dati del fascicolo") ?>
                </h2>
            </div>
            <div class="card-body form-section">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="numero" class="form-label">
                        <?= __("Numero") ?> <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="numero" id="numero"
                           value="<?= $val('numero') ?>" maxlength="50" required
                           class="form-input">
                </div>
                <div>
                    <label for="numero_progressivo" class="form-label">
                        <?= __("Numero progressivo") ?>
                    </label>
                    <input type="text" name="numero_progressivo" id="numero_progressivo"
                           value="<?= $val('numero_progressivo') ?>" maxlength="50"
                           class="form-input">
                </div>
                <div>
                    <label for="stato" class="form-label">
                        <?= __("Stato") ?>
                    </label>
                    <select name="stato" id="stato" class="form-input">
                        <?php foreach ($statoLabels as $value => $label): ?>
                            <option value="<?= $e($value) ?>" <?= ((string) ($fascicolo['stato'] ?? 'posseduto')) === $value ? 'selected' : '' ?>>
                                <?= $e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mt-4">
                <label for="titolo_fascicolo" class="form-label">
                    <?= __("Titolo del fascicolo") ?>
                    <span class="text-xs text-gray-500 font-normal">(<?= __("per numeri monografici") ?>)</span>
                </label>
                <input type="text" name="titolo_fascicolo" id="titolo_fascicolo"
                       value="<?= $val('titolo_fascicolo') ?>" maxlength="255"
                       class="form-input">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                <div>
                    <label for="data_copertina" class="form-label">
                        <?= __("Data di copertina") ?>
                        <span class="text-xs text-gray-500 font-normal">(<?= __("testo libero, es. \"Marzo 1998\"") ?>)</span>
                    </label>
                    <input type="text" name="data_copertina" id="data_copertina"
                           value="<?= $val('data_copertina') ?>" maxlength="100"
                           class="form-input">
                </div>
                <div>
                    <label for="data_pubblicazione" class="form-label">
                        <?= __("Data di pubblicazione") ?>
                    </label>
                    <input type="date" name="data_pubblicazione" id="data_pubblicazione"
                           value="<?= $val('data_pubblicazione') ?>"
                           class="form-input">
                </div>
                <div>
                    <label for="pagine" class="form-label">
                        <?= __("Pagine") ?>
                    </label>
                    <input type="number" name="pagine" id="pagine"
                           value="<?= $val('pagine') ?>" min="0" max="32767"
                           class="form-input">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                <div>
                    <label for="numero_inventario" class="form-label">
                        <?= __("Numero di inventario") ?>
                    </label>
                    <input type="text" name="numero_inventario" id="numero_inventario"
                           value="<?= $val('numero_inventario') ?>" maxlength="100"
                           class="form-input">
                </div>
                <div>
                    <label for="supplementi" class="form-label">
                        <?= __("Supplementi / allegati") ?>
                    </label>
                    <input type="text" name="supplementi" id="supplementi"
                           value="<?= $val('supplementi') ?>" maxlength="500"
                           class="form-input">
                </div>
                <div>
                    <label for="collocazione_id" class="form-label">
                        <?= __("Collocazione") ?>
                    </label>
                    <select name="collocazione_id" id="collocazione_id" class="form-input">
                        <option value=""><?= __("Nessuna") ?></option>
                        <?php foreach ($collocazioni as $collocazioneId => $collocazioneLabel): ?>
                            <option value="<?= (int) $collocazioneId ?>"
                                <?= (int) ($fascicolo['collocazione_id'] ?? 0) === (int) $collocazioneId ? 'selected' : '' ?>>
                                <?= $e($collocazioneLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mt-4">
                <label for="note" class="form-label">
                    <?= __("Note interne") ?>
                </label>
                <textarea name="note" id="note" rows="3"
                          class="form-input"><?= $val('note') ?></textarea>
            </div>
            </div>
        </section>

        <!-- ── Copertina ───────────────────────────────────────────── -->
        <section class="card">
            <div class="card-header">
                <h2 class="form-section-title flex items-center gap-2">
                    <i class="fas fa-image text-gray-600" aria-hidden="true"></i>
                    <?= __("Copertina") ?>
                </h2>
            </div>
            <div class="card-body">
            <div class="emt-media-editor__grid">
                <div id="emt-cover-preview" class="emt-upload-preview emt-upload-preview--cover <?= $coverSrc === '' ? 'is-empty' : '' ?>">
                    <?php if ($coverSrc !== ''): ?>
                        <img id="emt-cover-preview-image" src="<?= $e($coverSrc) ?>"
                             alt="<?= $e(__('Copertina del fascicolo')) ?>">
                    <?php else: ?>
                        <img id="emt-cover-preview-image" src="" alt="<?= $e(__('Copertina del fascicolo')) ?>" hidden>
                        <i class="fas fa-newspaper" aria-hidden="true"></i>
                    <?php endif; ?>
                </div>
                <div class="emt-media-editor__controls">
                    <div id="emt-cover-upload"
                         data-emt-uppy="image"
                         data-input="emt-cover-input"
                         data-progress="emt-cover-progress"
                         data-preview="emt-cover-preview"
                         data-preview-image="emt-cover-preview-image"
                         data-note="<?= $e(__('Immagini JPG, PNG o WebP (max 5MB)')) ?>"
                         data-drop="<?= $e(__("Trascina qui l'immagine o %{browse}")) ?>"
                         data-browse="<?= $e(__('seleziona file')) ?>"></div>
                    <div id="emt-cover-progress"></div>
                    <input type="file" name="copertina" id="emt-cover-input"
                           accept="image/jpeg,image/jpg,image/png,image/webp" hidden>
                    <p class="text-xs text-gray-500">
                        <?= __('Il nuovo file sostituisce la copertina attuale.') ?>
                    </p>
                </div>
            </div>
            </div>
        </section>

        <!-- ── Documento digitale ─────────────────────────────────── -->
        <section class="card">
            <div class="card-header">
                <h2 class="form-section-title flex items-center gap-2">
                    <i class="fas fa-file-pdf text-gray-600" aria-hidden="true"></i>
                    <?= __('Documento PDF') ?>
                </h2>
            </div>
            <div class="card-body">

            <?php if ($pdfPath !== ''): ?>
                <div class="emt-file-current">
                    <div class="emt-file-current__icon"><i class="fas fa-file-pdf" aria-hidden="true"></i></div>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-gray-900 truncate"><?= $e($pdfName !== '' ? $pdfName : __('Fascicolo in PDF')) ?></p>
                        <?php if ($pdfSizeLabel !== ''): ?><p class="text-xs text-gray-500"><?= $e($pdfSizeLabel) ?></p><?php endif; ?>
                    </div>
                    <a href="<?= $pdfAdminUrl ?>" target="_blank" rel="noopener noreferrer" class="btn-secondary text-sm">
                        <i class="fas fa-external-link-alt" aria-hidden="true"></i> <?= __('Apri PDF') ?>
                    </a>
                </div>
            <?php endif; ?>

            <div id="emt-pdf-upload"
                 data-emt-uppy="pdf"
                 data-input="emt-pdf-input"
                 data-progress="emt-pdf-progress"
                 data-result="emt-pdf-result"
                 data-note="<?= $e(__('PDF, max 100 MB')) ?>"
                 data-drop="<?= $e(__("Trascina qui il PDF o %{browse}")) ?>"
                 data-browse="<?= $e(__('seleziona file')) ?>"></div>
            <div id="emt-pdf-progress"></div>
            <div id="emt-pdf-result" class="emt-file-selected" hidden></div>
            <input type="file" name="pdf_file" id="emt-pdf-input" accept=".pdf,application/pdf" hidden>

            <div class="emt-choice-list mt-4">
                <label class="emt-choice">
                    <input type="checkbox" name="pdf_pubblico" value="1" class="form-checkbox"
                        <?= (int) ($fascicolo['pdf_pubblico'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <span>
                        <strong><?= __('Rendi consultabile nel catalogo pubblico') ?></strong>
                        <small><?= __('Se non selezionato, il PDF resta accessibile solo agli amministratori.') ?></small>
                    </span>
                </label>
                <?php if ($pdfPath !== ''): ?>
                    <label class="emt-choice emt-choice--danger">
                        <input type="checkbox" name="rimuovi_pdf" value="1" class="form-checkbox">
                        <span><strong><?= __('Rimuovi il PDF attuale') ?></strong></span>
                    </label>
                <?php endif; ?>
            </div>
            </div>
        </section>

        <!-- ── Spoglio (articoli) ──────────────────────────────────── -->
        <section class="card emt-articles-card">
            <div class="card-header">
                <h2 class="form-section-title flex items-center gap-2">
                    <i class="fas fa-list-alt text-gray-600" aria-hidden="true"></i>
                    <?= __("Spoglio degli articoli") ?>
                    <span class="text-xs font-normal text-gray-500 ml-1"><?= __("(salvato insieme al fascicolo)") ?></span>
                </h2>
            </div>
            <div class="card-body">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200" id="emt-spoglio-table">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Titolo") ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Autori") ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Pagg. da–a") ?></th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Tipo") ?></th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Azioni") ?></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200" id="emt-spoglio-body">
                        <?php foreach ($articoli as $art): ?>
                            <tr class="emt-art-row">
                                <td class="px-4 py-2" data-label="<?= $e(__('Titolo')) ?>">
                                    <input type="text" name="art_titolo[]" maxlength="500"
                                           value="<?= $e($art['titolo']) ?>"
                                           class="form-input text-sm">
                                    <input type="text" name="art_keywords[]" maxlength="500"
                                           value="<?= $e($art['keywords'] ?? '') ?>"
                                           placeholder="<?= $e(__('Parole chiave (opzionali)')) ?>"
                                           class="form-input text-sm mt-1">
                                </td>
                                <td class="px-4 py-2" data-label="<?= $e(__('Autori')) ?>">
                                    <input type="text" name="art_autori[]" maxlength="500"
                                           value="<?= $e($art['autori'] ?? '') ?>"
                                           class="form-input text-sm">
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap emt-pages" data-label="<?= $e(__('Pagine')) ?>">
                                    <input type="number" name="art_pag_da[]" min="0" max="32767"
                                           value="<?= $e($art['pagina_inizio'] ?? '') ?>"
                                           class="form-input text-sm w-20 inline-block">
                                    –
                                    <input type="number" name="art_pag_a[]" min="0" max="32767"
                                           value="<?= $e($art['pagina_fine'] ?? '') ?>"
                                           class="form-input text-sm w-20 inline-block">
                                </td>
                                <td class="px-4 py-2" data-label="<?= $e(__('Tipo')) ?>">
                                    <select name="art_tipo[]" class="form-input text-sm">
                                        <?php foreach ($tipoArticoloLabels as $value => $label): ?>
                                            <option value="<?= $e($value) ?>" <?= ((string) ($art['tipo'] ?? 'articolo')) === $value ? 'selected' : '' ?>>
                                                <?= $e($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="px-4 py-2 text-right text-sm whitespace-nowrap" data-label="<?= $e(__('Azioni')) ?>">
                                    <button type="button" class="text-red-600 hover:underline emt-art-remove"><?= __('rimuovi') ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-500 mt-2">
                <?= __("Le righe con titolo vuoto vengono ignorate al salvataggio.") ?>
            </p>
            <button type="button" id="emt-art-add" class="btn-secondary text-sm mt-2">
                <?= __("Aggiungi articolo") ?>
            </button>

            <template id="emt-art-template">
                <tr class="emt-art-row">
                    <td class="px-4 py-2" data-label="<?= $e(__('Titolo')) ?>">
                        <input type="text" name="art_titolo[]" maxlength="500" value=""
                               class="form-input text-sm">
                        <input type="text" name="art_keywords[]" maxlength="500" value=""
                               placeholder="<?= $e(__('Parole chiave (opzionali)')) ?>"
                               class="form-input text-sm mt-1">
                    </td>
                    <td class="px-4 py-2" data-label="<?= $e(__('Autori')) ?>">
                        <input type="text" name="art_autori[]" maxlength="500" value=""
                               class="form-input text-sm">
                    </td>
                    <td class="px-4 py-2 whitespace-nowrap emt-pages" data-label="<?= $e(__('Pagine')) ?>">
                        <input type="number" name="art_pag_da[]" min="0" max="32767" value=""
                               class="form-input text-sm w-20 inline-block">
                        –
                        <input type="number" name="art_pag_a[]" min="0" max="32767" value=""
                               class="form-input text-sm w-20 inline-block">
                    </td>
                    <td class="px-4 py-2" data-label="<?= $e(__('Tipo')) ?>">
                        <select name="art_tipo[]" class="form-input text-sm">
                            <?php foreach ($tipoArticoloLabels as $value => $label): ?>
                                <option value="<?= $e($value) ?>"><?= $e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="px-4 py-2 text-right text-sm whitespace-nowrap" data-label="<?= $e(__('Azioni')) ?>">
                        <button type="button" class="text-red-600 hover:underline emt-art-remove"><?= __('rimuovi') ?></button>
                    </td>
                </tr>
            </template>
            </div>
        </section>

        <div class="emt-form-actions">
            <a href="<?= $issuesUrl ?>"
               class="btn-secondary">
                <?= __("Annulla") ?>
            </a>
            <button type="submit"
                    class="btn-primary">
                <?= __("Salva fascicolo") ?>
            </button>
        </div>
    </form>

    <div class="emt-delete-row">
        <form method="POST" action="<?= $deleteAction ?>" class="inline"
              onsubmit="return confirm(<?= $e(json_encode(__('Eliminare questo fascicolo e il suo spoglio?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <button type="submit" class="btn-danger inline-flex items-center gap-2 text-sm">
                <i class="fas fa-trash" aria-hidden="true"></i>
                <?= __("Elimina fascicolo") ?>
            </button>
        </form>
    </div>
</div>

<script>
// Spoglio: add/remove article rows (vanilla JS, no libraries).
(function () {
    var body = document.getElementById('emt-spoglio-body');
    var tpl = document.getElementById('emt-art-template');
    var addBtn = document.getElementById('emt-art-add');
    if (!body || !tpl || !addBtn) {
        return;
    }
    addBtn.addEventListener('click', function () {
        body.appendChild(tpl.content.cloneNode(true));
    });
    document.getElementById('emt-spoglio-table').addEventListener('click', function (ev) {
        var btn = ev.target.closest('.emt-art-remove');
        if (!btn) {
            return;
        }
        var row = btn.closest('tr.emt-art-row');
        if (row) {
            row.remove();
        }
    });
})();
</script>
<script src="<?= $e(url('/plugins/emeroteca/assets/js/emeroteca-upload.js?v=1.2.3')) ?>" defer></script>

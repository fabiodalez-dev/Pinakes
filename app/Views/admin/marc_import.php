<?php
$pageTitle = $title ?? __('Import MARC');
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
ob_start();
?>

<div class="min-h-screen bg-gray-50 py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6">

        <?php
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title text-3xl font-bold text-gray-900 flex items-center"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?>

                    <?= __("Import MARC") ?>
                <?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?>
                    <i class="fas fa-file-import text-gray-600 mr-3"></i>
                    <?= __("Import MARC") ?>


        <?php
$workspaceHeader['context'] = (string) ob_get_clean();
ob_start();
?><a href="<?= htmlspecialchars(url('/admin/books'), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300 rounded-lg transition-colors inline-flex items-center">
                        <i class="fas fa-arrow-left mr-2"></i>
                        <?= __("Torna ai Libri") ?>
                    </a><?php
$workspaceHeader['back'] = (string) ob_get_clean();
ob_start();
?>

                <p class="text-sm text-gray-600"><?= __("Carica un file MARC 21, UNIMARC o danMARC2 (ISO 2709 o MARCXML) per importare più libri contemporaneamente") ?></p>
                <div>

                </div>
            <?php
$workspaceHeader['actions'] = (string) ob_get_clean();
ob_start();
?><nav class="pk-workspace-breadcrumb" aria-label="<?= htmlspecialchars(__('Percorso di navigazione'), ENT_QUOTES, 'UTF-8') ?>">
            <ol>
                <li>
                    <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Home') ?></a>
                </li>

                <li>
                    <a href="<?= htmlspecialchars(url('/admin/books'), ENT_QUOTES, 'UTF-8') ?>">
                        <?= __("Libri") ?>
                    </a>
                </li>

                <li aria-current="page">
                    <?= __("Import MARC") ?>
                </li>
            </ol>
        </nav><?php
$workspaceHeader['breadcrumb'] = (string) ob_get_clean();
require dirname(__DIR__, 3) . '/app/Views/partials/workspace-header.php';
unset($workspaceHeader);
?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <div class="lg:col-span-2 space-y-6">

                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4 flex items-center">
                        <i class="fas fa-upload text-gray-600 mr-2"></i>
                        <?= __("Carica File MARC") ?>
                    </h2>

                    <form id="marcUploadForm" action="<?= htmlspecialchars(url('/admin/books/import/marc/upload'), ENT_QUOTES, 'UTF-8') ?>" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <div id="uppy-marc-upload" class="mb-4"></div>
                        <div id="file-success-feedback" class="mb-4 hidden p-3 bg-green-50 border border-green-200 rounded-lg flex items-center">
                            <i class="fas fa-check-circle text-green-600 mr-3"></i>
                            <div class="flex-1">
                                <p class="text-green-800 font-medium"><?= __("File caricato con successo") ?></p>
                                <p class="text-green-700 text-sm" id="file-name-display"></p>
                            </div>
                        </div>
                        <input type="file" name="marc_file" id="marc_file" accept=".mrc,.marc,.iso,.dat,.xml" style="display: none;">

                        <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label for="marc_flavour" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Formato dei record") ?></label>
                                <select id="marc_flavour" name="flavour" class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
                                    <option value="auto" selected><?= __("Riconoscimento automatico") ?></option>
                                    <option value="marc21">MARC 21</option>
                                    <option value="unimarc">UNIMARC</option>
                                    <option value="danmarc2">danMARC2</option>
                                </select>
                            </div>
                            <div>
                                <label for="marc_encoding" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Codifica dei caratteri (ISO 2709)") ?></label>
                                <select id="marc_encoding" name="encoding" class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
                                    <option value="auto" selected><?= __("Dal leader del record (posizione 9)") ?></option>
                                    <option value="utf8">UTF-8</option>
                                    <option value="marc8">MARC-8</option>
                                </select>
                            </div>
                        </div>

                        <div class="mt-6 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                            <div class="text-sm">
                                <div class="font-medium text-gray-900">
                                    <i class="fas fa-clone mr-1 text-gray-600"></i>
                                    <?= __("Libri già presenti (stesso ISBN-13, ISBN-10 o EAN)") ?>
                                </div>
                            </div>
                            <div class="mt-3 grid grid-cols-1 gap-3">
                                <?php foreach ([
                                    'skip' => [__("Salta"), __("Il libro già in catalogo resta com'è")],
                                    'fill' => [__("Completa i campi vuoti"), __("Solo i campi vuoti del libro vengono riempiti dal record")],
                                    'overwrite' => [__("Sovrascrivi"), __("I campi presenti nel record sostituiscono quelli del libro; gli altri restano")],
                                ] as $modeKey => [$modeLabel, $modeHelp]): ?>
                                <label class="flex items-start cursor-pointer">
                                    <div class="flex items-center h-5">
                                        <input type="radio" name="duplicates" id="duplicates_<?= htmlspecialchars($modeKey, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($modeKey, ENT_QUOTES, 'UTF-8') ?>" <?= $modeKey === 'skip' ? 'checked' : '' ?> class="w-4 h-4 text-gray-800 bg-gray-100 border-gray-300 focus:ring-gray-500 focus:ring-2">
                                    </div>
                                    <div class="ml-3 text-sm">
                                        <div class="font-medium text-gray-900"><?= htmlspecialchars($modeLabel, ENT_QUOTES, 'UTF-8') ?></div>
                                        <p class="text-gray-600 mt-1"><?= htmlspecialchars($modeHelp, ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="mt-6 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                            <label class="flex items-start cursor-pointer">
                                <div class="flex items-center h-5">
                                    <input type="checkbox" name="download_covers" id="download_covers" value="1" checked class="w-4 h-4 text-gray-800 bg-gray-100 border-gray-300 rounded focus:ring-gray-500 focus:ring-2">
                                </div>
                                <div class="ml-3 text-sm">
                                    <div class="font-medium text-gray-900">
                                        <i class="fas fa-image mr-1 text-gray-600"></i>
                                        <?= __("Scarica le copertine (campo 856)") ?>
                                    </div>
                                    <p class="text-gray-600 mt-1"><?= __("Le copertine indicate nei record vengono salvate in locale. Rallenta l'importazione.") ?></p>
                                </div>
                            </label>
                        </div>

                        <?php if (!empty($fornitori)): ?>
                        <div class="mt-6 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                            <label for="fornitore_id" class="block text-sm font-medium text-gray-900 mb-2">
                                <i class="fas fa-truck mr-1 text-gray-600"></i>
                                <?= __("Fornitore") ?>
                            </label>
                            <select id="fornitore_id" name="fornitore_id" class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
                                <option value=""><?= __("Nessun fornitore") ?></option>
                                <?php foreach ($fornitori as $supplierId => $supplierName): ?>
                                <option value="<?= (int) $supplierId ?>"><?= htmlspecialchars((string) $supplierName, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-gray-600 mt-1 text-sm"><?= __("Assegnato ai libri nuovi e, con «Sovrascrivi», ai libri già presenti. I libri saltati o solo completati non cambiano fornitore.") ?></p>
                        </div>
                        <?php endif; ?>

                        <div id="import-progress-container" class="mt-6 hidden">
                            <div class="bg-white border border-gray-200 rounded-lg p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-sm font-medium text-gray-700"><?= __("Importazione in corso...") ?></span>
                                    <span id="progress-percent" class="text-sm font-bold text-gray-900">0%</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2.5 mb-2">
                                    <div id="progress-bar" class="bg-gray-800 h-2.5 rounded-full transition-all duration-300" style="width: 0%"></div>
                                </div>
                                <div class="text-xs text-gray-600">
                                    <span id="progress-status"><?= __("Inizializzazione...") ?></span>
                                    <span id="progress-details" class="ml-2"></span>
                                </div>
                            </div>
                        </div>

                        <div class="pk-form-actions mt-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2">
                            <div class="text-sm text-gray-600">
                                <div class="flex items-center">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    <?= __("Formato: .mrc, .marc, .iso (ISO 2709) o .xml (MARCXML) • Max 20MB") ?>
                                </div>
                            </div>
                            <button type="submit" id="previewBtn" class="px-6 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors inline-flex items-center disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap" disabled>
                                <i class="fas fa-eye mr-2"></i>
                                <?= __("Anteprima") ?>
                            </button>
                        </div>
                    </form>
                </div>

                <div id="marc-preview" class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 hidden">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4 flex items-center">
                        <i class="fas fa-list text-gray-600 mr-2"></i>
                        <?= __("Anteprima dei record") ?>
                    </h2>
                    <p id="marc-preview-summary" class="text-sm text-gray-600 mb-4"></p>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Titolo") ?></th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Autori") ?></th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Editore") ?></th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Anno") ?></th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("ISBN") ?></th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Stato") ?></th>
                                </tr>
                            </thead>
                            <tbody id="marc-preview-rows" class="bg-white divide-y divide-gray-200"></tbody>
                        </table>
                    </div>
                    <div class="pk-form-actions mt-6 flex flex-col sm:flex-row items-start sm:items-center justify-end gap-2">
                        <button type="button" id="cancelImportBtn" class="px-4 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300 rounded-lg transition-colors inline-flex items-center">
                            <i class="fas fa-times mr-2"></i>
                            <?= __("Annulla") ?>
                        </button>
                        <button type="button" id="confirmImportBtn" class="px-6 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors inline-flex items-center disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap">
                            <i class="fas fa-cloud-upload-alt mr-2"></i>
                            <?= __("Conferma importazione") ?>
                        </button>
                    </div>
                </div>

                <div id="marc-result" class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 hidden" role="status">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4 flex items-center">
                        <i class="fas fa-check-circle text-green-600 mr-2"></i>
                        <?= __("Import Completato") ?>
                    </h2>
                    <p id="marc-result-summary" class="text-sm text-gray-700"></p>
                    <ul id="marc-result-errors" class="ml-8 mt-3 space-y-1 text-sm text-yellow-800"></ul>
                    <div class="mt-4 flex flex-col sm:flex-row gap-2">
                        <a id="marc-errors-link" href="#" class="hidden inline-flex items-center px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded-lg hover:bg-blue-700 transition-colors duration-200">
                            <i class="fas fa-download mr-1.5"></i>
                            <?= __("Scarica Errori") ?>
                        </a>
                        <a href="<?= htmlspecialchars(url('/admin/imports-history'), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300 rounded-lg transition-colors inline-flex items-center">
                            <i class="fas fa-history mr-2"></i>
                            <?= __("Storico Import") ?>
                        </a>
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                        <i class="fas fa-info-circle text-gray-600 mr-2"></i>
                        <?= __("Come Funziona") ?>
                    </h3>
                    <ol class="space-y-3 text-sm text-gray-700">
                        <li class="flex items-start">
                            <span class="shrink-0 w-6 h-6 bg-gray-800 text-white rounded-full flex items-center justify-center text-xs mr-3">1</span>
                            <span><?= __("Carica il file esportato dal tuo catalogo (ISO 2709 o MARCXML)") ?></span>
                        </li>
                        <li class="flex items-start">
                            <span class="shrink-0 w-6 h-6 bg-gray-800 text-white rounded-full flex items-center justify-center text-xs mr-3">2</span>
                            <span><?= __("Controlla l'anteprima dei primi record") ?></span>
                        </li>
                        <li class="flex items-start">
                            <span class="shrink-0 w-6 h-6 bg-gray-800 text-white rounded-full flex items-center justify-center text-xs mr-3">3</span>
                            <span><?= __("Conferma: autori, editori e copie vengono creati come nell'import CSV") ?></span>
                        </li>
                    </ol>
                </div>
                <div class="bg-gray-800 text-white rounded-lg shadow-sm p-6">
                    <h3 class="text-lg font-semibold mb-4 flex items-center">
                        <i class="fas fa-lightbulb mr-2"></i>
                        <?= __("Suggerimenti") ?>
                    </h3>
                    <ul class="space-y-2 text-sm">
                        <li class="flex items-start">
                            <i class="fas fa-check text-gray-400 mr-2 mt-0.5"></i>
                            <span><?= __("I record illeggibili vengono saltati e riportati negli errori, senza fermare l'import") ?></span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-gray-400 mr-2 mt-0.5"></i>
                            <span><?= __("Le copie (campi 852/949/952) non vengono importate: ogni libro riceve una copia") ?></span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-gray-400 mr-2 mt-0.5"></i>
                            <span><?= __("Per esportare in MARC usa il menu Export dell'elenco libri") ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const BASE_PATH = window.BASE_PATH || '';
    const T = <?= json_encode([
        'records' => __("%d record nel file"),
        'unreadable' => __("%d non leggibili"),
        'existing' => __("Già in catalogo (#%d)"),
        'new' => __("Nuovo"),
        'error' => __("Errore"),
        'uploading' => __("Caricamento file..."),
        'processing' => __("Processing righe"),
        'done' => __("Completato!"),
        'summary' => __("%d importati, %d aggiornati, %d saltati, %d errori"),
        'covers' => __("%d copertine scaricate"),
        'connection' => __("Errore di connessione durante l'importazione"),
        'invalid' => __("Risposta non valida dal server"),
        'uploadError' => __("Errore Upload"),
        'note' => __("File MARC (max 20MB)"),
        'drop' => __("Trascina qui il file MARC o %{browse}"),
        'browse' => __("seleziona file"),
    ], $jsonFlags) ?>;
    const fmt = (s, ...args) => { let i = 0; return s.replace(/%d/g, () => String(args[i++] ?? '')); };
    const csrf = () => document.querySelector('#marcUploadForm input[name="csrf_token"]').value;
    const previewBtn = document.getElementById('previewBtn');
    const fileInput = document.getElementById('marc_file');
    let uppy = null;

    if (typeof Uppy !== 'undefined') {
        try {
            uppy = new Uppy({ restrictions: { maxFileSize: 20 * 1024 * 1024, maxNumberOfFiles: 1, allowedFileTypes: ['.mrc', '.marc', '.iso', '.dat', '.xml'] }, autoProceed: false });
            uppy.use(UppyDragDrop, { target: '#uppy-marc-upload', note: T.note, locale: { strings: { dropHereOr: T.drop, dropPasteFiles: T.drop, browse: T.browse } } });
            uppy.on('file-added', (file) => {
                previewBtn.disabled = false;
                document.getElementById('file-success-feedback').classList.remove('hidden');
                document.getElementById('file-name-display').textContent = file.name + ' (' + (file.size / 1024).toFixed(2) + ' KB)';
            });
            uppy.on('file-removed', () => {
                if (uppy.getFiles().length === 0) {
                    previewBtn.disabled = true;
                    document.getElementById('file-success-feedback').classList.add('hidden');
                }
            });
            uppy.on('restriction-failed', (file, error) => window.SwalApp.error(T.uploadError, error.message));
        } catch (e) {
            fileInput.style.display = 'block';
        }
    } else {
        fileInput.style.display = 'block';
    }
    fileInput.addEventListener('change', () => { previewBtn.disabled = fileInput.files.length === 0; });

    const esc = (v) => String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    function updateProgress(percent, status, details) {
        document.getElementById('progress-bar').style.width = percent + '%';
        document.getElementById('progress-percent').textContent = percent + '%';
        document.getElementById('progress-status').textContent = status;
        document.getElementById('progress-details').textContent = details || '';
    }
    function showError(message) {
        previewBtn.disabled = false;
        // A chunk error keeps the server state (position, counters): a new
        // click on Confirm resumes from there instead of a dead button.
        document.getElementById('confirmImportBtn').disabled = !upload;
        document.getElementById('import-progress-container').classList.add('hidden');
        window.SwalApp.error(T.error, message);
    }

    let upload = null;
    document.getElementById('marcUploadForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        if (uppy && uppy.getFiles().length > 0 && uppy.getFiles()[0].data) {
            formData.set('marc_file', uppy.getFiles()[0].data, uppy.getFiles()[0].name);
        }
        previewBtn.disabled = true;
        document.getElementById('marc-result').classList.add('hidden');
        document.getElementById('import-progress-container').classList.remove('hidden');
        updateProgress(5, T.uploading, '');
        fetch(e.target.action, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf() } })
            .then((r) => r.json())
            .then((data) => {
                document.getElementById('import-progress-container').classList.add('hidden');
                if (!data.success) { showError(data.error || T.invalid); return; }
                upload = data;
                renderPreview(data);
            })
            .catch(() => showError(T.connection));
    });

    function renderPreview(data) {
        let summary = fmt(T.records, data.total_rows);
        if (data.unreadable > 0) summary += ' — ' + fmt(T.unreadable, data.unreadable);
        if (data.truncated) summary += ' — ' + data.truncated;
        document.getElementById('marc-preview-summary').textContent = summary;
        const rows = (data.preview || []).map((r) => {
            if (r.error) {
                return `<tr data-preview-row="error"><td class="px-6 py-4 text-sm text-gray-900">${esc(r.position)}</td><td colspan="6" class="px-6 py-4 text-sm text-red-600">${esc(T.error)}: ${esc(r.error)}</td></tr>`;
            }
            const status = r.existing_id
                ? `<span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">${esc(fmt(T.existing, r.existing_id))}</span>`
                : `<span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">${esc(T.new)}</span>`;
            return `<tr data-preview-row="record"><td class="px-6 py-4 text-sm text-gray-900">${esc(r.position)}</td>`
                + `<td class="px-6 py-4 text-sm text-gray-900"><div class="font-medium">${esc(r.titolo)}</div><div class="text-xs text-gray-500">${esc(r.sottotitolo)}</div></td>`
                + `<td class="px-6 py-4 text-sm text-gray-900">${esc(r.autori)}</td><td class="px-6 py-4 text-sm text-gray-900">${esc(r.editore)}</td>`
                + `<td class="px-6 py-4 text-sm text-gray-900">${esc(r.anno)}</td><td class="px-6 py-4 text-sm text-gray-900 font-mono">${esc(r.isbn)}</td><td class="px-6 py-4 text-sm">${status}</td></tr>`;
        });
        document.getElementById('marc-preview-rows').innerHTML = rows.join('');
        document.getElementById('marc-preview').classList.remove('hidden');
        document.getElementById('confirmImportBtn').disabled = false;
    }

    document.getElementById('cancelImportBtn').addEventListener('click', () => {
        // Drop the staged records on the server too, not only the preview.
        fetch(BASE_PATH + '/admin/books/import/marc/discard', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf() }
        }).catch(() => {});
        upload = null;
        document.getElementById('marc-preview').classList.add('hidden');
        previewBtn.disabled = false;
    });

    document.getElementById('confirmImportBtn').addEventListener('click', () => {
        if (!upload) return;
        document.getElementById('confirmImportBtn').disabled = true;
        document.getElementById('import-progress-container').classList.remove('hidden');
        const total = upload.total_rows;
        const size = upload.chunk_size || 10;
        let current = 0;
        const next = () => {
            if (current >= total) return;
            updateProgress(Math.round((current / total) * 100), `${T.processing} ${current}-${Math.min(current + size, total)}...`, '');
            fetch(BASE_PATH + '/admin/books/import/marc/chunk', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
                body: JSON.stringify({ start: current, size: size })
            })
                .then((r) => r.json())
                .then((data) => {
                    if (!data.success) { showError(data.error || T.invalid); return; }
                    if (!data.complete && data.processed <= current) { showError(T.invalid); return; } // no progress: never loop
                    current = data.processed;
                    if (data.complete) { finish(data); } else { next(); }
                })
                .catch(() => showError(T.connection));
        };
        next();
    });

    function finish(data) {
        updateProgress(100, T.done, '');
        document.getElementById('marc-preview').classList.add('hidden');
        let summary = fmt(T.summary, data.imported, data.updated, data.skipped, data.failed);
        if (data.covers > 0) summary += ', ' + fmt(T.covers, data.covers);
        document.getElementById('marc-result-summary').textContent = summary;
        document.getElementById('marc-result-errors').innerHTML = (data.error_details || []).slice(0, 10).map((e) => `<li class="list-disc">${esc(e)}</li>`).join('');
        const link = document.getElementById('marc-errors-link');
        if (data.import_id && data.failed > 0) {
            link.href = BASE_PATH + '/admin/imports/download-errors?import_id=' + encodeURIComponent(data.import_id);
            link.classList.remove('hidden');
        } else {
            link.classList.add('hidden');
        }
        document.getElementById('marc-result').classList.remove('hidden');
        if (uppy) { uppy.cancelAll(); }
        upload = null;
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout.php';

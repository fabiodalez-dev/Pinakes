<?php
$pageTitle = $title ?? __('Import Libri da CSV');
ob_start();
?>

<!-- Uppy CSS is bundled in vendor.css - no CDN required -->

<div class="min-h-screen bg-gray-50 py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6">

        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="flex items-center space-x-2 text-sm">
                <li>
                    <a href="/admin/dashboard" class="text-gray-500 hover:text-gray-700 transition-colors">
                        <i class="fas fa-home mr-1"></i><?= __("Home") ?>
                    </a>
                </li>
                <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
                <li>
                    <a href="/admin/libri" class="text-gray-500 hover:text-gray-700 transition-colors">
                        <i class="fas fa-book mr-1"></i><?= __("Libri") ?>
                    </a>
                </li>
                <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
                <li class="text-gray-900 font-medium">
                    <i class="fas fa-file-csv mr-1"></i><?= __("Import CSV") ?>
                </li>
            </ol>
        </nav>

        <!-- Header -->
        <div class="mb-6 fade-in">
            <div class="flex flex-col gap-4">
                <h1 class="text-3xl font-bold text-gray-900 flex items-center">
                    <i class="fas fa-file-csv text-gray-600 mr-3"></i>
                    <?= __("Import Massivo Libri") ?>
                </h1>
                <p class="text-sm text-gray-600"><?= __("Carica un file CSV per importare più libri contemporaneamente") ?></p>
                <div>
                    <a href="/admin/libri" class="px-4 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300 rounded-lg transition-colors inline-flex items-center">
                        <i class="fas fa-arrow-left mr-2"></i>
                        <?= __("Torna ai Libri") ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg flex items-start" role="alert">
                <i class="fas fa-check-circle text-green-600 mt-0.5 mr-3"></i>
                <div class="flex-1">
                    <p class="text-green-800 font-medium"><?= htmlspecialchars($_SESSION['success']) ?></p>
                </div>
                <button onclick="this.parentElement.remove()" class="text-green-600 hover:text-green-800">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg flex items-start" role="alert">
                <i class="fas fa-exclamation-circle text-red-600 mt-0.5 mr-3"></i>
                <div class="flex-1">
                    <p class="text-red-800 font-medium"><?= htmlspecialchars($_SESSION['error']) ?></p>
                </div>
                <button onclick="this.parentElement.remove()" class="text-red-600 hover:text-red-800">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['import_errors']) && !empty($_SESSION['import_errors'])): ?>
            <div class="mb-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg" role="alert">
                <div class="flex items-start mb-2">
                    <i class="fas fa-exclamation-triangle text-yellow-600 mt-0.5 mr-3"></i>
                    <h5 class="text-yellow-900 font-semibold"><?= __("Errori durante l'import") ?></h5>
                </div>
                <ul class="ml-8 space-y-1 text-sm text-yellow-800">
                    <?php foreach (array_slice($_SESSION['import_errors'], 0, 10) as $error): ?>
                        <li class="list-disc"><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                    <?php if (count($_SESSION['import_errors']) > 10): ?>
                        <li class="list-none text-yellow-600 italic"><?= __("... e altri %d errori", count($_SESSION['import_errors']) - 10) ?></li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php unset($_SESSION['import_errors']); ?>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Main Upload Section -->
            <div class="lg:col-span-2 space-y-6">

                <!-- Uppy Upload Card -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4 flex items-center">
                        <i class="fas fa-upload text-gray-600 mr-2"></i>
                        <?= __("Carica File CSV") ?>
                    </h2>

                    <form id="uploadForm" action="/admin/libri/import/upload" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= \App\Support\Csrf::ensureToken() ?>">
                        <!-- Uppy Upload Area -->
                        <div id="uppy-csv-upload" class="mb-4"></div>
                        <div id="uppy-csv-progress" class="mb-4"></div>

                        <!-- File Upload Success Feedback -->
                        <div id="file-success-feedback" class="mb-4 hidden p-3 bg-green-50 border border-green-200 rounded-lg flex items-center">
                            <i class="fas fa-check-circle text-green-600 mr-3"></i>
                            <div class="flex-1">
                                <p class="text-green-800 font-medium"><?= __("File caricato con successo") ?></p>
                                <p class="text-green-700 text-sm" id="file-name-display"></p>
                            </div>
                        </div>

                        <!-- Fallback file input (hidden, used by Uppy) -->
                        <input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv,application/csv" style="display: none;">

                        <!-- Opzione Scraping Automatico -->
                        <div class="mt-6 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                            <label class="flex items-start cursor-pointer">
                                <div class="flex items-center h-5">
                                    <input type="checkbox" name="enable_scraping" id="enable_scraping" value="1" class="w-4 h-4 text-gray-800 bg-gray-100 border-gray-300 rounded focus:ring-gray-500 focus:ring-2">
                                </div>
                                <div class="ml-3 text-sm">
                                    <div class="font-medium text-gray-900">
                                        <i class="fas fa-robot mr-1 text-gray-600"></i>
                                        <?= __("Arricchimento automatico dati") ?>
                                    </div>
                                    <p class="text-gray-600 mt-1">
                                        <?= __("Per ogni libro con ISBN, prova a recuperare automaticamente i dati mancanti (copertina, autori, descrizione) dai servizi online.") ?>
                                        <strong><?= __("Rallenta l'importazione") ?></strong> <?= __("per evitare blocchi (delay di 3 secondi tra ogni richiesta).") ?>
                                    </p>
                                    <p class="text-gray-500 text-xs mt-2">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        <?= __("Limiti: massimo 50 libri con scraping attivo, timeout 5 minuti") ?>
                                    </p>
                                </div>
                            </label>
                        </div>

                        <!-- Progress Monitor (hidden initially) -->
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

                        <div class="mt-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2">
                            <div class="text-sm text-gray-600">
                                <div class="flex items-center">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    <?= __("Formato: CSV con separatore %s • Max 10MB", '<code class="bg-gray-100 px-2 py-0.5 rounded">;</code>') ?>
                                </div>
                                <div class="text-xs text-gray-500 mt-1">
                                    <?= __("Max 10.000 righe • Max 100 copie per libro") ?>
                                </div>
                            </div>
                            <button type="submit" id="submitBtn" class="px-6 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors inline-flex items-center disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap" disabled>
                                <i class="fas fa-cloud-upload-alt mr-2"></i>
                                <?= __("Importa") ?>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Download Example -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4 flex items-center">
                        <i class="fas fa-download text-gray-600 mr-2"></i>
                        <?= __("File di Esempio") ?>
                    </h2>
                    <p class="text-gray-600 mb-4">
                        <?= __("Scarica il CSV di esempio con 3 libri già compilati per capire il formato corretto e iniziare subito.") ?>
                    </p>
                    <a href="/admin/libri/import/example" class="px-6 py-2 bg-gray-100 text-gray-800 hover:bg-gray-200 rounded-lg transition-colors inline-flex items-center">
                        <i class="fas fa-file-download mr-2"></i>
                        <?= __("Scarica esempio_import_libri.csv") ?>
                    </a>
                </div>

                <!-- Format Details -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                    <button onclick="this.nextElementSibling.classList.toggle('hidden')" class="w-full px-6 py-4 flex items-center justify-between text-left hover:bg-gray-50 transition-colors">
                        <span class="font-semibold text-gray-900 flex items-center">
                            <i class="fas fa-table text-gray-600 mr-2"></i>
                            <?= __("Formato CSV Dettagliato") ?>
                        </span>
                        <i class="fas fa-chevron-down text-gray-400"></i>
                    </button>
                    <div class="hidden border-t border-gray-200">
                        <div class="p-6 overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 border-b border-gray-200">
                                    <tr>
                                        <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __("Campo") ?></th>
                                        <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __("Obbligatorio") ?></th>
                                        <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __("Descrizione") ?></th>
                                        <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __("Esempio") ?></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr>
                                        <td class="px-4 py-3"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs">titolo</code></td>
                                        <td class="px-4 py-3"><span class="px-2 py-0.5 bg-red-100 text-red-800 text-xs rounded"><?= __("Sì") ?></span></td>
                                        <td class="px-4 py-3 text-gray-600"><?= __("Titolo del libro") ?></td>
                                        <td class="px-4 py-3 text-gray-500 text-xs"><?= __("Il nome della rosa") ?></td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-3"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs">autori</code></td>
                                        <td class="px-4 py-3"><span class="px-2 py-0.5 bg-yellow-100 text-yellow-800 text-xs rounded"><?= __("Consigliato") ?></span></td>
                                        <td class="px-4 py-3 text-gray-600"><?= __("Autori multipli separati da %s o %s", '<code>;</code>', '<code>|</code>') ?></td>
                                        <td class="px-4 py-3 text-gray-500 text-xs"><?= __("Umberto Eco") ?><br><small><?= __("o multipli: Engels;Marx") ?></small></td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-3"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs">editore</code></td>
                                        <td class="px-4 py-3"><span class="px-2 py-0.5 bg-gray-100 text-gray-600 text-xs rounded"><?= __("No") ?></span></td>
                                        <td class="px-4 py-3 text-gray-600"><?= __("Nome dell'editore") ?></td>
                                        <td class="px-4 py-3 text-gray-500 text-xs"><?= __("Mondadori") ?></td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-3"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs">isbn13</code></td>
                                        <td class="px-4 py-3"><span class="px-2 py-0.5 bg-gray-100 text-gray-600 text-xs rounded"><?= __("No") ?></span></td>
                                        <td class="px-4 py-3 text-gray-600"><?= __("ISBN a 13 cifre (univoco)") ?></td>
                                        <td class="px-4 py-3 text-gray-500 text-xs">9788804562627</td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-3"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs">anno_pubblicazione</code></td>
                                        <td class="px-4 py-3"><span class="px-2 py-0.5 bg-gray-100 text-gray-600 text-xs rounded"><?= __("No") ?></span></td>
                                        <td class="px-4 py-3 text-gray-600"><?= __("Anno (YYYY)") ?></td>
                                        <td class="px-4 py-3 text-gray-500 text-xs">1980</td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-3"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs">categoria</code></td>
                                        <td class="px-4 py-3"><span class="px-2 py-0.5 bg-gray-100 text-gray-600 text-xs rounded"><?= __("No") ?></span></td>
                                        <td class="px-4 py-3 text-gray-600"><?= __("Nome categoria esistente") ?></td>
                                        <td class="px-4 py-3 text-gray-500 text-xs"><?= __("Narrativa") ?></td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-3 text-gray-500 text-xs italic" colspan="4">
                                            <?= __("+ 15 campi aggiuntivi disponibili (vedi CSV di esempio)") ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Sidebar -->
            <div class="space-y-6">

                <!-- Instructions -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                        <i class="fas fa-info-circle text-gray-600 mr-2"></i>
                        <?= __("Come Funziona") ?>
                    </h3>
                    <ol class="space-y-3 text-sm text-gray-700">
                        <li class="flex items-start">
                            <span class="flex-shrink-0 w-6 h-6 bg-gray-800 text-white rounded-full flex items-center justify-center text-xs mr-3">1</span>
                            <span><?= __("Scarica il file CSV di esempio") ?></span>
                        </li>
                        <li class="flex items-start">
                            <span class="flex-shrink-0 w-6 h-6 bg-gray-800 text-white rounded-full flex items-center justify-center text-xs mr-3">2</span>
                            <span><?= __("Compila con i dati dei tuoi libri") ?></span>
                        </li>
                        <li class="flex items-start">
                            <span class="flex-shrink-0 w-6 h-6 bg-gray-800 text-white rounded-full flex items-center justify-center text-xs mr-3">3</span>
                            <span><?= __("Carica il file usando l'uploader") ?></span>
                        </li>
                        <li class="flex items-start">
                            <span class="flex-shrink-0 w-6 h-6 bg-gray-800 text-white rounded-full flex items-center justify-center text-xs mr-3">4</span>
                            <span><?= __("Il sistema creerà automaticamente libri, autori ed editori") ?></span>
                        </li>
                    </ol>
                </div>

                <!-- Tips -->
                <div class="bg-gray-800 text-white rounded-lg shadow-sm p-6">
                    <h3 class="text-lg font-semibold mb-4 flex items-center">
                        <i class="fas fa-lightbulb mr-2"></i>
                        <?= __("Suggerimenti") ?>
                    </h3>
                    <ul class="space-y-2 text-sm">
                        <li class="flex items-start">
                            <i class="fas fa-check text-gray-400 mr-2 mt-0.5"></i>
                            <span><?= __("Usa il separatore %s", '<code class="bg-gray-700 px-1.5 py-0.5 rounded">;</code>') ?></span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-gray-400 mr-2 mt-0.5"></i>
                            <span><?= __("Campo %s obbligatorio", '<strong>titolo</strong>') ?></span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-gray-400 mr-2 mt-0.5"></i>
                            <span><?= __("Autori multipli separati da %s", '<code class="bg-gray-700 px-1.5 py-0.5 rounded">|</code>') ?></span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-gray-400 mr-2 mt-0.5"></i>
                            <span><?= __("Salva in UTF-8") ?></span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-gray-400 mr-2 mt-0.5"></i>
                            <span><?= __("Autori ed editori vengono creati automaticamente") ?></span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-sync-alt text-gray-400 mr-2 mt-0.5"></i>
                            <span><?= __("I doppioni (per ID, ISBN13 o EAN) vengono aggiornati senza modificare le copie fisiche") ?></span>
                        </li>
                    </ul>
                </div>

                <!-- Stats -->
                <div class="bg-gradient-to-br from-gray-700 to-gray-800 text-white rounded-lg shadow-sm p-6">
                    <h3 class="text-lg font-semibold mb-4 flex items-center">
                        <i class="fas fa-magic mr-2"></i>
                        <?= __("Automatismi") ?>
                    </h3>
                    <div class="space-y-3 text-sm">
                        <div class="flex items-center justify-between">
                            <span><?= __("✓ Crea autori mancanti") ?></span>
                            <i class="fas fa-user-plus text-gray-400"></i>
                        </div>
                        <div class="flex items-center justify-between">
                            <span><?= __("✓ Crea editori mancanti") ?></span>
                            <i class="fas fa-building text-gray-400"></i>
                        </div>
                        <div class="flex items-center justify-between">
                            <span><?= __("✓ Validazione dati") ?></span>
                            <i class="fas fa-shield-alt text-gray-400"></i>
                        </div>
                        <div class="flex items-center justify-between">
                            <span><?= __("✓ Report errori") ?></span>
                            <i class="fas fa-clipboard-list text-gray-400"></i>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

    if (typeof Uppy === 'undefined') {
        console.error('Uppy is not loaded! Check vendor.bundle.js');
        // Fallback to regular file input
        document.getElementById('csv_file').style.display = 'block';
        return;
    }

    let uppyCsv; // Declare outside try-catch for access in submit handler

    try {
        uppyCsv = new Uppy({
            restrictions: {
                maxFileSize: 10 * 1024 * 1024, // 10MB
                maxNumberOfFiles: 1,
                allowedFileTypes: ['.csv', 'text/csv', 'application/csv']
            },
            autoProceed: false
        });

        uppyCsv.use(UppyDragDrop, {
            target: '#uppy-csv-upload',
            note: '<?= addslashes(__("File CSV (max 10MB)")) ?>',
            locale: {
                strings: {
                    dropPasteFiles: '<?= addslashes(__("Trascina qui il file CSV o %{browse}")) ?>',
                    browse: '<?= addslashes(__("seleziona file")) ?>'
                }
            }
        });

        uppyCsv.use(UppyProgressBar, {
            target: '#uppy-csv-progress',
            hideAfterFinish: false
        });

        // Handle file added
        uppyCsv.on('file-added', (file) => {
            document.getElementById('submitBtn').disabled = false;

            // Show success feedback
            const feedbackEl = document.getElementById('file-success-feedback');
            const fileNameEl = document.getElementById('file-name-display');
            feedbackEl.classList.remove('hidden');
            fileNameEl.textContent = file.name + ' (' + (file.size / 1024).toFixed(2) + ' KB)';
        });

        // Handle file removed
        uppyCsv.on('file-removed', (file) => {
            if (uppyCsv.getFiles().length === 0) {
                document.getElementById('submitBtn').disabled = true;
                // Hide success feedback
                document.getElementById('file-success-feedback').classList.add('hidden');
            }
        });

        uppyCsv.on('restriction-failed', (file, error) => {
            console.error('Upload restriction failed:', error);
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: '<?= addslashes(__("Errore Upload")) ?>',
                    text: error.message
                });
            } else {
                alert('<?= addslashes(__("Errore:")) ?> ' + error.message);
            }
        });

    } catch (error) {
        console.error('Error initializing Uppy:', error);
        // Fallback to regular file input
        document.getElementById('csv_file').style.display = 'block';
    }

    // Handle form submission with progress monitoring
    document.getElementById('uploadForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const submitBtn = document.getElementById('submitBtn');
        const progressContainer = document.getElementById('import-progress-container');
        const form = e.target;
        const formData = new FormData(form);

        // Add file from Uppy to FormData (if Uppy is available)
        if (typeof uppyCsv !== 'undefined' && uppyCsv) {
            const uppyFiles = uppyCsv.getFiles();
            if (uppyFiles.length > 0 && uppyFiles[0].data) {
                formData.set('csv_file', uppyFiles[0].data, uppyFiles[0].name);
            }
        }

        // Show progress container
        progressContainer.classList.remove('hidden');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i><?= addslashes(__("Importazione in corso...")) ?>';

        // Use XMLHttpRequest for progress monitoring
        const xhr = new XMLHttpRequest();

        xhr.upload.addEventListener('progress', function(e) {
            if (e.lengthComputable) {
                const uploadPercent = Math.round((e.loaded / e.total) * 20); // Upload is first 20%
                updateProgress(uploadPercent, '<?= addslashes(__("Caricamento file...")) ?>', `${Math.round(e.loaded / 1024)} KB / ${Math.round(e.total / 1024)} KB`);
            }
        });

        xhr.addEventListener('load', function() {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.redirect) {
                        updateProgress(100, '<?= addslashes(__("Completato!")) ?>', '');
                        window.location.href = response.redirect;
                    } else if (response.error) {
                        showError(response.error);
                    }
                } catch (e) {
                    // Non-JSON response, assume redirect via headers
                    window.location.reload();
                }
            } else {
                showError('<?= addslashes(__("Errore durante l\'importazione (HTTP")) ?> ' + xhr.status + ')');
            }
        });

        xhr.addEventListener('error', function() {
            showError('<?= addslashes(__("Errore di connessione durante l\'importazione")) ?>');
        });

        xhr.open('POST', form.action, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.send(formData);

        // Start polling for progress if scraping is enabled
        if (document.getElementById('enable_scraping').checked) {
            setTimeout(() => pollProgress(), 1000);
        }
    });

    // Poll for import progress
    let pollInterval;
    function pollProgress() {
        fetch('/admin/libri/import/progress')
            .then(res => res.json())
            .then(data => {
                if (data.status === 'processing') {
                    const percent = Math.round((data.current / data.total) * 80) + 20; // 20-100%
                    updateProgress(
                        percent,
                        `<?= addslashes(__("Importazione libro")) ?> ${data.current}/${data.total}...`,
                        data.current_book || ''
                    );
                    pollInterval = setTimeout(() => pollProgress(), 500);
                } else if (data.status === 'completed') {
                    clearTimeout(pollInterval);
                    updateProgress(100, '<?= addslashes(__("Completato!")) ?>', '');
                }
            })
            .catch(err => {
                console.error('Progress polling error:', err);
                clearTimeout(pollInterval);
            });
    }

    function updateProgress(percent, status, details) {
        document.getElementById('progress-bar').style.width = percent + '%';
        document.getElementById('progress-percent').textContent = percent + '%';
        document.getElementById('progress-status').textContent = status;
        document.getElementById('progress-details').textContent = details;
    }

    function showError(message) {
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-cloud-upload-alt mr-2"></i><?= addslashes(__("Importa")) ?>';

        if (window.Swal) {
            Swal.fire({
                icon: 'error',
                title: '<?= addslashes(__("Errore")) ?>',
                text: message
            });
        } else {
            alert('<?= addslashes(__("Errore:")) ?> ' + message);
        }

        document.getElementById('import-progress-container').classList.add('hidden');
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout.php';
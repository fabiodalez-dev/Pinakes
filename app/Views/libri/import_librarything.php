<?php
use App\Support\Csrf;

$pageTitle = $title ?? __('Import LibraryThing');
$csrfToken = Csrf::ensureToken();
?>

<!-- Uppy CSS is bundled in vendor.css - no CDN required -->

<div class="min-h-screen bg-gray-50 py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6">

        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="flex items-center space-x-2 text-sm">
                <li>
                    <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
                        <i class="fas fa-home mr-1"></i><?= __("Home") ?>
                    </a>
                </li>
                <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
                <li>
                    <a href="<?= htmlspecialchars(url('/admin/libri'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
                        <i class="fas fa-book mr-1"></i><?= __("Libri") ?>
                    </a>
                </li>
                <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
                <li class="text-gray-900 font-medium">
                    <i class="fas fa-cloud-upload-alt mr-1"></i><?= __("Import LibraryThing") ?>
                </li>
            </ol>
        </nav>

        <!-- Header -->
        <div class="mb-6 fade-in">
            <div class="flex flex-col gap-4">
                <h1 class="text-3xl font-bold text-gray-900 flex items-center">
                    <i class="fas fa-cloud-upload-alt text-gray-600 mr-3"></i>
                    <?= __("Import da LibraryThing") ?>
                </h1>
                <p class="text-sm text-gray-600"><?= __("Importa i tuoi libri esportati da LibraryThing.com (formato TSV)") ?></p>
                <div class="flex gap-2">
                    <a href="<?= htmlspecialchars(url('/admin/libri'), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300 rounded-lg transition-colors inline-flex items-center">
                        <i class="fas fa-arrow-left mr-2"></i>
                        <?= __("Torna ai Libri") ?>
                    </a>
                    <a href="<?= htmlspecialchars(url('/admin/libri/import'), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 bg-gray-800 text-white hover:bg-black rounded-lg transition-colors inline-flex items-center">
                        <i class="fas fa-file-csv mr-2"></i>
                        <?= __("Import CSV Standard") ?>
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
                        <li class="list-none text-yellow-600 italic">
                            <?= sprintf(__("... e altri %d errori"), count($_SESSION['import_errors']) - 10) ?>
                        </li>
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
                        <?= __("Carica File LibraryThing") ?>
                    </h2>

                    <form method="POST" action="<?= htmlspecialchars(url('/admin/libri/import/librarything/process'), ENT_QUOTES, 'UTF-8') ?>" enctype="multipart/form-data" id="import-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                        <!-- Uppy Upload Area -->
                        <div id="uppy-lt-upload" class="mb-4"></div>
                        <div id="uppy-lt-progress" class="mb-4"></div>

                        <!-- File Upload Success Feedback -->
                        <div id="file-success-feedback" class="mb-4 hidden p-3 bg-green-50 border border-green-200 rounded-lg flex items-center">
                            <i class="fas fa-check-circle text-green-600 mr-3"></i>
                            <div class="flex-1">
                                <p class="text-green-800 font-medium"><?= __("File caricato con successo") ?></p>
                                <p class="text-green-700 text-sm" id="file-name-display"></p>
                            </div>
                        </div>

                        <!-- Fallback file input (hidden, used by Uppy) -->
                        <input type="file" name="tsv_file" id="tsv_file" accept=".tsv,.csv,.txt" style="display: none;">

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
                                    <?= __("Formato: TSV/CSV • Max 10MB") ?>
                                </div>
                                <div class="text-xs text-gray-500 mt-1">
                                    <?= __("Tab-delimited text da LibraryThing.com") ?>
                                </div>
                            </div>
                            <button type="submit" id="submitBtn" class="px-6 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors inline-flex items-center disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap font-medium" disabled>
                                <i class="fas fa-cloud-upload-alt mr-2"></i>
                                <?= __("Importa Libri") ?>
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
                        <?= __("Scarica un file TSV di esempio con alcuni libri già compilati per capire il formato LibraryThing e iniziare subito.") ?>
                    </p>
                    <a href="<?= htmlspecialchars(url('/admin/libri/import/librarything/example'), ENT_QUOTES, 'UTF-8') ?>" class="px-6 py-2 bg-gray-100 text-gray-800 hover:bg-gray-200 rounded-lg transition-colors inline-flex items-center">
                        <i class="fas fa-file-download mr-2"></i>
                        <?= __("Scarica esempio_librarything.tsv") ?>
                    </a>
                </div>

                <!-- Format Details -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                    <button onclick="this.nextElementSibling.classList.toggle('hidden')" class="w-full px-6 py-4 flex items-center justify-between text-left hover:bg-gray-50 transition-colors">
                        <span class="font-semibold text-gray-900 flex items-center">
                            <i class="fas fa-table text-gray-600 mr-2"></i>
                            <?= __("Formato LibraryThing Dettagliato") ?>
                        </span>
                        <i class="fas fa-chevron-down text-gray-400"></i>
                    </button>
                    <div class="hidden border-t border-gray-200">
                        <div class="p-6 overflow-x-auto">
                            <p class="text-sm text-gray-600 mb-4">
                                <?= __("LibraryThing esporta i dati in formato TSV (Tab-Separated Values). Pinakes riconosce automaticamente questi campi:") ?>
                            </p>
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 border-b border-gray-200">
                                    <tr>
                                        <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __("Campo LibraryThing") ?></th>
                                        <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __("Campo Pinakes") ?></th>
                                        <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __("Descrizione") ?></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr>
                                        <td class="px-4 py-3"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs">TITLE</code></td>
                                        <td class="px-4 py-3 text-gray-600">Titolo</td>
                                        <td class="px-4 py-3 text-gray-500 text-xs"><?= __("Titolo principale del libro") ?></td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-3"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs">AUTHOR (first, last)</code></td>
                                        <td class="px-4 py-3 text-gray-600">Autore</td>
                                        <td class="px-4 py-3 text-gray-500 text-xs"><?= __("Autore principale (formato: Cognome, Nome)") ?></td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-3"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs">SECONDARY AUTHOR(S)</code></td>
                                        <td class="px-4 py-3 text-gray-600">Autore Secondario</td>
                                        <td class="px-4 py-3 text-gray-500 text-xs"><?= __("Altri autori separati da pipe |") ?></td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-3"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs">ISBN</code></td>
                                        <td class="px-4 py-3 text-gray-600">ISBN-13</td>
                                        <td class="px-4 py-3 text-gray-500 text-xs"><?= __("Codice ISBN (supporta sia ISBN-10 che ISBN-13)") ?></td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-3"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs">PUBLICATION</code></td>
                                        <td class="px-4 py-3 text-gray-600">Editore</td>
                                        <td class="px-4 py-3 text-gray-500 text-xs"><?= __("Casa editrice") ?></td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-3"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs">DATE</code></td>
                                        <td class="px-4 py-3 text-gray-600">Anno Pubblicazione</td>
                                        <td class="px-4 py-3 text-gray-500 text-xs"><?= __("Anno di pubblicazione") ?></td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-3"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs">TAGS</code></td>
                                        <td class="px-4 py-3 text-gray-600">Descrizione/Tags</td>
                                        <td class="px-4 py-3 text-gray-500 text-xs"><?= __("Tag e parole chiave") ?></td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-3"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs">LANGUAGE (main)</code></td>
                                        <td class="px-4 py-3 text-gray-600">Lingua</td>
                                        <td class="px-4 py-3 text-gray-500 text-xs"><?= __("Lingua del libro (ITA, ENG, etc.)") ?></td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-3"><code class="bg-gray-100 px-2 py-0.5 rounded text-xs">ENTRY DATE</code></td>
                                        <td class="px-4 py-3 text-gray-600">Data Inserimento</td>
                                        <td class="px-4 py-3 text-gray-500 text-xs"><?= __("Data di inserimento in biblioteca") ?></td>
                                    </tr>
                                    <tr>
                                        <td class="px-4 py-3 text-gray-500 text-xs italic" colspan="3">
                                            <?= __("+ Altri campi LibraryThing vengono mappati automaticamente") ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-800">
                                <i class="fas fa-info-circle mr-1"></i>
                                <?= __("La mappatura avviene automaticamente. Non è necessario rinominare i campi!") ?>
                            </div>
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
                        <?= __("Come Esportare da LibraryThing") ?>
                    </h3>
                    <ol class="space-y-3 text-sm text-gray-700">
                        <li class="flex items-start">
                            <span class="flex-shrink-0 w-6 h-6 bg-gray-800 text-white rounded-full flex items-center justify-center text-xs mr-3">1</span>
                            <span><?= __("Vai su <strong>LibraryThing.com</strong> → Your Library") ?></span>
                        </li>
                        <li class="flex items-start">
                            <span class="flex-shrink-0 w-6 h-6 bg-gray-800 text-white rounded-full flex items-center justify-center text-xs mr-3">2</span>
                            <span><?= __("Clicca su <strong>More</strong> → <strong>Export your library</strong>") ?></span>
                        </li>
                        <li class="flex items-start">
                            <span class="flex-shrink-0 w-6 h-6 bg-gray-800 text-white rounded-full flex items-center justify-center text-xs mr-3">3</span>
                            <span><?= __("Seleziona <strong>Tab-delimited text</strong> come formato") ?></span>
                        </li>
                        <li class="flex items-start">
                            <span class="flex-shrink-0 w-6 h-6 bg-gray-800 text-white rounded-full flex items-center justify-center text-xs mr-3">4</span>
                            <span><?= __("Scarica il file .tsv e caricalo qui sopra") ?></span>
                        </li>
                    </ol>
                </div>

                <!-- Tips -->
                <div class="bg-gray-800 text-white rounded-lg shadow-sm p-6">
                    <h3 class="text-lg font-semibold mb-4 flex items-center">
                        <i class="fas fa-lightbulb mr-2"></i>
                        <?= __("Campi Supportati") ?>
                    </h3>
                    <ul class="space-y-2 text-sm">
                        <li class="flex items-start">
                            <i class="fas fa-check text-gray-400 mr-2 mt-0.5"></i>
                            <span><?= __("Titolo, Sottotitolo") ?></span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-gray-400 mr-2 mt-0.5"></i>
                            <span><?= __("Autore Principale e Secondario") ?></span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-gray-400 mr-2 mt-0.5"></i>
                            <span><?= __("ISBN, EAN, Barcode") ?></span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-gray-400 mr-2 mt-0.5"></i>
                            <span><?= __("Editore, Anno Pubblicazione") ?></span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-gray-400 mr-2 mt-0.5"></i>
                            <span><?= __("Tags, Descrizione") ?></span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-gray-400 mr-2 mt-0.5"></i>
                            <span><?= __("Lingua, Numero Pagine") ?></span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-gray-400 mr-2 mt-0.5"></i>
                            <span><?= __("Classificazione Dewey") ?></span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-gray-400 mr-2 mt-0.5"></i>
                            <span><?= __("Data Inserimento") ?></span>
                        </li>
                    </ul>
                </div>

                <!-- Automatismi -->
                <div class="bg-gradient-to-br from-gray-700 to-gray-800 text-white rounded-lg shadow-sm p-6">
                    <h3 class="text-lg font-semibold mb-4 flex items-center">
                        <i class="fas fa-magic mr-2"></i>
                        <?= __("Automatismi") ?>
                    </h3>
                    <div class="space-y-3 text-sm">
                        <div class="flex items-center justify-between">
                            <span><?= __("✓ Mappatura automatica campi") ?></span>
                            <i class="fas fa-exchange-alt text-gray-400"></i>
                        </div>
                        <div class="flex items-center justify-between">
                            <span><?= __("✓ Crea autori mancanti") ?></span>
                            <i class="fas fa-user-plus text-gray-400"></i>
                        </div>
                        <div class="flex items-center justify-between">
                            <span><?= __("✓ Crea editori mancanti") ?></span>
                            <i class="fas fa-building text-gray-400"></i>
                        </div>
                        <div class="flex items-center justify-between">
                            <span><?= __("✓ Aggiorna duplicati (ISBN)") ?></span>
                            <i class="fas fa-sync-alt text-gray-400"></i>
                        </div>
                        <div class="flex items-center justify-between">
                            <span><?= __("✓ Scraping copertine/dati") ?></span>
                            <i class="fas fa-robot text-gray-400"></i>
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
        document.getElementById('tsv_file').style.display = 'block';
        return;
    }

    let uppyLt; // Declare outside try-catch for access in submit handler

    try {
        uppyLt = new Uppy({
            restrictions: {
                maxFileSize: 10 * 1024 * 1024, // 10MB
                maxNumberOfFiles: 1,
                allowedFileTypes: ['.tsv', '.csv', '.txt', 'text/plain', 'text/tab-separated-values', 'text/csv']
            },
            autoProceed: false
        });

        uppyLt.use(UppyDragDrop, {
            target: '#uppy-lt-upload',
            note: <?= json_encode(__("File TSV/CSV da LibraryThing (max 10MB)"), JSON_HEX_TAG) ?>,
            locale: {
                strings: {
                    dropPasteFiles: <?= json_encode(__("Trascina qui il file TSV o %{browse}"), JSON_HEX_TAG) ?>,
                    browse: <?= json_encode(__("seleziona file"), JSON_HEX_TAG) ?>
                }
            }
        });

        uppyLt.use(UppyProgressBar, {
            target: '#uppy-lt-progress',
            hideAfterFinish: false
        });

        // Handle file added
        uppyLt.on('file-added', (file) => {
            document.getElementById('submitBtn').disabled = false;

            // Show success feedback
            const feedbackEl = document.getElementById('file-success-feedback');
            const fileNameEl = document.getElementById('file-name-display');
            feedbackEl.classList.remove('hidden');
            fileNameEl.textContent = file.name + ' (' + (file.size / 1024).toFixed(2) + ' KB)';
        });

        // Handle file removed
        uppyLt.on('file-removed', (file) => {
            if (uppyLt.getFiles().length === 0) {
                document.getElementById('submitBtn').disabled = true;
                // Hide success feedback
                document.getElementById('file-success-feedback').classList.add('hidden');
            }
        });

        uppyLt.on('restriction-failed', (file, error) => {
            console.error('Upload restriction failed:', error);
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: <?= json_encode(__("Errore Upload"), JSON_HEX_TAG) ?>,
                    text: error.message
                });
            } else {
                alert(<?= json_encode(__("Errore:"), JSON_HEX_TAG) ?> + ' ' + error.message);
            }
        });

    } catch (error) {
        console.error('Error initializing Uppy:', error);
        // Fallback to regular file input
        document.getElementById('tsv_file').style.display = 'block';
    }

    // Handle form submission with chunked processing
    document.getElementById('import-form').addEventListener('submit', async function(e) {
        e.preventDefault();

        const submitBtn = document.getElementById('submitBtn');
        const progressContainer = document.getElementById('import-progress-container');
        const form = e.target;
        const formData = new FormData(form);

        // Add file from Uppy to FormData (if Uppy is available)
        if (typeof uppyLt !== 'undefined' && uppyLt) {
            const uppyFiles = uppyLt.getFiles();
            if (uppyFiles.length > 0 && uppyFiles[0].data) {
                formData.set('tsv_file', uppyFiles[0].data, uppyFiles[0].name);
            }
        }

        // Show progress container
        progressContainer.classList.remove('hidden');
        submitBtn.disabled = true;
        submitBtn.textContent = <?= json_encode(__("Importazione in corso..."), JSON_HEX_TAG) ?>;

        const csrfToken = formData.get('csrf_token');

        try {
            // Step 1: Prepare import (validate and save file)
            updateProgress(10, <?= json_encode(__("Caricamento file..."), JSON_HEX_TAG) ?>, '');

            const prepareResponse = await fetch(window.BASE_PATH + '/admin/libri/import/librarything/prepare', {
                method: 'POST',
                body: formData
            });

            if (!prepareResponse.ok) {
                const errorText = await prepareResponse.text();
                throw new Error(errorText || '<?= __("Errore HTTP durante la preparazione") ?>');
            }

            const prepareData = await prepareResponse.json();

            if (!prepareData.success) {
                throw new Error(prepareData.error || '<?= __("Errore durante la preparazione") ?>');
            }

            updateProgress(20, <?= json_encode(__("File caricato, inizio processing..."), JSON_HEX_TAG) ?>, '');

            const importId = prepareData.import_id;
            const totalRows = prepareData.total_rows;
            const chunkSize = prepareData.chunk_size || 10;
            let currentRow = 0;

            // Step 2: Process chunks
            while (currentRow < totalRows) {
                const nextRow = Math.min(currentRow + chunkSize, totalRows);
                const percent = 20 + Math.round((currentRow / totalRows) * 80);
                updateProgress(percent, <?= json_encode(__("Elaborazione libri"), JSON_HEX_TAG) ?> + ` ${currentRow + 1}-${nextRow}...`, `${currentRow}/${totalRows}`);

                const chunkResponse = await fetch(window.BASE_PATH + '/admin/libri/import/librarything/chunk', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        csrf_token: csrfToken,
                        import_id: importId,
                        start: currentRow,
                        size: chunkSize
                    })
                });

                if (!chunkResponse.ok) {
                    const errorText = await chunkResponse.text();
                    throw new Error('HTTP ' + chunkResponse.status + ': ' + errorText.substring(0, 200));
                }

                let chunkData;
                try {
                    const responseText = await chunkResponse.text();
                    chunkData = JSON.parse(responseText);
                } catch (jsonError) {
                    throw new Error('<?= __("Risposta non valida dal server (timeout o errore)") ?>');
                }

                if (!chunkData.success) {
                    throw new Error(chunkData.error || '<?= __("Errore durante l\'elaborazione") ?>');
                }

                // Update progress
                currentRow = chunkData.current;

                // Check if complete
                if (chunkData.complete) {
                    break;
                }
            }

            // Step 3: Get final results
            updateProgress(100, <?= json_encode(__("Completato!"), JSON_HEX_TAG) ?>, '');

            const resultsResponse = await fetch(window.BASE_PATH + '/admin/libri/import/librarything/results');
            if (!resultsResponse.ok) {
                throw new Error('<?= __("Errore nel recupero dei risultati") ?>');
            }
            const resultsData = await resultsResponse.json();

            if (resultsData.success && resultsData.redirect) {
                setTimeout(() => {
                    window.location.href = resultsData.redirect;
                }, 500);
            } else {
                throw new Error('<?= __("Errore durante il completamento") ?>');
            }

        } catch (error) {
            showError('<?= __("Errore") ?>: ' + error.message);
        }
    });

    function updateProgress(percent, status, details) {
        document.getElementById('progress-bar').style.width = percent + '%';
        document.getElementById('progress-percent').textContent = percent + '%';
        document.getElementById('progress-status').textContent = status;
        document.getElementById('progress-details').textContent = details;
    }

    function showError(message) {
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.disabled = false;
        submitBtn.textContent = <?= json_encode(__("Importa Libri"), JSON_HEX_TAG) ?>;

        if (window.Swal) {
            Swal.fire({
                icon: 'error',
                title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>,
                text: message
            });
        } else {
            alert(message);
        }

        document.getElementById('import-progress-container').classList.add('hidden');
    }
});
</script>

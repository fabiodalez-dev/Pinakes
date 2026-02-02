<?php
use App\Support\Csrf;

error_log('[DEBUG VIEW] import_librarything.php: START');
error_log('[DEBUG VIEW] Session: ' . print_r($_SESSION ?? [], true));

$pageTitle = $title ?? __('Import LibraryThing');

error_log('[DEBUG VIEW] About to call Csrf::ensureToken()');
try {
    $csrfToken = Csrf::ensureToken();
    error_log('[DEBUG VIEW] CSRF token obtained: ' . $csrfToken);
} catch (\Throwable $e) {
    error_log('[DEBUG VIEW] EXCEPTION in Csrf::ensureToken(): ' . $e->getMessage());
    throw $e;
}
?>

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
                    <a href="/admin/libri" class="px-4 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300 rounded-lg transition-colors inline-flex items-center">
                        <i class="fas fa-arrow-left mr-2"></i>
                        <?= __("Torna ai Libri") ?>
                    </a>
                    <a href="/admin/libri/import" class="px-4 py-2 bg-gray-800 text-white hover:bg-black rounded-lg transition-colors inline-flex items-center">
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

                <!-- Upload Card -->
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <h2 class="text-xl font-semibold mb-4 flex items-center">
                        <i class="fas fa-upload text-gray-800 mr-2"></i>
                        <?= __("Carica File LibraryThing") ?>
                    </h2>

                    <form method="POST" action="/admin/libri/import/librarything/process" enctype="multipart/form-data" id="import-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                        <!-- File Upload -->
                        <div class="mb-4">
                            <label for="tsv_file" class="block text-sm font-medium text-gray-700 mb-2">
                                <?= __("File TSV/CSV") ?>
                            </label>
                            <input type="file" name="tsv_file" id="tsv_file" accept=".tsv,.csv,.txt" required
                                   class="block w-full text-sm text-gray-900 border border-gray-300 rounded-lg cursor-pointer bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-l-lg file:border-0 file:text-sm file:font-semibold file:bg-gray-50 file:text-gray-800 hover:file:bg-gray-200">
                            <p class="mt-1 text-xs text-gray-500"><?= __("Accetta file .tsv, .csv, .txt") ?></p>
                        </div>

                        <!-- Scraping Option -->
                        <div class="mb-4">
                            <label class="flex items-center space-x-2 cursor-pointer">
                                <input type="checkbox" name="enable_scraping" id="enable_scraping" value="1"
                                       class="w-4 h-4 text-gray-800 bg-gray-100 border-gray-300 rounded focus:ring-gray-500 focus:ring-2">
                                <span class="text-sm text-gray-700">
                                    <i class="fas fa-globe mr-1"></i>
                                    <?= __("Arricchisci dati con scraping web (copertine, descrizioni, etc.)") ?>
                                </span>
                            </label>
                            <p class="ml-6 mt-1 text-xs text-gray-500">
                                <?= __("Massimo 50 libri, timeout 5 minuti") ?>
                            </p>
                        </div>

                        <!-- Submit Button -->
                        <div class="flex items-center gap-3">
                            <button type="submit" id="submit-btn"
                                    class="px-6 py-2 bg-gray-800 text-white hover:bg-black rounded-lg transition-colors inline-flex items-center font-medium">
                                <i class="fas fa-cloud-upload-alt mr-2"></i>
                                <?= __("Importa Libri") ?>
                            </button>
                            <div id="progress-indicator" class="hidden flex items-center gap-2">
                                <i class="fas fa-spinner fa-spin text-gray-800"></i>
                                <span class="text-sm text-gray-600" id="progress-text"><?= __("Preparazione...") ?></span>
                            </div>
                        </div>

                        <!-- Progress Bar -->
                        <div id="progress-bar-container" class="hidden mt-4">
                            <div class="bg-gray-200 rounded-full h-4 overflow-hidden">
                                <div id="progress-bar" class="bg-gray-800 h-full transition-all duration-300" style="width: 0%"></div>
                            </div>
                            <div class="mt-2 text-sm text-gray-600 text-center" id="progress-stats">
                                0 / 0 (0%)
                            </div>
                        </div>
                    </form>
                </div>

            </div>

            <!-- Sidebar with Instructions -->
            <div class="lg:col-span-1">

                <!-- Instructions Card -->
                <div class="bg-gray-50 rounded-lg border border-gray-200 p-6 sticky top-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-3 flex items-center">
                        <i class="fas fa-info-circle mr-2"></i>
                        <?= __("Come Esportare da LibraryThing") ?>
                    </h3>
                    <ol class="space-y-3 text-sm text-gray-800">
                        <li class="flex items-start">
                            <span class="flex-shrink-0 w-6 h-6 flex items-center justify-center bg-gray-800 text-white rounded-full text-xs font-bold mr-2 mt-0.5">1</span>
                            <span><?= __("Vai su <strong>LibraryThing.com</strong> → La tua biblioteca") ?></span>
                        </li>
                        <li class="flex items-start">
                            <span class="flex-shrink-0 w-6 h-6 flex items-center justify-center bg-gray-800 text-white rounded-full text-xs font-bold mr-2 mt-0.5">2</span>
                            <span><?= __("Clicca su <strong>More</strong> → <strong>Export</strong>") ?></span>
                        </li>
                        <li class="flex items-start">
                            <span class="flex-shrink-0 w-6 h-6 flex items-center justify-center bg-gray-800 text-white rounded-full text-xs font-bold mr-2 mt-0.5">3</span>
                            <span><?= __("Seleziona formato <strong>Tab-delimited text</strong>") ?></span>
                        </li>
                        <li class="flex items-start">
                            <span class="flex-shrink-0 w-6 h-6 flex items-center justify-center bg-gray-800 text-white rounded-full text-xs font-bold mr-2 mt-0.5">4</span>
                            <span><?= __("Scarica il file e caricalo qui") ?></span>
                        </li>
                    </ol>

                    <div class="mt-4 pt-4 border-t border-gray-200">
                        <h4 class="font-semibold text-gray-900 mb-2">
                            <i class="fas fa-check-circle mr-1"></i>
                            <?= __("Campi Supportati") ?>
                        </h4>
                        <ul class="space-y-1 text-xs text-gray-800">
                            <li>✓ <?= __("Titolo, Sottotitolo") ?></li>
                            <li>✓ <?= __("Autore Principale e Secondario") ?></li>
                            <li>✓ <?= __("ISBN, EAN, Barcode") ?></li>
                            <li>✓ <?= __("Editore, Anno") ?></li>
                            <li>✓ <?= __("Descrizione, Tags") ?></li>
                            <li>✓ <?= __("Lingua, Pagine") ?></li>
                            <li>✓ <?= __("Classificazione Dewey") ?></li>
                            <li>✓ <?= __("Formato, Prezzo") ?></li>
                        </ul>
                    </div>

                    <div class="mt-4 pt-4 border-t border-gray-200">
                        <h4 class="font-semibold text-gray-900 mb-2">
                            <i class="fas fa-lightbulb mr-1"></i>
                            <?= __("Suggerimenti") ?>
                        </h4>
                        <ul class="space-y-1 text-xs text-gray-800">
                            <li>• <?= __("Libri duplicati vengono aggiornati automaticamente (per ISBN)") ?></li>
                            <li>• <?= __("Autori ed editori vengono creati se non esistono") ?></li>
                            <li>• <?= __("Lo scraping aggiunge copertine e descrizioni mancanti") ?></li>
                        </ul>
                    </div>
                </div>

            </div>

        </div>

    </div>
</div>

<script>
console.log('[DEBUG] Import page loaded successfully');
console.log('[DEBUG] Document ready state:', document.readyState);

// Add global error handler
window.addEventListener('error', function(event) {
    console.error('[DEBUG] Global error caught:', event.error);
});

// Chunked import processing
document.getElementById('import-form').addEventListener('submit', async function(e) {
    console.log('[DEBUG] Form submit handler attached');
    e.preventDefault();

    const submitBtn = document.getElementById('submit-btn');
    const progressIndicator = document.getElementById('progress-indicator');
    const progressText = document.getElementById('progress-text');
    const progressBarContainer = document.getElementById('progress-bar-container');
    const progressBar = document.getElementById('progress-bar');
    const progressStats = document.getElementById('progress-stats');

    // Disable submit button
    submitBtn.disabled = true;
    progressIndicator.classList.remove('hidden');
    progressText.textContent = '<?= __("Preparazione...") ?>';

    const formData = new FormData(this);
    const csrfToken = formData.get('csrf_token');
    console.log('[DEBUG] CSRF token from form:', csrfToken);

    try {
        // Step 1: Prepare import (validate and save file)
        console.log('[DEBUG] Starting prepare request with CSRF token');
        const prepareResponse = await fetch('/admin/libri/import/librarything/prepare', {
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

        // Show progress bar
        progressBarContainer.classList.remove('hidden');
        progressText.textContent = '<?= __("Import in corso...") ?>';

        const importId = prepareData.import_id;
        const totalRows = prepareData.total_rows;
        const chunkSize = prepareData.chunk_size || 10;
        let currentRow = 0;

        // Step 2: Process chunks
        while (currentRow < totalRows) {
            console.log('[DEBUG] Processing chunk, currentRow:', currentRow);
            const chunkResponse = await fetch('/admin/libri/import/librarything/chunk', {
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

            const chunkData = await chunkResponse.json();

            if (!chunkData.success) {
                throw new Error(chunkData.error || '<?= __("Errore durante l\'elaborazione") ?>');
            }

            // Update progress
            currentRow = chunkData.current;
            const percentage = Math.round((currentRow / totalRows) * 100);
            progressBar.style.width = percentage + '%';
            progressStats.textContent = `${currentRow} / ${totalRows} (${percentage}%)`;

            // Check if complete
            if (chunkData.complete) {
                break;
            }
        }

        // Step 3: Get final results
        const resultsResponse = await fetch('/admin/libri/import/librarything/results');
        if (!resultsResponse.ok) {
            throw new Error('<?= __("Errore nel recupero dei risultati") ?>');
        }
        const resultsData = await resultsResponse.json();

        if (resultsData.success && resultsData.redirect) {
            window.location.href = resultsData.redirect;
        } else {
            throw new Error('<?= __("Errore durante il completamento") ?>');
        }

    } catch (error) {
        alert('<?= __("Errore") ?>: ' + error.message);
        submitBtn.disabled = false;
        progressIndicator.classList.add('hidden');
        progressBarContainer.classList.add('hidden');
        progressBar.style.width = '0%';
    }
});
</script>

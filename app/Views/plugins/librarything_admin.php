<?php
$pageTitle = __('Gestione Plugin LibraryThing');
ob_start();

$status = $data['status'] ?? [];
$installed = $status['installed'] ?? false;
$complete = $status['complete'] ?? false;
$fieldsCount = $status['fields_count'] ?? 0;
$expectedFields = $status['expected_fields'] ?? 24;
?>

<div class="min-h-screen bg-gray-50 py-6">
    <div class="max-w-4xl mx-auto px-4 sm:px-6">

        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="flex items-center space-x-2 text-sm">
                <li>
                    <a href="<?= url('/admin/dashboard') ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
                        <i class="fas fa-home mr-1"></i><?= __("Home") ?>
                    </a>
                </li>
                <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
                <li class="text-gray-900 font-medium">
                    <i class="fas fa-plug mr-1"></i><?= __("Plugin LibraryThing") ?>
                </li>
            </ol>
        </nav>

        <!-- Header -->
        <div class="mb-6">
            <h1 class="text-3xl font-bold text-gray-900 flex items-center">
                <i class="fas fa-cloud text-gray-800 mr-3"></i>
                <?= __("Plugin LibraryThing") ?>
            </h1>
            <p class="text-sm text-gray-600 mt-1">
                <?= __("Gestisci l'installazione e la configurazione del plugin LibraryThing") ?>
            </p>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg flex items-start">
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
            <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg flex items-start">
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

        <!-- Status Card -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4 flex items-center">
                <i class="fas fa-info-circle text-gray-800 mr-2"></i>
                <?= __("Stato Plugin") ?>
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="text-sm text-gray-600 mb-1"><?= __("Stato") ?></div>
                    <div class="text-lg font-semibold <?= $installed ? 'text-green-600' : 'text-gray-400' ?>">
                        <?= $installed ? __('Installato') : __('Non Installato') ?>
                        <i class="fas fa-<?= $installed ? 'check-circle' : 'times-circle' ?> ml-2"></i>
                    </div>
                </div>

                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="text-sm text-gray-600 mb-1"><?= __("Campi Database") ?></div>
                    <div class="text-lg font-semibold text-gray-900">
                        <?= $fieldsCount ?> / <?= $expectedFields ?>
                    </div>
                </div>

                <div class="bg-gray-50 rounded-lg p-4">
                    <div class="text-sm text-gray-600 mb-1"><?= __("Completezza") ?></div>
                    <div class="text-lg font-semibold <?= $complete ? 'text-green-600' : 'text-yellow-600' ?>">
                        <?= $complete ? __('Completo') : __('Incompleto') ?>
                    </div>
                </div>
            </div>

            <?php if ($installed && !$complete): ?>
                <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                    <p class="text-yellow-800 text-sm">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <?= __("L'installazione sembra incompleta. Prova a disinstallare e reinstallare il plugin.") ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Actions Card -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4 flex items-center">
                <i class="fas fa-cogs text-gray-800 mr-2"></i>
                <?= __("Azioni") ?>
            </h2>

            <?php if (!$installed): ?>
                <!-- Install Form -->
                <form method="POST" action="<?= url('/admin/plugins/librarything/install') ?>" id="install-form">
                    <input type="hidden" name="csrf_token" value="<?= \App\Support\HtmlHelper::e(\App\Support\Csrf::ensureToken()) ?>">

                    <div class="mb-4">
                        <p class="text-gray-700 mb-4">
                            <?= __("L'installazione del plugin aggiungerà 27 nuovi campi alla tabella 'libri' per supportare tutte le funzionalità di LibraryThing.") ?>
                        </p>

                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-4">
                            <h4 class="font-semibold text-gray-900 mb-2">
                                <i class="fas fa-database mr-2"></i>
                                <?= __("Campi che verranno aggiunti:") ?>
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm text-gray-800">
                                <div>• <?= __("Review, Rating, Comments") ?></div>
                                <div>• <?= __("Physical Description (Weight, Height, etc.)") ?></div>
                                <div>• <?= __("Library Classifications (LCCN, LC, Dewey)") ?></div>
                                <div>• <?= __("Date Tracking (Acquired, Started, Read)") ?></div>
                                <div>• <?= __("Catalog IDs (OCLC, Work ID, ISSN)") ?></div>
                                <div>• <?= __("Lending Status & Patron") ?></div>
                                <div>• <?= __("Financial (Purchase Price, Value, Condition)") ?></div>
                                <div>• <?= __("Source & Acquisition Info") ?></div>
                            </div>
                        </div>

                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                            <p class="text-yellow-800 text-sm">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong><?= __("Importante:") ?></strong>
                                <?= __("Si consiglia di effettuare un backup del database prima dell'installazione.") ?>
                            </p>
                        </div>
                    </div>

                    <button type="submit" class="px-6 py-3 bg-gray-800 text-white hover:bg-black rounded-lg transition-colors font-medium inline-flex items-center">
                        <i class="fas fa-download mr-2"></i>
                        <?= __("Installa Plugin") ?>
                    </button>
                </form>

            <?php else: ?>
                <!-- Uninstall Form -->
                <form method="POST" action="<?= url('/admin/plugins/librarything/uninstall') ?>" id="uninstall-form">
                    <input type="hidden" name="csrf_token" value="<?= \App\Support\HtmlHelper::e(\App\Support\Csrf::ensureToken()) ?>">

                    <div class="mb-4">
                        <p class="text-gray-700 mb-4">
                            <?= __("Il plugin è attualmente installato e funzionante.") ?>
                        </p>

                        <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                            <h4 class="font-semibold text-red-900 mb-2">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <?= __("ATTENZIONE: Disinstallazione Irreversibile") ?>
                            </h4>
                            <p class="text-red-800 text-sm mb-2">
                                <?= __("La disinstallazione rimuoverà tutti i 27 campi LibraryThing dal database.") ?>
                            </p>
                            <p class="text-red-800 text-sm font-semibold">
                                <?= __("TUTTI I DATI IN QUESTI CAMPI VERRANNO ELIMINATI PERMANENTEMENTE!") ?>
                            </p>
                        </div>

                        <div class="flex items-center mb-4">
                            <input type="checkbox" id="confirm-uninstall" required
                                   class="w-4 h-4 text-red-600 bg-gray-100 border-gray-300 rounded focus:ring-red-500">
                            <label for="confirm-uninstall" class="ml-2 text-sm text-gray-700">
                                <?= __("Confermo di voler disinstallare il plugin e di aver effettuato un backup dei dati") ?>
                            </label>
                        </div>
                    </div>

                    <button type="submit" id="uninstall-btn" disabled
                            class="px-6 py-3 bg-red-600 text-white hover:bg-red-700 rounded-lg transition-colors font-medium inline-flex items-center disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fas fa-trash-alt mr-2"></i>
                        <?= __("Disinstalla Plugin") ?>
                    </button>
                </form>

            <?php endif; ?>
        </div>

        <!-- Features Card -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h2 class="text-xl font-semibold mb-4 flex items-center">
                <i class="fas fa-star text-yellow-500 mr-2"></i>
                <?= __("Funzionalità Plugin") ?>
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div class="flex items-start">
                    <i class="fas fa-check text-green-600 mr-2 mt-1"></i>
                    <div>
                        <div class="font-semibold text-gray-900"><?= __("Import/Export TSV") ?></div>
                        <div class="text-gray-600"><?= __("Formato nativo LibraryThing") ?></div>
                    </div>
                </div>

                <div class="flex items-start">
                    <i class="fas fa-check text-green-600 mr-2 mt-1"></i>
                    <div>
                        <div class="font-semibold text-gray-900"><?= __("55+ Campi") ?></div>
                        <div class="text-gray-600"><?= __("Supporto completo LibraryThing") ?></div>
                    </div>
                </div>

                <div class="flex items-start">
                    <i class="fas fa-check text-green-600 mr-2 mt-1"></i>
                    <div>
                        <div class="font-semibold text-gray-900"><?= __("Scraping Integration") ?></div>
                        <div class="text-gray-600"><?= __("Arricchimento automatico dati") ?></div>
                    </div>
                </div>

                <div class="flex items-start">
                    <i class="fas fa-check text-green-600 mr-2 mt-1"></i>
                    <div>
                        <div class="font-semibold text-gray-900"><?= __("Campi Editabili") ?></div>
                        <div class="text-gray-600"><?= __("Modificabili nel form libro") ?></div>
                    </div>
                </div>

                <div class="flex items-start">
                    <i class="fas fa-check text-green-600 mr-2 mt-1"></i>
                    <div>
                        <div class="font-semibold text-gray-900"><?= __("Retrocompatibile") ?></div>
                        <div class="text-gray-600"><?= __("Non modifica funzionalità esistenti") ?></div>
                    </div>
                </div>

                <div class="flex items-start">
                    <i class="fas fa-check text-green-600 mr-2 mt-1"></i>
                    <div>
                        <div class="font-semibold text-gray-900"><?= __("Prestiti Avanzati") ?></div>
                        <div class="text-gray-600"><?= __("Lending status e patron tracking") ?></div>
                    </div>
                </div>
            </div>

            <?php if ($installed): ?>
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <div class="flex gap-3">
                        <a href="<?= url('/admin/libri/import/librarything') ?>"
                           class="px-4 py-2 bg-gray-800 text-white hover:bg-black rounded-lg transition-colors inline-flex items-center text-sm">
                            <i class="fas fa-cloud-upload-alt mr-2"></i>
                            <?= __("Import da LibraryThing") ?>
                        </a>
                        <a href="<?= url('/admin/libri/export/librarything') ?>"
                           class="px-4 py-2 bg-green-600 text-white hover:bg-green-700 rounded-lg transition-colors inline-flex items-center text-sm">
                            <i class="fas fa-cloud-download-alt mr-2"></i>
                            <?= __("Export per LibraryThing") ?>
                        </a>
                        <a href="<?= url('/admin/libri') ?>"
                           class="px-4 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300 rounded-lg transition-colors inline-flex items-center text-sm">
                            <i class="fas fa-book mr-2"></i>
                            <?= __("Gestione Libri") ?>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
// Enable uninstall button when checkbox is checked
document.getElementById('confirm-uninstall')?.addEventListener('change', function() {
    document.getElementById('uninstall-btn').disabled = !this.checked;
});

// Confirmation dialog for uninstall
document.getElementById('uninstall-form')?.addEventListener('submit', function(e) {
    if (!confirm(<?= json_encode(__("Sei assolutamente sicuro? Tutti i dati LibraryThing verranno eliminati!")) ?>)) {
        e.preventDefault();
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout.php';
?>

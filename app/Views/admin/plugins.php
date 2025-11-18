<?php
use App\Support\HtmlHelper;
use App\Support\Csrf;

$pageTitle = __('Gestione Plugin');
$pluginSettings = $pluginSettings ?? [];
?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="mb-8">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900"><?= __("Plugin") ?></h1>
                <p class="mt-2 text-sm text-gray-600"><?= __("Gestisci le estensioni dell'applicazione") ?></p>
            </div>
            <button onclick="openUploadModal()" class="inline-flex items-center px-6 py-3 bg-black text-white rounded-xl hover:bg-gray-800 transition-all duration-200 shadow-md hover:shadow-lg">
                <i class="fas fa-upload mr-2"></i>
                <?= __("Carica Plugin") ?>
            </button>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600"><?= __("Plugin Totali") ?></p>
                    <p class="text-3xl font-bold text-gray-900 mt-2"><?= count($plugins) ?></p>
                </div>
                <div class="w-12 h-12 bg-gray-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-puzzle-piece text-gray-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600"><?= __("Plugin Attivi") ?></p>
                    <p class="text-3xl font-bold text-green-600 mt-2">
                        <?= count(array_filter($plugins, fn($p) => $p['is_active'])) ?>
                    </p>
                </div>
                <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-check-circle text-green-600 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600"><?= __("Plugin Inattivi") ?></p>
                    <p class="text-3xl font-bold text-gray-400 mt-2">
                        <?= count(array_filter($plugins, fn($p) => !$p['is_active'])) ?>
                    </p>
                </div>
                <div class="w-12 h-12 bg-gray-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-pause-circle text-gray-400 text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Plugins List -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div class="p-6 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900"><?= __("Plugin Installati") ?></h2>
        </div>

        <div class="divide-y divide-gray-200">
            <?php if (empty($plugins)): ?>
                <div class="p-12 text-center">
                    <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-puzzle-piece text-gray-400 text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2"><?= __("Nessun plugin installato") ?></h3>
                    <p class="text-gray-600 mb-6"><?= __("Inizia caricando il tuo primo plugin") ?></p>
                    <button onclick="openUploadModal()" class="inline-flex items-center px-6 py-3 bg-black text-white rounded-xl hover:bg-gray-800 transition-all duration-200">
                        <i class="fas fa-upload mr-2"></i>
                        <?= __("Carica Plugin") ?>
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($plugins as $plugin): ?>
                    <?php
                        $isOpenLibrary = $plugin['name'] === 'open-library';
                        $isApiBookScraper = $plugin['name'] === 'api-book-scraper';
                        $openLibrarySettings = $isOpenLibrary ? ($pluginSettings[$plugin['id']] ?? []) : [];
                        $apiBookScraperSettings = $isApiBookScraper ? ($pluginSettings[$plugin['id']] ?? []) : [];
                        $hasGoogleKey = $isOpenLibrary && !empty($openLibrarySettings['google_books_api_key_exists'] ?? false);
                        $hasApiConfig = $isApiBookScraper && !empty($apiBookScraperSettings['api_endpoint'] ?? false) && !empty($apiBookScraperSettings['api_key_exists'] ?? false);
                        $isApiEnabled = $isApiBookScraper && !empty($apiBookScraperSettings['enabled'] ?? false);
                    ?>
                    <div class="p-6 hover:bg-gray-50 transition-colors" data-plugin-id="<?= $plugin['id'] ?>">
                        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                            <!-- Plugin Info -->
                            <div class="flex-1">
                                <div class="flex items-start gap-4">
                                    <div class="hidden lg:flex w-12 h-12 bg-gradient-to-br from-gray-100 to-gray-200 rounded-xl items-center justify-center flex-shrink-0">
                                        <i class="fas fa-puzzle-piece text-gray-600 text-xl"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-3 mb-2">
                                            <h3 class="text-lg font-semibold text-gray-900">
                                                <?= HtmlHelper::e($plugin['display_name']) ?>
                                            </h3>
                                            <span class="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-700 rounded-lg">
                                                v<?= HtmlHelper::e($plugin['version']) ?>
                                            </span>
                                            <?php if ($plugin['is_active']): ?>
                                                <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-700 rounded-lg">
                                                    <i class="fas fa-check-circle mr-1"></i><?= __("Attivo") ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-500 rounded-lg">
                                                    <i class="fas fa-pause-circle mr-1"></i><?= __("Inattivo") ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-sm text-gray-600 mb-3">
                                            <?= HtmlHelper::e($plugin['description'] ?? __('Nessuna descrizione disponibile')) ?>
                                        </p>
                                        <?php if ($isOpenLibrary && $hasGoogleKey): ?>
                                            <div class="mb-3">
                                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-green-100 text-green-800 text-xs font-semibold rounded-lg border border-green-200">
                                                    <i class="fas fa-check-circle"></i>
                                                    <?= __("Google Books API collegata") ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($isApiBookScraper && $hasApiConfig): ?>
                                            <div class="mb-3 flex flex-wrap gap-2">
                                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-100 text-blue-800 text-xs font-semibold rounded-lg border border-blue-200">
                                                    <i class="fas fa-link"></i>
                                                    <?= __("API configurata") ?>
                                                </span>
                                                <?php if ($isApiEnabled): ?>
                                                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-green-100 text-green-800 text-xs font-semibold rounded-lg border border-green-200">
                                                        <i class="fas fa-check-circle"></i>
                                                        <?= __("Abilitato") ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-orange-100 text-orange-800 text-xs font-semibold rounded-lg border border-orange-200">
                                                        <i class="fas fa-pause-circle"></i>
                                                        <?= __("Disabilitato") ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="flex flex-wrap items-center gap-4 text-xs text-gray-500">
                                            <?php if ($plugin['author']): ?>
                                                <span>
                                                    <i class="fas fa-user mr-1"></i>
                                                    <?php if ($plugin['author_url']): ?>
                                                        <a href="<?= HtmlHelper::e($plugin['author_url']) ?>" target="_blank" class="hover:text-gray-700 underline">
                                                            <?= HtmlHelper::e($plugin['author']) ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <?= HtmlHelper::e($plugin['author']) ?>
                                                    <?php endif; ?>
                                                </span>
                                            <?php endif; ?>
                                            <span>
                                                <i class="fas fa-calendar mr-1"></i>
                                                <?= __("Installato:") ?> <?= date('d/m/Y', strtotime($plugin['installed_at'])) ?>
                                            </span>
                                            <?php if ($plugin['activated_at']): ?>
                                                <span>
                                                    <i class="fas fa-bolt mr-1"></i>
                                                    <?= __("Attivato:") ?> <?= date('d/m/Y', strtotime($plugin['activated_at'])) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <?php if ($isOpenLibrary): ?>
                                    <?php if ($hasGoogleKey): ?>
                                        <button type="button"
                                                class="px-4 py-2 bg-green-100 text-green-700 rounded-lg hover:bg-green-200 transition-all duration-200 text-sm font-medium"
                                                data-plugin-id="<?= $plugin['id'] ?>"
                                                data-plugin-name="<?= HtmlHelper::e($plugin['display_name']) ?>"
                                                data-plugin-type="open-library"
                                                data-has-key="1"
                                                onclick="openPluginSettingsModal(this)">
                                            <i class="fas fa-check-circle mr-1"></i>
                                            <?= __("Google Books Configurato") ?>
                                        </button>
                                    <?php else: ?>
                                        <button type="button"
                                                class="px-4 py-2 bg-orange-100 text-orange-700 rounded-lg hover:bg-orange-200 transition-all duration-200 text-sm font-medium"
                                                data-plugin-id="<?= $plugin['id'] ?>"
                                                data-plugin-name="<?= HtmlHelper::e($plugin['display_name']) ?>"
                                                data-plugin-type="open-library"
                                                data-has-key="0"
                                                onclick="openPluginSettingsModal(this)">
                                            <i class="fas fa-exclamation-triangle mr-1"></i>
                                            <?= __("Configura Google Books") ?>
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($isApiBookScraper): ?>
                                    <button type="button"
                                            class="px-4 py-2 <?= $hasApiConfig ? 'bg-blue-100 text-blue-700 hover:bg-blue-200' : 'bg-orange-100 text-orange-700 hover:bg-orange-200' ?> rounded-lg transition-all duration-200 text-sm font-medium"
                                            data-plugin-id="<?= $plugin['id'] ?>"
                                            data-plugin-name="<?= HtmlHelper::e($plugin['display_name']) ?>"
                                            data-plugin-type="api-book-scraper"
                                            data-has-config="<?= $hasApiConfig ? '1' : '0' ?>"
                                            data-api-endpoint="<?= HtmlHelper::e($apiBookScraperSettings['api_endpoint'] ?? '') ?>"
                                            data-timeout="<?= HtmlHelper::e($apiBookScraperSettings['timeout'] ?? '10') ?>"
                                            data-enabled="<?= $isApiEnabled ? '1' : '0' ?>"
                                            onclick="openApiBookScraperModal(this)">
                                        <i class="fas <?= $hasApiConfig ? 'fa-cog' : 'fa-exclamation-triangle' ?> mr-1"></i>
                                        <?= __("Configura API") ?>
                                    </button>
                                <?php endif; ?>
                                <?php if ($plugin['is_active']): ?>
                                    <button onclick="deactivatePlugin(<?= $plugin['id'] ?>)"
                                            class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-all duration-200 text-sm font-medium">
                                        <i class="fas fa-pause mr-1"></i>
                                        <?= __("Disattiva") ?>
                                    </button>
                                <?php else: ?>
                                    <button onclick="activatePlugin(<?= $plugin['id'] ?>)"
                                            class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-all duration-200 text-sm font-medium">
                                        <i class="fas fa-check mr-1"></i>
                                        <?= __("Attiva") ?>
                                    </button>
                                <?php endif; ?>

                                <button onclick="showPluginDetails(<?= $plugin['id'] ?>)"
                                        class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-all duration-200 text-sm font-medium">
                                    <i class="fas fa-info-circle"></i>
                                </button>

                                <button onclick="uninstallPlugin(<?= $plugin['id'] ?>, '<?= HtmlHelper::e($plugin['display_name']) ?>')"
                                        class="px-4 py-2 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition-all duration-200 text-sm font-medium">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Upload Modal -->
<div id="uploadModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-hidden">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-xl font-semibold text-gray-900"><?= __("Carica Plugin") ?></h3>
            <button onclick="closeUploadModal()" class="p-2 hover:bg-gray-100 rounded-lg transition-colors">
                <i class="fas fa-times text-gray-500"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="p-6">
            <div class="mb-6">
                <p class="text-sm text-gray-600 mb-4">
                    <?= __("Carica un file ZIP contenente il plugin. Il file deve includere un %s con le informazioni del plugin.", '<code class="px-2 py-1 bg-gray-100 rounded text-xs">plugin.json</code>') ?>
                </p>
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <div class="flex gap-3">
                        <i class="fas fa-info-circle text-blue-600 mt-0.5"></i>
                        <div class="text-sm text-blue-800">
                            <p class="font-medium mb-1"><?= __("Requisiti del plugin:") ?></p>
                            <ul class="list-disc list-inside space-y-1 text-xs">
                                <li><?= __("File ZIP con struttura plugin valida") ?></li>
                                <li><?= __("File %s nella directory root", '<code>plugin.json</code>') ?></li>
                                <li><?= __("File principale PHP specificato in %s", '<code>plugin.json</code>') ?></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Upload Area -->
            <form id="uploadForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= Csrf::ensureToken() ?>">

                <div id="uppy-dashboard"></div>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" onclick="closeUploadModal()" class="px-6 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-all duration-200 font-medium">
                        <?= __("Annulla") ?>
                    </button>
                    <button type="button" id="uploadButton" class="px-6 py-3 bg-black text-white rounded-xl hover:bg-gray-800 transition-all duration-200 font-medium disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fas fa-upload mr-2"></i>
                        <?= __("Installa Plugin") ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Plugin Settings Modal -->
<div id="pluginSettingsModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xl">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 id="pluginSettingsTitle" class="text-lg font-semibold text-gray-900"><?= __("Google Books API") ?></h3>
            <button id="pluginSettingsCloseButton" type="button" class="p-2 hover:bg-gray-100 rounded-lg transition-colors" onclick="closePluginSettingsModal()">
                <i class="fas fa-times text-gray-500"></i>
            </button>
        </div>
        <div class="p-6">
            <!-- Status Badge -->
            <div id="pluginSettingsStatusBadge" class="hidden mb-4 rounded-xl border border-green-200 bg-green-50 p-4">
                <div class="flex items-start gap-3">
                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-green-500 text-white flex-shrink-0">
                        <i class="fas fa-check"></i>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-green-900">
                            <?= __("API Key già configurata") ?>
                        </p>
                        <p class="mt-1 text-xs text-green-700">
                            <?= __("Una chiave è attualmente salvata e funzionante. Puoi aggiornarla inserendo un nuovo valore o lasciarla vuota per rimuoverla.") ?>
                        </p>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-indigo-100 bg-indigo-50/70 p-4">
                <p class="text-sm text-indigo-900">
                    <?= __("Aggiungi la tua API key per interrogare Google Books quando importi un ISBN. Google viene utilizzato prima di Open Library, ma dopo Scraping Pro.") ?>
                </p>
            </div>
            <form id="pluginSettingsForm" class="mt-6 space-y-4" onsubmit="saveGoogleBooksKey(event)">
                <input type="hidden" id="pluginSettingsPluginId">
                <div>
                    <label for="googleBooksKeyInput" class="block text-xs font-medium text-indigo-900/80">
                        <?= __("Chiave API Google Books") ?>
                    </label>
                    <input
                        id="googleBooksKeyInput"
                        type="password"
                        autocomplete="off"
                        class="mt-2 w-full rounded-xl border border-indigo-200 bg-white px-4 py-2.5 text-sm text-gray-900 placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-200"
                        placeholder="AIza..."
                    >
                    <p id="pluginSettingsHelper" class="mt-2 text-xs text-gray-600"><?= __("Se non imposti la chiave, il plugin utilizzerà esclusivamente Open Library.") ?></p>
                </div>
                <div class="flex items-center justify-between">
                    <a href="https://console.cloud.google.com/apis/library/books.googleapis.com" target="_blank" class="inline-flex items-center gap-2 text-sm font-medium text-indigo-700 hover:text-indigo-900">
                        <i class="fas fa-external-link-alt text-xs"></i>
                        <?= __("Apri Google Cloud Console") ?>
                    </a>
                    <div class="flex gap-3">
                        <button type="button" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-all text-sm font-medium" onclick="closePluginSettingsModal()">
                            <?= __("Chiudi") ?>
                        </button>
                        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-black px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 transition disabled:opacity-60" data-role="save-key" data-label="<?= __("Salva API Key") ?>">
                            <i class="fas fa-save"></i>
                            <?= __("Salva API Key") ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- API Book Scraper Settings Modal -->
<div id="apiBookScraperModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between sticky top-0 bg-white">
            <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                <i class="fas fa-cloud-download-alt text-blue-600"></i>
                API Book Scraper - <?= __("Configurazione") ?>
            </h3>
            <button type="button" class="p-2 hover:bg-gray-100 rounded-lg transition-colors" onclick="closeApiBookScraperModal()">
                <i class="fas fa-times text-gray-500"></i>
            </button>
        </div>
        <form id="apiBookScraperForm" class="p-6 space-y-5" onsubmit="saveApiBookScraperSettings(event)">
            <input type="hidden" id="apiScraperPluginId">

            <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
                <div class="flex items-start gap-3">
                    <i class="fas fa-info-circle text-blue-600 mt-0.5"></i>
                    <div class="text-xs text-blue-800">
                        <p class="font-semibold mb-1"><?= __("Plugin API personalizzata per scraping dati libri") ?></p>
                        <p><?= __("Questo plugin interroga un servizio API esterno per recuperare dati libri tramite ISBN/EAN. Ha priorità 3 (più alta di Open Library).") ?></p>
                    </div>
                </div>
            </div>

            <div>
                <label for="apiEndpointInput" class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-link mr-1"></i>
                    <?= __("URL Endpoint API") ?> *
                </label>
                <input type="url"
                       id="apiEndpointInput"
                       required
                       class="block w-full rounded-xl border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm py-3 px-4 font-mono"
                       placeholder="https://api.example.com/books/{isbn}">
                <p class="mt-2 text-xs text-gray-600">
                    <i class="fas fa-lightbulb mr-1"></i>
                    <?= __("Usa {isbn} come placeholder. Es: https://api.example.com/books/{isbn}") ?>
                </p>
            </div>

            <div>
                <label for="apiKeyInput" class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-key mr-1"></i>
                    <?= __("API Key") ?> *
                </label>
                <div class="relative">
                    <input type="password"
                           id="apiKeyInput"
                           required
                           autocomplete="off"
                           class="block w-full rounded-xl border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm py-3 px-4 pr-10 font-mono"
                           placeholder="sk_live_xxxxxxxxxx">
                    <button type="button"
                            onclick="toggleApiKeyVisibility()"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700">
                        <i id="apiKeyIcon" class="fas fa-eye"></i>
                    </button>
                </div>
                <p class="mt-2 text-xs text-gray-600">
                    <i class="fas fa-shield-alt mr-1"></i>
                    <?= __("L'API key viene criptata con AES-256-GCM prima di essere salvata.") ?>
                </p>
            </div>

            <div>
                <label for="apiTimeoutInput" class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-clock mr-1"></i>
                    <?= __("Timeout (secondi)") ?>
                </label>
                <div class="flex items-center gap-4">
                    <input type="number"
                           id="apiTimeoutInput"
                           min="5"
                           max="60"
                           value="10"
                           class="block w-32 rounded-xl border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm py-3 px-4 text-center">
                    <span class="text-sm text-gray-600"><?= __("secondi (min: 5, max: 60)") ?></span>
                </div>
            </div>

            <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                <label class="inline-flex items-center gap-3 cursor-pointer">
                    <input type="checkbox"
                           id="apiEnabledInput"
                           value="1"
                           class="w-5 h-5 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                    <div>
                        <span class="text-sm font-semibold text-gray-900">
                            <i class="fas fa-power-off mr-1"></i>
                            <?= __("Abilita Plugin") ?>
                        </span>
                        <p class="text-xs text-gray-600 mt-1">
                            <?= __("Quando abilitato, il plugin interrogherà l'API durante l'importazione dati libri.") ?>
                        </p>
                    </div>
                </label>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t">
                <button type="button"
                        onclick="closeApiBookScraperModal()"
                        class="px-6 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-all text-sm font-medium">
                    <?= __("Annulla") ?>
                </button>
                <button type="submit"
                        class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white rounded-xl hover:bg-blue-700 transition-all text-sm font-semibold">
                    <i class="fas fa-save"></i>
                    <?= __("Salva Configurazione") ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Uppy is loaded from vendor.bundle.js (self-hosted, no CDN) -->
<script>
const csrfToken = '<?= Csrf::ensureToken() ?>';
const googleBooksModalTexts = {
    titleSuffix: '<?= addslashes(__("Google Books API")) ?>',
    hasKey: '<?= addslashes(__("Una chiave è già salvata. Inserisci un nuovo valore per aggiornarla oppure lascia vuoto per rimuoverla.")) ?>',
    noKey: '<?= addslashes(__("Se non imposti la chiave, il plugin utilizzerà esclusivamente Open Library.")) ?>'
};
const pluginSettingsModal = document.getElementById('pluginSettingsModal');
const pluginSettingsTitle = document.getElementById('pluginSettingsTitle');
const pluginSettingsHelper = document.getElementById('pluginSettingsHelper');
const pluginSettingsPluginIdInput = document.getElementById('pluginSettingsPluginId');
const googleBooksKeyInput = document.getElementById('googleBooksKeyInput');
const apiBookScraperModal = document.getElementById('apiBookScraperModal');
let uppyInstance = null;
let selectedFile = null;

// Initialize Uppy
function initUppy() {
    if (uppyInstance) {
        return;
    }

    // Use self-hosted Uppy from window globals
    const { Uppy } = window;

    uppyInstance = new Uppy({
        restrictions: {
            maxNumberOfFiles: 1,
            allowedFileTypes: ['.zip']
        },
        autoProceed: false
    });

    uppyInstance.use(window.UppyDashboard, {
        target: '#uppy-dashboard',
        inline: true,
        height: 300,
        hideUploadButton: true,
        proudlyDisplayPoweredByUppy: false,
        locale: {
            strings: {
                dropPasteFiles: '<?= addslashes(__("Trascina qui il file ZIP del plugin o %{browse}")) ?>',
                browse: '<?= addslashes(__("seleziona")) ?>',
                uploadComplete: '<?= addslashes(__("Caricamento completato")) ?>',
                uploadFailed: '<?= addslashes(__("Caricamento fallito")) ?>',
                complete: '<?= addslashes(__("Completato")) ?>',
                uploading: '<?= addslashes(__("Caricamento in corso...")) ?>',
                error: '<?= addslashes(__("Errore")) ?>',
            }
        }
    });

    uppyInstance.on('file-added', (file) => {
        selectedFile = file;
        document.getElementById('uploadButton').disabled = false;
    });

    uppyInstance.on('file-removed', (file) => {
        selectedFile = null;
        document.getElementById('uploadButton').disabled = true;
    });
}

function openUploadModal() {
    document.getElementById('uploadModal').classList.remove('hidden');
    initUppy();
}

function closeUploadModal() {
    document.getElementById('uploadModal').classList.add('hidden');
    if (uppyInstance) {
        uppyInstance.cancelAll(); // Remove all files
        uppyInstance = null;
    }
    selectedFile = null;
}

document.getElementById('uploadButton')?.addEventListener('click', async function() {
    if (!selectedFile) {
        Swal.fire({
            icon: 'error',
            title: '<?= addslashes(__("Errore")) ?>',
            text: '<?= addslashes(__("Seleziona un file ZIP del plugin.")) ?>'
        });
        return;
    }

    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('plugin_file', selectedFile.data);

    this.disabled = true;
    this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i><?= addslashes(__("Installazione in corso...")) ?>';

    console.log('📤 Sending plugin upload request...', {
        file: selectedFile.name,
        size: selectedFile.data.size
    });

    try {
        const response = await fetch('/admin/plugins/upload', {
            method: 'POST',
            body: formData
        });

        console.log('📥 Response status:', response.status);

        const result = await response.json();
        console.log('📦 Response data:', result);

        if (result.success) {
            await Swal.fire({
                icon: 'success',
                title: '<?= addslashes(__("Successo")) ?>',
                text: result.message
            });
            window.location.reload();
        } else {
            await Swal.fire({
                icon: 'error',
                title: '<?= addslashes(__("Errore")) ?>',
                text: result.message
            });
        }
    } catch (error) {
        await Swal.fire({
            icon: 'error',
            title: '<?= addslashes(__("Errore")) ?>',
            text: '<?= addslashes(__("Errore durante l\'installazione del plugin.")) ?>'
        });
    } finally {
        this.disabled = false;
        this.innerHTML = '<i class="fas fa-upload mr-2"></i><?= addslashes(__("Installa Plugin")) ?>';
    }
});

async function activatePlugin(pluginId) {
    const result = await Swal.fire({
        title: '<?= addslashes(__("Conferma")) ?>',
        text: '<?= addslashes(__("Vuoi attivare questo plugin?")) ?>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '<?= addslashes(__("Sì, attiva")) ?>',
        cancelButtonText: '<?= addslashes(__("Annulla")) ?>',
        confirmButtonColor: '#000000'
    });

    if (!result.isConfirmed) {
        return;
    }

    try {
        const formData = new FormData();
        formData.append('csrf_token', csrfToken);

        const response = await fetch(`/admin/plugins/${pluginId}/activate`, {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            await Swal.fire({
                icon: 'success',
                title: '<?= addslashes(__("Successo")) ?>',
                text: data.message
            });
            window.location.reload();
        } else {
            await Swal.fire({
                icon: 'error',
                title: '<?= addslashes(__("Errore")) ?>',
                text: data.message
            });
        }
    } catch (error) {
        await Swal.fire({
            icon: 'error',
            title: '<?= addslashes(__("Errore")) ?>',
            text: '<?= addslashes(__("Errore durante l\'attivazione del plugin.")) ?>'
        });
    }
}

async function deactivatePlugin(pluginId) {
    const result = await Swal.fire({
        title: '<?= addslashes(__("Conferma")) ?>',
        text: '<?= addslashes(__("Vuoi disattivare questo plugin?")) ?>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '<?= addslashes(__("Sì, disattiva")) ?>',
        cancelButtonText: '<?= addslashes(__("Annulla")) ?>',
        confirmButtonColor: '#6b7280'
    });

    if (!result.isConfirmed) {
        return;
    }

    try {
        const formData = new FormData();
        formData.append('csrf_token', csrfToken);

        const response = await fetch(`/admin/plugins/${pluginId}/deactivate`, {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            await Swal.fire({
                icon: 'success',
                title: '<?= addslashes(__("Successo")) ?>',
                text: data.message
            });
            window.location.reload();
        } else {
            await Swal.fire({
                icon: 'error',
                title: '<?= addslashes(__("Errore")) ?>',
                text: data.message
            });
        }
    } catch (error) {
        await Swal.fire({
            icon: 'error',
            title: '<?= addslashes(__("Errore")) ?>',
            text: '<?= addslashes(__("Errore durante la disattivazione del plugin.")) ?>'
        });
    }
}

async function uninstallPlugin(pluginId, pluginName) {
    const result = await Swal.fire({
        title: '<?= addslashes(__("Conferma Disinstallazione")) ?>',
        html: `<?= addslashes(__("Sei sicuro di voler disinstallare")) ?> <strong>${pluginName}</strong>?<br><br>
               <span class="text-sm text-red-600"><?= addslashes(__("Questa azione eliminerà tutti i dati del plugin e non può essere annullata.")) ?></span>`,
        icon: 'error',
        showCancelButton: true,
        confirmButtonText: '<?= addslashes(__("Sì, disinstalla")) ?>',
        cancelButtonText: '<?= addslashes(__("Annulla")) ?>',
        confirmButtonColor: '#dc2626'
    });

    if (!result.isConfirmed) {
        return;
    }

    try {
        const formData = new FormData();
        formData.append('csrf_token', csrfToken);

        const response = await fetch(`/admin/plugins/${pluginId}/uninstall`, {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            await Swal.fire({
                icon: 'success',
                title: '<?= addslashes(__("Successo")) ?>',
                text: data.message
            });
            window.location.reload();
        } else {
            await Swal.fire({
                icon: 'error',
                title: '<?= addslashes(__("Errore")) ?>',
                text: data.message
            });
        }
    } catch (error) {
        await Swal.fire({
            icon: 'error',
            title: '<?= addslashes(__("Errore")) ?>',
            text: '<?= addslashes(__("Errore durante la disinstallazione del plugin.")) ?>'
        });
    }
}

async function showPluginDetails(pluginId) {
    try {
        const response = await fetch(`/admin/plugins/${pluginId}/details`);
        const data = await response.json();

        if (data.success) {
            const plugin = data.plugin;
            let metadata = '';

            if (plugin.metadata && Object.keys(plugin.metadata).length > 0) {
                metadata = '<div class="mt-4 text-sm"><strong><?= addslashes(__("Metadati:")) ?></strong><pre class="bg-gray-100 p-2 rounded mt-2 text-xs overflow-auto">' +
                           JSON.stringify(plugin.metadata, null, 2) + '</pre></div>';
            }

            await Swal.fire({
                title: plugin.display_name,
                html: `
                    <div class="text-left text-sm space-y-2">
                        <p><strong><?= addslashes(__("Nome:")) ?></strong> ${plugin.name}</p>
                        <p><strong><?= addslashes(__("Versione:")) ?></strong> ${plugin.version}</p>
                        ${plugin.author ? `<p><strong><?= addslashes(__("Autore:")) ?></strong> ${plugin.author}</p>` : ''}
                        ${plugin.description ? `<p><strong><?= addslashes(__("Descrizione:")) ?></strong> ${plugin.description}</p>` : ''}
                        ${plugin.requires_php ? `<p><strong><?= addslashes(__("Richiede PHP:")) ?></strong> ${plugin.requires_php}+</p>` : ''}
                        ${plugin.requires_app ? `<p><strong><?= addslashes(__("Richiede App:")) ?></strong> ${plugin.requires_app}+</p>` : ''}
                        <p><strong><?= addslashes(__("File Principale:")) ?></strong> ${plugin.main_file}</p>
                        <p><strong><?= addslashes(__("Percorso:")) ?></strong> ${plugin.path}</p>
                        ${metadata}
                    </div>
                `,
                width: 600,
                confirmButtonColor: '#000000'
            });
        }
    } catch (error) {
        await Swal.fire({
            icon: 'error',
            title: '<?= addslashes(__("Errore")) ?>',
            text: '<?= addslashes(__("Errore durante il caricamento dei dettagli del plugin.")) ?>'
        });
    }
}

function openPluginSettingsModal(triggerButton) {
    const { pluginId, pluginName, hasKey } = triggerButton.dataset;
    const hasApiKey = hasKey === '1';

    pluginSettingsPluginIdInput.value = pluginId || '';
    googleBooksKeyInput.value = '';
    pluginSettingsTitle.textContent = `${pluginName} — ${googleBooksModalTexts.titleSuffix}`;
    pluginSettingsHelper.textContent = hasApiKey ? googleBooksModalTexts.hasKey : googleBooksModalTexts.noKey;

    // Show/hide status badge
    const statusBadge = document.getElementById('pluginSettingsStatusBadge');
    if (statusBadge) {
        if (hasApiKey) {
            statusBadge.classList.remove('hidden');
        } else {
            statusBadge.classList.add('hidden');
        }
    }

    pluginSettingsModal.classList.remove('hidden');
    setTimeout(() => googleBooksKeyInput.focus(), 100);
}

function closePluginSettingsModal() {
    pluginSettingsModal.classList.add('hidden');
    pluginSettingsPluginIdInput.value = '';
    googleBooksKeyInput.value = '';
}

async function saveGoogleBooksKey(event) {
    event.preventDefault();
    console.log('🔑 saveGoogleBooksKey() called');

    const pluginId = pluginSettingsPluginIdInput.value;
    console.log('📋 Plugin ID:', pluginId);

    if (!pluginId) {
        console.error('❌ No plugin ID found');
        return;
    }

    const apiKey = googleBooksKeyInput.value.trim();
    console.log('🔐 API Key length:', apiKey.length);

    const submitButton = event.target.querySelector('[data-role="save-key"]');
    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('settings[google_books_api_key]', apiKey);
    let buttonLabel = '';

    if (submitButton) {
        buttonLabel = submitButton.dataset.label || submitButton.textContent.trim();
        submitButton.disabled = true;
        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    }

    try {
        console.log('📤 Sending request to:', `/admin/plugins/${pluginId}/settings`);

        const response = await fetch(`/admin/plugins/${pluginId}/settings`, {
            method: 'POST',
            body: formData
        });

        console.log('📥 Response status:', response.status);

        const data = await response.json();
        console.log('📦 Response data:', data);

        if (data.success) {
            await Swal.fire({
                icon: 'success',
                title: '<?= addslashes(__("Successo")) ?>',
                text: '<?= addslashes(__("Chiave Google Books aggiornata.")) ?>'
            });
            closePluginSettingsModal();
            // Reload to show updated status
            setTimeout(() => window.location.reload(), 1000);
        } else {
            await Swal.fire({
                icon: 'error',
                title: '<?= addslashes(__("Errore")) ?>',
                text: data.message || '<?= addslashes(__("Impossibile aggiornare la chiave Google Books.")) ?>'
            });
        }
    } catch (error) {
        console.error('💥 Error:', error);
        await Swal.fire({
            icon: 'error',
            title: '<?= addslashes(__("Errore")) ?>',
            text: '<?= addslashes(__("Errore durante l\'aggiornamento della chiave Google Books.")) ?>'
        });
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.innerHTML = `<i class="fas fa-save"></i> ${buttonLabel}`;
        }
    }
}

// ============================================================================
// API Book Scraper Modal Functions
// ============================================================================

function openApiBookScraperModal(button) {
    const pluginId = button.dataset.pluginId;
    const apiEndpoint = button.dataset.apiEndpoint || '';
    const timeout = button.dataset.timeout || '10';
    const enabled = button.dataset.enabled === '1';

    // Populate form fields
    document.getElementById('apiScraperPluginId').value = pluginId;
    document.getElementById('apiEndpointInput').value = apiEndpoint;
    document.getElementById('apiKeyInput').value = ''; // Always empty for security
    document.getElementById('apiTimeoutInput').value = timeout;
    document.getElementById('apiEnabledInput').checked = enabled;

    // Show modal
    document.getElementById('apiBookScraperModal').classList.remove('hidden');
    setTimeout(() => document.getElementById('apiEndpointInput').focus(), 100);
}

function closeApiBookScraperModal() {
    // Hide modal
    document.getElementById('apiBookScraperModal').classList.add('hidden');

    // Reset form
    document.getElementById('apiScraperPluginId').value = '';
    document.getElementById('apiEndpointInput').value = '';
    document.getElementById('apiKeyInput').value = '';
    document.getElementById('apiTimeoutInput').value = '10';
    document.getElementById('apiEnabledInput').checked = false;
}

function toggleApiKeyVisibility() {
    const input = document.getElementById('apiKeyInput');
    const icon = document.getElementById('apiKeyIcon');

    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

async function saveApiBookScraperSettings(event) {
    event.preventDefault();
    console.log('🔧 saveApiBookScraperSettings() called');

    const pluginId = document.getElementById('apiScraperPluginId').value;
    console.log('📋 Plugin ID:', pluginId);

    if (!pluginId) {
        console.error('❌ No plugin ID found');
        return;
    }

    const apiEndpoint = document.getElementById('apiEndpointInput').value.trim();
    const apiKey = document.getElementById('apiKeyInput').value.trim();
    const timeout = document.getElementById('apiTimeoutInput').value;
    const enabled = document.getElementById('apiEnabledInput').checked ? '1' : '0';

    console.log('🔐 Settings:', { apiEndpoint, timeout, enabled, hasApiKey: apiKey.length > 0 });

    const submitButton = event.target.querySelector('button[type="submit"]');
    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('settings[api_endpoint]', apiEndpoint);
    formData.append('settings[api_key]', apiKey);
    formData.append('settings[timeout]', timeout);
    formData.append('settings[enabled]', enabled);

    let buttonLabel = '';
    if (submitButton) {
        buttonLabel = submitButton.textContent.trim();
        submitButton.disabled = true;
        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    }

    try {
        console.log('📤 Sending request to:', `/admin/plugins/${pluginId}/settings`);

        const response = await fetch(`/admin/plugins/${pluginId}/settings`, {
            method: 'POST',
            body: formData
        });

        console.log('📥 Response status:', response.status);

        const data = await response.json();
        console.log('📦 Response data:', data);

        if (data.success) {
            await Swal.fire({
                icon: 'success',
                title: '<?= addslashes(__("Successo")) ?>',
                text: '<?= addslashes(__("Impostazioni API Book Scraper salvate correttamente.")) ?>'
            });
            closeApiBookScraperModal();
            // Reload to show updated status
            setTimeout(() => window.location.reload(), 1000);
        } else {
            await Swal.fire({
                icon: 'error',
                title: '<?= addslashes(__("Errore")) ?>',
                text: data.message || '<?= addslashes(__("Impossibile salvare le impostazioni.")) ?>'
            });
        }
    } catch (error) {
        console.error('💥 Error:', error);
        await Swal.fire({
            icon: 'error',
            title: '<?= addslashes(__("Errore")) ?>',
            text: '<?= addslashes(__("Errore durante il salvataggio delle impostazioni.")) ?>'
        });
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.innerHTML = `<i class="fas fa-save"></i> ${buttonLabel}`;
        }
    }
}
</script>

<?php
/** @var array $plugins */
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
            <button onclick="openUploadModal()"
                class="inline-flex items-center px-6 py-3 bg-black text-white rounded-xl hover:bg-gray-800 transition-all duration-200 shadow-md hover:shadow-lg">
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
                    <button onclick="openUploadModal()"
                        class="inline-flex items-center px-6 py-3 bg-black text-white rounded-xl hover:bg-gray-800 transition-all duration-200">
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
                    <div class="p-6 hover:bg-gray-50 transition-colors" data-plugin-id="<?= (int)$plugin['id'] ?>">
                        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                            <!-- Plugin Info -->
                            <div class="flex-1">
                                <div class="flex items-start gap-4">
                                    <div
                                        class="hidden lg:flex w-12 h-12 bg-gradient-to-br from-gray-100 to-gray-200 rounded-xl items-center justify-center flex-shrink-0">
                                        <i class="fas fa-puzzle-piece text-gray-600 text-xl"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex flex-wrap items-center gap-2 mb-2">
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
                                        <?php if ($plugin['name'] === 'z39-server'): ?>
                                            <div class="mb-3 p-2 bg-gray-50 rounded-lg border border-gray-200 inline-block">
                                                <p class="text-xs text-gray-500 font-medium mb-1"><?= __("Endpoint SRU:") ?></p>
                                                <div class="flex items-center gap-2">
                                                    <code
                                                        class="text-xs bg-white px-2 py-1 rounded border border-gray-200 select-all"><?= htmlspecialchars(absoluteUrl('/api/sru'), ENT_QUOTES, 'UTF-8') ?></code>
                                                    <a href="<?= htmlspecialchars(absoluteUrl('/api/sru') . '?operation=explain&version=1.1', ENT_QUOTES, 'UTF-8') ?>"
                                                        target="_blank" rel="noopener noreferrer"
                                                        class="text-indigo-600 hover:text-indigo-800 text-xs"
                                                        title="<?= __("Test Endpoint") ?>">
                                                        <i class="fas fa-external-link-alt"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($isOpenLibrary && $hasGoogleKey): ?>
                                            <div class="mb-3">
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-green-100 text-green-800 text-xs font-semibold rounded-lg border border-green-200">
                                                    <i class="fas fa-check-circle"></i>
                                                    <?= __("Google Books API collegata") ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($isApiBookScraper && $hasApiConfig): ?>
                                            <div class="mb-3 flex flex-wrap gap-2">
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-100 text-blue-800 text-xs font-semibold rounded-lg border border-blue-200">
                                                    <i class="fas fa-link"></i>
                                                    <?= __("API configurata") ?>
                                                </span>
                                                <?php if ($isApiEnabled): ?>
                                                    <span
                                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-green-100 text-green-800 text-xs font-semibold rounded-lg border border-green-200">
                                                        <i class="fas fa-check-circle"></i>
                                                        <?= __("Abilitato") ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span
                                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-orange-100 text-orange-800 text-xs font-semibold rounded-lg border border-orange-200">
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
                                                    <?php
                                                    $authorUrl = $plugin['author_url'];
                                                    $isSafeUrl = $authorUrl && preg_match('~^https?://~i', $authorUrl);
                                                    if ($isSafeUrl): ?>
                                                        <a href="<?= HtmlHelper::e($authorUrl) ?>" target="_blank"
                                                            rel="noopener noreferrer" class="hover:text-gray-700 underline">
                                                            <?= HtmlHelper::e($plugin['author']) ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <?= HtmlHelper::e($plugin['author']) ?>
                                                    <?php endif; ?>
                                                </span>
                                            <?php endif; ?>
                                            <span>
                                                <i class="fas fa-calendar mr-1"></i>
                                                <?= __("Installato:") ?>
                                                <?= format_date($plugin['installed_at'], false, '/') ?>
                                            </span>
                                            <?php if ($plugin['activated_at']): ?>
                                                <span>
                                                    <i class="fas fa-bolt mr-1"></i>
                                                    <?= __("Attivato:") ?> <?= format_date($plugin['activated_at'], false, '/') ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex flex-wrap items-center gap-2 flex-shrink-0 mt-3 md:mt-0">
                                <?php if ($isOpenLibrary): ?>
                                    <?php if ($hasGoogleKey): ?>
                                        <button type="button"
                                            class="px-4 py-2 bg-green-100 text-green-700 rounded-lg hover:bg-green-200 transition-all duration-200 text-sm font-medium"
                                            data-plugin-id="<?= (int)$plugin['id'] ?>"
                                            data-plugin-name="<?= HtmlHelper::e($plugin['display_name']) ?>"
                                            data-plugin-type="open-library" data-has-key="1" onclick="openPluginSettingsModal(this)">
                                            <i class="fas fa-check-circle mr-1"></i>
                                            <?= __("Google Books Configurato") ?>
                                        </button>
                                    <?php else: ?>
                                        <button type="button"
                                            class="px-4 py-2 bg-orange-100 text-orange-700 rounded-lg hover:bg-orange-200 transition-all duration-200 text-sm font-medium"
                                            data-plugin-id="<?= (int)$plugin['id'] ?>"
                                            data-plugin-name="<?= HtmlHelper::e($plugin['display_name']) ?>"
                                            data-plugin-type="open-library" data-has-key="0" onclick="openPluginSettingsModal(this)">
                                            <i class="fas fa-exclamation-triangle mr-1"></i>
                                            <?= __("Configura Google Books") ?>
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($isApiBookScraper): ?>
                                    <button type="button"
                                        class="px-4 py-2 <?= $hasApiConfig ? 'bg-blue-100 text-blue-700 hover:bg-blue-200' : 'bg-orange-100 text-orange-700 hover:bg-orange-200' ?> rounded-lg transition-all duration-200 text-sm font-medium"
                                        data-plugin-id="<?= (int)$plugin['id'] ?>"
                                        data-plugin-name="<?= HtmlHelper::e($plugin['display_name']) ?>"
                                        data-plugin-type="api-book-scraper" data-has-config="<?= $hasApiConfig ? '1' : '0' ?>"
                                        data-api-endpoint="<?= HtmlHelper::e($apiBookScraperSettings['api_endpoint'] ?? '') ?>"
                                        data-timeout="<?= HtmlHelper::e($apiBookScraperSettings['timeout'] ?? '10') ?>"
                                        data-enabled="<?= $isApiEnabled ? '1' : '0' ?>" onclick="openApiBookScraperModal(this)">
                                        <i class="fas <?= $hasApiConfig ? 'fa-cog' : 'fa-exclamation-triangle' ?> mr-1"></i>
                                        <?= __("Configura API") ?>
                                    </button>
                                <?php endif; ?>
                                <?php if ($plugin['name'] === 'z39-server'): ?>
                                    <?php
                                    $z39Settings = $pluginSettings[$plugin['id']] ?? [];
                                    $z39Servers = json_decode($z39Settings['servers'] ?? '[]', true) ?: [];
                                    $z39ServerCount = count($z39Servers);
                                    ?>
                                    <button type="button"
                                        class="px-4 py-2 bg-indigo-100 text-indigo-700 rounded-lg hover:bg-indigo-200 transition-all duration-200 text-sm font-medium"
                                        data-plugin-id="<?= (int)$plugin['id'] ?>"
                                        data-plugin-name="<?= HtmlHelper::e($plugin['display_name']) ?>"
                                        data-enable-server="<?= ($z39Settings['enable_server'] ?? '0') === '1' ? '1' : '0' ?>"
                                        data-enable-client="<?= ($z39Settings['enable_client'] ?? '0') === '1' ? '1' : '0' ?>"
                                        data-servers="<?= HtmlHelper::e($z39Settings['servers'] ?? '[]') ?>"
                                        onclick="openZ39ServerModal(this)">
                                        <i class="fas fa-globe mr-1"></i>
                                        <?= __("Configura Z39.50") ?>
                                        <?php if ($z39ServerCount > 0): ?>
                                            <span
                                                class="ml-1 px-1.5 py-0.5 bg-indigo-200 text-indigo-800 text-xs rounded-full"><?= $z39ServerCount ?></span>
                                        <?php endif; ?>
                                    </button>
                                <?php endif; ?>
                                <?php if ($plugin['name'] === 'dewey-editor' && $plugin['is_active']): ?>
                                    <a href="<?= htmlspecialchars(url('/admin/dewey-editor'), ENT_QUOTES, 'UTF-8') ?>"
                                        class="px-4 py-2 bg-amber-100 text-amber-700 rounded-lg hover:bg-amber-200 transition-all duration-200 text-sm font-medium inline-flex items-center">
                                        <i class="fas fa-edit mr-1"></i>
                                        <?= __("Apri Editor") ?>
                                    </a>
                                <?php endif; ?>
                                <?php if ($plugin['is_active']): ?>
                                    <button onclick="deactivatePlugin(<?= (int)$plugin['id'] ?>)"
                                        class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-all duration-200 text-sm font-medium">
                                        <i class="fas fa-pause mr-1"></i>
                                        <?= __("Disattiva") ?>
                                    </button>
                                <?php else: ?>
                                    <button onclick="activatePlugin(<?= (int)$plugin['id'] ?>)"
                                        class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-all duration-200 text-sm font-medium">
                                        <i class="fas fa-check mr-1"></i>
                                        <?= __("Attiva") ?>
                                    </button>
                                <?php endif; ?>

                                <button
                                    data-plugin-id="<?= (int)$plugin['id'] ?>"
                                    data-plugin-name="<?= HtmlHelper::e($plugin['display_name']) ?>"
                                    onclick="uninstallPlugin(this.dataset.pluginId, this.dataset.pluginName)"
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
<div id="uploadModal"
    class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-auto">
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
                    <button type="button" onclick="closeUploadModal()"
                        class="px-6 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-all duration-200 font-medium">
                        <?= __("Annulla") ?>
                    </button>
                    <button type="button" id="uploadButton"
                        class="px-6 py-3 bg-black text-white rounded-xl hover:bg-gray-800 transition-all duration-200 font-medium disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fas fa-upload mr-2"></i>
                        <?= __("Installa Plugin") ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Plugin Settings Modal -->
<div id="pluginSettingsModal"
    class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xl">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 id="pluginSettingsTitle" class="text-lg font-semibold text-gray-900"><?= __("Google Books API") ?></h3>
            <button id="pluginSettingsCloseButton" type="button"
                class="p-2 hover:bg-gray-100 rounded-lg transition-colors" onclick="closePluginSettingsModal()">
                <i class="fas fa-times text-gray-500"></i>
            </button>
        </div>
        <div class="p-6">
            <!-- Status Badge -->
            <div id="pluginSettingsStatusBadge" class="hidden mb-4 rounded-xl border border-green-200 bg-green-50 p-4">
                <div class="flex items-start gap-3">
                    <div
                        class="flex h-8 w-8 items-center justify-center rounded-full bg-green-500 text-white flex-shrink-0">
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
                    <input id="googleBooksKeyInput" type="password" autocomplete="off"
                        class="mt-2 w-full rounded-xl border border-indigo-200 bg-white px-4 py-2.5 text-sm text-gray-900 placeholder:text-gray-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-200"
                        placeholder="AIza...">
                    <p id="pluginSettingsHelper" class="mt-2 text-xs text-gray-600">
                        <?= __("Se non imposti la chiave, il plugin utilizzerà esclusivamente Open Library.") ?>
                    </p>
                </div>
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <a href="https://console.cloud.google.com/apis/library/books.googleapis.com" target="_blank"
                        class="inline-flex items-center gap-2 text-sm font-medium text-indigo-700 hover:text-indigo-900">
                        <i class="fas fa-external-link-alt text-xs"></i>
                        <?= __("Apri Google Cloud Console") ?>
                    </a>
                    <div class="flex gap-3">
                        <button type="button"
                            class="px-4 py-2 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-all text-sm font-medium"
                            onclick="closePluginSettingsModal()">
                            <?= __("Chiudi") ?>
                        </button>
                        <button type="submit"
                            class="inline-flex items-center gap-2 rounded-xl bg-black px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 transition disabled:opacity-60"
                            data-role="save-key" data-label="<?= __("Salva API Key") ?>">
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
<div id="apiBookScraperModal"
    class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between sticky top-0 bg-white">
            <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                <i class="fas fa-cloud-download-alt text-blue-600"></i>
                API Book Scraper - <?= __("Configurazione") ?>
            </h3>
            <button type="button" class="p-2 hover:bg-gray-100 rounded-lg transition-colors"
                onclick="closeApiBookScraperModal()">
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
                        <p><?= __("Questo plugin interroga un servizio API esterno per recuperare dati libri tramite ISBN/EAN. Ha priorità 3 (più alta di Open Library).") ?>
                        </p>
                    </div>
                </div>
            </div>

            <div>
                <label for="apiEndpointInput" class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-link mr-1"></i>
                    <?= __("URL Endpoint API") ?> *
                </label>
                <input type="url" id="apiEndpointInput" required
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
                    <input type="password" id="apiKeyInput" autocomplete="off"
                        class="block w-full rounded-xl border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm py-3 px-4 pr-10 font-mono"
                        placeholder="your-api-key-here">
                    <button type="button" onclick="toggleApiKeyVisibility()"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700">
                        <i id="apiKeyIcon" class="fas fa-eye"></i>
                    </button>
                </div>
                <p id="apiKeyHelper" class="mt-2 text-xs text-gray-600">
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
                    <input type="number" id="apiTimeoutInput" min="5" max="60" value="10"
                        class="block w-32 rounded-xl border-gray-300 focus:border-blue-500 focus:ring-blue-500 text-sm py-3 px-4 text-center">
                    <span class="text-sm text-gray-600"><?= __("secondi (min: 5, max: 60)") ?></span>
                </div>
            </div>

            <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                <label class="inline-flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" id="apiEnabledInput" value="1"
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
                <button type="button" onclick="closeApiBookScraperModal()"
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

<!-- Z39.50 Settings Modal -->
<div id="z39ServerModal"
    class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between sticky top-0 bg-white z-10">
            <h3 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
                <i class="fas fa-globe text-indigo-600"></i>
                Z39.50/SRU - <?= __("Configurazione") ?>
            </h3>
            <button type="button" class="p-2 hover:bg-gray-100 rounded-lg transition-colors"
                onclick="closeZ39ServerModal()">
                <i class="fas fa-times text-gray-500"></i>
            </button>
        </div>
        <form id="z39ServerForm" class="p-6 space-y-6" onsubmit="saveZ39ServerSettings(event)">
            <input type="hidden" id="z39PluginId">

            <!-- Global Settings -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-4">
                    <label class="inline-flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" id="z39EnableServer"
                            class="w-5 h-5 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                        <div>
                            <span class="text-sm font-semibold text-gray-900"><?= __("Abilita Server SRU") ?></span>
                            <p class="text-xs text-gray-600 mt-1">
                                <?= __("Espone il catalogo locale via protocollo SRU per altre biblioteche.") ?>
                            </p>
                        </div>
                    </label>
                </div>
                <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-4">
                    <label class="inline-flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" id="z39EnableClient"
                            class="w-5 h-5 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                        <div>
                            <span class="text-sm font-semibold text-gray-900"><?= __("Abilita Client SRU") ?></span>
                            <p class="text-xs text-gray-600 mt-1">
                                <?= __("Permette di importare libri (Copy Cataloging) e cercare su cataloghi esterni.") ?>
                            </p>
                        </div>
                    </label>
                </div>
            </div>

            <!-- SBN Built-in Notice -->
            <div id="z39SbnNotice" class="hidden">
                <div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-4">
                    <div class="flex items-start gap-3">
                        <div class="flex-shrink-0">
                            <i class="fas fa-check-circle text-green-600 text-lg"></i>
                        </div>
                        <div>
                            <h5 class="text-sm font-semibold text-green-800"><?= __("SBN Italia - Integrato") ?></h5>
                            <p class="text-xs text-green-700 mt-1">
                                <?= __("Il catalogo SBN (OPAC Nazionale Italiano) è già integrato e viene interrogato automaticamente durante l'importazione ISBN. Non è necessario aggiungerlo come server esterno.") ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- External Servers List -->
            <div id="z39ExternalServersSection" class="hidden">
                <div class="flex flex-col gap-3 mb-4">
                    <h4 class="text-md font-semibold text-gray-900"><?= __("Server Esterni SRU") ?></h4>
                    <p class="text-xs text-gray-500 -mt-2">
                        <?= __("Server SRU aggiuntivi per Copy Cataloging. SBN Italia è già integrato (vedi sopra).") ?>
                    </p>
                    <div class="flex flex-col sm:flex-row gap-2 w-full">
                        <select id="z39PresetServers" onchange="addPresetServer(this.value); this.value='';"
                            class="text-sm rounded-lg border-gray-300 focus:ring-indigo-500 focus:border-indigo-500 w-full sm:w-auto sm:flex-1 max-w-full">
                            <option value=""><?= __("+ Aggiungi da preset...") ?></option>
                            <optgroup label="[IT] Italia">
                                <option value="sbn_info" disabled>-- SBN: già integrato (vedi sopra) --</option>
                            </optgroup>
                            <optgroup label="[DE] Deutschland">
                                <option value="k10plus">* K10plus (GBV Germany)</option>
                                <option value="dnb">* DNB - Deutsche Nationalbibliothek</option>
                                <option value="gbv">GBV - Gemeinsamer Bibliotheksverbund</option>
                            </optgroup>
                            <optgroup label="[FR] France">
                                <option value="sudoc">* SUDOC - Système Universitaire</option>
                                <option value="bnf">BnF - Bibliothèque nationale de France</option>
                            </optgroup>
                            <optgroup label="[US] USA">
                                <option value="loc">Library of Congress</option>
                            </optgroup>
                            <optgroup label="[GB] UK">
                                <option value="bl">British Library</option>
                                <option value="copac">COPAC (UK Libraries)</option>
                            </optgroup>
                            <optgroup label="[ES] España">
                                <option value="bne">BNE - Biblioteca Nacional de España</option>
                                <option value="rebiun">REBIUN - Red de Bibliotecas Universitarias</option>
                            </optgroup>
                            <optgroup label="[...] Altri Paesi">
                                <option value="kb">KB - Koninklijke Bibliotheek (NL)</option>
                                <option value="ndl">NDL - National Diet Library (JP)</option>
                                <option value="nb">NB - Nasjonalbiblioteket (NO)</option>
                                <option value="nlp">NLP - National Library of Poland (PL)</option>
                            </optgroup>
                        </select>
                        <button type="button" onclick="addZ39ServerRow()"
                            class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium transition-colors whitespace-nowrap w-full sm:w-auto">
                            <i class="fas fa-plus mr-1"></i> <?= __("Personalizzato") ?>
                        </button>
                    </div>
                </div>

                <div id="z39ServersList" class="space-y-4">
                    <!-- Server rows will be injected here -->
                </div>

                <div id="z39NoServers"
                    class="text-center py-8 bg-gray-50 rounded-xl border border-dashed border-gray-300 hidden">
                    <p class="text-gray-500 text-sm">
                        <?= __("Nessun server configurato. Aggiungine uno per iniziare.") ?>
                    </p>
                </div>

                <p class="text-xs text-gray-500 mt-4">
                    <i class="fas fa-info-circle mr-1"></i>
                    <?= __("Questi sono i server a cui la tua biblioteca si collegherà per cercare e importare libri.") ?>
                </p>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t">
                <button type="button" onclick="closeZ39ServerModal()"
                    class="px-6 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-all text-sm font-medium">
                    <?= __("Annulla") ?>
                </button>
                <button type="submit"
                    class="inline-flex items-center gap-2 px-6 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 transition-all text-sm font-semibold">
                    <i class="fas fa-save"></i>
                    <?= __("Salva Configurazione") ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    const csrfToken = <?= json_encode(\App\Support\Csrf::ensureToken(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    // XSS protection helper
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Z39.50 Logic
    const z39ServerModal = document.getElementById('z39ServerModal');
    const z39ServersList = document.getElementById('z39ServersList');
    const z39ExternalServersSection = document.getElementById('z39ExternalServersSection');
    const z39SbnNotice = document.getElementById('z39SbnNotice');
    const z39EnableClientCheckbox = document.getElementById('z39EnableClient');

    // Preset SRU servers configuration
    // * = Tested and recommended
    // NOTE: SBN Italia uses a dedicated JSON API client (SbnClient.php), not SRU.
    //       It's automatically queried when Client is enabled - no need to add as external server.
    const z39PresetServers = {
        // [DE] Deutschland (Most reliable)
        k10plus: {
            name: '[DE] K10plus (GBV Germany)',
            url: 'http://sru.k10plus.de/opac-de-627',
            db: 'opac-de-627',
            syntax: 'marcxml',
            indexes: { isbn: 'pica.isb' }
        },
        dnb: {
            name: '[DE] DNB - Deutsche Nationalbibliothek',
            url: 'https://services.dnb.de/sru/dnb',
            db: 'dnb',
            syntax: 'marcxml',
            indexes: { isbn: 'num' }
        },
        gbv: {
            name: '[DE] GBV - Gemeinsamer Bibliotheksverbund',
            url: 'http://sru.gbv.de/gvk',
            db: 'gvk',
            syntax: 'marcxml',
            indexes: { isbn: 'pica.isb' }
        },
        // [FR] France
        bnf: {
            name: '[FR] BnF - Bibliotheque nationale de France',
            url: 'http://catalogue.bnf.fr/api/SRU',
            db: '',
            syntax: 'marcxml',
            indexes: { isbn: 'bib.isbn' }
        },
        sudoc: {
            name: '[FR] SUDOC - Systeme Universitaire',
            url: 'https://www.sudoc.abes.fr/cbs/sru/',
            db: '',
            syntax: 'unimarc',
            indexes: { isbn: 'isb' }
        },
        // [US] USA
        loc: {
            name: '[US] Library of Congress',
            url: 'http://lx2.loc.gov:210/LCDB',
            db: 'LCDB',
            syntax: 'marcxml',
            indexes: { isbn: 'bath.isbn' }
        },
        // [GB] UK
        bl: {
            name: '[GB] British Library',
            url: 'http://z3950cat.bl.uk:9909/BNB03U',
            db: 'BNB03U',
            syntax: 'marcxml',
            indexes: { isbn: 'bath.isbn' }
        },
        copac: {
            name: '[GB] COPAC - UK Libraries',
            url: 'http://copac.jisc.ac.uk/sru',
            db: 'copac',
            syntax: 'marcxml',
            indexes: { isbn: 'bath.isbn' }
        },
        // [ES] Espana
        bne: {
            name: '[ES] BNE - Biblioteca Nacional de Espana',
            url: 'http://sru.bne.es/bne/SRU',
            db: '',
            syntax: 'marcxml',
            indexes: { isbn: 'dc.identifier' }
        },
        rebiun: {
            name: '[ES] REBIUN - Red de Bibliotecas Universitarias',
            url: 'http://rebiun.absysnet.com/cgi-bin/rebiun/O7001/ID',
            db: 'rebiun',
            syntax: 'marcxml',
            indexes: { isbn: 'isbn' }
        },
        // Altri Paesi
        kb: {
            name: '[NL] KB - Koninklijke Bibliotheek',
            url: 'http://jsru.kb.nl/sru/sru',
            db: 'GGC',
            syntax: 'marcxml',
            indexes: { isbn: 'bath.isbn' }
        },
        ndl: {
            name: '[JP] NDL - National Diet Library',
            url: 'https://iss.ndl.go.jp/api/sru',
            db: '',
            syntax: 'dc',
            indexes: { isbn: 'isbn' }
        },
        nb: {
            name: '[NO] NB - Nasjonalbiblioteket',
            url: 'https://bibsys-k.alma.exlibrisgroup.com/view/sru/47BIBSYS_NETWORK',
            db: '',
            syntax: 'marcxml',
            indexes: { isbn: 'alma.isbn' }
        },
        nlp: {
            name: '[PL] NLP - National Library of Poland',
            url: 'http://data.bn.org.pl/api/bibs.marcxml',
            db: '',
            syntax: 'marcxml',
            indexes: { isbn: 'isbn' }
        }
    };

    function addPresetServer(presetKey) {
        if (!presetKey || !z39PresetServers[presetKey]) return;
        const preset = z39PresetServers[presetKey];
        addZ39ServerRow({
            name: preset.name,
            url: preset.url,
            db: preset.db,
            syntax: preset.syntax,
            indexes: preset.indexes,
            enabled: true
        });
    }

    // Toggle visibility based on checkbox - with defensive null check
    if (z39EnableClientCheckbox) {
        z39EnableClientCheckbox.addEventListener('change', function () {
            if (this.checked) {
                z39SbnNotice.classList.remove('hidden');
                z39ExternalServersSection.classList.remove('hidden');
            } else {
                z39SbnNotice.classList.add('hidden');
                z39ExternalServersSection.classList.add('hidden');
            }
        });
    }

    function openZ39ServerModal(btn) {
        const pluginId = btn.dataset.pluginId;
        const enableServer = btn.dataset.enableServer === '1';
        const enableClient = btn.dataset.enableClient === '1';

        // Hardening: handle corrupted JSON gracefully
        let servers = [];
        try {
            servers = JSON.parse(btn.dataset.servers || '[]');
        } catch (e) {
            servers = [];
        }

        document.getElementById('z39PluginId').value = pluginId;
        document.getElementById('z39EnableServer').checked = enableServer;
        z39EnableClientCheckbox.checked = enableClient;

        // Trigger visibility update
        z39EnableClientCheckbox.dispatchEvent(new Event('change'));

        renderZ39Servers(servers);
        z39ServerModal.classList.remove('hidden');
    }

    function closeZ39ServerModal() {
        z39ServerModal.classList.add('hidden');
    }

    function renderZ39Servers(servers) {
        z39ServersList.innerHTML = '';
        if (servers.length === 0) {
            document.getElementById('z39NoServers').classList.remove('hidden');
        } else {
            document.getElementById('z39NoServers').classList.add('hidden');
            servers.forEach((server) => {
                addZ39ServerRow(server);
            });
        }
    }

    function addZ39ServerRow(server = null) {
        document.getElementById('z39NoServers').classList.add('hidden');
        server = server || { name: '', url: '', db: '', syntax: 'marcxml', enabled: true };

        // Escape values to prevent XSS
        const safeName = escapeHtml(server.name);
        const safeUrl = escapeHtml(server.url);
        const safeDb = escapeHtml(server.db);
        const safeIsbnIndex = escapeHtml(server.indexes?.isbn || 'isbn');
        const safeSyntax = escapeHtml(server.syntax || 'marcxml');

        const row = document.createElement('div');
        row.className = 'z39-server-row bg-gray-50 rounded-xl p-4 border border-gray-200 relative group';
        row.innerHTML = `
        <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
            <div class="md:col-span-3">
                <label class="block text-xs font-medium text-gray-500 mb-1"><?= __("Nome Server") ?></label>
                <input type="text" name="server_name[]" value="${safeName}" placeholder="es. SBN Nazionale" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <div class="md:col-span-3">
                <label class="block text-xs font-medium text-gray-500 mb-1"><?= __("URL Endpoint SRU") ?></label>
                <input type="url" name="server_url[]" value="${safeUrl}" placeholder="http://opac.sbn.it/sru" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500 font-mono">
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-500 mb-1"><?= __("Database") ?></label>
                <input type="text" name="server_db[]" value="${safeDb}" placeholder="nopac" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-500 mb-1"><?= __("Indice ISBN") ?></label>
                <input type="text" name="server_isbn_index[]" value="${safeIsbnIndex}" placeholder="es. bath.isbn" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500 font-mono">
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-500 mb-1"><?= __("Sintassi") ?></label>
                <select name="server_syntax[]" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="marcxml" ${safeSyntax === 'marcxml' ? 'selected' : ''}>MARCXML</option>
                    <option value="unimarc" ${safeSyntax === 'unimarc' ? 'selected' : ''}>UNIMARC</option>
                    <option value="mods" ${safeSyntax === 'mods' ? 'selected' : ''}>MODS</option>
                    <option value="dc" ${safeSyntax === 'dc' ? 'selected' : ''}>Dublin Core</option>
                </select>
            </div>
        </div>
        <div class="absolute top-2 right-2 sm:opacity-0 sm:group-hover:opacity-100 transition-opacity">
            <button type="button" onclick="this.closest('.z39-server-row').remove(); checkEmptyServers();" class="text-red-500 hover:text-red-700 p-2">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    `;
        z39ServersList.appendChild(row);
    }

    function checkEmptyServers() {
        if (document.querySelectorAll('.z39-server-row').length === 0) {
            document.getElementById('z39NoServers').classList.remove('hidden');
        }
    }

    async function saveZ39ServerSettings(event) {
        event.preventDefault();
        const form = event.target;
        const pluginId = document.getElementById('z39PluginId').value;

        // Collect servers with validation
        const servers = [];
        const rows = document.querySelectorAll('.z39-server-row');
        let hasValidationError = false;

        // Clear previous error highlights
        rows.forEach(row => {
            row.classList.remove('border-red-400', 'bg-red-50');
            row.querySelectorAll('input').forEach(input => {
                input.classList.remove('border-red-400', 'ring-red-200');
            });
        });

        rows.forEach(row => {
            const nameInput = row.querySelector('[name="server_name[]"]');
            const urlInput = row.querySelector('[name="server_url[]"]');
            const name = nameInput.value.trim();
            const url = urlInput.value.trim();

            // Skip empty rows but validate partially filled ones
            if (!name && !url) {
                return;
            }

            if (!name || !url) {
                hasValidationError = true;
                // Highlight the row and incomplete fields
                row.classList.add('border-red-400', 'bg-red-50');
                if (!name) nameInput.classList.add('border-red-400', 'ring-red-200');
                if (!url) urlInput.classList.add('border-red-400', 'ring-red-200');
                return;
            }

            servers.push({
                name: name,
                url: url,
                db: row.querySelector('[name="server_db[]"]').value.trim(),
                syntax: row.querySelector('[name="server_syntax[]"]').value,
                indexes: {
                    isbn: row.querySelector('[name="server_isbn_index[]"]').value || 'isbn'
                },
                enabled: true
            });
        });

        if (hasValidationError) {
            Swal.fire({
                icon: 'warning',
                title: <?= json_encode(__("Attenzione"), JSON_HEX_TAG) ?>,
                text: <?= json_encode(__("Compila nome e URL per tutti i server."), JSON_HEX_TAG) ?>
            });
            return;
        }

        const formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('settings[enable_server]', document.getElementById('z39EnableServer').checked ? '1' : '0');
        formData.append('settings[enable_client]', document.getElementById('z39EnableClient').checked ? '1' : '0');
        formData.append('settings[servers]', JSON.stringify(servers));

        try {
            const response = await fetch(`${window.BASE_PATH}/admin/plugins/${pluginId}/settings`, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const result = await response.json();

            if (result.success) {
                await Swal.fire({
                    icon: 'success',
                    title: <?= json_encode(__("Successo"), JSON_HEX_TAG) ?>,
                    text: result.message
                });
                window.location.reload();
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>,
                text: error.message
            });
        }
    }
    // merged script
    const googleBooksModalTexts = {
        titleSuffix: <?= json_encode(__("Google Books API"), JSON_HEX_TAG) ?>,
        hasKey: <?= json_encode(__("Una chiave è già salvata. Inserisci un nuovo valore per aggiornarla oppure lascia vuoto per rimuoverla."), JSON_HEX_TAG) ?>,
        noKey: <?= json_encode(__("Se non imposti la chiave, il plugin utilizzerà esclusivamente Open Library."), JSON_HEX_TAG) ?>
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

        // Use self-hosted Uppy from window globals - defensive check
        if (typeof window.Uppy === 'undefined' || typeof window.UppyDashboard === 'undefined') {
            Swal.fire({
                icon: 'error',
                title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>,
                text: <?= json_encode(__("Librerie di upload non caricate. Ricarica la pagina."), JSON_HEX_TAG) ?>
            });
            return;
        }

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
                    dropPasteFiles: <?= json_encode(__("Trascina qui il file ZIP del plugin o %{browse}"), JSON_HEX_TAG) ?>,
                    browse: <?= json_encode(__("seleziona"), JSON_HEX_TAG) ?>,
                    uploadComplete: <?= json_encode(__("Caricamento completato"), JSON_HEX_TAG) ?>,
                    uploadFailed: <?= json_encode(__("Caricamento fallito"), JSON_HEX_TAG) ?>,
                    complete: <?= json_encode(__("Completato"), JSON_HEX_TAG) ?>,
                    uploading: <?= json_encode(__("Caricamento in corso..."), JSON_HEX_TAG) ?>,
                    error: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>,
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
        // Initialize button state
        const uploadBtn = document.getElementById('uploadButton');
        if (uploadBtn) uploadBtn.disabled = !selectedFile;
    }

    function closeUploadModal() {
        document.getElementById('uploadModal').classList.add('hidden');
        if (uppyInstance) {
            uppyInstance.cancelAll(); // Remove all files
            uppyInstance.close();     // Clean up DOM and listeners
            uppyInstance = null;
        }
        selectedFile = null;
        // Reset button state
        const uploadBtn = document.getElementById('uploadButton');
        if (uploadBtn) uploadBtn.disabled = true;
    }

    document.getElementById('uploadButton')?.addEventListener('click', async function () {
        if (!selectedFile) {
            Swal.fire({
                icon: 'error',
                title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>,
                text: <?= json_encode(__("Seleziona un file ZIP del plugin."), JSON_HEX_TAG) ?>
            });
            return;
        }

        const formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('plugin_file', selectedFile.data);

        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>' + <?= json_encode(__("Installazione in corso..."), JSON_HEX_TAG) ?>;

        try {
            const response = await fetch(window.BASE_PATH + '/admin/plugins/upload', {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const result = await response.json();

            if (result.success) {
                await Swal.fire({
                    icon: 'success',
                    title: <?= json_encode(__("Successo"), JSON_HEX_TAG) ?>,
                    text: result.message
                });
                window.location.reload();
            } else {
                await Swal.fire({
                    icon: 'error',
                    title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>,
                    text: result.message
                });
            }
        } catch (error) {
            await Swal.fire({
                icon: 'error',
                title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>,
                text: <?= json_encode(__("Errore durante l'installazione del plugin."), JSON_HEX_TAG) ?>
            });
        } finally {
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-upload mr-2"></i>' + <?= json_encode(__("Installa Plugin"), JSON_HEX_TAG) ?>;
        }
    });

    async function activatePlugin(pluginId) {
        const result = await Swal.fire({
            title: <?= json_encode(__("Conferma"), JSON_HEX_TAG) ?>,
            text: <?= json_encode(__("Vuoi attivare questo plugin?"), JSON_HEX_TAG) ?>,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: <?= json_encode(__("Sì, attiva"), JSON_HEX_TAG) ?>,
            cancelButtonText: <?= json_encode(__("Annulla"), JSON_HEX_TAG) ?>,
            confirmButtonColor: '#000000'
        });

        if (!result.isConfirmed) {
            return;
        }

        try {
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);

            const response = await fetch(`${window.BASE_PATH}/admin/plugins/${pluginId}/activate`, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.success) {
                await Swal.fire({
                    icon: 'success',
                    title: <?= json_encode(__("Successo"), JSON_HEX_TAG) ?>,
                    text: data.message
                });
                window.location.reload();
            } else {
                await Swal.fire({
                    icon: 'error',
                    title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>,
                    text: data.message
                });
            }
        } catch (error) {
            await Swal.fire({
                icon: 'error',
                title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>,
                text: <?= json_encode(__("Errore durante l'attivazione del plugin."), JSON_HEX_TAG) ?>
            });
        }
    }

    async function deactivatePlugin(pluginId) {
        const result = await Swal.fire({
            title: <?= json_encode(__("Conferma"), JSON_HEX_TAG) ?>,
            text: <?= json_encode(__("Vuoi disattivare questo plugin?"), JSON_HEX_TAG) ?>,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: <?= json_encode(__("Sì, disattiva"), JSON_HEX_TAG) ?>,
            cancelButtonText: <?= json_encode(__("Annulla"), JSON_HEX_TAG) ?>,
            confirmButtonColor: '#6b7280'
        });

        if (!result.isConfirmed) {
            return;
        }

        try {
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);

            const response = await fetch(`${window.BASE_PATH}/admin/plugins/${pluginId}/deactivate`, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.success) {
                await Swal.fire({
                    icon: 'success',
                    title: <?= json_encode(__("Successo"), JSON_HEX_TAG) ?>,
                    text: data.message
                });
                window.location.reload();
            } else {
                await Swal.fire({
                    icon: 'error',
                    title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>,
                    text: data.message
                });
            }
        } catch (error) {
            await Swal.fire({
                icon: 'error',
                title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>,
                text: <?= json_encode(__("Errore durante la disattivazione del plugin."), JSON_HEX_TAG) ?>
            });
        }
    }

    async function uninstallPlugin(pluginId, pluginName) {
        const escapedName = escapeHtml(pluginName);
        const result = await Swal.fire({
            title: <?= json_encode(__("Conferma Disinstallazione"), JSON_HEX_TAG) ?>,
            html: <?= json_encode(__("Sei sicuro di voler disinstallare"), JSON_HEX_TAG) ?> + ' <strong>' + escapedName + '</strong>?<br><br><span class="text-sm text-red-600">' + <?= json_encode(__("Questa azione eliminerà tutti i dati del plugin e non può essere annullata."), JSON_HEX_TAG) ?> + '</span>',
            icon: 'error',
            showCancelButton: true,
            confirmButtonText: <?= json_encode(__("Sì, disinstalla"), JSON_HEX_TAG) ?>,
            cancelButtonText: <?= json_encode(__("Annulla"), JSON_HEX_TAG) ?>,
            confirmButtonColor: '#dc2626'
        });

        if (!result.isConfirmed) {
            return;
        }

        try {
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);

            const response = await fetch(`${window.BASE_PATH}/admin/plugins/${pluginId}/uninstall`, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.success) {
                await Swal.fire({
                    icon: 'success',
                    title: <?= json_encode(__("Successo"), JSON_HEX_TAG) ?>,
                    text: data.message
                });
                window.location.reload();
            } else {
                await Swal.fire({
                    icon: 'error',
                    title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>,
                    text: data.message
                });
            }
        } catch (error) {
            await Swal.fire({
                icon: 'error',
                title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>,
                text: <?= json_encode(__("Errore durante la disinstallazione del plugin."), JSON_HEX_TAG) ?>
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

        const pluginId = pluginSettingsPluginIdInput.value;
        if (!pluginId) {
            return;
        }

        const apiKey = googleBooksKeyInput.value.trim();

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
            const response = await fetch(`${window.BASE_PATH}/admin/plugins/${pluginId}/settings`, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.success) {
                await Swal.fire({
                    icon: 'success',
                    title: <?= json_encode(__("Successo"), JSON_HEX_TAG) ?>,
                    text: <?= json_encode(__("Chiave Google Books aggiornata."), JSON_HEX_TAG) ?>
                });
                closePluginSettingsModal();
                // Reload to show updated status
                setTimeout(() => window.location.reload(), 1000);
            } else {
                await Swal.fire({
                    icon: 'error',
                    title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>,
                    text: data.message || <?= json_encode(__("Impossibile aggiornare la chiave Google Books."), JSON_HEX_TAG) ?>
                });
            }
        } catch (error) {
            console.error('💥 Error:', error);
            await Swal.fire({
                icon: 'error',
                title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>,
                text: <?= json_encode(__("Errore durante l'aggiornamento della chiave Google Books."), JSON_HEX_TAG) ?>
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
        const hasConfig = button.dataset.hasConfig === '1';

        // Populate form fields
        document.getElementById('apiScraperPluginId').value = pluginId;
        document.getElementById('apiEndpointInput').value = apiEndpoint;
        document.getElementById('apiKeyInput').value = ''; // Always empty for security
        document.getElementById('apiTimeoutInput').value = timeout;
        document.getElementById('apiEnabledInput').checked = enabled;

        // Update API key helper based on existing config
        const apiKeyHelper = document.getElementById('apiKeyHelper');
        if (apiKeyHelper) {
            if (hasConfig) {
                apiKeyHelper.innerHTML = '<i class="fas fa-info-circle mr-1"></i>' + <?= json_encode(__("Lascia vuoto per mantenere la chiave esistente. Inserisci un nuovo valore per aggiornarla."), JSON_HEX_TAG) ?>;
            } else {
                apiKeyHelper.innerHTML = '<i class="fas fa-shield-alt mr-1"></i>' + <?= json_encode(__("L'API key viene criptata con AES-256-GCM prima di essere salvata."), JSON_HEX_TAG) ?>;
            }
        }

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

        const pluginId = document.getElementById('apiScraperPluginId').value;
        if (!pluginId) {
            return;
        }

        const apiEndpoint = document.getElementById('apiEndpointInput').value.trim();
        const apiKey = document.getElementById('apiKeyInput').value.trim();
        const timeout = document.getElementById('apiTimeoutInput').value;
        const enabled = document.getElementById('apiEnabledInput').checked ? '1' : '0';

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
            const response = await fetch(`${window.BASE_PATH}/admin/plugins/${pluginId}/settings`, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (data.success) {
                await Swal.fire({
                    icon: 'success',
                    title: <?= json_encode(__("Successo"), JSON_HEX_TAG) ?>,
                    text: <?= json_encode(__("Impostazioni API Book Scraper salvate correttamente."), JSON_HEX_TAG) ?>
                });
                closeApiBookScraperModal();
                // Reload to show updated status
                setTimeout(() => window.location.reload(), 1000);
            } else {
                await Swal.fire({
                    icon: 'error',
                    title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>,
                    text: data.message || <?= json_encode(__("Impossibile salvare le impostazioni."), JSON_HEX_TAG) ?>
                });
            }
        } catch (error) {
            console.error('💥 Error:', error);
            await Swal.fire({
                icon: 'error',
                title: <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>,
                text: <?= json_encode(__("Errore durante il salvataggio delle impostazioni."), JSON_HEX_TAG) ?>
            });
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.innerHTML = `<i class="fas fa-save"></i> ${buttonLabel}`;
            }
        }
    }
</script>
<?php
use App\Support\HtmlHelper;
use App\Support\Csrf;

$pageTitle = __('Aggiornamenti');
$updateInfo ??= [
    'available' => false,
    'current' => '0.0.0',
    'latest' => '0.0.0',
    'error' => null,
];
$requirements ??= ['met' => false, 'requirements' => []];
$history ??= [];
$changelog ??= [];
?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="mb-8">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900"><?= __("Aggiornamenti") ?></h1>
                <p class="mt-2 text-sm text-gray-600"><?= __("Gestisci gli aggiornamenti dell'applicazione") ?></p>
            </div>
            <button onclick="checkForUpdatesManual()"
                class="inline-flex items-center px-6 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-all duration-200 shadow-sm hover:shadow-md">
                <i class="fas fa-sync-alt mr-2"></i>
                <?= __("Controlla Aggiornamenti") ?>
            </button>
        </div>
    </div>

    <!-- Version Status Card -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-8">
        <div class="p-6">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                <!-- Current Version -->
                <div class="flex items-center gap-4">
                    <div class="w-16 h-16 bg-gradient-to-br from-gray-100 to-gray-200 rounded-2xl flex items-center justify-center">
                        <i class="fas fa-code-branch text-gray-600 text-2xl"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500"><?= __("Versione Installata") ?></p>
                        <p class="text-3xl font-bold text-gray-900">v<?= HtmlHelper::e($updateInfo['current']) ?></p>
                    </div>
                </div>

                <!-- Arrow -->
                <?php if ($updateInfo['available']): ?>
                <div class="hidden lg:block">
                    <i class="fas fa-arrow-right text-gray-400 text-2xl"></i>
                </div>
                <?php endif; ?>

                <!-- Latest Version -->
                <div class="flex items-center gap-4">
                    <div class="w-16 h-16 <?= $updateInfo['available'] ? 'bg-gradient-to-br from-green-100 to-green-200' : 'bg-gradient-to-br from-gray-100 to-gray-200' ?> rounded-2xl flex items-center justify-center">
                        <i class="fas <?= $updateInfo['available'] ? 'fa-download text-green-600' : 'fa-check-circle text-gray-600' ?> text-2xl"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500"><?= __("Ultima Versione") ?></p>
                        <p class="text-3xl font-bold <?= $updateInfo['available'] ? 'text-green-600' : 'text-gray-900' ?>">
                            v<?= HtmlHelper::e($updateInfo['latest']) ?>
                        </p>
                    </div>
                </div>

                <!-- Update Button -->
                <?php if ($updateInfo['available'] && $requirements['met']): ?>
                <div>
                    <button onclick="startUpdate(this.dataset.version)" data-version="<?= HtmlHelper::e($updateInfo['latest']) ?>"
                        class="inline-flex items-center px-6 py-3 bg-green-600 text-white rounded-xl hover:bg-green-700 transition-all duration-200 shadow-md hover:shadow-lg">
                        <i class="fas fa-download mr-2"></i>
                        <?= __("Aggiorna Ora") ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <!-- Update Available Banner -->
            <?php if ($updateInfo['available']): ?>
            <div class="mt-6 p-4 bg-green-50 border border-green-200 rounded-xl">
                <div class="flex items-start gap-3">
                    <i class="fas fa-info-circle text-green-600 mt-0.5"></i>
                    <div>
                        <p class="font-medium text-green-800"><?= __("Nuovo aggiornamento disponibile!") ?></p>
                        <p class="text-sm text-green-700 mt-1">
                            <?= sprintf(__("La versione %s è disponibile. Prima di aggiornare, verrà creato un backup automatico del database."), HtmlHelper::e($updateInfo['latest'])) ?>
                        </p>
                    </div>
                </div>
            </div>
            <?php elseif (!empty($updateInfo['error'])): ?>
            <div class="mt-6 p-4 bg-red-50 border border-red-200 rounded-xl">
                <div class="flex items-start gap-3">
                    <i class="fas fa-exclamation-triangle text-red-600 mt-0.5"></i>
                    <div>
                        <p class="font-medium text-red-800"><?= __("Errore durante il controllo") ?></p>
                        <p class="text-sm text-red-700 mt-1"><?= HtmlHelper::e($updateInfo['error'] ?? '') ?></p>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="mt-6 p-4 bg-gray-50 border border-gray-200 rounded-xl">
                <div class="flex items-start gap-3">
                    <i class="fas fa-check-circle text-gray-600 mt-0.5"></i>
                    <div>
                        <p class="font-medium text-gray-800"><?= __("Pinakes è aggiornato") ?></p>
                        <p class="text-sm text-gray-600 mt-1"><?= __("Stai utilizzando l'ultima versione disponibile.") ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Requirements & Changelog in Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
        <!-- System Requirements -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200">
            <div class="p-6 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900"><?= __("Requisiti di Sistema") ?></h2>
                    <?php if ($requirements['met']): ?>
                    <span class="px-3 py-1 text-xs font-medium bg-green-100 text-green-700 rounded-lg">
                        <i class="fas fa-check-circle mr-1"></i><?= __("Tutti soddisfatti") ?>
                    </span>
                    <?php else: ?>
                    <span class="px-3 py-1 text-xs font-medium bg-red-100 text-red-700 rounded-lg">
                        <i class="fas fa-times-circle mr-1"></i><?= __("Alcuni mancanti") ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="p-6">
                <div class="space-y-4">
                    <?php foreach ($requirements['requirements'] ?? [] as $req): ?>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 <?= $req['met'] ? 'bg-green-100' : 'bg-red-100' ?> rounded-lg flex items-center justify-center">
                                <i class="fas <?= $req['met'] ? 'fa-check text-green-600' : 'fa-times text-red-600' ?> text-sm"></i>
                            </div>
                            <div>
                                <p class="font-medium text-gray-900"><?= HtmlHelper::e($req['name']) ?></p>
                                <p class="text-xs text-gray-500"><?= __("Richiesto:") ?> <?= HtmlHelper::e($req['required']) ?></p>
                            </div>
                        </div>
                        <span class="text-sm <?= $req['met'] ? 'text-gray-600' : 'text-red-600 font-medium' ?>">
                            <?= HtmlHelper::e($req['current']) ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Backup & Actions -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900"><?= __("Backup e Sicurezza") ?></h2>
            </div>
            <div class="p-6">
                <div class="space-y-6">
                    <div class="p-4 bg-blue-50 border border-blue-200 rounded-xl">
                        <div class="flex items-start gap-3">
                            <i class="fas fa-shield-alt text-blue-600 mt-0.5"></i>
                            <div>
                                <p class="font-medium text-blue-800"><?= __("Backup Automatico") ?></p>
                                <p class="text-sm text-blue-700 mt-1">
                                    <?= __("Prima di ogni aggiornamento viene creato automaticamente un backup del database.") ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <button onclick="createBackup()"
                            class="w-full inline-flex items-center justify-center px-6 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-all duration-200">
                            <i class="fas fa-database mr-2"></i>
                            <?= __("Crea Backup Manuale") ?>
                        </button>
                    </div>

                    <div class="text-sm text-gray-500">
                        <p><i class="fas fa-folder mr-2"></i><?= __("I backup sono salvati in:") ?></p>
                        <code class="block mt-2 p-2 bg-gray-100 rounded-lg text-xs">storage/backups/</code>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Manual Upload Section -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-8">
        <div class="p-6 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900"><?= __("Aggiornamento Manuale") ?></h2>
                    <p class="text-sm text-gray-500 mt-1"><?= __("Carica un pacchetto di aggiornamento scaricato manualmente da GitHub") ?></p>
                </div>
            </div>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Upload Section -->
                <div>
                    <h3 class="font-medium text-gray-900 mb-4 flex items-center">
                        <i class="fas fa-cloud-upload-alt text-gray-600 mr-2"></i>
                        <?= __("Carica Pacchetto") ?>
                    </h3>
                    <div id="uppy-manual-update" class="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center hover:border-gray-400 transition-colors bg-gray-50"></div>
                    <div class="mt-4">
                        <button id="manual-update-submit-btn" onclick="submitManualUpdate()" disabled
                            class="w-full px-6 py-3 bg-green-600 text-white rounded-xl hover:bg-green-700 transition-all duration-200 shadow-md hover:shadow-lg disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-green-600">
                            <i class="fas fa-upload mr-2"></i>
                            <span id="manual-update-btn-text"><?= __("Avvia Aggiornamento") ?></span>
                        </button>
                    </div>
                </div>

                <!-- Instructions -->
                <div>
                    <h3 class="font-medium text-gray-900 mb-4 flex items-center">
                        <i class="fas fa-info-circle text-gray-600 mr-2"></i>
                        <?= __("Istruzioni") ?>
                    </h3>
                    <div class="space-y-4 text-sm text-gray-600">
                        <div class="flex items-start gap-3">
                            <div class="w-6 h-6 bg-gray-100 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                                <span class="text-xs font-medium text-gray-600">1</span>
                            </div>
                            <div>
                                <p class="font-medium text-gray-900"><?= __("Scarica il pacchetto da GitHub") ?></p>
                                <p class="mt-1"><?= __("Vai alla") ?> <a href="https://github.com/fabiodalez-dev/Pinakes/releases" target="_blank" class="text-green-600 hover:text-green-700 underline">pagina releases</a> <?= __("e scarica il file") ?> <code class="bg-gray-100 px-1 rounded text-xs">pinakes-vX.X.X.zip</code></p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="w-6 h-6 bg-gray-100 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                                <span class="text-xs font-medium text-gray-600">2</span>
                            </div>
                            <div>
                                <p class="font-medium text-gray-900"><?= __("Carica il file ZIP") ?></p>
                                <p class="mt-1"><?= __("Trascina il file nell'area di upload o clicca per selezionarlo") ?></p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="w-6 h-6 bg-gray-100 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                                <span class="text-xs font-medium text-gray-600">3</span>
                            </div>
                            <div>
                                <p class="font-medium text-gray-900"><?= __("Avvia l'aggiornamento") ?></p>
                                <p class="mt-1"><?= __("Verrà creato automaticamente un backup prima dell'installazione") ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="mt-6 p-4 bg-yellow-50 border border-yellow-200 rounded-xl">
                        <div class="flex items-start gap-3">
                            <i class="fas fa-exclamation-triangle text-yellow-600 mt-0.5"></i>
                            <div>
                                <p class="font-medium text-yellow-800"><?= __("Nota Importante") ?></p>
                                <p class="text-xs text-yellow-700 mt-1">
                                    <?= __("Usa questa funzione solo se l'aggiornamento automatico non funziona a causa dei limiti di rate della GitHub API. Il pacchetto deve essere lo stesso generato per le release di GitHub.") ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Backup List -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-8">
        <div class="p-6 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-gray-900"><?= __("Backup Salvati") ?></h2>
                <button onclick="loadBackups()" class="text-sm text-gray-500 hover:text-gray-700">
                    <i class="fas fa-sync-alt mr-1"></i><?= __("Aggiorna") ?>
                </button>
            </div>
        </div>
        <div id="backupListContainer">
            <div class="p-12 text-center">
                <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-spinner fa-spin text-gray-400 text-xl"></i>
                </div>
                <p class="text-gray-600"><?= __("Caricamento backup...") ?></p>
            </div>
        </div>
    </div>

    <!-- Changelog -->
    <?php if (!empty($changelog)): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-8">
        <div class="p-6 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900"><?= __("Novità nelle versioni successive") ?></h2>
        </div>
        <div class="divide-y divide-gray-200">
            <?php foreach ($changelog as $release): ?>
            <div class="p-6">
                <div class="flex items-start gap-4">
                    <div class="w-10 h-10 bg-green-100 rounded-xl flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-tag text-green-600"></i>
                    </div>
                    <div class="flex-1">
                        <div class="flex items-center gap-2 mb-2">
                            <h3 class="font-semibold text-gray-900">v<?= HtmlHelper::e($release['version']) ?></h3>
                            <?php if (!empty($release['prerelease'])): ?>
                            <span class="px-2 py-0.5 text-xs font-medium bg-yellow-100 text-yellow-700 rounded-lg">
                                Pre-release
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($release['published_at'])): ?>
                            <span class="text-xs text-gray-500">
                                <?= format_date($release['published_at'], false, '/') ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($release['body'])): ?>
                        <div class="prose prose-sm max-w-none text-gray-600">
                            <?php
                            // Simple markdown parsing for release notes
                            $body = HtmlHelper::e($release['body']);
                            // Code blocks ```...```
                            $body = preg_replace('/```(\w*)\n?([\s\S]*?)```/', '<pre class="bg-gray-100 rounded p-2 overflow-x-auto text-xs"><code>$2</code></pre>', $body);
                            // Inline code `...`
                            $body = preg_replace('/`([^`]+)`/', '<code class="bg-gray-100 px-1 rounded text-xs">$1</code>', $body);
                            // Bold **...**
                            $body = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $body);
                            // Headers ## ...
                            $body = preg_replace('/^## (.+)$/m', '<h4 class="font-semibold mt-3 mb-1">$1</h4>', $body);
                            // List items - ...
                            $body = preg_replace('/^[\-\*] (.+)$/m', '<li class="ml-4">$1</li>', $body);
                            // Newlines
                            $body = nl2br($body);
                            echo $body;
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Update History -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div class="p-6 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900"><?= __("Cronologia Aggiornamenti") ?></h2>
        </div>
        <?php if (empty($history)): ?>
        <div class="p-12 text-center">
            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-history text-gray-400 text-2xl"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mb-2"><?= __("Nessun aggiornamento registrato") ?></h3>
            <p class="text-gray-600"><?= __("La cronologia degli aggiornamenti apparirà qui") ?></p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Data") ?></th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Versione") ?></th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Stato") ?></th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Eseguito da") ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($history as $log): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?= format_date($log['started_at'], true, '/') ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <span class="font-mono">
                                v<?= HtmlHelper::e($log['from_version']) ?>
                                <i class="fas fa-arrow-right text-gray-400 mx-2"></i>
                                v<?= HtmlHelper::e($log['to_version']) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <?php
                            $statusClass = match($log['status']) {
                                'completed' => 'bg-green-100 text-green-700',
                                'failed' => 'bg-red-100 text-red-700',
                                'rolled_back' => 'bg-yellow-100 text-yellow-700',
                                default => 'bg-gray-100 text-gray-700'
                            };
                            $statusIcon = match($log['status']) {
                                'completed' => 'fa-check-circle',
                                'failed' => 'fa-times-circle',
                                'rolled_back' => 'fa-undo',
                                default => 'fa-clock'
                            };
                            $statusText = match($log['status']) {
                                'completed' => __('Completato'),
                                'failed' => __('Fallito'),
                                'rolled_back' => __('Ripristinato'),
                                'started' => __('In corso'),
                                default => $log['status']
                            };
                            ?>
                            <span class="px-2 py-1 text-xs font-medium <?= $statusClass ?> rounded-lg">
                                <i class="fas <?= $statusIcon ?> mr-1"></i>
                                <?= HtmlHelper::e($statusText) ?>
                            </span>
                            <?php if (!empty($log['error_message'])): ?>
                            <p class="text-xs text-red-600 mt-1"><?= HtmlHelper::e($log['error_message']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            <?= HtmlHelper::e($log['executed_by_name'] ?? __('Sistema')) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Update Progress Modal -->
<div id="updateModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-2xl shadow-xl max-w-lg w-full">
            <div class="p-6">
                <div class="text-center">
                    <div id="updateIcon" class="w-20 h-20 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-spinner fa-spin text-blue-600 text-3xl"></i>
                    </div>
                    <h3 id="updateTitle" class="text-xl font-bold text-gray-900 mb-2"><?= __("Aggiornamento in corso...") ?></h3>
                    <p id="updateMessage" class="text-gray-600"><?= __("Non chiudere questa finestra") ?></p>
                </div>

                <div id="updateProgress" class="mt-6">
                    <div class="space-y-3">
                        <div class="flex items-center gap-3 update-step" data-step="backup">
                            <div class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center">
                                <i class="fas fa-circle text-gray-400 text-xs"></i>
                            </div>
                            <span class="text-sm text-gray-600"><?= __("Creazione backup database") ?></span>
                        </div>
                        <div class="flex items-center gap-3 update-step" data-step="download">
                            <div class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center">
                                <i class="fas fa-circle text-gray-400 text-xs"></i>
                            </div>
                            <span class="text-sm text-gray-600"><?= __("Download aggiornamento") ?></span>
                        </div>
                        <div class="flex items-center gap-3 update-step" data-step="install">
                            <div class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center">
                                <i class="fas fa-circle text-gray-400 text-xs"></i>
                            </div>
                            <span class="text-sm text-gray-600"><?= __("Installazione file") ?></span>
                        </div>
                        <div class="flex items-center gap-3 update-step" data-step="migrate">
                            <div class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center">
                                <i class="fas fa-circle text-gray-400 text-xs"></i>
                            </div>
                            <span class="text-sm text-gray-600"><?= __("Migrazione database") ?></span>
                        </div>
                    </div>
                </div>

                <div id="updateActions" class="mt-6 hidden">
                    <button onclick="closeUpdateModal()" class="w-full px-6 py-3 bg-black text-white rounded-xl hover:bg-gray-800 transition-all">
                        <?= __("Chiudi") ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = '<?= Csrf::ensureToken() ?>';
// formatDateLocale and appLocale are defined globally in layout.php

async function checkForUpdatesManual() {
    try {
        Swal.fire({
            title: '<?= __("Controllo aggiornamenti") ?>',
            text: '<?= __("Verifica in corso...") ?>',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => Swal.showLoading()
        });

        const response = await fetch('/admin/updates/check');
        const data = await response.json();

        if (data.available) {
            Swal.fire({
                icon: 'info',
                title: '<?= __("Aggiornamento disponibile!") ?>',
                text: `<?= __("Versione") ?> ${data.latest} <?= __("disponibile") ?>.`,
                confirmButtonText: '<?= __("OK") ?>'
            }).then(() => location.reload());
        } else if (data.error) {
            Swal.fire({
                icon: 'error',
                title: '<?= __("Errore") ?>',
                text: data.error
            });
        } else {
            Swal.fire({
                icon: 'success',
                title: '<?= __("Nessun aggiornamento") ?>',
                text: '<?= __("Non è stato trovato alcun aggiornamento") ?>'
            });
        }
    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: '<?= __("Errore") ?>',
            text: error.message
        });
    }
}

async function createBackup() {
    try {
        const result = await Swal.fire({
            title: '<?= __("Creare backup?") ?>',
            text: '<?= __("Verrà creato un backup completo del database.") ?>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '<?= __("Crea Backup") ?>',
            cancelButtonText: '<?= __("Annulla") ?>'
        });

        if (!result.isConfirmed) return;

        Swal.fire({
            title: '<?= __("Creazione backup...") ?>',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => Swal.showLoading()
        });

        const response = await fetch('/admin/updates/backup', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `csrf_token=${encodeURIComponent(csrfToken)}`
        });

        const data = await response.json();

        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: '<?= __("Backup creato!") ?>',
                text: data.message,
                timer: 1500,
                showConfirmButton: false
            });
            loadBackups(); // Aggiorna la lista dei backup
        } else {
            Swal.fire({
                icon: 'error',
                title: '<?= __("Errore") ?>',
                text: data.error
            });
        }
    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: '<?= __("Errore") ?>',
            text: error.message
        });
    }
}

async function startUpdate(version) {
    const result = await Swal.fire({
        title: '<?= __("Conferma aggiornamento") ?>',
        text: `<?= __("Stai per aggiornare Pinakes alla versione") ?> v${version}. <?= __("Verrà creato automaticamente un backup prima dell'aggiornamento.") ?>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '<?= __("Aggiorna") ?>',
        cancelButtonText: '<?= __("Annulla") ?>',
        confirmButtonColor: '#16a34a'
    });

    if (!result.isConfirmed) return;

    // Show progress modal
    document.getElementById('updateModal').classList.remove('hidden');
    setStepActive('backup');

    try {
        // Simulate step progress (actual update is single request)
        await sleep(500);
        setStepComplete('backup');
        setStepActive('download');

        // Perform the actual update
        const response = await fetch('/admin/updates/perform', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `csrf_token=${encodeURIComponent(csrfToken)}&version=${encodeURIComponent(version)}`
        });

        // Check response before parsing JSON
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            // Server returned HTML (error page or maintenance page)
            const text = await response.text();
            console.error('Server returned non-JSON response:', text.substring(0, 500));
            throw new Error('<?= __("Il server ha restituito una risposta non valida. Controlla i log per dettagli.") ?>');
        }

        if (!response.ok && response.status === 503) {
            throw new Error('<?= __("Server in manutenzione. Attendi il completamento dell\\'aggiornamento.") ?>');
        }

        const data = await response.json();

        if (data.success) {
            // Mark steps complete only on success
            setStepComplete('download');
            setStepActive('install');
            await sleep(300);
            setStepComplete('install');
            setStepActive('migrate');
            await sleep(300);
            setStepComplete('migrate');

            document.getElementById('updateIcon').innerHTML = '<i class="fas fa-check-circle text-green-600 text-3xl"></i>';
            document.getElementById('updateIcon').className = 'w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4';
            document.getElementById('updateTitle').textContent = '<?= __("Aggiornamento completato!") ?>';
            document.getElementById('updateMessage').textContent = '<?= __("Pinakes è stato aggiornato con successo.") ?>';
        } else {
            // Mark failed step with error indicator
            setStepFailed('download');
            document.getElementById('updateIcon').innerHTML = '<i class="fas fa-times-circle text-red-600 text-3xl"></i>';
            document.getElementById('updateIcon').className = 'w-20 h-20 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4';
            document.getElementById('updateTitle').textContent = '<?= __("Aggiornamento fallito") ?>';
            document.getElementById('updateMessage').textContent = data.error || '<?= __("Si è verificato un errore.") ?>';
        }

        document.getElementById('updateActions').classList.remove('hidden');

    } catch (error) {
        document.getElementById('updateIcon').innerHTML = '<i class="fas fa-times-circle text-red-600 text-3xl"></i>';
        document.getElementById('updateIcon').className = 'w-20 h-20 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4';
        document.getElementById('updateTitle').textContent = '<?= __("Errore") ?>';
        document.getElementById('updateMessage').innerHTML = escapeHtml(error.message) +
            '<br><br><button onclick="clearMaintenanceMode()" class="mt-2 px-4 py-2 text-sm bg-yellow-100 text-yellow-800 rounded-lg hover:bg-yellow-200">' +
            '<i class="fas fa-unlock mr-1"></i><?= __("Disattiva modalità manutenzione") ?></button>';
        document.getElementById('updateActions').classList.remove('hidden');
    }
}

function setStepActive(step) {
    const el = document.querySelector(`[data-step="${step}"]`);
    if (el) {
        el.querySelector('div').className = 'w-6 h-6 rounded-full bg-blue-500 flex items-center justify-center';
        el.querySelector('i').className = 'fas fa-spinner fa-spin text-white text-xs';
        el.querySelector('span').className = 'text-sm text-gray-900 font-medium';
    }
}

function setStepComplete(step) {
    const el = document.querySelector(`[data-step="${step}"]`);
    if (el) {
        el.querySelector('div').className = 'w-6 h-6 rounded-full bg-green-500 flex items-center justify-center';
        el.querySelector('i').className = 'fas fa-check text-white text-xs';
        el.querySelector('span').className = 'text-sm text-green-600';
    }
}

function setStepFailed(step) {
    const el = document.querySelector(`[data-step="${step}"]`);
    if (el) {
        el.querySelector('div').className = 'w-6 h-6 rounded-full bg-red-500 flex items-center justify-center';
        el.querySelector('i').className = 'fas fa-times text-white text-xs';
        el.querySelector('span').className = 'text-sm text-red-600 font-medium';
    }
}

function closeUpdateModal() {
    document.getElementById('updateModal').classList.add('hidden');
    location.reload();
}

async function clearMaintenanceMode() {
    try {
        const response = await fetch('/admin/updates/maintenance/clear', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `csrf_token=${encodeURIComponent(csrfToken)}`
        });

        const data = await response.json();

        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: '<?= __("Manutenzione disattivata") ?>',
                text: data.message,
                timer: 2000,
                showConfirmButton: false
            }).then(() => location.reload());
        } else {
            Swal.fire({
                icon: 'error',
                title: '<?= __("Errore") ?>',
                text: data.error
            });
        }
    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: '<?= __("Errore") ?>',
            text: error.message
        });
    }
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

// Backup Management
async function loadBackups() {
    const container = document.getElementById('backupListContainer');
    container.innerHTML = `
        <div class="p-12 text-center">
            <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-spinner fa-spin text-gray-400 text-xl"></i>
            </div>
            <p class="text-gray-600"><?= __("Caricamento backup...") ?></p>
        </div>
    `;

    try {
        const response = await fetch('/admin/updates/backups');
        const data = await response.json();

        if (data.error) {
            container.innerHTML = `
                <div class="p-6 text-center text-red-600">
                    <i class="fas fa-exclamation-triangle mr-2"></i>${escapeHtml(data.error)}
                </div>
            `;
            return;
        }

        if (!data.backups || data.backups.length === 0) {
            container.innerHTML = `
                <div class="p-12 text-center">
                    <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-database text-gray-400 text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2"><?= __("Nessun backup disponibile") ?></h3>
                    <p class="text-gray-600"><?= __("Crea un backup manuale o attendi il prossimo aggiornamento.") ?></p>
                </div>
            `;
            return;
        }

        let html = `
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Nome File") ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Data") ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Dimensione") ?></th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Azioni") ?></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
        `;

        data.backups.forEach(backup => {
            const date = new Date(backup.created_at * 1000);
            const formattedDate = formatDateLocale(date, true);

            html += `
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-file-code text-gray-400"></i>
                            <span class="font-mono text-gray-900">${escapeHtml(backup.name)}</span>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                        ${formattedDate}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                        ${formatBytes(backup.size)}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                        <button data-backup="${escapeHtml(backup.name)}" data-action="download"
                            class="btn-backup-download inline-flex items-center px-3 py-1.5 text-xs font-medium text-blue-700 bg-blue-100 rounded-lg hover:bg-blue-200 mr-2">
                            <i class="fas fa-download mr-1"></i>
                            <?= __("Scarica") ?>
                        </button>
                        <button data-backup="${escapeHtml(backup.name)}" data-action="delete"
                            class="btn-backup-delete inline-flex items-center px-3 py-1.5 text-xs font-medium text-red-700 bg-red-100 rounded-lg hover:bg-red-200">
                            <i class="fas fa-trash mr-1"></i>
                            <?= __("Elimina") ?>
                        </button>
                    </td>
                </tr>
            `;
        });

        html += `
                    </tbody>
                </table>
            </div>
        `;

        container.innerHTML = html;

    } catch (error) {
        container.innerHTML = `
            <div class="p-6 text-center text-red-600">
                <i class="fas fa-exclamation-triangle mr-2"></i>${escapeHtml(error.message)}
            </div>
        `;
    }
}

async function deleteBackup(backupName) {
    const result = await Swal.fire({
        title: '<?= __("Eliminare questo backup?") ?>',
        text: backupName,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '<?= __("Elimina") ?>',
        cancelButtonText: '<?= __("Annulla") ?>',
        confirmButtonColor: '#dc2626'
    });

    if (!result.isConfirmed) return;

    try {
        Swal.fire({
            title: '<?= __("Eliminazione in corso...") ?>',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => Swal.showLoading()
        });

        const response = await fetch('/admin/updates/backup/delete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `csrf_token=${encodeURIComponent(csrfToken)}&backup=${encodeURIComponent(backupName)}`
        });

        const data = await response.json();

        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: '<?= __("Backup eliminato") ?>',
                text: data.message,
                timer: 1500,
                showConfirmButton: false
            });
            loadBackups(); // Aggiorna subito, non aspetta il timer
        } else {
            Swal.fire({
                icon: 'error',
                title: '<?= __("Errore") ?>',
                text: data.error
            });
        }
    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: '<?= __("Errore") ?>',
            text: error.message
        });
    }
}

function downloadBackup(backupName) {
    window.location.href = `/admin/updates/backup/download?backup=${encodeURIComponent(backupName)}`;
}

function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Load backups on page load
document.addEventListener('DOMContentLoaded', loadBackups);

// Event delegation for backup buttons (safer than inline onclick)
document.addEventListener('click', (e) => {
    const downloadBtn = e.target.closest('.btn-backup-download');
    if (downloadBtn) {
        const backupName = downloadBtn.getAttribute('data-backup');
        if (backupName) downloadBackup(backupName);
        return;
    }

    const deleteBtn = e.target.closest('.btn-backup-delete');
    if (deleteBtn) {
        const backupName = deleteBtn.getAttribute('data-backup');
        if (backupName) deleteBackup(backupName);
        return;
    }
});

// Manual Update - Uppy Initialization
let uppyManualUpdate = null;
let uploadedFile = null;

document.addEventListener('DOMContentLoaded', function() {
    if (typeof Uppy === 'undefined' || typeof UppyDragDrop === 'undefined') {
        console.error('Uppy non caricato: verifica vendor bundle');
        return;
    }
    // Initialize Uppy for manual update
    uppyManualUpdate = new Uppy({
        restrictions: {
            maxFileSize: 50 * 1024 * 1024, // 50MB
            maxNumberOfFiles: 1,
            allowedFileTypes: ['.zip']
        },
        autoProceed: false
    });

    uppyManualUpdate.use(UppyDragDrop, {
        target: '#uppy-manual-update',
        note: '<?= addslashes(__("File ZIP del pacchetto di aggiornamento (max 50MB)")) ?>'
    });

    uppyManualUpdate.on('file-added', (file) => {
        uploadedFile = file;
        document.getElementById('manual-update-submit-btn').disabled = false;
    });

    uppyManualUpdate.on('file-removed', () => {
        uploadedFile = null;
        document.getElementById('manual-update-submit-btn').disabled = true;
    });
});

async function submitManualUpdate() {
    if (!uploadedFile) {
        Swal.fire({
            icon: 'error',
            title: '<?= __("Errore") ?>',
            text: '<?= __("Seleziona un file ZIP da caricare") ?>'
        });
        return;
    }

    const sanitizedName = escapeHtml(uploadedFile.name);
    const result = await Swal.fire({
        title: '<?= __("Avviare l\'aggiornamento manuale?") ?>',
        html: `<?= __("Verrà installato il pacchetto:") ?><br><code class="text-sm bg-gray-100 px-2 py-1 rounded">${sanitizedName}</code><br><br><?= __("Prima dell'installazione verrà creato automaticamente un backup del database.") ?>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '<?= __("Avvia Aggiornamento") ?>',
        cancelButtonText: '<?= __("Annulla") ?>',
        confirmButtonColor: '#16a34a'
    });

    if (!result.isConfirmed) return;

    const submitBtn = document.getElementById('manual-update-submit-btn');
    const btnText = document.getElementById('manual-update-btn-text');
    const originalText = btnText.textContent;

    submitBtn.disabled = true;
    btnText.textContent = '<?= __("Caricamento...") ?>';

    try {
        // Upload the file
        const formData = new FormData();
        formData.append('update_package', uploadedFile.data);
        formData.append('csrf_token', csrfToken);

        Swal.fire({
            title: '<?= __("Caricamento pacchetto...") ?>',
            html: '<div class="swal2-progress-bar"><div></div></div>',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => Swal.showLoading()
        });

        const uploadResponse = await fetch('/admin/updates/upload', {
            method: 'POST',
            body: formData
        });

        const uploadContentType = uploadResponse.headers.get('content-type') || '';
        if (!uploadContentType.includes('application/json')) {
            const text = await uploadResponse.text();
            console.error('Server returned non-JSON response:', text.substring(0, 500));
            throw new Error('<?= __("Il server ha restituito una risposta non valida. Controlla i log per dettagli.") ?>');
        }

        const uploadData = await uploadResponse.json();

        if (!uploadData.success) {
            throw new Error(uploadData.error || '<?= __("Errore durante il caricamento") ?>');
        }

        // Start update process
        btnText.textContent = '<?= __("Installazione...") ?>';

        Swal.fire({
            title: '<?= __("Installazione in corso...") ?>',
            html: `
                <div class="mb-4">
                    <p class="text-sm text-gray-600 mb-2"><?= __("L'aggiornamento può richiedere alcuni minuti. Non chiudere questa pagina.") ?></p>
                    <div class="bg-gray-100 rounded-lg p-4 space-y-2 text-sm text-left">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-spinner fa-spin text-green-600"></i>
                            <span><?= __("Creazione backup database...") ?></span>
                        </div>
                        <div class="flex items-center gap-2 text-gray-400">
                            <i class="far fa-circle"></i>
                            <span><?= __("Estrazione pacchetto...") ?></span>
                        </div>
                        <div class="flex items-center gap-2 text-gray-400">
                            <i class="far fa-circle"></i>
                            <span><?= __("Installazione file...") ?></span>
                        </div>
                        <div class="flex items-center gap-2 text-gray-400">
                            <i class="far fa-circle"></i>
                            <span><?= __("Completamento...") ?></span>
                        </div>
                    </div>
                </div>
            `,
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => Swal.showLoading()
        });

        const installResponse = await fetch('/admin/updates/install-manual', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `csrf_token=${encodeURIComponent(csrfToken)}`
        });

        const installContentType = installResponse.headers.get('content-type') || '';
        if (!installContentType.includes('application/json')) {
            const text = await installResponse.text();
            console.error('Server returned non-JSON response:', text.substring(0, 500));
            throw new Error('<?= __("Il server ha restituito una risposta non valida. Controlla i log per dettagli.") ?>');
        }

        const installData = await installResponse.json();

        if (installData.success) {
            Swal.fire({
                icon: 'success',
                title: '<?= __("Aggiornamento completato!") ?>',
                html: `<p>${escapeHtml(installData.message)}</p><p class="text-sm text-gray-600 mt-2"><?= __("La pagina verrà ricaricata automaticamente...") ?></p>`,
                timer: 3000,
                showConfirmButton: false
            }).then(() => {
                location.reload();
            });

            // Reset uppy
            uppyManualUpdate.cancelAll();
            uploadedFile = null;
        } else {
            throw new Error(installData.error || '<?= __("Errore durante l\'installazione") ?>');
        }

    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: '<?= __("Errore") ?>',
            text: error.message
        });
        btnText.textContent = originalText;
        submitBtn.disabled = false;
    }
}
</script>

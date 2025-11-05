<?php
use App\Support\HtmlHelper;
use App\Support\Csrf;

$pageTitle = 'Gestione Plugin';
?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="mb-8">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Plugin</h1>
                <p class="mt-2 text-sm text-gray-600">Gestisci le estensioni dell'applicazione</p>
            </div>
            <button onclick="openUploadModal()" class="inline-flex items-center px-6 py-3 bg-black text-white rounded-xl hover:bg-gray-800 transition-all duration-200 shadow-md hover:shadow-lg">
                <i class="fas fa-upload mr-2"></i>
                Carica Plugin
            </button>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600">Plugin Totali</p>
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
                    <p class="text-sm font-medium text-gray-600">Plugin Attivi</p>
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
                    <p class="text-sm font-medium text-gray-600">Plugin Inattivi</p>
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
            <h2 class="text-lg font-semibold text-gray-900">Plugin Installati</h2>
        </div>

        <div class="divide-y divide-gray-200">
            <?php if (empty($plugins)): ?>
                <div class="p-12 text-center">
                    <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-puzzle-piece text-gray-400 text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Nessun plugin installato</h3>
                    <p class="text-gray-600 mb-6">Inizia caricando il tuo primo plugin</p>
                    <button onclick="openUploadModal()" class="inline-flex items-center px-6 py-3 bg-black text-white rounded-xl hover:bg-gray-800 transition-all duration-200">
                        <i class="fas fa-upload mr-2"></i>
                        Carica Plugin
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($plugins as $plugin): ?>
                    <div class="p-6 hover:bg-gray-50 transition-colors" data-plugin-id="<?= $plugin['id'] ?>">
                        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                            <!-- Plugin Info -->
                            <div class="flex-1">
                                <div class="flex items-start gap-4">
                                    <div class="w-12 h-12 bg-gradient-to-br from-gray-100 to-gray-200 rounded-xl flex items-center justify-center flex-shrink-0">
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
                                                    <i class="fas fa-check-circle mr-1"></i>Attivo
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-500 rounded-lg">
                                                    <i class="fas fa-pause-circle mr-1"></i>Inattivo
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-sm text-gray-600 mb-3">
                                            <?= HtmlHelper::e($plugin['description'] ?? 'Nessuna descrizione disponibile') ?>
                                        </p>
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
                                                Installato: <?= date('d/m/Y', strtotime($plugin['installed_at'])) ?>
                                            </span>
                                            <?php if ($plugin['activated_at']): ?>
                                                <span>
                                                    <i class="fas fa-bolt mr-1"></i>
                                                    Attivato: <?= date('d/m/Y', strtotime($plugin['activated_at'])) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <?php if ($plugin['is_active']): ?>
                                    <button onclick="deactivatePlugin(<?= $plugin['id'] ?>)"
                                            class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-all duration-200 text-sm font-medium">
                                        <i class="fas fa-pause mr-1"></i>
                                        Disattiva
                                    </button>
                                <?php else: ?>
                                    <button onclick="activatePlugin(<?= $plugin['id'] ?>)"
                                            class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-all duration-200 text-sm font-medium">
                                        <i class="fas fa-check mr-1"></i>
                                        Attiva
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
            <h3 class="text-xl font-semibold text-gray-900">Carica Plugin</h3>
            <button onclick="closeUploadModal()" class="p-2 hover:bg-gray-100 rounded-lg transition-colors">
                <i class="fas fa-times text-gray-500"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="p-6">
            <div class="mb-6">
                <p class="text-sm text-gray-600 mb-4">
                    Carica un file ZIP contenente il plugin. Il file deve includere un <code class="px-2 py-1 bg-gray-100 rounded text-xs">plugin.json</code> con le informazioni del plugin.
                </p>
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <div class="flex gap-3">
                        <i class="fas fa-info-circle text-blue-600 mt-0.5"></i>
                        <div class="text-sm text-blue-800">
                            <p class="font-medium mb-1">Requisiti del plugin:</p>
                            <ul class="list-disc list-inside space-y-1 text-xs">
                                <li>File ZIP con struttura plugin valida</li>
                                <li>File <code>plugin.json</code> nella directory root</li>
                                <li>File principale PHP specificato in <code>plugin.json</code></li>
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
                        Annulla
                    </button>
                    <button type="button" id="uploadButton" class="px-6 py-3 bg-black text-white rounded-xl hover:bg-gray-800 transition-all duration-200 font-medium disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fas fa-upload mr-2"></i>
                        Installa Plugin
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://releases.transloadit.com/uppy/v3.3.1/uppy.min.js"></script>
<link href="https://releases.transloadit.com/uppy/v3.3.1/uppy.min.css" rel="stylesheet">

<script>
const csrfToken = '<?= Csrf::ensureToken() ?>';
let uppyInstance = null;
let selectedFile = null;

// Initialize Uppy
function initUppy() {
    if (uppyInstance) {
        return;
    }

    uppyInstance = new Uppy.Core({
        restrictions: {
            maxNumberOfFiles: 1,
            allowedFileTypes: ['.zip']
        },
        autoProceed: false
    });

    uppyInstance.use(Uppy.Dashboard, {
        target: '#uppy-dashboard',
        inline: true,
        height: 300,
        hideUploadButton: true,
        proudlyDisplayPoweredByUppy: false,
        locale: {
            strings: {
                dropPasteFiles: 'Trascina qui il file ZIP del plugin o %{browse}',
                browse: 'seleziona',
                uploadComplete: 'Caricamento completato',
                uploadFailed: 'Caricamento fallito',
                complete: 'Completato',
                uploading: 'Caricamento in corso...',
                error: 'Errore',
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
        uppyInstance.close();
        uppyInstance = null;
    }
    selectedFile = null;
}

document.getElementById('uploadButton')?.addEventListener('click', async function() {
    if (!selectedFile) {
        Swal.fire({
            icon: 'error',
            title: 'Errore',
            text: 'Seleziona un file ZIP del plugin.'
        });
        return;
    }

    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('plugin_file', selectedFile.data);

    this.disabled = true;
    this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Installazione in corso...';

    try {
        const response = await fetch('/admin/plugins/upload', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            await Swal.fire({
                icon: 'success',
                title: 'Successo',
                text: result.message
            });
            window.location.reload();
        } else {
            await Swal.fire({
                icon: 'error',
                title: 'Errore',
                text: result.message
            });
        }
    } catch (error) {
        await Swal.fire({
            icon: 'error',
            title: 'Errore',
            text: 'Errore durante l\'installazione del plugin.'
        });
    } finally {
        this.disabled = false;
        this.innerHTML = '<i class="fas fa-upload mr-2"></i>Installa Plugin';
    }
});

async function activatePlugin(pluginId) {
    const result = await Swal.fire({
        title: 'Conferma',
        text: 'Vuoi attivare questo plugin?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sì, attiva',
        cancelButtonText: 'Annulla',
        confirmButtonColor: '#000000'
    });

    if (!result.isConfirmed) {
        return;
    }

    try {
        const response = await fetch(`/admin/plugins/${pluginId}/activate`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ csrf_token: csrfToken })
        });

        const data = await response.json();

        if (data.success) {
            await Swal.fire({
                icon: 'success',
                title: 'Successo',
                text: data.message
            });
            window.location.reload();
        } else {
            await Swal.fire({
                icon: 'error',
                title: 'Errore',
                text: data.message
            });
        }
    } catch (error) {
        await Swal.fire({
            icon: 'error',
            title: 'Errore',
            text: 'Errore durante l\'attivazione del plugin.'
        });
    }
}

async function deactivatePlugin(pluginId) {
    const result = await Swal.fire({
        title: 'Conferma',
        text: 'Vuoi disattivare questo plugin?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sì, disattiva',
        cancelButtonText: 'Annulla',
        confirmButtonColor: '#6b7280'
    });

    if (!result.isConfirmed) {
        return;
    }

    try {
        const response = await fetch(`/admin/plugins/${pluginId}/deactivate`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ csrf_token: csrfToken })
        });

        const data = await response.json();

        if (data.success) {
            await Swal.fire({
                icon: 'success',
                title: 'Successo',
                text: data.message
            });
            window.location.reload();
        } else {
            await Swal.fire({
                icon: 'error',
                title: 'Errore',
                text: data.message
            });
        }
    } catch (error) {
        await Swal.fire({
            icon: 'error',
            title: 'Errore',
            text: 'Errore durante la disattivazione del plugin.'
        });
    }
}

async function uninstallPlugin(pluginId, pluginName) {
    const result = await Swal.fire({
        title: 'Conferma Disinstallazione',
        html: `Sei sicuro di voler disinstallare <strong>${pluginName}</strong>?<br><br>
               <span class="text-sm text-red-600">Questa azione eliminerà tutti i dati del plugin e non può essere annullata.</span>`,
        icon: 'error',
        showCancelButton: true,
        confirmButtonText: 'Sì, disinstalla',
        cancelButtonText: 'Annulla',
        confirmButtonColor: '#dc2626'
    });

    if (!result.isConfirmed) {
        return;
    }

    try {
        const response = await fetch(`/admin/plugins/${pluginId}/uninstall`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ csrf_token: csrfToken })
        });

        const data = await response.json();

        if (data.success) {
            await Swal.fire({
                icon: 'success',
                title: 'Successo',
                text: data.message
            });
            window.location.reload();
        } else {
            await Swal.fire({
                icon: 'error',
                title: 'Errore',
                text: data.message
            });
        }
    } catch (error) {
        await Swal.fire({
            icon: 'error',
            title: 'Errore',
            text: 'Errore durante la disinstallazione del plugin.'
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
                metadata = '<div class="mt-4 text-sm"><strong>Metadata:</strong><pre class="bg-gray-100 p-2 rounded mt-2 text-xs overflow-auto">' +
                           JSON.stringify(plugin.metadata, null, 2) + '</pre></div>';
            }

            await Swal.fire({
                title: plugin.display_name,
                html: `
                    <div class="text-left text-sm space-y-2">
                        <p><strong>Nome:</strong> ${plugin.name}</p>
                        <p><strong>Versione:</strong> ${plugin.version}</p>
                        ${plugin.author ? `<p><strong>Autore:</strong> ${plugin.author}</p>` : ''}
                        ${plugin.description ? `<p><strong>Descrizione:</strong> ${plugin.description}</p>` : ''}
                        ${plugin.requires_php ? `<p><strong>Richiede PHP:</strong> ${plugin.requires_php}+</p>` : ''}
                        ${plugin.requires_app ? `<p><strong>Richiede App:</strong> ${plugin.requires_app}+</p>` : ''}
                        <p><strong>File principale:</strong> ${plugin.main_file}</p>
                        <p><strong>Percorso:</strong> ${plugin.path}</p>
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
            title: 'Errore',
            text: 'Errore durante il caricamento dei dettagli del plugin.'
        });
    }
}
</script>

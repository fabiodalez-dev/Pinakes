<?php
/**
 * Digital Library Plugin - Admin Form Fields
 *
 * Renders enhanced upload fields for eBooks and audiobooks in the book form.
 * Uses Uppy for file uploads (following the existing pattern).
 */

use App\Support\HtmlHelper;

$currentFileUrl = $book['file_url'] ?? '';
$currentAudioUrl = $book['audio_url'] ?? '';
?>

<div class="mt-6 bg-gradient-to-br from-purple-50 to-indigo-50 border-2 border-purple-200 rounded-2xl p-6">
    <h3 class="text-lg font-bold text-purple-900 mb-4 flex items-center gap-2">
        <i class="fas fa-file-audio text-purple-600"></i>
        <?= __("Contenuti Digitali") ?>
    </h3>
    <p class="text-sm text-purple-700 mb-4">
        <i class="fas fa-info-circle mr-1"></i>
        <?= __("Carica o collega eBook (PDF/ePub) e audiobook (MP3/M4A) per renderli disponibili agli utenti.") ?>
    </p>

    <!-- eBook Section -->
    <div class="mb-6">
        <label for="file_url" class="form-label flex items-center gap-2">
            <i class="fas fa-file-pdf text-red-600"></i>
            <?= __("eBook (PDF/ePub)") ?>
        </label>

        <?php if (!empty($currentFileUrl)): ?>
        <div class="mb-3 p-3 bg-white border border-gray-200 rounded-lg flex items-center justify-between">
            <div class="flex items-center gap-2 text-sm">
                <i class="fas fa-check-circle text-green-500"></i>
                <span class="font-medium text-gray-700"><?= __("File attuale") ?>:</span>
                <a href="<?= HtmlHelper::e($currentFileUrl) ?>" target="_blank" class="text-blue-600 hover:underline truncate max-w-xs">
                    <?= HtmlHelper::e(basename($currentFileUrl)) ?>
                </a>
            </div>
            <button type="button"
                    onclick="document.getElementById('file_url').value=''; this.parentElement.remove();"
                    class="text-xs text-red-600 hover:text-red-800 flex items-center gap-1">
                <i class="fas fa-times"></i>
                <?= __("Rimuovi") ?>
            </button>
        </div>
        <?php endif; ?>

        <div class="flex flex-col md:flex-row gap-2">
            <input type="text"
                   id="file_url_display"
                   class="form-input w-full md:flex-1"
                   placeholder="<?= __('URL del file o carica usando il pulsante') ?>"
                   value="<?= HtmlHelper::e($currentFileUrl) ?>"
                   onchange="document.getElementById('file_url').value = this.value">
            <button type="button"
                    id="upload-ebook-btn"
                    class="btn btn-primary flex items-center justify-center gap-2 w-full md:w-auto">
                <i class="fas fa-upload"></i>
                <?= __("Carica") ?>
            </button>
        </div>

        <div id="ebook-uploader" class="mt-3 hidden"></div>
        <div id="ebook-progress" class="mt-2 hidden"></div>
        <div id="ebook-upload-result" class="mt-3 hidden"></div>

        <p class="text-xs text-gray-600 mt-2">
            <i class="fas fa-info-circle mr-1"></i>
            <?= __("Formati supportati: PDF, ePub • Dimensione massima: 100 MB") ?>
        </p>
    </div>

    <!-- Audiobook Section -->
    <div>
        <label for="audio_url" class="form-label flex items-center gap-2">
            <i class="fas fa-headphones text-green-600"></i>
            <?= __("Audiobook (MP3/M4A/OGG)") ?>
        </label>

        <?php if (!empty($currentAudioUrl)): ?>
        <div class="mb-3 p-3 bg-white border border-gray-200 rounded-lg flex items-center justify-between">
            <div class="flex items-center gap-2 text-sm">
                <i class="fas fa-check-circle text-green-500"></i>
                <span class="font-medium text-gray-700"><?= __("File attuale") ?>:</span>
                <a href="<?= HtmlHelper::e($currentAudioUrl) ?>" target="_blank" class="text-blue-600 hover:underline truncate max-w-xs">
                    <?= HtmlHelper::e(basename($currentAudioUrl)) ?>
                </a>
            </div>
            <button type="button"
                    onclick="document.getElementById('audio_url').value=''; this.parentElement.remove();"
                    class="text-xs text-red-600 hover:text-red-800 flex items-center gap-1">
                <i class="fas fa-times"></i>
                <?= __("Rimuovi") ?>
            </button>
        </div>
        <?php endif; ?>

        <div class="flex flex-col md:flex-row gap-2">
            <input type="text"
                   id="audio_url_display"
                   class="form-input w-full md:flex-1"
                   placeholder="<?= __('URL del file o carica usando il pulsante') ?>"
                   value="<?= HtmlHelper::e($currentAudioUrl) ?>"
                   onchange="document.getElementById('audio_url').value = this.value">
            <button type="button"
                    id="upload-audio-btn"
                    class="btn btn-primary flex items-center justify-center gap-2 w-full md:w-auto">
                <i class="fas fa-upload"></i>
                <?= __("Carica") ?>
            </button>
        </div>

        <div id="audio-uploader" class="mt-3 hidden"></div>
        <div id="audio-progress" class="mt-2 hidden"></div>
        <div id="audio-upload-result" class="mt-3 hidden"></div>

        <p class="text-xs text-gray-600 mt-2">
            <i class="fas fa-info-circle mr-1"></i>
            <?= __("Formati supportati: MP3, M4A, OGG • Dimensione massima: 500 MB") ?>
        </p>
    </div>
</div>

<script>
/**
 * Digital Library Upload Handlers
 * Uses existing Uppy instance to upload digital content
 */
document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    const csrfToken =
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
        document.querySelector('input[name="csrf_token"]')?.value ||
        '';

    const digitalUploaders = {
        ebook: null,
        audio: null
    };

    const libraryChecks = ['Uppy', 'UppyDragDrop', 'UppyProgressBar', 'UppyXHRUpload'];

    const showAlert = (icon, title, text) => {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon,
                title,
                text,
                timer: icon === 'success' ? 2200 : undefined,
                showConfirmButton: icon !== 'success'
            });
        } else {
            alert(title + '\n' + text);
        }
    };

    const waitForLibraries = (callback, attempts = 20) => {
        const missing = libraryChecks.filter((key) => typeof window[key] === 'undefined');
        if (missing.length === 0) {
            callback();
            return;
        }

        if (attempts <= 0) {
            console.warn('Digital uploads unavailable. Missing:', missing.join(', '));
            showAlert('error', '<?= __("Uploader non disponibile") ?>', '<?= __("Impossibile inizializzare Uppy per i contenuti digitali.") ?>');
            return;
        }

        setTimeout(() => waitForLibraries(callback, attempts - 1), 200);
    };

    const escapeHtml = (str) => {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str ?? '')));
        return div.innerHTML;
    };

    const bindUploadEvents = (uppyInstance, type) => {
        const inputId = type === 'audio' ? 'audio_url' : 'file_url';
        const displayId = type === 'audio' ? 'audio_url_display' : 'file_url_display';
        const resultId = type === 'audio' ? 'audio-upload-result' : 'ebook-upload-result';
        const resultEl = document.getElementById(resultId);

        uppyInstance.on('upload-success', (file, response) => {
            const body = response?.body || {};
            const uploadedUrl = body.uploadURL || (window.BASE_PATH || '') + `/uploads/digital/${file.name}`;
            const hiddenInput = document.getElementById(inputId);
            const displayInput = document.getElementById(displayId);

            if (hiddenInput) hiddenInput.value = uploadedUrl;
            if (displayInput) displayInput.value = uploadedUrl;

            if (resultEl) {
                const safeFileName = escapeHtml(file.name);
                const safeFileSize = escapeHtml((file.size / 1024 / 1024).toFixed(2));
                const safeUrl = escapeHtml(uploadedUrl);

                resultEl.classList.remove('hidden');
                resultEl.textContent = '';
                const wrapper = document.createElement('div');
                wrapper.className = 'flex items-center justify-between rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm';

                const infoDiv = document.createElement('div');
                infoDiv.className = 'flex items-center gap-3 text-sm text-gray-800';

                const icon = document.createElement('i');
                icon.className = 'fas fa-check-circle text-green-500 text-lg';
                infoDiv.appendChild(icon);

                const textDiv = document.createElement('div');
                textDiv.className = 'flex flex-col';

                const nameSpan = document.createElement('span');
                nameSpan.className = 'font-semibold';
                nameSpan.textContent = file.name;
                textDiv.appendChild(nameSpan);

                const sizeSpan = document.createElement('span');
                sizeSpan.className = 'text-xs text-gray-500';
                sizeSpan.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
                textDiv.appendChild(sizeSpan);

                infoDiv.appendChild(textDiv);
                wrapper.appendChild(infoDiv);

                const link = document.createElement('a');
                link.href = uploadedUrl;
                link.target = '_blank';
                link.className = 'text-xs text-purple-600 hover:underline';
                link.textContent = '<?= __("Apri file") ?>';
                wrapper.appendChild(link);

                resultEl.appendChild(wrapper);
            }

            const successTitle = type === 'audio'
                ? '<?= __("Audiobook caricato!") ?>'
                : '<?= __("eBook caricato!") ?>';

            showAlert('success', successTitle, file.name);
        });

        uppyInstance.on('upload-error', (file, error, response) => {
            console.error(`Digital ${type} upload error:`, error, response);
            if (resultEl) {
                resultEl.classList.add('hidden');
                resultEl.innerHTML = '';
            }
            const errorTitle = type === 'audio'
                ? '<?= __("Errore caricamento Audiobook") ?>'
                : '<?= __("Errore caricamento eBook") ?>';

            showAlert('error', errorTitle, error?.message || 'Upload failed');
        });
    };

    const initUploader = (type) => {
        if (digitalUploaders[type]) {
            return digitalUploaders[type];
        }

        const isAudio = type === 'audio';
        const targetSelector = isAudio ? '#audio-uploader' : '#ebook-uploader';
        const progressSelector = isAudio ? '#audio-progress' : '#ebook-progress';

        const restrictionConfig = isAudio
            ? {
                maxFileSize: 500 * 1024 * 1024,
                maxNumberOfFiles: 1,
                allowedFileTypes: ['.mp3', '.m4a', '.ogg', 'audio/mpeg', 'audio/mp4', 'audio/ogg']
            }
            : {
                maxFileSize: 100 * 1024 * 1024,
                maxNumberOfFiles: 1,
                allowedFileTypes: ['.pdf', '.epub', 'application/pdf', 'application/epub+zip']
            };

        const uppyInstance = new Uppy({
            restrictions: restrictionConfig,
            autoProceed: true,
            meta: {
                digital_type: isAudio ? 'audio' : 'ebook',
                csrf_token: csrfToken
            }
        });

        uppyInstance.use(UppyDragDrop, {
            target: targetSelector,
            note: isAudio
                ? '<?= __("MP3, M4A o OGG, max 500 MB") ?>'
                : '<?= __("PDF o ePub, max 100 MB") ?>'
        });

        uppyInstance.use(UppyProgressBar, {
            target: progressSelector,
            hideAfterFinish: false
        });

        if (typeof UppyXHRUpload !== 'undefined') {
            uppyInstance.use(UppyXHRUpload, {
                endpoint: (window.BASE_PATH || '') + '/admin/plugins/digital-library/upload',
                fieldName: 'file',
                formData: true,
                headers: {
                    'X-CSRF-Token': csrfToken
                }
            });
        }

        bindUploadEvents(uppyInstance, type);

        digitalUploaders[type] = uppyInstance;
        return uppyInstance;
    };

    const bindButton = (type) => {
        const buttonId = type === 'audio' ? 'upload-audio-btn' : 'upload-ebook-btn';
        const button = document.getElementById(buttonId);
        const uploaderEl = document.getElementById(type === 'audio' ? 'audio-uploader' : 'ebook-uploader');
        const progressEl = document.getElementById(type === 'audio' ? 'audio-progress' : 'ebook-progress');

        if (!button || !uploaderEl || !progressEl) {
            return;
        }

        button.addEventListener('click', function() {
            uploaderEl.classList.toggle('hidden');

            if (uploaderEl.classList.contains('hidden')) {
                progressEl.classList.add('hidden');
                return;
            }

            progressEl.classList.remove('hidden');
            waitForLibraries(() => initUploader(type));
        });
    };

    bindButton('ebook');
    bindButton('audio');
});
</script>

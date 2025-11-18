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

        <div class="flex gap-2">
            <input type="text"
                   id="file_url_display"
                   class="form-input flex-1"
                   placeholder="<?= __('URL del file o carica usando il pulsante') ?>"
                   value="<?= HtmlHelper::e($currentFileUrl) ?>"
                   onchange="document.getElementById('file_url').value = this.value">
            <button type="button"
                    id="upload-ebook-btn"
                    class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors flex items-center gap-2">
                <i class="fas fa-upload"></i>
                <?= __("Carica") ?>
            </button>
        </div>

        <div id="ebook-uploader" class="mt-3 hidden"></div>
        <div id="ebook-progress" class="mt-2 hidden"></div>

        <p class="text-xs text-gray-600 mt-2">
            <i class="fas fa-info-circle mr-1"></i>
            <?= __("Formati supportati: PDF, ePub • Dimensione massima: 50 MB") ?>
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

        <div class="flex gap-2">
            <input type="text"
                   id="audio_url_display"
                   class="form-input flex-1"
                   placeholder="<?= __('URL del file o carica usando il pulsante') ?>"
                   value="<?= HtmlHelper::e($currentAudioUrl) ?>"
                   onchange="document.getElementById('audio_url').value = this.value">
            <button type="button"
                    id="upload-audio-btn"
                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors flex items-center gap-2">
                <i class="fas fa-upload"></i>
                <?= __("Carica") ?>
            </button>
        </div>

        <div id="audio-uploader" class="mt-3 hidden"></div>
        <div id="audio-progress" class="mt-2 hidden"></div>

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
(function() {
    'use strict';

    // Wait for Uppy to be available
    if (typeof Uppy === 'undefined') {
        console.warn('Uppy not loaded - digital content upload disabled');
        return;
    }

    let ebookUppy = null;
    let audioUppy = null;

    // eBook Upload
    document.getElementById('upload-ebook-btn')?.addEventListener('click', function() {
        const uploaderEl = document.getElementById('ebook-uploader');
        const progressEl = document.getElementById('ebook-progress');

        // Toggle visibility
        uploaderEl.classList.toggle('hidden');

        if (!uploaderEl.classList.contains('hidden') && !ebookUppy) {
            // Initialize Uppy for eBook
            ebookUppy = new Uppy({
                restrictions: {
                    maxFileSize: 50 * 1024 * 1024, // 50MB
                    maxNumberOfFiles: 1,
                    allowedFileTypes: ['.pdf', '.epub', 'application/pdf', 'application/epub+zip']
                },
                autoProceed: false
            });

            ebookUppy.use(UppyDragDrop, {
                target: '#ebook-uploader',
                note: '<?= __("PDF o ePub, max 50 MB") ?>'
            });

            ebookUppy.use(UppyProgressBar, {
                target: '#ebook-progress',
                hideAfterFinish: false
            });

            // Handle successful upload
            ebookUppy.on('upload-success', (file, response) => {
                const uploadedUrl = response.uploadURL || `/uploads/digital/${file.name}`;
                document.getElementById('file_url').value = uploadedUrl;
                document.getElementById('file_url_display').value = uploadedUrl;

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: '<?= __("eBook caricato!") ?>',
                        text: file.name,
                        timer: 2000,
                        showConfirmButton: false
                    });
                }
            });

            progressEl.classList.remove('hidden');
        }
    });

    // Audiobook Upload
    document.getElementById('upload-audio-btn')?.addEventListener('click', function() {
        const uploaderEl = document.getElementById('audio-uploader');
        const progressEl = document.getElementById('audio-progress');

        // Toggle visibility
        uploaderEl.classList.toggle('hidden');

        if (!uploaderEl.classList.contains('hidden') && !audioUppy) {
            // Initialize Uppy for audiobook
            audioUppy = new Uppy({
                restrictions: {
                    maxFileSize: 500 * 1024 * 1024, // 500MB
                    maxNumberOfFiles: 1,
                    allowedFileTypes: ['.mp3', '.m4a', '.ogg', 'audio/mpeg', 'audio/mp4', 'audio/ogg']
                },
                autoProceed: false
            });

            audioUppy.use(UppyDragDrop, {
                target: '#audio-uploader',
                note: '<?= __("MP3, M4A o OGG, max 500 MB") ?>'
            });

            audioUppy.use(UppyProgressBar, {
                target: '#audio-progress',
                hideAfterFinish: false
            });

            // Handle successful upload
            audioUppy.on('upload-success', (file, response) => {
                const uploadedUrl = response.uploadURL || `/uploads/digital/${file.name}`;
                document.getElementById('audio_url').value = uploadedUrl;
                document.getElementById('audio_url_display').value = uploadedUrl;

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: '<?= __("Audiobook caricato!") ?>',
                        text: file.name,
                        timer: 2000,
                        showConfirmButton: false
                    });
                }
            });

            progressEl.classList.remove('hidden');
        }
    });
})();
</script>

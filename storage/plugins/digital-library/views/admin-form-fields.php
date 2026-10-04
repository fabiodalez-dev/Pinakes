<?php
/** Multiple files are edited as ordinary fields so saving also works without JavaScript. */
use App\Support\HtmlHelper;
$book = $book ?? [];
$attachments = \App\Support\DigitalAttachments::fromBook($book, true);
$attachmentKinds = ['ebook' => __('Edizione digitale'), 'supplement' => __('Recensione o documento correlato'), 'audio' => __('Audiobook')];
?>
<section class="digital-attachments-editor" aria-labelledby="digital-attachments-title">
    <h3 id="digital-attachments-title" class="form-section-title"><?= __('Contenuti Digitali') ?></h3>
    <p><?= __('Aggiungi più edizioni, recensioni, articoli correlati o file audio alla stessa scheda. Gli allegati saranno disponibili nella scheda pubblica.') ?></p>
    <input type="hidden" name="digital_attachments_present" value="1">
    <input type="hidden" id="file_url" name="file_url" value="<?= HtmlHelper::e($book['file_url'] ?? '') ?>">
    <input type="hidden" id="audio_url" name="audio_url" value="<?= HtmlHelper::e($book['audio_url'] ?? '') ?>">
    <div id="digital-attachment-rows">
        <?php foreach ($attachments as $index => $attachment): ?>
        <div class="digital-attachment-row" data-attachment-row>
            <label><?= __('Titolo allegato') ?><input class="form-input" name="digital_attachments[<?= $index ?>][label]" maxlength="255" value="<?= HtmlHelper::e($attachment['label']) ?>"></label>
            <label><?= __('Tipo di allegato') ?><select class="form-input" name="digital_attachments[<?= $index ?>][kind]">
                <?php foreach ($attachmentKinds as $kind => $label): ?><option value="<?= $kind ?>" <?= $attachment['kind'] === $kind ? 'selected' : '' ?>><?= HtmlHelper::e($label) ?></option><?php endforeach; ?>
            </select></label>
            <label><?= __('URL del file') ?><input class="form-input" name="digital_attachments[<?= $index ?>][url]" maxlength="2048" value="<?= HtmlHelper::e($attachment['url']) ?>"></label>
            <button type="button" class="ui-button btn-outline" data-remove-attachment><?= __('Rimuovi') ?></button>
            <?php if (!empty($attachment['invalid'])): ?>
            <p class="digital-attachment-invalid" role="alert"><?= __('Questo link salvato non è valido: correggilo o rimuovilo prima di salvare.') ?></p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="ui-button btn-outline" id="add-digital-attachment"><?= __('Aggiungi allegato') ?></button>
    <p><?= __('Per rimuovere un allegato, elimina la riga o svuota il suo URL. Il file originale rimane disponibile agli altri record che lo usano.') ?></p>
    <div class="digital-attachment-uploaders">
        <div>
            <button type="button" id="upload-ebook-btn" class="ui-button btn-primary"><?= __('Carica PDF o ePub') ?></button>
            <div id="ebook-uploader" class="mt-3 hidden"></div>
            <div id="ebook-progress" class="mt-2 hidden"></div>
            <div id="ebook-upload-result" class="mt-3 hidden"></div>
            <p><?= __('Formati supportati: PDF, ePub • Dimensione massima: 100 MB') ?></p>
        </div>
        <div>
            <button type="button" id="upload-audio-btn" class="ui-button btn-primary"><?= __('Carica file audio') ?></button>
            <div id="audio-uploader" class="mt-3 hidden"></div>
            <div id="audio-progress" class="mt-2 hidden"></div>
            <div id="audio-upload-result" class="mt-3 hidden"></div>
            <p><?= __('Formati supportati: MP3, M4A, OGG • Dimensione massima: 500 MB') ?></p>
        </div>
    </div>
</section>

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

    const attachmentRows = document.getElementById('digital-attachment-rows');
    let nextAttachment = <?= count($attachments) ?>;
    const attachmentLabels = <?= json_encode(['label'=>__('Titolo allegato'), 'kind'=>__('Tipo di allegato'), 'url'=>__('URL del file'), 'remove'=>__('Rimuovi')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const attachmentKinds = <?= json_encode($attachmentKinds, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const addAttachment = (url = '', label = '', kind = 'ebook') => {
        const index = nextAttachment++;
        const row = document.createElement('div');
        row.className = 'digital-attachment-row';
        row.dataset.attachmentRow = '';
        for (const field of ['label', 'kind', 'url']) {
            const wrap = document.createElement('label');
            wrap.appendChild(document.createTextNode(attachmentLabels[field]));
            const input = document.createElement(field === 'kind' ? 'select' : 'input');
            input.className = 'form-input';
            input.name = `digital_attachments[${index}][${field}]`;
            if (field === 'kind') {
                for (const [value, text] of Object.entries(attachmentKinds)) {
                    const option = document.createElement('option');
                    option.value = value; option.textContent = text; input.appendChild(option);
                }
            } else { input.maxLength = field === 'url' ? 2048 : 255; }
            input.value = field === 'url' ? url : field === 'label' ? label : kind;
            wrap.appendChild(input); row.appendChild(wrap);
        }
        const remove = document.createElement('button');
        remove.type = 'button'; remove.className = 'ui-button btn-outline';
        remove.dataset.removeAttachment = ''; remove.textContent = attachmentLabels.remove;
        row.appendChild(remove); attachmentRows.appendChild(row);
        return row;
    };
    document.getElementById('add-digital-attachment').addEventListener('click', () => {
        addAttachment().querySelector('input').focus();
    });
    attachmentRows.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-attachment]');
        if (button) button.closest('[data-attachment-row]').remove();
    });

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
            showAlert('error', <?= json_encode(__("Uploader non disponibile"), JSON_HEX_TAG) ?>, <?= json_encode(__("Impossibile inizializzare Uppy per i contenuti digitali."), JSON_HEX_TAG) ?>);
            return;
        }

        setTimeout(() => waitForLibraries(callback, attempts - 1), 200);
    };

    const bindUploadEvents = (uppyInstance, type) => {
        const resultId = type === 'audio' ? 'audio-upload-result' : 'ebook-upload-result';
        const resultEl = document.getElementById(resultId);

        uppyInstance.on('upload-success', (file, response) => {
            const body = response?.body || {};
            if (!body.success || !body.uploadURL) { return; }
            const storedUrl = body.uploadURL;
            const displayLinkUrl = body.uploadURL || (window.BASE_PATH || '') + `/uploads/digital/${encodeURIComponent(file.name)}`;

            addAttachment(storedUrl, file.name, type === 'audio' ? 'audio' : 'ebook');

            if (resultEl) {
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
                link.href = displayLinkUrl;
                link.target = '_blank';
                link.className = 'text-xs text-blue-600 hover:underline';
                link.textContent = <?= json_encode(__("Apri file"), JSON_HEX_TAG) ?>;
                wrapper.appendChild(link);

                resultEl.appendChild(wrapper);
            }

            const successTitle = type === 'audio'
                ? <?= json_encode(__("Audiobook caricato!"), JSON_HEX_TAG) ?>
                : <?= json_encode(__("eBook caricato!"), JSON_HEX_TAG) ?>;

            showAlert('success', successTitle, file.name);
        });

        uppyInstance.on('upload-error', (file, error, response) => {
            console.error(`Digital ${type} upload error:`, error, response);
            if (resultEl) {
                resultEl.classList.add('hidden');
                resultEl.innerHTML = '';
            }
            const errorTitle = type === 'audio'
                ? <?= json_encode(__("Errore caricamento Audiobook"), JSON_HEX_TAG) ?>
                : <?= json_encode(__("Errore caricamento eBook"), JSON_HEX_TAG) ?>;

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
                allowedFileTypes: ['.mp3', '.m4a', '.ogg', 'audio/mpeg', 'audio/mp4', 'audio/ogg']
            }
            : {
                maxFileSize: 100 * 1024 * 1024,
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
                ? <?= json_encode(__("MP3, M4A o OGG, max 500 MB"), JSON_HEX_TAG) ?>
                : <?= json_encode(__("PDF o ePub, max 100 MB"), JSON_HEX_TAG) ?>,
            locale: {
                strings: {
                    dropHereOr: isAudio
                        ? <?= json_encode(__("Trascina qui l'audiolibro o %{browse}"), JSON_HEX_TAG) ?>
                        : <?= json_encode(__("Trascina qui l'eBook o %{browse}"), JSON_HEX_TAG) ?>,
                    dropPasteFiles: isAudio
                        ? <?= json_encode(__("Trascina qui l'audiolibro o %{browse}"), JSON_HEX_TAG) ?>
                        : <?= json_encode(__("Trascina qui l'eBook o %{browse}"), JSON_HEX_TAG) ?>,
                    browse: <?= json_encode(__("seleziona file"), JSON_HEX_TAG) ?>
                }
            }
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

(function () {
    'use strict';

    function showError(message) {
        var title = typeof window.__ === 'function' ? window.__('Errore Upload') : 'Errore Upload';
        if (window.SwalApp && typeof window.SwalApp.error === 'function') {
            window.SwalApp.error(title, message);
        } else if (window.Swal && typeof window.Swal.fire === 'function') {
            window.Swal.fire({ icon: 'error', title: title, text: message });
        }
    }

    function transferToInput(file, input) {
        var transfer = new DataTransfer();
        var nativeFile = file.data instanceof File
            ? file.data
            : new File([file.data], file.name, { type: file.type });
        transfer.items.add(nativeFile);
        input.files = transfer.files;
    }

    function showImagePreview(mount, previewUrl) {
        var wrapper = document.getElementById(mount.dataset.preview || '');
        var image = document.getElementById(mount.dataset.previewImage || '');
        if (!wrapper || !image) {
            return;
        }
        image.src = previewUrl;
        image.hidden = false;
        wrapper.classList.remove('is-empty');
        var placeholder = wrapper.querySelector('i');
        if (placeholder) {
            placeholder.hidden = true;
        }
    }

    function showFileResult(mount, file) {
        var result = document.getElementById(mount.dataset.result || '');
        if (!result) {
            return;
        }
        result.textContent = '';
        var icon = document.createElement('i');
        icon.className = 'fas fa-check-circle';
        icon.setAttribute('aria-hidden', 'true');
        var label = document.createElement('span');
        var megabytes = file.size ? ' · ' + (file.size / 1024 / 1024).toFixed(2) + ' MB' : '';
        label.textContent = file.name + megabytes;
        result.appendChild(icon);
        result.appendChild(label);
        result.hidden = false;
    }

    function enableNativeFallback(mount, input) {
        mount.hidden = true;
        input.hidden = false;
        input.classList.add('form-input', 'text-sm');
    }

    function initializeMount(mount, attempts) {
        var input = document.getElementById(mount.dataset.input || '');
        if (!input) {
            return;
        }
        var progress = document.getElementById(mount.dataset.progress || '');
        var kind = mount.dataset.emtUppy;
        var isImage = kind === 'image';
        var previewImage = isImage ? document.getElementById(mount.dataset.previewImage || '') : null;
        var originalPreview = previewImage ? previewImage.getAttribute('src') || '' : '';

        if (typeof window.Uppy === 'undefined' || typeof window.UppyDragDrop === 'undefined') {
            if (attempts > 0) {
                window.setTimeout(function () {
                    initializeMount(mount, attempts - 1);
                }, 200);
            } else {
                enableNativeFallback(mount, input);
            }
            return;
        }

        try {
            var uppy = new window.Uppy({
                restrictions: isImage ? {
                    maxFileSize: 5 * 1024 * 1024,
                    maxNumberOfFiles: 1,
                    allowedFileTypes: ['image/jpeg', 'image/jpg', 'image/png', 'image/webp']
                } : {
                    maxFileSize: 100 * 1024 * 1024,
                    maxNumberOfFiles: 1,
                    allowedFileTypes: ['.pdf', 'application/pdf']
                },
                autoProceed: false
            });

            uppy.use(window.UppyDragDrop, {
                target: '#' + mount.id,
                note: mount.dataset.note || '',
                locale: {
                    strings: {
                        // Uppy 4.x uses dropHereOr; keep the older key too so
                        // the plugin remains compatible with Pinakes bundles
                        // built against Uppy 3.x.
                        dropHereOr: mount.dataset.drop || 'Trascina qui il file o %{browse}',
                        dropPasteFiles: mount.dataset.drop || 'Trascina qui il file o %{browse}',
                        browse: mount.dataset.browse || 'seleziona file'
                    }
                }
            });
            if (progress && typeof window.UppyProgressBar !== 'undefined') {
                uppy.use(window.UppyProgressBar, {
                    target: '#' + progress.id,
                    hideAfterFinish: false
                });
            }
            if (isImage && typeof window.UppyThumbnailGenerator !== 'undefined') {
                uppy.use(window.UppyThumbnailGenerator, { thumbnailWidth: 400 });
                uppy.on('thumbnail:generated', function (file, preview) {
                    showImagePreview(mount, preview);
                });
            }

            uppy.on('file-added', function (file) {
                transferToInput(file, input);
                if (!isImage) {
                    showFileResult(mount, file);
                }
            });
            uppy.on('file-removed', function () {
                input.value = '';
                var result = document.getElementById(mount.dataset.result || '');
                if (result) {
                    result.hidden = true;
                    result.textContent = '';
                }
                if (isImage && previewImage && originalPreview !== '') {
                    showImagePreview(mount, originalPreview);
                } else if (isImage && previewImage) {
                    var wrapper = document.getElementById(mount.dataset.preview || '');
                    previewImage.removeAttribute('src');
                    previewImage.hidden = true;
                    if (wrapper) {
                        wrapper.classList.add('is-empty');
                        var placeholder = wrapper.querySelector('i');
                        if (placeholder) {
                            placeholder.hidden = false;
                        }
                    }
                }
            });
            uppy.on('restriction-failed', function (file, error) {
                showError(error.message);
            });
        } catch (error) {
            console.error('Emeroteca Uppy init failed:', error);
            enableNativeFallback(mount, input);
        }
    }

    function initialize() {
        document.querySelectorAll('[data-emt-uppy]').forEach(function (mount) {
            initializeMount(mount, 20);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
})();

<?php
/**
 * OAI-PMH Plugin — Digital Assets section for the book edit form.
 *
 * @var int                            $bookId
 * @var array<int,array<string,mixed>> $assets   rows from digital_assets
 * @var string                         $csrfToken
 */
use App\Support\HtmlHelper;

$addUrl     = htmlspecialchars(url('/admin/api/books/' . $bookId . '/digital-assets'), ENT_QUOTES, 'UTF-8');
$deleteBase = url('/admin/api/books/' . $bookId . '/digital-assets');

// FIX F068: idempotent declaration (view may be included multiple times via hook)
if (!function_exists('oaiFormatBytes')) {
    function oaiFormatBytes(int $bytes): string
    {
        if ($bytes <= 0) return '';
        if ($bytes >= 1_048_576) return number_format($bytes / 1_048_576, 1) . ' MB';
        if ($bytes >= 1024)      return number_format($bytes / 1024, 1)      . ' KB';
        return $bytes . ' B';
    }
}

// Accordion (#274): collapsed by default to keep the book form uncluttered;
// opens automatically when the book already has digital assets (editing a
// digitised record) or when the user expands it. JS opens it on add too.
$oaiHasData = !empty($assets);
?>

<div class="mt-6 bg-gradient-to-br from-teal-50 to-cyan-50 border-2 border-teal-200 rounded-2xl p-6 dark:from-teal-900/20 dark:to-cyan-900/20 dark:border-teal-800"
     id="oai-digital-assets-section">
    <button type="button" id="oai-digital-assets-toggle"
            class="w-full flex items-center justify-between gap-2 text-left"
            aria-expanded="<?= $oaiHasData ? 'true' : 'false' ?>" aria-controls="oai-digital-assets-body">
        <span class="text-lg font-bold text-teal-900 dark:text-teal-100 flex items-center gap-2">
            <i class="fas fa-file-image text-teal-600"></i>
            <?= __("Copie Digitalizzate (MAG/ICCU)") ?>
        </span>
        <i class="fas fa-chevron-down text-teal-600 transition-transform duration-200 <?= $oaiHasData ? 'rotate-180' : '' ?>"
           id="oai-digital-assets-chevron"></i>
    </button>

    <div id="oai-digital-assets-body" class="<?= $oaiHasData ? 'mt-4' : 'hidden' ?>">
    <p class="text-sm text-teal-700 dark:text-teal-300 mb-4">
        <i class="fas fa-info-circle mr-1"></i>
        <?= __("Metadati delle copie digitali usati nell'esportazione OAI-PMH formato MAG per ICCU/Internet Culturale.") ?>
    </p>

    <!-- Assets table -->
    <p id="oai-empty-msg" class="text-sm text-teal-600 dark:text-teal-400 italic mb-4<?= empty($assets) ? '' : ' hidden' ?>">
        <?= __("Nessuna copia digitalizzata registrata.") ?>
    </p>

    <div id="oai-assets-table-wrap" class="overflow-x-auto mb-4<?= empty($assets) ? ' hidden' : '' ?>">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-teal-800 dark:text-teal-200 border-b border-teal-200 dark:border-teal-700">
                    <th class="pb-2 pr-4 font-semibold"><?= __("URL") ?></th>
                    <th class="pb-2 pr-4 font-semibold"><?= __("Tipo") ?></th>
                    <th class="pb-2 pr-4 font-semibold"><?= __("Dimensione") ?></th>
                    <th class="pb-2 pr-4 font-semibold"><?= __("Immagine") ?></th>
                    <th class="pb-2 pr-4 font-semibold">PPI</th>
                    <th class="pb-2 font-semibold"></th>
                </tr>
            </thead>
            <tbody id="oai-assets-tbody">
            <?php foreach ($assets as $asset): ?>
                <tr class="border-b border-teal-100 dark:border-teal-800/50" data-asset-id="<?= (int) $asset['id'] ?>">
                    <td class="py-2 pr-4 max-w-xs">
                        <?php
                        $assetScheme = strtolower((string) parse_url((string) $asset['url'], PHP_URL_SCHEME));
                        $assetUrlSafe = in_array($assetScheme, ['http', 'https'], true);
                        ?>
                        <?php if ($assetUrlSafe): ?>
                        <a href="<?= HtmlHelper::e((string) $asset['url']) ?>" target="_blank"
                           rel="noopener noreferrer"
                           class="text-teal-700 dark:text-teal-300 hover:underline truncate block max-w-xs">
                            <?= HtmlHelper::e(basename((string) $asset['url'])) ?>
                        </a>
                        <?php else: ?>
                        <span class="text-gray-400 truncate block max-w-xs"><?= HtmlHelper::e(basename((string) $asset['url'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="py-2 pr-4 font-mono text-xs"><?= HtmlHelper::e((string) $asset['filetype']) ?></td>
                    <td class="py-2 pr-4 text-gray-600 dark:text-gray-400">
                        <?php $fs = oaiFormatBytes((int) $asset['filesize']); ?>
                        <?= $fs !== '' ? HtmlHelper::e($fs) : '<span class="text-gray-400">—</span>' ?>
                    </td>
                    <td class="py-2 pr-4 text-gray-600 dark:text-gray-400 text-xs">
                        <?php if ((int) $asset['image_width'] > 0 && (int) $asset['image_height'] > 0): ?>
                            <?= (int) $asset['image_width'] ?>×<?= (int) $asset['image_height'] ?>
                        <?php else: ?>
                            <span class="text-gray-400">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-2 pr-4 text-gray-600 dark:text-gray-400">
                        <?= (int) $asset['ppi'] > 0 ? (int) $asset['ppi'] : '<span class="text-gray-400">—</span>' ?>
                    </td>
                    <td class="py-2">
                        <button type="button"
                                class="oai-delete-asset text-red-500 hover:text-red-700 transition-colors"
                                data-asset-id="<?= (int) $asset['id'] ?>"
                                title="<?= __("Elimina copia digitalizzata") ?>"
                                aria-label="<?= __("Elimina copia digitalizzata") ?>">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Add form -->
    <div>
        <button type="button" id="oai-toggle-add-form"
                class="inline-flex items-center gap-2 px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors">
            <i class="fas fa-plus"></i>
            <?= __("Aggiungi copia digitalizzata") ?>
        </button>

        <div id="oai-add-form" class="hidden mt-4 bg-white dark:bg-gray-800 rounded-xl border border-teal-200 dark:border-teal-700 p-4">
            <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">
                <?= __("Nuova copia digitalizzata") ?>
            </h4>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 mb-3">
                <div class="lg:col-span-2">
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                        <?= __("URL file") ?> <span class="text-red-500">*</span>
                    </label>
                    <input type="url" id="oai-new-url" required
                           placeholder="https://archivio.example.org/files/libro.pdf"
                           class="form-input w-full text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                        <?= __("Tipo file") ?>
                    </label>
                    <select id="oai-new-filetype" class="form-input w-full text-sm">
                        <option value="PDF">PDF</option>
                        <option value="TIFF">TIFF</option>
                        <option value="JPEG">JPEG</option>
                        <option value="PNG">PNG</option>
                        <option value="EPUB">EPUB</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                        <?= __("Dimensione (byte)") ?>
                    </label>
                    <input type="number" id="oai-new-filesize" min="0" placeholder="0"
                           class="form-input w-full text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                        MD5 hash
                    </label>
                    <input type="text" id="oai-new-md5" maxlength="32" pattern="[0-9a-fA-F]{32}"
                           placeholder="<?= __("facoltativo (32 hex)") ?>"
                           class="form-input w-full text-sm font-mono">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                        <?= __("Larghezza × Altezza (px)") ?>
                    </label>
                    <div class="flex gap-2">
                        <input type="number" id="oai-new-width" min="0" placeholder="W"
                               class="form-input w-full text-sm">
                        <input type="number" id="oai-new-height" min="0" placeholder="H"
                               class="form-input w-full text-sm">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">PPI</label>
                    <input type="number" id="oai-new-ppi" min="0" placeholder="300"
                           class="form-input w-full text-sm">
                </div>
            </div>
            <div class="flex gap-2">
                <button type="button" id="oai-add-asset-btn"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors">
                    <i class="fas fa-check"></i>
                    <?= __("Aggiungi") ?>
                </button>
                <button type="button" id="oai-cancel-add-btn"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-sm font-medium rounded-lg transition-colors">
                    <i class="fas fa-times"></i>
                    <?= __("Annulla") ?>
                </button>
            </div>
            <p id="oai-add-error" class="text-red-600 dark:text-red-400 text-xs mt-2 hidden"></p>
        </div>
    </div>
    </div><!-- /#oai-digital-assets-body -->
</div>

<script>
(function () {
    'use strict';

    var CSRF       = <?= json_encode($csrfToken, JSON_HEX_TAG) ?>;
    var ADD_URL    = <?= json_encode($addUrl, JSON_HEX_TAG) ?>;
    var DEL_BASE   = <?= json_encode($deleteBase, JSON_HEX_TAG) ?>;
    var MSG_CONFIRM = <?= json_encode(__('Eliminare questa copia digitalizzata?'), JSON_HEX_TAG) ?>;
    var MSG_ERR_URL = <?= json_encode(__('URL obbligatorio.'), JSON_HEX_TAG) ?>;
    var MSG_ERR_URL_INVALID = <?= json_encode(__('URL non valido.'), JSON_HEX_TAG) ?>;
    var MSG_ERR_MD5 = <?= json_encode(__('MD5 non valido (32 caratteri esadecimali).'), JSON_HEX_TAG) ?>;
    var MSG_ERR_INT = <?= json_encode(__('Il valore deve essere un intero non negativo.'), JSON_HEX_TAG) ?>;
    var MSG_ERR_NET = <?= json_encode(__('Errore di rete.'), JSON_HEX_TAG) ?>;
    var MSG_ERR     = <?= json_encode(__('Errore'), JSON_HEX_TAG) ?>;
    var MSG_DEL     = <?= json_encode(__('Elimina'), JSON_HEX_TAG) ?>;
    var MSG_DELETE_ASSET = <?= json_encode(__('Elimina copia digitalizzata'), JSON_HEX_TAG) ?>;
    var MSG_DELETED = <?= json_encode(__('Copia digitalizzata eliminata'), JSON_HEX_TAG) ?>;
    var MSG_UNSAFE_URL = <?= json_encode(__('URL non sicuro'), JSON_HEX_TAG) ?>;
    var MSG_LOADING = <?= json_encode(__('Salvataggio...'), JSON_HEX_TAG) ?>;
    var ADD_BTN_HTML = '<i class="fas fa-check"></i> ' + <?= json_encode(__('Aggiungi'), JSON_HEX_TAG) ?>;

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function hasSwal() {
        return typeof window !== 'undefined' && typeof window.Swal !== 'undefined' && typeof window.Swal.fire === 'function';
    }

    // Accordion (#274): toggle the section body; open on demand or when data lands.
    var acToggle = document.getElementById('oai-digital-assets-toggle');
    var acBody = document.getElementById('oai-digital-assets-body');
    var acChevron = document.getElementById('oai-digital-assets-chevron');
    function openOai() {
        if (!acBody || !acBody.classList.contains('hidden')) { return; }
        acBody.classList.remove('hidden');
        acBody.classList.add('mt-4');
        if (acToggle) { acToggle.setAttribute('aria-expanded', 'true'); }
        if (acChevron) { acChevron.classList.add('rotate-180'); }
    }
    if (acToggle && acBody) {
        acToggle.addEventListener('click', function () {
            if (acBody.classList.contains('hidden')) {
                openOai();
            } else {
                acBody.classList.add('hidden');
                acBody.classList.remove('mt-4');
                acToggle.setAttribute('aria-expanded', 'false');
                if (acChevron) { acChevron.classList.remove('rotate-180'); }
            }
        });
    }

    document.getElementById('oai-toggle-add-form').addEventListener('click', function () {
        document.getElementById('oai-add-form').classList.toggle('hidden');
    });
    document.getElementById('oai-cancel-add-btn').addEventListener('click', function () {
        document.getElementById('oai-add-form').classList.add('hidden');
        clearForm();
    });

    function val(id) { return (document.getElementById(id) || {value:''}).value || ''; }
    function int0(id) { return parseInt(val(id), 10) || 0; }

    var FIELD_IDS = ['oai-new-url','oai-new-md5','oai-new-filesize','oai-new-width','oai-new-height','oai-new-ppi'];

    function clearForm() {
        FIELD_IDS.forEach(function (id) { var el = document.getElementById(id); if (el) el.value = ''; });
        document.getElementById('oai-new-filetype').value = 'PDF';
        var err = document.getElementById('oai-add-error');
        err.textContent = '';
        err.classList.add('hidden');
        clearFieldErrors();
    }

    function clearFieldErrors() {
        FIELD_IDS.forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.removeAttribute('aria-invalid');
            var errId = id + '-err';
            var errEl = document.getElementById(errId);
            if (errEl && errEl.parentNode) errEl.parentNode.removeChild(errEl);
        });
    }

    function setFieldError(id, msg) {
        var el = document.getElementById(id);
        if (!el) return;
        el.setAttribute('aria-invalid', 'true');
        var errId = id + '-err';
        var errEl = document.getElementById(errId);
        if (!errEl) {
            errEl = document.createElement('span');
            errEl.id = errId;
            errEl.className = 'block text-xs text-red-600 dark:text-red-400 mt-1';
            errEl.setAttribute('role', 'alert');
            // Insert directly after the input (or its wrapper if input is inside .flex)
            var anchor = el;
            // For width/height inputs nested in a flex wrapper, attach after the wrapper
            if (el.parentNode && el.parentNode.classList && el.parentNode.classList.contains('flex')) {
                anchor = el.parentNode;
            }
            if (anchor.parentNode) {
                anchor.parentNode.insertBefore(errEl, anchor.nextSibling);
            }
        }
        errEl.textContent = msg;
    }

    function setError(msg) {
        var el = document.getElementById('oai-add-error');
        el.textContent = msg;
        el.classList.remove('hidden');
    }

    document.getElementById('oai-add-asset-btn').addEventListener('click', function () {
        var btn = this;
        // Clear any prior inline errors on re-submit
        clearFieldErrors();
        var err = document.getElementById('oai-add-error');
        err.textContent = '';
        err.classList.add('hidden');

        var url = val('oai-new-url').trim();
        var md5 = val('oai-new-md5').trim();
        var hasError = false;

        // (1) URL: required + must parse via new URL()
        if (!url) {
            setFieldError('oai-new-url', MSG_ERR_URL);
            hasError = true;
        } else {
            var urlOk = false;
            try { new URL(url); urlOk = true; } catch (e) { urlOk = false; }
            if (!urlOk) {
                setFieldError('oai-new-url', MSG_ERR_URL_INVALID);
                hasError = true;
            }
        }

        // (2) MD5: only validate if non-empty
        if (md5 !== '' && !/^[0-9a-fA-F]{32}$/.test(md5)) {
            setFieldError('oai-new-md5', MSG_ERR_MD5);
            hasError = true;
        }

        // (3) width / height / filesize / ppi: non-negative integers
        var intFields = ['oai-new-filesize', 'oai-new-width', 'oai-new-height', 'oai-new-ppi'];
        intFields.forEach(function (fid) {
            var raw = val(fid).trim();
            if (raw === '') return;
            if (!/^\d+$/.test(raw)) {
                setFieldError(fid, MSG_ERR_INT);
                hasError = true;
            }
        });

        if (hasError) { setError(MSG_ERR); return; }
        if (btn.disabled) return;
        btn.disabled = true;
        btn.classList.add('opacity-60', 'cursor-not-allowed');
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + MSG_LOADING;
        fetch(ADD_URL, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': CSRF},
            body: JSON.stringify({
                csrf_token: CSRF, url: url,
                filetype: val('oai-new-filetype'), md5_hash: val('oai-new-md5').trim(),
                filesize: int0('oai-new-filesize'), image_width: int0('oai-new-width'),
                image_height: int0('oai-new-height'), ppi: int0('oai-new-ppi')
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) { setError(d.error || MSG_ERR); return; }
            appendRow(d.asset);
            clearForm();
            openOai();
            document.getElementById('oai-add-form').classList.add('hidden');
        })
        .catch(function () { setError(MSG_ERR_NET); })
        .finally(function () {
            btn.disabled = false;
            btn.classList.remove('opacity-60', 'cursor-not-allowed');
            btn.innerHTML = ADD_BTN_HTML;
        });
    });

    function performDelete(aid) {
        fetch(DEL_BASE + '/' + aid + '/delete', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': CSRF},
            body: JSON.stringify({csrf_token: CSRF})
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) {
                if (hasSwal()) {
                    window.Swal.fire({icon: 'error', title: d.error || MSG_ERR});
                } else {
                    alert(d.error || MSG_ERR);
                }
                return;
            }
            var row = document.querySelector('#oai-assets-tbody tr[data-asset-id="' + aid + '"]');
            if (row) row.remove();
            syncEmptyState();
            if (hasSwal()) {
                window.Swal.fire({
                    toast: true, position: 'top-end', icon: 'success',
                    title: MSG_DELETED, timer: 2000, showConfirmButton: false
                });
            }
        })
        .catch(function () {
            if (hasSwal()) {
                window.Swal.fire({icon: 'error', title: MSG_ERR_NET});
            } else {
                alert(MSG_ERR_NET);
            }
        });
    }

    document.getElementById('oai-assets-tbody').addEventListener('click', function (e) {
        var btn = e.target.closest('.oai-delete-asset');
        if (!btn) return;
        var aid = parseInt(btn.dataset.assetId, 10);
        if (!aid) return;

        // Pull context for the confirmation dialog
        var row = btn.closest('tr');
        var rowUrl = '';
        var rowFiletype = '';
        if (row) {
            var linkEl = row.querySelector('a, span');
            if (linkEl) rowUrl = linkEl.getAttribute('href') || linkEl.textContent || '';
            var typeCell = row.querySelectorAll('td')[1];
            if (typeCell) rowFiletype = typeCell.textContent || '';
        }

        if (hasSwal()) {
            window.Swal.fire({
                title: MSG_CONFIRM,
                html: '<code>' + escapeHtml(rowUrl) + '</code><br>' + escapeHtml(rowFiletype),
                icon: 'warning',
                showCancelButton: true
            }).then(function (result) {
                if (result && result.isConfirmed) performDelete(aid);
            });
        } else {
            if (!confirm(MSG_CONFIRM)) return;
            performDelete(aid);
        }
    });

    function syncEmptyState() {
        var hasRows = document.getElementById('oai-assets-tbody').querySelectorAll('tr').length > 0;
        document.getElementById('oai-assets-table-wrap').classList.toggle('hidden', !hasRows);
        document.getElementById('oai-empty-msg').classList.toggle('hidden', hasRows);
    }

    function bytesStr(n) {
        if (n <= 0) return '—';
        if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
        if (n >= 1024)    return (n / 1024).toFixed(1) + ' KB';
        return n + ' B';
    }

    function appendRow(a) {
        var tbody  = document.getElementById('oai-assets-tbody');
        var tr     = document.createElement('tr');
        tr.className = 'border-b border-teal-100 dark:border-teal-800/50';
        tr.dataset.assetId = a.id;

        // URL cell
        var tdUrl = document.createElement('td');
        tdUrl.className = 'py-2 pr-4 max-w-xs';
        var link = document.createElement('a');
        var safeSchemes = ['http:', 'https:'];
        var urlAccepted = false;
        try {
            var parsedUrl = new URL(a.url);
            if (safeSchemes.indexOf(parsedUrl.protocol) !== -1) {
                link.href = a.url;
                urlAccepted = true;
            }
        } catch (e) {
            // invalid URL, leave href unset
        }
        link.className = 'text-teal-700 dark:text-teal-300 hover:underline truncate block max-w-xs';
        if (urlAccepted) {
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
        } else {
            // Rejected URL: visually flag, do not open in new tab, expose warning to AT
            link.className += ' text-red-500';
            link.setAttribute('aria-label', MSG_UNSAFE_URL);
            link.setAttribute('title', MSG_UNSAFE_URL);
        }
        link.textContent = a.url.split('/').pop() || a.url;
        tdUrl.appendChild(link);
        tr.appendChild(tdUrl);

        // Filetype cell
        var tdType = document.createElement('td');
        tdType.className = 'py-2 pr-4 font-mono text-xs';
        tdType.textContent = a.filetype;
        tr.appendChild(tdType);

        // Filesize cell
        var tdSize = document.createElement('td');
        tdSize.className = 'py-2 pr-4 text-gray-600 dark:text-gray-400';
        tdSize.textContent = bytesStr(a.filesize);
        tr.appendChild(tdSize);

        // Dimensions cell
        var tdDim = document.createElement('td');
        tdDim.className = 'py-2 pr-4 text-gray-600 dark:text-gray-400 text-xs';
        tdDim.textContent = (a.image_width > 0 && a.image_height > 0)
            ? a.image_width + '×' + a.image_height : '—';
        tr.appendChild(tdDim);

        // PPI cell
        var tdPpi = document.createElement('td');
        tdPpi.className = 'py-2 pr-4 text-gray-600 dark:text-gray-400';
        tdPpi.textContent = a.ppi > 0 ? String(a.ppi) : '—';
        tr.appendChild(tdPpi);

        // Delete cell
        var tdDel = document.createElement('td');
        tdDel.className = 'py-2';
        var delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'oai-delete-asset text-red-500 hover:text-red-700 transition-colors';
        delBtn.dataset.assetId = String(a.id);
        delBtn.title = MSG_DELETE_ASSET;
        delBtn.setAttribute('aria-label', MSG_DELETE_ASSET);
        var icon = document.createElement('i');
        icon.className = 'fas fa-trash-alt';
        delBtn.appendChild(icon);
        tdDel.appendChild(delBtn);
        tr.appendChild(tdDel);

        tbody.appendChild(tr);
        syncEmptyState();
    }
})();
</script>

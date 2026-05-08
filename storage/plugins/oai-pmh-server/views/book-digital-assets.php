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

function oaiFormatBytes(int $bytes): string
{
    if ($bytes <= 0) return '';
    if ($bytes >= 1_048_576) return number_format($bytes / 1_048_576, 1) . ' MB';
    if ($bytes >= 1024)      return number_format($bytes / 1024, 1)      . ' KB';
    return $bytes . ' B';
}
?>

<div class="mt-6 bg-gradient-to-br from-teal-50 to-cyan-50 border-2 border-teal-200 rounded-2xl p-6 dark:from-teal-900/20 dark:to-cyan-900/20 dark:border-teal-800"
     id="oai-digital-assets-section">
    <h3 class="text-lg font-bold text-teal-900 dark:text-teal-100 mb-1 flex items-center gap-2">
        <i class="fas fa-file-image text-teal-600"></i>
        <?= __("Copie Digitalizzate (MAG/ICCU)") ?>
    </h3>
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
                        <a href="<?= HtmlHelper::e((string) $asset['url']) ?>" target="_blank"
                           rel="noopener noreferrer"
                           class="text-teal-700 dark:text-teal-300 hover:underline truncate block max-w-xs">
                            <?= HtmlHelper::e(basename((string) $asset['url'])) ?>
                        </a>
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
                                title="<?= __("Elimina") ?>">
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
</div>

<script>
(function () {
    'use strict';

    var CSRF       = <?= json_encode($csrfToken, JSON_HEX_TAG) ?>;
    var ADD_URL    = <?= json_encode($addUrl, JSON_HEX_TAG) ?>;
    var DEL_BASE   = <?= json_encode($deleteBase, JSON_HEX_TAG) ?>;
    var MSG_CONFIRM = <?= json_encode(__('Eliminare questa copia digitalizzata?'), JSON_HEX_TAG) ?>;
    var MSG_ERR_URL = <?= json_encode(__('URL obbligatorio.'), JSON_HEX_TAG) ?>;
    var MSG_ERR_NET = <?= json_encode(__('Errore di rete.'), JSON_HEX_TAG) ?>;
    var MSG_ERR     = <?= json_encode(__('Errore'), JSON_HEX_TAG) ?>;
    var MSG_DEL     = <?= json_encode(__('Elimina'), JSON_HEX_TAG) ?>;

    document.getElementById('oai-toggle-add-form').addEventListener('click', function () {
        document.getElementById('oai-add-form').classList.toggle('hidden');
    });
    document.getElementById('oai-cancel-add-btn').addEventListener('click', function () {
        document.getElementById('oai-add-form').classList.add('hidden');
        clearForm();
    });

    function val(id) { return (document.getElementById(id) || {value:''}).value || ''; }
    function int0(id) { return parseInt(val(id), 10) || 0; }

    function clearForm() {
        ['oai-new-url','oai-new-md5','oai-new-filesize','oai-new-width','oai-new-height','oai-new-ppi']
            .forEach(function (id) { var el = document.getElementById(id); if (el) el.value = ''; });
        document.getElementById('oai-new-filetype').value = 'PDF';
        var err = document.getElementById('oai-add-error');
        err.textContent = '';
        err.classList.add('hidden');
    }

    function setError(msg) {
        var el = document.getElementById('oai-add-error');
        el.textContent = msg;
        el.classList.remove('hidden');
    }

    document.getElementById('oai-add-asset-btn').addEventListener('click', function () {
        var url = val('oai-new-url').trim();
        if (!url) { setError(MSG_ERR_URL); return; }
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
            document.getElementById('oai-add-form').classList.add('hidden');
        })
        .catch(function () { setError(MSG_ERR_NET); });
    });

    document.getElementById('oai-assets-tbody').addEventListener('click', function (e) {
        var btn = e.target.closest('.oai-delete-asset');
        if (!btn) return;
        var aid = parseInt(btn.dataset.assetId, 10);
        if (!aid || !confirm(MSG_CONFIRM)) return;
        fetch(DEL_BASE + '/' + aid + '/delete', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': CSRF},
            body: JSON.stringify({csrf_token: CSRF})
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) { alert(d.error || MSG_ERR); return; }
            var row = document.querySelector('#oai-assets-tbody tr[data-asset-id="' + aid + '"]');
            if (row) row.remove();
            syncEmptyState();
        })
        .catch(function () { alert(MSG_ERR_NET); });
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
        link.href = a.url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.className = 'text-teal-700 dark:text-teal-300 hover:underline truncate block max-w-xs';
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
        delBtn.title = MSG_DEL;
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

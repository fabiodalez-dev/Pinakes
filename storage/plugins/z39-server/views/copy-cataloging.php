<?php
/**
 * Copy cataloguing page. Expects $copyServers (array<string, array>), the
 * servers keyed as CopyCataloging::servers() returns them.
 *
 * @var array<string, array<string, mixed>> $copyServers
 */
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div class="min-h-screen bg-gray-50 py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6">
        <?php
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title text-3xl font-bold text-gray-900 flex items-center"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?>
                    <?= __("Copy cataloging") ?>
                <?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?>
                    <i class="fas fa-search-plus text-gray-600 mr-3"></i>
                    <?= __("Copy cataloging") ?>
        <?php
$workspaceHeader['context'] = (string) ob_get_clean();
ob_start();
?><a href="<?= htmlspecialchars(url('/admin/books'), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300 rounded-lg transition-colors inline-flex items-center">
                        <i class="fas fa-arrow-left mr-2"></i>
                        <?= __("Torna ai Libri") ?>
                    </a><?php
$workspaceHeader['back'] = (string) ob_get_clean();
ob_start();
?>
                <p class="text-sm text-gray-600"><?= __("Cerca i record in altri cataloghi (SRU, SBN, altre installazioni Pinakes) e importa quelli che ti servono") ?></p>
                <div>
                </div>
            <?php
$workspaceHeader['actions'] = (string) ob_get_clean();
ob_start();
?><nav class="pk-workspace-breadcrumb" aria-label="<?= htmlspecialchars(__('Percorso di navigazione'), ENT_QUOTES, 'UTF-8') ?>">
            <ol>
                <li>
                    <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Home') ?></a>
                </li>
                <li>
                    <a href="<?= htmlspecialchars(url('/admin/books'), ENT_QUOTES, 'UTF-8') ?>">
                        <?= __("Libri") ?>
                    </a>
                </li>
                <li aria-current="page">
                    <?= __("Copy cataloging") ?>
                </li>
            </ol>
        </nav><?php
$workspaceHeader['breadcrumb'] = (string) ob_get_clean();
require dirname(__DIR__, 4) . '/app/Views/partials/workspace-header.php';
unset($workspaceHeader);
?>

        <div class="space-y-6">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4 flex items-center">
                    <i class="fas fa-search text-gray-600 mr-2"></i>
                    <?= __("Cerca nei cataloghi") ?>
                </h2>
                <form id="copySearchForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="p-4 bg-gray-50 border border-gray-200 rounded-lg">
                        <div class="text-sm">
                            <div class="font-medium text-gray-900"><?= __("Cataloghi") ?></div>
                        </div>
                        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <?php foreach ($copyServers as $serverKey => $server): ?>
                            <?php $checked = ($server['kind'] ?? '') === 'configured' && !empty($server['enabled']); ?>
                            <label class="flex items-start cursor-pointer">
                                <div class="flex items-center h-5">
                                    <input type="checkbox" name="servers[]" value="<?= htmlspecialchars((string) $serverKey, ENT_QUOTES, 'UTF-8') ?>" data-server-name="<?= htmlspecialchars((string) $server['name'], ENT_QUOTES, 'UTF-8') ?>" <?= $checked ? 'checked' : '' ?> class="w-4 h-4 text-gray-800 bg-gray-100 border-gray-300 rounded focus:ring-gray-500 focus:ring-2">
                                </div>
                                <div class="ml-3 text-sm">
                                    <div class="font-medium text-gray-900"><?= htmlspecialchars((string) $server['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <p class="text-gray-500 text-xs mt-1"><?= htmlspecialchars((string) ($server['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mt-6 grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label for="copy_index" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Cerca per") ?></label>
                            <select id="copy_index" name="index" class="block w-full rounded-xl border-gray-300 focus:border-gray-500 focus:ring-gray-500 text-sm py-3 px-4">
                                <option value="title"><?= __("Titolo") ?></option>
                                <option value="author"><?= __("Autore") ?></option>
                                <option value="isbn"><?= __("ISBN") ?></option>
                                <option value="issn"><?= __("ISSN") ?></option>
                                <option value="any"><?= __("Tutti i campi") ?></option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label for="copy_term" class="block text-sm font-medium text-gray-700 mb-2"><?= __("Termine di ricerca") ?></label>
                            <input type="text" id="copy_term" name="term" required aria-required="true" maxlength="200" class="mt-1 block w-full rounded-md border-gray-300 focus:border-gray-900 focus:ring-gray-900" placeholder="<?= htmlspecialchars(__("es. Il nome della rosa"), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>
                    <div class="pk-form-actions mt-6 flex flex-col sm:flex-row items-start sm:items-center justify-end gap-2">
                        <button type="submit" id="copySearchBtn" class="px-6 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors inline-flex items-center disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap">
                            <i class="fas fa-search mr-2"></i>
                            <?= __("Cerca") ?>
                        </button>
                    </div>
                </form>
            </div>

            <div id="copy-results" class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 hidden">
                <h2 class="text-xl font-semibold text-gray-900 mb-4 flex items-center">
                    <i class="fas fa-list text-gray-600 mr-2"></i>
                    <?= __("Risultati") ?>
                </h2>
                <ul id="copy-server-report" class="space-y-1 text-sm text-gray-600 mb-4"></ul>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <input type="checkbox" id="copy-select-all" aria-label="<?= htmlspecialchars(__("Seleziona tutti"), ENT_QUOTES, 'UTF-8') ?>" class="w-4 h-4 text-gray-800 bg-gray-100 border-gray-300 rounded focus:ring-gray-500 focus:ring-2">
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Titolo") ?></th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Autori") ?></th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Editore") ?></th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Anno") ?></th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("ISBN") ?></th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Catalogo") ?></th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Azioni") ?></th>
                            </tr>
                        </thead>
                        <tbody id="copy-result-rows" class="bg-white divide-y divide-gray-200"></tbody>
                    </table>
                </div>

                <div class="mt-6 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                    <div class="text-sm">
                        <div class="font-medium text-gray-900"><?= __("Libri già presenti (stesso ISBN-13, ISBN-10 o EAN)") ?></div>
                    </div>
                    <div class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <?php foreach (['skip' => __("Salta"), 'fill' => __("Completa i campi vuoti"), 'overwrite' => __("Sovrascrivi")] as $modeKey => $modeLabel): ?>
                        <label class="flex items-start cursor-pointer">
                            <div class="flex items-center h-5">
                                <input type="radio" name="copy_duplicates" id="copy_duplicates_<?= htmlspecialchars($modeKey, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($modeKey, ENT_QUOTES, 'UTF-8') ?>" <?= $modeKey === 'skip' ? 'checked' : '' ?> class="w-4 h-4 text-gray-800 bg-gray-100 border-gray-300 focus:ring-gray-500 focus:ring-2">
                            </div>
                            <div class="ml-3 text-sm">
                                <div class="font-medium text-gray-900"><?= htmlspecialchars($modeLabel, ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <label class="flex items-start cursor-pointer mt-4">
                        <div class="flex items-center h-5">
                            <input type="checkbox" id="copy_covers" value="1" checked class="w-4 h-4 text-gray-800 bg-gray-100 border-gray-300 rounded focus:ring-gray-500 focus:ring-2">
                        </div>
                        <div class="ml-3 text-sm">
                            <div class="font-medium text-gray-900"><?= __("Scarica le copertine (campo 856)") ?></div>
                        </div>
                    </label>
                </div>
                <div class="pk-form-actions mt-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2">
                    <span class="text-sm text-gray-600"><span id="copy-selected-count">0</span> <?= __("selezionati") ?></span>
                    <button type="button" id="copyImportBtn" disabled class="px-6 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors inline-flex items-center disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap">
                        <i class="fas fa-cloud-download-alt mr-2"></i>
                        <?= __("Importa selezionati") ?>
                    </button>
                </div>
            </div>

            <div id="copy-import-result" class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 hidden" role="status">
                <h2 class="text-xl font-semibold text-gray-900 mb-4 flex items-center">
                    <i class="fas fa-check-circle text-green-600 mr-2"></i>
                    <?= __("Import Completato") ?>
                </h2>
                <p id="copy-import-summary" class="text-sm text-gray-700"></p>
                <ul id="copy-import-items" class="ml-8 mt-3 space-y-1 text-sm text-gray-700"></ul>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const BASE_PATH = window.BASE_PATH || '';
    const T = <?= json_encode([
        'records' => __("%d record"),
        'of' => __("su %d"),
        'error' => __("Errore"),
        'none' => __("Nessun risultato"),
        'existing' => __("Già in catalogo (#%d)"),
        'new' => __("Nuovo"),
        'details' => __("Dettagli"),
        'searching' => __("Ricerca in corso..."),
        'importing' => __("Importazione in corso..."),
        'summary' => __("%d importati, %d aggiornati, %d saltati, %d errori"),
        'covers' => __("%d copertine scaricate"),
        'open' => __("Apri il libro"),
        'created' => __("Importato"),
        'updated' => __("Aggiornato"),
        'skipped' => __("Saltato (già in catalogo)"),
        'failed' => __("Non importato"),
        'connection' => __("Errore di connessione"),
        'pickServer' => __("Indica cosa cercare e almeno un catalogo"),
    ], $jsonFlags) ?>;
    const fmt = (s, ...args) => { let i = 0; return s.replace(/%d/g, () => String(args[i++] ?? '')); };
    const esc = (v) => String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    const csrf = () => document.querySelector('#copySearchForm input[name="csrf_token"]').value;
    const importBtn = document.getElementById('copyImportBtn');
    const rowsEl = document.getElementById('copy-result-rows');

    const selectedIds = () => Array.from(rowsEl.querySelectorAll('input[data-result-id]:checked')).map((c) => c.dataset.resultId);
    const refreshSelection = () => {
        const count = selectedIds().length;
        document.getElementById('copy-selected-count').textContent = String(count);
        importBtn.disabled = count === 0;
    };

    document.getElementById('copySearchForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const servers = Array.from(this.querySelectorAll('input[name="servers[]"]:checked')).map((c) => c.value);
        const term = document.getElementById('copy_term').value.trim();
        if (!term || servers.length === 0) { window.SwalApp.error(T.error, T.pickServer); return; }
        const btn = document.getElementById('copySearchBtn');
        btn.disabled = true;
        document.getElementById('copy-import-result').classList.add('hidden');
        rowsEl.innerHTML = `<tr><td colspan="8" class="px-6 py-4 text-sm text-gray-500">${esc(T.searching)}</td></tr>`;
        document.getElementById('copy-results').classList.remove('hidden');
        fetch(BASE_PATH + '/admin/books/copy-cataloging/search', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify({ servers, index: document.getElementById('copy_index').value, term, max: 10 })
        })
            .then((r) => r.json())
            .then((data) => {
                btn.disabled = false;
                if (!data.success) { rowsEl.innerHTML = ''; window.SwalApp.error(T.error, data.error || T.error); return; }
                renderResults(data);
            })
            .catch(() => { btn.disabled = false; rowsEl.innerHTML = ''; window.SwalApp.error(T.error, T.connection); });
    });

    function renderResults(data) {
        document.getElementById('copy-server-report').innerHTML = (data.servers || []).map((s) => {
            const text = s.error ? `${T.error}: ${s.error}` : `${fmt(T.records, s.count)} ${fmt(T.of, s.total)}`;
            return `<li data-server-report="${esc(s.key)}"><strong>${esc(s.name)}</strong>: ${esc(text)}</li>`;
        }).join('');
        if (!data.results || data.results.length === 0) {
            rowsEl.innerHTML = `<tr><td colspan="8" class="px-6 py-12 text-center text-sm text-gray-500">${esc(T.none)}</td></tr>`;
            refreshSelection();
            return;
        }
        rowsEl.innerHTML = data.results.map((r) => {
            const status = r.existing_id
                ? `<span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">${esc(fmt(T.existing, r.existing_id))}</span>`
                : `<span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">${esc(T.new)}</span>`;
            const detail = (r.detail || []).map((l) => `<div><span class="font-mono font-semibold">${esc(l.tag)}</span> <span class="font-mono text-gray-500">${esc(l.ind)}</span> ${esc(l.value)}</div>`).join('');
            return `<tr data-result-row="${esc(r.id)}">`
                + `<td class="px-6 py-4 text-sm"><input type="checkbox" data-result-id="${esc(r.id)}" aria-label="${esc(r.titolo)}" class="w-4 h-4 text-gray-800 bg-gray-100 border-gray-300 rounded focus:ring-gray-500 focus:ring-2"></td>`
                + `<td class="px-6 py-4 text-sm text-gray-900"><div class="font-medium">${esc(r.titolo)}</div><div class="text-xs text-gray-500">${esc(r.sottotitolo)}</div><div class="mt-1">${status}</div></td>`
                + `<td class="px-6 py-4 text-sm text-gray-900">${esc(r.autori)}</td><td class="px-6 py-4 text-sm text-gray-900">${esc(r.editore)}</td>`
                + `<td class="px-6 py-4 text-sm text-gray-900">${esc(r.anno)}</td><td class="px-6 py-4 text-sm text-gray-900 font-mono">${esc(r.isbn)}</td>`
                + `<td class="px-6 py-4 text-sm text-gray-600">${esc(r.server)}</td>`
                + `<td class="px-6 py-4 text-sm"><button type="button" data-detail-toggle="${esc(r.id)}" class="px-4 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300 rounded-lg transition-colors inline-flex items-center"><i class="fas fa-eye mr-2"></i>${esc(T.details)}</button></td></tr>`
                + `<tr data-detail-row="${esc(r.id)}" class="hidden"><td colspan="8" class="px-6 py-4 text-xs text-gray-700 bg-gray-50">${detail}</td></tr>`;
        }).join('');
        refreshSelection();
    }

    rowsEl.addEventListener('change', (e) => { if (e.target.matches('input[data-result-id]')) refreshSelection(); });
    rowsEl.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-detail-toggle]');
        if (!btn) return;
        const row = rowsEl.querySelector(`tr[data-detail-row="${CSS.escape(btn.dataset.detailToggle)}"]`);
        if (row) row.classList.toggle('hidden');
    });
    document.getElementById('copy-select-all').addEventListener('change', function() {
        rowsEl.querySelectorAll('input[data-result-id]').forEach((c) => { c.checked = this.checked; });
        refreshSelection();
    });

    importBtn.addEventListener('click', () => {
        const ids = selectedIds();
        if (ids.length === 0) return;
        importBtn.disabled = true;
        importBtn.dataset.label = importBtn.innerHTML;
        importBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>' + esc(T.importing);
        fetch(BASE_PATH + '/admin/books/copy-cataloging/import', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify({
                ids,
                duplicates: (document.querySelector('input[name="copy_duplicates"]:checked') || {}).value || 'skip',
                covers: document.getElementById('copy_covers').checked
            })
        })
            .then((r) => r.json())
            .then((data) => {
                importBtn.innerHTML = importBtn.dataset.label;
                refreshSelection();
                if (!data.success) { window.SwalApp.error(T.error, data.error || T.error); return; }
                let summary = fmt(T.summary, data.imported, data.updated, data.skipped, data.failed);
                if (data.covers > 0) summary += ', ' + fmt(T.covers, data.covers);
                document.getElementById('copy-import-summary').textContent = summary;
                document.getElementById('copy-import-items').innerHTML = (data.items || []).map((i) => {
                    const label = T[i.action] || i.action;
                    const link = i.book_id ? ` — <a class="underline" href="${esc(BASE_PATH + '/admin/books/' + i.book_id)}">${esc(T.open)}</a>` : '';
                    const msg = i.message ? `: ${esc(i.message)}` : '';
                    return `<li class="list-disc" data-import-item="${esc(i.action)}">${esc(label)} — ${esc(i.title || '')}${msg}${link}</li>`;
                }).join('');
                document.getElementById('copy-import-result').classList.remove('hidden');
            })
            .catch(() => { importBtn.innerHTML = importBtn.dataset.label; refreshSelection(); window.SwalApp.error(T.error, T.connection); });
    });
});
</script>

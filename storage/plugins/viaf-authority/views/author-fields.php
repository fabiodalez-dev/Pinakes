<?php
/**
 * VIAF / ISNI authority panel injected into the author edit form via the
 * `author.form.fields` hook (finding #32).
 *
 * Reuses the existing plugin endpoints — no parallel endpoints are added:
 *   GET  /api/viaf/suggest?q=NAME        — VIAF AutoSuggest candidates
 *   POST /api/viaf/author/{id}/set       — persist viaf_id + isni_id on the author
 *
 * In scope: $autore (?array), $authorId (int), $authorName (string),
 *           $viafId (string), $viafUri (string), $isniId (string), $csrf (string).
 *
 * @var int    $authorId
 * @var string $authorName
 * @var string $viafId
 * @var string $viafUri
 * @var string $isniId
 * @var string $csrf
 */

use App\Support\HtmlHelper;
?>

<div id="viaf-authority-panel"
     class="card"
     data-csrf="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"
     data-author-id="<?php echo (int) $authorId; ?>">
    <div class="card-header">
        <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-fingerprint text-gray-900"></i>
            <?= __("Authority control VIAF / ISNI") ?>
        </h2>
    </div>
    <div class="card-body form-section">
        <p class="text-sm text-gray-600 mb-4">
            <i class="fas fa-info-circle mr-1"></i>
            <?= __("Collega l'autore al Virtual International Authority File (VIAF) e a ISNI. Cerca per nome per trovare un identificatore, oppure inseriscilo manualmente, e salva.") ?>
        </p>

        <div class="form-grid-2">
            <div>
                <label for="viaf_id_field" class="form-label"><?= __("VIAF ID") ?></label>
                <input id="viaf_id_field" name="viaf_id" class="form-input"
                       value="<?php echo HtmlHelper::e($viafId); ?>"
                       placeholder="<?= __('es. 102333412') ?>" />
                <p class="text-xs text-gray-500 mt-1">
                    <?php if ($viafId !== '' && $viafUri !== ''): ?>
                        <a href="<?= htmlspecialchars($viafUri, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-700" id="viaf-current-link">
                            <i class="fas fa-external-link-alt mr-1"></i><?= __("Apri in VIAF") ?>
                        </a>
                    <?php else: ?>
                        <span id="viaf-current-link" class="hidden">
                            <a href="#" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-700"><i class="fas fa-external-link-alt mr-1"></i><?= __("Apri in VIAF") ?></a>
                        </span>
                    <?php endif; ?>
                </p>
            </div>
            <div>
                <label for="isni_id_field" class="form-label"><?= __("ISNI") ?></label>
                <input id="isni_id_field" name="isni_id" class="form-input"
                       value="<?php echo HtmlHelper::e($isniId); ?>"
                       placeholder="<?= __('es. 0000 0001 2143 6543') ?>" />
                <p class="text-xs text-gray-500 mt-1"><?= __("16 cifre (l'ultima può essere una X).") ?></p>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <button type="button" id="viaf-lookup-btn" class="btn btn-secondary flex items-center gap-2">
                <i class="fas fa-search"></i>
                <?= __("Cerca su VIAF") ?>
            </button>
            <button type="button" id="viaf-save-btn" class="btn btn-primary flex items-center gap-2">
                <i class="fas fa-save"></i>
                <?= __("Salva VIAF / ISNI") ?>
            </button>
            <span id="viaf-lookup-status" class="text-sm text-gray-600"></span>
        </div>

        <div id="viaf-lookup-results" class="mt-3 hidden border border-gray-200 rounded-lg divide-y"></div>
    </div>
</div>

<script>
(function () {
    const panel = document.getElementById('viaf-authority-panel');
    if (!panel || panel.dataset.viafInit === '1') { return; }
    panel.dataset.viafInit = '1';

    const csrf = panel.dataset.csrf || '';
    const authorId = panel.dataset.authorId || '0';
    const base = (window.BASE_PATH || '');

    const T = {
        searching: <?= json_encode(__('Ricerca su VIAF…'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        none: <?= json_encode(__('Nessun risultato trovato su VIAF.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        saved: <?= json_encode(__('Dati VIAF/ISNI salvati.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        error: <?= json_encode(__('Errore durante la richiesta.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        apply: <?= json_encode(__('Applica'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        emptyName: <?= json_encode(__('Inserisci prima il nome dell\'autore.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        isni: <?= json_encode(__('ISNI'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    };

    function clearEl(el) { while (el.firstChild) { el.removeChild(el.firstChild); } }
    const statusEl = document.getElementById('viaf-lookup-status');
    const resultsEl = document.getElementById('viaf-lookup-results');
    const viafField = document.getElementById('viaf_id_field');
    const isniField = document.getElementById('isni_id_field');

    // Persist current field values via the existing set endpoint. Returns a Promise.
    function persist() {
        if (!authorId || authorId === '0') {
            statusEl.textContent = T.saved;
            return Promise.resolve();
        }
        const fd = new URLSearchParams();
        fd.set('viaf_id', viafField.value.trim());
        fd.set('isni_id', isniField.value.trim());
        fd.set('csrf_token', csrf);
        return fetch(base + '/api/viaf/author/' + encodeURIComponent(authorId) + '/set', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
            body: fd.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d && d.success) {
                statusEl.textContent = d.message || T.saved;
                updateCurrentLink(viafField.value.trim());
            } else {
                statusEl.textContent = (d && d.message) ? d.message : T.error;
            }
        })
        .catch(function () { statusEl.textContent = T.error; });
    }

    // Keep the "Open in VIAF" link in sync with the VIAF ID field.
    function updateCurrentLink(viafId) {
        const wrap = document.getElementById('viaf-current-link');
        if (!wrap) { return; }
        const anchor = wrap.tagName === 'A' ? wrap : wrap.querySelector('a');
        if (viafId) {
            if (anchor) { anchor.href = 'https://viaf.org/viaf/' + encodeURIComponent(viafId); }
            wrap.classList.remove('hidden');
        } else {
            wrap.classList.add('hidden');
        }
    }

    function applyCandidate(c) {
        viafField.value = c.viafid || '';
        if (c.isni_id) { isniField.value = c.isni_id; }
        resultsEl.classList.add('hidden');
        clearEl(resultsEl);
        persist();
    }

    function showCandidates(cands) {
        clearEl(resultsEl);
        if (!cands.length) { resultsEl.classList.add('hidden'); statusEl.textContent = T.none; return; }
        cands.forEach(function (c) {
            const row = document.createElement('div');
            row.className = 'flex items-center justify-between gap-3 px-3 py-2';
            const info = document.createElement('div');
            info.className = 'text-sm';
            const strong = document.createElement('span');
            strong.className = 'font-medium text-gray-800';
            strong.textContent = c.name || '';
            info.appendChild(strong);
            const meta = document.createElement('span');
            meta.className = 'text-gray-500 ml-2';
            meta.textContent = 'VIAF ' + (c.viafid || '') + (c.isni_id ? ' · ' + T.isni + ' ' + c.isni_id : '');
            info.appendChild(meta);
            row.appendChild(info);

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-primary btn-sm';
            btn.textContent = T.apply;
            btn.addEventListener('click', function () { applyCandidate(c); });
            row.appendChild(btn);

            resultsEl.appendChild(row);
        });
        resultsEl.classList.remove('hidden');
        statusEl.textContent = '';
    }

    document.getElementById('viaf-lookup-btn').addEventListener('click', function () {
        const btn = this;
        const nameEl = document.getElementById('nome');
        const name = nameEl ? nameEl.value.trim() : '';

        if (!name) { statusEl.textContent = T.emptyName; return; }

        btn.disabled = true;
        statusEl.textContent = T.searching;

        fetch(base + '/api/viaf/suggest?q=' + encodeURIComponent(name), {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || d.error) { statusEl.textContent = (d && d.message) ? d.message : T.error; return; }
            showCandidates(d.results || []);
        })
        .catch(function () { statusEl.textContent = T.error; })
        .finally(function () { btn.disabled = false; });
    });

    document.getElementById('viaf-save-btn').addEventListener('click', function () {
        const btn = this;
        btn.disabled = true;
        Promise.resolve(persist()).finally(function () { btn.disabled = false; });
    });
})();
</script>

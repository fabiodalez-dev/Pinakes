<?php
/**
 * REICAT/SBN authority panel injected into the author edit form via the
 * `author.form.fields` hook (issue #133).
 *
 * In scope: $autore (?array), $authorId (int), $authorName (string),
 *           $authData (ccn, sbn_authorized_form, qualifier_dates, qualifier_role),
 *           $csrf (string).
 *
 * @var int $authorId
 * @var string $authorName
 * @var array{ccn:string,sbn_authorized_form:string,qualifier_dates:string,qualifier_role:string} $authData
 * @var string $csrf
 */

use App\Support\HtmlHelper;
?>

<div id="reicat-authority-panel"
     class="card"
     data-csrf="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"
     data-author-id="<?php echo (int) $authorId; ?>">
    <div class="card-header">
        <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-landmark text-primary"></i>
            <?= __("Authority control SBN (REICAT)") ?>
        </h2>
    </div>
    <div class="card-body form-section">
        <p class="text-sm text-gray-600 mb-4">
            <i class="fas fa-info-circle mr-1"></i>
            <?= __("Cerca la forma autorizzata del nome nel catalogo SBN e applicala come intestazione di autorità REICAT 18.0, con qualificatore di omonimia (date).") ?>
        </p>

        <div class="form-grid-2">
            <div>
                <label for="sbn_authorized_form" class="form-label"><?= __("Forma autorizzata (REICAT 18.0)") ?></label>
                <input id="sbn_authorized_form" name="sbn_authorized_form" class="form-input"
                       value="<?php echo HtmlHelper::e($authData['sbn_authorized_form']); ?>"
                       placeholder="<?= __('es. Calvino, Italo') ?>" />
            </div>
            <div>
                <label for="ccn" class="form-label"><?= __("CCN (Codice di Controllo Nazionale)") ?></label>
                <input id="ccn" name="ccn" class="form-input"
                       value="<?php echo HtmlHelper::e($authData['ccn']); ?>"
                       placeholder="<?= __('opzionale') ?>" />
            </div>
        </div>

        <div class="form-grid-2">
            <div>
                <label for="qualifier_dates" class="form-label"><?= __("Qualificatore: date (REICAT 7.0)") ?></label>
                <input id="qualifier_dates" name="qualifier_dates" class="form-input"
                       value="<?php echo HtmlHelper::e($authData['qualifier_dates']); ?>"
                       placeholder="<?= __('es. 1923-1985') ?>" />
            </div>
            <div>
                <label for="qualifier_role" class="form-label"><?= __("Qualificatore: ruolo/titolo") ?></label>
                <input id="qualifier_role" name="qualifier_role" class="form-input"
                       value="<?php echo HtmlHelper::e($authData['qualifier_role']); ?>"
                       placeholder="<?= __('es. santo, papa, di Sassonia') ?>" />
            </div>
        </div>

        <div class="mt-4 flex items-center gap-2">
            <button type="button" id="reicat-lookup-btn" class="btn btn-secondary flex items-center gap-2">
                <i class="fas fa-search"></i>
                <?= __("Cerca su SBN") ?>
            </button>
            <span id="reicat-lookup-status" class="text-sm text-gray-600"></span>
        </div>

        <div id="reicat-lookup-results" class="mt-3 hidden border border-gray-200 rounded-lg divide-y"></div>
    </div>
</div>

<script>
(function () {
    const panel = document.getElementById('reicat-authority-panel');
    if (!panel || panel.dataset.reicatInit === '1') { return; }
    panel.dataset.reicatInit = '1';

    const csrf = panel.dataset.csrf || '';
    const authorId = panel.dataset.authorId || '0';
    const base = (window.BASE_PATH || '');

    const T = {
        searching: <?= json_encode(__('Ricerca su SBN…'), JSON_HEX_TAG) ?>,
        none: <?= json_encode(__('Nessuna forma trovata su SBN.'), JSON_HEX_TAG) ?>,
        applied: <?= json_encode(__('Forma autorizzata applicata.'), JSON_HEX_TAG) ?>,
        error: <?= json_encode(__('Errore durante la richiesta.'), JSON_HEX_TAG) ?>,
        occ: <?= json_encode(__('occorrenze'), JSON_HEX_TAG) ?>,
        apply: <?= json_encode(__('Applica'), JSON_HEX_TAG) ?>,
    };

    function clearEl(el) { while (el.firstChild) { el.removeChild(el.firstChild); } }
    const statusEl = document.getElementById('reicat-lookup-status');
    const resultsEl = document.getElementById('reicat-lookup-results');

    function applyCandidate(c) {
        document.getElementById('sbn_authorized_form').value = c.authorized_form || '';
        if (c.qualifier_dates) { document.getElementById('qualifier_dates').value = c.qualifier_dates; }

        // Persist immediately if editing an existing author.
        if (authorId && authorId !== '0') {
            const fd = new URLSearchParams();
            fd.set('authorized_form', c.authorized_form || '');
            fd.set('qualifier_dates', c.qualifier_dates || '');
            fd.set('csrf_token', csrf);
            fetch(base + '/admin/autori/' + encodeURIComponent(authorId) + '/apply-authority', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
                body: fd.toString()
            })
            .then(function (r) { return r.json(); })
            .then(function (d) { statusEl.textContent = (d && d.success) ? T.applied : ((d && d.error) ? d.error : T.error); })
            .catch(function () { statusEl.textContent = T.error; });
        } else {
            statusEl.textContent = T.applied;
        }
        resultsEl.classList.add('hidden');
        clearEl(resultsEl);
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
            strong.textContent = c.authorized_form + (c.qualifier_dates ? ' <' + c.qualifier_dates + '>' : '');
            info.appendChild(strong);
            const meta = document.createElement('span');
            meta.className = 'text-gray-500 ml-2';
            meta.textContent = '(' + c.count + ' ' + T.occ + ')';
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

    document.getElementById('reicat-lookup-btn').addEventListener('click', function () {
        const nameEl = document.getElementById('nome');
        const name = nameEl ? nameEl.value.trim() : '';
        statusEl.textContent = T.searching;

        const fd = new URLSearchParams();
        fd.set('name', name);
        fd.set('csrf_token', csrf);

        fetch(base + '/admin/autori/' + encodeURIComponent(authorId) + '/lookup-ccn', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
            body: fd.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.success) { statusEl.textContent = (d && d.error) ? d.error : T.error; return; }
            showCandidates(d.candidates || []);
        })
        .catch(function () { statusEl.textContent = T.error; });
    });
})();
</script>

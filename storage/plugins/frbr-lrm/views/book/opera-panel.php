<?php
/**
 * FRBR/LRM "link this book to an Opera (Work)" panel, injected into the book
 * edit form via the `book.form.fields` hook (issue #134, finding #6).
 *
 * In scope:
 * @var int $id                            book id (0 for a not-yet-saved book)
 * @var array<string,mixed>|null $currentOpera  the Work this book is linked to
 * @var string $csrf
 *
 * Chrome copied from the sibling z39-server REICAT/SBN book-form panel so the
 * two panels read as one system. All writes go through the existing endpoints
 * (POST /admin/books/{id}/attach-opera, GET /api/opere/search) via fetch —
 * no nested <form> (the panel lives inside the book form).
 */

$hasOpera = $currentOpera !== null;
$operaLabel = $hasOpera ? (string) ($currentOpera['titolo_uniforme'] ?? '') : '';
$operaAuthor = $hasOpera ? (string) ($currentOpera['autore_nome'] ?? '') : '';
$operaId = $hasOpera ? (int) ($currentOpera['id'] ?? 0) : 0;
?>

<div id="frbr-opera-panel"
     class="mt-6 bg-gradient-to-br from-emerald-50 to-teal-50 border-2 border-emerald-200 rounded-2xl p-6"
     data-csrf="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"
     data-book-id="<?php echo (int) $id; ?>">

    <button type="button" id="frbr-opera-toggle"
            class="w-full flex items-center justify-between gap-2 text-left"
            aria-expanded="<?= $hasOpera ? 'true' : 'false' ?>" aria-controls="frbr-opera-body">
        <span class="text-lg font-bold text-emerald-900 flex items-center gap-2">
            <i class="fas fa-sitemap text-emerald-600"></i>
            <?= __("Opera FRBR/LRM (Work)") ?>
        </span>
        <i class="fas fa-chevron-down text-emerald-600 transition-transform duration-200 <?= $hasOpera ? 'rotate-180' : '' ?>"
           id="frbr-opera-chevron"></i>
    </button>

    <div id="frbr-opera-body" class="<?= $hasOpera ? 'mt-4' : 'hidden' ?>">
    <p class="text-sm text-emerald-700 mb-4">
        <i class="fas fa-info-circle mr-1"></i>
        <?= __("Collega questo libro a un'Opera per raggruppare tutte le sue edizioni sotto un unico record.") ?>
    </p>

    <?php if ($id <= 0): ?>
        <p class="text-sm text-emerald-800 bg-emerald-100 border border-emerald-300 rounded-lg px-3 py-2">
            <i class="fas fa-exclamation-circle mr-1"></i>
            <?= __("Salva prima il libro: potrai collegarlo a un'Opera dalla pagina di modifica.") ?>
        </p>
    <?php else: ?>
        <!-- Current link -->
        <div id="frbr-opera-current" class="mb-4 <?= $hasOpera ? '' : 'hidden' ?>">
            <p class="text-xs uppercase tracking-wide text-emerald-700 font-semibold mb-1"><?= __("Collegato a") ?></p>
            <div class="flex items-center justify-between gap-2 bg-white border border-emerald-200 rounded-lg px-3 py-2">
                <span class="text-sm text-gray-900">
                    <i class="fas fa-book-open text-emerald-600 mr-1"></i>
                    <span id="frbr-opera-current-label"><?php echo htmlspecialchars($operaLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if ($operaAuthor !== ''): ?>
                        <span class="text-gray-400" id="frbr-opera-current-author">· <?php echo htmlspecialchars($operaAuthor, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php else: ?>
                        <span class="text-gray-400 hidden" id="frbr-opera-current-author"></span>
                    <?php endif; ?>
                </span>
                <span class="flex items-center gap-3">
                    <a id="frbr-opera-view" href="<?php echo $operaId > 0 ? htmlspecialchars(url('/admin/opere/' . $operaId), ENT_QUOTES, 'UTF-8') : '#'; ?>"
                       class="text-xs text-emerald-800 hover:text-emerald-900 font-medium <?= $operaId > 0 ? '' : 'hidden' ?>">
                        <i class="fas fa-external-link-alt mr-1"></i><?= __("Vedi Opera") ?>
                    </a>
                    <button type="button" id="frbr-opera-unlink"
                            class="text-xs text-red-600 hover:text-red-700 font-medium">
                        <i class="fas fa-unlink mr-1"></i><?= __("Scollega") ?>
                    </button>
                </span>
            </div>
        </div>

        <!-- Search + attach -->
        <div>
            <label for="frbr_opera_search" class="form-label flex items-center gap-2">
                <i class="fas fa-search text-emerald-600"></i>
                <?= __("Cerca un'Opera") ?>
            </label>
            <div class="relative">
                <input type="text" id="frbr_opera_search" class="form-input w-full" autocomplete="off"
                       placeholder="<?= __('Digita un titolo uniforme…') ?>" />
                <div id="frbr_opera_results"
                     class="absolute z-20 left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg hidden max-h-64 overflow-auto"></div>
            </div>
            <p class="text-xs text-emerald-700 mt-2">
                <i class="fas fa-plus-circle mr-1"></i>
                <a href="<?php echo htmlspecialchars(url('/admin/opere/new'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank"
                   class="text-emerald-800 hover:text-emerald-900 font-medium"><?= __("Crea una nuova Opera") ?></a>
            </p>
        </div>
        <div id="frbr-opera-status" class="text-sm mt-3 hidden"></div>
    <?php endif; ?>
    </div><!-- /#frbr-opera-body -->
</div>

<?php if ($id > 0): ?>
<script>
(function () {
    const panel = document.getElementById('frbr-opera-panel');
    if (!panel || panel.dataset.frbrInit === '1') { return; }
    panel.dataset.frbrInit = '1';

    const csrf = panel.dataset.csrf || '';
    const bookId = panel.dataset.bookId || '0';
    const base = (window.BASE_PATH || '');

    const T = {
        searching: <?= json_encode(__('Ricerca in corso…'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        none: <?= json_encode(__('Nessuna Opera trovata.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        linked: <?= json_encode(__('Collegamento aggiornato.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        unlinked: <?= json_encode(__('Libro scollegato dall\'Opera.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        error: <?= json_encode(__('Errore durante la richiesta.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        view: <?= json_encode(__('Vedi Opera'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
    };

    // Accordion toggle (matches the sibling REICAT/SBN panel behaviour).
    const acToggle = document.getElementById('frbr-opera-toggle');
    const acBody = document.getElementById('frbr-opera-body');
    const acChevron = document.getElementById('frbr-opera-chevron');
    function openPanel() {
        if (!acBody || !acBody.classList.contains('hidden')) { return; }
        acBody.classList.remove('hidden');
        acBody.classList.add('mt-4');
        if (acToggle) { acToggle.setAttribute('aria-expanded', 'true'); }
        if (acChevron) { acChevron.classList.add('rotate-180'); }
    }
    if (acToggle && acBody) {
        acToggle.addEventListener('click', function () {
            if (acBody.classList.contains('hidden')) {
                openPanel();
            } else {
                acBody.classList.add('hidden');
                acBody.classList.remove('mt-4');
                acToggle.setAttribute('aria-expanded', 'false');
                if (acChevron) { acChevron.classList.remove('rotate-180'); }
            }
        });
    }

    function clearEl(el) { while (el.firstChild) { el.removeChild(el.firstChild); } }
    function status(msg, kind) {
        const box = document.getElementById('frbr-opera-status');
        if (!box) { return; }
        box.textContent = msg;
        box.className = 'text-sm mt-3 ' + (kind === 'err' ? 'text-red-600' : (kind === 'ok' ? 'text-emerald-700' : 'text-gray-600'));
        box.classList.remove('hidden');
    }

    // ── POST helper to the existing attach-opera endpoint ────────────────────
    function postAttach(operaId, onOk) {
        const fd = new URLSearchParams();
        fd.set('csrf_token', csrf);
        if (operaId) { fd.set('opera_id', String(operaId)); }
        fetch(base + '/admin/books/' + encodeURIComponent(bookId) + '/attach-opera', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: fd.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.success) { status((d && d.error) ? d.error : T.error, 'err'); return; }
            onOk(d.opera || null);
        })
        .catch(function () { status(T.error, 'err'); });
    }

    // ── Current-link block ───────────────────────────────────────────────────
    const currentBox = document.getElementById('frbr-opera-current');
    const currentLabel = document.getElementById('frbr-opera-current-label');
    const currentAuthor = document.getElementById('frbr-opera-current-author');
    const viewLink = document.getElementById('frbr-opera-view');

    function renderCurrent(opera) {
        if (!currentBox) { return; }
        if (!opera) {
            currentBox.classList.add('hidden');
            return;
        }
        if (currentLabel) { currentLabel.textContent = opera.titolo_uniforme || ''; }
        if (currentAuthor) {
            if (opera.autore_nome) {
                currentAuthor.textContent = '· ' + opera.autore_nome;
                currentAuthor.classList.remove('hidden');
            } else {
                currentAuthor.textContent = '';
                currentAuthor.classList.add('hidden');
            }
        }
        if (viewLink) {
            if (opera.id) {
                viewLink.setAttribute('href', base + '/admin/opere/' + opera.id);
                viewLink.classList.remove('hidden');
            } else {
                viewLink.classList.add('hidden');
            }
        }
        currentBox.classList.remove('hidden');
    }

    const unlinkBtn = document.getElementById('frbr-opera-unlink');
    if (unlinkBtn) {
        unlinkBtn.addEventListener('click', function () {
            postAttach(null, function () {
                renderCurrent(null);
                status(T.unlinked, 'ok');
            });
        });
    }

    // ── Autocomplete search against the existing /api/opere/search endpoint ──
    const sInput = document.getElementById('frbr_opera_search');
    const sResults = document.getElementById('frbr_opera_results');
    let sTimer = null;

    function hideResults() { if (sResults) { clearEl(sResults); sResults.classList.add('hidden'); } }
    function showResults(items) {
        if (!sResults) { return; }
        clearEl(sResults);
        if (!items.length) { sResults.classList.add('hidden'); return; }
        items.forEach(function (it) {
            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'block w-full text-left px-3 py-2 text-sm hover:bg-emerald-50';
            row.textContent = it.label || '';
            row.addEventListener('click', function () {
                hideResults();
                if (sInput) { sInput.value = ''; }
                postAttach(it.id, function (opera) {
                    renderCurrent(opera);
                    openPanel();
                    status(T.linked, 'ok');
                });
            });
            sResults.appendChild(row);
        });
        sResults.classList.remove('hidden');
    }

    if (sInput) {
        sInput.addEventListener('input', function () {
            const q = sInput.value.trim();
            if (sTimer) { clearTimeout(sTimer); }
            if (q.length < 2) { hideResults(); return; }
            sTimer = setTimeout(function () {
                fetch(base + '/api/opere/search?q=' + encodeURIComponent(q), {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                })
                .then(function (r) { return r.json(); })
                .then(function (d) { showResults(Array.isArray(d) ? d : []); })
                .catch(function () { hideResults(); });
            }, 280);
        });
        sInput.addEventListener('keydown', function (e) { if (e.key === 'Escape') { hideResults(); } });
    }
    document.addEventListener('click', function (e) {
        if (sResults && !sResults.contains(e.target) && e.target !== sInput) { hideResults(); }
    });
})();
</script>
<?php endif; ?>

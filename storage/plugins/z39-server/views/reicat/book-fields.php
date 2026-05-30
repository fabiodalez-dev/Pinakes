<?php
/**
 * REICAT/SBN cataloguing fields injected into the book form via the
 * `book.form.fields` hook (issue #133).
 *
 * In scope: $reicat (sbn_bid, sbn_authority_level, sbn_polo, soggetti),
 *           $book (?array), $bookId (?int), $csrf (string), $id (int).
 *
 * @var array{sbn_bid:string,sbn_authority_level:string,sbn_polo:string,soggetti:array<int,array{id:int,termine:string,bncf_id:?string,uri:?string,schema:string}>} $reicat
 * @var int $id
 * @var string $csrf
 */

use App\Support\HtmlHelper;

$soggettiJson = json_encode(array_map(static function (array $s): array {
    return ['termine' => $s['termine'], 'bncf_id' => $s['bncf_id'], 'uri' => $s['uri']];
}, $reicat['soggetti']), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$soggettiJson = $soggettiJson !== false ? $soggettiJson : '[]';
?>

<div id="reicat-sbn-panel"
     class="mt-6 bg-gradient-to-br from-emerald-50 to-teal-50 border-2 border-emerald-200 rounded-2xl p-6"
     data-csrf="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>"
     data-book-id="<?php echo (int) $id; ?>">

    <h3 class="text-lg font-bold text-emerald-900 mb-1 flex items-center gap-2">
        <i class="fas fa-landmark text-emerald-600"></i>
        <?= __("Catalogazione REICAT / SBN") ?>
    </h3>
    <p class="text-sm text-emerald-700 mb-4">
        <i class="fas fa-info-circle mr-1"></i>
        <?= __("Importa da SBN (OPAC Nazionale), gestisci l'identificativo BID e i soggetti del Nuovo Soggettario BNCF, esporta in UNIMARC.") ?>
    </p>

    <!-- Import from SBN -->
    <div class="mb-6 flex flex-col md:flex-row gap-2 md:items-end">
        <div class="flex-1">
            <label for="reicat_import_isbn" class="form-label"><?= __("ISBN per import SBN") ?></label>
            <input type="text" id="reicat_import_isbn" class="form-input w-full"
                   placeholder="<?= __('es. 9788845292866') ?>" />
        </div>
        <button type="button" id="reicat-import-btn"
                class="btn btn-primary flex items-center justify-center gap-2">
            <i class="fas fa-download"></i>
            <?= __("Importa da SBN") ?>
        </button>
    </div>
    <div id="reicat-import-status" class="text-sm mb-4 hidden"></div>

    <!-- SBN identifiers -->
    <div class="form-grid-2 mb-4">
        <div>
            <label for="sbn_bid_display" class="form-label flex items-center gap-2">
                <i class="fas fa-fingerprint text-emerald-600"></i>
                <?= __("BID (SBN Bibliographic ID)") ?>
            </label>
            <input type="text" id="sbn_bid_display" class="form-input"
                   value="<?php echo HtmlHelper::e($reicat['sbn_bid']); ?>"
                   placeholder="IT\ICCU\…" />
            <input type="hidden" name="sbn_bid" id="sbn_bid"
                   value="<?php echo HtmlHelper::e($reicat['sbn_bid']); ?>" />
        </div>
        <div>
            <label for="sbn_polo" class="form-label"><?= __("Polo SBN") ?></label>
            <input type="text" id="sbn_polo" name="sbn_polo" class="form-input"
                   value="<?php echo HtmlHelper::e($reicat['sbn_polo']); ?>"
                   placeholder="<?= __('es. RMB') ?>" />
        </div>
    </div>

    <div class="mb-6">
        <label for="sbn_authority_level" class="form-label"><?= __("Livello di catalogazione") ?></label>
        <select id="sbn_authority_level" name="sbn_authority_level" class="form-input">
            <?php $lvl = $reicat['sbn_authority_level']; ?>
            <option value="" <?= $lvl === '' ? 'selected' : '' ?>><?= __("Non specificato") ?></option>
            <option value="95" <?= $lvl === '95' ? 'selected' : '' ?>><?= __("95 — Catalogazione originale") ?></option>
            <option value="51" <?= $lvl === '51' ? 'selected' : '' ?>><?= __("51 — Catalogazione derivata") ?></option>
        </select>
        <p class="text-xs text-emerald-700 mt-1">
            <?= __("REICAT: distingue il record catalogato originalmente (95) da quello derivato (51).") ?>
        </p>
    </div>

    <!-- Nuovo Soggettario picker -->
    <div class="mb-4">
        <label for="reicat_soggettario_search" class="form-label flex items-center gap-2">
            <i class="fas fa-tags text-emerald-600"></i>
            <?= __("Soggetti (Nuovo Soggettario BNCF)") ?>
        </label>
        <div class="relative">
            <input type="text" id="reicat_soggettario_search" class="form-input w-full" autocomplete="off"
                   placeholder="<?= __('Cerca un soggetto e premi Invio per aggiungere…') ?>" />
            <div id="reicat_soggettario_results"
                 class="absolute z-20 left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg hidden max-h-64 overflow-auto"></div>
        </div>
        <div id="reicat_soggetti_chips" class="flex flex-wrap gap-2 mt-3"></div>
        <input type="hidden" name="reicat_soggetti" id="reicat_soggetti" value="<?php echo htmlspecialchars($soggettiJson, ENT_QUOTES, 'UTF-8'); ?>" />
        <p class="text-xs text-emerald-700 mt-1">
            <?= __("I termini con identificativo BNCF sono controllati; puoi comunque aggiungere soggetti liberi.") ?>
        </p>
    </div>

    <?php if ($id > 0): ?>
    <!-- UNIMARC export -->
    <div class="pt-3 border-t border-emerald-200">
        <a href="<?php echo htmlspecialchars(url('/admin/libri/' . $id . '/export.unimarc.xml'), ENT_QUOTES, 'UTF-8'); ?>"
           class="inline-flex items-center gap-2 text-sm text-emerald-800 hover:text-emerald-900 font-medium">
            <i class="fas fa-file-export"></i>
            <?= __("Esporta record UNIMARC (MARCXchange)") ?>
        </a>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    const panel = document.getElementById('reicat-sbn-panel');
    if (!panel || panel.dataset.reicatInit === '1') { return; }
    panel.dataset.reicatInit = '1';

    const csrf = panel.dataset.csrf || '';
    const bookId = panel.dataset.bookId || '0';
    const base = (window.BASE_PATH || '');

    const T = {
        importing: <?= json_encode(__('Importazione in corso…'), JSON_HEX_TAG) ?>,
        noIsbn: <?= json_encode(__('Inserisci un ISBN.'), JSON_HEX_TAG) ?>,
        notFound: <?= json_encode(__('Nessun record trovato su SBN.'), JSON_HEX_TAG) ?>,
        imported: <?= json_encode(__('Dati importati da SBN.'), JSON_HEX_TAG) ?>,
        error: <?= json_encode(__('Errore durante la richiesta.'), JSON_HEX_TAG) ?>,
        removeSubject: <?= json_encode(__('Rimuovi soggetto'), JSON_HEX_TAG) ?>,
    };

    function clearEl(el) { while (el.firstChild) { el.removeChild(el.firstChild); } }

    // ── Soggettario chips state ─────────────────────────────────────────────
    const hidden = document.getElementById('reicat_soggetti');
    const chipsBox = document.getElementById('reicat_soggetti_chips');
    let soggetti = [];
    try { soggetti = JSON.parse(hidden.value || '[]') || []; } catch (e) { soggetti = []; }

    function syncHidden() { hidden.value = JSON.stringify(soggetti); }
    function renderChips() {
        clearEl(chipsBox);
        soggetti.forEach(function (s, idx) {
            const chip = document.createElement('span');
            chip.className = 'inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs ' +
                (s.bncf_id ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-gray-100 text-gray-700 border border-gray-300');
            chip.appendChild(document.createTextNode(s.termine + (s.bncf_id ? ' [' + s.bncf_id + ']' : '')));
            const x = document.createElement('button');
            x.type = 'button';
            x.className = 'ml-1 font-bold hover:text-red-600';
            x.textContent = '×';
            x.setAttribute('aria-label', T.removeSubject + ': ' + s.termine);
            x.addEventListener('click', function () { soggetti.splice(idx, 1); syncHidden(); renderChips(); });
            chip.appendChild(x);
            chipsBox.appendChild(chip);
        });
    }
    function addSubject(s) {
        const exists = soggetti.some(function (x) {
            return (s.bncf_id && x.bncf_id === s.bncf_id) ||
                   (!s.bncf_id && x.termine.toLowerCase() === s.termine.toLowerCase());
        });
        if (!exists) { soggetti.push(s); syncHidden(); renderChips(); }
    }
    renderChips();

    // ── Soggettario autocomplete ────────────────────────────────────────────
    const sInput = document.getElementById('reicat_soggettario_search');
    const sResults = document.getElementById('reicat_soggettario_results');
    let sTimer = null;

    function hideResults() { clearEl(sResults); sResults.classList.add('hidden'); }
    function showResults(items) {
        clearEl(sResults);
        if (!items.length) { sResults.classList.add('hidden'); return; }
        items.forEach(function (it) {
            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'block w-full text-left px-3 py-2 text-sm hover:bg-emerald-50';
            row.textContent = it.termine + ' [' + it.bncf_id + ']';
            row.addEventListener('click', function () {
                addSubject({ termine: it.termine, bncf_id: it.bncf_id, uri: it.uri });
                sInput.value = '';
                hideResults();
            });
            sResults.appendChild(row);
        });
        sResults.classList.remove('hidden');
    }

    sInput.addEventListener('input', function () {
        const q = sInput.value.trim();
        if (sTimer) { clearTimeout(sTimer); }
        if (q.length < 2) { hideResults(); return; }
        sTimer = setTimeout(function () {
            fetch(base + '/admin/libri/soggettario-search?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) { showResults((d && d.results) ? d.results : []); })
                .catch(function () { hideResults(); });
        }, 280);
    });
    // Enter adds a free-text subject when no controlled term is picked; Escape closes results.
    sInput.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            hideResults();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const q = sInput.value.trim();
            if (q) { addSubject({ termine: q, bncf_id: null, uri: null }); sInput.value = ''; hideResults(); }
        }
    });
    document.addEventListener('click', function (e) {
        if (!sResults.contains(e.target) && e.target !== sInput) { hideResults(); }
    });

    // ── Import from SBN ──────────────────────────────────────────────────────
    function setField(elId, value) {
        const el = document.getElementById(elId);
        if (el && value != null && String(value) !== '') { el.value = value; }
    }
    function status(msg, kind) {
        const box = document.getElementById('reicat-import-status');
        box.textContent = msg;
        box.className = 'text-sm mb-4 ' + (kind === 'err' ? 'text-red-600' : (kind === 'ok' ? 'text-emerald-700' : 'text-gray-600'));
        box.classList.remove('hidden');
    }

    // Pre-fill the SBN import field from the ISBN already entered or scraped in
    // the main form, so the user does not have to retype it. Only fills while
    // the field is empty, so a manual entry here is never clobbered.
    (function prefillReicatIsbn() {
        const target = document.getElementById('reicat_import_isbn');
        if (!target) { return; }
        const i13 = document.getElementById('isbn13');
        const i10 = document.getElementById('isbn10');
        const sync = function () {
            if (target.value.trim() !== '') { return; }
            const v = (i13 && i13.value.trim()) ? i13.value.trim()
                    : ((i10 && i10.value.trim()) ? i10.value.trim() : '');
            if (v) { target.value = v; }
        };
        sync();
        if (i13) { i13.addEventListener('input', sync); }
        if (i10) { i10.addEventListener('input', sync); }
    })();

    document.getElementById('reicat-import-btn').addEventListener('click', function () {
        const btn = this;
        let isbn = (document.getElementById('reicat_import_isbn').value || '').trim();
        if (!isbn) {
            const i13 = document.getElementById('isbn13'); const i10 = document.getElementById('isbn10');
            isbn = (i13 && i13.value) ? i13.value : ((i10 && i10.value) ? i10.value : '');
        }
        isbn = isbn.replace(/[^0-9Xx]/g, '');
        if (!isbn) { status(T.noIsbn, 'err'); return; }

        btn.disabled = true;
        status(T.importing, 'info');
        const fd = new URLSearchParams();
        fd.set('isbn', isbn);
        fd.set('csrf_token', csrf);
        if (bookId && bookId !== '0') { fd.set('libro_id', bookId); }

        fetch(base + '/admin/libri/import-sbn', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
            body: fd.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.success || !d.book) { status((d && d.error) ? d.error : T.notFound, 'err'); return; }
            const b = d.book;
            setField('titolo', b.title || b.titolo);
            setField('sottotitolo', b.subtitle || b.sottotitolo);
            setField('anno_pubblicazione', b.year || b.anno_pubblicazione);
            setField('numero_pagine', b.pages || b.numero_pagine);
            setField('isbn13', b.isbn13);
            setField('isbn10', b.isbn10);
            setField('collana', b.series || b.collana);
            if (d.sbn_bid) { setField('sbn_bid', d.sbn_bid); setField('sbn_bid_display', d.sbn_bid); }
            if (d.sbn_polo) { setField('sbn_polo', d.sbn_polo); }
            status(T.imported + (b.author ? ' — ' + b.author : ''), 'ok');
        })
        .catch(function () { status(T.error, 'err'); })
        .finally(function () { btn.disabled = false; });
    });
})();
</script>

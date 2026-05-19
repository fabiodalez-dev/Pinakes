<?php
/**
 * Archives — Luoghi (Phase 5 / v0.8.0) — detail view.
 *
 * @var array<string, mixed>|null       $row
 * @var list<array<string, mixed>>|null $relations
 * @var string|null                     $parent_label
 */
declare(strict_types=1);

$e            = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$row          = $row          ?? [];
$relations    = $relations    ?? [];
$parent_label = $parent_label ?? null;
$id           = (int) ($row['id'] ?? 0);

/**
 * Safely serialise a PHP value as a JS literal for use INSIDE an HTML
 * attribute delimited with double quotes (e.g. `onclick="..."`).
 * Matches the helper used in storage/plugins/archives/views/show.php.
 */
$jsAttr = static fn (mixed $x): string =>
    htmlspecialchars(
        (string) json_encode($x, JSON_UNESCAPED_UNICODE),
        ENT_QUOTES,
        'UTF-8'
    );

$archivesDeletePlaceId = 'archivesDeletePlace_' . $id;

$typeLabel = [
    'country'            => __('Paese'),
    'region'             => __('Regione'),
    'province'           => __('Provincia'),
    'municipality'       => __('Comune'),
    'locality'           => __('Località'),
    'building'           => __('Edificio'),
    'room'               => __('Locale'),
    'geographic_feature' => __('Caratteristica geografica'),
    'other'              => __('Altro'),
];
$type = (string) ($row['place_type'] ?? '');

/** @var array<string, string> $relationTargetLabels */
$relationTargetLabels = [
    'archive_activity' => __('Attività'),
    'archival_unit'    => __('Unità archivistica'),
    'authority_record' => __('Autorità'),
    'archive_place'    => __('Luogo'),
];
?>
<div class="p-6 max-w-4xl mx-auto">
    <div class="mb-6">
        <nav class="text-sm text-gray-500 mb-2">
            <a href="<?= $e(url('/admin/archives')) ?>" class="hover:underline"><?= __('Archivi') ?></a>
            &nbsp;&raquo;&nbsp;
            <a href="<?= $e(url('/admin/archives/places')) ?>" class="hover:underline"><?= __('Luoghi') ?></a>
            &nbsp;&raquo;&nbsp; #<?= $id ?>
        </nav>
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900"><?= $e((string) ($row['name'] ?? '')) ?></h1>
                <p class="text-sm text-gray-600 mt-1">
                    <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded bg-gray-100 text-gray-700">
                        <?= $e($typeLabel[$type] ?? $type) ?>
                    </span>
                </p>
            </div>
            <div class="space-x-2">
                <a href="<?= $e(url('/admin/archives/places/' . $id . '/edit')) ?>"
                   class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded text-sm font-semibold shadow-sm">
                    <?= __('Modifica') ?>
                </a>
                <?php
                /* FIX F038: dynamic relation-count blast-radius message,
                   mirroring activities/show.php. Static "Eliminare questo
                   luogo?" gave no signal that descendants/relations would
                   become orphaned. */
                $relCount = (int) ($relation_count ?? 0);
                $archivesDeletePlaceMessage = $relCount > 0
                    ? sprintf(__('Eliminare questo luogo? È collegato a %d relazione/i; le relazioni saranno orfane.'), $relCount)
                    : __('Eliminare questo luogo?');
                ?>
                <form id="<?= $e($archivesDeletePlaceId) ?>"
                      method="POST" action="<?= $e(url('/admin/archives/places/' . $id . '/delete')) ?>"
                      class="inline">
                    <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
                    <button type="button"
                            onclick="archivesSwalConfirm(<?= $jsAttr($archivesDeletePlaceId) ?>, <?= $jsAttr($archivesDeletePlaceMessage) ?>, <?= $jsAttr(__('Elimina')) ?>)"
                            class="bg-red-50 hover:bg-red-100 text-red-700 px-4 py-2 rounded text-sm font-semibold">
                        <?= __('Elimina') ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="bg-white shadow rounded-lg p-6 space-y-4">
        <?php if (!empty($row['description'])): ?>
            <div>
                <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2"><?= __('Descrizione') ?></h2>
                <div class="text-sm text-gray-800"><?= nl2br($e((string) $row['description'])) ?></div>
            </div>
        <?php endif; ?>

        <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <?php if (!empty($row['latitude']) && !empty($row['longitude'])): ?>
                <dt class="font-semibold text-gray-700"><?= __('Coordinate') ?></dt>
                <dd class="text-gray-800">
                    <?= $e((string) $row['latitude']) ?>, <?= $e((string) $row['longitude']) ?>
                </dd>
            <?php endif; ?>
            <?php if (!empty($row['geonames_id'])): ?>
                <dt class="font-semibold text-gray-700"><?= __('Identificativo GeoNames') ?></dt>
                <dd class="text-gray-800"><code class="text-xs"><?= $e((string) $row['geonames_id']) ?></code></dd>
            <?php endif; ?>
            <?php if (!empty($row['wikidata_id'])): ?>
                <dt class="font-semibold text-gray-700"><?= __('Identificativo Wikidata') ?></dt>
                <dd class="text-gray-800"><code class="text-xs"><?= $e((string) $row['wikidata_id']) ?></code></dd>
            <?php endif; ?>
            <?php if (!empty($row['tgn_id'])): ?>
                <dt class="font-semibold text-gray-700"><?= __('Identificativo Getty TGN') ?></dt>
                <dd class="text-gray-800"><code class="text-xs"><?= $e((string) $row['tgn_id']) ?></code></dd>
            <?php endif; ?>
            <?php if (!empty($row['parent_id'])): ?>
                <dt class="font-semibold text-gray-700"><?= __('Luogo padre') ?></dt>
                <dd>
                    <a class="text-blue-600 hover:underline"
                       href="<?= $e(url('/admin/archives/places/' . (int) $row['parent_id'])) ?>"><?= $parent_label ? $e($parent_label) : '#' . (int) $row['parent_id'] ?></a>
                </dd>
            <?php endif; ?>
            <?php if (!empty($row['date_start']) || !empty($row['date_end'])): ?>
                <dt class="font-semibold text-gray-700"><?= __('Intervallo storico') ?></dt>
                <dd class="text-gray-800">
                    <?= $e((string) ($row['date_start'] ?? '')) ?>
                    <?php if (!empty($row['date_end'])): ?>–<?= $e((string) $row['date_end']) ?><?php endif; ?>
                </dd>
            <?php endif; ?>
        </dl>
    </div>

    <div class="bg-white shadow rounded-lg p-6 mt-4">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3"><?= __('Relazioni RiC-CM') ?></h2>
        <?php if (empty($relations)): ?>
            <p class="text-sm text-gray-500"><?= __('Nessuna relazione collegata.') ?></p>
        <?php else: ?>
            <ul class="space-y-2 text-sm">
                <?php foreach ($relations as $rel):
                    $relId = (int) ($rel['id'] ?? 0);
                    $pred  = (string) ($rel['ric_predicate'] ?? '');
                    $tType = (string) ($rel['target_type'] ?? '');
                    $tId   = (int) ($rel['target_id'] ?? 0);
                ?>
                    <?php $relDetachId = 'archivesDetachRel_' . $relId; ?>
                    <li class="flex items-center gap-2">
                        <code class="text-xs text-gray-500 bg-gray-50 px-1 py-0.5 rounded"><?= $e($pred) ?></code>
                        <span><?= $e($relationTargetLabels[$tType] ?? $tType) ?> #<?= $tId ?></span>
                        <form id="<?= $e($relDetachId) ?>" method="POST" action="<?= $e(url('/admin/archives/relations/' . $relId . '/detach')) ?>" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
                            <input type="hidden" name="_return_to" value="<?= $e('/admin/archives/places/' . $id) ?>">
                            <button type="button"
                                    onclick="archivesSwalConfirm(<?= $jsAttr($relDetachId) ?>, <?= $jsAttr(__('Scollegare questa relazione?')) ?>, <?= $jsAttr(__('scollega')) ?>)"
                                    class="text-red-600 text-xs hover:underline">
                                <?= __('scollega') ?>
                            </button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="POST" action="<?= $e(url('/admin/archives/relations/attach')) ?>"
              class="mt-4 grid grid-cols-1 md:grid-cols-4 gap-2 text-sm"
              data-archives-relation-attach>
            <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
            <input type="hidden" name="source_type" value="archive_place">
            <input type="hidden" name="source_id" value="<?= $id ?>">
            <input type="hidden" name="_return_to" value="<?= $e('/admin/archives/places/' . $id) ?>">
            <select name="target_type" id="rel_target_type" required class="rounded-md border-gray-300">
                <option value=""><?= __('Tipo entità') ?></option>
                <option value="archival_unit"><?= __('Unità archivistica') ?></option>
                <option value="authority_record"><?= __('Agente') ?></option>
                <option value="archive_activity"><?= __('Attività') ?></option>
                <option value="archive_place"><?= __('Luogo') ?></option>
            </select>
            <div class="relative">
                <?php /* FIX F036: ARIA combobox/listbox/option attrs — see
                         activities/show.php for the matching pattern. */ ?>
                <input type="text" id="rel_target_search" autocomplete="off"
                       data-typeahead-input data-typeahead-target="rel_target_id"
                       placeholder="<?= $e(__('Cerca per nome o ID...')) ?>"
                       role="combobox"
                       aria-autocomplete="list"
                       aria-expanded="false"
                       aria-controls="rel_target_results"
                       aria-label="<?= $e(__('Cerca entità da collegare')) ?>"
                       class="rounded-md border-gray-300 w-full">
                <input type="hidden" id="rel_target_id" name="target_id" required>
                <div id="rel_target_results"
                     data-typeahead-results
                     role="listbox"
                     class="absolute z-10 w-full bg-white border border-gray-200 rounded mt-1 shadow-lg hidden max-h-60 overflow-y-auto"></div>
            </div>
            <input type="text" name="ric_predicate" required
                   placeholder="ric:isOrWasRelatedTo"
                   aria-label="<?= $e(__('Predicato RiC-O')) ?>"
                   class="rounded-md border-gray-300">
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded text-sm font-semibold"><?= __('Collega') ?></button>
        </form>
    </div>

    <p class="mt-4 text-xs text-gray-500">
        <?= __('Linked Data:') ?>
        <a class="text-blue-600 hover:underline"
           href="<?= $e(url('/archives/places/' . $id . '/ric.json')) ?>">
            /archives/places/<?= $id ?>/ric.json
        </a>
    </p>
</div>

<?php /* Relation-attach typeahead — fetches /api/archives/entities?type=&q=
         and populates the visible/hidden input pair. Pattern mirrors
         storage/plugins/archives/views/show.php (data-typeahead-*). */ ?>
<script>
(function () {
    var form = document.querySelector('form[data-archives-relation-attach]');
    if (!form) return;
    var input   = form.querySelector('[data-typeahead-input]');
    var results = form.querySelector('[data-typeahead-results]');
    var hidden  = document.getElementById(input.dataset.typeaheadTarget);
    var typeSel = form.querySelector('select[name="target_type"]');
    if (!input || !results || !hidden || !typeSel) return;
    var searchUrl = <?= json_encode(url('/api/archives/entities'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var noResultsMsg = <?= json_encode(__('Nessun risultato'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var debounceTimer = null;
    var lastQuery = '';

    function clearResults() { while (results.firstChild) results.removeChild(results.firstChild); }
    function hideResults() {
        results.classList.add('hidden');
        clearResults();
        // FIX F036: keep aria-expanded in sync with the visible state.
        if (input) input.setAttribute('aria-expanded', 'false');
    }

    function renderResults(rows) {
        clearResults();
        if (!rows.length) {
            var empty = document.createElement('div');
            empty.className = 'px-3 py-2 text-gray-500 italic text-sm';
            empty.textContent = noResultsMsg;
            results.appendChild(empty);
            results.classList.remove('hidden');
            return;
        }
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var div = document.createElement('div');
            div.className = 'px-3 py-2 cursor-pointer hover:bg-blue-50 text-sm';
            div.dataset.id = String(r.id);
            // FIX F036: role=option on each result so listbox semantics
            // are screen-reader navigable.
            div.setAttribute('role', 'option');
            var label = r.label || ('#' + r.id);
            if (r.extra) { label += ' (' + r.extra + ')'; }
            div.dataset.label = label;
            div.textContent = label;
            results.appendChild(div);
        }
        results.classList.remove('hidden');
        // FIX F036: aria-expanded mirrors the visible state of the listbox.
        if (input) input.setAttribute('aria-expanded', 'true');
    }

    function showLoading() {
        // FIX F039: visible loading indicator while the fetch is in flight.
        clearResults();
        var loading = document.createElement('div');
        loading.className = 'px-3 py-2 text-gray-500 italic text-sm';
        loading.setAttribute('role', 'status');
        loading.setAttribute('aria-live', 'polite');
        loading.textContent = '…';
        results.appendChild(loading);
        results.classList.remove('hidden');
        if (input) input.setAttribute('aria-expanded', 'true');
    }

    function search(q, type) {
        if (!type) { hideResults(); return; }
        if (q.length < 2) { lastQuery = ''; hideResults(); return; }
        var key = type + '|' + q;
        if (key === lastQuery) return;
        lastQuery = key;
        var snapshot = key;
        showLoading();  // FIX F039
        fetch(searchUrl + '?type=' + encodeURIComponent(type) + '&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : { results: [] }; })
            .then(function (data) {
                if ((typeSel.value + '|' + input.value.trim()) !== snapshot) return;
                renderResults(data.results || []);
            })
            .catch(function () {
                if ((typeSel.value + '|' + input.value.trim()) === snapshot) hideResults();
            });
    }

    input.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        var q = input.value.trim();
        hidden.value = '';
        debounceTimer = setTimeout(function () { search(q, typeSel.value); }, 200);
    });

    typeSel.addEventListener('change', function () {
        hidden.value = '';
        input.value = '';
        lastQuery = '';
        hideResults();
    });

    // FIX (CR full review): keyboard a11y on the typeahead listbox —
    // ArrowDown/ArrowUp move the active option, Enter selects, Escape
    // closes. Mirrors activities/show.php's keyboard implementation.
    function selectOption(div) {
        if (!div) return;
        hidden.value = div.dataset.id;
        input.value = div.dataset.label;
        hideResults();
        input.focus();
    }
    function getOptions() {
        return Array.prototype.slice.call(results.querySelectorAll('[data-id]'));
    }
    function setActive(idx, opts) {
        opts = opts || getOptions();
        opts.forEach(function (o) {
            o.classList.remove('bg-blue-100');
            o.removeAttribute('aria-selected');
            if (!o.id) o.id = 'archives-typeahead-place-opt-' + Math.random().toString(36).slice(2, 8);
        });
        if (idx < 0 || idx >= opts.length) {
            input.removeAttribute('aria-activedescendant');
            return -1;
        }
        var el = opts[idx];
        el.classList.add('bg-blue-100');
        el.setAttribute('aria-selected', 'true');
        input.setAttribute('aria-activedescendant', el.id);
        if (el.scrollIntoView) {
            el.scrollIntoView({ block: 'nearest', behavior: 'auto' });
        }
        return idx;
    }
    var activeIdx = -1;
    results.addEventListener('click', function (ev) {
        var div = ev.target.closest('[data-id]');
        if (!div) return;
        selectOption(div);
    });
    input.addEventListener('keydown', function (ev) {
        var opts = getOptions();
        if (results.classList.contains('hidden') || opts.length === 0) {
            if (ev.key === 'Escape') hideResults();
            return;
        }
        if (ev.key === 'ArrowDown') {
            ev.preventDefault();
            activeIdx = setActive((activeIdx + 1) % opts.length, opts);
        } else if (ev.key === 'ArrowUp') {
            ev.preventDefault();
            activeIdx = setActive(activeIdx <= 0 ? opts.length - 1 : activeIdx - 1, opts);
        } else if (ev.key === 'Enter') {
            if (activeIdx >= 0 && activeIdx < opts.length) {
                ev.preventDefault();
                selectOption(opts[activeIdx]);
            }
        } else if (ev.key === 'Escape') {
            ev.preventDefault();
            hideResults();
        }
    });
    input.addEventListener('input', function () { activeIdx = -1; });

    document.addEventListener('click', function (ev) {
        if (!form.contains(ev.target)) hideResults();
    });

    form.addEventListener('submit', function (ev) {
        if (!hidden.value) {
            ev.preventDefault();
            input.focus();
            input.classList.add('border-red-400');
            setTimeout(function () { input.classList.remove('border-red-400'); }, 1500);
        }
    });
})();
</script>

<?php /* SweetAlert2 confirm helper — matches the pattern used in
          storage/plugins/archives/views/show.php. Idempotency guard so
          multiple views on the same page can load it without redefining. */ ?>
<script>
if (typeof window.archivesSwalConfirm !== 'function') {
    window.archivesSwalConfirm = function (formId, message, confirmLabel) {
        var form = document.getElementById(formId);
        if (!form) return;
        if (typeof Swal === 'undefined' || !Swal.fire) {
            if (window.confirm(message)) form.submit();
            return;
        }
        Swal.fire({
            title: message,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: confirmLabel || <?= json_encode(__('Conferma'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            cancelButtonText: <?= json_encode(__('Annulla'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            confirmButtonColor: '#dc2626',
            focusCancel: true,
            reverseButtons: true
        }).then(function (r) {
            if (r && r.isConfirmed) form.submit();
        });
    };
}
</script>

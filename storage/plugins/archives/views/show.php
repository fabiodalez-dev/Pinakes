<?php
/**
 * Archives — show (detail) view.
 *
 * @var array<string, mixed> $row
 * @var string|null $parent_title
 * @var list<array<string, mixed>> $linked_authorities
 * @var list<array<string, mixed>> $available_authorities
 * @var list<string> $authority_roles
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$v = static fn(string $k): string => $e((string) ($row[$k] ?? ''));

/**
 * Safely serialise a PHP value as a JS literal for use INSIDE an HTML
 * attribute delimited with double quotes (e.g. `onclick="..."`).
 *
 * JSON_HEX_QUOT would escape the delimiter quotes of the JS string itself,
 * producing invalid JS like `archivesSwalConfirm(\u0022foo\u0022, …)`.
 * The right escape here is htmlspecialchars: `"` becomes `&quot;`, which
 * the HTML parser decodes back to `"` before handing the attribute value
 * to the JS engine — so the JS parser sees valid `"foo"` literals.
 */
$jsAttr = static fn(mixed $x): string =>
    htmlspecialchars(
        (string) json_encode($x, JSON_UNESCAPED_UNICODE),
        ENT_QUOTES,
        'UTF-8'
    );

$dateRange = '';
if ($row['date_start'] !== null) {
    $dateRange = (string) $row['date_start'];
    if ($row['date_end'] !== null && $row['date_end'] !== $row['date_start']) {
        $dateRange .= '–' . (string) $row['date_end'];
    }
}

$levelBadge = [
    'fonds'  => 'bg-purple-100 text-purple-800',
    'series' => 'bg-blue-100 text-blue-800',
    'file'   => 'bg-green-100 text-green-800',
    'item'   => 'bg-gray-100 text-gray-800',
];
$badgeClass = $levelBadge[(string) $row['level']] ?? 'bg-gray-100 text-gray-800';

$id = (int) $row['id'];
?>
<div class="p-6 max-w-4xl mx-auto">
    <nav class="text-sm text-gray-500 mb-2">
        <a href="<?= $e(url('/admin/archives')) ?>" class="hover:underline"><?= __("Archivi") ?></a>
        &nbsp;&raquo;&nbsp; <?= $v('reference_code') ?>
    </nav>

    <div class="flex items-start justify-between mb-6">
        <div>
            <div class="flex items-center gap-3 mb-1">
                <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded <?= $badgeClass ?>">
                    <?= $v('level') ?>
                </span>
                <span class="font-mono text-sm text-gray-500"><?= $v('reference_code') ?></span>
            </div>
            <h1 class="text-2xl font-bold text-gray-900"><?= $v('constructed_title') ?></h1>
            <?php if (!empty($row['formal_title']) && $row['formal_title'] !== $row['constructed_title']): ?>
                <p class="text-sm italic text-gray-600 mt-1">
                    <?= __("Titolo formale:") ?> <?= $v('formal_title') ?>
                </p>
            <?php endif; ?>
        </div>
        <div class="flex items-center gap-2">
            <a href="<?= $e(url('/admin/archives/' . $id . '/export.xml')) ?>"
               class="btn-secondary"
               title="<?= $e(__("Esporta MARCXML")) ?>">
                <?= __("MARCXML") ?>
            </a>
            <a href="<?= $e(url('/admin/archives/' . $id . '/edit')) ?>"
               class="btn-secondary">
                <?= __("Modifica") ?>
            </a>
            <?php
            // Aligned with the rest of Pinakes: destructive confirmations
            // go through SweetAlert2 (loaded globally in app/Views/layout.php).
            // json_encode serialises the localised message + button labels as
            // JS literals so apostrophes/quotes in any locale survive the
            // HTML-attribute → JS parse round-trip.
            $archivesDeleteUnitId = 'archivesDeleteUnit_' . $id;
            ?>
            <form id="<?= $e($archivesDeleteUnitId) ?>"
                  method="POST" action="<?= $e(url('/admin/archives/' . $id . '/delete')) ?>"
                  class="inline">
                <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
                <button type="button"
                        class="btn-danger"
                        onclick="archivesSwalConfirm(<?= $jsAttr($archivesDeleteUnitId) ?>, <?= $jsAttr(__("Eliminare questo record? L'operazione è reversibile (soft-delete) ma rimuoverà l'unità dalle viste.")) ?>, <?= $jsAttr(__("Elimina")) ?>)">
                    <?= __("Elimina") ?>
                </button>
            </form>
        </div>
    </div>

    <div class="bg-white shadow rounded-lg overflow-hidden">
        <dl class="divide-y divide-gray-200">
            <div class="px-6 py-3 grid grid-cols-3 gap-4">
                <dt class="text-sm font-medium text-gray-500"><?= __("Istituzione") ?></dt>
                <dd class="col-span-2 text-sm text-gray-900 font-mono"><?= $v('institution_code') ?></dd>
            </div>
            <?php if ($parent_title !== null): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Unità padre") ?></dt>
                    <dd class="col-span-2 text-sm text-gray-900">
                        <a href="<?= $e(url('/admin/archives/' . (int) $row['parent_id'])) ?>"
                           class="text-blue-600 hover:underline">
                            <?= $e($parent_title) ?>
                        </a>
                    </dd>
                </div>
            <?php endif; ?>
            <?php if ($dateRange !== ''): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Date estreme") ?></dt>
                    <dd class="col-span-2 text-sm text-gray-900"><?= $e($dateRange) ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($row['extent'])): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Estensione") ?></dt>
                    <dd class="col-span-2 text-sm text-gray-900"><?= $v('extent') ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($row['scope_content'])): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Ambito e contenuto") ?></dt>
                    <dd class="col-span-2 text-sm text-gray-900 whitespace-pre-wrap"><?= $v('scope_content') ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($row['language_codes'])): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Lingua") ?></dt>
                    <dd class="col-span-2 text-sm text-gray-900 font-mono"><?= $v('language_codes') ?></dd>
                </div>
            <?php endif; ?>
            <div class="px-6 py-3 grid grid-cols-3 gap-4">
                <dt class="text-sm font-medium text-gray-500"><?= __("Creato") ?></dt>
                <dd class="col-span-2 text-xs text-gray-600 font-mono"><?= $v('created_at') ?></dd>
            </div>
            <?php if (!empty($row['specific_material']) && $row['specific_material'] !== 'text'): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Tipo di materiale") ?></dt>
                    <dd class="col-span-2 text-sm text-gray-900"><?= $v('specific_material') ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($row['dimensions'])): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Dimensioni") ?></dt>
                    <dd class="col-span-2 text-sm text-gray-900"><?= $v('dimensions') ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($row['color_mode'])): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Modalità colore") ?></dt>
                    <dd class="col-span-2 text-sm text-gray-900"><?= $v('color_mode') ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($row['photographer'])): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Fotografo / autore primario") ?></dt>
                    <dd class="col-span-2 text-sm text-gray-900"><?= $v('photographer') ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($row['publisher'])): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Editore") ?></dt>
                    <dd class="col-span-2 text-sm text-gray-900"><?= $v('publisher') ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($row['collection_name'])): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Collezione") ?></dt>
                    <dd class="col-span-2 text-sm text-gray-900"><?= $v('collection_name') ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($row['local_classification'])): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Classificazione locale") ?></dt>
                    <dd class="col-span-2 text-sm text-gray-900 font-mono"><?= $v('local_classification') ?></dd>
                </div>
            <?php endif; ?>
            <?php if (!empty($row['updated_at']) && $row['updated_at'] !== $row['created_at']): ?>
                <div class="px-6 py-3 grid grid-cols-3 gap-4">
                    <dt class="text-sm font-medium text-gray-500"><?= __("Ultima modifica") ?></dt>
                    <dd class="col-span-2 text-xs text-gray-600 font-mono"><?= $v('updated_at') ?></dd>
                </div>
            <?php endif; ?>
        </dl>
    </div>

    <!-- Authority records linked to this archival_unit -->
    <div class="bg-white shadow rounded-lg overflow-hidden mt-6">
        <div class="px-6 py-3 bg-gray-50 border-b flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-700"><?= __("Authority records collegati") ?></h2>
            <a href="<?= $e(url('/admin/archives/authorities')) ?>" class="text-xs text-blue-600 hover:underline">
                <?= __("Gestisci authorities") ?>
            </a>
        </div>

        <?php $typeBadge = ['person'=>'bg-indigo-100 text-indigo-800','corporate'=>'bg-amber-100 text-amber-800','family'=>'bg-pink-100 text-pink-800']; ?>

        <?php if (empty($linked_authorities)): ?>
            <p class="px-6 py-4 text-sm text-gray-500 italic">
                <?= __("Nessun authority record collegato. Aggiungine uno qui sotto.") ?>
            </p>
        <?php else: ?>
            <ul class="divide-y divide-gray-200">
                <?php foreach ($linked_authorities as $auth): ?>
                    <?php
                    $authType = (string) ($auth['type'] ?? '');
                    $authBadge = $typeBadge[$authType] ?? 'bg-gray-100 text-gray-800';
                    $authId = (int) ($auth['id'] ?? 0);
                    ?>
                    <li class="px-6 py-3 flex items-center justify-between text-sm">
                        <div class="flex items-center gap-3">
                            <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded <?= $authBadge ?>"><?= $e($authType) ?></span>
                            <a href="<?= $e(url('/admin/archives/authorities/' . $authId)) ?>" class="text-blue-600 hover:underline">
                                <?= $e((string) ($auth['authorised_form'] ?? '')) ?>
                            </a>
                            <?php if (!empty($auth['dates_of_existence'])): ?>
                                <span class="text-xs text-gray-400 italic">(<?= $e((string) $auth['dates_of_existence']) ?>)</span>
                            <?php endif; ?>
                            <span class="text-xs text-gray-500"><?= __("ruolo:") ?> <strong><?= $e((string) $auth['role']) ?></strong></span>
                        </div>
                        <?php $detachId = 'archivesDetachAuth_' . $id . '_' . $authId; ?>
                        <form id="<?= $e($detachId) ?>" method="POST"
                              action="<?= $e(url('/admin/archives/' . $id . '/authorities/' . $authId . '/detach')) ?>"
                              class="inline">
                            <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
                            <button type="button" class="text-xs text-red-600 hover:underline"
                                    onclick="archivesSwalConfirm(<?= $jsAttr($detachId) ?>, <?= $jsAttr(__('Rimuovere questo collegamento?')) ?>, <?= $jsAttr(__('scollega')) ?>)"><?= __("scollega") ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <!-- Attach form with type-ahead (phase 7) -->
        <?php if (!empty($available_authorities)): ?>
            <form method="POST" action="<?= $e(url('/admin/archives/' . $id . '/authorities/attach')) ?>"
                  class="px-6 py-4 border-t bg-gray-50 relative"
                  data-archives-attach>
                <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
                <div class="flex items-center gap-2">
                    <div class="flex-1 relative">
                        <input type="text" id="archives-auth-input-<?= (int) $id ?>"
                               data-typeahead-input
                               placeholder="<?= $e(__("Cerca un authority record…")) ?>"
                               autocomplete="off"
                               class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500">
                        <input type="hidden" name="authority_id" id="archives-auth-id-<?= (int) $id ?>" required>
                        <ul data-typeahead-results
                            class="hidden absolute z-10 w-full mt-1 bg-white border border-gray-200 rounded-md shadow-lg max-h-60 overflow-auto text-sm">
                        </ul>
                    </div>
                    <select name="role" required
                            class="rounded-md border-gray-300 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500">
                        <?php foreach ($authority_roles as $r): ?>
                            <option value="<?= $e($r) ?>"><?= $e($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit"
                            class="btn-primary">
                        <?= __("Collega") ?>
                    </button>
                </div>
                <p class="text-xs text-gray-500 mt-2">
                    <?= __("Digita almeno 2 caratteri per cercare tra i authority record.") ?>
                </p>
            </form>
            <?php
            // Phase 7: inline script — keep the plugin self-contained. All DOM
            // construction uses createElement + textContent (no innerHTML) so
            // an authority name containing `<script>` (or any HTML) renders
            // as literal text, never as markup. The JSON endpoint is the
            // only source of result content, fetched same-origin.
            $searchUrl = url('/admin/archives/api/authorities/search');
            $msgNoResults = __("Nessun risultato");
            ?>
            <script>
            (function () {
                var form = document.querySelector('form[data-archives-attach]');
                if (!form) return;
                var input = form.querySelector('[data-typeahead-input]');
                var hidden = form.querySelector('input[name="authority_id"]');
                var results = form.querySelector('[data-typeahead-results]');
                var searchUrl = <?= json_encode($searchUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                var noResultsMsg = <?= json_encode($msgNoResults, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                var debounceTimer = null;
                var lastQuery = '';

                function clearResults() {
                    while (results.firstChild) {
                        results.removeChild(results.firstChild);
                    }
                }

                function hideResults() {
                    results.classList.add('hidden');
                    clearResults();
                }

                function renderResults(rows) {
                    clearResults();
                    if (!rows.length) {
                        var empty = document.createElement('li');
                        empty.className = 'px-3 py-2 text-gray-500 italic';
                        empty.textContent = noResultsMsg;
                        results.appendChild(empty);
                        results.classList.remove('hidden');
                        return;
                    }
                    for (var i = 0; i < rows.length; i++) {
                        var r = rows[i];
                        var li = document.createElement('li');
                        li.className = 'px-3 py-2 cursor-pointer hover:bg-blue-50';
                        li.dataset.id = String(r.id);
                        var label = '[' + r.type + '] ' + r.authorised_form;
                        if (r.dates_of_existence) { label += ' (' + r.dates_of_existence + ')'; }
                        li.dataset.label = label;
                        li.textContent = label;
                        results.appendChild(li);
                    }
                    results.classList.remove('hidden');
                }

                function search(q) {
                    if (q.length < 2) { hideResults(); return; }
                    if (q === lastQuery) return;
                    lastQuery = q;
                    fetch(searchUrl + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                        .then(function (r) { return r.ok ? r.json() : { results: [] }; })
                        .then(function (data) { renderResults(data.results || []); })
                        .catch(function () { hideResults(); });
                }

                input.addEventListener('input', function () {
                    clearTimeout(debounceTimer);
                    var q = input.value.trim();
                    hidden.value = '';
                    debounceTimer = setTimeout(function () { search(q); }, 200);
                });

                results.addEventListener('click', function (ev) {
                    var li = ev.target.closest('li[data-id]');
                    if (!li) return;
                    hidden.value = li.dataset.id;
                    input.value = li.dataset.label;
                    hideResults();
                });

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
        <?php else: ?>
            <p class="px-6 py-4 text-sm text-gray-500 italic border-t">
                <?= __("Nessun authority record disponibile.") ?>
                <a href="<?= $e(url('/admin/archives/authorities/new')) ?>" class="text-blue-600 hover:underline">
                    <?= __("Crealo ora") ?>
                </a>.
            </p>
        <?php endif; ?>
    </div>
</div>

<?php /* SweetAlert2 confirm helper — matches the pattern used elsewhere
          in Pinakes. Defined with an idempotency guard so multiple views
          can load it without redefining. */ ?>
<script>
if (typeof window.archivesSwalConfirm !== 'function') {
    window.archivesSwalConfirm = function (formId, message, confirmLabel) {
        var form = document.getElementById(formId);
        if (!form) return;
        // Graceful fallback if Swal somehow isn't loaded on the page.
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
            focusCancel: true,
            reverseButtons: true
        }).then(function (r) {
            if (r && r.isConfirmed) form.submit();
        });
    };
}
</script>

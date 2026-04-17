<?php
use App\Support\Csrf;

/** @var ?array{total_with_isbn: int, missing_cover: int, missing_description: int, pending: int} $stats */
/** @var ?bool $enabled */
/** @var ?string $pageTitle */
$pageTitle = $pageTitle ?? __('Arricchimento Massivo');
$stats = $stats ?? ['total_with_isbn' => 0, 'missing_cover' => 0, 'missing_description' => 0, 'pending' => 0];
$enabled = $enabled ?? false;
?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="mb-8">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900"><?= __("Arricchimento Massivo") ?></h1>
                <p class="mt-2 text-sm text-gray-600"><?= __("Arricchisci automaticamente i libri con ISBN cercando copertine e descrizioni mancanti") ?></p>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Books with ISBN -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-gradient-to-br from-blue-100 to-blue-200 rounded-xl flex items-center justify-center">
                    <i class="fas fa-barcode text-blue-600 text-xl"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500"><?= __("Libri con ISBN") ?></p>
                    <p class="text-2xl font-bold text-gray-900"><?= htmlspecialchars((string) $stats['total_with_isbn'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        </div>

        <!-- Missing Cover -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-gradient-to-br from-amber-100 to-amber-200 rounded-xl flex items-center justify-center">
                    <i class="fas fa-image text-amber-600 text-xl"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500"><?= __("Senza copertina") ?></p>
                    <p class="text-2xl font-bold text-gray-900"><?= htmlspecialchars((string) $stats['missing_cover'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        </div>

        <!-- Missing Description -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-gradient-to-br from-purple-100 to-purple-200 rounded-xl flex items-center justify-center">
                    <i class="fas fa-align-left text-purple-600 text-xl"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500"><?= __("Senza descrizione") ?></p>
                    <p class="text-2xl font-bold text-gray-900"><?= htmlspecialchars((string) $stats['missing_description'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        </div>

        <!-- Pending -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-gradient-to-br from-red-100 to-red-200 rounded-xl flex items-center justify-center">
                    <i class="fas fa-clock text-red-600 text-xl"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500"><?= __("In attesa") ?></p>
                    <p class="text-2xl font-bold text-gray-900"><?= htmlspecialchars((string) $stats['pending'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Controls -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
        <!-- Automatic Enrichment Toggle -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4"><?= __("Arricchimento Automatico") ?></h2>
            <p class="text-sm text-gray-600 mb-6"><?= __("Abilita l'arricchimento automatico tramite cron. I libri verranno arricchiti in background a intervalli regolari.") ?></p>
            <div class="flex items-center gap-4">
                <button type="button" id="toggle-enrichment"
                    class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 <?= $enabled ? 'bg-blue-600' : 'bg-gray-200' ?>"
                    role="switch" aria-checked="<?= $enabled ? 'true' : 'false' ?>"
                    aria-label="<?= htmlspecialchars(__('Arricchimento Automatico'), ENT_QUOTES, 'UTF-8') ?>"
                    aria-labelledby="toggle-label">
                    <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out <?= $enabled ? 'translate-x-5' : 'translate-x-0' ?>"></span>
                </button>
                <span id="toggle-label" class="text-sm font-medium <?= $enabled ? 'text-green-700' : 'text-gray-500' ?>">
                    <?= $enabled ? __("Attivo") : __("Disattivo") ?>
                </span>
            </div>
        </div>

        <!-- Manual Enrichment -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4"><?= __("Arricchimento Manuale") ?></h2>
            <p class="text-sm text-gray-600 mb-6"><?= __("Avvia manualmente l'arricchimento di un batch di 20 libri. Verranno cercate copertine e descrizioni mancanti.") ?></p>
            <button type="button" id="btn-enrich-now"
                class="inline-flex items-center px-6 py-3 bg-blue-600 text-white rounded-xl hover:bg-blue-700 transition-all duration-200 shadow-sm hover:shadow-md disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="fas fa-magic mr-2" id="enrich-icon"></i>
                <span id="enrich-text"><?= __("Arricchisci Adesso") ?></span>
            </button>
        </div>
    </div>

    <!-- Results Table -->
    <div id="results-section" class="hidden">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900"><?= __("Risultati Ultimo Batch") ?></h2>
                <p class="text-sm text-gray-500 mt-1" id="results-summary"></p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("ID Libro") ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Stato") ?></th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Campi Aggiornati") ?></th>
                        </tr>
                    </thead>
                    <tbody id="results-tbody" class="bg-white divide-y divide-gray-200">
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = <?= json_encode(Csrf::ensureToken(), JSON_HEX_TAG) ?>;
// Route URLs built server-side once — no per-fetch string concatenation of
// window.BASE_PATH + hardcoded path. If the route changes or the subfolder
// install prefix shifts, there's a single source of truth (url() helper).
const BULK_ENRICH_URLS = <?= json_encode([
    'toggle' => url('/admin/libri/bulk-enrich/toggle'),
    'start'  => url('/admin/libri/bulk-enrich/start'),
], JSON_HEX_TAG) ?>;

// Toggle automatic enrichment
document.getElementById('toggle-enrichment').addEventListener('click', async function () {
    const btn = this;
    const isCurrentlyEnabled = btn.getAttribute('aria-checked') === 'true';
    const newState = !isCurrentlyEnabled;

    try {
        const response = await fetch(BULK_ENRICH_URLS.toggle, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&enabled=' + (newState ? '1' : '0')
        });

        const data = await response.json();

        if (data.success) {
            const label = document.getElementById('toggle-label');
            const span = btn.querySelector('span');

            btn.setAttribute('aria-checked', newState ? 'true' : 'false');

            if (newState) {
                btn.classList.remove('bg-gray-200');
                btn.classList.add('bg-blue-600');
                span.classList.remove('translate-x-0');
                span.classList.add('translate-x-5');
                label.textContent = <?= json_encode(__("Attivo"), JSON_HEX_TAG) ?>;
                label.classList.remove('text-gray-500');
                label.classList.add('text-green-700');
            } else {
                btn.classList.remove('bg-blue-600');
                btn.classList.add('bg-gray-200');
                span.classList.remove('translate-x-5');
                span.classList.add('translate-x-0');
                label.textContent = <?= json_encode(__("Disattivo"), JSON_HEX_TAG) ?>;
                label.classList.remove('text-green-700');
                label.classList.add('text-gray-500');
            }
        } else {
            alert(data.error || <?= json_encode(__("Errore durante il salvataggio"), JSON_HEX_TAG) ?>);
        }
    } catch (err) {
        alert(<?= json_encode(__("Errore di rete"), JSON_HEX_TAG) ?>);
    }
});

// Manual enrichment
document.getElementById('btn-enrich-now').addEventListener('click', async function () {
    const btn = this;
    const icon = document.getElementById('enrich-icon');
    const text = document.getElementById('enrich-text');

    btn.disabled = true;
    icon.classList.remove('fa-magic');
    icon.classList.add('fa-spinner', 'fa-spin');
    text.textContent = <?= json_encode(__("Elaborazione in corso..."), JSON_HEX_TAG) ?>;

    try {
        const response = await fetch(BULK_ENRICH_URLS.start, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'csrf_token=' + encodeURIComponent(csrfToken)
        });

        const data = await response.json();

        if (data.success && data.results) {
            showResults(data.results);
        } else {
            alert(data.error || <?= json_encode(__("Errore durante l'arricchimento"), JSON_HEX_TAG) ?>);
        }
    } catch (err) {
        alert(<?= json_encode(__("Errore di rete"), JSON_HEX_TAG) ?>);
    } finally {
        btn.disabled = false;
        icon.classList.remove('fa-spinner', 'fa-spin');
        icon.classList.add('fa-magic');
        text.textContent = <?= json_encode(__("Arricchisci Adesso"), JSON_HEX_TAG) ?>;
    }
});

function showResults(results) {
    const section = document.getElementById('results-section');
    const summary = document.getElementById('results-summary');
    const tbody = document.getElementById('results-tbody');

    summary.textContent = <?= json_encode(__("Elaborati"), JSON_HEX_TAG) ?> + ': ' + results.processed +
        ' | ' + <?= json_encode(__("Arricchiti"), JSON_HEX_TAG) ?> + ': ' + results.enriched +
        ' | ' + <?= json_encode(__("Non trovati"), JSON_HEX_TAG) ?> + ': ' + results.not_found +
        ' | ' + <?= json_encode(__("Errori"), JSON_HEX_TAG) ?> + ': ' + results.errors;

    // Clear existing rows safely
    while (tbody.firstChild) {
        tbody.removeChild(tbody.firstChild);
    }

    if (results.details && results.details.length > 0) {
        for (const item of results.details) {
            const tr = document.createElement('tr');

            const tdId = document.createElement('td');
            tdId.className = 'px-6 py-4 whitespace-nowrap text-sm text-gray-900';
            tdId.textContent = String(item.book_id);

            const tdStatus = document.createElement('td');
            tdStatus.className = 'px-6 py-4 whitespace-nowrap text-sm';
            const badge = document.createElement('span');
            badge.className = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium';
            if (item.status === 'enriched') {
                badge.classList.add('bg-green-100', 'text-green-800');
                badge.textContent = <?= json_encode(__("Arricchito"), JSON_HEX_TAG) ?>;
            } else if (item.status === 'not_found') {
                badge.classList.add('bg-yellow-100', 'text-yellow-800');
                badge.textContent = <?= json_encode(__("Non trovato"), JSON_HEX_TAG) ?>;
            } else if (item.status === 'skipped') {
                badge.classList.add('bg-gray-100', 'text-gray-800');
                badge.textContent = <?= json_encode(__("Saltato"), JSON_HEX_TAG) ?>;
            } else {
                badge.classList.add('bg-red-100', 'text-red-800');
                badge.textContent = <?= json_encode(__("Errore"), JSON_HEX_TAG) ?>;
            }
            tdStatus.appendChild(badge);

            const tdFields = document.createElement('td');
            tdFields.className = 'px-6 py-4 whitespace-nowrap text-sm text-gray-500';
            tdFields.textContent = item.fields_updated && item.fields_updated.length > 0
                ? item.fields_updated.join(', ')
                : '-';

            tr.appendChild(tdId);
            tr.appendChild(tdStatus);
            tr.appendChild(tdFields);
            tbody.appendChild(tr);
        }
    } else {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 3;
        td.className = 'px-6 py-8 text-center text-sm text-gray-500';
        td.textContent = <?= json_encode(__("Nessun libro da arricchire"), JSON_HEX_TAG) ?>;
        tr.appendChild(td);
        tbody.appendChild(tr);
    }

    section.classList.remove('hidden');
}
</script>

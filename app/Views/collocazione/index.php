<link href="/assets/css/sortable.min.css" rel="stylesheet">

<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6">

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li>
          <a href="/admin/dashboard" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i><?= __("Home") ?>
          </a>
        </li>
        <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
        <li class="text-gray-900 font-medium">
          <i class="fas fa-warehouse mr-1"></i><?= __("Collocazione") ?>
        </li>
      </ol>
    </nav>

    <!-- Header -->
    <div class="mb-6 fade-in">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-3xl font-bold text-gray-900 flex items-center">
            <i class="fas fa-warehouse text-gray-600 mr-3"></i>
            <?= __("Gestione Collocazione") ?>
          </h1>
          <p class="text-sm text-gray-600 mt-1"><?= __("Organizza scaffali, mensole e posizioni per la biblioteca fisica") ?></p>
        </div>
      </div>
    </div>

    <!-- Alerts -->
    <?php if (!empty($_SESSION['success_message'])): ?>
      <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg flex items-start" role="alert">
        <i class="fas fa-check-circle text-green-600 mt-0.5 mr-3"></i>
        <div class="flex-1">
          <p class="text-green-800 font-medium"><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></p>
        </div>
        <button onclick="this.parentElement.remove()" class="text-green-600 hover:text-green-800">
          <i class="fas fa-times"></i>
        </button>
      </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['error_message'])): ?>
      <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg flex items-start" role="alert">
        <i class="fas fa-exclamation-circle text-red-600 mt-0.5 mr-3"></i>
        <div class="flex-1">
          <p class="text-red-800 font-medium"><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></p>
        </div>
        <button onclick="this.parentElement.remove()" class="text-red-600 hover:text-red-800">
          <i class="fas fa-times"></i>
        </button>
      </div>
    <?php endif; ?>

    <!-- Info & Stats Section -->
    <div class="mb-6 space-y-4">

      <!-- What is Collocazione - Full Width -->
      <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div>
            <h3 class="text-lg font-semibold text-gray-900 mb-3 flex items-center">
              <i class="fas fa-question-circle text-gray-600 mr-2"></i>
              <?= __("Cos'è la Collocazione?") ?>
            </h3>
            <p class="text-sm text-gray-700 mb-3">
              <?= __("La collocazione è l'indirizzo fisico che identifica dove si trova un libro nella biblioteca.") ?>
            </p>
            <div class="space-y-2 text-sm text-gray-600">
              <div class="flex items-start">
                <i class="fas fa-bookmark text-gray-400 mr-2 mt-0.5"></i>
                <span><?= __("Esempio:") ?> <code class="bg-gray-100 px-2 py-0.5 rounded">A.2.15</code></span>
              </div>
              <div class="ml-6 text-xs space-y-1">
                <div>• <strong>A</strong> = <?= __("Scaffale") ?> A</div>
                <div>• <strong>2</strong> = <?= __("Mensola") ?> 2</div>
                <div>• <strong>15</strong> = <?= __("Posizione") ?> 15</div>
              </div>
            </div>
          </div>

          <div>
            <h3 class="text-lg font-semibold text-gray-900 mb-3 flex items-center">
              <i class="fas fa-cogs text-gray-600 mr-2"></i>
              <?= __("Come Funziona") ?>
            </h3>
            <ol class="space-y-2 text-sm text-gray-700">
              <li class="flex items-start">
                <span class="flex-shrink-0 w-6 h-6 bg-gray-200 rounded-full flex items-center justify-center text-xs font-semibold mr-2">1</span>
                <span><?= __("Crea gli scaffali (es: A, B, C)") ?></span>
              </li>
              <li class="flex items-start">
                <span class="flex-shrink-0 w-6 h-6 bg-gray-200 rounded-full flex items-center justify-center text-xs font-semibold mr-2">2</span>
                <span><?= __("Aggiungi le mensole (livelli) a ogni scaffale") ?></span>
              </li>
              <li class="flex items-start">
                <span class="flex-shrink-0 w-6 h-6 bg-gray-200 rounded-full flex items-center justify-center text-xs font-semibold mr-2">3</span>
                <span><?= __("La posizione viene assegnata automaticamente") ?></span>
              </li>
            </ol>
          </div>

          <div>
            <h3 class="text-lg font-semibold text-gray-900 mb-3 flex items-center">
              <i class="fas fa-lightbulb text-gray-600 mr-2"></i>
              <?= __("Suggerimenti") ?>
            </h3>
            <ul class="space-y-2 text-sm text-gray-700">
              <li class="flex items-start">
                <i class="fas fa-check text-green-600 mr-2 mt-0.5"></i>
                <span><?= __("Usa codici semplici (A, B, C...)") ?></span>
              </li>
              <li class="flex items-start">
                <i class="fas fa-check text-green-600 mr-2 mt-0.5"></i>
                <span><?= __("Riordina trascinando gli elementi") ?></span>
              </li>
              <li class="flex items-start">
                <i class="fas fa-check text-green-600 mr-2 mt-0.5"></i>
                <span><?= __("Le posizioni si generano automaticamente") ?></span>
              </li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Stats Bar -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-600"><?= __("Scaffali") ?></p>
              <p class="text-2xl font-bold text-gray-900"><?php echo count($scaffali); ?></p>
            </div>
            <i class="fas fa-layer-group text-3xl text-gray-300"></i>
          </div>
        </div>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-600"><?= __("Mensole") ?></p>
              <p class="text-2xl font-bold text-gray-900"><?php echo count($mensole); ?></p>
            </div>
            <i class="fas fa-grip-lines text-3xl text-gray-300"></i>
          </div>
        </div>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-600"><?= __("Posizioni usate") ?></p>
              <p class="text-2xl font-bold text-gray-900"><?php echo count($posizioni); ?></p>
            </div>
            <i class="fas fa-bookmark text-3xl text-gray-300"></i>
          </div>
        </div>
      </div>

    </div>

    <!-- Main Content -->
    <div class="space-y-6">

        <!-- Scaffali -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
          <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-900 flex items-center">
              <i class="fas fa-layer-group text-gray-600 mr-2"></i>
              <?= __("Scaffali") ?>
            </h2>
            <p class="text-sm text-gray-600 mt-1"><?= __("I contenitori fisici principali dove sono organizzati i libri") ?></p>
          </div>
          <div class="p-6">

            <!-- Add Form -->
            <form method="post" action="/admin/collocazione/scaffali" class="mb-6">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
              <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                  <label class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Codice *") ?></label>
                  <input name="codice" maxlength="20" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 w-full focus:outline-none focus:ring-2 focus:ring-gray-400" placeholder="<?= __('A') ?>" required aria-required="true">
                </div>
                <div class="md:col-span-2">
                  <label class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Nome") ?></label>
                  <input name="nome" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 w-full focus:outline-none focus:ring-2 focus:ring-gray-400" placeholder="<?= __('Scaffale Narrativa') ?>">
                </div>
                <div class="flex items-end">
                  <button type="submit" class="px-6 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors w-full">
                    <i class="fas fa-plus mr-2"></i>
                    <?= __("Aggiungi") ?>
                  </button>
                </div>
              </div>
            </form>

            <!-- Sortable List -->
            <div class="border border-gray-200 rounded-lg">
              <ul id="list-scaffali" class="divide-y divide-gray-200">
                <?php if (empty($scaffali)): ?>
                  <li class="p-4 text-center text-gray-500 text-sm">
                    <i class="fas fa-inbox mb-2 text-2xl"></i>
                    <p><?= __("Nessuno scaffale. Creane uno per iniziare!") ?></p>
                  </li>
                <?php else: ?>
                  <?php foreach ($scaffali as $s): ?>
                    <li class="p-4 hover:bg-gray-50 transition-colors cursor-move" data-id="<?php echo (int)$s['id']; ?>">
                      <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                          <i class="fas fa-grip-vertical text-gray-400"></i>
                          <div>
                            <span class="inline-block px-2 py-1 bg-gray-700 text-white text-xs font-mono rounded"><?php echo htmlspecialchars($s['codice'] ?? ''); ?></span>
                            <span class="text-gray-900 font-medium ml-2"><?php echo htmlspecialchars($s['nome'] ?? ''); ?></span>
                          </div>
                        </div>
                        <div class="flex items-center gap-2">
                          <span class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded"><?= __("Ordine:") ?> <span class="order-label"><?php echo isset($s['ordine']) ? (int)$s['ordine'] : 0; ?></span></span>
                          <form method="post" action="/admin/collocazione/scaffali/<?php echo (int)$s['id']; ?>/delete" class="inline" onsubmit="return confirm('<?= __("Eliminare questo scaffale? (Solo se vuoto)") ?>');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="text-red-600 hover:text-red-800 text-sm" title="<?= __("Elimina") ?>"><i class="fas fa-trash"></i></button>
                          </form>
                        </div>
                      </div>
                    </li>
                  <?php endforeach; ?>
                <?php endif; ?>
              </ul>
            </div>

            <p class="text-xs text-gray-500 mt-3">
              <i class="fas fa-hand-pointer mr-1"></i>
              <?= __("Trascina per riordinare • Il codice deve essere univoco") ?>
            </p>

          </div>
        </div>

        <!-- Mensole -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
          <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-900 flex items-center">
              <i class="fas fa-grip-lines text-gray-600 mr-2"></i>
              <?= __("Mensole") ?>
            </h2>
            <p class="text-sm text-gray-600 mt-1"><?= __("I livelli (ripiani) all'interno di ogni scaffale") ?></p>
          </div>
          <div class="p-6">

            <!-- Add Form -->
            <form method="post" action="/admin/collocazione/mensole" class="mb-6">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
              <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="md:col-span-2">
                  <label class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Scaffale *") ?></label>
                  <select name="scaffale_id" id="add-mensola-scaffale" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 w-full focus:outline-none focus:ring-2 focus:ring-gray-400" required aria-required="true">
                    <option value=""><?= __("Seleziona...") ?></option>
                    <?php foreach ($scaffali as $s): ?>
                      <option value="<?php echo (int)$s['id']; ?>">
                        <?php echo htmlspecialchars('['.((string)($s['codice'] ?? '')).'] '.((string)($s['nome'] ?? ''))); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Livello *") ?></label>
                  <input name="numero_livello" type="number" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 w-full focus:outline-none focus:ring-2 focus:ring-gray-400" value="1" min="1">
                </div>
                <div class="flex items-end">
                  <button type="submit" class="px-6 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors w-full">
                    <i class="fas fa-plus mr-2"></i>
                    <?= __("Aggiungi") ?>
                  </button>
                </div>
              </div>
            </form>

            <!-- Scaffale Filter for Mensole List -->
            <div class="mb-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
              <label class="text-sm font-medium text-gray-700 mb-2 block">
                <i class="fas fa-filter mr-1"></i>
                <?= __("Filtra mensole per scaffale") ?>
              </label>
              <select id="filter-mensole-scaffale" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 w-full md:w-auto md:min-w-[300px] focus:outline-none focus:ring-2 focus:ring-gray-400">
                <option value=""><?= __("Seleziona uno scaffale per vedere le sue mensole...") ?></option>
                <?php foreach ($scaffali as $s): ?>
                  <option value="<?php echo (int)$s['id']; ?>" data-codice="<?php echo htmlspecialchars((string)($s['codice'] ?? '')); ?>">
                    <?php echo htmlspecialchars('['.((string)($s['codice'] ?? '')).'] '.((string)($s['nome'] ?? ''))); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="text-xs text-gray-500 mt-2">
                <i class="fas fa-info-circle mr-1"></i>
                <?= __("Seleziona uno scaffale per visualizzare e riordinare le sue mensole") ?>
              </p>
            </div>

            <!-- Sortable List -->
            <div class="border border-gray-200 rounded-lg">
              <ul id="list-mensole" class="divide-y divide-gray-200">
                <li class="p-4 text-center text-gray-500 text-sm" id="mensole-placeholder">
                  <i class="fas fa-layer-group mb-2 text-2xl"></i>
                  <p><?= __("Seleziona uno scaffale dal filtro sopra per gestire le sue mensole") ?></p>
                </li>
                <?php foreach ($mensole as $m): ?>
                  <li class="p-4 hover:bg-gray-50 transition-colors cursor-move mensola-item hidden"
                      data-id="<?php echo (int)$m['id']; ?>"
                      data-scaffale-id="<?php echo (int)($m['scaffale_id'] ?? 0); ?>">
                    <div class="flex items-center justify-between">
                      <div class="flex items-center gap-3">
                        <i class="fas fa-grip-vertical text-gray-400"></i>
                        <div>
                          <span class="inline-block px-2 py-1 bg-gray-700 text-white text-xs font-mono rounded"><?php echo htmlspecialchars((string)($m['scaffale_codice'] ?? '')); ?></span>
                          <span class="text-gray-700 ml-2"><?= __("Livello") ?> <?php echo (int)$m['numero_livello']; ?></span>
                        </div>
                      </div>
                      <div class="flex items-center gap-2">
                        <span class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded mensola-order-label"><?= __("Ordine:") ?> <span class="order-value"><?php echo (int)($m['ordine'] ?? 0); ?></span></span>
                        <form method="post" action="/admin/collocazione/mensole/<?php echo (int)$m['id']; ?>/delete" class="inline" onsubmit="return confirm('<?= __("Eliminare questa mensola? (Solo se vuota)") ?>');">
                          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
                          <button type="submit" class="text-red-600 hover:text-red-800 text-sm" title="<?= __("Elimina") ?>"><i class="fas fa-trash"></i></button>
                        </form>
                      </div>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>

            <p class="text-xs text-gray-500 mt-3">
              <i class="fas fa-hand-pointer mr-1"></i>
              <?= __("Trascina per riordinare • Ogni scaffale + livello deve essere univoco") ?>
            </p>

          </div>
        </div>

        <!-- Libri per Collocazione -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
          <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div>
                <h2 class="text-xl font-semibold text-gray-900 flex items-center">
                  <i class="fas fa-bookmark text-gray-600 mr-2"></i>
                  <?= __("Libri per Collocazione") ?>
                </h2>
                <p class="text-sm text-gray-600 mt-1"><?= __("Visualizza e esporta l'elenco dei libri per posizione fisica") ?></p>
              </div>
              <button onclick="exportCollocationCSV()" class="w-full md:w-auto px-4 py-2 bg-gray-100 text-gray-900 hover:bg-gray-200 rounded-lg transition-colors inline-flex items-center justify-center border border-gray-300">
                <i class="fas fa-file-csv mr-2"></i>
                <?= __("Esporta CSV") ?>
              </button>
            </div>
          </div>
          <div class="p-6">
            <!-- Filtri -->
            <div class="mb-4 grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Filtra per Scaffale") ?></label>
                <select id="filter-scaffale" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 w-full focus:outline-none focus:ring-2 focus:ring-gray-400">
                  <option value=""><?= __("Tutti gli scaffali") ?></option>
                  <?php foreach ($scaffali as $s): ?>
                    <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars((string)$s['codice']); ?> - <?php echo htmlspecialchars((string)$s['nome']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Filtra per Mensola") ?></label>
                <select id="filter-mensola" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 w-full focus:outline-none focus:ring-2 focus:ring-gray-400">
                  <option value=""><?= __("Tutte le mensole") ?></option>
                  <?php foreach ($mensole as $m): ?>
                    <option value="<?php echo (int)$m['id']; ?>"><?php echo htmlspecialchars((string)($m['scaffale_codice'] ?? '')); ?> - <?= __("Livello") ?> <?php echo (int)$m['numero_livello']; ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <!-- Tabella -->
            <div class="border border-gray-200 rounded-lg overflow-hidden">
              <div class="max-h-96 overflow-y-auto">
                <table class="w-full text-sm" id="collocation-table">
                  <thead class="bg-gray-50 border-b border-gray-200 sticky top-0">
                    <tr>
                      <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __('Collocazione') ?></th>
                      <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __('Titolo') ?></th>
                      <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __('Autori') ?></th>
                      <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __('Editore') ?></th>
                      <th class="px-4 py-2 text-center font-semibold text-gray-700"><?= __('Azioni') ?></th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-100" id="collocation-tbody">
                    <tr>
                      <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                        <i class="fas fa-spinner fa-spin mr-2"></i><?= __("Caricamento...") ?>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <p class="text-xs text-gray-500 mt-3">
              <i class="fas fa-info-circle mr-1"></i>
              <?= __("La collocazione può essere assegnata automaticamente o inserita manualmente durante la creazione/modifica del libro") ?>
            </p>
          </div>
        </div>

    </div>
  </div>
</div>

<script src="/assets/js/sortable.min.js"></script>
<script>
// Global __ function for JavaScript inline handlers (onsubmit, onclick, etc.)
if (typeof window.__ === 'undefined') {
  window.__ = function(key) {
    return key; // Return key as-is if translation not available
  };
}

document.addEventListener('DOMContentLoaded', function() {

  // Update order labels after sorting
  function updateOrderLabels(listEl) {
    if (!listEl) return;
    const items = Array.from(listEl.querySelectorAll('li[data-id]'));
    items.forEach((li, idx) => {
      const orderLabel = li.querySelector('.order-label');
      if (orderLabel) {
        orderLabel.textContent = (idx + 1);
      }
    });
  }

  // Send new order to server
  async function sendOrder(type, ids, scaffaleId = null) {
    try {
      const payload = {
        type,
        ids,
        csrf_token: '<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>'
      };
      // Include scaffale_id for mensole sorting (to ensure we only sort within that scaffale)
      if (scaffaleId) {
        payload.scaffale_id = scaffaleId;
      }
      const response = await fetch('/api/collocazione/sort', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      if (!response.ok) {
        throw new Error('Server returned ' + response.status);
      }
    } catch (error) {
      console.error('Error updating order:', error);
      alert('<?= __("Errore nel salvataggio dell'ordine. Ricarica la pagina e riprova.") ?>');
    }
  }

  // Initialize Sortable for Scaffali
  const scaffaliList = document.getElementById('list-scaffali');
  if (scaffaliList && window.Sortable) {
    new Sortable(scaffaliList, {
      animation: 150,
      handle: '.fa-grip-vertical',
      ghostClass: 'bg-gray-100',
      onEnd: function() {
        const ids = Array.from(scaffaliList.querySelectorAll('li[data-id]')).map(li => parseInt(li.dataset.id));
        sendOrder('scaffali', ids);
        updateOrderLabels(scaffaliList);
      }
    });
  }

  // Initialize Sortable for Mensole (after filtering)
  const mensoleList = document.getElementById('list-mensole');
  let mensoleSortable = null;
  let currentMensoleScaffaleId = null;

  function initMensoleSortable() {
    if (mensoleSortable) {
      mensoleSortable.destroy();
      mensoleSortable = null;
    }
    if (mensoleList && window.Sortable && currentMensoleScaffaleId) {
      mensoleSortable = new Sortable(mensoleList, {
        animation: 150,
        handle: '.fa-grip-vertical',
        ghostClass: 'bg-gray-100',
        filter: '#mensole-placeholder, .hidden',
        onEnd: function() {
          // Only get visible mensole IDs (those belonging to selected scaffale)
          const ids = Array.from(mensoleList.querySelectorAll('li.mensola-item:not(.hidden)')).map(li => parseInt(li.dataset.id));
          sendOrder('mensole', ids, currentMensoleScaffaleId);
          updateMensoleOrderLabels();
        }
      });
    }
  }

  function updateMensoleOrderLabels() {
    const visibleMensole = Array.from(mensoleList.querySelectorAll('li.mensola-item:not(.hidden)'));
    visibleMensole.forEach((li, idx) => {
      const orderLabel = li.querySelector('.order-value');
      if (orderLabel) {
        orderLabel.textContent = (idx + 1);
      }
    });
  }

  function filterMensoleByScaffale(scaffaleId) {
    currentMensoleScaffaleId = scaffaleId ? parseInt(scaffaleId) : null;
    const placeholder = document.getElementById('mensole-placeholder');
    const allMensole = mensoleList.querySelectorAll('li.mensola-item');

    if (!scaffaleId) {
      // Show placeholder, hide all mensole
      if (placeholder) placeholder.classList.remove('hidden');
      allMensole.forEach(li => li.classList.add('hidden'));
      if (mensoleSortable) {
        mensoleSortable.destroy();
        mensoleSortable = null;
      }
      return;
    }

    // Hide placeholder
    if (placeholder) placeholder.classList.add('hidden');

    // Show only mensole for selected scaffale
    let hasVisible = false;
    allMensole.forEach(li => {
      const liScaffaleId = parseInt(li.dataset.scaffaleId);
      if (liScaffaleId === currentMensoleScaffaleId) {
        li.classList.remove('hidden');
        hasVisible = true;
      } else {
        li.classList.add('hidden');
      }
    });

    // If no mensole for this scaffale, show a message
    if (!hasVisible) {
      if (placeholder) {
        placeholder.innerHTML = '<i class="fas fa-inbox mb-2 text-2xl"></i><p>' + __('Nessuna mensola per questo scaffale. Creane una!') + '</p>';
        placeholder.classList.remove('hidden');
      }
    }

    // Re-initialize sortable
    initMensoleSortable();
    updateMensoleOrderLabels();
  }

  // Handle mensole filter change
  const filterMensoleScaffale = document.getElementById('filter-mensole-scaffale');
  if (filterMensoleScaffale) {
    filterMensoleScaffale.addEventListener('change', function() {
      filterMensoleByScaffale(this.value);
    });
  }

  // Sync add form scaffale selection with filter
  const addMensolaScaffale = document.getElementById('add-mensola-scaffale');
  if (addMensolaScaffale && filterMensoleScaffale) {
    addMensolaScaffale.addEventListener('change', function() {
      if (this.value) {
        filterMensoleScaffale.value = this.value;
        filterMensoleByScaffale(this.value);
      }
    });
  }

  // Load collocation books
  let allBooks = [];

  async function loadCollocationBooks() {
    try {
      const response = await fetch('/api/collocazione/libri');
      if (!response.ok) {
        throw new Error('Network response was not ok');
      }
      const data = await response.json();
      allBooks = data.libri || [];
      renderBooks(allBooks);
    } catch (error) {
      console.error('Error loading books:', error);
      // Se non ci sono libri, mostra messaggio neutro invece di errore
      allBooks = [];
      renderBooks(allBooks);
    }
  }

  // SECURITY: Escape HTML to prevent XSS
  function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  function renderBooks(books) {
    const tbody = document.getElementById('collocation-tbody');

    if (books.length === 0) {
      tbody.innerHTML = '<tr><td colspan="5" class="px-4 py-8 text-center text-gray-500"><i class="fas fa-inbox mr-2"></i>' + __('Nessun libro con collocazione trovato') + '</td></tr>';
      return;
    }

    tbody.innerHTML = books.map(book => `
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-3">
          <code class="bg-gray-100 px-2 py-1 rounded text-xs font-mono">${escapeHtml(book.collocazione) || '-'}</code>
        </td>
        <td class="px-4 py-3 text-gray-900 font-medium">${escapeHtml(book.titolo)}</td>
        <td class="px-4 py-3 text-gray-700 text-xs">${escapeHtml(book.autori) || '-'}</td>
        <td class="px-4 py-3 text-gray-600 text-xs">${escapeHtml(book.editore) || '-'}</td>
        <td class="px-4 py-3 text-center">
          <a href="/admin/libri/modifica/${parseInt(book.id, 10)}" class="text-gray-600 hover:text-gray-900" title="<?= __("Modifica") ?>">
            <i class="fas fa-edit"></i>
          </a>
        </td>
      </tr>
    `).join('');
  }

  function filterBooks() {
    const scaffaleId = document.getElementById('filter-scaffale').value;
    const mensolaId = document.getElementById('filter-mensola').value;

    let filtered = allBooks;

    if (scaffaleId) {
      filtered = filtered.filter(b => b.scaffale_id == scaffaleId);
    }

    if (mensolaId) {
      filtered = filtered.filter(b => b.mensola_id == mensolaId);
    }

    renderBooks(filtered);
  }

  document.getElementById('filter-scaffale')?.addEventListener('change', filterBooks);
  document.getElementById('filter-mensola')?.addEventListener('change', filterBooks);

  loadCollocationBooks();
});

// Export CSV function (global scope)
function exportCollocationCSV() {
  const scaffaleId = document.getElementById('filter-scaffale').value;
  const mensolaId = document.getElementById('filter-mensola').value;

  let url = '/api/collocazione/export-csv';
  const params = new URLSearchParams();

  if (scaffaleId) params.append('scaffale_id', scaffaleId);
  if (mensolaId) params.append('mensola_id', mensolaId);

  if (params.toString()) {
    url += '?' + params.toString();
  }

  window.location.href = url;
}
</script>

<style>
.fade-in {
  animation: fadeIn 0.3s ease-in;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-10px); }
  to { opacity: 1; transform: translateY(0); }
}

.sortable-ghost {
  opacity: 0.4;
}
</style>
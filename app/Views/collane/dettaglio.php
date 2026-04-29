<?php
/** @var string $collana */
/** @var array $books */
/** @var bool $hasParentWork */
/** @var bool $supportsHierarchy */
/** @var string $seriesGroup */
/** @var string $seriesCycle */
/** @var int|null $cycleOrder */
/** @var string $seriesParent */
/** @var string $seriesType */
/** @var array $relatedCollane */
use App\Support\HtmlHelper;
use App\Support\Csrf;
$csrfToken = Csrf::ensureToken();
$seriesTypeOptions = [
    'serie' => __('Serie'),
    'universo' => __('Universo / macroserie'),
    'ciclo' => __('Ciclo'),
    'stagione' => __('Stagione'),
    'spin_off' => __('Spin-off'),
    'arco' => __('Arco narrativo'),
    'collezione_editoriale' => __('Collana editoriale'),
    'altro' => __('Altro'),
];
if ($seriesType === '') { $seriesType = 'serie'; }
?>

<section class="py-8 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

  <!-- Breadcrumb -->
  <nav aria-label="breadcrumb" class="mb-4">
    <ol class="flex items-center space-x-2 text-sm">
      <li><a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors"><i class="fas fa-home mr-1"></i>Home</a></li>
      <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
      <li><a href="<?= htmlspecialchars(url('/admin/collane'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors"><?= __("Collane") ?></a></li>
      <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
      <li class="text-gray-900 font-medium"><?= HtmlHelper::e($collana) ?></li>
    </ol>
  </nav>

  <!-- Header -->
  <div class="flex items-center justify-between">
    <h1 class="text-2xl font-bold text-gray-900">
      <i class="fas fa-layer-group text-gray-600 mr-2"></i><?= HtmlHelper::e($collana) ?>
      <span class="text-base font-normal text-gray-500 ml-2">(<?= count($books) ?> <?= __("libri") ?>)</span>
    </h1>
  </div>

  <!-- Messages -->
  <?php if (!empty($_SESSION['success_message'])): ?>
  <div class="p-4 bg-green-100 text-green-800 rounded">
    <i class="fas fa-check-circle mr-1"></i> <?= HtmlHelper::e($_SESSION['success_message']) ?>
  </div>
  <?php unset($_SESSION['success_message']); endif; ?>

  <?php if (!empty($_SESSION['error_message'])): ?>
  <div class="p-4 bg-red-100 text-red-800 rounded">
    <i class="fas fa-exclamation-circle mr-1"></i> <?= HtmlHelper::e($_SESSION['error_message']) ?>
  </div>
  <?php unset($_SESSION['error_message']); endif; ?>

  <!-- Description and series hierarchy -->
  <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow">
    <h3 class="text-sm font-medium text-gray-700 mb-3"><i class="fas fa-sitemap text-gray-400 mr-1"></i> <?= __("Metadati serie") ?></h3>
    <form method="post" action="<?= htmlspecialchars(url('/admin/collane/descrizione'), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="nome" value="<?= HtmlHelper::e($collana) ?>">
      <?php if (!empty($supportsHierarchy)): ?>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
        <div>
          <label for="gruppo_serie" class="form-label"><?= __("Gruppo serie") ?></label>
          <input id="gruppo_serie" name="gruppo_serie" type="text" class="form-input" placeholder="<?= HtmlHelper::e(__('es. Fairy Tail')) ?>" value="<?= HtmlHelper::e($seriesGroup) ?>">
        </div>
        <div>
          <label for="serie_padre" class="form-label"><?= __("Serie padre / universo") ?></label>
          <input id="serie_padre" name="serie_padre" type="text" class="form-input" placeholder="<?= HtmlHelper::e(__('es. I mondi di Aldebaran')) ?>" value="<?= HtmlHelper::e($seriesParent) ?>">
        </div>
        <div>
          <label for="tipo_collana" class="form-label"><?= __("Tipo serie") ?></label>
          <select id="tipo_collana" name="tipo_collana" class="form-input">
            <?php foreach ($seriesTypeOptions as $typeValue => $typeLabel): ?>
              <option value="<?= HtmlHelper::e($typeValue) ?>" <?= $seriesType === $typeValue ? 'selected' : '' ?>><?= HtmlHelper::e($typeLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
        <div>
          <label for="ciclo" class="form-label"><?= __("Ciclo / stagione") ?></label>
          <input id="ciclo" name="ciclo" type="text" class="form-input" placeholder="<?= HtmlHelper::e(__('es. Ciclo 1 - Aldebaran')) ?>" value="<?= HtmlHelper::e($seriesCycle) ?>">
        </div>
        <div>
          <label for="ordine_ciclo" class="form-label"><?= __("Ordine ciclo") ?></label>
          <input id="ordine_ciclo" name="ordine_ciclo" type="number" min="1" class="form-input" placeholder="1" value="<?= HtmlHelper::e((string) ($cycleOrder ?? '')) ?>">
        </div>
      </div>
      <?php endif; ?>
      <label for="descrizione" class="form-label"><?= __("Descrizione") ?></label>
      <textarea name="descrizione" rows="3" class="mt-1 block w-full rounded-lg border border-gray-300 px-4 py-2 mb-3" placeholder="<?= HtmlHelper::e(__('Descrizione della collana...')) ?>"><?= HtmlHelper::e($collanaDesc ?? '') ?></textarea>
      <button type="submit" class="px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-900 transition-colors font-medium text-sm">
        <i class="fas fa-save mr-2"></i><?= __("Salva descrizione") ?>
      </button>
    </form>
  </div>

  <?php if (!empty($supportsHierarchy) && !empty($relatedCollane)): ?>
  <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="px-4 py-3 bg-gray-50 border-b border-gray-200">
      <h3 class="text-sm font-medium text-gray-700"><i class="fas fa-project-diagram text-gray-400 mr-1"></i> <?= __("Altre serie nello stesso gruppo") ?></h3>
    </div>
    <div class="divide-y divide-gray-200">
      <?php foreach ($relatedCollane as $related): ?>
      <a class="flex items-center justify-between px-4 py-3 hover:bg-gray-50" href="<?= htmlspecialchars(url('/admin/collane/dettaglio?nome=' . urlencode($related['nome'])), ENT_QUOTES, 'UTF-8') ?>">
        <span class="font-medium text-gray-900"><?= HtmlHelper::e($related['nome']) ?></span>
        <span class="text-sm text-gray-500">
          <?php if (!empty($related['ciclo'])): ?>
            <?= HtmlHelper::e($related['ciclo']) ?>
          <?php endif; ?>
          <?php if (!empty($related['ordine_ciclo'])): ?>
            <span class="ml-1">#<?= (int) $related['ordine_ciclo'] ?></span>
          <?php endif; ?>
          <span class="ml-2">(<?= (int) $related['book_count'] ?> <?= __("libri") ?>)</span>
        </span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Books Table -->
  <?php if (!empty($books)): ?>
  <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
      <thead class="bg-gray-50">
        <tr>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-16">#</th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= __("Titolo") ?></th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= __("Autore") ?></th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ISBN</th>
          <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase"><?= __("Azioni") ?></th>
        </tr>
      </thead>
      <tbody class="bg-white divide-y divide-gray-200">
        <?php foreach ($books as $b): ?>
        <tr class="hover:bg-gray-50">
          <td class="px-4 py-3 text-sm font-medium text-gray-900"><?= HtmlHelper::e($b['numero_serie'] ?? '') ?></td>
          <td class="px-4 py-3 text-sm">
            <a href="<?= htmlspecialchars(url('/admin/libri/' . (int)$b['id']), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-900 hover:text-gray-700 font-medium"><?= HtmlHelper::e($b['titolo']) ?></a>
          </td>
          <td class="px-4 py-3 text-sm text-gray-600"><?= HtmlHelper::e($b['autore'] ?? '') ?></td>
          <td class="px-4 py-3 text-sm text-gray-500"><?= HtmlHelper::e(($b['isbn13'] ?? '') ?: ($b['isbn10'] ?? '')) ?></td>
          <td class="px-4 py-3 text-right text-sm">
            <form method="post" action="<?= htmlspecialchars(url('/admin/collane/rimuovi-libro'), ENT_QUOTES, 'UTF-8') ?>" onsubmit='return confirm(<?= json_encode(__("Rimuovere questo libro dalla serie?"), JSON_HEX_TAG | JSON_HEX_APOS) ?>)'>
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="book_id" value="<?= (int) $b['id'] ?>">
              <input type="hidden" name="collana" value="<?= HtmlHelper::e($collana) ?>">
              <button type="submit" class="text-red-600 hover:text-red-800 text-xs font-medium"><?= __("Rimuovi dalla serie") ?></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Actions -->
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

    <!-- Rename -->
    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow">
      <h3 class="text-sm font-medium text-gray-700 mb-3"><i class="fas fa-pen text-gray-400 mr-1"></i> <?= __("Rinomina collana") ?></h3>
      <form method="post" action="<?= htmlspecialchars(url('/admin/collane/rinomina'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="old_name" value="<?= HtmlHelper::e($collana) ?>">
        <input type="text" name="new_name" value="<?= HtmlHelper::e($collana) ?>" class="mt-1 block w-full rounded-lg border border-gray-300 px-4 py-2 mb-3" required>
        <button type="submit" class="px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-900 transition-colors font-medium text-sm">
          <i class="fas fa-save mr-2"></i><?= __("Rinomina") ?>
        </button>
      </form>
    </div>

    <!-- Merge -->
    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow">
      <h3 class="text-sm font-medium text-gray-700 mb-3"><i class="fas fa-compress-arrows-alt text-gray-400 mr-1"></i> <?= __("Unisci con altra collana") ?></h3>
      <form method="post" action="<?= htmlspecialchars(url('/admin/collane/unisci'), ENT_QUOTES, 'UTF-8') ?>" onsubmit='return confirm(<?= json_encode(__("Sei sicuro? Tutti i libri verranno spostati nella collana di destinazione."), JSON_HEX_TAG | JSON_HEX_APOS) ?>)'>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="source" value="<?= HtmlHelper::e($collana) ?>">
        <div class="relative">
          <input type="text" name="target" id="merge-target" placeholder="<?= HtmlHelper::e(__('Nome collana di destinazione')) ?>" class="mt-1 block w-full rounded-lg border border-gray-300 px-4 py-2 mb-3" required autocomplete="off">
          <div id="merge-suggestions" class="absolute z-10 w-full bg-white border border-gray-200 rounded-lg shadow-lg hidden max-h-48 overflow-y-auto"></div>
        </div>
        <button type="submit" class="px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-900 transition-colors font-medium text-sm">
          <i class="fas fa-compress-arrows-alt mr-2"></i><?= __("Unisci") ?>
        </button>
      </form>
    </div>

    <!-- Create Parent Work -->
    <?php if (!$hasParentWork && count($books) >= 2): ?>
    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow md:col-span-2">
      <h3 class="text-sm font-medium text-gray-700 mb-1"><i class="fas fa-book-open text-gray-400 mr-1"></i> <?= __("Crea opera multi-volume") ?></h3>
      <p class="text-xs text-gray-500 mb-3"><?= __("Crea un libro padre che raccoglie tutti i volumi di questa collana.") ?></p>
      <form method="post" action="<?= htmlspecialchars(url('/admin/collane/crea-opera'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="collana" value="<?= HtmlHelper::e($collana) ?>">
        <div class="flex gap-2">
          <input type="text" name="parent_title" value="<?= HtmlHelper::e($collana) ?>" class="flex-1 rounded-lg border border-gray-300 px-4 py-2" placeholder="<?= HtmlHelper::e(__('Titolo dell\'opera completa')) ?>" required>
          <button type="submit" class="px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-900 transition-colors font-medium text-sm whitespace-nowrap">
            <i class="fas fa-plus mr-2"></i><?= __("Crea opera") ?>
          </button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <!-- Delete -->
    <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow md:col-span-2">
      <h3 class="text-sm font-medium text-red-700 mb-1"><i class="fas fa-trash text-red-400 mr-1"></i> <?= __("Elimina collana") ?></h3>
      <p class="text-xs text-gray-500 mb-3"><?= __("Rimuove la collana da tutti i libri. I libri non verranno eliminati.") ?></p>
      <form method="post" action="<?= htmlspecialchars(url('/admin/collane/elimina'), ENT_QUOTES, 'UTF-8') ?>" onsubmit='return confirm(<?= json_encode(__("Sei sicuro? La collana verrà rimossa da tutti i libri."), JSON_HEX_TAG | JSON_HEX_APOS) ?>)'>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="nome" value="<?= HtmlHelper::e($collana) ?>">
        <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-medium text-sm">
          <i class="fas fa-trash mr-2"></i><?= __("Elimina collana") ?>
        </button>
      </form>
    </div>

  </div>

</section>

<script>
(function() {
  const input = document.getElementById('merge-target');
  const suggestions = document.getElementById('merge-suggestions');
  if (!input || !suggestions) return;

  const currentCollana = <?= json_encode($collana, JSON_HEX_TAG) ?>;
  let debounce;

  input.addEventListener('input', function() {
    clearTimeout(debounce);
    const q = this.value.trim();
    if (q.length < 1) { suggestions.classList.add('hidden'); return; }
    debounce = setTimeout(async () => {
      try {
        const resp = await fetch((window.BASE_PATH || '') + '/api/collane/search?q=' + encodeURIComponent(q));
        const data = await resp.json();
        const filtered = (Array.isArray(data) ? data : []).filter(name => name !== currentCollana);
        suggestions.textContent = '';
        if (filtered.length === 0) { suggestions.classList.add('hidden'); return; }
        for (const name of filtered) {
          const div = document.createElement('div');
          div.className = 'px-4 py-2 hover:bg-gray-100 cursor-pointer text-sm';
          div.textContent = name;
          div.addEventListener('click', () => { input.value = name; suggestions.classList.add('hidden'); });
          suggestions.appendChild(div);
        }
        suggestions.classList.remove('hidden');
      } catch { suggestions.classList.add('hidden'); }
    }, 250);
  });

  document.addEventListener('click', function(e) {
    if (!input.contains(e.target) && !suggestions.contains(e.target)) {
      suggestions.classList.add('hidden');
    }
  });
})();
</script>

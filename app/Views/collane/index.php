<?php
/** @var array $collane */
/** @var bool $supportsHierarchy */
use App\Support\HtmlHelper;
use App\Support\SeriesLabels;
// i18n-2 (refactor): centralised label map; see App\Support\SeriesLabels.
$seriesTypeLabels = SeriesLabels::types();
?>

<section class="py-8 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

  <!-- Breadcrumb -->
  <nav aria-label="breadcrumb" class="mb-4">
    <ol class="flex items-center space-x-2 text-sm">
      <li><a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-500 hover:text-gray-700 transition-colors"><i class="fas fa-home mr-1"></i>Home</a></li>
      <li><i class="fas fa-chevron-right text-gray-400 text-xs"></i></li>
      <li class="text-gray-900 font-medium"><?= __("Collane") ?></li>
    </ol>
  </nav>

  <!-- Header -->
  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">
        <i class="fas fa-layer-group text-gray-600 mr-2"></i><?= __("Gestione Collane") ?>
      </h1>
      <p class="text-sm text-gray-600 mt-1"><?= __("Gestisci collane, spin-off e cicli di serie") ?></p>
    </div>
    <button type="button" onclick="createCollana()" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-900 rounded-lg transition-colors text-sm font-medium">
      <i class="fas fa-plus mr-2"></i><?= __("Nuova Collana") ?>
    </button>
  </div>

  <script>
  async function createCollana() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const { value: name } = await Swal.fire({
      title: __('Nuova Collana'),
      input: 'text',
      inputLabel: __('Nome della collana'),
      inputPlaceholder: __('es. Harry Potter'),
      showCancelButton: true,
      confirmButtonText: __('Crea'),
      cancelButtonText: __('Annulla'),
      inputValidator: (v) => { if (!v || !v.trim()) return __('Inserisci un nome'); }
    });
    if (!name) return;
    try {
      const resp = await fetch((window.BASE_PATH || '') + '/admin/collane/crea', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        body: 'csrf_token=' + encodeURIComponent(csrf) + '&nome=' + encodeURIComponent(name.trim())
      });
      if (resp.redirected) {
        window.location.href = resp.url;
      } else {
        window.location.reload();
      }
    } catch (err) {
      await Swal.fire({ icon: 'error', title: __('Errore'), text: err.message });
    }
  }
  </script>

  <!-- Messages -->
  <?php if (!empty($_SESSION['success_message'])): ?>
  <div class="mb-4 p-4 bg-green-100 text-green-800 rounded">
    <i class="fas fa-check-circle mr-1"></i> <?= HtmlHelper::e($_SESSION['success_message']) ?>
  </div>
  <?php unset($_SESSION['success_message']); endif; ?>

  <?php if (!empty($_SESSION['error_message'])): ?>
  <div class="mb-4 p-4 bg-red-100 text-red-800 rounded">
    <i class="fas fa-exclamation-circle mr-1"></i> <?= HtmlHelper::e($_SESSION['error_message']) ?>
  </div>
  <?php unset($_SESSION['error_message']); endif; ?>

  <?php if (empty($collane)): ?>
  <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-8 text-center">
    <i class="fas fa-layer-group text-gray-300 text-4xl mb-3"></i>
    <p class="text-gray-500"><?= __("Nessuna collana trovata. Aggiungi una collana a un libro per iniziare.") ?></p>
  </div>
  <?php else: ?>

  <!-- Collane List -->
  <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
      <thead class="bg-gray-50">
        <tr>
          <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= __("Collana") ?></th>
          <?php if (!empty($supportsHierarchy)): ?>
          <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= __("Tipo serie") ?></th>
          <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= __("Serie padre / universo") ?></th>
          <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= __("Gruppo serie") ?></th>
          <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?= __("Ciclo / stagione") ?></th>
          <?php endif; ?>
          <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?= __("Libri") ?></th>
          <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?= __("Volumi") ?></th>
          <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase"></th>
        </tr>
      </thead>
      <tbody class="bg-white divide-y divide-gray-200">
        <?php foreach ($collane as $c): ?>
        <tr class="hover:bg-gray-50">
          <td class="px-6 py-4">
            <a href="<?= htmlspecialchars(url('/admin/collane/dettaglio?nome=' . urlencode($c['collana'])), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-900 hover:text-gray-700 font-medium">
              <?= HtmlHelper::e($c['collana']) ?>
            </a>
          </td>
          <?php if (!empty($supportsHierarchy)): ?>
          <td class="px-6 py-4 text-sm text-gray-600">
            <?= HtmlHelper::e(SeriesLabels::label($c['tipo'] ?? 'serie')) ?>
          </td>
          <td class="px-6 py-4 text-sm text-gray-600">
            <?= !empty($c['parent_nome']) ? HtmlHelper::e($c['parent_nome']) : '<span class="text-gray-400">—</span>' ?>
          </td>
          <td class="px-6 py-4 text-sm text-gray-600">
            <?= HtmlHelper::e($c['gruppo_serie'] ?? '') ?>
          </td>
          <td class="px-6 py-4 text-sm text-gray-600">
            <?php if (!empty($c['ciclo']) || !empty($c['ordine_ciclo'])): ?>
              <?= HtmlHelper::e($c['ciclo'] ?? '') ?>
              <?php if (!empty($c['ordine_ciclo'])): ?>
                <span class="text-xs text-gray-400 ml-1">#<?= (int) $c['ordine_ciclo'] ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="text-gray-400">—</span>
            <?php endif; ?>
          </td>
          <?php endif; ?>
          <td class="px-6 py-4 text-center">
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
              <?= (int) $c['book_count'] ?>
            </span>
          </td>
          <td class="px-6 py-4 text-center text-sm text-gray-500">
            <?php if ($c['min_num'] !== null && $c['max_num'] !== null): ?>
              <?= (int) $c['min_num'] ?> – <?= (int) $c['max_num'] ?>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td class="px-6 py-4 text-right">
            <a href="<?= htmlspecialchars(url('/admin/collane/dettaglio?nome=' . urlencode($c['collana'])), ENT_QUOTES, 'UTF-8') ?>" class="text-sm text-gray-500 hover:text-gray-700">
              <i class="fas fa-chevron-right"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</section>

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
    <button type="button" onclick="createCollana()" class="inline-flex items-center px-4 py-2 bg-gray-800 text-white hover:bg-gray-900 rounded-lg transition-colors text-sm font-medium">
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
      const resp = await fetch((window.BASE_PATH || '') + '/admin/series/create', {
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

  <?php
  // #428 — the rows were already ordered as a tree (universe first, then its
  // series by cycle order), but every row looked the same: to see that a cycle
  // belongs to an universe you had to read the "parent" column and rebuild the
  // hierarchy in your head. The block below marks where each universe starts
  // and ends, using exactly the key the SQL orders by, so the drawing can never
  // disagree with the ordering.
  $seriesBlockKey = static function (array $row): string {
      foreach ([$row['gruppo_serie'] ?? '', $row['parent_nome'] ?? '', $row['collana'] ?? ''] as $candidate) {
          $candidate = trim((string) $candidate);
          if ($candidate !== '') {
              return $candidate;
          }
      }
      return '';
  };
  $seriesKeys = array_map($seriesBlockKey, $collane);
  $seriesBlockSize = array_count_values(array_map('strval', $seriesKeys));
  ?>

  <style>
  /* #428 — the hierarchy is drawn here rather than with utility classes: the
     compiled stylesheet is purged, so a class that appears only in this view
     would have no rule behind it. Scoped to .series-tree. */
  .series-tree .series-block-start > td { border-top: 2px solid #9ca3af; }
  .series-tree tr:first-child.series-block-start > td { border-top: 0; }
  /* The closing rule is the one that matters: it says the universe ends here,
     whatever follows — another universe or an unrelated series. */
  .series-tree .series-block-end > td { border-bottom: 2px solid #9ca3af; }
  .series-tree .series-block-header > td { background-color: #f9fafb; }
  .series-tree .series-name { display: inline-flex; align-items: center; }
  /* The connector is drawn on the CELL, not on the text: a long name wraps to
     two lines and a text-anchored line would float away from the row. */
  .series-tree td.series-cell-child { position: relative; padding-left: 3rem; }
  .series-tree td.series-cell-child::before {
      content: "";
      position: absolute;
      left: 1.5rem;
      top: 0;
      bottom: 0;
      border-left: 1px solid #d1d5db;
  }
  /* The last series of the block closes the line at the corner instead of
     carrying it on: that is the "where it ends" the issue asks for. */
  .series-tree td.series-cell-last::before { bottom: 50%; }
  .series-tree td.series-cell-child::after {
      content: "";
      position: absolute;
      left: 1.5rem;
      top: 50%;
      width: 0.85rem;
      border-top: 1px solid #d1d5db;
  }
  .series-tree .series-parent-echo { color: #9ca3af; font-size: 1rem; }
  .series-tree .sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
  }
  .series-tree .series-block-count {
      margin-left: 0.5rem;
      padding: 0.05rem 0.45rem;
      border-radius: 9999px;
      background-color: #e5e7eb;
      color: #4b5563;
      font-size: 0.7rem;
      font-weight: 600;
  }
  </style>

  <!-- Collane List -->
  <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden series-tree">
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
        <?php foreach ($collane as $rowIndex => $c): ?>
          <?php
          $blockKey = $seriesKeys[$rowIndex] ?? '';
          $isChild = !empty($supportsHierarchy) && !empty($c['parent_nome']);
          $blockStarts = $rowIndex === 0 || ($seriesKeys[$rowIndex - 1] ?? null) !== $blockKey;
          $blockEnds = !isset($seriesKeys[$rowIndex + 1]) || $seriesKeys[$rowIndex + 1] !== $blockKey;
          $blockCount = (int) ($seriesBlockSize[(string) $blockKey] ?? 1);
          // A universe must close visibly even when what follows is an ordinary
          // series with no hierarchy at all: without it the block bleeds into
          // the next row and the reader cannot tell where it ended.
          $previousClosedBlock = $rowIndex > 0
              && ($seriesKeys[$rowIndex - 1] ?? null) !== $blockKey
              && (int) ($seriesBlockSize[(string) ($seriesKeys[$rowIndex - 1] ?? '')] ?? 1) > 1;
          // A block of one is a plain series: decorating it would add noise.
          $inTree = !empty($supportsHierarchy) && $blockCount > 1;
          $isHeader = $inTree && $blockStarts && !$isChild;
          ?>
        <tr class="hover:bg-gray-50<?= $inTree && $blockStarts && !$previousClosedBlock ? ' series-block-start' : '' ?><?= $inTree && $blockEnds ? ' series-block-end' : '' ?><?= $isHeader ? ' series-block-header' : '' ?>">
          <td class="px-6 py-4<?= $inTree && $isChild ? ' series-cell-child' : '' ?><?= $inTree && $isChild && $blockEnds ? ' series-cell-last' : '' ?>">
            <span class="series-name">
              <?php if ($isHeader): ?>
                <i class="fas fa-sitemap text-gray-400 mr-2" aria-hidden="true"></i>
              <?php endif; ?>
              <a href="<?= htmlspecialchars(url('/admin/series/detail?nome=' . urlencode($c['collana'])), ENT_QUOTES, 'UTF-8') ?>" class="text-gray-900 hover:text-gray-700 font-medium">
                <?= HtmlHelper::e($c['collana']) ?>
              </a>
              <?php if ($isHeader): ?>
                <span class="series-block-count" title="<?= htmlspecialchars(__('Serie raccolte in questo universo'), ENT_QUOTES, 'UTF-8') ?>"><?= $blockCount - 1 ?></span>
              <?php endif; ?>
            </span>
            <?php if (!empty($supportsCompleteFlag) && (int) ($c['is_completa'] ?? 0) === 1): ?>
              <span class="ml-2 inline-flex text-green-600" role="img" aria-label="<?= htmlspecialchars(__('Serie completa'), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(__('Tutti i volumi previsti sono presenti in catalogo.'), ENT_QUOTES, 'UTF-8') ?>">
                <i class="fas fa-check-circle" aria-hidden="true"></i>
              </span>
            <?php endif; ?>
          </td>
          <?php if (!empty($supportsHierarchy)): ?>
          <td class="px-6 py-4 text-sm text-gray-600">
            <?= HtmlHelper::e(SeriesLabels::label($c['tipo'] ?? 'serie')) ?>
          </td>
          <td class="px-6 py-4 text-sm text-gray-600">
            <?php if (empty($c['parent_nome'])): ?>
              <span class="text-gray-400">—</span>
            <?php elseif ($inTree): ?>
              <?php // Inside a block the tree already says who the parent is, and the
                    // name is repeated again under "gruppo serie": three times per row
                    // is noise. Keep one glyph for the eye and the name for a screen
                    // reader, so nothing is lost for anyone. ?>
              <span class="series-parent-echo" title="<?= htmlspecialchars($c['parent_nome'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true">&#8627;</span>
              <span class="sr-only"><?= HtmlHelper::e($c['parent_nome']) ?></span>
            <?php else: ?>
              <?= HtmlHelper::e($c['parent_nome']) ?>
            <?php endif; ?>
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
            <a href="<?= htmlspecialchars(url('/admin/series/detail?nome=' . urlencode($c['collana'])), ENT_QUOTES, 'UTF-8') ?>" class="text-sm text-gray-500 hover:text-gray-700">
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

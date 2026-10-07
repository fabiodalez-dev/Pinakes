<?php
/**
 * Emeroteca — admin list of testate.
 *
 * Chrome copied verbatim from the core book list (app/Views/libri/index.php):
 * page shell, breadcrumb, header with the actions on the right, one card with
 * the filter bar on top and the table below, row actions as the core icon
 * buttons that admin-ui.css turns into bordered squares.
 *
 * @var array<int, array<string, mixed>> $rows
 * @var array<int, string> $editori   id => nome (empty on degraded schema)
 * @var string|null $f_tipo
 * @var int|null    $f_editore
 * @var string|null $f_stato
 * @var int|null    $page         current page (1-based)
 * @var int|null    $total_pages
 * @var int|null    $total        total testate matching the filters
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$f_tipo    = $f_tipo    ?? '';
$f_editore = (int) ($f_editore ?? 0);
$f_stato   = $f_stato   ?? '';
$page       = max(1, (int) ($page ?? 1));
$totalPages = max(1, (int) ($total_pages ?? 1));
$total      = (int) ($total ?? count($rows));

/** Page link keeping the active filters (page param last). */
$pageUrl = static function (int $p) use ($f_tipo, $f_editore, $f_stato): string {
    $qs = ['view'=>'titles'];
    if ($f_tipo !== '') {
        $qs['tipo'] = $f_tipo;
    }
    if ($f_editore > 0) {
        $qs['editore'] = $f_editore;
    }
    if ($f_stato !== '') {
        $qs['stato_raccolta'] = $f_stato;
    }
    $qs['page'] = $p;
    return url('/admin/periodicals') . '?' . http_build_query($qs);
};

$tipoLabels = [
    'rivista'    => __('Rivista'),
    'giornale'   => __('Giornale'),
    'magazine'   => __('Magazine'),
    'bollettino' => __('Bollettino'),
    'fanzine'    => __('Fanzine'),
];
$statoLabels = [
    'attiva'   => __('Attiva'),
    'chiusa'   => __('Chiusa'),
    'dismessa' => __('Dismessa'),
];
$periodicitaLabels = [
    'quotidiano'   => __('Quotidiano'),
    'settimanale'  => __('Settimanale'),
    'quindicinale' => __('Quindicinale'),
    'mensile'      => __('Mensile'),
    'bimestrale'   => __('Bimestrale'),
    'trimestrale'  => __('Trimestrale'),
    'semestrale'   => __('Semestrale'),
    'annuale'      => __('Annuale'),
    'irregolare'   => __('Irregolare'),
];
$isFiltered = $f_tipo !== '' || $f_editore > 0 || $f_stato !== '';
?>
<link rel="stylesheet" href="<?= $e(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.4.0')) ?>">
<div id="emeroteca-admin-index" class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
      <ol class="flex items-center space-x-2 text-sm">
        <li>
          <a href="<?= $e(url('/admin/dashboard')) ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-home mr-1"></i><?= __("Home") ?>
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li class="text-gray-900 font-medium" aria-current="page">
          <?= __("Emeroteca") ?>
        </li>
      </ol>
    </nav>

    <!-- Header with Actions -->
    <div class="mb-6 fade-in">
      <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
        <div>
          <h1 class="text-3xl font-bold text-gray-900"><?= __("Emeroteca") ?></h1>
          <p class="text-sm text-gray-600 mt-1"><?= __("Gestione di riviste, giornali e periodici: testate, annate e fascicoli.") ?></p>
          <span class="status-badge bg-gray-100 text-gray-700 mt-3"><?= $e(sprintf(__('%d testate trovate'), $total)) ?></span>
        </div>
        <div class="flex flex-wrap items-center gap-2 periodicals-page-actions">
          <a href="<?= $e(url('/admin/periodicals/articles')) ?>" class="btn-secondary">
            <i class="fas fa-file-alt mr-2" aria-hidden="true"></i><?= __('Articoli') ?>
          </a>
          <a href="<?= $e(url('/admin/periodicals/export/kbart')) ?>" class="btn-secondary"
             title="<?= $e(__('Esporta le consistenze in formato KBART (TSV) per i cataloghi collettivi')) ?>">
            <i class="fas fa-file-export mr-2" aria-hidden="true"></i><?= __("Esporta KBART") ?>
          </a>
          <a href="<?= $e(url('/admin/periodicals/export/acnp')) ?>" class="btn-secondary"
             title="<?= $e(__('Esporta le consistenze in formato ACNP (CSV)')) ?>">
            <i class="fas fa-file-csv mr-2" aria-hidden="true"></i><?= __("Esporta ACNP") ?>
          </a>
          <a href="<?= $e(url('/admin/periodicals/create')) ?>" class="btn-primary">
            <i class="fas fa-plus mr-2" aria-hidden="true"></i><?= __("Nuova testata") ?>
          </a>
        </div>
      </div>
    </div>

    <?php
    // The workflow chooser is a shared partial (also on the articles list and
    // the plugin settings page) whose rules are scoped under .emeroteca-admin;
    // the wrapper only provides that scope, the shell spacing stays the core one.
    ?>
    <div class="emeroteca-admin periodicals-mode-scope">
      <?php $mode=$mode??'complete'; $modeReturnTo='/admin/periodicals?view=titles'; require __DIR__.'/article-mode.php'; ?>
    </div>

    <?php
    $menuToggle = ['action' => url('/admin/periodicals/menu-visibility'), 'enabled' => \App\Support\ConfigStore::isInPublicMenu('emeroteca'), 'title' => __("Voce Emeroteca nel menu")];
    require dirname(__DIR__, 5) . '/app/Views/admin/partials/menu-visibility-toggle.php';
    ?>

    <div class="card periodicals-list-card">
      <!-- Filters Bar -->
      <form method="GET" action="<?= $e(url('/admin/periodicals')) ?>" class="p-4 border-b border-gray-100">
        <input type="hidden" name="view" value="titles">
        <div class="periodicals-filter-grid">
          <div>
            <label for="emt-tipo" class="form-label periodicals-filter-label">
              <i class="fas fa-tag mr-1" aria-hidden="true"></i><?= __("Tipo") ?>
            </label>
            <select id="emt-tipo" name="tipo" class="form-input">
              <option value=""><?= __("Tutti i tipi") ?></option>
              <?php foreach ($tipoLabels as $value => $label): ?>
                <option value="<?= $e($value) ?>" <?= $f_tipo === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($editori !== []): ?>
          <div>
            <label for="emt-editore" class="form-label periodicals-filter-label">
              <i class="fas fa-building mr-1" aria-hidden="true"></i><?= __("Editore") ?>
            </label>
            <select id="emt-editore" name="editore" class="form-input">
              <option value=""><?= __("Tutti gli editori") ?></option>
              <?php foreach ($editori as $eid => $nome): ?>
                <option value="<?= (int) $eid ?>" <?= $f_editore === (int) $eid ? 'selected' : '' ?>><?= $e($nome) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div>
            <label for="emt-stato" class="form-label periodicals-filter-label">
              <i class="fas fa-info-circle mr-1" aria-hidden="true"></i><?= __("Stato raccolta") ?>
            </label>
            <select id="emt-stato" name="stato_raccolta" class="form-input">
              <option value=""><?= __("Tutti gli stati") ?></option>
              <?php foreach ($statoLabels as $value => $label): ?>
                <option value="<?= $e($value) ?>" <?= $f_stato === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn-secondary periodicals-filter-action">
            <i class="fas fa-search" aria-hidden="true"></i><span><?= __("Filtra") ?></span>
          </button>
          <?php if ($isFiltered): ?>
            <a href="<?= $e(url('/admin/periodicals?view=titles')) ?>" class="btn-secondary periodicals-filter-action">
              <i class="fas fa-times" aria-hidden="true"></i><span><?= __("Azzera") ?></span>
            </a>
          <?php endif; ?>
        </div>
      </form>

    <?php if (empty($rows)): ?>
      <div class="p-8 text-center">
        <?php if ($isFiltered): ?>
          <p class="text-sm text-gray-600"><?= __("Nessuna testata corrisponde ai filtri selezionati.") ?></p>
        <?php else: ?>
          <p class="text-sm text-gray-600" role="status">
            <strong class="text-gray-900"><?= __("Nessuna testata registrata.") ?></strong>
            <?= __("Crea la prima testata per iniziare a catalogare riviste e periodici.") ?>
          </p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <!--
          Duplicate merge, same UX as the core author/publisher merge: tick
          the duplicates in the list, review the preview, confirm there.
          The checkboxes belong to this form through the HTML `form`
          attribute, because the rows already contain the delete POST form
          and nesting forms is invalid markup.
      -->
      <form id="emt-merge-select" method="GET" action="<?= $e(url('/admin/periodicals/merge')) ?>"
            class="p-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <span class="text-sm text-gray-600">
          <?= __("Seleziona due testate duplicate per unirle in una sola.") ?>
        </span>
        <button type="submit" class="btn-secondary periodicals-filter-action">
          <i class="fas fa-code-merge" aria-hidden="true"></i><span><?= __("Unisci le testate selezionate") ?></span>
        </button>
      </form>

      <div class="p-4">
        <div class="periodicals-table-wrap overflow-x-auto">
          <table id="emeroteca-periodicals-table" class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="text-center"><span class="sr-only"><?= __("Seleziona") ?></span></th>
                <th><?= __("Testata") ?></th>
                <th><?= __("ISSN") ?></th>
                <th><?= __("Editore") ?></th>
                <th><?= __("Periodicità") ?></th>
                <th><?= __("Tipo") ?></th>
                <th><?= __("Fascicoli") ?></th>
                <th><?= __("Consistenza") ?></th>
                <th class="text-center"><?= __("Azioni") ?></th>
              </tr>
            </thead>
            <tbody class="bg-white">
              <?php foreach ($rows as $row): ?>
                <?php
                $rowId = (int) $row['id'];
                $issuesUrl = $e(url('/admin/periodicals/' . $rowId . '/issues'));
                $editUrl   = $e(url('/admin/periodicals/edit/' . $rowId));
                $deleteUrl = $e(url('/admin/periodicals/delete/' . $rowId));
                $subsUrl   = $e(url('/admin/periodicals/' . $rowId . '/subscriptions'));
                $logo = (string) ($row['logo_url'] ?? '');
                $logoSrc = $logo === '' ? '' : (str_starts_with($logo, '/') ? url($logo) : $logo);
                $statoVal = (string) ($row['stato_raccolta'] ?? 'attiva');
                $statoClass = $statoVal === 'attiva' ? 'bg-green-50 text-green-800' : 'bg-gray-100 text-gray-700';
                $nScadenza = (int) ($row['n_abbonamenti_scadenza'] ?? 0);
                $nMancanti = (int) $row['n_mancanti'];
                $periodicitaLabel = $periodicitaLabels[(string) ($row['periodicita'] ?? '')] ?? (string) ($row['periodicita'] ?? '');
                ?>
                <tr>
                  <td class="text-center align-middle">
                    <input type="checkbox" name="ids[]" value="<?= $rowId ?>"
                           form="emt-merge-select" class="w-4 h-4 rounded border-gray-300 text-gray-800 focus:ring-gray-500 cursor-pointer"
                           aria-label="<?= $e(sprintf(__('Seleziona «%s» per l\'unione'), (string) $row['titolo'])) ?>">
                  </td>
                  <td>
                    <div class="flex items-start gap-3 min-w-0">
                      <?php if ($logoSrc !== ''): ?>
                        <img src="<?= $e($logoSrc) ?>" alt="<?= $e($row['titolo']) ?>"
                             class="w-10 h-10 rounded object-cover shrink-0">
                      <?php else: ?>
                        <div class="flex items-center justify-center w-10 h-10 rounded bg-gray-100 shrink-0">
                          <i class="fas fa-newspaper text-gray-400" aria-hidden="true"></i>
                        </div>
                      <?php endif; ?>
                      <div class="min-w-0">
                        <a href="<?= $issuesUrl ?>" class="font-medium text-gray-900 hover:underline"><?= $e($row['titolo']) ?></a>
                        <?php if (!empty($row['sottotitolo'])): ?>
                          <div class="text-xs text-gray-500 italic mt-0.5 line-clamp-1"><?= $e($row['sottotitolo']) ?></div>
                        <?php endif; ?>
                        <div class="periodicals-secondary-meta text-xs text-gray-500 mt-0.5">
                          <?php if (!empty($row['editore_nome'])): ?><span><?= $e($row['editore_nome']) ?></span><?php endif; ?>
                          <?php if ($periodicitaLabel !== ''): ?><span><?= $e($periodicitaLabel) ?></span><?php endif; ?>
                        </div>
                        <div class="periodicals-mobile-meta text-xs text-gray-500 mt-0.5">
                          <?php if (!empty($row['issn'])): ?><span class="font-mono"><?= $e($row['issn']) ?></span><?php endif; ?>
                          <?php if (!empty($row['editore_nome'])): ?><span><?= $e($row['editore_nome']) ?></span><?php endif; ?>
                          <?php if ($periodicitaLabel !== ''): ?><span><?= $e($periodicitaLabel) ?></span><?php endif; ?>
                          <span><?= (int) $row['n_posseduti'] ?> <?= $e(__('Posseduti')) ?> · <?= $nMancanti ?> <?= $e(__('Mancanti')) ?></span>
                          <span><?= $e($row['consistenza'] ?? '—') ?></span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 mt-2">
                          <span class="status-badge <?= $statoClass ?>"><?= $e($statoLabels[$statoVal] ?? $statoVal) ?></span>
                          <?php if ($nScadenza > 0): ?>
                            <a href="<?= $subsUrl ?>" class="status-badge bg-yellow-50 text-yellow-800"
                               title="<?= $e(__('Abbonamenti in scadenza')) ?>">
                              <i class="fas fa-file-signature" aria-hidden="true"></i>
                              <?= sprintf(__('%d in scadenza'), $nScadenza) ?>
                            </a>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  </td>
                  <td class="whitespace-nowrap"><span class="text-xs font-mono text-gray-600"><?= $e($row['issn'] ?? '') ?></span></td>
                  <td class="text-gray-600"><?= $e($row['editore_nome'] ?? '') ?></td>
                  <td class="text-gray-600"><?= $e($periodicitaLabels[(string) ($row['periodicita'] ?? '')] ?? '') ?></td>
                  <td>
                    <span class="status-badge bg-gray-100 text-gray-700">
                      <?= $e($tipoLabels[(string) ($row['tipo'] ?? '')] ?? (string) ($row['tipo'] ?? '')) ?>
                    </span>
                  </td>
                  <td class="whitespace-nowrap">
                    <span class="status-badge bg-gray-100 text-gray-700" title="<?= $e(__('Posseduti')) ?>"><?= (int) $row['n_posseduti'] ?></span>
                    <span class="status-badge <?= $nMancanti > 0 ? 'bg-red-50 text-red-800' : 'bg-gray-100 text-gray-700' ?>"
                          title="<?= $e(__('Mancanti')) ?>"><?= $nMancanti ?></span>
                  </td>
                  <td class="text-gray-600"><?= $e($row['consistenza'] ?? '—') ?></td>
                  <td class="text-center align-middle">
                    <div class="flex items-center justify-center gap-0.5">
                      <a href="<?= $issuesUrl ?>" class="w-7 h-7 inline-flex items-center justify-center text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded transition-all" title="<?= $e(__('Fascicoli')) ?>" aria-label="<?= $e(__('Fascicoli')) ?>">
                        <i class="fas fa-layer-group text-xs" aria-hidden="true"></i>
                      </a>
                      <a href="<?= $subsUrl ?>" class="w-7 h-7 inline-flex items-center justify-center text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded transition-all" title="<?= $e(__('Abbonamenti')) ?>" aria-label="<?= $e(__('Abbonamenti')) ?>">
                        <i class="fas fa-file-signature text-xs" aria-hidden="true"></i>
                      </a>
                      <a href="<?= $editUrl ?>" class="w-7 h-7 inline-flex items-center justify-center text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded transition-all" title="<?= $e(__('Modifica')) ?>" aria-label="<?= $e(__('Modifica')) ?>">
                        <i class="fas fa-edit text-xs" aria-hidden="true"></i>
                      </a>
                      <form method="POST" action="<?= $deleteUrl ?>" class="inline-flex"
                            onsubmit="return confirm(<?= $e(json_encode(__('Eliminare questa testata con tutte le annate, i fascicoli e lo spoglio?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);">
                        <input type="hidden" name="csrf_token" value="<?= $e(\App\Support\Csrf::ensureToken()) ?>">
                        <button type="submit" class="w-7 h-7 inline-flex items-center justify-center text-gray-500 hover:text-red-500 hover:bg-red-50 rounded transition-all" title="<?= $e(__('Elimina')) ?>" aria-label="<?= $e(__('Elimina')) ?>">
                          <i class="fas fa-trash text-xs" aria-hidden="true"></i>
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
    </div>

    <!-- Pagination (chrome copied verbatim from app/Views/events/index.php) -->
    <?php if (!empty($rows) && $totalPages > 1): ?>
      <div class="mt-8 flex items-center justify-center gap-2">
        <?php if ($page > 1): ?>
          <a href="<?= $e($pageUrl($page - 1)) ?>"
            class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-semibold">
            <i class="fas fa-chevron-left"></i>
            <?= __("Precedente") ?>
          </a>
        <?php endif; ?>

        <div class="flex items-center gap-1">
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i == $page): ?>
              <span class="px-4 py-2 bg-gray-900 text-white rounded-lg font-semibold">
                <?= $i ?>
              </span>
            <?php elseif ($i == 1 || $i == $totalPages || abs($i - $page) <= 2): ?>
              <a href="<?= $e($pageUrl($i)) ?>"
                class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-semibold">
                <?= $i ?>
              </a>
            <?php elseif (abs($i - $page) == 3): ?>
              <span class="px-2 text-gray-400">...</span>
            <?php endif; ?>
          <?php endfor; ?>
        </div>

        <?php if ($page < $totalPages): ?>
          <a href="<?= $e($pageUrl($page + 1)) ?>"
            class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-semibold">
            <?= __("Successivo") ?>
            <i class="fas fa-chevron-right"></i>
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<style>
/* Same rules as the core book list (app/Views/libri/index.php), scoped here. */
.periodicals-list-card {
  padding: 0;
  overflow: visible;
}

/* The workflow chooser partial needs the plugin scope, not its page padding. */
#emeroteca-admin-index .periodicals-mode-scope {
  max-width: none;
  margin: 0;
  padding: 0;
}

.periodicals-page-actions .btn-primary,
.periodicals-page-actions .btn-secondary {
  min-height: 44px;
  padding: .65rem 1rem;
  white-space: nowrap;
}

.periodicals-filter-grid {
  display: flex;
  flex-wrap: wrap;
  align-items: end;
  gap: .75rem;
}

.periodicals-filter-grid > div {
  flex: 1 1 calc(50% - .375rem);
  min-width: 0;
}

.periodicals-filter-label {
  margin-bottom: .375rem;
  color: #4b5563;
  font-size: .75rem;
  line-height: 1rem;
}

.periodicals-filter-action {
  min-height: 44px;
  gap: .4rem;
  padding: .65rem .85rem;
  white-space: nowrap;
}

.periodicals-filter-grid > .periodicals-filter-action { flex: 1 1 calc(50% - .375rem); }

.line-clamp-1 { display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; }

.fade-in { animation: fadeIn 0.2s ease-out; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

@media (prefers-reduced-motion: reduce) {
  .fade-in { animation: none; }
}

table#emeroteca-periodicals-table { border: 1px solid #e5e7eb; width: 100%; }
#emeroteca-periodicals-table thead th {
  border-bottom: 1px solid #e5e7eb;
  background: #f9fafb;
  color: #4b5563;
  font-size: .75rem;
  font-weight: 600;
  letter-spacing: .025em;
  padding: .75rem;
  text-align: left;
  text-transform: uppercase;
}
#emeroteca-periodicals-table thead th.text-center { text-align: center; }
#emeroteca-periodicals-table tbody td {
  border-bottom: 1px solid #f3f4f6;
  font-size: .875rem;
  padding: .75rem;
  vertical-align: middle;
}
#emeroteca-periodicals-table tbody tr:hover { background: #f9fafb; }
#emeroteca-periodicals-table tbody td:nth-child(2) { overflow-wrap: anywhere; }

.periodicals-secondary-meta,
.periodicals-mobile-meta { display: none; }
.periodicals-secondary-meta > span + span::before,
.periodicals-mobile-meta > span + span::before { content: "·"; margin-inline: .35rem; }

@media (min-width: 640px) {
  .periodicals-filter-grid > div { flex: 1 1 9rem; }
  .periodicals-filter-grid > .periodicals-filter-action { flex: 0 0 auto; }
}

/* Mid widths: publisher and frequency move under the title. */
@media (min-width: 640px) and (max-width: 1279px) {
  #emeroteca-periodicals-table th:nth-child(4), #emeroteca-periodicals-table td:nth-child(4),
  #emeroteca-periodicals-table th:nth-child(5), #emeroteca-periodicals-table td:nth-child(5) { display: none; }
  .periodicals-secondary-meta { display: block; }
}

/* Phones: title, selection and actions only; the rest collapses under the title. */
@media (max-width: 639px) {
  #emeroteca-periodicals-table { table-layout: fixed; min-width: 0 !important; max-width: 100%; }
  #emeroteca-periodicals-table th:nth-child(n+3):not(:last-child),
  #emeroteca-periodicals-table td:nth-child(n+3):not(:last-child) { display: none; }
  #emeroteca-periodicals-table th:first-child, #emeroteca-periodicals-table td:first-child { width: 12%; padding: .55rem .2rem; }
  #emeroteca-periodicals-table th:nth-child(2), #emeroteca-periodicals-table td:nth-child(2) { width: 58%; padding: .55rem .35rem; }
  #emeroteca-periodicals-table th:last-child, #emeroteca-periodicals-table td:last-child { width: 30%; padding: .55rem .1rem; }
  #emeroteca-periodicals-table td:last-child > div { display: grid; grid-template-columns: repeat(2, 34px); justify-content: center; gap: .3rem; }
  #emeroteca-periodicals-table td:last-child :is(a, button) { flex: 0 0 auto; }
  #emeroteca-periodicals-table td:nth-child(2) img,
  #emeroteca-periodicals-table td:nth-child(2) .w-10 { display: none; }
  .periodicals-mobile-meta { display: block; }
  .periodicals-table-wrap { overflow-x: visible; }
}
</style>

<?php
/** @var list<array<string,mixed>> $sessions */
/** @var list<array<string,mixed>> $scaffali */
/** @var list<array<string,mixed>> $mensole */
/** @var list<array{id:int,label:string}> $generi */
/** @var list<array<string,mixed>> $sedi */
$stateLabels = ['aperta' => __('Aperta'), 'chiusa' => __('Chiusa'), 'annullata' => __('Annullata')];
$stateBadges = ['aperta' => 'bg-green-100 text-green-800', 'chiusa' => 'bg-gray-100 text-gray-800', 'annullata' => 'bg-red-100 text-red-800'];
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6">

    <!-- Header -->
    <?php
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title text-3xl font-bold text-gray-900 flex items-center"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?>

            <?= __("Revisione inventariale") ?>
          <?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?>
      <div class="flex items-center justify-between">
        <div>

          <p class="text-sm text-gray-600 mt-1"><?= __("Confronta le copie presenti sugli scaffali con quelle a catalogo") ?></p>
        </div>
      </div>
    <?php
$workspaceHeader['context'] = (string) ob_get_clean();
ob_start();
?><nav class="pk-workspace-breadcrumb" aria-label="<?= htmlspecialchars(__('Percorso di navigazione'), ENT_QUOTES, 'UTF-8') ?>">
      <ol>
        <li>
          <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Home') ?></a>
        </li>

        <li aria-current="page">
          <?= __("Revisione inventariale") ?>
        </li>
      </ol>
    </nav><?php
$workspaceHeader['breadcrumb'] = (string) ob_get_clean();
require dirname(__DIR__, 3) . '/app/Views/partials/workspace-header.php';
unset($workspaceHeader);
?>

    <!-- Alerts -->
    <?php if (!empty($_SESSION['success_message'])): ?>
      <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg flex items-start" role="alert">
        <i class="fas fa-check-circle text-green-600 mt-0.5 mr-3"></i>
        <div class="flex-1">
          <p class="text-green-800 font-medium"><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></p>
        </div>
        <button onclick="this.parentElement.remove()" class="btn-icon btn-secondary text-green-600 hover:text-green-800" aria-label="<?= htmlspecialchars(__('Chiudi'), ENT_QUOTES, 'UTF-8') ?>">
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
        <button onclick="this.parentElement.remove()" class="btn-icon btn-secondary text-red-600 hover:text-red-800" aria-label="<?= htmlspecialchars(__('Chiudi'), ENT_QUOTES, 'UTF-8') ?>">
          <i class="fas fa-times"></i>
        </button>
      </div>
    <?php endif; ?>

    <div class="space-y-6">

        <!-- Open a session -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
          <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-900 flex items-center">
              <i class="fas fa-plus-circle text-gray-600 mr-2"></i>
              <?= __("Nuova revisione") ?>
            </h2>
            <p class="text-sm text-gray-600 mt-1"><?= __("All'apertura viene fissato l'elenco delle copie attese: le modifiche successive al catalogo non cambiano il risultato.") ?></p>
          </div>
          <div class="p-6">
            <form method="post" action="<?= htmlspecialchars(url('/admin/inventory'), ENT_QUOTES, 'UTF-8') ?>" id="inventory-open-form">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
              <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="md:col-span-2">
                  <label for="inventory-name" class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Nome *") ?></label>
                  <input id="inventory-name" name="nome" maxlength="255" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 w-full focus:outline-none focus:ring-2 focus:ring-gray-400" placeholder="<?= htmlspecialchars(__('Revisione scaffale A, ottobre'), ENT_QUOTES, 'UTF-8') ?>" required aria-required="true">
                </div>
                <?php if ($sedi !== []): ?>
                <div>
                  <label for="inventory-sede" class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Sede") ?></label>
                  <select id="inventory-sede" name="sede_id" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 w-full focus:outline-none focus:ring-2 focus:ring-gray-400">
                    <option value=""><?= __("Tutte le sedi") ?></option>
                    <?php foreach ($sedi as $sede): ?>
                      <option value="<?= (int) $sede['id'] ?>"><?= htmlspecialchars((string) $sede['nome'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <?php endif; ?>
                <div>
                  <label for="inventory-media" class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Tipo media") ?></label>
                  <select id="inventory-media" name="tipo_media" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 w-full focus:outline-none focus:ring-2 focus:ring-gray-400">
                    <option value=""><?= __("Tutti") ?></option>
                    <?php foreach (\App\Services\StocktakeService::MEDIA_TYPES as $media): ?>
                      <option value="<?= htmlspecialchars($media, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(\App\Support\MediaLabels::tipoMediaDisplayName($media), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label for="inventory-shelf" class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Scaffale") ?></label>
                  <select id="inventory-shelf" name="scaffale_id" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 w-full focus:outline-none focus:ring-2 focus:ring-gray-400">
                    <option value=""><?= __("Tutti gli scaffali") ?></option>
                    <?php foreach ($scaffali as $s): ?>
                      <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars('[' . ((string) ($s['codice'] ?? '')) . '] ' . ((string) ($s['nome'] ?? '')), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label for="inventory-level" class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Mensola") ?></label>
                  <select id="inventory-level" name="mensola_id" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 w-full focus:outline-none focus:ring-2 focus:ring-gray-400">
                    <option value=""><?= __("Tutte le mensole") ?></option>
                    <?php foreach ($mensole as $m): ?>
                      <option value="<?= (int) $m['id'] ?>" data-scaffale-id="<?= (int) $m['scaffale_id'] ?>"><?= htmlspecialchars((string) ($m['scaffale_codice'] ?? ''), ENT_QUOTES, 'UTF-8') ?> - <?= __("Livello") ?> <?= (int) $m['numero_livello'] ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="md:col-span-2">
                  <label for="inventory-genre" class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Genere") ?></label>
                  <select id="inventory-genre" name="genere_id" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 w-full focus:outline-none focus:ring-2 focus:ring-gray-400">
                    <option value=""><?= __("Tutti i generi") ?></option>
                    <?php foreach ($generi as $g): ?>
                      <option value="<?= (int) $g['id'] ?>"><?= htmlspecialchars($g['label'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label for="inventory-from" class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Collocazione da") ?></label>
                  <input id="inventory-from" name="collocazione_da" maxlength="50" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 w-full focus:outline-none focus:ring-2 focus:ring-gray-400" placeholder="<?= htmlspecialchars(__('A.1'), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div>
                  <label for="inventory-to" class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Collocazione a") ?></label>
                  <input id="inventory-to" name="collocazione_a" maxlength="50" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 w-full focus:outline-none focus:ring-2 focus:ring-gray-400" placeholder="<?= htmlspecialchars(__('A.9'), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="md:col-span-3">
                  <label for="inventory-note" class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Note") ?></label>
                  <input id="inventory-note" name="note" maxlength="1000" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 w-full focus:outline-none focus:ring-2 focus:ring-gray-400">
                </div>
                <div class="pk-form-actions flex items-end">
                  <button type="submit" class="inline-flex items-center justify-center px-6 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors w-full">
                    <i class="fas fa-play mr-2"></i>
                    <?= __("Apri revisione") ?>
                  </button>
                </div>
              </div>
            </form>
            <p class="text-xs text-gray-500 mt-3">
              <i class="fas fa-info-circle mr-1"></i>
              <?= __("Lascia vuoti i filtri per includere tutte le copie a catalogo. Il genere comprende anche i suoi sottogeneri.") ?>
            </p>
          </div>
        </div>

        <!-- Sessions -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
          <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-900 flex items-center">
              <i class="fas fa-clipboard-list text-gray-600 mr-2"></i>
              <?= __("Revisioni") ?>
            </h2>
          </div>
          <div class="p-6">
            <div class="border border-gray-200 rounded-lg overflow-hidden">
              <div class="overflow-x-auto">
                <table class="w-full text-sm" id="inventory-sessions">
                  <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                      <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __('Nome') ?></th>
                      <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __('Stato') ?></th>
                      <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __('Copie attese') ?></th>
                      <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __('Scansioni') ?></th>
                      <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __('Aperta il') ?></th>
                      <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __('Aperta da') ?></th>
                      <th class="px-4 py-2 text-center font-semibold text-gray-700"><?= __('Azioni') ?></th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-100">
                    <?php if ($sessions === []): ?>
                    <tr>
                      <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                        <i class="fas fa-inbox mr-2"></i><?= __("Nessuna revisione. Aprine una per iniziare.") ?>
                      </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($sessions as $row): $stato = (string) $row['stato']; ?>
                    <tr class="hover:bg-gray-50" data-session-id="<?= (int) $row['id'] ?>">
                      <td class="px-4 py-3 text-gray-900 font-medium"><?= htmlspecialchars((string) $row['nome'], ENT_QUOTES, 'UTF-8') ?><?php if (!empty($row['sede_nome'])): ?> <span class="text-xs text-gray-500">· <?= htmlspecialchars((string) $row['sede_nome'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></td>
                      <td class="px-4 py-3"><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $stateBadges[$stato] ?? 'bg-gray-100 text-gray-800' ?>"><?= htmlspecialchars($stateLabels[$stato] ?? $stato, ENT_QUOTES, 'UTF-8') ?></span></td>
                      <td class="px-4 py-3 text-gray-700"><?= (int) $row['totale_attese'] ?></td>
                      <td class="px-4 py-3 text-gray-700"><?= (int) $row['scansioni'] ?></td>
                      <td class="px-4 py-3 text-gray-600 text-xs"><?= htmlspecialchars(format_date((string) $row['aperta_il'], true, '/'), ENT_QUOTES, 'UTF-8') ?></td>
                      <td class="px-4 py-3 text-gray-600 text-xs"><?= htmlspecialchars((string) ($row['aperta_da_nome'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                      <td class="px-4 py-3 text-center">
                        <a href="<?= htmlspecialchars(url('/admin/inventory/' . (int) $row['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn-icon btn-secondary text-gray-600 hover:text-gray-900" title="<?= htmlspecialchars($stato === 'aperta' ? __('Scansiona') : __('Report'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($stato === 'aperta' ? __('Scansiona') : __('Report'), ENT_QUOTES, 'UTF-8') ?>"><i class="fas <?= $stato === 'aperta' ? 'fa-barcode' : 'fa-file-alt' ?>"></i></a>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

    </div>
  </div>
</div>
<script>
(function () {
  // Narrow the shelf-level list to the chosen bookcase, as the placement page does.
  var shelf = document.getElementById('inventory-shelf');
  var level = document.getElementById('inventory-level');
  if (!shelf || !level) { return; }
  shelf.addEventListener('change', function () {
    Array.prototype.forEach.call(level.options, function (opt) {
      if (!opt.value) { return; }
      var match = !shelf.value || opt.getAttribute('data-scaffale-id') === shelf.value;
      opt.hidden = !match;
      if (!match && opt.selected) { level.value = ''; }
    });
  });
})();
</script>

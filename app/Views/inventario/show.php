<?php
/** @var array<string,mixed> $session */
/** @var array<string,int> $summary */
/** @var list<array<string,mixed>> $scans */
/** @var bool $mineOnly */
/** @var list<array<string,string>> $reportRows */
/** @var list<string> $filterLabels */
/** @var bool $isAdmin */
/** @var array<string,string> $outcomeLabels */
/** @var list<array<string,mixed>> $scaffali */
$sessionId = (int) $session['id'];
$isOpen = $session['stato'] === 'aperta';
$base = '/admin/inventory/' . $sessionId;
$stateLabels = ['aperta' => __('Aperta'), 'chiusa' => __('Chiusa'), 'annullata' => __('Annullata')];
$stateBadges = ['aperta' => 'bg-green-100 text-green-800', 'chiusa' => 'bg-gray-100 text-gray-800', 'annullata' => 'bg-red-100 text-red-800'];
$outcomeBadges = [
    'trovata' => 'bg-green-100 text-green-800',
    'fuori_posto' => 'bg-amber-100 text-amber-800',
    'in_prestito' => 'bg-blue-100 text-blue-800',
    'mancante' => 'bg-red-100 text-red-800',
    'sconosciuta' => 'bg-red-100 text-red-800',
    'fuori_ambito' => 'bg-purple-100 text-purple-800',
    'duplicata' => 'bg-gray-100 text-gray-800',
];
$counters = [
    'attese' => [__('Copie attese'), 'fa-list-ol'],
    'trovate' => [$outcomeLabels['trovata'], 'fa-check-circle'],
    'fuori_posto' => [$outcomeLabels['fuori_posto'], 'fa-random'],
    'in_prestito' => [$outcomeLabels['in_prestito'], 'fa-handshake'],
    'mancanti' => [__('Mancanti'), 'fa-question-circle'],
    'sconosciute' => [__('Codici sconosciuti'), 'fa-times-circle'],
    'fuori_ambito' => [$outcomeLabels['fuori_ambito'], 'fa-external-link-alt'],
    'duplicate' => [__('Scansioni ripetute'), 'fa-clone'],
];
$sessionShelf = (int) ($session['filtro']['scaffale_id'] ?? 0);
?>
<div class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6">

    <?php
$workspaceHeader = [];
ob_start();
?><h1 class="pk-workspace-header__title text-3xl font-bold text-gray-900 flex items-center"><?php
$workspaceHeader['titleOpen'] = (string) ob_get_clean();
ob_start();
?>

            <?= htmlspecialchars((string) $session['nome'], ENT_QUOTES, 'UTF-8') ?>
          <?php
$workspaceHeader['title'] = (string) ob_get_clean();
ob_start();
?>
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm text-gray-600 mt-1">
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $stateBadges[(string) $session['stato']] ?? 'bg-gray-100 text-gray-800' ?>" id="inventory-state"><?= htmlspecialchars($stateLabels[(string) $session['stato']] ?? (string) $session['stato'], ENT_QUOTES, 'UTF-8') ?></span>
            <?= __("Aperta il") ?> <?= htmlspecialchars(format_date((string) $session['aperta_il'], true, '/'), ENT_QUOTES, 'UTF-8') ?>
            <?php if (!empty($session['aperta_da_nome'])): ?>· <?= htmlspecialchars((string) $session['aperta_da_nome'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
            <?php if (!empty($session['sede_nome'])): ?>· <?= __("Sede") ?>: <?= htmlspecialchars((string) $session['sede_nome'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
          </p>
          <p class="text-xs text-gray-500 mt-1" id="inventory-filter"><i class="fas fa-filter mr-1"></i><?= htmlspecialchars($filterLabels !== [] ? implode(' · ', $filterLabels) : __('Tutto il catalogo'), ENT_QUOTES, 'UTF-8') ?></p>
          <?php if (trim((string) ($session['note'] ?? '')) !== ''): ?>
          <p class="text-xs text-gray-500 mt-1"><i class="fas fa-sticky-note mr-1"></i><?= htmlspecialchars((string) $session['note'], ENT_QUOTES, 'UTF-8') ?></p>
          <?php endif; ?>
        </div>
      </div>
    <?php
$workspaceHeader['context'] = (string) ob_get_clean();
ob_start();
?>
        <div class="flex items-center gap-3">
          <a href="<?= htmlspecialchars(url($base . '/report.csv'), ENT_QUOTES, 'UTF-8') ?>" id="inventory-csv" class="inline-flex items-center gap-2 whitespace-nowrap px-4 py-2 bg-gray-100 text-gray-900 border border-gray-300 rounded-lg hover:bg-gray-200 transition-colors font-medium">
            <i class="fas fa-file-csv"></i><?= __("Report CSV") ?>
          </a>
          <a href="<?= htmlspecialchars(url($base . '/report.pdf'), ENT_QUOTES, 'UTF-8') ?>" id="inventory-pdf" class="inline-flex items-center gap-2 whitespace-nowrap px-4 py-2 bg-gray-100 text-gray-900 border border-gray-300 rounded-lg hover:bg-gray-200 transition-colors font-medium">
            <i class="fas fa-file-pdf"></i><?= __("Report PDF") ?>
          </a>
        </div>
      <?php
$workspaceHeader['actions'] = (string) ob_get_clean();
ob_start();
?><nav class="pk-workspace-breadcrumb" aria-label="<?= htmlspecialchars(__('Percorso di navigazione'), ENT_QUOTES, 'UTF-8') ?>">
      <ol>
        <li>
          <a href="<?= htmlspecialchars(url('/admin/dashboard'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Home') ?></a>
        </li>
        <li>
          <a href="<?= htmlspecialchars(url('/admin/inventory'), ENT_QUOTES, 'UTF-8') ?>"><?= __("Revisione inventariale") ?></a>
        </li>
        <li aria-current="page">
          <?= htmlspecialchars((string) $session['nome'], ENT_QUOTES, 'UTF-8') ?>
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

    <!-- Live counters -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6" id="inventory-counters">
      <?php foreach ($counters as $key => [$label, $icon]): ?>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-600"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></p>
              <p class="text-2xl font-bold text-gray-900" data-counter="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= (int) ($summary[$key] ?? 0) ?></p>
            </div>
            <i class="fas <?= $icon ?> text-3xl text-gray-300"></i>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="space-y-6">

    <?php if ($isOpen): ?>
        <!-- Scan -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
          <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-900 flex items-center">
              <i class="fas fa-barcode text-gray-600 mr-2"></i>
              <?= __("Scansione") ?>
            </h2>
            <p class="text-sm text-gray-600 mt-1"><?= __("Leggi il codice con un lettore di codici a barre o con la fotocamera, oppure digitalo e premi Invio.") ?></p>
          </div>
          <div class="p-6">
            <form method="post" action="<?= htmlspecialchars(url($base . '/scan'), ENT_QUOTES, 'UTF-8') ?>" id="inventory-scan-form" autocomplete="off">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
              <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                  <label for="inventory-current-shelf" class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Scaffale in cui stai lavorando") ?></label>
                  <select id="inventory-current-shelf" name="scaffale_corrente" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 w-full focus:outline-none focus:ring-2 focus:ring-gray-400">
                    <option value=""><?= __("Non indicato") ?></option>
                    <?php foreach ($scaffali as $s): ?>
                      <option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === $sessionShelf ? ' selected' : '' ?>><?= htmlspecialchars('[' . ((string) ($s['codice'] ?? '')) . '] ' . ((string) ($s['nome'] ?? '')), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="md:col-span-2">
                  <label for="inventory-code" class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Codice copia (inventario)") ?></label>
                  <div class="flex gap-2">
                    <input type="text" name="codice" id="inventory-code" maxlength="100" autofocus autocomplete="off" spellcheck="false"
                           placeholder="<?= htmlspecialchars(__('Inserisci o scansiona il codice inventario'), ENT_QUOTES, 'UTF-8') ?>"
                           class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 w-full focus:outline-none focus:ring-2 focus:ring-gray-400">
                    <button type="button" data-copy-scan data-copy-scan-target="inventory-code" aria-label="<?= htmlspecialchars(__('Scansiona'), ENT_QUOTES, 'UTF-8') ?>"
                            class="inline-flex items-center gap-2 whitespace-nowrap px-4 py-2 bg-gray-100 text-gray-900 border border-gray-300 rounded-lg hover:bg-gray-200 transition-colors font-medium">
                      <i class="fas fa-camera"></i><span class="hidden sm:inline"><?= __("Scansiona") ?></span>
                    </button>
                  </div>
                </div>
                <div class="pk-form-actions flex items-end">
                  <button type="submit" id="inventory-scan-submit" class="inline-flex items-center justify-center px-6 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors w-full">
                    <i class="fas fa-check mr-2"></i>
                    <?= __("Registra") ?>
                  </button>
                </div>
              </div>
            </form>
            <div id="inventory-feedback" class="mt-4 p-4 rounded-lg border hidden" role="status" aria-live="polite">
              <p class="font-medium" data-feedback-title></p>
              <p class="text-sm" data-feedback-detail></p>
            </div>
          </div>
        </div>

        <!-- Upload -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
          <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-900 flex items-center">
              <i class="fas fa-file-upload text-gray-600 mr-2"></i>
              <?= __("Carica un elenco di codici") ?>
            </h2>
            <p class="text-sm text-gray-600 mt-1"><?= __("Un file .txt con un codice per riga, o un .csv con il codice nella prima colonna (lettori offline, inventari fatti con altre app).") ?></p>
          </div>
          <div class="p-6">
            <form method="post" action="<?= htmlspecialchars(url($base . '/upload'), ENT_QUOTES, 'UTF-8') ?>" enctype="multipart/form-data" id="inventory-upload-form">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="scaffale_corrente" id="inventory-upload-shelf" value="<?= $sessionShelf > 0 ? $sessionShelf : '' ?>">
              <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="md:col-span-3">
                  <label for="inventory-file" class="text-sm font-medium text-gray-700 mb-1 block"><?= __("File") ?></label>
                  <input type="file" name="lista" id="inventory-file" accept=".txt,.csv,text/plain,text/csv" required class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-900 w-full focus:outline-none focus:ring-2 focus:ring-gray-400">
                </div>
                <div class="pk-form-actions flex items-end">
                  <button type="submit" class="inline-flex items-center justify-center px-6 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors w-full">
                    <i class="fas fa-upload mr-2"></i>
                    <?= __("Carica") ?>
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
    <?php endif; ?>

        <!-- Recent scans -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
          <div class="px-6 py-4 border-b border-gray-200 flex flex-col md:flex-row md:items-center md:justify-between gap-2">
            <div>
              <h2 class="text-xl font-semibold text-gray-900 flex items-center">
                <i class="fas fa-history text-gray-600 mr-2"></i>
                <?= __("Ultime scansioni") ?>
              </h2>
              <p class="text-sm text-gray-600 mt-1"><?= __("Le mie scansioni") ?>: <span data-counter="mie"><?= (int) ($summary['mie'] ?? 0) ?></span> · <?= __("Totale") ?>: <span data-counter="scansioni"><?= (int) ($summary['scansioni'] ?? 0) ?></span></p>
            </div>
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
              <input type="checkbox" id="inventory-mine" <?= $mineOnly ? 'checked' : '' ?>>
              <?= __("Solo le mie scansioni") ?>
            </label>
          </div>
          <div class="p-6">
            <div class="border border-gray-200 rounded-lg overflow-hidden">
              <div class="max-h-96 overflow-y-auto">
                <table class="w-full text-sm" id="inventory-scans">
                  <thead class="bg-gray-50 border-b border-gray-200 sticky top-0">
                    <tr>
                      <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __('Esito') ?></th>
                      <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __('Codice copia') ?></th>
                      <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __('Titolo') ?></th>
                      <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __('Operatore') ?></th>
                      <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __('Data scansione') ?></th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-100" id="inventory-scans-body">
                    <?php if ($scans === []): ?>
                    <tr data-empty>
                      <td colspan="5" class="px-4 py-8 text-center text-gray-500"><i class="fas fa-inbox mr-2"></i><?= __("Nessuna scansione registrata.") ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($scans as $scan): $esito = (string) $scan['esito']; ?>
                    <tr class="hover:bg-gray-50" data-esito="<?= htmlspecialchars($esito, ENT_QUOTES, 'UTF-8') ?>">
                      <td class="px-4 py-3"><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $outcomeBadges[$esito] ?? 'bg-gray-100 text-gray-800' ?>"><?= htmlspecialchars($outcomeLabels[$esito] ?? $esito, ENT_QUOTES, 'UTF-8') ?></span></td>
                      <td class="px-4 py-3"><code class="bg-gray-100 px-2 py-1 rounded text-xs font-mono"><?= htmlspecialchars((string) $scan['codice'], ENT_QUOTES, 'UTF-8') ?></code></td>
                      <td class="px-4 py-3 text-gray-900 font-medium"><?= htmlspecialchars((string) ($scan['titolo'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                      <td class="px-4 py-3 text-gray-700 text-xs"><?= htmlspecialchars((string) ($scan['operatore'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                      <td class="px-4 py-3 text-gray-600 text-xs"><?= htmlspecialchars(format_date((string) $scan['scansionato_il'], true, '/'), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

    <?php if ($isOpen): ?>
        <!-- Close / cancel -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
          <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-900 flex items-center">
              <i class="fas fa-flag-checkered text-gray-600 mr-2"></i>
              <?= __("Chiudi la revisione") ?>
            </h2>
            <p class="text-sm text-gray-600 mt-1"><?= __("Scegli cosa fare con le copie mancanti. La disponibilità dei libri viene ricalcolata.") ?></p>
          </div>
          <div class="p-6 space-y-4">
            <form method="post" action="<?= htmlspecialchars(url($base . '/close'), ENT_QUOTES, 'UTF-8') ?>" id="inventory-close-form"
                  data-swal-confirm="<?= htmlspecialchars(__('Chiudere la revisione? Non sarà più possibile registrare scansioni.'), ENT_QUOTES, 'UTF-8') ?>"
                  data-swal-confirm-button="<?= htmlspecialchars(__('Chiudi revisione'), ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
              <div class="space-y-2 mb-4">
                <label class="flex items-start gap-2 text-sm text-gray-700">
                  <input type="radio" name="azione_mancanti" value="nessuna" checked class="mt-1">
                  <span><?= __("Lascia le copie mancanti come sono") ?></span>
                </label>
                <label class="flex items-start gap-2 text-sm <?= $isAdmin ? 'text-gray-700' : 'text-gray-400' ?>">
                  <input type="radio" name="azione_mancanti" value="perso" class="mt-1" <?= $isAdmin ? '' : 'disabled' ?>>
                  <span>
                    <?= __("Segna come perse le copie mancanti") ?>
                    <span class="block text-xs text-gray-500"><?= $isAdmin
                        ? __("Le copie in prestito, in restauro, in trasferimento o in manutenzione non vengono toccate.")
                        : __("Solo un amministratore può segnare come perse le copie mancanti.") ?></span>
                  </span>
                </label>
              </div>
              <button type="submit" class="inline-flex items-center justify-center px-6 py-2 bg-gray-800 text-white hover:bg-gray-700 rounded-lg transition-colors">
                <i class="fas fa-flag-checkered mr-2"></i>
                <?= __("Chiudi revisione") ?>
              </button>
            </form>
            <form method="post" action="<?= htmlspecialchars(url($base . '/cancel'), ENT_QUOTES, 'UTF-8') ?>" id="inventory-cancel-form"
                  data-swal-confirm="<?= htmlspecialchars(__('Annullare la revisione? Le scansioni restano registrate ma nessuna copia viene modificata.'), ENT_QUOTES, 'UTF-8') ?>"
                  data-swal-confirm-button="<?= htmlspecialchars(__('Annulla revisione'), ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
              <button type="submit" class="inline-flex items-center justify-center px-6 py-2 bg-red-600 text-white hover:bg-red-700 rounded-lg transition-colors">
                <i class="fas fa-ban mr-2"></i>
                <?= __("Annulla revisione") ?>
              </button>
            </form>
          </div>
        </div>
    <?php else: ?>
        <!-- Final report -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
          <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-900 flex items-center">
              <i class="fas fa-file-alt text-gray-600 mr-2"></i>
              <?= __("Report finale") ?>
            </h2>
            <?php if (!empty($session['chiusa_il'])): ?>
            <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($stateLabels[(string) $session['stato']] ?? '', ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(format_date((string) $session['chiusa_il'], true, '/'), ENT_QUOTES, 'UTF-8') ?><?php if (!empty($session['chiusa_da_nome'])): ?> · <?= htmlspecialchars((string) $session['chiusa_da_nome'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></p>
            <?php endif; ?>
          </div>
          <div class="p-6">
            <div class="border border-gray-200 rounded-lg overflow-hidden">
              <div class="max-h-96 overflow-y-auto">
                <table class="w-full text-sm" id="inventory-report">
                  <thead class="bg-gray-50 border-b border-gray-200 sticky top-0">
                    <tr>
                      <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __('Esito') ?></th>
                      <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __('Codice copia') ?></th>
                      <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __('Titolo') ?></th>
                      <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __('Collocazione') ?></th>
                      <th class="px-4 py-2 text-left font-semibold text-gray-700"><?= __('Stato copia') ?></th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-100">
                    <?php foreach ($reportRows as $row): $cat = $row['categoria']; ?>
                    <tr class="hover:bg-gray-50" data-categoria="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>">
                      <td class="px-4 py-3"><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $outcomeBadges[$cat] ?? 'bg-gray-100 text-gray-800' ?>"><?= htmlspecialchars($outcomeLabels[$cat] ?? $cat, ENT_QUOTES, 'UTF-8') ?></span></td>
                      <td class="px-4 py-3"><code class="bg-gray-100 px-2 py-1 rounded text-xs font-mono"><?= htmlspecialchars($row['codice'], ENT_QUOTES, 'UTF-8') ?></code></td>
                      <td class="px-4 py-3 text-gray-900 font-medium"><?= htmlspecialchars($row['titolo'], ENT_QUOTES, 'UTF-8') ?></td>
                      <td class="px-4 py-3 text-gray-700 text-xs"><?= htmlspecialchars($row['collocazione'], ENT_QUOTES, 'UTF-8') ?></td>
                      <td class="px-4 py-3 text-gray-600 text-xs"><?= htmlspecialchars($row['stato_copia'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
    <?php endif; ?>

    </div>
  </div>
</div>

<?php if ($isOpen): ?>
<?php include __DIR__ . '/../partials/copy-scanner-i18n.php'; ?>
<script>
(function () {
  var config = <?= json_encode([
      'scanUrl' => url($base . '/scan'),
      'stateUrl' => url($base . '/state'),
      'pageUrl' => url($base),
      'csrf' => \App\Support\Csrf::ensureToken(),
      'mine' => $mineOnly,
      'badges' => $outcomeBadges,
      'i18n' => [
          'error' => __('Impossibile registrare la scansione. Riprova.'),
          'noScans' => __('Nessuna scansione registrata.'),
          'expectedShelf' => __('Scaffale atteso'),
          'unknown' => __('Il codice non corrisponde a nessuna copia a catalogo.'),
          'duplicate' => __('Questa copia è già stata registrata in questa revisione.'),
          'outOfScope' => __('La copia esiste ma non rientra nel filtro della revisione.'),
          'onLoan' => __('La copia risulta in prestito o in attesa di ritiro: verifica il prestito.'),
          'closed' => __('La revisione è stata chiusa da un altro operatore.'),
      ],
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var form = document.getElementById('inventory-scan-form');
  var input = document.getElementById('inventory-code');
  var shelf = document.getElementById('inventory-current-shelf');
  var uploadShelf = document.getElementById('inventory-upload-shelf');
  var feedback = document.getElementById('inventory-feedback');
  var mine = document.getElementById('inventory-mine');
  var body = document.getElementById('inventory-scans-body');
  var busy = false;
  var queue = [];
  var refreshing = null; // AbortController of the refresh in flight, if any
  var tones = { trovata: [880, 0.12], fuori_posto: [520, 0.25], in_prestito: [660, 0.25], fuori_ambito: [440, 0.3], duplicata: [330, 0.2], sconosciuta: [220, 0.45] };
  var feedbackClasses = {
    trovata: 'bg-green-50 border-green-200 text-green-800',
    fuori_posto: 'bg-amber-50 border-amber-200 text-amber-800',
    in_prestito: 'bg-blue-50 border-blue-200 text-blue-800',
    fuori_ambito: 'bg-purple-50 border-purple-200 text-purple-800',
    duplicata: 'bg-gray-50 border-gray-200 text-gray-800',
    sconosciuta: 'bg-red-50 border-red-200 text-red-800'
  };
  var audio = null;

  function beep(outcome) {
    try {
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      var tone = tones[outcome] || tones.sconosciuta;
      var osc = audio.createOscillator();
      var gain = audio.createGain();
      osc.frequency.value = tone[0];
      gain.gain.value = 0.08;
      osc.connect(gain); gain.connect(audio.destination);
      osc.start(); osc.stop(audio.currentTime + tone[1]);
    } catch (e) { /* sound is a courtesy */ }
  }

  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
  }

  function setCounters(summary) {
    Object.keys(summary || {}).forEach(function (key) {
      document.querySelectorAll('[data-counter="' + key + '"]').forEach(function (el) { el.textContent = summary[key]; });
    });
  }

  function showFeedback(outcome, title, detail) {
    feedback.className = 'mt-4 p-4 rounded-lg border ' + (feedbackClasses[outcome] || feedbackClasses.sconosciuta);
    feedback.setAttribute('data-esito', outcome);
    feedback.querySelector('[data-feedback-title]').textContent = title;
    feedback.querySelector('[data-feedback-detail]').textContent = detail;
  }

  function detailFor(result) {
    var parts = [result.codice];
    if (result.titolo) { parts.push(result.titolo); }
    if (result.collocazione) { parts.push(result.collocazione); }
    if (result.esito === 'fuori_posto' && result.scaffale_atteso) { parts.push(config.i18n.expectedShelf + ': ' + result.scaffale_atteso); }
    if (result.esito === 'sconosciuta') { parts.push(config.i18n.unknown); }
    if (result.esito === 'duplicata') { parts.push(config.i18n.duplicate); }
    if (result.esito === 'fuori_ambito') { parts.push(config.i18n.outOfScope); }
    if (result.esito === 'in_prestito') { parts.push(config.i18n.onLoan); }
    return parts.join(' · ');
  }

  function renderScans(scans) {
    if (!scans.length) {
      body.innerHTML = '<tr data-empty><td colspan="5" class="px-4 py-8 text-center text-gray-500"><i class="fas fa-inbox mr-2"></i>' + escapeHtml(config.i18n.noScans) + '</td></tr>';
      return;
    }
    body.innerHTML = scans.map(function (s) {
      return '<tr class="hover:bg-gray-50" data-esito="' + escapeHtml(s.esito) + '">'
        + '<td class="px-4 py-3"><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' + (config.badges[s.esito] || 'bg-gray-100 text-gray-800') + '">' + escapeHtml(s.label) + '</span></td>'
        + '<td class="px-4 py-3"><code class="bg-gray-100 px-2 py-1 rounded text-xs font-mono">' + escapeHtml(s.codice) + '</code></td>'
        + '<td class="px-4 py-3 text-gray-900 font-medium">' + escapeHtml(s.titolo) + '</td>'
        + '<td class="px-4 py-3 text-gray-700 text-xs">' + escapeHtml(s.operatore) + '</td>'
        + '<td class="px-4 py-3 text-gray-600 text-xs">' + escapeHtml(s.quando) + '</td>'
        + '</tr>';
    }).join('');
  }

  function refresh() {
    // One refresh at a time: a poll, a scan and the "only mine" toggle each
    // start one, and an older answer must not overwrite a newer state.
    if (refreshing) { refreshing.abort(); }
    var controller = new AbortController();
    refreshing = controller;
    return fetch(config.stateUrl + (config.mine ? '?mine=1' : ''), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', signal: controller.signal })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (refreshing !== controller) { return; } // superseded after its body was read
        refreshing = null;
        if (!data || !data.success) { return; }
        setCounters(data.summary);
        renderScans(data.scans || []);
        if (data.stato !== 'aperta') {
          showFeedback('duplicata', config.i18n.closed, '');
          window.location.assign(config.pageUrl);
        }
      })
      .catch(function (e) {
        if (refreshing === controller) { refreshing = null; }
        // An aborted refresh was replaced by a newer one; a failed one is retried by the next poll.
        if (e && e.name === 'AbortError') { return; }
      });
  }

  function send(code) {
    busy = true;
    var payload = { codice: code, scaffale_corrente: shelf.value || null };
    return fetch(config.scanUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': config.csrf },
      body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
      .then(function (data) {
        if (!data || !data.success) {
          showFeedback('sconosciuta', (data && data.message) || config.i18n.error, code);
          beep('sconosciuta');
          return;
        }
        var result = data.result;
        showFeedback(result.esito, result.label, detailFor(result));
        beep(result.esito);
        setCounters(data.summary);
        return refresh();
      })
      .catch(function () {
        showFeedback('sconosciuta', config.i18n.error, code);
      })
      .then(function () {
        busy = false;
        if (queue.length) { send(queue.shift()); }
      });
  }

  function submitCode() {
    var code = (input.value || '').trim();
    input.value = '';
    input.focus();
    if (!code) { return; }
    // Wedge scanners can fire codes faster than the server answers: keep their order.
    if (busy) { queue.push(code); } else { send(code); }
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); submitCode(); });
  // The camera scanner fills the field and fires "change": record it straight away.
  input.addEventListener('change', function () { if (input.value.trim() !== '') { submitCode(); } });
  shelf.addEventListener('change', function () { if (uploadShelf) { uploadShelf.value = shelf.value; } input.focus(); });
  mine.addEventListener('change', function () {
    config.mine = mine.checked;
    var url = new URL(window.location.href);
    if (config.mine) { url.searchParams.set('mine', '1'); } else { url.searchParams.delete('mine'); }
    window.history.replaceState(null, '', url.toString());
    refresh();
  });
  // Other operators scan in parallel: keep the counters current.
  window.setInterval(function () { if (!busy && !document.hidden) { refresh(); } }, 5000);
  input.focus();
})();
</script>
<script src="<?= htmlspecialchars(assetUrl('copy-scanner.bundle.js'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php else: ?>
<script>
document.getElementById('inventory-mine').addEventListener('change', function () {
  var url = new URL(window.location.href);
  if (this.checked) { url.searchParams.set('mine', '1'); } else { url.searchParams.delete('mine'); }
  window.location.assign(url.toString());
});
</script>
<?php endif; ?>

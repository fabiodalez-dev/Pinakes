<?php
use App\Support\ConfigStore;
$isCatalogueMode = ConfigStore::isCatalogueMode();

$status = strtolower((string)($libro['stato'] ?? ''));
$statusClasses = [
    'disponibile' => 'inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold bg-green-500 text-white',
    'prestato'    => 'inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold bg-red-500 text-white',
    'in_ritardo'  => 'inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold bg-amber-500 text-white',
    'danneggiato' => 'inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold bg-orange-500 text-white',
    'perso'       => 'inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold bg-gray-700 text-white',
];
$statusBadgeClass = $statusClasses[$status] ?? 'inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold bg-slate-500 text-white';

$btnPrimary = 'inline-flex items-center gap-2 rounded-lg bg-gray-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-gray-700';
$btnGhost   = 'inline-flex items-center gap-2 rounded-lg border-2 border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-100';
$btnDanger  = 'inline-flex items-center gap-2 rounded-lg border-2 border-red-300 px-5 py-2.5 text-sm font-semibold text-red-700 transition hover:bg-red-50';

?>

<section class="min-h-screen bg-gray-50 py-6">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
<?php if (isset($_SESSION['success_message'])): ?>
  <div class="mb-6 p-4 rounded-xl border border-green-200 bg-green-50 text-green-700" role="alert">
    <i class="fas fa-check-circle mr-2"></i>
    <?php echo App\Support\HtmlHelper::e($_SESSION['success_message']); ?>
  </div>
  <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<!-- Hero Section with Breadcrumb -->
<div class="mb-6">
  <!-- Breadcrumb - Higher within hero -->
  <nav aria-label="breadcrumb" class="mb-4">
    <ol class="flex items-center space-x-2 text-sm">
      <li>
        <a href="/admin/dashboard" class="text-gray-500 hover:text-gray-700 transition-colors">
          <i class="fas fa-home mr-1"></i><?= __("Home") ?>
        </a>
      </li>
      <li>
        <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
      </li>
      <li>
        <a href="/admin/libri" class="text-gray-500 hover:text-gray-700 transition-colors">
          <i class="fas fa-book mr-1"></i><?= __("Libri") ?>
        </a>
      </li>
      <li>
        <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
      </li>
      <li class="text-gray-900 font-medium">
        <span><?php echo App\Support\HtmlHelper::e(mb_substr($libro['titolo'] ?? '', 0, 30) . (mb_strlen($libro['titolo'] ?? '') > 30 ? '...' : '')); ?></span>
      </li>
    </ol>
  </nav>
  
  <!-- Header: title + actions -->
  <div class="flex flex-col gap-4">
      <!-- Title and subtitle: full width -->
      <div class="flex flex-col gap-2">
        <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-3">
          <i class="fas fa-book text-gray-600"></i>
          <?php echo App\Support\HtmlHelper::e($libro['titolo'] ?? ''); ?>
        </h1>
        <?php if (!empty($libro['sottotitolo'])): ?>
          <div class="text-gray-600 mt-1"><?php echo App\Support\HtmlHelper::e($libro['sottotitolo']); ?></div>
        <?php endif; ?>
      </div>

      <!-- Action buttons: responsive layout below title -->
      <div class="flex flex-col lg:flex-row lg:flex-wrap items-stretch lg:items-center gap-3">
        <!-- Primo blocco: Stampa etichetta e Visualizza frontend: 50% each su mobile -->
        <div class="flex gap-3 w-full lg:w-auto">
          <!-- Stampa etichetta -->
          <a href="/api/libri/<?php echo (int)$libro['id']; ?>/etichetta-pdf" target="_blank" class="<?php echo $btnGhost; ?> flex-1 lg:flex-none justify-center">
            <i class="fas fa-barcode"></i>
            <?= __("Stampa etichetta") ?>
          </a>
          <!-- Visualizza nel frontend -->
          <a href="<?php echo htmlspecialchars(book_url($libro), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="<?php echo $btnGhost; ?> flex-1 lg:flex-none justify-center">
            <i class="fas fa-eye"></i>
            <?= __("Visualizza") ?>
          </a>
        </div>

        <!-- Secondo blocco: Modifica ed Elimina: 50% each su mobile, inline su desktop -->
        <div class="flex gap-3 w-full lg:w-auto">
          <a href="/admin/libri/modifica/<?php echo (int)$libro['id']; ?>" class="<?php echo $btnGhost; ?> flex-1 lg:flex-none justify-center">
            <i class="fas fa-edit"></i>
            <?= __("Modifica") ?>
          </a>
          <?php if (!empty($activeLoan) && (int)($activeLoan['attivo'] ?? 0) === 1 && !$isCatalogueMode): ?>
          <button type="button" id="open-return-modal" class="<?php echo $btnPrimary; ?> flex-1 lg:flex-none justify-center">
            <i class="fas fa-undo"></i>
            <?= __("Restituzione") ?>
          </button>
          <?php endif; ?>
          <form id="delete-book" method="post" action="/admin/libri/delete/<?php echo (int)$libro['id']; ?>" onsubmit="return confirmDeleteBook(event);" class="flex-1 lg:flex-none">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="<?php echo $btnDanger; ?> w-full">
              <i class="fas fa-trash"></i>
              <?= __("Elimina") ?>
            </button>
          </form>
        </div>
      </div>
  </div>
</div>

  <!-- Main content -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Left: Cover + quick info -->
    <div class="lg:col-span-1">
      <div class="card overflow-hidden">
        <?php 
          $cover = (string)($libro['copertina_url'] ?? '');
          if ($cover === '' && !empty($libro['copertina'])) { $cover = (string)$libro['copertina']; }
          if ($cover !== '' && strncmp($cover, 'uploads/', 8) === 0) { $cover = '/' . $cover; }
          if ($cover === '') { $cover = '/uploads/copertine/placeholder.jpg'; }
        ?>
        <div class="p-4 flex items-center justify-center bg-gray-50">
          <img src="<?php echo htmlspecialchars($cover, ENT_QUOTES, 'UTF-8'); ?>"
               onerror="this.src='/uploads/copertine/placeholder.jpg'"
               alt="<?php echo htmlspecialchars(($libro['titolo'] ?? 'Libro') . ' - Copertina', ENT_QUOTES, 'UTF-8'); ?>"
               class="max-h-80 object-contain rounded-lg shadow" />
        </div>
        <div class="p-4 space-y-3">
          <?php if (!empty($libro['stato'])): ?>
          <div>
            <span class="<?php echo $statusBadgeClass; ?>">
              <i class="fas fa-circle text-[8px]"></i>
              <?php echo App\Support\HtmlHelper::e(ucfirst($libro['stato'])); ?>
            </span>
          </div>
          <?php endif; ?>
          <div class="text-base text-gray-600">
            <i class="fas fa-building text-gray-400 mr-2"></i>
            <span class="font-medium"><?= __("Editore:") ?></span>
            <?php echo App\Support\HtmlHelper::e($libro['editore_nome'] ?? __('Non specificato')); ?>
          </div>
          <div class="text-base text-gray-600">
            <i class="fas fa-users text-gray-400 mr-2"></i>
            <span class="font-medium"><?= __("Autori:") ?></span>
            <div class="mt-2 flex flex-wrap gap-2">
              <?php
                $autori = $libro['autori'] ?? [];
                if (is_array($autori) && count($autori) > 0):
                  foreach ($autori as $a):
                    $label = trim((string)($a['nome'] ?? ''));
                    if ($label === '') continue;
              ?>
                <a href="/admin/autori/<?php echo (int)($a['id'] ?? 0); ?>"
                   class="inline-flex items-center px-2 py-1 rounded-full text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 transition">
                  <i class="fas fa-user mr-1"></i><?php echo App\Support\HtmlHelper::e($label); ?>
                </a>
             <?php endforeach; else: ?>
                <span class="text-gray-400"><?= __("Non specificato") ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="text-base text-gray-600">
            <i class="fas fa-layer-group text-gray-400 mr-2"></i>
            <span class="font-medium"><?= __("Genere:") ?></span>
            <?php
              $pathParts = [];
              if (!empty($libro['radice_nome'])) $pathParts[] = (string)$libro['radice_nome'];
              if (!empty($libro['genere_nome'])) {
                $genName = (string)$libro['genere_nome'];
                if (strpos($genName, ' - ') !== false) {
                  $parts = explode(' - ', $genName);
                  $genName = end($parts);
                }
                $pathParts[] = $genName;
              }
              if (!empty($libro['sottogenere_nome'])) $pathParts[] = (string)$libro['sottogenere_nome'];
              $path = implode(' → ', array_map('App\\Support\\HtmlHelper::e', $pathParts));
            ?>
            <a href="/admin/generi/<?php echo !empty($libro['sottogenere_id']) ? (int)$libro['sottogenere_id'] : (!empty($libro['genere_id']) ? (int)$libro['genere_id'] : (int)$libro['radice_id']); ?>" class="text-gray-900 hover:text-gray-600 hover:underline font-semibold">
              <?php echo $path !== '' ? $path : __('Non specificato'); ?>
            </a>
          </div>
          <div class="text-base text-gray-600">
            <i class="fas fa-barcode text-gray-400 mr-2"></i>
            <span class="font-medium"><?= __("ISBN") ?>:</span>
            <?php echo App\Support\HtmlHelper::e(($libro['isbn13'] ?? '') ?: ($libro['isbn10'] ?? __('Non specificato'))); ?>
          </div>
      </div>
    </div>
      <?php if (!empty($activeLoan) && (int)$activeLoan['attivo'] === 1 && !$isCatalogueMode): ?>
      <div class="card mt-4">
        <div class="card-header">
          <h3 class="text-base font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-clock text-yellow-600"></i>
            <?= __("Prestito attivo") ?>
          </h3>
        </div>
        <div class="card-body space-y-3 text-sm text-gray-700">
          <div class="flex items-center justify-between">
            <span class="font-medium"><?= __("Utente") ?></span>
            <span class="text-right">
              <?php echo App\Support\HtmlHelper::e($activeLoan['utente_nome'] ?? __('Sconosciuto')); ?><br>
              <span class="text-xs text-gray-500"><?php echo App\Support\HtmlHelper::e($activeLoan['utente_email'] ?? ''); ?></span>
            </span>
          </div>
          <div class="flex items-center justify-between">
            <span class="font-medium"><?= __("Dal") ?></span>
            <span><?php echo App\Support\HtmlHelper::e($activeLoan['data_prestito'] ?? ''); ?></span>
          </div>
          <div class="flex items-center justify-between">
            <span class="font-medium"><?= __("Scadenza") ?></span>
            <?php $isLate = strtotime($activeLoan['data_scadenza'] ?? '1970-01-01') < strtotime(date('Y-m-d')); ?>
            <span class="<?php echo $isLate ? 'text-red-600 font-semibold' : ''; ?>"><?php echo App\Support\HtmlHelper::e($activeLoan['data_scadenza'] ?? ''); ?></span>
          </div>
          <div class="flex items-center justify-between">
            <span class="font-medium"><?= __("Rinnovi effettuati") ?></span>
            <span class="inline-flex items-center px-2 py-0.5 rounded bg-gray-100 text-gray-800">
              <i class="fas fa-redo-alt mr-1 text-xs"></i>
              <?php echo (int)($activeLoan['renewals'] ?? 0); ?> / 3
            </span>
          </div>
          <?php if (!empty($activeLoan['processed_by_name'])): ?>
          <div class="flex items-center justify-between">
            <span class="font-medium"><?= __("Gestito da") ?></span>
            <span><?php echo App\Support\HtmlHelper::e($activeLoan['processed_by_name']); ?></span>
          </div>
          <?php endif; ?>
          <?php if (!empty($activeLoan['note'])): ?>
          <div>
            <span class="font-medium"><?= __("Note") ?></span>
            <p class="mt-1 text-gray-600"><?php echo App\Support\HtmlHelper::e($activeLoan['note']); ?></p>
          </div>
          <?php endif; ?>

          <div class="pt-3 border-t border-gray-200 space-y-2">
            <?php
              $maxRenewals = 3;
              $currentRenewals = (int)($activeLoan['renewals'] ?? 0);
              $canRenew = !$isLate && $currentRenewals < $maxRenewals;
            ?>

            <?php if ($canRenew): ?>
            <form method="post" action="/admin/prestiti/rinnova/<?php echo (int)$activeLoan['id']; ?>" onsubmit="return confirmRenewal(event);">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars('/admin/libri/' . (int)($libro['id'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
              <button type="submit" class="<?php echo $btnPrimary; ?> w-full justify-center">
                <i class="fas fa-redo-alt"></i> <?= __("Rinnova prestito (+14 giorni)") ?>
              </button>
            </form>
            <?php elseif ($isLate): ?>
            <div class="px-3 py-2 rounded-lg bg-red-50 border border-red-200 text-red-700 text-xs text-center" role="alert">
              <i class="fas fa-exclamation-triangle mr-1"></i>
              <?= __("Non rinnovabile: prestito in ritardo") ?>
            </div>
            <?php elseif ($currentRenewals >= $maxRenewals): ?>
            <div class="px-3 py-2 rounded-lg bg-yellow-50 border border-yellow-200 text-yellow-700 text-xs text-center" role="alert">
              <i class="fas fa-info-circle mr-1"></i>
              <?= __("Limite massimo rinnovi raggiunto") ?> (<?php echo $maxRenewals; ?>)
            </div>
            <?php endif; ?>

            <button type="button" id="open-return-modal-secondary" class="<?php echo $btnPrimary; ?> w-full justify-center">
              <i class="fas fa-undo mr-2"></i><?= __("Registra restituzione") ?>
            </button>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Right: Details -->
    <div class="lg:col-span-2 space-y-6">
      <!-- Metadata grid -->
      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-info-circle text-primary"></i>
            <?= __("Dettagli Libro") ?>
          </h2>
        </div>
        <div class="card-body">
          <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <?php if (!empty($libro['isbn10'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("ISBN10") ?></dt>
              <dd class="text-gray-900 font-medium"><?php echo App\Support\HtmlHelper::e($libro['isbn10']); ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['isbn13'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("ISBN13") ?></dt>
              <dd class="text-gray-900 font-medium"><?php echo App\Support\HtmlHelper::e($libro['isbn13']); ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['ean'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("EAN") ?></dt>
              <dd class="text-gray-900 font-medium"><?php echo App\Support\HtmlHelper::e($libro['ean']); ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['edizione'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("Edizione") ?></dt>
              <dd class="text-gray-900 font-medium"><?php echo App\Support\HtmlHelper::e($libro['edizione']); ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['anno_pubblicazione'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("Anno pubblicazione") ?></dt>
              <dd class="text-gray-900 font-medium"><?php echo (int)$libro['anno_pubblicazione']; ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['data_pubblicazione'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("Data di pubblicazione") ?></dt>
              <dd class="text-gray-900 font-medium"><?php echo App\Support\HtmlHelper::e(format_date($libro['data_pubblicazione'])); ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['collana'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("Collana") ?></dt>
              <dd class="text-gray-900 font-medium">
                <a href="/admin/libri?collana=<?php echo urlencode($libro['collana']); ?>"
                   class="text-gray-700 hover:text-gray-900 hover:underline transition-colors">
                  <?php echo App\Support\HtmlHelper::e($libro['collana']); ?>
                </a>
              </dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['numero_serie'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("Numero serie") ?></dt>
              <dd class="text-gray-900 font-medium"><?php echo App\Support\HtmlHelper::e($libro['numero_serie']); ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['numero_pagine'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("Pagine") ?></dt>
              <dd class="text-gray-900 font-medium"><?php echo (int)$libro['numero_pagine']; ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['dimensioni'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("Dimensioni") ?></dt>
              <dd class="text-gray-900 font-medium"><?php echo App\Support\HtmlHelper::e($libro['dimensioni']); ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['formato'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("Formato") ?></dt>
              <dd class="text-gray-900 font-medium"><?php echo App\Support\HtmlHelper::e($libro['formato']); ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['lingua'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("Lingua") ?></dt>
              <dd class="text-gray-900 font-medium"><?php echo App\Support\HtmlHelper::e($libro['lingua']); ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['peso'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("Peso") ?></dt>
              <dd class="text-gray-900 font-medium"><?php echo htmlspecialchars((string)$libro['peso'], ENT_QUOTES, 'UTF-8'); ?> <?= __("kg") ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['prezzo'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("Prezzo") ?></dt>
              <dd class="text-gray-900 font-medium">€ <?php echo htmlspecialchars(number_format((float)$libro['prezzo'], 2, ',', '.'), ENT_QUOTES, 'UTF-8'); ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['data_acquisizione'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("Data acquisizione") ?></dt>
              <dd class="text-gray-900 font-medium"><?php echo App\Support\HtmlHelper::e(format_date($libro['data_acquisizione'])); ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['tipo_acquisizione'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("Tipo acquisizione") ?></dt>
              <dd class="text-gray-900 font-medium"><?php echo App\Support\HtmlHelper::e($libro['tipo_acquisizione']); ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['traduttore'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("Traduttore") ?></dt>
              <dd class="text-gray-900 font-medium"><?php echo App\Support\HtmlHelper::e($libro['traduttore']); ?></dd>
            </div>
            <?php endif; ?>
            <div class="sm:col-span-2">
              <dt class="text-xs uppercase text-gray-500"><?= __("Classificazione Dewey") ?></dt>
              <dd class="text-gray-900 font-medium">
                <span class="font-mono"><?php echo App\Support\HtmlHelper::e($libro['classificazione_dewey'] ?? ''); ?></span>
                <span id="dewey_name_display" class="text-sm text-gray-600 italic ml-2"></span>
                <script>
                (async function() {
                  const displayEl = document.getElementById('dewey_name_display');
                  const code = <?php echo json_encode($libro['classificazione_dewey'] ?? ''); ?>;
                  if (!displayEl || !code) return;

                  // Se è nel vecchio formato (300-340-347), prendi solo l'ultimo valore
                  const parts = code.split('-');
                  const finalCode = parts.length > 1 ? parts[parts.length - 1] : code;

                  // Funzione per ottenere il parent code
                  const getParentCode = (c) => {
                    if (!c.includes('.')) return null;
                    const p = c.split('.');
                    const intPart = p[0];
                    const decPart = p[1];
                    if (decPart.length === 1) return intPart;
                    return `${intPart}.${decPart.substring(0, decPart.length - 1)}`;
                  };

                  try {
                    const response = await fetch(`/api/dewey/search?code=${encodeURIComponent(finalCode)}`, {
                      credentials: 'same-origin'
                    });
                    const result = response.ok ? await response.json() : null;

                    if (result && result.name) {
                      displayEl.textContent = `— ${result.name}`;
                    } else {
                      // Non trovato o 404, cerca parent
                      const parentCode = getParentCode(finalCode);
                      if (parentCode) {
                        const parentResponse = await fetch(`/api/dewey/search?code=${encodeURIComponent(parentCode)}`, {
                          credentials: 'same-origin'
                        });
                        const parentResult = parentResponse.ok ? await parentResponse.json() : null;

                        if (parentResult && parentResult.name) {
                          displayEl.textContent = `— ${parentResult.name} > ${finalCode}`;
                        }
                      }
                    }
                  } catch (e) {
                    console.error('Dewey name fetch error:', e);
                  }
                })();
                </script>
              </dd>
            </div>
            <?php 
              $scCod = (string)($libro['scaffale_codice'] ?? '');
              $scNome = (string)($libro['scaffale_nome'] ?? '');
              $msLvl = (string)($libro['mensola_livello'] ?? '');
              $posProgressiva = (int)($libro['posizione_progressiva'] ?? 0);
              $posCollocation = (string)($libro['collocazione'] ?? '');
              $posLabel = '';
              if ($posCollocation !== '') {
                $posLabel = $posCollocation;
              } elseif ($scCod !== '') {
                $parts = ['['.$scCod.']'];
                if ($scNome !== '') { $parts[] = $scNome; }
                if ($msLvl !== '') { $parts[] = __('Mensola').' '.$msLvl; }
                if ($posProgressiva > 0) { $parts[] = __('Pos').' '.str_pad((string)$posProgressiva, 2, '0', STR_PAD_LEFT); }
                $posLabel = implode(' — ', $parts);
              }
            ?>
            <div class="sm:col-span-2">
              <dt class="text-xs uppercase text-gray-500"><?= __("Collocazione") ?></dt>
              <dd class="text-gray-900 font-medium"><?php echo $posLabel !== '' ? App\Support\HtmlHelper::e($posLabel) : __('Non specificato'); ?></dd>
            </div>
            <?php if (!empty($libro['numero_inventario'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("Numero inventario") ?></dt>
              <dd class="text-gray-900 font-medium"><?php echo App\Support\HtmlHelper::e($libro['numero_inventario']); ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['parole_chiave'])): ?>
            <div class="sm:col-span-2">
              <dt class="text-xs uppercase text-gray-500"><?= __("Parole chiave") ?></dt>
              <dd class="text-gray-900 font-medium">
                <?php
                  $keywords = array_map('trim', explode(',', $libro['parole_chiave']));
                  foreach ($keywords as $keyword):
                    if (empty($keyword)) continue;
                ?>
                  <a href="/admin/libri?keywords=<?php echo urlencode($keyword); ?>"
                     class="inline-block px-2 py-1 mr-2 mb-2 text-xs bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-full transition-colors">
                    <i class="fas fa-tag mr-1"></i><?php echo App\Support\HtmlHelper::e($keyword); ?>
                  </a>
                <?php endforeach; ?>
              </dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['stato'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("Stato") ?></dt>
              <dd>
                <?php $st = strtolower((string)($libro['stato'])); $cls = 'bg-gray-100 text-gray-800';
                  if ($st === 'disponibile') $cls = 'bg-green-500 text-white';
                  elseif (in_array($st, ['prestato','in_ritardo'])) $cls = 'bg-red-100 text-red-800';
                  elseif (in_array($st, ['danneggiato','perso'])) $cls = 'bg-yellow-100 text-yellow-800'; ?>
                <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $cls; ?>"><?php echo App\Support\HtmlHelper::e($libro['stato'] ?? __('Non specificato')); ?></span>
              </dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['file_url'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("File") ?></dt>
              <dd><a class="text-gray-700 hover:text-gray-900 hover:underline" href="<?php echo htmlspecialchars($libro['file_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?= __("Apri") ?></a></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['audio_url'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("Audio") ?></dt>
              <dd><a class="text-gray-700 hover:text-gray-900 hover:underline" href="<?php echo htmlspecialchars($libro['audio_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?= __("Apri") ?></a></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['created_at'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("Data creazione") ?></dt>
              <dd class="text-gray-900 font-medium"><?php echo App\Support\HtmlHelper::e(format_date($libro['created_at'], true, '/')); ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($libro['updated_at'])): ?>
            <div>
              <dt class="text-xs uppercase text-gray-500"><?= __("Ultima modifica") ?></dt>
              <dd class="text-gray-900 font-medium"><?php echo App\Support\HtmlHelper::e(format_date($libro['updated_at'], true, '/')); ?></dd>
            </div>
            <?php endif; ?>
          </dl>
        </div>
      </div>

      <!-- Description -->
      <?php if (!empty($libro['descrizione'])): ?>
      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-align-left text-primary"></i>
            <?= __("Descrizione") ?>
          </h2>
        </div>
        <div class="card-body">
          <div class="prose prose-sm max-w-none text-gray-700">
            <?php echo App\Support\HtmlHelper::sanitizeHtml($libro['descrizione']); ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($libro['note_varie'])): ?>
      <div class="card">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-sticky-note text-primary"></i>
            <?= __("Note") ?>
          </h2>
        </div>
        <div class="card-body text-gray-700">
          <?php echo App\Support\HtmlHelper::e($libro['note_varie']); ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($activeReservations) && !$isCatalogueMode): ?>
  <div class="mt-6">
    <div class="card">
      <div class="card-header flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-calendar-check text-primary"></i>
          <?= __("Prenotazioni attive (slot libro)") ?>
        </h2>
        <span class="text-sm text-gray-600"><?= count($activeReservations); ?> <?= __("prenotazioni") ?></span>
      </div>
      <div class="card-body p-0">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Utente") ?></th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Inizio") ?></th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Fine") ?></th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Scadenza prenotazione") ?></th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Coda") ?></th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php foreach ($activeReservations as $res): ?>
              <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                  <div class="text-gray-900 font-medium">
                    <?php echo App\Support\HtmlHelper::e(trim(($res['nome'] ?? '').' '.($res['cognome'] ?? ''))); ?>
                  </div>
                  <?php if (!empty($res['email'])): ?>
                    <div class="text-gray-500 text-xs"><?php echo App\Support\HtmlHelper::e($res['email']); ?></div>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  <?php echo $res['data_inizio_richiesta'] ? App\Support\HtmlHelper::e(format_date($res['data_inizio_richiesta'], false, '/')) : '—'; ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  <?php
                    $endDate = $res['data_fine_richiesta'] ?: ($res['data_scadenza_prenotazione'] ? substr($res['data_scadenza_prenotazione'], 0, 10) : null);
                    echo $endDate ? App\Support\HtmlHelper::e(format_date($endDate, false, '/')) : '—';
                  ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  <?php echo !empty($res['data_scadenza_prenotazione']) ? App\Support\HtmlHelper::e(format_date($res['data_scadenza_prenotazione'], false, '/')) : '—'; ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  <?php echo (int)($res['queue_position'] ?? 1); ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Copies Section -->
  <?php if (!empty($copie) && count($copie) > 0): ?>
  <div class="mt-6">
    <div class="card">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-clone text-primary"></i>
          <?= __("Copie Fisiche") ?>
          <span class="ml-2 text-sm font-normal text-gray-500">
            (<?php echo count($copie); ?> <?= count($copie) > 1 ? __("copie") : __("copia") ?>)
            <?php if (isset($libro['copie_totali']) || isset($libro['copie_disponibili'])): ?>
              <?php if (isset($libro['copie_totali'])): ?>
                <span class="mx-2">•</span>
                <?= __("Copie totali") ?>: <strong><?php echo (int)$libro['copie_totali']; ?></strong>
              <?php endif; ?>
              <?php if (isset($libro['copie_disponibili'])): ?>
                <span class="mx-2">•</span>
                <?= __("Copie disponibili") ?>: <strong class="<?php echo (int)$libro['copie_disponibili'] > 0 ? 'text-green-600' : 'text-red-600'; ?>"><?php echo (int)$libro['copie_disponibili']; ?></strong>
              <?php endif; ?>
            <?php endif; ?>
          </span>
        </h2>
      </div>
      <div class="card-body p-0">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Inventario") ?></th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Stato") ?></th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("ID Prestito") ?></th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Utente") ?></th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Scadenza") ?></th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Note") ?></th>
                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Azioni") ?></th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php foreach ($copie as $copia): ?>
              <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="text-sm font-medium text-gray-900">
                    <i class="fas fa-barcode mr-2 text-gray-400"></i>
                    <?php echo App\Support\HtmlHelper::e($copia['numero_inventario']); ?>
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <?php
                  // Determine effective status based on loan state and dates
                  $rawCopiaStatus = strtolower($copia['stato'] ?? '');
                  $loanStatus = $copia['prestito_stato'] ?? null;
                  $loanStartDate = $copia['data_prestito'] ?? null;
                  $todayDate = date('Y-m-d');

                  // If copy is "prestato" but the loan is "prenotato" and hasn't started yet,
                  // show as "prenotato" (reserved for future) instead of "prestato"
                  // Use substr to compare only date portion (YYYY-MM-DD) in case of datetime strings
                  if ($rawCopiaStatus === 'prestato' && $loanStatus === 'prenotato' && substr((string)$loanStartDate, 0, 10) > $todayDate) {
                      $effectiveStatus = 'prenotato';
                      $effectiveLabel = __('Prenotato');
                      $effectiveClass = 'bg-purple-100 text-purple-800';
                  } else {
                      $effectiveStatus = $rawCopiaStatus;
                      $copiaStatusLabels = [
                          'disponibile' => __('Disponibile'),
                          'prestato'    => __('Prestato'),
                          'manutenzione' => __('In manutenzione'),
                          'perso'       => __('Perso'),
                          'danneggiato' => __('Danneggiato'),
                      ];
                      $copiaStatusClasses = [
                          'disponibile' => 'bg-green-100 text-green-800',
                          'prestato'    => 'bg-red-100 text-red-800',
                          'manutenzione' => 'bg-yellow-100 text-yellow-800',
                          'perso'       => 'bg-gray-100 text-gray-800',
                          'danneggiato' => 'bg-orange-100 text-orange-800',
                      ];
                      $effectiveLabel = $copiaStatusLabels[$effectiveStatus] ?? ucfirst($effectiveStatus);
                      $effectiveClass = $copiaStatusClasses[$effectiveStatus] ?? 'bg-gray-100 text-gray-800';
                  }
                  ?>
                  <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $effectiveClass; ?>">
                    <?php echo App\Support\HtmlHelper::e($effectiveLabel); ?>
                  </span>
                  <?php if ($effectiveStatus === 'prenotato' && $loanStartDate): ?>
                  <div class="text-xs text-purple-600 mt-1">
                    <i class="fas fa-calendar-alt mr-1"></i>
                    <?= __('Dal') ?> <?php echo App\Support\HtmlHelper::e(format_date($loanStartDate, false, '/')); ?>
                  </div>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  <?php if (!empty($copia['prestito_id'])): ?>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                      <i class="fas fa-book-reader mr-1"></i>
                      #<?php echo (int)$copia['prestito_id']; ?>
                    </span>
                  <?php else: ?>
                    <span class="text-gray-400">-</span>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                  <?php if (!empty($copia['utente_nome'])): ?>
                    <div class="text-gray-900">
                      <?php echo App\Support\HtmlHelper::e($copia['utente_nome'] . ' ' . $copia['utente_cognome']); ?>
                    </div>
                    <div class="text-gray-500 text-xs">
                      <?php echo App\Support\HtmlHelper::e($copia['utente_email']); ?>
                    </div>
                  <?php else: ?>
                    <span class="text-gray-400">-</span>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  <?php if (!empty($copia['data_scadenza'])): ?>
                    <?php
                    $scadenza = new DateTime($copia['data_scadenza']);
                    $oggi = new DateTime();
                    $isScaduto = $scadenza < $oggi;
                    ?>
                    <span class="<?php echo $isScaduto ? 'text-red-600 font-semibold' : ''; ?>">
                      <?= App\Support\HtmlHelper::e(format_date($copia['data_scadenza'], false, '/')) ?>
                      <?php if ($isScaduto): ?>
                        <i class="fas fa-exclamation-triangle ml-1"></i>
                      <?php endif; ?>
                    </span>
                  <?php else: ?>
                    <span class="text-gray-400">-</span>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 text-sm text-gray-500">
                  <?php echo App\Support\HtmlHelper::e($copia['note'] ?? '-'); ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                  <div class="flex items-center justify-end gap-2">
                    <?php
                    $canEdit = empty($copia['prestito_id']);
                    $canDelete = $canEdit && in_array($rawCopiaStatus, ['perso', 'danneggiato', 'manutenzione']);
                    ?>
                    <?php if ($canEdit): ?>
                    <button type="button"
                            onclick="openEditCopyModal(<?php echo (int)$copia['id']; ?>, '<?php echo htmlspecialchars($copia['stato'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($copia['note'] ?? '', ENT_QUOTES); ?>')"
                            class="text-blue-600 hover:text-blue-900 transition-colors"
                            title="<?= __("Modifica stato") ?>">
                      <i class="fas fa-edit"></i>
                    </button>
                    <?php endif; ?>
                    <?php if ($canDelete): ?>
                    <button type="button"
                            onclick="confirmDeleteCopy(<?php echo (int)$copia['id']; ?>, '<?php echo htmlspecialchars($copia['numero_inventario'], ENT_QUOTES); ?>')"
                            class="text-red-600 hover:text-red-900 transition-colors"
                            title="<?= __("Elimina copia") ?>">
                      <i class="fas fa-trash"></i>
                    </button>
                    <?php endif; ?>
                    <?php if (!$canEdit): ?>
                    <span class="text-gray-400 text-xs"><?= __("In prestito") ?></span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Loan History Section -->
  <?php if (!empty($loanHistory) && count($loanHistory) > 0 && !$isCatalogueMode): ?>
  <div class="mt-6">
    <div class="card">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-history text-primary"></i>
          <?= __("Storico Prestiti") ?>
          <span class="ml-2 text-sm font-normal text-gray-500">(<?php echo count($loanHistory); ?> <?= __("prestiti totali") ?>)</span>
        </h2>
      </div>
      <div class="card-body p-0">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Utente") ?></th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Data Prestito") ?></th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Scadenza") ?></th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Restituzione") ?></th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Stato") ?></th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Rinnovi") ?></th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Operatore") ?></th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php foreach ($loanHistory as $loan): ?>
              <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="flex items-center">
                    <div>
                      <div class="text-sm font-medium text-gray-900">
                        <a href="/admin/utenti/<?php echo (int)$loan['utente_id']; ?>" class="hover:text-blue-600 transition-colors">
                          <?php echo App\Support\HtmlHelper::e($loan['utente_nome'] . ' ' . $loan['utente_cognome']); ?>
                        </a>
                      </div>
                      <?php if (!empty($loan['utente_email'])): ?>
                      <div class="text-xs text-gray-500"><?php echo App\Support\HtmlHelper::e($loan['utente_email']); ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  <i class="fas fa-calendar-alt text-gray-400 mr-1"></i>
                  <?php echo App\Support\HtmlHelper::e(format_date($loan['data_prestito'], false, '/')); ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                  <?php
                    $isOverdue = strtotime($loan['data_scadenza']) < strtotime(date('Y-m-d')) && $loan['stato'] !== 'restituito';
                  ?>
                  <span class="<?php echo $isOverdue ? 'text-red-600 font-semibold' : 'text-gray-900'; ?>">
                    <i class="fas fa-calendar-times text-gray-400 mr-1"></i>
                    <?php echo App\Support\HtmlHelper::e(format_date($loan['data_scadenza'], false, '/')); ?>
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  <?php if (!empty($loan['data_restituzione'])): ?>
                    <i class="fas fa-check-circle text-green-500 mr-1"></i>
                    <?php echo App\Support\HtmlHelper::e(format_date($loan['data_restituzione'], false, '/')); ?>
                  <?php else: ?>
                    <span class="text-gray-400 italic"><?= __("In corso") ?></span>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <?php
                    $statusClass = 'bg-gray-100 text-gray-800';
                    $statusIcon = 'fa-circle';
                    $statusLabel = __('Sconosciuto');
                    switch ($loan['stato']) {
                      case 'restituito':
                        $statusClass = 'bg-green-100 text-green-800';
                        $statusIcon = 'fa-check-circle';
                        $statusLabel = __('Restituito');
                        break;
                      case 'prenotato':
                        $statusClass = 'bg-purple-100 text-purple-800';
                        $statusIcon = 'fa-calendar-check';
                        $statusLabel = __('Prenotato');
                        break;
                      case 'in_corso':
                        $statusClass = 'bg-blue-100 text-blue-800';
                        $statusIcon = 'fa-book-open';
                        $statusLabel = __('In Corso');
                        break;
                      case 'in_ritardo':
                        $statusClass = 'bg-red-100 text-red-800';
                        $statusIcon = 'fa-exclamation-triangle';
                        $statusLabel = __('In Ritardo');
                        break;
                      case 'perso':
                        $statusClass = 'bg-yellow-100 text-yellow-800';
                        $statusIcon = 'fa-exclamation-circle';
                        $statusLabel = __('Perso');
                        break;
                      case 'danneggiato':
                        $statusClass = 'bg-yellow-100 text-yellow-800';
                        $statusIcon = 'fa-exclamation-circle';
                        $statusLabel = __('Danneggiato');
                        break;
                      case 'pendente':
                        $statusClass = 'bg-orange-100 text-orange-800';
                        $statusIcon = 'fa-clock';
                        $statusLabel = __('In Attesa');
                        break;
                    }
                  ?>
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $statusClass; ?>">
                    <i class="fas <?php echo $statusIcon; ?> mr-1"></i>
                    <?php echo App\Support\HtmlHelper::e($statusLabel); ?>
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  <?php if ((int)$loan['renewals'] > 0): ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded bg-gray-100 text-gray-800">
                      <i class="fas fa-redo-alt mr-1"></i>
                      <?php echo (int)$loan['renewals']; ?>
                    </span>
                  <?php else: ?>
                    <span class="text-gray-400">-</span>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                  <?php if (!empty($loan['staff_nome'])): ?>
                    <i class="fas fa-user text-gray-400 mr-1"></i>
                    <?php echo App\Support\HtmlHelper::e($loan['staff_nome'] . ' ' . $loan['staff_cognome']); ?>
                  <?php else: ?>
                    <span class="text-gray-400">-</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php if (!empty($loan['note'])): ?>
              <tr class="bg-gray-50">
                <td colspan="7" class="px-6 py-3">
                  <div class="text-xs text-gray-600">
                    <i class="fas fa-comment text-gray-400 mr-1"></i>
                    <span class="font-medium">Note:</span> <?php echo App\Support\HtmlHelper::e($loan['note']); ?>
                  </div>
                </td>
              </tr>
              <?php endif; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Copy Availability Calendar (hidden in catalogue mode - no loans) -->
  <?php if (!empty($copie) && count($copie) > 0 && !$isCatalogueMode): ?>
  <div class="mt-6">
    <div class="card">
      <div class="card-header">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
          <i class="fas fa-calendar-alt text-primary"></i>
          <?= __("Calendario Disponibilità") ?>
          <span class="ml-2 text-sm font-normal text-gray-500">
            (<?= __("visualizzazione per copia") ?>)
          </span>
        </h2>
      </div>
      <div class="card-body">
        <!-- Status Legend -->
        <div style="display: flex; flex-wrap: wrap; gap: 1.5rem; margin-bottom: 1rem; font-size: 0.875rem;">
          <div style="display: flex; align-items: center; gap: 0.5rem;">
            <span style="width: 16px; height: 16px; border-radius: 4px; background-color: #FFFFFF; border: 1px solid #D1D5DB;"></span>
            <span style="color: #4B5563;"><?= __("Disponibile") ?></span>
          </div>
          <div style="display: flex; align-items: center; gap: 0.5rem;">
            <span style="width: 16px; height: 16px; border-radius: 4px; background-color: #E9D5FF; border: 1px solid #A855F7;"></span>
            <span style="color: #4B5563;"><?= __("Prenotato") ?></span>
          </div>
          <div style="display: flex; align-items: center; gap: 0.5rem;">
            <span style="width: 16px; height: 16px; border-radius: 4px; background-color: #FECACA; border: 1px solid #EF4444;"></span>
            <span style="color: #4B5563;"><?= __("In prestito") ?></span>
          </div>
          <div style="display: flex; align-items: center; gap: 0.5rem;">
            <span style="width: 16px; height: 16px; border-radius: 4px; background-color: #FEF08A; border: 1px solid #EAB308;"></span>
            <span style="color: #4B5563;"><?= __("In ritardo") ?></span>
          </div>
        </div>

        <!-- Copy Legend -->
        <div style="display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem; padding: 1rem; background-color: #F9FAFB; border-radius: 0.5rem;">
          <?php foreach ($copie as $idx => $cal_copia): ?>
          <div style="display: flex; align-items: center; gap: 0.5rem;">
            <span style="width: 12px; height: 12px; border-radius: 50%; background-color: <?php echo ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#06B6D4', '#84CC16'][$idx % 8]; ?>;"></span>
            <span style="font-size: 0.875rem; font-weight: 500; color: #374151;"><?php echo App\Support\HtmlHelper::e($cal_copia['numero_inventario']); ?></span>
            <?php
            $calCopiaStatus = $cal_copia['prestito_stato'] ?? null;
            $calLoanStart = $cal_copia['data_prestito'] ?? null;
            $calLoanEnd = $cal_copia['data_scadenza'] ?? null;
            $calToday = date('Y-m-d');
            if ($calCopiaStatus === 'prenotato' && $calLoanStart > $calToday):
            ?>
            <span style="font-size: 0.75rem; color: #7C3AED;">
              (<?= __("Prenotato") ?> <?php echo format_date_short($calLoanStart); ?> - <?php echo format_date_short($calLoanEnd); ?>)
            </span>
            <?php elseif (in_array($calCopiaStatus, ['in_corso', 'in_ritardo'])): ?>
            <span style="font-size: 0.75rem; color: #DC2626;">
              (<?= __("In prestito fino al") ?> <?php echo format_date_short($calLoanEnd); ?>)
            </span>
            <?php else: ?>
            <span style="font-size: 0.75rem; color: #16A34A;">(<?= __("Disponibile") ?>)</span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Calendar Container (FullCalendar like dashboard) -->
        <div id="copy-availability-calendar" class="min-h-[400px]"></div>
      </div>
    </div>
  </div>

  <!-- FullCalendar (same as dashboard) -->
  <script src="/assets/fullcalendar.min.js"></script>
  <?php
  // Prepare calendar events for each copy's loan
  $copyColors = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#06B6D4', '#84CC16'];
  $calendarEventsJson = [];

  foreach ($copie as $idx => $cal_copia) {
      $copyColor = $copyColors[$idx % 8];
      $inventario = $cal_copia['numero_inventario'] ?? '';

      // If this copy has an active loan, create an event
      if (!empty($cal_copia['prestito_stato']) && !empty($cal_copia['data_prestito'])) {
          $stato = $cal_copia['prestito_stato'];
          $startDate = substr((string)$cal_copia['data_prestito'], 0, 10);
          $endDate = !empty($cal_copia['data_scadenza'])
              ? substr((string)$cal_copia['data_scadenza'], 0, 10)
              : $startDate;

          // Determine event color based on status
          $eventColor = match($stato) {
              'in_corso' => '#EF4444',       // Red - on loan
              'prenotato' => '#8B5CF6',      // Purple - reserved
              'in_ritardo' => '#F59E0B',     // Amber - overdue
              'pendente' => '#3B82F6',       // Blue - pending
              default => $copyColor
          };

          // Status label
          $statusLabel = match($stato) {
              'in_corso' => __('In prestito'),
              'prenotato' => __('Prenotato'),
              'in_ritardo' => __('In ritardo'),
              'pendente' => __('In attesa'),
              default => ucfirst($stato)
          };

          // FullCalendar expects end date to be exclusive, so add 1 day
          $endDateObj = new DateTime($endDate);
          $endDateObj->modify('+1 day');
          $endDateExclusive = $endDateObj->format('Y-m-d');

          $calendarEventsJson[] = [
              'id' => 'copy_' . $cal_copia['id'],
              'title' => $inventario . ' - ' . $statusLabel,
              'start' => $startDate,
              'end' => $endDateExclusive,
              'color' => $eventColor,
              'extendedProps' => [
                  'inventario' => $inventario,
                  'stato' => $stato,
                  'statusLabel' => $statusLabel,
                  'copyColor' => $copyColor
              ]
          ];
      }
  }
  ?>
  <script>
  // XSS protection helper
  function escapeHtml(str) {
      return String(str ?? '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
  }

  // formatDateLocale and appLocale are defined globally in layout.php

  document.addEventListener('DOMContentLoaded', function() {
      const calendarEl = document.getElementById('copy-availability-calendar');
      if (calendarEl && typeof FullCalendar !== 'undefined') {
          // Detect mobile for responsive toolbar
          const isMobile = window.innerWidth < 768;

          const calendar = new FullCalendar.Calendar(calendarEl, {
              initialView: isMobile ? 'listWeek' : 'dayGridMonth',
              locale: '<?= strtolower(substr(\App\Support\I18n::getLocale(), 0, 2)) ?>',
              // Responsive toolbar: simpler on mobile
              headerToolbar: isMobile ? {
                  left: 'prev,next',
                  center: 'title',
                  right: 'listWeek,dayGridMonth'
              } : {
                  left: 'prev,next today',
                  center: 'title',
                  right: 'dayGridMonth,dayGridWeek,listWeek'
              },
              buttonText: {
                  today: '<?= __("Oggi") ?>',
                  month: '<?= __("Mese") ?>',
                  week: '<?= __("Settimana") ?>',
                  list: '<?= __("Lista") ?>'
              },
              // Responsive settings
              handleWindowResize: true,
              contentHeight: 'auto',
              expandRows: true,
              // Better mobile experience
              dayMaxEvents: isMobile ? 2 : true, // Limit events per day on mobile
              moreLinkClick: 'popover', // Show popover instead of navigating
              events: <?= json_encode(
                  $calendarEventsJson,
                  JSON_UNESCAPED_UNICODE
                  | JSON_HEX_TAG
                  | JSON_HEX_AMP
                  | JSON_HEX_APOS
                  | JSON_HEX_QUOT
              ) ?>,
              eventClick: function(info) {
                  const props = info.event.extendedProps;
                  const start = info.event.start;
                  const end = info.event.end ? new Date(info.event.end.getTime() - 86400000) : start; // Subtract 1 day (exclusive end)

                  if (window.Swal) {
                      Swal.fire({
                          title: escapeHtml(info.event.title),
                          html: `
                              <div class="text-left">
                                  <p><strong><?= __("Copia") ?>:</strong> ${escapeHtml(props.inventario)}</p>
                                  <p><strong><?= __("Stato") ?>:</strong> ${escapeHtml(props.statusLabel)}</p>
                                  <p><strong><?= __("Dal") ?>:</strong> ${formatDateLocale(start)}</p>
                                  <p><strong><?= __("Al") ?>:</strong> ${formatDateLocale(end)}</p>
                              </div>
                          `,
                          icon: 'info',
                          confirmButtonText: '<?= __("Chiudi") ?>'
                      });
                  } else {
                      alert(`${escapeHtml(info.event.title)}\n${escapeHtml(props.statusLabel)}`);
                  }
              },
              eventDidMount: function(info) {
                  // Add tooltip with XSS protection
                  info.el.title = escapeHtml(info.event.title);
              }
          });
          calendar.render();
      }
  });
  </script>

  <style>
    /* FullCalendar custom styles for copy availability */
    #copy-availability-calendar .fc-event {
      cursor: pointer;
      padding: 2px 4px;
      border-radius: 4px;
      font-size: 0.75rem;
    }
    #copy-availability-calendar .fc-event-title {
      font-weight: 500;
    }
    #copy-availability-calendar .fc-daygrid-event {
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    /* Responsive styles for mobile */
    @media (max-width: 767px) {
      #copy-availability-calendar {
        min-height: 300px;
      }
      #copy-availability-calendar .fc-toolbar {
        flex-direction: column;
        gap: 0.5rem;
      }
      #copy-availability-calendar .fc-toolbar-chunk {
        display: flex;
        justify-content: center;
      }
      #copy-availability-calendar .fc-toolbar-title {
        font-size: 1rem;
      }
      #copy-availability-calendar .fc-button {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
      }
      #copy-availability-calendar .fc-daygrid-day-number {
        font-size: 0.75rem;
        padding: 2px 4px;
      }
      #copy-availability-calendar .fc-event {
        font-size: 0.65rem;
        padding: 1px 2px;
      }
      #copy-availability-calendar .fc-list-event-title {
        font-size: 0.8rem;
      }
    }
  </style>
  <?php endif; ?>

  <script>
    function confirmDeleteBook(e){
      if (window.Swal){
        e.preventDefault();
        Swal.fire({title: __('Sei sicuro?'), text: __('Questa azione non può essere annullata'), icon:'warning', showCancelButton:true, confirmButtonText: __('Elimina'), cancelButtonText: __('Annulla'), confirmButtonColor:'#d33'}).then(r=>{ if(r.isConfirmed) e.target.submit(); });
        return false;
      }
      return confirm(__('Eliminare il libro?'));
    }

    function confirmRenewal(e){
      if (window.Swal){
        e.preventDefault();
        Swal.fire({
          title: __('Rinnova prestito?'),
          text: __('La scadenza verrà estesa di 14 giorni'),
          icon:'question',
          showCancelButton:true,
          confirmButtonText: __('Rinnova'),
          cancelButtonText: __('Annulla'),
          confirmButtonColor:'#1f2937'
        }).then(r=>{ if(r.isConfirmed) e.target.submit(); });
        return false;
      }
      return confirm(__('Rinnovare il prestito? La scadenza verrà estesa di 14 giorni.'));
    }
  </script>

  <?php if (!empty($activeLoan) && (int)$activeLoan['attivo'] === 1): ?>
    <div id="return-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black bg-opacity-50">
      <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xl mx-4">
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
          <h3 class="text-lg font-semibold text-gray-900">
            <i class="fas fa-undo text-gray-600 mr-2"></i>
            <?= __("Registra restituzione prestito") ?> #<?php echo (int)$activeLoan['id']; ?>
          </h3>
          <button type="button" id="close-return-modal" class="text-gray-500 hover:text-gray-700">
            <i class="fas fa-times"></i>
          </button>
        </div>
        <form method="post" action="/admin/prestiti/restituito/<?php echo (int)$activeLoan['id']; ?>" class="px-6 py-5 space-y-4">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($bookPath ?? ('/admin/libri/' . (int)($libro['id'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?>">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm text-gray-700">
            <div>
              <div class="text-xs text-gray-500 uppercase"><?= __("Utente") ?></div>
              <div class="font-medium"><?php echo App\Support\HtmlHelper::e($activeLoan['utente_nome'] ?? __('Sconosciuto')); ?></div>
              <div class="text-xs text-gray-500"><?php echo App\Support\HtmlHelper::e($activeLoan['utente_email'] ?? ''); ?></div>
            </div>
            <div>
              <div class="text-xs text-gray-500 uppercase"><?= __("Prestito") ?></div>
              <div class="font-medium"><?= __("Dal") ?> <?php echo App\Support\HtmlHelper::e($activeLoan['data_prestito'] ?? ''); ?></div>
              <?php $modalLate = strtotime($activeLoan['data_scadenza'] ?? '1970-01-01') < strtotime(date('Y-m-d')); ?>
              <div class="text-xs <?php echo $modalLate ? 'text-red-600 font-semibold' : 'text-gray-500'; ?>">
                <?= __("Scadenza") ?> <?php echo App\Support\HtmlHelper::e($activeLoan['data_scadenza'] ?? ''); ?>
              </div>
            </div>
          </div>
          <div>
            <label for="modal-stato" class="form-label"><?= __("Esito restituzione") ?></label>
            <select id="modal-stato" name="stato" class="form-input" required aria-required="true">
              <option value="restituito" selected><?= __("Restituito") ?></option>
              <option value="in_ritardo"><?= __("Mantieni in ritardo") ?></option>
              <option value="danneggiato"><?= __("Danneggiato") ?></option>
              <option value="perso"><?= __("Perso") ?></option>
            </select>
          </div>
          <div>
            <label for="modal-note" class="form-label"><?= __("Note") ?> (<?= __("opzionali") ?>)</label>
            <textarea id="modal-note" name="note" rows="3" class="form-input" placeholder="<?= __('Aggiungi eventuali note...') ?>"></textarea>
          </div>
          <div class="flex items-center justify-end gap-3 pt-2">
            <button type="button" id="close-return-modal-secondary" class="btn-secondary"><?= __("Annulla") ?></button>
            <button type="submit" class="<?php echo $btnPrimary; ?> justify-center">
              <i class="fas fa-check mr-2"></i><?= __("Conferma restituzione") ?>
            </button>
          </div>
        </form>
      </div>
    </div>

    <script>
      document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('return-modal');
        if (!modal) return;
        const openButtons = [
          document.getElementById('open-return-modal'),
          document.getElementById('open-return-modal-secondary')
        ].filter(Boolean);
        const closeButtons = [
          document.getElementById('close-return-modal'),
          document.getElementById('close-return-modal-secondary')
        ].filter(Boolean);

        const openModal = () => {
          modal.classList.remove('hidden');
          modal.classList.add('flex');
        };
        const closeModal = () => {
          modal.classList.add('hidden');
          modal.classList.remove('flex');
        };

        openButtons.forEach(btn => btn.addEventListener('click', openModal));
        closeButtons.forEach(btn => btn.addEventListener('click', closeModal));
        modal.addEventListener('click', (event) => {
          if (event.target === modal) closeModal();
        });
      });
    </script>
  <?php endif; ?>

  <!-- Modal Modifica Stato Copia -->
  <div id="edit-copy-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black bg-opacity-50">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4">
      <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
        <h3 class="text-lg font-semibold text-gray-900">
          <i class="fas fa-edit text-gray-600 mr-2"></i>
          <?= __("Modifica Stato Copia") ?>
        </h3>
        <button type="button" id="close-edit-copy-modal" class="text-gray-500 hover:text-gray-700">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <form method="post" id="edit-copy-form" class="px-6 py-5 space-y-4">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="copy_id" id="edit-copy-id" value="">

        <div>
          <label for="edit-copy-stato" class="form-label"><?= __("Stato della copia") ?></label>
          <select id="edit-copy-stato" name="stato" class="form-input" required aria-required="true">
            <option value="disponibile"><?= __("Disponibile") ?></option>
            <option value="prestato" disabled><?= __("Prestato (usa il sistema Prestiti)") ?></option>
            <option value="manutenzione"><?= __("In manutenzione") ?></option>
            <option value="danneggiato"><?= __("Danneggiato") ?></option>
            <option value="perso"><?= __("Perso") ?></option>
          </select>
          <p class="text-xs text-gray-600 mt-1">
            <i class="fas fa-info-circle text-blue-500 mr-1"></i>
            <strong><?= __("Nota:") ?></strong> <?= __("Per prestare una copia, usa la sezione Prestiti. Imposta \"Disponibile\" per chiudere un prestito attivo.") ?>
          </p>
        </div>

        <div>
          <label for="edit-copy-note" class="form-label"><?= __("Note") ?> (<?= __("opzionale") ?>)</label>
          <textarea id="edit-copy-note" name="note" rows="3" class="form-input" placeholder="<?= __('Aggiungi eventuali note...') ?>"></textarea>
        </div>

        <div class="flex items-center justify-end gap-3 pt-2">
          <button type="button" id="close-edit-copy-modal-secondary" class="btn-secondary"><?= __("Annulla") ?></button>
          <button type="submit" class="<?php echo $btnPrimary ?? 'btn-primary'; ?> justify-center">
            <i class="fas fa-save mr-2"></i><?= __("Salva Modifiche") ?>
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Modal gestione copia
    const editCopyModal = document.getElementById('edit-copy-modal');
    const editCopyForm = document.getElementById('edit-copy-form');

    function openEditCopyModal(copyId, currentStato, currentNote) {
      document.getElementById('edit-copy-id').value = copyId;
      document.getElementById('edit-copy-note').value = currentNote || '';

      const statoSelect = document.getElementById('edit-copy-stato');
      const prestatoOption = statoSelect.querySelector('option[value="prestato"]');

      // Se lo stato corrente è "prestato", riabilita l'opzione per poterla selezionare
      if (currentStato === 'prestato') {
        prestatoOption.disabled = false;
        prestatoOption.textContent = __('Prestato (imposta "Disponibile" per chiudere il prestito)');
      } else {
        prestatoOption.disabled = true;
        prestatoOption.textContent = __('Prestato (usa il sistema Prestiti)');
      }

      statoSelect.value = currentStato;

      editCopyForm.action = `/admin/libri/copie/${copyId}/update`;

      editCopyModal.classList.remove('hidden');
      editCopyModal.classList.add('flex');
    }

    function closeEditCopyModal() {
      editCopyModal.classList.add('hidden');
      editCopyModal.classList.remove('flex');
    }

    document.getElementById('close-edit-copy-modal')?.addEventListener('click', closeEditCopyModal);
    document.getElementById('close-edit-copy-modal-secondary')?.addEventListener('click', closeEditCopyModal);
    editCopyModal?.addEventListener('click', (e) => {
      if (e.target === editCopyModal) closeEditCopyModal();
    });

    editCopyForm?.addEventListener('submit', function(e) {
      e.preventDefault();

      if (window.Swal) {
        Swal.fire({
          title: __('Conferma modifica'),
          text: __('Vuoi aggiornare lo stato di questa copia?'),
          icon: 'question',
          showCancelButton: true,
          confirmButtonText: __('Sì, aggiorna'),
          cancelButtonText: __('Annulla'),
          confirmButtonColor: '#1f2937'
        }).then(result => {
          if (result.isConfirmed) {
            e.target.submit();
          }
        });
      } else {
        if (confirm(__('Vuoi aggiornare lo stato di questa copia?'))) {
          e.target.submit();
        }
      }
    });

    function confirmDeleteCopy(copyId, numeroInventario) {
      if (window.Swal) {
        Swal.fire({
          title: __('Elimina copia'),
          html: `${__('Sei sicuro di voler eliminare la copia')} <strong>${numeroInventario}</strong>?<br><span class="text-sm text-gray-600">${__('Questa azione non può essere annullata.')}</span>`,
          icon: 'warning',
          showCancelButton: true,
          confirmButtonText: __('Sì, elimina'),
          cancelButtonText: __('Annulla'),
          confirmButtonColor: '#dc2626'
        }).then(result => {
          if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = `/admin/libri/copie/${copyId}/delete`;

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = '<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>';
            form.appendChild(csrfInput);

            document.body.appendChild(form);
            form.submit();
          }
        });
      } else {
        if (confirm(`${__('Sei sicuro di voler eliminare la copia')} ${numeroInventario}? ${__('Questa azione non può essere annullata.')}`)) {
          const form = document.createElement('form');
          form.method = 'POST';
          form.action = `/admin/libri/copie/${copyId}/delete`;

          const csrfInput = document.createElement('input');
          csrfInput.type = 'hidden';
          csrfInput.name = 'csrf_token';
          csrfInput.value = '<?php echo htmlspecialchars(App\Support\Csrf::ensureToken(), ENT_QUOTES, 'UTF-8'); ?>';
          form.appendChild(csrfInput);

          document.body.appendChild(form);
          form.submit();
        }
      }
    }
  </script>
  </div>
</section>

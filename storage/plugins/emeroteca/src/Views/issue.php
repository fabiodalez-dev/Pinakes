<?php
/**
 * Emeroteca — fascicolo detail: all fields + cover upload + spoglio
 * (article rows added/removed with a small vanilla-JS helper).
 *
 * Chrome copied verbatim from the core book form (app/Views/libri);
 * only the spoglio table keeps the plugin stylesheet (stacked rows on phones).
 *
 * @var array<string, mixed> $fascicolo   includes anno, volume, testata_id, testata_titolo
 * @var array<int, array<string, mixed>> $articoli
 * @var array<int, array<string, mixed>> $collocazioni
 */
declare(strict_types=1);

$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$val = static fn(string $k): string => $e((string) ($fascicolo[$k] ?? ''));

$fid       = (int) $fascicolo['id'];
$testataId = (int) $fascicolo['testata_id'];
$issuesUrl = $e(url('/admin/periodicals/' . $testataId . '/issues'));
$formAction = $e(url('/admin/periodicals/issue/' . $fid));
$deleteAction = $e(url('/admin/periodicals/issue/' . $fid . '/delete'));
$csrf = $e(\App\Support\Csrf::ensureToken());

// Possession (1.4.0): condition lives in its own field below.
$statoLabels = [
    'posseduto' => __('Posseduto'),
    'mancante'  => __('Mancante'),
    'atteso'    => __('Atteso'),
    'smarrito'  => __('Smarrito'),
    'reclamato' => __('Reclamato'),
    'scartato'  => __('Scartato'),
];
$condizioneLabels = [
    'buono'       => __('Buono'),
    'discreto'    => __('Discreto'),
    'danneggiato' => __('Danneggiato'),
    'in_restauro' => __('In restauro'),
];
$acquisizioneLabels = [
    'abbonamento' => __('Abbonamento'),
    'acquisto'    => __('Acquisto'),
    'dono'        => __('Dono'),
    'scambio'     => __('Scambio'),
    'deposito'    => __('Deposito'),
];
$nReclami = (int) ($fascicolo['n_reclami'] ?? 0);
$reclamatoIl = (string) ($fascicolo['reclamato_il'] ?? '');
$testataBarcode = (string) ($fascicolo['testata_barcode_base'] ?? '');
$tipoArticoloLabels = [
    'articolo'   => __('Articolo'),
    'editoriale' => __('Editoriale'),
    'recensione' => __('Recensione'),
    'intervista' => __('Intervista'),
    'dossier'    => __('Dossier'),
    'rubrica'    => __('Rubrica'),
];
$cover = (string) ($fascicolo['copertina_url'] ?? '');
$coverSrc = $cover === '' ? '' : (str_starts_with($cover, '/') ? url($cover) : $cover);
$pdfPath = (string) ($fascicolo['pdf_path'] ?? '');
$pdfName = (string) ($fascicolo['pdf_nome_originale'] ?? '');
$pdfSize = (int) ($fascicolo['pdf_dimensione'] ?? 0);
$pdfSizeLabel = '';
if ($pdfSize > 0) {
    // Separatori decimali secondo la locale attiva, non hardcoded italiani —
    // anche nel fallback senza ext-intl.
    $pdfMb = $pdfSize / 1048576;
    $sizeLocale = \App\Support\I18n::getLocale();
    [$decSep, $thouSep] = match (substr($sizeLocale, 0, 2)) {
        'it', 'de', 'da' => [',', '.'],
        'fr' => [',', ' '],
        default => ['.', ','],
    };
    $formattedMb = false;
    if (class_exists(\NumberFormatter::class)) {
        $sizeFormatter = new \NumberFormatter($sizeLocale, \NumberFormatter::DECIMAL);
        $sizeFormatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 2);
        $sizeFormatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);
        $formattedMb = $sizeFormatter->format($pdfMb);
    }
    $pdfSizeLabel = ($formattedMb !== false ? $formattedMb : number_format($pdfMb, 2, $decSep, $thouSep)) . ' MB';
}
$pdfAdminUrl = $e(url('/admin/periodicals/issue/' . $fid . '/pdf'));
$checkboxClass = 'w-4 h-4 rounded border-gray-300 text-gray-900 focus:ring-gray-500';
?>
<link rel="stylesheet" href="<?= $e(url('/plugins/emeroteca/assets/css/emeroteca.css?v=1.4.0')) ?>">
<style>
    /* The spoglio table keeps the plugin's stacked-rows layout on phones,
       which is scoped to .emeroteca-admin: that scope wraps only the table,
       so it must not bring its own page width and padding. */
    #emeroteca-admin-issue .emeroteca-admin { max-width: none; margin: 0; padding: 0; }
    #emt-pdf-result i { margin-right: .5rem; }
</style>
<div id="emeroteca-admin-issue" class="min-h-screen bg-gray-50 py-6">
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
        <li>
          <a href="<?= $e(url('/admin/periodicals')) ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <i class="fas fa-newspaper mr-1"></i><?= __('Emeroteca') ?>
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li>
          <a href="<?= $issuesUrl ?>" class="text-gray-500 hover:text-gray-700 transition-colors">
            <?= $e($fascicolo['testata_titolo']) ?>
          </a>
        </li>
        <li>
          <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
        </li>
        <li class="text-gray-900 font-medium">
          <?= (int) $fascicolo['anno'] ?> · <?= __('n.') ?> <?= $e($fascicolo['numero']) ?>
        </li>
      </ol>
    </nav>

    <!-- Header -->
    <div class="mb-8">
      <h1 class="text-3xl font-bold text-gray-900 mb-2">
        <?= $e($fascicolo['testata_titolo']) ?> — <?= (int) $fascicolo['anno'] ?>, <?= __('n.') ?> <?= $e($fascicolo['numero']) ?>
      </h1>
      <p class="text-gray-600"><?= __("Scheda del fascicolo: dati, copertina e spoglio degli articoli.") ?></p>
    </div>

    <form id="emt-issue-form" method="POST" action="<?= $formAction ?>" enctype="multipart/form-data"
          class="space-y-8">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

      <!-- Dati del fascicolo -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-info-circle text-gray-900"></i>
            <?= __("Dati del fascicolo") ?>
          </h2>
        </div>
        <div class="card-body form-section">
          <div class="form-grid-3">
            <div>
              <label for="numero" class="form-label">
                <?= __("Numero") ?> <span class="text-red-500">*</span>
              </label>
              <input type="text" name="numero" id="numero"
                     value="<?= $val('numero') ?>" maxlength="50" required aria-required="true"
                     class="form-input">
            </div>
            <div>
              <label for="numero_progressivo" class="form-label"><?= __("Numero progressivo") ?></label>
              <input type="text" name="numero_progressivo" id="numero_progressivo"
                     value="<?= $val('numero_progressivo') ?>" maxlength="50"
                     class="form-input">
            </div>
            <div>
              <label for="stato" class="form-label"><?= __("Stato") ?></label>
              <select name="stato" id="stato" class="form-input">
                <?php foreach ($statoLabels as $value => $label): ?>
                  <option value="<?= $e($value) ?>" <?= ((string) ($fascicolo['stato'] ?? 'posseduto')) === $value ? 'selected' : '' ?>>
                    <?= $e($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="text-xs text-gray-500 mt-1">
                <?= __("possesso") ?><?php if ($nReclami > 0): ?> · <i class="fas fa-bell" aria-hidden="true"></i> <?= sprintf(__('solleciti: %d'), $nReclami) ?><?php if ($reclamatoIl !== ''): ?> · <?= $e(sprintf(__('Ultimo sollecito: %s'), $reclamatoIl)) ?><?php endif; ?><?php endif; ?>
              </p>
            </div>
          </div>

          <div class="form-grid-3">
            <div>
              <label for="condizione" class="form-label"><?= __("Condizione") ?></label>
              <select name="condizione" id="condizione" class="form-input">
                <option value="">— <?= __("Non rilevata") ?> —</option>
                <?php foreach ($condizioneLabels as $value => $label): ?>
                  <option value="<?= $e($value) ?>" <?= ((string) ($fascicolo['condizione'] ?? '')) === $value ? 'selected' : '' ?>>
                    <?= $e($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="text-xs text-gray-500 mt-1"><?= __("stato fisico della copia") ?></p>
            </div>
            <div>
              <label for="acquisizione" class="form-label"><?= __("Acquisizione") ?></label>
              <select name="acquisizione" id="acquisizione" class="form-input">
                <option value="">— <?= __("Non specificata") ?> —</option>
                <?php foreach ($acquisizioneLabels as $value => $label): ?>
                  <option value="<?= $e($value) ?>" <?= ((string) ($fascicolo['acquisizione'] ?? '')) === $value ? 'selected' : '' ?>>
                    <?= $e($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="prezzo" class="form-label"><?= __("Prezzo") ?></label>
              <input type="text" name="prezzo" id="prezzo"
                     value="<?= $val('prezzo') ?>" maxlength="20"
                     inputmode="decimal" placeholder="0.00"
                     class="form-input">
            </div>
          </div>

          <div>
            <label for="titolo_fascicolo" class="form-label"><?= __("Titolo del fascicolo") ?></label>
            <input type="text" name="titolo_fascicolo" id="titolo_fascicolo"
                   value="<?= $val('titolo_fascicolo') ?>" maxlength="255"
                   class="form-input">
            <p class="text-xs text-gray-500 mt-1"><?= __("per numeri monografici") ?></p>
          </div>

          <div class="form-grid-3">
            <div>
              <label for="data_copertina" class="form-label"><?= __("Data di copertina") ?></label>
              <input type="text" name="data_copertina" id="data_copertina"
                     value="<?= $val('data_copertina') ?>" maxlength="100"
                     class="form-input">
              <p class="text-xs text-gray-500 mt-1"><?= __("testo libero, es. \"Marzo 1998\"") ?></p>
            </div>
            <div>
              <label for="data_pubblicazione" class="form-label"><?= __("Data di pubblicazione") ?></label>
              <input type="date" name="data_pubblicazione" id="data_pubblicazione"
                     value="<?= $val('data_pubblicazione') ?>"
                     class="form-input">
            </div>
            <div>
              <label for="pagine" class="form-label"><?= __("Pagine") ?></label>
              <input type="number" name="pagine" id="pagine"
                     value="<?= $val('pagine') ?>" min="0" max="32767"
                     class="form-input">
            </div>
          </div>
        </div>
      </div>

      <!-- Inventario -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-barcode text-gray-900"></i>
            <?= __("Inventario") ?>
          </h2>
        </div>
        <div class="card-body form-section">
          <div class="form-grid-2">
            <div>
              <label for="numero_inventario" class="form-label"><?= __("Numero di inventario") ?></label>
              <input type="text" name="numero_inventario" id="numero_inventario"
                     value="<?= $val('numero_inventario') ?>" maxlength="100"
                     class="form-input">
            </div>
            <div>
              <label for="barcode" class="form-label"><?= __("Barcode") ?></label>
              <input type="text" name="barcode" id="barcode"
                     value="<?= $val('barcode') ?>" maxlength="18"
                     inputmode="numeric"
                     placeholder="<?= $e($testataBarcode !== '' ? $testataBarcode : '9770000000000') ?>"
                     class="form-input font-mono">
              <p class="text-xs text-gray-500 mt-1">
                <?= __("EAN-13 977 con eventuale add-on") ?><?php if ($testataBarcode !== ''): ?>. <?= __("Se lasciato vuoto, per la scansione e le etichette vale il barcode di base della testata.") ?><?php endif; ?>
              </p>
            </div>
          </div>
          <div class="form-grid-2">
            <div>
              <label for="supplementi" class="form-label"><?= __("Supplementi / allegati") ?></label>
              <input type="text" name="supplementi" id="supplementi"
                     value="<?= $val('supplementi') ?>" maxlength="500"
                     class="form-input">
            </div>
            <div>
              <label for="collocazione_id" class="form-label"><?= __("Collocazione") ?></label>
              <select name="collocazione_id" id="collocazione_id" class="form-input">
                <option value=""><?= __("Nessuna") ?></option>
                <?php foreach ($collocazioni as $collocazioneId => $collocazioneLabel): ?>
                  <option value="<?= (int) $collocazioneId ?>"
                    <?= (int) ($fascicolo['collocazione_id'] ?? 0) === (int) $collocazioneId ? 'selected' : '' ?>>
                    <?= $e($collocazioneLabel) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div>
            <label for="note" class="form-label"><?= __("Note interne") ?></label>
            <textarea name="note" id="note" rows="3"
                      class="form-input"><?= $val('note') ?></textarea>
          </div>
        </div>
      </div>

      <!-- Copertina -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-image text-gray-900"></i>
            <?= __("Copertina") ?>
          </h2>
        </div>
        <div class="card-body">
          <div>
            <div id="emt-cover-upload" class="mb-4"
                 data-emt-uppy="image"
                 data-input="emt-cover-input"
                 data-progress="emt-cover-progress"
                 data-preview="emt-cover-preview"
                 data-preview-image="emt-cover-preview-image"
                 data-note="<?= $e(__('Immagini JPG, PNG o WebP (max 5MB)')) ?>"
                 data-drop="<?= $e(__("Trascina qui l'immagine o %{browse}")) ?>"
                 data-browse="<?= $e(__('seleziona file')) ?>"></div>
            <div id="emt-cover-progress" class="mb-4"></div>
            <input type="file" name="copertina" id="emt-cover-input"
                   accept="image/jpeg,image/jpg,image/png,image/webp" hidden>
            <p class="text-xs text-gray-500 mt-1"><?= __('Il nuovo file sostituisce la copertina attuale.') ?></p>

            <!-- Cover preview area -->
            <div id="emt-cover-preview" class="mt-4 <?= $coverSrc === '' ? 'is-empty' : '' ?>">
              <img id="emt-cover-preview-image" src="<?= $e($coverSrc) ?>"
                   alt="<?= $e(__('Copertina del fascicolo')) ?>"
                   class="max-h-48 object-contain border border-gray-200 rounded-lg shadow-sm"<?= $coverSrc === '' ? ' hidden' : '' ?>>
            </div>
          </div>
        </div>
      </div>

      <!-- Documento digitale -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-file-pdf text-gray-900"></i>
            <?= __('Documento PDF') ?>
          </h2>
        </div>
        <div class="card-body">
          <?php if ($pdfPath !== ''): ?>
            <div class="mb-4 p-3 bg-gray-50 rounded-lg border border-gray-200">
              <div class="flex flex-wrap items-center gap-3">
                <i class="fas fa-file-pdf text-gray-900" aria-hidden="true"></i>
                <div class="min-w-0 flex-1">
                  <p class="text-sm font-medium text-gray-900 truncate"><?= $e($pdfName !== '' ? $pdfName : __('Fascicolo in PDF')) ?></p>
                  <?php if ($pdfSizeLabel !== ''): ?><p class="text-xs text-gray-500"><?= $e($pdfSizeLabel) ?></p><?php endif; ?>
                </div>
                <div>
                  <a href="<?= $pdfAdminUrl ?>" target="_blank" rel="noopener noreferrer" class="btn-secondary">
                    <i class="fas fa-external-link-alt mr-2" aria-hidden="true"></i><?= __('Apri PDF') ?>
                  </a>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <div id="emt-pdf-upload" class="mb-4"
               data-emt-uppy="pdf"
               data-input="emt-pdf-input"
               data-progress="emt-pdf-progress"
               data-result="emt-pdf-result"
               data-note="<?= $e(__('PDF, max 100 MB')) ?>"
               data-drop="<?= $e(__("Trascina qui il PDF o %{browse}")) ?>"
               data-browse="<?= $e(__('seleziona file')) ?>"></div>
          <div id="emt-pdf-progress" class="mb-4"></div>
          <div id="emt-pdf-result" class="mb-4 p-3 bg-gray-50 rounded-lg border border-gray-200 text-sm text-gray-700" hidden></div>
          <input type="file" name="pdf_file" id="emt-pdf-input" accept=".pdf,application/pdf" hidden>

          <div class="space-y-3">
            <label class="flex items-start space-x-2 text-sm cursor-pointer">
              <input type="checkbox" name="pdf_pubblico" value="1" class="<?= $checkboxClass ?> mt-1"
                <?= (int) ($fascicolo['pdf_pubblico'] ?? 0) === 1 ? 'checked' : '' ?>>
              <span>
                <span class="block font-medium text-gray-700"><?= __('Rendi consultabile nel catalogo pubblico') ?></span>
                <span class="block text-xs text-gray-500 mt-1"><?= __('Se non selezionato, il PDF resta accessibile solo agli amministratori.') ?></span>
              </span>
            </label>
            <?php if ($pdfPath !== ''): ?>
              <label class="flex items-start space-x-2 text-sm cursor-pointer">
                <input type="checkbox" name="rimuovi_pdf" value="1" class="<?= $checkboxClass ?> mt-1">
                <span class="font-medium text-red-600"><?= __('Rimuovi il PDF attuale') ?></span>
              </label>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Spoglio (articoli) -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-list-alt text-gray-900"></i>
            <?= __("Spoglio degli articoli") ?>
          </h2>
          <p class="text-sm text-gray-600 mt-1"><?= __("(salvato insieme al fascicolo)") ?></p>
        </div>
        <div class="card-body">
          <div class="emeroteca-admin">
            <div class="emt-articles-card">
              <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200" id="emt-spoglio-table">
                  <thead class="bg-gray-50">
                    <tr>
                      <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Titolo") ?></th>
                      <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Autori") ?></th>
                      <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Pagg. da–a") ?></th>
                      <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Tipo") ?></th>
                      <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"><?= __("Azioni") ?></th>
                    </tr>
                  </thead>
                  <tbody class="bg-white divide-y divide-gray-200" id="emt-spoglio-body">
                    <?php foreach ($articoli as $art): ?>
                      <tr class="emt-art-row">
                        <td class="px-4 py-2" data-label="<?= $e(__('Titolo')) ?>">
                          <input type="text" name="art_titolo[]" maxlength="500"
                                 value="<?= $e($art['titolo']) ?>"
                                 class="form-input text-sm">
                          <input type="text" name="art_keywords[]" maxlength="500"
                                 value="<?= $e($art['keywords'] ?? '') ?>"
                                 placeholder="<?= $e(__('Parole chiave (opzionali)')) ?>"
                                 class="form-input text-sm mt-1">
                        </td>
                        <td class="px-4 py-2" data-label="<?= $e(__('Autori')) ?>">
                          <input type="text" name="art_autori[]" maxlength="500"
                                 value="<?= $e($art['autori'] ?? '') ?>"
                                 class="form-input text-sm">
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap emt-pages" data-label="<?= $e(__('Pagine')) ?>">
                          <input type="number" name="art_pag_da[]" min="0" max="32767"
                                 value="<?= $e($art['pagina_inizio'] ?? '') ?>"
                                 class="form-input text-sm w-20 inline-block">
                          –
                          <input type="number" name="art_pag_a[]" min="0" max="32767"
                                 value="<?= $e($art['pagina_fine'] ?? '') ?>"
                                 class="form-input text-sm w-20 inline-block">
                        </td>
                        <td class="px-4 py-2" data-label="<?= $e(__('Tipo')) ?>">
                          <select name="art_tipo[]" class="form-input text-sm">
                            <?php foreach ($tipoArticoloLabels as $value => $label): ?>
                              <option value="<?= $e($value) ?>" <?= ((string) ($art['tipo'] ?? 'articolo')) === $value ? 'selected' : '' ?>>
                                <?= $e($label) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </td>
                        <td class="px-4 py-2 text-right text-sm whitespace-nowrap" data-label="<?= $e(__('Azioni')) ?>">
                          <button type="button" class="text-xs text-red-600 hover:text-red-800 hover:underline emt-art-remove"><?= __('rimuovi') ?></button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <p class="text-xs text-gray-500 mt-2">
            <?= __("Le righe con titolo vuoto vengono ignorate al salvataggio.") ?>
          </p>
          <div class="mt-4">
            <button type="button" id="emt-art-add" class="btn-secondary">
              <i class="fas fa-plus mr-2" aria-hidden="true"></i><?= __("Aggiungi articolo") ?>
            </button>
          </div>

          <template id="emt-art-template">
            <tr class="emt-art-row">
              <td class="px-4 py-2" data-label="<?= $e(__('Titolo')) ?>">
                <input type="text" name="art_titolo[]" maxlength="500" value=""
                       class="form-input text-sm">
                <input type="text" name="art_keywords[]" maxlength="500" value=""
                       placeholder="<?= $e(__('Parole chiave (opzionali)')) ?>"
                       class="form-input text-sm mt-1">
              </td>
              <td class="px-4 py-2" data-label="<?= $e(__('Autori')) ?>">
                <input type="text" name="art_autori[]" maxlength="500" value=""
                       class="form-input text-sm">
              </td>
              <td class="px-4 py-2 whitespace-nowrap emt-pages" data-label="<?= $e(__('Pagine')) ?>">
                <input type="number" name="art_pag_da[]" min="0" max="32767" value=""
                       class="form-input text-sm w-20 inline-block">
                –
                <input type="number" name="art_pag_a[]" min="0" max="32767" value=""
                       class="form-input text-sm w-20 inline-block">
              </td>
              <td class="px-4 py-2" data-label="<?= $e(__('Tipo')) ?>">
                <select name="art_tipo[]" class="form-input text-sm">
                  <?php foreach ($tipoArticoloLabels as $value => $label): ?>
                    <option value="<?= $e($value) ?>"><?= $e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td class="px-4 py-2 text-right text-sm whitespace-nowrap" data-label="<?= $e(__('Azioni')) ?>">
                <button type="button" class="text-xs text-red-600 hover:text-red-800 hover:underline emt-art-remove"><?= __('rimuovi') ?></button>
              </td>
            </tr>
          </template>
        </div>
      </div>

      <div class="flex flex-col sm:flex-row gap-4 justify-end">
        <a href="<?= $issuesUrl ?>" class="btn-secondary order-2 sm:order-1">
          <i class="fas fa-times mr-2"></i>
          <?= __("Annulla") ?>
        </a>
        <button type="submit" class="btn-primary order-1 sm:order-2">
          <i class="fas fa-save mr-2"></i>
          <?= __("Salva fascicolo") ?>
        </button>
      </div>
    </form>

    <div class="mt-8 pt-6 border-t border-gray-200 flex justify-end">
      <form method="POST" action="<?= $deleteAction ?>"
            onsubmit="return confirm(<?= $e(json_encode(__('Eliminare questo fascicolo e il suo spoglio?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <button type="submit" class="btn-danger">
          <i class="fas fa-trash mr-2" aria-hidden="true"></i><?= __("Elimina fascicolo") ?>
        </button>
      </form>
    </div>
  </div>
</div>

<script>
// Spoglio: add/remove article rows (vanilla JS, no libraries).
(function () {
    var body = document.getElementById('emt-spoglio-body');
    var tpl = document.getElementById('emt-art-template');
    var addBtn = document.getElementById('emt-art-add');
    if (!body || !tpl || !addBtn) {
        return;
    }
    addBtn.addEventListener('click', function () {
        body.appendChild(tpl.content.cloneNode(true));
    });
    document.getElementById('emt-spoglio-table').addEventListener('click', function (ev) {
        var btn = ev.target.closest('.emt-art-remove');
        if (!btn) {
            return;
        }
        var row = btn.closest('tr.emt-art-row');
        if (row) {
            row.remove();
        }
    });
})();
</script>
<script src="<?= $e(url('/plugins/emeroteca/assets/js/emeroteca-upload.js?v=1.3.0')) ?>" defer></script>

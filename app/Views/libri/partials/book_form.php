<?php
use App\Support\Hooks;
use App\Support\HtmlHelper;

$mode = $mode ?? 'create';
$book = $book ?? [];
$csrfToken = $csrfToken ?? null;
$error_message = $error_message ?? null;
$action = $action ?? ($mode === 'edit' ? '/admin/libri/update/' . ($book['id'] ?? '') : '/admin/libri/crea');
$currentCover = $book['copertina_url'] ?? ($book['copertina'] ?? '');
$scrapingAvailable = Hooks::has('scrape.fetch.custom');

$initialAuthors = array_map(static function ($author) {
    return [
        'id' => (int)($author['id'] ?? 0),
        'label' => $author['nome'] ?? ''
    ];
}, $book['autori'] ?? []);

$initialMensolaId = (int)($book['mensola_id'] ?? 0);
$initialPosizioneProgressiva = (int)($book['posizione_progressiva'] ?? 0);
$initialCollocazione = $book['collocazione'] ?? '';

$initialData = [
    'id' => (int)($book['id'] ?? 0),
    'radice_id' => (int)($book['radice_id'] ?? 0),
    'genere_id' => (int)($book['genere_id'] ?? 0),
    'sottogenere_id' => (int)($book['sottogenere_id'] ?? 0),
    'classificazione_dewey' => $book['classificazione_dewey'] ?? '',
    'editore_id' => (int)($book['editore_id'] ?? 0),
    'editore_nome' => $book['editore_nome'] ?? '',
    'scaffale_id' => (int)($book['scaffale_id'] ?? 0),
    'mensola_id' => $initialMensolaId,
    'posizione_progressiva' => $initialPosizioneProgressiva,
    'collocazione' => $initialCollocazione,
    'stato' => $book['stato'] ?? '',
    'tipo_acquisizione' => $book['tipo_acquisizione'] ?? '',
    'data_acquisizione' => $book['data_acquisizione'] ?? date('Y-m-d'),
    'prezzo' => $book['prezzo'] ?? '',
    'peso' => $book['peso'] ?? '',
    'numero_pagine' => $book['numero_pagine'] ?? '',
    'numero_inventario' => $book['numero_inventario'] ?? '',
    'collana' => $book['collana'] ?? '',
    'numero_serie' => $book['numero_serie'] ?? '',
    'note_varie' => $book['note_varie'] ?? '',
    'file_url' => $book['file_url'] ?? '',
    'audio_url' => $book['audio_url'] ?? '',
    'parole_chiave' => $book['parole_chiave'] ?? '',
];

$initialData['autori'] = $initialAuthors;
$initialData['current_cover'] = $currentCover;

$initialAuthorsJson = htmlspecialchars(json_encode($initialAuthors, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
$initialDataJsonRaw = json_encode($initialData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$modeAttr = htmlspecialchars($mode, ENT_QUOTES, 'UTF-8');
$actionAttr = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
?>
<?php if (!empty($error_message)): ?>
  <div class="mb-6 p-4 rounded-xl border border-red-200 bg-red-50 text-red-700" role="alert">
    <i class="fas fa-exclamation-triangle mr-2"></i>
    <?php echo HtmlHelper::e($error_message); ?>
  </div>
<?php endif; ?>

    <form id="bookForm" data-mode="<?php echo $modeAttr; ?>" method="post" action="<?php echo $actionAttr; ?>" class="space-y-8" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
      
      <!-- Hidden fields for scraped data -->
      <input type="hidden" id="scraped_ean" name="scraped_ean" value="">
      <input type="hidden" id="scraped_pub_date" name="scraped_pub_date" value="">
      <input type="hidden" id="scraped_price" name="scraped_price" value="">
      <input type="hidden" id="scraped_format" name="scraped_format" value="">
      <input type="hidden" id="scraped_series" name="scraped_series" value="">
      <input type="hidden" id="scraped_pages" name="scraped_pages" value="">
      <input type="hidden" id="scraped_publisher" name="scraped_publisher" value="">
      <input type="hidden" id="scraped_translator" name="scraped_translator" value="">
      <input type="hidden" id="scraped_cover_url" name="scraped_cover_url" value="">
      <input type="hidden" id="copertina_url" name="copertina_url" value="<?php echo HtmlHelper::e($currentCover); ?>">
      <input type="hidden" id="remove_cover" name="remove_cover" value="0">
      <input type="hidden" id="scraped_tipologia" name="scraped_tipologia" value="">
      <input type="hidden" id="scraped_author_bio" name="scraped_author_bio" value="">

      <?php if ($scrapingAvailable): ?>
      <div class="card mb-8">
        <div class="card-header">
          <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            <i class="fas fa-barcode text-primary"></i>
            <?= __("Importa da ISBN") ?>
          </h2>
          <p class="text-sm text-gray-600 mt-1"><?= __("Usa i servizi online per precompilare automaticamente i dati del libro") ?></p>
        </div>
        <div class="card-body">
          <div class="form-grid-2">
            <div>
              <label class="form-label"><?= __("Codice ISBN o EAN") ?></label>
              <input id="importIsbn" type="text" class="form-input" placeholder="<?= __('es. 9788842935780') ?>" />
            </div>
            <div class="flex items-end">
              <button type="button" id="btnImportIsbn" class="btn-primary w-full">
                <i class="fas fa-download mr-2"></i>
                <?= $mode === 'edit' ? __("Aggiorna Dati") : __("Importa Dati") ?>
              </button>
            </div>
          </div>
          <!-- Source info panel (shown after successful import) -->
          <div id="scrapeSourceInfo" class="hidden mt-4 p-3 bg-gray-50 rounded-lg border border-gray-200">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2 text-sm">
                <i class="fas fa-database text-primary"></i>
                <span class="text-gray-600"><?= __("Fonte dati:") ?></span>
                <span id="scrapeSourceName" class="font-medium text-gray-900"></span>
              </div>
              <button type="button" id="btnShowAlternatives" class="text-xs text-primary hover:text-primary-dark hover:underline hidden" aria-expanded="false" aria-controls="scrapeAlternativesPanel">
                <i class="fas fa-exchange-alt mr-1"></i>
                <?= __("Vedi alternative") ?>
              </button>
            </div>
            <div id="scrapeSourcesList" class="mt-2 text-xs text-gray-500 hidden">
              <span><?= __("Fonti consultate:") ?></span>
              <span id="scrapeSourcesListItems"></span>
            </div>
          </div>
          <!-- Alternatives panel (shown when clicking "Vedi alternative") -->
          <div id="scrapeAlternativesPanel" class="hidden mt-4 p-4 bg-blue-50 rounded-lg border border-blue-200">
            <div class="flex items-center justify-between mb-3">
              <h4 class="text-sm font-semibold text-blue-900 flex items-center gap-2">
                <i class="fas fa-layer-group"></i>
                <?= __("Dati alternativi disponibili") ?>
              </h4>
              <button type="button" id="btnCloseAlternatives" class="text-blue-600 hover:text-blue-800" aria-label="<?= __('Chiudi alternative') ?>">
                <i class="fas fa-times"></i>
              </button>
            </div>
            <div id="alternativesContent" class="space-y-2 text-sm">
              <!-- Populated by JavaScript -->
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Basic Information Section -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-book text-primary"></i>
            <?= __("Informazioni Base") ?>
          </h2>
        </div>
        <div class="card-body form-section">
          <div class="form-grid-2">
            <div>
              <label for="titolo" class="form-label">
                <?= __("Titolo") ?> <span class="text-red-500">*</span>
              </label>
              <input id="titolo" name="titolo" type="text" required aria-required="true" class="form-input" placeholder="<?= __('es. La morale anarchica') ?>" value="<?php echo HtmlHelper::e($book['titolo'] ?? ''); ?>" />
            </div>
            <div>
              <label for="sottotitolo" class="form-label"><?= __("Sottotitolo") ?></label>
              <input id="sottotitolo" name="sottotitolo" type="text" class="form-input" placeholder="<?= __('Sottotitolo del libro (opzionale)') ?>" value="<?php echo HtmlHelper::e($book['sottotitolo'] ?? ''); ?>" />
            </div>
          </div>
          
          <div class="form-grid-3">
            <div>
              <label for="isbn10" class="form-label"><?= __("ISBN 10") ?></label>
              <input id="isbn10" name="isbn10" type="text" class="form-input" placeholder="<?= __('es. 8842935786') ?>" value="<?php echo HtmlHelper::e($book['isbn10'] ?? ''); ?>" />
            </div>
            <div>
              <label for="isbn13" class="form-label"><?= __("ISBN 13") ?></label>
              <input id="isbn13" name="isbn13" type="text" class="form-input" placeholder="<?= __('es. 9788842935780') ?>" value="<?php echo HtmlHelper::e($book['isbn13'] ?? ''); ?>" />
            </div>
            <div>
              <label for="edizione" class="form-label"><?= __("Edizione") ?></label>
              <input id="edizione" name="edizione" type="text" class="form-input" placeholder="<?= __('es. Prima edizione') ?>" value="<?php echo HtmlHelper::e($book['edizione'] ?? ''); ?>" />
              <p class="text-xs text-gray-500 mt-1"><?= __("Numero o descrizione dell'edizione") ?></p>
            </div>
          </div>

          <div class="form-grid-2">
            <div>
              <label for="data_pubblicazione" class="form-label"><?= __("Data di Pubblicazione") ?></label>
              <input id="data_pubblicazione" name="data_pubblicazione" type="text" class="form-input" placeholder="<?= __('es. 26 agosto 2025') ?>" value="<?php echo HtmlHelper::e($book['data_pubblicazione'] ?? ''); ?>" />
              <p class="text-xs text-gray-500 mt-1"><?= __("Data originale di pubblicazione (formato italiano)") ?></p>
            </div>
            <div>
              <label for="anno_pubblicazione" class="form-label"><?= __("Anno di Pubblicazione") ?></label>
              <input id="anno_pubblicazione" name="anno_pubblicazione" type="number" min="1" max="9999" class="form-input" placeholder="<?= __('es. 2025') ?>" value="<?php echo HtmlHelper::e($book['anno_pubblicazione'] ?? ''); ?>" />
              <p class="text-xs text-gray-500 mt-1"><?= __("Anno numerico (usato per filtri e ordinamento)") ?></p>
            </div>
          </div>

          <div class="form-grid-2">
            <div>
              <label for="ean" class="form-label"><?= __("EAN") ?></label>
              <input id="ean" name="ean" type="text" class="form-input" placeholder="<?= __('es. 9788842935780') ?>" value="<?php echo HtmlHelper::e($book['ean'] ?? ''); ?>" />
              <p class="text-xs text-gray-500 mt-1"><?= __("European Article Number (opzionale)") ?></p>
            </div>
            <div>
              <label for="lingua" class="form-label"><?= __("Lingua") ?></label>
              <input id="lingua" name="lingua" type="text" class="form-input" placeholder="<?= __('es. Italiano, Inglese') ?>" value="<?php echo HtmlHelper::e($book['lingua'] ?? ''); ?>" />
              <p class="text-xs text-gray-500 mt-1"><?= __("Lingua originale del libro") ?></p>
            </div>
          </div>
          <div class="mt-2 text-xs text-gray-500" id="genre_path_preview" style="min-height:1.25rem;">
            <!-- Percorso selezionato -->
          </div>

          <!-- Publisher with Enhanced Search -->
          <div>
            <label for="editore_field" class="form-label"><?= __("Editore") ?></label>
            <div class="relative">
              <div id="editore_field" class="choices choices--multiple">
                <div class="choices__inner form-input pr-10 flex flex-wrap items-center gap-2">
                  <div id="editore_chip_list" class="choices__list choices__list--multiple flex flex-wrap items-center gap-2"></div>
                  <input id="editore_search" name="editore_search_display" type="text"
                         placeholder="<?= __('Cerca editore esistente o inserisci nuovo...') ?>"
                         class="choices__input choices__input--cloned flex-1 bg-transparent focus:outline-none border-none outline-none"
                         style="min-width: 140px; flex: 1 1 140px;"
                         autocomplete="off"
                         value="<?php echo HtmlHelper::e($book['editore_nome'] ?? ''); ?>" />
                </div>
              </div>
              <i class="fas fa-search absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
              <input type="hidden" name="editore_id" id="editore_id" value="<?php echo (int)($book['editore_id'] ?? 0); ?>" />
              <input type="hidden" name="editore_search" id="editore_search_value" value="<?php echo HtmlHelper::e($book['editore_nome'] ?? ''); ?>" />
              <ul id="editore_suggest" class="absolute z-20 bg-white border border-gray-200 rounded-lg mt-1 w-full hidden shadow-lg p-0"></ul>
              <p class="text-xs text-gray-500 mt-1" id="editore_hint"></p>
            </div>
          </div>

          <!-- Authors with Choices.js -->
          <div>
            <label for="autori_select" class="form-label"><?= __("Autori") ?></label>
            <select id="autori_select" name="autori_select[]" multiple placeholder="<?= __('Cerca autori esistenti o aggiungine di nuovi...') ?>" data-initial-authors="<?php echo $initialAuthorsJson; ?>">
              <!-- Options will be populated dynamically -->
            </select>
            <div id="autori_hidden"></div>
            <p class="text-xs text-gray-500 mt-1"><?= __("Puoi selezionare più autori o aggiungerne di nuovi digitando il nome") ?></p>
          </div>

          <!-- Book Status -->
          <div>
            <label for="stato" class="form-label"><?= __("Disponibilità") ?></label>
            <?php $statoCorrente = $book['stato'] ?? ''; ?>
            <select id="stato" name="stato" class="form-input">
              <option value="Disponibile" <?php echo strcasecmp($statoCorrente, 'Disponibile') === 0 ? 'selected' : ''; ?>><?= __("Disponibile") ?></option>
              <option value="Non Disponibile" <?php echo strcasecmp($statoCorrente, 'Non Disponibile') === 0 ? 'selected' : ''; ?>><?= __("Non Disponibile") ?></option>
              <option value="Prestato" <?php echo strcasecmp($statoCorrente, 'Prestato') === 0 ? 'selected' : ''; ?>><?= __("Prestato") ?></option>
              <option value="Riservato" <?php echo strcasecmp($statoCorrente, 'Riservato') === 0 ? 'selected' : ''; ?>><?= __("Riservato") ?></option>
              <option value="Danneggiato" <?php echo strcasecmp($statoCorrente, 'Danneggiato') === 0 ? 'selected' : ''; ?>><?= __("Danneggiato") ?></option>
              <option value="Perso" <?php echo strcasecmp($statoCorrente, 'Perso') === 0 ? 'selected' : ''; ?>><?= __("Perso") ?></option>
              <option value="In Riparazione" <?php echo strcasecmp($statoCorrente, 'In Riparazione') === 0 ? 'selected' : ''; ?>><?= __("In Riparazione") ?></option>
              <option value="Fuori Catalogo" <?php echo strcasecmp($statoCorrente, 'Fuori Catalogo') === 0 ? 'selected' : ''; ?>><?= __("Fuori Catalogo") ?></option>
              <option value="Da Inventariare" <?php echo strcasecmp($statoCorrente, 'Da Inventariare') === 0 ? 'selected' : ''; ?>><?= __("Da Inventariare") ?></option>
            </select>
            <p class="text-xs text-gray-500 mt-1"><?= __("Status attuale di questa copia del libro") ?></p>
          </div>

          <!-- Description -->
          <div>
            <label for="descrizione" class="form-label"><?= __("Descrizione") ?></label>
            <textarea id="descrizione" name="descrizione" rows="4" class="form-input" placeholder="<?= __('Descrizione del libro...') ?>"><?php echo HtmlHelper::e($book['descrizione'] ?? ''); ?></textarea>
          </div>
        </div>
      </div>
      <!-- Dewey Classification Section -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-tags text-primary"></i>
            <?= __("Classificazione Dewey") ?>
          </h2>
        </div>
        <div class="card-body form-section">
          <input type="hidden" name="classificazione_dewey" id="classificazione_dewey" value="<?php echo HtmlHelper::e($book['classificazione_dewey'] ?? ''); ?>" />

          <!-- Chip Dewey selezionato -->
          <div id="dewey_chip_container" class="mb-4" style="display: none;">
            <label class="form-label"><?= __("Classificazione selezionata:") ?></label>
            <div id="dewey_chip" class="inline-flex items-center gap-2 bg-blue-100 text-blue-800 px-3 py-2 rounded-lg">
              <span class="font-mono font-bold" id="dewey_chip_code"></span>
              <span class="text-sm" id="dewey_chip_name"></span>
              <button type="button" id="dewey_chip_remove" class="text-blue-600 hover:text-blue-900">
                <i class="fas fa-times"></i>
              </button>
            </div>
          </div>

          <!-- Input manuale Dewey -->
          <div class="mb-4">
            <label for="dewey_manual_input" class="form-label"><?= __("Codice Dewey") ?></label>
            <div class="flex gap-2">
              <input type="text" id="dewey_manual_input" class="form-input" placeholder="<?= __('es. 599.9, 004.6782, 641.5945, 599.1') ?>" />
              <button type="button" id="dewey_add_btn" class="btn btn-primary">
                <i class="fas fa-plus"></i> <?= __("Aggiungi") ?>
              </button>
            </div>
            <p class="text-xs text-gray-500 mt-1"><?= __("Inserisci qualsiasi codice Dewey (anche se non presente nell'elenco)") ?></p>
          </div>

          <!-- Navigazione per categorie (opzionale) -->
          <details class="mb-4">
            <summary class="cursor-pointer text-sm font-semibold text-gray-700 hover:text-blue-600">
              <?= __("Oppure naviga per categorie") ?>
            </summary>
            <div class="mt-3 p-3 bg-gray-50 rounded">
              <div id="dewey_breadcrumb" class="text-xs text-gray-600 mb-2 flex items-center gap-1">
                <i class="fas fa-home"></i>
                <span><?= __("Nessuna selezione") ?></span>
              </div>
              <div id="dewey_levels_container" class="space-y-2">
                <!-- I select verranno aggiunti dinamicamente -->
              </div>
            </div>
          </details>

          <p class="text-xs text-gray-500 mt-2"><?= __("La classificazione Dewey è utilizzata per organizzare i libri per argomento secondo standard internazionali") ?></p>

          <h3 class="text-lg font-semibold text-gray-900 mt-6 mb-4"><?= __("Genere") ?></h3>

          <div class="form-grid-3">
            <div>
              <label for="radice_select" class="form-label"><?= __("Radice") ?></label>
              <select id="radice_select" name="radice_id" class="form-input" data-initial-radice="<?php echo (int)$initialData['radice_id']; ?>">
                <option value="0"><?= __("Seleziona radice...") ?></option>
              </select>
              <p class="text-xs text-gray-500 mt-1"><?= __("Livello principale (es. Prosa, Poesia, Teatro)") ?></p>
            </div>
            <div>
              <label for="genere_select" class="form-label"><?= __("Genere") ?></label>
              <select id="genere_select" name="genere_id" class="form-input" disabled data-initial-genere="<?php echo (int)$initialData['genere_id']; ?>">
                <option value="0"><?= __("Seleziona prima una radice...") ?></option>
              </select>
              <p class="text-xs text-gray-500 mt-1" id="genere_hint"><?= __("Genere letterario del libro") ?></p>
            </div>
            <div>
              <label for="sottogenere_select" class="form-label"><?= __("Sottogenere") ?></label>
              <select id="sottogenere_select" name="sottogenere_id" class="form-input" disabled data-initial-sottogenere="<?php echo (int)$initialData['sottogenere_id']; ?>">
                <option value="0"><?= __("Seleziona prima un genere...") ?></option>
              </select>
              <p class="text-xs text-gray-500 mt-1" id="sottogenere_hint"><?= __("Sottogenere specifico (opzionale)") ?></p>
            </div>
          </div>

          <!-- Keywords -->
          <div class="mt-4">
            <label for="parole_chiave" class="form-label"><?= __("Parole Chiave") ?></label>
            <input id="parole_chiave" name="parole_chiave" type="text" class="form-input" placeholder="<?= __('es. romanzo, fantasy, avventura (separare con virgole)') ?>" value="<?php echo HtmlHelper::e($book['parole_chiave'] ?? ''); ?>" />
            <p class="text-xs text-gray-500 mt-1"><?= __("Inserisci parole chiave separate da virgole per facilitare la ricerca") ?></p>
          </div>
        </div>
      </div>
      <!-- Acquisition Details Section -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-shopping-cart text-primary"></i>
            <?= __("Dettagli Acquisizione") ?>
          </h2>
        </div>
        <div class="card-body form-section">
          <div class="form-grid-3">
            <div>
              <label for="tipo_acquisizione" class="form-label"><?= __("Data Acquisizione") ?></label>
              <input type="date" name="data_acquisizione" class="form-input" value="<?php echo HtmlHelper::e($book['data_acquisizione'] ?? ''); ?>" />
            </div>
            <div>
              <label for="tipo_acquisizione" class="form-label"><?= __("Tipo Acquisizione") ?></label>
              <input id="tipo_acquisizione" name="tipo_acquisizione" type="text" class="form-input" placeholder="<?= __('es. Acquisto, Donazione, Prestito') ?>" value="<?php echo HtmlHelper::e($book['tipo_acquisizione'] ?? ''); ?>" />
            </div>
            <div>
              <label for="prezzo" class="form-label"><?= __("Prezzo (€)") ?></label>
              <input id="prezzo" name="prezzo" type="number" step="0.01" class="form-input" placeholder="<?= __('es. 19.90') ?>" value="<?php echo HtmlHelper::e($book['prezzo'] ?? ''); ?>" />
            </div>
          </div>
        </div>
      </div>

      <!-- Physical Details Section -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-ruler text-primary"></i>
            <?= __("Dettagli Fisici") ?>
          </h2>
        </div>
        <div class="card-body form-section">
          <div class="form-grid-3">
            <div>
              <label for="formato" class="form-label"><?= __("Formato") ?></label>
              <input id="formato" name="formato" type="text" class="form-input" placeholder="<?= __('es. Copertina rigida, Brossura') ?>" value="<?php echo HtmlHelper::e($book['formato'] ?? ''); ?>" />
            </div>
            <div>
              <label for="numero_pagine" class="form-label"><?= __("Numero Pagine") ?></label>
              <input id="numero_pagine" name="numero_pagine" type="number" class="form-input" placeholder="<?= __('es. 320') ?>" value="<?php echo HtmlHelper::e($book['numero_pagine'] ?? ''); ?>" />
            </div>
            <div>
              <label for="peso" class="form-label"><?= __("Peso (kg)") ?></label>
              <input id="peso" name="peso" type="number" step="0.001" class="form-input" placeholder="<?= __('es. 0.450') ?>" value="<?php echo HtmlHelper::e($book['peso'] ?? ''); ?>" />
            </div>
          </div>

          <div>
            <label for="dimensioni" class="form-label"><?= __("Dimensioni") ?></label>
            <input id="dimensioni" name="dimensioni" type="text" class="form-input" placeholder="<?= __('es. 21x14 cm') ?>" value="<?php echo HtmlHelper::e($book['dimensioni'] ?? ''); ?>" />
          </div>
          
          <div class="form-grid-3">
            <div>
              <label for="copie_totali" class="form-label"><?= __("Copie Totali") ?> <span class="text-xs text-gray-500">(<?= __("Le copie disponibili vengono calcolate automaticamente") ?>)</span></label>
              <input id="copie_totali" name="copie_totali" type="number" class="form-input" value="<?php echo (int)($book['copie_totali'] ?? 1); ?>" min="<?php echo $mode === 'edit' ? (int)($book['copie_totali'] ?? 1) : 1; ?>" />
              <?php if ($mode === 'edit'): ?>
              <p class="text-xs text-gray-600 mt-1">
                <i class="fas fa-info-circle text-blue-500 mr-1"></i>
                Puoi ridurre le copie solo se non sono in prestito, perse o danneggiate.
              </p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <!-- Library Management Section -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-warehouse text-primary"></i>
            <?= __("Gestione Biblioteca") ?>
          </h2>
        </div>
        <div class="card-body form-section">
          <div class="form-grid-3">
            <div>
              <label for="numero_inventario" class="form-label"><?= __("Numero Inventario") ?></label>
              <input id="numero_inventario" name="numero_inventario" type="text" class="form-input" placeholder="<?= __('es. INV-2024-001') ?>" value="<?php echo HtmlHelper::e($book['numero_inventario'] ?? ''); ?>" />
            </div>
            <div>
              <label for="collana" class="form-label"><?= __("Collana") ?></label>
              <input id="collana" name="collana" type="text" class="form-input" placeholder="<?= __('es. I Classici') ?>" value="<?php echo HtmlHelper::e($book['collana'] ?? ''); ?>" />
            </div>
            <div>
              <label for="numero_serie" class="form-label"><?= __("Numero Serie") ?></label>
              <input id="numero_serie" name="numero_serie" type="text" class="form-input" placeholder="<?= __('es. 15') ?>" value="<?php echo HtmlHelper::e($book['numero_serie'] ?? ''); ?>" />
            </div>
          </div>

          <div class="form-grid-2">
            <div>
              <label for="file_url" class="form-label"><?= __("File URL") ?></label>
              <input id="file_url" name="file_url" type="text" class="form-input" placeholder="<?= __('Link al file digitale (se disponibile)') ?>" value="<?php echo HtmlHelper::e($book['file_url'] ?? ''); ?>" />
            </div>
            <div>
              <label for="audio_url" class="form-label"><?= __("Audio URL") ?></label>
              <input id="audio_url" name="audio_url" type="text" class="form-input" placeholder="<?= __('Link all\'audiolibro (se disponibile)') ?>" value="<?php echo HtmlHelper::e($book['audio_url'] ?? ''); ?>" />
            </div>
          </div>

          <?php
          // Hook: Allow plugins to add digital content upload fields (e.g., Uppy uploaders)
          do_action('book.form.digital_fields', $book ?? []);
          ?>

          <!-- Notes -->
          <div>
            <label for="note_varie" class="form-label"><?= __("Note Varie") ?></label>
            <textarea id="note_varie" name="note_varie" rows="3" class="form-input" placeholder="<?= __('Note aggiuntive o osservazioni particolari...') ?>"><?php echo HtmlHelper::e($book['note_varie'] ?? ''); ?></textarea>
          </div>
        </div>
      </div>

      <!-- Cover Upload Section -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-image text-primary"></i>
            <?= __("Copertina del Libro") ?>
          </h2>
        </div>
        <div class="card-body">
          <!-- Uppy Upload Area -->
          <div>
            <div id="uppy-upload" class="mb-4"></div>
            <div id="uppy-progress" class="mb-4"></div>
            
            <!-- Fallback file input (hidden, used by Uppy) -->
            <input type="file" name="copertina" accept="image/*" style="display: none;" id="fallback-file-input" />
            
            <!-- Cover preview area -->
            <div id="cover-preview-container" class="mt-4">
              <?php if (!empty($currentCover)): ?>
                <div class="inline-flex flex-col items-start space-y-2">
                  <div class="relative group">
                    <img src="<?php echo HtmlHelper::e($currentCover); ?>" alt="Copertina attuale" class="max-h-48 object-contain border border-gray-200 rounded-lg shadow-sm" onerror="this.dataset.error='true'; this.style.display='none';" />
                  </div>
                  <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-500"><?= __("Copertina attuale") ?></span>
                    <button type="button" onclick="removeCoverImage()" class="text-xs text-red-600 hover:text-red-800 hover:underline flex items-center gap-1">
                      <i class="fas fa-trash"></i>
                      Rimuovi
                    </button>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <!-- Campi nascosti per conservare i dati estratti evitando duplicazioni -->

      <!-- Physical Location Section -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-map-marker-alt text-primary"></i>
            <?= __("Posizione Fisica nella Biblioteca") ?>
          </h2>
        </div>
        <div class="card-body form-section">
          <div class="form-grid-2">
            <div>
              <label for="scaffale_id" class="form-label"><?= __("Scaffale") ?></label>
              <select id="scaffale_id" name="scaffale_id" class="form-input">
                <option value="0"><?= __("Seleziona scaffale...") ?></option>
                <?php foreach ($scaffali as $s): ?>
                  <option value="<?php echo (int)$s['id']; ?>" <?php echo ((int)$s['id'] === (int)($book['scaffale_id'] ?? 0)) ? 'selected' : ''; ?>><?php echo htmlspecialchars('['.($s['codice'] ?? '').'] '.($s['nome'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="form-label"><?= __("Mensola") ?></label>
              <?php
                $mensoleOptions = [];
                $selectedMensola = $initialMensolaId;
                $selectedScaffale = (int)($book['scaffale_id'] ?? 0);
                if ($selectedMensola && $selectedScaffale) {
                    foreach ($mensole as $m) {
                        if ((int)($m['scaffale_id'] ?? 0) === $selectedScaffale) {
                            $mensoleOptions[] = $m;
                        }
                    }
                }
              ?>
              <select id="mensola_select" name="mensola_id" class="form-input" <?php echo $selectedMensola ? '' : 'disabled'; ?> data-initial-mensola="<?php echo $selectedMensola; ?>">
                <?php if (!$mensoleOptions): ?>
                  <option value="0"><?= __("Seleziona prima uno scaffale...") ?></option>
                <?php else: ?>
                  <option value="0"><?= __("Seleziona mensola...") ?></option>
                  <?php foreach ($mensoleOptions as $mensola): ?>
                    <option value="<?php echo (int)$mensola['id']; ?>" <?php echo ((int)$mensola['id'] === $selectedMensola) ? 'selected' : ''; ?>>
                      <?php echo HtmlHelper::e(__('Livello') . ' ' . ($mensola['numero_livello'] ?? '')); ?>
                    </option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </div>
          </div>
          <div class="form-grid-2 mt-3">
            <div>
              <label for="posizione_progressiva_input" class="form-label"><?= __("Posizione progressiva") ?></label>
              <div class="flex flex-col gap-2">
                <input type="number" min="1" name="posizione_progressiva" id="posizione_progressiva_input" class="form-input" value="<?php echo $initialPosizioneProgressiva ?: ''; ?>" placeholder="<?= __('Auto') ?>" />
                <button type="button" id="btnAutoPosition" class="btn-outline w-full sm:w-auto"><i class="fas fa-sync mr-2"></i><?= __("Genera automaticamente") ?></button>
                <p class="text-xs text-gray-500"><?= __("Lascia vuoto o usa \"Genera\" per assegnare automaticamente la prossima posizione disponibile.") ?></p>
              </div>
            </div>
            <div>
              <label for="collocazione_preview" class="form-label"><?= __("Collocazione calcolata") ?></label>
              <input type="text" id="collocazione_preview" name="collocazione_preview" class="form-input bg-slate-900/20 text-slate-100" value="<?php echo HtmlHelper::e($initialCollocazione); ?>" readonly />
              <p class="text-xs text-gray-500 mt-1"><?= __("Aggiornata in base a scaffale, mensola e posizione.") ?></p>
            </div>
          </div>
          <p class="text-xs text-gray-500 mt-2"><?= __("La posizione fisica è indipendente dalla classificazione Dewey e indica dove si trova il libro sugli scaffali.") ?></p>
          <div class="mt-3">
            <button type="button" id="btnSuggestCollocazione" class="btn-outline"><i class="fas fa-magic mr-2"></i><?= __("Suggerisci collocazione") ?></button>
            <span id="suggest_info" class="ml-2 text-xs text-gray-500"></span>
          </div>
        </div>
      </div>

      <!-- Submit Section -->
      <?php
      // Plugin hook: Additional fields in book form (backend)
      $bookData = $mode === 'edit' ? ($libro ?? null) : null;
      $bookId = $mode === 'edit' ? ($libro['id'] ?? null) : null;
      \App\Support\Hooks::do('book.form.fields', [$bookData, $bookId]);
      ?>

      <div class="flex flex-col sm:flex-row gap-4 justify-end">
        <button type="button" id="btnCancel" class="btn-secondary order-2 sm:order-1">
          <i class="fas fa-times mr-2"></i>
          <?= __("Annulla") ?>
        </button>
        <button type="submit" class="btn-primary order-1 sm:order-2">
          <i class="fas fa-save mr-2"></i>
          <?php echo $mode === 'edit' ? __('Salva Modifiche') : __('Salva Libro'); ?>
        </button>
      </div>
    </form>
  </div>
</div>
<!-- CSS and JavaScript Libraries - LOCAL NPM PACKAGES VIA WEBPACK -->
<link rel="stylesheet" href="/assets/vendor.css">
<script src="/assets/vendor.bundle.js"></script>

<script>
const FORM_MODE = <?php echo json_encode($mode); ?>;
const INITIAL_BOOK = <?php echo $initialDataJsonRaw; ?>;
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;

// i18n translations for JavaScript - Inject PHP translations into JS
// Merge global translations (from layout) with local fallbacks; global wins if defined
const i18nTranslations = Object.assign({}, window.i18nTranslations || {}, <?= json_encode([
    'Nessun sottogenere' => __("Nessun sottogenere"),
    'Ricerca in corso...' => __("Ricerca in corso..."),
    'Errore nella ricerca' => __("Errore nella ricerca"),
    'Seleziona classe...' => __("Seleziona classe..."),
    'Seleziona divisione...' => __("Seleziona divisione..."),
    'Seleziona sezione...' => __("Seleziona sezione..."),
    'Seleziona radice...' => __("Seleziona radice..."),
    'Seleziona prima una radice...' => __("Seleziona prima una radice..."),
    'Seleziona genere...' => __("Seleziona genere..."),
    'Seleziona prima un genere...' => __("Seleziona prima un genere..."),
    '<?= __("Errore caricamento classificazione Dewey") ?>' => __("Errore caricamento classificazione Dewey"),
    'Rimuovi editore' => __("Rimuovi editore"),
    'Livello' => __("Livello"),
    '<?= __("Seleziona mensola...") ?>' => __("Seleziona mensola..."),
    '<?= __("Seleziona prima uno scaffale...") ?>' => __("Seleziona prima uno scaffale..."),
    'Aggiornamento in corso...' => __("Aggiornamento in corso..."),
    'Aggiornamento...' => __("Aggiornamento..."),
    'Salvataggio in corso...' => __("Salvataggio in corso..."),
    'Importazione...' => __("Importazione..."),
    'Attendere prego' => __("Attendere prego"),
    'Generazione...' => __("Generazione..."),
    'Genera automaticamente' => __("Genera automaticamente"),
    'Immagine Caricata!' => __("Immagine Caricata!"),
    'Aggiungi' => __("Aggiungi"),
    'come nuovo autore' => __("come nuovo autore"),
    'Rimuovi' => __("Rimuovi"),
    'Conferma Aggiornamento' => __("Conferma Aggiornamento"),
    'Conferma Salvataggio' => __("Conferma Salvataggio"),
    'Sì, Aggiorna' => __("Sì, Aggiorna"),
    'Sì, Salva' => __("Sì, Salva"),
    'Vuoi aggiornare il libro "%s"?' => __("Vuoi aggiornare il libro \"%s\"?"),
    'Sei sicuro di voler salvare il libro "%s"?' => __("Sei sicuro di voler salvare il libro \"%s\"?"),
    'Conferma Annullamento' => __("Conferma Annullamento"),
    'Sei sicuro di voler annullare? Tutti i dati inseriti andranno persi.' => __("Sei sicuro di voler annullare? Tutti i dati inseriti andranno persi."),
    'Sì, Annulla' => __("Sì, Annulla"),
    'Continua' => __("Continua"),
    'Errore' => __("Errore"),
    'Si è verificato un errore durante il salvataggio.' => __("Si è verificato un errore durante il salvataggio."),
    'Si è verificato un errore di rete.' => __("Si è verificato un errore di rete."),
    'ISBN Mancante' => __("ISBN Mancante"),
    'Inserisci un codice ISBN per continuare.' => __("Inserisci un codice ISBN per continuare.")
], JSON_UNESCAPED_UNICODE) ?>);

// Global translation function for JavaScript
window.__ = function(key) {
    return i18nTranslations[key] || key;
};

// Convenience object for direct access
const bookFormI18n = {
    noSubgenre: __("Nessun sottogenere"),
    searching: __("Ricerca in corso..."),
    searchError: __("Errore nella ricerca")
};

const bookFormMessages = {
    uploadReady: <?= json_encode(__('File "%s" pronto per l\'upload')) ?>,
    authorAlreadySelected: <?= json_encode(__('Autore "%s" è già selezionato')) ?>,
    authorReady: <?= json_encode(__('Autore "%s" pronto per essere creato')) ?>,
    publisherSelected: <?= json_encode(__('Editore "%s" selezionato')) ?>,
    publisherReady: <?= json_encode(__('Editore "%s" pronto per essere creato')) ?>,
    publisherPlaceholder: <?= json_encode(__('Cerca editore esistente o inserisci nuovo...')) ?>,
    priceImported: <?= json_encode(__('Prezzo "%s" importato')) ?>
};

const isbnImportMessages = {
    invalidResponse: <?= json_encode(__('Risposta non valida dal servizio ISBN.')) ?>,
    genericError: <?= json_encode(__('Impossibile importare i dati per questo ISBN.')) ?>,
    notFound: <?= json_encode(__('ISBN non trovato nelle fonti disponibili.')) ?>
};

// Global variables
let authorsChoice = null;
let uppy = null;
let editoreChipList = null;
let editoreHiddenInput = null;

// Wait for DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {
    
    // Initialize all components
    initializeUppy();
    initializeChoicesJS();
    initializeSweetAlert();
    initializeAutocomplete();
    initializeGeneriDropdowns();
    initializeFormValidation();
    initializeIsbnImport();
    
    // Dewey classification
    initializeDewey();
    initializeSuggestCollocazione();
    initializeCollocationFilters();

    // Add loading state management
    window.addEventListener('beforeunload', function() {
        if (uppy && typeof uppy.close === 'function') {
            try {
                uppy.close();
            } catch (error) {
                console.error('Error closing Uppy:', error);
            }
        }
    });
});

// Initialize Uppy File Upload
function initializeUppy() {
    //     Uppy: typeof window.Uppy,
    //     UppyDragDrop: typeof window.UppyDragDrop,
    //     UppyProgressBar: typeof window.UppyProgressBar
    // });

    if (typeof Uppy === 'undefined') {
        console.error('Uppy is not loaded! Check vendor.bundle.js');
        return;
    }
    
    try {
        uppy = new Uppy({
            restrictions: {
                maxFileSize: 5000000, // 5MB
                maxNumberOfFiles: 1,
                allowedFileTypes: ['image/*']
            },
            autoProceed: false
        });

        uppy.use(UppyDragDrop, {
            target: '#uppy-upload',
            note: '<?= __("Trascina qui la copertina del libro o clicca per selezionare") ?>',
            locale: {
                strings: {
                    dropPasteFiles: '<?= __("Trascina qui la copertina del libro o %{browse}") ?>',
                    browse: '<?= __("seleziona file") ?>'
                }
            }
        });

        uppy.use(UppyProgressBar, {
            target: '#uppy-progress',
            hideAfterFinish: false
        });

        // Handle file added
        uppy.on('file-added', (file) => {
            displayImagePreview(file);
            
            // Set the file to the hidden input for form submission
            const fileInput = document.getElementById('fallback-file-input');
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(new File([file.data], file.name, {type: file.type}));
            fileInput.files = dataTransfer.files;
            
            Swal.fire({
                icon: 'success',
                title: __("Immagine Caricata!"),
                text: bookFormMessages.uploadReady.replace('%s', file.name),
                timer: 2000,
                showConfirmButton: false
            });
        });

        // Handle file removed
        uppy.on('file-removed', (file) => {
            clearImagePreview();
            document.getElementById('fallback-file-input').value = '';
        });

        uppy.on('restriction-failed', (file, error) => {
            console.error('Upload restriction failed:', error);
            Swal.fire({
                icon: 'error',
                title: __('Errore Upload'),
                text: error.message
            });
        });

    } catch (error) {
        console.error('Error initializing Uppy:', error);
        // Fallback to regular file input
        document.getElementById('fallback-file-input').style.display = 'block';
    }
}

// Display image preview
function displayImagePreview(file) {
    const container = document.getElementById('cover-preview-container');
    const reader = new FileReader();

    reader.onload = function(e) {
        container.innerHTML = `
            <div class="inline-flex flex-col items-start space-y-2">
                <div class="relative">
                    <img src="${e.target.result}" alt="Anteprima copertina" class="max-h-48 object-contain border border-gray-200 rounded-lg shadow-sm">
                </div>
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-2 text-sm text-gray-600">
                        <i class="fas fa-check-circle text-green-500"></i>
                        <span>${file.name} (${(file.size / 1024).toFixed(1)} KB)</span>
                    </div>
                    <button type="button" onclick="removeCoverImage()" class="text-xs text-red-600 hover:text-red-800 hover:underline flex items-center gap-1">
                        <i class="fas fa-trash"></i>
                        Rimuovi
                    </button>
                </div>
            </div>
        `;
    };

    reader.readAsDataURL(file.data);
}

// Clear image preview
function clearImagePreview() {
    document.getElementById('cover-preview-container').innerHTML = '';
}

// Remove cover image
function removeCoverImage() {
    if (!confirm(__('Sei sicuro di voler rimuovere la copertina?'))) {
        return;
    }

    // Set hidden field to signal removal
    document.getElementById('remove_cover').value = '1';

    // Clear the copertina_url hidden field
    document.getElementById('copertina_url').value = '';

    // Clear preview
    clearImagePreview();

    // Show confirmation message
    const container = document.getElementById('cover-preview-container');
    container.innerHTML = `
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 text-sm text-yellow-800 flex items-center gap-2" role="alert">
            <i class="fas fa-info-circle"></i>
            <span><?= __("La copertina verrà rimossa al salvataggio del libro") ?></span>
        </div>
    `;
}

// Initialize Choices.js for Authors
function initializeChoicesJS() {

    try {
        const element = document.getElementById('autori_select');
        if (!element) return;

        const preselected = Array.isArray(INITIAL_BOOK.autori) ? INITIAL_BOOK.autori : [];

        authorsChoice = new Choices(element, {
            searchEnabled: true,
            removeItemButton: true,
            addItems: true,
            duplicateItemsAllowed: false,
            placeholder: true,
            placeholderValue: '<?= __("Cerca autori esistenti o aggiungine di nuovi...") ?>',
            noChoicesText: '<?= __("Nessun autore trovato, premi Invio per aggiungerne uno nuovo") ?>',
            itemSelectText: '<?= __("Clicca per selezionare") ?>',
            addItemText: (value) => `<?= __('Aggiungi') ?> <b>"${value}"</b> <?= __('come nuovo autore') ?>`,
            maxItemText: (maxItemCount) => `Solo ${maxItemCount} autori possono essere aggiunti`,
            shouldSort: false,
            searchResultLimit: 50,
            searchFloor: 1,
            fuseOptions: {
                threshold: 0.3,
                distance: 100
            },
            classNames: {
                containerInner: 'choices__inner'
            }
        });

        loadAuthorsData(preselected);

        const wrapper = element.closest('.choices');
        const internalInput = wrapper ? wrapper.querySelector('.choices__input--cloned') : null;

        // Force input to take remaining space, overriding Choices.js inline styles
        if (internalInput) {
            const forceInputWidth = () => {
                internalInput.style.flex = '1 1 auto';
                internalInput.style.minWidth = '200px';
                internalInput.style.width = 'auto';
            };

            // Initial force
            forceInputWidth();

            // Watch for Choices.js changing the width
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
                        forceInputWidth();
                    }
                });
            });

            observer.observe(internalInput, {
                attributes: true,
                attributeFilter: ['style']
            });
        }

        const ensureAuthorChoice = (value, label, customProperties = {}) => {
            const stringValue = String(value);
            const selectElement = document.getElementById('autori_select');
            if (!selectElement) {
                console.error('autori_select element not found');
                return Promise.resolve();
            }
            const exists = Array.from(selectElement.options).some(opt => opt.value === stringValue);

            if (!exists) {
                const result = authorsChoice.setChoices([
                    {
                        value: stringValue,
                        label,
                        selected: false,
                        customProperties
                    }
                ], 'value', 'label', false);
                if (result && typeof result.then === 'function') {
                    return result.catch((err) => {
                        console.error('Unable to append author choice', err);
                    });
                }
            } else {
            }
            return Promise.resolve();
        };

        const createAuthorFromInputWithValue = async (rawValue) => {
            if (!authorsChoice) {
                console.warn('createAuthorFromInputWithValue: missing authorsChoice');
                return;
            }
            if (!rawValue || !rawValue.trim()) {
                return;
            }


            const normalizedLabel = rawValue.trim();
            const normalizedKey = normalizedLabel.toLowerCase();
            const alreadySelected = Array.from(document.querySelectorAll('#autori_hidden [data-label]'))
                .some((input) => (input.dataset.label || '').toLowerCase() === normalizedKey);
            if (alreadySelected) {
                if (internalInput) internalInput.value = '';
                authorsChoice.hideDropdown();
                if (window.Toast) {
                    window.Toast.fire({
                        icon: 'info',
                        title: bookFormMessages.authorAlreadySelected.replace('%s', normalizedLabel)
                    });
                }
                return;
            }

            const tempId = 'new_' + Date.now() + '_' + Math.floor(Math.random() * 1000);

            try {
                await ensureAuthorChoice(tempId, normalizedLabel, {isNew: true });
                authorsChoice.setChoiceByValue(tempId);

                if (internalInput) internalInput.value = '';
                authorsChoice.hideDropdown();
                if (typeof authorsChoice.clearInput === 'function') {
                    authorsChoice.clearInput();
                }
                if (window.Toast) {
                    window.Toast.fire({
                        icon: 'info',
                        title: bookFormMessages.authorReady.replace('%s', normalizedLabel)
                    });
                }
            } catch (err) {
                console.error('createAuthorFromInputWithValue: error creating author', err);
            }
        };

        // Legacy function for backward compatibility
        const createAuthorFromInput = () => {
            if (!internalInput) return;
            const rawValue = internalInput.value.trim();
            createAuthorFromInputWithValue(rawValue);
        };

        if (internalInput) {
            internalInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    // Capture input value BEFORE checking dropdown
                    const currentValue = internalInput.value.trim();

                    if (!currentValue) {
                        return;
                    }

                    const dropdown = wrapper ? wrapper.querySelector('.choices__list--dropdown') : null;
                    const dropdownVisible = dropdown && !dropdown.classList.contains('is-hidden');
                    const highlighted = dropdown ? dropdown.querySelector('.choices__item--selectable.is-highlighted') : null;


                    // If there's a highlighted item AND the dropdown is visible, let Choices.js handle it
                    // BUT only if the highlighted text matches what the user typed (case insensitive)
                    if (highlighted && dropdownVisible) {
                        const highlightedText = highlighted.textContent.trim().toLowerCase();
                        const currentText = currentValue.toLowerCase();

                        if (highlightedText === currentText || highlightedText.startsWith(currentText)) {
                            return;
                        } else {
                        }
                    }

                    event.preventDefault();
                    createAuthorFromInputWithValue(currentValue);
                }
            });
        }

        element.addEventListener('addItem', function(event) {
            const value = String(event.detail.value);
            const label = (event.detail.label ?? event.detail.value ?? '').trim();
            const customProps = event.detail.customProperties || {};
            addAuthorHiddenInput(value, label || value);
        });

        element.addEventListener('removeItem', function(event) {
            const value = String(event.detail.value);
            removeAuthorHiddenInput(value);
        });

        window.ensureAuthorChoice = ensureAuthorChoice;
    } catch (error) {
        console.error('Error initializing Choices.js:', error);
    }
}

// Initialize Dewey with chip-based selection
async function initializeDewey() {
  const container = document.getElementById('dewey_levels_container');
  const breadcrumb = document.getElementById('dewey_breadcrumb');
  const hidden = document.getElementById('classificazione_dewey');
  const manualInput = document.getElementById('dewey_manual_input');
  const addBtn = document.getElementById('dewey_add_btn');
  const chipContainer = document.getElementById('dewey_chip_container');
  const chipCode = document.getElementById('dewey_chip_code');
  const chipName = document.getElementById('dewey_chip_name');
  const chipRemove = document.getElementById('dewey_chip_remove');

  let currentDeweyCode = '';
  let currentDeweyName = '';

  // Valida formato codice Dewey (3 cifre principali + opzionale parte decimale)
  // Allineato con DeweyValidator::PATTERN_ANY_CODE lato server
  const validateDeweyCode = (code) => {
    return /^[0-9]{3}(\.[0-9]{1,4})?$/.test(code);
  };

  // Ottieni il codice parent (es. 599.1 → 599, 599.93 → 599.9)
  const getParentCode = (code) => {
    if (!code.includes('.')) return null; // Nessun parent se non ha decimali

    const parts = code.split('.');
    const intPart = parts[0]; // 599
    const decPart = parts[1]; // 1 oppure 93

    if (decPart.length === 1) {
      // 599.1 → parent è 599
      return intPart;
    } else {
      // 599.93 → parent è 599.9
      return `${intPart}.${decPart.substring(0, decPart.length - 1)}`;
    }
  };

  // Imposta il codice Dewey corrente
  const setDeweyCode = async (code, name = null) => {
    if (!code) {
      clearDeweyCode();
      return;
    }

    currentDeweyCode = code;
    currentDeweyName = name || '';

    // Se non abbiamo il nome, prova a cercarlo
    if (!currentDeweyName) {
      try {
        const response = await fetch(`/api/dewey/search?code=${encodeURIComponent(code)}`, {
          credentials: 'same-origin'
        });
        const result = response.ok ? await response.json() : null;

        if (result && result.name) {
          currentDeweyName = result.name;
        } else {
          // Non trovato, cerca il parent
          const parentCode = getParentCode(code);
          if (parentCode) {
            const parentResponse = await fetch(`/api/dewey/search?code=${encodeURIComponent(parentCode)}`, {
              credentials: 'same-origin'
            });
            const parentResult = parentResponse.ok ? await parentResponse.json() : null;

            if (parentResult && parentResult.name) {
              currentDeweyName = `${parentResult.name} > ${code}`;
            }
          }
        }
      } catch (e) {
        // Silently fail - code will be set without name
      }
    }

    // Aggiorna UI
    hidden.value = currentDeweyCode;
    chipCode.textContent = currentDeweyCode;
    chipName.textContent = currentDeweyName ? `— ${currentDeweyName}` : '';
    chipContainer.style.display = 'block';
    manualInput.value = '';
  };

  // Expose to global scope for scraping handler
  window.setDeweyCode = setDeweyCode;

  // Rimuovi il codice Dewey corrente
  const clearDeweyCode = () => {
    currentDeweyCode = '';
    currentDeweyName = '';
    hidden.value = '';
    chipContainer.style.display = 'none';
    chipCode.textContent = '';
    chipName.textContent = '';
    manualInput.value = '';

    // Reset navigazione
    container.innerHTML = '';
    breadcrumb.innerHTML = '<i class="fas fa-home"></i> <span><?= __("Nessuna selezione") ?></span>';
    loadLevel(null, 0);
  };

  // Gestione pulsante "Aggiungi"
  addBtn.addEventListener('click', async () => {
    const code = manualInput.value.trim();

    if (!code) {
      if (window.Toast) {
        window.Toast.fire({
          icon: 'warning',
          title: __('<?= __("Inserisci un codice Dewey") ?>')
        });
      }
      return;
    }

    if (!validateDeweyCode(code)) {
      if (window.Toast) {
        window.Toast.fire({
          icon: 'error',
          title: __('<?= __("Formato codice non valido") ?>'),
          text: __('<?= __("Usa formato: 599 oppure 599.9 oppure 599.93") ?>')
        });
      }
      return;
    }

    await setDeweyCode(code);
  });

  // Gestione rimozione chip
  chipRemove.addEventListener('click', () => {
    clearDeweyCode();
  });

  // Gestione Enter nell'input
  manualInput.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      addBtn.click();
    }
  });

  // Carica livelli Dewey per navigazione
  const loadLevel = async (parentCode = null, levelIndex = 0) => {
    try {
      const url = parentCode
        ? `/api/dewey/children?parent_code=${encodeURIComponent(parentCode)}`
        : '/api/dewey/children';

      const response = await fetch(url, { credentials: 'same-origin' });
      if (!response.ok) {
        console.error('Dewey children API error:', response.status);
        return null;
      }
      const items = await response.json();

      if (!Array.isArray(items) || items.length === 0) return null;

      // Rimuovi tutti i select dopo questo livello
      while (container.children.length > levelIndex) {
        container.removeChild(container.lastChild);
      }

      // Crea nuovo select
      const selectWrapper = document.createElement('div');
      const select = document.createElement('select');
      select.className = 'form-input';
      select.dataset.level = levelIndex;

      const opt0 = document.createElement('option');
      opt0.value = '';
      opt0.textContent = '<?= __("Seleziona...") ?>';
      select.appendChild(opt0);

      items.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item.code;
        opt.dataset.hasChildren = item.has_children;
        opt.dataset.name = item.name;
        opt.textContent = `${item.code} — ${item.name}`;
        select.appendChild(opt);
      });

      select.addEventListener('change', async (e) => {
        const selectedOption = e.target.selectedOptions[0];
        const code = e.target.value;

        if (!code) {
          // Rimuovi select successivi
          while (container.children.length > levelIndex + 1) {
            container.removeChild(container.lastChild);
          }
          breadcrumb.innerHTML = '<i class="fas fa-home"></i> <span><?= __("Nessuna selezione") ?></span>';
          return;
        }

        const name = selectedOption.dataset.name;
        const hasChildren = selectedOption.dataset.hasChildren === 'true';

        // Aggiorna breadcrumb in modo sicuro (XSS prevention)
        breadcrumb.innerHTML = '<i class="fas fa-home"></i> ';
        const breadcrumbSpan = document.createElement('span');
        breadcrumbSpan.className = 'text-gray-500';
        breadcrumbSpan.textContent = code;
        breadcrumb.appendChild(breadcrumbSpan);

        // Se ha figli, carica il livello successivo
        if (hasChildren) {
          await loadLevel(code, levelIndex + 1);
        } else {
          // Rimuovi select successivi (non ci sono più figli)
          while (container.children.length > levelIndex + 1) {
            container.removeChild(container.lastChild);
          }
          // Imposta questo codice come selezionato
          await setDeweyCode(code, name);
        }
      });

      selectWrapper.appendChild(select);
      container.appendChild(selectWrapper);

      return select;
    } catch (e) {
      console.error('Dewey level error:', e);
    }
  };

  // Calcola il percorso gerarchico per un codice Dewey
  // es. "133.5" → ["100", "130", "133", "133.5"]
  const getCodePath = (code) => {
    const path = [];

    // Prima parte: classe principale (X00)
    const mainClass = code.substring(0, 1) + '00';
    path.push(mainClass);

    // Se il codice è solo la classe principale, restituisci
    if (code === mainClass) return path;

    // Seconda parte: divisione (XX0) se diversa dalla classe
    const division = code.substring(0, 2) + '0';
    if (division !== mainClass) {
      path.push(division);
    }

    // Terza parte: sezione (XXX) se non è una divisione
    const intPart = code.split('.')[0];
    if (intPart.length === 3 && intPart !== division && intPart !== mainClass) {
      path.push(intPart);
    }

    // Parti decimali (XXX.X, XXX.XX, etc.)
    if (code.includes('.')) {
      const [base, decimal] = code.split('.');
      // Aggiungi la parte intera se non già presente
      if (!path.includes(base)) {
        path.push(base);
      }
      // Aggiungi ogni livello decimale
      for (let i = 1; i <= decimal.length; i++) {
        const partial = base + '.' + decimal.substring(0, i);
        path.push(partial);
      }
    }

    return path;
  };

  // Naviga ai dropdown fino al codice specificato
  const navigateToCode = async (targetCode) => {
    const path = getCodePath(targetCode);
    let lastFoundCode = null;
    let lastFoundName = null;

    // Per ogni codice nel percorso, carica il livello e seleziona
    for (let i = 0; i < path.length; i++) {
      const code = path[i];
      const parentCode = i === 0 ? null : path[i - 1];

      // Assicurati che il dropdown per questo livello esista
      if (container.children.length <= i) {
        await loadLevel(parentCode, i);
      }

      // Trova e seleziona l'opzione nel dropdown
      const select = container.children[i]?.querySelector('select');
      if (select) {
        // Cerca l'opzione con questo codice
        const option = Array.from(select.options).find(opt => opt.value === code);
        if (option) {
          select.value = code;
          lastFoundCode = code;
          lastFoundName = option.dataset.name;

          // Se ha figli e non è l'ultimo nel percorso, carica il prossimo livello
          const hasChildren = option.dataset.hasChildren === 'true';
          const isLast = i === path.length - 1;

          if (hasChildren && !isLast) {
            await loadLevel(code, i + 1);
          } else if (isLast) {
            // Ultimo elemento: aggiorna breadcrumb e chip (XSS prevention)
            breadcrumb.innerHTML = '<i class="fas fa-home"></i> ';
            const breadcrumbSpan = document.createElement('span');
            breadcrumbSpan.className = 'text-gray-500';
            breadcrumbSpan.textContent = code;
            breadcrumb.appendChild(breadcrumbSpan);
            await setDeweyCode(code, option.dataset.name);
            return; // Successfully navigated to target
          }
        } else {
          // Codice non trovato nel dropdown - è un codice personalizzato
          break;
        }
      }
    }

    // Se non abbiamo raggiunto il targetCode, mostra comunque il chip
    // Questo gestisce i codici personalizzati non presenti nel JSON (es. 708.2)
    if (targetCode !== lastFoundCode) {
      // Aggiorna breadcrumb in modo sicuro senza inserire HTML non sanificato (XSS prevention)
      breadcrumb.innerHTML = '<i class="fas fa-home"></i> ';
      const span = document.createElement('span');
      span.className = 'text-gray-500';
      const prefix = lastFoundCode ? `${lastFoundCode} > ` : '';
      span.textContent = prefix + targetCode;
      breadcrumb.appendChild(span);
      // setDeweyCode cercherà il nome tramite API (o mostrerà il nome del parent)
      await setDeweyCode(targetCode, null);
    }
  };

  // Carica primo livello (classi principali)
  await loadLevel(null, 0);

  // Carica valore iniziale se presente e naviga fino ad esso
  const initialCode = (INITIAL_BOOK.classificazione_dewey || '').trim();
  if (initialCode) {
    // Se è nel vecchio formato (300-340-347), prendi solo l'ultimo valore
    const parts = initialCode.split('-');
    const finalCode = parts.length > 1 ? parts[parts.length - 1] : initialCode;

    // Naviga ai dropdown fino al codice
    await navigateToCode(finalCode);
  }
}

// Load authors data for Choices.js
async function loadAuthorsData(preselected = []) {
    try {
        // Load all authors without query parameter
        const response = await fetch('/api/search/autori', {
            credentials: 'same-origin'
        });
        const authors = await response.json();

        if (!authorsChoice) return;

        const preselectedMap = new Map();
        preselected.forEach(author => {
            if (author && author.id) {
                preselectedMap.set(String(author.id), author.label || author.nome || '');
            }
        });

        const baseChoices = (authors || []).map(author => ({
            value: String(author.id),
            label: author.label,
            selected: preselectedMap.has(String(author.id)),
            customProperties: {isNew: false }
        }));

        const setChoicesResult = authorsChoice.setChoices(baseChoices, 'value', 'label', true);
        if (setChoicesResult && typeof setChoicesResult.then === 'function') {
            await setChoicesResult;
        }

        preselectedMap.forEach((label, id) => {
            authorsChoice.setChoiceByValue(id);
            addAuthorHiddenInput(id, label);
        });
    } catch (error) {
        console.error('Error loading authors:', error);
    }
}

// Add hidden input for author
function addAuthorHiddenInput(value, label) {
    const container = document.getElementById('autori_hidden');
    if (!container) {
        console.error('autori_hidden container not found');
        return;
    }

    const choiceValue = String(value ?? '');
    const normalizedLabel = (label ?? '').trim();
    const existing = Array.from(container.querySelectorAll('[data-choice-value]'))
        .some((input) => input.dataset.choiceValue === choiceValue);

    if (existing) {
        return;
    }

    const isExisting = /^\d+$/.test(choiceValue);

    if (isExisting) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'autori_ids[]';
        input.value = choiceValue;
        input.dataset.choiceValue = choiceValue;
        input.dataset.label = normalizedLabel || choiceValue;
        container.appendChild(input);
        return;
    }

    const newInput = document.createElement('input');
    newInput.type = 'hidden';
    newInput.name = 'autori_new[]';
    newInput.value = normalizedLabel || choiceValue;
    newInput.dataset.choiceValue = choiceValue;
    newInput.dataset.label = normalizedLabel || choiceValue;
    container.appendChild(newInput);
}

// Remove hidden input for author
function removeAuthorHiddenInput(value) {
    const container = document.getElementById('autori_hidden');
    if (!container) return;
    const choiceValue = String(value ?? '');
    Array.from(container.querySelectorAll('[data-choice-value]')).forEach((input) => {
        if (input.dataset.choiceValue === choiceValue) {
            input.remove();
        }
    });
}

// Initialize SweetAlert2 configurations
function initializeSweetAlert() {
    
    // Set default configurations
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer);
            toast.addEventListener('mouseleave', Swal.resumeTimer);
        }
    });
    
    // Make Toast available globally
    window.Toast = Toast;
}

// Initialize Enhanced Autocomplete for Publishers
function initializeAutocomplete() {

    const editoreInput = document.getElementById('editore_search');
    const editoreIdInput = document.getElementById('editore_id');
    const editoreHint = document.getElementById('editore_hint');
    editoreChipList = document.getElementById('editore_chip_list');
    editoreHiddenInput = document.getElementById('editore_search_value');

    if (!editoreInput || !editoreChipList) {
        console.error('Autocomplete elements not found for editore field');
        return;
    }

    if (editoreHiddenInput && !editoreHiddenInput.value && editoreInput.value) {
        editoreHiddenInput.value = editoreInput.value.trim();
    }

    const clearEditoreChip = (preserveValue = false) => {
        if (editoreChipList) {
            Array.from(editoreChipList.querySelectorAll('.editore-chip')).forEach((chip) => chip.remove());
        }
        if (editoreInput) {
            const currentValue = editoreInput.value;
            editoreInput.disabled = false;
            editoreInput.style.display = 'block';
            if (!preserveValue) {
                editoreInput.value = '';
            } else {
                editoreInput.value = currentValue;
            }
            editoreInput.placeholder = bookFormMessages.publisherPlaceholder;
            editoreInput.focus();
        }
    };

    const renderEditoreChip = (label, options = {}) => {
        const displayLabel = (label || '').trim();
        let isNew = false;
        let publisherId = null;

        if (typeof options === 'boolean') {
            isNew = options;
        } else if (options && typeof options === 'object') {
            isNew = Boolean(options.isNew);
            publisherId = options.publisherId != null ? String(options.publisherId) : null;
        }

        clearEditoreChip();

        if (!displayLabel) {
            if (editoreHint) editoreHint.textContent = '';
            if (editoreHiddenInput) editoreHiddenInput.value = editoreInput.value.trim();
            if (editoreIdInput) editoreIdInput.value = '0';
            return;
        }

        if (editoreHiddenInput) {
            editoreHiddenInput.value = displayLabel;
        }
        if (editoreIdInput) {
            if (isNew) {
                editoreIdInput.value = '0';
            } else if (publisherId !== null) {
                editoreIdInput.value = publisherId;
            }
        }

        const chip = document.createElement('div');
        chip.className = 'choices__item choices__item--selectable editore-chip inline-flex items-center gap-2 px-3 py-1.5 rounded-full border text-sm';
        chip.dataset.label = displayLabel;
        chip.dataset.isNew = isNew ? '1' : '0';

        if (isNew) {
            chip.classList.add('bg-primary-600', 'text-white', 'border-primary-500');
            if (editoreHint) {
                editoreHint.textContent = `<?= __("Nuovo editore:") ?> ${displayLabel}`;
            }
        } else {
            chip.classList.add('bg-slate-900', 'text-slate-100', 'border-slate-700');
            if (editoreHint) {
                const suffix = publisherId ? ` (ID: ${publisherId})` : '';
                editoreHint.textContent = `<?= __("Editore selezionato:") ?> ${displayLabel}${suffix}`;
            }
        }

        const labelContainer = document.createElement('div');
        labelContainer.className = 'flex items-center gap-2';

        const labelSpan = document.createElement('span');
        labelSpan.className = 'font-medium';
        labelSpan.textContent = displayLabel;
        labelContainer.appendChild(labelSpan);

        const badge = document.createElement('span');
        badge.className = `text-xs px-2 py-0.5 rounded-full ${isNew ? 'bg-primary-700 text-primary-100' : 'bg-slate-700 text-slate-200'}`;
        badge.textContent = isNew ? '<?= __("Da creare") ?>' : '<?= __("Esistente") ?>';
        labelContainer.appendChild(badge);

        chip.appendChild(labelContainer);

        const removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.className = 'ml-2 text-white hover:text-red-300 text-lg font-bold leading-none w-6 h-6 flex items-center justify-center rounded-full hover:bg-red-600 transition-colors';
        removeButton.setAttribute('aria-label', __("Rimuovi editore"));
        removeButton.innerHTML = '<i class="fas fa-times text-xs"></i>';
        removeButton.addEventListener('click', () => {
            chip.remove();
            if (editoreIdInput) editoreIdInput.value = '0';
            if (editoreHiddenInput) editoreHiddenInput.value = '';
            if (editoreHint) editoreHint.textContent = '';
            // Don't call clearEditoreChip as it would clear the input
            // Just reset the state
            if (editoreInput) {
                editoreInput.disabled = false;
                editoreInput.value = '';
                editoreInput.placeholder = bookFormMessages.publisherPlaceholder;
                editoreInput.focus();
            }
        });
        chip.appendChild(removeButton);

        editoreChipList.appendChild(chip);
        editoreInput.value = '';
        editoreInput.disabled = true;
        editoreInput.placeholder = '';
    };

    setupEnhancedAutocomplete('editore_search', 'editore_suggest', '/api/search/editori?q=',
        (item) => {
            renderEditoreChip(item.label, {isNew: false, publisherId: item.id });
            if (window.Toast) {
                window.Toast.fire({
                    icon: 'success',
                    title: bookFormMessages.publisherSelected.replace('%s', item.label)
                });
            }
        },
        (query) => {
            if (editoreHint) {
                editoreHint.textContent = `<?= __("Nessun editore trovato per") ?> "${query}" — <?= __("premi Invio per crearne uno nuovo.") ?>`;
            }
        },
        (rawValue) => {
            const value = (rawValue || '').trim();
            if (!value) return;
            renderEditoreChip(value, {isNew: true });
            if (window.Toast) {
                window.Toast.fire({
                    icon: 'info',
                    title: bookFormMessages.publisherReady.replace('%s', value)
                });
            }
        }
    );

    editoreInput.addEventListener('input', () => {
        if (editoreInput.disabled) {
            editoreInput.value = '';
            return;
        }

        const hasChip = editoreChipList && editoreChipList.querySelector('.editore-chip');
        if (hasChip) {
            editoreInput.value = '';
            return;
        }

        if (editoreIdInput) editoreIdInput.value = '0';
        if (editoreHiddenInput) editoreHiddenInput.value = editoreInput.value.trim();
        if (editoreHint) editoreHint.textContent = '';
        // Don't clear the chip since we're typing new input
    });

    if ((INITIAL_BOOK.editore_id || 0) > 0 && INITIAL_BOOK.editore_nome) {
        renderEditoreChip(INITIAL_BOOK.editore_nome, {isNew: false, publisherId: INITIAL_BOOK.editore_id });
    } else if (editoreHiddenInput && editoreHiddenInput.value) {
        renderEditoreChip(editoreHiddenInput.value, {isNew: true });
    }

    window.__renderEditorePreview = renderEditoreChip;
}

// Inizializza menu a tendina Genere/Sottogenere con filtro
function initializeGeneriDropdowns() {
  const radiceSelect = document.getElementById('radice_select');
  const genereSelect = document.getElementById('genere_select');
  const sottogenereSelect = document.getElementById('sottogenere_select');
  const pathEl = document.getElementById('genre_path_preview');
  if (!radiceSelect || !genereSelect || !sottogenereSelect) return;

  const initialRadice = parseInt(radiceSelect.dataset.initialRadice || INITIAL_BOOK.radice_id || 0, 10) || 0;
  const initialGenere = parseInt(genereSelect.dataset.initialGenere || INITIAL_BOOK.genere_id || 0, 10) || 0;
  const initialSottogenere = parseInt(sottogenereSelect.dataset.initialSottogenere || INITIAL_BOOK.sottogenere_id || 0, 10) || 0;
  let genereApplied = false;
  let sottogenereApplied = false;

  const resetGenere = (placeholder) => {
    genereSelect.innerHTML = `<option value="0">${placeholder}</option>`;
    genereSelect.disabled = true;
  };
  const resetSottogenere = (placeholder) => {
    sottogenereSelect.innerHTML = `<option value="0">${placeholder}</option>`;
    sottogenereSelect.disabled = true;
  };

  // 1) Carica radici (parent_id NULL)
  fetch('/api/generi?only_parents=1&limit=500', { credentials: 'same-origin' })
    .then(r => r.json())
    .then(items => {
      radiceSelect.innerHTML = `<option value="0">${__('Seleziona radice...')}</option>`;
      (items || []).forEach(it => {
        const opt = document.createElement('option');
        opt.value = it.id;
        opt.textContent = it.nome;
        radiceSelect.appendChild(opt);
      });
      if (initialRadice > 0) {
        radiceSelect.value = String(initialRadice);
        radiceSelect.dispatchEvent(new Event('change'));
      }
    });

  // 2) Cambio radice => carica generi (figli della radice)
  radiceSelect.addEventListener('change', async function() {
    const rootId = parseInt(this.value || '0', 10);
    resetGenere(__('Seleziona prima una radice...'));
    resetSottogenere(__('Seleziona prima un genere...'));
    if (rootId > 0) {
      try {
        const res = await fetch(`/api/generi/sottogeneri?parent_id=${encodeURIComponent(rootId)}`);
        const data = await res.json();
        genereSelect.innerHTML = `<option value="0">${__("Seleziona genere...")}</option>`;
        data.forEach(g => {
          const opt = document.createElement('option');
          opt.value = g.id;
          opt.textContent = g.nome;
          genereSelect.appendChild(opt);
        });
        genereSelect.disabled = false;
        updatePath();
        if (!genereApplied && initialGenere > 0) {
          genereSelect.value = String(initialGenere);
          genereApplied = true;
          genereSelect.dispatchEvent(new Event('change'));
        }
      } catch (e) {}
    }
    updatePath();
  });

  // 3) Cambio genere => carica sottogeneri
  genereSelect.addEventListener('change', async function() {
    const parentId = parseInt(this.value || '0', 10);
    resetSottogenere(__('Seleziona prima un genere...'));
    if (parentId > 0) {
      try {
        const res = await fetch(`/api/generi/sottogeneri?parent_id=${encodeURIComponent(parentId)}`);
        const data = await res.json();
        sottogenereSelect.innerHTML = `<option value="0">${bookFormI18n.noSubgenre}</option>`;
        data.forEach(sg => {
          const opt = document.createElement('option');
          opt.value = sg.id;
          opt.textContent = sg.nome;
          sottogenereSelect.appendChild(opt);
        });
        sottogenereSelect.disabled = false;
        if (!sottogenereApplied && initialSottogenere > 0) {
          sottogenereSelect.value = String(initialSottogenere);
          sottogenereApplied = true;
        }
      } catch (e) {}
    }
    updatePath();
  });

  function updatePath() {
    const rtext = radiceSelect.options[radiceSelect.selectedIndex]?.text || '';
    const gtext = genereSelect.options[genereSelect.selectedIndex]?.text || '';
    const stext = sottogenereSelect.options[sottogenereSelect.selectedIndex]?.text || '';
    const parts = [];
    if (radiceSelect.value !== '0') parts.push(rtext);
    if (genereSelect.value !== '0') parts.push(gtext);
    if (sottogenereSelect.value !== '0') parts.push(stext);
    pathEl.textContent = parts.length ? `Percorso: ${parts.join(' → ')}` : '';
  }
}

function initializeSuggestCollocazione() {
  const btn = document.getElementById('btnSuggestCollocazione');
  if (!btn) return;
  const info = document.getElementById('suggest_info');
  btn.addEventListener('click', async () => {
    const gid = parseInt(document.getElementById('genere_select')?.value || '0', 10) || 0;
    const sid = parseInt(document.getElementById('sottogenere_select')?.value || '0', 10) || 0;
    try {
      const res = await fetch(`/api/collocazione/suggerisci?genere_id=${gid}&sottogenere_id=${sid}`);
      const data = await res.json();
      if (data && data.scaffale_id) {
        const scaffaleSel = document.querySelector('select[name="scaffale_id"]');
        if (scaffaleSel) {
          scaffaleSel.value = String(data.scaffale_id);
          scaffaleSel.dispatchEvent(new Event('change'));
        }
        const mensolaSel = document.getElementById('mensola_select');
        if (mensolaSel && data.mensola_id) {
          setTimeout(() => {
            mensolaSel.value = String(data.mensola_id);
            mensolaSel.dispatchEvent(new Event('change'));
          }, 100);
        }
        info.textContent = data.collocazione ? `Suggerito: ${data.collocazione}` : `Suggerito scaffale #${data.scaffale_id}`;
        if (window.Toast) window.Toast.fire({icon: 'success', title: __('Collocazione suggerita') });
      } else {
        info.textContent = '<?= __("Nessun suggerimento disponibile") ?>';
        if (window.Toast) window.Toast.fire({icon: 'info', title: __('Nessun suggerimento') });
      }
    } catch (e) {
      info.textContent = '<?= __("Errore suggerimento") ?>';
    }
  });
}

// Link scaffale -> mensola -> posizione
function initializeCollocationFilters() {
  const scaffaleSel = document.querySelector('select[name="scaffale_id"]');
  const mensolaSel = document.getElementById('mensola_select');
  const posizioneInput = document.getElementById('posizione_progressiva_input');
  const collocazionePreview = document.getElementById('collocazione_preview');
  const autoBtn = document.getElementById('btnAutoPosition');
  if (!scaffaleSel || !mensolaSel || !posizioneInput) return;

  const normalizeNumber = (value) => {
    const num = Number.parseInt(String(value ?? '0'), 10);
    return Number.isNaN(num) ? 0 : num;
  };

  const MENSOLE = (<?php echo json_encode($mensole ?? [], JSON_UNESCAPED_UNICODE); ?> || []).map(m => ({
    id: normalizeNumber(m.id),
    scaffale_id: normalizeNumber(m.scaffale_id),
    numero_livello: normalizeNumber(m.numero_livello)
  }));
  
  function fillOptions(select, items, placeholder, getText) {
    select.innerHTML = '';
    const opt0 = document.createElement('option');
    opt0.value = '0';
    opt0.textContent = placeholder;
    select.appendChild(opt0);
    items.forEach(it => {
      const o = document.createElement('option');
      o.value = String(it.id);
      o.textContent = getText ? getText(it) : String(it.id);
      select.appendChild(o);
    });
  }

  async function updateAutoPosition(force = false) {
    const sid = normalizeNumber(scaffaleSel.value);
    const mid = normalizeNumber(mensolaSel.value);
    if (sid > 0 && mid > 0) {
      const params = new URLSearchParams({
        scaffale_id: String(sid),
        mensola_id: String(mid)
      });
      const bookId = normalizeNumber(INITIAL_BOOK.id || 0);
      if (bookId) params.append('book_id', String(bookId));
      try {
        const res = await fetch(`/api/collocazione/next?${params.toString()}`);
        if (!res.ok) return;
        const data = await res.json();
        if (!posizioneInput.dataset.manual || force) {
          posizioneInput.value = data.next_position ?? '';
        }
        if (data.collocazione) {
          collocazionePreview.value = data.collocazione;
        }
      } catch (error) {
        console.error('<?= __("Impossibile aggiornare la posizione automatica") ?>', error);
      }
    } else {
      if (!posizioneInput.dataset.manual || force) {
        posizioneInput.value = '';
      }
      collocazionePreview.value = '';
    }
  }

  posizioneInput.addEventListener('input', () => {
    if (posizioneInput.value === '' || Number.parseInt(posizioneInput.value, 10) <= 0) {
      delete posizioneInput.dataset.manual;
    } else {
      posizioneInput.dataset.manual = '1';
    }
  });

  if (autoBtn) {
    autoBtn.addEventListener('click', async () => {
      const sid = normalizeNumber(scaffaleSel.value);
      const mid = normalizeNumber(mensolaSel.value);

      if (sid <= 0 || mid <= 0) {
        if (window.Toast) {
          window.Toast.fire({
            icon: 'warning',
            title: __('Seleziona scaffale e mensola prima')
          });
        }
        return;
      }

      // Show loading state
      autoBtn.disabled = true;
      autoBtn.innerHTML = `<i class="fas fa-spinner fa-spin mr-2"></i>${__("Generazione...")}`;

      delete posizioneInput.dataset.manual;
      await updateAutoPosition(true);

      // Restore button state
      autoBtn.disabled = false;
      autoBtn.innerHTML = `<i class="fas fa-sync mr-2"></i>${__("Genera automaticamente")}`;

      if (window.Toast && posizioneInput.value) {
        window.Toast.fire({
          icon: 'success',
          title: `<?= __("Posizione generata:") ?> ${posizioneInput.value}`
        });
      }
    });
  }

  scaffaleSel.addEventListener('change', () => {
    const sid = normalizeNumber(scaffaleSel.value);
    if (sid > 0) {
      const ms = MENSOLE.filter(m => m.scaffale_id === sid);
      fillOptions(mensolaSel, ms, '<?= __("Seleziona mensola...") ?>', m => `<?= __("Livello") ?> ${m.numero_livello}`);
      mensolaSel.disabled = false;
      mensolaSel.removeAttribute('disabled');
    } else {
      fillOptions(mensolaSel, [], '<?= __("Seleziona prima uno scaffale...") ?>', null);
      mensolaSel.disabled = true;
      mensolaSel.setAttribute('disabled', 'disabled');
    }
    delete posizioneInput.dataset.manual;
    updateAutoPosition(true);
  });

  mensolaSel.addEventListener('change', () => {
    delete posizioneInput.dataset.manual;
    updateAutoPosition(true);
  });

  if (FORM_MODE === 'edit') {
    const initialScaffale = normalizeNumber(INITIAL_BOOK.scaffale_id || 0);
    const initialMensola = normalizeNumber(INITIAL_BOOK.mensola_id || 0);
    const initialPosizione = normalizeNumber(INITIAL_BOOK.posizione_progressiva || 0);

    if (initialScaffale) {
      scaffaleSel.value = String(initialScaffale);
      scaffaleSel.dispatchEvent(new Event('change'));

      if (initialMensola) {
        setTimeout(() => {
          mensolaSel.value = String(initialMensola);
          mensolaSel.dispatchEvent(new Event('change'));
          if (initialPosizione) {
            posizioneInput.value = String(initialPosizione);
            posizioneInput.dataset.manual = '1';
            updateAutoPosition(false);
          }
        }, 0);
      }
    } else if (initialPosizione) {
      posizioneInput.value = String(initialPosizione);
      posizioneInput.dataset.manual = '1';
      updateAutoPosition(false);
    }
  } else {
    updateAutoPosition(false);
  }
}

// Enhanced autocomplete helper function
function setupEnhancedAutocomplete(inputId, suggestId, fetchUrl, onSelect, onEmpty, onCreate) {
    const input = document.getElementById(inputId);
    const suggestions = document.getElementById(suggestId);
    let timeout;
    let lastResults = [];
    let highlightedIndex = -1;
    
    if (!input || !suggestions) {
        console.error(`Autocomplete elements not found: ${inputId}, ${suggestId}`);
        return;
    }

    const safeOnSelect = typeof onSelect === 'function' ? onSelect : () => {};
    const safeOnEmpty = typeof onEmpty === 'function' ? onEmpty : () => {};
    const safeOnCreate = typeof onCreate === 'function' ? onCreate : null;

    const clearSuggestions = () => {
        clearTimeout(timeout);
        suggestions.classList.add('hidden');
        suggestions.innerHTML = '';
        lastResults = [];
        highlightedIndex = -1;
    };

    const refreshHighlight = () => {
        const items = suggestions.querySelectorAll('li[data-index]');
        items.forEach((item, idx) => {
            if (idx === highlightedIndex) {
                item.classList.add('bg-gray-100', 'text-gray-900');
            } else {
                item.classList.remove('bg-gray-100', 'text-gray-900');
            }
        });
    };

    const selectResultAtIndex = (index) => {
        if (index < 0 || index >= lastResults.length) return;
        const item = lastResults[index];
        if (item && item.isCreate) {
            if (safeOnCreate) {
                safeOnCreate(item.label);
            }
        } else {
            const payload = item?.raw ?? item;
            const label = payload?.label ?? item?.label ?? '';
            safeOnSelect(payload);
            input.value = label;
        }
        clearSuggestions();
    };

    const createFromCurrentInput = () => {
        const query = input.value.trim();
        if (!query) return;
        if (safeOnCreate) {
            safeOnCreate(query);
        } else if (lastResults.length === 1 && lastResults[0] && !lastResults[0].isCreate) {
            safeOnSelect(lastResults[0]);
            input.value = lastResults[0].label || '';
        }
        clearSuggestions();
    };

    input.addEventListener('input', async function() {
        clearTimeout(timeout);
        const query = input.value.trim();
        
        if (!query) {
            clearSuggestions();
            return;
        }
        
        // Show loading state
        suggestions.innerHTML = `<li class="px-4 py-2 text-gray-500"><i class="fas fa-spinner fa-spin mr-2"></i>${bookFormI18n.searching}</li>`;
        suggestions.classList.remove('hidden');
        
        timeout = setTimeout(async () => {
            try {
                const response = await fetch(fetchUrl + encodeURIComponent(query));
                const data = await response.json();

                suggestions.innerHTML = '';

                const normalized = Array.isArray(data) ? data : [];
                if (normalized.length === 0) {
                    safeOnEmpty(query);
                }

                const hasExactMatch = normalized.some(it => (it.label || '').toLowerCase() === query.toLowerCase());
                const combined = [];
                if (safeOnCreate && query && !hasExactMatch) {
                    combined.push({
                        id: null,
                        label: query,
                        isCreate: true
                    });
                }
                normalized.forEach(item => {
                    const itemLabel = typeof item.label === 'string'
                        ? item.label
                        : (typeof item.nome === 'string' ? item.nome : '');
                    combined.push({
                        id: item.id,
                        label: itemLabel,
                        raw: item,
                        isCreate: false
                    });
                });

                lastResults = combined;

                if (combined.length === 0) {
                    const emptyLi = document.createElement('li');
                    emptyLi.className = 'px-4 py-2 text-gray-500';
                    emptyLi.textContent = '<?= __("Nessun risultato trovato") ?>';
                    suggestions.appendChild(emptyLi);
                } else {
                    combined.forEach((item, index) => {
                        const li = document.createElement('li');
                        li.dataset.index = String(index);
                        li.dataset.label = item.label || '';
                        if (!item.isCreate && item.id != null) {
                            li.dataset.id = String(item.id);
                        }

                        const baseClasses = 'px-4 py-2 flex items-center gap-2 cursor-pointer border-b border-gray-100 last:border-b-0 transition-colors';
                        li.className = item.isCreate
                            ? `${baseClasses} text-gray-900 font-semibold hover:bg-gray-100`
                            : `${baseClasses} text-gray-900 hover:bg-gray-50`;

                        const icon = document.createElement('i');
                        icon.className = item.isCreate ? 'fas fa-plus-circle text-gray-600' : 'fas fa-building text-gray-400';

                        const text = document.createElement('span');
                        text.textContent = item.isCreate
                            ? `<?= __("Crea nuovo") ?> "${item.label}"`
                            : item.label || '';

                        li.appendChild(icon);
                        li.appendChild(text);

                        li.addEventListener('click', () => {
                            selectResultAtIndex(index);
                        });
                        li.addEventListener('mouseenter', () => {
                            highlightedIndex = index;
                            refreshHighlight();
                        });
                        suggestions.appendChild(li);
                    });
                }

                highlightedIndex = combined.length > 0 ? 0 : -1;
                refreshHighlight();
                suggestions.classList.remove('hidden');
            } catch (error) {
                console.error('Autocomplete fetch error:', error);
                const fallback = input.value.trim();
                if (safeOnCreate && fallback) {
                    lastResults = [{id: null, label: fallback, isCreate: true }];
                    suggestions.innerHTML = '';
                    const li = document.createElement('li');
                    li.className = 'px-4 py-2 flex items-center gap-2 cursor-pointer border-b border-gray-100 last:border-b-0 transition-colors text-gray-900 font-semibold hover:bg-gray-100';
                    li.dataset.index = '0';
                    li.dataset.label = fallback;

                    const icon = document.createElement('i');
                    icon.className = 'fas fa-plus-circle text-gray-600';
                    li.appendChild(icon);

                    const text = document.createElement('span');
                    text.textContent = `<?= __("Crea nuovo") ?> "${fallback}"`;
                    li.appendChild(text);

                    li.addEventListener('click', () => {
                        selectResultAtIndex(0);
                    });
                    li.addEventListener('mouseenter', () => {
                        highlightedIndex = 0;
                        refreshHighlight();
                    });

                    suggestions.appendChild(li);
                    highlightedIndex = 0;
                    refreshHighlight();
                    suggestions.classList.remove('hidden');
                    safeOnEmpty(fallback);
                } else {
                    suggestions.innerHTML = `<li class="px-4 py-2 text-red-500">${bookFormI18n.searchError}</li>`;
                    lastResults = [];
                    highlightedIndex = -1;
                    suggestions.classList.remove('hidden');
                }
            }
        }, 300);
    });
    
    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            if (lastResults.length > 0) {
                event.preventDefault();
                if (highlightedIndex < 0 || highlightedIndex >= lastResults.length - 1) {
                    highlightedIndex = 0;
                } else {
                    highlightedIndex += 1;
                }
                refreshHighlight();
            }
        } else if (event.key === 'ArrowUp') {
            if (lastResults.length > 0) {
                event.preventDefault();
                if (highlightedIndex < 0 || highlightedIndex === 0) {
                    highlightedIndex = lastResults.length - 1;
                } else {
                    highlightedIndex -= 1;
                }
                refreshHighlight();
            }
        } else if (event.key === 'Enter') {
            if (highlightedIndex >= 0 && lastResults[highlightedIndex]) {
                event.preventDefault();
                selectResultAtIndex(highlightedIndex);
            } else {
                event.preventDefault();
                createFromCurrentInput();
            }
        } else if (event.key === 'Escape') {
            clearSuggestions();
        }
    });

    // Hide suggestions when clicking outside (with form field protection)
    document.addEventListener('click', function(e) {
        // Don't interfere with form inputs, selects, buttons, or labels
        if (e.target.matches('input, select, button, label, textarea')) {
            return;
        }

        // Don't interfere if clicking inside form elements
        if (e.target.closest('form')) {
            return;
        }

        // Only hide suggestions if clicking truly outside
        if (!input.contains(e.target) && !suggestions.contains(e.target)) {
            clearSuggestions();
        }
    });
}

// Handle duplicate book detection
async function handleDuplicateBook(existingBook) {
    const result = await Swal.fire({
        icon: 'warning',
        title: __('Libro Già Esistente'),
        html: `
            <p class="mb-4">${__('Esiste già un libro con lo stesso identificatore (ISBN/EAN).')}</p>
            <div class="bg-gray-100 p-4 rounded-lg mb-4 text-left">
                <p class="font-semibold mb-2"><i class="fas fa-book mr-2"></i>${__('Libro Esistente:')}</p>
                <p class="text-gray-700 mb-1"><strong>${__('ID:')}</strong> #${existingBook.id}</p>
                <p class="text-gray-700 mb-1"><strong>${__('Titolo:')}</strong> ${existingBook.title}</p>
                ${existingBook.isbn13 ? `<p class="text-gray-700 mb-1"><strong>${__('ISBN-13:')}</strong> ${existingBook.isbn13}</p>` : ''}
                ${existingBook.ean ? `<p class="text-gray-700 mb-1"><strong>${__('EAN:')}</strong> ${existingBook.ean}</p>` : ''}
                ${existingBook.location ? `<p class="text-gray-700 mb-1"><strong>${__('Collocazione:')}</strong> <span class="inline-flex items-center px-2 py-1 bg-blue-100 text-blue-800 rounded-md text-sm"><i class="fas fa-map-marker-alt mr-1"></i>${existingBook.location}</span></p>` : `<p class="text-gray-700 mb-1"><strong>${__('Collocazione:')}</strong> <span class="text-gray-400">${__('Non specificata')}</span></p>`}
            </div>
            <p class="text-sm text-gray-600">${__('Vuoi aumentare il numero di copie di questo libro?')}</p>
        `,
        showCancelButton: true,
        showDenyButton: true,
        confirmButtonText: '<i class="fas fa-plus mr-2"></i>' + __('Aumenta Copie'),
        denyButtonText: '<i class="fas fa-eye mr-2"></i>' + __('Visualizza Libro'),
        cancelButtonText: __('Annulla'),
        confirmButtonColor: '#10b981',
        denyButtonColor: '#3b82f6',
        reverseButtons: true
    });

    if (result.isConfirmed) {
        // Show dialog to increase copies
        await increaseCopies(existingBook);
    } else if (result.isDenied) {
        // Redirect to book detail page
        window.location.href = `/admin/libri/${existingBook.id}`;
    }
}

// Increase copies of existing book
async function increaseCopies(book) {
    const { value: copiesToAdd } = await Swal.fire({
        title: __('Aumenta Copie'),
        html: `
            <p class="mb-4">${__('Quante copie vuoi aggiungere a "%s"?').replace('%s', book.title)}</p>
            <input type="number" id="copiesToAdd" class="swal2-input" value="1" min="1" max="100" style="width: 150px;">
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: __('Aggiungi'),
        cancelButtonText: __('Annulla'),
        preConfirm: () => {
            const value = parseInt(document.getElementById('copiesToAdd').value);
            if (!value || value < 1) {
                Swal.showValidationMessage(__('Inserisci un numero valido di copie'));
                return false;
            }
            return value;
        }
    });

    if (copiesToAdd) {
        // Show loading
        Swal.fire({
            title: __('Aggiornamento in corso...'),
            text: __('Attendere prego'),
            allowOutsideClick: false,
            showConfirmButton: false,
            willOpen: () => {
                Swal.showLoading();
            }
        });

        try {
            const response = await fetch(`/api/libri/${book.id}/increase-copies`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({ copies: copiesToAdd })
            });

            const data = await response.json();

            // Check for CSRF/session errors
            if (data.error || data.code) {
                await Swal.fire({
                    icon: 'error',
                    title: __('Errore di sicurezza'),
                    text: data.error || __('Errore di sicurezza'),
                    confirmButtonText: __('OK')
                });
                if (data.code === 'SESSION_EXPIRED' || data.code === 'CSRF_INVALID') {
                    setTimeout(() => window.location.reload(), 2000);
                }
                return;
            }

            if (response.ok && data.success) {
                await Swal.fire({
                    icon: 'success',
                    title: __('Copie Aggiunte!'),
                    html: `
                        <p class="mb-2">${__('Hai aggiunto %s copie a "%s"').replace('%s', copiesToAdd).replace('%s', book.title)}</p>
                        <p class="text-sm text-gray-600">${__('Copie totali:')}: ${data.copie_totali}</p>
                        <p class="text-sm text-gray-600">${__('Copie disponibili:')}: ${data.copie_disponibili}</p>
                    `,
                    confirmButtonText: __('OK')
                });
                // Redirect to book list
                window.location.href = '/admin/libri';
            } else {
                const error = data;
                Swal.fire({
                    icon: 'error',
                    title: __('Errore'),
                    text: error.message || __('Impossibile aggiornare le copie.')
                });
            }
        } catch (error) {
            console.error('Error increasing copies:', error);
            Swal.fire({
                icon: 'error',
                title: __('Errore'),
                text: __('Si è verificato un errore di rete.')
            });
        }
    }
}

// Initialize Form Validation
function initializeFormValidation() {
    
    const form = document.getElementById('bookForm');
    if (!form) return;
    
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        // Validate required fields
        const title = form.querySelector('input[name="titolo"]').value.trim();
        if (!title) {
            Swal.fire({
                icon: 'error',
                title: __('Campo Obbligatorio'),
                text: __('Il titolo del libro è obbligatorio.')
            });
            return;
        }
        
        // Validate ISBN format
        const isbn10 = form.querySelector('input[name="isbn10"]').value.replace(/[-\s]/g, '').toUpperCase();
        const isbn13 = form.querySelector('input[name="isbn13"]').value.replace(/[-\s]/g, '');

        if (isbn10 && !/^\d{9}[\dX]$/.test(isbn10)) {
            Swal.fire({
                icon: 'error',
                title: __('ISBN10 Non Valido'),
                text: __('ISBN10 deve contenere esattamente 10 caratteri (9 cifre + 1 cifra o X).')
            });
            return;
        }
        
        if (isbn13 && !/^\d{13}$/.test(isbn13)) {
            Swal.fire({
                icon: 'error',
                title: __('ISBN13 Non Valido'), 
                text: __('ISBN13 deve contenere esattamente 13 cifre.')
            });
            return;
        }
        
        // Frontend hierarchy validation for Radice/Genere/Sottogenere
        const radSel = document.getElementById('radice_select');
        const genSel = document.getElementById('genere_select');
        const subSel = document.getElementById('sottogenere_select');
        const rid = radSel ? parseInt(radSel.value || '0', 10) : 0;
        const gid = genSel ? parseInt(genSel.value || '0', 10) : 0;
        const sid = subSel ? parseInt(subSel.value || '0', 10) : 0;
        if (sid > 0 && gid === 0) {
            Swal.fire({icon: 'error', title: __('Selezione non valida'), text: __('Seleziona un Genere prima del Sottogenere.') });
            return;
        }
        if (gid > 0 && rid === 0) {
            Swal.fire({icon: 'error', title: __('Selezione non valida'), text: __('Seleziona una Radice prima del Genere.') });
            return;
        }

        // Show confirmation dialog
        const confirmTitle = FORM_MODE === 'edit' ? __('Conferma Aggiornamento') : __('Conferma Salvataggio');
        const confirmText = FORM_MODE === 'edit'
            ? __('Vuoi aggiornare il libro "%s"?').replace('%s', title)
            : __('Sei sicuro di voler salvare il libro "%s"?').replace('%s', title);
        const confirmButton = FORM_MODE === 'edit' ? __('Sì, Aggiorna') : __('Sì, Salva');

        const result = await Swal.fire({
            title: confirmTitle,
            text: confirmText,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: confirmButton,
            cancelButtonText: __('Annulla'),
            reverseButtons: true
        });
        
        if (result.isConfirmed) {
            // Show loading
            Swal.fire({
                title: FORM_MODE === 'edit' ? __('Aggiornamento in corso...') : __('Salvataggio in corso...'),
                text: __('Attendere prego'),
                allowOutsideClick: false,
                showConfirmButton: false,
                willOpen: () => {
                    Swal.showLoading();
                }
            });

            // Submit via fetch to handle duplicate detection
            try {
                const formData = new FormData(form);
                const response = await fetch(form.action, {
                    method: form.method || 'POST',
                    credentials: 'same-origin',
                    body: formData
                });

                if (response.status === 409) {
                    // Duplicate book found
                    const data = await response.json();
                    await handleDuplicateBook(data.existing_book);
                } else if (response.ok || response.redirected) {
                    // Success - follow redirect or reload
                    if (response.redirected) {
                        window.location.href = response.url;
                    } else {
                        window.location.href = '/admin/libri';
                    }
                } else {
                    // Other error
                    Swal.fire({
                        icon: 'error',
                        title: __('Errore'),
                        text: __('Si è verificato un errore durante il salvataggio.')
                    });
                }
            } catch (error) {
                console.error('Form submission error:', error);
                Swal.fire({
                    icon: 'error',
                    title: __('Errore'),
                    text: __('Si è verificato un errore di rete.')
                });
            }
        }
    });
    
    // Handle cancel button
    const cancelBtn = document.getElementById('btnCancel');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', async function(e) {
            e.preventDefault();
            const cancelUrl = (FORM_MODE === 'edit' && INITIAL_BOOK.id)
                ? `/admin/libri/${INITIAL_BOOK.id}`
                : '/admin/libri';
            const result = await Swal.fire({
                title: __('Conferma Annullamento'),
                text: __('Sei sicuro di voler annullare? Tutti i dati inseriti andranno persi.'),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: __('Sì, Annulla'),
                cancelButtonText: __('Continua'),
                reverseButtons: true
            });
            
            if (result.isConfirmed) {
                window.location.href = cancelUrl;
            }
        });
    }
}

// Sistema Dewey unificato - la funzione initializeDewey() gestisce tutto

// Display scrape source information after successful import
function displayScrapeSourceInfo(data) {
    const sourceInfoPanel = document.getElementById('scrapeSourceInfo');
    const sourceNameEl = document.getElementById('scrapeSourceName');
    const sourcesListEl = document.getElementById('scrapeSourcesList');
    const sourcesListItemsEl = document.getElementById('scrapeSourcesListItems');
    const btnShowAlternatives = document.getElementById('btnShowAlternatives');
    const alternativesPanel = document.getElementById('scrapeAlternativesPanel');
    const btnCloseAlternatives = document.getElementById('btnCloseAlternatives');

    if (!sourceInfoPanel) return;

    // Get source information from response
    const primarySource = data._primary_source || data.source || <?= json_encode(__("Sconosciuto")) ?>;
    const sources = data._sources || (data.source ? [data.source] : []);
    const alternatives = data._alternatives || null;

    // Format source name for display
    const formatSourceName = (source) => {
        const sourceNames = {
            'google-books': 'Google Books',
            'googlebooks': 'Google Books',
            'google': 'Google Books',
            'open-library': 'Open Library',
            'openlibrary': 'Open Library',
            'scraping-pro': 'Scraping Pro',
            'scrapingpro': 'Scraping Pro',
            'api-book-scraper': 'API Book Scraper',
            'custom-api': 'Custom API',
            'z39': 'Z39.50/SRU',
            'sru': 'Z39.50/SRU',
            'sbn': 'SBN Italia',
            'amazon': 'Amazon',
            'goodreads': 'Goodreads',
            'libreria-universitaria': 'Libreria Universitaria'
        };
        const normalized = (source || '').toLowerCase().replace(/[_\s]/g, '-');
        return sourceNames[normalized] || source;
    };

    // Update source name
    sourceNameEl.textContent = formatSourceName(primarySource);

    // Show sources list if multiple sources were consulted
    if (sources.length > 1) {
        sourcesListItemsEl.textContent = sources.map(formatSourceName).join(', ');
        sourcesListEl.classList.remove('hidden');
    } else {
        sourcesListEl.classList.add('hidden');
    }

    // Show alternatives button if alternatives are available
    if (alternatives && Object.keys(alternatives).length > 0) {
        btnShowAlternatives.classList.remove('hidden');

        // Store alternatives for later use
        window._scrapeAlternatives = alternatives;

        // Setup alternatives button click handler (only once)
        if (!btnShowAlternatives.dataset.initialized) {
            btnShowAlternatives.dataset.initialized = 'true';
            btnShowAlternatives.addEventListener('click', () => {
                showAlternativesPanel(window._scrapeAlternatives);
                btnShowAlternatives.setAttribute('aria-expanded', 'true');
            });
        }
    } else {
        btnShowAlternatives.classList.add('hidden');
        // Hide panel and reset state when no alternatives (e.g., new import without alternatives)
        if (alternativesPanel) {
            alternativesPanel.classList.add('hidden');
        }
        window._scrapeAlternatives = null;
        btnShowAlternatives.setAttribute('aria-expanded', 'false');
    }

    // Setup close alternatives button (only once)
    if (btnCloseAlternatives && !btnCloseAlternatives.dataset.initialized) {
        btnCloseAlternatives.dataset.initialized = 'true';
        btnCloseAlternatives.addEventListener('click', () => {
            alternativesPanel.classList.add('hidden');
            btnShowAlternatives.setAttribute('aria-expanded', 'false');
        });
    }

    // Show the source info panel
    sourceInfoPanel.classList.remove('hidden');
}

// Show alternatives panel with data from different sources
function showAlternativesPanel(alternatives) {
    const panel = document.getElementById('scrapeAlternativesPanel');
    const content = document.getElementById('alternativesContent');

    if (!panel || !content || !alternatives) return;

    // Build alternatives content
    let html = '';

    for (const [source, sourceData] of Object.entries(alternatives)) {
        const formatSourceName = (s) => {
            const names = {
                'google-books': 'Google Books',
                'open-library': 'Open Library',
                'scraping-pro': 'Scraping Pro',
                'api-book-scraper': 'API Book Scraper'
            };
            return names[s] || s;
        };

        html += `<div class="p-3 bg-white rounded border border-blue-100">
            <div class="font-medium text-blue-800 mb-2">${formatSourceName(source)}</div>
            <div class="space-y-1 text-xs text-gray-600">`;

        // Show key fields from this source (using data-* attributes for event delegation)
        if (sourceData.title && typeof sourceData.title === 'string') {
            html += `<div><span class="font-medium"><?= __("Titolo:") ?></span> ${escapeHtml(sourceData.title)}
                <button type="button" class="ml-2 text-blue-600 hover:underline apply-alt-value" data-field="titolo" data-value="${escapeAttr(sourceData.title)}"><?= __("Usa") ?></button></div>`;
        }
        if (sourceData.publisher && typeof sourceData.publisher === 'string') {
            html += `<div><span class="font-medium"><?= __("Editore:") ?></span> ${escapeHtml(sourceData.publisher)}
                <button type="button" class="ml-2 text-blue-600 hover:underline apply-alt-publisher" data-publisher="${escapeAttr(sourceData.publisher)}"><?= __("Usa") ?></button></div>`;
        }
        // Show cover only if it's not an SBN/LibraryThing cover (requires API key)
        // Also sanitize URL to prevent javascript: and other unsafe protocols
        const safeImage = sanitizeUrl(sourceData.image);
        if (safeImage && !safeImage.includes('librarything.com/devkey')) {
            html += `<div><span class="font-medium"><?= __("Copertina:") ?></span>
                <a href="${escapeAttr(safeImage)}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline"><?= __("Vedi") ?></a>
                <button type="button" class="ml-2 text-blue-600 hover:underline apply-alt-cover" data-cover="${escapeAttr(safeImage)}"><?= __("Usa") ?></button></div>`;
        }
        if (sourceData.description && typeof sourceData.description === 'string') {
            const shortDesc = sourceData.description.substring(0, 100) + (sourceData.description.length > 100 ? '...' : '');
            html += `<div><span class="font-medium"><?= __("Descrizione:") ?></span> ${escapeHtml(shortDesc)}
                <button type="button" class="ml-2 text-blue-600 hover:underline apply-alt-value" data-field="descrizione" data-value="${escapeAttr(sourceData.description)}"><?= __("Usa") ?></button></div>`;
        }

        html += `</div></div>`;
    }

    if (html === '') {
        html = `<p class="text-gray-500"><?= __("Nessuna alternativa disponibile") ?></p>`;
    }

    content.innerHTML = html;

    // Setup delegated event handlers for alternative buttons (only once per content element)
    if (!content.dataset.delegated) {
        content.dataset.delegated = 'true';
        content.addEventListener('click', (e) => {
            const btn = e.target.closest('button');
            if (!btn) return;

            if (btn.classList.contains('apply-alt-value')) {
                const field = btn.dataset.field;
                const value = btn.dataset.value;
                if (field && value) applyAlternativeValue(field, value);
            } else if (btn.classList.contains('apply-alt-publisher')) {
                const publisher = btn.dataset.publisher;
                if (publisher) applyAlternativePublisher(publisher);
            } else if (btn.classList.contains('apply-alt-cover')) {
                const cover = btn.dataset.cover;
                if (cover) applyAlternativeCover(cover);
            }
        });
    }

    panel.classList.remove('hidden');
}

// Helper functions for alternatives
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function escapeAttr(str) {
    return (str || '')
        .replace(/&/g, '&amp;')         // escape ampersand first
        .replace(/</g, '&lt;')          // escape less than
        .replace(/>/g, '&gt;')          // escape greater than
        .replace(/\r?\n/g, ' ')         // normalize newlines to space
        .replace(/"/g, '&quot;')        // escape double quote
        .replace(/'/g, '&#39;');        // escape single quote
}

// Sanitize URL to only allow safe protocols (http, https, relative paths)
function sanitizeUrl(url) {
    const value = (url || '').trim();
    if (!value) return '';
    if (value.startsWith('/')) return value;
    if (/^https?:\/\//i.test(value)) return value;
    return ''; // reject javascript:, data:, etc.
}

function applyAlternativeValue(fieldName, value) {
    const input = document.querySelector(`[name="${fieldName}"]`);
    if (input) {
        input.value = value;
        if (window.Toast) {
            window.Toast.fire({ icon: 'success', title: __('Valore applicato') });
        }
    }
}

function applyAlternativePublisher(name) {
    if (window.__renderEditorePreview) {
        window.__renderEditorePreview(name, { isNew: true });
        if (window.Toast) {
            window.Toast.fire({ icon: 'success', title: __('Editore applicato') });
        }
    }
}

function applyAlternativeCover(url) {
    const safeUrl = sanitizeUrl(url);
    if (!safeUrl) return; // reject unsafe URLs
    const coverHidden = document.getElementById('copertina_url');
    const scrapedCoverInput = document.getElementById('scraped_cover_url');
    if (coverHidden) coverHidden.value = safeUrl;
    if (scrapedCoverInput) scrapedCoverInput.value = safeUrl;
    displayScrapedCover(safeUrl);
    if (window.Toast) {
        window.Toast.fire({ icon: 'success', title: __('Copertina applicata') });
    }
}

// Initialize ISBN Import functionality
function initializeIsbnImport() {

    const btn = document.getElementById('btnImportIsbn');
    const input = document.getElementById('importIsbn');

    if (!btn || !input) return;
    const defaultBtnLabel = FORM_MODE === 'edit' ? __('Aggiorna Dati') : __('Importa Dati');
    
    btn.addEventListener('click', async function() {
        const isbn = input.value.trim();
        if (!isbn) {
            Swal.fire({
                icon: 'warning',
                title: __('ISBN Mancante'),
                text: __('Inserisci un codice ISBN per continuare.')
            });
            return;
        }
        
        // Show loading state
        btn.disabled = true;
        btn.innerHTML = `<i class="fas fa-spinner fa-spin mr-2"></i>${FORM_MODE === 'edit' ? __('Aggiornamento...') : __('Importazione...')}`;
        
        try {
            const response = await fetch(`/api/scrape/isbn?isbn=${encodeURIComponent(isbn)}`, {
                credentials: 'same-origin'  // Include session cookies for authentication
            });

            let data;

            try {
                data = await response.json();
            } catch (parseError) {
                throw new Error(isbnImportMessages.invalidResponse);
            }

            if (!response.ok) {
                // Even on error, try to populate ISBN fields from the response
                // (the API now returns calculated isbn10/isbn13 variants even on 404)
                if (data) {
                    try {
                        const isbn10Input = document.querySelector('input[name="isbn10"]');
                        const isbn13Input = document.querySelector('input[name="isbn13"]');
                        if (data.isbn13 && isbn13Input) {
                            isbn13Input.value = data.isbn13.replace(/[-\s]/g, '');
                        }
                        if (data.isbn10 && isbn10Input) {
                            isbn10Input.value = data.isbn10.replace(/[-\s]/g, '');
                        }
                        // Also try the generic isbn field
                        if (data.isbn) {
                            const cleanIsbn = data.isbn.replace(/[-\s]/g, '');
                            if (cleanIsbn.length === 10 && isbn10Input && !isbn10Input.value) {
                                isbn10Input.value = cleanIsbn;
                            } else if (cleanIsbn.length === 13 && isbn13Input && !isbn13Input.value) {
                                isbn13Input.value = cleanIsbn;
                            }
                        }
                    } catch (isbnErr) {
                        // Silent fail for ISBN population
                    }
                }

                // Use API error message if available, otherwise use default message
                let message = isbnImportMessages.genericError;
                if (data && data.error) {
                    message = data.error;
                } else if (response.status === 404 || response.status === 503) {
                    message = isbnImportMessages.notFound;
                }
                throw new Error(message);
            }

            if (data && data.error) {
                throw new Error(data.error);
            }

            // Title
            if (data.title) {
                const titleInput = document.querySelector('input[name="titolo"]');
                if (titleInput) {
                    titleInput.value = data.title;
                }
            }

            // Subtitle
            const subtitleInput = document.querySelector('input[name="sottotitolo"]');
            if (subtitleInput && data.subtitle) {
                subtitleInput.value = data.subtitle;
            }

            // Description - update TinyMCE if initialized (sanitize external data)
            if (data.description) {
                const descInput = document.querySelector('textarea[name="descrizione"]');
                if (descInput) {
                    // Sanitize description from external sources (XSS prevention)
                    let safeDescription;
                    if (window.DOMPurify) {
                        safeDescription = DOMPurify.sanitize(data.description, {
                            ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'ul', 'ol', 'li', 'a', 'b', 'i'],
                            ALLOWED_ATTR: ['href', 'title', 'target', 'rel']
                        });
                    } else {
                        // Fallback: strip all HTML tags for safety
                        const tempDiv = document.createElement('div');
                        tempDiv.textContent = data.description;
                        safeDescription = tempDiv.innerHTML;
                    }
                    descInput.value = safeDescription;
                    // Also update TinyMCE editor if available
                    if (window.tinymce && tinymce.get('descrizione')) {
                        tinymce.get('descrizione').setContent(safeDescription);
                    }
                }
            }
            
            // Handle publisher
            if (data.publisher) {
                document.getElementById('scraped_publisher').value = data.publisher;

                try {
                    const publishers = await fetchJSON(`/api/search/editori?q=${encodeURIComponent(data.publisher)}`);
                    if (publishers && publishers.length > 0) {
                        document.getElementById('editore_id').value = publishers[0].id;
                        document.getElementById('editore_search').value = publishers[0].label || data.publisher;
                        document.getElementById('editore_hint').textContent = `Editore trovato: ${publishers[0].label}`;
                        if (window.__renderEditorePreview) {
                            window.__renderEditorePreview(publishers[0].label || data.publisher, {
                                isNew: false,
                                publisherId: publishers[0].id
                            });
                        }
                    } else {
                        document.getElementById('editore_id').value = 0;
                        document.getElementById('editore_search').value = data.publisher;
                        document.getElementById('editore_hint').textContent = `Nuovo editore: ${data.publisher}`;
                        if (window.__renderEditorePreview) {
                            window.__renderEditorePreview(data.publisher, {isNew: true });
                        }
                    }
                } catch (error) {
                    console.error('Error searching publishers:', error);
                    document.getElementById('editore_id').value = 0;
                    document.getElementById('editore_search').value = data.publisher;
                    if (window.__renderEditorePreview) {
                        window.__renderEditorePreview(data.publisher, {isNew: true });
                    }
                }
            }
            
            // Handle authors (support multiple authors array) - select all at once
            try {
                if (authorsChoice && (Array.isArray(data.authors) ? data.authors.length > 0 : !!data.author)) {
                    let authorsRaw = Array.isArray(data.authors) && data.authors.length > 0 ? data.authors : [data.author];

                    // Normalize author names: "Surname, Name" → "Name Surname"
                    const normalizeAuthorName = (name) => {
                        name = (name || '').trim();
                        if (name.includes(',')) {
                            const parts = name.split(',', 2);
                            if (parts.length === 2) {
                                const surname = parts[0].trim();
                                const firstName = parts[1].trim();
                                if (surname && firstName) {
                                    return firstName + ' ' + surname;
                                }
                            }
                        }
                        return name;
                    };

                    // Normalize and deduplicate authors (case-insensitive)
                    const seenNormalized = new Set();
                    const authorsToProcess = [];
                    for (const rawName of authorsRaw) {
                        const normalized = normalizeAuthorName(rawName);
                        const key = normalized.toLowerCase();
                        if (normalized && !seenNormalized.has(key)) {
                            seenNormalized.add(key);
                            authorsToProcess.push(normalized);
                        }
                    }

                    const ensureChoiceFn = typeof window.ensureAuthorChoice === 'function'
                        ? window.ensureAuthorChoice
                        : null;

                    const selectElement = document.getElementById('autori_select');
                    if (!selectElement) {
                        return;
                    }

                    authorsChoice.removeActiveItems();
                    const hiddenContainer = document.getElementById('autori_hidden');
                    if (hiddenContainer) {
                        hiddenContainer.innerHTML = '';
                    }


                        for (const name of authorsToProcess) {
                        const label = (name || '').trim();
                        if (!label) {
                            continue;
                        }
                        let assignedId = null;

                        try {
                            const found = await fetchJSON(`/api/search/autori?q=${encodeURIComponent(label)}`);

                            if (found && found.length > 0) {
                                const existing = found[0];
                                assignedId = String(existing.id);
                                if (ensureChoiceFn) {
                                    await ensureChoiceFn(assignedId, existing.label || label);
                                } else {
                                    authorsChoice.setChoices([
                                        {value: assignedId, label: existing.label || label, selected: false }
                                    ], 'value', 'label', false);
                                }
                            } else {
                                assignedId = 'new_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
                                if (ensureChoiceFn) {
                                    await ensureChoiceFn(assignedId, label, {isNew: true });
                                } else {
                                    authorsChoice.setChoices([
                                        {value: assignedId, label, selected: false, customProperties: {isNew: true } }
                                    ], 'value', 'label', false);
                                }
                            }
                        } catch (err) {
                            assignedId = 'new_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
                            if (ensureChoiceFn) {
                                await ensureChoiceFn(assignedId, label, {isNew: true });
                            } else {
                                authorsChoice.setChoices([
                                    {value: assignedId, label, selected: false, customProperties: {isNew: true } }
                                ], 'value', 'label', false);
                            }
                        }

                        if (assignedId) {
                            authorsChoice.setChoiceByValue(assignedId);
                        }
                    }
                } else if (!authorsChoice) {
                } else {
                }
            } catch (err) {
            }
            
            // Handle cover image - store URL for backend download
            try {
                if (data.image) {
                    const scrapedCoverInput = document.getElementById('scraped_cover_url');
                    if (scrapedCoverInput) {
                        scrapedCoverInput.value = data.image;
                    }
                    const coverHidden = document.getElementById('copertina_url');
                    if (coverHidden) {
                        coverHidden.value = data.image;
                    }
                    displayScrapedCover(data.image);
                } else {
                }
            } catch (err) {
            }

            // Handle EAN - populate form field directly
            try {
                if (data.ean) {
                    const eanInput = document.querySelector('input[name="ean"]');
                    if (eanInput) {
                        eanInput.value = data.ean;
                    }
                    const scrapedEan = document.getElementById('scraped_ean');
                    if (scrapedEan) {
                        scrapedEan.value = data.ean;
                    }
                } else {
                }
            } catch (err) {
            }

            // Handle publication date - store Italian format directly
            try {
                if (data.pubDate) {
                    const pubDateInput = document.querySelector('input[name="data_pubblicazione"]');
                    if (pubDateInput) {
                        pubDateInput.value = data.pubDate;
                    }
                    const scrapedPubDate = document.getElementById('scraped_pub_date');
                    if (scrapedPubDate) {
                        scrapedPubDate.value = data.pubDate;
                    }
                } else {
                }
            } catch (err) {
            }

            // Handle price - populate form field directly
            try {
                if (data.price) {
                    // Parse price: remove currency symbols, codes (EUR, USD, etc.), and spaces
                    // Examples: "9.99 EUR" -> "9.99", "€12,50" -> "12.50", "$15.99" -> "15.99"
                    let priceValue = data.price.toString().trim();
                    priceValue = priceValue.replace(/[€$£¥\s]/g, ''); // Remove currency symbols and spaces
                    priceValue = priceValue.replace(/[A-Z]{3}/g, ''); // Remove 3-letter currency codes (EUR, USD, GBP, etc.)
                    priceValue = priceValue.replace(',', '.'); // Convert comma to dot for decimal
                    priceValue = priceValue.trim(); // Remove any remaining whitespace

                    const priceInput = document.querySelector('input[name="prezzo"]');
                    if (priceInput) {
                        priceInput.value = priceValue;
                    }
                    const scrapedPrice = document.getElementById('scraped_price');
                    if (scrapedPrice) {
                        scrapedPrice.value = priceValue;
                    }

                    if (window.Toast) {
                        window.Toast.fire({
                            icon: 'success',
                            title: bookFormMessages.priceImported.replace('%s', data.price)
                        });
                    }
                }
            } catch (err) {
            }

            // Handle format - populate form field directly
            try {
                if (data.format) {
                    const formatInput = document.querySelector('input[name="formato"]');
                    if (formatInput) {
                        formatInput.value = data.format;
                    }
                    const scrapedFormat = document.getElementById('scraped_format');
                    if (scrapedFormat) {
                        scrapedFormat.value = data.format;
                    }
                } else {
                }
            } catch (err) {
            }

            // Handle series (collana)
            try {
                if (data.series) {
                    const seriesInput = document.querySelector('input[name="collana"]');
                    if (seriesInput) {
                        seriesInput.value = data.series;
                    }
                    const scrapedSeries = document.getElementById('scraped_series');
                    if (scrapedSeries) {
                        scrapedSeries.value = data.series;
                    }
                } else {
                }
            } catch (err) {
            }

            // Handle pages
            try {
                if (data.pages) {
                    const pagesInput = document.querySelector('input[name="numero_pagine"]');
                    if (pagesInput) {
                        pagesInput.value = data.pages;
                    }
                    const scrapedPages = document.getElementById('scraped_pages');
                    if (scrapedPages) {
                        scrapedPages.value = data.pages;
                    }
                } else {
                }
            } catch (err) {
            }

            // Handle author bio - store for backend to update author record
            try {
                if (data.author_bio) {
                    const scrapedAuthorBio = document.getElementById('scraped_author_bio');
                    if (scrapedAuthorBio) {
                        scrapedAuthorBio.value = data.author_bio;
                    }
                }
            } catch (err) {
            }

            // Handle notes
            try {
                const noteField = document.querySelector('textarea[name="note_varie"]');
                const noteParts = [];
                if (noteField && noteField.value.trim() !== '') {
                    noteParts.push(noteField.value.trim());
                }
                if (data.notes) {
                    noteParts.push(data.notes.trim());
                }
                if (data.tipologia) {
                    noteParts.push(`Tipologia: ${data.tipologia.trim()}`);
                }
                if (noteField && noteParts.length > 0) {
                    const uniqueNotes = [];
                    noteParts.forEach(part => {
                        const clean = part.trim();
                        if (!clean) return;
                        const exists = uniqueNotes.some(existing => existing.toLowerCase() === clean.toLowerCase());
                        if (!exists) {
                            uniqueNotes.push(clean);
                        }
                    });
                    noteField.value = uniqueNotes.join('\n');
                }
                const tipologiaHidden = document.getElementById('scraped_tipologia');
                if (tipologiaHidden) {
                    tipologiaHidden.value = data.tipologia ? data.tipologia.trim() : '';
                }
            } catch (err) {
            }

            // Handle ISBN values - check all possible fields (isbn, isbn10, isbn13)
            try {
                const isbn10Input = document.querySelector('input[name="isbn10"]');
                const isbn13Input = document.querySelector('input[name="isbn13"]');

                // Direct isbn13 field (from SBN, Open Library, etc.)
                if (data.isbn13 && isbn13Input) {
                    isbn13Input.value = data.isbn13.replace(/[-\s]/g, '');
                }
                // Direct isbn10 field
                if (data.isbn10 && isbn10Input) {
                    isbn10Input.value = data.isbn10.replace(/[-\s]/g, '');
                }
                // Generic isbn field (fallback, length-based routing)
                if (data.isbn) {
                    const isbn = data.isbn.replace(/[-\s]/g, '');
                    if (isbn.length === 10 && isbn10Input && !isbn10Input.value) {
                        isbn10Input.value = isbn;
                    } else if (isbn.length === 13 && isbn13Input && !isbn13Input.value) {
                        isbn13Input.value = isbn;
                    }
                }
            } catch (err) {
                // Silent fail
            }

            // Handle year (anno_pubblicazione) - numeric year for filtering/sorting
            try {
                if (data.year) {
                    const yearInput = document.querySelector('input[name="anno_pubblicazione"]');
                    if (yearInput) {
                        yearInput.value = data.year;
                    } else {
                    }
                } else {
                }
            } catch (err) {
            }

            // Handle language (lingua) - book's original language
            try {
                if (data.language) {
                    const languageInput = document.querySelector('input[name="lingua"]');
                    if (languageInput) {
                        languageInput.value = data.language;
                    } else {
                    }
                } else {
                }
            } catch (err) {
            }

            // Handle keywords (parole_chiave) - categories from Google Books
            try {
                if (data.keywords) {
                    const keywordsInput = document.querySelector('input[name="parole_chiave"]');
                    if (keywordsInput) {
                        keywordsInput.value = data.keywords;
                    }
                }
            } catch (err) {
            }

            // Handle Dewey classification (classificazione_dewey) - from SBN or other sources
            try {
                if (data.classificazione_dewey) {
                    if (typeof window.setDeweyCode === 'function') {
                        await window.setDeweyCode(data.classificazione_dewey, null);
                    } else {
                        // Fallback if setDeweyCode not available
                        const deweyHidden = document.getElementById('classificazione_dewey');
                        if (deweyHidden) {
                            deweyHidden.value = data.classificazione_dewey;
                        }
                    }
                }
            } catch (err) {
                // Silently fail - Dewey is optional
            }

            // Data di acquisizione is not from scraping - it's when WE acquire the book
            // Set it to today's date automatically
            try {
                const today = new Date().toISOString().split('T')[0];
                const acquisitionInput = document.querySelector('input[name="data_acquisizione"]');
                if (acquisitionInput) {
                    acquisitionInput.value = today;
                } else {
                }
            } catch (err) {
            }

            // Summary of fields populated
            const fieldsPopulated = [];
            if (data.title) fieldsPopulated.push('title');
            if (data.subtitle) fieldsPopulated.push('subtitle');
            if (data.description) fieldsPopulated.push('description');
            if (data.publisher) fieldsPopulated.push('publisher');
            if (data.authors && data.authors.length > 0) fieldsPopulated.push('authors (' + data.authors.length + ')');
            if (data.image) fieldsPopulated.push('cover image');
            if (data.ean) fieldsPopulated.push('EAN');
            if (data.pubDate) fieldsPopulated.push('publication date');
            if (data.price) fieldsPopulated.push('price');
            if (data.format) fieldsPopulated.push('format');
            if (data.series) fieldsPopulated.push('series');
            if (data.pages) fieldsPopulated.push('pages');
            if (data.notes) fieldsPopulated.push('notes');
            if (data.isbn) fieldsPopulated.push('ISBN');
            if (data.year) fieldsPopulated.push('year');
            if (data.language) fieldsPopulated.push('language');
            if (data.keywords) fieldsPopulated.push('keywords');

            // Show source information panel
            displayScrapeSourceInfo(data);

            // Show success toast (small notification)
            if (window.Toast) {
                window.Toast.fire({
                    icon: 'success',
                    title: __('Importazione completata con successo!')
                });
            }

        } catch (error) {
            const fallbackMessage = __("Errore durante l'importazione dati");
            const message = error && typeof error.message === 'string' && error.message.trim() !== ''
                ? error.message
                : fallbackMessage;
            if (window.Toast) {
                window.Toast.fire({
                    icon: 'error',
                    title: message
                });
            }
        } finally {
            btn.disabled = false;
            btn.innerHTML = `<i class="fas fa-download mr-2"></i>${defaultBtnLabel}`;
        }
    });
}

// Display scraped cover image
function displayScrapedCover(imageUrl) {
    
    if (!imageUrl) return;
    
    const container = document.getElementById('cover-preview-container');
    if (!container) return;
    
    // Clear existing content
    container.innerHTML = '';
    
    // Create image element
    const img = document.createElement('img');
    let imageSrc = imageUrl;

    if (imageUrl.startsWith('/')) {
        // Local image - use as is
        imageSrc = window.location.origin + imageUrl;
    } else if (imageUrl.startsWith('http')) {
        // External image - use plugin proxy (no domain whitelist)
        imageSrc = `/api/plugins/proxy-image?url=${encodeURIComponent(imageUrl)}`;
    }

    img.src = imageSrc;
    img.alt = '<?= __("Copertina recuperata automaticamente") ?>';
    img.className = 'max-h-48 object-contain border border-gray-200 rounded-lg shadow-sm';
    
    img.onload = function() {
    };
    
    img.onerror = function() {
        console.error('Failed to load scraped cover:', imageSrc);
        container.innerHTML = `
            <div class="bg-gray-100 border-2 border-dashed border-gray-300 rounded-lg p-6 text-center">
                <div class="text-gray-400 mb-2">
                    <i class="fas fa-image text-3xl"></i>
                </div>
                <p class="text-sm text-gray-600 mb-2"><?= __("Anteprima non disponibile") ?></p>
                <p class="text-xs text-gray-500 mb-3"><?= __("L'immagine verrà scaricata al salvataggio") ?></p>
                <a href="${imageSrc}" target="_blank" class="text-xs text-gray-700 hover:text-gray-900 underline break-all">${imageUrl}</a>
            </div>
        `;
        return;
    };
    
    // Create container with image and info
    container.innerHTML = `
        <div class="inline-flex flex-col items-start space-y-2">
            <div class="relative">
                ${img.outerHTML}
            </div>
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-2 text-sm text-gray-600">
                    <i class="fas fa-globe text-gray-600"></i>
                    <span><?= __("Copertina recuperata automaticamente") ?></span>
                </div>
                <button type="button" onclick="removeCoverImage()" class="text-xs text-red-600 hover:text-red-800 hover:underline flex items-center gap-1">
                    <i class="fas fa-trash"></i>
                    Rimuovi
                </button>
            </div>
        </div>
    `;
}

// Utility function for fetching JSON
async function fetchJSON(url) {
    const response = await fetch(url, {
        credentials: 'same-origin'  // Include session cookies for authentication
    });
    if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
    }
    return response.json();
}

// Convert Italian date format to ISO (YYYY-MM-DD)
function convertItalianDateToISO(italianDate) {
    if (!italianDate) return null;
    
    const monthNames = {
        'gennaio': '01', 'febbraio': '02', 'marzo': '03', 'aprile': '04',
        'maggio': '05', 'giugno': '06', 'luglio': '07', 'agosto': '08',
        'settembre': '09', 'ottobre': '10', 'novembre': '11', 'dicembre': '12'
    };
    
    // Match format like "26 agosto 2025"
    const match = italianDate.match(/(\d{1,2})\s+(\w+)\s+(\d{4})/i);
    if (match) {
        const day = match[1].padStart(2, '0');
        const monthName = match[2].toLowerCase();
        const year = match[3];
        const month = monthNames[monthName];
        
        if (month) {
            return `${year}-${month}-${day}`;
        }
    }
    
    return null;
}

// Add some CSS for loading states and animations
const style = document.createElement('style');
style.textContent = `
    .fade-in {
        animation: fadeIn 0.5s ease-in-out;
    }
    
    .slide-in-up {
        animation: slideInUp 0.5s ease-out;
    }
    
    @keyframes fadeIn {
        from {opacity: 0; }
        to {opacity: 1; }
    }
    
    @keyframes slideInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .btn-primary:disabled,
    .btn-secondary:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none !important;
    }
    
    /* Choices.js styling to match form inputs */
    .choices__inner {
        background-color: white !important;
        border: 1px solid #d1d5db !important;
        border-radius: 0.375rem !important;
        font-size: 0.875rem !important;
        padding: 8px !important;
        min-height: 44px !important;
    }

    /* Desktop: use flex layout */
    @media screen and (min-width: 769px) {
        .choices__inner {
            display: flex !important;
            align-items: center !important;
            padding: 0 !important;
        }
    }
    
    .choices__list--multiple .choices__item {
        background-color: #1e293b !important;
        border: 1px solid #334155 !important;
        border-radius: 9999px !important;
        color: #f1f5f9 !important;
        font-size: 0.75rem !important;
        margin: 2px !important;
        padding: 4px 12px !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 6px !important;
    }

    /* Style for new authors (to be created) */
    .choices__list--multiple .choices__item[data-custom-properties*="isNew\":true"] {
        background-color: #1f2937 !important;
        border-color: #1d4ed8 !important;
        color: white !important;
    }

    .choices__input {
        background-color: transparent !important;
        margin: 0 8px !important;
        font-size: 0.875rem !important;
        flex: 1 1 auto !important;
        min-width: 200px !important;
    }

    .choices__input--cloned {
        flex: 1 1 auto !important;
        min-width: 200px !important;
    }

    .choices__placeholder {
        color: #9ca3af !important;
        margin: 0 8px !important;
    }

    /* Dropdown styling */
    .choices__list--dropdown {
        background-color: white !important;
        border: 1px solid #d1d5db !important;
        border-radius: 0.375rem !important;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06) !important;
        z-index: 100 !important;
    }

    .choices__list--dropdown .choices__item {
        color: #111827 !important;
        font-size: 0.875rem !important;
        padding: 8px 12px !important;
    }

    .choices__list--dropdown .choices__item--selectable {
        background-color: white !important;
        color: #111827 !important;
    }

    .choices__list--dropdown .choices__item--selectable.is-highlighted {
        background-color: #dbeafe !important;
        color: #111827 !important;
    }

    .choices__list--dropdown .choices__item--selectable:hover {
        background-color: #f3f4f6 !important;
        color: #111827 !important;
    }

    .choices__list--dropdown .choices__item--selectable:active {
        background-color: #e5e7eb !important;
        color: #111827 !important;
    }

    .choices__list--dropdown .choices__item--selectable:focus {
        background-color: #dbeafe !important;
        color: #111827 !important;
    }

    .choices__item,
    .choices__item:hover,
    .choices__item:active,
    .choices__item:focus,
    .choices__item.is-highlighted,
    .choices__item.is-selected {
        color: #111827 !important;
    }

    /* Editore chip styling */
    .editore-chip {
        transition: all 0.2s ease-in-out;
        align-items: center;
    }

    .editore-chip:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .editore-chip button {
        width: 20px;
        height: 20px;
        min-width: 20px;
        min-height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        font-size: 12px;
        line-height: 1;
    }

    /* Mobile styles for Choices.js chips */
    @media screen and (max-width: 768px) {
        .choices .choices__inner,
        div.choices__inner,
        .choices__inner {
            display: block !important;
            padding: 8px !important;
            min-height: auto !important;
            height: auto !important;
            flex-direction: unset !important;
            align-items: unset !important;
        }

        .choices__list.choices__list--multiple,
        .choices__list--multiple {
            display: block !important;
            width: 100% !important;
            margin-bottom: 8px !important;
        }

        .choices__list--multiple .choices__item,
        .choices__list--multiple .choices__item--selectable {
            display: flex !important;
            width: 100% !important;
            max-width: 100% !important;
            white-space: normal !important;
            padding: 8px 12px !important;
            font-size: 0.875rem !important;
            justify-content: space-between !important;
            align-items: center !important;
            border-radius: 8px !important;
            margin-bottom: 6px !important;
            box-sizing: border-box !important;
        }

        .choices__list--multiple .choices__item .choices__button {
            flex-shrink: 0 !important;
            margin-left: 8px !important;
        }

        .choices__input,
        .choices__input--cloned,
        input.choices__input--cloned {
            min-width: 0 !important;
            width: 100% !important;
            display: block !important;
        }

        /* Editore chips mobile */
        #editore_chip_list {
            display: block !important;
            width: 100% !important;
        }

        .editore-chip {
            display: flex !important;
            width: 100% !important;
            max-width: 100% !important;
            justify-content: space-between !important;
            margin-bottom: 6px !important;
        }
    }
`;
document.head.appendChild(style);

// Initialize TinyMCE for book description (basic editor: bold, italic, lists)
let tinyMceInitAttempts = 0;
const TINYMCE_MAX_RETRIES = 30;
function initBookTinyMCE() {
    if (window.tinymce) {
        // Guard against double initialization
        if (tinymce.get('descrizione')) {
            return;
        }
        tinymce.init({
            selector: '#descrizione',
            license_key: 'gpl',
            height: 250,
            menubar: false,
            toolbar_mode: 'wrap',
            plugins: ['lists', 'link', 'autolink'],
            toolbar: 'bold italic | bullist numlist | link | removeformat',
            content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 14px; line-height: 1.5; }',
            branding: false,
            promotion: false,
            statusbar: false,
            placeholder: '<?= addslashes(__("Descrizione del libro...")) ?>'
        });
    } else {
        // TinyMCE not loaded yet, retry in 100ms (with cap)
        if (tinyMceInitAttempts < TINYMCE_MAX_RETRIES) {
            tinyMceInitAttempts += 1;
            setTimeout(initBookTinyMCE, 100);
        } else {
            console.error('TinyMCE non disponibile dopo i retry.');
        }
    }
}
// Wait for DOM then init TinyMCE
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBookTinyMCE);
} else {
    initBookTinyMCE();
}

</script>

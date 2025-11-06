<?php
use App\Support\HtmlHelper;

$mode = $mode ?? 'create';
$book = $book ?? [];
$csrfToken = $csrfToken ?? null;
$error_message = $error_message ?? null;
$action = $action ?? ($mode === 'edit' ? '/admin/libri/update/' . ($book['id'] ?? '') : '/admin/libri/crea');
$currentCover = $book['copertina_url'] ?? ($book['copertina'] ?? '');

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
    'classificazione_dowey' => $book['classificazione_dowey'] ?? '',
    'editore_id' => (int)($book['editore_id'] ?? 0),
    'editore_nome' => $book['editore_nome'] ?? '',
    'scaffale_id' => (int)($book['scaffale_id'] ?? 0),
    'mensola_id' => $initialMensolaId,
    'posizione_progressiva' => $initialPosizioneProgressiva,
    'collocazione' => $initialCollocazione,
    'stato' => $book['stato'] ?? '',
    'tipo_acquisizione' => $book['tipo_acquisizione'] ?? '',
    'data_acquisizione' => $book['data_acquisizione'] ?? '',
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

    <form id="bookForm" data-mode="<?php echo $modeAttr; ?>" method="post" action="<?php echo $actionAttr; ?>" class="space-y-8 slide-in-up" enctype="multipart/form-data" style="animation-delay: 0.1s;">
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

      <!-- Basic Information Section -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-book text-primary"></i>
            Informazioni Base
          </h2>
        </div>
        <div class="card-body form-section">
          <div class="form-grid-2">
            <div>
              <label for="titolo" class="form-label">
                Titolo <span class="text-red-500">*</span>
              </label>
              <input id="titolo" name="titolo" type="text" required aria-required="true" class="form-input" placeholder="<?= __('es. La morale anarchica') ?>" value="<?php echo HtmlHelper::e($book['titolo'] ?? ''); ?>" />
            </div>
            <div>
              <label for="sottotitolo" class="form-label">Sottotitolo</label>
              <input id="sottotitolo" name="sottotitolo" type="text" class="form-input" placeholder="<?= __('Sottotitolo del libro (opzionale)') ?>" value="<?php echo HtmlHelper::e($book['sottotitolo'] ?? ''); ?>" />
            </div>
          </div>
          
          <div class="form-grid-3">
            <div>
              <label for="isbn10" class="form-label">ISBN 10</label>
              <input id="isbn10" name="isbn10" type="text" class="form-input" placeholder="<?= __('es. 8842935786') ?>" value="<?php echo HtmlHelper::e($book['isbn10'] ?? ''); ?>" />
            </div>
            <div>
              <label for="isbn13" class="form-label">ISBN 13</label>
              <input id="isbn13" name="isbn13" type="text" class="form-input" placeholder="<?= __('es. 9788842935780') ?>" value="<?php echo HtmlHelper::e($book['isbn13'] ?? ''); ?>" />
            </div>
            <div>
              <label for="edizione" class="form-label">Edizione</label>
              <input id="edizione" name="edizione" type="text" class="form-input" placeholder="<?= __('es. Prima edizione') ?>" value="<?php echo HtmlHelper::e($book['edizione'] ?? ''); ?>" />
              <p class="text-xs text-gray-500 mt-1">Numero o descrizione dell'edizione</p>
            </div>
          </div>

          <div class="form-grid-2">
            <div>
              <label for="data_pubblicazione" class="form-label">Data di Pubblicazione</label>
              <input id="data_pubblicazione" name="data_pubblicazione" type="text" class="form-input" placeholder="<?= __('es. 26 agosto 2025') ?>" value="<?php echo HtmlHelper::e($book['data_pubblicazione'] ?? ''); ?>" />
              <p class="text-xs text-gray-500 mt-1">Data originale di pubblicazione (formato italiano)</p>
            </div>
            <div>
              <label for="anno_pubblicazione" class="form-label">Anno di Pubblicazione</label>
              <input id="anno_pubblicazione" name="anno_pubblicazione" type="number" min="1000" max="2100" class="form-input" placeholder="<?= __('es. 2025') ?>" value="<?php echo HtmlHelper::e($book['anno_pubblicazione'] ?? ''); ?>" />
              <p class="text-xs text-gray-500 mt-1">Anno numerico (usato per filtri e ordinamento)</p>
            </div>
          </div>

          <div class="form-grid-2">
            <div>
              <label for="ean" class="form-label">EAN</label>
              <input id="ean" name="ean" type="text" class="form-input" placeholder="<?= __('es. 9788842935780') ?>" value="<?php echo HtmlHelper::e($book['ean'] ?? ''); ?>" />
              <p class="text-xs text-gray-500 mt-1">European Article Number (opzionale)</p>
            </div>
            <div>
              <label for="lingua" class="form-label">Lingua</label>
              <input id="lingua" name="lingua" type="text" class="form-input" placeholder="<?= __('es. Italiano, Inglese') ?>" value="<?php echo HtmlHelper::e($book['lingua'] ?? ''); ?>" />
              <p class="text-xs text-gray-500 mt-1">Lingua originale del libro</p>
            </div>
          </div>
          <div class="mt-2 text-xs text-gray-500" id="genre_path_preview" style="min-height:1.25rem;">
            <!-- Percorso selezionato -->
          </div>

          <!-- Publisher with Enhanced Search -->
          <div>
            <label for="editore_field" class="form-label">Editore</label>
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
            <label for="autori_select" class="form-label">Autori</label>
            <select id="autori_select" name="autori_select[]" multiple placeholder="<?= __('Cerca autori esistenti o aggiungine di nuovi...') ?>" data-initial-authors="<?php echo $initialAuthorsJson; ?>">
              <!-- Options will be populated dynamically -->
            </select>
            <div id="autori_hidden"></div>
            <p class="text-xs text-gray-500 mt-1">Puoi selezionare più autori o aggiungerne di nuovi digitando il nome</p>
          </div>

          <!-- Book Status -->
          <div>
            <label for="stato" class="form-label">Disponibilità</label>
            <?php $statoCorrente = $book['stato'] ?? ''; ?>
            <select id="stato" name="stato" class="form-input">
              <option value="Disponibile" <?php echo strcasecmp($statoCorrente, 'Disponibile') === 0 ? 'selected' : ''; ?>><?= __("Disponibile") ?></option>
              <option value="Non Disponibile" <?php echo strcasecmp($statoCorrente, 'Non Disponibile') === 0 ? 'selected' : ''; ?>>Non Disponibile</option>
              <option value="Prestato" <?php echo strcasecmp($statoCorrente, 'Prestato') === 0 ? 'selected' : ''; ?>>Prestato</option>
              <option value="Riservato" <?php echo strcasecmp($statoCorrente, 'Riservato') === 0 ? 'selected' : ''; ?>>Riservato</option>
              <option value="Danneggiato" <?php echo strcasecmp($statoCorrente, 'Danneggiato') === 0 ? 'selected' : ''; ?>>Danneggiato</option>
              <option value="Perso" <?php echo strcasecmp($statoCorrente, 'Perso') === 0 ? 'selected' : ''; ?>>Perso</option>
              <option value="In Riparazione" <?php echo strcasecmp($statoCorrente, 'In Riparazione') === 0 ? 'selected' : ''; ?>>In Riparazione</option>
              <option value="Fuori Catalogo" <?php echo strcasecmp($statoCorrente, 'Fuori Catalogo') === 0 ? 'selected' : ''; ?>>Fuori Catalogo</option>
              <option value="Da Inventariare" <?php echo strcasecmp($statoCorrente, 'Da Inventariare') === 0 ? 'selected' : ''; ?>>Da Inventariare</option>
            </select>
            <p class="text-xs text-gray-500 mt-1">Status attuale di questa copia del libro</p>
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
            Classificazione Dewey
          </h2>
        </div>
        <div class="card-body form-section">
          <input type="hidden" name="classificazione_dowey" id="classificazione_dowey" value="<?php echo HtmlHelper::e($book['classificazione_dowey'] ?? ''); ?>" />
          <div class="form-grid-3">
            <div>
              <label for="dewey_l1" class="form-label">Classe (000-900)</label>
              <select id="dewey_l1" name="dewey_l1" class="form-input">
                <option value="">Seleziona classe...</option>
              </select>
            </div>
            <div>
              <label for="dewey_l2" class="form-label">Divisione (010-990)</label>
              <select id="dewey_l2" name="dewey_l2" class="form-input" disabled>
                <option value="">Seleziona divisione...</option>
              </select>
            </div>
            <div>
              <label for="dewey_l3" class="form-label">Sezione</label>
              <select id="dewey_l3" name="dewey_l3" class="form-input" disabled>
                <option value="">Seleziona sezione...</option>
              </select>
            </div>
          </div>
          <p class="text-xs text-gray-500 mt-2">Codice Dewey selezionato: <span id="dewey_code" class="font-mono font-semibold text-gray-900">-</span></p>
          <p class="text-xs text-gray-500 mt-1">La classificazione Dewey è utilizzata per organizzare i libri per argomento secondo standard internazionali</p>

          <h3 class="text-lg font-semibold text-gray-900 mt-6 mb-4">Genere</h3>

          <div class="form-grid-3">
            <div>
              <label for="radice_select" class="form-label">Radice</label>
              <select id="radice_select" name="radice_id" class="form-input" data-initial-radice="<?php echo (int)$initialData['radice_id']; ?>">
                <option value="0">Seleziona radice...</option>
              </select>
              <p class="text-xs text-gray-500 mt-1">Livello principale (es. Prosa, Poesia, Teatro)</p>
            </div>
            <div>
              <label for="genere_select" class="form-label">Genere</label>
              <select id="genere_select" name="genere_id" class="form-input" disabled data-initial-genere="<?php echo (int)$initialData['genere_id']; ?>">
                <option value="0">Seleziona prima una radice...</option>
              </select>
              <p class="text-xs text-gray-500 mt-1" id="genere_hint">Genere letterario del libro</p>
            </div>
            <div>
              <label for="sottogenere_select" class="form-label">Sottogenere</label>
              <select id="sottogenere_select" name="sottogenere_id" class="form-input" disabled data-initial-sottogenere="<?php echo (int)$initialData['sottogenere_id']; ?>">
                <option value="0">Seleziona prima un genere...</option>
              </select>
              <p class="text-xs text-gray-500 mt-1" id="sottogenere_hint">Sottogenere specifico (opzionale)</p>
            </div>
          </div>

          <!-- Keywords -->
          <div class="mt-4">
            <label for="parole_chiave" class="form-label">Parole Chiave</label>
            <input id="parole_chiave" name="parole_chiave" type="text" class="form-input" placeholder="<?= __('es. romanzo, fantasy, avventura (separare con virgole)') ?>" value="<?php echo HtmlHelper::e($book['parole_chiave'] ?? ''); ?>" />
            <p class="text-xs text-gray-500 mt-1">Inserisci parole chiave separate da virgole per facilitare la ricerca</p>
          </div>
        </div>
      </div>
      <!-- Acquisition Details Section -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-shopping-cart text-primary"></i>
            Dettagli Acquisizione
          </h2>
        </div>
        <div class="card-body form-section">
          <div class="form-grid-3">
            <div>
              <label for="tipo_acquisizione" class="form-label">Data Acquisizione</label>
              <input type="date" name="data_acquisizione" class="form-input" value="<?php echo HtmlHelper::e($book['data_acquisizione'] ?? ''); ?>" />
            </div>
            <div>
              <label for="tipo_acquisizione" class="form-label">Tipo Acquisizione</label>
              <input id="tipo_acquisizione" name="tipo_acquisizione" type="text" class="form-input" placeholder="<?= __('es. Acquisto, Donazione, Prestito') ?>" value="<?php echo HtmlHelper::e($book['tipo_acquisizione'] ?? ''); ?>" />
            </div>
            <div>
              <label for="prezzo" class="form-label">Prezzo (€)</label>
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
            Dettagli Fisici
          </h2>
        </div>
        <div class="card-body form-section">
          <div class="form-grid-3">
            <div>
              <label for="formato" class="form-label">Formato</label>
              <input id="formato" name="formato" type="text" class="form-input" placeholder="<?= __('es. Copertina rigida, Brossura') ?>" value="<?php echo HtmlHelper::e($book['formato'] ?? ''); ?>" />
            </div>
            <div>
              <label for="numero_pagine" class="form-label">Numero Pagine</label>
              <input id="numero_pagine" name="numero_pagine" type="number" class="form-input" placeholder="<?= __('es. 320') ?>" value="<?php echo HtmlHelper::e($book['numero_pagine'] ?? ''); ?>" />
            </div>
            <div>
              <label for="peso" class="form-label">Peso (kg)</label>
              <input id="peso" name="peso" type="number" step="0.001" class="form-input" placeholder="<?= __('es. 0.450') ?>" value="<?php echo HtmlHelper::e($book['peso'] ?? ''); ?>" />
            </div>
          </div>

          <div>
            <label for="dimensioni" class="form-label">Dimensioni</label>
            <input id="dimensioni" name="dimensioni" type="text" class="form-input" placeholder="<?= __('es. 21x14 cm') ?>" value="<?php echo HtmlHelper::e($book['dimensioni'] ?? ''); ?>" />
          </div>
          
          <div class="form-grid-3">
            <div>
              <label for="copie_totali" class="form-label">Copie Totali <span class="text-xs text-gray-500">(Le copie disponibili vengono calcolate automaticamente)</span></label>
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
            Gestione Biblioteca
          </h2>
        </div>
        <div class="card-body form-section">
          <div class="form-grid-3">
            <div>
              <label for="numero_inventario" class="form-label">Numero Inventario</label>
              <input id="numero_inventario" name="numero_inventario" type="text" class="form-input" placeholder="<?= __('es. INV-2024-001') ?>" value="<?php echo HtmlHelper::e($book['numero_inventario'] ?? ''); ?>" />
            </div>
            <div>
              <label for="collana" class="form-label">Collana</label>
              <input id="collana" name="collana" type="text" class="form-input" placeholder="<?= __('es. I Classici') ?>" value="<?php echo HtmlHelper::e($book['collana'] ?? ''); ?>" />
            </div>
            <div>
              <label for="numero_serie" class="form-label">Numero Serie</label>
              <input id="numero_serie" name="numero_serie" type="text" class="form-input" placeholder="<?= __('es. 15') ?>" value="<?php echo HtmlHelper::e($book['numero_serie'] ?? ''); ?>" />
            </div>
          </div>

          <div class="form-grid-2">
            <div>
              <label for="file_url" class="form-label">File URL</label>
              <input id="file_url" name="file_url" type="text" class="form-input" placeholder="<?= __('Link al file digitale (se disponibile)') ?>" value="<?php echo HtmlHelper::e($book['file_url'] ?? ''); ?>" />
            </div>
            <div>
              <label for="audio_url" class="form-label">Audio URL</label>
              <input id="audio_url" name="audio_url" type="text" class="form-input" placeholder="<?= __('Link all\'audiolibro (se disponibile)') ?>" value="<?php echo HtmlHelper::e($book['audio_url'] ?? ''); ?>" />
            </div>
          </div>

          <!-- Notes -->
          <div>
            <label for="note_varie" class="form-label">Note Varie</label>
            <textarea id="note_varie" name="note_varie" rows="3" class="form-input" placeholder="<?= __('Note aggiuntive o osservazioni particolari...') ?>"><?php echo HtmlHelper::e($book['note_varie'] ?? ''); ?></textarea>
          </div>
        </div>
      </div>

      <!-- Cover Upload Section -->
      <div class="card">
        <div class="card-header">
          <h2 class="form-section-title flex items-center gap-2">
            <i class="fas fa-image text-primary"></i>
            Copertina del Libro
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
                    <span class="text-xs text-gray-500">Copertina attuale</span>
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
            Posizione Fisica nella Biblioteca
          </h2>
        </div>
        <div class="card-body form-section">
          <div class="form-grid-2">
            <div>
              <label for="scaffale_id" class="form-label">Scaffale</label>
              <select id="scaffale_id" name="scaffale_id" class="form-input">
                <option value="0">Seleziona scaffale...</option>
                <?php foreach ($scaffali as $s): ?>
                  <option value="<?php echo (int)$s['id']; ?>" <?php echo ((int)$s['id'] === (int)($book['scaffale_id'] ?? 0)) ? 'selected' : ''; ?>><?php echo htmlspecialchars('['.($s['codice'] ?? '').'] '.($s['nome'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="form-label">Mensola</label>
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
                  <option value="0">Seleziona prima uno scaffale...</option>
                <?php else: ?>
                  <option value="0">Seleziona mensola...</option>
                  <?php foreach ($mensoleOptions as $mensola): ?>
                    <option value="<?php echo (int)$mensola['id']; ?>" <?php echo ((int)$mensola['id'] === $selectedMensola) ? 'selected' : ''; ?>>
                      <?php echo HtmlHelper::e('Livello ' . ($mensola['numero_livello'] ?? '')); ?>
                    </option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </div>
          </div>
          <div class="form-grid-2 mt-3">
            <div>
              <label for="posizione_progressiva_input" class="form-label">Posizione progressiva</label>
              <div class="flex flex-col gap-2">
                <input type="number" min="1" name="posizione_progressiva" id="posizione_progressiva_input" class="form-input" value="<?php echo $initialPosizioneProgressiva ?: ''; ?>" placeholder="<?= __('Auto') ?>" />
                <button type="button" id="btnAutoPosition" class="btn-outline w-full sm:w-auto"><i class="fas fa-sync mr-2"></i>Genera automaticamente</button>
                <p class="text-xs text-gray-500">Lascia vuoto o usa "Genera" per assegnare automaticamente la prossima posizione disponibile.</p>
              </div>
            </div>
            <div>
              <label for="collocazione_preview" class="form-label">Collocazione calcolata</label>
              <input type="text" id="collocazione_preview" name="collocazione_preview" class="form-input bg-slate-900/20 text-slate-100" value="<?php echo HtmlHelper::e($initialCollocazione); ?>" readonly />
              <p class="text-xs text-gray-500 mt-1">Aggiornata in base a scaffale, mensola e posizione.</p>
            </div>
          </div>
          <p class="text-xs text-gray-500 mt-2">La posizione fisica è indipendente dalla classificazione Dewey e indica dove si trova il libro sugli scaffali.</p>
          <div class="mt-3">
            <button type="button" id="btnSuggestCollocazione" class="btn-outline"><i class="fas fa-magic mr-2"></i>Suggerisci collocazione</button>
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
          Annulla
        </button>
        <button type="submit" class="btn-primary order-1 sm:order-2">
          <i class="fas fa-save mr-2"></i>
          <?php echo $mode === 'edit' ? 'Salva Modifiche' : 'Salva Libro'; ?>
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
        if (uppy) uppy.close();
    });
});

// Initialize Uppy File Upload
function initializeUppy() {
    // Debug: Check if Uppy libraries are loaded
    // console.log({
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
            note: 'Trascina qui la copertina del libro o clicca per selezionare',
            locale: {
                strings: {
                    dropPasteFiles: 'Trascina qui la copertina del libro o %{browse}',
                    browse: 'seleziona file'
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
                title: __('Immagine Caricata!'),
                text: `File "${file.name}" pronto per l'upload`,
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
            <span>La copertina verrà rimossa al salvataggio del libro</span>
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
            placeholderValue: 'Cerca autori esistenti o aggiungine di nuovi...',
            noChoicesText: 'Nessun autore trovato, premi Invio per aggiungerne uno nuovo',
            itemSelectText: 'Clicca per selezionare',
            addItemText: (value) => `Aggiungi <b>"${value}"</b> come nuovo autore`,
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
                        title: `Autore "${normalizedLabel}" è già selezionato`
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
                        title: `Autore "${normalizedLabel}" pronto per essere creato`
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

// Initialize Dewey cascading selects
async function initializeDewey() {
  const l1 = document.getElementById('dewey_l1');
  const l2 = document.getElementById('dewey_l2');
  const l3 = document.getElementById('dewey_l3');
  const codeOut = document.getElementById('dewey_code');
  const hidden = document.getElementById('classificazione_dowey');

  const initialParts = (INITIAL_BOOK.classificazione_dowey || '').split('-').filter(Boolean);
  let appliedL2 = false;
  let appliedL3 = false;

  const fill = (sel, items, placeholder) => {
    sel.innerHTML = '';
    const opt0 = document.createElement('option');
    opt0.value = ''; opt0.textContent = placeholder; sel.appendChild(opt0);
    items.forEach(it => {
      const o = document.createElement('option');
      o.value = it.codice; // Use the code as value
      o.dataset.code = it.codice;
      o.textContent = `${it.codice} — ${it.nome}`;
      sel.appendChild(o);
    });
  };

  const updateHidden = () => {
    const code1 = l1.value || '';
    const code2 = l2.value || '';
    const code3 = l3.value || '';
    
    let finalCode = '';
    if (code1 && code2 && code3) {
      // All three levels selected: XXX-XXX-XXX
      finalCode = `${code1}-${code2}-${code3}`;
    } else if (code1 && code2) {
      // Two levels selected: XXX-XXX
      finalCode = `${code1}-${code2}`;
    } else if (code1) {
      // Only first level selected: XXX
      finalCode = code1;
    }
    
    hidden.value = finalCode;
    codeOut.textContent = finalCode || '-';
  };

  // Load L1 categories
  try {
    const cats = await fetch('/api/dewey/categories').then(r => r.json());
    // console.debug('Dewey L1 categories loaded:', cats.length);

    if (!cats || cats.length === 0) {
      const warn = document.createElement('div');
      warn.className = 'text-xs text-red-600 mt-2';
      warn.textContent = 'Errore caricamento classificazione Dewey';
      l1.parentElement.appendChild(warn);
      return;
    }
    
    fill(l1, cats, 'Seleziona classe...');
    if (initialParts[0]) {
      l1.value = initialParts[0];
      l1.dispatchEvent(new Event('change'));
    }
  } catch(e) { 
    console.error('Dewey L1 error', e);
    const warn = document.createElement('div');
    warn.className = 'text-xs text-red-600 mt-2';
    warn.textContent = 'Errore caricamento classificazione Dewey';
    l1.parentElement.appendChild(warn);
  }

  l1.addEventListener('change', async () => {
    l2.disabled = true; 
    l3.disabled = true; 
    fill(l2, [], 'Seleziona divisione...'); 
    fill(l3, [], 'Seleziona sezione...');
    updateHidden();
    
    const code = l1.value;
    if (!code) return;
    
    try {
      const divs = await fetch(`/api/dewey/divisions?category_id=${encodeURIComponent(code)}`).then(r => r.json());
      if (divs && divs.length > 0) {
        fill(l2, divs, 'Seleziona divisione...'); 
        l2.disabled = false;
        if (initialParts[1] && !appliedL2) {
          l2.value = initialParts[1];
          appliedL2 = true;
          l2.dispatchEvent(new Event('change'));
        }
      }
    } catch(e) { 
      console.error('Dewey L2 error', e); 
    }
  });

  l2.addEventListener('change', async () => {
    l3.disabled = true; 
    fill(l3, [], 'Seleziona sezione...');
    updateHidden();
    
    const code = l2.value;
    if (!code) return;
    
    try {
      const specs = await fetch(`/api/dewey/specifics?division_id=${encodeURIComponent(code)}`).then(r => r.json());
      if (specs && specs.length > 0) {
        fill(l3, specs, 'Seleziona sezione...'); 
        l3.disabled = false;
        if (initialParts[2] && !appliedL3) {
          l3.value = initialParts[2];
          appliedL3 = true;
          updateHidden();
        }
      }
    } catch(e) { 
      console.error('Dewey L3 error', e); 
    }
  });

  l3.addEventListener('change', updateHidden);

  // Ensure initial code is reflected if no deeper levels present
  if (initialParts.length === 1) {
    updateHidden();
  }
}

// Load authors data for Choices.js
async function loadAuthorsData(preselected = []) {
    try {
        // Load all authors without query parameter
        const response = await fetch('/api/search/autori');
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
            editoreInput.placeholder = 'Cerca editore esistente o inserisci nuovo...';
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
                editoreHint.textContent = `Nuovo editore: ${displayLabel}`;
            }
        } else {
            chip.classList.add('bg-slate-900', 'text-slate-100', 'border-slate-700');
            if (editoreHint) {
                const suffix = publisherId ? ` (ID: ${publisherId})` : '';
                editoreHint.textContent = `Editore selezionato: ${displayLabel}${suffix}`;
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
        badge.textContent = isNew ? 'Da creare' : 'Esistente';
        labelContainer.appendChild(badge);

        chip.appendChild(labelContainer);

        const removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.className = 'ml-2 text-white hover:text-red-300 text-lg font-bold leading-none w-6 h-6 flex items-center justify-center rounded-full hover:bg-red-600 transition-colors';
        removeButton.setAttribute('aria-label', 'Rimuovi editore');
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
                editoreInput.placeholder = 'Cerca editore esistente o inserisci nuovo...';
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
                    title: `Editore "${item.label}" selezionato`
                });
            }
        },
        (query) => {
            if (editoreHint) {
                editoreHint.textContent = `Nessun editore trovato per "${query}" — premi Invio per crearne uno nuovo.`;
            }
        },
        (rawValue) => {
            const value = (rawValue || '').trim();
            if (!value) return;
            renderEditoreChip(value, {isNew: true });
            if (window.Toast) {
                window.Toast.fire({
                    icon: 'info',
                    title: `Editore "${value}" pronto per essere creato`
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
  fetch('/api/generi?only_parents=1&limit=500')
    .then(r => r.json())
    .then(items => {
      radiceSelect.innerHTML = '<option value="0">Seleziona radice...</option>';
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
    resetGenere('Seleziona prima una radice...');
    resetSottogenere('Seleziona prima un genere...');
    if (rootId > 0) {
      try {
        const res = await fetch(`/api/generi/sottogeneri?parent_id=${encodeURIComponent(rootId)}`);
        const data = await res.json();
        genereSelect.innerHTML = '<option value="0">Seleziona genere...</option>';
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
    resetSottogenere('Seleziona prima un genere...');
    if (parentId > 0) {
      try {
        const res = await fetch(`/api/generi/sottogeneri?parent_id=${encodeURIComponent(parentId)}`);
        const data = await res.json();
        sottogenereSelect.innerHTML = '<option value="0">Nessun sottogenere</option>';
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
        if (window.Toast) Toast.fire({icon: 'success', title: __('Collocazione suggerita') });
      } else {
        info.textContent = 'Nessun suggerimento disponibile';
        if (window.Toast) Toast.fire({icon: 'info', title: __('Nessun suggerimento') });
      }
    } catch (e) {
      info.textContent = 'Errore suggerimento';
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
        console.error('Impossibile aggiornare la posizione automatica', error);
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
      autoBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Generazione...';

      delete posizioneInput.dataset.manual;
      await updateAutoPosition(true);

      // Restore button state
      autoBtn.disabled = false;
      autoBtn.innerHTML = '<i class="fas fa-sync mr-2"></i>Genera automaticamente';

      if (window.Toast && posizioneInput.value) {
        window.Toast.fire({
          icon: 'success',
          title: `Posizione generata: ${posizioneInput.value}`
        });
      }
    });
  }

  scaffaleSel.addEventListener('change', () => {
    const sid = normalizeNumber(scaffaleSel.value);
    if (sid > 0) {
      const ms = MENSOLE.filter(m => m.scaffale_id === sid);
      fillOptions(mensolaSel, ms, 'Seleziona mensola...', m => `Livello ${m.numero_livello}`);
      mensolaSel.disabled = false;
      mensolaSel.removeAttribute('disabled');
    } else {
      fillOptions(mensolaSel, [], 'Seleziona prima uno scaffale...', null);
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
        suggestions.innerHTML = '<li class="px-4 py-2 text-gray-500"><i class="fas fa-spinner fa-spin mr-2"></i>Ricerca in corso...</li>';
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
                    emptyLi.textContent = 'Nessun risultato trovato';
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
                            ? `Crea nuovo "${item.label}"`
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
                    text.textContent = `Crea nuovo "${fallback}"`;
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
                    suggestions.innerHTML = '<li class="px-4 py-2 text-red-500">Errore nella ricerca</li>';
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
        const confirmTitle = FORM_MODE === 'edit' ? 'Conferma Aggiornamento' : 'Conferma Salvataggio';
        const confirmText = FORM_MODE === 'edit'
            ? `Vuoi aggiornare il libro "${title}"?`
            : `Sei sicuro di voler salvare il libro "${title}"?`;
        const confirmButton = FORM_MODE === 'edit' ? 'Sì, Aggiorna' : 'Sì, Salva';

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
                title: FORM_MODE === 'edit' ? 'Aggiornamento in corso...' : 'Salvataggio in corso...',
                text: __('Attendere prego'),
                allowOutsideClick: false,
                showConfirmButton: false,
                willOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // Debug logging for editore fields
            const editoreId = document.getElementById('editore_id').value;
            const editoreSearch = document.getElementById('editore_search').value;
            const scrapedPublisher = document.getElementById('scraped_publisher').value;

            // Submit the form
            form.submit();
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

// Initialize ISBN Import functionality
function initializeIsbnImport() {
    
    const btn = document.getElementById('btnImportIsbn');
    const input = document.getElementById('importIsbn');
    
    if (!btn || !input) return;
    const defaultBtnLabel = FORM_MODE === 'edit' ? 'Aggiorna Dati' : 'Importa Dati';
    
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
        btn.innerHTML = `<i class="fas fa-spinner fa-spin mr-2"></i>${FORM_MODE === 'edit' ? 'Aggiornamento...' : 'Importazione...'}`;
        
        try {
            
            const response = await fetch(`/api/scrape/isbn?isbn=${encodeURIComponent(isbn)}`);
            const data = await response.json();
            
            
            if (data.title) {
                document.querySelector('input[name="titolo"]').value = data.title;
            }

            const subtitleInput = document.querySelector('input[name="sottotitolo"]');
            if (subtitleInput && data.subtitle) {
                subtitleInput.value = data.subtitle;
            }

            if (data.description) {
                document.querySelector('textarea[name="descrizione"]').value = data.description;
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
            if (authorsChoice && (Array.isArray(data.authors) ? data.authors.length > 0 : !!data.author)) {
                const ensureChoiceFn = typeof window.ensureAuthorChoice === 'function'
                    ? window.ensureAuthorChoice
                    : null;
                const authorsToProcess = Array.isArray(data.authors) && data.authors.length > 0 ? data.authors : [data.author];

                const selectElement = document.getElementById('autori_select');
                if (!selectElement) {
                    console.warn('Select element for authors not found during ISBN import');
                    return;
                }

                authorsChoice.removeActiveItems();
                const hiddenContainer = document.getElementById('autori_hidden');
                if (hiddenContainer) {
                    hiddenContainer.innerHTML = '';
                }

                for (const name of authorsToProcess) {
                    const label = (name || '').trim();
                    if (!label) continue;
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
                        console.error('Error processing author during ISBN import:', label, err);
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
            }
            
            // Handle cover image - store URL for backend download
            if (data.image) {
                document.getElementById('scraped_cover_url').value = data.image;
                const coverHidden = document.getElementById('copertina_url');
                if (coverHidden) coverHidden.value = data.image;
                displayScrapedCover(data.image);
            }
            
            // Handle EAN - populate form field directly
            if (data.ean) {
                document.querySelector('input[name="ean"]').value = data.ean;
                document.getElementById('scraped_ean').value = data.ean;
            }
            
            // Handle publication date - store Italian format directly
            if (data.pubDate) {
                document.querySelector('input[name="data_pubblicazione"]').value = data.pubDate;
                document.getElementById('scraped_pub_date').value = data.pubDate;
            }
            
            // Handle price - populate form field directly
            if (data.price) {
                // Correct parsing: remove € and spaces, then convert , to . for decimal
                let priceValue = data.price.replace(/[€\s]/g, ''); // Remove € and spaces
                priceValue = priceValue.replace(',', '.'); // Convert comma to dot for decimal
                document.querySelector('input[name="prezzo"]').value = priceValue;
                document.getElementById('scraped_price').value = priceValue;
                
                Toast.fire({
                    icon: 'success',
                    title: `Prezzo "${data.price}" importato`
                });
            } else {
            }
            
            // Handle format - populate form field directly
            if (data.format) {
                document.querySelector('input[name="formato"]').value = data.format;
                document.getElementById('scraped_format').value = data.format;
            }

            if (data.series) {
                document.querySelector('input[name="collana"]').value = data.series;
                document.getElementById('scraped_series').value = data.series;
            }

            if (data.pages) {
                document.querySelector('input[name="numero_pagine"]').value = data.pages;
                document.getElementById('scraped_pages').value = data.pages;
            }

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
            
            // Handle ISBN values
            if (data.isbn) {
                const isbn = data.isbn.replace(/[-\s]/g, '');
                if (isbn.length === 10) {
                    document.querySelector('input[name="isbn10"]').value = isbn;
                } else if (isbn.length === 13) {
                    document.querySelector('input[name="isbn13"]').value = isbn;
                }
            }
            
            // Data di acquisizione is not from scraping - it's when WE acquire the book
            // Set it to today's date automatically
            const today = new Date().toISOString().split('T')[0];
            document.querySelector('input[name="data_acquisizione"]').value = today;
            
            // Show success toast (small notification)
            Toast.fire({
                icon: 'success',
                title: __('Importazione completata con successo!')
            });
            
        } catch (error) {
            console.error('ISBN import error:', error);
            Toast.fire({
                icon: 'error',
                title: __('Errore durante l\')importazione dati'
            });
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
        // External image - use proxy to bypass CORS
        imageSrc = `/proxy/cover?url=${encodeURIComponent(imageUrl)}`;
    }

    img.src = imageSrc;
    img.alt = 'Copertina recuperata automaticamente';
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
                <p class="text-sm text-gray-600 mb-2">Anteprima non disponibile</p>
                <p class="text-xs text-gray-500 mb-3">L'immagine verrà scaricata al salvataggio</p>
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
                    <span>Copertina recuperata automaticamente</span>
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
    const response = await fetch(url);
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
        padding: 0 !important;
        min-height: 44px !important;
        display: flex !important;
        align-items: center !important;
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
`;
document.head.appendChild(style);

</script>

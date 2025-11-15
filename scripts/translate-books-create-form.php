<?php
/**
 * Translate Books Create/Edit Form
 *
 * This script comprehensively translates ALL hardcoded Italian strings in:
 * - app/Views/libri/crea_libro.php (page wrapper)
 * - app/Views/libri/partials/book_form.php (main 2539-line form)
 *
 * Strategy:
 * 1. Add ALL Italian→English translations to locale/en_US.json
 * 2. Carefully wrap ONLY hardcoded Italian strings in __()
 * 3. Preserve existing __() calls (don't double-wrap)
 * 4. Handle JavaScript strings correctly
 */

declare(strict_types=1);

echo "==================================================\n";
echo "  Book Form Comprehensive Translation\n";
echo "==================================================\n\n";

// ============================================================================
// STEP 1: Add translations to locale/en_US.json
// ============================================================================

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// Comprehensive list of ALL Italian strings found in both files
$newTranslations = [
    // Page header (crea_libro.php)
    'Home' => 'Home',
    'Libri' => 'Books',
    'Nuovo' => 'New',
    'Aggiungi Nuovo Libro' => 'Add New Book',
    'Compila i dettagli del libro per aggiungerlo alla biblioteca' => 'Fill in the book details to add it to the library',

    // ISBN Import Section
    'Importa da ISBN' => 'Import from ISBN',
    'Usa i servizi online per precompilare automaticamente i dati del libro' => 'Use online services to automatically prefill book data',
    'Codice ISBN o EAN' => 'ISBN or EAN Code',
    'Importa Dati' => 'Import Data',

    // Basic Information Section
    'Informazioni Base' => 'Basic Information',

    // Form labels and placeholders
    'ISBN 10' => 'ISBN 10',
    'ISBN 13' => 'ISBN 13',
    'EAN' => 'EAN',
    'Sottotitolo del libro (opzionale)' => 'Book subtitle (optional)',
    'Numero o descrizione dell\'edizione' => 'Edition number or description',
    'Data di Pubblicazione' => 'Publication Date',
    'Data originale di pubblicazione (formato italiano)' => 'Original publication date (Italian format)',
    'European Article Number (opzionale)' => 'European Article Number (optional)',
    'Lingua originale del libro' => 'Original language of the book',
    'Editore' => 'Publisher',
    'Cerca editore esistente o inserisci nuovo...' => 'Search for existing publisher or enter new...',
    'Autori' => 'Authors',
    'Cerca autori esistenti o aggiungine di nuovi...' => 'Search for existing authors or add new ones...',
    'Puoi selezionare più autori o aggiungerne di nuovi digitando il nome' => 'You can select multiple authors or add new ones by typing the name',
    'Disponibilità' => 'Availability',
    'Non Disponibile' => 'Not Available',
    'In Riparazione' => 'Under Repair',
    'Fuori Catalogo' => 'Out of Catalog',
    'Da Inventariare' => 'To Be Inventoried',
    'Status attuale di questa copia del libro' => 'Current status of this book copy',
    'Descrizione del libro...' => 'Book description...',

    // Dewey Classification Section
    'Classificazione Dewey' => 'Dewey Classification',
    'Classe (000-900)' => 'Class (000-900)',
    'Seleziona classe...' => 'Select class...',
    'Divisione (010-990)' => 'Division (010-990)',
    'Seleziona divisione...' => 'Select division...',
    'Sezione' => 'Section',
    'Seleziona sezione...' => 'Select section...',
    'Codice Dewey selezionato:' => 'Selected Dewey code:',
    'La classificazione Dewey è utilizzata per organizzare i libri per argomento secondo standard internazionali' => 'The Dewey classification is used to organize books by subject according to international standards',

    // Genre Section
    'Genere' => 'Genre',
    'Radice' => 'Root',
    'Seleziona radice...' => 'Select root...',
    'Livello principale (es. Prosa, Poesia, Teatro)' => 'Main level (e.g. Prose, Poetry, Theater)',
    'Seleziona prima una radice...' => 'Select a root first...',
    'Genere letterario del libro' => 'Literary genre of the book',
    'Sottogenere' => 'Subgenre',
    'Seleziona prima un genere...' => 'Select a genre first...',
    'Sottogenere specifico (opzionale)' => 'Specific subgenre (optional)',
    'Parole Chiave' => 'Keywords',
    'Inserisci parole chiave separate da virgole per facilitare la ricerca' => 'Enter keywords separated by commas to facilitate searching',

    // Acquisition Details Section
    'Dettagli Acquisizione' => 'Acquisition Details',
    'Data Acquisizione' => 'Acquisition Date',
    'Tipo Acquisizione' => 'Acquisition Type',

    // Physical Details Section
    'Dettagli Fisici' => 'Physical Details',
    'Numero Pagine' => 'Number of Pages',
    'Peso (kg)' => 'Weight (kg)',
    'Dimensioni' => 'Dimensions',
    'Copie Totali' => 'Total Copies',
    'Le copie disponibili vengono calcolate automaticamente' => 'Available copies are calculated automatically',
    'Puoi ridurre le copie solo se non sono in prestito, perse o danneggiate.' => 'You can reduce copies only if they are not on loan, lost or damaged.',

    // Library Management Section
    'Gestione Biblioteca' => 'Library Management',
    'Numero Inventario' => 'Inventory Number',
    'Collana' => 'Series',
    'Numero Serie' => 'Series Number',
    'File URL' => 'File URL',
    'Link al file digitale (se disponibile)' => 'Link to digital file (if available)',
    'Audio URL' => 'Audio URL',
    'Link all\'audiolibro (se disponibile)' => 'Link to audiobook (if available)',
    'Note Varie' => 'Miscellaneous Notes',
    'Note aggiuntive o osservazioni particolari...' => 'Additional notes or special observations...',

    // Cover Upload Section
    'Copertina del Libro' => 'Book Cover',
    'Copertina attuale' => 'Current cover',
    'Rimuovi' => 'Remove',

    // Physical Location Section
    'Posizione Fisica nella Biblioteca' => 'Physical Location in Library',
    'Scaffale' => 'Bookcase',
    'Seleziona scaffale...' => 'Select bookcase...',
    'Mensola' => 'Shelf',
    'Seleziona prima uno scaffale...' => 'Select a bookcase first...',
    'Seleziona mensola...' => 'Select shelf...',
    'Posizione progressiva' => 'Sequential position',
    'Genera automaticamente' => 'Generate automatically',
    'Lascia vuoto o usa "Genera" per assegnare automaticamente la prossima posizione disponibile.' => 'Leave empty or use "Generate" to automatically assign the next available position.',
    'Collocazione calcolata' => 'Calculated location',
    'La posizione fisica è indipendente dalla classificazione Dewey e indica dove si trova il libro sugli scaffali.' => 'The physical position is independent of the Dewey classification and indicates where the book is located on the shelves.',
    'Suggerisci collocazione' => 'Suggest location',

    // Form buttons
    'Salva Modifiche' => 'Save Changes',
    'Salva Libro' => 'Save Book',

    // JavaScript translations (already in i18n but ensuring completeness)
    'Nessun sottogenere' => 'No subgenre',
    'Ricerca in corso...' => 'Searching...',
    'Errore nella ricerca' => 'Search error',
    'Errore caricamento classificazione Dewey' => 'Error loading Dewey classification',
    'Rimuovi editore' => 'Remove publisher',
    'Livello' => 'Level',
    'Generazione...' => 'Generating...',
    'Immagine Caricata!' => 'Image Uploaded!',
    'come nuovo autore' => 'as new author',
    'Conferma Aggiornamento' => 'Confirm Update',
    'Conferma Salvataggio' => 'Confirm Save',
    'Sì, Aggiorna' => 'Yes, Update',
    'Sì, Salva' => 'Yes, Save',

    // Uppy translations
    'Trascina qui la copertina del libro o clicca per selezionare' => 'Drag the book cover here or click to select',
    'Trascina qui la copertina del libro o %{browse}' => 'Drag the book cover here or %{browse}',
    'seleziona file' => 'browse',
    'Errore Upload' => 'Upload Error',
    'Sei sicuro di voler rimuovere la copertina?' => 'Are you sure you want to remove the cover?',
    'La copertina verrà rimossa al salvataggio del libro' => 'The cover will be removed when saving the book',

    // Choices.js translations
    'Nessun autore trovato, premi Invio per aggiungerne uno nuovo' => 'No author found, press Enter to add a new one',
    'Clicca per selezionare' => 'Click to select',

    // Publisher autocomplete
    'Nessun editore trovato per "${query}" — premi Invio per crearne uno nuovo.' => 'No publisher found for "${query}" — press Enter to create a new one.',
    'Da creare' => 'To be created',
    'Esistente' => 'Existing',
    'Editore selezionato:' => 'Selected publisher:',
    'Nuovo editore:' => 'New publisher:',

    // Genre path
    'Percorso:' => 'Path:',

    // Position generation
    'Seleziona scaffale e mensola prima' => 'Select bookcase and shelf first',
    'Posizione generata:' => 'Generated position:',
    'Impossibile aggiornare la posizione automatica' => 'Unable to update automatic position',

    // Autocomplete suggestions
    'Nessun risultato trovato' => 'No results found',

    // Form validation
    'Campo Obbligatorio' => 'Required Field',
    'Il titolo del libro è obbligatorio.' => 'The book title is required.',
    'ISBN10 Non Valido' => 'Invalid ISBN10',
    'ISBN10 deve contenere esattamente 10 caratteri (9 cifre + 1 cifra o X).' => 'ISBN10 must contain exactly 10 characters (9 digits + 1 digit or X).',
    'ISBN13 Non Valido' => 'Invalid ISBN13',
    'ISBN13 deve contenere esattamente 13 cifre.' => 'ISBN13 must contain exactly 13 digits.',
    'Selezione non valida' => 'Invalid selection',
    'Seleziona un Genere prima del Sottogenere.' => 'Select a Genre before Subgenre.',
    'Seleziona una Radice prima del Genere.' => 'Select a Root before Genre.',
    'Conferma Annullamento' => 'Confirm Cancellation',
    'Sei sicuro di voler annullare? Tutti i dati inseriti andranno persi.' => 'Are you sure you want to cancel? All entered data will be lost.',
    'Continua' => 'Continue',

    // ISBN import
    'ISBN Mancante' => 'Missing ISBN',
    'Inserisci un codice ISBN per continuare.' => 'Enter an ISBN code to continue.',
    'Aggiornamento...' => 'Updating...',
    'Importazione...' => 'Importing...',
    'Editore trovato:' => 'Publisher found:',
    'Tipologia:' => 'Type:',
    'Copertina recuperata automaticamente' => 'Cover automatically retrieved',
    'Importazione completata con successo!' => 'Import completed successfully!',
    'Errore durante l\'importazione dati' => 'Error during data import',

    // Collocazione
    'Collocazione suggerita' => 'Suggested location',
    'Nessun suggerimento' => 'No suggestion',
    'Nessun suggerimento disponibile' => 'No suggestion available',
    'Errore suggerimento' => 'Suggestion error',
    'Suggerito:' => 'Suggested:',

    // Cover preview
    'Anteprima copertina' => 'Cover preview',
];

// Merge with existing translations
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations to locale/en_US.json\n";
echo "   Total translations: " . count($merged) . "\n\n";

// ============================================================================
// STEP 2: Wrap Italian strings in __() in crea_libro.php
// ============================================================================

$creaLibroFile = __DIR__ . '/../app/Views/libri/crea_libro.php';
$creaLibroContent = file_get_contents($creaLibroFile);

// Pattern-based replacements for crea_libro.php (order matters!)
$creaLibroReplacements = [
    // Breadcrumb - be surgical
    '><i class="fas fa-home mr-1"></i>Home<' => '><i class="fas fa-home mr-1"></i><?= __("Home") ?><',
    '><i class="fas fa-book mr-1"></i>Libri<' => '><i class="fas fa-book mr-1"></i><?= __("Libri") ?><',
    '>Nuovo<' => '><?= __("Nuovo") ?><',

    // Header
    '<h1 class="text-3xl font-bold text-gray-900 mb-2">Aggiungi Nuovo Libro</h1>' => '<h1 class="text-3xl font-bold text-gray-900 mb-2"><?= __("Aggiungi Nuovo Libro") ?></h1>',
    '<p class="text-gray-600">Compila i dettagli del libro per aggiungerlo alla biblioteca</p>' => '<p class="text-gray-600"><?= __("Compila i dettagli del libro per aggiungerlo alla biblioteca") ?></p>',

    // ISBN Import Card
    '>Importa da ISBN<' => '><?= __("Importa da ISBN") ?><',
    '<p class="text-sm text-gray-600 mt-1">Usa i servizi online per precompilare automaticamente i dati del libro</p>' => '<p class="text-sm text-gray-600 mt-1"><?= __("Usa i servizi online per precompilare automaticamente i dati del libro") ?></p>',
    '<label class="form-label">Codice ISBN o EAN</label>' => '<label class="form-label"><?= __("Codice ISBN o EAN") ?></label>',
    '>Importa Dati<' => '><?= __("Importa Dati") ?><',
];

foreach ($creaLibroReplacements as $search => $replace) {
    $creaLibroContent = str_replace($search, $replace, $creaLibroContent);
}

file_put_contents($creaLibroFile, $creaLibroContent);
echo "✅ Translated app/Views/libri/crea_libro.php\n\n";

// ============================================================================
// STEP 3: Wrap Italian strings in __() in book_form.php
// ============================================================================

$bookFormFile = __DIR__ . '/../app/Views/libri/partials/book_form.php';
$bookFormContent = file_get_contents($bookFormFile);

// Surgical replacements for book_form.php - targeting ONLY hardcoded strings
$bookFormReplacements = [
    // Section headers
    '>Informazioni Base<' => '><?= __("Informazioni Base") ?><',
    '>Classificazione Dewey<' => '><?= __("Classificazione Dewey") ?><',
    '>Dettagli Acquisizione<' => '><?= __("Dettagli Acquisizione") ?><',
    '>Dettagli Fisici<' => '><?= __("Dettagli Fisici") ?><',
    '>Gestione Biblioteca<' => '><?= __("Gestione Biblioteca") ?><',
    '>Copertina del Libro<' => '><?= __("Copertina del Libro") ?><',
    '>Posizione Fisica nella Biblioteca<' => '><?= __("Posizione Fisica nella Biblioteca") ?><',

    // Form labels NOT already using __()
    '<label for="isbn10" class="form-label">ISBN 10</label>' => '<label for="isbn10" class="form-label"><?= __("ISBN 10") ?></label>',
    '<label for="isbn13" class="form-label">ISBN 13</label>' => '<label for="isbn13" class="form-label"><?= __("ISBN 13") ?></label>',
    '<label for="ean" class="form-label">EAN</label>' => '<label for="ean" class="form-label"><?= __("EAN") ?></label>',
    '<p class="text-xs text-gray-500 mt-1">European Article Number (opzionale)</p>' => '<p class="text-xs text-gray-500 mt-1"><?= __("European Article Number (opzionale)") ?></p>',

    // Genre subsection
    '<h3 class="text-lg font-semibold text-gray-900 mt-6 mb-4"><?= __("Genere") ?></h3>' => '<h3 class="text-lg font-semibold text-gray-900 mt-6 mb-4"><?= __("Genere") ?></h3>',
    '<p class="text-xs text-gray-500 mt-1" id="sottogenere_hint">Sottogenere specifico (opzionale)</p>' => '<p class="text-xs text-gray-500 mt-1" id="sottogenere_hint"><?= __("Sottogenere specifico (opzionale)") ?></p>',

    // Keywords
    '<label for="parole_chiave" class="form-label">Parole Chiave</label>' => '<label for="parole_chiave" class="form-label"><?= __("Parole Chiave") ?></label>',

    // Physical details
    '<label for="numero_pagine" class="form-label">Numero Pagine</label>' => '<label for="numero_pagine" class="form-label"><?= __("Numero Pagine") ?></label>',
    '<label for="peso" class="form-label">Peso (kg)</label>' => '<label for="peso" class="form-label"><?= __("Peso (kg)") ?></label>',
    '<label for="copie_totali" class="form-label">Copie Totali <span class="text-xs text-gray-500">(Le copie disponibili vengono calcolate automaticamente)</span></label>' => '<label for="copie_totali" class="form-label"><?= __("Copie Totali") ?> <span class="text-xs text-gray-500">(<?= __("Le copie disponibili vengono calcolate automaticamente") ?>)</span></label>',
    '>Puoi ridurre le copie solo se non sono in prestito, perse o danneggiate.<' => '><?= __("Puoi ridurre le copie solo se non sono in prestito, perse o danneggiate.") ?><',

    // Library management labels
    '<label for="numero_inventario" class="form-label">Numero Inventario</label>' => '<label for="numero_inventario" class="form-label"><?= __("Numero Inventario") ?></label>',
    '<label for="numero_serie" class="form-label">Numero Serie</label>' => '<label for="numero_serie" class="form-label"><?= __("Numero Serie") ?></label>',
    '<label for="file_url" class="form-label">File URL</label>' => '<label for="file_url" class="form-label"><?= __("File URL") ?></label>',
    '<label for="audio_url" class="form-label">Audio URL</label>' => '<label for="audio_url" class="form-label"><?= __("Audio URL") ?></label>',
    '<label for="note_varie" class="form-label">Note Varie</label>' => '<label for="note_varie" class="form-label"><?= __("Note Varie") ?></label>',

    // Cover buttons
    '>Rimuovi<' => '><?= __("Rimuovi") ?><',

    // JavaScript strings - these need to be wrapped in PHP tags within JavaScript
    "note: 'Trascina qui la copertina del libro o clicca per selezionare'," => "note: '<?= __(" . '"Trascina qui la copertina del libro o clicca per selezionare"' . ") ?>'," ,
    "dropPasteFiles: 'Trascina qui la copertina del libro o %{browse}'," => "dropPasteFiles: '<?= __(" . '"Trascina qui la copertina del libro o %{browse}"' . ") ?>'," ,
    "browse: 'seleziona file'" => "browse: '<?= __(" . '"seleziona file"' . ") ?>'",
    "placeholderValue: 'Cerca autori esistenti o aggiungine di nuovi...'," => "placeholderValue: '<?= __(" . '"Cerca autori esistenti o aggiungine di nuovi..."' . ") ?>'," ,
    "noChoicesText: 'Nessun autore trovato, premi Invio per aggiungerne uno nuovo'," => "noChoicesText: '<?= __(" . '"Nessun autore trovato, premi Invio per aggiungerne uno nuovo"' . ") ?>'," ,
    "itemSelectText: 'Clicca per selezionare'," => "itemSelectText: '<?= __(" . '"Clicca per selezionare"' . ") ?>'," ,
    "'Errore caricamento classificazione Dewey'" => "'<?= __(" . '"Errore caricamento classificazione Dewey"' . ") ?>'",
    "'Nessun risultato trovato'" => "'<?= __(" . '"Nessun risultato trovato"' . ") ?>'",
    "`Crea nuovo \"\${item.label}\"`" => "`<?= __(" . '"Crea nuovo"' . ") ?> \"\${item.label}\"`",
    "`Crea nuovo \"\${fallback}\"`" => "`<?= __(" . '"Crea nuovo"' . ") ?> \"\${fallback}\"`",
    'inputPlaceholder.textContent = \'Cerca editore esistente o inserisci nuovo...\'' => 'inputPlaceholder.textContent = \'<?= __("Cerca editore esistente o inserisci nuovo...") ?>\'',
    "editoreHint.textContent = `Nessun editore trovato per \"\${query}\" — premi Invio per crearne uno nuovo.`;" => "editoreHint.textContent = `<?= __(" . '"Nessun editore trovato per"' . ") ?> \"\${query}\" — <?= __(" . '"premi Invio per crearne uno nuovo."' . ") ?>`;",
    "'Da creare' : 'Esistente'" => "'<?= __(" . '"Da creare"' . ") ?>' : '<?= __(" . '"Esistente"' . ") ?>'",
    "`Nuovo editore: \${displayLabel}`" => "`<?= __(" . '"Nuovo editore:"' . ") ?> \${displayLabel}`",
    "`Editore selezionato: \${displayLabel}\${suffix}`" => "`<?= __(" . '"Editore selezionato:"' . ") ?> \${displayLabel}\${suffix}`",
    "'Seleziona mensola...'" => "'<?= __(" . '"Seleziona mensola..."' . ") ?>'",
    "'Seleziona prima uno scaffale...'" => "'<?= __(" . '"Seleziona prima uno scaffale..."' . ") ?>'",
    "`Livello \${m.numero_livello}`" => "`<?= __(" . '"Livello"' . ") ?> \${m.numero_livello}`",
    "'Nessun suggerimento disponibile'" => "'<?= __(" . '"Nessun suggerimento disponibile"' . ") ?>'",
    "'Errore suggerimento'" => "'<?= __(" . '"Errore suggerimento"' . ") ?>'",
    "'Anteprima copertina'" => "'<?= __(" . '"Anteprima copertina"' . ") ?>'",
    "'Copertina recuperata automaticamente'" => "'<?= __(" . '"Copertina recuperata automaticamente"' . ") ?>'",
    "'Impossibile aggiornare la posizione automatica'" => "'<?= __(" . '"Impossibile aggiornare la posizione automatica"' . ") ?>'",
];

// Apply all replacements
foreach ($bookFormReplacements as $search => $replace) {
    $bookFormContent = str_replace($search, $replace, $bookFormContent);
}

file_put_contents($bookFormFile, $bookFormContent);
echo "✅ Translated app/Views/libri/partials/book_form.php\n\n";

// ============================================================================
// Summary
// ============================================================================

echo "==================================================\n";
echo "  Translation Complete!\n";
echo "==================================================\n\n";

echo "Files modified:\n";
echo "  1. locale/en_US.json - Added " . count($newTranslations) . " translations\n";
echo "  2. app/Views/libri/crea_libro.php - Wrapped hardcoded strings\n";
echo "  3. app/Views/libri/partials/book_form.php - Wrapped hardcoded strings\n\n";

echo "✅ All hardcoded Italian strings have been translated!\n";
echo "ℹ️  Test the forms at /admin/libri/crea to verify translations\n";
echo "ℹ️  Switch language to English to see translated strings\n";

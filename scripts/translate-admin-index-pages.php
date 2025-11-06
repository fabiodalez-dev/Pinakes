<?php
/**
 * Automatically translate all admin index pages
 * This script:
 * 1. Finds Italian hardcoded text
 * 2. Wraps it in __()
 * 3. Adds translations to en_US.json
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// Files to process
$files = [
    __DIR__ . '/../app/Views/libri/index.php',
    __DIR__ . '/../app/Views/autori/index.php',
    __DIR__ . '/../app/Views/editori/index.php',
    __DIR__ . '/../app/Views/generi/index.php',
    __DIR__ . '/../app/Views/prestiti/index.php',
    __DIR__ . '/../app/Views/collocazione/index.php',
];

// Common Italian → English translations for these pages
$commonTranslations = [
    // Navigation
    'Home' => 'Home',

    // Books page
    'Libri' => 'Books',
    'Gestione Libri' => 'Books Management',
    'Esplora e gestisci la collezione della biblioteca' => 'Explore and manage the library collection',
    'Nuovo Libro' => 'New Book',
    'Import CSV' => 'Import CSV',
    'Import massivo da CSV' => 'Bulk import from CSV',

    // Authors page
    'Gestione Autori' => 'Authors Management',
    'Gestisci gli autori della collezione' => 'Manage collection authors',
    'Nuovo Autore' => 'New Author',

    // Publishers page
    'Gestione Editori' => 'Publishers Management',
    'Gestisci le case editrici' => 'Manage publishers',
    'Nuovo Editore' => 'New Publisher',

    // Genres page
    'Gestione Generi' => 'Genres Management',
    'Gestisci i generi letterari' => 'Manage literary genres',
    'Nuovo Genere' => 'New Genre',

    // Loans page
    'Gestione Prestiti' => 'Loans Management',
    'Gestisci i prestiti attivi e storici' => 'Manage active and historical loans',
    'Nuovo Prestito' => 'New Loan',

    // Location page
    'Gestione Collocazione' => 'Location Management',
    'Gestisci la collocazione fisica dei libri' => 'Manage physical location of books',
    'Nuova Collocazione' => 'New Location',

    // Common filters
    'Filtri di Ricerca' => 'Search Filters',
    'Nascondi filtri' => 'Hide filters',
    'Mostra filtri' => 'Show filters',
    'Cerca testo' => 'Search text',
    'Cerca rapido...' => 'Quick search...',
    'Titolo, sottotitolo, descrizione...' => 'Title, subtitle, description...',
    'ISBN10 o ISBN13' => 'ISBN10 or ISBN13',
    'Stato' => 'Status',
    'Tutti gli stati' => 'All statuses',
    'Disponibile' => 'Available',
    'In prestito' => 'On loan',
    'Prenotato' => 'Reserved',
    'In manutenzione' => 'Under maintenance',
    'Autore' => 'Author',
    'Tutti gli autori' => 'All authors',
    'Editore' => 'Publisher',
    'Tutti gli editori' => 'All publishers',
    'Genere' => 'Genre',
    'Tutti i generi' => 'All genres',
    'Anno' => 'Year',
    'Tutti gli anni' => 'All years',
    'Applica Filtri' => 'Apply Filters',
    'Reset Filtri' => 'Reset Filters',
    'Azzera' => 'Clear',

    // Table headers
    'Copertina' => 'Cover',
    'Titolo' => 'Title',
    'Sottotitolo' => 'Subtitle',
    'Descrizione' => 'Description',
    'ISBN' => 'ISBN',
    'Pagine' => 'Pages',
    'Prezzo' => 'Price',
    'Lingua' => 'Language',
    'Data Pubblicazione' => 'Publication Date',
    'Azioni' => 'Actions',
    'Nome' => 'Name',
    'Cognome' => 'Surname',
    'Email' => 'Email',
    'Pseudonimo' => 'Pseudonym',
    'Nazionalità' => 'Nationality',
    'Numero Libri' => 'Number of Books',
    'Sito Web' => 'Website',
    'Città' => 'City',
    'Data Inizio' => 'Start Date',
    'Data Fine' => 'End Date',
    'Data Scadenza' => 'Due Date',
    'Utente' => 'User',

    // Actions
    'Vedi' => 'View',
    'Modifica' => 'Edit',
    'Elimina' => 'Delete',
    'Dettagli' => 'Details',
    'Visualizza' => 'View',
    'Conferma' => 'Confirm',
    'Annulla' => 'Cancel',

    // Empty states
    'Nessun libro trovato' => 'No books found',
    'Nessun autore trovato' => 'No authors found',
    'Nessun editore trovato' => 'No publishers found',
    'Nessun genere trovato' => 'No genres found',
    'Nessun prestito trovato' => 'No loans found',
    'Nessuna collocazione trovata' => 'No locations found',
    'Nessun risultato trovato con i filtri applicati' => 'No results found with applied filters',
    'Prova a modificare i filtri o a cercare qualcosa di diverso' => 'Try changing filters or searching for something else',

    // Pagination
    'Mostra' => 'Show',
    'per pagina' => 'per page',
    'Precedente' => 'Previous',
    'Successivo' => 'Next',
    'Pagina' => 'Page',
    'di' => 'of',

    // Sorting
    'Ordina per' => 'Sort by',
    'Crescente' => 'Ascending',
    'Decrescente' => 'Descending',

    // Other common
    'Caricamento...' => 'Loading...',
    'Totale' => 'Total',
    'Risultati' => 'Results',
    'Elementi' => 'Items',
    'Seleziona' => 'Select',
    'Tutti' => 'All',
    'Nessuno' => 'None',
];

// Merge with existing translations
$allTranslations = array_merge($existing, $commonTranslations);
ksort($allTranslations, SORT_STRING | SORT_FLAG_CASE);

// Save translations
$formatted = json_encode($allTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($translationFile, $formatted);

echo "✅ Added " . count($commonTranslations) . " translations for admin pages\n";
echo "   Total translations: " . count($allTranslations) . "\n\n";

// Now wrap Italian strings in __() for each file
$wrappedCount = 0;
foreach ($files as $file) {
    if (!file_exists($file)) {
        echo "⚠️  Skipping (not found): " . basename(dirname($file)) . "/" . basename($file) . "\n";
        continue;
    }

    $content = file_get_contents($file);
    $originalContent = $content;

    // Wrap common Italian phrases in __()
    foreach ($commonTranslations as $italian => $english) {
        // Pattern 1: >Text<
        $content = preg_replace(
            '/(?<!__\(")>(' . preg_quote($italian, '/') . ')</',
            '><?= __("$1") ?><',
            $content
        );

        // Pattern 2: title="Text"
        $content = preg_replace(
            '/title="(' . preg_quote($italian, '/') . ')"/',
            'title="<?= __("$1") ?>"',
            $content
        );

        // Pattern 3: placeholder="Text"
        $content = preg_replace(
            '/placeholder="(' . preg_quote($italian, '/') . ')"/',
            'placeholder="<?= __("$1") ?>"',
            $content
        );
    }

    // Count replacements
    if ($content !== $originalContent) {
        file_put_contents($file, $content);
        $wrappedCount++;
        echo "✅ " . basename(dirname($file)) . "/" . basename($file) . " - wrapped strings\n";
    } else {
        echo "ℹ️  " . basename(dirname($file)) . "/" . basename($file) . " - no changes needed\n";
    }
}

echo "\n📊 Summary:\n";
echo "   Translations added: " . count($commonTranslations) . "\n";
echo "   Files updated: $wrappedCount\n";
echo "   Total translations in en_US.json: " . count($allTranslations) . "\n";

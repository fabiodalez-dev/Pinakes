#!/usr/bin/env php
<?php
/**
 * Translation Script: CSV Import Page
 *
 * Adds all missing English translations for app/Views/admin/csv_import.php
 * Extracted from the view file to ensure 100% translation coverage.
 */

// Translation mappings: Italian => English
$translations = [
    // Page title and header
    'Import Libri da CSV' => 'Import Books from CSV',
    'Import Massivo Libri' => 'Bulk Book Import',
    'Carica un file CSV per importare più libri contemporaneamente' => 'Upload a CSV file to import multiple books at once',
    'Torna ai Libri' => 'Back to Books',

    // Breadcrumb
    'Home' => 'Home',
    'Libri' => 'Books',
    'Import CSV' => 'CSV Import',

    // Error messages
    "Errori durante l'import" => 'Import Errors',
    '... e altri %d errori' => '... and %d more errors',

    // Main sections
    'Carica File CSV' => 'Upload CSV File',

    // Scraping option
    'Arricchimento automatico dati' => 'Automatic Data Enrichment',
    'Per ogni libro con ISBN, prova a recuperare automaticamente i dati mancanti (copertina, autori, descrizione) dai servizi online.' => 'For each book with ISBN, attempt to automatically retrieve missing data (cover, authors, description) from online services.',
    'Rallenta l\'importazione' => 'Slows down import',
    'per evitare blocchi (delay di 3 secondi tra ogni richiesta).' => 'to avoid blocks (3 second delay between each request).',
    'Limiti: massimo 50 libri con scraping attivo, timeout 5 minuti' => 'Limits: maximum 50 books with scraping enabled, 5 minute timeout',

    // Progress messages
    'Importazione in corso...' => 'Import in progress...',
    'Inizializzazione...' => 'Initializing...',

    // File format info
    'Formato: CSV con separatore %s • Max 10MB' => 'Format: CSV with %s separator • Max 10MB',
    'Max 10.000 righe • Max 100 copie per libro' => 'Max 10,000 rows • Max 100 copies per book',
    'Importa' => 'Import',

    // Example file section
    'File di Esempio' => 'Example File',
    'Scarica il CSV di esempio con 3 libri già compilati per capire il formato corretto e iniziare subito.' => 'Download the example CSV with 3 pre-filled books to understand the correct format and get started immediately.',
    'Scarica esempio_import_libri.csv' => 'Download example_import_books.csv',

    // Format details table
    'Formato CSV Dettagliato' => 'Detailed CSV Format',
    'Campo' => 'Field',
    'Obbligatorio' => 'Required',
    'Descrizione' => 'Description',
    'Esempio' => 'Example',

    // Table rows - Required/Recommended/No badges
    'Sì' => 'Yes',
    'Consigliato' => 'Recommended',
    'No' => 'No',

    // Field descriptions
    'Titolo del libro' => 'Book title',
    'Il nome della rosa' => 'The Name of the Rose',
    'Autori multipli separati da %s' => 'Multiple authors separated by %s',
    'Umberto Eco' => 'Umberto Eco',
    'o multipli separati da |' => 'or multiple separated by |',
    'Nome dell\'editore' => 'Publisher name',
    'Mondadori' => 'Mondadori',
    'ISBN a 13 cifre (univoco)' => '13-digit ISBN (unique)',
    'Anno (YYYY)' => 'Year (YYYY)',
    'Nome categoria esistente' => 'Existing category name',
    'Narrativa' => 'Fiction',
    '+ 15 campi aggiuntivi disponibili (vedi CSV di esempio)' => '+ 15 additional fields available (see example CSV)',

    // Sidebar - Instructions
    'Come Funziona' => 'How It Works',
    'Scarica il file CSV di esempio' => 'Download the example CSV file',
    'Compila con i dati dei tuoi libri' => 'Fill in with your book data',
    'Carica il file usando l\'uploader' => 'Upload the file using the uploader',
    'Il sistema creerà automaticamente libri, autori ed editori' => 'The system will automatically create books, authors, and publishers',

    // Sidebar - Tips
    'Suggerimenti' => 'Tips',
    'Usa il separatore %s' => 'Use %s separator',
    'Campo %s obbligatorio' => '%s field required',
    'Autori multipli separati da %s' => 'Multiple authors separated by %s',
    'Salva in UTF-8' => 'Save as UTF-8',
    'Autori ed editori vengono creati automaticamente' => 'Authors and publishers are created automatically',

    // Sidebar - Automations
    'Automatismi' => 'Automations',
    '✓ Crea autori mancanti' => '✓ Create missing authors',
    '✓ Crea editori mancanti' => '✓ Create missing publishers',
    '✓ Validazione dati' => '✓ Data validation',
    '✓ Report errori' => '✓ Error reporting',

    // JavaScript strings (Uppy)
    'File CSV (max 10MB)' => 'CSV file (max 10MB)',
    'Trascina qui il file CSV o %{browse}' => 'Drag CSV file here or %{browse}',
    'seleziona file' => 'select file',

    // JavaScript strings (error messages)
    'Errore Upload' => 'Upload Error',
    'Errore:' => 'Error:',

    // JavaScript strings (progress messages)
    'Caricamento file...' => 'Uploading file...',
    'Completato!' => 'Completed!',
    'Errore durante l\'importazione (HTTP %d)' => 'Import error (HTTP %d)',
    'Errore di connessione durante l\'importazione' => 'Connection error during import',
    'Importazione libro' => 'Importing book',
    'Errore' => 'Error',
];

// Load current locale file
$localeFile = __DIR__ . '/../locale/en_US.json';

if (!file_exists($localeFile)) {
    echo "❌ Error: Locale file not found: $localeFile\n";
    exit(1);
}

$localeContent = file_get_contents($localeFile);
$localeData = json_decode($localeContent, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo "❌ Error: Failed to parse locale JSON: " . json_last_error_msg() . "\n";
    exit(1);
}

// Track statistics
$added = 0;
$skipped = 0;
$updated = 0;

// Add/update translations
foreach ($translations as $italian => $english) {
    if (!isset($localeData[$italian])) {
        $localeData[$italian] = $english;
        $added++;
        echo "✓ Added: \"$italian\" => \"$english\"\n";
    } elseif ($localeData[$italian] !== $english) {
        echo "⚠ Updating: \"$italian\"\n";
        echo "  Old: \"{$localeData[$italian]}\"\n";
        echo "  New: \"$english\"\n";
        $localeData[$italian] = $english;
        $updated++;
    } else {
        $skipped++;
    }
}

// Sort translations alphabetically by Italian key (for better organization)
ksort($localeData, SORT_NATURAL | SORT_FLAG_CASE);

// Save back to file with pretty print
$jsonOutput = json_encode($localeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if (file_put_contents($localeFile, $jsonOutput) === false) {
    echo "❌ Error: Failed to write to locale file\n";
    exit(1);
}

// Summary
echo "\n";
echo "╔════════════════════════════════════════╗\n";
echo "║   CSV Import Page Translation Report  ║\n";
echo "╚════════════════════════════════════════╝\n";
echo "\n";
echo "✅ Added:   $added new translations\n";
echo "📝 Updated: $updated existing translations\n";
echo "⏭️  Skipped: $skipped (already correct)\n";
echo "\n";
echo "📁 File: locale/en_US.json\n";
echo "📊 Total translations: " . count($localeData) . "\n";
echo "\n";

if ($added > 0 || $updated > 0) {
    echo "✅ CSV Import page is now fully translated!\n";
    echo "\n";
    echo "Next steps:\n";
    echo "1. Test the page: http://localhost:8000/admin/libri/import\n";
    echo "2. Commit changes:\n";
    echo "   git add locale/en_US.json\n";
    echo "   git commit -m \"feat(i18n): add English translations for CSV import page\"\n";
} else {
    echo "ℹ️  All translations were already up to date.\n";
}

echo "\n";

#!/usr/bin/env php
<?php
/**
 * Translation Script for Prenotazioni and Prestiti Pages
 *
 * This script translates all Italian strings in:
 * - Prenotazioni pages (3 files)
 * - Prestiti pages (5 files)
 *
 * It adds all missing translations to locale/en_US.json and wraps
 * all Italian strings with __() function calls.
 */

// Define project root
define('PROJECT_ROOT', dirname(__DIR__));

// ANSI color codes for terminal output
define('COLOR_GREEN', "\033[0;32m");
define('COLOR_BLUE', "\033[0;34m");
define('COLOR_YELLOW', "\033[1;33m");
define('COLOR_RED', "\033[0;31m");
define('COLOR_RESET', "\033[0m");

// Output helpers
function success($message) {
    echo COLOR_GREEN . "✓ " . $message . COLOR_RESET . PHP_EOL;
}

function info($message) {
    echo COLOR_BLUE . "ℹ " . $message . COLOR_RESET . PHP_EOL;
}

function warning($message) {
    echo COLOR_YELLOW . "⚠ " . $message . COLOR_RESET . PHP_EOL;
}

function error($message) {
    echo COLOR_RED . "✗ " . $message . COLOR_RESET . PHP_EOL;
}

function printHeader($message) {
    echo PHP_EOL . COLOR_BLUE . str_repeat("=", 70) . COLOR_RESET . PHP_EOL;
    echo COLOR_BLUE . $message . COLOR_RESET . PHP_EOL;
    echo COLOR_BLUE . str_repeat("=", 70) . COLOR_RESET . PHP_EOL . PHP_EOL;
}

// Translation mappings - comprehensive list for Prenotazioni & Prestiti
$translations = [
    // Page titles and headers
    "Crea Prenotazione" => "Create Reservation",
    "Crea Nuova Prenotazione" => "Create New Reservation",
    "Registra una prenotazione per permettere ad un utente di riservare un libro specifico" => "Register a reservation to allow a user to reserve a specific book",
    "Torna alle Prenotazioni" => "Back to Reservations",
    "Gestione Prenotazioni" => "Reservation Management",
    "Modifica Prenotazione" => "Edit Reservation",
    "Crea Nuovo Prestito" => "Create New Loan",
    "Gestione Prestiti" => "Loan Management",
    "Visualizza e gestisci tutti i prestiti della biblioteca" => "View and manage all library loans",
    "Nuovo Prestito" => "New Loan",
    "Dettagli del Prestito" => "Loan Details",
    "Modifica prestito" => "Edit loan",
    "Gestisci restituzione" => "Manage return",
    "Restituzione prestito" => "Loan return",
    "Torna all'elenco" => "Back to list",
    "Gestione prestiti" => "Loan management",

    // Breadcrumbs
    "Prenotazioni" => "Reservations",
    "Prestiti" => "Loans",
    "Nuovo" => "New",

    // Error messages
    "Errore di sicurezza" => "Security error",
    "Token di sicurezza non valido. Riprova." => "Invalid security token. Please try again.",
    "Dati mancanti" => "Missing data",
    "Libro e utente sono campi obbligatori." => "Book and user are required fields.",
    "Errore di salvataggio" => "Save error",
    "Si è verificato un errore durante il salvataggio della prenotazione." => "An error occurred while saving the reservation.",
    "Il libro selezionato è già in prestito. Seleziona un altro libro." => "The selected book is already on loan. Select another book.",
    "Errore: tutti i campi obbligatori devono essere compilati." => "Error: all required fields must be filled.",
    "Errore: la data di scadenza deve essere successiva alla data di prestito." => "Error: the due date must be after the loan date.",
    "Errore durante la creazione del prestito." => "Error creating the loan.",
    "Stato prestito non valido." => "Invalid loan status.",
    "Si è verificato un errore durante l'aggiornamento del prestito." => "An error occurred while updating the loan.",
    "Impossibile completare l'operazione. Riprova più tardi." => "Unable to complete the operation. Try again later.",

    // Success messages
    "Prenotazione aggiornata." => "Reservation updated.",
    "Prestito creato con successo." => "Loan created successfully.",
    "Prestito creato con successo!" => "Loan created successfully!",
    "Prestito aggiornato con successo!" => "Loan updated successfully!",

    // Form sections
    "Dati della Prenotazione" => "Reservation Details",
    "Compila tutti i campi per creare una nuova prenotazione" => "Fill in all fields to create a new reservation",
    "Seleziona Libro" => "Select Book",
    "Scegli il libro da prenotare dal catalogo" => "Choose the book to reserve from the catalog",
    "Libro da prenotare" => "Book to reserve",
    "Seleziona un libro dalla lista..." => "Select a book from the list...",
    "Seleziona Utente" => "Select User",
    "Scegli l'utente che effettua la prenotazione" => "Choose the user making the reservation",
    "Utente prenotante" => "User making reservation",
    "Seleziona un utente dalla lista..." => "Select a user from the list...",
    "Impostazioni Date" => "Date Settings",
    "Configura le date della prenotazione" => "Configure reservation dates",
    "Data Prenotazione" => "Reservation Date",
    "Data di inizio della prenotazione (default: oggi)" => "Reservation start date (default: today)",
    "Data Scadenza" => "Expiry Date",
    "Data di scadenza della prenotazione (default: +30 giorni)" => "Reservation expiry date (default: +30 days)",

    // Form labels
    "Data inizio" => "Start date",
    "Data fine" => "End date",
    "Default: un mese dopo la data inizio" => "Default: one month after start date",
    "Libro" => "Book",
    "Ricerca Utente" => "Search User",
    "Ricerca Libro" => "Search Book",
    "Data Prestito" => "Loan Date",
    "Note (opzionali)" => "Notes (optional)",
    "Note sul prestito" => "Loan notes",
    "Data prestito" => "Loan date",
    "Data scadenza prevista" => "Expected due date",
    "Stato prestito" => "Loan status",
    "Note sulla restituzione" => "Return notes",
    "Dettagli restituzione" => "Return details",
    "Eventuali annotazioni sullo stato del libro..." => "Any notes on the book condition...",

    // Info notes
    "Informazioni Importanti" => "Important Information",
    "La posizione in coda sarà calcolata automaticamente in base alle prenotazioni esistenti" => "Queue position will be calculated automatically based on existing reservations",
    "Lo stato della prenotazione sarà impostato automaticamente come \"attiva\"" => "The reservation status will be automatically set as \"active\"",
    "L'utente riceverà una notifica via email della prenotazione creata" => "The user will receive an email notification of the created reservation",

    // Buttons
    "Crea Prenotazione" => "Create Reservation",
    "Crea Prestito" => "Create Loan",
    "Salva modifiche" => "Save changes",
    "Registra Restituzione" => "Register Return",
    "Conferma restituzione" => "Confirm return",
    "Gestisci Restituzione" => "Manage Return",
    "Torna ai Prestiti" => "Back to Loans",

    // Table headers
    "Posizione Coda" => "Queue Position",
    "Date" => "Dates",
    "Elenco Prestiti" => "Loan List",
    "ID Prestito:" => "Loan ID:",

    // Filters
    "Filtro Libro" => "Book Filter",
    "Filtro Utente" => "User Filter",
    "Filtri di Ricerca" => "Search Filters",
    "Cerca Utente" => "Search User",
    "Cerca Libro" => "Search Book",
    "Data prestito (Da)" => "Loan date (From)",
    "Data prestito (A)" => "Loan date (To)",
    "Cancella filtri" => "Clear filters",
    "Applica Filtri" => "Apply Filters",

    // Status badges
    "In Corso" => "In Progress",
    "In Ritardo" => "Overdue",
    "Restituito" => "Returned",
    "Perso" => "Lost",
    "Danneggiato" => "Damaged",
    "Attiva" => "Active",
    "Completata" => "Completed",
    "Annullata" => "Cancelled",
    "In corso" => "In progress",
    "In ritardo" => "Overdue",
    "In Attesa di Approvazione" => "Pending Approval",

    // Loan details sections
    "Informazioni Prestito" => "Loan Information",
    "Libro:" => "Book:",
    "Non disponibile" => "Not available",
    "Utente:" => "User:",
    "Data Restituzione:" => "Return Date:",
    "Non ancora restituito" => "Not yet returned",
    "Stato e Gestione" => "Status and Management",
    "Stato:" => "Status:",
    "Attivo:" => "Active:",
    "Rinnovi Effettuati:" => "Renewals Made:",
    "Gestito da" => "Managed by",
    "Staff:" => "Staff:",
    "N/D" => "N/A",

    // Loan request widget
    "Richieste di Prestito in Attesa" => "Pending Loan Requests",
    "Inizio:" => "Start:",
    "Fine:" => "End:",
    "Richiesto il" => "Requested on",

    // Status filter buttons
    "Tutti" => "All",

    // Empty states
    "Nessun prestito trovato." => "No loans found.",

    // JavaScript alerts (SweetAlert)
    "Approva prestito?" => "Approve loan?",
    "Sei sicuro di voler approvare questa richiesta di prestito?" => "Are you sure you want to approve this loan request?",
    "Sì, approva" => "Yes, approve",
    "Approvato!" => "Approved!",
    "Il prestito è stato approvato con successo." => "The loan has been successfully approved.",
    "Errore durante l'approvazione" => "Error during approval",
    "Rifiuta prestito" => "Reject loan",
    "Motivo del rifiuto (opzionale)" => "Reason for rejection (optional)",
    "Inserisci il motivo del rifiuto..." => "Enter the reason for rejection...",
    "Rifiutato" => "Rejected",
    "Il prestito è stato rifiutato." => "The loan has been rejected.",
    "Errore durante il rifiuto" => "Error during rejection",
    "Errore nella comunicazione con il server" => "Error communicating with server",
    "Approva Prestito?" => "Approve Loan?",
    "Approverai questa richiesta di prestito?" => "Will you approve this loan request?",
    "Successo" => "Success",
    "Prestito approvato!" => "Loan approved!",
    "Errore nell'approvazione" => "Error in approval",
    "Errore di comunicazione con il server" => "Server communication error",
    "Rifiuta Prestito?" => "Reject Loan?",
    "Rifiuterai questa richiesta di prestito?" => "Will you reject this loan request?",
    "Prestito rifiutato!" => "Loan rejected!",
    "Errore nel rifiuto" => "Error in rejection",

    // User/Book info in return page
    "Prestato il" => "Loaned on",
    "Utente sconosciuto" => "Unknown user",
    "ID utente:" => "User ID:",
    "Libro non disponibile" => "Book not available",
    "ID libro:" => "Book ID:",
    "Titolo non disponibile" => "Title not available",

    // Misc
    "Impossibile configurare autocomplete: elementi mancanti" => "Unable to configure autocomplete: missing elements",
    "Richiesta fallita:" => "Request failed:",
    "Errore durante la ricerca su" => "Error during search on",
];

// Files to translate
$files = [
    // Prenotazioni files
    PROJECT_ROOT . '/app/Views/prenotazioni/crea_prenotazione.php',
    PROJECT_ROOT . '/app/Views/prenotazioni/index.php',
    PROJECT_ROOT . '/app/Views/prenotazioni/modifica_prenotazione.php',

    // Prestiti files
    PROJECT_ROOT . '/app/Views/prestiti/crea_prestito.php',
    PROJECT_ROOT . '/app/Views/prestiti/dettagli_prestito.php',
    PROJECT_ROOT . '/app/Views/prestiti/index.php',
    PROJECT_ROOT . '/app/Views/prestiti/modifica_prestito.php',
    PROJECT_ROOT . '/app/Views/prestiti/restituito_prestito.php',
];

// Track statistics
$stats = [
    'files_processed' => 0,
    'translations_added' => 0,
    'strings_wrapped' => 0,
    'errors' => [],
];

printHeader("Prenotazioni & Prestiti Pages Translation Script");

// Step 1: Update locale/en_US.json
info("Step 1: Updating locale/en_US.json with new translations...");

$localeFile = PROJECT_ROOT . '/locale/en_US.json';
if (!file_exists($localeFile)) {
    error("Locale file not found: $localeFile");
    exit(1);
}

$localeData = json_decode(file_get_contents($localeFile), true);
if ($localeData === null) {
    error("Failed to parse locale file: " . json_last_error_msg());
    exit(1);
}

$newTranslations = 0;
foreach ($translations as $italian => $english) {
    if (!isset($localeData[$italian])) {
        $localeData[$italian] = $english;
        $newTranslations++;
        info("  Added: \"$italian\" => \"$english\"");
    }
}

// Sort alphabetically
ksort($localeData);

// Save back to file
file_put_contents($localeFile, json_encode($localeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
success("Added $newTranslations new translations to locale file");
$stats['translations_added'] = $newTranslations;

// Step 2: Process each file
info("Step 2: Wrapping Italian strings in PHP files...");

foreach ($files as $file) {
    if (!file_exists($file)) {
        warning("File not found: $file");
        $stats['errors'][] = "File not found: $file";
        continue;
    }

    $filename = basename($file);
    info("Processing: $filename");

    $content = file_get_contents($file);
    $originalContent = $content;
    $wrappedCount = 0;

    // Process each translation
    foreach ($translations as $italian => $english) {
        // Escape special regex characters
        $escapedItalian = preg_quote($italian, '/');

        // Pattern 1: Plain text in HTML (between > and <), not already wrapped
        // Avoid matching if already inside __() or if preceded by <?= __()
        $pattern1 = '/(?<=>)(?!.*?__\([\'"]' . $escapedItalian . '[\'"])\s*' . $escapedItalian . '\s*(?=<)/u';
        $count = 0;
        $content = preg_replace($pattern1, '<?= __("' . addslashes($italian) . '") ?>', $content, -1, $count);
        $wrappedCount += $count;

        // Pattern 2: Text in headings/paragraphs that aren't wrapped yet
        // Match patterns like: <h1>Text</h1> where Text is not already __()
        $pattern2 = '/(<h[1-6][^>]*>)(?!.*?__\()' . $escapedItalian . '(<\/h[1-6]>)/u';
        $count = 0;
        $content = preg_replace($pattern2, '$1<?= __("' . addslashes($italian) . '") ?>$2', $content, -1, $count);
        $wrappedCount += $count;

        // Pattern 3: Standalone text spans
        $pattern3 = '/(<span[^>]*>)(?!.*?__\()' . $escapedItalian . '(<\/span>)/u';
        $count = 0;
        $content = preg_replace($pattern3, '$1<?= __("' . addslashes($italian) . '") ?>$2', $content, -1, $count);
        $wrappedCount += $count;
    }

    // Only write if content changed
    if ($content !== $originalContent) {
        file_put_contents($file, $content);
        success("  Wrapped $wrappedCount strings in $filename");
        $stats['strings_wrapped'] += $wrappedCount;
        $stats['files_processed']++;
    } else {
        info("  No changes needed for $filename");
    }
}

// Step 3: Summary
printHeader("Translation Summary");

echo "Files processed:      " . COLOR_GREEN . $stats['files_processed'] . COLOR_RESET . " / " . count($files) . PHP_EOL;
echo "Translations added:   " . COLOR_GREEN . $stats['translations_added'] . COLOR_RESET . PHP_EOL;
echo "Strings wrapped:      " . COLOR_GREEN . $stats['strings_wrapped'] . COLOR_RESET . PHP_EOL;

if (!empty($stats['errors'])) {
    echo PHP_EOL;
    warning("Errors encountered:");
    foreach ($stats['errors'] as $error) {
        echo "  " . COLOR_RED . "• " . $error . COLOR_RESET . PHP_EOL;
    }
}

echo PHP_EOL;
success("Translation script completed successfully!");
info("Next step: Review the changes and test the pages in both Italian and English.");
echo PHP_EOL;

exit(0);

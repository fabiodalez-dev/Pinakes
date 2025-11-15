<?php
/**
 * Add UI translations for buttons, messages, states, labels
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';

// Load existing translations
$existing = json_decode(file_get_contents($translationFile), true);

// UI translations for common buttons, messages, states
$uiTranslations = [
    // Common buttons
    'Annulla' => 'Cancel',
    'OK' => 'OK',
    'Salva' => 'Save',
    'Modifica' => 'Edit',
    'Elimina' => 'Delete',
    'Cerca' => 'Search',
    'Aggiungi' => 'Add',
    'Crea' => 'Create',
    'Carica' => 'Upload',
    'Scarica' => 'Download',
    'Esporta' => 'Export',
    'Importa' => 'Import',
    'Chiudi' => 'Close',
    'Conferma' => 'Confirm',
    'Sì, elimina!' => 'Yes, delete!',
    'Reset' => 'Reset',
    'Filtra' => 'Filter',
    'Applica' => 'Apply',
    'Indietro' => 'Back',
    'Avanti' => 'Next',
    'Fine' => 'Finish',

    // Common messages
    'Errore' => 'Error',
    'Successo' => 'Success',
    'Attenzione' => 'Warning',
    'Info' => 'Info',
    'Attendere prego' => 'Please wait',
    'Attendere prego...' => 'Please wait...',
    'Caricamento...' => 'Loading...',
    'Operazione completata' => 'Operation completed',
    'Operazione annullata' => 'Operation cancelled',

    // Error messages
    'Errore nella comunicazione con il server' => 'Server communication error',
    '❌ Errore di comunicazione con il server' => '❌ Server communication error',
    'Errore Upload' => 'Upload Error',
    'URL Non Valido' => 'Invalid URL',
    'Campo Obbligatorio' => 'Required Field',
    'Campo obbligatorio' => 'Required field',
    'Questa azione non può essere annullata!' => 'This action cannot be undone!',
    'Sei sicuro?' => 'Are you sure?',

    // Data states
    'Nessun dato' => 'No data',
    'Nessun risultato' => 'No results',
    'Non ci sono dati da esportare' => 'No data to export',
    'Nessun elemento trovato' => 'No items found',

    // Filter messages
    'Filtri cancellati' => 'Filters cleared',
    'Tutti i filtri sono stati rimossi' => 'All filters have been removed',
    'Torna alla categoria superiore' => 'Back to parent category',

    // Loan states
    'In corso' => 'Active',
    'In ritardo' => 'Overdue',
    'Restituito' => 'Returned',
    'Completato' => 'Completed',
    'Pending' => 'Pending',
    'Approvato' => 'Approved',
    'Rifiutato' => 'Rejected',

    // Table headers
    'Azioni' => 'Actions',
    'Stato' => 'Status',
    'Data' => 'Date',
    'ID' => 'ID',
    'Nome' => 'Name',
    'Descrizione' => 'Description',
    'Utente' => 'User',
    'Libro' => 'Book',
    'Autore' => 'Author',
    'Editore' => 'Publisher',
    'Genere' => 'Genre',
    'Anno' => 'Year',
    'ISBN' => 'ISBN',
    'Copie' => 'Copies',
    'Disponibilità' => 'Availability',
    'Disponibile' => 'Available',
    'Non disponibile' => 'Not available',

    // Stats labels
    'Totale' => 'Total',
    'Totale Libri' => 'Total Books',
    'Disponibili' => 'Available',
    'Non Disponibili' => 'Not Available',
    'Prestiti Attivi' => 'Active Loans',
    'Prestiti Scaduti' => 'Overdue Loans',
    'Totale Prestiti' => 'Total Loans',

    // Plurals
    'libro trovato' => 'book found',
    'libri trovati' => 'books found',
    'risultato' => 'result',
    'risultati' => 'results',
    'elemento' => 'item',
    'elementi' => 'items',

    // Confirmation messages
    'Vuoi aggiornare lo stato di questa copia?' => 'Do you want to update the status of this copy?',
    'Vuoi eliminare questo elemento?' => 'Do you want to delete this item?',
    'Vuoi procedere?' => 'Do you want to proceed?',

    // Validation messages
    'Il campo è obbligatorio' => 'This field is required',
    'Valore non valido' => 'Invalid value',
    'La data di nascita deve essere precedente alla data di morte.' => 'Date of birth must be before date of death.',

    // Other common UI text
    '••••••••' => '••••••••',  // Password placeholder
    'Seleziona...' => 'Select...',
    'Seleziona un\'opzione' => 'Select an option',
    'Nessuna selezione' => 'No selection',
    'Tutto' => 'All',
    'Nessuno' => 'None',
    'Sì' => 'Yes',
    'No' => 'No',
    'Mostra' => 'Show',
    'Nascondi' => 'Hide',
    'Dettagli' => 'Details',
    'Visualizza' => 'View',
    'Modifica profilo' => 'Edit profile',
    'Impostazioni' => 'Settings',
    'Esci' => 'Logout',
    'Login' => 'Login',
    'Registrati' => 'Register',
    'Password' => 'Password',
    'Email' => 'Email',
];

// Merge with existing
$merged = array_merge($existing, $uiTranslations);

// Sort alphabetically
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save
$formatted = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($translationFile, $formatted);

echo "✅ Added UI translations\n";
echo "   Previous count: " . count($existing) . "\n";
echo "   New translations added: " . (count($merged) - count($existing)) . "\n";
echo "   Total translations: " . count($merged) . "\n\n";

$newTranslations = array_diff_key($uiTranslations, $existing);
if (!empty($newTranslations)) {
    echo "📝 Sample of new translations (first 20):\n";
    $sample = array_slice($newTranslations, 0, 20, true);
    foreach ($sample as $it => $en) {
        echo "   • \"{$it}\" → \"{$en}\"\n";
    }
    if (count($newTranslations) > 20) {
        echo "   ... and " . (count($newTranslations) - 20) . " more\n";
    }
}

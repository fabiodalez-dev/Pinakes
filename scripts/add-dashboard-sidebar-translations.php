<?php
/**
 * Add all dashboard and sidebar translations
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';

// Load existing translations
$existing = json_decode(file_get_contents($translationFile), true);

// Dashboard + Sidebar translations
$newTranslations = [
    // Dashboard page
    'Dashboard' => 'Dashboard',
    'Panoramica generale del sistema bibliotecario' => 'General overview of the library system',
    'Totale libri presenti' => 'Total books in collection',
    'Utenti registrati' => 'Registered users',
    'In corso di restituzione' => 'Pending return',
    'Da approvare' => 'To approve',
    'Nessuna richiesta' => 'No requests',
    'Nella collezione' => 'In collection',
    'Richieste di Prestito in Attesa' => 'Pending Loan Requests',
    'Gestisci tutte' => 'Manage all',
    'Nessuna richiesta in attesa di approvazione.' => 'No requests pending approval.',
    'Inizio:' => 'Start:',
    'Fine:' => 'End:',
    'Richiesto il' => 'Requested on',
    'Ultimi Libri Inseriti' => 'Recently Added Books',
    'Nessun libro ancora inserito' => 'No books added yet',
    'Prestiti in Corso' => 'Active Loans',
    'Nessun prestito in corso' => 'No active loans',
    'Gestisci' => 'Manage',
    'Nessun prestito scaduto' => 'No overdue loans',
    'Sei sicuro di voler approvare questo prestito?' => 'Are you sure you want to approve this loan?',
    'Motivo del rifiuto (opzionale):' => 'Reason for rejection (optional):',

    // Sidebar Navigation
    'Menu Principale' => 'Main Menu',
    'Panoramica generale' => 'General overview',
    'Books' => 'Books',
    'Gestione collezione' => 'Collection management',
    'Gestione autori' => 'Authors management',
    'Case editrici' => 'Publishing houses',
    'Generi e sottogeneri' => 'Genres and subgenres',
    'Loans' => 'Loans',
    'Gestione prestiti' => 'Loans management',
    'Scaffali e mensole' => 'Shelves and racks',
    'Users' => 'Users',
    'Gestione utenti' => 'Users management',
    'Report e analisi' => 'Reports and analytics',
    'Gestione recensioni' => 'Reviews management',
    'Estensioni' => 'Extensions',

    // Quick Actions
    'Azioni Rapide' => 'Quick Actions',
    'Nuovo Libro' => 'New Book',
    'Aggiungi alla collezione' => 'Add to collection',
    'Nuovo Prestito' => 'New Loan',
    'Registra prestito' => 'Register loan',
    'Approva Prestiti' => 'Approve Loans',
    'Richieste pendenti' => 'Pending requests',
    'Integrità dati' => 'Data integrity',

    // Quick Stats
    'Statistiche Rapide' => 'Quick Statistics',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);

// Sort alphabetically
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save
$formatted = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($translationFile, $formatted);

echo "✅ Added dashboard and sidebar translations\n";
echo "   Previous count: " . count($existing) . "\n";
echo "   New translations added: " . (count($merged) - count($existing)) . "\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Show new translations
$newAdded = array_diff_key($newTranslations, $existing);
if (!empty($newAdded)) {
    echo "📝 New translations added:\n";
    foreach ($newAdded as $it => $en) {
        echo "   • \"{$it}\" → \"{$en}\"\n";
    }
}

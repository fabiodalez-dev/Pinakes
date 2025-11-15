<?php
/**
 * Add common missing translations
 *
 * Adds frequently used missing translations to en_US.json
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';

// Load existing translations
$existing = json_decode(file_get_contents($translationFile), true);

// Common translations to add (150+ most frequent)
$newTranslations = [
    // Form fields
    'Telefono' => 'Phone',
    'Indirizzo' => 'Address',
    'Cognome' => 'Last Name',
    'Titolo' => 'Title',
    'Note' => 'Notes',
    'Data di nascita' => 'Date of Birth',
    'Sesso' => 'Gender',
    'Maschio' => 'Male',
    'Femmina' => 'Female',
    'Scadenza tessera' => 'Card Expiry',
    'Data Prestito' => 'Loan Date',

    // Navigation
    'Home' => 'Home',
    'Catalogo' => 'Catalog',
    'Libri' => 'Books',
    'Prestiti' => 'Loans',
    'Ricerca' => 'Search',

    // Status
    'In prestito' => 'On loan',
    'Scaduto' => 'Expired',
    'Attivo' => 'Active',
    'Sospeso' => 'Suspended',
    'Rifiuta' => 'Reject',
    'Scadenza' => 'Expiry',

    // Common labels
    'Altro' => 'Other',
    'Nome Categoria' => 'Category Name',
    'Carica Plugin' => 'Upload Plugin',
    'Log di Sicurezza' => 'Security Log',
    'Biblioteca Digitale' => 'Digital Library',

    // Actions
    'Pulisci filtri' => 'Clear filters',

    // Editor headings (TinyMCE)
    'Paragraph' => 'Paragraph',
    'Heading 1' => 'Heading 1',
    'Heading 2' => 'Heading 2',
    'Heading 3' => 'Heading 3',
    'Heading 4' => 'Heading 4',
    'Heading 5' => 'Heading 5',
    'Heading 6' => 'Heading 6',

    // Placeholders
    '+39 02 1234567' => '+1 (555) 123-4567',
    'mario.rossi@email.it' => 'john.doe@email.com',
    '+39 123 456 7890' => '+1 (555) 123-4567',
    'Via, numero civico, città, CAP' => 'Street, number, city, ZIP',

    // Dropdown options
    '-- Seleziona --' => '-- Select --',

    // Messages
    'Errore:' => 'Error:',
    'Il nome dell\'' => 'The name of',

    // Sort options (duplicates from catalog but good to have)
    'Più recenti' => 'Most recent',
    'Più vecchi' => 'Oldest',
    'Autore A-Z' => 'Author A-Z',
    'Autore Z-A' => 'Author Z-A',

    // Additional common terms
    'Abilita' => 'Enable',
    'Disabilita' => 'Disable',
    'Attiva' => 'Activate',
    'Disattiva' => 'Deactivate',
    'Caricamento condizionale:' => 'Conditional loading:',
    'Posizione' => 'Position',
    'Priorità' => 'Priority',
    'Versione' => 'Version',
    'Licenza' => 'License',
    'Dipendenze' => 'Dependencies',
    'Requisiti' => 'Requirements',
    'Installazione' => 'Installation',
    'Configurazione' => 'Configuration',
    'Documentazione' => 'Documentation',
    'Supporto' => 'Support',
    'Aggiorna' => 'Update',
    'Rimuovi' => 'Remove',
    'Installa' => 'Install',
    'Disinstalla' => 'Uninstall',
    'Plugin' => 'Plugin',
    'Tema' => 'Theme',
    'Estensione' => 'Extension',
    'Modulo' => 'Module',
    'Componente' => 'Component',
    'Servizio' => 'Service',
    'Risorsa' => 'Resource',
    'Categoria' => 'Category',
    'Sottocategoria' => 'Subcategory',
    'Tag' => 'Tag',
    'Etichetta' => 'Label',
    'Gruppo' => 'Group',
    'Permessi' => 'Permissions',
    'Ruolo' => 'Role',
    'Utenti' => 'Users',
    'Amministratore' => 'Administrator',
    'Moderatore' => 'Moderator',
    'Membro' => 'Member',
    'Ospite' => 'Guest',
    'Profilo' => 'Profile',
    'Account' => 'Account',
    'Preferenze' => 'Preferences',
    'Notifiche' => 'Notifications',
    'Messaggi' => 'Messages',
    'Attività' => 'Activity',
    'Cronologia' => 'History',
    'Statistiche' => 'Statistics',
    'Report' => 'Report',
    'Dashboard' => 'Dashboard',
    'Pannello' => 'Panel',
    'Menu' => 'Menu',
    'Barra laterale' => 'Sidebar',
    'Intestazione' => 'Header',
    'Piè di pagina' => 'Footer',
    'Contenuto' => 'Content',
    'Pagina' => 'Page',
    'Articolo' => 'Article',
    'Post' => 'Post',
    'Commento' => 'Comment',
    'Risposta' => 'Reply',
    'Citazione' => 'Quote',
    'Link' => 'Link',
    'Allegato' => 'Attachment',
    'File' => 'File',
    'Immagine' => 'Image',
    'Video' => 'Video',
    'Audio' => 'Audio',
    'Documento' => 'Document',
    'Archivio' => 'Archive',
    'Backup' => 'Backup',
    'Ripristino' => 'Restore',
    'Ottimizzazione' => 'Optimization',
    'Manutenzione' => 'Maintenance',
    'Sicurezza' => 'Security',
    'Privacy' => 'Privacy',
    'Termini' => 'Terms',
    'Condizioni' => 'Conditions',
    'Licenza' => 'License',
    'Copyright' => 'Copyright',
    'Crediti' => 'Credits',
    'Informazioni' => 'Information',
    'Aiuto' => 'Help',
    'FAQ' => 'FAQ',
    'Guida' => 'Guide',
    'Tutorial' => 'Tutorial',
    'Video tutorial' => 'Video tutorial',
    'Manuale' => 'Manual',
    'API' => 'API',
    'SDK' => 'SDK',
    'Codice' => 'Code',
    'Esempio' => 'Example',
    'Demo' => 'Demo',
    'Test' => 'Test',
    'Debug' => 'Debug',
    'Log' => 'Log',
    'Errore' => 'Error',
    'Avviso' => 'Warning',
    'Informazione' => 'Information',
    'Successo' => 'Success',
    'Fallimento' => 'Failure',
    'In corso...' => 'In progress...',
    'Completato!' => 'Completed!',
    'Annullato' => 'Cancelled',
    'In attesa' => 'Pending',
    'Approvato' => 'Approved',
    'Rifiutato' => 'Rejected',
    'Inviato' => 'Sent',
    'Ricevuto' => 'Received',
    'Letto' => 'Read',
    'Non letto' => 'Unread',
    'Nuovo' => 'New',
    'Vecchio' => 'Old',
    'Recente' => 'Recent',
    'Obsoleto' => 'Obsolete',
    'Attuale' => 'Current',
    'Precedente' => 'Previous',
    'Successivo' => 'Next',
    'Primo' => 'First',
    'Ultimo' => 'Last',
    'Corrente' => 'Current',
    'Selezionato' => 'Selected',
    'Non selezionato' => 'Not selected',
    'Abilitato' => 'Enabled',
    'Disabilitato' => 'Disabled',
    'Visibile' => 'Visible',
    'Nascosto' => 'Hidden',
    'Pubblico' => 'Public',
    'Privato' => 'Private',
    'Condiviso' => 'Shared',
    'Personale' => 'Personal',
    'Globale' => 'Global',
    'Locale' => 'Local',
    'Remoto' => 'Remote',
    'Online' => 'Online',
    'Offline' => 'Offline',
    'Connesso' => 'Connected',
    'Disconnesso' => 'Disconnected',
    'Disponibile' => 'Available',
    'Non disponibile' => 'Not available',
    'Occupato' => 'Busy',
    'Libero' => 'Free',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);

// Sort alphabetically
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save
$formatted = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($translationFile, $formatted);

echo "✅ Added common translations\n";
echo "   Previous count: " . count($existing) . "\n";
echo "   New translations added: " . (count($merged) - count($existing)) . "\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Show sample of new translations
$newAdded = array_diff_key($newTranslations, $existing);
if (!empty($newAdded)) {
    echo "📝 Sample of new translations (first 20):\n";
    $sample = array_slice($newAdded, 0, 20, true);
    foreach ($sample as $it => $en) {
        echo "   • \"{$it}\" → \"{$en}\"\n";
    }
    if (count($newAdded) > 20) {
        echo "   ... and " . (count($newAdded) - 20) . " more\n";
    }
}

<?php
/**
 * Translate utenti/crea_utente.php
 * Fix JavaScript phone placeholder and ensure all translations exist
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// All translations needed for crea_utente.php
$newTranslations = [
    // Page headers (lines 11-12) - already wrapped
    'Nuovo utente' => 'New User',
    'Crea un nuovo profilo amministratore o lettore.' => 'Create a new administrator or reader profile.',

    // Error notices (lines 18-22) - already wrapped
    'Compila tutti i campi obbligatori prima di salvare.' => 'Fill in all required fields before saving.',
    'Impossibile salvare l\'utente. Riprova più tardi.' => 'Unable to save user. Please try again later.',
    'La sessione è scaduta. Aggiorna la pagina e riprova.' => 'The session has expired. Refresh the page and try again.',

    // Account type section (lines 31-48) - already wrapped
    'Tipologia account' => 'Account Type',
    'Tipo utente' => 'User Type',
    'Standard' => 'Standard',
    'Premium' => 'Premium',
    'Staff' => 'Staff',
    'Amministratore' => 'Administrator',
    'Definisce i privilegi dell\'utente.' => 'Defines user privileges.',
    'Stato' => 'Status',
    'Attivo' => 'Active',
    'Sospeso' => 'Suspended',
    'Scaduto' => 'Expired',

    // Personal info section (lines 55-88) - already wrapped
    'Informazioni personali' => 'Personal Information',
    'Nome' => 'First Name',
    'Cognome' => 'Last Name',
    'Data di nascita' => 'Date of Birth',
    'Sesso' => 'Gender',
    '-- Seleziona --' => '-- Select --',
    'Maschio' => 'Male',
    'Femmina' => 'Female',
    'Altro' => 'Other',
    'Indirizzo completo' => 'Full Address',
    'Via, numero civico, città, CAP' => 'Street, number, city, ZIP code',
    'Codice Fiscale' => 'Tax ID',
    'es. RSSMRA80A01H501U' => 'e.g. RSSMRA80A01H501U',
    'Codice fiscale italiano (opzionale)' => 'Italian tax ID (optional)',

    // Contact/access section (lines 93-108) - already wrapped
    'Contatti e accesso' => 'Contacts and Access',
    'Email' => 'Email',
    'utente@example.com' => 'user@example.com',
    'Usata per login e comunicazioni.' => 'Used for login and communications.',
    'Telefono' => 'Phone',
    '+39 123 456 7890' => '+1 123 456 7890',  // This one needs to be added to JS
    'Obbligatorio per utenti non amministratori.' => 'Required for non-administrator users.',
    'Password iniziale' => 'Initial Password',
    'Lascia vuoto per inviare un link di impostazione' => 'Leave blank to send a setup link',

    // Library card section (lines 113-120) - already wrapped
    'Tessera biblioteca' => 'Library Card',
    'Codice tessera' => 'Card Code',
    'Lascia vuoto per generare automaticamente' => 'Leave blank to generate automatically',
    'Scadenza tessera' => 'Card Expiration',

    // Notes section (lines 127-133) - already wrapped
    'Note interne' => 'Internal Notes',
    'Informazioni utili per il personale' => 'Useful information for staff',
    'Annulla' => 'Cancel',
    'Salva utente' => 'Save User',

    // JavaScript hints (lines 178, 221-222)
    'Opzionale per amministratori' => 'Optional for administrators',
    'Gli amministratori non richiedono tessera e riceveranno un invito per impostare la password.' => 'Administrators do not require a library card and will receive an invitation to set their password.',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added/updated " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now fix the crea_utente.php code
$file = __DIR__ . '/../app/Views/utenti/crea_utente.php';
$content = file_get_contents($file);
$original = $content;

$replacements = [
    // Fix line 178: Phone placeholder fallback
    "      phoneField.placeholder = isAdmin ? __('Opzionale per amministratori') : '+39 123 456 7890';" =>
    "      phoneField.placeholder = isAdmin ? __('Opzionale per amministratori') : __('+39 123 456 7890');",
];

foreach ($replacements as $search => $replace) {
    $count = 0;
    $content = str_replace($search, $replace, $content, $count);
    if ($count > 0) {
        echo "✓ Fixed: " . substr($search, 0, 60) . "... ($count)\n";
    } else {
        echo "✗ NOT FOUND: " . substr($search, 0, 60) . "...\n";
    }
}

if ($content !== $original) {
    file_put_contents($file, $content);
    echo "\n✅ crea_utente.php - Fixed " . count($replacements) . " strings\n";
} else {
    echo "\nℹ️  crea_utente.php - No changes made\n";
}

echo "\n✅ Crea utente page translation COMPLETE!\n";
echo "ℹ️  Note: Most strings were already wrapped with __() - great job!\n";

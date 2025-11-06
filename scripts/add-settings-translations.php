<?php
/**
 * Add all settings page translations to en_US.json
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';

// Load existing translations
$existing = json_decode(file_get_contents($translationFile), true);

// Settings page translations (67 missing strings)
$newTranslations = [
    // General settings
    'Impostazioni Applicazione' => 'Application Settings',
    'Impostazioni salvate.' => 'Settings saved.',
    'CSRF non valido.' => 'Invalid CSRF token.',
    'Salva Impostazioni' => 'Save Settings',
    'Nessuna' => 'None',

    // Email section
    'Driver' => 'Driver',
    'From Email' => 'From Email',
    'From Name' => 'From Name',
    'SMTP Host' => 'SMTP Host',
    'SMTP Port' => 'SMTP Port',
    'SMTP Username' => 'SMTP Username',
    'SMTP Password' => 'SMTP Password',
    'Encryption' => 'Encryption',

    // Registration section
    'Registrazione' => 'Registration',
    'Richiedi approvazione admin dopo la conferma email' => 'Require admin approval after email confirmation',

    // CMS section
    'Gestione Contenuti (CMS)' => 'Content Management (CMS)',
    'Gestisci il contenuto della pagina Chi Siamo con testo e immagine' => 'Manage the About Us page content with text and image',

    // Cron Job section
    'Configurazione Cron Job' => 'Cron Job Configuration',
    'Notifiche Automatiche' => 'Automatic Notifications',
    'Il sistema include un cron job che gestisce automaticamente:' => 'The system includes a cron job that automatically handles:',
    'Avvisi scadenza prestiti (configurabile in Impostazioni → Avanzate, default 3 giorni prima)' => 'Loan expiration warnings (configurable in Settings → Advanced, default 3 days before)',
    'Notifiche prestiti scaduti' => 'Overdue loan notifications',
    'Notifiche disponibilità libri in wishlist' => 'Wishlist book availability notifications',
    'Manutenzione giornaliera del database' => 'Daily database maintenance',
    'Installazione Cron Job' => 'Cron Job Installation',
    '1. Accesso al server' => '1. Server Access',
    'Accedi al server tramite SSH e modifica il crontab:' => 'Access the server via SSH and edit the crontab:',
    '2. Aggiungi una delle configurazioni seguenti:' => '2. Add one of the following configurations:',
    'Opzione 1:' => 'Option 1:',
    'Esecuzione ogni ora (8:00-20:00)' => 'Execute every hour (8:00-20:00)',
    'Opzione 2:' => 'Option 2:',
    'Ogni 15 minuti nei giorni lavorativi (8:00-18:00)' => 'Every 15 minutes on weekdays (8:00-18:00)',
    'Opzione 3:' => 'Option 3:',
    'Esecuzione ogni 30 minuti (consigliato)' => 'Execute every 30 minutes (recommended)',
    'Note importanti:' => 'Important notes:',
    'Sostituisci <code>/usr/bin/php</code> con il percorso corretto di PHP sul tuo server' => 'Replace <code>/usr/bin/php</code> with the correct PHP path on your server',
    'Assicurati che il path assoluto dello script sia corretto' => 'Make sure the absolute path to the script is correct',
    'Crea la cartella logs se non esiste: <code>mkdir -p logs</code>' => 'Create the logs folder if it doesn\'t exist: <code>mkdir -p logs</code>',
    'Verifica i permessi di esecuzione: <code>chmod +x cron/automatic-notifications.php</code>' => 'Verify execution permissions: <code>chmod +x cron/automatic-notifications.php</code>',
    'Test del cron job:' => 'Cron job test:',
    'Per testare lo script manualmente:' => 'To test the script manually:',

    // Cookie Banner section
    'Testi Cookie Banner' => 'Cookie Banner Texts',
    'Testi Banner' => 'Banner Texts',
    'Descrizione Banner' => 'Banner Description',
    'Testo principale mostrato nel banner. Puoi usare HTML.' => 'Main text shown in the banner. You can use HTML.',
    'Testi Modale Preferenze' => 'Preferences Modal Texts',
    'Titolo Modale' => 'Modal Title',
    'Descrizione Modale' => 'Modal Description',
    'Descrizione nella modale preferenze. Puoi usare HTML.' => 'Description in the preferences modal. You can use HTML.',
    'Cookie Essenziali' => 'Essential Cookies',
    'Cookie Analitici' => 'Analytics Cookies',
    'Disabilita se il tuo sito non usa cookie analitici (es. Google Analytics)' => 'Disable if your site doesn\'t use analytics cookies (e.g. Google Analytics)',
    'Codice JavaScript Analytics' => 'Analytics JavaScript Code',
    'Cookie di Marketing' => 'Marketing Cookies',
    'Disabilita se il tuo sito non usa cookie di marketing/advertising' => 'Disable if your site doesn\'t use marketing/advertising cookies',
    'Salva Testi Cookie Banner' => 'Save Cookie Banner Texts',

    // Email Templates section
    'Template Email' => 'Email Templates',
    'Seleziona Template' => 'Select Template',
    '-- Seleziona un template --' => '-- Select a template --',
    'Soggetto Email' => 'Email Subject',
    'Oggetto dell\'email' => 'Email subject',
    'Corpo Email' => 'Email Body',
    'Variabili disponibili:' => 'Available variables:',
    'Salva Template' => 'Save Template',
    'Template aggiornato con successo!' => 'Template updated successfully!',
    'Errore nell\'aggiornamento del template' => 'Error updating template',
    'Errore: ' => 'Error: ',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);

// Sort alphabetically
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save
$formatted = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($translationFile, $formatted);

echo "✅ Added settings translations\n";
echo "   Previous count: " . count($existing) . "\n";
echo "   New translations added: " . (count($merged) - count($existing)) . "\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Show new translations
$newAdded = array_diff_key($newTranslations, $existing);
if (!empty($newAdded)) {
    echo "📝 New translations added:\n";
    foreach ($newAdded as $it => $en) {
        echo "   • \"$it\" → \"$en\"\n";
    }
}

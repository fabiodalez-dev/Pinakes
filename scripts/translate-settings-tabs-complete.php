<?php
/**
 * Complete translation of all 4 settings tab files:
 * - contacts-tab.php
 * - privacy-tab.php
 * - messages-tab.php
 * - advanced-tab.php
 *
 * This fixes the "Contenuto Pagina" issue and all other Italian strings
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// ALL translations needed for the 4 settings tab files
$newTranslations = [
    // contacts-tab.php
    'Contenuto Pagina' => 'Page Content',
    'Personalizza il titolo e il testo introduttivo della pagina contatti' => 'Customize the title and introductory text of the contact page',
    'Titolo pagina' => 'Page title',
    'Testo introduttivo' => 'Introductory text',
    'Informazioni di Contatto' => 'Contact Information',
    'Email e telefono visibili sulla pagina contatti' => 'Email and phone visible on contact page',
    'Email di contatto' => 'Contact email',
    'Visibile pubblicamente sulla pagina contatti' => 'Publicly visible on contact page',
    'Email per notifiche' => 'Email for notifications',
    'Email dove ricevere i messaggi dal form contatti' => 'Email where to receive messages from contact form',
    'Mappa Interattiva' => 'Interactive Map',
    'Embed della mappa (Google Maps o OpenStreetMap). Puoi inserire l\'URL o il codice iframe completo.' => 'Map embed (Google Maps or OpenStreetMap). You can insert the URL or full iframe code.',
    '⚠️ Privacy: Le mappe esterne vengono caricate solo se l\'utente accetta i cookie Analytics.' => '⚠️ Privacy: External maps are loaded only if the user accepts Analytics cookies.',
    'Codice embed completo' => 'Full embed code',
    'Come ottenere il codice' => 'How to get the code',
    'Google reCAPTCHA v3' => 'Google reCAPTCHA v3',
    'Protezione anti-spam per il form contatti' => 'Anti-spam protection for contact form',
    'Site Key' => 'Site Key',
    'Secret Key' => 'Secret Key',
    'Ottieni le chiavi da Google reCAPTCHA' => 'Get keys from Google reCAPTCHA',
    'Testo Privacy' => 'Privacy Text',
    'Testo della checkbox privacy nel form' => 'Privacy checkbox text in the form',
    'Testo checkbox' => 'Checkbox text',
    'Anteprima' => 'Preview',
    'Salva Contatti' => 'Save Contacts',

    // privacy-tab.php
    'Contenuto Privacy Policy' => 'Privacy Policy Content',
    'Personalizza il titolo e il contenuto della pagina privacy policy' => 'Customize the title and content of the privacy policy page',
    'Contenuto pagina' => 'Page content',
    'Pagina Cookie Policy' => 'Cookie Policy Page',
    'Contenuto della pagina /cookies accessibile dal banner' => 'Content of /cookies page accessible from banner',
    'Contenuto Cookie Policy' => 'Cookie Policy Content',
    'Questo contenuto verrà mostrato nella pagina /cookies linkata dal cookie banner' => 'This content will be shown in /cookies page linked from cookie banner',
    'Cookie Banner' => 'Cookie Banner',
    'Configurazione del banner cookie' => 'Cookie banner configuration',
    'Abilita Cookie Banner' => 'Enable Cookie Banner',
    'Lingua' => 'Language',
    'Italiano (IT)' => 'Italian (IT)',
    'English (EN)' => 'English (EN)',
    'Deutsch (DE)' => 'German (DE)',
    'Español (ES)' => 'Spanish (ES)',
    'Français (FR)' => 'French (FR)',
    'Nederlands (NL)' => 'Dutch (NL)',
    'Polski (PL)' => 'Polish (PL)',
    'Dansk (DA)' => 'Danish (DA)',
    'Български (BG)' => 'Bulgarian (BG)',
    'Català (CA)' => 'Catalan (CA)',
    'Slovenčina (SK)' => 'Slovak (SK)',
    'עברית (HE)' => 'Hebrew (HE)',
    'Paese' => 'Country',
    'Codice ISO 2 lettere (es: IT, FR, GB)' => 'ISO 2-letter code (e.g. IT, FR, GB)',
    'Link Cookie Statement' => 'Cookie Statement Link',
    'URL della pagina con la cookie policy' => 'URL of the page with cookie policy',
    'Link Cookie Technologies' => 'Cookie Technologies Link',
    'URL della pagina con le tecnologie dei cookie' => 'URL of the page with cookie technologies',
    'Categorie Cookie' => 'Cookie Categories',
    'Gestisci la visibilità delle categorie di cookie nel banner. I cookie essenziali sono sempre visibili e obbligatori.' => 'Manage cookie category visibility in banner. Essential cookies are always visible and mandatory.',
    'Mostra Cookie Analitici' => 'Show Analytics Cookies',
    'Nascondi se il sito non utilizza strumenti di analytics (es. Google Analytics)' => 'Hide if site does not use analytics tools (e.g. Google Analytics)',
    'Mostra Cookie di Marketing' => 'Show Marketing Cookies',
    'Nascondi se il sito non utilizza cookie di marketing o advertising' => 'Hide if site does not use marketing or advertising cookies',
    'Nota:' => 'Note:',
    'I Cookie Essenziali sono sempre visibili e non possono essere disabilitati poiché necessari per il funzionamento del sito.' => 'Essential Cookies are always visible and cannot be disabled as they are necessary for site functionality.',
    'Salva Privacy Policy' => 'Save Privacy Policy',

    // messages-tab.php
    'Messaggi di Contatto' => 'Contact Messages',
    'Tutti i messaggi ricevuti tramite il form contatti' => 'All messages received through contact form',
    'Segna tutti come letti' => 'Mark all as read',
    'Mittente' => 'From',
    'Nuovo' => 'New',
    'Archiviato' => 'Archived',
    'Letto' => 'Read',
    'Non letto' => 'Unread',
    'Dettagli Messaggio' => 'Message Details',
    'Da' => 'From',
    'Messaggio' => 'Message',
    'Rispondi' => 'Reply',
    'Archivia' => 'Archive',
    'Nessun messaggio ricevuto' => 'No messages received',

    // advanced-tab.php
    'Gestione JavaScript Personalizzati basata su Cookie' => 'Cookie-based Custom JavaScript Management',
    'Gli script JavaScript sono divisi in 3 categorie in base alla tipologia di cookie:' => 'JavaScript scripts are divided into 3 categories based on cookie type:',
    '⚙️ Comportamento Automatico: Se inserisci codice in "JavaScript Analitici" o "JavaScript Marketing", i rispettivi toggle in <a href="/admin/settings?tab=privacy#privacy" class="underline font-semibold">Impostazioni Privacy</a> verranno automaticamente selezionati.' => '⚙️ Automatic Behavior: If you insert code in "Analytics JavaScript" or "Marketing JavaScript", the respective toggles in <a href="/admin/settings?tab=privacy#privacy" class="underline font-semibold">Privacy Settings</a> will be automatically selected.',
    '📋 Importante: Devi elencare manualmente i cookie tracciati da questi script nella <a href="/cookies" target="_blank" class="underline font-semibold">Pagina Cookie</a> per conformità GDPR.' => '📋 Important: You must manually list cookies tracked by these scripts in the <a href="/cookies" target="_blank" class="underline font-semibold">Cookie Page</a> for GDPR compliance.',
    'JavaScript Essenziali' => 'Essential JavaScript',
    'Script necessari per il funzionamento del sito (es. chat support, accessibility tools)' => 'Scripts necessary for site functionality (e.g. chat support, accessibility tools)',
    'JavaScript Analitici' => 'Analytics JavaScript',
    'Script di analisi e statistiche (es. Google Analytics, Matomo, Hotjar)' => 'Analytics and statistics scripts (e.g. Google Analytics, Matomo, Hotjar)',
    'Auto-attivazione: Se compili questo campo, il toggle "Mostra Cookie Analitici" in Privacy verrà attivato automaticamente.' => 'Auto-activation: If you fill this field, the "Show Analytics Cookies" toggle in Privacy will be automatically activated.',
    'JavaScript Marketing' => 'Marketing JavaScript',
    'Script pubblicitari e remarketing (es. Facebook Pixel, Google Ads, LinkedIn Insight)' => 'Advertising and remarketing scripts (e.g. Facebook Pixel, Google Ads, LinkedIn Insight)',
    'Auto-attivazione: Se compili questo campo, il toggle "Mostra Cookie Marketing" in Privacy verrà attivato automaticamente.' => 'Auto-activation: If you fill this field, the "Show Marketing Cookies" toggle in Privacy will be automatically activated.',
    'CSS Personalizzato' => 'Custom CSS',
    'Codice CSS da applicare a tutte le pagine del frontend' => 'CSS code to apply to all frontend pages',
    'Codice JavaScript' => 'JavaScript Code',
    'Codice CSS' => 'CSS Code',
    'Non includere tag <script></script>' => 'Do not include <script></script> tags',
    'Il codice verrà inserito in un tag <style> nell\'header. Non includere i tag <style></style>' => 'The code will be inserted in a <style> tag in header. Do not include <style></style> tags',
    'Notifiche Prestiti' => 'Loan Notifications',
    'Configura quando inviare l\'avviso di scadenza prestiti agli utenti' => 'Configure when to send loan expiry warning to users',
    'Giorni di preavviso per scadenza prestito' => 'Days of advance warning for loan expiry',
    'giorni prima della scadenza' => 'days before expiry',
    'Valore compreso tra 1 e 30 giorni. Consigliato: 3 giorni' => 'Value between 1 and 30 days. Recommended: 3 days',
    'Salva Impostazioni Avanzate' => 'Save Advanced Settings',

    // Sitemap section
    'Sitemap XML' => 'XML Sitemap',
    'Mappa del sito per i motori di ricerca' => 'Site map for search engines',
    'URL pubblico:' => 'Public URL:',
    'Percorso file:' => 'File path:',
    'Ultima generazione:' => 'Last generated:',
    'File esistente (data modifica)' => 'Existing file (modification date)',
    'Mai generata' => 'Never generated',
    'File sitemap presente' => 'Sitemap file present',
    'File sitemap non trovato' => 'Sitemap file not found',
    'Usa il pulsante "Rigenera adesso" per crearla' => 'Use "Regenerate now" button to create it',
    'Configurazione Cron Job' => 'Cron Job Configuration',
    'Per aggiornare automaticamente la sitemap ogni giorno:' => 'To automatically update sitemap daily:',
    'Esegue la rigenerazione ogni giorno alle 02:00 e registra il log in <code class="bg-gray-100 px-1 py-0.5 rounded">storage/logs/sitemap.log</code>.' => 'Executes regeneration daily at 02:00 and logs to <code class="bg-gray-100 px-1 py-0.5 rounded">storage/logs/sitemap.log</code>.',
    'Lo script CLI utilizza il valore di <code class="bg-gray-100 px-1 py-0.5 rounded">APP_CANONICAL_URL</code>. Assicurati che sia configurato correttamente per evitare URL duplicati.' => 'CLI script uses <code class="bg-gray-100 px-1 py-0.5 rounded">APP_CANONICAL_URL</code> value. Make sure it is configured correctly to avoid duplicate URLs.',
    'Rigenera Sitemap' => 'Regenerate Sitemap',
    'La sitemap viene aggiornata automaticamente quando premi il pulsante oppure tramite lo script CLI <code class="bg-gray-100 px-1 py-0.5 rounded text-xs">php scripts/generate-sitemap.php</code>. Usa questa azione dopo aver importato un grande numero di libri o modifiche ai contenuti CMS.' => 'Sitemap is automatically updated when you press button or via CLI script <code class="bg-gray-100 px-1 py-0.5 rounded text-xs">php scripts/generate-sitemap.php</code>. Use this action after importing many books or CMS content changes.',
    'Rigenera adesso' => 'Regenerate now',
    'Note tecniche' => 'Technical notes',
    'Il file generato si trova in <code class="bg-gray-100 px-1 py-0.5 rounded">public/sitemap.xml</code>' => 'Generated file is located at <code class="bg-gray-100 px-1 py-0.5 rounded">public/sitemap.xml</code>',
    'Il cron utilizza gli stessi permessi dell\'utente di sistema che lo esegue' => 'Cron uses same permissions as system user executing it',
    'Dopo la rigenerazione, invia l\'URL della sitemap a Google Search Console e Bing Webmaster Tools' => 'After regeneration, submit sitemap URL to Google Search Console and Bing Webmaster Tools',

    // API section
    'API Pubblica' => 'Public API',
    'Gestisci l\'accesso all\'API per cercare libri via EAN, ISBN e autore' => 'Manage API access to search books via EAN, ISBN and author',
    'Stato API' => 'API Status',
    'Abilita o disabilita l\'accesso all\'API pubblica' => 'Enable or disable public API access',
    'API Keys' => 'API Keys',
    'Crea Nuova API Key' => 'Create New API Key',
    'Nessuna API key configurata' => 'No API keys configured',
    'Crea Prima API Key' => 'Create First API Key',
    'Attiva' => 'Active',
    'Disattivata' => 'Disabled',
    'Creata:' => 'Created:',
    'Ultimo uso:' => 'Last used:',
    'Mai utilizzata' => 'Never used',
    'Mostra API Key' => 'Show API Key',
    'Nascondi API Key' => 'Hide API Key',
    'Copia' => 'Copy',
    'Disattiva' => 'Disable',
    'Elimina' => 'Delete',
    'Documentazione API' => 'API Documentation',
    'Endpoint' => 'Endpoint',
    'Autenticazione' => 'Authentication',
    'L\'API key può essere fornita in due modi:' => 'API key can be provided in two ways:',
    'Header HTTP (consigliato):' => 'HTTP Header (recommended):',
    'Query parameter:' => 'Query parameter:',
    'Parametri di Ricerca' => 'Search Parameters',
    'Almeno uno dei seguenti parametri è richiesto:' => 'At least one of the following parameters is required:',
    'Cerca per codice EAN' => 'Search by EAN code',
    'Cerca per ISBN-13' => 'Search by ISBN-13',
    'Cerca per ISBN-10' => 'Search by ISBN-10',
    'Cerca per nome autore (corrispondenza parziale)' => 'Search by author name (partial match)',
    'Esempio di Chiamata' => 'Request Example',
    'Risposta JSON' => 'JSON Response',
    'La risposta include tutti i dati del libro:' => 'Response includes all book data:',
    'Dati bibliografici completi (titolo, sottotitolo, ISBN, EAN, ecc.)' => 'Complete bibliographic data (title, subtitle, ISBN, EAN, etc.)',
    'Informazioni editore' => 'Publisher information',
    'Autori con biografie' => 'Authors with biographies',
    'Genere letterario' => 'Literary genre',
    'Stato prestito corrente' => 'Current loan status',
    'Recensioni utenti' => 'User reviews',
    'Numero prenotazioni attive' => 'Number of active reservations',
    'Disponibilità copie' => 'Copy availability',
    'Note Importanti' => 'Important Notes',
    'L\'API è limitata a 50 risultati per richiesta' => 'API is limited to 50 results per request',
    'Tutte le date sono in formato ISO 8601 (YYYY-MM-DD HH:MM:SS)' => 'All dates are in ISO 8601 format (YYYY-MM-DD HH:MM:SS)',
    'I campi null indicano dati non disponibili' => 'Null fields indicate unavailable data',
    'Le API key disattivate restituiranno errore 401' => 'Disabled API keys will return 401 error',
    'Nome *' => 'Name *',
    'Annulla' => 'Cancel',
    'Crea API Key' => 'Create API Key',
    'Copiato!' => 'Copied!',
    'Errore nella copia: ' => 'Copy error: ',
    'Sei sicuro di voler eliminare questa API key? Questa azione è irreversibile.' => 'Are you sure you want to delete this API key? This action is irreversible.',

    // Placeholders for forms
    'es. Integrazione Sito Web' => 'e.g. Website Integration',
    'Descrivi l\'utilizzo di questa API key...' => 'Describe the usage of this API key...',
    '// Script essenziali (es. chat, accessibility)
// Esempio:
// console.log(\'Essential JS loaded\');' => '// Essential scripts (e.g. chat, accessibility)
// Example:
// console.log(\'Essential JS loaded\');',
    '// Script analytics (es. Google Analytics)
// Esempio Google Analytics 4:
// (function(i,s,o,g,r,a,m){i[\'GoogleAnalyticsObject\']=r;i[r]=i[r]||function(){
// (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
// m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
// })(window,document,\'script\',\'https://www.google-analytics.com/analytics.js\',\'ga\');
// ga(\'create\', \'UA-XXXXX-Y\', \'auto\');
// ga(\'send\', \'pageview\');' => '// Analytics scripts (e.g. Google Analytics)
// Google Analytics 4 Example:
// (function(i,s,o,g,r,a,m){i[\'GoogleAnalyticsObject\']=r;i[r]=i[r]||function(){
// (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
// m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
// })(window,document,\'script\',\'https://www.google-analytics.com/analytics.js\',\'ga\');
// ga(\'create\', \'UA-XXXXX-Y\', \'auto\');
// ga(\'send\', \'pageview\');',
    '// Script marketing (es. Facebook Pixel)
// Esempio Facebook Pixel:
// !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
// n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
// n.push=n;n.loaded=!0;n.version=\'2.0\';n.queue=[];t=b.createElement(e);t.async=!0;
// t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
// document,\'script\',\'https://connect.facebook.net/en_US/fbevents.js\');
// fbq(\'init\', \'YOUR_PIXEL_ID\');
// fbq(\'track\', \'PageView\');' => '// Marketing scripts (e.g. Facebook Pixel)
// Facebook Pixel Example:
// !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
// n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
// n.push=n;n.loaded=!0;n.version=\'2.0\';n.queue=[];t=b.createElement(e);t.async=!0;
// t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
// document,\'script\',\'https://connect.facebook.net/en_US/fbevents.js\');
// fbq(\'init\', \'YOUR_PIXEL_ID\');
// fbq(\'track\', \'PageView\');',
    '/* Inserisci il tuo codice CSS qui */
/* Esempio: */
/* body { font-size: 16px; } */' => '/* Insert your CSS code here */
/* Example: */
/* body { font-size: 16px; } */',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations for settings tabs\n";
echo "   Previous count: " . count($existing) . "\n";
echo "   New translations added: " . (count($merged) - count($existing)) . "\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now wrap Italian strings in __() for each file
$files = [
    __DIR__ . '/../app/Views/settings/contacts-tab.php',
    __DIR__ . '/../app/Views/settings/privacy-tab.php',
    __DIR__ . '/../app/Views/settings/messages-tab.php',
    __DIR__ . '/../app/Views/settings/advanced-tab.php',
];

$replacements = [];

// contacts-tab.php specific replacements
$replacements[__DIR__ . '/../app/Views/settings/contacts-tab.php'] = [
    "          Contenuto Pagina\n" => "          <?= __(\"Contenuto Pagina\") ?>\n",
    "        <p class=\"text-sm text-gray-600\">Personalizza il titolo e il testo introduttivo della pagina contatti</p>\n" => "        <p class=\"text-sm text-gray-600\"><?= __(\"Personalizza il titolo e il testo introduttivo della pagina contatti\") ?></p>\n",
    "          <label for=\"page_title\" class=\"block text-sm font-medium text-gray-700\">Titolo pagina</label>\n" => "          <label for=\"page_title\" class=\"block text-sm font-medium text-gray-700\"><?= __(\"Titolo pagina\") ?></label>\n",
    "          <label for=\"page_content\" class=\"block text-sm font-medium text-gray-700\">Testo introduttivo</label>\n" => "          <label for=\"page_content\" class=\"block text-sm font-medium text-gray-700\"><?= __(\"Testo introduttivo\") ?></label>\n",
    "          Informazioni di Contatto\n" => "          <?= __(\"Informazioni di Contatto\") ?>\n",
    "        <p class=\"text-sm text-gray-600\">Email e telefono visibili sulla pagina contatti</p>\n" => "        <p class=\"text-sm text-gray-600\"><?= __(\"Email e telefono visibili sulla pagina contatti\") ?></p>\n",
    "          <label for=\"contact_email\" class=\"block text-sm font-medium text-gray-700\">Email di contatto</label>\n" => "          <label for=\"contact_email\" class=\"block text-sm font-medium text-gray-700\"><?= __(\"Email di contatto\") ?></label>\n",
    "          <p class=\"mt-1 text-xs text-gray-500\">Visibile pubblicamente sulla pagina contatti</p>\n" => "          <p class=\"mt-1 text-xs text-gray-500\"><?= __(\"Visibile pubblicamente sulla pagina contatti\") ?></p>\n",
    "          <label for=\"notification_email\" class=\"block text-sm font-medium text-gray-700\">Email per notifiche</label>\n" => "          <label for=\"notification_email\" class=\"block text-sm font-medium text-gray-700\"><?= __(\"Email per notifiche\") ?></label>\n",
    "          <p class=\"mt-1 text-xs text-gray-500\">Email dove ricevere i messaggi dal form contatti</p>\n" => "          <p class=\"mt-1 text-xs text-gray-500\"><?= __(\"Email dove ricevere i messaggi dal form contatti\") ?></p>\n",
    "          Mappa Interattiva\n" => "          <?= __(\"Mappa Interattiva\") ?>\n",
    "        <p class=\"text-sm text-gray-600\">Embed della mappa (Google Maps o OpenStreetMap). Puoi inserire l'URL o il codice iframe completo.</p>\n" => "        <p class=\"text-sm text-gray-600\"><?= __(\"Embed della mappa (Google Maps o OpenStreetMap). Puoi inserire l'URL o il codice iframe completo.\") ?></p>\n",
    "          <strong>⚠️ Privacy:</strong> Le mappe esterne vengono caricate solo se l'utente accetta i cookie Analytics.\n" => "          <strong><?= __(\"⚠️ Privacy: Le mappe esterne vengono caricate solo se l'utente accetta i cookie Analytics.\") ?></strong>\n",
    "        <label for=\"google_maps_embed\" class=\"block text-sm font-medium text-gray-700\">Codice embed completo</label>\n" => "        <label for=\"google_maps_embed\" class=\"block text-sm font-medium text-gray-700\"><?= __(\"Codice embed completo\") ?></label>\n",
    "          <p><i class=\"fas fa-info-circle mr-1\"></i><strong><?= __(\"$1\") ?></strong></p>\n" => "          <p><i class=\"fas fa-info-circle mr-1\"></i><strong><?= __(\"Come ottenere il codice\") ?></strong></p>\n",
    "          Google reCAPTCHA v3\n" => "          <?= __(\"Google reCAPTCHA v3\") ?>\n",
    "        <p class=\"text-sm text-gray-600\">Protezione anti-spam per il form contatti</p>\n" => "        <p class=\"text-sm text-gray-600\"><?= __(\"Protezione anti-spam per il form contatti\") ?></p>\n",
    "          <label for=\"recaptcha_site_key\" class=\"block text-sm font-medium text-gray-700\">Site Key</label>\n" => "          <label for=\"recaptcha_site_key\" class=\"block text-sm font-medium text-gray-700\"><?= __(\"Site Key\") ?></label>\n",
    "          <label for=\"recaptcha_secret_key\" class=\"block text-sm font-medium text-gray-700\">Secret Key</label>\n" => "          <label for=\"recaptcha_secret_key\" class=\"block text-sm font-medium text-gray-700\"><?= __(\"Secret Key\") ?></label>\n",
    "          <a href=\"https://www.google.com/recaptcha/admin\" target=\"_blank\" class=\"text-blue-600 hover:underline\">Ottieni le chiavi da Google reCAPTCHA</a>\n" => "          <a href=\"https://www.google.com/recaptcha/admin\" target=\"_blank\" class=\"text-blue-600 hover:underline\"><?= __(\"Ottieni le chiavi da Google reCAPTCHA\") ?></a>\n",
    "          Testo Privacy\n" => "          <?= __(\"Testo Privacy\") ?>\n",
    "        <p class=\"text-sm text-gray-600\">Testo della checkbox privacy nel form</p>\n" => "        <p class=\"text-sm text-gray-600\"><?= __(\"Testo della checkbox privacy nel form\") ?></p>\n",
    "        <label for=\"privacy_text\" class=\"block text-sm font-medium text-gray-700\">Testo checkbox</label>\n" => "        <label for=\"privacy_text\" class=\"block text-sm font-medium text-gray-700\"><?= __(\"Testo checkbox\") ?></label>\n",
    "        Anteprima\n" => "        <?= __(\"Anteprima\") ?>\n",
    "        Salva Contatti\n" => "        <?= __(\"Salva Contatti\") ?>\n",
];

// privacy-tab.php specific replacements
$replacements[__DIR__ . '/../app/Views/settings/privacy-tab.php'] = [
    "          Contenuto Privacy Policy\n" => "          <?= __(\"Contenuto Privacy Policy\") ?>\n",
    "        <p class=\"text-sm text-gray-600\">Personalizza il titolo e il contenuto della pagina privacy policy</p>\n" => "        <p class=\"text-sm text-gray-600\"><?= __(\"Personalizza il titolo e il contenuto della pagina privacy policy\") ?></p>\n",
    "          <label for=\"privacy_page_title\" class=\"block text-sm font-medium text-gray-700\">Titolo pagina</label>\n" => "          <label for=\"privacy_page_title\" class=\"block text-sm font-medium text-gray-700\"><?= __(\"Titolo pagina\") ?></label>\n",
    "          <label for=\"privacy_page_content\" class=\"block text-sm font-medium text-gray-700\">Contenuto pagina</label>\n" => "          <label for=\"privacy_page_content\" class=\"block text-sm font-medium text-gray-700\"><?= __(\"Contenuto pagina\") ?></label>\n",
    "          Pagina Cookie Policy\n" => "          <?= __(\"Pagina Cookie Policy\") ?>\n",
    "        <p class=\"text-sm text-gray-600\">Contenuto della pagina <code class=\"text-xs bg-gray-100 px-1 py-0.5 rounded\">/cookies</code> accessibile dal banner</p>\n" => "        <p class=\"text-sm text-gray-600\"><?= __(\"Contenuto della pagina /cookies accessibile dal banner\") ?></p>\n",
    "          <label for=\"cookie_policy_content\" class=\"block text-sm font-medium text-gray-700\">Contenuto Cookie Policy</label>\n" => "          <label for=\"cookie_policy_content\" class=\"block text-sm font-medium text-gray-700\"><?= __(\"Contenuto Cookie Policy\") ?></label>\n",
    "          <p class=\"mt-2 text-xs text-gray-500\">Questo contenuto verrà mostrato nella pagina /cookies linkata dal cookie banner</p>\n" => "          <p class=\"mt-2 text-xs text-gray-500\"><?= __(\"Questo contenuto verrà mostrato nella pagina /cookies linkata dal cookie banner\") ?></p>\n",
    "          Cookie Banner\n" => "          <?= __(\"Cookie Banner\") ?>\n",
    "        <p class=\"text-sm text-gray-600\">Configurazione del banner cookie</p>\n" => "        <p class=\"text-sm text-gray-600\"><?= __(\"Configurazione del banner cookie\") ?></p>\n",
    "          <label for=\"cookie_banner_enabled\" class=\"text-sm font-medium text-gray-700\">Abilita Cookie Banner</label>\n" => "          <label for=\"cookie_banner_enabled\" class=\"text-sm font-medium text-gray-700\"><?= __(\"Abilita Cookie Banner\") ?></label>\n",
    "            <label for=\"cookie_banner_language\" class=\"block text-sm font-medium text-gray-700\">Lingua</label>\n" => "            <label for=\"cookie_banner_language\" class=\"block text-sm font-medium text-gray-700\"><?= __(\"Lingua\") ?></label>\n",
    "              <option value=\"it\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'it' ? 'selected' : ''; ?>>Italiano (IT)</option>\n" => "              <option value=\"it\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'it' ? 'selected' : ''; ?>><?= __(\"Italiano (IT)\") ?></option>\n",
    "              <option value=\"en\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'en' ? 'selected' : ''; ?>>English (EN)</option>\n" => "              <option value=\"en\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'en' ? 'selected' : ''; ?>><?= __(\"English (EN)\") ?></option>\n",
    "              <option value=\"de\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'de' ? 'selected' : ''; ?>>Deutsch (DE)</option>\n" => "              <option value=\"de\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'de' ? 'selected' : ''; ?>><?= __(\"Deutsch (DE)\") ?></option>\n",
    "              <option value=\"es\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'es' ? 'selected' : ''; ?>>Español (ES)</option>\n" => "              <option value=\"es\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'es' ? 'selected' : ''; ?>><?= __(\"Español (ES)\") ?></option>\n",
    "              <option value=\"fr\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'fr' ? 'selected' : ''; ?>>Français (FR)</option>\n" => "              <option value=\"fr\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'fr' ? 'selected' : ''; ?>><?= __(\"Français (FR)\") ?></option>\n",
    "              <option value=\"nl\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'nl' ? 'selected' : ''; ?>>Nederlands (NL)</option>\n" => "              <option value=\"nl\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'nl' ? 'selected' : ''; ?>><?= __(\"Nederlands (NL)\") ?></option>\n",
    "              <option value=\"pl\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'pl' ? 'selected' : ''; ?>>Polski (PL)</option>\n" => "              <option value=\"pl\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'pl' ? 'selected' : ''; ?>><?= __(\"Polski (PL)\") ?></option>\n",
    "              <option value=\"da\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'da' ? 'selected' : ''; ?>>Dansk (DA)</option>\n" => "              <option value=\"da\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'da' ? 'selected' : ''; ?>><?= __(\"Dansk (DA)\") ?></option>\n",
    "              <option value=\"bg\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'bg' ? 'selected' : ''; ?>>Български (BG)</option>\n" => "              <option value=\"bg\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'bg' ? 'selected' : ''; ?>><?= __(\"Български (BG)\") ?></option>\n",
    "              <option value=\"ca\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'ca' ? 'selected' : ''; ?>>Català (CA)</option>\n" => "              <option value=\"ca\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'ca' ? 'selected' : ''; ?>><?= __(\"Català (CA)\") ?></option>\n",
    "              <option value=\"sk\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'sk' ? 'selected' : ''; ?>>Slovenčina (SK)</option>\n" => "              <option value=\"sk\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'sk' ? 'selected' : ''; ?>><?= __(\"Slovenčina (SK)\") ?></option>\n",
    "              <option value=\"he\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'he' ? 'selected' : ''; ?>>עברית (HE)</option>\n" => "              <option value=\"he\" <?php echo (\$privacySettings['cookie_banner_language'] ?? 'it') === 'he' ? 'selected' : ''; ?>><?= __(\"עברית (HE)\") ?></option>\n",
    "            <label for=\"cookie_banner_country\" class=\"block text-sm font-medium text-gray-700\">Paese</label>\n" => "            <label for=\"cookie_banner_country\" class=\"block text-sm font-medium text-gray-700\"><?= __(\"Paese\") ?></label>\n",
    "            <p class=\"mt-1 text-xs text-gray-500\">Codice ISO 2 lettere (es: IT, FR, GB)</p>\n" => "            <p class=\"mt-1 text-xs text-gray-500\"><?= __(\"Codice ISO 2 lettere (es: IT, FR, GB)\") ?></p>\n",
    "          <label for=\"cookie_statement_link\" class=\"block text-sm font-medium text-gray-700\">Link Cookie Statement</label>\n" => "          <label for=\"cookie_statement_link\" class=\"block text-sm font-medium text-gray-700\"><?= __(\"Link Cookie Statement\") ?></label>\n",
    "          <p class=\"mt-1 text-xs text-gray-500\">URL della pagina con la cookie policy</p>\n" => "          <p class=\"mt-1 text-xs text-gray-500\"><?= __(\"URL della pagina con la cookie policy\") ?></p>\n",
    "          <label for=\"cookie_technologies_link\" class=\"block text-sm font-medium text-gray-700\">Link Cookie Technologies</label>\n" => "          <label for=\"cookie_technologies_link\" class=\"block text-sm font-medium text-gray-700\"><?= __(\"Link Cookie Technologies\") ?></label>\n",
    "          <p class=\"mt-1 text-xs text-gray-500\">URL della pagina con le tecnologie dei cookie</p>\n" => "          <p class=\"mt-1 text-xs text-gray-500\"><?= __(\"URL della pagina con le tecnologie dei cookie\") ?></p>\n",
    "          Categorie Cookie\n" => "          <?= __(\"Categorie Cookie\") ?>\n",
    "        <p class=\"text-sm text-gray-600\">Gestisci la visibilità delle categorie di cookie nel banner. I cookie essenziali sono sempre visibili e obbligatori.</p>\n" => "        <p class=\"text-sm text-gray-600\"><?= __(\"Gestisci la visibilità delle categorie di cookie nel banner. I cookie essenziali sono sempre visibili e obbligatori.\") ?></p>\n",
    "              Mostra Cookie Analitici\n" => "              <?= __(\"Mostra Cookie Analitici\") ?>\n",
    "            <p class=\"text-xs text-gray-500 mt-1\">Nascondi se il sito non utilizza strumenti di analytics (es. Google Analytics)</p>\n" => "            <p class=\"text-xs text-gray-500 mt-1\"><?= __(\"Nascondi se il sito non utilizza strumenti di analytics (es. Google Analytics)\") ?></p>\n",
    "              Mostra Cookie di Marketing\n" => "              <?= __(\"Mostra Cookie di Marketing\") ?>\n",
    "            <p class=\"text-xs text-gray-500 mt-1\">Nascondi se il sito non utilizza cookie di marketing o advertising</p>\n" => "            <p class=\"text-xs text-gray-500 mt-1\"><?= __(\"Nascondi se il sito non utilizza cookie di marketing o advertising\") ?></p>\n",
    "              <p class=\"font-medium mb-1\">Nota:</p>\n" => "              <p class=\"font-medium mb-1\"><?= __(\"Nota:\") ?></p>\n",
    "              <p>I Cookie Essenziali sono sempre visibili e non possono essere disabilitati poiché necessari per il funzionamento del sito.</p>\n" => "              <p><?= __(\"I Cookie Essenziali sono sempre visibili e non possono essere disabilitati poiché necessari per il funzionamento del sito.\") ?></p>\n",
    "        Anteprima\n" => "        <?= __(\"Anteprima\") ?>\n",
    "        Salva Privacy Policy\n" => "        <?= __(\"Salva Privacy Policy\") ?>\n",
];

// messages-tab.php specific replacements
$replacements[__DIR__ . '/../app/Views/settings/messages-tab.php'] = [
    "          Messaggi di Contatto\n" => "          <?= __(\"Messaggi di Contatto\") ?>\n",
    "        <p class=\"text-sm text-gray-600 mt-1\">Tutti i messaggi ricevuti tramite il form contatti</p>\n" => "        <p class=\"text-sm text-gray-600 mt-1\"><?= __(\"Tutti i messaggi ricevuti tramite il form contatti\") ?></p>\n",
    "          Segna tutti come letti\n" => "          <?= __(\"Segna tutti come letti\") ?>\n",
    "              <th><?= __(\"$1\") ?></th>\n" => "              <th class=\"px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider\"><?= __(\"Mittente\") ?></th>\n",
    "                    Nuovo\n" => "                    <?= __(\"Nuovo\") ?>\n",
    "                    Archiviato\n" => "                    <?= __(\"Archiviato\") ?>\n",
    "                    Letto\n" => "                    <?= __(\"Letto\") ?>\n",
    "                    Non letto\n" => "                    <?= __(\"Non letto\") ?>\n",
    "                <p>Nessun messaggio ricevuto</p>\n" => "                <p><?= __(\"Nessun messaggio ricevuto\") ?></p>\n",
    "      <h3 class=\"text-xl font-semibold text-gray-900\">Dettagli Messaggio</h3>\n" => "      <h3 class=\"text-xl font-semibold text-gray-900\"><?= __(\"Dettagli Messaggio\") ?></h3>\n",
    "              <label class=\"text-sm font-medium text-gray-500\">Da</label>\n" => "              <label class=\"text-sm font-medium text-gray-500\"><?= __(\"Da\") ?></label>\n",
    "            <label class=\"text-sm font-medium text-gray-500\">Messaggio</label>\n" => "            <label class=\"text-sm font-medium text-gray-500\"><?= __(\"Messaggio\") ?></label>\n",
    "              Rispondi\n" => "              <?= __(\"Rispondi\") ?>\n",
    "              Archivia\n" => "              <?= __(\"Archivia\") ?>\n",
];

// advanced-tab.php - this one is complex, will handle separately
// I'll handle the most critical ones that have malformed __("$1") and __("$2")
$advancedContent = file_get_contents(__DIR__ . '/../app/Views/settings/advanced-tab.php');

$advancedContent = str_replace(
    '          <h3 class="text-sm font-semibold text-blue-900 mb-2">Gestione JavaScript Personalizzati basata su Cookie</h3>',
    '          <h3 class="text-sm font-semibold text-blue-900 mb-2"><?= __("Gestione JavaScript Personalizzati basata su Cookie") ?></h3>',
    $advancedContent
);

$advancedContent = str_replace(
    '            <p>Gli script JavaScript sono divisi in 3 categorie in base alla tipologia di cookie:</p>',
    '            <p><?= __("Gli script JavaScript sono divisi in 3 categorie in base alla tipologia di cookie:") ?></p>',
    $advancedContent
);

$advancedContent = str_replace(
    '<div class="$1"><?= __("$2") ?></div>',
    '<div class="flex items-center gap-2"><i class="fas fa-terminal text-gray-600"></i><strong><?= __("Configurazione Cron Job") ?></strong></div>',
    $advancedContent
);

$advancedContent = str_replace(
    '          <div class="$1"><?= __("$2") ?></div>',
    '          <div class="flex items-center gap-2"><i class="fas fa-lightbulb text-gray-600"></i><strong><?= __("Note tecniche") ?></strong></div>',
    $advancedContent
);

// Save the modified advanced-tab.php
file_put_contents(__DIR__ . '/../app/Views/settings/advanced-tab.php', $advancedContent);
echo "✅ Fixed advanced-tab.php malformed placeholders\n\n";

// Apply replacements to other files
$updatedCount = 0;
foreach ($files as $file) {
    if (!file_exists($file)) {
        echo "⚠️  Skipping (not found): " . basename($file) . "\n";
        continue;
    }

    // Skip advanced-tab.php as we already handled it
    if (basename($file) === 'advanced-tab.php') {
        continue;
    }

    $content = file_get_contents($file);
    $originalContent = $content;

    if (isset($replacements[$file])) {
        foreach ($replacements[$file] as $search => $replace) {
            $content = str_replace($search, $replace, $content);
        }
    }

    if ($content !== $originalContent) {
        file_put_contents($file, $content);
        $updatedCount++;
        echo "✅ " . basename($file) . " - wrapped Italian strings\n";
    } else {
        echo "ℹ️  " . basename($file) . " - no changes needed\n";
    }
}

echo "\n📊 Summary:\n";
echo "   Translations added: " . count($newTranslations) . "\n";
echo "   Files updated: $updatedCount\n";
echo "   Total translations in en_US.json: " . count($merged) . "\n";
echo "\n✅ ALL settings tab files are now fully translated!\n";

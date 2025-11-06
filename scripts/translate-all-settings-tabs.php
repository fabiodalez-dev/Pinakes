#!/usr/bin/env php
<?php
/**
 * Translate All Settings Tabs - Complete i18n for Settings Pages
 *
 * This script translates ALL Italian strings in settings tabs to use __() function.
 * Covers: advanced-tab, labels (inline), templates tab, and other remaining Italian strings.
 *
 * Run: php scripts/translate-all-settings-tabs.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

// File paths
$files = [
    'advanced-tab' => __DIR__ . '/../app/Views/settings/advanced-tab.php',
    'index' => __DIR__ . '/../app/Views/settings/index.php', // For labels tab and templates
];

$stats = [
    'files_processed' => 0,
    'total_replacements' => 0,
];

echo "\n╔════════════════════════════════════════════════════════════╗\n";
echo "║  TRANSLATE ALL SETTINGS TABS - Complete i18n             ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// ========================================
// ADVANCED TAB TRANSLATIONS
// ========================================
$advancedTabReplacements = [
    // Page header - already has __() but has hardcoded Italian
    "JavaScript Essenziali" => "<?= __(\"JavaScript Essenziali\") ?>",
    "JavaScript Analitici" => "<?= __(\"JavaScript Analitici\") ?>",
    "JavaScript Marketing" => "<?= __(\"JavaScript Marketing\") ?>",
    "CSS Personalizzato" => "<?= __(\"CSS Personalizzato\") ?>",
    "Notifiche Prestiti" => "<?= __(\"Notifiche Prestiti\") ?>",
    "Codice JavaScript" => "<?= __(\"Codice JavaScript\") ?>",
    "Codice CSS" => "<?= __(\"Codice CSS\") ?>",

    // Descriptions (hardcoded Italian text)
    '"Script necessari per il funzionamento del sito (es. chat support, accessibility tools)"'
        => '"<?= __(\"Script necessari per il funzionamento del sito (es. chat support, accessibility tools)\") ?>"',
    '"Script di analisi e statistiche (es. Google Analytics, Matomo, Hotjar)"'
        => '"<?= __(\"Script di analisi e statistiche (es. Google Analytics, Matomo, Hotjar)\") ?>"',
    '"Script pubblicitari e remarketing (es. Facebook Pixel, Google Ads, LinkedIn Insight)"'
        => '"<?= __(\"Script pubblicitari e remarketing (es. Facebook Pixel, Google Ads, LinkedIn Insight)\") ?>"',
    '"Codice CSS da applicare a tutte le pagine del frontend"'
        => '"<?= __(\"Codice CSS da applicare a tutte le pagine del frontend\") ?>"',
    '"Configura quando inviare l\'avviso di scadenza prestiti agli utenti"'
        => '"<?= __(\"Configura quando inviare l\'avviso di scadenza prestiti agli utenti\") ?>"',

    // Info boxes (hardcoded Italian)
    'Giorni di preavviso per scadenza prestito' => '<?= __("Giorni di preavviso per scadenza prestito") ?>',
    'giorni prima della scadenza' => '<?= __("giorni prima della scadenza") ?>',
    'Valore compreso tra 1 e 30 giorni. Consigliato: 3 giorni'
        => '<?= __("Valore compreso tra 1 e 30 giorni. Consigliato: 3 giorni") ?>',

    // Sitemap section (hardcoded Italian)
    'Sitemap XML' => '<?= __("Sitemap XML") ?>',
    'Mappa del sito per i motori di ricerca' => '<?= __("Mappa del sito per i motori di ricerca") ?>',
    'URL pubblico:' => '<?= __("URL pubblico:") ?>',
    'Percorso file:' => '<?= __("Percorso file:") ?>',
    'Ultima generazione:' => '<?= __("Ultima generazione:") ?>',
    'Mai generata' => '<?= __("Mai generata") ?>',
    'File sitemap presente' => '<?= __("File sitemap presente") ?>',
    'File sitemap non trovato' => '<?= __("File sitemap non trovato") ?>',
    'Usa il pulsante "Rigenera adesso" per crearla'
        => '<?= __("Usa il pulsante \"Rigenera adesso\" per crearla") ?>',
    'Rigenera Sitemap' => '<?= __("Rigenera Sitemap") ?>',
    'Rigenera adesso' => '<?= __("Rigenera adesso") ?>',

    // API section (hardcoded Italian)
    'API Pubblica' => '<?= __("API Pubblica") ?>',
    'Gestisci l\'accesso all\'API per cercare libri via EAN, ISBN e autore'
        => '<?= __("Gestisci l\'accesso all\'API per cercare libri via EAN, ISBN e autore") ?>',
    'Stato API' => '<?= __("Stato API") ?>',
    'Abilita o disabilita l\'accesso all\'API pubblica'
        => '<?= __("Abilita o disabilita l\'accesso all\'API pubblica") ?>',
    'API Keys' => '<?= __("API Keys") ?>',
    'Crea Nuova API Key' => '<?= __("Crea Nuova API Key") ?>',
    'Nessuna API key configurata' => '<?= __("Nessuna API key configurata") ?>',
    'Crea Prima API Key' => '<?= __("Crea Prima API Key") ?>',
    'Attiva' => '<?= __("Attiva") ?>',
    'Disattivata' => '<?= __("Disattivata") ?>',
    'Creata:' => '<?= __("Creata:") ?>',
    'Ultimo uso:' => '<?= __("Ultimo uso:") ?>',
    'Mai utilizzata' => '<?= __("Mai utilizzata") ?>',
    'Mostra API Key' => '<?= __("Mostra API Key") ?>',
    'Nascondi API Key' => '<?= __("Nascondi API Key") ?>',
    'Copia' => '<?= __("Copia") ?>',
    'Disattiva' => '<?= __("Disattiva") ?>',
    'Attiva' => '<?= __("Attiva") ?>',
    'Elimina' => '<?= __("Elimina") ?>',
    'Documentazione API' => '<?= __("Documentazione API") ?>',
    'Endpoint' => '<?= __("Endpoint") ?>',
    'Autenticazione' => '<?= __("Autenticazione") ?>',
    'L\'API key può essere fornita in due modi:'
        => '<?= __("L\'API key può essere fornita in due modi:") ?>',
    'Header HTTP (consigliato):' => '<?= __("Header HTTP (consigliato):") ?>',
    'Query parameter:' => '<?= __("Query parameter:") ?>',
    'Parametri di Ricerca' => '<?= __("Parametri di Ricerca") ?>',
    'Almeno uno dei seguenti parametri è richiesto:'
        => '<?= __("Almeno uno dei seguenti parametri è richiesto:") ?>',
    'Cerca per codice EAN' => '<?= __("Cerca per codice EAN") ?>',
    'Cerca per ISBN-13' => '<?= __("Cerca per ISBN-13") ?>',
    'Cerca per ISBN-10' => '<?= __("Cerca per ISBN-10") ?>',
    'Cerca per nome autore (corrispondenza parziale)'
        => '<?= __("Cerca per nome autore (corrispondenza parziale)") ?>',
    'Esempio di Chiamata' => '<?= __("Esempio di Chiamata") ?>',
    'Risposta JSON' => '<?= __("Risposta JSON") ?>',
    'La risposta include tutti i dati del libro:'
        => '<?= __("La risposta include tutti i dati del libro:") ?>',
    'Dati bibliografici completi (titolo, sottotitolo, ISBN, EAN, ecc.)'
        => '<?= __("Dati bibliografici completi (titolo, sottotitolo, ISBN, EAN, ecc.)") ?>',
    'Informazioni editore' => '<?= __("Informazioni editore") ?>',
    'Autori con biografie' => '<?= __("Autori con biografie") ?>',
    'Genere letterario' => '<?= __("Genere letterario") ?>',
    'Stato prestito corrente' => '<?= __("Stato prestito corrente") ?>',
    'Recensioni utenti' => '<?= __("Recensioni utenti") ?>',
    'Numero prenotazioni attive' => '<?= __("Numero prenotazioni attive") ?>',
    'Disponibilità copie' => '<?= __("Disponibilità copie") ?>',
    'Note Importanti' => '<?= __("Note Importanti") ?>',
    'L\'API è limitata a 50 risultati per richiesta'
        => '<?= __("L\'API è limitata a 50 risultati per richiesta") ?>',
    'Tutte le date sono in formato ISO 8601 (YYYY-MM-DD HH:MM:SS)'
        => '<?= __("Tutte le date sono in formato ISO 8601 (YYYY-MM-DD HH:MM:SS)") ?>',
    'I campi null indicano dati non disponibili'
        => '<?= __("I campi null indicano dati non disponibili") ?>',
    'Le API key disattivate restituiranno errore 401'
        => '<?= __("Le API key disattivate restituiranno errore 401") ?>',

    // API Modal (hardcoded Italian)
    'Nome *' => '<?= __("Nome *") ?>',
    'Annulla' => '<?= __("Annulla") ?>',

    // Button text
    'Salva Impostazioni Avanzate' => '<?= __("Salva Impostazioni Avanzate") ?>',
    'Auto-attivazione:' => '<?= __("Auto-attivazione:") ?>',
    'Se compili questo campo, il toggle "Mostra Cookie Analitici" in Privacy verrà attivato automaticamente.'
        => '<?= __("Se compili questo campo, il toggle \"Mostra Cookie Analitici\" in Privacy verrà attivato automaticamente.") ?>',
    'Se compili questo campo, il toggle "Mostra Cookie Marketing" in Privacy verrà attivato automaticamente.'
        => '<?= __("Se compili questo campo, il toggle \"Mostra Cookie Marketing\" in Privacy verrà attivato automaticamente.") ?>',
];

echo "Processing: advanced-tab.php\n";
$content = file_get_contents($files['advanced-tab']);
$originalContent = $content;

foreach ($advancedTabReplacements as $search => $replace) {
    $count = 0;
    $content = str_replace($search, $replace, $content, $count);
    if ($count > 0) {
        echo "  ✓ Replaced: " . substr($search, 0, 50) . "... ($count occurrence(s))\n";
        $stats['total_replacements'] += $count;
    }
}

if ($content !== $originalContent) {
    file_put_contents($files['advanced-tab'], $content);
    echo "  ✅ Saved advanced-tab.php\n\n";
    $stats['files_processed']++;
}

// ========================================
// INDEX.PHP - LABELS TAB FIX + TEMPLATES TAB
// ========================================
echo "Processing: index.php (labels tab + templates tab)\n";
$content = file_get_contents($files['index']);
$originalContent = $content;

// FIX 1: Labels tab - Fix HTML entity issue (lines 485-490)
// The problem is that 'desc' contains literal PHP tags as strings instead of being evaluated
$labelFormatsFixSearch = <<<'PHP'
                $labelFormats = [
                  ['width' => 25, 'height' => 38, 'name' => '25×38mm', 'desc' => '<?= __("Standard dorso libri (più comune)") ?>'],
                  ['width' => 50, 'height' => 25, 'name' => '50×25mm', 'desc' => '<?= __("Formato orizzontale per dorso") ?>'],
                  ['width' => 70, 'height' => 36, 'name' => '70×36mm', 'desc' => '<?= __("Etichette interne grandi (Herma 4630, Avery 3490)") ?>'],
                  ['width' => 25, 'height' => 40, 'name' => '25×40mm', 'desc' => '<?= __("Standard Tirrenia catalogazione") ?>'],
                  ['width' => 34, 'height' => 48, 'name' => '34×48mm', 'desc' => '<?= __("Formato quadrato Tirrenia") ?>'],
                  ['width' => 52, 'height' => 30, 'name' => '52×30mm', 'desc' => '<?= __("Formato biblioteche scolastiche (compatibile A4)") ?>'],
                ];
PHP;

$labelFormatsFixReplace = <<<'PHP'
                $labelFormats = [
                  ['width' => 25, 'height' => 38, 'name' => '25×38mm', 'desc' => __("Standard dorso libri (più comune)")],
                  ['width' => 50, 'height' => 25, 'name' => '50×25mm', 'desc' => __("Formato orizzontale per dorso")],
                  ['width' => 70, 'height' => 36, 'name' => '70×36mm', 'desc' => __("Etichette interne grandi (Herma 4630, Avery 3490)")],
                  ['width' => 25, 'height' => 40, 'name' => '25×40mm', 'desc' => __("Standard Tirrenia catalogazione")],
                  ['width' => 34, 'height' => 48, 'name' => '34×48mm', 'desc' => __("Formato quadrato Tirrenia")],
                  ['width' => 52, 'height' => 30, 'name' => '52×30mm', 'desc' => __("Formato biblioteche scolastiche (compatibili A4)")],
                ];
PHP;

$count = 0;
$content = str_replace($labelFormatsFixSearch, $labelFormatsFixReplace, $content, $count);
if ($count > 0) {
    echo "  ✓ FIXED: Labels tab HTML entity issue (removed literal PHP tags from array)\n";
    $stats['total_replacements'] += $count;
}

// FIX 2: Labels tab - Add responsive classes to label size elements
$labelSizeSearch = '<div class="flex items-center gap-2">';
$labelSizeReplace = '<div class="flex flex-col md:flex-row items-start md:items-center gap-2">';
$count = 0;
$content = str_replace($labelSizeSearch, $labelSizeReplace, $content, $count);
if ($count > 0) {
    echo "  ✓ Added responsive flex-column classes to label size selectors ($count occurrence(s))\n";
    $stats['total_replacements'] += $count;
}

// Translate remaining Italian in templates tab
$indexReplacements = [
    // Templates tab Italian (line 104, 205, etc.)
    "'Nessun logo caricato'" => "'<?= __(\"Nessun logo caricato\") ?>'",
    'Lascia vuoto per nascondere il social dal footer' => '<?= __("Lascia vuoto per nascondere il social dal footer") ?>',
];

foreach ($indexReplacements as $search => $replace) {
    $count = 0;
    $content = str_replace($search, $replace, $content, $count);
    if ($count > 0) {
        echo "  ✓ Replaced: " . substr($search, 0, 50) . "... ($count occurrence(s))\n";
        $stats['total_replacements'] += $count;
    }
}

if ($content !== $originalContent) {
    file_put_contents($files['index'], $content);
    echo "  ✅ Saved index.php\n\n";
    $stats['files_processed']++;
}

// ========================================
// SUMMARY
// ========================================
echo "\n╔════════════════════════════════════════════════════════════╗\n";
echo "║  TRANSLATION SUMMARY                                      ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";
echo "Files processed: {$stats['files_processed']}\n";
echo "Total replacements: {$stats['total_replacements']}\n";

if ($stats['total_replacements'] > 0) {
    echo "\n✅ All settings tabs translations completed!\n";
    echo "\n📝 Changes made:\n";
    echo "  1. Advanced tab: Translated all Italian strings (JS categories, API, Sitemap)\n";
    echo "  2. Labels tab: FIXED HTML entity issue (removed literal PHP tags)\n";
    echo "  3. Labels tab: Added responsive flex-column classes\n";
    echo "  4. Templates tab: Translated remaining Italian strings\n";
    echo "\n🔍 Test URLs:\n";
    echo "  - Labels: http://localhost:8000/admin/settings?tab=labels#labels\n";
    echo "  - Advanced: http://localhost:8000/admin/settings?tab=advanced#advanced\n";
    echo "  - Templates: http://localhost:8000/admin/settings?tab=templates#templates\n";
} else {
    echo "\nℹ️  No changes needed - all translations already applied.\n";
}

echo "\n";

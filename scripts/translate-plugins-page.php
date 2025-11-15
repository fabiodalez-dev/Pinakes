<?php
/**
 * Add all missing translations for admin/plugins page
 * The page already uses __() but translations are missing from en_US.json
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// All translations for plugins page (extracted from app/Views/admin/plugins.php)
$newTranslations = [
    // Page header
    'Gestione Plugin' => 'Plugin Management',
    'Plugin' => 'Plugins',
    'Gestisci le estensioni dell\'applicazione' => 'Manage application extensions',
    'Carica Plugin' => 'Upload Plugin',

    // Stats cards
    'Plugin Totali' => 'Total Plugins',
    'Plugin Attivi' => 'Active Plugins',
    'Plugin Inattivi' => 'Inactive Plugins',

    // Plugin list
    'Plugin Installati' => 'Installed Plugins',
    'Nessun plugin installato' => 'No plugins installed',
    'Inizia caricando il tuo primo plugin' => 'Get started by uploading your first plugin',
    'Attivo' => 'Active',
    'Inattivo' => 'Inactive',
    'Nessuna descrizione disponibile' => 'No description available',
    'Installato:' => 'Installed:',
    'Attivato:' => 'Activated:',

    // Action buttons
    'Disattiva' => 'Deactivate',
    'Attiva' => 'Activate',

    // Upload modal
    'Carica un file ZIP contenente il plugin. Il file deve includere un %s con le informazioni del plugin.' => 'Upload a ZIP file containing the plugin. The file must include a %s with the plugin information.',
    'Requisiti del plugin:' => 'Plugin requirements:',
    'File ZIP con struttura plugin valida' => 'ZIP file with valid plugin structure',
    'File %s nella directory root' => 'File %s in the root directory',
    'File principale PHP specificato in %s' => 'Main PHP file specified in %s',
    'Installa Plugin' => 'Install Plugin',

    // Uppy translations
    'Trascina qui il file ZIP del plugin o %{browse}' => 'Drag the plugin ZIP file here or %{browse}',
    'seleziona' => 'browse',
    'Caricamento completato' => 'Upload completed',
    'Caricamento fallito' => 'Upload failed',
    'Completato' => 'Completed',
    'Caricamento in corso...' => 'Uploading...',

    // JavaScript alerts
    'Seleziona un file ZIP del plugin.' => 'Select a plugin ZIP file.',
    'Installazione in corso...' => 'Installing...',
    'Successo' => 'Success',
    'Errore durante l\'installazione del plugin.' => 'Error during plugin installation.',
    'Conferma' => 'Confirm',
    'Vuoi attivare questo plugin?' => 'Do you want to activate this plugin?',
    'Sì, attiva' => 'Yes, activate',
    'Errore durante l\'attivazione del plugin.' => 'Error during plugin activation.',
    'Vuoi disattivare questo plugin?' => 'Do you want to deactivate this plugin?',
    'Sì, disattiva' => 'Yes, deactivate',
    'Errore durante la disattivazione del plugin.' => 'Error during plugin deactivation.',
    'Conferma Disinstallazione' => 'Confirm Uninstallation',
    'Sei sicuro di voler disinstallare' => 'Are you sure you want to uninstall',
    'Questa azione eliminerà tutti i dati del plugin e non può essere annullata.' => 'This action will delete all plugin data and cannot be undone.',
    'Sì, disinstalla' => 'Yes, uninstall',
    'Errore durante la disinstallazione del plugin.' => 'Error during plugin uninstallation.',

    // Plugin details modal
    'Metadati:' => 'Metadata:',
    'Nome:' => 'Name:',
    'Versione:' => 'Version:',
    'Autore:' => 'Author:',
    'Descrizione:' => 'Description:',
    'Richiede PHP:' => 'Requires PHP:',
    'Richiede App:' => 'Requires App:',
    'File Principale:' => 'Main File:',
    'Percorso:' => 'Path:',
    'Errore durante il caricamento dei dettagli del plugin.' => 'Error loading plugin details.',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n";

echo "\n✅ Translation complete!\n";
echo "ℹ️  Note: app/Views/admin/plugins.php already uses __() correctly - no code changes needed\n";

<?php
/**
 * Translate app/Views/admin/integrity_report.php
 * Fix all hardcoded Italian strings
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// New translations for integrity report page
$newTranslations = [
    // Page header (lines 12-21)
    'Report Integrità Dati' => 'Data Integrity Report',
    'Verifica coerenza e integrità del database' => 'Verify database consistency and integrity',
    'Esegui Manutenzione' => 'Run Maintenance',
    'Aggiorna' => 'Refresh',

    // Info section (lines 33-35)
    'Informazioni Report' => 'Report Information',
    'Generato il' => 'Generated on',

    // Issues section (lines 72-79)
    'Problemi di Integrità' => 'Integrity Issues',
    'Nessun Problema' => 'No Issues',
    '%d Problemi' => '%d Issues',

    // Success messages (lines 89-90)
    'Tutti i controlli di integrità sono passati con successo!' => 'All integrity checks passed successfully!',
    'Il database è coerente e non sono stati rilevati problemi.' => 'The database is consistent and no issues were detected.',

    // Type labels array (lines 103-108)
    'Copie Negative' => 'Negative Copies',
    'Copie Eccessive' => 'Excessive Copies',
    'Prestiti Orfani' => 'Orphan Loans',
    'Scadenza Mancante' => 'Missing Due Date',
    'Stato Incongruente' => 'Inconsistent Status',

    // Notice (line 131)
    'Clicca su "Esegui Manutenzione" per correggere automaticamente i problemi riparabili.' => 'Click "Run Maintenance" to automatically fix repairable issues.',

    // Actions section (line 142)
    'Azioni di Manutenzione' => 'Maintenance Actions',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now wrap Italian strings in integrity_report.php
$file = __DIR__ . '/../app/Views/admin/integrity_report.php';
$content = file_get_contents($file);
$original = $content;

$replacements = [
    // Page header (lines 12-21)
    '                        <h1 class="text-xl font-bold text-gray-900 dark:text-white">Report Integrità Dati</h1>' =>
        '                        <h1 class="text-xl font-bold text-gray-900 dark:text-white"><?= __("Report Integrità Dati") ?></h1>',

    '                        <p class="text-sm text-gray-500 dark:text-gray-400">Verifica coerenza e integrità del database</p>' =>
        '                        <p class="text-sm text-gray-500 dark:text-gray-400"><?= __("Verifica coerenza e integrità del database") ?></p>',

    '                        <i class="fas fa-tools mr-2"></i>Esegui Manutenzione' =>
        '                        <i class="fas fa-tools mr-2"></i><?= __("Esegui Manutenzione") ?>',

    '                        <i class="fas fa-sync-alt mr-2"></i>Aggiorna' =>
        '                        <i class="fas fa-sync-alt mr-2"></i><?= __("Aggiorna") ?>',

    // Info section (lines 33-35)
    '                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Informazioni Report</h2>' =>
        '                <h2 class="text-lg font-semibold text-gray-900 dark:text-white"><?= __("Informazioni Report") ?></h2>',

    '                    Generato il <?= date(\'d-m-Y H:i:s\', strtotime($report[\'timestamp\'])) ?>' =>
        '                    <?= __("Generato il") ?> <?= date(\'d-m-Y H:i:s\', strtotime($report[\'timestamp\'])) ?>',

    // Issues section (lines 72-79)
    '                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Problemi di Integrità</h2>' =>
        '                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white"><?= __("Problemi di Integrità") ?></h2>',

    '                            <i class="fas fa-check-circle mr-2"></i>Nessun Problema' =>
        '                            <i class="fas fa-check-circle mr-2"></i><?= __("Nessun Problema") ?>',

    '                            <i class="fas fa-exclamation-triangle mr-2"></i><?= count($report[\'consistency_issues\']) ?> Problemi' =>
        '                            <i class="fas fa-exclamation-triangle mr-2"></i><?= sprintf(__("%d Problemi"), count($report[\'consistency_issues\'])) ?>',

    // Success messages (lines 89-90)
    '                        <p class="text-gray-600 dark:text-gray-400 text-lg">Tutti i controlli di integrità sono passati con successo!</p>' =>
        '                        <p class="text-gray-600 dark:text-gray-400 text-lg"><?= __("Tutti i controlli di integrità sono passati con successo!") ?></p>',

    '                        <p class="text-sm text-gray-500 dark:text-gray-500 mt-2">Il database è coerente e non sono stati rilevati problemi.</p>' =>
        '                        <p class="text-sm text-gray-500 dark:text-gray-500 mt-2"><?= __("Il database è coerente e non sono stati rilevati problemi.") ?></p>',

    // Type labels (lines 103-108)
    '                        $typeLabels = [
                            \'negative_copies\' => \'Copie Negative\',
                            \'excess_copies\' => \'Copie Eccessive\',
                            \'orphan_loan\' => \'Prestiti Orfani\',
                            \'missing_due_date\' => \'Scadenza Mancante\',
                            \'status_mismatch\' => \'Stato Incongruente\'
                        ];' =>
    '                        $typeLabels = [
                            \'negative_copies\' => __(\'Copie Negative\'),
                            \'excess_copies\' => __(\'Copie Eccessive\'),
                            \'orphan_loan\' => __(\'Prestiti Orfani\'),
                            \'missing_due_date\' => __(\'Scadenza Mancante\'),
                            \'status_mismatch\' => __(\'Stato Incongruente\')
                        ];',

    // Notice (line 131)
    '                                    Clicca su "Esegui Manutenzione" per correggere automaticamente i problemi riparabili.' =>
        '                                    <?= __("Clicca su \"Esegui Manutenzione\" per correggere automaticamente i problemi riparabili.") ?>',

    // Actions section (line 142)
    '            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Azioni di Manutenzione</h3>' =>
        '            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4"><?= __("Azioni di Manutenzione") ?></h3>',
];

foreach ($replacements as $search => $replace) {
    $count = 0;
    $content = str_replace($search, $replace, $content, $count);
    if ($count > 0) {
        echo "✓ Replaced: " . substr($search, 0, 60) . "... ($count times)\n";
    } else {
        echo "✗ NOT FOUND: " . substr($search, 0, 60) . "...\n";
    }
}

if ($content !== $original) {
    file_put_contents($file, $content);
    echo "\n✅ integrity_report.php - wrapped " . count($replacements) . " strings\n";
} else {
    echo "\nℹ️  integrity_report.php - no changes needed\n";
}

echo "\n✅ Translation complete!\n";

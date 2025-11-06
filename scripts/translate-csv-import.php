<?php
/**
 * Translate app/Views/admin/csv_import.php
 * Fix hardcoded Italian strings in file example and format sections
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// New translations for CSV import page
$newTranslations = [
    // File example section (lines 175-183)
    'File di Esempio' => 'Sample File',
    'Scarica il CSV di esempio con 3 libri già compilati per capire il formato corretto e iniziare subito.' => 'Download the sample CSV with 3 pre-filled books to understand the correct format and get started immediately.',
    'Scarica esempio_import_libri.csv' => 'Download example_import_books.csv',

    // Format details section (line 192)
    'Formato CSV Dettagliato' => 'Detailed CSV Format',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now wrap Italian strings in csv_import.php
$file = __DIR__ . '/../app/Views/admin/csv_import.php';
$content = file_get_contents($file);
$original = $content;

$replacements = [
    // File example section (lines 175-183)
    '                        File di Esempio' =>
        '                        <?= __("File di Esempio") ?>',

    '                        Scarica il CSV di esempio con 3 libri già compilati per capire il formato corretto e iniziare subito.' =>
        '                        <?= __("Scarica il CSV di esempio con 3 libri già compilati per capire il formato corretto e iniziare subito.") ?>',

    '                        Scarica esempio_import_libri.csv' =>
        '                        <?= __("Scarica esempio_import_libri.csv") ?>',

    // Format details section (line 192)
    '                            Formato CSV Dettagliato' =>
        '                            <?= __("Formato CSV Dettagliato") ?>',
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
    echo "\n✅ csv_import.php - wrapped " . count($replacements) . " strings\n";
} else {
    echo "\nℹ️  csv_import.php - no changes needed\n";
}

echo "\n✅ Translation complete!\n";

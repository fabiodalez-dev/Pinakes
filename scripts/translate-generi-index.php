<?php
/**
 * Translate generi/index.php (genres list page)
 * Comprehensive translation of ALL Italian strings
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// ALL translations for genres index page
$newTranslations = [
    // Page header
    'Generi' => 'Genres',
    'Gestione Generi e Sottogeneri' => 'Genres and Subgenres Management',
    'Organizza e gestisci i generi letterari della biblioteca' => 'Organize and manage library literary genres',

    // Quick add section
    'Aggiungi Genere Rapido' => 'Quick Add Genre',
    'es. Noir mediterraneo' => 'e.g. Mediterranean Noir',
    'Genere padre (opz.)' => 'Parent genre (opt.)',
    '– Nessuno –' => '– None –',
    'Salva' => 'Save',

    // Statistics
    'Generi Principali' => 'Main Genres',
    'Sottogeneri' => 'Subgenres',
    'Totale Generi' => 'Total Genres',

    // Actions
    'Crea Nuovo Genere' => 'Create New Genre',
    'Visualizzazione gerarchica di generi e sottogeneri' => 'Hierarchical view of genres and subgenres',
    'Nessun genere trovato' => 'No genres found',
    'Inizia creando il primo genere letterario' => 'Start by creating your first literary genre',
    'Crea Primo Genere' => 'Create First Genre',

    // Genre details
    'Genere principale' => 'Main genre',
    'sottogeneri' => 'subgenres',
    'Dettagli' => 'Details',
    'Nessun sottogenere definito' => 'No subgenres defined',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now wrap Italian strings in generi/index.php
$file = __DIR__ . '/../app/Views/generi/index.php';
$content = file_get_contents($file);
$original = $content;

$replacements = [
    // Breadcrumb
    '            <i class="fas fa-home mr-1"></i>Home' => '            <i class="fas fa-home mr-1"></i><?= __("Home") ?>',
    '            <i class="fas fa-tags mr-1"></i>Generi' => '            <i class="fas fa-tags mr-1"></i><?= __("Generi") ?>',

    // Page header
    '          Gestione Generi e Sottogeneri' => '          <?= __("Gestione Generi e Sottogeneri") ?>',
    '        <p class="text-sm text-gray-600 mt-2">Organizza e gestisci i generi letterari della biblioteca</p>' => '        <p class="text-sm text-gray-600 mt-2"><?= __("Organizza e gestisci i generi letterari della biblioteca") ?></p>',

    // Quick add section
    '          Aggiungi Genere Rapido' => '          <?= __("Aggiungi Genere Rapido") ?>',
    '            <label for="parent_id_genere" class="form-label">Genere padre (opz.)</label>' => '            <label for="parent_id_genere" class="form-label"><?= __("Genere padre (opz.)") ?></label>',
    '              <option value="">– Nessuno –</option>' => '              <option value=""><?= __("– Nessuno –") ?></option>',
    '              Salva' => '              <?= __("Salva") ?>',

    // Statistics cards
    '            <p class="text-sm font-medium text-gray-600">Generi Principali</p>' => '            <p class="text-sm font-medium text-gray-600"><?= __("Generi Principali") ?></p>',
    '            <p class="text-sm font-medium text-gray-600">Sottogeneri</p>' => '            <p class="text-sm font-medium text-gray-600"><?= __("Sottogeneri") ?></p>',
    '            <p class="text-sm font-medium text-gray-600">Totale Generi</p>' => '            <p class="text-sm font-medium text-gray-600"><?= __("Totale Generi") ?></p>',

    // Create button
    '        Crea Nuovo Genere' => '        <?= __("Crea Nuovo Genere") ?>',

    // Card description
    '      <p class="text-sm text-gray-600">Visualizzazione gerarchica di generi e sottogeneri</p>' => '      <p class="text-sm text-gray-600"><?= __("Visualizzazione gerarchica di generi e sottogeneri") ?></p>',

    // Empty state
    '                <p class="text-gray-600 mb-6">Inizia creando il primo genere letterario</p>' => '                <p class="text-gray-600 mb-6"><?= __("Inizia creando il primo genere letterario") ?></p>',
    '              Crea Primo Genere' => '              <?= __("Crea Primo Genere") ?>',

    // Genre list
    '                        Genere principale • <?php echo $genere[\'children_count\']; ?> sottogeneri' => '                        <?= __("Genere principale") ?> • <?php echo $genere[\'children_count\']; ?> <?= __("sottogeneri") ?>',
    '                      Dettagli' => '                      <?= __("Dettagli") ?>',
    '                            <i class="fas fa-external-link-alt mr-1"></i>Dettagli' => '                            <i class="fas fa-external-link-alt mr-1"></i><?= __("Dettagli") ?>',
    '                    Nessun sottogenere definito' => '                    <?= __("Nessun sottogenere definito") ?>',
];

foreach ($replacements as $search => $replace) {
    $count = 0;
    $content = str_replace($search, $replace, $content, $count);
    if ($count > 0) {
        echo "✓ Replaced: " . substr($search, 0, 60) . "... ($count times)\n";
    }
}

if ($content !== $original) {
    file_put_contents($file, $content);
    echo "\n✅ generi/index.php - wrapped " . count($replacements) . " strings\n";
} else {
    echo "\nℹ️  generi/index.php - no changes needed\n";
}

echo "\n✅ Translation complete!\n";

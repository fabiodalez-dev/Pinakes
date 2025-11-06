<?php
/**
 * Translate remaining Italian strings in book_form.php
 * This includes HTML labels, JavaScript strings, placeholders, etc.
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// ALL remaining translations
$newTranslations = [
    // Form section titles
    'Classificazione Dewey' => 'Dewey Classification',

    // Dropdown placeholders
    'Seleziona classe...' => 'Select class...',
    'Seleziona divisione...' => 'Select division...',
    'Seleziona sezione...' => 'Select section...',
    'Seleziona radice...' => 'Select root...',
    'Seleziona prima una radice...' => 'Select a root first...',
    'Seleziona prima un genere...' => 'Select a genre first...',
    'Seleziona scaffale...' => 'Select shelf...',
    'Seleziona prima uno scaffale...' => 'Select a shelf first...',
    'Seleziona mensola...' => 'Select rack...',
    'Seleziona prima un genere' => 'Select a genre first',

    // Action buttons / messages
    'Aggiungi' => 'Add',
    'Immagine Caricata!' => 'Image Uploaded!',
    'come nuovo autore' => 'as new author',
    'Carica' => 'Upload',

    // Additional strings that might be in the file
    'Anteprima' => 'Preview',
    'Rimuovi' => 'Remove',
    'Salva' => 'Save',
    'Annulla' => 'Cancel',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Previous count: " . count($existing) . "\n";
echo "   New translations added: " . (count($merged) - count($existing)) . "\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now wrap Italian strings in book_form.php
$file = __DIR__ . '/../app/Views/libri/partials/book_form.php';
$content = file_get_contents($file);
$original = $content;

// HTML replacements
$replacements = [
    // Section title
    '            Classificazione Dewey' => '            <?= __("Classificazione Dewey") ?>',

    // Dropdown options
    '                <option value="">Seleziona classe...</option>' => '                <option value=""><?= __("Seleziona classe...") ?></option>',
    '                <option value="">Seleziona divisione...</option>' => '                <option value=""><?= __("Seleziona divisione...") ?></option>',
    '                <option value="">Seleziona sezione...</option>' => '                <option value=""><?= __("Seleziona sezione...") ?></option>',
    '                <option value="0">Seleziona radice...</option>' => '                <option value="0"><?= __("Seleziona radice...") ?></option>',
    '                <option value="0">Seleziona prima una radice...</option>' => '                <option value="0"><?= __("Seleziona prima una radice...") ?></option>',
    '                <option value="0">Seleziona prima un genere...</option>' => '                <option value="0"><?= __("Seleziona prima un genere...") ?></option>',
    '                <option value="0">Seleziona scaffale...</option>' => '                <option value="0"><?= __("Seleziona scaffale...") ?></option>',
    '                  <option value="0">Seleziona prima uno scaffale...</option>' => '                  <option value="0"><?= __("Seleziona prima uno scaffale...") ?></option>',
    '                  <option value="0">Seleziona mensola...</option>' => '                  <option value="0"><?= __("Seleziona mensola...") ?></option>',

    // JavaScript strings - need to use proper escaping
    "                title: __('Immagine Caricata!')," => "                title: __(\"Immagine Caricata!\"),",
    "            addItemText: (value) => `Aggiungi <b>\"\${value}\"</b> come nuovo autore`," => "            addItemText: (value) => `<?= __('Aggiungi') ?> <b>\"\${value}\"</b> <?= __('come nuovo autore') ?>`,"
];

// JavaScript function calls - these need different handling
$jsReplacements = [
    "    fill(l1, cats, 'Seleziona classe...');" => "    fill(l1, cats, __('Seleziona classe...'));",
    "    fill(l2, [], 'Seleziona divisione...');" => "    fill(l2, [], __('Seleziona divisione...'));",
    "    fill(l3, [], 'Seleziona sezione...');" => "    fill(l3, [], __('Seleziona sezione...'));",
    "        fill(l2, divs, 'Seleziona divisione...');" => "        fill(l2, divs, __('Seleziona divisione...'));",
    "        fill(l3, specs, 'Seleziona sezione...');" => "        fill(l3, specs, __('Seleziona sezione...'));",
    "      radiceSelect.innerHTML = '<option value=\"0\">Seleziona radice...</option>';" => "      radiceSelect.innerHTML = `<option value=\"0\">\${__('Seleziona radice...')}</option>`;",
    "    genereSelect.innerHTML = '<option value=\"0\">Seleziona prima una radice...</option>';" => "    genereSelect.innerHTML = `<option value=\"0\">\${__('Seleziona prima una radice...')}</option>`;",
    "    resetSottogenere('Seleziona prima un genere...');" => "    resetSottogenere(__('Seleziona prima un genere...'));",
];

foreach ($replacements as $search => $replace) {
    $count = 0;
    $content = str_replace($search, $replace, $content, $count);
    if ($count > 0) {
        echo "✓ Replaced: " . substr($search, 0, 50) . "... ($count times)\n";
    }
}

foreach ($jsReplacements as $search => $replace) {
    $count = 0;
    $content = str_replace($search, $replace, $content, $count);
    if ($count > 0) {
        echo "✓ Replaced JS: " . substr($search, 0, 50) . "... ($count times)\n";
    }
}

if ($content !== $original) {
    file_put_contents($file, $content);
    echo "\n✅ book_form.php - wrapped " . (count($replacements) + count($jsReplacements)) . " strings\n";
} else {
    echo "\nℹ️  book_form.php - no changes needed\n";
}

echo "\n✅ Translation complete!\n";

<?php
/**
 * Translate frontend home-books-grid.php
 * Fix Editore label, buttons, and empty states
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// All translations for home-books-grid
$newTranslations = [
    // Publisher label (line 52)
    'Editore:' => 'Publisher:',

    // Button text (line 61)
    'Dettagli' => 'Details',

    // Empty state (lines 70-74)
    'Nessun libro trovato' => 'No books found',
    'Prova a modificare i filtri o la tua ricerca' => 'Try adjusting your filters or search',
    'Pulisci filtri' => 'Clear Filters',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now fix the home-books-grid.php code
$file = __DIR__ . '/../app/Views/frontend/home-books-grid.php';
$content = file_get_contents($file);
$original = $content;

$replacements = [
    // Fix line 52: Editore label
    "                        <span class=\"text-muted\">Editore:</span>" =>
    "                        <span class=\"text-muted\"><?= __(\"Editore:\") ?></span>",

    // Fix line 61: Dettagli button
    "                        Dettagli
                    </a>" =>
    "                        <?= __(\"Dettagli\") ?>
                    </a>",

    // Fix line 70: Empty state title
    "        <h4 class=\"empty-state-title\">Nessun libro trovato</h4>" =>
    "        <h4 class=\"empty-state-title\"><?= __(\"Nessun libro trovato\") ?></h4>",

    // Fix line 71: Empty state text
    "        <p class=\"empty-state-text\">Prova a modificare i filtri o la tua ricerca</p>" =>
    "        <p class=\"empty-state-text\"><?= __(\"Prova a modificare i filtri o la tua ricerca\") ?></p>",

    // Fix line 74: Clear filters button
    "            Pulisci filtri
        </button>" =>
    "            <?= __(\"Pulisci filtri\") ?>
        </button>",
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
    echo "\n✅ home-books-grid.php - Fixed " . count($replacements) . " strings\n";
} else {
    echo "\nℹ️  home-books-grid.php - No changes made\n";
}

echo "\n✅ Home books grid translation COMPLETE!\n";

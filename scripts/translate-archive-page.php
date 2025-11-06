<?php
/**
 * Translate frontend archive.php
 * Fix subtitle labels and implement __n() for singular/plural
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// All translations for archive page
$newTranslations = [
    // Subtitle labels (lines 410-415)
    'Autore' => 'Author',
    'Casa Editrice' => 'Publisher',
    'Genere' => 'Genre',

    // Singular/plural forms for __n() (lines 426, 431)
    'libro' => 'book',
    'libri' => 'books',
    'pagina' => 'page',
    'pagine' => 'pages',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now fix the archive.php code
$file = __DIR__ . '/../app/Views/frontend/archive.php';
$content = file_get_contents($file);
$original = $content;

$replacements = [
    // Fix lines 410-415: Wrap subtitle labels
    "            <p class=\"archive-subtitle\">
                <?php if (\$archive_type === 'autore'): ?>
                    Autore
                <?php elseif (\$archive_type === 'editore'): ?>
                    Casa Editrice
                <?php else: ?>
                    Genere
                <?php endif; ?>
            </p>" =>
    "            <p class=\"archive-subtitle\">
                <?php if (\$archive_type === 'autore'): ?>
                    <?= __(\"Autore\") ?>
                <?php elseif (\$archive_type === 'editore'): ?>
                    <?= __(\"Casa Editrice\") ?>
                <?php else: ?>
                    <?= __(\"Genere\") ?>
                <?php endif; ?>
            </p>",

    // Fix line 426: Use __n() for libro/libri
    "                <span><?= \$totalBooks ?> <?= \$totalBooks === 1 ? 'libro' : 'libri' ?></span>" =>
    "                <span><?= \$totalBooks ?> <?= __n('libro', 'libri', \$totalBooks) ?></span>",

    // Fix line 431: Use __n() for pagina/pagine
    "                    <span><?= \$totalPages ?> <?= \$totalPages === 1 ? 'pagina' : 'pagine' ?></span>" =>
    "                    <span><?= \$totalPages ?> <?= __n('pagina', 'pagine', \$totalPages) ?></span>",
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
    echo "\n✅ archive.php - Fixed " . count($replacements) . " strings\n";
} else {
    echo "\nℹ️  archive.php - No changes made\n";
}

echo "\n✅ Archive page translation COMPLETE!\n";

<?php
/**
 * Translate app/Views/admin/cms-edit.php
 * Fix hardcoded image alt text
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// New translation for cms-edit page
$newTranslations = [
    // Image alt text (line 64)
    'Preview' => 'Preview',
    'Anteprima' => 'Preview',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now wrap Italian strings in cms-edit.php
$file = __DIR__ . '/../app/Views/admin/cms-edit.php';
$content = file_get_contents($file);
$original = $content;

$replacements = [
    // Image alt text (line 64)
    '              alt="Preview"' =>
        '              alt="<?= __("Anteprima") ?>"',
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
    echo "\n✅ cms-edit.php - wrapped " . count($replacements) . " strings\n";
} else {
    echo "\nℹ️  cms-edit.php - no changes needed\n";
}

echo "\n✅ Translation complete!\n";

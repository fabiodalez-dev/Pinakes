<?php
/**
 * Analyze untranslated placeholder attributes in views
 */

declare(strict_types=1);

$viewsDir = __DIR__ . '/../app/Views';

function scanDirectory(string $dir): array {
    $files = [];
    $items = scandir($dir);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;

        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $files = array_merge($files, scanDirectory($path));
        } elseif (str_ends_with($item, '.php')) {
            $files[] = $path;
        }
    }

    return $files;
}

function findUntranslatedPlaceholders(string $file): array {
    $content = file_get_contents($file);
    $found = [];

    // Pattern: placeholder="..." but NOT placeholder="<?= __('...') ?>"
    // Match placeholder with double quotes that doesn't start with <?=
    preg_match_all('/placeholder="([^"]+)"/i', $content, $matches, PREG_OFFSET_CAPTURE);

    foreach ($matches[0] as $index => $match) {
        $fullMatch = $match[0]; // e.g., placeholder="Titolo libro"
        $placeholderText = $matches[1][$index][0];
        $position = $match[1];

        // Check if this placeholder uses __() function
        // Look for patterns like: placeholder with translation function or PHP tags
        if (strpos($fullMatch, '__(') === false && strpos($fullMatch, '<' . '?=') === false && strpos($fullMatch, '<' . '?php') === false) {
            // This is a plain string placeholder without translation
            $found[] = [
                'text' => $placeholderText,
                'position' => $position,
                'context' => substr($content, max(0, $position - 100), 200)
            ];
        }
    }

    return $found;
}

echo "🔍 Analyzing untranslated placeholders in views...\n";
echo "=================================================\n\n";

$files = scanDirectory($viewsDir);
$allPlaceholders = [];
$fileStats = [];

foreach ($files as $file) {
    $placeholders = findUntranslatedPlaceholders($file);

    if (!empty($placeholders)) {
        $relativePath = str_replace($viewsDir . '/', '', $file);
        $fileStats[$relativePath] = count($placeholders);

        foreach ($placeholders as $p) {
            $allPlaceholders[] = [
                'file' => $relativePath,
                'text' => $p['text']
            ];
        }
    }
}

// Sort files by placeholder count (descending)
arsort($fileStats);

echo "📊 Files with untranslated placeholders:\n";
echo "========================================\n\n";

$totalPlaceholders = 0;
foreach ($fileStats as $file => $count) {
    echo sprintf("  • %s: %d placeholder%s\n", $file, $count, $count !== 1 ? 's' : '');
    $totalPlaceholders += $count;
}

echo "\n📈 Total: {$totalPlaceholders} untranslated placeholders in " . count($fileStats) . " files\n\n";

// Generate unique placeholder texts
$uniqueTexts = array_unique(array_column($allPlaceholders, 'text'));
sort($uniqueTexts);

echo "📝 Sample of unique placeholder texts (first 30):\n";
echo "=================================================\n\n";

$sample = array_slice($uniqueTexts, 0, 30);
foreach ($sample as $text) {
    echo "  • \"{$text}\"\n";
}

if (count($uniqueTexts) > 30) {
    echo "\n  ... and " . (count($uniqueTexts) - 30) . " more unique texts\n";
}

echo "\n✅ Analysis complete!\n";
echo "\n📋 Summary:\n";
echo "  • Total untranslated placeholders: {$totalPlaceholders}\n";
echo "  • Unique placeholder texts: " . count($uniqueTexts) . "\n";
echo "  • Files affected: " . count($fileStats) . "\n";

// Export placeholder texts to file for translation
$exportFile = __DIR__ . '/untranslated-placeholders.txt';
file_put_contents($exportFile, implode("\n", $uniqueTexts));
echo "\n💾 Exported unique texts to: " . basename($exportFile) . "\n";

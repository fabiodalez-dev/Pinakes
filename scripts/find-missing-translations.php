<?php
/**
 * Find missing translations
 *
 * Scans all view files for __() calls and checks which strings
 * are not yet translated in en_US.json
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$viewsDir = __DIR__ . '/../app/Views';

// Load existing translations
$existing = [];
if (file_exists($translationFile)) {
    $existing = json_decode(file_get_contents($translationFile), true) ?? [];
}

echo "🔍 Scanning for missing translations...\n";
echo "   Existing translations: " . count($existing) . "\n\n";

// Find all __() calls in PHP files
$allTranslatableStrings = [];

function extractTranslatableStrings(string $filePath): array
{
    $content = file_get_contents($filePath);
    $strings = [];

    // Pattern to match __('text') or __("text")
    // Matches: __('text'), __("text"), __('text with \'escaped\'')
    preg_match_all('/__\([\'"]([^\'"]+?)[\'"]\)/', $content, $matches);

    if (!empty($matches[1])) {
        foreach ($matches[1] as $string) {
            // Decode escaped quotes
            $string = str_replace(["\\'", '\\"'], ["'", '"'], $string);
            $strings[] = $string;
        }
    }

    return $strings;
}

function scanDirectory(string $dir, array &$allStrings): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $strings = extractTranslatableStrings($file->getPathname());
            foreach ($strings as $string) {
                if (!isset($allStrings[$string])) {
                    $allStrings[$string] = 0;
                }
                $allStrings[$string]++;
            }
        }
    }
}

scanDirectory($viewsDir, $allTranslatableStrings);

// Find missing translations
$missing = [];
foreach ($allTranslatableStrings as $string => $count) {
    if (!isset($existing[$string])) {
        $missing[$string] = $count;
    }
}

// Sort by frequency (most used first)
arsort($missing);

echo "📊 Statistics:\n";
echo "   Total translatable strings found: " . count($allTranslatableStrings) . "\n";
echo "   Already translated: " . (count($allTranslatableStrings) - count($missing)) . "\n";
echo "   Missing translations: " . count($missing) . "\n\n";

if (empty($missing)) {
    echo "✅ All strings are translated!\n";
    exit(0);
}

// Show top 50 most frequent missing translations
echo "📝 Top 50 missing translations (by frequency):\n";
echo "   " . str_repeat("─", 70) . "\n";

$displayed = 0;
foreach ($missing as $string => $count) {
    if ($displayed >= 50) {
        break;
    }

    // Truncate long strings for display
    $displayString = strlen($string) > 60 ? substr($string, 0, 57) . '...' : $string;
    printf("   [%3dx] %s\n", $count, $displayString);
    $displayed++;
}

if (count($missing) > 50) {
    echo "\n   ... and " . (count($missing) - 50) . " more\n";
}

// Save full list to file for reference
$outputFile = __DIR__ . '/missing-translations.txt';
$output = "Missing Translations Report\n";
$output .= "Generated: " . date('Y-m-d H:i:s') . "\n";
$output .= "Total missing: " . count($missing) . "\n";
$output .= str_repeat("=", 80) . "\n\n";

foreach ($missing as $string => $count) {
    $output .= sprintf("[%3dx] %s\n", $count, $string);
}

file_put_contents($outputFile, $output);
echo "\n📄 Full list saved to: " . basename($outputFile) . "\n";

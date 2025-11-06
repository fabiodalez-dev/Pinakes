<?php
/**
 * Check which settings.php strings are missing from en_US.json
 */

declare(strict_types=1);

$settingsFile = __DIR__ . '/../app/Views/admin/settings.php';
$translationFile = __DIR__ . '/../locale/en_US.json';

// Extract all __("...") strings from settings.php
$settingsContent = file_get_contents($settingsFile);
preg_match_all('/__\("([^"]+)"\)/', $settingsContent, $matches);
$settingsStrings = array_unique($matches[1]);
sort($settingsStrings, SORT_STRING | SORT_FLAG_CASE);

// Load existing translations
$existing = json_decode(file_get_contents($translationFile), true);

// Find missing translations
$missing = [];
foreach ($settingsStrings as $str) {
    if (!isset($existing[$str])) {
        $missing[] = $str;
    }
}

echo "📊 Settings Translation Status:\n";
echo "   Total strings in settings.php: " . count($settingsStrings) . "\n";
echo "   Already translated: " . (count($settingsStrings) - count($missing)) . "\n";
echo "   Missing translations: " . count($missing) . "\n\n";

if (count($missing) > 0) {
    echo "❌ Missing translations:\n";
    foreach ($missing as $str) {
        echo "   • \"$str\"\n";
    }
}

<?php
/**
 * Test I18n translation system
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Support\I18n;

echo "🧪 Testing I18n Translation System\n";
echo str_repeat("=", 50) . "\n\n";

// Test 1: Italian (default locale)
echo "📋 Test 1: Italian locale (it_IT - default)\n";
echo "Current locale: " . I18n::getLocale() . "\n";

$tests = [
    'Cerca libri...',
    'Titolo libro',
    'Nome Cognome',
    'es. La morale anarchica',
    'String without translation'
];

foreach ($tests as $text) {
    $translated = I18n::translate($text);
    echo sprintf("  • \"%s\" → \"%s\"%s\n",
        $text,
        $translated,
        ($text === $translated) ? ' (unchanged)' : ''
    );
}

// Test 2: Switch to English
echo "\n📋 Test 2: English locale (en_US)\n";
I18n::setLocale('en_US');
echo "Current locale: " . I18n::getLocale() . "\n";

foreach ($tests as $text) {
    $translated = I18n::translate($text);
    echo sprintf("  • \"%s\" → \"%s\"%s\n",
        $text,
        $translated,
        ($text === $translated) ? ' (unchanged)' : ''
    );
}

// Test 3: Verify translations loaded
echo "\n📋 Test 3: Check translation file\n";
$enFile = __DIR__ . '/../locale/en_US.json';
if (file_exists($enFile)) {
    $json = json_decode(file_get_contents($enFile), true);
    echo "  • en_US.json exists: ✓\n";
    echo "  • Translations count: " . count($json) . "\n";
    echo "  • Sample entries:\n";
    foreach (array_slice($json, 0, 5) as $it => $en) {
        echo "    - \"{$it}\" → \"{$en}\"\n";
    }
} else {
    echo "  ❌ en_US.json not found\n";
}

// Test 4: Switch back to Italian
echo "\n📋 Test 4: Switch back to Italian\n";
I18n::setLocale('it_IT');
echo "Current locale: " . I18n::getLocale() . "\n";

$text = 'Cerca libri...';
$translated = I18n::translate($text);
echo sprintf("  • \"%s\" → \"%s\"\n", $text, $translated);

// Test 5: __() helper function
echo "\n📋 Test 5: __() helper function\n";
I18n::setLocale('en_US');
$text = 'Titolo libro';
$result = __($text);
echo sprintf("  • __('%s') → \"%s\"\n", $text, $result);

echo "\n✅ All tests completed!\n";

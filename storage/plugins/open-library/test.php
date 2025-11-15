<?php
/**
 * Open Library Plugin Test Script
 *
 * This script tests the Open Library plugin functionality.
 * Run it from the command line:
 *   php app/Plugins/OpenLibrary/test.php
 */

require __DIR__ . '/../../../vendor/autoload.php';

use App\Plugins\OpenLibrary\OpenLibraryPlugin;

echo "=== Open Library Plugin Test ===\n\n";

// Create plugin instance
$plugin = new OpenLibraryPlugin();
echo "✓ Plugin instantiated\n";

// Activate plugin (registers hooks)
$plugin->activate();
echo "✓ Plugin activated\n\n";

// Test ISBN examples
$testIsbns = [
    '9780140328721' => 'Fantastic Mr. Fox by Roald Dahl',
    '9780451526538' => '1984 by George Orwell',
    '9788804671664' => 'Il nome della rosa by Umberto Eco',
    '9788806234515' => 'Se questo è un uomo by Primo Levi',
];

echo "Testing ISBN lookups:\n";
echo str_repeat('-', 80) . "\n";

foreach ($testIsbns as $isbn => $description) {
    echo "\nISBN: {$isbn} ({$description})\n";

    // Simulate the scrape.sources hook
    $sources = [
        'libreriauniversitaria' => [
            'name' => 'LibreriaUniversitaria',
            'enabled' => true,
            'priority' => 10,
        ],
    ];

    $sources = \App\Support\Hooks::apply('scrape.sources', $sources, [$isbn]);

    if (isset($sources['openlibrary'])) {
        echo "  ✓ Open Library source added\n";
        echo "    Priority: {$sources['openlibrary']['priority']}\n";
    } else {
        echo "  ✗ Open Library source NOT added\n";
    }

    // Test the actual API fetch
    echo "  Testing API fetch...\n";
    $result = $plugin->fetchFromOpenLibrary(null, $sources, $isbn);

    if ($result) {
        echo "  ✓ Data fetched successfully\n";
        echo "    Title: " . ($result['title'] ?? 'N/A') . "\n";
        echo "    Author: " . ($result['author'] ?? 'N/A') . "\n";
        echo "    Publisher: " . ($result['publisher'] ?? 'N/A') . "\n";
        echo "    Year: " . ($result['year'] ?? 'N/A') . "\n";
        echo "    Pages: " . ($result['pages'] ?? 'N/A') . "\n";
        echo "    Cover: " . (empty($result['image']) ? 'No' : 'Yes') . "\n";
        echo "    Description: " . (empty($result['description']) ? 'No' : substr($result['description'], 0, 60) . '...') . "\n";
    } else {
        echo "  ✗ Failed to fetch data\n";
    }

    echo str_repeat('-', 80) . "\n";

    // Be nice to the API
    sleep(1);
}

echo "\n=== Test Complete ===\n";

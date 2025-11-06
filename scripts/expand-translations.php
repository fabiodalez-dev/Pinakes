<?php
/**
 * Expand en_US.json with remaining placeholder translations
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';

// Load existing translations
$existing = json_decode(file_get_contents($translationFile), true);

// Additional translations for remaining placeholders
$additional = [
    // Additional examples - numbers and technical
    'es. 0.450' => 'e.g. 0.450',
    'es. 15' => 'e.g. 15',
    'es. 19.90' => 'e.g. 19.90',
    'es. 2020' => 'e.g. 2020',
    'es. 2024' => 'e.g. 2024',
    'es. 2025' => 'e.g. 2025',
    'es. 21x14 cm' => 'e.g. 21x14 cm',
    'es. 320' => 'e.g. 320',
    'es. 8842935786' => 'e.g. 8842935786',
    'es. 978-88-429-3578-0' => 'e.g. 978-88-429-3578-0',
    'es. 9788842935780' => 'e.g. 9788842935780',
    'es. INV-2024-001' => 'e.g. INV-2024-001',
    'es. noreply@biblioteca.local' => 'e.g. noreply@library.local',
    'es. RSSMRA80A01H501U' => 'e.g. RSSMRA80A01H501U',

    // Address example
    'Via Roma 123, 00100 Roma RM, Italia' => '123 Main St, New York, NY 10001, USA',

    // Form labels that might be in placeholders
    'Nome e cognome del referente' => 'Contact person name',
    'Nome e cognome dell\'autore' => 'Author\'s full name',
    'Titolo...' => 'Title...',

    // Settings placeholders
    'La tua biblioteca digitale...' => 'Your digital library...',
];

// Merge with existing, keeping existing translations
$merged = array_merge($existing, $additional);

// Sort alphabetically by Italian key for easier maintenance
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save back to file
$formatted = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($translationFile, $formatted);

echo "✅ Expanded en_US.json translations\n";
echo "   Previous count: " . count($existing) . "\n";
echo "   New translations added: " . (count($merged) - count($existing)) . "\n";
echo "   Total translations: " . count($merged) . "\n\n";

echo "📝 New translations added:\n";
foreach ($additional as $it => $en) {
    if (!isset($existing[$it])) {
        echo "   • \"{$it}\" → \"{$en}\"\n";
    }
}

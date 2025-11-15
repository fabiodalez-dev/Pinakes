#!/usr/bin/env php
<?php
/**
 * Translate notification strings in layout.php
 */

$file = 'app/Views/layout.php';

if (!file_exists($file)) {
    die("❌ File not found: $file\n");
}

$content = file_get_contents($file);

// Translations to add
$translations = [
    'Segna tutte come lette' => 'Mark all as read',
    'Vedi tutte le notifiche' => 'See all notifications',
    'Apri' => 'Open',
];

// 1. Replace "Segna tutte come lette" button
$content = str_replace(
    '                        Segna tutte come lette',
    '                        <?= __("Segna tutte come lette") ?>',
    $content
);

// 2. Replace "Vedi tutte le notifiche" link
$content = str_replace(
    '                        Vedi tutte le notifiche',
    '                        <?= __("Vedi tutte le notifiche") ?>',
    $content
);

// 3. Replace "Apri" button in JavaScript (needs to use window.__)
$content = str_replace(
    "                          Apri\n",
    "                          ' + __('Apri') + '\n",
    $content
);

// Write back
file_put_contents($file, $content);

// Add translations to locale file
$localeFile = 'locale/en_US.json';
$locale = json_decode(file_get_contents($localeFile), true);

$addedCount = 0;
foreach ($translations as $italian => $english) {
    if (!isset($locale[$italian])) {
        $locale[$italian] = $english;
        $addedCount++;
    }
}

ksort($locale);
file_put_contents($localeFile, json_encode($locale, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

echo "✅ Fixed layout.php notifications\n";
echo "✅ Added $addedCount translations to locale/en_US.json\n";
echo "✅ Total translations: " . count($locale) . "\n";

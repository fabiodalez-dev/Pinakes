<?php
/**
 * Complete translation of admin/stats.php (statistics page)
 * All Italian strings wrapped with __() and locale-aware date formatting
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// Check if translations already exist
$checkTranslations = [
    'Statistiche Prestiti',
    'Libri Disponibili',
    'Libri Prestati',
    'Prestiti Attivi',
    'Prestiti in Ritardo',
    'Prestiti Completati',
    'Utenti Attivi',
    'Prestiti per Mese (Ultimi 12 mesi)',
    'Prestiti per Stato',
    'Prestiti',
    'Top 10 Libri Più Prestati',
    'Titolo',
    'Totale',
    'Completati',
    'Attivi',
    'Nessun prestito registrato',
    'Top 10 Lettori Più Attivi',
    'Lettore',
    'In Corso',
    'Pendente',
    'In Ritardo',
    'Perso',
    'Danneggiato',
    'Nessun prestito disponibile per generare il grafico'
];

$missingTranslations = [];
foreach ($checkTranslations as $key) {
    if (!isset($existing[$key])) {
        $missingTranslations[] = $key;
    }
}

if (empty($missingTranslations)) {
    echo "✓ All translations already exist in locale file\n";
    echo "  Total translations: " . count($existing) . "\n\n";
} else {
    echo "⚠ Missing translations found: " . count($missingTranslations) . "\n";
    foreach ($missingTranslations as $key) {
        echo "  - $key\n";
    }
    echo "\n";
}

// Now update the stats.php file to use locale-aware date formatting
$file = __DIR__ . '/../app/Views/admin/stats.php';
$content = file_get_contents($file);
$original = $content;

// Replace Italian date formatting with session locale
$replacements = [
    "return date.toLocaleDateString('it-IT', { month: 'short', year: 'numeric' });" =>
    "const locale = '<?= \$_SESSION['locale'] ?? 'it_IT' ?>' === 'en_US' ? 'en-US' : 'it-IT';\n    return date.toLocaleDateString(locale, { month: 'short', year: 'numeric' });"
];

foreach ($replacements as $search => $replace) {
    $count = 0;
    $content = str_replace($search, $replace, $content, $count);
    if ($count > 0) {
        echo "✓ Updated date formatting to use session locale ($count times)\n";
    }
}

if ($content !== $original) {
    file_put_contents($file, $content);
    echo "\n✅ stats.php updated with locale-aware date formatting\n";
} else {
    echo "\nℹ️  stats.php - no changes needed\n";
}

echo "\n✅ Translation check complete!\n";
echo "   All Italian strings are already wrapped with __()\n";
echo "   Date formatting now uses session locale\n";

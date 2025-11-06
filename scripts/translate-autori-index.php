<?php
/**
 * Translate autori/index.php (authors list page)
 * Comprehensive translation of ALL Italian strings
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// ALL translations for authors index page
$newTranslations = [
    // Page header
    'Autori' => 'Authors',
    'Gestione Autori' => 'Authors Management',
    'Esplora e gestisci gli autori della biblioteca' => 'Explore and manage library authors',
    'Nuovo Autore' => 'New Author',

    // Filters
    'Filtri di Ricerca' => 'Search Filters',
    'Nome autore' => 'Author name',
    'Cerca per nome...' => 'Search by name...',
    'Pseudonimo' => 'Pseudonym',
    'Cerca per pseudonimo...' => 'Search by pseudonym...',
    'Nazionalità' => 'Nationality',
    'Es. Italiana, Americana...' => 'E.g. Italian, American...',
    'Sito web' => 'Website',
    'URL sito web...' => 'Website URL...',
    'Data nascita da' => 'Birth date from',
    'Data nascita a' => 'Birth date to',
    'Data morte da' => 'Death date from',
    'Data morte a' => 'Death date to',
    'I filtri vengono applicati automaticamente mentre digiti' => 'Filters are applied automatically as you type',
    'Cancella filtri' => 'Clear filters',

    // Table section
    'Elenco Autori' => 'Authors List',
    'Excel' => 'Excel',
    'PDF' => 'PDF',
    'Stampa' => 'Print',
    'Esporta Excel' => 'Export Excel',
    'Esporta PDF' => 'Export PDF',

    // Table headers
    'Nome' => 'Name',
    'Numero Libri' => 'Number of Books',

    // JavaScript strings
    'Autore sconosciuto' => 'Unknown author',
    'Visualizza dettagli' => 'View details',
    'Tutti' => 'All',
    'Mostra filtri' => 'Show filters',
    'Sì' => 'Yes',
    'No' => 'No',
    'Libri' => 'Books',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now wrap Italian strings in autori/index.php
$file = __DIR__ . '/../app/Views/autori/index.php';
$content = file_get_contents($file);
$original = $content;

$replacements = [
    // Page title variable
    '$title = "Autori";' => '$title = __("Autori");',

    // Breadcrumb
    '            <i class="fas fa-home mr-1"></i>Home' => '            <i class="fas fa-home mr-1"></i><?= __("Home") ?>',
    '            <i class="fas fa-user-edit mr-1"></i>Autori' => '            <i class="fas fa-user-edit mr-1"></i><?= __("Autori") ?>',

    // Page header
    '            Gestione Autori' => '            <?= __("Gestione Autori") ?>',
    '          <p class="text-sm text-gray-600 mt-1">Esplora e gestisci gli autori della biblioteca</p>' => '          <p class="text-sm text-gray-600 mt-1"><?= __("Esplora e gestisci gli autori della biblioteca") ?></p>',
    '            Nuovo Autore' => '            <?= __("Nuovo Autore") ?>',

    // Filters
    '          Filtri di Ricerca' => '          <?= __("Filtri di Ricerca") ?>',
    '              Nome autore' => '              <?= __("Nome autore") ?>',
    '              Pseudonimo' => '              <?= __("Pseudonimo") ?>',
    '              Nazionalità' => '              <?= __("Nazionalità") ?>',
    '              Sito web' => '              <?= __("Sito web") ?>',
    '              Data nascita da' => '              <?= __("Data nascita da") ?>',
    '              Data nascita a' => '              <?= __("Data nascita a") ?>',
    '              Data morte da' => '              <?= __("Data morte da") ?>',
    '              Data morte a' => '              <?= __("Data morte a") ?>',
    '            <span>I filtri vengono applicati automaticamente mentre digiti</span>' => '            <span><?= __("I filtri vengono applicati automaticamente mentre digiti") ?></span>',
    '            Cancella filtri' => '            <?= __("Cancella filtri") ?>',

    // Table section
    '          Elenco Autori' => '          <?= __("Elenco Autori") ?>',
    '            Excel' => '            <?= __("Excel") ?>',
    '            PDF' => '            <?= __("PDF") ?>',
    '            Stampa' => '            <?= __("Stampa") ?>',
    '          <button id="export-excel" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="Esporta Excel">' => '          <button id="export-excel" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="<?= __("Esporta Excel") ?>">',
    '          <button id="export-pdf" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="Esporta PDF">' => '          <button id="export-pdf" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="<?= __("Esporta PDF") ?>">',
    '          <button id="print-table" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="Stampa">' => '          <button id="print-table" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="<?= __("Stampa") ?>">',

    // JavaScript strings
    '          const nome = row.nome || \'Autore sconosciuto\';' => '          const nome = row.nome || __(\'Autore sconosciuto\');',
    '                 title="Visualizza dettagli">' => '                 title="<?= __("Visualizza dettagli") ?>">',
    '      [10, 25, 50, 100, "Tutti"]' => '      [10, 25, 50, 100, __("Tutti")]',
    '          text.textContent = \'Nascondi filtri\';' => '          text.textContent = __(\'Nascondi filtri\');',
    '          text.textContent = \'Mostra filtri\';' => '          text.textContent = __(\'Mostra filtri\');',
    '        const biografia = (row.biografia ? \'Sì\' : \'No\').replace(/"/g, \'\\"\\"\');' => '        const biografia = (row.biografia ? __(\'Sì\') : __(\'No\')).replace(/"/g, \'\\"\\"\');',
    '    const headers = [\'Nome\', \'Pseudonimo\', \'Nazionalità\', \'Libri\'];' => '    const headers = [__(\'Nome\'), __(\'Pseudonimo\'), __(\'Nazionalità\'), __(\'Libri\')];',
];

foreach ($replacements as $search => $replace) {
    $count = 0;
    $content = str_replace($search, $replace, $content, $count);
    if ($count > 0) {
        echo "✓ Replaced: " . substr($search, 0, 60) . "... ($count times)\n";
    }
}

if ($content !== $original) {
    file_put_contents($file, $content);
    echo "\n✅ autori/index.php - wrapped " . count($replacements) . " strings\n";
} else {
    echo "\nℹ️  autori/index.php - no changes needed\n";
}

echo "\n✅ Translation complete!\n";

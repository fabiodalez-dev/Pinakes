<?php
/**
 * Translate editori/index.php (publishers list page)
 * Comprehensive translation of ALL Italian strings
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// ALL translations for publishers index page
$newTranslations = [
    // Page header
    'Editori' => 'Publishers',
    'Gestione Editori' => 'Publishers Management',
    'Esplora e gestisci gli editori della biblioteca' => 'Explore and manage library publishers',
    'Nuovo Editore' => 'New Publisher',

    // Filters
    'Nome editore' => 'Publisher name',
    'Numero di libri' => 'Number of books',
    'Tutti gli editori' => 'All publishers',
    '0-10 libri' => '0-10 books',
    '11-50 libri' => '11-50 books',
    '51-100 libri' => '51-100 books',
    '101-500 libri' => '101-500 books',
    'Più di 500 libri' => 'More than 500 books',

    // Table section
    'Elenco Editori' => 'Publishers List',
    'editori' => 'publishers',

    // JavaScript strings
    'Editore sconosciuto' => 'Unknown publisher',
    'Errore' => 'Error',
    'Impossibile caricare gli editori. Controlla la console per i dettagli.' => 'Unable to load publishers. Check console for details.',
    'Elenco Editori - Biblioteca' => 'Publishers List - Library',
    'Generato il:' => 'Generated on:',
    'Totale editori:' => 'Total publishers:',
    'Sei sicuro di voler eliminare questo editore?' => 'Are you sure you want to delete this publisher?',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now wrap Italian strings in editori/index.php
$file = __DIR__ . '/../app/Views/editori/index.php';
$content = file_get_contents($file);
$original = $content;

$replacements = [
    // Page title variable
    '$title = "Editori";' => '$title = __("Editori");',

    // Breadcrumb
    '            <i class="fas fa-home mr-1"></i>Home' => '            <i class="fas fa-home mr-1"></i><?= __("Home") ?>',
    '            <i class="fas fa-building mr-1"></i>Editori' => '            <i class="fas fa-building mr-1"></i><?= __("Editori") ?>',

    // Page header
    '            Gestione Editori' => '            <?= __("Gestione Editori") ?>',
    '          <p class="text-sm text-gray-600 mt-1">Esplora e gestisci gli editori della biblioteca</p>' => '          <p class="text-sm text-gray-600 mt-1"><?= __("Esplora e gestisci gli editori della biblioteca") ?></p>',
    '            Nuovo Editore' => '            <?= __("Nuovo Editore") ?>',
    '          Nuovo Editore' => '          <?= __("Nuovo Editore") ?>',

    // Filters
    '          Filtri di Ricerca' => '          <?= __("Filtri di Ricerca") ?>',
    '              Nome editore' => '              <?= __("Nome editore") ?>',
    '              Sito web' => '              <?= __("Sito web") ?>',
    '              Numero di libri' => '              <?= __("Numero di libri") ?>',
    '              <option value="">Tutti gli editori</option>' => '              <option value=""><?= __("Tutti gli editori") ?></option>',
    '              <option value="0-10">0-10 libri</option>' => '              <option value="0-10"><?= __("0-10 libri") ?></option>',
    '              <option value="11-50">11-50 libri</option>' => '              <option value="11-50"><?= __("11-50 libri") ?></option>',
    '              <option value="51-100">51-100 libri</option>' => '              <option value="51-100"><?= __("51-100 libri") ?></option>',
    '              <option value="101-500">101-500 libri</option>' => '              <option value="101-500"><?= __("101-500 libri") ?></option>',
    '              <option value="501+">Più di 500 libri</option>' => '              <option value="501+"><?= __("Più di 500 libri") ?></option>',
    '            <span>I filtri vengono applicati automaticamente mentre digiti</span>' => '            <span><?= __("I filtri vengono applicati automaticamente mentre digiti") ?></span>',
    '            Cancella filtri' => '            <?= __("Cancella filtri") ?>',

    // Table section
    '          Elenco Editori' => '          <?= __("Elenco Editori") ?>',
    '            Excel' => '            <?= __("Excel") ?>',
    '            PDF' => '            <?= __("PDF") ?>',
    '            Stampa' => '            <?= __("Stampa") ?>',
    '          <button id="export-excel" class="btn-outline inline-flex items-center px-3 py-1.5 text-sm" title="Esporta Excel">' => '          <button id="export-excel" class="btn-outline inline-flex items-center px-3 py-1.5 text-sm" title="<?= __("Esporta Excel") ?>">',
    '          <button id="export-pdf" class="btn-outline inline-flex items-center px-3 py-1.5 text-sm" title="Esporta PDF">' => '          <button id="export-pdf" class="btn-outline inline-flex items-center px-3 py-1.5 text-sm" title="<?= __("Esporta PDF") ?>">',
    '          <button id="print-table" class="btn-outline inline-flex items-center px-3 py-1.5 text-sm" title="Stampa">' => '          <button id="print-table" class="btn-outline inline-flex items-center px-3 py-1.5 text-sm" title="<?= __("Stampa") ?>">',

    // Table headers
    '                    <th>Nome</th>' => '                    <th><?= __("Nome") ?></th>',
    '                    <th>Sito Web</th>' => '                    <th><?= __("Sito Web") ?></th>',
    '                    <th style="width:25%">Indirizzo</th>' => '                    <th style="width:25%"><?= __("Indirizzo") ?></th>',
    '                    <th>Città</th>' => '                    <th><?= __("Città") ?></th>',
    '                    <th style="width:10%" class="text-center">Azioni</th>' => '                    <th style="width:10%" class="text-center"><?= __("Azioni") ?></th>',
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
    echo "\n✅ editori/index.php - wrapped " . count($replacements) . " strings\n";
} else {
    echo "\nℹ️  editori/index.php - no changes needed\n";
}

echo "\n✅ Translation complete!\n";

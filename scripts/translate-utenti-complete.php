<?php
/**
 * Complete translation of utenti/index.php
 * Wrap all remaining Italian strings with __()
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// New translations needed
$newTranslations = [
    'Utente creato con successo!' => 'User created successfully!',
    'Utente aggiornato con successo!' => 'User updated successfully!',
    'Filtri di Ricerca' => 'Search Filters',
    'Solo sospesi' => 'Suspended only',
    'Nascondi filtri' => 'Hide filters',
    'Mostra filtri' => 'Show filters',
    'Cerca testo' => 'Search text',
    'Ruolo' => 'Role',
    'Tutti i ruoli' => 'All roles',
    'Amministratore' => 'Administrator',
    'Stato' => 'Status',
    'Tutti gli stati' => 'All statuses',
    'Sospeso' => 'Suspended',
    'Registrato da' => 'Registered from',
    'I filtri vengono applicati automaticamente mentre digiti' => 'Filters are applied automatically as you type',
    'Cancella filtri' => 'Clear filters',
    'Elenco Utenti' => 'Users List',
    'Esporta CSV (formato compatibile per import)' => 'Export CSV (compatible format for import)',
    'Esporta PDF' => 'Export PDF',
    'Stampa' => 'Print',
    'utenti' => 'users'
];

$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now wrap Italian strings in utenti/index.php
$file = __DIR__ . '/../app/Views/utenti/index.php';
$content = file_get_contents($file);
$original = $content;

$replacements = [
    // Success messages
    '          <span>Utente creato con successo!</span>' => '          <span><?= __("Utente creato con successo!") ?></span>',
    '          <span>Utente aggiornato con successo!</span>' => '          <span><?= __("Utente aggiornato con successo!") ?></span>',

    // Filters section
    '          Filtri di Ricerca' => '          <?= __("Filtri di Ricerca") ?>',
    '            <i class="fas fa-user-clock mr-2"></i> Solo sospesi' => '            <i class="fas fa-user-clock mr-2"></i> <?= __("Solo sospesi") ?>',
    '            <span>Nascondi filtri</span>' => '            <span><?= __("Nascondi filtri") ?></span>',

    // Filter labels
    '              Cerca testo' => '              <?= __("Cerca testo") ?>',
    '              Ruolo' => '              <?= __("Ruolo") ?>',
    '              <option value="">Tutti i ruoli</option>' => '              <option value=""><?= __("Tutti i ruoli") ?></option>',
    '              <option value="admin">Amministratore</option>' => '              <option value="admin"><?= __("Amministratore") ?></option>',
    '              Stato' => '              <?= __("Stato") ?>',
    '              <option value="">Tutti gli stati</option>' => '              <option value=""><?= __("Tutti gli stati") ?></option>',
    '              <option value="sospeso">Sospeso</option>' => '              <option value="sospeso"><?= __("Sospeso") ?></option>',
    '              Registrato da' => '              <?= __("Registrato da") ?>',

    // Filter info
    '            <span>I filtri vengono applicati automaticamente mentre digiti</span>' => '            <span><?= __("I filtri vengono applicati automaticamente mentre digiti") ?></span>',
    '            Cancella filtri' => '            <?= __("Cancella filtri") ?>',

    // Table header
    '          Elenco Utenti' => '          <?= __("Elenco Utenti") ?>',

    // Export buttons
    '          <button id="export-excel" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="Esporta CSV (formato compatibile per import)">' =>
    '          <button id="export-excel" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="<?= __("Esporta CSV (formato compatibile per import)") ?>">',

    '          <button id="export-pdf" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="Esporta PDF">' =>
    '          <button id="export-pdf" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="<?= __("Esporta PDF") ?>">',

    '          <button id="print-table" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="Stampa">' =>
    '          <button id="print-table" class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-lg transition-colors duration-200 inline-flex items-center" title="<?= __("Stampa") ?>">',

    '            Stampa' => '            <?= __("Stampa") ?>'
];

foreach ($replacements as $search => $replace) {
    $count = 0;
    $content = str_replace($search, $replace, $content, $count);
    if ($count > 0) {
        echo "✓ Replaced: " . substr($search, 0, 50) . "... ($count times)\n";
    }
}

if ($content !== $original) {
    file_put_contents($file, $content);
    echo "\n✅ utenti/index.php - wrapped " . count($replacements) . " strings\n";
} else {
    echo "\nℹ️  utenti/index.php - no changes needed\n";
}

echo "\n✅ Translation complete!\n";

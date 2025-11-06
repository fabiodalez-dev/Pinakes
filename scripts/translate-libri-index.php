<?php
/**
 * Translate libri/index.php (books list page)
 * Comprehensive translation of ALL Italian strings
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// ALL translations for libri index page
$newTranslations = [
    // Breadcrumb & Page Header
    'Gestione Libri' => 'Books Management',
    'Nuovo Libro' => 'New Book',

    // Filters section
    'Filtri di Ricerca' => 'Search Filters',
    'Nascondi filtri' => 'Hide filters',
    'Mostra filtri' => 'Show filters',
    'Cerca testo' => 'Search text',
    'Data acquisizione da' => 'Acquisition date from',
    'Data acquisizione a' => 'Acquisition date to',
    'Data pubblicazione da' => 'Publication date from',
    'Anno pubblicazione da' => 'Publication year from',
    'Anno pubblicazione a' => 'Publication year to',
    'I filtri vengono applicati automaticamente mentre digiti' => 'Filters are applied automatically as you type',
    'Cancella filtri' => 'Clear filters',
    'Salva filtri correnti' => 'Save current filters',

    // Status options
    'Tutti gli stati' => 'All statuses',
    'Prestato' => 'On loan',
    'Riservato' => 'Reserved',
    'Danneggiato' => 'Damaged',
    'Perso' => 'Lost',
    'In Riparazione' => 'Under repair',

    // Table section
    'Elenco Libri' => 'Books List',

    // Empty states
    'Nessun libro trovato' => 'No books found',
    'Prova a modificare i filtri di ricerca' => 'Try changing the search filters',
    'Nessun libro nel database' => 'No books in database',
    'Inizia aggiungendo il primo libro alla collezione' => 'Start by adding the first book to the collection',
    'Aggiungi primo libro' => 'Add first book',

    // Action tooltips
    'Visualizza dettagli libro' => 'View book details',

    // DataTables language
    'Caricamento libri...' => 'Loading books...',
    'Cerca:' => 'Search:',
    'Mostra _MENU_ libri' => 'Show _MENU_ books',
    'Visualizzazione da _START_ a _END_ di _TOTAL_ libri' => 'Showing _START_ to _END_ of _TOTAL_ books',
    '(filtrati da _MAX_ libri totali)' => '(filtered from _MAX_ total books)',
    'Primo' => 'First',
    'Precedente' => 'Previous',
    'Successivo' => 'Next',
    'Ultimo' => 'Last',
    ': attiva per ordinare la colonna in ordine crescente' => ': activate to sort column ascending',
    ': attiva per ordinare la colonna in ordine decrescente' => ': activate to sort column descending',

    // JavaScript messages
    'Impossibile caricare i libri. Controlla la console per i dettagli.' => 'Unable to load books. Check console for details.',
    'Filtri cancellati' => 'Filters cleared',
    'Tutti i filtri sono stati rimossi' => 'All filters have been removed',
    'Filtri salvati' => 'Filters saved',
    'I filtri correnti sono stati salvati nell\'URL' => 'Current filters have been saved in the URL',
    'Generazione CSV in corso...' => 'Generating CSV...',
    'Nessun dato' => 'No data',
    'Non ci sono dati da esportare' => 'There is no data to export',
    'Questa azione non può essere annullata!' => 'This action cannot be undone!',
    'Sì, elimina!' => 'Yes, delete it!',
    'Sei sicuro di voler eliminare questo libro?' => 'Are you sure you want to delete this book?',

    // Autocomplete
    'Nessun risultato trovato' => 'No results found',

    // Filter badges
    'Filtro genere attivo' => 'Genre filter active',
    'Filtro sottogenere attivo' => 'Subgenre filter active',

    // Position display
    'Non assegnata' => 'Not assigned',
    'Non specificato' => 'Not specified',

    // Modal content labels
    'Autore/i:' => 'Author(s):',
    'Editore:' => 'Publisher:',
    'Anno:' => 'Year:',
    'Genere:' => 'Genre:',
    'Posizione:' => 'Position:',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now wrap Italian strings in libri/index.php
$file = __DIR__ . '/../app/Views/libri/index.php';
$content = file_get_contents($file);
$original = $content;

$replacements = [
    // Breadcrumb
    '            <i class="fas fa-home mr-1"></i>Home' => '            <i class="fas fa-home mr-1"></i><?= __("Home") ?>',
    '            <i class="fas fa-book mr-1"></i>Libri' => '            <i class="fas fa-book mr-1"></i><?= __("Libri") ?>',

    // Page header
    '            Gestione Libri' => '            <?= __("Gestione Libri") ?>',
    '            Import CSV' => '            <?= __("Import CSV") ?>',
    '            Nuovo Libro' => '            <?= __("Nuovo Libro") ?>',
    '          Import CSV' => '          <?= __("Import CSV") ?>',
    '          Nuovo Libro' => '          <?= __("Nuovo Libro") ?>',

    // Filters section
    '          Filtri di Ricerca' => '          <?= __("Filtri di Ricerca") ?>',
    '              Cerca testo' => '              <?= __("Cerca testo") ?>',
    '              Data acquisizione da' => '              <?= __("Data acquisizione da") ?>',
    '              Data acquisizione a' => '              <?= __("Data acquisizione a") ?>',
    '              Data pubblicazione da' => '              <?= __("Data pubblicazione da") ?>',
    '              Autore' => '              <?= __("Autore") ?>',
    '              Editore' => '              <?= __("Editore") ?>',
    '              Genere' => '              <?= __("Genere") ?>',
    '              Posizione' => '              <?= __("Posizione") ?>',
    '              Anno pubblicazione da' => '              <?= __("Anno pubblicazione da") ?>',
    '              Anno pubblicazione a' => '              <?= __("Anno pubblicazione a") ?>',
    '            <span>I filtri vengono applicati automaticamente mentre digiti</span>' => '            <span><?= __("I filtri vengono applicati automaticamente mentre digiti") ?></span>',
    '              Salva' => '              <?= __("Salva") ?>',
    '              Cancella filtri' => '              <?= __("Cancella filtri") ?>',

    // Status options
    '              <option value="Prestato">Prestato</option>' => '              <option value="Prestato"><?= __("Prestato") ?></option>',
    '              <option value="Riservato">Riservato</option>' => '              <option value="Riservato"><?= __("Riservato") ?></option>',
    '              <option value="Danneggiato">Danneggiato</option>' => '              <option value="Danneggiato"><?= __("Danneggiato") ?></option>',
    '              <option value="Perso">Perso</option>' => '              <option value="Perso"><?= __("Perso") ?></option>',
    '              <option value="In Riparazione">In Riparazione</option>' => '              <option value="In Riparazione"><?= __("In Riparazione") ?></option>',

    // Table section
    '          Elenco Libri' => '          <?= __("Elenco Libri") ?>',
    '            CSV' => '            <?= __("CSV") ?>',
    '            PDF' => '            <?= __("PDF") ?>',
    '            Stampa' => '            <?= __("Stampa") ?>',

    // JavaScript strings
    '          text.textContent = \'Nascondi filtri\';' => '          text.textContent = \'<?= __("Nascondi filtri") ?>\';',
    '          text.textContent = \'Mostra filtri\';' => '          text.textContent = \'<?= __("Mostra filtri") ?>\';',
    '        b.textContent = \'Filtro genere attivo\';' => '        b.textContent = \'<?= __("Filtro genere attivo") ?>\';',
    '        b2.textContent = \'Filtro sottogenere attivo\';' => '        b2.textContent = \'<?= __("Filtro sottogenere attivo") ?>\';',
    '            suggestions.innerHTML = \'<li class="px-3 py-2 text-gray-500 text-sm">Nessun risultato trovato</li>\';' => '            suggestions.innerHTML = \'<li class="px-3 py-2 text-gray-500 text-sm"><?= __("Nessun risultato trovato") ?></li>\';',
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
    echo "\n✅ libri/index.php - wrapped " . count($replacements) . " strings\n";
} else {
    echo "\nℹ️  libri/index.php - no changes needed\n";
}

echo "\n✅ Translation complete!\n";

<?php
/**
 * Translate collocazione/index.php (location/shelving management page)
 * Comprehensive translation of ALL Italian strings
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// ALL translations for collocazione index page
$newTranslations = [
    // Page header
    'Collocazione' => 'Location',
    'Gestione Collocazione' => 'Location Management',
    'Organizza scaffali, mensole e posizioni per la biblioteca fisica' => 'Organize shelves, levels, and positions for the physical library',

    // Info sections
    'Cos\'è la Collocazione?' => 'What is Location?',
    'La collocazione è l\'indirizzo fisico che identifica dove si trova un libro nella biblioteca.' => 'Location is the physical address that identifies where a book is located in the library.',
    'Esempio:' => 'Example:',
    'Come Funziona' => 'How It Works',
    'Crea gli scaffali (es: A, B, C)' => 'Create shelves (e.g.: A, B, C)',
    'Aggiungi le mensole (livelli) a ogni scaffale' => 'Add levels (shelves) to each bookcase',
    'La posizione viene assegnata automaticamente' => 'Position is assigned automatically',
    'Suggerimenti' => 'Tips',
    'Usa codici semplici (A, B, C...)' => 'Use simple codes (A, B, C...)',
    'Riordina trascinando gli elementi' => 'Reorder by dragging elements',
    'Le posizioni si generano automaticamente' => 'Positions are generated automatically',

    // Stats
    'Scaffali' => 'Bookcases',
    'Mensole' => 'Shelves',
    'Posizioni usate' => 'Positions used',

    // Scaffali section
    'I contenitori fisici principali dove sono organizzati i libri' => 'The main physical containers where books are organized',
    'Codice *' => 'Code *',
    'Scaffale Narrativa' => 'Fiction Bookcase',
    'Aggiungi' => 'Add',
    'Nessuno scaffale. Creane uno per iniziare!' => 'No bookcases. Create one to get started!',
    'Ordine:' => 'Order:',
    'Eliminare questo scaffale? (Solo se vuoto)' => 'Delete this bookcase? (Only if empty)',
    'Trascina per riordinare • Il codice deve essere univoco' => 'Drag to reorder • Code must be unique',

    // Mensole section
    'I livelli (ripiani) all\'interno di ogni scaffale' => 'The levels (shelves) within each bookcase',
    'Scaffale *' => 'Bookcase *',
    'Seleziona...' => 'Select...',
    'Livello *' => 'Level *',
    'Nessuna mensola. Creane una per iniziare!' => 'No shelves. Create one to get started!',
    'Livello' => 'Level',
    'Eliminare questa mensola? (Solo se vuota)' => 'Delete this shelf? (Only if empty)',
    'Trascina per riordinare • Ogni scaffale + livello deve essere univoco' => 'Drag to reorder • Each bookcase + level must be unique',

    // Libri per Collocazione section
    'Libri per Collocazione' => 'Books by Location',
    'Visualizza e esporta l\'elenco dei libri per posizione fisica' => 'View and export the list of books by physical position',
    'Esporta CSV' => 'Export CSV',
    'Filtra per Scaffale' => 'Filter by Bookcase',
    'Tutti gli scaffali' => 'All bookcases',
    'Filtra per Mensola' => 'Filter by Shelf',
    'Tutte le mensole' => 'All shelves',
    'Caricamento...' => 'Loading...',
    'La collocazione può essere assegnata automaticamente o inserita manualmente durante la creazione/modifica del libro' => 'Location can be assigned automatically or entered manually during book creation/editing',

    // JavaScript messages
    'Nessun libro con collocazione trovato' => 'No books with location found',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now wrap Italian strings in collocazione/index.php
$file = __DIR__ . '/../app/Views/collocazione/index.php';
$content = file_get_contents($file);
$original = $content;

$replacements = [
    // Breadcrumb
    '            <i class="fas fa-home mr-1"></i>Home' => '            <i class="fas fa-home mr-1"></i><?= __("Home") ?>',
    '          <i class="fas fa-warehouse mr-1"></i>Collocazione' => '          <i class="fas fa-warehouse mr-1"></i><?= __("Collocazione") ?>',

    // Page header
    '            Gestione Collocazione' => '            <?= __("Gestione Collocazione") ?>',
    '          <p class="text-sm text-gray-600 mt-1">Organizza scaffali, mensole e posizioni per la biblioteca fisica</p>' => '          <p class="text-sm text-gray-600 mt-1"><?= __("Organizza scaffali, mensole e posizioni per la biblioteca fisica") ?></p>',

    // Info sections
    '              Cos\'è la Collocazione?' => '              <?= __("Cos\'è la Collocazione?") ?>',
    '              La collocazione è l\'<strong>indirizzo fisico</strong> che identifica dove si trova un libro nella biblioteca.' => '              <?= __("La collocazione è l\'indirizzo fisico che identifica dove si trova un libro nella biblioteca.") ?>',
    '                <span>Esempio: <code class="bg-gray-100 px-2 py-0.5 rounded">A.2.15</code></span>' => '                <span><?= __("Esempio:") ?> <code class="bg-gray-100 px-2 py-0.5 rounded">A.2.15</code></span>',
    '              Come Funziona' => '              <?= __("Come Funziona") ?>',
    '                <span>Crea gli <strong>scaffali</strong> (es: A, B, C)</span>' => '                <span><?= __("Crea gli scaffali (es: A, B, C)") ?></span>',
    '                <span>Aggiungi le <strong>mensole</strong> (livelli) a ogni scaffale</span>' => '                <span><?= __("Aggiungi le mensole (livelli) a ogni scaffale") ?></span>',
    '                <span>La <strong>posizione</strong> viene assegnata automaticamente</span>' => '                <span><?= __("La posizione viene assegnata automaticamente") ?></span>',
    '              Suggerimenti' => '              <?= __("Suggerimenti") ?>',
    '                <span>Usa codici semplici (A, B, C...)</span>' => '                <span><?= __("Usa codici semplici (A, B, C...)") ?></span>',
    '                <span>Riordina trascinando gli elementi</span>' => '                <span><?= __("Riordina trascinando gli elementi") ?></span>',
    '                <span>Le posizioni si generano automaticamente</span>' => '                <span><?= __("Le posizioni si generano automaticamente") ?></span>',

    // Stats
    '              <p class="text-sm text-gray-600">Scaffali</p>' => '              <p class="text-sm text-gray-600"><?= __("Scaffali") ?></p>',
    '              <p class="text-sm text-gray-600">Mensole</p>' => '              <p class="text-sm text-gray-600"><?= __("Mensole") ?></p>',
    '              <p class="text-sm text-gray-600">Posizioni usate</p>' => '              <p class="text-sm text-gray-600"><?= __("Posizioni usate") ?></p>',

    // Scaffali section
    '              Scaffali' => '              <?= __("Scaffali") ?>',
    '            <p class="text-sm text-gray-600 mt-1">I contenitori fisici principali dove sono organizzati i libri</p>' => '            <p class="text-sm text-gray-600 mt-1"><?= __("I contenitori fisici principali dove sono organizzati i libri") ?></p>',
    '                  <label class="text-sm font-medium text-gray-700 mb-1 block">Codice *</label>' => '                  <label class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Codice *") ?></label>',
    '                    Aggiungi' => '                    <?= __("Aggiungi") ?>',
    '                    <p>Nessuno scaffale. Creane uno per iniziare!</p>' => '                    <p><?= __("Nessuno scaffale. Creane uno per iniziare!") ?></p>',
    '                          <span class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded">Ordine: <span class="order-label"><?php echo isset($s[\'ordine\']) ? (int)$s[\'ordine\'] : 0; ?></span></span>' => '                          <span class="text-xs text-gray-500 bg-gray-100 px-2 py-1 rounded"><?= __("Ordine:") ?> <span class="order-label"><?php echo isset($s[\'ordine\']) ? (int)$s[\'ordine\'] : 0; ?></span></span>',
    '                          <form method="post" action="/admin/collocazione/scaffali/<?php echo (int)$s[\'id\']; ?>/delete" class="inline" onsubmit="return confirm(__(\'Eliminare questo scaffale? (Solo se vuoto)\'));">' => '                          <form method="post" action="/admin/collocazione/scaffali/<?php echo (int)$s[\'id\']; ?>/delete" class="inline" onsubmit="return confirm(__(\'<?= __("Eliminare questo scaffale? (Solo se vuoto)") ?>\'));">',
    '              Trascina per riordinare • Il codice deve essere univoco' => '              <?= __("Trascina per riordinare • Il codice deve essere univoco") ?>',

    // Mensole section
    '              Mensole' => '              <?= __("Mensole") ?>',
    '            <p class="text-sm text-gray-600 mt-1">I livelli (ripiani) all\'interno di ogni scaffale</p>' => '            <p class="text-sm text-gray-600 mt-1"><?= __("I livelli (ripiani) all\'interno di ogni scaffale") ?></p>',
    '                  <label class="text-sm font-medium text-gray-700 mb-1 block">Scaffale *</label>' => '                  <label class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Scaffale *") ?></label>',
    '                    <option value="">Seleziona...</option>' => '                    <option value=""><?= __("Seleziona...") ?></option>',
    '                  <label class="text-sm font-medium text-gray-700 mb-1 block">Livello *</label>' => '                  <label class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Livello *") ?></label>',
    '                    <p>Nessuna mensola. Creane una per iniziare!</p>' => '                    <p><?= __("Nessuna mensola. Creane una per iniziare!") ?></p>',
    '                            <span class="text-gray-700 ml-2">Livello <?php echo (int)$m[\'numero_livello\']; ?></span>' => '                            <span class="text-gray-700 ml-2"><?= __("Livello") ?> <?php echo (int)$m[\'numero_livello\']; ?></span>',
    '                          <form method="post" action="/admin/collocazione/mensole/<?php echo (int)$m[\'id\']; ?>/delete" class="inline" onsubmit="return confirm(__(\'Eliminare questa mensola? (Solo se vuota)\'));">' => '                          <form method="post" action="/admin/collocazione/mensole/<?php echo (int)$m[\'id\']; ?>/delete" class="inline" onsubmit="return confirm(__(\'<?= __("Eliminare questa mensola? (Solo se vuota)") ?>\'));">',
    '              Trascina per riordinare • Ogni scaffale + livello deve essere univoco' => '              <?= __("Trascina per riordinare • Ogni scaffale + livello deve essere univoco") ?>',

    // Libri per Collocazione
    '                  Libri per Collocazione' => '                  <?= __("Libri per Collocazione") ?>',
    '                <p class="text-sm text-gray-600 mt-1">Visualizza e esporta l\'elenco dei libri per posizione fisica</p>' => '                <p class="text-sm text-gray-600 mt-1"><?= __("Visualizza e esporta l\'elenco dei libri per posizione fisica") ?></p>',
    '                Esporta CSV' => '                <?= __("Esporta CSV") ?>',
    '                <label class="text-sm font-medium text-gray-700 mb-1 block">Filtra per Scaffale</label>' => '                <label class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Filtra per Scaffale") ?></label>',
    '                  <option value="">Tutti gli scaffali</option>' => '                  <option value=""><?= __("Tutti gli scaffali") ?></option>',
    '                <label class="text-sm font-medium text-gray-700 mb-1 block">Filtra per Mensola</label>' => '                <label class="text-sm font-medium text-gray-700 mb-1 block"><?= __("Filtra per Mensola") ?></label>',
    '                  <option value="">Tutte le mensole</option>' => '                  <option value=""><?= __("Tutte le mensole") ?></option>',
    '                        <i class="fas fa-spinner fa-spin mr-2"></i>Caricamento...' => '                        <i class="fas fa-spinner fa-spin mr-2"></i><?= __("Caricamento...") ?>',
    '              La collocazione può essere assegnata automaticamente o inserita manualmente durante la creazione/modifica del libro' => '              <?= __("La collocazione può essere assegnata automaticamente o inserita manualmente durante la creazione/modifica del libro") ?>',

    // JavaScript
    '      tbody.innerHTML = \'<tr><td colspan="5" class="px-4 py-8 text-center text-gray-500"><i class="fas fa-inbox mr-2"></i>Nessun libro con collocazione trovato</td></tr>\';' => '      tbody.innerHTML = \'<tr><td colspan="5" class="px-4 py-8 text-center text-gray-500"><i class="fas fa-inbox mr-2"></i>\' + __(\'Nessun libro con collocazione trovato\') + \'</td></tr>\';',
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
    echo "\n✅ collocazione/index.php - wrapped " . count($replacements) . " strings\n";
} else {
    echo "\nℹ️  collocazione/index.php - no changes needed\n";
}

echo "\n✅ Translation complete!\n";

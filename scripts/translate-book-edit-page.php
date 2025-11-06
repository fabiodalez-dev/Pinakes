<?php
/**
 * Translate book edit page and book form partial
 * Files: modifica_libro.php, partials/book_form.php
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// ALL translations for book edit page
$newTranslations = [
    // modifica_libro.php
    'Home' => 'Home',
    'Modifica Libro' => 'Edit Book',
    'Aggiorna i dettagli del libro:' => 'Update book details:',
    'I campi con * sono obbligatori' => 'Fields with * are required',
    'Aggiorna da ISBN' => 'Update from ISBN',
    'Aggiorna Dati' => 'Update Data',
    'Copertina Attuale' => 'Current Cover',
    'Nessuna copertina caricata' => 'No cover uploaded',

    // book_form.php - Labels
    'Sottotitolo' => 'Subtitle',
    'Edizione' => 'Edition',
    'Lingua' => 'Language',
    'Lingua originale del libro' => 'Original language of the book',
    'Editore' => 'Publisher',
    'Autori' => 'Authors',
    'Puoi selezionare più autori o aggiungerne di nuovi digitando il nome' => 'You can select multiple authors or add new ones by typing the name',
    'Disponibilità' => 'Availability',
    'Status attuale di questa copia del libro' => 'Current status of this book copy',
    'Sezione' => 'Section',
    'Genere' => 'Genre',
    'Radice' => 'Root',
    'Genere letterario del libro' => 'Literary genre of the book',
    'Sottogenere' => 'Subgenre',
    'Inserisci parole chiave separate da virgole per facilitare la ricerca' => 'Enter keywords separated by commas to facilitate search',
    'Formato' => 'Format',
    'Dimensioni' => 'Dimensions',
    'Collana' => 'Series',
    'Copertina attuale' => 'Current cover',
    'Scaffale' => 'Shelf',
    'Mensola' => 'Rack',
    'Posizione progressiva' => 'Progressive position',
    'Genera automaticamente' => 'Generate automatically',
    'Collocazione calcolata' => 'Calculated location',
    'Suggerisci collocazione' => 'Suggest location',
    'La copertina verrà rimossa al salvataggio del libro' => 'Cover will be removed when saving the book',
    'Nessun sottogenere' => 'No subgenre',
    'Errore nella ricerca' => 'Search error',
    'Anteprima non disponibile' => 'Preview not available',
    'Copertina recuperata automaticamente' => 'Cover retrieved automatically',

    // Stati libro
    'Disponibile' => 'Available',
    'Prestato' => 'On loan',
    'Riservato' => 'Reserved',
    'Danneggiato' => 'Damaged',
    'Perso' => 'Lost',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations for book edit page\n";
echo "   Previous count: " . count($existing) . "\n";
echo "   New translations added: " . (count($merged) - count($existing)) . "\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now wrap Italian strings

// File 1: modifica_libro.php
$file1 = __DIR__ . '/../app/Views/libri/modifica_libro.php';
$content1 = file_get_contents($file1);
$original1 = $content1;

$replacements1 = [
    '              <i class="fas fa-home mr-1"></i>Home' => '              <i class="fas fa-home mr-1"></i><?= __("Home") ?>',
    '              <i class="fas fa-book mr-1"></i>Libri' => '              <i class="fas fa-book mr-1"></i><?= __("Libri") ?>',
    '          Modifica Libro' => '          <?= __("Modifica Libro") ?>',
    '          Aggiorna i dettagli del libro: <a href="/admin/libri/<?php echo (int)($book[\'id\'] ?? 0); ?>" class="text-blue-600 hover:text-blue-800 hover:underline font-semibold transition-colors"><strong><?php echo HtmlHelper::e($book[\'titolo\'] ?? \'\'); ?></strong></a>' => '          <?= __("Aggiorna i dettagli del libro:") ?> <a href="/admin/libri/<?php echo (int)($book[\'id\'] ?? 0); ?>" class="text-blue-600 hover:text-blue-800 hover:underline font-semibold transition-colors"><strong><?php echo HtmlHelper::e($book[\'titolo\'] ?? \'\'); ?></strong></a>',
    '          I campi con * sono obbligatori' => '          <?= __("I campi con * sono obbligatori") ?>',
    '          Aggiorna da ISBN' => '          <?= __("Aggiorna da ISBN") ?>',
    '            Aggiorna Dati' => '            <?= __("Aggiorna Dati") ?>',
    '          Copertina Attuale' => '          <?= __("Copertina Attuale") ?>',
    '            <span class="text-sm text-gray-500">Nessuna copertina caricata</span>' => '            <span class="text-sm text-gray-500"><?= __("Nessuna copertina caricata") ?></span>',
];

foreach ($replacements1 as $search => $replace) {
    $content1 = str_replace($search, $replace, $content1);
}

if ($content1 !== $original1) {
    file_put_contents($file1, $content1);
    echo "✅ modifica_libro.php - wrapped " . count($replacements1) . " strings\n";
} else {
    echo "ℹ️  modifica_libro.php - no changes needed\n";
}

// File 2: book_form.php (molto più complesso)
$file2 = __DIR__ . '/../app/Views/libri/partials/book_form.php';
$content2 = file_get_contents($file2);
$original2 = $content2;

$replacements2 = [
    // Labels
    '              <label for="sottotitolo" class="form-label">Sottotitolo</label>' => '              <label for="sottotitolo" class="form-label"><?= __("Sottotitolo") ?></label>',
    '              <label for="edizione" class="form-label">Edizione</label>' => '              <label for="edizione" class="form-label"><?= __("Edizione") ?></label>',
    '              <label for="lingua" class="form-label">Lingua</label>' => '              <label for="lingua" class="form-label"><?= __("Lingua") ?></label>',
    '              <p class="text-xs text-gray-500 mt-1">Lingua originale del libro</p>' => '              <p class="text-xs text-gray-500 mt-1"><?= __("Lingua originale del libro") ?></p>',
    '            <label for="editore_field" class="form-label">Editore</label>' => '            <label for="editore_field" class="form-label"><?= __("Editore") ?></label>',
    '            <label for="autori_select" class="form-label">Autori</label>' => '            <label for="autori_select" class="form-label"><?= __("Autori") ?></label>',
    '            <p class="text-xs text-gray-500 mt-1">Puoi selezionare più autori o aggiungerne di nuovi digitando il nome</p>' => '            <p class="text-xs text-gray-500 mt-1"><?= __("Puoi selezionare più autori o aggiungerne di nuovi digitando il nome") ?></p>',
    '            <label for="stato" class="form-label">Disponibilità</label>' => '            <label for="stato" class="form-label"><?= __("Disponibilità") ?></label>',
    '            <p class="text-xs text-gray-500 mt-1">Status attuale di questa copia del libro</p>' => '            <p class="text-xs text-gray-500 mt-1"><?= __("Status attuale di questa copia del libro") ?></p>',
    '              <label for="dewey_l3" class="form-label">Sezione</label>' => '              <label for="dewey_l3" class="form-label"><?= __("Sezione") ?></label>',
    '          <h3 class="text-lg font-semibold text-gray-900 mt-6 mb-4">Genere</h3>' => '          <h3 class="text-lg font-semibold text-gray-900 mt-6 mb-4"><?= __("Genere") ?></h3>',
    '              <label for="radice_select" class="form-label">Radice</label>' => '              <label for="radice_select" class="form-label"><?= __("Radice") ?></label>',
    '              <label for="genere_select" class="form-label">Genere</label>' => '              <label for="genere_select" class="form-label"><?= __("Genere") ?></label>',
    '              <p class="text-xs text-gray-500 mt-1" id="genere_hint">Genere letterario del libro</p>' => '              <p class="text-xs text-gray-500 mt-1" id="genere_hint"><?= __("Genere letterario del libro") ?></p>',
    '              <label for="sottogenere_select" class="form-label">Sottogenere</label>' => '              <label for="sottogenere_select" class="form-label"><?= __("Sottogenere") ?></label>',
    '            <p class="text-xs text-gray-500 mt-1">Inserisci parole chiave separate da virgole per facilitare la ricerca</p>' => '            <p class="text-xs text-gray-500 mt-1"><?= __("Inserisci parole chiave separate da virgole per facilitare la ricerca") ?></p>',
    '              <label for="formato" class="form-label">Formato</label>' => '              <label for="formato" class="form-label"><?= __("Formato") ?></label>',
    '            <label for="dimensioni" class="form-label">Dimensioni</label>' => '            <label for="dimensioni" class="form-label"><?= __("Dimensioni") ?></label>',
    '              <label for="collana" class="form-label">Collana</label>' => '              <label for="collana" class="form-label"><?= __("Collana") ?></label>',
    '                    <span class="text-xs text-gray-500">Copertina attuale</span>' => '                    <span class="text-xs text-gray-500"><?= __("Copertina attuale") ?></span>',
    '              <label for="scaffale_id" class="form-label">Scaffale</label>' => '              <label for="scaffale_id" class="form-label"><?= __("Scaffale") ?></label>',
    '              <label class="form-label">Mensola</label>' => '              <label class="form-label"><?= __("Mensola") ?></label>',
    '              <label for="posizione_progressiva_input" class="form-label">Posizione progressiva</label>' => '              <label for="posizione_progressiva_input" class="form-label"><?= __("Posizione progressiva") ?></label>',
    '                <button type="button" id="btnAutoPosition" class="btn-outline w-full sm:w-auto"><i class="fas fa-sync mr-2"></i>Genera automaticamente</button>' => '                <button type="button" id="btnAutoPosition" class="btn-outline w-full sm:w-auto"><i class="fas fa-sync mr-2"></i><?= __("Genera automaticamente") ?></button>',
    '              <label for="collocazione_preview" class="form-label">Collocazione calcolata</label>' => '              <label for="collocazione_preview" class="form-label"><?= __("Collocazione calcolata") ?></label>',
    '            <button type="button" id="btnSuggestCollocazione" class="btn-outline"><i class="fas fa-magic mr-2"></i>Suggerisci collocazione</button>' => '            <button type="button" id="btnSuggestCollocazione" class="btn-outline"><i class="fas fa-magic mr-2"></i><?= __("Suggerisci collocazione") ?></button>',
    '            <span>La copertina verrà rimossa al salvataggio del libro</span>' => '            <span><?= __("La copertina verrà rimossa al salvataggio del libro") ?></span>',

    // Stati
    '              <option value="Disponibile" <?php echo strcasecmp($statoCorrente, \'Disponibile\') === 0 ? \'selected\' : \'\'; ?>>Disponibile</option>' => '              <option value="Disponibile" <?php echo strcasecmp($statoCorrente, \'Disponibile\') === 0 ? \'selected\' : \'\'; ?>><?= __("Disponibile") ?></option>',
    '              <option value="Prestato" <?php echo strcasecmp($statoCorrente, \'Prestato\') === 0 ? \'selected\' : \'\'; ?>>Prestato</option>' => '              <option value="Prestato" <?php echo strcasecmp($statoCorrente, \'Prestato\') === 0 ? \'selected\' : \'\'; ?>><?= __("Prestato") ?></option>',
    '              <option value="Riservato" <?php echo strcasecmp($statoCorrente, \'Riservato\') === 0 ? \'selected\' : \'\'; ?>>Riservato</option>' => '              <option value="Riservato" <?php echo strcasecmp($statoCorrente, \'Riservato\') === 0 ? \'selected\' : \'\'; ?>><?= __("Riservato") ?></option>',
    '              <option value="Danneggiato" <?php echo strcasecmp($statoCorrente, \'Danneggiato\') === 0 ? \'selected\' : \'\'; ?>>Danneggiato</option>' => '              <option value="Danneggiato" <?php echo strcasecmp($statoCorrente, \'Danneggiato\') === 0 ? \'selected\' : \'\'; ?>><?= __("Danneggiato") ?></option>',
    '              <option value="Perso" <?php echo strcasecmp($statoCorrente, \'Perso\') === 0 ? \'selected\' : \'\'; ?>>Perso</option>' => '              <option value="Perso" <?php echo strcasecmp($statoCorrente, \'Perso\') === 0 ? \'selected\' : \'\'; ?>><?= __("Perso") ?></option>',

    // JavaScript strings
    '        sottogenereSelect.innerHTML = \'<option value="0">Nessun sottogenere</option>\';' => '        sottogenereSelect.innerHTML = \'<option value="0"><?= __("Nessun sottogenere") ?></option>\';',
    '                    suggestions.innerHTML = \'<li class="px-4 py-2 text-red-500">Errore nella ricerca</li>\';' => '                    suggestions.innerHTML = \'<li class="px-4 py-2 text-red-500"><?= __("Errore nella ricerca") ?></li>\';',
    '                <p class="text-sm text-gray-600 mb-2">Anteprima non disponibile</p>' => '                <p class="text-sm text-gray-600 mb-2"><?= __("Anteprima non disponibile") ?></p>',
    '                    <span>Copertina recuperata automaticamente</span>' => '                    <span><?= __("Copertina recuperata automaticamente") ?></span>',
];

foreach ($replacements2 as $search => $replace) {
    $content2 = str_replace($search, $replace, $content2);
}

if ($content2 !== $original2) {
    file_put_contents($file2, $content2);
    echo "✅ book_form.php - wrapped " . count($replacements2) . " strings\n";
} else {
    echo "ℹ️  book_form.php - no changes needed\n";
}

echo "\n📊 Summary:\n";
echo "   Translations added: " . count($newTranslations) . "\n";
echo "   Files updated: 2\n";
echo "   Total translations in en_US.json: " . count($merged) . "\n";
echo "\n✅ Book edit page completely translated!\n";

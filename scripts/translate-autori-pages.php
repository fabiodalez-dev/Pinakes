<?php
/**
 * Translate ALL autori pages: crea_autore.php, modifica_autore.php, scheda_autore.php
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

$newTranslations = [
    // Common strings
    'Nuovo' => 'New',
    'Informazioni Base' => 'Basic Information',
    'Nome completo' => 'Full Name',
    'Pseudonimo' => 'Pseudonym',
    'Data di nascita' => 'Date of Birth',
    'Data di morte' => 'Date of Death',
    'Lascia vuoto se l\'autore è vivente' => 'Leave blank if the author is alive',
    'Nazionalità' => 'Nationality',
    'Sito Web' => 'Website',
    'Sito web ufficiale dell\'autore (se disponibile)' => 'Author\'s official website (if available)',
    'Biografia' => 'Biography',
    'Biografia dell\'autore' => 'Author Biography',
    'Una descrizione completa aiuta gli utenti a conoscere meglio l\'autore' => 'A complete description helps users get to know the author better',
    'Annulla' => 'Cancel',

    // crea_autore.php
    'Aggiungi Nuovo Autore' => 'Add New Author',
    'Compila i dettagli dell\'autore per aggiungerlo alla biblioteca' => 'Fill in the author details to add them to the library',
    'Salva Autore' => 'Save Author',

    // modifica_autore.php
    'Modifica Autore' => 'Edit Author',
    'Aggiorna i dettagli dell\'autore: %s' => 'Update author details: %s',
    'Salva Modifiche' => 'Save Changes',

    // scheda_autore.php
    'Nato il %s' => 'Born on %s',
    'Deceduto il %s' => 'Died on %s',
    'Creato il' => 'Created on',
    'Ultimo aggiornamento' => 'Last Update',
    '%d titoli' => '%d titles',
    'Nessun libro trovato' => 'No books found',
    'Questo autore non ha ancora libri registrati nella biblioteca.' => 'This author has no books registered in the library yet.',
    'Editore: %s' => 'Publisher: %s',
    'ISBN13: %s' => 'ISBN13: %s',
    'Dettagli' => 'Details',
];

$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Fix crea_autore.php
$file1 = __DIR__ . '/../app/Views/autori/crea_autore.php';
$content1 = file_get_contents($file1);
$original1 = $content1;

$replacements1 = [
    '        <li class="text-gray-900 font-medium">Nuovo</li>' =>
    '        <li class="text-gray-900 font-medium"><?= __("Nuovo") ?></li>',

    '        Aggiungi Nuovo Autore' =>
    '        <?= __("Aggiungi Nuovo Autore") ?>',

    '      <p class="text-gray-600">Compila i dettagli dell\'autore per aggiungerlo alla biblioteca</p>' =>
    '      <p class="text-gray-600"><?= __("Compila i dettagli dell\'autore per aggiungerlo alla biblioteca") ?></p>',

    '            Informazioni Base' =>
    '            <?= __("Informazioni Base") ?>',

    '              <label for="nome" class="form-label">
                Nome completo <span class="text-red-500">*</span>
              </label>' =>
    '              <label for="nome" class="form-label">
                <?= __("Nome completo") ?> <span class="text-red-500">*</span>
              </label>',

    '              <label for="pseudonimo" class="form-label">Pseudonimo</label>' =>
    '              <label for="pseudonimo" class="form-label"><?= __("Pseudonimo") ?></label>',

    '              <label for="data_nascita" class="form-label">Data di nascita</label>' =>
    '              <label for="data_nascita" class="form-label"><?= __("Data di nascita") ?></label>',

    '              <label for="data_morte" class="form-label">Data di morte</label>' =>
    '              <label for="data_morte" class="form-label"><?= __("Data di morte") ?></label>',

    '              <p class="text-xs text-gray-500 mt-1">Lascia vuoto se l\'autore è vivente</p>' =>
    '              <p class="text-xs text-gray-500 mt-1"><?= __("Lascia vuoto se l\'autore è vivente") ?></p>',

    '            <label for="nazionalita" class="form-label">Nazionalità</label>' =>
    '            <label for="nazionalita" class="form-label"><?= __("Nazionalità") ?></label>',

    '            <label for="sito_web" class="form-label">Sito Web</label>' =>
    '            <label for="sito_web" class="form-label"><?= __("Sito Web") ?></label>',

    '            <p class="text-xs text-gray-500 mt-1">Sito web ufficiale dell\'autore (se disponibile)</p>' =>
    '            <p class="text-xs text-gray-500 mt-1"><?= __("Sito web ufficiale dell\'autore (se disponibile)") ?></p>',

    '            Biografia' =>
    '            <?= __("Biografia") ?>',

    '            <label for="biografia" class="form-label">Biografia dell\'autore</label>' =>
    '            <label for="biografia" class="form-label"><?= __("Biografia dell\'autore") ?></label>',

    '            <p class="text-xs text-gray-500 mt-1">Una descrizione completa aiuta gli utenti a conoscere meglio l\'autore</p>' =>
    '            <p class="text-xs text-gray-500 mt-1"><?= __("Una descrizione completa aiuta gli utenti a conoscere meglio l\'autore") ?></p>',

    '          Annulla' =>
    '          <?= __("Annulla") ?>',

    '          Salva Autore' =>
    '          <?= __("Salva Autore") ?>',
];

foreach ($replacements1 as $search => $replace) {
    $content1 = str_replace($search, $replace, $content1);
}

if ($content1 !== $original1) {
    file_put_contents($file1, $content1);
    echo "✅ crea_autore.php - Fixed " . count($replacements1) . " strings\n";
}

// Fix modifica_autore.php
$file2 = __DIR__ . '/../app/Views/autori/modifica_autore.php';
$content2 = file_get_contents($file2);
$original2 = $content2;

$replacements2 = [
    '        Modifica Autore' =>
    '        <?= __("Modifica Autore") ?>',

    '      <p class="text-gray-600">Aggiorna i dettagli dell\'autore: <strong><?= HtmlHelper::e($author[\'nome\'] ?? \'\') ?></strong></p>' =>
    '      <p class="text-gray-600"><?= sprintf(__("Aggiorna i dettagli dell\'autore: %s"), \'<strong>\' . HtmlHelper::e($author[\'nome\'] ?? \'\') . \'</strong>\') ?></p>',

    '            Informazioni Base' =>
    '            <?= __("Informazioni Base") ?>',

    '              <label for="nome" class="form-label">
                Nome completo <span class="text-red-500">*</span>
              </label>' =>
    '              <label for="nome" class="form-label">
                <?= __("Nome completo") ?> <span class="text-red-500">*</span>
              </label>',

    '              <label for="pseudonimo" class="form-label">Pseudonimo</label>' =>
    '              <label for="pseudonimo" class="form-label"><?= __("Pseudonimo") ?></label>',

    '              <label for="data_nascita" class="form-label">Data di nascita</label>' =>
    '              <label for="data_nascita" class="form-label"><?= __("Data di nascita") ?></label>',

    '              <label for="data_morte" class="form-label">Data di morte</label>' =>
    '              <label for="data_morte" class="form-label"><?= __("Data di morte") ?></label>',

    '              <p class="text-xs text-gray-500 mt-1">Lascia vuoto se l\'autore è vivente</p>' =>
    '              <p class="text-xs text-gray-500 mt-1"><?= __("Lascia vuoto se l\'autore è vivente") ?></p>',

    '            <label for="nazionalita" class="form-label">Nazionalità</label>' =>
    '            <label for="nazionalita" class="form-label"><?= __("Nazionalità") ?></label>',

    '            <label for="sito_web" class="form-label">Sito Web</label>' =>
    '            <label for="sito_web" class="form-label"><?= __("Sito Web") ?></label>',

    '            <p class="text-xs text-gray-500 mt-1">Sito web ufficiale dell\'autore (se disponibile)</p>' =>
    '            <p class="text-xs text-gray-500 mt-1"><?= __("Sito web ufficiale dell\'autore (se disponibile)") ?></p>',

    '            Biografia' =>
    '            <?= __("Biografia") ?>',

    '            <label for="biografia" class="form-label">Biografia dell\'autore</label>' =>
    '            <label for="biografia" class="form-label"><?= __("Biografia dell\'autore") ?></label>',

    '            <p class="text-xs text-gray-500 mt-1">Una descrizione completa aiuta gli utenti a conoscere meglio l\'autore</p>' =>
    '            <p class="text-xs text-gray-500 mt-1"><?= __("Una descrizione completa aiuta gli utenti a conoscere meglio l\'autore") ?></p>',

    '          Annulla' =>
    '          <?= __("Annulla") ?>',

    '          Salva Modifiche' =>
    '          <?= __("Salva Modifiche") ?>',
];

foreach ($replacements2 as $search => $replace) {
    $content2 = str_replace($search, $replace, $content2);
}

if ($content2 !== $original2) {
    file_put_contents($file2, $content2);
    echo "✅ modifica_autore.php - Fixed " . count($replacements2) . " strings\n";
}

// Fix scheda_autore.php
$file3 = __DIR__ . '/../app/Views/autori/scheda_autore.php';
$content3 = file_get_contents($file3);
$original3 = $content3;

$replacements3 = [
    '            <p class="text-sm text-gray-600">Nato il <?= date(\'d/m/Y\', strtotime($author[\'data_nascita\'])) ?></p>' =>
    '            <p class="text-sm text-gray-600"><?= sprintf(__("Nato il %s"), date(\'d/m/Y\', strtotime($author[\'data_nascita\']))) ?></p>',

    '            <p class="text-sm text-gray-600">Deceduto il <?= date(\'d/m/Y\', strtotime($author[\'data_morte\'])) ?></p>' =>
    '            <p class="text-sm text-gray-600"><?= sprintf(__("Deceduto il %s"), date(\'d/m/Y\', strtotime($author[\'data_morte\']))) ?></p>',

    '                <div class="text-xs text-gray-500 uppercase tracking-wider">Nome completo</div>' =>
    '                <div class="text-xs text-gray-500 uppercase tracking-wider"><?= __("Nome completo") ?></div>',

    '                <div class="text-xs text-gray-500 uppercase tracking-wider">Pseudonimo</div>' =>
    '                <div class="text-xs text-gray-500 uppercase tracking-wider"><?= __("Pseudonimo") ?></div>',

    '                <div class="text-xs text-gray-500 uppercase tracking-wider">Data di nascita</div>' =>
    '                <div class="text-xs text-gray-500 uppercase tracking-wider"><?= __("Data di nascita") ?></div>',

    '                <div class="text-xs text-gray-500 uppercase tracking-wider">Data di morte</div>' =>
    '                <div class="text-xs text-gray-500 uppercase tracking-wider"><?= __("Data di morte") ?></div>',

    '                <div class="text-xs text-gray-500 uppercase tracking-wider">Nazionalità</div>' =>
    '                <div class="text-xs text-gray-500 uppercase tracking-wider"><?= __("Nazionalità") ?></div>',

    '                <div class="text-xs text-gray-500 uppercase tracking-wider">Sito web</div>' =>
    '                <div class="text-xs text-gray-500 uppercase tracking-wider"><?= __("Sito web") ?></div>',

    '                <div class="text-xs text-gray-500 uppercase tracking-wider">Creato il</div>' =>
    '                <div class="text-xs text-gray-500 uppercase tracking-wider"><?= __("Creato il") ?></div>',

    '                <div class="text-xs text-gray-500 uppercase tracking-wider">Ultimo aggiornamento</div>' =>
    '                <div class="text-xs text-gray-500 uppercase tracking-wider"><?= __("Ultimo aggiornamento") ?></div>',

    '            <span class="text-sm font-medium text-gray-700"><?= $totalBooks ?> titoli</span>' =>
    '            <span class="text-sm font-medium text-gray-700"><?= sprintf(__("%d titoli"), $totalBooks) ?></span>',

    '              <h3 class="text-lg font-semibold text-gray-700">Nessun libro trovato</h3>' =>
    '              <h3 class="text-lg font-semibold text-gray-700"><?= __("Nessun libro trovato") ?></h3>',

    '              <p class="text-gray-600 mt-2">Questo autore non ha ancora libri registrati nella biblioteca.</p>' =>
    '              <p class="text-gray-600 mt-2"><?= __("Questo autore non ha ancora libri registrati nella biblioteca.") ?></p>',

    '                        <p class="text-sm text-gray-600 mb-1"><strong>Editore:</strong> <?= HtmlHelper::e($book[\'editore\'] ?? \'Non specificato\') ?></p>' =>
    '                        <p class="text-sm text-gray-600 mb-1"><strong><?= sprintf(__("Editore: %s"), HtmlHelper::e($book[\'editore\'] ?? __(\'Non specificato\'))) ?></strong></p>',

    '                        <p class="text-sm text-gray-600"><strong>ISBN13:</strong> <?= HtmlHelper::e($book[\'isbn13\'] ?? \'N/A\') ?></p>' =>
    '                        <p class="text-sm text-gray-600"><strong><?= sprintf(__("ISBN13: %s"), HtmlHelper::e($book[\'isbn13\'] ?? \'N/A\')) ?></strong></p>',

    '                      Dettagli' =>
    '                      <?= __("Dettagli") ?>',
];

foreach ($replacements3 as $search => $replace) {
    $content3 = str_replace($search, $replace, $content3);
}

if ($content3 !== $original3) {
    file_put_contents($file3, $content3);
    echo "✅ scheda_autore.php - Fixed " . count($replacements3) . " strings\n";
}

echo "\n✅ All autori pages translated!\n";

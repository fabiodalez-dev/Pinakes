<?php
/**
 * Fix hardcoded Italian strings in utenti/index.php SweetAlert calls
 * - Delete function messages
 * - Export CSV messages
 * - PDF generation labels
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// New translations for utenti page
$newTranslations = [
    // Delete messages
    'Eliminato!' => 'Deleted!',
    'L\'utente è stato eliminato.' => 'The user has been deleted.',
    'Errore!' => 'Error!',
    'Non è stato possibile eliminare l\'utente. Controlla la console.' => 'Unable to delete the user. Check the console.',
    'Si è verificato un errore: %s' => 'An error occurred: %s',

    // Export messages
    'Esportazione di %d utenti filtrati su %d totali' => 'Exporting %d filtered users out of %d total',
    'Esportazione di tutti i %d utenti' => 'Exporting all %d users',

    // PDF generation
    'Elenco Utenti - Biblioteca' => 'Users List - Library',
    'Generato il:' => 'Generated on:',
    'Totale utenti:' => 'Total users:',
    'Nome' => 'Name',
    'Cognome' => 'Last Name',
    'Email' => 'Email',
    'Ruolo' => 'Role',
    'Stato' => 'Status',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now fix utenti/index.php
$file = __DIR__ . '/../app/Views/utenti/index.php';
$content = file_get_contents($file);
$original = $content;

$replacements = [
    // Delete messages (lines 454, 458, 464)
    "Swal.fire('Eliminato!', 'L\\'utente è stato eliminato.', 'success');" =>
        "Swal.fire(__('Eliminato!'), __('L\\'utente è stato eliminato.'), 'success');",

    "Swal.fire('Errore!', 'Non è stato possibile eliminare l\\'utente. Controlla la console.', 'error');" =>
        "Swal.fire(__('Errore!'), __('Non è stato possibile eliminare l\\'utente. Controlla la console.'), 'error');",

    "Swal.fire('Errore!', 'Si è verificato un errore: ' + error.message, 'error');" =>
        "Swal.fire(__('Errore!'), __('Si è verificato un errore: %s').replace('%s', error.message), 'error');",

    // Export messages (lines 529-530)
    "const message = hasFilters
        ? `Esportazione di \${filteredCount} utenti filtrati su \${totalCount} totali`
        : `Esportazione di tutti i \${totalCount} utenti`;" =>
    "const message = hasFilters
        ? __('Esportazione di %d utenti filtrati su %d totali').replace('%d', filteredCount).replace('%d', totalCount)
        : __('Esportazione di tutti i %d utenti').replace('%d', totalCount);",

    // PDF title (line 586)
    "doc.text('Elenco Utenti - Biblioteca', 14, 22);" =>
        "doc.text(__('Elenco Utenti - Biblioteca'), 14, 22);",

    // PDF generated date label (line 590)
    "doc.text(`Generato il: \${new Date().toLocaleDateString('it-IT')}`, 14, 30);" =>
        "doc.text(`\${__('Generato il:')} \${new Date().toLocaleDateString('it-IT')}`, 14, 30);",

    // PDF total users (line 593)
    "doc.text(`Totale utenti: \${data.length}`, 14, 38);" =>
        "doc.text(`\${__('Totale utenti:')} \${data.length}`, 14, 38);",

    // PDF table headers (line 596)
    "const headers = ['Nome', 'Cognome', 'Email', 'Ruolo', 'Stato'];" =>
        "const headers = [__('Nome'), __('Cognome'), __('Email'), __('Ruolo'), __('Stato')];",
];

foreach ($replacements as $search => $replace) {
    $count = 0;
    $content = str_replace($search, $replace, $content, $count);
    if ($count > 0) {
        echo "✓ Replaced: " . substr($search, 0, 60) . "... ($count times)\n";
    } else {
        echo "✗ NOT FOUND: " . substr($search, 0, 60) . "...\n";
    }
}

if ($content !== $original) {
    file_put_contents($file, $content);
    echo "\n✅ utenti/index.php - wrapped " . count($replacements) . " strings\n";
} else {
    echo "\nℹ️  utenti/index.php - no changes needed\n";
}

echo "\n✅ Translation complete!\n";

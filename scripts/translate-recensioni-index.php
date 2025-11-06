<?php
/**
 * Translate app/Views/admin/recensioni/index.php
 * Fix all hardcoded Italian strings in headings, badges, empty states, and JavaScript
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// New translations for recensioni page
$newTranslations = [
    // Page header (lines 12-30)
    'Gestione Recensioni' => 'Reviews Management',
    'Approva o rifiuta le recensioni degli utenti' => 'Approve or reject user reviews',
    'in attesa' => 'pending',
    'In Attesa di Approvazione' => 'Pending Approval',
    'Approva' => 'Approve',
    'Rifiuta' => 'Reject',
    'Recensione del' => 'Review on',

    // Empty states (lines 111-124)
    'Nessuna recensione in attesa' => 'No pending reviews',
    'Non ci sono recensioni in attesa di approvazione.' => 'There are no reviews waiting for approval.',
    'Recensioni Approvate' => 'Approved Reviews',

    // Approved/rejected sections (lines 133, 162, 187, 197, 226)
    'Nessuna recensione approvata' => 'No approved reviews',
    'Approvata il' => 'Approved on',
    'Recensioni Rifiutate' => 'Rejected Reviews',
    'Nessuna recensione rifiutata' => 'No rejected reviews',
    'Rifiutata il' => 'Rejected on',

    // JavaScript messages (lines 265-366)
    'Conferma' => 'Confirm',
    'Annulla' => 'Cancel',
    'Confermi l\'operazione?' => 'Confirm the operation?',
    'Operazione completata' => 'Operation completed',
    'Errore' => 'Error',
    'Approva recensione' => 'Approve review',
    'Vuoi approvare questa recensione e renderla visibile sul sito?' => 'Do you want to approve this review and make it visible on the site?',
    'Impossibile approvare la recensione' => 'Unable to approve the review',
    'Rifiuta recensione' => 'Reject review',
    'Vuoi rifiutare questa recensione? L\'utente verrà avvisato dell\'esito.' => 'Do you want to reject this review? The user will be notified of the outcome.',
    'Impossibile rifiutare la recensione' => 'Unable to reject the review',
    'errore di comunicazione con il server' => 'server communication error',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now wrap Italian strings in recensioni/index.php
$file = __DIR__ . '/../app/Views/admin/recensioni/index.php';
$content = file_get_contents($file);
$original = $content;

$replacements = [
    // Page header (lines 12-30)
    '                        Gestione Recensioni' =>
        '                        <?= __("Gestione Recensioni") ?>',

    '                    <p class="text-sm text-gray-600 mt-1">Approva o rifiuta le recensioni degli utenti</p>' =>
        '                    <p class="text-sm text-gray-600 mt-1"><?= __("Approva o rifiuta le recensioni degli utenti") ?></p>',

    '                    <?php echo $pendingCount; ?> in attesa' =>
        '                    <?php echo $pendingCount; ?> <?= __("in attesa") ?>',

    '                In Attesa di Approvazione' =>
        '                <?= __("In Attesa di Approvazione") ?>',

    '                                <i class="fas fa-check mr-2"></i>Approva' =>
        '                                <i class="fas fa-check mr-2"></i><?= __("Approva") ?>',

    '                                <i class="fas fa-times mr-2"></i>Rifiuta' =>
        '                                <i class="fas fa-times mr-2"></i><?= __("Rifiuta") ?>',

    '                            Recensione del <?php echo date(\'d-m-Y H:i\', strtotime($review[\'created_at\'])); ?>' =>
        '                            <?= __("Recensione del") ?> <?php echo date(\'d-m-Y H:i\', strtotime($review[\'created_at\'])); ?>',

    // Empty states (lines 111-124)
    '            <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-2">Nessuna recensione in attesa</h3>' =>
        '            <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-2"><?= __("Nessuna recensione in attesa") ?></h3>',

    '            <p class="text-blue-600 dark:text-blue-400">Non ci sono recensioni in attesa di approvazione.</p>' =>
        '            <p class="text-blue-600 dark:text-blue-400"><?= __("Non ci sono recensioni in attesa di approvazione.") ?></p>',

    '                        <span class="font-semibold text-gray-900 dark:text-white">Recensioni Approvate</span>' =>
        '                        <span class="font-semibold text-gray-900 dark:text-white"><?= __("Recensioni Approvate") ?></span>',

    // Approved/rejected sections (lines 133, 162, 187, 197, 226)
    '                        <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-4">Nessuna recensione approvata</p>' =>
        '                        <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-4"><?= __("Nessuna recensione approvata") ?></p>',

    '                                            Approvata il <?php echo date(\'d/m/Y\', strtotime($review[\'approved_at\'])); ?>' =>
        '                                            <?= __("Approvata il") ?> <?php echo date(\'d/m/Y\', strtotime($review[\'approved_at\'])); ?>',

    '                        <span class="font-semibold text-gray-900 dark:text-white">Recensioni Rifiutate</span>' =>
        '                        <span class="font-semibold text-gray-900 dark:text-white"><?= __("Recensioni Rifiutate") ?></span>',

    '                        <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-4">Nessuna recensione rifiutata</p>' =>
        '                        <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-4"><?= __("Nessuna recensione rifiutata") ?></p>',

    '                                            Rifiutata il <?php echo date(\'d/m/Y\', strtotime($review[\'approved_at\'])); ?>' =>
        '                                            <?= __("Rifiutata il") ?> <?php echo date(\'d/m/Y\', strtotime($review[\'approved_at\'])); ?>',

    // JavaScript confirmations (lines 265-366)
    '                confirmButtonText: options.confirmText || \'Conferma\',' =>
        '                confirmButtonText: options.confirmText || __(\'Conferma\'),',

    '                cancelButtonText: options.cancelText || \'Annulla\',' =>
        '                cancelButtonText: options.cancelText || __(\'Annulla\'),',

    '        return window.confirm(options.text || options.title || \'Confermi l\\\'operazione?\');' =>
        '        return window.confirm(options.text || options.title || __(\'Confermi l\\\'operazione?\'));',

    '            window.alert(text || title || \'Operazione completata\');' =>
        '            window.alert(text || title || __(\'Operazione completata\'));',

    '                    showFeedback(\'error\', \'Errore\', `${errorPrefix}: ${result.message || \'Operazione non riuscita\'}`);' =>
        '                    showFeedback(\'error\', __(\'Errore\'), `${errorPrefix}: ${result.message || __(\'Operazione non riuscita\')}`);',

    '                showFeedback(\'error\', \'Errore\', `${errorPrefix}: errore di comunicazione con il server`);' =>
        '                showFeedback(\'error\', __(\'Errore\'), `${errorPrefix}: ${__(\'errore di comunicazione con il server\')}`);',

    '                confirmTitle: \'Approva recensione\',' =>
        '                confirmTitle: __(\'Approva recensione\'),',

    '                confirmText: \'Vuoi approvare questa recensione e renderla visibile sul sito?\',' =>
        '                confirmText: __(\'Vuoi approvare questa recensione e renderla visibile sul sito?\'),',

    '                confirmButton: \'Approva\',' =>
        '                confirmButton: __(\'Approva\'),',

    '                errorPrefix: \'Impossibile approvare la recensione\'' =>
        '                errorPrefix: __(\'Impossibile approvare la recensione\')',

    '                confirmTitle: \'Rifiuta recensione\',' =>
        '                confirmTitle: __(\'Rifiuta recensione\'),',

    '                confirmText: \'Vuoi rifiutare questa recensione? L\\\'utente verrà avvisato dell\\\'esito.\',' =>
        '                confirmText: __(\'Vuoi rifiutare questa recensione? L\\\'utente verrà avvisato dell\\\'esito.\'),',

    '                confirmButton: \'Rifiuta\',' =>
        '                confirmButton: __(\'Rifiuta\'),',

    '                errorPrefix: \'Impossibile rifiutare la recensione\'' =>
        '                errorPrefix: __(\'Impossibile rifiutare la recensione\')',
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
    echo "\n✅ recensioni/index.php - wrapped " . count($replacements) . " strings\n";
} else {
    echo "\nℹ️  recensioni/index.php - no changes needed\n";
}

echo "\n✅ Translation complete!\n";

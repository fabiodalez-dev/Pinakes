#!/usr/bin/env php
<?php
/**
 * Translate notification strings in NotificationService.php
 */

$file = 'app/Support/NotificationService.php';

if (!file_exists($file)) {
    die("❌ File not found: $file\n");
}

$content = file_get_contents($file);

// 1. Fix loan request notification title
$content = str_replace(
    "            \$notificationTitle = 'Nuova richiesta di prestito';",
    "            \$notificationTitle = __('Nuova richiesta di prestito');",
    $content
);

// 2. Fix loan request notification message with proper sprintf format
$oldMessage = '            $notificationMessage = "Richiesta di prestito per \"" . $loan[\'libro_titolo\'] . "\" da " . $loan[\'utente_nome\'] .
                                  " dal " . date(\'d/m/Y\', strtotime($loan[\'data_prestito\'])) .
                                  " al " . date(\'d/m/Y\', strtotime($loan[\'data_scadenza\']));';

$newMessage = '            $notificationMessage = sprintf(
                __("Richiesta di prestito per \"%s\" da %s dal %s al %s"),
                $loan[\'libro_titolo\'],
                $loan[\'utente_nome\'],
                date(\'d/m/Y\', strtotime($loan[\'data_prestito\'])),
                date(\'d/m/Y\', strtotime($loan[\'data_scadenza\']))
            );';

$content = str_replace($oldMessage, $newMessage, $content);

// 3. Fix review notification title
$content = str_replace(
    "            \$notificationTitle = 'Nuova recensione da approvare';",
    "            \$notificationTitle = __('Nuova recensione da approvare');",
    $content
);

// 4. Fix review notification message
$oldReviewMessage = "            \$notificationMessage = sprintf(
                'Recensione per \"%s\" da %s - %s',
                \$review['libro_titolo'],
                \$review['utente_nome'],
                \$stelle_text
            );";

$newReviewMessage = "            \$notificationMessage = sprintf(
                __('Recensione per \"%s\" da %s - %s'),
                \$review['libro_titolo'],
                \$review['utente_nome'],
                \$stelle_text
            );";

$content = str_replace($oldReviewMessage, $newReviewMessage, $content);

// Write back
file_put_contents($file, $content);

// Add translations to locale file
$translations = [
    'Nuova richiesta di prestito' => 'New loan request',
    'Richiesta di prestito per "%s" da %s dal %s al %s' => 'Loan request for "%s" by %s from %s to %s',
    'Nuova recensione da approvare' => 'New review to approve',
    'Recensione per "%s" da %s - %s' => 'Review for "%s" by %s - %s',
];

$localeFile = 'locale/en_US.json';
$locale = json_decode(file_get_contents($localeFile), true);

$addedCount = 0;
foreach ($translations as $italian => $english) {
    if (!isset($locale[$italian])) {
        $locale[$italian] = $english;
        $addedCount++;
    }
}

ksort($locale);
file_put_contents($localeFile, json_encode($locale, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

echo "✅ Fixed NotificationService.php\n";
echo "✅ Added $addedCount translations to locale/en_US.json\n";
echo "✅ Total translations: " . count($locale) . "\n";

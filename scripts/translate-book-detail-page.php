<?php
/**
 * Translate frontend book-detail.php
 * COMPREHENSIVE: availability badges, sidebar labels, share card, SweetAlert, loan modal
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// All translations for book-detail page
$newTranslations = [
    // Availability badge (lines 1395-1399) - use __n() for plural
    'Disponibili' => 'Available',
    'Disponibile' => 'Available',
    'Non Disponibile' => 'Not Available',

    // Sidebar labels (lines 1729-1742)
    'Copie Disponibili' => 'Available Copies',
    'Collocazione' => 'Location',
    'Aggiunto il' => 'Added on',

    // Share card (lines 1750-1768)
    'Condividi' => 'Share',
    'Condividi su Facebook' => 'Share on Facebook',
    'Condividi su Twitter' => 'Share on Twitter',
    'Condividi su WhatsApp' => 'Share on WhatsApp',
    'Copia link negli appunti' => 'Copy link to clipboard',

    // Related books section (line 1782)
    'Potrebbero interessarti' => 'You might also like',

    // Alt text fallback (line 1805)
    'Copertina del libro' => 'Book cover',

    // SweetAlert login message (line 1932)
    'Per richiedere un prestito devi effettuare il login.' => 'To request a loan you must log in.',

    // Loan modal labels (lines 1991-1999)
    'Quando vuoi iniziare il prestito?' => 'When do you want to start the loan?',
    'Fino a quando? (opzionale):' => 'Until when? (optional):',
    'Le date rosse non sono disponibili. La richiesta verrà valutata da un amministratore.' => 'Red dates are not available. The request will be evaluated by an administrator.',

    // Validation message (line 2047)
    'Seleziona una data di inizio' => 'Select a start date',

    // Success message (line 2081)
    ' per 1 mese' => ' for 1 month',

    // Error messages (lines 2089, 2094, 2116)
    'Impossibile creare la prenotazione' => 'Unable to create reservation',

    // Fallback prompts (lines 2099-2116)
    'Inserisci la data di inizio (YYYY-MM-DD)' => 'Enter the start date (YYYY-MM-DD)',
    'Prenotazione effettuata per ' => 'Reservation made for ',
    'Errore: ' => 'Error: ',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now fix the book-detail.php code
$file = __DIR__ . '/../app/Views/frontend/book-detail.php';
$content = file_get_contents($file);
$original = $content;

$replacements = [
    // Fix lines 1395-1399: Availability badge with __n() for plural
    "                            <?= (\$book['copie_disponibili'] > 0)
                                ? (\$book['copie_totali'] > 1
                                    ? \"{\$book['copie_disponibili']}/{\$book['copie_totali']} Disponibili\"
                                    : 'Disponibile')
                                : 'Non Disponibile' ?>" =>
    "                            <?= (\$book['copie_disponibili'] > 0)
                                ? (\$book['copie_totali'] > 1
                                    ? \"{\$book['copie_disponibili']}/{\$book['copie_totali']} \" . __(\"Disponibili\")
                                    : __(\"Disponibile\"))
                                : __(\"Non Disponibile\") ?>",

    // Fix line 1729: Copie Disponibili
    "                            <div class=\"meta-label\">Copie Disponibili</div>" =>
    "                            <div class=\"meta-label\"><?= __(\"Copie Disponibili\") ?></div>",

    // Fix line 1735: Collocazione
    "                            <div class=\"meta-label\">Collocazione</div>" =>
    "                            <div class=\"meta-label\"><?= __(\"Collocazione\") ?></div>",

    // Fix line 1741: Aggiunto il
    "                            <div class=\"meta-label\">Aggiunto il</div>" =>
    "                            <div class=\"meta-label\"><?= __(\"Aggiunto il\") ?></div>",

    // Fix line 1750: Condividi
    "                        <h6 class=\"mb-0\"><i class=\"fas fa-share-alt me-2\"></i>Condividi</h6>" =>
    "                        <h6 class=\"mb-0\"><i class=\"fas fa-share-alt me-2\"></i><?= __(\"Condividi\") ?></h6>",

    // Fix line 1755: Condividi su Facebook
    "                            <a href=\"#\" class=\"social-icon-link\" id=\"share-facebook\" title=\"Condividi su Facebook\" target=\"_blank\" rel=\"noopener noreferrer\">" =>
    "                            <a href=\"#\" class=\"social-icon-link\" id=\"share-facebook\" title=\"<?= __(\"Condividi su Facebook\") ?>\" target=\"_blank\" rel=\"noopener noreferrer\">",

    // Fix line 1759: Condividi su Twitter
    "                            <a href=\"#\" class=\"social-icon-link\" id=\"share-twitter\" title=\"Condividi su Twitter\" target=\"_blank\" rel=\"noopener noreferrer\">" =>
    "                            <a href=\"#\" class=\"social-icon-link\" id=\"share-twitter\" title=\"<?= __(\"Condividi su Twitter\") ?>\" target=\"_blank\" rel=\"noopener noreferrer\">",

    // Fix line 1763: Condividi su WhatsApp
    "                            <a href=\"#\" class=\"social-icon-link\" id=\"share-whatsapp\" title=\"Condividi su WhatsApp\" target=\"_blank\" rel=\"noopener noreferrer\">" =>
    "                            <a href=\"#\" class=\"social-icon-link\" id=\"share-whatsapp\" title=\"<?= __(\"Condividi su WhatsApp\") ?>\" target=\"_blank\" rel=\"noopener noreferrer\">",

    // Fix line 1767: Copia link negli appunti
    "                            <button type=\"button\" class=\"social-icon-link\" id=\"copy-link\" title=\"Copia link negli appunti\" style=\"border: none; background: none; padding: 0; cursor: pointer;\">" =>
    "                            <button type=\"button\" class=\"social-icon-link\" id=\"copy-link\" title=\"<?= __(\"Copia link negli appunti\") ?>\" style=\"border: none; background: none; padding: 0; cursor: pointer;\">",

    // Fix line 1782: Potrebbero interessarti
    "        <h3 class=\"text-center mb-5\" style=\"font-weight: 700; font-size: 2rem; color: #1a1a1a;\">Potrebbero interessarti</h3>" =>
    "        <h3 class=\"text-center mb-5\" style=\"font-weight: 700; font-size: 2rem; color: #1a1a1a;\"><?= __(\"Potrebbero interessarti\") ?></h3>",

    // Fix line 1805: Copertina del libro (fallback)
    "                            \$relatedCoverAlt = 'Copertina del libro';" =>
    "                            \$relatedCoverAlt = __(\"Copertina del libro\");",

    // Fix line 1932: SweetAlert login message
    "            html: '<p class=\"mb-3\">Per richiedere un prestito devi effettuare il login.</p>'," =>
    "            html: '<p class=\"mb-3\">' + __('Per richiedere un prestito devi effettuare il login.') + '</p>',",

    // Fix line 1991: Quando vuoi iniziare il prestito?
    "            `<label class=\"form-label\">Quando vuoi iniziare il prestito?</label>`+" =>
    "            `<label class=\"form-label\">\${__('Quando vuoi iniziare il prestito?')}</label>`+",

    // Fix line 1993: Fino a quando? (opzionale):
    "            `<label class=\"form-label mt-3\">Fino a quando? (opzionale):</label>`+" =>
    "            `<label class=\"form-label mt-3\">\${__('Fino a quando? (opzionale):')}</label>`+",

    // Fix line 1997: Le date rosse non sono disponibili...
    "            `Le date rosse non sono disponibili. La richiesta verrà valutata da un amministratore.`+" =>
    "            `\${__('Le date rosse non sono disponibili. La richiesta verrà valutata da un amministratore.')}`+",

    // Fix line 2047: Seleziona una data di inizio
    "              Swal.showValidationMessage('Seleziona una data di inizio');" =>
    "              Swal.showValidationMessage(__('Seleziona una data di inizio'));",

    // Fix line 2081: per 1 mese
    "                      (formValues.endDate ? ` al <strong>\${formatDateIT(formValues.endDate)}</strong>` : ' per 1 mese') +" =>
    "                      (formValues.endDate ? ` al <strong>\${formatDateIT(formValues.endDate)}</strong>` : __(' per 1 mese')) +",

    // Fix line 2089: Impossibile creare la prenotazione
    "                text: result.message || 'Impossibile creare la prenotazione'" =>
    "                text: result.message || __('Impossibile creare la prenotazione')",

    // Fix line 2094: Impossibile creare la prenotazione
    "            Swal.fire({ icon:'error', title: __('Errore'), text: __('Impossibile creare la prenotazione') });" =>
    "            Swal.fire({ icon:'error', title: __('Errore'), text: __('Impossibile creare la prenotazione') });",

    // Fix line 2099: Inserisci la data di inizio (YYYY-MM-DD)
    "        const date = prompt('Inserisci la data di inizio (YYYY-MM-DD)', suggestedDate);" =>
    "        const date = prompt(__('Inserisci la data di inizio (YYYY-MM-DD)'), suggestedDate);",

    // Fix line 2114: Prenotazione effettuata per
    "              alert('Prenotazione effettuata per ' + date);" =>
    "              alert(__('Prenotazione effettuata per ') + date);",

    // Fix line 2116: Errore:
    "              alert('Errore: ' + (result.message || 'Impossibile creare la prenotazione'));" =>
    "              alert(__('Errore: ') + (result.message || __('Impossibile creare la prenotazione')));"
];

foreach ($replacements as $search => $replace) {
    $count = 0;
    $content = str_replace($search, $replace, $content, $count);
    if ($count > 0) {
        echo "✓ Fixed: " . substr($search, 0, 60) . "... ($count)\n";
    } else {
        echo "✗ NOT FOUND: " . substr($search, 0, 60) . "...\n";
    }
}

if ($content !== $original) {
    file_put_contents($file, $content);
    echo "\n✅ book-detail.php - Fixed " . count($replacements) . " strings\n";
} else {
    echo "\nℹ️  book-detail.php - No changes made\n";
}

echo "\n✅ Book detail page translation COMPLETE!\n";

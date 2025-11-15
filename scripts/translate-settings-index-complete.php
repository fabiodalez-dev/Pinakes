<?php
/**
 * Complete translation of settings/index.php
 * This script wraps ALL Italian strings in __() and adds translations
 */

declare(strict_types=1);

$file = __DIR__ . '/../app/Views/settings/index.php';
$translationFile = __DIR__ . '/../locale/en_US.json';

// Load existing translations
$existing = json_decode(file_get_contents($translationFile), true);

// ALL translations needed (complete mapping)
$translations = [
    // Main header
    'Centro Impostazioni' => 'Settings Center',
    'Configura l\'identità dell\'applicazione, i metodi di invio email e personalizza i template delle notifiche automatiche.' => 'Configure application identity, email sending methods and customize automatic notification templates.',

    // General tab
    'Identità Applicazione' => 'Application Identity',
    'Rimuovi logo attuale' => 'Remove current logo',
    'Anteprima logo' => 'Logo preview',
    'Nessun logo caricato' => 'No logo uploaded',
    'Consigliato PNG o SVG con sfondo trasparente. Dimensione massima 2MB.' => 'Recommended PNG or SVG with transparent background. Maximum size 2MB.',
    'Footer' => 'Footer',
    'Personalizza il testo descrittivo e i link ai social media nel footer del sito' => 'Customize the descriptive text and social media links in the site footer',
    'Descrizione footer' => 'Footer description',
    'Testo che apparirà nel footer del sito' => 'Text that will appear in the site footer',
    'Link Social Media' => 'Social Media Links',
    'Facebook' => 'Facebook',
    'Twitter' => 'Twitter',
    'Instagram' => 'Instagram',
    'LinkedIn' => 'LinkedIn',
    'Bluesky' => 'Bluesky',
    'Lascia vuoto per nascondere il social dal footer' => 'Leave empty to hide the social from footer',
    'Salva identità' => 'Save identity',

    // Email tab
    'Configurazione invio' => 'Sending Configuration',
    'Scegli come inviare le email dal sistema. Puoi usare la funzione PHP <code class="text-xs bg-gray-100 px-1 py-0.5 rounded">mail()</code>, PHPMailer o un server SMTP esterno.' => 'Choose how to send emails from the system. You can use the PHP <code class="text-xs bg-gray-100 px-1 py-0.5 rounded">mail()</code> function, PHPMailer or an external SMTP server.',
    'Metodo di invio' => 'Sending method',
    'PHP mail()' => 'PHP mail()',
    'PHPMailer' => 'PHPMailer',
    'SMTP personalizzato' => 'Custom SMTP',
    'Mittente (email)' => 'Sender (email)',
    'Mittente (nome)' => 'Sender (name)',
    'Server SMTP' => 'SMTP Server',
    'Disponibile solo con driver SMTP' => 'Available only with SMTP driver',
    'Host' => 'Host',
    'Porta' => 'Port',
    'Username' => 'Username',
    'Crittografia' => 'Encryption',
    'Quando utilizzi PHPMailer il sistema invia le email con le configurazioni definite nel codice o tramite provider esterni. Passa al driver "SMTP personalizzato" per modificare questi parametri direttamente dall\'interfaccia.' => 'When using PHPMailer the system sends emails with the configurations defined in the code or through external providers. Switch to "Custom SMTP" driver to modify these parameters directly from the interface.',
    'Salva impostazioni email' => 'Save email settings',

    // Templates tab
    'Template email' => 'Email templates',
    'Personalizza il contenuto delle mail automatiche con l\'editor TinyMCE. Usa i segnaposto <code class="text-xs bg-gray-100 px-1 py-0.5 rounded">{{variabile}}</code> per inserire dati dinamici.' => 'Customize the content of automatic emails with the TinyMCE editor. Use placeholders <code class="text-xs bg-gray-100 px-1 py-0.5 rounded">{{variable}}</code> to insert dynamic data.',
    'Segnaposto disponibili mostrati in ciascun template.' => 'Available placeholders shown in each template.',
    'Segnaposto:' => 'Placeholders:',
    'Oggetto' => 'Subject',
    'Corpo email' => 'Email body',
    'Salva template' => 'Save template',

    // CMS tab
    'Gestione Contenuti (CMS)' => 'Content Management (CMS)',
    'Modifica le pagine statiche del sito' => 'Edit static site pages',
    'Homepage' => 'Homepage',
    'Modifica i contenuti della homepage: hero, features, CTA e immagine di sfondo' => 'Edit homepage content: hero, features, CTA and background image',
    'Visualizza pagina live' => 'View live page',
    'Modifica Homepage' => 'Edit Homepage',
    'Chi Siamo' => 'About Us',
    'Gestisci il contenuto della pagina Chi Siamo con testo e immagine personalizzati' => 'Manage About Us page content with custom text and image',
    'Modifica Chi Siamo' => 'Edit About Us',
    'Suggerimento' => 'Tip',
    'Utilizza l\'editor TinyMCE per formattare il testo e Uppy per caricare immagini di alta qualità. Le modifiche saranno immediatamente visibili nella pagina pubblica.' => 'Use the TinyMCE editor to format text and Uppy to upload high quality images. Changes will be immediately visible on the public page.',

    // Labels tab
    'Configurazione Etichette Libri' => 'Book Labels Configuration',
    'Seleziona il formato delle etichette da stampare per i libri.' => 'Select the format of labels to print for books.',
    'Il formato scelto verrà utilizzato per generare i PDF delle etichette con codice a barre.' => 'The chosen format will be used to generate label PDFs with barcodes.',
    'Formato Etichetta' => 'Label Format',
    'Standard dorso libri (più comune)' => 'Standard book spine (most common)',
    'Formato orizzontale per dorso' => 'Horizontal format for spine',
    'Etichette interne grandi (Herma 4630, Avery 3490)' => 'Large internal labels (Herma 4630, Avery 3490)',
    'Standard Tirrenia catalogazione' => 'Standard Tirrenia cataloging',
    'Formato quadrato Tirrenia' => 'Tirrenia square format',
    'Formato biblioteche scolastiche (compatibile A4)' => 'School library format (A4 compatible)',
    'Nota:' => 'Note:',
    'Il formato selezionato verrà applicato a tutte le etichette generate dal sistema.' => 'The selected format will be applied to all labels generated by the system.',
    'Assicurati che corrisponda al tipo di carta per etichette che utilizzi.' => 'Make sure it matches the type of label paper you use.',
    'Salva impostazioni etichette' => 'Save label settings',

    // JavaScript strings (Uppy locale)
    'Trascina qui il logo o %{browse}' => 'Drag logo here or %{browse}',
    'seleziona file' => 'select file',
    'PNG, SVG, JPG o WebP (max 2MB)' => 'PNG, SVG, JPG or WebP (max 2MB)',
    'Errore Upload' => 'Upload Error',
    'Errore: ' => 'Error: ',
];

// Merge and save translations
$merged = array_merge($existing, $translations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . (count($merged) - count($existing)) . " new translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now fix the file with exact replacements
$content = file_get_contents($file);
$originalContent = $content;

// Exact replacements (avoiding regex complications)
$replacements = [
    '>Centro Impostazioni<' => '><?= __("Centro Impostazioni") ?><',
    '>Configura l\'identità dell\'applicazione, i metodi di invio email e personalizza i template delle notifiche automatiche.<' => '><?= __("Configura l\'identità dell\'applicazione, i metodi di invio email e personalizza i template delle notifiche automatiche.") ?><',
    '>Identità Applicazione<' => '><?= __("Identità Applicazione") ?><',
    '>Rimuovi logo attuale<' => '><?= __("Rimuovi logo attuale") ?><',
    'alt="Anteprima logo"' => 'alt="<?= __("Anteprima logo") ?>"',
    "'Anteprima logo'" => "'<?= __(\"Anteprima logo\") ?>'",
    "'Nessun logo caricato'" => "'<?= __(\"Nessun logo caricato\") ?>'",
    '>Consigliato PNG o SVG con sfondo trasparente. Dimensione massima 2MB.<' => '><?= __("Consigliato PNG o SVG con sfondo trasparente. Dimensione massima 2MB.") ?><',
    '>Footer<' => '><?= __("Footer") ?><',
    '>Personalizza il testo descrittivo e i link ai social media nel footer del sito<' => '><?= __("Personalizza il testo descrittivo e i link ai social media nel footer del sito") ?><',
    '>Descrizione footer<' => '><?= __("Descrizione footer") ?><',
    '>Testo che apparirà nel footer del sito<' => '><?= __("Testo che apparirà nel footer del sito") ?><',
    '>Link Social Media<' => '><?= __("Link Social Media") ?><',
    '>Facebook<' => '><?= __("Facebook") ?><',
    '>Twitter<' => '><?= __("Twitter") ?><',
    '>Instagram<' => '><?= __("Instagram") ?><',
    '>LinkedIn<' => '><?= __("LinkedIn") ?><',
    '>Bluesky<' => '><?= __("Bluesky") ?><',
    '>Lascia vuoto per nascondere il social dal footer<' => '><?= __("Lascia vuoto per nascondere il social dal footer") ?><',
    '>Salva identità<' => '><?= __("Salva identità") ?><',
    '>Configurazione invio<' => '><?= __("Configurazione invio") ?><',
    '>Scegli come inviare le email dal sistema. Puoi usare la funzione PHP <code class="text-xs bg-gray-100 px-1 py-0.5 rounded">mail()</code>, PHPMailer o un server SMTP esterno.<' => '><?= __("Scegli come inviare le email dal sistema. Puoi usare la funzione PHP <code class=\"text-xs bg-gray-100 px-1 py-0.5 rounded\">mail()</code>, PHPMailer o un server SMTP esterno.") ?><',
    '>Metodo di invio<' => '><?= __("Metodo di invio") ?><',
    '>PHP mail()<' => '><?= __("PHP mail()") ?><',
    '>PHPMailer<' => '><?= __("PHPMailer") ?><',
    '>SMTP personalizzato<' => '><?= __("SMTP personalizzato") ?><',
    '>Mittente (email)<' => '><?= __("Mittente (email)") ?><',
    '>Mittente (nome)<' => '><?= __("Mittente (nome)") ?><',
    '>Server SMTP<' => '><?= __("Server SMTP") ?><',
    '>Disponibile solo con driver SMTP<' => '><?= __("Disponibile solo con driver SMTP") ?><',
    '>Host<' => '><?= __("Host") ?><',
    '>Porta<' => '><?= __("Porta") ?><',
    '>Username<' => '><?= __("Username") ?><',
    '>Crittografia<' => '><?= __("Crittografia") ?><',
    '>Nessuna<' => '><?= __("Nessuna") ?><',
    '>Quando utilizzi PHPMailer il sistema invia le email con le configurazioni definite nel codice o tramite provider esterni. Passa al driver "SMTP personalizzato" per modificare questi parametri direttamente dall\'interfaccia.<' => '><?= __("Quando utilizzi PHPMailer il sistema invia le email con le configurazioni definite nel codice o tramite provider esterni. Passa al driver \"SMTP personalizzato\" per modificare questi parametri direttamente dall\'interfaccia.") ?><',
    '>Salva impostazioni email<' => '><?= __("Salva impostazioni email") ?><',
    '>Template email<' => '><?= __("Template email") ?><',
    '>Personalizza il contenuto delle mail automatiche con l\'editor TinyMCE. Usa i segnaposto <code class="text-xs bg-gray-100 px-1 py-0.5 rounded">{{variabile}}</code> per inserire dati dinamici.<' => '><?= __("Personalizza il contenuto delle mail automatiche con l\'editor TinyMCE. Usa i segnaposto <code class=\"text-xs bg-gray-100 px-1 py-0.5 rounded\">{{variabile}}</code> per inserire dati dinamici.") ?><',
    '>Segnaposto disponibili mostrati in ciascun template.<' => '><?= __("Segnaposto disponibili mostrati in ciascun template.") ?><',
    '>Segnaposto:<' => '><?= __("Segnaposto:") ?><',
    '>Oggetto<' => '><?= __("Oggetto") ?><',
    '>Corpo email<' => '><?= __("Corpo email") ?><',
    '>Salva template<' => '><?= __("Salva template") ?><',
    '>Modifica le pagine statiche del sito<' => '><?= __("Modifica le pagine statiche del sito") ?><',
    '>Homepage<' => '><?= __("Homepage") ?><',
    '>Modifica i contenuti della homepage: hero, features, CTA e immagine di sfondo<' => '><?= __("Modifica i contenuti della homepage: hero, features, CTA e immagine di sfondo") ?><',
    '>Visualizza pagina live<' => '><?= __("Visualizza pagina live") ?><',
    '>Modifica Homepage<' => '><?= __("Modifica Homepage") ?><',
    '>Chi Siamo<' => '><?= __("Chi Siamo") ?><',
    '>Gestisci il contenuto della pagina Chi Siamo con testo e immagine personalizzati<' => '><?= __("Gestisci il contenuto della pagina Chi Siamo con testo e immagine personalizzati") ?><',
    '>Modifica Chi Siamo<' => '><?= __("Modifica Chi Siamo") ?><',
    '>Suggerimento<' => '><?= __("Suggerimento") ?><',
    '>Utilizza l\'editor TinyMCE per formattare il testo e Uppy per caricare immagini di alta qualità. Le modifiche saranno immediatamente visibili nella pagina pubblica.<' => '><?= __("Utilizza l\'editor TinyMCE per formattare il testo e Uppy per caricare immagini di alta qualità. Le modifiche saranno immediatamente visibili nella pagina pubblica.") ?><',
    '>Configurazione Etichette Libri<' => '><?= __("Configurazione Etichette Libri") ?><',
    '>Seleziona il formato delle etichette da stampare per i libri.<' => '><?= __("Seleziona il formato delle etichette da stampare per i libri.") ?><',
    '>Il formato scelto verrà utilizzato per generare i PDF delle etichette con codice a barre.<' => '><?= __("Il formato scelto verrà utilizzato per generare i PDF delle etichette con codice a barre.") ?><',
    '>Formato Etichetta<' => '><?= __("Formato Etichetta") ?><',
    "'Standard dorso libri (più comune)'" => "'<?= __(\"Standard dorso libri (più comune)\") ?>'",
    "'Formato orizzontale per dorso'" => "'<?= __(\"Formato orizzontale per dorso\") ?>'",
    "'Etichette interne grandi (Herma 4630, Avery 3490)'" => "'<?= __(\"Etichette interne grandi (Herma 4630, Avery 3490)\") ?>'",
    "'Standard Tirrenia catalogazione'" => "'<?= __(\"Standard Tirrenia catalogazione\") ?>'",
    "'Formato quadrato Tirrenia'" => "'<?= __(\"Formato quadrato Tirrenia\") ?>'",
    "'Formato biblioteche scolastiche (compatibile A4)'" => "'<?= __(\"Formato biblioteche scolastiche (compatibile A4)\") ?>'",
    '>Nota:<' => '><?= __("Nota:") ?><',
    '>Il formato selezionato verrà applicato a tutte le etichette generate dal sistema.<' => '><?= __("Il formato selezionato verrà applicato a tutte le etichette generate dal sistema.") ?><',
    '>Assicurati che corrisponda al tipo di carta per etichette che utilizzi.<' => '><?= __("Assicurati che corrisponda al tipo di carta per etichette che utilizzi.") ?><',
    '>Salva impostazioni etichette<' => '><?= __("Salva impostazioni etichette") ?><',

    // JavaScript strings
    "dropPasteFiles: 'Trascina qui il logo o %{browse}'" => "dropPasteFiles: '<?= __(\"Trascina qui il logo o %{browse}\") ?>'",
    "browse: 'seleziona file'" => "browse: '<?= __(\"seleziona file\") ?>'",
    "note: 'PNG, SVG, JPG o WebP (max 2MB)'" => "note: '<?= __(\"PNG, SVG, JPG o WebP (max 2MB)\") ?>'",
    "title: __('Errore Upload')" => "title: '<?= __(\"Errore Upload\") ?>'",
    "'Errore: '" => "'<?= __(\"Errore: \") ?>'",
];

// Apply replacements
$count = 0;
foreach ($replacements as $search => $replace) {
    $before = $content;
    $content = str_replace($search, $replace, $content);
    if ($content !== $before) $count++;
}

// Save file
if ($content !== $originalContent) {
    file_put_contents($file, $content);
    echo "✅ Fixed settings/index.php\n";
    echo "   Applied $count replacements\n";
} else {
    echo "ℹ️  No changes needed\n";
}

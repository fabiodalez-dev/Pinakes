#!/usr/bin/env php
<?php
/**
 * Translation Script for Editori Pages
 *
 * Translates ALL 3 editori pages:
 * - crea_editore.php
 * - modifica_editore.php
 * - scheda_editore.php
 *
 * Similar patterns to autori pages we just translated.
 */

$baseDir = dirname(__DIR__);
$localeFile = $baseDir . '/locale/en_US.json';

// Load existing translations
if (!file_exists($localeFile)) {
    die("❌ Locale file not found: $localeFile\n");
}

$translations = json_decode(file_get_contents($localeFile), true);
if (!$translations) {
    die("❌ Failed to parse locale file\n");
}

echo "📚 Editori Pages Translation Script\n";
echo str_repeat("=", 50) . "\n\n";

// Track new translations
$newTranslations = [];
$translationCount = count($translations);

/**
 * Add translation if it doesn't exist
 */
function addTranslation($italian, $english) {
    global $translations, $newTranslations;

    if (!isset($translations[$italian])) {
        $translations[$italian] = $english;
        $newTranslations[$italian] = $english;
        return true;
    }
    return false;
}

// ============================================================================
// TRANSLATIONS FOR ALL EDITORI PAGES
// ============================================================================

echo "Adding translations for editori pages...\n\n";

// Breadcrumb & Navigation
addTranslation("Editori", "Publishers");
addTranslation("Nuovo", "New");

// Page Titles & Headers
addTranslation("Aggiungi Nuovo Editore", "Add New Publisher");
addTranslation("Compila i dettagli della casa editrice per aggiungerla alla biblioteca", "Fill in the publishing house details to add it to the library");
addTranslation("Modifica Editore", "Edit Publisher");
addTranslation("Aggiorna i dettagli dell'editore:", "Update publisher details:");
addTranslation("Profilo Editore", "Publisher Profile");

// Form Sections
addTranslation("Informazioni Base", "Basic Information");
addTranslation("Referente", "Contact Person");

// Form Labels - Basic Info
addTranslation("Nome Editore", "Publisher Name");
addTranslation("Nome della casa editrice", "Publishing house name");
addTranslation("Sito Web", "Website");
addTranslation("https://www.editore.com", "https://www.publisher.com");
addTranslation("Sito web ufficiale dell'editore", "Publisher's official website");
addTranslation("Email Contatto", "Contact Email");
addTranslation("info@editore.com", "info@publisher.com");

// Form Labels - Referente Section
addTranslation("Nome Referente", "Contact Person Name");
addTranslation("Nome e cognome del referente", "Contact person's full name");
addTranslation("Persona di riferimento presso l'editore", "Reference person at the publisher");
addTranslation("Telefono Referente", "Contact Person Phone");
addTranslation("Email Referente", "Contact Person Email");
addTranslation("referente@editore.com", "contact@publisher.com");
addTranslation("Codice Fiscale", "Tax Code");
addTranslation("es. RSSMRA80A01H501U", "e.g. RSSMRA80A01H501U");
addTranslation("Codice fiscale dell'editore (opzionale)", "Publisher's tax code (optional)");

// Buttons
addTranslation("Salva Editore", "Save Publisher");
addTranslation("Salva Modifiche", "Save Changes");

// JavaScript Validation Messages
addTranslation("Campo Obbligatorio", "Required Field");
addTranslation("Il nome dell'editore è obbligatorio.", "Publisher name is required.");
addTranslation("URL Non Valido", "Invalid URL");
addTranslation("Il sito web deve essere un URL valido (es. https://www.esempio.com).", "The website must be a valid URL (e.g. https://www.example.com).");
addTranslation("Il sito web deve essere un URL valido.", "The website must be a valid URL.");
addTranslation("Email Non Valida", "Invalid Email");
addTranslation("L'indirizzo email deve essere valido.", "The email address must be valid.");
addTranslation("Conferma Salvataggio", "Confirm Save");
addTranslation("Conferma Aggiornamento", "Confirm Update");
addTranslation("Sì, Salva", "Yes, Save");
addTranslation("Sì, Aggiorna", "Yes, Update");
addTranslation("Salvataggio in corso...", "Saving...");
addTranslation("Aggiornamento in corso...", "Updating...");
addTranslation("Attendere prego", "Please wait");

// Scheda Editore - Stats & Metadata
addTranslation("Totale Libri", "Total Books");
addTranslation("Totale Autori", "Total Authors");
addTranslation("Ultimo Aggiornamento", "Last Updated");
addTranslation("ID Editore", "Publisher ID");
addTranslation("titoli", "titles");

// Scheda Editore - Section Headers
addTranslation("Informazioni generali", "General Information");
addTranslation("Contatti", "Contacts");
addTranslation("Catalogo libri", "Book Catalog");
addTranslation("Risorse esterne", "External Resources");
addTranslation("Autori pubblicati", "Published Authors");

// Scheda Editore - Metadata Labels
addTranslation("Sito web", "Website");
addTranslation("Aggiunto il", "Added on");

// Scheda Editore - Buttons & Actions
addTranslation("Nuovo Libro", "New Book");
addTranslation("Non eliminabile", "Cannot Delete");
addTranslation("Rimuovere i libri dell'editore prima di eliminarlo", "Remove publisher's books before deleting");
addTranslation("Confermi l'eliminazione dell'editore?", "Confirm publisher deletion?");
addTranslation("Aggiungi nuovo libro", "Add new book");
addTranslation("Visita il sito ufficiale", "Visit official website");

// Scheda Editore - Empty States
addTranslation("Nessun libro registrato", "No books registered");
addTranslation("Aggiungi un nuovo titolo per arricchire il catalogo di questo editore.", "Add a new title to enrich this publisher's catalog.");

// Book Card Labels (in catalog)
addTranslation("Copertina", "Cover");
addTranslation("Titolo non disponibile", "Title not available");
addTranslation("Editore:", "Publisher:");
addTranslation("Dettagli", "Details");

// ============================================================================
// SAVE TRANSLATIONS
// ============================================================================

// Sort translations alphabetically
ksort($translations);

// Save to file with pretty print
$json = json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    die("❌ Failed to encode translations\n");
}

file_put_contents($localeFile, $json . "\n");

// ============================================================================
// APPLY TRANSLATIONS TO PHP FILES
// ============================================================================

echo "\n" . str_repeat("=", 50) . "\n";
echo "📝 Applying translations to editori pages...\n\n";

// Define files to translate
$files = [
    $baseDir . '/app/Views/editori/crea_editore.php',
    $baseDir . '/app/Views/editori/modifica_editore.php',
    $baseDir . '/app/Views/editori/scheda_editore.php'
];

$totalReplacements = 0;

foreach ($files as $file) {
    if (!file_exists($file)) {
        echo "⚠️  File not found: " . basename($file) . "\n";
        continue;
    }

    $content = file_get_contents($file);
    $original = $content;
    $replacements = 0;

    // Pattern 1: Simple labels in HTML
    $patterns = [
        // Breadcrumb
        '/<i class="fas fa-building mr-1"><\/i>Editori/' => '<i class="fas fa-building mr-1"></i><?= __("Editori") ?>',
        '/class="text-gray-900 font-medium">Nuovo</' => 'class="text-gray-900 font-medium"><?= __("Nuovo") ?><',

        // Page headers
        '/Aggiungi Nuovo Editore/' => '<?= __("Aggiungi Nuovo Editore") ?>',
        '/Compila i dettagli della casa editrice per aggiungerla alla biblioteca/' => '<?= __("Compila i dettagli della casa editrice per aggiungerla alla biblioteca") ?>',
        '/Modifica Editore<\/h1>/' => '<?= __("Modifica Editore") ?></h1>',
        '/Aggiorna i dettagli dell\'editore:/' => '<?= __("Aggiorna i dettagli dell\'editore:") ?>',
        '/Profilo Editore<\/div>/' => '<?= __("Profilo Editore") ?></div>',

        // Section titles
        '/Informazioni Base<\/h2>/' => '<?= __("Informazioni Base") ?></h2>',
        '/Referente<\/h2>/' => '<?= __("Referente") ?></h2>',
        '/Informazioni generali<\/h2>/' => '<?= __("Informazioni generali") ?></h2>',
        '/Contatti<\/h2>/' => '<?= __("Contatti") ?></h2>',
        '/Autori pubblicati<\/h2>/' => '<?= __("Autori pubblicati") ?></h2>',
        '/Catalogo libri<\/h2>/' => '<?= __("Catalogo libri") ?></h2>',
        '/Risorse esterne<\/h2>/' => '<?= __("Risorse esterne") ?></h2>',

        // Form labels
        '/Nome Editore <span/' => '<?= __("Nome Editore") ?> <span',
        '/Sito Web<\/label>/' => '<?= __("Sito Web") ?></label>',
        '/Email Contatto<\/label>/' => '<?= __("Email Contatto") ?></label>',
        '/Nome Referente<\/label>/' => '<?= __("Nome Referente") ?></label>',
        '/Telefono Referente<\/label>/' => '<?= __("Telefono Referente") ?></label>',
        '/Email Referente<\/label>/' => '<?= __("Email Referente") ?></label>',
        '/Codice Fiscale<\/label>/' => '<?= __("Codice Fiscale") ?></label>',

        // Help text
        '/Sito web ufficiale dell\'editore<\/p>/' => '<?= __("Sito web ufficiale dell\'editore") ?></p>',
        '/Persona di riferimento presso l\'editore<\/p>/' => '<?= __("Persona di riferimento presso l\'editore") ?></p>',
        '/Codice fiscale dell\'editore \(opzionale\)<\/p>/' => '<?= __("Codice fiscale dell\'editore (opzionale)") ?></p>',

        // Buttons
        '/Salva Editore<\/button>/' => '<?= __("Salva Editore") ?></button>',
        '/Salva Modifiche<\/button>/' => '<?= __("Salva Modifiche") ?></button>',
        '/Nuovo Libro<\/a>/' => '<?= __("Nuovo Libro") ?></a>',
        '/Non eliminabile<\/button>/' => '<?= __("Non eliminabile") ?></button>',
        '/Aggiungi nuovo libro<\/a>/' => '<?= __("Aggiungi nuovo libro") ?></a>',
        '/Visita il sito ufficiale<\/a>/' => '<?= __("Visita il sito ufficiale") ?></a>',

        // Stats labels (scheda_editore.php)
        '/<div class="text-sm text-gray-600 font-medium">Totale Libri<\/div>/' => '<div class="text-sm text-gray-600 font-medium"><?= __(\'Totale Libri\') ?></div>',
        '/<div class="text-sm text-gray-600 font-medium">Totale Autori<\/div>/' => '<div class="text-sm text-gray-600 font-medium"><?= __(\'Totale Autori\') ?></div>',
        '/<div class="text-sm text-gray-600 font-medium">Ultimo Aggiornamento<\/div>/' => '<div class="text-sm text-gray-600 font-medium"><?= __(\'Ultimo Aggiornamento\') ?></div>',
        '/<div class="text-sm text-gray-600 font-medium">ID Editore<\/div>/' => '<div class="text-sm text-gray-600 font-medium"><?= __(\'ID Editore\') ?></div>',

        // Metadata labels
        '/<dt class="text-gray-500 uppercase tracking-wide text-xs">Sito web<\/dt>/' => '<dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Sito web") ?></dt>',
        '/<dt class="text-gray-500 uppercase tracking-wide text-xs">Codice Fiscale<\/dt>/' => '<dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Codice Fiscale") ?></dt>',
        '/<dt class="text-gray-500 uppercase tracking-wide text-xs">Aggiunto il<\/dt>/' => '<dt class="text-gray-500 uppercase tracking-wide text-xs"><?= __("Aggiunto il") ?></dt>',

        // Empty state
        '/Nessun libro registrato<\/h3>/' => '<?= __("Nessun libro registrato") ?></h3>',
        '/Aggiungi un nuovo titolo per arricchire il catalogo di questo editore\.<\/p>/' => '<?= __("Aggiungi un nuovo titolo per arricchire il catalogo di questo editore.") ?></p>',

        // Book card
        '/Titolo non disponibile/' => '<?= __("Titolo non disponibile") ?>',
        '/Editore: /' => '<?= __("Editore:") ?> ',
        '/Dettagli<\/a>/' => '<?= __("Dettagli") ?></a>',

        // Tooltips
        '/title="Rimuovere i libri dell\'editore prima di eliminarlo"/' => 'title="<?= __("Rimuovere i libri dell\'editore prima di eliminarlo") ?>"',
        '/onclick="return confirm\(\'Confermi l\\\'eliminazione dell\\\'editore\?\'\);"/' => 'onclick="return confirm(\'<?= __("Confermi l\'eliminazione dell\'editore?") ?>\');"',

        // JavaScript strings (with __() function) - use # delimiter to avoid conflicts with quotes
        "#title: 'Campo Obbligatorio'#" => "title: __('Campo Obbligatorio')",
        "#text: 'Il nome dell\\\\'editore è obbligatorio\\.#" => "text: __('Il nome dell\\'editore è obbligatorio.')",
        "#title: 'URL Non Valido'#" => "title: __('URL Non Valido')",
        "#text: 'Il sito web deve essere un URL valido \\(es\\. https://www\\.esempio\\.com\\)\\.#" => "text: __('Il sito web deve essere un URL valido (es. https://www.esempio.com).')",
        "#title: 'Email Non Valida'#" => "title: __('Email Non Valida')",
        "#text: 'L\\\\'indirizzo email deve essere valido\\.#" => "text: __('L\\'indirizzo email deve essere valido.')",
        "#title: 'Conferma Salvataggio'#" => "title: __('Conferma Salvataggio')",
        "#title: 'Conferma Aggiornamento'#" => "title: __('Conferma Aggiornamento')",
        "#confirmButtonText: 'Sì, Salva'#" => "confirmButtonText: __('Sì, Salva')",
        "#confirmButtonText: 'Sì, Aggiorna'#" => "confirmButtonText: __('Sì, Aggiorna')",
        "#cancelButtonText: 'Annulla'#" => "cancelButtonText: __('Annulla')",
        "#title: 'Salvataggio in corso\\.\\.\\.#" => "title: __('Salvataggio in corso...')",
        "#title: 'Aggiornamento in corso\\.\\.\\.#" => "title: __('Aggiornamento in corso...')",
        "#text: 'Attendere prego'#" => "text: __('Attendere prego')",
    ];

    foreach ($patterns as $search => $replace) {
        $newContent = preg_replace($search, $replace, $content);
        if ($newContent !== null && $newContent !== $content) {
            $content = $newContent;
            $replacements++;
        }
    }

    // Only write if changes were made
    if ($content !== $original) {
        file_put_contents($file, $content);
        echo "✅ " . basename($file) . " - $replacements replacements\n";
        $totalReplacements += $replacements;
    } else {
        echo "⚪ " . basename($file) . " - No changes needed\n";
    }
}

// ============================================================================
// SUMMARY
// ============================================================================

echo "\n" . str_repeat("=", 50) . "\n";
echo "📊 TRANSLATION SUMMARY\n";
echo str_repeat("=", 50) . "\n\n";

echo "New translations added: " . count($newTranslations) . "\n";
echo "Total translations now: " . count($translations) . "\n";
echo "Total replacements: $totalReplacements\n\n";

if (count($newTranslations) > 0) {
    echo "📝 New Translations:\n";
    echo str_repeat("-", 50) . "\n";
    $i = 1;
    foreach ($newTranslations as $it => $en) {
        echo sprintf("%3d. %-40s => %s\n", $i++,
            strlen($it) > 40 ? substr($it, 0, 37) . '...' : $it,
            strlen($en) > 35 ? substr($en, 0, 32) . '...' : $en
        );
    }
}

echo "\n✅ Translation script completed!\n";
echo "📁 Locale file updated: locale/en_US.json\n";
echo "📄 Files updated: " . count(array_filter($files, 'file_exists')) . "/3\n\n";

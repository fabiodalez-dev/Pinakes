#!/usr/bin/env php
<?php
/**
 * Script to translate Italian h1/h2 headings to English in admin pages
 *
 * This script:
 * 1. Adds English translations to locale/en_US.json
 * 2. Wraps Italian headings in __() function calls
 * 3. Handles dynamic content with sprintf when needed
 */

$projectRoot = dirname(__DIR__);
chdir($projectRoot);

// Define translations to add
$translations = [
    // autori/scheda_autore.php
    "Profilo professionale" => "Professional Profile",
    "Biografia" => "Biography",

    // cms/edit-home.php
    "Modifica Homepage" => "Edit Homepage",
    "Sezione Hero (Testata principale)" => "Hero Section (Main Header)",
    "Sezione Caratteristiche" => "Features Section",
    "Sezione Testo Libero" => "Free Text Section",
    "Sezione Ultimi Libri" => "Latest Books Section",
    "Call to Action (CTA)" => "Call to Action (CTA)",

    // generi/crea_genere.php
    "Crea Nuovo Genere" => "Create New Genre",

    // generi/dettaglio_genere.php
    "Sottogeneri" => "Subgenres",
    "Aggiungi Sottogenere" => "Add Subgenre",

    // libri/partials/book_form.php
    "Informazioni Base" => "Basic Information",
    "Dettagli Fisici" => "Physical Details",
    "Gestione Biblioteca" => "Library Management",
    "Copertina del Libro" => "Book Cover",
    "Posizione Fisica nella Biblioteca" => "Physical Location in Library",

    // libri/scheda_libro.php
    "Note" => "Notes",

    // prenotazioni/modifica_prenotazione.php
    "Modifica Prenotazione #%s" => "Edit Reservation #%s",

    // prestiti/modifica_prestito.php
    "Modifica prestito #%s" => "Edit Loan #%s",

    // prestiti/restituito_prestito.php
    "Restituzione prestito #%s" => "Loan Return #%s",

    // settings/advanced-tab.php
    "JavaScript Essenziali" => "Essential JavaScript",
    "JavaScript Analitici" => "Analytics JavaScript",
    "JavaScript Marketing" => "Marketing JavaScript",
    "CSS Personalizzato" => "Custom CSS",
    "Notifiche Prestiti" => "Loan Notifications",
];

// 1. Update locale/en_US.json
$localeFile = $projectRoot . '/locale/en_US.json';
if (!file_exists($localeFile)) {
    die("❌ Error: locale/en_US.json not found\n");
}

$locale = json_decode(file_get_contents($localeFile), true);
if ($locale === null) {
    die("❌ Error: Failed to parse locale/en_US.json\n");
}

$addedCount = 0;
foreach ($translations as $italian => $english) {
    if (!isset($locale[$italian])) {
        $locale[$italian] = $english;
        $addedCount++;
        echo "✓ Added translation: \"$italian\" => \"$english\"\n";
    }
}

if ($addedCount > 0) {
    file_put_contents($localeFile, json_encode($locale, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    echo "\n✅ Added $addedCount new translations to locale/en_US.json\n\n";
} else {
    echo "\nℹ All translations already exist in locale/en_US.json\n\n";
}

// 2. Apply file edits
$edits = [
    // autori/scheda_autore.php
    'app/Views/autori/scheda_autore.php' => [
        [
            'old' => '          Profilo professionale',
            'new' => '          <?= __("Profilo professionale") ?>',
        ],
        [
            'old' => '            Biografia',
            'new' => '            <?= __("Biografia") ?>',
        ],
    ],

    // cms/edit-home.php
    'app/Views/cms/edit-home.php' => [
        [
            'old' => '          Modifica Homepage',
            'new' => '          <?= __("Modifica Homepage") ?>',
        ],
        [
            'old' => '          Sezione Hero (Testata principale)',
            'new' => '          <?= __("Sezione Hero (Testata principale)") ?>',
        ],
        [
            'old' => '            Sezione Caratteristiche',
            'new' => '            <?= __("Sezione Caratteristiche") ?>',
        ],
        [
            'old' => '            Sezione Testo Libero',
            'new' => '            <?= __("Sezione Testo Libero") ?>',
        ],
        [
            'old' => '            Sezione Ultimi Libri',
            'new' => '            <?= __("Sezione Ultimi Libri") ?>',
        ],
        [
            'old' => '            Call to Action (CTA)',
            'new' => '            <?= __("Call to Action (CTA)") ?>',
        ],
    ],

    // generi/crea_genere.php
    'app/Views/generi/crea_genere.php' => [
        [
            'old' => '          Crea Nuovo Genere',
            'new' => '          <?= __("Crea Nuovo Genere") ?>',
        ],
    ],

    // generi/dettaglio_genere.php
    'app/Views/generi/dettaglio_genere.php' => [
        [
            'old' => '            Sottogeneri',
            'new' => '            <?= __("Sottogeneri") ?>',
        ],
        [
            'old' => '            Aggiungi Sottogenere',
            'new' => '            <?= __("Aggiungi Sottogenere") ?>',
        ],
    ],

    // libri/partials/book_form.php
    'app/Views/libri/partials/book_form.php' => [
        [
            'old' => '            Informazioni Base',
            'new' => '            <?= __("Informazioni Base") ?>',
        ],
        [
            'old' => '            Dettagli Fisici',
            'new' => '            <?= __("Dettagli Fisici") ?>',
        ],
        [
            'old' => '            Gestione Biblioteca',
            'new' => '            <?= __("Gestione Biblioteca") ?>',
        ],
        [
            'old' => '            Copertina del Libro',
            'new' => '            <?= __("Copertina del Libro") ?>',
        ],
        [
            'old' => '            Posizione Fisica nella Biblioteca',
            'new' => '            <?= __("Posizione Fisica nella Biblioteca") ?>',
        ],
    ],

    // libri/scheda_libro.php
    'app/Views/libri/scheda_libro.php' => [
        [
            'old' => '            Note',
            'new' => '            <?= __("Note") ?>',
        ],
    ],

    // prenotazioni/modifica_prenotazione.php
    'app/Views/prenotazioni/modifica_prenotazione.php' => [
        [
            'old' => '      <h1 class="text-2xl font-bold text-gray-900 mb-4">Modifica Prenotazione #<?php echo (int)$p[\'id\']; ?></h1>',
            'new' => '      <h1 class="text-2xl font-bold text-gray-900 mb-4"><?= sprintf(__("Modifica Prenotazione #%s"), (int)$p[\'id\']) ?></h1>',
        ],
    ],

    // prestiti/modifica_prestito.php
    'app/Views/prestiti/modifica_prestito.php' => [
        [
            'old' => '            <h1 class="text-2xl font-bold text-gray-900">Modifica prestito #<?= (int)($prestito[\'id\'] ?? 0); ?></h1>',
            'new' => '            <h1 class="text-2xl font-bold text-gray-900"><?= sprintf(__("Modifica prestito #%s"), (int)($prestito[\'id\'] ?? 0)) ?></h1>',
        ],
    ],

    // prestiti/restituito_prestito.php
    'app/Views/prestiti/restituito_prestito.php' => [
        [
            'old' => '                Restituzione prestito #<?= (int)($prestito[\'id\'] ?? 0); ?>',
            'new' => '                <?= sprintf(__("Restituzione prestito #%s"), (int)($prestito[\'id\'] ?? 0)) ?>',
        ],
    ],

    // settings/advanced-tab.php
    'app/Views/settings/advanced-tab.php' => [
        [
            'old' => '          JavaScript Essenziali',
            'new' => '          <?= __("JavaScript Essenziali") ?>',
        ],
        [
            'old' => '          JavaScript Analitici',
            'new' => '          <?= __("JavaScript Analitici") ?>',
        ],
        [
            'old' => '          JavaScript Marketing',
            'new' => '          <?= __("JavaScript Marketing") ?>',
        ],
        [
            'old' => '          CSS Personalizzato',
            'new' => '          <?= __("CSS Personalizzato") ?>',
        ],
        [
            'old' => '          Notifiche Prestiti',
            'new' => '          <?= __("Notifiche Prestiti") ?>',
        ],
    ],
];

$filesUpdated = 0;
$totalEdits = 0;

foreach ($edits as $file => $fileEdits) {
    $filePath = $projectRoot . '/' . $file;

    if (!file_exists($filePath)) {
        echo "⚠ Warning: File not found: $file\n";
        continue;
    }

    $content = file_get_contents($filePath);
    $originalContent = $content;
    $editsApplied = 0;

    foreach ($fileEdits as $edit) {
        if (strpos($content, $edit['old']) !== false) {
            $content = str_replace($edit['old'], $edit['new'], $content);
            $editsApplied++;
            $totalEdits++;
        } else {
            echo "⚠ Warning: String not found in $file:\n";
            echo "  Looking for: " . substr($edit['old'], 0, 100) . "...\n";
        }
    }

    if ($editsApplied > 0 && $content !== $originalContent) {
        file_put_contents($filePath, $content);
        $filesUpdated++;
        echo "✓ Updated $file ($editsApplied edits)\n";
    }
}

echo "\n";
echo "═══════════════════════════════════════════════\n";
echo "✅ Translation script completed!\n";
echo "═══════════════════════════════════════════════\n";
echo "📊 Summary:\n";
echo "  • Translations added: $addedCount\n";
echo "  • Files updated: $filesUpdated\n";
echo "  • Total edits applied: $totalEdits\n";
echo "═══════════════════════════════════════════════\n";

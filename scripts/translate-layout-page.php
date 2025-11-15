<?php
/**
 * Translate frontend layout.php
 * Fix cookie banner defaults, search fallback, section headings
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// All translations for layout page
$newTranslations = [
    // Cookie banner defaults (lines 29, 35, and others)
    'Utilizziamo i cookie per migliorare la tua esperienza. Continuando a visitare questo sito, accetti il nostro uso dei cookie.' => 'We use cookies to improve your experience. By continuing to visit this site, you accept our use of cookies.',
    'Accetta tutti' => 'Accept All',
    'Rifiuta non essenziali' => 'Reject Non-Essential',
    'Accetta selezionati' => 'Accept Selected',
    'Preferenze' => 'Preferences',
    'Personalizza le tue preferenze sui cookie' => 'Customize your cookie preferences',
    'Rispettiamo il tuo diritto alla privacy. Puoi scegliere di non consentire alcuni tipi di cookie. Le tue preferenze si applicheranno all\'intero sito web.' => 'We respect your right to privacy. You can choose not to allow some types of cookies. Your preferences will apply to the entire website.',
    'Cookie Essenziali' => 'Essential Cookies',
    'Questi cookie sono necessari per il funzionamento del sito e non possono essere disabilitati.' => 'These cookies are necessary for the operation of the site and cannot be disabled.',
    'Cookie Analitici' => 'Analytics Cookies',

    // Search fallback (line 1450)
    'Nessun risultato trovato' => 'No results found',

    // Search section headings (lines 1464, 1486, 1507)
    'Libri' => 'Books',
    'Autori' => 'Authors',
    'Editori' => 'Publishers',

    // Book count suffix (lines 1490, 1511) - use with __n()
    ' libri' => ' books',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now fix the layout.php code
$file = __DIR__ . '/../app/Views/frontend/layout.php';
$content = file_get_contents($file);
$original = $content;

$replacements = [
    // Fix line 29: Cookie banner description default
    "    'banner_description' => (string)ConfigStore::get('cookie_banner.banner_description', '<p>Utilizziamo i cookie per migliorare la tua esperienza. Continuando a visitare questo sito, accetti il nostro uso dei cookie.</p>')," =>
    "    'banner_description' => (string)ConfigStore::get('cookie_banner.banner_description', '<p>' . __(\"Utilizziamo i cookie per migliorare la tua esperienza. Continuando a visitare questo sito, accetti il nostro uso dei cookie.\") . '</p>'),",

    // Fix line 30: Accept all text
    "    'accept_all_text' => (string)ConfigStore::get('cookie_banner.accept_all_text', 'Accetta tutti')," =>
    "    'accept_all_text' => (string)ConfigStore::get('cookie_banner.accept_all_text', __(\"Accetta tutti\")),",

    // Fix line 31: Reject non-essential text
    "    'reject_non_essential_text' => (string)ConfigStore::get('cookie_banner.reject_non_essential_text', 'Rifiuta non essenziali')," =>
    "    'reject_non_essential_text' => (string)ConfigStore::get('cookie_banner.reject_non_essential_text', __(\"Rifiuta non essenziali\")),",

    // Fix line 32: Save selected text
    "    'save_selected_text' => (string)ConfigStore::get('cookie_banner.save_selected_text', 'Accetta selezionati')," =>
    "    'save_selected_text' => (string)ConfigStore::get('cookie_banner.save_selected_text', __(\"Accetta selezionati\")),",

    // Fix line 33: Preferences button text
    "    'preferences_button_text' => (string)ConfigStore::get('cookie_banner.preferences_button_text', 'Preferenze')," =>
    "    'preferences_button_text' => (string)ConfigStore::get('cookie_banner.preferences_button_text', __(\"Preferenze\")),",

    // Fix line 34: Preferences title
    "    'preferences_title' => (string)ConfigStore::get('cookie_banner.preferences_title', 'Personalizza le tue preferenze sui cookie')," =>
    "    'preferences_title' => (string)ConfigStore::get('cookie_banner.preferences_title', __(\"Personalizza le tue preferenze sui cookie\")),",

    // Fix line 35: Preferences description
    "    'preferences_description' => (string)ConfigStore::get('cookie_banner.preferences_description', '<p>Rispettiamo il tuo diritto alla privacy. Puoi scegliere di non consentire alcuni tipi di cookie. Le tue preferenze si applicheranno all\\'intero sito web.</p>')," =>
    "    'preferences_description' => (string)ConfigStore::get('cookie_banner.preferences_description', '<p>' . __(\"Rispettiamo il tuo diritto alla privacy. Puoi scegliere di non consentire alcuni tipi di cookie. Le tue preferenze si applicheranno all'intero sito web.\") . '</p>'),",

    // Fix line 36: Cookie essential name
    "    'cookie_essential_name' => (string)ConfigStore::get('cookie_banner.cookie_essential_name', 'Cookie Essenziali')," =>
    "    'cookie_essential_name' => (string)ConfigStore::get('cookie_banner.cookie_essential_name', __(\"Cookie Essenziali\")),",

    // Fix line 37: Cookie essential description
    "    'cookie_essential_description' => (string)ConfigStore::get('cookie_banner.cookie_essential_description', 'Questi cookie sono necessari per il funzionamento del sito e non possono essere disabilitati.')," =>
    "    'cookie_essential_description' => (string)ConfigStore::get('cookie_banner.cookie_essential_description', __(\"Questi cookie sono necessari per il funzionamento del sito e non possono essere disabilitati.\")),",

    // Fix line 38: Cookie analytics name
    "    'cookie_analytics_name' => (string)ConfigStore::get('cookie_banner.cookie_analytics_name', 'Cookie Analitici')," =>
    "    'cookie_analytics_name' => (string)ConfigStore::get('cookie_banner.cookie_analytics_name', __(\"Cookie Analitici\")),",

    // Fix line 1450: Search no results
    "                    container.innerHTML = '<div class=\"search-no-results\" style=\"padding: 1rem; text-align: center; color: #9ca3af;\">Nessun risultato trovato</div>';" =>
    "                    container.innerHTML = '<div class=\"search-no-results\" style=\"padding: 1rem; text-align: center; color: #9ca3af;\">' + __('Nessun risultato trovato') + '</div>';",

    // Fix line 1464: Libri section heading
    "                    html += '<div class=\"search-section\" style=\"padding: 0.75rem 0; border-bottom: 1px solid #f3f4f6;\"><h6 class=\"search-section-title\" style=\"margin: 0 1rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;\">Libri</h6>';" =>
    "                    html += '<div class=\"search-section\" style=\"padding: 0.75rem 0; border-bottom: 1px solid #f3f4f6;\"><h6 class=\"search-section-title\" style=\"margin: 0 1rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;\">' + __('Libri') + '</h6>';",

    // Fix line 1486: Autori section heading
    "                    html += '<div class=\"search-section\" style=\"padding: 0.75rem 0; border-bottom: 1px solid #f3f4f6;\"><h6 class=\"search-section-title\" style=\"margin: 0 1rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;\">Autori</h6>';" =>
    "                    html += '<div class=\"search-section\" style=\"padding: 0.75rem 0; border-bottom: 1px solid #f3f4f6;\"><h6 class=\"search-section-title\" style=\"margin: 0 1rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;\">' + __('Autori') + '</h6>';",

    // Fix line 1490: Author book count suffix
    "                        const authorBooks = escapeHtml(author.book_count ?? '0') + ' libri';" =>
    "                        const authorBooks = escapeHtml(author.book_count ?? '0') + __(' libri');",

    // Fix line 1507: Editori section heading
    "                    html += '<div class=\"search-section\" style=\"padding: 0.75rem 0; border-bottom: 1px solid #f3f4f6;\"><h6 class=\"search-section-title\" style=\"margin: 0 1rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;\">Editori</h6>';" =>
    "                    html += '<div class=\"search-section\" style=\"padding: 0.75rem 0; border-bottom: 1px solid #f3f4f6;\"><h6 class=\"search-section-title\" style=\"margin: 0 1rem 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151;\">' + __('Editori') + '</h6>';",

    // Fix line 1511: Publisher book count suffix
    "                        const publisherBooks = escapeHtml(publisher.book_count ?? '0') + ' libri';" =>
    "                        const publisherBooks = escapeHtml(publisher.book_count ?? '0') + __(' libri');"
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
    echo "\n✅ layout.php - Fixed " . count($replacements) . " strings\n";
} else {
    echo "\nℹ️  layout.php - No changes made\n";
}

echo "\n✅ Layout page translation COMPLETE!\n";

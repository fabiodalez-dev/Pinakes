<?php
/**
 * Translate frontend home.php
 * Fix hero, features, latest books, and CTA fallback strings
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// All translations for home page fallbacks
$newTranslations = [
    // Hero section fallbacks (lines 481-483)
    'La Tua Biblioteca Digitale' => 'Your Digital Library',
    'Scopri, prenota e gestisci i tuoi libri preferiti con la nostra piattaforma elegante e moderna.' => 'Discover, book and manage your favorite books with our elegant and modern platform.',

    // Features section fallbacks (lines 548-557)
    'Perché Scegliere la Nostra Biblioteca' => 'Why Choose Our Library',
    'Un\'esperienza di lettura moderna, intuitiva e sempre a portata di mano' => 'A modern, intuitive reading experience always at your fingertips',
    'Feature %d' => 'Feature %d',

    // Latest books section fallbacks (lines 592-594)
    'Ultimi Libri Aggiunti' => 'Latest Books Added',
    'Scopri le ultime novità della nostra collezione' => 'Discover the latest additions to our collection',

    // CTA section fallbacks (lines 633-640)
    'Inizia la Tua Avventura Letteraria' => 'Start Your Literary Adventure',
    'Unisciti alla nostra community di lettori e scopri il piacere della lettura con la nostra piattaforma moderna.' => 'Join our community of readers and discover the joy of reading with our modern platform.',
    'Registrati Ora' => 'Register Now',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now fix the home.php code
$file = __DIR__ . '/../app/Views/frontend/home.php';
$content = file_get_contents($file);
$original = $content;

$replacements = [
    // Fix line 481: Hero title fallback
    "            <h1 class=\"hero-title\"><?php echo htmlspecialchars(\$homeContent['hero']['title'] ?? 'La Tua Biblioteca Digitale', ENT_QUOTES, 'UTF-8'); ?></h1>" =>
    "            <h1 class=\"hero-title\"><?php echo htmlspecialchars(\$homeContent['hero']['title'] ?? __(\"La Tua Biblioteca Digitale\"), ENT_QUOTES, 'UTF-8'); ?></h1>",

    // Fix line 483: Hero subtitle fallback
    "                <?php echo htmlspecialchars(\$homeContent['hero']['subtitle'] ?? 'Scopri, prenota e gestisci i tuoi libri preferiti con la nostra piattaforma elegante e moderna.', ENT_QUOTES, 'UTF-8'); ?>" =>
    "                <?php echo htmlspecialchars(\$homeContent['hero']['subtitle'] ?? __(\"Scopri, prenota e gestisci i tuoi libri preferiti con la nostra piattaforma elegante e moderna.\"), ENT_QUOTES, 'UTF-8'); ?>",

    // Fix line 548: Features title fallback
    "        <h2 class=\"section-title\"><?php echo htmlspecialchars(\$homeContent['features_title']['title'] ?? 'Perché Scegliere la Nostra Biblioteca', ENT_QUOTES, 'UTF-8'); ?></h2>" =>
    "        <h2 class=\"section-title\"><?php echo htmlspecialchars(\$homeContent['features_title']['title'] ?? __(\"Perché Scegliere la Nostra Biblioteca\"), ENT_QUOTES, 'UTF-8'); ?></h2>",

    // Fix line 550: Features subtitle fallback
    "            <?php echo htmlspecialchars(\$homeContent['features_title']['subtitle'] ?? 'Un\\'esperienza di lettura moderna, intuitiva e sempre a portata di mano', ENT_QUOTES, 'UTF-8'); ?>" =>
    "            <?php echo htmlspecialchars(\$homeContent['features_title']['subtitle'] ?? __(\"Un'esperienza di lettura moderna, intuitiva e sempre a portata di mano\"), ENT_QUOTES, 'UTF-8'); ?>",

    // Fix line 556: Feature title fallback
    "                \$title = \$feature['title'] ?? \"Feature {\$i}\";" =>
    "                \$title = \$feature['title'] ?? sprintf(__(\"Feature %d\"), \$i);",

    // Fix line 592: Latest books title fallback
    "        <h2 class=\"section-title\"><?php echo htmlspecialchars(\$homeContent['latest_books_title']['title'] ?? 'Ultimi Libri Aggiunti', ENT_QUOTES, 'UTF-8'); ?></h2>" =>
    "        <h2 class=\"section-title\"><?php echo htmlspecialchars(\$homeContent['latest_books_title']['title'] ?? __(\"Ultimi Libri Aggiunti\"), ENT_QUOTES, 'UTF-8'); ?></h2>",

    // Fix line 594: Latest books subtitle fallback
    "            <?php echo htmlspecialchars(\$homeContent['latest_books_title']['subtitle'] ?? 'Scopri le ultime novità della nostra collezione', ENT_QUOTES, 'UTF-8'); ?>" =>
    "            <?php echo htmlspecialchars(\$homeContent['latest_books_title']['subtitle'] ?? __(\"Scopri le ultime novità della nostra collezione\"), ENT_QUOTES, 'UTF-8'); ?>",

    // Fix line 633: CTA title fallback
    "            <h2 class=\"cta-title\"><?php echo htmlspecialchars(\$homeContent['cta']['title'] ?? 'Inizia la Tua Avventura Letteraria', ENT_QUOTES, 'UTF-8'); ?></h2>" =>
    "            <h2 class=\"cta-title\"><?php echo htmlspecialchars(\$homeContent['cta']['title'] ?? __(\"Inizia la Tua Avventura Letteraria\"), ENT_QUOTES, 'UTF-8'); ?></h2>",

    // Fix line 635: CTA subtitle fallback
    "                <?php echo htmlspecialchars(\$homeContent['cta']['subtitle'] ?? 'Unisciti alla nostra community di lettori e scopri il piacere della lettura con la nostra piattaforma moderna.', ENT_QUOTES, 'UTF-8'); ?>" =>
    "                <?php echo htmlspecialchars(\$homeContent['cta']['subtitle'] ?? __(\"Unisciti alla nostra community di lettori e scopri il piacere della lettura con la nostra piattaforma moderna.\"), ENT_QUOTES, 'UTF-8'); ?>",

    // Fix line 640: CTA button text fallback
    "                    <?php echo htmlspecialchars(\$homeContent['cta']['button_text'] ?? 'Registrati Ora', ENT_QUOTES, 'UTF-8'); ?>" =>
    "                    <?php echo htmlspecialchars(\$homeContent['cta']['button_text'] ?? __(\"Registrati Ora\"), ENT_QUOTES, 'UTF-8'); ?>",
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
    echo "\n✅ home.php - Fixed " . count($replacements) . " strings\n";
} else {
    echo "\nℹ️  home.php - No changes made\n";
}

echo "\n✅ Home page translation COMPLETE!\n";

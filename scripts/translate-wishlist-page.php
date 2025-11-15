<?php
/**
 * Translate app/Views/profile/wishlist.php
 * Comprehensive translation of ALL Italian strings
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// All translations for wishlist page
$newTranslations = [
    // Hero section
    'I tuoi preferiti' => 'Your Favorites',
    'Una panoramica dei libri che hai salvato per non perderli di vista.' => 'An overview of the books you\'ve saved to keep track of.',

    // Summary card
    'Riepilogo wishlist' => 'Wishlist Summary',
    'Gestisci i tuoi titoli preferiti, scopri quando tornano disponibili e accedi rapidamente ai dettagli del libro.' => 'Manage your favorite titles, find out when they become available, and quickly access book details.',
    'preferiti' => 'favorites',
    'disponibili ora' => 'available now',
    'in attesa' => 'pending',
    'Esplora catalogo' => 'Browse Catalog',
    'Prenotazioni' => 'Reservations',

    // Filter card
    'Ricerca rapida' => 'Quick Search',
    'Pulisci filtro' => 'Clear Filter',

    // Empty state
    'La tua wishlist è vuota' => 'Your wishlist is empty',
    'Aggiungi i libri che ti interessano dalla scheda di dettaglio per ricevere un promemoria quando tornano disponibili.' => 'Add books you\'re interested in from the detail page to receive a reminder when they become available.',
    'Cerca titoli' => 'Search Titles',
    'Torna alla dashboard' => 'Back to Dashboard',

    // No results message
    'Nessun titolo corrisponde al filtro corrente.' => 'No titles match the current filter.',

    // Book cards
    'Copertina' => 'Cover',
    'Disponibile ora' => 'Available Now',
    'In attesa' => 'Pending',
    'Copie disponibili:' => 'Available Copies:',
    'Rimuovi dalla wishlist' => 'Remove from Wishlist',

    // JavaScript messages (already using __() but need translations)
    'Rimuovere dalla wishlist?' => 'Remove from Wishlist?',
    'Sei sicuro di voler rimuovere questo libro dalla tua wishlist?' => 'Are you sure you want to remove this book from your wishlist?',
    'Sì, rimuovi' => 'Yes, Remove',
    'Si è verificato un errore nella rimozione. Riprova.' => 'An error occurred during removal. Please try again.',
    'OK' => 'OK',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Now wrap Italian strings in wishlist.php
$file = __DIR__ . '/../app/Views/profile/wishlist.php';
$content = file_get_contents($file);
$original = $content;

$replacements = [
    // Hero section
    '    <h1 class="hero-title">I tuoi preferiti</h1>' =>
        '    <h1 class="hero-title"><?= __("I tuoi preferiti") ?></h1>',

    '    <p class="hero-subtitle">Una panoramica dei libri che hai salvato per non perderli di vista.</p>' =>
        '    <p class="hero-subtitle"><?= __("Una panoramica dei libri che hai salvato per non perderli di vista.") ?></p>',

    // Summary card
    '        <h2 class="h4 fw-bold mb-2">Riepilogo wishlist</h2>' =>
        '        <h2 class="h4 fw-bold mb-2"><?= __("Riepilogo wishlist") ?></h2>',

    '        <p class="text-muted mb-0">Gestisci i tuoi titoli preferiti, scopri quando tornano disponibili e accedi rapidamente ai dettagli del libro.</p>' =>
        '        <p class="text-muted mb-0"><?= __("Gestisci i tuoi titoli preferiti, scopri quando tornano disponibili e accedi rapidamente ai dettagli del libro.") ?></p>',

    '          <span class="wishlist-stat"><i class="fas fa-heart"></i> <span id="wishlist-total-count"><?= $totalItems; ?></span> preferiti</span>' =>
        '          <span class="wishlist-stat"><i class="fas fa-heart"></i> <span id="wishlist-total-count"><?= $totalItems; ?></span> <?= __("preferiti") ?></span>',

    '          <span class="wishlist-stat"><i class="fas fa-bolt"></i> <span id="wishlist-available-count"><?= $availableCount; ?></span> disponibili ora</span>' =>
        '          <span class="wishlist-stat"><i class="fas fa-bolt"></i> <span id="wishlist-available-count"><?= $availableCount; ?></span> <?= __("disponibili ora") ?></span>',

    '          <span class="wishlist-stat"><i class="fas fa-clock"></i> <span id="wishlist-pending-count"><?= max($pendingCount, 0); ?></span> in attesa</span>' =>
        '          <span class="wishlist-stat"><i class="fas fa-clock"></i> <span id="wishlist-pending-count"><?= max($pendingCount, 0); ?></span> <?= __("in attesa") ?></span>',

    '          <a href="/catalogo" class="btn-outline"><i class="fas fa-search me-2"></i>Esplora catalogo</a>' =>
        '          <a href="/catalogo" class="btn-outline"><i class="fas fa-search me-2"></i><?= __("Esplora catalogo") ?></a>',

    '          <a href="/prenotazioni" class="btn-outline"><i class="fas fa-bookmark me-2"></i>Prenotazioni</a>' =>
        '          <a href="/prenotazioni" class="btn-outline"><i class="fas fa-bookmark me-2"></i><?= __("Prenotazioni") ?></a>',

    // Filter card
    '      <label for="wishlist_search" class="mb-2">Ricerca rapida</label>' =>
        '      <label for="wishlist_search" class="mb-2"><?= __("Ricerca rapida") ?></label>',

    '    <button id="clear-search" type="button" class="text-uppercase">Pulisci filtro</button>' =>
        '    <button id="clear-search" type="button" class="text-uppercase"><?= __("Pulisci filtro") ?></button>',

    // Empty state
    '      <h2 class="h4 fw-bold mb-2">La tua wishlist è vuota</h2>' =>
        '      <h2 class="h4 fw-bold mb-2"><?= __("La tua wishlist è vuota") ?></h2>',

    '      <p class="text-muted mb-4">Aggiungi i libri che ti interessano dalla scheda di dettaglio per ricevere un promemoria quando tornano disponibili.</p>' =>
        '      <p class="text-muted mb-4"><?= __("Aggiungi i libri che ti interessano dalla scheda di dettaglio per ricevere un promemoria quando tornano disponibili.") ?></p>',

    '        <a href="/catalogo" class="btn-outline"><i class="fas fa-compass me-2"></i>Cerca titoli</a>' =>
        '        <a href="/catalogo" class="btn-outline"><i class="fas fa-compass me-2"></i><?= __("Cerca titoli") ?></a>',

    '        <a href="/dashboard" class="btn-outline"><i class="fas fa-arrow-left me-2"></i>Torna alla dashboard</a>' =>
        '        <a href="/dashboard" class="btn-outline"><i class="fas fa-arrow-left me-2"></i><?= __("Torna alla dashboard") ?></a>',

    // No results message
    '      <i class="fas fa-info-circle me-2"></i>Nessun titolo corrisponde al filtro corrente.' =>
        '      <i class="fas fa-info-circle me-2"></i><?= __("Nessun titolo corrisponde al filtro corrente.") ?>',

    // Book cards
    '              <img src="<?= HtmlHelper::e($cover); ?>" alt="Copertina" onerror="this.src=\'/uploads/copertine/placeholder.jpg\'">' =>
        '              <img src="<?= HtmlHelper::e($cover); ?>" alt="<?= __("Copertina") ?>" onerror="this.src=\'/uploads/copertine/placeholder.jpg\'">',

    '                <?= $available ? \'Disponibile ora\' : \'In attesa\'; ?>' =>
        '                <?= $available ? __("Disponibile ora") : __("In attesa"); ?>',

    '              <p class="text-muted small mb-0">Copie disponibili: <?= (int)($it[\'copie_disponibili\'] ?? 0); ?></p>' =>
        '              <p class="text-muted small mb-0"><?= __("Copie disponibili:") ?> <?= (int)($it[\'copie_disponibili\'] ?? 0); ?></p>',

    '                <button type="button" class="btn btn-light remove-fav-btn" title="Rimuovi dalla wishlist">' =>
        '                <button type="button" class="btn btn-light remove-fav-btn" title="<?= __("Rimuovi dalla wishlist") ?>">',
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
    echo "\n✅ wishlist.php - wrapped " . count($replacements) . " strings\n";
} else {
    echo "\nℹ️  wishlist.php - no changes needed\n";
}

echo "\n✅ Translation complete!\n";

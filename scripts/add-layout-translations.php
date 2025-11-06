<?php
/**
 * Add all layout translations (admin sidebar, frontend header, notifications, etc.)
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';

// Load existing translations
$existing = json_decode(file_get_contents($translationFile), true);

// Layout translations
$newTranslations = [
    // Sidebar footer
    'Sistema attivo' => 'System active',
    'Sistema' => 'System',

    // Header search
    'Cerca' => 'Search',

    // Notifications
    'Notifiche' => 'Notifications',
    'Segna tutte come lette' => 'Mark all as read',
    'Nessuna notifica' => 'No notifications',
    'Vedi tutte le notifiche' => 'View all notifications',

    // User menu (frontend header)
    'Prenotazioni' => 'Reservations',
    'Preferiti' => 'Favorites',
    'Il mio profilo' => 'My Profile',
    'Le mie prenotazioni' => 'My Reservations',
    'I miei preferiti' => 'My Favorites',

    // Auth buttons
    'Accedi' => 'Login',
    'Registrati' => 'Register',

    // Mobile
    'Chiudi menu' => 'Close menu',
    'Apri menu' => 'Open menu',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);

// Sort alphabetically
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save
$formatted = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($translationFile, $formatted);

echo "✅ Added layout translations\n";
echo "   Previous count: " . count($existing) . "\n";
echo "   New translations added: " . (count($merged) - count($existing)) . "\n";
echo "   Total translations: " . count($merged) . "\n\n";

// Show new translations
$newAdded = array_diff_key($newTranslations, $existing);
if (!empty($newAdded)) {
    echo "📝 New translations added:\n";
    foreach ($newAdded as $it => $en) {
        echo "   • \"{$it}\" → \"{$en}\"\n";
    }
}

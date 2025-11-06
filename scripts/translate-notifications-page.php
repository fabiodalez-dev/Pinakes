<?php
/**
 * Add missing translations for admin/notifications page
 * The page already uses __() but some translations are missing
 */

declare(strict_types=1);

$translationFile = __DIR__ . '/../locale/en_US.json';
$existing = json_decode(file_get_contents($translationFile), true);

// Missing translations for notifications page
$newTranslations = [
    // Link button
    'Vai' => 'Go',

    // Time labels
    'Adesso' => 'Just now',
    'Ieri alle %s' => 'Yesterday at %s',
    '%d minuto fa' => '%d minute ago',
    '%d minuti fa' => '%d minutes ago',
    '%d ora fa' => '%d hour ago',
    '%d ore fa' => '%d hours ago',

    // Action buttons
    'Segna come letto' => 'Mark as read',
    'Elimina' => 'Delete',

    // Status messages
    '%d notifica non letta' => '%d unread notification',
    '%d notifiche non lette' => '%d unread notifications',

    // Confirmation
    'Sei sicuro di voler eliminare questa notifica?' => 'Are you sure you want to delete this notification?',
];

// Merge with existing
$merged = array_merge($existing, $newTranslations);
ksort($merged, SORT_STRING | SORT_FLAG_CASE);

// Save translations
file_put_contents($translationFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "✅ Added " . count($newTranslations) . " translations\n";
echo "   Total translations: " . count($merged) . "\n";

echo "\n✅ Translation complete!\n";
echo "ℹ️  Note: app/Views/admin/notifications.php already uses __() correctly - no code changes needed\n";

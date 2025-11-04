<?php
/**
 * Cron job per notifiche automatiche
 * Esegue invii email automatici per:
 * - Avvisi scadenza prestiti (giorni configurabili, default 3)
 * - Notifiche prestiti scaduti
 * - Disponibilità libri in wishlist
 *
 * Aggiungere a crontab:
 * Esegui ogni ora dalle 8 alle 20:
 * 0 8-20 * * * /usr/bin/php /path/to/biblioteca/cron/automatic-notifications.php
 *
 * Oppure ogni 15 minuti durante l'orario di lavoro:
 * */15 8-18 * * 1-5 /usr/bin/php /path/to/biblioteca/cron/automatic-notifications.php
 */

declare(strict_types=1);

// Include autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Include settings
$settings = require __DIR__ . '/../config/settings.php';

use App\Support\NotificationService;
use mysqli;

// Funzione per logging
function logMessage(string $message): void {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] {$message}\n";
}

try {
    logMessage("Starting automatic notifications cron job");

    // Database connection
    $cfg = $settings['db'];
    $db = new mysqli(
        $cfg['hostname'],
        $cfg['username'],
        $cfg['password'],
        $cfg['database'],
        $cfg['port'],
        $cfg['hostname'] === 'localhost' ? '/opt/homebrew/var/mysql/mysql.sock' : null
    );

    if ($db->connect_error) {
        throw new Exception("Database connection failed: " . $db->connect_error);
    }

    $db->set_charset($cfg['charset']);
    logMessage("Database connected successfully");

    // Initialize notification service
    $notificationService = new NotificationService($db);

    // Run all automatic notifications
    $results = $notificationService->runAutomaticNotifications();

    // Log results
    logMessage("Notifications completed:");
    logMessage("- Expiration warnings sent: " . $results['expiration_warnings']);
    logMessage("- Overdue notifications sent: " . $results['overdue_notifications']);
    logMessage("- Wishlist notifications sent: " . $results['wishlist_notifications']);

    if (!empty($results['errors'])) {
        logMessage("Errors encountered:");
        foreach ($results['errors'] as $error) {
            logMessage("  - " . $error);
        }
    }

    $totalSent = $results['expiration_warnings'] + $results['overdue_notifications'] + $results['wishlist_notifications'];
    logMessage("Total emails sent: {$totalSent}");

    // Additional maintenance tasks (run once a day)
    $hour = (int)date('H');
    if ($hour === 6) { // Run at 6 AM
        logMessage("Running daily maintenance tasks");

        // Update overdue loans status
        $stmt = $db->prepare("
            UPDATE prestiti
            SET stato = 'in_ritardo'
            WHERE stato = 'in_corso'
            AND data_scadenza < CURDATE()
        ");
        $stmt->execute();
        $overdueUpdated = $db->affected_rows;
        $stmt->close();

        logMessage("Updated {$overdueUpdated} loans to overdue status");

        // Clean up old notification flags (older than 30 days)
        $stmt = $db->prepare("
            UPDATE prestiti
            SET warning_sent = 0, overdue_notification_sent = 0
            WHERE stato IN ('restituito', 'perso', 'danneggiato')
            AND data_restituzione < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ");
        $stmt->execute();
        $cleanedUp = $db->affected_rows;
        $stmt->close();

        logMessage("Cleaned up notification flags for {$cleanedUp} old loans");
    }

    $db->close();
    logMessage("Cron job completed successfully");

} catch (Exception $e) {
    logMessage("ERROR: " . $e->getMessage());
    exit(1);
}
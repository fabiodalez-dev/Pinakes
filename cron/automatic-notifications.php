<?php
/**
 * Cron job per notifiche automatiche
 *
 * Esegue SOLO invii email automatici per:
 * - Avvisi scadenza prestiti (giorni configurabili, default 3)
 * - Notifiche prestiti scaduti
 * - Disponibilità libri in wishlist
 *
 * NOTE: Per la manutenzione completa (transizioni di stato, scadenze, ecc.)
 * usare full-maintenance.php che chiama MaintenanceService::runAll()
 *
 * Aggiungere a crontab:
 * 0 8-20 * * * /usr/bin/php /path/to/biblioteca/cron/automatic-notifications.php
 *
 */

declare(strict_types=1);

use Dotenv\Dotenv;
use App\Support\NotificationService;
use App\Controllers\ReservationManager;

// ============================================================
// PROCESS LOCK - Prevent concurrent cron executions
// ============================================================
$lockFile = __DIR__ . '/../storage/cache/notifications.lock';

// Ensure lock directory exists
$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
    if (!is_dir($lockDir)) {
        fwrite(STDERR, "ERROR: Could not create lock directory: $lockDir\n");
        exit(1);
    }
}

$lockHandle = fopen($lockFile, 'c');
if (!$lockHandle) {
    fwrite(STDERR, "ERROR: Could not create lock file: $lockFile\n");
    exit(1);
}

// Try to acquire exclusive lock (non-blocking)
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "INFO: Another notifications process is already running. Exiting.\n");
    fclose($lockHandle);
    exit(0);
}

// Write PID to lock file for debugging
ftruncate($lockHandle, 0);
fwrite($lockHandle, (string)getmypid());
fflush($lockHandle);

// Register shutdown function to release lock and clean up
register_shutdown_function(function () use ($lockHandle, $lockFile) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    @unlink($lockFile);
});

// ============================================================
// END PROCESS LOCK
// ============================================================

// Include autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables from .env file
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
try {
    $dotenv->load();
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: Failed to load .env file: " . $e->getMessage() . "\n");
    exit(1);
}

// Include settings
$settings = require __DIR__ . '/../config/settings.php';

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
        $cfg['socket'] ?? null  // Use socket from config if set
    );

    if ($db->connect_error) {
        throw new Exception("Database connection failed: " . $db->connect_error);
    }

    $db->set_charset($cfg['charset']);

    // Niente SET SESSION time_zone (M9): la coerenza sul giorno è garantita dai
    // parametri PHP calcolati con DateHelper nel timezone applicativo; il web non
    // imposta alcuna session timezone, quindi forzare UTC nel solo cron creava
    // due regimi diversi per gli stessi confronti di data.

    // Bootstrap I18n con il locale di installazione, come fa public/index.php per
    // il web (H4): in CLI il locale resterebbe il default statico it_IT e le email
    // cron uscirebbero in italiano — o fallirebbero del tutto per i template senza
    // default hardcoded — sulle installazioni non italiane.
    try {
        \App\Support\I18n::loadFromDatabase($db);
    } catch (\Throwable $e) {
        // Fallback ai locale hardcoded se la query fallisce (es. installazione
        // incompleta): stesso comportamento del bootstrap web.
        logMessage("WARNING: Failed to load languages from database: " . $e->getMessage());
    }

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

    // M4: recupero delle email 'reservation_book_available' il cui invio era
    // fallito (prenotazione già 'completata' + notifica_inviata=0). Fuori da
    // ogni transazione: il metodo invia le email direttamente.
    $retriedNotifications = 0;
    try {
        $reservationManager = new ReservationManager($db);
        $retriedNotifications = $reservationManager->retryUnsentReservationNotifications();
        logMessage("- Reservation availability notifications retried: " . $retriedNotifications);
    } catch (Throwable $e) {
        logMessage("WARNING: retryUnsentReservationNotifications failed: " . $e->getMessage());
    }

    $totalSent = $results['expiration_warnings'] + $results['overdue_notifications'] + $results['wishlist_notifications'] + $retriedNotifications;
    logMessage("Total emails sent: {$totalSent}");

    // NOTE: Daily maintenance tasks (loan state transitions, expiry handling) are now
    // handled exclusively by full-maintenance.php via MaintenanceService::runAll()
    // This script focuses only on sending notifications.

    $db->close();
    logMessage("Cron job completed successfully");

} catch (Exception $e) {
    logMessage("ERROR: " . $e->getMessage());
    exit(1);
}

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
 */

declare(strict_types=1);

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

// Include settings
$settings = require __DIR__ . '/../config/settings.php';

use App\Support\NotificationService;
use App\Support\DataIntegrity;
use App\Models\SettingsRepository;

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

    // Set timezone to UTC for consistency with main application
    $db->query("SET SESSION time_zone = '+00:00'");

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

        // Transition scheduled loans (prenotato -> da_ritirare) when their start date arrives
        // NOTE: Copy stays 'prenotato' during da_ritirare - it only becomes 'prestato' when user picks up (confirmPickup)
        $stmt = $db->prepare("
            SELECT id, copia_id, libro_id FROM prestiti
            WHERE stato = 'prenotato'
            AND data_prestito <= CURDATE()
            AND attivo = 1
        ");
        $stmt->execute();
        $scheduledLoans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Get pickup deadline setting
        $settingsRepo = new \App\Models\SettingsRepository($db);
        $pickupDays = (int) ($settingsRepo->get('loans', 'pickup_expiry_days', '3') ?? 3);
        // Use UTC to match DB session timezone (set to UTC at connection)
        $pickupDeadline = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("+{$pickupDays} days")
            ->format('Y-m-d');

        $activatedLoans = 0;
        foreach ($scheduledLoans as $loan) {
            $db->begin_transaction();
            try {
                // Update loan status to da_ritirare (NOT in_corso - user must pick up first)
                // Set pickup_deadline so MaintenanceService can expire if not picked up
                // State guard prevents overwriting concurrent changes (e.g., already picked up)
                $updateStmt = $db->prepare("UPDATE prestiti SET stato = 'da_ritirare', pickup_deadline = ? WHERE id = ? AND stato = 'prenotato'");
                $updateStmt->bind_param('si', $pickupDeadline, $loan['id']);
                $updateStmt->execute();
                $affectedRows = $updateStmt->affected_rows;
                $updateStmt->close();

                // Skip if loan state changed concurrently (already picked up, cancelled, etc.)
                if ($affectedRows === 0) {
                    $db->rollback();
                    continue;
                }

                // DO NOT mark the copy as 'prestato' - copy stays available/prenotato
                // Copy state will be updated to 'prestato' only when user confirms pickup
                // DataIntegrity.recalculateBookAvailability will set copy to 'prenotato' for da_ritirare loans

                // Recalculate book availability using DataIntegrity for consistency
                $integrity = new DataIntegrity($db);
                if (!$integrity->recalculateBookAvailability((int)$loan['libro_id'])) {
                    throw new \RuntimeException("recalculateBookAvailability failed for book {$loan['libro_id']}");
                }

                $db->commit();
                $activatedLoans++;

                // Send pickup ready notification to user (outside transaction)
                try {
                    $notificationService = new \App\Support\NotificationService($db);
                    $notificationService->sendPickupReadyNotification($loan['id']);
                } catch (Throwable $notifError) {
                    logMessage("Failed to send pickup notification for loan {$loan['id']}: " . $notifError->getMessage());
                }
            } catch (Throwable $e) {
                $db->rollback();
                logMessage("Failed to activate loan {$loan['id']}: " . $e->getMessage());
            }
        }

        logMessage("Activated {$activatedLoans} scheduled loans (prenotato -> da_ritirare)");

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

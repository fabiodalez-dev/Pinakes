<?php
/**
 * Cron job per manutenzione completa
 * Esegue MaintenanceService::runAll() per copertura completa di tutte le attività:
 * - Attivazione prestiti programmati (prenotato -> da_ritirare)
 * - Aggiornamento prestiti scaduti (in_corso -> in_ritardo)
 * - Scadenza prenotazioni non ritirate (da_ritirare -> scaduto)
 * - Scadenza prenotazioni non utilizzate (prenotato -> scaduto)
 * - Conversione prenotazioni programmate
 * - Generazione calendario ICS
 * - Invio notifiche automatiche
 *
 * Aggiungere a crontab:
 * Esegui ogni giorno alle 6:00:
 * 0 6 * * * /usr/bin/php /path/to/biblioteca/cron/full-maintenance.php
 *
 * Oppure ogni 6 ore per maggiore copertura:
 * 0 0,6,12,18 * * * /usr/bin/php /path/to/biblioteca/cron/full-maintenance.php
 *
 */

declare(strict_types=1);

use Dotenv\Dotenv;
use App\Support\MaintenanceService;

// ============================================================
// PROCESS LOCK - Prevent concurrent cron executions
// ============================================================
$lockFile = __DIR__ . '/../storage/cache/full-maintenance.lock';

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
    fwrite(STDERR, "INFO: Another full-maintenance process is already running. Exiting.\n");
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
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- $lockFile is a fixed internal path (storage/cache/full-maintenance.lock), not user input
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
    logMessage("Starting full maintenance cron job");

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

    // Initialize maintenance service (cron should always run without cooldown)
    $maintenance = new MaintenanceService($db);

    // Run all maintenance tasks
    logMessage("Running all maintenance tasks...");
    $results = $maintenance->runAll();

    // Log detailed results (runAll() returns a fully-populated typed array, so
    // every key is guaranteed present — no null-coalescing needed)
    logMessage("Maintenance completed:");
    logMessage("- Scheduled loans activated (prenotato -> da_ritirare): " . $results['scheduled_loans_activated']);
    logMessage("- Expired waitlist reservations (prenotazioni -> annullata): " . $results['expired_waitlist_reservations']);
    logMessage("- Scheduled reservations converted: " . $results['reservations_converted']);
    logMessage("- Expired reservations (prenotato -> scaduto): " . $results['expired_reservations']);
    logMessage("- Expired pickups (da_ritirare -> scaduto): " . $results['expired_pickups']);
    logMessage("- Overdue loans updated (in_corso -> in_ritardo): " . $results['overdue_loans_updated']);

    // Notification results (counts returned at top-level by MaintenanceService::runAll)
    logMessage("Notifications sent:");
    logMessage("  - Expiration warnings: " . $results['expiration_warnings']);
    logMessage("  - Overdue notifications: " . $results['overdue_notifications']);
    logMessage("  - Wishlist notifications: " . $results['wishlist_notifications']);

    if (!empty($results['errors'])) {
        logMessage("Errors encountered during maintenance:");
        foreach ($results['errors'] as $error) {
            logMessage("  - " . $error);
        }
    }

    // ICS calendar generation
    logMessage("- ICS calendar generated: " . ($results['ics_generated'] ? 'Yes' : 'No'));

    // Calculate totals
    $totalActions =
        $results['scheduled_loans_activated'] +
        $results['expired_waitlist_reservations'] +
        $results['reservations_converted'] +
        $results['expired_reservations'] +
        $results['expired_pickups'] +
        $results['overdue_loans_updated'];

    logMessage("Total database actions: {$totalActions}");

    // Output JSON for programmatic parsing (useful for monitoring)
    if (in_array('--json', $argv ?? [], true)) {
        echo "\n--- JSON OUTPUT ---\n";
        echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
    }

    $db->close();

    // Exit with code 2 if there were non-fatal errors (distinguishes from code 1 = fatal)
    if (!empty($results['errors'])) {
        logMessage("Full maintenance completed with " . count($results['errors']) . " error(s)");
        exit(2);
    }

    logMessage("Full maintenance cron job completed successfully");

} catch (\Throwable $e) {
    // \Throwable, not \Exception: with strict_types a TypeError is an \Error and
    // would otherwise escape the cron's error/cleanup path.
    logMessage("ERROR: " . $e->getMessage());
    if (isset($db) && $db instanceof mysqli) {
        $db->close();
    }
    exit(1);
}

<?php
/**
 * Cron script to check for expired reservations
 * Run daily or hourly via cron
 */

use App\Support\MaintenanceService;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

// Check if running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

// Process lock: prevent two overlapping runs from both expiring the same
// reservation and desyncing copy state / reassignment (mirrors maintenance.php).
$lockFile = __DIR__ . '/../storage/cache/check-expired-reservations.lock';
$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "INFO: another check-expired-reservations run is in progress. Exiting.\n");
    exit(0);
}
register_shutdown_function(static function () use ($lockHandle) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
});

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();  // safeLoad: no .env is OK — container/CLI runs rely on real env vars (getenv fallback in config/settings.php)

// Connect to DB via the shared cron bootstrap helper (handles DB_SOCKET
// host normalisation so the socket is actually used on macOS installs).
require __DIR__ . '/_db_bootstrap.php';
$db = pinakes_db_from_env();
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting expired reservations check...\n";

// Delegate to the SINGLE production sweep instead of duplicating its logic:
// MaintenanceService::checkExpiredReservations() covers scheduled loans AND
// never-approved pending conversions (origine prenotazione/richiesta/ncip),
// frees pinned copies with the state guard, promotes the freed capacity,
// records SYSTEM audit events and sends the reservation_expired email —
// guarantees the old inline copy of this loop silently lacked.
$maintenanceService = new MaintenanceService($db);
$expiredCount = $maintenanceService->checkExpiredReservations();

$db->close();

echo "[" . date('Y-m-d H:i:s') . "] Completed. Expired {$expiredCount} reservations.\n";

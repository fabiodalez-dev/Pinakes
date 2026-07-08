<?php
/**
 * Cron script to check for expired reservations
 * Run daily or hourly via cron
 */

use App\Services\ReservationReassignmentService;
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
$dotenv->load();

// Connect to DB via the shared cron bootstrap helper (handles DB_SOCKET
// host normalisation so the socket is actually used on macOS installs).
require __DIR__ . '/_db_bootstrap.php';
$db = pinakes_db_from_env();
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting expired reservations check...\n";

// Find expired reservations (prestiti with stato='prenotato' and data_scadenza < TODAY)
// attivi=1. "Today" must be the app timezone (DateHelper), not the PHP process tz —
// the rest of the loan pipeline compares against app-tz dates, so a raw date() here
// would skip/early-expire reservations in the pre-midnight local offset window.
$today = \App\Support\DateHelper::today();

$stmt = $db->prepare("
    SELECT id, libro_id, copia_id, utente_id
    FROM prestiti
    WHERE stato = 'prenotato'
    AND attivo = 1
    AND data_scadenza < ?
");
$stmt->bind_param('s', $today);
$stmt->execute();
$result = $stmt->get_result();

$expiredCount = 0;

while ($reservation = $result->fetch_assoc()) {
    $reassignmentService = new ReservationReassignmentService($db);
    $reassignmentService->setExternalTransaction(true);

    $id = (int) $reservation['id'];
    $copiaId = $reservation['copia_id'] ? (int) $reservation['copia_id'] : null;
    $utenteId = (int) $reservation['utente_id'];

    echo "Expiring reservation #{$id}...\n";

    $db->begin_transaction();
    try {
        // Mark as expired — mark-then-act: the state guard + affected_rows check make
        // this idempotent, so a concurrent run (or a row whose state changed since the
        // SELECT) does NOT re-free the copy and re-run reassignment.
        $updateStmt = $db->prepare("
            UPDATE prestiti
            SET stato = 'scaduto',
                attivo = 0,
                updated_at = NOW(),
                note = CONCAT(COALESCE(note, ''), '\n[System] Scaduta il ', DATE_FORMAT(CURDATE(), '%d/%m/%Y'))
            WHERE id = ? AND stato = 'prenotato' AND attivo = 1
        ");
        $updateStmt->bind_param('i', $id);
        $updateStmt->execute();
        $claimed = $updateStmt->affected_rows === 1;
        $updateStmt->close();

        if (!$claimed) {
            // Already expired/changed by another run — skip without touching the copy.
            $db->rollback();
            continue;
        }

        // If a copy was assigned, make it available (if currently 'prenotato')
        if ($copiaId) {
            // Check current copy status
            $checkCopy = $db->prepare("SELECT stato FROM copie WHERE id = ? FOR UPDATE");
            $checkCopy->bind_param('i', $copiaId);
            $checkCopy->execute();
            $copyState = $checkCopy->get_result()->fetch_assoc();
            $checkCopy->close();

            if ($copyState && $copyState['stato'] === 'prenotato') {
                // Update copy to available
                $updateCopy = $db->prepare("UPDATE copie SET stato = 'disponibile' WHERE id = ?");
                $updateCopy->bind_param('i', $copiaId);
                $updateCopy->execute();
                $updateCopy->close();

                // Trigger reassignment logic for this copy
                // This will find the next reservation in line and assign the copy to it
                $reassignmentService->reassignOnReturn($copiaId);
            }
        }

        $db->commit();
        $expiredCount++;

        // Send deferred notifications after commit (in separate try/catch
        // since rollback is meaningless after a successful commit)
        try {
            $reassignmentService->flushDeferredNotifications();
        } catch (\Throwable $notifyEx) {
            echo "Warning: failed to send notifications for reservation #{$id}: " . $notifyEx->getMessage() . "\n";
        }

    } catch (\Throwable $e) {
        $db->rollback();
        echo "Error expiring reservation #{$id}: " . $e->getMessage() . "\n";
    }
}

$stmt->close();
$db->close();

echo "[" . date('Y-m-d H:i:s') . "] Completed. Expired {$expiredCount} reservations.\n";

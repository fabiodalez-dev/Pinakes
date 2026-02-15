<?php
/**
 * Cron script to check for expired reservations
 * Run daily or hourly via cron
 */

use App\Services\ReservationReassignmentService;
use App\Support\NotificationService;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

// Check if running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Connect to DB
$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$name = $_ENV['DB_NAME'] ?? 'biblioteca';
$port = (int) ($_ENV['DB_PORT'] ?? 3306);

$db = new mysqli($host, $user, $pass, $name, $port);
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

echo "[" . date('Y-m-d H:i:s') . "] Starting expired reservations check...\n";

// Find expired reservations (prestiti with stato='prenotato' and data_scadenza < TODAY)
// attivi=1
$today = date('Y-m-d');

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
$reassignmentService = new ReservationReassignmentService($db);
$reassignmentService->setExternalTransaction(true);
$notificationService = new NotificationService($db);

while ($reservation = $result->fetch_assoc()) {
    $id = (int) $reservation['id'];
    $copiaId = $reservation['copia_id'] ? (int) $reservation['copia_id'] : null;
    $utenteId = (int) $reservation['utente_id'];

    echo "Expiring reservation #{$id}...\n";

    $db->begin_transaction();
    try {
        // Mark as expired
        $updateStmt = $db->prepare("
            UPDATE prestiti
            SET stato = 'scaduto',
                attivo = 0,
                updated_at = NOW(),
                note = CONCAT(COALESCE(note, ''), '\n[System] Scaduta il " . date('d/m/Y') . "')
            WHERE id = ?
        ");
        $updateStmt->bind_param('i', $id);
        $updateStmt->execute();
        $updateStmt->close();

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

        // Notify user
        // $notificationService->notifyUserReservationExpired($utenteId, $reservation['libro_id']); 
        // (Method needs to be added to NotificationService if not exists)

    } catch (Exception $e) {
        $db->rollback();
        echo "Error expiring reservation #{$id}: " . $e->getMessage() . "\n";
    }
}

$stmt->close();
$db->close();

echo "[" . date('Y-m-d H:i:s') . "] Completed. Expired {$expiredCount} reservations.\n";

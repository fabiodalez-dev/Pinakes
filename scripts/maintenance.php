<?php
declare(strict_types=1);

// Load environment variables from .env file
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

require_once __DIR__ . '/../vendor/autoload.php';

use App\Controllers\ReservationManager;

// Database configuration
$settings = require __DIR__ . '/../config/settings.php';
$cfg = $settings['db'];

// Connect to database
$socket = null;
if ($cfg['hostname'] === 'localhost') {
    // Try common socket locations for localhost
    $socketPaths = [
        '/tmp/mysql.sock',
        '/var/run/mysqld/mysqld.sock',
        '/usr/local/var/mysql/mysql.sock',
        '/opt/homebrew/var/mysql/mysql.sock'
    ];

    foreach ($socketPaths as $socketPath) {
        if (file_exists($socketPath)) {
            $socket = $socketPath;
            break;
        }
    }
}

$db = new mysqli(
    $cfg['hostname'],
    $cfg['username'],
    $cfg['password'],
    $cfg['database'],
    $cfg['port'],
    $socket
);

if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

$db->set_charset($cfg['charset']);

$reservationManager = new ReservationManager($db);

echo "=== RESERVATION MAINTENANCE STARTED ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Cancel expired reservations
echo "Cancelling expired reservations...\n";
$reservationManager->cancelExpiredReservations();
echo "✓ Expired reservations processed\n\n";

// Process available books for pending reservations
echo "Processing available books for reservations...\n";

// Get all books that might have pending reservations
$stmt = $db->prepare("
    SELECT DISTINCT libro_id
    FROM prenotazioni
    WHERE stato = 'attiva'
");
$stmt->execute();
$result = $stmt->get_result();

$processedBooks = 0;
while ($row = $result->fetch_assoc()) {
    $bookId = (int)$row['libro_id'];
    $inTransaction = false;
    try {
        // Wrap in transaction to ensure FOR UPDATE locks are effective
        $db->begin_transaction();
        $inTransaction = true;

        if ($reservationManager->processBookAvailability($bookId)) {
            echo "✓ Processed reservations for book ID: $bookId\n";
            $processedBooks++;
        }
        $db->commit();
        $inTransaction = false;
    } catch (\Throwable $e) {
        if ($inTransaction) {
            try {
                $db->rollback();
            } catch (\Throwable $rollbackError) {
                error_log("Maintenance: Rollback failed for book $bookId: " . $rollbackError->getMessage());
            }
        }
        error_log("Maintenance: Error processing book $bookId: " . $e->getMessage());
        echo "✗ Error processing book ID: $bookId - " . $e->getMessage() . "\n";
    }
}

echo "✓ Processed $processedBooks books with reservations\n\n";

// Clean up old completed/cancelled reservations (older than 30 days)
echo "Cleaning up old reservations...\n";
$stmt = $db->prepare("
    DELETE FROM prenotazioni
    WHERE stato IN ('completata', 'annullata')
    AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$stmt->execute();
$cleanedUp = $db->affected_rows;
echo "✓ Cleaned up $cleanedUp old reservations\n\n";

echo "=== MAINTENANCE COMPLETED ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";

$db->close();
<?php
declare(strict_types=1);

// ============================================================
// PROCESS LOCK - Prevent concurrent cron executions
// ============================================================
$lockFile = __DIR__ . '/../storage/cache/maintenance.lock';

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
    fwrite(STDERR, "INFO: Another maintenance process is already running. Exiting.\n");
    fclose($lockHandle);
    exit(0);
}

// Write PID to lock file for debugging
ftruncate($lockHandle, 0);
fwrite($lockHandle, (string)getmypid());
fflush($lockHandle);

// Register shutdown function to release lock
register_shutdown_function(function () use ($lockHandle, $lockFile) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    // Optionally remove lock file (commented out to avoid race with next execution)
    // @unlink($lockFile);
});

// ============================================================
// END PROCESS LOCK
// ============================================================

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
use App\Support\DataIntegrity;
use App\Support\RememberMeService;

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

// ============================================================
// DATABASE OPTIMIZATION - Index check (weekly)
// ============================================================
$dataIntegrity = new DataIntegrity($db);

// Check for missing indexes once a week (using marker file)
$indexCheckMarker = __DIR__ . '/../storage/cache/last_index_check.txt';
$lastIndexCheck = file_exists($indexCheckMarker) ? (int)file_get_contents($indexCheckMarker) : 0;
$oneWeekAgo = time() - 7 * 24 * 60 * 60;

if ($lastIndexCheck < $oneWeekAgo) {
    echo "Checking database indexes (weekly)...\n";

    $missingIndexes = $dataIntegrity->checkMissingIndexes();

    if (!empty($missingIndexes)) {
        echo "Found " . count($missingIndexes) . " missing indexes, creating...\n";
        $indexResult = $dataIntegrity->createMissingIndexes();

        if ($indexResult['created'] > 0) {
            echo "✓ Created {$indexResult['created']} indexes\n";
        }
        if (!empty($indexResult['errors'])) {
            foreach ($indexResult['errors'] as $error) {
                error_log("Maintenance: Index error: $error");
                echo "✗ $error\n";
            }
        }
    } else {
        echo "✓ All indexes present\n";
    }

    // Update marker
    if (file_put_contents($indexCheckMarker, (string)time()) === false) {
        error_log("Maintenance: Could not update index check marker");
    }
    echo "\n";
}

// ============================================================
// BOOK AVAILABILITY - Batch recalculation (daily, if needed)
// ============================================================
$availabilityMarker = __DIR__ . '/../storage/cache/last_availability_check.txt';
$lastAvailabilityCheck = file_exists($availabilityMarker) ? (int)file_get_contents($availabilityMarker) : 0;
$oneDayAgo = time() - 24 * 60 * 60;

if ($lastAvailabilityCheck < $oneDayAgo) {
    echo "Recalculating book availability (daily)...\n";

    // Count books to decide method
    $countResult = $db->query("SELECT COUNT(*) as total FROM libri");
    if (!$countResult) {
        error_log("Maintenance: Failed to count books: " . $db->error);
        echo "✗ Could not retrieve book count, skipping availability recalculation\n\n";
    } else {
        $totalBooks = (int)$countResult->fetch_assoc()['total'];

        if ($totalBooks > 5000) {
            // Use batched version for large catalogs
            echo "Using batched method for $totalBooks books...\n";
            $availResult = $dataIntegrity->recalculateAllBookAvailabilityBatched(500, function($processed, $total) {
                if ($processed % 1000 === 0 || $processed === $total) {
                    echo "  Progress: $processed / $total\n";
                }
            });
        } else {
            // Use standard method for smaller catalogs
            $availResult = $dataIntegrity->recalculateAllBookAvailability();
            $availResult['total'] = $totalBooks;
        }

        echo "✓ Updated {$availResult['updated']} / {$availResult['total']} books\n";

        if (!empty($availResult['errors'])) {
            echo "✗ Errors: " . count($availResult['errors']) . "\n";
            foreach (array_slice($availResult['errors'], 0, 5) as $error) {
                echo "  - $error\n";
            }
        }

        // Update marker
        if (file_put_contents($availabilityMarker, (string)time()) === false) {
            error_log("Maintenance: Could not update availability check marker");
        }
        echo "\n";
    }
}

// ============================================================
// USER SESSIONS - Clean up expired remember tokens (every run)
// ============================================================
echo "Cleaning up expired user sessions...\n";
try {
    $rememberMeService = new RememberMeService($db);
    $cleanedSessions = $rememberMeService->cleanupExpiredSessions();
    echo "✓ Cleaned up $cleanedSessions expired sessions\n\n";
} catch (\Throwable $e) {
    // Table might not exist yet (pre-0.4.0 installations)
    error_log("Maintenance: Session cleanup skipped: " . $e->getMessage());
    echo "✓ Session cleanup skipped (table may not exist yet)\n\n";
}

echo "=== MAINTENANCE COMPLETED ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";

$db->close();
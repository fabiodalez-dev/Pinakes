#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Cron script for bulk ISBN enrichment.
 *
 * Finds books with ISBN/EAN that are missing cover images or descriptions,
 * then scrapes the data using the existing scraping infrastructure.
 *
 * Usage:
 *   php scripts/bulk-enrich-cron.php
 *
 * Cron example (every 6 hours):
 *   0 0,6,12,18 * * * cd /path/to/biblioteca && php scripts/bulk-enrich-cron.php
 */

use App\Services\BulkEnrichmentService;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

// Check if running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

$projectRoot = realpath(__DIR__ . '/..');
$lockFile = $projectRoot . '/storage/tmp/bulk-enrich.lock';
$logFile = $projectRoot . '/storage/logs/bulk-enrich.log';

/**
 * Append a timestamped message to the log file.
 */
function logMessage(string $message, string $logFile): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

// Load environment
$dotenv = Dotenv::createImmutable($projectRoot);
$dotenv->load();

// Connect to DB
$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$name = $_ENV['DB_NAME'] ?? 'biblioteca';
$port = (int) ($_ENV['DB_PORT'] ?? 3306);

$db = new mysqli($host, $user, $pass, $name, $port);
if ($db->connect_error) {
    logMessage('ERROR: DB connection failed: ' . $db->connect_error, $logFile);
    exit(1);
}
$db->set_charset('utf8mb4');

$service = new BulkEnrichmentService($db);

// Check if bulk enrichment is enabled
if (!$service->isEnabled()) {
    logMessage('Bulk enrichment is disabled. Exiting.', $logFile);
    $db->close();
    exit(0);
}

// Acquire lock file
if (file_exists($lockFile)) {
    // Check if the lock is stale (older than 30 minutes)
    $lockAge = time() - (int) filemtime($lockFile);
    if ($lockAge < 1800) {
        logMessage('Another instance is running (lock file exists, age: ' . $lockAge . 's). Exiting.', $logFile);
        $db->close();
        exit(0);
    }
    logMessage('WARNING: Stale lock file detected (age: ' . $lockAge . 's). Removing and proceeding.', $logFile);
    unlink($lockFile);
}

// Create lock file
file_put_contents($lockFile, (string) getmypid());

try {
    logMessage('Starting bulk enrichment...', $logFile);

    $stats = $service->getStats();
    logMessage(sprintf(
        'Stats: %d books with ISBN, %d missing cover, %d missing description, %d pending',
        $stats['total_with_isbn'],
        $stats['missing_cover'],
        $stats['missing_description'],
        $stats['pending']
    ), $logFile);

    if ($stats['pending'] === 0) {
        logMessage('No books pending enrichment. Done.', $logFile);
    } else {
        $summary = $service->enrichBatch(20, function (int $current, int $total, array $result) use ($logFile): void {
            $fields = !empty($result['fields_updated']) ? implode(', ', $result['fields_updated']) : '-';
            logMessage(sprintf(
                '  [%d/%d] Book #%d: %s (fields: %s)',
                $current,
                $total,
                $result['book_id'],
                $result['status'],
                $fields
            ), $logFile);
        });

        logMessage(sprintf(
            'Completed: %d processed, %d enriched, %d not found, %d errors',
            $summary['processed'],
            $summary['enriched'],
            $summary['not_found'],
            $summary['errors']
        ), $logFile);
    }
} catch (\Throwable $e) {
    logMessage('FATAL ERROR: ' . $e->getMessage(), $logFile);
} finally {
    // Release lock
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
    $db->close();
}

exit(0);

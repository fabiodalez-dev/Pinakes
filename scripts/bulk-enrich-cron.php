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

// Connect to DB. Respect DB_SOCKET when set — on macOS Homebrew MySQL
// listens only on the unix socket by default, so building a mysqli with
// TCP host/port fails with ENOENT. Passing the 6th arg (socket) keeps
// the script portable across Linux (TCP) and macOS (socket) installs.
$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$name = $_ENV['DB_NAME'] ?? 'biblioteca';
$port = (int) ($_ENV['DB_PORT'] ?? 3306);
$sock = $_ENV['DB_SOCKET'] ?? null;

$db = new mysqli($host, $user, $pass, $name, $port, $sock ?: null);
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

// Acquire lock ATOMICALLY via flock(LOCK_EX | LOCK_NB). The previous
// file_exists() / file_put_contents() sequence was racy: two cron invocations
// could both observe "no lock" and both start a batch, doubling upstream API
// usage. flock on an open handle is an OS-level mutex.
$lockHandle = fopen($lockFile, 'c+');
if ($lockHandle === false) {
    logMessage('ERROR: cannot open lock file ' . $lockFile . '. Exiting.', $logFile);
    $db->close();
    exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    // Another instance owns the lock. Report PID if readable for diagnostics.
    rewind($lockHandle);
    $existingPid = trim((string) fread($lockHandle, 32));
    logMessage(
        'Another instance is running (PID: ' . ($existingPid !== '' ? $existingPid : 'unknown') . '). Exiting.',
        $logFile
    );
    fclose($lockHandle); // releases nothing (we don't hold it); just closes fd
    $db->close();
    exit(0);
}
// We hold the lock. Write our PID + keep the fd open until script end.
ftruncate($lockHandle, 0);
rewind($lockHandle);
fwrite($lockHandle, (string) getmypid());
fflush($lockHandle);

$exitCode = 0;
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
    // Non-zero exit so cron + monitoring record the run as FAILED, not success.
    logMessage('FATAL ERROR: ' . $e->getMessage(), $logFile);
    $exitCode = 1;
} finally {
    // Release flock + close fd. Keep the lock FILE on disk so the inode
    // stays stable: unlinking here would reintroduce a race where another
    // process could recreate the pathname with a new inode and bypass the
    // lock held by an earlier lingering fd. We truncate+rewind so a leftover
    // stale PID doesn't confuse the next run's diagnostics.
    if (is_resource($lockHandle)) {
        ftruncate($lockHandle, 0);
        rewind($lockHandle);
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    $db->close();
}

exit($exitCode);

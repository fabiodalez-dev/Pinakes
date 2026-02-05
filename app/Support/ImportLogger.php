<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Import Logger
 *
 * Tracks import progress and errors for CSV and LibraryThing imports
 * Persists data to database for historical analysis and error reporting
 */
class ImportLogger
{
    private \mysqli $db;
    private string $importId;
    private array $stats;
    private array $errors;
    private string $importType;
    private bool $completed = false;

    /**
     * Initialize a new import session
     *
     * @param \mysqli $db Database connection
     * @param string $importType 'csv' or 'librarything'
     * @param string $fileName Original uploaded filename
     * @param int|null $userId User who initiated the import
     */
    public function __construct(\mysqli $db, string $importType, string $fileName, ?int $userId = null)
    {
        $this->db = $db;
        $this->importType = $importType;
        $this->importId = bin2hex(random_bytes(18)); // Generates 36-char hex string (UUID-like)
        $this->stats = [
            'imported' => 0,
            'updated' => 0,
            'failed' => 0,
            'authors_created' => 0,
            'publishers_created' => 0,
            'scraped' => 0
        ];
        $this->errors = [];

        // Insert initial log record
        $stmt = $db->prepare("
            INSERT INTO import_logs (import_id, import_type, file_name, user_id, status)
            VALUES (?, ?, ?, ?, 'processing')
        ");
        if ($stmt === false) {
            error_log("[ImportLogger] Failed to prepare statement: " . $db->error);
            return;
        }

        $stmt->bind_param('sssi', $this->importId, $importType, $fileName, $userId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Increment a statistic counter
     *
     * @param string $key One of: imported, updated, failed, authors_created, publishers_created, scraped
     * @return void
     */
    public function incrementStat(string $key): void
    {
        if (!array_key_exists($key, $this->stats)) {
            error_log("[ImportLogger] Unknown stat key: {$key}");
            return;
        }

        $this->stats[$key]++;
    }

    /**
     * Add an error for a specific row
     *
     * @param int $lineNumber Line number in CSV (1-indexed)
     * @param string $title Book title (for identification)
     * @param string $message Error message
     * @param string $type Error type: validation, duplicate, database, scraping
     * @return void
     */
    public function addError(int $lineNumber, string $title, string $message, string $type = 'validation'): void
    {
        $this->errors[] = [
            'line' => $lineNumber,
            'title' => $title,
            'message' => $message,
            'type' => $type
        ];
        $this->stats['failed']++;
    }

    /**
     * Mark import as completed and persist final stats
     *
     * @param int $totalRows Total rows processed in CSV
     * @return void
     */
    public function complete(int $totalRows): void
    {
        if ($this->completed) {
            return; // Prevent double completion
        }

        $this->completed = true;
        $errorsJson = json_encode($this->errors, JSON_UNESCAPED_UNICODE);

        // Compress if errors JSON is too large (> 1MB)
        if (strlen($errorsJson) > 1024 * 1024) {
            error_log("[ImportLogger] Errors JSON too large, truncating to first 1000 errors");
            $truncatedErrors = array_slice($this->errors, 0, 1000);
            $truncatedErrors[] = [
                'line' => 0,
                'title' => 'TRUNCATED',
                'message' => 'Error list truncated. Total errors: ' . count($this->errors),
                'type' => 'system'
            ];
            $errorsJson = json_encode($truncatedErrors, JSON_UNESCAPED_UNICODE);
        }

        $stmt = $this->db->prepare("
            UPDATE import_logs
            SET total_rows = ?,
                imported = ?,
                updated = ?,
                failed = ?,
                authors_created = ?,
                publishers_created = ?,
                scraped = ?,
                errors_json = ?,
                completed_at = NOW(),
                status = 'completed'
            WHERE import_id = ?
        ");

        if ($stmt === false) {
            error_log("[ImportLogger] Failed to prepare complete statement: " . $this->db->error);
            return;
        }

        $stmt->bind_param(
            'iiiiiiiss',
            $totalRows,
            $this->stats['imported'],
            $this->stats['updated'],
            $this->stats['failed'],
            $this->stats['authors_created'],
            $this->stats['publishers_created'],
            $this->stats['scraped'],
            $errorsJson,
            $this->importId
        );
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Mark import as failed (for critical errors that stop the entire import)
     *
     * @param string $errorMessage Fatal error message
     * @return void
     */
    public function fail(string $errorMessage): void
    {
        if ($this->completed) {
            return;
        }

        $this->completed = true;
        $this->addError(0, 'FATAL', $errorMessage, 'system');
        $errorsJson = json_encode($this->errors, JSON_UNESCAPED_UNICODE);

        $stmt = $this->db->prepare("
            UPDATE import_logs
            SET failed = ?,
                errors_json = ?,
                completed_at = NOW(),
                status = 'failed'
            WHERE import_id = ?
        ");

        if ($stmt === false) {
            error_log("[ImportLogger] Failed to prepare fail statement: " . $this->db->error);
            return;
        }

        $failed = $this->stats['failed'];
        $stmt->bind_param('iss', $failed, $errorsJson, $this->importId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get the unique import ID for this session
     *
     * @return string Import ID (36 chars hex)
     */
    public function getImportId(): string
    {
        return $this->importId;
    }

    /**
     * Get current statistics
     *
     * @return array Statistics array
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Get all errors recorded so far
     *
     * @return array Errors array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}

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
            throw new \RuntimeException("[ImportLogger] Failed to initialize: " . $db->error);
        }

        $stmt->bind_param('sssi', $this->importId, $importType, $fileName, $userId);

        if (!$stmt->execute()) {
            $error = $stmt->error ?: $db->error;
            $stmt->close();
            error_log("[ImportLogger] Failed to execute INSERT: " . $error);
            throw new \RuntimeException("[ImportLogger] Failed to create import log: " . $error);
        }

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
     * Set multiple statistics at once (more efficient than multiple incrementStat calls)
     *
     * @param array<string, int> $stats Statistics to set
     * @return void
     */
    public function setStats(array $stats): void
    {
        foreach ($stats as $key => $value) {
            if (array_key_exists($key, $this->stats)) {
                $this->stats[$key] = (int)$value;
            } else {
                error_log("[ImportLogger] Unknown stat key in setStats: {$key}");
            }
        }
    }

    /**
     * Add an error for a specific row
     *
     * @param int $lineNumber Line number in CSV (1-indexed)
     * @param string $title Book title (for identification)
     * @param string $message Error message
     * @param string $type Error type: validation, duplicate, database, scraping
     * @param bool $incrementFailed Whether to increment the failed counter (false if already counted)
     * @return void
     */
    public function addError(int $lineNumber, string $title, string $message, string $type = 'validation', bool $incrementFailed = false): void
    {
        $this->errors[] = [
            'line' => $lineNumber,
            'title' => $title,
            'message' => $message,
            'type' => $type
        ];
        if ($incrementFailed) {
            $this->stats['failed']++;
        }
    }

    /**
     * Mark import as completed and persist final stats
     *
     * @param int $totalRows Total rows processed in CSV
     * @return bool True if persisted successfully, false on failure
     */
    public function complete(int $totalRows): bool
    {
        if ($this->completed) {
            return true; // Already completed
        }

        $errorsJson = json_encode($this->errors, JSON_UNESCAPED_UNICODE);
        if ($errorsJson === false) {
            error_log("[ImportLogger] Failed to encode errors JSON: " . json_last_error_msg());
            $errorsJson = '[]';
        }

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
            $errorsJson = json_encode($truncatedErrors, JSON_UNESCAPED_UNICODE) ?: '[]';
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
            return false;
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

        if (!$stmt->execute()) {
            error_log("[ImportLogger] Failed to execute complete: " . ($stmt->error ?: $this->db->error));
            $stmt->close();
            return false;
        }

        $stmt->close();
        $this->completed = true;
        return true;
    }

    /**
     * Mark import as failed (for critical errors that stop the entire import)
     *
     * @param string $errorMessage Fatal error message
     * @return bool True if persisted successfully, false on failure
     */
    public function fail(string $errorMessage): bool
    {
        if ($this->completed) {
            return true; // Already completed
        }

        // Add fatal error and increment failed counter
        $this->addError(0, 'FATAL', $errorMessage, 'system', true);
        $errorsJson = json_encode($this->errors, JSON_UNESCAPED_UNICODE);
        if ($errorsJson === false) {
            error_log("[ImportLogger] Failed to encode errors JSON in fail(): " . json_last_error_msg());
            $errorsJson = json_encode([['line' => 0, 'title' => 'FATAL', 'message' => $errorMessage, 'type' => 'system']], JSON_UNESCAPED_UNICODE) ?: '[]';
        }

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
            return false;
        }

        $failed = $this->stats['failed'];
        $stmt->bind_param('iss', $failed, $errorsJson, $this->importId);

        if (!$stmt->execute()) {
            error_log("[ImportLogger] Failed to execute fail: " . ($stmt->error ?: $this->db->error));
            $stmt->close();
            return false;
        }

        $stmt->close();
        $this->completed = true;
        return true;
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

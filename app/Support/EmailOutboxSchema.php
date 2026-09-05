<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Runtime-compatible schema for durable circulation emails.
 *
 * The installer migration is authoritative. This helper lets mixed-version
 * deployments start persisting failed terminal notifications immediately.
 * Call it only outside circulation transactions because CREATE TABLE may
 * implicitly commit on MySQL/MariaDB.
 */
final class EmailOutboxSchema
{
    /** @var array<int, bool> connection-local availability cache */
    private static array $availability = [];

    public static function ensure(\mysqli $db): bool
    {
        $connectionId = spl_object_id($db);
        if (array_key_exists($connectionId, self::$availability)) {
            return self::$availability[$connectionId];
        }

        try {
            $exists = $db->query("
                SELECT 1
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                  AND table_name = 'email_delivery_outbox'
                LIMIT 1
            ");
            if ($exists && $exists->num_rows > 0) {
                return self::$availability[$connectionId] = true;
            }

            // Never let compatibility DDL implicitly commit a circulation
            // transaction. Pre-migration callers simply send best-effort; the
            // installer/updater creates the table authoritatively.
            // (@@session.in_transaction is MariaDB-only: on MySQL it throws
            // "Unknown system variable" and would poison the availability
            // cache — use the portable autocommit + savepoint probe instead.)
            if (self::hasActiveTransaction($db)) {
                SecureLogger::warning('Email outbox creation deferred until outside the active transaction');
                return false;
            }

            $db->query("
                CREATE TABLE IF NOT EXISTS email_delivery_outbox (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    recipient_email VARCHAR(255) NOT NULL,
                    template_name VARCHAR(100) NOT NULL,
                    variables_json LONGTEXT NOT NULL,
                    attempts INT UNSIGNED NOT NULL DEFAULT 0,
                    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    claim_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
                    claimed_at DATETIME DEFAULT NULL,
                    last_error TEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_email_outbox_due (available_at, claim_token),
                    KEY idx_email_outbox_claimed (claimed_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            self::$availability[$connectionId] = true;
        } catch (\Throwable $e) {
            self::$availability[$connectionId] = false;
            SecureLogger::warning('Email outbox schema unavailable', ['error' => $e->getMessage()]);
        }

        return self::$availability[$connectionId];
    }

    /**
     * Portable active-transaction probe (MySQL + MariaDB): detects both
     * autocommit(false) and an explicit begin_transaction() via a disposable
     * uniquely-named savepoint — same pattern as GenereRepository.
     */
    private static function hasActiveTransaction(\mysqli $db): bool
    {
        $result = $db->query('SELECT @@autocommit AS ac');
        if ($result instanceof \mysqli_result) {
            $row = $result->fetch_assoc();
            $result->free();
            if ((int) ($row['ac'] ?? 1) === 0) {
                return true;
            }
        }

        $probe = 'pinakes_outbox_probe_' . bin2hex(random_bytes(6));
        $probeCreated = false;
        try {
            if (!$db->query("SAVEPOINT {$probe}")) {
                return false;
            }
            $probeCreated = true;
            if (!$db->query("ROLLBACK TO SAVEPOINT {$probe}")) {
                return false;
            }
            return true;
        } catch (\mysqli_sql_exception) {
            return false;
        } finally {
            if ($probeCreated) {
                try {
                    $db->query("RELEASE SAVEPOINT {$probe}");
                } catch (\mysqli_sql_exception) {
                    // The caller still owns its transaction; a failed cleanup
                    // of the disposable probe savepoint is harmless.
                }
            }
        }
    }
}

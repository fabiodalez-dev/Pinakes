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
            $transaction = $db->query('SELECT @@session.in_transaction AS active')->fetch_assoc();
            if ((int) ($transaction['active'] ?? 0) === 1) {
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
}

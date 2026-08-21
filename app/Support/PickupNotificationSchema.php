<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Runtime compatibility for the pickup-ready notification claim schema.
 *
 * Migration 0.7.64 is authoritative. This helper keeps web/cron requests
 * functional during the short pre-migration upgrade window without ever
 * running DDL inside a circulation transaction. Existing rows are initialized
 * as already announced by the ADD COLUMN default itself, then the default is
 * changed to 0 for future rows. This avoids UPDATEing prestiti while legacy
 * circulation triggers are still installed (those triggers can reject an
 * otherwise unrelated notification backfill).
 */
final class PickupNotificationSchema
{
    private const MARKER_CATEGORY = 'migrations';
    private const MARKER_KEY = 'pickup_notification_backfill_0_7_64';
    private const CLAIM_LEASE_SECONDS = 900;

    /**
     * Claim timestamps are stored as UTC wall-clock values. The column is
     * protocol-only (never displayed), so UTC avoids a one-hour lease jump at
     * daylight-saving transitions in the application's local timezone.
     *
     * @return array{attemptedAt:string, staleBefore:string}
     */
    public static function claimLeaseWindow(): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return [
            'attemptedAt' => $now->format('Y-m-d H:i:s'),
            'staleBefore' => $now->modify('-' . self::CLAIM_LEASE_SECONDS . ' seconds')->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * A missing timestamp on a non-empty token fails closed. Normal claims
     * always persist both values atomically, so this branch only protects a
     * malformed/partially migrated row from being reassigned mid-delivery.
     */
    public static function isClaimLive(?string $token, ?string $attemptedAt, ?string $staleBefore = null): bool
    {
        if ($token === null || $token === '') {
            return false;
        }
        if ($attemptedAt === null || $attemptedAt === '') {
            return true;
        }

        $staleBefore ??= self::claimLeaseWindow()['staleBefore'];
        return $attemptedAt >= $staleBefore;
    }

    /**
     * Ensure all columns used by the claim/retry protocol are available.
     * Must be called before begin_transaction() by mutation paths.
     */
    public static function ensure(\mysqli $db): bool
    {
        try {
            $columnNames = self::columnNames($db);
            $marker = self::markerValue($db);

            if ($marker === null) {
                // If the original sent flag already exists, its installer or a
                // prior self-heal already chose the historical cohort. Add
                // newer protocol columns without reclassifying legitimate
                // sent=0 retry rows.
                $markerValue = 'done';
                if (!isset($columnNames['pickup_notification_sent'])) {
                    $markerValue = 'pending';
                }
                self::insertMarkerIfAbsent($db, $markerValue);
                $marker = self::markerValue($db);
                if ($marker === null) {
                    throw new \RuntimeException('pickup notification backfill marker was not persisted');
                }
            }

            $legacyDefaultPending = $marker === 'pending' || preg_match('/^pending:\d+$/D', $marker) === 1;
            self::addMissingColumns($db, $columnNames, $legacyDefaultPending);

            if ($legacyDefaultPending) {
                // ADD DEFAULT 1 materializes the safe historical value without
                // firing UPDATE triggers. Flip only the default for rows
                // created after the upgrade; existing values remain 1.
                $db->query(
                    "ALTER TABLE prestiti
                     MODIFY COLUMN pickup_notification_sent TINYINT(1) DEFAULT 0
                     COMMENT 'claim/retry flag for the ready-for-pickup email'"
                );

                $finish = $db->prepare(
                    "UPDATE system_settings
                        SET setting_value = 'done'
                      WHERE category = ? AND setting_key = ? AND setting_value = ?"
                );
                $category = self::MARKER_CATEGORY;
                $key = self::MARKER_KEY;
                $finish->bind_param('sss', $category, $key, $marker);
                $finish->execute();
                $finish->close();
            }

            return self::allColumnsExist(self::columnNames($db));
        } catch (\Throwable $e) {
            SecureLogger::error('Failed to ensure pickup notification schema: ' . $e->getMessage());
            return false;
        }
    }

    /** @return array<string, true> */
    private static function columnNames(\mysqli $db): array
    {
        $result = $db->query(
            "SELECT COLUMN_NAME
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'prestiti'
                AND COLUMN_NAME IN (
                    'pickup_notification_sent',
                    'pickup_notification_claim_token',
                    'pickup_notification_last_attempt_at'
                )"
        );
        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[(string) $row['COLUMN_NAME']] = true;
        }
        $result->free();
        return $columns;
    }

    /** @param array<string, true> $columns */
    private static function allColumnsExist(array $columns): bool
    {
        return isset(
            $columns['pickup_notification_sent'],
            $columns['pickup_notification_claim_token'],
            $columns['pickup_notification_last_attempt_at']
        );
    }

    /** @param array<string, true> $columns */
    private static function addMissingColumns(\mysqli $db, array $columns, bool $legacyDefaultPending): void
    {
        if (!isset($columns['pickup_notification_sent'])) {
            $historicalDefault = $legacyDefaultPending ? 1 : 0;
            $db->query(
                "ALTER TABLE prestiti
                 ADD COLUMN pickup_notification_sent TINYINT(1) DEFAULT {$historicalDefault}
                 COMMENT 'claim/retry flag for the ready-for-pickup email'"
            );
        }
        if (!isset($columns['pickup_notification_claim_token'])) {
            $db->query('ALTER TABLE prestiti ADD COLUMN pickup_notification_claim_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL');
        }
        if (!isset($columns['pickup_notification_last_attempt_at'])) {
            $db->query('ALTER TABLE prestiti ADD COLUMN pickup_notification_last_attempt_at DATETIME NULL DEFAULT NULL');
        }
    }

    private static function markerValue(\mysqli $db): ?string
    {
        $stmt = $db->prepare(
            'SELECT setting_value FROM system_settings WHERE category = ? AND setting_key = ? LIMIT 1'
        );
        $category = self::MARKER_CATEGORY;
        $key = self::MARKER_KEY;
        $stmt->bind_param('ss', $category, $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row === null ? null : (string) $row['setting_value'];
    }

    private static function insertMarkerIfAbsent(\mysqli $db, string $value): void
    {
        $stmt = $db->prepare(
            "INSERT IGNORE INTO system_settings
                (category, setting_key, setting_value, description)
             VALUES (?, ?, ?, 'Resumable state for the 0.7.64 pickup notification schema')"
        );
        $category = self::MARKER_CATEGORY;
        $key = self::MARKER_KEY;
        $stmt->bind_param('sss', $category, $key, $value);
        $stmt->execute();
        $stmt->close();
    }
}

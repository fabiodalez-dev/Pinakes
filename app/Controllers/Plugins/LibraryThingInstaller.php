<?php
declare(strict_types=1);

namespace App\Controllers\Plugins;

/**
 * LibraryThing Plugin Installer
 *
 * Handles installation and uninstallation of the LibraryThing plugin,
 * including database schema modifications.
 */
class LibraryThingInstaller
{
    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Check if plugin is installed
     */
    public static function isInstalled(\mysqli $db): bool
    {
        // Check if plugin-specific columns exist
        $result = $db->query("SHOW COLUMNS FROM libri LIKE 'review'");
        return $result && $result->num_rows > 0;
    }

    /**
     * Install plugin - create all LibraryThing fields
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function install(): array
    {
        if (self::isInstalled($this->db)) {
            return ['success' => false, 'message' => __('Plugin già installato')];
        }

        try {
            $this->db->begin_transaction();

            // Add review and rating fields
            $this->db->query("
                ALTER TABLE libri
                    ADD COLUMN review TEXT NULL COMMENT 'Book review (LibraryThing)' AFTER descrizione,
                    ADD COLUMN rating TINYINT UNSIGNED NULL COMMENT 'Rating 1-5 (LibraryThing)' AFTER review,
                    ADD COLUMN comment TEXT NULL COMMENT 'Public comment (LibraryThing)' AFTER rating,
                    ADD COLUMN private_comment TEXT NULL COMMENT 'Private comment (LibraryThing)' AFTER comment
            ");

            // Add physical description fields
            $this->db->query("
                ALTER TABLE libri
                    ADD COLUMN physical_description VARCHAR(255) NULL COMMENT 'Physical description (LibraryThing)' AFTER dimensioni,
                    ADD COLUMN weight VARCHAR(50) NULL COMMENT 'Weight (LibraryThing)' AFTER physical_description,
                    ADD COLUMN height VARCHAR(50) NULL COMMENT 'Height (LibraryThing)' AFTER weight,
                    ADD COLUMN thickness VARCHAR(50) NULL COMMENT 'Thickness (LibraryThing)' AFTER height,
                    ADD COLUMN length VARCHAR(50) NULL COMMENT 'Length (LibraryThing)' AFTER thickness
            ");

            // Add library classification fields
            $this->db->query("
                ALTER TABLE libri
                    ADD COLUMN lccn VARCHAR(50) NULL COMMENT 'Library of Congress Control Number (LibraryThing)' AFTER classificazione_dewey,
                    ADD COLUMN lc_classification VARCHAR(100) NULL COMMENT 'LC Classification (LibraryThing)' AFTER lccn,
                    ADD COLUMN other_call_number VARCHAR(100) NULL COMMENT 'Other call number (LibraryThing)' AFTER lc_classification
            ");

            // Add date tracking fields
            $this->db->query("
                ALTER TABLE libri
                    ADD COLUMN date_acquired DATE NULL COMMENT 'Date acquired (LibraryThing)' AFTER data_acquisizione,
                    ADD COLUMN date_started DATE NULL COMMENT 'Date started reading (LibraryThing)' AFTER date_acquired,
                    ADD COLUMN date_read DATE NULL COMMENT 'Date finished reading (LibraryThing)' AFTER date_started
            ");

            // Add catalog identifiers
            $this->db->query("
                ALTER TABLE libri
                    ADD COLUMN bcid VARCHAR(50) NULL COMMENT 'BCID (LibraryThing)' AFTER ean,
                    ADD COLUMN oclc VARCHAR(50) NULL COMMENT 'OCLC number (LibraryThing)' AFTER bcid,
                    ADD COLUMN work_id VARCHAR(50) NULL COMMENT 'LibraryThing Work ID' AFTER oclc,
                    ADD COLUMN issn VARCHAR(20) NULL COMMENT 'ISSN for periodicals (LibraryThing)' AFTER isbn13
            ");

            // Add language fields
            $this->db->query("
                ALTER TABLE libri
                    ADD COLUMN original_languages VARCHAR(255) NULL COMMENT 'Original languages (LibraryThing)' AFTER lingua
            ");

            // Add acquisition fields
            $this->db->query("
                ALTER TABLE libri
                    ADD COLUMN source VARCHAR(255) NULL COMMENT 'Source/vendor (LibraryThing)' AFTER editore_id,
                    ADD COLUMN from_where VARCHAR(255) NULL COMMENT 'From where acquired (LibraryThing)' AFTER source
            ");

            // Add lending tracking fields
            $this->db->query("
                ALTER TABLE libri
                    ADD COLUMN lending_patron VARCHAR(255) NULL COMMENT 'Current lending patron (LibraryThing)' AFTER from_where,
                    ADD COLUMN lending_status VARCHAR(50) NULL COMMENT 'Lending status (LibraryThing)' AFTER lending_patron,
                    ADD COLUMN lending_start DATE NULL COMMENT 'Lending start date (LibraryThing)' AFTER lending_status,
                    ADD COLUMN lending_end DATE NULL COMMENT 'Lending end date (LibraryThing)' AFTER lending_start
            ");

            // Add financial fields
            $this->db->query("
                ALTER TABLE libri
                    ADD COLUMN purchase_price DECIMAL(10,2) NULL COMMENT 'Purchase price (LibraryThing)' AFTER prezzo,
                    ADD COLUMN value DECIMAL(10,2) NULL COMMENT 'Current value (LibraryThing)' AFTER purchase_price,
                    ADD COLUMN condition_lt VARCHAR(100) NULL COMMENT 'Physical condition (LibraryThing)' AFTER value
            ");

            // Add indexes for commonly queried fields
            $this->db->query("
                ALTER TABLE libri
                    ADD INDEX idx_lt_rating (rating),
                    ADD INDEX idx_lt_date_acquired (date_acquired),
                    ADD INDEX idx_lt_date_read (date_read),
                    ADD INDEX idx_lt_lending_status (lending_status),
                    ADD INDEX idx_lt_lccn (lccn),
                    ADD INDEX idx_lt_oclc (oclc),
                    ADD INDEX idx_lt_work_id (work_id),
                    ADD INDEX idx_lt_issn (issn)
            ");

            // Add check constraint for rating (1-5 or NULL)
            $this->db->query("
                ALTER TABLE libri
                    ADD CONSTRAINT chk_lt_rating CHECK (rating IS NULL OR (rating >= 1 AND rating <= 5))
            ");

            $this->db->commit();

            return ['success' => true, 'message' => __('Plugin LibraryThing installato con successo')];

        } catch (\Exception $e) {
            $this->db->rollback();
            error_log('[LibraryThing Installer] Installation failed: ' . $e->getMessage());
            return ['success' => false, 'message' => __('Errore durante l\'installazione: ') . $e->getMessage()];
        }
    }

    /**
     * Uninstall plugin - remove all LibraryThing fields
     *
     * WARNING: This will delete all data in these fields!
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function uninstall(): array
    {
        if (!self::isInstalled($this->db)) {
            return ['success' => false, 'message' => __('Plugin non installato')];
        }

        try {
            $this->db->begin_transaction();

            // Remove check constraint
            $this->db->query("ALTER TABLE libri DROP CONSTRAINT IF EXISTS chk_lt_rating");

            // Remove indexes
            $this->db->query("
                ALTER TABLE libri
                    DROP INDEX IF EXISTS idx_lt_rating,
                    DROP INDEX IF EXISTS idx_lt_date_acquired,
                    DROP INDEX IF EXISTS idx_lt_date_read,
                    DROP INDEX IF EXISTS idx_lt_lending_status,
                    DROP INDEX IF EXISTS idx_lt_lccn,
                    DROP INDEX IF EXISTS idx_lt_oclc,
                    DROP INDEX IF EXISTS idx_lt_work_id,
                    DROP INDEX IF EXISTS idx_lt_issn
            ");

            // Remove all LibraryThing fields
            $this->db->query("
                ALTER TABLE libri
                    DROP COLUMN IF EXISTS review,
                    DROP COLUMN IF EXISTS rating,
                    DROP COLUMN IF EXISTS comment,
                    DROP COLUMN IF EXISTS private_comment,
                    DROP COLUMN IF EXISTS physical_description,
                    DROP COLUMN IF EXISTS weight,
                    DROP COLUMN IF EXISTS height,
                    DROP COLUMN IF EXISTS thickness,
                    DROP COLUMN IF EXISTS length,
                    DROP COLUMN IF EXISTS lccn,
                    DROP COLUMN IF EXISTS lc_classification,
                    DROP COLUMN IF EXISTS other_call_number,
                    DROP COLUMN IF EXISTS date_acquired,
                    DROP COLUMN IF EXISTS date_started,
                    DROP COLUMN IF EXISTS date_read,
                    DROP COLUMN IF EXISTS bcid,
                    DROP COLUMN IF EXISTS oclc,
                    DROP COLUMN IF EXISTS work_id,
                    DROP COLUMN IF EXISTS issn,
                    DROP COLUMN IF EXISTS original_languages,
                    DROP COLUMN IF EXISTS source,
                    DROP COLUMN IF EXISTS from_where,
                    DROP COLUMN IF EXISTS lending_patron,
                    DROP COLUMN IF EXISTS lending_status,
                    DROP COLUMN IF EXISTS lending_start,
                    DROP COLUMN IF EXISTS lending_end,
                    DROP COLUMN IF EXISTS purchase_price,
                    DROP COLUMN IF EXISTS value,
                    DROP COLUMN IF EXISTS condition_lt
            ");

            $this->db->commit();

            return ['success' => true, 'message' => __('Plugin LibraryThing disinstallato con successo')];

        } catch (\Exception $e) {
            $this->db->rollback();
            error_log('[LibraryThing Installer] Uninstallation failed: ' . $e->getMessage());
            return ['success' => false, 'message' => __('Errore durante la disinstallazione: ') . $e->getMessage()];
        }
    }

    /**
     * Get plugin status information
     *
     * @return array Status information
     */
    public function getStatus(): array
    {
        $installed = self::isInstalled($this->db);

        $fieldsCount = 0;
        if ($installed) {
            // Count LibraryThing-specific fields
            $result = $this->db->query("
                SELECT COUNT(*) as count
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'libri'
                AND COLUMN_NAME IN (
                    'review', 'rating', 'comment', 'private_comment',
                    'physical_description', 'weight', 'height', 'thickness', 'length',
                    'lccn', 'lc_classification', 'other_call_number',
                    'date_acquired', 'date_started', 'date_read',
                    'bcid', 'oclc', 'work_id', 'issn',
                    'original_languages', 'source', 'from_where',
                    'lending_patron', 'lending_status', 'lending_start', 'lending_end',
                    'purchase_price', 'value', 'condition_lt'
                )
            ");

            if ($result) {
                $row = $result->fetch_assoc();
                $fieldsCount = (int) $row['count'];
            }
        }

        return [
            'installed' => $installed,
            'fields_count' => $fieldsCount,
            'expected_fields' => 29,
            'complete' => $fieldsCount === 29
        ];
    }
}

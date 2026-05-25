-- Pinakes v0.7.9 — RiC-CM Phase 3: Activities as RiC-CM entities
--
-- Issue #122 — Records in Contexts Conceptual Model (ICA 2023).
-- Phase 3 of the 6-phase roadmap.
--
-- What Phase 3 adds
-- =================
-- 1. New table `archive_activities` — first-class Activity entity
--    corresponding to ISDF (International Standard for Describing
--    Functions). Captures any human / organisational activity that
--    produced, used, or managed archival material. Self-referential
--    parent_id supports the ISDF function → activity → transaction
--    hierarchy. An Activity can optionally point at the responsible
--    agent (`agent_id` FK to authority_records) — that's the "agent
--    that performs the activity" relation in RiC-O terms.
--
-- 2. New link table `archive_unit_activities` — M:N between archival
--    units and activities, carrying a RiC-O predicate that describes
--    the nature of the link:
--      ric:resultsOrResultedFrom — the unit was produced by the activity
--      ric:isOrWasUsedBy         — the unit was used during the activity
--      ric:isSubjectOf           — the activity is about this unit
--    The predicate is VARCHAR (open vocabulary) rather than ENUM so
--    new RiC-O predicates can be added without a migration; the
--    admin form layer validates against a known list.
--
-- Why a dedicated Activity table (not free-text on archival_units)
-- ================================================================
-- - Activities are reused across many units (a Prefettura's
--   "corrispondenza diplomatica" produces dozens of files). A free-
--   text field would duplicate the activity description per row,
--   loses queryability ("show me everything produced by activity X"),
--   and makes consolidation expensive on cleanup.
-- - Activities have their own lifecycle (date_start / date_end,
--   is_ongoing) that doesn't fit on individual units.
-- - RiC-O's `ric:Activity` class has a well-defined predicate set
--   (`ric:performsOrPerformed`, `ric:resultsOrResultedIn`); a
--   dedicated table makes the Linked Data emission natural.

-- Guard: when upgrading from a release that PRE-DATES the archives
-- plugin, archival_units + authority_records do not exist yet. The
-- plugin's ensureSchema() will create archive_activities + the link
-- table after first activation. Skip when the base tables are absent.
SET @archives_present = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN ('archival_units', 'authority_records')
);

SET @sql = IF(@archives_present = 2,
    "CREATE TABLE IF NOT EXISTS archive_activities (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        title         VARCHAR(500) NOT NULL,
        description   TEXT NULL,
        activity_type ENUM('function','activity','transaction','task','mandate') NOT NULL DEFAULT 'activity',
        parent_id     BIGINT UNSIGNED NULL,
        date_start    VARCHAR(20) NULL,
        date_end      VARCHAR(20) NULL,
        is_ongoing    TINYINT(1) NOT NULL DEFAULT 0,
        agent_id      BIGINT UNSIGNED NULL,
        place_id      BIGINT UNSIGNED NULL,
        source_ref    VARCHAR(500) NULL,
        created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at    TIMESTAMP NULL,
        PRIMARY KEY (id),
        KEY idx_parent (parent_id),
        KEY idx_agent (agent_id),
        KEY idx_type (activity_type),
        KEY idx_deleted (deleted_at),
        FULLTEXT KEY ft_activity_search (title, description),
        CONSTRAINT fk_activity_parent FOREIGN KEY (parent_id) REFERENCES archive_activities(id) ON DELETE SET NULL,
        CONSTRAINT fk_activity_agent  FOREIGN KEY (agent_id)  REFERENCES authority_records(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'SELECT 1');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql = IF(@archives_present = 2,
    "CREATE TABLE IF NOT EXISTS archive_unit_activities (
        id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        unit_id        BIGINT UNSIGNED NOT NULL,
        activity_id    BIGINT UNSIGNED NOT NULL,
        ric_predicate  VARCHAR(128) NOT NULL DEFAULT 'ric:resultsOrResultedFrom',
        created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_unit_activity_predicate (unit_id, activity_id, ric_predicate),
        KEY idx_unit (unit_id),
        KEY idx_activity (activity_id),
        KEY idx_predicate (ric_predicate),
        CONSTRAINT fk_ua_unit     FOREIGN KEY (unit_id)     REFERENCES archival_units(id)     ON DELETE CASCADE,
        CONSTRAINT fk_ua_activity FOREIGN KEY (activity_id) REFERENCES archive_activities(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'SELECT 1');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SELECT 'RiC-CM Phase 3 schema applied (archive_activities + archive_unit_activities)' AS migration_note;

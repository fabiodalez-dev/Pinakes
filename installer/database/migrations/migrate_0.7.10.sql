-- Pinakes v0.7.10 — RiC-CM Phase 4: Places + polymorphic Relations graph
--
-- Issue #122 — Records in Contexts Conceptual Model (ICA 2023).
-- Phase 4 of the 6-phase roadmap. With Phases 1-3 we modelled three of
-- the five RiC-CM entity types (Record/RecordSet, Agent, Activity).
-- Phase 4 introduces the fourth — Place — and the generic Relations
-- backbone that lets any pair of entities carry a typed RiC-O predicate.
--
-- What Phase 4 adds
-- =================
-- 1. `archive_places` — first-class Place entity (RiC-CM §3.5). Models
--    geographic locations (countries, regions, municipalities,
--    buildings, rooms, geographic features) with optional GeoNames /
--    Wikidata / Getty TGN identifiers. Self-referential parent_id
--    captures the hierarchy ("Catania" → "Sicily" → "Italy"). When
--    populated, archive_activities.place_id, archive_agent_relations.*
--    (Phase 5 use), and archival_units.* gain a real FK target;
--    Phase 1-3 emitted Place nodes inline because the table didn't
--    yet exist.
--
-- 2. `archive_relations` — polymorphic relations table. This is the
--    GENERAL N:M relation backbone of RiC-CM. Any pair of entities
--    (archival_unit / authority_record / archive_activity /
--    archive_place) can carry a typed RiC-O predicate. The existing
--    purpose-built link tables (archival_unit_authority,
--    archive_unit_activities, archive_agent_relations) remain for
--    fast queries on common patterns; archive_relations covers the
--    long-tail of RiC-O predicates that don't fit the existing
--    tables (Record ↔ Place "isOrWasLocatedAt", Activity ↔ Place
--    "isOrWasPerformedAt", Agent ↔ Place "isOrWasResidentAt",
--    Record ↔ Record "isOrWasIncludedIn" / "isOrWasRelatedTo", etc.).
--    Source and target are polymorphic (entity_type + entity_id)
--    rather than separate FK columns; the application validator
--    (validateRelationEndpoints in ArchivesPlugin) enforces
--    referential integrity.
--
-- Why a polymorphic relations table vs N specialised link tables
-- ===============================================================
-- RiC-O has dozens of agent/activity/record/place predicates. Adding
-- one link table per (sourceType, targetType, predicate) triple would
-- explode the schema. Polymorphic source/target keeps the schema
-- compact while the application layer enforces the integrity that
-- the schema can't (MySQL can't FK to "one of two tables").

-- Guard: skip when archives plugin not yet activated (base tables
-- archival_units / authority_records absent → plugin's ensureSchema()
-- creates everything Phase 4 needs on first onActivate).
SET @archives_present = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN ('archival_units', 'authority_records')
);

SET @sql = IF(@archives_present = 2,
    "CREATE TABLE IF NOT EXISTS archive_places (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name         VARCHAR(500) NOT NULL,
        place_type   ENUM('country','region','province','municipality','locality','building','room','geographic_feature','other') NOT NULL DEFAULT 'locality',
        parent_id    BIGINT UNSIGNED NULL,
        latitude     DECIMAL(10,7) NULL,
        longitude    DECIMAL(10,7) NULL,
        geonames_id  VARCHAR(20)   NULL,
        wikidata_id  VARCHAR(20)   NULL,
        tgn_id       VARCHAR(20)   NULL,
        description  TEXT          NULL,
        date_start   VARCHAR(20)   NULL,
        date_end     VARCHAR(20)   NULL,
        created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at   TIMESTAMP NULL,
        PRIMARY KEY (id),
        KEY idx_parent (parent_id),
        KEY idx_place_type (place_type),
        KEY idx_geonames (geonames_id),
        KEY idx_wikidata (wikidata_id),
        KEY idx_deleted (deleted_at),
        FULLTEXT KEY ft_place_search (name, description),
        CONSTRAINT fk_place_parent FOREIGN KEY (parent_id) REFERENCES archive_places(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'SELECT 1');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @sql = IF(@archives_present = 2,
    "CREATE TABLE IF NOT EXISTS archive_relations (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        source_type   ENUM('archival_unit','authority_record','archive_activity','archive_place') NOT NULL,
        source_id     BIGINT UNSIGNED NOT NULL,
        target_type   ENUM('archival_unit','authority_record','archive_activity','archive_place') NOT NULL,
        target_id     BIGINT UNSIGNED NOT NULL,
        ric_predicate VARCHAR(128) NOT NULL,
        qualifier     VARCHAR(500) NULL,
        certainty     ENUM('certain','probable','uncertain') NOT NULL DEFAULT 'certain',
        date_start    VARCHAR(20)  NULL,
        date_end      VARCHAR(20)  NULL,
        source_ref    VARCHAR(500) NULL,
        created_by    BIGINT UNSIGNED NULL,
        created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_relation (source_type, source_id, target_type, target_id, ric_predicate),
        KEY idx_source (source_type, source_id),
        KEY idx_target (target_type, target_id),
        KEY idx_predicate (ric_predicate)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'SELECT 1');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

SELECT 'RiC-CM Phase 4 schema applied (archive_places + archive_relations polymorphic)' AS migration_note;

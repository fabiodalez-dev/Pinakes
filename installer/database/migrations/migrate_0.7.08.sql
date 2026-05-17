-- Pinakes v0.7.8 — RiC-CM Phase 2: Agents as first-class RiC-CM entities
--
-- Issue #122 — Records in Contexts Conceptual Model (ICA 2023).
-- Phase 2 of the 6-phase roadmap. Phase 1 (v0.7.7) was schema-free —
-- this is the first migration in the RiC-CM chain that touches the DB.
--
-- What Phase 2 adds
-- =================
-- 1. Four new columns on `authority_records`:
--      ric_type        — RiC-CM canonical type (Person/CorporateBody/
--                        Family/Position/Group), backfilled from the
--                        existing `type` (ISAAR-CPF: person/corporate/
--                        family). Position and Group are RiC-CM-only
--                        types ISAAR does not model.
--      birth_date      — Structured birth/foundation date (xsd:date or
--                        partial like '1840' / '1840-08' / '1840-08-12').
--      death_date      — Structured death/dissolution date.
--      place_of_origin — Birthplace / founding place free text. Phase 4
--                        will replace this with a FK to archive_places.
--
-- 2. New table `archive_agent_identifiers`. Lets an authority carry
--    multiple identifiers from different schemes (VIAF, ISNI, Wikidata,
--    GND, BNF, LCNAF, Getty ULAN, ARK, local) — analogous to the
--    `author_authority_alternates` pattern used by the viaf-authority
--    plugin on the bibliographic `autori` table, but separate because
--    these belong to archive authorities, not library authors.
--
-- 3. New table `archive_agent_relations`. Records Agent ↔ Agent edges
--    typed with a RiC-O predicate (ric:isParentOf, ric:isMemberOf,
--    ric:isSuccessorOf, ...). Captures organisational hierarchies,
--    family relationships, and corporate successions that ISAAR's flat
--    table cannot express.
--
-- Why none of this lives on `authority_records` directly
-- ======================================================
-- - identifiers: 1:N — an authority can have a VIAF id, an ISNI id, AND
--   a Wikidata id simultaneously. The pre-Phase-2 `identifiers VARCHAR`
--   column collapsed them into a single comma-separated blob, losing the
--   scheme tag. archive_agent_identifiers is the structured replacement;
--   the old column is preserved for back-compat.
--
-- - relations: M:M — Agent→Agent is a graph, not a tree. The shared
--   archive_agent_relations table can represent every direction with a
--   single FK pair.
--
-- All DDLs use CREATE TABLE IF NOT EXISTS / idempotent ALTER guards via
-- INFORMATION_SCHEMA so re-running the migration is safe.
--
-- See README.md "What's New in v0.7.8" + ~/Desktop/ric-cm-plan.md
-- (the canonical 6-phase roadmap).

-- ─── ALTER TABLE authority_records — Phase 2 columns ────────────────

-- ric_type — RiC-CM canonical type (broader than the ISAAR enum).
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'authority_records'
      AND COLUMN_NAME  = 'ric_type'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE authority_records ADD COLUMN ric_type ENUM('Person','CorporateBody','Family','Position','Group') NOT NULL DEFAULT 'Person' AFTER type",
    'SELECT 1');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- Backfill ric_type from the existing ISAAR `type` column. Idempotent:
-- the WHERE narrows to rows still on the default 'Person' value, so
-- re-running the migration won't overwrite a curator-set override.
UPDATE authority_records
   SET ric_type = CASE type
       WHEN 'person'    THEN 'Person'
       WHEN 'corporate' THEN 'CorporateBody'
       WHEN 'family'    THEN 'Family'
       ELSE 'Person'
   END
 WHERE ric_type = 'Person'
   AND type IN ('corporate', 'family');

-- birth_date — structured begin-of-existence date.
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'authority_records'
      AND COLUMN_NAME  = 'birth_date'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE authority_records ADD COLUMN birth_date VARCHAR(20) NULL AFTER dates_of_existence",
    'SELECT 1');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- death_date — structured end-of-existence date.
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'authority_records'
      AND COLUMN_NAME  = 'death_date'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE authority_records ADD COLUMN death_date VARCHAR(20) NULL AFTER birth_date",
    'SELECT 1');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- place_of_origin — birthplace / founding place (Phase 4 swaps this for
-- a FK to archive_places).
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'authority_records'
      AND COLUMN_NAME  = 'place_of_origin'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE authority_records ADD COLUMN place_of_origin VARCHAR(255) NULL AFTER death_date",
    'SELECT 1');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ─── CREATE TABLE archive_agent_identifiers ─────────────────────────
-- Multi-scheme identifier ledger for archive authorities. Mirrors the
-- author_authority_alternates pattern used by the viaf-authority plugin
-- on `autori`, but keyed on authority_records so archival agents can
-- carry their own VIAF/ISNI/Wikidata/GND/BNF identifiers without
-- depending on a bibliographic-author link.

CREATE TABLE IF NOT EXISTS archive_agent_identifiers (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    authority_id BIGINT UNSIGNED NOT NULL,
    scheme       ENUM('viaf','isni','wikidata','gnd','bnf','lcnaf','ulan','ark','local') NOT NULL,
    value        VARCHAR(255) NOT NULL,
    uri          VARCHAR(500) NULL,
    is_preferred TINYINT(1) NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_authority_id (authority_id),
    KEY idx_scheme_value (scheme, value(64)),
    UNIQUE KEY uq_authority_scheme_value (authority_id, scheme, value(128)),
    CONSTRAINT fk_aai_authority FOREIGN KEY (authority_id)
        REFERENCES authority_records(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Defensive: on installs where the table existed in a pre-release form
-- without the unique constraint, add it (idempotent).
SET @idx_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'archive_agent_identifiers'
      AND INDEX_NAME   = 'uq_authority_scheme_value'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE archive_agent_identifiers ADD UNIQUE KEY uq_authority_scheme_value (authority_id, scheme, value(128))',
    'SELECT 1');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ─── CREATE TABLE archive_agent_relations ───────────────────────────
-- Agent ↔ Agent edges. The `ric_predicate` column is VARCHAR rather
-- than ENUM because RiC-O's agent-to-agent predicate set is open and
-- new ones can appear without a migration; the validator lives in
-- ArchivesPlugin so the column stays flexible.

CREATE TABLE IF NOT EXISTS archive_agent_relations (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id      BIGINT UNSIGNED NOT NULL,
    related_id    BIGINT UNSIGNED NOT NULL,
    ric_predicate VARCHAR(128) NOT NULL,
    -- Examples: ric:isParentOf, ric:isChildOf, ric:isMemberOf,
    --           ric:isSuccessorOf, ric:isAssociatedWith,
    --           ric:isMarriedTo, ric:isSiblingOf.
    qualifier     VARCHAR(255) NULL,
    date_start    VARCHAR(20)  NULL,
    date_end      VARCHAR(20)  NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_agent (agent_id),
    KEY idx_related (related_id),
    KEY idx_predicate (ric_predicate),
    UNIQUE KEY uq_agent_related_predicate (agent_id, related_id, ric_predicate),
    CONSTRAINT fk_aar_agent
        FOREIGN KEY (agent_id)   REFERENCES authority_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_aar_related
        FOREIGN KEY (related_id) REFERENCES authority_records(id) ON DELETE CASCADE,
    -- Reject self-loops at the schema layer (an agent cannot be
    -- related to itself with any predicate). MySQL 8.0.16+ enforces
    -- CHECK constraints; on older versions this is parsed but ignored
    -- — the application-layer validator in ArchivesPlugin covers the
    -- fallback.
    CONSTRAINT chk_aar_no_self_loop CHECK (agent_id <> related_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration record (consumed by the updater's idempotent re-run guard).
SELECT 'RiC-CM Phase 2 schema applied (authority_records + agent_identifiers + agent_relations)' AS migration_note;

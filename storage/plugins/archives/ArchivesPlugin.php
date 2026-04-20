<?php

declare(strict_types=1);

namespace App\Plugins\Archives;

/**
 * Archives plugin — ISAD(G) / ISAAR(CPF) support for Pinakes.
 *
 * SKELETON for issue #103. Introduces two new tables:
 *   - archival_units   : hierarchical archival records (fonds/series/file/item)
 *   - authority_records: persons, corporate bodies, families (ISAAR(CPF))
 *
 * The design mirrors the ABA (Copenhagen) mapping of ISAD(G) onto an extended
 * danMARC2 — see README.md for the ISAD(G) → column crosswalk.
 *
 * Nothing UI- or API-facing is wired yet. Activating the plugin creates the
 * tables; deactivating keeps them (data preservation).
 */
class ArchivesPlugin
{
    /**
     * Logical archival-unit levels, per ISAD(G) 3.1.4.
     * Ordered from most-inclusive (1) to least (4).
     */
    public const LEVELS = [
        'fonds'  => 1, // archive collection — whole of a creator's output
        'series' => 2, // grouping by provenance/function/form
        'file'   => 3, // organic unit (case file, volume)
        'item'   => 4, // smallest indivisible unit (single letter, memo)
    ];

    /**
     * Authority-record types, per ISAAR(CPF).
     */
    public const AUTHORITY_TYPES = [
        'person'    => 'Single person (biographical authority)',
        'corporate' => 'Corporate body (organisation, union, party)',
        'family'    => 'Family (genealogical authority)',
    ];

    public function __construct()
    {
    }

    /**
     * Called by PluginManager when the plugin is activated via the admin UI.
     * Creates the archival schema if missing. Idempotent.
     */
    public function onActivate(): void
    {
        $this->ensureSchema();
        // Hook registration will iterate plannedHooks() once wiring lands.
    }

    /**
     * Called when deactivated. Keeps the tables in place — dropping them
     * would delete archival records, which are probably more valuable than
     * a clean uninstall.
     */
    public function onDeactivate(): void
    {
    }

    /**
     * Returns the list of hooks this plugin *will* register once the feature
     * set ships. Exposed as a pure data array so the skeleton can be audited
     * without side-effects, and so a future `registerHooks()` can simply
     * iterate this list and call Hooks::add(...) per entry.
     *
     * Shape: [hook_name, callback_method, priority]
     *
     * @return list<array{hook: string, method: string, priority: int, description: string}>
     */
    public function plannedHooks(): array
    {
        return [
            [
                'hook'        => 'search.unified.sources',
                'method'      => 'addArchivalSources',
                'priority'    => 10,
                'description' => 'Contribute archival_units + authority_records to unified search',
            ],
            [
                'hook'        => 'admin.menu.render',
                'method'      => 'addAdminMenu',
                'priority'    => 10,
                'description' => 'Add "Archivi" section to the admin sidebar',
            ],
            [
                'hook'        => 'libri.authority.resolve',
                'method'      => 'resolveAuthority',
                'priority'    => 10,
                'description' => 'Share authority_records with the legacy `libri.autori` table',
            ],
        ];
    }

    /**
     * Create archival tables if they do not exist. Uses the global mysqli
     * connection conventionally exposed as `Database::getInstance()->getMysqli()`
     * in Pinakes — keep in sync with the host's DI container when wiring.
     *
     * Schema is intentionally minimal: core ISAD(G) 3.1-3.5 fields only.
     * Extensions (3.6 note area, 3.7 control area, finding aids) will land
     * in follow-up migrations once CRUD is in place.
     */
    private function ensureSchema(): void
    {
        // TODO: wire to the host's Database service (see app/Support/Database.php).
        // Deliberately not executing raw queries here yet — the skeleton ships
        // the DDL as string constants so reviewers can audit schema intent
        // before we touch production DBs.
    }

    /**
     * DDL for the `archival_units` hierarchical table.
     * Exposed as a string so tests and reviewers can inspect intent.
     *
     * ISAD(G) crosswalk (selected):
     *   reference_code         → 3.1.1 Reference code
     *   formal_title           → 3.1.2 Title (241*a in ABA format)
     *   constructed_title      → 3.1.2 Title (245*a — title given by archivist)
     *   date_start/date_end    → 3.1.3 Dates of creation
     *   predominant_dates      → 3.1.3 Dates — predominant
     *   level                  → 3.1.4 Level of description
     *   extent                 → 3.1.5 Extent and medium
     *   scope_content          → 3.3.1 Scope and content / Abstract
     *   appraisal              → 3.3.2 Appraisal / destruction
     *   accruals               → 3.3.3 Accruals
     *   arrangement_system     → 3.3.4 System of arrangement
     *   access_conditions      → 3.4.1 Conditions governing access
     *   reproduction_rules     → 3.4.2 Conditions governing reproduction
     *   language_codes         → 3.4.3 Language/scripts
     *   finding_aids           → 3.4.5 Finding aids
     *   originals_location     → 3.5.1 Existence and location of originals
     *   copies_location        → 3.5.2 Existence and location of copies
     *   related_units          → 3.5.3 Related units of description
     *   archival_history       → 3.2.3 Archival history
     *   acquisition_source     → 3.2.4 Immediate source of acquisition
     *   registration_date      → 3.7.3 Date(s) of descriptions
     */
    public static function ddlArchivalUnits(): string
    {
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS archival_units (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id           BIGINT UNSIGNED NULL,
            reference_code      VARCHAR(64)  NOT NULL,
            institution_code    VARCHAR(16)  NOT NULL DEFAULT 'PINAKES',
            level               ENUM('fonds','series','file','item') NOT NULL,
            formal_title        VARCHAR(500) NULL,
            constructed_title   VARCHAR(500) NOT NULL,
            date_start          SMALLINT NULL,
            date_end            SMALLINT NULL,
            predominant_dates   VARCHAR(255) NULL,
            date_gaps           VARCHAR(255) NULL,
            extent              VARCHAR(500) NULL,
            scope_content       TEXT NULL,
            appraisal           TEXT NULL,
            accruals            ENUM('none','completed','ongoing','irregular') NULL,
            arrangement_system  VARCHAR(255) NULL,
            access_conditions   VARCHAR(255) NULL,
            reproduction_rules  VARCHAR(255) NULL,
            language_codes      VARCHAR(64)  NULL,
            finding_aids        TEXT NULL,
            originals_location  VARCHAR(500) NULL,
            copies_location     VARCHAR(500) NULL,
            related_units       TEXT NULL,
            archival_history    TEXT NULL,
            acquisition_source  VARCHAR(500) NULL,
            physical_location   VARCHAR(255) NULL,
            material_status     ENUM('unclassified','cataloguing','completed') NOT NULL DEFAULT 'unclassified',
            registration_date   DATE NULL,
            created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at          TIMESTAMP NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_reference (institution_code, reference_code),
            KEY idx_parent (parent_id),
            KEY idx_level (level),
            KEY idx_dates (date_start, date_end),
            KEY idx_deleted (deleted_at),
            FULLTEXT KEY ft_search (formal_title, constructed_title, scope_content, archival_history),
            CONSTRAINT fk_archival_parent FOREIGN KEY (parent_id) REFERENCES archival_units(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
    }

    /**
     * DDL for `authority_records` (ISAAR(CPF)).
     *
     * Kept separate from the existing `autori` table because ISAAR covers
     * corporate bodies + families in addition to persons, and carries a
     * richer element set (dates of existence, history, functions, mandates).
     *
     * ISAAR(CPF) crosswalk (selected):
     *   type                 → 5.1.1 Type of entity (person/corporate/family)
     *   authorised_form      → 5.1.2 Authorized form of name
     *   parallel_forms       → 5.1.3 Parallel forms of name
     *   other_forms          → 5.1.5 Other forms of name
     *   identifiers          → 5.1.6 Identifiers
     *   dates_of_existence   → 5.2.1 Dates of existence (birth/death, founded/dissolved)
     *   history              → 5.2.2 History
     *   places               → 5.2.3 Places
     *   legal_status         → 5.2.4 Legal status
     *   functions            → 5.2.5 Functions, occupations, activities
     *   mandates             → 5.2.6 Mandates / sources of authority
     *   internal_structure   → 5.2.7 Internal structure / genealogy
     *   general_context      → 5.2.8 General context
     */
    public static function ddlAuthorityRecords(): string
    {
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS authority_records (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type               ENUM('person','corporate','family') NOT NULL,
            authorised_form    VARCHAR(500) NOT NULL,
            parallel_forms     TEXT NULL,
            other_forms        TEXT NULL,
            identifiers        VARCHAR(500) NULL,
            dates_of_existence VARCHAR(255) NULL,
            history            TEXT NULL,
            places             TEXT NULL,
            legal_status       VARCHAR(255) NULL,
            functions          TEXT NULL,
            mandates           TEXT NULL,
            internal_structure TEXT NULL,
            general_context    TEXT NULL,
            gender             ENUM('female','male','other','unknown') NULL,
            external_refs      TEXT NULL,
            created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at         TIMESTAMP NULL,
            PRIMARY KEY (id),
            KEY idx_type (type),
            KEY idx_deleted (deleted_at),
            FULLTEXT KEY ft_search (authorised_form, parallel_forms, history, functions)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
    }

    /**
     * DDL for the many-to-many link between archival_units and authority_records.
     * Mirrors MARC fields 610/700/710 (subject + added entry) from ABA format.
     */
    public static function ddlArchivalAuthorityLinks(): string
    {
        return <<<'SQL'
        CREATE TABLE IF NOT EXISTS archival_unit_authority (
            archival_unit_id BIGINT UNSIGNED NOT NULL,
            authority_id     BIGINT UNSIGNED NOT NULL,
            role             ENUM('creator','subject','recipient','custodian','associated') NOT NULL DEFAULT 'subject',
            PRIMARY KEY (archival_unit_id, authority_id, role),
            KEY idx_authority (authority_id, role),
            CONSTRAINT fk_aua_unit FOREIGN KEY (archival_unit_id) REFERENCES archival_units(id) ON DELETE CASCADE,
            CONSTRAINT fk_aua_auth FOREIGN KEY (authority_id)     REFERENCES authority_records(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;
    }
}

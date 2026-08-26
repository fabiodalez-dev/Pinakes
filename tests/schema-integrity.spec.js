// @ts-check
/**
 * E2E — Schema integrity verification (v0.7.4)
 *
 * Post-install / post-upgrade DB parity check.
 * Verifies that every migration from v0.3.0 → v0.7.4 has been applied
 * correctly, whether via fresh install (schema.sql) or upgrade (migrate_*.sql).
 *
 * IMPORTANT — two-tier table model:
 *   CORE_TABLES (derived from schema.sql): every CREATE TABLE the base installer runs.
 *   Archives tables (5): created only when the Archives plugin is activated
 *     (archival_units, authority_records, archival_unit_authority,
 *      autori_authority_link, archival_unit_files). Tests 10–12 skip
 *     automatically when those tables are absent (checked via tableExists()).
 *
 * Covers:
 *  1. Installer creates every core table schema.sql defines (derived, not hardcoded)
 *  2. All core tables schema.sql defines exist by name
 *  3. libri: critical columns + tipo_media ENUM values
 *  4. autori: VIAF/ISNI authority control columns (v0.7.0)
 *  5. prestiti: NCIP columns; origine ENUM includes 'ncip' (v0.7.3)
 *  6. ncip_partners: isil + notes columns (v0.7.4)
 *  7. ncip_transactions: v0.7.4 schema (partner_id, prestito_id, status,
 *       error_msg added; related_loan_id DROPPED)
 *  8. mag_project_config: institution_code, collection_name, rights_statement,
 *       base_url columns (v0.7.4 extended)
 *  9. digital_assets: all columns (v0.7.4)
 * 10. [Archives] archival_units: base + photo + interop columns (skip if absent)
 * 11. [Archives] archival_units.specific_material ENUM — MARC21 values (skip if absent)
 * 12. [Archives] archival_unit_files: all columns (v0.7.4) (skip if absent)
 * 13. oai_deleted_records: entity_type ENUM includes 'archival_unit' (v0.6.0)
 * 14. collane: hierarchy columns parent_id / tipo / gruppo_serie (v0.5.9.5)
 * 15. utenti: GDPR privacy columns (v0.4.0)
 * 16. Plugin registrations: all bundled plugins (BundledPlugins::LIST) present in DB
 * 17. Plugin path integrity: path = name for all bundled (bind_param type-swap regression)
 *
 * Run:
 *   /tmp/run-e2e.sh tests/schema-integrity.spec.js \
 *     --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const {
    readCoreTablesFromSchema,
    readBundledPlugins,
} = require('./helpers/source-expectations');

const DB_USER   = process.env.E2E_DB_USER   || '';
const DB_PASS   = process.env.E2E_DB_PASS   || '';
const DB_NAME   = process.env.E2E_DB_NAME   || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_HOST   = process.env.E2E_DB_HOST   || '';
const DB_PORT   = process.env.E2E_DB_PORT   || '';

function mysqlArgs(sql) {
    const args = [];
    if (DB_HOST) {
        args.push('-h', DB_HOST);
        if (DB_PORT) args.push('-P', DB_PORT);
    } else if (DB_SOCKET) {
        args.push('-S', DB_SOCKET);
    }
    args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
    return args;
}
const MYSQL_ENV = () => ({ ...process.env, MYSQL_PWD: DB_PASS });
function dbQuery(sql) {
    return execFileSync('mysql', mysqlArgs(sql), {
        encoding: 'utf-8', timeout: 10000, env: MYSQL_ENV(),
    }).trim();
}

test.skip(!DB_USER || !DB_NAME, 'Missing E2E env (DB_*)');

// ── Expected state (DERIVED, never hardcoded) ───────────────────────────────
//
// Both lists come from the app's own sources of truth — schema.sql for core
// tables, BundledPlugins::LIST for plugins — so schema growth NEVER requires
// editing this file. A hardcoded count/list here would only re-assert what the
// source already says and would fail on every legitimate addition; that isn't a
// real invariant. What we actually verify: the installed DB reflects exactly
// what those sources declare.
//
// KEEP THIS DYNAMIC. Do not reintroduce a hardcoded table/plugin list or count.

/** Core tables the installer must create — every CREATE TABLE in schema.sql. */
const coreTablesFromSource = readCoreTablesFromSchema();
const CORE_TABLES = coreTablesFromSource ?? [];

/** Bundled plugins that must be registered — every entry in BundledPlugins::LIST. */
const pluginsFromSource = readBundledPlugins();
const EXPECTED_PLUGINS = pluginsFromSource ?? [];

// Without the sources we can't derive expectations — skip rather than assert
// against an empty/guessed list (remote-only CI target, no source checkout).
test.skip(coreTablesFromSource === null || pluginsFromSource === null,
    'schema.sql / BundledPlugins.php not readable — cannot derive expectations');

// ── Helper ────────────────────────────────────────────────────────────────────

/**
 * Returns COLUMN_TYPE string for a given table.column, or '' if absent.
 * @param {string} tbl
 * @param {string} col
 * @returns {string}
 */
function colType(tbl, col) {
    return dbQuery(
        `SELECT COALESCE(COLUMN_TYPE,'')
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = '${tbl}'
            AND COLUMN_NAME  = '${col}'`
    );
}

/** Returns true if a table exists in the current DB. */
function tableExists(tbl) {
    return parseInt(dbQuery(
        `SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES` +
        ` WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${tbl}'`
    )) === 1;
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test.describe.serial('Schema integrity — v0.7.4 (17 tests)', () => {

    // ── 1. Core table count ───────────────────────────────────────────────────

    test(`1. Installer creates every core table schema.sql defines (${CORE_TABLES.length})`, () => {
        // CORE_TABLES is derived from schema.sql (the source of truth), so this
        // verifies the installed DB contains exactly the tables the schema
        // declares — and updates itself when schema.sql grows.
        const list = CORE_TABLES.map(t => `'${t}'`).join(',');
        const cnt = parseInt(dbQuery(
            `SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES` +
            ` WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (${list})`
        ));
        expect(cnt, `Expected ${CORE_TABLES.length} core tables from schema.sql, found ${cnt} in DB`).toBe(CORE_TABLES.length);
    });

    // ── 2. All core tables present by name ────────────────────────────────────

    test('2. All core tables schema.sql defines exist in the DB', () => {
        const actual = new Set(
            dbQuery("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()")
                .split('\n').map(t => t.trim()).filter(Boolean)
        );
        const missing = CORE_TABLES.filter(t => !actual.has(t));
        expect(missing,
            `Tables defined in schema.sql but missing from DB: ${missing.join(', ')}`
        ).toHaveLength(0);
    });

    // ── 3. libri columns ──────────────────────────────────────────────────────

    test('3. libri: critical columns with correct types', () => {
        const checks = /** @type {[string,string][]} */ ([
            ['deleted_at',        'datetime'],
            ['descrizione_plain', 'text'],
            ['curatore',          'varchar(255)'],
            ['issn',              'varchar(20)'],
            ['ean',               'varchar(20)'],
            ['isbn13',            'varchar(13)'],
            ['isbn10',            'varchar(10)'],
            ['tipo_media',        'enum'],
        ]);
        for (const [col, expectedLike] of checks) {
            const ct = colType('libri', col);
            expect(ct, `libri.${col} missing or wrong type`).toContain(expectedLike);
        }
        const tipoType = colType('libri', 'tipo_media');
        for (const v of ['libro', 'disco', 'audiolibro', 'dvd', 'altro']) {
            expect(tipoType, `libri.tipo_media missing '${v}'`).toContain(`'${v}'`);
        }
    });

    // ── 4. autori VIAF/ISNI columns ───────────────────────────────────────────

    test('4. autori: VIAF/ISNI authority control columns (v0.7.0)', () => {
        const cols = /** @type {[string,string][]} */ ([
            ['viaf_id',             'varchar(50)'],
            ['viaf_uri',            'varchar(500)'],
            ['isni_id',             'varchar(16)'],
            ['isni_uri',            'varchar(500)'],
            ['authority_source',    'enum'],
            ['authority_confidence','enum'],
        ]);
        for (const [col, expectedLike] of cols) {
            const ct = colType('autori', col);
            expect(ct, `autori.${col} missing or wrong type`).toContain(expectedLike);
        }
        const src = colType('autori', 'authority_source');
        for (const v of ['manual', 'viaf', 'isni', 'sbn', 'wikidata']) {
            expect(src, `autori.authority_source missing '${v}'`).toContain(`'${v}'`);
        }
        const conf = colType('autori', 'authority_confidence');
        for (const v of ['exact', 'probable', 'candidate', 'rejected']) {
            expect(conf, `autori.authority_confidence missing '${v}'`).toContain(`'${v}'`);
        }
    });

    // ── 4b. autori photo + source links (#163) ─────────────────────────────────

    test('4b. autori: photo + source-link columns (#163, v0.7.20)', () => {
        // Present via both paths: schema.sql (fresh install) and the 0.7.20 migration (upgrade).
        expect(colType('autori', 'foto'), 'autori.foto missing').toContain('varchar');
        // collegamenti is JSON on MySQL but LONGTEXT on MariaDB — assert presence, not exact type.
        expect(colType('autori', 'collegamenti'), 'autori.collegamenti missing').toBeTruthy();
    });

    // ── 5. prestiti NCIP ──────────────────────────────────────────────────────

    test("5. prestiti: ncip_request_id + origine ENUM includes 'ncip' (v0.7.3)", () => {
        const reqId = colType('prestiti', 'ncip_request_id');
        expect(reqId, 'prestiti.ncip_request_id missing').toContain('varchar(255)');

        const origine = colType('prestiti', 'origine');
        expect(origine, 'prestiti.origine missing').toContain('enum');
        for (const v of ['richiesta', 'prenotazione', 'diretto', 'ncip']) {
            expect(origine, `prestiti.origine missing '${v}'`).toContain(`'${v}'`);
        }
    });

    // ── 6. ncip_partners columns ──────────────────────────────────────────────

    test('6. ncip_partners: isil + notes columns added (v0.7.4)', () => {
        expect(colType('ncip_partners', 'code'),        'ncip_partners.code').toContain('varchar(64)');
        expect(colType('ncip_partners', 'name'),        'ncip_partners.name').toContain('varchar(255)');
        expect(colType('ncip_partners', 'endpoint_url'),'ncip_partners.endpoint_url').toContain('varchar(500)');
        expect(colType('ncip_partners', 'isil'),        'ncip_partners.isil missing (v0.7.4)').toContain('varchar(64)');
        expect(colType('ncip_partners', 'notes'),       'ncip_partners.notes missing (v0.7.4)').toContain('text');
        expect(colType('ncip_partners', 'active'),      'ncip_partners.active').toContain('tinyint');
    });

    // ── 7. ncip_transactions v0.7.4 schema ───────────────────────────────────

    test('7. ncip_transactions: v0.7.4 columns added, related_loan_id dropped', () => {
        expect(colType('ncip_transactions', 'partner_id'),  'ncip_transactions.partner_id must be integer').toContain('int');
        expect(colType('ncip_transactions', 'prestito_id'), 'ncip_transactions.prestito_id must be integer').toContain('int');
        expect(colType('ncip_transactions', 'prenotazione_id'), 'ncip_transactions.prenotazione_id must be integer').toContain('int');
        expect(colType('ncip_transactions', 'error_msg'),   'ncip_transactions.error_msg missing').toContain('varchar(1000)');
        const status = colType('ncip_transactions', 'status');
        expect(status, 'ncip_transactions.status missing').toContain('enum');
        for (const v of ['pending', 'success', 'error']) {
            expect(status, `ncip_transactions.status missing '${v}'`).toContain(`'${v}'`);
        }
        const dropped = dbQuery(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS " +
            "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ncip_transactions' " +
            "AND COLUMN_NAME='related_loan_id'"
        );
        expect(parseInt(dropped), 'ncip_transactions.related_loan_id should be dropped (v0.7.4)').toBe(0);
    });

    // ── 8. mag_project_config columns ────────────────────────────────────────

    test('8. mag_project_config: extended columns present (v0.7.4)', () => {
        expect(colType('mag_project_config', 'project_code'),     'project_code').toContain('varchar(64)');
        expect(colType('mag_project_config', 'institution_code'), 'institution_code missing (v0.7.4)').toContain('varchar(16)');
        expect(colType('mag_project_config', 'collection_name'),  'collection_name missing (v0.7.4)').toContain('varchar(255)');
        expect(colType('mag_project_config', 'rights_statement'), 'rights_statement missing (v0.7.4)').toContain('varchar(500)');
        expect(colType('mag_project_config', 'base_url'),         'base_url missing (v0.7.4)').toContain('varchar(500)');
    });

    // ── 9. digital_assets columns ─────────────────────────────────────────────

    test('9. digital_assets: all columns present (v0.7.4)', () => {
        const cols = /** @type {[string,string][]} */ ([
            ['url',          'varchar(500)'],
            ['md5_hash',     'char(32)'],
            ['filesize',     'bigint'],
            ['image_width',  'int'],
            ['image_height', 'int'],
            ['ppi',          'smallint'],
            ['filetype',     'varchar(32)'],
        ]);
        for (const [col, expectedLike] of cols) {
            const ct = colType('digital_assets', col);
            expect(ct, `digital_assets.${col} missing or wrong type`).toContain(expectedLike);
        }
    });

    // ── 10–12: Archives plugin tables (skip if plugin not yet activated) ───────

    test('10. [Archives] archival_units: base + photo + interop columns', () => {
        test.skip(!tableExists('archival_units'), 'Archives plugin not activated — archival_units absent');
        // Base columns
        expect(colType('archival_units', 'reference_code'),    'reference_code').toContain('varchar(64)');
        expect(colType('archival_units', 'institution_code'),  'institution_code').toContain('varchar(16)');
        expect(colType('archival_units', 'level'),             'level').toContain('enum');
        expect(colType('archival_units', 'constructed_title'), 'constructed_title').toContain('varchar(500)');
        expect(colType('archival_units', 'deleted_at'),        'deleted_at').toBeTruthy();
        // Photo extension columns
        expect(colType('archival_units', 'specific_material'), 'specific_material').toContain('enum');
        expect(colType('archival_units', 'dimensions'),        'dimensions').toContain('varchar(100)');
        expect(colType('archival_units', 'color_mode'),        'color_mode').toContain('enum');
        expect(colType('archival_units', 'photographer'),      'photographer').toContain('varchar(255)');
        expect(colType('archival_units', 'local_classification'),'local_classification').toContain('varchar(64)');
        // Per-document asset columns
        expect(colType('archival_units', 'cover_image_path'),  'cover_image_path').toContain('varchar(500)');
        expect(colType('archival_units', 'document_path'),     'document_path').toContain('varchar(500)');
        expect(colType('archival_units', 'document_mime'),     'document_mime').toContain('varchar(100)');
        expect(colType('archival_units', 'document_filename'), 'document_filename').toContain('varchar(255)');
        // Interop columns
        expect(colType('archival_units', 'iiif_manifest_url'),   'iiif_manifest_url').toContain('varchar(2000)');
        expect(colType('archival_units', 'rights_statement_url'),'rights_statement_url').toContain('varchar(500)');
        expect(colType('archival_units', 'ark_identifier'),      'ark_identifier').toContain('varchar(255)');
        expect(colType('archival_units', 'version_note'),        'version_note').toContain('varchar(500)');
    });

    test('11. [Archives] archival_units.specific_material ENUM includes extended MARC21 values', () => {
        test.skip(!tableExists('archival_units'), 'Archives plugin not activated — archival_units absent');
        const ct = colType('archival_units', 'specific_material');
        const required = ['text', 'photograph', 'poster', 'postcard', 'drawing',
                          'audio', 'video', 'other',
                          'map', 'picture', 'object', 'film', 'microform', 'electronic', 'mixed'];
        for (const v of required) {
            expect(ct, `archival_units.specific_material missing '${v}'`).toContain(`'${v}'`);
        }
    });

    test('12. [Archives] archival_unit_files: all columns present (v0.7.4)', () => {
        test.skip(!tableExists('archival_unit_files'), 'Archives plugin not activated — archival_unit_files absent');
        expect(colType('archival_unit_files', 'unit_id'),           'unit_id').toContain('bigint');
        expect(colType('archival_unit_files', 'file_path'),         'file_path').toContain('varchar(500)');
        expect(colType('archival_unit_files', 'file_mime'),         'file_mime').toContain('varchar(127)');
        expect(colType('archival_unit_files', 'original_filename'), 'original_filename').toContain('varchar(255)');
        expect(colType('archival_unit_files', 'sort_order'),        'sort_order').toContain('smallint');
    });

    // ── 13. oai_deleted_records entity_type ──────────────────────────────────

    test("13. oai_deleted_records: entity_type ENUM includes 'archival_unit' (v0.6.0)", () => {
        const ct = colType('oai_deleted_records', 'entity_type');
        expect(ct, 'oai_deleted_records.entity_type missing').toContain('enum');
        expect(ct, "entity_type missing 'book'").toContain("'book'");
        expect(ct, "entity_type missing 'archival_unit'").toContain("'archival_unit'");
    });

    // ── 14. collane hierarchy columns ─────────────────────────────────────────

    test('14. collane: hierarchy columns present (v0.5.9.5)', () => {
        expect(colType('collane', 'parent_id'),    'collane.parent_id missing').toBeTruthy();
        expect(colType('collane', 'tipo'),         'collane.tipo missing').toContain('varchar(32)');
        expect(colType('collane', 'gruppo_serie'), 'collane.gruppo_serie missing').toContain('varchar(100)');
        expect(colType('collane', 'ciclo'),        'collane.ciclo missing').toContain('varchar(100)');
        expect(colType('collane', 'ordine_ciclo'), 'collane.ordine_ciclo missing').toContain('smallint');
    });

    // ── 15. utenti GDPR columns ───────────────────────────────────────────────

    test('15. utenti: GDPR privacy columns present (v0.4.0)', () => {
        expect(colType('utenti', 'privacy_accettata'),         'privacy_accettata').toContain('tinyint');
        expect(colType('utenti', 'data_accettazione_privacy'), 'data_accettazione_privacy').toContain('datetime');
        expect(colType('utenti', 'privacy_policy_version'),    'privacy_policy_version').toContain('varchar(20)');
    });

    // ── 16. Plugin registrations ──────────────────────────────────────────────

    test(`16. All bundled plugins registered in plugins table (${EXPECTED_PLUGINS.length})`, () => {
        const registered = new Set(
            dbQuery("SELECT name FROM plugins").split('\n').map(n => n.trim()).filter(Boolean)
        );
        const missing = EXPECTED_PLUGINS.filter(p => !registered.has(p));
        expect(missing, `Missing plugin registrations: ${missing.join(', ')}`).toHaveLength(0);
    });

    // ── 17. Plugin path integrity ─────────────────────────────────────────────

    test('17. Plugin path = name for all bundled plugins (bind_param regression)', () => {
        const rows = dbQuery(
            "SELECT name, path FROM plugins WHERE name IN (" +
            EXPECTED_PLUGINS.map(p => `'${p}'`).join(',') +
            ")"
        ).split('\n').filter(Boolean);

        const broken = [];
        for (const row of rows) {
            const [name, path] = row.split('\t');
            if (name !== path) broken.push(`${name} (path='${path}')`);
        }
        expect(broken, `Plugins with path ≠ name: ${broken.join(', ')}`).toHaveLength(0);
    });
});

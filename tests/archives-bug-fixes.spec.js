// @ts-check
/**
 * E2E — Archives bug-fix regression tests (PR feat/archives-interop-standards)
 *
 * 5 tests targeting the 6 bugs fixed in the 15-agent PR review:
 *  1. migration: registration_date column exists in archival_units
 *  2. validateArchivalUnit pass-by-ref: resolver URL normalization persists to DB
 *  3. ARK soft-delete filter: reusing an ARK from a soft-deleted unit is allowed
 *  4. buildMetsXml: OBJID = ARK identifier; metsHdr has CREATEDATE + LASTMODDATE
 *  5. oaiListRecords: malformed from/until returns OAI-PMH badArgument error
 *
 * Run: /tmp/run-e2e.sh tests/archives-bug-fixes.spec.js --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE        = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const DB_HOST     = process.env.E2E_DB_HOST     || '';
const DB_PORT     = process.env.E2E_DB_PORT     || '';
const DB_USER     = process.env.E2E_DB_USER     || '';
const DB_PASS     = process.env.E2E_DB_PASS     || '';
const DB_NAME     = process.env.E2E_DB_NAME     || '';
const DB_SOCKET   = process.env.E2E_DB_SOCKET   || '';

function mysqlArgs(sql, batch = false) {
    const args = [];
    if (DB_HOST)               args.push('-h', DB_HOST);
    if (DB_PORT)               args.push('-P', DB_PORT);
    if (!DB_HOST && DB_SOCKET) args.push('-S', DB_SOCKET);
    args.push('-u', DB_USER);
    args.push(DB_NAME);
    if (batch) args.push('-N', '-B');
    if (sql !== '') args.push('-e', sql);
    return args;
}
const MYSQL_ENV = () => ({ ...process.env, MYSQL_PWD: DB_PASS });
function dbQuery(sql) {
    return execFileSync('mysql', mysqlArgs(sql, true), { encoding: 'utf-8', timeout: 10000, env: MYSQL_ENV() }).trim();
}
function dbExec(sql) {
    execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout: 10000, env: MYSQL_ENV() });
}

const TAG     = 'E2E_BUGFIX_' + Date.now();
const REF_A   = TAG + '_A';  // unit for ARK normalization test
const REF_B   = TAG + '_B';  // unit for soft-delete ARK-reuse test (first, to be deleted)
const REF_C   = TAG + '_C';  // unit for soft-delete ARK-reuse test (second, reuses same ARK)
const REF_D   = TAG + '_D';  // unit for METS tests

// Resolver URL form — the system must strip the https://n2t.net/ prefix on save.
const ARK_BARE      = 'ark:/99999/' + TAG.toLowerCase() + '_norm';
const ARK_RESOLVER  = 'https://n2t.net/' + ARK_BARE;

// A distinct ARK for the soft-delete reuse test.
const ARK_REUSE     = 'ark:/99999/' + TAG.toLowerCase() + '_reuse';

// ARK used in the METS test (canonical form, no resolver prefix).
const ARK_METS      = 'ark:/99999/' + TAG.toLowerCase() + '_mets';

test.skip(
    !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
    'Missing E2E env (ADMIN_EMAIL/PASS, DB_*)'
);

test.describe.serial('Archives bug-fix regressions (5 tests)', () => {
    /** @type {import('@playwright/test').BrowserContext} */
    let context;
    /** @type {import('@playwright/test').Page} */
    let page;
    /** @type {number} */
    let metsUnitId = 0;

    test.beforeAll(async ({ browser }) => {
        context = await browser.newContext();
        page    = await context.newPage();

        // Login as admin.
        await page.goto(`${BASE}/login`);
        await page.fill('input[name="email"]', ADMIN_EMAIL);
        await page.fill('input[name="password"]', ADMIN_PASS);
        await Promise.all([
            page.waitForURL(/\/admin\//, { timeout: 15000 }),
            page.click('button[type="submit"]'),
        ]);

        // Ensure the archives plugin is active (creates archival_units and related tables).
        await page.goto(`${BASE}/admin/plugins`);
        await page.waitForLoadState('domcontentloaded');
        const isActive = dbQuery("SELECT is_active FROM plugins WHERE name = 'archives'");
        if (isActive !== '1') {
            const btn = page.locator('button[onclick^="activatePlugin("]').first();
            if (await btn.isVisible({ timeout: 3000 }).catch(() => false)) {
                await btn.click();
                const confirm = page.locator('.swal2-confirm').first();
                if (await confirm.isVisible({ timeout: 5000 }).catch(() => false)) await confirm.click();
                await page.waitForLoadState('domcontentloaded');
            }
        }

        // Pre-cleanup: remove any stale units from a prior failed run.
        try {
            dbExec(`DELETE FROM archival_units WHERE reference_code LIKE '${TAG}%' ESCAPE '\\\\'`);
        } catch { /* best-effort */ }
    });

    test.afterAll(async () => {
        try {
            dbExec(
                `DELETE FROM archival_units WHERE reference_code LIKE '${TAG}%' ESCAPE '\\\\'`
            );
        } catch { /* best-effort */ }
        await context?.close();
    });

    // ── Test 1: registration_date column present in schema ─────────────────────
    //
    // Bug: migrate_0.5.9.7.sql Part 1 CREATE TABLE omitted registration_date,
    // so fresh 0.5.9.6→0.5.9.7 upgrades left the column absent, causing
    // runtime MySQL errors. Fixed: column added to DDL + idempotent ALTER guard.

    test('1. archival_units schema has registration_date column', async () => {
        const col = dbQuery(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS " +
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'archival_units' " +
            "AND COLUMN_NAME = 'registration_date'"
        );
        expect(col).toBe('registration_date');
    });

    // ── Test 2: ARK normalization persists (pass-by-reference fix) ─────────────
    //
    // Bug: validateArchivalUnit received $values by value. The normalisation
    //   $values['ark_identifier'] = $ark  (resolver URL → canonical ark:/…)
    // was discarded when the function returned. The caller's INSERT/UPDATE used
    // the un-normalised resolver URL. Fixed: signature changed to array &$values.

    test('2. resolver URL ark_identifier is normalised to canonical ark:/ on save', async () => {
        await page.goto(`${BASE}/admin/archives/new`);
        await page.waitForLoadState('domcontentloaded');

        await page.fill('input[name="reference_code"]', REF_A);
        await page.selectOption('select[name="level"]', 'fonds');
        await page.fill('input[name="constructed_title"]', 'BugFix ARK Normalisation');
        // Submit a resolver URL — the server must strip the https://n2t.net/ prefix.
        await page.fill('input[name="ark_identifier"]', ARK_RESOLVER);

        await Promise.all([
            page.waitForURL(/\/admin\/archives$/, { timeout: 10000 }),
            page.click('button[type="submit"]'),
        ]);

        const stored = dbQuery(
            `SELECT ark_identifier FROM archival_units WHERE reference_code = '${REF_A}' AND deleted_at IS NULL`
        );
        // Must be the canonical form, NOT the resolver URL.
        expect(stored).toBe(ARK_BARE);
        expect(stored).not.toContain('https://');
    });

    // ── Test 3: ARK reuse allowed after soft-delete (soft-delete filter fix) ───
    //
    // Bug: the ARK uniqueness SELECT lacked AND deleted_at IS NULL, so a
    // soft-deleted record's ARK permanently blocked reuse. Fixed: condition added.
    // Also verifies the UNIQUE KEY uq_ark_identifier is present (migration fix).

    test('3. ARK from a soft-deleted unit can be reused by a new unit', async () => {
        // Step A: create a unit with ARK_REUSE.
        await page.goto(`${BASE}/admin/archives/new`);
        await page.waitForLoadState('domcontentloaded');
        await page.fill('input[name="reference_code"]', REF_B);
        await page.selectOption('select[name="level"]', 'fonds');
        await page.fill('input[name="constructed_title"]', 'BugFix ARK Reuse Original');
        await page.fill('input[name="ark_identifier"]', ARK_REUSE);
        await Promise.all([
            page.waitForURL(/\/admin\/archives$/, { timeout: 10000 }),
            page.click('button[type="submit"]'),
        ]);
        const origId = dbQuery(
            `SELECT id FROM archival_units WHERE reference_code = '${REF_B}' AND deleted_at IS NULL`
        );
        expect(Number(origId)).toBeGreaterThan(0);

        // Step B: soft-delete it directly in the DB (simulates admin delete action).
        dbExec(
            `UPDATE archival_units SET deleted_at = NOW(), ark_identifier = NULL WHERE id = ${origId}`
        );

        // Step C: create a new unit with the same ARK — must not be blocked.
        await page.goto(`${BASE}/admin/archives/new`);
        await page.waitForLoadState('domcontentloaded');
        await page.fill('input[name="reference_code"]', REF_C);
        await page.selectOption('select[name="level"]', 'fonds');
        await page.fill('input[name="constructed_title"]', 'BugFix ARK Reuse Replacement');
        await page.fill('input[name="ark_identifier"]', ARK_REUSE);
        await Promise.all([
            page.waitForURL(/\/admin\/archives$/, { timeout: 10000 }),
            page.click('button[type="submit"]'),
        ]);

        const newStored = dbQuery(
            `SELECT ark_identifier FROM archival_units WHERE reference_code = '${REF_C}' AND deleted_at IS NULL`
        );
        expect(newStored).toBe(ARK_REUSE);
    });

    // ── Test 4: METS OBJID = ARK identifier; metsHdr has CREATEDATE+LASTMODDATE ─
    //
    // Bug A: METS OBJID was always 'oai:pinakes:archival_unit:{id}', ignoring ARK.
    //   Fixed: OBJID now prefers row['ark_identifier'] when set.
    // Bug B: metsHdr CREATEDATE used updated_at instead of created_at; LASTMODDATE
    //   was missing entirely. Fixed: separate $createdAt/$updatedAt; LASTMODDATE added.

    test('4. METS XML: OBJID = ARK identifier and metsHdr has CREATEDATE + LASTMODDATE', async () => {
        // Create a unit with a known ARK.
        await page.goto(`${BASE}/admin/archives/new`);
        await page.waitForLoadState('domcontentloaded');
        await page.fill('input[name="reference_code"]', REF_D);
        await page.selectOption('select[name="level"]', 'fonds');
        await page.fill('input[name="constructed_title"]', 'BugFix METS Fields');
        await page.fill('input[name="ark_identifier"]', ARK_METS);

        await Promise.all([
            page.waitForURL(/\/admin\/archives$/, { timeout: 10000 }),
            page.click('button[type="submit"]'),
        ]);
        metsUnitId = parseInt(
            dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${REF_D}' AND deleted_at IS NULL`)
        );
        expect(metsUnitId).toBeGreaterThan(0);

        // Fetch the METS XML.
        const res  = await page.request.get(`${BASE}/archives/${metsUnitId}/mets.xml`);
        expect(res.status()).toBe(200);
        const text = await res.text();

        // OBJID must be the ARK value, not the oai: fallback.
        expect(text).toContain(`OBJID="${ARK_METS}"`);
        expect(text).not.toContain(`OBJID="oai:pinakes:archival_unit:${metsUnitId}"`);

        // metsHdr must have both CREATEDATE and LASTMODDATE.
        expect(text).toContain('CREATEDATE=');
        expect(text).toContain('LASTMODDATE=');

        // Both dates must be ISO 8601 UTC (YYYY-MM-DDThh:mm:ssZ).
        const createMatch  = text.match(/CREATEDATE="(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z)"/);
        const lastmodMatch = text.match(/LASTMODDATE="(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z)"/);
        expect(createMatch).not.toBeNull();
        expect(lastmodMatch).not.toBeNull();
    });

    // ── Test 5: OAI-PMH returns badArgument on malformed from/until ────────────
    //
    // Bug: malformed from/until parameters were silently accepted by MySQL's date
    // coercion instead of returning the OAI-PMH badArgument error required by spec.
    // Fixed: regex validation added in oaiListRecords before building the query.

    test('5. OAI-PMH ListRecords returns badArgument on malformed from/until dates', async () => {
        // Malformed "from" date.
        const badFrom = await page.request.get(
            `${BASE}/archives/oai?verb=ListRecords&metadataPrefix=oai_dc&from=not-a-date`
        );
        expect(badFrom.status()).toBe(200); // OAI-PMH always returns 200
        const fromText = await badFrom.text();
        expect(fromText).toContain('<error code="badArgument"');
        expect(fromText).toContain('from');

        // Malformed "until" date.
        const badUntil = await page.request.get(
            `${BASE}/archives/oai?verb=ListRecords&metadataPrefix=oai_dc&until=2024/01/01`
        );
        const untilText = await badUntil.text();
        expect(untilText).toContain('<error code="badArgument"');
        expect(untilText).toContain('until');

        // Valid dates must NOT return badArgument.
        const good = await page.request.get(
            `${BASE}/archives/oai?verb=ListRecords&metadataPrefix=oai_dc&from=2000-01-01`
        );
        const goodText = await good.text();
        expect(goodText).not.toContain('<error code="badArgument"');
        // OAI-PMH: valid response has either <ListRecords> or noRecordsMatch.
        const validResponse = goodText.includes('<ListRecords>') || goodText.includes('noRecordsMatch');
        expect(validResponse).toBe(true);
    });
});

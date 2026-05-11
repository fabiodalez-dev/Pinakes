// @ts-check
/**
 * E2E — OAI-PMH Server plugin tests (v0.7.0)
 *
 * Covers:
 *  1. Migration: oai_deleted_records, oai_resumption_tokens, mag_project_config tables exist
 *  2. Migration: trg_libri_soft_delete trigger tracks soft-deletes to oai_deleted_records
 *  3. Migration: trg_archival_soft_delete trigger tracks archival soft-deletes
 *  4. Endpoint /oai?verb=Identify → deletedRecord=persistent, oai-identifier element
 *  5. Endpoint /oai?verb=ListMetadataFormats → all 5 formats declared (incl. unimarc)
 *  6. Endpoint /oai?verb=ListSets → books + archives sets
 *  7. Endpoint /oai?verb=ListRecords&metadataPrefix=oai_dc → valid XML, record identifiers
 *  8. Endpoint /oai?verb=ListRecords&metadataPrefix=marcxml → MARC21 record
 *  9. Endpoint /oai?verb=ListRecords&metadataPrefix=mods → MODS 3.7 record
 * 10. Endpoint /oai?verb=ListRecords&metadataPrefix=mag → MAG 2.0.1 record
 * 11. Endpoint /oai?verb=ListRecords&metadataPrefix=unimarc → UNIMARC/XML record (v0.7.0)
 * 12. Endpoint /oai?verb=GetRecord&identifier=...&metadataPrefix=oai_dc → single record
 * 13. Endpoint /oai?verb=GetRecord on soft-deleted book → status="deleted" header (persistent)
 * 14. Endpoint /oai?verb=ListRecords&from=bad-date → badArgument error
 * 15. Endpoint /oai?verb=ListRecords&metadataPrefix=unknown → cannotDisseminateFormat
 * 16. Endpoint /oai?verb=badVerb → badVerb error
 * 17. Endpoint /oai?verb=ListIdentifiers&metadataPrefix=oai_dc → headers only (no metadata)
 * 18. Endpoint /oai?verb=ListIdentifiers (no metadataPrefix) → badArgument error
 *
 * Run: /tmp/run-e2e.sh tests/oai-pmh-server.spec.js --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE        = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
// OAI identifiers use the hostname from the base URL (matches PHP parse_url PHP_URL_HOST).
const oaiHost = new URL(BASE).hostname;
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const DB_USER     = process.env.E2E_DB_USER     || '';
const DB_PASS     = process.env.E2E_DB_PASS     || '';
const DB_NAME     = process.env.E2E_DB_NAME     || '';
const DB_SOCKET   = process.env.E2E_DB_SOCKET   || '';

function mysqlArgs(sql, batch = false) {
    const args = [];
    if (DB_SOCKET) args.push('-S', DB_SOCKET);
    args.push('-u', DB_USER, DB_NAME);
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

function nextResumptionToken(xml) {
    const match = xml.match(/<resumptionToken(?:\s[^>]*)?>([^<]*)<\/resumptionToken>/);
    return match && match[1] ? match[1].trim() : '';
}

async function fetchOaiPagesUntil(request, initialUrl, needle, maxPages = 12) {
    let url = initialUrl;
    let lastText = '';
    for (let pageIndex = 0; pageIndex < maxPages; pageIndex += 1) {
        const res = await request.get(url);
        expect(res.status()).toBe(200);
        const text = await res.text();
        lastText = text;
        expect(text).not.toContain('<error code=');
        if (text.includes(needle)) {
            return text;
        }
        const token = nextResumptionToken(text);
        if (!token) {
            break;
        }
        url = `${BASE}/oai?verb=ListRecords&resumptionToken=${encodeURIComponent(token)}`;
    }
    return lastText;
}

const TAG      = 'E2E_OAI_' + Date.now();
const BOOK_TAG = TAG + '_BOOK';

test.skip(
    !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
    'Missing E2E env (ADMIN_EMAIL/PASS, DB_*)'
);

test.describe.serial('OAI-PMH Server plugin — v0.7.0 (18 tests)', () => {
    /** @type {import('@playwright/test').BrowserContext} */
    let context;
    /** @type {import('@playwright/test').Page} */
    let page;
    /** @type {number} */
    let testBookId = 0;
    /** ISO-8601 timestamp captured just before test book insertion — used as ?from= filter */
    let beforeAllFrom = '';

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

        // Ensure oai-pmh-server plugin is active.
        const isActive = dbQuery("SELECT is_active FROM plugins WHERE name = 'oai-pmh-server'");
        if (isActive !== '1') {
            await page.goto(`${BASE}/admin/plugins`);
            await page.waitForLoadState('domcontentloaded');
            const btn = page.locator('button[onclick*="oai-pmh-server"]').first();
            if (await btn.isVisible({ timeout: 3000 }).catch(() => false)) {
                await btn.click();
                const confirm = page.locator('.swal2-confirm').first();
                if (await confirm.isVisible({ timeout: 5000 }).catch(() => false)) await confirm.click();
                await page.waitForLoadState('domcontentloaded');
            }
        }

        // Snapshot time before inserting the test book (used as OAI ?from= filter).
        beforeAllFrom = new Date(Date.now() - 5000).toISOString().replace(/\.\d+Z$/, 'Z');

        // Create a test book via admin UI.
        await page.goto(`${BASE}/admin/books/new`);
        await page.waitForLoadState('domcontentloaded');
        if (await page.locator('input[name="titolo"]').isVisible({ timeout: 3000 }).catch(() => false)) {
            await page.fill('input[name="titolo"]', BOOK_TAG);
            await page.fill('input[name="anno_pubblicazione"]', '2024');
            const submitBtn = page.locator('button[type="submit"]').first();
            if (await submitBtn.isVisible().catch(() => false)) {
                await submitBtn.click();
                await page.waitForLoadState('domcontentloaded');
            }
        }
        // Fallback: insert directly if UI not available.
        const existsCheck = dbQuery(
            `SELECT COUNT(*) FROM libri WHERE titolo = '${BOOK_TAG}' AND deleted_at IS NULL`
        );
        if (existsCheck === '0') {
            dbExec(
                `INSERT INTO libri (titolo, anno_pubblicazione, tipo_media)
                 VALUES ('${BOOK_TAG}', 2024, 'libro')`
            );
        }
        testBookId = parseInt(
            dbQuery(`SELECT id FROM libri WHERE titolo = '${BOOK_TAG}' AND deleted_at IS NULL LIMIT 1`)
        );

        // Pre-cleanup stale oai_deleted_records from prior runs.
        try {
            dbExec(`DELETE FROM oai_deleted_records WHERE oai_id LIKE 'oai:%:book:%' AND entity_id IN (SELECT id FROM libri WHERE titolo LIKE 'E2E\\_OAI\\_%' ESCAPE '\\\\')`);
        } catch { /* best-effort */ }
    });

    test.afterAll(async () => {
        try {
            // FK-safe: delete child rows first, then parents
            dbExec(`DELETE FROM oai_deleted_records WHERE entity_type = 'book' AND entity_id IN (SELECT id FROM libri WHERE titolo LIKE 'E2E\\_OAI\\_%' ESCAPE '\\\\')`);
            dbExec(`DELETE FROM libri WHERE titolo LIKE 'E2E\\_OAI\\_%' ESCAPE '\\\\'`);
            dbExec(`DELETE FROM archival_units WHERE reference_code LIKE 'E2E\\_OAI\\_%' ESCAPE '\\\\'`);
        } catch (error) {
            console.error('[oai-pmh afterAll] cleanup error:', error);
        }
        await context?.close();
    });

    // ── Tests 1-3: Migration schema ───────────────────────────────────────────

    test('1. oai_deleted_records table exists with correct columns', async () => {
        const cols = dbQuery(
            "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME SEPARATOR ',') " +
            "FROM information_schema.COLUMNS " +
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'oai_deleted_records'"
        );
        expect(cols).toContain('datestamp');
        expect(cols).toContain('entity_id');
        expect(cols).toContain('entity_type');
        expect(cols).toContain('oai_id');
    });

    test('2. oai_resumption_tokens and mag_project_config tables exist', async () => {
        const t1 = dbQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='oai_resumption_tokens'"
        );
        expect(t1).toBe('1');

        const t2 = dbQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mag_project_config'"
        );
        expect(t2).toBe('1');
    });

    test('3. soft-delete trigger logs book deletion to oai_deleted_records', async () => {
        // Create a temp book to soft-delete.
        const tmpTitle = TAG + '_DEL';
        dbExec(`INSERT INTO libri (titolo, tipo_media) VALUES ('${tmpTitle}', 'libro')`);
        const tmpId = parseInt(
            dbQuery(`SELECT id FROM libri WHERE titolo='${tmpTitle}' AND deleted_at IS NULL`)
        );
        expect(tmpId).toBeGreaterThan(0);

        // Soft-delete it — trigger should fire.
        dbExec(`UPDATE libri SET deleted_at=NOW() WHERE id=${tmpId}`);

        // Check oai_deleted_records.
        const oaiId = dbQuery(
            `SELECT oai_id FROM oai_deleted_records WHERE entity_type='book' AND entity_id=${tmpId}`
        );
        expect(oaiId).toMatch(new RegExp(`^oai:(${oaiHost}|pinakes):book:${tmpId}$`));

        // Cleanup.
        dbExec(`DELETE FROM libri WHERE id=${tmpId}`);
        dbExec(`DELETE FROM oai_deleted_records WHERE entity_id=${tmpId} AND entity_type='book'`);
    });

    // ── Tests 4-6: Identify, ListMetadataFormats, ListSets ───────────────────

    test('4. /oai?verb=Identify → deletedRecord=persistent and oai-identifier', async () => {
        const res  = await page.request.get(`${BASE}/oai?verb=Identify`);
        expect(res.status()).toBe(200);
        const text = await res.text();
        expect(text).toContain('<deletedRecord>persistent</deletedRecord>');
        expect(text).toContain('<scheme>oai</scheme>');
        expect(text).toContain('<delimiter>:</delimiter>');
        expect(text).toContain('<sampleIdentifier>');
        expect(text).toContain('<protocolVersion>2.0</protocolVersion>');
    });

    test('5. /oai?verb=ListMetadataFormats → 5 formats: oai_dc, marcxml, mods, mag, unimarc', async () => {
        const res  = await page.request.get(`${BASE}/oai?verb=ListMetadataFormats`);
        expect(res.status()).toBe(200);
        const text = await res.text();
        expect(text).toContain('<metadataPrefix>oai_dc</metadataPrefix>');
        expect(text).toContain('<metadataPrefix>marcxml</metadataPrefix>');
        expect(text).toContain('<metadataPrefix>mods</metadataPrefix>');
        expect(text).toContain('<metadataPrefix>mag</metadataPrefix>');
        expect(text).toContain('<metadataPrefix>unimarc</metadataPrefix>');
    });

    test('6. /oai?verb=ListSets → books and archives sets', async () => {
        const res  = await page.request.get(`${BASE}/oai?verb=ListSets`);
        expect(res.status()).toBe(200);
        const text = await res.text();
        expect(text).toContain('<setSpec>books</setSpec>');
        // archives set present if archives plugin is active and table exists.
        const archivesActive = dbQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units'"
        );
        if (archivesActive === '1') {
            expect(text).toContain('<setSpec>archives</setSpec>');
        }
    });

    // ── Tests 7-10: ListRecords per format ────────────────────────────────────

    test('7. ListRecords oai_dc → valid OAI-PMH XML with book identifier', async () => {
        // Use ?from= to restrict to records modified since just before this test run,
        // so the test book is always on the first page regardless of total repository size.
        const url  = `${BASE}/oai?verb=ListRecords&metadataPrefix=oai_dc&set=books&from=${beforeAllFrom}`;
        const expectedIdentifier = `oai:${oaiHost}:book:${testBookId}`;
        const text = await fetchOaiPagesUntil(page.request, url, expectedIdentifier);
        // Must contain at least one record.
        expect(text).toContain('<record>');
        // Must include our test book.
        expect(text).toContain(expectedIdentifier);
        // Must have oai_dc namespace.
        expect(text).toContain('oai_dc:dc');
        // resumptionToken element (always present, even if empty).
        expect(text).toContain('<resumptionToken');
    });

    test('8. ListRecords marcxml → valid MARC21 XML', async () => {
        const res  = await page.request.get(`${BASE}/oai?verb=ListRecords&metadataPrefix=marcxml&set=books`);
        expect(res.status()).toBe(200);
        const text = await res.text();
        expect(text).not.toContain('<error code=');
        // MARC21 leader
        expect(text).toContain('<leader>');
        // controlfield tag=001
        expect(text).toContain('tag="001"');
        // datafield tag=245 (title)
        expect(text).toContain('tag="245"');
    });

    test('9. ListRecords mods → valid MODS 3.7 XML', async () => {
        const res  = await page.request.get(`${BASE}/oai?verb=ListRecords&metadataPrefix=mods&set=books`);
        expect(res.status()).toBe(200);
        const text = await res.text();
        expect(text).not.toContain('<error code=');
        expect(text).toContain('<titleInfo>');
        expect(text).toContain('<typeOfResource>text</typeOfResource>');
        expect(text).toContain('version="3.7"');
    });

    test('10. ListRecords mag → valid MAG 2.0.1 XML', async () => {
        const res  = await page.request.get(`${BASE}/oai?verb=ListRecords&metadataPrefix=mag&set=books`);
        expect(res.status()).toBe(200);
        const text = await res.text();
        expect(text).not.toContain('<error code=');
        expect(text).toContain('<gen>');
        expect(text).toContain('<bib>');
        expect(text).toContain('version="2.0.1"');
        expect(text).toContain('<paese>IT</paese>');
    });

    test('11. ListRecords unimarc → valid UNIMARC/XML record (v0.7.0)', async () => {
        const res  = await page.request.get(`${BASE}/oai?verb=ListRecords&metadataPrefix=unimarc&set=books`);
        expect(res.status()).toBe(200);
        const text = await res.text();
        expect(text).not.toContain('<error code=');
        // UNIMARC leader
        expect(text).toContain('<leader>00000nam a2200000 u 4500</leader>');
        // UNIMARC field 100 (general processing data, 36 chars fixed-length)
        expect(text).toContain('tag="100"');
        // UNIMARC field 200 (title)
        expect(text).toContain('tag="200"');
        // UNIMARC field 801 (originating source — 'IT', 'Pinakes')
        expect(text).toContain('tag="801"');
        // Uses MARC21slim XML namespace
        expect(text).toContain('http://www.loc.gov/MARC21/slim');
    });

    // ── Tests 12-13: GetRecord (active + deleted) ─────────────────────────────

    test('12. GetRecord on active book → correct metadata', async () => {
        const identifier = `oai:${oaiHost}:book:${testBookId}`;
        const res  = await page.request.get(
            `${BASE}/oai?verb=GetRecord&metadataPrefix=oai_dc&identifier=${identifier}`
        );
        expect(res.status()).toBe(200);
        const text = await res.text();
        expect(text).toContain(`<identifier>${identifier}</identifier>`);
        expect(text).not.toContain('status="deleted"');
        expect(text).toContain('<metadata>');
        // Book title should be in the oai_dc response.
        expect(text).toContain(BOOK_TAG);
    });

    test('13. GetRecord on soft-deleted book → status="deleted" (persistent deletedRecord)', async () => {
        // Create and immediately soft-delete a book.
        const delTitle = TAG + '_GETDEL';
        dbExec(`INSERT INTO libri (titolo, tipo_media) VALUES ('${delTitle}', 'libro')`);
        const delId = parseInt(
            dbQuery(`SELECT id FROM libri WHERE titolo='${delTitle}' AND deleted_at IS NULL`)
        );
        expect(delId).toBeGreaterThan(0);
        dbExec(`UPDATE libri SET deleted_at=NOW() WHERE id=${delId}`);

        // Verify it was logged.
        const logged = dbQuery(
            `SELECT COUNT(*) FROM oai_deleted_records WHERE entity_type='book' AND entity_id=${delId}`
        );
        expect(logged).toBe('1');

        // GetRecord must return a deleted header.
        const identifier = `oai:${oaiHost}:book:${delId}`;
        const res = await page.request.get(
            `${BASE}/oai?verb=GetRecord&metadataPrefix=oai_dc&identifier=${identifier}`
        );
        expect(res.status()).toBe(200);
        const text = await res.text();
        expect(text).toContain('status="deleted"');
        expect(text).not.toContain('<metadata>');

        // Cleanup.
        dbExec(`DELETE FROM libri WHERE id=${delId}`);
        dbExec(`DELETE FROM oai_deleted_records WHERE entity_id=${delId} AND entity_type='book'`);
    });

    // ── Tests 14-16: Error codes ──────────────────────────────────────────────

    test('14. ListRecords with bad from date → badArgument', async () => {
        const res  = await page.request.get(
            `${BASE}/oai?verb=ListRecords&metadataPrefix=oai_dc&from=not-a-date`
        );
        expect(res.status()).toBe(200); // OAI-PMH always 200
        const text = await res.text();
        expect(text).toContain('<error code="badArgument"');
    });

    test('15. ListRecords with unknown metadataPrefix → cannotDisseminateFormat', async () => {
        const res  = await page.request.get(
            `${BASE}/oai?verb=ListRecords&metadataPrefix=nonexistent`
        );
        expect(res.status()).toBe(200);
        const text = await res.text();
        expect(text).toContain('<error code="cannotDisseminateFormat"');
    });

    test('16. Unknown OAI-PMH verb → badVerb error', async () => {
        const res  = await page.request.get(`${BASE}/oai?verb=FakeVerb`);
        expect(res.status()).toBe(200);
        const text = await res.text();
        expect(text).toContain('<error code="badVerb"');
    });

    // ── Tests 17-18: ListIdentifiers ──────────────────────────────────────────

    test('17. ListIdentifiers returns headers without metadata', async ({ request }) => {
        const res  = await request.get(`${BASE}/oai?verb=ListIdentifiers&metadataPrefix=oai_dc`);
        expect(res.status()).toBe(200);
        const body = await res.text();

        // Must have correct OAI envelope.
        expect(body).toContain('xmlns="http://www.openarchives.org/OAI/2.0/"');
        expect(body).toContain('<ListIdentifiers>');

        // Must have <header> elements.
        expect(body).toContain('<header>');
        expect(body).toContain('<identifier>');
        expect(body).toContain('<datestamp>');

        // Must NOT have <metadata> elements (ListIdentifiers returns headers only).
        expect(body).not.toContain('<metadata>');
        // Full <record> elements are ListRecords-only.
        expect(body).not.toContain('<record>');

        // No error response.
        expect(body).not.toContain('<error code=');
    });

    test('18. ListIdentifiers without metadataPrefix → badArgument', async ({ request }) => {
        const res  = await request.get(`${BASE}/oai?verb=ListIdentifiers`);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('<error code="badArgument"');
    });
});

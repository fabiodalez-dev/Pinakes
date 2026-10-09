// @ts-check
/**
 * Archives plugin — the `published` flag ("Pubblicata nel sito").
 *
 * An unpublished unit stays visible in the admin (with a "Non pubblicata"
 * badge) but disappears from every public / harvest surface:
 *   - the public detail page (rendered as a 404 inside the site layout)
 *   - /archives/{id}/ead.xml, dc.xml, mets.xml, manifest.json
 *   - the catalogue's "also found in the archive" results
 *   - OAI-PMH GetRecord and SRU searchRetrieve
 *   - the public document route /archives/{id}/documents/{fileId}
 * and publishing it again restores all of them. The checkbox is driven
 * through the real admin create and edit forms.
 *
 * Run:
 *   /tmp/run-e2e.sh tests/archives-published.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const PUBLIC_DIR = path.resolve(__dirname, '..', 'public');

function mysqlArgs(sql, batch = false) {
    const args = [];
    if (DB_HOST) {
        args.push('-h', DB_HOST);
        if (DB_PORT) args.push('-P', DB_PORT);
    } else if (DB_SOCKET) {
        args.push('-S', DB_SOCKET);
    }
    // utf8mb4 on the wire: the fixture file name carries a non-ASCII letter.
    args.push('--default-character-set=utf8mb4', '-u', DB_USER, DB_NAME);
    if (batch) args.push('-N', '-B');
    if (sql !== '') args.push('-e', sql);
    return args;
}
function dbQuery(sql) {
    return execFileSync('mysql', mysqlArgs(sql, true), {
        encoding: 'utf-8', timeout: 10000,
        env: { ...process.env, MYSQL_PWD: DB_PASS },
    }).trim();
}
function dbExec(sql) {
    execFileSync('mysql', mysqlArgs(sql), {
        encoding: 'utf-8', timeout: 10000,
        env: { ...process.env, MYSQL_PWD: DB_PASS },
    });
}

const STAMP = Date.now();
const REF = `E2E_PUB_${STAMP}`;
const TITLE = `Fondo pubblicazione ${STAMP}`;
const DOC_NAME = `verbale è ${STAMP}.pdf`;

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME, 'Missing E2E env (ADMIN_EMAIL/PASS, DB_*)');

test.describe.serial('Archives — published flag on public surfaces', () => {
    /** @type {import('@playwright/test').BrowserContext} */
    let context;
    /** @type {import('@playwright/test').Page} */
    let page;
    let unitId = 0;
    let fileId = 0;
    /** The stored path of the uploaded document (/uploads/archives/documents/…). */
    let DOC_REL = '';

    /** Every public surface of the unit, fetched without a session. */
    async function publicStatuses(request) {
        const urls = {
            page: `${BASE}/archive/${unitId}`,
            ead: `${BASE}/archives/${unitId}/ead.xml`,
            dc: `${BASE}/archives/${unitId}/dc.xml`,
            mets: `${BASE}/archives/${unitId}/mets.xml`,
            manifest: `${BASE}/archives/${unitId}/manifest.json`,
            document: `${BASE}/archives/${unitId}/documents/${fileId}`,
            documentLocalised: `${BASE}/archivio/${unitId}/documents/${fileId}`,
        };
        const out = {};
        for (const [key, url] of Object.entries(urls)) {
            const res = await request.get(url, { maxRedirects: 5 });
            out[key] = res.status();
        }
        return out;
    }

    /** Does the catalogue's "also found in the archive" block link the unit? */
    async function catalogueHasUnit(request) {
        const res = await request.get(`${BASE}/catalogo?q=${encodeURIComponent(TITLE)}`);
        expect(res.status()).toBe(200);
        // The search term itself is echoed back in the page, so look for the
        // archive result link (/<archive base>/<slug>-<id>), not the title.
        return new RegExp(`href="[^"]*/(?:archivio|archive|archiv|archives|arkiv)/[a-z0-9-]*-${unitId}"`).test(await res.text());
    }

    async function oaiHasUnit(request) {
        const res = await request.get(`${BASE}/archives/oai?verb=GetRecord&metadataPrefix=oai_dc&identifier=oai:pinakes:archival_unit:${unitId}`);
        const body = await res.text();
        return !body.includes('idDoesNotExist') && body.includes(TITLE);
    }

    async function sruCount(request) {
        const res = await request.get(`${BASE}/api/archives/sru?operation=searchRetrieve&query=${encodeURIComponent(`reference="${REF}"`)}`);
        const m = (await res.text()).match(/<sru:numberOfRecords>(\d+)<\/sru:numberOfRecords>/);
        return m ? Number(m[1]) : -1;
    }

    async function setPublishedViaEditForm(checked) {
        await page.goto(`${BASE}/admin/archives/${unitId}/edit`);
        const box = page.locator('input[type="checkbox"][name="published"]');
        await expect(box).toBeVisible();
        if (checked) { await box.check(); } else { await box.uncheck(); }
        await Promise.all([
            page.waitForURL(new RegExp(`/admin/archives/${unitId}$`), { timeout: 15000 }),
            page.locator('form.archive-record-form button[type="submit"]').click(),
        ]);
    }

    test.beforeAll(async ({ browser }) => {
        context = await browser.newContext();
        page = await context.newPage();
        await page.goto(`${BASE}/login`);
        await page.fill('input[name="email"]', ADMIN_EMAIL);
        await page.fill('input[name="password"]', ADMIN_PASS);
        await Promise.all([
            page.waitForURL(/\/admin\//, { timeout: 15000 }),
            page.click('button[type="submit"]'),
        ]);
        // Visiting the plugin list runs the bundled-plugin self-heal, which
        // adds the `published` column on an install that predates it.
        await page.goto(`${BASE}/admin/plugins`);
        expect(dbQuery("SELECT is_active FROM plugins WHERE name = 'archives'")).toBe('1');
        expect(dbQuery(`SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'archival_units' AND COLUMN_NAME = 'published'`)).toBe('1');
    });

    test.afterAll(async () => {
        try {
            if (unitId > 0) {
                dbExec(`DELETE FROM archival_unit_files WHERE unit_id = ${unitId}`);
            }
            dbExec(`DELETE FROM archival_units WHERE reference_code = '${REF}'`);
        } catch { /* best-effort */ }
        // Owned by the web server in CI: best effort only.
        if (DOC_REL) { try { fs.unlinkSync(path.join(PUBLIC_DIR, DOC_REL)); } catch { /* not ours to delete */ } }
        await context?.close();
    });

    test('1. create an unpublished unit from the admin form', async () => {
        await page.goto(`${BASE}/admin/archives/new`);
        const box = page.locator('input[type="checkbox"][name="published"]');
        await expect(box).toBeChecked(); // new units default to published
        await page.fill('input[name="reference_code"]', REF);
        await page.selectOption('select[name="level"]', 'fonds');
        await page.fill('input[name="constructed_title"]', TITLE);
        await box.uncheck();
        await Promise.all([
            page.waitForURL(/\/admin\/archives$/, { timeout: 15000 }),
            page.locator('form.archive-record-form button[type="submit"]').click(),
        ]);
        unitId = Number(dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${REF}' AND deleted_at IS NULL`));
        expect(unitId).toBeGreaterThan(0);
        expect(dbQuery(`SELECT published FROM archival_units WHERE id = ${unitId}`)).toBe('0');

        // A real document, uploaded through the admin form as a librarian
        // does (the web server owns the upload folder; the test runner may
        // not be able to write there), so the document route has something
        // to stream once the unit is published.
        await page.goto(`${BASE}/admin/archives/${unitId}`);
        const upload = page.locator('form[action*="upload-document"]');
        await upload.locator('input[name="document"]').setInputFiles({
            name: DOC_NAME,
            mimeType: 'application/pdf',
            buffer: Buffer.from('%PDF-1.4\n% e2e published-flag fixture\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n'),
        });
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }),
            upload.locator('button[type="submit"]').click(),
        ]);
        // One column per value: batch mode escapes a tab inside a value.
        const row = dbQuery(`SELECT id, file_path, original_filename FROM archival_unit_files WHERE unit_id = ${unitId} ORDER BY id DESC LIMIT 1`).split('\t');
        fileId = Number(row[0]);
        DOC_REL = row[1] || '';
        expect(fileId).toBeGreaterThan(0);
        expect(DOC_REL).toMatch(/^\/uploads\/archives\/documents\//);
        expect(row[2]).toBe(DOC_NAME);
    });

    test('2. the admin still lists it, with a "Non pubblicata" badge', async () => {
        await page.goto(`${BASE}/admin/archives`);
        const row = page.locator('tr', { hasText: TITLE });
        await expect(row).toHaveCount(1);
        await expect(row.locator('.archive-unpublished-badge')).toHaveCount(1);
        const res = await page.request.get(`${BASE}/admin/archives/${unitId}/ead.xml`);
        expect(res.status(), 'admin export still works for an unpublished unit').toBe(200);
    });

    test('3. every public surface answers 404 while unpublished', async ({ request }) => {
        const statuses = await publicStatuses(request);
        expect(statuses).toEqual({
            page: 404, ead: 404, dc: 404, mets: 404, manifest: 404, document: 404, documentLocalised: 404,
        });
        // The public 404 is the site's own page inside the frontend layout.
        const res = await request.get(`${BASE}/archive/${unitId}`);
        const html = await res.text();
        expect(html).toContain('class="footer"');
        expect(html).toContain('error-404-title');
        expect(html).not.toContain(TITLE);

        expect(await catalogueHasUnit(request), 'catalogue archive results must not list it').toBe(false);
        expect(await oaiHasUnit(request), 'OAI GetRecord must not disseminate it').toBe(false);
        expect(await sruCount(request), 'SRU must not return it').toBe(0);
    });

    test('4. publishing it from the edit form restores every surface', async ({ request }) => {
        await setPublishedViaEditForm(true);
        expect(dbQuery(`SELECT published FROM archival_units WHERE id = ${unitId}`)).toBe('1');

        const statuses = await publicStatuses(request);
        expect(statuses).toEqual({
            page: 200, ead: 200, dc: 200, mets: 200, manifest: 200, document: 200, documentLocalised: 200,
        });
        expect(await catalogueHasUnit(request)).toBe(true);
        expect(await oaiHasUnit(request)).toBe(true);
        expect(await sruCount(request)).toBe(1);

        // The document route streams the stored file with safe headers.
        const doc = await request.get(`${BASE}/archives/${unitId}/documents/${fileId}`);
        expect(doc.headers()['content-type']).toBe('application/pdf');
        expect(doc.headers()['x-content-type-options']).toBe('nosniff');
        const disposition = doc.headers()['content-disposition'] || '';
        expect(disposition).toMatch(/^inline; filename="[A-Za-z0-9._-]+"; filename\*=UTF-8''/);
        expect(disposition).toContain(encodeURIComponent(DOC_NAME));
        expect((await doc.body()).toString()).toContain('e2e published-flag fixture');

        // A file of another unit is not served under this unit's id.
        const otherId = Number(dbQuery(`SELECT id FROM archival_unit_files WHERE unit_id <> ${unitId} LIMIT 1`) || '0');
        if (otherId > 0) {
            expect((await request.get(`${BASE}/archives/${unitId}/documents/${otherId}`)).status()).toBe(404);
        }

        // Public page, manifest and MARC link the route, never /uploads.
        const pageHtml = await (await request.get(`${BASE}/archive/${unitId}`, { maxRedirects: 5 })).text();
        expect(pageHtml).toContain(`/archives/${unitId}/documents/${fileId}`);
        expect(pageHtml).not.toContain(DOC_REL);
        const manifest = await (await request.get(`${BASE}/archives/${unitId}/manifest.json`)).text();
        expect(manifest).toContain(`/archives/${unitId}/documents/${fileId}`);
        expect(manifest).not.toContain(DOC_REL);
        const marc = await (await page.request.get(`${BASE}/admin/archives/${unitId}/export.xml`)).text();
        expect(marc).toMatch(new RegExp(`<datafield tag="856" ind1="4" ind2=" ">\\s*<subfield code="u">[^<]*/archives/${unitId}/documents/${fileId}</subfield>`));
    });

    test('5. unpublishing again from the edit form withdraws it again', async ({ request }) => {
        await setPublishedViaEditForm(false);
        expect(dbQuery(`SELECT published FROM archival_units WHERE id = ${unitId}`)).toBe('0');
        const statuses = await publicStatuses(request);
        expect(Object.values(statuses).every((s) => s === 404), JSON.stringify(statuses)).toBe(true);
        expect(await catalogueHasUnit(request)).toBe(false);
    });

    test('6. an unrelated edit without the field keeps the stored visibility', async () => {
        // A POST that does not carry `published` (API caller, older form)
        // must not silently republish the unit.
        await page.goto(`${BASE}/admin/archives/${unitId}/edit`);
        const token = await page.locator('form.archive-record-form input[name="csrf_token"]').inputValue();
        const res = await page.request.post(`${BASE}/admin/archives/${unitId}/edit`, {
            form: {
                csrf_token: token,
                reference_code: REF,
                level: 'fonds',
                constructed_title: TITLE,
                language_codes: 'ita',
            },
            maxRedirects: 0,
        });
        expect(res.status()).toBe(303);
        expect(dbQuery(`SELECT published FROM archival_units WHERE id = ${unitId}`)).toBe('0');
    });
});

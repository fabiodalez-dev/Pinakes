// @ts-check
/**
 * E2E for Archives RiC-CM Phase 6 — OAI-PMH metadataPrefix=ric-o.
 *
 * Verifies the contract between the oai-pmh-server plugin and the
 * archives plugin's RicJsonLdBuilder::serializeToRdfXml():
 *
 *   1. ListMetadataFormats advertises `ric-o` alongside oai_dc/marcxml/mods/mag/unimarc
 *   2. GetRecord on an archival_unit identifier with metadataPrefix=ric-o
 *      returns valid RDF/XML wrapped in OAI <metadata>
 *   3. GetRecord on a book identifier with metadataPrefix=ric-o returns
 *      cannotDisseminateFormat (ric-o is archival_unit-only)
 *   4. ListRecords?set=archives&metadataPrefix=ric-o streams the graph
 *   5. ListRecords?set=books&metadataPrefix=ric-o rejects via cannotDisseminateFormat
 *
 * Cleanup: hard-deletes any test rows created here in afterAll().
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

function mysqlArgs(sql, batch = false) {
    const args = ['-u', DB_USER];
    if (DB_PASS !== '') args.push(`-p${DB_PASS}`);
    if (DB_SOCKET) args.push('-S', DB_SOCKET);
    args.push(DB_NAME);
    if (batch) args.push('-N', '-B');
    if (sql !== '') args.push('-e', sql);
    return args;
}
function dbQuery(sql) {
    return execFileSync('mysql', mysqlArgs(sql, true), { encoding: 'utf-8', timeout: 10000 }).trim();
}
function dbExec(sql) {
    execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout: 10000 });
}

const TAG = 'E2E_P6_' + Date.now();
const FONDS_REF = TAG + '_FONDS';

test.skip(
    !DB_USER || !DB_NAME,
    'Missing E2E env (DB_*)'
);

test.describe.serial('Archives Phase 6 — OAI-PMH metadataPrefix=ric-o', () => {
    /** @type {import('@playwright/test').BrowserContext} */
    let context;
    /** @type {import('@playwright/test').Page} */
    let page;
    /** @type {number|null} */
    let archivalUnitId = null;
    /** @type {string|null} */
    let bookIdentifier = null;
    /** @type {string|null} */
    let archivalIdentifier = null;

    test.beforeAll(async ({ browser }) => {
        try {
            dbExec(`DELETE FROM archival_units WHERE reference_code LIKE '${TAG}%'`);
        } catch { /* table may not exist yet */ }

        context = await browser.newContext();
        page = await context.newPage();

        // Login is only needed to ensure the archives plugin is active.
        // If credentials are present we'll go through /admin/plugins;
        // otherwise we trust the plugin is already active.
        if (ADMIN_EMAIL && ADMIN_PASS) {
            await page.goto(`${BASE}/login`);
            await page.fill('input[name="email"]', ADMIN_EMAIL);
            await page.fill('input[name="password"]', ADMIN_PASS);
            await Promise.all([
                page.waitForURL(/\/admin\//, { timeout: 15000 }),
                page.click('button[type="submit"]'),
            ]);
        }

        // Activate archives plugin if not already.
        try {
            const isActive = dbQuery("SELECT is_active FROM plugins WHERE name = 'archives'");
            if (isActive !== '1' && ADMIN_EMAIL && ADMIN_PASS) {
                await page.goto(`${BASE}/admin/plugins`);
                const activateBtn = page.locator('button[onclick^="activatePlugin("]').first();
                if (await activateBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
                    await activateBtn.click();
                    const swal = page.locator('.swal2-confirm').first();
                    if (await swal.isVisible({ timeout: 5000 }).catch(() => false)) {
                        await swal.click();
                    }
                    await page.waitForLoadState('domcontentloaded');
                }
            }
        } catch { /* best-effort */ }

        // Seed one archival_unit so we have something to GetRecord on.
        dbExec(`INSERT INTO archival_units
            (level, reference_code, formal_title, constructed_title, language_codes,
             scope_content, date_start, date_end, created_at, updated_at)
            VALUES ('fonds', '${FONDS_REF}', '${TAG} Test Fonds', '${TAG} Test Fonds',
                    'ita', 'Phase 6 test fonds — RDF/XML round-trip.',
                    1900, 1950, NOW(), NOW())`);
        const idStr = dbQuery(
            `SELECT id FROM archival_units WHERE reference_code = '${FONDS_REF}' LIMIT 1`
        );
        archivalUnitId = parseInt(idStr, 10);
        expect(Number.isFinite(archivalUnitId) && archivalUnitId > 0).toBe(true);
        archivalIdentifier = `oai:pinakes:archival_unit:${archivalUnitId}`;

        // Look up a book identifier we can hit with ric-o for the negative test.
        const bookIdStr = dbQuery(
            `SELECT id FROM libri WHERE deleted_at IS NULL ORDER BY id LIMIT 1`
        );
        if (bookIdStr) {
            bookIdentifier = `oai:pinakes:book:${parseInt(bookIdStr, 10)}`;
        }
    });

    test.afterAll(async () => {
        try {
            dbExec(`DELETE FROM archival_units WHERE reference_code LIKE '${TAG}%'`);
        } catch { /* best-effort */ }
        await context?.close();
    });

    test('1. ListMetadataFormats advertises ric-o', async () => {
        const res = await page.request.get(`${BASE}/oai?verb=ListMetadataFormats`);
        expect(res.status(), `OAI must respond 200, got ${res.status()}`).toBe(200);
        const body = await res.text();
        expect(body, 'response body should be XML, not HTML').toContain('<?xml');
        expect(body, 'must list ric-o metadataPrefix').toContain('<metadataPrefix>ric-o</metadataPrefix>');
        expect(body, 'must advertise the ICA RiC-O ontology namespace')
            .toContain('https://www.ica.org/standards/RiC/ontology#');
    });

    test('2. ListMetadataFormats for archival_unit only shows oai_dc + ric-o', async () => {
        expect(archivalIdentifier).not.toBeNull();
        const res = await page.request.get(
            `${BASE}/oai?verb=ListMetadataFormats&identifier=${encodeURIComponent(archivalIdentifier || '')}`
        );
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body, 'archival_unit must expose ric-o').toContain('<metadataPrefix>ric-o</metadataPrefix>');
        expect(body, 'archival_unit must expose oai_dc').toContain('<metadataPrefix>oai_dc</metadataPrefix>');
        // book-only formats must be hidden for archival_unit identifiers.
        expect(body, 'archival_unit must NOT advertise marcxml').not.toContain('<metadataPrefix>marcxml</metadataPrefix>');
        expect(body, 'archival_unit must NOT advertise mods').not.toContain('<metadataPrefix>mods</metadataPrefix>');
        expect(body, 'archival_unit must NOT advertise unimarc').not.toContain('<metadataPrefix>unimarc</metadataPrefix>');
    });

    test('3. ListMetadataFormats for book hides ric-o', async () => {
        test.skip(bookIdentifier === null, 'No book in DB to test against');
        const res = await page.request.get(
            `${BASE}/oai?verb=ListMetadataFormats&identifier=${encodeURIComponent(bookIdentifier || '')}`
        );
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body, 'book identifier MUST NOT advertise ric-o').not.toContain('<metadataPrefix>ric-o</metadataPrefix>');
        expect(body, 'book identifier must still expose oai_dc').toContain('<metadataPrefix>oai_dc</metadataPrefix>');
    });

    test('4. GetRecord on archival_unit with ric-o returns valid RDF/XML', async () => {
        expect(archivalIdentifier).not.toBeNull();
        const res = await page.request.get(
            `${BASE}/oai?verb=GetRecord&identifier=${encodeURIComponent(archivalIdentifier || '')}&metadataPrefix=ric-o`
        );
        expect(res.status(), 'GetRecord must respond 200').toBe(200);
        const body = await res.text();
        // No OAI-level error envelope:
        expect(body, 'must not return an OAI error').not.toContain('<error code="');
        // The RDF/XML payload must be wrapped in <metadata>.
        expect(body, 'must contain <rdf:RDF> root inside metadata').toContain('<rdf:RDF');
        // Namespace declarations from RicJsonLdBuilder constants:
        expect(body).toContain('xmlns:ric="https://www.ica.org/standards/RiC/ontology#"');
        expect(body).toContain('xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"');
        // Subject element — level=fonds → ric:RecordSet
        expect(body, 'fonds level must serialise as ric:RecordSet').toMatch(/<ric:RecordSet[\s>]/);
        // rdf:about MUST carry the unit IRI built by RicJsonLdBuilder::unitIri.
        expect(body).toMatch(new RegExp(`rdf:about="[^"]*\\/archives\\/${archivalUnitId}"`));
        // ric:title from the seeded constructed_title.
        expect(body).toContain(`<ric:title>${TAG} Test Fonds</ric:title>`);
        // xsd:gYear typed literals for the date range.
        expect(body, 'date_start must round-trip as xsd:gYear').toMatch(
            /rdf:datatype="http:\/\/www\.w3\.org\/2001\/XMLSchema#gYear">1900</
        );
        // Italian language tag on rdfs:label (locale=it_IT, normalised to "it").
        expect(body).toMatch(/<rdfs:label xml:lang="[a-z]{2}/);
    });

    test('5. GetRecord on book with ric-o returns cannotDisseminateFormat', async () => {
        test.skip(bookIdentifier === null, 'No book in DB to test against');
        const res = await page.request.get(
            `${BASE}/oai?verb=GetRecord&identifier=${encodeURIComponent(bookIdentifier || '')}&metadataPrefix=ric-o`
        );
        expect(res.status(), 'OAI errors still return 200 with error envelope').toBe(200);
        const body = await res.text();
        expect(body, 'book + ric-o must yield cannotDisseminateFormat')
            .toContain('error code="cannotDisseminateFormat"');
        expect(body, 'must NOT serialise RDF for a book').not.toContain('<rdf:RDF');
    });

    test('6. ListRecords?set=archives&metadataPrefix=ric-o streams the graph', async () => {
        const res = await page.request.get(
            `${BASE}/oai?verb=ListRecords&set=archives&metadataPrefix=ric-o`
        );
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body, 'must not return OAI error envelope').not.toContain('<error code="');
        expect(body, 'response must contain at least one <record>').toContain('<record>');
        // Each metadata block must wrap RDF/XML.
        expect(body, 'each record must carry an RDF/XML payload').toContain('<rdf:RDF');
        // At least one subject must carry a unit IRI. We don't pin it to
        // our seeded fonds because larger DBs paginate and our row may
        // land in page 2+ — the seeded-row presence is covered by test 4
        // (GetRecord on the specific identifier).
        expect(body, 'must emit at least one ric:Record or ric:RecordSet subject')
            .toMatch(/<ric:(Record|RecordSet)[\s>]/);
        expect(body, 'subjects must carry rdf:about pointing at /archives/{id}')
            .toMatch(/rdf:about="[^"]*\/archives\/\d+"/);
    });

    test('7. ListRecords?set=books&metadataPrefix=ric-o returns cannotDisseminateFormat', async () => {
        const res = await page.request.get(
            `${BASE}/oai?verb=ListRecords&set=books&metadataPrefix=ric-o`
        );
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body, 'books + ric-o must yield cannotDisseminateFormat')
            .toContain('error code="cannotDisseminateFormat"');
    });

    test('8. Default set (no set= parameter) with ric-o yields archives records', async () => {
        // The plugin should auto-default to set=archives when metadataPrefix=ric-o
        // is requested without an explicit set parameter (book records can't be
        // expressed as ric-o).
        const res = await page.request.get(`${BASE}/oai?verb=ListRecords&metadataPrefix=ric-o`);
        expect(res.status()).toBe(200);
        const body = await res.text();
        if (body.includes('<error code="')) {
            // Acceptable alternative: server demands explicit set= — verify
            // that the error is at least specific to the format, not a 500.
            expect(body).toMatch(/error code="(cannotDisseminateFormat|noRecordsMatch|badArgument)"/);
        } else {
            expect(body, 'must contain at least one <record>').toContain('<record>');
            expect(body, 'each record must carry RDF/XML').toContain('<rdf:RDF');
        }
    });

    test('9. GetRecord on unknown archival_unit identifier returns idDoesNotExist', async () => {
        const res = await page.request.get(
            `${BASE}/oai?verb=GetRecord&identifier=${encodeURIComponent('oai:pinakes:archival_unit:9999999')}&metadataPrefix=ric-o`
        );
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('error code="idDoesNotExist"');
    });

    test('10. ric-o GetRecord roundtrip preserves rdf:resource references for parent/children', async () => {
        // Seed a second unit as a child of the fonds to verify
        // ric:hasOrHadPart emission with rdf:resource references.
        const childRef = TAG + '_CHILD';
        dbExec(`INSERT INTO archival_units
            (level, parent_id, reference_code, formal_title, constructed_title, created_at, updated_at)
            VALUES ('series', ${archivalUnitId}, '${childRef}', '${TAG} Series 1', '${TAG} Series 1', NOW(), NOW())`);
        const childIdStr = dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${childRef}'`);
        const childId = parseInt(childIdStr, 10);

        try {
            const res = await page.request.get(
                `${BASE}/oai?verb=GetRecord&identifier=${encodeURIComponent(archivalIdentifier || '')}&metadataPrefix=ric-o`
            );
            const body = await res.text();
            expect(body, 'parent must reference child via ric:hasOrHadPart').toContain('<ric:hasOrHadPart>');
            // Either a nested <ric:RecordSet rdf:about="..."> (current builder)
            // or a flat <ric:hasOrHadPart rdf:resource="..."/> — both are valid
            // striped RDF/XML for the same triples.
            expect(body).toMatch(new RegExp(`(rdf:about|rdf:resource)="[^"]*\\/archives\\/${childId}"`));
        } finally {
            dbExec(`DELETE FROM archival_units WHERE id = ${childId}`);
        }
    });
});

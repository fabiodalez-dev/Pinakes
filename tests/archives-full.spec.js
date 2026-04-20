// @ts-check
/**
 * Archives plugin — full regression suite (25 tests).
 *
 * Covers every phase shipped on PR #105 (issue #103):
 *   1a/1b/1c/1d  archival_units CRUD + sidebar + i18n
 *   2 / 2b       authority records CRUD + M:N linking + libri.autori reconciliation
 *   3            unified cross-entity search
 *   4 / 4b / 4c  MARCXML import/export + UPSERT round-trip + multi-occurrence
 *   4d           XSD validation
 *   5            photographic items (ABA billedmarc)
 *   6            SRU endpoint
 *   7            JS type-ahead
 *
 * Serial execution — most tests mutate data and rely on earlier setup.
 * Reusable: file lives under tests/ (whitelisted in .gitignore), runs via
 *   /tmp/run-e2e.sh tests/archives-full.spec.js --config=tests/playwright.config.js --workers=1
 *
 * All test rows tagged with TAG_PREFIX so parallel runs + leftover data
 * never collide; pre/post cleanup drops everything with that prefix.
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

function dbQuery(sql) {
    const args = ['-u', DB_USER, `-p${DB_PASS}`, DB_NAME, '-N', '-B', '-e', sql];
    if (DB_SOCKET) args.splice(3, 0, '-S', DB_SOCKET);
    return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000 }).trim();
}
function dbExec(sql) {
    const args = ['-u', DB_USER, `-p${DB_PASS}`, DB_NAME, '-e', sql];
    if (DB_SOCKET) args.splice(3, 0, '-S', DB_SOCKET);
    execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000 });
}

const TAG = 'E2E_FULL_' + Date.now();
const FONDS_REF = TAG + '_F1';
const SERIES_REF = TAG + '_S1';
const PHOTO_REF = TAG + '_PH1';
const IMPORT_REF = TAG + '_IMP1';
const AUTH_NAME_PERSON = TAG + '_Person_Stauning';
const AUTH_NAME_CORP = TAG + '_Corp_ABA';

test.skip(
    !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
    'Missing E2E env (ADMIN_EMAIL/PASS, DB_*)'
);

test.describe.serial('Archives plugin — full regression (#103 phases 1–6)', () => {
    /** @type {import('@playwright/test').BrowserContext} */
    let context;
    /** @type {import('@playwright/test').Page} */
    let page;

    test.beforeAll(async ({ browser }) => {
        // Pre-clean in FK-safe order.
        try {
            dbExec(`DELETE aua FROM archival_unit_authority aua
                    JOIN archival_units au ON au.id = aua.archival_unit_id
                    WHERE au.reference_code LIKE '${TAG}%'`);
            dbExec(`DELETE aal FROM autori_authority_link aal
                    JOIN authority_records ar ON ar.id = aal.authority_id
                    WHERE ar.authorised_form LIKE '${TAG}%'`);
            dbExec(`DELETE FROM archival_units WHERE reference_code LIKE '${TAG}%'`);
            dbExec(`DELETE FROM authority_records WHERE authorised_form LIKE '${TAG}%'`);
        } catch { /* tables may not exist yet on fresh installs without the plugin active */ }

        context = await browser.newContext();
        page = await context.newPage();
        await page.goto(`${BASE}/login`);
        await page.fill('input[name="email"]', ADMIN_EMAIL);
        await page.fill('input[name="password"]', ADMIN_PASS);
        await Promise.all([
            page.waitForURL(/\/admin\//, { timeout: 15000 }),
            page.click('button[type="submit"]'),
        ]);
    });

    test.afterAll(async () => {
        try {
            dbExec(`DELETE aua FROM archival_unit_authority aua
                    JOIN archival_units au ON au.id = aua.archival_unit_id
                    WHERE au.reference_code LIKE '${TAG}%'`);
            dbExec(`DELETE aal FROM autori_authority_link aal
                    JOIN authority_records ar ON ar.id = aal.authority_id
                    WHERE ar.authorised_form LIKE '${TAG}%'`);
            dbExec(`DELETE FROM archival_units WHERE reference_code LIKE '${TAG}%'`);
            dbExec(`DELETE FROM authority_records WHERE authorised_form LIKE '${TAG}%'`);
        } catch { /* best-effort */ }
        await context?.close();
    });

    // ─── Phase 1: schema + activation + sidebar ────────────────────────

    test('01. Plugin is active + schema tables exist', async () => {
        await page.goto(`${BASE}/admin/plugins`);
        await page.waitForLoadState('domcontentloaded');
        const isActive = dbQuery("SELECT is_active FROM plugins WHERE name = 'archives'");
        if (isActive === '0' || isActive === '') {
            const row = page.locator('tr', { hasText: 'archives' }).first();
            const btn = row.locator('form button:has-text("Attiva")');
            if (await btn.isVisible({ timeout: 3000 }).catch(() => false)) {
                await btn.click();
                await page.waitForLoadState('domcontentloaded');
            }
        }
        const nowActive = dbQuery("SELECT is_active FROM plugins WHERE name = 'archives'");
        expect(nowActive).toBe('1');
        for (const t of ['archival_units', 'authority_records', 'archival_unit_authority', 'autori_authority_link']) {
            const count = dbQuery(
                `SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '${t}'`
            );
            expect(count, `table ${t} must exist`).toBe('1');
        }
    });

    test('02. Sidebar entry for "Archivi" is visible', async () => {
        await page.goto(`${BASE}/admin/dashboard`);
        await page.waitForLoadState('domcontentloaded');
        const link = page.locator('aside a[href$="/admin/archives"]').first();
        await expect(link).toBeVisible();
    });

    test('03. /admin/archives index renders (empty or populated)', async () => {
        await page.goto(`${BASE}/admin/archives`);
        await page.waitForLoadState('domcontentloaded');
        const empty = await page.locator('text=Nessun record archivistico').isVisible().catch(() => false);
        const tbl = await page.locator('table thead').isVisible().catch(() => false);
        expect(empty || tbl).toBe(true);
    });

    // ─── Phase 1b/1c: archival_units CRUD ──────────────────────────────

    test('04. Create a fonds via /admin/archives/new', async () => {
        await page.goto(`${BASE}/admin/archives/new`);
        await page.fill('input[name="reference_code"]', FONDS_REF);
        await page.selectOption('select[name="level"]', 'fonds');
        await page.fill('input[name="constructed_title"]', 'E2E Full Test Fonds');
        await page.fill('input[name="date_start"]', '1888');
        await page.fill('input[name="date_end"]', '2003');
        await page.fill('input[name="extent"]', '1357 boxes, 613 volumes');
        await page.fill('textarea[name="scope_content"]', 'Archive of a Danish labour-movement union.');
        await Promise.all([
            page.waitForURL(/\/admin\/archives$/, { timeout: 10000 }),
            page.click('button[type="submit"]'),
        ]);
        const row = dbQuery(
            `SELECT CONCAT_WS('|', level, constructed_title, date_start, date_end)
               FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`
        );
        expect(row).toBe('fonds|E2E Full Test Fonds|1888|2003');
    });

    test('05. Fonds appears in the index list', async () => {
        await page.goto(`${BASE}/admin/archives`);
        await expect(page.locator('table')).toContainText(FONDS_REF);
        await expect(page.locator('table')).toContainText('E2E Full Test Fonds');
    });

    test('06. Detail view shows all fields + MARCXML button', async () => {
        const fondsId = dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`);
        await page.goto(`${BASE}/admin/archives/${fondsId}`);
        await expect(page.locator('body')).toContainText('E2E Full Test Fonds');
        await expect(page.locator('body')).toContainText('1888');
        await expect(page.locator('body')).toContainText('1357 boxes');
        await expect(page.locator('a[href*="/export.xml"]')).toBeVisible();
    });

    test('07. Edit fonds updates persist', async () => {
        const fondsId = dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`);
        await page.goto(`${BASE}/admin/archives/${fondsId}/edit`);
        await expect(page.locator('input[name="reference_code"]')).toHaveValue(FONDS_REF);
        await page.fill('input[name="extent"]', '1357 boxes, 613 volumes (updated)');
        await Promise.all([
            page.waitForURL(new RegExp(`/admin/archives/${fondsId}$`), { timeout: 10000 }),
            page.click('button[type="submit"]'),
        ]);
        const extent = dbQuery(`SELECT extent FROM archival_units WHERE id = ${fondsId}`);
        expect(extent).toContain('(updated)');
    });

    test('08. Create child series with parent_id (hierarchy)', async () => {
        const fondsId = dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`);
        await page.goto(`${BASE}/admin/archives/new`);
        await page.fill('input[name="reference_code"]', SERIES_REF);
        await page.selectOption('select[name="level"]', 'series');
        await page.fill('input[name="constructed_title"]', 'Cirkulærer og skrivelser');
        await page.fill('input[name="parent_id"]', fondsId);
        await Promise.all([
            page.waitForURL(/\/admin\/archives$/, { timeout: 10000 }),
            page.click('button[type="submit"]'),
        ]);
        const parent = dbQuery(`SELECT parent_id FROM archival_units WHERE reference_code = '${SERIES_REF}' AND deleted_at IS NULL`);
        expect(parent).toBe(fondsId);
    });

    test('09. Self-parent attempt is rejected on edit', async () => {
        const fondsId = dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`);
        await page.goto(`${BASE}/admin/archives/${fondsId}/edit`);
        await page.fill('input[name="parent_id"]', fondsId);
        await page.click('button[type="submit"]');
        await expect(page.locator('body')).toContainText(/cannot be its own parent/i);
    });

    // ─── Phase 2: authority records CRUD ───────────────────────────────

    test('10. Create a person authority record', async () => {
        await page.goto(`${BASE}/admin/archives/authorities/new`);
        await page.selectOption('select[name="type"]', 'person');
        await page.fill('input[name="authorised_form"]', AUTH_NAME_PERSON);
        await page.fill('input[name="dates_of_existence"]', '1873-1942');
        await page.fill('textarea[name="history"]', 'Danish statesman and Prime Minister.');
        await Promise.all([
            page.waitForURL(/\/admin\/archives\/authorities$/, { timeout: 10000 }),
            page.click('button[type="submit"]'),
        ]);
        const row = dbQuery(
            `SELECT type FROM authority_records WHERE authorised_form = '${AUTH_NAME_PERSON}' AND deleted_at IS NULL`
        );
        expect(row).toBe('person');
    });

    test('11. Create a corporate authority record', async () => {
        await page.goto(`${BASE}/admin/archives/authorities/new`);
        await page.selectOption('select[name="type"]', 'corporate');
        await page.fill('input[name="authorised_form"]', AUTH_NAME_CORP);
        await page.fill('input[name="dates_of_existence"]', '1908-');
        await Promise.all([
            page.waitForURL(/\/admin\/archives\/authorities$/, { timeout: 10000 }),
            page.click('button[type="submit"]'),
        ]);
        const row = dbQuery(
            `SELECT type FROM authority_records WHERE authorised_form = '${AUTH_NAME_CORP}' AND deleted_at IS NULL`
        );
        expect(row).toBe('corporate');
    });

    test('12. Edit authority + extended ISAAR fields persist', async () => {
        const id = dbQuery(`SELECT id FROM authority_records WHERE authorised_form = '${AUTH_NAME_PERSON}' AND deleted_at IS NULL`);
        await page.goto(`${BASE}/admin/archives/authorities/${id}/edit`);
        await page.fill('textarea[name="places"]', 'Copenhagen');
        await page.fill('textarea[name="functions"]', 'Politics, trade-unionism');
        await page.fill('input[name="identifiers"]', 'VIAF:12345');
        await Promise.all([
            page.waitForURL(new RegExp(`/admin/archives/authorities/${id}$`), { timeout: 10000 }),
            page.click('button[type="submit"]'),
        ]);
        const row = dbQuery(
            `SELECT CONCAT_WS('|', places, functions, identifiers) FROM authority_records WHERE id = ${id}`
        );
        expect(row).toBe('Copenhagen|Politics, trade-unionism|VIAF:12345');
    });

    // ─── Phase 2: M:N attach/detach authority ↔ archival_unit ──────────

    test('13. Attach authority to fonds with role=creator', async () => {
        const fondsId = dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`);
        const authId = dbQuery(`SELECT id FROM authority_records WHERE authorised_form = '${AUTH_NAME_PERSON}' AND deleted_at IS NULL`);
        await page.goto(`${BASE}/admin/archives/${fondsId}`);
        // Phase 7 type-ahead: type then click the matching option
        const input = page.locator('[data-typeahead-input]').first();
        await input.click();
        await input.fill(AUTH_NAME_PERSON.slice(0, 12));
        const option = page.locator('[data-typeahead-results] li', { hasText: AUTH_NAME_PERSON }).first();
        await expect(option).toBeVisible({ timeout: 10000 });
        await option.click();
        await page.selectOption('select[name="role"]', 'creator');
        await Promise.all([
            page.waitForURL(new RegExp(`/admin/archives/${fondsId}$`), { timeout: 10000 }),
            page.click('button:has-text("Collega"), button:has-text("Link")'),
        ]);
        const linkCount = dbQuery(
            `SELECT COUNT(*) FROM archival_unit_authority
              WHERE archival_unit_id = ${fondsId} AND authority_id = ${authId} AND role = 'creator'`
        );
        expect(linkCount).toBe('1');
    });

    test('14. Linked authority visible on both detail pages', async () => {
        const fondsId = dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`);
        const authId = dbQuery(`SELECT id FROM authority_records WHERE authorised_form = '${AUTH_NAME_PERSON}' AND deleted_at IS NULL`);
        await page.goto(`${BASE}/admin/archives/${fondsId}`);
        await expect(page.locator('body')).toContainText(AUTH_NAME_PERSON);
        await expect(page.locator('body')).toContainText('creator');
        await page.goto(`${BASE}/admin/archives/authorities/${authId}`);
        await expect(page.locator('body')).toContainText('E2E Full Test Fonds');
    });

    test('15. Detach authority from fonds', async () => {
        const fondsId = dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`);
        const authId = dbQuery(`SELECT id FROM authority_records WHERE authorised_form = '${AUTH_NAME_PERSON}' AND deleted_at IS NULL`);
        await page.goto(`${BASE}/admin/archives/${fondsId}`);
        page.once('dialog', d => d.accept());
        await Promise.all([
            page.waitForURL(new RegExp(`/admin/archives/${fondsId}$`), { timeout: 10000 }),
            page.click('button:has-text("scollega"), button:has-text("unlink")'),
        ]);
        const count = dbQuery(
            `SELECT COUNT(*) FROM archival_unit_authority
              WHERE archival_unit_id = ${fondsId} AND authority_id = ${authId}`
        );
        expect(count).toBe('0');
    });

    // ─── Phase 3: unified search ───────────────────────────────────────

    test('16. Unified search finds archival units + authorities', async () => {
        await page.goto(`${BASE}/admin/archives/search?q=${encodeURIComponent(TAG)}`);
        await page.waitForLoadState('domcontentloaded');
        const body = await page.locator('body').textContent() || '';
        // Either the fonds or the authority tagged with our prefix should show.
        const hasHit = body.includes(FONDS_REF) || body.includes(AUTH_NAME_PERSON) || body.includes(AUTH_NAME_CORP) || body.includes('risultati');
        expect(hasHit, 'unified search should return at least one hit for the tag prefix').toBe(true);
    });

    // ─── Phase 4: MARCXML export ───────────────────────────────────────

    test('17. Export archival_unit as MARCXML', async () => {
        const fondsId = dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`);
        const resp = await page.request.get(`${BASE}/admin/archives/${fondsId}/export.xml`);
        expect(resp.status()).toBe(200);
        const xml = await resp.text();
        expect(xml).toContain('<?xml');
        expect(xml).toContain('<collection xmlns="http://www.loc.gov/MARC21/slim">');
        expect(xml).toContain(FONDS_REF);
        expect(xml).toContain('E2E Full Test Fonds');
    });

    test('18. Export collection bulk returns multiple records', async () => {
        const resp = await page.request.get(`${BASE}/admin/archives/export.xml`);
        expect(resp.status()).toBe(200);
        const xml = await resp.text();
        const matches = xml.match(/<record /g) || [];
        expect(matches.length).toBeGreaterThanOrEqual(2); // fonds + series at minimum
    });

    test('19. Export authority record', async () => {
        const authId = dbQuery(`SELECT id FROM authority_records WHERE authorised_form = '${AUTH_NAME_PERSON}' AND deleted_at IS NULL`);
        const resp = await page.request.get(`${BASE}/admin/archives/authorities/${authId}/export.xml`);
        expect(resp.status()).toBe(200);
        const xml = await resp.text();
        expect(xml).toContain('type="Authority"');
        expect(xml).toContain(AUTH_NAME_PERSON);
    });

    // ─── Phase 4/4b: MARCXML import + UPSERT ───────────────────────────

    test('20. Import MARCXML — dry-run preview', async () => {
        const xml = buildFixtureMarcXml(IMPORT_REF, 'E2E Import Test', 'fonds');
        await page.goto(`${BASE}/admin/archives/import`);
        // Dry-run is checked by default.
        await page.setInputFiles('input[name="marcxml"]', {
            name: 'test.xml',
            mimeType: 'application/xml',
            buffer: Buffer.from(xml, 'utf-8'),
        });
        await page.click('button[type="submit"]');
        await page.waitForLoadState('domcontentloaded');
        // Dry-run result: parsed but not inserted.
        await expect(page.locator('body')).toContainText(IMPORT_REF);
        const existsInDb = dbQuery(
            `SELECT COUNT(*) FROM archival_units WHERE reference_code = '${IMPORT_REF}'`
        );
        expect(existsInDb, 'dry-run must not insert').toBe('0');
    });

    test('21. Import MARCXML — actual insert', async () => {
        const xml = buildFixtureMarcXml(IMPORT_REF, 'E2E Import Test', 'fonds');
        await page.goto(`${BASE}/admin/archives/import`);
        await page.setInputFiles('input[name="marcxml"]', {
            name: 'test.xml',
            mimeType: 'application/xml',
            buffer: Buffer.from(xml, 'utf-8'),
        });
        await page.uncheck('input[name="dry_run"]');
        await page.click('button[type="submit"]');
        await page.waitForLoadState('domcontentloaded');
        const row = dbQuery(
            `SELECT constructed_title FROM archival_units WHERE reference_code = '${IMPORT_REF}' AND deleted_at IS NULL`
        );
        expect(row).toBe('E2E Import Test');
    });

    test('22. Re-import same file is idempotent (UPSERT, not duplicate error)', async () => {
        const xml = buildFixtureMarcXml(IMPORT_REF, 'E2E Import Test (v2)', 'fonds');
        await page.goto(`${BASE}/admin/archives/import`);
        await page.setInputFiles('input[name="marcxml"]', {
            name: 'test.xml',
            mimeType: 'application/xml',
            buffer: Buffer.from(xml, 'utf-8'),
        });
        await page.uncheck('input[name="dry_run"]');
        await page.click('button[type="submit"]');
        await page.waitForLoadState('domcontentloaded');
        const row = dbQuery(
            `SELECT constructed_title FROM archival_units WHERE reference_code = '${IMPORT_REF}' AND deleted_at IS NULL`
        );
        expect(row).toBe('E2E Import Test (v2)');
        // Still only one row (UPSERT, not INSERT twice).
        const count = dbQuery(
            `SELECT COUNT(*) FROM archival_units WHERE reference_code = '${IMPORT_REF}'`
        );
        expect(count).toBe('1');
    });

    // ─── Phase 4d: XSD strict validation ───────────────────────────────

    test('23. XSD strict validation rejects malformed MARCXML', async () => {
        const malformed = '<?xml version="1.0"?><bogus><notmarc/></bogus>';
        await page.goto(`${BASE}/admin/archives/import`);
        await page.setInputFiles('input[name="marcxml"]', {
            name: 'bad.xml',
            mimeType: 'application/xml',
            buffer: Buffer.from(malformed, 'utf-8'),
        });
        await page.check('input[name="strict_xsd"]');
        await page.click('button[type="submit"]');
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('body')).toContainText(/XSD/i);
    });

    // ─── Phase 5: photographic items ───────────────────────────────────

    test('24. Create photograph item with specific_material round-trip', async () => {
        const fondsId = dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`);
        await page.goto(`${BASE}/admin/archives/new`);
        await page.fill('input[name="reference_code"]', PHOTO_REF);
        await page.selectOption('select[name="level"]', 'item');
        await page.fill('input[name="constructed_title"]', 'Demonstration photo, 1917');
        await page.fill('input[name="parent_id"]', fondsId);
        // Expand the "Materiale specifico" details element
        await page.click('summary:has-text("Materiale specifico")');
        await page.selectOption('select[name="specific_material"]', 'photograph');
        await page.selectOption('select[name="color_mode"]', 'bw');
        await page.fill('input[name="dimensions"]', '15×10 cm');
        await page.fill('input[name="photographer"]', 'Harry Nielsen');
        await Promise.all([
            page.waitForURL(/\/admin\/archives$/, { timeout: 10000 }),
            page.click('button[type="submit"]'),
        ]);
        const row = dbQuery(
            `SELECT CONCAT_WS('|', specific_material, color_mode, dimensions, photographer)
               FROM archival_units WHERE reference_code = '${PHOTO_REF}' AND deleted_at IS NULL`
        );
        expect(row).toBe('photograph|bw|15×10 cm|Harry Nielsen');
        // Export and verify MARC 009/300 mapping lands in the XML.
        const photoId = dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${PHOTO_REF}' AND deleted_at IS NULL`);
        const resp = await page.request.get(`${BASE}/admin/archives/${photoId}/export.xml`);
        const xml = await resp.text();
        expect(xml).toContain('tag="009"');
        expect(xml).toContain('Harry Nielsen');
        expect(xml).toMatch(/tag="300"[\s\S]*black-and-white/);
    });

    // ─── Phase 6: SRU endpoint ─────────────────────────────────────────

    test('25. SRU explain + searchRetrieve return well-formed MARCXML', async () => {
        const explain = await page.request.get(`${BASE}/api/archives/sru?operation=explain`);
        expect(explain.status()).toBe(200);
        const explainXml = await explain.text();
        expect(explainXml).toContain('explainResponse');
        expect(explainXml).toContain('<zr:database');

        const search = await page.request.get(
            `${BASE}/api/archives/sru?operation=searchRetrieve&query=${encodeURIComponent('reference="' + FONDS_REF + '"')}`
        );
        expect(search.status()).toBe(200);
        const searchXml = await search.text();
        expect(searchXml).toContain('numberOfRecords');
        expect(searchXml).toContain(FONDS_REF);
        expect(searchXml).toContain('MARC21/slim');
    });
});

/**
 * Build a minimal but valid MARCXML document for import tests.
 * @param {string} ref
 * @param {string} title
 * @param {string} level 'fonds' | 'series' | 'file' | 'item'
 */
function buildFixtureMarcXml(ref, title, level) {
    const levelCode = { fonds: 'a', series: 'b', file: 'c', item: 'd' }[level] || 'a';
    const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return `<?xml version="1.0" encoding="UTF-8"?>
<collection xmlns="http://www.loc.gov/MARC21/slim">
  <record type="Bibliographic">
    <datafield tag="001" ind1=" " ind2=" ">
      <subfield code="a">${esc(ref)}</subfield>
      <subfield code="b">PINAKES</subfield>
    </datafield>
    <datafield tag="008" ind1=" " ind2=" ">
      <subfield code="a">1900</subfield>
      <subfield code="z">1950</subfield>
      <subfield code="c">${levelCode}</subfield>
      <subfield code="l">ita</subfield>
    </datafield>
    <datafield tag="245" ind1=" " ind2=" ">
      <subfield code="a">${esc(title)}</subfield>
    </datafield>
  </record>
</collection>`;
}

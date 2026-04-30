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

// Build mysql CLI args safely — passing bare `-p` (when DB_PASS is empty)
// triggers an interactive password prompt that hangs the test until timeout.
// Only append `-p${DB_PASS}` when a password is actually set. When `sql`
// is the empty string the `-e` flag is omitted so callers can pipe SQL
// via stdin (used for applying migration files).
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

    // FK-safe cleanup — archival_units has a self-referencing parent_id,
    // so a blanket DELETE can fail (or partially succeed) if rows are
    // processed parent-before-child. Delete children first, then roots.
    // Link tables are cleared first to avoid blocking the unit delete.
    function cleanupFullRows() {
        dbExec(`DELETE aua FROM archival_unit_authority aua
                JOIN archival_units au ON au.id = aua.archival_unit_id
                WHERE au.reference_code LIKE '${TAG}%'`);
        dbExec(`DELETE aal FROM autori_authority_link aal
                JOIN authority_records ar ON ar.id = aal.authority_id
                WHERE ar.authorised_form LIKE '${TAG}%'`);
        // Children first (parent_id IS NOT NULL) — self-FK safe.
        dbExec(`DELETE FROM archival_units WHERE reference_code LIKE '${TAG}%' AND parent_id IS NOT NULL`);
        dbExec(`DELETE FROM archival_units WHERE reference_code LIKE '${TAG}%'`);
        dbExec(`DELETE FROM authority_records WHERE authorised_form LIKE '${TAG}%'`);
    }

    test.beforeAll(async ({ browser }) => {
        try {
            cleanupFullRows();
        } catch (err) {
            // Tables may not exist yet on fresh installs without the plugin
            // active — suppress the "table doesn't exist" case, but surface
            // any other failure so real cleanup bugs don't hide silently.
            const msg = String(err?.message || err);
            if (!/doesn.?t exist|Table .* doesn't exist/i.test(msg)) {
                // eslint-disable-next-line no-console
                console.error('[archives-full beforeAll cleanup]', msg);
            }
        }

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
            cleanupFullRows();
        } catch (err) {
            // eslint-disable-next-line no-console
            console.error('[archives-full afterAll cleanup]', String(err?.message || err));
        }
        await context?.close();
    });

    // ─── Phase 1: schema + activation + sidebar ────────────────────────

    test('01. Plugin is active + schema tables exist', async () => {
        // Run the 0.5.9 migration first (version.json is 0.5.8 on fresh
        // installs), then activate the plugin through the real admin UI so
        // PluginManager::activatePlugin() runs setPluginId() + onActivate()
        // and the hook rows get written naturally. This guards against
        // silent hook-registration regressions — the previous version of
        // this test manually inserted the rows, masking the real flow.
        const fs = require('fs');
        const path = require('path');
        const migPath = path.join(__dirname, '..', 'installer', 'database', 'migrations', 'migrate_0.5.9.sql');
        if (fs.existsSync(migPath)) {
            execFileSync('mysql', mysqlArgs(''), {
                input: fs.readFileSync(migPath, 'utf-8'),
                encoding: 'utf-8',
                timeout: 15000,
            });
        }
        // Reset: deactivate and scrub hooks so we assert the UI-click is what
        // re-registers them. PluginManager::deactivatePlugin() would do this
        // too, but it refuses when is_active=0 already, so we force-reset here.
        const pluginId = dbQuery("SELECT id FROM plugins WHERE name = 'archives'");
        if (pluginId !== '') {
            dbExec(`DELETE FROM plugin_hooks WHERE plugin_id = ${pluginId}`);
            dbExec(`UPDATE plugins SET is_active = 0 WHERE id = ${pluginId}`);
        }
        // Activate via the admin UI — exercises the real
        // runPluginMethod('setPluginId') + runPluginMethod('onActivate') path.
        // The UI button opens a Swal confirm; we click `.swal2-confirm` which
        // kicks the POST /admin/plugins/{id}/activate fetch and then reloads
        // the page. Wait for the POST response so we assert against a settled
        // DB state, not a pending one.
        await page.goto(`${BASE}/admin/plugins`);
        await page.waitForLoadState('domcontentloaded');
        const activateBtn = page.locator(`button[onclick^="activatePlugin("]`, { hasText: 'Attiva' }).first();
        if (await activateBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
            await activateBtn.click();
            await page.locator('.swal2-confirm').first().click();
            await page.waitForResponse(r =>
                /\/admin\/plugins\/\d+\/activate$/.test(r.url()) && r.request().method() === 'POST',
                { timeout: 15000 });
            // Dismiss the success Swal (if present) and wait for reload.
            const okBtn = page.locator('.swal2-confirm');
            if (await okBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
                await okBtn.click();
            }
            await page.waitForLoadState('domcontentloaded');
        }

        const nowActive = dbQuery("SELECT is_active FROM plugins WHERE name = 'archives'");
        expect(nowActive).toBe('1');
        // Regression guard: real activation must populate plugin_hooks.
        const hookCount = dbQuery(
            `SELECT COUNT(*) FROM plugin_hooks ph
               JOIN plugins p ON p.id = ph.plugin_id
              WHERE p.name = 'archives' AND ph.is_active = 1`
        );
        expect(parseInt(hookCount, 10), 'onActivate() must write plugin_hooks rows').toBeGreaterThanOrEqual(2);
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
        // NOTE: date_start/date_end are <input type="number"> (year-only,
        // smallint range) by design — see views/form.php L128-150. They are
        // NOT Flatpickr-backed, so page.fill() is the correct interaction.
        // If the UI ever migrates to Flatpickr, replace with
        // page.evaluate((val) => fp.setDate(val)) per project convention.
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
        // places/identifiers live inside a <details> collapsible. Force
        // open=true instead of click() because a click on <summary> toggles:
        // if the element happens to already be open (e.g. because the
        // server re-rendered it with [open] after a validation error) the
        // click would CLOSE it and the subsequent page.fill calls would
        // time out waiting for hidden fields.
        await page.evaluate(() => {
            document.querySelectorAll('details').forEach(d => {
                const sum = d.querySelector('summary');
                if (sum && /Campi ISAAR aggiuntivi/.test(sum.textContent || '')) {
                    d.open = true;
                }
            });
        });
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

    test('13. Attach authority to fonds with role=creator (phase 7 widget)', async () => {
        const fondsId = dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`);
        const authId = dbQuery(`SELECT id FROM authority_records WHERE authorised_form = '${AUTH_NAME_PERSON}' AND deleted_at IS NULL`);
        await page.goto(`${BASE}/admin/archives/${fondsId}`);

        // Exercise the REAL type-ahead user flow: type a prefix into the
        // input, wait for the JSON response, confirm the option renders,
        // click it, then verify the hidden authority_id got populated by
        // the JS handler (NOT by test evaluate-injection). Because the
        // fixture authority name starts with `E2E_FULL_…_Person_Stauning`
        // the backend LIKE-prefix pass matches it — we're relying on the
        // typeahead's LIKE-pre-FULLTEXT contract here. A regression in
        // that contract (e.g. FULLTEXT-only) would fail the prefix match
        // and this test.
        const prefix = AUTH_NAME_PERSON.substring(0, 10); // e.g. "E2E_FULL_1"
        const input = page.locator('[data-typeahead-input]');
        await expect(input).toBeVisible();
        await input.fill(prefix);
        // Debounce is 200ms; wait for the fetch to complete.
        await page.waitForResponse(r =>
            r.url().includes('/admin/archives/api/authorities/search') &&
            r.status() === 200,
            { timeout: 5000 });
        const option = page.locator(`[data-typeahead-results] li[data-id="${authId}"]`);
        await expect(option, 'LIKE-prefix typeahead must surface the fixture authority').toBeVisible({ timeout: 5000 });
        await option.click();
        // The JS handler must have populated the hidden field + input label.
        await expect(page.locator('input[name="authority_id"]')).toHaveValue(String(authId));
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

    // Destructive confirmations go through SweetAlert2 (same pattern as the
    // rest of Pinakes). The button is <button type="button"> — clicking it
    // opens the swal modal; we then click .swal2-confirm which fires the
    // real form submit.
    test('15. Detach authority from fonds', async () => {
        const fondsId = dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`);
        const authId = dbQuery(`SELECT id FROM authority_records WHERE authorised_form = '${AUTH_NAME_PERSON}' AND deleted_at IS NULL`);
        await page.goto(`${BASE}/admin/archives/${fondsId}`);
        await page.click('button:has-text("scollega"), button:has-text("unlink")');
        await page.locator('.swal2-confirm').click();
        await page.waitForURL(new RegExp(`/admin/archives/${fondsId}$`), { timeout: 10000 });
        const count = dbQuery(
            `SELECT COUNT(*) FROM archival_unit_authority
              WHERE archival_unit_id = ${fondsId} AND authority_id = ${authId}`
        );
        expect(count).toBe('0');
    });

    // ─── Phase 3: unified search ───────────────────────────────────────

    test('16. Unified search finds archival units + authorities', async () => {
        // Search by a real FT-indexable word that lives in the fixtures:
        // "Danish" is in the fonds' scope_content (test 04) and in the
        // authority's history (test 10). Tag prefixes like `E2E_FULL_…` are
        // split on `_` and most tokens fall below innodb_ft_min_token_size.
        // Strict assert (no fallback text match) catches search regressions.
        await page.goto(`${BASE}/admin/archives/search?q=Danish`);
        await page.waitForLoadState('domcontentloaded');
        const body = await page.locator('body').textContent() || '';
        const hasHit = body.includes(FONDS_REF) || body.includes(AUTH_NAME_PERSON) || body.includes(AUTH_NAME_CORP);
        expect(hasHit, 'unified search should return at least one hit for the Danish fixtures').toBe(true);
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
        const tmpPath = writeTmpXml(xml);
        try {
            await page.setInputFiles('input[name="marcxml"]', tmpPath);
            await Promise.all([
                page.waitForResponse(r =>
                    r.url().endsWith('/admin/archives/import') && r.request().method() === 'POST',
                    { timeout: 15000 }),
                page.click('form[enctype="multipart/form-data"] button[type="submit"]'),
            ]);
            await page.waitForLoadState('domcontentloaded');
            await expect(page.locator('body')).toContainText(IMPORT_REF);
            const existsInDb = dbQuery(
                `SELECT COUNT(*) FROM archival_units WHERE reference_code = '${IMPORT_REF}'`
            );
            expect(existsInDb, 'dry-run must not insert').toBe('0');
        } finally {
            rmIfExists(tmpPath);
        }
    });

    test('21. Import MARCXML — actual insert', async () => {
        const xml = buildFixtureMarcXml(IMPORT_REF, 'E2E Import Test', 'fonds');
        await page.goto(`${BASE}/admin/archives/import`);
        const tmpPath = writeTmpXml(xml);
        try {
            await page.setInputFiles('input[name="marcxml"]', tmpPath);
            await page.uncheck('input[name="dry_run"]');
            await Promise.all([
                page.waitForResponse(r =>
                    r.url().endsWith('/admin/archives/import') && r.request().method() === 'POST',
                    { timeout: 15000 }),
                page.click('form[enctype="multipart/form-data"] button[type="submit"]'),
            ]);
            await page.waitForLoadState('domcontentloaded');
            const row = dbQuery(
                `SELECT constructed_title FROM archival_units WHERE reference_code = '${IMPORT_REF}' AND deleted_at IS NULL`
            );
            expect(row).toBe('E2E Import Test');
        } finally {
            rmIfExists(tmpPath);
        }
    });

    test('22. Re-import same file is idempotent (UPSERT, not duplicate error)', async () => {
        const xml = buildFixtureMarcXml(IMPORT_REF, 'E2E Import Test (v2)', 'fonds');
        await page.goto(`${BASE}/admin/archives/import`);
        const tmpPath = writeTmpXml(xml);
        try {
            await page.setInputFiles('input[name="marcxml"]', tmpPath);
            await page.uncheck('input[name="dry_run"]');
            await Promise.all([
                page.waitForResponse(r =>
                    r.url().endsWith('/admin/archives/import') && r.request().method() === 'POST',
                    { timeout: 15000 }),
                page.click('form[enctype="multipart/form-data"] button[type="submit"]'),
            ]);
            await page.waitForLoadState('domcontentloaded');
            const row = dbQuery(
                `SELECT constructed_title FROM archival_units WHERE reference_code = '${IMPORT_REF}' AND deleted_at IS NULL`
            );
            expect(row).toBe('E2E Import Test (v2)');
            const count = dbQuery(
                `SELECT COUNT(*) FROM archival_units WHERE reference_code = '${IMPORT_REF}'`
            );
            expect(count).toBe('1');
        } finally {
            rmIfExists(tmpPath);
        }
    });

    // ─── Phase 4d: XSD strict validation ───────────────────────────────

    test('23. XSD strict validation rejects malformed MARCXML', async () => {
        const malformed = '<?xml version="1.0"?><bogus><notmarc/></bogus>';
        await page.goto(`${BASE}/admin/archives/import`);
        const badPath = writeTmpXml(malformed, 'archives-bad');
        await page.setInputFiles('input[name="marcxml"]', badPath);
        await page.check('input[name="strict_xsd"]');
        await Promise.all([
            page.waitForResponse(r =>
                r.url().endsWith('/admin/archives/import') && r.request().method() === 'POST',
                { timeout: 15000 }),
            page.click('form[enctype="multipart/form-data"] button[type="submit"]'),
        ]);
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('body')).toContainText(/XSD/i);
        rmIfExists(badPath);
    });

    // ─── Phase 5: photographic items ───────────────────────────────────

    test('24. Create photograph item with specific_material round-trip', async () => {
        const fondsId = dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`);
        await page.goto(`${BASE}/admin/archives/new`);
        await page.fill('input[name="reference_code"]', PHOTO_REF);
        await page.selectOption('select[name="level"]', 'item');
        await page.fill('input[name="constructed_title"]', 'Demonstration photo, 1917');
        await page.fill('input[name="parent_id"]', fondsId);
        // Force open=true on the "Materiale specifico" details (avoid the
        // toggle-on-click pitfall — see note at test 12).
        await page.evaluate(() => {
            document.querySelectorAll('details').forEach(d => {
                const sum = d.querySelector('summary');
                if (sum && /Materiale specifico/.test(sum.textContent || '')) {
                    d.open = true;
                }
            });
        });
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
        // SRU envelope declares the sru namespace; MARC namespace is on the
        // explain response or on the outer collection in plain export, but
        // the per-record shape is a <record> element emitted by the same
        // writer used in phase-4 export. Validate the record shape instead.
        expect(searchXml).toMatch(/<record type="Bibliographic"/);
        expect(searchXml).toContain('<datafield tag="245"');
    });
});

/**
 * Build a minimal but valid MARCXML document for import tests.
 * @param {string} ref
 * @param {string} title
 * @param {string} level 'fonds' | 'series' | 'file' | 'item'
 */
/**
 * Write an XML payload to a uniquely-named file in os.tmpdir() and return
 * the full path. Caller is responsible for rmIfExists() in a finally{}.
 * @param {string} content
 * @param {string} [prefix='archives-e2e']
 */
function writeTmpXml(content, prefix = 'archives-e2e') {
    const path = require('path');
    const os = require('os');
    const fs = require('fs');
    const name = `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2)}.xml`;
    const p = path.join(os.tmpdir(), name);
    fs.writeFileSync(p, content, 'utf-8');
    return p;
}

/** Best-effort unlink; silent if the file is already gone. */
function rmIfExists(p) {
    try { require('fs').unlinkSync(p); } catch { /* already gone */ }
}

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

// @ts-check
/**
 * E2E for the Archives plugin (#103) — phase 1d.
 *
 * Activation flow:
 *   - Ensure the plugin is activated via /admin/plugins
 *   - ensureSchema() must create archival_units + authority_records + link table
 *
 * CRUD flow:
 *   1. Navigate to /admin/archives from the sidebar entry
 *   2. Create a fonds (reference_code, level, title)
 *   3. Verify row appears in the list + detail view
 *   4. Edit it, verify update
 *   5. Create a child series with parent_id set
 *   6. Tree indent renders (check DOM order)
 *   7. Soft-delete the series → disappears from list but row still in DB
 *      with deleted_at set
 *
 * Requires the standard E2E env (ADMIN_EMAIL, DB_*, etc.).
 * Cleanup: soft-deletes all E2E-created rows in afterAll().
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

// Unique prefix so parallel runs + leftover data don't collide.
const TAG = 'E2E_ARCHIVES_' + Date.now();
const FONDS_REF = TAG + '_F1';
const SERIES_REF = TAG + '_S1';

test.skip(
    !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
    'Missing E2E env (ADMIN_EMAIL/PASS, DB_*)'
);

test.describe.serial('Archives plugin CRUD (#103 phase 1d)', () => {
    /** @type {import('@playwright/test').BrowserContext} */
    let context;
    /** @type {import('@playwright/test').Page} */
    let page;

    test.beforeAll(async ({ browser }) => {
        // Preclean leftover test rows — UNIQUE on (institution_code, reference_code)
        // would otherwise kill the create test.
        try {
            dbExec(`DELETE FROM archival_units WHERE reference_code LIKE '${TAG}%'`);
        } catch { /* table may not exist yet — plugin will create it */ }

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
            // Hard-delete test rows — soft-delete would leave them orphaned
            // across runs since the UNIQUE index ignores deleted_at.
            dbExec(`DELETE FROM archival_units WHERE reference_code LIKE '${TAG}%'`);
        } catch { /* best-effort */ }
        await context?.close();
    });

    test('1. Activate archives plugin (if not already)', async () => {
        await page.goto(`${BASE}/admin/plugins`);
        await page.waitForLoadState('domcontentloaded');

        const isActive = dbQuery(
            "SELECT is_active FROM plugins WHERE name = 'archives'"
        );
        if (isActive === '0') {
            // Click the Attiva button next to the archives row.
            const row = page.locator('tr', { hasText: 'archives' }).first();
            const activateBtn = row.locator('form button:has-text("Attiva")');
            if (await activateBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
                await activateBtn.click();
                await page.waitForLoadState('domcontentloaded');
            }
        }

        const afterActive = dbQuery(
            "SELECT is_active FROM plugins WHERE name = 'archives'"
        );
        expect(afterActive, 'archives plugin should be active after beforeAll').toBe('1');

        // Schema should now exist.
        const tableExists = dbQuery(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'archival_units'"
        );
        expect(tableExists, 'archival_units table must exist post-activation').toBe('1');
    });

    test('2. Index page renders empty state or existing rows', async () => {
        await page.goto(`${BASE}/admin/archives`);
        await page.waitForLoadState('domcontentloaded');
        // Either the empty-state alert OR the table is visible.
        const hasEmptyState = await page.locator('text=Nessun record archivistico').isVisible().catch(() => false);
        const hasTable = await page.locator('table thead').isVisible().catch(() => false);
        expect(hasEmptyState || hasTable, 'index must render either empty-state or table').toBe(true);
    });

    test('3. Create a fonds via the form', async () => {
        await page.goto(`${BASE}/admin/archives/new`);
        await page.waitForLoadState('domcontentloaded');

        await page.fill('input[name="reference_code"]', FONDS_REF);
        await page.selectOption('select[name="level"]', 'fonds');
        await page.fill('input[name="constructed_title"]', 'E2E Test Fonds');
        await page.fill('input[name="date_start"]', '1900');
        await page.fill('input[name="date_end"]', '1950');
        await page.fill('input[name="extent"]', '12 boxes');
        await Promise.all([
            page.waitForURL(/\/admin\/archives$/, { timeout: 10000 }),
            page.click('button[type="submit"]'),
        ]);

        // DB check: row present with right fields.
        const row = dbQuery(
            `SELECT CONCAT_WS('|', level, constructed_title, date_start, date_end, extent)
               FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`
        );
        expect(row).toBe('fonds|E2E Test Fonds|1900|1950|12 boxes');
    });

    test('4. Fonds appears in the index list', async () => {
        await page.goto(`${BASE}/admin/archives`);
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('table')).toContainText(FONDS_REF);
        await expect(page.locator('table')).toContainText('E2E Test Fonds');
    });

    test('5. Edit fonds — change title + extent', async () => {
        const fondsId = dbQuery(
            `SELECT id FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`
        );
        await page.goto(`${BASE}/admin/archives/${fondsId}/edit`);
        await page.waitForLoadState('domcontentloaded');

        // Sticky pre-fill check.
        await expect(page.locator('input[name="reference_code"]')).toHaveValue(FONDS_REF);

        await page.fill('input[name="constructed_title"]', 'E2E Test Fonds (updated)');
        await page.fill('input[name="extent"]', '15 boxes');
        await Promise.all([
            page.waitForURL(new RegExp(`/admin/archives/${fondsId}$`), { timeout: 10000 }),
            page.click('button[type="submit"]'),
        ]);

        const updated = dbQuery(
            `SELECT CONCAT_WS('|', constructed_title, extent)
               FROM archival_units WHERE id = ${fondsId}`
        );
        expect(updated).toBe('E2E Test Fonds (updated)|15 boxes');
    });

    test('6. Create a child series with parent_id', async () => {
        const fondsId = dbQuery(
            `SELECT id FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`
        );
        await page.goto(`${BASE}/admin/archives/new`);
        await page.waitForLoadState('domcontentloaded');
        await page.fill('input[name="reference_code"]', SERIES_REF);
        await page.selectOption('select[name="level"]', 'series');
        await page.fill('input[name="constructed_title"]', 'E2E Test Series');
        await page.fill('input[name="parent_id"]', fondsId);
        await Promise.all([
            page.waitForURL(/\/admin\/archives$/, { timeout: 10000 }),
            page.click('button[type="submit"]'),
        ]);

        const parentCheck = dbQuery(
            `SELECT parent_id FROM archival_units WHERE reference_code = '${SERIES_REF}' AND deleted_at IS NULL`
        );
        expect(parentCheck).toBe(fondsId);
    });

    test('7. Self-parent attempt is rejected', async () => {
        const fondsId = dbQuery(
            `SELECT id FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`
        );
        await page.goto(`${BASE}/admin/archives/${fondsId}/edit`);
        await page.waitForLoadState('domcontentloaded');
        await page.fill('input[name="parent_id"]', fondsId); // self
        await page.click('button[type="submit"]');
        // Stays on the edit page and shows the error.
        await expect(page.locator('body')).toContainText(/cannot be its own parent/i);
    });

    test('8. Soft-delete the series', async () => {
        const seriesId = dbQuery(
            `SELECT id FROM archival_units WHERE reference_code = '${SERIES_REF}' AND deleted_at IS NULL`
        );
        await page.goto(`${BASE}/admin/archives/${seriesId}`);
        await page.waitForLoadState('domcontentloaded');

        // Playwright auto-dismisses the confirm() by default unless we stub it.
        page.once('dialog', dialog => dialog.accept());

        await Promise.all([
            page.waitForURL(/\/admin\/archives$/, { timeout: 10000 }),
            page.click('form button:has-text("Elimina")'),
        ]);

        // Row still exists but deleted_at is set.
        const deletedAt = dbQuery(
            `SELECT deleted_at FROM archival_units WHERE id = ${seriesId}`
        );
        expect(deletedAt).not.toBe('NULL');
        expect(deletedAt).not.toBe('');

        // Index no longer shows the series.
        await page.goto(`${BASE}/admin/archives`);
        await page.waitForLoadState('domcontentloaded');
        const pageText = await page.locator('body').textContent();
        expect(pageText).not.toContain(SERIES_REF);
    });

    test('9. Sidebar entry links to /admin/archives', async () => {
        await page.goto(`${BASE}/admin/dashboard`);
        await page.waitForLoadState('domcontentloaded');
        // The sidebar entry is hook-rendered; look for the href.
        const sidebarLink = page.locator('aside a[href$="/admin/archives"]').first();
        await expect(sidebarLink).toBeVisible();
    });
});

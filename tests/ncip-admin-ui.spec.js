// @ts-check
/**
 * E2E — NCIP Admin UI: partner management and transaction log (v0.7.4+)
 *
 * Covers the admin routes added to NcipServerPlugin:
 *   GET  /admin/plugins/ncip-server/partners         (list + add form)
 *   POST /admin/plugins/ncip-server/partners         (create partner)
 *   POST /admin/plugins/ncip-server/partners/{id}/delete  (delete partner)
 *   GET  /admin/plugins/ncip-server/transactions     (transaction log)
 *
 * Tests:
 *  1.  GET /admin/plugins/ncip-server/partners without auth → redirect to login
 *  2.  GET /admin/plugins/ncip-server/transactions without auth → redirect to login
 *  3.  Admin can access partners page (200 OK)
 *  4.  Partners page contains "Aggiungi Partner" heading/button
 *  5.  Partners page has the add-partner form with required inputs
 *  6.  Admin can submit the add-partner form and partner is created
 *  7.  Newly added partner appears in the partners list
 *  8.  Transactions page loads with correct page title
 *
 * Run: /tmp/run-e2e.sh tests/ncip-admin-ui.spec.js --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

function clearRateLimits() {
    // Use PHP to locate the actual sys_get_temp_dir() used by PHP-FPM (may differ from os.tmpdir())
    try {
        execFileSync('php', [
            '-r',
            'array_map("unlink", glob(sys_get_temp_dir()."/pinakes_ratelimit/*.json") ?: []);',
        ], { encoding: 'utf-8', timeout: 5000 });
    } catch { /* ignore */ }
}
clearRateLimits();

const BASE        = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const DB_USER     = process.env.E2E_DB_USER     || '';
const DB_PASS     = process.env.E2E_DB_PASS     || '';
const DB_NAME     = process.env.E2E_DB_NAME     || '';
const DB_SOCKET   = process.env.E2E_DB_SOCKET   || '';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';

function mysqlArgs(sql, batch = false) {
    const args = [];
    if (DB_SOCKET) args.push('-S', DB_SOCKET);
    args.push('-u', DB_USER, DB_NAME);
    if (batch) args.push('-N', '-B');
    if (sql !== '') args.push('-e', sql);
    return args;
}
const MYSQL_ENV = () => ({ ...process.env, MYSQL_PWD: DB_PASS });
function dbExec(sql) {
    execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout: 10000, env: MYSQL_ENV() });
}

test.skip(
    !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
    'Missing E2E env (ADMIN_* and DB_*)'
);

const PARTNERS_URL    = `${BASE}/admin/plugins/ncip-server/partners`;
const TRANSACTIONS_URL = `${BASE}/admin/plugins/ncip-server/transactions`;
const RUN_ID = Date.now().toString(36);
const TEST_PARTNER_NAME = `E2E_NCIP_Partner_${RUN_ID}`;

test.describe.serial('NCIP Admin UI — partners and transactions (8 tests)', () => {
    /** @type {import('@playwright/test').BrowserContext} */
    let context;
    /** @type {import('@playwright/test').Page} */
    let page;

    test.beforeAll(async ({ browser }) => {
        clearRateLimits();
        context = await browser.newContext();
        page = await context.newPage();
        // Login as admin
        await page.goto(`${BASE}/accedi`);
        await page.waitForLoadState('domcontentloaded');
        await page.fill('input[name="email"]', ADMIN_EMAIL);
        await page.fill('input[name="password"]', ADMIN_PASS);
        await page.locator('button[type="submit"]').click();
        await page.waitForURL(url => !url.toString().includes('/accedi'), { timeout: 15000 });
    });

    test.afterAll(async () => {
        // Clean up test partner rows if any survived (FK-safe order)
        try {
            dbExec(`DELETE FROM ncip_transactions WHERE prestito_id IS NULL AND request_id LIKE '%E2E%'`);
            dbExec(`DELETE FROM ncip_partners WHERE name LIKE 'E2E_NCIP_Partner_%'`);
        } catch { /* best-effort */ }
        await context?.close();
    });

    // ── Auth guard checks (unauthenticated request via new context) ────────────

    test('1. GET partners page without auth → redirects to login', async ({ request }) => {
        const res = await request.get(PARTNERS_URL, { maxRedirects: 0 });
        // Should be 302/303/401/403 or redirect to login
        const isBlocked = res.status() >= 300 && res.status() < 400;
        const isUnauth  = res.status() === 401 || res.status() === 403;
        expect(isBlocked || isUnauth).toBe(true);
    });

    test('2. GET transactions page without auth → redirects to login', async ({ request }) => {
        const res = await request.get(TRANSACTIONS_URL, { maxRedirects: 0 });
        const isBlocked = res.status() >= 300 && res.status() < 400;
        const isUnauth  = res.status() === 401 || res.status() === 403;
        expect(isBlocked || isUnauth).toBe(true);
    });

    // ── Authenticated access ──────────────────────────────────────────────────

    test('3. Admin can access partners page (200 OK after login)', async () => {
        await page.goto(PARTNERS_URL);
        await page.waitForLoadState('networkidle');
        expect(page.url()).toContain('partners');
        // Should not be redirected to login page
        expect(page.url()).not.toContain('accedi');
        expect(page.url()).not.toContain('login');
    });

    test('4. Partners page contains "Aggiungi Partner" heading/button', async () => {
        await page.goto(PARTNERS_URL);
        await page.waitForLoadState('networkidle');
        const addText = page.getByText(/Aggiungi Partner/i).first();
        await expect(addText).toBeVisible();
    });

    test('5. Partners page has add-partner form with name and endpoint inputs', async () => {
        await page.goto(PARTNERS_URL);
        await page.waitForLoadState('networkidle');
        await expect(page.locator('input[name="name"]')).toBeVisible();
        await expect(page.locator('input[name="endpoint_url"]')).toBeVisible();
        await expect(page.locator('input[name="isil"]')).toBeVisible();
    });

    test('6. Admin can submit add-partner form successfully', async () => {
        await page.goto(PARTNERS_URL);
        await page.waitForLoadState('networkidle');

        await page.fill('input[name="name"]', TEST_PARTNER_NAME);
        await page.fill('input[name="endpoint_url"]', 'https://e2e-ncip.example.org/ncip');
        await page.fill('input[name="isil"]', 'IT-E2E01');
        await page.fill('input[name="notes"]', 'E2E test partner');

        await page.locator('form button[type="submit"]').first().click();
        await page.waitForLoadState('networkidle');

        // Should stay on partners page (redirect after POST)
        expect(page.url()).toContain('partners');
    });

    test('7. Newly added partner appears in the partners list', async () => {
        await page.goto(PARTNERS_URL);
        await page.waitForLoadState('networkidle');
        await expect(page.getByText(TEST_PARTNER_NAME)).toBeVisible();
    });

    // ── Transactions page ─────────────────────────────────────────────────────

    test('8. Transactions page loads with correct title text', async () => {
        await page.goto(TRANSACTIONS_URL);
        await page.waitForLoadState('networkidle');
        expect(page.url()).toContain('transactions');
        // Page title element
        const title = page.locator('h1').first();
        await expect(title).toBeVisible();
        const titleText = await title.textContent();
        expect(titleText ?? '').toMatch(/transazion/i);
    });
});

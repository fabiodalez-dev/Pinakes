// @ts-check
/**
 * E2E — Plugin lifecycle: deactivate → activate → deactivate → activate (v0.7.4)
 *
 * Verifies that every registered plugin survives two full deactivate/activate
 * cycles without errors, and that interop plugins properly clean up hooks
 * on deactivation (onDeactivate must call deleteHooksFromDb).
 *
 * Tests (10):
 *  1. All registered plugins found in DB (count > 0)
 *  2. Setup: activate all plugins (idempotent, already-active is acceptable)
 *  3. Deactivate all plugins → each API response success=true
 *  4. After deactivation: interop plugins (ncip/openurl/resource-sync/oai-pmh/viaf) have 0 hooks
 *  5. Activate all plugins → each API response success=true
 *  6. After activation: interop plugin_hooks count > 0
 *  7. Deactivate again (2nd cycle) → success=true
 *  8. Activate again (2nd cycle, idempotency) → success=true
 *  9. Hook count same as after first activation
 * 10. ncip_partners and ncip_transactions tables still exist after lifecycle
 *
 * Run: /tmp/run-e2e.sh tests/plugin-lifecycle.spec.js --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE        = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const DB_USER     = process.env.E2E_DB_USER     || '';
const DB_PASS     = process.env.E2E_DB_PASS     || '';
const DB_NAME     = process.env.E2E_DB_NAME     || '';
const DB_SOCKET   = process.env.E2E_DB_SOCKET   || '';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';

/** Interop plugins that implement deleteHooksFromDb — must have 0 hooks after deactivation */
const INTEROP_PLUGIN_NAMES = [
    'ncip-server', 'openurl-resolver', 'resource-sync',
    'oai-pmh-server', 'viaf-authority',
];

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
    return execFileSync('mysql', mysqlArgs(sql, true), {
        encoding: 'utf-8', timeout: 10000, env: MYSQL_ENV(),
    }).trim();
}

/** Call activate or deactivate via admin API from browser context (handles CSRF). */
async function pluginApiCall(page, action, pluginId) {
    return page.evaluate(async ([act, pid]) => {
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        const token     = csrfInput ? (/** @type {HTMLInputElement} */ (csrfInput)).value : '';
        const formData  = new FormData();
        formData.append('csrf_token', token);
        try {
            const res = await fetch(
                `${window.location.origin}${window.BASE_PATH || ''}/admin/plugins/${pid}/${act}`,
                { method: 'POST', body: formData }
            );
            return await res.json();
        } catch (e) {
            return { success: false, error: String(e) };
        }
    }, [action, pluginId]);
}

test.skip(
    !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
    'Missing E2E env (ADMIN_EMAIL/PASS, DB_*)'
);

test.describe.serial('Plugin lifecycle — v0.7.4 (10 tests)', () => {
    /** @type {import('@playwright/test').BrowserContext} */
    let context;
    /** @type {import('@playwright/test').Page} */
    let page;
    /** @type {Array<{id: number, name: string}>} */
    let allPlugins = [];
    /** @type {Array<{id: number, name: string}>} */
    let interopPlugins = [];
    /** @type {number} */
    let hookCountAfterFirstActivation = 0;

    test.beforeAll(async ({ browser }) => {
        context = await browser.newContext();
        page    = await context.newPage();
        await page.goto(`${BASE}/login`);
        await page.fill('input[name="email"]', ADMIN_EMAIL);
        await page.fill('input[name="password"]', ADMIN_PASS);
        await Promise.all([
            page.waitForURL(/\/admin\//, { timeout: 15000 }),
            page.click('button[type="submit"]'),
        ]);
    });

    test.afterAll(async () => { await context?.close(); });

    // ── Test 1: Discover plugins ──────────────────────────────────────────────

    test('1. All registered plugins found in DB', async () => {
        const parseRows = (/** @type {string} */ raw) =>
            raw.split('\n')
               .filter(r => r.trim() !== '')
               .map(r => { const p = r.split('\t'); return { id: parseInt(p[0]), name: p[1] || '' }; })
               .filter(p => p.id > 0 && p.name !== '');

        allPlugins    = parseRows(dbQuery("SELECT id, name FROM plugins ORDER BY name"));
        interopPlugins = parseRows(dbQuery(
            `SELECT id, name FROM plugins WHERE name IN (${INTEROP_PLUGIN_NAMES.map(n => `'${n}'`).join(',')}) ORDER BY name`
        ));

        expect(allPlugins.length).toBeGreaterThan(0);
    });

    // ── Test 2: Setup — activate all plugins ──────────────────────────────────

    test('2. Setup: activate all plugins (already-active is acceptable)', async () => {
        test.skip(allPlugins.length === 0, 'No plugins found in test 1');
        await page.goto(`${BASE}/admin/plugins`);

        for (const plugin of allPlugins) {
            const result = await pluginApiCall(page, 'activate', plugin.id);
            // Accept success OR "already active" as valid — both mean plugin is active
            const alreadyActive = result.success === false && typeof result.message === 'string'
                && result.message.toLowerCase().includes('già att');
            expect(
                result.success || alreadyActive,
                `Setup activate ${plugin.name}: ${result.message || result.error || ''}`
            ).toBe(true);
        }
    });

    // ── Test 3: Deactivate all plugins ────────────────────────────────────────

    test('3. Deactivate all plugins → each API response success=true', async () => {
        test.skip(allPlugins.length === 0, 'No plugins found in test 1');
        await page.goto(`${BASE}/admin/plugins`);

        for (const plugin of allPlugins) {
            const result = await pluginApiCall(page, 'deactivate', plugin.id);
            expect(result.success, `Deactivate ${plugin.name}: ${result.message || result.error || ''}`).toBe(true);
        }
    });

    // ── Test 4: Interop hooks removed after deactivation ──────────────────────

    test('4. After deactivation: interop plugin hooks are removed (0 rows)', async () => {
        test.skip(interopPlugins.length === 0, 'No interop plugins in DB');

        for (const plugin of interopPlugins) {
            const count = parseInt(dbQuery(
                `SELECT COUNT(*) FROM plugin_hooks WHERE plugin_id = ${plugin.id}`
            ));
            expect(count, `${plugin.name} still has ${count} hook(s) after deactivation`).toBe(0);
        }
    });

    // ── Test 5: Activate all plugins ─────────────────────────────────────────

    test('5. Activate all plugins → each API response success=true', async () => {
        test.skip(allPlugins.length === 0, 'No plugins found in test 1');
        await page.goto(`${BASE}/admin/plugins`);

        for (const plugin of allPlugins) {
            const result = await pluginApiCall(page, 'activate', plugin.id);
            expect(result.success, `Activate ${plugin.name}: ${result.message || result.error || ''}`).toBe(true);
        }
    });

    // ── Test 6: Interop hooks restored after activation ───────────────────────

    test('6. After activation: interop plugin_hooks count > 0', async () => {
        test.skip(interopPlugins.length === 0, 'No interop plugins in DB');

        const pluginIds = interopPlugins.map(p => p.id).join(',');
        hookCountAfterFirstActivation = parseInt(dbQuery(
            `SELECT COUNT(*) FROM plugin_hooks WHERE plugin_id IN (${pluginIds})`
        ));
        expect(hookCountAfterFirstActivation).toBeGreaterThan(0);
    });

    // ── Test 7: Second deactivation cycle ────────────────────────────────────

    test('7. Second deactivation cycle → success=true', async () => {
        test.skip(allPlugins.length === 0, 'No plugins found in test 1');
        await page.goto(`${BASE}/admin/plugins`);

        for (const plugin of allPlugins) {
            const result = await pluginApiCall(page, 'deactivate', plugin.id);
            expect(result.success, `Deactivate (2nd) ${plugin.name}: ${result.message || result.error || ''}`).toBe(true);
        }
    });

    // ── Test 8: Second activation cycle (idempotency) ────────────────────────

    test('8. Second activation cycle (idempotency) → success=true', async () => {
        test.skip(allPlugins.length === 0, 'No plugins found in test 1');
        await page.goto(`${BASE}/admin/plugins`);

        for (const plugin of allPlugins) {
            const result = await pluginApiCall(page, 'activate', plugin.id);
            expect(result.success, `Activate (2nd) ${plugin.name}: ${result.message || result.error || ''}`).toBe(true);
        }
    });

    // ── Test 9: Hook count stable after second cycle ──────────────────────────

    test('9. Hook count same after second activation as after first', async () => {
        test.skip(interopPlugins.length === 0 || hookCountAfterFirstActivation === 0, 'Prerequisites not met');

        const pluginIds = interopPlugins.map(p => p.id).join(',');
        const count = parseInt(dbQuery(
            `SELECT COUNT(*) FROM plugin_hooks WHERE plugin_id IN (${pluginIds})`
        ));
        expect(count).toBe(hookCountAfterFirstActivation);
    });

    // ── Test 10: Critical tables survive full lifecycle ───────────────────────

    test('10. ncip_partners and ncip_transactions tables exist after lifecycle', async () => {
        const ncipPartners = parseInt(dbQuery(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ncip_partners'"
        ));
        const ncipTx = parseInt(dbQuery(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ncip_transactions'"
        ));
        expect(ncipPartners).toBe(1);
        expect(ncipTx).toBe(1);
    });
});

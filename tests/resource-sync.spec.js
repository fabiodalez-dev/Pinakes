// @ts-check
/**
 * E2E — ResourceSync plugin tests (v0.7.1)
 *
 * Covers:
 *  1. Plugin registered in plugins table
 *  2. GET /.well-known/resourcesync → Source Description XML
 *  3. Source Description contains rs:md capability="description"
 *  4. Source Description links to capabilitylist
 *  5. GET /resync/capabilitylist.xml → Capability List XML
 *  6. Capability List has rs:md capability="capabilitylist"
 *  7. Capability List lists resourcelist and changelist
 *  8. GET /resync/resourcelist.xml → Resource List XML
 *  9. Resource List has rs:md capability="resourcelist"
 * 10. Resource List entries have <loc> pointing to /api/bibframe/book/...
 * 11. GET /resync/changelist.xml → Change List XML
 * 12. Change List has rs:md capability="changelist"
 * 13. GET /resync/changelist.xml?from=2020-01-01 → filtered change list
 * 14-16. Change List: rs:md/@from always present, chronological order, UTC ?from=
 * 17. require_basic_auth=1 → 401 Basic challenge / 200 with admin credentials
 *
 * Run: /tmp/run-e2e.sh tests/resource-sync.spec.js --config=tests/playwright.config.js --workers=1
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

test.skip(
    !DB_USER || !DB_NAME,
    'Missing E2E env (DB_*)'
);

test.describe.serial('ResourceSync plugin — v0.7.1 (17 tests)', () => {
    /** @type {import('@playwright/test').BrowserContext} */
    let context;
    /** @type {import('@playwright/test').Page} */
    let page;
    let pluginId = 0;
    let activatedHere = false;

    test.beforeAll(async ({ browser }) => {
        context = await browser.newContext();
        page    = await context.newPage();

        // Login as admin.
        await page.goto(`${BASE}/accedi`);
        await page.fill('input[name="email"]', ADMIN_EMAIL);
        await page.fill('input[name="password"]', ADMIN_PASS);
        await Promise.all([
            page.waitForURL(/\/admin\//, { timeout: 15000 }),
            page.click('button[type="submit"]'),
        ]);

        // Ensure resource-sync plugin is active, through the real lifecycle
        // endpoint (flipping is_active in SQL leaves the route cache stale).
        // The plugin list renders activatePlugin(<id>), so the old
        // button[onclick*="resource-sync"] selector never matched anything.
        const row = dbQuery("SELECT CONCAT(id, ':', is_active) FROM plugins WHERE name = 'resource-sync'");
        const [rsId, rsActive] = row.split(':');
        pluginId = Number(rsId);
        if (rsActive !== '1') {
            await page.goto(`${BASE}/admin/plugins`);
            const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
            const status = await page.evaluate(async ({ base, id, token }) => {
                const r = await fetch(`${base}/admin/plugins/${id}/activate`, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': token || '', 'Content-Type': 'application/json' },
                    body: '{}',
                });
                return r.status;
            }, { base: BASE, id: pluginId, token: csrf });
            expect(status).toBe(200);
            activatedHere = true;
        }
    });

    test.afterAll(async () => {
        // Leave the plugin as found.
        if (activatedHere && page) {
            await page.goto(`${BASE}/admin/plugins`);
            const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
            await page.evaluate(async ({ base, id, token }) => {
                await fetch(`${base}/admin/plugins/${id}/deactivate`, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': token || '', 'Content-Type': 'application/json' },
                    body: '{}',
                });
            }, { base: BASE, id: pluginId, token: csrf });
        }
        await context?.close();
    });

    // ── Test 1: Plugin registration ──────────────────────────────────────────

    test('1. resource-sync plugin registered in plugins table', async () => {
        const name = dbQuery("SELECT name FROM plugins WHERE name = 'resource-sync'");
        expect(name).toBe('resource-sync');
    });

    // ── Tests 2-4: /.well-known/resourcesync (Source Description) ────────────

    test('2. GET /.well-known/resourcesync → 200 with XML Content-Type', async ({ request }) => {
        const res = await request.get(`${BASE}/.well-known/resourcesync`);
        expect(res.status()).toBe(200);
        const ct = res.headers()['content-type'] ?? '';
        expect(ct).toContain('application/xml');
    });

    test('3. Source Description has rs:md capability="description"', async ({ request }) => {
        const res = await request.get(`${BASE}/.well-known/resourcesync`);
        const body = await res.text();
        expect(body).toContain('capability="description"');
    });

    test('4. Source Description links to capabilitylist', async ({ request }) => {
        const res = await request.get(`${BASE}/.well-known/resourcesync`);
        const body = await res.text();
        expect(body).toContain('capabilitylist.xml');
        expect(body).toContain('capability="capabilitylist"');
    });

    // ── Tests 5-7: /resync/capabilitylist.xml ─────────────────────────────────

    test('5. GET /resync/capabilitylist.xml → 200 with XML Content-Type', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/capabilitylist.xml`);
        expect(res.status()).toBe(200);
        const ct = res.headers()['content-type'] ?? '';
        expect(ct).toContain('application/xml');
    });

    test('6. Capability List has rs:md capability="capabilitylist"', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/capabilitylist.xml`);
        const body = await res.text();
        expect(body).toContain('capability="capabilitylist"');
    });

    test('7. Capability List advertises resourcelist and changelist capabilities', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/capabilitylist.xml`);
        const body = await res.text();
        expect(body).toContain('capability="resourcelist"');
        expect(body).toContain('capability="changelist"');
        expect(body).toContain('resourcelist.xml');
        expect(body).toContain('changelist.xml');
    });

    // ── Tests 8-10: /resync/resourcelist.xml ─────────────────────────────────

    test('8. GET /resync/resourcelist.xml → 200 with XML Content-Type', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/resourcelist.xml`);
        expect(res.status()).toBe(200);
        const ct = res.headers()['content-type'] ?? '';
        expect(ct).toContain('application/xml');
    });

    test('9. Resource List has rs:md capability="resourcelist"', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/resourcelist.xml`);
        const body = await res.text();
        expect(body).toContain('capability="resourcelist"');
    });

    test('10. Resource List entries link to BIBFRAME only when that plugin is active', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/resourcelist.xml`);
        const body = await res.text();
        // Small catalogues are one <urlset>; a larger one is a <sitemapindex>.
        expect(body).toMatch(/<(urlset|sitemapindex)/);
        if (body.includes('<urlset') && body.includes('<url>')) {
            const bibframe = dbQuery("SELECT COUNT(*) FROM plugins WHERE name = 'bibframe-linked-data' AND is_active = 1");
            if (bibframe === '1') {
                expect(body).toContain('/api/bibframe/book/');
            } else {
                expect(body).not.toContain('/api/bibframe/book/');
            }
        }
    });

    // ── Tests 11-13: /resync/changelist.xml ──────────────────────────────────

    test('11. GET /resync/changelist.xml → 200 with XML Content-Type', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/changelist.xml`);
        expect(res.status()).toBe(200);
        const ct = res.headers()['content-type'] ?? '';
        expect(ct).toContain('application/xml');
    });

    test('12. Change List has rs:md capability="changelist"', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/changelist.xml`);
        const body = await res.text();
        expect(body).toContain('capability="changelist"');
    });

    test('13. GET /resync/changelist.xml?from=2020-01-01 → valid filtered change list', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/changelist.xml?from=2020-01-01`);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('capability="changelist"');
        // from attribute must appear when filter is specified
        expect(body).toContain('from=');
    });

    // ── Tests 14-16: standard shape of the Change List ───────────────────────

    test('14. Change List always carries rs:md/@from, even without ?from=', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/changelist.xml`);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toMatch(/<rs:md[^>]*capability="changelist"[^>]*from="\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z"/);
    });

    test('15. Change List entries are in chronological order and use no rel="next"/"prev"', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/changelist.xml`);
        const body = await res.text();
        expect(body).not.toContain('rel="next"');
        expect(body).not.toContain('rel="prev"');
        const stamps = [...body.matchAll(/<lastmod>([^<]+)<\/lastmod>/g)].map((m) => Date.parse(m[1]));
        for (let i = 1; i < stamps.length; i++) {
            expect(stamps[i]).toBeGreaterThanOrEqual(stamps[i - 1]);
        }
    });

    test('16. ?from= is a UTC instant: echoed normalised in rs:md/@from', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/changelist.xml?from=2020-01-01T10:00:00Z`);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('from="2020-01-01T10:00:00Z"');
    });

    // ── Test 17: opt-in Basic Auth gate (require_basic_auth=1) ───────────────

    test('17. require_basic_auth=1 → 401 Basic challenge anonymously, 200 with admin credentials', async ({ request }) => {
        const pluginId = dbQuery("SELECT id FROM plugins WHERE name = 'resource-sync'");
        expect(pluginId).toMatch(/^\d+$/);
        const previous = dbQuery(
            `SELECT IFNULL((SELECT setting_value FROM plugin_settings WHERE plugin_id = ${pluginId} AND setting_key = 'require_basic_auth'), '__absent__')`
        );
        dbQuery(
            `INSERT INTO plugin_settings (plugin_id, setting_key, setting_value, created_at)
             VALUES (${pluginId}, 'require_basic_auth', '1', NOW())
             ON DUPLICATE KEY UPDATE setting_value = '1'`
        );
        try {
            const anon = await request.get(`${BASE}/resync/capabilitylist.xml`);
            expect(anon.status()).toBe(401);
            expect(anon.headers()['www-authenticate'] ?? '').toMatch(/^Basic realm="ResourceSync"/);

            const wrong = await request.get(`${BASE}/resync/capabilitylist.xml`, {
                headers: { Authorization: 'Basic ' + Buffer.from(`${ADMIN_EMAIL}:not-the-password`).toString('base64') },
            });
            expect(wrong.status()).toBe(401);
            expect(wrong.headers()['www-authenticate'] ?? '').toContain('Basic');

            const ok = await request.get(`${BASE}/resync/capabilitylist.xml`, {
                headers: { Authorization: 'Basic ' + Buffer.from(`${ADMIN_EMAIL}:${ADMIN_PASS}`).toString('base64') },
            });
            expect(ok.status()).toBe(200);
            expect(await ok.text()).toContain('capability="capabilitylist"');
        } finally {
            if (previous === '__absent__') {
                dbQuery(`DELETE FROM plugin_settings WHERE plugin_id = ${pluginId} AND setting_key = 'require_basic_auth'`);
            } else {
                dbQuery(
                    `UPDATE plugin_settings SET setting_value = '${previous.replace(/'/g, "''")}'
                     WHERE plugin_id = ${pluginId} AND setting_key = 'require_basic_auth'`
                );
            }
        }
    });
});

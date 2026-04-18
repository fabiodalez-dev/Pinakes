// @ts-check
/**
 * Plugin-integrity regression for issue #101.
 *
 * After any upgrade / fresh install, the 9 bundled plugins must:
 *   1) exist as directories on disk under storage/plugins/
 *   2) have corresponding rows in the `plugins` table
 *   3) not trigger "Main file not found" at load time
 *   4) not get deleted by cleanupOrphanPlugins on the next page load
 *
 * This runs after an install/upgrade has completed, hits any admin page
 * once to force loadActivePlugins() + cleanupOrphanPlugins(), then checks
 * the DB state AGAIN — regression against the Hans scenario where SQL
 * inserts were wiped by the orphan sweep.
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
const INSTALL_ROOT = process.env.E2E_INSTALL_ROOT || '';

function mysqlArgs(sql) {
    const args = ['-u', DB_USER, `-p${DB_PASS}`];
    if (DB_SOCKET) args.push('--socket=' + DB_SOCKET);
    args.push(DB_NAME, '-N', '-B', '-e', sql);
    return args;
}
function dbQuery(sql) {
    return execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout: 10000 }).trim();
}

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME || !INSTALL_ROOT,
    'Missing env (ADMIN_EMAIL/PASS, DB_*, E2E_INSTALL_ROOT)');

const EXPECTED_BUNDLED = [
    'api-book-scraper',
    'deezer',
    'dewey-editor',
    'digital-library',
    'discogs',
    'goodlib',
    'musicbrainz',
    'open-library',
    'z39-server',
];

test.describe.serial('Plugin integrity regression (#101)', () => {
    /** @type {import('@playwright/test').BrowserContext} */
    let context;
    /** @type {import('@playwright/test').Page} */
    let page;

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
    });

    test.afterAll(async () => {
        await context?.close();
    });

    test('1. All 9 bundled plugin folders exist on disk', async () => {
        const fs = require('fs');
        const path = require('path');
        for (const plugin of EXPECTED_BUNDLED) {
            const dir = path.join(INSTALL_ROOT, 'storage', 'plugins', plugin);
            expect(fs.existsSync(dir), `plugin folder missing: ${dir}`).toBe(true);
            const pluginJson = path.join(dir, 'plugin.json');
            expect(fs.existsSync(pluginJson), `plugin.json missing: ${pluginJson}`).toBe(true);
            const mainFile = path.join(dir, 'wrapper.php');
            // main_file is required for active-plugin loading — every bundled
            // plugin in BundledPlugins::LIST must have it (even if it's a stub
            // for metadata-only entries like deezer/musicbrainz)
            expect(fs.existsSync(mainFile), `wrapper.php missing: ${mainFile}`).toBe(true);
        }
    });

    test('2. All 9 plugins are registered in DB after admin page hit', async () => {
        // Hit /admin/plugins — this calls loadActivePlugins() →
        // autoRegisterBundledPlugins() + cleanupOrphanPlugins()
        await page.goto(`${BASE}/admin/plugins`);
        await page.waitForLoadState('domcontentloaded');

        const names = dbQuery("SELECT name FROM plugins ORDER BY name").split('\n').filter(Boolean);
        for (const plugin of EXPECTED_BUNDLED) {
            expect(names, `DB missing bundled plugin row: ${plugin}`).toContain(plugin);
        }
    });

    test('3. DB state is stable on a second page load (orphan-sweep regression)', async () => {
        // If cleanupOrphanPlugins wrongly treats bundled plugins as orphan,
        // a second hit would delete them. Regression against the scenario in
        // issue #101 where 4 INSERTs were wiped 23 seconds later.
        await page.goto(`${BASE}/admin/dashboard`);
        await page.waitForLoadState('domcontentloaded');
        await page.goto(`${BASE}/admin/plugins`);
        await page.waitForLoadState('domcontentloaded');

        const count = parseInt(
            dbQuery(`SELECT COUNT(*) FROM plugins WHERE name IN (${EXPECTED_BUNDLED.map(n => `'${n}'`).join(',')})`),
            10
        );
        expect(count, `expected ${EXPECTED_BUNDLED.length} bundled rows, got ${count}`)
            .toBe(EXPECTED_BUNDLED.length);
    });

    test('4. Optional music plugins (discogs/deezer/musicbrainz) default to inactive', async () => {
        // Safety: activating them on a server that can't reach external APIs
        // would throw — they must start disabled and be opt-in.
        const rows = dbQuery(
            "SELECT CONCAT(name, '=', is_active) FROM plugins " +
            "WHERE name IN ('discogs','deezer','musicbrainz') ORDER BY name"
        ).split('\n').filter(Boolean);
        expect(rows).toContain('deezer=0');
        expect(rows).toContain('discogs=0');
        expect(rows).toContain('musicbrainz=0');
    });

    test('5. No "Main file not found" errors in app.log', async () => {
        const fs = require('fs');
        const path = require('path');
        const logPath = path.join(INSTALL_ROOT, 'storage', 'logs', 'app.log');
        if (!fs.existsSync(logPath)) return; // fresh install may have empty log
        const log = fs.readFileSync(logPath, 'utf-8');
        // Look at the TAIL (last ~200 lines) — earlier entries may be from
        // install-time transient states we don't care about.
        const tail = log.split('\n').slice(-200).join('\n');
        expect(tail, 'Main file not found error in recent log').not.toContain('Main file not found');
        expect(tail, 'Failed to load plugin recent log').not.toMatch(/Failed to load plugin/);
    });
});

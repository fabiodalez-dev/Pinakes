// @ts-check
/**
 * Plugin-integrity regression for issue #101.
 *
 * After any upgrade / fresh install, the 16 bundled plugins must:
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
const DB_HOST    = process.env.E2E_DB_HOST   || '';
const DB_PORT    = process.env.E2E_DB_PORT   || '';
const INSTALL_ROOT = process.env.E2E_INSTALL_ROOT || '';

function mysqlArgs(sql) {
    const args = ['-u', DB_USER];
    if (DB_SOCKET) {
        args.push('--socket=' + DB_SOCKET);
    } else {
        if (DB_HOST) args.push('-h', DB_HOST);
        if (DB_PORT) args.push('-P', DB_PORT);
    }
    args.push(DB_NAME, '-N', '-B', '-e', sql);
    return args;
}
function dbQuery(sql) {
    return execFileSync('mysql', mysqlArgs(sql), {
        encoding: 'utf-8', timeout: 10000,
        env: { ...process.env, MYSQL_PWD: DB_PASS },
    }).trim();
}

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME || !INSTALL_ROOT,
    'Missing env (ADMIN_EMAIL/PASS, DB_*, E2E_INSTALL_ROOT)');

const EXPECTED_BUNDLED = [
    'api-book-scraper',
    'archives',
    'bibframe-linked-data',
    'deezer',
    'dewey-editor',
    'digital-library',
    'discogs',
    'goodlib',
    'musicbrainz',
    'ncip-server',
    'oai-pmh-server',
    'open-library',
    'openurl-resolver',
    'resource-sync',
    'viaf-authority',
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

    test('1. All 16 bundled plugin folders exist on disk', async () => {
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

    test('2. All 16 plugins are registered in DB after admin page hit', async () => {
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

    test('6. Every activatable plugin activates cleanly (schema lands)', async () => {
        // Generalises the book-club activation failure (#233): book-club shipped a
        // hard FK to a core table that doesn't exist on every install, so its very
        // first CREATE TABLE aborted the whole activation — and that reached a real
        // user. The same blind spot exists for EVERY plugin, and for any added later.
        //
        // Here we activate every plugin that isn't already active and assert its
        // onActivate/ensureSchema succeeds (a failure comes back from the endpoint as
        // { success:false, message:"... Schema activation failed for: <table> ..." })
        // and that it ends up active. The plugin list is read from the DB, so a plugin
        // added in the future is covered automatically — no per-plugin test to write.
        //
        // The three external-API plugins (see test 4) are the deliberate exception:
        // their onActivate reaches out to discogs/deezer/musicbrainz and legitimately
        // throws when the CI host is offline, so they stay opt-in and are excluded.
        const EXTERNAL_OPT_IN = ['discogs', 'deezer', 'musicbrainz'];

        await page.goto(`${BASE}/admin/plugins`);
        await page.waitForLoadState('domcontentloaded');
        const csrf = await page.evaluate(() =>
            document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        );

        const plugins = dbQuery('SELECT id, name FROM plugins WHERE is_active = 0 ORDER BY id')
            .split('\n')
            .filter(Boolean)
            .map((r) => {
                const parts = r.split('\t');
                return { id: Number(parts[0]), name: parts.slice(1).join('\t') };
            })
            .filter((p) => !EXTERNAL_OPT_IN.includes(p.name));

        test.skip(plugins.length === 0, 'no activatable inactive plugins');

        const failures = [];
        for (const p of plugins) {
            const res = await page.evaluate(async ({ base, id, token }) => {
                const r = await fetch(`${base}/admin/plugins/${id}/activate`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': token, 'Content-Type': 'application/json' },
                    body: '{}',
                });
                let data = {};
                try { data = await r.json(); } catch (_) { /* non-JSON body */ }
                return { status: r.status, data };
            }, { base: BASE, id: p.id, token: csrf });

            const active = dbQuery(`SELECT is_active FROM plugins WHERE id=${p.id}`);
            if (!res.data.success || active !== '1') {
                failures.push(`${p.name} (#${p.id}): ${res.data.message || ('HTTP ' + res.status)}`);
            }
        }

        expect(failures, `plugin activation failed:\n${failures.join('\n')}`).toHaveLength(0);
    });
});

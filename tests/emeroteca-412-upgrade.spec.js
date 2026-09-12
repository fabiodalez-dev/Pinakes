// Real upgrade of the Emeroteca (#412) through the admin UI, in four phases.
//
// Runs in the dedicated job of .github/workflows/ci-real-upgrade.yml — never in
// the shared browser shards, which install the current branch fresh and have no
// previous release to upgrade from:
//
//   prepare  on an install of the previous release: activate the plugin and
//            write holdings the upgrade must preserve
//   upgrade  upload the candidate package through /admin/updates and install it
//   verify   the holdings survived, the workflow stayed Complete, and a
//            standalone article can be created
//   fresh    on a clean install of the candidate: first activation leaves the
//            initial workflow unset for the administrator to choose
//
// The job pins the previous release to one that predates Emeroteca 1.5.0, so
// `prepare` can assert the new table is absent: the scenario under test is an
// existing collection meeting standalone articles for the first time. Expected
// versions are read from this checkout, so the test does not rot at the next
// release.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.env.E2E_BASE_URL;
const phase = process.env.E2E_412_UPGRADE_PHASE;
test.skip(!BASE || !phase, 'Runs only in the dedicated real-upgrade job (phase given by E2E_412_UPGRADE_PHASE)');

const repoRoot = path.resolve(__dirname, '..');
const expectedAppVersion = JSON.parse(fs.readFileSync(path.join(repoRoot, 'version.json'), 'utf8')).version;
const expectedPluginVersion = JSON.parse(fs.readFileSync(path.join(repoRoot, 'storage/plugins/emeroteca/plugin.json'), 'utf8')).version;

// Socket locally, TCP in CI. The CI `mysql` wrapper only routes to the service
// container when no endpoint is given, so passing `-S` unconditionally — as this
// helper used to — pointed it at a socket that does not exist on the runner.
function db(sql) {
    const args = [];
    if (process.env.E2E_DB_SOCKET) {
        args.push('-S', process.env.E2E_DB_SOCKET);
    } else if (process.env.E2E_DB_HOST) {
        args.push('-h', process.env.E2E_DB_HOST, '-P', process.env.E2E_DB_PORT || '3306');
    }
    args.push('-u', process.env.E2E_DB_USER, process.env.E2E_DB_NAME, '-N', '-B', '-e', sql);
    return execFileSync('mysql', args, { encoding: 'utf8', env: { ...process.env, MYSQL_PWD: process.env.E2E_DB_PASS } }).trim();
}

function modeSetting() {
    return db("SELECT setting_value FROM plugin_settings WHERE plugin_id=(SELECT id FROM plugins WHERE name='emeroteca') AND setting_key='mode'");
}

async function login(page) {
    await page.goto(BASE + '/admin/plugins');
    await page.locator('[name=email]').fill(process.env.E2E_ADMIN_EMAIL);
    await page.locator('[name=password]').fill(process.env.E2E_ADMIN_PASS);
    await page.locator('button[type=submit]').click();
    await page.waitForURL(u => u.pathname.startsWith('/admin'));
    await page.goto(BASE + '/admin/plugins');
}

async function activate(page) {
    const id = db("SELECT id FROM plugins WHERE name='emeroteca'");
    const card = page.locator(`[data-plugin-id="${id}"]`).first();
    await card.locator('button:has-text("Attiva")').click();
    const confirmation = page.locator('.swal2-confirm');
    await expect(confirmation).toBeVisible();
    await confirmation.click();
    // Activation runs real DDL — six tables, their foreign keys and the
    // information_schema probes — so the default 5 s poll is a race it can lose
    // on a busy runner. It did locally, right after an upgrade had just run.
    await expect.poll(() => db(`SELECT is_active FROM plugins WHERE id=${Number(id)}`), { timeout: 30_000 }).toBe('1');
    await page.goto(BASE + '/admin/periodicals');
}

test('Emeroteca 412 real upgrade, phase from E2E_412_UPGRADE_PHASE', async ({ page }) => {
    // The install step extracts and migrates a full package: minutes, not seconds.
    test.setTimeout(phase === 'upgrade' ? 600_000 : 60_000);
    await login(page);

    if (phase === 'prepare') {
        await activate(page);
        expect(db("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='emeroteca_contributi'")).toBe('0');
        db("INSERT INTO emeroteca_testate(titolo) VALUES('Legacy412'); SET @t=LAST_INSERT_ID(); INSERT INTO emeroteca_annate(testata_id,anno,volume) VALUES(@t,2019,'7'); SET @a=LAST_INSERT_ID(); INSERT INTO emeroteca_fascicoli(annata_id,numero,stato) VALUES(@a,'1','posseduto'); SET @f=LAST_INSERT_ID(); INSERT INTO emeroteca_articoli(fascicolo_id,titolo,pagina_inizio,pagina_fine) VALUES(@f,'Legacy412 article',138,148)");
        return;
    }

    if (phase === 'upgrade') {
        const zip = process.env.E2E_ZIP_PATH;
        expect(zip && fs.existsSync(zip), `candidate package not found: ${zip}`).toBeTruthy();
        await page.goto(BASE + '/admin/updates', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('#uppy-manual-update')).toBeVisible({ timeout: 10_000 });
        await page.waitForFunction(() => typeof window.Uppy !== 'undefined' && typeof window.UppyDragDrop !== 'undefined', null, { timeout: 15_000 });
        const fileInput = page.locator('#uppy-manual-update input[type="file"]').first();
        await fileInput.waitFor({ state: 'attached', timeout: 10_000 });
        await fileInput.setInputFiles(zip);
        const submit = page.locator('#manual-update-submit-btn');
        await expect(submit).toBeEnabled({ timeout: 10_000 });
        const uploaded = page.waitForResponse(r => r.url().includes('/admin/updates/upload') && r.request().method() === 'POST', { timeout: 120_000 });
        const installed = page.waitForResponse(r => r.url().includes('/admin/updates/install-manual') && r.request().method() === 'POST', { timeout: 540_000 });
        await submit.click();
        const confirmation = page.locator('.swal2-confirm');
        await expect(confirmation).toBeVisible({ timeout: 10_000 });
        await confirmation.click();
        expect((await uploaded).status(), 'package upload').toBe(200);
        const response = await installed;
        const raw = await response.text();
        // A PHP warning printed before the JSON is the classic way this endpoint
        // breaks: say so instead of failing on a parse error.
        expect(response.headers()['content-type'] || '', `install returned non-JSON: ${raw.slice(0, 400)}`).toContain('application/json');
        const body = JSON.parse(raw);
        expect(response.status(), 'install HTTP status').toBe(200);
        expect(body.success, `install failed: ${body.error || 'no error message'}`).toBe(true);
        return;
    }

    if (phase === 'verify') {
        await page.goto(BASE + '/admin/periodicals');
        expect(JSON.parse(fs.readFileSync(path.join(process.env.E2E_INSTALL_ROOT, 'version.json'), 'utf8')).version).toBe(expectedAppVersion);
        expect(db("SELECT version FROM plugins WHERE name='emeroteca'")).toBe(expectedPluginVersion);
        // An existing collection keeps the workflow it was run with.
        expect(modeSetting()).toBe('complete');
        expect(db("SELECT COUNT(*) FROM emeroteca_articoli WHERE titolo='Legacy412 article' AND pagina_inizio=138 AND pagina_fine=148")).toBe('1');
        expect(db("SELECT COUNT(*) FROM emeroteca_fascicoli WHERE stato='posseduto'")).toBe('1');
        expect(db('SELECT COUNT(*) FROM emeroteca_contributi')).toBe('0');
        await page.goto(BASE + '/admin/periodicals/articles/create');
        await page.locator('[name=titolo]').fill('Upgraded412 article');
        await page.getByRole('button', { name: 'Salva articolo', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Modifica articolo' })).toBeVisible();
        expect(db("SELECT COUNT(*) FROM emeroteca_contributi WHERE titolo='Upgraded412 article'")).toBe('1');
        const id = db("SELECT id FROM plugins WHERE name='emeroteca'");
        await page.goto(BASE + `/admin/plugins/${id}/settings`);
        // The chooser is radio rows now, not a <select>.
        await expect(page.locator('input[name=mode][value=complete]')).toBeChecked();
        return;
    }

    if (phase === 'fresh') {
        await activate(page);
        // Nothing decides the initial workflow on the administrator's behalf: no
        // setting is written, the plugin behaves as Complete meanwhile so nothing
        // is hidden, and the chooser is right there on the page.
        expect(modeSetting()).toBe('');
        await expect(page).toHaveURL(/\/admin\/periodicals$/);
        await expect(page.locator('input[name=mode][value=simple]')).toBeVisible();
        await expect(page.locator('input[name=mode][value=complete]')).toBeChecked();
        return;
    }

    throw new Error(`unknown E2E_412_UPGRADE_PHASE: ${phase}`);
});

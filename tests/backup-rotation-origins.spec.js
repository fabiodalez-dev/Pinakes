// Backup rotation, origins and the legacy directory format — through the real UI.
//
// The unit suite proves the rules against the class. These prove the operator
// actually gets them: the button really produces a preserved backup, the list
// really shows it, download and delete really work on both formats, and a
// rotation triggered from the UI really spares what it must.
//
// Requires: E2E_ADMIN_EMAIL, E2E_ADMIN_PASS, E2E_APP_ROOT (the SERVED docroot —
// filesystem assertions are meaningless against __dirname when the app under
// test is a different copy), plus E2E_BASE_URL/APP_URL.
//
// Everything it creates it removes, including on failure: storage/backups is
// live data on a dev install, and a test that leaves archives behind would
// itself feed the accumulation this whole change is about.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || process.env.APP_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const APP_ROOT = process.env.E2E_APP_ROOT || path.resolve(__dirname, '..');
const BACKUP_DIR = path.join(APP_ROOT, 'storage', 'backups');

test.skip(
    !ADMIN_EMAIL || !ADMIN_PASS,
    'E2E credentials not configured (set E2E_ADMIN_EMAIL, E2E_ADMIN_PASS)'
);

// Everything this spec plants carries the run id, so cleanup can never reach an
// archive that belonged to the installation before the test started.
const RUN_ID = Math.random().toString(16).slice(2, 8);
const planted = [];

/** A backup filename in the shape the app generates, tagged with the run id. */
function plantArchive(dayOfMonth, origin) {
    const stamp = `2020-01-${String(dayOfMonth).padStart(2, '0')}_000000`;
    const suffix = origin === 'auto' ? `${RUN_ID}` : `${RUN_ID}_${origin}`;
    const name = `backup_${stamp}_${suffix}.zip`;
    const full = path.join(BACKUP_DIR, name);
    // A real (if minimal) ZIP: the list reads a manifest from it, and a
    // zero-byte file would exercise the error path instead of the one we mean.
    fs.writeFileSync(full, Buffer.from('PK\x05\x06' + '\x00'.repeat(18), 'binary'));
    fs.utimesSync(full, new Date('2020-01-01'), new Date(`2020-01-${String(dayOfMonth).padStart(2, '0')}`));
    planted.push(full);
    return name;
}

function plantLegacyDir(dayOfMonth) {
    const name = `update_2020-02-${String(dayOfMonth).padStart(2, '0')}_000000`;
    const full = path.join(BACKUP_DIR, name);
    fs.mkdirSync(full, { recursive: true });
    // Fixtures are created by the runner; PHP may run as a different user.
    fs.chmodSync(full, 0o777);
    fs.writeFileSync(path.join(full, 'database.sql'), `-- e2e ${RUN_ID}\nSELECT 1;\n`);
    planted.push(full);
    return name;
}

function removePlanted() {
    for (const p of planted.splice(0)) {
        try {
            fs.rmSync(p, { recursive: true, force: true });
        } catch { /* already gone: the UI deleted it, which is the point */ }
    }
}

/**
 * Click a button that opens a SweetAlert confirmation, then confirm it.
 *
 * Every destructive action on this page goes through Swal.fire(), which is DOM,
 * not a native dialog — page.on('dialog') never sees it and the request the test
 * is waiting for is never sent.
 */
async function clickAndConfirm(page, locator) {
    await locator.click();
    const confirm = page.locator('.swal2-confirm');
    await confirm.waitFor({ state: 'visible', timeout: 15000 });
    await confirm.click();
}

/** Names currently shown in the backup table, read from the API the table renders. */
async function listedNames(page) {
    return page.evaluate(async (base) => {
        const r = await fetch(base + '/admin/updates/backups', { credentials: 'same-origin' });
        const d = await r.json();
        return (d.backups || []).map((b) => b.name);
    }, BASE);
}

test.describe.serial('Backup: origins, rotation and the legacy format', () => {
    let context;
    let page;
    const created = [];

    test.beforeAll(async ({ browser }) => {
        expect(fs.existsSync(BACKUP_DIR), `backup dir not found: ${BACKUP_DIR}`).toBeTruthy();
        context = await browser.newContext({ acceptDownloads: true });
        page = await context.newPage();
        await page.goto(`${BASE}/accedi`);
        await page.fill('input[name="email"]', ADMIN_EMAIL);
        await page.fill('input[name="password"]', ADMIN_PASS);
        await page.locator('button[type="submit"]').click();
        await page.waitForURL(/admin/, { timeout: 15000 });
    });

    test.afterAll(async () => {
        // Remove what the button created, too — those carry a real timestamp and
        // cannot be matched by run id.
        for (const name of created) {
            try { fs.rmSync(path.join(BACKUP_DIR, name), { force: true }); } catch { /* noop */ }
        }
        removePlanted();
        if (context) await context.close();
    });

    test('1. the button creates a backup marked as manual, and the list shows it', async () => {
        await page.goto(`${BASE}/admin/updates`);
        await page.waitForLoadState('networkidle');

        const before = await listedNames(page);

        await page.selectOption('#backupScope', 'db');
        const response = page.waitForResponse(
            (r) => r.url().endsWith('/admin/updates/backup') && r.request().method() === 'POST',
            { timeout: 120000 }
        );
        await clickAndConfirm(page, page.locator('button[data-pk-action="updates-create-backup"]'));
        const body = await (await response).json();

        expect(body.success, `backup failed: ${body.error || ''}`).toBeTruthy();
        created.push(body.name);

        // The origin has to be in the NAME, not only in the manifest: the
        // rotation decides from a glob and never opens the archive.
        expect(body.name).toMatch(/^backup_\d{4}-\d{2}-\d{2}_\d{6}_[0-9a-f]{6}_manual\.zip$/);
        expect(fs.existsSync(path.join(BACKUP_DIR, body.name))).toBeTruthy();

        const after = await listedNames(page);
        expect(after).toContain(body.name);
        expect(after.length).toBe(before.length + 1);

        // And it must be visible as a row the operator can act on.
        await page.locator('button[data-pk-action="updates-load-backups"]').click();
        await expect(page.locator(`[data-backup="${body.name}"][data-action="delete"]`)).toBeVisible({ timeout: 15000 });
    });

    test('2. creating a backup rotates the automatic ones and spares the deliberate ones', async () => {
        // Twelve automatic archives, all older than anything real on this install,
        // plus one manual and one safety copy made OLDER still — if the rotation
        // ever stopped honouring origins, those two would be the first to go.
        for (let d = 1; d <= 12; d++) plantArchive(d, 'auto');
        const manual = plantArchive(20, 'manual');
        const safety = plantArchive(21, 'safety');
        fs.utimesSync(path.join(BACKUP_DIR, manual), new Date('2019-01-01'), new Date('2019-01-01'));
        fs.utimesSync(path.join(BACKUP_DIR, safety), new Date('2019-01-02'), new Date('2019-01-02'));

        await page.goto(`${BASE}/admin/updates`);
        await page.waitForLoadState('networkidle');

        await page.selectOption('#backupScope', 'db');
        const response = page.waitForResponse(
            (r) => r.url().endsWith('/admin/updates/backup') && r.request().method() === 'POST',
            { timeout: 120000 }
        );
        await clickAndConfirm(page, page.locator('button[data-pk-action="updates-create-backup"]'));
        const body = await (await response).json();
        expect(body.success).toBeTruthy();
        created.push(body.name);

        const names = await listedNames(page);

        // The two deliberate restore points are still there.
        expect(names, 'a backup created from the button was rotated away').toContain(manual);
        expect(names, 'the pre-restore safety copy was rotated away').toContain(safety);
        expect(fs.existsSync(path.join(BACKUP_DIR, manual))).toBeTruthy();
        expect(fs.existsSync(path.join(BACKUP_DIR, safety))).toBeTruthy();

        // The planted automatic ones were the oldest on the install, so the
        // rotation must have taken them: retention is 10 by default and we added
        // twelve plus a fresh one.
        const survivingPlantedAuto = names.filter(
            (n) => n.includes(RUN_ID) && !n.includes('_manual') && !n.includes('_safety')
        );
        expect(
            survivingPlantedAuto.length,
            'the rotation did not trim the automatic archives at all'
        ).toBeLessThan(12);
    });

    test('3. a backup downloads from the list with the name it has on disk', async () => {
        const name = created[0];
        expect(name, 'test 1 must have created a backup').toBeTruthy();

        await page.goto(`${BASE}/admin/updates`);
        await page.waitForLoadState('networkidle');
        await page.locator('button[data-pk-action="updates-load-backups"]').click();

        const btn = page.locator(`[data-backup="${name}"][data-action="download"]`);
        await expect(btn).toBeVisible({ timeout: 15000 });

        const download = await Promise.all([
            page.waitForEvent('download', { timeout: 30000 }),
            btn.click(),
        ]).then(([d]) => d);

        expect(download.suggestedFilename()).toBe(name);
        const stream = await download.createReadStream();
        expect(stream, 'the download produced no body').toBeTruthy();
    });

    test('4. a legacy directory backup lists, downloads as .sql and is not restorable', async () => {
        const legacy = plantLegacyDir(3);

        await page.goto(`${BASE}/admin/updates`);
        await page.waitForLoadState('networkidle');
        await page.locator('button[data-pk-action="updates-load-backups"]').click();

        await expect(page.locator(`[data-backup="${legacy}"][data-action="download"]`)).toBeVisible({ timeout: 15000 });

        // Restore is deliberately not offered for the folder format — it would
        // have nothing to unpack.
        await expect(page.locator(`[data-backup="${legacy}"][data-action="restore"]`)).toHaveCount(0);

        const download = await Promise.all([
            page.waitForEvent('download', { timeout: 30000 }),
            page.locator(`[data-backup="${legacy}"][data-action="download"]`).click(),
        ]).then(([d]) => d);

        // The directory is served as the raw dump it contains.
        expect(download.suggestedFilename()).toBe(`${legacy}.sql`);
    });

    test('5. deleting removes both formats, directory contents included', async () => {
        const zipName = plantArchive(28, 'auto');
        const legacy = plantLegacyDir(9);
        const legacyPath = path.join(BACKUP_DIR, legacy);

        await page.goto(`${BASE}/admin/updates`);
        await page.waitForLoadState('networkidle');
        await page.locator('button[data-pk-action="updates-load-backups"]').click();

        const [zipResponse] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/admin/updates/backup/delete') && r.request().method() === 'POST', { timeout: 30000 }),
            clickAndConfirm(page, page.locator(`[data-backup="${zipName}"][data-action="delete"]`)),
        ]);
        expect(zipResponse.ok()).toBeTruthy();
        expect((await zipResponse.json()).success).toBe(true);
        expect(fs.existsSync(path.join(BACKUP_DIR, zipName)), 'the archive is still on disk').toBeFalsy();

        await page.locator('button[data-pk-action="updates-load-backups"]').click();
        await expect(page.locator(`[data-backup="${legacy}"][data-action="delete"]`)).toBeVisible({ timeout: 15000 });

        const [legacyResponse] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/admin/updates/backup/delete') && r.request().method() === 'POST', { timeout: 30000 }),
            clickAndConfirm(page, page.locator(`[data-backup="${legacy}"][data-action="delete"]`)),
        ]);

        expect(legacyResponse.ok()).toBeTruthy();
        expect((await legacyResponse.json()).success).toBe(true);

        // The whole directory, not just the row: a delete that left database.sql
        // behind would keep consuming the quota it was meant to release.
        expect(fs.existsSync(legacyPath), 'the legacy directory survived the delete').toBeFalsy();

        const names = await listedNames(page);
        expect(names).not.toContain(zipName);
        expect(names).not.toContain(legacy);
    });
});

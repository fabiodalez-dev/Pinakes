// @ts-check
/**
 * E2E for the Archives authority_records CRUD + linking (#103 phase 2).
 *
 * Flow:
 *   1. Plugin already active (covered by archives-crud.spec.js)
 *   2. Create a fonds to link against
 *   3. Create an authority record (person)
 *   4. Link the authority to the fonds with role=creator
 *   5. Verify the link renders on both detail pages
 *   6. Detach the authority from the fonds
 *   7. Edit the authority (change authorised_form)
 *   8. Soft-delete the authority
 *
 * Pre-clean + post-clean with a shared tag prefix so parallel runs and
 * leftover data never collide.
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
// via stdin.
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

const TAG = 'E2E_ARCHAUTH_' + Date.now();
const FONDS_REF = TAG + '_F1';
const AUTH_NAME = TAG + '_Stauning';
const AUTH_NAME_UPDATED = TAG + '_Stauning_Thorvald';

test.skip(
    !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
    'Missing E2E env (ADMIN_EMAIL/PASS, DB_*)'
);

test.describe.serial('Archives authorities CRUD + linking (#103 phase 2)', () => {
    /** @type {import('@playwright/test').BrowserContext} */
    let context;
    /** @type {import('@playwright/test').Page} */
    let page;

    test.beforeAll(async ({ browser }) => {
        // Preclean: link table, authority_records, archival_units (FK order).
        try {
            dbExec(`DELETE aua FROM archival_unit_authority aua
                    JOIN authority_records ar ON ar.id = aua.authority_id
                    WHERE ar.authorised_form LIKE '${TAG}%'`);
            dbExec(`DELETE FROM authority_records WHERE authorised_form LIKE '${TAG}%'`);
            dbExec(`DELETE FROM archival_units WHERE reference_code LIKE '${TAG}%'`);
        } catch { /* tables may not exist yet */ }

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
                    JOIN authority_records ar ON ar.id = aua.authority_id
                    WHERE ar.authorised_form LIKE '${TAG}%'`);
            dbExec(`DELETE FROM authority_records WHERE authorised_form LIKE '${TAG}%'`);
            dbExec(`DELETE FROM archival_units WHERE reference_code LIKE '${TAG}%'`);
        } catch { /* best-effort */ }
        await context?.close();
    });

    test('1. Create a fonds for linking', async () => {
        await page.goto(`${BASE}/admin/archives/new`);
        await page.fill('input[name="reference_code"]', FONDS_REF);
        await page.selectOption('select[name="level"]', 'fonds');
        await page.fill('input[name="constructed_title"]', 'E2E Auth Test Fonds');
        await Promise.all([
            page.waitForURL(/\/admin\/archives$/, { timeout: 10000 }),
            page.click('button[type="submit"]'),
        ]);
        const found = dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`);
        expect(found).toMatch(/^\d+$/);
    });

    test('2. Authority index page renders', async () => {
        await page.goto(`${BASE}/admin/archives/authorities`);
        await page.waitForLoadState('domcontentloaded');
        // Playwright text selectors don't treat comma as OR — the old
        // `'text=A, text=B'` selector was always matching the literal string.
        // Use a regex (`getByText`) so both IT and EN copy match.
        const hasEmpty = await page.getByText(/Nessun authority record|No authority records/i).first().isVisible().catch(() => false);
        const hasTable = await page.locator('table thead').isVisible().catch(() => false);
        expect(hasEmpty || hasTable).toBe(true);
    });

    test('3. Create a person authority record', async () => {
        await page.goto(`${BASE}/admin/archives/authorities/new`);
        await page.selectOption('select[name="type"]', 'person');
        await page.fill('input[name="authorised_form"]', AUTH_NAME);
        await page.fill('input[name="dates_of_existence"]', '1873-1942');
        await page.fill('textarea[name="history"]', 'Danish politician and statesman.');
        await Promise.all([
            page.waitForURL(/\/admin\/archives\/authorities$/, { timeout: 10000 }),
            page.click('button[type="submit"]'),
        ]);
        const row = dbQuery(
            `SELECT CONCAT_WS('|', type, dates_of_existence)
               FROM authority_records WHERE authorised_form = '${AUTH_NAME}' AND deleted_at IS NULL`
        );
        expect(row).toBe('person|1873-1942');
    });

    test('4. Authority appears in the list', async () => {
        await page.goto(`${BASE}/admin/archives/authorities`);
        await expect(page.locator('table')).toContainText(AUTH_NAME);
    });

    test('5. Link authority to fonds with role=creator', async () => {
        const fondsId = dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`);
        const authId = dbQuery(`SELECT id FROM authority_records WHERE authorised_form = '${AUTH_NAME}' AND deleted_at IS NULL`);

        await page.goto(`${BASE}/admin/archives/${fondsId}`);
        await page.waitForLoadState('domcontentloaded');
        // Phase 7 replaced the plain <select name="authority_id"> with a
        // type-ahead input + hidden <input name="authority_id"> that gets
        // filled by JS on option click. We skip the UI interaction and set
        // the hidden value directly — the server-side attach path is what
        // this test exercises. Mirrors archives-full.spec.js test 13.
        await page.evaluate((id) => {
            const hidden = document.querySelector('input[name="authority_id"]');
            if (hidden instanceof HTMLInputElement) { hidden.value = String(id); }
        }, authId);
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

    test('6. Link renders on both detail pages', async () => {
        const fondsId = dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`);
        const authId = dbQuery(`SELECT id FROM authority_records WHERE authorised_form = '${AUTH_NAME}' AND deleted_at IS NULL`);

        // On the fonds page: authority name + role visible
        await page.goto(`${BASE}/admin/archives/${fondsId}`);
        await expect(page.locator('body')).toContainText(AUTH_NAME);
        await expect(page.locator('body')).toContainText('creator');

        // On the authority page: fonds title + role visible
        await page.goto(`${BASE}/admin/archives/authorities/${authId}`);
        await expect(page.locator('body')).toContainText('E2E Auth Test Fonds');
        await expect(page.locator('body')).toContainText('creator');
    });

    test('7. Edit authority — update authorised_form', async () => {
        const authId = dbQuery(`SELECT id FROM authority_records WHERE authorised_form = '${AUTH_NAME}' AND deleted_at IS NULL`);
        await page.goto(`${BASE}/admin/archives/authorities/${authId}/edit`);
        await page.fill('input[name="authorised_form"]', AUTH_NAME_UPDATED);
        await Promise.all([
            page.waitForURL(new RegExp(`/admin/archives/authorities/${authId}$`), { timeout: 10000 }),
            page.click('button[type="submit"]'),
        ]);
        const updated = dbQuery(`SELECT authorised_form FROM authority_records WHERE id = ${authId}`);
        expect(updated).toBe(AUTH_NAME_UPDATED);
    });

    test('8. Detach authority from fonds', async () => {
        const fondsId = dbQuery(`SELECT id FROM archival_units WHERE reference_code = '${FONDS_REF}' AND deleted_at IS NULL`);
        const authId = dbQuery(`SELECT id FROM authority_records WHERE authorised_form = '${AUTH_NAME_UPDATED}' AND deleted_at IS NULL`);

        await page.goto(`${BASE}/admin/archives/${fondsId}`);
        // Destructive confirmations go through SweetAlert2 (archivesSwalConfirm
        // helper in views/authorities/show.php). Click the button, then the
        // swal confirm, which fires the real form submit.
        await page.click('button:has-text("scollega"), button:has-text("unlink")');
        await page.locator('.swal2-confirm').click();
        await page.waitForURL(new RegExp(`/admin/archives/${fondsId}$`), { timeout: 10000 });

        const linkCount = dbQuery(
            `SELECT COUNT(*) FROM archival_unit_authority
              WHERE archival_unit_id = ${fondsId} AND authority_id = ${authId}`
        );
        expect(linkCount).toBe('0');
    });

    test('9. Soft-delete the authority', async () => {
        const authId = dbQuery(`SELECT id FROM authority_records WHERE authorised_form = '${AUTH_NAME_UPDATED}' AND deleted_at IS NULL`);
        await page.goto(`${BASE}/admin/archives/authorities/${authId}`);
        await page.click('button:has-text("Elimina"), button:has-text("Delete")');
        await page.locator('.swal2-confirm').click();
        await page.waitForURL(/\/admin\/archives\/authorities$/, { timeout: 10000 });

        const deletedAt = dbQuery(`SELECT deleted_at FROM authority_records WHERE id = ${authId}`);
        expect(deletedAt).not.toBe('NULL');
        expect(deletedAt).not.toBe('');

        // List no longer shows it.
        await page.goto(`${BASE}/admin/archives/authorities`);
        const txt = await page.locator('body').textContent();
        expect(txt).not.toContain(AUTH_NAME_UPDATED);
    });
});

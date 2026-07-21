// @ts-check
/**
 * E2E — Collapsible plugin cataloguing sections on the book form (#274).
 *
 * The REICAT/SBN (z39-server) and MAG digital-copies (oai-pmh-server) sections
 * are injected into the book form via the book.form.fields hook and were made
 * collapsible accordions. Reusable regression coverage for:
 *   - collapsed by default on a fresh create (no data);
 *   - the header toggle opens the body on click;
 *   - auto-open when the record already carries that data (editing a
 *     catalogued / digitised book);
 *   - a collapsed section still submits (fields stay in the DOM).
 *
 * Requires admin login + the two plugins active; each section-specific test
 * skips if its plugin isn't injecting the section.
 *
 * Run: /tmp/run-e2e.sh tests/accordion-book-sections-274.spec.js --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE        = process.env.E2E_BASE_URL   || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const DB_USER     = process.env.E2E_DB_USER    || '';
const DB_PASS     = process.env.E2E_DB_PASS    || '';
const DB_NAME     = process.env.E2E_DB_NAME    || '';
const DB_SOCKET   = process.env.E2E_DB_SOCKET  || '';
const DB_HOST     = process.env.E2E_DB_HOST    || '';
const DB_PORT     = process.env.E2E_DB_PORT    || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER, 'Missing E2E env: ADMIN_EMAIL/PASS + DB_USER required');

function db(sql, batch = true) {
    const args = [];
    if (DB_HOST) { args.push('-h', DB_HOST); if (DB_PORT) args.push('-P', DB_PORT); }
    else if (DB_SOCKET) { args.push('-S', DB_SOCKET); }
    args.push('-u', DB_USER, DB_NAME);
    if (batch) args.push('-N', '-B');
    args.push('-e', sql);
    return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 15000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}

async function loginAdmin(page) {
    await page.goto(`${BASE}/login`);
    if (await page.locator('input[name="email"]').count()) {
        await page.fill('input[name="email"]', ADMIN_EMAIL);
        await page.fill('input[name="password"]', ADMIN_PASS);
        await Promise.all([
            page.waitForURL(/\/admin\//, { timeout: 15000 }).catch(() => null),
            page.click('button[type="submit"]'),
        ]);
    }
}

/** State of an accordion body: present + whether it's collapsed (hidden). */
async function bodyState(page, bodyId, toggleId) {
    return page.evaluate(([bid, tid]) => {
        const body = document.getElementById(bid);
        const toggle = document.getElementById(tid);
        return {
            togglePresent: !!toggle,
            bodyPresent: !!body,
            collapsed: body ? body.classList.contains('hidden') : null,
        };
    }, [bodyId, toggleId]);
}

test.describe.serial('Book form — collapsible plugin sections (#274)', () => {
    /** @type {number} */ let sbnBookId = 0;
    /** @type {number} */ let assetBookId = 0;
    let seededAssetId = 0;

    test.beforeAll(() => {
        const ids = db("SELECT id FROM libri WHERE deleted_at IS NULL ORDER BY id LIMIT 2").split('\n').map((s) => parseInt(s, 10)).filter(Boolean);
        sbnBookId = ids[0] || 0;
        assetBookId = ids[1] || ids[0] || 0;
        if (sbnBookId) {
            db(`UPDATE libri SET sbn_bid='IT\\\\ICCU\\\\E2E274\\\\0001' WHERE id=${sbnBookId}`, false);
        }
        if (assetBookId) {
            db(`INSERT INTO digital_assets (libro_id, url, filetype) VALUES (${assetBookId}, 'https://example.org/e2e274.pdf', 'PDF')`, false);
            seededAssetId = parseInt(db(`SELECT id FROM digital_assets WHERE libro_id=${assetBookId} AND url='https://example.org/e2e274.pdf' ORDER BY id DESC LIMIT 1`), 10) || 0;
        }
    });

    test.afterAll(() => {
        if (sbnBookId) db(`UPDATE libri SET sbn_bid=NULL WHERE id=${sbnBookId}`, false);
        if (seededAssetId) db(`DELETE FROM digital_assets WHERE id=${seededAssetId}`, false);
    });

    test('REICAT/SBN section is collapsed by default on create', async ({ page }) => {
        await loginAdmin(page);
        await page.goto(`${BASE}/admin/books/create`);
        await page.waitForTimeout(2500);
        const s = await bodyState(page, 'reicat-sbn-body', 'reicat-sbn-toggle');
        test.skip(!s.togglePresent, 'z39-server plugin not injecting the REICAT section');
        expect(s.bodyPresent).toBe(true);
        expect(s.collapsed).toBe(true);
    });

    test('REICAT/SBN header toggle opens the body on click', async ({ page }) => {
        await loginAdmin(page);
        await page.goto(`${BASE}/admin/books/create`);
        await page.waitForTimeout(2500);
        const before = await bodyState(page, 'reicat-sbn-body', 'reicat-sbn-toggle');
        test.skip(!before.togglePresent, 'z39-server plugin not injecting the REICAT section');
        await page.locator('#reicat-sbn-toggle').click();
        await page.waitForTimeout(300);
        const after = await bodyState(page, 'reicat-sbn-body', 'reicat-sbn-toggle');
        expect(after.collapsed).toBe(false);
    });

    test('REICAT/SBN auto-opens when the book already has SBN data', async ({ page }) => {
        test.skip(sbnBookId === 0, 'no book to seed sbn_bid on');
        await loginAdmin(page);
        await page.goto(`${BASE}/admin/books/edit/${sbnBookId}`);
        await page.waitForTimeout(3000);
        const s = await bodyState(page, 'reicat-sbn-body', 'reicat-sbn-toggle');
        test.skip(!s.togglePresent, 'z39-server plugin not injecting the REICAT section');
        expect(s.collapsed).toBe(false); // open because sbn_bid is set
    });

    test('MAG digital-copies section auto-opens when the book has assets', async ({ page }) => {
        test.skip(assetBookId === 0 || seededAssetId === 0, 'no book/asset seeded');
        await loginAdmin(page);
        await page.goto(`${BASE}/admin/books/edit/${assetBookId}`);
        await page.waitForTimeout(3000);
        const s = await bodyState(page, 'oai-digital-assets-body', 'oai-digital-assets-toggle');
        test.skip(!s.togglePresent, 'oai-pmh-server plugin not injecting the MAG section');
        expect(s.bodyPresent).toBe(true);
        expect(s.collapsed).toBe(false); // open because an asset exists
    });

    test('collapsed REICAT/SBN still submits — sbn_bid preserved on save', async ({ page }) => {
        test.skip(sbnBookId === 0, 'no book to test');
        await loginAdmin(page);
        await page.goto(`${BASE}/admin/books/edit/${sbnBookId}`);
        await page.waitForTimeout(3000);
        const s = await bodyState(page, 'reicat-sbn-body', 'reicat-sbn-toggle');
        test.skip(!s.togglePresent, 'z39-server plugin not injecting the REICAT section');
        // Collapse it, then save — the hidden inputs must still submit.
        if (s.collapsed === false) {
            await page.locator('#reicat-sbn-toggle').click();
            await page.waitForTimeout(300);
        }
        const save = page.locator('#bookForm button[type="submit"]').filter({ hasText: /Salva Modifiche/i }).first();
        await save.scrollIntoViewIfNeeded();
        await save.click();
        await page.waitForTimeout(1200);
        const confirm = page.locator('.swal2-confirm');
        if (await confirm.count() && await confirm.first().isVisible().catch(() => false)) {
            await Promise.all([page.waitForNavigation({ timeout: 15000 }).catch(() => null), confirm.first().click()]);
            await page.waitForTimeout(1200);
        }
        const stored = db(`SELECT sbn_bid FROM libri WHERE id=${sbnBookId}`);
        expect(stored).toContain('E2E274'); // sbn_bid survived the save with the section collapsed
    });
});

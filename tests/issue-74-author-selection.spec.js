// @ts-check
/**
 * Issue #74 regression — selecting EXISTING authors from the Choices.js
 * dropdown via CLICK on the suggestion (NOT typing + Enter).
 *
 * Companion to scraping-form-25.spec.js test 2.5 which exercises the
 * "type a new author + Enter" path. Together they cover both Choices.js
 * code paths the book form supports:
 *   - Click an existing suggestion → add as token
 *   - Type a new name + Enter      → add typed value as new token
 *     (defended by the _onEnterKey monkey-patch — see issue #74)
 *
 * Two scenarios:
 *   1. Pick a single existing author + save book → verify libri_autori link
 *   2. Pick two existing authors + save book → verify both links
 *
 * The authors are pre-seeded in beforeAll with a RUN_TAG so the cleanup
 * is deterministic and the test doesn't depend on the DB containing any
 * specific catalogue.
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const RUN_ID = Date.now().toString();
const RUN_TAG = `E2E_ISSUE74_${RUN_ID}`;

const AUTHOR_1 = `${RUN_TAG}_Author_Alpha`;
const AUTHOR_2 = `${RUN_TAG}_Author_Beta`;

function mysqlArgs(sql, batch = false) {
    const args = [];
    if (DB_HOST) {
        args.push('-h', DB_HOST);
        if (DB_PORT) args.push('-P', DB_PORT);
    } else if (DB_SOCKET) {
        args.push('-S', DB_SOCKET);
    }
    args.push('-u', DB_USER, DB_NAME);
    if (batch) args.push('-N', '-B');
    args.push('-e', sql);
    return args;
}

function dbQuery(sql) {
    return execFileSync('mysql', mysqlArgs(sql, true), {
        encoding: 'utf8',
        env: { ...process.env, MYSQL_PWD: DB_PASS },
    }).trim();
}

function dbExec(sql) {
    execFileSync('mysql', mysqlArgs(sql), {
        encoding: 'utf8',
        env: { ...process.env, MYSQL_PWD: DB_PASS },
    });
}

async function loginAsAdmin(page) {
    for (let attempt = 0; attempt < 2; attempt++) {
        await page.goto(`${BASE}/accedi`);
        await page.waitForLoadState('domcontentloaded');
        const email = page.locator('input[name="email"]');
        if (await email.isVisible({ timeout: 3000 }).catch(() => false)) {
            await email.fill(ADMIN_EMAIL);
            await page.fill('input[name="password"]', ADMIN_PASS);
            await page.locator('button[type="submit"]').click();
            try {
                await page.waitForURL(u => u.toString().includes('/admin'), { timeout: 30000 });
                return;
            } catch (_) {
                if (attempt === 1) throw _;
            }
        } else {
            return; // already authenticated
        }
    }
}

async function dismissAnySwals(page) {
    for (let i = 0; i < 3; i++) {
        const open = await page.locator('.swal2-container').isVisible().catch(() => false);
        if (!open) return;
        await page.keyboard.press('Escape').catch(() => {});
        await page.waitForTimeout(150);
    }
}

async function openCreateForm(page) {
    await page.goto(`${BASE}/admin/libri/crea`);
    await page.waitForLoadState('domcontentloaded');
    await dismissAnySwals(page);
    await page.locator('input[name="titolo"]').waitFor({ state: 'visible', timeout: 10000 });
}

async function submitAndWait(page) {
    await dismissAnySwals(page);
    const submitBtn = page.locator('#bookForm button[type="submit"], button[type="submit"]').first();
    await submitBtn.scrollIntoViewIfNeeded();
    await submitBtn.click();
    const swalConfirm = page.locator('.swal2-confirm:visible');
    if (await swalConfirm.isVisible({ timeout: 5000 }).catch(() => false)) {
        await swalConfirm.click();
    }
    await page.waitForFunction(
        () => !window.location.pathname.endsWith('/admin/libri/crea')
              && !window.location.pathname.includes('/admin/libri/modifica/'),
        null,
        { timeout: 30000 }
    );
    await page.waitForLoadState('domcontentloaded');
}

/**
 * Select an EXISTING author from the Choices.js dropdown.
 * Types the name to filter the list, waits for the matching suggestion
 * to appear, then CLICKS the suggestion item. This exercises the
 * code path issue #74 originally regressed on — the dropdown-click
 * flow for already-cataloged authors.
 */
async function selectExistingAuthor(page, authorName) {
    const wrap = page.locator('.choices').filter({ has: page.locator('#autori_select') }).first();
    await expect(wrap).toBeVisible({ timeout: 5000 });
    // Focus the Choices.js input. Clicking the wrapper opens the dropdown.
    await wrap.click();
    const input = wrap.locator('input.choices__input').first();
    // Type enough characters to trigger the server-side author search
    // (searchFloor: 1 in the Choices.js config). Use the unique RUN_TAG
    // prefix so we never accidentally match a non-test author.
    await input.fill(authorName);
    // Server-side search is debounced; wait for the matching suggestion.
    const suggestion = wrap.locator('.choices__list--dropdown .choices__item--selectable')
        .filter({ hasText: authorName }).first();
    await expect(suggestion).toBeVisible({ timeout: 10000 });
    await suggestion.click();
    // Token must appear in the chip area.
    await expect(
        wrap.locator('.choices__list--multiple .choices__item').filter({ hasText: authorName })
    ).toBeVisible({ timeout: 3000 });
}

let adminContext;
let page;
let setupOk = false;
let author1Id = 0;
let author2Id = 0;
const createdBookIds = [];

test.beforeAll(async ({ browser }) => {
    if (!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME) {
        throw new Error('Missing E2E_* env vars — run via /tmp/run-e2e.sh');
    }
    // Pre-seed two authors with RUN_TAG so the dropdown returns them
    // as existing matches. Without this, the "select existing" code path
    // can't be exercised (there's no existing author to match against).
    dbExec(`INSERT INTO autori (nome) VALUES ('${AUTHOR_1}'), ('${AUTHOR_2}')`);
    author1Id = Number(dbQuery(`SELECT id FROM autori WHERE nome='${AUTHOR_1}'`));
    author2Id = Number(dbQuery(`SELECT id FROM autori WHERE nome='${AUTHOR_2}'`));
    if (author1Id === 0 || author2Id === 0) {
        throw new Error(`Failed to seed test authors: id1=${author1Id} id2=${author2Id}`);
    }
    adminContext = await browser.newContext();
    page = await adminContext.newPage();
    await loginAsAdmin(page);
    setupOk = true;
});

test.afterAll(async () => {
    try {
        // FK-safe order: libri_autori first, then libri, then autori.
        for (const id of createdBookIds) {
            dbExec(`DELETE FROM libri_autori WHERE libro_id=${id}`);
            dbExec(`DELETE FROM libri WHERE id=${id}`);
        }
        // Stragglers tagged with RUN_TAG.
        dbExec(
            `DELETE la FROM libri_autori la JOIN libri l ON la.libro_id=l.id ` +
            `WHERE l.titolo LIKE '%${RUN_TAG}%'`
        );
        dbExec(`DELETE FROM libri WHERE titolo LIKE '%${RUN_TAG}%'`);
        dbExec(`DELETE FROM autori WHERE nome LIKE '%${RUN_TAG}%'`);
    } catch (e) {
        console.log('issue-74 cleanup warning:', e.message);
    }
    await adminContext?.close();
});

test.beforeEach(({}, testInfo) => {
    test.skip(!setupOk, 'Setup failed in beforeAll');
    // Generous timeout — selecting from Choices.js involves a server-side
    // author search (debounced) which can be slow on Apache+PHP-FPM under
    // load.
    testInfo.setTimeout(60_000);
});

test.describe.serial('Issue #74 — selecting EXISTING authors from Choices.js', () => {
    test('1. Single existing author selected via dropdown click → saved + linked', async () => {
        const title = `${RUN_TAG} SINGLE_AUTHOR`;
        await openCreateForm(page);
        await page.fill('input[name="titolo"]', title);
        await selectExistingAuthor(page, AUTHOR_1);
        await submitAndWait(page);
        const bookId = Number(dbQuery(
            `SELECT id FROM libri WHERE titolo='${title}' AND deleted_at IS NULL`
        ));
        expect(bookId).toBeGreaterThan(0);
        createdBookIds.push(bookId);
        // The pre-seeded author MUST be linked via libri_autori — by id,
        // not just by name, so we're sure Choices.js posted the FK value
        // (not a fresh duplicate row created by the typed string).
        const linkedRows = dbQuery(
            `SELECT a.id, a.nome FROM autori a JOIN libri_autori la ON la.autore_id=a.id ` +
            `WHERE la.libro_id=${bookId} ORDER BY a.id`
        );
        expect(linkedRows.split('\n').length).toBe(1);
        expect(linkedRows).toContain(String(author1Id));
        expect(linkedRows).toContain(AUTHOR_1);
        // Regression guard for issue #74: no extra "Alpha"-named duplicate
        // got created (the bug had Choices.js create a new author row even
        // when the user picked an existing one from the dropdown).
        const alphaCount = Number(dbQuery(
            `SELECT COUNT(*) FROM autori WHERE nome='${AUTHOR_1}'`
        ));
        expect(alphaCount).toBe(1);
    });

    test('2. Two existing authors selected sequentially → both linked, no duplicates', async () => {
        const title = `${RUN_TAG} TWO_AUTHORS`;
        await openCreateForm(page);
        await page.fill('input[name="titolo"]', title);
        await selectExistingAuthor(page, AUTHOR_1);
        await selectExistingAuthor(page, AUTHOR_2);
        await submitAndWait(page);
        const bookId = Number(dbQuery(
            `SELECT id FROM libri WHERE titolo='${title}' AND deleted_at IS NULL`
        ));
        expect(bookId).toBeGreaterThan(0);
        createdBookIds.push(bookId);
        // Both authors linked, by id, deterministic order.
        const linkedIds = dbQuery(
            `SELECT autore_id FROM libri_autori WHERE libro_id=${bookId} ORDER BY autore_id`
        ).split('\n').filter(Boolean).map(Number);
        expect(linkedIds).toEqual([author1Id, author2Id].sort((a, b) => a - b));
        // Issue #74 regression: no duplicate "Alpha" or "Beta" rows created.
        const alphaCount = Number(dbQuery(
            `SELECT COUNT(*) FROM autori WHERE nome='${AUTHOR_1}'`
        ));
        const betaCount = Number(dbQuery(
            `SELECT COUNT(*) FROM autori WHERE nome='${AUTHOR_2}'`
        ));
        expect(alphaCount).toBe(1);
        expect(betaCount).toBe(1);
    });
});

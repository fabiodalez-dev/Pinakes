// @ts-check
//
// Regression test for issue #74 — Choices.js author autocomplete must
// create a new author from the typed text when Enter is pressed against
// a HIGHLIGHTED-BUT-NON-MATCHING dropdown item.
//
// History (don't repeat):
//   • 2026-03-01 v0.4.9.4 — original fix (monkey-patch _onEnterKey)
//   • 2026-XX    CR round-11 review — refactored to capture-phase listener,
//                regressed the bug silently because nothing exercised this
//                exact path. Reported by @HansUwe52.
//   • 2026-05-20 v0.7.7 hotfix — monkey-patch restored.
//
// This test reproduces the exact "highlighted-but-non-matching + Enter"
// scenario so any future regression of the monkey-patch (e.g. another
// well-meaning "cleanup" refactor) trips here loudly instead of shipping
// to users.
//
// Run:
//   /tmp/run-e2e.sh tests/issue-74-author-autocomplete.spec.js \
//     --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE        = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

test.skip(
    !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
    'E2E credentials not configured (set E2E_ADMIN_EMAIL, E2E_ADMIN_PASS)',
);

// Unique suffix so re-runs don't collide with rows from a previous session.
const RUN_ID       = Date.now().toString(36);
const TRAP_AUTHOR  = `Norbert Bauer ${RUN_ID}`;   // must pre-exist; the highlight bait
const NEW_AUTHOR   = `Norbert Wex ${RUN_ID}`;     // must be created from typed text
const SHARED_PREFIX = `Norbert`;                  // the substring that makes Bauer the highlight

function dbExec(sql) {
    const args = [];
    if (DB_SOCKET) args.push('-S', DB_SOCKET);
    args.push('-u', DB_USER, DB_NAME, '-e', sql);
    return execFileSync('mysql', args, {
        encoding: 'utf8', timeout: 10000,
        env: { ...process.env, MYSQL_PWD: DB_PASS },
    }).trim();
}
function sqlq(value) { return "'" + String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'"; }

test.describe.serial('Issue #74 — Choices.js: Enter creates new author when dropdown highlight mismatches', () => {

    /** @type {import('@playwright/test').BrowserContext} */
    let context;
    /** @type {import('@playwright/test').Page} */
    let page;

    test.beforeAll(async ({ browser }) => {
        context = await browser.newContext();
        page = await context.newPage();

        // Login as admin
        await page.goto(`${BASE}/accedi`);
        await page.fill('input[name="email"]', ADMIN_EMAIL);
        await page.fill('input[name="password"]', ADMIN_PASS);
        await Promise.all([
            page.waitForURL(/\/(admin|profilo)/, { timeout: 15000 }),
            page.click('button[type="submit"]'),
        ]);

        // This suite tests Choices.js, not the author-create form. Seed the
        // autocomplete precondition directly and prove the real search API can
        // see it before exercising the keyboard path.
        dbExec(`DELETE FROM autori WHERE nome IN (${sqlq(TRAP_AUTHOR)}, ${sqlq(NEW_AUTHOR)})`);
        dbExec(`INSERT INTO autori (nome, created_at) VALUES (${sqlq(TRAP_AUTHOR)}, NOW())`);
        const search = await page.request.get(`${BASE}/api/search/autori?q=${encodeURIComponent(SHARED_PREFIX)}`);
        expect(search.status()).toBe(200);
        const results = await search.json();
        expect(results.some((author) => String(author.label || '').includes(TRAP_AUTHOR))).toBe(true);
    });

    test.afterAll(async () => {
        // The test does not commit a book, so neither author has a relation.
        try {
            dbExec(`DELETE FROM autori WHERE nome IN (${sqlq(TRAP_AUTHOR)}, ${sqlq(NEW_AUTHOR)})`);
        } catch (_) { /* cleanup is best-effort */ }
        await context.close();
    });

    test('Typing a new author name and pressing Enter creates the new author, NOT the highlighted existing match', async () => {
        // Go to the create-book form
        await page.goto(`${BASE}/admin/books/create`);
        await page.waitForLoadState('domcontentloaded');

        // Wait for Choices.js to render the cloned input
        const choicesInput = page.locator('label[for="autori_select"]')
            .locator('xpath=following::input[contains(@class,"choices__input--cloned")][1]');
        await expect(choicesInput).toBeVisible({ timeout: 10000 });

        // First let the shared-prefix server search render the existing trap.
        // Then complete the new name and press Enter inside the 200 ms debounce
        // window: that is the exact highlighted-but-no-longer-matching state
        // that regressed in #74.
        await choicesInput.click();
        await choicesInput.pressSequentially(SHARED_PREFIX, { delay: 50 });
        await page.waitForTimeout(700); // debounce + fetch + Choices render

        // Confirm the trap (existing author starting with shared prefix)
        // is present in the dropdown (regression-prone state).
        const trapItem = page.locator('.choices__list--dropdown .choices__item--selectable', {
            hasText: TRAP_AUTHOR,
        }).first();
        await expect(trapItem).toBeAttached({ timeout: 8000 });

        await choicesInput.pressSequentially(NEW_AUTHOR.slice(SHARED_PREFIX.length), { delay: 0 });
        await expect(choicesInput).toHaveValue(NEW_AUTHOR);

        // Press Enter. EXPECTED behaviour: the typed string becomes a
        // newly created author. BUG behaviour (the regression we guard
        // against): Choices.js auto-selects the highlighted "Norbert
        // Bauer" and discards the typed text.
        await choicesInput.press('Enter');

        // Give the create-author POST a chance to land.
        await page.waitForTimeout(800);

        // The new author should appear as a selected choice (Choices.js
        // renders selected items as `.choices__item--selectable` outside
        // the dropdown, inside `.choices__list--multiple`).
        const selectedItems = page.locator('.choices__list--multiple .choices__item');
        const selectedTexts = await selectedItems.allInnerTexts();
        const joined = selectedTexts.join('|');

        expect(
            joined,
            `Selected items must include the typed new author. Got: ${joined}`
        ).toContain(NEW_AUTHOR);

        expect(
            joined,
            `Selected items must NOT include the trap (highlighted match). Got: ${joined}`
        ).not.toContain(TRAP_AUTHOR);
    });

    test('Typing an EXISTING author name and pressing Enter still selects the existing match', async () => {
        // Counter-test: the monkey-patch must NOT regress the "type exact
        // existing name → Enter → select existing" path. Without this
        // assertion, a too-aggressive monkey-patch could create a duplicate.
        await page.goto(`${BASE}/admin/books/create`);
        await page.waitForLoadState('domcontentloaded');

        const choicesInput = page.locator('label[for="autori_select"]')
            .locator('xpath=following::input[contains(@class,"choices__input--cloned")][1]');
        await expect(choicesInput).toBeVisible({ timeout: 10000 });

        await choicesInput.click();
        await choicesInput.pressSequentially(TRAP_AUTHOR, { delay: 60 });
        await page.waitForTimeout(700); // 200ms debounce + ~500ms fetch

        // Find the trap row in the dropdown (it should be the highlighted
        // match since we typed its exact name).
        const trapMatch = page.locator('.choices__list--dropdown .choices__item--selectable', {
            hasText: TRAP_AUTHOR,
        }).first();
        await expect(trapMatch).toBeAttached({ timeout: 8000 });

        // Press Enter — should select the existing author, not create a duplicate.
        await choicesInput.press('Enter');
        await page.waitForTimeout(500);

        const selectedTexts = await page.locator('.choices__list--multiple .choices__item').allInnerTexts();
        const trapCount = selectedTexts.filter(t => t.includes(TRAP_AUTHOR)).length;

        expect(
            trapCount,
            `Existing author must be selected exactly once (no duplicate-creation). Selected texts: ${selectedTexts.join(' | ')}`
        ).toBe(1);
    });
});

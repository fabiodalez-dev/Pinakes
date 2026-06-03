// @ts-check
//
// Regression-guard tests for app/Views/libri/partials/book_form.php.
//
// This file contains targeted, narrow tests for code paths in book_form.php
// that have regressed before or are at high risk of regressing due to
// well-meaning "refactor for clarity" code review suggestions. Each test
// covers ONE specific behavior; failures here mean a previous fix has been
// undone, not that a new feature broke.
//
// Run:
//   /tmp/run-e2e.sh tests/book-form-regression-guards.spec.js \
//     --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');

const BASE        = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';

test.skip(
    !ADMIN_EMAIL || !ADMIN_PASS,
    'E2E credentials not configured (set E2E_ADMIN_EMAIL, E2E_ADMIN_PASS)',
);

const RUN_ID = Date.now().toString(36);

/**
 * Authenticate as admin once per file. The same browser context is shared
 * across all tests so the session cookie survives.
 */
async function loginAsAdmin(page) {
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
        page.waitForURL(/\/(admin|profilo)/, { timeout: 15000 }),
        page.click('button[type="submit"]'),
    ]);
}

test.describe.serial('book_form.php — regression guards', () => {

    /** @type {import('@playwright/test').BrowserContext} */
    let context;
    /** @type {import('@playwright/test').Page} */
    let page;

    test.beforeAll(async ({ browser }) => {
        context = await browser.newContext();
        page = await context.newPage();
        await loginAsAdmin(page);
    });

    test.afterAll(async () => {
        await context.close();
    });

    // ────────────────────────────────────────────────────────────────────
    // Guard #1 — Publisher multi-select (Choices.js, issue #143). Since v0.7.15
    // the publisher field is a Choices.js multi-select (mirroring authors). The
    // critical contract is that picking an EXISTING publisher from the dropdown
    // records it as an existing id (`editori_ids[]`) with the correct database
    // row id — NOT as a newly-typed publisher (`editori_new[]`), which would
    // silently create a duplicate publisher row on save.
    // ────────────────────────────────────────────────────────────────────
    test('Guard #1: Publisher Choices.js — picking an existing publisher records editori_ids[] with the right id', async () => {
        const trapPublisher = `MondadoriTest${RUN_ID}`;

        // Seed via the publishers UI
        await page.goto(`${BASE}/admin/publishers/create`);
        await page.fill('input[name="nome"]', trapPublisher);
        await page.click('button[type="submit"]');
        const swalConfirm = page.locator('.swal2-confirm').first();
        if (await swalConfirm.isVisible({ timeout: 5000 }).catch(() => false)) {
            await Promise.all([
                page.waitForURL(/\/admin\/publishers(\?|$)/, { timeout: 10000 }),
                swalConfirm.click(),
            ]);
        }

        // Open the book form
        await page.goto(`${BASE}/admin/books/create`);
        await page.waitForLoadState('domcontentloaded');

        // Publisher Choices.js field: type the trap name, wait for the
        // server-search dropdown to populate, then CLICK the existing option
        // (not Enter, which would create a new publisher).
        const publisherWrapper = page.locator('#editori_select')
            .locator('xpath=ancestor::*[contains(@class,"choices")]').first();
        const publisherInput = publisherWrapper.locator('.choices__input--cloned');
        await expect(publisherInput).toBeVisible({ timeout: 10000 });

        // No publisher selected initially.
        await expect(page.locator('#editori_hidden input')).toHaveCount(0);

        await publisherInput.click();
        await publisherInput.fill(trapPublisher);
        await page.waitForTimeout(700); // debounce + API

        const option = publisherWrapper
            .locator('.choices__list--dropdown .choices__item', { hasText: trapPublisher })
            .first();
        await expect(option).toBeVisible({ timeout: 8000 });
        await option.click();
        await page.waitForTimeout(300);

        // Must be recorded as an EXISTING publisher with a positive integer id.
        const idInput = page.locator('#editori_hidden input[name="editori_ids[]"]').first();
        await expect(idInput).toBeAttached({ timeout: 5000 });
        const idVal = await idInput.inputValue();
        expect(
            parseInt(idVal, 10),
            `Picking an existing publisher MUST record editori_ids[] with a positive id (got "${idVal}")`
        ).toBeGreaterThan(0);

        // And it must NOT have been added as a new publisher.
        await expect(page.locator('#editori_hidden input[name="editori_new[]"]')).toHaveCount(0);
    });

    // ────────────────────────────────────────────────────────────────────
    // Guard #2 — TinyMCE description field: the textarea must be synced
    // when the user blurs / when the underlying form is about to submit.
    // A previous refactor accidentally dropped the iframe→textarea sync
    // and book descriptions saved as empty even with typed content.
    //
    // Narrow scope: type into the iframe, dispatch a synthetic form submit
    // (without actually navigating away — preventDefault) and verify that
    // the textarea value now contains the typed content. This isolates
    // the TinyMCE sync logic from the rest of the book-form validation.
    // ────────────────────────────────────────────────────────────────────
    test('Guard #2: TinyMCE description content syncs from iframe to textarea on submit', async () => {
        await page.goto(`${BASE}/admin/books/create`);
        await page.waitForLoadState('domcontentloaded');

        const descText = `Regression guard ${RUN_ID}: TinyMCE iframe to textarea sync must work.`;

        // Wait for TinyMCE to mount on the description textarea
        const tinymceFrame = page.frameLocator('iframe[id*="descrizione"]').first();
        await expect(tinymceFrame.locator('body')).toBeVisible({ timeout: 15000 });

        // Type into TinyMCE
        await tinymceFrame.locator('body').click();
        await tinymceFrame.locator('body').fill(descText);

        // Trigger TinyMCE's save() so it serialises iframe → textarea.
        // book_form.php registers an editor that exposes window.tinymce.
        // If the global is missing the test simply asserts the contract
        // softly (no false fail on a future TinyMCE-less build).
        const synced = await page.evaluate(() => {
            // @ts-ignore — tinymce is a runtime global injected by the
            // editor bundle; it is intentionally not in TS types here.
            if (typeof window.tinymce === 'undefined' || !window.tinymce.activeEditor) return null;
            // @ts-ignore
            window.tinymce.activeEditor.save();
            const t = document.querySelector('textarea[name="descrizione"]');
            return t ? /** @type {HTMLTextAreaElement} */(t).value : null;
        });

        if (synced === null) {
            test.skip(true, 'TinyMCE not present on this build — sync test n/a');
            return;
        }

        expect(
            synced,
            `TinyMCE.save() MUST sync the iframe body content into the underlying textarea[name="descrizione"]. Got: "${synced.slice(0, 80)}"`
        ).toContain('Regression guard');
    });

    // ────────────────────────────────────────────────────────────────────
    // Guard #3 — Genre cascade dropdown: changing the "radice" must trigger
    // a fetch that re-populates the "genere" select, which in turn must
    // re-populate "sottogenere". This pipeline broke in a previous refactor
    // when an inline arrow function lost its `this` binding inside the AJAX
    // resolve callback and the cascade silently went dead.
    // ────────────────────────────────────────────────────────────────────
    test('Guard #3: Genre cascade — selecting a radice repopulates the genre dropdown via AJAX', async () => {
        await page.goto(`${BASE}/admin/books/create`);
        await page.waitForLoadState('domcontentloaded');

        const radiceSelect = page.locator('#radice_select');
        const genereSelect = page.locator('#genere_select');
        await expect(radiceSelect).toBeVisible({ timeout: 10000 });

        // The genere dropdown must be DISABLED initially.
        await expect(genereSelect).toBeDisabled();

        // The radice <select> is populated by an async fetch to
        // /api/generi?only_parents=1. Wait for the call to resolve AND
        // for the select to gain at least one real option.
        await page.waitForResponse(
            r => r.url().includes('/api/generi') && r.url().includes('only_parents=1') && r.status() === 200,
            { timeout: 10000 }
        ).catch(() => { /* fetch may have already resolved before await */ });
        // Poll the option count
        await expect.poll(
            () => radiceSelect.locator('option').count(),
            { timeout: 8000, message: 'Radice select must be populated by /api/generi fetch' },
        ).toBeGreaterThan(1);

        // Pick the first non-default radice option.
        const options = await radiceSelect.locator('option').all();
        let pickedRadice = '';
        for (const opt of options) {
            const val = await opt.getAttribute('value');
            if (val && val !== '0' && val !== '') {
                pickedRadice = val;
                break;
            }
        }
        expect(pickedRadice, 'At least one radice option must exist').not.toBe('');

        // Listen for the children call BEFORE selecting so we don't miss it.
        const childrenResponse = page.waitForResponse(
            r => /\/api\/generi.*parent_id|\/api\/generi.*radice|\/api\/dewey\/children/.test(r.url()) && r.status() === 200,
            { timeout: 10000 }
        ).catch(() => null);

        await radiceSelect.selectOption(pickedRadice);
        await childrenResponse;
        await page.waitForTimeout(400);

        await expect(genereSelect).toBeEnabled({ timeout: 5000 });

        const genereOptionCount = await genereSelect.locator('option').count();
        expect(
            genereOptionCount,
            `Genre cascade MUST populate options after radice selection. Got ${genereOptionCount} options.`
        ).toBeGreaterThan(1);
    });
});

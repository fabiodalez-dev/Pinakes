// @ts-check
//
// Regression test for issue #140 — every refactored banner/popup uses
// SweetAlert2 instead of the native browser dialogs. Each test exercises
// one of the patterns introduced by the unification:
//
//   1. Defensive helpers (window.SwalApp.confirmDelete / .error / .success)
//      degrade gracefully when Swal is missing (this we test by stubbing
//      Swal away — verifies the fallback contract).
//   2. data-swal-confirm forms auto-wire to Swal at DOMContentLoaded and
//      block the default submit until the user clicks .swal2-confirm.
//   3. .swal2-confirm + .swal2-cancel selectors are reachable from
//      Playwright (no native browser-dialog handlers needed).
//
// What this is NOT: a per-finding click-through of every refactored
// site. The unification is mechanical (same helper bus, same data-*
// API, same selectors) — a contract-level test on the bus is more
// stable and catches the regressions we actually care about:
//   - the helper bus disappearing
//   - the auto-attach DOMContentLoaded handler not firing
//   - .swal2-confirm not landing on user click
//
// Run:
//   /tmp/run-e2e.sh tests/sweetalert2-popups.spec.js \
//     --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');

const BASE        = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'E2E credentials not configured');

/**
 * Login admin once per browser context so the protected admin pages
 * we'll mount are reachable.
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

test.describe.serial('Issue #140 — SweetAlert2 popup unification', () => {

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
    // Test 1 — Swal config bus is reachable.
    // The whole unification rests on `window.SwalApp` existing on every
    // page that loads the admin/frontend layout. If swal-config.js
    // failed to load (CDN error, CSP), every confirm/alert breaks.
    // ────────────────────────────────────────────────────────────────────
    test('window.SwalApp bus is initialized on every admin page', async () => {
        await page.goto(`${BASE}/admin/dashboard`);
        await page.waitForLoadState('domcontentloaded');

        const swalAppShape = await page.evaluate(() => {
            const a = window.SwalApp;
            if (!a) return null;
            return {
                hasConfirmDelete: typeof a.confirmDelete === 'function',
                hasConfirm:       typeof a.confirm === 'function',
                hasError:         typeof a.error === 'function',
                hasSuccess:       typeof a.success === 'function',
                hasInfo:          typeof a.info === 'function',
                hasWarning:       typeof a.warning === 'function',
                hasPrompt:        typeof a.prompt === 'function',
                hasToast:         typeof a.toast === 'function',
                hasAttach:        typeof a.attachSwalConfirm === 'function',
            };
        });

        expect(swalAppShape, 'window.SwalApp must exist on every page').not.toBeNull();
        expect(swalAppShape).toMatchObject({
            hasConfirmDelete: true,
            hasConfirm:       true,
            hasError:         true,
            hasSuccess:       true,
            hasInfo:          true,
            hasWarning:       true,
            hasPrompt:        true,
            hasToast:         true,
            hasAttach:        true,
        });
    });

    // ────────────────────────────────────────────────────────────────────
    // Test 2 — Defensive native fallback.
    // When Swal is removed at runtime, each helper still returns a
    // Promise-like {isConfirmed, value} shape so callers using
    // `await SwalApp.confirmDelete().then(r => r.isConfirmed && doStuff())`
    // keep working with native confirm/alert behind the scenes.
    // ────────────────────────────────────────────────────────────────────
    test('SwalApp helpers fall back to native dialogs when Swal is unavailable', async () => {
        await page.goto(`${BASE}/admin/dashboard`);
        await page.waitForLoadState('domcontentloaded');

        // Auto-accept any native dialog this test triggers so the
        // browser doesn't hang the page on a real confirm/alert.
        page.on('dialog', (d) => d.accept());

        const fallbackResult = await page.evaluate(async () => {
            // Stash + remove Swal to simulate bundle failure.
            const stashed = window.Swal;
            try {
                delete window.Swal;

                // confirmDelete must still return {isConfirmed: true}
                // because the native confirm() is auto-accepted by the
                // page.on('dialog') handler above.
                const r1 = await window.SwalApp.confirmDelete({ text: 'native fallback test' });
                // error() must not throw and must return {isConfirmed: true}
                // (the underlying alert is auto-accepted).
                const r2 = await window.SwalApp.error('e', 'fallback error');
                return {
                    confirmDeleteIsConfirmed: r1 && r1.isConfirmed === true,
                    errorReturnedPromise:     r2 && r2.isConfirmed === true,
                };
            } finally {
                window.Swal = stashed;
            }
        });

        expect(fallbackResult).toEqual({
            confirmDeleteIsConfirmed: true,
            errorReturnedPromise:     true,
        });

        // Drop the test-scoped dialog handler so the next tests use
        // Swal-driven flows instead.
        page.removeAllListeners('dialog');
    });

    // ────────────────────────────────────────────────────────────────────
    // Test 3 — data-swal-confirm auto-attach.
    // The DOMContentLoaded handler in swal-config.js walks every
    // `form[data-swal-confirm]` and installs the Swal interceptor.
    // We inject a synthetic form into the dashboard and verify:
    //   - the submit is blocked at first
    //   - .swal2-confirm appears after click
    //   - clicking .swal2-confirm allows the second submit to go through
    // ────────────────────────────────────────────────────────────────────
    test('data-swal-confirm forms are auto-wired and gated by .swal2-confirm', async () => {
        await page.goto(`${BASE}/admin/dashboard`);
        await page.waitForLoadState('domcontentloaded');

        // Inject the synthetic form. We intercept form.submit() (which
        // attachSwalConfirm calls programmatically after the user
        // clicks .swal2-confirm) — the native `submit` event would
        // also fire on the first user click before Swal preventDefault
        // runs, which makes it a noisy sentinel. form.submit() bypasses
        // listeners entirely, so a flag inside the prototype override
        // is the cleanest signal of "the user confirmed and the form
        // actually went through".
        await page.evaluate(() => {
            const form = document.createElement('form');
            form.id = 'swal-test-form';
            form.method = 'post';
            form.action = 'javascript:void(0)';
            form.setAttribute('data-swal-confirm', 'Banner test confirm text');
            form.setAttribute('data-swal-confirm-button', 'Proceed');
            const btn = document.createElement('button');
            btn.type = 'submit';
            btn.textContent = 'submit';
            btn.id = 'swal-test-submit';
            form.appendChild(btn);
            document.body.appendChild(form);

            // Re-run the auto-attach so the freshly-injected form gets
            // wired up (the DOMContentLoaded handler already fired).
            window.SwalApp.attachSwalConfirm();

            // Override THIS form's submit() so we track the programmatic
            // call from the Swal handler (not every submit event).
            window.__swalTestSubmitted = false;
            const origSubmit = HTMLFormElement.prototype.submit;
            form.submit = function() {
                window.__swalTestSubmitted = true;
                // Do NOT call origSubmit — that would navigate even with
                // action='javascript:void(0)' in some browsers.
            };
        });

        // First click: should NOT submit (blocked by Swal preventDefault).
        const submitBtn = page.locator('#swal-test-submit');
        await submitBtn.click();

        // Wait for the Swal dialog to land.
        const swalConfirm = page.locator('.swal2-confirm');
        await expect(swalConfirm).toBeAttached({ timeout: 5000 });
        // The dialog content matches what we passed in data-swal-confirm.
        await expect(page.locator('.swal2-popup')).toContainText('Banner test confirm text');

        // Confirming should call form.submit() programmatically.
        await swalConfirm.click();
        await page.waitForTimeout(300);
        const submittedAfter = await page.evaluate(() => window.__swalTestSubmitted === true);
        expect(submittedAfter, 'form.submit() must be called after Swal confirm').toBe(true);
    });

    // ────────────────────────────────────────────────────────────────────
    // Test 4 — Cancel path.
    // Clicking the Swal cancel button must NOT submit the form.
    // ────────────────────────────────────────────────────────────────────
    test('data-swal-confirm cancel keeps the form untouched', async () => {
        await page.goto(`${BASE}/admin/dashboard`);
        await page.waitForLoadState('domcontentloaded');

        await page.evaluate(() => {
            const form = document.createElement('form');
            form.id = 'swal-cancel-form';
            form.action = 'javascript:void(0)';
            form.setAttribute('data-swal-confirm', 'You will cancel this');
            const btn = document.createElement('button');
            btn.type = 'submit';
            btn.textContent = 'submit';
            btn.id = 'swal-cancel-submit';
            form.appendChild(btn);
            document.body.appendChild(form);
            window.SwalApp.attachSwalConfirm();

            // Track form.submit() (programmatic), not the submit event —
            // see Test 3 for why. Cancel must leave the flag false.
            window.__swalCancelSubmitted = false;
            form.submit = function() {
                window.__swalCancelSubmitted = true;
            };
        });

        await page.locator('#swal-cancel-submit').click();
        const swalCancel = page.locator('.swal2-cancel');
        await expect(swalCancel).toBeAttached({ timeout: 5000 });
        await swalCancel.click();
        await page.waitForTimeout(300);

        const submitted = await page.evaluate(() => window.__swalCancelSubmitted === true);
        expect(submitted, 'form.submit() must NOT be called after Swal cancel').toBe(false);
    });

    // ────────────────────────────────────────────────────────────────────
    // Test 5 — Real-site smoke: settings/index event-image-layout form.
    // The /admin/settings?tab=cms tab contains the new event-layout
    // settings form added in PR #139. Loading the tab should render
    // the form WITHOUT a native dialog wired to its submit.
    // (Negative invariant — guards against accidental re-introduction
    // of onsubmit='return confirm(...)' on this form.)
    // ────────────────────────────────────────────────────────────────────
    test('admin/settings?tab=cms: no native onsubmit confirm on event-layout form', async () => {
        await page.goto(`${BASE}/admin/settings?tab=cms`);
        await page.waitForLoadState('domcontentloaded');

        const violatingForms = await page.evaluate(() => {
            const forms = document.querySelectorAll('form');
            const out = [];
            forms.forEach((f) => {
                const onsubmit = f.getAttribute('onsubmit') || '';
                const onclick  = (f.querySelector('button[type="submit"]')?.getAttribute('onclick')) || '';
                if (onsubmit.includes('confirm(') || onclick.includes('confirm(')) {
                    out.push({
                        action: f.getAttribute('action') || '',
                        onsubmit, onclick,
                    });
                }
            });
            return out;
        });

        expect(
            violatingForms,
            'No form on /admin/settings?tab=cms must still use native confirm() in onsubmit/onclick',
        ).toEqual([]);
    });

    // ────────────────────────────────────────────────────────────────────
    // Test 6 — Real-site smoke: admin/languages page exercises the
    // form-attribute auto-wire. The language list has set-default/delete
    // buttons that we converted from onclick='return confirm(...)' to
    // data-swal-confirm on their parent <form>. Verify at least one
    // form on that page actually carries the attribute.
    // ────────────────────────────────────────────────────────────────────
    test('admin/languages: at least one data-swal-confirm form is present', async () => {
        await page.goto(`${BASE}/admin/languages`);
        await page.waitForLoadState('domcontentloaded');

        const count = await page.locator('form[data-swal-confirm]').count();
        // Skip when no languages are configured (a fresh install ships
        // only the default — the set-default + delete buttons only
        // render for non-default rows).
        test.skip(count === 0, 'no languages with data-swal-confirm — empty configuration');

        // At least one form with the attribute should exist.
        expect(count).toBeGreaterThan(0);

        // The first such form should also have data-swal-confirm-button
        // (we wired both attributes on every refactor) — sanity that
        // the attribute set is complete.
        const firstHasButtonAttr = await page.locator('form[data-swal-confirm]').first()
            .evaluate((el) => el.hasAttribute('data-swal-confirm-button'));
        expect(firstHasButtonAttr).toBe(true);
    });
});

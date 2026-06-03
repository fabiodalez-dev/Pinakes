// @ts-check
//
// Comprehensive regression suite for SweetAlert2 unification (PR #141 +
// UX follow-ups). 25 reusable tests organised into 5 contract blocks:
//
//   A. Bus contract           — window.SwalApp shape + native fallbacks      [6]
//   B. Attribute-bus contract — attachSwalConfirm wiring, idempotence, gates [5]
//   C. Kind routing           — confirmDelete vs confirm + confirmText def   [3]
//   D. i18n & attribute pass  — data-swal-* propagation + revoke edge case   [3]
//   E. Refactored views       — per-page attribute assertions (admin login)  [8]
//
// Blocks A–D run against a static HTML harness (no login, no DB) by
// loading the on-disk `swal-config.js` into a synthetic page and
// stubbing `window.Swal`. They are the fast, deterministic core.
//
// Block E loads the actual admin pages and only asserts that the
// rendered HTML carries the expected `data-swal-confirm*` attributes
// (it does NOT click through — DB-state independent). Each test is
// guarded with `test.skip(...)` so missing credentials / missing DB
// state collapse cleanly to a skip rather than a hard fail.
//
// Run:
//   /tmp/run-e2e.sh tests/sweetalert2-comprehensive.spec.js \
//     --config=tests/playwright.config.js --workers=1
//
// Run just the no-login core:
//   /tmp/run-e2e.sh tests/sweetalert2-comprehensive.spec.js \
//     --config=tests/playwright.config.js --workers=1 \
//     --grep "BUS|ATTRIBUTE|KIND|I18N"

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const BASE        = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';

// ─── Static harness ─────────────────────────────────────────────────
// Loads the on-disk swal-config.js into a synthetic page with Swal
// stubbed. Returns a Page instance ready for evaluate() / click().
//
// `opts.swal` controls the Swal stub:
//   - 'present'  (default) — Swal.fire returns {isConfirmed:true} so
//                            confirm/confirmDelete resolve truthy.
//   - 'cancel'             — Swal.fire returns {isConfirmed:false}.
//   - 'absent'             — `window.Swal` is undefined → native
//                            fallback path is exercised.
//
// `opts.forms` is the inline form markup injected into <body>.
async function loadHarness(page, opts = {}) {
    const swalMode = opts.swal || 'present';
    const formsHtml = opts.forms || '';
    const swalConfigSrc = fs.readFileSync(
        path.resolve(__dirname, '..', 'public/assets/js/swal-config.js'),
        'utf8'
    );

    // The static harness uses an in-page `Swal` stub. Its `mixin`
    // returns the stub itself (so Toast.fire works). `fire` returns a
    // deterministic result the test controls.
    const swalStubBody = swalMode === 'absent'
        ? '// Swal absent — native fallback path'
        : `
            window.__swalCalls = [];
            window.Swal = {
                fire: function(opts) {
                    window.__swalCalls.push(opts);
                    return Promise.resolve({
                        isConfirmed: ${swalMode === 'present' ? 'true' : 'false'},
                        value: ${swalMode === 'present' ? '"stub-value"' : 'undefined'},
                        dismiss: ${swalMode === 'cancel' ? '"cancel"' : 'undefined'}
                    });
                },
                isVisible: function(){ return false; },
                mixin: function(){ return this; },
                showLoading: function(){},
                close: function(){}
            };
        `;

    const html = `<!doctype html><html><head><meta charset="utf-8"></head><body>
        ${formsHtml}
        <script>${swalStubBody}</script>
        <script>${swalConfigSrc}<` + `/script>
    </body></html>`;

    await page.setContent(html);
    await page.waitForLoadState('domcontentloaded');
    // The swal-config.js bottom block re-runs attachSwalConfirm() if
    // document.readyState !== 'loading'. We give it one tick to land.
    await page.waitForTimeout(80);
}

// Login helper for Block E. Mirrors the pattern in
// tests/sweetalert2-popups.spec.js. Returns true on success, false
// (with a test.skip-style abort) when credentials are wrong.
async function tryLoginAsAdmin(page) {
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    // Strict admin-only match: /admin/* (with slash or end-of-path).
    // Previously /\/(admin|profilo)/ also matched /profilo and
    // /user/dashboard wouldn't have stopped the wait either — meaning
    // non-admin credentials returned `true` and Block E tests would
    // run on a non-admin session, failing on 302/403 from /admin/*
    // routes in confusing ways. Non-admin login now cleanly times
    // out here so callers can test.skip(). Mirrors the F14 fix in
    // unified-release-regression.spec.js.
    try {
        await page.waitForURL(/\/admin(?:\/|$)/, { timeout: 10000 });
        return true;
    } catch {
        return false;
    }
}

// ═══════════════════════════════════════════════════════════════════
// BLOCK A — Bus contract (6 tests, no login)
// ═══════════════════════════════════════════════════════════════════
test.describe('[BUS] window.SwalApp contract', () => {

    test('A1: SwalApp exposes the expected method surface', async ({ page }) => {
        await loadHarness(page);
        const surface = await page.evaluate(() => {
            const bus = window.SwalApp;
            return {
                exists: typeof bus === 'object' && bus !== null,
                confirmDelete:        typeof bus.confirmDelete,
                confirm:              typeof bus.confirm,
                prompt:               typeof bus.prompt,
                success:              typeof bus.success,
                error:                typeof bus.error,
                info:                 typeof bus.info,
                warning:              typeof bus.warning,
                toast:                typeof bus.toast,
                attachSwalConfirm:    typeof bus.attachSwalConfirm,
            };
        });
        expect(surface.exists).toBe(true);
        for (const fn of ['confirmDelete', 'confirm', 'prompt', 'success', 'error', 'info', 'warning', 'toast', 'attachSwalConfirm']) {
            expect(surface[fn], `SwalApp.${fn} must be a function`).toBe('function');
        }
    });

    test('A2: confirmDelete returns Promise<{isConfirmed,value}>', async ({ page }) => {
        await loadHarness(page);
        const result = await page.evaluate(async () => {
            const r = await window.SwalApp.confirmDelete({ text: 'test' });
            return { hasShape: typeof r === 'object' && 'isConfirmed' in r, isConfirmed: r.isConfirmed };
        });
        expect(result.hasShape).toBe(true);
        expect(result.isConfirmed).toBe(true); // Swal stub resolves truthy
    });

    test('A3: confirm returns Promise<{isConfirmed,value}>', async ({ page }) => {
        await loadHarness(page, { swal: 'cancel' });
        const result = await page.evaluate(async () => {
            const r = await window.SwalApp.confirm({ title: 'q?', text: 'sure?' });
            return { hasShape: typeof r === 'object' && 'isConfirmed' in r, isConfirmed: r.isConfirmed };
        });
        expect(result.hasShape).toBe(true);
        expect(result.isConfirmed).toBe(false); // 'cancel' stub
    });

    test('A4: prompt empty-string semantics (native fallback)', async ({ page }) => {
        await loadHarness(page, { swal: 'absent' });
        // window.prompt is auto-dismissed (returns null) by the test
        // browser when no handler is installed — exercise both branches
        // via dialog handler.
        // First call: dismiss (null) → isConfirmed=false, value=''
        page.once('dialog', d => d.dismiss());
        const dismissed = await page.evaluate(async () => {
            const r = await window.SwalApp.prompt({ title: 't' });
            return { isConfirmed: r.isConfirmed, value: r.value };
        });
        expect(dismissed.isConfirmed).toBe(false);
        expect(dismissed.value).toBe('');

        // Second call: accept with empty string → isConfirmed=true, value=''
        page.once('dialog', d => d.accept(''));
        const accepted = await page.evaluate(async () => {
            const r = await window.SwalApp.prompt({ title: 't' });
            return { isConfirmed: r.isConfirmed, value: r.value };
        });
        expect(accepted.isConfirmed).toBe(true);
        expect(accepted.value).toBe('');
    });

    test('A5: error/success/info/warning return Promises (no throw) when Swal absent', async ({ page }) => {
        await loadHarness(page, { swal: 'absent' });
        // Auto-accept any native alert() the fallbacks raise.
        page.on('dialog', d => d.accept());
        const result = await page.evaluate(async () => {
            const checks = {};
            for (const name of ['error', 'success', 'info', 'warning']) {
                try {
                    const r = await window.SwalApp[name]('title', 'body');
                    checks[name] = (r && typeof r === 'object' && 'isConfirmed' in r);
                } catch (e) {
                    checks[name] = `THREW: ${e.message}`;
                }
            }
            return checks;
        });
        expect(result.error).toBe(true);
        expect(result.success).toBe(true);
        expect(result.info).toBe(true);
        expect(result.warning).toBe(true);
    });

    test('A6: toast no-ops gracefully when Swal absent', async ({ page }) => {
        await loadHarness(page, { swal: 'absent' });
        // toast must not throw even with Swal stripped; it returns a
        // fake result so awaiters don't hang.
        const safe = await page.evaluate(async () => {
            try {
                const r = await window.SwalApp.toast({ title: 'x', icon: 'success' });
                return { threw: false, result: r };
            } catch (e) {
                return { threw: true, message: e.message };
            }
        });
        expect(safe.threw).toBe(false);
    });
});

// ═══════════════════════════════════════════════════════════════════
// BLOCK B — attachSwalConfirm contract (5 tests, no login)
// ═══════════════════════════════════════════════════════════════════
test.describe('[ATTRIBUTE] data-swal-confirm auto-wire', () => {

    const FORM = `
        <form id="f" method="post" action="javascript:void(0)"
              data-swal-confirm="Sei sicuro?"
              data-swal-confirm-button="Procedi">
            <button id="b" type="submit">Submit</button>
        </form>
    `;

    test('B1: forms with data-swal-confirm are auto-wired at DOMContentLoaded', async ({ page }) => {
        await loadHarness(page, { forms: FORM });
        const wired = await page.evaluate(() => {
            const f = document.getElementById('f');
            return f && f.dataset.swalAttached === '1';
        });
        expect(wired).toBe(true);
    });

    test('B2: attachSwalConfirm is idempotent (re-run = no-op)', async ({ page }) => {
        await loadHarness(page, { swal: 'cancel', forms: FORM });
        // Re-run attachSwalConfirm twice on top of the DOMContentLoaded
        // call. If the helper is NOT idempotent, the listener stack
        // grows on every re-attach — clicking the submit once would
        // then trigger Swal.fire() multiple times.
        //
        // The strictest signal isn't `data-swal-attached === '1'`
        // (which a buggy non-idempotent helper might still set), but
        // the observable count of Swal.fire invocations after a real
        // submit click. The harness installs a Swal stub that records
        // every call into `window.__swalCalls`; one click on a single
        // form must produce exactly ONE entry.
        await page.evaluate(() => {
            window.SwalApp.attachSwalConfirm();
            window.SwalApp.attachSwalConfirm();
            window.__swalCalls.length = 0;
        });
        // Real user click — exercises the actual installed submit
        // listener(s), not a synthetic event.
        await page.click('#b');
        await page.waitForTimeout(120);
        const result = await page.evaluate(() => ({
            attached: document.getElementById('f').dataset.swalAttached,
            fireCount: window.__swalCalls.length,
        }));
        expect(result.attached).toBe('1');
        // The crucial assertion: no listener duplication. If
        // attachSwalConfirm registered two listeners, Swal.fire would
        // have been called twice (each listener races to preventDefault
        // + dispatch its own Swal modal).
        expect(result.fireCount, 'Swal.fire must be called exactly once per submit (no duplicate listeners)').toBe(1);
    });

    test('B3: cancel keeps form untouched (no submit)', async ({ page }) => {
        await loadHarness(page, { swal: 'cancel', forms: FORM });
        await page.evaluate(() => {
            const f = document.getElementById('f');
            window.__submitted = false;
            f.submit = function() { window.__submitted = true; };
        });
        await page.click('#b');
        await page.waitForTimeout(120);
        const submitted = await page.evaluate(() => window.__submitted);
        expect(submitted).toBe(false);
    });

    test('B4: confirm calls form.submit programmatically', async ({ page }) => {
        await loadHarness(page, { swal: 'present', forms: FORM });
        await page.evaluate(() => {
            const f = document.getElementById('f');
            window.__submitted = false;
            f.submit = function() { window.__submitted = true; };
        });
        await page.click('#b');
        await page.waitForTimeout(120);
        const submitted = await page.evaluate(() => window.__submitted);
        expect(submitted).toBe(true);
    });

    test('B5: one-shot data-swal-proceed: re-click re-shows dialog', async ({ page }) => {
        await loadHarness(page, { swal: 'present', forms: FORM });
        const events = await page.evaluate(async () => {
            const f = document.getElementById('f');
            // After the confirm path runs, we expect data-swal-proceed
            // to be temporarily set, then cleared when the proceed
            // submit fires. Track its values across the lifecycle.
            const events = [];
            f.submit = function() {
                events.push({ event: 'submit-called', swalProceed: f.dataset.swalProceed });
                // The proceed listener should reset it to '' on the
                // wrapper handler's re-entry; we simulate the wrapper
                // re-entry by dispatching submit again with proceed=1.
                const evt = new Event('submit', { cancelable: true, bubbles: true });
                f.dispatchEvent(evt);
                events.push({ event: 'second-dispatch', swalProceed: f.dataset.swalProceed });
            };

            // Click the submit button — Swal stub resolves isConfirmed:true,
            // so the wrapper sets swalProceed='1' and calls f.submit().
            document.getElementById('b').click();
            await new Promise(r => setTimeout(r, 100));
            return events;
        });
        // First call to submit() must have swalProceed='1' (the proceed
        // marker). After the second dispatch, the wrapper's "if
        // swalProceed==='1' clear+return" branch must have reset it.
        expect(events.length).toBeGreaterThanOrEqual(1);
        expect(events[0].event).toBe('submit-called');
        expect(events[0].swalProceed).toBe('1');
        if (events.length >= 2) {
            expect(events[1].swalProceed).toBe(''); // cleared by re-entry
        }
    });
});

// ═══════════════════════════════════════════════════════════════════
// BLOCK C — Kind routing & confirmText default (3 tests, no login)
// ═══════════════════════════════════════════════════════════════════
test.describe('[KIND] confirmDelete vs confirm routing', () => {

    test('C1: default form (no kind) routes through confirmDelete (red)', async ({ page }) => {
        const FORM = `<form id="f" method="post" action="javascript:void(0)" data-swal-confirm="d"><button type="submit" id="b">x</button></form>`;
        await loadHarness(page, { swal: 'present', forms: FORM });
        await page.click('#b');
        await page.waitForTimeout(80);
        // The Swal stub captured the .fire opts. confirmDelete configures
        // showCancelButton:true + confirmButtonColor (red) + icon:'warning'.
        const fire = await page.evaluate(() => window.__swalCalls[0]);
        expect(fire).toBeTruthy();
        // confirmDelete sets icon='warning' AND showCancelButton=true AND
        // confirmButtonText='Elimina' by default; the red colour comes
        // from SwalConfig.confirmButtonColor when the kind helper passes
        // through. Check the markers we control: button text default.
        expect(fire.confirmButtonText).toBe('Elimina');
        expect(fire.showCancelButton).toBe(true);
    });

    test('C2: kind="action" routes through confirm (neutral gray)', async ({ page }) => {
        const FORM = `<form id="f" method="post" action="javascript:void(0)" data-swal-confirm="a" data-swal-confirm-kind="action"><button type="submit" id="b">x</button></form>`;
        await loadHarness(page, { swal: 'present', forms: FORM });
        await page.click('#b');
        await page.waitForTimeout(80);
        const fire = await page.evaluate(() => window.__swalCalls[0]);
        expect(fire).toBeTruthy();
        // confirm() defaults to 'Conferma' button text and neutral
        // confirmButtonColor (#6b7280 gray in swal-config.js).
        expect(fire.confirmButtonText).toBe('Conferma');
        expect(fire.showCancelButton).toBe(true);
    });

    test('C3: data-swal-confirm-button override wins for both kinds', async ({ page }) => {
        const FORM = `
            <form id="d" method="post" action="javascript:void(0)" data-swal-confirm="x" data-swal-confirm-button="Rimuovi"><button type="submit" id="bd">x</button></form>
            <form id="a" method="post" action="javascript:void(0)" data-swal-confirm="x" data-swal-confirm-kind="action" data-swal-confirm-button="Procedi"><button type="submit" id="ba">x</button></form>
        `;
        await loadHarness(page, { swal: 'present', forms: FORM });
        await page.click('#bd');
        await page.waitForTimeout(80);
        await page.click('#ba');
        await page.waitForTimeout(80);
        const calls = await page.evaluate(() => window.__swalCalls);
        expect(calls).toHaveLength(2);
        expect(calls[0].confirmButtonText).toBe('Rimuovi');  // destructive override
        expect(calls[1].confirmButtonText).toBe('Procedi');  // action override
    });
});

// ═══════════════════════════════════════════════════════════════════
// BLOCK D — i18n & attribute propagation (3 tests, no login)
// ═══════════════════════════════════════════════════════════════════
test.describe('[I18N] data-swal-* attribute propagation', () => {

    test('D1: data-swal-confirm-title overrides default title', async ({ page }) => {
        const FORM = `<form id="f" method="post" action="javascript:void(0)" data-swal-confirm="msg" data-swal-confirm-title="Custom Title"><button type="submit" id="b">x</button></form>`;
        await loadHarness(page, { swal: 'present', forms: FORM });
        await page.click('#b');
        await page.waitForTimeout(80);
        const fire = await page.evaluate(() => window.__swalCalls[0]);
        expect(fire.title).toBe('Custom Title');
    });

    test('D2: data-swal-confirm body text is forwarded to Swal.fire opts.text', async ({ page }) => {
        const FORM = `<form id="f" method="post" action="javascript:void(0)" data-swal-confirm="Body message €é à"><button type="submit" id="b">x</button></form>`;
        await loadHarness(page, { swal: 'present', forms: FORM });
        await page.click('#b');
        await page.waitForTimeout(80);
        const fire = await page.evaluate(() => window.__swalCalls[0]);
        // confirmDelete uses html or text — the kind helper passes
        // through whichever the bus chooses. Accept either field.
        const body = fire.text || fire.html;
        expect(body).toContain('Body message');
        // Verify diacritics survive the data-attr → JS round-trip.
        expect(body).toContain('é');
    });

    test('D3: revokeSession confirmButton edge case (undefined/empty falls back)', async ({ page }) => {
        // Mirrors profile/index.php:632–633 pattern: confirmText is
        // derived from translations.confirmRevokeButton ONLY when it
        // is a non-empty string; otherwise SwalApp default applies.
        await loadHarness(page);
        const calls = await page.evaluate(async () => {
            const calls = [];
            const cases = [
                undefined,
                '',
                null,
                'Custom Revoke'
            ];
            for (const v of cases) {
                const confirmText = (typeof v === 'string' && v.length > 0) ? v : undefined;
                window.__swalCalls.length = 0;
                await window.SwalApp.confirm({
                    title: 'Revoke?',
                    text: 'sure?',
                    confirmText: confirmText
                });
                calls.push({
                    input: v,
                    confirmButtonText: (window.__swalCalls[0] || {}).confirmButtonText
                });
            }
            return calls;
        });
        // First 3 cases (undefined/''/null) fall back to SwalApp.confirm's
        // own default 'Conferma'. Fourth case uses the override.
        expect(calls[0].confirmButtonText).toBe('Conferma');
        expect(calls[1].confirmButtonText).toBe('Conferma');
        expect(calls[2].confirmButtonText).toBe('Conferma');
        expect(calls[3].confirmButtonText).toBe('Custom Revoke');
    });
});

// ═══════════════════════════════════════════════════════════════════
// BLOCK E — Refactored views (8 tests, login required)
// ═══════════════════════════════════════════════════════════════════
//
// These tests assert the per-page attribute contract — they do NOT
// click through (avoiding DB-state dependence). Each test:
//   1. Login as admin (skip on auth failure).
//   2. Navigate to the target admin page.
//   3. Locate the form via its `action` URL pattern (DB-state
//      independent — works on empty installs too because we look for
//      the markup, not specific records).
//   4. Assert presence/absence of data-swal-confirm-kind and other
//      data-swal-* attributes.
//
// If the page renders no matching forms (empty DB), the test skips —
// rather than fail, surface the gap so an operator can seed data.

test.describe('[REFACTORED] per-view attribute assertions', () => {

    test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'E2E credentials not configured (set E2E_ADMIN_EMAIL / E2E_ADMIN_PASS)');

    let context, page;
    test.beforeAll(async ({ browser }) => {
        context = await browser.newContext();
        page = await context.newPage();
        const ok = await tryLoginAsAdmin(page);
        if (!ok) {
            test.skip(true, 'Admin login failed — credentials may be wrong');
        }
    });

    test.afterAll(async () => {
        if (context) await context.close();
    });

    // E1: admin/users — activate-directly form uses kind=action
    test('E1: admin/users activate-directly form has kind="action"', async () => {
        await page.goto(`${BASE}/admin/users`);
        await page.waitForLoadState('domcontentloaded');
        const forms = page.locator('form[action*="/activate-directly"]');
        const count = await forms.count();
        test.skip(count === 0, 'No pending-activation users on this install — seed a user to run');
        const kind = await forms.first().getAttribute('data-swal-confirm-kind');
        expect(kind).toBe('action');
    });

    // E2: admin/users/dettagli — activate-directly form uses kind=action
    test('E2: admin/users/<id> activate-directly form has kind="action"', async () => {
        // Find a pending user via the index page first.
        await page.goto(`${BASE}/admin/users`);
        const detailLink = page.locator('a[href*="/admin/users/"][href*="/details"], a[href^="/admin/users/"]:not([href="/admin/users"])').first();
        const linkCount = await detailLink.count();
        test.skip(linkCount === 0, 'No user-detail link present — seed a user');
        const href = await detailLink.getAttribute('href');
        await page.goto(`${BASE}${href.startsWith('http') ? new URL(href).pathname : href}`);
        await page.waitForLoadState('domcontentloaded');
        const form = page.locator('form[action*="/activate-directly"]');
        const count = await form.count();
        test.skip(count === 0, 'This user is already active — find one with activate-directly form');
        const kind = await form.first().getAttribute('data-swal-confirm-kind');
        expect(kind).toBe('action');
    });

    // E3: admin/languages — set-default form uses kind=action
    test('E3: admin/languages set-default form has kind="action"', async () => {
        await page.goto(`${BASE}/admin/languages`);
        await page.waitForLoadState('domcontentloaded');
        const form = page.locator('form[action*="/set-default"]');
        const count = await form.count();
        test.skip(count === 0, 'Only one language installed — no set-default form rendered');
        const kind = await form.first().getAttribute('data-swal-confirm-kind');
        expect(kind).toBe('action');
    });

    // E4: admin/languages — delete form is destructive (no kind=action)
    test('E4: admin/languages delete form is destructive (no kind=action)', async () => {
        await page.goto(`${BASE}/admin/languages`);
        await page.waitForLoadState('domcontentloaded');
        // Match the delete form: action contains /delete (POST).
        const form = page.locator('form[action*="/admin/languages/"][action$="/delete"], form[action*="/admin/languages/"][action*="/delete"]').first();
        const count = await form.count();
        test.skip(count === 0, 'No deletable language present (only default lang installed)');
        const kind = await form.getAttribute('data-swal-confirm-kind');
        const confirmText = await form.getAttribute('data-swal-confirm-button');
        // Destructive: must NOT opt into kind=action; button text must
        // include the destructive label.
        expect(kind).not.toBe('action');
        expect((confirmText || '').toLowerCase()).toContain('elimin'); // covers Elimina/Eliminate
    });

    // Resolve the first entity that's actually DELETABLE via the admin
    // JSON API, so the scheda renders the delete-form path rather than
    // the "non eliminabile" placeholder. The view gates the form on
    // *every* dependency reaching zero — accept an array of dep keys
    // and require ALL of them to be 0 / null / missing on the picked
    // row. Single-string input is preserved for the simple cases
    // (autori, editori) where the only blocker is books.
    //
    // Returns null when every row has at least one outstanding
    // dependency — legitimate on a seeded install; tests skip cleanly
    // in that case.
    //
    // Why all-dep-keys-zero (not any-dep-key-zero): the previous
    // single-key check let through a genre with `children_count=0` but
    // `libri_count > 0`. The view renders the form only when BOTH gates
    // pass, so the picked row would still render the lock button and
    // the toBeAttached assertion fails. The view is the source of
    // truth here — every dep listed must clear.
    async function firstDeletableId(apiPath, depKeys) {
        const keys = Array.isArray(depKeys) ? depKeys : [depKeys];
        const resp = await page.request.get(`${BASE}${apiPath}`);
        if (!resp.ok()) return null;
        const ct = resp.headers()['content-type'] || '';
        if (!ct.includes('json')) return null;
        try {
            const body = await resp.json();
            const rows = Array.isArray(body) ? body
                       : Array.isArray(body.data) ? body.data
                       : Array.isArray(body.items) ? body.items
                       : null;
            if (!rows || rows.length === 0) return null;
            // Find the first row where EVERY listed dep is 0/null/missing.
            const row = rows.find(r => keys.every(k => {
                const dep = r[k];
                return dep == null || Number(dep) === 0;
            }));
            if (!row) return null;
            const id = row.id ?? row.ID ?? row.pk;
            return id != null ? String(id) : null;
        } catch { return null; }
    }

    // E5: autori/scheda_autore — delete-author form is wired
    test('E5: autori/scheda_autore delete-author form carries data-swal-confirm', async () => {
        // libri_count > 0 → scheda renders the "non eliminabile" lock
        // button instead of the delete form. Pick a deletable author.
        const id = await firstDeletableId('/api/autori', 'libri_count');
        test.skip(!id, 'No deletable author on this install (every author has at least one associated book)');
        await page.goto(`${BASE}/admin/authors/${id}`);
        await page.waitForLoadState('domcontentloaded');
        const form = page.locator('form[data-swal-confirm]').first();
        await expect(form).toBeAttached();
        const text = await form.getAttribute('data-swal-confirm');
        expect((text || '').length).toBeGreaterThan(0);
    });

    // E6: editori/scheda_editore — delete-publisher form is wired
    test('E6: editori/scheda_editore delete-publisher form carries data-swal-confirm', async () => {
        const id = await firstDeletableId('/api/editori', 'libri_count');
        test.skip(!id, 'No deletable publisher on this install');
        await page.goto(`${BASE}/admin/publishers/${id}`);
        await page.waitForLoadState('domcontentloaded');
        const form = page.locator('form[data-swal-confirm]').first();
        await expect(form).toBeAttached();
    });

    // E7: generi/dettaglio_genere — delete-genre form is wired
    test('E7: generi/dettaglio_genere delete-genre form carries data-swal-confirm', async () => {
        // The view gates the delete form on TWO conditions (per
        // GeneriController + dettaglio_genere.php): no sub-genres
        // (`children_count == 0`) AND no books referencing this genre
        // (`libri_count == 0`). Either dep being non-zero swaps the
        // form out for a lock button, so both must reach zero on the
        // picked row. The /api/generi index returns both fields when
        // the row has them; firstDeletableId treats missing fields as
        // zero (same conservative rule the view applies).
        const id = await firstDeletableId('/api/generi', ['children_count', 'libri_count']);
        test.skip(!id, 'No deletable genre on this install (every genre has sub-genres or books)');
        await page.goto(`${BASE}/admin/genres/${id}`);
        await page.waitForLoadState('domcontentloaded');
        const form = page.locator('form[data-swal-confirm]').first();
        await expect(form).toBeAttached();
    });

    // E8: collocazione — delete-shelf and delete-position forms wired
    test('E8: collocazione admin page exposes data-swal-confirm forms for shelf/position deletion', async () => {
        await page.goto(`${BASE}/admin/placement`);
        await page.waitForLoadState('domcontentloaded');
        const forms = page.locator('form[data-swal-confirm]');
        const n = await forms.count();
        test.skip(n === 0, 'No shelves seeded yet — create at least one shelf to exercise this');
        // Confirm at least one form has a destructive label.
        const firstText = await forms.first().getAttribute('data-swal-confirm-button');
        expect((firstText || '').toLowerCase()).toMatch(/elimin/);
    });
});

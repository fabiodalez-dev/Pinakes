// @ts-check
//
// Unified-release regression suite — 25 focused tests covering the
// three PRs combined into this release:
//
//   Block U-SWAL  (8 tests) — PR #141 popup unification + UX follow-ups
//   Block U-EVT   (8 tests) — PR #139 event image layout (#137)
//   Block U-RIC   (9 tests) — PR #136 RiC-CM full roadmap (#122)
//
// These are NEW tests that target the *user-observable contract* each
// PR introduces — they sit alongside the existing per-PR specs (which
// stay in place) and act as a fast, focused regression net the release
// branch can rely on without spinning up every full-suite run.
//
// Design constraints:
//   - No-login subset preferred: 16 tests don't need admin auth.
//   - Authenticated subset (9 tests) gracefully `test.skip()` when
//     E2E_ADMIN_PASS is wrong or DB seed state is missing.
//   - No state mutation: every assertion is on rendered HTML, JSON
//     endpoints, or static repo files. Re-runnable in any order.
//
// Run:
//   /tmp/run-e2e.sh tests/unified-release-regression.spec.js \
//     --config=tests/playwright.config.js --workers=1
//
// Run just the no-login core:
//   /tmp/run-e2e.sh tests/unified-release-regression.spec.js \
//     --config=tests/playwright.config.js --workers=1 \
//     --grep "U-SWAL-(S|H1)|U-EVT-S|U-RIC-S"

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const BASE        = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const REPO        = path.resolve(__dirname, '..');

function readRepoFile(rel) {
    return fs.readFileSync(path.join(REPO, rel), 'utf8');
}

async function tryLoginAsAdmin(page) {
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.click('button[type="submit"]');
    try {
        // Strict admin-only match: /admin/* (with slash or end-of-path).
        // The previous /\/(admin|profilo)/ pattern accepted /profilo
        // too, which is the redirect target for SUCCESSFULLY logged-in
        // non-admin users — meaning the suite's E-block tests would
        // run on a non-admin session, hit 302/403 on /admin/*, and
        // fail in confusing ways. Now non-admin credentials cleanly
        // time out here and the caller falls back to `test.skip()`.
        await page.waitForURL(/\/admin(?:\/|$)/, { timeout: 10000 });
        return true;
    } catch {
        return false;
    }
}

// ═══════════════════════════════════════════════════════════════════
// BLOCK U-SWAL — PR #141 popup unification (8 tests)
// ═══════════════════════════════════════════════════════════════════
test.describe('[U-SWAL] PR #141 popup unification', () => {

    // S1 — SwalApp bus helper exports the 9 expected methods
    test('U-SWAL-S1: swal-config.js exports SwalApp with the canonical method set', async () => {
        const src = readRepoFile('public/assets/js/swal-config.js');
        for (const m of ['confirmDelete', 'confirm', 'prompt', 'success', 'error', 'info', 'warning', 'toast', 'attachSwalConfirm']) {
            expect(src, `SwalApp.${m} must be declared`).toMatch(new RegExp(`${m}\\s*:`));
        }
    });

    // S2 — confirmText default follows the kind (CodeRabbit fix dcf98a0f / 383b0a2d)
    test('U-SWAL-S2: confirmText default branches on isAction (kind-aware)', async () => {
        const src = readRepoFile('public/assets/js/swal-config.js');
        // The fix introduces `const isAction = form.dataset.swalConfirmKind === 'action';`
        // and `confirmText = ... || (isAction ? __swal('Conferma') : __swal('Elimina'))`.
        expect(src).toMatch(/const\s+isAction\s*=\s*form\.dataset\.swalConfirmKind\s*===\s*['"]action['"]/);
        expect(src).toMatch(/isAction\s*\?\s*__swal\(['"]Conferma['"]\)\s*:\s*__swal\(['"]Elimina['"]\)/);
    });

    // S3 — Defensive native fallback when Swal is missing (CDN/CSP failure)
    test('U-SWAL-S3: helpers fall back to window.confirm/alert/prompt when Swal absent', async () => {
        const src = readRepoFile('public/assets/js/swal-config.js');
        // The defensive guard `_hasSwal()` should appear in every public helper.
        expect(src).toMatch(/_hasSwal\s*\(/);
        // Native fallbacks must be present.
        expect(src).toMatch(/window\.confirm\(/);
        expect(src).toMatch(/window\.alert\(/);
        expect(src).toMatch(/window\.prompt\(/);
    });

    // S4 — One-shot data-swal-proceed marker (replaces the clear-on-confirm anti-pattern)
    test('U-SWAL-S4: attachSwalConfirm uses one-shot data-swal-proceed, not attribute mutation', async () => {
        const src = readRepoFile('public/assets/js/swal-config.js');
        // The proceed marker must be set + reset within the listener.
        expect(src).toMatch(/form\.dataset\.swalProceed\s*=\s*['"]1['"]/);
        expect(src).toMatch(/form\.dataset\.swalProceed\s*===\s*['"]1['"]/);
        expect(src).toMatch(/form\.dataset\.swalProceed\s*=\s*['"]['"]/);
        // The old anti-pattern `removeAttribute('data-swal-confirm')` must NOT
        // appear in the new auto-wire path — clearing the trigger attribute
        // permanently bypassed the dialog on every later click.
        expect(src).not.toMatch(/removeAttribute\(['"]data-swal-confirm['"]\)/);
    });

    // S5 — UX follow-ups: 3 non-destructive forms opt into kind=action
    test('U-SWAL-S5: non-destructive forms set data-swal-confirm-kind="action"', async () => {
        const expected = [
            'app/Views/admin/languages/index.php',
            'app/Views/utenti/dettagli_utente.php',
            'app/Views/utenti/index.php',
        ];
        for (const f of expected) {
            const src = readRepoFile(f);
            expect(src, `${f} must set kind=action on at least one form`).toMatch(/data-swal-confirm-kind="action"/);
        }
    });

    // S6 — Locale files carry the new SwalApp UI strings (Italian source)
    test('U-SWAL-S6: it_IT.json includes SwalApp default copy', async () => {
        const it = JSON.parse(readRepoFile('locale/it_IT.json'));
        // Italian is the source language — these keys are the strings the
        // SwalApp helper defaults to. Their presence in the IT catalog is
        // the canary that the locale file kept up with the bus contract.
        const sourceKey = 'Sei sicuro?';
        expect(sourceKey in it || JSON.stringify(it).includes(sourceKey)).toBe(true);
    });

    // ── Authenticated suite ──────────────────────────────────────────
    test.describe('[U-SWAL-H] admin-side runtime contract', () => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'E2E credentials not configured');
        let context, page;
        test.beforeAll(async ({ browser }) => {
            context = await browser.newContext();
            page = await context.newPage();
            const ok = await tryLoginAsAdmin(page);
            if (!ok) test.skip(true, 'Admin login failed');
        });
        test.afterAll(async () => { if (context) await context.close(); });

        // H1 — window.SwalApp is defined on /admin/dashboard
        test('U-SWAL-H1: window.SwalApp is initialised on admin pages', async () => {
            await page.goto(`${BASE}/admin/dashboard`);
            await page.waitForLoadState('domcontentloaded');
            const exists = await page.evaluate(() => typeof window.SwalApp === 'object' && window.SwalApp !== null);
            expect(exists).toBe(true);
        });

        // H2 — data-swal-confirm forms are wired post-DOMContentLoaded
        test('U-SWAL-H2: at least one data-swal-confirm form is wired on /admin/utenti', async () => {
            await page.goto(`${BASE}/admin/utenti`);
            await page.waitForLoadState('domcontentloaded');
            const wired = await page.locator('form[data-swal-confirm][data-swal-attached="1"]').count();
            // If no such form is present at all the page may render no pending
            // users — skip cleanly instead of failing on DB seed state.
            const present = await page.locator('form[data-swal-confirm]').count();
            test.skip(present === 0, 'No data-swal-confirm forms on this page — seed a pending user');
            expect(wired, 'auto-attach must mark forms with data-swal-attached="1"').toBeGreaterThan(0);
        });
    });
});

// ═══════════════════════════════════════════════════════════════════
// BLOCK U-EVT — PR #139 event image layout (8 tests)
// ═══════════════════════════════════════════════════════════════════
test.describe('[U-EVT] PR #139 configurable event image layout', () => {

    // E1 — 4-preset enum is the source of truth in settings/index.php
    test('U-EVT-S1: settings/index.php declares all 4 layout presets', async () => {
        const src = readRepoFile('app/Views/settings/index.php');
        expect(src).toMatch(/\$eventImageLayoutChoices\s*=\s*\[/);
        for (const preset of ['full', 'banner', 'contained', 'thumb']) {
            expect(src).toMatch(new RegExp(`['"]${preset}['"]\\s*=>`));
        }
    });

    // E2 — frontend/event-detail.php emits event-cover--<preset> classes
    test('U-EVT-S2: event-detail.php renders distinct CSS class per preset', async () => {
        const src = readRepoFile('app/Views/frontend/event-detail.php');
        for (const preset of ['full', 'banner', 'contained', 'thumb']) {
            expect(src).toMatch(new RegExp(`event-cover--${preset}`));
        }
        expect(src).toMatch(/event-card--thumb-layout/);
    });

    // E3 — SettingsController validates layout against an allow-list
    test('U-EVT-S3: SettingsController validates submitted layout value with in_array', async () => {
        const src = readRepoFile('app/Controllers/SettingsController.php');
        expect(src).toMatch(/event_image_layout/);
        expect(src).toMatch(/in_array\(\$submitted,\s*\$allowed/);
    });

    // E4 — EventsController has 4 deleteUploadedImageFile callsites (orphan-safe lifecycle)
    test('U-EVT-S4: EventsController calls deleteUploadedImageFile at 4 lifecycle slots', async () => {
        const src = readRepoFile('app/Controllers/EventsController.php');
        const callCount = (src.match(/\$this->deleteUploadedImageFile\(/g) || []).length;
        expect(callCount).toBeGreaterThanOrEqual(4);
        // Each callsite is documented in the function docblock — if a future
        // refactor drops one, the docblock alignment check fails.
        for (const slot of [
            /update\(\) success branch with image replacement/,
            /update\(\) failure branch/,
            /update\(\) validation-error short-circuit/,
            /delete\(\) success branch/,
        ]) {
            expect(src).toMatch(slot);
        }
    });

    // E5 — Path-traversal hardening on the unlink helper
    test('U-EVT-S5: deleteUploadedImageFile enforces /uploads/events/ prefix + realpath containment', async () => {
        const src = readRepoFile('app/Controllers/EventsController.php');
        const fn = src.match(/private function deleteUploadedImageFile[\s\S]*?\n {4}\}/);
        expect(fn).not.toBeNull();
        expect(fn[0]).toMatch(/strpos\(\$relativePath, ['"]\/uploads\/events\/['"]\) !== 0/);
        expect(fn[0]).toMatch(/realpath/);
        expect(fn[0]).toMatch(/strpos\(\$realCandidate.*\$baseDir/);
    });

    // E6 — Mobile collapse: thumb layout @media restyle present
    test('U-EVT-S6: event-detail.php has @media restyle for thumb layout on small screens', async () => {
        const src = readRepoFile('app/Views/frontend/event-detail.php');
        expect(src).toMatch(/@media[^{]*max-width[^{]*\)\s*\{/);
        const mediaBlocks = src.match(/@media[\s\S]*?\}\s*\}/g) || [];
        const hasThumbResponsive = mediaBlocks.some(b => b.includes('thumb-layout') || b.includes('event-cover--thumb'));
        expect(hasThumbResponsive).toBe(true);
    });

    // ── Authenticated suite ──────────────────────────────────────────
    test.describe('[U-EVT-H] admin-side layout-picker', () => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'E2E credentials not configured');
        let context, page;
        test.beforeAll(async ({ browser }) => {
            context = await browser.newContext();
            page = await context.newPage();
            const ok = await tryLoginAsAdmin(page);
            if (!ok) test.skip(true, 'Admin login failed');
        });
        test.afterAll(async () => { if (context) await context.close(); });

        // H1 — CMS settings tab exposes the layout picker
        test('U-EVT-H1: /admin/settings?tab=cms renders the event_image_layout picker', async () => {
            await page.goto(`${BASE}/admin/settings?tab=cms`);
            await page.waitForLoadState('domcontentloaded');
            const radios = page.locator('input[type="radio"][name*="event_image_layout"], input[type="radio"][name*="image_layout"]');
            const n = await radios.count();
            if (n === 0) {
                const sel = page.locator('select[name*="event_image_layout"], select[name*="image_layout"]');
                expect(await sel.count()).toBeGreaterThan(0);
            } else {
                expect(n).toBeGreaterThanOrEqual(4);
            }
        });

        // H2 — Save form posts to the canonical events route with a CSRF token
        test('U-EVT-H2: settings form for events posts to /admin/settings/events with CSRF', async () => {
            await page.goto(`${BASE}/admin/settings?tab=cms`);
            await page.waitForLoadState('domcontentloaded');
            const form = page.locator('form[action*="/admin/settings/events"]').first();
            const count = await form.count();
            test.skip(count === 0, 'Events settings form not rendered on this install');
            const csrf = form.locator('input[name="csrf_token"]');
            expect(await csrf.count()).toBeGreaterThan(0);
        });
    });
});

// ═══════════════════════════════════════════════════════════════════
// BLOCK U-RIC — PR #136 RiC-CM full roadmap (9 tests)
// ═══════════════════════════════════════════════════════════════════
test.describe('[U-RIC] PR #136 RiC-CM full roadmap', () => {

    // R1 — Migration set: phases 2/3/4/cleanup files exist
    test('U-RIC-S1: phase 2/3/4 + cleanup migration files exist on the unified branch', async () => {
        for (const f of [
            'installer/database/migrations/migrate_0.7.08.sql',
            'installer/database/migrations/migrate_0.7.09.sql',
            'installer/database/migrations/migrate_0.7.10.sql',
            'installer/database/migrations/migrate_0.7.12.sql',
        ]) {
            const c = readRepoFile(f);
            expect(c.length).toBeGreaterThan(0);
        }
    });

    // R2 — version.json >= highest migration version (CLAUDE.md rule #6)
    test('U-RIC-S2: version.json release ≥ highest migration file version', async () => {
        const { version } = JSON.parse(readRepoFile('version.json'));
        function cmp(a, b) {
            const aa = a.split('.').map(Number);
            const bb = b.split('.').map(Number);
            for (let i = 0; i < Math.max(aa.length, bb.length); i++) {
                const x = aa[i] || 0, y = bb[i] || 0;
                if (x !== y) return x - y;
            }
            return 0;
        }
        expect(cmp(version, '0.7.12')).toBeGreaterThanOrEqual(0);
    });

    // R3 — Phase 3 schema: archive_activities + link table, both IF NOT EXISTS
    test('U-RIC-S3: 0.7.09.sql creates archive_activities + archive_unit_activities idempotently', async () => {
        const sql = readRepoFile('installer/database/migrations/migrate_0.7.09.sql');
        expect(sql).toMatch(/archive_activities/i);
        expect(sql).toMatch(/archive_unit_activities/i);
        expect(sql).toMatch(/CREATE TABLE\s+IF NOT EXISTS/i);
    });

    // R4 — Phase 4 schema: places + relations
    test('U-RIC-S4: 0.7.10.sql creates archive_places + archive_relations', async () => {
        const sql = readRepoFile('installer/database/migrations/migrate_0.7.10.sql');
        expect(sql).toMatch(/archive_places/i);
        expect(sql).toMatch(/archive_relations/i);
    });

    // R5 — ArchivesPlugin follows the ensureSchema() pattern (CLAUDE.md plugin rule)
    test('U-RIC-S5: ArchivesPlugin.ensureSchema() is called from BOTH onActivate() AND onInstall()', async () => {
        const src = readRepoFile('storage/plugins/archives/ArchivesPlugin.php');
        expect(src).toMatch(/function\s+ensureSchema\s*\(/);
        const activateMatch = src.match(/function\s+onActivate[\s\S]*?\n {4}\}/);
        const installMatch  = src.match(/function\s+onInstall[\s\S]*?\n {4}\}/);
        expect(activateMatch).not.toBeNull();
        expect(installMatch).not.toBeNull();
        expect(activateMatch[0]).toMatch(/(?:\$this->)?ensureSchema\s*\(/);
        expect(installMatch[0]).toMatch(/(?:\$this->)?ensureSchema\s*\(/);
        expect(src).toMatch(/CREATE TABLE\s+IF NOT EXISTS/i);
    });

    // R6 — RicJsonLdBuilder covers the 4 RiC-O entity types added across phases 1-4
    test('U-RIC-S6: RicJsonLdBuilder references RecordResource/Agent/Activity/Place RiC-O types', async () => {
        const src = readRepoFile('storage/plugins/archives/RicJsonLdBuilder.php');
        expect(src).toMatch(/RecordResource|RecordSet/i);
        expect(src).toMatch(/Agent|CorporateBody|Person/i);
        expect(src).toMatch(/Activity/i);
        expect(src).toMatch(/Place/i);
        expect(src).toMatch(/@context|@type/);
    });

    // R7 — Phase 6: OAI-PMH plugin advertises metadataPrefix=ric-o
    test('U-RIC-S7: OaiPmhServerPlugin handles metadataPrefix=ric-o', async () => {
        const src = readRepoFile('storage/plugins/oai-pmh-server/OaiPmhServerPlugin.php');
        expect(src).toMatch(/ric-o/);
    });

    // R8 — Plugin version bumps reflect the new capabilities
    test('U-RIC-S8: archives plugin.json ≥ 1.5.0 and oai-pmh-server ≥ 1.1.0', async () => {
        function cmp(a, b) {
            const aa = a.split('.').map(Number);
            const bb = b.split('.').map(Number);
            for (let i = 0; i < Math.max(aa.length, bb.length); i++) {
                const x = aa[i] || 0, y = bb[i] || 0;
                if (x !== y) return x - y;
            }
            return 0;
        }
        const archives = JSON.parse(readRepoFile('storage/plugins/archives/plugin.json'));
        const oai = JSON.parse(readRepoFile('storage/plugins/oai-pmh-server/plugin.json'));
        expect(cmp(archives.version, '1.5.0')).toBeGreaterThanOrEqual(0);
        expect(cmp(oai.version, '1.1.0')).toBeGreaterThanOrEqual(0);
    });

    // R9 — Public RiC-O endpoints exist (smoke: route registered, returns 200 + JSON-LD)
    test('U-RIC-H1: /archives/collection.ric.json returns JSON-LD when archives plugin is active', async ({ request }) => {
        const resp = await request.get(`${BASE}/archives/collection.ric.json`);
        // Plugin may be deactivated on this install — accept 404 OR 200+JSON-LD.
        if (resp.status() === 404) {
            test.skip(true, 'Archives plugin not active on this install — activate to exercise');
            return;
        }
        expect(resp.ok()).toBe(true);
        const ct = resp.headers()['content-type'] || '';
        expect(ct).toMatch(/json/i);
        const body = await resp.json();
        expect(body).toHaveProperty('@context');
    });
});

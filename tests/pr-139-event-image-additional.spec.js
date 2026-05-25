// @ts-check
//
// Complementary regression suite for PR #139 (feat/event-image-layout-setting,
// closes #137). The dedicated `issue-137-event-image-layout.spec.js` covers
// the 4 visual presets + admin update + thumb overflow + i18n keys. This
// file targets the *other* modifications shipped in #139 that the layout
// tests don't exercise:
//
//   - EventsController orphan-cleanup symmetry across 4 callsites
//   - deleteUploadedImageFile path-traversal hardening
//   - Locale key completeness across it/en/de/fr
//   - cms.event_image_layout schema invariants
//   - Settings tab routing / form action
//   - Frontend CSS class set per preset
//
// Block S (Static) — no login, no DB. Runs against repo files only.
// Block H (HTTP)   — admin login + HTTP request, no UI clicks.
//
// Run:
//   /tmp/run-e2e.sh tests/pr-139-event-image-additional.spec.js \
//     --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

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
        await page.waitForURL(/\/(admin|profilo)/, { timeout: 10000 });
        return true;
    } catch {
        return false;
    }
}

// ═══════════════════════════════════════════════════════════════════
// BLOCK S — Static contract checks (no login, no HTTP)
// ═══════════════════════════════════════════════════════════════════
test.describe('[STATIC] PR #139 controller + assets contract', () => {

    // S1 — All four deleteUploadedImageFile callsites present in EventsController
    test('S1: EventsController calls deleteUploadedImageFile at 4 expected callsites', async () => {
        const src = readRepoFile('app/Controllers/EventsController.php');
        const callCount = (src.match(/\$this->deleteUploadedImageFile\(/g) || []).length;
        expect(callCount).toBeGreaterThanOrEqual(4);
        // The contract docblock must mention all four lifecycle slots so a
        // future refactor that drops one is loud.
        expect(src).toMatch(/update\(\) success branch with image replacement/);
        expect(src).toMatch(/update\(\) failure branch/);
        expect(src).toMatch(/update\(\) validation-error short-circuit/);
        expect(src).toMatch(/delete\(\) success branch/);
    });

    // S2 — deleteUploadedImageFile rejects paths that don't start with /uploads/events/
    test('S2: deleteUploadedImageFile early-return on bad relative-path prefix', async () => {
        const src = readRepoFile('app/Controllers/EventsController.php');
        const fn = src.match(/private function deleteUploadedImageFile[\s\S]*?\n {4}\}/);
        expect(fn).not.toBeNull();
        expect(fn[0]).toMatch(/strpos\(\$relativePath, ['"]\/uploads\/events\/['"]\) !== 0/);
        // Plus a realpath-containment check that defends against symlinks.
        expect(fn[0]).toMatch(/realpath/);
        expect(fn[0]).toMatch(/strpos\(\$realCandidate.*\$baseDir/);
    });

    // S3 — Unlink failures go to SecureLogger, not error_log (CLAUDE.md rule)
    test('S3: unlink failure uses SecureLogger::error not error_log', async () => {
        const src = readRepoFile('app/Controllers/EventsController.php');
        const fn = src.match(/private function deleteUploadedImageFile[\s\S]*?\n {4}\}/);
        expect(fn[0]).toMatch(/SecureLogger::error\(/);
        expect(fn[0]).not.toMatch(/error_log\(/);
        // error_get_last() must be captured to provide the OS-level errno.
        expect(fn[0]).toMatch(/error_get_last\(\)/);
    });

    // S4 — Locale key completeness for layout option strings (all 4 JSONs)
    test('S4: layout option labels exist in all 4 locale JSONs', async () => {
        const locales = ['it_IT', 'en_US', 'de_DE', 'fr_FR'];
        const keys = [
            'Piccola a sinistra (max 420px) — consigliato',
            'Miniatura affiancata al testo (240px)',
            'Banner basso a tutta larghezza (max altezza 220px)',
            'Originale a tutta larghezza (grande)',
        ];
        const report = {};
        for (const lang of locales) {
            const blob = readRepoFile(`locale/${lang}.json`);
            for (const k of keys) {
                report[`${lang}::${k.substring(0, 30)}`] = blob.includes(k);
            }
        }
        // The keys checked here are Italian SOURCE strings that the
        // i18n catalog uses as map keys (value is the locale-translated
        // form, key stays in Italian). Every locale JSON must therefore
        // contain the same key set — a missing en_US/de_DE/fr_FR key is
        // a real translation gap, not a translator-style preference.
        // Fail loudly on any locale's miss so CI catches the regression.
        const fails = Object.entries(report).filter(([, v]) => !v);
        expect(
            fails,
            `Missing locale entries: ${fails.map(([k]) => k).join(', ')}`
        ).toEqual([]);
    });

    // S5 — settings/index.php exposes the 4 layout choices
    test('S5: settings/index.php declares the 4-preset layout enum', async () => {
        const src = readRepoFile('app/Views/settings/index.php');
        expect(src).toMatch(/\$eventImageLayoutChoices\s*=\s*\[/);
        for (const preset of ['full', 'banner', 'contained', 'thumb']) {
            expect(src).toMatch(new RegExp(`['"]${preset}['"]\\s*=>`));
        }
    });

    // S6 — event-detail.php renders distinct CSS class per preset
    test('S6: event-detail.php emits event-cover--<preset> class', async () => {
        const src = readRepoFile('app/Views/frontend/event-detail.php');
        for (const preset of ['full', 'banner', 'contained', 'thumb']) {
            expect(src).toMatch(new RegExp(`event-cover--${preset}`));
        }
        // Plus the wrapper class for the side-by-side thumb layout.
        expect(src).toMatch(/event-card--thumb-layout/);
    });

    // S7 — Frontend CSS includes responsive collapse for thumb layout (mobile)
    test('S7: thumb layout has @media collapse to vertical stack on small screens', async () => {
        const src = readRepoFile('app/Views/frontend/event-detail.php');
        // The thumb preset uses CSS grid in landscape and must collapse to
        // a single column at small viewports.
        expect(src).toMatch(/@media[^{]*max-width[^{]*\)\s*\{/);
        // The mobile breakpoint should specifically restyle the thumb layout
        // (either the grid-template-columns or the event-card--thumb-layout class).
        const mediaBlocks = src.match(/@media[\s\S]*?\}\s*\}/g) || [];
        const hasThumbResponsive = mediaBlocks.some(b => b.includes('thumb-layout') || b.includes('event-cover--thumb'));
        expect(hasThumbResponsive).toBe(true);
    });

    // S8 — SettingsController persists cms.event_image_layout
    test('S8: SettingsController has a handler that writes event_image_layout', async () => {
        const src = readRepoFile('app/Controllers/SettingsController.php');
        expect(src).toMatch(/event_image_layout/);
        // Mandatory: persists via a settings-write API (repository->set,
        // ConfigStore::set, SettingsService, or direct system_settings).
        expect(src).toMatch(/->set\(['"]cms['"]|ConfigStore::set|system_settings|SettingsService|setSetting|saveSetting|updateSetting/i);
        // Plus an allow-list filter: untrusted POST input must be
        // validated against the 4 known preset values before persistence.
        expect(src).toMatch(/in_array\(\$submitted,\s*\$allowed/);
    });

    // S9 — web.php declares the events-settings POST route
    test('S9: routes/web.php has POST /admin/settings/events route', async () => {
        const src = readRepoFile('app/Routes/web.php');
        expect(src).toMatch(/\/admin\/settings\/events/);
    });
});

// ═══════════════════════════════════════════════════════════════════
// BLOCK H — HTTP-level checks (admin login required)
// ═══════════════════════════════════════════════════════════════════
test.describe('[HTTP] PR #139 admin-side behavior', () => {

    test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'E2E credentials not configured');

    let context, page;
    test.beforeAll(async ({ browser }) => {
        context = await browser.newContext();
        page = await context.newPage();
        const ok = await tryLoginAsAdmin(page);
        if (!ok) test.skip(true, 'Admin login failed');
    });

    test.afterAll(async () => {
        if (context) await context.close();
    });

    // H1 — Settings page exposes the layout picker
    test('H1: admin/settings?tab=cms renders the event-image-layout radio group', async () => {
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

    // H2 — Settings form posts to the events route
    test('H2: settings form for events-layout posts to /admin/settings/events', async () => {
        await page.goto(`${BASE}/admin/settings?tab=cms`);
        await page.waitForLoadState('domcontentloaded');
        const form = page.locator('form[action*="/admin/settings/events"]').first();
        const count = await form.count();
        test.skip(count === 0, 'Events settings form not rendered on this install');
        // Form must include a CSRF token (CLAUDE.md mandatory rule).
        const csrf = form.locator('input[name="csrf_token"]');
        expect(await csrf.count()).toBeGreaterThan(0);
    });
});

// @ts-check
//
// Regression test for issue #137 — admin-configurable layout for the
// featured image on event detail pages (`/eventi/<slug>`).
//
// Coverage (5 cases):
//   1. Default fallback ('contained') when the setting row is missing
//   2. Explicit layout = 'full'         (legacy full-width-no-constraint)
//   3. Explicit layout = 'banner'       (16:9 cropped)
//   4. Explicit layout = 'contained'    (max-height: 480px, object-fit: contain)
//   5. Explicit layout = 'thumb'        (right-floated 3:4 thumbnail)
//
// Each case sets `cms.event_image_layout` directly in the KV store
// (`system_settings`), navigates to the event detail page, and asserts:
//   • the figure has the class `event-cover--<layout>`
//   • the figure has `data-event-cover-layout="<layout>"`
//   • exactly one cover figure is rendered
//
// Run:
//   /tmp/run-e2e.sh tests/issue-137-event-image-layout.spec.js \
//     --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE        = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';
const DB_USER     = process.env.E2E_DB_USER     || '';
const DB_PASS     = process.env.E2E_DB_PASS     || '';
const DB_SOCKET   = process.env.E2E_DB_SOCKET   || '';
const DB_NAME     = process.env.E2E_DB_NAME     || '';

test.skip(
    !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_PASS || !DB_NAME,
    'E2E credentials not configured (set E2E_ADMIN_*, E2E_DB_*)',
);

const RUN_ID    = Date.now().toString(36);
const EVENT_TITLE = `Issue 137 Layout Test ${RUN_ID}`;
const EVENT_SLUG  = `issue-137-layout-test-${RUN_ID}`;
const EVENT_IMG   = '/assets/books.jpg'; // ships in public/assets

function dbExec(sql) {
    const args = ['-u', DB_USER, `-p${DB_PASS}`, DB_NAME, '-e', sql];
    if (DB_SOCKET) args.splice(3, 0, '-S', DB_SOCKET);
    execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000 });
}

function dbQuery(sql) {
    const args = ['-u', DB_USER, `-p${DB_PASS}`, DB_NAME, '-N', '-B', '-e', sql];
    if (DB_SOCKET) args.splice(3, 0, '-S', DB_SOCKET);
    return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000 }).trim();
}

function sqlEscape(s) {
    // MySQL string escape — sufficient for test fixtures where the input
    // is controlled (no untrusted data here, but we don't want a stray
    // apostrophe to break the seed).
    return String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

function setLayout(layout) {
    if (layout === null) {
        dbExec(`DELETE FROM system_settings WHERE category='cms' AND setting_key='event_image_layout'`);
        return;
    }
    dbExec(`
        INSERT INTO system_settings (category, setting_key, setting_value)
        VALUES ('cms', 'event_image_layout', '${sqlEscape(layout)}')
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)
    `);
}

test.describe.serial('Issue #137 — admin-configurable event image layout', () => {

    test.beforeAll(async () => {
        // Make sure the events page is enabled (the frontend controller
        // 404s otherwise).
        dbExec(`
            INSERT INTO system_settings (category, setting_key, setting_value)
            VALUES ('cms', 'events_page_enabled', '1')
            ON DUPLICATE KEY UPDATE setting_value='1'
        `);

        // Seed one event with a featured_image. event_date is today so it
        // is reachable from the public listing too.
        dbExec(`
            INSERT INTO events (title, slug, content, event_date, event_time, featured_image, is_active)
            VALUES (
                '${sqlEscape(EVENT_TITLE)}',
                '${sqlEscape(EVENT_SLUG)}',
                '<p>Issue 137 test event</p>',
                CURDATE(),
                '18:00:00',
                '${sqlEscape(EVENT_IMG)}',
                1
            )
        `);
    });

    test.afterAll(async () => {
        // Cleanup: delete test event + reset layout setting to default.
        dbExec(`DELETE FROM events WHERE slug='${sqlEscape(EVENT_SLUG)}'`);
        dbExec(`DELETE FROM system_settings WHERE category='cms' AND setting_key='event_image_layout'`);
    });

    /**
     * Shared assertion: fetch the event page, assert the figure has the
     * expected layout class + data attribute, and that there's exactly
     * one cover figure (no duplicate rendering from a stale partial).
     */
    async function expectLayout(page, expected) {
        const url = `${BASE}/eventi/${EVENT_SLUG}`;
        const response = await page.goto(url, { waitUntil: 'domcontentloaded' });
        expect(response, `GET ${url} must succeed`).not.toBeNull();
        // Defense in depth — some Apache setups normalise 200 → 200 but
        // a 404 here would mean events_page_enabled rolled back, which
        // the test should surface explicitly.
        expect(
            response.status(),
            `GET ${url} returned ${response.status()} — events_page_enabled may have been disabled`
        ).toBeLessThan(400);

        const cover = page.locator('figure.event-cover');
        await expect(cover).toHaveCount(1);

        // Class assertion: figure must carry the layout-specific modifier.
        await expect(cover).toHaveClass(new RegExp(`event-cover--${expected}\\b`));

        // Data attribute — survives any future CSS class rename and is
        // a stable hook for further QA tooling.
        await expect(cover).toHaveAttribute('data-event-cover-layout', expected);
    }

    test('1/5 default — when cms.event_image_layout is unset, falls back to contained', async ({ page }) => {
        setLayout(null);
        await expectLayout(page, 'contained');
    });

    test('2/5 full — explicit layout=full applies event-cover--full', async ({ page }) => {
        setLayout('full');
        await expectLayout(page, 'full');
    });

    test('3/5 banner — explicit layout=banner applies event-cover--banner', async ({ page }) => {
        setLayout('banner');
        await expectLayout(page, 'banner');
    });

    test('4/5 contained — explicit layout=contained applies event-cover--contained', async ({ page }) => {
        setLayout('contained');
        await expectLayout(page, 'contained');
    });

    test('5/5 thumb — explicit layout=thumb applies event-cover--thumb', async ({ page }) => {
        setLayout('thumb');
        await expectLayout(page, 'thumb');
    });

    // ────────────────────────────────────────────────────────────────────
    // Containment regression — guards against the float-overflow bug
    // reported during initial review: when content is short, a floated
    // .event-cover--thumb escapes its parent .event-card and ends up
    // visually on top of the page footer.
    //
    // The grid-based refactor places the figure in its own row/column,
    // so geometrically the figure can never extend below its parent
    // article. The test asserts that invariant at desktop width.
    // ────────────────────────────────────────────────────────────────────
    test('thumb layout: short-body event keeps the figure inside its article (no float overflow)', async ({ page }) => {
        setLayout('thumb');

        // Shrink the event content so a CSS float would expose the bug.
        const shortContent = '<p>Breve.</p>';
        const sqlSafe = shortContent.replace(/'/g, "\\'");
        dbExec(`UPDATE events SET content='${sqlSafe}' WHERE slug='${sqlEscape(EVENT_SLUG)}'`);

        // Desktop viewport — the grid kicks in at >=768px.
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(`${BASE}/eventi/${EVENT_SLUG}`, { waitUntil: 'domcontentloaded' });

        const card = page.locator('article.event-card').first();
        const fig  = page.locator('figure.event-cover--thumb').first();
        await expect(card).toBeVisible();
        await expect(fig).toBeVisible();

        // Card must carry the modifier that activates the grid layout.
        await expect(card).toHaveClass(/event-card--thumb-layout/);

        // Bounding-box invariant: figure.bottom MUST be <= card.bottom.
        // If the float regression returns, figure.bottom escapes the
        // parent and this assertion fails loudly.
        const cardBox = await card.boundingBox();
        const figBox  = await fig.boundingBox();
        expect(cardBox, 'event-card must have a bounding box').not.toBeNull();
        expect(figBox, 'event-cover--thumb must have a bounding box').not.toBeNull();
        if (cardBox && figBox) {
            const cardBottom = cardBox.y + cardBox.height;
            const figBottom  = figBox.y  + figBox.height;
            expect(
                figBottom,
                `figure bottom (${figBottom}) must stay within card bottom (${cardBottom}) — the float-overflow regression has returned`
            ).toBeLessThanOrEqual(cardBottom + 1);
        }
    });
});

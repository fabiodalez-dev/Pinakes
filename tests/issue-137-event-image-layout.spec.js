// @ts-check
//
// Regression test for issue #137 — admin-configurable layout for the
// featured image on event detail pages (`/eventi/<slug>`).
//
// Coverage (5 cases):
//   1. Default fallback ('contained') when the setting row is missing
//   2. Explicit layout = 'full'         (legacy full-width-no-constraint)
//   3. Explicit layout = 'banner'       (low banner, capped at 220px height with object-fit:cover)
//   4. Explicit layout = 'contained'    (max-width 420px left-aligned, max-height 320px, object-fit: contain)
//   5. Explicit layout = 'thumb'        (side thumbnail via CSS grid + .event-card--thumb-layout, 3:4 portrait)
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

// Locale-aware events URL prefix. Captured in beforeAll so cross-locale
// installs (en_US, de_DE) don't silently 404 against a hardcoded
// Italian /eventi/ prefix. Defaults to /eventi (the Italian path) when
// the locale row is absent so existing IT installs behave unchanged.
const EVENT_URL_PREFIX_BY_LOCALE = {
    it_IT: '/eventi',
    en_US: '/events',
    de_DE: '/events',
};
let EVENT_URL_PREFIX = '/eventi';

// Snapshot for events_page_enabled so afterAll can restore the original
// admin choice rather than leave the test seed (=1) behind.
//   null   → the row was absent before the test ran (DELETE on restore)
//   string → original setting_value (UPDATE back on restore)
let originalEventsPageEnabled = null;
let eventsPageEnabledWasAbsent = false;

// Same shape, but for event_image_layout. Per-test setLayout() rewrites
// this row, and the original afterAll unconditionally DELETEd it — which
// destroyed an admin's pre-existing custom layout choice on every run.
// Snapshot once at startup and restore the original value (or DELETE if
// absent) at teardown. Matches the events_page_enabled pattern above.
let originalEventImageLayout = null;
let eventImageLayoutWasAbsent = false;

test.describe.serial('Issue #137 — admin-configurable event image layout', () => {

    test.beforeAll(async () => {
        // Resolve locale-aware events URL prefix once. Falls back to
        // /eventi when the locale row is missing (matches installer
        // default and keeps legacy IT installs working).
        let locale = 'it_IT';
        try {
            const localeRow = dbQuery(
                `SELECT setting_value FROM system_settings WHERE category='app' AND setting_key='locale'`
            );
            if (localeRow) {
                locale = localeRow;
            }
        } catch (e) {
            // Best-effort — keep the IT default.
        }
        EVENT_URL_PREFIX = EVENT_URL_PREFIX_BY_LOCALE[locale] || '/eventi';

        // Snapshot the events_page_enabled setting so we can restore
        // it in afterAll (test pollution guard).
        const existing = dbQuery(
            `SELECT setting_value FROM system_settings WHERE category='cms' AND setting_key='events_page_enabled'`
        );
        if (existing === '' || existing === null) {
            eventsPageEnabledWasAbsent = true;
            originalEventsPageEnabled = null;
        } else {
            eventsPageEnabledWasAbsent = false;
            originalEventsPageEnabled = existing;
        }

        // Snapshot event_image_layout too — setLayout() rewrites it in
        // every test, and the original DELETE-on-teardown destroyed any
        // pre-existing custom choice the admin had configured.
        const existingLayout = dbQuery(
            `SELECT setting_value FROM system_settings WHERE category='cms' AND setting_key='event_image_layout'`
        );
        if (existingLayout === '' || existingLayout === null) {
            eventImageLayoutWasAbsent = true;
            originalEventImageLayout = null;
        } else {
            eventImageLayoutWasAbsent = false;
            originalEventImageLayout = existingLayout;
        }

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
        // Cleanup: delete test event + restore the original
        // event_image_layout (DELETE if it was absent before the suite,
        // else UPDATE back to the captured original value).
        dbExec(`DELETE FROM events WHERE slug='${sqlEscape(EVENT_SLUG)}'`);
        if (eventImageLayoutWasAbsent) {
            dbExec(`DELETE FROM system_settings WHERE category='cms' AND setting_key='event_image_layout'`);
        } else {
            dbExec(`
                INSERT INTO system_settings (category, setting_key, setting_value)
                VALUES ('cms', 'event_image_layout', '${sqlEscape(originalEventImageLayout)}')
                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)
            `);
        }

        // Restore events_page_enabled to its pre-test value so we do
        // not leave permanent test-state pollution behind.
        if (eventsPageEnabledWasAbsent) {
            dbExec(`DELETE FROM system_settings WHERE category='cms' AND setting_key='events_page_enabled'`);
        } else {
            dbExec(`
                UPDATE system_settings
                   SET setting_value='${sqlEscape(originalEventsPageEnabled)}'
                 WHERE category='cms' AND setting_key='events_page_enabled'
            `);
        }
    });

    /**
     * Shared assertion: fetch the event page, assert the figure has the
     * expected layout class + data attribute, and that there's exactly
     * one cover figure (no duplicate rendering from a stale partial).
     */
    async function expectLayout(page, expected) {
        const url = `${BASE}${EVENT_URL_PREFIX}/${EVENT_SLUG}`;
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
    // Effective-size regression (issue #137 user feedback): the four
    // presets must actually render at DIFFERENT, progressively smaller
    // dimensions. An earlier iteration of this feature shipped four
    // visually-equivalent "full width" variants, defeating the purpose
    // of the setting (the user explicitly asked for a smaller image).
    //
    // We assert:
    //   contained  → figure narrower than the article (small centred)
    //   thumb      → figure narrower than the article (side thumbnail)
    //   banner     → figure at full article width, capped to ~220px tall
    //   full       → figure at full article width, no height cap
    //
    // Using rendered bounding boxes (NOT computed CSS) catches the
    // historical mistake where `width: 100%` was applied to all four
    // variants but only the class name differed.
    // ────────────────────────────────────────────────────────────────────
    test('effective size — each preset renders at a distinct, smaller dimension', async ({ page }) => {
        // Reset content to a long enough body so 'banner' / 'contained'
        // have something below them to measure against.
        const longContent = '<p>' + 'Test event description. '.repeat(40) + '</p>';
        const sqlSafeLong = longContent.replace(/'/g, "\\'");
        dbExec(`UPDATE events SET content='${sqlSafeLong}' WHERE slug='${sqlEscape(EVENT_SLUG)}'`);

        await page.setViewportSize({ width: 1280, height: 900 });

        async function measure(layout) {
            setLayout(layout);
            await page.goto(`${BASE}${EVENT_URL_PREFIX}/${EVENT_SLUG}`, { waitUntil: 'domcontentloaded' });
            const card = page.locator('article.event-card').first();
            const fig  = page.locator('figure.event-cover').first();
            await expect(card).toBeVisible();
            await expect(fig).toBeVisible();
            const cardBox = await card.boundingBox();
            const figBox  = await fig.boundingBox();
            return {
                cardX: cardBox.x,
                cardWidth: cardBox.width,
                figX: figBox.x,
                figWidth: figBox.width,
                figHeight: figBox.height,
            };
        }

        const full      = await measure('full');
        const banner    = await measure('banner');
        const contained = await measure('contained');
        const thumb     = await measure('thumb');

        // full: figure width equals the inner card width (within padding tolerance)
        expect(
            full.figWidth,
            `full layout: figure should fill the card width (got ${full.figWidth}px, card ${full.cardWidth}px)`
        ).toBeGreaterThan(full.cardWidth * 0.85);

        // banner: width like full, height ~ 220px (within ±5px CSS rendering tolerance)
        expect(
            banner.figWidth,
            `banner layout: figure should be full-width (got ${banner.figWidth}px, card ${banner.cardWidth}px)`
        ).toBeGreaterThan(banner.cardWidth * 0.85);
        expect(
            banner.figHeight,
            `banner layout: figure height must be capped to ~220px (got ${banner.figHeight}px)`
        ).toBeLessThanOrEqual(225);

        // contained: max-width 420px, NARROWER than the card
        expect(
            contained.figWidth,
            `contained layout: figure must be ≤ 420px wide (got ${contained.figWidth}px) — the whole point of the default preset`
        ).toBeLessThanOrEqual(420);
        expect(
            contained.figWidth,
            `contained layout: figure must be visibly narrower than the card (got ${contained.figWidth}px vs card ${contained.cardWidth}px)`
        ).toBeLessThan(contained.cardWidth * 0.7);

        // contained: must be LEFT-ALIGNED (figure.x ≈ card.x + padding).
        // If a future refactor reintroduces margin: auto the figure
        // would centre and figX would shift toward cardWidth/2 — guard
        // against that here. We allow up to 100px of inner padding.
        const containedOffsetFromLeft = contained.figX - contained.cardX;
        expect(
            containedOffsetFromLeft,
            `contained layout: figure must be left-aligned (offset from card.left should be ≤ 100px of padding, got ${containedOffsetFromLeft}px)`
        ).toBeLessThanOrEqual(100);

        // thumb: ~240px wide (grid column), NARROWER than the card
        expect(
            thumb.figWidth,
            `thumb layout: figure must be ≤ 240px wide (got ${thumb.figWidth}px)`
        ).toBeLessThanOrEqual(245);

        // thumb: must be left-aligned too (grid column 1).
        const thumbOffsetFromLeft = thumb.figX - thumb.cardX;
        expect(
            thumbOffsetFromLeft,
            `thumb layout: figure must occupy the left grid column (offset ≤ 100px, got ${thumbOffsetFromLeft}px)`
        ).toBeLessThanOrEqual(100);
    });

    // ────────────────────────────────────────────────────────────────────
    // Replace-while-removing regression (issue #137 follow-up):
    // when the admin form posts BOTH a new featured_image file AND
    // ticks "remove_image=1", the new upload must win — not be
    // discarded along with the old image. Earlier code path used an
    // if/elseif with remove_image first, silently dropping the
    // upload.
    //
    // Reproduced end-to-end through the admin UI: login → open edit
    // form → tick "Rimuovi immagine attuale" → also choose a new
    // file via the hidden file input → submit → verify the DB row
    // has a brand-new path (not NULL and not the original).
    // ────────────────────────────────────────────────────────────────────
    test('admin update: uploading a new image while ticking "remove" keeps the new image', async ({ page }) => {
        // Bootstrap a "previous" image so the form actually shows the
        // "Rimuovi immagine attuale" checkbox (it only renders when
        // featured_image is non-empty).
        const sqlPre = `/uploads/events/event_test_pre_${RUN_ID}.jpg`;
        dbExec(`UPDATE events SET featured_image='${sqlEscape(sqlPre)}' WHERE slug='${sqlEscape(EVENT_SLUG)}'`);
        const eventId = parseInt(
            dbQuery(`SELECT id FROM events WHERE slug='${sqlEscape(EVENT_SLUG)}'`),
            10,
        );
        expect(eventId, 'seed event must exist').toBeGreaterThan(0);

        // Admin login (the form requires it).
        await page.goto(`${BASE}/accedi`);
        await page.fill('input[name="email"]', ADMIN_EMAIL);
        await page.fill('input[name="password"]', ADMIN_PASS);
        await Promise.all([
            page.waitForURL(/\/(admin|profilo)/, { timeout: 15000 }),
            page.click('button[type="submit"]'),
        ]);

        // Open the edit form.
        await page.goto(`${BASE}/admin/cms/events/edit/${eventId}`);
        await page.waitForLoadState('domcontentloaded');

        // Tick the "Rimuovi immagine attuale" checkbox.
        const removeCheckbox = page.locator('input[name="remove_image"]');
        await expect(removeCheckbox).toHaveCount(1);
        await removeCheckbox.check({ force: true });

        // Attach a new image to the hidden file input — the same
        // path the Uppy widget normally writes into.
        await page.setInputFiles('input[name="featured_image"]', {
            name: 'test-replacement.jpg',
            mimeType: 'image/jpeg',
            // 1x1 jpeg, base64-decoded — minimal valid file.
            buffer: Buffer.from(
                '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAr/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFAEBAAAAAAAAAAAAAAAAAAAAAP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AKpgB//Z',
                'base64',
            ),
        });

        // Submit. The submit button label varies with locale but
        // there's always a visible primary button inside the form.
        const submitButton = page.locator('form button[type="submit"]').first();
        await Promise.all([
            page.waitForURL(/\/admin\/cms\/events(\?|$)/, { timeout: 15000 }),
            submitButton.click(),
        ]);

        // Assert: the DB now points at a NEW upload, not NULL and
        // not the original sentinel path.
        const after = dbQuery(`SELECT featured_image FROM events WHERE id=${eventId}`);
        expect(
            after,
            `featured_image MUST contain a new upload, not be cleared. Got: "${after}"`
        ).toMatch(/^\/uploads\/events\/event_\d{8}_\d{6}_[a-f0-9]+\.(jpg|jpeg|png|webp)$/i);
        expect(
            after,
            `featured_image MUST NOT match the pre-existing sentinel ("${sqlPre}")`
        ).not.toBe(sqlPre);
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
        await page.goto(`${BASE}${EVENT_URL_PREFIX}/${EVENT_SLUG}`, { waitUntil: 'domcontentloaded' });

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

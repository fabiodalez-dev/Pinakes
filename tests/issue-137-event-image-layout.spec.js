// @ts-check
//
// Regression test for issue #137 — admin-configurable layout for the
// featured image on event detail pages (`/eventi/<slug>`).
//
// Coverage (5 cases):
//   1. Default fallback ('contained') when the setting row is missing
//   2. Explicit layout = 'full'         (legacy full-width-no-constraint)
//   3. Explicit layout = 'banner'       (low banner, capped at 220px height with object-fit:cover)
//   4. Explicit layout = 'contained'    (the hero cover, max 350px wide)
//   5. Stored legacy 'thumb'            (no longer offered; still the hero cover)
//
// Each case sets `cms.event_image_layout` directly in the KV store
// (`system_settings`), navigates to the event detail page, and asserts:
//   • contained (and a legacy thumb) → the image is the resource-hero cover
//     (`.resource-hero .book-cover-large`) and no body figure is rendered
//   • full / banner     → the hero stays plain and the image is rendered in the
//     body as `figure.event-cover.event-cover--<layout>` with
//     `data-event-cover-layout="<layout>"`
//
// Run:
//   /tmp/run-e2e.sh tests/issue-137-event-image-layout.spec.js \
//     --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

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
        //
        // BEFORE the DB DELETE: if the admin-update test (or any future
        // test) replaced the seed featured_image with a real upload via
        // handleImageUpload(), the path now points at
        // /uploads/events/event_*.jpg on disk. Bypassing the controller
        // delete() means deleteUploadedImageFile() would never run —
        // unlink the file here explicitly so the suite doesn't leave
        // orphans behind. The seed value (`/assets/books.jpg`) is a
        // static asset and is excluded by the /uploads/events/ prefix
        // guard, so this is safe to call unconditionally.
        const currentImage = dbQuery(
            `SELECT featured_image FROM events WHERE slug='${sqlEscape(EVENT_SLUG)}'`
        );
        if (currentImage && currentImage.startsWith('/uploads/events/')) {
            // Defense in depth: even with a sanitizing controller, the
            // teardown reads from the DB and a crafted path containing
            // '..' segments would let fs.unlinkSync escape public/uploads/events
            // when joined with path.join. Resolve from the uploads-events
            // root and verify the final absolute path stays inside it
            // before unlinking. (CodeRabbit #141.)
            const uploadsRoot = path.resolve(__dirname, '..', 'public', 'uploads', 'events');
            const relative    = currentImage.slice('/uploads/events/'.length);
            const absPath     = path.resolve(uploadsRoot, relative);
            const escaped     = path.relative(uploadsRoot, absPath);
            if (escaped.startsWith('..') || path.isAbsolute(escaped)) {
                console.warn(`teardown: refusing to unlink path outside uploads root: ${currentImage}`);
            } else {
                try {
                    fs.unlinkSync(absPath);
                } catch (e) {
                    // ENOENT (already gone) is acceptable — the controller's
                    // own cleanup may have unlinked it on the success path.
                    // Any other error we surface to the test log but do not
                    // fail the teardown — the suite has finished otherwise.
                    if (e && e.code !== 'ENOENT') {
                        console.warn(`teardown: could not unlink ${absPath}: ${e.message}`);
                    }
                }
            }
        }
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
     * Shared assertion: fetch the event page and check where the image is
     * rendered for the given layout. contained/thumb → hero cover;
     * full/banner → one body figure with the layout class + data attribute.
     */
    async function expectLayout(page, expected) {
        const url = `${BASE}${EVENT_URL_PREFIX}/${EVENT_SLUG}`;
        const response = await page.goto(url, { waitUntil: 'domcontentloaded' });
        expect(response, `GET ${url} must succeed`).not.toBeNull();
        expect(
            response.status(),
            `GET ${url} returned ${response.status()} — events_page_enabled may have been disabled`
        ).toBeLessThan(400);

        const heroCover = page.locator('.resource-hero .book-cover-large');
        const figure = page.locator('figure.event-cover');
        // The layout owns the page's only main landmark.
        await expect(page.locator('main'), 'one <main> per page').toHaveCount(1);

        if (expected === 'contained' || expected === 'thumb') {
            await expect(heroCover).toHaveCount(1);
            await expect(figure).toHaveCount(0);
            return;
        }

        await expect(heroCover).toHaveCount(0);
        await expect(figure).toHaveCount(1);
        await expect(figure).toHaveClass(new RegExp(`event-cover--${expected}\\b`));
        await expect(figure).toHaveAttribute('data-event-cover-layout', expected);
    }

    test('1/5 default — when cms.event_image_layout is unset, falls back to contained', async ({ page }) => {
        setLayout(null);
        await expectLayout(page, 'contained');
    });

    test('2/5 full — explicit layout=full renders the image in a body figure event-cover--full', async ({ page }) => {
        setLayout('full');
        await expectLayout(page, 'full');
    });

    test('3/5 banner — explicit layout=banner renders the image in a body figure event-cover--banner', async ({ page }) => {
        setLayout('banner');
        await expectLayout(page, 'banner');
    });

    test('4/5 contained — explicit layout=contained renders the image as the hero cover', async ({ page }) => {
        setLayout('contained');
        await expectLayout(page, 'contained');
    });

    test('5/5 legacy thumb — a stored layout=thumb still renders the hero cover', async ({ page }) => {
        setLayout('thumb');
        await expectLayout(page, 'contained');
    });

    // ────────────────────────────────────────────────────────────────────
    // Effective-size regression (issue #137): the presets must render at
    // different dimensions. Measured on rendered bounding boxes, not CSS:
    //   full       → body figure at the full width of the description column
    //   banner     → body figure at full width, capped to ~220px tall
    //   contained  → hero cover, much narrower than the description column
    // ────────────────────────────────────────────────────────────────────
    test('effective size — each preset renders at its own dimension', async ({ page }) => {
        const longContent = '<p>' + 'Test event description. '.repeat(40) + '</p>';
        dbExec(`UPDATE events SET content='${sqlEscape(longContent)}' WHERE slug='${sqlEscape(EVENT_SLUG)}'`);

        await page.setViewportSize({ width: 1280, height: 900 });

        async function measure(layout, selector) {
            setLayout(layout);
            await page.goto(`${BASE}${EVENT_URL_PREFIX}/${EVENT_SLUG}`, { waitUntil: 'domcontentloaded' });
            const section = page.locator('.book-description-section').first();
            const img = page.locator(selector).first();
            await expect(section).toBeVisible();
            await expect(img).toBeVisible();
            const sectionBox = await section.boundingBox();
            const imgBox = await img.boundingBox();
            expect(sectionBox, `description section boundingBox missing for layout=${layout}`).not.toBeNull();
            expect(imgBox, `image boundingBox missing for layout=${layout}`).not.toBeNull();
            return {
                sectionX: sectionBox ? sectionBox.x : 0,
                sectionWidth: sectionBox ? sectionBox.width : 0,
                imgX: imgBox ? imgBox.x : 0,
                imgWidth: imgBox ? imgBox.width : 0,
                imgHeight: imgBox ? imgBox.height : 0,
            };
        }

        const full      = await measure('full', 'figure.event-cover--full');
        const banner    = await measure('banner', 'figure.event-cover--banner');
        const contained = await measure('contained', '.resource-hero .book-cover-large');

        expect(full.imgWidth, `full: figure should fill the column (got ${full.imgWidth}px of ${full.sectionWidth}px)`)
            .toBeGreaterThan(full.sectionWidth * 0.85);

        expect(banner.imgWidth, `banner: figure should fill the column (got ${banner.imgWidth}px of ${banner.sectionWidth}px)`)
            .toBeGreaterThan(banner.sectionWidth * 0.85);
        expect(banner.imgHeight, `banner: height must be capped to ~220px (got ${banner.imgHeight}px)`)
            .toBeLessThanOrEqual(225);

        expect(contained.imgWidth, `contained: hero cover must be visibly narrower than the body column (got ${contained.imgWidth}px vs ${contained.sectionWidth}px)`)
            .toBeLessThan(contained.sectionWidth * 0.8);
        expect(contained.imgWidth, `contained: the hero cover is capped at 350px (got ${contained.imgWidth}px)`)
            .toBeLessThanOrEqual(351);
    });

    // Listing page: real cards from the shared catalogue markup.
    test('events list renders the event as a book-card with a "Dettagli" button', async ({ page }) => {
        const url = `${BASE}${EVENT_URL_PREFIX}`;
        const response = await page.goto(url, { waitUntil: 'domcontentloaded' });
        expect(response).not.toBeNull();
        expect(response.status(), `GET ${url}`).toBeLessThan(400);

        await expect(page.locator('.catalog-header h1.catalog-title')).toBeVisible();
        const card = page.locator('.books-grid .book-card--event', { hasText: EVENT_TITLE }).first();
        await expect(card).toBeVisible();
        await expect(card.locator(`a.btn-cta.btn-cta-sm[href$="/${EVENT_SLUG}"]`)).toHaveCount(1);
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

    // The settings offer three presets. 'thumb' rendered exactly like
    // 'contained', so it was merged into it: a stored 'thumb' shows as
    // 'contained' in the picker and saving the form stores 'contained'.
    test('admin settings: three presets, and a stored thumb is saved back as contained', async ({ page }) => {
        setLayout('thumb');
        await page.goto(`${BASE}/accedi`);
        await page.fill('input[name="email"]', ADMIN_EMAIL);
        await page.fill('input[name="password"]', ADMIN_PASS);
        await Promise.all([
            page.waitForURL(/\/(admin|profilo)/, { timeout: 15000 }),
            page.click('button[type="submit"]'),
        ]);

        await page.goto(`${BASE}/admin/settings?tab=cms`);
        const select = page.locator('select#event_image_layout');
        await expect(select).toHaveCount(1);
        const values = await select.locator('option').evaluateAll(options => options.map(o => o.value));
        expect(values).toEqual(['contained', 'banner', 'full']);
        await expect(select).toHaveValue('contained');

        const form = page.locator('form[action*="/admin/settings/events"]');
        await form.locator('button[type="submit"]').scrollIntoViewIfNeeded();
        await Promise.all([
            page.waitForURL(/\/admin\/settings/, { timeout: 15000 }),
            form.locator('button[type="submit"]').click(),
        ]);
        await expect.poll(() => dbQuery(
            "SELECT setting_value FROM system_settings WHERE category='cms' AND setting_key='event_image_layout'"
        )).toBe('contained');
    });

    // ────────────────────────────────────────────────────────────────────
    // Containment regression: with a short body the hero cover must stay
    // inside the hero band (never overlap the content below it).
    // ────────────────────────────────────────────────────────────────────
    test('contained layout: short-body event keeps the hero cover inside the hero', async ({ page }) => {
        setLayout('contained');
        dbExec(`UPDATE events SET content='${sqlEscape('<p>Breve.</p>')}' WHERE slug='${sqlEscape(EVENT_SLUG)}'`);

        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(`${BASE}${EVENT_URL_PREFIX}/${EVENT_SLUG}`, { waitUntil: 'domcontentloaded' });

        const hero = page.locator('.resource-hero').first();
        const cover = page.locator('.resource-hero .book-cover-large').first();
        await expect(hero).toBeVisible();
        await expect(cover).toBeVisible();

        const heroBox = await hero.boundingBox();
        const coverBox = await cover.boundingBox();
        expect(heroBox, 'hero must have a bounding box').not.toBeNull();
        expect(coverBox, 'hero cover must have a bounding box').not.toBeNull();
        if (heroBox && coverBox) {
            expect(
                coverBox.y + coverBox.height,
                'hero cover bottom must stay within the hero band'
            ).toBeLessThanOrEqual(heroBox.y + heroBox.height + 1);
        }
    });
});

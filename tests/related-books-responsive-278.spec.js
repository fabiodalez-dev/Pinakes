// @ts-check
/**
 * E2E — Related-books ("Potrebbero interessarti") responsive layout (#278).
 *
 * Guards the fix that stopped the related-book covers ballooning on large
 * screens. Reusable regression coverage for:
 *   - the card is capped to a book-sane width on desktop (never full-column);
 *   - NO size inversion across the 768px boundary (widening must not shrink
 *     the card within the same column count);
 *   - layout mode matches the viewport: below 1024px the section is a
 *     single-row horizontal snap-scroll strip (all cards on the strip,
 *     scrollable when they overflow); from 1024px up it is a centred grid
 *     showing at most 4 columns in the single visible row;
 *   - the row is grouped/centred (bounded width) on ultra-wide screens.
 *
 * Book detail is public — no login needed. The spec finds a book that actually
 * renders related books (an author with ≥2 catalogued books) so it never
 * silently passes on a page with no section.
 *
 * Run: /tmp/run-e2e.sh tests/related-books-responsive-278.spec.js --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE      = process.env.E2E_BASE_URL  || 'http://localhost:8081';
const DB_USER   = process.env.E2E_DB_USER   || '';
const DB_PASS   = process.env.E2E_DB_PASS   || '';
const DB_NAME   = process.env.E2E_DB_NAME   || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_HOST   = process.env.E2E_DB_HOST   || '';
const DB_PORT   = process.env.E2E_DB_PORT   || '';

test.skip(!DB_USER || !DB_NAME, 'Missing E2E env: DB_USER / DB_NAME required to locate a book with related books');

function dbQuery(sql) {
    const args = [];
    if (DB_HOST) { args.push('-h', DB_HOST); if (DB_PORT) args.push('-P', DB_PORT); }
    else if (DB_SOCKET) { args.push('-S', DB_SOCKET); }
    args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
    return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 15000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}

/**
 * A book whose author has ≥2 catalogued books → getRelatedBooks() returns rows.
 * Deterministic pick (ORDER BY, skip other specs' ZZ* fixture books) so the
 * measured page doesn't change with the physical row order of the catalog.
 */
function findBookWithRelated() {
    const row = dbQuery(
        "SELECT la.libro_id FROM libri_autori la " +
        "JOIN libri l ON l.id = la.libro_id AND l.deleted_at IS NULL " +
        "WHERE la.ruolo = 'principale' AND l.titolo NOT LIKE 'ZZ%' " +
        "AND la.autore_id IN (" +
        "  SELECT autore_id FROM libri_autori la2 " +
        "  JOIN libri l2 ON l2.id = la2.libro_id AND l2.deleted_at IS NULL " +
        "  WHERE la2.ruolo = 'principale' AND l2.titolo NOT LIKE 'ZZ%' " +
        "  GROUP BY autore_id HAVING COUNT(DISTINCT la2.libro_id) >= 2" +
        ") ORDER BY la.libro_id LIMIT 1"
    );
    return parseInt(row, 10) || 0;
}

/** Measure the related-books section at a given viewport width. */
async function measure(page, bookId, width) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`${BASE}/libro/${bookId}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(600);
    return page.evaluate(() => {
        const cards = Array.from(document.querySelectorAll('.related-book-card'));
        if (cards.length === 0) return { present: false };
        const rects = cards.map((c) => c.getBoundingClientRect());
        const top0 = Math.round(rects[0].top);
        const perRow = rects.filter((r) => Math.abs(Math.round(r.top) - top0) < 5).length;
        const wrap = document.querySelector('.related-books-wrap');
        const grid = document.querySelector('.related-books-grid');
        return {
            present: true,
            count: cards.length,
            cardW: Math.round(rects[0].width),
            perRow,
            wrapW: wrap ? Math.round(wrap.getBoundingClientRect().width) : null,
            mode: grid ? getComputedStyle(grid).display : null,
            scrollable: grid ? grid.scrollWidth > grid.clientWidth + 5 : false,
        };
    });
}

test.describe.serial('Related books — responsive layout (#278)', () => {
    /** @type {number} */
    let bookId = 0;

    test.beforeAll(() => {
        bookId = findBookWithRelated();
    });

    test('section renders for a book that has related books', async ({ page }) => {
        test.skip(bookId === 0, 'no book with related books in the catalog');
        const m = await measure(page, bookId, 1440);
        expect(m.present).toBe(true);
        expect(m.count).toBeGreaterThan(0);
    });

    test('card is capped to a book-sane width on desktop (never balloons)', async ({ page }) => {
        test.skip(bookId === 0, 'no book with related books');
        for (const w of [1440, 1920, 2560]) {
            const m = await measure(page, bookId, w);
            // The card must never exceed the 280px cap on desktop, no matter how
            // wide the viewport/column is. Pre-fix it grew to ~440px+.
            expect(m.cardW, `card width at ${w}px`).toBeLessThanOrEqual(285);
        }
    });

    test('no size inversion across the 768px boundary', async ({ page }) => {
        test.skip(bookId === 0, 'no book with related books');
        // Bug: a mobile rule capped the card wider (400px) than the desktop cap
        // (280px), so widening past 768px SHRANK the card. Widening must never
        // shrink the card.
        const below = await measure(page, bookId, 760);
        const above = await measure(page, bookId, 820);
        expect(above.cardW, 'widening past 768px must not shrink the card').toBeGreaterThanOrEqual(below.cardW - 1);
    });

    test('layout mode matches the viewport (snap strip < 1024px, grid above)', async ({ page }) => {
        test.skip(bookId === 0, 'no book with related books');
        // Below 1024px the section is a horizontal snap-scroll strip: every
        // card sits on the single strip row (never wraps), and when there is
        // more than one card the strip overflows sideways so the peeking next
        // card signals scroll-for-more.
        for (const w of [480, 700]) {
            const m = await measure(page, bookId, w);
            expect(m.mode, `layout mode at ${w}px`).toBe('flex');
            expect(m.perRow, `strip must keep all cards on one row at ${w}px`).toBe(m.count);
            if (m.count > 1) {
                expect(m.scrollable, `strip must overflow sideways at ${w}px`).toBe(true);
            }
        }
        // From 1024px up it is a centred grid: as many columns as there are
        // cards, capped at 4 in the single visible row (extras are clipped).
        const wide = await measure(page, bookId, 1400);
        expect(wide.mode, 'layout mode at 1400px').toBe('grid');
        expect(wide.perRow).toBeGreaterThanOrEqual(Math.min(3, wide.count));
        expect(wide.perRow).toBeLessThanOrEqual(4);
    });

    test('row is grouped/centred (bounded) on ultra-wide screens', async ({ page }) => {
        test.skip(bookId === 0, 'no book with related books');
        const m = await measure(page, bookId, 2560);
        // The .related-books-wrap caps the row so the cards don't spread across
        // the whole 2560px container.
        expect(m.wrapW, 'wrap width on 2560px').not.toBeNull();
        expect(m.wrapW).toBeLessThanOrEqual(1000);
    });
});

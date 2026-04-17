// @ts-check
/**
 * Regression for commit a51c53b — /api/dewey/children must return 200+[]
 * (not 404) for codes deeper than the JSON catalog, and the book-form
 * cascade must stop recursing when a loadLevel call comes back empty.
 *
 * Reproduction: a book with classificazione_dewey='305.42097' opened in
 * the admin edit form used to fire 4 console errors:
 *   /api/dewey/children?parent_code=305.42    → 404
 *   /api/dewey/children?parent_code=305.420   → 404
 *   /api/dewey/children?parent_code=305.4209  → 404
 *   /api/dewey/children?parent_code=305.42097 → 404
 *
 * This spec seeds such a book, opens its edit page in a headed browser,
 * captures every HTTP response that targets /api/dewey/children, and
 * asserts none came back as 404. Also asserts no "Dewey children API
 * error" console message was logged.
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

const RUN_ID = Date.now().toString(36);
const BOOK_TITLE = `E2E_DEWEY_${RUN_ID}`;
// Dewey code deeper than the JSON catalog (JSON stops at 305.4 in that subtree)
const LEGACY_DEWEY = '305.42097';

function mysqlArgs(sql) {
    const args = ['-u', DB_USER, `-p${DB_PASS}`];
    if (DB_SOCKET) args.push('--socket=' + DB_SOCKET);
    args.push(DB_NAME, '-N', '-B', '-e', sql);
    return args;
}
function dbQuery(sql) {
    return execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout: 10000 }).trim();
}
function dbExec(sql) {
    execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout: 10000 });
}

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
    'Missing E2E_ADMIN_EMAIL/PASS or E2E_DB_USER/NAME');

test.describe.serial('Dewey cascade — no 404 on legacy codes deeper than JSON', () => {
    /** @type {import('@playwright/test').BrowserContext} */
    let context;
    /** @type {import('@playwright/test').Page} */
    let page;
    let bookId = 0;

    test.beforeAll(async ({ browser }) => {
        context = await browser.newContext();
        page = await context.newPage();

        // Seed a book with a Dewey code deeper than the JSON catalog.
        dbExec(
            "INSERT INTO libri (titolo, classificazione_dewey, copie_totali, copie_disponibili, " +
            "stato, created_at, updated_at) " +
            `VALUES ('${BOOK_TITLE}', '${LEGACY_DEWEY}', 1, 1, 'disponibile', NOW(), NOW())`
        );
        bookId = parseInt(
            dbQuery(`SELECT id FROM libri WHERE titolo = '${BOOK_TITLE}' AND deleted_at IS NULL LIMIT 1`),
            10
        );
        expect(bookId).toBeGreaterThan(0);

        // Login
        await page.goto(`${BASE}/login`);
        await page.fill('input[name="email"]', ADMIN_EMAIL);
        await page.fill('input[name="password"]', ADMIN_PASS);
        await Promise.all([
            page.waitForURL(/\/admin\//, { timeout: 15000 }),
            page.click('button[type="submit"]'),
        ]);
    });

    test.afterAll(async () => {
        if (bookId > 0) {
            try {
                dbExec(
                    `UPDATE libri SET deleted_at = NOW(), ean = NULL, isbn13 = NULL, ` +
                    `isbn10 = NULL WHERE id = ${bookId} AND deleted_at IS NULL`
                );
            } catch (err) { console.error('[dewey-cascade teardown]', err.message); }
        }
        await context?.close();
    });

    test('opening edit form does not produce 404 on /api/dewey/children', async () => {
        // Collect all /api/dewey/children responses + console errors
        const deweyResponses = [];
        const consoleErrors = [];

        page.on('response', (resp) => {
            if (resp.url().includes('/api/dewey/children')) {
                deweyResponses.push({ url: resp.url(), status: resp.status() });
            }
        });
        page.on('console', (msg) => {
            if (msg.type() === 'error') consoleErrors.push(msg.text());
        });

        await page.goto(`${BASE}/admin/libri/modifica/${bookId}`);
        await page.waitForLoadState('networkidle', { timeout: 15000 });

        // Let the cascade finish its sweep up the path
        await page.waitForTimeout(2000);

        console.log(`[dewey responses for book ${bookId} / ${LEGACY_DEWEY}]:`,
            deweyResponses.map(r => `${r.status} ${r.url.split('?')[1] || '(root)'}`).join(', '));

        // Primary regression assertion: no 404 on the cascade
        const fourOhFours = deweyResponses.filter(r => r.status === 404);
        expect(
            fourOhFours,
            `Dewey cascade fired ${fourOhFours.length} 404(s): ${fourOhFours.map(r => r.url).join(', ')}`
        ).toEqual([]);

        // Every cascade call should be 200
        expect(deweyResponses.every(r => r.status === 200),
            `not all Dewey responses were 200: ${JSON.stringify(deweyResponses)}`).toBe(true);

        // No "Dewey children API error" in console (the loadLevel error log)
        const deweyConsoleErrors = consoleErrors.filter(e => e.includes('Dewey children API error'));
        expect(
            deweyConsoleErrors,
            `console errors for Dewey cascade: ${deweyConsoleErrors.join(' | ')}`
        ).toEqual([]);

        // Sanity: the cascade must have actually fired (else test is a no-op)
        expect(deweyResponses.length,
            'no /api/dewey/children calls at all — book form never loaded or Dewey cascade disabled')
            .toBeGreaterThan(0);
    });
});

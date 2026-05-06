// @ts-check
/**
 * E2E — OpenURL Z39.88 Resolver + COinS plugin tests (v0.7.2)
 *
 * Covers:
 *  1. Plugin registered in plugins table
 *  2. GET /openurl?rft.btitle=... → 302 redirect to external resolver
 *  3. GET /openurl with no params → 302 redirect (graceful fallback)
 *  4. GET /openurl?rft_val_fmt=...&rft.btitle=&rft.au= → valid redirect
 *  5. GET /api/coins/book/{id} → 200 with JSON containing coins_title and coins_html
 *  6. COinS title contains ctx_ver=Z39.88-2004
 *  7. COinS title contains rft_val_fmt=info:ofi/fmt:kev:mtx:book
 *  8. COinS HTML contains <span class="Z3988"
 *  9. GET /api/coins/book/9999999 → 404
 * 10. COinS is injected on book detail page (script tag present in <head>)
 *
 * Run: /tmp/run-e2e.sh tests/openurl-resolver.spec.js --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE      = process.env.E2E_BASE_URL  || 'http://localhost:8081';
const DB_USER   = process.env.E2E_DB_USER   || '';
const DB_PASS   = process.env.E2E_DB_PASS   || '';
const DB_NAME   = process.env.E2E_DB_NAME   || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';

function mysqlArgs(sql, batch = false) {
    const args = [];
    if (DB_SOCKET) args.push('-S', DB_SOCKET);
    args.push('-u', DB_USER, DB_NAME);
    if (batch) args.push('-N', '-B');
    if (sql !== '') args.push('-e', sql);
    return args;
}
const MYSQL_ENV = () => ({ ...process.env, MYSQL_PWD: DB_PASS });
function dbQuery(sql) {
    return execFileSync('mysql', mysqlArgs(sql, true), {
        encoding: 'utf-8', timeout: 10000, env: MYSQL_ENV(),
    }).trim();
}

test.skip(
    !DB_USER || !DB_NAME,
    'Missing E2E env (DB_*)'
);

test.describe.serial('OpenURL Z39.88 Resolver + COinS plugin — v0.7.2 (10 tests)', () => {
    /** @type {number} */
    let testBookId = 0;

    test.beforeAll(async () => {
        // Use a book known to exist in the DB (fallback to query).
        const result = dbQuery(
            "SELECT id FROM libri WHERE deleted_at IS NULL ORDER BY id LIMIT 1"
        );
        testBookId = parseInt(result) || 0;
    });

    // ── Test 1: Plugin registration ──────────────────────────────────────────

    test('1. openurl-resolver plugin registered in plugins table', async () => {
        const name = dbQuery("SELECT name FROM plugins WHERE name = 'openurl-resolver'");
        expect(name).toBe('openurl-resolver');
    });

    // ── Tests 2-4: /openurl resolver ─────────────────────────────────────────

    test('2. GET /openurl?rft.btitle=... → 302 redirect to external resolver', async ({ request }) => {
        const res = await request.get(
            `${BASE}/openurl?rft_val_fmt=info%3Aofi%2Ffmt%3Akev%3Amtx%3Abook&rft.btitle=Umberto+Eco&rft.au=Eco`,
            { maxRedirects: 0 }
        );
        expect(res.status()).toBe(302);
        const location = res.headers()['location'] ?? '';
        expect(location).toBeTruthy();
        // Should redirect to worldcat, google books, or local libro page
        expect(location.length).toBeGreaterThan(5);
    });

    test('3. GET /openurl with no params → 302 graceful fallback', async ({ request }) => {
        const res = await request.get(`${BASE}/openurl`, { maxRedirects: 0 });
        expect(res.status()).toBe(302);
        const location = res.headers()['location'] ?? '';
        expect(location).toBeTruthy();
    });

    test('4. GET /openurl with ISBN → 302 redirect', async ({ request }) => {
        // Use any ISBN (even non-existent triggers external fallback gracefully)
        const res = await request.get(
            `${BASE}/openurl?rft.isbn=9780141182605`,
            { maxRedirects: 0 }
        );
        expect(res.status()).toBe(302);
    });

    // ── Tests 5-8: /api/coins/book/{id} ──────────────────────────────────────

    test('5. GET /api/coins/book/{id} → 200 JSON with coins_title and coins_html', async ({ request }) => {
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/coins/book/${testBookId}`);
        expect(res.status()).toBe(200);
        const ct = res.headers()['content-type'] ?? '';
        expect(ct).toContain('application/json');
        const json = await res.json();
        expect(json).toHaveProperty('coins_title');
        expect(json).toHaveProperty('coins_html');
        expect(json).toHaveProperty('book_id', testBookId);
    });

    test('6. COinS title contains ctx_ver=Z39.88-2004', async ({ request }) => {
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/coins/book/${testBookId}`);
        const json = await res.json();
        expect(json.coins_title).toContain('ctx_ver=Z39.88-2004');
    });

    test('7. COinS title contains rft_val_fmt=info:ofi/fmt:kev:mtx:book', async ({ request }) => {
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/coins/book/${testBookId}`);
        const json = await res.json();
        // The value is URL-encoded in the title string
        expect(json.coins_title).toContain('rft_val_fmt=');
        expect(json.coins_title).toContain('book');
    });

    test('8. COinS HTML contains <span class="Z3988"', async ({ request }) => {
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/coins/book/${testBookId}`);
        const json = await res.json();
        expect(json.coins_html).toContain('class="Z3988"');
        expect(json.coins_html).toContain('<span');
    });

    // ── Test 9: 404 handling ──────────────────────────────────────────────────

    test('9. GET /api/coins/book/9999999 → 404', async ({ request }) => {
        const res = await request.get(`${BASE}/api/coins/book/9999999`);
        expect(res.status()).toBe(404);
        const json = await res.json();
        expect(json).toHaveProperty('error', true);
    });

    // ── Test 10: COinS injection script ──────────────────────────────────────

    test('10. COinS injection script tag is present in book detail page <head>', async ({ page }) => {
        test.skip(testBookId === 0, 'No book in DB');
        await page.goto(`${BASE}/libro/${testBookId}`, { waitUntil: 'domcontentloaded' });

        // The plugin injects a <script> tag that includes the coins endpoint URL
        const headContent = await page.evaluate(() => document.head.innerHTML);
        expect(headContent).toContain('/api/coins/book/');
    });
});

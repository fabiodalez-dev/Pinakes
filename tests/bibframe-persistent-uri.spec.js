// @ts-check
/**
 * E2E — BIBFRAME Persistent Linked-Data URIs (v0.7.4+)
 *
 * Covers the /id/work/{id} and /id/instance/{id} routes added to
 * BibframeLinkedDataPlugin. Implements the Linked Data Principles pattern:
 *   - HTML Accept  → 303 See Other to book frontend page
 *   - machine-readable Accept → 200 with BIBFRAME JSON-LD / Turtle / RDF+XML
 *
 * Tests:
 *  1.  /id/work/{id}     Accept: text/html        → 303 redirect
 *  2.  303 Location header points to book frontend URL
 *  3.  /id/instance/{id} Accept: text/html        → 303 redirect
 *  4.  303 Location for instance points to book frontend URL
 *  5.  /id/work/{id}     Accept: application/ld+json → 200
 *  6.  /id/work/{id}     JSON-LD @type is bf:Work
 *  7.  /id/work/{id}     JSON-LD @id ends with /work
 *  8.  /id/instance/{id} Accept: application/ld+json → 200
 *  9.  /id/instance/{id} JSON-LD @type is bf:Instance
 * 10.  /id/instance/{id} JSON-LD bf:isInstanceOf references /work URI
 * 11.  /id/work/{id}     Accept: text/turtle → 200
 * 12.  Turtle response Content-Type is text/turtle
 * 13.  /id/work/{id}     Accept: application/rdf+xml → 200
 * 14.  /id/work/9999999  → 404 for unknown book
 * 15.  /id/instance/9999999 → 404 for unknown book
 *
 * Run: /tmp/run-e2e.sh tests/bibframe-persistent-uri.spec.js --config=tests/playwright.config.js --workers=1
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

function mysqlArgs(sql, batch = false) {
    const args = [];
    if (DB_HOST) {
        args.push('-h', DB_HOST);
        if (DB_PORT) args.push('-P', DB_PORT);
    } else if (DB_SOCKET) {
        args.push('-S', DB_SOCKET);
    }
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
function dbExec(sql) {
    execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout: 10000, env: MYSQL_ENV() });
}

test.skip(!DB_USER || !DB_NAME, 'Missing E2E env (DB_*)');

const TAG = `E2E_BIBFRAME_LD_URI_${Date.now()}`;

test.describe.serial('BIBFRAME Persistent URIs — /id/work and /id/instance (15 tests)', () => {
    /** @type {number} */
    let bookId = 0;

    test.beforeAll(async () => {
        // Pre-cleanup: remove stale entries from prior failed runs.
        try { dbExec("DELETE FROM libri WHERE titolo LIKE 'E2E_BIBFRAME_LD_URI%'"); } catch { /* best-effort */ }
        dbExec(
            `INSERT INTO libri (titolo, anno_pubblicazione, created_at, updated_at) ` +
            `VALUES ('${TAG}', 2024, NOW(), NOW())`
        );
        bookId = parseInt(
            dbQuery(`SELECT id FROM libri WHERE titolo='${TAG}' AND deleted_at IS NULL LIMIT 1`)
        ) || 0;
    });

    test.afterAll(async () => {
        try { dbExec(`DELETE FROM libri WHERE titolo='${TAG}'`); } catch { /* best-effort */ }
    });

    // ── 303 redirect behaviour ────────────────────────────────────────────────

    test('1. /id/work/{id} Accept:text/html → 303 redirect', async ({ request }) => {
        test.skip(bookId === 0, 'No test book');
        const res = await request.get(`${BASE}/id/work/${bookId}`, {
            headers: { Accept: 'text/html' },
            maxRedirects: 0,
        });
        expect(res.status()).toBe(303);
    });

    test('2. 303 Location header points to book frontend URL', async ({ request }) => {
        test.skip(bookId === 0, 'No test book');
        const res = await request.get(`${BASE}/id/work/${bookId}`, {
            headers: { Accept: 'text/html' },
            maxRedirects: 0,
        });
        const loc = res.headers()['location'] ?? '';
        expect(loc).toBeTruthy();
        // Location must contain the book ID
        expect(loc).toContain(String(bookId));
    });

    test('3. /id/instance/{id} Accept:text/html → 303 redirect', async ({ request }) => {
        test.skip(bookId === 0, 'No test book');
        const res = await request.get(`${BASE}/id/instance/${bookId}`, {
            headers: { Accept: 'text/html' },
            maxRedirects: 0,
        });
        expect(res.status()).toBe(303);
    });

    test('4. 303 Location for /id/instance points to book frontend URL', async ({ request }) => {
        test.skip(bookId === 0, 'No test book');
        const res = await request.get(`${BASE}/id/instance/${bookId}`, {
            headers: { Accept: 'text/html' },
            maxRedirects: 0,
        });
        const loc = res.headers()['location'] ?? '';
        expect(loc).toContain(String(bookId));
    });

    // ── JSON-LD content negotiation ───────────────────────────────────────────

    test('5. /id/work/{id} Accept:application/ld+json → 200', async ({ request }) => {
        test.skip(bookId === 0, 'No test book');
        const res = await request.get(`${BASE}/id/work/${bookId}`, {
            headers: { Accept: 'application/ld+json' },
        });
        expect(res.status()).toBe(200);
    });

    test('6. /id/work/{id} JSON-LD @type is bf:Work', async ({ request }) => {
        test.skip(bookId === 0, 'No test book');
        const res = await request.get(`${BASE}/id/work/${bookId}`, {
            headers: { Accept: 'application/ld+json' },
        });
        const body = await res.json();
        const graph = Array.isArray(body['@graph']) ? body['@graph'] : [];
        const work = graph.find(n => n && n['@type'] === 'bf:Work');
        expect(work).toBeTruthy();
    });

    test('7. /id/work/{id} JSON-LD @id is the persistent work URI', async ({ request }) => {
        test.skip(bookId === 0, 'No test book');
        const res = await request.get(`${BASE}/id/work/${bookId}`, {
            headers: { Accept: 'application/ld+json' },
        });
        const body = await res.json();
        const graph = Array.isArray(body['@graph']) ? body['@graph'] : [];
        const work = graph.find(n => n && n['@type'] === 'bf:Work');
        expect((work?.['@id'] ?? '')).toContain(`/id/work/${bookId}`);
    });

    test('8. /id/instance/{id} Accept:application/ld+json → 200', async ({ request }) => {
        test.skip(bookId === 0, 'No test book');
        const res = await request.get(`${BASE}/id/instance/${bookId}`, {
            headers: { Accept: 'application/ld+json' },
        });
        expect(res.status()).toBe(200);
    });

    test('9. /id/instance/{id} JSON-LD @type is bf:Instance', async ({ request }) => {
        test.skip(bookId === 0, 'No test book');
        const res = await request.get(`${BASE}/id/instance/${bookId}`, {
            headers: { Accept: 'application/ld+json' },
        });
        const body = await res.json();
        const graph = Array.isArray(body['@graph']) ? body['@graph'] : [];
        const inst = graph.find(n => n && n['@type'] === 'bf:Instance');
        expect(inst).toBeTruthy();
    });

    test('10. /id/instance/{id} JSON-LD bf:isInstanceOf references /work URI', async ({ request }) => {
        test.skip(bookId === 0, 'No test book');
        const res = await request.get(`${BASE}/id/instance/${bookId}`, {
            headers: { Accept: 'application/ld+json' },
        });
        const body = await res.json();
        const graph = Array.isArray(body['@graph']) ? body['@graph'] : [];
        const inst = graph.find(n => n && n['@type'] === 'bf:Instance');
        const isInstanceOf = inst?.['bf:instanceOf']?.['@id'] ?? '';
        expect(isInstanceOf).toContain(`/id/work/${bookId}`);
    });

    // ── Turtle and RDF/XML serialisations ─────────────────────────────────────

    test('11. /id/work/{id} Accept:text/turtle → 200', async ({ request }) => {
        test.skip(bookId === 0, 'No test book');
        const res = await request.get(`${BASE}/id/work/${bookId}`, {
            headers: { Accept: 'text/turtle' },
        });
        expect(res.status()).toBe(200);
    });

    test('12. Turtle response Content-Type is text/turtle', async ({ request }) => {
        test.skip(bookId === 0, 'No test book');
        const res = await request.get(`${BASE}/id/work/${bookId}`, {
            headers: { Accept: 'text/turtle' },
        });
        expect(res.headers()['content-type'] ?? '').toContain('text/turtle');
    });

    test('13. /id/work/{id} Accept:application/rdf+xml → 200', async ({ request }) => {
        test.skip(bookId === 0, 'No test book');
        const res = await request.get(`${BASE}/id/work/${bookId}`, {
            headers: { Accept: 'application/rdf+xml' },
        });
        expect(res.status()).toBe(200);
        expect(res.headers()['content-type'] ?? '').toContain('rdf+xml');
    });

    // ── 404 for unknown IDs ───────────────────────────────────────────────────

    test('14. /id/work/9999999 → 404 for unknown book', async ({ request }) => {
        const res = await request.get(`${BASE}/id/work/9999999`, {
            headers: { Accept: 'application/ld+json' },
        });
        expect(res.status()).toBe(404);
    });

    test('15. /id/instance/9999999 → 404 for unknown book', async ({ request }) => {
        const res = await request.get(`${BASE}/id/instance/9999999`, {
            headers: { Accept: 'application/ld+json' },
        });
        expect(res.status()).toBe(404);
    });
});

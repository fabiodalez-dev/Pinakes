// @ts-check
/**
 * E2E — UNIMARC direct download endpoints (v0.7.4)
 *
 * Covers:
 *  1. GET /admin/books/{id}/unimarc.xml without auth → 403
 *  2. GET /admin/books/{id}/unimarc.xml → 200 with application/xml Content-Type
 *  3. Response body contains XML declaration
 *  4. Response contains MARC21slim namespace
 *  5. Response contains <leader> element
 *  6. Response contains UNIMARC field 001 (record ID)
 *  7. Response contains UNIMARC field 200 (title)
 *  8. Response contains UNIMARC field 801 (originating source = Pinakes)
 *  9. Content-Disposition header is attachment with .xml filename
 * 10. GET /admin/books/{id}/unimarc.mrc without auth → 403
 * 11. GET /admin/books/{id}/unimarc.mrc → 200 with application/marc Content-Type
 * 12. ISO 2709 binary: record starts with 5-digit length
 * 13. ISO 2709 binary: record ends with 0x1D (Record Terminator)
 * 14. ISO 2709 binary: leader[05]='n', leader[06]='a', leader[07]='m' (new, language material, monograph)
 * 15. ISO 2709 binary: directory entries are parseable (12 bytes each)
 * 16. GET /admin/books/9999999/unimarc.xml → 404
 * 17. GET /admin/books/9999999/unimarc.mrc → 404
 *
 * Run: /tmp/run-e2e.sh tests/unimarc-export.spec.js --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE        = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const DB_USER     = process.env.E2E_DB_USER     || '';
const DB_PASS     = process.env.E2E_DB_PASS     || '';
const DB_NAME     = process.env.E2E_DB_NAME     || '';
const DB_SOCKET   = process.env.E2E_DB_SOCKET   || '';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';

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

function basicAuth(user, pass) {
    return 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64');
}

test.skip(
    !DB_USER || !DB_NAME,
    'Missing E2E env (DB_*)'
);

test.describe.serial('UNIMARC direct download — v0.7.4 (17 tests)', () => {
    /** @type {number} */
    let testBookId = 0;
    /** @type {string} */
    let testBookTitle = '';

    test.beforeAll(async () => {
        const row = dbQuery(
            "SELECT id, titolo FROM libri WHERE deleted_at IS NULL ORDER BY id LIMIT 1"
        );
        if (row) {
            const [id, ...titleParts] = row.split('\t');
            testBookId = parseInt(id) || 0;
            testBookTitle = titleParts.join('\t').trim();
        }
    });

    // ── Tests 1-9: UNIMARC/XML ───────────────────────────────────────────────

    test('1. GET /admin/books/{id}/unimarc.xml without auth → 403', async ({ request }) => {
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/admin/books/${testBookId}/unimarc.xml`);
        expect(res.status()).toBe(403);
    });

    test('2. GET /admin/books/{id}/unimarc.xml → 200 application/xml', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/admin/books/${testBookId}/unimarc.xml`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        expect(res.status()).toBe(200);
        const ct = res.headers()['content-type'] ?? '';
        expect(ct).toContain('xml');
    });

    test('3. Response body contains XML declaration', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/admin/books/${testBookId}/unimarc.xml`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        const body = await res.text();
        expect(body).toContain('<?xml');
    });

    test('4. Response contains MARC21slim namespace', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/admin/books/${testBookId}/unimarc.xml`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        const body = await res.text();
        expect(body).toContain('loc.gov/MARC21/slim');
    });

    test('5. Response contains <leader> element', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/admin/books/${testBookId}/unimarc.xml`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        const body = await res.text();
        expect(body).toContain('<leader>');
    });

    test('6. Response contains UNIMARC field 001 (record ID)', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/admin/books/${testBookId}/unimarc.xml`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        const body = await res.text();
        expect(body).toContain('tag="001"');
        expect(body).toContain(`>${testBookId}<`);
    });

    test('7. Response contains UNIMARC field 200 (title)', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/admin/books/${testBookId}/unimarc.xml`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        const body = await res.text();
        expect(body).toContain('tag="200"');
    });

    test('8. Response contains UNIMARC field 801 (Pinakes originating source)', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/admin/books/${testBookId}/unimarc.xml`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        const body = await res.text();
        expect(body).toContain('tag="801"');
        expect(body).toContain('Pinakes');
    });

    test('9. Content-Disposition is attachment with .xml filename', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/admin/books/${testBookId}/unimarc.xml`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        const cd = res.headers()['content-disposition'] ?? '';
        expect(cd).toContain('attachment');
        expect(cd).toContain('.xml');
    });

    // ── Tests 10-15: ISO 2709 binary ─────────────────────────────────────────

    test('10. GET /admin/books/{id}/unimarc.mrc without auth → 403', async ({ request }) => {
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/admin/books/${testBookId}/unimarc.mrc`);
        expect(res.status()).toBe(403);
    });

    test('11. GET /admin/books/{id}/unimarc.mrc → 200 application/marc', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/admin/books/${testBookId}/unimarc.mrc`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        expect(res.status()).toBe(200);
        const ct = res.headers()['content-type'] ?? '';
        expect(ct).toContain('marc');
    });

    test('12. ISO 2709 leader: first 5 bytes are the record length (digits)', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/admin/books/${testBookId}/unimarc.mrc`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        const buf = Buffer.from(await res.body());
        const lenStr = buf.slice(0, 5).toString('ascii');
        expect(lenStr).toMatch(/^\d{5}$/);
        const recordLen = parseInt(lenStr);
        expect(recordLen).toBe(buf.length);
    });

    test('13. ISO 2709 binary: record ends with 0x1D (Record Terminator)', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/admin/books/${testBookId}/unimarc.mrc`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        const buf = Buffer.from(await res.body());
        expect(buf[buf.length - 1]).toBe(0x1D);
    });

    test('14. ISO 2709 leader[05..07] = "nam" (new, language material, monograph)', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/admin/books/${testBookId}/unimarc.mrc`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        const buf = Buffer.from(await res.body());
        expect(buf[5]).toBe(0x6E); // 'n'
        expect(buf[6]).toBe(0x61); // 'a'
        expect(buf[7]).toBe(0x6D); // 'm'
    });

    test('15. ISO 2709 directory entries are 12 bytes each', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testBookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/admin/books/${testBookId}/unimarc.mrc`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        const buf = Buffer.from(await res.body());
        const baseAddr = parseInt(buf.slice(12, 17).toString('ascii'));
        // Directory spans bytes 24 to baseAddr-1; the last byte is 0x1E (field terminator)
        const dirLen = baseAddr - 24 - 1; // exclude trailing 0x1E
        expect(dirLen % 12).toBe(0); // must be multiple of 12
        expect(dirLen / 12).toBeGreaterThan(0);
    });

    // ── Tests 16-17: Not found ────────────────────────────────────────────────

    test('16. GET /admin/books/9999999/unimarc.xml → 404', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        const res = await request.get(`${BASE}/admin/books/9999999/unimarc.xml`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        expect(res.status()).toBe(404);
    });

    test('17. GET /admin/books/9999999/unimarc.mrc → 404', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        const res = await request.get(`${BASE}/admin/books/9999999/unimarc.mrc`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        expect(res.status()).toBe(404);
    });
});

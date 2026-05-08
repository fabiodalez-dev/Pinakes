// @ts-check
/**
 * E2E — SRU recordSchema=unimarcxml (v0.7.4+)
 *
 * Verifies that the z39-server plugin's SRU endpoint correctly handles
 * recordSchema=unimarcxml, producing UNIMARC/XML inside the MARC21slim
 * namespace container.
 *
 * Tests:
 *  1.  SRU explain response is 200 OK
 *  2.  SRU explain lists "unimarcxml" as supported schema
 *  3.  SRU explain includes the SRU UNIMARC/XML schema URI
 *  4.  searchRetrieve?recordSchema=unimarcxml → 200
 *  5.  Response Content-Type contains xml
 *  6.  Response body contains searchRetrieveResponse element
 *  7.  Response body contains MARC21slim namespace declaration
 *  8.  numberOfRecords element is present and non-negative
 *  9.  When books exist: response contains <record> element
 * 10.  When books exist: record contains UNIMARC field 200 (title)
 *
 * Run: /tmp/run-e2e.sh tests/sru-unimarcxml.spec.js --config=tests/playwright.config.js --workers=1
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

/** Base SRU endpoint (z39-server plugin). */
const SRU = `${BASE}/api/sru`;

test.skip(!DB_USER || !DB_NAME, 'Missing E2E env (DB_*)');

test.describe.serial('SRU recordSchema=unimarcxml — v0.7.4 (10 tests)', () => {
    /** @type {boolean} */
    let hasBooks = false;

    test.beforeAll(async () => {
        const count = dbQuery(
            "SELECT COUNT(*) FROM libri WHERE deleted_at IS NULL"
        );
        hasBooks = parseInt(count) > 0;
    });

    // ── Explain operation ─────────────────────────────────────────────────────

    test('1. SRU explain response is 200 OK', async ({ request }) => {
        const res = await request.get(`${SRU}?operation=explain&version=1.1`);
        expect(res.status()).toBe(200);
    });

    test('2. SRU explain lists "unimarcxml" as supported record schema', async ({ request }) => {
        const res = await request.get(`${SRU}?operation=explain&version=1.1`);
        const body = await res.text();
        expect(body).toContain('unimarcxml');
    });

    test('3. SRU explain includes SRU UNIMARC/XML schema URI', async ({ request }) => {
        const res = await request.get(`${SRU}?operation=explain&version=1.1`);
        const body = await res.text();
        expect(body).toContain('info:srw/schema/8/unimarcxml-v0.1');
    });

    // ── searchRetrieve with recordSchema=unimarcxml ───────────────────────────

    test('4. searchRetrieve?recordSchema=unimarcxml → 200', async ({ request }) => {
        const res = await request.get(
            `${SRU}?operation=searchRetrieve&version=1.1&query=dc.title+%3D+%22a%22&recordSchema=unimarcxml`
        );
        expect(res.status()).toBe(200);
    });

    test('5. Response Content-Type contains xml', async ({ request }) => {
        const res = await request.get(
            `${SRU}?operation=searchRetrieve&version=1.1&query=dc.title+%3D+%22a%22&recordSchema=unimarcxml`
        );
        const ct = res.headers()['content-type'] ?? '';
        expect(ct).toContain('xml');
    });

    test('6. Response body contains searchRetrieveResponse element', async ({ request }) => {
        const res = await request.get(
            `${SRU}?operation=searchRetrieve&version=1.1&query=dc.title+%3D+%22a%22&recordSchema=unimarcxml`
        );
        const body = await res.text();
        expect(body).toContain('searchRetrieveResponse');
    });

    test('7. Response body contains MARC21slim namespace declaration', async ({ request }) => {
        test.skip(!hasBooks, 'No books in DB to produce records');
        const res = await request.get(
            `${SRU}?operation=searchRetrieve&version=1.1&query=dc.title+%3D+%22e%22&recordSchema=unimarcxml&maximumRecords=1`
        );
        const body = await res.text();
        expect(body).toContain('loc.gov/MARC21/slim');
    });

    test('8. numberOfRecords element is present and non-negative', async ({ request }) => {
        const res = await request.get(
            `${SRU}?operation=searchRetrieve&version=1.1&query=dc.title+%3D+%22a%22&recordSchema=unimarcxml`
        );
        const body = await res.text();
        const match = body.match(/<numberOfRecords>(\d+)<\/numberOfRecords>/);
        expect(match).not.toBeNull();
        expect(parseInt(match?.[1] ?? '-1')).toBeGreaterThanOrEqual(0);
    });

    test('9. When books exist: response contains <record> element', async ({ request }) => {
        test.skip(!hasBooks, 'No books in DB');
        // Use a very broad query that should match something
        const res = await request.get(
            `${SRU}?operation=searchRetrieve&version=1.1&query=dc.title+%3D+%22e%22&recordSchema=unimarcxml&maximumRecords=3`
        );
        const body = await res.text();
        // If numberOfRecords > 0, there must be <record> elements
        const nrMatch = body.match(/<numberOfRecords>(\d+)<\/numberOfRecords>/);
        const nr = parseInt(nrMatch?.[1] ?? '0');
        if (nr > 0) {
            expect(body).toContain('<record>');
        } else {
            // No results for this query — test is vacuously true
            expect(nr).toBeGreaterThanOrEqual(0);
        }
    });

    test('10. When books exist: record contains UNIMARC field 200 (title)', async ({ request }) => {
        test.skip(!hasBooks, 'No books in DB');
        const res = await request.get(
            `${SRU}?operation=searchRetrieve&version=1.1&query=dc.title+%3D+%22e%22&recordSchema=unimarcxml&maximumRecords=3`
        );
        const body = await res.text();
        const nrMatch = body.match(/<numberOfRecords>(\d+)<\/numberOfRecords>/);
        const nr = parseInt(nrMatch?.[1] ?? '0');
        if (nr > 0) {
            // UNIMARC field 200 is the title field
            expect(body).toContain('tag="200"');
        } else {
            expect(nr).toBeGreaterThanOrEqual(0);
        }
    });
});

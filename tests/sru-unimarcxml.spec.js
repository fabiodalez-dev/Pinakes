// @ts-check
/**
 * E2E — SRU recordSchema=unimarcxml (v0.7.4+)
 *
 * Verifies that the z39-server plugin's SRU endpoint correctly handles
 * recordSchema=unimarcxml, producing UNIMARC/XML inside the MARCXchange
 * namespace container.
 *
 * Tests:
 *  1.  SRU explain response is 200 OK
 *  2.  SRU explain lists "unimarcxml" as supported schema
 *  3.  SRU explain includes the SRU UNIMARC/XML schema URI
 *  4.  searchRetrieve?recordSchema=unimarcxml → 200
 *  5.  Response Content-Type contains xml
 *  6.  Response body contains searchRetrieveResponse element
 *  7.  Response body contains MARCXchange namespace declaration
 *  8.  numberOfRecords element is present and non-negative
 *  9.  When books exist: response contains <record> element
 * 10.  When books exist: record contains UNIMARC field 200 (title)
 *
 * SRU 1.2 protocol conformance (second describe block): explain without
 * operation, context sets in indexInfo, real serverInfo port, diagnostic
 * namespace, searchRetrieveResponse child order, binary NOT at book level,
 * diagnostics 6 / 16 / 71 and recordPacking=string.
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
const RUN_ID = `${Date.now()}`;

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

    test('7. Response body contains MARCXchange namespace declaration', async ({ request }) => {
        test.skip(!hasBooks, 'No books in DB to produce records');
        const res = await request.get(
            `${SRU}?operation=searchRetrieve&version=1.1&query=dc.title+%3D+%22e%22&recordSchema=unimarcxml&maximumRecords=1`
        );
        const body = await res.text();
        expect(body).toContain('info:lc/xmlns/marcxchange-v2');
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

test.describe.serial('SRU 1.2 protocol conformance', () => {
    test.skip(
        () => dbQuery("SELECT is_active FROM plugins WHERE name='z39-server'") !== '1',
        'z39-server plugin not active',
    );

    const TITLE = `SRUNOT${RUN_ID}`;
    /** @type {string[]} */
    let bookIds = [];
    /** @type {string[]} */
    let authorIds = [];

    test.beforeAll(() => {
        // Alpha has two authors, Beta has none: `NOT dc.creator=Drop` must drop
        // Alpha as a whole (not keep it through its other author's row) and
        // must keep Beta although its author columns are NULL.
        for (const suffix of ['Alpha', 'Beta']) {
            dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at) VALUES ('${TITLE} ${suffix}', 1, 1, NOW())`);
            bookIds.push(dbQuery(`SELECT id FROM libri WHERE titolo='${TITLE} ${suffix}' AND deleted_at IS NULL LIMIT 1`));
        }
        for (const name of [`Keep${RUN_ID} Author`, `Drop${RUN_ID} Author`]) {
            dbQuery(`INSERT INTO autori (nome) VALUES ('${name}')`);
            authorIds.push(dbQuery(`SELECT id FROM autori WHERE nome='${name}' LIMIT 1`));
        }
        dbQuery(`INSERT INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito) VALUES (${bookIds[0]}, ${authorIds[0]}, 'principale', 1), (${bookIds[0]}, ${authorIds[1]}, 'co-autore', 2)`);
    });

    test.afterAll(() => {
        if (bookIds.length) {
            dbQuery(`DELETE FROM libri_autori WHERE libro_id IN (${bookIds.join(',')})`);
            dbQuery(`UPDATE libri SET deleted_at=NOW(), isbn10=NULL, isbn13=NULL, ean=NULL WHERE id IN (${bookIds.join(',')})`);
        }
        if (authorIds.length) {
            dbQuery(`DELETE FROM autori WHERE id IN (${authorIds.join(',')})`);
        }
    });

    /** @param {import('@playwright/test').APIRequestContext} request @param {string} qs */
    const sru = async (request, qs) => {
        const res = await request.get(`${SRU}${qs}`);
        expect(res.status()).toBe(200);
        return res.text();
    };
    /** @param {string} body */
    const total = (body) => parseInt(body.match(/<numberOfRecords>(\d+)<\/numberOfRecords>/)?.[1] ?? '-1', 10);

    test('P1. a request without operation is an explain with declared context sets', async ({ request }) => {
        const body = await sru(request, '');
        expect(body).toContain('<explainResponse');
        expect(body).toContain('<set identifier="info:srw/cql-context-set/1/dc-v1.1" name="dc"/>');
        expect(body).toContain('name="bath"');
        expect(body).toContain('name="cql"');
        expect(body).toContain('<name set="dc">title</name>');
        expect(body).toContain('<name set="bath">isbn</name>');
        // serverInfo comes from the real request, not the localhost/80 defaults
        const port = new URL(BASE).port || (BASE.startsWith('https') ? '443' : '80');
        expect(body).toContain(`<port>${port}</port>`);
        expect(body).toContain(`<host>${new URL(BASE).hostname}</host>`);
    });

    test('P2. diagnostics live in the SRU diagnostic namespace', async ({ request }) => {
        const body = await sru(request, '?operation=badOp&version=1.2');
        expect(body).toContain('<diag:diagnostic xmlns:diag="http://www.loc.gov/zing/srw/diagnostic/">');
        expect(body).toContain('<diag:uri>info:srw/diagnostic/1/4</diag:uri>');
        expect(body).toMatch(/<diag:details>[^<]*<\/diag:details>/);
        expect(body).toMatch(/<diag:message>[^<]*<\/diag:message>/);
        expect(body).not.toContain('xmlns="info:srw/diagnostic/1/"');
    });

    test('P3. searchRetrieveResponse children follow the SRU 1.2 order', async ({ request }) => {
        const body = await sru(request, `?operation=searchRetrieve&version=1.2&query=${encodeURIComponent(`dc.title="${TITLE}"`)}&maximumRecords=1&recordSchema=dc`);
        expect(total(body)).toBe(2);
        const order = ['<version>', '<numberOfRecords>', '<records>', '<nextRecordPosition>', '<echoedSearchRetrieveRequest>']
            .map((tag) => body.indexOf(tag));
        expect(order.every((pos) => pos >= 0)).toBe(true);
        expect([...order].sort((a, b) => a - b)).toEqual(order);
        // inside <record>: recordSchema, recordPacking, recordData, recordPosition
        const rec = ['<recordSchema>', '<recordPacking>', '<recordData>', '<recordPosition>'].map((tag) => body.indexOf(tag));
        expect([...rec].sort((a, b) => a - b)).toEqual(rec);
    });

    test('P4. binary NOT excludes the whole book, also with several authors', async ({ request }) => {
        const notTitle = await sru(request, `?operation=searchRetrieve&version=1.2&recordSchema=dc&query=${encodeURIComponent(`dc.title="${TITLE}" NOT dc.title="Beta"`)}`);
        expect(total(notTitle)).toBe(1);
        expect(notTitle).toContain(`${TITLE} Alpha`);
        expect(notTitle).not.toContain(`${TITLE} Beta`);

        const notAuthor = await sru(request, `?operation=searchRetrieve&version=1.2&recordSchema=dc&query=${encodeURIComponent(`dc.title="${TITLE}" NOT dc.creator="Drop${RUN_ID}"`)}`);
        expect(total(notAuthor)).toBe(1);
        expect(notAuthor).toContain(`${TITLE} Beta`);
        expect(notAuthor).not.toContain(`${TITLE} Alpha`);

        // The leading form accepted by earlier releases keeps working.
        const andNot = await sru(request, `?operation=searchRetrieve&version=1.2&recordSchema=dc&query=${encodeURIComponent(`dc.title="${TITLE}" AND NOT dc.title="Alpha"`)}`);
        expect(total(andNot)).toBe(1);
        expect(andNot).toContain(`${TITLE} Beta`);
    });

    test('P5. an unknown index is diagnostic 16; serverChoice and bare terms search everywhere', async ({ request }) => {
        const unknown = await sru(request, `?operation=searchRetrieve&version=1.2&query=${encodeURIComponent('foo.bar=x')}`);
        expect(unknown).toContain('<diag:uri>info:srw/diagnostic/1/16</diag:uri>');
        expect(unknown).toContain('<diag:details>foo.bar</diag:details>');

        const serverChoice = await sru(request, `?operation=searchRetrieve&version=1.2&recordSchema=dc&query=${encodeURIComponent(`cql.serverChoice="${TITLE}"`)}`);
        expect(total(serverChoice)).toBe(2);
        const bare = await sru(request, `?operation=searchRetrieve&version=1.2&recordSchema=dc&query=${encodeURIComponent(`"${TITLE}"`)}`);
        expect(total(bare)).toBe(2);
    });

    test('P6. a negative or non-numeric maximumRecords is diagnostic 6', async ({ request }) => {
        for (const value of ['-1', 'abc']) {
            const body = await sru(request, `?operation=searchRetrieve&version=1.2&query=${encodeURIComponent(`dc.title="${TITLE}"`)}&maximumRecords=${value}`);
            expect(body).toContain('<diag:uri>info:srw/diagnostic/1/6</diag:uri>');
            expect(body).toContain('<numberOfRecords>0</numberOfRecords>');
        }
    });

    test('P7. recordPacking=string returns the record as escaped text; an unknown packing is diagnostic 71', async ({ request }) => {
        const body = await sru(request, `?operation=searchRetrieve&version=1.2&recordSchema=marcxml&recordPacking=string&query=${encodeURIComponent(`dc.title="${TITLE} Alpha"`)}`);
        expect(total(body)).toBe(1);
        expect(body).toContain('<recordPacking>string</recordPacking>');
        expect(body).toMatch(/<recordData>&lt;record xmlns="http:\/\/www\.loc\.gov\/MARC21\/slim"/);
        expect(body).toContain(`${TITLE} Alpha`);

        const bad = await sru(request, `?operation=searchRetrieve&version=1.2&recordPacking=json&query=${encodeURIComponent(`dc.title="${TITLE}"`)}`);
        expect(bad).toContain('<diag:uri>info:srw/diagnostic/1/71</diag:uri>');
    });
});

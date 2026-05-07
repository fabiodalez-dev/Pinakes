// @ts-check
/**
 * E2E — W3C Reconciliation API (v0.7.4)
 *
 * Covers the OpenRefine-compatible W3C Reconciliation API served by the
 * viaf-authority plugin at GET/POST /admin/api/reconcile.
 *
 * Covers:
 *  1. GET /admin/api/reconcile without auth → 403
 *  2. GET /admin/api/reconcile → 200 JSON service manifest
 *  3. Manifest has "name" field
 *  4. Manifest has "identifierSpace" pointing to viaf.org
 *  5. Manifest has "schemaSpace"
 *  6. Manifest has "defaultTypes" array
 *  7. Manifest has "versions" array containing "0.2"
 *  8. Manifest has "view" with url template
 *  9. GET /admin/api/reconcile?callback=fn → JSONP response
 * 10. POST /admin/api/reconcile without queries → manifest (same as GET)
 * 11. POST /admin/api/reconcile without auth → 403
 * 12. POST with queries={} → empty results per query key
 * 13. POST with valid query → result array for each query key
 * 14. POST result item has required fields: id, name, score, match, type
 * 15. POST result item type has id and name fields
 * 16. Exact name match returns score=100 and match=true
 * 17. No-match query returns empty result array (not an error)
 * 18. POST with invalid JSON queries → error response
 *
 * Run: /tmp/run-e2e.sh tests/viaf-reconcile.spec.js --config=tests/playwright.config.js --workers=1
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

/** POST reconcile with form-encoded queries JSON */
function postReconcile(request, queriesObj) {
    return request.post(`${BASE}/admin/api/reconcile`, {
        headers: {
            'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS),
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        data: 'queries=' + encodeURIComponent(JSON.stringify(queriesObj)),
    });
}

test.skip(
    !DB_USER || !DB_NAME,
    'Missing E2E env (DB_*)'
);

test.describe.serial('W3C Reconciliation API — v0.7.4 (18 tests)', () => {
    /** @type {string} */
    let testAuthorName = '';

    test.beforeAll(async () => {
        const row = dbQuery("SELECT nome FROM autori ORDER BY id LIMIT 1");
        testAuthorName = row.trim();
    });

    // ── Tests 1-2: Auth and basic response ───────────────────────────────────

    test('1. GET /admin/api/reconcile without auth → 403', async ({ request }) => {
        const res = await request.get(`${BASE}/admin/api/reconcile`);
        expect(res.status()).toBe(403);
    });

    test('2. GET /admin/api/reconcile → 200 JSON manifest', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        const res = await request.get(`${BASE}/admin/api/reconcile`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        expect(res.status()).toBe(200);
        const ct = res.headers()['content-type'] ?? '';
        expect(ct).toContain('json');
    });

    // ── Tests 3-8: Manifest structure ────────────────────────────────────────

    test('3. Manifest has "name" field', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        const res = await request.get(`${BASE}/admin/api/reconcile`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        const body = await res.json();
        expect(typeof body.name).toBe('string');
        expect(body.name.length).toBeGreaterThan(0);
    });

    test('4. Manifest identifierSpace points to viaf.org', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        const res = await request.get(`${BASE}/admin/api/reconcile`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        const body = await res.json();
        expect(body.identifierSpace).toContain('viaf.org');
    });

    test('5. Manifest has "schemaSpace" field', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        const res = await request.get(`${BASE}/admin/api/reconcile`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        const body = await res.json();
        expect(typeof body.schemaSpace).toBe('string');
    });

    test('6. Manifest has "defaultTypes" array with id and name', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        const res = await request.get(`${BASE}/admin/api/reconcile`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        const body = await res.json();
        expect(Array.isArray(body.defaultTypes)).toBe(true);
        expect(body.defaultTypes.length).toBeGreaterThan(0);
        expect(body.defaultTypes[0]).toHaveProperty('id');
        expect(body.defaultTypes[0]).toHaveProperty('name');
    });

    test('7. Manifest "versions" contains "0.2"', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        const res = await request.get(`${BASE}/admin/api/reconcile`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        const body = await res.json();
        expect(Array.isArray(body.versions)).toBe(true);
        expect(body.versions).toContain('0.2');
    });

    test('8. Manifest "view" has url template with {{id}}', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        const res = await request.get(`${BASE}/admin/api/reconcile`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        const body = await res.json();
        expect(body.view).toHaveProperty('url');
        expect(body.view.url).toContain('{{id}}');
    });

    // ── Test 9: JSONP ─────────────────────────────────────────────────────────

    test('9. GET with ?callback=myFn → JSONP response', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        const res = await request.get(`${BASE}/admin/api/reconcile?callback=myFn`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        expect(res.status()).toBe(200);
        const text = await res.text();
        expect(text.startsWith('myFn({')).toBe(true);
    });

    // ── Tests 10-11: POST without queries → manifest ──────────────────────────

    test('10. POST without queries body → returns manifest', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        const res = await request.post(`${BASE}/admin/api/reconcile`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
            data: '',
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body).toHaveProperty('name');
    });

    test('11. POST /admin/api/reconcile without auth → 403', async ({ request }) => {
        const res = await request.post(`${BASE}/admin/api/reconcile`, {
            data: 'queries={}',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        });
        expect(res.status()).toBe(403);
    });

    // ── Tests 12-15: Batch reconciliation ────────────────────────────────────

    test('12. POST with empty queries object → {} response', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        const res = await postReconcile(request, {});
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(typeof body).toBe('object');
        expect(Object.keys(body)).toHaveLength(0);
    });

    test('13. POST with valid query → result array for each query key', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(!testAuthorName, 'No author in DB');
        const res = await postReconcile(request, {
            q0: { query: testAuthorName, limit: 3 },
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body).toHaveProperty('q0');
        expect(Array.isArray(body.q0.result)).toBe(true);
    });

    test('14. POST result item has id, name, score, match, type', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(!testAuthorName, 'No author in DB');
        const res = await postReconcile(request, {
            q0: { query: testAuthorName, limit: 1 },
        });
        const body = await res.json();
        expect(body.q0.result.length).toBeGreaterThan(0);
        const item = body.q0.result[0];
        expect(item).toHaveProperty('id');
        expect(item).toHaveProperty('name');
        expect(typeof item.score).toBe('number');
        expect(typeof item.match).toBe('boolean');
        expect(Array.isArray(item.type)).toBe(true);
    });

    test('15. POST result type has id and name', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(!testAuthorName, 'No author in DB');
        const res = await postReconcile(request, {
            q0: { query: testAuthorName, limit: 1 },
        });
        const body = await res.json();
        expect(body.q0.result.length).toBeGreaterThan(0);
        const typeEntry = body.q0.result[0].type[0];
        expect(typeEntry).toHaveProperty('id');
        expect(typeEntry).toHaveProperty('name');
    });

    // ── Tests 16-17: Score semantics ─────────────────────────────────────────

    test('16. Exact name match → score=100 and match=true', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(!testAuthorName, 'No author in DB');
        const res = await postReconcile(request, {
            q0: { query: testAuthorName, limit: 5 },
        });
        const body = await res.json();
        const exact = (body.q0.result || []).find(
            (/** @type {any} */ r) => r.name.toLowerCase() === testAuthorName.toLowerCase()
        );
        expect(exact).toBeDefined();
        expect(exact.score).toBe(100);
        expect(exact.match).toBe(true);
    });

    test('17. Nonsense query returns empty result (not an error)', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        const res = await postReconcile(request, {
            q0: { query: 'zzz_no_match_xyz_9999', limit: 3 },
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(Array.isArray(body.q0.result)).toBe(true);
        expect(body.q0.result).toHaveLength(0);
    });

    // ── Test 18: Error handling ───────────────────────────────────────────────

    test('18. POST with invalid JSON queries → error response (4xx or error key)', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        const res = await request.post(`${BASE}/admin/api/reconcile`, {
            headers: {
                'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS),
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            data: 'queries=not-valid-json{{{',
        });
        // Spec allows 400 for malformed input or 200 with error key
        const isError = res.status() === 400 || res.status() === 422;
        let hasErrorKey = false;
        if (res.headers()['content-type']?.includes('json')) {
            try {
                const body = await res.json();
                hasErrorKey = typeof body.error !== 'undefined';
            } catch { /* non-JSON response is fine for 4xx */ }
        }
        expect(isError || hasErrorKey).toBe(true);
    });
});

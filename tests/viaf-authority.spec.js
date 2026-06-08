// @ts-check
/**
 * E2E — VIAF Authority Control + ISNI plugin tests (v1.1.0)
 *
 * Covers:
 *  1. Plugin registered in plugins table
 *  2. GET /api/viaf/suggest?q= without auth → 403
 *  3. GET /api/viaf/suggest?q= with auth, short query → 400
 *  4. GET /api/viaf/suggest?q=Primo+Levi → results array with viafid + name
 *  5. Suggest result contains viafUrl
 *  6. GET /api/viaf/author/{id} without auth → 403
 *  7. GET /api/viaf/author/{id} → returns author with viaf + isni fields
 *  8. POST /api/viaf/author/{id}/set → saves viaf_id + isni_id
 *  9. GET /api/viaf/author/{id} after set → viaf_id, isni_id, viaf_uri, isni_uri match
 * 10. POST /api/viaf/author/{id}/set with invalid VIAF ID → 400
 * 11. POST /api/viaf/author/{id}/isni/set with valid ISNI → 200
 * 12. POST /api/viaf/author/{id}/isni/set with invalid check digit → 400
 * 13. POST /api/viaf/author/{id}/set with empty values → clears both fields
 * 14. authority_source = 'viaf' and authority_confidence = 'exact' after set
 * 15. ISNI with spaces in request is accepted (normalised)
 * 16. GET /api/viaf/author/9999999 → 404
 *
 * Run: /tmp/run-e2e.sh tests/viaf-authority.spec.js --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE        = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const DB_USER     = process.env.E2E_DB_USER     || '';
const DB_PASS     = process.env.E2E_DB_PASS     || '';
const DB_NAME     = process.env.E2E_DB_NAME     || '';
const DB_HOST     = process.env.E2E_DB_HOST     || '';
const DB_PORT     = process.env.E2E_DB_PORT     || '';
const DB_SOCKET   = process.env.E2E_DB_SOCKET   || '';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';

// Connection precedence: TCP (-h/-P) → Unix socket (-S) → defaults.
// CI runs MySQL in a Docker container reachable only via TCP; this
// helper used to support only DB_SOCKET, which produced silent
// connection failures and downstream "no row" assertion errors.
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

function basicAuth(user, pass) {
    return 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64');
}

/**
 * POST helper that sends form-encoded data with Basic Auth.
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {string} path
 * @param {string} data  URL-encoded body string
 * @returns {Promise<import('@playwright/test').APIResponse>}
 */
function adminPost(request, path, data) {
    return request.post(`${BASE}${path}`, {
        headers: {
            'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS),
            'Content-Type': 'application/x-www-form-urlencoded',
            // Non-ambient API-client marker: a real stateless client sets this;
            // a cross-site browser request cannot (CORS preflight). Required for
            // the plugin's CSRF exemption alongside Basic auth + no cookies.
            'X-Requested-With': 'XMLHttpRequest',
        },
        data,
    });
}

test.skip(
    !DB_USER || !DB_NAME,
    'Missing E2E env (DB_*)'
);

test.describe.serial('VIAF Authority + ISNI plugin — v1.1.0 (16 tests)', () => {
    /** @type {number} */
    let testAuthorId = 0;
    /** @type {Record<string, string>} original authority field values to restore after tests */
    let originalAuthorFields = {};

    test.beforeAll(async () => {
        const row = dbQuery("SELECT id FROM autori ORDER BY id LIMIT 1");
        testAuthorId = parseInt(row) || 0;

        if (testAuthorId > 0) {
            // Save original values so afterAll can perform a symmetric restore.
            const orig = dbQuery(
                `SELECT viaf_id, viaf_uri, isni_id, isni_uri, authority_source, authority_confidence
                 FROM autori WHERE id = ${testAuthorId}`
            );
            const cols = orig.split('\t');
            originalAuthorFields = {
                viaf_id:              cols[0] ?? 'NULL',
                viaf_uri:             cols[1] ?? 'NULL',
                isni_id:              cols[2] ?? 'NULL',
                isni_uri:             cols[3] ?? 'NULL',
                authority_source:     cols[4] ?? 'NULL',
                authority_confidence: cols[5] ?? 'NULL',
            };

            dbQuery(
                `UPDATE autori SET viaf_id = NULL, viaf_uri = NULL, isni_id = NULL,
                 isni_uri = NULL, authority_source = NULL, authority_confidence = NULL
                 WHERE id = ${testAuthorId}`
            );
        }
    });

    test.afterAll(async () => {
        if (testAuthorId > 0) {
            try {
                // Restore original values (symmetric restore — not just NULLing).
                const toSql = (v) => (v === 'NULL' || v === '' || v === '\\N')
                    ? 'NULL'
                    : `'${v.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
                dbQuery(
                    `UPDATE autori SET
                       viaf_id              = ${toSql(originalAuthorFields.viaf_id ?? 'NULL')},
                       viaf_uri             = ${toSql(originalAuthorFields.viaf_uri ?? 'NULL')},
                       isni_id              = ${toSql(originalAuthorFields.isni_id ?? 'NULL')},
                       isni_uri             = ${toSql(originalAuthorFields.isni_uri ?? 'NULL')},
                       authority_source     = ${toSql(originalAuthorFields.authority_source ?? 'NULL')},
                       authority_confidence = ${toSql(originalAuthorFields.authority_confidence ?? 'NULL')}
                     WHERE id = ${testAuthorId}`
                );
            } catch { /* best-effort */ }
        }
    });

    // ── Test 1: Plugin registration ──────────────────────────────────────────

    test('1. viaf-authority plugin registered', async () => {
        const name = dbQuery("SELECT name FROM plugins WHERE name = 'viaf-authority'");
        expect(name).toBe('viaf-authority');
    });

    // ── Tests 2-3: Auth and input validation ─────────────────────────────────

    test('2. GET /api/viaf/suggest without auth → 403', async ({ request }) => {
        const res = await request.get(`${BASE}/api/viaf/suggest?q=Test`);
        expect(res.status()).toBe(403);
    });

    test('3. GET /api/viaf/suggest with 1-char query → 400', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        const res = await request.get(`${BASE}/api/viaf/suggest?q=L`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        expect(res.status()).toBe(400);
    });

    // ── Tests 4-5: AutoSuggest proxy (VIAF may be unreachable in CI) ─────────

    test('4. GET /api/viaf/suggest?q=Levi → results array with viafid', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        const res = await request.get(`${BASE}/api/viaf/suggest?q=Levi`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        if (res.status() === 503) { test.skip(true, 'VIAF unreachable'); return; }
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(Array.isArray(body.results)).toBe(true);
        if (body.results.length > 0) {
            expect(body.results[0]).toHaveProperty('viafid');
            expect(body.results[0]).toHaveProperty('name');
            expect(body.results[0]).toHaveProperty('isni_id');
        }
    });

    test('5. Suggest result contains viafUrl in https://viaf.org format', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        const res = await request.get(`${BASE}/api/viaf/suggest?q=Levi`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        if (res.status() === 503) { test.skip(true, 'VIAF unreachable'); return; }
        const body = await res.json();
        if (body.results.length === 0) { test.skip(true, 'No VIAF results'); return; }
        expect(body.results[0].viafUrl).toMatch(/^https:\/\/viaf\.org\/viaf\/\d+$/);
    });

    // ── Tests 6-7: GET author ─────────────────────────────────────────────────

    test('6. GET /api/viaf/author/{id} without auth → 403', async ({ request }) => {
        test.skip(testAuthorId === 0, 'No author in DB');
        const res = await request.get(`${BASE}/api/viaf/author/${testAuthorId}`);
        expect(res.status()).toBe(403);
    });

    test('7. GET /api/viaf/author/{id} → returns all authority fields', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testAuthorId === 0, 'No author in DB');
        const res = await request.get(`${BASE}/api/viaf/author/${testAuthorId}`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body.id).toBe(testAuthorId);
        expect(typeof body.nome).toBe('string');
        // All new fields present (may be null initially)
        expect('viaf_id'              in body).toBe(true);
        expect('viaf_uri'             in body).toBe(true);
        expect('isni_id'              in body).toBe(true);
        expect('isni_uri'             in body).toBe(true);
        expect('authority_source'     in body).toBe(true);
        expect('authority_confidence' in body).toBe(true);
    });

    // ── Tests 8-10: Set viaf_id + isni_id ────────────────────────────────────

    test('8. POST /api/viaf/author/{id}/set → saves viaf_id + isni_id', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testAuthorId === 0, 'No author in DB');
        // VIAF 79045105 = Primo Levi; ISNI algorithmically valid: check digit = 6
        const res = await adminPost(request, `/api/viaf/author/${testAuthorId}/set`,
            'viaf_id=79045105&isni_id=0000000121436346');
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body.success).toBe(true);
        expect(body.viaf_id).toBe('79045105');
        expect(body.isni_id).toBe('0000000121436346');
    });

    test('9. GET after set → viaf_id, isni_id, URIs, authority fields correct', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testAuthorId === 0, 'No author in DB');
        const res = await request.get(`${BASE}/api/viaf/author/${testAuthorId}`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body.viaf_id).toBe('79045105');
        expect(body.isni_id).toBe('0000000121436346');
        expect(body.viaf_uri).toBe('https://viaf.org/viaf/79045105');
        expect(body.isni_uri).toBe('https://isni.org/isni/0000000121436346');
        expect(body.authority_source).toBe('viaf');
        expect(body.authority_confidence).toBe('exact');
    });

    test('10. POST /api/viaf/author/{id}/set with invalid VIAF ID → 400', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testAuthorId === 0, 'No author in DB');
        const res = await adminPost(request, `/api/viaf/author/${testAuthorId}/set`,
            'viaf_id=not-a-number');
        expect(res.status()).toBe(400);
    });

    // ── Tests 11-12: ISNI-only endpoint ──────────────────────────────────────

    test('11. POST /api/viaf/author/{id}/isni/set with valid ISNI → 200', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testAuthorId === 0, 'No author in DB');
        // Algorithmically valid: first 15 digits all-zero except last=2 → check=8
        const res = await adminPost(request, `/api/viaf/author/${testAuthorId}/isni/set`,
            'isni_id=0000000000000028');
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body.success).toBe(true);
        expect(body.isni_id).toBe('0000000000000028');
    });

    test('12. POST /api/viaf/author/{id}/isni/set with invalid check digit → 400', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testAuthorId === 0, 'No author in DB');
        // 0000000000000027 = check should be 8 but is 7 → MOD 11-2 fails
        const res = await adminPost(request, `/api/viaf/author/${testAuthorId}/isni/set`,
            'isni_id=0000000000000027');
        expect(res.status()).toBe(400);
        const body = await res.json();
        expect(body.error).toBe(true);
        expect(body.message).toMatch(/ISNI non valido/i);
    });

    // ── Test 13: Clear values ─────────────────────────────────────────────────

    test('13. POST /api/viaf/author/{id}/set with empty values → clears both', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testAuthorId === 0, 'No author in DB');
        const res = await adminPost(request, `/api/viaf/author/${testAuthorId}/set`,
            'viaf_id=&isni_id=');
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body.success).toBe(true);
        expect(body.viaf_id).toBeNull();
        expect(body.isni_id).toBeNull();

        // Confirm DB
        const row = dbQuery(`SELECT IFNULL(viaf_id,'NULL'), IFNULL(isni_id,'NULL') FROM autori WHERE id = ${testAuthorId}`);
        expect(row).toBe('NULL\tNULL');
    });

    // ── Test 14: authority fields after re-set ────────────────────────────────

    test('14. authority_source and confidence set correctly after viaf set', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testAuthorId === 0, 'No author in DB');
        const setupRes = await adminPost(request, `/api/viaf/author/${testAuthorId}/set`, 'viaf_id=79045105');
        expect(setupRes.status()).toBe(200);
        const res = await request.get(`${BASE}/api/viaf/author/${testAuthorId}`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        const body = await res.json();
        expect(body.authority_source).toBe('viaf');
        expect(body.authority_confidence).toBe('exact');
    });

    // ── Test 15: ISNI with spaces normalised ─────────────────────────────────

    test('15. ISNI with spaces is accepted and normalised', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testAuthorId === 0, 'No author in DB');
        // Send "0000 0001 2143 6346" → should be stored as "0000000121436346" (check digit=6, valid)
        const res = await adminPost(request, `/api/viaf/author/${testAuthorId}/isni/set`,
            encodeURIComponent('isni_id') + '=' + encodeURIComponent('0000 0001 2143 6346'));
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body.isni_id).toBe('0000000121436346');
    });

    // ── Test 16: Non-existent author ─────────────────────────────────────────

    test('16. GET /api/viaf/author/9999999 → 404', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        const res = await request.get(`${BASE}/api/viaf/author/9999999`, {
            headers: { 'Authorization': basicAuth(ADMIN_EMAIL, ADMIN_PASS) },
        });
        expect(res.status()).toBe(404);
    });
});

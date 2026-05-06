// @ts-check
/**
 * E2E — BIBFRAME 2.0 Linked Data plugin tests (v0.7.1)
 *
 * Covers:
 *  1. Plugin registered in plugins table
 *  2. GET /api/bibframe/book/{id} → JSON-LD (default Accept)
 *  3. GET /api/bibframe/book/{id} → JSON-LD with Accept: application/ld+json
 *  4. GET /api/bibframe/book/{id} → Turtle with Accept: text/turtle
 *  5. GET /api/bibframe/book/{id} → RDF/XML with Accept: application/rdf+xml
 *  6. GET /api/bibframe/book/{id}/work → JSON-LD Work node only
 *  7. GET /api/bibframe/book/{id}/instance → JSON-LD Instance node only
 *  8. GET /api/bibframe/book/9999999 → 404 for unknown book
 *  9. Content negotiation: JSON-LD @context has bf prefix
 * 10. JSON-LD graph has bf:Work and bf:Instance nodes
 *
 * Run: /tmp/run-e2e.sh tests/bibframe-linked-data.spec.js --config=tests/playwright.config.js --workers=1
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
function dbExec(sql) {
    execFileSync('mysql', mysqlArgs(sql), { encoding: 'utf-8', timeout: 10000, env: MYSQL_ENV() });
}

test.skip(
    !DB_USER || !DB_NAME,
    'Missing E2E env (DB_*)'
);

test.describe.serial('BIBFRAME 2.0 Linked Data plugin — v0.7.1 (10 tests)', () => {
    /** @type {number} */
    let testBookId = 0;

    test.beforeAll(async () => {
        // Create a minimal test book.
        dbExec(
            "INSERT INTO libri (titolo, anno_pubblicazione, created_at, updated_at) " +
            "VALUES ('E2E_BIBFRAME_Book', 2024, NOW(), NOW())"
        );
        testBookId = parseInt(
            dbQuery("SELECT id FROM libri WHERE titolo='E2E_BIBFRAME_Book' AND deleted_at IS NULL LIMIT 1")
        );
    });

    test.afterAll(async () => {
        try {
            dbExec(`DELETE FROM libri WHERE titolo='E2E_BIBFRAME_Book'`);
        } catch { /* best-effort */ }
    });

    // ── Test 1: Plugin registration ──────────────────────────────────────────

    test('1. bibframe-linked-data plugin registered in plugins table', async () => {
        const name = dbQuery("SELECT name FROM plugins WHERE name = 'bibframe-linked-data'");
        expect(name).toBe('bibframe-linked-data');
    });

    // ── Tests 2-5: Content negotiation ────────────────────────────────────────

    test('2. GET /api/bibframe/book/{id} → JSON-LD (default Accept)', async ({ request }) => {
        const res = await request.get(`${BASE}/api/bibframe/book/${testBookId}`);
        expect(res.status()).toBe(200);
        const ct = res.headers()['content-type'] ?? '';
        expect(ct).toContain('application/ld+json');
        const json = await res.json();
        expect(json).toHaveProperty('@context');
        expect(json).toHaveProperty('@graph');
    });

    test('3. GET /api/bibframe/book/{id} → JSON-LD with explicit Accept', async ({ request }) => {
        const res = await request.get(`${BASE}/api/bibframe/book/${testBookId}`, {
            headers: { Accept: 'application/ld+json' },
        });
        expect(res.status()).toBe(200);
        expect(res.headers()['content-type'] ?? '').toContain('application/ld+json');
    });

    test('4. GET /api/bibframe/book/{id} → Turtle with Accept: text/turtle', async ({ request }) => {
        const res = await request.get(`${BASE}/api/bibframe/book/${testBookId}`, {
            headers: { Accept: 'text/turtle' },
        });
        expect(res.status()).toBe(200);
        const ct = res.headers()['content-type'] ?? '';
        expect(ct).toContain('text/turtle');
        const body = await res.text();
        expect(body).toContain('@prefix bf:');
        expect(body).toContain('bf:Work');
    });

    test('5. GET /api/bibframe/book/{id} → RDF/XML with Accept: application/rdf+xml', async ({ request }) => {
        const res = await request.get(`${BASE}/api/bibframe/book/${testBookId}`, {
            headers: { Accept: 'application/rdf+xml' },
        });
        expect(res.status()).toBe(200);
        const ct = res.headers()['content-type'] ?? '';
        expect(ct).toContain('application/rdf+xml');
        const body = await res.text();
        expect(body).toContain('rdf:RDF');
        expect(body).toContain('bf:Work');
    });

    // ── Tests 6-7: Sub-resource endpoints ─────────────────────────────────────

    test('6. GET /api/bibframe/book/{id}/work → JSON-LD Work node only', async ({ request }) => {
        const res = await request.get(`${BASE}/api/bibframe/book/${testBookId}/work`);
        expect(res.status()).toBe(200);
        const json = await res.json();
        // The endpoint may return a bare node or a @graph wrapper — both are valid.
        const node = json['@graph'] ? json['@graph'][0] : json;
        expect(node).toHaveProperty('@type');
        const type = Array.isArray(node['@type']) ? node['@type'].join(' ') : (node['@type'] ?? '');
        expect(type).toContain('Work');
    });

    test('7. GET /api/bibframe/book/{id}/instance → JSON-LD Instance node only', async ({ request }) => {
        const res = await request.get(`${BASE}/api/bibframe/book/${testBookId}/instance`);
        expect(res.status()).toBe(200);
        const json = await res.json();
        const node = json['@graph'] ? json['@graph'][0] : json;
        const type = Array.isArray(node['@type']) ? node['@type'].join(' ') : (node['@type'] ?? '');
        expect(type).toContain('Instance');
    });

    // ── Test 8: 404 handling ──────────────────────────────────────────────────

    test('8. GET /api/bibframe/book/9999999 → 404', async ({ request }) => {
        const res = await request.get(`${BASE}/api/bibframe/book/9999999`);
        expect(res.status()).toBe(404);
        const json = await res.json();
        expect(json).toHaveProperty('error', true);
    });

    // ── Tests 9-10: JSON-LD structure ─────────────────────────────────────────

    test('9. JSON-LD @context has bf prefix pointing to BIBFRAME ontology', async ({ request }) => {
        const res = await request.get(`${BASE}/api/bibframe/book/${testBookId}`);
        const json = await res.json();
        const ctx = json['@context'] ?? {};
        expect(ctx).toHaveProperty('bf');
        expect(ctx.bf).toContain('bibframe');
    });

    test('10. JSON-LD @graph contains bf:Work and bf:Instance nodes', async ({ request }) => {
        const res = await request.get(`${BASE}/api/bibframe/book/${testBookId}`);
        const json = await res.json();
        const graph = json['@graph'] ?? [];
        expect(Array.isArray(graph)).toBe(true);
        expect(graph.length).toBeGreaterThanOrEqual(2);

        const types = graph.map((/** @type {any} */ n) => {
            const t = n['@type'] ?? '';
            return Array.isArray(t) ? t.join(' ') : t;
        }).join(' ');
        expect(types).toContain('Work');
        expect(types).toContain('Instance');
    });
});

// @ts-check
/**
 * E2E — ResourceSync plugin tests (v0.7.1)
 *
 * Covers:
 *  1. Plugin registered in plugins table
 *  2. GET /.well-known/resourcesync → Source Description XML
 *  3. Source Description contains rs:md capability="description"
 *  4. Source Description links to capabilitylist
 *  5. GET /resync/capabilitylist.xml → Capability List XML
 *  6. Capability List has rs:md capability="capabilitylist"
 *  7. Capability List lists resourcelist and changelist
 *  8. GET /resync/resourcelist.xml → Resource List XML
 *  9. Resource List has rs:md capability="resourcelist"
 * 10. Resource List entries have <loc> pointing to /api/bibframe/book/...
 * 11. GET /resync/changelist.xml → Change List XML
 * 12. Change List has rs:md capability="changelist"
 * 13. GET /resync/changelist.xml?from=2020-01-01 → filtered change list
 *
 * Run: /tmp/run-e2e.sh tests/resource-sync.spec.js --config=tests/playwright.config.js --workers=1
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

test.describe.serial('ResourceSync plugin — v0.7.1 (13 tests)', () => {

    // ── Test 1: Plugin registration ──────────────────────────────────────────

    test('1. resource-sync plugin registered in plugins table', async () => {
        const name = dbQuery("SELECT name FROM plugins WHERE name = 'resource-sync'");
        expect(name).toBe('resource-sync');
    });

    // ── Tests 2-4: /.well-known/resourcesync (Source Description) ────────────

    test('2. GET /.well-known/resourcesync → 200 with XML Content-Type', async ({ request }) => {
        const res = await request.get(`${BASE}/.well-known/resourcesync`);
        expect(res.status()).toBe(200);
        const ct = res.headers()['content-type'] ?? '';
        expect(ct).toContain('application/xml');
    });

    test('3. Source Description has rs:md capability="description"', async ({ request }) => {
        const res = await request.get(`${BASE}/.well-known/resourcesync`);
        const body = await res.text();
        expect(body).toContain('capability="description"');
    });

    test('4. Source Description links to capabilitylist', async ({ request }) => {
        const res = await request.get(`${BASE}/.well-known/resourcesync`);
        const body = await res.text();
        expect(body).toContain('capabilitylist.xml');
        expect(body).toContain('capability="capabilitylist"');
    });

    // ── Tests 5-7: /resync/capabilitylist.xml ─────────────────────────────────

    test('5. GET /resync/capabilitylist.xml → 200 with XML Content-Type', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/capabilitylist.xml`);
        expect(res.status()).toBe(200);
        const ct = res.headers()['content-type'] ?? '';
        expect(ct).toContain('application/xml');
    });

    test('6. Capability List has rs:md capability="capabilitylist"', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/capabilitylist.xml`);
        const body = await res.text();
        expect(body).toContain('capability="capabilitylist"');
    });

    test('7. Capability List advertises resourcelist and changelist capabilities', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/capabilitylist.xml`);
        const body = await res.text();
        expect(body).toContain('capability="resourcelist"');
        expect(body).toContain('capability="changelist"');
        expect(body).toContain('resourcelist.xml');
        expect(body).toContain('changelist.xml');
    });

    // ── Tests 8-10: /resync/resourcelist.xml ─────────────────────────────────

    test('8. GET /resync/resourcelist.xml → 200 with XML Content-Type', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/resourcelist.xml`);
        expect(res.status()).toBe(200);
        const ct = res.headers()['content-type'] ?? '';
        expect(ct).toContain('application/xml');
    });

    test('9. Resource List has rs:md capability="resourcelist"', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/resourcelist.xml`);
        const body = await res.text();
        expect(body).toContain('capability="resourcelist"');
    });

    test('10. Resource List entries link to /api/bibframe/book/ endpoints', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/resourcelist.xml`);
        const body = await res.text();
        // If there are books in the DB, their URLs should appear.
        // If the catalog is empty, urlset is still valid XML.
        expect(body).toContain('<urlset');
        if (body.includes('<loc>')) {
            expect(body).toContain('/api/bibframe/book/');
        }
    });

    // ── Tests 11-13: /resync/changelist.xml ──────────────────────────────────

    test('11. GET /resync/changelist.xml → 200 with XML Content-Type', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/changelist.xml`);
        expect(res.status()).toBe(200);
        const ct = res.headers()['content-type'] ?? '';
        expect(ct).toContain('application/xml');
    });

    test('12. Change List has rs:md capability="changelist"', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/changelist.xml`);
        const body = await res.text();
        expect(body).toContain('capability="changelist"');
    });

    test('13. GET /resync/changelist.xml?from=2020-01-01 → valid filtered change list', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/changelist.xml?from=2020-01-01`);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('capability="changelist"');
        // from attribute must appear when filter is specified
        expect(body).toContain('from=');
    });
});

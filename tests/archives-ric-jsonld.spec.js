// @ts-check
/**
 * E2E — RiC-O JSON-LD endpoints (v0.7.7+, issue #122)
 *
 * Phase 1 of the RiC-CM roadmap exposes three public read-only endpoints
 * that emit Records in Contexts Ontology (ICA 2023) JSON-LD by
 * translating the existing ISAD(G)/ISAAR(CPF) tree into the RiC-CM
 * graph vocabulary. These tests verify the HTTP contract end-to-end —
 * pure unit coverage lives in tests/archives-ric-jsonld.unit.php.
 *
 * Scope:
 *  1.  Three RiC endpoints respond 200 with `application/ld+json` on the
 *      happy path (root collection + a unit + an agent).
 *  2.  Each response carries the standard headers (Link rel=canonical,
 *      Cache-Control, CORS) — no Vary: Accept-Language (CodeRabbit R3).
 *  3.  Each body declares `@context` with the RiC-O namespace.
 *  4.  Missing unit / agent returns 404 with an RFC 7807
 *      `application/problem+json` envelope (CodeRabbit R4).
 *  5.  Non-RiC exporters still work — i.e. the R4-1 shared-helper fix
 *      did not break dc.xml / ead.xml / mets.xml / manifest.json.
 *
 * Run: /tmp/run-e2e.sh tests/archives-ric-jsonld.spec.js \
 *        --config=tests/playwright.config.js --workers=1
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

/**
 * Build mysql CLI args using whichever connection mode the harness set.
 * Mirrors the helper in tests/sru-unimarcxml.spec.js.
 */
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

const RIC_NS = 'https://www.ica.org/standards/RiC/ontology#';

test.skip(!DB_USER || !DB_NAME, 'Missing E2E env (DB_*)');

test.describe.serial('RiC-O JSON-LD endpoints — v0.7.7', () => {
    /** @type {number} */
    let unitId = 0;
    /** @type {number} */
    let agentId = 0;
    /** @type {boolean} */
    let hasArchives = false;

    test.beforeAll(async () => {
        // Use the first archival_unit that has at least one linked
        // authority so the relation block is exercised. Both sides of
        // the join MUST filter `deleted_at IS NULL` — picking up a
        // soft-deleted authority would cause the agent test below to
        // get a 404 (the production endpoint correctly hides
        // soft-deleted rows) and the test would flake (CodeRabbit R6).
        const linked = dbQuery(
            "SELECT au.id, aua.authority_id " +
            "  FROM archival_unit_authority aua " +
            "  JOIN archival_units au ON au.id = aua.archival_unit_id AND au.deleted_at IS NULL " +
            "  JOIN authority_records ar ON ar.id = aua.authority_id AND ar.deleted_at IS NULL " +
            " LIMIT 1"
        );
        if (linked) {
            const [uid, aid] = linked.split(/\s+/);
            unitId  = parseInt(uid, 10) || 0;
            agentId = parseInt(aid, 10) || 0;
        }
        if (unitId === 0) {
            const fallback = dbQuery(
                "SELECT id FROM archival_units WHERE deleted_at IS NULL LIMIT 1"
            );
            unitId = parseInt(fallback, 10) || 0;
        }
        if (agentId === 0) {
            const anyAgent = dbQuery(
                "SELECT id FROM authority_records WHERE deleted_at IS NULL LIMIT 1"
            );
            agentId = parseInt(anyAgent, 10) || 0;
        }
        hasArchives = unitId > 0;
    });

    // ── 1. Happy path: 3 endpoints, content-type, @context ────────────

    test('collection.ric.json responds 200 with application/ld+json', async ({ request }) => {
        const res = await request.get(`${BASE}/archives/collection.ric.json`);
        expect(res.status()).toBe(200);
        expect(res.headers()['content-type'] ?? '').toContain('application/ld+json');
    });

    test('collection.ric.json body declares the RiC-O @context', async ({ request }) => {
        const res = await request.get(`${BASE}/archives/collection.ric.json`);
        const body = await res.json();
        expect(body['@context']?.ric).toBe(RIC_NS);
        expect(body['@type']).toBe('ric:RecordSet');
    });

    test('GET /archives/{id}/ric.json responds 200 for an existing unit', async ({ request }) => {
        test.skip(!hasArchives, 'No archival_units seeded');
        const res = await request.get(`${BASE}/archives/${unitId}/ric.json`);
        expect(res.status()).toBe(200);
        expect(res.headers()['content-type'] ?? '').toContain('application/ld+json');
    });

    test('unit ric.json body @type is ric:Record or ric:RecordSet', async ({ request }) => {
        test.skip(!hasArchives, 'No archival_units seeded');
        const res = await request.get(`${BASE}/archives/${unitId}/ric.json`);
        const body = await res.json();
        expect(body['@type']).toMatch(/^ric:(Record|RecordSet)$/);
        expect(body['@id']).toMatch(/\/archives\/\d+$/);
    });

    test('GET /archives/agents/{id}/ric.json responds 200 with an agent type', async ({ request }) => {
        test.skip(agentId === 0, 'No authority_records seeded');
        const res = await request.get(`${BASE}/archives/agents/${agentId}/ric.json`);
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body['@type']).toMatch(/^ric:(Person|CorporateBody|Family)$/);
        expect(body['@id']).toMatch(/\/archives\/agents\/\d+$/);
    });

    // ── 2. Headers — Cache-Control, Link rel=canonical, CORS, no Vary ─

    test('RiC response carries Link rel=canonical', async ({ request }) => {
        const res = await request.get(`${BASE}/archives/collection.ric.json`);
        const link = res.headers()['link'] ?? '';
        expect(link).toContain('rel="canonical"');
    });

    test('RiC response carries public, max-age=300 Cache-Control', async ({ request }) => {
        const res = await request.get(`${BASE}/archives/collection.ric.json`);
        const cc = res.headers()['cache-control'] ?? '';
        expect(cc).toContain('public');
        expect(cc).toContain('max-age=300');
    });

    test('RiC response carries CORS Access-Control-Allow-Origin: *', async ({ request }) => {
        const res = await request.get(`${BASE}/archives/collection.ric.json`);
        expect(res.headers()['access-control-allow-origin']).toBe('*');
    });

    test('RiC response does NOT carry Vary: Accept-Language (CodeRabbit R3)', async ({ request }) => {
        // The body is pinned to the installation locale via
        // I18n::getInstallationLocale() — URL-deterministic — so a
        // Vary: Accept-Language header would mis-fragment shared HTTP
        // caches per client language while every fragment stored
        // byte-identical content.
        const res = await request.get(`${BASE}/archives/collection.ric.json`);
        const vary = res.headers()['vary'] ?? '';
        expect(vary.toLowerCase()).not.toContain('accept-language');
    });

    // ── 3. RFC 7807 error envelope on missing resources (R4-2) ────────

    test('404 on missing unit returns application/problem+json', async ({ request }) => {
        const res = await request.get(`${BASE}/archives/999999999/ric.json`);
        expect(res.status()).toBe(404);
        expect(res.headers()['content-type'] ?? '').toContain('application/problem+json');
    });

    test('404 envelope contains the RFC 7807 canonical fields', async ({ request }) => {
        const res  = await request.get(`${BASE}/archives/999999999/ric.json`);
        const body = await res.json();
        expect(body.type).toBe('about:blank');
        expect(body.title).toBe('not_found');
        expect(body.status).toBe(404);
        expect(typeof body.detail).toBe('string');
        // Legacy fields preserved for back-compat with pre-R4 consumers.
        expect(body.error).toBe('not_found');
        expect(typeof body.message).toBe('string');
    });

    test('404 on missing agent also returns problem+json', async ({ request }) => {
        const res = await request.get(`${BASE}/archives/agents/999999999/ric.json`);
        expect(res.status()).toBe(404);
        expect(res.headers()['content-type'] ?? '').toContain('application/problem+json');
        const body = await res.json();
        expect(body.title).toBe('not_found');
    });

    // ── 4. Non-RiC exporters still work (R4-1 regression guard) ───────

    test('dc.xml on the same unit still returns 200 XML', async ({ request }) => {
        test.skip(!hasArchives, 'No archival_units seeded');
        const res = await request.get(`${BASE}/archives/${unitId}/dc.xml`);
        expect(res.status()).toBe(200);
        expect(res.headers()['content-type'] ?? '').toContain('xml');
    });

    test('ead.xml on the same unit still returns 200 XML', async ({ request }) => {
        test.skip(!hasArchives, 'No archival_units seeded');
        const res = await request.get(`${BASE}/archives/${unitId}/ead.xml`);
        expect(res.status()).toBe(200);
        expect(res.headers()['content-type'] ?? '').toContain('xml');
    });

    test('mets.xml on the same unit still returns 200 XML', async ({ request }) => {
        test.skip(!hasArchives, 'No archival_units seeded');
        const res = await request.get(`${BASE}/archives/${unitId}/mets.xml`);
        expect(res.status()).toBe(200);
        expect(res.headers()['content-type'] ?? '').toContain('xml');
    });

    test('IIIF manifest seeAlso advertises the RiC-O sibling', async ({ request }) => {
        test.skip(!hasArchives, 'No archival_units seeded');
        const res = await request.get(`${BASE}/archives/${unitId}/manifest.json`);
        expect(res.status()).toBe(200);
        const body = await res.json();
        const seeAlso = body.seeAlso ?? [];
        const ricEntry = seeAlso.find((s) => (s.id ?? '').endsWith('/ric.json'));
        expect(ricEntry).toBeTruthy();
        expect(ricEntry.format).toBe('application/ld+json');
    });
});

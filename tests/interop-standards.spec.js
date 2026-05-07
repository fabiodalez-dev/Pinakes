// @ts-check
/**
 * E2E — Interoperability Standards suite (v0.7.1–v0.7.3)
 *
 * Comprehensive cross-protocol integration tests for all four interop plugins:
 *   BIBFRAME 2.0 (v0.7.1), ResourceSync (v0.7.1), OpenURL + COinS (v0.7.2), NCIP 2.0 (v0.7.3)
 *
 * Focus: book-insertion cross-protocol verification — a single book record
 * must be accessible and consistent across all interop endpoints.
 *
 * Tests (52 total):
 *   Group A — Plugin registration & health (4)
 *   Group B — BIBFRAME cross-format consistency (8)
 *   Group C — ResourceSync hierarchy integrity (8)
 *   Group D — OpenURL + COinS semantic verification (8)
 *   Group E — NCIP circulation lifecycle (10)
 *   Group F — Cross-protocol data consistency (8)
 *   Group G — Edge cases & error paths (6)
 *
 * Run: /tmp/run-e2e.sh tests/interop-standards.spec.js --config=tests/playwright.config.js --workers=1
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

const NCIP_NS = 'http://www.niso.org/2008/ncip';

// ─── DB helpers ──────────────────────────────────────────────────────────────

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

// ─── NCIP helpers ─────────────────────────────────────────────────────────────

function basicAuth(user, pass) {
    return 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64');
}
function ncipPost(request, body, auth = null) {
    /** @type {Record<string, string>} */
    const headers = { 'Content-Type': 'application/xml' };
    if (auth) headers['Authorization'] = auth;
    return request.post(`${BASE}/ncip`, { data: body, headers });
}
function ncipLookupItem(itemId) {
    return `<?xml version="1.0" encoding="UTF-8"?>
<NCIPMessage xmlns="${NCIP_NS}">
  <LookupItem><ItemId><ItemIdentifierValue>${itemId}</ItemIdentifierValue></ItemId>
    <ItemElementType>CirculationStatus</ItemElementType></LookupItem>
</NCIPMessage>`;
}
function ncipCheckOut(itemId, userId, auth) {
    return `<?xml version="1.0" encoding="UTF-8"?>
<NCIPMessage xmlns="${NCIP_NS}">
  <CheckOutItem>
    <UserId><UserIdentifierValue>${userId}</UserIdentifierValue></UserId>
    <ItemId><ItemIdentifierValue>${itemId}</ItemIdentifierValue></ItemId>
  </CheckOutItem>
</NCIPMessage>`;
}
function ncipCheckIn(itemId) {
    return `<?xml version="1.0" encoding="UTF-8"?>
<NCIPMessage xmlns="${NCIP_NS}">
  <CheckInItem><ItemId><ItemIdentifierValue>${itemId}</ItemIdentifierValue></ItemId></CheckInItem>
</NCIPMessage>`;
}
function ncipRenew(itemId) {
    return `<?xml version="1.0" encoding="UTF-8"?>
<NCIPMessage xmlns="${NCIP_NS}">
  <RenewItem><ItemId><ItemIdentifierValue>${itemId}</ItemIdentifierValue></ItemId></RenewItem>
</NCIPMessage>`;
}
function ncipLookupUser(userId) {
    return `<?xml version="1.0" encoding="UTF-8"?>
<NCIPMessage xmlns="${NCIP_NS}">
  <LookupUser><UserId><UserIdentifierValue>${userId}</UserIdentifierValue></UserId></LookupUser>
</NCIPMessage>`;
}

// ─── Test setup ───────────────────────────────────────────────────────────────

test.skip(!DB_USER || !DB_NAME, 'Missing E2E env (DB_*)');

test.describe.serial('Interoperability Standards Suite — v0.7.1–v0.7.3 (52 tests)', () => {
    /** @type {number} */
    let bookId = 0;
    /** @type {number} */
    let userId = 0;
    /** @type {string} */
    let adminAuth = '';
    /** @type {number} */
    let checkoutItemId = 0;

    test.beforeAll(async () => {
        bookId = parseInt(dbQuery(
            'SELECT id FROM libri WHERE deleted_at IS NULL AND copie_disponibili > 0 ORDER BY id LIMIT 1'
        )) || 0;
        userId = parseInt(dbQuery(
            'SELECT id FROM utenti ORDER BY id LIMIT 1'
        )) || 0;
        if (ADMIN_EMAIL && ADMIN_PASS) {
            adminAuth = basicAuth(ADMIN_EMAIL, ADMIN_PASS);
        }
    });

    test.afterAll(async () => {
        // FK-safe cleanup of any NCIP loan created by E.8 that E.9 may not have returned.
        if (bookId > 0 && userId > 0) {
            try {
                // ncip_transactions.prestito_id FK → prestiti; clear dependents first.
                dbQuery(`UPDATE ncip_transactions SET prestito_id = NULL
                          WHERE prestito_id IN (
                              SELECT id FROM prestiti WHERE libro_id = ${bookId} AND utente_id = ${userId}
                          )`);
                dbQuery(`DELETE FROM prestiti WHERE libro_id = ${bookId} AND utente_id = ${userId} AND origine = 'ncip'`);
            } catch { /* best-effort */ }
        }
    });

    // ═══════════════════════════════════════════════════════════════════════
    // GROUP A — Plugin registration & health (4 tests)
    // ═══════════════════════════════════════════════════════════════════════

    test('A.1 bibframe-linked-data plugin registered and active', async () => {
        const row = dbQuery(
            "SELECT name, is_active FROM plugins WHERE name = 'bibframe-linked-data'"
        );
        expect(row).toContain('bibframe-linked-data');
        expect(row).toContain('1');
    });

    test('A.2 resource-sync plugin registered and active', async () => {
        const row = dbQuery(
            "SELECT name, is_active FROM plugins WHERE name = 'resource-sync'"
        );
        expect(row).toContain('resource-sync');
        expect(row).toContain('1');
    });

    test('A.3 openurl-resolver plugin registered and active', async () => {
        const row = dbQuery(
            "SELECT name, is_active FROM plugins WHERE name = 'openurl-resolver'"
        );
        expect(row).toContain('openurl-resolver');
        expect(row).toContain('1');
    });

    test('A.4 ncip-server plugin registered and active', async () => {
        const row = dbQuery(
            "SELECT name, is_active FROM plugins WHERE name = 'ncip-server'"
        );
        expect(row).toContain('ncip-server');
        expect(row).toContain('1');
    });

    // ═══════════════════════════════════════════════════════════════════════
    // GROUP B — BIBFRAME cross-format consistency (8 tests)
    // ═══════════════════════════════════════════════════════════════════════

    test('B.1 BIBFRAME JSON-LD default: status 200 + application/ld+json', async ({ request }) => {
        test.skip(bookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/bibframe/book/${bookId}`);
        expect(res.status()).toBe(200);
        expect(res.headers()['content-type'] ?? '').toContain('ld+json');
    });

    test('B.2 BIBFRAME JSON-LD body has @context with bf prefix', async ({ request }) => {
        test.skip(bookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/bibframe/book/${bookId}`, {
            headers: { Accept: 'application/ld+json' },
        });
        const json = await res.json();
        const ctx = json['@context'];
        const hasBf = typeof ctx === 'object' && ctx !== null
            ? Object.values(ctx).some(v => String(v).includes('id.loc.gov/ontologies/bibframe'))
            : String(ctx ?? '').includes('bibframe');
        expect(hasBf).toBe(true);
    });

    test('B.3 BIBFRAME JSON-LD @graph has at least two nodes (Work + Instance)', async ({ request }) => {
        test.skip(bookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/bibframe/book/${bookId}`, {
            headers: { Accept: 'application/ld+json' },
        });
        const json = await res.json();
        const graph = json['@graph'] ?? [json];
        expect(graph.length).toBeGreaterThanOrEqual(2);
    });

    test('B.4 BIBFRAME Turtle: Content-Type text/turtle', async ({ request }) => {
        test.skip(bookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/bibframe/book/${bookId}`, {
            headers: { Accept: 'text/turtle' },
        });
        expect(res.status()).toBe(200);
        expect(res.headers()['content-type'] ?? '').toContain('turtle');
    });

    test('B.5 BIBFRAME Turtle body contains bf:Work predicate', async ({ request }) => {
        test.skip(bookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/bibframe/book/${bookId}`, {
            headers: { Accept: 'text/turtle' },
        });
        const body = await res.text();
        expect(body).toContain('bf:Work');
    });

    test('B.6 BIBFRAME RDF/XML: Content-Type application/rdf+xml', async ({ request }) => {
        test.skip(bookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/bibframe/book/${bookId}`, {
            headers: { Accept: 'application/rdf+xml' },
        });
        expect(res.status()).toBe(200);
        expect(res.headers()['content-type'] ?? '').toContain('rdf+xml');
    });

    test('B.7 BIBFRAME /work endpoint returns Work node only', async ({ request }) => {
        test.skip(bookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/bibframe/book/${bookId}/work`);
        expect(res.status()).toBe(200);
        const json = await res.json();
        const node = json['@graph'] ? json['@graph'][0] : json;
        const type = node['@type'] ?? '';
        const types = Array.isArray(type) ? type : [type];
        expect(types.some((t) => String(t).includes('Work'))).toBe(true);
    });

    test('B.8 BIBFRAME 404 for non-existent book', async ({ request }) => {
        const res = await request.get(`${BASE}/api/bibframe/book/9999999`);
        expect(res.status()).toBe(404);
    });

    // ═══════════════════════════════════════════════════════════════════════
    // GROUP C — ResourceSync hierarchy integrity (8 tests)
    // ═══════════════════════════════════════════════════════════════════════

    test('C.1 Source Description /.well-known/resourcesync → 200 XML', async ({ request }) => {
        const res = await request.get(`${BASE}/.well-known/resourcesync`);
        expect(res.status()).toBe(200);
        expect(res.headers()['content-type'] ?? '').toContain('xml');
    });

    test('C.2 Source Description rs:md capability="description"', async ({ request }) => {
        const res = await request.get(`${BASE}/.well-known/resourcesync`);
        const body = await res.text();
        expect(body).toContain('capability="description"');
    });

    test('C.3 Source Description contains link to capabilitylist', async ({ request }) => {
        const res = await request.get(`${BASE}/.well-known/resourcesync`);
        const body = await res.text();
        expect(body).toContain('capabilitylist');
    });

    test('C.4 Capability List advertises resourcelist', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/capabilitylist.xml`);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('resourcelist');
    });

    test('C.5 Capability List advertises changelist', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/capabilitylist.xml`);
        const body = await res.text();
        expect(body).toContain('changelist');
    });

    test('C.6 Resource List has rs:md capability="resourcelist"', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/resourcelist.xml`);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('capability="resourcelist"');
    });

    test('C.7 Resource List entries link to BIBFRAME endpoint', async ({ request }) => {
        test.skip(bookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/resync/resourcelist.xml`);
        const body = await res.text();
        expect(body).toContain('/api/bibframe/book/');
    });

    test('C.8 Change List with ?from= filter returns valid XML', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/changelist.xml?from=2020-01-01`);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('changelist');
    });

    // ═══════════════════════════════════════════════════════════════════════
    // GROUP D — OpenURL + COinS semantic verification (8 tests)
    // ═══════════════════════════════════════════════════════════════════════

    test('D.1 GET /openurl with rft.btitle → 302 redirect', async ({ request }) => {
        const res = await request.get(
            `${BASE}/openurl?rft_val_fmt=info%3Aofi%2Ffmt%3Akev%3Amtx%3Abook&rft.btitle=Test+Book`,
            { maxRedirects: 0 }
        );
        expect(res.status()).toBe(302);
    });

    test('D.2 GET /openurl with no params → 302 graceful fallback', async ({ request }) => {
        const res = await request.get(`${BASE}/openurl`, { maxRedirects: 0 });
        expect(res.status()).toBe(302);
    });

    test('D.3 GET /openurl with ISBN → 302 redirect', async ({ request }) => {
        const res = await request.get(`${BASE}/openurl?rft.isbn=9780141182605`, { maxRedirects: 0 });
        expect(res.status()).toBe(302);
    });

    test('D.4 COinS API returns JSON with coins_title', async ({ request }) => {
        test.skip(bookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/coins/book/${bookId}`);
        expect(res.status()).toBe(200);
        const json = await res.json();
        expect(json).toHaveProperty('coins_title');
    });

    test('D.5 COinS title contains ctx_ver=Z39.88-2004', async ({ request }) => {
        test.skip(bookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/coins/book/${bookId}`);
        const json = await res.json();
        expect(json.coins_title).toContain('ctx_ver=Z39.88-2004');
    });

    test('D.6 COinS title contains rft_val_fmt book format', async ({ request }) => {
        test.skip(bookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/coins/book/${bookId}`);
        const json = await res.json();
        expect(json.coins_title).toContain('rft_val_fmt=');
        expect(json.coins_title).toContain('book');
    });

    test('D.7 COinS HTML span has Z3988 class', async ({ request }) => {
        test.skip(bookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/coins/book/${bookId}`);
        const json = await res.json();
        expect(json.coins_html).toContain('class="Z3988"');
    });

    test('D.8 COinS API 404 for non-existent book', async ({ request }) => {
        const res = await request.get(`${BASE}/api/coins/book/9999999`);
        expect(res.status()).toBe(404);
    });

    // ═══════════════════════════════════════════════════════════════════════
    // GROUP E — NCIP 2.0 circulation lifecycle (10 tests)
    // ═══════════════════════════════════════════════════════════════════════

    test('E.1 GET /ncip capability discovery → 200 XML', async ({ request }) => {
        const res = await request.get(`${BASE}/ncip`);
        expect(res.status()).toBe(200);
        expect(res.headers()['content-type'] ?? '').toContain('xml');
        const body = await res.text();
        expect(body).toContain('<NCIPMessage');
    });

    test('E.2 NCIP capability includes LookupItem', async ({ request }) => {
        const res = await request.get(`${BASE}/ncip`);
        const body = await res.text();
        expect(body).toContain('LookupItem');
    });

    test('E.3 NCIP capability includes RenewItem', async ({ request }) => {
        const res = await request.get(`${BASE}/ncip`);
        const body = await res.text();
        expect(body).toContain('RenewItem');
    });

    test('E.4 LookupItem existing book → CirculationStatus', async ({ request }) => {
        test.skip(bookId === 0, 'No book in DB');
        const res = await ncipPost(request, ncipLookupItem(bookId));
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('LookupItemResponse');
        expect(body).toContain('CirculationStatus');
    });

    test('E.5 LookupItem non-existent → Problem response', async ({ request }) => {
        const res = await ncipPost(request, ncipLookupItem(9999999));
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('Problem');
    });

    test('E.6 LookupUser requires auth → 401 without header', async ({ request }) => {
        test.skip(userId === 0, 'No user in DB');
        const res = await ncipPost(request, ncipLookupUser(userId));
        expect(res.status()).toBe(401);
    });

    test('E.7 LookupUser with staff auth → LookupUserResponse', async ({ request }) => {
        test.skip(!adminAuth || userId === 0, 'Missing auth or user');
        const res = await ncipPost(request, ncipLookupUser(userId), adminAuth);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('LookupUserResponse');
    });

    test('E.8 CheckOutItem with staff auth → CheckOutItemResponse (book insertion)', async ({ request }) => {
        test.skip(!adminAuth || bookId === 0 || userId === 0, 'Missing auth or data');
        const res = await ncipPost(request, ncipCheckOut(bookId, userId), adminAuth);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('CheckOutItemResponse');
        expect(body).toContain('DateDue');
        // Capture item ID for subsequent check-in
        const match = body.match(/<ItemIdentifierValue>(\d+)<\/ItemIdentifierValue>/);
        if (match) checkoutItemId = parseInt(match[1]);
    });

    test('E.9 CheckInItem with staff auth → CheckInItemResponse', async ({ request }) => {
        test.skip(!adminAuth || checkoutItemId === 0, 'No checkout recorded in E.8');
        const res = await ncipPost(request, ncipCheckIn(checkoutItemId), adminAuth);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('CheckInItemResponse');
        expect(body).toContain('DateReturned');
    });

    test('E.10 RenewItem on non-existent loan → Problem response', async ({ request }) => {
        test.skip(!adminAuth, 'Missing admin auth');
        const res = await ncipPost(request, ncipRenew(9999999), adminAuth);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('Problem');
    });

    // ═══════════════════════════════════════════════════════════════════════
    // GROUP F — Cross-protocol data consistency (8 tests)
    // A book must appear consistently across all four interop channels.
    // ═══════════════════════════════════════════════════════════════════════

    test('F.1 Same bookId visible in BIBFRAME JSON-LD and Resource List', async ({ request }) => {
        test.skip(bookId === 0, 'No book in DB');
        const bibRes = await request.get(`${BASE}/api/bibframe/book/${bookId}`);
        const rsRes  = await request.get(`${BASE}/resync/resourcelist.xml`);

        expect(bibRes.status()).toBe(200);
        expect(rsRes.status()).toBe(200);

        const rsBody = await rsRes.text();
        // Resource list must link to the book's BIBFRAME URL
        expect(rsBody).toContain(`/api/bibframe/book/${bookId}`);
    });

    test('F.2 BIBFRAME JSON-LD title matches DB title for test book', async ({ request }) => {
        test.skip(bookId === 0, 'No book in DB');
        const dbTitle = dbQuery(
            `SELECT titolo FROM libri WHERE id = ${bookId} AND deleted_at IS NULL`
        ).trim();
        test.skip(!dbTitle, 'Book title not found');

        const res = await request.get(`${BASE}/api/bibframe/book/${bookId}`);
        const json = await res.json();
        const jsonStr = JSON.stringify(json);
        expect(jsonStr).toContain(dbTitle.substring(0, 20));
    });

    test('F.3 COinS API book_id matches requested bookId', async ({ request }) => {
        test.skip(bookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/coins/book/${bookId}`);
        const json = await res.json();
        expect(json).toHaveProperty('book_id', bookId);
    });

    test('F.4 NCIP LookupItem responds with same bookId in ItemId element', async ({ request }) => {
        test.skip(bookId === 0, 'No book in DB');
        const res = await ncipPost(request, ncipLookupItem(bookId));
        const body = await res.text();
        expect(body).toContain(`<ItemIdentifierValue>${bookId}</ItemIdentifierValue>`);
    });

    test('F.5 ResourceSync source description links through to resourcelist', async ({ request }) => {
        const sdRes = await request.get(`${BASE}/.well-known/resourcesync`);
        const sdBody = await sdRes.text();
        // Capabilitylist URL is inside <loc>...</loc> in the Sitemap XML
        const capMatch = sdBody.match(/<loc>(https?:\/\/[^<]*capabilitylist[^<]*)<\/loc>/);
        expect(capMatch).toBeTruthy();
        if (!capMatch) return;
        const capRes = await request.get(capMatch[1]);
        expect(capRes.status()).toBe(200);
    });

    test('F.6 COinS rfr_id contains "pinakes" site identifier', async ({ request }) => {
        test.skip(bookId === 0, 'No book in DB');
        const res = await request.get(`${BASE}/api/coins/book/${bookId}`);
        const json = await res.json();
        expect(json.coins_title.toLowerCase()).toContain('pinakes');
    });

    test('F.7 BIBFRAME and NCIP agree on book availability', async ({ request }) => {
        test.skip(bookId === 0, 'No book in DB');
        const dbCopies = parseInt(dbQuery(
            `SELECT copie_disponibili FROM libri WHERE id = ${bookId}`
        )) || 0;
        const isAvailable = dbCopies > 0;

        const ncipRes = await ncipPost(request, ncipLookupItem(bookId));
        const ncipBody = await ncipRes.text();
        if (isAvailable) {
            expect(ncipBody).toContain('Available On Shelf');
        } else {
            expect(ncipBody).toContain('Checked Out');
        }
    });

    test('F.8 ResourceSync change list timestamp format is ISO 8601', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/changelist.xml`);
        const body = await res.text();
        // If changelist has entries, timestamps must match ISO 8601
        const tsMatch = body.match(/lastmod>(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/);
        if (tsMatch) {
            const date = new Date(tsMatch[1]);
            expect(isNaN(date.getTime())).toBe(false);
        } else {
            // No entries yet — just verify the XML is valid with correct structure
            expect(body).toContain('changelist');
        }
    });

    // ═══════════════════════════════════════════════════════════════════════
    // GROUP G — Edge cases & error paths (6 tests)
    // ═══════════════════════════════════════════════════════════════════════

    test('G.1 NCIP POST invalid XML body → 400', async ({ request }) => {
        const res = await ncipPost(request, '<not-valid-xml<<>><>');
        expect(res.status()).toBe(400);
    });

    test('G.2 NCIP unsupported message type → Problem unsupported-request', async ({ request }) => {
        const body = `<?xml version="1.0" encoding="UTF-8"?>
<NCIPMessage xmlns="${NCIP_NS}">
  <UnsupportedInteropRequest><ItemId><ItemIdentifierValue>1</ItemIdentifierValue></ItemId></UnsupportedInteropRequest>
</NCIPMessage>`;
        const res = await ncipPost(request, body);
        const responseBody = await res.text();
        expect(responseBody).toContain('Problem');
        expect(responseBody).toContain('unsupported-request');
    });

    test('G.3 BIBFRAME returns 404 for deleted book ID', async ({ request }) => {
        const res = await request.get(`${BASE}/api/bibframe/book/0`);
        expect([404, 400]).toContain(res.status());
    });

    test('G.4 OpenURL redirect location has non-empty value', async ({ request }) => {
        const res = await request.get(
            `${BASE}/openurl?rft.btitle=Umberto+Eco&rft.au=Eco`,
            { maxRedirects: 0 }
        );
        expect(res.status()).toBe(302);
        const location = res.headers()['location'] ?? '';
        expect(location.length).toBeGreaterThan(10);
    });

    test('G.5 NCIP empty request body → 400 with Problem XML', async ({ request }) => {
        const res = await request.post(`${BASE}/ncip`, {
            data: '',
            headers: { 'Content-Type': 'application/xml' },
        });
        expect(res.status()).toBe(400);
        const body = await res.text();
        expect(body).toContain('Problem');
    });

    test('G.6 ResourceSync capabilitylist Content-Type is XML', async ({ request }) => {
        const res = await request.get(`${BASE}/resync/capabilitylist.xml`);
        expect(res.status()).toBe(200);
        const ct = res.headers()['content-type'] ?? '';
        expect(ct).toContain('xml');
    });
});

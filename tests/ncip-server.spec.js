// @ts-check
/**
 * E2E — NCIP 2.0 Server plugin tests (v0.7.4)
 *
 * Covers:
 *  1. Plugin registered in plugins table
 *  2. GET /ncip → 200 XML capability discovery
 *  3. GET /ncip contains LookupItem in capability profile
 *  4. GET /ncip contains CheckOutItem in capability profile
 *  5. POST /ncip LookupItem → 200 with ItemId and CirculationStatus
 *  6. POST /ncip LookupItem non-existent item → Problem response
 *  7. POST /ncip LookupUser (authenticated) → 200 with UserId
 *  8. POST /ncip LookupUser unauthenticated → 401
 *  9. POST /ncip CheckOutItem (staff auth) → 200 CheckOutItemResponse
 * 10. POST /ncip CheckInItem (staff auth) → 200 CheckInItemResponse
 * 11. POST /ncip RenewItem without active loan → Problem response
 * 12. POST /ncip unsupported message type → Problem Unsupported
 * 13. POST /ncip invalid XML → 400 error
 * 14. Schema: ncip_partners table exists
 * 15. Schema: ncip_transactions table exists
 * 16. Schema: prestiti.ncip_request_id column exists
 * 17. Schema: prestiti.origine ENUM includes 'ncip'
 * 18. POST /ncip RequestItem (staff auth) → 200 RequestItemResponse
 * 19. RequestItem creates prestito with origine='ncip' in DB
 * 20. POST /ncip CancelRequestItem (staff auth) → 200 CancelRequestItemResponse
 *
 * Run: /tmp/run-e2e.sh tests/ncip-server.spec.js --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE         = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const DB_USER      = process.env.E2E_DB_USER     || '';
const DB_PASS      = process.env.E2E_DB_PASS     || '';
const DB_NAME      = process.env.E2E_DB_NAME     || '';
const DB_SOCKET    = process.env.E2E_DB_SOCKET   || '';
const DB_HOST      = process.env.E2E_DB_HOST     || '';
const DB_PORT      = process.env.E2E_DB_PORT     || '';
const ADMIN_EMAIL  = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS   = process.env.E2E_ADMIN_PASS  || '';

const NCIP_NS = 'http://www.niso.org/2008/ncip';

function mysqlArgs(sql = '', batch = false) {
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

function tableExists(tableName) {
    const count = dbQuery(
        `SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '${tableName.replace(/'/g, "''")}'`
    );
    return parseInt(count, 10) === 1;
}

function getNcipPluginState() {
    const row = dbQuery(
        "SELECT p.id, p.is_active, COUNT(ph.id) AS hooks " +
        "FROM plugins p " +
        "LEFT JOIN plugin_hooks ph ON ph.plugin_id = p.id " +
        "AND ph.hook_name = 'app.routes.register' " +
        "AND ph.callback_method = 'registerRoutes' " +
        "AND ph.is_active = 1 " +
        "WHERE p.name = 'ncip-server' " +
        "GROUP BY p.id, p.is_active " +
        "LIMIT 1"
    );
    if (!row) return { id: 0, active: false, hooks: 0 };
    const parts = row.split('\t');
    return {
        id: parseInt(parts[0], 10) || 0,
        active: parts[1] === '1',
        hooks: parseInt(parts[2], 10) || 0,
    };
}

async function pluginApiCall(page, action, pluginId) {
    return page.evaluate(async ([act, pid]) => {
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        const token = csrfInput ? (/** @type {HTMLInputElement} */ (csrfInput)).value : '';
        const formData = new FormData();
        formData.append('csrf_token', token);
        const res = await fetch(
            `${window.location.origin}${window.BASE_PATH || ''}/admin/plugins/${pid}/${act}`,
            { method: 'POST', body: formData }
        );
        return res.json();
    }, [action, pluginId]);
}

async function ensureNcipPlugin(browser) {
    let plugin = getNcipPluginState();
    if (plugin.id === 0) {
        throw new Error('NCIP server plugin is not registered in the plugins table');
    }

    const hasSchema = () => tableExists('ncip_partners') && tableExists('ncip_transactions');
    if (plugin.active && plugin.hooks > 0 && hasSchema()) {
        return;
    }

    const context = await browser.newContext();
    const page = await context.newPage();
    try {
        await page.goto(`${BASE}/login`);
        await page.fill('input[name="email"]', ADMIN_EMAIL);
        await page.fill('input[name="password"]', ADMIN_PASS);
        await Promise.all([
            page.waitForURL(/\/admin\//, { timeout: 15_000 }),
            page.click('button[type="submit"]'),
        ]);
        await page.goto(`${BASE}/admin/plugins`);

        if (plugin.active) {
            const deactivate = await pluginApiCall(page, 'deactivate', plugin.id);
            if (!deactivate.success) {
                throw new Error(`NCIP plugin deactivation failed: ${deactivate.message || deactivate.error || ''}`);
            }
        }

        const activate = await pluginApiCall(page, 'activate', plugin.id);
        if (!activate.success) {
            throw new Error(`NCIP plugin activation failed: ${activate.message || activate.error || ''}`);
        }
    } finally {
        await context.close();
    }

    plugin = getNcipPluginState();
    if (!plugin.active || plugin.hooks === 0 || !hasSchema()) {
        throw new Error('NCIP plugin activation did not create schema and route hooks');
    }
}

/** Build NCIP LookupItem XML body for a given item barcode/id. */
function lookupItemXml(itemId) {
    return `<?xml version="1.0" encoding="UTF-8"?>
<NCIPMessage xmlns="${NCIP_NS}"
             ncip:version="http://www.niso.org/schemas/ncip/v2_02/ncip_v2_02.xsd"
             xmlns:ncip="${NCIP_NS}">
  <LookupItem>
    <ItemId>
      <ItemIdentifierValue>${itemId}</ItemIdentifierValue>
    </ItemId>
    <ItemElementType>CirculationStatus</ItemElementType>
  </LookupItem>
</NCIPMessage>`;
}

/** Build NCIP LookupUser XML body. */
function lookupUserXml(userId) {
    return `<?xml version="1.0" encoding="UTF-8"?>
<NCIPMessage xmlns="${NCIP_NS}"
             ncip:version="http://www.niso.org/schemas/ncip/v2_02/ncip_v2_02.xsd"
             xmlns:ncip="${NCIP_NS}">
  <LookupUser>
    <UserId>
      <UserIdentifierValue>${userId}</UserIdentifierValue>
    </UserId>
  </LookupUser>
</NCIPMessage>`;
}

/** Build NCIP CheckOutItem XML body. */
function checkOutItemXml(itemId, userId) {
    return `<?xml version="1.0" encoding="UTF-8"?>
<NCIPMessage xmlns="${NCIP_NS}"
             ncip:version="http://www.niso.org/schemas/ncip/v2_02/ncip_v2_02.xsd"
             xmlns:ncip="${NCIP_NS}">
  <CheckOutItem>
    <UserId>
      <UserIdentifierValue>${userId}</UserIdentifierValue>
    </UserId>
    <ItemId>
      <ItemIdentifierValue>${itemId}</ItemIdentifierValue>
    </ItemId>
  </CheckOutItem>
</NCIPMessage>`;
}

/** Build NCIP CheckInItem XML body. */
function checkInItemXml(loanId) {
    return `<?xml version="1.0" encoding="UTF-8"?>
<NCIPMessage xmlns="${NCIP_NS}"
             ncip:version="http://www.niso.org/schemas/ncip/v2_02/ncip_v2_02.xsd"
             xmlns:ncip="${NCIP_NS}">
  <CheckInItem>
    <ItemId>
      <ItemIdentifierValue>${loanId}</ItemIdentifierValue>
    </ItemId>
  </CheckInItem>
</NCIPMessage>`;
}

/** Build NCIP RenewItem XML body. */
function renewItemXml(loanId) {
    return `<?xml version="1.0" encoding="UTF-8"?>
<NCIPMessage xmlns="${NCIP_NS}"
             ncip:version="http://www.niso.org/schemas/ncip/v2_02/ncip_v2_02.xsd"
             xmlns:ncip="${NCIP_NS}">
  <RenewItem>
    <ItemId>
      <ItemIdentifierValue>${loanId}</ItemIdentifierValue>
    </ItemId>
  </RenewItem>
</NCIPMessage>`;
}

function basicAuth(user, pass) {
    return 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64');
}

function ncipPost(request, body, auth = null) {
    /** @type {Record<string, string>} */
    const headers = { 'Content-Type': 'application/xml' };
    if (auth) headers['Authorization'] = auth;
    return request.post(`${BASE}/ncip`, { data: body, headers });
}

test.skip(
    !DB_USER || !DB_NAME || !ADMIN_EMAIL || !ADMIN_PASS,
    'Missing E2E env (DB_* and admin credentials)'
);

test.describe.serial('NCIP 2.0 Server plugin — v0.7.4 (20 tests)', () => {
    /** @type {number} */
    let testBookId = 0;
    /** @type {number} */
    let testUserId = 0;
    /** @type {number} */
    let createdLoanId = 0;
    /** Track specific prestiti IDs created during these tests for targeted cleanup */
    let createdPrestitiIds = /** @type {number[]} */ ([]);

    test.beforeAll(async ({ browser }) => {
        await ensureNcipPlugin(browser);

        // Find a book with available copies.
        const bookRow = dbQuery(
            "SELECT id FROM libri WHERE deleted_at IS NULL AND copie_disponibili > 0 ORDER BY id LIMIT 1"
        );
        testBookId = parseInt(bookRow) || 0;

        // Find any non-admin user; fall back to any user if none found.
        const userRow = dbQuery(
            "SELECT id FROM utenti ORDER BY id LIMIT 1"
        );
        testUserId = parseInt(userRow) || 0;
    });

    // ── Test 1: Plugin registration ──────────────────────────────────────────

    test('1. ncip-server plugin registered in plugins table', async () => {
        const name = dbQuery("SELECT name FROM plugins WHERE name = 'ncip-server'");
        expect(name).toBe('ncip-server');
    });

    // ── Tests 2-4: GET /ncip capability discovery ─────────────────────────────

    test('2. GET /ncip → 200 XML with NCIPMessage root', async ({ request }) => {
        const res = await request.get(`${BASE}/ncip`);
        expect(res.status()).toBe(200);
        const ct = res.headers()['content-type'] ?? '';
        expect(ct).toContain('xml');
        const body = await res.text();
        expect(body).toContain('<NCIPMessage');
    });

    test('3. GET /ncip capability contains LookupItem', async ({ request }) => {
        const res = await request.get(`${BASE}/ncip`);
        const body = await res.text();
        expect(body).toContain('LookupItem');
    });

    test('4. GET /ncip capability contains CheckOutItem', async ({ request }) => {
        const res = await request.get(`${BASE}/ncip`);
        const body = await res.text();
        expect(body).toContain('CheckOutItem');
    });

    // ── Tests 5-6: LookupItem ─────────────────────────────────────────────────

    test('5. POST LookupItem existing book → LookupItemResponse with CirculationStatus', async ({ request }) => {
        test.skip(testBookId === 0, 'No available book in DB');
        const res = await ncipPost(request, lookupItemXml(testBookId));
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('LookupItemResponse');
        expect(body).toContain('CirculationStatus');
        expect(body).toContain(`<ItemIdentifierValue>${testBookId}</ItemIdentifierValue>`);
    });

    test('6. POST LookupItem non-existent item → Problem response', async ({ request }) => {
        const res = await ncipPost(request, lookupItemXml(9999999));
        // NCIP errors are still 200 HTTP with a Problem element
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('Problem');
    });

    // ── Tests 7-8: LookupUser ─────────────────────────────────────────────────

    test('7. POST LookupUser with staff auth → LookupUserResponse', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testUserId === 0, 'No regular user in DB');
        const auth = basicAuth(ADMIN_EMAIL, ADMIN_PASS);
        const res = await ncipPost(request, lookupUserXml(testUserId), auth);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('LookupUserResponse');
        expect(body).toContain('UserId');
    });

    test('8. POST LookupUser without auth → 401', async ({ request }) => {
        test.skip(testUserId === 0, 'No regular user in DB');
        const res = await ncipPost(request, lookupUserXml(testUserId));
        expect(res.status()).toBe(401);
    });

    // ── Tests 9-10: CheckOut / CheckIn lifecycle ───────────────────────────────

    test('9. POST CheckOutItem with staff auth → CheckOutItemResponse', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testBookId === 0 || testUserId === 0, 'Missing test data');
        const auth = basicAuth(ADMIN_EMAIL, ADMIN_PASS);
        const res = await ncipPost(request, checkOutItemXml(testBookId, testUserId), auth);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('CheckOutItemResponse');

        // Extract loan ID from ItemIdentifierValue for check-in test.
        const match = body.match(/<ItemIdentifierValue>(\d+)<\/ItemIdentifierValue>/);
        if (match) {
            createdLoanId = parseInt(match[1]);
            createdPrestitiIds.push(createdLoanId);
        }
    });

    test('10. POST CheckInItem with staff auth → CheckInItemResponse', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(createdLoanId === 0, 'No loan created in test 9');
        const auth = basicAuth(ADMIN_EMAIL, ADMIN_PASS);
        const res = await ncipPost(request, checkInItemXml(createdLoanId), auth);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('CheckInItemResponse');
    });

    // ── Test 11: RenewItem on non-existent loan ────────────────────────────────

    test('11. POST RenewItem non-existent loan → Problem response', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        const auth = basicAuth(ADMIN_EMAIL, ADMIN_PASS);
        const res = await ncipPost(request, renewItemXml(9999999), auth);
        expect(res.status()).toBe(200);
        const body = await res.text();
        expect(body).toContain('Problem');
    });

    // ── Test 12: Unsupported message ──────────────────────────────────────────

    test('12. POST unsupported NCIP message type → Problem Unsupported', async ({ request }) => {
        const body = `<?xml version="1.0" encoding="UTF-8"?>
<NCIPMessage xmlns="${NCIP_NS}">
  <DeleteItem>
    <ItemId><ItemIdentifierValue>1</ItemIdentifierValue></ItemId>
  </DeleteItem>
</NCIPMessage>`;
        const res = await ncipPost(request, body);
        expect(res.status()).toBe(200);
        const responseBody = await res.text();
        expect(responseBody).toContain('Problem');
        expect(responseBody).toContain('unsupported-request');
    });

    // ── Test 13: Invalid XML ───────────────────────────────────────────────────

    test('13. POST invalid XML → 400', async ({ request }) => {
        const res = await ncipPost(request, '<not valid xml>>><<<');
        expect(res.status()).toBe(400);
    });

    // ── Tests 14-17: Schema (ncip_partners, ncip_transactions, prestiti cols) ──

    test('14. ncip_partners table exists', async () => {
        const result = dbQuery(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ncip_partners'"
        );
        expect(parseInt(result)).toBe(1);
    });

    test('15. ncip_transactions table exists', async () => {
        const result = dbQuery(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ncip_transactions'"
        );
        expect(parseInt(result)).toBe(1);
    });

    test('16. prestiti.ncip_request_id column exists', async () => {
        const result = dbQuery(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prestiti' AND COLUMN_NAME = 'ncip_request_id'"
        );
        expect(parseInt(result)).toBe(1);
    });

    test("17. prestiti.origine ENUM includes 'ncip'", async () => {
        const colType = dbQuery(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prestiti' AND COLUMN_NAME = 'origine'"
        );
        expect(colType).toContain("'ncip'");
    });

    // ── Tests 18-20: RequestItem / CancelRequestItem ──────────────────────────

    /** @type {number} */
    let ncipLoanBookId = 0;
    /** @type {number} */
    let copieBaseline = 0;
    /** @type {number} */
    let ncipLoanCountBaseline = 0;

    test('18. POST RequestItem (staff auth) → RequestItemResponse', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(testBookId === 0 || testUserId === 0, 'Missing test data');

        ncipLoanBookId = testBookId;
        copieBaseline = parseInt(dbQuery(`SELECT copie_disponibili FROM libri WHERE id = ${testBookId} LIMIT 1`)) || 0;
        ncipLoanCountBaseline = parseInt(dbQuery(`SELECT COUNT(*) FROM prestiti WHERE libro_id = ${testBookId} AND origine = 'ncip'`)) || 0;
        const auth = basicAuth(ADMIN_EMAIL, ADMIN_PASS);
        const body = `<?xml version="1.0" encoding="UTF-8"?>
<NCIPMessage xmlns="${NCIP_NS}">
  <RequestItem>
    <UserId><UserIdentifierValue>${testUserId}</UserIdentifierValue></UserId>
    <ItemId><ItemIdentifierValue>${testBookId}</ItemIdentifierValue></ItemId>
    <RequestType><Scheme>http://www.niso.org/ncip/v2_02/schemes/requesttype/</Scheme><Value>Hold</Value></RequestType>
  </RequestItem>
</NCIPMessage>`;
        const res = await ncipPost(request, body, auth);
        expect(res.status()).toBe(200);
        const text = await res.text();
        expect(text).toContain('RequestItemResponse');
        expect(text).toContain(`<ItemIdentifierValue>${testBookId}</ItemIdentifierValue>`);

        // Capture the specific prestiti ID created by this RequestItem for targeted cleanup
        const newIds = dbQuery(
            `SELECT id FROM prestiti WHERE libro_id = ${testBookId} AND origine = 'ncip' ORDER BY id DESC LIMIT 1`
        );
        const newId = parseInt(newIds);
        if (newId > 0) createdPrestitiIds.push(newId);
    });

    test('19. RequestItem creates prestito with origine=ncip in DB', async () => {
        test.skip(ncipLoanBookId === 0, 'Test 18 skipped or failed');
        const count = parseInt(dbQuery(
            `SELECT COUNT(*) FROM prestiti WHERE libro_id = ${ncipLoanBookId} AND origine = 'ncip'`
        )) || 0;
        expect(count).toBeGreaterThan(ncipLoanCountBaseline);
    });

    test('20. POST CancelRequestItem (staff auth) → CancelRequestItemResponse', async ({ request }) => {
        test.skip(!ADMIN_EMAIL || !ADMIN_PASS, 'Missing admin credentials');
        test.skip(ncipLoanBookId === 0, 'Test 18 skipped or failed');

        const auth = basicAuth(ADMIN_EMAIL, ADMIN_PASS);
        const body = `<?xml version="1.0" encoding="UTF-8"?>
<NCIPMessage xmlns="${NCIP_NS}">
  <CancelRequestItem>
    <UserId><UserIdentifierValue>${testUserId}</UserIdentifierValue></UserId>
    <ItemId><ItemIdentifierValue>${ncipLoanBookId}</ItemIdentifierValue></ItemId>
  </CancelRequestItem>
</NCIPMessage>`;
        const res = await ncipPost(request, body, auth);
        expect(res.status()).toBe(200);
        const text = await res.text();
        expect(text).toContain('CancelRequestItemResponse');
        expect(text).toContain(`<ItemIdentifierValue>${ncipLoanBookId}</ItemIdentifierValue>`);
    });

    test.afterAll(async () => {
        // Clean up only the specific prestiti IDs created by these tests.
        // This avoids accidentally deleting pre-existing NCIP loans for the same book
        // (e.g. from other test runs that were not fully cleaned up).
        if (createdPrestitiIds.length > 0) {
            try {
                const idList = createdPrestitiIds.join(',');
                // FK-safe: child table first (ncip_transactions → prestiti), then parent
                dbQuery(`DELETE FROM ncip_transactions WHERE prestito_id IN (${idList})`);
                dbQuery(`DELETE FROM prestiti WHERE id IN (${idList})`);
            } catch { /* best-effort */ }
        }
    });
});

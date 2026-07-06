// @ts-check
/**
 * E2E — Mobile API contract fixes (mobile-api + book-club bridge)
 *
 * Verifies the server-side fixes found by the cross-review with the Android
 * app, using Playwright's request context (no browser except plugin
 * activation, mirroring mobile-api.spec.js):
 *
 *   Gate      : mobile_api.enabled=0 → POST /auth/login|register|forgot-password
 *               all answer 403 app_access_disabled; enabled=1 → login issues a token
 *   Lazy close: a poll whose closes_at is in the past is reported closed by
 *               GET /api/v1/bookclub/clubs/{slug}, with the winner advanced
 *               in the workflow (voting → selected) and the loser reset
 *   ISO dates : review created_at/updated_at (GET /me/reviews) and device
 *               created_at/last_used_at (GET /me/devices) match
 *               ^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$
 *   Discovery : GET /api/v1/bookclub/health exposes app_access_enabled
 *               coherently with the mobile_api.enabled setting
 *
 * Run: /tmp/run-e2e.sh tests/mobile-api-bookclub-fixes.spec.js --config=tests/playwright.config.js --workers=1
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

// ─── Env vars (set by /tmp/run-e2e.sh) ────────────────────────────────────────

const BASE         = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const DB_USER      = process.env.E2E_DB_USER     || '';
const DB_PASS      = process.env.E2E_DB_PASS     || '';
const DB_NAME      = process.env.E2E_DB_NAME     || '';
const DB_SOCKET    = process.env.E2E_DB_SOCKET   || '';
const DB_HOST      = process.env.E2E_DB_HOST     || '';
const DB_PORT      = process.env.E2E_DB_PORT     || '';
const ADMIN_EMAIL  = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS   = process.env.E2E_ADMIN_PASS  || '';

const API = `${BASE}/api/v1`;
const ISO_UTC = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;

test.skip(
    !DB_USER || !DB_NAME || !ADMIN_EMAIL || !ADMIN_PASS,
    'Missing E2E env: DB_* and ADMIN_EMAIL/ADMIN_PASS are required'
);

// ─── DB helpers (same shape as mobile-api.spec.js) ────────────────────────────

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
        encoding: 'utf-8', timeout: 15000, env: MYSQL_ENV(),
    }).trim();
}

function dbExec(sql) {
    execFileSync('mysql', mysqlArgs(sql, false), {
        encoding: 'utf-8', timeout: 15000, env: MYSQL_ENV(),
    });
}

function tableExists(name) {
    const n = parseInt(dbQuery(
        `SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${name.replace(/'/g, "''")}'`
    ), 10);
    return n === 1;
}

function setMobileApiEnabled(value) {
    dbExec(
        "INSERT INTO system_settings (category, setting_key, setting_value) " +
        `VALUES ('mobile_api', 'enabled', '${value}') ` +
        `ON DUPLICATE KEY UPDATE setting_value = '${value}'`
    );
}

// ─── Plugin activation (mirrors mobile-api.spec.js / ncip-server.spec.js) ─────

function getPluginState(name, hookMethod) {
    const row = dbQuery(
        "SELECT p.id, p.is_active, COUNT(ph.id) AS hooks " +
        "FROM plugins p " +
        "LEFT JOIN plugin_hooks ph ON ph.plugin_id = p.id " +
        "  AND ph.hook_name = 'app.routes.register' " +
        `  AND ph.callback_method = '${hookMethod}' ` +
        "  AND ph.is_active = 1 " +
        `WHERE p.name = '${name}' ` +
        "GROUP BY p.id, p.is_active " +
        "LIMIT 1"
    );
    if (!row) return { id: 0, active: false, hooks: 0 };
    const parts = row.split('\t');
    return {
        id:     parseInt(parts[0], 10) || 0,
        active: parts[1] === '1',
        hooks:  parseInt(parts[2], 10) || 0,
    };
}

async function pluginApiCall(page, action, pluginId) {
    return page.evaluate(async ([act, pid]) => {
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        const token = csrfInput ? /** @type {HTMLInputElement} */ (csrfInput).value : '';
        const fd = new FormData();
        fd.append('csrf_token', token);
        const res = await fetch(
            `${window.location.origin}${window.BASE_PATH || ''}/admin/plugins/${pid}/${act}`,
            { method: 'POST', body: fd }
        );
        return res.json();
    }, [action, pluginId]);
}

/** Activate mobile-api + book-club through the admin UI when needed. */
async function ensurePlugins(browser) {
    const mobile = getPluginState('mobile-api', 'registerRoutes');
    const bookclub = getPluginState('book-club', 'registerRoutes');
    if (mobile.id === 0) throw new Error('mobile-api plugin not registered');
    if (bookclub.id === 0) throw new Error('book-club plugin not registered');

    const needsWork =
        !mobile.active || mobile.hooks === 0 || !tableExists('mobile_app_tokens') ||
        !bookclub.active || bookclub.hooks === 0 || !tableExists('bookclub_clubs');

    if (needsWork) {
        const ctx  = await browser.newContext();
        const page = await ctx.newPage();
        try {
            await page.goto(`${BASE}/login`);
            await page.fill('input[name="email"]',    ADMIN_EMAIL);
            await page.fill('input[name="password"]', ADMIN_PASS);
            await Promise.all([
                page.waitForURL(/\/admin\//, { timeout: 15_000 }),
                page.click('button[type="submit"]'),
            ]);
            await page.goto(`${BASE}/admin/plugins`);
            if (!mobile.active) await pluginApiCall(page, 'activate', mobile.id);
            if (!bookclub.active) await pluginApiCall(page, 'activate', bookclub.id);
        } finally {
            await ctx.close();
        }
        const m = getPluginState('mobile-api', 'registerRoutes');
        const b = getPluginState('book-club', 'registerRoutes');
        if (!m.active || !b.active || !tableExists('bookclub_polls')) {
            throw new Error('plugin activation did not produce schema + hooks');
        }
    }
}

// ─── Seeding ──────────────────────────────────────────────────────────────────

function ensureTestUser(email, password, suffix) {
    const existing = dbQuery(
        `SELECT id FROM utenti WHERE email = '${email.replace(/'/g, "''")}' LIMIT 1`
    );
    if (existing !== '') return parseInt(existing, 10);
    const hash = execFileSync('php', ['-r', `echo password_hash('${password}', PASSWORD_DEFAULT);`], {
        encoding: 'utf-8', timeout: 5000,
    }).trim();
    dbExec(
        `INSERT INTO utenti (nome, cognome, email, password, tipo_utente, email_verificata, stato, codice_tessera, privacy_accettata)
         VALUES ('Test${suffix}', 'User', '${email}', '${hash}', 'standard', 1, 'attivo', 'BCFX${suffix}', 1)`
    );
    return parseInt(dbQuery(`SELECT id FROM utenti WHERE email = '${email}' LIMIT 1`), 10);
}

function seedBook(title) {
    const existing = dbQuery(`SELECT id FROM libri WHERE titolo = '${title}' AND deleted_at IS NULL LIMIT 1`);
    if (existing !== '') return parseInt(existing, 10);
    dbExec(`INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES ('${title}', 1, 1)`);
    return parseInt(dbQuery(`SELECT id FROM libri WHERE titolo = '${title}' LIMIT 1`), 10);
}

const SLUG = 'e2e-bookclub-fixes';

/**
 * Club + membership + two proposals + an EXPIRED open poll where book A got
 * the only vote. The bridge's lazy close must resolve it on first read:
 * winner voting → selected, loser voting → proposed (default workflow).
 */
function seedClubWithExpiredPoll(userId) {
    dbExec(`DELETE FROM bookclub_clubs WHERE slug = '${SLUG}'`); // cascades to books/polls/members

    const roleId = parseInt(dbQuery(
        "SELECT id FROM bookclub_roles WHERE club_id IS NULL AND slug = 'owner' LIMIT 1"
    ), 10);
    if (!roleId) throw new Error('book-club system roles not seeded');

    dbExec(
        `INSERT INTO bookclub_clubs (slug, name, description, color, privacy, settings, ics_token, created_by, is_active)
         VALUES ('${SLUG}', 'E2E Fixes Club', 'seeded by mobile-api-bookclub-fixes.spec.js', '#4f46e5', 'public', '{}',
                 REPLACE(UUID(), '-', ''), ${userId}, 1)`
    );
    const clubId = parseInt(dbQuery(`SELECT id FROM bookclub_clubs WHERE slug = '${SLUG}' LIMIT 1`), 10);

    // Per-club copy of the default workflow (same seeding createClub performs).
    dbExec(
        `INSERT INTO bookclub_workflows (club_id, name, states)
         SELECT ${clubId}, 'E2E Fixes Club', states FROM bookclub_workflows WHERE club_id IS NULL LIMIT 1`
    );
    dbExec(
        `UPDATE bookclub_clubs SET workflow_id =
            (SELECT id FROM bookclub_workflows WHERE club_id = ${clubId} LIMIT 1)
          WHERE id = ${clubId}`
    );

    dbExec(
        `INSERT INTO bookclub_members (club_id, user_id, role_id, status)
         VALUES (${clubId}, ${userId}, ${roleId}, 'active')`
    );

    const bookA = seedBook('E2E BC Fixes Winner');
    const bookB = seedBook('E2E BC Fixes Loser');
    dbExec(
        `INSERT INTO bookclub_books (club_id, libro_id, state, proposed_by)
         VALUES (${clubId}, ${bookA}, 'voting', ${userId}), (${clubId}, ${bookB}, 'voting', ${userId})`
    );
    const cbA = parseInt(dbQuery(`SELECT id FROM bookclub_books WHERE club_id = ${clubId} AND libro_id = ${bookA}`), 10);
    const cbB = parseInt(dbQuery(`SELECT id FROM bookclub_books WHERE club_id = ${clubId} AND libro_id = ${bookB}`), 10);

    dbExec(
        `INSERT INTO bookclub_polls (club_id, title, mode, votes_per_member, anonymity, closes_at, status, created_by)
         VALUES (${clubId}, 'E2E expired poll', 'simple', 1, 'public', DATE_SUB(NOW(), INTERVAL 1 HOUR), 'open', ${userId})`
    );
    const pollId = parseInt(dbQuery(
        `SELECT id FROM bookclub_polls WHERE club_id = ${clubId} AND title = 'E2E expired poll' LIMIT 1`
    ), 10);
    dbExec(`INSERT INTO bookclub_poll_options (poll_id, club_book_id) VALUES (${pollId}, ${cbA}), (${pollId}, ${cbB})`);
    const optA = parseInt(dbQuery(
        `SELECT id FROM bookclub_poll_options WHERE poll_id = ${pollId} AND club_book_id = ${cbA}`
    ), 10);
    dbExec(`INSERT INTO bookclub_votes (poll_id, option_id, user_id, value) VALUES (${pollId}, ${optA}, ${userId}, 1)`);

    return { clubId, pollId, cbA, cbB, bookA };
}

// ─── Request helpers ──────────────────────────────────────────────────────────

function authHeaders(token) {
    /** @type {Record<string, string>} */
    const headers = { 'Content-Type': 'application/json' };
    if (token) headers['Authorization'] = `Bearer ${token}`;
    return headers;
}

// ─── Suite ────────────────────────────────────────────────────────────────────

const USER_EMAIL = 'e2e-bcfixes@example.test';
const USER_PASS  = 'E2eBcFixes!2026';

test.describe.serial('Mobile API contract fixes — E2E', () => {
    /** @type {string} */
    let token = '';
    /** @type {number} */
    let userId = 0;
    /** @type {{clubId: number, pollId: number, cbA: number, cbB: number, bookA: number}} */
    let seed = { clubId: 0, pollId: 0, cbA: 0, cbB: 0, bookA: 0 };

    test.beforeAll(async ({ browser }) => {
        await ensurePlugins(browser);
        userId = ensureTestUser(USER_EMAIL, USER_PASS, 'X1');
        seed = seedClubWithExpiredPoll(userId);
    });

    test.afterAll(async () => {
        setMobileApiEnabled('1'); // leave the instance usable for other suites
    });

    // ── 1. mobile_api.enabled gate on the public auth endpoints ──────────────

    test('gate off → login answers 403 app_access_disabled', async ({ request }) => {
        setMobileApiEnabled('0');
        const res = await request.post(`${API}/auth/login`, {
            headers: authHeaders(null),
            data: { email: USER_EMAIL, password: USER_PASS },
        });
        expect(res.status()).toBe(403);
        const body = await res.json();
        // Core mobile-api envelope: {data, meta, error} — no `success` field.
        expect(body.data).toBeNull();
        expect(body.error.code).toBe('app_access_disabled');
    });

    test('gate off → register and forgot-password answer 403 app_access_disabled', async ({ request }) => {
        const reg = await request.post(`${API}/auth/register`, {
            headers: authHeaders(null),
            data: { email: 'nobody@example.test', password: 'Xx12345678!', nome: 'No', cognome: 'Body' },
        });
        expect(reg.status()).toBe(403);
        expect((await reg.json()).error.code).toBe('app_access_disabled');

        const forgot = await request.post(`${API}/auth/forgot-password`, {
            headers: authHeaders(null),
            data: { email: USER_EMAIL },
        });
        expect(forgot.status()).toBe(403);
        expect((await forgot.json()).error.code).toBe('app_access_disabled');
    });

    test('gate on → login issues a token', async ({ request }) => {
        setMobileApiEnabled('1');
        const res = await request.post(`${API}/auth/login`, {
            headers: authHeaders(null),
            data: { email: USER_EMAIL, password: USER_PASS, device_name: 'e2e-fixes', platform: 'android' },
        });
        expect(res.status()).toBe(200);
        const body = await res.json();
        // Core mobile-api envelope: {data, meta, error} — no `success` field.
        expect(body.error).toBeNull();
        expect(typeof body.data.token).toBe('string');
        token = body.data.token;
    });

    // ── 2. Lazy close of expired polls on the mobile read path ───────────────

    test('expired poll is closed (with winner transition) by GET /bookclub/clubs/{slug}', async ({ request }) => {
        const res = await request.get(`${API}/bookclub/clubs/${SLUG}`, { headers: authHeaders(token) });
        expect(res.status()).toBe(200);
        const body = await res.json();
        expect(body.success).toBe(true);

        const poll = body.data.polls.find((p) => p.id === seed.pollId);
        expect(poll, 'seeded poll must be listed').toBeTruthy();
        expect(poll.status).toBe('closed');

        // Winner advanced one workflow step past 'voting' (default: selected);
        // the loser fell back to the entry state.
        const winner = body.data.books.find((b) => b.id === seed.cbA);
        const loser  = body.data.books.find((b) => b.id === seed.cbB);
        expect(winner.state).toBe('selected');
        expect(loser.state).toBe('proposed');

        // DB agrees: status flipped exactly once with the winner recorded.
        const row = dbQuery(
            `SELECT status, winner_club_book_id FROM bookclub_polls WHERE id = ${seed.pollId}`
        ).split('\t');
        expect(row[0]).toBe('closed');
        expect(parseInt(row[1], 10)).toBe(seed.cbA);
    });

    // ── 3. ISO-8601 UTC timestamps ────────────────────────────────────────────

    test('review created_at/updated_at are ISO-8601 UTC', async ({ request }) => {
        dbExec(
            `INSERT INTO recensioni (libro_id, utente_id, stelle, titolo, descrizione, stato)
             VALUES (${seed.bookA}, ${userId}, 5, 'E2E fix review', 'iso check', 'approvata')
             ON DUPLICATE KEY UPDATE stelle = 5, stato = 'approvata'`
        );
        const res = await request.get(`${API}/me/reviews`, { headers: authHeaders(token) });
        expect(res.status()).toBe(200);
        const body = await res.json();
        // ResponseEnvelope: data is the plain items array for this endpoint.
        const items = Array.isArray(body.data) ? body.data : [];
        const mine = items.find((r) => r.book_id === seed.bookA);
        expect(mine, 'seeded review must be listed').toBeTruthy();
        expect(mine.created_at).toMatch(ISO_UTC);
        expect(mine.updated_at).toMatch(ISO_UTC);
    });

    test('device created_at/last_used_at are ISO-8601 UTC', async ({ request }) => {
        const res = await request.get(`${API}/me/devices`, { headers: authHeaders(token) });
        expect(res.status()).toBe(200);
        const body = await res.json();
        // ResponseEnvelope: data is the plain devices array for this endpoint.
        const devices = Array.isArray(body.data) ? body.data : [];
        expect(devices.length).toBeGreaterThan(0);
        for (const device of devices) {
            if (device.created_at !== null)   expect(device.created_at).toMatch(ISO_UTC);
            if (device.last_used_at !== null) expect(device.last_used_at).toMatch(ISO_UTC);
        }
    });

    // ── 4. Book Club discovery reflects the app-access gate ──────────────────

    test('bookclub health exposes app_access_enabled coherently', async ({ request }) => {
        let res = await request.get(`${API}/bookclub/health`);
        expect(res.status()).toBe(200);
        let body = await res.json();
        expect(body.success).toBe(true);
        expect(body.data.plugin).toBe('book-club');
        expect(body.data.app_access_enabled).toBe(true);

        setMobileApiEnabled('0');
        res = await request.get(`${API}/bookclub/health`);
        expect(res.status()).toBe(200);
        body = await res.json();
        expect(body.data.app_access_enabled).toBe(false);

        setMobileApiEnabled('1');
    });
});

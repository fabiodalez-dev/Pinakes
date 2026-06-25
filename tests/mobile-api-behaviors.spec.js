// @ts-check
// Mobile API — reusable behaviour suite (25 tests).
//
// Covers the critical mobile-API behaviours built this session (see
// tests/CRITICAL_AREAS.md): health/discovery + catalogue-mode gating, opaque
// token auth/logout, the English snake_case catalog field contract, the
// availability `state` machine + calendar, personal_history (incl. the new
// has_pending_request), the /me/loans envelope, and reservation create/cancel.
//
// SELF-CONTAINED & CLEAN:
//   - beforeAll logs in as admin (POST /auth/login → token) and seeds a borrower
//     (direct DB) for reservation tests.
//   - Each test that needs an on-loan / loan / pending fixture seeds it against
//     the admin user and tears it down in a finally{} block so tests stay
//     independent and order-free.
//   - afterAll removes EVERYTHING seeded (admin loans/reservations, the borrower
//     and its tokens/reservations) so the full-test suite is unaffected and the
//     DB is left exactly as found.
//
// All state is created/torn down via DIRECT DB (mysql CLI through execFileSync);
// all assertions go through the live HTTP API. Creds come only from E2E_* env.
//
// Run:
//   /tmp/run-e2e.sh tests/mobile-api-behaviors.spec.js --config=tests/playwright.config.js --workers=1 --reporter=line

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE        = process.env.E2E_BASE_URL    || 'http://localhost:8081';
const API         = `${BASE}/api/v1`;
const DB_USER     = process.env.E2E_DB_USER     || '';
const DB_PASS     = process.env.E2E_DB_PASS     || '';
const DB_NAME     = process.env.E2E_DB_NAME     || '';
const DB_SOCKET   = process.env.E2E_DB_SOCKET   || '';
const DB_HOST     = process.env.E2E_DB_HOST     || '';
const DB_PORT     = process.env.E2E_DB_PORT     || '';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS  = process.env.E2E_ADMIN_PASS  || '';

test.skip(!DB_USER || !DB_NAME || !ADMIN_EMAIL || !ADMIN_PASS, 'Missing E2E env: DB_* and ADMIN_EMAIL/ADMIN_PASS');

// ─── DB helpers (mirror mobile-api-idempotency.spec.js) ──────────────────────

function mysqlArgs(sql, batch) {
    const args = [];
    if (DB_HOST) { args.push('-h', DB_HOST); if (DB_PORT) args.push('-P', DB_PORT); }
    else if (DB_SOCKET) { args.push('-S', DB_SOCKET); }
    args.push('-u', DB_USER, DB_NAME);
    if (batch) args.push('-N', '-B');
    args.push('-e', sql);
    return args;
}
function MYSQL_ENV() { return { ...process.env, MYSQL_PWD: DB_PASS }; }
function dbExec(sql) { execFileSync('mysql', mysqlArgs(sql, false), { encoding: 'utf-8', timeout: 15000, env: MYSQL_ENV() }); }
function dbScalar(sql) {
    const out = execFileSync('mysql', mysqlArgs(sql, true), { encoding: 'utf-8', timeout: 15000, env: MYSQL_ENV() }).trim();
    return out.split('\n')[0] || '';
}
function dbInt(sql) { const v = dbScalar(sql); return v === '' ? 0 : parseInt(v, 10); }
function sqlStr(v) { return "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'"; }

// ─── Request helpers ─────────────────────────────────────────────────────────

function headers(token, extra) {
    const h = { 'Content-Type': 'application/json', ...(extra || {}) };
    if (token) h['Authorization'] = `Bearer ${token}`;
    return h;
}
async function call(request, method, path, { token, body, extraHeaders } = {}) {
    const opts = { headers: headers(token, extraHeaders) };
    if (body !== undefined && body !== null) opts.data = body;
    return request.fetch(`${API}${path}`, { method, ...opts });
}
async function jsonOf(res) { try { return await res.json(); } catch { return null; } }

// ─── Date helpers ────────────────────────────────────────────────────────────

function ymd(d) { return d.toISOString().slice(0, 10); }
function todayYmd() { return ymd(new Date()); }
function plusDays(n) { const d = new Date(); d.setDate(d.getDate() + n); return ymd(d); }

// ─── Borrower fixture ────────────────────────────────────────────────────────

// Unique per run so the fixture never collides with — or destroys — a
// pre-existing real account on a shared (non-ephemeral) database.
const RUN_ID     = `${Date.now()}_${process.pid}`;
const USER_EMAIL = `behaviors_${RUN_ID}@pinakes.test`;
const USER_PASS  = 'Behav1234Test!';
const USER_CARD  = `BEHAV${String(process.pid).padStart(6, '0')}`.slice(0, 20);

// ─── Shared context ──────────────────────────────────────────────────────────

/** @type {Record<string, any>} */
const ctx = {};

// Pick a non-deleted book with exactly ONE copie row that is currently free.
function pickOneCopyAvailableBook() {
    return dbInt(`
        SELECT l.id
        FROM libri l
        WHERE l.deleted_at IS NULL
          AND l.copie_disponibili > 0
          AND (SELECT COUNT(*) FROM copie c WHERE c.libro_id = l.id) = 1
        ORDER BY l.id
        LIMIT 1`);
}

// Pick any available, non-deleted book (used for reservation create).
function pickAvailableBook(excludeId) {
    const ex = excludeId ? ` AND l.id <> ${excludeId}` : '';
    return dbInt(`SELECT l.id FROM libri l WHERE l.deleted_at IS NULL AND l.copie_disponibili > 0${ex} ORDER BY l.id LIMIT 1`);
}

// ── Seed / restore an on-loan single-copy book ───────────────────────────────
// Returns a teardown function that fully restores availability + copy state and
// removes the seeded loan. dataPrestito/dataScadenza define the blocked range.
function seedOnLoanBook(bookId, userId, dataPrestito, dataScadenza) {
    const copiaId = dbInt(`SELECT id FROM copie WHERE libro_id = ${bookId} ORDER BY id LIMIT 1`);
    const prevCopie = dbInt(`SELECT copie_disponibili FROM libri WHERE id = ${bookId}`);

    dbExec(`UPDATE libri SET copie_disponibili = 0 WHERE id = ${bookId}`);
    if (copiaId > 0) dbExec(`UPDATE copie SET stato = 'prestato' WHERE id = ${copiaId}`);
    dbExec(`
        INSERT INTO prestiti (libro_id, utente_id, copia_id, data_prestito, data_scadenza, stato, attivo, origine, renewals)
        VALUES (${bookId}, ${userId}, ${copiaId > 0 ? copiaId : 'NULL'}, ${sqlStr(dataPrestito)}, ${sqlStr(dataScadenza)}, 'in_corso', 1, 'diretto', 0)`);
    const loanId = dbInt(`SELECT id FROM prestiti WHERE libro_id = ${bookId} AND utente_id = ${userId} ORDER BY id DESC LIMIT 1`);

    return function teardown() {
        try { dbExec(`DELETE FROM prestiti WHERE id = ${loanId}`); } catch {}
        try { if (copiaId > 0) dbExec(`UPDATE copie SET stato = 'disponibile' WHERE id = ${copiaId}`); } catch {}
        try { dbExec(`UPDATE libri SET copie_disponibili = ${prevCopie} WHERE id = ${bookId}`); } catch {}
    };
}

// ── Seed / restore a reserved (scheduled, prenotato) single-copy book ────────
// Same shape as seedOnLoanBook but the hold is a future 'prenotato' loan
// (attivo = 1), which the API must report as availability.state = 'reserved'.
function seedReservedBook(bookId, userId, dataPrestito, dataScadenza) {
    const copiaId = dbInt(`SELECT id FROM copie WHERE libro_id = ${bookId} ORDER BY id LIMIT 1`);
    const prevCopie = dbInt(`SELECT copie_disponibili FROM libri WHERE id = ${bookId}`);

    dbExec(`UPDATE libri SET copie_disponibili = 0 WHERE id = ${bookId}`);
    if (copiaId > 0) dbExec(`UPDATE copie SET stato = 'prestato' WHERE id = ${copiaId}`);
    dbExec(`
        INSERT INTO prestiti (libro_id, utente_id, copia_id, data_prestito, data_scadenza, stato, attivo, origine, renewals)
        VALUES (${bookId}, ${userId}, ${copiaId > 0 ? copiaId : 'NULL'}, ${sqlStr(dataPrestito)}, ${sqlStr(dataScadenza)}, 'prenotato', 1, 'prenotazione', 0)`);
    const loanId = dbInt(`SELECT id FROM prestiti WHERE libro_id = ${bookId} AND utente_id = ${userId} ORDER BY id DESC LIMIT 1`);

    return function teardown() {
        try { dbExec(`DELETE FROM prestiti WHERE id = ${loanId}`); } catch {}
        try { if (copiaId > 0) dbExec(`UPDATE copie SET stato = 'disponibile' WHERE id = ${copiaId}`); } catch {}
        try { dbExec(`UPDATE libri SET copie_disponibili = ${prevCopie} WHERE id = ${bookId}`); } catch {}
    };
}

test.beforeAll(async ({ request }) => {
    // 1) Admin token via the API.
    const login = await call(request, 'POST', '/auth/login', {
        body: { email: ADMIN_EMAIL, password: ADMIN_PASS, device_name: 'BehavAdmin', device_id: 'behav-admin', platform: 'test' },
    });
    expect(login.status(), 'admin login must succeed').toBe(200);
    const loginJson = await login.json();
    ctx.adminToken = loginJson.data.token;
    ctx.adminId = parseInt(String(loginJson.data.user.id), 10);
    expect(ctx.adminId, 'admin id resolved').toBeGreaterThan(0);

    // 2) Seed the borrower (direct DB) with a real bcrypt hash for USER_PASS.
    let uid = dbInt(`SELECT id FROM utenti WHERE email = ${sqlStr(USER_EMAIL)} LIMIT 1`);
    const realHash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(USER_PASS)}, PASSWORD_DEFAULT);`], { encoding: 'utf-8' }).trim();
    if (uid === 0) {
        dbExec(`
            INSERT INTO utenti (nome, cognome, email, password, codice_tessera, tipo_utente, stato, email_verificata, created_at)
            VALUES ('Behav','Test',${sqlStr(USER_EMAIL)},${sqlStr(realHash)},${sqlStr(USER_CARD)},'standard','attivo',1,NOW())`);
        uid = dbInt(`SELECT id FROM utenti WHERE email = ${sqlStr(USER_EMAIL)} LIMIT 1`);
        ctx.createdUser = true;
    } else {
        dbExec(`UPDATE utenti SET password = ${sqlStr(realHash)}, stato = 'attivo', email_verificata = 1, codice_tessera = ${sqlStr(USER_CARD)} WHERE id = ${uid}`);
    }
    ctx.userId = uid;
    expect(ctx.userId, 'borrower id seeded').toBeGreaterThan(0);

    // 3) Borrower token via the API.
    const blogin = await call(request, 'POST', '/auth/login', {
        body: { email: USER_EMAIL, password: USER_PASS, device_name: 'BehavUser', device_id: 'behav-user', platform: 'test' },
    });
    expect(blogin.status(), 'borrower login must succeed').toBe(200);
    ctx.userToken = (await blogin.json()).data.token;

    // 4) Fixture book ids.
    ctx.oneCopyBook = pickOneCopyAvailableBook();
    ctx.availBook   = pickAvailableBook();
    expect(ctx.availBook, 'an available book exists').toBeGreaterThan(0);
});

test.afterAll(async () => {
    // Remove everything seeded so the next suite sees a pristine DB.
    if (ctx.userId) {
        try { dbExec(`DELETE FROM prestiti WHERE utente_id = ${ctx.userId}`); } catch {}
        try { dbExec(`DELETE FROM prenotazioni WHERE utente_id = ${ctx.userId}`); } catch {}
        try { dbExec(`DELETE FROM mobile_app_tokens WHERE user_id = ${ctx.userId}`); } catch {}
        try { dbExec(`DELETE FROM mobile_availability_watchers WHERE user_id = ${ctx.userId}`); } catch {}
        try { dbExec(`DELETE FROM wishlist WHERE utente_id = ${ctx.userId}`); } catch {}
        // Only delete the account if THIS run created it — never an account that
        // already existed (the unique per-run email makes a collision unlikely,
        // but stay defensive).
        if (ctx.createdUser) {
            try { dbExec(`DELETE FROM utenti WHERE id = ${ctx.userId}`); } catch {}
        }
    }
    if (ctx.adminId) {
        // Only the tokens this suite created for the admin; leave any others.
        try { dbExec(`DELETE FROM mobile_app_tokens WHERE user_id = ${ctx.adminId} AND device_id = 'behav-admin'`); } catch {}
        // (No admin reservations are seeded by this suite — all admin fixtures are
        // loans, each cleaned by its own id — so we avoid a broad time-window
        // DELETE on prenotazioni that could remove unrelated real reservations.)
    }
    // Always restore catalogue mode off.
    try { dbExec("UPDATE system_settings SET setting_value = '0' WHERE category = 'system' AND setting_key = 'catalogue_mode'"); } catch {}
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. HEALTH (4)
// ─────────────────────────────────────────────────────────────────────────────

test.describe('Health & discovery', () => {
    test('1) GET /health → 200 with name + api_version + features', async ({ request }) => {
        const res = await call(request, 'GET', '/health');
        expect(res.status()).toBe(200);
        const j = await res.json();
        expect(j.data.name, 'name present').toBeTruthy();
        expect(j.data.api_version, 'api_version present').toBeTruthy();
        expect(typeof j.data.features, 'features is an object').toBe('object');
    });

    test('2) features.loans/reservations/wishlist all true when not in catalogue mode', async ({ request }) => {
        // Ensure catalogue mode is off for this assertion.
        dbExec("UPDATE system_settings SET setting_value = '0' WHERE category = 'system' AND setting_key = 'catalogue_mode'");
        const j = await jsonOf(await call(request, 'GET', '/health'));
        expect(j.data.catalogue_mode).toBe(false);
        expect(j.data.features.loans).toBe(true);
        expect(j.data.features.reservations).toBe(true);
        expect(j.data.features.wishlist).toBe(true);
    });

    test('3) catalogue_mode is a boolean and app_access_enabled is true', async ({ request }) => {
        const j = await jsonOf(await call(request, 'GET', '/health'));
        expect(typeof j.data.catalogue_mode).toBe('boolean');
        expect(j.data.app_access_enabled).toBe(true);
    });

    test('4) toggling catalogue mode flips features.loans and catalogue_mode', async ({ request }) => {
        try {
            // Turn catalogue-only mode ON.
            dbExec(`
                INSERT INTO system_settings (category, setting_key, setting_value)
                VALUES ('system','catalogue_mode','1')
                ON DUPLICATE KEY UPDATE setting_value = '1'`);
            const on = await jsonOf(await call(request, 'GET', '/health'));
            expect(on.data.catalogue_mode, 'catalogue_mode true when on').toBe(true);
            expect(on.data.features.loans, 'loans gated off in catalogue mode').toBe(false);
            expect(on.data.features.reservations).toBe(false);
            expect(on.data.features.wishlist).toBe(false);
        } finally {
            // Restore and confirm loans is re-enabled.
            dbExec("UPDATE system_settings SET setting_value = '0' WHERE category = 'system' AND setting_key = 'catalogue_mode'");
        }
        const off = await jsonOf(await call(request, 'GET', '/health'));
        expect(off.data.catalogue_mode, 'catalogue_mode restored false').toBe(false);
        expect(off.data.features.loans, 'loans re-enabled after restore').toBe(true);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. AUTH (3)
// ─────────────────────────────────────────────────────────────────────────────

test.describe('Auth & opaque tokens', () => {
    test('5) valid login → token + user.email', async ({ request }) => {
        const res = await call(request, 'POST', '/auth/login', {
            body: { email: USER_EMAIL, password: USER_PASS, device_name: 'BehavLogin5', device_id: 'behav-login5', platform: 'test' },
        });
        expect(res.status()).toBe(200);
        const j = await res.json();
        expect(j.data.token, 'token issued').toBeTruthy();
        expect(j.data.user.email, 'user email echoed').toBe(USER_EMAIL);
        // Clean up this throwaway device token.
        dbExec(`DELETE FROM mobile_app_tokens WHERE user_id = ${ctx.userId} AND device_id = 'behav-login5'`);
    });

    test('6) wrong password → 401, no token', async ({ request }) => {
        const res = await call(request, 'POST', '/auth/login', {
            body: { email: USER_EMAIL, password: 'definitely-wrong-pw', device_name: 'BehavBad', device_id: 'behav-bad', platform: 'test' },
        });
        expect(res.status()).toBe(401);
        const j = await jsonOf(res);
        expect(j?.data?.token, 'no token on failed login').toBeFalsy();
    });

    test('7) token authorises /me; after logout the same token → 401', async ({ request }) => {
        // Dedicated throwaway token so the logout does not revoke the shared one.
        const login = await call(request, 'POST', '/auth/login', {
            body: { email: USER_EMAIL, password: USER_PASS, device_name: 'BehavLogout', device_id: 'behav-logout', platform: 'test' },
        });
        const token = (await login.json()).data.token;

        const me1 = await call(request, 'GET', '/me', { token });
        expect(me1.status(), '/me authorised before logout').toBe(200);

        const out = await call(request, 'POST', '/auth/logout', { token });
        expect(out.status() >= 200 && out.status() < 300, 'logout 2xx').toBe(true);

        const me2 = await call(request, 'GET', '/me', { token });
        expect(me2.status(), '/me rejected after logout (token revoked)').toBe(401);

        dbExec(`DELETE FROM mobile_app_tokens WHERE user_id = ${ctx.userId} AND device_id = 'behav-logout'`);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. CATALOG FIELD CONTRACT (4)
// ─────────────────────────────────────────────────────────────────────────────

test.describe('Catalog field contract (English snake_case)', () => {
    test('8) /catalog/search item exposes English keys', async ({ request }) => {
        const res = await call(request, 'GET', '/catalog/search?limit=5', { token: ctx.userToken });
        expect(res.status()).toBe(200);
        const j = await res.json();
        expect(Array.isArray(j.data), 'data is an array').toBe(true);
        expect(j.data.length, 'at least one result').toBeGreaterThan(0);
        const item = j.data[0];
        expect(typeof item.title, 'title is a string').toBe('string');
        expect(typeof item.loanable_now, 'loanable_now is a boolean').toBe('boolean');
        expect(typeof item.cover_url, 'cover_url is a string').toBe('string');
    });

    test('9) /catalog/books/{id} detail has title + availability + personal_history', async ({ request }) => {
        const res = await call(request, 'GET', `/catalog/books/${ctx.availBook}`, { token: ctx.userToken });
        expect(res.status()).toBe(200);
        const d = (await res.json()).data;
        expect(typeof d.title).toBe('string');
        expect(typeof d.availability, 'availability is an object').toBe('object');
        expect(typeof d.personal_history, 'personal_history is an object').toBe('object');
    });

    test('10) /catalog/genres returns nodes with name + children[]', async ({ request }) => {
        const res = await call(request, 'GET', '/catalog/genres', { token: ctx.userToken });
        expect(res.status()).toBe(200);
        const nodes = (await res.json()).data;
        expect(Array.isArray(nodes)).toBe(true);
        expect(nodes.length, 'at least one genre node').toBeGreaterThan(0);
        const n = nodes[0];
        expect(typeof n.name, 'genre exposes `name` (not `nome`)').toBe('string');
        expect(n.name.length, 'name non-empty').toBeGreaterThan(0);
        expect(Array.isArray(n.children), 'children is an array').toBe(true);
    });

    test('11) book detail includes has_audio/has_ebook (bool) and audio_url/ebook_url (string|null)', async ({ request }) => {
        const d = (await jsonOf(await call(request, 'GET', `/catalog/books/${ctx.availBook}`, { token: ctx.userToken }))).data;
        expect(typeof d.has_audio).toBe('boolean');
        expect(typeof d.has_ebook).toBe('boolean');
        expect(d.audio_url === null || typeof d.audio_url === 'string').toBe(true);
        expect(d.ebook_url === null || typeof d.ebook_url === 'string').toBe(true);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. AVAILABILITY STATE (4)
// ─────────────────────────────────────────────────────────────────────────────

const ALLOWED_STATES = ['available', 'on_loan', 'reserved', 'unavailable'];

test.describe('Availability state', () => {
    test('12) availability.state is one of the 4 allowed strings', async ({ request }) => {
        const d = (await jsonOf(await call(request, 'GET', `/catalog/books/${ctx.availBook}`, { token: ctx.userToken }))).data;
        expect(ALLOWED_STATES).toContain(d.availability.state);
    });

    test('13) a known-available book → state=available + loanable_now=true', async ({ request }) => {
        const book = pickAvailableBook();
        const d = (await jsonOf(await call(request, 'GET', `/catalog/books/${book}`, { token: ctx.userToken }))).data;
        expect(d.availability.state).toBe('available');
        expect(d.availability.loanable_now).toBe(true);
    });

    test('14) an on-loan single-copy book → state=on_loan, loanable_now=false, copies_available=0', async ({ request }) => {
        const book = pickOneCopyAvailableBook();
        expect(book, 'a 1-copy available book exists').toBeGreaterThan(0);
        const teardown = seedOnLoanBook(book, ctx.adminId, todayYmd(), plusDays(7));
        try {
            const d = (await jsonOf(await call(request, 'GET', `/catalog/books/${book}`, { token: ctx.adminToken }))).data;
            expect(d.availability.state).toBe('on_loan');
            expect(d.availability.loanable_now).toBe(false);
            expect(d.availability.copies_available).toBe(0);
        } finally {
            teardown();
        }
    });

    test('14b) a prenotato (scheduled) single-copy book → state=reserved, loanable_now=false', async ({ request }) => {
        const book = pickOneCopyAvailableBook();
        expect(book, 'a 1-copy available book exists').toBeGreaterThan(0);
        const teardown = seedReservedBook(book, ctx.adminId, plusDays(10), plusDays(40));
        try {
            const d = (await jsonOf(await call(request, 'GET', `/catalog/books/${book}`, { token: ctx.adminToken }))).data;
            expect(d.availability.state).toBe('reserved');
            expect(d.availability.loanable_now).toBe(false);
            expect(d.availability.copies_available).toBe(0);
        } finally {
            teardown();
        }
    });

    test('15) the on-loan book\'s /availability blocks the loan range; earliest_available is after today', async ({ request }) => {
        const book = pickOneCopyAvailableBook();
        expect(book, 'a 1-copy available book exists').toBeGreaterThan(0);
        const start = todayYmd();
        const end = plusDays(7);
        const teardown = seedOnLoanBook(book, ctx.adminId, start, end);
        try {
            const d = (await jsonOf(await call(request, 'GET', `/catalog/books/${book}/availability`, { token: ctx.adminToken }))).data;
            expect(Array.isArray(d.unavailable_dates)).toBe(true);
            // The loan start day must be blocked.
            expect(d.unavailable_dates, 'loan start date is unavailable').toContain(start);
            // earliest_available must be strictly after today (the copy is busy now).
            expect(d.earliest_available, 'earliest_available present').toBeTruthy();
            expect(d.earliest_available > start, 'earliest_available after today').toBe(true);
        } finally {
            teardown();
        }
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. PERSONAL HISTORY (3)
// ─────────────────────────────────────────────────────────────────────────────

test.describe('Personal history', () => {
    test('16) personal_history exposes all five booleans incl. has_pending_request', async ({ request }) => {
        const ph = (await jsonOf(await call(request, 'GET', `/catalog/books/${ctx.availBook}`, { token: ctx.adminToken }))).data.personal_history;
        for (const key of ['has_read', 'has_reserved', 'has_wishlisted', 'has_active_loan', 'has_pending_request']) {
            expect(typeof ph[key], `${key} is a boolean`).toBe('boolean');
        }
    });

    test('17) an in_corso loan for ADMIN → personal_history.has_active_loan=true', async ({ request }) => {
        const book = pickAvailableBook();
        const copiaId = dbInt(`SELECT id FROM copie WHERE libro_id = ${book} ORDER BY id LIMIT 1`);
        dbExec(`
            INSERT INTO prestiti (libro_id, utente_id, copia_id, data_prestito, data_scadenza, stato, attivo, origine, renewals)
            VALUES (${book}, ${ctx.adminId}, ${copiaId > 0 ? copiaId : 'NULL'}, ${sqlStr(todayYmd())}, ${sqlStr(plusDays(14))}, 'in_corso', 1, 'diretto', 0)`);
        const loanId = dbInt(`SELECT id FROM prestiti WHERE libro_id = ${book} AND utente_id = ${ctx.adminId} ORDER BY id DESC LIMIT 1`);
        try {
            const ph = (await jsonOf(await call(request, 'GET', `/catalog/books/${book}`, { token: ctx.adminToken }))).data.personal_history;
            expect(ph.has_active_loan).toBe(true);
        } finally {
            dbExec(`DELETE FROM prestiti WHERE id = ${loanId}`);
        }
    });

    test('18) a pendente request (attivo=0) for ADMIN → has_pending_request=true, has_active_loan=false', async ({ request }) => {
        const book = pickAvailableBook();
        dbExec(`
            INSERT INTO prestiti (libro_id, utente_id, copia_id, data_prestito, data_scadenza, stato, attivo, origine, renewals)
            VALUES (${book}, ${ctx.adminId}, NULL, ${sqlStr(todayYmd())}, ${sqlStr(plusDays(14))}, 'pendente', 0, 'richiesta', 0)`);
        const loanId = dbInt(`SELECT id FROM prestiti WHERE libro_id = ${book} AND utente_id = ${ctx.adminId} AND stato = 'pendente' ORDER BY id DESC LIMIT 1`);
        try {
            const ph = (await jsonOf(await call(request, 'GET', `/catalog/books/${book}`, { token: ctx.adminToken }))).data.personal_history;
            expect(ph.has_pending_request, 'pending request flagged').toBe(true);
            expect(ph.has_active_loan, 'pending request is NOT an active loan').toBe(false);
        } finally {
            dbExec(`DELETE FROM prestiti WHERE id = ${loanId}`);
        }
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 6. LOANS (3)
// ─────────────────────────────────────────────────────────────────────────────

test.describe('Loans envelope', () => {
    test('19) /me/loans returns an object with pending/active/history arrays', async ({ request }) => {
        const d = (await jsonOf(await call(request, 'GET', '/me/loans', { token: ctx.adminToken }))).data;
        expect(Array.isArray(d.pending)).toBe(true);
        expect(Array.isArray(d.active)).toBe(true);
        expect(Array.isArray(d.history)).toBe(true);
    });

    test('20) a seeded in_corso loan appears in active[] with status=in_corso and a due_at', async ({ request }) => {
        const book = pickAvailableBook();
        const copiaId = dbInt(`SELECT id FROM copie WHERE libro_id = ${book} ORDER BY id LIMIT 1`);
        const due = plusDays(14);
        dbExec(`
            INSERT INTO prestiti (libro_id, utente_id, copia_id, data_prestito, data_scadenza, stato, attivo, origine, renewals)
            VALUES (${book}, ${ctx.adminId}, ${copiaId > 0 ? copiaId : 'NULL'}, ${sqlStr(todayYmd())}, ${sqlStr(due)}, 'in_corso', 1, 'diretto', 0)`);
        const loanId = dbInt(`SELECT id FROM prestiti WHERE libro_id = ${book} AND utente_id = ${ctx.adminId} ORDER BY id DESC LIMIT 1`);
        try {
            const active = (await jsonOf(await call(request, 'GET', '/me/loans', { token: ctx.adminToken }))).data.active;
            const mine = active.find((l) => l.id === loanId);
            expect(mine, 'seeded loan present in active[]').toBeTruthy();
            expect(mine.status).toBe('in_corso');
            expect(mine.due_at, 'due_at present on active loan').toBeTruthy();
        } finally {
            dbExec(`DELETE FROM prestiti WHERE id = ${loanId}`);
        }
    });

    test('21) a seeded restituito loan appears in history[]', async ({ request }) => {
        const book = pickAvailableBook();
        dbExec(`
            INSERT INTO prestiti (libro_id, utente_id, copia_id, data_prestito, data_scadenza, data_restituzione, stato, attivo, origine, renewals)
            VALUES (${book}, ${ctx.adminId}, NULL, ${sqlStr(plusDays(-30))}, ${sqlStr(plusDays(-16))}, ${sqlStr(plusDays(-18))}, 'restituito', 0, 'diretto', 0)`);
        const loanId = dbInt(`SELECT id FROM prestiti WHERE libro_id = ${book} AND utente_id = ${ctx.adminId} AND stato = 'restituito' ORDER BY id DESC LIMIT 1`);
        try {
            const history = (await jsonOf(await call(request, 'GET', '/me/loans', { token: ctx.adminToken }))).data.history;
            const mine = history.find((l) => l.id === loanId);
            expect(mine, 'seeded returned loan present in history[]').toBeTruthy();
            expect(mine.status).toBe('restituito');
        } finally {
            dbExec(`DELETE FROM prestiti WHERE id = ${loanId}`);
        }
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// 7. RESERVATIONS (4)
// ─────────────────────────────────────────────────────────────────────────────

test.describe('Reservations', () => {
    // Each reservation test cleans up after itself so create→409→cancel stay
    // independent. We use the BORROWER token (a fresh user with no prior state).
    function clearBorrowerReservations() {
        dbExec(`DELETE FROM prenotazioni WHERE utente_id = ${ctx.userId}`);
        dbExec(`DELETE FROM prestiti WHERE utente_id = ${ctx.userId}`);
        dbExec(`DELETE FROM mobile_availability_watchers WHERE user_id = ${ctx.userId}`);
    }

    test('22) POST /reservations {available book, future desired_date} → 201', async ({ request }) => {
        clearBorrowerReservations();
        try {
            const book = pickAvailableBook();
            const res = await call(request, 'POST', '/reservations', {
                token: ctx.userToken,
                body: { book_id: book, desired_date: plusDays(10) },
            });
            expect(res.status(), 'first reservation created').toBe(201);
        } finally {
            clearBorrowerReservations();
        }
    });

    test('23) immediately re-POSTing the same reservation → 409', async ({ request }) => {
        clearBorrowerReservations();
        try {
            const book = pickAvailableBook();
            const body = { book_id: book, desired_date: plusDays(10) };
            const r1 = await call(request, 'POST', '/reservations', { token: ctx.userToken, body });
            expect(r1.status(), 'first call 201').toBe(201);
            const r2 = await call(request, 'POST', '/reservations', { token: ctx.userToken, body });
            expect(r2.status(), 'duplicate request rejected with 409').toBe(409);
        } finally {
            clearBorrowerReservations();
        }
    });

    test('24) a busy-now book is reservable for a future free date → 201', async ({ request }) => {
        clearBorrowerReservations();
        const book = pickOneCopyAvailableBook();
        expect(book, 'a 1-copy book exists').toBeGreaterThan(0);
        // Make the book busy now with an admin loan ending in 7 days.
        const teardown = seedOnLoanBook(book, ctx.adminId, todayYmd(), plusDays(7));
        try {
            const res = await call(request, 'POST', '/reservations', {
                token: ctx.userToken,
                body: { book_id: book, desired_date: plusDays(20) }, // after the loan ends
            });
            expect(res.status(), 'reservation for a future free date accepted').toBe(201);
        } finally {
            clearBorrowerReservations();
            teardown();
        }
    });

    test('25) DELETE a just-created reservation → 2xx; a second DELETE → 4xx', async ({ request }) => {
        clearBorrowerReservations();
        try {
            const book = pickAvailableBook();
            // Create a reservation row directly (queue) so we own a known id to cancel.
            dbExec(`
                INSERT INTO prenotazioni (libro_id, utente_id, stato, data_prenotazione, created_at)
                VALUES (${book}, ${ctx.userId}, 'attiva', NOW(), NOW())`);
            const resId = dbInt(`SELECT id FROM prenotazioni WHERE utente_id = ${ctx.userId} ORDER BY id DESC LIMIT 1`);
            expect(resId, 'reservation seeded').toBeGreaterThan(0);

            const d1 = await call(request, 'DELETE', `/reservations/${resId}`, { token: ctx.userToken });
            expect(d1.status() >= 200 && d1.status() < 300, `first cancel 2xx (got ${d1.status()})`).toBe(true);

            const d2 = await call(request, 'DELETE', `/reservations/${resId}`, { token: ctx.userToken });
            expect(d2.status(), 'second cancel of the same id → 4xx (gone/conflict)').toBeGreaterThanOrEqual(400);
        } finally {
            clearBorrowerReservations();
        }
    });

    test('26) {available book, desired_date=today} → immediate loan (type=loan), not a reservation', async ({ request }) => {
        // The app's "Request loan" on an AVAILABLE title sends today's date (its
        // date picker pre-selects the first free day = today). The backend must
        // treat today + a free copy as an immediate pending loan, not a
        // reservation — otherwise the available-now flow silently queues instead
        // of requesting a loan. A FUTURE date stays a reservation (test 22).
        clearBorrowerReservations();
        try {
            const book = pickAvailableBook();
            const res = await call(request, 'POST', '/reservations', {
                token: ctx.userToken,
                body: { book_id: book, desired_date: todayYmd() },
            });
            expect(res.status(), 'created').toBe(201);
            const j = await jsonOf(res);
            expect(j?.data?.type, 'today + available → immediate loan').toBe('loan');
        } finally {
            clearBorrowerReservations();
        }
    });
});

// @ts-check
// Mobile API — reusable "two calls per endpoint" idempotency + ETag/304 suite.
//
// Every exposed /api/v1 endpoint is exercised TWICE and asserted against an
// idempotency contract:
//   - etag  GET : 1st → 200 with an ETag header; 2nd with If-None-Match → 304.
//   - safe  GET : both calls 2xx, same status (no state corrupts on re-read).
//   - write     : the 2nd identical call obeys the endpoint's repeat contract
//                 (idempotent 2xx / 409 conflict / 404 gone), per the manifest.
//
// ── THE RULE ────────────────────────────────────────────────────────────────
// Adding an endpoint means adding EXACTLY ONE row to ENDPOINTS below. A guard
// test ('manifest covers every exposed route') reads /api/v1/openapi.json and
// FAILS if any documented route has no manifest row — so a new endpoint cannot
// ship without its idempotency contract being declared here.
// ─────────────────────────────────────────────────────────────────────────────
//
// Run: /tmp/run-e2e.sh tests/mobile-api-idempotency.spec.js --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

test.describe.configure({ mode: 'serial' });

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

// ─── DB helpers (mirror mobile-api.spec.js) ──────────────────────────────────

function mysqlArgs(sql, batch = false) {
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

// ─── Plugin activation + enable gate (mirror mobile-api.spec.js) ──────────────

function pluginId() {
    return parseInt(dbScalar("SELECT id FROM plugins WHERE name='mobile-api' LIMIT 1") || '0', 10);
}
async function ensurePluginReady(page) {
    const pid = pluginId();
    if (pid === 0) throw new Error('mobile-api plugin is not registered');
    const active = dbScalar(`SELECT is_active FROM plugins WHERE id=${pid}`) === '1';
    const hooks = parseInt(dbScalar(`SELECT COUNT(*) FROM plugin_hooks WHERE plugin_id=${pid} AND is_active=1`) || '0', 10);
    if (!active || hooks === 0) {
        await page.goto(`${BASE}/accedi`);
        await page.fill('input[name="email"]', ADMIN_EMAIL);
        await page.fill('input[name="password"]', ADMIN_PASS);
        await page.locator('button[type="submit"]').click();
        await page.waitForURL((u) => !u.toString().includes('/accedi'), { timeout: 15000 });
        await page.evaluate(async (pid) => {
            const base = window.location.origin + (window.BASE_PATH || '');
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            if (document.querySelector(`[data-plugin-id="${pid}"].active`)) {
                const d = await fetch(`${base}/admin/plugins/${pid}/deactivate`, { method: 'POST', headers: { 'X-CSRF-Token': csrf } });
                if (!d.ok) throw new Error(`plugin deactivate failed: HTTP ${d.status}`);
            }
            const a = await fetch(`${base}/admin/plugins/${pid}/activate`, { method: 'POST', headers: { 'X-CSRF-Token': csrf } });
            if (!a.ok) throw new Error(`plugin activate failed: HTTP ${a.status}`);
        }, pid);
    }
    dbExec("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('mobile_api','enabled','1') ON DUPLICATE KEY UPDATE setting_value='1'");

    // Fail fast with a clear diagnostic if activation did not actually persist
    // (CSRF/session/hook issues otherwise surface as confusing 404s later).
    const activeNow = dbScalar(`SELECT is_active FROM plugins WHERE id=${pid}`) === '1';
    const hooksNow  = parseInt(dbScalar(`SELECT COUNT(*) FROM plugin_hooks WHERE plugin_id=${pid} AND is_active=1`) || '0', 10);
    if (!activeNow || hooksNow === 0) {
        throw new Error('mobile-api plugin activation did not persist (active/hooks check failed)');
    }
}

// ─── Request helpers ─────────────────────────────────────────────────────────

function headers(token, extra) {
    const h = { 'Content-Type': 'application/json', ...(extra || {}) };
    if (token) h['Authorization'] = `Bearer ${token}`;
    return h;
}
async function call(request, method, fullPath, { token, body, extraHeaders } = {}) {
    const opts = { headers: headers(token, extraHeaders) };
    if (body !== undefined && body !== null) opts.data = body;
    const res = await request.fetch(fullPath, { method, ...opts });
    return res;
}

// ─── Test user A (a borrower) ────────────────────────────────────────────────

// Unique per run so the fixture never mutates or deletes a pre-existing real
// account on a shared (non-ephemeral) database.
const RUN_ID     = `${Date.now()}_${process.pid}`;
const USER_EMAIL = `idem_${RUN_ID}@pinakes.test`;
const USER_PASS  = 'Idem1234Test!';
const USER_CARD  = `IDEM${String(process.pid).padStart(6, '0')}`.slice(0, 20);

// ─────────────────────────────────────────────────────────────────────────────
// THE MANIFEST — one row per exposed /api/v1 endpoint. `kind` selects the
// two-call contract the shared runner enforces. `path` may use {placeholders}
// resolved from `ctx` (built in beforeAll). `body` may be an object or a
// function (ctx) => object so writes can reference fixtures.
// ─────────────────────────────────────────────────────────────────────────────
//
// kinds:
//   doc        — public, no token, no ETag: both calls 200.
//   etag       — GET with ETag: 1st 200+ETag, 2nd If-None-Match → 304.
//   safeGet    — authed GET, no ETag: both 2xx (read is idempotent).
//   write2xx   — write whose repeat is idempotent: both calls in 2xx.
//   conflict2  — write whose 2nd identical call is rejected (>=400).
//   gone2      — delete: 1st 2xx-or-404, 2nd → 404.
//   authGate   — call WITHOUT token then WITH token: 1st 401, 2nd 2xx.

const ENDPOINTS = [
    { name: 'GET /openapi.json',                 method: 'GET',    path: '/openapi.json',                 auth: false, kind: 'doc' },
    { name: 'GET /docs',                         method: 'GET',    path: '/docs',                         auth: false, kind: 'doc' },
    { name: 'GET /health',                       method: 'GET',    path: '/health',                       auth: false, kind: 'doc' },
    { name: 'POST /auth/login',                  method: 'POST',   path: '/auth/login',                   auth: false, kind: 'write2xx',
        body: () => ({ email: USER_EMAIL, password: USER_PASS, device_name: 'IdemDev', device_id: 'idem-dev', platform: 'test' }) },
    { name: 'POST /auth/forgot-password',        method: 'POST',   path: '/auth/forgot-password',         auth: false, kind: 'write2xx',
        body: () => ({ email: USER_EMAIL }) },
    { name: 'POST /auth/register',               method: 'POST',   path: '/auth/register',                auth: false, kind: 'conflict2',
        body: () => ({ email: USER_EMAIL, password: USER_PASS, password_confirm: USER_PASS, nome: 'Idem', cognome: 'Test', telefono: '0', indirizzo: 'x', privacy_acceptance: '1' }),
        firstAny: true /* registration may be disabled → 1st is 4xx; still asserts 2nd >= 400 */ },
    { name: 'POST /auth/logout',                 method: 'POST',   path: '/auth/logout',                  auth: 'throwaway', kind: 'revoked2' },
    { name: 'GET /me',                           method: 'GET',    path: '/me',                           auth: true,  kind: 'safeGet' },
    { name: 'PATCH /me',                         method: 'PATCH',  path: '/me',                           auth: true,  kind: 'write2xx',
        body: () => ({ nome: 'Idem' }) },
    { name: 'POST /me/password',                 method: 'POST',   path: '/me/password',                  auth: true,  kind: 'conflict2', firstAny: true,
        body: () => ({ current_password: 'definitely-wrong', password: USER_PASS, password_confirm: USER_PASS }) /* wrong current → both rejected (422), no mutation */ },
    { name: 'GET /me/devices',                   method: 'GET',    path: '/me/devices',                   auth: true,  kind: 'safeGet' },
    { name: 'DELETE /me/devices/{deviceId}',     method: 'DELETE', path: '/me/devices/{deviceId}',        auth: true,  kind: 'gone2' },
    { name: 'GET /catalog/search',               method: 'GET',    path: '/catalog/search',               auth: true,  kind: 'etag' },
    { name: 'GET /catalog/books/{bookId}',       method: 'GET',    path: '/catalog/books/{bookId}',       auth: true,  kind: 'etag' },
    { name: 'GET /catalog/books/{bookId}/availability', method: 'GET', path: '/catalog/books/{bookId}/availability', auth: true, kind: 'safeGet' },
    { name: 'GET /catalog/genres',               method: 'GET',    path: '/catalog/genres',               auth: true,  kind: 'etag' },
    { name: 'GET /me/loans',                     method: 'GET',    path: '/me/loans',                     auth: true,  kind: 'safeGet' },
    { name: 'GET /me/reservations',              method: 'GET',    path: '/me/reservations',              auth: true,  kind: 'safeGet' },
    { name: 'POST /reservations',                method: 'POST',   path: '/reservations',                 auth: true,  kind: 'conflict2',
        body: (ctx) => ({ book_id: ctx.bookId }), firstAny: true /* 1st may 201 or 422 (availability); 2nd identical must be rejected */ },
    { name: 'DELETE /reservations/{reservationId}', method: 'DELETE', path: '/reservations/{reservationId}', auth: true, kind: 'gone2' },
    { name: 'GET /me/wishlist',                  method: 'GET',    path: '/me/wishlist',                  auth: true,  kind: 'safeGet' },
    { name: 'POST /me/wishlist',                 method: 'POST',   path: '/me/wishlist',                  auth: true,  kind: 'write2xx',
        body: (ctx) => ({ book_id: ctx.bookId }) /* adding twice must not duplicate; both 2xx */ },
    { name: 'DELETE /me/wishlist/{bookId}',      method: 'DELETE', path: '/me/wishlist/{bookId}',         auth: true,  kind: 'gone2' },
    { name: 'POST /messages',                    method: 'POST',   path: '/messages',                     auth: true,  kind: 'write2xx',
        body: () => ({ messaggio: 'idem test message', oggetto: 'idem' }) },
    { name: 'GET /me/notifications',             method: 'GET',    path: '/me/notifications',             auth: true,  kind: 'safeGet' },
    { name: 'GET /me/push/prefs',                method: 'GET',    path: '/me/push/prefs',                auth: true,  kind: 'safeGet' },
    { name: 'PUT /me/push/prefs',                method: 'PUT',    path: '/me/push/prefs',                auth: true,  kind: 'write2xx',
        body: () => ({ loan_due: true, loan_overdue: true, reservation_ready: true, new_message: true, book_available: true }) },
    { name: 'POST /me/push/subscribe',           method: 'POST',   path: '/me/push/subscribe',            auth: true,  kind: 'write2xx',
        body: () => ({ provider: 'unifiedpush', endpoint: 'https://example.com/idem-push', public_key: 'k', auth: 'a' }) },
    { name: 'DELETE /me/push/subscribe',         method: 'DELETE', path: '/me/push/subscribe',            auth: true,  kind: 'write2xx' /* unsubscribe is idempotent: both 2xx */ },
];

// ─────────────────────────────────────────────────────────────────────────────

const ok2xx     = (s) => s >= 200 && s < 300;
const resolve   = (tpl, ctx) => tpl.replace(/\{(\w+)\}/g, (_, k) => String(ctx[k]));
const bodyOf    = (e, ctx) => (typeof e.body === 'function' ? e.body(ctx) : e.body);
const tokenFor  = (e, ctx) => (e.auth === 'throwaway' ? ctx.throwawayToken : e.auth === true ? ctx.token : null);

/**
 * The reusable two-call runner. Given a manifest entry + ctx, it issues exactly
 * two requests and asserts the entry's idempotency/ETag contract. New endpoints
 * reuse this verbatim — only the manifest row changes.
 */
async function runTwice(request, e, ctx) {
    const url   = `${API}${resolve(e.path, ctx)}`;
    const token = tokenFor(e, ctx);
    const body  = bodyOf(e, ctx);

    if (e.kind === 'etag') {
        const r1 = await call(request, e.method, url, { token, body });
        expect(r1.status(), `${e.name} #1`).toBe(200);
        const etag = r1.headers()['etag'];
        expect(etag, `${e.name} must emit an ETag`).toBeTruthy();
        const r2 = await call(request, e.method, url, { token, body, extraHeaders: { 'If-None-Match': etag } });
        expect(r2.status(), `${e.name} #2 (If-None-Match) → 304`).toBe(304);
        return;
    }
    if (e.kind === 'doc') {
        const r1 = await call(request, e.method, url, { token, body });
        const r2 = await call(request, e.method, url, { token, body });
        expect(r1.status(), `${e.name} #1`).toBe(200);
        expect(r2.status(), `${e.name} #2 (stable)`).toBe(r1.status());
        return;
    }
    if (e.kind === 'safeGet') {
        const r1 = await call(request, e.method, url, { token, body });
        const r2 = await call(request, e.method, url, { token, body });
        expect(ok2xx(r1.status()), `${e.name} #1 2xx (got ${r1.status()})`).toBe(true);
        expect(r2.status(), `${e.name} #2 same status`).toBe(r1.status());
        return;
    }
    if (e.kind === 'write2xx') {
        const r1 = await call(request, e.method, url, { token, body });
        const r2 = await call(request, e.method, url, { token, body });
        expect(ok2xx(r1.status()), `${e.name} #1 2xx (got ${r1.status()})`).toBe(true);
        expect(ok2xx(r2.status()), `${e.name} #2 idempotent 2xx (got ${r2.status()})`).toBe(true);
        return;
    }
    if (e.kind === 'conflict2') {
        const r1 = await call(request, e.method, url, { token, body });
        if (!e.firstAny) {
            expect(ok2xx(r1.status()), `${e.name} #1 2xx (got ${r1.status()})`).toBe(true);
        }
        const r2 = await call(request, e.method, url, { token, body });
        // The 2nd identical call must NOT succeed (conflict / validation / duplicate).
        expect(r2.status(), `${e.name} #2 rejected (>=400)`).toBeGreaterThanOrEqual(400);
        return;
    }
    if (e.kind === 'revoked2') {
        // 1st: the action succeeds and revokes the token. 2nd: the now-revoked
        // token is rejected by AppAuthMiddleware → 401.
        const r1 = await call(request, e.method, url, { token, body });
        expect(ok2xx(r1.status()), `${e.name} #1 2xx (got ${r1.status()})`).toBe(true);
        const r2 = await call(request, e.method, url, { token, body });
        expect(r2.status(), `${e.name} #2 token revoked → 401`).toBe(401);
        return;
    }
    if (e.kind === 'gone2') {
        const r1 = await call(request, e.method, url, { token, body });
        // 1st: either a successful delete (2xx) or already-absent (404).
        expect(ok2xx(r1.status()) || r1.status() === 404, `${e.name} #1 (2xx|404, got ${r1.status()})`).toBe(true);
        const r2 = await call(request, e.method, url, { token, body });
        expect(r2.status(), `${e.name} #2 gone → 404`).toBe(404);
        return;
    }
    throw new Error(`unknown kind '${e.kind}' for ${e.name}`);
}

// ─────────────────────────────────────────────────────────────────────────────

test.describe('Mobile API — two calls per endpoint (idempotency + ETag/304)', () => {
    /** @type {import('@playwright/test').Page} */
    let page;
    /** @type {Record<string, any>} */
    const ctx = {};

    test.beforeAll(async ({ browser, request }) => {
        page = await browser.newPage();
        await ensurePluginReady(page);

        // Test borrower with a known password hash for USER_PASS.
        let uid = parseInt(dbScalar(`SELECT id FROM utenti WHERE email='${USER_EMAIL}' LIMIT 1`) || '0', 10);
        if (uid === 0) {
            dbExec(`INSERT INTO utenti (nome, cognome, email, password, tipo_utente, stato, email_verificata, codice_tessera, created_at)
                    VALUES ('Idem','Test','${USER_EMAIL}','x','standard','attivo',1,'${USER_CARD}',NOW())`);
            uid = parseInt(dbScalar(`SELECT id FROM utenti WHERE email='${USER_EMAIL}' LIMIT 1`) || '0', 10);
            ctx.createdUser = true;
        }
        // Set the password to USER_PASS using PHP password_hash so /auth/login matches.
        const realHash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(USER_PASS)}, PASSWORD_DEFAULT);`], { encoding: 'utf-8' }).trim();
        dbExec(`UPDATE utenti SET password=${sqlStr(realHash)}, stato='attivo', email_verificata=1 WHERE id=${uid}`);
        ctx.userId = uid;

        // Fixtures: a non-deleted book.
        ctx.bookId = parseInt(dbScalar('SELECT id FROM libri WHERE deleted_at IS NULL ORDER BY id LIMIT 1') || '0', 10);

        // Primary token (used by all authed reads/writes).
        const login = await call(request, 'POST', `${API}/auth/login`, {
            body: { email: USER_EMAIL, password: USER_PASS, device_name: 'IdemPrimary', device_id: 'idem-primary', platform: 'test' },
        });
        expect(login.status(), 'primary login must succeed').toBe(200);
        ctx.token = (await login.json()).data.token;

        // Throwaway token for the logout gone2 test.
        const login2 = await call(request, 'POST', `${API}/auth/login`, {
            body: { email: USER_EMAIL, password: USER_PASS, device_name: 'IdemThrowaway', device_id: 'idem-throwaway', platform: 'test' },
        });
        ctx.throwawayToken = (await login2.json()).data.token;

        // A DEDICATED deletable device for DELETE /me/devices/{id} — separate from
        // the primary device that ctx.token authenticates with, so deleting it
        // never revokes the token the rest of the suite uses.
        await call(request, 'POST', `${API}/auth/login`, {
            body: { email: USER_EMAIL, password: USER_PASS, device_name: 'IdemDeletable', device_id: 'idem-deletable', platform: 'test' },
        });
        ctx.deviceId = parseInt(dbScalar(
            `SELECT id FROM mobile_app_tokens WHERE user_id=${ctx.userId} AND device_id='idem-deletable' AND revoked_at IS NULL ORDER BY id DESC LIMIT 1`
        ) || '0', 10);

        // A cancellable reservation to delete (gone2). classifyCancellable() treats
        // a prenotazioni row with stato='attiva' as a 'reservation'.
        try {
            dbExec(`INSERT INTO prenotazioni (utente_id, libro_id, stato, data_prenotazione, created_at)
                    VALUES (${ctx.userId}, ${ctx.bookId}, 'attiva', NOW(), NOW())`);
            ctx.reservationId = parseInt(dbScalar(`SELECT id FROM prenotazioni WHERE utente_id=${ctx.userId} ORDER BY id DESC LIMIT 1`) || '0', 10);
        } catch { ctx.reservationId = 0; }

        // Pre-add the wishlist book so DELETE /me/wishlist/{bookId} has something to remove on call #1.
        try { dbExec(`INSERT IGNORE INTO wishlist (utente_id, libro_id, created_at) VALUES (${ctx.userId}, ${ctx.bookId}, NOW())`); } catch {}
    });

    test.afterAll(async () => {
        try { dbExec(`DELETE FROM mobile_app_tokens WHERE user_id=${ctx.userId}`); } catch {}
        try { dbExec(`DELETE FROM mobile_push_subscriptions WHERE user_id=${ctx.userId}`); } catch {}
        try { dbExec(`DELETE FROM wishlist WHERE utente_id=${ctx.userId}`); } catch {}
        try { dbExec(`DELETE FROM prenotazioni WHERE utente_id=${ctx.userId}`); } catch {}
        // Only delete the account if THIS run created it.
        if (ctx.createdUser) {
            try { dbExec(`DELETE FROM utenti WHERE id=${ctx.userId}`); } catch {}
        }
        await page?.close();
    });

    // The guard: every exposed route (per the OpenAPI doc the app serves) MUST
    // have a manifest row. Adding an endpoint without a row fails here.
    test('manifest covers every exposed route (add-endpoint ⇒ add-one-row rule)', async ({ request }) => {
        const res = await call(request, 'GET', `${API}/openapi.json`, {});
        expect(res.status()).toBe(200);
        const doc = await res.json();
        // Normalize both sides: collapse any {param} to {} so the comparison is
        // independent of path-parameter naming (OpenAPI uses {id}/{book_id};
        // the manifest uses {deviceId}/{bookId}/… — same route, different label).
        const norm = (method, p) => `${method.toUpperCase()} ${p.replace(/\{[^}]+\}/g, '{}')}`;
        const documented = [];
        for (const [p, methods] of Object.entries(doc.paths || {})) {
            for (const m of Object.keys(methods)) documented.push(norm(m, p));
        }
        const covered = new Set(ENDPOINTS.map((e) => norm(e.method, e.path)));
        const missing = documented.filter((d) => !covered.has(d));
        // A documented route with no manifest row fails here — enforcing the
        // "add an endpoint ⇒ add exactly one manifest row" rule.
        expect(missing, `Documented endpoints missing a manifest row (add one row each): ${missing.join(', ')}`).toEqual([]);
    });

    // Data-driven: two calls per endpoint, contract enforced by the shared runner.
    for (const e of ENDPOINTS) {
        test(`2× ${e.name} [${e.kind}]`, async ({ request }) => {
            await runTwice(request, e, ctx);
        });
    }
});

// Minimal SQL string escaper for the few literal inserts above (test-only).
function sqlStr(v) { return "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'"; }

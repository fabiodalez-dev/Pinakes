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
const { flushCache } = require('./helpers/flush-cache');
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');

// A real Web Push subscription key pair (RFC 8291): registration refuses keys
// that could not encrypt a message.
const WEB_PUSH_KEYS = (() => {
    const ecdh = crypto.createECDH('prime256v1');
    ecdh.generateKeys();
    return { public_key: ecdh.getPublicKey().toString('base64url'), auth: crypto.randomBytes(16).toString('base64url') };
})();

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
async function call(request, method, fullPath, { token, body, extraHeaders, multipart } = {}) {
    const opts = { headers: headers(token, extraHeaders) };
    if (multipart) {
        delete opts.headers['Content-Type'];
        opts.multipart = multipart;
    } else if (body !== undefined && body !== null) opts.data = body;
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
const STAFF_EMAIL = `idem_staff_${RUN_ID}@pinakes.test`;
const STAFF_CARD  = `IDEMS${String(process.pid).padStart(6, '0')}`.slice(0, 20);
// 1x1 PNG and a minimal PDF for the archive upload rows.
const PNG_1PX = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
const TINY_PDF = Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n');
/** Multipart body of a one-row CSV import (Mobile API 1.7.0 rows). */
function importCsv(title) {
    return { file: { name: 'idem.csv', mimeType: 'text/csv', buffer: Buffer.from(`titolo;autori\n${title};Idem Author Import ${RUN_ID}\n`) }, kind: 'csv' };
}
/** A valid, run-unique ISBN-13 (978 prefix + mod-10 check digit). */
function isbn13FromRun() {
    const base = '978' + String(Date.now()).slice(-6) + String(process.pid).padStart(3, '0').slice(-3);
    let sum = 0;
    for (let i = 0; i < 12; i++) sum += Number(base[i]) * (i % 2 === 0 ? 1 : 3);
    return base + String((10 - (sum % 10)) % 10);
}

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
    ...['archives', 'desiderata'].flatMap(collection => ['', '/health', '/{id}'].map(suffix => ({
        name: `GET /${collection}${suffix}`, method: 'GET', path: `/${collection}${suffix === '/{id}' ? '/1' : suffix}`,
        route: `/${collection}${suffix}`, auth: true, kind: 'optionalGet',
    }))),
    { name: 'GET /desiderata/offers/{submission}', method: 'GET', path: '/desiderata/offers/00000000-0000-4000-8000-000000000001', route: '/desiderata/offers/{submission}', auth: true, kind: 'optionalGet' },
    { name: 'POST /desiderata/offers', method: 'POST', path: '/desiderata/offers', auth: true, kind: 'conflict2', firstAny: true,
      body: { submission_id: '00000000-0000-4000-8000-000000000001', title: 'Invalid proposal: no consent', consent: false } },

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
    { name: 'GET /auth/registration-fields',     method: 'GET',    path: '/auth/registration-fields',     auth: false, kind: 'doc' },
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
    { name: 'GET /catalog/languages',            method: 'GET',    path: '/catalog/languages',            auth: true,  kind: 'etag' },
    { name: 'GET /me/loans',                     method: 'GET',    path: '/me/loans',                     auth: true,  kind: 'safeGet' },
    { name: 'GET /me/movements',                 method: 'GET',    path: '/me/movements',                 auth: true,  kind: 'safeGet' },
    { name: 'GET /me/reservations',              method: 'GET',    path: '/me/reservations',              auth: true,  kind: 'safeGet' },
    { name: 'POST /reservations',                method: 'POST',   path: '/reservations',                 auth: true,  kind: 'conflict2',
        body: (ctx) => ({ book_id: ctx.bookId }), firstAny: true /* 1st may 201 or 422 (availability); 2nd identical must be rejected */ },
    { name: 'DELETE /reservations/{reservationId}', method: 'DELETE', path: '/reservations/{reservationId}', auth: true, kind: 'gone2' },
    { name: 'DELETE /loans/{loanId}',               method: 'DELETE', path: '/loans/{loanId}',               auth: true, kind: 'gone2' },
    { name: 'GET /me/wishlist',                  method: 'GET',    path: '/me/wishlist',                  auth: true,  kind: 'safeGet' },
    { name: 'POST /me/wishlist',                 method: 'POST',   path: '/me/wishlist',                  auth: true,  kind: 'write2xx',
        body: (ctx) => ({ book_id: ctx.bookId }) /* adding twice must not duplicate; both 2xx */ },
    { name: 'DELETE /me/wishlist/{bookId}',      method: 'DELETE', path: '/me/wishlist/{bookId}',         auth: true,  kind: 'gone2' },
    { name: 'GET /catalog/books/{bookId}/reviews', method: 'GET', path: '/catalog/books/{bookId}/reviews', auth: true, kind: 'safeGet' },
    { name: 'PUT /catalog/books/{bookId}/reviews', method: 'PUT',    path: '/catalog/books/{bookId}/reviews', auth: true,  kind: 'write2xx',
        body: () => ({ rating: 5, text: 'Idempotency probe.' }) /* upsert: twice must both be 2xx (setup seeds a returned loan for eligibility) */ },
    { name: 'DELETE /catalog/books/{bookId}/reviews', method: 'DELETE', path: '/catalog/books/{bookId}/reviews', auth: true, kind: 'gone2' },
    { name: 'GET /me/reviews',                   method: 'GET',    path: '/me/reviews',                    auth: true,  kind: 'safeGet' },
    { name: 'POST /messages',                    method: 'POST',   path: '/messages',                     auth: true,  kind: 'write2xx',
        body: () => ({ messaggio: 'idem test message', oggetto: 'idem' }) },
    { name: 'GET /me/notifications',             method: 'GET',    path: '/me/notifications',             auth: true,  kind: 'safeGet' },
    { name: 'GET /me/push/prefs',                method: 'GET',    path: '/me/push/prefs',                auth: true,  kind: 'safeGet' },
    { name: 'PUT /me/push/prefs',                method: 'PUT',    path: '/me/push/prefs',                auth: true,  kind: 'write2xx',
        body: () => ({ loan_due: true, loan_overdue: true, reservation_ready: true, new_message: true, book_available: true }) },
    { name: 'POST /me/push/subscribe',           method: 'POST',   path: '/me/push/subscribe',            auth: true,  kind: 'write2xx',
        body: () => ({ provider: 'unifiedpush', endpoint: 'https://example.com/idem-push', ...WEB_PUSH_KEYS }) },
    { name: 'DELETE /me/push/subscribe',         method: 'DELETE', path: '/me/push/subscribe',            auth: true,  kind: 'write2xx' /* unsubscribe is idempotent: both 2xx */ },

    // ── Mobile API 1.6.0: staff content area (tokenKey: the operator token) ──
    // Fixtures (book, throwaway book, author, publisher, bookcase) are created
    // in beforeAll through the API itself; everything carries RUN_ID and is
    // removed in afterAll.
    { name: 'GET /staff/lookups',                method: 'GET',    path: '/staff/lookups',                auth: true, tokenKey: 'staffToken', kind: 'etag' },
    { name: 'GET /staff/isbn/{isbn}',            method: 'GET',    path: '/staff/isbn/9780140328721',     route: '/staff/isbn/{isbn}', auth: true, tokenKey: 'staffToken', kind: 'safeGet',
        skipIf: (c) => !c.scraperStub /* without the E2E stub this would hit real scraping sources */ },
    { name: 'GET /staff/books',                  method: 'GET',    path: '/staff/books',                  auth: true, tokenKey: 'staffToken', kind: 'etag' },
    { name: 'POST /staff/books',                 method: 'POST',   path: '/staff/books',                  auth: true, tokenKey: 'staffToken', kind: 'conflict2',
        body: (c) => ({ title: `Idem staff book ${RUN_ID}`, isbn13: c.staffIsbn }) /* same ISBN twice: 201 then 409 duplicate */ },
    { name: 'GET /staff/books/{staffBookId}',    method: 'GET',    path: '/staff/books/{staffBookId}',    auth: true, tokenKey: 'staffToken', kind: 'etag' },
    { name: 'PUT /staff/books/{staffBookId}',    method: 'PUT',    path: '/staff/books/{staffBookId}',    auth: true, tokenKey: 'staffToken', kind: 'write2xx',
        body: () => ({ subtitle: 'Idempotent subtitle' }) },
    { name: 'POST /staff/books/{staffBookId}/copies', method: 'POST', path: '/staff/books/{staffBookId}/copies', auth: true, tokenKey: 'staffToken', kind: 'write2xx',
        body: () => ({ quantity: 1 }) /* each call adds one more copy: both 201 */ },
    { name: 'POST /staff/books/{staffBookId}/cover', method: 'POST', path: '/staff/books/{staffBookId}/cover', auth: true, tokenKey: 'staffToken', kind: 'write2xx',
        body: () => ({ cover_url: `${BASE}/uploads/copertine/placeholder.jpg` }) /* own-host cover: re-encoded copy, both 200 */ },
    { name: 'DELETE /staff/books/{staffDeleteBookId}', method: 'DELETE', path: '/staff/books/{staffDeleteBookId}', auth: true, tokenKey: 'staffToken', kind: 'gone2' },
    { name: 'GET /staff/authors',                method: 'GET',    path: '/staff/authors',                auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'GET /staff/authors/{staffAuthorId}', method: 'GET',   path: '/staff/authors/{staffAuthorId}', auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'POST /staff/authors',               method: 'POST',   path: '/staff/authors',                auth: true, tokenKey: 'staffToken', kind: 'conflict2',
        body: () => ({ name: `Idem Author New ${RUN_ID}` }) /* 2nd: 409 duplicate */ },
    { name: 'PUT /staff/authors/{staffAuthorId}', method: 'PUT',   path: '/staff/authors/{staffAuthorId}', auth: true, tokenKey: 'staffToken', kind: 'write2xx',
        body: () => ({ biography: 'Idempotent biography' }) },
    { name: 'GET /staff/publishers',             method: 'GET',    path: '/staff/publishers',             auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'GET /staff/publishers/{staffPublisherId}', method: 'GET', path: '/staff/publishers/{staffPublisherId}', auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'POST /staff/publishers',            method: 'POST',   path: '/staff/publishers',             auth: true, tokenKey: 'staffToken', kind: 'conflict2',
        body: () => ({ name: `Idem Publisher New ${RUN_ID}` }) },
    { name: 'PUT /staff/publishers/{staffPublisherId}', method: 'PUT', path: '/staff/publishers/{staffPublisherId}', auth: true, tokenKey: 'staffToken', kind: 'write2xx',
        body: () => ({ website: 'https://example.org/' }) },
    { name: 'GET /staff/genres',                 method: 'GET',    path: '/staff/genres',                 auth: true, tokenKey: 'staffToken', kind: 'etag' },
    { name: 'POST /staff/genres',                method: 'POST',   path: '/staff/genres',                 auth: true, tokenKey: 'staffToken', kind: 'write2xx',
        body: () => ({ name: `Idem Genre ${RUN_ID}` }) /* find-or-create: both 201 */ },
    { name: 'GET /staff/shelves',                method: 'GET',    path: '/staff/shelves',                auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'POST /staff/shelves',               method: 'POST',   path: '/staff/shelves',                auth: true, tokenKey: 'staffToken', kind: 'conflict2',
        body: (c) => ({ code: c.staffShelfCode2 }) },
    { name: 'POST /staff/shelves/{staffShelfId}/levels', method: 'POST', path: '/staff/shelves/{staffShelfId}/levels', auth: true, tokenKey: 'staffToken', kind: 'conflict2',
        body: () => ({ number: 7 }) },
    { name: 'GET /staff/shelves/next-position',  method: 'GET',    path: '/staff/shelves/next-position?shelf_id={staffShelfId}&level_id={staffLevelId}',
        route: '/staff/shelves/next-position', auth: true, tokenKey: 'staffToken', kind: 'safeGet' },

    // ── Mobile API 1.7.0: staff circulation desk (operator token) ──────────
    // Every loan / reservation fixture lives on its own run-unique title
    // (created through POST /staff/books in beforeAll), so the rows never
    // touch pre-existing circulation and afterAll removes them by RUN_ID.
    { name: 'GET /staff/circulation/summary',    method: 'GET',    path: '/staff/circulation/summary',    auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'GET /staff/loans',                  method: 'GET',    path: '/staff/loans?bucket=active',    route: '/staff/loans', auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'POST /staff/loans',                 method: 'POST',   path: '/staff/loans',                  auth: true, tokenKey: 'staffToken', kind: 'conflict2',
        body: (c) => ({ user_id: c.userId, book_id: c.circCreateBookId, immediate: true }) /* same borrower + title twice: 201 then 409 duplicate */ },
    { name: 'GET /staff/loans/{staffLoanId}',    method: 'GET',    path: '/staff/loans/{staffLoanId}',    auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'PUT /staff/loans/{staffLoanId}',    method: 'PUT',    path: '/staff/loans/{staffLoanId}',    auth: true, tokenKey: 'staffToken', kind: 'write2xx',
        body: (c) => ({ due_at: c.circDue }) },
    { name: 'POST /staff/loans/{id}/approve',    method: 'POST',   path: '/staff/loans/{circApproveLoanId}/approve', route: '/staff/loans/{id}/approve', auth: true, tokenKey: 'staffToken', kind: 'conflict2' /* 2nd: no longer pending → 409 */ },
    { name: 'POST /staff/loans/{id}/reject',     method: 'POST',   path: '/staff/loans/{circRejectLoanId}/reject',   route: '/staff/loans/{id}/reject', auth: true, tokenKey: 'staffToken', kind: 'conflict2',
        body: () => ({ reason: 'Idempotency probe' }) },
    { name: 'POST /staff/loans/{id}/pickup',     method: 'POST',   path: '/staff/loans/{circPickupLoanId}/pickup',   route: '/staff/loans/{id}/pickup', auth: true, tokenKey: 'staffToken', kind: 'conflict2' },
    { name: 'POST /staff/loans/{id}/cancel-pickup', method: 'POST', path: '/staff/loans/{circCancelPickupLoanId}/cancel-pickup', route: '/staff/loans/{id}/cancel-pickup', auth: true, tokenKey: 'staffToken', kind: 'conflict2' },
    { name: 'POST /staff/loans/{id}/return',     method: 'POST',   path: '/staff/loans/{circReturnLoanId}/return',   route: '/staff/loans/{id}/return', auth: true, tokenKey: 'staffToken', kind: 'conflict2',
        body: () => ({ outcome: 'restituito' }) /* 2nd: not_returnable */ },
    { name: 'POST /staff/loans/return-by-code',  method: 'POST',   path: '/staff/loans/return-by-code',   auth: true, tokenKey: 'staffToken', kind: 'conflict2',
        body: (c) => ({ code: c.circReturnCode }) /* 2nd: no active loan for the code → 404 */ },
    { name: 'POST /staff/loans/{id}/renew',      method: 'POST',   path: '/staff/loans/{circRenewLoanId}/renew',     route: '/staff/loans/{id}/renew', auth: true, tokenKey: 'staffToken', kind: 'conflict2'
        /* loans.max_renewals is 1 for this run: 2nd → 409 max_renewals */ },
    { name: 'POST /staff/loans/bulk-extend',     method: 'POST',   path: '/staff/loans/bulk-extend',      auth: true, tokenKey: 'staffToken', kind: 'write2xx',
        body: (c) => ({ ids: [c.staffLoanId], days: 1 }) },
    { name: 'POST /staff/loans/{id}/recall',     method: 'POST',   path: '/staff/loans/{staffLoanId}/recall', route: '/staff/loans/{id}/recall', auth: true, tokenKey: 'staffToken', kind: 'write2xx' /* not overdue: 200 {sent:false} both */ },
    { name: 'POST /staff/loans/bulk-recall',     method: 'POST',   path: '/staff/loans/bulk-recall',      auth: true, tokenKey: 'staffToken', kind: 'write2xx',
        body: (c) => ({ ids: [c.staffLoanId] }) },
    { name: 'GET /staff/loans/{id}/receipt.pdf', method: 'GET',    path: '/staff/loans/{staffLoanId}/receipt.pdf', route: '/staff/loans/{id}/receipt.pdf', auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'POST /staff/loans/{id}/receipt/email', method: 'POST', path: '/staff/loans/{staffLoanId}/receipt/email', route: '/staff/loans/{id}/receipt/email', auth: true, tokenKey: 'staffToken', kind: 'write2xx' /* SMTP failure is still 200 {sent:false} */ },
    { name: 'GET /staff/reservations',           method: 'GET',    path: '/staff/reservations',           auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'POST /staff/reservations',          method: 'POST',   path: '/staff/reservations',           auth: true, tokenKey: 'staffToken', kind: 'conflict2',
        body: (c) => ({ user_id: c.userId, book_id: c.circResvCreateBookId, reserved_at: c.circResvFrom, expires_at: c.circResvTo }) /* 2nd: duplicate */ },
    { name: 'GET /staff/reservations/{id}',      method: 'GET',    path: '/staff/reservations/{circReservationId}', route: '/staff/reservations/{id}', auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'PUT /staff/reservations/{id}',      method: 'PUT',    path: '/staff/reservations/{circReservationId}', route: '/staff/reservations/{id}', auth: true, tokenKey: 'staffToken', kind: 'write2xx',
        body: (c) => ({ expires_at: c.circResvTo }) },
    { name: 'POST /staff/reservations/{id}/cancel', method: 'POST', path: '/staff/reservations/{circCancelReservationId}/cancel', route: '/staff/reservations/{id}/cancel', auth: true, tokenKey: 'staffToken', kind: 'conflict2' },
    { name: 'GET /staff/books/{id}/queue',       method: 'GET',    path: '/staff/books/{circBookId}/queue', route: '/staff/books/{id}/queue', auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'GET /staff/books/{id}/availability', method: 'GET',   path: '/staff/books/{circBookId}/availability?user_id={userId}', route: '/staff/books/{id}/availability', auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'GET /staff/users',                  method: 'GET',    path: '/staff/users?q=Idem',           route: '/staff/users', auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'GET /staff/users/{id}',             method: 'GET',    path: '/staff/users/{userId}',         route: '/staff/users/{id}', auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'GET /staff/copies/by-code',         method: 'GET',    path: '/staff/copies/by-code?code={circCopyCode}', route: '/staff/copies/by-code', auth: true, tokenKey: 'staffToken', kind: 'safeGet' },

    // ── Mobile API 1.7.0: staff content tools (copies, series, merges, labels) ──
    { name: 'GET /staff/copies/{id}',            method: 'GET',    path: '/staff/copies/{staffCopyId}',   route: '/staff/copies/{id}', auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'PUT /staff/copies/{id}',            method: 'PUT',    path: '/staff/copies/{staffCopyId}',   route: '/staff/copies/{id}', auth: true, tokenKey: 'staffToken', kind: 'write2xx',
        body: () => ({ notes: 'Idempotent copy note' }) },
    { name: 'DELETE /staff/copies/{id}',         method: 'DELETE', path: '/staff/copies/{staffLostCopyId}', route: '/staff/copies/{id}', auth: true, tokenKey: 'staffToken', kind: 'gone2' /* fixture copy in perso, no history */ },
    { name: 'GET /staff/series',                 method: 'GET',    path: '/staff/series',                 auth: true, tokenKey: 'staffToken', kind: 'etag' },
    { name: 'POST /staff/series',                method: 'POST',   path: '/staff/series',                 auth: true, tokenKey: 'staffToken', kind: 'conflict2',
        body: () => ({ name: `Idem series new ${RUN_ID}` }) /* 2nd: duplicate */ },
    { name: 'GET /staff/series/suggest',         method: 'GET',    path: '/staff/series/suggest?q=Idem',  route: '/staff/series/suggest', auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'GET /staff/series/{id}',            method: 'GET',    path: '/staff/series/{seriesId}',      route: '/staff/series/{id}', auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'PUT /staff/series/{id}',            method: 'PUT',    path: '/staff/series/{seriesId}',      route: '/staff/series/{id}', auth: true, tokenKey: 'staffToken', kind: 'write2xx',
        body: () => ({ description: 'Idempotent description' }) },
    { name: 'POST /staff/series/{id}/rename',    method: 'POST',   path: '/staff/series/{seriesId}/rename', route: '/staff/series/{id}/rename', auth: true, tokenKey: 'staffToken', kind: 'conflict2', firstAny: true,
        body: (c) => ({ name: c.seriesOtherName, merge_if_exists: false }) /* existing name: both 409 duplicate */ },
    { name: 'POST /staff/series/{id}/books',     method: 'POST',   path: '/staff/series/{seriesId}/books', route: '/staff/series/{id}/books', auth: true, tokenKey: 'staffToken', kind: 'write2xx',
        body: (c) => ({ book_ids: [c.staffBookId] }) },
    { name: 'PUT /staff/series/{id}/books/{bookId}', method: 'PUT', path: '/staff/series/{seriesId}/books/{staffBookId}', route: '/staff/series/{id}/books/{bookId}', auth: true, tokenKey: 'staffToken', kind: 'write2xx',
        body: () => ({ series_number: '1' }) },
    { name: 'POST /staff/series/{id}/parent-work', method: 'POST', path: '/staff/series/{seriesId}/parent-work', route: '/staff/series/{id}/parent-work', auth: true, tokenKey: 'staffToken', kind: 'write2xx',
        body: () => ({ title: `Idem parent work ${RUN_ID}` }) /* each call creates one parent work: both 201 */ },
    { name: 'DELETE /staff/series/{id}/books/{bookId}', method: 'DELETE', path: '/staff/series/{seriesId}/books/{staffBookId}', route: '/staff/series/{id}/books/{bookId}', auth: true, tokenKey: 'staffToken', kind: 'write2xx' /* idempotent removal */ },
    { name: 'POST /staff/series/{id}/merge',     method: 'POST',   path: '/staff/series/{seriesMergeId}/merge', route: '/staff/series/{id}/merge', auth: true, tokenKey: 'staffToken', kind: 'conflict2',
        body: (c) => ({ target_id: c.seriesId }) /* 2nd: source gone → 404 */ },
    { name: 'DELETE /staff/series/{id}',         method: 'DELETE', path: '/staff/series/{seriesDeleteId}', route: '/staff/series/{id}', auth: true, tokenKey: 'staffToken', kind: 'gone2' },
    { name: 'GET /staff/authors/duplicates',     method: 'GET',    path: '/staff/authors/duplicates',     auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'POST /staff/authors/merge',         method: 'POST',   path: '/staff/authors/merge',          auth: true, tokenKey: 'staffToken', kind: 'conflict2',
        body: (c) => ({ ids: c.mergeAuthorIds }) /* 2nd: the merged-away id is gone → 409 merge_error */ },
    { name: 'POST /staff/publishers/merge',      method: 'POST',   path: '/staff/publishers/merge',       auth: true, tokenKey: 'staffToken', kind: 'conflict2',
        body: (c) => ({ ids: c.mergePublisherIds }) },
    { name: 'POST /staff/genres/{id}/merge',     method: 'POST',   path: '/staff/genres/{mergeGenreId}/merge', route: '/staff/genres/{id}/merge', auth: true, tokenKey: 'staffToken', kind: 'conflict2',
        body: (c) => ({ target_id: c.mergeGenreTargetId }) /* 2nd: source gone → 404 */ },
    { name: 'GET /staff/imports',                method: 'GET',    path: '/staff/imports',                auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'POST /staff/imports',               method: 'POST',   path: '/staff/imports',                auth: true, tokenKey: 'staffToken', kind: 'write2xx',
        multipart: () => importCsv(`Idem import upload ${RUN_ID}`) /* each upload is a new job: both 201 */ },
    { name: 'GET /staff/imports/example.csv',    method: 'GET',    path: '/staff/imports/example.csv',    auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'GET /staff/imports/history/{importId}/errors.csv', method: 'GET', path: '/staff/imports/history/{importLogId}/errors.csv', route: '/staff/imports/history/{importId}/errors.csv', auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'GET /staff/imports/{id}',           method: 'GET',    path: '/staff/imports/{importJobId}',  route: '/staff/imports/{id}', auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'POST /staff/imports/{id}/chunk',    method: 'POST',   path: '/staff/imports/{importJobId}/chunk', route: '/staff/imports/{id}/chunk', auth: true, tokenKey: 'staffToken', kind: 'conflict2',
        body: () => ({ start: 0 }) /* 1st completes the 1-row job; 2nd: 410 gone */ },
    { name: 'DELETE /staff/imports/{id}',        method: 'DELETE', path: '/staff/imports/{importCancelJobId}', route: '/staff/imports/{id}', auth: true, tokenKey: 'staffToken', kind: 'write2xx' /* cancel is idempotent */ },
    { name: 'GET /staff/exports/librarything.tsv', method: 'GET',  path: '/staff/exports/librarything.tsv', auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'GET /staff/covers/missing',         method: 'GET',    path: '/staff/covers/missing',         auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'POST /staff/books/{id}/cover/fetch', method: 'POST',  path: '/staff/books/{staffBookId}/cover/fetch', route: '/staff/books/{id}/cover/fetch', auth: true, tokenKey: 'staffToken', kind: 'write2xx' /* no ISBN: 200 reason=no_isbn both times */ },
    { name: 'POST /staff/covers/sync',           method: 'POST',   path: '/staff/covers/sync',            auth: true, tokenKey: 'staffToken', kind: 'write2xx',
        skipIf: () => !process.env.PINAKES_E2E_SLOW /* synchronous, minutes */ },
    { name: 'GET /staff/enrich',                 method: 'GET',    path: '/staff/enrich',                 auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'POST /staff/enrich/batch',          method: 'POST',   path: '/staff/enrich/batch',           auth: true, tokenKey: 'staffToken', kind: 'write2xx',
        skipIf: () => !process.env.PINAKES_E2E_SLOW /* synchronous, minutes */ },
    { name: 'PUT /staff/enrich/cron',            method: 'PUT',    path: '/staff/enrich/cron',            auth: true, tokenKey: 'staffToken', kind: 'write2xx',
        body: (c) => ({ enabled: c.enrichCron }) /* the current value: a no-op both times */ },
    { name: 'GET /staff/books/{id}/labels/book.pdf', method: 'GET', path: '/staff/books/{staffBookId}/labels/book.pdf', route: '/staff/books/{id}/labels/book.pdf', auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'GET /staff/books/{id}/labels/copies.pdf', method: 'GET', path: '/staff/books/{staffBookId}/labels/copies.pdf', route: '/staff/books/{id}/labels/copies.pdf', auth: true, tokenKey: 'staffToken', kind: 'safeGet' },
    { name: 'GET /staff/settings/labels',        method: 'GET',    path: '/staff/settings/labels',        auth: true, tokenKey: 'staffToken', kind: 'etag' },
    { name: 'PUT /staff/settings/labels',        method: 'PUT',    path: '/staff/settings/labels',        auth: true, tokenKey: 'staffToken', kind: 'write2xx',
        body: (c) => c.labelSettings /* the current values: a no-op both times */ },

    // ── Mobile API 1.7.0: staff work on plugin content (desiderata, archives) ──
    { name: 'GET /staff/desiderata/offers',     method: 'GET',    path: '/staff/desiderata/offers?status=all', route: '/staff/desiderata/offers', auth: true, tokenKey: 'staffToken', kind: 'safeGet', skipIf: (c) => !c.desiderataActive },
    { name: 'POST /staff/desiderata/offers/{id}/accept', method: 'POST', path: '/staff/desiderata/offers/{offerAcceptId}/accept', route: '/staff/desiderata/offers/{id}/accept', auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.desiderataActive /* accepting an accepted offer is a no-op */ },
    { name: 'POST /staff/desiderata/offers/{id}/reject', method: 'POST', path: '/staff/desiderata/offers/{offerRejectId}/reject', route: '/staff/desiderata/offers/{id}/reject', auth: true, tokenKey: 'staffToken', kind: 'conflict2', skipIf: (c) => !c.desiderataActive /* 2nd: offer_closed */ },
    { name: 'POST /staff/desiderata/offers/{id}/receive', method: 'POST', path: '/staff/desiderata/offers/{offerReceiveId}/receive', route: '/staff/desiderata/offers/{id}/receive', auth: true, tokenKey: 'staffToken', kind: 'conflict2', skipIf: (c) => !c.desiderataActive },
    { name: 'DELETE /staff/desiderata/offers/{id}', method: 'DELETE', path: '/staff/desiderata/offers/{offerDeleteId}', route: '/staff/desiderata/offers/{id}', auth: true, tokenKey: 'staffToken', kind: 'gone2', skipIf: (c) => !c.desiderataActive },
    { name: 'GET /staff/desiderata/wanted',     method: 'GET',    path: '/staff/desiderata/wanted',      auth: true, tokenKey: 'staffToken', kind: 'safeGet', skipIf: (c) => !c.desiderataActive },
    { name: 'POST /staff/desiderata/wanted/{bookId}/receive', method: 'POST', path: '/staff/desiderata/wanted/{wantedDirectId}/receive', route: '/staff/desiderata/wanted/{bookId}/receive', auth: true, tokenKey: 'staffToken', kind: 'conflict2', skipIf: (c) => !c.desiderataActive /* 2nd: not_wanted */ },
    { name: 'GET /staff/archives/lookups',      method: 'GET',    path: '/staff/archives/lookups',       auth: true, tokenKey: 'staffToken', kind: 'etag', skipIf: (c) => !c.archivesActive },
    { name: 'GET /staff/archives',              method: 'GET',    path: '/staff/archives',               auth: true, tokenKey: 'staffToken', kind: 'safeGet', skipIf: (c) => !c.archivesActive },
    { name: 'POST /staff/archives',             method: 'POST',   path: '/staff/archives',               auth: true, tokenKey: 'staffToken', kind: 'conflict2', skipIf: (c) => !c.archivesActive,
        body: () => ({ reference_code: `IDEM-NEW-${RUN_ID}`.slice(0, 60), level: 'item', constructed_title: `Idem unit new ${RUN_ID}` }) /* 2nd: duplicate */ },
    { name: 'GET /staff/archives/{id}',         method: 'GET',    path: '/staff/archives/{archiveId}',   route: '/staff/archives/{id}', auth: true, tokenKey: 'staffToken', kind: 'safeGet', skipIf: (c) => !c.archivesActive },
    { name: 'PUT /staff/archives/{id}',         method: 'PUT',    path: '/staff/archives/{archiveId}',   route: '/staff/archives/{id}', auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.archivesActive,
        body: () => ({ extent: 'Idempotent extent' }) },
    { name: 'POST /staff/archives/{id}/cover',  method: 'POST',   path: '/staff/archives/{archiveId}/cover', route: '/staff/archives/{id}/cover', auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.archivesActive,
        multipart: () => ({ cover: { name: 'c.png', mimeType: 'image/png', buffer: PNG_1PX } }) },
    { name: 'DELETE /staff/archives/{id}/cover', method: 'DELETE', path: '/staff/archives/{archiveId}/cover', route: '/staff/archives/{id}/cover', auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.archivesActive /* idempotent */ },
    { name: 'POST /staff/archives/{id}/documents', method: 'POST', path: '/staff/archives/{archiveId}/documents', route: '/staff/archives/{id}/documents', auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.archivesActive,
        multipart: () => ({ document: { name: 'idem.pdf', mimeType: 'application/pdf', buffer: TINY_PDF } }) /* each call attaches one more file */ },
    { name: 'DELETE /staff/archives/{id}/documents/{fileId}', method: 'DELETE', path: '/staff/archives/{archiveId}/documents/{archiveFileId}', route: '/staff/archives/{id}/documents/{fileId}', auth: true, tokenKey: 'staffToken', kind: 'gone2', skipIf: (c) => !c.archivesActive },
    { name: 'POST /staff/archives/{id}/authorities', method: 'POST', path: '/staff/archives/{archiveId}/authorities', route: '/staff/archives/{id}/authorities', auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.archivesActive,
        body: (c) => ({ authority_id: c.authorityId, role: 'creator' }) /* INSERT IGNORE: both 200 */ },
    { name: 'DELETE /staff/archives/{id}/authorities/{authorityId}', method: 'DELETE', path: '/staff/archives/{archiveId}/authorities/{authorityId}', route: '/staff/archives/{id}/authorities/{authorityId}', auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.archivesActive /* idempotent */ },
    { name: 'DELETE /staff/archives/{id}',      method: 'DELETE', path: '/staff/archives/{archiveDeleteId}', route: '/staff/archives/{id}', auth: true, tokenKey: 'staffToken', kind: 'gone2', skipIf: (c) => !c.archivesActive },
    { name: 'GET /staff/archives/authorities',  method: 'GET',    path: '/staff/archives/authorities',   auth: true, tokenKey: 'staffToken', kind: 'safeGet', skipIf: (c) => !c.archivesActive },
    { name: 'POST /staff/archives/authorities', method: 'POST',   path: '/staff/archives/authorities',   auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.archivesActive,
        body: () => ({ type: 'family', authorised_form: `Idem family ${RUN_ID}` }) /* no uniqueness on authorities: both 201 */ },
    { name: 'GET /staff/archives/authorities/{id}', method: 'GET', path: '/staff/archives/authorities/{authorityId}', route: '/staff/archives/authorities/{id}', auth: true, tokenKey: 'staffToken', kind: 'safeGet', skipIf: (c) => !c.archivesActive },
    { name: 'PUT /staff/archives/authorities/{id}', method: 'PUT', path: '/staff/archives/authorities/{authorityId}', route: '/staff/archives/authorities/{id}', auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.archivesActive,
        body: () => ({ history: 'Idempotent history' }) },
    { name: 'DELETE /staff/archives/authorities/{id}', method: 'DELETE', path: '/staff/archives/authorities/{authorityDeleteId}', route: '/staff/archives/authorities/{id}', auth: true, tokenKey: 'staffToken', kind: 'gone2', skipIf: (c) => !c.archivesActive },

    // ── Mobile API 1.7.0 Tier 2: periodicals desk, book club staff, FRBR works, digital ──
    { name: 'GET /periodicals/staff/scan-lookup', method: 'GET', path: '/periodicals/staff/scan-lookup?code=9770000000017', route: '/periodicals/staff/scan-lookup', auth: true, tokenKey: 'staffToken', kind: 'safeGet', skipIf: (c) => !c.periodicalsStaff },
    { name: 'POST /periodicals/staff/issues/{id}/receive', method: 'POST', path: '/periodicals/staff/issues/{issueReceiveId}/receive', route: '/periodicals/staff/issues/{id}/receive', auth: true, tokenKey: 'staffToken', kind: 'conflict2', skipIf: (c) => !c.periodicalsStaff /* 2nd: already received */ },
    { name: 'POST /periodicals/staff/issues/{id}/claim', method: 'POST', path: '/periodicals/staff/issues/{issueClaimId}/claim', route: '/periodicals/staff/issues/{id}/claim', auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.periodicalsStaff /* a second reminder is ordinary practice */ },
    { name: 'POST /periodicals/staff/years/{id}/issues', method: 'POST', path: '/periodicals/staff/years/{periodicalYearIdStaff}/issues', route: '/periodicals/staff/years/{id}/issues', auth: true, tokenKey: 'staffToken', kind: 'conflict2', skipIf: (c) => !c.periodicalsStaff,
        body: () => ({ number: 'Idem-new' }) /* 2nd: same number → 422 */ },
    { name: 'POST /periodicals/staff/{id}/years', method: 'POST', path: '/periodicals/staff/{periodicalStaffId}/years', route: '/periodicals/staff/{id}/years', auth: true, tokenKey: 'staffToken', kind: 'conflict2', skipIf: (c) => !c.periodicalsStaff,
        body: () => ({ year: 2030 }) /* 2nd: same year → 422 */ },
    { name: 'GET /periodicals/staff/subscriptions', method: 'GET', path: '/periodicals/staff/subscriptions', auth: true, tokenKey: 'staffToken', kind: 'safeGet', skipIf: (c) => !c.periodicalsStaff },
    { name: 'POST /periodicals/staff/subscriptions', method: 'POST', path: '/periodicals/staff/subscriptions', auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.periodicalsStaff,
        body: (c) => ({ periodical_id: c.periodicalStaffId, supplier: 'Idem supplier' }) /* no uniqueness: both 201 */ },
    { name: 'PUT /periodicals/staff/subscriptions/{id}', method: 'PUT', path: '/periodicals/staff/subscriptions/{subscriptionId}', route: '/periodicals/staff/subscriptions/{id}', auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.periodicalsStaff,
        body: () => ({ notes: 'Idempotent note' }) },
    { name: 'GET /bookclub/staff/clubs',        method: 'GET',    path: '/bookclub/staff/clubs',         auth: true, tokenKey: 'staffToken', kind: 'safeGet', skipIf: (c) => !c.bookclubStaff },
    { name: 'POST /bookclub/staff/clubs',       method: 'POST',   path: '/bookclub/staff/clubs',         auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.bookclubStaff,
        body: () => ({ name: `Idem club new ${RUN_ID}` }) /* unique slugs: both 201 */ },
    { name: 'GET /bookclub/staff/clubs/{id}',   method: 'GET',    path: '/bookclub/staff/clubs/{clubId}', route: '/bookclub/staff/clubs/{id}', auth: true, tokenKey: 'staffToken', kind: 'safeGet', skipIf: (c) => !c.bookclubStaff },
    { name: 'PUT /bookclub/staff/clubs/{id}',   method: 'PUT',    path: '/bookclub/staff/clubs/{clubId}', route: '/bookclub/staff/clubs/{id}', auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.bookclubStaff,
        body: () => ({ description: 'Idempotent description' }) },
    { name: 'PUT /bookclub/staff/clubs/{id}/workflow', method: 'PUT', path: '/bookclub/staff/clubs/{clubId}/workflow', route: '/bookclub/staff/clubs/{id}/workflow', auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.bookclubStaff,
        body: () => ({ states: [{ key: 'a', label: 'A' }, { key: 'b', label: 'B' }] }) },
    { name: 'GET /bookclub/staff/clubs/{id}/members', method: 'GET', path: '/bookclub/staff/clubs/{clubId}/members', route: '/bookclub/staff/clubs/{id}/members', auth: true, tokenKey: 'staffToken', kind: 'safeGet', skipIf: (c) => !c.bookclubStaff },
    { name: 'POST /bookclub/staff/clubs/{id}/members', method: 'POST', path: '/bookclub/staff/clubs/{clubId}/members', route: '/bookclub/staff/clubs/{id}/members', auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.bookclubStaff,
        body: (c) => ({ user_id: c.userId, role: 'member' }) /* upsert: both 201 */ },
    { name: 'PUT /bookclub/staff/clubs/{id}/members/{memberId}', method: 'PUT', path: '/bookclub/staff/clubs/{clubId}/members/{clubMemberId}', route: '/bookclub/staff/clubs/{id}/members/{memberId}', auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.bookclubStaff,
        body: () => ({ role: 'moderator' }) },
    { name: 'DELETE /bookclub/staff/clubs/{id}', method: 'DELETE', path: '/bookclub/staff/clubs/{clubDeleteId}', route: '/bookclub/staff/clubs/{id}', auth: true, tokenKey: 'staffToken', kind: 'gone2', skipIf: (c) => !c.bookclubStaff },
    { name: 'GET /staff/works',                 method: 'GET',    path: '/staff/works?q=Idem',           route: '/staff/works', auth: true, tokenKey: 'staffToken', kind: 'safeGet', skipIf: (c) => !c.frbrActive },
    { name: 'POST /staff/works',                method: 'POST',   path: '/staff/works',                  auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.frbrActive,
        body: () => ({ uniform_title: `Idem work new ${RUN_ID}` }) /* no uniqueness: both 201 */ },
    { name: 'GET /staff/works/{id}',            method: 'GET',    path: '/staff/works/{workId}',         route: '/staff/works/{id}', auth: true, tokenKey: 'staffToken', kind: 'safeGet', skipIf: (c) => !c.frbrActive },
    { name: 'PUT /staff/works/{id}',            method: 'PUT',    path: '/staff/works/{workId}',         route: '/staff/works/{id}', auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.frbrActive,
        body: () => ({ notes: 'Idempotent note' }) },
    { name: 'GET /staff/works/{id}/expressions', method: 'GET',   path: '/staff/works/{workId}/expressions', route: '/staff/works/{id}/expressions', auth: true, tokenKey: 'staffToken', kind: 'safeGet', skipIf: (c) => !c.frbrActive },
    { name: 'POST /staff/works/{id}/expressions', method: 'POST', path: '/staff/works/{workId}/expressions', route: '/staff/works/{id}/expressions', auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.frbrActive,
        body: () => ({ type: 'traduzione', language: 'eng' }) },
    { name: 'PUT /staff/works/expressions/{id}', method: 'PUT',   path: '/staff/works/expressions/{expressionId}', route: '/staff/works/expressions/{id}', auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.frbrActive,
        body: () => ({ title: 'Idempotent' }) },
    { name: 'DELETE /staff/works/expressions/{id}', method: 'DELETE', path: '/staff/works/expressions/{expressionDeleteId}', route: '/staff/works/expressions/{id}', auth: true, tokenKey: 'staffToken', kind: 'gone2', skipIf: (c) => !c.frbrActive },
    { name: 'POST /staff/books/{id}/work',      method: 'POST',   path: '/staff/books/{staffBookId}/work', route: '/staff/books/{id}/work', auth: true, tokenKey: 'staffToken', kind: 'write2xx', skipIf: (c) => !c.frbrActive,
        body: (c) => ({ work_id: c.workId }) },
    { name: 'DELETE /staff/works/{id}',         method: 'DELETE', path: '/staff/works/{workDeleteId}',   route: '/staff/works/{id}', auth: true, tokenKey: 'staffToken', kind: 'gone2', skipIf: (c) => !c.frbrActive },
    { name: 'POST /staff/books/{id}/digital',   method: 'POST',   path: '/staff/books/{staffBookId}/digital', route: '/staff/books/{id}/digital', auth: true, tokenKey: 'staffToken', kind: 'conflict2', firstAny: true, skipIf: (c) => !c.digitalActive,
        multipart: () => ({ file: { name: 'fake.pdf', mimeType: 'application/pdf', buffer: Buffer.from('not a pdf') }, digital_type: 'ebook' }) /* content check refuses both: nothing stored */ },

    // ── Mobile API 1.6.0: offline sync + personal calendar (borrower token) ──
    { name: 'GET /catalog/sync',                 method: 'GET',    path: '/catalog/sync?limit=5',         route: '/catalog/sync', auth: true, kind: 'safeGet' },
    { name: 'GET /calendar/feed/{token}.ics',    method: 'GET',    path: '/calendar/feed/{calendarToken}.ics', route: '/calendar/feed/{token}.ics', auth: false, kind: 'etag' },
    { name: 'GET /me/calendar',                  method: 'GET',    path: '/me/calendar',                  auth: true, kind: 'safeGet' },
    { name: 'POST /me/calendar/token',           method: 'POST',   path: '/me/calendar/token',            auth: true, kind: 'write2xx' /* rotation: both 201 */ },
    { name: 'DELETE /me/calendar/token',         method: 'DELETE', path: '/me/calendar/token',            auth: true, kind: 'write2xx' /* revoke is idempotent: both 200 */ },

    // ── Book Club bridge (/api/v1/bookclub, mounted by the book-club plugin) ──
    // skipIf: the plugin is optional — rows only run when the bridge answers
    // (probed in beforeAll via /bookclub/health) and the referenced data exists.
    // The openapi guard stays coherent: the bridge documents itself through the
    // 'mobile_api.openapi' filter only while active, so paths and rows
    // appear/disappear together. Placeholders resolve from ctx like {bookId}.
    { name: 'GET /bookclub/health',              method: 'GET',    path: '/bookclub/health',              auth: false, kind: 'doc',      skipIf: (c) => !c.bookclubActive },
    { name: 'GET /bookclub/clubs',               method: 'GET',    path: '/bookclub/clubs',               auth: true,  kind: 'safeGet',  skipIf: (c) => !c.bookclubActive },
    { name: 'GET /bookclub/clubs/{bookclubSlug}', method: 'GET',   path: '/bookclub/clubs/{bookclubSlug}', auth: true, kind: 'safeGet',  skipIf: (c) => !c.bookclubActive || !c.bookclubSlug },
    { name: 'GET /bookclub/me/dashboard',        method: 'GET',    path: '/bookclub/me/dashboard',        auth: true,  kind: 'safeGet',  skipIf: (c) => !c.bookclubActive },
    { name: 'POST /bookclub/clubs/{slug}/join',  method: 'POST',   path: '/bookclub/clubs/{bookclubSlug}/join', auth: true, kind: 'write2xx', skipIf: (c) => !c.bookclubActive || !c.bookclubSlug
        /* re-join of an active member is a no-op 2xx */ },
    { name: 'POST /bookclub/clubs/{slug}/proposals', method: 'POST', path: '/bookclub/clubs/{bookclubSlug}/proposals', auth: true, kind: 'conflict2', skipIf: (c) => !c.bookclubActive || !c.bookclubSlug || !c.bookId,
        body: (c) => ({ libro_id: c.bookId }), firstAny: true /* proposing may be closed/duplicate -> 1st can be 4xx; 2nd identical must be >= 400 */ },
    { name: 'POST /bookclub/clubs/{slug}/polls/{pollId}/vote', method: 'POST', path: '/bookclub/clubs/{bookclubSlug}/polls/{bookclubPollId}/vote', auth: true, kind: 'write2xx', skipIf: (c) => !c.bookclubActive || !c.bookclubPollId,
        body: (c) => ({ options: [c.bookclubOptionId] }) /* re-vote replaces the ballot: both 2xx */ },
    { name: 'POST /bookclub/clubs/{slug}/meetings/{meetingId}/rsvp', method: 'POST', path: '/bookclub/clubs/{bookclubSlug}/meetings/{bookclubMeetingId}/rsvp', auth: true, kind: 'write2xx', skipIf: (c) => !c.bookclubActive || !c.bookclubMeetingId,
        body: () => ({ response: 'no' }) /* 'no' never hits seat gating: both 2xx */ },
    { name: 'POST /bookclub/clubs/{slug}/books/{clubBookId}/progress', method: 'POST', path: '/bookclub/clubs/{bookclubSlug}/books/{bookclubClubBookId}/progress', auth: true, kind: 'write2xx', skipIf: (c) => !c.bookclubActive || !c.bookclubClubBookId,
        body: () => ({ percent: 10 }) /* upsert: both 2xx */ },

    // ── Emeroteca bridge (/api/v1/periodicals, mounted by the emeroteca plugin) ──
    // skipIf: optional plugin — rows only run when the bridge answers (probed
    // in beforeAll via /periodicals/health, authed) and the demo data exists.
    { name: 'GET /periodicals/articles', method: 'GET', path: '/periodicals/articles', auth: true, kind: 'etag', skipIf: (c) => !c.periodicalsActive },
    { name: 'GET /periodicals/articles/{articleId}', method: 'GET', path: '/periodicals/articles/{periodicalArticleId}', auth: true, kind: 'etag', skipIf: (c) => !c.periodicalsActive || !c.periodicalArticleId },
    { name: 'GET /periodicals/health',               method: 'GET', path: '/periodicals/health',                     auth: true, kind: 'safeGet', skipIf: (c) => !c.periodicalsActive },
    { name: 'GET /periodicals',                      method: 'GET', path: '/periodicals',                            auth: true, kind: 'etag',    skipIf: (c) => !c.periodicalsActive },
    { name: 'GET /periodicals/{periodicalId}',       method: 'GET', path: '/periodicals/{periodicalId}',             auth: true, kind: 'etag',    skipIf: (c) => !c.periodicalsActive || !c.periodicalId },
    { name: 'GET /periodicals/years/{yearId}/issues', method: 'GET', path: '/periodicals/years/{periodicalYearId}/issues', auth: true, kind: 'etag', skipIf: (c) => !c.periodicalsActive || !c.periodicalYearId },
    { name: 'GET /periodicals/issues/{issueId}',     method: 'GET', path: '/periodicals/issues/{periodicalIssueId}', auth: true, kind: 'etag',    skipIf: (c) => !c.periodicalsActive || !c.periodicalIssueId },
];

// ─────────────────────────────────────────────────────────────────────────────

const ok2xx     = (s) => s >= 200 && s < 300;
const resolve   = (tpl, ctx) => tpl.replace(/\{(\w+)\}/g, (_, k) => String(ctx[k]));
const bodyOf    = (e, ctx) => (typeof e.body === 'function' ? e.body(ctx) : e.body);
const tokenFor  = (e, ctx) => (e.auth === 'throwaway' ? ctx.throwawayToken : e.auth === true ? ctx[e.tokenKey || 'token'] : null);

/**
 * The reusable two-call runner. Given a manifest entry + ctx, it issues exactly
 * two requests and asserts the entry's idempotency/ETag contract. New endpoints
 * reuse this verbatim — only the manifest row changes.
 */
async function runTwice(request, e, ctx) {
    const url   = `${API}${resolve(e.path, ctx)}`;
    const token = tokenFor(e, ctx);
    const body  = bodyOf(e, ctx);
    const multipart = typeof e.multipart === 'function' ? e.multipart(ctx) : e.multipart;

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
    if (e.kind === 'optionalGet') {
        const anonymous = await call(request, e.method, url);
        expect(anonymous.status()).toBe(401);
        const r1 = await call(request, e.method, url, { token, body });
        const r2 = await call(request, e.method, url, { token, body });
        expect([200, 404]).toContain(r1.status());
        expect(r2.status()).toBe(r1.status());
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
        const r1 = await call(request, e.method, url, { token, body, multipart });
        const r2 = await call(request, e.method, url, { token, body, multipart });
        expect(ok2xx(r1.status()), `${e.name} #1 2xx (got ${r1.status()})`).toBe(true);
        expect(ok2xx(r2.status()), `${e.name} #2 idempotent 2xx (got ${r2.status()})`).toBe(true);
        return;
    }
    if (e.kind === 'conflict2') {
        const r1 = await call(request, e.method, url, { token, body, multipart });
        if (!e.firstAny) {
            expect(ok2xx(r1.status()), `${e.name} #1 2xx (got ${r1.status()})`).toBe(true);
        }
        const r2 = await call(request, e.method, url, { token, body, multipart });
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
            dbExec(`INSERT INTO utenti (nome, cognome, email, password, telefono, indirizzo, tipo_utente, stato, email_verificata, codice_tessera, created_at)
                    VALUES ('Idem','Test','${USER_EMAIL}','x','000','E2E address','standard','attivo',1,'${USER_CARD}',NOW())`);
            uid = parseInt(dbScalar(`SELECT id FROM utenti WHERE email='${USER_EMAIL}' LIMIT 1`) || '0', 10);
            ctx.createdUser = true;
        }
        // Set the password to USER_PASS using PHP password_hash so /auth/login matches.
        const realHash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(USER_PASS)}, PASSWORD_DEFAULT);`], { encoding: 'utf-8' }).trim();
        dbExec(`UPDATE utenti
                   SET password=${sqlStr(realHash)}, telefono='000', indirizzo='E2E address',
                       stato='attivo', email_verificata=1
                 WHERE id=${uid}`);
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

        // Dedicated pending loan for the unambiguous Mobile API 1.4.4 route.
        // Prefer a different title from the reservation fixture so duplicate
        // circulation guards cannot pre-empt the setup.
        try {
            const loanBookId = parseInt(dbScalar(
                `SELECT id FROM libri WHERE deleted_at IS NULL AND id != ${ctx.bookId} ORDER BY id LIMIT 1`
            ) || String(ctx.bookId), 10);
            dbExec(`INSERT INTO prestiti
                        (utente_id, libro_id, data_prestito, data_scadenza, stato, attivo, created_at)
                    VALUES (${ctx.userId}, ${loanBookId}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'pendente', 0, NOW())`);
            ctx.loanId = parseInt(dbScalar(
                `SELECT id FROM prestiti WHERE utente_id=${ctx.userId} AND stato='pendente' ORDER BY id DESC LIMIT 1`
            ) || '0', 10);
        } catch { ctx.loanId = 0; }


        // Pre-add the wishlist book so DELETE /me/wishlist/{bookId} has something to remove on call #1.
        try { dbExec(`INSERT IGNORE INTO wishlist (utente_id, libro_id) VALUES (${ctx.userId}, ${ctx.bookId})`); } catch {}

        // Reviews eligibility: PUT /catalog/books/{id}/reviews requires the user
        // to have borrowed the title (prestiti stato IN restituito/in_corso). Seed
        // a RETURNED loan (attivo=0, no copia) — inert for the occupancy triggers.
        try {
            dbExec(`INSERT INTO prestiti (utente_id, libro_id, data_prestito, data_scadenza, data_restituzione, stato, attivo)
                    SELECT ${ctx.userId}, ${ctx.bookId}, DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_SUB(CURDATE(), INTERVAL 16 DAY), DATE_SUB(CURDATE(), INTERVAL 16 DAY), 'restituito', 0
                    WHERE NOT EXISTS (SELECT 1 FROM prestiti WHERE utente_id=${ctx.userId} AND libro_id=${ctx.bookId} AND stato='restituito')`);
        } catch {}

        // ── Mobile API 1.6.0 fixtures ───────────────────────────────────────
        // An operator account (tipo_utente='staff') for the /staff rows; the
        // borrower above keeps proving the borrower contract.
        let staffUid = parseInt(dbScalar(`SELECT id FROM utenti WHERE email='${STAFF_EMAIL}' LIMIT 1`) || '0', 10);
        if (staffUid === 0) {
            dbExec(`INSERT INTO utenti (nome, cognome, email, password, telefono, indirizzo, tipo_utente, stato, email_verificata, codice_tessera, created_at)
                    VALUES ('Idem','Staff','${STAFF_EMAIL}',${sqlStr(realHash)},'000','E2E address','staff','attivo',1,'${STAFF_CARD}',NOW())`);
            staffUid = parseInt(dbScalar(`SELECT id FROM utenti WHERE email='${STAFF_EMAIL}' LIMIT 1`) || '0', 10);
            ctx.createdStaff = true;
        }
        ctx.staffUserId = staffUid;
        const staffLogin = await call(request, 'POST', `${API}/auth/login`, {
            body: { email: STAFF_EMAIL, password: USER_PASS, device_name: 'IdemStaff', device_id: 'idem-staff', platform: 'test' },
        });
        expect(staffLogin.status(), 'staff login must succeed').toBe(200);
        ctx.staffToken = (await staffLogin.json()).data.token;
        const staffPost = async (route, body) => {
            const r = await call(request, 'POST', `${API}${route}`, { token: ctx.staffToken, body });
            expect(r.status(), `fixture POST ${route}`).toBe(201);
            return (await r.json()).data;
        };
        ctx.staffIsbn = isbn13FromRun();
        ctx.staffBookId = (await staffPost('/staff/books', { title: `Idem staff fixture ${RUN_ID}`, copies: 1 })).id;
        ctx.staffDeleteBookId = (await staffPost('/staff/books', { title: `Idem staff throwaway ${RUN_ID}` })).id;
        ctx.staffAuthorId = (await staffPost('/staff/authors', { name: `Idem Author ${RUN_ID}` })).id;
        ctx.staffPublisherId = (await staffPost('/staff/publishers', { name: `Idem Publisher ${RUN_ID}` })).id;
        const shelf = await staffPost('/staff/shelves', { code: `I${String(process.pid).slice(-4)}${String(Date.now()).slice(-6)}` });
        ctx.staffShelfId = shelf.id;
        ctx.staffShelfCode = shelf.code;
        ctx.staffShelfCode2 = `J${String(process.pid).slice(-4)}${String(Date.now()).slice(-6)}`;
        ctx.staffLevelId = (await staffPost(`/staff/shelves/${ctx.staffShelfId}/levels`, { number: 1 })).id;
        try {
            const probe = await call(request, 'GET', `${API}/staff/isbn/9780140328721`, { token: ctx.staffToken });
            ctx.scraperStub = probe.status() === 200 && (await probe.json())?.data?.preview?.title === 'Fantastic Mr. Fox';
        } catch { ctx.scraperStub = false; }
        // ── Mobile API 1.7.0 circulation fixtures ───────────────────────────
        // One run-unique title per loan/reservation fixture (1 copy each).
        const summary = await call(request, 'GET', `${API}/staff/circulation/summary`, { token: ctx.staffToken });
        expect(summary.status(), 'circulation summary fixture').toBe(200);
        const today = (await summary.json()).data.today;
        const day = (offset) => { const d = new Date(`${today}T12:00:00Z`); d.setUTCDate(d.getUTCDate() + offset); return d.toISOString().slice(0, 10); };
        ctx.circDue = day(25);
        ctx.circResvFrom = day(60);
        ctx.circResvTo = day(70);
        const circBook = async (label) => (await staffPost('/staff/books', { title: `Idem circ ${label} ${RUN_ID}`, copies: 1 })).id;
        const staffLoan = async (bookId, immediate) => (await staffPost('/staff/loans', { user_id: ctx.userId, book_id: bookId, immediate })).id;
        ctx.circBookId = await circBook('main');
        ctx.staffLoanId = await staffLoan(ctx.circBookId, true);
        ctx.circCopyCode = dbScalar(`SELECT numero_inventario FROM copie WHERE libro_id=${ctx.circBookId} ORDER BY id LIMIT 1`);
        ctx.circCreateBookId = await circBook('create');
        for (const key of ['Approve', 'Reject']) {
            const bookId = await circBook(key.toLowerCase());
            dbExec(`INSERT INTO prestiti (utente_id, libro_id, data_prestito, data_scadenza, stato, attivo, origine, created_at)
                    VALUES (${ctx.userId}, ${bookId}, '${today}', '${day(14)}', 'pendente', 0, 'richiesta', NOW())`);
            ctx[`circ${key}LoanId`] = parseInt(dbScalar(`SELECT MAX(id) FROM prestiti WHERE libro_id=${bookId}`) || '0', 10);
        }
        ctx.circPickupLoanId = await staffLoan(await circBook('pickup'), false);
        ctx.circCancelPickupLoanId = await staffLoan(await circBook('cancelpickup'), false);
        ctx.circReturnLoanId = await staffLoan(await circBook('return'), true);
        const byCodeBook = await circBook('bycode');
        await staffLoan(byCodeBook, true);
        ctx.circReturnCode = dbScalar(`SELECT numero_inventario FROM copie WHERE libro_id=${byCodeBook} ORDER BY id LIMIT 1`);
        ctx.circRenewLoanId = await staffLoan(await circBook('renew'), true);
        ctx.circResvCreateBookId = await circBook('resvcreate');
        const resv = async (bookId) => (await staffPost('/staff/reservations', { user_id: ctx.userId, book_id: bookId, reserved_at: ctx.circResvFrom, expires_at: ctx.circResvTo })).id;
        ctx.circReservationId = await resv(await circBook('resv'));
        ctx.circCancelReservationId = await resv(await circBook('resvcancel'));
        // ── Mobile API 1.7.0 content-tool fixtures ─────────────────────────
        ctx.staffCopyId = parseInt(dbScalar(`SELECT id FROM copie WHERE libro_id=${ctx.staffBookId} ORDER BY id LIMIT 1`) || '0', 10);
        const lost = await staffPost(`/staff/books/${ctx.staffBookId}/copies`, { quantity: 1, status: 'perso' });
        ctx.staffLostCopyId = lost.created[0].id;
        const series = async (name) => (await staffPost('/staff/series', { name: `${name} ${RUN_ID}` })).id;
        ctx.seriesId = await series('Idem series');
        ctx.seriesOtherName = `Idem series other ${RUN_ID}`;
        await series('Idem series other');
        ctx.seriesMergeId = await series('Idem series merge');
        ctx.seriesDeleteId = await series('Idem series delete');
        ctx.mergeAuthorIds = [
            (await staffPost('/staff/authors', { name: `Idem merge A ${RUN_ID}` })).id,
            (await staffPost('/staff/authors', { name: `Idem merge B ${RUN_ID}` })).id,
        ];
        ctx.mergePublisherIds = [
            (await staffPost('/staff/publishers', { name: `Idem merge P1 ${RUN_ID}` })).id,
            (await staffPost('/staff/publishers', { name: `Idem merge P2 ${RUN_ID}` })).id,
        ];
        ctx.mergeGenreId = (await staffPost('/staff/genres', { name: `Idem genre src ${RUN_ID}` })).id;
        ctx.mergeGenreTargetId = (await staffPost('/staff/genres', { name: `Idem genre dst ${RUN_ID}` })).id;
        const importJob = async (title) => {
            const r = await call(request, 'POST', `${API}/staff/imports`, { token: ctx.staffToken, multipart: importCsv(title) });
            expect(r.status(), 'import upload fixture').toBe(201);
            return (await r.json()).data.id;
        };
        ctx.importJobId = await importJob(`Idem import chunk ${RUN_ID}`);
        ctx.importCancelJobId = await importJob(`Idem import cancel ${RUN_ID}`);
        const doneJob = await importJob(`Idem import done ${RUN_ID}`);
        const done = await call(request, 'POST', `${API}/staff/imports/${doneJob}/chunk`, { token: ctx.staffToken, body: {} });
        expect(done.status(), 'import chunk fixture').toBe(200);
        ctx.importLogId = dbScalar(`SELECT import_id FROM import_logs WHERE user_id=${ctx.staffUserId} ORDER BY started_at DESC LIMIT 1`) || 'missing';
        const enrich = await call(request, 'GET', `${API}/staff/enrich`, { token: ctx.staffToken });
        ctx.enrichCron = (await enrich.json()).data.cron_enabled;
        // Plugin content (only when the plugins are active).
        ctx.desiderataActive = dbScalar("SELECT is_active FROM plugins WHERE name='desiderata'") === '1';
        if (ctx.desiderataActive) {
            const wanted = (label) => { dbExec(`INSERT INTO libri (titolo, is_desiderata, copie_totali, copie_disponibili, created_at, updated_at) VALUES ('Idem wanted ${label} ${RUN_ID}', 1, 0, 0, NOW(), NOW())`); return parseInt(dbScalar(`SELECT MAX(id) FROM libri WHERE titolo='Idem wanted ${label} ${RUN_ID}'`), 10); };
            const offer = (label, bookId) => { dbExec(`INSERT INTO desiderata_offers (book_id, donor_name, donor_email, title, notes, status) VALUES (${bookId || 'NULL'}, 'Idem donor', 'donor@example.invalid', 'Idem offer ${label} ${RUN_ID}', '', 'pending')`); return parseInt(dbScalar(`SELECT MAX(id) FROM desiderata_offers WHERE title='Idem offer ${label} ${RUN_ID}'`), 10); };
            ctx.offerAcceptId = offer('accept');
            ctx.offerRejectId = offer('reject');
            ctx.offerReceiveId = offer('receive', wanted('receive'));
            ctx.offerDeleteId = offer('delete');
            ctx.wantedDirectId = wanted('direct');
        }
        ctx.archivesActive = dbScalar("SELECT is_active FROM plugins WHERE name='archives'") === '1';
        if (ctx.archivesActive) {
            const ref = `IDEM-${String(process.pid).slice(-5)}-${String(Date.now()).slice(-6)}`;
            ctx.archiveRef = ref;
            ctx.archiveId = (await staffPost('/staff/archives', { reference_code: `${ref}-A`, level: 'fonds', constructed_title: `Idem unit ${RUN_ID}` })).id;
            ctx.archiveDeleteId = (await staffPost('/staff/archives', { reference_code: `${ref}-D`, level: 'fonds', constructed_title: `Idem unit delete ${RUN_ID}` })).id;
            const doc = await call(request, 'POST', `${API}/staff/archives/${ctx.archiveId}/documents`, { token: ctx.staffToken, multipart: { document: { name: 'fixture.pdf', mimeType: 'application/pdf', buffer: TINY_PDF } } });
            expect(doc.status(), 'archive document fixture').toBe(201);
            ctx.archiveFileId = (await doc.json()).data.files[0].id;
            ctx.authorityId = (await staffPost('/staff/archives/authorities', { type: 'person', authorised_form: `Idem person ${RUN_ID}` })).id;
            ctx.authorityDeleteId = (await staffPost('/staff/archives/authorities', { type: 'corporate', authorised_form: `Idem body ${RUN_ID}` })).id;
        }
        // Tier 2 fixtures (each only when its plugin + bridge answers).
        const active = (name) => dbScalar(`SELECT is_active FROM plugins WHERE name='${name}'`) === '1';
        const staffJson = async (method, route, body) => {
            const r = await call(request, method, `${API}${route}`, { token: ctx.staffToken, body });
            const res = { status: r.status(), json: await r.json().catch(() => null) };
            // A failed fixture names its endpoint instead of a TypeError on .data.id.
            if (method !== 'GET') expect(res.status, `fixture ${method} ${route}`).toBe(201);
            return res;
        };
        ctx.periodicalsStaff = active('emeroteca') && (await staffJson('GET', '/periodicals/staff/subscriptions')).status === 200;
        if (ctx.periodicalsStaff) {
            dbExec(`INSERT INTO emeroteca_testate (titolo) VALUES ('Idem rivista ${RUN_ID}')`);
            ctx.periodicalStaffId = parseInt(dbScalar(`SELECT MAX(id) FROM emeroteca_testate WHERE titolo='Idem rivista ${RUN_ID}'`), 10);
            ctx.periodicalYearIdStaff = (await staffJson('POST', `/periodicals/staff/${ctx.periodicalStaffId}/years`, { year: 2029 })).json.data.id;
            ctx.issueReceiveId = (await staffJson('POST', `/periodicals/staff/years/${ctx.periodicalYearIdStaff}/issues`, { number: 'R', status: 'atteso' })).json.data.id;
            ctx.issueClaimId = (await staffJson('POST', `/periodicals/staff/years/${ctx.periodicalYearIdStaff}/issues`, { number: 'C', status: 'atteso' })).json.data.id;
            ctx.subscriptionId = (await staffJson('POST', '/periodicals/staff/subscriptions', { periodical_id: ctx.periodicalStaffId, supplier: 'Idem fixture' })).json.data.id;
        }
        ctx.bookclubStaff = active('book-club') && (await staffJson('GET', '/bookclub/staff/clubs')).status === 200;
        if (ctx.bookclubStaff) {
            const club = (await staffJson('POST', '/bookclub/staff/clubs', { name: `Idem club ${RUN_ID}` })).json.data;
            ctx.clubId = club.id;
            ctx.clubMemberId = club.members[0].id;
            ctx.clubDeleteId = (await staffJson('POST', '/bookclub/staff/clubs', { name: `Idem club delete ${RUN_ID}` })).json.data.id;
        }
        ctx.frbrActive = active('frbr-lrm');
        if (ctx.frbrActive) {
            ctx.workId = (await staffJson('POST', '/staff/works', { uniform_title: `Idem work ${RUN_ID}` })).json.data.id;
            ctx.workDeleteId = (await staffJson('POST', '/staff/works', { uniform_title: `Idem work delete ${RUN_ID}` })).json.data.id;
            ctx.expressionId = (await staffJson('POST', `/staff/works/${ctx.workId}/expressions`, { type: 'testo' })).json.data.id;
            ctx.expressionDeleteId = (await staffJson('POST', `/staff/works/${ctx.workId}/expressions`, { type: 'audio' })).json.data.id;
        }
        ctx.digitalActive = active('digital-library');
        const labels = await call(request, 'GET', `${API}/staff/settings/labels`, { token: ctx.staffToken });
        ctx.labelSettings = (await labels.json()).data;

        // Renew row: one renewal allowed for this run (restored in afterAll).
        ctx.oldMaxRenewals = dbScalar("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='max_renewals' LIMIT 1");
        dbExec("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans','max_renewals','1') ON DUPLICATE KEY UPDATE setting_value='1'");
        await flushCache();

        // The borrower's personal calendar feed token.
        const cal = await call(request, 'POST', `${API}/me/calendar/token`, { token: ctx.token });
        expect(cal.status(), 'calendar token fixture').toBe(201);
        const feedUrl = String((await cal.json()).data.feed_url || '');
        ctx.calendarToken = (feedUrl.match(/\/calendar\/feed\/([a-f0-9]{64})\.ics$/) || [])[1] || 'missing';

        // ── Book Club bridge probe (optional plugin) ────────────────────────
        // The bridge answers /bookclub/health only when both plugins are active.
        // When it does, opportunistically pick existing club data and make the
        // test user an active member so the write rows exercise real paths;
        // every missing piece just skips its row (skipIf).
        ctx.bookclubActive = false;
        try {
            const health = await call(request, 'GET', `${API}/bookclub/health`, {});
            ctx.bookclubActive = health.status() === 200;
        } catch {}
        if (ctx.bookclubActive) {
            try {
                const club = dbScalar(`SELECT CONCAT(id, '|', slug) FROM bookclub_clubs WHERE privacy='public' AND is_active=1 AND deleted_at IS NULL ORDER BY id LIMIT 1`) || '';
                const [clubId, slug] = club.split('|');
                if (clubId && slug) {
                    ctx.bookclubSlug = slug;
                    // Membership is established by the join manifest row itself
                    // (declaration order precedes the other write rows), through
                    // the production join path — no raw INSERT with role ids.
                    const poll = dbScalar(`SELECT CONCAT(p.id, '|', o.id) FROM bookclub_polls p JOIN bookclub_poll_options o ON o.poll_id=p.id
                                           WHERE p.club_id=${clubId} AND p.status='open' AND (p.closes_at IS NULL OR p.closes_at > UTC_TIMESTAMP())
                                             AND p.mode IN ('simple','multi') ORDER BY p.id, o.id LIMIT 1`) || '';
                    const [pollId, optionId] = poll.split('|');
                    if (pollId) { ctx.bookclubPollId = pollId; ctx.bookclubOptionId = parseInt(optionId, 10); }
                    ctx.bookclubMeetingId = dbScalar(`SELECT id FROM bookclub_meetings WHERE club_id=${clubId} AND status='scheduled' AND starts_at >= NOW() ORDER BY starts_at LIMIT 1`) || undefined;
                    ctx.bookclubClubBookId = dbScalar(`SELECT id FROM bookclub_books WHERE club_id=${clubId} ORDER BY id LIMIT 1`) || undefined;
                }
            } catch {}
        }

        // ── Emeroteca bridge probe (optional plugin) ────────────────────────
        // The bridge answers /periodicals/health (authed) only when both the
        // emeroteca and mobile-api plugins are active. Fixtures come from the
        // existing demo data; every missing piece just skips its row (skipIf).
        ctx.periodicalsActive = false;
        try {
            const pHealth = await call(request, 'GET', `${API}/periodicals/health`, { token: ctx.token });
            ctx.periodicalsActive = pHealth.status() === 200;
        } catch {}
        if (ctx.periodicalsActive) {
            // Its own try: health does not probe the schema, so a missing table
            // would otherwise throw here and take down ETag tests for endpoints
            // that have nothing to do with standalone articles. Undefined lets
            // skipIf drop only the article detail.
            try {
                ctx.periodicalArticleId = dbScalar('SELECT id FROM emeroteca_contributi WHERE pubblico=1 ORDER BY id LIMIT 1') || undefined;
            } catch {
                ctx.periodicalArticleId = undefined;
            }
            try {
                ctx.periodicalId = dbScalar('SELECT id FROM emeroteca_testate ORDER BY id LIMIT 1') || undefined;
                if (ctx.periodicalId) {
                    ctx.periodicalYearId = dbScalar(`SELECT id FROM emeroteca_annate WHERE testata_id=${ctx.periodicalId} ORDER BY id LIMIT 1`) || undefined;
                }
                if (ctx.periodicalYearId) {
                    ctx.periodicalIssueId = dbScalar(`SELECT id FROM emeroteca_fascicoli WHERE annata_id=${ctx.periodicalYearId} ORDER BY id LIMIT 1`) || undefined;
                }
            } catch {}
        }
    });

    test.afterAll(async () => {
        try { dbExec(`DELETE FROM mobile_app_tokens WHERE user_id=${ctx.userId}`); } catch {}
        try { dbExec(`DELETE FROM mobile_push_subscriptions WHERE user_id=${ctx.userId}`); } catch {}
        try { dbExec(`DELETE FROM wishlist WHERE utente_id=${ctx.userId}`); } catch {}
        try { dbExec(`DELETE FROM prenotazioni WHERE utente_id=${ctx.userId}`); } catch {}
        try { dbExec(`DELETE FROM prestiti WHERE utente_id=${ctx.userId}`); } catch {}
        try { dbExec(`DELETE FROM recensioni WHERE utente_id=${ctx.userId}`); } catch {}
        try { dbExec(`DELETE FROM bookclub_progress WHERE user_id=${ctx.userId}`); } catch {}
        try { dbExec(`DELETE FROM bookclub_votes WHERE user_id=${ctx.userId}`); } catch {}
        try { dbExec(`DELETE FROM bookclub_meeting_rsvps WHERE user_id=${ctx.userId}`); } catch {}
        try { dbExec(`DELETE FROM bookclub_members WHERE user_id=${ctx.userId}`); } catch {}
        // Mobile API 1.6.0 staff fixtures (everything carries RUN_ID).
        try {
            const books = dbScalar(`SELECT GROUP_CONCAT(id) FROM libri WHERE titolo LIKE '%${RUN_ID}%'`);
            if (books && books !== 'NULL') {
                for (const t of ['prestiti', 'prenotazioni', 'copie', 'libri_autori', 'libri_editori', 'libri_collane']) {
                    try { dbExec(`DELETE FROM ${t} WHERE libro_id IN (${books})`); } catch {}
                }
                try { dbExec(`DELETE FROM log_modifiche WHERE tabella='libri' AND record_id IN (${books})`); } catch {}
                try { dbExec(`DELETE FROM oai_deleted_records WHERE entity_type='book' AND entity_id IN (${books})`); } catch {}
                // Re-encoded cover files written by the cover row.
                const covers = dbScalar(`SELECT GROUP_CONCAT(copertina_url SEPARATOR '|') FROM libri WHERE id IN (${books})`);
                for (const c of (covers && covers !== 'NULL' ? covers.split('|') : [])) {
                    if (/^\/uploads\/copertine\/libro_\d+_[a-f0-9]+\.(jpg|png)$/.test(c)) {
                        try { fs.unlinkSync(path.join(__dirname, '..', 'public', c)); } catch {}
                    }
                }
                try { dbExec(`DELETE FROM volumi WHERE opera_id IN (${books}) OR volume_id IN (${books})`); } catch {}
                dbExec(`DELETE FROM libri WHERE id IN (${books})`);
            }
        } catch {}
        try { dbExec(`DELETE FROM autori WHERE nome LIKE '%${RUN_ID}%'`); } catch {}
        try { dbExec(`DELETE FROM editori WHERE nome LIKE '%${RUN_ID}%'`); } catch {}
        try { dbExec(`DELETE FROM generi WHERE nome LIKE '%${RUN_ID}%'`); } catch {}
        for (const code of [ctx.staffShelfCode, ctx.staffShelfCode2].filter(Boolean)) {
            try { dbExec(`DELETE m FROM mensole m JOIN scaffali s ON s.id = m.scaffale_id WHERE s.codice='${code}'`); } catch {}
            try { dbExec(`DELETE FROM scaffali WHERE codice='${code}'`); } catch {}
        }
        // Mobile API 1.7.0: restore the renewal limit, drop the admin notices
        // the circulation fixtures raised (their text carries RUN_ID).
        // Restore only what beforeAll actually read: undefined means setup
        // failed before touching the setting (leave the real value alone),
        // '' means there was no row, anything else is the prior value.
        if (ctx.oldMaxRenewals !== undefined) {
            try {
                if (ctx.oldMaxRenewals === '') {
                    dbExec("DELETE FROM system_settings WHERE category='loans' AND setting_key='max_renewals'");
                } else {
                    dbExec(`UPDATE system_settings SET setting_value=${sqlStr(ctx.oldMaxRenewals)} WHERE category='loans' AND setting_key='max_renewals'`);
                }
            } catch {}
            await flushCache();
        }
        try { dbExec(`DELETE FROM admin_notifications WHERE message LIKE '%${RUN_ID}%'`); } catch {}
        try { dbExec(`DELETE v FROM volumi v LEFT JOIN libri l ON l.id = v.opera_id WHERE l.id IS NULL`); } catch {}
        try { dbExec(`DELETE FROM desiderata_offers WHERE title LIKE '%${RUN_ID}%'`); } catch {}
        try {
            const units = dbScalar(`SELECT GROUP_CONCAT(id) FROM archival_units WHERE constructed_title LIKE '%${RUN_ID}%'`);
            if (units && units !== 'NULL') {
                const files = dbScalar(`SELECT GROUP_CONCAT(file_path SEPARATOR '|') FROM archival_unit_files WHERE unit_id IN (${units})`);
                const covers = dbScalar(`SELECT GROUP_CONCAT(cover_image_path SEPARATOR '|') FROM archival_units WHERE id IN (${units})`);
                for (const p of [...(files && files !== 'NULL' ? files.split('|') : []), ...(covers && covers !== 'NULL' ? covers.split('|') : [])]) {
                    if (/^\/uploads\/archives\/(covers|documents)\/[^/]+$/.test(p)) { try { fs.unlinkSync(path.join(__dirname, '..', 'public', p)); } catch {} }
                }
                dbExec(`DELETE FROM archival_unit_files WHERE unit_id IN (${units})`);
                dbExec(`DELETE FROM archival_unit_authority WHERE archival_unit_id IN (${units})`);
                dbExec(`DELETE FROM archival_units WHERE id IN (${units})`);
            }
        } catch {}
        try { dbExec(`DELETE FROM authority_records WHERE authorised_form LIKE '%${RUN_ID}%'`); } catch {}
        if (ctx.periodicalStaffId) {
            try {
                dbExec(`DELETE f FROM emeroteca_fascicoli f JOIN emeroteca_annate a ON a.id = f.annata_id WHERE a.testata_id=${ctx.periodicalStaffId}`);
                dbExec(`DELETE FROM emeroteca_annate WHERE testata_id=${ctx.periodicalStaffId}`);
                dbExec(`DELETE FROM emeroteca_abbonamenti WHERE testata_id=${ctx.periodicalStaffId}`);
                dbExec(`DELETE FROM emeroteca_testate WHERE id=${ctx.periodicalStaffId}`);
            } catch {}
        }
        try {
            const clubs = dbScalar(`SELECT GROUP_CONCAT(id) FROM bookclub_clubs WHERE name LIKE '%${RUN_ID}%'`);
            if (clubs && clubs !== 'NULL') {
                const wf = dbScalar(`SELECT GROUP_CONCAT(workflow_id) FROM bookclub_clubs WHERE id IN (${clubs}) AND workflow_id IS NOT NULL`);
                dbExec(`DELETE FROM bookclub_members WHERE club_id IN (${clubs})`);
                dbExec(`DELETE FROM bookclub_clubs WHERE id IN (${clubs})`);
                if (wf && wf !== 'NULL') { try { dbExec(`DELETE FROM bookclub_workflows WHERE id IN (${wf})`); } catch {} }
            }
        } catch {}
        try {
            const works = dbScalar(`SELECT GROUP_CONCAT(id) FROM opere WHERE titolo_uniforme LIKE '%${RUN_ID}%'`);
            if (works && works !== 'NULL') {
                dbExec(`UPDATE libri SET opera_id = NULL, espressione_id = NULL WHERE opera_id IN (${works})`);
                dbExec(`DELETE FROM espressioni WHERE opera_id IN (${works})`);
                dbExec(`DELETE FROM opere WHERE id IN (${works})`);
            }
        } catch {}
        if (ctx.staffUserId) {
            try { dbExec(`DELETE FROM import_logs WHERE user_id=${ctx.staffUserId}`); } catch {}
            try { dbExec(`DELETE FROM mobile_import_jobs WHERE user_id=${ctx.staffUserId}`); } catch {}
        }
        try { dbExec(`DELETE FROM collane WHERE nome LIKE '%${RUN_ID}%' AND parent_id IS NOT NULL`); } catch {}
        try { dbExec(`DELETE FROM collane WHERE nome LIKE '%${RUN_ID}%'`); } catch {}
        try { dbExec(`DELETE FROM mobile_calendar_tokens WHERE user_id=${ctx.userId}`); } catch {}
        if (ctx.staffUserId) {
            try { dbExec(`DELETE FROM mobile_app_tokens WHERE user_id=${ctx.staffUserId}`); } catch {}
            try { dbExec(`DELETE FROM log_modifiche WHERE utente_id=${ctx.staffUserId}`); } catch {}
            if (ctx.createdStaff) {
                try { dbExec(`DELETE FROM utenti WHERE id=${ctx.staffUserId}`); } catch {}
            }
        }
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
        const covered = new Set(ENDPOINTS.map((e) => norm(e.method, e.route || e.path)));
        const missing = documented.filter((d) => !covered.has(d));
        // A documented route with no manifest row fails here — enforcing the
        // "add an endpoint ⇒ add exactly one manifest row" rule.
        expect(missing, `Documented endpoints missing a manifest row (add one row each): ${missing.join(', ')}`).toEqual([]);
    });

    // Data-driven: two calls per endpoint, contract enforced by the shared runner.
    for (const e of ENDPOINTS) {
        test(`2× ${e.name} [${e.kind}]`, async ({ request }) => {
            // Optional-plugin rows (book-club bridge) no-op when the plugin or
            // the referenced data is absent on this instance.
            test.skip(Boolean(e.skipIf && e.skipIf(ctx)), 'optional plugin/data not available on this instance');
            await runTwice(request, e, ctx);
        });
    }
});

// Minimal SQL string escaper for the few literal inserts above (test-only).
function sqlStr(v) { return "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'"; }

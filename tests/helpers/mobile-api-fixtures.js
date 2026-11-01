// @ts-check
/**
 * Shared fixtures for the Mobile API 1.6.0 suites (staff, sync, calendar).
 *
 * Same conventions as tests/mobile-api-idempotency.spec.js: the plugin is
 * activated through the real admin endpoint (never by flipping is_active in
 * SQL), test accounts are unique per run and created straight in the database
 * with a real password_hash, and every helper that writes records what it
 * created so the suite can remove exactly that in afterAll.
 */
const { expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const API = `${BASE}/api/v1`;
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_NAME = process.env.E2E_DB_NAME || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';

const ENV_READY = Boolean(DB_USER && DB_NAME && ADMIN_EMAIL && ADMIN_PASS);

function mysqlArgs(sql, batch, withNames = false) {
    const args = [];
    if (DB_HOST) { args.push('-h', DB_HOST); if (DB_PORT) args.push('-P', DB_PORT); }
    else if (DB_SOCKET) { args.push('-S', DB_SOCKET); }
    // Explicit client charset: the CLI default would double-encode UTF-8 titles.
    args.push('--default-character-set=utf8mb4', '-u', DB_USER, DB_NAME);
    if (batch) args.push(withNames ? '-B' : '-N', ...(withNames ? [] : ['-B']));
    args.push('-e', sql);
    return args;
}
const MYSQL_ENV = () => ({ ...process.env, MYSQL_PWD: DB_PASS });

function dbExec(sql) {
    execFileSync('mysql', mysqlArgs(sql, false), { encoding: 'utf-8', timeout: 20000, env: MYSQL_ENV() });
}
function dbScalar(sql) {
    const out = execFileSync('mysql', mysqlArgs(sql, true), { encoding: 'utf-8', timeout: 20000, env: MYSQL_ENV() }).trim();
    return out.split('\n')[0] || '';
}
function dbInt(sql) { return parseInt(dbScalar(sql) || '0', 10); }
/** Rows as objects (column names from the -B header). */
function dbRows(sql) {
    const out = execFileSync('mysql', mysqlArgs(sql, true, true), { encoding: 'utf-8', timeout: 20000, env: MYSQL_ENV() });
    const lines = out.split('\n').filter((l) => l !== '');
    if (lines.length === 0) return [];
    const head = lines[0].split('\t');
    return lines.slice(1).map((line) => {
        const cells = line.split('\t');
        /** @type {Record<string, string>} */
        const row = {};
        head.forEach((h, i) => { row[h] = cells[i]; });
        return row;
    });
}
/** INSERT … and LAST_INSERT_ID() in ONE connection (each mysql call is a new one). */
function dbInsert(sql) {
    return parseInt(dbScalar(`${sql}; SELECT LAST_INSERT_ID();`) || '0', 10);
}
function sqlStr(v) { return "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'"; }

/** A valid ISBN-13 (978 prefix) built from 9 digits. */
function isbn13(nineDigits) {
    const base = '978' + String(nineDigits).padStart(9, '0').slice(-9);
    let sum = 0;
    for (let i = 0; i < 12; i++) sum += Number(base[i]) * (i % 2 === 0 ? 1 : 3);
    return base + String((10 - (sum % 10)) % 10);
}
/** A random, run-unique ISBN-13 that no real record carries. */
function uniqueIsbn13() {
    return isbn13(String(Date.now() % 1e6).padStart(6, '0') + String(Math.floor(Math.random() * 1000)).padStart(3, '0'));
}

async function adminLogin(page) {
    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL((u) => !u.toString().includes('/accedi'), { timeout: 15000 });
}

/**
 * Activate mobile-api through the admin endpoint when needed and open the
 * app-access gate. Returns the previous gate value so afterAll can restore it.
 */
async function ensureMobileApiReady(page) {
    const pid = dbInt("SELECT id FROM plugins WHERE name='mobile-api' LIMIT 1");
    if (pid === 0) throw new Error('mobile-api plugin is not registered');
    const active = dbScalar(`SELECT is_active FROM plugins WHERE id=${pid}`) === '1';
    const hooks = dbInt(`SELECT COUNT(*) FROM plugin_hooks WHERE plugin_id=${pid} AND is_active=1`);
    if (!active || hooks === 0) {
        await adminLogin(page);
        await page.evaluate(async (id) => {
            const base = window.location.origin + (window.BASE_PATH || '');
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            if (document.querySelector(`[data-plugin-id="${id}"].active`)) {
                await fetch(`${base}/admin/plugins/${id}/deactivate`, { method: 'POST', headers: { 'X-CSRF-Token': csrf } });
            }
            const a = await fetch(`${base}/admin/plugins/${id}/activate`, { method: 'POST', headers: { 'X-CSRF-Token': csrf } });
            if (!a.ok) throw new Error(`plugin activate failed: HTTP ${a.status}`);
        }, pid);
    }
    const hadGate = dbInt("SELECT COUNT(*) FROM system_settings WHERE category='mobile_api' AND setting_key='enabled'") > 0;
    const oldGate = dbScalar("SELECT setting_value FROM system_settings WHERE category='mobile_api' AND setting_key='enabled' LIMIT 1");
    dbExec("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('mobile_api','enabled','1') ON DUPLICATE KEY UPDATE setting_value='1'");
    expect(dbScalar(`SELECT is_active FROM plugins WHERE id=${pid}`), 'mobile-api active').toBe('1');
    return { hadGate, oldGate };
}

function restoreGate(state) {
    if (!state) return;
    try {
        if (state.hadGate) {
            dbExec(`UPDATE system_settings SET setting_value=${sqlStr(state.oldGate)} WHERE category='mobile_api' AND setting_key='enabled'`);
        } else {
            dbExec("DELETE FROM system_settings WHERE category='mobile_api' AND setting_key='enabled'");
        }
    } catch { /* best effort */ }
}

/**
 * Create a verified, active account with a known password; returns its id.
 */
function createUser(email, password, role, tag) {
    const hash = execFileSync('php', ['-r', `echo password_hash(${JSON.stringify(password)}, PASSWORD_DEFAULT);`], { encoding: 'utf-8' }).trim();
    const card = `${tag}${String(Date.now()).slice(-8)}${Math.floor(Math.random() * 90 + 10)}`.slice(0, 20);
    return dbInsert(`INSERT INTO utenti (nome, cognome, email, password, telefono, indirizzo, tipo_utente, stato, email_verificata, codice_tessera, privacy_accettata, created_at)
                     VALUES ('E2E', ${sqlStr(tag)}, ${sqlStr(email)}, ${sqlStr(hash)}, '000', 'E2E address', ${sqlStr(role)}, 'attivo', 1, ${sqlStr(card)}, 1, NOW())`);
}

function deleteUsers(ids) {
    const list = ids.filter((id) => id > 0).join(',');
    if (!list) return;
    for (const sql of [
        `DELETE FROM mobile_calendar_tokens WHERE user_id IN (${list})`,
        `DELETE FROM mobile_push_subscriptions WHERE user_id IN (${list})`,
        `DELETE FROM mobile_app_tokens WHERE user_id IN (${list})`,
        `DELETE FROM prestiti WHERE utente_id IN (${list})`,
        `DELETE FROM prenotazioni WHERE utente_id IN (${list})`,
        `DELETE FROM log_modifiche WHERE utente_id IN (${list})`,
        `DELETE FROM user_sessions WHERE utente_id IN (${list})`,
        `DELETE FROM consent_log WHERE utente_id IN (${list})`,
        `DELETE FROM utenti WHERE id IN (${list})`,
    ]) {
        try { dbExec(sql); } catch { /* table may be absent */ }
    }
}

/**
 * Hard-remove books this run created (and everything hanging off them),
 * in FK-safe order. Only ever called with ids the suite created itself.
 */
function deleteBooks(ids) {
    const list = ids.filter((id) => id > 0).join(',');
    if (!list) return;
    for (const sql of [
        `DELETE FROM prestiti WHERE libro_id IN (${list})`,
        `DELETE FROM prenotazioni WHERE libro_id IN (${list})`,
        `DELETE FROM wishlist WHERE libro_id IN (${list})`,
        `DELETE FROM copie WHERE libro_id IN (${list})`,
        `DELETE FROM libri_autori_import_sources WHERE libro_id IN (${list})`,
        `DELETE FROM libri_autori WHERE libro_id IN (${list})`,
        `DELETE FROM mobile_availability_watchers WHERE libro_id IN (${list})`,
        `DELETE FROM libri_tag WHERE libro_id IN (${list})`,
        `DELETE FROM libri_editori WHERE libro_id IN (${list})`,
        `DELETE FROM libri_collane WHERE libro_id IN (${list})`,
        `DELETE FROM log_modifiche WHERE tabella='libri' AND record_id IN (${list})`,
        `DELETE FROM oai_deleted_records WHERE entity_type='book' AND entity_id IN (${list})`,
        `DELETE FROM libri WHERE id IN (${list})`,
    ]) {
        try { dbExec(sql); } catch { /* optional table */ }
    }
}

function headers(token, extra) {
    const h = { Accept: 'application/json', ...(extra || {}) };
    if (token) h.Authorization = `Bearer ${token}`;
    return h;
}

/**
 * Call the API. `body` is sent as JSON; `multipart` as multipart/form-data.
 * Returns {status, json, headers, text}.
 */
async function api(request, method, path, { token, body, multipart, extraHeaders } = {}) {
    const opts = { method, headers: headers(token, extraHeaders) };
    if (multipart) {
        opts.multipart = multipart;
    } else if (body !== undefined) {
        opts.headers['Content-Type'] = 'application/json';
        opts.data = JSON.stringify(body);
    }
    const url = path.startsWith('http') ? path : `${API}${path}`;
    let res;
    try {
        res = await request.fetch(url, opts);
    } catch (error) {
        // Apache closes a kept-alive connection after MaxKeepAliveRequests
        // (100 by default) or KeepAliveTimeout; a request sent on it at that
        // moment fails with no response at all, so the server never saw it.
        // A long loop of calls meets that race: retry once on a fresh socket.
        if (!/socket hang up|ECONNRESET/.test(String(error && error.message))) throw error;
        res = await request.fetch(url, opts);
    }
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch { json = null; }
    return { status: res.status(), json, headers: res.headers(), text };
}

async function login(request, email, password, device) {
    const r = await api(request, 'POST', '/auth/login', {
        body: { email, password, device_name: device, device_id: device, platform: 'test' },
    });
    expect(r.status, `login ${email}: ${r.text.slice(0, 200)}`).toBe(200);
    return String(r.json.data.token);
}

/**
 * Toggle catalogue mode through the admin settings form (a direct SQL write
 * would leave ConfigStore's cross-request cache stale).
 */
async function setCatalogueMode(browser, enabled) {
    const context = await browser.newContext();
    const page = await context.newPage();
    try {
        await adminLogin(page);
        await page.goto(`${BASE}/admin/settings?tab=advanced`);
        const toggle = page.locator('#catalogue_mode');
        await expect(toggle).toHaveCount(1);
        await toggle.evaluate((element, checked) => {
            element.checked = checked;
            element.dispatchEvent(new Event('change', { bubbles: true }));
        }, enabled);
        const form = toggle.locator('xpath=ancestor::form[1]');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }),
            form.locator('button[type="submit"]').click(),
        ]);
        expect(dbScalar("SELECT setting_value FROM system_settings WHERE category='system' AND setting_key='catalogue_mode' LIMIT 1")).toBe(enabled ? '1' : '0');
    } finally {
        await context.close();
    }
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

module.exports = {
    BASE, API, ENV_READY, ADMIN_EMAIL, ADMIN_PASS,
    dbExec, dbScalar, dbInt, dbRows, dbInsert, sqlStr,
    isbn13, uniqueIsbn13,
    ensureMobileApiReady, restoreGate, createUser, deleteUsers, deleteBooks,
    api, login, setCatalogueMode, adminLogin, sleep,
};

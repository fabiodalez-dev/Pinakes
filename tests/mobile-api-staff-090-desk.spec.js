// @ts-check
// Mobile API 1.10.0 — Pinakes 0.9.0 front-desk areas:
//   visitor log      GET /staff/attendance, POST …/check-ins, POST …/guests,
//                    GET …/export.csv, PUT …/settings, POST …/purge   (visitor_log)
//   statistics       GET /staff/statistics, GET …/fines.csv (fines)
//   patron CSV import POST /staff/user-imports, GET|DELETE …/{id}, POST …/{id}/start,
//                    POST …/{id}/chunk, GET …/{id}/errors.csv, GET …/example.csv (user_import)
//
// Proves against the real Apache instance: role guards (reader 403, no token
// 401, Staff 403 on the administrator-only settings and purge), the real
// effect of every write (rows in presenze / utenti / import_logs), the
// kiosk's outcomes for unknown, suspended and expired cards, idempotent
// replays, downloads, the statistics sections following their module switch
// and matching the numbers of the web statistics page, a full CSV import
// (created, duplicates, invalid rows, no password imported), mapping change,
// chunk ordering, cancel, job ownership, and 404 feature_disabled per module.
//
// Run: /tmp/run-e2e.sh tests/mobile-api-staff-090-desk.spec.js --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const F = require('./helpers/mobile-api-fixtures');
const featureToggles = require('./helpers/feature-toggles');
const { flushCache } = require('./helpers/flush-cache');

test.describe.configure({ mode: 'serial' });
test.skip(!F.ENV_READY, 'Missing E2E env: DB_* and ADMIN_EMAIL/ADMIN_PASS');

const RUN = `${Date.now()}_${process.pid}`;
const TAG = `dk${String(Date.now()).slice(-7)}`;
const PASS = 'Desk1234Test!';
const MODULES = ['visitor_log', 'user_import', 'fines', 'automatic_fines', 'debt_block', 'loss_penalties', 'stocktake', 'circulation_rules', 'attendance_retention', 'patron_photos'];

/** @type {Record<string, any>} */
const ctx = { users: [], visits: [], jobs: [] };

const idemKey = (label) => `desk-${label}-${RUN}`.slice(0, 128);
const email = (label) => `desk_${label}_${RUN}@example.test`;

/** Raw download (the JSON helper decodes bodies as text). */
async function download(request, route, token) {
    const res = await request.fetch(`${F.API}${route}`, { method: 'GET', headers: token ? { Authorization: `Bearer ${token}` } : {} });
    const body = await res.body();
    return { status: res.status(), headers: res.headers(), body, text: body.toString('utf-8') };
}

function csvFile(name, lines) {
    return { name, mimeType: 'text/csv', buffer: Buffer.from(lines.join('\n') + '\n', 'utf-8') };
}

function stagedFile(jobId) {
    return F.dbScalar(`SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(state, '$.core.file')), '') FROM mobile_import_jobs WHERE id=${F.sqlStr(jobId)}`);
}

function passwordMatches(hash, plain) {
    // Values as argv: a bcrypt hash inside a double-quoted PHP literal would interpolate.
    return execFileSync('php', ['-r', "echo password_verify($argv[1], $argv[2]) ? 'yes' : 'no';", '--', plain, hash], { encoding: 'utf-8' }).trim() === 'yes';
}

async function setFeatures(values) {
    await featureToggles.writeFeatures(values);
    await flushCache();
}

async function upload(request, token, file, delimiter) {
    const multipart = { file };
    if (delimiter !== undefined) multipart.delimiter = delimiter;
    const r = await F.api(request, 'POST', '/staff/user-imports', { token, multipart });
    if (r.status === 201) ctx.jobs.push(r.json.data.id);
    return r;
}

test.beforeAll(async ({ browser, request }) => {
    const page = await browser.newPage();
    ctx.gate = await F.ensureMobileApiReady(page);
    await page.close();
    ctx.features = featureToggles.readFeatures(MODULES);
    await featureToggles.enableFeatures(['visitor_log', 'user_import', 'fines', 'circulation_rules']);
    ctx.hadRetention = F.dbInt("SELECT COUNT(*) FROM system_settings WHERE category='attendance' AND setting_key='retention_days'") > 0;
    ctx.oldRetention = F.dbScalar("SELECT setting_value FROM system_settings WHERE category='attendance' AND setting_key='retention_days' LIMIT 1");

    ctx.adminId = F.createUser(email('admin'), PASS, 'admin', 'DKA');
    ctx.staffId = F.createUser(email('staff'), PASS, 'staff', 'DKS');
    ctx.staff2Id = F.createUser(email('staff2'), PASS, 'staff', 'DKT');
    ctx.readerId = F.createUser(email('reader'), PASS, 'standard', 'DKR');
    ctx.users.push(ctx.adminId, ctx.staffId, ctx.staff2Id, ctx.readerId);
    // Patrons whose cards the phone scans: active, suspended, expired account,
    // active account with a card expiry in the past.
    const patron = (label, stato, expiry) => {
        const card = `${TAG}${label}`.slice(0, 20);
        const id = F.dbInsert(`INSERT INTO utenti (nome, cognome, email, password, telefono, indirizzo, tipo_utente, stato, email_verificata, codice_tessera, data_scadenza_tessera, privacy_accettata, created_at)
            VALUES ('Mario', 'Rossi', ${F.sqlStr(email(label))}, 'x', '000', 'E2E', 'standard', ${F.sqlStr(stato)}, 1, ${F.sqlStr(card)}, ${expiry ? F.sqlStr(expiry) : 'NULL'}, 1, NOW())`);
        ctx.users.push(id);
        return { id, card };
    };
    ctx.active = patron('ok', 'attivo', null);
    ctx.suspended = patron('sus', 'sospeso', null);
    ctx.expiredAccount = patron('exp', 'scaduto', null);
    ctx.oldCard = patron('old', 'attivo', '2020-01-31');
    ctx.branchId = F.dbInsert(`INSERT INTO sedi (nome, codice, is_active) VALUES (${F.sqlStr(`Sede E2E ${RUN}`)}, ${F.sqlStr(TAG)}, 1)`);
    ctx.inactiveBranchId = F.dbInsert(`INSERT INTO sedi (nome, codice, is_active) VALUES (${F.sqlStr(`Sede chiusa E2E ${RUN}`)}, ${F.sqlStr(`${TAG}x`)}, 0)`);
    await flushCache();

    ctx.admin = await F.login(request, email('admin'), PASS, 'dk-admin');
    ctx.staff = await F.login(request, email('staff'), PASS, 'dk-staff');
    ctx.staff2 = await F.login(request, email('staff2'), PASS, 'dk-staff2');
    ctx.reader = await F.login(request, email('reader'), PASS, 'dk-reader');
    ctx.today = F.dbScalar('SELECT CURDATE()');
});

test.afterAll(async () => {
    const list = ctx.users.join(',');
    for (const id of ctx.jobs) {
        const file = stagedFile(id);
        if (file && file.includes('/writable/uploads/user_import_')) { try { fs.unlinkSync(file); } catch {} }
    }
    const imported = F.dbScalar(`SELECT GROUP_CONCAT(id) FROM utenti WHERE email LIKE ${F.sqlStr(`%_${RUN}@example.test`)}`);
    const all = [...new Set([...ctx.users, ...(imported ? imported.split(',').map(Number) : [])])];
    try { F.dbExec(`DELETE FROM presenze WHERE utente_id IN (${all.join(',')}) OR nome_ospite LIKE ${F.sqlStr(`%${RUN}%`)} OR sede_id IN (${ctx.branchId || 0}, ${ctx.inactiveBranchId || 0})`); } catch {}
    if (ctx.visits.length) { try { F.dbExec(`DELETE FROM presenze WHERE id IN (${ctx.visits.join(',')})`); } catch {} }
    try { F.dbExec(`DELETE FROM movimenti_lettore WHERE utente_id IN (${all.join(',')})`); } catch {}
    try { F.dbExec(`DELETE FROM import_logs WHERE user_id IN (${list})`); } catch {}
    try { F.dbExec(`DELETE FROM mobile_import_jobs WHERE user_id IN (${list})`); } catch {}
    try { F.dbExec(`DELETE FROM mobile_idempotency_keys WHERE user_id IN (${list})`); } catch {}
    try { F.dbExec(`DELETE FROM sedi WHERE id IN (${ctx.branchId || 0}, ${ctx.inactiveBranchId || 0})`); } catch {}
    F.deleteUsers(all);
    if (ctx.hadRetention) {
        F.dbExec(`UPDATE system_settings SET setting_value=${F.sqlStr(ctx.oldRetention)} WHERE category='attendance' AND setting_key='retention_days'`);
    } else {
        F.dbExec("DELETE FROM system_settings WHERE category='attendance' AND setting_key='retention_days'");
    }
    await featureToggles.restoreFeatures(ctx.features);
    F.restoreGate(ctx.gate);
});

const JOB0 = '00000000-0000-4000-8000-000000000000';
const ROUTES = () => [
    ['GET', '/staff/attendance'],
    ['POST', '/staff/attendance/check-ins'],
    ['POST', '/staff/attendance/guests'],
    ['GET', '/staff/attendance/export.csv'],
    ['PUT', '/staff/attendance/settings'],
    ['POST', '/staff/attendance/purge'],
    ['GET', '/staff/statistics'],
    ['GET', '/staff/statistics/fines.csv'],
    ['GET', '/staff/user-imports'],
    ['POST', '/staff/user-imports'],
    ['GET', '/staff/user-imports/example.csv'],
    ['GET', `/staff/user-imports/${JOB0}`],
    ['POST', `/staff/user-imports/${JOB0}/start`],
    ['POST', `/staff/user-imports/${JOB0}/chunk`],
    ['DELETE', `/staff/user-imports/${JOB0}`],
    ['GET', `/staff/user-imports/${JOB0}/errors.csv`],
];

test.describe('Guards and discovery', () => {
    test('a reader gets 403 on every route, no token 401; nothing is recorded', async ({ request }) => {
        const before = F.dbInt('SELECT COUNT(*) FROM presenze');
        for (const [method, route] of ROUTES()) {
            const body = method === 'GET' || method === 'DELETE' ? undefined : { card_code: ctx.active.card, name: 'x', retention_days: 30 };
            const r = await F.api(request, method, route, { token: ctx.reader, body });
            expect(r.status, `${method} ${route}`).toBe(403);
            expect(r.json?.error?.code).toBe('forbidden');
            const anon = await F.api(request, method, route, { body });
            expect(anon.status, `anon ${method} ${route}`).toBe(401);
        }
        expect(F.dbInt('SELECT COUNT(*) FROM presenze')).toBe(before);
    });

    test('health advertises the three areas; OpenAPI documents every route', async ({ request }) => {
        const f = (await F.api(request, 'GET', '/health')).json.data.features;
        expect(f.staff_attendance).toBe(true);
        expect(f.staff_statistics).toBe(true);
        expect(f.staff_user_import).toBe(true);
        const doc = (await F.api(request, 'GET', '/openapi.json')).json;
        for (const p of ['/staff/attendance', '/staff/attendance/check-ins', '/staff/attendance/guests', '/staff/attendance/export.csv',
            '/staff/attendance/settings', '/staff/attendance/purge', '/staff/statistics', '/staff/statistics/fines.csv',
            '/staff/user-imports', '/staff/user-imports/example.csv', '/staff/user-imports/{id}', '/staff/user-imports/{id}/start',
            '/staff/user-imports/{id}/chunk', '/staff/user-imports/{id}/errors.csv']) {
            expect(doc.paths[p], p).toBeTruthy();
        }
        expect(Object.keys(doc.paths['/staff/user-imports/{id}']).sort()).toEqual(['delete', 'get']);
        expect(Object.keys(doc.paths['/staff/user-imports']).sort()).toEqual(['get', 'post']);
        for (const s of ['AttendanceVisit', 'AttendanceOverview', 'StaffStatistics', 'UserImportJob']) {
            expect(doc.components.schemas[s], s).toBeTruthy();
        }
    });
});

test.describe('Visitor log', () => {
    test('dashboard: periods, 24 hour slots, branches, retention; invalid period → 422', async ({ request }) => {
        const r = await F.api(request, 'GET', '/staff/attendance?days=7', { token: ctx.staff });
        expect(r.status, r.text.slice(0, 300)).toBe(200);
        const d = r.json.data;
        expect(d.days).toBe(7);
        expect(d.periods).toEqual([7, 30, 90, 365]);
        expect(d.daily).toHaveLength(7);
        expect(d.daily[6].date).toBe(ctx.today);
        expect(d.hourly).toHaveLength(24);
        expect(d.branches.map((b) => b.id)).toContain(ctx.branchId);
        expect(d.branches.map((b) => b.id)).not.toContain(ctx.inactiveBranchId);
        expect(d.retention.max_days).toBe(3650);
        expect(d.can_configure).toBe(false);
        expect((await F.api(request, 'GET', '/staff/attendance', { token: ctx.admin })).json.data.can_configure).toBe(true);
        const bad = await F.api(request, 'GET', '/staff/attendance?days=12', { token: ctx.staff });
        expect(bad.status).toBe(422);
        expect(bad.json.error.code).toBe('validation');
        expect(bad.json.meta.fields.days).toBeTruthy();
    });

    test('check-in with a valid card records the member; the same key replays it', async ({ request }) => {
        const body = { card_code: ` ${ctx.active.card} `, branch_id: ctx.branchId };
        const r = await F.api(request, 'POST', '/staff/attendance/check-ins', { token: ctx.staff, body, extraHeaders: { 'Idempotency-Key': idemKey('in1') } });
        expect(r.status, r.text.slice(0, 300)).toBe(201);
        const v = r.json.data;
        ctx.visits.push(v.id);
        expect(v.type).toBe('member');
        expect(v.branch).toEqual({ id: ctx.branchId, name: `Sede E2E ${RUN}` });
        expect(v.member).toMatchObject({ id: ctx.active.id, short_name: 'Mario R.', card_code: ctx.active.card, account_status: 'attivo', card_active: true, card_expired: false, has_photo: false, photo_url: null });
        expect(v.warning).toBeNull();
        expect(v.message).toContain('Mario R.');
        expect(F.dbScalar(`SELECT CONCAT(tipo, '|', utente_id, '|', sede_id) FROM presenze WHERE id=${v.id}`)).toBe(`socio|${ctx.active.id}|${ctx.branchId}`);

        const again = await F.api(request, 'POST', '/staff/attendance/check-ins', { token: ctx.staff, body, extraHeaders: { 'Idempotency-Key': idemKey('in1') } });
        expect(again.status).toBe(200);
        expect(again.json.meta.replayed).toBe(true);
        expect(again.json.data.id).toBe(v.id);
        expect(F.dbInt(`SELECT COUNT(*) FROM presenze WHERE utente_id=${ctx.active.id}`)).toBe(1);

        const other = await F.api(request, 'POST', '/staff/attendance/check-ins', { token: ctx.staff, body: { card_code: ctx.oldCard.card }, extraHeaders: { 'Idempotency-Key': idemKey('in1') } });
        expect(other.status).toBe(422);
        expect(other.json.error.code).toBe('idempotency_conflict');
        expect(F.dbInt(`SELECT COUNT(*) FROM presenze WHERE utente_id=${ctx.oldCard.id}`)).toBe(0);
    });

    test('unknown card → 404 card_not_found and nothing recorded; empty card, bad branch, bad key → 422', async ({ request }) => {
        const before = F.dbInt('SELECT COUNT(*) FROM presenze');
        const r = await F.api(request, 'POST', '/staff/attendance/check-ins', { token: ctx.staff, body: { card_code: `NOPE${RUN}`.slice(0, 20) } });
        expect(r.status).toBe(404);
        expect(r.json.error.code).toBe('card_not_found');
        for (const [body, field, headers] of [
            [{ card_code: '   ' }, 'card_code', undefined],
            [{}, 'card_code', undefined],
            [{ card_code: ctx.active.card, branch_id: ctx.inactiveBranchId }, 'branch_id', undefined],
            [{ card_code: ctx.active.card, branch_id: 'abc' }, 'branch_id', undefined],
            [{ card_code: ctx.active.card }, 'idempotency_key', { 'Idempotency-Key': 'bad key!' }],
        ]) {
            const bad = await F.api(request, 'POST', '/staff/attendance/check-ins', { token: ctx.staff, body, extraHeaders: headers });
            expect(bad.status, JSON.stringify(body)).toBe(422);
            expect(bad.json.error.code).toBe('validation');
            expect(bad.json.meta.fields[field], JSON.stringify(bad.json)).toBeTruthy();
        }
        expect(F.dbInt('SELECT COUNT(*) FROM presenze')).toBe(before);
    });

    test('suspended or expired accounts are recorded with the kiosk warning; a past card expiry is reported', async ({ request }) => {
        for (const p of [ctx.suspended, ctx.expiredAccount]) {
            const r = await F.api(request, 'POST', '/staff/attendance/check-ins', { token: ctx.staff, body: { card_code: p.card } });
            expect(r.status, r.text.slice(0, 300)).toBe(201);
            ctx.visits.push(r.json.data.id);
            expect(r.json.data.member.card_active).toBe(false);
            expect(r.json.data.warning).toBe('card_not_active');
            expect(r.json.data.message).toContain('Mario R.');
            expect(F.dbInt(`SELECT COUNT(*) FROM presenze WHERE utente_id=${p.id} AND tipo='socio'`)).toBe(1);
        }
        const old = await F.api(request, 'POST', '/staff/attendance/check-ins', { token: ctx.admin, body: { card_code: ctx.oldCard.card } });
        expect(old.status).toBe(201);
        ctx.visits.push(old.json.data.id);
        expect(old.json.data.member).toMatchObject({ card_active: true, card_expires_at: '2020-01-31', card_expired: true });
        expect(old.json.data.warning).toBeNull();
        expect(old.json.data.branch).toBeNull();
    });

    test('guest visit with name and reason; replay with the same key', async ({ request }) => {
        const body = { name: `Ospite ${RUN}`, reason: 'Consultazione', branch_id: ctx.branchId };
        const r = await F.api(request, 'POST', '/staff/attendance/guests', { token: ctx.staff, body, extraHeaders: { 'Idempotency-Key': idemKey('g1') } });
        expect(r.status, r.text.slice(0, 300)).toBe(201);
        ctx.visits.push(r.json.data.id);
        expect(r.json.data).toMatchObject({ type: 'guest', member: null, guest: { name: `Ospite ${RUN}`, reason: 'Consultazione' } });
        expect(F.dbScalar(`SELECT CONCAT(tipo, '|', nome_ospite, '|', motivo, '|', sede_id) FROM presenze WHERE id=${r.json.data.id}`)).toBe(`ospite|Ospite ${RUN}|Consultazione|${ctx.branchId}`);
        const again = await F.api(request, 'POST', '/staff/attendance/guests', { token: ctx.staff, body, extraHeaders: { 'Idempotency-Key': idemKey('g1') } });
        expect(again.status).toBe(200);
        expect(again.json.data.id).toBe(r.json.data.id);
        expect(F.dbInt(`SELECT COUNT(*) FROM presenze WHERE nome_ospite=${F.sqlStr(`Ospite ${RUN}`)}`)).toBe(1);
        const anonymous = await F.api(request, 'POST', '/staff/attendance/guests', { token: ctx.staff, body: {} });
        expect(anonymous.status).toBe(201);
        ctx.visits.push(anonymous.json.data.id);
        expect(anonymous.json.data.guest).toEqual({ name: null, reason: null });
        const bad = await F.api(request, 'POST', '/staff/attendance/guests', { token: ctx.staff, body: { name: 42 } });
        expect(bad.status).toBe(422);
        expect(bad.json.meta.fields.name).toBeTruthy();
    });

    test('the dashboard counts today\'s visits and lists them among the recent ones', async ({ request }) => {
        const r = await F.api(request, 'GET', '/staff/attendance?days=7', { token: ctx.staff });
        const d = r.json.data;
        const members = F.dbInt(`SELECT COUNT(*) FROM presenze WHERE tipo='socio' AND DATE(created_at)=CURDATE()`);
        const guests = F.dbInt(`SELECT COUNT(*) FROM presenze WHERE tipo='ospite' AND DATE(created_at)=CURDATE()`);
        expect(d.today).toEqual({ members, guests, total: members + guests });
        expect(d.daily[6]).toEqual({ date: ctx.today, members, guests, total: members + guests });
        expect(d.hourly.reduce((s, h) => s + h.total, 0)).toBe(d.totals.total);
        expect(d.recent.map((x) => x.label)).toContain(`Ospite ${RUN}`);
        expect(d.recent.find((x) => x.type === 'member')?.label).toBe('Mario R.');
    });

    test('export.csv: the daily counts of the period as a CSV download', async ({ request }) => {
        const r = await download(request, '/staff/attendance/export.csv?days=7', ctx.staff);
        expect(r.status).toBe(200);
        expect(r.headers['content-type']).toContain('text/csv');
        expect(r.headers['content-disposition']).toContain('attachment; filename="presenze_7g.csv"');
        const lines = r.text.replace(/^﻿/, '').trim().split(/\r?\n/);
        expect(lines).toHaveLength(8);
        const members = F.dbInt(`SELECT COUNT(*) FROM presenze WHERE tipo='socio' AND DATE(created_at)=CURDATE()`);
        expect(lines[7].split(';').slice(0, 2)).toEqual([ctx.today, String(members)]);
        expect((await F.api(request, 'GET', '/staff/attendance/export.csv?days=5', { token: ctx.staff })).status).toBe(422);
    });

    test('retention settings: Staff 403, administrator saves, invalid → 422', async ({ request }) => {
        const denied = await F.api(request, 'PUT', '/staff/attendance/settings', { token: ctx.staff, body: { retention_days: 30 } });
        expect(denied.status).toBe(403);
        expect(denied.json.error.code).toBe('not_permitted');
        for (const v of [-1, 3651, 'abc', null, 1.5]) {
            const bad = await F.api(request, 'PUT', '/staff/attendance/settings', { token: ctx.admin, body: { retention_days: v } });
            expect(bad.status, String(v)).toBe(422);
            expect(bad.json.meta.fields.retention_days).toBeTruthy();
        }
        const r = await F.api(request, 'PUT', '/staff/attendance/settings', { token: ctx.admin, body: { retention_days: 400 } });
        expect(r.status, r.text.slice(0, 300)).toBe(200);
        expect(r.json.data.retention.days).toBe(400);
        expect(F.dbScalar("SELECT setting_value FROM system_settings WHERE category='attendance' AND setting_key='retention_days'")).toBe('400');
        expect((await F.api(request, 'GET', '/staff/attendance', { token: ctx.staff })).json.data.retention.days).toBe(400);
    });

    test('purge: Staff 403; the administrator deletes the visits older than the retention only', async ({ request }) => {
        const oldId = F.dbInsert(`INSERT INTO presenze (utente_id, tipo, nome_ospite, created_at) VALUES (NULL, 'ospite', ${F.sqlStr(`Vecchio ${RUN}`)}, NOW() - INTERVAL 450 DAY)`);
        ctx.visits.push(oldId);
        const denied = await F.api(request, 'POST', '/staff/attendance/purge', { token: ctx.staff });
        expect(denied.status).toBe(403);
        expect(F.dbInt(`SELECT COUNT(*) FROM presenze WHERE id=${oldId}`)).toBe(1);
        const r = await F.api(request, 'POST', '/staff/attendance/purge', { token: ctx.admin });
        expect(r.status, r.text.slice(0, 300)).toBe(200);
        expect(r.json.data.deleted).toBeGreaterThanOrEqual(1);
        expect(r.json.data.retention.days).toBe(400);
        expect(F.dbInt(`SELECT COUNT(*) FROM presenze WHERE id=${oldId}`)).toBe(0);
        expect(F.dbInt(`SELECT COUNT(*) FROM presenze WHERE id IN (${ctx.visits.slice(0, -1).join(',')})`)).toBe(ctx.visits.length - 1);
    });

    test('visitor_log off → 404 feature_disabled on every route, nothing recorded', async ({ request }) => {
        await setFeatures({ visitor_log: '0' });
        try {
            const before = F.dbInt('SELECT COUNT(*) FROM presenze');
            expect((await F.api(request, 'GET', '/health')).json.data.features.staff_attendance).toBe(false);
            for (const [method, route] of ROUTES().filter(([, r]) => r.startsWith('/staff/attendance'))) {
                const r = await F.api(request, method, route, { token: ctx.admin, body: method === 'GET' ? undefined : { card_code: ctx.active.card, retention_days: 30 } });
                expect(r.status, `${method} ${route}`).toBe(404);
                expect(r.json.error.code).toBe('feature_disabled');
            }
            expect(F.dbInt('SELECT COUNT(*) FROM presenze')).toBe(before);
        } finally {
            await setFeatures({ visitor_log: '1' });
        }
    });
});

test.describe('Statistics', () => {
    test('the API reports the numbers the web statistics page shows', async ({ request, browser }) => {
        const r = await F.api(request, 'GET', '/staff/statistics', { token: ctx.staff });
        expect(r.status, r.text.slice(0, 300)).toBe(200);
        const d = r.json.data;
        const page = await browser.newPage();
        try {
            await F.adminLogin(page);
            await page.goto(`${F.BASE}/admin/statistics`);
            const cards = await page.locator('.grid p.text-2xl').allInnerTexts();
            const num = (s) => parseInt(String(s).replace(/[^0-9]/g, ''), 10);
            const o = d.overview;
            expect(cards.slice(0, 6).map(num)).toEqual([o.books_available, o.books_on_loan, o.active_loans, o.overdue_loans, o.completed_loans, o.borrowers]);
            for (const m of d.loans_by_media_type) {
                expect(num(await page.locator(`#stats-loans-by-media tr[data-media="${m.media_type}"] [data-count]`).innerText()), m.media_type).toBe(m.total);
            }
            expect(num(await page.locator('[data-visitors="totale"]').innerText())).toBe(d.visitors.total);
            expect(num(await page.locator('[data-visitors="soci"]').innerText())).toBe(d.visitors.members);
            const chart = await page.evaluate(() => (typeof loansByMonthData !== 'undefined' ? loansByMonthData : null)).catch(() => null);
            if (chart) {
                expect(d.loans_by_month).toEqual(chart.map((x) => ({ month: x.mese, total: Number(x.totale_prestiti) })));
            }
        } finally {
            await page.close();
        }
        expect(d.top_books.length).toBeLessThanOrEqual(10);
        expect(d.top_books.every((b) => F.dbInt(`SELECT COUNT(*) FROM libri WHERE id=${b.id} AND deleted_at IS NULL`) === 1)).toBe(true);
        expect(d.visitors.members).toBe(F.dbInt(`SELECT COUNT(*) FROM presenze WHERE tipo='socio' AND created_at >= CURDATE() - INTERVAL 29 DAY`));
        expect(d.sections.map((s) => s.id)).toContain('patron-types');
        expect(r.json.meta.modules).toMatchObject({ visitor_log: true, circulation_rules: true, fines: true });
    });

    test('fines: report and CSV carry the ledger movements; the section follows the module', async ({ request }) => {
        F.dbExec(`INSERT INTO movimenti_lettore (utente_id, tipo, importo, descrizione, created_at) VALUES (${ctx.active.id}, 'addebito_manuale', 2.50, ${F.sqlStr(`E2E ${RUN}`)}, NOW())`);
        const month = ctx.today.slice(0, 7);
        const r = await F.api(request, 'GET', '/staff/statistics', { token: ctx.staff });
        const report = r.json.data.fines_report;
        expect(report.to).toBe(ctx.today);
        expect(report.currency).toMatch(/^[A-Z]{3}$/);
        const row = report.months.find((m) => m.month === month);
        const charged = Math.round(parseFloat(F.dbScalar(`SELECT SUM(importo) FROM movimenti_lettore WHERE tipo IN ('multa_ritardo','addebito_perdita','addebito_danno','addebito_manuale') AND DATE_FORMAT(created_at, '%Y-%m')='${month}'`)) * 100);
        expect(row.charged_cents).toBe(charged);
        expect(report.totals.charged_cents).toBeGreaterThanOrEqual(250);
        expect(r.json.data.sections.map((s) => s.id)).toContain('fines');

        const csv = await download(request, `/staff/statistics/fines.csv?fines_from=${month}-01&fines_to=${ctx.today}`, ctx.staff);
        expect(csv.status).toBe(200);
        expect(csv.headers['content-type']).toContain('text/csv');
        expect(csv.headers['content-disposition']).toContain(`attachment; filename="multe_${month}-01_${ctx.today}.csv"`);
        const line = csv.text.split(/\r?\n/).find((l) => l.startsWith(`${month},`));
        expect(line, csv.text).toBeTruthy();
        expect(Math.round(parseFloat(line.split(',')[1]) * 100)).toBe(charged);

        // Swapped range and validation.
        const swapped = await F.api(request, 'GET', `/staff/statistics?fines_from=${ctx.today}&fines_to=2020-01-01`, { token: ctx.staff });
        expect(swapped.json.data.fines_report).toMatchObject({ from: '2020-01-01', to: ctx.today });
        for (const q of ['fines_from=2026-13-01', 'fines_to=yesterday']) {
            const bad = await F.api(request, 'GET', `/staff/statistics?${q}`, { token: ctx.staff });
            expect(bad.status, q).toBe(422);
            expect(bad.json.error.code).toBe('validation');
            expect((await F.api(request, 'GET', `/staff/statistics/fines.csv?${q}`, { token: ctx.staff })).status).toBe(422);
        }
    });

    test('each section disappears with its module; fines.csv answers 404 feature_disabled', async ({ request }) => {
        await setFeatures({ visitor_log: '0', fines: '0', circulation_rules: '0', stocktake: '0' });
        try {
            const r = await F.api(request, 'GET', '/staff/statistics', { token: ctx.staff });
            expect(r.status).toBe(200);
            const d = r.json.data;
            expect('visitors' in d).toBe(false);
            expect('fines_report' in d).toBe(false);
            expect(d.sections).toEqual([]);
            expect(d.overview.books_available).toBeGreaterThanOrEqual(0);
            expect(r.json.meta.modules).toEqual({ visitor_log: false, circulation_rules: false, fines: false, stocktake: false });
            const csv = await F.api(request, 'GET', '/staff/statistics/fines.csv', { token: ctx.staff });
            expect(csv.status).toBe(404);
            expect(csv.json.error.code).toBe('feature_disabled');
        } finally {
            await setFeatures({ visitor_log: '1', fines: '1', circulation_rules: '1', stocktake: ctx.features.stocktake });
        }
        const back = (await F.api(request, 'GET', '/staff/statistics', { token: ctx.staff })).json.data;
        expect(back.visitors).toBeTruthy();
        expect(back.fines_report).toBeTruthy();
        expect(back.sections.map((s) => s.id)).toEqual(expect.arrayContaining(['patron-types', 'fines']));
    });
});

test.describe('Patron CSV import', () => {
    test('example.csv download', async ({ request }) => {
        const r = await download(request, '/staff/user-imports/example.csv', ctx.staff);
        expect(r.status).toBe(200);
        expect(r.headers['content-type']).toContain('text/csv');
        expect(r.headers['content-disposition']).toContain('attachment; filename="esempio_import_utenti.csv"');
        expect(r.text).toContain('nome;cognome;email');
    });

    test('upload validation: no file, wrong extension, empty file, bad delimiter → 422', async ({ request }) => {
        const before = F.dbInt('SELECT COUNT(*) FROM mobile_import_jobs');
        for (const [multipart, field] of [
            [{ delimiter: ';' }, 'file'],
            [{ file: { name: 'x.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4') } }, 'file'],
            [{ file: csvFile('empty.csv', ['nome;email']) }, 'file'],
            [{ file: csvFile('ok.csv', ['nome;email', 'A;a@example.test']), delimiter: '|' }, 'delimiter'],
        ]) {
            const r = await F.api(request, 'POST', '/staff/user-imports', { token: ctx.staff, multipart });
            expect(r.status, JSON.stringify(Object.keys(multipart))).toBe(422);
            expect(r.json.error.code).toBe('validation');
            expect(r.json.meta.fields[field]).toBeTruthy();
        }
        expect(F.dbInt('SELECT COUNT(*) FROM mobile_import_jobs')).toBe(before);
    });

    test('full import: valid rows created, duplicates skipped, invalid rows reported, no password imported', async ({ request }) => {
        const lines = [
            'nome;cognome;email;password;codice_tessera;telefono',
            `Anna;Import;${email('imp_a')};Segreta123!;${TAG}IA;+39 333 0000001`,
            `Bruno;Import;${email('imp_b')};Segreta456!;;+39 333 0000002`,
            `Carla;Dup;${email('staff')};x;;`,
            `Anna;Bis;${email('imp_a')};x;;`,
            `;SenzaNome;${email('imp_c')};x;;`,
            'Dario;Mail;not-an-email;x;;',
        ];
        const up = await upload(request, ctx.staff, csvFile(`soci_${RUN}.csv`, lines), ';');
        expect(up.status, up.text.slice(0, 400)).toBe(201);
        const job = up.json.data;
        ctx.fullJob = job.id;
        expect(job).toMatchObject({ kind: 'users', status: 'ready', started: false, total_rows: 6, processed: 0, chunk_size: 10, send_invites: null });
        expect(job.headers).toEqual(['nome', 'cognome', 'email', 'password', 'codice_tessera', 'telefono']);
        expect(job.mapping).toEqual(['nome', 'cognome', 'email', '', 'codice_tessera', 'telefono']);
        expect(job.preview).toHaveLength(5);
        expect(job.fields.filter((f) => f.required).map((f) => f.key)).toEqual(['nome', 'email']);
        expect(job.fields.map((f) => f.key)).not.toContain('password');
        expect(fs.existsSync(stagedFile(job.id))).toBe(true);
        // The list finds the job (resume from another device) and holds only patron imports.
        const listed = await F.api(request, 'GET', '/staff/user-imports', { token: ctx.staff });
        expect(listed.status, listed.text).toBe(200);
        expect(listed.json.data.map((j) => j.id)).toContain(job.id);
        expect(listed.json.data.every((j) => j.kind === 'users')).toBe(true);

        // Chunk before start, invalid mappings.
        const early = await F.api(request, 'POST', `/staff/user-imports/${job.id}/chunk`, { token: ctx.staff, body: {} });
        expect(early.status).toBe(409);
        expect(early.json.error.code).toBe('not_started');
        for (const mapping of [['nome', 'cognome', '', '', '', ''], ['nome', 'nome', 'email', '', '', ''], ['nome', 'cognome', 'email', 'password', '', '']]) {
            const bad = await F.api(request, 'POST', `/staff/user-imports/${job.id}/start`, { token: ctx.staff, body: { mapping } });
            expect(bad.status, JSON.stringify(mapping)).toBe(422);
            expect(bad.json.meta.fields.mapping).toBeTruthy();
        }
        expect((await F.api(request, 'POST', `/staff/user-imports/${job.id}/start`, { token: ctx.staff, body: { send_invites: 'maybe' } })).status).toBe(422);

        // The librarian changes the suggestion: the phone column is ignored.
        const mapping = { 0: 'nome', 1: 'cognome', 2: 'email', 4: 'codice_tessera' };
        const start = await F.api(request, 'POST', `/staff/user-imports/${job.id}/start`, { token: ctx.staff, body: { mapping, send_invites: false } });
        expect(start.status, start.text.slice(0, 300)).toBe(200);
        expect(start.json.data).toMatchObject({ started: true, send_invites: false, mapping: ['nome', 'cognome', 'email', '', 'codice_tessera', ''] });
        const twice = await F.api(request, 'POST', `/staff/user-imports/${job.id}/start`, { token: ctx.staff, body: { mapping } });
        expect(twice.status).toBe(409);
        expect(twice.json.error.code).toBe('already_started');

        const ooo = await F.api(request, 'POST', `/staff/user-imports/${job.id}/chunk`, { token: ctx.staff, body: { start: 5 } });
        expect(ooo.status).toBe(409);
        expect(ooo.json.error.code).toBe('out_of_order');
        expect(ooo.json.meta.processed).toBe(0);
        expect((await F.api(request, 'POST', `/staff/user-imports/${job.id}/chunk`, { token: ctx.staff, body: { start: 'x' } })).status).toBe(422);

        const chunk = await F.api(request, 'POST', `/staff/user-imports/${job.id}/chunk`, { token: ctx.staff, body: { start: 0 } });
        expect(chunk.status, chunk.text.slice(0, 400)).toBe(200);
        const done = chunk.json.data;
        expect(done).toMatchObject({ status: 'completed', complete: true, processed: 6, created: 2, duplicates: 2, failed: 2, invited: 0, report_available: true });
        expect(done.import_id).toMatch(/^[0-9a-f-]{36}$/);
        expect(done.errors.map((e) => [e.line, e.type])).toEqual([[4, 'duplicate'], [5, 'duplicate'], [6, 'validation'], [7, 'validation']]);
        expect(fs.existsSync(stagedFile(job.id))).toBe(false);

        const a = F.dbRows(`SELECT id, nome, cognome, codice_tessera, COALESCE(telefono, '') AS telefono, password, stato, tipo_utente FROM utenti WHERE email=${F.sqlStr(email('imp_a'))}`);
        expect(a).toHaveLength(1);
        expect(a[0]).toMatchObject({ nome: 'Anna', cognome: 'Import', codice_tessera: `${TAG}IA`, telefono: '', stato: 'attivo', tipo_utente: 'standard' });
        // The check itself works: the staff account's real password matches its hash.
        expect(passwordMatches(F.dbScalar(`SELECT password FROM utenti WHERE id=${ctx.staffId}`), PASS)).toBe(true);
        expect(a[0].password).toMatch(/^\$2y\$/);
        expect(passwordMatches(a[0].password, 'Segreta123!')).toBe(false);
        const b = F.dbRows(`SELECT codice_tessera, password FROM utenti WHERE email=${F.sqlStr(email('imp_b'))}`);
        expect(b).toHaveLength(1);
        expect(b[0].codice_tessera).toMatch(/^T[0-9A-F]{10}$/);
        expect(passwordMatches(b[0].password, 'Segreta456!')).toBe(false);
        expect(F.dbInt(`SELECT COUNT(*) FROM utenti WHERE email IN (${F.sqlStr(email('imp_c'))}, 'not-an-email')`)).toBe(0);
        expect(F.dbInt(`SELECT COUNT(*) FROM utenti WHERE email=${F.sqlStr(email('staff'))}`)).toBe(1);
        expect(F.dbScalar(`SELECT CONCAT(import_type, '|', imported, '|', failed, '|', user_id) FROM import_logs WHERE import_id=${F.sqlStr(done.import_id)}`)).toBe(`users|2|2|${ctx.staffId}`);

        const gone = await F.api(request, 'POST', `/staff/user-imports/${job.id}/chunk`, { token: ctx.staff, body: { start: 6 } });
        expect(gone.status).toBe(410);
        expect(gone.json.error.code).toBe('gone');
        expect(gone.json.meta.status).toBe('completed');

        const report = await download(request, `/staff/user-imports/${job.id}/errors.csv`, ctx.staff);
        expect(report.status).toBe(200);
        expect(report.headers['content-type']).toContain('text/csv');
        expect(report.headers['content-disposition']).toMatch(/attachment; filename="[^"]+\.csv"/);
        expect(report.text).toContain(email('imp_a'));
        expect(report.text).toContain('duplicate');

        const detail = await F.api(request, 'GET', `/staff/user-imports/${job.id}`, { token: ctx.staff });
        expect(detail.json.data).toMatchObject({ status: 'completed', created: 2, mapping: ['nome', 'cognome', 'email', '', 'codice_tessera', ''] });
    });

    test('job ownership: another Staff account sees 404, an administrator sees the job', async ({ request }) => {
        for (const [method, suffix] of [['GET', ''], ['POST', '/start'], ['POST', '/chunk'], ['DELETE', ''], ['GET', '/errors.csv']]) {
            const r = await F.api(request, method, `/staff/user-imports/${ctx.fullJob}${suffix}`, { token: ctx.staff2, body: method === 'POST' ? {} : undefined });
            expect(r.status, `${method} ${suffix}`).toBe(404);
            expect(r.json.error.code).toBe('not_found');
        }
        const admin = await F.api(request, 'GET', `/staff/user-imports/${ctx.fullJob}`, { token: ctx.admin });
        expect(admin.status).toBe(200);
        expect(admin.json.data.created).toBe(2);
        expect((await F.api(request, 'GET', `/staff/user-imports/${JOB0}`, { token: ctx.admin })).status).toBe(404);
    });

    test('cancel before start releases the upload; cancel is idempotent; the job is gone', async ({ request }) => {
        const up = await upload(request, ctx.staff, csvFile('cancel.csv', ['nome,email', `Elsa,${email('imp_e')}`]), '');
        expect(up.status, up.text.slice(0, 300)).toBe(201);
        const id = up.json.data.id;
        expect(up.json.data.mapping).toEqual(['nome', 'email']);
        const file = stagedFile(id);
        expect(fs.existsSync(file)).toBe(true);
        const r = await F.api(request, 'DELETE', `/staff/user-imports/${id}`, { token: ctx.staff });
        expect(r.status, r.text.slice(0, 300)).toBe(200);
        expect(r.json.data).toMatchObject({ id, cancelled: true, status: 'cancelled', import_id: null });
        expect(fs.existsSync(file)).toBe(false);
        expect((await F.api(request, 'DELETE', `/staff/user-imports/${id}`, { token: ctx.staff })).status).toBe(200);
        const start = await F.api(request, 'POST', `/staff/user-imports/${id}/start`, { token: ctx.staff, body: {} });
        expect(start.status).toBe(410);
        expect(start.json.meta.status).toBe('cancelled');
        const report = await F.api(request, 'GET', `/staff/user-imports/${id}/errors.csv`, { token: ctx.staff });
        expect(report.status).toBe(404);
        expect(F.dbInt(`SELECT COUNT(*) FROM utenti WHERE email=${F.sqlStr(email('imp_e'))}`)).toBe(0);
    });

    test('cancel mid-run keeps the accounts already created and writes the partial run to the history', async ({ request }) => {
        const lines = ['nome;email'];
        for (let i = 1; i <= 15; i++) lines.push(`Lotto${i};${email(`lot${i}`)}`);
        const up = await upload(request, ctx.staff, csvFile('lotto.csv', lines), ';');
        const id = up.json.data.id;
        expect((await F.api(request, 'POST', `/staff/user-imports/${id}/start`, { token: ctx.staff, body: {} })).status).toBe(200);
        const first = await F.api(request, 'POST', `/staff/user-imports/${id}/chunk`, { token: ctx.staff, body: { start: 0 } });
        expect(first.json.data).toMatchObject({ status: 'ready', processed: 10, created: 10, complete: false });
        const report = await F.api(request, 'GET', `/staff/user-imports/${id}/errors.csv`, { token: ctx.staff });
        expect(report.status).toBe(409);
        expect(report.json.error.code).toBe('import_not_finished');
        const file = stagedFile(id);
        const r = await F.api(request, 'DELETE', `/staff/user-imports/${id}`, { token: ctx.staff });
        expect(r.status, r.text.slice(0, 300)).toBe(200);
        expect(r.json.data.import_id).toMatch(/^[0-9a-f-]{36}$/);
        expect(fs.existsSync(file)).toBe(false);
        expect(F.dbScalar(`SELECT CONCAT(imported, '|', total_rows) FROM import_logs WHERE import_id=${F.sqlStr(r.json.data.import_id)}`)).toBe('10|10');
        expect(F.dbInt(`SELECT COUNT(*) FROM utenti WHERE email LIKE ${F.sqlStr(`desk_lot%_${RUN}@example.test`)}`)).toBe(10);
        const detail = (await F.api(request, 'GET', `/staff/user-imports/${id}`, { token: ctx.staff })).json.data;
        expect(detail).toMatchObject({ status: 'cancelled', import_id: r.json.data.import_id, report_available: true, started: true });
        expect((await download(request, `/staff/user-imports/${id}/errors.csv`, ctx.staff)).status).toBe(200);
        expect((await F.api(request, 'POST', `/staff/user-imports/${id}/chunk`, { token: ctx.staff, body: { start: 10 } })).status).toBe(410);
    });

    test('a staged file that vanished fails the job with file_missing', async ({ request }) => {
        const up = await upload(request, ctx.staff, csvFile('vanish.csv', ['nome;email', `Fede;${email('imp_f')}`]), ';');
        const id = up.json.data.id;
        expect((await F.api(request, 'POST', `/staff/user-imports/${id}/start`, { token: ctx.staff, body: {} })).status).toBe(200);
        fs.unlinkSync(stagedFile(id));
        const r = await F.api(request, 'POST', `/staff/user-imports/${id}/chunk`, { token: ctx.staff, body: {} });
        expect(r.status, r.text.slice(0, 300)).toBe(409);
        expect(r.json.error.code).toBe('file_missing');
        expect((await F.api(request, 'GET', `/staff/user-imports/${id}`, { token: ctx.staff })).json.data.status).toBe('failed');
        expect((await F.api(request, 'POST', `/staff/user-imports/${id}/chunk`, { token: ctx.staff, body: {} })).status).toBe(410);
        expect(F.dbInt(`SELECT COUNT(*) FROM utenti WHERE email=${F.sqlStr(email('imp_f'))}`)).toBe(0);
    });

    test('user_import off → 404 feature_disabled on every route', async ({ request }) => {
        await setFeatures({ user_import: '0' });
        try {
            expect((await F.api(request, 'GET', '/health')).json.data.features.staff_user_import).toBe(false);
            for (const [method, route] of ROUTES().filter(([, r]) => r.startsWith('/staff/user-imports'))) {
                const r = await F.api(request, method, route.replace(JOB0, ctx.fullJob), { token: ctx.admin, body: method === 'POST' ? {} : undefined });
                expect(r.status, `${method} ${route}`).toBe(404);
                expect(r.json.error.code).toBe('feature_disabled');
            }
        } finally {
            await setFeatures({ user_import: '1' });
        }
    });
});

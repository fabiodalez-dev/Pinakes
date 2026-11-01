// @ts-check
// Mobile API 1.10.0 — library settings of the Pinakes 0.9.0 modules:
// feature switches, loan and card settings, patron types and the rules
// matrix, the closures calendar (closures, holiday presets, yearly switches,
// the library's own holidays, ICS import).
//
// What this proves, end to end against the real Apache instance: every write
// goes through the web action with the complete form, so a partial save keeps
// every untouched setting; library policy stays administrator-only (Staff gets
// 403, a reader 403 from the role gate); the circulation rules and the
// calendar answer an administrator even while their module is off
// (configuration mode) and answer Staff 404 feature_disabled then; a closure
// added from the app shows up in the reader's /catalog/books/{id}/availability
// closed_dates. Every setting and row it changes is put back in afterAll.
//
// Run: /tmp/run-e2e.sh tests/mobile-api-staff-090-settings.spec.js --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
const F = require('./helpers/mobile-api-fixtures');
const featureToggles = require('./helpers/feature-toggles');
const { flushCache } = require('./helpers/flush-cache');

test.describe.configure({ mode: 'serial' });
test.skip(!F.ENV_READY, 'Missing E2E env: DB_* and ADMIN_EMAIL/ADMIN_PASS');

const RUN = `${Date.now()}_${process.pid}`;
const PASS = 'Set090Test!';
/** @type {Record<string, any>} */
const ctx = { users: [] };

const SETTING_CATEGORIES = ['features', 'loans', 'cards', 'attendance', 'calendar', 'app'];

/** Every row of the touched setting categories, NULL-safe and byte-exact (HEX). */
function snapshotSettings() {
    const list = SETTING_CATEGORIES.map((c) => `'${c}'`).join(',');
    return F.dbRows(`SELECT category, setting_key, setting_value IS NULL AS isnull, HEX(setting_value) AS hex FROM system_settings WHERE category IN (${list})`);
}
function restoreSettings(rows) {
    const list = SETTING_CATEGORIES.map((c) => `'${c}'`).join(',');
    const keep = rows.map((r) => `(${F.sqlStr(r.category)}, ${F.sqlStr(r.setting_key)})`);
    F.dbExec(`DELETE FROM system_settings WHERE category IN (${list})${keep.length ? ` AND (category, setting_key) NOT IN (${keep.join(',')})` : ''}`);
    for (const r of rows) {
        const value = r.isnull === '1' ? 'NULL' : (r.hex === '' ? "''" : `CONVERT(UNHEX('${r.hex}') USING utf8mb4)`);
        F.dbExec(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES (${F.sqlStr(r.category)}, ${F.sqlStr(r.setting_key)}, ${value}) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)`);
    }
}
/** A table as JSON rows (all columns), for an exact put-back. */
function snapshotTable(table) {
    const out = F.dbScalar(`SELECT COALESCE(JSON_ARRAYAGG(JSON_OBJECT(${columns(table).map((c) => `'${c}', \`${c}\``).join(', ')})), JSON_ARRAY()) FROM ${table}`);
    return JSON.parse(out || '[]');
}
function columns(table) {
    return F.dbRows(`SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${table}' AND EXTRA NOT LIKE '%GENERATED%' ORDER BY ORDINAL_POSITION`).map((r) => r.COLUMN_NAME);
}
function restoreTable(table, rows) {
    const cols = columns(table);
    F.dbExec(`DELETE FROM ${table}`);
    for (const row of rows) {
        const values = cols.map((c) => (row[c] === null || row[c] === undefined ? 'NULL' : F.sqlStr(row[c])));
        F.dbExec(`INSERT INTO ${table} (${cols.map((c) => `\`${c}\``).join(', ')}) VALUES (${values.join(', ')})`);
    }
}

/** YYYY-MM-DD, $days from today in the app timezone (Europe/Rome by default). */
function day(days) {
    const d = new Date(Date.now() + days * 86400000);
    return d.toLocaleDateString('sv-SE', { timeZone: 'Europe/Rome' });
}
/** First date >= $days ahead that is not a Sunday (a weekly closure in the fixture would hide the effect). */
function weekday(days) {
    for (let i = days; i < days + 7; i++) {
        if (new Date(`${day(i)}T12:00:00Z`).getUTCDay() !== 0) return day(i);
    }
    return day(days);
}

const api = (request, method, path, who, body) => F.api(request, method, path, { token: ctx[who], body });

test.beforeAll(async ({ browser, request }) => {
    const page = await browser.newPage();
    ctx.gate = await F.ensureMobileApiReady(page);
    await page.close();
    ctx.settings = snapshotSettings();
    ctx.types = snapshotTable('tipologie_lettore');
    ctx.rules = snapshotTable('regole_prestito');
    ctx.closures = snapshotTable('calendario_chiusure');
    ctx.customs = snapshotTable('festivita_personalizzate');
    // Every module this suite needs on; the snapshot above puts back what was there.
    await featureToggles.writeFeatures({ circulation_rules: '1', closures_calendar: '1', patron_cards: '1' });
    for (const [key, role] of [['staff', 'staff'], ['reader', 'standard']]) {
        const email = `s090_${key}_${RUN}@pinakes.test`;
        ctx[`${key}Id`] = F.createUser(email, PASS, role, `S9${key.slice(0, 2).toUpperCase()}`);
        ctx.users.push(ctx[`${key}Id`]);
        ctx[key] = await F.login(request, email, PASS, `s090-${key}`);
    }
    ctx.admin = await F.login(request, F.ADMIN_EMAIL, F.ADMIN_PASS, 's090-admin');
    ctx.bookId = F.dbInt('SELECT id FROM libri WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
});

test.afterAll(async () => {
    try { F.dbExec(`DELETE FROM mobile_idempotency_keys WHERE user_id IN (${[...ctx.users, F.dbInt(`SELECT id FROM utenti WHERE email=${F.sqlStr(F.ADMIN_EMAIL)}`)].join(',')}) AND scope IN ('patron_type_create','calendar_closure_create','calendar_custom_holiday_create')`); } catch {}
    try { if (ctx.types) { F.dbExec('UPDATE utenti SET tipologia_id = NULL WHERE tipologia_id NOT IN (SELECT id FROM (SELECT id FROM tipologie_lettore) t)'); } } catch {}
    try { if (ctx.rules) restoreTable('regole_prestito', ctx.rules); } catch (e) { console.error('restore rules', e); }
    try { if (ctx.types) restoreTable('tipologie_lettore', ctx.types); } catch (e) { console.error('restore types', e); }
    try { if (ctx.closures) restoreTable('calendario_chiusure', ctx.closures); } catch (e) { console.error('restore closures', e); }
    try { if (ctx.customs) restoreTable('festivita_personalizzate', ctx.customs); } catch (e) { console.error('restore customs', e); }
    try { if (ctx.settings) restoreSettings(ctx.settings); } catch (e) { console.error('restore settings', e); }
    try { F.dbExec(`DELETE FROM log_modifiche WHERE utente_id IN (${ctx.users.join(',') || 0})`); } catch {}
    F.deleteUsers(ctx.users);
    F.restoreGate(ctx.gate);
    await flushCache();
});

// ─── Discovery and role gate ─────────────────────────────────────────────

test('health advertises the three areas; a reader gets 403 everywhere', async ({ request }) => {
    const f = (await F.api(request, 'GET', '/health')).json.data.features;
    expect(f.staff_library_settings).toBe(true);
    expect(f.staff_circulation_rules).toBe(true);
    expect(f.staff_closures_calendar).toBe(true);
    for (const [method, path] of [
        ['GET', '/staff/settings/modules'], ['PUT', '/staff/settings/modules'], ['GET', '/staff/settings/loans'], ['PUT', '/staff/settings/loans'],
        ['GET', '/staff/settings/cards'], ['PUT', '/staff/settings/cards'], ['GET', '/staff/circulation/types'], ['POST', '/staff/circulation/types'],
        ['GET', '/staff/circulation/rules'], ['PUT', '/staff/circulation/rules'], ['GET', '/staff/calendar'], ['PUT', '/staff/calendar/weekdays'],
        ['POST', '/staff/calendar/closures'], ['GET', '/staff/calendar/holidays/countries'], ['POST', '/staff/calendar/custom-holidays'],
    ]) {
        const r = await api(request, method, path, 'reader', method === 'GET' ? undefined : {});
        expect(r.status, `${method} ${path}`).toBe(403);
    }
    expect((await F.api(request, 'GET', '/staff/settings/modules')).status).toBe(401);
});

// ─── Modules ─────────────────────────────────────────────────────────────

test('modules: staff reads but may not save; admin saves a partial map and keeps the rest', async ({ request }) => {
    const staffView = await api(request, 'GET', '/staff/settings/modules', 'staff');
    expect(staffView.status).toBe(200);
    expect(staffView.json.data.can_configure).toBe(false);
    const forbidden = await api(request, 'PUT', '/staff/settings/modules', 'staff', { features: { stocktake: true } });
    expect(forbidden.status).toBe(403);
    expect(forbidden.json.error.code).toBe('forbidden');

    const before = await api(request, 'GET', '/staff/settings/modules', 'admin');
    expect(before.status).toBe(200);
    expect(before.json.data.can_configure).toBe(true);
    const modules = Object.fromEntries(before.json.data.modules.map((m) => [m.key, m]));
    expect(Object.keys(modules)).toHaveLength(18);
    expect(modules.automatic_fines).toMatchObject({ group: 'loans', parent: 'fines', inherits_from: 'fines', circulation: true });
    expect(modules.stocktake.group).toBe('modules');
    const loansBefore = (await api(request, 'GET', '/staff/settings/loans', 'admin')).json.data.settings;

    // One Modules-tab switch, one Loans-tab switch, the retention.
    const wantStock = !modules.stocktake.stored;
    const wantSuppliers = modules.suppliers.stored;
    const wantFines = !modules.fines.stored;
    const saved = await api(request, 'PUT', '/staff/settings/modules', 'admin', { features: { stocktake: wantStock, fines: wantFines }, attendance_retention_days: 120 });
    expect(saved.status, saved.text.slice(0, 300)).toBe(200);
    const after = Object.fromEntries(saved.json.data.modules.map((m) => [m.key, m]));
    expect(after.stocktake.stored).toBe(wantStock);
    expect(after.fines.stored).toBe(wantFines);
    expect(saved.json.data.attendance_retention_days).toBe(120);
    expect(F.dbScalar("SELECT setting_value FROM system_settings WHERE category='features' AND setting_key='stocktake'")).toBe(wantStock ? '1' : '0');
    expect(F.dbScalar("SELECT setting_value FROM system_settings WHERE category='attendance' AND setting_key='retention_days'")).toBe('120');
    // Untouched switches and every loan setting survive both delegated saves.
    for (const key of Object.keys(modules)) {
        if (!['stocktake', 'fines', 'automatic_fines', 'debt_block', 'loss_penalties'].includes(key)) {
            expect(after[key].stored, `switch ${key} kept`).toBe(modules[key].stored);
        }
    }
    expect(after.suppliers.stored).toBe(wantSuppliers);
    expect((await api(request, 'GET', '/staff/settings/loans', 'admin')).json.data.settings).toEqual(loansBefore);

    // Switch an inherited control off explicitly: it must really go off.
    const photos = await api(request, 'PUT', '/staff/settings/modules', 'admin', { features: { patron_photos: false } });
    expect(photos.status).toBe(200);
    expect(photos.json.data.modules.find((m) => m.key === 'patron_photos').stored).toBe(false);

    const bad = await api(request, 'PUT', '/staff/settings/modules', 'admin', { features: { nonexistent: true, marc: 'yes' }, attendance_retention_days: -1 });
    expect(bad.status).toBe(422);
    expect(bad.json.error.code).toBe('validation');
    expect(Object.keys(bad.json.meta.fields).sort()).toEqual(['attendance_retention_days', 'features.marc', 'features.nonexistent']);
});

// ─── Loans ───────────────────────────────────────────────────────────────

test('loans: staff saves its fields, gets 403 on money policy; partial saves keep the rest', async ({ request }) => {
    const start = await api(request, 'GET', '/staff/settings/loans', 'staff');
    expect(start.status).toBe(200);
    expect(start.json.data.can_configure).toBe(false);
    expect(start.json.data.editable_fields).not.toContain('daily_fine_cents');
    expect(start.json.data.admin_only_fields).toContain('daily_fine_cents');
    const before = start.json.data.settings;

    const newDuration = before.loan_duration_days === 21 ? 22 : 21;
    const staffSave = await api(request, 'PUT', '/staff/settings/loans', 'staff', { loan_duration_days: newDuration, recall_auto_enabled: !before.recall_auto_enabled });
    expect(staffSave.status, staffSave.text.slice(0, 300)).toBe(200);
    expect(staffSave.json.data.settings).toEqual({ ...before, loan_duration_days: newDuration, recall_auto_enabled: !before.recall_auto_enabled });
    expect(F.dbScalar("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='loan_duration_days'")).toBe(String(newDuration));

    const money = await api(request, 'PUT', '/staff/settings/loans', 'staff', { daily_fine_cents: 100, max_renewals: 2 });
    expect(money.status).toBe(403);
    expect(money.json.error.code).toBe('forbidden');
    expect(Object.keys(money.json.meta.fields)).toEqual(['daily_fine_cents']);
    expect((await api(request, 'GET', '/staff/settings/loans', 'staff')).json.data.settings.max_renewals).toBe(before.max_renewals);

    const adminSave = await api(request, 'PUT', '/staff/settings/loans', 'admin', { daily_fine_cents: 250, fine_grace_mode: 'deduct', fine_max_per_loan_cents: null });
    expect(adminSave.status, adminSave.text.slice(0, 300)).toBe(200);
    expect(adminSave.json.data.settings).toMatchObject({ daily_fine_cents: 250, fine_grace_mode: 'deduct', fine_max_per_loan_cents: null, loan_duration_days: newDuration });
    expect(F.dbScalar("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='daily_fine'")).toBe('2.50');
    expect(adminSave.json.data.editable_fields).toContain('daily_fine_cents');
    // The fine amount survives a later Staff save of another field.
    const staffAgain = await api(request, 'PUT', '/staff/settings/loans', 'staff', { pickup_expiry_days: before.pickup_expiry_days === 5 ? 6 : 5 });
    expect(staffAgain.status).toBe(200);
    expect(staffAgain.json.data.settings).toMatchObject({ daily_fine_cents: 250, fine_grace_mode: 'deduct', loan_duration_days: newDuration });

    const invalid = await api(request, 'PUT', '/staff/settings/loans', 'admin', { loan_duration_days: 0, debt_block_threshold_cents: 1000000, app_timezone: 'Mars/Base', nope: 1 });
    expect(invalid.status).toBe(422);
    expect(Object.keys(invalid.json.meta.fields).sort()).toEqual(['app_timezone', 'debt_block_threshold_cents', 'loan_duration_days', 'nope']);
});

// ─── Cards ───────────────────────────────────────────────────────────────

test('cards: staff edits while patron_cards is on; module off = admin only', async ({ request }) => {
    const start = await api(request, 'GET', '/staff/settings/cards', 'staff');
    expect(start.status).toBe(200);
    expect(start.json.data.options.layout).toEqual(['classic', 'minimal', 'portrait']);
    const before = start.json.data.settings;
    const title = `Tessera ${RUN}`.slice(0, 60);
    const save = await api(request, 'PUT', '/staff/settings/cards', 'staff', { title, show_logo: !before.show_logo });
    expect(save.status, save.text.slice(0, 300)).toBe(200);
    expect(save.json.data.settings).toEqual({ ...before, title, show_logo: !before.show_logo });
    expect(F.dbScalar("SELECT setting_value FROM system_settings WHERE category='cards' AND setting_key='title'")).toBe(title);

    const bad = await api(request, 'PUT', '/staff/settings/cards', 'staff', { layout: 'fancy', title: 'x'.repeat(61), show_photo: 'yes' });
    expect(bad.status).toBe(422);
    expect(Object.keys(bad.json.meta.fields).sort()).toEqual(['layout', 'show_photo', 'title']);

    await featureToggles.writeFeatures({ patron_cards: '0' });
    try {
        const off = await api(request, 'GET', '/staff/settings/cards', 'staff');
        expect(off.status).toBe(404);
        expect(off.json.error.code).toBe('feature_disabled');
        expect((await api(request, 'PUT', '/staff/settings/cards', 'staff', { title: 'x' })).status).toBe(404);
        const adminOff = await api(request, 'GET', '/staff/settings/cards', 'admin');
        expect(adminOff.status).toBe(200);
        expect(adminOff.json.data.module_enabled).toBe(false);
        const adminSave = await api(request, 'PUT', '/staff/settings/cards', 'admin', { code_type: 'qr' });
        expect(adminSave.status).toBe(200);
        expect(adminSave.json.data.settings).toMatchObject({ code_type: 'qr', title });
    } finally {
        await featureToggles.writeFeatures({ patron_cards: '1' });
    }
});

// ─── Patron types ────────────────────────────────────────────────────────

test('patron types: admin only, configuration mode, CRUD with idempotent create', async ({ request }) => {
    expect((await api(request, 'GET', '/staff/circulation/types', 'staff')).status).toBe(403);
    await featureToggles.writeFeatures({ circulation_rules: '0' });
    try {
        const staffOff = await api(request, 'GET', '/staff/circulation/types', 'staff');
        expect(staffOff.status).toBe(404);
        expect(staffOff.json.error.code).toBe('feature_disabled');
        const adminOff = await api(request, 'GET', '/staff/circulation/types', 'admin');
        expect(adminOff.status).toBe(200);
        expect(adminOff.json.data.module_enabled).toBe(false);
        expect(adminOff.json.data.types.length).toBeGreaterThan(0);
    } finally {
        await featureToggles.writeFeatures({ circulation_rules: '1' });
    }

    const list = (await api(request, 'GET', '/staff/circulation/types', 'admin')).json.data;
    ctx.originalDefault = list.default_type_id;
    const body = { name: `Ricercatori ${RUN}`, code: `ric-${process.pid}`, max_loans: 7, daily_fine_cents: 150, reservations_enabled: false };
    const key = `s090-type-${RUN}`;
    const created = await F.api(request, 'POST', '/staff/circulation/types', { token: ctx.admin, body, extraHeaders: { 'Idempotency-Key': key } });
    expect(created.status, created.text.slice(0, 300)).toBe(201);
    expect(created.json.data).toMatchObject({ name: body.name, code: body.code, max_loans: 7, daily_fine_cents: 150, reservations_enabled: false, active: true, is_default: false, user_count: 0 });
    expect(created.json.data.effective.max_loans).toBe(7);
    ctx.typeId = created.json.data.id;
    expect(F.dbScalar(`SELECT multa_giornaliera FROM tipologie_lettore WHERE id=${ctx.typeId}`)).toBe('1.50');
    const replay = await F.api(request, 'POST', '/staff/circulation/types', { token: ctx.admin, body, extraHeaders: { 'Idempotency-Key': key } });
    expect(replay.status).toBe(200);
    expect(replay.json.meta.replayed).toBe(true);
    expect(replay.json.data.id).toBe(ctx.typeId);
    expect(F.dbInt(`SELECT COUNT(*) FROM tipologie_lettore WHERE codice=${F.sqlStr(body.code)}`)).toBe(1);

    const dup = await api(request, 'POST', '/staff/circulation/types', 'admin', { name: `Altro ${RUN}`, code: body.code });
    expect(dup.status).toBe(422);
    expect(Object.keys(dup.json.meta.fields)).toEqual(['code']);
    const badCode = await api(request, 'POST', '/staff/circulation/types', 'admin', { name: `Altro ${RUN}`, code: 'Bad Code!' });
    expect(badCode.status).toBe(422);
    expect(Object.keys(badCode.json.meta.fields)).toEqual(['code']);
    const badRange = await api(request, 'POST', '/staff/circulation/types', 'admin', { name: `Altro ${RUN}`, max_loans: 5000, debt_block_threshold_cents: 1000000 });
    expect(badRange.status).toBe(422);
    expect(Object.keys(badRange.json.meta.fields).sort()).toEqual(['debt_block_threshold_cents', 'max_loans']);
    expect((await api(request, 'POST', '/staff/circulation/types', 'admin', { code: 'x' })).status).toBe(422);
    expect((await api(request, 'POST', '/staff/circulation/types', 'staff', body)).status).toBe(403);

    const one = await api(request, 'GET', `/staff/circulation/types/${ctx.typeId}`, 'admin');
    expect(one.status).toBe(200);
    expect(one.json.data.code).toBe(body.code);
    expect((await api(request, 'GET', '/staff/circulation/types/999999', 'admin')).status).toBe(404);

    const upd = await api(request, 'PUT', `/staff/circulation/types/${ctx.typeId}`, 'admin', { loan_duration_days: 45, description: 'Accesso esteso' });
    expect(upd.status, upd.text.slice(0, 300)).toBe(200);
    expect(upd.json.data).toMatchObject({ loan_duration_days: 45, description: 'Accesso esteso', max_loans: 7, daily_fine_cents: 150, reservations_enabled: false, name: body.name, code: body.code });
    const clear = await api(request, 'PUT', `/staff/circulation/types/${ctx.typeId}`, 'admin', { max_loans: null });
    expect(clear.json.data).toMatchObject({ max_loans: null, loan_duration_days: 45, daily_fine_cents: 150 });

    // Move: the new type is last; up swaps it with the one before.
    const order = (await api(request, 'GET', '/staff/circulation/types', 'admin')).json.data.types.map((t) => t.id);
    const moved = await api(request, 'POST', `/staff/circulation/types/${ctx.typeId}/move`, 'admin', { direction: 'up' });
    expect(moved.status).toBe(200);
    const newOrder = moved.json.data.types.map((t) => t.id);
    const pos = order.indexOf(ctx.typeId);
    if (pos > 0) expect(newOrder.indexOf(ctx.typeId)).toBe(pos - 1);
    expect((await api(request, 'POST', `/staff/circulation/types/${ctx.typeId}/move`, 'admin', { direction: 'left' })).status).toBe(422);

    // Default: the new type becomes the default, then cannot be deleted.
    const def = await api(request, 'POST', `/staff/circulation/types/${ctx.typeId}/default`, 'admin');
    expect(def.status).toBe(200);
    expect(def.json.data.is_default).toBe(true);
    const refused = await api(request, 'DELETE', `/staff/circulation/types/${ctx.typeId}`, 'admin');
    expect(refused.status).toBe(409);
    expect(refused.json.error.code).toBe('default_type');
    if (ctx.originalDefault) {
        expect((await api(request, 'POST', `/staff/circulation/types/${ctx.originalDefault}/default`, 'admin')).json.data.is_default).toBe(true);
    }
});

// ─── Rules matrix ────────────────────────────────────────────────────────

test('rules matrix: complete grid, partial updates keep the other cells', async ({ request }) => {
    const grid = await api(request, 'GET', '/staff/circulation/rules', 'admin');
    expect(grid.status).toBe(200);
    const { media, rows, cells } = grid.json.data;
    expect(cells).toHaveLength(media.length * rows.length);
    expect(rows[0].type_id).toBe(0);
    const m = media[media.length - 1].key;
    const target = cells.find((c) => c.type_id === ctx.typeId && c.media === m);
    expect(target.has_rule).toBe(false);
    expect(target.inherited).toHaveProperty('loan_duration_days', 45);

    const first = await api(request, 'PUT', '/staff/circulation/rules', 'admin', { cells: [{ type_id: ctx.typeId, media: m, max_loans: 2, loanable: false }] });
    expect(first.status, first.text.slice(0, 300)).toBe(200);
    const second = await api(request, 'PUT', '/staff/circulation/rules', 'admin', { cells: [{ type_id: ctx.typeId, media: media[0].key, loan_duration_days: 10, daily_fine_cents: 75 }] });
    expect(second.status).toBe(200);
    const cellA = second.json.data.cells.find((c) => c.type_id === ctx.typeId && c.media === m);
    const cellB = second.json.data.cells.find((c) => c.type_id === ctx.typeId && c.media === media[0].key);
    expect(cellA).toMatchObject({ has_rule: true, max_loans: 2, loanable: false });
    expect(cellB).toMatchObject({ has_rule: true, loan_duration_days: 10, daily_fine_cents: 75, max_loans: null });
    expect(F.dbScalar(`SELECT multa_giornaliera FROM regole_prestito WHERE tipologia_id=${ctx.typeId} AND tipo_media=${F.sqlStr(media[0].key)}`)).toBe('0.75');
    // Every other stored cell is byte-identical.
    for (const before of cells) {
        if (before.type_id === ctx.typeId && [m, media[0].key].includes(before.media)) continue;
        const now = second.json.data.cells.find((c) => c.type_id === before.type_id && c.media === before.media);
        const { inherited: _a, ...b } = before;
        const { inherited: _b, ...n } = now;
        expect(n, `${before.type_id}|${before.media}`).toEqual(b);
    }

    const cleared = await api(request, 'PUT', '/staff/circulation/rules', 'admin', { cells: [{ type_id: ctx.typeId, media: m, max_loans: null, loanable: null }] });
    expect(cleared.json.data.cells.find((c) => c.type_id === ctx.typeId && c.media === m).has_rule).toBe(false);

    const bad = await api(request, 'PUT', '/staff/circulation/rules', 'admin', { cells: [{ type_id: ctx.typeId, media: 'spaceship' }, { type_id: 0, media: m, renewals: 1, max_renewals: 500 }] });
    expect(bad.status).toBe(422);
    expect(Object.keys(bad.json.meta.fields).sort()).toEqual(['cells.0.media', 'cells.1.max_renewals', 'cells.1.renewals']);
    expect((await api(request, 'PUT', '/staff/circulation/rules', 'admin', { cells: 'all' })).status).toBe(422);
    expect((await api(request, 'PUT', '/staff/circulation/rules', 'staff', { cells: [] })).status).toBe(403);
});

test('patron types: delete the new type, then it is gone', async ({ request }) => {
    const del = await api(request, 'DELETE', `/staff/circulation/types/${ctx.typeId}`, 'admin');
    expect(del.status, del.text.slice(0, 300)).toBe(200);
    expect(del.json.data).toMatchObject({ id: ctx.typeId, deleted: true });
    expect(F.dbInt(`SELECT COUNT(*) FROM tipologie_lettore WHERE id=${ctx.typeId}`)).toBe(0);
    expect(F.dbInt(`SELECT COUNT(*) FROM regole_prestito WHERE tipologia_id=${ctx.typeId}`)).toBe(0);
    expect((await api(request, 'DELETE', `/staff/circulation/types/${ctx.typeId}`, 'admin')).status).toBe(404);
});

// ─── Closures calendar ───────────────────────────────────────────────────

test('calendar: admin only, configuration mode, month grid and weekly closed days', async ({ request }) => {
    expect((await api(request, 'GET', '/staff/calendar', 'staff')).status).toBe(403);
    await featureToggles.writeFeatures({ closures_calendar: '0' });
    try {
        const staffOff = await api(request, 'GET', '/staff/calendar', 'staff');
        expect(staffOff.status).toBe(404);
        expect(staffOff.json.error.code).toBe('feature_disabled');
        const adminOff = await api(request, 'GET', '/staff/calendar', 'admin');
        expect(adminOff.status).toBe(200);
        expect(adminOff.json.data.module_enabled).toBe(false);
    } finally {
        await featureToggles.writeFeatures({ closures_calendar: '1' });
    }
    const month = day(40).slice(0, 7);
    const cal = await api(request, 'GET', `/staff/calendar?month=${month}`, 'admin');
    expect(cal.status).toBe(200);
    expect(cal.json.data.month).toBe(month);
    expect(cal.json.data.days[0].date).toBe(`${month}-01`);
    expect(cal.json.data.custom_holiday_options.kinds).toContain('nth_weekday');
    expect((await api(request, 'GET', '/staff/calendar?month=2026-13', 'admin')).status).toBe(422);
    expect((await api(request, 'GET', '/staff/calendar?branch_id=999999', 'admin')).status).toBe(404);

    const set = await api(request, 'PUT', '/staff/calendar/weekdays', 'admin', { closed_weekdays: [0] });
    expect(set.status, set.text.slice(0, 300)).toBe(200);
    expect(set.json.data.closed_weekdays).toEqual([0]);
    const grid = (await api(request, 'GET', `/staff/calendar?month=${month}`, 'admin')).json.data.days;
    const sunday = grid.find((d) => d.weekday === 0);
    expect(sunday.closed).toBe(true);
    expect(sunday.reasons.map((r) => r.type)).toContain('weekly');
    expect(F.dbScalar("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'")).toBe('0');
    const all = await api(request, 'PUT', '/staff/calendar/weekdays', 'admin', { closed_weekdays: [0, 1, 2, 3, 4, 5, 6] });
    expect(all.status).toBe(422);
    expect(Object.keys(all.json.meta.fields)).toEqual(['closed_weekdays']);
    expect((await api(request, 'PUT', '/staff/calendar/weekdays', 'admin', { closed_weekdays: [9] })).status).toBe(422);
    expect((await api(request, 'PUT', '/staff/calendar/weekdays', 'admin', { closed_weekdays: [] })).json.data.closed_weekdays).toEqual([]);
});

test('calendar closures: create (idempotent), visible in availability closed_dates, update, delete', async ({ request }) => {
    const date = weekday(12);
    const body = { start_date: date, description: `Inventario ${RUN}` };
    const key = `s090-closure-${RUN}`;
    const created = await F.api(request, 'POST', '/staff/calendar/closures', { token: ctx.admin, body, extraHeaders: { 'Idempotency-Key': key } });
    expect(created.status, created.text.slice(0, 300)).toBe(201);
    expect(created.json.data).toMatchObject({ start_date: date, end_date: date, description: body.description, yearly: false, branch_id: null, editable: true, active: true });
    ctx.closureId = created.json.data.id;
    const replay = await F.api(request, 'POST', '/staff/calendar/closures', { token: ctx.admin, body, extraHeaders: { 'Idempotency-Key': key } });
    expect(replay.status).toBe(200);
    expect(replay.json.meta.replayed).toBe(true);
    expect(F.dbInt(`SELECT COUNT(*) FROM calendario_chiusure WHERE descrizione=${F.sqlStr(body.description)}`)).toBe(1);

    const grid = (await api(request, 'GET', `/staff/calendar?month=${date.slice(0, 7)}`, 'admin')).json.data;
    const cell = grid.days.find((d) => d.date === date);
    expect(cell.closed).toBe(true);
    expect(cell.reasons).toContainEqual({ type: 'closure', id: ctx.closureId, name: body.description });
    expect(grid.closures.map((c) => c.id)).toContain(ctx.closureId);

    if (ctx.bookId > 0) {
        const avail = await api(request, 'GET', `/catalog/books/${ctx.bookId}/availability`, 'reader');
        expect(avail.status).toBe(200);
        expect(avail.json.data.closed_dates).toContain(date);
    }

    const end = weekday(14);
    const upd = await api(request, 'PUT', `/staff/calendar/closures/${ctx.closureId}`, 'admin', { end_date: end });
    expect(upd.status, upd.text.slice(0, 300)).toBe(200);
    expect(upd.json.data).toMatchObject({ start_date: date, end_date: end, description: body.description });
    const backwards = await api(request, 'PUT', `/staff/calendar/closures/${ctx.closureId}`, 'admin', { end_date: day(1) });
    expect(backwards.status).toBe(422);
    expect(Object.keys(backwards.json.meta.fields)).toEqual(['end_date']);
    const badDate = await api(request, 'POST', '/staff/calendar/closures', 'admin', { start_date: '01/02/2026' });
    expect(badDate.status).toBe(422);
    expect(Object.keys(badDate.json.meta.fields)).toEqual(['start_date']);
    const badBranch = await api(request, 'POST', '/staff/calendar/closures', 'admin', { start_date: date, branch_id: 999999 });
    expect(badBranch.status).toBe(422);
    expect(Object.keys(badBranch.json.meta.fields)).toEqual(['branch_id']);
    expect((await api(request, 'PUT', '/staff/calendar/closures/999999', 'admin', { description: 'x' })).status).toBe(404);

    const del = await api(request, 'DELETE', `/staff/calendar/closures/${ctx.closureId}`, 'admin');
    expect(del.status).toBe(200);
    expect(F.dbInt(`SELECT COUNT(*) FROM calendario_chiusure WHERE id=${ctx.closureId}`)).toBe(0);
    expect((await api(request, 'DELETE', `/staff/calendar/closures/${ctx.closureId}`, 'admin')).status).toBe(404);
    if (ctx.bookId > 0) {
        const avail = await api(request, 'GET', `/catalog/books/${ctx.bookId}/availability`, 'reader');
        expect(avail.json.data.closed_dates).not.toContain(date);
    }
});

test('calendar yearly closures: switch off and on without deleting', async ({ request }) => {
    const created = await api(request, 'POST', '/staff/calendar/closures', 'admin', { start_date: '2026-08-14', end_date: '2026-08-16', yearly: true, description: `Ferragosto ${RUN}` });
    expect(created.status, created.text.slice(0, 300)).toBe(201);
    const id = created.json.data.id;
    const off = await api(request, 'PUT', '/staff/calendar/holidays/yearly', 'admin', { closures: [{ id, active: false }] });
    expect(off.status, off.text.slice(0, 300)).toBe(200);
    expect(off.json.data.changed).toBe(1);
    expect(off.json.data.yearly_closures.find((c) => c.id === id).active).toBe(false);
    expect(F.dbScalar(`SELECT attiva FROM calendario_chiusure WHERE id=${id}`)).toBe('0');
    const grid = (await api(request, 'GET', '/staff/calendar?month=2027-08', 'admin')).json.data.days;
    expect(grid.find((d) => d.date === '2027-08-15').reasons.map((r) => r.id)).not.toContain(id);
    const on = await api(request, 'PUT', '/staff/calendar/holidays/yearly', 'admin', { closures: [{ id, active: true }] });
    expect(on.json.data.yearly_closures.find((c) => c.id === id).active).toBe(true);
    const bad = await api(request, 'PUT', '/staff/calendar/holidays/yearly', 'admin', { closures: [{ id: 999999, active: true }] });
    expect(bad.status).toBe(422);
    expect(Object.keys(bad.json.meta.fields)).toEqual(['closures.0.id']);
    expect((await api(request, 'DELETE', `/staff/calendar/closures/${id}`, 'admin')).status).toBe(200);
});

test('calendar holiday presets: countries, preview and apply', async ({ request }) => {
    const countries = await api(request, 'GET', '/staff/calendar/holidays/countries?country=IT&year=2027', 'admin');
    expect(countries.status).toBe(200);
    expect(countries.json.data.countries.map((c) => c.code)).toContain('IT');
    expect(countries.json.data.country).toBe('IT');
    expect(countries.json.data.year).toBe(2027);
    const statutory = countries.json.data.holidays.filter((h) => !h.optional);
    expect(statutory.length).toBeGreaterThan(3);
    const christmas = statutory.find((h) => h.date === '2027-12-25') || statutory[0];

    const applied = await api(request, 'POST', '/staff/calendar/holidays/preset', 'admin', { country: 'IT', region: '', rules: [christmas.id] });
    expect(applied.status, applied.text.slice(0, 300)).toBe(200);
    expect(applied.json.data.message).not.toBe('');
    expect(applied.json.data.holiday_preset).toMatchObject({ country: 'IT', saved: true });
    expect(applied.json.data.holiday_preset.holidays.filter((h) => h.checked).map((h) => h.id)).toEqual([christmas.id]);
    expect(F.dbScalar("SELECT setting_value FROM system_settings WHERE category='calendar' AND setting_key='holiday_country'")).toBe('IT');
    if (christmas.date) {
        const grid = (await api(request, 'GET', `/staff/calendar?month=${christmas.date.slice(0, 7)}`, 'admin')).json.data.days;
        const cell = grid.find((d) => d.date === christmas.date);
        expect(cell.closed).toBe(true);
        expect(cell.reasons.map((r) => r.type)).toContain('holiday');
    }
    const bad = await api(request, 'POST', '/staff/calendar/holidays/preset', 'admin', { country: 'ZZ', rules: [] });
    expect(bad.status).toBe(422);
    expect(Object.keys(bad.json.meta.fields)).toEqual(['country']);
    expect((await api(request, 'POST', '/staff/calendar/holidays/preset', 'admin', { country: 'IT', rules: 'all' })).status).toBe(422);
});

test('calendar custom holidays: create (idempotent), update, toggle, delete', async ({ request }) => {
    const body = { name: `Santo patrono ${RUN}`, kind: 'fixed', month: 6, day: 15 };
    const key = `s090-custom-${RUN}`;
    const created = await F.api(request, 'POST', '/staff/calendar/custom-holidays', { token: ctx.admin, body, extraHeaders: { 'Idempotency-Key': key } });
    expect(created.status, created.text.slice(0, 300)).toBe(201);
    expect(created.json.data).toMatchObject({ name: body.name, kind: 'fixed', month: 6, day: 15, active: true, duration_days: 1, branch_id: null });
    expect(created.json.data.next_dates.length).toBe(3);
    ctx.customId = created.json.data.id;
    const replay = await F.api(request, 'POST', '/staff/calendar/custom-holidays', { token: ctx.admin, body, extraHeaders: { 'Idempotency-Key': key } });
    expect(replay.status).toBe(200);
    expect(replay.json.data.id).toBe(ctx.customId);
    expect(F.dbInt(`SELECT COUNT(*) FROM festivita_personalizzate WHERE nome=${F.sqlStr(body.name)}`)).toBe(1);

    const grid = (await api(request, 'GET', '/staff/calendar?month=2027-06', 'admin')).json.data;
    expect(grid.days.find((d) => d.date === '2027-06-15').reasons).toContainEqual({ type: 'custom_holiday', id: ctx.customId, name: body.name });
    expect(grid.custom_holidays.map((h) => h.id)).toContain(ctx.customId);

    const upd = await api(request, 'PUT', `/staff/calendar/custom-holidays/${ctx.customId}`, 'admin', { duration_days: 2 });
    expect(upd.status, upd.text.slice(0, 300)).toBe(200);
    expect(upd.json.data).toMatchObject({ name: body.name, month: 6, day: 15, duration_days: 2 });
    const nth = await api(request, 'PUT', `/staff/calendar/custom-holidays/${ctx.customId}`, 'admin', { kind: 'nth_weekday', month: 11, weekday: 4, occurrence: 4 });
    expect(nth.status, nth.text.slice(0, 300)).toBe(200);
    expect(nth.json.data).toMatchObject({ kind: 'nth_weekday', month: 11, weekday: 4, occurrence: 4 });

    const toggled = await api(request, 'POST', `/staff/calendar/custom-holidays/${ctx.customId}/toggle`, 'admin');
    expect(toggled.status).toBe(200);
    expect(toggled.json.data.active).toBe(false);
    expect(F.dbScalar(`SELECT attiva FROM festivita_personalizzate WHERE id=${ctx.customId}`)).toBe('0');

    const badDay = await api(request, 'POST', '/staff/calendar/custom-holidays', 'admin', { name: `X ${RUN}`, kind: 'fixed', month: 2, day: 31 });
    expect(badDay.status).toBe(422);
    expect(Object.keys(badDay.json.meta.fields)).toEqual(['day']);
    const badKind = await api(request, 'POST', '/staff/calendar/custom-holidays', 'admin', { name: `X ${RUN}`, kind: 'lunar' });
    expect(badKind.status).toBe(422);
    expect(Object.keys(badKind.json.meta.fields)).toEqual(['kind']);
    const noName = await api(request, 'POST', '/staff/calendar/custom-holidays', 'admin', { name: '', kind: 'easter', offset_days: 1 });
    expect(noName.status).toBe(422);
    expect(Object.keys(noName.json.meta.fields)).toEqual(['name']);
    const dates = await api(request, 'POST', '/staff/calendar/custom-holidays', 'admin', { name: `Date ${RUN}`, kind: 'dates', dates: ['2027-03-01', '2027-04-02'] });
    expect(dates.status, dates.text.slice(0, 300)).toBe(201);
    expect(dates.json.data.dates).toEqual(['2027-03-01', '2027-04-02']);
    expect((await api(request, 'DELETE', `/staff/calendar/custom-holidays/${dates.json.data.id}`, 'admin')).status).toBe(200);

    const del = await api(request, 'DELETE', `/staff/calendar/custom-holidays/${ctx.customId}`, 'admin');
    expect(del.status).toBe(200);
    expect((await api(request, 'POST', `/staff/calendar/custom-holidays/${ctx.customId}/toggle`, 'admin')).status).toBe(404);
    expect((await api(request, 'PUT', `/staff/calendar/custom-holidays/${ctx.customId}`, 'admin', { name: 'x' })).status).toBe(404);
});

test('calendar ICS import: adds once, skips duplicates, refuses a non-calendar file', async ({ request }) => {
    const date = weekday(25).replace(/-/g, '');
    const ics = `BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Pinakes test//EN\r\nBEGIN:VEVENT\r\nUID:s090-${RUN}@pinakes.test\r\nDTSTART;VALUE=DATE:${date}\r\nSUMMARY:Disinfestazione ${RUN}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n`;
    const file = { name: 'chiusure.ics', mimeType: 'text/calendar', buffer: Buffer.from(ics) };
    const first = await F.api(request, 'POST', '/staff/calendar/import-ics', { token: ctx.admin, multipart: { ics_file: file } });
    expect(first.status, first.text.slice(0, 300)).toBe(200);
    expect(first.json.data.added).toBe(1);
    expect(first.json.data.message).not.toBe('');
    const id = first.json.data.added_ids[0];
    expect(F.dbScalar(`SELECT descrizione FROM calendario_chiusure WHERE id=${id}`)).toBe(`Disinfestazione ${RUN}`);
    const again = await F.api(request, 'POST', '/staff/calendar/import-ics', { token: ctx.admin, multipart: { ics_file: file } });
    expect(again.status).toBe(200);
    expect(again.json.data.added).toBe(0);
    const junk = await F.api(request, 'POST', '/staff/calendar/import-ics', { token: ctx.admin, multipart: { ics_file: { name: 'x.ics', mimeType: 'text/calendar', buffer: Buffer.from('hello') } } });
    expect(junk.status).toBe(422);
    expect(Object.keys(junk.json.meta.fields)).toEqual(['ics_file']);
    const none = await F.api(request, 'POST', '/staff/calendar/import-ics', { token: ctx.admin, multipart: { branch_id: '' } });
    expect(none.status).toBe(422);
    expect((await F.api(request, 'POST', '/staff/calendar/import-ics', { token: ctx.staff, multipart: { ics_file: file } })).status).toBe(403);
    expect((await api(request, 'DELETE', `/staff/calendar/closures/${id}`, 'admin')).status).toBe(200);
});

test('openapi documents every route of the three areas', async ({ request }) => {
    const doc = (await F.api(request, 'GET', '/openapi.json')).json;
    for (const path of [
        '/staff/settings/modules', '/staff/settings/loans', '/staff/settings/cards',
        '/staff/circulation/types', '/staff/circulation/types/{id}', '/staff/circulation/types/{id}/default', '/staff/circulation/types/{id}/move', '/staff/circulation/rules',
        '/staff/calendar', '/staff/calendar/weekdays', '/staff/calendar/closures', '/staff/calendar/closures/{id}', '/staff/calendar/holidays/countries',
        '/staff/calendar/holidays/preset', '/staff/calendar/holidays/yearly', '/staff/calendar/custom-holidays', '/staff/calendar/custom-holidays/{id}',
        '/staff/calendar/custom-holidays/{id}/toggle', '/staff/calendar/import-ics',
    ]) {
        expect(doc.paths[path], path).toBeTruthy();
    }
    expect(doc.components.schemas.StaffPatronType.properties.daily_fine_cents).toBeTruthy();
});

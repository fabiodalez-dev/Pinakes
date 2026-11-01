// @ts-check
// Mobile API 1.10.0 — Pinakes 0.9.0 patron tools and the staff side of the
// fines ledger:
//   GET  /staff/patron-types                         (circulation_rules)
//   PUT  /staff/users/{id}/patron-type               (circulation_rules)
//   POST /staff/users/{id}/renew-card
//   GET  /staff/users/{id}/card.pdf, /staff/users/cards.pdf   (patron_cards)
//   GET|POST|DELETE /staff/users/{id}/photo          (patron_photos)
//   GET  /staff/users/{id}/ledger, POST …/ledger/payments|charges|waivers,
//   GET  …/ledger/movements/{movement}/receipt.pdf   (fines)
//   the 1.10.0 StaffUser fields, GET /staff/users?patron_type_id=,
//   late_fine on the staff loan and waive_fine/waive_reason on the return.
//
// Proves against the real Apache instance: the role guards (reader 403, no
// token 401, Staff 403 where the web editor refuses it), the real effect of
// every write (DB rows, follow-up reads), validation, idempotent replays, the
// downloads, and 404 feature_disabled for each module switched off.
//
// Run: /tmp/run-e2e.sh tests/mobile-api-staff-090-patrons.spec.js --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const F = require('./helpers/mobile-api-fixtures');
const featureToggles = require('./helpers/feature-toggles');
const { flushCache } = require('./helpers/flush-cache');

test.describe.configure({ mode: 'serial' });
test.skip(!F.ENV_READY, 'Missing E2E env: DB_* and ADMIN_EMAIL/ADMIN_PASS');

const RUN = `${Date.now()}_${process.pid}`;
const PASS = 'Patr1234Test!';
const MODULES = ['fines', 'automatic_fines', 'debt_block', 'loss_penalties', 'patron_cards', 'patron_photos', 'circulation_rules', 'automatic_card_expiry'];

/** @type {Record<string, any>} */
const ctx = { users: [], books: [], types: [] };

function isoDay(offset) {
    const d = new Date(`${ctx.today}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + offset);
    return d.toISOString().slice(0, 10);
}

/** Raw download (the JSON helper decodes bodies as text). */
async function download(request, route, token) {
    const res = await request.fetch(`${F.API}${route}`, { method: 'GET', headers: token ? { Authorization: `Bearer ${token}` } : {} });
    const body = await res.body();
    return { status: res.status(), headers: res.headers(), body };
}

function pngImage(size) {
    const b64 = execFileSync('php', ['-r', `$i=imagecreatetruecolor(${size},${size});imagefill($i,0,0,imagecolorallocate($i,200,40,40));ob_start();imagepng($i);echo base64_encode(ob_get_clean());`], { encoding: 'utf-8' }).trim();
    return Buffer.from(b64, 'base64');
}

function patronType(code, extra) {
    const cols = { codice: code, nome: `E2E ${code}`, attiva: 1, predefinita: 0, ordinamento: 900, ...extra };
    const keys = Object.keys(cols);
    const vals = keys.map((k) => (typeof cols[k] === 'number' ? String(cols[k]) : F.sqlStr(cols[k])));
    const id = F.dbInsert(`INSERT INTO tipologie_lettore (${keys.join(', ')}) VALUES (${vals.join(', ')})`);
    ctx.types.push(id);
    return id;
}

const idemKey = (label) => `patrons-${label}-${RUN}`.slice(0, 128);

test.beforeAll(async ({ browser, request }) => {
    const page = await browser.newPage();
    ctx.gate = await F.ensureMobileApiReady(page);
    await page.close();
    ctx.features = featureToggles.readFeatures(MODULES);
    await featureToggles.enableFeatures(['fines', 'patron_cards', 'circulation_rules', 'automatic_card_expiry']);

    ctx.adminId = F.createUser(`pt_admin_${RUN}@pinakes.test`, PASS, 'admin', 'PTA');
    ctx.staffId = F.createUser(`pt_staff_${RUN}@pinakes.test`, PASS, 'staff', 'PTS');
    ctx.readerId = F.createUser(`pt_reader_${RUN}@pinakes.test`, PASS, 'standard', 'PTR');
    ctx.patronId = F.createUser(`pt_patron_${RUN}@pinakes.test`, PASS, 'standard', 'PTP');
    ctx.patron2Id = F.createUser(`pt_patron2_${RUN}@pinakes.test`, PASS, 'premium', 'PTQ');
    ctx.users.push(ctx.adminId, ctx.staffId, ctx.readerId, ctx.patronId, ctx.patron2Id);
    ctx.admin = await F.login(request, `pt_admin_${RUN}@pinakes.test`, PASS, 'pt-admin');
    ctx.staff = await F.login(request, `pt_staff_${RUN}@pinakes.test`, PASS, 'pt-staff');
    ctx.reader = await F.login(request, `pt_reader_${RUN}@pinakes.test`, PASS, 'pt-reader');

    // One active type with a card duration, fine and debt threshold, one inactive type.
    ctx.typeCode = `e2e_${String(process.pid).slice(-4)}${String(Date.now()).slice(-6)}`;
    ctx.typeId = patronType(ctx.typeCode, { durata_tessera_giorni: 30, multa_giornaliera: '0.50', giorni_tolleranza: 0, soglia_blocco_debito: '10.00' });
    ctx.inactiveTypeId = patronType(`${ctx.typeCode}x`, { attiva: 0 });
    ctx.defaultTypeId = F.dbInt('SELECT id FROM tipologie_lettore WHERE predefinita = 1 ORDER BY id LIMIT 1');
    await flushCache();

    const summary = await F.api(request, 'GET', '/staff/circulation/summary', { token: ctx.staff });
    expect(summary.status).toBe(200);
    ctx.today = summary.json.data.today;
});

test.afterAll(async () => {
    try { F.dbExec(`DELETE FROM movimenti_lettore WHERE utente_id IN (${ctx.users.join(',')})`); } catch {}
    for (const id of ctx.users) {
        const stored = F.dbScalar(`SELECT COALESCE(foto_profilo_url, '') FROM utenti WHERE id=${id}`);
        if (/^patron_photos\/\d+-[a-f0-9]{16}\.jpg$/.test(stored)) {
            try { fs.unlinkSync(path.join(__dirname, '..', 'storage', stored)); } catch {}
        }
    }
    F.deleteBooks(ctx.books);
    try { F.dbExec(`DELETE FROM mobile_idempotency_keys WHERE user_id IN (${ctx.users.join(',')})`); } catch {}
    try { F.dbExec(`DELETE FROM admin_notifications WHERE message LIKE '%${RUN}%'`); } catch {}
    F.deleteUsers(ctx.users);
    for (const id of ctx.types) {
        try { F.dbExec(`DELETE FROM tipologie_lettore WHERE id=${id}`); } catch {}
    }
    await featureToggles.restoreFeatures(ctx.features);
    F.restoreGate(ctx.gate);
});

const ROUTES = () => [
    ['GET', '/staff/patron-types'],
    ['PUT', `/staff/users/${ctx.patronId}/patron-type`],
    ['POST', `/staff/users/${ctx.patronId}/renew-card`],
    ['GET', `/staff/users/${ctx.patronId}/card.pdf`],
    ['GET', `/staff/users/cards.pdf?ids=${ctx.patronId}`],
    ['GET', `/staff/users/${ctx.patronId}/photo`],
    ['POST', `/staff/users/${ctx.patronId}/photo`],
    ['DELETE', `/staff/users/${ctx.patronId}/photo`],
    ['GET', `/staff/users/${ctx.patronId}/ledger`],
    ['POST', `/staff/users/${ctx.patronId}/ledger/payments`],
    ['POST', `/staff/users/${ctx.patronId}/ledger/charges`],
    ['POST', `/staff/users/${ctx.patronId}/ledger/waivers`],
    ['GET', `/staff/users/${ctx.patronId}/ledger/movements/1/receipt.pdf`],
];

test.describe('Guards and discovery', () => {
    test('a reader gets 403 on every route; no token → 401; nothing is written', async ({ request }) => {
        const before = F.dbInt(`SELECT COUNT(*) FROM movimenti_lettore WHERE utente_id=${ctx.patronId}`);
        for (const [method, route] of ROUTES()) {
            const body = method === 'GET' || method === 'DELETE' ? undefined : { patron_type_id: null, amount_cents: 100, method: 'contanti', description: 'x', reason: 'x', idempotency_key: 'k' };
            const r = await F.api(request, method, route, { token: ctx.reader, body });
            expect(r.status, `${method} ${route}`).toBe(403);
            expect(r.json?.error?.code).toBe('forbidden');
            const anon = await F.api(request, method, route, { body });
            expect(anon.status, `anon ${method} ${route}`).toBe(401);
        }
        expect(F.dbInt(`SELECT COUNT(*) FROM movimenti_lettore WHERE utente_id=${ctx.patronId}`)).toBe(before);
    });

    test('health advertises staff_patrons and staff_ledger; OpenAPI documents every route', async ({ request }) => {
        const f = (await F.api(request, 'GET', '/health')).json.data.features;
        expect(f.staff_patrons).toBe(true);
        expect(f.staff_ledger).toBe(true);
        const doc = (await F.api(request, 'GET', '/openapi.json')).json;
        for (const p of ['/staff/patron-types', '/staff/users/{id}/patron-type', '/staff/users/{id}/renew-card', '/staff/users/{id}/card.pdf',
            '/staff/users/cards.pdf', '/staff/users/{id}/photo', '/staff/users/{id}/ledger', '/staff/users/{id}/ledger/payments',
            '/staff/users/{id}/ledger/charges', '/staff/users/{id}/ledger/waivers', '/staff/users/{id}/ledger/movements/{movement}/receipt.pdf']) {
            expect(doc.paths[p], p).toBeTruthy();
        }
        expect(Object.keys(doc.paths['/staff/users/{id}/photo']).sort()).toEqual(['delete', 'get', 'post']);
        expect(doc.components.schemas.StaffUser.properties.can_edit).toBeTruthy();
        expect(doc.components.schemas.StaffLoanLateFine).toBeTruthy();
    });
});

test.describe('Patron types', () => {
    test('GET /staff/patron-types lists every type with its flags', async ({ request }) => {
        const r = await F.api(request, 'GET', '/staff/patron-types', { token: ctx.staff });
        expect(r.status, r.text.slice(0, 300)).toBe(200);
        const mine = r.json.data.find((t) => t.id === ctx.typeId);
        expect(mine).toMatchObject({ code: ctx.typeCode, name: `E2E ${ctx.typeCode}`, is_default: false, active: true, card_duration_days: 30 });
        expect(r.json.data.find((t) => t.id === ctx.inactiveTypeId).active).toBe(false);
        expect(r.json.data.find((t) => t.id === ctx.defaultTypeId).is_default).toBe(true);
        expect(r.json.meta.default_id).toBe(ctx.defaultTypeId);
        expect(r.json.meta.can_assign).toBe(true);
    });

    test('Staff cannot change another account (web editor rule); the admin can, with the card expiry from the type', async ({ request }) => {
        const route = `/staff/users/${ctx.patronId}/patron-type`;
        const denied = await F.api(request, 'PUT', route, { token: ctx.staff, body: { patron_type_id: ctx.typeId } });
        expect(denied.status).toBe(403);
        expect(denied.json.error.code).toBe('not_permitted');
        expect(F.dbScalar(`SELECT COALESCE(tipologia_id, 'NULL') FROM utenti WHERE id=${ctx.patronId}`)).toBe('NULL');

        const r = await F.api(request, 'PUT', route, { token: ctx.admin, body: { patron_type_id: ctx.typeId, recompute_card_expiry: true } });
        expect(r.status, r.text.slice(0, 300)).toBe(200);
        expect(r.json.data.patron_type).toEqual({ id: ctx.typeId, code: ctx.typeCode, name: `E2E ${ctx.typeCode}`, assigned: true });
        expect(r.json.meta.card_expiry_recomputed).toBe(true);
        expect(r.json.data.card_expires_at).toBe(isoDay(30));
        expect(F.dbInt(`SELECT tipologia_id FROM utenti WHERE id=${ctx.patronId}`)).toBe(ctx.typeId);
        expect(F.dbScalar(`SELECT data_scadenza_tessera FROM utenti WHERE id=${ctx.patronId}`)).toBe(isoDay(30));
        expect(r.json.data.can_edit).toBe(true);
    });

    test('validation: inactive, unknown, malformed or missing type → 422; unknown user → 404', async ({ request }) => {
        const route = `/staff/users/${ctx.patron2Id}/patron-type`;
        for (const body of [{ patron_type_id: ctx.inactiveTypeId }, { patron_type_id: 999999999 }, { patron_type_id: 'abc' }, {}]) {
            const r = await F.api(request, 'PUT', route, { token: ctx.admin, body });
            expect(r.status, JSON.stringify(body)).toBe(422);
            expect(r.json.error.code).toBe('validation');
            expect(r.json.meta.fields.patron_type_id).toBeTruthy();
        }
        const bad = await F.api(request, 'PUT', route, { token: ctx.admin, body: { patron_type_id: null, recompute_card_expiry: 'maybe' } });
        expect(bad.status).toBe(422);
        expect(bad.json.meta.field).toBe('recompute_card_expiry');
        const missing = await F.api(request, 'PUT', '/staff/users/999999999/patron-type', { token: ctx.admin, body: { patron_type_id: null } });
        expect(missing.status).toBe(404);
        expect(F.dbScalar(`SELECT COALESCE(tipologia_id, 'NULL') FROM utenti WHERE id=${ctx.patron2Id}`)).toBe('NULL');
    });

    test('a Staff account may change its own record; null goes back to the default type', async ({ request }) => {
        const own = await F.api(request, 'PUT', `/staff/users/${ctx.staffId}/patron-type`, { token: ctx.staff, body: { patron_type_id: ctx.typeId } });
        expect(own.status, own.text.slice(0, 200)).toBe(200);
        expect(F.dbInt(`SELECT tipologia_id FROM utenti WHERE id=${ctx.staffId}`)).toBe(ctx.typeId);
        const back = await F.api(request, 'PUT', `/staff/users/${ctx.staffId}/patron-type`, { token: ctx.staff, body: { patron_type_id: null } });
        expect(back.status).toBe(200);
        expect(back.json.data.patron_type.id).toBe(ctx.defaultTypeId);
        expect(back.json.data.patron_type.assigned).toBe(false);
        expect(F.dbScalar(`SELECT COALESCE(tipologia_id, 'NULL') FROM utenti WHERE id=${ctx.staffId}`)).toBe('NULL');
    });

    test('StaffUser carries the 0.9.0 fields; the users search filters by patron type', async ({ request }) => {
        const asStaff = await F.api(request, 'GET', `/staff/users/${ctx.patronId}`, { token: ctx.staff });
        expect(asStaff.status).toBe(200);
        const u = asStaff.json.data;
        expect(u.patron_type.id).toBe(ctx.typeId);
        expect(u.card_number).toBeTruthy();
        expect(u.card_expires_at).toBe(isoDay(30));
        expect(u.has_photo).toBe(false);
        expect(u.photo_url).toBeNull();
        expect(u.ledger).toEqual({ balance_cents: 0, currency: expect.any(String), debt_blocked: false });
        expect(u.can_edit).toBe(false);
        expect(Array.isArray(u.loans)).toBe(true);
        const asAdmin = await F.api(request, 'GET', `/staff/users/${ctx.patronId}`, { token: ctx.admin });
        expect(asAdmin.json.data.can_edit).toBe(true);

        const filtered = await F.api(request, 'GET', `/staff/users?patron_type_id=${ctx.typeId}`, { token: ctx.staff });
        expect(filtered.status, filtered.text.slice(0, 200)).toBe(200);
        expect(filtered.json.data.map((x) => x.id)).toContain(ctx.patronId);
        expect(filtered.json.data.map((x) => x.id)).not.toContain(ctx.patron2Id);
        const both = await F.api(request, 'GET', `/staff/users?q=pt_patron&patron_type_id=${ctx.defaultTypeId}`, { token: ctx.staff });
        expect(both.json.data.map((x) => x.id)).toContain(ctx.patron2Id);
        expect(both.json.data.map((x) => x.id)).not.toContain(ctx.patronId);
        const invalid = await F.api(request, 'GET', '/staff/users?patron_type_id=999999999', { token: ctx.staff });
        expect(invalid.status).toBe(422);
        expect(invalid.json.meta.field).toBe('patron_type_id');
        const noQuery = await F.api(request, 'GET', '/staff/users', { token: ctx.staff });
        expect(noQuery.status).toBe(422);
    });
});

test.describe('Card renewal and card PDFs', () => {
    test('renew-card: new expiry from the type, idempotent replay, admin account refused', async ({ request }) => {
        F.dbExec(`UPDATE utenti SET data_scadenza_tessera='${isoDay(-3)}', stato='scaduto' WHERE id=${ctx.patronId}`);
        const key = idemKey('renew');
        const r = await F.api(request, 'POST', `/staff/users/${ctx.patronId}/renew-card`, { token: ctx.staff, extraHeaders: { 'Idempotency-Key': key } });
        expect(r.status, r.text.slice(0, 300)).toBe(201);
        expect(r.json.data.card_expires_at).toBe(isoDay(30));
        expect(r.json.data.status).toBe('attivo');
        expect(F.dbScalar(`SELECT CONCAT(data_scadenza_tessera, '|', stato) FROM utenti WHERE id=${ctx.patronId}`)).toBe(`${isoDay(30)}|attivo`);
        expect(F.dbInt(`SELECT COUNT(*) FROM log_modifiche WHERE tabella='utenti' AND record_id=${ctx.patronId} AND dati_nuovi LIKE '%card_renewed%'`)).toBeGreaterThan(0);

        F.dbExec(`UPDATE utenti SET data_scadenza_tessera='${isoDay(5)}' WHERE id=${ctx.patronId}`);
        const replay = await F.api(request, 'POST', `/staff/users/${ctx.patronId}/renew-card`, { token: ctx.staff, extraHeaders: { 'Idempotency-Key': key } });
        expect(replay.status).toBe(200);
        expect(replay.json.meta.replayed).toBe(true);
        expect(F.dbScalar(`SELECT data_scadenza_tessera FROM utenti WHERE id=${ctx.patronId}`)).toBe(isoDay(5));

        const admin = await F.api(request, 'POST', `/staff/users/${ctx.adminId}/renew-card`, { token: ctx.staff });
        expect(admin.status).toBe(409);
        expect(admin.json.error.code).toBe('card_not_renewable');
        const bad = await F.api(request, 'POST', `/staff/users/${ctx.patronId}/renew-card`, { token: ctx.staff, extraHeaders: { 'Idempotency-Key': 'bad key!' } });
        expect(bad.status).toBe(422);
        const missing = await F.api(request, 'POST', '/staff/users/999999999/renew-card', { token: ctx.staff });
        expect(missing.status).toBe(404);
    });

    test('card.pdf and cards.pdf download as attachments; validation and 404', async ({ request }) => {
        const card = await download(request, `/staff/users/${ctx.patronId}/card.pdf`, ctx.staff);
        expect(card.status).toBe(200);
        expect(card.headers['content-type']).toContain('application/pdf');
        expect(card.headers['content-disposition']).toMatch(/^attachment; filename="tessera_[A-Za-z0-9_-]+\.pdf"$/);
        expect(card.body.subarray(0, 5).toString()).toBe('%PDF-');

        const sheet = await download(request, `/staff/users/cards.pdf?ids=${ctx.patronId},${ctx.patron2Id}&format=sheet`, ctx.staff);
        expect(sheet.status).toBe(200);
        expect(sheet.headers['content-disposition']).toBe('attachment; filename="tessere.pdf"');
        expect(sheet.body.subarray(0, 5).toString()).toBe('%PDF-');
        const single = await download(request, `/staff/users/cards.pdf?ids=${ctx.patronId}&format=single`, ctx.admin);
        expect(single.status).toBe(200);

        for (const route of ['/staff/users/cards.pdf', '/staff/users/cards.pdf?ids=a,b', `/staff/users/cards.pdf?ids=${ctx.patronId}&format=poster`,
            `/staff/users/cards.pdf?ids=${Array.from({ length: 501 }, (_, i) => i + 1).join(',')}`, `/staff/users/${ctx.patronId}/card.pdf?format=poster`]) {
            const r = await F.api(request, 'GET', route, { token: ctx.staff });
            expect(r.status, route).toBe(422);
            expect(r.json.error.code).toBe('validation');
        }
        expect((await F.api(request, 'GET', '/staff/users/999999999/card.pdf', { token: ctx.staff })).status).toBe(404);
        expect((await F.api(request, 'GET', '/staff/users/cards.pdf?ids=999999998,999999999', { token: ctx.staff })).status).toBe(404);
    });
});

test.describe('Patron photo', () => {
    test('no photo → 404; Staff may not set another account\'s photo; the admin sets, reads and removes it', async ({ request }) => {
        const route = `/staff/users/${ctx.patronId}/photo`;
        const none = await F.api(request, 'GET', route, { token: ctx.staff });
        expect(none.status).toBe(404);
        expect(none.json.error.code).toBe('not_found');

        const png = pngImage(64);
        const denied = await F.api(request, 'POST', route, { token: ctx.staff, multipart: { photo: { name: 'p.png', mimeType: 'image/png', buffer: png } } });
        expect(denied.status).toBe(403);
        expect(F.dbScalar(`SELECT COALESCE(foto_profilo_url, '') FROM utenti WHERE id=${ctx.patronId}`)).toBe('');

        const up = await F.api(request, 'POST', route, { token: ctx.admin, multipart: { photo: { name: 'p.png', mimeType: 'image/png', buffer: png } } });
        expect(up.status, up.text.slice(0, 300)).toBe(200);
        expect(up.json.data.has_photo).toBe(true);
        expect(up.json.data.photo_url).toMatch(new RegExp(`/api/v1/staff/users/${ctx.patronId}/photo\\?v=[a-f0-9]{10}$`));
        const stored = F.dbScalar(`SELECT foto_profilo_url FROM utenti WHERE id=${ctx.patronId}`);
        expect(stored).toMatch(new RegExp(`^patron_photos/${ctx.patronId}-[a-f0-9]{16}\\.jpg$`));
        expect(fs.existsSync(path.join(__dirname, '..', 'storage', stored))).toBe(true);

        const detail = await F.api(request, 'GET', `/staff/users/${ctx.patronId}`, { token: ctx.staff });
        expect(detail.json.data.has_photo).toBe(true);
        expect(detail.json.data.photo_url).toBe(up.json.data.photo_url);

        const img = await download(request, route, ctx.staff);
        expect(img.status).toBe(200);
        expect(img.headers['content-type']).toBe('image/jpeg');
        expect(img.headers['cache-control']).toContain('no-store');
        expect(img.body[0]).toBe(0xff);
        expect(img.body[1]).toBe(0xd8);

        const notImage = await F.api(request, 'POST', route, { token: ctx.admin, multipart: { photo: { name: 'x.png', mimeType: 'image/png', buffer: Buffer.from('not an image at all') } } });
        expect(notImage.status).toBe(422);
        expect(notImage.json.meta.field).toBe('photo');
        const noFile = await F.api(request, 'POST', route, { token: ctx.admin, multipart: { other: 'x' } });
        expect(noFile.status).toBe(422);
        expect(F.dbScalar(`SELECT foto_profilo_url FROM utenti WHERE id=${ctx.patronId}`)).toBe(stored);

        const staffDelete = await F.api(request, 'DELETE', route, { token: ctx.staff });
        expect(staffDelete.status).toBe(403);
        const del = await F.api(request, 'DELETE', route, { token: ctx.admin });
        expect(del.status, del.text.slice(0, 200)).toBe(200);
        expect(del.json.data).toEqual({ user_id: ctx.patronId, has_photo: false, photo_url: null });
        expect(F.dbScalar(`SELECT COALESCE(foto_profilo_url, '') FROM utenti WHERE id=${ctx.patronId}`)).toBe('');
        expect(fs.existsSync(path.join(__dirname, '..', 'storage', stored))).toBe(false);
        const again = await F.api(request, 'DELETE', route, { token: ctx.admin });
        expect(again.status).toBe(200);
        expect((await F.api(request, 'DELETE', '/staff/users/999999999/photo', { token: ctx.admin })).status).toBe(404);
    });

    test('a Staff account may set its own photo', async ({ request }) => {
        const r = await F.api(request, 'POST', `/staff/users/${ctx.staffId}/photo`, { token: ctx.staff, multipart: { photo: { name: 'me.png', mimeType: 'image/png', buffer: pngImage(32) } } });
        expect(r.status, r.text.slice(0, 200)).toBe(200);
        expect(r.json.data.has_photo).toBe(true);
    });
});

test.describe('Ledger', () => {
    const route = () => `/staff/users/${ctx.patronId}/ledger`;

    test('empty ledger: balance 0, methods, permissions', async ({ request }) => {
        const r = await F.api(request, 'GET', route(), { token: ctx.staff });
        expect(r.status, r.text.slice(0, 300)).toBe(200);
        expect(r.json.data).toMatchObject({ user_id: ctx.patronId, balance_cents: 0, balance: '0.00', accrued_cents: 0, debt_blocked: false,
            debt_block_threshold_cents: 1000, can_record_payment: true, can_charge: true, can_waive: true, items: [] });
        expect(r.json.data.payment_methods.map((m) => m.code)).toEqual(['contanti', 'pos', 'bonifico', 'altro']);
        expect(r.json.meta.total_count).toBe(0);
        expect((await F.api(request, 'GET', '/staff/users/999999999/ledger', { token: ctx.staff })).status).toBe(404);
    });

    test('charge: Idempotency-Key required, recorded once, replay answers the same movement, other body → idempotency_conflict', async ({ request }) => {
        const noKey = await F.api(request, 'POST', `${route()}/charges`, { token: ctx.staff, body: { amount_cents: 1500, description: `Tessera ${RUN}` } });
        expect(noKey.status).toBe(422);
        expect(noKey.json.error.code).toBe('validation');
        expect(noKey.json.meta.fields.idempotency_key).toBeTruthy();
        for (const body of [{ amount_cents: 0, description: 'x' }, { amount_cents: '12.50', description: 'x' }, { amount_cents: 100000000, description: 'x' }, { amount_cents: 100, description: '  ' }]) {
            const r = await F.api(request, 'POST', `${route()}/charges`, { token: ctx.staff, body, extraHeaders: { 'Idempotency-Key': idemKey('bad') } });
            expect(r.status, JSON.stringify(body)).toBe(422);
        }
        expect(F.dbInt(`SELECT COUNT(*) FROM movimenti_lettore WHERE utente_id=${ctx.patronId}`)).toBe(0);

        const key = idemKey('charge');
        const r = await F.api(request, 'POST', `${route()}/charges`, { token: ctx.staff, body: { amount_cents: 1500, description: `Tessera ${RUN}` }, extraHeaders: { 'Idempotency-Key': key } });
        expect(r.status, r.text.slice(0, 300)).toBe(201);
        expect(r.json.data.movement).toMatchObject({ type: 'addebito_manuale', amount_cents: 1500, amount: '15.00', description: `Tessera ${RUN}`,
            receipt_available: false, operator: { id: ctx.staffId, name: expect.any(String) } });
        expect(r.json.data.balance_cents).toBe(1500);
        expect(r.json.data.debt_blocked).toBe(true);
        ctx.chargeId = r.json.data.movement.id;
        expect(F.dbScalar(`SELECT CONCAT(tipo, '|', importo, '|', creato_da, '|', chiave_idempotenza) FROM movimenti_lettore WHERE id=${ctx.chargeId}`))
            .toMatch(new RegExp(`^addebito_manuale\\|15\\.00\\|${ctx.staffId}\\|form:[a-f0-9]{32}$`));

        const replay = await F.api(request, 'POST', `${route()}/charges`, { token: ctx.staff, body: { amount_cents: 1500, description: `Tessera ${RUN}` }, extraHeaders: { 'Idempotency-Key': key } });
        expect(replay.status).toBe(200);
        expect(replay.json.meta.replayed).toBe(true);
        expect(replay.json.data.movement.id).toBe(ctx.chargeId);
        const conflict = await F.api(request, 'POST', `${route()}/charges`, { token: ctx.staff, body: { amount_cents: 1600, description: `Tessera ${RUN}` }, extraHeaders: { 'Idempotency-Key': key } });
        expect(conflict.status).toBe(422);
        expect(conflict.json.error.code).toBe('idempotency_conflict');
        expect(F.dbInt(`SELECT COUNT(*) FROM movimenti_lettore WHERE utente_id=${ctx.patronId}`)).toBe(1);

        // The ledger's own key: after the 24 h memory is gone the same retry still writes nothing new.
        F.dbExec(`DELETE FROM mobile_idempotency_keys WHERE user_id=${ctx.staffId} AND scope='ledger_charge'`);
        const late = await F.api(request, 'POST', `${route()}/charges`, { token: ctx.staff, body: { amount_cents: 1500, description: `Tessera ${RUN}` }, extraHeaders: { 'Idempotency-Key': key } });
        expect(late.status).toBe(201);
        expect(late.json.data.movement.id).toBe(ctx.chargeId);
        expect(F.dbInt(`SELECT COUNT(*) FROM movimenti_lettore WHERE utente_id=${ctx.patronId}`)).toBe(1);

        const detail = await F.api(request, 'GET', `/staff/users/${ctx.patronId}`, { token: ctx.staff });
        expect(detail.json.data.ledger).toMatchObject({ balance_cents: 1500, debt_blocked: true });
        expect(detail.json.data.eligibility_reason).toBe('debt_block');
    });

    test('payment: method checked, never more than the balance, receipt PDF', async ({ request }) => {
        const pay = (body, key) => F.api(request, 'POST', `${route()}/payments`, { token: ctx.staff, body, extraHeaders: { 'Idempotency-Key': key } });
        const badMethod = await pay({ amount_cents: 500, method: 'bitcoin' }, idemKey('pay-bad'));
        expect(badMethod.status).toBe(422);
        expect(badMethod.json.meta.field).toBe('method');
        const tooMuch = await pay({ amount_cents: 2000, method: 'contanti' }, idemKey('pay-much'));
        expect(tooMuch.status).toBe(409);
        expect(tooMuch.json.error.code).toBe('amount_exceeds_balance');
        expect(tooMuch.json.meta.balance_cents).toBe(1500);

        const r = await pay({ amount_cents: 1000, method: 'pos', note: `Desk ${RUN}` }, idemKey('pay'));
        expect(r.status, r.text.slice(0, 300)).toBe(201);
        expect(r.json.data.movement).toMatchObject({ type: 'pagamento', amount_cents: -1000, payment_method: 'pos', receipt_available: true });
        expect(r.json.data.movement.payment_method_label).toBeTruthy();
        expect(r.json.data.balance_cents).toBe(500);
        expect(r.json.data.debt_blocked).toBe(false);
        ctx.paymentId = r.json.data.movement.id;
        expect(F.dbScalar(`SELECT CONCAT(tipo, '|', importo, '|', metodo_pagamento) FROM movimenti_lettore WHERE id=${ctx.paymentId}`)).toBe('pagamento|-10.00|pos');

        const receipt = await download(request, `${route()}/movements/${ctx.paymentId}/receipt.pdf`, ctx.staff);
        expect(receipt.status).toBe(200);
        expect(receipt.headers['content-type']).toContain('application/pdf');
        expect(receipt.headers['content-disposition']).toBe(`attachment; filename="ricevuta_pagamento_${String(ctx.paymentId).padStart(6, '0')}.pdf"`);
        expect(receipt.body.subarray(0, 5).toString()).toBe('%PDF-');
        expect((await F.api(request, 'GET', `${route()}/movements/${ctx.chargeId}/receipt.pdf`, { token: ctx.staff })).status).toBe(404);
        expect((await F.api(request, 'GET', `/staff/users/${ctx.patron2Id}/ledger/movements/${ctx.paymentId}/receipt.pdf`, { token: ctx.staff })).status).toBe(404);
    });

    test('waiver: reason required, then the balance goes to zero; paged movement list', async ({ request }) => {
        const noReason = await F.api(request, 'POST', `${route()}/waivers`, { token: ctx.admin, body: { amount_cents: 500 }, extraHeaders: { 'Idempotency-Key': idemKey('w-bad') } });
        expect(noReason.status).toBe(422);
        expect(noReason.json.meta.field).toBe('reason');
        const r = await F.api(request, 'POST', `${route()}/waivers`, { token: ctx.admin, body: { amount_cents: 500, reason: `Goodwill ${RUN}`, idempotency_key: idemKey('w') } });
        expect(r.status, r.text.slice(0, 300)).toBe(201);
        expect(r.json.data.movement).toMatchObject({ type: 'condono', amount_cents: -500, description: `Goodwill ${RUN}` });
        expect(r.json.data.balance_cents).toBe(0);

        const page1 = await F.api(request, 'GET', `${route()}?limit=2`, { token: ctx.staff });
        expect(page1.status).toBe(200);
        expect(page1.json.data.balance_cents).toBe(0);
        expect(page1.json.data.items.map((m) => m.type)).toEqual(['condono', 'pagamento']);
        expect(page1.json.meta).toMatchObject({ count: 2, has_more: true, total_count: 3 });
        // A movement written between the pages does not shift them.
        await F.api(request, 'POST', `${route()}/charges`, { token: ctx.staff, body: { amount_cents: 100, description: 'Between pages' }, extraHeaders: { 'Idempotency-Key': idemKey('between') } });
        const page2 = await F.api(request, 'GET', `${route()}?limit=2&cursor=${encodeURIComponent(page1.json.meta.next_cursor)}`, { token: ctx.staff });
        expect(page2.json.data.items.map((m) => m.id)).toEqual([ctx.chargeId]);
        expect(page2.json.meta.has_more).toBe(false);
        // Same item shape as the reader's own /me/movements.
        const keys = Object.keys(page2.json.data.items[0]);
        for (const k of ['id', 'type', 'type_label', 'amount_cents', 'amount', 'description', 'payment_method', 'loan_id', 'book_title', 'created_at', 'operator', 'receipt_available']) {
            expect(keys, k).toContain(k);
        }
    });
});

test.describe('Late fine on return', () => {
    async function overdueLoan(request, label) {
        const book = await F.api(request, 'POST', '/staff/books', { token: ctx.staff, body: { title: `Patrons fine ${label} ${RUN}`, copies: 1 } });
        expect(book.status).toBe(201);
        ctx.books.push(book.json.data.id);
        const loan = await F.api(request, 'POST', '/staff/loans', { token: ctx.staff, body: { user_id: ctx.patronId, book_id: book.json.data.id, immediate: true } });
        expect(loan.status, loan.text.slice(0, 200)).toBe(201);
        F.dbExec(`UPDATE prestiti SET data_prestito='${isoDay(-20)}', data_scadenza='${isoDay(-4)}', stato='in_ritardo' WHERE id=${loan.json.data.id}`);
        return loan.json.data.id;
    }

    test('the loan shows the accruing fine; a waiver without reason is refused; waived → charged 0, balance unchanged', async ({ request }) => {
        const loanId = await overdueLoan(request, 'waived');
        const detail = await F.api(request, 'GET', `/staff/loans/${loanId}`, { token: ctx.staff });
        expect(detail.status).toBe(200);
        const fine = detail.json.data.late_fine;
        expect(fine.automatic_fines).toBe(true);
        expect(fine.accruing_days).toBeGreaterThan(0);
        expect(fine.accruing_cents).toBe(fine.accruing_days * 50);
        expect(fine.charged_cents).toBe(0);
        const ledger = await F.api(request, 'GET', `/staff/users/${ctx.patronId}/ledger`, { token: ctx.staff });
        expect(ledger.json.data.accrued.find((a) => a.loan_id === loanId).amount_cents).toBe(fine.accruing_cents);
        const balanceBefore = ledger.json.data.balance_cents;

        const refused = await F.api(request, 'POST', `/staff/loans/${loanId}/return`, { token: ctx.staff, body: { waive_fine: true } });
        expect(refused.status).toBe(422);
        expect(refused.json.meta.field).toBe('waive_reason');
        expect(F.dbScalar(`SELECT stato FROM prestiti WHERE id=${loanId}`)).toBe('in_ritardo');

        const r = await F.api(request, 'POST', `/staff/loans/${loanId}/return`, { token: ctx.staff, body: { waive_fine: true, waive_reason: `First time ${RUN}` } });
        expect(r.status, r.text.slice(0, 300)).toBe(200);
        expect(r.json.data.status).toBe('restituito');
        expect(r.json.data.late_fine.charged_cents).toBe(0);
        expect(r.json.data.late_fine.accruing_cents).toBe(0);
        expect(F.dbScalar(`SELECT GROUP_CONCAT(CONCAT(tipo, ':', importo) ORDER BY id) FROM movimenti_lettore WHERE prestito_id=${loanId}`))
            .toBe(`multa_ritardo:${(fine.accruing_cents / 100).toFixed(2)},condono:-${(fine.accruing_cents / 100).toFixed(2)}`);
        expect(F.dbScalar(`SELECT descrizione FROM movimenti_lettore WHERE prestito_id=${loanId} AND tipo='condono'`)).toBe(`First time ${RUN}`);
        const after = await F.api(request, 'GET', `/staff/users/${ctx.patronId}/ledger`, { token: ctx.staff });
        expect(after.json.data.balance_cents).toBe(balanceBefore);
    });

    test('without a waiver the return charges the fine', async ({ request }) => {
        const loanId = await overdueLoan(request, 'charged');
        const preview = (await F.api(request, 'GET', `/staff/loans/${loanId}`, { token: ctx.staff })).json.data.late_fine.accruing_cents;
        const before = (await F.api(request, 'GET', `/staff/users/${ctx.patronId}/ledger`, { token: ctx.staff })).json.data.balance_cents;
        const r = await F.api(request, 'POST', `/staff/loans/${loanId}/return`, { token: ctx.staff, body: { outcome: 'restituito' } });
        expect(r.status, r.text.slice(0, 300)).toBe(200);
        expect(r.json.data.late_fine.charged_cents).toBe(preview);
        const after = (await F.api(request, 'GET', `/staff/users/${ctx.patronId}/ledger`, { token: ctx.staff })).json.data;
        expect(after.balance_cents).toBe(before + preview);
        expect(after.items[0]).toMatchObject({ type: 'multa_ritardo', loan_id: loanId, amount_cents: preview });
    });
});

test.describe('Modules switched off', () => {
    test('fines off: ledger routes 404 feature_disabled, no ledger/late_fine fields, staff_ledger false', async ({ request }) => {
        await featureToggles.writeFeatures({ fines: '0' });
        try {
            for (const [method, r] of ROUTES().filter(([, p]) => p.includes('/ledger'))) {
                const res = await F.api(request, method, r, { token: ctx.staff, body: method === 'POST' ? { amount_cents: 100 } : undefined });
                expect(res.status, `${method} ${r}`).toBe(404);
                expect(res.json.error.code).toBe('feature_disabled');
            }
            expect((await F.api(request, 'GET', '/health')).json.data.features.staff_ledger).toBe(false);
            expect((await F.api(request, 'GET', `/staff/users/${ctx.patronId}`, { token: ctx.staff })).json.data.ledger).toBeNull();
            const loanId = F.dbInt(`SELECT MAX(id) FROM prestiti WHERE utente_id=${ctx.patronId}`);
            expect((await F.api(request, 'GET', `/staff/loans/${loanId}`, { token: ctx.staff })).json.data.late_fine).toBeNull();
        } finally {
            await featureToggles.writeFeatures({ fines: '1' });
        }
    });

    test('patron_cards, patron_photos, circulation_rules off: their routes 404 feature_disabled; renew-card stays', async ({ request }) => {
        await featureToggles.writeFeatures({ patron_cards: '0', patron_photos: '0', circulation_rules: '0' });
        try {
            for (const [method, r] of ROUTES().filter(([, p]) => /card\.pdf|cards\.pdf|photo|patron-type/.test(p))) {
                const res = await F.api(request, method, r, { token: ctx.admin, body: method === 'PUT' ? { patron_type_id: null } : undefined });
                expect(res.status, `${method} ${r}`).toBe(404);
                expect(res.json.error.code).toBe('feature_disabled');
            }
            const u = (await F.api(request, 'GET', `/staff/users/${ctx.staffId}`, { token: ctx.staff })).json.data;
            expect(u.patron_type).toBeNull();
            expect(u.photo_url).toBeNull();
            expect(u.has_photo).toBe(false);
            expect((await F.api(request, 'GET', '/health')).json.data.features.staff_patrons).toBe(true);
            const renew = await F.api(request, 'POST', `/staff/users/${ctx.patron2Id}/renew-card`, { token: ctx.staff });
            expect(renew.status).toBe(201);
        } finally {
            await featureToggles.writeFeatures({ patron_cards: '1', patron_photos: '1', circulation_rules: '1' });
        }
    });
});

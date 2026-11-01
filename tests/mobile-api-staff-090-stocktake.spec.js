// @ts-check
//
// Mobile API 1.10.0 — inventory stocktake from the app (/api/v1/staff/stocktake).
//
// The phone is the scanner: open a session on a shelf, scan found /
// misplaced / unknown / on-loan / out-of-circulation codes, replay a scan with
// its Idempotency-Key, upload a list, read the counters, the scan list and the
// report, download the CSV and the PDF, close leaving the missing copies
// (staff) or marking them lost (administrator only, resumable), cancel.
// Module off → 404 feature_disabled, a reader → 403.
//
// Self-provisions an admin, a staff operator, a reader (also the borrower),
// two shelves, books and copies (tokenised), and removes them in afterAll.
// The DB is used only to provision fixtures and to verify effects.

const { test, expect } = require('@playwright/test');
const crypto = require('crypto');
const featureToggles = require('./helpers/feature-toggles');
const fx = require('./helpers/mobile-api-fixtures');

test.skip(!fx.ENV_READY, 'Missing E2E env: E2E_DB_* and E2E_ADMIN_*');
test.describe.configure({ mode: 'serial' });

const RUN = crypto.randomBytes(4).toString('hex').toUpperCase();
const TAG = `MST${RUN}`;
const PASS = 'Stocktake!2026';
const users = {
    admin: { email: `mst-admin-${RUN.toLowerCase()}@stocktake.test.local`, id: 0, token: '' },
    staff: { email: `mst-staff-${RUN.toLowerCase()}@stocktake.test.local`, id: 0, token: '' },
    reader: { email: `mst-reader-${RUN.toLowerCase()}@stocktake.test.local`, id: 0, token: '' },
    // A reader account with a custom role granting only the stocktake (inventory.manage).
    stocktaker: { email: `mst-role-${RUN.toLowerCase()}@stocktake.test.local`, id: 0, token: '' },
};
let roleId = 0;
const code = (c) => `${TAG}-${c}`;
const S = (v) => fx.sqlStr(v);
const data = { shelfX: 0, shelfY: 0, levelX: 0, books: {}, copies: {}, sessions: [] };
let featureSnapshot;
let gate;

const P = '/staff/stocktake';
const call = (request, method, path, who, opts = {}) => fx.api(request, method, path, { token: who ? users[who].token : undefined, ...opts });
const copyState = (c) => fx.dbScalar(`SELECT stato FROM copie WHERE id=${data.copies[c]}`);

test.beforeAll(async ({ browser, request }) => {
    featureSnapshot = await featureToggles.enableFeatures(['stocktake']);
    const page = await browser.newPage();
    try {
        gate = await fx.ensureMobileApiReady(page);
    } finally {
        await page.close();
    }
    users.admin.id = fx.createUser(users.admin.email, PASS, 'admin', 'MSTA');
    users.staff.id = fx.createUser(users.staff.email, PASS, 'staff', 'MSTS');
    users.reader.id = fx.createUser(users.reader.email, PASS, 'standard', 'MSTR');
    users.stocktaker.id = fx.createUser(users.stocktaker.email, PASS, 'standard', 'MSTK');
    roleId = fx.dbInsert(`INSERT INTO ruoli (nome, permessi) VALUES (${S(`${TAG} stocktake`)}, '["backend.access","inventory.manage"]')`);
    fx.dbExec(`UPDATE utenti SET ruolo_id=${roleId} WHERE id=${users.stocktaker.id}`);
    for (const [key, letter] of [['shelfX', 'X'], ['shelfY', 'Y']]) {
        data[key] = fx.dbInsert(`INSERT INTO scaffali (codice, nome, lettera) VALUES (${S(TAG + letter)}, ${S('Stocktake API ' + letter)}, ${S(letter)})`);
    }
    data.levelX = fx.dbInsert(`INSERT INTO mensole (scaffale_id, numero_livello) VALUES (${data.shelfX}, 1)`);
    const book = (key, shelf, copies, extra = '') => {
        data.books[key] = fx.dbInsert(`INSERT INTO libri (titolo, scaffale_id, collocazione, copie_totali, copie_disponibili${extra ? ', deleted_at' : ''})
            VALUES (${S(`${TAG} ${key}`)}, ${shelf}, ${S(TAG + '.' + key)}, ${copies.length}, ${copies.length}${extra ? ', NOW()' : ''})`);
        for (const [c, stato] of copies) {
            data.copies[c] = fx.dbInsert(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${data.books[key]}, ${S(code(c))}, ${S(stato)})`);
        }
    };
    book('B1', data.shelfX, [['A1', 'disponibile'], ['A2', 'disponibile'], ['A3', 'disponibile']]);
    book('B2', data.shelfX, [['L1', 'disponibile']]);
    book('B3', data.shelfY, [['Y1', 'disponibile']]);
    // Recorded lost: left out of the snapshot, a scan of it says it turned up.
    book('B4', data.shelfX, [['P1', 'perso']]);
    // A soft-deleted book: its copy is an unknown code.
    book('B5', data.shelfX, [['D1', 'disponibile']], 'deleted');
    // L1 is out on a real active loan to the reader.
    fx.dbExec(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo)
        VALUES (${data.books.B2}, ${data.copies.L1}, ${users.reader.id}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'in_corso', 1)`);
    fx.dbExec(`UPDATE copie SET stato='prestato' WHERE id=${data.copies.L1}`);
    fx.dbExec(`UPDATE libri SET copie_disponibili=0 WHERE id=${data.books.B2}`);
    for (const who of ['admin', 'staff', 'reader', 'stocktaker']) {
        users[who].token = await fx.login(request, users[who].email, PASS, `mst-${who}-${RUN}`);
    }
});

test.afterAll(async () => {
    const ids = Object.values(users).map((u) => u.id).filter(Boolean);
    try {
        const found = ids.length
            ? fx.dbScalar(`SELECT GROUP_CONCAT(id) FROM inventario_sessioni WHERE nome LIKE ${S(TAG + '%')} OR aperta_da IN (${ids.join(',')})`)
            : '';
        const sessions = [...new Set([...data.sessions, ...(found && found !== 'NULL' ? found.split(',').map(Number) : [])])];
        if (sessions.length) {
            fx.dbExec(`DELETE FROM log_modifiche WHERE tabella='inventario_sessioni' AND record_id IN (${sessions.join(',')})`);
            // inventario_attese and inventario_scansioni go with the session (ON DELETE CASCADE).
            fx.dbExec(`DELETE FROM inventario_sessioni WHERE id IN (${sessions.join(',')})`);
        }
    } catch { /* best effort */ }
    if (ids.length) {
        try { fx.dbExec(`DELETE FROM mobile_idempotency_keys WHERE user_id IN (${ids.join(',')})`); } catch { /* optional */ }
    }
    fx.deleteBooks(Object.values(data.books));
    try { fx.dbExec(`DELETE FROM mensole WHERE scaffale_id IN (${data.shelfX || 0}, ${data.shelfY || 0})`); } catch { /* best effort */ }
    try { fx.dbExec(`DELETE FROM scaffali WHERE codice IN (${S(TAG + 'X')}, ${S(TAG + 'Y')})`); } catch { /* best effort */ }
    fx.deleteUsers(ids);
    if (roleId) { try { fx.dbExec(`DELETE FROM ruoli WHERE id=${roleId}`); } catch { /* best effort */ } }
    fx.restoreGate(gate);
    await featureToggles.restoreFeatures(featureSnapshot);
});

async function createSession(request, who, name, extra = {}, headers = undefined) {
    const r = await call(request, 'POST', `${P}/sessions`, who, { body: { name, shelf_id: data.shelfX, ...extra }, extraHeaders: headers });
    if (r.json && r.json.data && r.json.data.id) data.sessions.push(r.json.data.id);
    return r;
}

let s1 = 0;

test('1. only operators reach the area; a reader is refused, anonymous is unauthorised', async ({ request }) => {
    expect((await call(request, 'GET', `${P}/options`, null)).status).toBe(401);
    for (const path of [`${P}/options`, `${P}/sessions`]) {
        const r = await call(request, 'GET', path, 'reader');
        expect(r.status, path).toBe(403);
        expect(r.json.error.code).toBe('forbidden');
    }
    const r = await call(request, 'POST', `${P}/sessions`, 'reader', { body: { name: `${TAG} reader` } });
    expect(r.status).toBe(403);
    expect(fx.dbInt(`SELECT COUNT(*) FROM inventario_sessioni WHERE nome=${S(`${TAG} reader`)}`)).toBe(0);
    // inventory.manage opens the stocktake to a custom role, as /admin/inventory does on the web…
    const role = await call(request, 'GET', `${P}/options`, 'stocktaker');
    expect(role.status).toBe(200);
    expect(role.json.data.can_mark_lost).toBe(false);
    // …and nothing else of the staff surface.
    expect((await call(request, 'GET', '/staff/loans', 'stocktaker')).status).toBe(403);
});

test('2. options carry shelves with levels, outcomes with the web colours and tones, and the admin-only action', async ({ request }) => {
    const admin = await call(request, 'GET', `${P}/options`, 'admin');
    expect(admin.status).toBe(200);
    const d = admin.json.data;
    const shelf = d.shelves.find((s) => s.id === data.shelfX);
    expect(shelf.code).toBe(TAG + 'X');
    expect(shelf.levels).toEqual([{ id: data.levelX, level: 1 }]);
    const found = d.outcomes.find((o) => o.code === 'found');
    expect(found.color.name).toBe('green');
    expect(found.tone).toEqual({ frequency_hz: 880, duration_ms: 120 });
    expect(found.label.length).toBeGreaterThan(0);
    expect(d.outcomes.find((o) => o.code === 'missing').scan_result).toBe(false);
    expect(d.outcomes.map((o) => o.code).sort()).toEqual(['duplicate', 'found', 'misplaced', 'missing', 'on_loan', 'out_of_scope', 'unknown']);
    expect(d.media_types.map((m) => m.code)).toEqual(['libro', 'disco', 'audiolibro', 'dvd', 'altro']);
    expect(d.missing_actions.find((a) => a.code === 'lost').admin_only).toBe(true);
    expect(d.limits).toMatchObject({ max_code_length: 100, max_upload_codes: 5000, max_upload_bytes: 2097152, scans_per_minute: 600 });
    expect(d.can_mark_lost).toBe(true);
    const staff = await call(request, 'GET', `${P}/options`, 'staff');
    expect(staff.status).toBe(200);
    expect(staff.json.data.can_mark_lost).toBe(false);
});

test('3. creating a session validates every field', async ({ request }) => {
    const cases = [
        [{ name: '   ' }, 'name'],
        [{ name: `${TAG} bad`, media_type: 'vinile' }, 'media_type'],
        [{ name: `${TAG} bad`, shelf_id: 'abc' }, 'shelf_id'],
        [{ name: `${TAG} bad`, genre_id: -3 }, 'genre_id'],
        [{ name: `${TAG} bad`, branch_id: 999999999 }, 'branch_id'],
    ];
    for (const [body, field] of cases) {
        const r = await call(request, 'POST', `${P}/sessions`, 'staff', { body });
        expect(r.status, JSON.stringify(body)).toBe(422);
        expect(r.json.error.code).toBe('validation');
        expect(Object.keys(r.json.meta.fields)).toContain(field);
    }
    expect(fx.dbInt(`SELECT COUNT(*) FROM inventario_sessioni WHERE nome LIKE ${S(`${TAG} bad%`)}`)).toBe(0);
});

test('4. staff opens a session on a shelf; the Idempotency-Key replays it instead of opening a second one', async ({ request }) => {
    const key = `mst-open-${RUN}`;
    const r = await createSession(request, 'staff', `${TAG} shelf X`, { note: 'From the phone' }, { 'Idempotency-Key': key });
    expect(r.status, r.text.slice(0, 300)).toBe(201);
    const d = r.json.data;
    s1 = d.id;
    expect(d.status).toBe('open');
    expect(d.filter.shelf_id).toBe(data.shelfX);
    expect(d.filter_labels.join(' ')).toContain(TAG + 'X');
    expect(d.note).toBe('From the phone');
    expect(d.expected_total).toBe(4); // A1 A2 A3 L1: the lost copy and the deleted book stay out
    expect(d.summary).toMatchObject({ expected: 4, found: 0, on_loan: 1, missing: 3, scans: 0 });
    expect(d.opened_by).toEqual({ id: users.staff.id, name: expect.any(String) });
    expect(d).toMatchObject({ can_scan: true, can_upload: true, can_close: true, can_cancel: true, can_mark_lost: false });
    expect(Number(fx.dbScalar(`SELECT totale_attese FROM inventario_sessioni WHERE id=${s1}`))).toBe(4);
    expect(fx.dbScalar(`SELECT GROUP_CONCAT(copia_id ORDER BY copia_id) FROM inventario_attese WHERE sessione_id=${s1}`))
        .toBe(['A1', 'A2', 'A3', 'L1'].map((c) => data.copies[c]).sort((a, b) => a - b).join(','));

    const replay = await call(request, 'POST', `${P}/sessions`, 'staff', { body: { name: `${TAG} shelf X`, shelf_id: data.shelfX, note: 'From the phone' }, extraHeaders: { 'Idempotency-Key': key } });
    expect(replay.status).toBe(200);
    expect(replay.json.meta.replayed).toBe(true);
    expect(replay.json.data.id).toBe(s1);
    expect(fx.dbInt(`SELECT COUNT(*) FROM inventario_sessioni WHERE nome=${S(`${TAG} shelf X`)}`)).toBe(1);

    const other = await call(request, 'POST', `${P}/sessions`, 'staff', { body: { name: `${TAG} another`, shelf_id: data.shelfX }, extraHeaders: { 'Idempotency-Key': key } });
    expect(other.status).toBe(422);
    expect(other.json.error.code).toBe('idempotency_conflict');
});

test('5. the session list pages by status with counters', async ({ request }) => {
    const open = await call(request, 'GET', `${P}/sessions?status=open&limit=100`, 'staff');
    expect(open.status).toBe(200);
    const row = open.json.data.find((s) => s.id === s1);
    expect(row.summary.expected).toBe(4);
    expect(row.recent_scans).toBeUndefined();
    expect(open.json.meta.total_count).toBeGreaterThanOrEqual(1);
    expect(open.json.data.every((s) => s.status === 'open')).toBe(true);
    const closed = await call(request, 'GET', `${P}/sessions?status=closed&limit=100`, 'staff');
    expect(closed.json.data.some((s) => s.id === s1)).toBe(false);
    const bad = await call(request, 'GET', `${P}/sessions?status=bogus`, 'staff');
    expect(bad.status).toBe(422);
    expect(bad.json.meta.fields.status).toBeTruthy();
});

test('6. scans answer the outcome, the copy and the updated counters', async ({ request }) => {
    const scan = (c, shelfId = data.shelfX, who = 'staff') => call(request, 'POST', `${P}/sessions/${s1}/scans`, who, { body: { code: c, shelf_id: shelfId } });

    const found = await scan(code('A1'));
    expect(found.status, found.text.slice(0, 300)).toBe(201);
    expect(found.json.data.scan).toMatchObject({ code: code('A1'), outcome: 'found', copy_id: data.copies.A1, book_id: data.books.B1, title: `${TAG} B1`, off_shelf: false });
    expect(found.json.data.scan.color.name).toBe('green');
    expect(found.json.data.tone).toEqual({ frequency_hz: 880, duration_ms: 120 });
    expect(found.json.data.summary).toMatchObject({ found: 1, missing: 2, scans: 1, mine: 1 });
    expect(found.json.data.session_status).toBe('open');

    // Expected on shelf X, read while the operator says they are on shelf Y.
    const misplaced = await scan(code('A2'), data.shelfY);
    expect(misplaced.json.data.scan.outcome).toBe('misplaced');
    expect(misplaced.json.data.scan.expected_shelf).toContain(TAG + 'X');

    const onLoan = await scan(code('L1'));
    expect(onLoan.json.data.scan.outcome).toBe('on_loan');

    const unknown = await scan(`${TAG}-NOPE`);
    expect(unknown.json.data.scan).toMatchObject({ outcome: 'unknown', copy_id: null, title: null });

    const deleted = await scan(code('D1'));
    expect(deleted.json.data.scan.outcome).toBe('unknown');

    const outOfScope = await scan(code('Y1'));
    expect(outOfScope.json.data.scan.outcome).toBe('out_of_scope');
    expect(outOfScope.json.data.scan.off_shelf).toBe(false);

    const turnedUp = await scan(code('P1'));
    expect(turnedUp.json.data.scan).toMatchObject({ outcome: 'out_of_scope', off_shelf: true, copy_status: 'perso' });

    expect(fx.dbScalar(`SELECT GROUP_CONCAT(esito ORDER BY id) FROM inventario_scansioni WHERE sessione_id=${s1}`))
        .toBe('trovata,fuori_posto,in_prestito,sconosciuta,sconosciuta,fuori_ambito,fuori_ambito');
    expect(fx.dbInt(`SELECT COUNT(*) FROM inventario_scansioni WHERE sessione_id=${s1} AND scansionato_da=${users.staff.id}`)).toBe(7);
});

test('7. a retried scan with the same Idempotency-Key is recorded once; a second read without it is a duplicate', async ({ request }) => {
    const key = `mst-scan-${RUN}`;
    const first = await call(request, 'POST', `${P}/sessions/${s1}/scans`, 'staff', { body: { code: code('A3'), shelf_id: data.shelfX }, extraHeaders: { 'Idempotency-Key': key } });
    expect(first.status).toBe(201);
    expect(first.json.data.scan.outcome).toBe('found');
    const replay = await call(request, 'POST', `${P}/sessions/${s1}/scans`, 'staff', { body: { code: code('A3'), shelf_id: data.shelfX }, extraHeaders: { 'Idempotency-Key': key } });
    expect(replay.status).toBe(200);
    expect(replay.json.meta.replayed).toBe(true);
    expect(replay.json.data.scan.id).toBe(first.json.data.scan.id);
    expect(replay.json.data.scan.outcome).toBe('found');
    expect(fx.dbInt(`SELECT COUNT(*) FROM inventario_scansioni WHERE sessione_id=${s1} AND codice_letto=${S(code('A3'))}`)).toBe(1);

    const again = await call(request, 'POST', `${P}/sessions/${s1}/scans`, 'admin', { body: { code: code('A3') } });
    expect(again.status).toBe(201);
    // The repeat is stored without its copy, but the answer still names it.
    expect(again.json.data.scan).toMatchObject({ outcome: 'duplicate', copy_id: data.copies.A3, title: `${TAG} B1` });
    expect(again.json.data.summary).toMatchObject({ found: 2, duplicates: 1, mine: 1 });
    expect(fx.dbInt(`SELECT COUNT(*) FROM inventario_scansioni WHERE sessione_id=${s1} AND codice_letto=${S(code('A3'))}`)).toBe(2);
});

test('8. scan validation, unknown session', async ({ request }) => {
    const empty = await call(request, 'POST', `${P}/sessions/${s1}/scans`, 'staff', { body: { code: '  ' } });
    expect(empty.status).toBe(422);
    expect(empty.json.meta.fields.code).toBeTruthy();
    const long = await call(request, 'POST', `${P}/sessions/${s1}/scans`, 'staff', { body: { code: 'X'.repeat(101) } });
    expect(long.status).toBe(422);
    const shelf = await call(request, 'POST', `${P}/sessions/${s1}/scans`, 'staff', { body: { code: code('A1'), shelf_id: 'x' } });
    expect(shelf.status).toBe(422);
    expect(shelf.json.meta.fields.shelf_id).toBeTruthy();
    const missing = await call(request, 'POST', `${P}/sessions/999999999/scans`, 'staff', { body: { code: code('A1') } });
    expect(missing.status).toBe(404);
    expect(missing.json.error.code).toBe('not_found');
    expect((await call(request, 'GET', `${P}/sessions/999999999`, 'staff')).status).toBe(404);
});

test('9. detail, state, the scan list (paged, by outcome, only mine) and the report', async ({ request }) => {
    const detail = await call(request, 'GET', `${P}/sessions/${s1}`, 'staff');
    expect(detail.status).toBe(200);
    expect(detail.json.data.recent_scans.length).toBe(9);
    expect(detail.json.data.recent_scans[0].outcome).toBe('duplicate');
    expect(detail.json.data.history.some((h) => h.event === 'inventory.opened')).toBe(true);
    const mine = await call(request, 'GET', `${P}/sessions/${s1}?mine=1`, 'admin');
    expect(mine.json.data.recent_scans.length).toBe(1);
    expect(mine.json.data.recent_scans[0].operator.id).toBe(users.admin.id);

    const state = await call(request, 'GET', `${P}/sessions/${s1}/state`, 'staff');
    expect(state.status).toBe(200);
    expect(state.json.data).toMatchObject({ id: s1, status: 'open' });
    expect(state.json.data.summary).toMatchObject({ expected: 4, found: 2, misplaced: 1, on_loan: 1, missing: 0, unknown: 2, out_of_scope: 2, duplicates: 1, scans: 9 });

    const page1 = await call(request, 'GET', `${P}/sessions/${s1}/scans?limit=4`, 'staff');
    expect(page1.status).toBe(200);
    expect(page1.json.data.length).toBe(4);
    expect(page1.json.meta).toMatchObject({ has_more: true, total_count: 9 });
    const page3 = await call(request, 'GET', `${P}/sessions/${s1}/scans?limit=4&cursor=${encodeURIComponent(page1.json.meta.next_cursor)}`, 'staff');
    const page2Ids = page3.json.data.map((s) => s.id);
    expect(page2Ids.some((id) => page1.json.data.map((s) => s.id).includes(id))).toBe(false);
    const unknown = await call(request, 'GET', `${P}/sessions/${s1}/scans?outcome=unknown`, 'staff');
    expect(unknown.json.data.length).toBe(2);
    expect(unknown.json.data.every((s) => s.outcome === 'unknown')).toBe(true);
    expect((await call(request, 'GET', `${P}/sessions/${s1}/scans?outcome=bogus`, 'staff')).status).toBe(422);
    const mineList = await call(request, 'GET', `${P}/sessions/${s1}/scans?mine=1`, 'admin');
    expect(mineList.json.meta.total_count).toBe(1);

    const report = await call(request, 'GET', `${P}/sessions/${s1}/report?limit=200`, 'staff');
    expect(report.status).toBe(200);
    // Every expected copy was read (L1 as on loan), so the rows are the 9 scans.
    expect(report.json.meta.total_count).toBe(9);
    expect(report.json.data.length).toBe(9);
    expect(report.json.data.find((r) => r.code === code('L1')).category).toBe('on_loan');
    const dup = await call(request, 'GET', `${P}/sessions/${s1}/report?category=duplicate`, 'staff');
    expect(dup.json.data.length).toBe(1);
    expect((await call(request, 'GET', `${P}/sessions/${s1}/report?category=bogus`, 'staff')).status).toBe(422);
});

let s2 = 0;

test('10. a list of codes uploads as one scan each; a wrong file type is refused', async ({ request }) => {
    const r = await createSession(request, 'admin', `${TAG} upload`);
    expect(r.status).toBe(201);
    s2 = r.json.data.id;
    const bad = await call(request, 'POST', `${P}/sessions/${s2}/upload`, 'staff', { multipart: { file: { name: 'codes.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4') } } });
    expect(bad.status).toBe(422);
    expect(bad.json.meta.fields.file).toBeTruthy();
    const none = await call(request, 'POST', `${P}/sessions/${s2}/upload`, 'staff', { multipart: { shelf_id: String(data.shelfX) } });
    expect(none.status).toBe(422);

    const list = ['codice', code('A1'), code('A1'), `${TAG}-GHOST`, code('Y1')].join('\n') + '\n';
    const up = await call(request, 'POST', `${P}/sessions/${s2}/upload`, 'staff', { multipart: { file: { name: 'codes.txt', mimeType: 'text/plain', buffer: Buffer.from(list) }, shelf_id: String(data.shelfX) } });
    expect(up.status, up.text.slice(0, 300)).toBe(200);
    expect(up.json.data.total).toBe(4);
    const counts = Object.fromEntries(up.json.data.outcomes.map((o) => [o.code, o.count]));
    expect(counts).toMatchObject({ found: 1, duplicate: 1, unknown: 1, out_of_scope: 1, misplaced: 0, on_loan: 0 });
    expect(up.json.data.summary).toMatchObject({ found: 1, scans: 4 });
    expect(fx.dbInt(`SELECT COUNT(*) FROM inventario_scansioni WHERE sessione_id=${s2}`)).toBe(4);

    const csv = await call(request, 'POST', `${P}/sessions/${s2}/upload`, 'staff', { multipart: { file: { name: 'codes.csv', mimeType: 'text/csv', buffer: Buffer.from(`code;title\n"${code('A2')}";B1\n`) } } });
    expect(csv.status).toBe(200);
    expect(csv.json.data.total).toBe(1);
    expect(fx.dbScalar(`SELECT esito FROM inventario_scansioni WHERE sessione_id=${s2} AND codice_letto=${S(code('A2'))}`)).toBe('trovata');
});

test('11. the CSV and PDF reports download with their file name', async ({ request }) => {
    const csv = await call(request, 'GET', `${P}/sessions/${s1}/report.csv`, 'staff');
    expect(csv.status).toBe(200);
    expect(csv.headers['content-type']).toContain('text/csv');
    expect(csv.headers['content-disposition']).toMatch(new RegExp(`attachment; filename="revisione-${s1}-[^"]*\\.csv"`));
    expect(csv.text).toContain(code('A1'));
    expect(csv.text.split('\n').length).toBeGreaterThan(9);
    const pdf = await call(request, 'GET', `${P}/sessions/${s1}/report.pdf`, 'staff');
    expect(pdf.status).toBe(200);
    expect(pdf.headers['content-type']).toContain('application/pdf');
    expect(pdf.headers['content-disposition']).toMatch(new RegExp(`attachment; filename="revisione-${s1}-[^"]*\\.pdf"`));
    expect(pdf.text.startsWith('%PDF')).toBe(true);
    expect((await call(request, 'GET', `${P}/sessions/999999999/report.csv`, 'staff')).status).toBe(404);
});

test('12. staff closes leaving the missing copies; marking them lost is admin-only; a closed session takes no scans', async ({ request }) => {
    const lost = await call(request, 'POST', `${P}/sessions/${s2}/close`, 'staff', { body: { missing_action: 'lost' } });
    expect(lost.status).toBe(403);
    expect(lost.json.error.code).toBe('not_permitted');
    expect(fx.dbScalar(`SELECT stato FROM inventario_sessioni WHERE id=${s2}`)).toBe('aperta');
    const bad = await call(request, 'POST', `${P}/sessions/${s2}/close`, 'staff', { body: { missing_action: 'burn' } });
    expect(bad.status).toBe(422);
    expect(bad.json.meta.fields.missing_action).toBeTruthy();

    const close = await call(request, 'POST', `${P}/sessions/${s1}/close`, 'staff', { body: { missing_action: 'none' } });
    expect(close.status, close.text.slice(0, 300)).toBe(200);
    expect(close.json.data).toMatchObject({ id: s1, status: 'closed', missing_action: 'none', can_scan: false, can_close: false });
    expect(close.json.data.close_result).toMatchObject({ resumed: false, marked_lost: 0, partial: false });
    expect(close.json.data.closed_by.id).toBe(users.staff.id);
    expect(fx.dbScalar(`SELECT CONCAT(stato, '/', azione_mancanti) FROM inventario_sessioni WHERE id=${s1}`)).toBe('chiusa/nessuna');
    expect(close.json.data.history.some((h) => h.event === 'inventory.closed')).toBe(true);

    const again = await call(request, 'POST', `${P}/sessions/${s1}/close`, 'staff', { body: { missing_action: 'none' } });
    expect(again.status).toBe(409);
    expect(again.json.error.code).toBe('session_closed');
    const scan = await call(request, 'POST', `${P}/sessions/${s1}/scans`, 'staff', { body: { code: code('A1') } });
    expect(scan.status).toBe(409);
    expect(scan.json.error.code).toBe('session_closed');
    const up = await call(request, 'POST', `${P}/sessions/${s1}/upload`, 'staff', { multipart: { file: { name: 'c.txt', mimeType: 'text/plain', buffer: Buffer.from(code('A1')) } } });
    expect(up.status).toBe(409);
    const cancel = await call(request, 'POST', `${P}/sessions/${s1}/cancel`, 'staff');
    expect(cancel.status).toBe(409);
    // A closed session still reports (frozen buckets).
    const pdf = await call(request, 'GET', `${P}/sessions/${s1}/report.pdf`, 'admin');
    expect(pdf.status).toBe(200);
});

test('13. an administrator closes marking the missing copies lost, and can finish the marking again', async ({ request }) => {
    // s2: A1 and A2 found, A3 missing, L1 on loan.
    const close = await call(request, 'POST', `${P}/sessions/${s2}/close`, 'admin', { body: { missing_action: 'lost' } });
    expect(close.status, close.text.slice(0, 300)).toBe(200);
    expect(close.json.data).toMatchObject({ status: 'closed', missing_action: 'lost' });
    expect(close.json.data.close_result).toMatchObject({ resumed: false, marked_lost: 1, pending_lost: 0, partial: false });
    expect(close.json.data.summary).toMatchObject({ found: 2, on_loan: 1, missing: 1, lost: 1 });
    expect(copyState('A3')).toBe('perso');
    expect(copyState('A1')).toBe('disponibile');
    expect(copyState('L1')).toBe('prestato');

    const resume = await call(request, 'POST', `${P}/sessions/${s2}/close`, 'admin', { body: { missing_action: 'lost' } });
    expect(resume.status).toBe(200);
    expect(resume.json.data.close_result).toMatchObject({ resumed: true, pending_lost: 0 });
    expect(resume.json.data.can_mark_lost).toBe(false);
    fx.dbExec(`UPDATE copie SET stato='disponibile' WHERE id=${data.copies.A3}`);
});

test('14. a session can be cancelled once; nothing changes on the copies', async ({ request }) => {
    const r = await createSession(request, 'staff', `${TAG} to cancel`);
    expect(r.status).toBe(201);
    const id = r.json.data.id;
    const cancel = await call(request, 'POST', `${P}/sessions/${id}/cancel`, 'staff');
    expect(cancel.status, cancel.text.slice(0, 300)).toBe(200);
    expect(cancel.json.data).toMatchObject({ id, status: 'cancelled', can_scan: false });
    expect(fx.dbScalar(`SELECT stato FROM inventario_sessioni WHERE id=${id}`)).toBe('annullata');
    const again = await call(request, 'POST', `${P}/sessions/${id}/cancel`, 'staff');
    expect(again.status).toBe(409);
    expect(again.json.error.code).toBe('session_cancelled');
    const close = await call(request, 'POST', `${P}/sessions/${id}/close`, 'admin', { body: { missing_action: 'lost' } });
    expect(close.status).toBe(409);
    expect((await call(request, 'POST', `${P}/sessions/999999999/cancel`, 'staff')).status).toBe(404);
    expect((await call(request, 'POST', `${P}/sessions/999999999/close`, 'staff', { body: {} })).status).toBe(404);
    expect(copyState('A1')).toBe('disponibile');
});

test('15. with the module off every route answers 404 feature_disabled', async ({ request }) => {
    await featureToggles.writeFeatures({ stocktake: '0' });
    try {
        for (const [method, path] of [['GET', `${P}/options`], ['GET', `${P}/sessions`], ['GET', `${P}/sessions/${s1}`], ['POST', `${P}/sessions/${s1}/scans`]]) {
            const r = await call(request, method, path, 'admin', method === 'POST' ? { body: { code: code('A1') } } : {});
            expect(r.status, path).toBe(404);
            expect(r.json.error.code).toBe('feature_disabled');
        }
        const health = await call(request, 'GET', '/health', null);
        expect(health.json.data.features.staff_stocktake).toBe(false);
    } finally {
        await featureToggles.writeFeatures({ stocktake: '1' });
    }
    const health = await call(request, 'GET', '/health', null);
    expect(health.json.data.features.staff_stocktake).toBe(true);
});

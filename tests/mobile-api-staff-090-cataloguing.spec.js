// @ts-check
// Mobile API 1.10.0 — Pinakes 0.9.0 cataloguing areas from the staff app:
// suppliers, supplier + RDA fields on books, catalogue cards, MARC import /
// export and copy cataloguing.
//
// What this proves, against the real Apache instance:
//   - /staff/suppliers is the CRUD of /admin/suppliers (validation, the
//     Idempotency-Key replay on create, delete unlinks the books);
//   - POST/PUT /staff/books store supplier_id and the RDA overrides while
//     their modules are on and ignore them while they are off; the detail,
//     /staff/lookups and the ?supplier_id= list filter follow;
//   - catalogue card PDFs (one book, a selection) are attachments;
//   - a MARC file goes upload → chunks → done through the web importer, with
//     the CSV chunk contract (out_of_order, gone), the three duplicate modes,
//     the supplier of the batch, cancel, private jobs (admin sees them all);
//   - the MARC export answers ISO 2709 (MARC 21, UNIMARC) and MARCXML;
//   - copy cataloguing searches this installation's own SRU server (answered
//     in-process, no network), imports a result, replays an Idempotency-Key
//     and refuses a reused key with another selection; without the
//     z39-server plugin the routes answer copy_cataloging_unavailable;
//   - a reader gets 403, a custom role follows the catalogue permission,
//     a module switched off answers 404 feature_disabled.
//
// Run: /tmp/run-e2e.sh tests/mobile-api-staff-090-cataloguing.spec.js --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const F = require('./helpers/mobile-api-fixtures');
const FT = require('./helpers/feature-toggles');
const { marcFile, cleanupRun } = require('./helpers/marc-e2e');

test.describe.configure({ mode: 'serial' });
test.skip(!F.ENV_READY, 'Missing E2E env: DB_* and ADMIN_EMAIL/ADMIN_PASS');

const RUN = 'CA' + Date.now().toString(36);
const PASS = 'Cat1234Test!';
/** @type {Record<string, any>} */
const ctx = { users: [], books: [], suppliers: [], roles: [], files: [] };

/** A binary download: status, headers and the raw body. */
async function download(request, token, path) {
    const res = await request.fetch(`${F.API}${path}`, { headers: { Authorization: `Bearer ${token}`, Accept: '*/*' } });
    return { status: res.status(), headers: res.headers(), body: await res.body() };
}

function marcUpload(request, token, file, fields = {}) {
    return F.api(request, 'POST', '/staff/marc/imports', {
        token,
        multipart: { file: { name: file.split('/').pop(), mimeType: 'application/octet-stream', buffer: fs.readFileSync(file) }, ...fields },
    });
}

const bookIds = () => F.dbScalar(`SELECT GROUP_CONCAT(id ORDER BY titolo) FROM libri WHERE titolo LIKE 'Libro MARC ${RUN} %' AND deleted_at IS NULL`);

test.beforeAll(async ({ browser, request }) => {
    const page = await browser.newPage();
    ctx.gate = await F.ensureMobileApiReady(page);
    await page.close();
    ctx.features = await FT.enableFeatures(['suppliers', 'rda_types', 'marc', 'catalogue_cards']);
    for (const [key, role] of [['staff', 'staff'], ['other', 'staff'], ['admin', 'admin'], ['std', 'standard']]) {
        const email = `cat_${key}_${RUN}@pinakes.test`;
        ctx[`${key}Id`] = F.createUser(email, PASS, role, `CAT${key.slice(0, 2).toUpperCase()}`);
        ctx.users.push(ctx[`${key}Id`]);
        ctx[key] = await F.login(request, email, PASS, `cat-${key}`);
    }
    // Two custom roles on reader accounts: the catalogue permission, and the loan desk only.
    for (const [key, perms] of [['cataloguer', '["backend.access","catalog.manage"]'], ['desk', '["backend.access","loans.manage"]']]) {
        const name = `ZZ Cat ${key} ${RUN}`;
        const roleId = F.dbInsert(`INSERT INTO ruoli (nome, permessi) VALUES (${F.sqlStr(name)}, ${F.sqlStr(perms)})`);
        ctx.roles.push(roleId);
        const email = `cat_${key}_${RUN}@pinakes.test`;
        const id = F.createUser(email, PASS, 'standard', `CAT${key.slice(0, 2).toUpperCase()}`);
        F.dbExec(`UPDATE utenti SET ruolo_id=${roleId} WHERE id=${id}`);
        ctx.users.push(id);
        ctx[key] = await F.login(request, email, PASS, `cat-${key}`);
    }
});

test.afterAll(async ({ browser }) => {
    if (ctx.z39Deactivated) {
        await setZ39Active(browser, true);
    }
    const books = F.dbScalar(`SELECT GROUP_CONCAT(id) FROM libri WHERE titolo LIKE '%${RUN}%'`);
    if (books && books !== 'NULL') F.deleteBooks(books.split(',').map(Number));
    try { cleanupRun(RUN); } catch { /* best effort */ }
    if (ctx.suppliers.length) { try { F.dbExec(`DELETE FROM fornitori WHERE id IN (${ctx.suppliers.join(',')})`); } catch {} }
    try { F.dbExec(`DELETE FROM fornitori WHERE nome LIKE '%${RUN}%'`); } catch {}
    const users = ctx.users.join(',');
    if (users) {
        const logs = F.dbScalar(`SELECT GROUP_CONCAT(QUOTE(import_id)) FROM import_logs WHERE user_id IN (${users})`);
        if (logs && logs !== 'NULL') { try { F.dbExec(`DELETE FROM import_logs WHERE import_id IN (${logs})`); } catch {} }
        // Staged MARC records of jobs left unfinished (cancel/complete already removed theirs).
        const states = F.dbRows(`SELECT state FROM mobile_import_jobs WHERE user_id IN (${users}) AND kind='marc'`);
        for (const row of states) {
            try {
                const file = JSON.parse(row.state).file_path;
                if (typeof file === 'string' && /\/writable\/uploads\/marc_import_[^/]+\.jsonl$/.test(file) && fs.existsSync(file)) fs.unlinkSync(file);
            } catch { /* not JSON */ }
        }
        try { F.dbExec(`DELETE FROM mobile_import_jobs WHERE user_id IN (${users})`); } catch {}
        try { F.dbExec(`DELETE FROM mobile_idempotency_keys WHERE user_id IN (${users})`); } catch {}
    }
    F.deleteUsers(ctx.users);
    if (ctx.roles.length) { try { F.dbExec(`DELETE FROM ruoli WHERE id IN (${ctx.roles.join(',')})`); } catch {} }
    for (const f of ctx.files) { try { fs.rmSync(require('path').dirname(f), { recursive: true, force: true }); } catch {} }
    await FT.restoreFeatures(ctx.features);
    F.restoreGate(ctx.gate);
});

/** Activate / deactivate z39-server through the admin plugin endpoints. */
async function setZ39Active(browser, active) {
    const pid = F.dbInt("SELECT id FROM plugins WHERE name='z39-server' LIMIT 1");
    const page = await browser.newPage();
    try {
        await F.adminLogin(page);
        await page.goto(`${F.BASE}/admin/plugins`);
        const status = await page.evaluate(async ({ id, on }) => {
            const base = window.location.origin + (window.BASE_PATH || '');
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const r = await fetch(`${base}/admin/plugins/${id}/${on ? 'activate' : 'deactivate'}`, { method: 'POST', headers: { 'X-CSRF-Token': csrf } });
            return r.status;
        }, { id: pid, on: active });
        expect(status, `z39-server ${active ? 'activate' : 'deactivate'}`).toBeLessThan(400);
    } finally {
        await page.close();
    }
    expect(F.dbScalar(`SELECT is_active FROM plugins WHERE id=${pid}`)).toBe(active ? '1' : '0');
}

test('health flags, reader 403 on every route, custom roles follow the catalogue permission', async ({ request }) => {
    const f = (await F.api(request, 'GET', '/health')).json.data.features;
    expect(f.staff_suppliers).toBe(true);
    expect(f.staff_catalogue_cards).toBe(true);
    expect(f.staff_marc).toBe(true);
    const job = '00000000-0000-4000-8000-000000000000';
    for (const [method, route] of [
        ['GET', '/staff/suppliers'], ['POST', '/staff/suppliers'], ['GET', '/staff/suppliers/1'], ['PUT', '/staff/suppliers/1'], ['DELETE', '/staff/suppliers/1'],
        ['GET', '/staff/books/1/catalogue-card.pdf'], ['GET', '/staff/books/catalogue-cards.pdf?ids=1'],
        ['GET', '/staff/marc/options'], ['POST', '/staff/marc/imports'], ['GET', `/staff/marc/imports/${job}`], ['POST', `/staff/marc/imports/${job}/chunk`],
        ['DELETE', `/staff/marc/imports/${job}`], ['GET', '/staff/marc/export'],
        ['GET', '/staff/copy-cataloging/servers'], ['POST', '/staff/copy-cataloging/search'], ['POST', '/staff/copy-cataloging/import'],
    ]) {
        const r = await F.api(request, method, route, { token: ctx.std, body: method === 'GET' || method === 'DELETE' ? undefined : {} });
        expect(r.status, `${method} ${route}`).toBe(403);
        expect(r.json.error.code).toBe('forbidden');
    }
    // A custom role with the catalogue permission reaches the area, the loan desk does not.
    expect((await F.api(request, 'GET', '/staff/suppliers', { token: ctx.cataloguer })).status).toBe(200);
    expect((await F.api(request, 'GET', '/staff/marc/options', { token: ctx.cataloguer })).status).toBe(200);
    expect((await F.api(request, 'GET', '/staff/copy-cataloging/servers', { token: ctx.cataloguer })).status).toBe(200);
    expect((await F.api(request, 'GET', '/staff/suppliers', { token: ctx.desk })).status).toBe(403);
    expect((await F.api(request, 'GET', '/staff/marc/options', { token: ctx.desk })).status).toBe(403);
    expect((await F.api(request, 'GET', '/staff/copy-cataloging/servers', { token: ctx.desk })).status).toBe(403);
});

test.describe('suppliers', () => {
    test('create: validation, website normalised, Idempotency-Key replay', async ({ request }) => {
        let r = await F.api(request, 'POST', '/staff/suppliers', { token: ctx.staff, body: { email: 'not-an-email', website: 'not a url', vat_number: 'X'.repeat(21) } });
        expect(r.status).toBe(422);
        expect(r.json.error.code).toBe('validation');
        expect(Object.keys(r.json.meta.fields).sort()).toEqual(['email', 'name', 'vat_number', 'website']);
        r = await F.api(request, 'POST', '/staff/suppliers', { token: ctx.staff, body: { name: 123 } });
        expect(r.status).toBe(201); // a number is a valid name, as the web form posts it
        ctx.suppliers.push(r.json.data.id);
        F.dbExec(`DELETE FROM fornitori WHERE id=${r.json.data.id}`);

        const body = { name: `Fornitore ${RUN}`, contact_person: 'Mario Rossi', email: 'ordini@example.org', phone: '+39 02 1234', website: 'www.example.org', address: 'Via Roma 1', vat_number: 'IT01234567890', notes: 'Note' };
        const key = `sup-${RUN}`;
        const first = await F.api(request, 'POST', '/staff/suppliers', { token: ctx.staff, body, extraHeaders: { 'Idempotency-Key': key } });
        expect(first.status, first.text).toBe(201);
        const s = first.json.data;
        ctx.suppliers.push(s.id);
        ctx.supplierId = s.id;
        expect(s).toMatchObject({ name: body.name, contact_person: 'Mario Rossi', email: 'ordini@example.org', website: 'https://www.example.org', vat_number: 'IT01234567890', book_count: 0, can_edit: true, can_delete: true });
        expect(s.created_at).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/);
        const replay = await F.api(request, 'POST', '/staff/suppliers', { token: ctx.staff, body, extraHeaders: { 'Idempotency-Key': key } });
        expect(replay.status).toBe(200);
        expect(replay.json.meta.replayed).toBe(true);
        expect(replay.json.data.id).toBe(s.id);
        expect(F.dbInt(`SELECT COUNT(*) FROM fornitori WHERE nome=${F.sqlStr(body.name)}`)).toBe(1);
        const conflict = await F.api(request, 'POST', '/staff/suppliers', { token: ctx.staff, body: { ...body, notes: 'other' }, extraHeaders: { 'Idempotency-Key': key } });
        expect(conflict.status).toBe(422);
        expect(conflict.json.error.code).toBe('idempotency_conflict');
        // A second supplier for the delete test.
        const second = await F.api(request, 'POST', '/staff/suppliers', { token: ctx.staff, body: { name: `Fornitore ${RUN} B` } });
        expect(second.status).toBe(201);
        ctx.suppliers.push(second.json.data.id);
        ctx.supplierB = second.json.data.id;
    });

    test('list, detail, update (absent keys kept, null clears), 404', async ({ request }) => {
        const list = await F.api(request, 'GET', `/staff/suppliers?q=${encodeURIComponent(RUN)}`, { token: ctx.staff });
        expect(list.status).toBe(200);
        expect(list.json.data.map((x) => x.id).sort()).toEqual([ctx.supplierId, ctx.supplierB].sort());
        expect(list.json.meta.can_create).toBe(true);
        const detail = await F.api(request, 'GET', `/staff/suppliers/${ctx.supplierId}`, { token: ctx.staff });
        expect(detail.json.data.name).toBe(`Fornitore ${RUN}`);
        let r = await F.api(request, 'PUT', `/staff/suppliers/${ctx.supplierId}`, { token: ctx.staff, body: { phone: null, notes: 'Aggiornato' } });
        expect(r.status, r.text).toBe(200);
        expect(r.json.data).toMatchObject({ name: `Fornitore ${RUN}`, contact_person: 'Mario Rossi', phone: null, notes: 'Aggiornato' });
        expect(F.dbScalar(`SELECT CONCAT_WS('|', referente, IFNULL(telefono,'NULL'), note) FROM fornitori WHERE id=${ctx.supplierId}`)).toBe('Mario Rossi|NULL|Aggiornato');
        r = await F.api(request, 'PUT', `/staff/suppliers/${ctx.supplierId}`, { token: ctx.staff, body: { name: '  ', email: 'x@' } });
        expect(r.status).toBe(422);
        expect(Object.keys(r.json.meta.fields).sort()).toEqual(['email', 'name']);
        expect((await F.api(request, 'GET', '/staff/suppliers/999999999', { token: ctx.staff })).status).toBe(404);
        expect((await F.api(request, 'PUT', '/staff/suppliers/999999999', { token: ctx.staff, body: { name: 'x' } })).status).toBe(404);
    });
});

test.describe('books: supplier and RDA fields', () => {
    test('lookups carry the supplier and RDA lists while the modules are on', async ({ request }) => {
        const r = await F.api(request, 'GET', '/staff/lookups', { token: ctx.staff });
        expect(r.status).toBe(200);
        expect(r.json.data.suppliers.some((s) => s.id === ctx.supplierId)).toBe(true);
        expect(r.json.data.rda_content_types.map((o) => o.value)).toContain('spw');
        expect(r.json.data.rda_media_types.map((o) => o.value)).toContain('s');
        expect(r.json.data.rda_carrier_types.map((o) => o.value)).toContain('nc');
    });

    test('create and update store supplier and RDA overrides; bad values are 422', async ({ request }) => {
        let r = await F.api(request, 'POST', '/staff/books', { token: ctx.staff, body: { title: `Cat book ${RUN}`, supplier_id: 999999999 } });
        expect(r.status).toBe(422);
        expect(r.json.meta.field).toBe('supplier_id');
        r = await F.api(request, 'POST', '/staff/books', { token: ctx.staff, body: { title: `Cat book ${RUN}`, rda_media_type: 'zz' } });
        expect(r.status).toBe(422);
        expect(r.json.meta.field).toBe('rda_media_type');

        r = await F.api(request, 'POST', '/staff/books', { token: ctx.staff, body: { title: `Cat book ${RUN}`, supplier_id: ctx.supplierId, rda_content_type: 'spw', rda_carrier_type: 'cd' } });
        expect(r.status, r.text).toBe(201);
        const book = r.json.data;
        ctx.books.push(book.id);
        ctx.bookId = book.id;
        expect(book.supplier_id).toBe(ctx.supplierId);
        expect(book.supplier).toEqual({ id: ctx.supplierId, name: `Fornitore ${RUN}` });
        expect([book.rda_content_type, book.rda_media_type, book.rda_carrier_type]).toEqual(['spw', null, 'cd']);
        // media and nothing else derived from tipo_media (libro → n)
        expect(book.rda_effective).toEqual({ content_type: 'spw', media_type: 'n', carrier_type: 'cd' });
        expect(F.dbScalar(`SELECT CONCAT_WS('|', fornitore_id, rda_content_type, IFNULL(rda_media_type,'NULL'), rda_carrier_type) FROM libri WHERE id=${book.id}`)).toBe(`${ctx.supplierId}|spw|NULL|cd`);

        // An update without the keys keeps them; null clears; a new supplier replaces.
        r = await F.api(request, 'PUT', `/staff/books/${book.id}`, { token: ctx.staff, body: { subtitle: 'Sottotitolo' } });
        expect(r.status, r.text).toBe(200);
        expect([r.json.data.supplier_id, r.json.data.rda_content_type]).toEqual([ctx.supplierId, 'spw']);
        r = await F.api(request, 'PUT', `/staff/books/${book.id}`, { token: ctx.staff, body: { supplier_id: ctx.supplierB, rda_content_type: null } });
        expect(r.status, r.text).toBe(200);
        expect(r.json.data.supplier_id).toBe(ctx.supplierB);
        expect(r.json.data.rda_content_type).toBeNull();
        expect(r.json.data.rda_effective.content_type).toBe('txt');
        expect(F.dbScalar(`SELECT CONCAT_WS('|', fornitore_id, IFNULL(rda_content_type,'NULL')) FROM libri WHERE id=${book.id}`)).toBe(`${ctx.supplierB}|NULL`);
        r = await F.api(request, 'PUT', `/staff/books/${book.id}`, { token: ctx.staff, body: { supplier_id: ctx.supplierId } });
        expect(r.json.data.supplier_id).toBe(ctx.supplierId);
    });

    test('the staff book list filters by supplier', async ({ request }) => {
        const r = await F.api(request, 'GET', `/staff/books?supplier_id=${ctx.supplierId}&limit=50`, { token: ctx.staff });
        expect(r.status).toBe(200);
        const ids = r.json.data.map((b) => b.id);
        expect(ids).toContain(ctx.bookId);
        expect(ids.length).toBe(F.dbInt(`SELECT COUNT(*) FROM libri WHERE fornitore_id=${ctx.supplierId} AND deleted_at IS NULL`));
    });

    test('modules off: the keys are ignored on write and absent on read', async ({ request }) => {
        await FT.writeFeatures({ suppliers: '0', rda_types: '0' });
        try {
            const r = await F.api(request, 'POST', '/staff/books', { token: ctx.staff, body: { title: `Cat book off ${RUN}`, supplier_id: 999999999, rda_content_type: 'zz' } });
            expect(r.status, r.text).toBe(201);
            ctx.books.push(r.json.data.id);
            expect('supplier_id' in r.json.data).toBe(false);
            expect('rda_content_type' in r.json.data).toBe(false);
            expect(F.dbScalar(`SELECT CONCAT_WS('|', IFNULL(fornitore_id,'NULL'), IFNULL(rda_content_type,'NULL')) FROM libri WHERE id=${r.json.data.id}`)).toBe('NULL|NULL');
            // An update while off leaves the stored values alone.
            const u = await F.api(request, 'PUT', `/staff/books/${ctx.bookId}`, { token: ctx.staff, body: { supplier_id: null, rda_carrier_type: null } });
            expect(u.status).toBe(200);
            expect(F.dbScalar(`SELECT CONCAT_WS('|', fornitore_id, rda_carrier_type) FROM libri WHERE id=${ctx.bookId}`)).toBe(`${ctx.supplierId}|cd`);
            const lookups = (await F.api(request, 'GET', '/staff/lookups', { token: ctx.staff })).json.data;
            expect('suppliers' in lookups).toBe(false);
            expect('rda_content_types' in lookups).toBe(false);
            const off = await F.api(request, 'GET', '/staff/suppliers', { token: ctx.staff });
            expect(off.status).toBe(404);
            expect(off.json.error.code).toBe('feature_disabled');
            expect((await F.api(request, 'GET', '/health')).json.data.features.staff_suppliers).toBe(false);
        } finally {
            await FT.writeFeatures({ suppliers: '1', rda_types: '1' });
        }
    });
});

test.describe('catalogue cards', () => {
    test('one book and a selection as PDF attachments; 404 / 422', async ({ request }) => {
        let d = await download(request, ctx.staff, `/staff/books/${ctx.bookId}/catalogue-card.pdf`);
        expect(d.status).toBe(200);
        expect(d.headers['content-type']).toBe('application/pdf');
        expect(d.headers['content-disposition']).toBe(`attachment; filename="scheda_${ctx.bookId}.pdf"`);
        expect(d.body.subarray(0, 5).toString()).toBe('%PDF-');
        d = await download(request, ctx.staff, `/staff/books/catalogue-cards.pdf?ids=${ctx.bookId},${ctx.books[1]},999999999`);
        expect(d.status).toBe(200);
        expect(d.headers['content-disposition']).toBe('attachment; filename="schede_catalografiche.pdf"');
        expect(d.body.subarray(0, 5).toString()).toBe('%PDF-');
        expect(d.body.length).toBeGreaterThan(500);
        let r = await F.api(request, 'GET', '/staff/books/999999999/catalogue-card.pdf', { token: ctx.staff });
        expect([r.status, r.json.error.code]).toEqual([404, 'not_found']);
        r = await F.api(request, 'GET', '/staff/books/catalogue-cards.pdf?ids=x,0', { token: ctx.staff });
        expect([r.status, r.json.error.code]).toEqual([422, 'validation']);
        r = await F.api(request, 'GET', '/staff/books/catalogue-cards.pdf?ids=999999998,999999999', { token: ctx.staff });
        expect(r.status).toBe(404);
    });

    test('module off → 404 feature_disabled', async ({ request }) => {
        await FT.writeFeatures({ catalogue_cards: '0' });
        try {
            const r = await F.api(request, 'GET', `/staff/books/${ctx.bookId}/catalogue-card.pdf`, { token: ctx.staff });
            expect([r.status, r.json.error.code]).toEqual([404, 'feature_disabled']);
        } finally {
            await FT.writeFeatures({ catalogue_cards: '1' });
        }
    });
});

test.describe('MARC import', () => {
    test('options', async ({ request }) => {
        const r = await F.api(request, 'GET', '/staff/marc/options', { token: ctx.staff });
        expect(r.status).toBe(200);
        expect(r.json.data.flavours.map((o) => o.value)).toEqual(['auto', 'marc21', 'unimarc', 'danmarc2']);
        expect(r.json.data.duplicate_modes.map((o) => o.value)).toEqual(['skip', 'fill', 'overwrite']);
        expect(r.json.data.export_formats.map((o) => o.value)).toEqual(['marc21', 'marcxml', 'unimarc']);
        expect(r.json.data.suppliers.some((s) => s.id === ctx.supplierId)).toBe(true);
        expect(r.json.data.copy_cataloging).toEqual({ available: true, reason: null });
    });

    test('upload validation', async ({ request }) => {
        let r = await F.api(request, 'POST', '/staff/marc/imports', { token: ctx.staff, multipart: { duplicates: 'skip' } });
        expect([r.status, r.json.error.code, r.json.meta.field]).toEqual([422, 'validation', 'file']);
        r = await F.api(request, 'POST', '/staff/marc/imports', { token: ctx.staff, multipart: { file: { name: 'books.csv', mimeType: 'text/csv', buffer: Buffer.from('a;b\n') } } });
        expect([r.status, r.json.meta.field]).toEqual([422, 'file']);
        r = await F.api(request, 'POST', '/staff/marc/imports', { token: ctx.staff, multipart: { file: { name: 'books.mrc', mimeType: 'application/octet-stream', buffer: Buffer.from('this is not MARC at all') } } });
        expect([r.status, r.json.meta.field]).toEqual([422, 'file']);
        const file = marcFile(RUN, 3, 'mrc');
        ctx.files.push(file);
        ctx.mrc = file;
        r = await marcUpload(request, ctx.staff, file, { flavour: 'marc99', duplicates: 'never', supplier_id: '999999999' });
        expect(r.status).toBe(422);
        expect(Object.keys(r.json.meta.fields).sort()).toEqual(['duplicates', 'flavour', 'supplier_id']);
    });

    test('upload → preview → chunks in order → done, with the supplier of the batch', async ({ request }) => {
        const up = await marcUpload(request, ctx.staff, ctx.mrc, { duplicates: 'skip', supplier_id: String(ctx.supplierB) });
        expect(up.status, up.text).toBe(201);
        const job = up.json.data;
        expect(job).toMatchObject({ kind: 'marc', status: 'ready', total_rows: 3, processed: 0, chunk_size: 10, duplicates: 'skip', supplier_id: ctx.supplierB, container: 'iso2709', unreadable: 0 });
        expect(job.preview.map((p) => p.title)).toEqual([1, 2, 3].map((n) => `Libro MARC ${RUN} 0${n}`));
        expect(job.preview[0]).toMatchObject({ position: 1, flavour: 'marc21', existing_id: null, error: null });
        expect(job.preview[0].isbn).toMatch(/^97[89]\d{10}$/);
        // The detail shows the same job.
        expect((await F.api(request, 'GET', `/staff/marc/imports/${job.id}`, { token: ctx.staff })).json.data.preview.length).toBe(3);

        let r = await F.api(request, 'POST', `/staff/marc/imports/${job.id}/chunk`, { token: ctx.staff, body: { start: 1 } });
        expect([r.status, r.json.error.code, r.json.meta.processed]).toEqual([409, 'out_of_order', 0]);
        r = await F.api(request, 'POST', `/staff/marc/imports/${job.id}/chunk`, { token: ctx.staff, body: { start: '0' } });
        expect([r.status, r.json.meta.field]).toEqual([422, 'start']);
        r = await F.api(request, 'POST', `/staff/marc/imports/${job.id}/chunk`, { token: ctx.staff, body: { start: 0, size: 2 } });
        expect(r.status, r.text).toBe(200);
        expect(r.json.data).toMatchObject({ status: 'ready', processed: 2, imported: 2, complete: false });
        r = await F.api(request, 'POST', `/staff/marc/imports/${job.id}/chunk`, { token: ctx.staff, body: { start: 2 } });
        expect(r.status, r.text).toBe(200);
        expect(r.json.data).toMatchObject({ status: 'completed', processed: 3, imported: 3, skipped: 0, failed: 0, complete: true });
        const importId = r.json.data.import_id;
        expect(importId).toMatch(/^[a-f0-9]+$/);
        r = await F.api(request, 'POST', `/staff/marc/imports/${job.id}/chunk`, { token: ctx.staff, body: {} });
        expect([r.status, r.json.error.code, r.json.meta.status]).toEqual([410, 'gone', 'completed']);

        expect(F.dbInt(`SELECT COUNT(*) FROM libri WHERE titolo LIKE 'Libro MARC ${RUN} %' AND deleted_at IS NULL AND fornitore_id=${ctx.supplierB}`)).toBe(3);
        expect(F.dbScalar(`SELECT CONCAT(import_type,':',imported,':',user_id) FROM import_logs WHERE import_id=${F.sqlStr(importId)}`)).toBe(`marc:3:${ctx.staffId}`);
        // The staged records are gone with the finished import.
        const state = JSON.parse(F.dbScalar(`SELECT state FROM mobile_import_jobs WHERE id='${job.id}'`));
        expect(fs.existsSync(state.file_path)).toBe(false);
        ctx.importedIds = bookIds().split(',').map(Number);
        expect(ctx.importedIds.length).toBe(3);
    });

    test('duplicates: skip keeps, fill completes, overwrite replaces (and takes the supplier)', async ({ request }) => {
        const run = async (mode, extra = {}) => {
            const up = await marcUpload(request, ctx.staff, ctx.mrc, { duplicates: mode, ...extra });
            expect(up.status, up.text).toBe(201);
            expect(up.json.data.preview.every((p) => ctx.importedIds.includes(p.existing_id))).toBe(true);
            const r = await F.api(request, 'POST', `/staff/marc/imports/${up.json.data.id}/chunk`, { token: ctx.staff, body: { start: 0 } });
            expect(r.status, r.text).toBe(200);
            return r.json.data;
        };
        let done = await run('skip');
        expect([done.imported, done.updated, done.skipped]).toEqual([0, 0, 3]);
        // fill: a field emptied on one book is filled again from the record.
        F.dbExec(`UPDATE libri SET luogo_pubblicazione=NULL WHERE id=${ctx.importedIds[0]}`);
        done = await run('fill', { supplier_id: String(ctx.supplierId) });
        expect([done.imported, done.skipped]).toEqual([0, 0]);
        expect(done.updated).toBe(3);
        expect(F.dbScalar(`SELECT IFNULL(luogo_pubblicazione,'NULL') FROM libri WHERE id=${ctx.importedIds[0]}`)).not.toBe('NULL');
        // fill never changes the supplier
        expect(F.dbInt(`SELECT COUNT(*) FROM libri WHERE id IN (${ctx.importedIds.join(',')}) AND fornitore_id=${ctx.supplierB}`)).toBe(3);
        F.dbExec(`UPDATE libri SET sottotitolo='cambiato' WHERE id=${ctx.importedIds[1]}`);
        done = await run('overwrite', { supplier_id: String(ctx.supplierId) });
        expect([done.imported, done.updated, done.skipped]).toEqual([0, 3, 0]);
        expect(F.dbScalar(`SELECT sottotitolo FROM libri WHERE id=${ctx.importedIds[1]}`)).toBe('romanzo di prova');
        expect(F.dbInt(`SELECT COUNT(*) FROM libri WHERE id IN (${ctx.importedIds.join(',')}) AND fornitore_id=${ctx.supplierId}`)).toBe(3);
        expect(F.dbInt(`SELECT COUNT(*) FROM libri WHERE titolo LIKE 'Libro MARC ${RUN} %' AND deleted_at IS NULL`)).toBe(3);
    });

    test('jobs are private: another staff gets 404, an admin sees them; cancel drops the staged records', async ({ request }) => {
        const up = await marcUpload(request, ctx.staff, ctx.mrc, { duplicates: 'skip' });
        expect(up.status).toBe(201);
        const id = up.json.data.id;
        const staged = JSON.parse(F.dbScalar(`SELECT state FROM mobile_import_jobs WHERE id='${id}'`)).file_path;
        expect(fs.existsSync(staged)).toBe(true);
        for (const [method, path] of [['GET', `/staff/marc/imports/${id}`], ['POST', `/staff/marc/imports/${id}/chunk`], ['DELETE', `/staff/marc/imports/${id}`]]) {
            const r = await F.api(request, method, path, { token: ctx.other, body: method === 'POST' ? {} : undefined });
            expect([r.status, r.json.error.code], `${method} ${path}`).toEqual([404, 'not_found']);
        }
        expect((await F.api(request, 'GET', `/staff/marc/imports/${id}`, { token: ctx.admin })).status).toBe(200);
        // A CSV job id is not a MARC job.
        const csv = await F.api(request, 'POST', '/staff/imports', { token: ctx.staff, multipart: { file: { name: 'x.csv', mimeType: 'text/csv', buffer: Buffer.from(`titolo;autori\nCsv ${RUN};A ${RUN}\n`) }, kind: 'csv' } });
        expect(csv.status).toBe(201);
        expect((await F.api(request, 'GET', `/staff/marc/imports/${csv.json.data.id}`, { token: ctx.staff })).status).toBe(404);
        await F.api(request, 'DELETE', `/staff/imports/${csv.json.data.id}`, { token: ctx.staff });

        let r = await F.api(request, 'DELETE', `/staff/marc/imports/${id}`, { token: ctx.staff });
        expect(r.status).toBe(200);
        expect(r.json.data).toEqual({ id, cancelled: true });
        expect(fs.existsSync(staged)).toBe(false);
        r = await F.api(request, 'DELETE', `/staff/marc/imports/${id}`, { token: ctx.staff });
        expect(r.status).toBe(200); // idempotent
        r = await F.api(request, 'POST', `/staff/marc/imports/${id}/chunk`, { token: ctx.staff, body: { start: 0 } });
        expect([r.status, r.json.meta.status]).toEqual([410, 'cancelled']);
        expect((await F.api(request, 'GET', `/staff/marc/imports/${id}`, { token: ctx.staff })).json.data.status).toBe('cancelled');
        expect((await F.api(request, 'GET', '/staff/marc/imports/00000000-0000-4000-8000-000000000000', { token: ctx.staff })).status).toBe(404);
    });

    test('MARCXML upload', async ({ request }) => {
        const file = marcFile(RUN, 2, 'xml', 4);
        ctx.files.push(file);
        const up = await marcUpload(request, ctx.staff, file);
        expect(up.status, up.text).toBe(201);
        expect(up.json.data).toMatchObject({ container: 'xml', total_rows: 2 });
        const r = await F.api(request, 'POST', `/staff/marc/imports/${up.json.data.id}/chunk`, { token: ctx.staff, body: { start: 0 } });
        expect(r.json.data).toMatchObject({ complete: true, imported: 2 });
    });
});

test.describe('MARC export', () => {
    test('MARC 21, UNIMARC and MARCXML of a selection; validation', async ({ request }) => {
        const ids = bookIds();
        const title = `Libro MARC ${RUN} 01`;
        for (const [format, type, ext] of [['marc21', 'application/marc', 'mrc'], ['unimarc', 'application/marc', 'mrc'], ['marcxml', 'application/marcxml+xml; charset=UTF-8', 'xml']]) {
            const d = await download(request, ctx.staff, `/staff/marc/export?format=${format}&ids=${ids}`);
            expect(d.status, format).toBe(200);
            expect(d.headers['content-type']).toBe(type);
            expect(d.headers['content-disposition']).toMatch(new RegExp(`^attachment; filename="catalogo_${format}_\\d{4}-\\d{2}-\\d{2}\\.${ext}"$`));
            const text = d.body.toString('utf8');
            expect(text).toContain(title);
            if (format === 'marcxml') {
                expect(text).toMatch(/^<\?xml[^>]*\?>\s*<collection\b/);
                expect((text.match(/<record\b/g) || []).length).toBe(ids.split(',').length);
            } else {
                // ISO 2709: a 24-byte leader (record length, status, type…), records end with 0x1D.
                expect(text.slice(0, 5)).toMatch(/^\d{5}$/);
                expect(Number(text.slice(0, 5))).toBeLessThanOrEqual(d.body.length);
                expect(text.slice(20, 24)).toBe('4500');
                expect(d.body.filter((b) => b === 0x1d).length).toBe(ids.split(',').length);
            }
        }
        let r = await F.api(request, 'GET', '/staff/marc/export?format=pdf', { token: ctx.staff });
        expect([r.status, r.json.error.code, r.json.meta.field]).toEqual([422, 'validation', 'format']);
        r = await F.api(request, 'GET', '/staff/marc/export?ids=abc', { token: ctx.staff });
        expect([r.status, r.json.meta.field]).toEqual([422, 'ids']);
        const many = Array.from({ length: 1001 }, (_, i) => i + 1).join(',');
        r = await F.api(request, 'GET', `/staff/marc/export?ids=${many}`, { token: ctx.staff });
        expect([r.status, r.json.meta.field]).toEqual([422, 'ids']);
    });
});

test.describe('copy cataloguing', () => {
    test('servers: this installation is offered', async ({ request }) => {
        const r = await F.api(request, 'GET', '/staff/copy-cataloging/servers', { token: ctx.staff });
        expect(r.status).toBe(200);
        const local = r.json.data.servers.find((s) => s.key === 'local');
        expect(local).toMatchObject({ kind: 'local' });
        expect(local.url).toMatch(/\/api\/sru$/);
        expect(r.json.data.servers.some((s) => s.key === 'preset:loc')).toBe(true);
        expect(r.json.data.indexes.map((i) => i.value)).toEqual(['title', 'author', 'isbn', 'issn', 'any']);
    });

    test('search validation', async ({ request }) => {
        let r = await F.api(request, 'POST', '/staff/copy-cataloging/search', { token: ctx.staff, body: { servers: ['local'], term: '  ' } });
        expect([r.status, r.json.error.code, r.json.meta.field]).toEqual([422, 'validation', 'term']);
        r = await F.api(request, 'POST', '/staff/copy-cataloging/search', { token: ctx.staff, body: { servers: ['nowhere'], index: 'colour', term: 'x', max: 99 } });
        expect(Object.keys(r.json.meta.fields).sort()).toEqual(['index', 'max', 'servers']);
    });

    test('search this installation, import, Idempotency-Key replay and conflict', async ({ request }) => {
        const r = await F.api(request, 'POST', '/staff/copy-cataloging/search', { token: ctx.staff, body: { servers: ['local'], index: 'title', term: `Libro MARC ${RUN}`, max: 10 } });
        expect(r.status, r.text).toBe(201);
        const search = r.json.data;
        expect(search.servers).toEqual([expect.objectContaining({ key: 'local', count: 5, error: null })]);
        expect(search.results.length).toBe(5);
        const first = search.results.find((x) => x.title === `Libro MARC ${RUN} 01`);
        expect(first.existing_id).toBe(ctx.importedIds[0]);
        expect(first.id).toMatch(/^[a-f0-9]{16}$/);
        expect(first.detail.some((l) => l.tag === '245')).toBe(true);

        // Already in the catalogue → skipped.
        let imp = await F.api(request, 'POST', '/staff/copy-cataloging/import', { token: ctx.staff, body: { search_id: search.search_id, ids: [first.id], duplicates: 'skip' } });
        expect(imp.status, imp.text).toBe(200);
        expect(imp.json.data).toMatchObject({ imported: 0, skipped: 1, failed: 0 });
        expect(imp.json.data.items[0]).toMatchObject({ id: first.id, action: 'skipped', book_id: ctx.importedIds[0] });

        // The source withdrawn, the record becomes a new book; the same key replays.
        F.dbExec(`UPDATE libri SET deleted_at=NOW(), isbn13=NULL, isbn10=NULL, ean=NULL WHERE id=${ctx.importedIds[0]}`);
        const key = `cc-${RUN}`;
        imp = await F.api(request, 'POST', '/staff/copy-cataloging/import', { token: ctx.staff, body: { search_id: search.search_id, ids: [first.id], duplicates: 'skip', covers: false }, extraHeaders: { 'Idempotency-Key': key } });
        expect(imp.status, imp.text).toBe(200);
        expect(imp.json.data).toMatchObject({ imported: 1, skipped: 0 });
        const newId = imp.json.data.items[0].book_id;
        expect(newId).toBeGreaterThan(0);
        expect(F.dbScalar(`SELECT titolo FROM libri WHERE id=${newId} AND deleted_at IS NULL`)).toBe(`Libro MARC ${RUN} 01`);
        expect(F.dbScalar(`SELECT import_type FROM import_logs WHERE import_id=${F.sqlStr(imp.json.data.import_id)}`)).toBe('marc');
        const replay = await F.api(request, 'POST', '/staff/copy-cataloging/import', { token: ctx.staff, body: { search_id: search.search_id, ids: [first.id], duplicates: 'skip', covers: false }, extraHeaders: { 'Idempotency-Key': key } });
        expect(replay.status).toBe(200);
        expect(replay.json.meta.replayed).toBe(true);
        expect(replay.json.data.items[0].book_id).toBe(newId);
        expect(F.dbInt(`SELECT COUNT(*) FROM libri WHERE titolo='Libro MARC ${RUN} 01' AND deleted_at IS NULL`)).toBe(1);
        const conflict = await F.api(request, 'POST', '/staff/copy-cataloging/import', { token: ctx.staff, body: { search_id: search.search_id, ids: [first.id], duplicates: 'overwrite' }, extraHeaders: { 'Idempotency-Key': key } });
        expect([conflict.status, conflict.json.error.code]).toEqual([422, 'idempotency_conflict']);

        // Validation, another operator's search, an unknown id.
        let bad = await F.api(request, 'POST', '/staff/copy-cataloging/import', { token: ctx.staff, body: { search_id: search.search_id, ids: [], duplicates: 'maybe' } });
        expect(Object.keys(bad.json.meta.fields).sort()).toEqual(['duplicates', 'ids']);
        bad = await F.api(request, 'POST', '/staff/copy-cataloging/import', { token: ctx.other, body: { search_id: search.search_id, ids: [first.id] } });
        expect([bad.status, bad.json.error.code]).toEqual([404, 'not_found']);
        bad = await F.api(request, 'POST', '/staff/copy-cataloging/import', { token: ctx.staff, body: { search_id: search.search_id, ids: ['0123456789abcdef'] } });
        expect(bad.status).toBe(200);
        expect(bad.json.data).toMatchObject({ imported: 0, failed: 1 });
        expect(bad.json.data.items[0].action).toBe('failed');
    });

    test('without the z39-server plugin: 404 copy_cataloging_unavailable, options say so', async ({ browser, request }) => {
        test.setTimeout(90000);
        ctx.z39Deactivated = true;
        await setZ39Active(browser, false);
        try {
            for (const [method, route] of [['GET', '/staff/copy-cataloging/servers'], ['POST', '/staff/copy-cataloging/search'], ['POST', '/staff/copy-cataloging/import']]) {
                const body = method === 'GET' ? undefined : (route.endsWith('search') ? { servers: ['local'], term: 'x' } : { search_id: '00000000-0000-4000-8000-000000000000', ids: ['0123456789abcdef'] });
                const r = await F.api(request, method, route, { token: ctx.staff, body });
                expect([r.status, r.json.error.code], route).toEqual([404, 'copy_cataloging_unavailable']);
            }
            const o = await F.api(request, 'GET', '/staff/marc/options', { token: ctx.staff });
            expect(o.json.data.copy_cataloging).toEqual({ available: false, reason: 'plugin_inactive' });
        } finally {
            await setZ39Active(browser, true);
            ctx.z39Deactivated = false;
        }
    });

    test('module marc off → 404 feature_disabled on the whole area', async ({ request }) => {
        await FT.writeFeatures({ marc: '0' });
        try {
            for (const [method, route] of [['GET', '/staff/marc/options'], ['GET', '/staff/marc/export'], ['GET', '/staff/copy-cataloging/servers']]) {
                const r = await F.api(request, method, route, { token: ctx.staff });
                expect([r.status, r.json.error.code], route).toEqual([404, 'feature_disabled']);
            }
            expect((await F.api(request, 'GET', '/health')).json.data.features.staff_marc).toBe(false);
        } finally {
            await FT.writeFeatures({ marc: '1' });
        }
    });
});

test.describe('supplier delete', () => {
    test('deleting a supplier unlinks its books', async ({ request }) => {
        const linked = F.dbInt(`SELECT COUNT(*) FROM libri WHERE fornitore_id=${ctx.supplierId} AND deleted_at IS NULL`);
        expect(linked).toBeGreaterThan(0);
        const detail = await F.api(request, 'GET', `/staff/suppliers/${ctx.supplierId}`, { token: ctx.staff });
        expect(detail.json.data.book_count).toBe(linked);
        let r = await F.api(request, 'DELETE', `/staff/suppliers/${ctx.supplierId}`, { token: ctx.staff });
        expect(r.status).toBe(200);
        expect(r.json.data).toEqual({ id: ctx.supplierId, deleted: true, books_unlinked: linked });
        expect(F.dbInt(`SELECT COUNT(*) FROM libri WHERE fornitore_id=${ctx.supplierId}`)).toBe(0);
        expect(F.dbInt(`SELECT COUNT(*) FROM fornitori WHERE id=${ctx.supplierId}`)).toBe(0);
        const book = await F.api(request, 'GET', `/staff/books/${ctx.bookId}`, { token: ctx.staff });
        expect([book.json.data.supplier_id, book.json.data.supplier]).toEqual([null, null]);
        r = await F.api(request, 'DELETE', `/staff/suppliers/${ctx.supplierId}`, { token: ctx.staff });
        expect(r.status).toBe(404);
    });
});

// @ts-check
// Mobile API 1.7.0 — staff circulation desk (/api/v1/staff/loans, reservations,
// users, copies/by-code, books/{id}/queue|availability, circulation/summary).
//
// What this proves, end to end against the real Apache instance:
//   - the role guard (403 for a borrower on every route, 401 without a token)
//     and the catalogue-mode guard (404 feature_disabled on every route);
//   - every write goes through the web controllers: a loan created from the
//     app is a real `diretto` loan with the copy handed over, an activity-log
//     row attributed to the operator, and its own id resolved even without
//     the PDF redirect; two back-to-back creates for different borrowers on
//     the same title each answer with their own loan;
//   - the full desk lifecycle of a borrower request: pending → approve →
//     ready for pickup → pickup → renew → return as damaged with a penalty;
//   - the error mapping (not_available, not_eligible, loan_closed, 404 by
//     code, duplicate reservation) and the `actions` hints per state;
//   - partial PUTs merge over the stored row (change one date, the borrower
//     and the other date stay).
//
// Run: /tmp/run-e2e.sh tests/mobile-api-circulation.spec.js --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
const F = require('./helpers/mobile-api-fixtures');

test.describe.configure({ mode: 'serial' });
test.skip(!F.ENV_READY, 'Missing E2E env: DB_* and ADMIN_EMAIL/ADMIN_PASS');

const RUN = `${Date.now()}_${process.pid}`;
const PASS = 'Circ1234Test!';

/** @type {Record<string, any>} */
const ctx = { books: [], users: [] };

function isoDay(offset) {
    // Library clock: the server reports "today" in /summary; derive from it.
    const d = new Date(`${ctx.today}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + offset);
    return d.toISOString().slice(0, 10);
}

async function staffBook(request, title, copies) {
    const r = await F.api(request, 'POST', '/staff/books', { token: ctx.staff, body: { title: `${title} ${RUN}`, copies } });
    expect(r.status, r.text.slice(0, 300)).toBe(201);
    ctx.books.push(r.json.data.id);
    return r.json.data.id;
}

function copyCodes(bookId) {
    return F.dbRows(`SELECT id, numero_inventario FROM copie WHERE libro_id=${bookId} ORDER BY id`);
}

test.beforeAll(async ({ browser, request }) => {
    const page = await browser.newPage();
    ctx.gate = await F.ensureMobileApiReady(page);
    await page.close();

    ctx.staffEmail = `circ_op_${RUN}@pinakes.test`;
    ctx.staffId = F.createUser(ctx.staffEmail, PASS, 'staff', 'CIRO');
    ctx.users.push(ctx.staffId);
    ctx.staff = await F.login(request, ctx.staffEmail, PASS, 'circ-op');

    for (const key of ['a', 'b', 'c', 'd']) {
        const email = `circ_${key}_${RUN}@pinakes.test`;
        const id = F.createUser(email, PASS, 'standard', `CIR${key.toUpperCase()}`);
        ctx.users.push(id);
        ctx[`user_${key}`] = id;
        ctx[`email_${key}`] = email;
    }
    ctx.borrowerA = await F.login(request, ctx.email_a, PASS, 'circ-a');

    const summary = await F.api(request, 'GET', '/staff/circulation/summary', { token: ctx.staff });
    expect(summary.status).toBe(200);
    ctx.today = summary.json.data.today;

    ctx.bookMain = await staffBook(request, 'Circ main', 3);
    ctx.bookQueue = await staffBook(request, 'Circ queue', 1);
    ctx.bookRequest = await staffBook(request, 'Circ request', 1);
});

test.afterAll(async () => {
    F.deleteBooks(ctx.books);
    const stray = F.dbScalar(`SELECT GROUP_CONCAT(id) FROM libri WHERE titolo LIKE '%${RUN}%'`);
    if (stray && stray !== 'NULL') F.deleteBooks(stray.split(',').map(Number));
    for (const id of ctx.books) {
        try { F.dbExec(`DELETE FROM log_modifiche WHERE tabella='libri' AND record_id=${id}`); } catch {}
    }
    try { F.dbExec(`DELETE FROM admin_notifications WHERE message LIKE '%${RUN}%'`); } catch {}
    try { F.dbExec(`DELETE FROM mobile_idempotency_keys WHERE user_id=${ctx.staffId}`); } catch {}
    F.deleteUsers(ctx.users);
    F.restoreGate(ctx.gate);
});

// Every circulation route, with a valid-looking id where one is needed.
const ROUTES = [
    ['GET', '/staff/circulation/summary'], ['GET', '/staff/loans'], ['POST', '/staff/loans'],
    ['GET', '/staff/loans/1'], ['PUT', '/staff/loans/1'], ['POST', '/staff/loans/1/approve'],
    ['POST', '/staff/loans/1/reject'], ['POST', '/staff/loans/1/pickup'], ['POST', '/staff/loans/1/cancel-pickup'],
    ['POST', '/staff/loans/1/return'], ['POST', '/staff/loans/return-by-code'], ['POST', '/staff/loans/1/renew'],
    ['POST', '/staff/loans/bulk-extend'], ['POST', '/staff/loans/1/recall'], ['POST', '/staff/loans/bulk-recall'],
    ['GET', '/staff/loans/1/receipt.pdf'], ['POST', '/staff/loans/1/receipt/email'],
    ['GET', '/staff/reservations'], ['GET', '/staff/reservations/1'], ['POST', '/staff/reservations'],
    ['PUT', '/staff/reservations/1'], ['POST', '/staff/reservations/1/cancel'],
    ['GET', '/staff/books/1/queue'], ['GET', '/staff/books/1/availability'],
    ['GET', '/staff/users?q=ab'], ['GET', '/staff/users/1'], ['GET', '/staff/copies/by-code?code=x'],
];

test.describe('Guards', () => {
    test('a borrower gets 403 on every circulation route; no token → 401', async ({ request }) => {
        const loansBefore = F.dbInt('SELECT COUNT(*) FROM prestiti');
        for (const [method, route] of ROUTES) {
            const r = await F.api(request, method, route, { token: ctx.borrowerA, body: method === 'GET' ? undefined : { user_id: ctx.user_a, book_id: ctx.bookMain, ids: [1], days: 3, code: 'x' } });
            expect(r.status, `${method} ${route}`).toBe(403);
            expect(r.json?.error?.code).toBe('forbidden');
            const anon = await F.api(request, method, route, { body: method === 'GET' ? undefined : {} });
            expect(anon.status, `anon ${method} ${route}`).toBe(401);
        }
        expect(F.dbInt('SELECT COUNT(*) FROM prestiti')).toBe(loansBefore);
    });

    test('health advertises the 1.7.0 staff flags', async ({ request }) => {
        const r = await F.api(request, 'GET', '/health');
        const f = r.json.data.features;
        for (const flag of ['staff_circulation', 'staff_copies', 'staff_series', 'staff_merges', 'staff_labels', 'staff_imports',
            'staff_covers', 'staff_desiderata', 'staff_archives', 'staff_periodicals', 'staff_bookclub', 'staff_frbr',
            'staff_digital_library', 'staff_librarything']) {
            expect(typeof f[flag], flag).toBe('boolean');
        }
        expect(f.staff_circulation).toBe(true);
        // A flag is true only when this build registers the area's routes:
        // every true staff_* flag must have its paths in the OpenAPI document.
        const doc = (await F.api(request, 'GET', '/openapi.json')).json;
        const probes = { staff_circulation: '/staff/loans', staff_copies: '/staff/copies/{id}', staff_series: '/staff/series',
            staff_merges: '/staff/authors/merge', staff_labels: '/staff/settings/labels', staff_imports: '/staff/imports',
            staff_covers: '/staff/covers/missing', staff_desiderata: '/staff/desiderata/offers', staff_archives: '/staff/archives' };
        for (const [flag, path] of Object.entries(probes)) {
            if (f[flag]) expect(doc.paths[path], `${flag} is true but ${path} is not served`).toBeTruthy();
        }
    });
});

test.describe('Create, edit and return', () => {
    test('immediate loan: in_corso, copy handed over, operator in the activity log, id resolved', async ({ request }) => {
        const before = (await F.api(request, 'GET', '/staff/circulation/summary', { token: ctx.staff })).json.data;
        const r = await F.api(request, 'POST', '/staff/loans', {
            token: ctx.staff, body: { user_id: ctx.user_a, book_id: ctx.bookMain, immediate: true, notes: `Desk ${RUN}` },
        });
        expect(r.status, r.text.slice(0, 300)).toBe(201);
        const loan = r.json.data;
        ctx.loanA = loan.id;
        expect(loan.status).toBe('in_corso');
        expect(loan.origin).toBe('diretto');
        expect(loan.user.id).toBe(ctx.user_a);
        expect(loan.book.id).toBe(ctx.bookMain);
        expect(loan.copy).not.toBeNull();
        expect(loan.copy.status).toBe('prestato');
        expect(loan.processed_by?.id).toBe(ctx.staffId);
        expect(loan.notes).toBe(`Desk ${RUN}`);
        expect(loan.actions).toEqual(expect.arrayContaining(['return', 'renew', 'edit', 'receipt', 'email_receipt']));
        // The newest loan for this user+book in the DB is exactly the one answered.
        expect(F.dbInt(`SELECT MAX(id) FROM prestiti WHERE utente_id=${ctx.user_a} AND libro_id=${ctx.bookMain}`)).toBe(loan.id);
        expect(F.dbInt(`SELECT COUNT(*) FROM log_modifiche WHERE tabella='libri' AND record_id=${ctx.bookMain}
                         AND utente_id=${ctx.staffId} AND dati_nuovi LIKE '%loan.created%'`)).toBeGreaterThan(0);
        const after = (await F.api(request, 'GET', '/staff/circulation/summary', { token: ctx.staff })).json.data;
        expect(after.active).toBe(before.active + 1);
    });

    test('two back-to-back creates for different borrowers each answer with their own loan', async ({ request }) => {
        const [b, c] = await Promise.all([
            F.api(request, 'POST', '/staff/loans', { token: ctx.staff, body: { user_id: ctx.user_b, book_id: ctx.bookMain, immediate: true } }),
            F.api(request, 'POST', '/staff/loans', { token: ctx.staff, body: { user_id: ctx.user_c, book_id: ctx.bookMain, immediate: true } }),
        ]);
        expect(b.status, b.text.slice(0, 200)).toBe(201);
        expect(c.status, c.text.slice(0, 200)).toBe(201);
        expect(b.json.data.user.id).toBe(ctx.user_b);
        expect(c.json.data.user.id).toBe(ctx.user_c);
        expect(b.json.data.id).not.toBe(c.json.data.id);
        ctx.loanB = b.json.data.id;
        ctx.loanC = c.json.data.id;
    });

    test('a copy already out → 409 not_available; no copies left → 409 not_available', async ({ request }) => {
        const outCode = F.dbScalar(`SELECT c.numero_inventario FROM copie c JOIN prestiti p ON p.copia_id=c.id WHERE p.id=${ctx.loanA}`);
        const r = await F.api(request, 'POST', '/staff/loans', {
            token: ctx.staff, body: { user_id: ctx.user_d, book_id: ctx.bookMain, copy_code: outCode, immediate: true },
        });
        expect(r.status, r.text.slice(0, 200)).toBe(409);
        expect(r.json.error.code).toBe('not_available');
        expect(typeof r.json.meta.reason).toBe('string');
        const none = await F.api(request, 'POST', '/staff/loans', { token: ctx.staff, body: { user_id: ctx.user_d, book_id: ctx.bookMain, immediate: true } });
        expect(none.status).toBe(409);
        expect(none.json.error.code).toBe('not_available');
    });

    test('validation: missing user → 422, unknown book → 404, bad date → 422, copy of another title → 422', async ({ request }) => {
        let r = await F.api(request, 'POST', '/staff/loans', { token: ctx.staff, body: { book_id: ctx.bookMain } });
        expect(r.status).toBe(422);
        expect(r.json.meta.field).toBe('user_id');
        r = await F.api(request, 'POST', '/staff/loans', { token: ctx.staff, body: { user_id: ctx.user_d, book_id: 999999999 } });
        expect(r.status).toBe(404);
        expect(r.json.meta.field).toBe('book_id');
        r = await F.api(request, 'POST', '/staff/loans', { token: ctx.staff, body: { user_id: ctx.user_d, book_id: ctx.bookMain, due_at: '12/03/2026' } });
        expect(r.status).toBe(422);
        expect(r.json.meta.field).toBe('due_at');
        const otherCode = copyCodes(ctx.bookQueue)[0].numero_inventario;
        r = await F.api(request, 'POST', '/staff/loans', { token: ctx.staff, body: { user_id: ctx.user_d, book_id: ctx.bookRequest, copy_code: otherCode } });
        expect(r.status).toBe(422);
        expect(r.json.meta.field).toBe('copy_code');
    });

    test('a suspended borrower → 409 not_eligible with meta.reason', async ({ request }) => {
        F.dbExec(`UPDATE utenti SET stato='sospeso' WHERE id=${ctx.user_d}`);
        try {
            const r = await F.api(request, 'POST', '/staff/loans', { token: ctx.staff, body: { user_id: ctx.user_d, book_id: ctx.bookQueue } });
            expect(r.status, r.text.slice(0, 200)).toBe(409);
            expect(r.json.error.code).toBe('not_eligible');
            expect(r.json.meta.reason).toBe('user_suspended');
            const users = await F.api(request, 'GET', `/staff/users?q=${encodeURIComponent(ctx.email_d)}`, { token: ctx.staff });
            expect(users.status).toBe(200);
            expect(users.json.data[0].eligible).toBe(false);
            expect(users.json.data[0].eligibility_reason).toBe('user_suspended');
        } finally {
            F.dbExec(`UPDATE utenti SET stato='attivo' WHERE id=${ctx.user_d}`);
        }
    });

    test('a future loan is scheduled (prenotato) and only offers edit/receipt', async ({ request }) => {
        const r = await F.api(request, 'POST', '/staff/loans', {
            token: ctx.staff, body: { user_id: ctx.user_d, book_id: ctx.bookQueue, loaned_at: isoDay(40), due_at: isoDay(50) },
        });
        expect(r.status, r.text.slice(0, 300)).toBe(201);
        expect(r.json.data.status).toBe('prenotato');
        expect(r.json.data.actions).toEqual(['edit', 'receipt']);
        ctx.futureLoan = r.json.data.id;
        const list = await F.api(request, 'GET', `/staff/loans?bucket=scheduled&book_id=${ctx.bookQueue}`, { token: ctx.staff });
        expect(list.json.data.map((l) => l.id)).toContain(ctx.futureLoan);
    });

    test('PUT changes one date and keeps the borrower and the other date', async ({ request }) => {
        const before = (await F.api(request, 'GET', `/staff/loans/${ctx.futureLoan}`, { token: ctx.staff })).json.data;
        const r = await F.api(request, 'PUT', `/staff/loans/${ctx.futureLoan}`, { token: ctx.staff, body: { due_at: isoDay(55) } });
        expect(r.status, r.text.slice(0, 300)).toBe(200);
        expect(r.json.data.due_at).toBe(isoDay(55));
        expect(r.json.data.loaned_at).toBe(before.loaned_at);
        expect(r.json.data.user.id).toBe(ctx.user_d);
    });

    test('bulk extend turns an overdue loan back into in_corso', async ({ request }) => {
        F.dbExec(`UPDATE prestiti SET data_prestito=DATE_SUB('${ctx.today}', INTERVAL 20 DAY), data_scadenza=DATE_SUB('${ctx.today}', INTERVAL 3 DAY), stato='in_ritardo' WHERE id=${ctx.loanB}`);
        const overdue = await F.api(request, 'GET', `/staff/loans/${ctx.loanB}`, { token: ctx.staff });
        expect(overdue.json.data.status).toBe('in_ritardo');
        expect(overdue.json.data.days_overdue).toBe(3);
        expect(overdue.json.data.actions).toEqual(['return', 'edit', 'recall', 'receipt', 'email_receipt']);
        const recall = await F.api(request, 'POST', `/staff/loans/${ctx.loanB}/recall`, { token: ctx.staff });
        expect(recall.status).toBe(200);
        expect(typeof recall.json.data.sent).toBe('boolean');
        const r = await F.api(request, 'POST', '/staff/loans/bulk-extend', { token: ctx.staff, body: { ids: [ctx.loanB], days: 10 } });
        expect(r.status, r.text.slice(0, 200)).toBe(200);
        expect(r.json.data).toEqual({ extended: 1, days: 10 });
        const after = await F.api(request, 'GET', `/staff/loans/${ctx.loanB}`, { token: ctx.staff });
        expect(after.json.data.status).toBe('in_corso');
        expect(after.json.data.due_at).toBe(isoDay(10));
        const bad = await F.api(request, 'POST', '/staff/loans/bulk-extend', { token: ctx.staff, body: { ids: [ctx.loanB], days: 0 } });
        expect(bad.status).toBe(422);
    });

    test('bulk recall reports sent + skipped; receipt PDF and email', async ({ request }) => {
        const r = await F.api(request, 'POST', '/staff/loans/bulk-recall', { token: ctx.staff, body: { ids: [ctx.loanA, ctx.loanC] } });
        expect(r.status).toBe(200);
        expect(r.json.data.sent + r.json.data.skipped).toBe(2);
        const pdf = await F.api(request, 'GET', `/staff/loans/${ctx.loanA}/receipt.pdf`, { token: ctx.staff });
        expect(pdf.status).toBe(200);
        expect(pdf.headers['content-type']).toContain('application/pdf');
        expect(pdf.text.startsWith('%PDF-')).toBe(true);
        expect(pdf.headers['content-disposition']).toContain(`prestito_${ctx.loanA}_`);
        const mail = await F.api(request, 'POST', `/staff/loans/${ctx.loanA}/receipt/email`, { token: ctx.staff });
        expect(mail.status).toBe(200);
        expect(typeof mail.json.data.sent).toBe('boolean');
        expect((await F.api(request, 'GET', '/staff/loans/999999999/receipt.pdf', { token: ctx.staff })).status).toBe(404);
    });

    test('return by code: unknown → 404, then the loan is closed as returned', async ({ request }) => {
        const missing = await F.api(request, 'POST', '/staff/loans/return-by-code', { token: ctx.staff, body: { code: `NOPE-${RUN}` } });
        expect(missing.status).toBe(404);
        expect(missing.json.meta.field).toBe('code');
        const code = F.dbScalar(`SELECT c.numero_inventario FROM copie c JOIN prestiti p ON p.copia_id=c.id WHERE p.id=${ctx.loanC}`);
        const lookup = await F.api(request, 'GET', `/staff/copies/by-code?code=${encodeURIComponent(code)}`, { token: ctx.staff });
        expect(lookup.status).toBe(200);
        expect(lookup.json.data.found).toBe(true);
        expect(lookup.json.data.copy.available).toBe(false);
        expect(lookup.json.data.copy.loan.id).toBe(ctx.loanC);
        expect(lookup.json.data.copy.on_loan.loan_id).toBe(ctx.loanC);
        const r = await F.api(request, 'POST', '/staff/loans/return-by-code', { token: ctx.staff, body: { code } });
        expect(r.status, r.text.slice(0, 200)).toBe(200);
        expect(r.json.data.id).toBe(ctx.loanC);
        expect(r.json.data.status).toBe('restituito');
        expect(r.json.data.copy.status).toBe('disponibile');
        const again = await F.api(request, 'POST', '/staff/loans/return-by-code', { token: ctx.staff, body: { code } });
        expect(again.status).toBe(404);
        const nf = await F.api(request, 'GET', '/staff/copies/by-code?code=definitely-not-a-code', { token: ctx.staff });
        expect(nf.json.data).toEqual({ found: false, copy: null });
    });

    test('editing a closed loan → 409 loan_closed; returning it again → 409 not_returnable', async ({ request }) => {
        const r = await F.api(request, 'PUT', `/staff/loans/${ctx.loanC}`, { token: ctx.staff, body: { due_at: isoDay(30) } });
        expect(r.status).toBe(409);
        expect(r.json.error.code).toBe('loan_closed');
        const again = await F.api(request, 'POST', `/staff/loans/${ctx.loanC}/return`, { token: ctx.staff, body: {} });
        expect(again.status).toBe(409);
        expect(again.json.error.code).toBe('not_returnable');
        const bad = await F.api(request, 'POST', `/staff/loans/${ctx.loanA}/return`, { token: ctx.staff, body: { outcome: 'perso', penalty: -3 } });
        expect(bad.status).toBe(422);
        expect(bad.json.meta.field).toBe('penalty');
        expect((await F.api(request, 'GET', '/staff/loans/999999999', { token: ctx.staff })).status).toBe(404);
    });
});

test.describe('Borrower request lifecycle', () => {
    test('request → pending → approve → pickup → renew → return damaged with a penalty', async ({ request }) => {
        const req = await F.api(request, 'POST', '/reservations', { token: ctx.borrowerA, body: { book_id: ctx.bookRequest } });
        expect([200, 201], req.text.slice(0, 300)).toContain(req.status);
        const loanId = F.dbInt(`SELECT MAX(id) FROM prestiti WHERE utente_id=${ctx.user_a} AND libro_id=${ctx.bookRequest} AND stato='pendente'`);
        expect(loanId).toBeGreaterThan(0);

        const pending = await F.api(request, 'GET', '/staff/loans?bucket=pending', { token: ctx.staff });
        const row = pending.json.data.find((l) => l.id === loanId);
        expect(row).toBeTruthy();
        expect(row.actions).toEqual(['approve', 'reject', 'edit']);

        const approved = await F.api(request, 'POST', `/staff/loans/${loanId}/approve`, { token: ctx.staff });
        expect(approved.status, approved.text.slice(0, 300)).toBe(200);
        expect(['da_ritirare', 'prenotato']).toContain(approved.json.data.status);
        const twice = await F.api(request, 'POST', `/staff/loans/${loanId}/approve`, { token: ctx.staff });
        expect(twice.status).toBe(409);
        expect(twice.json.error.code).toBe('conflict');

        const picked = await F.api(request, 'POST', `/staff/loans/${loanId}/pickup`, { token: ctx.staff });
        expect(picked.status, picked.text.slice(0, 300)).toBe(200);
        expect(picked.json.data.status).toBe('in_corso');

        const renewed = await F.api(request, 'POST', `/staff/loans/${loanId}/renew`, { token: ctx.staff });
        expect(renewed.status, renewed.text.slice(0, 300)).toBe(200);
        expect(renewed.json.data.renewals).toBe(1);

        const returned = await F.api(request, 'POST', `/staff/loans/${loanId}/return`, {
            token: ctx.staff, body: { outcome: 'danneggiato', penalty: 12.5, notes: `Spine broken ${RUN}` },
        });
        expect(returned.status, returned.text.slice(0, 300)).toBe(200);
        expect(returned.json.data.status).toBe('danneggiato');
        expect(returned.json.data.penalty).toBe(12.5);
        expect(returned.json.data.actions).toEqual(['receipt']);
        expect(F.dbScalar(`SELECT CONCAT(attivo, '|', sanzione) FROM prestiti WHERE id=${loanId}`)).toBe('0|12.50');
        expect(F.dbScalar(`SELECT c.stato FROM copie c JOIN prestiti p ON p.copia_id=c.id WHERE p.id=${loanId}`)).toBe('danneggiato');
    });

    test('a pending request can be rejected with a reason', async ({ request }) => {
        // bookRequest's only copy is now damaged: a request on bookQueue instead.
        const loanId = F.dbInsert(`INSERT INTO prestiti (utente_id, libro_id, data_prestito, data_scadenza, stato, attivo, origine, created_at)
                                   VALUES (${ctx.user_b}, ${ctx.bookQueue}, '${ctx.today}', DATE_ADD('${ctx.today}', INTERVAL 7 DAY), 'pendente', 0, 'richiesta', NOW())`);
        const r = await F.api(request, 'POST', `/staff/loans/${loanId}/reject`, { token: ctx.staff, body: { reason: 'No copies this week' } });
        expect(r.status, r.text.slice(0, 300)).toBe(200);
        expect(r.json.data.status).toBe('annullato');
        expect(r.json.data.notes).toContain('No copies this week');
    });
});

test.describe('Reader request on a closed day', () => {
    // The library is closed today: the auto-approved request starts on the
    // next open day, so the app is told `prenotato` (with the start day), not
    // `da_ritirare` as every approved request used to be reported.
    test('with today closed and auto-approval on, POST /reservations answers status prenotato', async ({ request }) => {
        const TAG = `E2E-MOBCAL-${RUN}`;
        const prevAuto = F.dbScalar("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='auto_approve_requests'");
        const prevWeekdays = F.dbScalar("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='closed_weekdays'");
        const setSetting = (key, value) => {
            if (value === '' || value === null || value === undefined) {
                F.dbExec(`DELETE FROM system_settings WHERE category='loans' AND setting_key='${key}'`);
            } else {
                F.dbExec(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans','${key}',${F.sqlStr(value)}) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
            }
        };
        const email = `circ_closed_${RUN}@pinakes.test`;
        const userId = F.createUser(email, PASS, 'standard', 'CIRX');
        ctx.users.push(userId);
        const token = await F.login(request, email, PASS, 'circ-closed');
        const bookId = await staffBook(request, 'Circ closed today', 1);
        try {
            // Inside the try: a failed setup still restores both settings.
            setSetting('auto_approve_requests', '1');
            setSetting('closed_weekdays', '');
            F.dbExec(`INSERT INTO calendario_chiusure (data_inizio, data_fine, descrizione) VALUES ('${ctx.today}', '${ctx.today}', '${TAG}')`);
            const r = await F.api(request, 'POST', '/reservations', { token, body: { book_id: bookId } });
            expect(r.status, r.text.slice(0, 300)).toBe(201);
            expect(r.json.data.type).toBe('loan');
            expect(r.json.data.auto_approved).toBe(true);
            expect(r.json.data.status).toBe('prenotato');
            // The next open day: tomorrow, unless another closure follows.
            expect(r.json.data.start_date >= isoDay(1), r.json.data.start_date).toBe(true);
            expect(r.json.meta.message).not.toMatch(/attesa di ritiro/);
            const stored = F.dbScalar(`SELECT CONCAT(stato,'|',data_prestito) FROM prestiti WHERE id=${r.json.data.loan_id}`);
            expect(stored).toBe(`prenotato|${r.json.data.start_date}`);
        } finally {
            F.dbExec(`DELETE FROM calendario_chiusure WHERE descrizione='${TAG}'`);
            setSetting('auto_approve_requests', prevAuto);
            setSetting('closed_weekdays', prevWeekdays);
        }
    });
});

test.describe('Reservations and queue', () => {
    test('create → queue position, duplicate 409, cancel, reactivate; queue + availability', async ({ request }) => {
        const r = await F.api(request, 'POST', '/staff/reservations', {
            token: ctx.staff, body: { user_id: ctx.user_c, book_id: ctx.bookQueue, reserved_at: isoDay(60), expires_at: isoDay(70) },
        });
        expect(r.status, r.text.slice(0, 300)).toBe(201);
        const res = r.json.data;
        expect(res.status).toBe('attiva');
        expect(res.user.id).toBe(ctx.user_c);
        expect(res.queue_position).toBeGreaterThanOrEqual(1);
        expect(res.actions).toEqual(['edit', 'cancel']);
        ctx.reservation = res.id;

        const dup = await F.api(request, 'POST', '/staff/reservations', {
            token: ctx.staff, body: { user_id: ctx.user_c, book_id: ctx.bookQueue, reserved_at: isoDay(60), expires_at: isoDay(70) },
        });
        expect(dup.status).toBe(409);
        expect(dup.json.error.code).toBe('duplicate');

        const edited = await F.api(request, 'PUT', `/staff/reservations/${ctx.reservation}`, { token: ctx.staff, body: { expires_at: isoDay(72) } });
        expect(edited.status, edited.text.slice(0, 300)).toBe(200);
        expect(edited.json.data.expires_at).toBe(isoDay(72));
        expect(edited.json.data.reserved_at).toBe(isoDay(60));

        const queue = await F.api(request, 'GET', `/staff/books/${ctx.bookQueue}/queue`, { token: ctx.staff });
        expect(queue.status).toBe(200);
        expect(queue.json.data.reservations.map((x) => x.id)).toContain(ctx.reservation);
        expect(queue.json.data.loans.map((x) => x.id)).toContain(ctx.futureLoan);
        expect(queue.json.data.copies.length).toBe(1);

        const avail = await F.api(request, 'GET', `/staff/books/${ctx.bookQueue}/availability?user_id=${ctx.user_c}&days=90`, { token: ctx.staff });
        expect(avail.status).toBe(200);
        expect(avail.json.data.total_copies).toBe(1);
        expect(Array.isArray(avail.json.data.days)).toBe(true);

        const cancelled = await F.api(request, 'POST', `/staff/reservations/${ctx.reservation}/cancel`, { token: ctx.staff, body: { reason: 'Test' } });
        expect(cancelled.status, cancelled.text.slice(0, 300)).toBe(200);
        expect(cancelled.json.data.status).toBe('annullata');
        expect(cancelled.json.data.actions).toEqual(['reactivate']);
        const again = await F.api(request, 'POST', `/staff/reservations/${ctx.reservation}/cancel`, { token: ctx.staff });
        expect(again.status).toBe(409);

        const reactivated = await F.api(request, 'PUT', `/staff/reservations/${ctx.reservation}`, { token: ctx.staff, body: { status: 'attiva' } });
        expect(reactivated.status, reactivated.text.slice(0, 300)).toBe(200);
        expect(reactivated.json.data.status).toBe('attiva');

        const list = await F.api(request, 'GET', `/staff/reservations?book_id=${ctx.bookQueue}`, { token: ctx.staff });
        expect(list.json.data.map((x) => x.id)).toContain(ctx.reservation);
        expect((await F.api(request, 'GET', '/staff/reservations/999999999', { token: ctx.staff })).status).toBe(404);
    });

    test('user detail lists open loans and active reservations', async ({ request }) => {
        const r = await F.api(request, 'GET', `/staff/users/${ctx.user_c}`, { token: ctx.staff });
        expect(r.status).toBe(200);
        expect(r.json.data.eligible).toBe(true);
        expect(r.json.data.reservations.map((x) => x.id)).toContain(ctx.reservation);
        const short = await F.api(request, 'GET', '/staff/users?q=a', { token: ctx.staff });
        expect(short.status).toBe(422);
    });
});

test.describe('Idempotency-Key on creates', () => {
    test('a retry with the same key returns the original loan; a different payload → 422', async ({ request }) => {
        const bookId = await staffBook(request, 'Circ idem', 3);
        const key = `loan-${RUN}-a`;
        const body = { user_id: ctx.user_d, book_id: bookId, immediate: true };
        const first = await F.api(request, 'POST', '/staff/loans', { token: ctx.staff, body, extraHeaders: { 'Idempotency-Key': key } });
        expect(first.status, first.text.slice(0, 300)).toBe(201);
        const retry = await F.api(request, 'POST', '/staff/loans', { token: ctx.staff, body, extraHeaders: { 'Idempotency-Key': key } });
        expect(retry.status, retry.text.slice(0, 300)).toBe(200);
        expect(retry.json.meta.replayed).toBe(true);
        expect(retry.json.data.id).toBe(first.json.data.id);
        // Body key is accepted too and is the same key.
        const viaBody = await F.api(request, 'POST', '/staff/loans', { token: ctx.staff, body: { ...body, idempotency_key: key } });
        expect(viaBody.status).toBe(200);
        expect(viaBody.json.data.id).toBe(first.json.data.id);
        expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE libro_id=${bookId}`)).toBe(1);

        const other = await F.api(request, 'POST', '/staff/loans', {
            token: ctx.staff, body: { ...body, notes: 'different' }, extraHeaders: { 'Idempotency-Key': key },
        });
        expect(other.status).toBe(422);
        expect(other.json.error.code).toBe('idempotency_conflict');
        const bad = await F.api(request, 'POST', '/staff/loans', { token: ctx.staff, body, extraHeaders: { 'Idempotency-Key': 'has spaces!' } });
        expect(bad.status).toBe(422);
        expect(bad.json.meta.field).toBe('idempotency_key');
        expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE libro_id=${bookId}`)).toBe(1);
    });

    test('two concurrent creates with the same key make ONE loan; the other replays it', async ({ request }) => {
        const bookId = await staffBook(request, 'Circ idem race', 3);
        // Several loans of the same title for one borrower would be allowed
        // with allow_multiple_loans_same_book: the key alone must prevent it.
        const key = `loan-${RUN}-race`;
        const body = { user_id: ctx.user_b, book_id: bookId, immediate: true };
        const [a, b] = await Promise.all([
            F.api(request, 'POST', '/staff/loans', { token: ctx.staff, body, extraHeaders: { 'Idempotency-Key': key } }),
            F.api(request, 'POST', '/staff/loans', { token: ctx.staff, body, extraHeaders: { 'Idempotency-Key': key } }),
        ]);
        expect([a.status, b.status].sort(), `${a.text.slice(0, 150)} | ${b.text.slice(0, 150)}`).toEqual([200, 201]);
        expect(a.json.data.id).toBe(b.json.data.id);
        expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE libro_id=${bookId}`)).toBe(1);
    });

    test('concurrent creates WITHOUT a key on the same title each resolve their own loan', async ({ request }) => {
        const bookId = await staffBook(request, 'Circ race nokey', 3);
        const results = await Promise.all(['a', 'b', 'c'].map((k) => F.api(request, 'POST', '/staff/loans', {
            token: ctx.staff, body: { user_id: ctx[`user_${k}`], book_id: bookId, immediate: true },
        })));
        for (const [i, k] of ['a', 'b', 'c'].entries()) {
            expect(results[i].status, results[i].text.slice(0, 200)).toBe(201);
            expect(results[i].json.data.user.id).toBe(ctx[`user_${k}`]);
            expect(F.dbInt(`SELECT utente_id FROM prestiti WHERE id=${results[i].json.data.id}`)).toBe(ctx[`user_${k}`]);
        }
        expect(new Set(results.map((r) => r.json.data.id)).size).toBe(3);
    });

    test('reservations honour the key too', async ({ request }) => {
        const bookId = await staffBook(request, 'Circ idem resv', 1);
        const key = `resv-${RUN}`;
        const body = { user_id: ctx.user_a, book_id: bookId, reserved_at: isoDay(80), expires_at: isoDay(90) };
        const [a, b] = await Promise.all([
            F.api(request, 'POST', '/staff/reservations', { token: ctx.staff, body, extraHeaders: { 'Idempotency-Key': key } }),
            F.api(request, 'POST', '/staff/reservations', { token: ctx.staff, body, extraHeaders: { 'Idempotency-Key': key } }),
        ]);
        expect([a.status, b.status].sort(), `${a.text.slice(0, 150)} | ${b.text.slice(0, 150)}`).toEqual([200, 201]);
        expect(a.json.data.id).toBe(b.json.data.id);
        expect(F.dbInt(`SELECT COUNT(*) FROM prenotazioni WHERE libro_id=${bookId}`)).toBe(1);
        const conflict = await F.api(request, 'POST', '/staff/reservations', {
            token: ctx.staff, body: { ...body, expires_at: isoDay(95) }, extraHeaders: { 'Idempotency-Key': key },
        });
        expect(conflict.status).toBe(422);
        expect(conflict.json.error.code).toBe('idempotency_conflict');
    });
});

test.describe('Catalogue mode', () => {
    test('every circulation route answers 404 feature_disabled', async ({ browser, request }) => {
        await F.setCatalogueMode(browser, true);
        try {
            for (const [method, route] of ROUTES) {
                const r = await F.api(request, method, route, { token: ctx.staff, body: method === 'GET' ? undefined : {} });
                expect(r.status, `${method} ${route}`).toBe(404);
                expect(r.json?.error?.code, `${method} ${route}`).toBe('feature_disabled');
            }
            const health = await F.api(request, 'GET', '/health');
            expect(health.json.data.features.staff_circulation).toBe(false);
        } finally {
            await F.setCatalogueMode(browser, false);
        }
        expect((await F.api(request, 'GET', '/staff/circulation/summary', { token: ctx.staff })).status).toBe(200);
    });
});

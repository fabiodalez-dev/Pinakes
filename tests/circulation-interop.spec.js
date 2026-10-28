// @ts-check
/**
 * Circulation policy — the same patron-type rules on every channel:
 * the Mobile API (reader and staff routes) and the NCIP server refuse what
 * the website refuses, with stable codes.
 */
const { test, expect } = require('@playwright/test');
const F = require('./helpers/circulation-fixtures');
const M = require('./helpers/mobile-api-fixtures');

test.skip(!F.CONFIGURED, 'E2E credentials not configured');

const RUN = String(Date.now()).slice(-6);
const PREFIX = `e2eio${RUN}`;
const PASS = 'Circ1234!';
const NCIP_NS = 'http://www.niso.org/2008/ncip';

const ncipXml = (service, userId, itemId) => `<?xml version="1.0" encoding="UTF-8"?>
<NCIPMessage xmlns="${NCIP_NS}" ncip:version="http://www.niso.org/schemas/ncip/v2_02/ncip_v2_02.xsd" xmlns:ncip="${NCIP_NS}">
  <${service}>
    ${userId ? `<UserId><UserIdentifierValue>${userId}</UserIdentifierValue></UserId>` : ''}
    <ItemId><ItemIdentifierValue>${itemId}</ItemIdentifierValue></ItemId>
  </${service}>
</NCIPMessage>`;
const ncipAuth = 'Basic ' + Buffer.from(`${F.ADMIN_EMAIL}:${F.ADMIN_PASS}`).toString('base64');
const ncip = (request, body) => request.post(`${F.BASE}/ncip`, { data: body, headers: { 'Content-Type': 'application/xml', Authorization: ncipAuth } });

test.describe.serial('Patron-type rules on the Mobile API and NCIP', () => {
  const ctx = { users: [], titles: [] };
  let gate = null;

  test.beforeAll(async ({ browser, request }) => {
    const page = await browser.newPage();
    gate = await M.ensureMobileApiReady(page);
    // NCIP server active (same activation path as ncip-server.spec.js).
    const ncipId = F.dbInt("SELECT id FROM plugins WHERE name='ncip-server' LIMIT 1");
    if (ncipId > 0 && F.dbQuery(`SELECT is_active FROM plugins WHERE id=${ncipId}`) !== '1') {
      await F.loginAsAdmin(page);
      await page.goto(`${F.BASE}/admin/plugins`);
      await page.evaluate(async (pid) => {
        const fd = new FormData();
        fd.append('csrf_token', document.querySelector('input[name="csrf_token"]')?.getAttribute('value') || '');
        await fetch(`${window.location.origin}${window.BASE_PATH || ''}/admin/plugins/${pid}/activate`, { method: 'POST', body: fd });
      }, ncipId);
    }
    await F.loginAsAdmin(page);
    ctx.type = await F.createTypeViaUi(page, { nome: `Ragazzi ${RUN}`, codice: `${PREFIX}-kid`, max_prestiti: 1, max_rinnovi: 0 }, { reservations: false });
    await F.setMatrixCell(page, ctx.type, 'dvd', { loanable: 0 });
    await page.close();

    ctx.kid = F.createPatron(`IOK${RUN}`, { typeId: ctx.type, password: PASS });
    ctx.users.push(ctx.kid.id);
    ctx.staffEmail = `io_staff_${RUN}@circ.test`;
    ctx.staffId = M.createUser(ctx.staffEmail, PASS, 'staff', 'IOST');
    ctx.users.push(ctx.staffId);
    ctx.dvd = F.createTitle(`${RUN} IODVD`, 'dvd');
    ctx.book1 = F.createTitle(`${RUN} IOB1`, 'libro');
    ctx.book2 = F.createTitle(`${RUN} IOB2`, 'libro');
    ctx.busy = F.createTitle(`${RUN} IOBUSY`, 'libro');
    ctx.titles.push(ctx.dvd.id, ctx.book1.id, ctx.book2.id, ctx.busy.id);
    ctx.kidToken = await M.login(request, ctx.kid.email, PASS, `io-kid-${RUN}`);
    ctx.staffToken = await M.login(request, ctx.staffEmail, PASS, `io-staff-${RUN}`);
  });
  test.afterAll(() => {
    F.deleteTitles(ctx.titles);
    F.deletePatrons(ctx.users);
    F.deleteTypes(PREFIX);
    M.restoreGate(gate);
  });

  test('app availability tells the reader the DVD is consultation only and reservations are off', async ({ request }) => {
    const r = await M.api(request, 'GET', `/catalog/books/${ctx.dvd.id}/availability`, { token: ctx.kidToken });
    expect(r.status, r.text.slice(0, 200)).toBe(200);
    expect(r.json.data.loanable).toBe(false);
    expect(r.json.data.reservations_enabled).toBe(false);
    const b = await M.api(request, 'GET', `/catalog/books/${ctx.book1.id}/availability`, { token: ctx.kidToken });
    expect(b.json.data.loanable).toBe(true);
  });

  test('app request of the consultation-only DVD → 409 not_loanable', async ({ request }) => {
    const r = await M.api(request, 'POST', '/reservations', { token: ctx.kidToken, body: { book_id: ctx.dvd.id } });
    expect(r.status, r.text.slice(0, 300)).toBe(409);
    expect(r.json.error.code).toBe('not_loanable');
  });

  test('app staff loan of the DVD to the child → 409 not_loanable', async ({ request }) => {
    const r = await M.api(request, 'POST', '/staff/loans', { token: ctx.staffToken, body: { user_id: ctx.kid.id, book_id: ctx.dvd.id, immediate: true } });
    expect(r.status, r.text.slice(0, 300)).toBe(409);
    expect(r.json.error.code).toBe('not_loanable');
  });

  test('NCIP CheckOutItem of the DVD → Item Does Not Circulate', async ({ request }) => {
    const res = await ncip(request, ncipXml('CheckOutItem', ctx.kid.id, ctx.dvd.id));
    const body = await res.text();
    expect(body).toContain('Item Does Not Circulate');
    expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${ctx.kid.id}`)).toBe(0);
  });

  test('NCIP checkout of a book works, the second is over the type limit of 1', async ({ request }) => {
    const ok = await ncip(request, ncipXml('CheckOutItem', ctx.kid.id, ctx.book1.id));
    expect(await ok.text()).toContain('CheckOutItemResponse');
    expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${ctx.kid.id} AND attivo=1`)).toBe(1);
    const over = await ncip(request, ncipXml('CheckOutItem', ctx.kid.id, ctx.book2.id));
    expect(await over.text()).toContain('Maximum Check Outs Exceeded');
  });

  test('NCIP RenewItem honours the type renewals (0) → Maximum Renewals Exceeded', async ({ request }) => {
    const loanId = F.dbInt(`SELECT id FROM prestiti WHERE utente_id=${ctx.kid.id} AND libro_id=${ctx.book1.id} AND attivo=1`);
    F.dbQuery(`UPDATE prestiti SET stato='in_corso' WHERE id=${loanId}`);
    const res = await ncip(request, ncipXml('RenewItem', ctx.kid.id, ctx.book1.id));
    expect(await res.text()).toContain('Maximum Renewals Exceeded');
  });

  test('app request over the total limit → 409 max_loans_reached', async ({ request }) => {
    const r = await M.api(request, 'POST', '/reservations', { token: ctx.kidToken, body: { book_id: ctx.book2.id } });
    expect(r.status, r.text.slice(0, 300)).toBe(409);
    expect(r.json.error.code).toBe('max_loans_reached');
  });

  test('app waitlist request with reservations off → 409 reservations_disabled', async ({ request }) => {
    // Free the limit, then make the only copy busy: the request would queue.
    F.dbQuery(`DELETE FROM prestiti WHERE utente_id=${ctx.kid.id}`);
    F.dbQuery(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo, origine) VALUES (${ctx.busy.id}, ${ctx.busy.copyIds[0]}, ${ctx.staffId}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 20 DAY), 'in_corso', 1, 'diretto')`);
    F.dbQuery(`UPDATE copie SET stato='prestato' WHERE id=${ctx.busy.copyIds[0]}`);
    const r = await M.api(request, 'POST', '/reservations', { token: ctx.kidToken, body: { book_id: ctx.busy.id } });
    expect(r.status, r.text.slice(0, 300)).toBe(409);
    expect(r.json.error.code).toBe('reservations_disabled');
    expect(F.dbInt(`SELECT COUNT(*) FROM prenotazioni WHERE utente_id=${ctx.kid.id}`)).toBe(0);
  });
});

// @ts-check
/**
 * Circulation policy — the same patron-type rules on every channel:
 * the Mobile API (reader and staff routes) and the NCIP server refuse what
 * the website refuses, with stable codes; the debt block (plan §7) too.
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

  test('NCIP CheckOutItem and RenewItem of a reader in debt → User Ineligible (debt_block)', async ({ request }) => {
    // Global threshold 5 (the child's type leaves the field empty, so it inherits it), a charge of 8.
    const hadThreshold = F.dbInt("SELECT COUNT(*) FROM system_settings WHERE category='loans' AND setting_key='debt_block_threshold'") === 1;
    const savedThreshold = hadThreshold ? F.dbQuery("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='debt_block_threshold'") : null;
    F.dbQuery("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans','debt_block_threshold','5.00') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    F.dbQuery(`DELETE FROM prestiti WHERE utente_id=${ctx.kid.id}`);
    F.dbQuery(`INSERT INTO movimenti_lettore (utente_id, tipo, importo, descrizione, created_at) VALUES (${ctx.kid.id}, 'addebito_manuale', 8.00, 'Tessera smarrita', NOW())`);
    try {
      const out = await ncip(request, ncipXml('CheckOutItem', ctx.kid.id, ctx.book2.id));
      const outBody = await out.text();
      expect(outBody).toContain('User Ineligible To Check Out This Item');
      expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${ctx.kid.id}`)).toBe(0);

      // An open loan of the child (made before the debt): the renewal is refused the same way.
      F.dbQuery(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo, origine) VALUES (${ctx.book1.id}, ${ctx.book1.copyIds[0]}, ${ctx.kid.id}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 10 DAY), 'in_corso', 1, 'diretto')`);
      F.dbQuery(`UPDATE copie SET stato='prestato' WHERE id=${ctx.book1.copyIds[0]}`);
      const loanId = F.dbInt(`SELECT id FROM prestiti WHERE utente_id=${ctx.kid.id} AND libro_id=${ctx.book1.id} AND attivo=1`);
      const due = F.dbQuery(`SELECT data_scadenza FROM prestiti WHERE id=${loanId}`);
      const renew = await ncip(request, ncipXml('RenewItem', ctx.kid.id, ctx.book1.id));
      expect(await renew.text()).toContain('User Ineligible To Renew This Item');
      expect(F.dbQuery(`SELECT CONCAT(data_scadenza,'|',renewals) FROM prestiti WHERE id=${loanId}`)).toBe(`${due}|0`);

      // Paid below the threshold (the type still allows no renewal and one
      // loan): with the loan returned, the checkout refused above goes through.
      F.dbQuery(`INSERT INTO movimenti_lettore (utente_id, tipo, importo, descrizione, metodo_pagamento, created_at) VALUES (${ctx.kid.id}, 'pagamento', -8.00, 'Saldo', 'contanti', NOW())`);
      F.dbQuery(`DELETE FROM prestiti WHERE utente_id=${ctx.kid.id}`);
      F.dbQuery(`UPDATE copie SET stato='disponibile' WHERE id=${ctx.book1.copyIds[0]}`);
      const paid = await ncip(request, ncipXml('CheckOutItem', ctx.kid.id, ctx.book2.id));
      const paidBody = await paid.text();
      expect(paidBody).toContain('CheckOutItemResponse');
      expect(paidBody).not.toContain('User Ineligible');
      expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${ctx.kid.id} AND attivo=1`)).toBe(1);
    } finally {
      F.dbQuery(`DELETE FROM movimenti_lettore WHERE utente_id=${ctx.kid.id}`);
      F.dbQuery(`DELETE FROM prestiti WHERE utente_id=${ctx.kid.id}`);
      F.dbQuery(`UPDATE copie SET stato='disponibile' WHERE id IN (${ctx.book1.copyIds[0]}, ${ctx.book2.copyIds[0]})`);
      if (savedThreshold === null) F.dbQuery("DELETE FROM system_settings WHERE category='loans' AND setting_key='debt_block_threshold'");
      else F.dbQuery(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans','debt_block_threshold',${F.sqlStr(savedThreshold)}) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
    }
  });
});

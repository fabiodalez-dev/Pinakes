// @ts-check
/**
 * 0.9.0 cross-feature flow, end to end through the admin UI: a patron type
 * with its own loan length, daily fine and debt threshold (#508/#510), the
 * closures calendar (#509) moving the due date and keeping closed days out of
 * the late days, the late return charging the type's fine, the debt blocking
 * the reader on the website, the Mobile API and NCIP, and the payment at the
 * reader record lifting the block on all three channels.
 *
 * The global fine settings stay EMPTY for the whole file: every fine and
 * block comes from the patron type, and a reader of the default type
 * returning just as late pays nothing.
 *
 * The DB is used only to create the patron/books, to age the loan (dates in
 * the application timezone) and to verify what the UI wrote.
 *
 * Run: /tmp/run-e2e.sh tests/stack-090-integration.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');

// 0.9.0 optional modules (Settings → Modules / Loans): this suite tests
// fines, so it switches it on for its run and puts back what was there.
const featureToggles = require('./helpers/feature-toggles');
let featureSnapshot;
test.beforeAll(async () => { featureSnapshot = await featureToggles.enableFeatures(['fines']); });
test.afterAll(async () => { await featureToggles.restoreFeatures(featureSnapshot); });
const fs = require('fs');
const path = require('path');
const F = require('./helpers/circulation-fixtures');
const M = require('./helpers/mobile-api-fixtures');

test.skip(!F.CONFIGURED, 'E2E credentials not configured');

const RUN = String(Date.now()).slice(-6);
const PREFIX = `e2eint${RUN}`;
const TAG = `E2E-INT-${RUN}`;
const PASS = 'Circ1234!';
const TYPE_DAYS = 10;
const DEBT_MESSAGE = 'Saldo a debito oltre la soglia consentita';
const LOAN_SETTINGS = ['daily_fine', 'fine_grace_days', 'fine_max_per_loan', 'debt_block_threshold', 'closed_weekdays', 'auto_approve_requests', 'fine_charged_email'];
const NCIP_NS = 'http://www.niso.org/2008/ncip';

const ncipXml = (service, userId, itemId) => `<?xml version="1.0" encoding="UTF-8"?>
<NCIPMessage xmlns="${NCIP_NS}" ncip:version="http://www.niso.org/schemas/ncip/v2_02/ncip_v2_02.xsd" xmlns:ncip="${NCIP_NS}">
  <${service}>
    <UserId><UserIdentifierValue>${userId}</UserIdentifierValue></UserId>
    <ItemId><ItemIdentifierValue>${itemId}</ItemIdentifierValue></ItemId>
  </${service}>
</NCIPMessage>`;
const ncipAuth = 'Basic ' + Buffer.from(`${F.ADMIN_EMAIL}:${F.ADMIN_PASS}`).toString('base64');
const ncip = async (request, body) => (await request.post(`${F.BASE}/ncip`, { data: body, headers: { 'Content-Type': 'application/xml', Authorization: ncipAuth } })).text();

function readSetting(key) {
  const exists = F.dbInt(`SELECT COUNT(*) FROM system_settings WHERE category='loans' AND setting_key=${F.sqlStr(key)}`) === 1;
  return exists ? F.dbQuery(`SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key=${F.sqlStr(key)}`) : null;
}
function restoreSetting(key, value) {
  if (value === null) F.dbQuery(`DELETE FROM system_settings WHERE category='loans' AND setting_key=${F.sqlStr(key)}`);
  else F.dbQuery(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans', ${F.sqlStr(key)}, ${F.sqlStr(value)}) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
}
function clearConfigCache() {
  const cacheDir = path.join(__dirname, '..', 'storage', 'cache');
  if (!fs.existsSync(cacheDir)) return;
  for (const name of fs.readdirSync(cacheDir)) {
    if (name.startsWith('pinakes_config_settings_raw_')) {
      try { fs.unlinkSync(path.join(cacheDir, name)); } catch { /* best effort */ }
    }
  }
}
function addDays(iso, days) {
  const d = new Date(`${iso}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}
const balanceCents = (userId) => Math.round(Number(F.dbQuery(`SELECT COALESCE(SUM(importo),0) FROM movimenti_lettore WHERE utente_id=${userId}`)) * 100);

async function addClosure(page, { start, end = '', description }) {
  await page.goto(`${F.BASE}/admin/calendar`);
  await page.fill('#closure_start', start);
  if (end) await page.fill('#closure_end', end);
  await page.fill('#closure_description', description);
  await Promise.all([page.waitForURL(/\/admin\/calendar/, { timeout: 15000 }), page.locator('#save-closure').click()]);
  expect(F.dbInt(`SELECT COUNT(*) FROM calendario_chiusure WHERE descrizione=${F.sqlStr(description)}`), `closure ${description} saved`).toBe(1);
}

/** Return screen: the fine it announces (cents, 0 when none), then confirm. */
async function returnAtDesk(page, loanId) {
  await page.goto(`${F.BASE}/admin/loans/returned/${loanId}`);
  await expect(page.getByRole('button', { name: 'Conferma restituzione' })).toBeVisible();
  const box = page.locator('#fine-preview');
  const shown = (await box.isVisible().catch(() => false)) ? Number(await box.getAttribute('data-fine-cents')) : 0;
  await page.getByRole('button', { name: 'Conferma restituzione' }).click();
  await page.waitForFunction(() => !window.location.pathname.includes('/admin/loans/returned/'), null, { timeout: 20000 });
  return shown;
}

/** Age an active loan so that it was lent 20 days ago and due $late days ago. */
function makeOverdue(loanId, late) {
  F.dbQuery(`UPDATE prestiti SET data_prestito='${F.appDateOffsetISO(-20)}', data_scadenza='${F.appDateOffsetISO(-late)}', stato='in_ritardo' WHERE id=${loanId}`);
}

test.describe.serial('0.9.0 stack: patron type + closures + fines + debt block across channels', () => {
  test.describe.configure({ timeout: 240000 });
  /** @type {import('@playwright/test').Page} */ let admin;
  let adminCtx;
  const saved = {};
  let savedClosures = null;
  let ncipWasActive = true;
  let mobileWasActive = true;
  let gate = null;
  let typeId = 0;
  let reader;
  let plain;
  const books = {};
  const errors = [];
  let today = '';
  let deskLoan = 0;

  test.beforeAll(async ({ browser }) => {
    for (const k of LOAN_SETTINGS) saved[k] = readSetting(k);
    savedClosures = F.stashRows('calendario_chiusure');
    F.dbQuery('DELETE FROM calendario_chiusure');
    // Global fines and threshold empty: only the patron type charges and blocks.
    for (const k of ['daily_fine', 'fine_max_per_loan', 'debt_block_threshold']) restoreSetting(k, '');
    restoreSetting('fine_grace_days', '0');
    restoreSetting('fine_charged_email', '0');
    restoreSetting('closed_weekdays', null);
    restoreSetting('auto_approve_requests', '0');
    clearConfigCache();
    today = F.appTodayISO();

    reader = F.createPatron(`INT${RUN}`, { password: PASS });
    plain = F.createPatron(`INP${RUN}`, { password: PASS });
    for (const key of ['desk', 'web', 'app', 'ncip', 'plain']) books[key] = F.createTitle(`${PREFIX}-${key}`);

    mobileWasActive = F.pluginActive('mobile-api');
    const helper = await browser.newPage();
    gate = await M.ensureMobileApiReady(helper);
    await helper.close();
    const ncipId = F.dbInt("SELECT id FROM plugins WHERE name='ncip-server' LIMIT 1");
    expect(ncipId, 'ncip-server plugin registered').toBeGreaterThan(0);

    adminCtx = await browser.newContext();
    admin = await adminCtx.newPage();
    admin.on('response', (r) => { if (r.status() >= 500) errors.push(`${r.status()} ${r.url()}`); });
    await F.loginAsAdmin(admin);
    ncipWasActive = F.pluginActive('ncip-server');
    await F.setPluginActive(admin, 'ncip-server', true);
  });

  test.afterEach(() => { expect(errors.splice(0), 'no HTTP 5xx').toEqual([]); });

  test.afterAll(async () => {
    // Independent restore steps: one failing must not leave the others undone.
    await F.runAll([
      () => { for (const k of LOAN_SETTINGS) restoreSetting(k, saved[k]); },
      () => clearConfigCache(),
      () => F.unstashRows(savedClosures),
      () => {
        for (const p of [reader, plain]) {
          if (!p) continue;
          try { F.dbQuery(`DELETE FROM mobile_app_tokens WHERE user_id=${p.id}`); } catch { /* no token table */ }
        }
      },
      () => {
        const ids = Object.values(books).map((b) => b.id);
        if (ids.length) {
          try { F.dbQuery(`DELETE FROM admin_notifications WHERE related_id IN (SELECT id FROM prestiti WHERE libro_id IN (${ids.join(',')}))`); } catch { /* */ }
          try { F.dbQuery(`DELETE FROM log_modifiche WHERE tabella='libri' AND record_id IN (${ids.join(',')})`); } catch { /* */ }
        }
        F.deleteTitles(ids);
      },
      () => F.deletePatrons([reader?.id, plain?.id]),
      () => F.deleteTypes(PREFIX),
      () => M.restoreGate(gate),
      async () => { if (admin && !ncipWasActive) await F.setPluginActive(admin, 'ncip-server', false); },
      async () => { if (admin && !mobileWasActive) await F.setPluginActive(admin, 'mobile-api', false); },
      async () => { await adminCtx?.close(); },
    ]);
  });

  test('1. the librarian creates a patron type with its own loan length, daily fine and debt threshold', async () => {
    typeId = await F.createTypeViaUi(admin, {
      nome: `Integrazione ${RUN}`, codice: `${PREFIX}-t`, durata_prestito_giorni: TYPE_DAYS,
      multa_giornaliera: '1,00', giorni_tolleranza: 0, soglia_blocco_debito: '2',
    });
    expect(F.dbQuery(`SELECT CONCAT_WS('|', durata_prestito_giorni, multa_giornaliera, IFNULL(giorni_tolleranza,'-'), soglia_blocco_debito) FROM tipologie_lettore WHERE id=${typeId}`))
      .toMatch(new RegExp(`^${TYPE_DAYS}\\|1\\.00\\|(0|-)\\|2\\.00$`));
    // The globals stay empty: the type is the only source of fines.
    expect(readSetting('daily_fine')).toBe('');
    expect(readSetting('debt_block_threshold')).toBe('');
  });

  test('2. the reader gets the type from the user edit form and the record shows its rules', async () => {
    await admin.goto(`${F.BASE}/admin/users/edit/${reader.id}`);
    await admin.locator('#tipologia_id').selectOption(String(typeId));
    await admin.locator('#user-form button[type="submit"]').click();
    await admin.waitForURL((u) => !u.pathname.includes('/edit/'), { timeout: 20000 });
    expect(admin.url()).not.toContain('error=');
    expect(F.dbInt(`SELECT IFNULL(tipologia_id,0) FROM utenti WHERE id=${reader.id}`)).toBe(typeId);
    await admin.goto(`${F.BASE}/admin/users/details/${reader.id}`);
    await expect(admin.locator('[data-testid="patron-type-name"]')).toContainText(`Integrazione ${RUN}`);
    await expect(admin.locator('[data-ledger-blocked]')).toHaveCount(0);
  });

  test('3. a closure on the type due date: the desk loan is proposed and stored on the next open day', async () => {
    const raw = addDays(today, TYPE_DAYS);
    await addClosure(admin, { start: raw, description: `${TAG} scadenza` });
    await admin.goto(`${F.BASE}/admin/loans/create`);
    await F.fillAutocomplete(admin, '#utente_search', '#utente_suggest', reader.surname, '/api/search/utenti');
    await expect(admin.locator('#utente_id')).toHaveValue(String(reader.id));
    const availability = admin.waitForResponse((r) => r.url().includes('/availability') && r.url().includes(`for_user=${reader.id}`), { timeout: 15000 });
    const resolved = admin.waitForResponse((r) => r.url().includes('/admin/copies/by-code'));
    await admin.locator('#copy_code').fill(F.copyCodeOf(books.desk.copyIds[0]));
    await admin.locator('#copy_code').dispatchEvent('change');
    await resolved;
    const data = await (await availability).json();
    expect(Number(data.availability.staff_loan_duration_days)).toBe(TYPE_DAYS);
    await expect(admin.locator('#data_scadenza')).toHaveValue(addDays(raw, 1));
    await admin.evaluate(() => document.querySelectorAll('.flatpickr-calendar.open').forEach((c) => c.classList.remove('open')));
    await admin.uncheck('#scarica_pdf').catch(() => {});
    await Promise.all([
      admin.waitForURL((u) => u.toString().includes('created=1') || u.toString().includes('error='), { timeout: 20000 }),
      admin.locator('form[action$="/admin/loans/create"] button[type="submit"]:not([name="save_and_new"])').click(),
    ]);
    expect(admin.url()).toContain('created=1');
    deskLoan = F.dbInt(`SELECT id FROM prestiti WHERE libro_id=${books.desk.id} AND utente_id=${reader.id} ORDER BY id DESC LIMIT 1`);
    expect(F.dbQuery(`SELECT data_scadenza FROM prestiti WHERE id=${deskLoan}`)).toBe(addDays(raw, 1));
  });

  test('4. the late return is fined at the type rate on the open late days only', async () => {
    // Due 6 days ago; the library was closed 4 and 3 days ago: 6 - 2 = 4 open late days.
    makeOverdue(deskLoan, 6);
    await addClosure(admin, { start: addDays(today, -4), end: addDays(today, -3), description: `${TAG} ponte` });
    await admin.goto(`${F.BASE}/admin/loans/details/${deskLoan}`);
    await expect(admin.locator('[data-loan-fine-accrued]')).toHaveAttribute('data-loan-fine-accrued', '400');
    const shown = await returnAtDesk(admin, deskLoan);
    expect(shown).toBe(400);
    expect(F.dbQuery(`SELECT CONCAT(stato,'|',attivo) FROM prestiti WHERE id=${deskLoan}`)).toBe('restituito|0');
    expect(F.dbQuery(`SELECT GROUP_CONCAT(importo) FROM movimenti_lettore WHERE prestito_id=${deskLoan} AND tipo='multa_ritardo'`)).toBe('4.00');
    expect(balanceCents(reader.id)).toBe(400);
    // 4,00 owed is over the type threshold of 2,00: the reader record says so.
    await admin.goto(`${F.BASE}/admin/users/details/${reader.id}`);
    await expect(admin.locator('[data-ledger-balance]')).toHaveAttribute('data-ledger-balance', '400');
    await expect(admin.locator('[data-ledger-blocked]')).toContainText('Prestiti bloccati');
  });

  test('5. the website refuses the blocked reader with the debt message', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    try {
      await F.loginAs(page, reader.email, PASS);
      const { ok, text } = await F.requestFromBookPage(page, books.web.id, today);
      expect(ok).toBe(false);
      expect(text).toContain(DEBT_MESSAGE);
    } finally {
      await ctx.close();
    }
    expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${reader.id} AND libro_id=${books.web.id}`)).toBe(0);
  });

  test('6. the Mobile API refuses the same reader with the same message', async ({ request }) => {
    const token = await M.login(request, reader.email, PASS, `int-${RUN}`);
    const r = await M.api(request, 'POST', '/reservations', { token, body: { book_id: books.app.id } });
    expect(r.status, r.text.slice(0, 300)).toBe(403);
    expect(r.json.error.code).toBe('not_eligible');
    expect(r.json.error.message).toContain(DEBT_MESSAGE);
    expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${reader.id} AND libro_id=${books.app.id}`)).toBe(0);
  });

  test('7. NCIP refuses the checkout to the same reader (User Ineligible)', async ({ request }) => {
    const body = await ncip(request, ncipXml('CheckOutItem', reader.id, books.ncip.id));
    expect(body).toContain('User Ineligible To Check Out This Item');
    expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${reader.id} AND libro_id=${books.ncip.id}`)).toBe(0);
  });

  test('8. the payment at the reader record lifts the block on website, app and NCIP', async ({ browser, request }) => {
    await admin.goto(`${F.BASE}/admin/users/details/${reader.id}#movimenti`);
    await admin.fill('#ledger_pay_amount', '4,00');
    await admin.selectOption('#ledger_pay_method', 'contanti');
    await admin.locator('#ledger-payment-form button[type="submit"]').click();
    await expect(admin.locator('[data-ledger-flash="paid"]')).toBeVisible();
    expect(balanceCents(reader.id)).toBe(0);
    await expect(admin.locator('[data-ledger-blocked]')).toHaveCount(0);

    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    try {
      await F.loginAs(page, reader.email, PASS);
      const { ok, text } = await F.requestFromBookPage(page, books.web.id, today);
      expect(ok, text).toBe(true);
    } finally {
      await ctx.close();
    }
    expect(F.dbQuery(`SELECT stato FROM prestiti WHERE utente_id=${reader.id} AND libro_id=${books.web.id} ORDER BY id DESC LIMIT 1`)).toBe('pendente');

    const token = await M.login(request, reader.email, PASS, `int2-${RUN}`);
    const r = await M.api(request, 'POST', '/reservations', { token, body: { book_id: books.app.id } });
    expect(r.status, r.text.slice(0, 300)).toBeLessThan(300);
    expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${reader.id} AND libro_id=${books.app.id}`)).toBe(1);

    const body = await ncip(request, ncipXml('CheckOutItem', reader.id, books.ncip.id));
    expect(body).toContain('CheckOutItemResponse');
    expect(body).not.toContain('User Ineligible');
    // The NCIP loan follows the type length, moved over a closure if one falls on it.
    const ncipDue = F.dbQuery(`SELECT data_scadenza FROM prestiti WHERE utente_id=${reader.id} AND libro_id=${books.ncip.id} AND attivo=1`);
    expect(ncipDue).toBe(addDays(today, TYPE_DAYS + 1));
  });

  test('9. a reader of the default type, just as late over the same closures, pays nothing (globals empty)', async () => {
    const copyId = books.plain.copyIds[0];
    F.dbQuery(`UPDATE copie SET stato='prestato' WHERE id=${copyId}`);
    F.dbQuery(`UPDATE libri SET copie_disponibili=0, stato='prestato' WHERE id=${books.plain.id}`);
    F.dbQuery(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (${books.plain.id}, ${copyId}, ${plain.id}, '${F.appDateOffsetISO(-20)}', '${F.appDateOffsetISO(-6)}', 'in_ritardo', 'diretto', 1)`);
    const loanId = F.dbInt(`SELECT id FROM prestiti WHERE libro_id=${books.plain.id} AND utente_id=${plain.id} ORDER BY id DESC LIMIT 1`);
    await admin.goto(`${F.BASE}/admin/loans/returned/${loanId}`);
    await expect(admin.locator('#fine-preview')).toHaveCount(0);
    const shown = await returnAtDesk(admin, loanId);
    expect(shown).toBe(0);
    expect(F.dbQuery(`SELECT stato FROM prestiti WHERE id=${loanId}`)).toBe('restituito');
    expect(F.dbInt(`SELECT COUNT(*) FROM movimenti_lettore WHERE utente_id=${plain.id}`)).toBe(0);
    await admin.goto(`${F.BASE}/admin/users/details/${plain.id}`);
    await expect(admin.locator('[data-ledger-blocked]')).toHaveCount(0);
  });
});

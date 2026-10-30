// @ts-check
/**
 * Zero-config equivalence of the 0.9.0 stack: an install that never touches
 * patron types, the rules matrix, the closures calendar, fines or the debt
 * threshold must behave as 0.8.9 did. Due dates and renewals follow
 * Settings → Loans (loan_duration_days), the pickup deadline follows
 * pickup_expiry_days, a late return charges nothing and its email has no
 * fine line, and a reader with money owed in the ledger is never blocked on
 * the website, the Mobile API or NCIP.
 *
 * beforeAll puts the install in that state (empty calendar, no matrix rows,
 * empty fine settings) and afterAll restores what was there. The DB is used
 * only for that, for the fixtures (dates in the application timezone) and to
 * verify what the UI wrote.
 *
 * Run: /tmp/run-e2e.sh tests/stack-090-zero-config.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const F = require('./helpers/circulation-fixtures');
const M = require('./helpers/mobile-api-fixtures');

test.skip(!F.CONFIGURED, 'E2E credentials not configured');

const RUN = String(Date.now()).slice(-6);
const PREFIX = `e2ezc${RUN}`;
const PASS = 'Circ1234!';
const MAILPIT_API = process.env.MAILPIT_API || 'http://localhost:8025/api/v1';
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

function readSetting(category, key) {
  const exists = F.dbInt(`SELECT COUNT(*) FROM system_settings WHERE category=${F.sqlStr(category)} AND setting_key=${F.sqlStr(key)}`) === 1;
  return exists ? F.dbQuery(`SELECT setting_value FROM system_settings WHERE category=${F.sqlStr(category)} AND setting_key=${F.sqlStr(key)}`) : null;
}
function writeSetting(category, key, value) {
  if (value === null) F.dbQuery(`DELETE FROM system_settings WHERE category=${F.sqlStr(category)} AND setting_key=${F.sqlStr(key)}`);
  else F.dbQuery(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES (${F.sqlStr(category)}, ${F.sqlStr(key)}, ${F.sqlStr(value)}) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
}
// Drops the file-backed settings cache used when APCu is off (local slots).
// With APCu (CI) this is a no-op: the settings these specs change are read
// through SettingsRepository, which is not cached, and email settings are
// refreshed by saving a settings tab through the app.
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
/** A title with one copy lent to $patron, lent 20 days ago and due $late days ago. */
function overdueLoan(patron, book, late) {
  const copyId = book.copyIds[0];
  F.dbQuery(`UPDATE copie SET stato='prestato' WHERE id=${copyId}`);
  F.dbQuery(`UPDATE libri SET copie_disponibili=0, stato='prestato' WHERE id=${book.id}`);
  F.dbQuery(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (${book.id}, ${copyId}, ${patron.id}, '${F.appDateOffsetISO(-20)}', '${F.appDateOffsetISO(-late)}', 'in_ritardo', 'diretto', 1)`);
  return F.dbInt(`SELECT id FROM prestiti WHERE libro_id=${book.id} AND utente_id=${patron.id} ORDER BY id DESC LIMIT 1`);
}

test.describe.serial('0.9.0 with nothing configured behaves as 0.8.9', () => {
  test.describe.configure({ timeout: 240000 });
  /** @type {import('@playwright/test').Page} */ let admin;
  let adminCtx;
  const saved = {};
  let closures;
  let rules;
  let emailSettings;
  let ncipWasActive = true;
  let mobileWasActive = true;
  let gate = null;
  let reader;
  let debtor;
  const books = {};
  const errors = [];
  let today = '';
  let loanDays = 30;
  let pickupDays = 3;
  let deskLoan = 0;

  test.beforeAll(async ({ browser }) => {
    for (const k of LOAN_SETTINGS) saved[k] = readSetting('loans', k);
    closures = F.stashRows('calendario_chiusure');
    rules = F.stashRows('regole_prestito');
    emailSettings = F.stashRows('system_settings', "category='email'");
    F.dbQuery('DELETE FROM calendario_chiusure');
    F.dbQuery('DELETE FROM regole_prestito');
    for (const k of ['daily_fine', 'fine_max_per_loan', 'debt_block_threshold']) writeSetting('loans', k, '');
    writeSetting('loans', 'fine_grace_days', '0');
    writeSetting('loans', 'fine_charged_email', '0');
    writeSetting('loans', 'closed_weekdays', null);
    writeSetting('loans', 'auto_approve_requests', '0');
    clearConfigCache();
    today = F.appTodayISO();
    loanDays = Number(readSetting('loans', 'loan_duration_days') || 30);
    pickupDays = Number(readSetting('loans', 'pickup_expiry_days') || 3);

    reader = F.createPatron(`ZCR${RUN}`, { password: PASS, email: `zc-reader-${RUN}@circ.test` });
    debtor = F.createPatron(`ZCD${RUN}`, { password: PASS, email: `zc-debtor-${RUN}@circ.test` });
    // Money owed in the ledger, as an earlier manual charge would leave it.
    F.dbQuery(`INSERT INTO movimenti_lettore (utente_id, tipo, importo, descrizione, created_at) VALUES (${debtor.id}, 'addebito_manuale', 50.00, 'Tessera smarrita', NOW())`);
    for (const key of ['desk', 'renew', 'request', 'late', 'web', 'app', 'ncip']) books[key] = F.createTitle(`${PREFIX}-${key}`);

    mobileWasActive = F.pluginActive('mobile-api');
    const helper = await browser.newPage();
    gate = await M.ensureMobileApiReady(helper);
    await helper.close();

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
      () => { for (const k of LOAN_SETTINGS) writeSetting('loans', k, saved[k]); },
      () => F.unstashRows(closures),
      () => F.unstashRows(rules),
      // Always reset the category: an install with no email rows gets none back.
      () => F.unstashRows(emailSettings),
      () => clearConfigCache(),
      () => {
        for (const p of [reader, debtor]) {
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
      () => F.deletePatrons([reader?.id, debtor?.id]),
      () => M.restoreGate(gate),
      async () => { if (admin && !ncipWasActive) await F.setPluginActive(admin, 'ncip-server', false); },
      async () => { if (admin && !mobileWasActive) await F.setPluginActive(admin, 'mobile-api', false); },
      async () => { await adminCtx?.close(); },
    ]);
  });

  test('1. the settings, calendar and circulation pages show nothing configured', async () => {
    await admin.goto(`${F.BASE}/admin/settings?tab=loans`);
    for (const id of ['#daily_fine', '#fine_max_per_loan', '#debt_block_threshold']) await expect(admin.locator(id)).toHaveValue('');
    // Saving the tab unchanged (also refreshes the server's settings cache) enables nothing.
    await admin.locator('form[action$="/admin/settings/loans"] button[type="submit"]').click();
    await admin.waitForURL(/tab=loans/, { timeout: 15000 });
    expect(readSetting('loans', 'daily_fine')).toBe('');
    expect(readSetting('loans', 'debt_block_threshold')).toBe('');
    await F.openCirculationTab(admin);
    // Only the seeded default type, inheriting every rule; an empty matrix.
    await expect(admin.locator('#circulation-types-table tbody tr[data-type-code]')).toHaveCount(F.dbInt('SELECT COUNT(*) FROM tipologie_lettore'));
    expect(F.dbInt('SELECT COUNT(*) FROM regole_prestito')).toBe(0);
    expect(F.dbInt(`SELECT COUNT(*) FROM tipologie_lettore WHERE predefinita=1 AND durata_prestito_giorni IS NULL AND max_rinnovi IS NULL AND multa_giornaliera IS NULL AND soglia_blocco_debito IS NULL`)).toBe(1);
    await admin.goto(`${F.BASE}/admin/calendar`);
    await expect(admin.locator('#save-closure')).toBeVisible();
    expect(F.dbInt('SELECT COUNT(*) FROM calendario_chiusure')).toBe(0);
  });

  test('2. the desk loan is due after loan_duration_days, as in 0.8.9', async () => {
    await admin.goto(`${F.BASE}/admin/loans/create`);
    await expect(admin.locator('#data_scadenza')).toHaveValue(addDays(today, loanDays));
    await F.fillAutocomplete(admin, '#utente_search', '#utente_suggest', reader.surname, '/api/search/utenti');
    await expect(admin.locator('#utente_id')).toHaveValue(String(reader.id));
    const availability = admin.waitForResponse((r) => r.url().includes('/availability') && r.url().includes(`for_user=${reader.id}`), { timeout: 15000 });
    const resolved = admin.waitForResponse((r) => r.url().includes('/admin/copies/by-code'));
    await admin.locator('#copy_code').fill(F.copyCodeOf(books.desk.copyIds[0]));
    await admin.locator('#copy_code').dispatchEvent('change');
    await resolved;
    const data = await (await availability).json();
    expect(Number(data.availability.staff_loan_duration_days)).toBe(loanDays);
    await expect(admin.locator('#data_scadenza')).toHaveValue(addDays(today, loanDays));
    await admin.evaluate(() => document.querySelectorAll('.flatpickr-calendar.open').forEach((c) => c.classList.remove('open')));
    await admin.uncheck('#scarica_pdf').catch(() => {});
    await Promise.all([
      admin.waitForURL((u) => u.toString().includes('created=1') || u.toString().includes('error='), { timeout: 20000 }),
      admin.locator('form[action$="/admin/loans/create"] button[type="submit"]:not([name="save_and_new"])').click(),
    ]);
    expect(admin.url()).toContain('created=1');
    deskLoan = F.dbInt(`SELECT id FROM prestiti WHERE libro_id=${books.desk.id} AND utente_id=${reader.id} ORDER BY id DESC LIMIT 1`);
    expect(F.dbQuery(`SELECT CONCAT(data_prestito,'|',data_scadenza) FROM prestiti WHERE id=${deskLoan}`)).toBe(`${today}|${addDays(today, loanDays)}`);
  });

  test('3. a renewal adds loan_duration_days to the due date and counts one renewal', async () => {
    const copyId = books.renew.copyIds[0];
    const due = addDays(today, 5);
    F.dbQuery(`UPDATE copie SET stato='prestato' WHERE id=${copyId}`);
    F.dbQuery(`UPDATE libri SET copie_disponibili=0, stato='prestato' WHERE id=${books.renew.id}`);
    F.dbQuery(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (${books.renew.id}, ${copyId}, ${reader.id}, '${F.appDateOffsetISO(-3)}', '${due}', 'in_corso', 'diretto', 1)`);
    const loanId = F.dbInt(`SELECT id FROM prestiti WHERE libro_id=${books.renew.id} AND utente_id=${reader.id} AND attivo=1`);
    await admin.goto(`${F.BASE}/admin/books/${books.renew.id}`);
    await admin.getByRole('button', { name: /Rinnova prestito/ }).click();
    await admin.locator('.swal2-confirm').click();
    await admin.waitForFunction((id) => window.location.search.includes('renewed') || window.location.search.includes('error=') || !document.querySelector(`[data-loan-id="${id}"]`), loanId, { timeout: 20000 }).catch(() => {});
    await expect.poll(() => F.dbQuery(`SELECT CONCAT(data_scadenza,'|',renewals) FROM prestiti WHERE id=${loanId}`), { timeout: 15000 }).toBe(`${addDays(due, loanDays)}|1`);
    expect(admin.url()).not.toContain('error=');
  });

  test('4. a reader request and its approval: due after loan_duration_days, pickup after pickup_expiry_days', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    try {
      await F.loginAs(page, reader.email, PASS);
      const { ok, text } = await F.requestFromBookPage(page, books.request.id, today);
      expect(ok, text).toBe(true);
    } finally {
      await ctx.close();
    }
    const loanId = F.dbInt(`SELECT id FROM prestiti WHERE libro_id=${books.request.id} AND utente_id=${reader.id} AND stato='pendente'`);
    expect(loanId).toBeGreaterThan(0);
    expect(F.dbQuery(`SELECT CONCAT(data_prestito,'|',data_scadenza) FROM prestiti WHERE id=${loanId}`)).toBe(`${today}|${addDays(today, loanDays)}`);

    await admin.goto(`${F.BASE}/admin/loans/pending`);
    const approve = admin.locator(`.approve-btn[data-loan-id="${loanId}"]`);
    await expect(approve).toBeVisible({ timeout: 15000 });
    await approve.click();
    await admin.waitForSelector('.swal2-popup', { timeout: 10000 });
    await admin.locator('.swal2-confirm').click();
    await expect.poll(() => F.dbQuery(`SELECT stato FROM prestiti WHERE id=${loanId}`), { timeout: 15000 }).toBe('da_ritirare');
    expect(F.dbQuery(`SELECT CONCAT(data_scadenza,'|',IFNULL(pickup_deadline,'-')) FROM prestiti WHERE id=${loanId}`))
      .toBe(`${addDays(today, loanDays)}|${addDays(today, Math.min(pickupDays, loanDays))}`);
  });

  test('5. a late return charges nothing, the loan page shows no fine and the return email has no fine line', async () => {
    let mailpit = false;
    try { mailpit = (await fetch(`${MAILPIT_API}/messages`, { signal: AbortSignal.timeout(3000) })).ok; } catch { mailpit = false; }
    // In CI Mailpit is a service of the job: without it the e-mail half of
    // this test would pass silently, so it fails instead. Locally it is optional.
    if (!mailpit && process.env.CI) {
      throw new Error(`Mailpit is not reachable at ${MAILPIT_API} in CI`);
    }
    if (mailpit) {
      F.dbQuery(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES
        ('email','type','smtp'),('email','driver_mode','smtp'),('email','smtp_host','localhost'),('email','smtp_port','1025'),
        ('email','smtp_security',''),('email','smtp_username',''),('email','smtp_password',''),
        ('email','from_email','test@biblioteca.local'),('email','from_name','Pinakes Test')
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
      clearConfigCache();
      // Saving through the app invalidates the web server's settings cache too.
      await admin.goto(`${F.BASE}/admin/settings?tab=loans`);
      await admin.locator('form[action$="/admin/settings/loans"] button[type="submit"]').click();
      await admin.waitForURL(/tab=loans/, { timeout: 15000 });
    }
    const loanId = overdueLoan(reader, books.late, 12);
    await admin.goto(`${F.BASE}/admin/loans/details/${loanId}`);
    await expect(admin.locator('[data-loan-fine-accrued]')).toHaveCount(0);
    await admin.goto(`${F.BASE}/admin/loans/returned/${loanId}`);
    await expect(admin.getByRole('button', { name: 'Conferma restituzione' })).toBeVisible();
    await expect(admin.locator('#fine-preview')).toHaveCount(0);
    await expect(admin.locator('#waive_fine')).toHaveCount(0);
    await admin.getByRole('button', { name: 'Conferma restituzione' }).click();
    await admin.waitForFunction(() => !window.location.pathname.includes('/admin/loans/returned/'), null, { timeout: 20000 });
    expect(F.dbQuery(`SELECT CONCAT(stato,'|',attivo,'|',restituito_in_ritardo) FROM prestiti WHERE id=${loanId}`)).toBe('restituito|0|1');
    expect(F.dbInt(`SELECT COUNT(*) FROM movimenti_lettore WHERE utente_id=${reader.id}`)).toBe(0);

    if (!mailpit) return;
    const search = async (query) => (await (await fetch(`${MAILPIT_API}/search?query=${encodeURIComponent(query)}`)).json()).messages || [];
    let returned = null;
    const deadline = Date.now() + 30000;
    while (!returned && Date.now() < deadline) {
      for (const m of await search(`to:${reader.email} subject:"Restituzione"`)) {
        const full = await (await fetch(`${MAILPIT_API}/message/${m.ID}`)).json();
        if (full.HTML.includes(books.late.title)) { returned = full; break; }
      }
      if (!returned) await new Promise((r) => setTimeout(r, 500));
    }
    expect(returned, 'loan_returned email in Mailpit').not.toBeNull();
    expect(returned.HTML).not.toContain('Multa');
    expect(returned.HTML).not.toContain('{{multa}}');
    expect(await search(`to:${reader.email} subject:"Multa per ritardo"`)).toEqual([]);
  });

  test('6. money owed in the ledger does not block the website request', async ({ browser }) => {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    try {
      await F.loginAs(page, debtor.email, PASS);
      const { ok, text } = await F.requestFromBookPage(page, books.web.id, today);
      expect(ok, text).toBe(true);
    } finally {
      await ctx.close();
    }
    expect(F.dbQuery(`SELECT stato FROM prestiti WHERE utente_id=${debtor.id} AND libro_id=${books.web.id}`)).toBe('pendente');
    // Fines are an opt-in module (features.fines, off by default): the reader
    // record has no Movimenti section and no block; the ledger row stays.
    await admin.goto(`${F.BASE}/admin/users/details/${debtor.id}`);
    await expect(admin.locator('h1').first()).toBeVisible();
    await expect(admin.locator('#movimenti')).toHaveCount(0);
    await expect(admin.locator('[data-ledger-blocked]')).toHaveCount(0);
    expect(Math.round(Number(F.dbQuery(`SELECT COALESCE(SUM(importo),0) FROM movimenti_lettore WHERE utente_id=${debtor.id}`)) * 100)).toBe(5000);
  });

  test('7. money owed does not block the Mobile API request', async ({ request }) => {
    const token = await M.login(request, debtor.email, PASS, `zc-${RUN}`);
    const r = await M.api(request, 'POST', '/reservations', { token, body: { book_id: books.app.id } });
    expect(r.status, r.text.slice(0, 300)).toBeLessThan(300);
    expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${debtor.id} AND libro_id=${books.app.id}`)).toBe(1);
  });

  test('8. money owed does not block an NCIP checkout, due after loan_duration_days', async ({ request }) => {
    const body = await ncip(request, ncipXml('CheckOutItem', debtor.id, books.ncip.id));
    expect(body).toContain('CheckOutItemResponse');
    expect(body).not.toContain('User Ineligible');
    expect(F.dbQuery(`SELECT data_scadenza FROM prestiti WHERE utente_id=${debtor.id} AND libro_id=${books.ncip.id} AND attivo=1`)).toBe(addDays(today, loanDays));
  });
});

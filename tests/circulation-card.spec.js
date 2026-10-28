// @ts-check
/**
 * Circulation policy — library card of the patron type.
 * A: automatic card duration on creation, edit and registration approval.
 * B: "Rinnova tessera" from the reader page.
 * C: the card_expiring email, sent once per expiry by the notifications cron
 *    (Mailpit on :8025 receives it).
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const F = require('./helpers/circulation-fixtures');

test.skip(!F.CONFIGURED, 'E2E credentials not configured');

const RUN = String(Date.now()).slice(-6);
const PREFIX = `e2ecd${RUN}`;
const MAILPIT = process.env.MAILPIT_API || 'http://localhost:8025/api/v1';
const ROOT = path.resolve(__dirname, '..');

/** Days between two Y-m-d dates (calendar, DST-safe). */
const daysBetween = (a, b) => Math.round((Date.parse(`${b}T12:00:00Z`) - Date.parse(`${a}T12:00:00Z`)) / 86400000);
const cardOf = (id) => F.dbQuery(`SELECT IFNULL(data_scadenza_tessera,'') FROM utenti WHERE id=${id}`);

/** Create a patron from the admin form; returns its id. */
async function createUserViaForm(page, { email, surname, typeId, cardAuto = true, manualExpiry = '' }) {
  await page.goto(`${F.BASE}/admin/users/create`);
  await page.locator('#nome').fill('Card');
  await page.locator('#cognome').fill(surname);
  await page.locator('#email').fill(email);
  await page.locator('#telefono').fill('0000000');
  await page.locator('#indirizzo').fill('Via E2E 1');
  await page.locator('#password').fill('Circ1234!');
  if (typeId) await page.locator('#tipologia_id').selectOption(String(typeId));
  if (manualExpiry) {
    await page.evaluate((d) => {
      const el = /** @type {any} */ (document.querySelector('#data_scadenza_tessera'));
      if (el && el._flatpickr) el._flatpickr.setDate(d, true, 'Y-m-d'); else if (el) el.value = d;
    }, manualExpiry);
  }
  if (!cardAuto) await page.locator('#card_auto').uncheck();
  await page.locator('#user-form button[type="submit"]').click();
  await page.waitForURL((u) => u.pathname.endsWith('/admin/users') && u.search.includes('created=1'), { timeout: 20000 });
  return F.dbInt(`SELECT id FROM utenti WHERE email=${F.sqlStr(email)}`);
}

test.describe.serial('Card duration, renewal and expiry warning', () => {
  /** @type {import('@playwright/test').Page} */ let admin;
  let ctx; let yearType = 0; let monthType = 0; let plainType = 0;
  const users = [];
  const errors = [];
  const savedEmail = {};
  let savedWarning = null;

  test.beforeAll(async ({ browser }) => {
    ctx = await browser.newContext(); admin = await ctx.newPage();
    admin.on('response', (r) => { if (r.status() >= 500) errors.push(`${r.status()} ${r.url()}`); });
    await F.loginAsAdmin(admin);
    yearType = await F.createTypeViaUi(admin, { nome: `Annuale ${RUN}`, codice: `${PREFIX}-year`, durata_tessera_giorni: 365 });
    monthType = await F.createTypeViaUi(admin, { nome: `Mensile ${RUN}`, codice: `${PREFIX}-month`, durata_tessera_giorni: 30 });
    plainType = await F.createTypeViaUi(admin, { nome: `Libera ${RUN}`, codice: `${PREFIX}-plain` });
    for (const key of ['type', 'driver_mode', 'smtp_host', 'smtp_port', 'smtp_security', 'smtp_username', 'smtp_password']) {
      savedEmail[key] = F.dbQuery(`SELECT setting_value FROM system_settings WHERE category='email' AND setting_key='${key}' LIMIT 1`);
    }
    savedWarning = F.dbQuery("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='card_expiry_warning_days' LIMIT 1");
    F.dbQuery(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES
      ('email','type','smtp'),('email','driver_mode','smtp'),('email','smtp_host','localhost'),('email','smtp_port','1025'),
      ('email','smtp_security',''),('email','smtp_username',''),('email','smtp_password','')
      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)`);
  });
  test.afterEach(() => { expect(errors.splice(0), 'no HTTP 5xx').toEqual([]); });
  test.afterAll(async () => {
    for (const [key, value] of Object.entries(savedEmail)) {
      F.dbQuery(`UPDATE system_settings SET setting_value=${F.sqlStr(value)} WHERE category='email' AND setting_key='${key}'`);
    }
    if (savedWarning === '') F.dbQuery("DELETE FROM system_settings WHERE category='loans' AND setting_key='card_expiry_warning_days'");
    else F.dbQuery(`UPDATE system_settings SET setting_value=${F.sqlStr(savedWarning)} WHERE category='loans' AND setting_key='card_expiry_warning_days'`);
    F.dbQuery("UPDATE tipologie_lettore SET durata_tessera_giorni=NULL WHERE codice='standard'");
    F.deletePatrons(users);
    F.deleteTypes(PREFIX);
    await ctx?.close();
  });

  // ── A. Automatic card duration ──────────────────────────────────────
  test('creating a patron of a 365-day type sets the card to today + 365', async () => {
    const id = await createUserViaForm(admin, { email: `${PREFIX}-a1@circ.test`, surname: `CDA${RUN}`, typeId: yearType });
    users.push(id);
    expect(daysBetween(F.appTodayISO(), cardOf(id))).toBe(365);
    expect(F.dbInt(`SELECT tipologia_id FROM utenti WHERE id=${id}`)).toBe(yearType);
  });

  test('with "calcola" unchecked the date typed by the librarian is kept', async () => {
    const manual = F.appDateOffsetISO(100);
    const id = await createUserViaForm(admin, { email: `${PREFIX}-a2@circ.test`, surname: `CDB${RUN}`, typeId: yearType, cardAuto: false, manualExpiry: manual });
    users.push(id);
    expect(cardOf(id)).toBe(manual);
  });

  test('a type without card duration keeps the date even with "calcola"', async () => {
    const manual = F.appDateOffsetISO(77);
    const id = await createUserViaForm(admin, { email: `${PREFIX}-a3@circ.test`, surname: `CDC${RUN}`, typeId: plainType, manualExpiry: manual });
    users.push(id);
    expect(cardOf(id)).toBe(manual);
  });

  test('editing a patron to a 30-day type with "calcola" recomputes the card', async () => {
    const id = users[0];
    await admin.goto(`${F.BASE}/admin/users/edit/${id}`);
    await admin.locator('#tipologia_id').selectOption(String(monthType));
    await admin.locator('#card_auto').check();
    await admin.locator('#user-form button[type="submit"]').click();
    await admin.waitForURL((u) => !u.pathname.includes('/edit/'), { timeout: 20000 });
    expect(daysBetween(F.appTodayISO(), cardOf(id))).toBe(30);
  });

  test('approving a registration gives the default type card duration', async () => {
    F.dbQuery("UPDATE tipologie_lettore SET durata_tessera_giorni=200 WHERE codice='standard'");
    const pending = F.createPatron(`CDR${RUN}`, { cardExpiry: F.appDateOffsetISO(1800) });
    users.push(pending.id);
    F.dbQuery(`UPDATE utenti SET stato='sospeso' WHERE id=${pending.id}`);
    await admin.goto(`${F.BASE}/admin/users/details/${pending.id}`);
    await admin.locator(`form[action$="/admin/users/${pending.id}/activate-directly"] button[type="submit"]`).click();
    const confirm = admin.locator('.swal2-confirm');
    if (await confirm.isVisible({ timeout: 3000 }).catch(() => false)) await confirm.click();
    await expect.poll(() => F.dbQuery(`SELECT stato FROM utenti WHERE id=${pending.id}`), { timeout: 15000 }).toBe('attivo');
    expect(daysBetween(F.appTodayISO(), cardOf(pending.id))).toBe(200);
    F.dbQuery("UPDATE tipologie_lettore SET durata_tessera_giorni=NULL WHERE codice='standard'");
  });

  // ── B. Card renewal ─────────────────────────────────────────────────
  async function renewFromPage(id) {
    await admin.goto(`${F.BASE}/admin/users/details/${id}`);
    await admin.locator('[data-testid="renew-card"]').click();
    const confirm = admin.locator('.swal2-confirm');
    if (await confirm.isVisible({ timeout: 3000 }).catch(() => false)) await confirm.click();
    await admin.waitForURL((u) => u.pathname.endsWith(`/admin/users/details/${id}`));
  }

  test('an expired card of a 365-day patron is renewed to today + 365 and the account reactivated', async () => {
    const p = F.createPatron(`CDE${RUN}`, { typeId: yearType, cardExpiry: F.appDateOffsetISO(-3) });
    users.push(p.id);
    F.dbQuery(`UPDATE utenti SET stato='scaduto' WHERE id=${p.id}`);
    await renewFromPage(p.id);
    expect(daysBetween(F.appTodayISO(), cardOf(p.id))).toBe(365);
    expect(F.dbQuery(`SELECT stato FROM utenti WHERE id=${p.id}`)).toBe('attivo');
    await expect(admin.locator('[role="alert"]').filter({ hasText: /rinnovata|renewed/i }).first()).toBeVisible();
  });

  test('a type without card duration renews by one year', async () => {
    const p = F.createPatron(`CDF${RUN}`, { typeId: plainType, cardExpiry: F.appDateOffsetISO(5) });
    users.push(p.id);
    await renewFromPage(p.id);
    expect(daysBetween(F.appTodayISO(), cardOf(p.id))).toBe(365);
  });

  test('the renewal is recorded in the activity log', async () => {
    const id = users[users.length - 1];
    expect(F.dbInt(`SELECT COUNT(*) FROM log_modifiche WHERE tabella='utenti' AND record_id=${id} AND dati_nuovi LIKE '%user.card_renewed%'`)).toBe(1);
  });

  test('a reader with an expired card is refused, and can borrow again after the renewal', async ({ browser }) => {
    const p = F.createPatron(`CDG${RUN}`, { typeId: monthType, cardExpiry: F.appDateOffsetISO(10) });
    users.push(p.id);
    const title = F.createTitle(`${RUN} CARD`, 'libro');
    const rctx = await browser.newContext(); const reader = await rctx.newPage();
    try {
      await F.loginAs(reader, p.email, p.password);
      // The card expires while the reader is logged in.
      F.dbQuery(`UPDATE utenti SET data_scadenza_tessera=${F.sqlStr(F.appDateOffsetISO(-1))} WHERE id=${p.id}`);
      const before = await F.requestFromBookPage(reader, title.id, F.appTodayISO());
      expect(before.ok).toBe(false);
      expect(before.text).toMatch(/[Tt]essera|card/);
      await renewFromPage(p.id);
      expect(daysBetween(F.appTodayISO(), cardOf(p.id))).toBe(30);
      const after = await F.requestFromBookPage(reader, title.id, F.appTodayISO());
      expect(after.ok, after.text).toBe(true);
    } finally {
      await rctx.close();
      F.deleteTitles([title.id]);
    }
  });

  test('staff and admin accounts have no card to renew', async () => {
    const staff = F.createPatron(`CDH${RUN}`, { role: 'staff' });
    users.push(staff.id);
    await admin.goto(`${F.BASE}/admin/users/details/${staff.id}`);
    await expect(admin.locator('[data-testid="renew-card"]')).toHaveCount(0);
  });

  // ── C. Card expiring email ──────────────────────────────────────────
  const runCron = () => execFileSync('php', [path.join(ROOT, 'cron/automatic-notifications.php')], { cwd: ROOT, encoding: 'utf-8', timeout: 120000 });
  const mailsTo = async (email) => {
    const r = await fetch(`${MAILPIT}/search?query=${encodeURIComponent(`to:${email}`)}`);
    const data = await r.json();
    return (data.messages || []).filter((m) => /tessera|card/i.test(m.Subject || ''));
  };

  test('the librarian enables the warning 10 days ahead in Settings → Loans', async () => {
    await admin.goto(`${F.BASE}/admin/settings?tab=loans`);
    await admin.locator('#card_expiry_warning_days').fill('10');
    await admin.locator('[data-settings-panel="loans"] button[type="submit"]').click();
    await admin.waitForURL((u) => u.searchParams.get('tab') === 'loans');
    expect(F.dbQuery("SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='card_expiry_warning_days'")).toBe('10');
  });

  test('the cron warns a patron whose card expires in 5 days', async () => {
    const p = F.createPatron(`CDM${RUN}`, { email: `${PREFIX}-mail1@circ.test`, cardExpiry: F.appDateOffsetISO(5) });
    users.push(p.id);
    runCron();
    await expect.poll(async () => (await mailsTo(p.email)).length, { timeout: 30000 }).toBe(1);
    expect(F.dbQuery(`SELECT card_expiry_warning_sent_for FROM utenti WHERE id=${p.id}`)).toBe(F.appDateOffsetISO(5));
  });

  test('a second cron run sends no duplicate', async () => {
    const id = users[users.length - 1];
    runCron();
    const email = F.dbQuery(`SELECT email FROM utenti WHERE id=${id}`);
    expect((await mailsTo(email)).length).toBe(1);
  });

  test('a card expiring after the window gets nothing', async () => {
    const p = F.createPatron(`CDN${RUN}`, { email: `${PREFIX}-mail2@circ.test`, cardExpiry: F.appDateOffsetISO(20) });
    users.push(p.id);
    runCron();
    expect((await mailsTo(p.email)).length).toBe(0);
    expect(F.dbQuery(`SELECT IFNULL(card_expiry_warning_sent_for,'NULL') FROM utenti WHERE id=${p.id}`)).toBe('NULL');
  });

  test('a new expiry date re-arms the warning; 0 turns it off', async () => {
    const id = users[users.length - 2];
    const email = F.dbQuery(`SELECT email FROM utenti WHERE id=${id}`);
    F.dbQuery(`UPDATE utenti SET data_scadenza_tessera=${F.sqlStr(F.appDateOffsetISO(8))} WHERE id=${id}`);
    runCron();
    await expect.poll(async () => (await mailsTo(email)).length, { timeout: 30000 }).toBe(2);
    await admin.goto(`${F.BASE}/admin/settings?tab=loans`);
    await admin.locator('#card_expiry_warning_days').fill('0');
    await admin.locator('[data-settings-panel="loans"] button[type="submit"]').click();
    await admin.waitForURL((u) => u.searchParams.get('tab') === 'loans');
    F.dbQuery(`UPDATE utenti SET data_scadenza_tessera=${F.sqlStr(F.appDateOffsetISO(3))} WHERE id=${id}`);
    runCron();
    expect((await mailsTo(email)).length).toBe(2);
  });
});

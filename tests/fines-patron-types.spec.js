// @ts-check
/**
 * Fines per patron type and per material (plan §7 over the circulation
 * policy). The librarian sets the global fines in Settings → Loans, creates
 * patron types with their own daily fine, grace days and debt threshold from
 * Settings → Circulation, sets a fine for one material in the rules matrix,
 * then returns overdue loans from the return screen and lends from the staff
 * desk. The database is used only to create the overdue loans (due dates in
 * the application's timezone), to seed a debt, and to verify what was written.
 *
 * Run: /tmp/run-e2e.sh tests/fines-patron-types.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const F = require('./helpers/circulation-fixtures');

test.skip(!F.CONFIGURED, 'E2E credentials not configured');

const RUN = String(Date.now()).slice(-6);
const PREFIX = `e2efpt${RUN}`;
const SETTING_KEYS = ['daily_fine', 'fine_grace_days', 'fine_max_per_loan', 'debt_block_threshold', 'fine_charged_email'];

/** Same rendering as FineParameters::format() for it_IT / EUR. */
function euro(cents) {
  const abs = Math.abs(cents);
  const units = Math.floor(abs / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  return `${cents < 0 ? '-' : ''}${units},${String(abs % 100).padStart(2, '0')} €`;
}
function readSetting(key) {
  const exists = F.dbInt(`SELECT COUNT(*) FROM system_settings WHERE category='loans' AND setting_key=${F.sqlStr(key)}`) === 1;
  return exists ? F.dbQuery(`SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key=${F.sqlStr(key)}`) : null;
}
function restoreSetting(key, value) {
  if (value === null) {
    F.dbQuery(`DELETE FROM system_settings WHERE category='loans' AND setting_key=${F.sqlStr(key)}`);
  } else {
    F.dbQuery(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans', ${F.sqlStr(key)}, ${F.sqlStr(value)}) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
  }
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

/** Global fines block of Settings → Loans, filled and saved like an operator. */
async function saveGlobalFines(page, { daily, grace, cap, threshold }) {
  await page.goto(`${F.BASE}/admin/settings?tab=loans`);
  await expect(page.locator('#daily_fine')).toBeVisible();
  if (daily !== undefined) await page.fill('#daily_fine', daily);
  if (grace !== undefined) await page.fill('#fine_grace_days', grace);
  if (cap !== undefined) await page.fill('#fine_max_per_loan', cap);
  if (threshold !== undefined) await page.fill('#debt_block_threshold', threshold);
  await page.locator('form[action$="/admin/settings/loans"] button[type="submit"]').click();
  await page.waitForURL(/tab=loans/, { timeout: 15000 });
}

/** A title of $media with one copy, out on a loan to $patron that was due $daysLate days ago. */
function overdueLoan(patron, tag, media, daysLate, titles) {
  const book = F.createTitle(`${RUN} ${tag}`, media, 1);
  titles.push(book.id);
  const copyId = book.copyIds[0];
  F.dbQuery(`UPDATE copie SET stato='prestato' WHERE id=${copyId}`);
  F.dbQuery(`UPDATE libri SET copie_disponibili=0, stato='prestato' WHERE id=${book.id}`);
  const due = F.appDateOffsetISO(-daysLate);
  const start = F.appDateOffsetISO(-daysLate - 14);
  F.dbQuery(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (${book.id}, ${copyId}, ${patron.id}, '${start}', '${due}', 'in_ritardo', 'diretto', 1)`);
  return F.dbInt(`SELECT id FROM prestiti WHERE libro_id=${book.id} ORDER BY id DESC LIMIT 1`);
}

/** The fine the return screen announces (cents), or 0 when it shows none. */
async function previewedFine(page, loanId) {
  await page.goto(`${F.BASE}/admin/loans/returned/${loanId}`);
  await expect(page.getByRole('button', { name: 'Conferma restituzione' })).toBeVisible();
  const box = page.locator('#fine-preview');
  if (!(await box.isVisible().catch(() => false))) return 0;
  return Number(await box.getAttribute('data-fine-cents'));
}
async function confirmReturn(page) {
  await page.getByRole('button', { name: 'Conferma restituzione' }).click();
  await page.waitForFunction(() => !window.location.pathname.includes('/admin/loans/returned/'), null, { timeout: 20000 });
}
const lateFineOf = (loanId) => F.dbQuery(`SELECT COALESCE(GROUP_CONCAT(importo), '-') FROM movimenti_lettore WHERE prestito_id=${loanId} AND tipo='multa_ritardo'`);

test.describe.serial('Fines per patron type and per material', () => {
  test.describe.configure({ timeout: 240000 });
  /** @type {import('@playwright/test').Page} */ let admin;
  let ctx;
  const saved = {};
  const titles = [];
  const patrons = [];
  const types = {};
  const errors = [];

  test.beforeAll(async ({ browser }) => {
    for (const k of SETTING_KEYS) saved[k] = readSetting(k);
    ctx = await browser.newContext();
    admin = await ctx.newPage();
    admin.on('response', (r) => { if (r.status() >= 500) errors.push(`${r.status()} ${r.url()}`); });
    await F.loginAsAdmin(admin);
  });
  test.afterEach(() => { expect(errors.splice(0), 'no HTTP 5xx').toEqual([]); });
  test.afterAll(async () => {
    for (const k of SETTING_KEYS) restoreSetting(k, saved[k]);
    clearConfigCache();
    for (const p of patrons) {
      try { F.dbQuery(`DELETE FROM movimenti_lettore WHERE utente_id=${p.id}`); } catch { /* */ }
    }
    F.deleteTitles(titles);
    F.deletePatrons(patrons.map((p) => p.id));
    F.deleteTypes(PREFIX);
    await ctx?.close();
  });

  test('1. the librarian sets global fines and creates patron types with their own fines', async () => {
    await saveGlobalFines(admin, { daily: '0,50', grace: '0', cap: '', threshold: '5' });
    types.adult = await F.createTypeViaUi(admin, { nome: `Adulto ${RUN}`, codice: `${PREFIX}-adu`, multa_giornaliera: '1,00', giorni_tolleranza: 0 });
    types.young = await F.createTypeViaUi(admin, { nome: `Ragazzo ${RUN}`, codice: `${PREFIX}-rag`, multa_giornaliera: '0,20', giorni_tolleranza: 3, soglia_blocco_debito: '2' });
    types.guest = await F.createTypeViaUi(admin, { nome: `Ospite ${RUN}`, codice: `${PREFIX}-osp` });
    types.teacher = await F.createTypeViaUi(admin, { nome: `Docente ${RUN}`, codice: `${PREFIX}-doc`, multa_giornaliera: '0' });
    expect(F.dbQuery(`SELECT CONCAT_WS('|', codice, IFNULL(multa_giornaliera,'-'), IFNULL(giorni_tolleranza,'-'), IFNULL(soglia_blocco_debito,'-')) FROM tipologie_lettore WHERE codice LIKE '${PREFIX}%' ORDER BY codice`).split('\n')).toEqual([
      `${PREFIX}-adu|1.00|0|-`,
      `${PREFIX}-doc|0.00|-|-`,
      `${PREFIX}-osp|-|-|-`,
      `${PREFIX}-rag|0.20|3|2.00`,
    ]);
    // The types table summarises the effective fine and debt block of each type.
    await F.openCirculationTab(admin);
    const row = (code) => admin.locator(`#circulation-types-table tr[data-type-code="${code}"]`);
    await expect(row(`${PREFIX}-adu`).locator('[data-testid="type-fine-summary"]')).toContainText(euro(100));
    await expect(row(`${PREFIX}-rag`).locator('[data-testid="type-debt-summary"]')).toContainText(euro(200));
    await expect(row(`${PREFIX}-osp`).locator('[data-testid="type-fine-summary"]')).toContainText(euro(50));
    await expect(row(`${PREFIX}-doc`).locator('[data-testid="type-fine-summary"]')).toHaveCount(0);
    // An empty field shows the global value it inherits.
    await admin.goto(`${F.BASE}/admin/settings/circulation/types/${types.guest}`);
    await expect(admin.locator(`#type-${types.guest}-multa_giornaliera`)).toHaveValue('');
    await expect(admin.locator(`#type-${types.guest}-multa_giornaliera`)).toHaveAttribute('placeholder', `Eredita: ${euro(50)}`);
    await expect(admin.locator(`#type-${types.guest}-soglia_blocco_debito`)).toHaveAttribute('placeholder', `Eredita: ${euro(500)}`);
  });

  test('2. the daily fine and the grace days differ between two patron types', async () => {
    const adult = F.createPatron(`FPA${RUN}`, { typeId: types.adult }); patrons.push(adult);
    const young = F.createPatron(`FPR${RUN}`, { typeId: types.young }); patrons.push(young);
    const adultLoan = overdueLoan(adult, 'A5', 'libro', 5, titles);
    const youngLoan = overdueLoan(young, 'R5', 'libro', 5, titles);
    const youngGrace = overdueLoan(young, 'R3', 'libro', 3, titles);

    expect(await previewedFine(admin, adultLoan)).toBe(500); // 5 days × 1.00, no grace
    await expect(admin.locator('#fine-preview')).toContainText(euro(500));
    await confirmReturn(admin);
    expect(await previewedFine(admin, youngLoan)).toBe(100); // 5 > 3 grace: every day × 0.20
    await expect(admin.locator('#fine-preview')).toContainText(euro(100));
    await confirmReturn(admin);
    expect(await previewedFine(admin, youngGrace)).toBe(0); // 3 days = within the 3 grace days
    await confirmReturn(admin);

    expect(lateFineOf(adultLoan)).toBe('5.00');
    expect(lateFineOf(youngLoan)).toBe('1.00');
    expect(lateFineOf(youngGrace)).toBe('-');
    expect(F.dbQuery(`SELECT stato FROM prestiti WHERE id=${youngGrace}`)).toBe('restituito');
  });

  test('3. a fine set for one material in the matrix overrides the patron type', async () => {
    // Adults pay 2.00 a day for a late DVD; books keep the type's 1.00.
    await F.openCirculationTab(admin);
    await expect(admin.locator(`#rule-${types.adult}-dvd-fine`)).toHaveAttribute('placeholder', '1.00');
    await admin.locator(`#rule-${types.adult}-dvd-fine`).fill('2,00');
    await admin.locator('[data-testid="circulation-matrix-form"] button[type="submit"]').click();
    await admin.waitForURL((u) => u.searchParams.get('tab') === 'circulation');
    expect(F.dbQuery(`SELECT CONCAT_WS('|', IFNULL(multa_giornaliera,'-'), IFNULL(max_prestiti,'-')) FROM regole_prestito WHERE tipologia_id=${types.adult} AND tipo_media='dvd'`)).toBe('2.00|-');
    await expect(admin.locator(`#rule-${types.adult}-dvd-fine`)).toHaveValue('2.00');

    const adult = F.createPatron(`FPD${RUN}`, { typeId: types.adult }); patrons.push(adult);
    const dvdLoan = overdueLoan(adult, 'D4', 'dvd', 4, titles);
    const bookLoan = overdueLoan(adult, 'B4', 'libro', 4, titles);
    expect(await previewedFine(admin, dvdLoan)).toBe(800);
    await confirmReturn(admin);
    expect(await previewedFine(admin, bookLoan)).toBe(400);
    await confirmReturn(admin);
    expect(lateFineOf(dvdLoan)).toBe('8.00');
    expect(lateFineOf(bookLoan)).toBe('4.00');
  });

  test('4. the debt threshold of the patron type blocks one reader and not another', async () => {
    // Both owe 3.00. Ragazzo blocks over 2.00 (its own), Adulto inherits the global 5.00.
    const young = F.createPatron(`FPY${RUN}`, { typeId: types.young }); patrons.push(young);
    const adult = F.createPatron(`FPZ${RUN}`, { typeId: types.adult }); patrons.push(adult);
    for (const p of [young, adult]) {
      F.dbQuery(`INSERT INTO movimenti_lettore (utente_id, tipo, importo, descrizione) VALUES (${p.id}, 'addebito_manuale', 3.00, 'E2E debito')`);
    }
    const bookY = F.createTitle(`${RUN} DY`, 'libro', 1); titles.push(bookY.id);
    const bookZ = F.createTitle(`${RUN} DZ`, 'libro', 1); titles.push(bookZ.id);

    const refused = await F.staffLoan(admin, young, bookY);
    expect(refused).toContain('error=debt_block');
    expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${young.id}`)).toBe(0);

    const granted = await F.staffLoan(admin, adult, bookZ);
    expect(granted).not.toContain('error=');
    expect(F.dbInt(`SELECT COUNT(*) FROM prestiti WHERE utente_id=${adult.id} AND libro_id=${bookZ.id} AND attivo=1`)).toBe(1);

    // The reader record shows the young reader is over the threshold of the type.
    await admin.goto(`${F.BASE}/admin/users/details/${young.id}`);
    await expect(admin.locator('body')).toContainText(euro(300));
  });

  test('5. a patron type with no fine of its own inherits the global settings', async () => {
    const guest = F.createPatron(`FPO${RUN}`, { typeId: types.guest }); patrons.push(guest);
    const loan = overdueLoan(guest, 'O4', 'libro', 4, titles);
    expect(await previewedFine(admin, loan)).toBe(200); // 4 days × the global 0.50
    await confirmReturn(admin);
    expect(lateFineOf(loan)).toBe('2.00');
    // A reader with no type at all gets the default type, which inherits the globals too.
    const untyped = F.createPatron(`FPN${RUN}`); patrons.push(untyped);
    const loan2 = overdueLoan(untyped, 'N2', 'libro', 2, titles);
    expect(await previewedFine(admin, loan2)).toBe(100);
    await confirmReturn(admin);
    expect(lateFineOf(loan2)).toBe('1.00');
  });

  test('6. a patron type with the daily fine at 0 is never fined, whatever the globals', async () => {
    const teacher = F.createPatron(`FPT${RUN}`, { typeId: types.teacher }); patrons.push(teacher);
    const loan = overdueLoan(teacher, 'T10', 'libro', 10, titles);
    expect(await previewedFine(admin, loan)).toBe(0);
    await expect(admin.locator('#fine-preview')).toHaveCount(0);
    await confirmReturn(admin);
    expect(F.dbQuery(`SELECT stato FROM prestiti WHERE id=${loan}`)).toBe('restituito');
    expect(F.dbInt(`SELECT COUNT(*) FROM movimenti_lettore WHERE utente_id=${teacher.id}`)).toBe(0);
  });

  test('7. clearing a type fine from the edit page makes it inherit again', async () => {
    await admin.goto(`${F.BASE}/admin/settings/circulation/types/${types.teacher}`);
    const field = admin.locator(`#type-${types.teacher}-multa_giornaliera`);
    await expect(field).toHaveValue('0.00');
    await field.fill('');
    await admin.locator('[data-testid="circulation-type-edit"] button[type="submit"]').click();
    await admin.waitForURL((u) => u.searchParams.get('tab') === 'circulation');
    expect(F.dbQuery(`SELECT IFNULL(multa_giornaliera, 'NULL') FROM tipologie_lettore WHERE id=${types.teacher}`)).toBe('NULL');
    const teacher = F.createPatron(`FPU${RUN}`, { typeId: types.teacher }); patrons.push(teacher);
    const loan = overdueLoan(teacher, 'U2', 'libro', 2, titles);
    expect(await previewedFine(admin, loan)).toBe(100); // the global 0.50 again
  });

  test('8. an invalid amount in the type form is refused with a message', async () => {
    await admin.goto(`${F.BASE}/admin/settings/circulation/types/${types.guest}`);
    const field = admin.locator(`#type-${types.guest}-multa_giornaliera`);
    // Bypass the browser pattern check, as a script or an old browser could.
    await field.evaluate((el) => el.removeAttribute('pattern'));
    await field.fill('1,234');
    await admin.locator('[data-testid="circulation-type-edit"] button[type="submit"]').click();
    await admin.waitForURL(new RegExp(`/admin/settings/circulation/types/${types.guest}`));
    await expect(admin.locator('body')).toContainText('Valore non valido per il campo "Multa giornaliera"');
    expect(F.dbQuery(`SELECT IFNULL(multa_giornaliera, 'NULL') FROM tipologie_lettore WHERE id=${types.guest}`)).toBe('NULL');
  });
});

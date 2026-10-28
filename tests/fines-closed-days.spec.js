// @ts-check
/**
 * Late fines and the closures calendar (plan §13, decision 5): the days the
 * library is closed are not late days. The librarian sets a daily fine in
 * Settings → Loans and the closed days on /admin/calendar (weekly closed day,
 * a closure period, a yearly closure, a closure of one branch), then returns
 * overdue loans from the return screen. The database is used only to create
 * the overdue loans (dates in the application's timezone), to put a copy in a
 * branch, and to verify what was written.
 *
 * Every loan is due 10 days ago and returned today: with no closure it is
 * 10 days late, so at 1.00 a day the fine in cents is 100 × open late days.
 *
 * Run: /tmp/run-e2e.sh tests/fines-closed-days.spec.js --config=tests/playwright.config.js --workers=1
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const F = require('./helpers/circulation-fixtures');

test.skip(!F.CONFIGURED, 'E2E credentials not configured');

const RUN = String(Date.now()).slice(-6);
const TAG = `E2E-FCD-${RUN}`;
const LOAN_SETTINGS = ['daily_fine', 'fine_grace_days', 'fine_max_per_loan', 'debt_block_threshold', 'closed_weekdays'];
const LATE = 10;

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
function addDays(iso, days) {
  const d = new Date(`${iso}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}
const weekdayOf = (iso) => new Date(`${iso}T12:00:00Z`).getUTCDay();
/** Open days after $due up to and including $ret, for an "is closed" predicate. */
function openLateDays(due, ret, isClosed) {
  let n = 0;
  for (let d = addDays(due, 1); d <= ret; d = addDays(d, 1)) if (!isClosed(d)) n++;
  return n;
}

async function saveDailyFine(page, daily) {
  await page.goto(`${F.BASE}/admin/settings?tab=loans`);
  await expect(page.locator('#daily_fine')).toBeVisible();
  await page.fill('#daily_fine', daily);
  await page.fill('#fine_grace_days', '0');
  await page.fill('#fine_max_per_loan', '');
  await page.fill('#debt_block_threshold', '');
  await page.locator('form[action$="/admin/settings/loans"] button[type="submit"]').click();
  await page.waitForURL(/tab=loans/, { timeout: 15000 });
}
async function addClosure(page, { start, end = '', description, yearly = false, branch = '' }) {
  await page.goto(`${F.BASE}/admin/calendar`);
  await page.fill('#closure_start', start);
  if (end) await page.fill('#closure_end', end);
  await page.fill('#closure_description', description);
  if (branch) await page.selectOption('#closure_branch', branch);
  if (yearly) await page.check('#closure_yearly');
  await Promise.all([page.waitForURL(/\/admin\/calendar/, { timeout: 15000 }), page.locator('#save-closure').click()]);
  expect(F.dbInt(`SELECT COUNT(*) FROM calendario_chiusure WHERE descrizione=${F.sqlStr(description)}`), `closure ${description} saved`).toBe(1);
}
async function setClosedWeekdays(page, days) {
  await page.goto(`${F.BASE}/admin/calendar`);
  for (let w = 0; w <= 6; w++) {
    const box = page.locator(`#closed_weekday_${w}`);
    if (days.includes(w)) await box.check(); else await box.uncheck();
  }
  await Promise.all([page.waitForURL(/\/admin\/calendar/, { timeout: 15000 }), page.locator('#save-weekdays').click()]);
}

/** A title with one copy (optionally in branch $sedeId) out on a loan to $patron, due LATE days ago. */
function overdueLoan(patron, tag, titles, sedeId = null) {
  const book = F.createTitle(`${RUN} ${tag}`, 'libro', 1);
  titles.push(book.id);
  const copyId = book.copyIds[0];
  F.dbQuery(`UPDATE copie SET stato='prestato', sede_id=${sedeId ? Number(sedeId) : 'NULL'} WHERE id=${copyId}`);
  F.dbQuery(`UPDATE libri SET copie_disponibili=0, stato='prestato' WHERE id=${book.id}`);
  const due = F.appDateOffsetISO(-LATE);
  const start = F.appDateOffsetISO(-LATE - 14);
  F.dbQuery(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) VALUES (${book.id}, ${copyId}, ${patron.id}, '${start}', '${due}', 'in_ritardo', 'diretto', 1)`);
  return F.dbInt(`SELECT id FROM prestiti WHERE libro_id=${book.id} ORDER BY id DESC LIMIT 1`);
}
async function previewedFine(page, loanId) {
  await page.goto(`${F.BASE}/admin/loans/returned/${loanId}`);
  await expect(page.getByRole('button', { name: 'Conferma restituzione' })).toBeVisible();
  const box = page.locator('#fine-preview');
  if (!(await box.isVisible().catch(() => false))) return 0;
  return Number(await box.getAttribute('data-fine-cents'));
}
async function returnAndCharge(page, loanId) {
  const shown = await previewedFine(page, loanId);
  await page.getByRole('button', { name: 'Conferma restituzione' }).click();
  await page.waitForFunction(() => !window.location.pathname.includes('/admin/loans/returned/'), null, { timeout: 20000 });
  const charged = Math.round(Number(F.dbQuery(`SELECT COALESCE(SUM(importo), 0) FROM movimenti_lettore WHERE prestito_id=${loanId} AND tipo='multa_ritardo'`)) * 100);
  return { shown, charged };
}

test.describe.serial('Late fines skip the days the library is closed', () => {
  test.describe.configure({ timeout: 240000 });
  /** @type {import('@playwright/test').Page} */ let admin;
  let ctx;
  const saved = {};
  let savedClosures = [];
  let sedeId = 0;
  let patron;
  const titles = [];
  const errors = [];
  let today = '';
  let due = '';

  test.beforeAll(async ({ browser }) => {
    for (const k of LOAN_SETTINGS) saved[k] = readSetting(k);
    // Start from an empty calendar; the rows already there come back afterwards.
    savedClosures = F.dbQuery('SELECT CONCAT_WS(0x1f, id, data_inizio, data_fine, ricorrente_annuale, IFNULL(sede_id, \'\'), IFNULL(descrizione, \'\')) FROM calendario_chiusure').split('\n').filter(Boolean);
    F.dbQuery('DELETE FROM calendario_chiusure');
    restoreSetting('closed_weekdays', null);
    F.dbQuery(`INSERT INTO sedi (nome) VALUES (${F.sqlStr(`${TAG} Succursale`)})`);
    sedeId = F.dbInt(`SELECT id FROM sedi WHERE nome=${F.sqlStr(`${TAG} Succursale`)}`);
    patron = F.createPatron(`FCD${RUN}`);
    today = F.appTodayISO();
    due = F.appDateOffsetISO(-LATE);
    ctx = await browser.newContext();
    admin = await ctx.newPage();
    admin.on('response', (r) => { if (r.status() >= 500) errors.push(`${r.status()} ${r.url()}`); });
    await F.loginAsAdmin(admin);
    await saveDailyFine(admin, '1,00');
  });
  test.afterEach(async () => {
    expect(errors.splice(0), 'no HTTP 5xx').toEqual([]);
    // Each test sets its own closed days.
    F.dbQuery(`DELETE FROM calendario_chiusure WHERE descrizione LIKE ${F.sqlStr(`${TAG}%`)}`);
    restoreSetting('closed_weekdays', null);
  });
  test.afterAll(async () => {
    for (const k of LOAN_SETTINGS) restoreSetting(k, saved[k]);
    clearConfigCache();
    F.dbQuery('DELETE FROM calendario_chiusure');
    for (const line of savedClosures) {
      const [id, start, end, yearly, sede, description] = line.split('\x1f');
      F.dbQuery(`INSERT INTO calendario_chiusure (id, data_inizio, data_fine, ricorrente_annuale, sede_id, descrizione) VALUES (${Number(id)}, '${start}', '${end}', ${Number(yearly)}, ${sede === '' ? 'NULL' : Number(sede)}, ${F.sqlStr(description)})`);
    }
    try { F.dbQuery(`DELETE FROM movimenti_lettore WHERE utente_id=${patron?.id || 0}`); } catch { /* */ }
    F.deleteTitles(titles);
    F.deletePatrons([patron?.id]);
    if (sedeId) F.dbQuery(`DELETE FROM sedi WHERE id=${sedeId}`);
    await ctx?.close();
  });

  test('1. with no closed day configured every late day counts (zero configuration)', async () => {
    expect(F.dbInt('SELECT COUNT(*) FROM calendario_chiusure')).toBe(0);
    const loan = overdueLoan(patron, 'Z', titles);
    const { shown, charged } = await returnAndCharge(admin, loan);
    expect(shown).toBe(LATE * 100);
    expect(charged).toBe(LATE * 100);
    expect(F.dbQuery(`SELECT descrizione FROM movimenti_lettore WHERE prestito_id=${loan} AND tipo='multa_ritardo'`)).toBe(`${LATE}|${due}|${today}`);
  });

  test('2. a weekly closed day is not a late day', async () => {
    const closedWeekday = weekdayOf(addDays(today, -3));
    await setClosedWeekdays(admin, [closedWeekday]);
    const expected = openLateDays(due, today, (d) => weekdayOf(d) === closedWeekday);
    expect(expected).toBeLessThan(LATE);
    const loan = overdueLoan(patron, 'W', titles);
    // The loan page shows the fine accruing on the open loan with the same count.
    await admin.goto(`${F.BASE}/admin/loans/details/${loan}`);
    await expect(admin.locator('[data-loan-fine-accrued]')).toHaveAttribute('data-loan-fine-accrued', String(expected * 100));
    const { shown, charged } = await returnAndCharge(admin, loan);
    expect(shown).toBe(expected * 100);
    expect(charged).toBe(expected * 100);
  });

  test('3. a closure period inside the late days is not counted', async () => {
    await addClosure(admin, { start: addDays(today, -6), end: addDays(today, -4), description: `${TAG} chiusura estiva` });
    const loan = overdueLoan(patron, 'R', titles);
    const { shown, charged } = await returnAndCharge(admin, loan);
    expect(shown).toBe((LATE - 3) * 100);
    expect(charged).toBe((LATE - 3) * 100);
  });

  test('4. a yearly closure entered for an earlier year still closes this year', async () => {
    const closed = addDays(today, -5);
    const pastYear = String(Number(closed.slice(0, 4)) - 2);
    await addClosure(admin, { start: `${pastYear}${closed.slice(4)}`, description: `${TAG} patrono`, yearly: true });
    expect(F.dbQuery(`SELECT ricorrente_annuale FROM calendario_chiusure WHERE descrizione=${F.sqlStr(`${TAG} patrono`)}`)).toBe('1');
    const loan = overdueLoan(patron, 'Y', titles);
    const { shown, charged } = await returnAndCharge(admin, loan);
    expect(shown).toBe((LATE - 1) * 100);
    expect(charged).toBe((LATE - 1) * 100);
  });

  test('5. a closure of one branch counts only for the copies of that branch', async () => {
    await addClosure(admin, { start: addDays(today, -7), end: addDays(today, -6), description: `${TAG} succursale chiusa`, branch: String(sedeId) });
    const inBranch = overdueLoan(patron, 'B1', titles, sedeId);
    const elsewhere = overdueLoan(patron, 'B2', titles);
    const branchLoan = await returnAndCharge(admin, inBranch);
    expect(branchLoan.shown).toBe((LATE - 2) * 100);
    expect(branchLoan.charged).toBe((LATE - 2) * 100);
    const otherLoan = await returnAndCharge(admin, elsewhere);
    expect(otherLoan.shown).toBe(LATE * 100);
    expect(otherLoan.charged).toBe(LATE * 100);
  });

  test('6. closed days and grace: the grace threshold applies to the open late days', async () => {
    // Grace 8 days. 10 calendar days late with 3 closed = 7 open days: within the grace, no fine.
    await admin.goto(`${F.BASE}/admin/settings?tab=loans`);
    await admin.fill('#fine_grace_days', '8');
    await admin.locator('form[action$="/admin/settings/loans"] button[type="submit"]').click();
    await admin.waitForURL(/tab=loans/, { timeout: 15000 });
    await addClosure(admin, { start: addDays(today, -6), end: addDays(today, -4), description: `${TAG} ponte` });
    const loan = overdueLoan(patron, 'G', titles);
    const { shown, charged } = await returnAndCharge(admin, loan);
    expect(shown).toBe(0);
    expect(charged).toBe(0);
    expect(F.dbQuery(`SELECT stato FROM prestiti WHERE id=${loan}`)).toBe('restituito');
  });
});
